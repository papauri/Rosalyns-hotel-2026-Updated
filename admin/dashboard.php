<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */

require_once '../includes/modal.php';
require_once '../includes/alert.php';
require_once '../includes/room-management.php';
require_once '../includes/station-hours.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$today = date('Y-m-d');
$roomServiceReminderTime = trim((string)getSetting('room_service_reminder_time', '12:00'));
if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $roomServiceReminderTime)) {
    $roomServiceReminderTime = '12:00';
}
$roomServiceReminderTimezone = trim((string)getSetting('site_timezone', 'Africa/Blantyre'));
if ($roomServiceReminderTimezone === '' || !in_array($roomServiceReminderTimezone, DateTimeZone::listIdentifiers(), true)) {
    $roomServiceReminderTimezone = 'Africa/Blantyre';
}
$roomServiceNow = new DateTimeImmutable('now', new DateTimeZone($roomServiceReminderTimezone));
$roomServiceReminderDueNow = $roomServiceNow->format('H:i') >= $roomServiceReminderTime;

// Default values so the template never sees undefined variables if DB queries fail
$recent_bookings   = [];
$recent_conferences = [];
$upcoming_checkins = [];
$today_conference_events = [];
$upcoming_conferences = [];
$today_checkins = 0;
$today_checkouts = 0;
$pending_bookings = 0;
$current_guests = 0;
$pending_conference = 0;
$today_conferences = 0;
$expired_bookings = 0;
$ops = [
    'open_tabs' => 0,
    'open_tabs_value' => 0.0,
    'room_service_pending' => 0,
    'room_service_reminder_pending' => 0,
    'room_service_reminders_due' => 0,
    'kds_kitchen_pending' => 0,
    'kds_bar_pending' => 0,
    'kds_coffee_pending' => 0,
    'orders_today' => 0,
    'restaurant_rev_today' => 0.0,
];
$stock = [
    'low_stock' => 0,
    'expiring_batches' => 0,
    'expired_batches' => 0,
    'wastage_today' => 0.0,
    'po_pending' => 0,
    'value_on_hand' => 0.0,
];
$finance = [
    'revenue_today' => 0.0,
    'payments_today' => 0,
    'outstanding' => 0.0,
    'outstanding_count' => 0,
    'refunds_pending' => 0,
];
$guestSvc = [
    'pending_reviews' => 0,
    'unread_contact' => 0,
    'pending_gym' => 0,
    'pending_events' => 0,
    'maintenance_open' => 0,
    'housekeeping_due' => 0,
];
$gymDash = [
    'active_members' => 0,
    'expiring_members' => 0,
];
$roomServiceQueue = [];
$activity_log = [];
$activity_log_total = 0;
$activity_log_page = 1;
$activity_log_per_page = 10;
$activity_log_total_pages = 1;
$is_card_insight_ajax = isset($_GET['ajax']) && $_GET['ajax'] === 'card_insight';
$station_union_window = null;
$station_union_start_sql = '';
$station_union_end_sql = '';

try {
    if (function_exists('rh_station_union_business_window')) {
        $station_union_window = rh_station_union_business_window();
        $station_union_start_sql = (string)($station_union_window['start_sql'] ?? '');
        $station_union_end_sql = (string)($station_union_window['end_sql'] ?? '');
    }
} catch (Throwable $e) {
    $station_union_window = null;
    $station_union_start_sql = '';
    $station_union_end_sql = '';
}

// Resolve module flags once — used by both queries and HTML
$mod_bookings    = moduleEnabled('bookings');
$mod_housekeeping= moduleEnabled('housekeeping');
$mod_pos         = moduleEnabled('pos');
$mod_stock       = moduleEnabled('stock');
$mod_conference  = moduleEnabled('conference');
$mod_gym         = moduleEnabled('gym');
$mod_finance     = moduleEnabled('finance');
$mod_website_cms = moduleEnabled('website_cms');
$mod_station_kds = moduleEnabled('station_kds');
$mod_station_bds = moduleEnabled('station_bds');
$mod_station_cds = moduleEnabled('station_cds');
$mod_station_room_service = moduleEnabled('station_room_service');
$mod_events      = function_exists('isEventsEnabled') && isEventsEnabled();
// Presets that invoice a named client in advance and can therefore carry an
// outstanding balance. Pure-POS presets (bar, retail, supermarket) settle at
// the till, so an "Outstanding Balances" tile would always read zero for them.
$mod_receivables = $mod_bookings || $mod_conference || $mod_gym || $mod_events;

