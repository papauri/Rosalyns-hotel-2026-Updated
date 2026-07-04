<?php

/**
 * End of Day Report — PDF generator.
 *
 * GET: end-of-day-pdf.php?date=YYYY-MM-DD&csrf=TOKEN
 * Outputs a binary PDF with Content-Disposition: attachment.
 */

declare(strict_types=1);

require_once __DIR__ . '/api-init.php';

/** @var PDO $pdo */
/** @var array $user */

requireApiPermission('reports');

$site_name       = getSetting('site_name') ?: "Rosalyn's Beach Hotel";
$currency_symbol = getSetting('currency_symbol') ?: 'K ';

// ---- Auth & CSRF -----------------------------------------------------------
if (empty($_SESSION['admin_user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$csrf = (string)($_GET['csrf'] ?? '');
if (!function_exists('validateCsrfToken') || !validateCsrfToken($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token. Please reload the page.']);
    exit;
}

// ---- Date ------------------------------------------------------------------
$date = (string)($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
    $date = date('Y-m-d');
}
$dayStart  = $date . ' 00:00:00';
$dayEnd    = $date . ' 23:59:59';
$tomorrow  = date('Y-m-d', strtotime($date . ' +1 day'));
$dateLabel = date('l, F j, Y', strtotime($date));

// ---- Money helper ----------------------------------------------------------
function pdfMoney(string $sym, float $v): string
{
    return $sym . number_format($v, 2);
}

function pdfSign(float $v): string
{
    return $v >= 0 ? '+' : '-';
}

// ---- Data queries ----------------------------------------------------------
try {
    // 1) Ops
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

    // 2) Occupancy
    $rooms_total    = (int)$pdo->query("SELECT COUNT(*) FROM individual_rooms WHERE status <> 'out_of_order'")->fetchColumn();
    $rooms_oo       = (int)$pdo->query("SELECT COUNT(*) FROM individual_rooms WHERE status = 'out_of_order'")->fetchColumn();
    $occStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status IN ('checked-in','checked-out') AND check_in_date <= ? AND check_out_date > ?");
    $occStmt->execute([$date, $date]);
    $rooms_occupied = (int)$occStmt->fetchColumn();
    $occupancy_pct  = $rooms_total > 0 ? ($rooms_occupied / $rooms_total) * 100 : 0;

    // 3) Revenue
    $payStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN booking_type='room'       AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END),0) AS room_gross,
            COALESCE(SUM(CASE WHEN booking_type='conference' AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END),0) AS conf_gross,
            COALESCE(SUM(CASE WHEN booking_type='restaurant' AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END),0) AS fnb_gross,
            COALESCE(SUM(CASE WHEN booking_type='gym'        AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END),0) AS gym_gross,
            COALESCE(SUM(CASE WHEN booking_type='event'      AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END),0) AS events_gross,
            COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN vat_amount ELSE 0 END),0) AS total_vat,
            COALESCE(SUM(CASE WHEN booking_type='room'       AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN vat_amount ELSE 0 END),0) AS room_vat,
            COALESCE(SUM(CASE WHEN booking_type='conference' AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN vat_amount ELSE 0 END),0) AS conf_vat,
            COALESCE(SUM(CASE WHEN booking_type='restaurant' AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN vat_amount ELSE 0 END),0) AS fnb_vat,
            COALESCE(SUM(CASE WHEN payment_type='refund' AND refund_status IN ('completed','processing') THEN refund_amount ELSE 0 END),0) AS refunds,
            COALESCE(SUM(CASE WHEN payment_type='refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END),0) AS refund_vat,
            COALESCE(SUM(CASE WHEN payment_status IN ('pending','partial') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END),0) AS pending,
            COUNT(*) AS txn_count
        FROM payments
        WHERE DATE(payment_date) = :d AND deleted_at IS NULL
    ");
    $payStmt->execute([':d' => $date]);
    $rev = $payStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $rev['total_vat'] = (float)($rev['total_vat'] ?? 0) - (float)($rev['refund_vat'] ?? 0);

    $gross  = (float)$rev['room_gross'] + (float)$rev['conf_gross'] + (float)$rev['fnb_gross'] + (float)$rev['gym_gross'] + (float)$rev['events_gross'];
    $net    = $gross - (float)$rev['refunds'];
    $adr    = $rooms_occupied > 0 ? ((float)$rev['room_gross'] / $rooms_occupied) : 0;
    $revpar = $rooms_total    > 0 ? ((float)$rev['room_gross'] / $rooms_total)    : 0;

    // 4) Payment mix
    $mStmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(payment_method,''),'Unassigned') AS method,
               COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total
        FROM payments
        WHERE DATE(payment_date) = :d
          AND payment_status IN ('completed','paid')
          AND COALESCE(payment_type,'') <> 'refund'
          AND deleted_at IS NULL
        GROUP BY method ORDER BY total DESC
    ");
    $mStmt->execute([':d' => $date]);
    $methods     = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    $cash_total  = 0.0;
    $ncash_total = 0.0;
    foreach ($methods as $mRow) {
        $mn = strtolower(trim((string)($mRow['method'] ?? '')));
        if (strpos($mn, 'cash') !== false) {
            $cash_total += (float)$mRow['total'];
        } else {
            $ncash_total += (float)$mRow['total'];
        }
    }

    // 5) POS
    $totStmt = $pdo->prepare("
        SELECT COUNT(*) AS orders,
               COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END),0) AS gross,
               COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_cost   ELSE 0 END),0) AS cogs,
               COALESCE(SUM(CASE WHEN status='voided' THEN total_amount ELSE 0 END),0) AS voided_value,
               COALESCE(SUM(CASE WHEN status='voided' THEN 1 ELSE 0 END),0) AS voided_count
        FROM stock_orders WHERE created_at BETWEEN :a AND :b
    ");
    $totStmt->execute([':a' => $dayStart, ':b' => $dayEnd]);
    $pos = array_merge(['orders' => 0, 'gross' => 0, 'cogs' => 0, 'voided_value' => 0, 'voided_count' => 0], $totStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $pos_margin     = (float)$pos['gross'] - (float)$pos['cogs'];
    $pos_margin_pct = (float)$pos['gross'] > 0 ? ($pos_margin / (float)$pos['gross']) * 100 : 0;
    $avg_order      = (int)$pos['orders'] > 0 ? (float)$pos['gross'] / (int)$pos['orders'] : 0;

    // 6) POS by type
    $tStmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(order_type,''),'walk_in') AS order_type,
               COUNT(*) AS cnt,
               COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END),0) AS gross
        FROM stock_orders WHERE created_at BETWEEN :a AND :b GROUP BY order_type ORDER BY gross DESC
    ");
    $tStmt->execute([':a' => $dayStart, ':b' => $dayEnd]);
    $pos_by_type = $tStmt->fetchAll(PDO::FETCH_ASSOC);

    // 7) Top items
    $itStmt = $pdo->prepare("
        SELECT soi.item_name, SUM(soi.quantity) AS qty, SUM(soi.line_total) AS revenue
        FROM stock_order_items soi
        INNER JOIN stock_orders o ON o.id = soi.order_id
        WHERE o.status IN ('paid','completed') AND o.created_at BETWEEN :a AND :b
        GROUP BY soi.item_name ORDER BY revenue DESC LIMIT 8
    ");
    $itStmt->execute([':a' => $dayStart, ':b' => $dayEnd]);
    $top_items = $itStmt->fetchAll(PDO::FETCH_ASSOC);

    // 8) Reviews
    $rStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(AVG(rating),0) AS avg_rating FROM reviews WHERE DATE(created_at) = :d");
    $rStmt->execute([':d' => $date]);
    $reviewRow = $rStmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'avg_rating' => 0];

    // 9) Housekeeping
    $hkStmt = $pdo->prepare("
        SELECT SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,
               SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS in_progress,
               SUM(CASE WHEN status='completed' AND DATE(updated_at)=:d THEN 1 ELSE 0 END) AS completed
        FROM housekeeping_assignments
    ");
    $hkStmt->execute([':d' => $date]);
    $hk = array_merge(['pending' => 0, 'in_progress' => 0, 'completed' => 0], $hkStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    // 10) Outstanding folio
    $outstanding = (float)$pdo->query("SELECT COALESCE(SUM(amount_due),0) FROM bookings WHERE amount_due > 0 AND status IN ('checked-in','confirmed','tentative')")->fetchColumn();

    // 11) Previous day comparison
    $prevDay = date('Y-m-d', strtotime($date . ' -1 day'));
    $prevPay = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END),0) AS gross,
               COALESCE(SUM(CASE WHEN payment_type='refund' AND refund_status IN ('completed','processing') THEN refund_amount ELSE 0 END),0) AS refunds
        FROM payments WHERE DATE(payment_date) = :d AND deleted_at IS NULL
    ");
    $prevPay->execute([':d' => $prevDay]);
    $pr       = $prevPay->fetch(PDO::FETCH_ASSOC) ?: ['gross' => 0, 'refunds' => 0];
    $prev_net = (float)$pr['gross'] - (float)$pr['refunds'];

    $prevOcc = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status IN ('checked-in','checked-out') AND check_in_date <= ? AND check_out_date > ?");
    $prevOcc->execute([$prevDay, $prevDay]);
    $prev_occ_count = (int)$prevOcc->fetchColumn();
    $prev_occ_pct   = $rooms_total > 0 ? ($prev_occ_count / $rooms_total) * 100 : 0;

    $prevPos = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END),0) FROM stock_orders WHERE created_at BETWEEN ? AND ?");
    $prevPos->execute([$prevDay . ' 00:00:00', $prevDay . ' 23:59:59']);
    $prev_pos_gross = (float)$prevPos->fetchColumn();

    $net_change = $net - $prev_net;
    $occ_change = $occupancy_pct - $prev_occ_pct;
    $pos_change = (float)$pos['gross'] - $prev_pos_gross;

    // 12) Tomorrow preview
    $tp = $pdo->prepare("
        SELECT SUM(CASE WHEN check_in_date  = :t1 AND status IN ('confirmed','tentative','pending') THEN 1 ELSE 0 END) AS arrivals,
               SUM(CASE WHEN check_out_date = :t2 AND status IN ('checked-in','confirmed')           THEN 1 ELSE 0 END) AS departures,
               COALESCE(SUM(CASE WHEN check_in_date = :t3 AND status IN ('confirmed','tentative','pending') THEN total_amount ELSE 0 END),0) AS rev_forecast
        FROM bookings
    ");
    $tp->execute([':t1' => $tomorrow, ':t2' => $tomorrow, ':t3' => $tomorrow]);
    $tom = $tp->fetch(PDO::FETCH_ASSOC) ?: ['arrivals' => 0, 'departures' => 0, 'rev_forecast' => 0];

    // 13) Health score
    $arrivals_remaining   = max(0, (int)($ops['expected_arrivals'] ?? 0) - (int)($ops['arrivals_completed'] ?? 0));
    $departures_remaining = max(0, (int)($ops['expected_departures'] ?? 0) - (int)($ops['departures_completed'] ?? 0));
    $rooms_unsold         = max(0, $rooms_total - $rooms_occupied);
    $score = 100;
    $score -= min(18, $arrivals_remaining * 4);
    $score -= min(16, $departures_remaining * 4);
    $score -= min(16, (int)($ops['no_shows'] ?? 0) * 8);
    $score -= min(12, (int)($ops['cancellations'] ?? 0) * 3);
    $score -= min(12, (int)$pos['voided_count'] * 4);
    $score -= (float)$rev['pending'] > 0 ? 8 : 0;
    $score -= $outstanding > 0 ? 8 : 0;
    $score -= ((int)$hk['pending'] + (int)$hk['in_progress']) > 0 ? 8 : 0;
    $score -= $rooms_oo > 0 ? 5 : 0;
    $score = max(0, min(100, $score));
    $score_label = $score >= 90 ? 'Excellent close' : ($score >= 75 ? 'Good close' : ($score >= 55 ? 'Needs attention' : 'Critical review'));

    // 14) Void reasons
    $vrStmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(void_reason),''),'No reason given') AS reason, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS value
        FROM stock_orders WHERE status='voided' AND created_at BETWEEN :a AND :b GROUP BY reason ORDER BY cnt DESC LIMIT 5
    ");
    $vrStmt->execute([':a' => $dayStart, ':b' => $dayEnd]);
    $void_reasons = $vrStmt->fetchAll(PDO::FETCH_ASSOC);

    // 15) Room type performance
    $room_type_perf = [];
    try {
        $rtStmt = $pdo->prepare("
            SELECT rt.name AS room_type, COUNT(DISTINCT b.id) AS bookings, COALESCE(SUM(p.total_amount), 0) AS revenue
            FROM payments p
            INNER JOIN bookings b ON b.id = p.booking_id
            INNER JOIN individual_rooms ir ON ir.id = b.room_id
            INNER JOIN room_types rt ON rt.id = ir.room_type_id
            WHERE DATE(p.payment_date) = :d AND p.payment_status IN ('completed','paid')
              AND COALESCE(p.payment_type,'') <> 'refund' AND p.booking_type = 'room' AND p.deleted_at IS NULL
            GROUP BY rt.id, rt.name ORDER BY revenue DESC LIMIT 6
        ");
        $rtStmt->execute([':d' => $date]);
        $room_type_perf = $rtStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    // 16) Guest intelligence
    $guest_intel  = ['new_guests' => 0, 'returning_guests' => 0, 'avg_lead_days' => 0];
    $returning_rate = 0.0;
    try {
        $giStmt = $pdo->prepare("
            SELECT SUM(CASE WHEN bcount.total = 1 THEN 1 ELSE 0 END) AS new_guests,
                   SUM(CASE WHEN bcount.total > 1 THEN 1 ELSE 0 END) AS returning_guests
            FROM bookings b
            INNER JOIN (SELECT guest_email, COUNT(*) AS total FROM bookings WHERE guest_email != '' AND status NOT IN ('cancelled','no-show','expired') GROUP BY guest_email) bcount ON bcount.guest_email = b.guest_email
            WHERE b.check_in_date = :d AND b.status NOT IN ('cancelled','no-show','expired') AND b.guest_email != ''
        ");
        $giStmt->execute([':d' => $date]);
        $giRow = $giStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $guest_intel['new_guests']       = (int)($giRow['new_guests'] ?? 0);
        $guest_intel['returning_guests'] = (int)($giRow['returning_guests'] ?? 0);
        $leadStmt = $pdo->prepare("SELECT ROUND(AVG(DATEDIFF(check_in_date, DATE(created_at)))) FROM bookings WHERE check_in_date = :d AND status NOT IN ('cancelled','no-show','expired')");
        $leadStmt->execute([':d' => $date]);
        $guest_intel['avg_lead_days'] = max(0, (int)($leadStmt->fetchColumn() ?? 0));
        $gitotal = $guest_intel['new_guests'] + $guest_intel['returning_guests'];
        $returning_rate = $gitotal > 0 ? ($guest_intel['returning_guests'] / $gitotal) * 100 : 0.0;
    } catch (Throwable $e) {}

    // 17) Maintenance snapshot
    $maintenance = ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'total_open' => 0];
    try {
        $mStmt = $pdo->prepare("SELECT COALESCE(priority,'medium') AS priority, COUNT(*) AS cnt FROM room_maintenance_schedules WHERE status IN ('pending','in_progress') GROUP BY COALESCE(priority,'medium')");
        $mStmt->execute();
        foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $mr) {
            $p = strtolower(trim((string)($mr['priority'] ?? 'medium')));
            if (isset($maintenance[$p])) $maintenance[$p] = (int)$mr['cnt'];
            $maintenance['total_open'] += (int)$mr['cnt'];
        }
    } catch (Throwable $e) {}

    // 18) Quotation pipeline
    $quotation_stats = ['sent_today' => 0, 'accepted_today' => 0, 'total_active' => 0, 'pipeline_value' => 0.0];
    try {
        $qStmt = $pdo->prepare("SELECT SUM(CASE WHEN DATE(sent_at)=:d THEN 1 ELSE 0 END) AS sent_today, SUM(CASE WHEN DATE(updated_at)=:d AND status='accepted' THEN 1 ELSE 0 END) AS accepted_today, SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) AS total_active, COALESCE(SUM(CASE WHEN status='sent' THEN total_amount ELSE 0 END),0) AS pipeline_value FROM quotations");
        $qStmt->execute([':d' => $date]);
        $qRow = $qStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $quotation_stats['sent_today']     = (int)($qRow['sent_today'] ?? 0);
        $quotation_stats['accepted_today'] = (int)($qRow['accepted_today'] ?? 0);
        $quotation_stats['total_active']   = (int)($qRow['total_active'] ?? 0);
        $quotation_stats['pipeline_value'] = (float)($qRow['pipeline_value'] ?? 0);
    } catch (Throwable $e) {}
} catch (Throwable $e) {
    error_log('EOD PDF: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Report generation failed: ' . $e->getMessage()]);
    exit;
}

