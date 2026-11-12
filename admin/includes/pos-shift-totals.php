<?php
/**
 * POS till / shift / business-day money maths — the ONE place the rules live.
 *
 * Rules (owner-settled, see accounting decisions 2026-10):
 *  - A sale counts in the window its paid_at falls in, whatever its status is later
 *    (paid, refunded or voided-after-payment). Legacy paid rows with no paid_at fall
 *    back to created_at.
 *  - Cash is attributed to who took the money: stock_orders.paid_by (fallback
 *    created_by); split legs use stock_order_splits.paid_by_user_id (fallback the order).
 *  - A refund / void of a PAID order is a payout in the window its own timestamp
 *    (refunded_at / voided_at) falls in, by tender, against the user who paid it out.
 *    The original sale is never moved or restated.
 *  - Expected per tender = sales (net of nothing) minus payouts. Split orders are read
 *    per leg tender, never from stock_orders.payment_method (last leg only).
 *  - A refund of a STILL-PAID order (partial refund, or an 86 refund 'REF86-') is a payout
 *    when it is SETTLED (payments.refund_status completed, at refund_date_processed), by
 *    tender, against the user who recorded it. A refunded order whose refund is still
 *    pending/processing is not paid out yet. An 86 lowers the order total at sale time, but
 *    the guest paid the full amount: the REF86 value is therefore added back to the SALE
 *    tender (never to revenue) so the sale shift is right and the settling shift pays it out.
 */

if (!function_exists('rh_pos_col_exists')) {
    function rh_pos_col_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $st->execute([$table, $column]);
                $cache[$key] = ((int)$st->fetchColumn() > 0);
            } catch (Throwable $e) {
                $cache[$key] = false;
            }
        }
        return $cache[$key];
    }
}

if (!function_exists('rh_pos_payer_sql')) {
    /** SQL expression: the user who took payment on a (non-split) order row. */
    function rh_pos_payer_sql(PDO $pdo, string $o = 'o'): string
    {
        return rh_pos_col_exists($pdo, 'stock_orders', 'paid_by')
            ? "COALESCE($o.paid_by, $o.created_by)"
            : "$o.created_by";
    }
}

if (!function_exists('rh_pos_leg_payer_sql')) {
    /** SQL expression: the user who took a split leg (fallback: the order's payer). */
    function rh_pos_leg_payer_sql(PDO $pdo, string $s = 's', string $o = 'o'): string
    {
        return rh_pos_col_exists($pdo, 'stock_order_splits', 'paid_by_user_id')
            ? "COALESCE($s.paid_by_user_id, " . rh_pos_payer_sql($pdo, $o) . ")"
            : rh_pos_payer_sql($pdo, $o);
    }
}

if (!function_exists('rh_pos_tender_bucket')) {
    function rh_pos_tender_bucket(?string $method): ?string
    {
        switch ((string)$method) {
            case 'cash':
                return 'cash';
            case 'mobile_money':
                return 'mobile';
            case 'card_manual':
            case 'card_pos':
            case 'credit_card': // payments.payment_method enum value for a card
                return 'card';
        }
        return null;
    }
}

if (!function_exists('rh_pos_sale_window_sql')) {
    /** Orders whose SALE falls in [start,end): by paid_at, any later status. 4 params. */
    function rh_pos_sale_window_sql(string $o = 'o'): string
    {
        return "((($o.paid_at IS NOT NULL) AND $o.status IN ('paid','refunded','voided') AND $o.paid_at >= ? AND $o.paid_at < ?)"
            . " OR ($o.paid_at IS NULL AND $o.status = 'paid' AND $o.created_at >= ? AND $o.created_at < ?))";
    }
}

if (!function_exists('rh_pos_room_charge_sql')) {
    /**
     * Orders CHARGED TO A ROOM in [start,end): closed to a guest bill with no till tender (status
     * 'completed' - room service charged to the folio, closed when served / at checkout / on a booking
     * close). Counted when the order was started (created_at), or paid_at if one was ever stamped -
     * exactly End of Day's long-standing 'completed' rule, so the Z for a day's shifts sums to End of Day.
     * Owner 2026-11-11 (standard hotel POS practice): a "Charged to room" line on the Z, inside the order
     * count and sales, never in the drawer. 4 params, same as rh_pos_sale_window_sql.
     */
    function rh_pos_room_charge_sql(string $o = 'o'): string
    {
        return "($o.status = 'completed' AND (($o.paid_at IS NOT NULL AND $o.paid_at >= ? AND $o.paid_at < ?)"
            . " OR ($o.paid_at IS NULL AND $o.created_at >= ? AND $o.created_at < ?)))";
    }
}

if (!function_exists('rh_pos_z_sale_sql')) {
    /**
     * Every order on the Z for [start,end): sold at the till (rh_pos_sale_window_sql) OR charged to a room
     * (rh_pos_room_charge_sql). The ONE order definition of the Z, End of Day and Orders & Tabs "sold".
     * 8 params: rh_pos_z_sale_params($start, $end).
     */
    function rh_pos_z_sale_sql(string $o = 'o'): string
    {
        return '(' . rh_pos_sale_window_sql($o) . ' OR ' . rh_pos_room_charge_sql($o) . ')';
    }
}

if (!function_exists('rh_pos_z_sale_params')) {
    /** The 8 params of rh_pos_z_sale_sql. Pure. */
    function rh_pos_z_sale_params(string $start, string $end): array
    {
        return [$start, $end, $start, $end, $start, $end, $start, $end];
    }
}

if (!function_exists('rh_pos_z_order_tender')) {
    /**
     * Pure classifier: how an order sits on the Z. 'room' = charged to a guest bill (status completed, no
     * till tender); 'split' = split bill (each leg its own tender); 'cash' / 'mobile' / 'card' = the
     * order's tender; 'other' = a till sale with no drawer tender; null = not a sale (open, cancelled,
     * voided or refunded before any payment). A room-service order PAID at the till is a till sale in its
     * tender, never a room charge, so it is never counted twice.
     */
    function rh_pos_z_order_tender(array $o): ?string
    {
        $status = (string)($o['status'] ?? '');
        $paidAt = $o['paid_at'] ?? null;
        $paid = ($paidAt !== null && $paidAt !== '');
        if ($status === 'completed') {
            return 'room';
        }
        $sold = $paid ? in_array($status, ['paid', 'refunded', 'voided'], true) : ($status === 'paid');
        if (!$sold) {
            return null;
        }
        if ((int)($o['split_count'] ?? 1) > 1) {
            return 'split';
        }
        return rh_pos_tender_bucket($o['payment_method'] ?? null) ?? 'other';
    }
}

if (!function_exists('rh_pos_shift_window_floor')) {
    /** Earliest start of a cashier close: the PREVIOUS trading day's opening (trading start - 1 day). Pure. */
    function rh_pos_shift_window_floor(string $windowStart): string
    {
        return (new DateTimeImmutable($windowStart))->modify('-1 day')->format('Y-m-d H:i:s');
    }
}

if (!function_exists('rh_pos_shift_window_from')) {
    /** Close window start from the user's last close (null = none since the floor). Pure. */
    function rh_pos_shift_window_from(?string $lastClose, string $floor): string
    {
        return ($lastClose !== null && $lastClose !== '' && $lastClose > $floor) ? $lastClose : $floor;
    }
}

if (!function_exists('rh_pos_close_covered_end')) {
    /**
     * Up to when a stored close counted sales: its closed_at, never past the end of its own trading
     * day (an accountant close of a past day is recorded later but only covers that day). Pure.
     */
    function rh_pos_close_covered_end(string $closedAt, string $shiftDate): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $shiftDate)) {
            return $closedAt;
        }
        require_once __DIR__ . '/../../includes/station-hours.php';
        $tradingEnd = (string)rh_station_union_window_for_date($shiftDate)['trading_end_sql'];
        return $closedAt < $tradingEnd ? $closedAt : $tradingEnd;
    }
}

if (!function_exists('rh_pos_shift_window_from_closes')) {
    /**
     * Close window start from the user's other closes ([closed_at, shift_date] rows): the latest
     * covered end before $windowEnd, never before $floor. Pure.
     */
    function rh_pos_shift_window_from_closes(array $closes, string $floor, string $windowEnd): string
    {
        $last = null;
        foreach ($closes as $c) {
            $covered = rh_pos_close_covered_end((string)$c['closed_at'], (string)($c['shift_date'] ?? ''));
            if ($covered < $windowEnd && ($last === null || $covered > $last)) {
                $last = $covered;
            }
        }
        return rh_pos_shift_window_from($last, $floor);
    }
}