if (!$is_card_insight_ajax) {

    // Fetch dashboard statistics — skip queries for disabled modules
    try {
        if ($mod_bookings) {
            $checkins_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM bookings WHERE check_in_date = ? AND status IN ('confirmed', 'pending')");
            $checkins_stmt->execute([$today]);
            $today_checkins = $checkins_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            $checkouts_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM bookings WHERE check_out_date = ? AND status = 'checked-in'");
            $checkouts_stmt->execute([$today]);
            $today_checkouts = $checkouts_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            $pending_stmt = $pdo->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'pending'");
            $pending_bookings = $pending_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            $current_stmt = $pdo->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'checked-in'");
            $current_guests = $current_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            $expired_stmt = $pdo->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'expired' AND expired_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $expired_bookings = $expired_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            $recent_stmt = $pdo->query("
                SELECT b.*, r.name as room_name,
                       ir.room_number as individual_room_number, ir.room_name as individual_room_name,
                       b.total_amount, b.amount_paid, b.amount_due, b.payment_status
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                ORDER BY b.created_at DESC LIMIT 10
            ");
            $recent_bookings = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

            $upcoming_stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name,
                       ir.room_number as individual_room_number, ir.room_name as individual_room_name,
                       b.total_amount, b.amount_paid, b.amount_due, b.payment_status
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                WHERE b.check_in_date BETWEEN DATE_ADD(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
                AND b.status IN ('pending', 'confirmed')
                ORDER BY b.check_in_date ASC
            ");
            $upcoming_stmt->execute([$today, $today]);
            $upcoming_checkins = $upcoming_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($mod_conference) {
            $pending_conf_stmt = $pdo->query("SELECT COUNT(*) as count FROM conference_inquiries WHERE status = 'pending'");
            $pending_conference = $pending_conf_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            $today_conf_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM conference_inquiries WHERE event_date = ? AND status IN ('confirmed', 'pending')");
            $today_conf_stmt->execute([$today]);
            $today_conferences = $today_conf_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            $recent_conf_stmt = $pdo->query("
                SELECT ci.*, cr.name as room_name
                FROM conference_inquiries ci
                LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
                ORDER BY ci.created_at DESC LIMIT 10
            ");
            $recent_conferences = $recent_conf_stmt->fetchAll(PDO::FETCH_ASSOC);

            $today_conf_events_stmt = $pdo->prepare("
                SELECT ci.*, cr.name as room_name
                FROM conference_inquiries ci
                LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
                WHERE ci.event_date = ? AND ci.status IN ('confirmed', 'pending')
                ORDER BY ci.start_time ASC
            ");
            $today_conf_events_stmt->execute([$today]);
            $today_conference_events = $today_conf_events_stmt->fetchAll(PDO::FETCH_ASSOC);

            $upcoming_conf_stmt = $pdo->prepare("
                SELECT ci.*, cr.name as room_name
                FROM conference_inquiries ci
                LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
                WHERE ci.event_date BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)
                AND ci.status IN ('pending', 'confirmed')
                ORDER BY ci.event_date ASC, ci.start_time ASC
            ");
            $upcoming_conf_stmt->execute([$today, $today]);
            $upcoming_conferences = $upcoming_conf_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $error = "Unable to load dashboard data.";
    }

    /* =======================================================================
 * Comprehensive operations / stock / finance / guest-services metrics.
 * Each block is wrapped so a single missing/legacy table never breaks the
 * dashboard — falls back to 0/empty arrays.
 * ======================================================================= */
    $ops = [
        'open_tabs'            => 0,
        'open_tabs_value'      => 0.0,
        'room_service_pending' => 0,
        'room_service_reminder_pending' => 0,
        'room_service_reminders_due' => 0,
        'kds_kitchen_pending'  => 0,
        'kds_bar_pending'      => 0,
        'kds_coffee_pending'   => 0,
        'orders_today'         => 0,
        'restaurant_rev_today' => 0.0,
    ];
    if ($mod_pos) {
        try {
            $r = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) v FROM stock_orders WHERE status='placed'")->fetch(PDO::FETCH_ASSOC);
            $ops['open_tabs'] = (int)$r['c'];
            $ops['open_tabs_value'] = (float)$r['v'];
            if ($mod_bookings) {
                $ops['room_service_pending'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_orders WHERE order_type='room_service' AND status IN ('placed','pending','confirmed')")->fetchColumn();
                $ops['room_service_reminder_pending'] = (int)$pdo->query("SELECT COUNT(*)
                    FROM bookings b
                    INNER JOIN individual_rooms ir ON ir.id = b.individual_room_id
                    WHERE b.status = 'checked-in'
                        AND b.individual_room_id IS NOT NULL
                        AND ir.is_active = 1
                        AND NOT EXISTS (
                            SELECT 1 FROM stock_orders o
                            WHERE o.order_type = 'room_service'
                                AND (o.booking_id = b.id OR (o.booking_id IS NULL AND o.individual_room_id = b.individual_room_id))
                                AND (o.status IN ('completed', 'paid') OR o.kitchen_status = 'served')
                                AND DATE(COALESCE(o.served_at, o.updated_at, o.created_at)) = CURDATE()
                        )")->fetchColumn();
                $ops['room_service_reminders_due'] = $roomServiceReminderDueNow ? (int)$ops['room_service_reminder_pending'] : 0;
            }
            if ($station_union_start_sql !== '' && $station_union_end_sql !== '') {
                $stationCountStmt = $pdo->prepare("SELECT oi.station, COUNT(DISTINCT o.id) AS c
                    FROM stock_orders o
                    INNER JOIN stock_order_items oi ON oi.order_id = o.id
                    WHERE o.kitchen_status IN ('new','in_progress','ready','recalled')
                      AND o.fired_at IS NOT NULL AND o.fired_at >= ? AND o.fired_at < ?
                      AND oi.kds_status NOT IN ('served','void')
                    GROUP BY oi.station");
                $stationCountStmt->execute([$station_union_start_sql, $station_union_end_sql]);
                $st = $stationCountStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $ops['kds_kitchen_pending'] = (int)($st['kitchen'] ?? 0);
                $ops['kds_bar_pending']     = (int)($st['bar'] ?? 0);
                $ops['kds_coffee_pending']  = (int)($st['coffee_bar'] ?? 0);
            }
            $r = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) v FROM stock_orders WHERE status IN ('paid','completed') AND DATE(COALESCE(paid_at, created_at))=CURDATE()")->fetch(PDO::FETCH_ASSOC);
            $ops['orders_today'] = (int)$r['c'];
            $ops['restaurant_rev_today'] = (float)$r['v'];
        } catch (Throwable $e) { /* legacy schema — keep zeros */ }
    }

    if ($mod_stock) {
        try {
            $stock['low_stock']        = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE is_archived=0 AND min_quantity > 0 AND current_quantity <= min_quantity")->fetchColumn();
            $stock['expiring_batches'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches WHERE status='active' AND quantity_remaining > 0 AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
            $stock['expired_batches']  = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches WHERE status='active' AND quantity_remaining > 0 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetchColumn();
            $stock['wastage_today']    = (float)$pdo->query("SELECT COALESCE(SUM(quantity * COALESCE(cost_per_unit,0)),0) FROM stock_wastage WHERE DATE(created_at)=CURDATE()")->fetchColumn();
            $stock['low_items']        = $pdo->query("SELECT id, name, unit, current_quantity, min_quantity FROM stock_ingredients WHERE is_archived=0 AND min_quantity > 0 AND current_quantity <= min_quantity ORDER BY (current_quantity / NULLIF(min_quantity,0)) ASC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
            $stock['expiring_items']   = $pdo->query("SELECT b.id, i.name, b.batch_number, b.quantity_remaining, i.unit, b.expiry_date FROM stock_batches b JOIN stock_ingredients i ON i.id=b.ingredient_id WHERE b.status='active' AND b.quantity_remaining > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY b.expiry_date ASC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* fine */ }
    }

    if ($mod_finance) {
        try {
            // Gross takings today. POS sales sync into payments as booking_type='restaurant',
            // so counting them here AND adding restaurant_rev_today would double-count —
            // exclude restaurant rows from the ledger sum and add the POS gross figure once.
            $r = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) v FROM payments WHERE DATE(payment_date)=CURDATE() AND payment_status IN ('paid','completed','partial') AND deleted_at IS NULL AND COALESCE(payment_type, '') <> 'refund' AND booking_type <> 'restaurant'")->fetch(PDO::FETCH_ASSOC);
            $finance['payments_today'] = (int)$r['c'];
            $finance['revenue_today']  = (float)$r['v'];
            $finance['revenue_today'] += (float)($ops['restaurant_rev_today'] ?? 0);
            // Outstanding receivables from every module this preset actually runs —
            // a gym must not be shown "bookings with amount due" it can never have.
            $outstandingSql = [];
            if ($mod_bookings)   { $outstandingSql[] = "SELECT COUNT(*) c, COALESCE(SUM(amount_due),0) v FROM bookings WHERE amount_due > 0 AND status IN ('pending','confirmed','checked-in')"; }
            if ($mod_conference) { $outstandingSql[] = "SELECT COUNT(*) c, COALESCE(SUM(amount_due),0) v FROM conference_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled')"; }
            if ($mod_gym)        { $outstandingSql[] = "SELECT COUNT(*) c, COALESCE(SUM(amount_due),0) v FROM gym_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled','closed')"; }
            if ($mod_events)     { $outstandingSql[] = "SELECT COUNT(*) c, COALESCE(SUM(amount_due),0) v FROM event_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled')"; }
            foreach ($outstandingSql as $q) {
                try {
                    $r = $pdo->query($q)->fetch(PDO::FETCH_ASSOC);
                    $finance['outstanding_count'] += (int)($r['c'] ?? 0);
                    $finance['outstanding']       += (float)($r['v'] ?? 0);
                } catch (Throwable $e) { /* per-module table may not exist yet */ }
            }
            $finance['refunds_pending']   = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE payment_type='refund' AND refund_status IN ('pending','processing') AND deleted_at IS NULL")->fetchColumn();
        } catch (Throwable $e) { /* fine */ }
    }

    try {
        if ($mod_website_cms) {
            $guestSvc['pending_reviews'] = (int)$pdo->query("SELECT COUNT(*) FROM reviews WHERE status='pending'")->fetchColumn();
            $guestSvc['unread_contact']  = (int)$pdo->query("SELECT COUNT(*) FROM contact_inquiries WHERE status='new'")->fetchColumn();
        }
        if ($mod_gym) {
            $guestSvc['pending_gym'] = (int)$pdo->query("SELECT COUNT(*) FROM gym_inquiries WHERE status='pending' OR status='new'")->fetchColumn();
            // Membership register (gym_members) — guarded until its migration runs.
            try {
                $gymDash['active_members']   = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='active'")->fetchColumn();
                $gymDash['expiring_members'] = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
            } catch (Throwable $e) { /* table pending migration */ }
        }
        if ($mod_events) {
            try {
                $guestSvc['pending_events'] = (int)$pdo->query("SELECT COUNT(*) FROM event_inquiries WHERE status='pending'")->fetchColumn();
            } catch (Throwable $e) { $guestSvc['pending_events'] = 0; }
        }
        if ($mod_housekeeping) {
            $guestSvc['maintenance_open'] = (int)$pdo->query("SELECT COUNT(*) FROM individual_rooms WHERE status IN ('maintenance','out_of_order')")->fetchColumn();
            $guestSvc['housekeeping_due'] = (int)$pdo->query("SELECT COUNT(*) FROM housekeeping_assignments WHERE status IN ('pending','in_progress') AND (due_date IS NULL OR due_date <= CURDATE())")->fetchColumn();
        }
    } catch (Throwable $e) { /* fine */ }

    if ($mod_pos && $mod_bookings) {
        try {
            $roomServiceQueue = $pdo->query("
                SELECT o.id, o.reference, o.room_number, o.customer_name, o.total_amount, o.created_at, o.status,
                       TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS age_min,
                       (SELECT COUNT(*) FROM stock_order_items i WHERE i.order_id=o.id) AS item_count
                FROM stock_orders o
                WHERE o.order_type='room_service' AND o.status IN ('placed','pending','confirmed')
                ORDER BY o.created_at ASC LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $roomServiceQueue = [];
        }
    }

    // Fetch recent login activity (admin only)
    $activity_log = [];
    if ($user['role'] === 'admin') {
        try {
            $activity_log_page = max(1, (int)($_GET['activity_page'] ?? 1));

            $activity_log_total_stmt = $pdo->query("
                SELECT COUNT(*)
                FROM admin_activity_log
                WHERE action IN ('login_success', 'login_failed', 'logout', 'password_reset', 'login_blocked')
            ");
            $activity_log_total = (int)$activity_log_total_stmt->fetchColumn();
            $activity_log_total_pages = max(1, (int)ceil($activity_log_total / $activity_log_per_page));
            if ($activity_log_page > $activity_log_total_pages) {
                $activity_log_page = $activity_log_total_pages;
            }

            $activity_log_offset = max(0, ($activity_log_page - 1) * $activity_log_per_page);
            $log_stmt = $pdo->prepare("
                SELECT al.*, au.full_name
                FROM admin_activity_log al
                LEFT JOIN admin_users au ON al.user_id = au.id
                WHERE al.action IN ('login_success', 'login_failed', 'logout', 'password_reset', 'login_blocked')
                ORDER BY al.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $log_stmt->bindValue(':limit', $activity_log_per_page, PDO::PARAM_INT);
            $log_stmt->bindValue(':offset', $activity_log_offset, PDO::PARAM_INT);
            $log_stmt->execute();
            $activity_log = $log_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Table may not exist yet - that's fine
            $activity_log = [];
            $activity_log_total = 0;
            $activity_log_page = 1;
            $activity_log_total_pages = 1;
        }
    }
}

if ($is_card_insight_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    $currency_symbol = getSetting('currency_symbol');
    $card = trim((string)($_GET['card'] ?? ''));

    // Per-preset gate: an insight card is only served when its module is
    // enabled — mirrors the UI gating so disabled-module data can't be
    // fetched by direct URL on presets that hide those cards.
    $insightCardGates = [
        'checkins_today'            => $mod_bookings,
        'checkouts_today'           => $mod_bookings,
        'pending_bookings'          => $mod_bookings,
        'inhouse_guests'            => $mod_bookings,
        'expired_bookings'          => $mod_bookings,
        'pending_conference'        => $mod_conference,
        'today_conferences'         => $mod_conference,
        'outstanding_balances'      => $mod_finance && $mod_receivables,
        'open_tabs'                 => $mod_stock,
        'room_service_reminders_due'=> $mod_pos && $mod_bookings,
        'room_service_pending'      => $mod_pos && $mod_bookings,
        'kitchen_tickets'           => $mod_pos && $mod_station_kds,
        'bar_tickets'               => $mod_pos && $mod_station_bds,
        'coffee_tickets'            => $mod_pos && $mod_station_cds,
        'restaurant_revenue_today'  => $mod_pos,
        'total_revenue_today'       => $mod_finance,
        'refunds_pending'           => $mod_finance,
        'stock_health'              => $mod_stock,
        'guest_services_queue'      => $mod_website_cms || $mod_gym || $mod_bookings,
        'operations_facilities'     => $mod_bookings || $mod_housekeeping || $mod_pos || $mod_finance,
        'room_status_overview'      => $mod_bookings || $mod_housekeeping,
    ];
    $gateKey = str_starts_with($card, 'room_status_') ? 'room_status_overview' : $card;
    if (isset($insightCardGates[$gateKey]) && !$insightCardGates[$gateKey]) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'This insight is not available for the current business setup.']);
        exit;
    }
    $stationWindowStart = $station_union_start_sql;
    $stationWindowEnd = $station_union_end_sql;
    $stationWindowLabel = (string)($station_union_window['window_label'] ?? 'Current service window');
    $stationHoursLabel = (string)($station_union_window['hours_label'] ?? '');

    $formatMoney = static function (float $amount) use ($currency_symbol): string {
        return $currency_symbol . number_format($amount, 2);
    };
    $formatDateTime = static function (?string $raw): string {
        $value = trim((string)($raw ?? ''));
        if ($value === '') {
            return '—';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return $value;
        }
        return date('M j, Y H:i', $ts);
    };
    $formatAge = static function (?string $raw): string {
        $value = trim((string)($raw ?? ''));
        if ($value === '') {
            return '—';
        }
        $createdAt = strtotime($value);
        if ($createdAt === false) {
            return '—';
        }
        $minutes = (int)floor((time() - $createdAt) / 60);
        if ($minutes < 1) {
            return 'Just now';
        }
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $hours = (int)floor($minutes / 60);
        $rest = $minutes % 60;
        return $hours . 'h ' . $rest . 'm';
    };
    $locationLabel = static function (?string $tableNumber, ?string $roomNumber, ?string $customerName): string {
        $table = trim((string)($tableNumber ?? ''));
        $room = trim((string)($roomNumber ?? ''));
        $customer = trim((string)($customerName ?? ''));
        if ($table !== '') {
            return 'Table ' . $table;
        }
        if ($room !== '') {
            return 'Room ' . $room;
        }
        if ($customer !== '') {
            return $customer;
        }
        return 'Walk-in';
    };
    $humanizeStatus = static function (?string $status): string {
        $value = trim((string)($status ?? ''));
        if ($value === '') {
            return 'N/A';
        }
        return ucwords(str_replace('_', ' ', $value));
    };
    $buildRowAction = static function (array $row): ?array {
        if (isset($row['order_id']) && (int)$row['order_id'] > 0) {
            return [
                'href' => 'order-lifecycle.php?id=' . (int)$row['order_id'],
                'label' => 'Lifecycle',
                'target' => '_blank',
            ];
        }
        if (isset($row['booking_id']) && (int)$row['booking_id'] > 0) {
            return [
                'href' => 'booking-details.php?id=' . (int)$row['booking_id'],
                'label' => 'Booking',
                'target' => '_blank',
            ];
        }
        if (isset($row['payment_id']) && (int)$row['payment_id'] > 0) {
            return [
                'href' => 'payment-details.php?id=' . (int)$row['payment_id'],
                'label' => 'Payment',
                'target' => '_blank',
            ];
        }
        if (isset($row['inquiry_id']) && (int)$row['inquiry_id'] > 0) {
            return [
                'href' => 'conference-management.php#enquiry-' . (int)$row['inquiry_id'],
                'label' => 'Enquiry',
                'target' => '_blank',
            ];
        }
        return null;
    };
    $normalizeInsightRows = static function (array &$payload) use ($buildRowAction): void {
        $hasActions = false;
        foreach ($payload['rows'] as &$row) {
            if (!is_array($row)) {
                continue;
            }
            if (!isset($row['action']) || !is_array($row['action'])) {
                $action = $buildRowAction($row);
                if ($action !== null) {
                    $row['action'] = $action;
                }
            }
            if (isset($row['action']) && is_array($row['action'])) {
                $hasActions = true;
            }
            unset($row['order_id'], $row['booking_id'], $row['payment_id'], $row['inquiry_id']);
        }
        unset($row);
        if ($hasActions) {
            $hasActionColumn = false;
            foreach (($payload['columns'] ?? []) as $column) {
                if (($column['key'] ?? '') === 'action') {
                    $hasActionColumn = true;
                    break;
                }
            }
            if (!$hasActionColumn) {
                $payload['columns'][] = ['key' => 'action', 'label' => 'Action'];
            }
        }
    };

    $payload = [
        'success' => true,
        'title' => 'Dashboard Insight',
        'subtitle' => 'Latest records',
        'columns' => [],
        'rows' => [],
        'empty' => 'No records found for this card right now.',
        'link' => ['href' => 'dashboard.php', 'label' => 'Open dashboard page'],
    ];

    try {
        switch ($card) {
            case 'checkins_today':
                $payload['title'] = "Today's Check-ins";
                $payload['subtitle'] = 'Confirmed + pending arrivals due today';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Booking'],
                    ['key' => 'guest', 'label' => 'Guest'],
                    ['key' => 'room', 'label' => 'Room'],
                    ['key' => 'checkout', 'label' => 'Check-out'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'payment', 'label' => 'Payment'],
                ];
                $payload['link'] = ['href' => 'bookings.php?filter=checkin_today&flash=results', 'label' => 'Open check-ins list'];
                $stmt = $pdo->prepare("SELECT b.id AS booking_id, b.booking_reference, b.guest_name, b.check_out_date, b.status, b.payment_status,
                                              r.name AS room_name, ir.room_number, ir.room_name AS individual_room_name
                                       FROM bookings b
                                       JOIN rooms r ON r.id = b.room_id
                                       LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                                       WHERE b.check_in_date = ? AND b.status IN ('confirmed','pending')
                                       ORDER BY b.created_at ASC
                                       LIMIT 30");
                $stmt->execute([$today]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $room = trim((string)($row['individual_room_name'] ?? ''));
                    if ($room === '') {
                        $room = trim((string)($row['room_number'] ?? ''));
                    }
                    if ($room !== '') {
                        $room = (trim((string)($row['room_name'] ?? '')) !== '' ? ((string)$row['room_name'] . ' · ') : '') . $room;
                    } else {
                        $room = (string)($row['room_name'] ?? '—');
                    }
                    $bid = (int)($row['booking_id'] ?? 0);
                    $payload['rows'][] = [
                        'reference' => ['href' => 'booking-details.php?id=' . $bid, 'label' => (string)$row['booking_reference']],
                        'guest' => (string)$row['guest_name'],
                        'room' => $room,
                        'checkout' => date('M j, Y', strtotime((string)$row['check_out_date'])),
                        'status' => ucfirst((string)$row['status']),
                        'payment' => ucfirst((string)$row['payment_status']),
                    ];
                }
                $payload['empty'] = 'No check-ins are due today.';
                break;

            case 'checkouts_today':
                $payload['title'] = "Today's Check-outs";
                $payload['subtitle'] = 'In-house guests due out today';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Booking'],
                    ['key' => 'guest', 'label' => 'Guest'],
                    ['key' => 'room', 'label' => 'Room'],
                    ['key' => 'checkout', 'label' => 'Check-out'],
                    ['key' => 'amount_due', 'label' => 'Outstanding'],
                ];
                $payload['link'] = ['href' => 'bookings.php?filter=checkout_today&flash=results', 'label' => 'Open check-outs list'];
                $stmt = $pdo->prepare("SELECT b.id AS booking_id, b.booking_reference, b.guest_name, b.check_out_date, b.amount_due,
                                              r.name AS room_name, ir.room_number, ir.room_name AS individual_room_name
                                       FROM bookings b
                                       JOIN rooms r ON r.id = b.room_id
                                       LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                                       WHERE b.check_out_date = ? AND b.status = 'checked-in'
                                       ORDER BY b.check_out_date ASC, b.created_at ASC
                                       LIMIT 30");
                $stmt->execute([$today]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $room = trim((string)($row['individual_room_name'] ?? ''));
                    if ($room === '') {
                        $room = trim((string)($row['room_number'] ?? ''));
                    }
                    if ($room !== '') {
                        $room = (trim((string)($row['room_name'] ?? '')) !== '' ? ((string)$row['room_name'] . ' · ') : '') . $room;
                    } else {
                        $room = (string)($row['room_name'] ?? '—');
                    }
                    $bid = (int)($row['booking_id'] ?? 0);
                    $payload['rows'][] = [
                        'reference' => ['href' => 'booking-details.php?id=' . $bid, 'label' => (string)$row['booking_reference']],
                        'guest' => (string)$row['guest_name'],
                        'room' => $room,
                        'checkout' => date('M j, Y', strtotime((string)$row['check_out_date'])),
                        'amount_due' => $formatMoney((float)($row['amount_due'] ?? 0)),
                    ];
                }
                $payload['empty'] = 'No check-outs are due today.';
                break;

            case 'pending_bookings':
                $payload['title'] = 'Pending Bookings';
                $payload['subtitle'] = 'Bookings waiting for confirmation';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Booking'],
                    ['key' => 'guest', 'label' => 'Guest'],
                    ['key' => 'checkin', 'label' => 'Check-in'],
                    ['key' => 'nights', 'label' => 'Nights'],
                    ['key' => 'total', 'label' => 'Total'],
                    ['key' => 'amount_due', 'label' => 'Outstanding'],
                ];
                $payload['link'] = ['href' => 'bookings.php?status=pending&flash=status:pending', 'label' => 'Open pending bookings'];
                $stmt = $pdo->query("SELECT id AS booking_id, booking_reference, guest_name, check_in_date, number_of_nights, total_amount, amount_due
                                     FROM bookings
                                     WHERE status = 'pending'
                                     ORDER BY created_at ASC
                                     LIMIT 30");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $bid = (int)($row['booking_id'] ?? 0);
                    $payload['rows'][] = [
                        'reference' => ['href' => 'booking-details.php?id=' . $bid, 'label' => (string)$row['booking_reference']],
                        'guest' => (string)$row['guest_name'],
                        'checkin' => date('M j, Y', strtotime((string)$row['check_in_date'])),
                        'nights' => (string)(int)($row['number_of_nights'] ?? 0),
                        'total' => $formatMoney((float)($row['total_amount'] ?? 0)),
                        'amount_due' => $formatMoney((float)($row['amount_due'] ?? 0)),
                    ];
                }
                $payload['empty'] = 'No pending bookings right now.';
                break;

            case 'inhouse_guests':
                $payload['title'] = 'In-House Guests';
                $payload['subtitle'] = 'Bookings currently checked in';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Booking'],
                    ['key' => 'guest', 'label' => 'Guest'],
                    ['key' => 'room', 'label' => 'Room'],
                    ['key' => 'checkout', 'label' => 'Check-out'],
                    ['key' => 'amount_due', 'label' => 'Outstanding'],
                ];
                $payload['link'] = ['href' => 'bookings.php?status=checked-in&flash=status:checked-in', 'label' => 'Open in-house guests'];
                $stmt = $pdo->query("SELECT b.id AS booking_id, b.booking_reference, b.guest_name, b.check_out_date, b.amount_due,
                                            r.name AS room_name, ir.room_number, ir.room_name AS individual_room_name
                                     FROM bookings b
                                     JOIN rooms r ON r.id = b.room_id
                                     LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                                     WHERE b.status = 'checked-in'
                                     ORDER BY b.check_out_date ASC
                                     LIMIT 30");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $room = trim((string)($row['individual_room_name'] ?? ''));
                    if ($room === '') {
                        $room = trim((string)($row['room_number'] ?? ''));
                    }
                    if ($room !== '') {
                        $room = (trim((string)($row['room_name'] ?? '')) !== '' ? ((string)$row['room_name'] . ' · ') : '') . $room;
                    } else {
                        $room = (string)($row['room_name'] ?? '—');
                    }
                    $bid = (int)($row['booking_id'] ?? 0);
                    $payload['rows'][] = [
                        'reference' => ['href' => 'booking-details.php?id=' . $bid, 'label' => (string)$row['booking_reference']],
                        'guest' => (string)$row['guest_name'],
                        'room' => $room,
                        'checkout' => date('M j, Y', strtotime((string)$row['check_out_date'])),
                        'amount_due' => $formatMoney((float)($row['amount_due'] ?? 0)),
                    ];
                }
                $payload['empty'] = 'No guests are currently checked in.';
                break;

            case 'pending_conference':
                $payload['title'] = 'Pending Conference Enquiries';
                $payload['subtitle'] = 'Awaiting quote, call-back, or confirmation';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Reference'],
                    ['key' => 'company', 'label' => 'Company'],
                    ['key' => 'contact', 'label' => 'Contact'],
                    ['key' => 'event_date', 'label' => 'Event Date'],
                    ['key' => 'attendees', 'label' => 'Attendees'],
                ];
                $payload['link'] = ['href' => 'conference-management.php?status=pending', 'label' => 'Open pending enquiries'];
                $stmt = $pdo->query("SELECT id AS inquiry_id, inquiry_reference, company_name, contact_person, event_date, number_of_attendees
                                     FROM conference_inquiries
                                     WHERE status = 'pending'
                                     ORDER BY created_at ASC
                                     LIMIT 30");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $iid = (int)($row['inquiry_id'] ?? 0);
                    $payload['rows'][] = [
                        'reference' => ['href' => 'conference-management.php#enquiry-' . $iid, 'label' => (string)$row['inquiry_reference']],
                        'company' => (string)$row['company_name'],
                        'contact' => (string)$row['contact_person'],
                        'event_date' => date('M j, Y', strtotime((string)$row['event_date'])),
                        'attendees' => (string)(int)($row['number_of_attendees'] ?? 0),
                    ];
                }
                $payload['empty'] = 'No pending conference enquiries right now.';
                break;

            case 'today_conferences':
                $payload['title'] = "Today's Conference Events";
                $payload['subtitle'] = 'Confirmed + pending events due today';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Reference'],
                    ['key' => 'company', 'label' => 'Company'],
                    ['key' => 'room', 'label' => 'Room'],
                    ['key' => 'time', 'label' => 'Time'],
                    ['key' => 'status', 'label' => 'Status'],
                ];
                $payload['link'] = ['href' => 'conference-management.php?event_date=' . urlencode($today), 'label' => 'Open today\'s conference events'];
                $stmt = $pdo->prepare("SELECT ci.id AS inquiry_id, ci.inquiry_reference, ci.company_name, ci.start_time, ci.end_time, ci.status,
                                              cr.name AS room_name
                                       FROM conference_inquiries ci
                                       LEFT JOIN conference_rooms cr ON cr.id = ci.conference_room_id
                                       WHERE ci.event_date = ? AND ci.status IN ('confirmed','pending')
                                       ORDER BY ci.start_time ASC
                                       LIMIT 30");
                $stmt->execute([$today]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $iid = (int)($row['inquiry_id'] ?? 0);
                    $payload['rows'][] = [
                        'reference' => ['href' => 'conference-management.php#enquiry-' . $iid, 'label' => (string)$row['inquiry_reference']],
                        'company' => (string)$row['company_name'],
                        'room' => (string)($row['room_name'] ?? 'Unassigned'),
                        'time' => date('H:i', strtotime((string)$row['start_time'])) . ' - ' . date('H:i', strtotime((string)$row['end_time'])),
                        'status' => ucfirst((string)$row['status']),
                    ];
                }
                $payload['empty'] = 'No conference events are scheduled for today.';
                break;

            case 'expired_bookings':
                $payload['title'] = 'Expired Bookings (Last 24 Hours)';
                $payload['subtitle'] = 'Holds released after payment timeout';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Booking'],
                    ['key' => 'guest', 'label' => 'Guest'],
                    ['key' => 'checkin', 'label' => 'Check-in'],
                    ['key' => 'expired_at', 'label' => 'Expired At'],
                    ['key' => 'amount_due', 'label' => 'Amount Due'],
                ];
                $payload['link'] = ['href' => 'bookings.php?status=expired&flash=status:expired', 'label' => 'Open expired bookings'];
                $stmt = $pdo->query("SELECT id AS booking_id, booking_reference, guest_name, check_in_date, expired_at, amount_due
                                     FROM bookings
                                     WHERE status = 'expired' AND expired_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                     ORDER BY expired_at DESC
                                     LIMIT 30");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $bid = (int)($row['booking_id'] ?? 0);
                    $payload['rows'][] = [
                        'reference' => ['href' => 'booking-details.php?id=' . $bid, 'label' => (string)$row['booking_reference']],
                        'guest' => (string)$row['guest_name'],
                        'checkin' => date('M j, Y', strtotime((string)$row['check_in_date'])),
                        'expired_at' => $formatDateTime((string)$row['expired_at']),
                        'amount_due' => $formatMoney((float)($row['amount_due'] ?? 0)),
                    ];
                }
                $payload['empty'] = 'No bookings have expired in the last 24 hours.';
                break;

            case 'outstanding_balances':
                $payload['title'] = 'Outstanding Balances';
                $payload['subtitle'] = 'Accounts with amount due still unpaid';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Reference'],
                    ['key' => 'department', 'label' => 'Department'],
                    ['key' => 'guest', 'label' => 'Client'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'total', 'label' => 'Total (incl. VAT & extras)'],
                    ['key' => 'paid', 'label' => 'Paid'],
                    ['key' => 'due', 'label' => 'Outstanding'],
                ];
                $payload['link'] = ['href' => 'payments.php?balance=outstanding', 'label' => 'Open outstanding balances'];
                // Pull receivables only from modules this preset runs. The grand total
                // shown is amount_paid + amount_due (the gross, VAT-inclusive amount
                // owed, plus any folio extras for rooms) so Outstanding can never
                // exceed Total — total_amount alone is the NET base and would read
                // lower than the gross balance due.
                $ob_union = [];
                if ($mod_bookings)   { $ob_union[] = "SELECT id, booking_reference AS ref, guest_name AS who, status, amount_paid, amount_due, 'booking' AS src FROM bookings WHERE amount_due > 0 AND status IN ('pending','confirmed','checked-in')"; }
                if ($mod_conference) { $ob_union[] = "SELECT id, inquiry_reference AS ref, COALESCE(NULLIF(company_name,''), contact_person) AS who, status, amount_paid, amount_due, 'conference' AS src FROM conference_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled')"; }
                if ($mod_gym)        { $ob_union[] = "SELECT id, reference_number AS ref, name AS who, status, amount_paid, amount_due, 'gym' AS src FROM gym_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled','closed')"; }
                if ($mod_events)     { $ob_union[] = "SELECT id, reference_number AS ref, name AS who, status, amount_paid, amount_due, 'event' AS src FROM event_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled')"; }
                $rows = [];
                foreach ($ob_union as $obSql) {
                    try {
                        foreach ($pdo->query($obSql)->fetchAll(PDO::FETCH_ASSOC) as $obRow) { $rows[] = $obRow; }
                    } catch (Throwable $e) { /* module table may not exist yet */ }
                }
                usort($rows, static fn($a, $b) => (float)$b['amount_due'] <=> (float)$a['amount_due']);
                $rows = array_slice($rows, 0, 30);
                // Land on the record's own page/row (scrolled + flashed) rather
                // than dumping the user on a general list. Bookings have a detail
                // page; the inquiry lists deep-link to the anchored row.
                $ob_links = ['booking' => 'booking-details.php?id=', 'conference' => 'conference-management.php#enquiry-', 'gym' => 'gym-inquiries.php#inquiry-', 'event' => 'events-inquiries.php#inquiry-'];
                // Which department the receivable belongs to, so the modal makes
                // clear whether an outstanding balance is for a room booking, the
                // gym, an event, etc.
                $ob_departments = ['booking' => 'Rooms', 'conference' => 'Conference', 'gym' => 'Gym', 'event' => 'Events'];
                foreach ($rows as $row) {
                    $bid = (int)($row['id'] ?? 0);
                    $src = (string)($row['src'] ?? '');
                    $rowPaid = (float)($row['amount_paid'] ?? 0);
                    $rowDue  = (float)($row['amount_due'] ?? 0);
                    // Grand total (gross, incl. VAT and any room folio extras) is the
                    // sum of what has been paid and what is still owed. This is the
                    // authoritative invoiced total and guarantees Paid + Outstanding
                    // reconcile to Total on the card.
                    $rowGrand = $rowPaid + $rowDue;
                    $payload['rows'][] = [
                        'reference' => ['href' => ($ob_links[$src] ?? 'payments.php?q=') . $bid, 'label' => (string)$row['ref']],
                        'department' => $ob_departments[$src] ?? ucfirst($src),
                        'guest' => (string)$row['who'],
                        'status' => ucfirst((string)$row['status']),
                        'total' => $formatMoney($rowGrand),
                        'paid' => $formatMoney($rowPaid),
                        'due' => $formatMoney($rowDue),
                    ];
                }
                $payload['empty'] = 'No outstanding balances right now.';
                break;

            case 'open_tabs':
                $payload['title'] = isRestaurantEnabled() ? 'Open Restaurant Tabs Awaiting Payment' : 'Placed Orders Awaiting Payment';
                $payload['subtitle'] = isRestaurantEnabled() ? 'Oldest open tabs first (max 30 shown)' : 'Oldest pending orders first (max 30 shown)';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Order'],
                    ['key' => 'location', 'label' => 'Location'],
                    ['key' => 'foh', 'label' => 'FOH (POS)'],
                    ['key' => 'items', 'label' => 'Items'],
                    ['key' => 'total', 'label' => isRestaurantEnabled() ? 'Tab Total' : 'Order Total'],
                    ['key' => 'age', 'label' => 'Age'],
                    ['key' => 'status', 'label' => 'Status'],
                ];
                $payload['link'] = $mod_stock
                    ? ['href' => 'stock-orders.php?status=placed', 'label' => 'Open full tabs list']
                    : ['href' => 'pos.php', 'label' => 'Open POS till'];
                $stmt = $pdo->query("SELECT o.id AS order_id,
                                            o.reference,
                                            o.table_number,
                                            o.room_number,
                                            o.customer_name,
                                            o.total_amount,
                                            o.created_at,
                                            o.status,
                                            COALESCE(NULLIF(u.full_name, ''), u.username, 'POS') AS foh_in_charge
                                     FROM stock_orders o
                                     LEFT JOIN admin_users u ON u.id = o.created_by
                                     WHERE o.status = 'placed'
                                     ORDER BY o.created_at ASC
                                     LIMIT 30");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $itemsByOrder = [];
                $orderIds = [];
                foreach ($rows as $row) {
                    $orderId = (int)($row['order_id'] ?? 0);
                    if ($orderId > 0) {
                        $orderIds[] = $orderId;
                    }
                }
                $orderIds = array_values(array_unique($orderIds));

                if ($orderIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                    $itemStmt = $pdo->prepare(
                        "SELECT order_id,
                                item_name,
                                quantity,
                                COALESCE(kds_status, 'pending') AS kds_status
                         FROM stock_order_items
                         WHERE order_id IN ($placeholders)
                         ORDER BY order_id ASC, id ASC"
                    );
                    $itemStmt->execute($orderIds);
                    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $itemRow) {
                        $orderId = (int)($itemRow['order_id'] ?? 0);
                        if ($orderId <= 0) {
                            continue;
                        }
                        $itemsByOrder[$orderId][] = [
                            'name' => trim((string)($itemRow['item_name'] ?? '')),
                            'quantity' => (int)($itemRow['quantity'] ?? 0),
                            'kds_status' => (string)($itemRow['kds_status'] ?? 'pending'),
                        ];
                    }
                }

                foreach ($rows as $row) {
                    $orderId = (int)($row['order_id'] ?? 0);
                    $orderStatus = $humanizeStatus((string)($row['status'] ?? ''));
                    $orderItems = $itemsByOrder[$orderId] ?? [];
                    $detailsItems = [];
                    foreach ($orderItems as $itemRow) {
                        $detailsItems[] = [
                            'name' => trim((string)($itemRow['name'] ?? '')) !== '' ? (string)$itemRow['name'] : 'Item',
                            'quantity' => (int)($itemRow['quantity'] ?? 0),
                            'kds_status' => $humanizeStatus((string)($itemRow['kds_status'] ?? '')),
                            'pos_status' => $orderStatus,
                        ];
                    }

                    $reference = trim((string)($row['reference'] ?? ''));
                    if ($reference === '' && $orderId > 0) {
                        $reference = 'Order #' . $orderId;
                    }
                    $itemCount = count($detailsItems);
                    $payload['rows'][] = [
                        'order_id' => $orderId,
                        'reference' => [
                            'type' => 'details',
                            'summary' => $reference !== '' ? $reference : 'Order',
                            'caption' => $itemCount . ' item' . ($itemCount === 1 ? '' : 's'),
                            'items' => $detailsItems,
                        ],
                        'location' => $locationLabel((string)($row['table_number'] ?? ''), (string)($row['room_number'] ?? ''), (string)($row['customer_name'] ?? '')),
                        'foh' => trim((string)($row['foh_in_charge'] ?? '')) !== '' ? (string)$row['foh_in_charge'] : 'POS',
                        'items' => (string)$itemCount,
                        'total' => $formatMoney((float)($row['total_amount'] ?? 0)),
                        'age' => $formatAge((string)$row['created_at']),
                        'status' => $orderStatus,
                    ];
                }
                $payload['empty'] = isRestaurantEnabled() ? 'No open restaurant tabs are awaiting payment.' : 'No placed orders are awaiting payment.';
                break;

            case 'room_service_reminders_due':
                $payload['title'] = 'Room-Service Daily Reminder';
                $payload['subtitle'] = $roomServiceReminderDueNow
                    ? 'Occupied rooms still waiting for today\'s room-service completion.'
                    : ('Reminder becomes active daily at ' . $roomServiceReminderTime . ' (' . $roomServiceReminderTimezone . ').');
                $payload['columns'] = [
                    ['key' => 'room', 'label' => 'Occupied Room'],
                    ['key' => 'guest', 'label' => 'Guest'],
                    ['key' => 'service_state', 'label' => 'Room Service Today'],
                    ['key' => 'last_service', 'label' => 'Last Service'],
                    ['key' => 'housekeeping', 'label' => 'Housekeeping Assignee'],
                    ['key' => 'workload', 'label' => 'Staff Workload'],
                    ['key' => 'action', 'label' => 'Follow-up'],
                ];
                $payload['link'] = ['href' => 'housekeeping.php', 'label' => 'Open housekeeping follow-up'];

                $stmt = $pdo->query("SELECT
                                        b.id AS booking_id,
                                        b.booking_reference,
                                        b.guest_name,
                                        ir.room_number,
                                        ir.room_name,
                                        hk_user.username AS housekeeping_assignee,
                                        COALESCE(hk_workload.active_tasks, 0) AS staff_active_tasks,
                                        COALESCE(hk_workload.completed_today, 0) AS staff_completed_today,
                                        COALESCE(rs_booking.completed_today, rs_room.completed_today, 0) AS room_service_completed_today,
                                        COALESCE(rs_booking.active_orders_today, rs_room.active_orders_today, 0) AS room_service_active_today,
                                        COALESCE(rs_booking.last_service_at, rs_room.last_service_at) AS last_service_at
                                    FROM bookings b
                                    INNER JOIN individual_rooms ir ON ir.id = b.individual_room_id
                                    LEFT JOIN (
                                        SELECT h1.individual_room_id, h1.assigned_to
                                        FROM housekeeping_assignments h1
                                        INNER JOIN (
                                            SELECT individual_room_id, MAX(id) AS latest_id
                                            FROM housekeeping_assignments
                                            WHERE status IN ('pending', 'in_progress')
                                            GROUP BY individual_room_id
                                        ) h2 ON h2.latest_id = h1.id
                                    ) hk_latest ON hk_latest.individual_room_id = b.individual_room_id
                                    LEFT JOIN admin_users hk_user ON hk_user.id = hk_latest.assigned_to
                                    LEFT JOIN (
                                        SELECT
                                            ha.assigned_to,
                                            COUNT(CASE WHEN ha.status IN ('pending', 'in_progress') THEN 1 END) AS active_tasks,
                                            COUNT(CASE WHEN ha.status = 'completed' AND DATE(ha.completed_at) = CURDATE() THEN 1 END) AS completed_today
                                        FROM housekeeping_assignments ha
                                        WHERE ha.assigned_to IS NOT NULL
                                          AND (ha.status IN ('pending', 'in_progress') OR (ha.status = 'completed' AND DATE(ha.completed_at) = CURDATE()))
                                        GROUP BY ha.assigned_to
                                    ) hk_workload ON hk_workload.assigned_to = hk_latest.assigned_to
                                    LEFT JOIN (
                                        SELECT
                                            booking_id,
                                            SUM(CASE
                                                WHEN (status IN ('completed', 'paid') OR kitchen_status = 'served')
                                                 AND DATE(COALESCE(served_at, updated_at, created_at)) = CURDATE()
                                                THEN 1 ELSE 0 END) AS completed_today,
                                            SUM(CASE
                                                WHEN status IN ('placed', 'pending', 'confirmed')
                                                  OR kitchen_status IN ('new', 'in_progress', 'ready', 'recalled', 'collection')
                                                THEN 1 ELSE 0 END) AS active_orders_today,
                                            MAX(CASE
                                                WHEN (status IN ('completed', 'paid') OR kitchen_status = 'served')
                                                THEN COALESCE(served_at, updated_at, created_at)
                                                ELSE NULL END) AS last_service_at
                                        FROM stock_orders
                                        WHERE order_type = 'room_service'
                                          AND booking_id IS NOT NULL
                                        GROUP BY booking_id
                                    ) rs_booking ON rs_booking.booking_id = b.id
                                    LEFT JOIN (
                                        SELECT
                                            individual_room_id,
                                            SUM(CASE
                                                WHEN (status IN ('completed', 'paid') OR kitchen_status = 'served')
                                                 AND DATE(COALESCE(served_at, updated_at, created_at)) = CURDATE()
                                                THEN 1 ELSE 0 END) AS completed_today,
                                            SUM(CASE
                                                WHEN status IN ('placed', 'pending', 'confirmed')
                                                  OR kitchen_status IN ('new', 'in_progress', 'ready', 'recalled', 'collection')
                                                THEN 1 ELSE 0 END) AS active_orders_today,
                                            MAX(CASE
                                                WHEN (status IN ('completed', 'paid') OR kitchen_status = 'served')
                                                THEN COALESCE(served_at, updated_at, created_at)
                                                ELSE NULL END) AS last_service_at
                                        FROM stock_orders
                                        WHERE order_type = 'room_service'
                                          AND booking_id IS NULL
                                          AND individual_room_id IS NOT NULL
                                        GROUP BY individual_room_id
                                    ) rs_room ON rs_room.individual_room_id = b.individual_room_id
                                    WHERE b.status = 'checked-in'
                                      AND b.individual_room_id IS NOT NULL
                                      AND ir.is_active = 1
                                    ORDER BY ir.room_number ASC, b.id ASC
                                    LIMIT 80");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $roomNumber = trim((string)($row['room_number'] ?? ''));
                    $roomName = trim((string)($row['room_name'] ?? ''));
                    $roomLabel = $roomNumber !== '' ? ('Room ' . $roomNumber) : 'Room';
                    if ($roomName !== '') {
                        $roomLabel .= ' - ' . $roomName;
                    }

                    $completedToday = (int)($row['room_service_completed_today'] ?? 0) > 0;
                    $activeOrdersToday = (int)($row['room_service_active_today'] ?? 0);
                    if ($completedToday) {
                        $serviceState = 'Completed';
                    } elseif ($activeOrdersToday > 0) {
                        $serviceState = 'In Progress';
                    } elseif ($roomServiceReminderDueNow) {
                        $serviceState = 'Due - Pending';
                    } else {
                        $serviceState = 'Pending (Not Due Yet)';
                    }

                    $housekeepingAssignee = trim((string)($row['housekeeping_assignee'] ?? ''));
                    if ($housekeepingAssignee === '') {
                        $housekeepingAssignee = 'Unassigned';
                    }
                    $workload = 'No active assignee';
                    if ($housekeepingAssignee !== 'Unassigned') {
                        $workload = (int)($row['staff_active_tasks'] ?? 0) . ' active / ' . (int)($row['staff_completed_today'] ?? 0) . ' completed today';
                    }

                    $housekeepingLink = 'housekeeping.php';
                    if ($roomNumber !== '') {
                        $housekeepingLink .= '?room=' . rawurlencode($roomNumber);
                    }

                    $payload['rows'][] = [
                        'booking_id' => (int)($row['booking_id'] ?? 0),
                        'room' => $roomLabel,
                        'guest' => trim((string)($row['guest_name'] ?? '')) !== '' ? (string)$row['guest_name'] : 'Walk-in guest',
                        'service_state' => $serviceState,
                        'last_service' => $formatDateTime((string)($row['last_service_at'] ?? '')),
                        'housekeeping' => $housekeepingAssignee,
                        'workload' => $workload,
                        'action' => ['href' => $housekeepingLink, 'label' => 'Housekeeping', 'target' => '_blank'],
                    ];
                }
                $payload['empty'] = 'No occupied rooms currently require room-service reminder tracking.';
                break;

            case 'room_service_pending':
                $payload['title'] = 'Room-Service Orders In Flight';
                $payload['subtitle'] = 'Room orders that still need fulfilment or settlement';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Order'],
                    ['key' => 'room', 'label' => 'Room'],
                    ['key' => 'guest', 'label' => 'Guest'],
                    ['key' => 'items', 'label' => 'Items'],
                    ['key' => 'total', 'label' => 'Total'],
                    ['key' => 'status', 'label' => 'Status'],
                ];
                $payload['link'] = ['href' => 'stock-orders.php?type=room_service', 'label' => 'Open room-service orders'];
                $stmt = $pdo->query("SELECT o.id AS order_id, o.reference, o.room_number, o.customer_name, o.total_amount, o.status,
                                            (SELECT COUNT(*) FROM stock_order_items oi WHERE oi.order_id = o.id) AS item_count
                                     FROM stock_orders o
                                     WHERE o.order_type = 'room_service' AND o.status IN ('placed','pending','confirmed')
                                     ORDER BY o.created_at ASC
                                     LIMIT 30");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $payload['rows'][] = [
                        'order_id' => (int)($row['order_id'] ?? 0),
                        'reference' => (string)$row['reference'],
                        'room' => trim((string)($row['room_number'] ?? '')) !== '' ? ('Room ' . (string)$row['room_number']) : '—',
                        'guest' => trim((string)($row['customer_name'] ?? '')) !== '' ? (string)$row['customer_name'] : '—',
                        'items' => (string)(int)($row['item_count'] ?? 0),
                        'total' => $formatMoney((float)($row['total_amount'] ?? 0)),
                        'status' => ucfirst((string)$row['status']),
                    ];
                }
                $payload['empty'] = 'No room-service orders are pending right now.';
                break;

            case 'kitchen_tickets':
            case 'bar_tickets':
            case 'coffee_tickets':
                $station = $card === 'kitchen_tickets' ? 'kitchen' : ($card === 'bar_tickets' ? 'bar' : 'coffee_bar');
                $stationLabel = $card === 'kitchen_tickets' ? 'Kitchen' : ($card === 'bar_tickets' ? 'Bar' : 'Coffee');
                $payload['title'] = $stationLabel . ' Ticket Queue';
                $payload['subtitle'] = 'Active station tickets for ' . $stationWindowLabel . ($stationHoursLabel !== '' ? (' (' . $stationHoursLabel . ')') : '');
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Order'],
                    ['key' => 'location', 'label' => 'Location'],
                    ['key' => 'queue', 'label' => 'Queue Breakdown'],
                    ['key' => 'total', 'label' => 'Order Total'],
                    ['key' => 'fired', 'label' => 'Fired At'],
                ];
                $payload['link'] = ['href' => ($card === 'kitchen_tickets' ? 'kds.php' : ($card === 'bar_tickets' ? 'bds.php' : 'cds.php')), 'label' => 'Open ' . $stationLabel . ' display'];
                $queueSql = "SELECT o.id AS order_id, o.reference, o.table_number, o.room_number, o.customer_name, o.total_amount,
                                    o.fired_at,
                                    SUM(CASE WHEN oi.kds_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                                    SUM(CASE WHEN oi.kds_status = 'preparing' THEN 1 ELSE 0 END) AS preparing_count,
                                    SUM(CASE WHEN oi.kds_status = 'ready' THEN 1 ELSE 0 END) AS ready_count,
                                    SUM(CASE WHEN oi.kds_status = 'collection' THEN 1 ELSE 0 END) AS collection_count
                             FROM stock_orders o
                             INNER JOIN stock_order_items oi ON oi.order_id = o.id AND oi.station = ?
                             WHERE o.kitchen_status IN ('new','in_progress','ready','recalled')
                               AND o.fired_at IS NOT NULL
                               AND oi.kds_status NOT IN ('served','void')";
                $queueParams = [$station];
                if ($stationWindowStart !== '' && $stationWindowEnd !== '') {
                    $queueSql .= " AND o.fired_at >= ? AND o.fired_at < ?";
                    $queueParams[] = $stationWindowStart;
                    $queueParams[] = $stationWindowEnd;
                }
                $queueSql .= " GROUP BY o.id ORDER BY o.fired_at ASC LIMIT 30";
                $stmt = $pdo->prepare($queueSql);
                $stmt->execute($queueParams);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $parts = [];
                    $pendingCount = (int)($row['pending_count'] ?? 0);
                    $preparingCount = (int)($row['preparing_count'] ?? 0);
                    $readyCount = (int)($row['ready_count'] ?? 0);
                    $collectionCount = (int)($row['collection_count'] ?? 0);
                    if ($pendingCount > 0) {
                        $parts[] = $pendingCount . ' pending';
                    }
                    if ($preparingCount > 0) {
                        $parts[] = $preparingCount . ' preparing';
                    }
                    if ($readyCount > 0) {
                        $parts[] = $readyCount . ' ready';
                    }
                    if ($collectionCount > 0) {
                        $parts[] = $collectionCount . ' collection';
                    }
                    $payload['rows'][] = [
                        'order_id' => (int)($row['order_id'] ?? 0),
                        'reference' => (string)$row['reference'],
                        'location' => $locationLabel((string)($row['table_number'] ?? ''), (string)($row['room_number'] ?? ''), (string)($row['customer_name'] ?? '')),
                        'queue' => $parts ? implode(' · ', $parts) : 'No active queue',
                        'total' => $formatMoney((float)($row['total_amount'] ?? 0)),
                        'fired' => $formatDateTime((string)$row['fired_at']),
                    ];
                }
                $payload['empty'] = 'No ' . strtolower($stationLabel) . ' tickets are waiting right now.';
                break;

            case 'restaurant_revenue_today':
                $payload['title'] = 'Restaurant Revenue Today';
                $payload['subtitle'] = 'Settled restaurant orders in the current day';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Order'],
                    ['key' => 'type', 'label' => 'Type'],
                    ['key' => 'method', 'label' => 'Payment Method'],
                    ['key' => 'amount', 'label' => 'Amount'],
                    ['key' => 'settled_at', 'label' => 'Settled At'],
                ];
                $payload['link'] = ['href' => 'reports.php?type=accounting&range=today', 'label' => 'Open restaurant revenue report'];
                $stmt = $pdo->query("SELECT id AS order_id, reference, order_type, payment_method, total_amount, COALESCE(paid_at, created_at) AS settled_at
                                     FROM stock_orders
                                     WHERE status IN ('paid','completed')
                                       AND DATE(COALESCE(paid_at, created_at)) = CURDATE()
                                     ORDER BY COALESCE(paid_at, created_at) DESC
                                     LIMIT 30");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $payload['rows'][] = [
                        'order_id' => (int)($row['order_id'] ?? 0),
                        'reference' => (string)$row['reference'],
                        'type' => ucwords(str_replace('_', ' ', (string)$row['order_type'])),
                        'method' => ucwords(str_replace('_', ' ', (string)($row['payment_method'] ?? 'N/A'))),
                        'amount' => $formatMoney((float)($row['total_amount'] ?? 0)),
                        'settled_at' => $formatDateTime((string)$row['settled_at']),
                    ];
                }
                $payload['empty'] = 'No restaurant orders have been settled today yet.';
                break;

            case 'total_revenue_today':
                $payload['title'] = 'Total Revenue Today';
                $payload['subtitle'] = $mod_pos
                    ? ('Payments ledger plus settled ' . (isRestaurantEnabled() ? 'restaurant' : 'POS') . ' orders')
                    : 'Payments ledger';
                $payload['columns'] = [
                    ['key' => 'source', 'label' => 'Source'],
                    ['key' => 'reference', 'label' => 'Reference'],
                    ['key' => 'context', 'label' => 'Context'],
                    ['key' => 'method', 'label' => 'Method'],
                    ['key' => 'amount', 'label' => 'Amount'],
                    ['key' => 'time', 'label' => 'Time'],
                ];
                $payload['link'] = ['href' => 'payments.php?date=' . urlencode($today), 'label' => 'Open payments captured today'];
                $combined = [];

                $payStmt = $pdo->query("SELECT id AS payment_id,
                                                COALESCE(payment_reference, CONCAT('PAY-', id)) AS payment_reference,
                                                COALESCE(booking_reference, booking_type, 'Payment') AS context_label,
                                                COALESCE(payment_method, 'other') AS payment_method,
                                                COALESCE(payment_amount, total_amount, 0) AS amount,
                                                COALESCE(created_at, CONCAT(payment_date, ' 00:00:00')) AS ts
                                         FROM payments
                                         WHERE DATE(payment_date) = CURDATE()
                                           AND payment_status IN ('paid','completed','partial')
                                           AND deleted_at IS NULL
                                           AND COALESCE(payment_type, '') <> 'refund'
                                           AND booking_type <> 'restaurant'
                                         ORDER BY COALESCE(created_at, CONCAT(payment_date, ' 00:00:00')) DESC
                                         LIMIT 20");
                foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $combined[] = [
                        'payment_id' => (int)($row['payment_id'] ?? 0),
                        'source' => 'Payments',
                        'reference' => (string)$row['payment_reference'],
                        'context' => (string)$row['context_label'],
                        'method' => ucwords(str_replace('_', ' ', (string)$row['payment_method'])),
                        'amount' => $formatMoney((float)($row['amount'] ?? 0)),
                        'time' => $formatDateTime((string)$row['ts']),
                        '_ts' => (string)$row['ts'],
                    ];
                }

                if ($mod_pos) {
                $restStmt = $pdo->query("SELECT id AS order_id, reference, order_type, payment_method, total_amount, COALESCE(paid_at, created_at) AS ts
                                          FROM stock_orders
                                          WHERE status IN ('paid','completed')
                                            AND DATE(COALESCE(paid_at, created_at)) = CURDATE()
                                          ORDER BY COALESCE(paid_at, created_at) DESC
                                          LIMIT 15");
                foreach ($restStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $combined[] = [
                        'order_id' => (int)($row['order_id'] ?? 0),
                        'source' => isRestaurantEnabled() ? 'Restaurant' : 'POS',
                        'reference' => (string)$row['reference'],
                        'context' => ucwords(str_replace('_', ' ', (string)$row['order_type'])),
                        'method' => ucwords(str_replace('_', ' ', (string)($row['payment_method'] ?? 'N/A'))),
                        'amount' => $formatMoney((float)($row['total_amount'] ?? 0)),
                        'time' => $formatDateTime((string)$row['ts']),
                        '_ts' => (string)$row['ts'],
                    ];
                }
                }

                usort($combined, static function (array $a, array $b): int {
                    return strtotime((string)($b['_ts'] ?? '')) <=> strtotime((string)($a['_ts'] ?? ''));
                });
                $combined = array_slice($combined, 0, 30);
                foreach ($combined as $row) {
                    unset($row['_ts']);
                    $payload['rows'][] = $row;
                }
                $payload['empty'] = 'No revenue entries were captured today yet.';
                break;

            case 'refunds_pending':
                $payload['title'] = 'Refunds Pending';
                $payload['subtitle'] = 'Refund entries awaiting approval or processing';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Payment Ref'],
                    ['key' => 'booking', 'label' => 'Booking Ref'],
                    ['key' => 'method', 'label' => 'Method'],
                    ['key' => 'amount', 'label' => 'Amount'],
                    ['key' => 'status', 'label' => 'Refund Status'],
                    ['key' => 'requested', 'label' => 'Requested'],
                ];
                $payload['link'] = ['href' => 'payments.php?refund_status=pending', 'label' => 'Open pending refunds'];
                $stmt = $pdo->query("SELECT id AS payment_id,
                                            COALESCE(payment_reference, CONCAT('PAY-', id)) AS payment_reference,
                                            COALESCE(booking_reference, '—') AS booking_reference,
                                            COALESCE(payment_method, 'other') AS payment_method,
                                            COALESCE(total_amount, payment_amount, 0) AS amount,
                                            COALESCE(refund_status, 'pending') AS refund_status,
                                            COALESCE(created_at, CONCAT(payment_date, ' 00:00:00')) AS requested_at
                                     FROM payments
                                     WHERE payment_type = 'refund'
                                       AND refund_status IN ('pending','processing')
                                       AND deleted_at IS NULL
                                     ORDER BY COALESCE(created_at, CONCAT(payment_date, ' 00:00:00')) DESC
                                     LIMIT 30");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $payload['rows'][] = [
                        'payment_id' => (int)($row['payment_id'] ?? 0),
                        'reference' => (string)$row['payment_reference'],
                        'booking' => (string)$row['booking_reference'],
                        'method' => ucwords(str_replace('_', ' ', (string)$row['payment_method'])),
                        'amount' => $formatMoney((float)($row['amount'] ?? 0)),
                        'status' => ucfirst((string)$row['refund_status']),
                        'requested' => $formatDateTime((string)$row['requested_at']),
                    ];
                }
                $payload['empty'] = 'No refunds are pending right now.';
                break;

            case 'stock_health':
                $payload['title'] = 'Stock Health';
                $payload['subtitle'] = 'Inventory risk and wastage overview';
                $payload['columns'] = [
                    ['key' => 'metric', 'label' => 'Metric'],
                    ['key' => 'current', 'label' => 'Current'],
                    ['key' => 'detail', 'label' => 'Detail'],
                ];
                $payload['link'] = ['href' => 'stock-orders.php?view=stock', 'label' => 'Open stock dashboard'];

                $lowCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE is_archived = 0 AND min_quantity > 0 AND current_quantity <= min_quantity")->fetchColumn();
                $expiringCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches WHERE status = 'active' AND quantity_remaining > 0 AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
                $expiredCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches WHERE status = 'active' AND quantity_remaining > 0 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetchColumn();
                $wastageToday = (float)$pdo->query("SELECT COALESCE(SUM(quantity * COALESCE(cost_per_unit,0)), 0) FROM stock_wastage WHERE DATE(created_at) = CURDATE()")->fetchColumn();

                $lowItemRows = $pdo->query("SELECT name, current_quantity, min_quantity, unit
                                            FROM stock_ingredients
                                            WHERE is_archived = 0 AND min_quantity > 0 AND current_quantity <= min_quantity
                                            ORDER BY (current_quantity / NULLIF(min_quantity, 0)) ASC
                                            LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
                $expiringRows = $pdo->query("SELECT i.name, b.expiry_date
                                             FROM stock_batches b
                                             INNER JOIN stock_ingredients i ON i.id = b.ingredient_id
                                             WHERE b.status = 'active'
                                               AND b.quantity_remaining > 0
                                               AND b.expiry_date IS NOT NULL
                                               AND b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                                             ORDER BY b.expiry_date ASC
                                             LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

                $lowDetail = 'All tracked ingredients are above minimum.';
                if ($lowItemRows !== []) {
                    $lowParts = [];
                    foreach ($lowItemRows as $itemRow) {
                        $lowParts[] = (string)$itemRow['name'] . ' (' . number_format((float)($itemRow['current_quantity'] ?? 0), 1) . '/' . number_format((float)($itemRow['min_quantity'] ?? 0), 1) . ')';
                    }
                    $lowDetail = implode(' · ', $lowParts);
                }

                $expiringDetail = 'No batches expiring in the next 7 days.';
                if ($expiringRows !== []) {
                    $expiringParts = [];
                    foreach ($expiringRows as $itemRow) {
                        $expiringParts[] = (string)$itemRow['name'] . ' (' . $formatDateTime((string)($itemRow['expiry_date'] ?? '')) . ')';
                    }
                    $expiringDetail = implode(' · ', $expiringParts);
                }

                $payload['rows'][] = [
                    'metric' => 'Low stock ingredients',
                    'current' => (string)$lowCount,
                    'detail' => $lowDetail,
                    'action' => ['href' => 'stock-ingredients.php?filter=low', 'label' => 'Ingredients', 'target' => '_blank'],
                ];
                $payload['rows'][] = [
                    'metric' => 'Expiring batches (<= 7 days)',
                    'current' => (string)$expiringCount,
                    'detail' => $expiringDetail,
                    'action' => ['href' => 'stock-batches.php?filter=expiring', 'label' => 'Batches', 'target' => '_blank'],
                ];
                $payload['rows'][] = [
                    'metric' => 'Expired active batches',
                    'current' => (string)$expiredCount,
                    'detail' => $expiredCount > 0 ? 'Immediate review required' : 'No expired active stock',
                    'action' => ['href' => 'stock-batches.php?filter=expired', 'label' => 'Review', 'target' => '_blank'],
                ];
                $payload['rows'][] = [
                    'metric' => 'Wastage today',
                    'current' => $formatMoney($wastageToday),
                    'detail' => $wastageToday > 0 ? 'Recorded from stock_wastage log' : 'No wastage entries logged today',
                    'action' => ['href' => 'stock-wastage.php', 'label' => 'Wastage', 'target' => '_blank'],
                ];
                $payload['empty'] = 'No stock health entries are available right now.';
                break;

            case 'guest_services_queue':
                // Only queues from modules this preset runs — a gym must not see
                // "Today's check-ins", a shop must not see gym inquiries. Mirrors
                // the row gating of the Guest Services widget on the dashboard.
                $payload['title'] = ($mod_bookings ? 'Guest' : 'Customer') . ' Services Queue';
                $payload['subtitle'] = $mod_bookings
                    ? 'Front-desk and guest communication workloads'
                    : 'Customer communication workloads';
                $payload['columns'] = [
                    ['key' => 'queue', 'label' => 'Queue'],
                    ['key' => 'count', 'label' => 'Open'],
                    ['key' => 'priority', 'label' => 'Priority'],
                    ['key' => 'detail', 'label' => 'Detail'],
                ];
                $payload['link'] = $mod_website_cms
                    ? ['href' => 'reviews.php?status=pending', 'label' => 'Open guest services pages']
                    : ($mod_gym
                        ? ['href' => 'gym-inquiries.php', 'label' => 'Open gym inquiries']
                        : ['href' => 'bookings.php', 'label' => 'Open bookings']);

                if ($mod_website_cms) {
                    $pendingReviews = (int)$pdo->query("SELECT COUNT(*) FROM reviews WHERE status = 'pending'")->fetchColumn();
                    $unreadContact = (int)$pdo->query("SELECT COUNT(*) FROM contact_inquiries WHERE status = 'new'")->fetchColumn();
                    $payload['rows'][] = [
                        'queue' => 'Reviews awaiting moderation',
                        'count' => (string)$pendingReviews,
                        'priority' => $pendingReviews > 0 ? 'Medium' : 'Low',
                        'detail' => ($mod_bookings ? 'Guest' : 'Customer') . ' feedback waiting publication decision',
                        'action' => ['href' => 'reviews.php?status=pending', 'label' => 'Reviews', 'target' => '_blank'],
                    ];
                    $payload['rows'][] = [
                        'queue' => 'Unread contact inquiries',
                        'count' => (string)$unreadContact,
                        'priority' => $unreadContact > 0 ? 'High' : 'Low',
                        'detail' => 'Website contact messages awaiting first response',
                        'action' => ['href' => 'contact-inquiries.php', 'label' => 'Contacts', 'target' => '_blank'],
                    ];
                }
                if ($mod_gym) {
                    $pendingGym = (int)$pdo->query("SELECT COUNT(*) FROM gym_inquiries WHERE status IN ('pending', 'new')")->fetchColumn();
                    $payload['rows'][] = [
                        'queue' => 'Gym inquiries pending',
                        'count' => (string)$pendingGym,
                        'priority' => $pendingGym > 0 ? 'Medium' : 'Low',
                        'detail' => 'Membership/wellness requests not yet closed',
                        'action' => ['href' => 'gym-inquiries.php', 'label' => 'Gym', 'target' => '_blank'],
                    ];
                }
                if ($mod_website_cms && $mod_events) {
                    try {
                        $pendingEventsQueue = (int)$pdo->query("SELECT COUNT(*) FROM event_inquiries WHERE status = 'pending'")->fetchColumn();
                        $payload['rows'][] = [
                            'queue' => 'Event bookings pending',
                            'count' => (string)$pendingEventsQueue,
                            'priority' => $pendingEventsQueue > 0 ? 'Medium' : 'Low',
                            'detail' => 'Event inquiries awaiting confirmation',
                            'action' => ['href' => 'events-inquiries.php', 'label' => 'Events', 'target' => '_blank'],
                        ];
                    } catch (Throwable $e) { /* events table may not exist yet */ }
                }
                if ($mod_bookings) {
                    $todayCheckinsStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE check_in_date = ? AND status IN ('confirmed', 'pending')");
                    $todayCheckinsStmt->execute([$today]);
                    $todayCheckins = (int)$todayCheckinsStmt->fetchColumn();
                    $inHouseGuests = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'checked-in'")->fetchColumn();
                    $payload['rows'][] = [
                        'queue' => "Today's check-ins",
                        'count' => (string)$todayCheckins,
                        'priority' => $todayCheckins > 0 ? 'High' : 'Low',
                        'detail' => 'Arrivals that need front-desk readiness',
                        'action' => ['href' => 'bookings.php?filter=checkin_today', 'label' => 'Arrivals', 'target' => '_blank'],
                    ];
                    $payload['rows'][] = [
                        'queue' => 'In-house guests',
                        'count' => (string)$inHouseGuests,
                        'priority' => 'Monitor',
                        'detail' => 'Current occupied stays requiring guest support',
                        'action' => ['href' => 'bookings.php?status=checked-in', 'label' => 'In-house', 'target' => '_blank'],
                    ];
                }
                $payload['empty'] = 'No customer-services queues are active right now.';
                break;

            case 'operations_facilities':
                // Each metric only appears when its module is on — a gym or shop
                // must never see hotel rows (maintenance, housekeeping, room
                // service). Mirrors the Operations & Facilities widget gating.
                $payload['title'] = 'Operations & Facilities';
                $payload['subtitle'] = $mod_housekeeping
                    ? 'Maintenance, housekeeping, service and payment pressure points'
                    : 'Service and payment pressure points';
                $payload['columns'] = [
                    ['key' => 'metric', 'label' => 'Metric'],
                    ['key' => 'current', 'label' => 'Current'],
                    ['key' => 'detail', 'label' => 'Detail'],
                ];
                $payload['link'] = $mod_housekeeping
                    ? ['href' => 'room-maintenance.php', 'label' => 'Open operations tools']
                    : ($mod_stock
                        ? ['href' => 'stock-orders.php', 'label' => 'Open orders']
                        : ['href' => 'payments.php', 'label' => 'Open payments']);

                if ($mod_housekeeping) {
                    $maintenanceOpen = (int)$pdo->query("SELECT COUNT(*) FROM individual_rooms WHERE status IN ('maintenance', 'out_of_order')")->fetchColumn();
                    $housekeepingDue = (int)$pdo->query("SELECT COUNT(*) FROM housekeeping_assignments WHERE status IN ('pending', 'in_progress') AND (due_date IS NULL OR due_date <= CURDATE())")->fetchColumn();
                    $payload['rows'][] = [
                        'metric' => 'Rooms in maintenance / out of order',
                        'current' => (string)$maintenanceOpen,
                        'detail' => $maintenanceOpen > 0 ? 'Unavailable inventory requiring engineering follow-up' : 'No rooms blocked by maintenance',
                        'action' => ['href' => 'room-maintenance.php', 'label' => 'Maintenance', 'target' => '_blank'],
                    ];
                    $payload['rows'][] = [
                        'metric' => 'Housekeeping due today',
                        'current' => (string)$housekeepingDue,
                        'detail' => 'Assignments in pending/in-progress status due now',
                        'action' => ['href' => 'housekeeping.php', 'label' => 'Housekeeping', 'target' => '_blank'],
                    ];
                }
                if ($mod_pos && $mod_bookings) {
                    $roomServiceOpen = (int)$pdo->query("SELECT COUNT(*) FROM stock_orders WHERE order_type = 'room_service' AND status IN ('placed', 'pending', 'confirmed')")->fetchColumn();
                    $roomServiceReminderPending = (int)$pdo->query("SELECT COUNT(*)
                                        FROM bookings b
                                        INNER JOIN individual_rooms ir ON ir.id = b.individual_room_id
                                        WHERE b.status = 'checked-in'
                                            AND b.individual_room_id IS NOT NULL
                                            AND ir.is_active = 1
                                            AND NOT EXISTS (
                                                        SELECT 1
                                                        FROM stock_orders o
                                                        WHERE o.order_type = 'room_service'
                                                            AND (o.booking_id = b.id OR (o.booking_id IS NULL AND o.individual_room_id = b.individual_room_id))
                                                            AND (o.status IN ('completed', 'paid') OR o.kitchen_status = 'served')
                                                            AND DATE(COALESCE(o.served_at, o.updated_at, o.created_at)) = CURDATE()
                                                )")->fetchColumn();
                    $roomServiceReminderDue = $roomServiceReminderDueNow ? $roomServiceReminderPending : 0;
                    $payload['rows'][] = [
                        'metric' => 'Room-service orders open',
                        'current' => (string)$roomServiceOpen,
                        'detail' => 'Orders awaiting fulfilment or settlement',
                        'action' => ['href' => 'stock-orders.php?type=room_service', 'label' => 'Room Service', 'target' => '_blank'],
                    ];
                    $payload['rows'][] = [
                        'metric' => 'Room-service reminders due',
                        'current' => (string)$roomServiceReminderDue,
                        'detail' => $roomServiceReminderDueNow
                            ? ('Reminder active now - ' . $roomServiceReminderDue . ' occupied room(s) pending today')
                            : ('Reminder starts at ' . $roomServiceReminderTime . ' (' . $roomServiceReminderTimezone . ')'),
                        'action' => ['href' => 'housekeeping.php', 'label' => 'Housekeeping', 'target' => '_blank'],
                    ];
                }
                if ($mod_stock) {
                    $openTabsStmt = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS v FROM stock_orders WHERE status = 'placed'")->fetch(PDO::FETCH_ASSOC);
                    $openTabsCount = (int)($openTabsStmt['c'] ?? 0);
                    $openTabsValue = (float)($openTabsStmt['v'] ?? 0);
                    $payload['rows'][] = [
                        'metric' => isRestaurantEnabled() ? 'Open restaurant tabs' : 'Pending orders',
                        'current' => (string)$openTabsCount,
                        'detail' => $formatMoney($openTabsValue) . ' awaiting payment',
                        'action' => ['href' => 'stock-orders.php?status=placed', 'label' => isRestaurantEnabled() ? 'Open Tabs' : 'Orders', 'target' => '_blank'],
                    ];
                }
                if ($mod_finance && $mod_receivables) {
                    // Same multi-module receivables union as the Outstanding
                    // Balances tile — not just bookings.
                    $outstandingCount = 0;
                    $outstandingValue = 0.0;
                    $ofUnion = [];
                    if ($mod_bookings)   { $ofUnion[] = "SELECT COUNT(*) c, COALESCE(SUM(amount_due),0) v FROM bookings WHERE amount_due > 0 AND status IN ('pending','confirmed','checked-in')"; }
                    if ($mod_conference) { $ofUnion[] = "SELECT COUNT(*) c, COALESCE(SUM(amount_due),0) v FROM conference_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled')"; }
                    if ($mod_gym)        { $ofUnion[] = "SELECT COUNT(*) c, COALESCE(SUM(amount_due),0) v FROM gym_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled','closed')"; }
                    if ($mod_events)     { $ofUnion[] = "SELECT COUNT(*) c, COALESCE(SUM(amount_due),0) v FROM event_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled')"; }
                    foreach ($ofUnion as $ofSql) {
                        try {
                            $ofRow = $pdo->query($ofSql)->fetch(PDO::FETCH_ASSOC);
                            $outstandingCount += (int)($ofRow['c'] ?? 0);
                            $outstandingValue += (float)($ofRow['v'] ?? 0);
                        } catch (Throwable $e) { /* module table may not exist yet */ }
                    }
                    $payload['rows'][] = [
                        'metric' => ($mod_bookings ? 'Bookings' : 'Accounts') . ' with balance due',
                        'current' => (string)$outstandingCount,
                        'detail' => $formatMoney($outstandingValue) . ' still receivable',
                        'action' => ['href' => 'payments.php?balance=outstanding', 'label' => 'Balances', 'target' => '_blank'],
                    ];
                }
                $payload['empty'] = 'No operations or facilities issues are active right now.';
                break;

            case 'room_status_overview':
                $payload['title'] = 'Room Status Overview';
                $payload['subtitle'] = 'Active room inventory by operational state';
                $payload['columns'] = [
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'count', 'label' => 'Rooms'],
                    ['key' => 'share', 'label' => 'Share'],
                    ['key' => 'detail', 'label' => 'Detail'],
                ];
                $payload['link'] = ['href' => 'room-dashboard.php', 'label' => 'Open room dashboard'];

                $statusRows = $pdo->query("SELECT status, COUNT(*) AS c
                                           FROM individual_rooms
                                           WHERE is_active = 1
                                           GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
                $statusTotals = array_map('intval', $statusRows ?: []);
                $totalRooms = array_sum($statusTotals);
                $roomStatusLabels = [
                    'available' => 'Available',
                    'occupied' => 'Occupied',
                    'cleaning' => 'Cleaning',
                    'inspection' => 'Inspection',
                    'maintenance' => 'Maintenance',
                    'out_of_order' => 'Out of Order',
                ];

                foreach ($roomStatusLabels as $statusKey => $statusLabel) {
                    $count = (int)($statusTotals[$statusKey] ?? 0);
                    $share = $totalRooms > 0 ? round(($count / $totalRooms) * 100, 1) . '%' : '0%';
                    $payload['rows'][] = [
                        'status' => $statusLabel,
                        'count' => (string)$count,
                        'share' => $share,
                        'detail' => 'Active rooms in this state',
                        'action' => ['href' => 'individual-rooms.php?status=' . urlencode($statusKey), 'label' => 'Rooms', 'target' => '_blank'],
                    ];
                }
                $payload['empty'] = 'Room status counts are unavailable right now.';
                break;

            default:
                if (str_starts_with($card, 'room_status_')) {
                    $statusKey = trim(substr($card, strlen('room_status_')));
                    $roomStatusLabels = [
                        'available' => 'Available',
                        'occupied' => 'Occupied',
                        'cleaning' => 'Cleaning',
                        'inspection' => 'Inspection',
                        'maintenance' => 'Maintenance',
                        'out_of_order' => 'Out of Order',
                    ];
                    if (!isset($roomStatusLabels[$statusKey])) {
                        throw new RuntimeException('Unknown room status insight card');
                    }

                    $payload['title'] = $roomStatusLabels[$statusKey] . ' Rooms';
                    $payload['subtitle'] = 'Individual room list for this operational state';
                    $payload['columns'] = [
                        ['key' => 'room', 'label' => 'Room'],
                        ['key' => 'type', 'label' => 'Type'],
                        ['key' => 'floor', 'label' => 'Floor'],
                        ['key' => 'housekeeping', 'label' => 'Housekeeping'],
                        ['key' => 'booking', 'label' => 'Current Booking'],
                        ['key' => 'guest', 'label' => 'Guest'],
                    ];
                    $payload['link'] = ['href' => 'individual-rooms.php?status=' . urlencode($statusKey), 'label' => 'Open filtered room list'];

                    $statusStmt = $pdo->prepare("SELECT ir.id AS room_id,
                                                        ir.room_number,
                                                        ir.room_name,
                                                        ir.floor,
                                                        COALESCE(ir.housekeeping_status, '') AS housekeeping_status,
                                                        COALESCE(r.name, '—') AS room_type,
                                                        b.id AS booking_id,
                                                        b.booking_reference,
                                                        b.guest_name
                                                 FROM individual_rooms ir
                                                 LEFT JOIN rooms r ON r.id = ir.room_type_id
                                                                                                 LEFT JOIN bookings b ON b.id = (
                                                                                                        SELECT b1.id
                                                                                                        FROM bookings b1
                                                                                                        WHERE b1.individual_room_id = ir.id
                                                                                                            AND b1.status = 'checked-in'
                                                                                                        ORDER BY b1.check_in_date DESC, b1.id DESC
                                                                                                        LIMIT 1
                                                                                                 )
                                                 WHERE ir.is_active = 1
                                                   AND ir.status = ?
                                                 ORDER BY ir.room_number ASC
                                                 LIMIT 50");
                    $statusStmt->execute([$statusKey]);
                    $rows = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($rows as $row) {
                        $roomNumber = trim((string)($row['room_number'] ?? ''));
                        $roomName = trim((string)($row['room_name'] ?? ''));
                        $roomLabel = $roomNumber !== '' ? $roomNumber : ('Room #' . (int)($row['room_id'] ?? 0));
                        if ($roomName !== '') {
                            $roomLabel .= ' · ' . $roomName;
                        }

                        $bookingId = (int)($row['booking_id'] ?? 0);
                        $rowAction = [
                            'href' => 'individual-rooms.php?status=' . urlencode($statusKey),
                            'label' => 'Rooms',
                            'target' => '_blank',
                        ];
                        if ($bookingId > 0) {
                            $rowAction = [
                                'href' => 'booking-details.php?id=' . $bookingId,
                                'label' => 'Booking',
                                'target' => '_blank',
                            ];
                        }

                        $housekeepingStatus = trim((string)($row['housekeeping_status'] ?? ''));
                        $payload['rows'][] = [
                            'room' => $roomLabel,
                            'type' => (string)($row['room_type'] ?? '—'),
                            'floor' => trim((string)($row['floor'] ?? '')) !== '' ? (string)$row['floor'] : '—',
                            'housekeeping' => $housekeepingStatus !== '' ? $humanizeStatus($housekeepingStatus) : 'N/A',
                            'booking' => trim((string)($row['booking_reference'] ?? '')) !== '' ? (string)$row['booking_reference'] : '—',
                            'guest' => trim((string)($row['guest_name'] ?? '')) !== '' ? (string)$row['guest_name'] : '—',
                            'action' => $rowAction,
                        ];
                    }
                    $payload['empty'] = 'No rooms currently match this status.';
                    break;
                }
                throw new RuntimeException('Unknown insight card');
        }

        $normalizeInsightRows($payload);

        echo json_encode($payload);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Unable to load this card detail right now.',
        ]);
    }
    exit;
}

