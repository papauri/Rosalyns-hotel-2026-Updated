<?php

/**
 * Station Display — Touchscreen ticket board (Kitchen / Bar / Coffee Bar).
 *
 * Default station is 'kitchen' (KDS). Wrapper pages bds.php and cds.php
 * pre-set $STATION before requiring this file to repurpose the same engine
 * as a Bar Display (BDS) or Coffee Bar Display (CDS).
 *
 * Permission keys: kds_view (kitchen) | bds_view (bar) | cds_view (coffee_bar)
 */
require_once 'admin-init.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/../includes/station-hours.php';

/* === Station configuration (overridable by including page) === */
$STATION       = $STATION       ?? 'kitchen';
$STATION_LABEL = $STATION_LABEL ?? 'KDS';
$STATION_TITLE = $STATION_TITLE ?? 'KDS — Kitchen Display';
$STATION_PERM  = $STATION_PERM  ?? 'kds_view';
$STATION_ICON  = $STATION_ICON  ?? 'fa-utensils';
$STATION_COLOR = $STATION_COLOR ?? '#d4a843';
$STATION_ROLE  = $STATION_ROLE  ?? 'chef';      // role that should be locked to this screen
$STATION_HOMEPAGE = $STATION_HOMEPAGE ?? basename($_SERVER['PHP_SELF']);
$STATION_GUIDE_HREF = $STATION_GUIDE_HREF ?? (
    $STATION === 'bar' ? '../docs/guides/03-bds-bar.html' : ($STATION === 'coffee_bar' ? '../docs/guides/04-cds-coffee.html' : '../docs/guides/02-kds-kitchen.html')
);
$STATION_GUIDE_LABEL = $STATION_GUIDE_LABEL ?? (
    $STATION === 'bar' ? 'BDS Guide' : ($STATION === 'coffee_bar' ? 'CDS Guide' : 'KDS Guide')
);

