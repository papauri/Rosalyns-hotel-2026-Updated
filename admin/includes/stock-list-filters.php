<?php
/**
 * List filter rules for the stock screens that dashboards link into (pure: no database, no settings).
 * Each filter uses the SAME condition as the count that links to it, so the list always adds up to it:
 *   stock-batches.php?filter=expiring  -> dashboard "Expiring batches (<= 7 days)" / "Expiring 7d"
 *   stock-batches.php?filter=expired   -> dashboard "Expired active batches" / stock dashboard "Expired Batches Still Active"
 *   stock-ingredients.php?filter=low   -> rh_stock_alert_summary()['attention'] (out + low; never-stocked add 0)
 * The batch list LEFT JOINs the ingredient (the dashboard counts read stock_batches alone), so list == count.
 * CURDATE() is the hotel date (config/database.php sets the session time_zone to the hotel's).
 * Tested by scripts/tests/stock_list_filters.php.
 */

if (!function_exists('rh_stock_batch_list_filters')) {
    /** Dashboard filters stock-batches.php accepts (value => wording used in the notice). */
    function rh_stock_batch_list_filters(): array
    {
        return [
            'expiring' => 'expiring within 7 days (today up to 7 days from now, active with stock left)',
            'expired'  => 'past their expiry date with stock left',
        ];
    }
}

if (!function_exists('rh_stock_batch_filter_key')) {
    /** Whitelisted ?filter= value for the batches page, or '' (unknown values and arrays are dropped). */
    function rh_stock_batch_filter_key($v): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';
        return array_key_exists($v, rh_stock_batch_list_filters()) ? $v : '';
    }
}

if (!function_exists('rh_stock_batch_alias')) {
    function rh_stock_batch_alias(string $alias): string
    {
        $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
        return $a !== '' ? $a . '.' : '';
    }
}

if (!function_exists('rh_stock_batch_expiring_sql')) {
    /** Active batches with stock left expiring today .. today + 7 (the dashboards' "Expiring 7d" count). */
    function rh_stock_batch_expiring_sql(string $alias = 'b'): string
    {
        $p = rh_stock_batch_alias($alias);
        return "{$p}status = 'active' AND {$p}quantity_remaining > 0 AND {$p}expiry_date IS NOT NULL"
            . " AND {$p}expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    }
}

if (!function_exists('rh_stock_batch_expired_sql')) {
    /** Active batches with stock left whose expiry date has passed (the dashboards' "Expired" count). */
    function rh_stock_batch_expired_sql(string $alias = 'b'): string
    {
        $p = rh_stock_batch_alias($alias);
        return "{$p}status = 'active' AND {$p}quantity_remaining > 0 AND {$p}expiry_date IS NOT NULL"
            . " AND {$p}expiry_date < CURDATE()";
    }
}

if (!function_exists('rh_stock_expired_snapshot')) {
    /**
     * Batch ids the ?filter=expired list shows. Opening stock-batches.php runs the expiry sweep, which
     * writes these batches off (status 'expired', nothing left), so the ids are read with the dashboard's
     * condition BEFORE the sweep. Arriving from a dashboard link takes the fresh read only (even when empty),
     * so the list never contradicts a dashboard that now says 0. Moving within the list (next page, picking
     * an ingredient: $reuse = true) keeps the same day's stored ids and adds any fresh ones.
     *
     * @param mixed $stored  what was kept in the session: ['date' => Y-m-d, 'ids' => int[]] or null
     * @return int[]
     */
    function rh_stock_expired_snapshot($stored, string $today, bool $reuse, array $freshIds): array
    {
        $out = [];
        $add = static function (array $ids) use (&$out): void {
            foreach ($ids as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $n = (int)$id;
                    if ($n > 0) $out[$n] = $n;
                }
            }
        };
        if ($reuse && is_array($stored) && ($stored['date'] ?? null) === $today && is_array($stored['ids'] ?? null)) {
            $add($stored['ids']);
        }
        $add($freshIds);
        ksort($out);
        return array_values($out);
    }
}

if (!function_exists('rh_stock_id_in_sql')) {
    /** "col IN (?,?,..)" for a list of ids, or a clause that matches nothing when the list is empty. */
    function rh_stock_id_in_sql(string $column, array $ids): string
    {
        $col = preg_replace('/[^A-Za-z0-9_.]/', '', $column);
        return $ids ? $col . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' : '0 = 1';
    }
}

if (!function_exists('rh_stock_batch_list_cap')) {
    /** Most batch rows stock-batches.php loads at once (soonest expiry first); a COUNT gives the true total past it. */
    function rh_stock_batch_list_cap(): int
    {
        return 2000;
    }
}

if (!function_exists('rh_stock_batch_list_total')) {
    /**
     * How many batches match: the rows loaded, or the COUNT when the cap was reached (a failed COUNT, null,
     * falls back to the rows loaded; a COUNT below the rows loaded is never believed).
     */
    function rh_stock_batch_list_total(int $loaded, ?int $counted): int
    {
        $loaded = max(0, $loaded);
        if ($loaded < rh_stock_batch_list_cap() || $counted === null) return $loaded;
        return max($loaded, $counted);
    }
}

if (!function_exists('rh_stock_batch_capped_text')) {
    /** '' when every matching batch is listed, else "Showing 2,000 of 2,345 batches ..." (plain text, escape on output). */
    function rh_stock_batch_capped_text(int $loaded, int $total): string
    {
        if ($total <= $loaded) return '';
        return 'Showing ' . number_format($loaded) . ' of ' . number_format($total) . ' batches: the '
            . number_format($loaded) . ' with the soonest expiry. Pick an ingredient, status or expiry range to see the rest.';
    }
}

if (!function_exists('rh_stock_ingredient_filter_key')) {
    /** Whitelisted ?filter= value for the ingredients page ('low' or ''). */
    function rh_stock_ingredient_filter_key($v): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';
        return $v === 'low' ? 'low' : '';
    }
}

if (!function_exists('rh_stock_attention_parts')) {
    /**
     * The two halves of rh_stock_alert_summary()'s 'attention' count, written exactly as that function
     * writes them. $thr = rh_stock_threshold_sql($alias), $hist = rh_stock_history_sql($pdo, $alias).
     *   out : has stock history and nothing on hand
     *   low : something on hand, a threshold set, and at or below it
     * @return array{out:string,low:string}
     */
    function rh_stock_attention_parts(string $thr, string $hist, string $alias = 'i'): array
    {
        $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
        return [
            'out' => "{$a}.current_quantity <= 0.0001 AND {$hist}",
            'low' => "{$a}.current_quantity > 0.0001 AND {$thr} > 0 AND {$a}.current_quantity <= {$thr}",
        ];
    }
}

if (!function_exists('rh_stock_attention_sql')) {
    /** Ingredients the low-stock alert counts: not archived and (out or low). Same rule as 'attention'. */
    function rh_stock_attention_sql(string $thr, string $hist, string $alias = 'i'): string
    {
        $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
        $p = rh_stock_attention_parts($thr, $hist, $a);
        return "({$a}.is_archived = 0 AND (({$p['out']}) OR ({$p['low']})))";
    }
}
