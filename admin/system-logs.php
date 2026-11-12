<?php

/**
 * System Logs
 * Aggregates operational logs for the admin portal.
 */

require_once 'admin-init.php';

$user = $user ?? ['id' => 0];

if (!hasPermission((int)$user['id'], 'system_logs')) {
    rhDenyAndRedirectHome((int)$_SESSION['admin_user_id'], (string)($_SESSION['admin_role'] ?? ''), basename($_SERVER['PHP_SELF']));
    exit;
}

$site_name = getSetting('site_name');
require_once __DIR__ . '/includes/system-log-attention.php';
require_once __DIR__ . '/includes/staff-activity-lib.php';
// Every list on this page pages on the server (owner 2026-11-12): {key}_page / {key}_size, 10 per page by default.
require_once __DIR__ . '/includes/log-pager.php';
require_once __DIR__ . '/includes/login-bundles.php'; // Recent Logins: sign-ins bundled per person/device/session
// Empty pagers first (a missing table shows an empty list); each is replaced when its list loads.
$lpEmpty = function (string $key): array {
    return rh_lp_clamp(rh_lp_parse($_GET, $key), 0);
};
$evP = $lpEmpty('ev');   // System Event Stream
$baP = $lpEmpty('ba');   // Booking Changes Audit
$emP = $lpEmpty('em');   // Emails sent
$lgP = $lpEmpty('lg');   // Recent Logins
$aaP = $lpEmpty('aa');   // Recent Admin Activity
$posP = $lpEmpty('pos'); // POS Orders
$kdsP = $lpEmpty('kds'); // Display Screens: station events
$smP = $lpEmpty('sm');   // Display Screens: station messages
$scP = $lpEmpty('sc');   // Display Screens: shift closes
$apiP = $lpEmpty('api'); // Recent API Calls
$offP = $lpEmpty('off'); // Recent Offline Sync
$evNote = '';
$lgNote = '';

// Staff activity filters (person, days, type). They also steer the older panels below once the person has used them.
$saF = rh_sa_parse_filters($_GET);

// CSV of the filtered timeline: same filters, sent before any page output.
if ((string)($_GET['export'] ?? '') === 'csv') {
    try {
        logActivity((int)$user['id'], 'logs_export', 'Staff activity CSV ' . $saF['from'] . ' to ' . $saF['to']);
    } catch (Throwable $e) {
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="staff-activity-' . $saF['from'] . ($saF['to'] !== $saF['from'] ? '_to_' . $saF['to'] : '') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    rh_sa_write_csv($pdo, $saF, fopen('php://output', 'w'));
    exit;
}

// Level 'problems' (warnings, errors and critical) is the default so real faults show first.
$levelLabels = [
    'problems' => 'Warnings and errors',
    'all' => 'Everything',
    'error' => 'Errors only',
    'critical' => 'Critical only',
    'warning' => 'Warnings only',
    'info' => 'Info only',
    'debug' => 'Debug only',
];
$validLevels = array_keys($levelLabels);
$sourceFilter = trim((string)($_GET['source'] ?? 'all'));
$levelFilter = trim((string)($_GET['level'] ?? 'problems'));
if (!in_array($levelFilter, $validLevels, true)) {
    $levelFilter = 'problems';
}
$limit = min(500, max(20, (int)($_GET['limit'] ?? 50))); // old links only (lists now page: see log-pager.php)
$loginScanLimit = 3000; // sign-ins are bundled from the newest 3,000 records, then paged
$eventScanLimit = 3000; // "Group repeats" groups the newest 3,000 events, then pages the groups
$autoRefresh = isset($_GET['auto']) && $_GET['auto'] === '1';
$isAutoReload = $autoRefresh && ($_GET['r'] ?? '') === '1'; // r=1 is added only by this page's own 15-second reload
$groupRepeats = (string)($_GET['group'] ?? '1') !== '0';

// Emails sent (owner 2026-11-12): every email the system sent or was told not to send. Filters are prefixed em_ so
// they never mix with Staff activity's from / to / type / user. Newest first, paged (em_page / em_size).
require_once __DIR__ . '/../includes/email-send-log.php';
$emF = rh_email_log_filters($_GET, date('Y-m-d'));
$emReady = rh_email_send_log_ready($pdo);

/**
 * The Emails sent filters as SQL. Mirrors rh_email_send_log_list() (includes/email-send-log.php), with paging on top;
 * to move into that file once its 2026-11-12 review is done.
 */
function rh_logs_email_where(array $f): array
{
    $where = ['sent_at >= ?', 'sent_at < DATE_ADD(?, INTERVAL 1 DAY)'];
    $args = [$f['from'] . ' 00:00:00', $f['to']];
    if (($f['kind'] ?? 'all') === 'test') {
        $where[] = "email_kind IN ('test', 'test_email')"; // test_email: the first day's test sends
    } elseif (($f['kind'] ?? 'all') !== 'all') {
        $where[] = 'email_kind = ?';
        $args[] = (string)$f['kind'];
    }
    if (($f['result'] ?? 'all') !== 'all') {
        $where[] = 'status = ?';
        $args[] = (string)$f['result'];
    }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $where[] = '(account_ref LIKE ? OR to_email LIKE ? OR cc LIKE ? OR subject LIKE ? OR sent_by_label LIKE ?)';
        array_push($args, $like, $like, $like, $like, $like);
    }
    return [implode(' AND ', $where), $args];
}