// ── Closeout alerts for the PDF ──────────────────────────────────────────────
$payment_capture_rate2 = ($gross + (float)($rev['pending'] ?? 0)) > 0
    ? ($gross / ($gross + (float)($rev['pending'] ?? 0))) * 100
    : 100.0;
$arrivals_remaining2   = max(0, (int)($ops['expected_arrivals'] ?? 0) - (int)($ops['arrivals_completed'] ?? 0));
$departures_remaining2 = max(0, (int)($ops['expected_departures'] ?? 0) - (int)($ops['departures_completed'] ?? 0));
$rooms_unsold2         = max(0, $rooms_total - $rooms_occupied);
$empty_room_opp2       = $adr > 0 ? $rooms_unsold2 * $adr : 0.0;

$closeout_alerts = [];
if ($arrivals_remaining2 > 0)
    $closeout_alerts[] = ['level' => 'warn', 'title' => 'Arrivals still open', 'detail' => $arrivals_remaining2 . ' expected arrival(s) not checked in.'];
if ($departures_remaining2 > 0)
    $closeout_alerts[] = ['level' => 'warn', 'title' => 'Departures still open', 'detail' => $departures_remaining2 . ' expected departure(s) not checked out.'];
if ((float)($rev['pending'] ?? 0) > 0)
    $closeout_alerts[] = ['level' => 'warn', 'title' => 'Pending payments', 'detail' => pdfMoney($currency_symbol, (float)$rev['pending']) . ' still pending.'];
