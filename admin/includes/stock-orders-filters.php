<?php
/**
 * Orders & Tabs (admin/stock-orders.php) list filters - pure SQL-clause builder (no DB).
 *
 * Owner 2026-11-11 "all filters 100% accurate": a card and the list it opens count the same rows.
 * `date=today` means today's TRADING window (opening to next opening, owner 2026-11-10), never the
 * calendar day, and the timestamp depends on what the card measures:
 *  - money (status=sold, or any payment_method): when the sale was PAID - rh_pos_sale_window_sql,
 *    the till / Z-report rule (paid/refunded/voided later still count; legacy paid rows by created_at),
 *    tender read per split leg like the shift totals;
 *  - voids (status=voided): when it was voided (voided_at, else created_at) - same as the shift totals;
 *  - settled (status=paid/completed/settled): when it was paid (paid_at, else created_at);
 *  - everything else (orders today, open, channel, hour): when the order was started (created_at).
 * Requires admin/includes/pos-shift-totals.php (rh_pos_sale_window_sql).
 */

if (!function_exists('rh_so_void_window_sql')) {
    /** Voided orders in [start,end): by voided_at, else created_at. 4 params (start,end,start,end). */
    function rh_so_void_window_sql(string $o = 'o'): string
    {
        return "($o.status = 'voided' AND (($o.voided_at IS NOT NULL AND $o.voided_at >= ? AND $o.voided_at < ?)"
            . " OR ($o.voided_at IS NULL AND $o.created_at >= ? AND $o.created_at < ?)))";
    }
}

if (!function_exists('rh_so_order_type_expr')) {
    /** The channel an order is grouped under on the Order Channels panel (blank = restaurant). */
    function rh_so_order_type_expr(string $o = 'o'): string
    {
        return "COALESCE(NULLIF(TRIM($o.order_type), ''), 'restaurant')";
    }
}

if (!function_exists('rh_so_not_room_service_sql')) {
    /** Every order that is not room service (NULL type included) - the Live Open "Walk-in" split. */
    function rh_so_not_room_service_sql(string $o = 'o'): string
    {
        return "COALESCE($o.order_type, '') <> 'room_service'";
    }
}

if (!function_exists('rh_so_kitchen_groups')) {
    function rh_so_kitchen_groups(): array
    {
        return [
            'awaiting_prep'   => ['new', 'pending'],
            'in_progress_all' => ['in_progress', 'preparing'],
            'ready_all'       => ['ready', 'collection'],
        ];
    }
}

if (!function_exists('rh_so_normalize_filters')) {
    /** Read the list filters from $_GET; anything not understood is dropped (never shown as a filter it is not). */
    function rh_so_normalize_filters(array $get): array
    {
        $s = static fn(string $k): string => is_scalar($get[$k] ?? null) ? trim((string)$get[$k]) : '';
        $f = [
            'date' => $s('date'), 'payment_method' => $s('payment_method'), 'order_type' => $s('order_type'),
            'hour' => $s('hour'), 'status' => $s('status'), 'health' => $s('health'), 'kitchen_status' => $s('kitchen_status'),
        ];
        if ($f['date'] !== 'today') { $f['date'] = ''; }
        if (preg_match('/^[a-z_]{1,40}$/', $f['payment_method']) !== 1) { $f['payment_method'] = ''; }
        if (preg_match('/^[a-z_]{1,40}$/', $f['order_type']) !== 1) { $f['order_type'] = ''; }
        if (!(ctype_digit($f['hour']) && (int)$f['hour'] >= 0 && (int)$f['hour'] <= 23)) { $f['hour'] = ''; } else { $f['hour'] = (string)(int)$f['hour']; }
        if (!in_array($f['status'], ['placed', 'paid', 'completed', 'cancelled', 'voided', 'parked', 'refunded', 'settled', 'sold'], true)) { $f['status'] = ''; }
        if ($f['health'] !== 'review') { $f['health'] = ''; }
        $kitchen = ['new', 'pending', 'in_progress', 'preparing', 'ready', 'collection', 'served', 'recalled'];
        if (!isset(rh_so_kitchen_groups()[$f['kitchen_status']]) && !in_array($f['kitchen_status'], $kitchen, true)) { $f['kitchen_status'] = ''; }
        return $f;
    }
}