$emRows = [];
if ($emReady) {
    try {
        [$emW, $emA] = rh_logs_email_where($emF);
        [$emP, $emRows] = rh_lp_fetch($pdo, $_GET, 'em', 'SELECT 1 FROM email_send_log WHERE ' . $emW,
            'SELECT * FROM email_send_log WHERE ' . $emW . ' ORDER BY sent_at DESC, id DESC', $emA);
    } catch (Throwable $e) {
        error_log('email_send_log page: ' . $e->getMessage());
        $emRows = [];
    }
}
$emKindOptions = [];
foreach (rh_email_kinds() as $emKey => $emDef) {
    $emKindOptions[$emKey] = $emDef[0];
}
asort($emKindOptions);
// Links that keep every other filter on the page and change only the email ones.
$emUrl = function (array $over = []) use ($emF, $emP) {
    $q = [];
    foreach ($_GET as $k => $v) {
        if (is_string($k) && is_string($v) && strpos($k, 'em_') !== 0 && $k !== 'export') {
            $q[$k] = $v;
        }
    }
    // em_page is left out: a new view or filter starts at page 1; the rows-per-page choice stays.
    $em = ['em_from' => $emF['from'], 'em_to' => $emF['to'], 'em_kind' => $emF['kind'], 'em_result' => $emF['result'], 'em_q' => $emF['q'], 'em_view' => $emF['view'], 'em_size' => (string)$emP['size']];
    return 'system-logs.php?' . http_build_query(array_merge($q, $em, $over)) . '#log-emails';
};
$emDayLabel = function (string $ymd): string {
    $ts = strtotime($ymd);
    return $ts ? date('D j M Y', $ts) : $ymd;
};

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

    // Log the page view before querying so it appears in the current result set. Not on the page's own
    // 15-second reload (marker r=1), which would add thousands of identical rows a day.
    if (!$isAutoReload) {
        rh_log_event('system_logs', 'debug', 'System logs viewed', ['source' => $sourceFilter, 'level' => $levelFilter, 'limit' => $limit]);
    }

    if (rh_logs_table_exists($pdo, 'system_event_log')) {
        $where = [];
        $args = [];
        if ($sourceFilter !== '' && $sourceFilter !== 'all') {
            $where[] = 'source = ?';
            $args[] = $sourceFilter;
        }
        if ($levelFilter === 'problems') {
            $where[] = "level IN ('warning','error','critical')";
        } elseif ($levelFilter !== 'all') {
            $where[] = 'level = ?';
            $args[] = $levelFilter;
        }
        if ($saF['filtered']) {
            [$saFrom, $saTo] = rh_sa_bounds($saF);
            $where[] = 'created_at >= ? AND created_at < ?';
            $args[] = $saFrom;
            $args[] = $saTo;
            if ($saF['user'] > 0) {
                $where[] = 'user_id = ?';
                $args[] = $saF['user'];
            }
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        if ($groupRepeats) {
            // Read a wider slice without the (large) context, group repeats, page the groups, then fetch the
            // context of just the rows that are shown.
            $stmt = $pdo->prepare("SELECT id, source, level, message, user_id, username, created_at FROM system_event_log {$whereSql} ORDER BY created_at DESC, id DESC LIMIT {$eventScanLimit}");
            $stmt->execute($args);
            $evRaw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            [$evP, $dbEvents] = rh_lp_slice($_GET, 'ev', rh_log_group_events($evRaw));
            $evNote = 'Identical events are grouped (numbers and addresses ignored), newest first.'
                . (count($evRaw) >= $eventScanLimit ? ' Grouped from the newest ' . number_format($eventScanLimit) . ' events: untick Group repeats to page through every event.' : '');
            $ids = array_map(function ($r) { return (int)$r['id']; }, $dbEvents);
            if ($ids) {
                $ctx = $pdo->prepare('SELECT id, context_json FROM system_event_log WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
                $ctx->execute($ids);
                $ctxById = [];
                foreach ($ctx->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $ctxById[(int)$c['id']] = $c['context_json'];
                }
                foreach ($dbEvents as &$ev) {
                    $ev['context_json'] = $ctxById[(int)$ev['id']] ?? null;
                }
                unset($ev);
            }
        } else {
            [$evP, $dbEvents] = rh_lp_fetch($pdo, $_GET, 'ev', "SELECT 1 FROM system_event_log {$whereSql}",
                "SELECT * FROM system_event_log {$whereSql} ORDER BY created_at DESC, id DESC", $args);
            $evNote = trim('Newest first. ' . rh_lp_cap_note($evP));
        }

        $stats['system_events'] = (int)$pdo->query("SELECT COUNT(*) FROM system_event_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
        // Real errors only: a form refusing a typing mistake is not counted.
        foreach ($pdo->query("SELECT source, level, message FROM system_event_log WHERE level IN ('error','critical') AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY created_at DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) as $errRow) {
            if (!rh_log_is_validation_refusal((string)$errRow['source'], (string)$errRow['level'], (string)$errRow['message'])) {
                $stats['errors']++;
            }
        }
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
        [$saW, $saA] = rh_sa_panel_clause($saF, 'created_at', 'user_id', 'WHERE');
        [$aaP, $adminActivity] = rh_lp_fetch($pdo, $_GET, 'aa', "SELECT 1 FROM admin_activity_log{$saW}",
            "SELECT user_id, username, action, details, ip_address, created_at FROM admin_activity_log{$saW} ORDER BY created_at DESC, id DESC", $saA);
    } catch (Throwable $e) {
    }
}

$bookingAuditEvents = [];
if (rh_logs_table_exists($pdo, 'booking_audit_log')) {
    try {
        [$saW, $saA] = rh_sa_panel_clause($saF, 'bal.performed_at', 'bal.performed_by', 'WHERE');
        [$baP, $bookingAuditEvents] = rh_lp_fetch($pdo, $_GET, 'ba', "SELECT 1 FROM booking_audit_log bal{$saW}",
            "SELECT bal.*, au.username AS performed_by_username FROM booking_audit_log bal LEFT JOIN admin_users au ON au.id = bal.performed_by{$saW} ORDER BY bal.performed_at DESC, bal.id DESC", $saA);
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
        [$saW, $saA] = rh_sa_panel_clause($saF, 'l.created_at', null, 'WHERE');
        [$apiP, $apiActivity] = rh_lp_fetch($pdo, $_GET, 'api', "SELECT 1 FROM api_usage_logs l{$saW}", "
            SELECT l.endpoint, l.method, l.ip_address, l.response_code, l.response_time, l.created_at, k.client_name
            FROM api_usage_logs l
            LEFT JOIN api_keys k ON k.id = l.api_key_id{$saW}
            ORDER BY l.created_at DESC, l.id DESC", $saA);
    } catch (Throwable $e) {
    }
}

$offlineActivity = [];
if (rh_logs_table_exists($pdo, 'offline_replay_log')) {
    try {
        [$saW, $saA] = rh_sa_panel_clause($saF, 'replayed_at', 'user_id', 'WHERE');
        [$offP, $offlineActivity] = rh_lp_fetch($pdo, $_GET, 'off', "SELECT 1 FROM offline_replay_log{$saW}", "
            SELECT username, endpoint, action, entity_reference, response_status, response_summary, replayed_at
            FROM offline_replay_log{$saW}
            ORDER BY replayed_at DESC, id DESC", $saA);
    } catch (Throwable $e) {
    }
}

$recentLogins = [];
$recentLoginBundles = [];
if (rh_logs_table_exists($pdo, 'admin_activity_log')) {
    try {
        [$saW, $saA] = rh_sa_panel_clause($saF, 'created_at', 'user_id', 'AND');
        $stmt = $pdo->prepare("
            SELECT user_id, username, action, details, ip_address, user_agent, created_at
            FROM admin_activity_log
            WHERE action LIKE 'login%'{$saW}
            ORDER BY created_at DESC
            LIMIT {$loginScanLimit}
        ");
        $stmt->execute($saA);
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

        // Bundle consecutive sign-ins from the same source into one row (admin/includes/login-bundles.php), then page them.
        [$lgP, $recentLoginBundles] = rh_lp_slice($_GET, 'lg', rh_login_bundles($recentLogins));
        $lgNote = 'Repeated sign-ins from the same person and device are bundled, newest first.'
            . (count($recentLogins) >= $loginScanLimit ? ' Bundled from the newest ' . number_format($loginScanLimit) . ' sign-in records.' : '');
    } catch (Throwable $e) {
    }
}

// ── POS: recent orders ──────────────────────────────────────────────────────
$posOrders = [];
$posOrderAudit = [];
$currencyCode = getSetting('currency_code', '');
if (rh_logs_table_exists($pdo, 'stock_orders')) {
    try {
        [$posP, $posOrders] = rh_lp_fetch($pdo, $_GET, 'pos', 'SELECT 1 FROM stock_orders', "
            SELECT so.id, so.reference, so.order_type, so.status, so.table_number, so.room_number,
                   so.total_amount, so.payment_method, so.voided_at, so.void_reason,
                   so.paid_at, so.created_at, so.opened_as_tab, so.is_priority,
                   au.username AS created_by_name
            FROM stock_orders so
            LEFT JOIN admin_users au ON au.id = so.created_by
            ORDER BY so.created_at DESC, so.id DESC");
    } catch (Throwable $e) {
    }
}
$posPageIds = array_values(array_filter(array_map(function ($o) { return (int)$o['id']; }, $posOrders)));
if ($posPageIds && rh_logs_table_exists($pdo, 'stock_order_audit')) {
    try {
        // The audit trail of just the orders on this page.
        $stmt = $pdo->prepare("
            SELECT soa.id, soa.order_id, soa.actor_name, soa.event, soa.details, soa.ip_address, soa.created_at,
                   so.reference
            FROM stock_order_audit soa
            LEFT JOIN stock_orders so ON so.id = soa.order_id
            WHERE soa.order_id IN (" . implode(',', array_fill(0, count($posPageIds), '?')) . ")
            ORDER BY soa.created_at DESC
            LIMIT 2000
        ");
        $stmt->execute($posPageIds);
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
        [$kdsP, $kdsEvents] = rh_lp_fetch($pdo, $_GET, 'kds', 'SELECT 1 FROM stock_kds_events', "
            SELECT ske.id, ske.event, ske.from_status, ske.to_status, ske.user_name, ske.ip_address, ske.created_at,
                   so.reference, so.table_number,
                   soi.item_name, soi.station
            FROM stock_kds_events ske
            LEFT JOIN stock_orders so ON so.id = ske.order_id
            LEFT JOIN stock_order_items soi ON soi.id = ske.order_item_id
            ORDER BY ske.created_at DESC, ske.id DESC");
    } catch (Throwable $e) {
    }
}
if (rh_logs_table_exists($pdo, 'station_messages')) {
    try {
        [$smP, $stationMessages] = rh_lp_fetch($pdo, $_GET, 'sm', 'SELECT 1 FROM station_messages', "
            SELECT id, station, message, sent_by_name, source, priority, is_acknowledged, order_ref, created_at
            FROM station_messages
            ORDER BY created_at DESC, id DESC");
    } catch (Throwable $e) {
    }
}
if (rh_logs_table_exists($pdo, 'stock_shift_closes')) {
    try {
        [$scP, $shiftCloses] = rh_lp_fetch($pdo, $_GET, 'sc', 'SELECT 1 FROM stock_shift_closes', "
            SELECT id, user_id, user_name, shift_date, closed_at, total_revenue, orders_count,
                   voids_count, voids_amount, declared_cash, variance_cash, notes
            FROM stock_shift_closes
            ORDER BY closed_at DESC, id DESC");
        require_once __DIR__ . '/includes/pos-shift-totals.php';
        // Z definition (owner 2026-11-11): Orders / Revenue = till as stored + "Charged to room" read now over the
        // close's own window, read for the whole page in two queries (rh_pos_closes_room_charges_batch). Nothing
        // stored changes; cash variance stays till-only.
        $scRoom = rh_pos_closes_room_charges_batch($pdo, $shiftCloses);
        foreach ($shiftCloses as &$scRow) {
            $scRow['total_revenue'] = rh_pos_close_revenue($pdo, $scRow);
            // NULL = the room read failed: keep the stored till figures and say so, never show 0 (absence rule).
            $scRow = rh_pos_close_add_room($scRow, $scRoom !== null ? ($scRoom[(int)$scRow['id']] ?? ['count' => 0, 'amount' => 0.0]) : ['count' => 0, 'amount' => 0.0]);
            $scRow['room_unavailable'] = ($scRoom === null);
        }
        unset($scRow);
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

// Source filter: every source seen in the last 30 days (not just the rows on screen).
$sources = [];
try {
    foreach ($pdo->query("SELECT DISTINCT source FROM system_event_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY source LIMIT 150")->fetchAll(PDO::FETCH_COLUMN) as $src) {
        if ((string)$src !== '') {
            $sources[] = (string)$src;
        }
    }
} catch (Throwable $e) {
}
if ($sourceFilter !== 'all' && $sourceFilter !== '' && !in_array($sourceFilter, $sources, true)) {
    $sources[] = $sourceFilter;
}
sort($sources);
array_unshift($sources, 'all');

// Needs attention (last 7 days).
$attention = rh_log_attention_load($pdo, 7);
$attentionLinkOk = function (?array $link) use ($user) {
    if (!$link) {
        return false;
    }
    if (!empty($link['permission']) && !hasPermission((int)$user['id'], $link['permission'])) {
        return false;
    }
    if (!empty($link['module']) && function_exists('rh_module_key_enabled') && !rh_module_key_enabled($link['module'])) {
        return false;
    }
    return true;
};

// Staff activity section data (read-only; see admin/includes/staff-activity-lib.php).
$saStaff = rh_sa_staff($pdo);
$saTypes = rh_sa_types();
$saCounters = rh_sa_counters();
$saSummary = rh_sa_summary($pdo, $saF);
// Day cards page in memory (key 'sad'); the timeline pages on a capped count (key 'sa', newest 1000 lines reachable).
[$sadP, $sadRows] = rh_lp_slice($_GET, 'sad', $saSummary['rows']);
$saTotal = rh_sa_count($pdo, $saF);
$saP = rh_sa_pager($_GET, $saTotal);
$saTimeline = rh_sa_timeline($pdo, $saF, $saP['offset'], $saP['size']);
$saP = rh_sa_pager_more($saP, $saTimeline['has_more']);
$saRetention = rh_sa_retention($pdo);
$saLinkOk = function (?array $l) use ($user) {
    if (!$l || empty($l['page'])) {
        return false;
    }
    $perm = function_exists('getPermissionForPage') ? getPermissionForPage($l['page']) : null;
    if ($perm !== null && !hasPermission((int)$user['id'], $perm)) {
        return false;
    }
    $mod = function_exists('getModuleForPage') ? getModuleForPage($l['page']) : null;
    if ($mod !== null && ($user['role'] ?? '') !== 'admin' && function_exists('rh_module_key_enabled')) {
        foreach ((array)$mod as $mk) {
            if (!rh_module_key_enabled((string)$mk)) {
                return false;
            }
        }
    }
    return true;
};
// Links keep the other sections' filters and pages plus the rows-per-page choices; Staff activity's pages restart at 1.
$saUrl = function (array $over = []) use ($saF) {
    return 'system-logs.php?' . rh_sa_query($saF, $over, $_GET);
};
// One person on one day (a day card), optionally one kind of work: lands on the timeline.
$saDayUrl = function (int $uid, string $day, string $type = 'all') {
    return 'system-logs.php?' . rh_sa_query(['user' => $uid, 'type' => $type, 'from' => $day, 'to' => $day], [], $_GET) . '#staff-activity-timeline';
};
$saDayLabel = function (string $ymd) {
    return date('D j M Y', strtotime($ymd));
};
$saSingleDay = $saF['from'] === $saF['to'];
$saWhoLabel = $saF['user'] > 0 ? ($saStaff[$saF['user']]['name'] ?? ('User #' . $saF['user'])) : 'everyone';
$saLines = $saP['unknown'] ? 'count unavailable'
    : number_format($saP['total']) . ($saP['capped'] ? '+' : '') . ' line' . ($saP['total'] === 1 ? '' : 's');
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
            <p class="text-muted">Start with &ldquo;Needs attention&rdquo;. Everything below it is the full record of what the system, staff, devices and API clients did.</p>
        </div>

        <section class="log-section sa" id="staff-activity" aria-labelledby="staff-activity-title">
            <h3 id="staff-activity-title"><i class="fas fa-user-clock"></i> Staff activity</h3>
            <p class="log-intro">Pick a staff member and a day to see what they did. Read from the hotel's own records: Booking History, payments, orders, shifts, the kitchen and bar screens, stock records and the sign-in log.</p>

            <form method="get" class="log-toolbar sa__filters" action="system-logs.php#staff-activity-results">
                <input type="hidden" name="sa" value="1">
                <?php // Keep the other sections' filters and pages; Staff activity's own keys come from this form, its pages restart at 1.
                foreach ($_GET as $saKeepKey => $saKeepVal): if (is_string($saKeepKey) && is_string($saKeepVal) && preg_match('/^[a-z][a-z0-9_]{0,30}$/', $saKeepKey)
                    && !in_array($saKeepKey, array_merge(rh_sa_own_keys(), ['sa_size', 'sad_size', 'action', 'csrf_token']), true)): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($saKeepKey); ?>" value="<?php echo htmlspecialchars($saKeepVal); ?>">
                <?php endif; endforeach; ?>
                <input type="hidden" name="sa_size" value="<?php echo (int)$saP['size']; ?>">
                <input type="hidden" name="sad_size" value="<?php echo (int)$sadP['size']; ?>">
                <label>Staff member
                    <select name="user">
                        <option value="0">Everyone</option>
                        <?php foreach ($saStaff as $saId => $saPerson): ?>
                            <option value="<?php echo (int)$saId; ?>" <?php echo $saF['user'] === (int)$saId ? 'selected' : ''; ?>><?php echo htmlspecialchars($saPerson['name']); ?> (<?php echo htmlspecialchars($saPerson['username']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>From
                    <input type="date" name="from" value="<?php echo htmlspecialchars($saF['from']); ?>">
                </label>
                <label>To
                    <input type="date" name="to" value="<?php echo htmlspecialchars($saF['to']); ?>">
                </label>
                <label>What they did
                    <select name="type">
                        <option value="all">Everything</option>
                        <?php foreach ($saTypes as $saKey => $saLabel): ?>
                            <option value="<?php echo htmlspecialchars($saKey); ?>" <?php echo $saF['type'] === $saKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($saLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit"><i class="fas fa-filter"></i> Show</button>
            </form>
            <div class="log-bar">
                <div class="log-toggle" role="group" aria-label="Quick dates">
                    <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 days'] as $saRangeKey => $saRangeLabel): $saOn = $saF['range'] === $saRangeKey; ?>
                        <a class="<?php echo $saOn ? 'is-on' : ''; ?>" href="<?php echo htmlspecialchars($saUrl(['range' => $saRangeKey])); ?>#staff-activity-results" <?php echo $saOn ? 'aria-current="true"' : ''; ?>><?php echo htmlspecialchars($saRangeLabel); ?></a>
                    <?php endforeach; ?>
                </div>
                <p class="log-bar__note">
                    <?php echo htmlspecialchars($saDayLabel($saF['from'])) . ($saSingleDay ? '' : ' to ' . htmlspecialchars($saDayLabel($saF['to']))); ?>,
                    <?php echo htmlspecialchars($saWhoLabel); ?><?php echo $saF['type'] !== 'all' ? ', ' . htmlspecialchars($saTypes[$saF['type']]) : ''; ?>:
                    <?php echo htmlspecialchars($saLines); ?>.
                </p>
                <a class="log-bar__btn" href="<?php echo htmlspecialchars('system-logs.php?' . rh_sa_query($saF, ['export' => 'csv'])); ?>" title="The list below with these filters, up to <?php echo number_format(RH_SA_CSV_ROWS); ?> lines, as a spreadsheet file."><i class="fas fa-file-csv"></i> Download this list (CSV)</a>
            </div>
            <p class="sa__note">
                Up to <?php echo (int)RH_SA_MAX_DAYS; ?> days at a time<?php echo $saF['clamped'] ? ' (your range was longer, so its last ' . (int)RH_SA_MAX_DAYS . ' days are shown)' : ''; ?>.
                &ldquo;What they did&rdquo; narrows the list, not the day cards. Lines with no staff account show as &ldquo;System / unknown user&rdquo; and get no day card.
                <?php echo $saF['filtered'] ? 'The older panels further down follow the same person and days.' : 'The older panels further down show everything until you press Show.'; ?>
            </p>

            <div id="staff-activity-results" class="sa__results">
                <h4 class="sa__sub">Each person, each day</h4>
                <?php if (!$sadRows): ?>
                    <p class="log-empty">No recorded activity for that person and those days.</p>
                <?php else: ?>
                    <div class="sa__cards" data-disable-auto-pagination>
                        <?php foreach ($sadRows as $saRow): ?>
                            <article class="sa-card">
                                <header>
                                    <strong><?php echo htmlspecialchars($saRow['who']); ?></strong>
                                    <span><?php echo htmlspecialchars($saDayLabel($saRow['day'])); ?></span>
                                </header>
                                <dl class="sa-card__times">
                                    <div title="The first time this person signed in that day."><dt>First sign-in</dt><dd><?php echo $saRow['first_in'] ? htmlspecialchars(substr($saRow['first_in'], 11, 5)) : '&mdash;'; ?></dd></div>
                                    <div title="From this person's first to their last recorded action that day, in any record (sign-ins, bookings, payments, orders, screen saves)."><dt>Active</dt><dd><?php echo htmlspecialchars(substr($saRow['first_seen'], 11, 5)); ?>&ndash;<?php echo htmlspecialchars(substr($saRow['last_seen'], 11, 5)); ?></dd></div>
                                    <div title="The last time this person pressed Sign out that day. Blank when they never did (the session timed out or the browser was closed)."><dt>Signed out</dt><dd><?php echo $saRow['last_out'] ? htmlspecialchars(substr($saRow['last_out'], 11, 5)) : '&mdash;'; ?></dd></div>
                                </dl>
                                <ul class="sa-card__counts">
                                    <?php $saAny = false; foreach ($saCounters as $saCk => [$saCl, $saCh]):
                                        if ((int)$saRow['counts'][$saCk] <= 0) { continue; }
                                        $saAny = true; ?>
                                        <li>
                                            <a href="<?php echo htmlspecialchars($saDayUrl((int)$saRow['uid'], (string)$saRow['day'], rh_sa_counter_type($saCk))); ?>" title="<?php echo htmlspecialchars($saCh . ' Opens these lines in the list below.'); ?>">
                                                <span><?php echo htmlspecialchars($saCl); ?></span>
                                                <strong><?php echo number_format((int)$saRow['counts'][$saCk]); ?><?php
                                                    if ($saCk === 'payments' && $saRow['payments_total'] > 0) { echo ' &middot; ' . htmlspecialchars(rh_sa_money((float)$saRow['payments_total'])); }
                                                    if ($saCk === 'refunds' && $saRow['refunds_total'] > 0) { echo ' &middot; ' . htmlspecialchars(rh_sa_money((float)$saRow['refunds_total'])); }
                                                ?></strong>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (!$saAny): ?><li class="sa-card__none">Nothing recorded besides activity times.</li><?php endif; ?>
                                </ul>
                                <a class="sa-card__see" href="<?php echo htmlspecialchars($saDayUrl((int)$saRow['uid'], (string)$saRow['day'])); ?>" title="Shows everything this person did that day in the list below.">See what they did <i class="fas fa-arrow-down" aria-hidden="true"></i></a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php echo rh_lp_render('system-logs.php', $_GET, $sadP, count($sadRows), 'staff-activity-results', 'Day card pages',
                        $saSummary['limited'] ? 'More people and days matched than can be listed. Pick one person or a shorter range.' : ''); ?>
                <?php endif; ?>

                <details class="sa__legend">
                    <summary>What each figure counts</summary>
                    <dl>
                        <?php foreach ($saCounters as $saCl): ?>
                            <div><dt><?php echo htmlspecialchars($saCl[0]); ?></dt><dd><?php echo htmlspecialchars($saCl[1]); ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <p>Amounts are the full amount paid or refunded, shown in full; refunds are never netted off payments.</p>
                </details>

                <h4 class="sa__sub" id="staff-activity-timeline">What happened, newest first<?php echo $saF['type'] !== 'all' ? ' &mdash; ' . htmlspecialchars($saTypes[$saF['type']]) : ''; ?></h4>
                <?php if (!$saTimeline['rows']): ?>
                    <p class="log-empty">Nothing recorded for this choice. Try another day or &ldquo;Everything&rdquo;.</p>
                <?php else: ?>
                    <div class="log-scroll" data-disable-auto-pagination>
                        <table class="log-table sa-table">
                            <thead>
                                <tr>
                                    <th>When<span class="log-th-help"><?php echo $saSingleDay ? 'Time it was recorded (hotel time)' : 'Day and time it was recorded (hotel time)'; ?></span></th>
                                    <th>Person<span class="log-th-help">The staff member; System / unknown user when no staff account is on the record</span></th>
                                    <th>What they did<span class="log-th-help">Names, references, amounts and field names only, never what was typed</span></th>
                                    <th>Kind<span class="log-th-help">The kind of work, as in the &ldquo;What they did&rdquo; filter</span></th>
                                    <th>Where<span class="log-th-help">The screen or area; &ldquo;screen save&rdquo; = recorded when a screen saved</span></th>
                                    <th>Open<span class="log-th-help">The record itself, when you may open it</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($saTimeline['rows'] as $saItem): ?>
                                    <tr>
                                        <td class="log-nowrap"><time datetime="<?php echo htmlspecialchars(str_replace(' ', 'T', $saItem['ts'])); ?>"><?php echo $saSingleDay ? htmlspecialchars(substr($saItem['ts'], 11, 8)) : htmlspecialchars(date('D j M, H:i', strtotime($saItem['ts']))); ?></time></td>
                                        <td><?php echo htmlspecialchars($saItem['who']); ?></td>
                                        <td class="sa-table__what"><?php echo htmlspecialchars($saItem['what']); ?></td>
                                        <td><span class="log-pill muted"><?php echo htmlspecialchars($saTypes[$saItem['type']] ?? 'Other'); ?></span></td>
                                        <td>
                                            <?php echo htmlspecialchars($saItem['where']); ?>
                                            <?php if (!empty($saItem['screen_save'])): ?><span class="sa__tag" title="Recorded automatically when a screen saved. It only says which screen and action, never what was typed.">screen save</span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($saLinkOk($saItem['link'])): ?>
                                                <a class="em-link" href="<?php echo htmlspecialchars($saItem['link']['page'] . ($saItem['link']['query'] !== '' ? '?' . $saItem['link']['query'] : '')); ?>">Open</a>
                                            <?php else: ?>
                                                <span class="em-none">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php if ($saTimeline['rows'] || $saP['page'] > 1): // an empty later page still offers the way back
                    echo rh_lp_render('system-logs.php', $_GET, $saP, count($saTimeline['rows']), 'staff-activity-timeline', 'Staff activity pages', rh_sa_pager_note($saP));
                endif; ?>

                <p class="sa__note sa__retention">
                    How far back the records go:
                    <?php $saBits = []; foreach ($saRetention as $saLab => $saFirst) { $saBits[] = htmlspecialchars($saLab) . ' ' . ($saFirst ? 'from ' . htmlspecialchars(date('j M Y', strtotime((string)$saFirst))) : 'none yet'); } echo implode(' &middot; ', $saBits); ?>.
                    This page never deletes anything. &ldquo;Screen saves&rdquo; are recorded from the day this feature was switched on; earlier work only shows where it has its own record.
                    A screen save means the screen accepted the request; it is not proof the entry passed every check.
                </p>
            </div>
        </section>

        <section class="log-attention" id="log-attention" aria-labelledby="log-attention-title">
            <div class="log-attention__head">
                <h3 id="log-attention-title">Needs attention</h3>
                <span class="log-attention__range">Last 7 days</span>
            </div>
            <?php if (!$attention): ?>
                <p class="log-attention__clear"><strong>All clear.</strong> No system errors, repeated failed sign-ins, failed emails, stock below zero, offline sync failures or refused API keys in the last 7 days.</p>
            <?php else: ?>
                <div class="log-attention__grid">
                    <?php foreach ($attention as $item): ?>
                        <article class="log-attn log-attn--<?php echo htmlspecialchars($item['level']); ?><?php echo !empty($item['highlight']) ? ' log-attn--hot' : ''; ?>">
                            <div class="log-attn__top">
                                <h4><?php echo htmlspecialchars($item['title']); ?></h4>
                                <span class="log-pill <?php echo htmlspecialchars($item['level']); ?>"><?php echo number_format((int)$item['count']); ?></span>
                            </div>
                            <p class="log-attn__meaning"><?php echo htmlspecialchars($item['meaning']); ?></p>
                            <?php if (!empty($item['examples'])): ?>
                                <ul class="log-attn__examples">
                                    <?php foreach ($item['examples'] as $exLine): ?><li><?php echo htmlspecialchars($exLine); ?></li><?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <div class="log-attn__foot">
                                <span>Last seen <?php echo htmlspecialchars(rh_log_short_time((string)$item['last_seen'])); ?></span>
                                <?php if ($attentionLinkOk($item['link'] ?? null)): ?>
                                    <a class="log-attn__link" href="<?php echo htmlspecialchars($item['link']['page']); ?>"><?php echo htmlspecialchars($item['link']['label']); ?></a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p class="log-attention__note">Entries a form refused because of a typing mistake (for example a number in the wrong format) are warnings in the record below and are not counted here.</p>
        </section>

        <div class="log-grid">
            <div class="log-card"><small>System events, 24h</small><strong><?php echo number_format($stats['system_events']); ?></strong></div>
            <div class="log-card"><small>Admin activity, 24h</small><strong><?php echo number_format($stats['admin_activity']); ?></strong></div>
            <div class="log-card"><small>Booking changes, 24h</small><strong><?php echo number_format($stats['booking_changes']); ?></strong></div>
            <div class="log-card"><small>POS orders, 24h</small><strong><?php echo number_format($stats['pos_orders']); ?></strong></div>
            <div class="log-card"><small>KDS/DS events, 24h</small><strong><?php echo number_format($stats['kds_events']); ?></strong></div>
            <div class="log-card"><small>API calls, 24h</small><strong><?php echo number_format($stats['api_calls']); ?></strong></div>
            <div class="log-card"><small>Offline replays, 24h</small><strong><?php echo number_format($stats['offline_replays']); ?></strong></div>
            <div class="log-card<?php echo $stats['errors'] > 0 ? ' log-card--danger' : ''; ?>"><small>Errors, 24h</small><strong><?php echo number_format($stats['errors']); ?></strong></div>
        </div>

        <?php rh_presence_render_panel($pdo, (string)($user['role'] ?? ''), (int)$user['id']); /* Who's online: admin + manager only */ ?>

        <form method="get" class="log-toolbar" action="system-logs.php#log-events">
            <?php // A new filter starts the event stream at page 1; its rows-per-page and the other lists' pages stay.
            foreach ($_GET as $lpKeepKey => $lpKeepVal):
                if (is_string($lpKeepKey) && is_string($lpKeepVal) && preg_match('/^(?:(?:ba|em|lg|aa|pos|kds|sm|sc|api|off)_(?:page|size)|ev_size)$/', $lpKeepKey)): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($lpKeepKey); ?>" value="<?php echo htmlspecialchars($lpKeepVal); ?>">
            <?php endif; endforeach; ?>
            <?php if ($saF['filtered']): ?>
                <input type="hidden" name="sa" value="1">
                <input type="hidden" name="user" value="<?php echo (int)$saF['user']; ?>">
                <input type="hidden" name="from" value="<?php echo htmlspecialchars($saF['from']); ?>">
                <input type="hidden" name="to" value="<?php echo htmlspecialchars($saF['to']); ?>">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($saF['type']); ?>">
            <?php endif; ?>
            <label>Source
                <select name="source">
                    <?php foreach ($sources as $source): ?>
                        <option value="<?php echo htmlspecialchars($source); ?>" <?php echo $sourceFilter === $source ? 'selected' : ''; ?>><?php echo htmlspecialchars($source); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Show
                <select name="level">
                    <?php foreach ($levelLabels as $levelKey => $levelText): ?>
                        <option value="<?php echo htmlspecialchars($levelKey); ?>" <?php echo $levelFilter === $levelKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($levelText); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <input type="hidden" name="group" value="0">
            <label class="log-toolbar__check"><input type="checkbox" name="group" value="1" <?php echo $groupRepeats ? 'checked' : ''; ?>> Group repeats</label>
            <label class="log-toolbar__check"><input type="checkbox" name="auto" value="1" <?php echo $autoRefresh ? 'checked' : ''; ?>> Auto-refresh 15s</label>
            <button type="submit"><i class="fas fa-filter"></i> Apply</button>
            <a href="system-logs.php#log-events"><i class="fas fa-rotate-left"></i> Reset</a>
        </form>

        <div class="log-section" id="log-events">
            <h3><i class="fas fa-stream"></i> System Event Stream</h3>
            <?php if (!$dbEvents): ?>
                <p class="log-empty"><?php echo $levelFilter === 'problems' ? 'No warnings or errors match. Choose &ldquo;Everything&rdquo; to see all events.' : 'No system events match. New admin actions will appear here automatically.'; ?></p>
            <?php else: ?>
                <div class="log-events">
                    <?php foreach ($dbEvents as $event):
                        $evRepeat = (int)($event['repeat_count'] ?? 1);
                        $evUser = (string)($event['username'] ?: (!empty($event['user_id']) ? '#' . $event['user_id'] : ''));
                        $evCtx = (string)($event['context_json'] ?? '');
                    ?>
                        <article class="log-event log-event--<?php echo htmlspecialchars(rh_log_level_class((string)$event['level'])); ?>">
                            <div class="log-event__top">
                                <span class="log-pill <?php echo rh_log_level_class((string)$event['level']); ?>"><?php echo htmlspecialchars((string)$event['level']); ?></span>
                                <code><?php echo htmlspecialchars((string)$event['source']); ?></code>
                                <?php if ($evRepeat > 1): ?>
                                    <span class="log-repeat" title="First seen <?php echo htmlspecialchars((string)$event['first_seen']); ?>">x<?php echo number_format($evRepeat); ?> &middot; last <?php echo htmlspecialchars(rh_log_short_time((string)$event['last_seen'])); ?></span>
                                <?php else: ?>
                                    <time><?php echo htmlspecialchars((string)$event['created_at']); ?></time>
                                <?php endif; ?>
                            </div>
                            <p class="log-event__msg"><?php echo htmlspecialchars((string)$event['message']); ?></p>
                            <?php if ($evUser !== '' || $evCtx !== ''): ?>
                                <div class="log-event__meta">
                                    <?php if ($evUser !== ''): ?><span>By <?php echo htmlspecialchars($evUser); ?></span><?php endif; ?>
                                    <?php if ($evCtx !== ''): ?>
                                        <details><summary>Details<?php echo $evRepeat > 1 ? ' (latest)' : ''; ?></summary><pre><?php echo htmlspecialchars($evCtx); ?></pre></details>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php echo rh_lp_render('system-logs.php', $_GET, $evP, count($dbEvents), 'log-events', 'System event pages', $evNote); ?>
            <?php endif; ?>
        </div>

        <div class="log-section" id="log-booking-audit">
            <h3><i class="fas fa-clock-rotate-left"></i> Booking Changes Audit</h3>
            <div class="log-scroll" data-disable-auto-pagination>
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
                                    <td class="log-nowrap"><?php echo htmlspecialchars((string)$ev['performed_at']); ?></td>
                                    <td><a href="booking-details.php?id=<?php echo (int)$ev['booking_id']; ?>" style="text-decoration:none;color:#7E684B;font-weight:600;"><?php echo htmlspecialchars($ev['booking_reference'] ?? ('Booking #' . $ev['booking_id'])); ?></a></td>
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
            <?php echo rh_lp_render('system-logs.php', $_GET, $baP, count($bookingAuditEvents), 'log-booking-audit', 'Booking change pages', rh_lp_cap_note($baP)); ?>
        </div>

        <div class="log-section em" id="log-emails">
            <h3><i class="fas fa-envelope-open-text"></i> Emails sent</h3>
            <p class="em__intro log-intro">Every email the system sent, or was told not to send: who sent it, when, to whom and what happened. Newest first. Times are hotel time.</p>

            <form method="get" class="log-toolbar em__filters" action="system-logs.php#log-emails">
                <?php foreach ($_GET as $emKeepKey => $emKeepVal): if (is_string($emKeepKey) && is_string($emKeepVal) && strpos($emKeepKey, 'em_') !== 0 && $emKeepKey !== 'export' && preg_match('/^[a-z_]{1,20}$/', $emKeepKey)): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($emKeepKey); ?>" value="<?php echo htmlspecialchars($emKeepVal); ?>">
                <?php endif; endforeach; ?>
                <input type="hidden" name="em_view" value="<?php echo htmlspecialchars($emF['view']); ?>">
                <input type="hidden" name="em_size" value="<?php echo (int)$emP['size']; ?>">
                <label>From
                    <input type="date" name="em_from" value="<?php echo htmlspecialchars($emF['from']); ?>">
                </label>
                <label>To
                    <input type="date" name="em_to" value="<?php echo htmlspecialchars($emF['to']); ?>">
                </label>
                <label>Email
                    <select name="em_kind">
                        <option value="all">Every kind</option>
                        <?php foreach ($emKindOptions as $emKey => $emLabel): ?>
                            <option value="<?php echo htmlspecialchars($emKey); ?>" <?php echo $emF['kind'] === $emKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($emLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Result
                    <select name="em_result">
                        <?php foreach (['all' => 'Any result', 'sent' => 'Sent', 'failed' => 'Failed', 'not_sent' => 'Not sent', 'preview' => 'Preview'] as $emKey => $emLabel): ?>
                            <option value="<?php echo htmlspecialchars($emKey); ?>" <?php echo $emF['result'] === $emKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($emLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Search
                    <input type="search" name="em_q" value="<?php echo htmlspecialchars($emF['q']); ?>" placeholder="Booking ref, email address, subject or name" maxlength="100">
                </label>
                <button type="submit"><i class="fas fa-filter"></i> Show</button>
            </form>

            <div class="em__bar">
                <div class="em__toggle" role="group" aria-label="Show the emails as">
                    <a class="<?php echo $emF['view'] === 'table' ? 'is-on' : ''; ?>" href="<?php echo htmlspecialchars($emUrl(['em_view' => 'table'])); ?>" <?php echo $emF['view'] === 'table' ? 'aria-current="true"' : ''; ?>><i class="fas fa-table"></i> Table</a>
                    <a class="<?php echo $emF['view'] === 'timeline' ? 'is-on' : ''; ?>" href="<?php echo htmlspecialchars($emUrl(['em_view' => 'timeline'])); ?>" <?php echo $emF['view'] === 'timeline' ? 'aria-current="true"' : ''; ?>><i class="fas fa-timeline"></i> Timeline</a>
                </div>
                <p class="em__note">
                    <?php echo htmlspecialchars($emDayLabel($emF['from'])) . ($emF['to'] !== $emF['from'] ? ' to ' . htmlspecialchars($emDayLabel($emF['to'])) : ''); ?>:
                    <?php echo number_format($emP['total']) . ($emP['capped'] ? '+' : ''); ?> email<?php echo $emP['total'] === 1 ? '' : 's'; ?>.
                    <?php echo $emF['clamped'] ? 'A range can be at most 92 days, so the last 92 days of your range are shown.' : ''; ?>
                </p>
            </div>

            <?php if (!$emReady): ?>
                <p class="log-empty">The email log starts once the database update 081 (email_send_log) has been run with admin/migrations/migrate.php. Until then emails are sent as usual but not listed here.</p>
            <?php elseif (!$emRows): ?>
                <p class="log-empty">No emails match these filters. Emails are listed from the day the email log was switched on.</p>
            <?php elseif ($emF['view'] === 'timeline'): ?>
                <div class="log-scroll" data-disable-auto-pagination>
                    <table class="log-table em-timeline">
                        <thead>
                            <tr>
                                <th>Day<span class="em-th-help">The day it was sent (hotel time)</span></th>
                                <th>What happened<span class="em-th-help">Time · email and who it went to · who sent it · the booking or account · the result</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (rh_email_group_by_day($emRows) as $emDay => $emDayRows): foreach ($emDayRows as $emI => $emRow):
                                $emStatus = (string)$emRow['status'];
                                $emErr = trim((string)($emRow['error'] ?? ''));
                            ?>
                                <tr class="<?php echo $emI === 0 ? 'em-timeline__first' : ''; ?>">
                                    <td class="log-nowrap em-timeline__day"><?php echo htmlspecialchars($emDayLabel((string)$emDay)); ?></td>
                                    <td>
                                        <span class="em-timeline__line"><?php echo htmlspecialchars(rh_email_timeline_line($emRow)); ?></span>
                                        <?php if ($emStatus !== 'sent' && $emErr !== ''): ?><span class="em-timeline__why"><?php echo htmlspecialchars(rh_email_result_label($emStatus, $emErr)); ?></span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="log-scroll" data-disable-auto-pagination>
                    <table class="log-table em-table">
                        <thead>
                            <tr>
                                <th>When<span class="em-th-help">Hotel time</span></th>
                                <th>Sent by<span class="em-th-help">Staff member (role); System (scheduler) for automatic emails; Guest website for online bookings</span></th>
                                <th>To<span class="em-th-help">The address the email went to</span></th>
                                <th>Copy to<span class="em-th-help">Copies of the same email; (hidden) = the guest did not see that copy</span></th>
                                <th>Email<span class="em-th-help">What kind of email, and its subject line</span></th>
                                <th>About<span class="em-th-help">The booking or account it was for</span></th>
                                <th>Result<span class="em-th-help">Sent, Failed (with the reason), Not sent (staff chose not to) or Preview (test only)</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($emRows as $emRow):
                                $emStatus = (string)$emRow['status'];
                                $emAbout = rh_email_about_label($emRow['account_type'] ?? null, $emRow['account_ref'] ?? null, $emRow['account_id'] ?? null);
                                $emPill = ['sent' => 'success', 'failed' => 'danger', 'not_sent' => 'muted', 'preview' => 'warning'][$emStatus] ?? 'muted';
                            ?>
                                <tr>
                                    <td class="log-nowrap"><?php echo htmlspecialchars(substr((string)$emRow['sent_at'], 0, 16)); ?></td>
                                    <td><?php echo htmlspecialchars((string)$emRow['sent_by_label']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$emRow['to_email']); ?><?php if (trim((string)($emRow['to_name'] ?? '')) !== ''): ?><span class="em-sub"><?php echo htmlspecialchars((string)$emRow['to_name']); ?></span><?php endif; ?></td>
                                    <td class="em-copy"><?php echo trim((string)($emRow['cc'] ?? '')) !== '' ? htmlspecialchars((string)$emRow['cc']) : '<span class="em-none">None</span>'; ?></td>
                                    <td><strong><?php echo htmlspecialchars(rh_email_kind_label((string)$emRow['email_kind'])); ?></strong><?php if (trim((string)$emRow['subject']) !== ''): ?><span class="em-sub"><?php echo htmlspecialchars((string)$emRow['subject']); ?></span><?php endif; ?></td>
                                    <td>
                                        <?php if ($emAbout === ''): ?>
                                            <span class="em-none">Not linked</span>
                                        <?php elseif (($emRow['account_type'] ?? '') === 'room' && (int)($emRow['account_id'] ?? 0) > 0): ?>
                                            <a href="booking-details.php?id=<?php echo (int)$emRow['account_id']; ?>" class="em-link"><?php echo htmlspecialchars($emAbout); ?></a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($emAbout); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="log-pill <?php echo $emPill; ?>"><?php echo htmlspecialchars(rh_email_result_word($emStatus)); ?></span><?php if ($emStatus !== 'sent' && trim((string)($emRow['error'] ?? '')) !== ''): ?><span class="em-sub"><?php echo htmlspecialchars(rh_email_result_label($emStatus, (string)$emRow['error'])); ?></span><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($emReady && $emRows): echo rh_lp_render('system-logs.php', $_GET, $emP, count($emRows), 'log-emails', 'Email pages', rh_lp_cap_note($emP)); endif; ?>
        </div>

        <div class="log-section" id="log-logins">
            <h3><i class="fas fa-right-to-bracket"></i> Recent Logins</h3>
            <div class="log-scroll" data-disable-auto-pagination>
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
                                    <td class="log-nowrap">
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
            <?php echo rh_lp_render('system-logs.php', $_GET, $lgP, count($recentLoginBundles), 'log-logins', 'Sign-in pages', $lgNote); ?>
        </div>

        <div class="log-section" id="log-admin-activity">
            <h3><i class="fas fa-user-shield"></i> Recent Admin Activity</h3>
            <div class="log-scroll" data-disable-auto-pagination>
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
            <?php echo rh_lp_render('system-logs.php', $_GET, $aaP, count($adminActivity), 'log-admin-activity', 'Admin activity pages', rh_lp_cap_note($aaP)); ?>
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

        <div class="log-section" id="log-pos">
            <h3><i class="fas fa-cash-register"></i> POS Orders</h3>
            <div class="log-scroll" data-disable-auto-pagination>
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
                            <th>User <span id="pos-filter-clear" style="display:none;cursor:pointer;font-size:11px;color:#7E684B;font-weight:400;margin-left:4px;">(clear)</span></th>
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
                                if (!empty($order['opened_as_tab'])) $flags[] = '<span style="color:#7E684B;font-size:11px;">TAB</span>';
                            ?>
                                <tr>
                                    <td class="log-nowrap"><?php echo htmlspecialchars((string)$order['created_at']); ?></td>
                                    <td><strong><?php echo htmlspecialchars((string)($order['reference'] ?? '#' . $order['id'])); ?></strong><?php echo $flags ? '<div style="margin-top:3px;">' . implode(' ', $flags) . '</div>' : ''; ?></td>
                                    <td style="text-transform:capitalize;"><?php echo htmlspecialchars($orderType); ?></td>
                                    <td><?php echo $location; ?></td>
                                    <td class="log-nowrap"><?php echo htmlspecialchars($amountFormatted); ?></td>
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
            <?php echo rh_lp_render('system-logs.php', $_GET, $posP, count($posOrders), 'log-pos', 'POS order pages', trim('Clicking a user shows only their orders on this page. ' . rh_lp_cap_note($posP))); ?>
        </div>

        <div class="log-section" id="log-kds">
            <h3><i class="fas fa-display"></i> Display Screens — KDS / BDS / CDS</h3>

            <h4 style="margin:0 0 12px;font-size:14px;color:var(--text-secondary,#5E554D);">Station Events</h4>
            <div class="log-scroll" data-disable-auto-pagination>
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
                                    <td class="log-nowrap"><?php echo htmlspecialchars((string)$ev['created_at']); ?></td>
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
            <?php echo rh_lp_render('system-logs.php', $_GET, $kdsP, count($kdsEvents), 'log-kds', 'Station event pages', rh_lp_cap_note($kdsP)); ?>

            <h4 id="log-station-messages" style="margin:24px 0 12px;font-size:14px;color:var(--text-secondary,#5E554D);">Station Messages</h4>
            <div class="log-scroll" data-disable-auto-pagination>
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
                                    <td class="log-nowrap"><?php echo htmlspecialchars((string)$msg['created_at']); ?></td>
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
            <?php echo rh_lp_render('system-logs.php', $_GET, $smP, count($stationMessages), 'log-station-messages', 'Station message pages', rh_lp_cap_note($smP)); ?>

            <?php if ($scP['total'] > 0 || $shiftCloses): /* rows show even if the count failed */ ?>
                <h4 id="log-shift-closes" style="margin:24px 0 12px;font-size:14px;color:var(--text-secondary,#5E554D);">Shift Closes</h4>
                <div class="log-scroll" data-disable-auto-pagination>
                    <table class="log-table">
                        <thead>
                            <tr>
                                <th>Shift Date</th>
                                <th>Closed At</th>
                                <th>User</th>
                                <th title="Orders on the Z: paid at the till (as stored at close) plus orders charged to a guest room (read now).">Orders</th>
                                <th title="Sales on the Z: till takings as stored at close plus orders charged to a guest room (read now, on guest bills, not in the drawer).">Revenue</th>
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
                                    <td class="log-nowrap"><?php echo htmlspecialchars((string)$sc['closed_at']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$sc['user_name']); ?></td>
                                    <td><?php echo (int)($sc['orders_count'] ?? 0); ?><?php if ((int)($sc['room_charge_count'] ?? 0) > 0): ?><br><small style="color:#6b7280;">till <?php echo (int)$sc['till_orders_count']; ?> + room <?php echo (int)$sc['room_charge_count']; ?></small><?php endif; ?></td>
                                    <td class="log-nowrap"><?php echo htmlspecialchars($revenueFormatted); ?><?php if ((int)($sc['room_charge_count'] ?? 0) > 0): ?><br><small style="color:#6b7280;">incl. charged to room <?php echo htmlspecialchars(($currencyCode ? $currencyCode . ' ' : '') . number_format((float)$sc['room_charge_amount'], 2)); ?> (read now)</small><?php elseif (!empty($sc['room_unavailable'])): ?><br><small style="color:#b45309;">till only: Charged to room could not be loaded</small><?php endif; ?></td>
                                    <td class="log-nowrap"><?php echo (int)($sc['voids_count'] ?? 0); ?> (<?php echo htmlspecialchars($voidsFormatted); ?>)</td>
                                    <td><span class="log-pill <?php echo $varianceClass; ?>" style="font-size:11px;"><?php echo htmlspecialchars($varianceFormatted); ?></span></td>
                                    <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars((string)($sc['notes'] ?: '—')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo rh_lp_render('system-logs.php', $_GET, $scP, count($shiftCloses), 'log-shift-closes', 'Shift close pages', rh_lp_cap_note($scP)); ?>
            <?php endif; ?>
        </div>

        <div class="log-section" id="log-api-calls">
            <h3><i class="fas fa-key"></i> Recent API Calls</h3>
            <div class="log-scroll" data-disable-auto-pagination>
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
            <?php echo rh_lp_render('system-logs.php', $_GET, $apiP, count($apiActivity), 'log-api-calls', 'API call pages', rh_lp_cap_note($apiP)); ?>
        </div>

        <div class="log-section" id="log-offline-sync">
            <h3><i class="fas fa-cloud-arrow-up"></i> Recent Offline Sync</h3>
            <div class="log-scroll" data-disable-auto-pagination>
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
            <?php echo rh_lp_render('system-logs.php', $_GET, $offP, count($offlineActivity), 'log-offline-sync', 'Offline sync pages', rh_lp_cap_note($offP)); ?>
        </div>

        <div class="log-section">
            <h3><i class="fas fa-file-lines"></i> File Log Tails</h3>
            <div class="log-files">
                <?php foreach ($fileLogs as $name => $lines): ?>
                    <div class="log-file">
                        <h4><?php echo htmlspecialchars($name); ?></h4>
                        <?php if (!$lines): ?>
                            <pre>No readable entries.</pre>
                        <?php else: ?>
                            <pre><?php echo htmlspecialchars(implode("\n", array_slice($lines, 0, 20))); ?></pre>
                            <?php if (count($lines) > 20): ?>
                                <details class="log-file__more">
                                    <summary style="min-height:44px;padding:12px 0;box-sizing:border-box;cursor:pointer;">Show <?php echo count($lines) - 20; ?> more lines</summary>
                                    <pre><?php echo htmlspecialchars(implode("\n", array_slice($lines, 20))); ?></pre>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php require_once 'includes/admin-footer.php'; ?>
    <script>
        (function() {
            'use strict';

            // ── POS Orders — click a user to show only their orders on this page ─────
            // (Pages come from the server: the pager under the table, pos_page / pos_size.)
            var posUserCellEl = document.querySelector('td.pos-user-cell');
            if (posUserCellEl) {
                var tbody = posUserCellEl.closest('tbody');
                var clearBtn = document.getElementById('pos-filter-clear');
                var activeUser = '';

                function renderPOS(user) {
                    if (!tbody) return;
                    activeUser = user;
                    tbody.querySelectorAll('tr').forEach(function(tr) {
                        var cell = tr.querySelector('.pos-user-cell');
                        if (!cell) return;
                        tr.style.display = (!user || (cell.dataset.user || '').toLowerCase() === user) ? '' : 'none';
                    });
                    if (clearBtn) clearBtn.style.display = user ? 'inline' : 'none';
                }

                if (tbody) {
                    tbody.addEventListener('click', function(e) {
                        var cell = e.target.closest('.pos-user-cell');
                        if (!cell) return;
                        var user = (cell.dataset.user || '').toLowerCase();
                        if (!user || user === '—') return;
                        renderPOS(activeUser === user ? '' : user);
                    });
                }

                if (clearBtn) {
                    clearBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        renderPOS('');
                    });
                }
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
                var u = new URL(window.location.href);
                u.searchParams.set('r', '1');
                window.location.replace(u.toString());
            }, 15000);
        </script>
    <?php endif; ?>
</body>

</html>

