<?php

/**
 * End-of-day REVENUE EARNED (accrual) - the single calculation behind the headline revenue figure on
 * the End of Day page, its CSV, the PDF and the e-mail / WhatsApp summary.
 *
 * Owner decision 2026-10-18: the headline is what was EARNED on the business date, not what was
 * banked. Cash collected (payments by payment_date less refunds), the payment-method mix and cash
 * to bank are a separate, cash-based view and are NOT computed here.
 *
 * Earned on a date =
 *   1. ROOMS     each in-house booking (checked-in / checked-out, check_in <= date < check_out) earns its
 *                BOOKED nightly rate for that one night, gross incl. VAT and levy: rh_booked_stay_split()
 *                per_night (room portion of total_with_vat / number_of_nights - the same basis
 *                rh_reprice_at_booked_rate uses). Package value is spread evenly over the stay's nights.
 *                A booking is ONE rate whatever the joined-room factor (the factor only enters rooms
 *                occupied, i.e. the ADR / RevPAR denominators).
 *   2. FOLIO     non-voided booking_charges posted on the date (posted_at), gross line_total. Food / drink /
 *                minibar / breakfast / room-service charges are F&B; every other type (service, laundry,
 *                custom, other) is "guest-bill extras". charge_type 'room' is skipped so a room-rate line
 *                can never double count the nights in (1).
 *   3. POS       sales paid on the business date (rh_pos_eod_summary, station business window) EXCLUDING
 *                room-service orders that were charged to a guest folio (already counted in (2)), less
 *                paid orders voided / refunded on the date (reversal on its own day, as the till does).
 *   1b. KEPT ON CANCELLATIONS   a cancelled booking under "keep first night" earns its
 *                cancellation_retained_amount (gross) on its check-in date, as its own line. Not in ADR / RevPAR.
 *   4. CONFERENCE / EVENTS   confirmed and completed bookings earn their total including VAT
 *                (total_with_vat, else total_amount + vat_amount) on the event date: conference_inquiries.event_date,
 *                and events.event_date for event_inquiries (joined by event_id). Cancelled / pending earn nothing.
 *      GYM     no service date exists, so gym stays on payments received on the date less gym refunds paid out.
 *
 * Only positional placeholders are used, so this runs the same with emulated or native prepares.
 */

require_once __DIR__ . '/pos-shift-totals.php';

