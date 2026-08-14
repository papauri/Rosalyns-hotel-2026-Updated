<?php

/**
 * End of Day Report — Daily operations & revenue snapshot.
 *
 * One screen, one click. Pulls the most useful operational and financial
 * KPIs for a single day, designed to be emailed/WhatsApped to owners
 * every evening.
 */

require_once 'admin-init.php';

/** @var array $user */
/** @var string $csrf_token */
/** @var PDO $pdo */

require_once '../config/credit-notes.php';

$user = [
    'id'        => $_SESSION['admin_user_id'] ?? 0,
    'username'  => $_SESSION['admin_username'] ?? '',
    'role'      => $_SESSION['admin_role'] ?? '',
    'full_name' => $_SESSION['admin_full_name'] ?? '',
];

$site_name       = getSetting('site_name') ?: "Rosalyn's Beach Hotel";
$currency_symbol = getSetting('currency_symbol') ?: 'K ';
$vatEnabled      = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);

// ---------------------------------------------------------------------------
// Date selection — defaults to today (Africa/Blantyre via DB connection TZ).
// ---------------------------------------------------------------------------
$report_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date) || !strtotime($report_date)) {
    $report_date = date('Y-m-d');
}
$dayStart = $report_date . ' 00:00:00';
$dayEnd   = $report_date . ' 23:59:59';
$tomorrow = date('Y-m-d', strtotime($report_date . ' +1 day'));
$isToday  = ($report_date === date('Y-m-d'));

// Module flags — drives query and HTML gating
$mod_bookings     = function_exists('moduleEnabled') && moduleEnabled('bookings');
$mod_pos          = function_exists('moduleEnabled') && moduleEnabled('pos');
$mod_conference   = function_exists('moduleEnabled') && moduleEnabled('conference');
$mod_gym          = function_exists('moduleEnabled') && moduleEnabled('gym');
$mod_housekeeping = function_exists('moduleEnabled') && moduleEnabled('housekeeping');
// Events has no dedicated module toggle — gated by its own legacy setting.
$mod_events       = function_exists('isEventsEnabled') && isEventsEnabled();

// Helper for formatted money
$money = function ($v) use ($currency_symbol) {
    return '<span class="kpi-currency">' . $currency_symbol . '</span>' . number_format((float)$v, 2);
};

$trendTone = function (float $value, bool $inverse = false): string {
    if (abs($value) < 0.01) {
        return 'neutral';
    }
    $isPositive = $value > 0;
    return ($inverse ? !$isPositive : $isPositive) ? 'good' : 'bad';
};

$trendLabel = function (float $value, bool $isMoney = false, string $suffix = '') use ($money): string {
    if (abs($value) < 0.01) {
        return $isMoney ? $money(0) : '0' . $suffix;
    }

    $prefix = $value > 0 ? '+' : '-';
    return $prefix . ($isMoney ? $money(abs($value)) : number_format(abs($value), 1) . $suffix);
};