if (!function_exists('rh_so_date_basis')) {
    /** Which timestamp `date=today` uses for these filters: sale | void | settled | created ('' = no date filter). */
    function rh_so_date_basis(array $f): string
    {
        if (($f['date'] ?? '') !== 'today') {
            return '';
        }
        $status = $f['status'] ?? '';
        if ($status === 'voided') {
            return 'void';
        }
        if ($status === 'sold' || ($f['payment_method'] ?? '') !== '') {
            return 'sale';
        }
        if (in_array($status, ['paid', 'completed', 'settled'], true)) {
            return 'settled';
        }
        return 'created';
    }
}

if (!function_exists('rh_so_list_where')) {
    /**
     * WHERE clauses + params for the orders list. $start/$end = the trading window
     * (rh_station_union_trading_window()['start_sql'], ['trading_end_sql']). Returns [clauses, params].
     */
    function rh_so_list_where(array $f, string $start, string $end): array
    {
        $c = [];
        $p = [];
        $win = [$start, $end, $start, $end];
        switch (rh_so_date_basis($f)) {
            case 'sale':
                $c[] = rh_pos_sale_window_sql('o');
                array_push($p, ...$win);
                break;
            case 'void':
                $c[] = rh_so_void_window_sql('o');
                array_push($p, ...$win);
                break;
            case 'settled':
                $c[] = 'COALESCE(o.paid_at, o.created_at) >= ? AND COALESCE(o.paid_at, o.created_at) < ?';
                array_push($p, $start, $end);
                break;
            case 'created':
                $c[] = 'o.created_at >= ? AND o.created_at < ?';
                array_push($p, $start, $end);
                break;
        }

        // Tender: per order for single payments, per leg for split bills (as the shift totals read it).
        $m = $f['payment_method'] ?? '';
        if ($m === 'unassigned') {
            $c[] = "((COALESCE(o.split_count,1) <= 1 AND (o.payment_method IS NULL OR TRIM(o.payment_method) = ''))"
                . " OR (COALESCE(o.split_count,1) > 1 AND EXISTS (SELECT 1 FROM stock_order_splits sf WHERE sf.order_id = o.id AND (sf.payment_method IS NULL OR TRIM(sf.payment_method) = ''))))";
        } elseif ($m !== '') {
            $methods = $m === 'card' ? ['card_manual', 'card_pos', 'credit_card'] : [$m];
            $ph = implode(',', array_fill(0, count($methods), '?'));
            $c[] = "((COALESCE(o.split_count,1) <= 1 AND o.payment_method IN ($ph))"
                . " OR (COALESCE(o.split_count,1) > 1 AND EXISTS (SELECT 1 FROM stock_order_splits sf WHERE sf.order_id = o.id AND sf.payment_method IN ($ph))))";
            array_push($p, ...$methods, ...$methods);
        }

        $t = $f['order_type'] ?? '';
        if ($t === 'non_room_service') {
            $c[] = rh_so_not_room_service_sql('o');
        } elseif ($t !== '') {
            $c[] = rh_so_order_type_expr('o') . ' = ?';
            $p[] = $t;
        }

        if (($f['hour'] ?? '') !== '') {
            $c[] = 'HOUR(o.created_at) = ?';
            $p[] = (int)$f['hour'];
        }

        $s = $f['status'] ?? '';
        if ($s === 'settled') {
            $c[] = "o.status IN ('paid','completed')";
        } elseif ($s === 'sold') {
            // Without a window: every order that was sold (any later status), same shape as the sale rule.
            if (rh_so_date_basis($f) !== 'sale') {
                $c[] = "((o.paid_at IS NOT NULL AND o.status IN ('paid','refunded','voided')) OR (o.paid_at IS NULL AND o.status = 'paid'))";
            }
        } elseif ($s !== '') {
            $c[] = 'o.status = ?';
            $p[] = $s;
        }

        $k = $f['kitchen_status'] ?? '';
        $groups = rh_so_kitchen_groups();
        if (isset($groups[$k])) {
            $c[] = 'o.kitchen_status IN (' . implode(',', array_fill(0, count($groups[$k]), '?')) . ')';
            array_push($p, ...$groups[$k]);
        } elseif ($k !== '') {
            $c[] = 'o.kitchen_status = ?';
            $p[] = $k;
        }

        return [$c, $p];
    }
}
