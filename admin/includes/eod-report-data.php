<?php

/**
 * End of Day report DATA: one builder for every End of Day output that leaves the page (owner 2026-11-13,
 * .claude/EOD_SPEC.md L6): the PDF download (admin/api/end-of-day-pdf.php), the clicked Email / WhatsApp
 * (admin/api/end-of-day-send.php) and the End of Day PDF in the timed daily email (includes/daily-reports-send.php).
 * The figures come from the shared helpers in eod-revenue.php / pos-shift-totals.php / receivables.php, so they
 * equal the page.
 *
 * Also here (pure, unit tested by scripts/tests/daily_reports_job.php): the WhatsApp target chain, the headline
 * facts list (rh_eod_headline_facts) and the optional "The day in brief" hook (AI lead's file, absent = nothing).
 *
 * Including this file connects to nothing; rh_eod_report_data() loads its database helpers when called.
 */

require_once __DIR__ . '/eod-revenue.php'; // pure at include (finance-refunds.php, pos-shift-totals.php)

if (!function_exists('rh_eod_report_data')) {
    /**
     * Every figure the End of Day PDF / e-mail / WhatsApp show for business date $date, in the shape buildEodPdf()
     * reads, plus the e-mail extras. Throws RuntimeException when the core figures cannot be read (F6: never send
     * or print a report of zeros); secondary blocks (room types, guests, maintenance, quotations) fall back to empty.
     */
    function rh_eod_report_data(PDO $pdo, string $date, string $currencySymbol = ''): array
    {
        require_once __DIR__ . '/front-desk-counts.php';
        require_once __DIR__ . '/receivables.php';
        if (!function_exists('isRestaurantEnabled') && is_file(__DIR__ . '/../../includes/booking-functions.php')) {
            require_once __DIR__ . '/../../includes/booking-functions.php';
        }
        $mod = static function (string $m): bool { return !function_exists('moduleEnabled') || moduleEnabled($m); };
        $mods = [
            'bookings' => $mod('bookings'), 'pos' => $mod('pos'), 'housekeeping' => $mod('housekeeping'),
            'conference' => $mod('conference'), 'gym' => $mod('gym'),
            'events' => function_exists('isEventsEnabled') ? isEventsEnabled() : true,
        ];
        $money = static function (float $v) use ($currencySymbol): string { return $currencySymbol . number_format($v, 2); };
        $tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));
        $prevDay = date('Y-m-d', strtotime($date . ' -1 day'));
        $earnedModules = ['bookings' => $mods['bookings'], 'pos' => $mods['pos']];
        $occSql = "SELECT COALESCE(SUM(" . rh_booking_rooms_factor_sql('b') . "), 0) FROM bookings b WHERE b.deleted_at IS NULL AND b.status IN ('checked-in','checked-out') AND b.check_in_date <= ? AND b.check_out_date > ?";

        try {
            // 1) Front office (expected arrivals = confirmed + pending + checked in; tentative apart).
            $st = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN check_in_date = ? AND status IN ('confirmed','pending','checked-in') THEN 1 ELSE 0 END) AS expected_arrivals,
                    SUM(CASE WHEN check_in_date = ? AND status = 'checked-in' THEN 1 ELSE 0 END) AS arrivals_completed,
                    SUM(CASE WHEN check_out_date = ? AND status IN ('checked-in','checked-out') THEN 1 ELSE 0 END) AS expected_departures,
                    SUM(CASE WHEN check_out_date = ? AND status = 'checked-out' THEN 1 ELSE 0 END) AS departures_completed,
                    SUM(CASE WHEN check_in_date < ? AND check_out_date > ? AND status = 'checked-in' THEN 1 ELSE 0 END) AS stayovers,
                    SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) AS new_bookings,
                    0 AS cancellations,
                    SUM(CASE WHEN check_in_date = ? AND status = 'no-show' THEN 1 ELSE 0 END) AS no_shows,
                    SUM(CASE WHEN check_in_date = ? AND status = 'tentative' THEN 1 ELSE 0 END) AS tentative_arrivals
                FROM bookings
                WHERE deleted_at IS NULL
            ");
            $st->execute(array_fill(0, 9, $date));
            $ops = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $ops['cancellations'] = rh_cancellations_on_date($pdo, $date);

            // 2) Occupancy: ONE base everywhere (sellable rooms; a joined pair counts once).
            $roomsTotal = rh_sellable_room_units();
            $roomsOo = rh_unsellable_room_units();
            $st = $pdo->prepare($occSql);
            $st->execute([$date, $date]);
            $roomsOccupied = (int)$st->fetchColumn();
            $occupancyPct = rh_occupancy_pct((float)$roomsOccupied, (float)$roomsTotal);

            // 3) Cash view: money received on the day, refunds on the day they were paid out (rh_eod_pay_day_sql).
            $ok = "payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund'";
            $rf = "payment_type='refund' AND refund_status IN ('completed','processing')";
            $st = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN booking_type='room'       AND $ok THEN total_amount ELSE 0 END),0) AS room_gross,
                    COALESCE(SUM(CASE WHEN booking_type='conference' AND $ok THEN total_amount ELSE 0 END),0) AS conf_gross,
                    COALESCE(SUM(CASE WHEN booking_type='restaurant' AND $ok THEN total_amount ELSE 0 END),0) AS fnb_gross,
                    COALESCE(SUM(CASE WHEN booking_type='gym'        AND $ok THEN total_amount ELSE 0 END),0) AS gym_gross,
                    COALESCE(SUM(CASE WHEN booking_type='event'      AND $ok THEN total_amount ELSE 0 END),0) AS events_gross,
                    COALESCE(SUM(CASE WHEN $ok THEN vat_amount ELSE 0 END),0) AS total_vat,
                    COALESCE(SUM(CASE WHEN booking_type='room'       AND $ok THEN vat_amount ELSE 0 END),0) AS room_vat,
                    COALESCE(SUM(CASE WHEN booking_type='conference' AND $ok THEN vat_amount ELSE 0 END),0) AS conf_vat,
                    COALESCE(SUM(CASE WHEN booking_type='restaurant' AND $ok THEN vat_amount ELSE 0 END),0) AS fnb_vat,
                    COALESCE(SUM(CASE WHEN $rf THEN " . rh_refund_amount_sql() . " ELSE 0 END),0) AS refunds,
                    COALESCE(SUM(CASE WHEN $rf THEN vat_amount ELSE 0 END),0) AS refund_vat,
                    COALESCE(SUM(CASE WHEN payment_status IN ('pending','partial') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount ELSE 0 END),0) AS pending,
                    COUNT(*) AS txn_count
                FROM payments
                WHERE " . rh_eod_pay_day_sql() . " AND " . rh_eod_cash_row_sql() . " AND deleted_at IS NULL
            ");
            $st->execute([$date, $date]);
            $rev = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $creditNotesRedeemed = rh_eod_credit_notes_redeemed($pdo, $date); // guest credit used: its own line, not cash
            $rev['total_vat'] = (float)($rev['total_vat'] ?? 0) - (float)($rev['refund_vat'] ?? 0);
            // The F&B part of room payments (room service on the folio) is F&B, not room revenue.
            $folioFnb = rh_eod_folio_fnb_allocation($pdo, $date);
            $moved = max(0.0, min((float)$folioFnb['gross'], (float)$rev['room_gross']));
            if ($moved > 0) {
                $movedVat = (float)$folioFnb['gross'] > 0 ? min((float)$rev['room_vat'], (float)$folioFnb['vat'] * ($moved / (float)$folioFnb['gross'])) : 0.0;
                $rev['room_gross'] = (float)$rev['room_gross'] - $moved;
                $rev['room_vat'] = (float)$rev['room_vat'] - $movedVat;
                $rev['fnb_gross'] = (float)$rev['fnb_gross'] + $moved;
                $rev['fnb_vat'] = (float)$rev['fnb_vat'] + $movedVat;
            }
            $gross = (float)$rev['room_gross'] + (float)$rev['conf_gross'] + (float)$rev['fnb_gross'] + (float)$rev['gym_gross'] + (float)$rev['events_gross'];
            $net = $gross - (float)$rev['refunds'];

            // 4) Headline: revenue EARNED (the page's calculation).
            $earned = rh_eod_earned_revenue($pdo, $date, $earnedModules);
            $adr = rh_adr((float)$earned['rooms_gross'], (float)$roomsOccupied);
            $revpar = $roomsTotal > 0 ? ((float)$earned['rooms_gross'] / $roomsTotal) : 0.0;

            // 5) Payment mix (non-cash net of non-cash refunds; credit-note moves apart), cash less cash refunds.
            $methods = rh_eod_payment_mix($pdo, $date);
            $methodTotals = ['cash' => 0.0, 'non_cash' => 0.0, 'unassigned' => 0.0];
            foreach ($methods as $m) {
                $n = strtolower(trim((string)($m['method'] ?? '')));
                $k = ($n === '' || $n === 'unassigned') ? 'unassigned' : (strpos($n, 'cash') !== false ? 'cash' : 'non_cash');
                $methodTotals[$k] += (float)$m['total'];
            }
            $cashRefundsOut = rh_eod_cash_refunds_out($pdo, $date);
            $methodTotals['cash'] -= $cashRefundsOut;
            $refundsToCredit = rh_eod_refunds_to_credit($pdo, $date);

            // 6) POS (trading window; voids / refunds by their own time).
            $posSummary = rh_pos_eod_summary($pdo, $date);
            $pos = array_merge(['orders' => 0, 'gross' => 0, 'cogs' => 0, 'voided_value' => 0, 'voided_count' => 0], $posSummary['totals']);

            // 7) Still owed: the shared receivables rule (snapshot now).
            $out = rh_eod_outstanding_split($pdo, rh_receivable_mods());

            // 8) Tomorrow + previous day (earned vs earned; cash on the same day rule).
            $tom = rh_eod_tomorrow_preview($pdo, $tomorrow);
            $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN $ok THEN total_amount ELSE 0 END),0) AS gross,
                    COALESCE(SUM(CASE WHEN $rf THEN " . rh_refund_amount_sql() . " ELSE 0 END),0) AS refunds
                FROM payments WHERE " . rh_eod_pay_day_sql() . " AND " . rh_eod_cash_row_sql() . " AND deleted_at IS NULL");
            $st->execute([$prevDay, $prevDay]);
            $pr = $st->fetch(PDO::FETCH_ASSOC) ?: ['gross' => 0, 'refunds' => 0];
            $st = $pdo->prepare($occSql);
            $st->execute([$prevDay, $prevDay]);
            $prevOccPct = rh_occupancy_pct((float)$st->fetchColumn(), (float)$roomsTotal);
            $previous = [
                'net' => (float)$pr['gross'] - (float)$pr['refunds'],
                'pos_gross' => (float)(rh_pos_eod_summary($pdo, $prevDay)['totals']['gross'] ?? 0),
                'earned' => (float)rh_eod_earned_revenue($pdo, $prevDay, $earnedModules)['total'],
                'occupancy_pct' => $prevOccPct,
            ];
        } catch (Throwable $e) {
            error_log('EOD report data ' . $date . ': ' . $e->getMessage());
            throw new RuntimeException('The End of Day figures could not be loaded: ' . $e->getMessage(), 0, $e);
        }

        // Secondary blocks: never stop the report.
        $hk = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
        try {
            $st = $pdo->prepare("SELECT SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN status='completed' AND DATE(updated_at) = ? THEN 1 ELSE 0 END) AS completed
                FROM housekeeping_assignments");
            $st->execute([$date]);
            $hk = array_merge($hk, array_map('intval', $st->fetch(PDO::FETCH_ASSOC) ?: []));
        } catch (Throwable $e) { /* table may not exist on every install */ }
        $reviewRow = ['cnt' => 0, 'avg_rating' => 0.0];
        try {
            $st = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(AVG(rating),0) AS avg_rating FROM reviews WHERE DATE(created_at) = ? AND status = 'approved'");
            $st->execute([$date]);
            $reviewRow = $st->fetch(PDO::FETCH_ASSOC) ?: $reviewRow;
        } catch (Throwable $e) { /* optional */ }
        $roomTypePerf = [];
        try {
            $st = $pdo->prepare("SELECT rt.name AS room_type, COUNT(DISTINCT b.id) AS bookings, COALESCE(SUM(p.total_amount), 0) AS revenue
                FROM payments p INNER JOIN bookings b ON b.id = p.booking_id INNER JOIN rooms rt ON rt.id = b.room_id
                WHERE DATE(p.payment_date) = ? AND p.payment_status IN ('completed','paid','refunded','partially_refunded')
                  AND COALESCE(p.payment_type,'') <> 'refund' AND p.booking_type = 'room' AND p.deleted_at IS NULL
                GROUP BY rt.id, rt.name ORDER BY revenue DESC LIMIT 6");
            $st->execute([$date]);
            $roomTypePerf = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* optional */ }
        $guestIntel = ['new_guests' => 0, 'returning_guests' => 0, 'avg_lead_days' => 0];
        $returningRate = 0.0;
        try {
            $st = $pdo->prepare("SELECT SUM(CASE WHEN bcount.total = 1 THEN 1 ELSE 0 END) AS new_guests,
                       SUM(CASE WHEN bcount.total > 1 THEN 1 ELSE 0 END) AS returning_guests
                FROM bookings b
                INNER JOIN (SELECT guest_email, COUNT(*) AS total FROM bookings WHERE guest_email != '' AND status NOT IN ('cancelled','no-show','expired') GROUP BY guest_email) bcount ON bcount.guest_email = b.guest_email
                WHERE b.check_in_date = ? AND b.status NOT IN ('cancelled','no-show','expired') AND b.guest_email != ''");
            $st->execute([$date]);
            $gi = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $guestIntel['new_guests'] = (int)($gi['new_guests'] ?? 0);
            $guestIntel['returning_guests'] = (int)($gi['returning_guests'] ?? 0);
            $st = $pdo->prepare("SELECT ROUND(AVG(DATEDIFF(check_in_date, DATE(created_at)))) FROM bookings WHERE check_in_date = ? AND status NOT IN ('cancelled','no-show','expired')");
            $st->execute([$date]);
            $guestIntel['avg_lead_days'] = max(0, (int)($st->fetchColumn() ?? 0));
            $giTotal = $guestIntel['new_guests'] + $guestIntel['returning_guests'];
            $returningRate = $giTotal > 0 ? ($guestIntel['returning_guests'] / $giTotal) * 100 : 0.0;
        } catch (Throwable $e) { /* optional */ }
        $maintenance = ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'total_open' => 0];
        try {
            foreach ($pdo->query("SELECT COALESCE(priority,'medium') AS priority, COUNT(*) AS cnt FROM room_maintenance_schedules WHERE status IN ('pending','in_progress') GROUP BY COALESCE(priority,'medium')")->fetchAll(PDO::FETCH_ASSOC) as $mr) {
                $p = strtolower(trim((string)($mr['priority'] ?? 'medium')));
                if (isset($maintenance[$p])) {
                    $maintenance[$p] = (int)$mr['cnt'];
                }
                $maintenance['total_open'] += (int)$mr['cnt'];
            }
        } catch (Throwable $e) { /* optional */ }
        $quotationStats = ['sent_today' => 0, 'accepted_today' => 0, 'total_active' => 0, 'pipeline_value' => 0.0];
        try {
            // Positional placeholders: the date is bound twice (a repeated named one fails under native prepares, L7).
            $st = $pdo->prepare("SELECT SUM(CASE WHEN DATE(sent_at) = ? THEN 1 ELSE 0 END) AS sent_today,
                    SUM(CASE WHEN DATE(updated_at) = ? AND status = 'accepted' THEN 1 ELSE 0 END) AS accepted_today,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS total_active,
                    COALESCE(SUM(CASE WHEN status = 'sent' THEN total_amount ELSE 0 END),0) AS pipeline_value FROM quotations");
            $st->execute([$date, $date]);
            $q = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $quotationStats = ['sent_today' => (int)($q['sent_today'] ?? 0), 'accepted_today' => (int)($q['accepted_today'] ?? 0),
                'total_active' => (int)($q['total_active'] ?? 0), 'pipeline_value' => (float)($q['pipeline_value'] ?? 0)];
        } catch (Throwable $e) { /* optional */ }

        $arrRem = max(0, (int)($ops['expected_arrivals'] ?? 0) - (int)($ops['arrivals_completed'] ?? 0));
        $depRem = max(0, (int)($ops['expected_departures'] ?? 0) - (int)($ops['departures_completed'] ?? 0));
        $roomsUnsold = max(0, $roomsTotal - $roomsOccupied);
        $stillOwed = (float)$out['folio'] + (float)$out['accounts'] + (float)$out['tabs'];
        $hkOpen = (int)$hk['pending'] + (int)$hk['in_progress'];
        $score = rh_eod_close_score([
            'arrivals_remaining' => $arrRem, 'departures_remaining' => $depRem, 'no_shows' => (int)($ops['no_shows'] ?? 0),
            'cancellations' => (int)($ops['cancellations'] ?? 0), 'voided_count' => (int)$pos['voided_count'], 'pending' => (float)$rev['pending'],
            'still_owed' => $stillOwed, 'hk_open' => $hkOpen, 'rooms_oo' => $roomsOo,
        ], $mods);
        $captureBase = $gross + (float)$rev['pending'];

        $alerts = [];
        if ($arrRem > 0) { $alerts[] = ['level' => 'warn', 'title' => 'Arrivals still open', 'detail' => $arrRem . ' expected arrival(s) not checked in.']; }
        if ($depRem > 0) { $alerts[] = ['level' => 'warn', 'title' => 'Departures still open', 'detail' => $depRem . ' expected departure(s) not checked out.']; }
        if ((float)$rev['pending'] > 0) { $alerts[] = ['level' => 'warn', 'title' => 'Pending payments', 'detail' => $money((float)$rev['pending']) . ' still pending.']; }
        if ((float)$out['folio'] > 0) { $alerts[] = ['level' => 'warn', 'title' => 'Outstanding folio', 'detail' => $money((float)$out['folio']) . ' still owed on open bookings (in-house, upcoming and checked out).']; }
        if ((float)$out['tabs'] > 0) { $alerts[] = ['level' => 'warn', 'title' => 'Open tabs (unpaid)', 'detail' => $money((float)$out['tabs']) . ' still owed on open tabs.']; }
        if (!empty($out['failed'])) { $alerts[] = ['level' => 'warn', 'title' => 'Outstanding figure incomplete', 'detail' => 'Some accounts could not be read, so the amount still owed may be understated.']; }
        if ((float)$out['accounts'] > 0) { $alerts[] = ['level' => 'warn', 'title' => 'Outstanding accounts', 'detail' => $money((float)$out['accounts']) . ' still owed on conference, event and gym accounts.']; }
        if ((int)$pos['voided_count'] > 0) { $alerts[] = ['level' => 'watch', 'title' => 'POS voids to review', 'detail' => (int)$pos['voided_count'] . ' void(s) worth ' . $money((float)$pos['voided_value']) . '.']; }
        if ($mods['housekeeping'] && $hkOpen > 0) { $alerts[] = ['level' => 'watch', 'title' => 'Housekeeping open', 'detail' => $hkOpen . ' task(s) not completed.']; }

        return [
            'date' => $date, 'mods' => $mods,
            'ops' => $ops, 'rev' => $rev, 'gross' => $gross, 'net' => $net,
            'earned' => $earned, 'earned_total' => (float)$earned['total'],
            'adr' => $adr, 'revpar' => $revpar,
            'methods' => $methods, 'method_totals' => $methodTotals, 'cash_total' => (float)$methodTotals['cash'],
            'cash_refunds_out' => $cashRefundsOut, 'refunds_to_credit' => $refundsToCredit, 'credit_notes_redeemed' => $creditNotesRedeemed,
            'pos' => $pos, 'pos_by_type' => $posSummary['by_type'] ?? [], 'top_items' => $posSummary['top_items'] ?? [],
            'void_reasons' => $posSummary['void_reasons'] ?? [],
            'hk' => $hk, 'reviewRow' => $reviewRow,
            'outstanding' => (float)$out['folio'], 'outstanding_accounts' => (float)$out['accounts'],
            'outstanding_tabs' => (float)$out['tabs'], 'outstanding_failed' => !empty($out['failed']), 'still_owed' => $stillOwed,
            'rooms_total' => $roomsTotal, 'rooms_occupied' => $roomsOccupied, 'rooms_oo' => $roomsOo, 'occupancy_pct' => $occupancyPct,
            'tom' => $tom, 'previous' => $previous,
            'score' => $score['score'], 'score_label' => $score['label'],
            'net_change' => (float)$earned['total'] - $previous['earned'],
            'pos_change' => (float)$pos['gross'] - $previous['pos_gross'],
            'occ_change' => $occupancyPct - $previous['occupancy_pct'],
            'arrivals_remaining' => $arrRem, 'departures_remaining' => $depRem, 'rooms_unsold' => $roomsUnsold,
            'empty_room_opportunity' => $adr > 0 ? $roomsUnsold * $adr : 0.0,
            'payment_capture_rate' => $captureBase > 0 ? ($gross / $captureBase) * 100 : 100.0,
            'room_type_perf' => $roomTypePerf, 'guest_intel' => $guestIntel, 'returning_rate' => $returningRate,
            'closeout_alerts' => $alerts, 'maintenance' => $maintenance, 'quotation_stats' => $quotationStats,
            'generated_hm' => rh_eod_now()->format('H:i'),
        ];
    }
}

if (!function_exists('rh_eod_headline_facts')) {
    /**
     * Pure: the headline figures of an End of Day report as a flat list for a written summary (owner 2026-11-13, the
     * AI lead's "The day in brief"). Only figures already on the report; no guest names, no booking references.
     * $d = rh_eod_report_data() shape (the page passes the same keys). $can(key) drops a figure this viewer cannot
     * see; null = everything in the report (the e-mail).
     * @return list<array{key:string,label:string,value:float|int,unit:string,basis:string}>
     */
    function rh_eod_headline_facts(array $d, ?callable $can = null): array
    {
        $mods = (array)($d['mods'] ?? []);
        $on = static function (string $k) use ($mods): bool { return !array_key_exists($k, $mods) || !empty($mods[$k]); };
        $earned = (array)($d['earned'] ?? []);
        $ops = (array)($d['ops'] ?? []);
        $pos = (array)($d['pos'] ?? []);
        $tom = (array)($d['tom'] ?? []);
        $rev = (array)($d['rev'] ?? []);
        $f = [];
        $add = static function (string $key, string $label, $value, string $unit, string $basis) use (&$f, $can): void {
            if ($can !== null && !$can($key)) {
                return;
            }
            $f[] = ['key' => $key, 'label' => $label, 'value' => $unit === 'count' ? (int)$value : round((float)$value, 2), 'unit' => $unit, 'basis' => $basis];
        };
        $add('revenue_earned', 'Revenue earned', $d['earned_total'] ?? ($earned['total'] ?? 0), 'money', 'Room nights stayed, guest-bill charges, till sales, conferences and events on their date, gym payments; VAT and levy included');
        $add('revenue_vs_previous_day', 'Revenue earned compared with the day before', $d['net_change'] ?? 0, 'money', 'This day\'s revenue earned less the day before\'s');
        if ($on('bookings')) {
            $add('rooms_revenue', 'Rooms (nights stayed)', $earned['rooms_total_gross'] ?? 0, 'money', 'Each in-house booking\'s booked nightly rate for this night, packages spread over the stay');
        }
        if ($on('pos')) {
            $add('fnb_revenue', 'Food, drink and till sales earned', $earned['fnb_gross'] ?? 0, 'money', 'Till sales on the trading day less voids and refunds, plus food and drink posted to guest bills');
        }
        $add('cash_collected', 'Cash collected (all methods, after refunds)', $d['net'] ?? 0, 'money', 'Payments received on this day less refunds paid out on this day');
        $add('refunds_paid_out', 'Refunds paid out', $rev['refunds'] ?? 0, 'money', 'The full amount paid back, on the day it was paid out');
        $add('cash_to_reconcile', 'Cash to reconcile', $d['cash_total'] ?? 0, 'money', 'Cash payments received less cash refunds paid out');
        if (empty($d['outstanding_failed'])) {
            $add('still_owed', 'Still owed', $d['still_owed'] ?? 0, 'money', 'Room folios, conference / event / gym accounts and open tabs, as of now');
        }
        if ($on('bookings')) {
            $add('occupancy', 'Occupancy', $d['occupancy_pct'] ?? 0, 'percent', 'Rooms occupied this night out of sellable rooms');
            $add('rooms_occupied', 'Rooms occupied', $d['rooms_occupied'] ?? 0, 'count', 'Checked-in or checked-out bookings staying this night');
            $add('adr', 'Average room rate incl. VAT (ADR)', $d['adr'] ?? 0, 'money', 'Room revenue earned for the night divided by rooms occupied');
            $add('arrivals_done', 'Arrivals checked in', $ops['arrivals_completed'] ?? 0, 'count', 'Arrivals due this day that are checked in');
            $add('arrivals_expected', 'Arrivals expected', $ops['expected_arrivals'] ?? 0, 'count', 'Confirmed, pending and checked-in bookings arriving this day');
            $add('departures_expected', 'Departures expected', $ops['expected_departures'] ?? 0, 'count', 'Bookings due to leave this day');
            $add('no_shows', 'No-shows', $ops['no_shows'] ?? 0, 'count', 'Bookings arriving this day marked no-show');
            $add('cancellations', 'Cancellations', $ops['cancellations'] ?? 0, 'count', 'Bookings cancelled on this day');
            $add('tomorrow_arrivals', 'Arrivals tomorrow', $tom['arrivals'] ?? 0, 'count', 'Confirmed and pending bookings arriving the next day');
        }
        if ($on('pos')) {
            $add('pos_orders', 'Till orders', $pos['orders'] ?? 0, 'count', 'Orders paid at the till or charged to a room on the trading day');
            $add('pos_voids', 'Till voids', $pos['voided_count'] ?? 0, 'count', 'Orders voided on the trading day');
        }
        $add('close_score', 'Close score (out of 100)', $d['score'] ?? 0, 'count', 'Starts at 100; open arrivals, departures, voids, pending payments and money owed lower it');
        return $f;
    }
}

if (!function_exists('rh_eod_headline_lines')) {
    /** Pure: the End of Day headline as plain lines (the timed daily e-mail body). Same figures as the PDF. */
    function rh_eod_headline_lines(array $d, string $currency = ''): array
    {
        $mods = (array)($d['mods'] ?? []);
        $on = static function (string $k) use ($mods): bool { return !array_key_exists($k, $mods) || !empty($mods[$k]); };
        $m = static function ($v) use ($currency): string { return $currency . number_format((float)$v, 2); };
        $chg = (float)($d['net_change'] ?? 0);
        $rev = (array)($d['rev'] ?? []);
        $lines = [];
        $lines[] = 'Revenue earned: ' . $m($d['earned_total'] ?? 0) . ' (' . ($chg >= 0 ? '+' : '-') . $m(abs($chg)) . ' against the day before)';
        $lines[] = 'Cash collected (after refunds): ' . $m($d['net'] ?? 0) . ((float)($rev['refunds'] ?? 0) > 0.004 ? ' (refunds paid out ' . $m($rev['refunds']) . ')' : '');
        if ((float)($d['refunds_to_credit'] ?? 0) > 0.004) {
            $lines[] = 'Of the refunds, moved to credit notes (guest credit, no cash out): ' . $m($d['refunds_to_credit']);
        }
        if ((float)($d['credit_notes_redeemed'] ?? 0) > 0.004) {
            $lines[] = 'Credit notes redeemed (guest credit used, no new money): ' . $m($d['credit_notes_redeemed']);
        }
        $lines[] = 'Cash to reconcile: ' . $m($d['cash_total'] ?? 0);
        if ($on('bookings')) {
            $lines[] = 'Occupancy: ' . number_format((float)($d['occupancy_pct'] ?? 0), 1) . '% (' . (int)($d['rooms_occupied'] ?? 0) . ' of ' . (int)($d['rooms_total'] ?? 0) . ' sellable rooms)';
        }
        if ($on('pos')) {
            $pos = (array)($d['pos'] ?? []);
            $lines[] = 'Till: ' . (int)($pos['orders'] ?? 0) . ' orders, ' . $m($pos['gross'] ?? 0);
        }
        $lines[] = 'Still owed: ' . (!empty($d['outstanding_failed']) ? 'unavailable (some accounts could not be read)' : $m($d['still_owed'] ?? 0));
        $lines[] = 'Close score: ' . (int)($d['score'] ?? 0) . '/100 (' . (string)($d['score_label'] ?? '') . ')';
        return $lines;
    }
}

if (!function_exists('rh_eod_ai_brief')) {
    /**
     * "The day in brief" for an e-mail: the AI lead's rh_ai_eod_email_brief() when its file exists and AI is
     * available, else null (nothing at all is shown). Never throws.
     */
    function rh_eod_ai_brief(PDO $pdo, string $date, array $facts): ?string
    {
        $file = __DIR__ . '/../../includes/ai/ai-eod-narrative.php';
        try {
            if (!function_exists('rh_ai_eod_email_brief') && is_file($file)) {
                require_once $file;
            }
            $brief = function_exists('rh_ai_eod_email_brief') ? rh_ai_eod_email_brief($pdo, $date, $facts) : null;
            return is_string($brief) && trim($brief) !== '' ? trim($brief) : null;
        } catch (Throwable $e) {
            error_log('EOD day in brief: ' . $e->getMessage());
            return null;
        }
    }

    /** Pure: the e-mail block for the brief ('' when there is none). */
    function rh_eod_brief_html(?string $brief): string
    {
        if ($brief === null || trim($brief) === '') {
            return '';
        }
        return '<div style="padding:14px 18px;background:#F7F3EE;border-left:4px solid #B18247;margin:0;">'
            . '<div style="font-size:10px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;font-weight:700;margin-bottom:6px;">The day in brief</div>'
            . '<div style="font-size:13px;color:#2A2723;line-height:1.5;">' . nl2br(htmlspecialchars(trim($brief), ENT_QUOTES, 'UTF-8')) . '</div></div>';
    }

    /** Pure: one WhatsApp line for the brief, or null when there is none or it is longer than 300 characters. */
    function rh_eod_brief_wa_line(?string $brief): ?string
    {
        if ($brief === null) {
            return null;
        }
        $line = trim(preg_replace('/\s+/u', ' ', $brief) ?? '');
        return ($line === '' || mb_strlen($line) > 300) ? null : 'The day in brief: ' . $line;
    }
}

if (!function_exists('rh_eod_whatsapp_target')) {
    /**
     * Who gets the End of Day WhatsApp (owner B2 2026-11-13): "End of Day WhatsApp number" (eod_whatsapp_number), then
     * the old chain whatsapp_number -> whatsapp_hotel_number -> phone_main. $get(key) is for tests (else getSetting).
     * @return array{number:string, source:string}
     */
    function rh_eod_whatsapp_target(?callable $get = null): array
    {
        $get = $get ?? static fn(string $k): string => function_exists('getSetting') ? trim((string)getSetting($k, '')) : '';
        foreach ([
            'eod_whatsapp_number' => 'End of Day WhatsApp number',
            'whatsapp_number' => 'hotel WhatsApp number',
            'whatsapp_hotel_number' => 'hotel WhatsApp number',
            'phone_main' => 'main phone number',
        ] as $k => $label) {
            $v = trim((string)$get($k));
            if ($v !== '') {
                return ['number' => $v, 'source' => $label];
            }
        }
        return ['number' => '', 'source' => ''];
    }
}
