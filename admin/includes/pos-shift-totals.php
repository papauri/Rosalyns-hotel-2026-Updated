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

if (!function_exists('rh_pos_shift_window_start')) {
    /**
     * A till close's window starts at the previous close's end (closed_at) for that user,
     * so a second close the same business day never re-counts the first close's sales.
     */
    function rh_pos_shift_window_start(PDO $pdo, int $userId, string $windowStart, string $windowEnd): string
    {
        try {
            $st = $pdo->prepare("SELECT MAX(closed_at) FROM stock_shift_closes WHERE user_id = ? AND closed_at >= ? AND closed_at < ?");
            $st->execute([$userId, $windowStart, $windowEnd]);
            $last = $st->fetchColumn();
            if ($last && $last > $windowStart) {
                return (string)$last;
            }
        } catch (Throwable $e) {
            error_log('rh_pos_shift_window_start: ' . $e->getMessage());
        }
        return $windowStart;
    }
}

if (!function_exists('rh_pos_settled_refund_payouts')) {
    /**
     * Second payout source: refund rows (payments.payment_type = 'refund', refund_status
     * 'completed') settled in [start,end) whose order is NOT in a refunded/voided state, so the
     * order-level payout never counts them:
     *  - a partial refund of a still-paid order (non-86 rows), and
     *  - 86 refunds ('REF86-' rows), on any order that was paid.
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
                SELECT p.id AS pid, o.id AS oid, p.payment_method AS method,
                       CASE WHEN COALESCE(p.refund_amount, 0) > 0 THEN p.refund_amount ELSE p.total_amount END AS amt,
                       $at AS event_at,
                       COALESCE(p.recorded_by, o.created_by) AS actor_id
                FROM payments p
                INNER JOIN stock_orders o ON o.id = p.booking_id
                WHERE p.booking_type = 'restaurant' AND p.payment_type = 'refund'
                  AND p.refund_status = 'completed' AND p.deleted_at IS NULL
                  AND o.paid_at IS NOT NULL
                  AND (p.payment_reference LIKE 'REF86-%' OR o.status = 'paid')
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
                'kind' => 'refund',
                'actor_id' => (int)$r['actor_id'],
                'at' => (string)$r['event_at'],
                'cash' => $t['cash'], 'mobile' => $t['mobile'], 'card' => $t['card'],
                'total' => $t['cash'] + $t['mobile'] + $t['card'],
            ];
        }
        return $out;
    }
}

if (!function_exists('rh_pos_shift_reversals')) {
    /**
     * Refund / void payouts of PAID orders whose own timestamp falls in [start,end).
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

        $out = [];
        foreach ($rows as $r) {
            $t = ['cash' => 0.0, 'mobile' => 0.0, 'card' => 0.0];
            $oid = (int)$r['id'];
            if ((int)$r['sc'] > 1 && !empty($legs[$oid])) {
                foreach ($legs[$oid] as $l) {
                    $b = rh_pos_tender_bucket($l['payment_method']);
                    if ($b) {
                        $t[$b] += (float)$l['split_amount'] + (float)$l['tip'];
                    }
                }
            } else {
                $b = rh_pos_tender_bucket($r['payment_method']);
                if ($b) {
                    $t[$b] += (float)$r['total_amount'] + (float)$r['tip'];
                }
            }
            $out[] = [
                'id' => $oid,
                'kind' => $r['status'] === 'refunded' ? 'refund' : 'void',
                'actor_id' => (int)$r['actor_id'],
                'at' => (string)$r['event_at'],
                'cash' => $t['cash'], 'mobile' => $t['mobile'], 'card' => $t['card'],
                'total' => $t['cash'] + $t['mobile'] + $t['card'],
            ];
        }
        return array_merge($out, rh_pos_settled_refund_payouts($pdo, $start, $end));
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

        $net = [];
        foreach (['cash', 'mobile', 'card'] as $k) {
            $net[$k] = round($gross[$k] - $refund[$k], 2);
        }

        // Opening float is cash that sits in the drawer but is not a sale: it only affects the
        // expected DRAWER cash ('drawer_cash'). 'cash' stays net takings so revenue views are unchanged.
        $fl = rh_pos_shift_float($pdo, $userId, $start, $end);

        return [
            'float' => $fl['amount'], 'float_recorded' => $fl['recorded'],
            'drawer_cash' => round($fl['amount'] + $net['cash'], 2),
            'cash' => $net['cash'], 'mobile' => $net['mobile'], 'card' => $net['card'],
            'gross_cash' => round($gross['cash'], 2), 'gross_mobile' => round($gross['mobile'], 2), 'gross_card' => round($gross['card'], 2),
            'refund_cash' => round($refund['cash'], 2), 'refund_mobile' => round($refund['mobile'], 2), 'refund_card' => round($refund['card'], 2),
            'refund_count' => $refundCount,
            'refund_amount' => round($refundTotal, 2),
            'sales_total' => round((float)($m['sales_total'] ?? 0), 2),
            'tips_total' => (float)($m['tips'] ?? 0),
            'orders_count' => (int)($m['n'] ?? 0),
            'settled_from_tabs_count' => (int)($m['tab_n'] ?? 0),
            'settled_from_tabs_amount' => (float)($m['tab_amt'] ?? 0),
            'voids_count' => (int)($v['n'] ?? 0),
            'voids_amount' => (float)($v['amt'] ?? 0),
        ];
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
        $b = $w['end_sql'];
        $saleCond = "((o.paid_at IS NOT NULL AND o.status IN ('paid','completed','refunded','voided') AND o.paid_at >= ? AND o.paid_at < ?)"
            . " OR (o.paid_at IS NULL AND o.status IN ('paid','completed') AND o.created_at >= ? AND o.created_at < ?))";
        $saleParams = [$a, $b, $a, $b];
        $hasRefundedAt = rh_pos_col_exists($pdo, 'stock_orders', 'refunded_at');

        $out = [
            'totals' => ['orders' => 0, 'gross' => 0.0, 'cogs' => 0.0, 'voided_value' => 0.0, 'voided_count' => 0, 'refunded_value' => 0.0, 'refunded_count' => 0],
            'by_type' => [], 'top_items' => [], 'void_reasons' => [],
            'window_start' => $a, 'window_end' => $b,
        ];

        $st = $pdo->prepare("SELECT COUNT(*) AS orders, COALESCE(SUM(o.total_amount),0) AS gross,
                COALESCE(SUM(CASE WHEN o.status IN ('paid','completed','refunded') THEN o.total_cost ELSE 0 END),0) AS cogs
            FROM stock_orders o WHERE $saleCond");
        $st->execute($saleParams);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['totals']['orders'] = (int)($r['orders'] ?? 0);
        $out['totals']['gross'] = (float)($r['gross'] ?? 0);
        $out['totals']['cogs'] = (float)($r['cogs'] ?? 0);

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