if ($outstanding > 0)
    $closeout_alerts[] = ['level' => 'warn', 'title' => 'Outstanding folio', 'detail' => pdfMoney($currency_symbol, $outstanding) . ' unpaid across active stays.'];
if ((int)$pos['voided_count'] > 0)
    $closeout_alerts[] = ['level' => 'watch', 'title' => 'POS voids to review', 'detail' => (int)$pos['voided_count'] . ' void(s) worth ' . pdfMoney($currency_symbol, (float)$pos['voided_value']) . '.'];
if (((int)$hk['pending'] + (int)$hk['in_progress']) > 0)
    $closeout_alerts[] = ['level' => 'watch', 'title' => 'Housekeeping open', 'detail' => ((int)$hk['pending'] + (int)$hk['in_progress']) . ' task(s) not completed.'];

// ---- Build and output PDF via shared builder --------------------------------
require_once __DIR__ . '/../../includes/eod-pdf-builder.php';

$d = [
    'date'                   => $date,
    'ops'                    => $ops,
    'rev'                    => $rev,
    'gross'                  => $gross,
    'net'                    => $net,
    'adr'                    => $adr,
    'revpar'                 => $revpar,
    'methods'                => $methods,
    'cash_total'             => $cash_total,
    'pos'                    => $pos,
    'pos_by_type'            => $pos_by_type,
    'top_items'              => $top_items,
    'void_reasons'           => $void_reasons,
    'hk'                     => $hk,
    'reviewRow'              => $reviewRow,
    'outstanding'            => $outstanding,
    'rooms_total'            => $rooms_total,
    'rooms_occupied'         => $rooms_occupied,
    'rooms_oo'               => $rooms_oo,
    'occupancy_pct'          => $occupancy_pct,
    'tom'                    => $tom,
    'score'                  => $score,
    'score_label'            => $score_label,
    'net_change'             => $net_change,
    'pos_change'             => $pos_change,
    'occ_change'             => $occ_change,
    'arrivals_remaining'     => $arrivals_remaining2,
    'departures_remaining'   => $departures_remaining2,
    'rooms_unsold'           => $rooms_unsold2,
    'empty_room_opportunity' => $empty_room_opp2,
    'payment_capture_rate'   => $payment_capture_rate2,
    'room_type_perf'         => $room_type_perf,
    'guest_intel'            => $guest_intel,
    'returning_rate'         => $returning_rate,
    'closeout_alerts'        => $closeout_alerts,
    'maintenance'            => $maintenance,
    'quotation_stats'        => $quotation_stats,
];

