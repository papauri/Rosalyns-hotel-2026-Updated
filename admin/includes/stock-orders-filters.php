<?php
/**
 * Orders & Tabs (admin/stock-orders.php) list filters - pure SQL-clause builder (no DB).
 *
 * Owner 2026-11-11 "all filters 100% accurate": a card and the list it opens count the same rows.
 * `date=today` means today's TRADING window (opening to next opening, owner 2026-11-10), never the
 * calendar day, and the timestamp depends on what the card measures:
 *  - money (status=sold, or any payment_method): when the sale was PAID - rh_pos_sale_window_sql,
 *    the till / Z-report rule (paid/refunded/voided later still count; legacy paid rows by created_at),
 *    tender read per split leg like the shift totals. status=sold with no tender filter is every order
 *    on the Z: rh_pos_z_sale_sql adds orders charged to a room (owner 2026-11-11), so Settled Today =
 *    the Z order count; a tender list stays till-only (a room charge is never in a drawer);
 *  - voids (status=voided): when it was voided (voided_at, else created_at) - same as the shift totals;
 *  - settled (status=paid/completed/settled): when it was paid (paid_at, else created_at);
 *  - everything else (orders today, open, channel, hour): when the order was started (created_at).
 * `folio=exclude` leaves out room service charged to a guest bill (booking_charges), exactly as the
 * stock dashboard's Net Sales card does (rh_so_folio_exclude_sql is shared by both).
 * `status` may be a comma list of plain statuses (e.g. placed,pending,confirmed) for room-service sets.
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

if (!function_exists('rh_so_folio_exclude_sql')) {
    /**
     * Leaves out room service charged to a guest bill: the bill already counts it. The stock dashboard's
     * Net Sales card and Top Items use this same expression, so the card and its list agree row for row
     * (kept exactly as the card has always read it, NULL order_type included).
     */
    function rh_so_folio_exclude_sql(string $o = 'o'): string
    {
        return "NOT ($o.order_type = 'room_service' AND EXISTS (SELECT 1 FROM booking_charges bc WHERE bc.stock_order_id = $o.id))";
    }
}

if (!function_exists('rh_so_tender_methods')) {
    /** The payment_method values one tender filter covers ('card' = manual + POS + credit_card, as the Card card). [] = none/unassigned. */
    function rh_so_tender_methods(string $m): array
    {
        if ($m === '' || $m === 'unassigned') {
            return [];
        }
        return $m === 'card' ? ['card_manual', 'card_pos', 'credit_card'] : [$m];
    }
}

if (!function_exists('rh_so_tender_leg_sql')) {
    /**
     * For a tender-filtered list: SQL + params summing, per split order, the legs paid by that tender
     * (SUM(split_amount), as the payment mix and shift totals read them) and that part's tips (the tender
     * cards include tips), so each row can show its part and the list adds up to the card.
     * $ids = the split orders shown. Returns null when not needed.
     */
    function rh_so_tender_leg_sql(string $m, array $ids): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if ($m === '' || !$ids) {
            return null;
        }
        $idPh = implode(',', array_fill(0, count($ids), '?'));
        if ($m === 'unassigned') {
            $match = "(s.payment_method IS NULL OR TRIM(s.payment_method) = '')";
            $params = $ids;
        } else {
            $methods = rh_so_tender_methods($m);
            $match = 's.payment_method IN (' . implode(',', array_fill(0, count($methods), '?')) . ')';
            $params = array_merge($ids, $methods);
        }
        return ["SELECT s.order_id, COALESCE(SUM(s.split_amount), 0) AS leg_amount, COALESCE(SUM(COALESCE(s.tip_amount, 0)), 0) AS leg_tip FROM stock_order_splits s"
            . " WHERE s.order_id IN ($idPh) AND $match GROUP BY s.order_id", $params];
    }
}

if (!function_exists('rh_so_ref86_by_method_sql')) {
    /**
     * Items 86'd after payment: the guest paid the full amount, the order total was lowered and a REF86
     * refund paid back the difference. The tender cards (rh_pos_shift_totals) add that paid value to the
     * tender, but no order row shows it, so the cards and lists note it. Same rule and window as the
     * shift totals' REF86 add-back; grouped by payment method. Params: the 4 sale-window params.
     */
    function rh_so_ref86_by_method_sql(): string
    {
        return "SELECT p.payment_method AS method, COUNT(*) AS n,"
            . " COALESCE(SUM(CASE WHEN COALESCE(p.refund_amount, 0) > 0 THEN p.refund_amount ELSE p.total_amount END), 0) AS amt"
            . " FROM payments p INNER JOIN stock_orders o ON o.id = p.booking_id"
            . " WHERE p.booking_type = 'restaurant' AND p.payment_type = 'refund'"
            . " AND p.payment_reference LIKE 'REF86-%' AND p.deleted_at IS NULL"
            . " AND COALESCE(p.refund_status, 'completed') IN ('pending','processing','completed')"
            . " AND " . rh_pos_sale_window_sql('o')
            . " GROUP BY p.payment_method";
    }
}

if (!function_exists('rh_so_ref86_for_tender')) {
    /** From the grouped REF86 rows: [count, amount] for one list tender filter (card = the card bucket). */
    function rh_so_ref86_for_tender(array $rows, string $m): array
    {
        $methods = rh_so_tender_methods($m);
        $n = 0;
        $amt = 0.0;
        foreach ($rows as $r) {
            if (in_array((string)($r['method'] ?? ''), $methods, true)) {
                $n += (int)($r['n'] ?? 0);
                $amt += (float)($r['amt'] ?? 0);
            }
        }
        return [$n, round($amt, 2)];
    }
}