if (!function_exists('rh_pos_close_dates_covering')) {
    /**
     * Shift dates whose closes may have counted sales of trading day $date: that day, and the next
     * one (its close looks back one trading day). A user with a close on either is closed for $date. Pure.
     */
    function rh_pos_close_dates_covering(string $date): array
    {
        return [$date, (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')];
    }
}

if (!function_exists('rh_pos_shift_window_start')) {
    /**
     * Every till sale lands in exactly one cashier close (owner 2026-11-10). A close's window
     * starts where that user's previous close stopped counting (so nothing is counted twice),
     * looking back as far as the PREVIOUS trading day's opening (so a sale paid after a cashier's
     * 03:30 close, or a night shift that only closes after the 06:00 opening, is still counted,
     * once, in the next close). $excludeCloseId: rebuilding a stored close (leave itself out).
     * Day reports are unaffected: they stay on the trading business date.
     */
    function rh_pos_shift_window_start(PDO $pdo, int $userId, string $windowStart, string $windowEnd, ?int $excludeCloseId = null): string
    {
        $floor = rh_pos_shift_window_floor($windowStart);
        try {
            // No upper bound on closed_at: a close recorded LATER (e.g. an accountant catching up on an earlier day)
            // still counts by what it covered (its counted-up-to time, filtered in rh_pos_shift_window_from_closes).
            $sql = "SELECT closed_at, shift_date FROM stock_shift_closes WHERE user_id = ? AND closed_at >= ?";
            $params = [$userId, $floor];
            if ($excludeCloseId !== null) {
                $sql .= " AND id <> ?";
                $params[] = $excludeCloseId;
            }
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return rh_pos_shift_window_from_closes($st->fetchAll(PDO::FETCH_ASSOC) ?: [], $floor, $windowEnd);
        } catch (Throwable $e) {
            error_log('rh_pos_shift_window_start: ' . $e->getMessage());
        }
        return $windowStart;
    }
}

if (!function_exists('rh_pos_close_window')) {
    /**
     * The window a stored close counted: [from, end). end = when it was recorded (exclusive), never past its
     * trading day's end; from = as the till computed it. Used by every rebuild (Z report, revenue
     * rebuild, drift report) so each close is read against its OWN window.
     */
    function rh_pos_close_window(PDO $pdo, array $close): array
    {
        require_once __DIR__ . '/../../includes/station-hours.php';
        $biz = rh_station_union_window_for_date((string)$close['shift_date']);
        $end = (string)$biz['trading_end_sql'];
        // Up to the moment the close was recorded (exclusive), the same end the till counted to.
        $closedAt = (string)($close['closed_at'] ?? '');
        if ($closedAt !== '' && $closedAt < $end) {
            $end = $closedAt;
        }
        $from = rh_pos_shift_window_start($pdo, (int)$close['user_id'], (string)$biz['start_sql'], $end, (int)($close['id'] ?? 0));
        return ['from' => $from, 'end' => $end, 'day_start' => (string)$biz['start_sql']];
    }
}

if (!function_exists('rh_pos_settled_refund_payouts')) {
    /**
     * The ledger payout source: every refund row (payments.payment_type = 'refund', refund_status
     * 'completed') settled in [start,end) on an order that was paid - part refunds of a still-paid
     * order, the REF-/VOID- contra rows of a refunded/voided order, earlier part refunds of an order
     * refunded or voided later, and 86 refunds ('REF86-' rows). Each row is money that left the
     * drawer ONCE, at its own time, in its own tender, so a rebuilt earlier close keeps its part
     * refunds after the order is refunded in full. rh_pos_shift_reversals adds only what these rows
     * do not cover (tips, legacy orders without contra rows).
     * Event time = refund_date_processed (fallback updated_at, created_at); actor = recorded_by
     * (fallback the order's creator); tender = the refund row's own method. Store-credit
     * ('credit_note') rows never touch the drawer. Rows: same shape as rh_pos_shift_reversals,
     * id = the ORDER id, plus payment_id.
     */
    function rh_pos_settled_refund_payouts(PDO $pdo, string $start, string $end): array
    {
        $at = "COALESCE(p.refund_date_processed, p.updated_at, p.created_at)";
        try {
            $st = $pdo->prepare("
                SELECT p.id AS pid, o.id AS oid, p.payment_method AS method, p.payment_reference AS pref,
                       CASE WHEN COALESCE(p.refund_amount, 0) > 0 THEN p.refund_amount ELSE p.total_amount END AS amt,
                       $at AS event_at,
                       COALESCE(p.recorded_by, o.created_by) AS actor_id
                FROM payments p
                INNER JOIN stock_orders o ON o.id = p.booking_id
                WHERE p.booking_type = 'restaurant' AND p.payment_type = 'refund'
                  AND p.refund_status = 'completed' AND p.deleted_at IS NULL
                  AND o.paid_at IS NOT NULL
                  AND (p.payment_reference LIKE 'REF86-%' OR o.status IN ('paid','refunded','voided'))
                  AND $at >= ? AND $at < ?
                ORDER BY p.id ASC");
            $st->execute([$start, $end]);
        } catch (Throwable $e) {
            error_log('rh_pos_settled_refund_payouts: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $b = rh_pos_tender_bucket($r['method']);
            if (!$b) {
                continue; // credit note / other: not drawer money
            }
            $t = ['cash' => 0.0, 'mobile' => 0.0, 'card' => 0.0];
            $t[$b] = (float)$r['amt'];
            $out[] = [
                'id' => (int)$r['oid'],
                'payment_id' => (int)$r['pid'],
                'kind' => strpos((string)$r['pref'], 'VOID-') === 0 ? 'void' : 'refund',
                'actor_id' => (int)$r['actor_id'],
                'at' => (string)$r['event_at'],
                'cash' => $t['cash'], 'mobile' => $t['mobile'], 'card' => $t['card'],
                'total' => $t['cash'] + $t['mobile'] + $t['card'],
            ];
        }
        return $out;
    }
}

if (!function_exists('rh_pos_partial_refunds')) {
    /**
     * Money refunded on orders that are STILL paid (status paid / completed) and SETTLED in
     * [start,end) - the partial refunds that rh_pos_eod_summary / rh_eod_earned_revenue / the
     * accounting dashboard would otherwise miss, because only status 'refunded' orders are reversed
     * there. Each completed refund row counts at refund_amount (the full amount paid back, VAT
     * included) on its own date (refund_date_processed, else updated_at / created_at - the same
     * event time the shift close uses). 86 refunds ('REF86-') are skipped: an 86 already lowers the
     * order total. Orders refunded in full or voided are skipped: they are reversed at their whole
     * total elsewhere, so nothing is counted twice. $excludeFolio drops room-service orders charged
     * to a guest folio (those are folio revenue, not POS sales).
     *
     * @return array{amount:float,orders:int,by_type:array<string,float>}
     */
    function rh_pos_partial_refunds(PDO $pdo, string $start, string $end, bool $excludeFolio = false): array
    {
        $out = ['amount' => 0.0, 'orders' => 0, 'by_type' => []];
        $at = "COALESCE(p.refund_date_processed, p.updated_at, p.created_at)";
        $notFolio = '';
        if ($excludeFolio && rh_pos_col_exists($pdo, 'booking_charges', 'stock_order_id')) {
            $notFolio = " AND NOT (o.order_type = 'room_service' AND EXISTS (SELECT 1 FROM booking_charges bc WHERE bc.stock_order_id = o.id))";
        }
        try {
            $st = $pdo->prepare("
                SELECT COALESCE(NULLIF(o.order_type,''),'walk_in') AS order_type, o.id AS oid,
                       CASE WHEN COALESCE(p.refund_amount, 0) > 0 THEN p.refund_amount ELSE p.total_amount END AS amt
                FROM payments p
                INNER JOIN stock_orders o ON o.id = p.booking_id
                WHERE p.booking_type = 'restaurant' AND p.payment_type = 'refund'
                  AND p.refund_status = 'completed' AND p.deleted_at IS NULL
                  AND p.payment_reference NOT LIKE 'REF86-%'
                  AND o.paid_at IS NOT NULL AND o.status IN ('paid','completed')
                  AND $at >= ? AND $at < ?$notFolio");
            $st->execute([$start, $end]);
            $orders = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $amt = (float)$r['amt'];
                if ($amt <= 0) {
                    continue;
                }
                $out['amount'] += $amt;
                $out['by_type'][(string)$r['order_type']] = ($out['by_type'][(string)$r['order_type']] ?? 0.0) + $amt;
                $orders[(int)$r['oid']] = true;
            }
            $out['amount'] = round($out['amount'], 2);
            $out['orders'] = count($orders);
            foreach ($out['by_type'] as $k => $v) {
                $out['by_type'][$k] = round($v, 2);
            }
        } catch (Throwable $e) {
            error_log('rh_pos_partial_refunds: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('rh_pos_shift_reversals')) {
    /**
     * Refund / void payouts of PAID orders in [start,end): money that actually left the drawer, per
     * tender. Ledger refund/contra rows count at their own time in their own tender
     * (rh_pos_settled_refund_payouts); a refunded/voided order whose event falls in the window adds
     * only its tip and any total no ledger row covers (split by the order's tenders AFTER the
     * covered part is taken off). Across windows the payouts add up to exactly what was refunded.
     * Each row: id, kind (refund|void), actor_id, at, cash, mobile, card, total.
     */
    function rh_pos_shift_reversals(PDO $pdo, string $start, string $end): array
    {
        $hasRefundedAt = rh_pos_col_exists($pdo, 'stock_orders', 'refunded_at');
        $hasVoidedBy = rh_pos_col_exists($pdo, 'stock_orders', 'voided_by');
        $voidActor = $hasVoidedBy ? 'COALESCE(o.voided_by, o.created_by)' : 'o.created_by';
        // A refunded order counts when its money actually left the drawer: refunded_at, or the latest
        // settlement of its (non-86) refund rows when they were raised pending and settled later.
        // While any such row is still pending/processing nothing has been paid out yet.
        $refundAt = "GREATEST(o.refunded_at, COALESCE((SELECT MAX(pr.refund_date_processed) FROM payments pr WHERE pr.booking_type = 'restaurant' AND pr.booking_id = o.id AND pr.payment_type = 'refund' AND pr.deleted_at IS NULL AND pr.payment_reference NOT LIKE 'REF86-%' AND pr.refund_status = 'completed'), o.refunded_at))";
        $refundNotPending = "NOT EXISTS (SELECT 1 FROM payments pp WHERE pp.booking_type = 'restaurant' AND pp.booking_id = o.id AND pp.payment_type = 'refund' AND pp.deleted_at IS NULL AND pp.payment_reference NOT LIKE 'REF86-%' AND pp.refund_status IN ('pending','processing'))";
        $refundCond = $hasRefundedAt ? "(o.status = 'refunded' AND $refundNotPending AND $refundAt >= ? AND $refundAt < ?)" : "(1 = 0)";
        $sql = "
            SELECT o.id, o.status, o.payment_method, o.total_amount, COALESCE(o.tip_amount,0) AS tip,
                   COALESCE(o.split_count,1) AS sc,
                   " . ($hasRefundedAt ? "CASE WHEN o.status = 'refunded' THEN $refundAt ELSE o.voided_at END" : "o.voided_at") . " AS event_at,
                   CASE WHEN o.status = 'refunded'
                        THEN COALESCE((SELECT a.actor_id FROM stock_order_audit a WHERE a.order_id = o.id AND a.event = 'refunded' ORDER BY a.id DESC LIMIT 1), o.created_by)
                        ELSE $voidActor END AS actor_id
            FROM stock_orders o
            WHERE o.paid_at IS NOT NULL
              AND ($refundCond OR (o.status = 'voided' AND o.voided_at >= ? AND o.voided_at < ?))
        ";
        $params = $hasRefundedAt ? [$start, $end, $start, $end] : [$start, $end];
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return rh_pos_settled_refund_payouts($pdo, $start, $end);
        }

        $splitIds = [];
        foreach ($rows as $r) {
            if ((int)$r['sc'] > 1) {
                $splitIds[] = (int)$r['id'];
            }
        }
        $legs = [];
        if ($splitIds) {
            $ph = implode(',', array_fill(0, count($splitIds), '?'));
            $ls = $pdo->prepare("SELECT order_id, payment_method, split_amount, COALESCE(tip_amount,0) AS tip FROM stock_order_splits WHERE order_id IN ($ph)");
            $ls->execute($splitIds);
            foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $legs[(int)$l['order_id']][] = $l;
            }
        }

        // What the ledger already covers on each order: every non-86 refund / contra row raised on it
        // (pending, processing or completed; store credit included - refunded, just not drawer money).
        // rh_pos_settled_refund_payouts pays those rows out at their own time, in their own tender, so
        // the order-level payout below carries only what they do NOT cover.
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $orderIds = array_map(static fn($r) => (int)$r['id'], $rows);
        $covered = [];
        $cph = implode(',', array_fill(0, count($orderIds), '?'));
        $cv = $pdo->prepare("SELECT p.booking_id AS oid,
                                    COALESCE(SUM(CASE WHEN COALESCE(p.refund_amount, 0) > 0 THEN p.refund_amount ELSE p.total_amount END), 0) AS amt
                               FROM payments p
                              WHERE p.booking_type = 'restaurant' AND p.booking_id IN ($cph) AND p.payment_type = 'refund'
                                AND p.deleted_at IS NULL AND p.payment_reference NOT LIKE 'REF86-%'
                                AND COALESCE(p.refund_status, 'completed') IN ('pending','processing','completed')
                           GROUP BY p.booking_id");
        $cv->execute($orderIds);
        foreach ($cv->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
            $covered[(int)$c['oid']] = (float)$c['amt'];
        }

        $out = [];
        foreach ($rows as $r) {
            $oid = (int)$r['id'];
            // The order's tenders (sale value and tip separately): split legs, else the order's method.
            $base = ['cash' => 0.0, 'mobile' => 0.0, 'card' => 0.0];
            $tips = ['cash' => 0.0, 'mobile' => 0.0, 'card' => 0.0];
            $baseSum = 0.0;
            if ((int)$r['sc'] > 1 && !empty($legs[$oid])) {
                foreach ($legs[$oid] as $l) {
                    $baseSum += (float)$l['split_amount'];
                    $b = rh_pos_tender_bucket($l['payment_method']);
                    if ($b) {
                        $base[$b] += (float)$l['split_amount'];
                        $tips[$b] += (float)$l['tip'];
                    }
                }
            } else {
                $baseSum = (float)$r['total_amount'];
                $b = rh_pos_tender_bucket($r['payment_method']);
                if ($b) {
                    $base[$b] += (float)$r['total_amount'];
                    $tips[$b] += (float)$r['tip'];
                }
            }
            // Only the part of the order total no ledger row covers (orders without contra rows) is
            // inferred from the order's tenders - the covered part is taken off the total BEFORE the
            // split, so a part refund in another tender never leaves a phantom payout. Tips are never
            // in the ledger, so they leave here, in the tender they were paid in.
            $residual = round((float)$r['total_amount'] - ($covered[$oid] ?? 0.0), 2);
            $share = ($residual > $tol && $baseSum > $tol) ? min(1.0, $residual / $baseSum) : 0.0;
            $t = [];
            foreach (['cash', 'mobile', 'card'] as $k) {
                $t[$k] = round($base[$k] * $share + $tips[$k], 2);
            }
            $out[] = [
                'id' => $oid,
                'kind' => $r['status'] === 'refunded' ? 'refund' : 'void',
                'actor_id' => (int)$r['actor_id'],
                'at' => (string)$r['event_at'],
                'cash' => $t['cash'], 'mobile' => $t['mobile'], 'card' => $t['card'],
                'total' => round($t['cash'] + $t['mobile'] + $t['card'], 2),
            ];
        }

        // One payout per order and person per window: the order's ledger rows paid out by the same
        // person fold into its order-level row (so refund_count and the payout list show it once).
        $index = [];
        foreach ($out as $i => $o) {
            $index[$o['id'] . ':' . $o['actor_id']] = $i;
        }
        $extra = [];
        $hasLedger = [];
        foreach (rh_pos_settled_refund_payouts($pdo, $start, $end) as $s) {
            $key = $s['id'] . ':' . $s['actor_id'];
            if (isset($index[$key])) {
                $i = $index[$key];
                foreach (['cash', 'mobile', 'card', 'total'] as $k) {
                    $out[$i][$k] = round($out[$i][$k] + $s[$k], 2);
                }
                $out[$i]['merged'] = true;
            } else {
                $extra[] = $s;
                $hasLedger[$s['id']] = true;
            }
        }
        // An order-level row left with no money while another person's ledger rows paid that order out
        // is not a payout of its own.
        foreach ($out as $i => $o) {
            if (empty($o['merged']) && abs($o['total']) <= $tol && !empty($hasLedger[$o['id']])) {
                unset($out[$i]);
                continue;
            }
            unset($out[$i]['merged']);
        }
        return array_merge(array_values($out), $extra);
    }
}

if (!function_exists('rh_pos_shift_float')) {
    /**
     * Opening cash float declared in [start,end) (stock_shift_opens.opened_at). Each user's
     * LATEST declaration in the window counts (re-declaring corrects the float, it does not
     * add to it); $userId null = sum of every user's latest. Because a till close's window
     * starts at the previous close, a float already consumed by an earlier close is not
     * counted again. Returns ['amount' => float, 'recorded' => bool]; none recorded = 0.
     */
    function rh_pos_shift_float(PDO $pdo, ?int $userId, string $start, string $end): array
    {
        try {
            $st = $pdo->prepare("SELECT o.user_id, o.float_amount FROM stock_shift_opens o
                WHERE o.opened_at >= ? AND o.opened_at < ?" . ($userId !== null ? " AND o.user_id = ?" : '') . "
                ORDER BY o.opened_at ASC, o.id ASC");
            $st->execute($userId !== null ? [$start, $end, $userId] : [$start, $end]);
            $latest = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $latest[(int)$r['user_id']] = (float)$r['float_amount'];
            }
            return ['amount' => round(array_sum($latest), 2), 'recorded' => !empty($latest)];
        } catch (Throwable $e) {
            error_log('rh_pos_shift_float: ' . $e->getMessage());
            return ['amount' => 0.0, 'recorded' => false];
        }
    }
}

if (!function_exists('rh_pos_shift_totals')) {
    /**
     * Expected till figures for [start,end). $userId null = whole business day (all users).
     * $tabCutoff: orders created before it count as "settled from earlier tabs"
     * (defaults to $start). Returns gross sale tenders (gross_*), payouts (refund_*) and
     * the expected drawer figures (cash/mobile/card = gross - payouts).
     */
    function rh_pos_shift_totals(PDO $pdo, ?int $userId, string $start, string $end, ?string $tabCutoff = null): array
    {
        $tabCutoff = $tabCutoff ?: $start;
        $saleWin = rh_pos_sale_window_sql('o');
        $saleParams = [$start, $end, $start, $end];
        $payer = rh_pos_payer_sql($pdo, 'o');
        $legPayer = rh_pos_leg_payer_sql($pdo, 's', 'o');

        $gross = ['cash' => 0.0, 'mobile' => 0.0, 'card' => 0.0];

        // Non-split orders: tender straight off the order row.
        $sql = "SELECT o.payment_method AS method, COALESCE(SUM(o.total_amount + COALESCE(o.tip_amount,0)),0) AS amt
                FROM stock_orders o
                WHERE COALESCE(o.split_count,1) <= 1 AND $saleWin" . ($userId !== null ? " AND $payer = ?" : '') . "
                GROUP BY o.payment_method";
        $st = $pdo->prepare($sql);
        $st->execute($userId !== null ? array_merge($saleParams, [$userId]) : $saleParams);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $b = rh_pos_tender_bucket($r['method']);
            if ($b) {
                $gross[$b] += (float)$r['amt'];
            }
        }

        // Split orders: each leg's own tender and payer.
        $sql = "SELECT s.payment_method AS method, COALESCE(SUM(s.split_amount + COALESCE(s.tip_amount,0)),0) AS amt
                FROM stock_order_splits s
                INNER JOIN stock_orders o ON o.id = s.order_id
                WHERE COALESCE(o.split_count,1) > 1 AND $saleWin" . ($userId !== null ? " AND $legPayer = ?" : '') . "
                GROUP BY s.payment_method";
        $st = $pdo->prepare($sql);
        $st->execute($userId !== null ? array_merge($saleParams, [$userId]) : $saleParams);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $b = rh_pos_tender_bucket($r['method']);
            if ($b) {
                $gross[$b] += (float)$r['amt'];
            }
        }

        // 86 refunds: the order total was lowered at 86 time but the guest paid the full amount, so
        // that value is part of the SALE tender here (the settling shift pays it out via
        // rh_pos_settled_refund_payouts). Revenue (sales_total below) stays on the lowered total.
        // Attributed to the order's payer (not per split leg).
        try {
            $sql = "SELECT p.payment_method AS method,
                           COALESCE(SUM(CASE WHEN COALESCE(p.refund_amount, 0) > 0 THEN p.refund_amount ELSE p.total_amount END), 0) AS amt
                    FROM payments p
                    INNER JOIN stock_orders o ON o.id = p.booking_id
                    WHERE p.booking_type = 'restaurant' AND p.payment_type = 'refund'
                      AND p.payment_reference LIKE 'REF86-%' AND p.deleted_at IS NULL
                      AND COALESCE(p.refund_status, 'completed') IN ('pending','processing','completed')
                      AND $saleWin" . ($userId !== null ? " AND $payer = ?" : '') . "
                    GROUP BY p.payment_method";
            $st = $pdo->prepare($sql);
            $st->execute($userId !== null ? array_merge($saleParams, [$userId]) : $saleParams);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $b = rh_pos_tender_bucket($r['method']);
                if ($b) {
                    $gross[$b] += (float)$r['amt'];
                }
            }
        } catch (Throwable $e) {
            error_log('rh_pos_shift_totals REF86 add-back: ' . $e->getMessage());
        }

        // Order-level metrics (count, tips, earlier-tab settlements) follow the order's payer.
        $sql = "SELECT COUNT(*) AS n,
                       COALESCE(SUM(COALESCE(o.tip_amount,0)),0) AS tips,
                       COALESCE(SUM(CASE WHEN o.created_at < ? THEN 1 ELSE 0 END),0) AS tab_n,
                       COALESCE(SUM(CASE WHEN o.created_at < ? THEN o.total_amount + COALESCE(o.tip_amount,0) ELSE 0 END),0) AS tab_amt,
                       COALESCE(SUM(o.total_amount),0) AS sales_total
                FROM stock_orders o
                WHERE $saleWin" . ($userId !== null ? " AND $payer = ?" : '');
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$tabCutoff, $tabCutoff], $saleParams, $userId !== null ? [$userId] : []));
        $m = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        // Payouts in this window, by their own timestamp.
        $refund = ['cash' => 0.0, 'mobile' => 0.0, 'card' => 0.0];
        $refundCount = 0;
        $refundTotal = 0.0;
        foreach (rh_pos_shift_reversals($pdo, $start, $end) as $rv) {
            if ($userId !== null && $rv['actor_id'] !== $userId) {
                continue;
            }
            foreach (['cash', 'mobile', 'card'] as $k) {
                $refund[$k] += $rv[$k];
            }
            $refundCount++;
            $refundTotal += $rv['total'];
        }

        // Voids (any voided order, paid or not) by the voiding user, at voided_at.
        $voidActor = rh_pos_col_exists($pdo, 'stock_orders', 'voided_by') ? 'COALESCE(o.voided_by, o.created_by)' : 'o.created_by';
        $sql = "SELECT COUNT(*) AS n, COALESCE(SUM(o.total_amount),0) AS amt
                FROM stock_orders o
                WHERE o.status = 'voided'
                  AND ((o.voided_at IS NOT NULL AND o.voided_at >= ? AND o.voided_at < ?)
                    OR (o.voided_at IS NULL AND o.created_at >= ? AND o.created_at < ?))"
            . ($userId !== null ? " AND $voidActor = ?" : '');
        $st = $pdo->prepare($sql);
        $st->execute(array_merge($saleParams, $userId !== null ? [$userId] : []));
        $v = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        // Opening float is cash that sits in the drawer but is not a sale: it only affects the
        // expected DRAWER cash ('drawer_cash'). 'cash' stays net takings so revenue views are unchanged.
        $fl = rh_pos_shift_float($pdo, $userId, $start, $end);

        // Charged to room: on the Z (orders and sales), never in the drawer.
        $rc = rh_pos_room_charges($pdo, $userId, $start, $end);

        return rh_pos_shift_totals_assemble($gross, $refund, $refundCount, $refundTotal, $m, $v, $fl, $rc);
    }
}