$filename = 'eod-report-' . $date . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo buildEodPdf($d, $site_name, $currency_symbol, $user['full_name'] ?? 'Admin');
exit;

// ---- (Legacy code below — superseded by eod-pdf-builder.php) ---------------
// Design constants kept only so the file parses if somehow the exit above fails:
$CREAM     = [243, 236, 228]; // #F3ECE4
$CHARCOAL  = [35, 31, 28];    // #231F1C
$BROWN     = [138, 119, 95];  // #8A775F
$GOLD      = [177, 130, 71];  // #B18247
$WHITE     = [255, 255, 255];
$LIGHT_BG  = [247, 243, 238]; // #F7F3EE
$TEXT2     = [94, 85, 77];    // #5E554D
$GREEN     = [22, 101, 52];   // dark green
$GREEN_BG  = [240, 253, 244];
$AMBER     = [146, 64, 14];
$AMBER_BG  = [255, 251, 235];
$RED       = [185, 28, 28];
$RED_BG    = [255, 241, 242];
$DIVIDER   = [229, 217, 201]; // #E5D9C9

$score_color = $score >= 90 ? [22, 101, 52] : ($score >= 75 ? [21, 128, 61] : ($score >= 55 ? [146, 64, 14] : [185, 28, 28]));

// ---- PDF setup -------------------------------------------------------------
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
$pdf = new JapandiTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Rosalyn\'s Hotel System');
$pdf->SetAuthor($site_name);
$pdf->SetTitle('End of Day Report — ' . $dateLabel);
$pdf->SetSubject('EOD Report');
$pdf->SetAutoPageBreak(true, 15);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(14, 14, 14);
$pdf->AddPage();

