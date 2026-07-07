<?php

/**
 * End of Day Report sender.
 *
 * POST JSON: { "date": "YYYY-MM-DD", "channel": "email|whatsapp", "csrf": "..." }
 *
 * Builds a snapshot of the day's KPIs and sends it via email (HTML body)
 * or WhatsApp (plain-text summary).
 */

declare(strict_types=1);

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../../includes/report-mailer.php';
require_once __DIR__ . '/../../includes/whatsapp-functions.php';

header('Content-Type: application/json');

/** @var PDO $pdo */
/** @var array $user */

requireApiPermission('reports');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$csrf    = (string)($body['csrf']     ?? '');
$date    = (string)($body['date']     ?? date('Y-m-d'));
$channel = (string)($body['channel']  ?? 'email');
$cc_raw  = (string)($body['cc_email'] ?? '');

if (!function_exists('validateCsrfToken') || !validateCsrfToken($csrf)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token. Please reload the page.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
    $date = date('Y-m-d');
}
if (!in_array($channel, ['email', 'whatsapp'], true)) {
    echo json_encode(['success' => false, 'error' => 'Unknown channel']);
    exit;
}

// Parse optional CC emails (comma/semicolon/space-separated)
$cc_emails = [];
foreach (preg_split('/[,;\s]+/', $cc_raw) as $em) {
    $em = trim($em);
    if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
        $cc_emails[] = $em;
    }
}

$site_name       = getSetting('site_name') ?: "Rosalyn's Beach Hotel";
$currency_symbol = getSetting('currency_symbol') ?: 'K ';

// -----------------------------------------------------------------------------
// Re-run the same KPI queries that the dashboard page uses.
// -----------------------------------------------------------------------------
$dayStart = $date . ' 00:00:00';
$dayEnd   = $date . ' 23:59:59';

function _money(string $sym, int|float $v): string
{
    return $sym . number_format((float)$v, 2);
}