if (!function_exists('rh_pos_shift_totals_assemble')) {
    /**
     * Pure: builds the rh_pos_shift_totals result from its read figures. Drawer figures (cash / mobile /
     * card, drawer_cash, gross_*, refund_*) come ONLY from the till tenders and payouts; room charges
     * ($rc = ['count','amount']) never touch them. 'orders_count' / 'sales_total' stay the till's own
     * (the figures stored on every close, old and new alike); 'room_charge_*' is the "Charged to room"
     * line and 'orders_all_count' / 'sales_all_total' are the Z totals that include it once.
     */
    function rh_pos_shift_totals_assemble(array $gross, array $refund, int $refundCount, float $refundTotal, array $m, array $v, array $fl, array $rc): array
    {
        $net = [];
        foreach (['cash', 'mobile', 'card'] as $k) {
            $net[$k] = round((float)($gross[$k] ?? 0) - (float)($refund[$k] ?? 0), 2);
        }
        $tillN = (int)($m['n'] ?? 0);
        $tillSales = round((float)($m['sales_total'] ?? 0), 2);
        $rcN = (int)($rc['count'] ?? 0);
        $rcAmt = round((float)($rc['amount'] ?? 0), 2);
        return [
            'float' => (float)($fl['amount'] ?? 0), 'float_recorded' => (bool)($fl['recorded'] ?? false),
            'drawer_cash' => round((float)($fl['amount'] ?? 0) + $net['cash'], 2),
            'cash' => $net['cash'], 'mobile' => $net['mobile'], 'card' => $net['card'],
            'gross_cash' => round((float)($gross['cash'] ?? 0), 2), 'gross_mobile' => round((float)($gross['mobile'] ?? 0), 2), 'gross_card' => round((float)($gross['card'] ?? 0), 2),
            'refund_cash' => round((float)($refund['cash'] ?? 0), 2), 'refund_mobile' => round((float)($refund['mobile'] ?? 0), 2), 'refund_card' => round((float)($refund['card'] ?? 0), 2),
            'refund_count' => $refundCount,
            'refund_amount' => round($refundTotal, 2),
            'sales_total' => $tillSales,
            'tips_total' => (float)($m['tips'] ?? 0),
            'orders_count' => $tillN,
            'settled_from_tabs_count' => (int)($m['tab_n'] ?? 0),
            'settled_from_tabs_amount' => (float)($m['tab_amt'] ?? 0),
            'voids_count' => (int)($v['n'] ?? 0),
            'voids_amount' => (float)($v['amt'] ?? 0),
            'room_charge_count' => $rcN,
            'room_charge_amount' => $rcAmt,
            'orders_all_count' => $tillN + $rcN,
            'sales_all_total' => round($tillSales + $rcAmt, 2),
        ];
    }
}