$y = 14;  // current Y cursor

// ============================================================
// HEADER BAND
// ============================================================
$pdf->SetFillColorArray($CHARCOAL);
$pdf->Rect(14, $y, 182, 20, 'F');
$pdf->SetTextColorArray($WHITE);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetXY(18, $y + 4);
$pdf->Cell(130, 7, $site_name . '  —  End of Day Report', 0, 0, 'L');
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(18, $y + 11);
$pdf->Cell(170, 5, $dateLabel . '   |   Generated ' . date('H:i') . '   |   By ' . ($user['full_name'] ?? 'Admin'), 0, 0, 'L');
$y += 24;

// ============================================================
// KPI STRIP (4 boxes)
// ============================================================
$kpiW = 44;
$kpiH = 18;
// Preset flags — hotel KPIs/sections only render for booking businesses;
// till-first presets (supermarket, retail, gym, bar) get POS KPIs instead.
$eodModBookings   = !function_exists('moduleEnabled') || moduleEnabled('bookings');
$eodModConference = !function_exists('moduleEnabled') || moduleEnabled('conference');
$eodOrdersToday   = (int)($pos['orders'] ?? 0);
if ($eodModBookings) {
    $kpi_slot2 = ['OCCUPANCY', number_format($occupancy_pct, 1) . '%', number_format($rooms_occupied) . '/' . $rooms_total . ' rooms'];
    $kpi_slot3 = ['ADR',       pdfMoney($currency_symbol, $adr),       'RevPAR ' . pdfMoney($currency_symbol, $revpar)];
} else {
    $kpi_slot2 = ['ORDERS TODAY',    (string)$eodOrdersToday, (int)($pos['voided_count'] ?? 0) . ' void(s)'];
    $kpi_slot3 = ['AVG ORDER VALUE', pdfMoney($currency_symbol, $eodOrdersToday > 0 ? ((float)($pos['gross'] ?? 0)) / $eodOrdersToday : 0), 'per settled order'];
}
$kpis = [
    ['NET REVENUE',  pdfMoney($currency_symbol, $net),              pdfSign($net_change) . pdfMoney($currency_symbol, abs($net_change)) . ' vs yday'],
    $kpi_slot2,
    $kpi_slot3,
    ['HEALTH SCORE', (string)$score . ' / 100',                     $score_label],
];

$kpiColors = [$CREAM, $CREAM, $CREAM, $CREAM];
$kpiX      = 14;
foreach ($kpis as $i => $k) {
    $pdf->SetFillColorArray($kpiColors[$i]);
    $pdf->Rect($kpiX, $y, $kpiW, $kpiH, 'F');
    $pdf->SetDrawColorArray($DIVIDER);
    $pdf->Rect($kpiX, $y, $kpiW, $kpiH, 'D');
    // label
    $pdf->SetTextColorArray($BROWN);
    $pdf->SetFont('helvetica', 'B', 6);
    $pdf->SetXY($kpiX + 2, $y + 2);
    $pdf->Cell($kpiW - 4, 4, $k[0], 0, 0, 'L');
    // value
    $pdf->SetTextColorArray($CHARCOAL);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetXY($kpiX + 2, $y + 6);
    $pdf->Cell($kpiW - 4, 7, $k[1], 0, 0, 'L');
    // sub
    $pdf->SetTextColorArray($TEXT2);
    $pdf->SetFont('helvetica', '', 6.5);
    $pdf->SetXY($kpiX + 2, $y + 13);
    $pdf->Cell($kpiW - 4, 4, $k[2], 0, 0, 'L');
    $kpiX += $kpiW + 2;
}
$y += $kpiH + 5;

// ============================================================
// SECTION HELPER
// ============================================================
/** @param array<int> $color */
function pdfSection(TCPDF $pdf, string $title, float $y, array $color): float
{
    $pdf->SetFillColorArray($color);
    $pdf->SetTextColorArray([255, 255, 255]);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetXY(14, $y);
    $pdf->Cell(182, 7, '  ' . strtoupper($title), 0, 0, 'L', true);
    return $y + 9;
}