if (!function_exists('rh_so_plain_statuses')) {
    /** Order statuses a list may filter on one by one or as a comma list. */
    function rh_so_plain_statuses(): array
    {
        return ['placed', 'pending', 'confirmed', 'paid', 'completed', 'cancelled', 'voided', 'parked', 'refunded'];
    }
}

if (!function_exists('rh_so_status_list')) {
    /** The statuses of a comma-list filter (two or more plain statuses), else []. */
    function rh_so_status_list(string $status): array
    {
        if (strpos($status, ',') === false) {
            return [];
        }
        return array_values(array_unique(array_map('trim', explode(',', $status))));
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
            'folio' => $s('folio'),
        ];
        if ($f['date'] !== 'today') { $f['date'] = ''; }
        if (preg_match('/^[a-z_]{1,40}$/', $f['payment_method']) !== 1) { $f['payment_method'] = ''; }
        if (preg_match('/^[a-z_]{1,40}$/', $f['order_type']) !== 1) { $f['order_type'] = ''; }
        if (!(ctype_digit($f['hour']) && (int)$f['hour'] >= 0 && (int)$f['hour'] <= 23)) { $f['hour'] = ''; } else { $f['hour'] = (string)(int)$f['hour']; }
        if (strpos($f['status'], ',') !== false) {
            // Comma list (room-service sets such as placed,pending,confirmed): still-open statuses only;
            // paid/void/refund statuses have their own timestamp rules, so a list holding one is dropped whole.
            $list = rh_so_status_list($f['status']);
            $allowed = ['placed', 'pending', 'confirmed', 'parked'];
            $ok = count($list) >= 2;
            foreach ($list as $one) {
                if (!in_array($one, $allowed, true)) { $ok = false; break; }
            }
            $f['status'] = $ok ? implode(',', $list) : '';
        } elseif (!in_array($f['status'], array_merge(rh_so_plain_statuses(), ['settled', 'sold']), true)) {
            $f['status'] = '';
        }
        if ($f['folio'] !== 'exclude') { $f['folio'] = ''; }
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
        // The Z's order list (till sales + charged to room) when no tender narrows it to a drawer tender.
        $zOrders = (($f['status'] ?? '') === 'sold' && ($f['payment_method'] ?? '') === '');
        switch (rh_so_date_basis($f)) {
            case 'sale':
                if ($zOrders) {
                    $c[] = rh_pos_z_sale_sql('o');
                    array_push($p, ...rh_pos_z_sale_params($start, $end));
                } else {
                    $c[] = rh_pos_sale_window_sql('o');
                    array_push($p, ...$win);
                }
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
            $methods = rh_so_tender_methods($m);
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
                $c[] = "((o.paid_at IS NOT NULL AND o.status IN ('paid','refunded','voided')) OR (o.paid_at IS NULL AND o.status = 'paid')"
                    . ($zOrders ? " OR o.status = 'completed'" : '') . ')';
            }
        } elseif (($list = rh_so_status_list($s)) !== []) {
            $c[] = 'o.status IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
            array_push($p, ...$list);
        } elseif ($s !== '') {
            $c[] = 'o.status = ?';
            $p[] = $s;
        }

        if (($f['folio'] ?? '') === 'exclude') {
            $c[] = rh_so_folio_exclude_sql('o');
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

if (!function_exists('rh_so_list_cap')) {
    /** Most orders the list reads in one go (the SQL LIMIT); the notice below says when there are more. */
    function rh_so_list_cap(): int
    {
        return 2000;
    }
}

if (!function_exists('rh_so_cap_notice')) {
    /**
     * '' unless the list was cut at the cap. $read = rows the capped query returned, $total = COUNT of the same
     * filters (-1 = count failed), $shown = rows actually listed (fewer than $read after the Needs Review
     * post-filter). "Showing 2,000 of 2,345 orders ..." / "Showing 37 of 2,345 orders: only the newest 2,000 ...".
     */
    function rh_so_cap_notice(int $read, int $total, ?int $shown = null): string
    {
        $shown = $shown ?? $read;
        if ($total >= 0 && $total <= $read) {
            return '';
        }
        $of = $total >= 0 ? ' of ' . number_format($total) : '';
        $more = $total >= 0 ? '' : ' (there may be more)';
        if ($shown !== $read) {
            return 'Showing ' . number_format($shown) . $of . ' orders' . $more . ': only the newest ' . number_format($read)
                . ' were checked. Narrow the filters to see the rest.';
        }
        return 'Showing ' . number_format($shown) . $of . ' orders (newest first)' . $more . '. Narrow the filters to see the rest.';
    }
}

if (!function_exists('rh_so_has_amount')) {
    /** Is there an amount worth showing: it rounds to at least 0.01 (a display check, not a balance comparison). */
    function rh_so_has_amount(float $x): bool
    {
        return abs(round($x, 2)) >= 0.01;
    }
}

if (!function_exists('rh_so_legs_failed_notice')) {
    /** Shown on a tender list when the split-bill legs could not be read (rows then show the whole order total). */
    function rh_so_legs_failed_notice(): string
    {
        return 'Split-bill details could not be loaded; totals shown in full.';
    }
}