if (!function_exists('rh_pos_room_charges')) {
    /**
     * Orders charged to a room in [start,end) (rh_pos_room_charge_sql), for one user (attributed to the
     * order's payer, i.e. who took it: paid_by, else created_by) or all users (null). Gross order total,
     * the same figure End of Day counts. Returns ['count' => int, 'amount' => float].
     */
    function rh_pos_room_charges(PDO $pdo, ?int $userId, string $start, string $end): array
    {
        try {
            $payer = rh_pos_payer_sql($pdo, 'o');
            $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(o.total_amount),0) AS amt
                FROM stock_orders o WHERE " . rh_pos_room_charge_sql('o') . ($userId !== null ? " AND $payer = ?" : ''));
            $params = [$start, $end, $start, $end];
            if ($userId !== null) {
                $params[] = $userId;
            }
            $st->execute($params);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            return ['count' => (int)($r['n'] ?? 0), 'amount' => round((float)($r['amt'] ?? 0), 2)];
        } catch (Throwable $e) {
            error_log('rh_pos_room_charges: ' . $e->getMessage());
            return ['count' => 0, 'amount' => 0.0];
        }
    }
}

if (!function_exists('rh_pos_close_room_charges')) {
    /**
     * "Charged to room" for a STORED close, read from data on view over the close's own window (nothing
     * stored is changed). Closes store till orders / till takings only, so every close - before or after
     * this line existed - shows Z orders = stored orders + this count, Z sales = stored takings + this amount.
     */
    function rh_pos_close_room_charges(PDO $pdo, array $close): array
    {
        if (empty($close['user_id']) || empty($close['shift_date'])) {
            return ['count' => 0, 'amount' => 0.0];
        }
        try {
            $w = rh_pos_close_window($pdo, $close);
            return rh_pos_room_charges($pdo, (int)$close['user_id'], $w['from'], $w['end']);
        } catch (Throwable $e) {
            error_log('rh_pos_close_room_charges: ' . $e->getMessage());
            return ['count' => 0, 'amount' => 0.0];
        }
    }
}