/** @param array<int> $fillColor */
function pdfRow(TCPDF $pdf, string $lbl, string $val, float $y, array $fillColor, bool $bold = false): float
{
    $pdf->SetFillColorArray($fillColor);
    $pdf->SetDrawColorArray([229, 217, 201]);
    $pdf->SetFont('helvetica', $bold ? 'B' : '', 8);
    $pdf->SetTextColorArray([94, 85, 77]);
    $pdf->SetXY(14, $y);
    $pdf->Cell(110, 6, '  ' . $lbl, 'B', 0, 'L', true);
    $pdf->SetTextColorArray([35, 31, 28]);
    $pdf->SetFont('helvetica', $bold ? 'B' : '', 8);
    $pdf->Cell(72, 6, $val, 'B', 0, 'R', true);
    return $y + 6;
}

// ============================================================
// TWO-COLUMN LAYOUT  (left = revenue+ops, right = pos+cash)
// ============================================================
$colW  = 88;
$colR  = 14 + $colW + 6;  // x start of right column
$yL    = $y;
$yR    = $y;

// --- LEFT: REVENUE BY SOURCE ---
$yL = pdfSection($pdf, '  Revenue by Source', $yL, $CHARCOAL);
$revRows = [];
$eodModGym    = !function_exists('moduleEnabled') || moduleEnabled('gym');
$eodModEvents = function_exists('isEventsEnabled') ? isEventsEnabled() : true;
if ($eodModBookings)   { $revRows[] = ['Rooms',       pdfMoney($currency_symbol, (float)$rev['room_gross']), false]; }
if ($eodModConference) { $revRows[] = ['Conferences', pdfMoney($currency_symbol, (float)$rev['conf_gross']), false]; }
$revRows[] = [isRestaurantEnabled() ? 'F&B / POS' : 'POS', pdfMoney($currency_symbol, (float)$rev['fnb_gross']), false];
if ($eodModGym    || (float)$rev['gym_gross'] > 0)    { $revRows[] = ['Gym',    pdfMoney($currency_symbol, (float)$rev['gym_gross']),    false]; }
if ($eodModEvents || (float)$rev['events_gross'] > 0) { $revRows[] = ['Events', pdfMoney($currency_symbol, (float)$rev['events_gross']), false]; }
$revRows[] = ['Gross Total',  pdfMoney($currency_symbol, $gross),  true];
if ((float)$rev['refunds'] > 0) {
    $revRows[] = ['Less Refunds', '-' . pdfMoney($currency_symbol, (float)$rev['refunds']), false];
}
$revRows[] = ['Net Revenue',     pdfMoney($currency_symbol, $net),            true];
$revRows[] = ['VAT Collected',   pdfMoney($currency_symbol, (float)$rev['total_vat']), false];
$revRows[] = ['Transactions',    (string)(int)$rev['txn_count'],              false];
$revRows[] = ['Pending',         pdfMoney($currency_symbol, (float)$rev['pending']), false];
$revRows[] = ['Outstanding Folio', pdfMoney($currency_symbol, $outstanding),  false];

foreach ($revRows as $i => $rr) {
    $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
    // Draw only in left column area
    $pdf->SetFillColorArray($bg);
    $pdf->SetDrawColorArray($DIVIDER);
    $pdf->SetFont('helvetica', $rr[2] ? 'B' : '', 8);
    $pdf->SetTextColorArray($TEXT2);
    $pdf->SetXY(14, $yL);
    $pdf->Cell(55, 6, '  ' . $rr[0], 'B', 0, 'L', true);
    $pdf->SetTextColorArray($CHARCOAL);
    $pdf->Cell(33, 6, $rr[1], 'B', 0, 'R', true);
    $yL += 6;
}

// --- LEFT: FRONT OFFICE (hotel businesses only) ---
if ($eodModBookings) {
$yL += 3;
$yL = pdfSection($pdf, '  Front Office', $yL, $CHARCOAL);
$foRows = [
    ['Expected Arrivals',    (int)($ops['expected_arrivals'] ?? 0) . '  (done: ' . (int)($ops['arrivals_completed'] ?? 0) . ')'],
    ['Expected Departures',  (int)($ops['expected_departures'] ?? 0) . '  (done: ' . (int)($ops['departures_completed'] ?? 0) . ')'],
    ['Stay-overs (in-house)', (string)(int)($ops['stayovers'] ?? 0)],
    ['New Bookings Today',   (string)(int)($ops['new_bookings'] ?? 0)],
    ['Cancellations',        (string)(int)($ops['cancellations'] ?? 0)],
    ['No-Shows',             (string)(int)($ops['no_shows'] ?? 0)],
    ['ADR',                  pdfMoney($currency_symbol, $adr)],
    ['RevPAR',               pdfMoney($currency_symbol, $revpar)],
    ['Rooms Unsold',         (string)$rooms_unsold . ' of ' . $rooms_total],
];
foreach ($foRows as $i => $fr) {
    $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
    $pdf->SetFillColorArray($bg);
    $pdf->SetDrawColorArray($DIVIDER);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColorArray($TEXT2);
    $pdf->SetXY(14, $yL);
    $pdf->Cell(55, 6, '  ' . $fr[0], 'B', 0, 'L', true);
    $pdf->SetTextColorArray($CHARCOAL);
    $pdf->Cell(33, 6, $fr[1], 'B', 0, 'R', true);
    $yL += 6;
}
} // end front office (bookings)

