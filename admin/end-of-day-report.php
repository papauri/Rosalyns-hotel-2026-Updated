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
require_once '../includes/station-hours.php';
require_once __DIR__ . '/includes/pos-shift-totals.php';

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
            WHERE deleted_at IS NULL AND status IN ('checked-in','checked-out')
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
            COALESCE(SUM(CASE WHEN booking_type='room'       AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS room_gross,
            COALESCE(SUM(CASE WHEN booking_type='room'       AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount   ELSE 0 END), 0) AS room_vat,
            COALESCE(SUM(CASE WHEN booking_type='conference' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS conf_gross,
            COALESCE(SUM(CASE WHEN booking_type='conference' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount   ELSE 0 END), 0) AS conf_vat,
            COALESCE(SUM(CASE WHEN booking_type='restaurant' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS fnb_gross,
            COALESCE(SUM(CASE WHEN booking_type='restaurant' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount   ELSE 0 END), 0) AS fnb_vat,
            COALESCE(SUM(CASE WHEN booking_type='gym'        AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS gym_gross,
            COALESCE(SUM(CASE WHEN booking_type='gym'        AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount   ELSE 0 END), 0) AS gym_vat,
            COALESCE(SUM(CASE WHEN booking_type='event'      AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS events_gross,
            COALESCE(SUM(CASE WHEN booking_type='event'      AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount   ELSE 0 END), 0) AS events_vat,
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

// Department revenue comes from what was POSTED, not from which folio the cash landed on:
// food / drink / room-service charges posted to guest folios today are F&B, so they leave
// room revenue (and ADR / RevPAR). Room revenue = room charges only. The total is unchanged.
$folioFnb = rh_eod_folio_fnb($pdo, $report_date);
$folioFnbMoved = max(0.0, min((float)$folioFnb['gross'], (float)$rev['room_gross']));
if ($folioFnbMoved > 0) {
    $folioFnbVat = (float)$folioFnb['gross'] > 0 ? min((float)$rev['room_vat'], (float)$folioFnb['vat'] * ($folioFnbMoved / (float)$folioFnb['gross'])) : 0.0;
    $rev['room_gross'] = (float)$rev['room_gross'] - $folioFnbMoved;
    $rev['room_vat']   = (float)$rev['room_vat'] - $folioFnbVat;
    $rev['fnb_gross']  = (float)$rev['fnb_gross'] + $folioFnbMoved;
    $rev['fnb_vat']    = (float)$rev['fnb_vat'] + $folioFnbVat;
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
          AND payment_status IN ('completed','paid','refunded','partially_refunded')
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
$pos_totals  = ['orders' => 0, 'gross' => 0.0, 'cogs' => 0.0, 'voided_value' => 0.0, 'voided_count' => 0, 'refunded_value' => 0.0, 'refunded_count' => 0];
$posSummary  = [];
$void_reasons = [];
if ($mod_pos) {
    try {
        // One shared block (admin/includes/pos-shift-totals.php): sales by paid_at in the
        // business window, voids/refunds by their own timestamp, so this agrees with the
        // ledger and the till closes by payment date.
        $posSummary  = rh_pos_eod_summary($pdo, $report_date);
        $pos_by_type = $posSummary['by_type'];
        $pos_totals  = array_merge($pos_totals, $posSummary['totals']);
        $void_reasons = $posSummary['void_reasons'];
    } catch (Throwable $e) {
        error_log('EOD POS: ' . $e->getMessage());
    }
}
$pos_margin = (float)$pos_totals['gross'] - (float)$pos_totals['cogs'];
$pos_margin_pct = $pos_totals['gross'] > 0 ? ($pos_margin / (float)$pos_totals['gross']) * 100 : 0;

$top_items = [];
if ($mod_pos) {
    $top_items = $posSummary['top_items'] ?? [];
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
                COALESCE(SUM(CASE WHEN rate_plan_id IS NOT NULL THEN COALESCE(rate_plan_discount,0) * GREATEST(1, COALESCE(NULLIF(DATEDIFF(check_out_date, check_in_date), 0), 1)) ELSE 0 END),0) AS total_discount_given,
                COALESCE(SUM(COALESCE(package_total,0)),0) AS package_revenue
            FROM bookings
            WHERE deleted_at IS NULL AND DATE(created_at) = :d
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
            WHERE deleted_at IS NULL AND DATE(created_at) = :d AND rate_plan_id IS NOT NULL AND status NOT IN ('cancelled','no-show')
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
            COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS gross_revenue,
            COALESCE(SUM(CASE WHEN booking_type='room' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS room_gross,
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
        $prevPosRow = rh_pos_eod_summary($pdo, $previous_day)['totals'];
        $previous['pos_orders'] = (int)($prevPosRow['orders'] ?? 0);
        $previous['pos_gross'] = (float)($prevPosRow['gross'] ?? 0);
    }

    if ($mod_bookings) {
        $prevBookings = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE DATE(created_at) = :d");
        $prevBookings->execute([':d' => $previous_day]);
        $previous['new_bookings'] = (int)$prevBookings->fetchColumn();

        $prevOcc = $pdo->prepare("
            SELECT COUNT(*) FROM bookings
            WHERE deleted_at IS NULL AND status IN ('checked-in','checked-out')
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
               COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded')
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
        SELECT DATE(paid_at) AS day,
               COALESCE(SUM(total_amount), 0) AS pos_gross
        FROM stock_orders
        WHERE paid_at BETWEEN :a AND :b
          AND status IN ('paid','completed','refunded','voided')
        GROUP BY DATE(paid_at)
    ");
    $tPosStmt->execute([':a' => $trend_start_dt, ':b' => $trend_end_dt]);
    $trend_pos_by_day = [];
    foreach ($tPosStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $trend_pos_by_day[$row['day']] = ['pos_gross' => (float)$row['pos_gross'], 'voids' => 0];
    }
    // Voids count on the day they were voided, never the day the order was opened.
    $tVoidStmt = $pdo->prepare("
        SELECT DATE(voided_at) AS day, COUNT(*) AS voids
        FROM stock_orders
        WHERE status = 'voided' AND voided_at BETWEEN :a AND :b
        GROUP BY DATE(voided_at)
    ");
    $tVoidStmt->execute([':a' => $trend_start_dt, ':b' => $trend_end_dt]);
    foreach ($tVoidStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $trend_pos_by_day[$row['day']] = array_merge($trend_pos_by_day[$row['day']] ?? ['pos_gross' => 0.0], ['voids' => (int)$row['voids']]);
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
              AND p.payment_status IN ('completed','paid','refunded','partially_refunded')
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
                WHERE deleted_at IS NULL AND guest_email != ''
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
            WHERE deleted_at IS NULL AND check_in_date = :d
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
// Already loaded with the POS block above, keyed on the void's own timestamp.

// ---------------------------------------------------------------------------
// ENHANCEMENT E — Previous day per-source revenue for segment comparison
// ---------------------------------------------------------------------------
$prev_sources = ['room_gross' => 0.0, 'conf_gross' => 0.0, 'fnb_gross' => 0.0, 'gym_gross' => 0.0, 'events_gross' => 0.0];
try {
    $psStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN booking_type='room'       AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END), 0) AS room_gross,
            COALESCE(SUM(CASE WHEN booking_type='conference' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END), 0) AS conf_gross,
            COALESCE(SUM(CASE WHEN booking_type='restaurant' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END), 0) AS fnb_gross,
            COALESCE(SUM(CASE WHEN booking_type='gym'        AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END), 0) AS gym_gross,
            COALESCE(SUM(CASE WHEN booking_type='event'      AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END), 0) AS events_gross
        FROM payments
        WHERE DATE(payment_date) = :d
          AND deleted_at IS NULL
    ");
    $psStmt->execute([':d' => $previous_day]);
    $psRow = $psStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $prev_sources = array_merge($prev_sources, $psRow);
    // Same department reclass as today so the day-over-day change compares like with like.
    $prevFolioFnb = rh_eod_folio_fnb($pdo, $previous_day);
    $prevMoved = max(0.0, min((float)$prevFolioFnb['gross'], (float)$prev_sources['room_gross']));
    $prev_sources['room_gross'] = (float)$prev_sources['room_gross'] - $prevMoved;
    $prev_sources['fnb_gross']  = (float)$prev_sources['fnb_gross'] + $prevMoved;
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
    <link rel="stylesheet" href="css/admin-cockpit.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-cockpit.css'); ?>">
    <link rel="stylesheet" href="css/end-of-day.css?v=<?php echo @filemtime(__DIR__ . '/css/end-of-day.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content" data-rh-page-header-normalized="1"><?php /* the cockpit hero is this page's header */ ?>
        <?php
        /* =====================================================================
         * Cockpit view-model — read-only; derived from the figures computed above.
         * =================================================================== */
        $curPlain = (string)$currency_symbol;
        $plain = static function ($v) use ($curPlain): string {
            return $curPlain . number_format((float)$v, 2);
        };
        $eod_billing = function_exists('rh_module_key_enabled') && rh_module_key_enabled('billing');
        $posShort = rh_pos_short_label();
        $posCategory = rh_pos_category_label();

        $kv = static function (string $label, string $value, string $cls = ''): string {
            return '<li><span>' . $label . '</span><strong' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '>' . $value . '</strong></li>';
        };
        $sub = static function (string $text): string {
            return ' <small class="eod-sub">' . $text . '</small>';
        };
        $pillTone = ['good' => 'green', 'bad' => 'red', 'neutral' => 'muted'];

        // Day-over-day arrow for the headline tiles
        $netTone = $trendTone($net_change);
        $netArrow = $net_change > 0.01 ? 'fa-arrow-trend-up' : ($net_change < -0.01 ? 'fa-arrow-trend-down' : 'fa-minus');
        $prevHasTakings = $previous['net_revenue'] > 0.01;
$netPctTxt = abs($net_change) < 0.01 ? 'flat' : ($prevHasTakings ? number_format(abs($net_change_pct), 1) . '%' : 'new');

        // Alerts: warn = red, watch = amber, good = clean
        $alertHref = [
            'fa-person-walking-luggage' => 'bookings.php?filter=checkin_today',
            'fa-door-open'              => 'bookings.php?filter=checkout_today',
            'fa-file-invoice-dollar'    => 'payments.php?balance=outstanding',
            'fa-screwdriver-wrench'     => 'room-maintenance.php',
            'fa-hourglass-half'         => 'payments.php',
            'fa-ban'                    => 'stock-orders.php',
            'fa-triangle-exclamation'   => 'stock-orders.php',
            'fa-rotate-left'            => 'payments.php?refund_status=pending',
            'fa-broom'                  => 'housekeeping.php',
            'fa-sack-dollar'            => 'payments.php?date=' . $report_date,
        ];
        $alertsOpen = array_values(array_filter($closeout_alerts, static fn($a) => $a['level'] !== 'good'));
        $alertsGood = array_values(array_filter($closeout_alerts, static fn($a) => $a['level'] === 'good'));
        usort($alertsOpen, static fn($a, $b) => ($a['level'] === 'warn' ? 0 : 1) <=> ($b['level'] === 'warn' ? 0 : 1));
        $alertCount = count($alertsOpen);

        // Verdict sentence
        $verdict = $daily_health_label . '. Net takings ' . $plain($net_revenue);
        if ($previous['net_revenue'] > 0.01 || $net_revenue > 0.01) {
            $verdict .= abs($net_change) < 0.01 ? ', level with yesterday' : ($prevHasTakings ? ', ' . ($net_change > 0 ? 'up ' : 'down ') . number_format(abs($net_change_pct), 1) . '% on yesterday' : ', with no takings yesterday to compare');
        }
        $verdict .= '. ' . ($alertCount ? $alertCount . ' item' . ($alertCount === 1 ? '' : 's') . ' need' . ($alertCount === 1 ? 's' : '') . ' attention before you close.' : 'Nothing needs attention.');

        $scoreMeter = $daily_health_score >= 90 ? 'good' : ($daily_health_score >= 55 ? 'amber' : 'red');
        $scoreKpi   = $daily_health_score >= 90 ? 'ck-kpi--good' : ($daily_health_score >= 55 ? 'ck-kpi--warn' : 'ck-kpi--alert');

        // Trend chart scaling (net takings per day)
        $chartMax = 1.0;
        foreach ($trend_days as $td0) { $chartMax = max($chartMax, (float)$td0['net']); }
        $trendNetTotal = 0.0;
        foreach ($trend_days as $td0) { $trendNetTotal += (float)$td0['net']; }
        $trendAvg = $trend_days ? $trendNetTotal / count($trend_days) : 0.0;
        $lastTrend = $trend_days ? $trend_days[count($trend_days) - 1] : null;

        // Share of gross for the cash-by-method meters
        $methodBase = max(0.01, $gross_revenue);

        // Detail tabs
        $detailTabs = ['revenue' => ['Revenue', null]];
        if ($mod_bookings) { $detailTabs['front'] = ['Front office', null]; }
        if ($mod_pos)      { $detailTabs['pos'] = [$posShort . ' &amp; POS', (int)$pos_totals['orders']]; }
        if ($mod_bookings) { $detailTabs['rooms'] = ['Room types', null]; }
        $detailTabs['trend'] = ['7-day trend', null];
        $detailTabs['insight'] = ['Yield &amp; guests', null];
        if ($mod_bookings || $eod_billing) { $detailTabs['pricing'] = ['Pricing &amp; quotes', null]; }
        $detailTabs['ops'] = ['Housekeeping &amp; more', null];
        $hkOpen = (int)$housekeeping['pending'] + (int)$housekeeping['in_progress'];
        ?>

        <div class="ck eod" data-ck-root>

        <!-- ============ HERO ============ -->
        <header class="ck-hero">
            <div class="ck-hero__intro">
                <p class="ck-eyebrow">
                    <i class="far fa-calendar"></i> <?php echo htmlspecialchars(date('l, j F Y', strtotime($report_date))); ?>
                    <span class="ck-dot"></span>
                    <span class="ck-pill <?php echo $isToday ? 'ck-pill--green' : 'ck-pill--muted'; ?>"><?php echo $isToday ? 'Live (today)' : 'Archived day'; ?></span>
                </p>
                <h1 class="ck-hero__title">End of Day Report</h1>
                <p class="ck-hero__lede"><?php echo htmlspecialchars($verdict); ?></p>
            </div>
            <nav class="ck-hero__actions eod-noprint" aria-label="Report actions">
                <button type="button" class="ck-btn ck-btn--primary" id="eodSendEmail" data-date="<?php echo htmlspecialchars($report_date); ?>">
                    <i class="fas fa-paper-plane"></i><span>Email</span>
                </button>
                <button type="button" class="ck-btn" id="eodSendWhatsApp" data-date="<?php echo htmlspecialchars($report_date); ?>">
                    <i class="fab fa-whatsapp"></i><span>WhatsApp</span>
                </button>
                <button type="button" class="ck-btn" onclick="window.print()">
                    <i class="fas fa-print"></i><span>Print</span>
                </button>
                <a href="api/end-of-day-pdf.php?date=<?php echo htmlspecialchars($report_date); ?>&csrf=<?php echo urlencode($csrf_token); ?>" class="ck-btn" data-no-spa="1" data-no-admin-loader="1">
                    <i class="fas fa-file-pdf"></i><span>PDF</span>
                </a>
                <a href="end-of-day-report.php?date=<?php echo htmlspecialchars($report_date); ?>&export=csv" class="ck-btn" data-no-spa="1" data-no-admin-loader="1">
                    <i class="fas fa-file-csv"></i><span>CSV</span>
                </a>
            </nav>
        </header>

        <!-- Date navigation + CC for the email -->
        <div class="eod-toolbar eod-noprint">
            <form method="GET" class="eod-datebar" action="end-of-day-report.php">
                <a href="end-of-day-report.php?date=<?php echo htmlspecialchars($previous_day); ?>" class="ck-icon-btn eod-nav" title="Previous day" aria-label="Previous day"><i class="fas fa-chevron-left"></i></a>
                <input type="date" name="date" aria-label="Report date" value="<?php echo htmlspecialchars($report_date); ?>" max="<?php echo date('Y-m-d'); ?>">
                <a href="end-of-day-report.php?date=<?php echo htmlspecialchars($tomorrow); ?>" class="ck-icon-btn eod-nav<?php echo $isToday ? ' is-disabled' : ''; ?>" title="Next day" aria-label="Next day"<?php echo $isToday ? ' aria-disabled="true" tabindex="-1"' : ''; ?>><i class="fas fa-chevron-right"></i></a>
                <button type="submit" class="ck-btn ck-btn--sm"><i class="fas fa-rotate-right"></i><span>Refresh</span></button>
                <a href="end-of-day-report.php?date=<?php echo date('Y-m-d'); ?>" class="ck-btn ck-btn--sm">Today</a>
            </form>
            <div class="eod-cc">
                <label class="eod-cc__label" for="eodCcEmail"><i class="fas fa-user-plus"></i> CC email (optional)</label>
                <input
                    type="email"
                    id="eodCcEmail"
                    class="eod-cc__input"
                    placeholder="e.g. owner@example.com, gm@hotel.com"
                    value="<?php echo htmlspecialchars(getSetting('eod_report_cc_emails') ?? ''); ?>"
                    autocomplete="email">
            </div>
        </div>

        <!-- Send result toast -->
        <div id="eodResultToast" class="eod-toast eod-noprint" role="status" aria-live="polite" hidden></div>

        <!-- ============ KPI STRIP: the verdict ============ -->
        <section class="ck-kpis" aria-label="Day summary">
            <a class="ck-kpi ck-kpi--dark" href="payments.php?date=<?php echo htmlspecialchars($report_date); ?>">
                <span class="ck-kpi__icon"><i class="fas fa-coins"></i></span>
                <span class="ck-kpi__label">Net takings</span>
                <span class="ck-kpi__value ck-kpi__value--money"><?php echo $money($net_revenue); ?></span>
                <?php if ($trend_days): ?>
                <span class="ck-spark" aria-hidden="true">
                    <?php foreach ($trend_days as $td0): ?><span style="height:<?php echo max(6, round(max(0, (float)$td0['net']) / $chartMax * 100)); ?>%"<?php echo $td0['is_today'] ? ' class="is-today"' : ''; ?>></span><?php endforeach; ?>
                </span>
                <?php endif; ?>
                <span class="ck-kpi__sub">
                    <span class="ck-pill ck-pill--<?php echo $pillTone[$netTone]; ?> eod-pill-on-dark"><i class="fas <?php echo $netArrow; ?>"></i> <?php echo $netPctTxt; ?></span>
                    vs yesterday &middot; gross <?php echo $money($gross_revenue); ?><?php if ((float)$rev['refunds'] > 0): ?> &middot; refunds &minus;<?php echo $money($rev['refunds']); ?><?php endif; ?>
                </span>
            </a>

            <?php if ($mod_bookings): ?>
            <a class="ck-kpi" href="room-dashboard.php">
                <span class="ck-kpi__icon"><i class="fas fa-bed"></i></span>
                <span class="ck-kpi__label">Occupancy</span>
                <span class="ck-kpi__value"><?php echo rtrim(rtrim(number_format($occupancy_pct, 1), '0'), '.'); ?><small>%</small></span>
                <span class="ck-meter"><span style="width:<?php echo min(100, max(0, $occupancy_pct)); ?>%"></span></span>
                <span class="ck-kpi__sub">
                    <?php echo (int)$rooms_occupied; ?> of <?php echo (int)$rooms_total; ?> rooms sold<?php if ($rooms_oo > 0): ?> &middot; <?php echo (int)$rooms_oo; ?> out of order<?php endif; ?>
                    <span class="ck-pill ck-pill--<?php echo $pillTone[$trendTone($occupancy_change)]; ?>"><?php echo $trendLabel($occupancy_change, false, ' pts'); ?></span>
                </span>
            </a>
            <div class="ck-kpi">
                <span class="ck-kpi__icon"><i class="fas fa-chart-line"></i></span>
                <span class="ck-kpi__label">ADR</span>
                <span class="ck-kpi__value ck-kpi__value--money"><?php echo $money($adr); ?></span>
                <span class="ck-kpi__sub">RevPAR <strong><?php echo $money($revpar); ?></strong> &middot; room revenue <?php echo $money($rev['room_gross']); ?></span>
            </div>
            <?php endif; ?>

            <a class="ck-kpi" href="#eodCash">
                <span class="ck-kpi__icon"><i class="fas fa-vault"></i></span>
                <span class="ck-kpi__label">Cash to bank</span>
                <span class="ck-kpi__value ck-kpi__value--money"><?php echo $money($method_totals['cash']); ?></span>
                <span class="ck-kpi__sub">Non-cash <?php echo $money($non_cash_total); ?> &middot; <?php echo number_format($payment_capture_rate, 0); ?>% collected</span>
            </a>

            <a class="ck-kpi<?php echo $unpaid_risk > 0.01 ? ' ck-kpi--alert' : ''; ?>" href="payments.php?balance=outstanding">
                <span class="ck-kpi__icon"><i class="fas fa-file-invoice-dollar"></i></span>
                <span class="ck-kpi__label">Still owed</span>
                <span class="ck-kpi__value ck-kpi__value--money"><?php echo $money($unpaid_risk); ?></span>
                <span class="ck-kpi__sub">Folio <?php echo $money($outstanding_folio); ?> &middot; pending <?php echo $money($rev['pending']); ?></span>
            </a>

            <a class="ck-kpi <?php echo $scoreKpi; ?>" href="#eodAttn">
                <span class="ck-kpi__icon"><i class="fas fa-heart-pulse"></i></span>
                <span class="ck-kpi__label">Close score</span>
                <span class="ck-kpi__value"><?php echo (int)$daily_health_score; ?><small>/100</small></span>
                <span class="ck-meter ck-meter--<?php echo $scoreMeter; ?>"><span style="width:<?php echo (int)$daily_health_score; ?>%"></span></span>
                <span class="ck-kpi__sub"><?php echo htmlspecialchars($daily_health_label); ?></span>
            </a>
        </section>

        <!-- ============ BENTO ============ -->
        <div class="ck-bento">

            <!-- Needs attention (driven by the closeout checks) -->
            <section class="ck-panel ck-attn" id="eodAttn">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Needs attention <?php if ($alertCount): ?><span class="ck-badge"><?php echo (int)$alertCount; ?></span><?php endif; ?></h2>
                        <p class="ck-panel__sub">Closeout checks, most urgent first.</p>
                    </div>
                </header>
                <?php if ($alertsOpen): ?>
                <ul class="ck-attn__list">
                    <?php foreach ($alertsOpen as $i => $a):
                        $tone = $a['level'] === 'warn' ? 'red' : 'amber';
                        $href = $alertHref[$a['icon']] ?? '';
                        $tag = $href !== '' ? 'a' : 'div';
                    ?>
                    <li>
                        <<?php echo $tag; ?> class="ck-attn__item ck-attn__item--<?php echo $tone; ?><?php echo $i === 0 ? ' is-top' : ''; ?>"<?php echo $href !== '' ? ' href="' . htmlspecialchars($href) . '"' : ''; ?>>
                            <span class="ck-attn__icon"><i class="fas <?php echo htmlspecialchars($a['icon']); ?>"></i></span>
                            <span class="ck-attn__text">
                                <strong><?php echo htmlspecialchars($a['title']); ?></strong>
                                <small><?php echo $a['detail']; ?></small>
                            </span>
                            <?php if ($href !== ''): ?><span class="ck-attn__go"><i class="fas fa-chevron-right"></i></span><?php endif; ?>
                        </<?php echo $tag; ?>>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                    <?php $goodMsg = $alertsGood[0] ?? ['title' => 'Clean closeout', 'detail' => 'No major exceptions flagged.']; ?>
                    <div class="ck-empty ck-empty--good"><i class="fas fa-circle-check"></i><p><strong><?php echo htmlspecialchars($goodMsg['title']); ?>.</strong> <?php echo $goodMsg['detail']; ?></p></div>
                <?php endif; ?>
            </section>

            <!-- Cash to bank by method -->
            <section class="ck-panel eod-cash" id="eodCash">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Cash to bank</h2>
                        <p class="ck-panel__sub">Count the till; it must match.</p>
                    </div>
                </header>
                <div class="ck-money__headline">
                    <span class="ck-money__label">Cash to reconcile</span>
                    <span class="ck-money__value"><?php echo $money($method_totals['cash']); ?></span>
                </div>
                <?php if (empty($method_mix)): ?>
                    <div class="ck-empty"><i class="fas fa-wallet"></i><p>No payments recorded <?php echo $isToday ? 'today' : 'on this day'; ?>.</p></div>
                <?php else: ?>
                <ul class="ck-levels">
                    <?php foreach ($method_mix as $m):
                        $mShare = min(100, max(2, ((float)$m['total'] / $methodBase) * 100));
                        $mLabel = ucwords(str_replace('_', ' ', (string)$m['method']));
                    ?>
                    <li>
                        <span class="ck-levels__name"><?php echo htmlspecialchars($mLabel); ?></span>
                        <span class="ck-levels__qty"><strong><?php echo $money($m['total']); ?></strong> &middot; <?php echo (int)$m['cnt']; ?> txn</span>
                        <span class="ck-meter ck-meter--gold"><span style="width:<?php echo number_format($mShare, 1, '.', ''); ?>%"></span></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <ul class="ck-kv eod-kv">
                    <?php echo $kv('Non-cash collected', $money($non_cash_total)); ?>
                    <?php echo $kv('Pending today', $money($rev['pending']), (float)$rev['pending'] > 0 ? 'eod-warn' : ''); ?>
                    <?php echo $kv('Collection rate', number_format($payment_capture_rate, 1) . '%'); ?>
                </ul>
            </section>

            <!-- Takings, 7 days -->
            <section class="ck-panel ck-money eod-takings">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Takings, 7 days</h2>
                        <p class="ck-panel__sub"><?php echo htmlspecialchars($plain($trendNetTotal)); ?> net &middot; avg <?php echo htmlspecialchars($plain($trendAvg)); ?>/day</p>
                    </div>
                </header>
                <?php if ($trend_days): ?>
                <div class="ck-money__headline" aria-live="polite">
                    <span class="ck-money__label" data-ck-trend-label><?php echo htmlspecialchars($lastTrend['label']); ?></span>
                    <span class="ck-money__value" data-ck-trend-value><?php echo htmlspecialchars($plain($lastTrend['net'])); ?></span>
                    <span class="eod-sub" data-ck-trend-sub><?php echo $mod_pos ? htmlspecialchars($posShort . ' ' . $plain($lastTrend['pos_gross'])) . ' &middot; ' : ''; ?><?php echo (int)$lastTrend['voids']; ?> void<?php echo (int)$lastTrend['voids'] === 1 ? '' : 's'; ?></span>
                </div>
                <div class="ck-bars" role="list" aria-label="Net takings, last 7 days">
                    <?php foreach ($trend_days as $td0):
                        $isLast = $td0['is_today'];
                        $subTxt = ($mod_pos ? $posShort . ' ' . $plain($td0['pos_gross']) . ' · ' : '') . (int)$td0['voids'] . ' void' . ((int)$td0['voids'] === 1 ? '' : 's');
                    ?>
                    <button type="button" role="listitem" class="ck-bars__col<?php echo $isLast ? ' is-today is-active' : ''; ?>"
                        data-ck-trend="<?php echo htmlspecialchars($plain($td0['net'])); ?>"
                        data-ck-trend-day="<?php echo htmlspecialchars($td0['label']); ?>"
                        data-ck-trend-extra="<?php echo htmlspecialchars($subTxt); ?>"
                        aria-label="<?php echo htmlspecialchars($td0['label'] . ': ' . $plain($td0['net'])); ?>">
                        <span class="ck-bars__bar"><span style="height:<?php echo max(3, round(max(0, (float)$td0['net']) / $chartMax * 100)); ?>%"></span></span>
                        <span class="ck-bars__day"><?php echo htmlspecialchars(substr($td0['label'], 0, 3)); ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <div class="ck-empty"><i class="fas fa-chart-column"></i><p>Trend data is unavailable.</p></div>
                <?php endif; ?>
            </section>

            <!-- Versus yesterday -->
            <section class="ck-panel eod-moved">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">What moved</h2>
                        <p class="ck-panel__sub">Today against <?php echo htmlspecialchars(date('D j M', strtotime($previous_day))); ?>.</p>
                    </div>
                </header>
                <ul class="ck-kv eod-kv">
                    <?php
                    $mv = static function (string $label, float $delta, string $valueHtml, string $extra = '') use ($trendTone): string {
                        return '<li><span>' . $label . '</span><strong class="eod-trend eod-trend--' . $trendTone($delta) . '">' . $valueHtml . $extra . '</strong></li>';
                    };
                    echo $mv('Net revenue', $net_change, $trendLabel($net_change, true), ' <small>' . $trendLabel($net_change_pct, false, '%') . '</small>');
                    if ($mod_bookings) { echo $mv('Rooms revenue', $room_rev_change, $trendLabel($room_rev_change, true)); }
                    if ($mod_conference && ((float)$rev['conf_gross'] > 0 || (float)$prev_sources['conf_gross'] > 0)) { echo $mv('Conference revenue', $conf_rev_change, $trendLabel($conf_rev_change, true)); }
                    if ($mod_pos) { echo $mv(htmlspecialchars($posShort), $fnb_rev_change, $trendLabel($fnb_rev_change, true)); }
                    if ($mod_gym && ((float)$rev['gym_gross'] > 0 || (float)$prev_sources['gym_gross'] > 0)) { echo $mv('Gym revenue', $gym_rev_change, $trendLabel($gym_rev_change, true)); }
                    if ($mod_events && ((float)$rev['events_gross'] > 0 || (float)$prev_sources['events_gross'] > 0)) { echo $mv('Event revenue', $events_rev_change, $trendLabel($events_rev_change, true)); }
                    if ($mod_bookings) { echo $mv('Occupancy', $occupancy_change, $trendLabel($occupancy_change, false, ' pts')); }
                    if ($mod_pos) {
                        echo $mv('POS sales', $pos_change, $trendLabel($pos_change, true), ' <small>' . $trendLabel($pos_change_pct, false, '%') . '</small>');
                        echo $mv('Average POS order', $order_value_change, $money($average_order_value));
                    }
                    if ($mod_bookings) {
                        $nbDelta = (float)((int)$ops['new_bookings'] - $previous['new_bookings']);
                        echo $mv('New bookings', $nbDelta, (int)$ops['new_bookings'] . ' <small>' . $trendLabel($nbDelta) . '</small>');
                    }
                    ?>
                </ul>
            </section>

            <?php if ($mod_bookings): ?>
            <!-- Front desk at a glance -->
            <section class="ck-panel eod-front">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Front desk</h2>
                        <p class="ck-panel__sub">How the day's movements closed.</p>
                    </div>
                    <div class="ck-panel__tools"><a class="ck-link" href="bookings.php">Bookings <i class="fas fa-arrow-right"></i></a></div>
                </header>
                <?php
                $arrPct = (int)$ops['expected_arrivals'] > 0 ? min(100, round((int)$ops['arrivals_completed'] / (int)$ops['expected_arrivals'] * 100)) : 100;
                $depPct = (int)$ops['expected_departures'] > 0 ? min(100, round((int)$ops['departures_completed'] / (int)$ops['expected_departures'] * 100)) : 100;
                ?>
                <ul class="ck-levels">
                    <li>
                        <span class="ck-levels__name">Arrivals</span>
                        <span class="ck-levels__qty"><strong><?php echo (int)$ops['arrivals_completed']; ?></strong> of <?php echo (int)$ops['expected_arrivals']; ?> checked in</span>
                        <span class="ck-meter ck-meter--<?php echo $arrivals_remaining > 0 ? 'amber' : 'good'; ?>"><span style="width:<?php echo $arrPct; ?>%"></span></span>
                    </li>
                    <li>
                        <span class="ck-levels__name">Departures</span>
                        <span class="ck-levels__qty"><strong><?php echo (int)$ops['departures_completed']; ?></strong> of <?php echo (int)$ops['expected_departures']; ?> checked out</span>
                        <span class="ck-meter ck-meter--<?php echo $departures_remaining > 0 ? 'amber' : 'good'; ?>"><span style="width:<?php echo $depPct; ?>%"></span></span>
                    </li>
                </ul>
                <ul class="ck-kv eod-kv">
                    <?php
                    echo $kv('Stay-overs', (int)$ops['stayovers'] . $sub('in-house tonight'));
                    echo $kv('New bookings', (string)(int)$ops['new_bookings']);
                    echo $kv('Cancellations', (string)(int)$ops['cancellations'], (int)$ops['cancellations'] > 0 ? 'eod-warn' : '');
                    echo $kv('No-shows', (string)(int)$ops['no_shows'], (int)$ops['no_shows'] > 0 ? 'eod-warn' : '');
                    ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($mod_bookings): ?>
            <!-- Tomorrow -->
            <section class="ck-panel eod-tomorrow">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Tomorrow</h2>
                        <p class="ck-panel__sub"><?php echo htmlspecialchars(date('l, M j', strtotime($tomorrow))); ?></p>
                    </div>
                    <div class="ck-panel__tools"><a class="ck-link" href="bookings.php">Bookings <i class="fas fa-arrow-right"></i></a></div>
                </header>
                <div class="ck-stock__trio">
                    <div class="ck-mini"><strong><?php echo (int)$tomorrow_preview['arrivals']; ?></strong><span>Arrivals</span></div>
                    <div class="ck-mini"><strong><?php echo (int)$tomorrow_preview['departures']; ?></strong><span>Departures</span></div>
                    <div class="ck-mini"><strong><?php echo (int)$ops['stayovers']; ?></strong><span>Stay-overs</span></div>
                </div>
                <ul class="ck-kv eod-kv">
                    <?php echo $kv('Forecast revenue', $money($tomorrow_preview['rev_forecast'])); ?>
                </ul>
            </section>
            <?php endif; ?>

            <!-- ============ DETAIL (tabs) ============ -->
            <section class="ck-panel ck-span-3 eod-detail" data-ck-tabs>
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">The day in detail</h2>
                        <p class="ck-panel__sub">Every figure behind the headlines. Printing shows all sections.</p>
                    </div>
                    <div class="ck-tabs eod-noprint" role="tablist">
                        <?php $firstTab = true; foreach ($detailTabs as $key => [$label, $n]): ?>
                            <button type="button" class="ck-tab<?php echo $firstTab ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $firstTab ? 'true' : 'false'; ?>" data-ck-tab="<?php echo $key; ?>">
                                <?php echo $label; ?><?php if ($n !== null): ?> <span class="ck-tab__count"><?php echo (int)$n; ?></span><?php endif; ?>
                            </button>
                        <?php $firstTab = false; endforeach; ?>
                    </div>
                </header>

                <!-- Revenue -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="revenue">
                    <h3 class="eod-pane-title">Revenue</h3>
                    <div class="eod-table-wrap">
                        <table class="eod-table no-auto-pagination no-card-mobile">
                            <thead>
                                <tr>
                                    <th>Source</th>
                                    <th class="num">Gross</th>
                                    <th class="num">VAT</th>
                                    <th class="num">Mix</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $rows = [];
                                if ($mod_bookings)   { $rows[] = ['Rooms',         (float)$rev['room_gross'], (float)$rev['room_vat']]; }
                                if ($mod_conference) { $rows[] = ['Conferences',   (float)$rev['conf_gross'], (float)$rev['conf_vat']]; }
                                if ($mod_pos)        { $rows[] = [htmlspecialchars($posCategory), (float)$rev['fnb_gross'],  (float)$rev['fnb_vat']]; }
                                if ($mod_gym)        { $rows[] = ['Gym',          (float)$rev['gym_gross'],  (float)$rev['gym_vat']]; }
                                if ($mod_events)     { $rows[] = ['Events',       (float)$rev['events_gross'], (float)$rev['events_vat']]; }
                                foreach ($rows as $r):
                                    $share = $gross_revenue > 0 ? ($r[1] / $gross_revenue) * 100 : 0;
                                ?>
                                    <tr>
                                        <td><?php echo $r[0]; ?></td>
                                        <td class="num"><?php echo $money($r[1]); ?></td>
                                        <td class="num"><?php echo $money($r[2]); ?></td>
                                        <td class="num eod-mixcell"><span class="ck-meter ck-meter--gold"><span style="width:<?php echo number_format(min(100, $share), 1, '.', ''); ?>%"></span></span><?php echo number_format($share, 1); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="eod-table__total">
                                    <td>Total</td>
                                    <td class="num"><?php echo $money($gross_revenue); ?></td>
                                    <td class="num"><?php echo $money($total_vat); ?></td>
                                    <td class="num">100%</td>
                                </tr>
                                <?php if ((float)$rev['refunds'] > 0): ?>
                                    <tr class="eod-table__neg">
                                        <td>Less: refunds</td>
                                        <td class="num">&minus;<?php echo $money($rev['refunds']); ?></td>
                                        <td class="num">&mdash;</td>
                                        <td class="num">&mdash;</td>
                                    </tr>
                                <?php endif; ?>
                                <tr class="eod-table__net">
                                    <td><strong>Net</strong></td>
                                    <td class="num"><strong><?php echo $money($net_revenue); ?></strong></td>
                                    <td class="num">&mdash;</td>
                                    <td class="num">&mdash;</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <ul class="ck-kv eod-kv eod-kv--cols">
                        <?php
                        echo $kv('VAT collected', $money($total_vat) . $sub('VAT ' . ($vatEnabled ? 'enabled' : 'disabled')));
                        echo $kv('Transactions', (int)$rev['txn_count'] . $sub('avg ' . $money($revenue_per_transaction)));
                        echo $kv('Outstanding folio', $money($outstanding_folio) . ' <a class="eod-link" href="payments.php">Collect &rarr;</a>', $outstanding_folio > 0 ? 'eod-warn' : '');
                        if ($cn_issued_count > 0 || $cn_redeemed_today > 0) {
                            $cnLink = (function_exists('rh_module_key_enabled') && rh_module_key_enabled('advance_booking')) ? ' <a class="eod-link" href="credit-notes.php">View &rarr;</a>' : '';
                            echo $kv('Credit notes issued', $money($cn_issued_today) . $sub((int)$cn_issued_count . ' note' . ($cn_issued_count !== 1 ? 's' : '')) . $cnLink);
                            echo $kv('Credit notes redeemed', $money($cn_redeemed_today) . $sub('applied to bookings'));
                        }
                        ?>
                    </ul>
                </div>

                <?php if ($mod_bookings): ?>
                <!-- Front office -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="front" hidden>
                    <h3 class="eod-pane-title">Front office</h3>
                    <div class="eod-split">
                        <ul class="ck-kv eod-kv">
                            <?php
                            echo $kv('Expected arrivals', (int)$ops['expected_arrivals'] . $sub((int)$ops['arrivals_completed'] . ' checked in'));
                            echo $kv('Expected departures', (int)$ops['expected_departures'] . $sub((int)$ops['departures_completed'] . ' checked out'));
                            echo $kv('Stay-overs', (int)$ops['stayovers'] . $sub('in-house tonight'));
                            ?>
                        </ul>
                        <ul class="ck-kv eod-kv">
                            <?php
                            echo $kv('New bookings', (int)$ops['new_bookings'] . $sub('created today'));
                            echo $kv('Cancellations', (string)(int)$ops['cancellations'], (int)$ops['cancellations'] > 0 ? 'eod-warn' : '');
                            echo $kv('No-shows', (string)(int)$ops['no_shows'], (int)$ops['no_shows'] > 0 ? 'eod-warn' : '');
                            ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($mod_pos): ?>
                <!-- POS -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="pos" hidden>
                    <h3 class="eod-pane-title">POS<?php echo isRestaurantEnabled() ? ' / F&amp;B' : ''; ?></h3>
                    <div class="eod-split">
                        <ul class="ck-kv eod-kv">
                            <?php
                            echo $kv('Orders', (string)(int)$pos_totals['orders']);
                            echo $kv('Gross', $money($pos_totals['gross']));
                            echo $kv('COGS', $money($pos_totals['cogs']));
                            echo $kv('Margin', number_format($pos_margin_pct, 1) . '%');
                            echo $kv('Voids', (int)$pos_totals['voided_count'] . ' &middot; ' . $money($pos_totals['voided_value']), (int)$pos_totals['voided_count'] > 0 ? 'eod-warn' : '');
                            if ((int)($pos_totals['refunded_count'] ?? 0) > 0) {
                                echo $kv('Refunds paid out', (int)$pos_totals['refunded_count'] . ' &middot; ' . $money($pos_totals['refunded_value']), 'eod-warn');
                            }
                            ?>
                        </ul>
                        <div>
                            <?php if (!empty($pos_by_type)): ?>
                            <div class="eod-table-wrap">
                                <table class="eod-table no-auto-pagination no-card-mobile">
                                    <thead><tr><th>Order type</th><th class="num">Orders</th><th class="num">Gross</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($pos_by_type as $p): $label = $order_type_labels[$p['order_type']] ?? ucfirst((string)$p['order_type']); ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($label); ?></td>
                                                <td class="num"><?php echo (int)$p['cnt']; ?></td>
                                                <td class="num"><?php echo $money($p['gross']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($void_reasons)): ?>
                            <div class="eod-table-wrap">
                                <table class="eod-table no-auto-pagination no-card-mobile">
                                    <thead><tr><th>Void reason</th><th class="num">Count</th><th class="num">Value</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($void_reasons as $vr): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)$vr['reason']); ?></td>
                                                <td class="num"><?php echo (int)$vr['cnt']; ?></td>
                                                <td class="num eod-warn"><?php echo $money($vr['value']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <h4 class="eod-sub-title">Top selling items</h4>
                    <?php if (empty($top_items)): ?>
                        <div class="ck-empty"><i class="fas fa-receipt"></i><p>No POS sales recorded <?php echo $isToday ? 'today' : 'on this day'; ?>.</p></div>
                    <?php else: ?>
                    <div class="eod-table-wrap">
                        <table class="eod-table no-auto-pagination no-card-mobile">
                            <thead><tr><th>Item</th><th>Type</th><th class="num">Qty</th><th class="num">Revenue</th></tr></thead>
                            <tbody>
                                <?php foreach ($top_items as $it): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$it['item_name']); ?></td>
                                        <td><span class="eod-tag eod-tag--<?php echo htmlspecialchars((string)$it['menu_type']); ?>"><?php echo htmlspecialchars(ucfirst((string)$it['menu_type'])); ?></span></td>
                                        <td class="num"><?php echo rtrim(rtrim(number_format((float)$it['qty'], 2, '.', ''), '0'), '.'); ?></td>
                                        <td class="num"><?php echo $money($it['revenue']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($mod_bookings): ?>
                <!-- Room types -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="rooms" hidden>
                    <h3 class="eod-pane-title">Room type revenue</h3>
                    <?php if (empty($room_type_perf)): ?>
                        <div class="ck-empty"><i class="fas fa-bed"></i><p>No room payments recorded <?php echo $isToday ? 'today' : 'on this day'; ?>.</p></div>
                    <?php else: ?>
                    <div class="eod-table-wrap">
                        <table class="eod-table no-auto-pagination no-card-mobile">
                            <thead><tr><th>Room type</th><th class="num">Bookings</th><th class="num">Revenue</th><th class="num">Share</th></tr></thead>
                            <tbody>
                                <?php foreach ($room_type_perf as $rt):
                                    $rt_share = $rev['room_gross'] > 0 ? min(100, ((float)$rt['revenue'] / (float)$rev['room_gross']) * 100) : 0;
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$rt['room_type']); ?></td>
                                        <td class="num"><?php echo (int)$rt['bookings']; ?></td>
                                        <td class="num"><?php echo $money($rt['revenue']); ?></td>
                                        <td class="num eod-mixcell"><span class="ck-meter ck-meter--gold"><span style="width:<?php echo number_format($rt_share, 1, '.', ''); ?>%"></span></span><?php echo number_format($rt_share, 1); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- 7-day trend -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="trend" hidden>
                    <h3 class="eod-pane-title">7-day revenue trend <span class="eod-sub"><?php echo htmlspecialchars(date('M j', strtotime($trend_start))); ?> – <?php echo htmlspecialchars(date('M j', strtotime($report_date))); ?></span></h3>
                    <div class="eod-table-wrap">
                        <table class="eod-table eod-table--trend no-auto-pagination no-card-mobile">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th class="num">Net rev</th>
                                    <?php if ($mod_pos): ?><th class="num"><?php echo htmlspecialchars($posShort); ?></th><?php endif; ?>
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
                                        <td><strong><?php echo htmlspecialchars($td['label']); ?></strong><?php echo $is_today ? ' <span class="eod-tag eod-tag--today">Selected</span>' : ''; ?></td>
                                        <td class="num"><?php echo $money($td['net']); ?></td>
                                        <?php if ($mod_pos): ?><td class="num"><?php echo $money($td['pos_gross']); ?></td><?php endif; ?>
                                        <td>
                                            <div class="eod-trend-bar">
                                                <div class="eod-trend-bar__fill<?php echo $is_today ? ' eod-trend-bar__fill--today' : ''; ?>" style="width:<?php echo number_format($bar_width, 1, '.', ''); ?>%"></div>
                                                <span class="eod-trend-bar__val"><?php echo $money($combined); ?></span>
                                            </div>
                                        </td>
                                        <td class="num<?php echo $td['voids'] > 0 ? ' eod-warn' : ''; ?>"><?php echo $td['voids'] > 0 ? (int)$td['voids'] : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Yield & guests -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="insight" hidden>
                    <h3 class="eod-pane-title">Yield, guests &amp; exposure</h3>
                    <div class="eod-split">
                        <?php if ($mod_bookings): ?>
                        <div>
                            <h4 class="eod-sub-title">Yield &amp; opportunity</h4>
                            <ul class="ck-kv eod-kv">
                                <?php
                                echo $kv('Unsold rooms', (string)(int)$rooms_unsold);
                                echo $kv('Empty-room opportunity', $money($empty_room_opportunity));
                                if ($mod_pos) { echo $kv(htmlspecialchars($posShort) . ' per occupied room', $money($fnb_per_occupied_room)); }
                                echo $kv('Top revenue source', htmlspecialchars($top_revenue_source['label']) . $sub(number_format($top_revenue_source_share, 1) . '%'));
                                ?>
                            </ul>
                        </div>
                        <div>
                            <h4 class="eod-sub-title">Guest intelligence</h4>
                            <?php if ($guest_intel_total === 0): ?>
                                <div class="ck-empty"><i class="fas fa-user-group"></i><p>No arrival data to analyse.</p></div>
                            <?php else: ?>
                            <ul class="ck-kv eod-kv">
                                <?php
                                echo $kv('New guests', (string)(int)$guest_intel['new_guests']);
                                echo $kv('Returning guests', (int)$guest_intel['returning_guests'] . $sub(number_format($returning_rate, 1) . '% repeat rate'));
                                echo $kv('Avg booking lead time', (int)$guest_intel['avg_lead_days'] . ' day' . ($guest_intel['avg_lead_days'] === 1 ? '' : 's') . $sub(htmlspecialchars($lead_time_label)));
                                ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div>
                            <h4 class="eod-sub-title">Best seller &amp; risk</h4>
                            <ul class="ck-kv eod-kv">
                                <?php
                                echo $kv('Top POS item', htmlspecialchars($top_item_name) . ($top_item_revenue > 0 ? $sub($money($top_item_revenue)) : ''));
                                echo $kv('Unpaid exposure', $money($unpaid_risk) . $sub('pending + folio'), $unpaid_risk > 0 ? 'eod-warn' : '');
                                ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <?php if ($mod_bookings || $eod_billing): ?>
                <!-- Pricing & quotes -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="pricing" hidden>
                    <h3 class="eod-pane-title">Pricing, packages &amp; quotations</h3>
                    <div class="eod-split">
                        <?php if ($mod_bookings): ?>
                        <div>
                            <h4 class="eod-sub-title">Dynamic pricing &amp; packages</h4>
                            <?php if ($dynamic_pricing['bookings_with_rate_plan'] === 0 && $dynamic_pricing['packages_booked'] === 0): ?>
                                <div class="ck-empty"><i class="fas fa-tags"></i><p>No rate plans or packages applied in today&rsquo;s bookings.</p></div>
                            <?php else: ?>
                            <ul class="ck-kv eod-kv">
                                <?php
                                echo $kv('Rate-plan bookings', (int)$dynamic_pricing['bookings_with_rate_plan'] . ($dynamic_pricing['top_rate_plan'] ? $sub(htmlspecialchars($dynamic_pricing['top_rate_plan'])) : ''));
                                echo $kv('Total discounts given', $money($dynamic_pricing['total_discount_given']), 'eod-warn');
                                echo $kv('Package add-on revenue', $money($dynamic_pricing['package_revenue']) . $sub((int)$dynamic_pricing['packages_booked'] . ' package' . ((int)$dynamic_pricing['packages_booked'] === 1 ? '' : 's')), 'eod-good');
                                if ($dynamic_pricing['top_package']) { echo $kv('Top package', htmlspecialchars($dynamic_pricing['top_package'])); }
                                ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($eod_billing): ?>
                        <div>
                            <h4 class="eod-sub-title">Quotations <a class="eod-link" href="quotations.php">View all &rarr;</a></h4>
                            <ul class="ck-kv eod-kv">
                                <?php
                                echo $kv('Sent today', (string)(int)$quotation_stats['sent_today']);
                                echo $kv('Accepted today', (string)(int)$quotation_stats['accepted_today'], 'eod-good');
                                echo $kv('Active open quotes', (int)$quotation_stats['total_active'] . $sub($money($quotation_stats['pipeline_value']) . ' pipeline'));
                                ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Housekeeping & more -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="ops" hidden>
                    <h3 class="eod-pane-title">Housekeeping, maintenance &amp; guest feedback</h3>
                    <div class="eod-split">
                        <?php if ($mod_housekeeping): ?>
                        <div>
                            <h4 class="eod-sub-title">Housekeeping <a class="eod-link" href="housekeeping.php">Open &rarr;</a></h4>
                            <div class="ck-stock__trio">
                                <div class="ck-mini<?php echo (int)$housekeeping['pending'] > 0 ? ' ck-mini--amber' : ''; ?>"><strong><?php echo (int)$housekeeping['pending']; ?></strong><span>Pending</span></div>
                                <div class="ck-mini"><strong><?php echo (int)$housekeeping['in_progress']; ?></strong><span>In progress</span></div>
                                <div class="ck-mini"><strong><?php echo (int)$housekeeping['completed']; ?></strong><span>Done today</span></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($mod_bookings): ?>
                        <div>
                            <h4 class="eod-sub-title">Open maintenance <a class="eod-link" href="room-maintenance.php">View all &rarr;</a></h4>
                            <?php if ($maintenance['total_open'] > 0): ?>
                            <ul class="ck-kv eod-kv">
                                <?php
                                if ($maintenance['urgent'] > 0) { echo $kv('Urgent', (string)$maintenance['urgent'], 'eod-warn'); }
                                if ($maintenance['high'] > 0) { echo $kv('High priority', (string)$maintenance['high'], 'eod-warn'); }
                                if ($maintenance['medium'] > 0) { echo $kv('Medium', (string)$maintenance['medium']); }
                                echo $kv('Total open tasks', (string)$maintenance['total_open'], 'eod-warn');
                                ?>
                            </ul>
                            <?php else: ?>
                                <div class="ck-empty ck-empty--good"><i class="fas fa-circle-check"></i><p>No open maintenance tasks.</p></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div>
                            <h4 class="eod-sub-title">Reviews <?php if ($reviews['count'] > 0): ?><a class="eod-link" href="reviews.php">View all &rarr;</a><?php endif; ?></h4>
                            <?php if ($reviews['count'] === 0): ?>
                                <div class="ck-empty"><i class="fas fa-star"></i><p>No reviews submitted <?php echo $isToday ? 'today' : 'on this day'; ?>.</p></div>
                            <?php else: ?>
                            <ul class="ck-kv eod-kv">
                                <?php
                                echo $kv('Average rating', number_format($reviews['avg'], 1) . $sub('/ 5'));
                                echo $kv('Reviews', (string)(int)$reviews['count']);
                                ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                        <?php if ($gym_inquiries_today > 0): ?>
                        <div>
                            <h4 class="eod-sub-title">Gym inquiries <a class="eod-link" href="gym-inquiries.php">View &rarr;</a></h4>
                            <ul class="ck-kv eod-kv"><?php echo $kv('Pending response', $gym_inquiries_today . $sub('new inquir' . ($gym_inquiries_today === 1 ? 'y' : 'ies'))); ?></ul>
                        </div>
                        <?php endif; ?>
                        <?php if ($event_bookings_today > 0): ?>
                        <div>
                            <h4 class="eod-sub-title">Event bookings <a class="eod-link" href="events-inquiries.php">View &rarr;</a></h4>
                            <ul class="ck-kv eod-kv"><?php echo $kv('Pending response', $event_bookings_today . $sub('new booking' . ($event_bookings_today === 1 ? '' : 's'))); ?></ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>

        <!-- Footer note -->
        <footer class="eod-footer">
            <p>Generated by <?php echo htmlspecialchars($user['full_name'] ?: 'Admin'); ?> at <?php echo date('Y-m-d H:i'); ?> &middot; All figures in <?php echo htmlspecialchars(trim($currency_symbol)); ?>.</p>
        </footer>

        </div><!-- /.ck -->
    </div>

    <script>
        (function() {
            const csrf = window._rhCsrf || '<?php echo htmlspecialchars($csrf_token ?? ''); ?>';

            // ---- Result toast (state classes, no inline styles) ----
            function showResult(title, html, isError) {
                const toast = document.getElementById('eodResultToast');
                const text = html.replace(/<[^>]+>/g, '');
                if (!toast) {
                    if (window.Alert && Alert.show) { Alert.show(title + ' - ' + text, isError ? 'error' : 'success'); }
                    return;
                }
                toast.textContent = '';
                const strong = document.createElement('strong');
                strong.textContent = title;
                toast.appendChild(strong);
                toast.appendChild(document.createTextNode(' — ' + text));
                toast.classList.toggle('eod-toast--error', !!isError);
                toast.classList.toggle('eod-toast--ok', !isError);
                toast.hidden = false;
                clearTimeout(toast._hideTimer);
                toast._hideTimer = setTimeout(() => {
                    toast.hidden = true;
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
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Sending…</span>';

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

            // ---- Cockpit interactions: detail tabs + takings readout ----
            document.querySelectorAll('[data-ck-tabs]').forEach(function(panel) {
                const tabs = panel.querySelectorAll('[data-ck-tab]');
                tabs.forEach(function(tab) {
                    tab.addEventListener('click', function() {
                        tabs.forEach(function(t) {
                            const on = t === tab;
                            t.classList.toggle('is-active', on);
                            t.setAttribute('aria-selected', on ? 'true' : 'false');
                        });
                        panel.querySelectorAll('[data-ck-pane]').forEach(function(pane) {
                            pane.hidden = pane.dataset.ckPane !== tab.dataset.ckTab;
                        });
                    });
                });
            });

            document.querySelectorAll('.ck-money').forEach(function(box) {
                const label = box.querySelector('[data-ck-trend-label]');
                const value = box.querySelector('[data-ck-trend-value]');
                const extra = box.querySelector('[data-ck-trend-sub]');
                const cols = box.querySelectorAll('[data-ck-trend]');
                cols.forEach(function(col) {
                    function show() {
                        cols.forEach(function(c) {
                            c.classList.toggle('is-active', c === col);
                        });
                        if (label) label.textContent = col.dataset.ckTrendDay;
                        if (value) value.textContent = col.dataset.ckTrend;
                        if (extra) extra.textContent = col.dataset.ckTrendExtra || '';
                    }
                    col.addEventListener('mouseenter', show);
                    col.addEventListener('focus', show);
                    col.addEventListener('click', show);
                });
            });
        })();
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