try {
    // Ops
    $opsStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN check_in_date = ? AND status IN ('confirmed','tentative','pending','checked-in') THEN 1 ELSE 0 END) AS expected_arrivals,
            SUM(CASE WHEN check_in_date = ? AND status = 'checked-in' THEN 1 ELSE 0 END) AS arrivals_completed,
            SUM(CASE WHEN check_out_date = ? AND status IN ('checked-in','checked-out') THEN 1 ELSE 0 END) AS expected_departures,
            SUM(CASE WHEN check_out_date = ? AND status = 'checked-out' THEN 1 ELSE 0 END) AS departures_completed,
            SUM(CASE WHEN check_in_date < ? AND check_out_date > ? AND status = 'checked-in' THEN 1 ELSE 0 END) AS stayovers,
            SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) AS new_bookings,
            SUM(CASE WHEN DATE(updated_at) = ? AND status = 'cancelled' THEN 1 ELSE 0 END) AS cancellations,
            SUM(CASE WHEN check_in_date = ? AND status = 'expired' THEN 1 ELSE 0 END) AS no_shows
        FROM bookings
    ");
    $opsStmt->execute(array_fill(0, 9, $date));
    $ops = $opsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Occupancy
    $rooms_total = (int)$pdo->query("SELECT COUNT(*) FROM individual_rooms WHERE status <> 'out_of_order'")->fetchColumn();
    $rooms_oo = (int)$pdo->query("SELECT COUNT(*) FROM individual_rooms WHERE status = 'out_of_order'")->fetchColumn();
    $occStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status IN ('checked-in','checked-out') AND check_in_date <= ? AND check_out_date > ?");
    $occStmt->execute([$date, $date]);
    $rooms_occupied = (int)$occStmt->fetchColumn();
    $occupancy_pct  = $rooms_total > 0 ? ($rooms_occupied / $rooms_total) * 100 : 0;

    // Revenue
    $payStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN booking_type='room'       AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS room_gross,
            COALESCE(SUM(CASE WHEN booking_type='conference' AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS conf_gross,
            COALESCE(SUM(CASE WHEN booking_type='restaurant' AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS fnb_gross,
            COALESCE(SUM(CASE WHEN booking_type='gym'        AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS gym_gross,
            COALESCE(SUM(CASE WHEN booking_type='event'      AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS events_gross,
            COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount ELSE 0 END), 0) AS total_vat,
                COALESCE(SUM(CASE WHEN payment_type='refund' AND refund_status IN ('completed','processing') THEN refund_amount ELSE 0 END), 0) AS refunds,
                COALESCE(SUM(CASE WHEN payment_type='refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END), 0) AS refund_vat,
            COALESCE(SUM(CASE WHEN payment_status IN ('pending','partial') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS pending,
            COUNT(*) AS txn_count
        FROM payments
        WHERE DATE(payment_date) = :d AND deleted_at IS NULL
    ");
    $payStmt->execute([':d' => $date]);
    $rev = $payStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $rev['total_vat'] = (float)($rev['total_vat'] ?? 0) - (float)($rev['refund_vat'] ?? 0);

    $gross = (float)$rev['room_gross'] + (float)$rev['conf_gross'] + (float)$rev['fnb_gross'] + (float)$rev['gym_gross'] + (float)$rev['events_gross'];
    $net   = $gross - (float)$rev['refunds'];
    $adr   = $rooms_occupied > 0 ? ((float)$rev['room_gross'] / $rooms_occupied) : 0;
    $revpar = $rooms_total    > 0 ? ((float)$rev['room_gross'] / $rooms_total)    : 0;

    // Payment mix
    $methods = [];
    try {
        $mStmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(payment_method,''),'unassigned') AS method,
                   COALESCE(SUM(total_amount),0) AS total
            FROM payments
            WHERE DATE(payment_date) = :d AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' AND deleted_at IS NULL
            GROUP BY method ORDER BY total DESC
        ");
        $mStmt->execute([':d' => $date]);
        $methods = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $methods = [];
    }

    $method_totals = ['cash' => 0.0, 'non_cash' => 0.0, 'unassigned' => 0.0];
    foreach ($methods as $methodRow) {
        $methodName = strtolower(trim((string)($methodRow['method'] ?? '')));
        $methodTotal = (float)($methodRow['total'] ?? 0);
        if ($methodName === '' || $methodName === 'unassigned') {
            $method_totals['unassigned'] += $methodTotal;
        } elseif (strpos($methodName, 'cash') !== false) {
            $method_totals['cash'] += $methodTotal;
        } else {
            $method_totals['non_cash'] += $methodTotal;
        }
    }

    // POS totals
    $pos = ['orders' => 0, 'gross' => 0, 'cogs' => 0, 'voided_value' => 0, 'voided_count' => 0];
    try {
        $tot = $pdo->prepare("
            SELECT COUNT(*) AS orders,
                   COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END),0) AS gross,
                   COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_cost   ELSE 0 END),0) AS cogs,
                   COALESCE(SUM(CASE WHEN status='voided' THEN total_amount ELSE 0 END),0) AS voided_value,
                   COALESCE(SUM(CASE WHEN status='voided' THEN 1 ELSE 0 END),0) AS voided_count
            FROM stock_orders WHERE created_at BETWEEN :a AND :b
        ");
        $tot->execute([':a' => $dayStart, ':b' => $dayEnd]);
        $pos = array_merge($pos, $tot->fetch(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        $pos = ['orders' => 0, 'gross' => 0, 'cogs' => 0, 'voided_value' => 0, 'voided_count' => 0];
    }

    // Top items
    $top = [];
    try {
        $itStmt = $pdo->prepare("
            SELECT soi.item_name, SUM(soi.quantity) AS qty, SUM(soi.line_total) AS revenue
            FROM stock_order_items soi
            INNER JOIN stock_orders o ON o.id = soi.order_id
            WHERE o.status IN ('paid','completed') AND o.created_at BETWEEN :a AND :b
            GROUP BY soi.item_name
            ORDER BY revenue DESC
            LIMIT 5
        ");
        $itStmt->execute([':a' => $dayStart, ':b' => $dayEnd]);
        $top = $itStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $top = [];
    }

    // Reviews
    $reviews = ['c' => 0, 'a' => 0];
    try {
        $rStmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(AVG(rating),0) AS a FROM reviews WHERE DATE(created_at) = :d");
        $rStmt->execute([':d' => $date]);
        $reviews = $rStmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'a' => 0];
    } catch (Throwable $e) {
        $reviews = ['c' => 0, 'a' => 0];
    }

    // Tomorrow
    $tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));
    $tp = $pdo->prepare("
        SELECT
            SUM(CASE WHEN check_in_date  = :t1 AND status IN ('confirmed','tentative','pending') THEN 1 ELSE 0 END) AS arrivals,
            SUM(CASE WHEN check_out_date = :t2 AND status IN ('checked-in','confirmed')           THEN 1 ELSE 0 END) AS departures,
            COALESCE(SUM(CASE WHEN check_in_date = :t3 AND status IN ('confirmed','tentative','pending') THEN total_amount ELSE 0 END),0) AS rev_forecast
        FROM bookings
    ");
    $tp->execute([':t1' => $tomorrow, ':t2' => $tomorrow, ':t3' => $tomorrow]);
    $tom = $tp->fetch(PDO::FETCH_ASSOC) ?: ['arrivals' => 0, 'departures' => 0, 'rev_forecast' => 0];

    $outstanding = 0.0;
    try {
        $outstanding = (float)$pdo->query("SELECT COALESCE(SUM(amount_due),0) FROM bookings WHERE amount_due > 0 AND status IN ('checked-in','confirmed','tentative')")->fetchColumn();
    } catch (Throwable $e) {
        $outstanding = 0.0;
    }

    $housekeeping = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
    try {
        $hkStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN status='completed' AND DATE(updated_at)=:d THEN 1 ELSE 0 END) AS completed
            FROM housekeeping_assignments
        ");
        $hkStmt->execute([':d' => $date]);
        $housekeeping = array_merge($housekeeping, $hkStmt->fetch(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        $housekeeping = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
    }

    $previous_day = date('Y-m-d', strtotime($date . ' -1 day'));
    $previous_day_start = $previous_day . ' 00:00:00';
    $previous_day_end = $previous_day . ' 23:59:59';
    $previous = ['net' => 0.0, 'pos_gross' => 0.0, 'rooms_occupied' => 0, 'occupancy_pct' => 0.0];
    $prevPay = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS gross,
            COALESCE(SUM(CASE WHEN payment_type='refund' AND refund_status IN ('completed','processing') THEN refund_amount ELSE 0 END), 0) AS refunds
        FROM payments
        WHERE DATE(payment_date) = :d
          AND deleted_at IS NULL
    ");
    $prevPay->execute([':d' => $previous_day]);
    $prevPayRow = $prevPay->fetch(PDO::FETCH_ASSOC) ?: [];
    $previous['net'] = (float)($prevPayRow['gross'] ?? 0) - (float)($prevPayRow['refunds'] ?? 0);

    try {
        $prevPos = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END), 0) FROM stock_orders WHERE created_at BETWEEN :a AND :b");
        $prevPos->execute([':a' => $previous_day_start, ':b' => $previous_day_end]);
        $previous['pos_gross'] = (float)$prevPos->fetchColumn();
    } catch (Throwable $e) {
        $previous['pos_gross'] = 0.0;
    }

    $prevOcc = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status IN ('checked-in','checked-out') AND check_in_date <= ? AND check_out_date > ?");
    $prevOcc->execute([$previous_day, $previous_day]);
    $previous['rooms_occupied'] = (int)$prevOcc->fetchColumn();
    $previous['occupancy_pct'] = $rooms_total > 0 ? ($previous['rooms_occupied'] / $rooms_total) * 100 : 0;

    $arrivals_remaining = max(0, (int)($ops['expected_arrivals'] ?? 0) - (int)($ops['arrivals_completed'] ?? 0));
    $departures_remaining = max(0, (int)($ops['expected_departures'] ?? 0) - (int)($ops['departures_completed'] ?? 0));
    $rooms_unsold = max(0, $rooms_total - $rooms_occupied);
    $empty_room_opportunity = $adr > 0 ? $rooms_unsold * $adr : 0;
    $payment_capture_rate = ($gross + (float)($rev['pending'] ?? 0)) > 0 ? ($gross / ($gross + (float)($rev['pending'] ?? 0))) * 100 : 100;
    $net_change = $net - $previous['net'];
    $pos_change = (float)$pos['gross'] - $previous['pos_gross'];
    $occupancy_change = $occupancy_pct - $previous['occupancy_pct'];

    $daily_health_score = 100;
    $daily_health_score -= min(18, $arrivals_remaining * 4);
    $daily_health_score -= min(16, $departures_remaining * 4);
    $daily_health_score -= min(16, (int)($ops['no_shows'] ?? 0) * 8);
    $daily_health_score -= min(12, (int)($ops['cancellations'] ?? 0) * 3);
    $daily_health_score -= min(12, (int)($pos['voided_count'] ?? 0) * 4);
    $daily_health_score -= (float)($rev['pending'] ?? 0) > 0 ? 8 : 0;
    $daily_health_score -= $outstanding > 0 ? 8 : 0;
    $daily_health_score -= ((int)$housekeeping['pending'] + (int)$housekeeping['in_progress']) > 0 ? 8 : 0;
    $daily_health_score -= $rooms_oo > 0 ? 5 : 0;
    $daily_health_score = max(0, min(100, $daily_health_score));
    $daily_health_label = $daily_health_score >= 90 ? 'Excellent close' : ($daily_health_score >= 75 ? 'Good close' : ($daily_health_score >= 55 ? 'Needs attention' : 'Critical review'));
} catch (Throwable $eFetch) {
    error_log('EOD send fetch: ' . $eFetch->getMessage());

    // Fallback to a safe zeroed dataset so sending can still proceed.
    $ops = $ops ?? [
        'expected_arrivals' => 0,
        'arrivals_completed' => 0,
        'expected_departures' => 0,
        'departures_completed' => 0,
        'stayovers' => 0,
        'new_bookings' => 0,
        'cancellations' => 0,
        'no_shows' => 0,
    ];
    $rooms_total = $rooms_total ?? 0;
    $rooms_oo = $rooms_oo ?? 0;
    $rooms_occupied = $rooms_occupied ?? 0;
    $occupancy_pct = $occupancy_pct ?? 0.0;

    $rev = $rev ?? [
        'room_gross' => 0,
        'conf_gross' => 0,
        'fnb_gross' => 0,
        'gym_gross' => 0,
        'events_gross' => 0,
        'total_vat' => 0,
        'refunds' => 0,
        'pending' => 0,
        'txn_count' => 0,
    ];
    $rev += ['gym_gross' => 0, 'events_gross' => 0];
    $gross = $gross ?? 0.0;
    $net = $net ?? 0.0;
    $adr = $adr ?? 0.0;
    $revpar = $revpar ?? 0.0;

    $methods = $methods ?? [];
    $method_totals = $method_totals ?? ['cash' => 0.0, 'non_cash' => 0.0, 'unassigned' => 0.0];
    $pos = $pos ?? ['orders' => 0, 'gross' => 0, 'cogs' => 0, 'voided_value' => 0, 'voided_count' => 0];
    $top = $top ?? [];
    $reviews = $reviews ?? ['c' => 0, 'a' => 0];
    $tom = $tom ?? ['arrivals' => 0, 'departures' => 0];
    $outstanding = $outstanding ?? 0.0;
    $housekeeping = $housekeeping ?? ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
    $previous = $previous ?? ['net' => 0.0, 'pos_gross' => 0.0, 'rooms_occupied' => 0, 'occupancy_pct' => 0.0];

    $arrivals_remaining = $arrivals_remaining ?? 0;
    $departures_remaining = $departures_remaining ?? 0;
    $rooms_unsold = $rooms_unsold ?? 0;
    $empty_room_opportunity = $empty_room_opportunity ?? 0.0;
    $payment_capture_rate = $payment_capture_rate ?? 100.0;
    $net_change = $net_change ?? 0.0;
    $pos_change = $pos_change ?? 0.0;
    $occupancy_change = $occupancy_change ?? 0.0;
    $daily_health_score = $daily_health_score ?? 0;
    $daily_health_label = $daily_health_label ?? 'Needs attention';
}

// ── Extra data for the PDF attachment ──────────────────────────────────────────
$pdf_room_type_perf = [];
try {
    $rtStmt2 = $pdo->prepare("
        SELECT rt.name AS room_type, COUNT(DISTINCT b.id) AS bookings, COALESCE(SUM(p.total_amount), 0) AS revenue
        FROM payments p INNER JOIN bookings b ON b.id = p.booking_id
        INNER JOIN rooms rt ON rt.id = b.room_id
        WHERE DATE(p.payment_date) = :d AND p.payment_status IN ('completed','paid')
          AND COALESCE(p.payment_type,'') <> 'refund' AND p.booking_type = 'room' AND p.deleted_at IS NULL
        GROUP BY rt.id, rt.name ORDER BY revenue DESC LIMIT 6
    ");
    $rtStmt2->execute([':d' => $date]);
    $pdf_room_type_perf = $rtStmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$pdf_guest_intel    = ['new_guests' => 0, 'returning_guests' => 0, 'avg_lead_days' => 0];
$pdf_returning_rate = 0.0;
try {
    $giStmt2 = $pdo->prepare("
        SELECT SUM(CASE WHEN bcount.total = 1 THEN 1 ELSE 0 END) AS new_guests,
               SUM(CASE WHEN bcount.total > 1 THEN 1 ELSE 0 END) AS returning_guests
        FROM bookings b
        INNER JOIN (SELECT guest_email, COUNT(*) AS total FROM bookings WHERE guest_email != '' AND status NOT IN ('cancelled','no-show','expired') GROUP BY guest_email) bcount ON bcount.guest_email = b.guest_email
        WHERE b.check_in_date = :d AND b.status NOT IN ('cancelled','no-show','expired') AND b.guest_email != ''
    ");
    $giStmt2->execute([':d' => $date]);
    $giRow2 = $giStmt2->fetch(PDO::FETCH_ASSOC) ?: [];
    $pdf_guest_intel['new_guests']       = (int)($giRow2['new_guests'] ?? 0);
    $pdf_guest_intel['returning_guests'] = (int)($giRow2['returning_guests'] ?? 0);
    $gitotal2 = $pdf_guest_intel['new_guests'] + $pdf_guest_intel['returning_guests'];
    $pdf_returning_rate = $gitotal2 > 0 ? ($pdf_guest_intel['returning_guests'] / $gitotal2) * 100 : 0.0;
} catch (Throwable $e) {}

$pdf_maintenance = ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'total_open' => 0];
try {
    $mStmt2 = $pdo->prepare("SELECT COALESCE(priority,'medium') AS priority, COUNT(*) AS cnt FROM room_maintenance_schedules WHERE status IN ('pending','in_progress') GROUP BY COALESCE(priority,'medium')");
    $mStmt2->execute();
    foreach ($mStmt2->fetchAll(PDO::FETCH_ASSOC) as $mr2) {
        $p2 = strtolower(trim((string)($mr2['priority'] ?? 'medium')));
        if (isset($pdf_maintenance[$p2])) $pdf_maintenance[$p2] = (int)$mr2['cnt'];
        $pdf_maintenance['total_open'] += (int)$mr2['cnt'];
    }
} catch (Throwable $e) {}

$pdf_quotation_stats = ['sent_today' => 0, 'accepted_today' => 0, 'total_active' => 0, 'pipeline_value' => 0.0];
try {
    $qStmt2 = $pdo->prepare("SELECT SUM(CASE WHEN DATE(sent_at)=:d THEN 1 ELSE 0 END) AS sent_today, SUM(CASE WHEN DATE(updated_at)=:d AND status='accepted' THEN 1 ELSE 0 END) AS accepted_today, SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) AS total_active, COALESCE(SUM(CASE WHEN status='sent' THEN total_amount ELSE 0 END),0) AS pipeline_value FROM quotations");
    $qStmt2->execute([':d' => $date]);
    $qRow2 = $qStmt2->fetch(PDO::FETCH_ASSOC) ?: [];
    $pdf_quotation_stats['sent_today']     = (int)($qRow2['sent_today'] ?? 0);
    $pdf_quotation_stats['accepted_today'] = (int)($qRow2['accepted_today'] ?? 0);
    $pdf_quotation_stats['total_active']   = (int)($qRow2['total_active'] ?? 0);
    $pdf_quotation_stats['pipeline_value'] = (float)($qRow2['pipeline_value'] ?? 0);
} catch (Throwable $e) {}

$pdf_closeout_alerts = [];
if ($arrivals_remaining > 0)
    $pdf_closeout_alerts[] = ['level' => 'warn', 'title' => 'Arrivals still open', 'detail' => $arrivals_remaining . ' expected arrival(s) not checked in.'];
if ($departures_remaining > 0)
    $pdf_closeout_alerts[] = ['level' => 'warn', 'title' => 'Departures still open', 'detail' => $departures_remaining . ' expected departure(s) not checked out.'];
if ((float)($rev['pending'] ?? 0) > 0)
    $pdf_closeout_alerts[] = ['level' => 'warn', 'title' => 'Pending payments', 'detail' => _money($currency_symbol, (float)$rev['pending']) . ' still pending.'];
if ($outstanding > 0)
    $pdf_closeout_alerts[] = ['level' => 'warn', 'title' => 'Outstanding folio', 'detail' => _money($currency_symbol, $outstanding) . ' unpaid across active stays.'];
if ((int)($pos['voided_count'] ?? 0) > 0)
    $pdf_closeout_alerts[] = ['level' => 'watch', 'title' => 'POS voids', 'detail' => (int)$pos['voided_count'] . ' void(s) worth ' . _money($currency_symbol, (float)($pos['voided_value'] ?? 0)) . '.'];
if (((int)($housekeeping['pending'] ?? 0) + (int)($housekeeping['in_progress'] ?? 0)) > 0)
    $pdf_closeout_alerts[] = ['level' => 'watch', 'title' => 'Housekeeping open', 'detail' => ((int)($housekeeping['pending'] ?? 0) + (int)($housekeeping['in_progress'] ?? 0)) . ' task(s) not completed.'];

$dateLabel = date('l, F j, Y', strtotime($date));

// Preset flags — rooms/occupancy/front-office content only for booking
// businesses; conference lines only when the conference module is on.
// Used by the WhatsApp text, HTML email, and attached PDF alike.
$eodModBookings   = !function_exists('moduleEnabled') || moduleEnabled('bookings');
$eodModConference = !function_exists('moduleEnabled') || moduleEnabled('conference');
$eodModGym        = !function_exists('moduleEnabled') || moduleEnabled('gym');
$eodModEvents     = function_exists('isEventsEnabled') ? isEventsEnabled() : true;
$eodOrdersToday   = (int)($pos['orders'] ?? 0);

// -----------------------------------------------------------------------------
// Channel: WhatsApp — concise plain-text summary
// -----------------------------------------------------------------------------
if ($channel === 'whatsapp') {
    $waNumber = getSetting('whatsapp_number') ?: getSetting('whatsapp_hotel_number') ?: getSetting('phone_main');
    if (!$waNumber) {
        echo json_encode(['success' => false, 'error' => 'No WhatsApp recipient number configured (whatsapp_number / whatsapp_hotel_number / phone_main).']);
        exit;
    }

    // ── Health badge ──────────────────────────────────────────────────────────
    $healthEmoji = $daily_health_score >= 90 ? '🟢' : ($daily_health_score >= 75 ? '🟡' : ($daily_health_score >= 55 ? '🟠' : '🔴'));
    $netArrow    = $net_change >= 0 ? '▲' : '▼';
    $occArrow    = $occupancy_change >= 0 ? '▲' : '▼';
    $posArrow    = $pos_change >= 0 ? '▲' : '▼';

    // ── Pending/exceptions flag ───────────────────────────────────────────────
    $alerts_wa = [];
    if ($arrivals_remaining > 0)     $alerts_wa[] = '⚠️ ' . $arrivals_remaining . ' arrival(s) not checked in';
    if ($departures_remaining > 0)   $alerts_wa[] = '⚠️ ' . $departures_remaining . ' departure(s) not checked out';
    if ((float)($rev['pending'] ?? 0) > 0) $alerts_wa[] = '💳 Pending payments: ' . _money($currency_symbol, (float)$rev['pending']);
    if ($outstanding > 0)            $alerts_wa[] = '📋 Outstanding folio: ' . _money($currency_symbol, $outstanding);
    if ((int)$pos['voided_count'] > 0) $alerts_wa[] = '🚫 ' . (int)$pos['voided_count'] . ' POS void(s) — review required';

    $lines = [];
    $lines[] = "┌──────────────────────────────────┐";
    $lines[] = "│  📊 END OF DAY REPORT              │";
    $lines[] = "│  " . $site_name;
    $lines[] = "│  " . $dateLabel;
    $lines[] = "└──────────────────────────────────┘";
    $lines[] = '';
    $lines[] = $healthEmoji . " *Closeout Health: " . $daily_health_score . "/100 — " . $daily_health_label . "*";
    $lines[] = '';
    $lines[] = "━━━━━━━━━━ 💰 REVENUE ━━━━━━━━━━";
    $lines[] = "Net Revenue:    *" . _money($currency_symbol, (float)$net) . "*";
    $lines[] = "  " . $netArrow . " vs yesterday: " . ($net_change >= 0 ? '+' : '') . _money($currency_symbol, $net_change);
    $lines[] = "Gross:          " . _money($currency_symbol, (float)$gross);
    if ((float)$rev['refunds'] > 0) {
        $lines[] = "Refunds:        -" . _money($currency_symbol, (float)$rev['refunds']);
    }
    $lines[] = "VAT Collected:  " . _money($currency_symbol, (float)$rev['total_vat']);
    $lines[] = '';
    if ($eodModBookings)   { $lines[] = "  🏨 Rooms:       " . _money($currency_symbol, (float)$rev['room_gross']); }
    if ($eodModConference) { $lines[] = "  🎪 Conference:  " . _money($currency_symbol, (float)$rev['conf_gross']); }
    $lines[] = (isRestaurantEnabled() ? "  🍽️ F&B:         " : "  🛒 POS:         ") . _money($currency_symbol, (float)$rev['fnb_gross']);
    if ($eodModGym    || (float)$rev['gym_gross'] > 0)    { $lines[] = "  🏋️ Gym:         " . _money($currency_symbol, (float)$rev['gym_gross']); }
    if ($eodModEvents || (float)$rev['events_gross'] > 0) { $lines[] = "  🎟️ Events:      " . _money($currency_symbol, (float)$rev['events_gross']); }
    $lines[] = '';
    if ($eodModBookings) {
    $lines[] = "━━━━━━━━━━ 🛏️ ROOMS ━━━━━━━━━━";
    $lines[] = "Occupancy: *" . number_format($occupancy_pct, 1) . "%* (" . $rooms_occupied . "/" . $rooms_total . " rooms)";
    $lines[] = "  " . $occArrow . " " . ($occupancy_change >= 0 ? '+' : '') . number_format($occupancy_change, 1) . " pts vs yesterday";
    $lines[] = "ADR:    " . _money($currency_symbol, (float)$adr) . "   RevPAR: " . _money($currency_symbol, (float)$revpar);
    $lines[] = "Unsold: " . $rooms_unsold . " rooms  (opp. " . _money($currency_symbol, (float)$empty_room_opportunity) . ")";
    $lines[] = '';
    $lines[] = "Arrivals:   " . (int)($ops['arrivals_completed'] ?? 0) . " done / " . (int)($ops['expected_arrivals'] ?? 0) . " expected";
    $lines[] = "Departures: " . (int)($ops['departures_completed'] ?? 0) . " done / " . (int)($ops['expected_departures'] ?? 0) . " expected";
    $lines[] = "Stay-overs: " . (int)($ops['stayovers'] ?? 0) . "   New bookings: " . (int)($ops['new_bookings'] ?? 0);
    if ((int)($ops['cancellations'] ?? 0) > 0 || (int)($ops['no_shows'] ?? 0) > 0) {
        $lines[] = "Cancellations: " . (int)($ops['cancellations'] ?? 0) . "   No-shows: " . (int)($ops['no_shows'] ?? 0);
    }
    $lines[] = '';
    } // end rooms block (bookings)
    $lines[] = isRestaurantEnabled() ? "━━━━━━━━━━ 🍽️ F&B / POS ━━━━━━━━━━" : "━━━━━━━━━━ 🛒 POS ━━━━━━━━━━";
    $lines[] = "Orders: " . (int)$pos['orders'] . "   Gross: *" . _money($currency_symbol, (float)$pos['gross']) . "*";
    $lines[] = "Margin: " . _money($currency_symbol, ((float)$pos['gross'] - (float)$pos['cogs'])) . " (" . number_format((float)$pos['gross'] > 0 ? (((float)$pos['gross'] - (float)$pos['cogs']) / (float)$pos['gross']) * 100 : 0, 1) . "%)";
    $lines[] = "  " . $posArrow . " vs yesterday: " . ($pos_change >= 0 ? '+' : '') . _money($currency_symbol, $pos_change);
    if ($top) {
        $topNames = array_map(fn(array $t): string => (string)$t['item_name'], array_slice($top, 0, 3));
        $lines[] = "🔥 Top: " . implode(' · ', $topNames);
    }
    $lines[] = '';
    $lines[] = "━━━━━━━━━━ 💵 CASHIER ━━━━━━━━━━";
    $lines[] = "Cash to reconcile: *" . _money($currency_symbol, (float)$method_totals['cash']) . "*";
    $lines[] = "Non-cash:          " . _money($currency_symbol, (float)$method_totals['non_cash']);
    $lines[] = "Capture rate:      " . number_format((float)$payment_capture_rate, 1) . "%";
    if ($outstanding > 0) {
        $lines[] = "⚠️ Outstanding folio: " . _money($currency_symbol, $outstanding);
    }
    $lines[] = '';

    if (!empty($alerts_wa)) {
        $lines[] = "━━━━━━━━━━ 🚨 ACTION ITEMS ━━━━━━━━━━";
        foreach ($alerts_wa as $aw) {
            $lines[] = $aw;
        }
        $lines[] = '';
    }

    if ((int)$reviews['c'] > 0) {
        $stars = str_repeat('★', (int)round((float)$reviews['a'])) . str_repeat('☆', 5 - (int)round((float)$reviews['a']));
        $lines[] = "⭐ Reviews today: " . (int)$reviews['c'] . " review(s)  " . $stars . " " . number_format((float)$reviews['a'], 1) . "/5";
        $lines[] = '';
    }

    if ($eodModBookings) {
        $lines[] = "━━━━━━━━━━ 📅 TOMORROW ━━━━━━━━━━";
        $lines[] = date('D, j M Y', strtotime($date . ' +1 day'));
        $lines[] = "Arrivals: " . (int)$tom['arrivals'] . "   Departures: " . (int)$tom['departures'];
        $lines[] = "Forecast: " . _money($currency_symbol, (float)$tom['rev_forecast']);
        $lines[] = '';
    }
    $lines[] = "— " . ($user['full_name'] ?: 'Admin') . " · " . date('H:i') . " · " . $site_name;

    $msg = implode("\n", $lines);
    $result = sendWhatsAppMessage($waNumber, $msg);

    if (function_exists('rh_log_event')) {
        rh_log_event('eod-report', $result['success'] ? 'info' : 'warning', 'EOD WhatsApp send', [
            'date' => $date,
            'to' => $waNumber,
            'success' => $result['success'],
        ]);
    }

    echo json_encode([
        'success' => (bool)$result['success'],
        'message' => $result['success'] ? "WhatsApp sent to {$waNumber}." : ($result['message'] ?? 'WhatsApp send failed'),
        'error'   => $result['success'] ? null : ($result['message'] ?? 'WhatsApp send failed'),
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// Channel: Email — rich HTML
// -----------------------------------------------------------------------------
$recipientsCsv = getSetting('eod_report_recipients');
if (!$recipientsCsv) {
    $recipientsCsv = getSetting('email_admin_email') ?: getSetting('email_from_email');
}
$recipients = [];
foreach (preg_split('/[,;\s]+/', (string)$recipientsCsv) as $em) {
    $em = trim($em);
    if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
        $recipients[] = ['email' => $em];
    }
}
if (!$recipients) {
    echo json_encode(['success' => false, 'error' => 'No admin recipient configured. Add an email to site setting "eod_report_recipients" or "email_admin_email".']);
    exit;
}

$row = function (string $label, string $value, bool $bold = false) {
    $w = $bold ? 'font-weight:700;' : '';
    return '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;color:#5e554d;">' . htmlspecialchars($label) . '</td>'
        . '<td style="padding:8px 12px;border-bottom:1px solid #eee;text-align:right;' . $w . '">' . $value . '</td></tr>';
};

// ── Score colour ──────────────────────────────────────────────────────────────
$score_hex   = $daily_health_score >= 90 ? '#166534' : ($daily_health_score >= 75 ? '#15803d' : ($daily_health_score >= 55 ? '#92400e' : '#b91c1c'));
$score_bg    = $daily_health_score >= 90 ? '#f0fdf4' : ($daily_health_score >= 75 ? '#f0fdf4' : ($daily_health_score >= 55 ? '#fffbeb' : '#fff1f2'));
$net_color   = $net_change >= 0 ? '#166534' : '#b91c1c';
$occ_color   = $occupancy_change >= 0 ? '#166534' : '#b91c1c';
$pos_color   = $pos_change >= 0 ? '#166534' : '#b91c1c';
$net_sign    = $net_change >= 0 ? '+' : '';
$occ_sign    = $occupancy_change >= 0 ? '+' : '';
$pos_sign    = $pos_change >= 0 ? '+' : '';

// ── Alerts for email ─────────────────────────────────────────────────────────
$email_alerts = [];
if ($arrivals_remaining > 0)     $email_alerts[] = ['warn', '⚠️ Arrivals still open', $arrivals_remaining . ' expected arrival(s) not checked in.'];
if ($departures_remaining > 0)   $email_alerts[] = ['warn', '⚠️ Departures still open', $departures_remaining . ' expected departure(s) not checked out.'];
if ((float)($rev['pending'] ?? 0) > 0) $email_alerts[] = ['warn', '💳 Pending payments', _money($currency_symbol, (float)$rev['pending']) . ' still pending.'];
if ($outstanding > 0)            $email_alerts[] = ['warn', '📋 Outstanding folio', _money($currency_symbol, $outstanding) . ' unpaid across active stays.'];
if ((int)$pos['voided_count'] > 0) $email_alerts[] = ['warn', '🚫 POS voids', (int)$pos['voided_count'] . ' void(s) worth ' . _money($currency_symbol, (float)$pos['voided_value']) . ' — review required.'];
if (((int)$housekeeping['pending'] + (int)$housekeeping['in_progress']) > 0) {
    $email_alerts[] = ['info', '🧹 Housekeeping open', ((int)$housekeeping['pending'] + (int)$housekeeping['in_progress']) . ' task(s) not completed.'];
}

// ── Helper closures ───────────────────────────────────────────────────────────
$section_head = function (string $label): string {
    return '<tr><td colspan="2" style="padding:10px 12px 4px;background:#F3ECE4;font-size:10px;letter-spacing:0.1em;color:#8A775F;font-weight:700;text-transform:uppercase;border-top:2px solid #e5d9c9;">' . $label . '</td></tr>';
};

// ════════════════════════════════════════════════════════════════
// EMAIL HTML
// ════════════════════════════════════════════════════════════════
$html  = '<div style="font-family:Arial,Helvetica,sans-serif;color:#2A2723;max-width:660px;margin:0 auto;background:#ffffff;">';

// ── TOP HEADER BAR ────────────────────────────────────────────────────────────
$html .= '<div style="background:#231F1C;padding:20px 24px 16px;border-radius:6px 6px 0 0;">';
$html .= '<div style="font-size:11px;letter-spacing:0.15em;color:#B18247;text-transform:uppercase;margin-bottom:4px;">End of Day Report</div>';
$html .= '<div style="font-size:20px;font-weight:700;color:#ffffff;margin-bottom:2px;">' . htmlspecialchars($site_name) . '</div>';
$html .= '<div style="font-size:13px;color:#a89683;">' . htmlspecialchars($dateLabel) . ' &nbsp;·&nbsp; Generated ' . date('H:i') . ' &nbsp;·&nbsp; By ' . htmlspecialchars($user['full_name'] ?: 'Admin') . '</div>';
$html .= '</div>';

// ── HEALTH SCORE BANNER ───────────────────────────────────────────────────────
$html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $score_bg . ';border-left:5px solid ' . $score_hex . ';">';
$html .= '<tr>';
$html .= '<td style="padding:16px 20px;vertical-align:middle;font-family:Arial,Helvetica,sans-serif;">';
$html .= '<div style="font-size:10px;letter-spacing:0.12em;color:' . $score_hex . ';text-transform:uppercase;font-weight:700;margin-bottom:4px;">Daily Closeout Health</div>';
$html .= '<div style="font-size:13px;color:' . $score_hex . ';">' . htmlspecialchars($daily_health_label) . '</div>';
$html .= '</td>';
$html .= '<td align="right" style="padding:16px 20px;vertical-align:middle;white-space:nowrap;font-family:Arial,Helvetica,sans-serif;">';
$html .= '<span style="font-size:44px;font-weight:700;color:' . $score_hex . ';line-height:1;">' . $daily_health_score . '</span>';
$html .= '<span style="font-size:15px;font-weight:400;color:' . $score_hex . ';"> / 100</span>';
$html .= '</td>';
$html .= '</tr>';
$html .= '</table>';

// ── 4-COLUMN KPI STRIP ────────────────────────────────────────────────────────
$html .= '<table style="width:100%;border-collapse:collapse;border-bottom:2px solid #e5d9c9;">';
$html .= '<tr>';

// Net Revenue
$html .= '<td style="padding:16px;text-align:center;background:#F3ECE4;border-right:1px solid #e5d9c9;">';
$html .= '<div style="font-size:9px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;margin-bottom:4px;">Net Revenue</div>';
$html .= '<div style="font-size:20px;font-weight:700;color:#231F1C;">' . _money($currency_symbol, $net) . '</div>';
$html .= '<div style="font-size:11px;color:' . $net_color . ';margin-top:3px;">' . $net_sign . _money($currency_symbol, $net_change) . ' vs yesterday</div>';
$html .= '</td>';

if ($eodModBookings) {
    // Occupancy
    $html .= '<td style="padding:16px;text-align:center;background:#F3ECE4;border-right:1px solid #e5d9c9;">';
    $html .= '<div style="font-size:9px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;margin-bottom:4px;">Occupancy</div>';
    $html .= '<div style="font-size:20px;font-weight:700;color:#231F1C;">' . number_format($occupancy_pct, 1) . '%</div>';
    $html .= '<div style="font-size:11px;color:#5e554d;margin-top:3px;">' . $rooms_occupied . '&nbsp;/&nbsp;' . $rooms_total . ' rooms &nbsp;<span style="color:' . $occ_color . ';">' . $occ_sign . number_format($occupancy_change, 1) . ' pts</span></div>';
    $html .= '</td>';

    // ADR / RevPAR
    $html .= '<td style="padding:16px;text-align:center;background:#F3ECE4;border-right:1px solid #e5d9c9;">';
    $html .= '<div style="font-size:9px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;margin-bottom:4px;">ADR</div>';
    $html .= '<div style="font-size:20px;font-weight:700;color:#231F1C;">' . _money($currency_symbol, $adr) . '</div>';
    $html .= '<div style="font-size:11px;color:#5e554d;margin-top:3px;">RevPAR&nbsp;' . _money($currency_symbol, $revpar) . '</div>';
    $html .= '</td>';
} else {
    // Till-first presets: orders + average order value instead of hotel KPIs
    $html .= '<td style="padding:16px;text-align:center;background:#F3ECE4;border-right:1px solid #e5d9c9;">';
    $html .= '<div style="font-size:9px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;margin-bottom:4px;">Orders Today</div>';
    $html .= '<div style="font-size:20px;font-weight:700;color:#231F1C;">' . $eodOrdersToday . '</div>';
    $html .= '<div style="font-size:11px;color:#5e554d;margin-top:3px;">' . (int)($pos['voided_count'] ?? 0) . ' void(s)</div>';
    $html .= '</td>';

    $html .= '<td style="padding:16px;text-align:center;background:#F3ECE4;border-right:1px solid #e5d9c9;">';
    $html .= '<div style="font-size:9px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;margin-bottom:4px;">Avg Order Value</div>';
    $html .= '<div style="font-size:20px;font-weight:700;color:#231F1C;">' . _money($currency_symbol, $eodOrdersToday > 0 ? ((float)($pos['gross'] ?? 0)) / $eodOrdersToday : 0) . '</div>';
    $html .= '<div style="font-size:11px;color:#5e554d;margin-top:3px;">per settled order</div>';
    $html .= '</td>';
}

// POS
$html .= '<td style="padding:16px;text-align:center;background:#F3ECE4;">';
$html .= '<div style="font-size:9px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;margin-bottom:4px;">' . (isRestaurantEnabled() ? 'F&amp;B / POS' : 'POS') . '</div>';
$html .= '<div style="font-size:20px;font-weight:700;color:#231F1C;">' . _money($currency_symbol, (float)$pos['gross']) . '</div>';
$html .= '<div style="font-size:11px;color:' . $pos_color . ';margin-top:3px;">' . $pos_sign . _money($currency_symbol, $pos_change) . ' vs yesterday</div>';
$html .= '</td>';

$html .= '</tr></table>';

// ── ALERTS BLOCK (only when exceptions exist) ─────────────────────────────────
if (!empty($email_alerts)) {
    $html .= '<div style="padding:14px 16px;background:#fff8f0;border-left:4px solid #B18247;">';
    $html .= '<div style="font-size:10px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;font-weight:700;margin-bottom:8px;">Action Items Before Close</div>';
    foreach ($email_alerts as $ea) {
        $abg   = $ea[0] === 'warn' ? '#fff1f2' : '#fffbeb';
        $abdr  = $ea[0] === 'warn' ? '#ef4444' : '#f59e0b';
        $html .= '<div style="margin-bottom:6px;padding:8px 10px;background:' . $abg . ';border-left:3px solid ' . $abdr . ';border-radius:3px;font-size:12px;">';
        $html .= '<strong>' . $ea[1] . '</strong> &mdash; ' . $ea[2];
        $html .= '</div>';
    }
    $html .= '</div>';
}

// ── MAIN DATA: 2-column layout ────────────────────────────────────────────────
$html .= '<table style="width:100%;border-collapse:collapse;">';

// REVENUE section
$html .= $section_head('Revenue by Source');
$revSrcRows = [];
if ($eodModBookings)   { $revSrcRows[] = ['Rooms',       _money($currency_symbol, (float)$rev['room_gross'])]; }
if ($eodModConference) { $revSrcRows[] = ['Conferences', _money($currency_symbol, (float)$rev['conf_gross'])]; }
$revSrcRows[] = [isRestaurantEnabled() ? 'F&amp;B / POS' : 'POS', _money($currency_symbol, (float)$rev['fnb_gross'])];
if ($eodModGym    || (float)$rev['gym_gross'] > 0)    { $revSrcRows[] = ['Gym',    _money($currency_symbol, (float)$rev['gym_gross'])]; }
if ($eodModEvents || (float)$rev['events_gross'] > 0) { $revSrcRows[] = ['Events', _money($currency_symbol, (float)$rev['events_gross'])]; }
foreach ($revSrcRows as $rr) {
    $html .= $row($rr[0], $rr[1]);
}
$html .= '<tr style="background:#F3ECE4;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #e5d9c9;">Gross Total</td><td style="padding:8px 12px;text-align:right;font-weight:700;border-bottom:1px solid #e5d9c9;">' . _money($currency_symbol, $gross) . '</td></tr>';
if ((float)$rev['refunds'] > 0) {
    $html .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;color:#b91c1c;">Less: Refunds</td><td style="padding:8px 12px;text-align:right;color:#b91c1c;border-bottom:1px solid #eee;">&minus;' . _money($currency_symbol, (float)$rev['refunds']) . '</td></tr>';
}
$html .= '<tr style="background:#231F1C;"><td style="padding:10px 12px;font-weight:700;color:#ffffff;font-size:14px;">NET REVENUE</td><td style="padding:10px 12px;text-align:right;font-weight:700;color:#B18247;font-size:14px;">' . _money($currency_symbol, $net) . '</td></tr>';
$html .= $row('VAT Collected', _money($currency_symbol, (float)$rev['total_vat']));
$html .= $row('Total Transactions', (string)(int)$rev['txn_count']);
$html .= $row('Pending / Partial', _money($currency_symbol, (float)($rev['pending'] ?? 0)));
$html .= $row('Outstanding Folio', _money($currency_symbol, (float)$outstanding));
$html .= $row('Payment Capture Rate', number_format((float)$payment_capture_rate, 1) . '%');

// FRONT OFFICE section (hotel businesses only)
if ($eodModBookings) {
    $html .= $section_head('Front Office Activity');
    $html .= $row('Arrivals (done / expected)', (int)($ops['arrivals_completed'] ?? 0) . ' of ' . (int)($ops['expected_arrivals'] ?? 0));
    $html .= $row('Departures (done / expected)', (int)($ops['departures_completed'] ?? 0) . ' of ' . (int)($ops['expected_departures'] ?? 0));
    $html .= $row('Stay-overs tonight', (string)(int)($ops['stayovers'] ?? 0));
    $html .= $row('New Bookings Created', (string)(int)($ops['new_bookings'] ?? 0));
    $html .= $row('Cancellations', (string)(int)($ops['cancellations'] ?? 0));
    $html .= $row('No-Shows', (string)(int)($ops['no_shows'] ?? 0));
    $html .= $row('Rooms Sold / Available', $rooms_occupied . ' / ' . $rooms_total);
    $html .= $row('Unsold Room Opportunity', _money($currency_symbol, (float)$empty_room_opportunity));
}

// POS section
$html .= $section_head(isRestaurantEnabled() ? 'POS / F&B' : 'POS');
$html .= $row('Total Orders', (string)(int)$pos['orders']);
$html .= $row('Gross Revenue', _money($currency_symbol, (float)$pos['gross']));
$html .= $row('Cost of Goods (COGS)', _money($currency_symbol, (float)$pos['cogs']));
$pos_margin_val = (float)$pos['gross'] - (float)$pos['cogs'];
$pos_marg_pct   = (float)$pos['gross'] > 0 ? ($pos_margin_val / (float)$pos['gross']) * 100 : 0;
$html .= $row('Gross Margin', _money($currency_symbol, $pos_margin_val) . ' (' . number_format($pos_marg_pct, 1) . '%)');
$html .= $row('POS vs Yesterday', ($pos_sign ?: '') . _money($currency_symbol, $pos_change));
$html .= $row('POS Voids', (int)$pos['voided_count'] . ' orders worth ' . _money($currency_symbol, (float)$pos['voided_value']));

// Payment mix
if (!empty($methods)) {
    $html .= $section_head('Payment Method Mix');
    foreach ($methods as $mRow) {
        $mLabel = htmlspecialchars(ucwords(str_replace('_', ' ', (string)$mRow['method'])));
        $mShare = $gross > 0 ? ((float)$mRow['total'] / $gross) * 100 : 0;
        $html  .= $row($mLabel . ' (' . (int)$mRow['cnt'] . ' txn)', _money($currency_symbol, (float)$mRow['total']) . ' &nbsp;<small style="color:#8A775F;">(' . number_format($mShare, 1) . '%)</small>');
    }
    $html .= '<tr style="background:#F3ECE4;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #e5d9c9;">Cash to Reconcile</td><td style="padding:8px 12px;text-align:right;font-weight:700;border-bottom:1px solid #e5d9c9;">' . _money($currency_symbol, (float)$method_totals['cash']) . '</td></tr>';
    $html .= '<tr style="background:#F3ECE4;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #e5d9c9;">Non-Cash Collected</td><td style="padding:8px 12px;text-align:right;font-weight:700;border-bottom:1px solid #e5d9c9;">' . _money($currency_symbol, (float)$method_totals['non_cash']) . '</td></tr>';
}

$html .= '</table>';

// ── TOP ITEMS ────────────────────────────────────────────────────────────────
if ($top) {
    $html .= '<div style="background:#F7F3EE;border-top:1px solid #e5d9c9;padding:16px 20px;">';
    $html .= '<div style="font-size:10px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;font-weight:700;margin-bottom:10px;">🔥 Top Selling Items</div>';
    $html .= '<table style="width:100%;border-collapse:collapse;">';
    $html .= '<tr style="background:#F3ECE4;"><th style="padding:6px 10px;text-align:left;font-size:11px;color:#5e554d;font-weight:600;">Item</th><th style="padding:6px 10px;text-align:right;font-size:11px;color:#5e554d;font-weight:600;">Qty</th><th style="padding:6px 10px;text-align:right;font-size:11px;color:#5e554d;font-weight:600;">Revenue</th></tr>';
    foreach ($top as $i => $t) {
        $bg  = $i % 2 === 0 ? '#ffffff' : '#F7F3EE';
        $qty = rtrim(rtrim(number_format((float)$t['qty'], 2, '.', ''), '0'), '.');
        $html .= '<tr style="background:' . $bg . ';">';
        $html .= '<td style="padding:6px 10px;font-size:12px;border-bottom:1px solid #eee;">' . htmlspecialchars((string)$t['item_name']) . '</td>';
        $html .= '<td style="padding:6px 10px;text-align:right;font-size:12px;border-bottom:1px solid #eee;">' . $qty . '</td>';
        $html .= '<td style="padding:6px 10px;text-align:right;font-size:12px;font-weight:600;border-bottom:1px solid #eee;">' . _money($currency_symbol, (float)$t['revenue']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table></div>';
}

// ── HOUSEKEEPING + REVIEWS ───────────────────────────────────────────────────
$html .= '<table style="width:100%;border-collapse:collapse;">';
$html .= $section_head('Housekeeping');
$html .= $row('Pending Tasks', (string)(int)$housekeeping['pending']);
$html .= $row('In Progress', (string)(int)$housekeeping['in_progress']);
$html .= $row('Completed Today', (string)(int)$housekeeping['completed']);
if ((int)$reviews['c'] > 0) {
    $html .= $section_head('Guest Reviews');
    $html .= $row('Reviews Received', (int)$reviews['c'] . ' review(s)');
    $html .= $row('Average Rating', number_format((float)$reviews['a'], 1) . ' / 5');
}
$html .= '</table>';

// ── TOMORROW PREVIEW ─────────────────────────────────────────────────────────
if ($eodModBookings) {
    $html .= '<div style="background:#231F1C;padding:16px 20px;margin-top:4px;">';
    $html .= '<div style="font-size:10px;letter-spacing:0.1em;color:#B18247;text-transform:uppercase;font-weight:700;margin-bottom:8px;">Tomorrow Preview — ' . htmlspecialchars(date('D, j M Y', strtotime($date . ' +1 day'))) . '</div>';
    $html .= '<table style="width:100%;">';
    $html .= '<tr>';
    $html .= '<td style="text-align:center;padding:10px;"><div style="font-size:9px;color:#a89683;text-transform:uppercase;letter-spacing:0.1em;">Arrivals</div><div style="font-size:22px;font-weight:700;color:#ffffff;">' . (int)$tom['arrivals'] . '</div></td>';
    $html .= '<td style="text-align:center;padding:10px;border-left:1px solid #3d3733;"><div style="font-size:9px;color:#a89683;text-transform:uppercase;letter-spacing:0.1em;">Departures</div><div style="font-size:22px;font-weight:700;color:#ffffff;">' . (int)$tom['departures'] . '</div></td>';
    $html .= '<td style="text-align:center;padding:10px;border-left:1px solid #3d3733;"><div style="font-size:9px;color:#a89683;text-transform:uppercase;letter-spacing:0.1em;">Revenue Forecast</div><div style="font-size:22px;font-weight:700;color:#B18247;">' . _money($currency_symbol, (float)$tom['rev_forecast']) . '</div></td>';
    $html .= '</tr>';
    $html .= '</table></div>';
}

// ── FOOTER ───────────────────────────────────────────────────────────────────
$html .= '<div style="padding:12px 20px;background:#F7F3EE;border-top:1px solid #e5d9c9;border-radius:0 0 6px 6px;">';
$html .= '<p style="font-size:11px;color:#8A775F;margin:0;">Sent by ' . htmlspecialchars($user['full_name'] ?: 'Admin') . ' &nbsp;·&nbsp; ' . date('Y-m-d H:i') . ' &nbsp;·&nbsp; All amounts in ' . htmlspecialchars(trim($currency_symbol)) . ' &nbsp;·&nbsp; ' . htmlspecialchars($site_name) . '</p>';
$html .= '</div>';

$html .= '</div>';

$subject = 'EOD Report — ' . $site_name . ' — ' . $dateLabel;

// ─── PDF attachment ──────────────────────────────────────────────────────────
$tomorrow    = $tomorrow ?? date('Y-m-d', strtotime($date . ' +1 day'));
$attachments = [];
try {
    require_once __DIR__ . '/../../includes/eod-pdf-builder.php';

    $pdf_d = [
        'date'                   => $date,
        'ops'                    => $ops,
        'rev'                    => $rev,
        'gross'                  => $gross,
        'net'                    => $net,
        'adr'                    => $adr,
        'revpar'                 => $revpar,
        'methods'                => $methods,
        'cash_total'             => (float)($method_totals['cash'] ?? 0),
        'pos'                    => $pos,
        'pos_by_type'            => [],
        'top_items'              => $top,
        'void_reasons'           => [],
        'hk'                     => $housekeeping,
        'reviewRow'              => ['cnt' => (int)($reviews['c'] ?? 0), 'avg_rating' => (float)($reviews['a'] ?? 0)],
        'outstanding'            => $outstanding,
        'rooms_total'            => $rooms_total,
        'rooms_occupied'         => $rooms_occupied,
        'rooms_oo'               => $rooms_oo,
        'occupancy_pct'          => $occupancy_pct,
        'tom'                    => $tom,
        'score'                  => $daily_health_score,
        'score_label'            => $daily_health_label,
        'net_change'             => $net_change,
        'pos_change'             => $pos_change,
        'occ_change'             => $occupancy_change,
        'arrivals_remaining'     => $arrivals_remaining,
        'departures_remaining'   => $departures_remaining,
        'rooms_unsold'           => $rooms_unsold,
        'empty_room_opportunity' => $empty_room_opportunity,
        'payment_capture_rate'   => $payment_capture_rate,
        'room_type_perf'         => $pdf_room_type_perf,
        'guest_intel'            => $pdf_guest_intel,
        'returning_rate'         => $pdf_returning_rate,
        'closeout_alerts'        => $pdf_closeout_alerts,
        'maintenance'            => $pdf_maintenance,
        'quotation_stats'        => $pdf_quotation_stats,
    ];
    $attachments[] = [
        'filename' => 'eod-report-' . $date . '.pdf',
        'content'  => buildEodPdf($pdf_d, $site_name, $currency_symbol, $user['full_name'] ?? 'Admin'),
        'mime'     => 'application/pdf',
    ];
} catch (Throwable $e) {
    error_log('EOD email PDF attachment: ' . $e->getMessage());
}

// ─── (Legacy inline PDF block removed — replaced by eod-pdf-builder.php) ────
if (false) {
    require_once __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';

    $pC  = [243, 236, 228];
    $pK  = [35, 31, 28];
    $pBr = [138, 119, 95];
    $pWh = [255, 255, 255];
    $pLg = [247, 243, 238];
    $pT2 = [94, 85, 77];
    $pDv = [229, 217, 201];
    $pSc = $daily_health_score >= 90 ? [22, 101, 52] : ($daily_health_score >= 75 ? [21, 128, 61] : ($daily_health_score >= 55 ? [146, 64, 14] : [185, 28, 28]));
    $pSb = $daily_health_score >= 90 ? [240, 253, 244] : ($daily_health_score >= 75 ? [240, 253, 244] : ($daily_health_score >= 55 ? [255, 251, 235] : [255, 241, 242]));

    if (!class_exists('JapandiTCPDF')) {
        class JapandiTCPDF extends TCPDF {
            public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false): void
            {
                parent::AddPage($orientation, $format, $keepmargins, $tocpage);
                $this->SetFillColor(247, 243, 238);
                $this->Rect(0, 0, $this->getPageWidth(), $this->getPageHeight(), 'F');
            }
        }
    }
    $pa = new JapandiTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pa->SetCreator($site_name);
    $pa->SetAuthor($site_name);
    $pa->SetTitle('End of Day Report — ' . $dateLabel);
    $pa->SetAutoPageBreak(true, 15);
    $pa->setPrintHeader(false);
    $pa->setPrintFooter(false);
    $pa->SetMargins(14, 14, 14);
    $pa->AddPage();
    $py = 14.0;

    // Header band
    $pa->SetFillColorArray($pK);
    $pa->Rect(14, $py, 182, 20, 'F');
    $pa->SetTextColorArray($pWh);
    $pa->SetFont('helvetica', 'B', 14);
    $pa->SetXY(18, $py + 4);
    $pa->Cell(130, 7, $site_name . '  -  End of Day Report', 0, 0, 'L');
    $pa->SetFont('helvetica', '', 8);
    $pa->SetXY(18, $py + 11);
    $pa->Cell(170, 5, $dateLabel . '   |   Generated ' . date('H:i') . '   |   By ' . ($user['full_name'] ?? 'Admin'), 0, 0, 'L');
    $py += 24.0;

    // Health score band
    $pa->SetFillColorArray($pSb);
    $pa->Rect(14, $py, 182, 12, 'F');
    $pa->SetFillColorArray($pSc);
    $pa->Rect(14, $py, 5, 12, 'F');
    $pa->SetTextColorArray($pSc);
    $pa->SetFont('helvetica', 'B', 9);
    $pa->SetXY(22, $py + 2.5);
    $pa->Cell(130, 7, 'Closeout Health:  ' . $daily_health_score . '/100  -  ' . $daily_health_label, 0, 0, 'L');
    $py += 16.0;

    // KPI strip
    $kpiW = 44.0;
    $kpiH = 18.0;
    if ($eodModBookings) {
        $kpiSlot2 = ['OCCUPANCY', number_format($occupancy_pct, 1) . '%', $rooms_occupied . '/' . $rooms_total . ' rooms'];
        $kpiSlot3 = ['ADR',       _money($currency_symbol, $adr),         'RevPAR ' . _money($currency_symbol, $revpar)];
    } else {
        $kpiSlot2 = ['ORDERS TODAY',    (string)$eodOrdersToday, (int)($pos['voided_count'] ?? 0) . ' void(s)'];
        $kpiSlot3 = ['AVG ORDER VALUE', _money($currency_symbol, $eodOrdersToday > 0 ? ((float)($pos['gross'] ?? 0)) / $eodOrdersToday : 0), 'per settled order'];
    }
    $kpiItems = [
        ['NET REVENUE', _money($currency_symbol, $net),                 ($net_change >= 0 ? '+' : '') . _money($currency_symbol, $net_change) . ' vs yday'],
        $kpiSlot2,
        $kpiSlot3,
        [isRestaurantEnabled() ? 'F&B / POS' : 'POS',   _money($currency_symbol, (float)$pos['gross']), ($pos_change >= 0 ? '+' : '') . _money($currency_symbol, $pos_change) . ' vs yday'],
    ];
    $kpiXp = 14.0;
    foreach ($kpiItems as $ki) {
        $pa->SetFillColorArray($pC);
        $pa->Rect($kpiXp, $py, $kpiW, $kpiH, 'F');
        $pa->SetDrawColorArray($pDv);
        $pa->Rect($kpiXp, $py, $kpiW, $kpiH, 'D');
        $pa->SetTextColorArray($pBr);
        $pa->SetFont('helvetica', 'B', 6);
        $pa->SetXY($kpiXp + 2, $py + 2);
        $pa->Cell($kpiW - 4, 4, $ki[0], 0, 0, 'L');
        $pa->SetTextColorArray($pK);
        $pa->SetFont('helvetica', 'B', 11);
        $pa->SetXY($kpiXp + 2, $py + 6);
        $pa->Cell($kpiW - 4, 7, $ki[1], 0, 0, 'L');
        $pa->SetTextColorArray($pT2);
        $pa->SetFont('helvetica', '', 6.5);
        $pa->SetXY($kpiXp + 2, $py + 13);
        $pa->Cell($kpiW - 4, 4, $ki[2], 0, 0, 'L');
        $kpiXp += $kpiW + 2;
    }
    $py += $kpiH + 6.0;

    $pdfSec = function (string $lbl) use ($pa, $pBr, $pWh, &$py): void {
        $pa->SetFillColorArray($pBr);
        $pa->SetTextColorArray($pWh);
        $pa->SetFont('helvetica', 'B', 8);
        $pa->SetXY(14, $py);
        $pa->Cell(182, 7, '  ' . strtoupper($lbl), 0, 0, 'L', true);
        $py += 9.0;
    };
    $pdfRowA = function (string $l, string $v, bool $b = false, int $i = 0) use ($pa, $pWh, $pLg, $pT2, $pK, $pDv, &$py): void {
        $bg = $i % 2 === 0 ? $pWh : $pLg;
        $pa->SetFillColorArray($bg);
        $pa->SetDrawColorArray($pDv);
        $pa->SetFont('helvetica', $b ? 'B' : '', 8);
        $pa->SetTextColorArray($pT2);
        $pa->SetXY(14, $py);
        $pa->Cell(110, 6, '  ' . $l, 'B', 0, 'L', true);
        $pa->SetTextColorArray($pK);
        $pa->Cell(72, 6, $v, 'B', 0, 'R', true);
        $py += 6.0;
    };

    $pdfSec('Revenue by Source');
    $pdfRowIdx = 0;
    if ($eodModBookings)   { $pdfRowA('Rooms',       _money($currency_symbol, (float)$rev['room_gross']), false, $pdfRowIdx++); }
    if ($eodModConference) { $pdfRowA('Conferences', _money($currency_symbol, (float)$rev['conf_gross']), false, $pdfRowIdx++); }
    $pdfRowA(isRestaurantEnabled() ? 'F&B / POS' : 'POS', _money($currency_symbol, (float)$rev['fnb_gross']),  false, $pdfRowIdx++);
    if ($eodModGym    || (float)$rev['gym_gross'] > 0)    { $pdfRowA('Gym',    _money($currency_symbol, (float)$rev['gym_gross']),    false, $pdfRowIdx++); }
    if ($eodModEvents || (float)$rev['events_gross'] > 0) { $pdfRowA('Events', _money($currency_symbol, (float)$rev['events_gross']), false, $pdfRowIdx++); }
    $pdfRowA('Gross Total',      _money($currency_symbol, $gross), true, $pdfRowIdx++);
    if ((float)$rev['refunds'] > 0) {
        $pdfRowA('Less Refunds', '-' . _money($currency_symbol, (float)$rev['refunds']), false, $pdfRowIdx++);
    }
    $pdfRowA('Net Revenue',       _money($currency_symbol, $net), true, $pdfRowIdx++);
    $pdfRowA('VAT Collected',     _money($currency_symbol, (float)$rev['total_vat']), false, $pdfRowIdx++);
    $pdfRowA('Pending',           _money($currency_symbol, (float)$rev['pending']), false, $pdfRowIdx++);
    $pdfRowA('Outstanding Folio', _money($currency_symbol, $outstanding), false, $pdfRowIdx++);
    $py += 3.0;

    if ($eodModBookings) {
        $pdfSec('Front Office');
        $pdfRowA('Arrivals (done / exp)',   (int)($ops['arrivals_completed']  ?? 0) . ' of ' . (int)($ops['expected_arrivals']  ?? 0), false, 0);
        $pdfRowA('Departures (done / exp)', (int)($ops['departures_completed'] ?? 0) . ' of ' . (int)($ops['expected_departures'] ?? 0), false, 1);
        $pdfRowA('Stay-overs tonight',      (string)(int)($ops['stayovers']   ?? 0), false, 2);
        $pdfRowA('New Bookings',            (string)(int)($ops['new_bookings'] ?? 0), false, 3);
        $pdfRowA('Cancellations / No-Shows', (int)($ops['cancellations'] ?? 0) . ' / ' . (int)($ops['no_shows'] ?? 0), false, 4);
        $py += 3.0;
    }

    if ($py > 230) {
        $pa->AddPage();
        $py = 14.0;
    }
    $pdfSec(isRestaurantEnabled() ? 'POS / F&B' : 'POS');
    $pdfRowA('Total Orders',  (string)(int)$pos['orders'], false, 0);
    $pdfRowA('Gross Revenue', _money($currency_symbol, (float)$pos['gross']), false, 1);
    $pdfRowA('Gross Margin',  _money($currency_symbol, $pos_margin_val) . ' (' . number_format($pos_marg_pct, 1) . '%)', false, 2);
    $pdfRowA('Voids',         (int)$pos['voided_count'] . ' orders  -  ' . _money($currency_symbol, (float)$pos['voided_value']), false, 3);
    $py += 3.0;

    if (!empty($methods)) {
        if ($py > 230) {
            $pa->AddPage();
            $py = 14.0;
        }
        $pdfSec('Payment Method Mix');
        foreach ($methods as $pmi => $pmRow) {
            $pmLabel = ucwords(str_replace('_', ' ', (string)$pmRow['method']));
            $pdfRowA($pmLabel, _money($currency_symbol, (float)$pmRow['total']), false, $pmi);
        }
        $pdfRowA('Cash to Reconcile', _money($currency_symbol, (float)$method_totals['cash']), true, count($methods));
        $py += 3.0;
    }

    if (!empty($top)) {
        if ($py > 230) {
            $pa->AddPage();
            $py = 14.0;
        }
        $pdfSec('Top Selling Items');
        foreach ($top as $pti => $ptRow) {
            $ptQty = rtrim(rtrim(number_format((float)$ptRow['qty'], 2, '.', ''), '0'), '.');
            $pdfRowA((string)$ptRow['item_name'] . '  (qty: ' . $ptQty . ')', _money($currency_symbol, (float)$ptRow['revenue']), false, $pti);
        }
        $py += 3.0;
    }

    if ($py > 230) {
        $pa->AddPage();
        $py = 14.0;
    }
    $pdfSec('Housekeeping & Reviews');
    $pdfRowA('HK Pending',     (string)(int)$housekeeping['pending'], false, 0);
    $pdfRowA('HK In Progress', (string)(int)$housekeeping['in_progress'], false, 1);
    $pdfRowA('HK Completed',   (string)(int)$housekeeping['completed'], false, 2);
    if ((int)$reviews['c'] > 0) {
        $pdfRowA('Reviews Today', (int)$reviews['c'] . ' - avg ' . number_format((float)$reviews['a'], 1) . '/5', false, 3);
    }
    $py += 3.0;

    if ($py > 240) {
        $pa->AddPage();
        $py = 14.0;
    }
    if ($eodModBookings) {
        $pdfSec('Tomorrow Preview - ' . date('D, M j', strtotime($tomorrow)));
        $pdfRowA('Expected Arrivals',   (string)(int)$tom['arrivals'], false, 0);
        $pdfRowA('Expected Departures', (string)(int)$tom['departures'], false, 1);
        $pdfRowA('Revenue Forecast',    _money($currency_symbol, (float)($tom['rev_forecast'] ?? 0)), true, 2);
    }

    $pa->SetY(-10);
    $pa->SetFont('helvetica', 'I', 7);
    $pa->SetTextColorArray($pT2);
    $pa->Cell(0, 4, $site_name . '  |  EOD Report  |  ' . $date . '  |  Generated ' . date('Y-m-d H:i') . '  |  All amounts in ' . trim($currency_symbol), 0, 0, 'C');

} // end if (false) — legacy inline PDF block

$result = sendReportEmail($recipients, $subject, $html, $attachments, '', $cc_emails);

if (function_exists('rh_log_event')) {
    rh_log_event('eod-report', $result['success'] ? 'info' : 'warning', 'EOD email send', [
        'date' => $date,
        'recipients' => array_column($recipients, 'email'),
        'cc' => $cc_emails,
        'success' => $result['success'],
    ]);
}

$recipientList = implode(', ', array_column($recipients, 'email'));
$ccNote = !empty($cc_emails) ? ' — CC: ' . implode(', ', $cc_emails) : '';
echo json_encode([
    'success' => (bool)$result['success'],
    'message' => $result['success']
        ? 'Sent to ' . count($recipients) . ' recipient(s): ' . $recipientList . $ccNote
        : ($result['message'] ?? 'Email send failed'),
    'error'   => $result['success'] ? null : ($result['message'] ?? 'Email send failed'),
]);

