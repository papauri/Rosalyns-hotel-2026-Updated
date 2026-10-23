<?php

/**
 * Stock budget (open-to-buy) - all logic lives here; pages only call these functions.
 *
 * Per month, per ingredient category (plus an optional whole-stock cap):
 *   Budget     a fixed amount, or a target % of EXPECTED F&B sales for the month (see rh_sbud_expected_sales)
 *   Spent      goods received into stock in the month (stock_in_log.cost_total, hotel timezone)
 *   Committed  purchase orders sent / part-received but not yet received:
 *              sum of max(ordered - received, 0) x unit_cost, whatever the PO date. Counted against the
 *              CURRENT month only (it is money already promised); past and future months show none.
 *   Left       Budget - Spent - Committed
 * Pace compares the share of the budget used with the share of the month gone, and the month-end forecast
 * is the current run rate (never less than spent + committed). Each line has a control: "warn" (confirm to
 * go over) or "approval" (only a user with stock_budget_manage can send a PO that goes over, with a reason).
 *
 * Amounts are stock COST values exactly as stored (goods-in cost, PO unit cost) - not sales prices.
 * Target-% mode uses rh_eod_earned_revenue()['fnb_gross'] per day (the End-of-Day F&B figure) taken BEFORE VAT
 * (rh_sbud_ex_vat(): food and beverage cost % is measured on sales without VAT). F&B carries no tourism levy.
 * A target-% line with no sales to measure against yet is "no sales basis yet": it has no budget figure, never
 * shows as over and never blocks or warns a PO send.
 *
 * Schema is additive and self-healing (same pattern as procurement-schema.php): rh_sbud_ensure_schema().
 * Dates use the hotel timezone (PHP default timezone and MySQL session are both set to RH_TIMEZONE).
 * Money comparisons use BALANCE_TOLERANCE.
 */

require_once __DIR__ . '/procurement-schema.php';

if (!defined('BALANCE_TOLERANCE')) {
    define('BALANCE_TOLERANCE', 0.01);
}

/** Category key of the optional whole-stock cap line. */
const RH_SBUD_ALL = '*';

/** Longest receipts / open-orders lists the detail panel returns (the totals on the page always include everything). */
const RH_SBUD_DETAIL_RECEIPTS = 200;
const RH_SBUD_DETAIL_ORDERS = 100;

/** Days of the month that must have gone before pace is trusted: forecasts, and projecting this month onto the next. */
const RH_SBUD_MIN_PACE_DAYS = 7.0;