if (!hasPermission((int)$_SESSION['admin_user_id'], $STATION_PERM)) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$user = [
    'id'        => $_SESSION['admin_user_id'],
    'username'  => $_SESSION['admin_username'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];
$currency_symbol = getSetting('currency_symbol');
$site_name = getSetting('site_name') ?: 'Hotel';
$isFullScreen = in_array($user['role'] ?? '', [$STATION_ROLE], true);
$csrf_token = generateCsrfToken();
$stationWindow = rh_station_business_window($STATION);
$stationPreviousWindow = rh_station_previous_business_window($STATION, $stationWindow);
$displayUserName = trim((string)($user['full_name'] ?? ''));
if ($displayUserName === '') {
    $displayUserName = trim((string)($user['username'] ?? 'Staff'));
}
$nameTokens = preg_split('/\s+/', $displayUserName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$userInitials = '';
if (!empty($nameTokens)) {
    $userInitials .= strtoupper(substr((string)$nameTokens[0], 0, 1));
    if (count($nameTokens) > 1) {
        $userInitials .= strtoupper(substr((string)$nameTokens[count($nameTokens) - 1], 0, 1));
    }
}
if ($userInitials === '') {
    $userInitials = 'ST';
}

/* Initial payload — only orders that have AT LEAST one un-served item for this station.
 * Always filter by the current station — admin/manager still only see their station's tickets
 * on the display they opened, matching what the feed API returns on every subsequent poll.
 *
 * Use the UNION (restaurant-wide) window as the ticket cutoff so that orders fired during
 * another station's hours (e.g. a drink ordered before the bar opens at 11:00 but after the
 * kitchen opens at 06:00) still appear here.  Per-station windows are kept for served_today
 * and all_day counts which are genuinely station-scoped. */
$unionWindow = rh_station_union_business_window();
$cutoff = $unionWindow['start_sql'];
$isPrivileged = in_array($user['role'] ?? '', ['admin', 'manager'], true);
$sql = "SELECT o.id, o.reference, o.table_number, o.customer_name, o.order_type, o.kitchen_status, o.fired_at, o.kitchen_printed_at, o.served_at, o.notes, o.created_at, o.created_by,
                             COALESCE(NULLIF(u.full_name, ''), u.username, 'POS') AS ordered_by
          FROM stock_orders o
                    LEFT JOIN admin_users u ON u.id = o.created_by
         WHERE o.kitchen_status IN ('new','in_progress','ready','recalled')
           AND o.fired_at IS NOT NULL
           AND o.fired_at >= ?
           AND o.fired_at < ?
           AND EXISTS (SELECT 1 FROM stock_order_items oi WHERE oi.order_id = o.id AND oi.station = ? AND oi.kds_status NOT IN ('served','void'))
         ORDER BY o.fired_at ASC";
$st = $pdo->prepare($sql);
$st->execute([$cutoff, $unionWindow['end_sql'], $STATION]);
$orders = $st->fetchAll(PDO::FETCH_ASSOC);
$ids = array_column($orders, 'id');
$itemsByOrder = [];
if ($ids) {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $itemSql = "SELECT id, order_id, item_name, quantity, notes, kds_status, started_at, ready_at, menu_type, station,
                       IF(station = ?, 1, 0) AS is_mine
                  FROM stock_order_items
                 WHERE order_id IN ($place)
              ORDER BY order_id, (station <> ?) ASC, id";
    $params = array_merge([$STATION], $ids, [$STATION]);
    $iSt = $pdo->prepare($itemSql);
    $iSt->execute($params);
    foreach ($iSt->fetchAll(PDO::FETCH_ASSOC) as $r) $itemsByOrder[(int)$r['order_id']][] = $r;
}
$servedStmt = $pdo->prepare("SELECT COUNT(DISTINCT o.id)
    FROM stock_orders o
    INNER JOIN stock_order_items oi ON oi.order_id = o.id
    WHERE oi.station = ?
      AND oi.kds_status = 'served'
      AND oi.served_at >= ?
      AND oi.served_at < ?");
$servedStmt->execute([$STATION, $stationWindow['start_sql'], $stationWindow['end_sql']]);
$servedToday = (int)$servedStmt->fetchColumn();
$allDayStmt = $pdo->prepare("SELECT oi.item_name, SUM(oi.quantity) AS quantity
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
$allDayStmt->execute([$STATION, $stationWindow['start_sql'], $stationWindow['end_sql']]);
$stationAllDay = $allDayStmt->fetchAll(PDO::FETCH_ASSOC);

$controlKeys = [
    'paused' => 'station_' . $STATION . '_online_paused',
    'wait' => 'station_' . $STATION . '_estimated_wait_minutes',
    'reason' => 'station_' . $STATION . '_pause_reason',
];
$controlStmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (?, ?, ?)");
$controlStmt->execute([$controlKeys['paused'], $controlKeys['wait'], $controlKeys['reason']]);
$controlRows = $controlStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$stationWaitMinutes = max(5, min(180, (int)($controlRows[$controlKeys['wait']] ?? 20)));
$stationControl = [
    'paused' => in_array((string)($controlRows[$controlKeys['paused']] ?? '0'), ['1', 'true', 'on'], true),
    'wait_minutes' => $stationWaitMinutes,
    'reason' => (string)($controlRows[$controlKeys['reason']] ?? ''),
];
$bootstrap = [
    'tickets' => array_map(function ($o) use ($itemsByOrder) {
        $o['items'] = $itemsByOrder[(int)$o['id']] ?? [];
        return $o;
    }, $orders),
    'served_today' => $servedToday,
    'all_day' => $stationAllDay,
    'messages' => [],
    'station_control' => $stationControl,
    'business_window' => [
        'label' => $stationWindow['window_label'],
        'hours' => $stationWindow['hours_label'],
        'is_open_now' => $stationWindow['is_open_now'],
    ],
];

// Compute bootstrap fingerprint — seeded into JS to prevent a spurious full re-render on the first 500ms poll
try {
    $btMsgStmt = $pdo->prepare(
        "SELECT id, priority, seen_at, reply_message, replied_at FROM station_messages
         WHERE station = ? AND is_acknowledged = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
         ORDER BY priority DESC, created_at DESC, id DESC LIMIT 10"
    );
    $btMsgStmt->execute([$STATION]);
    $btMsgs = $btMsgStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $btMsgs = [];
}
$btMsgSig = implode(',', array_map(
    fn($m) => implode(':', [
        (string)$m['id'],
        (string)$m['priority'],
        (string)($m['seen_at'] ?? ''),
        (string)($m['replied_at'] ?? ''),
        substr(sha1((string)($m['reply_message'] ?? '')), 0, 10),
    ]),
    $btMsgs
));
$btFpParts = array_map(
    fn($o) => $o['id'] . ':' . $o['kitchen_status'] . ':' . ($o['is_priority'] ?? 0) . ':' .
        implode(',', array_column($o['items'] ?? [], 'kds_status')) . ':' .
        md5(implode('|', array_column($o['items'] ?? [], 'notes'))),
    $bootstrap['tickets']
);
$bootstrap['fingerprint'] = md5(
    implode('|', $btFpParts) .
        '|msgs:' . $btMsgSig .
        '|ctrl:' . ($stationControl['paused'] ? '1' : '0') . ':' . $stationWaitMinutes .
        '|sc:' . $servedToday
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=1920, initial-scale=1, user-scalable=no">
    <title><?php echo htmlspecialchars($STATION_TITLE); ?></title>
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="RH KDS">
    <link rel="manifest" href="manifest.php">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="css/kds.css?v=<?php echo @filemtime(__DIR__ . '/css/kds.css'); ?>">
    <style>
        :root {
            --kds-station-color: <?php echo $STATION_COLOR; ?>;
        }
    </style>
    <script src="js/station-sounds.js"></script>
    <script src="js/kiosk-fullscreen.js" defer></script>
</head>

<body class="station-screen">
    <!-- Hotel-branded fullscreen loader -->
    <div class="kds-action-loader" id="kdsActionLoader" role="status" aria-live="polite" aria-label="Loading">
        <div class="kds-action-loader__card">
            <i class="fas fa-hotel kds-action-loader__icon" aria-hidden="true"></i>
            <span class="kds-action-loader__hotel"><?php echo htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'); ?></span>
            <div class="kds-action-loader__divider"></div>
            <div class="kds-action-loader__spinner" aria-hidden="true"></div>
            <p class="kds-action-loader__title" id="kdsActionLoaderTitle">Loading…</p>
        </div>
    </div>
    <div id="kdsTopWrap" style="position:relative; z-index:9991;">
        <div class="topbar">
            <div class="brand">
                <span class="brand__label"><i class="fas <?php echo htmlspecialchars($STATION_ICON); ?>"></i> <?php echo htmlspecialchars($STATION_LABEL); ?></span>
                <span class="brand__hotel"><?php echo htmlspecialchars($site_name); ?></span>
            </div>
            <div class="stats">
                <span class="kds-stat-pill stat-open"><span class="kds-stat-pill__label">Open</span><strong id="openCount">0</strong></span>
                <span class="kds-stat-pill stat-ready"><span class="kds-stat-pill__label">Ready</span><strong id="readyCount">0</strong></span>
                <span class="kds-stat-pill stat-served"><span class="kds-stat-pill__label">Served shift</span><strong id="servedTodayCount"><?php echo $servedToday; ?></strong></span>
                <span class="kds-stat-pill stat-hours"><span class="kds-stat-pill__label">Hours</span><strong><?php echo htmlspecialchars($stationWindow['hours_label']); ?></strong></span>
                <span class="station-open-pill <?php echo $stationWindow['is_open_now'] ? 'open' : 'closed'; ?>"><i class="fas <?php echo $stationWindow['is_open_now'] ? 'fa-circle-check' : 'fa-circle-minus'; ?>"></i><?php echo $stationWindow['is_open_now'] ? 'Open' : 'Closed'; ?></span>
                <span class="stats-user" title="Signed in as <?php echo htmlspecialchars($displayUserName, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="stats-user__avatar" aria-hidden="true"><?php echo htmlspecialchars($userInitials, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="stats-user__name"><?php echo htmlspecialchars($displayUserName, ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
            </div>
            <div class="filter">
                <button class="active" data-filter="all" data-help="Show all|Every ticket that's still open in the kitchen — new, cooking, ready and recalled.">All</button>
                <button data-filter="new" data-help="New tickets|Tickets that just arrived from the till and haven't been started yet. Tap Start to begin cooking.">New</button>
                <button data-filter="in_progress" data-help="Cooking|Tickets where at least one item has been started by the chef but not yet ready.">Cooking</button>
                <button data-filter="ready" data-help="Ready for pickup|All food on the ticket is plated and waiting for a runner. Bump the ticket once it's been delivered to the table.">Ready</button>
            </div>
            <div class="right">
                <span class="clock" id="clock">--:--:--</span>
                <button class="toggle on" id="soundToggle" data-help="Sound chime|Play a sound when new tickets or notes arrive. Tap to mute."><i class="fas fa-volume-up"></i> <span>Sound</span></button>
                <button id="soundSettingsBtn" title="Sound settings" aria-label="Open sound settings" onclick="RHSounds.openSettings()"><i class="fas fa-sliders"></i><span>Settings</span></button>
                <button class="toggle" id="servedTodayBtn" data-loader-manual onclick="openServedToday('current', this)" data-help="Served menu|See served tickets for this station with guests, order taker, timestamps, item lines and station logs."><i class="fas fa-clipboard-check"></i> <span>Served</span></button>
                <button type="button" class="rh-help-toggle toggle" data-inline="1" id="rhHelpToggle" aria-label="Toggle help tooltips" data-help="Help mode|Turn tooltip hints on or off for station actions."><span class="dot"></span><i class="fas fa-question-circle"></i> <span id="rhHelpLabel">Help</span></button>
                <a href="<?php echo htmlspecialchars($STATION_GUIDE_HREF); ?>" target="_blank" rel="noopener" class="logout-link rh-guide-link" data-help="Station guide|Open the quick guide for this station."><i class="fas fa-book-open"></i> <span><?php echo htmlspecialchars($STATION_GUIDE_LABEL); ?></span></a>
                <?php if (function_exists('hasPermission') && hasPermission((int)$user['id'], 'kds_reports')): ?>
                    <a href="kds-report.php?station=<?php echo urlencode($STATION); ?>" class="logout-link rh-report-link" data-help="Daily report|Open the end-of-day station report. CSV export and email delivery available."><i class="fas fa-file-invoice"></i> <span>Daily Report</span></a>
                <?php endif; ?>
                <?php if ($user['role'] !== $STATION_ROLE): ?>
                    <a href="dashboard.php" class="logout-link rh-dashboard-link" data-help="Back to admin|Returns to the admin dashboard. Station staff are locked to this screen — only managers/admins see this link."><i class="fas fa-arrow-left"></i> <span>Dashboard</span></a>
                <?php endif; ?>
                <a href="logout.php" class="logout-link" data-help="Sign out|End your session. Always sign out at the end of a shift so the next chef logs in as themselves — every action is logged per user."><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
            </div>
            <!-- Burger menu toggle (compact desktop + mobile) -->
            <button class="kds-menu-toggle" id="kdsMenuToggle" aria-label="Open menu" aria-expanded="false" aria-controls="kdsMenuDrawer">
                <span></span><span></span><span></span>
            </button>
        </div>

        <div id="kdsMenuBackdrop" class="kds-menu-backdrop" aria-hidden="true"></div>

        <!-- Responsive station menu: mobile off-canvas + desktop sidebar -->
        <div id="kdsMenuDrawer" class="kds-drawer" role="navigation" aria-label="Station menu">
            <div class="kds-drawer__head">
                <div class="kds-drawer__title-wrap">
                    <span class="kds-drawer__title"><?php echo htmlspecialchars($STATION_LABEL); ?> Menu</span>
                    <span class="kds-drawer__subtitle">Station controls & quick links</span>
                </div>
                <button type="button" class="kds-drawer__collapse" id="kdsSidebarCollapse" aria-label="Collapse station menu" title="Collapse menu">
                    <i class="fas fa-angle-left"></i>
                </button>
            </div>
            <div class="drawer-clock" id="drawerClock">--:--:--</div>
            <div class="drawer-hours"><?php echo htmlspecialchars($stationWindow['label']); ?> · <?php echo htmlspecialchars($stationWindow['hours_label']); ?></div>

            <!-- Filter buttons mirrored -->
            <div class="drawer-filter">
                <button class="drawer-filter-btn active" data-filter="all">All</button>
                <button class="drawer-filter-btn" data-filter="new">New</button>
                <button class="drawer-filter-btn" data-filter="in_progress">Cooking</button>
                <button class="drawer-filter-btn" data-filter="ready">Ready</button>
            </div>

            <!-- Action links -->
            <div class="drawer-links">
                <button type="button" id="drawerFullscreenToggle" class="drawer-fullscreen-btn" onclick="toggleStationFullscreen(this)">
                    <i class="fas fa-expand"></i> <span>Enter Fullscreen</span>
                </button>
                <button id="drawerSoundToggle" class="sound-toggle-drawer on" onclick="toggleSoundFromDrawer(this)">
                    <i class="fas fa-volume-up" id="drawerSoundIcon"></i> <span id="drawerSoundLabel">Sound On</span>
                </button>
                <button type="button" onclick="document.getElementById('rhHelpToggle')?.click(); closeKdsDrawer();">
                    <i class="fas fa-question-circle"></i> Help Tooltips
                </button>
                <button class="drawer-settings-btn" onclick="RHSounds.openSettings(); closeKdsDrawer();">
                    <i class="fas fa-sliders"></i> Sound Settings
                </button>
                <button data-loader-manual onclick="openServedToday('current', this); closeKdsDrawer();">
                    <i class="fas fa-clipboard-check"></i> Served Menu
                </button>
                <?php if (function_exists('hasPermission') && hasPermission((int)$user['id'], 'kds_reports')): ?>
                    <a href="kds-report.php?station=<?php echo urlencode($STATION); ?>">
                        <i class="fas fa-file-invoice"></i> Daily Report
                    </a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($STATION_GUIDE_HREF); ?>" target="_blank" rel="noopener">
                    <i class="fas fa-book-open"></i> <?php echo htmlspecialchars($STATION_GUIDE_LABEL); ?>
                </a>
                <?php if ($user['role'] !== $STATION_ROLE): ?>
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Admin Dashboard
                    </a>
                    <?php if (!function_exists('moduleEnabled') || moduleEnabled('bookings')): ?>
                    <a href="bookings.php">
                        <i class="fas fa-calendar-check"></i> Bookings
                    </a>
                    <?php endif; ?>
                    <a href="pos.php">
                        <i class="fas fa-cash-register"></i> POS / Restaurant Till
                    </a>
                    <?php if (!function_exists('moduleEnabled') || moduleEnabled('bookings')): ?>
                    <a href="room-management.php">
                        <i class="fas fa-door-open"></i> Rooms
                    </a>
                    <?php endif; ?>
                    <a href="payments.php">
                        <i class="fas fa-credit-card"></i> Payments
                    </a>
                    <a href="reports.php">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                <?php endif; ?>
                <a href="logout.php" class="danger">
                    <i class="fas fa-sign-out-alt"></i> Sign Out
                </a>
            </div>
            <div id="kdsSidebarResizeHandle" class="kds-sidebar-resize-handle" role="separator" aria-label="Resize station menu" aria-orientation="vertical"></div>
        </div><!-- /#kdsMenuDrawer -->
    </div><!-- /#kdsTopWrap -->

    <div id="station-closed-overlay" role="status" aria-live="polite">
        <span class="sc-icon"><i class="fas fa-circle-minus"></i></span>
        <span class="sc-title"><?php echo htmlspecialchars($STATION_LABEL); ?> is currently closed</span>
        <span class="sc-hours" id="scHours">Hours: <?php echo htmlspecialchars($stationWindow['hours_label']); ?></span>
        <span class="sc-next" id="scNext"></span>
    </div>

    <div class="board" id="board"></div>

    <div class="station-bottom" aria-label="Station tools">
        <section class="station-panel" aria-labelledby="stationMessagesTitle">
            <div class="station-panel__head" id="stationMessagesTitle"><span><i class="fas fa-comments"></i> FOH Notes</span><span id="stationMessageCount">0</span></div>
            <div class="station-message-list" id="stationMessages">
                <div class="station-empty-line">No active notes.</div>
            </div>
        </section>
        <section class="station-panel" aria-labelledby="allDayTitle">
            <div class="station-panel__head" id="allDayTitle"><span><i class="fas fa-list-ol"></i> All Day</span><span id="allDayCount">0</span></div>
            <div class="all-day-list" id="allDayList">
                <div class="station-empty-line">No items working.</div>
            </div>
        </section>
        <section class="station-panel" aria-labelledby="stationControlTitle">
            <div class="station-panel__head" id="stationControlTitle"><span><i class="fas fa-gauge-high"></i> Online Flow</span><button type="button" class="station-panel__sound-test" onclick="RHSounds.play('normal')" title="Test notification sound"><i class="fas fa-volume-high"></i><span>Test</span></button></div>
            <div class="station-control">
                <div class="station-control__status" id="stationControlStatus">Loading station flow...</div>
                <div class="station-control__actions">
                    <button type="button" class="station-control__pause live" id="stationPauseBtn" data-loader-manual onclick="toggleStationPause(this)"><i class="fas fa-pause"></i><span>Pause</span></button>
                    <label class="station-control__wait"><input type="number" id="stationWaitInput" min="5" max="180" step="5" value="20"> min</label>
                    <button type="button" class="station-control__save" data-loader-manual onclick="saveStationWait(this)"><i class="fas fa-check"></i><span>Save</span></button>
                </div>
            </div>
        </section>
    </div>

    <div class="toast" id="toast"></div>

    <!-- ── Cancel-before-prep modal ──────────────────────────────────── -->
    <div id="kds-cancel-modal" class="rh-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="kds-cancel-title">
        <div class="rh-modal-bg" onclick="closeCancelModal()"></div>
        <div class="rh-modal-card" style="max-width:460px;">
            <div class="rh-modal-head">
                <h3 id="kds-cancel-title"><i class="fas fa-ban" style="color:#e74c3c;"></i> Cancel Order Before Prep</h3>
                <button class="rh-modal-close" onclick="closeCancelModal()" title="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="rh-modal-body">
                <p style="margin:0 0 12px; font-size:14px; color:var(--c-text,#fff); opacity:.8;">Choose a reason — FOH will be notified immediately.</p>
                <div id="kds-cancel-reasons" style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:12px;"></div>
                <textarea id="kds-cancel-custom" placeholder="Additional details (optional)…" maxlength="500" rows="2" style="width:100%; box-sizing:border-box; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); border-radius:6px; color:inherit; padding:8px 10px; font-size:13px; resize:vertical;"></textarea>
                <div style="display:flex; gap:8px; margin-top:14px;">
                    <button id="kds-cancel-confirm-btn" class="b-cancel" style="flex:1; font-size:15px; padding:12px 0;" onclick="confirmCancelTicket()"><i class="fas fa-ban"></i> Confirm Cancel</button>
                    <button style="flex:0 0 auto; padding:12px 16px; background:rgba(255,255,255,.1); border:none; border-radius:8px; color:inherit; cursor:pointer; font-size:15px;" onclick="closeCancelModal()">Keep</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 86-item modal ──────────────────────────────────────────────── -->
    <div id="kds-86-modal" class="rh-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="kds-86-title">
        <div class="rh-modal-bg" onclick="close86Modal()"></div>
        <div class="rh-modal-card" style="max-width:400px;">
            <div class="rh-modal-head">
                <h3 id="kds-86-title"><i class="fas fa-circle-xmark" style="color:#f39c12;"></i> 86 Item</h3>
                <button class="rh-modal-close" onclick="close86Modal()" title="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="rh-modal-body">
                <p id="kds-86-desc" style="margin:0 0 14px; font-size:14px; line-height:1.5;"></p>
                <p style="margin:0 0 14px; font-size:13px; opacity:.7;">FOH will be notified so the cashier can apologise to the guest and offer an alternative.</p>
                <div style="display:flex; gap:8px;">
                    <button id="kds-86-confirm-btn" style="flex:1; padding:12px 0; background:#e67e22; border:none; border-radius:8px; color:#fff; font-size:15px; font-weight:700; cursor:pointer;" onclick="confirm86Item()"><i class="fas fa-ban"></i> 86 this item</button>
                    <button style="flex:0 0 auto; padding:12px 16px; background:rgba(255,255,255,.1); border:none; border-radius:8px; color:inherit; cursor:pointer; font-size:15px;" onclick="close86Modal()">Keep</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Served Today modal -->
    <div id="rhModal" class="rh-modal" style="display:none;">
        <div class="rh-modal-bg" onclick="closeModal()"></div>
        <div class="rh-modal-card">
            <div class="rh-modal-head">
                <h3 id="rhModalTitle"><i class="fas fa-clipboard-check"></i> Served Today</h3>
                <button class="rh-modal-close" onclick="closeModal()" title="Close (Esc)"><i class="fas fa-times"></i></button>
            </div>
            <div class="rh-modal-body" id="rhModalBody">Loading…</div>
        </div>
    </div>

    <audio id="dingSound" preload="auto" src="data:audio/wav;base64,UklGRl9vT19XQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YU"></audio>

    <script>
        /* ── RHPoll: persistent polling helper ──────────────────────────
       Keep station polling active even when this tab is not focused so
       live alerts continue without forcing tab switching. */
        const RHPoll = (() => {
            const timers = new Map();
            return {
                every(fn, ms) {
                    if (timers.has(fn)) return;
                    const id = setInterval(() => {
                        try {
                            fn();
                        } catch (e) {
                            /* keep scheduler alive */
                        }
                    }, ms);
                    timers.set(fn, id);
                }
            };
        })();
        const csrf = <?php echo json_encode($csrf_token); ?>;
        const apiUrl = '../api/kds-action.php';
        const STATION = <?php echo json_encode($STATION); ?>;
        const STATION_LABEL = <?php echo json_encode($STATION_LABEL); ?>;
        let state = <?php echo json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        /* ── Sound system init ──────────────────────────────────────────── */
        RHSounds.init();
        let soundOn = RHSounds.isEnabled();
        RHSounds.onToggle(v => {
            soundOn = v;
            const btn = document.getElementById('soundToggle');
            const dbtn = document.getElementById('drawerSoundToggle');
            if (btn) {
                btn.classList.toggle('on', v);
                btn.innerHTML = v ? '<i class="fas fa-volume-up"></i> <span>Sound</span>' : '<i class="fas fa-volume-mute"></i> <span>Muted</span>';
            }
            if (dbtn) {
                dbtn.classList.toggle('on', v);
                document.getElementById('drawerSoundIcon').className = v ? 'fas fa-volume-up' : 'fas fa-volume-mute';
                document.getElementById('drawerSoundLabel').textContent = v ? 'Sound On' : 'Muted';
            }
        });

        let currentFilter = 'all';
        let knownIds = new Set(state.tickets.map(t => t.id));
        let knownMessageIds = new Set((state.messages || []).map(m => m.id));
        let hasPolledMessages = false;
        let lastFeedFingerprint = state.fingerprint || ''; /* seeded from PHP bootstrap — prevents spurious flash on first 500ms poll */
        /* Per-message reply drafts (preserved across the 8s auto-refresh) and a
           Set of FOH messages the cook has acknowledged on the ticket card so the
           flash stops without losing the message. */
        const _replyDrafts = {};
        const _seenTicketMsgIds = new Set();
        /* Drafts of new station→FOH notes the cook is composing in each ticket card. */
        const _composerDrafts = {};
        /* Outbound notes this station has sent. Persisted client-side so the chat
           bubbles don't disappear when the next 8s feed-poll wipes state.messages. */
        const _outboundQueue = [];

        function escHtml(s) {
            return String(s || '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        function escJsSingle(s) {
            return String(s || '')
                .replace(/\\/g, '\\\\')
                .replace(/'/g, "\\'")
                .replace(/\r/g, ' ')
                .replace(/\n/g, ' ');
        }

        function getStationClicker(trigger) {
            return trigger && trigger.closest ? trigger.closest('button, a, [role="button"], [data-lock-click]') : null;
        }

        function setStationClickerLoading(trigger, isLoading) {
            const btn = getStationClicker(trigger);
            if (!btn) return null;
            if (isLoading) {
                if (btn.dataset.wasDisabled === undefined) {
                    btn.dataset.wasDisabled = (btn.disabled || btn.getAttribute('aria-disabled') === 'true') ? '1' : '0';
                }
                btn.classList.add('is-loading');
                btn.setAttribute('aria-busy', 'true');
                btn.setAttribute('aria-disabled', 'true');
                if ('disabled' in btn) btn.disabled = true;
                return btn;
            }
            btn.classList.remove('is-loading');
            btn.removeAttribute('aria-busy');
            if (btn.dataset.wasDisabled === '0') {
                btn.removeAttribute('aria-disabled');
                if ('disabled' in btn) btn.disabled = false;
            }
            delete btn.dataset.wasDisabled;
            return btn;
        }

        /* Global click-lock to prevent double-clicks. Async buttons use data-loader-manual
           and clear the spinner after their network action completes. */
        const _clickLocks = new WeakMap();

        /* ── Hotel-branded fullscreen loader ─────────────────────────── */
        function showKdsLoader(title) {
            const el = document.getElementById('kdsActionLoader');
            if (!el) return;
            const t = document.getElementById('kdsActionLoaderTitle');
            if (t) t.textContent = title || 'Loading…';
            el.classList.add('show');
            clearTimeout(window._kdsLoaderSafetyTimer);
            window._kdsLoaderSafetyTimer = setTimeout(() => hideKdsLoader(), 12000);
        }

        function hideKdsLoader() {
            clearTimeout(window._kdsLoaderSafetyTimer);
            document.getElementById('kdsActionLoader')?.classList.remove('show');
        }
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('button, a, [role="button"], [data-lock-click]');
            if (!btn) return;
            if (btn.dataset.noLock !== undefined) return;
            if (btn.hasAttribute('data-filter')) return; /* tab switches are local-only */
            if (btn.getAttribute('aria-busy') === 'true') {
                e.stopImmediatePropagation();
                e.preventDefault();
                return;
            }
            if (btn.disabled || btn.getAttribute('aria-disabled') === 'true') return;
            const now = Date.now();
            const last = _clickLocks.get(btn) || 0;
            if (now - last < 1200) {
                e.stopImmediatePropagation();
                e.preventDefault();
                return;
            }
            _clickLocks.set(btn, now);
            if (btn.dataset.loaderManual !== undefined) return;
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy', 'true');
            setTimeout(() => {
                btn.classList.remove('is-loading');
                btn.removeAttribute('aria-busy');
            }, 1200);
        }, true);

        function syncStationViewportMetrics() {
            const top = document.getElementById('kdsTopWrap');
            const bottom = document.querySelector('.station-bottom');
            const topHeight = Math.ceil(top?.getBoundingClientRect().height || 56);
            const bottomHeight = Math.ceil(bottom?.getBoundingClientRect().height || 108);
            document.documentElement.style.setProperty('--station-topbar-height', topHeight + 'px');
            document.documentElement.style.setProperty('--station-bottom-height', bottomHeight + 'px');
        }

        function elapsedSeconds(iso) {
            if (!iso) return 0;
            // Server returns "YYYY-MM-DD HH:MM:SS" stored in UTC — append Z so the browser
            // parses it as UTC rather than local time (Malawi is UTC+2, losing 2h otherwise).
            const norm = iso.includes('T') ? iso : iso.replace(' ', 'T');
            const t = new Date(norm.endsWith('Z') || norm.includes('+') ? norm : norm + 'Z');
            return Math.max(0, Math.floor((Date.now() - t.getTime()) / 1000));
        }

        function fmtElapsed(s) {
            // Human-friendly elapsed timer:
            //   0-9s    → "just fired"
            //   10-59s  → "45 sec"
            //   1-4m    → "3m 12s"
            //   5-59m   → "12 min"
            //   1-12h   → "1h 12m"
            //   12h+    → "stale" (anything older than half a day on the board is wrong)
            if (s < 10) return '<span class="u">just fired</span>';
            if (s < 60) return s + ' <span class="u">sec</span>';
            const m = Math.floor(s / 60),
                ss = s % 60;
            if (m < 5) return m + '<span class="u">m</span> ' + String(ss).padStart(2, '0') + '<span class="u">s</span>';
            if (m < 60) return m + ' <span class="u">min</span>';
            const h = Math.floor(m / 60),
                mm = m % 60;
            if (h >= 12) return '<span class="u">check FOH</span>';
            return h + '<span class="u">h</span> ' + String(mm).padStart(2, '0') + '<span class="u">m</span>';
        }

        function fmtPlacedTime(iso) {
            if (!iso) return '';
            const norm = iso.includes('T') ? iso : iso.replace(' ', 'T');
            const t = new Date(norm.endsWith('Z') || norm.includes('+') ? norm : norm + 'Z');
            if (isNaN(t.getTime())) return '';
            const today = new Date();
            const sameDay = t.toDateString() === today.toDateString();
            const hh = String(t.getHours()).padStart(2, '0');
            const mm = String(t.getMinutes()).padStart(2, '0');
            return sameDay ? `${hh}:${mm}` : `${t.toLocaleDateString()} ${hh}:${mm}`;
        }

        function fmtQty(q) {
            const n = parseFloat(q || 0);
            return n % 1 === 0 ? String(n) : n.toFixed(1);
        }

        function elapsedClass(seconds, status) {
            if (status === 'ready') {
                // Escalate visually when ticket has been ready but not collected
                if (seconds > 600) return 'stale-ready late';
                if (seconds > 300) return 'stale-ready warn';
                return '';
            }
            if (seconds > 600) return 'late';
            if (seconds > 300) return 'warn';
            return '';
        }

        function timerStatus(seconds, status, firedAt) {
            if (status === 'ready') return 'Ready for pickup';
            const placed = firedAt ? fmtPlacedTime(firedAt) : '';
            if (seconds > 3600) return placed ? 'Overdue — fired ' + placed + ' · check with FOH' : 'Overdue — verify with FOH';
            if (seconds > 600) return placed ? 'Late — fired ' + placed : 'Late — needs attention';
            if (seconds > 300) return placed ? 'Watch closely — fired ' + placed : 'Watch closely';
            return placed ? 'On time — fired ' + placed : 'On time';
        }

        function orderTypeLabel(type) {
            return String(type || 'walk_in')
                .replace(/_/g, ' ')
                .replace(/\b\w/g, letter => letter.toUpperCase());
        }

        function stationActionSuccessMessage(action) {
            return ({
                start_item: 'Prep started. Timer is now tracking this line.',
                ready_item: 'Ready for pickup. POS will see it instantly.',
                collect_item: 'Marked for collection. Runner handoff is visible.',
                serve_item: 'Item served. Ticket totals have been updated.',
                start_ticket: 'Ticket started. All pending lines are now preparing.',
                bump_ticket: 'Bumped cleanly. Ticket cleared and stock trail updated.',
                recall_ticket: 'Ticket recalled. Stock and kitchen status are back in play.'
            })[action] || 'Updated successfully.';
        }

        function stationFriendlyIssue(rawMessage) {
            const raw = String(rawMessage || 'Action failed');
            const lower = raw.toLowerCase();
            if (lower.includes('stock check') || lower.includes('ingredient') || lower.includes('stock deduction')) {
                return {
                    title: 'Stock check needs attention',
                    body: raw,
                    detail: 'Receive stock, adjust the recipe, or 86 the item so FOH can offer the guest an alternative.',
                    type: 'urgent'
                };
            }
            if (lower.includes('cannot start') || lower.includes('pending') || lower.includes('current:')) {
                return {
                    title: 'Nothing pending on that line',
                    body: 'This ticket has already moved past that step.',
                    detail: raw,
                    type: 'info'
                };
            }
            if (lower.includes('already served') || lower.includes('already voided') || lower.includes('cannot mark ready')) {
                return {
                    title: 'Already completed',
                    body: 'The station board has already recorded that step.',
                    detail: raw,
                    type: 'info'
                };
            }
            if (lower.includes('csrf') || lower.includes('security') || lower.includes('authenticated') || lower.includes('forbidden')) {
                return {
                    title: 'Session check needed',
                    body: 'Refresh the display and try the action again.',
                    detail: raw,
                    type: 'urgent'
                };
            }
            if (lower.includes('network')) {
                return {
                    title: 'Connection issue',
                    body: 'The display could not reach the server just now. Try again in a moment.',
                    detail: raw,
                    type: 'urgent'
                };
            }
            return {
                title: 'Action needs attention',
                body: raw,
                detail: 'The board has not changed. Please review the ticket state before trying again.',
                type: 'urgent'
            };
        }

        function showStationIssue(rawMessage, opts = {}) {
            const issue = stationFriendlyIssue(rawMessage);
            toast(issue.title, true);
            if (typeof RHNotif !== 'undefined' && RHNotif.show) {
                RHNotif.show({
                    title: issue.title,
                    body: issue.body + (issue.detail ? '\n' + issue.detail : ''),
                    type: issue.type,
                    source: STATION_LABEL,
                    duration: 12000,
                    sound: false,
                });
            }
            if (opts.modal) {
                openModal(issue.title);
                document.getElementById('rhModalBody').innerHTML = `
                <div class="station-dialog station-dialog--${escHtml(issue.type)}">
                    <div class="station-dialog__icon"><i class="fas ${issue.type === 'urgent' ? 'fa-triangle-exclamation' : 'fa-circle-info'}"></i></div>
                    <div class="station-dialog__copy">
                        <p class="station-dialog__body">${escHtml(issue.body)}</p>
                        ${issue.detail ? `<p class="station-dialog__detail">${escHtml(issue.detail)}</p>` : ''}
                    </div>
                </div>`;
            }
        }

        function ticketHtml(t) {
            const firedAt = t.fired_at || t.kitchen_printed_at;
            const elapsed = elapsedSeconds(firedAt);
            const ec = elapsedClass(elapsed, t.kitchen_status);
            const isUrgent = elapsed > 720 && t.kitchen_status !== 'ready';
            const isRush = !!(t.is_priority);
            const tableLbl = t.order_type === 'room_service' ?
                (t.table_number ? escHtml(t.table_number) : 'Room Service') :
                (t.table_number ? `Table ${escHtml(t.table_number)}` : (t.order_type || 'walk_in').toUpperCase().replace('_', ' '));
            const ticketItems = t.items || [];
            const items = ticketItems.map(it => itemHtml(it)).join('');
            const myItems = ticketItems.filter(i => i.is_mine == 1 || i.is_mine === true);
            const totalQty = ticketItems.reduce((sum, item) => sum + (parseFloat(item.quantity || 0) || 0), 0);
            const orderedBy = t.ordered_by || t.opened_by || 'POS';
            const serviceLabel = orderTypeLabel(t.order_type);
            const guestLabel = t.customer_name || 'Walk-in guest';
            const orderNote = t.notes ? `<div class="t-orderNote"><i class="fas fa-exclamation-triangle"></i> ${escHtml(t.notes)}</div>` : '';
            const placedLine = firedAt ? `<div class="t-placed"><i class="fas fa-clock"></i>Placed ${escHtml(fmtPlacedTime(firedAt))}</div>` : '';
            const detailStrip = `<div class="t-detail-strip">
                <span class="t-detail-chip"><i class="fas fa-user-tie"></i><b>FOH</b>${escHtml(orderedBy)}</span>
                <span class="t-detail-chip"><i class="fas fa-user"></i><b>Guest</b>${escHtml(guestLabel)}</span>
                <span class="t-detail-chip"><i class="fas fa-clipboard-list"></i><b>Lines</b>${ticketItems.length} · ${fmtQty(totalQty)} item${Math.abs(totalQty - 1) < 0.001 ? '' : 's'}</span>
            </div>`;
            const allMyReady = myItems.filter(i => i.kds_status !== 'served').every(i => i.kds_status === 'ready');
            const hasPending = myItems.some(i => i.kds_status === 'pending');
            const hasMyItems = myItems.length > 0;
            const hasPrepStarted = ticketItems.some(i => ['preparing', 'in_progress', 'ready', 'collection', 'served'].includes(i.kds_status));
            const canCancelBeforePrep = !hasPrepStarted && ['new', 'recalled', 'none'].includes((t.kitchen_status || 'new'));
            const hasPreparing = myItems.some(i => ['preparing', 'in_progress', 'collection'].includes(i.kds_status));
            // Count other stations still working
            const otherPending = ticketItems.filter(i => !(i.is_mine == 1 || i.is_mine === true) && !['served', 'void'].includes(i.kds_status)).length;
            const otherLabel = otherPending > 0 ? `<div class="t-other-pending"><i class="fas fa-hourglass-half"></i> ${otherPending} item${otherPending>1?'s':''} at other station${otherPending>1?'s':''}</div>` : '';
            const nextStep = (() => {
                if (!hasMyItems) {
                    return {
                        tone: 'wait',
                        icon: 'fa-eye',
                        text: 'Monitor this ticket. Waiting on another station.'
                    };
                }
                if (hasPending) {
                    return {
                        tone: 'start',
                        icon: 'fa-fire',
                        text: 'Start pending lines now.'
                    };
                }
                if (allMyReady) {
                    return {
                        tone: 'bump',
                        icon: 'fa-check-double',
                        text: 'Runner picked up? Tap Bump.'
                    };
                }
                if (hasPreparing) {
                    return {
                        tone: 'ready',
                        icon: 'fa-bell',
                        text: 'Mark lines Ready when plating is done.'
                    };
                }
                return {
                    tone: 'review',
                    icon: 'fa-clipboard-check',
                    text: 'Review lines and update status.'
                };
            })();
            const nextStepHtml = `<div class="t-next t-next--${nextStep.tone}"><span class="t-next__label">Next</span><span class="t-next__value"><i class="fas ${nextStep.icon}"></i> ${escHtml(nextStep.text)}</span></div>`;
            // Embedded FOH notes for this ticket — flash until staff click them.
            const ticketMsgs = (state.messages || []).filter(m => m.order_ref && m.order_ref === t.reference);
            const unseenMsgs = ticketMsgs.filter(m => !_seenTicketMsgIds.has(parseInt(m.id, 10)));
            const flashClass = unseenMsgs.length ? ' has-foh-flash' : '';
            const rushClass = isRush ? ' is-rush' : '';
            const composerDraft = _composerDrafts[t.id] || '';
            const composerHtml = `<div class="t-foh-compose" onclick="event.stopPropagation();">
            <input type="text" class="t-foh-compose__input" id="ticket-compose-${t.id}" placeholder="Send a note to FOH about this ticket\u2026" value="${escHtml(composerDraft)}" maxlength="240" oninput="_composerDrafts[${t.id}] = this.value;" onkeydown="if(event.key==='Enter'){event.preventDefault(); sendTicketNoteToPOS(${t.id});}">
            <button class="t-foh-compose__send" data-loader-manual onclick="sendTicketNoteToPOS(${t.id}, this)" title="Send to FOH"><i class="fas fa-paper-plane"></i></button>
        </div>`;
            const ticketMsgsHtml = ticketMsgs.length ?
                `<div class="t-foh-notes" onclick="markTicketMsgsSeen(${t.id})">
                <div class="t-foh-notes__head"><i class="fas fa-comments"></i> FOH note${ticketMsgs.length>1?'s':''} <span class="t-foh-notes__count">${ticketMsgs.length}</span>${unseenMsgs.length ? '<span class="t-foh-notes__new">NEW</span>' : ''}</div>
                ${ticketMsgs.map(m => renderTicketMsg(m)).join('')}
                ${composerHtml}
            </div>` :
                `<div class="t-foh-notes t-foh-notes--empty">${composerHtml}</div>`;
            const rushBadge = isRush ? '<span class="t-rush-badge"><i class="fas fa-bolt"></i> RUSH</span>' : '';
            return `
            <div class="ticket t-${t.kitchen_status} ${isUrgent?'urgent':''}${flashClass}${rushClass}" data-id="${t.id}" data-status="${t.kitchen_status}" data-priority="${isRush?'1':'0'}">
                <div class="t-head">
                    <div class="t-head-main">
                        <div class="t-table">${escHtml(tableLbl)}${rushBadge}</div>
                        <div class="t-ref"><span class="t-ref__code">${escHtml(t.reference)}</span> <span class="t-status-pill s-${t.kitchen_status}">${t.kitchen_status.replace('_',' ')}</span></div>
                    </div>
                    <div class="t-timer-wrap">
                        <div class="t-timer ${ec}" data-fired="${firedAt||''}">${fmtElapsed(elapsed)}</div>
                        <div class="t-timer-note">${escHtml(timerStatus(elapsed, t.kitchen_status, firedAt))}</div>
                    </div>
                    <div class="t-meta"><span class="t-service-pill t-service-pill--${escHtml(t.order_type || 'walk_in')}"><i class="fas ${t.order_type === 'room_service' ? 'fa-bell-concierge' : 'fa-utensils'}"></i> ${escHtml(serviceLabel)}</span></div>
                    ${placedLine}
                </div>
                ${detailStrip}
                ${orderNote}
                ${ticketMsgsHtml}
                ${otherLabel}
                ${nextStepHtml}
                <div class="t-items">${items}</div>
                <div class="t-foot">
                    ${hasPending ? `<button class="b-start-all is-primary" data-loader-manual onclick="act('start_ticket',${t.id},null,this)" data-help="Start All|Mark every pending item on this ticket as &lsquo;preparing&rsquo;. Use it when you fire the whole ticket at once instead of starting each line individually."><i class="fas fa-fire"></i> Start All</button>` : `<button class="b-view" data-loader-manual onclick="openFullOrder(${t.id}, this)" data-help="View whole order|See every line on this order across all stations (Kitchen / Bar / Coffee Bar). Useful when timing your prep with the bar."><i class="fas fa-eye"></i> View order</button>`}
                    ${canCancelBeforePrep ? `<button class="b-cancel" data-loader-manual onclick="cancelTicketBeforePrep(${t.id},this)" data-help="Cancel before prep|Fully cancels this order while all items are still pending. Stock is restored and the ticket disappears from all station boards."><i class="fas fa-xmark-circle"></i> Cancel</button>` : ''}
                    <button class="b-rush${isRush?' is-rush':''}" data-loader-manual onclick="togglePriority(${t.id},this)" data-help="Rush / Priority|Flag this ticket as urgent so FOH knows it needs express service. A RUSH badge appears and the ticket is highlighted in red. Tap again to clear."><i class="fas fa-bolt"></i> ${isRush ? 'Un-rush' : 'Rush'}</button>
                    <button class="b-bump-all${allMyReady ? ' is-primary' : ''}" data-loader-manual onclick="act('bump_ticket',${t.id},null,this)" ${!hasMyItems?'disabled':''} data-help="Bump ticket|Mark this whole ticket as DELIVERED to the customer and clear it from the kitchen board.\nUse Bump when the runner has carried the food to the table or pickup counter.\nIt does NOT undo the order, refund money, or restore stock — stock was already deducted at order placement. If you bumped by mistake, you have 10 minutes to use Recall."><i class="fas fa-check-double"></i> Bump</button>
                    <a class="b-log" href="order-lifecycle.php?id=${t.id}" target="_blank" data-help="Order log|Open the full order lifecycle — placement, kitchen events, stock movements, and payment — in a new tab."><i class="fas fa-stream"></i> Log</a>
                </div>
            </div>`;
        }

        /* Render a single FOH message inside a ticket card. Uses _replyDrafts to
           preserve typed text across the 8-second auto-refresh. */
        function renderTicketMsg(m) {
            const isUrgent = m.priority === 'urgent';
            const draft = _replyDrafts[m.id] || '';
            /* Outbound: this station sent the note to FOH. Render right-aligned chat-bubble style. */
            if (m.is_outbound) {
                const replyLine = m.reply_message ? `<div class="t-foh-msg__reply"><i class="fas fa-reply"></i> ${escHtml(m.replied_by_name || 'FOH')}: ${escHtml(m.reply_message)} ${m.replied_at ? `<span style="opacity:.7;">${escHtml(fmtTime(m.replied_at))}</span>` : ''}</div>` : '';
                return `<div class="t-foh-msg t-foh-msg--out${isUrgent ? ' is-urgent' : ''}" onclick="event.stopPropagation();">
                <div class="t-foh-msg__from"><i class="fas fa-paper-plane"></i> You → FOH · ${escHtml(fmtTime(m.created_at))}${m.optimistic ? ' · <span class="t-foh-msg__pending">sending…</span>' : ' · <span class="t-foh-msg__sent">sent</span>'}</div>
                <div class="t-foh-msg__body">${escHtml(m.message)}</div>
                ${replyLine}
            </div>`;
            }
            if (m.reply_message) {
                const rt = m.replied_at ? fmtTime(m.replied_at) : '';
                return `<div class="t-foh-msg${isUrgent ? ' is-urgent' : ''}" onclick="event.stopPropagation();">
                <div class="t-foh-msg__from"><i class="fas fa-user-tie"></i> ${escHtml(m.sent_by_name || 'FOH')} · ${escHtml(fmtTime(m.created_at))}${isUrgent ? ' · <span class="t-foh-msg__urgent">URGENT</span>' : ''}</div>
                <div class="t-foh-msg__body">${escHtml(m.message)}</div>
                <div class="t-foh-msg__reply"><i class="fas fa-reply"></i> ${escHtml(m.replied_by_name || 'You')}: ${escHtml(m.reply_message)} <span style="opacity:.7;">${rt}</span></div>
            </div>`;
            }
            const ticketQuickChips = KDS_QUICK_REPLIES.map(r =>
                `<button type="button" class="kds-quick-reply-chip kds-quick-reply-chip--sm" data-loader-manual onclick="quickReplyFromTicket(${m.id},'${escJsSingle(r)}',this)">${escHtml(r)}</button>`
            ).join('');
            return `<div class="t-foh-msg${isUrgent ? ' is-urgent' : ''}" onclick="event.stopPropagation();">
            <div class="t-foh-msg__from"><i class="fas fa-user-tie"></i> ${escHtml(m.sent_by_name || 'FOH')} · ${escHtml(fmtTime(m.created_at))}${isUrgent ? ' · <span class="t-foh-msg__urgent">URGENT</span>' : ''}</div>
            <div class="t-foh-msg__body">${escHtml(m.message)}</div>
            <div class="t-foh-msg__quick-replies">${ticketQuickChips}</div>
            <div class="t-foh-msg__actions">
                <input type="text" class="t-foh-msg__input" id="ticket-reply-${m.id}" placeholder="Custom reply…" maxlength="255" value="${escHtml(draft)}" oninput="_replyDrafts[${m.id}] = this.value;" onkeydown="if(event.key==='Enter'){event.preventDefault();replyFromTicket(${m.id}, this.closest('.t-foh-msg__actions')?.querySelector('.t-foh-msg__send'));}">
                <button type="button" class="t-foh-msg__send" data-loader-manual onclick="replyFromTicket(${m.id}, this)" title="Send reply"><i class="fas fa-paper-plane"></i></button>
                <button type="button" class="t-foh-msg__ack t-foh-msg__ack--dismiss" data-loader-manual onclick="ackStationMessage(${m.id}, this)" title="Dismiss"><i class="fas fa-times"></i></button>
            </div>
        </div>`;
        }

        /* Once the cook taps the FOH-notes block on a ticket, stop the flash. */
        function markTicketMsgsSeen(ticketId) {
            const t = (state.tickets || []).find(x => parseInt(x.id, 10) === parseInt(ticketId, 10));
            if (!t) return;
            (state.messages || []).filter(m => m.order_ref === t.reference).forEach(m => {
                _seenTicketMsgIds.add(parseInt(m.id, 10));
            });
            const card = document.querySelector(`.ticket[data-id="${ticketId}"]`);
            if (card) card.classList.remove('has-foh-flash');
        }

        /* Reply from inside a ticket card. Reads the per-ticket input id and
           forwards to replyToMessage which expects a reply-{id} input — so we
           stash the value into reply-{id} first, then fall through. */
        function replyFromTicket(messageId, triggerButton = null) {
            const tInput = document.getElementById('ticket-reply-' + messageId);
            if (!tInput) return;
            const v = (tInput.value || '').trim();
            if (!v) {
                toast('Type a reply first', true);
                tInput.focus();
                return;
            }
            let bottomInput = document.getElementById('reply-' + messageId);
            if (!bottomInput) {
                /* Bottom-panel input may not exist if rendered hidden — create a sandbox */
                bottomInput = document.createElement('input');
                bottomInput.type = 'hidden';
                bottomInput.id = 'reply-' + messageId;
                document.body.appendChild(bottomInput);
            }
            bottomInput.value = v;
            delete _replyDrafts[messageId];
            replyToMessage(messageId, triggerButton);
        }

        /* Send a fresh note from this station to the cashier who placed the ticket.
           Optimistically appends the note as a chat bubble in the ticket card so the
           cook sees it immediately; the bubble persists across the 8-second feed
           refresh via _outboundQueue. */
        async function sendTicketNoteToPOS(ticketId, triggerButton = null) {
            const input = document.getElementById('ticket-compose-' + ticketId);
            if (!input) return;
            const v = (input.value || '').trim();
            if (!v) {
                toast('Type a note first', true);
                input.focus();
                return;
            }
            const ticket = (state.tickets || []).find(x => parseInt(x.id, 10) === parseInt(ticketId, 10));
            if (!ticket) {
                toast('Ticket not found', true);
                return;
            }
            const loadingButton = setStationClickerLoading(triggerButton || input.closest('.t-foh-compose')?.querySelector('.t-foh-compose__send'), true);
            const tempId = 'tmp-' + Date.now();
            const optimistic = {
                id: tempId,
                station: STATION,
                message: v,
                order_id: ticket.id,
                order_ref: ticket.reference,
                priority: 'normal',
                source: 'station',
                sent_by_name: 'You',
                created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                is_outbound: true,
                optimistic: true
            };
            _outboundQueue.push(optimistic);
            state.messages = (state.messages || []).concat([optimistic]);
            delete _composerDrafts[ticketId];
            input.value = '';
            render();
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', 'send_to_pos');
            fd.append('station', STATION);
            fd.append('order_id', ticketId);
            fd.append('message', v);
            try {
                const r = await fetch(apiUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) {
                    /* Roll back optimistic message on failure. */
                    const idx = _outboundQueue.findIndex(o => o.id === tempId);
                    if (idx > -1) _outboundQueue.splice(idx, 1);
                    state.messages = (state.messages || []).filter(m => m.id !== tempId);
                    _composerDrafts[ticketId] = v;
                    render();
                    toast(j.error || 'Send failed', true);
                    return;
                }
                /* Replace temp with confirmed id and drop the optimistic flag. */
                const idx = _outboundQueue.findIndex(o => o.id === tempId);
                if (idx > -1) {
                    _outboundQueue[idx].id = j.message_id;
                    _outboundQueue[idx].optimistic = false;
                }
                state.messages = (state.messages || []).map(m => {
                    if (m.id === tempId) {
                        m.id = j.message_id;
                        m.optimistic = false;
                    }
                    return m;
                });
                render();
            } catch (e) {
                const idx = _outboundQueue.findIndex(o => o.id === tempId);
                if (idx > -1) _outboundQueue.splice(idx, 1);
                state.messages = (state.messages || []).filter(m => m.id !== tempId);
                _composerDrafts[ticketId] = v;
                render();
                toast('Network error', true);
            } finally {
                setStationClickerLoading(loadingButton, false);
            }
        }

        function itemHtml(it) {
            const isMine = it.is_mine == 1 || it.is_mine === true;
            const note = it.notes ? `<div class="nt">→ ${escHtml(it.notes)}</div>` : '';
            const qty = parseFloat(it.quantity);
            const qStr = qty % 1 === 0 ? qty : qty.toFixed(1);
            const stationLabel = {
                'kitchen': 'Kitchen',
                'bar': 'Bar',
                'coffee_bar': 'Coffee Bar'
            } [it.station] || it.station;
            const otherStationBadge = !isMine ?
                `<span class="item-station-badge stn-${escHtml(it.station)}">${escHtml(stationLabel)}</span>` : '';
            const recipeCue = isMine ? '<span class="recipe-pill"><i class="fas fa-book-open"></i> Recipe</span>' : '';
            const actions = isMine ? `<div class="item-actions">
                <button class="b-start" data-loader-manual onclick="act('start_item',null,${it.id},this)" ${it.kds_status!=='pending'?'disabled':''} title="Start" data-help="Start prepping|Tap when you actually pick this item up to prepare it. Stamps the started_at time so management can see your prep speed."><i class="fas fa-fire"></i><span>Start</span></button>
                <button class="b-ready" data-loader-manual onclick="act('ready_item',null,${it.id},this)" ${['served','ready','collection'].includes(it.kds_status)?'disabled':''} title="Ready" data-help="Ready for pickup|Plate/glass is up and the runner can collect it. The whole ticket goes &lsquo;READY&rsquo; once every line for this station is ready."><i class="fas fa-bell"></i><span>Ready</span></button>
                <button class="b-collect" data-loader-manual onclick="act('collect_item',null,${it.id},this)" ${it.kds_status!=='ready'?'disabled':''} title="Collect" data-help="Mark for collection|Runner is collecting this item. Stock has been deducted. Moves the item to collection status before final served confirmation."><i class="fas fa-hand-holding"></i><span>Collect</span></button>
                <button class="b-serve" data-loader-manual onclick="act('serve_item',null,${it.id},this)" ${it.kds_status==='served'?'disabled':''} title="Served" data-help="Item served|Mark this single item as delivered. Use it when one line is delivered before the rest of the ticket. Most staff just Bump the whole ticket instead."><i class="fas fa-check"></i><span>Served</span></button>
                <button class="b-recipe" data-loader-manual onclick="openRecipeCard(${it.id}, this)" title="Recipe" data-help="Recipe card|Open the recipe and ingredient card for this ticket line. Use this when you need portion, yield or prep guidance without leaving the station screen."><i class="fas fa-book-open"></i><span>Recipe</span></button>
                                <button class="b-86" data-loader-manual onclick="open86Modal(${it.id},'${escJsSingle(it.item_name)}',this)" ${['served','void'].includes(it.kds_status)?'disabled':''} title="86 item" data-help="86 this item|Ingredient ran out or item cannot be prepared. Removes it from the order and sends an urgent alert to FOH so they can offer the guest an alternative."><i class="fas fa-ban"></i><span>86</span></button>
              </div>` : `<div class="item-actions item-actions--other"><span class="item-other-station-note"><i class="fas fa-eye-slash"></i></span></div>`;
            return `<div class="item${isMine ? '' : ' item--other-station'}">
            <div class="qty">${qStr}×</div>
            <div class="info">
                <div class="nm">${escHtml(it.item_name)}${otherStationBadge}${recipeCue}</div>
                ${note}
                <span class="badge b-${it.kds_status}">${it.kds_status}</span>
            </div>
            ${actions}
        </div>`;
        }

        function render() {
            const board = document.getElementById('board');
            /* Snapshot any in-progress composer drafts inside ticket cards BEFORE we
               rebuild the board so the cook doesn't lose what they were typing. */
            document.querySelectorAll('.t-foh-compose__input, .t-foh-msg__input').forEach(el => {
                if (!el.value) return;
                if (el.classList.contains('t-foh-compose__input')) {
                    const id = el.id.replace(/^ticket-compose-/, '');
                    if (id) _composerDrafts[id] = el.value;
                } else {
                    const id = el.id.replace(/^ticket-reply-/, '');
                    if (id) _replyDrafts[id] = el.value;
                }
            });
            const filtered = currentFilter === 'all' ? state.tickets : state.tickets.filter(t => t.kitchen_status === currentFilter);
            // Rush tickets always bubble to top regardless of age
            filtered.sort((a, b) => (b.is_priority || 0) - (a.is_priority || 0));
            if (!filtered.length) {
                board.innerHTML = `<div class="empty"><i class="fas fa-utensils"></i><h2>No ${currentFilter==='all'?'open':currentFilter} tickets</h2><p>New orders will appear here when fired from the till.</p></div>`;
            } else {
                board.innerHTML = filtered.map(ticketHtml).join('');
            }
            document.getElementById('openCount').textContent = state.tickets.length;
            document.getElementById('readyCount').textContent = state.tickets.filter(t => t.kitchen_status === 'ready').length;
            document.getElementById('servedTodayCount').textContent = state.served_today || 0;
            renderAllDay();
            renderMessages();
            renderStationControl();
            renderStationStatus();
        }

        function renderStationStatus() {
            const bw = state.business_window;
            const overlay = document.getElementById('station-closed-overlay');
            const pill = document.querySelector('.station-open-pill');
            if (!overlay) return;
            const isOpen = bw && bw.is_open_now;
            overlay.classList.toggle('visible', !isOpen);
            document.body.classList.toggle('closed-banner-active', !isOpen);
            if (pill) {
                pill.className = 'station-open-pill ' + (isOpen ? 'open' : 'closed');
                pill.innerHTML = isOpen ?
                    '<i class="fas fa-circle-check"></i> Open' :
                    '<i class="fas fa-circle-minus"></i> Closed';
            }
            if (!isOpen && bw) {
                const hoursEl = document.getElementById('scHours');
                const nextEl = document.getElementById('scNext');
                if (hoursEl && bw.hours) hoursEl.textContent = 'Hours: ' + bw.hours;
                if (nextEl) nextEl.textContent = 'Polling for opening…';
            }
        }

        function renderAllDay() {
            const list = document.getElementById('allDayList');
            const count = document.getElementById('allDayCount');
            if (!list || !count) return;
            const rows = state.all_day || [];
            count.textContent = rows.length;
            list.innerHTML = rows.length ? rows.map(row => `
            <div class="all-day-chip"><span>${escHtml(row.item_name)} All Day</span><strong>${fmtQty(row.quantity)}</strong></div>
        `).join('') : '<div class="station-empty-line">No items working.</div>';
        }

        function renderMessages() {
            const list = document.getElementById('stationMessages');
            const count = document.getElementById('stationMessageCount');
            if (!list || !count) return;
            /* Capture any in-progress drafts in BOTH the bottom panel and ticket-card inputs
               BEFORE we wipe them out with innerHTML. Drafts survive across the 8s polling. */
            document.querySelectorAll('.station-note__reply-input, .t-foh-msg__input').forEach(el => {
                const id = el.id.replace(/^reply-|^ticket-reply-/, '');
                if (id && el.value) _replyDrafts[id] = el.value;
            });
            const rows = (state.messages || []).filter(m => (m.source || '') !== 'station');
            const urgentCount = rows.filter(m => m.priority === 'urgent').length;
            count.textContent = rows.length;
            count.className = urgentCount ? 'station-message-count--urgent' : '';
            list.innerHTML = rows.length ? rows.map(msg => {
                const isUrgent = msg.priority === 'urgent';
                const urgentBadge = isUrgent ? '<span class="station-note__urgent-badge">URGENT</span>' : '';
                /* Order reference badge — clickable to scroll/highlight the matching ticket */
                const orderBadge = msg.order_ref ?
                    `<button type="button" class="station-note__order-ref" onclick="highlightTicketByRef(${JSON.stringify(msg.order_ref)})" title="Jump to ticket ${escHtml(msg.order_ref)}"><i class="fas fa-receipt"></i> ${escHtml(msg.order_ref)}</button>` :
                    '';
                let replySection = '';
                if (msg.reply_message) {
                    const rt = msg.replied_at ? fmtTime(msg.replied_at) : '';
                    replySection = `<div class="station-note__station-reply"><i class="fas fa-reply"></i> ${escHtml(msg.replied_by_name || 'Station')}: ${escHtml(msg.reply_message)} <span style="color:#6c757d;">${rt}</span></div>`;
                } else {
                    const draft = _replyDrafts[msg.id] || '';
                    const quickChips = KDS_QUICK_REPLIES.map(r =>
                        `<button type="button" class="kds-quick-reply-chip" data-loader-manual onclick="quickReplyToMessage(${msg.id},'${escJsSingle(r)}',this)">${escHtml(r)}</button>`
                    ).join('');
                    replySection = `<div class="station-note__quick-replies">${quickChips}</div>
                <div class="station-note__reply-row">
                    <input type="text" class="station-note__reply-input" id="reply-${msg.id}" placeholder="Or type a custom reply…" maxlength="255" value="${escHtml(draft)}" oninput="_replyDrafts[${msg.id}] = this.value;" onkeydown="if(event.key==='Enter'){event.preventDefault();replyToMessage(${msg.id});}">
                    <button type="button" class="station-note__reply-btn" data-loader-manual onclick="replyToMessage(${msg.id}, this)"><i class="fas fa-paper-plane"></i> Send</button>
                </div>
                <div class="station-note__dismiss-row">
                    <button type="button" class="station-note__dismiss-btn" data-loader-manual onclick="ackStationMessage(${msg.id}, this)"><i class="fas fa-times-circle"></i> Dismiss</button>
                </div>`;
                }
                return `<div class="station-note${isUrgent ? ' station-note--urgent' : ''}" data-msg-id="${msg.id}">
                <div style="min-width:0;width:100%;">
                    <div class="station-note__message">${urgentBadge}${escHtml(msg.message)}</div>
                    <div class="station-note__meta">${escHtml(msg.sent_by_name || 'FOH')} · ${escHtml(fmtTime(msg.created_at))}${orderBadge ? ' · ' + orderBadge : ''}</div>
                    ${replySection}
                </div>
            </div>`;
            }).join('') : '<div class="station-empty-line">No active notes.</div>';
            updateTabTitle();
        }

        function highlightTicketByRef(ref) {
            /* Try to find and scroll to the matching ticket card on the board */
            const tickets = state.tickets || [];
            const match = tickets.find(t => t.reference === ref);
            if (!match) {
                toast('Order ' + ref + ' not on this board', true);
                return;
            }
            const el = document.querySelector(`.ticket[data-id="${match.id}"]`);
            if (!el) {
                toast('Ticket not visible — may be filtered', true);
                return;
            }
            /* Switch to "all" filter so the ticket is visible */
            if (currentFilter !== 'all') {
                document.querySelectorAll('.filter button').forEach(b => b.classList.remove('active'));
                const allBtn = document.querySelector('.filter button[data-filter="all"]');
                if (allBtn) allBtn.classList.add('active');
                currentFilter = 'all';
                render();
            }
            el.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            el.style.outline = '3px solid #f7d889';
            el.style.outlineOffset = '2px';
            setTimeout(() => {
                el.style.outline = '';
                el.style.outlineOffset = '';
            }, 3000);
        }

        function renderStationControl() {
            const status = document.getElementById('stationControlStatus');
            const btn = document.getElementById('stationPauseBtn');
            const wait = document.getElementById('stationWaitInput');
            if (!status || !btn || !wait) return;
            const control = state.station_control || {
                paused: false,
                wait_minutes: 20,
                reason: ''
            };
            const paused = !!control.paused;
            status.classList.toggle('paused', paused);
            status.innerHTML = paused ?
                `<strong>Online paused</strong><br>${escHtml(control.reason || 'Chef pause is active.')} · ${parseInt(control.wait_minutes || 20, 10)} min wait saved` :
                `<strong>Online live</strong><br>Estimated wait ${parseInt(control.wait_minutes || 20, 10)} min`;
            btn.classList.toggle('live', !paused);
            btn.innerHTML = paused ? '<i class="fas fa-play"></i><span>Go Live</span>' : '<i class="fas fa-pause"></i><span>Pause</span>';
            btn.title = paused ? 'Resume online orders' : 'Pause online orders';
            if (document.activeElement !== wait) wait.value = parseInt(control.wait_minutes || 20, 10);
        }

        function tickClock() {
            const d = new Date();
            document.getElementById('clock').textContent = d.toLocaleTimeString();
            document.querySelectorAll('.t-timer').forEach(el => {
                const s = elapsedSeconds(el.dataset.fired);
                el.innerHTML = fmtElapsed(s);
                const status = el.closest('.ticket')?.dataset?.status;
                el.classList.remove('warn', 'late');
                const ec = elapsedClass(s, status);
                if (ec) el.classList.add(ec);
                const note = el.closest('.t-timer-wrap')?.querySelector('.t-timer-note');
                if (note) note.textContent = timerStatus(s, status, el.dataset.fired);
                if (s > 720 && status !== 'ready') el.closest('.ticket').classList.add('urgent');
            });
        }

        let polling = false;
        async function poll() {
            if (polling) return;
            polling = true;
            try {
                const fd = new FormData();
                fd.append('action', 'feed');
                fd.append('csrf_token', csrf);
                fd.append('station', STATION);
                fd.append('fingerprint', lastFeedFingerprint); /* skip full payload when board unchanged */
                const r = await fetch(apiUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) {
                    polling = false;
                    return;
                }

                /* ── Lightweight "unchanged" fast-path ─────────────────────────
                   Server confirmed the board state hasn't changed.  Still update
                   station control + business window (pause/open status may flip). */
                if (j.unchanged) {
                    lastFeedFingerprint = j.fingerprint || lastFeedFingerprint;
                    if (j.station_control) {
                        state.station_control = j.station_control;
                        renderStationControl();
                    }
                    if (j.business_window) {
                        state.business_window = j.business_window;
                        renderStationStatus();
                    }
                    polling = false;
                    return;
                }

                /* ── Full board update ──────────────────────────────────────── */
                const newIds = new Set(j.tickets.map(t => t.id));
                const arrived = [...newIds].filter(id => !knownIds.has(id));
                if (arrived.length) {
                    if (soundOn) RHSounds.play('normal');
                    RHNotif.show({
                        title: arrived.length === 1 ? 'New Ticket' : `${arrived.length} New Tickets`,
                        body: 'Tap to start cooking.',
                        type: 'normal',
                        source: STATION === 'kitchen' ? 'Kitchen' : STATION === 'bar' ? 'Bar' : 'Coffee Bar',
                        sound: false,
                    });
                }
                knownIds = newIds;
                const nextMessages = j.messages || [];
                const nextMessageIds = new Set(nextMessages.map(m => m.id));
                const freshMessages = [...nextMessageIds].filter(id => !knownMessageIds.has(id));
                if (hasPolledMessages && freshMessages.length) {
                    const freshData = nextMessages.filter(m => freshMessages.includes(m.id) || freshMessages.includes(String(m.id)));
                    const hasUrgent = freshData.some(m => m.priority === 'urgent');
                    if (soundOn) RHSounds.play(hasUrgent ? 'urgent' : 'normal');
                    freshData.forEach(m => {
                        const sndr = m.sent_by_name ? m.sent_by_name + ' (FOH)' : 'FOH';
                        RHNotif.show({
                            title: hasUrgent ? '⚠ URGENT from FOH' : 'FOH Note',
                            body: m.message || '',
                            type: hasUrgent ? 'urgent' : 'info',
                            source: sndr,
                            sound: false,
                        });
                    });
                }
                knownMessageIds = nextMessageIds;
                hasPolledMessages = true;
                const normalizedMessages = nextMessages.map(m => ({
                    ...m,
                    is_outbound: !!m.is_outbound || (m.source || '') === 'station'
                }));
                const mergedMessages = normalizedMessages.slice();
                _outboundQueue
                    .filter(o => j.tickets.some(t => t.reference === o.order_ref))
                    .forEach(o => {
                        const exists = mergedMessages.some(m => String(m.id) === String(o.id));
                        if (!exists) mergedMessages.push(o);
                    });

                state = {
                    tickets: j.tickets,
                    served_today: j.served_today,
                    all_day: j.all_day || [],
                    /* Merge in outbound notes this session sent and dedupe by id so
                       server-confirmed rows replace optimistic copies cleanly. */
                    messages: mergedMessages,
                    station_control: j.station_control || state.station_control || {
                        paused: false,
                        wait_minutes: 20,
                        reason: ''
                    },
                    business_window: j.business_window || state.business_window || null
                };
                lastFeedFingerprint = j.fingerprint || '';
                render();
            } catch (e) {
                /* swallow — keep last state */
            }
            polling = false;
        }

        const _stationActionLocks = new Set();

        /* Apply an optimistic local state change so the UI responds instantly.
           Returns a snapshot of the pre-change state so callers can roll back. */
        function applyOptimisticAction(action, orderId, itemId) {
            const snap = JSON.parse(JSON.stringify(state));
            const oid = parseInt(orderId, 10) || 0;
            const iid = parseInt(itemId, 10) || 0;

            if (action === 'bump_ticket' && oid > 0) {
                state.tickets.forEach(t => {
                    if (parseInt(t.id, 10) !== oid) return;
                    (t.items || []).forEach(i => {
                        if (i.is_mine == 1 || i.is_mine === true) i.kds_status = 'served';
                    });
                    t.kitchen_status = 'served';
                });
                // Remove fully-served tickets (all my items served) from the live board
                state.tickets = state.tickets.filter(t => {
                    if (parseInt(t.id, 10) !== oid) return true;
                    return (t.items || []).some(i => (i.is_mine == 1 || i.is_mine === true) && !['served','void'].includes(i.kds_status));
                });
            } else if (action === 'start_ticket' && oid > 0) {
                const t = state.tickets.find(t => parseInt(t.id, 10) === oid);
                if (t) {
                    (t.items || []).forEach(i => { if ((i.is_mine == 1 || i.is_mine === true) && i.kds_status === 'pending') i.kds_status = 'preparing'; });
                    t.kitchen_status = 'in_progress';
                }
            } else if (iid > 0) {
                const newStatus = { start_item: 'preparing', ready_item: 'ready', collect_item: 'collection', serve_item: 'served' }[action];
                if (newStatus) {
                    state.tickets.forEach(t => { (t.items || []).forEach(i => { if (parseInt(i.id, 10) === iid) i.kds_status = newStatus; }); });
                }
            }
            return snap;
        }

        async function act(action, orderId = null, itemId = null, triggerButton = null) {
            const lockKey = `${action}:${orderId || ''}:${itemId || ''}`;
            if (_stationActionLocks.has(lockKey)) return;
            _stationActionLocks.add(lockKey);

            // Optimistic: apply state immediately so the board reacts before the network round-trip
            const rollback = applyOptimisticAction(action, orderId, itemId);
            render();
            // Flash the button into loading state AFTER render (so it stays briefly visible on item actions)
            const loadingButton = setStationClickerLoading(triggerButton, true);

            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', action);
            fd.append('station', STATION);
            if (orderId !== null) fd.append('order_id', orderId);
            if (itemId !== null) fd.append('item_id', itemId);
            try {
                const r = await fetch(apiUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) {
                    // Rollback optimistic change then show the error
                    state = rollback;
                    render();
                    showStationIssue(j.error || 'Action failed', { modal: true });
                } else {
                    const msg = stationActionSuccessMessage(action);
                    toast(msg);
                    if (action === 'bump_ticket') {
                        if (soundOn) RHSounds.play('normal');
                        if (typeof RHNotif !== 'undefined' && RHNotif.show) {
                            RHNotif.show({ title: 'Ticket bumped', body: 'Cleared from board — delivered to the table.', type: 'success', source: STATION_LABEL, duration: 3500, sound: false });
                        }
                    }
                    lastFeedFingerprint = '';
                    // Background poll — don't await, just sync external changes
                    poll().catch(() => {});
                }
            } catch (e) {
                state = rollback;
                render();
                showStationIssue('Network error while updating the ticket. Please try again.', { modal: false });
            } finally {
                _stationActionLocks.delete(lockKey);
                setStationClickerLoading(loadingButton, false);
            }
        }

        async function cancelTicketBeforePrep(orderId, triggerButton = null) {
            const lockKey = `cancel_before_prep:${orderId}`;
            if (_stationActionLocks.has(lockKey)) return;

            // Show the cancel-reason modal.
            const modal = document.getElementById('kds-cancel-modal');
            const reasonsEl = document.getElementById('kds-cancel-reasons');
            const customEl = document.getElementById('kds-cancel-custom');
            const confirmBtn = document.getElementById('kds-cancel-confirm-btn');

            const presetReasons = [
                'Customer cancelled',
                'Wrong order sent',
                'Out of ingredient',
                'Quality issue',
                'Test / training order',
                'Other',
            ];
            let selectedReason = '';
            reasonsEl.innerHTML = presetReasons.map(r =>
                `<button type="button" class="kds-reason-btn" onclick="kdsSelectReason(this,'${r.replace(/'/g,"\\'")}')">${r}</button>`
            ).join('');
            customEl.value = '';
            customEl.placeholder = 'Additional details or custom reason…';

            window.kdsSelectReason = (el, reason) => {
                document.querySelectorAll('.kds-reason-btn').forEach(b => b.classList.remove('selected'));
                el.classList.add('selected');
                selectedReason = reason;
            };

            // Store trigger so we can pass it to the confirm handler
            confirmBtn.dataset.orderId = orderId;
            modal.style.display = 'flex';
            _cancelTriggerButton = triggerButton;
            _cancelLockKey = lockKey;
        }

        let _cancelTriggerButton = null;
        let _cancelLockKey = '';

        function closeCancelModal() {
            document.getElementById('kds-cancel-modal').style.display = 'none';
            _cancelTriggerButton = null;
            _cancelLockKey = '';
        }

        async function confirmCancelTicket() {
            const confirmBtn = document.getElementById('kds-cancel-confirm-btn');
            const orderId = parseInt(confirmBtn.dataset.orderId || '0', 10);
            if (!orderId) return;

            const selectedBtn = document.querySelector('.kds-reason-btn.selected');
            const preset = selectedBtn ? selectedBtn.textContent : '';
            const custom = (document.getElementById('kds-cancel-custom').value || '').trim();
            const reason = [preset, custom].filter(Boolean).join(' — ');
            if (reason.length < 8) {
                toast('Choose a reason before confirming', true);
                return;
            }

            if (_cancelLockKey) _stationActionLocks.add(_cancelLockKey);
            closeCancelModal();

            const loadingButton = setStationClickerLoading(_cancelTriggerButton, true);
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('order_id', orderId);
            fd.append('cancel_reason', reason);
            try {
                const r = await fetch('/api/cancel-order.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) {
                    showStationIssue(j.error || 'Cancel failed', {
                        modal: true
                    });
                    return;
                }
                toast(j.message || 'Order cancelled — FOH notified');
                lastFeedFingerprint = '';
                await poll();
            } catch (e) {
                showStationIssue('Network error while cancelling the ticket. Please try again.', {
                    modal: false
                });
            } finally {
                if (_cancelLockKey) _stationActionLocks.delete(_cancelLockKey);
                setStationClickerLoading(loadingButton, false);
                _cancelTriggerButton = null;
                _cancelLockKey = '';
            }
        }

        /* ── 86 item ──────────────────────────────────────────────────────── */
        let _86ItemId = null;
        let _86TriggerButton = null;

        function open86Modal(itemId, itemName, triggerButton = null) {
            _86ItemId = itemId;
            _86TriggerButton = triggerButton;
            const desc = document.getElementById('kds-86-desc');
            desc.innerHTML = `86 <strong>${escHtml(itemName)}</strong>? This removes it from the order and sends an urgent alert to FOH.`;
            document.getElementById('kds-86-modal').style.display = 'flex';
        }

        function close86Modal() {
            document.getElementById('kds-86-modal').style.display = 'none';
            _86ItemId = null;
            _86TriggerButton = null;
        }
        async function confirm86Item() {
            const itemId = _86ItemId;
            if (!itemId) return;
            close86Modal();
            const lb = setStationClickerLoading(_86TriggerButton, true);
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', 'void_item');
            fd.append('item_id', itemId);
            fd.append('station', STATION);
            try {
                const r = await fetch(apiUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) {
                    showStationIssue(j.error || '86 failed', {
                        modal: true
                    });
                    return;
                }
                toast('Item 86\'d — FOH notified');
                lastFeedFingerprint = '';
                await poll();
            } catch (e) {
                showStationIssue('Network error while sending the 86 notice. Please try again.', {
                    modal: false
                });
            } finally {
                setStationClickerLoading(lb, false);
            }
        }

        /* ── Priority / Rush toggle ───────────────────────────────────────── */
        async function togglePriority(orderId, triggerButton = null) {
            const lockKey = `priority:${orderId}`;
            if (_stationActionLocks.has(lockKey)) return;
            _stationActionLocks.add(lockKey);
            const lb = setStationClickerLoading(triggerButton, true);
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', 'toggle_priority');
            fd.append('order_id', orderId);
            fd.append('station', STATION);
            try {
                const r = await fetch(apiUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) {
                    showStationIssue(j.error || 'Priority toggle failed', {
                        modal: true
                    });
                    return;
                }
                toast(j.is_priority ? 'Marked as RUSH — FOH notified' : 'Rush cleared');
                lastFeedFingerprint = '';
                await poll();
            } catch (e) {
                showStationIssue('Network error while updating priority. Please try again.', {
                    modal: false
                });
            } finally {
                _stationActionLocks.delete(lockKey);
                setStationClickerLoading(lb, false);
            }
        }

        // Close new modals on Escape (extends existing keydown listener)
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeCancelModal();
                close86Modal();
            }
        });

        async function ackStationMessage(messageId, triggerButton = null) {
            const mid = parseInt(messageId, 10);
            // Optimistic: remove immediately so UI feels instant
            const prevMessages = (state.messages || []).slice();
            state.messages = prevMessages.filter(m => parseInt(m.id, 10) !== mid);
            knownMessageIds.delete(messageId);
            knownMessageIds.delete(String(messageId));
            delete _replyDrafts[messageId];
            _seenTicketMsgIds.delete(mid);
            render();

            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', 'ack_message');
            fd.append('station', STATION);
            fd.append('message_id', messageId);
            try {
                const r = await fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
                const j = await r.json();
                if (!j.ok) {
                    // Rollback
                    state.messages = prevMessages;
                    render();
                    toast(j.error || 'Dismiss failed — note restored', true);
                }
            } catch (e) {
                state.messages = prevMessages;
                render();
                toast('Network error — note restored', true);
            }
        }

        const KDS_QUICK_REPLIES = [
            'On it!',
            '5 minutes',
            '10 minutes',
            'Ready soon',
            'Out of stock',
            'Need clarification',
        ];

        /* Send a quick reply without needing an input element. */
        async function quickReplyToMessage(messageId, text, triggerEl = null) {
            const input = document.getElementById('reply-' + messageId);
            if (input) input.value = text;
            _replyDrafts[messageId] = text;
            await replyToMessage(messageId, triggerEl, text);
        }

        async function quickReplyFromTicket(messageId, text, triggerEl = null) {
            const tInput = document.getElementById('ticket-reply-' + messageId);
            if (tInput) tInput.value = text;
            _replyDrafts[messageId] = text;
            await replyFromTicket(messageId, triggerEl);
        }

        async function replyToMessage(messageId, triggerButton = null, directText = null) {
            const input = document.getElementById('reply-' + messageId);
            const reply = (directText || input?.value || _replyDrafts[messageId] || '').trim().slice(0, 255);
            if (!reply) {
                toast('Type a reply first', true);
                if (input) input.focus();
                return;
            }
            const loadingButton = setStationClickerLoading(triggerButton || input?.closest('.station-note__reply-row')?.querySelector('.station-note__reply-btn'), true);
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', 'ack_message');
            fd.append('station', STATION);
            fd.append('message_id', messageId);
            fd.append('reply', reply);
            try {
                const r = await fetch(apiUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) {
                    toast(j.error || 'Reply failed', true);
                    return;
                }
                state.messages = (state.messages || []).filter(m => parseInt(m.id, 10) !== parseInt(messageId, 10));
                knownMessageIds.delete(messageId);
                knownMessageIds.delete(String(messageId));
                delete _replyDrafts[messageId];
                _seenTicketMsgIds.delete(parseInt(messageId, 10));
                toast('Reply sent to FOH');
                render();
            } catch (e) {
                toast('Network error', true);
            } finally {
                setStationClickerLoading(loadingButton, false);
            }
        }

        function updateTabTitle() {
            const rows = state.messages || [];
            const urgentCount = rows.filter(m => m.priority === 'urgent').length;
            const total = rows.length;
            const base = <?php echo json_encode($STATION_LABEL . ($site_name ? ' — ' . $site_name : '')); ?>;
            if (urgentCount > 0) {
                document.title = `[${urgentCount} URGENT] ${base}`;
            } else if (total > 0) {
                document.title = `[${total} note${total > 1 ? 's' : ''}] ${base}`;
            } else {
                document.title = base;
            }
        }

        async function saveStationControl(paused, waitMinutes, triggerButton = null) {
            const loadingButton = setStationClickerLoading(triggerButton, true);
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', 'update_station_control');
            fd.append('station', STATION);
            fd.append('paused', paused ? '1' : '0');
            fd.append('wait_minutes', String(waitMinutes));
            fd.append('reason', paused ? 'Station paused from display' : '');
            try {
                const r = await fetch(apiUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) {
                    showStationIssue(j.error || 'Flow update failed', {
                        modal: false
                    });
                    return;
                }
                state.station_control = j.control || state.station_control;
                renderStationControl();
                toast(paused ? 'Online orders paused. FOH sees the wait time.' : 'Station is live. FOH can send tickets.');
            } catch (e) {
                showStationIssue('Network error while updating online flow. Please try again.', {
                    modal: false
                });
            } finally {
                setStationClickerLoading(loadingButton, false);
            }
        }

        function toggleStationPause(triggerButton = null) {
            const control = state.station_control || {
                paused: false,
                wait_minutes: 20
            };
            const wait = parseInt(document.getElementById('stationWaitInput')?.value || control.wait_minutes || 20, 10);
            saveStationControl(!control.paused, wait, triggerButton);
        }

        function saveStationWait(triggerButton = null) {
            const control = state.station_control || {
                paused: false,
                wait_minutes: 20
            };
            const wait = parseInt(document.getElementById('stationWaitInput')?.value || control.wait_minutes || 20, 10);
            saveStationControl(!!control.paused, wait, triggerButton);
        }

        function toast(msg, err = false) {
            /* Quick operational feedback (bumped, failed, saved) — keeps the simple topbar toast */
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.toggle('err', err);
            t.classList.add('show');
            clearTimeout(window._tT);
            window._tT = setTimeout(() => t.classList.remove('show'), 2200);
        }

        /* ----- Modal helpers ----- */
        let _modalOpenedAt = 0;

        function openModal(title) {
            document.getElementById('rhModalTitle').textContent = title;
            document.getElementById('rhModalBody').innerHTML = '<div style="text-align:center; padding:30px; color:#9aa3af;"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
            document.getElementById('rhModal').style.display = 'flex';
            _modalOpenedAt = Date.now();
        }

        function closeModal() {
            if (Date.now() - _modalOpenedAt < 250) return; // guard against phantom touch clicks
            document.getElementById('rhModal').style.display = 'none';
        }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeModal();
        });

        function fmtTime(iso) {
            if (!iso) return '—';
            const t = iso.includes('T') ? new Date(iso) : new Date(iso.replace(' ', 'T'));
            if (isNaN(t.getTime())) return '—';
            return String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0') + ':' + String(t.getSeconds()).padStart(2, '0');
        }

        function fmtDur(sec) {
            sec = Math.max(0, parseInt(sec) || 0);
            if (!sec) return '—';
            if (sec < 60) return sec + ' sec';
            const m = Math.floor(sec / 60),
                s = sec % 60;
            if (m < 5) return m + ' min ' + String(s).padStart(2, '0') + ' sec';
            if (m < 60) return m + ' min';
            const h = Math.floor(m / 60),
                mm = m % 60;
            return h + ' hr ' + mm + ' min';
        }

        function fmtMoney(n) {
            return Number(n || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        async function openServedToday(day = 'current', triggerButton = null) {
            const loadingButton = setStationClickerLoading(triggerButton, true);
            const selectedDay = ['current', 'yesterday', 'after_hours'].includes(day) ? day : 'current';
            const title = selectedDay === 'after_hours' ? 'After Hours Served Menu' : (selectedDay === 'yesterday' ? 'Yesterday Served Menu' : 'Served Menu');
            openModal(title);
            showKdsLoader('Loading served menu…');
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('action', 'served_today');
                fd.append('station', STATION);
                fd.append('day', selectedDay);
                const r = await fetch(apiUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) {
                    document.getElementById('rhModalBody').innerHTML = '<div style="color:#fca5a5; padding:16px;">' + escHtml(j.error || 'Failed to load') + '</div>';
                    return;
                }
                const rows = j.tickets || [];
                const logs = j.logs || [];

                /* ── Period tabs ── */
                const tabs = `<div class="rh-period-tabs">
                <button class="${selectedDay === 'current' ? 'active' : ''}" data-loader-manual onclick="openServedToday('current', this)"><i class="fas fa-sun"></i> Current Shift</button>
                <button class="${selectedDay === 'yesterday' ? 'active' : ''}" data-loader-manual onclick="openServedToday('yesterday', this)"><i class="fas fa-clock-rotate-left"></i> Yesterday</button>
                <button class="${selectedDay === 'after_hours' ? 'active' : ''}" data-loader-manual onclick="openServedToday('after_hours', this)"><i class="fas fa-moon"></i> After Hours</button>
            </div>`;

                /* ── Window note ── */
                const windowNote = j.window?.hours ? `<div class="rh-window-note">
                <i class="fas fa-clock"></i>
                <strong style="color:#fff;">${escHtml(j.window.hours)}</strong>
                <span style="color:#6b7280;">·</span>
                <span>${escHtml(j.window.label || '')}</span>
            </div>` : '';

                /* ── KPIs ── */
                const kpi = `<div class="rh-kpi-row">
                <div class="rh-kpi"><div class="lbl"><i class="fas fa-receipt"></i> Orders served</div><div class="val">${rows.length}</div></div>
                <div class="rh-kpi"><div class="lbl"><i class="fas fa-utensils"></i> Items served</div><div class="val">${fmtQty(j.total_qty)}</div></div>
                <div class="rh-kpi"><div class="lbl"><i class="fas fa-coins"></i> Revenue</div><div class="val">${fmtMoney(j.revenue)}</div></div>
                <div class="rh-kpi"><div class="lbl"><i class="fas fa-stopwatch"></i> Avg prep</div><div class="val">${fmtDur(j.avg_seconds)}</div></div>
            </div>`;

                /* ── Log event type metadata ── */
                const LOG_META = {
                    fired: {
                        color: '#f59e0b',
                        bg: 'rgba(245,158,11,0.12)',
                        icon: 'fas fa-fire',
                        label: 'Fired'
                    },
                    serve: {
                        color: '#10b981',
                        bg: 'rgba(16,185,129,0.12)',
                        icon: 'fas fa-check-circle',
                        label: 'Served'
                    },
                    served: {
                        color: '#10b981',
                        bg: 'rgba(16,185,129,0.12)',
                        icon: 'fas fa-check-circle',
                        label: 'Served'
                    },
                    ready: {
                        color: '#38bdf8',
                        bg: 'rgba(56,189,248,0.12)',
                        icon: 'fas fa-bell',
                        label: 'Ready'
                    },
                    preparing: {
                        color: '#f59e0b',
                        bg: 'rgba(245,158,11,0.12)',
                        icon: 'fas fa-fire-burner',
                        label: 'Preparing'
                    },
                    status_change: {
                        color: '#818cf8',
                        bg: 'rgba(129,140,248,0.12)',
                        icon: 'fas fa-arrow-right-arrow-left',
                        label: 'Status'
                    },
                    cancel: {
                        color: '#ef4444',
                        bg: 'rgba(239,68,68,0.12)',
                        icon: 'fas fa-ban',
                        label: 'Cancelled'
                    },
                    cancelled: {
                        color: '#ef4444',
                        bg: 'rgba(239,68,68,0.12)',
                        icon: 'fas fa-ban',
                        label: 'Cancelled'
                    },
                    void: {
                        color: '#ef4444',
                        bg: 'rgba(239,68,68,0.12)',
                        icon: 'fas fa-trash-xmark',
                        label: 'Void'
                    },
                    voided: {
                        color: '#ef4444',
                        bg: 'rgba(239,68,68,0.12)',
                        icon: 'fas fa-trash-xmark',
                        label: 'Void'
                    },
                    note: {
                        color: '#a78bfa',
                        bg: 'rgba(167,139,250,0.12)',
                        icon: 'fas fa-comment',
                        label: 'Note'
                    },
                    login: {
                        color: '#6b7280',
                        bg: 'rgba(107,114,128,0.12)',
                        icon: 'fas fa-sign-in-alt',
                        label: 'Login'
                    },
                    logout: {
                        color: '#6b7280',
                        bg: 'rgba(107,114,128,0.12)',
                        icon: 'fas fa-sign-out-alt',
                        label: 'Logout'
                    },
                    bump: {
                        color: '#f59e0b',
                        bg: 'rgba(245,158,11,0.12)',
                        icon: 'fas fa-forward',
                        label: 'Bumped'
                    },
                    rush: {
                        color: '#ef4444',
                        bg: 'rgba(239,68,68,0.12)',
                        icon: 'fas fa-bolt',
                        label: 'Rush'
                    },
                    '86': {
                        color: '#ef4444',
                        bg: 'rgba(239,68,68,0.12)',
                        icon: 'fas fa-circle-xmark',
                        label: '86\'d'
                    },
                };

                function getLogMeta(event) {
                    const key = (event || '').toLowerCase().replace(/[^a-z_86]/g, '').replace(/^(status.*)$/, 'status_change');
                    return LOG_META[key] || {
                        color: '#4b5563',
                        bg: 'rgba(75,85,99,0.10)',
                        icon: 'fas fa-circle-dot',
                        label: event || 'Event'
                    };
                }

                function avatarInitials(name) {
                    return (name || '?').trim().split(/\s+/).map(w => w[0] || '').join('').substring(0, 2).toUpperCase();
                }

                /* ── Served board ── */
                const searchId = 'rh-order-search-' + Date.now();
                const countId = 'rh-order-count-' + Date.now();
                const boardId = 'rh-served-board-' + Date.now();

                const ticketsBody = !rows.length ?
                    `<div style="text-align:center; padding:32px; color:#6b7280; background:#13161c; border:1px solid #2f3441; border-radius:10px;">
                    <i class="fas fa-plate-wheat" style="font-size:28px; margin-bottom:10px; opacity:0.4; display:block;"></i>
                    No served tickets in this station window.
                   </div>` :
                    `<div class="rh-search-bar">
                    <i class="fas fa-magnifying-glass"></i>
                    <input id="${searchId}" type="text" placeholder="Search by ref, guest, table…" oninput="rhFilterServed('${boardId}','${searchId}','${countId}')">
                    <span class="rh-search-count" id="${countId}">${rows.length} order${rows.length!==1?'s':''}</span>
                   </div>
                   <div class="served-board" id="${boardId}">${rows.map(r => {
                    const tableLbl = r.table_number ? r.table_number : (r.order_type||'').replace(/_/g,' ').toUpperCase();
                    const servedAt = r.last_served_at || r.served_at;
                    const orderedBy = r.ordered_by || r.ordered_by_username || 'System';
                    const stns = (r.stations||'').split(',').filter(Boolean).map(s => {
                        const lbl = {kitchen:'Kitchen',bar:'Bar',coffee_bar:'Coffee'}[s] || s;
                        return ` < span class = "rh-stn-pill ${escHtml(s)}" > $ {
                        escHtml(lbl)
                    } < /span>`;
            }).join(' ');
        const items = r.items || [];
        /* Speed indicator: green<8min, amber<18min, red>=18min */
        const prepSec = parseInt(r.prep_seconds) || 0;
        const speedColor = prepSec === 0 ? '#6b7280' : prepSec < 480 ? '#10b981' : prepSec < 1080 ? '#f59e0b' : '#ef4444';
        const speedLabel = prepSec === 0 ? '—' : prepSec < 480 ? 'On time' : prepSec < 1080 ? 'Moderate' : 'Slow';
        const itemRows = items.length ? items.map(it => `
                        <div class="served-line">
                            <div class="served-line__qty">${fmtQty(it.quantity)}</div>
                            <div>
                                <div class="served-line__name">${escHtml(it.item_name)}</div>
                                ${it.notes ? `<div class="served-line__note"><i class="fas fa-comment-dots"></i> ${escHtml(it.notes)}</div>` : ''}
                            </div>
                            <div class="served-line__times">
                                <strong>Start</strong> ${fmtTime(it.started_at)}<br>
                                <strong>Ready</strong> ${fmtTime(it.ready_at)}<br>
                                <strong>Served</strong> ${fmtTime(it.served_at)}
                            </div>
                        </div>`).join('') :
            `<div style="color:#4b5563; font-size:11px; padding:6px 0; font-style:italic;">No item lines recorded.</div>`;
        return `<article class="served-card" style="--sc-speed-color:${speedColor};"
                        data-ref="${escHtml(r.reference)}"
                        data-guest="${escHtml((r.customer_name||'').toLowerCase())}"
                        data-table="${escHtml((tableLbl||'').toLowerCase())}">
                        <div class="served-card__head">
                            <div>
                                <div class="served-card__ref">${escHtml(r.reference)}</div>
                                <div class="served-card__table"><i class="fas fa-location-dot"></i> ${escHtml(tableLbl || 'Walk-in')}</div>
                            </div>
                            <div class="served-card__served"><i class="fas fa-circle-check"></i> ${escHtml(fmtTime(servedAt))}</div>
                        </div>
                        <div class="served-meta">
                            <div class="served-meta__item">
                                <div class="served-meta__label"><i class="fas fa-user"></i> Guest</div>
                                <div class="served-meta__value">${escHtml(r.customer_name || 'Walk-in guest')}</div>
                            </div>
                            <div class="served-meta__item">
                                <div class="served-meta__label"><i class="fas fa-user-tie"></i> Taken by</div>
                                <div class="served-meta__value">${escHtml(orderedBy)}</div>
                            </div>
                            <div class="served-meta__item">
                                <div class="served-meta__label"><i class="fas fa-kitchen-set"></i> Station</div>
                                <div class="served-meta__value">${stns || '—'}</div>
                            </div>
                            <div class="served-meta__item">
                                <div class="served-meta__label"><i class="fas fa-coins"></i> Revenue</div>
                                <div class="served-meta__value" style="color:#6ee7b7;">${fmtMoney(r.revenue)}</div>
                            </div>
                        </div>
                        <div class="served-timeline">
                            <div class="served-time st-created"><span><i class="fas fa-file-pen"></i> Created</span><strong>${fmtTime(r.created_at)}</strong></div>
                            <div class="served-time st-fired"><span><i class="fas fa-fire"></i> Fired</span><strong>${fmtTime(r.fired_at)}</strong></div>
                            <div class="served-time st-ready"><span><i class="fas fa-bell"></i> Ready</span><strong>${fmtTime(r.last_ready_at)}</strong></div>
                            <div class="served-time st-served"><span><i class="fas fa-circle-check"></i> Served</span><strong>${fmtTime(servedAt)}</strong></div>
                        </div>
                        <div class="served-lines">${itemRows}</div>
                        <div class="served-card__foot">
                            <div class="prep-badge"><i class="fas fa-stopwatch"></i> ${fmtDur(prepSec)} · ${escHtml(speedLabel)}</div>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <span style="color:#4b5563; font-size:10px;">${fmtQty(r.qty)} item${r.qty!==1?'s':''}</span>
                                <button class="b-view" data-loader-manual onclick="openFullOrder(${r.id}, this)"><i class="fas fa-eye"></i> Full order</button>
                            </div>
                        </div>
                    </article>`;
        }).join('')
        } < /div>`;

        /* ── Station logs ── */
        const logsBody = !logs.length ?
            `<div style="text-align:center; padding:24px; color:#6b7280; background:#13161c; border:1px solid #2f3441; border-radius:10px; font-size:13px;">
                    <i class="fas fa-clipboard-list" style="font-size:24px; margin-bottom:8px; opacity:0.35; display:block;"></i>
                    No station log entries in this window.
                   </div>` :
            `<div class="rh-logs-panel">
                    <div class="rh-logs-panel-head">
                        <span><i class="fas fa-list-timeline" style="color:var(--kds-station-color); margin-right:6px;"></i>Station event log</span>
                        <span>${logs.length} entr${logs.length!==1?'ies':'y'}</span>
                    </div>
                    <div class="rh-log-list">${logs.map(log => {
                        const meta = getLogMeta(log.event);
                        const transition = log.from_status && log.to_status
                            ? `<span class="log-arrow">›</span><span class="rh-status-pill ${escHtml(log.from_status)}">${escHtml(log.from_status)}</span><span class="log-arrow">→</span><span class="rh-status-pill ${escHtml(log.to_status)}">${escHtml(log.to_status)}</span>`
                            : '';
                        const itemName = log.item_name ? `<span class="log-item-name"><i class="fas fa-utensils" style="font-size:9px;"></i> ${escHtml(log.item_name)}</span>` : '';
                        const initials = avatarInitials(log.user_name);
                        return `<div class="rh-log-item" style="--log-color:${meta.color}; --log-bg:${meta.bg};">
                            <div class="rh-log-time">${fmtTime(log.created_at)}</div>
                            <div class="rh-log-icon"><i class="${meta.icon}"></i></div>
                            <div class="rh-log-main">
                                <div class="rh-log-event-badge">${meta.label}</div>
                                <div class="rh-log-detail">
                                    ${log.reference ? `<span class="log-ref">${escHtml(log.reference)}</span>` : ''}
                                    ${transition}
                                    ${itemName}
                                </div>
                            </div>
                            <div class="rh-log-user">
                                <div class="rh-log-avatar">${escHtml(initials)}</div>
                                <div>${escHtml(log.user_name || 'System')}</div>
                            </div>
                        </div>`;
                    }).join('')}</div>
                   </div>`;

        const sectionOrders = `<div class="rh-section-title"><div class="rh-sec-inner"><i class="fas fa-clipboard-check"></i> Served Orders</div></div>`;
        const sectionLogs = `<div class="rh-section-title"><div class="rh-sec-inner"><i class="fas fa-list-timeline"></i> Station Logs</div></div>`;

        document.getElementById('rhModalBody').innerHTML = tabs + windowNote + kpi + sectionOrders + ticketsBody + sectionLogs + logsBody;

        /* Wire live search after DOM insertion */
        const si = document.getElementById(searchId);
        if (si) si.focus();

        }
        catch (e) {
            document.getElementById('rhModalBody').innerHTML = '<div style="color:#fca5a5; padding:16px;"><i class="fas fa-circle-exclamation"></i> Network error — please close and retry.</div>';
        } finally {
            hideKdsLoader();
            setStationClickerLoading(loadingButton, false);
        }
        }

        function rhFilterServed(boardId, searchId, countId) {
            const q = (document.getElementById(searchId)?.value || '').toLowerCase().trim();
            const cards = document.querySelectorAll('#' + boardId + ' .served-card');
            let visible = 0;
            cards.forEach(c => {
                const match = !q ||
                    c.dataset.ref.toLowerCase().includes(q) ||
                    c.dataset.guest.includes(q) ||
                    c.dataset.table.includes(q);
                c.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            const el = document.getElementById(countId);
            if (el) el.textContent = visible + ' order' + (visible !== 1 ? 's' : '');
        }

        async function openFullOrder(orderId, triggerButton = null) {
            const loadingButton = setStationClickerLoading(triggerButton, true);
            openModal('Order #' + orderId + ' — full breakdown');
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('action', 'view_order');
                fd.append('order_id', orderId);
                fd.append('station', STATION);
                const r = await fetch(apiUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) {
                    document.getElementById('rhModalBody').innerHTML = '<div style="color:#f5c6cb;">' + escHtml(j.error || 'Failed to load') + '</div>';
                    return;
                }
                const o = j.order;
                const tableLbl = o.table_number ? escHtml(o.table_number) : (o.order_type || '').replace('_', ' ').toUpperCase();
                const head = `<div style="margin-bottom:12px;">
                <div style="font-size:18px; font-weight:700; color:#fff;">${escHtml(o.reference)} · ${tableLbl}</div>
                <div style="font-size:12px; color:#9aa3af; margin-top:2px;">
                    ${o.customer_name ? '<i class="fas fa-user"></i> '+escHtml(o.customer_name)+' · ' : ''}
                    Order: <span class="rh-status-pill ${escHtml(o.kitchen_status)}">${escHtml(o.kitchen_status)}</span>
                    · Total: <strong style="color:#fff;">${fmtMoney(o.total_amount)}</strong>
                    · Fired: ${escHtml(fmtTime(o.fired_at))}
                    ${o.served_at ? '· Served: '+escHtml(fmtTime(o.served_at)) : ''}
                </div>
                ${o.notes ? '<div style="margin-top:8px; padding:8px; background:#3a2d1f; border-left:3px solid #d4a843; color:#ffe9b8; font-size:12px;"><i class="fas fa-exclamation-triangle"></i> '+escHtml(o.notes)+'</div>' : ''}
            </div>`;
                const items = o.items || [];
                const rows = items.length ? items.map(it => {
                    const isMine = it.station === STATION;
                    return `<tr style="${isMine ? 'background:rgba(40,167,69,0.08);' : ''}">
                    <td><span class="rh-stn-pill ${escHtml(it.station||'')}">${escHtml((it.station||'').replace('_',' '))}</span></td>
                    <td><strong>${escHtml(it.item_name)}</strong>${it.notes ? '<div style="color:#9aa3af; font-size:11px;">→ '+escHtml(it.notes)+'</div>' : ''}</td>
                    <td class="rh-num">${escHtml(parseFloat(it.quantity).toString())}</td>
                    <td class="rh-num">${fmtMoney(it.line_total)}</td>
                    <td><span class="rh-status-pill ${escHtml(it.kds_status)}">${escHtml(it.kds_status)}</span></td>
                    <td>${escHtml(fmtTime(it.started_at))}</td>
                    <td>${escHtml(fmtTime(it.ready_at))}</td>
                    <td>${escHtml(fmtTime(it.served_at))}</td>
                </tr>`;
                }).join('') : '<tr><td colspan="8" style="text-align:center; color:#9aa3af; padding:24px;">No items.</td></tr>';
                document.getElementById('rhModalBody').innerHTML = head + `<table class="rh-tbl"><thead><tr>
                <th>Station</th><th>Item</th><th class="rh-num">Qty</th><th class="rh-num">Total</th>
                <th>Status</th><th>Started</th><th>Ready</th><th>Served</th>
            </tr></thead><tbody>${rows}</tbody></table>
            <p style="font-size:11px; color:#6c757d; margin-top:10px;">Lines highlighted green belong to your station. Other stations are read-only here.</p>`;
            } catch (e) {
                document.getElementById('rhModalBody').innerHTML = '<div style="color:#f5c6cb;">Network error.</div>';
            } finally {
                setStationClickerLoading(loadingButton, false);
            }
        }

        async function openRecipeCard(itemId, triggerButton = null) {
            const loadingButton = setStationClickerLoading(triggerButton, true);
            openModal('Recipe Card');
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('action', 'recipe_card');
                fd.append('station', STATION);
                fd.append('item_id', itemId);
                const r = await fetch(apiUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) {
                    document.getElementById('rhModalBody').innerHTML = '<div style="color:#f5c6cb;">' + escHtml(j.error || 'Failed to load') + '</div>';
                    return;
                }
                const item = j.item || {};
                const menu = j.menu || {};
                const recipe = j.recipe || null;
                const ingredients = j.ingredients || [];
                document.getElementById('rhModalTitle').textContent = item.item_name || 'Recipe Card';
                const itemNote = item.notes ? `<div style="margin-top:8px; padding:8px; background:#3a2d1f; border-left:3px solid #d4a843; color:#ffe9b8; font-size:12px;"><i class="fas fa-comment-dots"></i> ${escHtml(item.notes)}</div>` : '';
                const desc = menu.description ? `<div style="margin-top:8px; color:#d0d6dd; font-size:12px; line-height:1.4;">${escHtml(menu.description)}</div>` : '';
                const recipeNote = recipe && recipe.notes ? `<div style="margin-top:10px; padding:8px; background:#1a1d21; border:1px solid #303640; border-radius:7px; color:#d0d6dd; font-size:12px;"><strong style="color:#fff;">Recipe note:</strong> ${escHtml(recipe.notes)}</div>` : '';
                const rows = ingredients.length ? ingredients.map(line => `
                <tr>
                    <td><strong>${escHtml(line.name)}</strong><div style="color:#9aa3af; font-size:11px;">${escHtml(line.category || 'Ingredient')}</div></td>
                    <td class="rh-num">${fmtQty(line.quantity_per_portion)}</td>
                    <td>${escHtml(line.unit || '')}</td>
                    <td class="rh-num">${fmtQty(line.yield_percent || 100)}%</td>
                </tr>
            `).join('') : '<tr><td colspan="4" style="text-align:center; color:#9aa3af; padding:24px;">No recipe has been saved for this item.</td></tr>';
                document.getElementById('rhModalBody').innerHTML = `
                <div style="margin-bottom:12px;">
                    <div style="font-size:18px; font-weight:800; color:#fff;">${escHtml(item.item_name || '')}</div>
                    <div style="font-size:12px; color:#9aa3af; margin-top:3px;">${escHtml(j.station_label || '')} · ${fmtQty(item.quantity)} ordered${recipe ? ' · Recipe serves '+fmtQty(recipe.portions_per_recipe || 1) : ''}</div>
                    ${desc}${itemNote}${recipeNote}
                </div>
                <table class="rh-tbl"><thead><tr><th>Ingredient</th><th class="rh-num">Qty / portion</th><th>Unit</th><th class="rh-num">Yield</th></tr></thead><tbody>${rows}</tbody></table>
            `;
            } catch (e) {
                document.getElementById('rhModalBody').innerHTML = '<div style="color:#f5c6cb;">Network error.</div>';
            } finally {
                setStationClickerLoading(loadingButton, false);
            }
        }

        /* Sound functions — delegate to RHSounds library */
        function playDing() {
            RHSounds.play('normal');
        }

        function playUrgentDing() {
            RHSounds.play('urgent');
        }

        document.getElementById('soundToggle').addEventListener('click', e => {
            RHSounds.setEnabled(!RHSounds.isEnabled());
            // RHSounds.onToggle callback above handles UI sync
        });
        document.querySelectorAll('.filter button').forEach(b => b.addEventListener('click', e => {
            document.querySelectorAll('.filter button').forEach(x => x.classList.remove('active'));
            e.currentTarget.classList.add('active');
            currentFilter = e.currentTarget.dataset.filter;
            render();
        }));

        syncStationViewportMetrics();
        window.addEventListener('resize', syncStationViewportMetrics);
        if ('ResizeObserver' in window) {
            const stationLayoutObserver = new ResizeObserver(syncStationViewportMetrics);
            const topWrap = document.getElementById('kdsTopWrap');
            const stationBottom = document.querySelector('.station-bottom');
            if (topWrap) stationLayoutObserver.observe(topWrap);
            if (stationBottom) stationLayoutObserver.observe(stationBottom);
        }

        render();
        tickClock();
        setTimeout(poll, 500);
        setInterval(tickClock, 1000);
        RHPoll.every(poll, 1000);

        /* ---- Reminder: re-ring for old unacknowledged urgent messages every 2 minutes ---- */
        RHPoll.every(() => {
            if (!soundOn) return;
            const rows = state.messages || [];
            const oldUrgent = rows.filter(m => m.priority === 'urgent' && elapsedSeconds(m.created_at) > 90);
            if (oldUrgent.length > 0) {
                RHSounds.play('urgent');
                RHNotif.show({
                    title: `${oldUrgent.length} URGENT note${oldUrgent.length > 1 ? 's' : ''} need attention!`,
                    body: 'These messages have been waiting over 90 seconds.',
                    type: 'urgent',
                    source: 'Reminder',
                    sound: false,
                });
            }
        }, 120000);

        /* ---- Burger / drawer nav ---- */
        (function() {
            const toggle = document.getElementById('kdsMenuToggle');
            const drawer = document.getElementById('kdsMenuDrawer');
            const backdrop = document.getElementById('kdsMenuBackdrop');
            const collapseBtn = document.getElementById('kdsSidebarCollapse');
            const resizeHandle = document.getElementById('kdsSidebarResizeHandle');
            const fullscreenBtn = document.getElementById('drawerFullscreenToggle');
            if (!toggle || !drawer || !backdrop) return;

            const SIDEBAR_WIDTH_KEY = 'rh_kds_sidebar_width_v1';
            const SIDEBAR_COLLAPSED_KEY = 'rh_kds_sidebar_collapsed_v1';
            const SIDEBAR_HIDDEN_KEY = 'rh_kds_sidebar_hidden_v1';
            const DESKTOP_QUERY = '(min-width: 901px)';

            function isDesktopMode() {
                return window.matchMedia(DESKTOP_QUERY).matches;
            }

            function setMobileDrawerState(open) {
                if (isDesktopMode()) return;
                drawer.classList.toggle('open', open);
                backdrop.classList.toggle('open', open);
                toggle.classList.toggle('open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                document.body.classList.toggle('kds-mobile-menu-open', open);
            }

            function closeKdsDrawer() {
                setMobileDrawerState(false);
            }
            window.closeKdsDrawer = closeKdsDrawer;

            function setDesktopCollapsed(collapsed, persist) {
                if (!isDesktopMode()) return;
                document.body.classList.toggle('kds-sidebar-collapsed', !!collapsed);
                if (collapseBtn) {
                    collapseBtn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
                    collapseBtn.setAttribute('aria-label', collapsed ? 'Expand station menu' : 'Collapse station menu');
                    collapseBtn.title = collapsed ? 'Expand menu' : 'Collapse menu';
                    const icon = collapseBtn.querySelector('i');
                    if (icon) icon.className = collapsed ? 'fas fa-angle-right' : 'fas fa-angle-left';
                }
                if (persist) {
                    try {
                        localStorage.setItem(SIDEBAR_COLLAPSED_KEY, collapsed ? '1' : '0');
                    } catch (_) {
                        // Ignore storage errors.
                    }
                }
            }

            function setDesktopHidden(hidden, persist) {
                if (!isDesktopMode()) return;
                document.body.classList.toggle('kds-sidebar-hidden', !!hidden);
                if (toggle) {
                    toggle.classList.toggle('open', !!hidden);
                    toggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
                    toggle.setAttribute('aria-label', hidden ? 'Show menu' : 'Hide menu');
                }
                if (persist) {
                    try {
                        localStorage.setItem(SIDEBAR_HIDDEN_KEY, hidden ? '1' : '0');
                    } catch (_) {
                        // Ignore storage errors.
                    }
                }
            }

            function clampSidebarWidth(width) {
                const minWidth = 220;
                const maxWidth = 460;
                return Math.max(minWidth, Math.min(maxWidth, Math.round(width)));
            }

            function applySidebarWidth(width, persist) {
                const safeWidth = clampSidebarWidth(width);
                document.documentElement.style.setProperty('--kds-sidebar-width', safeWidth + 'px');
                if (persist) {
                    try {
                        localStorage.setItem(SIDEBAR_WIDTH_KEY, String(safeWidth));
                    } catch (_) {
                        // Ignore storage errors.
                    }
                }
            }
            window.setKdsSidebarWidth = function(width) {
                applySidebarWidth(width, true);
            };

            function syncDrawerViewportMode() {
                const desktop = isDesktopMode();
                document.body.classList.toggle('kds-desktop-mode', desktop);
                document.body.classList.toggle('kds-mobile-mode', !desktop);
                if (!desktop) {
                    setDesktopCollapsed(false, false);
                    document.documentElement.style.removeProperty('--kds-sidebar-width');
                    closeKdsDrawer();
                    return;
                }

                let savedWidth = null;
                let savedCollapsed = false;
                let savedHidden = false;
                try {
                    savedWidth = parseInt(localStorage.getItem(SIDEBAR_WIDTH_KEY) || '', 10);
                } catch (_) {
                    savedWidth = null;
                }
                try {
                    savedCollapsed = localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1';
                } catch (_) {
                    savedCollapsed = false;
                }
                try {
                    savedHidden = localStorage.getItem(SIDEBAR_HIDDEN_KEY) === '1';
                } catch (_) {
                    savedHidden = false;
                }

                if (Number.isFinite(savedWidth) && savedWidth > 0) {
                    applySidebarWidth(savedWidth, false);
                }
                setDesktopCollapsed(savedCollapsed, false);
                setDesktopHidden(savedHidden, false);
                closeKdsDrawer();
            }

            function updateFullscreenButton() {
                if (!fullscreenBtn || !window.KioskFullscreen) return;
                const active = !!window.KioskFullscreen.isActive();
                const icon = fullscreenBtn.querySelector('i');
                const label = fullscreenBtn.querySelector('span');
                if (icon) icon.className = active ? 'fas fa-compress' : 'fas fa-expand';
                if (label) label.textContent = active ? 'Exit Fullscreen' : 'Enter Fullscreen';
                fullscreenBtn.classList.toggle('is-active', active);
            }

            window.toggleStationFullscreen = function(trigger) {
                if (!window.KioskFullscreen || typeof window.KioskFullscreen.toggle !== 'function') return;
                const done = function() {
                    updateFullscreenButton();
                    if (trigger && !isDesktopMode()) closeKdsDrawer();
                };
                Promise.resolve(window.KioskFullscreen.toggle()).finally(done);
            };

            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                if (isDesktopMode()) {
                    const hidden = document.body.classList.contains('kds-sidebar-hidden');
                    if (hidden) {
                        setDesktopHidden(false, true);
                        setDesktopCollapsed(false, true);
                    } else {
                        setDesktopHidden(true, true);
                    }
                    return;
                }
                setMobileDrawerState(!drawer.classList.contains('open'));
            });

            if (collapseBtn) {
                collapseBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    setDesktopHidden(false, true);
                    setDesktopCollapsed(!document.body.classList.contains('kds-sidebar-collapsed'), true);
                });
            }

            if (resizeHandle) {
                let dragging = false;

                function onMove(e) {
                    if (!dragging || !isDesktopMode()) return;
                    applySidebarWidth(e.clientX, true);
                }

                function onMouseMove(e) {
                    if (!dragging || !isDesktopMode()) return;
                    applySidebarWidth(e.clientX, true);
                }

                function stopDrag() {
                    dragging = false;
                    document.body.classList.remove('kds-sidebar-resizing');
                    document.removeEventListener('pointermove', onMove, true);
                    document.removeEventListener('mousemove', onMouseMove, true);
                    document.removeEventListener('pointerup', stopDrag, true);
                    document.removeEventListener('pointercancel', stopDrag, true);
                    document.removeEventListener('mouseup', stopDrag, true);
                }
                resizeHandle.addEventListener('pointerdown', function(e) {
                    if (!isDesktopMode()) return;
                    if (e.pointerType === 'mouse' && e.button !== 0) return;
                    e.preventDefault();
                    dragging = true;
                    document.body.classList.add('kds-sidebar-resizing');
                    document.addEventListener('pointermove', onMove, true);
                    document.addEventListener('mousemove', onMouseMove, true);
                    document.addEventListener('pointerup', stopDrag, true);
                    document.addEventListener('pointercancel', stopDrag, true);
                    document.addEventListener('mouseup', stopDrag, true);
                });
                resizeHandle.addEventListener('mousedown', function(e) {
                    if (!isDesktopMode()) return;
                    if (e.button !== 0) return;
                    e.preventDefault();
                    dragging = true;
                    document.body.classList.add('kds-sidebar-resizing');
                    document.addEventListener('mousemove', onMouseMove, true);
                    document.addEventListener('mouseup', stopDrag, true);
                });
                resizeHandle.addEventListener('dblclick', function() {
                    document.documentElement.style.removeProperty('--kds-sidebar-width');
                    try {
                        localStorage.removeItem(SIDEBAR_WIDTH_KEY);
                    } catch (_) {
                        // Ignore storage errors.
                    }
                });
            }

            backdrop.addEventListener('click', function() {
                closeKdsDrawer();
            });

            // Close on outside click
            document.addEventListener('click', function(e) {
                if (!isDesktopMode() && drawer.classList.contains('open') && !drawer.contains(e.target) && !toggle.contains(e.target)) {
                    closeKdsDrawer();
                }
            });

            // ESC closes mobile drawer.
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeKdsDrawer();
            });

            document.addEventListener('fullscreenchange', updateFullscreenButton);
            document.addEventListener('webkitfullscreenchange', updateFullscreenButton);
            document.addEventListener('mozfullscreenchange', updateFullscreenButton);
            document.addEventListener('MSFullscreenChange', updateFullscreenButton);

            window.addEventListener('resize', syncDrawerViewportMode);
            syncDrawerViewportMode();
            updateFullscreenButton();

            // Drawer filter buttons sync with main filter
            drawer.querySelectorAll('.drawer-filter-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    // Sync main .filter buttons
                    const f = btn.dataset.filter;
                    document.querySelectorAll('.filter button').forEach(function(b) {
                        b.classList.remove('active');
                    });
                    const mainBtn = document.querySelector('.filter button[data-filter="' + f + '"]');
                    if (mainBtn) {
                        mainBtn.classList.add('active');
                        mainBtn.dispatchEvent(new Event('click'));
                    }
                    // Sync drawer active state
                    drawer.querySelectorAll('.drawer-filter-btn').forEach(function(b) {
                        b.classList.remove('active');
                    });
                    btn.classList.add('active');
                    if (!isDesktopMode()) closeKdsDrawer();
                });
            });

            // Drawer clock mirrors main clock
            function tickDrawerClock() {
                const dc = document.getElementById('drawerClock');
                const mc = document.getElementById('clock');
                if (dc && mc) dc.textContent = mc.textContent;
            }
            setInterval(tickDrawerClock, 1000);

            // Sound toggle mirroring
            window.toggleSoundFromDrawer = function() {
                RHSounds.setEnabled(!RHSounds.isEnabled());
            };
        })();
    </script>
    <?php $rh_help_hide_fab = true;
    require __DIR__ . '/includes/help-tooltips.php'; ?>
    <?php require __DIR__ . '/includes/offline-banner.php'; ?>
    <script src="js/pwa-install.js" defer></script>
</body>

</html>