// ---------------------------------------------------------------------------
// 1) Room operations — arrivals, departures, in-house, occupancy
// ---------------------------------------------------------------------------
$ops = [
    'expected_arrivals'   => 0,
    'arrivals_completed'  => 0,
    'expected_departures' => 0,
    'departures_completed' => 0,
    'stayovers'           => 0,
    'new_bookings'        => 0,
    'cancellations'       => 0,
    'no_shows'            => 0,
];
if ($mod_bookings) {
    try {
        $opsStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN check_in_date = :d AND status IN ('confirmed','tentative','pending','checked-in') THEN 1 ELSE 0 END) AS expected_arrivals,
                SUM(CASE WHEN check_in_date = :d AND status = 'checked-in' THEN 1 ELSE 0 END) AS arrivals_completed,
                SUM(CASE WHEN check_out_date = :d AND status IN ('checked-in','checked-out') THEN 1 ELSE 0 END) AS expected_departures,
                SUM(CASE WHEN check_out_date = :d AND status = 'checked-out' THEN 1 ELSE 0 END) AS departures_completed,
                SUM(CASE WHEN check_in_date < :d AND check_out_date > :d AND status = 'checked-in' THEN 1 ELSE 0 END) AS stayovers,
                SUM(CASE WHEN DATE(created_at) = :d THEN 1 ELSE 0 END) AS new_bookings,
                SUM(CASE WHEN DATE(updated_at) = :d AND status = 'cancelled' THEN 1 ELSE 0 END) AS cancellations,
                SUM(CASE WHEN check_in_date = :d AND status = 'expired' THEN 1 ELSE 0 END) AS no_shows
            FROM bookings
        ");
        $opsStmt->execute([':d' => $report_date]);
        $ops = array_merge($ops, $opsStmt->fetch(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        error_log('EOD ops: ' . $e->getMessage());
    }
}

// Room inventory & occupancy
$rooms_total = 0;
$rooms_occupied = 0;
$rooms_oo = 0;
if ($mod_bookings) {
    try {
        $rooms_total = (int)$pdo->query("SELECT COUNT(*) FROM individual_rooms WHERE status <> 'out_of_order'")->fetchColumn();
        $rooms_oo    = (int)$pdo->query("SELECT COUNT(*) FROM individual_rooms WHERE status = 'out_of_order'")->fetchColumn();
        $occStmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookings
            WHERE status IN ('checked-in','checked-out')
              AND check_in_date <= :d
              AND check_out_date > :d
        ");
        $occStmt->execute([':d' => $report_date]);
        $rooms_occupied = (int)$occStmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('EOD occupancy: ' . $e->getMessage());
    }
}
$occupancy_pct = $rooms_total > 0 ? ($rooms_occupied / $rooms_total) * 100 : 0;

// ---------------------------------------------------------------------------
// 2) Revenue — split by source
// ---------------------------------------------------------------------------
$rev = [
    'room_gross'      => 0.0,
    'room_vat'        => 0.0,
    'conf_gross'      => 0.0,
    'conf_vat'        => 0.0,
    'fnb_gross'       => 0.0,
    'fnb_vat'         => 0.0,
    'gym_gross'       => 0.0,
    'gym_vat'         => 0.0,
    'events_gross'    => 0.0,
    'events_vat'      => 0.0,
    'refunds'         => 0.0,
    'pending'         => 0.0,
    'txn_count'       => 0,
];
try {
    $payStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN booking_type='room'       AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS room_gross,
            COALESCE(SUM(CASE WHEN booking_type='room'       AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount   ELSE 0 END), 0) AS room_vat,
            COALESCE(SUM(CASE WHEN booking_type='conference' AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS conf_gross,
            COALESCE(SUM(CASE WHEN booking_type='conference' AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount   ELSE 0 END), 0) AS conf_vat,
            COALESCE(SUM(CASE WHEN booking_type='restaurant' AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS fnb_gross,
            COALESCE(SUM(CASE WHEN booking_type='restaurant' AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount   ELSE 0 END), 0) AS fnb_vat,
            COALESCE(SUM(CASE WHEN booking_type='gym'        AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS gym_gross,
            COALESCE(SUM(CASE WHEN booking_type='gym'        AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount   ELSE 0 END), 0) AS gym_vat,
            COALESCE(SUM(CASE WHEN booking_type='event'      AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS events_gross,
            COALESCE(SUM(CASE WHEN booking_type='event'      AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount   ELSE 0 END), 0) AS events_vat,
            COALESCE(SUM(CASE WHEN payment_type='refund' AND refund_status IN ('completed','processing') THEN refund_amount ELSE 0 END), 0) AS refunds,
            COALESCE(SUM(CASE WHEN payment_type='refund' AND refund_status IN ('completed','processing') THEN vat_amount   ELSE 0 END), 0) AS refund_vat,
            COALESCE(SUM(CASE WHEN payment_status IN ('pending','partial') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS pending,
            COUNT(*) AS txn_count
        FROM payments
        WHERE DATE(payment_date) = :d
          AND deleted_at IS NULL
    ");
    $payStmt->execute([':d' => $report_date]);
    $rev = array_merge($rev, $payStmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {
    error_log('EOD payments: ' . $e->getMessage());
}

$gross_revenue = (float)$rev['room_gross'] + (float)$rev['conf_gross'] + (float)$rev['fnb_gross'] + (float)$rev['gym_gross'] + (float)$rev['events_gross'];
$net_revenue   = $gross_revenue - (float)$rev['refunds'];
$total_vat     = (float)$rev['room_vat'] + (float)$rev['conf_vat'] + (float)$rev['fnb_vat'] + (float)$rev['gym_vat'] + (float)$rev['events_vat'] - (float)($rev['refund_vat'] ?? 0);

// ADR / RevPAR — based on room payments today
$adr    = $rooms_occupied > 0 ? ((float)$rev['room_gross'] / $rooms_occupied) : 0;
$revpar = $rooms_total > 0 ? ((float)$rev['room_gross'] / $rooms_total) : 0;

// ---------------------------------------------------------------------------
// 3) Payment method mix (from payments + stock_orders settled today)
// ---------------------------------------------------------------------------
$method_mix = [];
try {
    $mStmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(payment_method,''),'unassigned') AS method,
               COUNT(*) AS cnt,
               COALESCE(SUM(total_amount),0) AS total
        FROM payments
        WHERE DATE(payment_date) = :d
          AND payment_status IN ('completed','paid')
          AND COALESCE(payment_type, '') <> 'refund'
          AND deleted_at IS NULL
        GROUP BY method
        ORDER BY total DESC
    ");
    $mStmt->execute([':d' => $report_date]);
    $method_mix = $mStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('EOD method mix: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 4) POS — orders by type, top items, voids
// ---------------------------------------------------------------------------
$pos_by_type = [];
$pos_totals  = ['orders' => 0, 'gross' => 0.0, 'cogs' => 0.0, 'voided_value' => 0.0, 'voided_count' => 0];
if ($mod_pos) {
    try {
        $tStmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(order_type,''),'walk_in') AS order_type,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END),0) AS gross,
                   COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_cost   ELSE 0 END),0) AS cogs
            FROM stock_orders
            WHERE created_at BETWEEN :a AND :b
            GROUP BY order_type
            ORDER BY gross DESC
        ");
        $tStmt->execute([':a' => $dayStart, ':b' => $dayEnd]);
        $pos_by_type = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        $tot = $pdo->prepare("
            SELECT
                COUNT(*) AS orders,
                COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END),0) AS gross,
                COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_cost   ELSE 0 END),0) AS cogs,
                COALESCE(SUM(CASE WHEN status='voided' THEN total_amount ELSE 0 END),0) AS voided_value,
                COALESCE(SUM(CASE WHEN status='voided' THEN 1 ELSE 0 END),0) AS voided_count
            FROM stock_orders
            WHERE created_at BETWEEN :a AND :b
        ");
        $tot->execute([':a' => $dayStart, ':b' => $dayEnd]);
        $pos_totals = array_merge($pos_totals, $tot->fetch(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        error_log('EOD POS: ' . $e->getMessage());
    }
}
$pos_margin = (float)$pos_totals['gross'] - (float)$pos_totals['cogs'];
$pos_margin_pct = $pos_totals['gross'] > 0 ? ($pos_margin / (float)$pos_totals['gross']) * 100 : 0;

$top_items = [];
if ($mod_pos) {
    try {
        $itStmt = $pdo->prepare("
            SELECT soi.item_name, soi.menu_type,
                   SUM(soi.quantity)   AS qty,
                   SUM(soi.line_total) AS revenue
            FROM stock_order_items soi
            INNER JOIN stock_orders o ON o.id = soi.order_id
            WHERE o.status IN ('paid','completed')
              AND o.created_at BETWEEN :a AND :b
            GROUP BY soi.item_name, soi.menu_type
            ORDER BY revenue DESC
            LIMIT 8
        ");
        $itStmt->execute([':a' => $dayStart, ':b' => $dayEnd]);
        $top_items = $itStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('EOD top items: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 5) Reviews today
// ---------------------------------------------------------------------------
$reviews = ['count' => 0, 'avg' => 0.0];
try {
    $rStmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt, COALESCE(AVG(rating),0) AS avg_rating
        FROM reviews
        WHERE DATE(created_at) = :d
    ");
    $rStmt->execute([':d' => $report_date]);
    $row = $rStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $reviews['count'] = (int)($row['cnt'] ?? 0);
    $reviews['avg']   = (float)($row['avg_rating'] ?? 0);
} catch (Throwable $e) {
    error_log('EOD reviews: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 6) Housekeeping snapshot
// ---------------------------------------------------------------------------
$housekeeping = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
if ($mod_housekeeping) {
    try {
        $hkStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN status='pending'     THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN status='completed' AND DATE(updated_at)=:d THEN 1 ELSE 0 END) AS completed
            FROM housekeeping_assignments
        ");
        $hkStmt->execute([':d' => $report_date]);
        $housekeeping = array_merge($housekeeping, $hkStmt->fetch(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        // Table may not exist on every install — silent fallback
    }
}

// ---------------------------------------------------------------------------
// 7) Outstanding folio (across all in-house guests)
// ---------------------------------------------------------------------------
$outstanding_folio = 0.0;
if ($mod_bookings) {
    try {
        $oStmt = $pdo->query("SELECT COALESCE(SUM(amount_due),0) FROM bookings WHERE amount_due > 0 AND status IN ('checked-in','confirmed','tentative')");
        $outstanding_folio = (float)$oStmt->fetchColumn();
    } catch (Throwable $e) { /* ignore */
    }
}

// ---------------------------------------------------------------------------
// 7b) Credit notes — issued and redeemed today
// ---------------------------------------------------------------------------
$cn_issued_today   = 0.0;
$cn_issued_count   = 0;
$cn_redeemed_today = 0.0;
try {
    // Expire any stale CNs first
    if (function_exists('checkExpiredCreditNotes')) {
        checkExpiredCreditNotes($pdo);
    }
    $cnIssStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(original_amount),0) AS total FROM credit_notes WHERE DATE(issued_at) = ?");
    $cnIssStmt->execute([$report_date]);
    $cnIssRow = $cnIssStmt->fetch(PDO::FETCH_ASSOC);
    if ($cnIssRow) {
        $cn_issued_count = (int)$cnIssRow['cnt'];
        $cn_issued_today = (float)$cnIssRow['total'];
    }

    $cnRedStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_applied),0) FROM credit_note_applications WHERE DATE(applied_at) = ?");
    $cnRedStmt->execute([$report_date]);
    $cn_redeemed_today = (float)$cnRedStmt->fetchColumn();
} catch (Throwable $e) { /* credit_notes table may not exist yet */
}

// ---------------------------------------------------------------------------
// 8) Dynamic pricing — rate plans used today, package revenue
// ---------------------------------------------------------------------------
$dynamic_pricing = [
    'bookings_with_rate_plan' => 0,
    'total_discount_given'    => 0.0,
    'package_revenue'         => 0.0,
    'packages_booked'         => 0,
    'top_rate_plan'           => '',
    'top_package'             => '',
];
if ($mod_bookings) {
    try {
        $dpStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN rate_plan_id IS NOT NULL THEN 1 ELSE 0 END) AS bookings_with_rate_plan,
                COALESCE(SUM(CASE WHEN rate_plan_id IS NOT NULL THEN COALESCE(rate_plan_discount,0) ELSE 0 END),0) AS total_discount_given,
                COALESCE(SUM(COALESCE(package_total,0)),0) AS package_revenue
            FROM bookings
            WHERE DATE(created_at) = :d
              AND status NOT IN ('cancelled','no-show')
        ");
        $dpStmt->execute([':d' => $report_date]);
        $dpRow = $dpStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $dynamic_pricing['bookings_with_rate_plan'] = (int)($dpRow['bookings_with_rate_plan'] ?? 0);
        $dynamic_pricing['total_discount_given']    = (float)($dpRow['total_discount_given'] ?? 0);
        $dynamic_pricing['package_revenue']         = (float)($dpRow['package_revenue'] ?? 0);

        $bpStmt = $pdo->prepare("
            SELECT COUNT(*) FROM booking_packages bp
            INNER JOIN bookings b ON b.id = bp.booking_id
            WHERE DATE(b.created_at) = :d AND b.status NOT IN ('cancelled','no-show')
        ");
        $bpStmt->execute([':d' => $report_date]);
        $dynamic_pricing['packages_booked'] = (int)$bpStmt->fetchColumn();

        $rpStmt = $pdo->prepare("
            SELECT rate_plan_label, COUNT(*) AS cnt FROM bookings
            WHERE DATE(created_at) = :d AND rate_plan_id IS NOT NULL AND status NOT IN ('cancelled','no-show')
            GROUP BY rate_plan_label ORDER BY cnt DESC LIMIT 1
        ");
        $rpStmt->execute([':d' => $report_date]);
        $rpRow = $rpStmt->fetch(PDO::FETCH_ASSOC);
        $dynamic_pricing['top_rate_plan'] = $rpRow ? (string)$rpRow['rate_plan_label'] : '';

        $tpStmt = $pdo->prepare("
            SELECT bp.package_name, COUNT(*) AS cnt FROM booking_packages bp
            INNER JOIN bookings b ON b.id = bp.booking_id
            WHERE DATE(b.created_at) = :d AND b.status NOT IN ('cancelled','no-show')
            GROUP BY bp.package_name ORDER BY cnt DESC LIMIT 1
        ");
        $tpStmt->execute([':d' => $report_date]);
        $tpRow = $tpStmt->fetch(PDO::FETCH_ASSOC);
        $dynamic_pricing['top_package'] = $tpRow ? (string)$tpRow['package_name'] : '';
    } catch (Throwable $e) {
        error_log('EOD dynamic pricing: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 9) Tomorrow preview
// ---------------------------------------------------------------------------
$tomorrow_preview = ['arrivals' => 0, 'departures' => 0, 'rev_forecast' => 0.0];
if ($mod_bookings) {
    try {
        $tp = $pdo->prepare("
            SELECT
                SUM(CASE WHEN check_in_date  = :t AND status IN ('confirmed','tentative','pending') THEN 1 ELSE 0 END) AS arrivals,
                SUM(CASE WHEN check_out_date = :t AND status IN ('checked-in','confirmed')           THEN 1 ELSE 0 END) AS departures,
                COALESCE(SUM(CASE WHEN check_in_date = :t AND status IN ('confirmed','tentative','pending') THEN total_amount ELSE 0 END), 0) AS rev_forecast
            FROM bookings
        ");
        $tp->execute([':t' => $tomorrow]);
        $tomorrow_preview = array_merge($tomorrow_preview, $tp->fetch(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) { /* ignore */
    }
}

// ---------------------------------------------------------------------------
// 10) Owner-grade closeout insights and day-over-day movement
// ---------------------------------------------------------------------------
$previous_day = date('Y-m-d', strtotime($report_date . ' -1 day'));
$previous_day_start = $previous_day . ' 00:00:00';
$previous_day_end   = $previous_day . ' 23:59:59';
$previous = [
    'gross_revenue'  => 0.0,
    'net_revenue'    => 0.0,
    'refunds'        => 0.0,
    'room_gross'     => 0.0,
    'pos_gross'      => 0.0,
    'pos_orders'     => 0,
    'new_bookings'   => 0,
    'rooms_occupied' => 0,
    'occupancy_pct'  => 0.0,
];
try {
    $prevPay = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS gross_revenue,
            COALESCE(SUM(CASE WHEN booking_type='room' AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS room_gross,
            COALESCE(SUM(CASE WHEN payment_type='refund' AND refund_status IN ('completed','processing') THEN refund_amount ELSE 0 END), 0) AS refunds
        FROM payments
        WHERE DATE(payment_date) = :d
          AND deleted_at IS NULL
    ");
    $prevPay->execute([':d' => $previous_day]);
    $prevPayRow = $prevPay->fetch(PDO::FETCH_ASSOC) ?: [];
    $previous['gross_revenue'] = (float)($prevPayRow['gross_revenue'] ?? 0);
    $previous['refunds'] = (float)($prevPayRow['refunds'] ?? 0);
    $previous['room_gross'] = (float)($prevPayRow['room_gross'] ?? 0);
    $previous['net_revenue'] = $previous['gross_revenue'] - $previous['refunds'];

    if ($mod_pos) {
        $prevPos = $pdo->prepare("
            SELECT
                COUNT(*) AS orders,
                COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END), 0) AS gross
            FROM stock_orders
            WHERE created_at BETWEEN :a AND :b
        ");
        $prevPos->execute([':a' => $previous_day_start, ':b' => $previous_day_end]);
        $prevPosRow = $prevPos->fetch(PDO::FETCH_ASSOC) ?: [];
        $previous['pos_orders'] = (int)($prevPosRow['orders'] ?? 0);
        $previous['pos_gross'] = (float)($prevPosRow['gross'] ?? 0);
    }

    if ($mod_bookings) {
        $prevBookings = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE DATE(created_at) = :d");
        $prevBookings->execute([':d' => $previous_day]);
        $previous['new_bookings'] = (int)$prevBookings->fetchColumn();

        $prevOcc = $pdo->prepare("
            SELECT COUNT(*) FROM bookings
            WHERE status IN ('checked-in','checked-out')
              AND check_in_date <= :d
              AND check_out_date > :d
        ");
        $prevOcc->execute([':d' => $previous_day]);
        $previous['rooms_occupied'] = (int)$prevOcc->fetchColumn();
        $previous['occupancy_pct'] = $rooms_total > 0 ? ($previous['rooms_occupied'] / $rooms_total) * 100 : 0;
    }
} catch (Throwable $e) {
    error_log('EOD previous comparison: ' . $e->getMessage());
}

$arrivals_remaining   = max(0, (int)$ops['expected_arrivals'] - (int)$ops['arrivals_completed']);
$departures_remaining = max(0, (int)$ops['expected_departures'] - (int)$ops['departures_completed']);
$rooms_unsold         = max(0, $rooms_total - $rooms_occupied);
$room_sell_through    = $rooms_total > 0 ? ($rooms_occupied / $rooms_total) * 100 : 0;
$empty_room_opportunity = $adr > 0 ? $rooms_unsold * $adr : 0;
$average_order_value = (int)$pos_totals['orders'] > 0 ? (float)$pos_totals['gross'] / (int)$pos_totals['orders'] : 0;
$previous_average_order_value = $previous['pos_orders'] > 0 ? $previous['pos_gross'] / $previous['pos_orders'] : 0;
$capture_base = $gross_revenue + (float)$rev['pending'];
$payment_capture_rate = $capture_base > 0 ? ($gross_revenue / $capture_base) * 100 : 100;

$method_totals = [
    'cash'         => 0.0,
    'mobile_money' => 0.0,
    'card'         => 0.0,
    'bank_transfer' => 0.0,
    'unassigned'   => 0.0,
    'other'        => 0.0,
];
foreach ($method_mix as $method_row) {
    $method_name = strtolower(trim((string)($method_row['method'] ?? '')));
    $method_total = (float)($method_row['total'] ?? 0);
    if ($method_name === '' || $method_name === 'unassigned') {
        $method_totals['unassigned'] += $method_total;
    } elseif (strpos($method_name, 'cash') !== false) {
        $method_totals['cash'] += $method_total;
    } elseif (strpos($method_name, 'mobile') !== false || strpos($method_name, 'airtel') !== false || strpos($method_name, 'mpamba') !== false) {
        $method_totals['mobile_money'] += $method_total;
    } elseif (strpos($method_name, 'card') !== false || strpos($method_name, 'visa') !== false || strpos($method_name, 'master') !== false) {
        $method_totals['card'] += $method_total;
    } elseif (strpos($method_name, 'bank') !== false || strpos($method_name, 'transfer') !== false) {
        $method_totals['bank_transfer'] += $method_total;
    } else {
        $method_totals['other'] += $method_total;
    }
}
$non_cash_total = $method_totals['mobile_money'] + $method_totals['card'] + $method_totals['bank_transfer'] + $method_totals['other'];
$revenue_per_transaction = (int)$rev['txn_count'] > 0 ? $gross_revenue / (int)$rev['txn_count'] : 0;
$fnb_per_occupied_room = $rooms_occupied > 0 ? (float)$pos_totals['gross'] / $rooms_occupied : 0;
$unpaid_risk = (float)$rev['pending'] + $outstanding_folio;

$revenue_sources = [];
if ($mod_bookings)   { $revenue_sources[] = ['label' => 'Rooms',       'value' => (float)$rev['room_gross']]; }
if ($mod_conference) { $revenue_sources[] = ['label' => 'Conferences',  'value' => (float)$rev['conf_gross']]; }
if ($mod_pos)        { $revenue_sources[] = ['label' => rh_pos_category_label(), 'value' => (float)$rev['fnb_gross']]; }
if ($mod_gym)        { $revenue_sources[] = ['label' => 'Gym',          'value' => (float)$rev['gym_gross']]; }
if ($mod_events)     { $revenue_sources[] = ['label' => 'Events',       'value' => (float)$rev['events_gross']]; }
if (empty($revenue_sources)) { $revenue_sources[] = ['label' => 'Revenue', 'value' => $gross_revenue]; }
usort($revenue_sources, fn($a, $b) => $b['value'] <=> $a['value']);
$top_revenue_source = $revenue_sources[0];
$top_revenue_source_share = $gross_revenue > 0 ? ((float)$top_revenue_source['value'] / $gross_revenue) * 100 : 0;

$top_item_name = !empty($top_items) ? (string)$top_items[0]['item_name'] : 'No POS winner yet';
$top_item_revenue = !empty($top_items) ? (float)$top_items[0]['revenue'] : 0;

$net_change = $net_revenue - $previous['net_revenue'];
$net_change_pct = $previous['net_revenue'] > 0 ? ($net_change / $previous['net_revenue']) * 100 : ($net_revenue > 0 ? 100 : 0);
$occupancy_change = $occupancy_pct - $previous['occupancy_pct'];
$pos_change = (float)$pos_totals['gross'] - $previous['pos_gross'];
$pos_change_pct = $previous['pos_gross'] > 0 ? ($pos_change / $previous['pos_gross']) * 100 : ((float)$pos_totals['gross'] > 0 ? 100 : 0);
$order_value_change = $average_order_value - $previous_average_order_value;

$daily_health_score = 100;
if ($mod_bookings) {
    $daily_health_score -= min(18, $arrivals_remaining * 4);
    $daily_health_score -= min(16, $departures_remaining * 4);
    $daily_health_score -= min(16, (int)$ops['no_shows'] * 8);
    $daily_health_score -= min(12, (int)$ops['cancellations'] * 3);
    $daily_health_score -= $outstanding_folio > 0 ? 8 : 0;
    $daily_health_score -= $rooms_oo > 0 ? 5 : 0;
}
if ($mod_pos) {
    $daily_health_score -= min(12, (int)$pos_totals['voided_count'] * 4);
}
$daily_health_score -= (float)$rev['pending'] > 0 ? 8 : 0;
if ($mod_housekeeping) {
    $daily_health_score -= ((int)$housekeeping['pending'] + (int)$housekeeping['in_progress']) > 0 ? 8 : 0;
}
$daily_health_score = max(0, min(100, $daily_health_score));
$daily_health_label = $daily_health_score >= 90 ? 'Excellent close' : ($daily_health_score >= 75 ? 'Good close' : ($daily_health_score >= 55 ? 'Needs attention' : 'Critical review'));

// Defaults — overwritten by ENHANCEMENT C queries below
$guest_intel       = ['new_guests' => 0, 'returning_guests' => 0, 'avg_lead_days' => 0];
$guest_intel_total = 0;
$returning_rate    = 0.0;
$lead_time_label   = '';

$closeout_alerts = [];
$addAlert = function (string $level, string $icon, string $title, string $detail) use (&$closeout_alerts): void {
    $closeout_alerts[] = ['level' => $level, 'icon' => $icon, 'title' => $title, 'detail' => $detail];
};
if ($mod_bookings) {
    if ($arrivals_remaining > 0) {
        $addAlert('warn', 'fa-person-walking-luggage', 'Arrivals still open', $arrivals_remaining . ' expected arrival' . ($arrivals_remaining === 1 ? '' : 's') . ' not checked in.');
    }
    if ($departures_remaining > 0) {
        $addAlert('warn', 'fa-door-open', 'Departures still open', $departures_remaining . ' expected departure' . ($departures_remaining === 1 ? '' : 's') . ' not checked out.');
    }
    if ($outstanding_folio > 0) {
        $addAlert('warn', 'fa-file-invoice-dollar', 'Outstanding guest folio', $money($outstanding_folio) . ' unpaid across in-house / active stays.');
    }
    if ($rooms_oo > 0) {
        $addAlert('watch', 'fa-screwdriver-wrench', 'Rooms out of order', $rooms_oo . ' room' . ($rooms_oo === 1 ? '' : 's') . ' unavailable for sale.');
    }
}
if ((float)$rev['pending'] > 0) {
    $addAlert('warn', 'fa-hourglass-half', 'Same-day pending payments', $money((float)$rev['pending']) . ' still pending or partial in today\'s ledger.');
}
if ($mod_pos && (int)$pos_totals['voided_count'] > 0) {
    $addAlert('warn', 'fa-ban', 'POS voids to review', (int)$pos_totals['voided_count'] . ' void' . ((int)$pos_totals['voided_count'] === 1 ? '' : 's') . ' worth ' . $money((float)$pos_totals['voided_value']) . '.');
}
if ((float)$rev['refunds'] > 0) {
    $addAlert('watch', 'fa-rotate-left', 'Refunds processed', $money((float)$rev['refunds']) . ' refunded today.');
}
if ($mod_housekeeping && ((int)$housekeeping['pending'] + (int)$housekeeping['in_progress']) > 0) {
    $addAlert('watch', 'fa-broom', 'Housekeeping not fully closed', ((int)$housekeeping['pending'] + (int)$housekeeping['in_progress']) . ' room task' . (((int)$housekeeping['pending'] + (int)$housekeeping['in_progress']) === 1 ? '' : 's') . ' still open.');
}

// --- Smart analytical alerts ---
$void_rate = (int)$pos_totals['orders'] > 0 ? ((int)$pos_totals['voided_count'] / (int)$pos_totals['orders']) * 100 : 0;
if ($mod_pos && $void_rate > 5 && (int)$pos_totals['voided_count'] > 0) {
    $addAlert('warn', 'fa-triangle-exclamation', 'High void rate — review immediately', sprintf('%.1f%% of all POS orders voided (%d orders worth %s). Investigate cashier logs.', $void_rate, (int)$pos_totals['voided_count'], strip_tags($money((float)$pos_totals['voided_value']))));
}
$cash_share = $gross_revenue > 0 ? ($method_totals['cash'] / $gross_revenue) * 100 : 0;
if ($cash_share > 60 && $method_totals['cash'] > 0) {
    $addAlert('watch', 'fa-sack-dollar', 'High cash day — reconcile drawers', sprintf('%.0f%% of today\'s revenue collected in cash. Ensure cashier drawers are counted and closed before end of shift.', $cash_share));
}
if ($mod_pos && $pos_margin_pct < 25 && (float)$pos_totals['gross'] > 500) {
    $addAlert('watch', 'fa-chart-pie', 'Low ' . rh_pos_short_label() . ' gross margin', sprintf('POS margin is %.1f%% today (healthy target ≥ 35%%). Review high-cost items or check COGS recipe costs.', $pos_margin_pct));
}
if ($mod_bookings && $occupancy_pct < 40 && $rooms_total > 0 && $isToday) {
    $addAlert('watch', 'fa-bed', 'Low occupancy day', sprintf('%.1f%% occupancy. Consider activating walk-in promotions or last-minute rate adjustments.', $occupancy_pct));
}
if ($mod_bookings && (int)$ops['new_bookings'] === 0 && $isToday) {
    $addAlert('watch', 'fa-calendar-xmark', 'No new bookings today', 'Zero new reservations created. Monitor demand signals and consider a short-window promotion.');
}
if ($mod_bookings && $guest_intel['returning_guests'] === 0 && $guest_intel_total > 0 && $returning_rate < 15) {
    $addAlert('watch', 'fa-person-walking-arrow-loop-left', 'Low repeat guest rate', sprintf('Only %.0f%% of today\'s arrivals are returning guests. Loyalty programme or follow-up emails may help.', $returning_rate));
}
if (!$closeout_alerts) {
    $addAlert('good', 'fa-circle-check', 'Clean closeout', 'No major finance, rooms, POS, or housekeeping exceptions flagged.');
}

$order_type_labels = [
    'walk_in'      => 'Walk-in / Dine-in',
    'dine_in'      => 'Dine-in',
    'room_service' => 'Room Service',
    'takeaway'     => 'Takeaway',
    'delivery'     => 'Delivery',
    'other'        => 'Other',
];

// ---------------------------------------------------------------------------
// ENHANCEMENT A — 7-day rolling revenue + POS trend
// Gives a business owner the weekly momentum pattern, not just yesterday.
// ---------------------------------------------------------------------------
$trend_days       = [];
$trend_start      = date('Y-m-d', strtotime($report_date . ' -6 days'));
$trend_start_dt   = $trend_start . ' 00:00:00';
$trend_end_dt     = $report_date . ' 23:59:59';
try {
    $tRevStmt = $pdo->prepare("
        SELECT DATE(payment_date) AS day,
               COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid')
                                  AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END), 0) AS gross,
               COALESCE(SUM(CASE WHEN payment_type='refund'
                                  AND refund_status IN ('completed','processing') THEN refund_amount ELSE 0 END), 0) AS refunds
        FROM payments
        WHERE payment_date BETWEEN :a AND :b
          AND deleted_at IS NULL
        GROUP BY DATE(payment_date)
    ");
    $tRevStmt->execute([':a' => $trend_start_dt, ':b' => $trend_end_dt]);
    $trend_rev_by_day = [];
    foreach ($tRevStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $trend_rev_by_day[$row['day']] = ['gross' => (float)$row['gross'], 'refunds' => (float)$row['refunds']];
    }

    $tPosStmt = $pdo->prepare("
        SELECT DATE(created_at) AS day,
               COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END), 0) AS pos_gross,
               COALESCE(SUM(CASE WHEN status = 'voided' THEN 1 ELSE 0 END), 0) AS voids
        FROM stock_orders
        WHERE created_at BETWEEN :a AND :b
        GROUP BY DATE(created_at)
    ");
    $tPosStmt->execute([':a' => $trend_start_dt, ':b' => $trend_end_dt]);
    $trend_pos_by_day = [];
    foreach ($tPosStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $trend_pos_by_day[$row['day']] = ['pos_gross' => (float)$row['pos_gross'], 'voids' => (int)$row['voids']];
    }

    for ($i = 6; $i >= 0; $i--) {
        $day     = date('Y-m-d', strtotime($report_date . " -$i days"));
        $rev_row = $trend_rev_by_day[$day] ?? ['gross' => 0.0, 'refunds' => 0.0];
        $pos_row = $trend_pos_by_day[$day] ?? ['pos_gross' => 0.0, 'voids' => 0];
        $trend_days[] = [
            'day'       => $day,
            'label'     => date('D j', strtotime($day)),
            'gross'     => $rev_row['gross'],
            'net'       => $rev_row['gross'] - $rev_row['refunds'],
            'pos_gross' => $pos_row['pos_gross'],
            'voids'     => $pos_row['voids'],
            'is_today'  => $day === $report_date,
        ];
    }
} catch (Throwable $e) {
    error_log('EOD 7-day trend: ' . $e->getMessage());
}
$trend_max_total = max(array_map(fn($r) => $r['net'] + $r['pos_gross'], $trend_days ?: [['net' => 0, 'pos_gross' => 0]])) ?: 1;

// ---------------------------------------------------------------------------
// ENHANCEMENT B — Room type revenue breakdown today
// ---------------------------------------------------------------------------
$room_type_perf = [];
if ($mod_bookings) {
    try {
        $rtStmt = $pdo->prepare("
            SELECT rt.name AS room_type,
                   COUNT(DISTINCT b.id) AS bookings,
                   COALESCE(SUM(p.total_amount), 0) AS revenue
            FROM payments p
            INNER JOIN bookings b  ON b.id = p.booking_id
            INNER JOIN rooms rt ON rt.id = b.room_id
            WHERE DATE(p.payment_date) = :d
              AND p.payment_status IN ('completed','paid')
              AND COALESCE(p.payment_type,'') <> 'refund'
              AND p.booking_type = 'room'
              AND p.deleted_at IS NULL
            GROUP BY rt.id, rt.name
            ORDER BY revenue DESC
            LIMIT 6
        ");
        $rtStmt->execute([':d' => $report_date]);
        $room_type_perf = $rtStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('EOD room type perf: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// ENHANCEMENT C — Guest intelligence: new vs returning guests + lead time
// ---------------------------------------------------------------------------
$guest_intel = ['new_guests' => 0, 'returning_guests' => 0, 'avg_lead_days' => 0];
if ($mod_bookings) {
    try {
        $giStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN bcount.total = 1 THEN 1 ELSE 0 END) AS new_guests,
                SUM(CASE WHEN bcount.total > 1 THEN 1 ELSE 0 END) AS returning_guests
            FROM bookings b
            INNER JOIN (
                SELECT guest_email, COUNT(*) AS total
                FROM bookings
                WHERE guest_email != ''
                  AND status NOT IN ('cancelled','no-show','expired')
                GROUP BY guest_email
            ) bcount ON bcount.guest_email = b.guest_email
            WHERE b.check_in_date = :d
              AND b.status NOT IN ('cancelled','no-show','expired')
              AND b.guest_email != ''
        ");
        $giStmt->execute([':d' => $report_date]);
        $giRow = $giStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $guest_intel['new_guests']       = (int)($giRow['new_guests'] ?? 0);
        $guest_intel['returning_guests'] = (int)($giRow['returning_guests'] ?? 0);

        $leadStmt = $pdo->prepare("
            SELECT ROUND(AVG(DATEDIFF(check_in_date, DATE(created_at)))) AS avg_lead
            FROM bookings
            WHERE check_in_date = :d
              AND status NOT IN ('cancelled','no-show','expired')
        ");
        $leadStmt->execute([':d' => $report_date]);
        $guest_intel['avg_lead_days'] = max(0, (int)($leadStmt->fetchColumn() ?? 0));
    } catch (Throwable $e) {
        error_log('EOD guest intel: ' . $e->getMessage());
    }
}
$guest_intel_total = $guest_intel['new_guests'] + $guest_intel['returning_guests'];
$returning_rate    = $guest_intel_total > 0 ? ($guest_intel['returning_guests'] / $guest_intel_total) * 100 : 0;
$lead_time_label   = $guest_intel['avg_lead_days'] <= 1 ? 'Same-day / walk-in' : ($guest_intel['avg_lead_days'] <= 7 ? 'Short (≤ 7 days)' : ($guest_intel['avg_lead_days'] <= 30 ? 'Medium (1–4 weeks)' : 'Long advance'));

// ---------------------------------------------------------------------------
// ENHANCEMENT D — Void breakdown by reason
// ---------------------------------------------------------------------------
$void_reasons = [];
if ($mod_pos) {
    try {
        $vrStmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(void_reason), ''), 'No reason given') AS reason,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(total_amount), 0) AS value
            FROM stock_orders
            WHERE status = 'voided'
              AND created_at BETWEEN :a AND :b
            GROUP BY reason
            ORDER BY cnt DESC
            LIMIT 5
        ");
        $vrStmt->execute([':a' => $dayStart, ':b' => $dayEnd]);
        $void_reasons = $vrStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('EOD void reasons: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// ENHANCEMENT E — Previous day per-source revenue for segment comparison
// ---------------------------------------------------------------------------
$prev_sources = ['room_gross' => 0.0, 'conf_gross' => 0.0, 'fnb_gross' => 0.0, 'gym_gross' => 0.0, 'events_gross' => 0.0];
try {
    $psStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN booking_type='room'       AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END), 0) AS room_gross,
            COALESCE(SUM(CASE WHEN booking_type='conference' AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END), 0) AS conf_gross,
            COALESCE(SUM(CASE WHEN booking_type='restaurant' AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END), 0) AS fnb_gross,
            COALESCE(SUM(CASE WHEN booking_type='gym'        AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END), 0) AS gym_gross,
            COALESCE(SUM(CASE WHEN booking_type='event'      AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END), 0) AS events_gross
        FROM payments
        WHERE DATE(payment_date) = :d
          AND deleted_at IS NULL
    ");
    $psStmt->execute([':d' => $previous_day]);
    $psRow = $psStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $prev_sources = array_merge($prev_sources, $psRow);
} catch (Throwable $e) {
    error_log('EOD prev sources: ' . $e->getMessage());
}
$room_rev_change = (float)$rev['room_gross'] - (float)$prev_sources['room_gross'];
$conf_rev_change = (float)$rev['conf_gross'] - (float)$prev_sources['conf_gross'];
$fnb_rev_change  = (float)$rev['fnb_gross']  - (float)$prev_sources['fnb_gross'];
$gym_rev_change  = (float)$rev['gym_gross']  - (float)$prev_sources['gym_gross'];
$events_rev_change = (float)$rev['events_gross'] - (float)$prev_sources['events_gross'];

// ---------------------------------------------------------------------------
// ENHANCEMENT F — Maintenance snapshot
// ---------------------------------------------------------------------------
$maintenance = ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'total_open' => 0];
try {
    $maintStmt = $pdo->prepare("
        SELECT COALESCE(priority, 'medium') AS priority, COUNT(*) AS cnt
        FROM room_maintenance_schedules
        WHERE status IN ('pending', 'in_progress')
        GROUP BY COALESCE(priority, 'medium')
    ");
    $maintStmt->execute();
    foreach ($maintStmt->fetchAll(PDO::FETCH_ASSOC) as $mr) {
        $p = strtolower(trim((string)($mr['priority'] ?? 'medium')));
        if (isset($maintenance[$p])) $maintenance[$p] = (int)$mr['cnt'];
        $maintenance['total_open'] += (int)$mr['cnt'];
    }
} catch (Throwable $e) { /* table may not exist */ }

// ---------------------------------------------------------------------------
// ENHANCEMENT G — Quotation pipeline + gym inquiries
// ---------------------------------------------------------------------------
$quotation_stats = ['sent_today' => 0, 'accepted_today' => 0, 'total_active' => 0, 'pipeline_value' => 0.0];
try {
    $qStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN DATE(sent_at) = :d THEN 1 ELSE 0 END) AS sent_today,
            SUM(CASE WHEN DATE(updated_at) = :d AND status = 'accepted' THEN 1 ELSE 0 END) AS accepted_today,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS total_active,
            COALESCE(SUM(CASE WHEN status = 'sent' THEN total_amount ELSE 0 END), 0) AS pipeline_value
        FROM quotations
    ");
    $qStmt->execute([':d' => $report_date]);
    $qRow = $qStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $quotation_stats['sent_today']     = (int)($qRow['sent_today'] ?? 0);
    $quotation_stats['accepted_today'] = (int)($qRow['accepted_today'] ?? 0);
    $quotation_stats['total_active']   = (int)($qRow['total_active'] ?? 0);
    $quotation_stats['pipeline_value'] = (float)($qRow['pipeline_value'] ?? 0);
} catch (Throwable $e) { /* ignore */ }

$gym_inquiries_today = 0;
if ($mod_gym) {
    try {
        $gymStmt = $pdo->prepare("SELECT COUNT(*) FROM gym_inquiries WHERE DATE(created_at) = :d AND (status = 'new' OR status = 'pending')");
        $gymStmt->execute([':d' => $report_date]);
        $gym_inquiries_today = (int)$gymStmt->fetchColumn();
    } catch (Throwable $e) { /* ignore */
    }
}

$event_bookings_today = 0;
if ($mod_events) {
    try {
        $eventBookingsStmt = $pdo->prepare("SELECT COUNT(*) FROM event_inquiries WHERE DATE(created_at) = :d AND status = 'pending'");
        $eventBookingsStmt->execute([':d' => $report_date]);
        $event_bookings_today = (int)$eventBookingsStmt->fetchColumn();
    } catch (Throwable $e) { /* ignore */
    }
}

// ---------------------------------------------------------------------------
// CSV export — must run before any HTML output
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'eod-report-' . $report_date . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8 compatibility
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Date',
        'Hotel',
        // Revenue
        'Gross Revenue',
        'Refunds',
        'Net Revenue',
        'VAT Collected',
        'Room Revenue',
        'Conference Revenue',
        rh_pos_short_label() . ' Revenue',
        'Gym Revenue',
        'Events Revenue',
        // Rooms
        'Rooms Total',
        'Rooms Occupied',
        'Rooms Unsold',
        'Rooms OOO',
        'Occupancy %',
        'ADR',
        'RevPAR',
        // Transactions & payments
        'Transactions',
        'Pending Payments',
        'Outstanding Folio',
        'Payment Capture Rate %',
        'Cash',
        'Mobile Money',
        'Card',
        'Bank Transfer',
        'Unassigned',
        // Ops
        'Expected Arrivals',
        'Arrivals Completed',
        'Arrivals Remaining',
        'Expected Departures',
        'Departures Completed',
        'Departures Remaining',
        'Stayovers',
        'New Bookings',
        'Cancellations',
        'No Shows',
        // POS
        'POS Orders',
        'POS Gross',
        'POS COGS',
        'POS Margin',
        'POS Margin %',
        'POS Avg Order Value',
        'POS Voids',
        'POS Void Value',
        // Reviews
        'Reviews Count',
        'Reviews Avg Rating',
        // Housekeeping
        'HK Pending',
        'HK In Progress',
        'HK Completed',
        // Tomorrow
        'Tomorrow Arrivals',
        'Tomorrow Departures',
        // Health
        'Daily Health Score',
        'Daily Health Label',
    ]);

    fputcsv($out, [
        $report_date,
        $site_name,
        number_format($gross_revenue, 2, '.', ''),
        number_format((float)$rev['refunds'], 2, '.', ''),
        number_format($net_revenue, 2, '.', ''),
        number_format($total_vat, 2, '.', ''),
        number_format((float)$rev['room_gross'], 2, '.', ''),
        number_format((float)$rev['conf_gross'], 2, '.', ''),
        number_format((float)$rev['fnb_gross'], 2, '.', ''),
        number_format((float)$rev['gym_gross'], 2, '.', ''),
        number_format((float)$rev['events_gross'], 2, '.', ''),
        $rooms_total,
        $rooms_occupied,
        ($rooms_total - $rooms_occupied),
        $rooms_oo,
        number_format($occupancy_pct, 2, '.', ''),
        number_format((float)$adr, 2, '.', ''),
        number_format((float)$revpar, 2, '.', ''),
        (int)$rev['txn_count'],
        number_format((float)$rev['pending'], 2, '.', ''),
        number_format($outstanding_folio, 2, '.', ''),
        number_format($payment_capture_rate, 2, '.', ''),
        number_format($method_totals['cash'], 2, '.', ''),
        number_format($method_totals['mobile_money'], 2, '.', ''),
        number_format($method_totals['card'], 2, '.', ''),
        number_format($method_totals['bank_transfer'], 2, '.', ''),
        number_format($method_totals['unassigned'], 2, '.', ''),
        (int)$ops['expected_arrivals'],
        (int)$ops['arrivals_completed'],
        $arrivals_remaining,
        (int)$ops['expected_departures'],
        (int)$ops['departures_completed'],
        $departures_remaining,
        (int)$ops['stayovers'],
        (int)$ops['new_bookings'],
        (int)$ops['cancellations'],
        (int)$ops['no_shows'],
        (int)$pos_totals['orders'],
        number_format((float)$pos_totals['gross'], 2, '.', ''),
        number_format((float)$pos_totals['cogs'], 2, '.', ''),
        number_format($pos_margin, 2, '.', ''),
        number_format($pos_margin_pct, 2, '.', ''),
        number_format($average_order_value, 2, '.', ''),
        (int)$pos_totals['voided_count'],
        number_format((float)$pos_totals['voided_value'], 2, '.', ''),
        (int)$reviews['count'],
        number_format((float)$reviews['avg'], 2, '.', ''),
        (int)$housekeeping['pending'],
        (int)$housekeeping['in_progress'],
        (int)$housekeeping['completed'],
        (int)$tomorrow_preview['arrivals'],
        (int)$tomorrow_preview['departures'],
        $daily_health_score,
        $daily_health_label,
    ]);

    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>End of Day Report — <?php echo htmlspecialchars($site_name); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/end-of-day.css?v=<?php echo @filemtime(__DIR__ . '/css/end-of-day.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="eod-page">
            <!-- Header -->
            <header class="eod-header">
                <div class="eod-header__copy">
                    <span class="eod-header__eyebrow"><i class="fas fa-moon"></i> End of Day Report</span>
                    <h1 class="eod-header__title"><?php echo htmlspecialchars(date('l, F j, Y', strtotime($report_date))); ?></h1>
                    <p class="eod-header__sub">
                        <?php echo htmlspecialchars($site_name); ?> &middot;
                        Generated <?php echo date('H:i'); ?> &middot;
                        <?php echo $isToday ? '<span style="color:var(--color-success);font-weight:500;">Live (today)</span>' : 'Archived day'; ?>
                    </p>
                </div>
                <form method="GET" class="eod-header__controls" action="end-of-day-report.php">
                    <label class="eod-date">
                        <span>Select date</span>
                        <input type="date" name="date" value="<?php echo htmlspecialchars($report_date); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </label>
                    <a href="end-of-day-report.php?date=<?php echo date('Y-m-d', strtotime($report_date . ' -1 day')); ?>" class="eod-btn eod-btn--ghost" title="Previous day"><i class="fas fa-chevron-left"></i></a>
                    <button type="submit" class="eod-btn eod-btn--ghost"><i class="fas fa-rotate-right"></i> Refresh</button>
                    <a href="end-of-day-report.php?date=<?php echo date('Y-m-d'); ?>" class="eod-btn eod-btn--primary">Today</a>
                </form>
            </header>

            <!-- Action bar -->
            <div class="eod-actions">
                <div class="eod-cc-row">
                    <label class="eod-cc-row__label" for="eodCcEmail"><i class="fas fa-user-plus"></i> CC email (optional)</label>
                    <input
                        type="email"
                        id="eodCcEmail"
                        class="eod-cc-row__input"
                        placeholder="e.g. owner@example.com, gm@hotel.com"
                        value="<?php echo htmlspecialchars(getSetting('eod_report_cc_emails') ?? ''); ?>"
                        autocomplete="email">
                </div>
                <div class="eod-actions__btns">
                    <button type="button" class="eod-btn eod-btn--primary" id="eodSendEmail" data-date="<?php echo htmlspecialchars($report_date); ?>">
                        <i class="fas fa-paper-plane"></i> Email Report
                    </button>
                    <button type="button" class="eod-btn eod-btn--whatsapp" id="eodSendWhatsApp" data-date="<?php echo htmlspecialchars($report_date); ?>">
                        <i class="fab fa-whatsapp"></i> Send via WhatsApp
                    </button>
                    <button type="button" class="eod-btn eod-btn--ghost" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <a href="api/end-of-day-pdf.php?date=<?php echo htmlspecialchars($report_date); ?>&csrf=<?php echo urlencode($csrf_token); ?>" class="eod-btn eod-btn--ghost" data-no-spa="1" data-no-admin-loader="1">
                        <i class="fas fa-file-pdf"></i> Download PDF
                    </a>
                    <a href="end-of-day-report.php?date=<?php echo htmlspecialchars($report_date); ?>&export=csv" class="eod-btn eod-btn--ghost" data-no-spa="1" data-no-admin-loader="1">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                </div>
            </div>

            <!-- Send result toast -->
            <div id="eodResultToast" style="display:none;margin:0.75rem 0 0;padding:0.85rem 1.1rem;border-radius:6px;font-size:0.92rem;font-weight:500;line-height:1.4;border-left:4px solid currentColor;"></div>

            <!-- KPI strip -->
            <section class="eod-kpis">
                <div class="eod-kpi eod-kpi--revenue" data-help="Net Revenue|Gross revenue collected today minus refunds. This is the actual money earned — the figure your accounts team closes the day on.">
                    <div class="eod-kpi__label">Net Revenue</div>
                    <div class="eod-kpi__value"><?php echo $money($net_revenue); ?></div>
                    <div class="eod-kpi__meta">
                        <span>Gross <?php echo $money($gross_revenue); ?></span>
                        <span>Refunds &minus;<?php echo $money($rev['refunds']); ?></span>
                    </div>
                </div>
                <?php if ($mod_bookings): ?>
                <div class="eod-kpi eod-kpi--occupancy" data-help="Occupancy Rate|Rooms sold ÷ total available rooms × 100. A room counts as occupied if a checked-in or checked-out booking spans tonight. Out-of-order rooms are excluded from the available room count.">
                    <div class="eod-kpi__label">Occupancy</div>
                    <div class="eod-kpi__value"><?php echo number_format($occupancy_pct, 1); ?>%</div>
                    <div class="eod-kpi__meta">
                        <span><?php echo (int)$rooms_occupied; ?>/<?php echo (int)$rooms_total; ?> rooms sold</span>
                        <?php if ($rooms_oo > 0): ?><span><?php echo (int)$rooms_oo; ?> out of order</span><?php endif; ?>
                    </div>
                </div>
                <div class="eod-kpi eod-kpi--adr" data-help="ADR & RevPAR|Average Daily Rate = room revenue ÷ rooms sold. RevPAR = room revenue ÷ all available rooms including unsold. RevPAR penalises unsold rooms so it is a stronger measure of overall yield performance.">
                    <div class="eod-kpi__label">ADR <span class="eod-help" title="Average Daily Rate — room revenue divided by rooms sold">i</span></div>
                    <div class="eod-kpi__value"><?php echo $money($adr); ?></div>
                    <div class="eod-kpi__meta">
                        <span>RevPAR <?php echo $money($revpar); ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <div class="eod-kpi eod-kpi--cash" data-help="VAT Collected|Total Value Added Tax charged across all completed transactions today. This amount is owed to the tax authority — it is not hotel profit. Shown here as a closeout reference.">
                    <div class="eod-kpi__label">VAT Collected</div>
                    <div class="eod-kpi__value"><?php echo $money($total_vat); ?></div>
                    <div class="eod-kpi__meta">
                        <span><?php echo $vatEnabled ? 'Enabled' : 'Disabled'; ?></span>
                        <span><?php echo (int)$rev['txn_count']; ?> transactions</span>
                    </div>
                </div>
                <?php if ($mod_bookings): ?>
                <div class="eod-kpi eod-kpi--owed" data-help="Outstanding Folio|Unpaid charges posted to in-house guest accounts. Guests can run charges to their room and settle on check-out. This balance must be collected before departure — it is live unrecovered revenue.">
                    <div class="eod-kpi__label">Outstanding Folio</div>
                    <div class="eod-kpi__value"><?php echo $money($outstanding_folio); ?></div>
                    <div class="eod-kpi__meta">
                        <span>In-house unpaid</span>
                        <a href="payments.php">Collect &rarr;</a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($cn_issued_count > 0 || $cn_redeemed_today > 0): ?>
                    <div class="eod-kpi eod-kpi--cash" data-help="Credit Notes Issued|Total value of credit notes created today. A credit note is issued instead of a cash refund — it gives the guest hotel credit to use on a future visit. Track this to monitor outstanding liability.">
                        <div class="eod-kpi__label">CN Issued Today</div>
                        <div class="eod-kpi__value"><?php echo $money($cn_issued_today); ?></div>
                        <div class="eod-kpi__meta">
                            <span><?php echo (int)$cn_issued_count; ?> credit note<?php echo $cn_issued_count !== 1 ? 's' : ''; ?></span>
                            <?php if (function_exists('rh_module_key_enabled') && rh_module_key_enabled('advance_booking')): ?><a href="credit-notes.php">View &rarr;</a><?php endif; ?>
                        </div>
                    </div>
                    <div class="eod-kpi eod-kpi--occupancy" data-help="Credit Notes Redeemed|Value of credit notes that guests used as payment today. Each redemption reduces the outstanding credit note liability balance.">
                        <div class="eod-kpi__label">CN Redeemed Today</div>
                        <div class="eod-kpi__value"><?php echo $money($cn_redeemed_today); ?></div>
                        <div class="eod-kpi__meta">
                            <span>Applied to bookings</span>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Owner intelligence -->
            <section class="eod-intel" aria-label="Owner closeout intelligence">
                <article class="eod-insight-card eod-insight-card--score" data-help="Daily Closeout Health Score|A 0–100 score measuring how cleanly today is closing. Points are deducted for: unchecked arrivals/departures, pending payments, outstanding folio, POS voids above 5%, incomplete housekeeping, and out-of-order rooms. 90+ is excellent. Below 70 means action is needed before end of shift.">
                    <div class="eod-insight-card__head">
                        <span class="eod-insight-card__label">Daily Closeout Health</span>
                        <i class="fas fa-gauge-high"></i>
                    </div>
                    <div class="eod-score" style="--score: <?php echo (int)$daily_health_score; ?>%;">
                        <strong><?php echo (int)$daily_health_score; ?></strong>
                        <span>/ 100</span>
                    </div>
                    <h2 class="eod-insight-card__title"><?php echo htmlspecialchars($daily_health_label); ?></h2>
                    <p class="eod-insight-card__text">Weighted from arrivals, departures, unpaid balances, POS exceptions, housekeeping, and room availability.</p>
                </article>

                <article class="eod-insight-card" data-help="What Moved Today|Each row compares today to yesterday for the same metric. Green = improved, red = declined. Net change shows the actual currency movement; percentage shows the relative shift. Use this to spot momentum patterns at a glance.">
                    <div class="eod-insight-card__head">
                        <span class="eod-insight-card__label">What Moved Today</span>
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <ul class="eod-trends">
                        <li>
                            <span>Net revenue vs yesterday</span>
                            <strong class="eod-trend eod-trend--<?php echo $trendTone($net_change); ?>"><?php echo $trendLabel($net_change, true); ?> <small><?php echo $trendLabel($net_change_pct, false, '%'); ?></small></strong>
                        </li>
                        <?php if ($mod_bookings): ?>
                        <li>
                            <span>Rooms revenue vs yesterday</span>
                            <strong class="eod-trend eod-trend--<?php echo $trendTone($room_rev_change); ?>"><?php echo $trendLabel($room_rev_change, true); ?></strong>
                        </li>
                        <?php endif; ?>
                        <?php if ($mod_conference && ((float)$rev['conf_gross'] > 0 || (float)$prev_sources['conf_gross'] > 0)): ?>
                        <li>
                            <span>Conference revenue vs yesterday</span>
                            <strong class="eod-trend eod-trend--<?php echo $trendTone($conf_rev_change); ?>"><?php echo $trendLabel($conf_rev_change, true); ?></strong>
                        </li>
                        <?php endif; ?>
                        <?php if ($mod_pos): ?>
                        <li>
                            <span><?php echo htmlspecialchars(rh_pos_short_label()); ?> vs yesterday</span>
                            <strong class="eod-trend eod-trend--<?php echo $trendTone($fnb_rev_change); ?>"><?php echo $trendLabel($fnb_rev_change, true); ?></strong>
                        </li>
                        <?php endif; ?>
                        <?php if ($mod_gym && ((float)$rev['gym_gross'] > 0 || (float)$prev_sources['gym_gross'] > 0)): ?>
                        <li>
                            <span>Gym revenue vs yesterday</span>
                            <strong class="eod-trend eod-trend--<?php echo $trendTone($gym_rev_change); ?>"><?php echo $trendLabel($gym_rev_change, true); ?></strong>
                        </li>
                        <?php endif; ?>
                        <?php if ($mod_events && ((float)$rev['events_gross'] > 0 || (float)$prev_sources['events_gross'] > 0)): ?>
                        <li>
                            <span>Event revenue vs yesterday</span>
                            <strong class="eod-trend eod-trend--<?php echo $trendTone($events_rev_change); ?>"><?php echo $trendLabel($events_rev_change, true); ?></strong>
                        </li>
                        <?php endif; ?>
                        <?php if ($mod_bookings): ?>
                        <li>
                            <span>Occupancy movement</span>
                            <strong class="eod-trend eod-trend--<?php echo $trendTone($occupancy_change); ?>"><?php echo $trendLabel($occupancy_change, false, ' pts'); ?></strong>
                        </li>
                        <?php endif; ?>
                        <?php if ($mod_pos): ?>
                        <li>
                            <span>POS sales vs yesterday</span>
                            <strong class="eod-trend eod-trend--<?php echo $trendTone($pos_change); ?>"><?php echo $trendLabel($pos_change, true); ?> <small><?php echo $trendLabel($pos_change_pct, false, '%'); ?></small></strong>
                        </li>
                        <li>
                            <span>Average POS order</span>
                            <strong class="eod-trend eod-trend--<?php echo $trendTone($order_value_change); ?>"><?php echo $money($average_order_value); ?></strong>
                        </li>
                        <?php endif; ?>
                        <?php if ($mod_bookings): ?>
                        <li>
                            <span>New bookings today</span>
                            <strong class="eod-trend eod-trend--<?php echo $trendTone((float)((int)$ops['new_bookings'] - $previous['new_bookings'])); ?>"><?php echo (int)$ops['new_bookings']; ?> <small><?php echo $trendLabel((float)((int)$ops['new_bookings'] - $previous['new_bookings'])); ?> vs yesterday</small></strong>
                        </li>
                        <?php endif; ?>
                    </ul>
                </article>

                <article class="eod-insight-card eod-insight-card--wide" data-help="Closeout Exceptions|Automated checks run each time the page loads. Red (warn) = financial or operational risk requiring immediate action. Amber (watch) = advisory, action recommended before end of shift. Green (good) = clean closeout, no issues detected.">
                    <div class="eod-insight-card__head">
                        <span class="eod-insight-card__label">Closeout Exceptions</span>
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>
                    <div class="eod-alerts">
                        <?php foreach ($closeout_alerts as $alert): ?>
                            <div class="eod-alert eod-alert--<?php echo htmlspecialchars($alert['level']); ?>">
                                <i class="fas <?php echo htmlspecialchars($alert['icon']); ?>"></i>
                                <div>
                                    <strong><?php echo htmlspecialchars($alert['title']); ?></strong>
                                    <span><?php echo $alert['detail']; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="eod-insight-card" data-help="Cashier Closeout|Cash to reconcile: physical cash collected — count the till and it must match this figure. Non-cash: card, mobile money, and transfers. Pending: invoiced amounts not yet paid. Collection rate: % of all invoiced amounts that have been settled today.">
                    <div class="eod-insight-card__head">
                        <span class="eod-insight-card__label">Cashier Closeout</span>
                        <i class="fas fa-scale-balanced"></i>
                    </div>
                    <ul class="eod-ledger">
                        <li><span>Cash to reconcile</span><strong><?php echo $money($method_totals['cash']); ?></strong></li>
                        <li><span>Non-cash collected</span><strong><?php echo $money($non_cash_total); ?></strong></li>
                        <li><span>Pending today</span><strong class="eod-ledger__warn"><?php echo $money($rev['pending']); ?></strong></li>
                        <li><span>Collection rate</span><strong><?php echo number_format($payment_capture_rate, 1); ?>%</strong></li>
                    </ul>
                </article>

                <?php if ($mod_bookings): ?>
                <article class="eod-insight-card" data-help="Yield & Opportunity|Empty-room opportunity: estimated revenue lost from unsold rooms (unsold rooms × ADR). <?php echo htmlspecialchars(rh_pos_short_label()); ?> per occupied room: <?php echo isRestaurantEnabled() ? 'food and beverage' : 'POS/till'; ?> revenue per occupied room — measures in-house guest spend. Top revenue source shows which booking type generated the most gross income today.">
                    <div class="eod-insight-card__head">
                        <span class="eod-insight-card__label">Yield & Opportunity</span>
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <ul class="eod-ledger">
                        <li><span>Unsold rooms</span><strong><?php echo (int)$rooms_unsold; ?></strong></li>
                        <li><span>Empty-room opportunity</span><strong><?php echo $money($empty_room_opportunity); ?></strong></li>
                        <?php if ($mod_pos): ?><li><span><?php echo htmlspecialchars(rh_pos_short_label()); ?> per occupied room</span><strong><?php echo $money($fnb_per_occupied_room); ?></strong></li><?php endif; ?>
                        <li><span>Top revenue source</span><strong><?php echo htmlspecialchars($top_revenue_source['label']); ?> <small><?php echo number_format($top_revenue_source_share, 1); ?>%</small></strong></li>
                    </ul>
                </article>
                <?php endif; ?>

                <article class="eod-insight-card" data-help="Best Seller & Risk Exposure|Top POS item: the single menu item generating the most revenue today. Unpaid exposure: combined total of all pending payments and outstanding folio — the maximum amount currently at risk of non-collection.">
                    <div class="eod-insight-card__head">
                        <span class="eod-insight-card__label">Best Seller Signal</span>
                        <i class="fas fa-ranking-star"></i>
                    </div>
                    <div class="eod-hero-metric">
                        <strong><?php echo htmlspecialchars($top_item_name); ?></strong>
                        <span><?php echo $top_item_revenue > 0 ? $money($top_item_revenue) . ' revenue from the top POS item.' : 'No paid POS item has led today yet.'; ?></span>
                    </div>
                    <div class="eod-hero-metric eod-hero-metric--subtle">
                        <strong><?php echo $money($unpaid_risk); ?></strong>
                        <span>Total unpaid exposure: pending payments plus active folio balance.</span>
                    </div>
                </article>
            </section>

            <!-- Operations grid -->
            <section class="eod-grid">
                <?php if ($mod_bookings): ?>
                <!-- Front office activity -->
                <article class="eod-panel" data-help="Front Office Activity|Expected arrivals: confirmed bookings due to check in today. Departures: guests due to check out. Stayovers: guests in-house tonight with a future departure date. New bookings: reservations created today for any future date. Cancellations and no-shows reduce both occupancy and revenue.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-clipboard-list"></i> Front Office</h2>
                    </header>
                    <ul class="eod-stats">
                        <li>
                            <span class="eod-stats__label">Expected arrivals</span>
                            <span class="eod-stats__value"><?php echo (int)$ops['expected_arrivals']; ?></span>
                            <span class="eod-stats__sub"><?php echo (int)$ops['arrivals_completed']; ?> checked in</span>
                        </li>
                        <li>
                            <span class="eod-stats__label">Expected departures</span>
                            <span class="eod-stats__value"><?php echo (int)$ops['expected_departures']; ?></span>
                            <span class="eod-stats__sub"><?php echo (int)$ops['departures_completed']; ?> checked out</span>
                        </li>
                        <li>
                            <span class="eod-stats__label">Stay-overs</span>
                            <span class="eod-stats__value"><?php echo (int)$ops['stayovers']; ?></span>
                            <span class="eod-stats__sub">In-house tonight</span>
                        </li>
                        <li>
                            <span class="eod-stats__label">New bookings</span>
                            <span class="eod-stats__value"><?php echo (int)$ops['new_bookings']; ?></span>
                            <span class="eod-stats__sub">Created today</span>
                        </li>
                        <li>
                            <span class="eod-stats__label">Cancellations</span>
                            <span class="eod-stats__value eod-stats__value--warn"><?php echo (int)$ops['cancellations']; ?></span>
                        </li>
                        <li>
                            <span class="eod-stats__label">No-shows</span>
                            <span class="eod-stats__value eod-stats__value--warn"><?php echo (int)$ops['no_shows']; ?></span>
                        </li>
                    </ul>
                </article>
                <?php endif; ?>

                <!-- Revenue by source -->
                <article class="eod-panel" data-help="Revenue by Source|Rooms: accommodation payments collected today. Conferences: event and function booking payments. <?php echo htmlspecialchars(rh_pos_category_label()); ?>: <?php echo isRestaurantEnabled() ? 'restaurant charges' : 'till sales'; ?> posted through the payments system. Net = Gross minus any refunds processed today. The % Mix column shows each source's share of total gross.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-coins"></i> Revenue by Source</h2>
                    </header>
                    <div class="eod-table-wrap">
                        <table class="eod-table">
                            <thead>
                                <tr>
                                    <th>Source</th>
                                    <th class="num">Gross</th>
                                    <th class="num">VAT</th>
                                    <th class="num">% Mix</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $rows = [];
                                if ($mod_bookings)   { $rows[] = ['Rooms',         (float)$rev['room_gross'], (float)$rev['room_vat']]; }
                                if ($mod_conference) { $rows[] = ['Conferences',   (float)$rev['conf_gross'], (float)$rev['conf_vat']]; }
                                if ($mod_pos)        { $rows[] = [htmlspecialchars(rh_pos_category_label()), (float)$rev['fnb_gross'],  (float)$rev['fnb_vat']]; }
                                if ($mod_gym)        { $rows[] = ['Gym',          (float)$rev['gym_gross'],  (float)$rev['gym_vat']]; }
                                if ($mod_events)     { $rows[] = ['Events',       (float)$rev['events_gross'], (float)$rev['events_vat']]; }
                                foreach ($rows as $r):
                                    $share = $gross_revenue > 0 ? ($r[1] / $gross_revenue) * 100 : 0;
                                ?>
                                    <tr>
                                        <td data-label="Source"><?php echo $r[0]; ?></td>
                                        <td class="num" data-label="Gross"><?php echo $money($r[1]); ?></td>
                                        <td class="num" data-label="VAT"><?php echo $money($r[2]); ?></td>
                                        <td class="num" data-label="Mix"><?php echo number_format($share, 1); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="eod-table__total">
                                    <td data-label="Source">Total</td>
                                    <td class="num" data-label="Gross"><?php echo $money($gross_revenue); ?></td>
                                    <td class="num" data-label="VAT"><?php echo $money($total_vat); ?></td>
                                    <td class="num" data-label="Mix">100%</td>
                                </tr>
                                <?php if ((float)$rev['refunds'] > 0): ?>
                                    <tr class="eod-table__neg">
                                        <td data-label="Source">Less: refunds</td>
                                        <td class="num" data-label="Gross">&minus;<?php echo $money($rev['refunds']); ?></td>
                                        <td class="num" data-label="VAT">&mdash;</td>
                                        <td class="num" data-label="Mix">&mdash;</td>
                                    </tr>
                                <?php endif; ?>
                                <tr class="eod-table__net">
                                    <td data-label="Source"><strong>Net</strong></td>
                                    <td class="num" data-label="Gross"><strong><?php echo $money($net_revenue); ?></strong></td>
                                    <td class="num" data-label="VAT">&mdash;</td>
                                    <td class="num" data-label="Mix">&mdash;</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <!-- Payment mix -->
                <article class="eod-panel" data-help="Payment Method Mix|How today's revenue was collected. Cash must be physically counted and matched to the till. Non-cash (card, mobile money, bank transfer) reconciles to gateway statements. Bar length shows each method's share of total gross revenue. Number in brackets = transaction count.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-wallet"></i> Payment Mix</h2>
                    </header>
                    <?php if (empty($method_mix)): ?>
                        <p class="eod-empty">No payments recorded today.</p>
                    <?php else: ?>
                        <ul class="eod-bars">
                            <?php
                            $maxMethod = max(array_map(fn($r) => (float)$r['total'], $method_mix)) ?: 1;
                            foreach ($method_mix as $m):
                                $w = ((float)$m['total'] / $maxMethod) * 100;
                                $label = ucwords(str_replace('_', ' ', (string)$m['method']));
                            ?>
                                <li>
                                    <div class="eod-bars__row">
                                        <span class="eod-bars__label"><?php echo htmlspecialchars($label); ?></span>
                                        <span class="eod-bars__value"><?php echo $money($m['total']); ?> <small>(<?php echo (int)$m['cnt']; ?>)</small></span>
                                    </div>
                                    <div class="eod-bars__track">
                                        <div class="eod-bars__fill" style="width:<?php echo max(2, $w); ?>%"></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>

                <?php if ($mod_pos): ?>
                <!-- POS -->
                <article class="eod-panel" data-help="<?php echo htmlspecialchars('POS / ' . rh_pos_short_label() . ' Sales'); ?>|Orders placed on the till system (walk-in, room service, takeaway, delivery). COGS is the ingredient cost from stock recipes. Margin % = (Gross − COGS) ÷ Gross × 100. Healthy margin target is ≥35%. Voids are cancelled orders — review if they exceed 5% of total orders.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-cash-register"></i> POS<?php echo isRestaurantEnabled() ? ' / F&amp;B' : ''; ?></h2>
                    </header>
                    <div class="eod-mini-kpis">
                        <div><span>Orders</span><strong><?php echo (int)$pos_totals['orders']; ?></strong></div>
                        <div><span>Gross</span><strong><?php echo $money($pos_totals['gross']); ?></strong></div>
                        <div><span>COGS</span><strong><?php echo $money($pos_totals['cogs']); ?></strong></div>
                        <div><span>Margin</span><strong><?php echo number_format($pos_margin_pct, 1); ?>%</strong></div>
                        <div><span>Voids</span><strong class="eod-warn"><?php echo (int)$pos_totals['voided_count']; ?> &middot; <?php echo $money($pos_totals['voided_value']); ?></strong></div>
                    </div>
                    <?php if (!empty($pos_by_type)): ?>
                        <div class="eod-table-wrap">
                            <table class="eod-table eod-table--compact">
                                <thead>
                                    <tr>
                                        <th>Order type</th>
                                        <th class="num">Orders</th>
                                        <th class="num">Gross</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pos_by_type as $p): $label = $order_type_labels[$p['order_type']] ?? ucfirst((string)$p['order_type']); ?>
                                        <tr>
                                            <td data-label="Order type"><?php echo htmlspecialchars($label); ?></td>
                                            <td class="num" data-label="Orders"><?php echo (int)$p['cnt']; ?></td>
                                            <td class="num" data-label="Gross"><?php echo $money($p['gross']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($void_reasons)): ?>
                        <div class="eod-void-reasons">
                            <p class="eod-void-reasons__title"><i class="fas fa-ban"></i> Void breakdown</p>
                            <ul class="eod-void-reasons__list">
                                <?php foreach ($void_reasons as $vr): ?>
                                    <li>
                                        <span class="eod-void-reasons__reason"><?php echo htmlspecialchars((string)$vr['reason']); ?></span>
                                        <span class="eod-void-reasons__meta"><?php echo (int)$vr['cnt']; ?>× &middot; <?php echo $money($vr['value']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </article>
                <?php endif; ?>

                <?php if ($mod_pos): ?>
                <!-- Top selling items -->
                <article class="eod-panel eod-panel--wide" data-help="Top Selling Items|Best-performing individual menu items today ranked by total revenue. Qty is total units sold. Use this to guide menu decisions, manage stock for tomorrow, and identify high-margin items worth promoting.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-fire"></i> Top Selling Items</h2>
                    </header>
                    <?php if (empty($top_items)): ?>
                        <p class="eod-empty">No POS sales recorded today.</p>
                    <?php else: ?>
                        <div class="eod-table-wrap">
                            <table class="eod-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Type</th>
                                        <th class="num">Qty</th>
                                        <th class="num">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top_items as $it): ?>
                                        <tr>
                                            <td data-label="Item"><?php echo htmlspecialchars((string)$it['item_name']); ?></td>
                                            <td data-label="Type"><span class="eod-tag eod-tag--<?php echo htmlspecialchars((string)$it['menu_type']); ?>"><?php echo htmlspecialchars(ucfirst((string)$it['menu_type'])); ?></span></td>
                                            <td class="num" data-label="Qty"><?php echo rtrim(rtrim(number_format((float)$it['qty'], 2, '.', ''), '0'), '.'); ?></td>
                                            <td class="num" data-label="Revenue"><?php echo $money($it['revenue']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
                <?php endif; ?>

                <?php if ($mod_bookings): ?>
                <!-- Dynamic pricing & packages -->
                <article class="eod-panel" data-help="Dynamic Pricing & Packages|Rate-plan bookings used a configured pricing rule (early bird, corporate, long stay, etc.). Discounts given is the total reduction from rack rate applied today. Package add-on revenue comes from extras bundled with a booking.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-tag"></i> Dynamic Pricing &amp; Packages</h2>
                    </header>
                    <?php if ($dynamic_pricing['bookings_with_rate_plan'] === 0 && $dynamic_pricing['packages_booked'] === 0): ?>
                        <p class="eod-empty">No rate plans or packages applied in today&rsquo;s bookings.</p>
                    <?php else: ?>
                        <ul class="eod-stats">
                            <li>
                                <span class="eod-stats__label">Rate-plan bookings</span>
                                <span class="eod-stats__value"><?php echo (int)$dynamic_pricing['bookings_with_rate_plan']; ?></span>
                                <?php if ($dynamic_pricing['top_rate_plan']): ?><span class="eod-stats__sub"><?php echo htmlspecialchars($dynamic_pricing['top_rate_plan']); ?></span><?php endif; ?>
                            </li>
                            <li>
                                <span class="eod-stats__label">Total discounts given</span>
                                <span class="eod-stats__value eod-stats__value--warn"><?php echo $money($dynamic_pricing['total_discount_given']); ?></span>
                                <span class="eod-stats__sub">Rate plan savings applied</span>
                            </li>
                            <li>
                                <span class="eod-stats__label">Package add-on revenue</span>
                                <span class="eod-stats__value eod-stats__value--good"><?php echo $money($dynamic_pricing['package_revenue']); ?></span>
                                <span class="eod-stats__sub"><?php echo (int)$dynamic_pricing['packages_booked']; ?> package<?php echo (int)$dynamic_pricing['packages_booked'] === 1 ? '' : 's'; ?> booked</span>
                            </li>
                            <?php if ($dynamic_pricing['top_package']): ?>
                                <li>
                                    <span class="eod-stats__label">Top package today</span>
                                    <span class="eod-stats__value" style="font-size:13px;"><?php echo htmlspecialchars($dynamic_pricing['top_package']); ?></span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </article>
                <?php endif; ?>

                <?php if ($mod_housekeeping): ?>
                <!-- Housekeeping -->
                <article class="eod-panel" data-help="Housekeeping Status|Pending: tasks assigned but not yet started. In progress: currently being worked on. Completed: fully done today. All pending and in-progress tasks should be resolved before end of shift so rooms are ready for tomorrow's arrivals.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-broom"></i> Housekeeping</h2>
                    </header>
                    <ul class="eod-stats">
                        <li>
                            <span class="eod-stats__label">Pending</span>
                            <span class="eod-stats__value eod-stats__value--warn"><?php echo (int)$housekeeping['pending']; ?></span>
                        </li>
                        <li>
                            <span class="eod-stats__label">In progress</span>
                            <span class="eod-stats__value"><?php echo (int)$housekeeping['in_progress']; ?></span>
                        </li>
                        <li>
                            <span class="eod-stats__label">Completed today</span>
                            <span class="eod-stats__value eod-stats__value--good"><?php echo (int)$housekeeping['completed']; ?></span>
                        </li>
                    </ul>
                </article>
                <?php endif; ?>

                <!-- Guest sentiment -->
                <article class="eod-panel" data-help="Guest Reviews|Reviews submitted today. Average rating is out of 5. Daily monitoring catches service issues before they escalate. Low scores should be reviewed with the relevant department head before the next shift.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-star"></i> Reviews</h2>
                    </header>
                    <?php if ($reviews['count'] === 0): ?>
                        <p class="eod-empty">No reviews submitted today.</p>
                    <?php else: ?>
                        <div class="eod-review-block">
                            <div class="eod-review-block__avg">
                                <strong><?php echo number_format($reviews['avg'], 1); ?></strong>
                                <span>/ 5</span>
                            </div>
                            <div class="eod-review-block__count">
                                <?php echo (int)$reviews['count']; ?> review<?php echo $reviews['count'] === 1 ? '' : 's'; ?> today
                            </div>
                            <a href="reviews.php" class="eod-link">View all &rarr;</a>
                        </div>
                    <?php endif; ?>
                </article>

                <!-- 7-day rolling trend -->
                <article class="eod-panel eod-panel--wide" data-help="7-Day Revenue Trend|Net room/conference/<?php echo htmlspecialchars(rh_pos_short_label()); ?> revenue plus POS gross for each of the last 7 days. The momentum bar compares each day's combined total to the week's highest day. The gold bar is today. Voids column shows cancelled POS orders per day.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-chart-line"></i> 7-Day Revenue Trend</h2>
                        <span class="eod-panel__date"><?php echo htmlspecialchars(date('M j', strtotime($trend_start))); ?> – <?php echo htmlspecialchars(date('M j', strtotime($report_date))); ?></span>
                    </header>
                    <div class="eod-table-wrap">
                        <table class="eod-table eod-table--trend">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th class="num">Net Rev</th>
                                    <?php if ($mod_pos): ?><th class="num"><?php echo htmlspecialchars(rh_pos_short_label()); ?></th><?php endif; ?>
                                    <th>Momentum</th>
                                    <th class="num">Voids</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($trend_days as $td):
                                    $combined   = $td['net'] + $td['pos_gross'];
                                    $bar_width  = $trend_max_total > 0 ? max(1, ($combined / $trend_max_total) * 100) : 0;
                                    $is_today   = $td['is_today'];
                                ?>
                                    <tr class="<?php echo $is_today ? 'eod-table__today' : ''; ?>">
                                        <td data-label="Day"><strong><?php echo htmlspecialchars($td['label']); ?></strong><?php echo $is_today ? ' <span class="eod-tag eod-tag--today">Today</span>' : ''; ?></td>
                                        <td class="num" data-label="Net Rev"><?php echo $money($td['net']); ?></td>
                                        <?php if ($mod_pos): ?><td class="num" data-label="<?php echo htmlspecialchars(rh_pos_short_label()); ?>"><?php echo $money($td['pos_gross']); ?></td><?php endif; ?>
                                        <td data-label="Momentum">
                                            <div class="eod-trend-bar">
                                                <div class="eod-trend-bar__fill<?php echo $is_today ? ' eod-trend-bar__fill--today' : ''; ?>" style="width:<?php echo number_format($bar_width, 1); ?>%"></div>
                                                <span class="eod-trend-bar__val"><?php echo $money($combined); ?></span>
                                            </div>
                                        </td>
                                        <td class="num<?php echo $td['voids'] > 0 ? ' eod-warn' : ''; ?>" data-label="Voids"><?php echo $td['voids'] > 0 ? (int)$td['voids'] : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <?php if ($mod_bookings): ?>
                <!-- Room type revenue -->
                <article class="eod-panel" data-help="Room Type Revenue|Accommodation payments broken down by room category today. Bar length shows each type's share relative to the highest-earning category. Useful for pricing decisions, upsell targets, and understanding which room tiers drive revenue.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-building"></i> Room Type Revenue</h2>
                    </header>
                    <?php if (empty($room_type_perf)): ?>
                        <p class="eod-empty">No room payments recorded today.</p>
                    <?php else:
                        $rt_max = max(array_map(fn($r) => (float)$r['revenue'], $room_type_perf)) ?: 1;
                    ?>
                        <ul class="eod-bars">
                            <?php foreach ($room_type_perf as $rt):
                                $rt_share = $rev['room_gross'] > 0 ? ((float)$rt['revenue'] / (float)$rev['room_gross']) * 100 : 0;
                                $rt_w     = ((float)$rt['revenue'] / $rt_max) * 100;
                            ?>
                                <li>
                                    <div class="eod-bars__row">
                                        <span class="eod-bars__label"><?php echo htmlspecialchars((string)$rt['room_type']); ?></span>
                                        <span class="eod-bars__value"><?php echo $money($rt['revenue']); ?> <small><?php echo (int)$rt['bookings']; ?> booking<?php echo (int)$rt['bookings'] === 1 ? '' : 's'; ?> &middot; <?php echo number_format($rt_share, 1); ?>%</small></span>
                                    </div>
                                    <div class="eod-bars__track">
                                        <div class="eod-bars__fill" style="width:<?php echo max(2, $rt_w); ?>%"></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>
                <?php endif; ?>

                <?php if ($mod_bookings): ?>
                <!-- Guest intelligence -->
                <article class="eod-panel" data-help="Guest Intelligence|New guests: arrivals today with no prior booking history in the system. Returning guests: arrivals who have booked before. Booking lead time: average days between when today's guests made their reservation and their arrival date. Short lead times may indicate last-minute demand.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-users"></i> Guest Intelligence</h2>
                    </header>
                    <?php if ($guest_intel_total === 0): ?>
                        <p class="eod-empty">No arrival data to analyse today.</p>
                    <?php else: ?>
                        <div class="eod-guest-intel">
                            <div class="eod-guest-intel__split">
                                <div class="eod-guest-intel__seg eod-guest-intel__seg--new">
                                    <strong><?php echo (int)$guest_intel['new_guests']; ?></strong>
                                    <span>New guests</span>
                                </div>
                                <div class="eod-guest-intel__bar">
                                    <div class="eod-guest-intel__bar-fill" style="width:<?php echo number_format($returning_rate, 1); ?>%"></div>
                                </div>
                                <div class="eod-guest-intel__seg eod-guest-intel__seg--returning">
                                    <strong><?php echo (int)$guest_intel['returning_guests']; ?></strong>
                                    <span>Returning</span>
                                </div>
                            </div>
                            <p class="eod-guest-intel__rate"><?php echo number_format($returning_rate, 1); ?>% repeat guest rate today</p>
                        </div>
                        <ul class="eod-stats">
                            <li>
                                <span class="eod-stats__label">Avg booking lead time</span>
                                <span class="eod-stats__value"><?php echo (int)$guest_intel['avg_lead_days']; ?> day<?php echo $guest_intel['avg_lead_days'] === 1 ? '' : 's'; ?></span>
                                <span class="eod-stats__sub"><?php echo htmlspecialchars($lead_time_label); ?></span>
                            </li>
                        </ul>
                    <?php endif; ?>
                </article>
                <?php endif; ?>

                <!-- Gym inquiries -->
                <?php if ($gym_inquiries_today > 0): ?>
                <article class="eod-panel" data-help="Gym Inquiries|New fitness centre membership inquiries received today that are awaiting response. Follow up promptly to convert leads.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-dumbbell"></i> Gym Inquiries</h2>
                    </header>
                    <div class="eod-hero-metric">
                        <strong><?php echo $gym_inquiries_today; ?></strong>
                        <span>New inquiry<?php echo $gym_inquiries_today === 1 ? '' : 's'; ?> pending response today.</span>
                    </div>
                    <a href="gym-inquiries.php" class="eod-link" style="margin-top:0.5rem;display:inline-block;">View &rarr;</a>
                </article>
                <?php endif; ?>

                <!-- Event bookings -->
                <?php if ($event_bookings_today > 0): ?>
                <article class="eod-panel" data-help="Event Bookings|New event RSVPs/bookings received today that are awaiting confirmation. Follow up promptly.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-calendar-check"></i> Event Bookings</h2>
                    </header>
                    <div class="eod-hero-metric">
                        <strong><?php echo $event_bookings_today; ?></strong>
                        <span>New booking<?php echo $event_bookings_today === 1 ? '' : 's'; ?> pending response today.</span>
                    </div>
                    <a href="events-inquiries.php" class="eod-link" style="margin-top:0.5rem;display:inline-block;">View &rarr;</a>
                </article>
                <?php endif; ?>

                <!-- Open Maintenance -->
                <?php if ($mod_bookings && $maintenance['total_open'] > 0): ?>
                <article class="eod-panel" data-help="Open Maintenance|Rooms maintenance tasks still open at end of shift. Urgent and high priority tasks should be resolved before tomorrow's arrivals to ensure rooms are ready.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-screwdriver-wrench"></i> Open Maintenance</h2>
                    </header>
                    <ul class="eod-stats">
                        <?php if ($maintenance['urgent'] > 0): ?>
                        <li>
                            <span class="eod-stats__label">Urgent</span>
                            <span class="eod-stats__value eod-stats__value--warn"><?php echo $maintenance['urgent']; ?></span>
                        </li>
                        <?php endif; ?>
                        <?php if ($maintenance['high'] > 0): ?>
                        <li>
                            <span class="eod-stats__label">High priority</span>
                            <span class="eod-stats__value eod-stats__value--warn"><?php echo $maintenance['high']; ?></span>
                        </li>
                        <?php endif; ?>
                        <?php if ($maintenance['medium'] > 0): ?>
                        <li>
                            <span class="eod-stats__label">Medium</span>
                            <span class="eod-stats__value"><?php echo $maintenance['medium']; ?></span>
                        </li>
                        <?php endif; ?>
                        <li>
                            <span class="eod-stats__label">Total open tasks</span>
                            <span class="eod-stats__value eod-stats__value--warn"><?php echo $maintenance['total_open']; ?></span>
                            <span class="eod-stats__sub">Rooms need attention</span>
                        </li>
                    </ul>
                    <a href="room-maintenance.php" class="eod-link" style="margin-top:0.5rem;display:inline-block;">View all &rarr;</a>
                </article>
                <?php endif; ?>

                <!-- Quotation Pipeline (billing businesses only — matches the nav/page gate) -->
                <?php $eod_billing = function_exists('rh_module_key_enabled') && rh_module_key_enabled('billing'); ?>
                <?php if ($eod_billing && ($quotation_stats['sent_today'] > 0 || $quotation_stats['total_active'] > 0)): ?>
                <article class="eod-panel" data-help="Quotation Pipeline|Quotes sent today and quotes still awaiting a decision from prospects. Pipeline value is the total of all sent quotes not yet accepted or declined. Track conversion to ensure proposals translate into confirmed revenue.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-file-lines"></i> Quotations</h2>
                    </header>
                    <ul class="eod-stats">
                        <li>
                            <span class="eod-stats__label">Sent today</span>
                            <span class="eod-stats__value"><?php echo $quotation_stats['sent_today']; ?></span>
                        </li>
                        <li>
                            <span class="eod-stats__label">Accepted today</span>
                            <span class="eod-stats__value eod-stats__value--good"><?php echo $quotation_stats['accepted_today']; ?></span>
                        </li>
                        <li>
                            <span class="eod-stats__label">Active open quotes</span>
                            <span class="eod-stats__value"><?php echo $quotation_stats['total_active']; ?></span>
                            <span class="eod-stats__sub"><?php echo $money($quotation_stats['pipeline_value']); ?> pipeline value</span>
                        </li>
                    </ul>
                    <a href="quotations.php" class="eod-link" style="margin-top:0.5rem;display:inline-block;">View all &rarr;</a>
                </article>
                <?php endif; ?>

                <?php if ($mod_bookings): ?>
                <!-- Tomorrow preview -->
                <article class="eod-panel eod-panel--accent" data-help="Tomorrow Preview|Expected arrivals: confirmed bookings checking in tomorrow. Expected departures: guests due to check out. Revenue forecast: the sum of booking charges due from tomorrow's arrivals based on their confirmed booking values.">
                    <header class="eod-panel__head">
                        <h2 class="eod-panel__title"><i class="fas fa-arrow-right"></i> Tomorrow Preview</h2>
                        <span class="eod-panel__date"><?php echo htmlspecialchars(date('D, M j', strtotime($tomorrow))); ?></span>
                    </header>
                    <ul class="eod-stats">
                        <li>
                            <span class="eod-stats__label">Arrivals expected</span>
                            <span class="eod-stats__value"><?php echo (int)$tomorrow_preview['arrivals']; ?></span>
                        </li>
                        <li>
                            <span class="eod-stats__label">Departures expected</span>
                            <span class="eod-stats__value"><?php echo (int)$tomorrow_preview['departures']; ?></span>
                        </li>
                        <li>
                            <span class="eod-stats__label">Forecast revenue</span>
                            <span class="eod-stats__value"><?php echo $money($tomorrow_preview['rev_forecast']); ?></span>
                        </li>
                    </ul>
                </article>
                <?php endif; ?>
            </section>

            <!-- Footer note -->
            <footer class="eod-footer">
                <p>Generated by <?php echo htmlspecialchars($user['full_name'] ?: 'Admin'); ?> at <?php echo date('Y-m-d H:i'); ?> &middot; All figures in <?php echo htmlspecialchars(trim($currency_symbol)); ?>.</p>
            </footer>
        </div>
    </div>

    <script>
        (function() {
            const csrf = window._rhCsrf || '<?php echo htmlspecialchars($csrf_token ?? ''); ?>';

            function showResult(title, html, isError) {
                const toast = document.getElementById('eodResultToast');
                if (!toast) {
                    alert(title + '\n\n' + html.replace(/<[^>]+>/g, ''));
                    return;
                }
                toast.innerHTML = '<strong>' + title + '</strong> &mdash; ' + html.replace(/<[^>]+>/g, '');
                if (isError) {
                    toast.style.background = '#fff1f2';
                    toast.style.color = '#b91c1c';
                    toast.style.borderColor = '#ef4444';
                } else {
                    toast.style.background = '#f0fdf4';
                    toast.style.color = '#166534';
                    toast.style.borderColor = '#22c55e';
                }
                toast.style.display = 'block';
                clearTimeout(toast._hideTimer);
                toast._hideTimer = setTimeout(() => {
                    toast.style.display = 'none';
                }, 8000);
                toast.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });
            }

            function sendReport(channel, btn) {
                const date = btn.dataset.date;
                const ccInput = document.getElementById('eodCcEmail');
                const ccEmail = ccInput ? ccInput.value.trim() : '';
                const originalHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';

                fetch('api/end-of-day-send.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            date: date,
                            channel: channel,
                            csrf: csrf,
                            cc_email: ccEmail
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showResult('Report Sent', data.message || 'Sent successfully.', false);
                            if (channel === 'email' && ccInput) ccInput.value = '';
                        } else {
                            showResult('Could Not Send', data.error || 'Unknown error', true);
                        }
                    })
                    .catch(() => showResult('Network Error', 'Please try again.', true))
                    .finally(() => {
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                    });
            }

            const emailBtn = document.getElementById('eodSendEmail');
            const waBtn = document.getElementById('eodSendWhatsApp');
            if (emailBtn) emailBtn.addEventListener('click', () => sendReport('email', emailBtn));
            if (waBtn) waBtn.addEventListener('click', () => sendReport('whatsapp', waBtn));
        })();
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