// --- RIGHT: POS / F&B ---
$yR = pdfSection($pdf, isRestaurantEnabled() ? '  POS / F&B' : '  POS', $yR, $GOLD);
$posRows = [
    ['Total Orders',      (string)(int)$pos['orders']],
    ['Gross Revenue',     pdfMoney($currency_symbol, (float)$pos['gross'])],
    ['Cost of Goods',     pdfMoney($currency_symbol, (float)$pos['cogs'])],
    ['Gross Margin',      pdfMoney($currency_symbol, $pos_margin) . ' (' . number_format($pos_margin_pct, 1) . '%)'],
    ['Avg Order Value',   pdfMoney($currency_symbol, $avg_order)],
    ['Voids (count)',     (string)(int)$pos['voided_count']],
    ['Voided Value',      pdfMoney($currency_symbol, (float)$pos['voided_value'])],
    ['vs Yesterday',      pdfSign($pos_change) . pdfMoney($currency_symbol, abs($pos_change))],
];
foreach ($posRows as $i => $pr2) {
    $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
    $pdf->SetFillColorArray($bg);
    $pdf->SetDrawColorArray($DIVIDER);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColorArray($TEXT2);
    $pdf->SetXY($colR, $yR);
    $pdf->Cell(50, 6, '  ' . $pr2[0], 'B', 0, 'L', true);
    $pdf->SetTextColorArray($CHARCOAL);
    $pdf->Cell(32, 6, $pr2[1], 'B', 0, 'R', true);
    $yR += 6;
}

// Right: POS by type
if (!empty($pos_by_type)) {
    $yR += 3;
    $typeLabels = ['walk_in' => 'Walk-in/Dine-in', 'dine_in' => 'Dine-in', 'room_service' => 'Room Service', 'takeaway' => 'Takeaway', 'delivery' => 'Delivery', 'other' => 'Other'];
    $pdf->SetFillColorArray($GOLD);
    $pdf->SetTextColorArray($WHITE);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetXY($colR, $yR);
    $pdf->Cell(82, 5, '  ORDER TYPE BREAKDOWN', 0, 0, 'L', true);
    $yR += 5;
    foreach ($pos_by_type as $i => $pt) {
        $typeLabel = $typeLabels[$pt['order_type']] ?? ucfirst((string)$pt['order_type']);
        $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
        $pdf->SetFillColorArray($bg);
        $pdf->SetDrawColorArray($DIVIDER);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColorArray($TEXT2);
        $pdf->SetXY($colR, $yR);
        $pdf->Cell(43, 5, '  ' . $typeLabel, 'B', 0, 'L', true);
        $pdf->SetTextColorArray($CHARCOAL);
        $pdf->Cell(20, 5, (string)(int)$pt['cnt'] . ' orders', 'B', 0, 'C', true);
        $pdf->Cell(19, 5, pdfMoney($currency_symbol, (float)$pt['gross']), 'B', 0, 'R', true);
        $yR += 5;
    }
}

// Right: payment mix
$yR += 3;
$pdf->SetFillColorArray($BROWN);
$pdf->SetTextColorArray($WHITE);
$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetXY($colR, $yR);
$pdf->Cell(82, 5, '  PAYMENT METHOD MIX', 0, 0, 'L', true);
$yR += 5;
if (!empty($methods)) {
    foreach ($methods as $i => $mRow) {
        $mLabel = ucwords(str_replace('_', ' ', (string)$mRow['method']));
        $mShare = $gross > 0 ? ((float)$mRow['total'] / $gross) * 100 : 0;
        $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
        $pdf->SetFillColorArray($bg);
        $pdf->SetDrawColorArray($DIVIDER);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColorArray($TEXT2);
        $pdf->SetXY($colR, $yR);
        $pdf->Cell(43, 5, '  ' . $mLabel . '  (' . (int)$mRow['cnt'] . 'x)', 'B', 0, 'L', true);
        $pdf->SetTextColorArray($CHARCOAL);
        $pdf->Cell(20, 5, number_format($mShare, 1) . '%', 'B', 0, 'C', true);
        $pdf->Cell(19, 5, pdfMoney($currency_symbol, (float)$mRow['total']), 'B', 0, 'R', true);
        $yR += 5;
    }
}
$pdf->SetFillColorArray($CREAM);
$pdf->SetDrawColorArray($DIVIDER);
$pdf->SetFont('helvetica', 'B', 7.5);
$pdf->SetTextColorArray($BROWN);
$pdf->SetXY($colR, $yR);
$pdf->Cell(43, 5, '  Cash to reconcile', 'B', 0, 'L', true);
$pdf->SetTextColorArray($CHARCOAL);
$pdf->Cell(39, 5, pdfMoney($currency_symbol, $cash_total), 'B', 0, 'R', true);
$yR += 5;
$pdf->SetTextColorArray($BROWN);
$pdf->SetXY($colR, $yR);
$pdf->Cell(43, 5, '  Non-cash collected', 'B', 0, 'L', true);
$pdf->SetTextColorArray($CHARCOAL);
$pdf->Cell(39, 5, pdfMoney($currency_symbol, $ncash_total), 'B', 0, 'R', true);
$yR += 5;

// Advance Y past both columns
$y = max($yL, $yR) + 5;

