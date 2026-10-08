<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */

require_once '../includes/modal.php';
require_once '../includes/alert.php';
require_once '../includes/room-management.php';
require_once '../includes/station-hours.php';
require_once '../includes/conference-pricing.php';

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
$roomServiceReminderTimezone = trim((string)getSetting('site_timezone', date_default_timezone_get()));
if ($roomServiceReminderTimezone === '' || !in_array($roomServiceReminderTimezone, DateTimeZone::listIdentifiers(), true)) {
    $roomServiceReminderTimezone = 'Africa/Blantyre';
}
$roomServiceNow = new DateTimeImmutable('now', new DateTimeZone($roomServiceReminderTimezone));
$roomServiceReminderDueNow = $roomServiceNow->format('H:i') >= $roomServiceReminderTime;

// Default values so the template never sees undefined variables if DB queries fail
$upcoming_checkins = [];
$today_conference_events = [];
$upcoming_conferences = [];
$today_checkins = 0;
$today_checkouts = 0;
$pending_bookings = 0;
$current_guests = 0;
$pending_conference = 0;
$today_conferences = 0;
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
$dayProgress = ['arrived' => 0, 'departed' => 0];
$overdueDepartures = 0; // checked in past their checkout date
$departureList = [];
$weekAhead = [];
$revenueTrend = [];
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

            $upcoming_stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name,
                       ir.room_number as individual_room_number, ir.room_name as individual_room_name,
                       b.total_amount, b.amount_paid, b.amount_due, b.payment_status
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                WHERE b.deleted_at IS NULL AND b.check_in_date BETWEEN DATE_ADD(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
                AND b.status IN ('pending', 'confirmed')
                ORDER BY b.check_in_date ASC
            ");
            $upcoming_stmt->execute([$today, $today]);
            $upcoming_checkins = $upcoming_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($mod_conference) {
            $pending_conf_stmt = $pdo->query("SELECT COUNT(*) as count FROM conference_inquiries WHERE status = 'pending'");
            $pending_conference = $pending_conf_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Multi-day events (end_date, migration 061) count on every day of their range.
            $confTodayCond = rh_conf_has_end_date($pdo)
                ? "(event_date <= ? AND COALESCE(end_date, event_date) >= ?)"
                : "event_date = ?";
            $confTodayParams = rh_conf_has_end_date($pdo) ? [$today, $today] : [$today];
            $today_conf_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM conference_inquiries WHERE {$confTodayCond} AND status IN ('confirmed', 'pending')");
            $today_conf_stmt->execute($confTodayParams);
            $today_conferences = $today_conf_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            $today_conf_events_stmt = $pdo->prepare("
                SELECT ci.*, cr.name as room_name
                FROM conference_inquiries ci
                LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
                WHERE " . (rh_conf_has_end_date($pdo) ? "(ci.event_date <= ? AND COALESCE(ci.end_date, ci.event_date) >= ?)" : "ci.event_date = ?") . " AND ci.status IN ('confirmed', 'pending')
                ORDER BY ci.start_time ASC
            ");
            $today_conf_events_stmt->execute($confTodayParams);
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
                    WHERE b.deleted_at IS NULL AND b.status = 'checked-in'
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
            if ($mod_bookings)   { $outstandingSql[] = "SELECT COUNT(*) c, COALESCE(SUM(amount_due),0) v FROM bookings WHERE amount_due > 0.01 AND (status IN ('pending','confirmed','checked-in','checked-out') OR (status = 'cancelled' AND COALESCE(cancellation_retained_amount,0) > 0))"; }
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

    /* -----------------------------------------------------------------------
     * Cockpit extras: day progress, departures, 7-day takings trend.
     * Read-only and individually guarded like the blocks above.
     * --------------------------------------------------------------------- */
    if ($mod_bookings) {
        try {
            $st = $pdo->prepare("SELECT
                    SUM(check_in_date = ? AND status = 'checked-in') AS arrived,
                    SUM(check_out_date = ? AND status = 'checked-out') AS departed
                FROM bookings
                WHERE deleted_at IS NULL AND (check_in_date = ? OR check_out_date = ?)");
            $st->execute([$today, $today, $today, $today]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $dayProgress['arrived']  = (int)($r['arrived'] ?? 0);
            $dayProgress['departed'] = (int)($r['departed'] ?? 0);

            // Everyone due out today (already departed ones included, so the list always matches
            // the Departures card) plus guests still checked in after their checkout date (overdue).
            $st = $pdo->prepare("SELECT b.id, b.booking_reference, b.guest_name, b.check_in_date, b.check_out_date, b.number_of_nights,
                       b.amount_due, b.payment_status, b.status, r.name AS room_name,
                       ir.room_number AS individual_room_number, ir.room_name AS individual_room_name,
                       CASE WHEN b.status = 'checked-out' THEN 'departed'
                            WHEN b.check_out_date < ? THEN 'overdue' ELSE 'due' END AS departure_state
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                WHERE b.deleted_at IS NULL AND ((b.check_out_date = ? AND b.status IN ('checked-in', 'checked-out'))
                      OR (b.check_out_date < ? AND b.status = 'checked-in'))
                ORDER BY FIELD(departure_state, 'overdue', 'due', 'departed'), b.check_out_date ASC, b.amount_due DESC, b.guest_name ASC");
            $st->execute([$today, $today, $today]);
            $departureList = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($departureList as $d) {
                if ($d['departure_state'] === 'overdue') { $overdueDepartures++; }
            }
        } catch (Throwable $e) { /* keep defaults */ }
    }

    // Next 7 days of arrivals bucketed per day (from the list already loaded)
    for ($i = 1; $i <= 7; $i++) {
        $weekAhead[date('Y-m-d', strtotime($today . " +{$i} day"))] = 0;
    }
    foreach ($upcoming_checkins as $uc) {
        if (isset($weekAhead[$uc['check_in_date']])) {
            $weekAhead[$uc['check_in_date']]++;
        }
    }

    // Takings over the last 7 days — same rules as "revenue today" (ledger
    // without restaurant rows + POS gross), so today's bar matches the KPI.
    for ($i = 6; $i >= 0; $i--) {
        $revenueTrend[date('Y-m-d', strtotime($today . " -{$i} day"))] = 0.0;
    }
    $trendStart = array_key_first($revenueTrend);
    if ($mod_finance) {
        try {
            $st = $pdo->prepare("SELECT DATE(payment_date) d, COALESCE(SUM(total_amount),0) v FROM payments
                WHERE DATE(payment_date) BETWEEN ? AND ? AND payment_status IN ('paid','completed','partial')
                  AND deleted_at IS NULL AND COALESCE(payment_type, '') <> 'refund' AND booking_type <> 'restaurant'
                GROUP BY DATE(payment_date)");
            $st->execute([$trendStart, $today]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($revenueTrend[$r['d']])) { $revenueTrend[$r['d']] += (float)$r['v']; }
            }
        } catch (Throwable $e) { /* fine */ }
    }
    if ($mod_pos) {
        try {
            $st = $pdo->prepare("SELECT DATE(COALESCE(paid_at, created_at)) d, COALESCE(SUM(total_amount),0) v FROM stock_orders
                WHERE status IN ('paid','completed') AND DATE(COALESCE(paid_at, created_at)) BETWEEN ? AND ?
                GROUP BY DATE(COALESCE(paid_at, created_at))");
            $st->execute([$trendStart, $today]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($revenueTrend[$r['d']])) { $revenueTrend[$r['d']] += (float)$r['v']; }
            }
        } catch (Throwable $e) { /* fine */ }
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
                $payload['subtitle'] = 'Everyone due in today, with their status';
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
                                       WHERE b.deleted_at IS NULL AND b.check_in_date = ? AND b.status IN ('confirmed','pending','checked-in','checked-out')
                                       ORDER BY FIELD(b.status, 'pending', 'confirmed', 'checked-in', 'checked-out'), b.created_at ASC
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
                $payload['subtitle'] = 'Everyone due out today, plus guests still in after their checkout date';
                $payload['columns'] = [
                    ['key' => 'reference', 'label' => 'Booking'],
                    ['key' => 'guest', 'label' => 'Guest'],
                    ['key' => 'room', 'label' => 'Room'],
                    ['key' => 'checkout', 'label' => 'Check-out'],
                    ['key' => 'state', 'label' => 'Status'],
                    ['key' => 'amount_due', 'label' => 'Outstanding'],
                ];
                $payload['link'] = ['href' => 'bookings.php?filter=checkout_today&flash=results', 'label' => 'Open check-outs list'];
                $stmt = $pdo->prepare("SELECT b.id AS booking_id, b.booking_reference, b.guest_name, b.check_out_date, b.amount_due, b.status,
                                              r.name AS room_name, ir.room_number, ir.room_name AS individual_room_name
                                       FROM bookings b
                                       JOIN rooms r ON r.id = b.room_id
                                       LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                                       WHERE b.deleted_at IS NULL AND ((b.check_out_date = ? AND b.status IN ('checked-in', 'checked-out'))
                                             OR (b.check_out_date < ? AND b.status = 'checked-in'))
                                       ORDER BY (b.status = 'checked-out'), b.check_out_date ASC, b.created_at ASC
                                       LIMIT 30");
                $stmt->execute([$today, $today]);
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
                        'state' => $row['status'] === 'checked-out' ? 'Checked out' : ((string)$row['check_out_date'] < $today ? 'Overdue' : 'Due today'),
                        'amount_due' => $formatMoney((float)($row['amount_due'] ?? 0)),
                    ];
                }
                $payload['empty'] = 'No check-outs are due today, and nobody is overdue.';
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
                                     WHERE deleted_at IS NULL AND status = 'pending'
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
                                     WHERE b.deleted_at IS NULL AND b.status = 'checked-in'
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
                                       WHERE " . (rh_conf_has_end_date($pdo) ? "(ci.event_date <= ? AND COALESCE(ci.end_date, ci.event_date) >= ?)" : "ci.event_date = ?") . " AND ci.status IN ('confirmed','pending')
                                       ORDER BY ci.start_time ASC
                                       LIMIT 30");
                $stmt->execute(rh_conf_has_end_date($pdo) ? [$today, $today] : [$today]);
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
                                     WHERE deleted_at IS NULL AND status = 'expired' AND expired_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
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
                if ($mod_bookings)   { $ob_union[] = "SELECT id, booking_reference AS ref, guest_name AS who, status, amount_paid, amount_due, 'booking' AS src FROM bookings WHERE amount_due > 0.01 AND (status IN ('pending','confirmed','checked-in','checked-out') OR (status = 'cancelled' AND COALESCE(cancellation_retained_amount,0) > 0))"; }
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
                                        WHERE b.deleted_at IS NULL AND b.status = 'checked-in'
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
                    if ($mod_bookings)   { $ofUnion[] = "SELECT COUNT(*) c, COALESCE(SUM(amount_due),0) v FROM bookings WHERE amount_due > 0.01 AND (status IN ('pending','confirmed','checked-in','checked-out') OR (status = 'cancelled' AND COALESCE(cancellation_retained_amount,0) > 0))"; }
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
                                                                                                        WHERE b1.deleted_at IS NULL AND b1.individual_room_id = ir.id
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
    <link rel="stylesheet" href="css/admin-cockpit.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-cockpit.css'); ?>">
    <link rel="stylesheet" href="css/dashboard.css?v=<?php echo @filemtime(__DIR__ . '/css/dashboard.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content" data-rh-page-header-normalized="1"><?php /* the cockpit hero is this page's header */ ?>
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

        <?php
        /* =====================================================================
         * Cockpit view-model — everything the page shows is decided here so the
         * markup below stays a plain render.
         * =================================================================== */
        $uid = (int)$user['id'];
        $cur = htmlspecialchars((string)$currency_symbol, ENT_QUOTES, 'UTF-8');
        $money = static function ($v) use ($cur): string {
            return '<span class="ck-cur">' . $cur . '</span>' . number_format((float)$v, 2);
        };
        $moneyShort = static function ($v) use ($cur): string {
            $v = (float)$v;
            if (abs($v) >= 1000000) { return $cur . number_format($v / 1000000, 1) . 'M'; }
            if (abs($v) >= 10000)   { return $cur . number_format($v / 1000, 1) . 'k'; }
            return $cur . number_format($v, 0);
        };
        $initials = static function (string $name): string {
            $parts = preg_split('/\s+/', trim($name)) ?: [];
            $out = '';
            foreach (array_slice($parts, 0, 2) as $p) { $out .= mb_strtoupper(mb_substr($p, 0, 1)); }
            return $out !== '' ? $out : '?';
        };
        $roomLabel = static function (array $b): string {
            $unit = trim((string)($b['individual_room_name'] ?? '')) ?: trim((string)($b['individual_room_number'] ?? ''));
            return $unit !== '' ? $unit : 'Unassigned';
        };
        $can = static function (string $page) use ($uid): bool {
            return !function_exists('rhCanLinkTo') || rhCanLinkTo($uid, $page);
        };

        $hour = (int)date('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $firstName = trim(explode(' ', trim((string)($user['full_name'] ?: $user['username'])))[0] ?? '');

        // Today's arrivals still to come (pending/confirmed)
        $checkin_bookings = [];
        if ($mod_bookings) {
            try {
                $st = $pdo->prepare("
                    SELECT b.*, r.name as room_name,
                           ir.room_number as individual_room_number, ir.room_name as individual_room_name
                    FROM bookings b
                    JOIN rooms r ON b.room_id = r.id
                    LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                    WHERE b.deleted_at IS NULL AND b.check_in_date = ? AND b.status IN ('confirmed', 'pending', 'checked-in', 'checked-out')
                    ORDER BY FIELD(b.status, 'pending', 'confirmed', 'checked-in', 'checked-out'), b.created_at ASC");
                $st->execute([$today]);
                $checkin_bookings = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $checkin_bookings = []; }
        }

        $roomSummary = ($mod_bookings || $mod_housekeeping) ? getRoomDashboardSummary() : [];
        $roomStatuses = ($mod_bookings || $mod_housekeeping) ? getRoomStatuses() : [];
        $statusCounts = $roomSummary['status_counts'] ?? [];
        $totalRooms = array_sum($statusCounts);
        $occupiedRooms = (int)($statusCounts['occupied'] ?? 0);
        $occupancy = (float)($roomSummary['occupancy_rate'] ?? 0);
        $noShows = (int)($roomSummary['no_show_candidates'] ?? 0);

        $arrivalsTotal = (int)$today_checkins + $dayProgress['arrived'];
        // Overdue guests (still in after their checkout date) count as departures still to do.
        $departuresTotal = (int)$today_checkouts + $dayProgress['departed'] + $overdueDepartures;
        $departuresStillToGo = (int)$today_checkouts + $overdueDepartures;
        $departuresOwing = 0;
        foreach ($departureList as $d) { if ($d['departure_state'] !== 'departed' && (float)$d['amount_due'] > 0.01) { $departuresOwing++; } }

        // ---- Needs-attention queue: only what is non-zero is shown loudly ----
        $attn = [];
        $add = static function (array &$list, bool $on, int $count, string $label, string $icon, string $tone, string $href, ?string $insight = null, string $hint = '') {
            if ($on) { $list[] = compact('count', 'label', 'icon', 'tone', 'href', 'insight', 'hint'); }
        };
        $add($attn, $mod_bookings, $noShows, 'Overdue arrivals (no-show?)', 'fa-user-clock', 'red', 'bookings.php?arrival=overdue', null, 'Arrival date passed, never checked in');
        $add($attn, $mod_bookings, $overdueDepartures, 'Overdue departures', 'fa-person-walking-luggage', 'red', 'bookings.php?filter=checked_in', null, 'Checkout date passed, still checked in');
        $add($attn, $mod_bookings, (int)$pending_bookings, 'Bookings awaiting confirmation', 'fa-hourglass-half', 'amber', 'bookings.php?status=pending', 'pending_bookings');
        $add($attn, $mod_bookings, $departuresOwing, 'Departures with a balance', 'fa-sack-dollar', 'red', 'bookings.php?filter=checkout_today', 'checkouts_today', 'Collect before they leave');
        $add($attn, $mod_finance, (int)$finance['refunds_pending'], 'Refunds to approve', 'fa-rotate-left', 'red', 'payments.php?refund_status=pending', 'refunds_pending');
        $add($attn, $mod_conference, (int)$pending_conference, 'Conference enquiries to answer', 'fa-users-gear', 'amber', 'conference-management.php?status=pending', 'pending_conference');
        $add($attn, $mod_website_cms, (int)$guestSvc['unread_contact'], 'Unread contact messages', 'fa-envelope', 'red', 'contact-inquiries.php');
        $add($attn, $mod_website_cms, (int)$guestSvc['pending_reviews'], 'Reviews to moderate', 'fa-star', 'amber', 'reviews.php?status=pending');
        $add($attn, $mod_website_cms && $mod_events, (int)$guestSvc['pending_events'], 'Event bookings pending', 'fa-calendar-check', 'amber', 'events-inquiries.php');
        $add($attn, $mod_gym, (int)$guestSvc['pending_gym'], 'Gym enquiries to answer', 'fa-dumbbell', 'amber', 'gym-inquiries.php');
        $add($attn, $mod_gym, (int)$gymDash['expiring_members'], 'Gym memberships expiring (30d)', 'fa-id-card', 'amber', 'gym-members.php?filter=expiring');
        $add($attn, $mod_housekeeping, (int)$guestSvc['maintenance_open'], 'Rooms out of service', 'fa-screwdriver-wrench', 'red', 'room-maintenance.php');
        $add($attn, $mod_housekeeping, (int)$guestSvc['housekeeping_due'], 'Housekeeping tasks due', 'fa-broom', 'amber', 'housekeeping.php');
        $add($attn, $mod_pos && $mod_bookings, (int)$ops['room_service_pending'], 'Room-service orders open', 'fa-bell-concierge', 'amber', 'stock-orders.php?type=room_service', 'room_service_pending');
        $add($attn, $mod_pos && $mod_bookings, (int)$ops['room_service_reminders_due'], 'Room-service reminders due', 'fa-bell', 'amber', 'stock-orders.php?type=room_service', 'room_service_reminders_due', 'Rooms with no order served today');
        $add($attn, $mod_stock && $mod_bookings, (int)$ops['open_tabs'], isRestaurantEnabled() ? 'Open tabs awaiting payment' : 'Orders awaiting payment', 'fa-receipt', 'amber', 'stock-orders.php?status=placed', 'open_tabs');
        $add($attn, $mod_stock, (int)$stock['expired_batches'], 'Expired stock batches', 'fa-skull-crossbones', 'red', 'stock-batches.php');
        $add($attn, $mod_stock, (int)$stock['low_stock'], 'Items below minimum stock', 'fa-box-open', 'amber', 'stock-reorder.php');
        $attnOpen = array_values(array_filter($attn, static fn($a) => $a['count'] > 0));
        $attnClear = array_values(array_filter($attn, static fn($a) => $a['count'] <= 0));
        usort($attnOpen, static fn($a, $b) => [$a['tone'] === 'red' ? 0 : 1, -$a['count']] <=> [$b['tone'] === 'red' ? 0 : 1, -$b['count']]);
        $attnTotal = array_sum(array_column($attnOpen, 'count'));

        // ---- Hero sentence ----
        $lede = [];
        if ($mod_bookings) {
            $lede[] = $arrivalsTotal . ' arrival' . ($arrivalsTotal === 1 ? '' : 's');
            $lede[] = $departuresTotal . ' departure' . ($departuresTotal === 1 ? '' : 's');
        } elseif ($mod_pos) {
            $lede[] = (int)$ops['orders_today'] . ' order' . ((int)$ops['orders_today'] === 1 ? '' : 's') . ' settled';
        } elseif ($mod_gym) {
            $lede[] = (int)$gymDash['active_members'] . ' active members';
        }
        if ($mod_conference && $today_conferences > 0) { $lede[] = $today_conferences . ' event' . ($today_conferences == 1 ? '' : 's'); }
        $ledeText = $lede ? implode(', ', $lede) . ' today' : 'Here is your day';
        $ledeText .= count($attnOpen) ? ' — ' . count($attnOpen) . ' thing' . (count($attnOpen) === 1 ? '' : 's') . ' need' . (count($attnOpen) === 1 ? 's' : '') . ' you.' : ' — nothing needs you right now.';

        // ---- Trend chart scaling ----
        $trendMax = max(1.0, ...array_values($revenueTrend ?: [0]));
        $trendTotal = array_sum($revenueTrend);
        $weekMax = max(1, ...array_values($weekAhead ?: [0]));
        $confByDay = [];
        foreach ($upcoming_conferences as $c) { $confByDay[$c['event_date']] = ($confByDay[$c['event_date']] ?? 0) + 1; }
        $hasMoney = $mod_finance || $mod_pos;
        $roomColors = [
            'occupied' => '#231F1C', 'available' => '#3f8f5a', 'cleaning' => '#c9a227',
            'inspection' => '#2f6fad', 'maintenance' => '#b4632f', 'out_of_order' => '#9aa1ab',
        ];
        ?>

        <div class="ck" data-ck-root>

        <!-- ============ HERO ============ -->
        <header class="ck-hero">
            <div class="ck-hero__intro">
                <p class="ck-eyebrow"><i class="far fa-calendar"></i> <?php echo date('l, j F Y'); ?> <span class="ck-dot"></span> <span id="ckClock"><?php echo date('H:i'); ?></span></p>
                <h1 class="ck-hero__title"><?php echo $greeting; ?><?php echo $firstName !== '' ? ', ' . htmlspecialchars($firstName) : ''; ?></h1>
                <p class="ck-hero__lede"><?php echo htmlspecialchars($ledeText); ?></p>
            </div>
            <nav class="ck-hero__actions" aria-label="Quick actions">
                <?php if ($mod_bookings && $can('create-booking.php')): ?>
                    <a class="ck-btn ck-btn--primary" href="create-booking.php"><i class="fas fa-plus"></i><span>New booking</span></a>
                <?php endif; ?>
                <?php if ($mod_bookings && $can('calendar.php')): ?>
                    <a class="ck-btn" href="calendar.php"><i class="far fa-calendar-days"></i><span>Calendar</span></a>
                <?php endif; ?>
                <?php if ($mod_pos && $can('pos.php')): ?>
                    <a class="ck-btn<?php echo $mod_bookings ? '' : ' ck-btn--primary'; ?>" href="pos.php" target="_blank" rel="noopener"><i class="fas fa-cash-register"></i><span>POS</span></a>
                <?php endif; ?>
                <?php if ($mod_gym && !$mod_bookings && $can('gym-checkin.php')): ?>
                    <a class="ck-btn ck-btn--primary" href="gym-checkin.php"><i class="fas fa-id-badge"></i><span>Member check-in</span></a>
                <?php endif; ?>
                <?php if ($can('end-of-day-report.php')): ?>
                    <a class="ck-btn" href="end-of-day-report.php"><i class="fas fa-file-invoice-dollar"></i><span>Day report</span></a>
                <?php endif; ?>
                <?php if ($mod_finance && $can('accounting-dashboard.php')): ?>
                    <a class="ck-btn" href="accounting-dashboard.php"><i class="fas fa-scale-balanced"></i><span>Accounting</span></a>
                <?php endif; ?>
                <details class="ck-menu">
                    <summary class="ck-btn ck-btn--ghost" aria-label="Guides"><i class="fas fa-book-open"></i><span>Guides</span><i class="fas fa-chevron-down ck-menu__caret"></i></summary>
                    <div class="ck-menu__list">
                        <a href="../docs/guides/index.html" target="_blank" rel="noopener"><i class="fas fa-book-open"></i> All guides</a>
                        <a href="../docs/guides/99-admin-dashboard-full-guide.html" target="_blank" rel="noopener"><i class="fas fa-scroll"></i> Admin reference</a>
                        <?php if ($mod_bookings): ?><a href="../docs/guides/07-reception-bookings.html" target="_blank" rel="noopener"><i class="fas fa-calendar-check"></i> Reception</a><?php endif; ?>
                        <?php if ($mod_pos): ?><a href="../docs/guides/01-pos-till.html" target="_blank" rel="noopener"><i class="fas fa-cash-register"></i> POS till</a><?php endif; ?>
                        <?php if ($mod_pos && $mod_station_kds): ?><a href="../docs/guides/02-kds-kitchen.html" target="_blank" rel="noopener"><i class="fas fa-utensils"></i> Kitchen (KDS)</a><?php endif; ?>
                        <?php if ($mod_pos && $mod_station_bds): ?><a href="../docs/guides/03-bds-bar.html" target="_blank" rel="noopener"><i class="fas fa-martini-glass"></i> Bar (BDS)</a><?php endif; ?>
                        <?php if ($mod_pos && $mod_station_cds): ?><a href="../docs/guides/04-cds-coffee.html" target="_blank" rel="noopener"><i class="fas fa-mug-hot"></i> Coffee (CDS)</a><?php endif; ?>
                        <?php if ($mod_pos && $mod_station_room_service): ?><a href="../docs/guides/05-room-service.html" target="_blank" rel="noopener"><i class="fas fa-bell-concierge"></i> Room service</a><?php endif; ?>
                        <?php if ($mod_housekeeping): ?><a href="../docs/guides/06-housekeeping.html" target="_blank" rel="noopener"><i class="fas fa-broom"></i> Housekeeping</a><?php endif; ?>
                        <?php if ($mod_stock): ?><a href="../docs/guides/08-stock-orders.html" target="_blank" rel="noopener"><i class="fas fa-boxes-stacked"></i> Stock</a><?php endif; ?>
                        <a href="../docs/guides/13-finance-payments.html" target="_blank" rel="noopener"><i class="fas fa-money-bill-wave"></i> Finance</a>
                        <a href="../docs/guides/14-reports-eod.html" target="_blank" rel="noopener"><i class="fas fa-chart-column"></i> Reports &amp; EOD</a>
                    </div>
                </details>
            </nav>
        </header>

        <?php if (hasPermission($uid, 'system_logs')): ?>
        <!-- System health: silent while all is well; appears only when something needs the owner's attention.
             Polls admin/api/system-health.php every 5 minutes. -->
        <div class="dashboard-health-alert" id="sysHealthAlert" role="status" hidden>
            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
            <div class="dashboard-health-alert__body">
                <strong>System needs attention</strong>
                <ul id="sysHealthIssues"></ul>
            </div>
            <div class="dashboard-health-alert__actions">
                <a href="backup-management.php" class="btn btn-sm btn-outline">Backups</a>
                <a href="system-logs.php" class="btn btn-sm btn-outline">System Logs</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============ KPI STRIP ============ -->
        <section class="ck-kpis" aria-label="Key figures">
            <?php if ($mod_bookings): ?>
                <?php if ($totalRooms > 0): ?>
                <a class="ck-kpi ck-kpi--dark js-dashboard-insight" data-insight-card="room_status_overview" data-insight-label="Room status" href="room-dashboard.php">
                    <span class="ck-kpi__icon"><i class="fas fa-bed"></i></span>
                    <span class="ck-kpi__label">Occupancy</span>
                    <span class="ck-kpi__value"><?php echo rtrim(rtrim(number_format($occupancy, 1), '0'), '.'); ?><small>%</small></span>
                    <span class="ck-meter"><span style="width:<?php echo min(100, $occupancy); ?>%"></span></span>
                    <span class="ck-kpi__sub"><?php echo $occupiedRooms; ?> of <?php echo $totalRooms; ?> rooms occupied</span>
                </a>
                <?php endif; ?>
                <a class="ck-kpi js-dashboard-insight" data-insight-card="checkins_today" data-insight-label="Today's check-ins" href="bookings.php?filter=checkin_today">
                    <span class="ck-kpi__icon"><i class="fas fa-plane-arrival"></i></span>
                    <span class="ck-kpi__label">Arrivals</span>
                    <span class="ck-kpi__value"><?php echo $dayProgress['arrived']; ?><small>/<?php echo $arrivalsTotal; ?></small></span>
                    <span class="ck-meter ck-meter--info"><span style="width:<?php echo $arrivalsTotal ? round($dayProgress['arrived'] / $arrivalsTotal * 100) : 0; ?>%"></span></span>
                    <span class="ck-kpi__sub"><?php echo (int)$today_checkins > 0 ? (int)$today_checkins . ' still to arrive' : ($arrivalsTotal ? 'Everyone has arrived' : 'No arrivals today'); ?></span>
                </a>
                <a class="ck-kpi js-dashboard-insight" data-insight-card="checkouts_today" data-insight-label="Today's check-outs" href="bookings.php?filter=checkout_today">
                    <span class="ck-kpi__icon"><i class="fas fa-plane-departure"></i></span>
                    <span class="ck-kpi__label">Departures</span>
                    <span class="ck-kpi__value"><?php echo $dayProgress['departed']; ?><small>/<?php echo $departuresTotal; ?></small></span>
                    <span class="ck-meter ck-meter--gold"><span style="width:<?php echo $departuresTotal ? round($dayProgress['departed'] / $departuresTotal * 100) : 0; ?>%"></span></span>
                    <span class="ck-kpi__sub"><?php echo $departuresStillToGo > 0 ? $departuresStillToGo . ' still to check out' . ($overdueDepartures ? ' (' . $overdueDepartures . ' overdue)' : '') : ($departuresTotal ? 'All checked out' : 'No departures today'); ?></span>
                </a>
                <a class="ck-kpi js-dashboard-insight" data-insight-card="inhouse_guests" data-insight-label="In-house guests" href="bookings.php?status=checked-in">
                    <span class="ck-kpi__icon"><i class="fas fa-people-roof"></i></span>
                    <span class="ck-kpi__label">In house</span>
                    <span class="ck-kpi__value"><?php echo (int)$current_guests; ?></span>
                    <span class="ck-kpi__sub">Bookings checked in now</span>
                </a>
            <?php elseif ($mod_pos): ?>
                <a class="ck-kpi ck-kpi--dark" href="pos.php" target="_blank" rel="noopener">
                    <span class="ck-kpi__icon"><i class="fas fa-receipt"></i></span>
                    <span class="ck-kpi__label">Orders today</span>
                    <span class="ck-kpi__value"><?php echo (int)$ops['orders_today']; ?></span>
                    <span class="ck-kpi__sub">Settled through the till</span>
                </a>
                <a class="ck-kpi js-dashboard-insight" data-insight-card="restaurant_revenue_today" data-insight-label="<?php echo isRestaurantEnabled() ? 'Restaurant revenue today' : 'POS revenue today'; ?>" href="reports.php?type=accounting&range=today">
                    <span class="ck-kpi__icon"><i class="fas fa-cash-register"></i></span>
                    <span class="ck-kpi__label"><?php echo isRestaurantEnabled() ? 'Restaurant sales' : 'POS sales'; ?></span>
                    <span class="ck-kpi__value ck-kpi__value--money"><?php echo $money($ops['restaurant_rev_today']); ?></span>
                    <span class="ck-kpi__sub">Gross settled today</span>
                </a>
                <?php if ($mod_stock): ?>
                <a class="ck-kpi<?php echo $ops['open_tabs'] > 0 ? ' ck-kpi--warn' : ''; ?> js-dashboard-insight" data-insight-card="open_tabs" data-insight-label="<?php echo isRestaurantEnabled() ? 'Open tabs' : 'Pending orders'; ?>" href="stock-orders.php?status=placed">
                    <span class="ck-kpi__icon"><i class="fas fa-hourglass-half"></i></span>
                    <span class="ck-kpi__label"><?php echo isRestaurantEnabled() ? 'Open tabs' : 'Pending orders'; ?></span>
                    <span class="ck-kpi__value"><?php echo (int)$ops['open_tabs']; ?></span>
                    <span class="ck-kpi__sub"><?php echo $money($ops['open_tabs_value']); ?> unpaid</span>
                </a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($mod_gym && !$mod_bookings): ?>
                <a class="ck-kpi<?php echo $mod_pos ? '' : ' ck-kpi--dark'; ?>" href="gym-members.php">
                    <span class="ck-kpi__icon"><i class="fas fa-id-card"></i></span>
                    <span class="ck-kpi__label">Active members</span>
                    <span class="ck-kpi__value"><?php echo (int)$gymDash['active_members']; ?></span>
                    <span class="ck-kpi__sub"><?php echo (int)$gymDash['expiring_members']; ?> renewing in 30 days</span>
                </a>
            <?php endif; ?>
            <?php if ($mod_gym && $mod_bookings): ?>
                <a class="ck-kpi" href="gym-members.php">
                    <span class="ck-kpi__icon"><i class="fas fa-dumbbell"></i></span>
                    <span class="ck-kpi__label">Gym members</span>
                    <span class="ck-kpi__value"><?php echo (int)$gymDash['active_members']; ?></span>
                    <span class="ck-kpi__sub"><?php echo (int)$gymDash['expiring_members']; ?> renewing in 30 days</span>
                </a>
            <?php endif; ?>
            <?php if ($mod_finance): ?>
                <a class="ck-kpi js-dashboard-insight" data-insight-card="total_revenue_today" data-insight-label="Total revenue today" href="payments.php?date=<?php echo $today; ?>">
                    <span class="ck-kpi__icon"><i class="fas fa-coins"></i></span>
                    <span class="ck-kpi__label">Takings today</span>
                    <span class="ck-kpi__value ck-kpi__value--money"><?php echo $money($finance['revenue_today']); ?></span>
                    <span class="ck-spark" aria-hidden="true">
                        <?php foreach ($revenueTrend as $d => $v): ?><span style="height:<?php echo max(6, round($v / $trendMax * 100)); ?>%"<?php echo $d === $today ? ' class="is-today"' : ''; ?>></span><?php endforeach; ?>
                    </span>
                    <span class="ck-kpi__sub"><?php echo (int)$finance['payments_today']; ?> payment(s)<?php echo $mod_pos ? ' + till' : ''; ?></span>
                </a>
                <?php if ($mod_receivables): ?>
                <a class="ck-kpi<?php echo $finance['outstanding'] > 0.01 ? ' ck-kpi--alert' : ''; ?> js-dashboard-insight" data-insight-card="outstanding_balances" data-insight-label="Outstanding balances" href="payments.php?balance=outstanding">
                    <span class="ck-kpi__icon"><i class="fas fa-file-invoice-dollar"></i></span>
                    <span class="ck-kpi__label">Owed to us</span>
                    <span class="ck-kpi__value ck-kpi__value--money"><?php echo $money($finance['outstanding']); ?></span>
                    <span class="ck-kpi__sub"><?php echo (int)$finance['outstanding_count']; ?> <?php echo $mod_bookings ? 'booking(s)' : 'account(s)'; ?> with a balance</span>
                </a>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <!-- ============ BENTO ============ -->
        <div class="ck-bento">

            <?php
            $todayTabs = [];
            if ($mod_bookings) {
                $todayTabs['arrivals'] = ['Arrivals', count($checkin_bookings)];
                $todayTabs['departures'] = ['Departures', count($departureList)];
            }
            if ($mod_conference) { $todayTabs['events'] = ['Events', count($today_conference_events)]; }
            if ($mod_pos && $mod_bookings) { $todayTabs['roomservice'] = ['Room service', count($roomServiceQueue)]; }
            ?>
            <?php if ($todayTabs): ?>
            <!-- Today: the front-desk worklist -->
            <section class="ck-panel ck-span-2 ck-today" data-ck-tabs>
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Today's movements</h2>
                        <p class="ck-panel__sub">Act straight from the list — tap a row for the full record.</p>
                    </div>
                    <div class="ck-tabs" role="tablist">
                        <?php $firstTab = true; foreach ($todayTabs as $key => [$label, $n]): ?>
                            <button type="button" class="ck-tab<?php echo $firstTab ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $firstTab ? 'true' : 'false'; ?>" data-ck-tab="<?php echo $key; ?>">
                                <?php echo $label; ?> <span class="ck-tab__count"><?php echo (int)$n; ?></span>
                            </button>
                        <?php $firstTab = false; endforeach; ?>
                    </div>
                </header>

                <?php $firstPane = array_key_first($todayTabs); ?>
                <?php if ($mod_bookings): ?>
                <div class="ck-pane" role="tabpanel" data-ck-pane="arrivals"<?php echo $firstPane === 'arrivals' ? '' : ' hidden'; ?>>
                    <?php if ($checkin_bookings): ?>
                    <ul class="ck-rows">
                        <?php foreach ($checkin_bookings as $booking):
                            $can_checkin = ($booking['status'] === 'confirmed' && $booking['payment_status'] === 'paid');
                            $guestJs = htmlspecialchars(addslashes($booking['guest_name']), ENT_QUOTES, 'UTF-8'); ?>
                        <li class="ck-row" id="checkin-row-<?php echo (int)$booking['id']; ?>">
                            <span class="ck-avatar"><?php echo htmlspecialchars($initials((string)$booking['guest_name'])); ?></span>
                            <a class="ck-row__main" href="booking-details.php?id=<?php echo (int)$booking['id']; ?>">
                                <strong><?php echo htmlspecialchars($booking['guest_name']); ?></strong>
                                <span><?php echo htmlspecialchars($booking['booking_reference']); ?> · <?php echo htmlspecialchars($booking['room_name']); ?> · <?php echo (int)$booking['number_of_nights']; ?> night<?php echo (int)$booking['number_of_nights'] === 1 ? '' : 's'; ?></span>
                            </a>
                            <span class="ck-chip<?php echo $roomLabel($booking) === 'Unassigned' ? ' ck-chip--muted' : ''; ?>"><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($roomLabel($booking)); ?></span>
                            <span class="ck-row__meta">
                                <span class="badge badge-<?php echo htmlspecialchars($booking['status']); ?>" id="status-<?php echo (int)$booking['id']; ?>"><?php echo ucfirst(htmlspecialchars($booking['status'])); ?></span>
                                <?php if ((float)$booking['amount_due'] > 0.01): ?>
                                    <small class="ck-due">Due <?php echo $money($booking['amount_due']); ?></small>
                                <?php else: ?>
                                    <small class="ck-paid"><i class="fas fa-check"></i> Paid</small>
                                <?php endif; ?>
                            </span>
                            <span class="ck-row__act">
                                <?php if (in_array($booking['status'], ['checked-in', 'checked-out'], true)): ?>
                                <a class="ck-btn ck-btn--sm" href="booking-details.php?id=<?php echo (int)$booking['id']; ?>"><i class="fas fa-eye"></i> View</a>
                                <?php else: ?>
                                <button type="button" id="checkin-btn-<?php echo (int)$booking['id']; ?>"
                                    class="ck-btn ck-btn--sm <?php echo $can_checkin ? 'ck-btn--primary' : ''; ?>"
                                    <?php echo $can_checkin ? '' : 'aria-disabled="true"'; ?>
                                    onclick="<?php echo $can_checkin ? "processCheckIn(" . (int)$booking['id'] . ", '" . $guestJs . "')" : "Alert.show('Cannot check in: booking must be CONFIRMED and PAID.', 'error')"; ?>">
                                    <i class="fas fa-right-to-bracket"></i> Check in
                                </button>
                                <?php endif; ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                        <div class="ck-empty"><i class="fas fa-mug-saucer"></i><p><?php echo $dayProgress['arrived'] ? 'All ' . $dayProgress['arrived'] . ' arrivals are checked in.' : 'No arrivals scheduled for today.'; ?></p></div>
                    <?php endif; ?>
                </div>

                <div class="ck-pane" role="tabpanel" data-ck-pane="departures" hidden>
                    <?php if ($departureList): ?>
                    <ul class="ck-rows">
                        <?php foreach ($departureList as $d): $dState = $d['departure_state']; $owes = $dState !== 'departed' && (float)$d['amount_due'] > 0.01; ?>
                        <li class="ck-row">
                            <span class="ck-avatar ck-avatar--gold"><?php echo htmlspecialchars($initials((string)$d['guest_name'])); ?></span>
                            <a class="ck-row__main" href="booking-details.php?id=<?php echo (int)$d['id']; ?>">
                                <strong><?php echo htmlspecialchars($d['guest_name']); ?></strong>
                                <span><?php echo htmlspecialchars($d['booking_reference']); ?> · <?php echo htmlspecialchars($d['room_name']); ?> · since <?php echo date('j M', strtotime($d['check_in_date'])); ?></span>
                            </a>
                            <span class="ck-chip"><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($roomLabel($d)); ?></span>
                            <span class="ck-row__meta">
                                <?php if ($dState === 'departed'): ?>
                                    <span class="badge badge-checked-out">Checked out</span>
                                <?php elseif ($dState === 'overdue'): ?>
                                    <span class="ck-due ck-due--strong">Overdue · was due <?php echo date('j M', strtotime($d['check_out_date'])); ?></span>
                                <?php endif; ?>
                                <?php if ($owes): ?>
                                    <span class="ck-due ck-due--strong">Owes <?php echo $money($d['amount_due']); ?></span>
                                <?php elseif ($dState !== 'departed'): ?>
                                    <small class="ck-paid"><i class="fas fa-check"></i> Settled</small>
                                <?php endif; ?>
                            </span>
                            <span class="ck-row__act">
                                <?php if ($dState === 'departed'): ?>
                                <a class="ck-btn ck-btn--sm" href="booking-details.php?id=<?php echo (int)$d['id']; ?>"><i class="fas fa-eye"></i> View</a>
                                <?php else: ?>
                                <a class="ck-btn ck-btn--sm <?php echo $owes ? '' : 'ck-btn--primary'; ?>" href="booking-details.php?id=<?php echo (int)$d['id']; ?>">
                                    <i class="fas <?php echo $owes ? 'fa-hand-holding-dollar' : 'fa-right-from-bracket'; ?>"></i> <?php echo $owes ? 'Settle &amp; check out' : 'Check out'; ?>
                                </a>
                                <?php endif; ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                        <div class="ck-empty"><i class="fas fa-suitcase-rolling"></i><p><?php echo $dayProgress['departed'] ? 'All ' . $dayProgress['departed'] . ' departures are checked out.' : 'No departures due today.'; ?></p></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($mod_conference): ?>
                <div class="ck-pane" role="tabpanel" data-ck-pane="events"<?php echo $firstPane === 'events' ? '' : ' hidden'; ?>>
                    <?php if ($today_conference_events): ?>
                    <ol class="ck-timeline">
                        <?php foreach ($today_conference_events as $conf): ?>
                        <li class="ck-timeline__item">
                            <span class="ck-timeline__time"><?php echo date('H:i', strtotime($conf['start_time'])); ?></span>
                            <a class="ck-timeline__card" href="conference-management.php">
                                <strong><?php echo htmlspecialchars($conf['company_name']); ?></strong>
                                <span><?php echo date('H:i', strtotime($conf['start_time'])); ?>–<?php echo date('H:i', strtotime($conf['end_time'])); ?> · <?php echo htmlspecialchars($conf['room_name'] ?? 'Room TBC'); ?> · <?php echo (int)$conf['number_of_attendees']; ?> guests · <?php echo htmlspecialchars($conf['contact_person']); ?></span>
                                <span class="badge badge-<?php echo htmlspecialchars($conf['status']); ?>"><?php echo ucfirst(htmlspecialchars($conf['status'])); ?></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                    <?php else: ?>
                        <div class="ck-empty"><i class="fas fa-calendar-xmark"></i><p>No conference events today.</p></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($mod_pos && $mod_bookings): ?>
                <div class="ck-pane" role="tabpanel" data-ck-pane="roomservice" hidden>
                    <?php if ($roomServiceQueue): ?>
                    <ul class="ck-rows">
                        <?php foreach ($roomServiceQueue as $rs): $age = (int)$rs['age_min']; $ageTone = $age >= 30 ? 'red' : ($age >= 15 ? 'amber' : 'green'); ?>
                        <li class="ck-row">
                            <span class="ck-avatar ck-avatar--soft"><i class="fas fa-bell-concierge"></i></span>
                            <a class="ck-row__main" href="stock-orders.php?id=<?php echo (int)$rs['id']; ?>">
                                <strong>Room <?php echo htmlspecialchars($rs['room_number'] ?? '—'); ?> · <?php echo htmlspecialchars($rs['customer_name'] ?? 'Guest'); ?></strong>
                                <span><?php echo htmlspecialchars($rs['reference']); ?> · <?php echo (int)$rs['item_count']; ?> item(s) · <?php echo $money($rs['total_amount']); ?></span>
                            </a>
                            <span class="ck-pill ck-pill--<?php echo $ageTone; ?>"><i class="far fa-clock"></i> <?php echo rh_format_age($age); ?></span>
                            <span class="ck-row__meta"><span class="badge badge-<?php echo htmlspecialchars($rs['status']); ?>"><?php echo ucfirst(htmlspecialchars($rs['status'])); ?></span></span>
                            <span class="ck-row__act">
                                <a href="pos.php?settle=<?php echo (int)$rs['id']; ?>" target="_blank" rel="noopener" class="ck-btn ck-btn--sm ck-btn--primary"><i class="fas fa-credit-card"></i> Take payment</a>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                        <div class="ck-empty"><i class="fas fa-bell-concierge"></i><p>No room-service orders in flight.</p></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <!-- Needs attention -->
            <section class="ck-panel ck-attn<?php echo $todayTabs ? ' ck-rows-2' : ''; ?>">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Needs attention <?php if ($attnTotal): ?><span class="ck-badge"><?php echo (int)$attnTotal; ?></span><?php endif; ?></h2>
                        <p class="ck-panel__sub">Most urgent first.</p>
                    </div>
                    <div class="ck-panel__tools">
                        <?php if ($mod_website_cms || $mod_gym || $mod_bookings): ?>
                            <button type="button" class="ck-icon-btn js-dashboard-insight" data-insight-card="guest_services_queue" data-insight-label="Guest services queue" title="Guest services overview"><i class="fas fa-headset"></i></button>
                        <?php endif; ?>
                        <?php if ($mod_bookings || $mod_housekeeping || $mod_pos || $mod_finance): ?>
                            <button type="button" class="ck-icon-btn js-dashboard-insight" data-insight-card="operations_facilities" data-insight-label="Operations &amp; facilities" title="Operations overview"><i class="fas fa-screwdriver-wrench"></i></button>
                        <?php endif; ?>
                    </div>
                </header>
                <?php if ($attnOpen): ?>
                <ul class="ck-attn__list">
                    <?php foreach ($attnOpen as $i => $a): ?>
                    <li>
                        <a class="ck-attn__item ck-attn__item--<?php echo $a['tone']; ?><?php echo $i === 0 ? ' is-top' : ''; ?><?php echo $a['insight'] ? ' js-dashboard-insight' : ''; ?>"
                           href="<?php echo htmlspecialchars($a['href']); ?>"
                           <?php if ($a['insight']): ?>data-insight-card="<?php echo htmlspecialchars($a['insight']); ?>" data-insight-label="<?php echo htmlspecialchars($a['label']); ?>"<?php endif; ?>>
                            <span class="ck-attn__icon"><i class="fas <?php echo $a['icon']; ?>"></i></span>
                            <span class="ck-attn__text">
                                <strong><?php echo htmlspecialchars($a['label']); ?></strong>
                                <?php if ($a['hint']): ?><small><?php echo htmlspecialchars($a['hint']); ?></small><?php endif; ?>
                            </span>
                            <span class="ck-attn__count"><?php echo (int)$a['count']; ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                    <div class="ck-empty ck-empty--good"><i class="fas fa-circle-check"></i><p><strong>All clear.</strong> Nothing is waiting on you.</p></div>
                <?php endif; ?>
                <?php if ($attnClear): ?>
                <details class="ck-clear">
                    <summary><i class="fas fa-check"></i> <?php echo count($attnClear); ?> queue<?php echo count($attnClear) === 1 ? '' : 's'; ?> clear</summary>
                    <div class="ck-clear__chips">
                        <?php foreach ($attnClear as $a): ?>
                            <a href="<?php echo htmlspecialchars($a['href']); ?>" class="ck-chip ck-chip--muted"><i class="fas <?php echo $a['icon']; ?>"></i> <?php echo htmlspecialchars($a['label']); ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>
            </section>

            <?php if ($hasMoney): ?>
            <!-- Money -->
            <section class="ck-panel ck-money">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Money</h2>
                        <p class="ck-panel__sub">Last 7 days · <?php echo $moneyShort($trendTotal); ?> taken</p>
                    </div>
                    <div class="ck-panel__tools">
                        <?php if ($can('end-of-day-report.php')): ?><a class="ck-link" href="end-of-day-report.php">Day report <i class="fas fa-arrow-right"></i></a><?php endif; ?>
                    </div>
                </header>
                <div class="ck-money__headline">
                    <span class="ck-money__label" data-ck-trend-label>Today</span>
                    <span class="ck-money__value" data-ck-trend-value><?php echo $money($revenueTrend[$today] ?? 0); ?></span>
                </div>
                <div class="ck-bars" role="list" aria-label="Takings for the last 7 days">
                    <?php foreach ($revenueTrend as $d => $v): $isToday = $d === $today; ?>
                        <button type="button" role="listitem" class="ck-bars__col<?php echo $isToday ? ' is-today is-active' : ''; ?>"
                            data-ck-trend="<?php echo htmlspecialchars($cur . number_format($v, 2)); ?>"
                            data-ck-trend-day="<?php echo $isToday ? 'Today' : date('l j M', strtotime($d)); ?>"
                            aria-label="<?php echo date('l j M', strtotime($d)) . ': ' . htmlspecialchars($cur . number_format($v, 2)); ?>">
                            <span class="ck-bars__bar"><span style="height:<?php echo max(3, round($v / $trendMax * 100)); ?>%"></span></span>
                            <span class="ck-bars__day"><?php echo $isToday ? 'Today' : date('D', strtotime($d)); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <ul class="ck-kv">
                    <?php if ($mod_finance && $mod_pos): ?>
                        <li><span><i class="fas fa-bed"></i> <?php echo $mod_bookings ? 'Rooms &amp; services' : 'Invoiced sales'; ?> today</span><strong><?php echo $money(max(0, $finance['revenue_today'] - $ops['restaurant_rev_today'])); ?></strong></li>
                    <?php endif; ?>
                    <?php if ($mod_pos): ?>
                        <li><a class="js-dashboard-insight" data-insight-card="restaurant_revenue_today" data-insight-label="Till sales today" href="reports.php?type=accounting&range=today"><span><i class="fas fa-cash-register"></i> <?php echo isRestaurantEnabled() ? 'Restaurant &amp; bar' : 'Till sales'; ?> today · <?php echo (int)$ops['orders_today']; ?> order(s)</span><strong><?php echo $money($ops['restaurant_rev_today']); ?></strong></a></li>
                    <?php endif; ?>
                    <?php if ($mod_stock && $mod_bookings): ?>
                        <li><a class="js-dashboard-insight" data-insight-card="open_tabs" data-insight-label="Open tabs" href="stock-orders.php?status=placed"><span><i class="fas fa-receipt"></i> <?php echo (int)$ops['open_tabs']; ?> open tab(s)</span><strong><?php echo $money($ops['open_tabs_value']); ?></strong></a></li>
                    <?php endif; ?>
                    <?php if ($mod_finance): ?>
                        <li><a class="js-dashboard-insight" data-insight-card="refunds_pending" data-insight-label="Refunds pending" href="payments.php?refund_status=pending"><span><i class="fas fa-rotate-left"></i> Refunds pending</span><strong><?php echo (int)$finance['refunds_pending']; ?></strong></a></li>
                    <?php endif; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($totalRooms > 0): ?>
            <!-- Rooms -->
            <section class="ck-panel ck-rooms">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Rooms</h2>
                        <p class="ck-panel__sub"><?php echo (int)($statusCounts['available'] ?? 0); ?> ready to sell<?php if (!empty($roomSummary['cleaning_queue'])): ?> · <?php echo (int)$roomSummary['cleaning_queue']; ?> to clean<?php endif; ?></p>
                    </div>
                    <div class="ck-panel__tools">
                        <?php if ($can('room-dashboard.php')): ?><a class="ck-link" href="room-dashboard.php">Room board <i class="fas fa-arrow-right"></i></a><?php endif; ?>
                    </div>
                </header>
                <?php
                $stops = []; $acc = 0.0;
                foreach ($roomStatuses as $status => $info) {
                    $n = (int)($statusCounts[$status] ?? 0);
                    if ($n <= 0) { continue; }
                    $from = $acc; $acc += $n / $totalRooms * 100;
                    $stops[] = ($roomColors[$status] ?? '#ccc') . ' ' . round($from, 2) . '% ' . round($acc, 2) . '%';
                }
                ?>
                <div class="ck-rooms__body">
                    <button type="button" class="ck-donut js-dashboard-insight" data-insight-card="room_status_overview" data-insight-label="Room status"
                        style="--ck-donut: conic-gradient(<?php echo htmlspecialchars(implode(', ', $stops)); ?>);" aria-label="Room status overview">
                        <span class="ck-donut__hole"><strong><?php echo rtrim(rtrim(number_format($occupancy, 1), '0'), '.'); ?>%</strong><small>occupied</small></span>
                    </button>
                    <ul class="ck-legend">
                        <?php foreach ($roomStatuses as $status => $info): $n = (int)($statusCounts[$status] ?? 0); ?>
                        <li>
                            <button type="button" class="ck-legend__item js-dashboard-insight<?php echo $n ? '' : ' is-zero'; ?>"
                                data-insight-card="room_status_<?php echo htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8'); ?>"
                                data-insight-label="<?php echo htmlspecialchars((string)$info['label'], ENT_QUOTES, 'UTF-8'); ?> rooms">
                                <span class="ck-legend__dot" style="background:<?php echo $roomColors[$status] ?? '#ccc'; ?>"></span>
                                <span class="ck-legend__label"><?php echo htmlspecialchars((string)$info['label']); ?></span>
                                <strong><?php echo $n; ?></strong>
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($mod_bookings || $mod_conference): ?>
            <!-- Week ahead -->
            <section class="ck-panel ck-span-2 ck-week" data-ck-week>
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Week ahead</h2>
                        <p class="ck-panel__sub"><?php echo count($upcoming_checkins); ?> arrival(s)<?php echo $mod_conference ? ' · ' . count($upcoming_conferences) . ' event(s)' : ''; ?> in the next 7 days — tap a day to filter.</p>
                    </div>
                    <div class="ck-panel__tools">
                        <button type="button" class="ck-link" data-ck-day="" hidden>Show all</button>
                        <?php if ($mod_bookings && $can('calendar.php')): ?><a class="ck-link" href="calendar.php">Calendar <i class="fas fa-arrow-right"></i></a><?php endif; ?>
                    </div>
                </header>
                <div class="ck-days">
                    <?php foreach ($weekAhead as $d => $n): $ev = (int)($confByDay[$d] ?? 0); ?>
                        <button type="button" class="ck-day<?php echo ($n + $ev) ? '' : ' is-empty'; ?>" data-ck-day="<?php echo $d; ?>">
                            <span class="ck-day__name"><?php echo date('D', strtotime($d)); ?></span>
                            <span class="ck-day__date"><?php echo date('j', strtotime($d)); ?></span>
                            <span class="ck-day__bar"><span style="height:<?php echo round($n / $weekMax * 100); ?>%"></span></span>
                            <span class="ck-day__count"><?php echo $n; ?><?php if ($ev): ?><i title="<?php echo $ev; ?> event(s)"> +<?php echo $ev; ?>ev</i><?php endif; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php if ($upcoming_checkins || $upcoming_conferences): ?>
                <ul class="ck-rows ck-rows--compact">
                    <?php foreach ($upcoming_checkins as $booking): ?>
                    <li class="ck-row" data-ck-day-row="<?php echo htmlspecialchars($booking['check_in_date']); ?>">
                        <span class="ck-date"><b><?php echo date('j', strtotime($booking['check_in_date'])); ?></b><?php echo date('M', strtotime($booking['check_in_date'])); ?></span>
                        <a class="ck-row__main" href="booking-details.php?id=<?php echo (int)$booking['id']; ?>">
                            <strong><?php echo htmlspecialchars($booking['guest_name']); ?></strong>
                            <span><?php echo htmlspecialchars($booking['booking_reference']); ?> · <?php echo htmlspecialchars($booking['room_name']); ?> · <?php echo (int)$booking['number_of_nights']; ?> night(s)</span>
                        </a>
                        <span class="ck-chip<?php echo $roomLabel($booking) === 'Unassigned' ? ' ck-chip--muted' : ''; ?>"><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($roomLabel($booking)); ?></span>
                        <span class="ck-row__meta">
                            <span class="badge badge-<?php echo htmlspecialchars($booking['status']); ?>"><?php echo ucfirst(htmlspecialchars($booking['status'])); ?></span>
                            <?php if ((float)$booking['amount_due'] > 0.01): ?><small class="ck-due">Due <?php echo $money($booking['amount_due']); ?></small><?php else: ?><small class="ck-paid"><i class="fas fa-check"></i> Paid</small><?php endif; ?>
                        </span>
                        <span class="ck-row__act">
                            <?php if ($booking['status'] === 'pending'): ?>
                                <a href="booking-details.php?id=<?php echo (int)$booking['id']; ?>&action=confirm" class="ck-btn ck-btn--sm ck-btn--primary">Confirm</a>
                            <?php else: ?>
                                <a href="booking-details.php?id=<?php echo (int)$booking['id']; ?>" class="ck-btn ck-btn--sm">View</a>
                            <?php endif; ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                    <?php foreach ($upcoming_conferences as $conf): if ($conf['event_date'] === $today) { continue; } ?>
                    <li class="ck-row" data-ck-day-row="<?php echo htmlspecialchars($conf['event_date']); ?>">
                        <span class="ck-date ck-date--event"><b><?php echo date('j', strtotime($conf['event_date'])); ?></b><?php echo date('M', strtotime($conf['event_date'])); ?></span>
                        <a class="ck-row__main" href="conference-management.php">
                            <strong><?php echo htmlspecialchars($conf['company_name']); ?></strong>
                            <span><?php echo htmlspecialchars($conf['inquiry_reference']); ?> · <?php echo date('H:i', strtotime($conf['start_time'])); ?>–<?php echo date('H:i', strtotime($conf['end_time'])); ?> · <?php echo (int)$conf['number_of_attendees']; ?> guests</span>
                        </a>
                        <span class="ck-chip ck-chip--event"><i class="fas fa-users"></i> Event</span>
                        <span class="ck-row__meta"><span class="badge badge-<?php echo htmlspecialchars($conf['status']); ?>"><?php echo ucfirst(htmlspecialchars($conf['status'])); ?></span></span>
                        <span class="ck-row__act"><a href="conference-management.php" class="ck-btn ck-btn--sm">Manage</a></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="ck-empty" data-ck-day-empty hidden><i class="far fa-calendar"></i><p>Nothing booked for this day.</p></div>
                <?php else: ?>
                    <div class="ck-empty"><i class="far fa-calendar"></i><p>Nothing booked in the next 7 days.</p></div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($mod_stock): ?>
            <!-- Stock -->
            <section class="ck-panel ck-stock">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Stock</h2>
                        <p class="ck-panel__sub"><?php echo $stock['wastage_today'] > 0 ? 'Wastage today ' . $money($stock['wastage_today']) : 'No wastage logged today'; ?></p>
                    </div>
                    <div class="ck-panel__tools">
                        <button type="button" class="ck-icon-btn js-dashboard-insight" data-insight-card="stock_health" data-insight-label="Stock health" title="Stock health overview"><i class="fas fa-chart-simple"></i></button>
                        <a class="ck-link" href="stock-orders.php?view=stock">Manage <i class="fas fa-arrow-right"></i></a>
                    </div>
                </header>
                <div class="ck-stock__trio">
                    <a href="stock-reorder.php" class="ck-mini<?php echo $stock['low_stock'] ? ' ck-mini--amber' : ''; ?>"><strong><?php echo (int)$stock['low_stock']; ?></strong><span>Low</span></a>
                    <a href="stock-batches.php" class="ck-mini<?php echo $stock['expiring_batches'] ? ' ck-mini--amber' : ''; ?>"><strong><?php echo (int)$stock['expiring_batches']; ?></strong><span>Expiring 7d</span></a>
                    <a href="stock-batches.php" class="ck-mini<?php echo $stock['expired_batches'] ? ' ck-mini--red' : ''; ?>"><strong><?php echo (int)$stock['expired_batches']; ?></strong><span>Expired</span></a>
                </div>
                <?php if (!empty($stock['low_items'])): ?>
                <ul class="ck-levels">
                    <?php foreach (array_slice($stock['low_items'], 0, 5) as $li): $pct = (float)$li['min_quantity'] > 0 ? min(100, (float)$li['current_quantity'] / (float)$li['min_quantity'] * 100) : 0; ?>
                    <li>
                        <span class="ck-levels__name"><?php echo htmlspecialchars($li['name']); ?></span>
                        <span class="ck-levels__qty"><?php echo number_format((float)$li['current_quantity'], 1); ?> / <?php echo number_format((float)$li['min_quantity'], 1); ?> <?php echo htmlspecialchars($li['unit']); ?></span>
                        <span class="ck-meter ck-meter--<?php echo $pct < 34 ? 'red' : 'amber'; ?>"><span style="width:<?php echo round($pct); ?>%"></span></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                    <div class="ck-empty ck-empty--good"><i class="fas fa-circle-check"></i><p>All <?php echo isRestaurantEnabled() ? 'ingredients' : 'stock items'; ?> are above minimum.</p></div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

        </div><!-- /.ck-bento -->
        </div><!-- /.ck -->

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
                <a id="dashboardInsightLink" class="btn btn-primary dashboard-insight-modal__link" href="dashboard.php">Open full page</a>
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
            const cardLabel = cardEl.dataset.insightLabel || cardEl.querySelector('.ck-kpi__label')?.textContent?.trim() || 'Loading details…';
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

        async function processCheckIn(bookingId, guestName, roomOverride) {
            const confirmed = roomOverride || await dashboardConfirm({
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
            if (roomOverride) formData.append('confirm_checkin_room_not_ready', '1');

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
                            button.outerHTML = `<button onclick="cancelCheckIn(${bookingId}, '${guestName.replace(/'/g, "\\'")}')" id="cancel-checkin-btn-${bookingId}" type="button" class="ck-btn ck-btn--sm"><i class="fas fa-rotate-left"></i> Undo check-in</button>`;
                        }

                        if (window.AdminPageLoader) AdminPageLoader.hide();
                        Alert.show(`${guestName} successfully checked in!`, 'success');
                    } else {
                        if (window.AdminPageLoader) AdminPageLoader.hide();
                        if (window.ButtonLoader && actionButton) ButtonLoader.hide(actionButton);
                        if (data.needs_confirm_room && !roomOverride) {
                            dashboardConfirm({ title: 'Room not marked clean', message: data.message, confirmText: 'Check in anyway', icon: 'fa-broom' })
                                .then(ok => { if (ok) processCheckIn(bookingId, guestName, true); });
                            return;
                        }
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
                            button.outerHTML = `<span class="ck-pill ck-pill--amber">Reverted</span>`;
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
        // Cockpit interactions: tabs, week-ahead day filter, takings readout, clock
        // -------------------------------------------------------------------
        (function () {
            'use strict';
            document.querySelectorAll('[data-ck-tabs]').forEach(function (panel) {
                const tabs = panel.querySelectorAll('[data-ck-tab]');
                tabs.forEach(function (tab) {
                    tab.addEventListener('click', function () {
                        tabs.forEach(function (t) {
                            const on = t === tab;
                            t.classList.toggle('is-active', on);
                            t.setAttribute('aria-selected', on ? 'true' : 'false');
                        });
                        panel.querySelectorAll('[data-ck-pane]').forEach(function (pane) {
                            pane.hidden = pane.dataset.ckPane !== tab.dataset.ckTab;
                        });
                    });
                });
            });

            document.querySelectorAll('[data-ck-week]').forEach(function (week) {
                const rows = week.querySelectorAll('[data-ck-day-row]');
                const empty = week.querySelector('[data-ck-day-empty]');
                const reset = week.querySelector('.ck-panel__tools [data-ck-day]');
                function filter(day) {
                    let shown = 0;
                    rows.forEach(function (row) {
                        const on = day === '' || row.dataset.ckDayRow === day;
                        row.hidden = !on;
                        if (on) shown++;
                    });
                    week.querySelectorAll('.ck-days [data-ck-day]').forEach(function (b) {
                        b.classList.toggle('is-active', b.dataset.ckDay === day);
                    });
                    if (empty) empty.hidden = shown > 0 || rows.length === 0;
                    if (reset) reset.hidden = day === '';
                }
                week.querySelectorAll('[data-ck-day]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        filter(btn.classList.contains('is-active') ? '' : btn.dataset.ckDay);
                    });
                });
            });

            document.querySelectorAll('.ck-money').forEach(function (box) {
                const label = box.querySelector('[data-ck-trend-label]');
                const value = box.querySelector('[data-ck-trend-value]');
                const cols = box.querySelectorAll('[data-ck-trend]');
                cols.forEach(function (col) {
                    function show() {
                        cols.forEach(function (c) { c.classList.toggle('is-active', c === col); });
                        if (label) label.textContent = col.dataset.ckTrendDay;
                        if (value) value.textContent = col.dataset.ckTrend;
                    }
                    col.addEventListener('mouseenter', show);
                    col.addEventListener('focus', show);
                    col.addEventListener('click', show);
                });
            });

            const clock = document.getElementById('ckClock');
            if (clock) {
                setInterval(function () {
                    const d = new Date();
                    clock.textContent = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
                }, 30000);
            }

            document.addEventListener('click', function (e) {
                document.querySelectorAll('.ck-menu[open]').forEach(function (m) {
                    if (!m.contains(e.target)) m.removeAttribute('open');
                });
            });
        })();

        // -------------------------------------------------------------------
        // System health: stays hidden unless something needs attention
        // -------------------------------------------------------------------
        (function () {
            'use strict';
            const box  = document.getElementById('sysHealthAlert');
            const list = document.getElementById('sysHealthIssues');
            if (!box || !list) return;
            const CHECK_SWEEP = <?php echo $mod_bookings ? 'true' : 'false'; ?>;
            const POLL_MS = 300000;

            function hoursSince(iso) {
                return Math.round((Date.now() - new Date(iso).getTime()) / 3600000);
            }

            async function check() {
                let d;
                try {
                    const res = await fetch('api/system-health.php', {
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!res.ok) return;
                    d = await res.json();
                } catch (err) {
                    console.error('[SysHealth]', err);
                    return;
                }
                const issues = [];
                if (d.db !== 'ok') issues.push('The database is not responding.');
                if (!d.last_backup_at) {
                    issues.push('No backup has been taken yet.');
                } else if (d.last_backup_age_hours !== null && d.last_backup_age_hours >= 36) {
                    issues.push('The last backup was ' + d.last_backup_age_hours + ' hours ago.');
                }
                if (d.disk_free_pct !== null && d.disk_free_pct <= 10) {
                    issues.push('Server disk space is low (' + d.disk_free_pct + '% free).');
                }
                if (CHECK_SWEEP && d.last_tentative_sweep_at && hoursSince(d.last_tentative_sweep_at) >= 25) {
                    issues.push('Unpaid booking holds have not been released for ' + hoursSince(d.last_tentative_sweep_at) + ' hours.');
                }
                list.innerHTML = '';
                issues.forEach(function (text) {
                    const li = document.createElement('li');
                    li.textContent = text;
                    list.appendChild(li);
                });
                box.hidden = issues.length === 0;
            }

            check();
            setInterval(check, POLL_MS);
        })();
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>