if (!function_exists('rh_pos_close_add_room')) {
    /**
     * Pure: a STORED close shown on the Z definition. Keeps the stored till figures as till_orders_count /
     * till_revenue and sets orders_count / total_revenue to till + "Charged to room" ($rc = ['count','amount'],
     * from rh_pos_close_room_charges). Drawer figures (expected / declared / variance) are not touched.
     */
    function rh_pos_close_add_room(array $c, array $rc): array
    {
        $c['till_orders_count'] = (int)($c['orders_count'] ?? 0);
        $c['till_revenue'] = round((float)($c['total_revenue'] ?? 0), 2);
        $c['room_charge_count'] = (int)($rc['count'] ?? 0);
        $c['room_charge_amount'] = round((float)($rc['amount'] ?? 0), 2);
        $c['orders_count'] = $c['till_orders_count'] + $c['room_charge_count'];
        $c['total_revenue'] = round($c['till_revenue'] + $c['room_charge_amount'], 2);
        return $c;
    }
}

if (!function_exists('rh_pos_z_window_sql')) {
    /**
     * One row of Z figures for [start,end), all users: till sales (rh_pos_sale_window_sql) and charged to room
     * (rh_pos_room_charge_sql) split by status = 'completed' (the two rules never share a row). Gross order
     * totals, the same as rh_pos_shift_totals' sales_total and rh_pos_room_charges. 8 params:
     * rh_pos_z_sale_params($start, $end).
     */
    function rh_pos_z_window_sql(): string
    {
        return "SELECT COALESCE(SUM(CASE WHEN o.status = 'completed' THEN 0 ELSE 1 END),0) AS till_n,
                       COALESCE(SUM(CASE WHEN o.status = 'completed' THEN 0 ELSE o.total_amount END),0) AS till_amt,
                       COALESCE(SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END),0) AS room_n,
                       COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.total_amount ELSE 0 END),0) AS room_amt
                FROM stock_orders o WHERE " . rh_pos_z_sale_sql('o');
    }
}

if (!function_exists('rh_pos_z_window_assemble')) {
    /** Pure: rh_pos_z_window_sql row -> till / room / Z totals (orders = till + room, sales = till + room). */
    function rh_pos_z_window_assemble(array $r): array
    {
        $tillN = (int)($r['till_n'] ?? 0);
        $tillAmt = round((float)($r['till_amt'] ?? 0), 2);
        $roomN = (int)($r['room_n'] ?? 0);
        $roomAmt = round((float)($r['room_amt'] ?? 0), 2);
        return [
            'till_count' => $tillN, 'till_amount' => $tillAmt,
            'room_charge_count' => $roomN, 'room_charge_amount' => $roomAmt,
            'orders' => $tillN + $roomN, 'sales' => round($tillAmt + $roomAmt, 2),
        ];
    }
}