// ============================================================
// FULL-WIDTH: TOP SELLING ITEMS
// ============================================================
if (!empty($top_items)) {
    // Check if we need a page break
    if ($y > 230) {
        $pdf->AddPage();
        $y = 14;
    }
    $y = pdfSection($pdf, '  Top Selling Items Today', $y, $CHARCOAL);
    // Header
    $pdf->SetFillColorArray($CREAM);
    $pdf->SetDrawColorArray($DIVIDER);
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetTextColorArray($BROWN);
    $pdf->SetXY(14, $y);
    $pdf->Cell(90, 5, '  Item', 'B', 0, 'L', true);
    $pdf->Cell(30, 5, 'Qty', 'B', 0, 'C', true);
    $pdf->Cell(62, 5, 'Revenue', 'B', 0, 'R', true);
    $y += 5;
    foreach ($top_items as $i => $it) {
        $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
        $pdf->SetFillColorArray($bg);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColorArray($TEXT2);
        $pdf->SetXY(14, $y);
        $pdf->Cell(90, 5, '  ' . (string)$it['item_name'], 'B', 0, 'L', true);
        $qty = rtrim(rtrim(number_format((float)$it['qty'], 2, '.', ''), '0'), '.');
        $pdf->SetTextColorArray($CHARCOAL);
        $pdf->Cell(30, 5, $qty, 'B', 0, 'C', true);
        $pdf->Cell(62, 5, pdfMoney($currency_symbol, (float)$it['revenue']), 'B', 0, 'R', true);
        $y += 5;
    }
    $y += 3;
}

// ============================================================
// FULL-WIDTH: VOID REASONS
// ============================================================
if (!empty($void_reasons)) {
    if ($y > 240) {
        $pdf->AddPage();
        $y = 14;
    }
    $y = pdfSection($pdf, '  POS Void Reasons', $y, $RED);
    $pdf->SetFillColorArray($CREAM);
    $pdf->SetDrawColorArray($DIVIDER);
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetTextColorArray($BROWN);
    $pdf->SetXY(14, $y);
    $pdf->Cell(110, 5, '  Reason', 'B', 0, 'L', true);
    $pdf->Cell(30, 5, 'Count', 'B', 0, 'C', true);
    $pdf->Cell(42, 5, 'Value', 'B', 0, 'R', true);
    $y += 5;
    foreach ($void_reasons as $i => $vr) {
        $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
        $pdf->SetFillColorArray($bg);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColorArray($TEXT2);
        $pdf->SetXY(14, $y);
        $pdf->Cell(110, 5, '  ' . (string)$vr['reason'], 'B', 0, 'L', true);
        $pdf->SetTextColorArray($CHARCOAL);
        $pdf->Cell(30, 5, (string)(int)$vr['cnt'], 'B', 0, 'C', true);
        $pdf->Cell(42, 5, pdfMoney($currency_symbol, (float)$vr['value']), 'B', 0, 'R', true);
        $y += 5;
    }
    $y += 3;
}

// ============================================================
// FULL-WIDTH: HOUSEKEEPING + REVIEWS + TOMORROW
// ============================================================
if ($y > 240) {
    $pdf->AddPage();
    $y = 14;
}
$eodModHousekeeping = !function_exists('moduleEnabled') || moduleEnabled('housekeeping');
if ($eodModHousekeeping) {
$y = pdfSection($pdf, '  Housekeeping & Guest Sentiment', $y, $BROWN);
$hkRevRows = [
    ['HK Pending',            (string)(int)$hk['pending']],
    ['HK In Progress',        (string)(int)$hk['in_progress']],
    ['HK Completed Today',    (string)(int)$hk['completed']],
    ['Reviews Received',      (int)$reviewRow['cnt'] > 0 ? (string)(int)$reviewRow['cnt'] . ' — avg ' . number_format((float)$reviewRow['avg_rating'], 1) . '/5' : 'None today'],
];
foreach ($hkRevRows as $i => $hr) {
    $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
    $pdf->SetFillColorArray($bg);
    $pdf->SetDrawColorArray($DIVIDER);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColorArray($TEXT2);
    $pdf->SetXY(14, $y);
    $pdf->Cell(100, 6, '  ' . $hr[0], 'B', 0, 'L', true);
    $pdf->SetTextColorArray($CHARCOAL);
    $pdf->Cell(82, 6, $hr[1], 'B', 0, 'R', true);
    $y += 6;
}
$y += 3;
} // end housekeeping section

if ($eodModBookings) {
$y = pdfSection($pdf, '  Tomorrow Preview — ' . date('D, M j', strtotime($tomorrow)), $y, $BROWN);
$tomRows = [
    ['Expected Arrivals',    (string)(int)$tom['arrivals']],
    ['Expected Departures',  (string)(int)$tom['departures']],
    ['Revenue Forecast',     pdfMoney($currency_symbol, (float)$tom['rev_forecast'])],
];
foreach ($tomRows as $i => $tr) {
    $bg = $i % 2 === 0 ? $LIGHT_BG : $CREAM;
    $pdf->SetFillColorArray($bg);
    $pdf->SetDrawColorArray($DIVIDER);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColorArray($TEXT2);
    $pdf->SetXY(14, $y);
    $pdf->Cell(100, 6, '  ' . $tr[0], 'B', 0, 'L', true);
    $pdf->SetTextColorArray($CHARCOAL);
    $pdf->Cell(82, 6, $tr[1], 'B', 0, 'R', true);
    $y += 6;
}
} // end tomorrow preview (bookings)

// ============================================================
// FOOTER
// ============================================================
$pdf->SetY(-10);
$pdf->SetFont('helvetica', 'I', 7);
$pdf->SetTextColorArray($TEXT2);
$pdf->Cell(0, 4, $site_name . '  |  EOD Report  |  ' . $date . '  |  Generated ' . date('Y-m-d H:i') . ' by ' . ($user['full_name'] ?? 'Admin') . '  |  All amounts in ' . trim($currency_symbol), 0, 0, 'C');

// ---- Output ----------------------------------------------------------------
$filename = 'eod-report-' . $date . '.pdf';
$pdf->Output($filename, 'D');
exit;