if (!function_exists('rh_sbud_ensure_schema')) {
    function rh_sbud_ensure_schema(PDO $pdo): bool
    {
        static $done = null;
        if ($done !== null) {
            return $done;
        }
        try {
            ensureProcurementSchema($pdo);
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS stock_budgets (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    budget_month DATE NOT NULL,
                    category VARCHAR(100) NOT NULL,
                    mode ENUM('fixed','target_pct') NOT NULL DEFAULT 'fixed',
                    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                    target_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                    control ENUM('warn','approval') NOT NULL DEFAULT 'warn',
                    updated_by INT UNSIGNED DEFAULT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_budget_line (budget_month, category)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS stock_budget_audit (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    budget_month DATE NOT NULL,
                    category VARCHAR(100) DEFAULT NULL,
                    action VARCHAR(30) NOT NULL,
                    source VARCHAR(20) DEFAULT NULL,
                    old_value VARCHAR(255) DEFAULT NULL,
                    new_value VARCHAR(255) DEFAULT NULL,
                    purchase_order_id INT UNSIGNED DEFAULT NULL,
                    reason VARCHAR(500) DEFAULT NULL,
                    user_id INT UNSIGNED DEFAULT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_sba_month (budget_month),
                    KEY idx_sba_po (purchase_order_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            // Receipts of PO lines with no ingredient link (non-stock goods): they write no stock_in_log
            // row, so without this they would leave "Already ordered" without ever showing as "Received".
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS stock_nonstock_receipts (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    purchase_order_id INT UNSIGNED NOT NULL,
                    po_item_id INT UNSIGNED NOT NULL,
                    description VARCHAR(255) DEFAULT NULL,
                    quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
                    unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
                    cost_total DECIMAL(14,2) NOT NULL DEFAULT 0,
                    received_by INT UNSIGNED DEFAULT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_snr_created (created_at),
                    KEY idx_snr_po (purchase_order_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            rh_add_column($pdo, 'stock_purchase_orders', 'budget_override_reason', "VARCHAR(500) DEFAULT NULL");
            rh_add_column($pdo, 'stock_purchase_orders', 'budget_override_by', "INT UNSIGNED DEFAULT NULL");
            rh_add_column($pdo, 'stock_purchase_orders', 'budget_override_at', "TIMESTAMP NULL DEFAULT NULL");
            $done = true;
        } catch (Throwable $e) {
            error_log('rh_sbud_ensure_schema error: ' . $e->getMessage());
            $done = false;
        }
        return $done;
    }
}

// ── Period maths (hotel timezone) ───────────────────────────────────────────────

if (!function_exists('rh_sbud_now')) {
    function rh_sbud_now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    }
}

if (!function_exists('rh_sbud_month')) {
    /** A valid YYYY-MM, else the month of $now. */
    function rh_sbud_month($raw, ?DateTimeImmutable $now = null): string
    {
        $raw = trim((string)$raw);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw) && (int)substr($raw, 0, 4) >= 2000 && (int)substr($raw, 0, 4) <= 2100) {
            return $raw;
        }
        return ($now ?: rh_sbud_now())->format('Y-m');
    }
}

if (!function_exists('rh_sbud_shift_month')) {
    function rh_sbud_shift_month(string $ym, int $delta): string
    {
        $d = new DateTimeImmutable($ym . '-01 00:00:00');
        return $d->modify(($delta >= 0 ? '+' : '') . $delta . ' month')->format('Y-m');
    }
}

if (!function_exists('rh_sbud_month_info')) {
    /**
     * @return array{ym:string,start:string,end:string,first:string,last:string,days:int,state:string,
     *               days_done:float,month_pct:float,label:string}
     *  state: current | past | future. days_done = whole days gone plus the part of today (current month).
     */
    function rh_sbud_month_info(string $ym, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: rh_sbud_now();
        $start = new DateTimeImmutable($ym . '-01 00:00:00', $now->getTimezone());
        $next = $start->modify('+1 month');
        $days = (int)$start->format('t');
        $curYm = $now->format('Y-m');
        if ($ym === $curYm) {
            $state = 'current';
            $secs = ((int)$now->format('G') * 3600) + ((int)$now->format('i') * 60) + (int)$now->format('s');
            $daysDone = ((int)$now->format('j') - 1) + ($secs / 86400);
        } elseif ($ym < $curYm) {
            $state = 'past';
            $daysDone = (float)$days;
        } else {
            $state = 'future';
            $daysDone = 0.0;
        }
        return [
            'ym' => $ym,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $next->format('Y-m-d H:i:s'),
            'first' => $start->format('Y-m-d'),
            'last' => $next->modify('-1 day')->format('Y-m-d'),
            'days' => $days,
            'state' => $state,
            'days_done' => $daysDone,
            'month_pct' => $days > 0 ? min(100.0, max(0.0, $daysDone / $days * 100)) : 0.0,
            'label' => $start->format('F Y'),
        ];
    }
}

// ── Raw figures ─────────────────────────────────────────────────────────────────

if (!function_exists('rh_sbud_cat_key')) {
    function rh_sbud_cat_key(?string $category): string
    {
        return mb_strtolower(trim((string)$category));
    }
}

if (!function_exists('rh_sbud_spent_by_category')) {
    /** Goods-in value received in the month, keyed by lower-cased category ('' = uncategorised). */
    function rh_sbud_spent_by_category(PDO $pdo, string $ym, ?DateTimeImmutable $now = null): array
    {
        $info = rh_sbud_month_info($ym, $now);
        $st = $pdo->prepare("
            SELECT TRIM(COALESCE(i.category, '')) AS cat, COALESCE(SUM(l.cost_total), 0) AS v
            FROM stock_in_log l
            LEFT JOIN stock_ingredients i ON i.id = l.ingredient_id
            WHERE l.created_at >= ? AND l.created_at < ?
            GROUP BY TRIM(COALESCE(i.category, ''))
        ");
        $st->execute([$info['start'], $info['end']]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = rh_sbud_cat_key($r['cat']);
            $out[$k] = ($out[$k] ?? 0.0) + (float)$r['v'];
        }
        // Non-stock PO lines received in the month count as uncategorised spend.
        try {
            $ns = $pdo->prepare("SELECT COALESCE(SUM(cost_total), 0) FROM stock_nonstock_receipts WHERE created_at >= ? AND created_at < ?");
            $ns->execute([$info['start'], $info['end']]);
            $nsv = (float)$ns->fetchColumn();
            if ($nsv > 0) {
                $out[''] = ($out[''] ?? 0.0) + $nsv;
            }
        } catch (Throwable $e) {
            // table not provisioned yet: nothing received this way
        }
        return $out;
    }
}

if (!function_exists('rh_sbud_record_nonstock_receipt')) {
    /** Book a received non-stock PO line (no ingredient) so the budget shows it as received. Call inside the receive transaction. */
    function rh_sbud_record_nonstock_receipt(PDO $pdo, int $poId, int $itemId, string $description, float $qty, float $unitCost, ?int $userId): void
    {
        $pdo->prepare("INSERT INTO stock_nonstock_receipts (purchase_order_id, po_item_id, description, quantity, unit_cost, cost_total, received_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$poId, $itemId, mb_substr($description, 0, 255), $qty, $unitCost, round($qty * $unitCost, 2), $userId && $userId > 0 ? $userId : null]);
    }
}

if (!function_exists('rh_sbud_po_fully_received')) {
    /**
     * Has EVERY line of the PO arrived in full? Decided line by line: an over-delivered line must not make up for
     * a short one (that would drop the short line's committed value from the budget while it is still owed).
     * Quantity tolerance 0.0001 (stock quantities are DECIMAL; never raw float equality). A PO with no
     * ordered quantity is not "received".
     */
    function rh_sbud_po_fully_received(PDO $pdo, int $poId): bool
    {
        $st = $pdo->prepare("
            SELECT SUM(CASE WHEN ordered_qty > 0 THEN 1 ELSE 0 END) AS n,
                   SUM(CASE WHEN ordered_qty > 0 AND received_qty + 0.0001 < ordered_qty THEN 1 ELSE 0 END) AS short_lines
            FROM stock_purchase_order_items WHERE purchase_order_id = ?");
        $st->execute([$poId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return (int)($r['n'] ?? 0) > 0 && (int)($r['short_lines'] ?? 0) === 0;
    }
}

if (!function_exists('rh_sbud_committed_by_category')) {
    /** Outstanding value of sent / part-received POs, keyed like spent. $excludePoId leaves one PO out. */
    function rh_sbud_committed_by_category(PDO $pdo, int $excludePoId = 0): array
    {
        $st = $pdo->prepare("
            SELECT TRIM(COALESCE(i.category, '')) AS cat,
                   COALESCE(SUM(GREATEST(poi.ordered_qty - poi.received_qty, 0) * poi.unit_cost), 0) AS v
            FROM stock_purchase_order_items poi
            INNER JOIN stock_purchase_orders po ON po.id = poi.purchase_order_id
            LEFT JOIN stock_ingredients i ON i.id = poi.ingredient_id
            WHERE po.status IN ('sent','partial') AND po.id <> ?
            GROUP BY TRIM(COALESCE(i.category, ''))
        ");
        $st->execute([$excludePoId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = rh_sbud_cat_key($r['cat']);
            $out[$k] = ($out[$k] ?? 0.0) + (float)$r['v'];
        }
        return $out;
    }
}

if (!function_exists('rh_sbud_sales_provider')) {
    /** Test hook: pass a callable (PDO, ym, lastDayInclusive) => float to replace the real F&B figure; null restores. */
    function rh_sbud_sales_provider($set = false)
    {
        static $fn = null;
        if ($set !== false) {
            $fn = $set;
        }
        return $fn;
    }
}

if (!function_exists('rh_sbud_ex_vat')) {
    /**
     * An F&B gross amount (till / folio prices include VAT whatever the VAT pricing mode) taken before VAT.
     * Uses the shared POS ex-VAT basis (rh_pos_margin_basis): "No VAT" leaves the amount as is; both
     * "prices include VAT" and "VAT added on top" extract VAT at the configured rate.
     */
    function rh_sbud_ex_vat(float $gross): float
    {
        // Negative days (refunds/voids bigger than sales) come out of VAT too, exactly as the End-of-Day margin does;
        // only a zero amount, or "No VAT", is left alone.
        if (abs($gross) < 0.005 || !function_exists('vat_mode') || vat_mode() === 'off') {
            return round($gross, 2);
        }
        require_once __DIR__ . '/pos-shift-totals.php';
        return (float)rh_pos_margin_basis($gross, 0.0)['net_ex_vat'];
    }
}

if (!function_exists('rh_sbud_fnb_sales')) {
    /**
     * F&B sales of the month up to and including the last elapsed day, BEFORE VAT: the SAME daily figure the
     * End-of-Day report uses (rh_eod_earned_revenue()['fnb_gross']) with VAT taken out of each day (rh_sbud_ex_vat).
     * A test provider (rh_sbud_sales_provider) returns the figure already before VAT.
     */
    function rh_sbud_fnb_sales(PDO $pdo, string $ym, ?DateTimeImmutable $now = null): float
    {
        $info = rh_sbud_month_info($ym, $now);
        if ($info['state'] === 'future') {
            return 0.0;
        }
        $lastDay = $info['state'] === 'past' ? $info['last'] : ($now ?: rh_sbud_now())->format('Y-m-d');
        $fn = rh_sbud_sales_provider();
        if (is_callable($fn)) {
            return (float)$fn($pdo, $ym, $lastDay);
        }
        static $memo = [];
        $key = $ym . '|' . $lastDay;
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        require_once __DIR__ . '/eod-revenue.php';
        $total = 0.0;
        try {
            for ($t = strtotime($info['first']); $t <= strtotime($lastDay); $t = strtotime('+1 day', $t)) {
                $r = rh_eod_earned_revenue($pdo, date('Y-m-d', $t));
                $total += rh_sbud_ex_vat((float)($r['fnb_gross'] ?? 0));
            }
        } catch (Throwable $e) {
            error_log('rh_sbud_fnb_sales: ' . $e->getMessage());
        }
        return $memo[$key] = round($total, 2);
    }
}

if (!function_exists('rh_sbud_expected_sales')) {
    /**
     * F&B sales (before VAT) a target-% budget is a share of (whole month):
     *  - past month: its actual total (none when nothing was sold)                          basis 'actual'
     *  - current month once 7 days have gone and there are sales: so far / days elapsed x days in month   basis 'month'
     *  - current month in its first 7 days, or no sales yet this month: LAST full month's total          basis 'last_month'
     *  - next month: this month projected to the full month once 7 days have gone and there
     *    are sales (same pace maths as 'month'); before that the last full month             basis 'projected' / 'last_month'
     *  - two or more months ahead: the last full month's total                              basis 'last_month'
     *  - nothing to go on at all                                                            basis 'none' (has_basis false)
     * @return array{so_far:float,expected:float,basis:string,last_month:float,has_basis:bool}
     */
    function rh_sbud_expected_sales(PDO $pdo, string $ym, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: rh_sbud_now();
        $info = rh_sbud_month_info($ym, $now);
        $so = rh_sbud_fnb_sales($pdo, $ym, $now);
        $make = static function (float $expected, string $basis, float $last) use ($so): array {
            return ['so_far' => $so, 'expected' => $basis === 'none' ? 0.0 : $expected, 'basis' => $basis, 'last_month' => $last, 'has_basis' => $basis !== 'none'];
        };
        if ($info['state'] === 'past') {
            return $so > BALANCE_TOLERANCE ? $make($so, 'actual', $so) : $make(0.0, 'none', 0.0);
        }
        $curYm = $now->format('Y-m');
        if ($info['state'] === 'current' && $info['days_done'] >= RH_SBUD_MIN_PACE_DAYS && $so > BALANCE_TOLERANCE) {
            return $make(round($so / ($info['days_done'] / $info['days']), 2), 'month', 0.0);
        }
        if ($info['state'] === 'future' && $ym === rh_sbud_shift_month($curYm, 1)) {
            // Next month: project the current month to its full length (needs 7 days of pace).
            $curInfo = rh_sbud_month_info($curYm, $now);
            $curSo = rh_sbud_fnb_sales($pdo, $curYm, $now);
            if ($curInfo['days_done'] >= RH_SBUD_MIN_PACE_DAYS && $curSo > BALANCE_TOLERANCE) {
                return $make(round($curSo / ($curInfo['days_done'] / $curInfo['days']), 2), 'projected', 0.0);
            }
        }
        // Last FULL month (the month before the current one) - for a current month this is the month before it too.
        $lastYm = $info['state'] === 'current' ? rh_sbud_shift_month($ym, -1) : rh_sbud_shift_month($curYm, -1);
        $last = rh_sbud_fnb_sales($pdo, $lastYm, $now);
        return $last > BALANCE_TOLERANCE ? $make($last, 'last_month', $last) : $make(0.0, 'none', 0.0);
    }
}

if (!function_exists('rh_sbud_sales_basis_text')) {
    /** Plain-words basis for a target-% line, e.g. "30% of expected sales before VAT (MWK 1,000,000) - based on this month so far". */
    function rh_sbud_sales_basis_text(float $pct, array $sales, string $cur = ''): string
    {
        $p = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
        if (empty($sales['has_basis'])) {
            return $p . '% of sales before VAT - no sales basis yet (there are no sales to measure it against)';
        }
        $how = ['month' => 'based on this month so far', 'projected' => 'based on this month\'s sales so far, projected to the full month',
                'last_month' => 'based on last month', 'actual' => 'based on what was actually sold that month'][$sales['basis']] ?? '';
        return $p . '% of expected sales before VAT (' . rh_sbud_money((float)$sales['expected'], $cur) . ') - ' . $how;
    }
}

if (!function_exists('rh_sbud_categories')) {
    /** Distinct non-empty ingredient categories (display case, A-Z). */
    function rh_sbud_categories(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT DISTINCT TRIM(category) AS c FROM stock_ingredients WHERE TRIM(COALESCE(category,'')) <> '' ORDER BY c")->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_map('strval', $rows));
    }
}

if (!function_exists('rh_sbud_lines')) {
    /** Saved budget lines for a month. */
    function rh_sbud_lines(PDO $pdo, string $ym): array
    {
        $st = $pdo->prepare("SELECT * FROM stock_budgets WHERE budget_month = ? ORDER BY (category = '*') DESC, category");
        $st->execute([$ym . '-01']);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── The numbers ─────────────────────────────────────────────────────────────────

if (!function_exists('rh_sbud_line_status')) {
    /** ok | ahead | near | over | none, from used (spent+committed), budget and the forecast. */
    function rh_sbud_line_status(float $budget, float $used, ?float $forecast, ?float $forecastBudget): string
    {
        if ($budget <= BALANCE_TOLERANCE) {
            return $used > BALANCE_TOLERANCE ? 'over' : 'none';
        }
        if ($used > $budget + BALANCE_TOLERANCE) {
            return 'over';
        }
        if ($used >= $budget * 0.8) {
            return 'near';
        }
        if ($forecast !== null && $forecastBudget !== null && $forecast > $forecastBudget + BALANCE_TOLERANCE) {
            return 'ahead';
        }
        return 'ok';
    }
}

if (!function_exists('rh_sbud_forecast')) {
    /** Month-end spend at the current rate; null when it is too early (under 7 days of pace) or not the current month. */
    function rh_sbud_forecast(array $info, float $spent, float $committed): ?float
    {
        if ($info['state'] === 'past') {
            return $spent;
        }
        if ($info['state'] !== 'current' || $info['days_done'] < RH_SBUD_MIN_PACE_DAYS) {
            return null;
        }
        $run = $spent / ($info['days_done'] / $info['days']);
        return max($run, $spent + $committed);
    }
}

if (!function_exists('rh_sbud_compute')) {
    /**
     * Everything the page, the PO check, the dashboard and the buying page need for one month.
     *
     * @param array $opt now (DateTimeImmutable), exclude_po (int: leave that PO out of "committed")
     * @return array{ym:string,info:array,lines:array,other:array,total:array,summary:array,sales:?array,has_budget:bool}
     */
    function rh_sbud_compute(PDO $pdo, string $ym, array $opt = []): array
    {
        $now = $opt['now'] ?? rh_sbud_now();
        $info = rh_sbud_month_info($ym, $now);
        $saved = rh_sbud_lines($pdo, $ym);
        $spentBy = rh_sbud_spent_by_category($pdo, $ym, $now);
        // Committed is money already promised: it counts against the current month only.
        $commBy = $info['state'] === 'current' ? rh_sbud_committed_by_category($pdo, (int)($opt['exclude_po'] ?? 0)) : [];
        $totalSpent = array_sum($spentBy);
        $totalComm = array_sum($commBy);

        // F&B sales are only fetched when a target-% line exists.
        $sales = null;
        foreach ($saved as $r) {
            if ($r['mode'] === 'target_pct') {
                $sales = rh_sbud_expected_sales($pdo, $ym, $now);
                break;
            }
        }

        $lines = [];
        $coveredKeys = [];
        foreach ($saved as $r) {
            $isAll = $r['category'] === RH_SBUD_ALL;
            $key = $isAll ? RH_SBUD_ALL : rh_sbud_cat_key($r['category']);
            if (!$isAll) {
                $coveredKeys[$key] = true;
            }
            $spent = $isAll ? $totalSpent : (float)($spentBy[$key] ?? 0.0);
            $comm = $isAll ? $totalComm : (float)($commBy[$key] ?? 0.0);
            $noBasis = false;
            if ($r['mode'] === 'target_pct') {
                $pct = (float)$r['target_pct'];
                // Budget = target % of EXPECTED sales for the whole month (see rh_sbud_expected_sales()).
                // No sales to measure against yet = "no sales basis yet", not a zero budget.
                $noBasis = empty($sales['has_basis']);
                $budget = $noBasis ? 0.0 : round($pct / 100 * (float)$sales['expected'], 2);
                $fBudget = $budget;
            } else {
                $budget = (float)$r['amount'];
                $fBudget = $budget;
            }
            $used = $spent + $comm;
            $forecast = $noBasis ? null : rh_sbud_forecast($info, $spent, $comm);
            $lines[] = [
                'category' => $r['category'],
                'label' => $isAll ? 'Whole stock' : $r['category'],
                'is_all' => $isAll,
                'mode' => $r['mode'],
                'amount' => (float)$r['amount'],
                'target_pct' => (float)$r['target_pct'],
                'control' => $r['control'],
                'budget' => $budget,
                'spent' => $spent,
                'committed' => $comm,
                'used' => $used,
                'left' => $noBasis ? 0.0 : $budget - $used,
                'pct_used' => $noBasis ? 0.0 : ($budget > BALANCE_TOLERANCE ? $used / $budget * 100 : ($used > BALANCE_TOLERANCE ? 100.0 : 0.0)),
                'forecast' => $forecast,
                'forecast_budget' => $fBudget,
                'no_basis' => $noBasis,
                'status' => $noBasis ? 'nobasis' : rh_sbud_line_status($budget, $used, $forecast, $fBudget),
            ];
        }

        // Spend in categories with no budget line of their own (uncategorised ingredients included).
        $otherSpent = 0.0;
        $otherComm = 0.0;
        foreach ($spentBy as $k => $v) {
            if (!isset($coveredKeys[$k])) {
                $otherSpent += $v;
            }
        }
        foreach ($commBy as $k => $v) {
            if (!isset($coveredKeys[$k])) {
                $otherComm += $v;
            }
        }

        // Summary: the whole-stock cap when there is one, otherwise the category lines added up.
        $allLine = null;
        $cat = ['budget' => 0.0, 'spent' => 0.0, 'committed' => 0.0, 'forecast' => 0.0, 'fbudget' => 0.0, 'fc_ok' => true];
        $usable = 0;
        $unbased = 0;
        $nbSpent = 0.0;
        $nbComm = 0.0;
        foreach ($lines as $l) {
            if ($l['no_basis']) {
                // No budget figure yet: left out of Budget, Left and status so it can never look over,
                // but its real spend is still tracked so Received / Already ordered stay true.
                $unbased++;
                if (!$l['is_all']) {
                    $nbSpent += $l['spent'];
                    $nbComm += $l['committed'];
                }
                continue;
            }
            $usable++;
            if ($l['is_all']) {
                $allLine = $l;
                continue;
            }
            $cat['budget'] += $l['budget'];
            $cat['spent'] += $l['spent'];
            $cat['committed'] += $l['committed'];
            $cat['fbudget'] += $l['forecast_budget'];
            if ($l['forecast'] === null) {
                $cat['fc_ok'] = false;
            } else {
                $cat['forecast'] += $l['forecast'];
            }
        }
        if ($allLine) {
            $s = ['basis' => 'whole', 'budget' => $allLine['budget'], 'spent' => $allLine['spent'], 'committed' => $allLine['committed'],
                  'forecast' => $allLine['forecast'], 'forecast_budget' => $allLine['forecast_budget']];
        } else {
            $s = ['basis' => 'categories', 'budget' => $cat['budget'], 'spent' => $cat['spent'], 'committed' => $cat['committed'],
                  'forecast' => $cat['fc_ok'] && $usable ? $cat['forecast'] : null, 'forecast_budget' => $cat['fbudget']];
        }
        // What was really received / is on order: with a whole-stock cap the cap line already holds everything.
        $s['received'] = $s['spent'] + ($s['basis'] === 'whole' ? 0.0 : $nbSpent);
        $s['ordered'] = $s['committed'] + ($s['basis'] === 'whole' ? 0.0 : $nbComm);
        $s['used'] = $s['spent'] + $s['committed'];
        $s['left'] = $s['budget'] - $s['used'];
        $s['pct_used'] = $s['budget'] > BALANCE_TOLERANCE ? $s['used'] / $s['budget'] * 100 : ($s['used'] > BALANCE_TOLERANCE ? 100.0 : 0.0);
        $s['month_pct'] = $info['month_pct'];
        $s['has_lines'] = (bool)$lines;
        $s['nobasis_lines'] = $unbased;
        $s['status'] = !$lines ? 'none' : ($usable === 0
            ? 'nobasis'
            : rh_sbud_line_status($s['budget'], $s['used'], $s['forecast'], $s['forecast_budget']));
        $s['verdict'] = rh_sbud_verdict($s, $info);

        return [
            'ym' => $ym, 'info' => $info, 'lines' => $lines,
            'other' => ['spent' => $otherSpent, 'committed' => $otherComm],
            'total' => ['spent' => $totalSpent, 'committed' => $totalComm],
            'summary' => $s, 'sales' => $sales, 'has_budget' => (bool)$lines,
        ];
    }
}

if (!function_exists('rh_sbud_money')) {
    function rh_sbud_money(float $v, string $cur = ''): string
    {
        return ($cur !== '' ? $cur . ' ' : '') . number_format($v, 0);
    }
}

if (!function_exists('rh_sbud_verdict')) {
    /** One plain-words line for the summary card. */
    function rh_sbud_verdict(array $s, array $info, string $cur = ''): string
    {
        switch ($s['status']) {
            case 'none':
                return !empty($s['has_lines']) ? 'Nothing spent yet.' : 'No budget set for this month.';
            case 'nobasis':
                return 'No sales basis yet - a % of sales budget needs some food and drink sales to measure against, so it is not checked until there are.';
            case 'over':
                $t = 'Over budget by ' . rh_sbud_money($s['used'] - $s['budget'], $cur) . '.';
                break;
            case 'near':
                $t = rh_sbud_money($s['left'], $cur) . ' left to spend - ' . number_format($s['pct_used'], 0) . '% of the budget is used.';
                break;
            case 'ahead':
                $t = 'Ahead of pace - at this rate you\'ll spend ' . rh_sbud_money((float)$s['forecast'], $cur) . ' by month end.';
                break;
            default:
                // Under 7 days of pace nothing can be said about "on track" or "behind".
                $t = $info['state'] === 'current' && $s['forecast'] === null && $info['days_done'] < RH_SBUD_MIN_PACE_DAYS
                    ? 'Too early in the month to tell how spending is going.'
                    : 'On track.';
        }
        if (!empty($s['nobasis_lines'])) {
            $t .= ' ' . (int)$s['nobasis_lines'] . ' line' . ((int)$s['nobasis_lines'] === 1 ? ' has' : 's have') . ' no sales basis yet and ' . ((int)$s['nobasis_lines'] === 1 ? 'is' : 'are') . ' not counted.';
        }
        return $t;
    }
}

if (!function_exists('rh_sbud_pill_key')) {
    /** The status a pill is styled and worded by: "ok" in the first 7 days of the current month becomes "early". */
    function rh_sbud_pill_key(string $status, array $info): string
    {
        return $status === 'ok' && $info['state'] === 'current' && $info['days_done'] < RH_SBUD_MIN_PACE_DAYS ? 'early' : $status;
    }
}

if (!function_exists('rh_sbud_pill_text')) {
    /** Plain label for a status pill; an "ok" line early in the current month is "too early to tell", not "on track". */
    function rh_sbud_pill_text(string $status, array $info): string
    {
        if (rh_sbud_pill_key($status, $info) === 'early') {
            return 'Too early to tell';
        }
        return ['ok' => 'On track', 'ahead' => 'Ahead of pace', 'near' => 'Nearly used', 'over' => 'Over budget',
                'none' => 'No spend yet', 'nobasis' => 'No sales basis yet'][$status] ?? '';
    }
}

// ── Saving budgets, copy / suggest, history ─────────────────────────────────────

if (!function_exists('rh_sbud_describe')) {
    function rh_sbud_describe(array $l): string
    {
        $ctl = ($l['control'] ?? 'warn') === 'approval' ? 'needs approval' : 'warn';
        if (($l['mode'] ?? 'fixed') === 'target_pct') {
            return rtrim(rtrim(number_format((float)$l['target_pct'], 2, '.', ''), '0'), '.') . '% of F&B sales, ' . $ctl;
        }
        return 'Fixed ' . number_format((float)$l['amount'], 2, '.', '') . ', ' . $ctl;
    }
}

if (!function_exists('rh_sbud_audit')) {
    function rh_sbud_audit(PDO $pdo, string $ym, ?string $category, string $action, ?string $old, ?string $new, ?int $userId, string $source = '', ?int $poId = null, ?string $reason = null): void
    {
        $pdo->prepare("INSERT INTO stock_budget_audit (budget_month, category, action, source, old_value, new_value, purchase_order_id, reason, user_id)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$ym . '-01', $category !== null ? mb_substr($category, 0, 100) : null, $action, $source !== '' ? $source : null,
                $old !== null ? mb_substr($old, 0, 255) : null, $new !== null ? mb_substr($new, 0, 255) : null,
                $poId, $reason !== null ? mb_substr($reason, 0, 500) : null, $userId]);
    }
}

if (!function_exists('rh_sbud_save')) {
    /**
     * Replace a month's budget lines with $input (rows of category, mode, amount, target_pct, control).
     * Lines left out are removed. Every change is audited. Only the current and future months can be edited.
     *
     * @param string $source manual | copy | suggest (stored on the audit rows)
     * @return array{ok:bool,error:string,changed:int}
     */
    function rh_sbud_save(PDO $pdo, string $ym, array $input, int $userId, string $source = 'manual', ?DateTimeImmutable $now = null): array
    {
        $info = rh_sbud_month_info($ym, $now);
        if ($info['state'] === 'past') {
            return ['ok' => false, 'error' => 'Past months are closed. Pick this month or a future month.', 'changed' => 0];
        }
        $source = in_array($source, ['manual', 'copy', 'suggest'], true) ? $source : 'manual';
        $valid = array_fill_keys(array_map('rh_sbud_cat_key', rh_sbud_categories($pdo)), true);
        foreach (rh_sbud_lines($pdo, $ym) as $r0) {
            $valid[rh_sbud_cat_key($r0['category'])] = true; // a line already saved stays editable if the category was renamed
        }
        $new = [];
        foreach ($input as $row) {
            $cat = trim((string)($row['category'] ?? ''));
            if ($cat === '') {
                continue;
            }
            $isAll = $cat === RH_SBUD_ALL;
            if (!$isAll && !isset($valid[rh_sbud_cat_key($cat)])) {
                return ['ok' => false, 'error' => '"' . mb_substr($cat, 0, 60) . '" is not an ingredient category.', 'changed' => 0];
            }
            // Use the stored spelling of the category.
            if (!$isAll) {
                foreach (array_merge(rh_sbud_categories($pdo), array_column(rh_sbud_lines($pdo, $ym), 'category')) as $c) {
                    if (rh_sbud_cat_key($c) === rh_sbud_cat_key($cat)) {
                        $cat = $c;
                        break;
                    }
                }
            }
            $mode = ($row['mode'] ?? 'fixed') === 'target_pct' ? 'target_pct' : 'fixed';
            $control = ($row['control'] ?? 'warn') === 'approval' ? 'approval' : 'warn';
            $amount = (float)str_replace(',', '', trim((string)($row['amount'] ?? '0')));
            $pct = (float)str_replace(',', '', trim((string)($row['target_pct'] ?? '0')));
            if ($mode === 'fixed') {
                if ($amount < 0 || $amount > 999999999) {
                    return ['ok' => false, 'error' => 'The budget for ' . ($isAll ? 'whole stock' : $cat) . ' must be between 0 and 999,999,999.', 'changed' => 0];
                }
                $pct = 0.0;
            } else {
                if ($pct <= 0 || $pct > 100) {
                    return ['ok' => false, 'error' => 'The target % for ' . ($isAll ? 'whole stock' : $cat) . ' must be above 0 and at most 100.', 'changed' => 0];
                }
                $amount = 0.0;
            }
            $new[rh_sbud_cat_key($cat)] = ['category' => $cat, 'mode' => $mode, 'amount' => round($amount, 2), 'target_pct' => round($pct, 2), 'control' => $control];
        }

        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $old = [];
            foreach (rh_sbud_lines($pdo, $ym) as $r) {
                $old[rh_sbud_cat_key($r['category'])] = $r;
            }
            $changed = 0;
            foreach ($new as $k => $n) {
                if (!isset($old[$k])) {
                    $pdo->prepare("INSERT INTO stock_budgets (budget_month, category, mode, amount, target_pct, control, updated_by) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$ym . '-01', $n['category'], $n['mode'], $n['amount'], $n['target_pct'], $n['control'], $userId]);
                    rh_sbud_audit($pdo, $ym, $n['category'], 'set', null, rh_sbud_describe($n), $userId, $source);
                    $changed++;
                } else {
                    $o = $old[$k];
                    $same = $o['mode'] === $n['mode'] && abs((float)$o['amount'] - $n['amount']) <= BALANCE_TOLERANCE
                        && abs((float)$o['target_pct'] - $n['target_pct']) <= 0.001 && $o['control'] === $n['control'];
                    if (!$same) {
                        $pdo->prepare("UPDATE stock_budgets SET mode=?, amount=?, target_pct=?, control=?, updated_by=? WHERE id=?")
                            ->execute([$n['mode'], $n['amount'], $n['target_pct'], $n['control'], $userId, (int)$o['id']]);
                        rh_sbud_audit($pdo, $ym, $n['category'], 'changed', rh_sbud_describe($o), rh_sbud_describe($n), $userId, $source);
                        $changed++;
                    }
                }
            }
            foreach ($old as $k => $o) {
                if (!isset($new[$k])) {
                    $pdo->prepare("DELETE FROM stock_budgets WHERE id = ?")->execute([(int)$o['id']]);
                    rh_sbud_audit($pdo, $ym, $o['category'], 'removed', rh_sbud_describe($o), null, $userId, $source);
                    $changed++;
                }
            }
            if ($own) {
                $pdo->commit();
            }
            return ['ok' => true, 'error' => '', 'changed' => $changed];
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('rh_sbud_save: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the budgets. Nothing was changed.', 'changed' => 0];
        }
    }
}

if (!function_exists('rh_sbud_copy_proposal')) {
    /** Last month's lines, ready to drop into the edit form (nothing is saved). */
    function rh_sbud_copy_proposal(PDO $pdo, string $ym): array
    {
        $out = [];
        foreach (rh_sbud_lines($pdo, rh_sbud_shift_month($ym, -1)) as $r) {
            $out[] = ['category' => $r['category'], 'mode' => $r['mode'], 'amount' => (float)$r['amount'],
                      'target_pct' => (float)$r['target_pct'], 'control' => $r['control']];
        }
        return $out;
    }
}

if (!function_exists('rh_sbud_suggest_proposal')) {
    /**
     * Fixed amounts from what was actually received per category over the 3 months before $ym
     * (averaged over the months that had any goods-in; whole currency units). Nothing is saved.
     */
    function rh_sbud_suggest_proposal(PDO $pdo, string $ym): array
    {
        $sum = [];
        $monthsWithData = 0;
        for ($i = 1; $i <= 3; $i++) {
            $by = rh_sbud_spent_by_category($pdo, rh_sbud_shift_month($ym, -$i));
            if (array_sum($by) > BALANCE_TOLERANCE) {
                $monthsWithData++;
            }
            foreach ($by as $k => $v) {
                $sum[$k] = ($sum[$k] ?? 0.0) + $v;
            }
        }
        $out = [];
        if ($monthsWithData === 0) {
            return $out;
        }
        foreach (rh_sbud_categories($pdo) as $c) {
            $v = $sum[rh_sbud_cat_key($c)] ?? 0.0;
            if ($v > BALANCE_TOLERANCE) {
                $out[] = ['category' => $c, 'mode' => 'fixed', 'amount' => round($v / $monthsWithData, 0), 'target_pct' => 0.0, 'control' => 'warn'];
            }
        }
        return $out;
    }
}

if (!function_exists('rh_sbud_history')) {
    /** Budget vs actual for the $n months before $ym (newest first). Budget = the month's summary budget. */
    function rh_sbud_history(PDO $pdo, string $ym, int $n = 6): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $m = rh_sbud_shift_month($ym, -$i);
            $c = rh_sbud_compute($pdo, $m);
            // Only % lines with no sales that month: there is no budget figure to compare against.
            $known = $c['has_budget'] && $c['summary']['status'] !== 'nobasis';
            $actual = $known ? $c['summary']['received'] : $c['total']['spent'];
            $out[] = [
                'ym' => $m, 'label' => $c['info']['label'], 'has_budget' => $known,
                'budget' => $c['summary']['budget'], 'actual' => $actual,
                'variance' => $known ? $c['summary']['budget'] - $actual : null,
            ];
        }
        return $out;
    }
}

if (!function_exists('rh_sbud_audit_recent')) {
    function rh_sbud_audit_recent(PDO $pdo, string $ym, int $limit = 15): array
    {
        $st = $pdo->prepare("
            SELECT a.*, u.full_name, po.reference AS po_reference
            FROM stock_budget_audit a
            LEFT JOIN admin_users u ON u.id = a.user_id
            LEFT JOIN stock_purchase_orders po ON po.id = a.purchase_order_id
            WHERE a.budget_month = ? ORDER BY a.id DESC LIMIT " . (int)$limit);
        $st->execute([$ym . '-01']);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('rh_sbud_detail')) {
    /**
     * Receipts and open POs behind a line's numbers. $category: a category, RH_SBUD_ALL, or '__other__'
     * (everything without a budget line of its own).
     */
    function rh_sbud_detail(PDO $pdo, string $ym, string $category, ?DateTimeImmutable $now = null): array
    {
        $info = rh_sbud_month_info($ym, $now);
        $covered = [];
        foreach (rh_sbud_lines($pdo, $ym) as $r) {
            if ($r['category'] !== RH_SBUD_ALL) {
                $covered[] = rh_sbud_cat_key($r['category']);
            }
        }
        $catSql = "LOWER(TRIM(COALESCE(i.category, '')))";
        $where = '1=1';
        $params = [];
        if ($category === '__other__') {
            if ($covered) {
                $where = "$catSql NOT IN (" . implode(',', array_fill(0, count($covered), '?')) . ")";
                $params = $covered;
            }
        } elseif ($category !== RH_SBUD_ALL) {
            $where = "$catSql = ?";
            $params = [rh_sbud_cat_key($category)];
        }

        $st = $pdo->prepare("
            SELECT l.id, l.created_at, l.quantity, l.cost_total, l.purchase_order_id, COALESCE(i.name, '(deleted item)') AS name,
                   COALESCE(i.unit, '') AS unit, po.reference AS po_reference
            FROM stock_in_log l
            LEFT JOIN stock_ingredients i ON i.id = l.ingredient_id
            LEFT JOIN stock_purchase_orders po ON po.id = l.purchase_order_id
            WHERE l.created_at >= ? AND l.created_at < ? AND $where
            ORDER BY l.created_at DESC, l.id DESC LIMIT " . RH_SBUD_DETAIL_RECEIPTS);
        $st->execute(array_merge([$info['start'], $info['end']], $params));
        $receipts = $st->fetchAll(PDO::FETCH_ASSOC);
        // How many receipts there really are, so a capped list can say so (the totals above always include all).
        $ct = $pdo->prepare("
            SELECT COUNT(*) FROM stock_in_log l
            LEFT JOIN stock_ingredients i ON i.id = l.ingredient_id
            WHERE l.created_at >= ? AND l.created_at < ? AND $where");
        $ct->execute(array_merge([$info['start'], $info['end']], $params));
        $receiptsTotal = (int)$ct->fetchColumn();
        // Non-stock PO lines count as uncategorised spend (see rh_sbud_spent_by_category).
        $showNonStock = $category === RH_SBUD_ALL || rh_sbud_cat_key($category) === ''
            || ($category === '__other__' && !in_array('', $covered, true));
        if ($showNonStock) {
            try {
                $ns = $pdo->prepare("
                    SELECT CONCAT('ns', n.id) AS id, n.created_at, n.quantity, n.cost_total, n.purchase_order_id,
                           CONCAT(COALESCE(NULLIF(n.description, ''), 'PO line'), ' (non-stock)') AS name, '' AS unit, po.reference AS po_reference
                    FROM stock_nonstock_receipts n
                    LEFT JOIN stock_purchase_orders po ON po.id = n.purchase_order_id
                    WHERE n.created_at >= ? AND n.created_at < ?
                    ORDER BY n.created_at DESC LIMIT " . RH_SBUD_DETAIL_RECEIPTS);
                $ns->execute([$info['start'], $info['end']]);
                $receipts = array_merge($receipts, $ns->fetchAll(PDO::FETCH_ASSOC));
                $nc = $pdo->prepare("SELECT COUNT(*) FROM stock_nonstock_receipts WHERE created_at >= ? AND created_at < ?");
                $nc->execute([$info['start'], $info['end']]);
                $receiptsTotal += (int)$nc->fetchColumn();
                usort($receipts, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
            } catch (Throwable $e) {
                // table not provisioned yet
            }
        }
        // The latest RH_SBUD_DETAIL_RECEIPTS of both kinds together.
        $receipts = array_slice($receipts, 0, RH_SBUD_DETAIL_RECEIPTS);

        $pos = [];
        if ($info['state'] === 'current') {
            $st = $pdo->prepare("
                SELECT po.id, po.reference, po.status, COALESCE(s.name, 'Unassigned') AS supplier,
                       SUM(GREATEST(poi.ordered_qty - poi.received_qty, 0) * poi.unit_cost) AS outstanding
                FROM stock_purchase_order_items poi
                INNER JOIN stock_purchase_orders po ON po.id = poi.purchase_order_id
                LEFT JOIN stock_suppliers s ON s.id = po.supplier_id
                LEFT JOIN stock_ingredients i ON i.id = poi.ingredient_id
                WHERE po.status IN ('sent','partial') AND $where
                GROUP BY po.id, po.reference, po.status, s.name
                HAVING outstanding > 0.0001
                ORDER BY po.id DESC LIMIT " . (RH_SBUD_DETAIL_ORDERS + 1));
            $st->execute($params);
            $pos = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $posCapped = count($pos) > RH_SBUD_DETAIL_ORDERS;
        $pos = array_slice($pos, 0, RH_SBUD_DETAIL_ORDERS);
        return ['receipts' => $receipts, 'receipts_total' => $receiptsTotal, 'pos' => $pos, 'pos_capped' => $posCapped];
    }
}

// ── Purchase order control ──────────────────────────────────────────────────────

if (!function_exists('rh_sbud_po_effect')) {
    /**
     * What a PO does to this month's budget lines: for every line it touches, left before -> left after.
     * The PO's own current commitment is left out of "before" so a sent PO is not counted twice.
     * A line is "over" when it ends below zero (beyond tolerance) and this PO adds to it.
     *
     * @return array{applies:bool,rows:array,over:array,needs_approval:bool,po_total:float,ym:string}
     */
    function rh_sbud_po_effect(PDO $pdo, int $poId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: rh_sbud_now();
        $ym = $now->format('Y-m');
        $res = ['applies' => false, 'rows' => [], 'over' => [], 'needs_approval' => false, 'po_total' => 0.0, 'ym' => $ym];

        $st = $pdo->prepare("
            SELECT TRIM(COALESCE(i.category, '')) AS cat,
                   COALESCE(SUM(GREATEST(poi.ordered_qty - poi.received_qty, 0) * poi.unit_cost), 0) AS v
            FROM stock_purchase_order_items poi
            LEFT JOIN stock_ingredients i ON i.id = poi.ingredient_id
            WHERE poi.purchase_order_id = ?
            GROUP BY TRIM(COALESCE(i.category, ''))");
        $st->execute([$poId]);
        $poBy = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = rh_sbud_cat_key($r['cat']);
            $poBy[$k] = ($poBy[$k] ?? 0.0) + (float)$r['v'];
        }
        $res['po_total'] = array_sum($poBy);

        $c = rh_sbud_compute($pdo, $ym, ['now' => $now, 'exclude_po' => $poId]);
        if (!$c['has_budget']) {
            return $res;
        }
        $res['applies'] = true;
        foreach ($c['lines'] as $l) {
            if (!empty($l['no_basis'])) {
                continue; // no sales basis yet: nothing to be over, so it never blocks or warns
            }
            $add = $l['is_all'] ? $res['po_total'] : (float)($poBy[rh_sbud_cat_key($l['category'])] ?? 0.0);
            if ($add <= BALANCE_TOLERANCE) {
                continue;
            }
            $after = $l['left'] - $add;
            $over = $after < -BALANCE_TOLERANCE;
            $row = ['category' => $l['category'], 'label' => $l['label'], 'control' => $l['control'], 'budget' => $l['budget'],
                    'left_before' => $l['left'], 'po_value' => $add, 'left_after' => $after, 'over' => $over];
            $res['rows'][] = $row;
            if ($over) {
                $res['over'][] = $row;
                if ($l['control'] === 'approval') {
                    $res['needs_approval'] = true;
                }
            }
        }
        return $res;
    }
}

if (!function_exists('rh_sbud_lock')) {
    /**
     * Take the row lock on this month's budget lines. Call it as the FIRST statement after beginTransaction():
     * under REPEATABLE READ the transaction's read snapshot starts at its first plain SELECT, so a lock taken
     * after one would be granted only after the other sender committed, yet the numbers would still be read from
     * the older snapshot and both sends could pass the last money.
     */
    function rh_sbud_lock(PDO $pdo, ?DateTimeImmutable $now = null): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }
        $now = $now ?: rh_sbud_now();
        $lk = $pdo->prepare("SELECT id FROM stock_budgets WHERE budget_month = ? FOR UPDATE");
        $lk->execute([$now->format('Y-m') . '-01']);
        $lk->fetchAll();
    }
}

if (!function_exists('rh_sbud_authorize_po')) {
    /**
     * May this PO be sent (or grown) given the budget? Call inside the transaction that changes the PO.
     * - Within budget: ok.
     * - Over on "warn" lines only: ok once $confirmed is true.
     * - Over on an "approval" line: only a user who $canManage may, and a reason of 5+ characters is required.
     * An override is stored on the PO and audited. Refusals change nothing.
     *
     * @return array{ok:bool,error:string,overridden:bool,effect:array}
     */
    function rh_sbud_authorize_po(PDO $pdo, int $poId, int $userId, bool $canManage, bool $confirmed, string $reason, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: rh_sbud_now();
        $ym = $now->format('Y-m');
        // Serialise concurrent sends against the same budget lines (callers should already have called
        // rh_sbud_lock() right after beginTransaction(); taking it again here is harmless).
        rh_sbud_lock($pdo, $now);
        $effect = rh_sbud_po_effect($pdo, $poId, $now);
        $fail = static function (string $msg) use ($effect) {
            return ['ok' => false, 'error' => $msg, 'overridden' => false, 'effect' => $effect];
        };
        if (!$effect['over']) {
            return ['ok' => true, 'error' => '', 'overridden' => false, 'effect' => $effect];
        }
        $names = implode(', ', array_map(static function ($r) { return $r['label']; }, $effect['over']));
        $reason = trim($reason);

        if ($effect['needs_approval']) {
            if (!$canManage) {
                return $fail('This order takes the budget over for ' . $names . '. A budget manager has to approve it before it can be sent.');
            }
            if (mb_strlen($reason) < 5) {
                return $fail('This order takes the budget over for ' . $names . '. Give a reason (at least 5 characters) to approve it.');
            }
        } elseif (!$confirmed) {
            return $fail('This order takes the budget over for ' . $names . '. Tick the box to send it anyway.');
        }

        $reasonStored = $reason !== '' ? $reason : 'Confirmed over budget (warning only)';
        $pdo->prepare("UPDATE stock_purchase_orders SET budget_override_reason = ?, budget_override_by = ?, budget_override_at = NOW() WHERE id = ?")
            ->execute([mb_substr($reasonStored, 0, 500), $userId, $poId]);
        foreach ($effect['over'] as $r) {
            rh_sbud_audit($pdo, $ym, $r['category'], 'po_override', 'Left ' . number_format($r['left_before'], 2, '.', ''),
                'Left ' . number_format($r['left_after'], 2, '.', '') . ' after PO value ' . number_format($r['po_value'], 2, '.', ''),
                $userId, $effect['needs_approval'] ? 'approval' : 'warn', $poId, $reasonStored);
        }
        return ['ok' => true, 'error' => '', 'overridden' => true, 'effect' => $effect];
    }
}

// ── Small summaries for other screens ───────────────────────────────────────────

if (!function_exists('rh_sbud_dashboard')) {
    /**
     * This month at a glance for the stock dashboard and the buying page; null when no budget is set.
     * @return ?array{ym:string,label:string,pct_used:float,budget:float,used:float,left:float,status:string,worst:?array,notice:string}
     */
    function rh_sbud_dashboard(PDO $pdo, ?DateTimeImmutable $now = null): ?array
    {
        $now = $now ?: rh_sbud_now();
        $c = rh_sbud_compute($pdo, $now->format('Y-m'), ['now' => $now]);
        // No budget set, or only % lines with no sales basis yet: there is no figure to show.
        if (!$c['has_budget'] || $c['summary']['status'] === 'nobasis') {
            return null;
        }
        $s = $c['summary'];
        $worst = null;
        foreach ($c['lines'] as $l) {
            if (!empty($l['no_basis'])) {
                continue;
            }
            if ($l['is_all'] && $s['basis'] === 'whole' && count($c['lines']) > 1) {
                continue;
            }
            if ($worst === null || $l['pct_used'] > $worst['pct_used']) {
                $worst = $l;
            }
        }
        $notice = '';
        $worstLabel = $worst ? $worst['label'] : '';
        if ($s['status'] === 'over' || ($worst && $worst['status'] === 'over')) {
            $notice = 'Over budget this month' . ($worst && $worst['status'] === 'over' ? ' - ' . $worstLabel : '') . '.';
        } elseif ($s['pct_used'] >= 80 || ($worst && $worst['pct_used'] >= 80)) {
            $notice = 'Most of this month\'s stock budget is used' . ($worst && $worst['pct_used'] >= 80 ? ' - ' . $worstLabel . ' is at ' . number_format($worst['pct_used'], 0) . '%' : '') . '.';
        }
        return [
            'ym' => $c['ym'], 'label' => $c['info']['label'], 'pct_used' => $s['pct_used'], 'budget' => $s['budget'], 'used' => $s['used'],
            'left' => $s['left'], 'status' => $s['status'], 'worst' => $worst, 'notice' => $notice,
        ];
    }
}