if (!function_exists('rh_eod_earned_revenue')) {
    /**
     * @param array $modules optional ['bookings' => bool, 'pos' => bool]; a department whose module is
     *                       switched off contributes nothing (default: both on).
     * @return array{
     *   date:string, rooms_gross:float, package_gross:float, rooms_total_gross:float,
     *   folio_fnb_gross:float, folio_other_gross:float, pos_sales_gross:float, pos_room_folio_gross:float,
     *   pos_reversed:float, pos_net:float, fnb_gross:float, extras_gross:float,
     *   conf_gross:float, gym_gross:float, events_gross:float, cancel_kept_gross:float, in_house_bookings:int,
     *   total:float, cash_basis_note:string
     * }
     */
    function rh_eod_earned_revenue(PDO $pdo, string $date, array $modules = []): array
    {
        static $memo = [];
        $useBookings = (bool)($modules['bookings'] ?? true);
        $usePos = (bool)($modules['pos'] ?? true);
        $key = $date . '|' . ($useBookings ? '1' : '0') . ($usePos ? '1' : '0');
        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $out = [
            'date' => $date,
            'rooms_gross' => 0.0, 'package_gross' => 0.0, 'rooms_total_gross' => 0.0,
            'folio_fnb_gross' => 0.0, 'folio_other_gross' => 0.0,
            'pos_sales_gross' => 0.0, 'pos_room_folio_gross' => 0.0, 'pos_reversed' => 0.0, 'pos_net' => 0.0,
            'fnb_gross' => 0.0, 'extras_gross' => 0.0,
            'conf_gross' => 0.0, 'gym_gross' => 0.0, 'events_gross' => 0.0,
            'in_house_bookings' => 0,
            'total' => 0.0,
            'cancel_kept_gross' => 0.0,
            'cash_basis_note' => 'Gym revenue is counted when the payment is received (less refunds paid out), because gym fees have no service date to earn them on.',
        ];
        $dayStart = $date . ' 00:00:00';
        $dayEnd = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';

        // 1) Room nights stayed on this date, at each booking's booked nightly rate.
        if ($useBookings) {
            try {
                $st = $pdo->prepare("SELECT total_amount, vat_amount, total_with_vat, package_total, number_of_nights
                    FROM bookings
                    WHERE deleted_at IS NULL AND status IN ('checked-in','checked-out')
                      AND check_in_date <= ? AND check_out_date > ?");
                $st->execute([$date, $date]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
                    $split = rh_booked_stay_split($b);
                    $out['rooms_gross'] += (float)$split['per_night'];
                    $out['package_gross'] += (float)$split['package_gross'] / max(1, (int)$split['nights']);
                    $out['in_house_bookings']++;
                }
            } catch (Throwable $e) {
                error_log('rh_eod_earned_revenue rooms: ' . $e->getMessage());
            }

            // 1b) Kept on cancellations: a cancelled booking under "keep first night" keeps
            // cancellation_retained_amount (gross, VAT included). It is earned on the booking's check-in
            // date (the night that was kept) and is its own line, so it never enters ADR / RevPAR.
            try {
                $st = $pdo->prepare("SELECT COALESCE(SUM(cancellation_retained_amount),0)
                    FROM bookings
                    WHERE deleted_at IS NULL AND status = 'cancelled'
                      AND cancellation_retained_amount > 0 AND check_in_date = ?");
                $st->execute([$date]);
                $out['cancel_kept_gross'] = (float)$st->fetchColumn();
            } catch (Throwable $e) {
                error_log('rh_eod_earned_revenue cancellations: ' . $e->getMessage());
            }

            // 2) Folio charges posted on this date (not voided).
            try {
                $st = $pdo->prepare("SELECT bc.charge_type, COALESCE(SUM(bc.line_total),0) AS g
                    FROM booking_charges bc
                    INNER JOIN bookings b ON b.id = bc.booking_id AND b.deleted_at IS NULL
                    WHERE bc.voided = 0 AND bc.charge_type <> 'room'
                      AND bc.posted_at >= ? AND bc.posted_at < ?
                    GROUP BY bc.charge_type");
                $st->execute([$dayStart, $dayEnd]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (in_array((string)$r['charge_type'], ['food', 'drink', 'minibar', 'breakfast', 'room_service'], true)) {
                        $out['folio_fnb_gross'] += (float)$r['g'];
                    } else {
                        $out['folio_other_gross'] += (float)$r['g'];
                    }
                }
            } catch (Throwable $e) {
                error_log('rh_eod_earned_revenue folio: ' . $e->getMessage());
            }
        }

        // 3) POS / restaurant sales paid on this business date.
        if ($usePos) {
            try {
                $sum = rh_pos_eod_summary($pdo, $date);
                $a = (string)$sum['window_start'];
                $b = (string)$sum['window_end'];
                $out['pos_sales_gross'] = (float)($sum['totals']['gross'] ?? 0);

                // A room-service order is charged to the guest folio (counted in 2), so it is not a POS sale
                // as well. Same sale condition as rh_pos_eod_summary.
                $saleCond = "((o.paid_at IS NOT NULL AND o.status IN ('paid','completed','refunded','voided') AND o.paid_at >= ? AND o.paid_at < ?)"
                    . " OR (o.paid_at IS NULL AND o.status IN ('paid','completed') AND o.created_at >= ? AND o.created_at < ?))";
                $folioOrder = "o.order_type = 'room_service' AND EXISTS (SELECT 1 FROM booking_charges bc WHERE bc.stock_order_id = o.id)";
                $hasLink = rh_pos_col_exists($pdo, 'booking_charges', 'stock_order_id');

                if ($hasLink) {
                    $st = $pdo->prepare("SELECT COALESCE(SUM(o.total_amount),0) FROM stock_orders o WHERE $saleCond AND $folioOrder");
                    $st->execute([$a, $b, $a, $b]);
                    $out['pos_room_folio_gross'] = (float)$st->fetchColumn();
                }

                // Sales reversed on this date: paid orders voided today, orders refunded today.
                $notFolio = $hasLink ? " AND NOT ($folioOrder)" : '';
                $st = $pdo->prepare("SELECT COALESCE(SUM(o.total_amount),0) FROM stock_orders o
                    WHERE o.status = 'voided' AND o.paid_at IS NOT NULL AND o.voided_at >= ? AND o.voided_at < ?$notFolio");
                $st->execute([$a, $b]);
                $out['pos_reversed'] += (float)$st->fetchColumn();
                if (rh_pos_col_exists($pdo, 'stock_orders', 'refunded_at')) {
                    $st = $pdo->prepare("SELECT COALESCE(SUM(o.total_amount),0) FROM stock_orders o
                        WHERE o.status = 'refunded' AND o.refunded_at >= ? AND o.refunded_at < ?$notFolio");
                    $st->execute([$a, $b]);
                    $out['pos_reversed'] += (float)$st->fetchColumn();
                }
                $out['pos_net'] = $out['pos_sales_gross'] - $out['pos_room_folio_gross'] - $out['pos_reversed'];
            } catch (Throwable $e) {
                error_log('rh_eod_earned_revenue pos: ' . $e->getMessage());
            }
        }

        // 4a) Gym: payments received on the date less gym refunds paid out on it (no service date exists).
        try {
            $st = $pdo->prepare("SELECT
                    COALESCE(SUM(CASE WHEN COALESCE(payment_type,'') <> 'refund' AND payment_status IN ('completed','paid','refunded','partially_refunded') THEN total_amount ELSE 0 END),0) AS received,
                    COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN refund_amount ELSE 0 END),0) AS refunded
                FROM payments
                WHERE payment_date >= ? AND payment_date < ? AND deleted_at IS NULL
                  AND booking_type = 'gym'");
            $st->execute([$dayStart, $dayEnd]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['gym_gross'] = (float)($r['received'] ?? 0) - (float)($r['refunded'] ?? 0);
        } catch (Throwable $e) {
            error_log('rh_eod_earned_revenue gym: ' . $e->getMessage());
        }

        // 4b) Conferences: confirmed / completed bookings earn their total (total_with_vat, falling back to
        // total_amount + vat_amount when it is empty) on conference_inquiries.event_date. Cancelled and
        // pending bookings earn nothing.
        try {
            $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(NULLIF(total_with_vat,0), COALESCE(total_amount,0) + COALESCE(vat_amount,0))),0)
                FROM conference_inquiries
                WHERE status IN ('confirmed','completed') AND event_date = ?");
            $st->execute([$date]);
            $out['conf_gross'] = (float)$st->fetchColumn();
        } catch (Throwable $e) {
            error_log('rh_eod_earned_revenue conference: ' . $e->getMessage());
        }

        // 4c) Events: event_inquiries has no date of its own, so the date is events.event_date (via event_id).
        // Same statuses and amount rule as conferences.
        try {
            $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(NULLIF(ei.total_with_vat,0), COALESCE(ei.total_amount,0) + COALESCE(ei.vat_amount,0))),0)
                FROM event_inquiries ei
                INNER JOIN events ev ON ev.id = ei.event_id
                WHERE ei.status IN ('confirmed','completed') AND ev.event_date = ?");
            $st->execute([$date]);
            $out['events_gross'] = (float)$st->fetchColumn();
        } catch (Throwable $e) {
            error_log('rh_eod_earned_revenue events: ' . $e->getMessage());
        }

        foreach (['rooms_gross', 'package_gross', 'folio_fnb_gross', 'folio_other_gross', 'pos_sales_gross', 'pos_room_folio_gross', 'pos_reversed', 'pos_net', 'conf_gross', 'gym_gross', 'events_gross', 'cancel_kept_gross'] as $k) {
            $out[$k] = round((float)$out[$k], 2);
        }
        $out['rooms_total_gross'] = round($out['rooms_gross'] + $out['package_gross'], 2);
        $out['fnb_gross'] = round($out['pos_net'] + $out['folio_fnb_gross'], 2);
        $out['extras_gross'] = $out['folio_other_gross'];
        $out['total'] = round($out['rooms_total_gross'] + $out['extras_gross'] + $out['cancel_kept_gross'] + $out['fnb_gross'] + $out['conf_gross'] + $out['gym_gross'] + $out['events_gross'], 2);

        return $memo[$key] = $out;
    }
}

if (!function_exists('rh_eod_earned_series')) {
    /** Earned revenue for each date from $from to $to inclusive, keyed by Y-m-d. */
    function rh_eod_earned_series(PDO $pdo, string $from, string $to, array $modules = []): array
    {
        $series = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
            $d = date('Y-m-d', $t);
            $series[$d] = rh_eod_earned_revenue($pdo, $d, $modules);
        }
        return $series;
    }
}