if (!function_exists('rh_pos_z_window_totals')) {
    /** Z figures for [start,end), all users (rh_pos_z_window_sql). Read-only; zeros on error. */
    function rh_pos_z_window_totals(PDO $pdo, string $start, string $end): array
    {
        try {
            $st = $pdo->prepare(rh_pos_z_window_sql());
            $st->execute(rh_pos_z_sale_params($start, $end));
            return rh_pos_z_window_assemble($st->fetch(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            error_log('rh_pos_z_window_totals: ' . $e->getMessage());
            return rh_pos_z_window_assemble([]);
        }
    }
}

if (!function_exists('rh_pos_last_ended_trading_date')) {
    /**
     * The business date of the latest trading day that has ENDED by $now (owner 2026-11-10: "count with the day
     * that ended"): the newest of today / yesterday / the day before whose trading end (next opening) is <= $now.
     * With 06:00 openings: run at 23:00 on the 12th -> the 11th (the 12th is still trading); run at 03:00 on the 12th
     * -> the 10th, because the 11th's trading day runs until the 12th's 06:00 opening and has not ended yet; from
     * 06:00 on the 12th -> the 11th. So a run before opening reports the day before yesterday. $windowFor($date) returns a
     * station-hours window (needs 'trading_end' as DateTimeImmutable); defaults to rh_station_union_window_for_date.
     */
    function rh_pos_last_ended_trading_date(DateTimeImmutable $now, ?callable $windowFor = null): string
    {
        if ($windowFor === null) {
            require_once __DIR__ . '/../../includes/station-hours.php';
            $windowFor = static fn(string $d): array => rh_station_union_window_for_date($d);
        }
        for ($i = 0; $i <= 2; $i++) {
            $d = $now->modify('-' . $i . ' day')->format('Y-m-d');
            $w = $windowFor($d);
            if (isset($w['trading_end']) && $w['trading_end'] instanceof DateTimeInterface && $w['trading_end'] <= $now) {
                return $d;
            }
        }
        return $now->modify('-1 day')->format('Y-m-d');
    }
}

if (!function_exists('rh_pos_close_windows_batch')) {
    /**
     * Pure: rh_pos_close_window for many stored closes without a query each. $closes: the closes to window
     * (id, user_id, shift_date, closed_at). $others: every close of those users recorded on or after the earliest
     * floor (id, user_id, closed_at, shift_date) - the rows rh_pos_shift_window_start would read. $bizFor($date)
     * returns the station-hours window (start_sql, trading_end_sql). Same rules as rh_pos_close_window: end = the
     * close's closed_at capped at its trading end; from = the user's previous covered end, never before the floor.
     * @return array<int, array{user_id:int, from:string, end:string}> keyed by close id
     */
    function rh_pos_close_windows_batch(array $closes, array $others, callable $bizFor): array
    {
        $byUser = [];
        foreach ($others as $o) {
            $byUser[(int)($o['user_id'] ?? 0)][] = $o;
        }
        $out = [];
        foreach ($closes as $c) {
            $id = (int)($c['id'] ?? 0);
            $uid = (int)($c['user_id'] ?? 0);
            if ($id <= 0 || $uid <= 0 || empty($c['shift_date'])) {
                continue;
            }
            $biz = $bizFor((string)$c['shift_date']);
            $end = (string)$biz['trading_end_sql'];
            $closedAt = (string)($c['closed_at'] ?? '');
            if ($closedAt !== '' && $closedAt < $end) {
                $end = $closedAt;
            }
            $floor = rh_pos_shift_window_floor((string)$biz['start_sql']);
            $mine = [];
            foreach ($byUser[$uid] ?? [] as $o) {
                if ((int)($o['id'] ?? 0) !== $id && (string)($o['closed_at'] ?? '') >= $floor) {
                    $mine[] = $o;
                }
            }
            $out[$id] = ['user_id' => $uid, 'from' => rh_pos_shift_window_from_closes($mine, $floor, $end), 'end' => $end];
        }
        return $out;
    }
}

if (!function_exists('rh_pos_room_charges_by_windows')) {
    /**
     * Pure: "Charged to room" per close window. $orders: room-charge rows (uid = payer, ts = paid_at else
     * created_at, amount = total_amount) - the rh_pos_room_charge_sql rule. An order counts in a window when its
     * payer is the close's user and from <= ts < end, exactly as rh_pos_room_charges.
     * @return array<int, array{count:int, amount:float}> keyed by close id
     */
    function rh_pos_room_charges_by_windows(array $windows, array $orders): array
    {
        $byUser = [];
        foreach ($orders as $o) {
            $byUser[(int)($o['uid'] ?? 0)][] = $o;
        }
        $out = [];
        foreach ($windows as $id => $w) {
            $n = 0;
            $amt = 0.0;
            foreach ($byUser[(int)$w['user_id']] ?? [] as $o) {
                $ts = (string)($o['ts'] ?? '');
                if ($ts >= $w['from'] && $ts < $w['end']) {
                    $n++;
                    $amt += (float)($o['amount'] ?? 0);
                }
            }
            $out[$id] = ['count' => $n, 'amount' => round($amt, 2)];
        }
        return $out;
    }
}

if (!function_exists('rh_pos_closes_room_charges_batch')) {
    /**
     * "Charged to room" for a page of stored closes in two queries (System Logs), the same figures as calling
     * rh_pos_close_room_charges per close. Read-only. A close with no user or shift date is not in the result (0, as
     * before). NULL = the read FAILED: callers show "could not be loaded", never 0 (absence rule).
     * @return array<int, array{count:int, amount:float}>|null keyed by close id
     */
    function rh_pos_closes_room_charges_batch(PDO $pdo, array $closes): ?array
    {
        if ($closes === []) {
            return [];
        }
        try {
            require_once __DIR__ . '/../../includes/station-hours.php';
            $bizCache = [];
            $bizFor = static function (string $d) use (&$bizCache): array {
                if (!isset($bizCache[$d])) {
                    $bizCache[$d] = rh_station_union_window_for_date($d);
                }
                return $bizCache[$d];
            };
            $userIds = [];
            $minFloor = null;
            foreach ($closes as $c) {
                if ((int)($c['user_id'] ?? 0) > 0 && !empty($c['shift_date'])) {
                    $userIds[(int)$c['user_id']] = true;
                    $f = rh_pos_shift_window_floor((string)$bizFor((string)$c['shift_date'])['start_sql']);
                    $minFloor = ($minFloor === null || $f < $minFloor) ? $f : $minFloor;
                }
            }
            if ($userIds === []) {
                return [];
            }
            $ids = array_keys($userIds);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id, user_id, closed_at, shift_date FROM stock_shift_closes WHERE user_id IN ($in) AND closed_at >= ?");
            $st->execute(array_merge($ids, [$minFloor]));
            $windows = rh_pos_close_windows_batch($closes, $st->fetchAll(PDO::FETCH_ASSOC) ?: [], $bizFor);
            if ($windows === []) {
                return [];
            }
            $lo = min(array_column($windows, 'from'));
            $hi = max(array_column($windows, 'end'));
            $payer = rh_pos_payer_sql($pdo, 'o');
            $st = $pdo->prepare("SELECT $payer AS uid, COALESCE(o.paid_at, o.created_at) AS ts, o.total_amount AS amount
                FROM stock_orders o WHERE " . rh_pos_room_charge_sql('o') . " AND $payer IN ($in)");
            $st->execute(array_merge([$lo, $hi, $lo, $hi], $ids));
            return rh_pos_room_charges_by_windows($windows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            error_log('rh_pos_closes_room_charges_batch: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('rh_pos_station_report_sql')) {
    /**
     * Pure: the Station Day CSV's two queries (includes/report-builders.php) for [start,end) on the Z definition.
     * Per station / item line totals: till sales (rh_pos_sale_window_sql), charged to room (rh_pos_room_charge_sql)
     * and their total (rh_pos_z_sale_sql); orders / items / qty count Z orders only. Voided lines by void time
     * (voided_at, else created_at), so unpaid voids still show. Every window placeholder is a [start, end) pair.
     * Detail date: the HOTEL date of the sale / room charge / void. Timestamps are stored in the DB session zone, so
     * $hotelOffsetSeconds (hotel zone offset minus DB zone offset) is added first; it is the detail's first param.
     * @return array{summary: array{sql:string, params:array}, detail: array{sql:string, params:array}}
     */
    function rh_pos_station_report_sql(string $start, string $end, int $hotelOffsetSeconds = 0): array
    {
        $zSql = rh_pos_z_sale_sql('o');      // 8 params
        $zP = rh_pos_z_sale_params($start, $end);
        $till = rh_pos_sale_window_sql('o'); // 4 params
        $room = rh_pos_room_charge_sql('o'); // 4 params
        $p4 = [$start, $end, $start, $end];
        $void = "(o.status = 'voided' AND ((o.voided_at IS NOT NULL AND o.voided_at >= ? AND o.voided_at < ?)"
            . " OR (o.voided_at IS NULL AND o.created_at >= ? AND o.created_at < ?)))"; // 4 params

        $summary = "SELECT COALESCE(NULLIF(oi.station, ''), 'unassigned') AS station,
                          COUNT(DISTINCT CASE WHEN $zSql THEN o.id END) AS orders,
                          SUM(CASE WHEN $zSql THEN oi.quantity ELSE 0 END) AS items,
                          SUM(CASE WHEN $till THEN oi.line_total ELSE 0 END) AS till_sales,
                          SUM(CASE WHEN $room THEN oi.line_total ELSE 0 END) AS room_charged,
                          SUM(CASE WHEN $void THEN oi.line_total ELSE 0 END) AS voided_amount,
                          SUM(CASE WHEN $void THEN 1 ELSE 0 END) AS voided_lines,
                          SUM(CASE WHEN $zSql THEN oi.line_total ELSE 0 END) AS total_sales
                   FROM stock_order_items oi
                   INNER JOIN stock_orders o ON o.id = oi.order_id
                   WHERE ($zSql OR $void)
                   GROUP BY station
                   ORDER BY total_sales DESC";
        $detail = "SELECT DATE(DATE_ADD(COALESCE(o.paid_at, o.voided_at, o.created_at), INTERVAL ? SECOND)) AS day,
                         COALESCE(NULLIF(oi.station,''),'unassigned') AS station,
                         oi.menu_type, oi.item_name,
                         SUM(CASE WHEN $zSql THEN oi.quantity ELSE 0 END) AS qty,
                         SUM(CASE WHEN $till THEN oi.line_total ELSE 0 END) AS till_sales,
                         SUM(CASE WHEN $room THEN oi.line_total ELSE 0 END) AS room_charged,
                         SUM(CASE WHEN $void THEN oi.line_total ELSE 0 END) AS voided,
                         SUM(CASE WHEN $zSql THEN oi.line_total ELSE 0 END) AS total_sales
                  FROM stock_order_items oi
                  INNER JOIN stock_orders o ON o.id = oi.order_id
                  WHERE ($zSql OR $void)
                  GROUP BY day, station, oi.menu_type, oi.item_name
                  ORDER BY day, station, total_sales DESC";
        return [
            'summary' => ['sql' => $summary, 'params' => array_merge($zP, $zP, $p4, $p4, $p4, $p4, $zP, $zP, $p4)],
            'detail' => ['sql' => $detail, 'params' => array_merge([$hotelOffsetSeconds], $zP, $p4, $p4, $p4, $zP, $zP, $p4)],
        ];
    }
}

if (!function_exists('rh_pos_toolbar_z_figures')) {
    /**
     * Pure: the POS toolbar's shift figures on the Z definition, from pos_fetch_shift_summary(). Orders and Gross
     * = till + charged to room; Net = Gross less refunds paid out this shift; room = the "Charged to room" chip.
     * Cash / Mobile / Card (drawer) are not here: they stay till-only.
     */
    function rh_pos_toolbar_z_figures(array $s): array
    {
        $tillN = (int)($s['orders_today'] ?? 0);
        $tillAmt = (float)($s['revenue_today'] ?? 0);
        $roomAmt = (float)($s['room_charge_amount'] ?? 0);
        $orders = isset($s['orders_all']) ? (int)$s['orders_all'] : $tillN + (int)($s['room_charge_count'] ?? 0);
        $gross = isset($s['revenue_all']) ? (float)$s['revenue_all'] : $tillAmt + $roomAmt;
        return [
            'orders' => $orders,
            'gross' => round($gross, 2),
            'net' => round($gross - (float)($s['refund_today'] ?? 0), 2),
            'room' => round($roomAmt, 2),
        ];
    }
}

if (!function_exists('rh_pos_close_revenue')) {
    /**
     * Net sales of a stored shift close (net takings across tenders, the figure written to
     * stock_shift_closes.total_revenue). Closes recorded before that column was filled carry 0/NULL:
     * for those the window is rebuilt exactly as the Z-report does (business window of the close date,
     * from this user's previous close to this close) and the till maths is re-read. Nothing is stored.
     */
    function rh_pos_close_revenue(PDO $pdo, array $close): float
    {
        $stored = (float)($close['total_revenue'] ?? 0);
        if (abs($stored) > 0.004) {
            return $stored;
        }
        if (empty($close['user_id']) || empty($close['shift_date']) || empty($close['closed_at'])) {
            return 0.0;
        }
        try {
            // The close's OWN window, exactly as the till counted it.
            $w = rh_pos_close_window($pdo, $close);
            $t = rh_pos_shift_totals($pdo, (int)$close['user_id'], $w['from'], $w['end'], $w['day_start']);
            return round((float)$t['cash'] + (float)$t['mobile'] + (float)$t['card'], 2);
        } catch (Throwable $e) {
            error_log('rh_pos_close_revenue: ' . $e->getMessage());
            return 0.0;
        }
    }
}

if (!function_exists('rh_pos_shift_user_ids')) {
    /** Every user with till activity (sales taken, tabs opened, payouts, voids) in [start,end). */
    function rh_pos_shift_user_ids(PDO $pdo, string $start, string $end): array
    {
        $ids = [];
        $saleWin = rh_pos_sale_window_sql('o');
        $saleParams = [$start, $end, $start, $end];
        $payer = rh_pos_payer_sql($pdo, 'o');
        $legPayer = rh_pos_leg_payer_sql($pdo, 's', 'o');

        $queries = [
            ["SELECT DISTINCT $payer FROM stock_orders o WHERE $saleWin", $saleParams],
            ["SELECT DISTINCT $legPayer FROM stock_order_splits s INNER JOIN stock_orders o ON o.id = s.order_id WHERE $saleWin", $saleParams],
            ["SELECT DISTINCT o.created_by FROM stock_orders o WHERE o.created_at >= ? AND o.created_at < ?", [$start, $end]],
        ];
        foreach ($queries as [$sql, $params]) {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if ((int)$id > 0) {
                    $ids[(int)$id] = true;
                }
            }
        }
        foreach (rh_pos_shift_reversals($pdo, $start, $end) as $rv) {
            if ($rv['actor_id'] > 0) {
                $ids[$rv['actor_id']] = true;
            }
        }
        return array_keys($ids);
    }
}

if (!function_exists('rh_pos_margin_basis')) {
    /**
     * The ONE POS margin basis (End of Day page/PDF/email, Accounting dashboard): sales that stand
     * (paid/completed, so refunded and voided sales are out), measured ex-VAT (F&B prices are gross, VAT is
     * not income), less COGS (paid/completed/refunded orders by paid date). Returns net_ex_vat, margin, pct.
     */
    function rh_pos_margin_basis(float $standingGross, float $cogs): array
    {
        $div = 1.0;
        if (function_exists('getSetting')) {
            // Same switch as vat_mode(): rh_vat_enabled() (accepts 1/true/on/yes in any case).
            $on = function_exists('rh_vat_enabled')
                ? rh_vat_enabled()
                : in_array(strtolower(trim((string)getSetting('vat_enabled'))), ['1', 'true', 'on', 'yes'], true);
            $rate = (float)getSetting('vat_rate');
            if ($on && $rate > 0) {
                $div = 1 + ($rate / 100);
            }
        }
        $net = round($standingGross / $div, 2);
        $margin = round($net - $cogs, 2);
        return ['net_ex_vat' => $net, 'margin' => $margin, 'pct' => $net > 0 ? ($margin / $net) * 100 : 0.0];
    }
}

if (!function_exists('rh_pos_eod_summary')) {
    /**
     * End-of-day POS block for a business date, on the same rules as the till and the ledger:
     * sales are summed by paid_at inside that date's business window (whatever the status is
     * now); voids by voided_at and refunds by refunded_at, each only in their own window.
     * Returns ['totals','by_type','top_items','void_reasons'].
     */
    function rh_pos_eod_summary(PDO $pdo, string $date): array
    {
        require_once __DIR__ . '/../../includes/station-hours.php';
        $w = rh_station_union_window_for_date($date);
        $a = $w['start_sql'];
        $b = $w['trading_end_sql']; // trading day: opening to next opening, no gap (owner 2026-11-10)
        // The Z's own order definition (till sales OR charged to room), so the day's Zs sum to this block.
        // Same rows as the earlier inline rule (paid/completed/refunded/voided by paid_at, paid/completed
        // without paid_at by created_at), now shared.
        $saleCond = rh_pos_z_sale_sql('o');
        $saleParams = rh_pos_z_sale_params($a, $b);
        $hasRefundedAt = rh_pos_col_exists($pdo, 'stock_orders', 'refunded_at');

        $out = [
            'totals' => ['orders' => 0, 'gross' => 0.0, 'cogs' => 0.0, 'net_ex_vat' => 0.0, 'margin' => 0.0, 'margin_pct' => 0.0, 'voided_value' => 0.0, 'voided_count' => 0, 'refunded_value' => 0.0, 'refunded_count' => 0, 'room_charge_count' => 0, 'room_charge_amount' => 0.0],
            'by_type' => [], 'top_items' => [], 'void_reasons' => [],
            'window_start' => $a, 'window_end' => $b,
        ];

        $st = $pdo->prepare("SELECT COUNT(*) AS orders, COALESCE(SUM(o.total_amount),0) AS gross,
                COALESCE(SUM(CASE WHEN o.status IN ('paid','completed') THEN o.total_amount ELSE 0 END),0) AS standing_gross,
                COALESCE(SUM(CASE WHEN o.status IN ('paid','completed','refunded') THEN o.total_cost ELSE 0 END),0) AS cogs
            FROM stock_orders o WHERE $saleCond");
        $st->execute($saleParams);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['totals']['orders'] = (int)($r['orders'] ?? 0);
        $out['totals']['gross'] = (float)($r['gross'] ?? 0);
        $out['totals']['cogs'] = (float)($r['cogs'] ?? 0);
        // Standing sales less money refunded on still-paid orders (partial refunds) settled in this window;
        // orders refunded in full or voided are already out of standing_gross.
        $partial = rh_pos_partial_refunds($pdo, $a, $b);
        $mb = rh_pos_margin_basis((float)($r['standing_gross'] ?? 0) - $partial['amount'], $out['totals']['cogs']);
        $out['totals']['net_ex_vat'] = $mb['net_ex_vat'];
        $out['totals']['margin'] = $mb['margin'];
        $out['totals']['margin_pct'] = $mb['pct'];
        $out['totals']['partial_refund_value'] = $partial['amount'];
        // "Charged to room": inside orders / gross above (on the guest bill, never in a drawer).
        $rc = rh_pos_room_charges($pdo, null, $a, $b);
        $out['totals']['room_charge_count'] = $rc['count'];
        $out['totals']['room_charge_amount'] = $rc['amount'];

        $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(o.total_amount),0) AS v FROM stock_orders o WHERE o.status = 'voided' AND o.voided_at >= ? AND o.voided_at < ?");
        $st->execute([$a, $b]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['totals']['voided_count'] = (int)($r['n'] ?? 0);
        $out['totals']['voided_value'] = (float)($r['v'] ?? 0);

        if ($hasRefundedAt) {
            $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(o.total_amount),0) AS v FROM stock_orders o WHERE o.status = 'refunded' AND o.refunded_at >= ? AND o.refunded_at < ?");
            $st->execute([$a, $b]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['totals']['refunded_count'] = (int)($r['n'] ?? 0);
            $out['totals']['refunded_value'] = (float)($r['v'] ?? 0);
        }
        // "Refunds paid out" also covers partial refunds of orders that are still paid.
        $out['totals']['refunded_count'] += $partial['orders'];
        $out['totals']['refunded_value'] += $partial['amount'];

        $st = $pdo->prepare("SELECT COALESCE(NULLIF(o.order_type,''),'walk_in') AS order_type, COUNT(*) AS cnt,
                COALESCE(SUM(o.total_amount),0) AS gross,
                COALESCE(SUM(CASE WHEN o.status IN ('paid','completed','refunded') THEN o.total_cost ELSE 0 END),0) AS cogs
            FROM stock_orders o WHERE $saleCond GROUP BY order_type ORDER BY gross DESC");
        $st->execute($saleParams);
        $out['by_type'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Top items: sold lines only; voided / 86'd lines never count, and neither do orders since voided or refunded.
        $st = $pdo->prepare("SELECT soi.item_name, soi.menu_type, SUM(soi.quantity) AS qty, SUM(soi.line_total) AS revenue
            FROM stock_order_items soi INNER JOIN stock_orders o ON o.id = soi.order_id
            WHERE $saleCond AND o.status IN ('paid','completed') AND COALESCE(soi.kds_status,'') NOT IN ('void','voided','cancelled','86','86d','86ed')
            GROUP BY soi.item_name, soi.menu_type ORDER BY revenue DESC LIMIT 8");
        $st->execute($saleParams);
        $out['top_items'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(o.void_reason),''),'No reason given') AS reason, COUNT(*) AS cnt, COALESCE(SUM(o.total_amount),0) AS value
            FROM stock_orders o WHERE o.status = 'voided' AND o.voided_at >= ? AND o.voided_at < ? GROUP BY reason ORDER BY cnt DESC LIMIT 5");
        $st->execute([$a, $b]);
        $out['void_reasons'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $out;
    }
}

if (!function_exists('rh_eod_folio_fnb')) {
    /**
     * Food / drink / room-service charges posted to guest folios on a date (non-voided).
     * Department revenue treats these as F&B, not room revenue.
     * Returns ['gross' => line_total sum, 'vat' => vat_amount sum].
     */
    function rh_eod_folio_fnb(PDO $pdo, string $date): array
    {
        try {
            $st = $pdo->prepare("SELECT COALESCE(SUM(line_total),0) AS g, COALESCE(SUM(vat_amount),0) AS v
                FROM booking_charges
                WHERE voided = 0 AND DATE(posted_at) = ?
                  AND charge_type IN ('food','drink','minibar','breakfast','room_service')");
            $st->execute([$date]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            return ['gross' => (float)($r['g'] ?? 0), 'vat' => (float)($r['v'] ?? 0)];
        } catch (Throwable $e) {
            return ['gross' => 0.0, 'vat' => 0.0];
        }
    }
}

if (!function_exists('rh_eod_folio_fnb_allocation')) {
    /**
     * The F&B part of the ROOM payments received on $date, allocated payment by payment (cash basis):
     * for each booking paid today, fnb_share = today's room payments on it x (its non-voided F&B folio
     * charges / its grand total from the same figures getBookingFolioSummary uses), with the VAT moved at
     * the F&B charges' own VAT ratio. Room service posted on day 1 and paid at checkout on day 3 is
     * therefore F&B revenue on day 3 in proportion to what the payment settles, and a deposit for a
     * stay with no F&B charges stays room revenue.
     * Returns ['gross' => moved gross, 'vat' => moved VAT, 'bookings' => bookings with a share].
     */
    function rh_eod_folio_fnb_allocation(PDO $pdo, string $date): array
    {
        $out = ['gross' => 0.0, 'vat' => 0.0, 'bookings' => 0];
        try {
            $st = $pdo->prepare("SELECT booking_id, COALESCE(SUM(total_amount),0) AS pay_gross, COALESCE(SUM(vat_amount),0) AS pay_vat
                FROM payments
                WHERE booking_type = 'room' AND DATE(payment_date) = ? AND deleted_at IS NULL
                  AND payment_status IN ('completed','paid','refunded','partially_refunded')
                  AND COALESCE(payment_type, '') <> 'refund'
                  AND COALESCE(payment_method, '') <> 'credit_note' -- guest credit used, not money received (End of Day cash view)
                GROUP BY booking_id");
            $st->execute([$date]);
            $pays = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$pays) {
                return $out;
            }
            $ids = array_map(static function ($r) { return (int)$r['booking_id']; }, $pays);
            $ph = implode(',', array_fill(0, count($ids), '?'));

            $st = $pdo->prepare("SELECT booking_id,
                       COALESCE(SUM(line_total),0) AS all_total,
                       COALESCE(SUM(CASE WHEN charge_type IN ('food','drink','minibar','breakfast','room_service') THEN line_total ELSE 0 END),0) AS fnb_total,
                       COALESCE(SUM(CASE WHEN charge_type IN ('food','drink','minibar','breakfast','room_service') THEN vat_amount ELSE 0 END),0) AS fnb_vat
                FROM booking_charges WHERE voided = 0 AND booking_id IN ($ph) GROUP BY booking_id");
            $st->execute($ids);
            $folio = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $folio[(int)$r['booking_id']] = $r;
            }
            if (!$folio) {
                return $out;
            }

            $st = $pdo->prepare("SELECT id, status, total_amount, vat_amount, total_with_vat, cancellation_retained_amount FROM bookings WHERE id IN ($ph)");
            $st->execute($ids);
            $bk = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $bk[(int)$r['id']] = $r;
            }

            foreach ($pays as $p) {
                $bid = (int)$p['booking_id'];
                if (empty($folio[$bid]) || empty($bk[$bid]) || (float)$folio[$bid]['fnb_total'] <= 0) {
                    continue;
                }
                $b = $bk[$bid];
                $baseGross = (float)($b['total_with_vat'] ?? 0);
                if ($baseGross <= 0) {
                    $baseGross = (float)($b['total_amount'] ?? 0) + (float)($b['vat_amount'] ?? 0);
                }
                if ((string)$b['status'] === 'cancelled') {
                    $baseGross = max(0.0, (float)($b['cancellation_retained_amount'] ?? 0));
                }
                $grand = $baseGross + (float)$folio[$bid]['all_total'];
                if ($grand <= 0) {
                    continue;
                }
                $payGross = (float)$p['pay_gross'];
                $share = min($payGross, $payGross * ((float)$folio[$bid]['fnb_total'] / $grand));
                if ($share <= 0) {
                    continue;
                }
                $vatRatio = (float)$folio[$bid]['fnb_total'] > 0 ? ((float)$folio[$bid]['fnb_vat'] / (float)$folio[$bid]['fnb_total']) : 0.0;
                $out['gross'] += $share;
                $out['vat'] += min((float)$p['pay_vat'], $share * $vatRatio);
                $out['bookings']++;
            }
            $out['gross'] = round($out['gross'], 2);
            $out['vat'] = round($out['vat'], 2);
        } catch (Throwable $e) {
            error_log('rh_eod_folio_fnb_allocation: ' . $e->getMessage());
            return ['gross' => 0.0, 'vat' => 0.0, 'bookings' => 0];
        }
        return $out;
    }
}