$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="RH Admin">
    <link rel="manifest" href="manifest.php">
    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="shortcut icon" href="../favicon.ico">
    <title>Dashboard | <?php echo htmlspecialchars($site_name); ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/dashboard.css?v=<?php echo @filemtime(__DIR__ . '/css/dashboard.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <?php if (isset($_GET['error']) && $_GET['error'] === 'access_denied'): ?>
            <div style="background:#fff3e0; border:1px solid #ffe0b2; border-radius:8px; padding:14px 20px; margin-bottom:20px; color:#e65100; display:flex; align-items:center; gap:10px; font-size:14px;">
                <i class="fas fa-exclamation-triangle"></i> You do not have permission to access that page. Contact your administrator to request access.
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'module_disabled'): ?>
            <div style="background:#fff3e0; border:1px solid #ffe0b2; border-radius:8px; padding:14px 20px; margin-bottom:20px; color:#e65100; display:flex; align-items:center; gap:10px; font-size:14px;">
                <i class="fas fa-puzzle-piece"></i> That page belongs to a module that's disabled for this installation. Enable it from Module Settings if you need access.
            </div>
        <?php endif; ?>

        <h2 class="section-title">Dashboard Overview</h2>
        <h3 class="section-title" style="margin-top:6px;"><i class="fas fa-book-open"></i> Guides Menu</h3>
        <div class="guide-menu-grid">
            <a class="guide-menu-btn" href="../docs/guides/index.html" target="_blank" rel="noopener"><i class="fas fa-book-open"></i> All Guides</a>
            <a class="guide-menu-btn" href="../docs/guides/99-admin-dashboard-full-guide.html" target="_blank" rel="noopener"><i class="fas fa-scroll"></i> Admin Bible</a>
            <?php if ($mod_pos): ?><a class="guide-menu-btn" href="../docs/guides/01-pos-till.html" target="_blank" rel="noopener"><i class="fas fa-cash-register"></i> POS Guide</a><?php endif; ?>
            <?php if ($mod_pos && $mod_station_kds): ?><a class="guide-menu-btn" href="../docs/guides/02-kds-kitchen.html" target="_blank" rel="noopener"><i class="fas fa-utensils"></i> KDS Guide</a><?php endif; ?>
            <?php if ($mod_pos && $mod_station_bds): ?><a class="guide-menu-btn" href="../docs/guides/03-bds-bar.html" target="_blank" rel="noopener"><i class="fas fa-cocktail"></i> BDS Guide</a><?php endif; ?>
            <?php if ($mod_pos && $mod_station_cds): ?><a class="guide-menu-btn" href="../docs/guides/04-cds-coffee.html" target="_blank" rel="noopener"><i class="fas fa-mug-hot"></i> CDS Guide</a><?php endif; ?>
            <?php if ($mod_pos && $mod_station_room_service): ?><a class="guide-menu-btn" href="../docs/guides/05-room-service.html" target="_blank" rel="noopener"><i class="fas fa-bell-concierge"></i> Room Service Guide</a><?php endif; ?>
            <?php if ($mod_housekeeping): ?><a class="guide-menu-btn" href="../docs/guides/06-housekeeping.html" target="_blank" rel="noopener"><i class="fas fa-broom"></i> Housekeeping Guide</a><?php endif; ?>
            <?php if ($mod_bookings): ?><a class="guide-menu-btn" href="../docs/guides/07-reception-bookings.html" target="_blank" rel="noopener"><i class="fas fa-calendar-check"></i> Reception Guide</a><?php endif; ?>
            <?php if ($mod_stock): ?><a class="guide-menu-btn" href="../docs/guides/08-stock-orders.html" target="_blank" rel="noopener"><i class="fas fa-boxes"></i> Stock Guide</a><?php endif; ?>
            <a class="guide-menu-btn" href="../docs/guides/13-finance-payments.html" target="_blank" rel="noopener"><i class="fas fa-money-bill-wave"></i> Finance Guide</a>
            <a class="guide-menu-btn" href="../docs/guides/14-reports-eod.html" target="_blank" rel="noopener"><i class="fas fa-chart-bar"></i> Reports Guide</a>
        </div>

        <?php if ($mod_bookings || $mod_conference || $mod_finance || $mod_pos): ?>
        <div class="stats-grid">
            <?php if ($mod_pos && !$mod_bookings): ?>
            <?php /* POS-first businesses (bar, retail, supermarket, gym) — their overview
                     is orders and takings, not check-ins. Hotels keep the booking-centric
                     overview; their POS numbers live in Operations Pulse below. */ ?>
            <a class="stat-card stat-info" href="pos.php" target="_blank" rel="noopener" title="Open the POS till">
                <span class="stat-cta">Open →</span>
                <div class="stat-icon"><i class="fas fa-receipt"></i></div>
                <div class="stat-value"><?php echo (int)$ops['orders_today']; ?></div>
                <div class="stat-label">Orders Today</div>
                <div class="stat-sub">Settled through the POS till</div>
            </a>
            <a class="stat-card stat-good js-dashboard-insight" data-insight-card="restaurant_revenue_today" href="reports.php?type=accounting&range=today" title="Today's POS takings">
                <span class="stat-cta">View →</span>
                <div class="stat-icon"><i class="fas fa-cash-register"></i></div>
                <div class="stat-value"><span class="kpi-currency"><?php echo $currency_symbol; ?></span><?php echo number_format($ops['restaurant_rev_today'], 2); ?></div>
                <div class="stat-label"><?php echo isRestaurantEnabled() ? 'Restaurant Revenue Today' : 'POS Revenue Today'; ?></div>
                <div class="stat-sub">Gross takings settled today</div>
            </a>
            <?php /* "Placed order awaiting payment" is only a real workflow where the
                     order pipeline exists (stock module: restaurant tabs, retail/
                     supermarket held orders). A gym snack till (pos on, stock off)
                     settles at the point of sale, so this card is not relevant there. */ ?>
            <?php if ($mod_stock): ?>
            <a class="stat-card <?php echo $ops['open_tabs'] > 0 ? 'stat-warn' : ''; ?> js-dashboard-insight" data-insight-card="open_tabs" href="stock-orders.php?status=placed" title="<?php echo isRestaurantEnabled() ? 'Open tabs awaiting payment' : 'Placed orders awaiting payment'; ?>">
                <span class="stat-cta">Action →</span>
                <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                <div class="stat-value"><?php echo (int)$ops['open_tabs']; ?></div>
                <div class="stat-label"><?php echo isRestaurantEnabled() ? 'Open Tabs' : 'Pending Orders'; ?></div>
                <div class="stat-sub"><span class="kpi-currency"><?php echo $currency_symbol; ?></span><?php echo number_format($ops['open_tabs_value'], 2); ?> outstanding</div>
            </a>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($mod_gym && !$mod_bookings): ?>
            <?php /* Gym-first businesses — membership register front and centre. */ ?>
            <a class="stat-card stat-good" href="gym-members.php" title="Open the membership register">
                <span class="stat-cta">View →</span>
                <div class="stat-icon"><i class="fas fa-id-card"></i></div>
                <div class="stat-value"><?php echo (int)$gymDash['active_members']; ?></div>
                <div class="stat-label">Active Members</div>
                <div class="stat-sub">Currently enrolled memberships</div>
            </a>
            <a class="stat-card <?php echo $gymDash['expiring_members'] > 0 ? 'stat-warn' : ''; ?>" href="gym-members.php?filter=expiring" title="Memberships expiring within 30 days">
                <span class="stat-cta">Action →</span>
                <div class="stat-icon"><i class="fas fa-hourglass-end"></i></div>
                <div class="stat-value"><?php echo (int)$gymDash['expiring_members']; ?></div>
                <div class="stat-label">Expiring Soon</div>
                <div class="stat-sub">Renewals due in the next 30 days</div>
            </a>
            <a class="stat-card <?php echo $guestSvc['pending_gym'] > 0 ? 'stat-warn' : 'stat-info'; ?>" href="gym-inquiries.php" title="New membership inquiries awaiting reply">
                <span class="stat-cta">Reply →</span>
                <div class="stat-icon"><i class="fas fa-inbox"></i></div>
                <div class="stat-value"><?php echo (int)$guestSvc['pending_gym']; ?></div>
                <div class="stat-label">New Inquiries</div>
                <div class="stat-sub">Prospects waiting to hear back</div>
            </a>
            <?php endif; ?>
            <?php if ($mod_bookings): ?>
            <a class="stat-card stat-info js-dashboard-insight" data-insight-card="checkins_today" href="bookings.php?filter=checkin_today" title="View today's check-ins">
                <span class="stat-cta">View →</span>
                <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-value"><?php echo $today_checkins; ?></div>
                <div class="stat-label">Today's Check-ins</div>
                <div class="stat-sub">Confirmed + pending arrivals</div>
            </a>

            <a class="stat-card stat-info js-dashboard-insight" data-insight-card="checkouts_today" href="bookings.php?filter=checkout_today" title="View today's check-outs">
                <span class="stat-cta">View →</span>
                <div class="stat-icon"><i class="fas fa-calendar-times"></i></div>
                <div class="stat-value"><?php echo $today_checkouts; ?></div>
                <div class="stat-label">Today's Check-outs</div>
                <div class="stat-sub">Currently checked-in guests due out</div>
            </a>

            <a class="stat-card <?php echo $pending_bookings > 0 ? 'stat-warn' : ''; ?> js-dashboard-insight" data-insight-card="pending_bookings" href="bookings.php?status=pending" title="Manage pending bookings">
                <span class="stat-cta">Action →</span>
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-value"><?php echo $pending_bookings; ?></div>
                <div class="stat-label">Pending Bookings</div>
                <div class="stat-sub">Awaiting confirmation</div>
            </a>

            <a class="stat-card stat-good js-dashboard-insight" data-insight-card="inhouse_guests" href="bookings.php?status=checked-in" title="View in-house guests">
                <span class="stat-cta">View →</span>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?php echo $current_guests; ?></div>
                <div class="stat-label">In-House Guests</div>
                <div class="stat-sub">Currently checked in</div>
            </a>

            <a class="stat-card <?php echo $expired_bookings > 0 ? 'stat-warn' : ''; ?> js-dashboard-insight" data-insight-card="expired_bookings" href="bookings.php?status=expired" title="Bookings that expired in the last 24h">
                <span class="stat-cta">Review →</span>
                <div class="stat-icon"><i class="fas fa-hourglass-end"></i></div>
                <div class="stat-value"><?php echo $expired_bookings; ?></div>
                <div class="stat-label">Expired (24h)</div>
                <div class="stat-sub">Unpaid holds released</div>
            </a>
            <?php endif; ?>

            <?php if ($mod_conference): ?>
            <a class="stat-card <?php echo $pending_conference > 0 ? 'stat-warn' : ''; ?> js-dashboard-insight" data-insight-card="pending_conference" href="conference-management.php?status=pending" title="Conference enquiries needing reply">
                <span class="stat-cta">Action →</span>
                <div class="stat-icon"><i class="fas fa-users-cog"></i></div>
                <div class="stat-value"><?php echo $pending_conference; ?></div>
                <div class="stat-label">Pending Conference Enquiries</div>
                <div class="stat-sub">Awaiting quote / response</div>
            </a>

            <a class="stat-card stat-info js-dashboard-insight" data-insight-card="today_conferences" href="conference-management.php?event_date=<?php echo $today; ?>" title="Today's conference events">
                <span class="stat-cta">View →</span>
                <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                <div class="stat-value"><?php echo $today_conferences; ?></div>
                <div class="stat-label">Today's Conference Events</div>
                <div class="stat-sub">Confirmed + pending</div>
            </a>
            <?php endif; ?>

            <?php if ($mod_gym && $mod_bookings): ?>
            <?php /* Booking-led presets that also run a gym (Full Hotel, Hotel + gym):
                     the gym hero block above is booking-less only, so surface the key
                     membership numbers here too. New inquiries already appear in the
                     Guest Services Queue, so this stays to the two register metrics. */ ?>
            <a class="stat-card stat-good" href="gym-members.php" title="Open the membership register">
                <span class="stat-cta">View →</span>
                <div class="stat-icon"><i class="fas fa-id-card"></i></div>
                <div class="stat-value"><?php echo (int)$gymDash['active_members']; ?></div>
                <div class="stat-label">Active Gym Members</div>
                <div class="stat-sub">Currently enrolled memberships</div>
            </a>
            <a class="stat-card <?php echo $gymDash['expiring_members'] > 0 ? 'stat-warn' : ''; ?>" href="gym-members.php?filter=expiring" title="Gym memberships expiring within 30 days">
                <span class="stat-cta">Action →</span>
                <div class="stat-icon"><i class="fas fa-hourglass-end"></i></div>
                <div class="stat-value"><?php echo (int)$gymDash['expiring_members']; ?></div>
                <div class="stat-label">Gym Memberships Expiring</div>
                <div class="stat-sub">Renewals due in the next 30 days</div>
            </a>
            <?php endif; ?>

            <?php if ($mod_finance && $mod_receivables): ?>
            <a class="stat-card <?php echo $finance['outstanding'] > 0 ? 'stat-alert' : 'stat-good'; ?> js-dashboard-insight" data-insight-card="outstanding_balances" href="payments.php?balance=outstanding" title="View accounts with outstanding balances">
                <span class="stat-cta">Collect →</span>
                <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-value">
                    <span class="stat-money">
                        <span class="stat-money__currency"><?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="stat-money__amount"><?php echo number_format((float)$finance['outstanding'], 2); ?></span>
                    </span>
                </div>
                <div class="stat-label">Outstanding Balances</div>
                <div class="stat-sub"><?php echo $finance['outstanding_count']; ?> <?php echo $mod_bookings ? 'booking(s)' : 'account(s)'; ?> with amount due</div>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($mod_pos || $mod_finance): ?>
        <!-- Operations Pulse: real-time restaurant / room-service / KDS pipeline -->
        <h3 class="section-title" style="margin-top:6px;"><i class="fas fa-bolt"></i> Operations Pulse</h3>
        <div class="ops-grid">
            <?php if ($mod_stock): ?>
            <a class="ops-card js-dashboard-insight" data-insight-card="open_tabs" href="stock-orders.php?status=placed" title="<?php echo isRestaurantEnabled() ? 'Open restaurant tabs awaiting payment' : 'Placed orders awaiting payment'; ?>">
                <div class="ops-icon" style="background:#e67e22;"><i class="fas fa-receipt"></i></div>
                <div class="ops-body">
                    <div class="ops-value"><?php echo $ops['open_tabs']; ?></div>
                    <div class="ops-label"><?php echo isRestaurantEnabled() ? 'Open Tabs' : 'Pending Orders'; ?></div>
                    <div class="ops-sub"><?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($ops['open_tabs_value'], 2); ?> outstanding</div>
                </div>
            </a>
            <?php endif; // mod_stock — open tabs ?>

            <?php if ($mod_pos && $mod_bookings): ?>
            <a class="ops-card js-dashboard-insight" data-insight-card="room_service_pending" href="stock-orders.php?type=room_service" title="Room-service orders in flight">
                <div class="ops-icon" style="background:#8e44ad;"><i class="fas fa-concierge-bell"></i></div>
                <div class="ops-body">
                    <div class="ops-value"><?php echo $ops['room_service_pending']; ?></div>
                    <div class="ops-label">Room Service Pending</div>
                    <div class="ops-sub">Active room-folio orders</div>
                </div>
            </a>
            <a class="ops-card js-dashboard-insight" data-insight-card="room_service_reminders_due" href="housekeeping.php" title="Daily room-service reminders for occupied rooms">
                <div class="ops-icon" style="background:<?php echo $ops['room_service_reminders_due'] > 0 ? '#c62828' : '#455a64'; ?>;"><i class="fas fa-bell"></i></div>
                <div class="ops-body">
                    <div class="ops-value"><?php echo $ops['room_service_reminders_due']; ?></div>
                    <div class="ops-label">Room-Service Reminders Due</div>
                    <div class="ops-sub">
                        <?php if ($roomServiceReminderDueNow): ?>
                            <?php echo $ops['room_service_reminders_due'] > 0 ? 'Follow-up needed now' : 'All occupied rooms served today'; ?>
                        <?php else: ?>
                            Opens at <?php echo htmlspecialchars($roomServiceReminderTime, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($roomServiceReminderTimezone, ENT_QUOTES, 'UTF-8'); ?>)
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($mod_pos && $mod_station_kds): ?>
            <a class="ops-card js-dashboard-insight" data-insight-card="kitchen_tickets" href="kds.php" target="_blank" rel="noopener" title="Open Kitchen Display System">
                <div class="ops-icon" style="background:#dc3545;"><i class="fas fa-utensils"></i></div>
                <div class="ops-body">
                    <div class="ops-value"><?php echo $ops['kds_kitchen_pending']; ?></div>
                    <div class="ops-label">Kitchen Tickets</div>
                    <div class="ops-sub">Active service-window tickets</div>
                </div>
            </a>
            <?php endif; ?>
            <?php if ($mod_pos && $mod_station_bds): ?>
            <a class="ops-card js-dashboard-insight" data-insight-card="bar_tickets" href="bds.php" target="_blank" rel="noopener" title="Open Bar Display System">
                <div class="ops-icon" style="background:#6f42c1;"><i class="fas fa-cocktail"></i></div>
                <div class="ops-body">
                    <div class="ops-value"><?php echo $ops['kds_bar_pending']; ?></div>
                    <div class="ops-label">Bar Tickets</div>
                    <div class="ops-sub">Active service-window tickets</div>
                </div>
            </a>
            <?php endif; ?>
            <?php if ($mod_pos && $mod_station_cds): ?>
            <a class="ops-card js-dashboard-insight" data-insight-card="coffee_tickets" href="cds.php" target="_blank" rel="noopener" title="Open Coffee Display System">
                <div class="ops-icon" style="background:#8B5A2B;"><i class="fas fa-mug-hot"></i></div>
                <div class="ops-body">
                    <div class="ops-value"><?php echo $ops['kds_coffee_pending']; ?></div>
                    <div class="ops-label">Coffee Tickets</div>
                    <div class="ops-sub">Active service-window tickets</div>
                </div>
            </a>
            <?php endif; ?>
            <?php if ($mod_pos): ?>
            <a class="ops-card js-dashboard-insight" data-insight-card="restaurant_revenue_today" href="reports.php?type=accounting&range=today" title="<?php echo isRestaurantEnabled() ? "Today's restaurant revenue" : "Today's POS revenue"; ?>">
                <div class="ops-icon" style="background:#16a085;"><i class="fas fa-cash-register"></i></div>
                <div class="ops-body">
                    <div class="ops-value"><?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($ops['restaurant_rev_today'], 2); ?></div>
                    <div class="ops-label"><?php echo isRestaurantEnabled() ? 'Restaurant Revenue Today' : 'POS Revenue Today'; ?></div>
                    <div class="ops-sub"><?php echo $ops['orders_today']; ?> order(s) settled</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($mod_finance): ?>
            <a class="ops-card js-dashboard-insight" data-insight-card="total_revenue_today" href="payments.php?date=<?php echo $today; ?>" title="Payments captured today">
                <div class="ops-icon" style="background:#2e7d32;"><i class="fas fa-credit-card"></i></div>
                <div class="ops-body">
                    <div class="ops-value"><?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($finance['revenue_today'], 2); ?></div>
                    <div class="ops-label">Total Revenue Today</div>
                    <div class="ops-sub"><?php echo $finance['payments_today']; ?> payment(s)<?php echo $mod_pos ? (isRestaurantEnabled() ? ' + restaurant' : ' + POS') : ''; ?></div>
                </div>
            </a>
            <a class="ops-card js-dashboard-insight" data-insight-card="refunds_pending" href="payments.php?refund_status=pending" title="Refunds in queue">
                <div class="ops-icon" style="background:#c62828;"><i class="fas fa-undo-alt"></i></div>
                <div class="ops-body">
                    <div class="ops-value"><?php echo $finance['refunds_pending']; ?></div>
                    <div class="ops-label">Refunds Pending</div>
                    <div class="ops-sub">Need approval / processing</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($mod_stock || $mod_website_cms || $mod_gym || $mod_bookings || $mod_housekeeping || $mod_pos || $mod_finance): ?>
        <!-- Three-up widget strip: Stock Health · Guest Services · Operations & Facilities -->
        <div class="widget-strip">
            <?php if ($mod_stock): ?>
            <!-- Stock Health -->
            <div class="widget-card">
                <h4>
                    <span><i class="fas fa-warehouse"></i> Stock Health</span>
                    <span class="widget-card__heading-actions">
                        <button type="button" class="btn btn-outline dashboard-widget-insight-trigger js-dashboard-insight" data-insight-card="stock_health" title="Open stock health overview">
                            Overview
                        </button>
                        <a href="stock-orders.php?view=stock">Manage stock →</a>
                    </span>
                </h4>
                <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:10px;">
                    <div style="text-align:center; padding:8px; background:<?php echo $stock['low_stock'] > 0 ? '#fff3e0' : '#f8f9fa'; ?>; border-radius:6px;">
                        <div style="font-size:20px; font-weight:700; color:<?php echo $stock['low_stock'] > 0 ? '#e65100' : '#222'; ?>;"><?php echo $stock['low_stock']; ?></div>
                        <div style="font-size:10px; color:#666;">LOW STOCK</div>
                    </div>
                    <div style="text-align:center; padding:8px; background:<?php echo $stock['expiring_batches'] > 0 ? '#fff8e1' : '#f8f9fa'; ?>; border-radius:6px;">
                        <div style="font-size:20px; font-weight:700; color:<?php echo $stock['expiring_batches'] > 0 ? '#b45309' : '#222'; ?>;"><?php echo $stock['expiring_batches']; ?></div>
                        <div style="font-size:10px; color:#666;">EXPIRING ≤7d</div>
                    </div>
                    <div style="text-align:center; padding:8px; background:<?php echo $stock['expired_batches'] > 0 ? '#ffebee' : '#f8f9fa'; ?>; border-radius:6px;">
                        <div style="font-size:20px; font-weight:700; color:<?php echo $stock['expired_batches'] > 0 ? '#c62828' : '#222'; ?>;"><?php echo $stock['expired_batches']; ?></div>
                        <div style="font-size:10px; color:#666;">EXPIRED</div>
                    </div>
                </div>
                <?php if (!empty($stock['low_items'])): ?>
                    <div style="font-size:11px; color:#666; margin-bottom:4px; font-weight:600;">Lowest items:</div>
                    <ul class="widget-list">
                        <?php foreach (array_slice($stock['low_items'], 0, 5) as $li): ?>
                            <li>
                                <span class="pri"><?php echo htmlspecialchars($li['name']); ?></span>
                                <span class="meta"><?php echo number_format((float)$li['current_quantity'], 1); ?> / <?php echo number_format((float)$li['min_quantity'], 1); ?> <?php echo htmlspecialchars($li['unit']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="font-size:12px; color:#28a745; margin:8px 0 0;"><i class="fas fa-check-circle"></i> All <?php echo isRestaurantEnabled() ? 'ingredients' : 'stock items'; ?> above minimum.</p>
                <?php endif; ?>
                <?php if ($stock['wastage_today'] > 0): ?>
                    <div style="margin-top:10px; padding:6px 10px; background:#fbe9e7; border-radius:6px; font-size:11px; color:#c62828;">
                        <i class="fas fa-trash"></i> Wastage today: <strong><?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($stock['wastage_today'], 2); ?></strong>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; // mod_stock ?>

            <?php if ($mod_website_cms || $mod_gym || $mod_bookings): ?>
            <!-- Guest Services -->
            <div class="widget-card">
                <h4>
                    <span><i class="fas fa-headset"></i> <?php echo $mod_bookings ? 'Guest' : 'Customer'; ?> Services Queue</span>
                    <span class="widget-card__heading-actions">
                        <button type="button" class="btn btn-outline dashboard-widget-insight-trigger js-dashboard-insight" data-insight-card="guest_services_queue" title="Open guest services overview">
                            Overview
                        </button>
                    </span>
                </h4>
                <ul class="widget-list">
                    <?php if ($mod_website_cms): ?>
                    <li>
                        <span class="pri"><i class="fas fa-star" style="color:#f1c40f;"></i> Reviews awaiting moderation</span>
                        <a href="reviews.php?status=pending" style="text-decoration:none;">
                            <span class="pulse-pill <?php echo $guestSvc['pending_reviews'] > 0 ? 'amber' : 'green'; ?>"><?php echo $guestSvc['pending_reviews']; ?></span>
                        </a>
                    </li>
                    <li>
                        <span class="pri"><i class="fas fa-envelope" style="color:#1565c0;"></i> Unread contact inquiries</span>
                        <a href="contact-inquiries.php" style="text-decoration:none;">
                            <span class="pulse-pill <?php echo $guestSvc['unread_contact'] > 0 ? 'red' : 'green'; ?>"><?php echo $guestSvc['unread_contact']; ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($mod_gym): ?>
                    <li>
                        <span class="pri"><i class="fas fa-dumbbell" style="color:#16a085;"></i> Gym inquiries pending</span>
                        <a href="gym-inquiries.php" style="text-decoration:none;">
                            <span class="pulse-pill <?php echo $guestSvc['pending_gym'] > 0 ? 'amber' : 'green'; ?>"><?php echo $guestSvc['pending_gym']; ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($mod_website_cms && $mod_events): ?>
                    <li>
                        <span class="pri"><i class="fas fa-calendar-check" style="color:#5e35b1;"></i> Event bookings pending</span>
                        <a href="events-inquiries.php" style="text-decoration:none;">
                            <span class="pulse-pill <?php echo $guestSvc['pending_events'] > 0 ? 'amber' : 'green'; ?>"><?php echo $guestSvc['pending_events']; ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($mod_bookings): ?>
                    <li>
                        <span class="pri"><i class="fas fa-calendar-day"></i> Today's check-ins</span>
                        <a href="bookings.php?filter=checkin_today" style="text-decoration:none;">
                            <span class="pulse-pill green"><?php echo $today_checkins; ?></span>
                        </a>
                    </li>
                    <li>
                        <span class="pri"><i class="fas fa-bed"></i> In-house guests</span>
                        <a href="bookings.php?status=checked-in" style="text-decoration:none;">
                            <span class="pulse-pill green"><?php echo $current_guests; ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ($mod_housekeeping || $mod_pos || $mod_finance): ?>
            <!-- Operations & Facilities -->
            <div class="widget-card">
                <h4>
                    <span><i class="fas fa-tools"></i> Operations & Facilities</span>
                    <span class="widget-card__heading-actions">
                        <button type="button" class="btn btn-outline dashboard-widget-insight-trigger js-dashboard-insight" data-insight-card="operations_facilities" title="Open operations and facilities overview">
                            Overview
                        </button>
                    </span>
                </h4>
                <ul class="widget-list">
                    <?php if ($mod_housekeeping): ?>
                    <li>
                        <span class="pri"><i class="fas fa-wrench" style="color:#fd7e14;"></i> Rooms in maintenance / OOO</span>
                        <a href="room-maintenance.php" style="text-decoration:none;">
                            <span class="pulse-pill <?php echo $guestSvc['maintenance_open'] > 0 ? 'red' : 'green'; ?>"><?php echo $guestSvc['maintenance_open']; ?></span>
                        </a>
                    </li>
                    <li>
                        <span class="pri"><i class="fas fa-broom" style="color:#17a2b8;"></i> Housekeeping due today</span>
                        <a href="housekeeping.php" style="text-decoration:none;">
                            <span class="pulse-pill <?php echo $guestSvc['housekeeping_due'] > 0 ? 'amber' : 'green'; ?>"><?php echo $guestSvc['housekeeping_due']; ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($mod_pos && $mod_bookings): ?>
                    <li>
                        <span class="pri"><i class="fas fa-concierge-bell" style="color:#8e44ad;"></i> Room-service orders open</span>
                        <a href="stock-orders.php?type=room_service" style="text-decoration:none;">
                            <span class="pulse-pill <?php echo $ops['room_service_pending'] > 0 ? 'amber' : 'green'; ?>"><?php echo $ops['room_service_pending']; ?></span>
                        </a>
                    </li>
                    <li>
                        <span class="pri"><i class="fas fa-bell" style="color:#455a64;"></i> Room-service reminders due</span>
                        <button type="button"
                            class="pulse-pill <?php echo $roomServiceReminderDueNow ? ($ops['room_service_reminders_due'] > 0 ? 'red' : 'green') : 'amber'; ?> js-dashboard-insight"
                            data-insight-card="room_service_reminders_due"
                            style="border:0; cursor:pointer;">
                            <?php echo $ops['room_service_reminders_due']; ?>
                        </button>
                    </li>
                    <?php endif; ?>
                    <?php if ($mod_stock): ?>
                    <li>
                        <span class="pri"><i class="fas fa-receipt" style="color:#e67e22;"></i> <?php echo isRestaurantEnabled() ? 'Open restaurant tabs' : 'Pending orders'; ?></span>
                        <a href="stock-orders.php?status=placed" style="text-decoration:none;">
                            <span class="pulse-pill <?php echo $ops['open_tabs'] > 0 ? 'amber' : 'green'; ?>"><?php echo $ops['open_tabs']; ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($mod_finance && $mod_receivables): ?>
                    <li>
                        <span class="pri"><i class="fas fa-money-check-alt" style="color:#dc3545;"></i> <?php echo $mod_bookings ? 'Bookings' : 'Accounts'; ?> with balance due</span>
                        <a href="payments.php?balance=outstanding" style="text-decoration:none;">
                            <span class="pulse-pill <?php echo $finance['outstanding_count'] > 0 ? 'red' : 'green'; ?>"><?php echo $finance['outstanding_count']; ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ===================================================================
             System Health Monitor — polls /admin/api/system-health.php
             ================================================================ -->
        <h3 class="section-title" style="margin-top:6px;"><i class="fas fa-heartbeat" style="color:#dc3545;"></i> System Health</h3>
        <div class="ops-grid" id="sysHealthGrid" style="margin-bottom:6px;">
            <!-- Database -->
            <div class="ops-card" style="cursor:default;">
                <div class="ops-icon" id="shcDbIcon" style="background:#aaa;"><i class="fas fa-database"></i></div>
                <div class="ops-body">
                    <div class="ops-value" id="shcDbValue"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="ops-label">Database</div>
                    <div class="ops-sub" id="shcDbMeta">Checking…</div>
                </div>
            </div>
            <!-- Last Backup -->
            <div class="ops-card" style="cursor:default;">
                <div class="ops-icon" id="shcBackupIcon" style="background:#aaa;"><i class="fas fa-cloud-download-alt"></i></div>
                <div class="ops-body">
                    <div class="ops-value" id="shcBackupValue" style="font-size:18px;"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="ops-label">Last Backup</div>
                    <div class="ops-sub" id="shcBackupMeta">Checking…</div>
                </div>
            </div>
            <!-- Disk Space -->
            <div class="ops-card" style="cursor:default;">
                <div class="ops-icon" id="shcDiskIcon" style="background:#aaa;"><i class="fas fa-hdd"></i></div>
                <div class="ops-body">
                    <div class="ops-value" id="shcDiskValue" style="font-size:18px;"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="ops-label">Disk Space Free</div>
                    <div class="ops-sub" id="shcDiskMeta">Checking…</div>
                </div>
            </div>
            <!-- Error Log -->
            <div class="ops-card" style="cursor:default;">
                <div class="ops-icon" id="shcLogIcon" style="background:#aaa;"><i class="fas fa-file-medical-alt"></i></div>
                <div class="ops-body">
                    <div class="ops-value" id="shcLogValue" style="font-size:18px;"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="ops-label">Error Log Size</div>
                    <div class="ops-sub" id="shcLogMeta">Checking…</div>
                </div>
            </div>
            <!-- PHP Version -->
            <div class="ops-card" style="cursor:default;">
                <div class="ops-icon" style="background:#8892bf;"><i class="fab fa-php"></i></div>
                <div class="ops-body">
                    <div class="ops-value" id="shcPhpValue" style="font-size:16px;"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="ops-label">PHP Version</div>
                    <div class="ops-sub" id="shcPhpMeta">Checking…</div>
                </div>
            </div>
            <?php if ($mod_bookings): ?>
            <!-- Tentative Booking Sweep — booking-expiry housekeeping, only meaningful with the bookings module -->
            <div class="ops-card" style="cursor:default;">
                <div class="ops-icon" id="shcSweepIcon" style="background:#aaa;"><i class="fas fa-broom"></i></div>
                <div class="ops-body">
                    <div class="ops-value" id="shcSweepValue" style="font-size:18px;"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="ops-label">Tentative Sweep</div>
                    <div class="ops-sub" id="shcSweepMeta">Checking…</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; font-size:11px; color:#888; margin-bottom:20px; padding:0 2px 0 4px;">
            <span>Auto-refreshes every 60 s &nbsp;&middot;&nbsp; Last checked: <strong id="shcLastChecked">—</strong></span>
            <div style="display:flex; gap:6px;">
                <a href="backup-management.php" class="btn btn-outline" style="font-size:11px; padding:4px 12px;"><i class="fas fa-archive"></i> Manage Backups</a>
                <a href="system-logs.php" class="btn btn-outline" style="font-size:11px; padding:4px 12px;"><i class="fas fa-list-alt"></i> System Logs</a>
                <button type="button" id="shcRefreshBtn" class="btn btn-outline" style="font-size:11px; padding:4px 12px;"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
        </div>

        <?php if ($mod_pos && $mod_bookings && !empty($roomServiceQueue)): ?>
            <!-- Room-service queue: live oldest-first list of in-flight room orders -->
            <div class="today-checkins-section">
                <h3>
                    <i class="fas fa-concierge-bell"></i> Room Service Queue (<?php echo count($roomServiceQueue); ?>)
                    <a href="stock-orders.php?type=room_service" class="btn btn-sm btn-outline" style="float:right;">All room orders →</a>
                </h3>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Order Ref</th>
                                <th>Room</th>
                                <th>Guest</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Age</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roomServiceQueue as $rs):
                                $age = (int)$rs['age_min'];
                                $ageColor = $age >= 30 ? '#c62828' : ($age >= 15 ? '#b45309' : '#2e7d32');
                            ?>
                                <tr>
                                    <td data-label="Order Ref"><strong><?php echo htmlspecialchars($rs['reference']); ?></strong></td>
                                    <td data-label="Room"><?php echo htmlspecialchars($rs['room_number'] ?? '—'); ?></td>
                                    <td data-label="Guest"><?php echo htmlspecialchars($rs['customer_name'] ?? '—'); ?></td>
                                    <td data-label="Items"><?php echo (int)$rs['item_count']; ?></td>
                                    <td data-label="Total"><?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format((float)$rs['total_amount'], 2); ?></td>
                                    <td data-label="Age" style="color:<?php echo $ageColor; ?>; font-weight:600;"><?php echo rh_format_age($age); ?></td>
                                    <td data-label="Status"><span class="badge badge-<?php echo htmlspecialchars($rs['status']); ?>"><?php echo ucfirst($rs['status']); ?></span></td>
                                    <td data-label="Actions">
                                        <a href="stock-orders.php?id=<?php echo (int)$rs['id']; ?>" class="btn btn-primary btn-sm">View</a>
                                        <a href="pos.php?settle=<?php echo (int)$rs['id']; ?>" target="_blank" rel="noopener" class="btn btn-success btn-sm">Take Payment</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($mod_stock && !empty($stock['expiring_items'])): ?>
            <!-- Stock alerts: batches expiring within 7 days -->
            <div class="today-checkins-section">
                <h3>
                    <i class="fas fa-exclamation-triangle" style="color:#b45309;"></i> Batches Expiring Within 7 Days (<?php echo count($stock['expiring_items']); ?>)
                    <a href="stock-orders.php?view=batches" class="btn btn-sm btn-outline" style="float:right;">All batches →</a>
                </h3>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <th>Batch</th>
                                <th>Remaining</th>
                                <th>Expiry</th>
                                <th>Days Left</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stock['expiring_items'] as $b):
                                $daysLeft = (int)((strtotime($b['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
                                $color = $daysLeft < 0 ? '#c62828' : ($daysLeft <= 2 ? '#e65100' : '#b45309');
                            ?>
                                <tr>
                                    <td data-label="Ingredient"><?php echo htmlspecialchars($b['name']); ?></td>
                                    <td data-label="Batch"><?php echo htmlspecialchars($b['batch_number'] ?? '—'); ?></td>
                                    <td data-label="Remaining"><?php echo number_format((float)$b['quantity_remaining'], 1) . ' ' . htmlspecialchars($b['unit']); ?></td>
                                    <td data-label="Expiry"><?php echo date('M j, Y', strtotime($b['expiry_date'])); ?></td>
                                    <td data-label="Days Left" style="color:<?php echo $color; ?>; font-weight:600;">
                                        <?php echo $daysLeft < 0 ? abs($daysLeft) . ' days OVERDUE' : $daysLeft . ' days'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>


        <?php if ($mod_bookings || $mod_housekeeping): ?>
        <!-- Room Status Widget -->
        <?php
        $roomSummary = getRoomDashboardSummary();
        $roomStatuses = getRoomStatuses();
        ?>
        <div class="room-status-widget">
            <div class="widget-header">
                <h3><i class="fas fa-door-open"></i> Room Status Overview</h3>
                <div class="room-status-widget__actions">
                    <button type="button" class="btn btn-outline dashboard-widget-insight-trigger js-dashboard-insight" data-insight-card="room_status_overview" title="Open room status summary">
                        Overview
                    </button>
                    <a href="room-dashboard.php" class="btn btn-sm btn-outline">View Details <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
            <div class="widget-content">
                <div class="occupancy-overview">
                    <div class="occupancy-percentage">
                        <span class="value"><?php echo $roomSummary['occupancy_rate'] ?? 0; ?>%</span>
                        <span class="label">Occupancy</span>
                    </div>
                    <div class="occupancy-bar-mini">
                        <?php
                        $total = array_sum($roomSummary['status_counts'] ?? []);
                        if ($total > 0):
                            $colors = [
                                'occupied' => '#dc3545',
                                'available' => '#28a745',
                                'cleaning' => '#ffc107',
                                'inspection' => '#17a2b8',
                                'maintenance' => '#fd7e14',
                                'out_of_order' => '#6c757d'
                            ];
                            foreach ($roomSummary['status_counts'] ?? [] as $status => $count):
                                $percent = ($count / $total) * 100;
                        ?>
                                <div class="bar-segment" style="width: <?php echo $percent; ?>%; background: <?php echo $colors[$status] ?? '#ccc'; ?>;" title="<?php echo ucfirst($status); ?>: <?php echo $count; ?>"></div>
                        <?php
                            endforeach;
                        endif;
                        ?>
                    </div>
                </div>
                <div class="status-cards-mini">
                    <?php foreach ($roomStatuses as $status => $info): ?>
                        <button type="button"
                            class="status-mini <?php echo $status; ?> js-dashboard-insight"
                            data-insight-card="room_status_<?php echo htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8'); ?>"
                            title="View <?php echo htmlspecialchars((string)$info['label'], ENT_QUOTES, 'UTF-8'); ?> rooms overview">
                            <span class="count"><?php echo $roomSummary['status_counts'][$status] ?? 0; ?></span>
                            <span class="label"><?php echo $info['label']; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="quick-actions-row">
                    <button type="button" class="action-item js-dashboard-insight" data-insight-card="room_status_cleaning" title="View rooms queued for cleaning" data-no-spa="1" data-no-admin-loader="1">
                        <span class="action-value"><?php echo $roomSummary['cleaning_queue'] ?? 0; ?></span>
                        <span class="action-label"><i class="fas fa-broom"></i> To Clean</span>
                    </button>
                    <button type="button" class="action-item js-dashboard-insight" data-insight-card="checkins_today" title="View today's check-ins" data-no-spa="1" data-no-admin-loader="1">
                        <span class="action-value"><?php echo $roomSummary['checkins_today'] ?? 0; ?></span>
                        <span class="action-label"><i class="fas fa-sign-in-alt"></i> Check-ins</span>
                    </button>
                    <button type="button" class="action-item js-dashboard-insight" data-insight-card="checkouts_today" title="View today's check-outs" data-no-spa="1" data-no-admin-loader="1">
                        <span class="action-value"><?php echo $roomSummary['checkouts_today'] ?? 0; ?></span>
                        <span class="action-label"><i class="fas fa-sign-out-alt"></i> Check-outs</span>
                    </button>
                    <button type="button" class="action-item js-dashboard-insight" data-insight-card="room_status_occupied" title="View occupied rooms" data-no-spa="1" data-no-admin-loader="1">
                        <span class="action-value"><?php echo (int)($roomSummary['status_counts']['occupied'] ?? 0); ?></span>
                        <span class="action-label"><i class="fas fa-users"></i> Occupied</span>
                    </button>
                </div>
            </div>
        </div>

        <?php endif; // mod_bookings || mod_housekeeping — room status widget ?>

        <?php if ($mod_bookings): ?>
        <!-- Today's Check-ins Management -->
        <div class="today-checkins-section">
            <h3>
                <i class="fas fa-door-open"></i> Today's Check-ins (<?php echo $today_checkins; ?>)
            </h3>

            <?php
            $today_checkin_list = $pdo->prepare("
                SELECT b.*, r.name as room_name,
                       ir.room_number as individual_room_number, ir.room_name as individual_room_name,
                       b.total_amount, b.amount_paid, b.amount_due, b.payment_status
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                WHERE b.check_in_date = ?
                AND b.status IN ('confirmed', 'pending')
                ORDER BY b.created_at ASC
            ");
            $today_checkin_list->execute([$today]);
            $checkin_bookings = $today_checkin_list->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php if (!empty($checkin_bookings)): ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Booking Ref</th>
                                <th>Guest Name</th>
                                <th>Room</th>
                                <th>Check-out</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($checkin_bookings as $booking): ?>
                                <tr id="checkin-row-<?php echo $booking['id']; ?>">
                                    <td data-label="Booking Ref"><strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong></td>
                                    <td data-label="Guest Name"><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                                    <td data-label="Room">
                                        <?php echo htmlspecialchars($booking['room_name']); ?>
                                        <?php if (!empty($booking['individual_room_id'])): ?>
                                            <br><span class="dashboard-room-chip">
                                                <i class="fas fa-door-open"></i>
                                                <?php echo htmlspecialchars($booking['individual_room_name'] ?: $booking['individual_room_number']); ?>
                                            </span>
                                            <?php if ($booking['individual_room_name'] && $booking['individual_room_number']): ?>
                                                <br><small style="color:#888;font-size:10px;">#<?php echo htmlspecialchars($booking['individual_room_number']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <br><small style="color:#bbb;font-style:italic;font-size:10px;">Unassigned</small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Check-out"><?php echo date('M d, Y', strtotime($booking['check_out_date'])); ?></td>
                                    <td data-label="Status">
                                        <span class="badge badge-<?php echo $booking['status']; ?>" id="status-<?php echo $booking['id']; ?>">
                                            <?php echo ucfirst($booking['status']); ?>
                                        </span>
                                    </td>
                                    <td data-label="Payment">
                                        <span class="badge badge-<?php echo $booking['payment_status']; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                        <br><small style="color: #666; font-size: 11px; margin-top: 4px; display: block;">
                                            <?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($booking['amount_paid'], 2); ?> / <?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($booking['total_amount'], 2); ?>
                                            <?php if ($booking['amount_due'] > 0): ?>
                                                <span style="color: #dc3545; font-weight: 600;">(Due: <?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($booking['amount_due'], 2); ?>)</span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td data-label="Actions">
                                        <?php if ($booking['status'] !== 'checked-in'): ?>
                                            <?php $can_checkin = ($booking['status'] === 'confirmed' && $booking['payment_status'] === 'paid'); ?>
                                            <button onclick="<?php echo $can_checkin ? "processCheckIn({$booking['id']}, '" . htmlspecialchars(addslashes($booking['guest_name'])) . "')" : "Alert.show('Cannot check in: booking must be CONFIRMED and PAID.', 'error')"; ?>"
                                                id="checkin-btn-<?php echo $booking['id']; ?>"
                                                class="btn <?php echo $can_checkin ? 'btn-primary' : 'btn-light'; ?>" <?php echo $can_checkin ? '' : 'disabled'; ?>>
                                                <i class="fas fa-check"></i> Check In
                                            </button>
                                        <?php else: ?>
                                            <button onclick="cancelCheckIn(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars(addslashes($booking['guest_name'])); ?>')"
                                                id="cancel-checkin-btn-<?php echo $booking['id']; ?>"
                                                class="btn btn-dark">
                                                <i class="fas fa-undo"></i> Cancel Check-in
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No check-ins scheduled for today</p>
                </div>
            <?php endif; ?>
        </div>

        <?php endif; // mod_bookings — today's check-ins ?>

        <?php if ($mod_conference): ?>
        <!-- Today's Conference Events -->
        <div class="today-checkins-section">
            <h3>
                <i class="fas fa-calendar-check"></i> Today's Conference Events (<?php echo $today_conferences; ?>)
            </h3>

            <?php if (!empty($today_conference_events)): ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Company</th>
                                <th>Contact</th>
                                <th>Room</th>
                                <th>Time</th>
                                <th>Attendees</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($today_conference_events as $conf): ?>
                                <tr>
                                    <td data-label="Reference"><strong><?php echo htmlspecialchars($conf['inquiry_reference']); ?></strong></td>
                                    <td data-label="Company"><?php echo htmlspecialchars($conf['company_name']); ?></td>
                                    <td data-label="Contact"><?php echo htmlspecialchars($conf['contact_person']); ?></td>
                                    <td data-label="Room"><?php echo htmlspecialchars($conf['room_name'] ?? 'N/A'); ?></td>
                                    <td data-label="Time">
                                        <?php echo date('H:i', strtotime($conf['start_time'])); ?> -
                                        <?php echo date('H:i', strtotime($conf['end_time'])); ?>
                                    </td>
                                    <td data-label="Attendees"><?php echo (int) $conf['number_of_attendees']; ?></td>
                                    <td data-label="Status">
                                        <span class="badge badge-<?php echo $conf['status']; ?>">
                                            <?php echo ucfirst($conf['status']); ?>
                                        </span>
                                    </td>
                                    <td data-label="Actions">
                                        <a href="conference-management.php" class="btn btn-primary btn-sm">Manage</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <p>No conference events scheduled for today</p>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; // mod_conference — today's conference events ?>

        <?php if ($mod_bookings): ?>
        <h3 class="section-title">Upcoming Check-ins (Next 7 Days)</h3>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Booking Ref</th>
                        <th>Guest Name</th>
                        <th>Room</th>
                        <th>Check-in</th>
                        <th>Nights</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($upcoming_checkins)): ?>
                        <tr>
                            <td colspan="8" class="empty-state">
                                <i class="fas fa-calendar"></i>
                                <p>No upcoming check-ins in the next 7 days</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($upcoming_checkins as $booking): ?>
                            <tr>
                                <td data-label="Booking Ref"><strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong></td>
                                <td data-label="Guest Name"><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                                <td data-label="Room">
                                    <?php echo htmlspecialchars($booking['room_name']); ?>
                                    <?php if (!empty($booking['individual_room_id'])): ?>
                                        <br><span class="dashboard-room-chip">
                                            <i class="fas fa-door-open"></i>
                                            <?php echo htmlspecialchars($booking['individual_room_name'] ?: $booking['individual_room_number']); ?>
                                        </span>
                                        <?php if ($booking['individual_room_name'] && $booking['individual_room_number']): ?>
                                            <br><small style="color:#888;font-size:10px;">#<?php echo htmlspecialchars($booking['individual_room_number']); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <br><small style="color:#bbb;font-style:italic;font-size:10px;">Unassigned</small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Check-in"><?php echo date('M j, Y', strtotime($booking['check_in_date'])); ?></td>
                                <td data-label="Nights"><?php echo $booking['number_of_nights']; ?></td>
                                <td data-label="Status">
                                    <span class="badge badge-<?php echo $booking['status']; ?>">
                                        <?php echo ucfirst($booking['status']); ?>
                                    </span>
                                </td>
                                <td data-label="Payment">
                                    <span class="badge badge-<?php echo $booking['payment_status']; ?>">
                                        <?php echo ucfirst($booking['payment_status']); ?>
                                    </span>
                                    <br><small style="color: #666; font-size: 11px; margin-top: 4px; display: block;">
                                        <?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($booking['amount_paid'], 2); ?> / <?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($booking['total_amount'], 2); ?>
                                        <?php if ($booking['amount_due'] > 0): ?>
                                            <span style="color: #dc3545; font-weight: 600;">(Due: <?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($booking['amount_due'], 2); ?>)</span>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td data-label="Actions">
                                    <div class="quick-actions">
                                        <?php if ($booking['status'] == 'pending'): ?>
                                            <a href="booking-details.php?id=<?php echo $booking['id']; ?>&action=confirm" class="btn btn-success btn-sm">Confirm</a>
                                        <?php elseif ($booking['status'] == 'confirmed'): ?>
                                            <?php if ($booking['payment_status'] === 'paid'): ?>
                                                <a href="booking-details.php?id=<?php echo $booking['id']; ?>&action=checkin" class="btn btn-primary btn-sm">Check In</a>
                                            <?php else: ?>
                                                <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="btn btn-primary btn-sm disabled" onclick="Alert.show('Cannot check in: booking must be PAID first.', 'error'); return false;">Check In</a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="btn btn-primary btn-sm">View</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 class="section-title mt-4">Recent Bookings</h3>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Booking Ref</th>
                        <th>Guest Name</th>
                        <th>Room</th>
                        <th>Dates</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_bookings as $booking): ?>
                        <tr>
                            <td data-label="Booking Ref"><strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong></td>
                            <td data-label="Guest Name"><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                            <td data-label="Room">
                                <?php echo htmlspecialchars($booking['room_name']); ?>
                                <?php if (!empty($booking['individual_room_id'])): ?>
                                    <br><span class="dashboard-room-chip">
                                        <i class="fas fa-door-open"></i>
                                        <?php echo htmlspecialchars($booking['individual_room_name'] ?: $booking['individual_room_number']); ?>
                                    </span>
                                    <?php if ($booking['individual_room_name'] && $booking['individual_room_number']): ?>
                                        <br><small style="color:#888;font-size:10px;">#<?php echo htmlspecialchars($booking['individual_room_number']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <br><small style="color:#bbb;font-style:italic;font-size:10px;">Unassigned</small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Dates">
                                <?php echo date('M j', strtotime($booking['check_in_date'])); ?> -
                                <?php echo date('M j, Y', strtotime($booking['check_out_date'])); ?>
                            </td>
                            <td data-label="Total"><?php echo $currency_symbol; ?><?php echo number_format($booking['total_amount'], 2); ?></td>
                            <td data-label="Status">
                                <span class="badge badge-<?php echo $booking['status']; ?>">
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                            </td>
                            <td data-label="Payment">
                                <span class="badge badge-<?php echo $booking['payment_status']; ?>">
                                    <?php echo ucfirst($booking['payment_status']); ?>
                                </span>
                                <br><small style="color: #666; font-size: 11px; margin-top: 4px; display: block;">
                                    <?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($booking['amount_paid'], 2); ?> / <?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($booking['total_amount'], 2); ?>
                                    <?php if ($booking['amount_due'] > 0): ?>
                                        <span style="color: #dc3545; font-weight: 600;">(Due: <?php echo '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format($booking['amount_due'], 2); ?>)</span>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td data-label="Actions">
                                <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="btn btn-primary btn-sm">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php endif; // mod_bookings ?>

        <?php if ($mod_conference): ?>
        <h3 class="section-title mt-4">Upcoming Conference Events (Next 7 Days)</h3>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Company</th>
                        <th>Contact</th>
                        <th>Event Date</th>
                        <th>Time</th>
                        <th>Attendees</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($upcoming_conferences)): ?>
                        <tr>
                            <td colspan="8" class="empty-state">
                                <i class="fas fa-calendar"></i>
                                <p>No upcoming conference events in the next 7 days</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($upcoming_conferences as $conf): ?>
                            <tr>
                                <td data-label="Reference"><strong><?php echo htmlspecialchars($conf['inquiry_reference']); ?></strong></td>
                                <td data-label="Company"><?php echo htmlspecialchars($conf['company_name']); ?></td>
                                <td data-label="Contact"><?php echo htmlspecialchars($conf['contact_person']); ?></td>
                                <td data-label="Event Date"><?php echo date('M j, Y', strtotime($conf['event_date'])); ?></td>
                                <td data-label="Time">
                                    <?php echo date('H:i', strtotime($conf['start_time'])); ?> -
                                    <?php echo date('H:i', strtotime($conf['end_time'])); ?>
                                </td>
                                <td data-label="Attendees"><?php echo (int) $conf['number_of_attendees']; ?></td>
                                <td data-label="Status">
                                    <span class="badge badge-<?php echo $conf['status']; ?>">
                                        <?php echo ucfirst($conf['status']); ?>
                                    </span>
                                </td>
                                <td data-label="Actions">
                                    <a href="conference-management.php" class="btn btn-primary btn-sm">Manage</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 class="section-title mt-4">Recent Conference Enquiries</h3>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Company</th>
                        <th>Contact</th>
                        <th>Event Date</th>
                        <th>Attendees</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_conferences as $conf): ?>
                        <tr>
                            <td data-label="Reference"><strong><?php echo htmlspecialchars($conf['inquiry_reference']); ?></strong></td>
                            <td data-label="Company"><?php echo htmlspecialchars($conf['company_name']); ?></td>
                            <td data-label="Contact"><?php echo htmlspecialchars($conf['contact_person']); ?></td>
                            <td data-label="Event Date"><?php echo date('M j, Y', strtotime($conf['event_date'])); ?></td>
                            <td data-label="Attendees"><?php echo (int) $conf['number_of_attendees']; ?></td>
                            <td data-label="Status">
                                <span class="badge badge-<?php echo $conf['status']; ?>">
                                    <?php echo ucfirst($conf['status']); ?>
                                </span>
                            </td>
                            <td data-label="Actions">
                                <a href="conference-management.php" class="btn btn-primary btn-sm">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; // mod_conference ?>

        <?php if ($user['role'] === 'admin'): ?>
            <!-- Login Activity Log -->
            <div class="today-checkins-section" id="dashboard-login-activity">
                <h3>
                    <i class="fas fa-shield-alt"></i> Recent Login Activity
                    <?php if ($activity_log_total > 0): ?>
                        <span style="font-size:12px; color:#6b7280; font-weight:500; margin-left:8px;">(<?php echo (int)$activity_log_total; ?> total)</span>
                    <?php endif; ?>
                </h3>
                <?php if (empty($activity_log)): ?>
                    <div class="empty-state">
                        <i class="fas fa-shield-alt"></i>
                        <p>No recent login activity found</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="table" style="width:100%; border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr style="background:linear-gradient(135deg, var(--deep-navy, #111111) 0%, var(--navy, #1A1A1A) 100%); color:white;">
                                    <th style="padding:10px 14px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase;">Time</th>
                                    <th style="padding:10px 14px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase;">User</th>
                                    <th style="padding:10px 14px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase;">Action</th>
                                    <th style="padding:10px 14px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase;">Details</th>
                                    <th style="padding:10px 14px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase;">IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activity_log as $log):
                                    $action_colors = [
                                        'login_success' => ['bg' => '#e8f5e9', 'color' => '#2e7d32', 'icon' => 'fa-sign-in-alt', 'label' => 'Login'],
                                        'login_failed' => ['bg' => '#fbe9e7', 'color' => '#c62828', 'icon' => 'fa-times-circle', 'label' => 'Failed Login'],
                                        'login_blocked' => ['bg' => '#fff3e0', 'color' => '#e65100', 'icon' => 'fa-lock', 'label' => 'Blocked'],
                                        'logout' => ['bg' => '#e3f2fd', 'color' => '#1565c0', 'icon' => 'fa-sign-out-alt', 'label' => 'Logout'],
                                        'password_reset' => ['bg' => '#f3e5f5', 'color' => '#7b1fa2', 'icon' => 'fa-key', 'label' => 'Password Reset'],
                                    ];
                                    $ac = $action_colors[$log['action']] ?? ['bg' => '#f5f5f5', 'color' => '#666', 'icon' => 'fa-info-circle', 'label' => $log['action']];
                                ?>
                                    <tr style="border-bottom:1px solid #f0f0f0;">
                                        <td data-label="Time" style="padding:10px 14px; white-space:nowrap; color:#888; font-size:12px;">
                                            <?php echo date('M j, g:ia', strtotime($log['created_at'])); ?>
                                        </td>
                                        <td data-label="User" style="padding:10px 14px;">
                                            <strong><?php echo htmlspecialchars($log['full_name'] ?? $log['username'] ?? '—'); ?></strong>
                                            <?php if ($log['username']): ?>
                                                <span style="color:#999; font-size:11px;">(<?php echo htmlspecialchars($log['username']); ?>)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Action" style="padding:10px 14px;">
                                            <span style="display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; background:<?php echo $ac['bg']; ?>; color:<?php echo $ac['color']; ?>;">
                                                <i class="fas <?php echo $ac['icon']; ?>"></i> <?php echo $ac['label']; ?>
                                            </span>
                                        </td>
                                        <td data-label="Details" style="padding:10px 14px; color:#555; font-size:12px;">
                                            <?php echo htmlspecialchars($log['details'] ?? ''); ?>
                                        </td>
                                        <td data-label="IP Address" style="padding:10px 14px; font-family:monospace; font-size:12px; color:#888;">
                                            <?php echo htmlspecialchars($log['ip_address'] ?? ''); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($activity_log_total_pages > 1): ?>
                        <?php
                        $activity_page_params = $_GET;
                        unset($activity_page_params['activity_page']);
                        $activity_page_base = 'dashboard.php';
                        $activity_page_base .= empty($activity_page_params) ? '?' : ('?' . http_build_query($activity_page_params) . '&');
                        $activity_page_start = (($activity_log_page - 1) * $activity_log_per_page) + 1;
                        $activity_page_end = min($activity_log_page * $activity_log_per_page, $activity_log_total);
                        $activity_page_window_start = max(1, $activity_log_page - 2);
                        $activity_page_window_end = min($activity_log_total_pages, $activity_log_page + 2);
                        ?>
                        <nav class="bookings-pagination" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:14px 0 4px;flex-wrap:wrap;">
                            <?php if ($activity_log_page > 1): ?>
                                <a href="<?php echo htmlspecialchars($activity_page_base . 'activity_page=' . ($activity_log_page - 1), ENT_QUOTES); ?>#dashboard-login-activity" class="btn btn-sm btn-outline" data-no-admin-loader="1">&lsaquo; Prev</a>
                            <?php endif; ?>

                            <?php if ($activity_page_window_start > 1): ?>
                                <a href="<?php echo htmlspecialchars($activity_page_base . 'activity_page=1', ENT_QUOTES); ?>#dashboard-login-activity" class="btn btn-sm btn-outline" data-no-admin-loader="1">1</a>
                                <?php if ($activity_page_window_start > 2): ?>
                                    <span style="font-size:12px; color:#6b7280; padding:2px 2px;">&hellip;</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($activity_page_num = $activity_page_window_start; $activity_page_num <= $activity_page_window_end; $activity_page_num++): ?>
                                <?php if ($activity_page_num === $activity_log_page): ?>
                                    <span class="btn btn-sm btn-primary" aria-current="page"><?php echo $activity_page_num; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo htmlspecialchars($activity_page_base . 'activity_page=' . $activity_page_num, ENT_QUOTES); ?>#dashboard-login-activity" class="btn btn-sm btn-outline" data-no-admin-loader="1"><?php echo $activity_page_num; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($activity_page_window_end < $activity_log_total_pages): ?>
                                <?php if ($activity_page_window_end < ($activity_log_total_pages - 1)): ?>
                                    <span style="font-size:12px; color:#6b7280; padding:2px 2px;">&hellip;</span>
                                <?php endif; ?>
                                <a href="<?php echo htmlspecialchars($activity_page_base . 'activity_page=' . $activity_log_total_pages, ENT_QUOTES); ?>#dashboard-login-activity" class="btn btn-sm btn-outline" data-no-admin-loader="1"><?php echo $activity_log_total_pages; ?></a>
                            <?php endif; ?>

                            <?php if ($activity_log_page < $activity_log_total_pages): ?>
                                <a href="<?php echo htmlspecialchars($activity_page_base . 'activity_page=' . ($activity_log_page + 1), ENT_QUOTES); ?>#dashboard-login-activity" class="btn btn-sm btn-outline" data-no-admin-loader="1">Next &rsaquo;</a>
                            <?php endif; ?>

                            <span style="font-size:12px; color:#6b7280; padding:4px 6px;">Showing <?php echo (int)$activity_page_start; ?>–<?php echo (int)$activity_page_end; ?> of <?php echo (int)$activity_log_total; ?></span>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="dashboard-insight-modal modal-overlay" data-modal id="dashboardInsightModal" aria-hidden="true" inert>
        <div class="dashboard-insight-modal__backdrop" data-close-dashboard-insight></div>
        <div class="dashboard-insight-modal__dialog modal-content" role="dialog" aria-modal="true" aria-labelledby="dashboardInsightTitle">
            <header class="dashboard-insight-modal__header modal-header">
                <div>
                    <p class="dashboard-insight-modal__eyebrow" id="dashboardInsightEyebrow">Dashboard Insight</p>
                    <h3 id="dashboardInsightTitle">Loading details…</h3>
                    <p class="dashboard-insight-modal__subtitle" id="dashboardInsightSubtitle">Fetching latest records.</p>
                </div>
                <button type="button" class="dashboard-insight-modal__close modal-close" data-close-dashboard-insight aria-label="Close modal">
                    <i class="fas fa-times"></i>
                </button>
            </header>
            <div class="dashboard-insight-modal__body modal-body" id="dashboardInsightBody">
                <p class="dashboard-insight-modal__loading"><i class="fas fa-spinner fa-spin"></i> Loading details…</p>
            </div>
            <footer class="dashboard-insight-modal__footer modal-footer" id="dashboardInsightFooter">
                <a id="dashboardInsightLink" class="dashboard-insight-modal__link" href="dashboard.php">Open full page</a>
            </footer>
        </div>
    </div>

    <script src="js/admin-components.js"></script>
    <script>
        const _dashCsrf = <?php echo json_encode($csrf_token); ?>;

        const _insightModal = document.getElementById('dashboardInsightModal');
        const _insightEyebrow = document.getElementById('dashboardInsightEyebrow');
        const _insightTitle = document.getElementById('dashboardInsightTitle');
        const _insightSubtitle = document.getElementById('dashboardInsightSubtitle');
        const _insightBody = document.getElementById('dashboardInsightBody');
        const _insightLink = document.getElementById('dashboardInsightLink');
        const _insightFooter = document.getElementById('dashboardInsightFooter');
        const _insightCloseBtn = _insightModal ? _insightModal.querySelector('[data-close-dashboard-insight][aria-label="Close modal"]') : null;
        let _insightLastTrigger = null;
        let _insightLastFocused = null;

        document.querySelectorAll('.js-dashboard-insight').forEach((anchor) => {
            anchor.setAttribute('data-no-spa', '1');
            anchor.setAttribute('data-no-admin-loader', '1');
        });

        function dashboardInsightEscape(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [char]));
        }

        function dashboardInsightSetOpen(open) {
            if (!_insightModal) return;
            const shouldOpen = !!open;
            const isOpen = _insightModal.classList.contains('is-open');
            if (shouldOpen === isOpen) {
                return;
            }

            if (shouldOpen) {
                _insightLastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                _insightModal.removeAttribute('inert');
                _insightModal.classList.add('is-open', 'active');
                _insightModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('dashboard-insight-open', 'modal-open');
                if (_insightCloseBtn instanceof HTMLElement) {
                    requestAnimationFrame(() => {
                        _insightCloseBtn.focus();
                    });
                }
                return;
            }

            if (document.activeElement instanceof HTMLElement && _insightModal.contains(document.activeElement)) {
                document.activeElement.blur();
            }
            _insightModal.classList.remove('is-open', 'active');
            _insightModal.setAttribute('aria-hidden', 'true');
            _insightModal.setAttribute('inert', '');
            document.body.classList.remove('dashboard-insight-open');
            if (!document.querySelector('.modal-overlay.active')) {
                document.body.classList.remove('modal-open');
            }

            let restoreTarget = null;
            if (_insightLastTrigger instanceof HTMLElement && document.contains(_insightLastTrigger)) {
                restoreTarget = _insightLastTrigger;
            } else if (_insightLastFocused instanceof HTMLElement && document.contains(_insightLastFocused)) {
                restoreTarget = _insightLastFocused;
            }
            if (restoreTarget instanceof HTMLElement) {
                requestAnimationFrame(() => {
                    restoreTarget.focus();
                });
            }
        }

        function dashboardInsightShowLoading(cardLabel) {
            if (_insightEyebrow) _insightEyebrow.textContent = 'Dashboard Insight';
            if (_insightTitle) _insightTitle.textContent = cardLabel || 'Loading details…';
            if (_insightSubtitle) _insightSubtitle.textContent = 'Fetching latest records.';
            if (_insightBody) {
                _insightBody.innerHTML = '<p class="dashboard-insight-modal__loading"><i class="fas fa-spinner fa-spin"></i> Loading details…</p>';
            }
            if (_insightFooter) _insightFooter.hidden = true;
        }

        function dashboardInsightRender(payload) {
            if (!_insightModal || !payload) return;
            if (_insightEyebrow) _insightEyebrow.textContent = 'Dashboard Insight';
            if (_insightTitle) _insightTitle.textContent = payload.title || 'Insight';
            if (_insightSubtitle) _insightSubtitle.textContent = payload.subtitle || 'Latest operational records';

            const columns = Array.isArray(payload.columns) ? payload.columns : [];
            const rows = Array.isArray(payload.rows) ? payload.rows : [];
            const descriptionText = typeof payload.description === 'string' ? payload.description.trim() : '';

            if (_insightBody) {
                if (!rows.length || !columns.length) {
                    _insightBody.innerHTML = '<div class="dashboard-insight-modal__empty"><i class="fas fa-inbox"></i><p>' +
                        dashboardInsightEscape(payload.empty || 'No matching records right now.') +
                        '</p></div>';
                } else {
                    const summaryText = descriptionText !== '' ?
                        descriptionText :
                        (rows.length + ' record' + (rows.length === 1 ? '' : 's') + ' shown.');
                    const summaryHtml = '<p class="dashboard-insight-modal__description">' + dashboardInsightEscape(summaryText) + '</p>';
                    const normalizedColumns = columns.map((column, index) => {
                        const key = typeof column.key === 'string' && column.key !== '' ? column.key : String(index);
                        const label = typeof column.label === 'string' && column.label !== '' ? column.label : key;
                        return {
                            key,
                            label
                        };
                    });
                    const headHtml = normalizedColumns.map((column) => '<th>' + dashboardInsightEscape(column.label) + '</th>').join('');
                    const bodyHtml = rows.map((row) => {
                        const cells = normalizedColumns.map((column) => {
                            const dataLabel = dashboardInsightEscape(column.label || 'Field');
                            const dataLabelAttr = ' data-label="' + dataLabel + '"';
                            const cellValue = row[column.key];
                            if (cellValue && typeof cellValue === 'object' && !Array.isArray(cellValue) && cellValue.href) {
                                const href = dashboardInsightEscape(cellValue.href);
                                const label = dashboardInsightEscape(cellValue.label || 'Open');
                                const target = cellValue.target === '_blank' ? ' target="_blank" rel="noopener"' : '';
                                return '<td' + dataLabelAttr + '><a class="dashboard-insight-modal__row-link" href="' + href + '"' + target + '>' + label + '</a></td>';
                            }
                            if (cellValue && typeof cellValue === 'object' && !Array.isArray(cellValue) && cellValue.type === 'details') {
                                const summary = dashboardInsightEscape(cellValue.summary || 'View details');
                                const caption = cellValue.caption ? '<span class="dashboard-insight-modal__cell-details-caption">' + dashboardInsightEscape(cellValue.caption) + '</span>' : '';
                                const detailItems = Array.isArray(cellValue.items) ? cellValue.items : [];
                                const detailHtml = detailItems.length ?
                                    '<ul class="dashboard-insight-modal__cell-details-list">' + detailItems.map((item) => {
                                        const itemName = dashboardInsightEscape(item && item.name ? item.name : 'Item');
                                        const qty = Number(item && item.quantity ? item.quantity : 0);
                                        const qtyLabel = Number.isFinite(qty) && qty > 0 ? ' x' + qty : '';
                                        const kdsStatus = dashboardInsightEscape(item && item.kds_status ? item.kds_status : 'N/A');
                                        const posStatus = dashboardInsightEscape(item && item.pos_status ? item.pos_status : 'N/A');
                                        return '<li><span class="dashboard-insight-modal__cell-details-item">' + itemName + qtyLabel + '</span><span class="dashboard-insight-modal__cell-details-status">KDS: ' + kdsStatus + ' · POS: ' + posStatus + '</span></li>';
                                    }).join('') + '</ul>' :
                                    '<p class="dashboard-insight-modal__cell-details-empty">No item details captured.</p>';
                                return '<td class="dashboard-insight-modal__details-cell"' + dataLabelAttr + '><details class="dashboard-insight-modal__cell-details"><summary><span class="dashboard-insight-modal__cell-details-summary">' + summary + '</span>' + caption + '</summary>' + detailHtml + '</details></td>';
                            }
                            return '<td' + dataLabelAttr + '>' + dashboardInsightEscape(cellValue ?? '—') + '</td>';
                        }).join('');
                        return '<tr>' + cells + '</tr>';
                    }).join('');
                    _insightBody.innerHTML = summaryHtml + '<div class="dashboard-insight-modal__table-wrap">' +
                        '<table class="dashboard-insight-modal__table no-card-mobile">' +
                        '<thead><tr>' + headHtml + '</tr></thead>' +
                        '<tbody>' + bodyHtml + '</tbody>' +
                        '</table>' +
                        '</div>';
                }
            }

            if (_insightLink && _insightFooter) {
                const href = payload.link && payload.link.href ? String(payload.link.href) : '';
                if (href !== '') {
                    _insightLink.href = href;
                    _insightLink.textContent = payload.link.label || 'Open full page';
                    _insightFooter.hidden = false;
                } else {
                    _insightFooter.hidden = true;
                }
            }
        }

        async function dashboardInsightOpen(cardEl) {
            if (!_insightModal || !cardEl) return;
            const cardKey = String(cardEl.dataset.insightCard || '').trim();
            if (cardKey === '') return;
            const cardLabel = cardEl.querySelector('.stat-label, .ops-label')?.textContent?.trim() || 'Loading details…';
            _insightLastTrigger = cardEl instanceof HTMLElement ? cardEl : null;

            dashboardInsightSetOpen(true);
            dashboardInsightShowLoading(cardLabel);

            try {
                const response = await fetch('dashboard.php?ajax=card_insight&card=' + encodeURIComponent(cardKey), {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });
                const payload = await response.json();
                if (!response.ok || !payload.success) {
                    throw new Error(payload.error || 'Unable to load details');
                }
                dashboardInsightRender(payload);
            } catch (error) {
                if (_insightTitle) _insightTitle.textContent = cardLabel;
                if (_insightSubtitle) _insightSubtitle.textContent = 'Could not load details right now.';
                if (_insightBody) {
                    _insightBody.innerHTML = '<div class="dashboard-insight-modal__empty"><i class="fas fa-triangle-exclamation"></i><p>' +
                        dashboardInsightEscape(error.message || 'Unable to load details.') +
                        '</p></div>';
                }
                if (_insightFooter) _insightFooter.hidden = true;
            }
        }

        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('.js-dashboard-insight');
            if (!trigger) return;
            if (event.defaultPrevented) return;
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            dashboardInsightOpen(trigger);
        });

        document.querySelectorAll('[data-close-dashboard-insight]').forEach((node) => {
            node.addEventListener('click', () => dashboardInsightSetOpen(false));
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && _insightModal?.classList.contains('is-open')) {
                dashboardInsightSetOpen(false);
            }
        });

        function dashboardConfirm(options) {
            if (window.AdminConfirm && typeof window.AdminConfirm.request === 'function') {
                return window.AdminConfirm.request(options);
            }
            return Promise.resolve(confirm(options.message || options.title || 'Confirm action'));
        }

        async function processCheckIn(bookingId, guestName) {
            const confirmed = await dashboardConfirm({
                title: 'Confirm guest check-in',
                message: `Check in ${guestName}?`,
                details: ['The booking status will be changed to checked-in.', 'This action will be recorded in the audit trail.'],
                confirmText: 'Check In',
                icon: 'fa-right-to-bracket',
                tone: 'success'
            });
            if (!confirmed) return;

            const actionButton = document.getElementById(`checkin-btn-${bookingId}`);
            if (window.ButtonLoader && actionButton) ButtonLoader.show(actionButton, {
                text: 'Checking in...'
            });
            if (window.AdminPageLoader) AdminPageLoader.show('Checking in guest...');

            const formData = new FormData();
            formData.append('action', 'checkin');
            formData.append('booking_id', bookingId);
            formData.append('csrf_token', _dashCsrf);

            fetch('process-checkin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update UI
                        const statusBadge = document.getElementById(`status-${bookingId}`);
                        if (statusBadge) {
                            statusBadge.className = 'badge badge-checked-in';
                            statusBadge.textContent = 'Checked-in';
                        }

                        const button = document.getElementById(`checkin-btn-${bookingId}`);
                        if (button) {
                            button.outerHTML = `<button onclick="cancelCheckIn(${bookingId}, '${guestName.replace(/'/g, "\\'")}')" id="cancel-checkin-btn-${bookingId}" class="btn btn-dark"><i class="fas fa-undo"></i> Cancel Check-in</button>`;
                        }

                        if (window.AdminPageLoader) AdminPageLoader.hide();
                        Alert.show(`${guestName} successfully checked in!`, 'success');
                    } else {
                        if (window.AdminPageLoader) AdminPageLoader.hide();
                        if (window.ButtonLoader && actionButton) ButtonLoader.hide(actionButton);
                        Alert.show('Error: ' + (data.message || 'Failed to check in guest'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (window.AdminPageLoader) AdminPageLoader.hide();
                    if (window.ButtonLoader && actionButton) ButtonLoader.hide(actionButton);
                    Alert.show('An error occurred during check-in', 'error');
                });
        }

        async function cancelCheckIn(bookingId, guestName) {
            const confirmed = await dashboardConfirm({
                title: 'Cancel check-in',
                message: `Cancel check-in for ${guestName}?`,
                details: ['The booking will be reverted to confirmed.', 'This action will be recorded in the audit trail.'],
                confirmText: 'Cancel Check-in',
                icon: 'fa-rotate-left',
                tone: 'warning'
            });
            if (!confirmed) return;

            const actionButton = document.getElementById(`cancel-checkin-btn-${bookingId}`);
            if (window.ButtonLoader && actionButton) ButtonLoader.show(actionButton, {
                text: 'Cancelling...'
            });
            if (window.AdminPageLoader) AdminPageLoader.show('Cancelling check-in...');

            const formData = new FormData();
            formData.append('action', 'cancel_checkin');
            formData.append('booking_id', bookingId);
            formData.append('csrf_token', _dashCsrf);

            fetch('process-checkin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const statusBadge = document.getElementById(`status-${bookingId}`);
                        if (statusBadge) {
                            statusBadge.className = 'badge badge-confirmed';
                            statusBadge.textContent = 'Confirmed';
                        }

                        const button = document.getElementById(`cancel-checkin-btn-${bookingId}`);
                        if (button) {
                            button.outerHTML = `<span class="badge badge-confirmed">Reverted to confirmed</span>`;
                        }

                        if (window.AdminPageLoader) AdminPageLoader.hide();
                        Alert.show(`Check-in cancelled for ${guestName}.`, 'success');
                    } else {
                        if (window.AdminPageLoader) AdminPageLoader.hide();
                        if (window.ButtonLoader && actionButton) ButtonLoader.hide(actionButton);
                        Alert.show('Error: ' + (data.message || 'Failed to cancel check-in'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (window.AdminPageLoader) AdminPageLoader.hide();
                    if (window.ButtonLoader && actionButton) ButtonLoader.hide(actionButton);
                    Alert.show('An error occurred while cancelling check-in', 'error');
                });
        }
        // -------------------------------------------------------------------
        // System Health Monitor
        // -------------------------------------------------------------------
        (function () {
            'use strict';
            const SHC_URL  = 'api/system-health.php';
            const POLL_MS  = 60000;
            let   _shcTimer = null;

            function fmtBytes(b) {
                if (b === null || b === undefined) return '—';
                b = Number(b);
                if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
                if (b >= 1048576)    return (b / 1048576).toFixed(1) + ' MB';
                return Math.round(b / 1024) + ' KB';
            }

            function setCard(iconId, valueId, metaId, cfg) {
                const ic = document.getElementById(iconId);
                const vl = document.getElementById(valueId);
                const mt = document.getElementById(metaId);
                if (ic) ic.style.background = cfg.bg;
                if (vl) vl.innerHTML        = cfg.value;
                if (mt) mt.textContent      = cfg.meta;
            }

            async function shcFetch() {
                const btn = document.getElementById('shcRefreshBtn');
                if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
                try {
                    const res = await fetch(SHC_URL, {
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const d = await res.json();

                    // Database
                    const dbOk = d.db === 'ok';
                    setCard('shcDbIcon', 'shcDbValue', 'shcDbMeta', {
                        bg:    dbOk ? '#2e7d32' : '#c62828',
                        value: dbOk
                            ? '<i class="fas fa-check-circle" style="color:#fff;"></i>'
                            : '<i class="fas fa-times-circle" style="color:#fff;"></i>',
                        meta:  dbOk ? 'Connected' : 'Connection failed'
                    });

                    // Backup
                    const bkAge = d.last_backup_age_hours;
                    const bkBg  = !d.last_backup_at
                        ? '#c62828'
                        : (bkAge < 12 ? '#2e7d32' : (bkAge < 36 ? '#b45309' : '#c62828'));
                    const bkVal  = d.last_backup_at
                        ? (bkAge !== null ? bkAge + 'h ago' : '—')
                        : 'Never';
                    const bkMeta = d.last_backup_at
                        ? (new Date(d.last_backup_at).toLocaleString()
                            + (d.last_backup_size_bytes ? ' · ' + fmtBytes(d.last_backup_size_bytes) : ''))
                        : 'No backup on record';
                    setCard('shcBackupIcon', 'shcBackupValue', 'shcBackupMeta',
                        { bg: bkBg, value: bkVal, meta: bkMeta });

                    // Disk
                    const dkPct = d.disk_free_pct;
                    const dkBg  = dkPct === null ? '#607d8b'
                        : (dkPct > 20 ? '#2e7d32' : (dkPct > 10 ? '#b45309' : '#c62828'));
                    const dkVal  = dkPct !== null ? dkPct + '%' : '—';
                    const dkMeta = d.disk_free_bytes !== null
                        ? fmtBytes(d.disk_free_bytes) + ' free of ' + fmtBytes(d.disk_total_bytes)
                        : 'Unavailable on this host';
                    setCard('shcDiskIcon', 'shcDiskValue', 'shcDiskMeta',
                        { bg: dkBg, value: dkVal, meta: dkMeta });

                    // Error log
                    const lgSz  = d.log_error_size_bytes;
                    const lgBg  = lgSz === null ? '#607d8b'
                        : (lgSz < 102400 ? '#2e7d32' : (lgSz < 1048576 ? '#b45309' : '#c62828'));
                    const lgVal  = lgSz !== null ? fmtBytes(lgSz) : '—';
                    const lgMeta = lgSz !== null
                        ? (lgSz === 0 ? 'No errors logged' : 'php-errors.log on server')
                        : 'File not found';
                    setCard('shcLogIcon', 'shcLogValue', 'shcLogMeta',
                        { bg: lgBg, value: lgVal, meta: lgMeta });

                    // PHP version
                    const phpVal = document.getElementById('shcPhpValue');
                    const phpMt  = document.getElementById('shcPhpMeta');
                    if (phpVal) phpVal.textContent = d.php_version || '—';
                    if (phpMt)  phpMt.textContent  = d.server_time
                        ? 'Server: ' + new Date(d.server_time).toLocaleTimeString()
                        : '—';

                    // Tentative sweep
                    let swBg = '#607d8b', swVal = 'Never', swMeta = 'Cron not yet run';
                    if (d.last_tentative_sweep_at) {
                        const swAge = Math.round(
                            (Date.now() - new Date(d.last_tentative_sweep_at).getTime()) / 3600000
                        );
                        swBg   = swAge < 2  ? '#2e7d32' : (swAge < 25 ? '#b45309' : '#c62828');
                        swVal  = swAge + 'h ago';
                        swMeta = new Date(d.last_tentative_sweep_at).toLocaleString();
                    }
                    setCard('shcSweepIcon', 'shcSweepValue', 'shcSweepMeta',
                        { bg: swBg, value: swVal, meta: swMeta });

                    const el = document.getElementById('shcLastChecked');
                    if (el) el.textContent = new Date().toLocaleTimeString();
                } catch (err) {
                    console.error('[SysHealth]', err);
                    const el = document.getElementById('shcLastChecked');
                    if (el) el.textContent = 'Error: ' + (err.message || 'check failed');
                } finally {
                    if (btn) {
                        btn.disabled  = false;
                        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh';
                    }
                }
            }

            const btn = document.getElementById('shcRefreshBtn');
            if (btn) {
                btn.addEventListener('click', () => {
                    clearTimeout(_shcTimer);
                    shcFetch().finally(() => {
                        _shcTimer = setTimeout(shcFetch, POLL_MS);
                    });
                });
            }

            // Run immediately on page load, then every 60 s
            shcFetch();
            _shcTimer = setInterval(shcFetch, POLL_MS);
        })();
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>

