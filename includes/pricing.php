<?php
/**
 * Dynamic Pricing Engine
 *
 * Provides functions for applying rate plans and packages to bookings.
 * Called from booking.php and check-availability.php.
 *
 * Public API:
 *   applyDynamicPricing($pdo, $room_id, $check_in, $check_out, $nights, $base_price) : array
 *   applyRatePlanToPrice(?array $plan, float $base) : float
 *   getActivePackages($pdo, $room_id) : array
 *   calculatePackageCost(array $pkg, int $nights, int $adult_guests) : float
 *   getDynamicPricingPreview($pdo, $room_id, $check_in, $check_out, $nights, $base_price) : array
 *   rh_apply_rate_plans(array $plans, ...) : array                 PURE plan selection (applyDynamicPricing's rule)
 *   rh_stay_totals_with_package(float $gross, float $pkgGross, bool $levy) : array   levy on the room part only
 *   rh_rate_plan_dropped($pdo, array $b, $in, $out, $nights, ?$roomTypeId) : array   staff date change: discount lost?
 *   rh_rate_plan_effective(array $plan, int $roomId) : ?array      step 8h: a room type's own amount (or the plan's) + basis
 *   rh_rate_plan_price(array $plan, int $roomId, float $price, ?float $F) : float   step 8h: one plan on one room type
 *   rh_rate_plan_breakdown(...) / rh_rate_plan_validate_rooms(...)  step 8h: Rate Plans screen breakdown + save checks
 */

if (!function_exists('applyDynamicPricing')) {

    /**
     * Find and apply the applicable rate plans to a base room price.
     *
     * Surcharges and discounts are chosen separately (see rh_apply_rate_plans()): in each group a non-stacking
     * plan means only that group's single best plan applies, otherwise all stack; surcharges first, then discounts.
     *
     * @param PDO    $pdo
     * @param int    $room_id       The rooms.id (room type)
     * @param string $check_in      Y-m-d
     * @param string $check_out     Y-m-d
     * @param int    $nights        Number of nights
     * @param float  $base_price    Per-night base rate (occupancy-resolved)
     * @return array {
     *   final_price      float,
     *   original_price   float,
     *   discount_amount  float,   // negative = surcharge
     *   rate_plan_id     int|null,
     *   rate_plan_label  string,
     *   rate_plan_row    array|null  // raw DB row for re-use in split-booking loops
     * }
     */
    function applyDynamicPricing(
        PDO $pdo,
        int $room_id,
        string $check_in,
        string $check_out,
        int $nights,
        float $base_price
    ): array {

        $empty = [
            'final_price'     => $base_price,
            'original_price'  => $base_price,
            'discount_amount' => 0.0,
            'rate_plan_id'    => null,
            'rate_plan_label' => '',
            'rate_plan_row'   => null,
        ];

        if ($nights < 1) {
            return $empty;
        }

        // Guard against a zero base price — dynamic pricing cannot apply a
        // meaningful adjustment to a £0 rate and would silently produce a
        // free booking. Log and return immediately so the caller must fix the
        // room's price configuration before a booking can proceed.
        if ($base_price <= 0) {
            error_log("[pricing] applyDynamicPricing called with base_price={$base_price} for room_id={$room_id}. Check room price configuration.");
            return $empty;
        }

        try {
            $plans = _fetchActiveRatePlans($pdo);
        } catch (PDOException $e) {
            error_log('[pricing] DB error fetching rate plans: ' . $e->getMessage());
            return $empty;
        }

        return rh_apply_rate_plans($plans, $room_id, $check_in, $check_out, $nights, $base_price);
    }

    /**
     * PURE: apply a priority-ordered list of rate plan rows (priority DESC, id ASC, as _fetchActiveRatePlans()
     * returns them) to a base nightly price. Same return keys as applyDynamicPricing().
     *
     * Rule (owner 2026-11-10, RP-3):
     *  - matching plans are split into surcharges (adjustment_value > 0) and discounts (< 0);
     *  - inside each group: if any plan is non-stacking, only that group's single best plan applies (discounts: the
     *    lowest resulting price, surcharges: the highest; a tie goes to the earlier plan in priority order); if every
     *    plan in the group stacks, they all apply in priority order;
     *  - surcharges are applied first, from the base price, then discounts (judged on the price after surcharges,
     *    the price the guest actually pays); never below 0.
     * rate_plan_id / rate_plan_row = the first applied plan in priority order; rate_plan_ids / rate_plan_label = every
     * applied plan in priority order (the label is cut to 120 characters, the bookings.rate_plan_label column).
     *
     * @param DateTime|null $today for tests; defaults to today
     */
    function rh_apply_rate_plans(array $plans, int $room_id, string $check_in, string $check_out, int $nights, float $base_price, ?DateTime $today = null): array
    {
        $empty = [
            'final_price'     => $base_price,
            'original_price'  => $base_price,
            'discount_amount' => 0.0,
            'rate_plan_id'    => null,
            'rate_plan_label' => '',
            'rate_plan_row'   => null,
        ];
        if ($nights < 1 || $base_price <= 0 || empty($plans)) {
            return $empty;
        }

        $checkInDate  = new DateTime($check_in);
        $checkOutDate = new DateTime($check_out);
        $today        = $today ? clone $today : new DateTime('today');
        $daysUntilArrival = (int)$today->diff($checkInDate)->days;
        if ($checkInDate < $today) {
            $daysUntilArrival = 0;
        }

        // Every plan matching this room + stay, split by direction (list order = priority order).
        $surcharges = [];
        $discounts  = [];
        $order      = [];
        foreach (array_values($plans) as $pos => $plan) {
            if (!_planAppliesToRoom($plan, $room_id)) {
                continue;
            }
            if (!_planMatchesStay($plan, $checkInDate, $checkOutDate, $nights, $daysUntilArrival)) {
                continue;
            }
            // Step 8h: the direction comes from THIS room's adjustment (its own amount when the plan sets one).
            $eff   = rh_rate_plan_effective($plan, $room_id);
            $value = $eff ? (float)$eff['value'] : 0.0;
            if ($value > 0) {
                $surcharges[] = $plan;
            } elseif ($value < 0) {
                $discounts[] = $plan;
            } else {
                continue; // a 0 adjustment changes nothing (the form refuses 0)
            }
            $order[] = ['pos' => $pos, 'plan' => $plan];
        }
        if (!$surcharges && !$discounts) {
            return $empty;
        }

        $currentPrice = $base_price;
        $appliedPos   = [];
        foreach ([[$surcharges, false], [$discounts, true]] as [$group, $isDiscount]) {
            foreach (_rhRatePlanGroupPick($group, $currentPrice, $isDiscount, $room_id) as $plan) {
                $currentPrice = rh_rate_plan_price($plan, $room_id, $currentPrice);
                $appliedPos[] = $plan;
            }
        }
        $totalDiscount = $base_price - $currentPrice; // positive = discount, negative = surcharge

        // Applied plans back in priority order.
        $applied = [];
        foreach ($order as $o) {
            foreach ($appliedPos as $p) {
                if ($p === $o['plan']) {
                    $applied[] = $o['plan'];
                    break;
                }
            }
        }
        $appliedPlan = $applied[0];
        $labels = [];
        $ids    = [];
        foreach ($applied as $plan) {
            $labels[] = (string)$plan['name'];
            $ids[]    = (int)$plan['id'];
        }
        $label = implode(', ', $labels);
        if (function_exists('mb_substr') && mb_strlen($label) > 120) {
            $label = rtrim(mb_substr($label, 0, 120));
        }

        return [
            'final_price'     => round($currentPrice, 2),
            'original_price'  => $base_price,
            'discount_amount' => round($totalDiscount, 2),
            'rate_plan_id'    => (int)$appliedPlan['id'],       // primary plan
            'rate_plan_label' => $label,                        // every applied plan
            'rate_plan_ids'   => $ids,
            'rate_plan_row'   => $appliedPlan,
        ];
    }

    /**
     * The plans of ONE direction that apply, starting from $price: all of them (in order) when every plan stacks,
     * else the single best one (discount: lowest result; surcharge: highest result; ties keep the earlier plan).
     * $roomId (step 8h): judge each plan with that room type's own amount; null = the plan's default amount (legacy).
     */
    function _rhRatePlanGroupPick(array $group, float $price, bool $isDiscount, ?int $roomId = null): array
    {
        if (!$group) {
            return [];
        }
        $hasNonStacking = false;
        foreach ($group as $plan) {
            if (empty($plan['is_stacking'])) {
                $hasNonStacking = true;
                break;
            }
        }
        if (!$hasNonStacking) {
            return $group;
        }
        $bestPlan  = null;
        $bestPrice = null;
        foreach ($group as $plan) {
            $candidate = $roomId === null ? applyRatePlanToPrice($plan, $price) : rh_rate_plan_price($plan, $roomId, $price);
            if ($bestPrice === null
                || ($isDiscount && $candidate < $bestPrice - 0.00001)
                || (!$isDiscount && $candidate > $bestPrice + 0.00001)) {
                $bestPrice = $candidate;
                $bestPlan  = $plan;
            }
        }
        return [$bestPlan];
    }

    /**
     * Single source of truth for splitting a stay amount into net / VAT / levy / total.
     * The tourism levy sits OUTSIDE the VAT base: levy = net * levyRate, VAT = net * vatRate,
     * total = net + vat + levy (exactly, at 2dp; rounding residue lands on net).
     *
     * $basis 'price': a catalog amount read per vat_pricing_mode
     *            inclusive -> amount contains VAT; exclusive -> amount is net; off -> no VAT.
     * $basis 'final': an all-in amount the guest pays; net = amount / (1 + vat + levy).
     * $applyLevy false skips the levy for this amount (e.g. packages).
     *
     * @return array{net:float,vat:float,levy:float,total_with_vat:float,vat_rate:float,levy_rate:float}
     *         vat_rate / levy_rate are percentages (16.5 = 16.5%).
     */
    function rh_stay_totals(float $amount, string $basis = 'price', bool $applyLevy = true): array
    {
        $mode = function_exists('vat_mode') ? vat_mode() : 'off';
        $vatPct = $mode === 'off' ? 0.0 : (float)getSetting('vat_rate', 0);
        $levyOn = in_array(getSetting('tourism_levy_enabled', '0'), ['1', 1, true, 'true', 'on'], true);
        $levyPct = ($applyLevy && $levyOn) ? max(0.0, (float)getSetting('tourism_levy_percent', 0)) : 0.0;
        $v = $vatPct / 100;
        $l = $levyPct / 100;
        $amount = max(0.0, $amount);

        // Owner 2026-10-24: a room price is ALWAYS the ALL-IN amount (base + VAT + levy), in every
        // vat_pricing_mode; components switched off are simply not extracted. Every caller prices a
        // stay (room, child supplement, packages), so there is no add-on-top branch any more.
        if (true) {
            $total    = round($amount, 2);
            $netExact = $amount / (1 + $v + $l);
            $vat      = round($netExact * $v, 2);
            $levy     = round($netExact * $l, 2);
            $net      = round($total - $vat - $levy, 2);
        } else {
            if ($mode === 'inclusive' && $v > 0) {
                $netExact = $amount / (1 + $v);
                $vat      = round($amount - $netExact, 2);
                $levy     = round($netExact * $l, 2);
                $total    = round(round($amount, 2) + $levy, 2);
                $net      = round($total - $vat - $levy, 2);
            } else {
                $net  = round($amount, 2);
                $vat  = round($amount * $v, 2);
                $levy = round($amount * $l, 2);
                $total = round($net + $vat + $levy, 2);
            }
        }

        return [
            'net'            => $net,
            'vat'            => $vat,
            'levy'           => $levy,
            'total_with_vat' => $total,
            'vat_rate'       => $vatPct,
            'levy_rate'      => $levyPct,
        ];
    }

    /**
     * Apply a single rate plan's adjustment to a price.
     * Safe to call with null plan (returns price unchanged).
     */
    function applyRatePlanToPrice(?array $plan, float $price): float
    {
        if ($plan === null) {
            return $price;
        }
        $value = (float)($plan['adjustment_value'] ?? 0);
        if ($plan['adjustment_type'] === 'fixed') {
            return max(0.0, $price + $value);
        }
        // percentage: value of -10 = 10% discount, +20 = 20% surcharge
        return max(0.0, round($price * (1 + $value / 100), 2));
    }

    /* ── Step 8h (owner 2026-11-15): an amount per room type + price basis ─────────────────────────────────────────
     * rate_plans.room_adjustments (JSON {"<room_id>":{"type":"percentage|fixed","value":n}}) and rate_plans.price_basis
     * ('all_in' | 'base'), migration 086. A missing column reads as NULL / 'all_in' = exactly today's price. */

    /**
     * True once migration 086 added rate_plans.room_adjustments AND rate_plans.price_basis. One information_schema
     * read per request. Tests can force it with $GLOBALS['rh_rate_plans_086_override'] = true|false.
     */
    function rh_rate_plans_086_ready(PDO $pdo): bool
    {
        if (array_key_exists('rh_rate_plans_086_override', $GLOBALS) && $GLOBALS['rh_rate_plans_086_override'] !== null) {
            return (bool)$GLOBALS['rh_rate_plans_086_override'];
        }
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_plans' AND COLUMN_NAME IN ('room_adjustments', 'price_basis')");
            $st->execute();
            $ready = (int)$st->fetchColumn() === 2;
        } catch (Throwable $e) {
            error_log('rh_rate_plans_086_ready: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    /**
     * VAT and levy percentages inside a room price, read exactly as rh_stay_totals() reads them
     * (VAT 0 when vat_mode() is off; levy 0 when switched off or negative).
     * @return array{vat:float,levy:float}
     */
    function rh_rate_plan_tax_rates(): array
    {
        $mode = function_exists('vat_mode') ? vat_mode() : 'off';
        $vatPct = $mode === 'off' ? 0.0 : (float)getSetting('vat_rate', 0);
        $levyOn = in_array(getSetting('tourism_levy_enabled', '0'), ['1', 1, true, 'true', 'on'], true);
        $levyPct = $levyOn ? max(0.0, (float)getSetting('tourism_levy_percent', 0)) : 0.0;
        return ['vat' => max(0.0, $vatPct), 'levy' => $levyPct];
    }

    /** 1 + VAT + levy (as fractions): what one unit of base rate costs the guest all-in. 1.0 when both are off. */
    function rh_rate_plan_tax_factor(): float
    {
        $r = rh_rate_plan_tax_rates();
        return 1 + $r['vat'] / 100 + $r['levy'] / 100;
    }

    /** True when VAT and/or the levy actually add something (the basis choice only means something then). */
    function rh_rate_plan_basis_matters(?float $factor = null): bool
    {
        return ($factor ?? rh_rate_plan_tax_factor()) > 1.0000001;
    }

    /**
     * The plan's per-room-type amounts, cleaned: [room_id => ['type' => 'percentage'|'fixed', 'value' => float]].
     * Bad JSON or bad rows are ignored (that room then uses the plan's own amount). Never adds keys to $plan.
     */
    function rh_rate_plan_overrides(array $plan): array
    {
        $raw = $plan['room_adjustments'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $data = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $rid => $row) {
            $rid = (int)$rid;
            if ($rid <= 0 || !is_array($row) || !isset($row['value']) || !is_numeric($row['value'])) {
                continue;
            }
            $type = ($row['type'] ?? '') === 'fixed' ? 'fixed' : (($row['type'] ?? '') === 'percentage' ? 'percentage' : null);
            $value = round((float)$row['value'], 2);
            if ($type === null || abs($value) < 0.000001) {
                continue;
            }
            $out[$rid] = ['type' => $type, 'value' => $value];
        }
        ksort($out);
        return $out;
    }

    /**
     * The adjustment a plan makes for ONE room type, or null when the plan does not cover that room type.
     * applies_to 'all': a listed room type uses its own amount, every other (incl. room types added later) the default.
     * applies_to 'room_types': only the chosen room types; a listed one uses its own amount.
     * @return array{type:string,value:float,basis:string,override:bool}|null
     */
    function rh_rate_plan_effective(array $plan, int $roomId): ?array
    {
        if (!_planAppliesToRoom($plan, $roomId)) {
            return null;
        }
        $basis = (($plan['price_basis'] ?? 'all_in') === 'base') ? 'base' : 'all_in';
        $ov = rh_rate_plan_overrides($plan);
        if (isset($ov[$roomId])) {
            return ['type' => $ov[$roomId]['type'], 'value' => $ov[$roomId]['value'], 'basis' => $basis, 'override' => true];
        }
        return [
            'type'     => (($plan['adjustment_type'] ?? '') === 'fixed') ? 'fixed' : 'percentage',
            'value'    => (float)($plan['adjustment_value'] ?? 0),
            'basis'    => $basis,
            'override' => false,
        ];
    }

    /**
     * PURE math of one adjustment on an all-in price $price.
     *  percentage (either basis): round(P * (1 + p/100), 2) - the same guest total on either basis.
     *  fixed on the all-in price:  P + value (today's rule, unrounded).
     *  fixed on the base rate:     P + round(value * F, 2), F = 1 + VAT + levy (VAT and levy added on top of the amount).
     * Never below 0. $factor is read only for fixed + base (null = rh_rate_plan_tax_factor()).
     */
    function rh_rate_plan_adjust(array $eff, float $price, ?float $factor = null): float
    {
        $value = (float)($eff['value'] ?? 0);
        if (($eff['type'] ?? '') === 'fixed') {
            if (($eff['basis'] ?? 'all_in') === 'base') {
                $f = $factor ?? rh_rate_plan_tax_factor();
                return max(0.0, round($price + round($value * $f, 2), 2));
            }
            return max(0.0, $price + $value);
        }
        return max(0.0, round($price * (1 + $value / 100), 2));
    }

    /** A plan's price for ONE room type (its own amount + the plan's basis). Not covered = $price unchanged. */
    function rh_rate_plan_price(array $plan, int $roomId, float $price, ?float $factor = null): float
    {
        $eff = rh_rate_plan_effective($plan, $roomId);
        return $eff === null ? $price : rh_rate_plan_adjust($eff, $price, $factor);
    }

    /** Short money text: 100,000 / 1,234.50 (no currency). */
    function rh_rate_plan_money(float $v): string
    {
        return number_format($v, abs($v - round($v)) > 0.004 ? 2 : 0);
    }

    /** "+10%", "-MWK 5,000", "+MWK 5,000 before VAT/levy". */
    function rh_rate_plan_adjustment_text(array $eff, string $cur = 'MWK'): string
    {
        $v = (float)($eff['value'] ?? 0);
        $sign = $v > 0 ? '+' : ($v < 0 ? '-' : '');
        if (($eff['type'] ?? '') === 'fixed') {
            $t = $sign . $cur . ' ' . rh_rate_plan_money(abs($v));
            return ($eff['basis'] ?? 'all_in') === 'base' ? $t . ' before VAT/levy' : $t;
        }
        $pct = rtrim(rtrim(number_format(abs($v), 2, '.', ''), '0'), '.');
        return $sign . $pct . '%';
    }

    /**
     * The breakdown staff see for one room type: standard all-in price and its split, the adjustment, the new all-in
     * price and its split (rh_stay_totals(..., 'final'), rounding residue on the base), and the change.
     * Null when the plan does not cover the room type.
     */
    function rh_rate_plan_breakdown(array $plan, int $roomId, float $standard, ?float $factor = null, string $cur = 'MWK'): ?array
    {
        $eff = rh_rate_plan_effective($plan, $roomId);
        if ($eff === null) {
            return null;
        }
        $new = round(rh_rate_plan_adjust($eff, $standard, $factor), 2);
        $b = rh_stay_totals($standard, 'final');
        $a = rh_stay_totals($new, 'final');
        $diff = round($new - $standard, 2);
        return [
            'adjustment'      => $eff,
            'adjustment_text' => rh_rate_plan_adjustment_text($eff, $cur),
            'standard'        => round($standard, 2),
            'before'          => ['base' => $b['net'], 'vat' => $b['vat'], 'levy' => $b['levy'], 'total' => $b['total_with_vat']],
            'new'             => $new,
            'after'           => ['base' => $a['net'], 'vat' => $a['vat'], 'levy' => $a['levy'], 'total' => $a['total_with_vat']],
            'diff'            => $diff,
            'pct'             => $standard > 0 ? round($diff / $standard * 100, 2) : null,
            'vat_rate'        => $a['vat_rate'],
            'levy_rate'       => $a['levy_rate'],
        ];
    }

    /** One History line for a room type: "+10% (100,000 -> 110,000)"; '' when the plan does not cover it. */
    function rh_rate_plan_room_hist_text(array $plan, int $roomId, float $standard, ?float $factor = null, string $cur = 'MWK'): string
    {
        $eff = rh_rate_plan_effective($plan, $roomId);
        if ($eff === null) {
            return '';
        }
        $new = rh_rate_plan_adjust($eff, $standard, $factor);
        $t = rh_rate_plan_adjustment_text($eff, $cur);
        return $standard > 0 ? $t . ' (' . rh_rate_plan_money($standard) . ' -> ' . rh_rate_plan_money($new) . ')' : $t;
    }

    /**
     * PURE. Check and clean what the Rate Plans form posted for the per-room amounts and the basis.
     *
     * @param string $mode       'same' (one amount for all) | 'per_room'
     * @param array  $posted     room_adj[<room_id>] => ['type' => ..., 'value' => ...] (a blank value = use the default)
     * @param array  $coveredIds the room type ids the plan covers (applies_to 'room_types'), or null for 'all'
     * @param array  $rooms      [room_id => ['name' => string, 'price' => float lowest price it sells at]] (active types)
     * @return array{error:string, overrides:?array, basis:string}  overrides null = "same for all" (saved as NULL)
     */
    function rh_rate_plan_validate_rooms(string $defType, float $defValue, string $mode, array $posted, ?array $coveredIds,
                                         array $rooms, string $basis, float $factor, string $cur = 'MWK'): array
    {
        $basis = ($basis === 'base' && rh_rate_plan_basis_matters($factor)) ? 'base' : 'all_in';
        $fail = static function (string $msg) use ($basis): array {
            return ['error' => $msg, 'overrides' => null, 'basis' => $basis];
        };
        $overrides = [];
        if ($mode === 'per_room') {
            foreach ($posted as $rid => $row) {
                $rid = (int)$rid;
                if ($rid <= 0 || !is_array($row)) {
                    continue;
                }
                if ($coveredIds !== null && !in_array($rid, $coveredIds, true)) {
                    continue; // not one of the chosen room types: dropped
                }
                if (!isset($rooms[$rid])) {
                    return $fail('One of the room types is no longer active. Reopen the plan and try again.');
                }
                $raw = trim((string)($row['value'] ?? ''));
                if ($raw === '') {
                    continue; // blank = this room type uses the plan's own amount
                }
                $name = (string)$rooms[$rid]['name'];
                $type = in_array($row['type'] ?? '', ['percentage', 'fixed'], true) ? (string)$row['type'] : 'percentage';
                if (!is_numeric($raw) || abs(round((float)$raw, 2)) < 0.000001) {
                    return $fail('The amount for ' . $name . ' must be a non-zero number (leave it empty to use the plan\'s own amount).');
                }
                $v = round((float)$raw, 2);
                if ($type === 'percentage' && ($v <= -100 || $v > 1000)) {
                    return $fail('The percentage for ' . $name . ' must be above -100 and at most 1000.');
                }
                if ($type === 'fixed' && abs($v) > 9999999) {
                    return $fail('The fixed amount for ' . $name . ' cannot exceed 9,999,999 either way.');
                }
                $overrides[$rid] = ['type' => $type, 'value' => $v];
            }
            ksort($overrides);
        }
        // One plan is either all increases or all discounts (B2): the default and every room type amount agree.
        foreach ($overrides as $rid => $o) {
            if (($o['value'] > 0) !== ($defValue > 0)) {
                return $fail('A plan is either an increase or a discount for every room type. ' . $rooms[$rid]['name'] . ' goes the other way from the plan\'s own amount: make a separate plan for it.');
            }
        }
        // A fixed discount may not make a room type free or below 0.
        $probe = ['adjustment_type' => $defType, 'adjustment_value' => $defValue, 'price_basis' => $basis,
            'applies_to' => $coveredIds === null ? 'all' : 'room_types', 'room_type_ids' => $coveredIds === null ? null : json_encode(array_values($coveredIds)),
            'room_adjustments' => $overrides ? json_encode($overrides) : null];
        foreach ($rooms as $rid => $info) {
            $eff = rh_rate_plan_effective($probe, (int)$rid);
            if ($eff === null || $eff['type'] !== 'fixed' || $eff['value'] >= 0 || (float)$info['price'] <= 0) {
                continue;
            }
            $price = (float)$info['price'];
            if (rh_rate_plan_adjust($eff, $price, $factor) <= (defined('BALANCE_TOLERANCE') ? BALANCE_TOLERANCE : 0.01)) {
                return $fail('The fixed discount for ' . $info['name'] . ' (' . rh_rate_plan_adjustment_text($eff, $cur) . ') would make it free: its lowest price is '
                    . $cur . ' ' . rh_rate_plan_money($price) . '.');
            }
        }
        return ['error' => '', 'overrides' => $overrides ?: null, 'basis' => $basis];
    }

    /**
     * Return all active rate plans ordered by priority DESC.
     * Cached in a static variable for the request lifetime.
     */
    function _fetchActiveRatePlans(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $st = $pdo->prepare(
            "SELECT * FROM rate_plans WHERE is_active = 1 ORDER BY priority DESC, id ASC"
        );
        $st->execute();
        $cache = $st->fetchAll(PDO::FETCH_ASSOC);
        return $cache;
    }

    /** Check whether a rate plan applies to a given room_id */
    function _planAppliesToRoom(array $plan, int $room_id): bool
    {
        if ($plan['applies_to'] === 'all') {
            return true;
        }
        if (empty($plan['room_type_ids'])) {
            return false;
        }
        $ids = json_decode($plan['room_type_ids'], true);
        if (!is_array($ids)) {
            return false;
        }
        return in_array($room_id, array_map('intval', $ids), true);
    }

    /** Check whether a rate plan's rule matches the requested stay */
    function _planMatchesStay(
        array    $plan,
        DateTime $checkIn,
        DateTime $checkOut,
        int      $nights,
        int      $daysUntilArrival
    ): bool {

        switch ($plan['rule_type']) {

            case 'seasonal':
                if (empty($plan['start_date']) || empty($plan['end_date'])) {
                    return false;
                }
                $planStart = new DateTime($plan['start_date']);
                $planEnd   = new DateTime($plan['end_date']);
                // Match if check-in falls within the seasonal window
                return $checkIn >= $planStart && $checkIn <= $planEnd;

            case 'weekend':
                if (empty($plan['days_of_week'])) {
                    return false;
                }
                $targetDays = array_map('intval', explode(',', $plan['days_of_week']));
                // True if any night of the stay lands on a target day-of-week
                $cursor = clone $checkIn;
                while ($cursor < $checkOut) {
                    if (in_array((int)$cursor->format('w'), $targetDays, true)) {
                        return true;
                    }
                    $cursor->modify('+1 day');
                }
                return false;

            case 'los_discount':
                $min = isset($plan['min_nights']) ? (int)$plan['min_nights'] : 1;
                $max = isset($plan['max_nights']) && $plan['max_nights'] > 0 ? (int)$plan['max_nights'] : PHP_INT_MAX;
                return $nights >= $min && $nights <= $max;

            case 'last_minute':
                $min = isset($plan['days_before_min']) ? (int)$plan['days_before_min'] : 0;
                $max = isset($plan['days_before_max']) && $plan['days_before_max'] !== null ? (int)$plan['days_before_max'] : PHP_INT_MAX;
                return $daysUntilArrival >= $min && $daysUntilArrival <= $max;

            case 'early_bird':
                $min = isset($plan['days_before_min']) ? (int)$plan['days_before_min'] : 0;
                $max = isset($plan['days_before_max']) && $plan['days_before_max'] !== null ? (int)$plan['days_before_max'] : PHP_INT_MAX;
                return $daysUntilArrival >= $min && $daysUntilArrival <= $max;

            case 'promotion':
                // Always active; admin controls via is_active flag
                return true;

            default:
                return false;
        }
    }

    /**
     * Return active packages available for a given room type.
     *
     * @param PDO $pdo
     * @param int $room_id  rooms.id (room type)
     * @return array  Array of room_packages rows with inclusions decoded
     */
    function getActivePackages(PDO $pdo, int $room_id): array
    {
        try {
            $st = $pdo->prepare(
                "SELECT * FROM room_packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
            );
            $st->execute();
            $all = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[pricing] DB error fetching packages: ' . $e->getMessage());
            return [];
        }

        $result = [];
        foreach ($all as $pkg) {
            // Filter by room applicability
            if ($pkg['applies_to'] === 'room_types') {
                if (empty($pkg['room_type_ids'])) {
                    continue;
                }
                $ids = json_decode($pkg['room_type_ids'], true);
                if (!is_array($ids) || !in_array($room_id, array_map('intval', $ids), true)) {
                    continue;
                }
            }
            // Decode inclusions JSON
            if (!empty($pkg['inclusions'])) {
                $decoded = json_decode($pkg['inclusions'], true);
                $pkg['inclusions_list'] = is_array($decoded) ? $decoded : [];
            } else {
                $pkg['inclusions_list'] = [];
            }
            $result[] = $pkg;
        }
        return $result;
    }

    /**
     * Calculate the total cost of a package for a booking.
     *
     * @param array $pkg           room_packages row
     * @param int   $nights        Number of nights
     * @param int   $adult_guests  Adult guest count
     * @return float
     */
    function calculatePackageCost(array $pkg, int $nights, int $adult_guests): float
    {
        $amount = (float)$pkg['price_amount'];
        switch ($pkg['price_type']) {
            case 'per_night':
                return round($amount * max(1, $nights), 2);
            case 'per_stay':
                return round($amount, 2);
            case 'per_person_per_night':
                return round($amount * max(1, $adult_guests) * max(1, $nights), 2);
            default:
                return round($amount, 2);
        }
    }

    /**
     * Build a preview payload for the check-availability AJAX endpoint.
     * Returns dynamic pricing details and available packages.
     */
    function getDynamicPricingPreview(
        PDO    $pdo,
        int    $room_id,
        string $check_in,
        string $check_out,
        int    $nights,
        float  $base_price
    ): array {

        $pricing  = applyDynamicPricing($pdo, $room_id, $check_in, $check_out, $nights, $base_price);
        $packages = getActivePackages($pdo, $room_id);

        $pkgOut = [];
        foreach ($packages as $pkg) {
            $pkgOut[] = [
                'id'                => (int)$pkg['id'],
                'name'              => $pkg['name'],
                'short_description' => $pkg['short_description'] ?? '',
                'icon'              => $pkg['icon'] ?? 'fas fa-gift',
                'price_type'        => $pkg['price_type'],
                'price_amount'      => (float)$pkg['price_amount'],
                'inclusions'        => $pkg['inclusions_list'] ?? [],
                'is_featured'       => (int)$pkg['is_featured'],
            ];
        }

        return [
            'dynamic_pricing' => [
                'original_price'  => $pricing['original_price'],
                'final_price'     => $pricing['final_price'],
                'discount_amount' => $pricing['discount_amount'],
                'rate_plan_id'    => $pricing['rate_plan_id'],
                'rate_plan_label' => $pricing['rate_plan_label'],
                'has_discount'    => $pricing['discount_amount'] > 0,
                'has_surcharge'   => $pricing['discount_amount'] < 0,
            ],
            'packages' => $pkgOut,
        ];
    }
}

if (!function_exists('rh_stay_totals_with_package')) {
    /**
     * Split an all-in stay amount the way booking.php saves it: the room part (room + children) carries the tourism
     * levy, the package part does not (rh_stay_totals($pkg, 'price', false)); the two are then added up.
     * total_with_vat is the same as one rh_stay_totals($gross) call; only net / VAT / levy move.
     * With no package this IS rh_stay_totals($gross, 'final', $applyLevy) (byte-identical).
     *
     * @param float $gross        the whole stay, all-in (room + children + packages)
     * @param float $packageGross the package part inside $gross (rh_booked_stay_split()['package_gross'])
     */
    function rh_stay_totals_with_package(float $gross, float $packageGross, bool $applyLevy): array
    {
        if ($packageGross <= 0.004) {
            return rh_stay_totals($gross, 'final', $applyLevy);
        }
        $gross = max(0.0, $gross);
        $pkg   = round(min($packageGross, $gross), 2);
        $room  = rh_stay_totals(max(0.0, round($gross - $pkg, 2)), 'final', $applyLevy);
        $pack  = rh_stay_totals($pkg, 'price', false);
        return [
            'net'            => round($room['net'] + $pack['net'], 2),
            'vat'            => round($room['vat'] + $pack['vat'], 2),
            'levy'           => $room['levy'],
            'total_with_vat' => round($room['total_with_vat'] + $pack['total_with_vat'], 2),
            'vat_rate'       => $room['vat_rate'],
            'levy_rate'      => $room['levy_rate'],
        ];
    }
}

/*
 * Room packages on a booking (owner 2026-11-26). booking_packages rows keep the price the guest booked at
 * (price_type, price_amount); when the stay changes, per-night and per-person-per-night packages are worked out
 * again from that price, per-stay packages stay as they are. A package's units are what calculatePackageCost()
 * multiplies by: per night = nights, per person per night = persons x nights, per stay = 1.
 */
if (!function_exists('rh_pkg_units')) {
    /** PURE. Units a room package is charged for (the saved booking_packages.quantity). */
    function rh_pkg_units(string $type, int $nights, int $persons): int
    {
        $n = max(1, $nights);
        if ($type === 'per_night') {
            return $n;
        }
        if ($type === 'per_person_per_night') {
            return max(1, $persons) * $n;
        }
        return 1;
    }
}

if (!function_exists('rh_pkg_row_units')) {
    /**
     * PURE. Units a saved booking_packages row was charged for: total_cost / price_amount when that is a whole number
     * (rows saved before 2026-11-26 all say quantity 1), else the saved quantity.
     */
    function rh_pkg_row_units(array $row): int
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $price = (float)($row['price_amount'] ?? 0);
        $total = (float)($row['total_cost'] ?? 0);
        if ($price > $tol) {
            $u = (int)round($total / $price);
            if ($u >= 1 && abs($u * $price - $total) <= $tol) {
                return $u;
            }
        }
        return max(1, (int)($row['quantity'] ?? 1));
    }
}

if (!function_exists('rh_pkg_label')) {
    /**
     * PURE. How a package line adds up, e.g. "MWK 50,000.00 per night × 2 nights = MWK 100,000.00",
     * "MWK 20,000.00 per person per night × 3 guests × 3 nights = MWK 180,000.00", "MWK 30,000.00 per stay".
     * $nights: the nights the row was charged for (the lead room's); 0 = unknown (a per-person row then reads "× 9").
     * $money: formats an amount; default "<currency> 1,234.00".
     */
    function rh_pkg_label(array $row, int $nights = 0, ?callable $money = null, bool $withTotal = true, string $currency = 'MWK'): string
    {
        $m = $money ?? static function (float $v) use ($currency): string {
            return $currency . ' ' . number_format($v, 2);
        };
        $type = (string)($row['price_type'] ?? '');
        $price = (float)($row['price_amount'] ?? 0);
        $total = round((float)($row['total_cost'] ?? 0), 2);
        $units = rh_pkg_row_units($row);
        $nw = static function (int $n): string {
            return $n === 1 ? '1 night' : $n . ' nights';
        };
        if ($type === 'per_night') {
            $s = $m($price) . ' per night × ' . $nw($units);
        } elseif ($type === 'per_person_per_night') {
            if ($nights > 0 && $units % $nights === 0) {
                $p = intdiv($units, $nights);
                $s = $m($price) . ' per person per night × ' . ($p === 1 ? '1 guest' : $p . ' guests') . ' × ' . $nw($nights);
            } else {
                $s = $m($price) . ' per person per night × ' . $units;
            }
        } else {
            return $m($total > 0 ? $total : $price) . ' per stay';
        }
        return $withTotal ? $s . ' = ' . $m($total) : $s;
    }
}

if (!function_exists('rh_pkg_requote')) {
    /**
     * PURE. Work a booking's package rows out again for $nights (the lead room's nights) and $persons (the party's
     * adults), from each row's saved price_type / price_amount (calculatePackageCost() rounding). Per-stay rows are
     * kept as saved. Amounts are on the price basis, like booking_packages.total_cost and bookings.package_total.
     * $oldNights: the nights the rows were charged for (only for the wording of the "was" side).
     *
     * @return array{rows:array,old_total:float,new_total:float,changed:bool,lines:array<int,string>}
     *   rows: [id, package_name, price_type, price_amount, old_quantity, old_total, new_quantity, new_total, changed]
     *   lines: "Breakfast: MWK 50,000.00 per night × 5 nights = MWK 250,000.00 → MWK 50,000.00 per night × 2 nights = ..."
     */
    function rh_pkg_requote(array $rows, int $nights, int $persons, ?callable $money = null, string $currency = 'MWK', int $oldNights = 0): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $out = ['rows' => [], 'old_total' => 0.0, 'new_total' => 0.0, 'changed' => false, 'lines' => []];
        foreach ($rows as $r) {
            $type = (string)($r['price_type'] ?? '');
            $price = round((float)($r['price_amount'] ?? 0), 2);
            $oldTotal = round((float)($r['total_cost'] ?? 0), 2);
            $oldQty = (int)($r['quantity'] ?? 1);
            $newQty = $oldQty;
            $newTotal = $oldTotal;
            if (in_array($type, ['per_night', 'per_person_per_night'], true) && $price > $tol) {
                $newQty = rh_pkg_units($type, $nights, $persons);
                $newTotal = round($price * $newQty, 2);
            }
            $changed = abs($newTotal - $oldTotal) > $tol;
            $row = [
                'id' => (int)($r['id'] ?? 0), 'package_name' => (string)($r['package_name'] ?? ''), 'price_type' => $type,
                'price_amount' => $price, 'old_quantity' => $oldQty, 'old_total' => $oldTotal,
                'new_quantity' => $changed ? $newQty : $oldQty, 'new_total' => $changed ? $newTotal : $oldTotal, 'changed' => $changed,
            ];
            if ($changed) {
                $was = rh_pkg_label($r, $oldNights, $money, true, $currency);
                $now = rh_pkg_label(['price_type' => $type, 'price_amount' => $price, 'total_cost' => $newTotal, 'quantity' => $newQty], $nights, $money, true, $currency);
                $out['lines'][] = ($row['package_name'] !== '' ? $row['package_name'] : 'Package') . ': ' . $was . ' → ' . $now;
                $out['changed'] = true;
            }
            $out['rows'][] = $row;
            $out['old_total'] += $oldTotal;
            $out['new_total'] += $row['new_total'];
        }
        $out['old_total'] = round($out['old_total'], 2);
        $out['new_total'] = round($out['new_total'], 2);
        return $out;
    }
}

if (!function_exists('rh_package_allowed_for_type')) {
    /**
     * PURE. Whether a room_packages row (applies_to, room_type_ids) is offered for a room type. is_active is ignored
     * (a package switched off is not a room-type question); a package no longer in the catalogue (deleted) = allowed.
     */
    function rh_package_allowed_for_type(?array $catalogue, int $roomTypeId): bool
    {
        if ($catalogue === null || (string)($catalogue['applies_to'] ?? 'all') !== 'room_types') {
            return true;
        }
        $ids = json_decode((string)($catalogue['room_type_ids'] ?? ''), true);
        return is_array($ids) && in_array($roomTypeId, array_map('intval', $ids), true);
    }
}

if (!function_exists('rh_packages_for_booking')) {
    /**
     * The packages a guest ticked, checked again at submit (fault C, owner 2026-11-26): each id must still be active
     * and offered for $roomTypeId. Returns the rows to save (quantity = units), their total (price basis) and the
     * names of any that are no longer available ("a selected package" for one deleted meanwhile).
     *
     * @return array{rows:array,total:float,missing:array<int,string>}
     */
    function rh_packages_for_booking(PDO $pdo, int $roomTypeId, array $ids, int $nights, int $adults): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($v) {
            return $v > 0;
        })));
        $out = ['rows' => [], 'total' => 0.0, 'missing' => []];
        if (!$ids) {
            return $out;
        }
        $byId = [];
        foreach (getActivePackages($pdo, $roomTypeId) as $p) {
            $byId[(int)$p['id']] = $p;
        }
        $missingIds = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                $missingIds[] = $id;
                continue;
            }
            $p = $byId[$id];
            $cost = calculatePackageCost($p, $nights, $adults);
            $out['rows'][] = [
                'package_id' => $id,
                'package_name' => (string)$p['name'],
                'price_type' => (string)$p['price_type'],
                'price_amount' => (float)$p['price_amount'],
                'quantity' => rh_pkg_units((string)$p['price_type'], $nights, $adults),
                'total_cost' => $cost,
            ];
            $out['total'] += $cost;
        }
        $out['total'] = round($out['total'], 2);
        if ($missingIds) {
            $names = [];
            try {
                $st = $pdo->prepare('SELECT id, name FROM room_packages WHERE id IN (' . implode(',', array_fill(0, count($missingIds), '?')) . ')');
                $st->execute($missingIds);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $names[(int)$r['id']] = (string)$r['name'];
                }
            } catch (PDOException $e) {
                error_log('[pricing] package name lookup failed: ' . $e->getMessage());
            }
            foreach ($missingIds as $id) {
                $out['missing'][] = $names[$id] ?? 'a selected package';
            }
        }
        return $out;
    }
}

if (!function_exists('rh_package_unavailable_message')) {
    /** PURE. The guest message when ticked packages are no longer available (nothing is booked). */
    function rh_package_unavailable_message(array $names): string
    {
        $names = array_values(array_unique(array_map('strval', $names)));
        $named = array_values(array_filter($names, static function ($n) {
            return $n !== 'a selected package';
        }));
        if (!$named) {
            $what = count($names) > 1 ? 'Some of the packages you selected are' : 'A package you selected is';
        } else {
            $list = count($named) > 1
                ? implode(', ', array_slice($named, 0, -1)) . ' and ' . end($named)
                : $named[0];
            $what = 'The ' . $list . (count($named) > 1 ? ' packages are' : ' package is');
        }
        return $what . ' no longer available for this room. Please untick it, check your extras and submit again. Nothing has been booked.';
    }
}

if (!function_exists('rh_rate_plan_dropped')) {
    /**
     * PURE. Owner 2026-11-10 (RP-3 b): when STAFF change a booking's dates or nights, a length-of-stay, weekend or
     * seasonal DISCOUNT is removed if the new stay no longer qualifies. Returns the per-night amount to add back to
     * the booked rate (0 = keep the discount), the plan and a plain reason.
     *
     * Added back only when ALL hold: rate_plan_discount > BALANCE_TOLERANCE; the plan row still exists; the stored
     * label is that plan's name alone (a stacked label is kept); rule_type is los_discount / weekend / seasonal; and the
     * plan no longer matches the new stay (_planMatchesStay) or no longer covers the room type (_planAppliesToRoom).
     * Early bird, last minute and promotion discounts are kept (they depend on when the guest booked), as is a
     * discount whose plan was deleted. Never adds a discount, never adds a surcharge.
     * Not for early / late check-out (owner 2026-10-01: those keep the booked rate).
     *
     * @param array      $b    bookings row (rate_plan_id, rate_plan_label, rate_plan_discount, room_id)
     * @param array|null $plan rate_plans row for $b['rate_plan_id'] (null = deleted)
     * @return array{per_night:float,removed:bool,plan_id:?int,plan_name:string,reason:string}
     */
    function rh_rate_plan_drop_check(array $b, ?array $plan, string $newIn, string $newOut, int $nights, int $roomTypeId): array
    {
        $keep = ['per_night' => 0.0, 'removed' => false, 'plan_id' => null, 'plan_name' => '', 'reason' => ''];
        if (!rh_rate_plan_drop_eligible($b, $plan)) {
            return $keep;
        }
        $reason = rh_rate_plan_drop_reason($plan, $newIn, $newOut, $nights, $roomTypeId);
        if ($reason === '') {
            return $keep;
        }
        return [
            'per_night' => round((float)$b['rate_plan_discount'], 2),
            'removed'   => true,
            'plan_id'   => (int)$plan['id'],
            'plan_name' => (string)$plan['name'],
            'reason'    => $reason,
        ];
    }

    /** PURE. The parts of the rule that do not depend on the new stay (see rh_rate_plan_drop_check()). */
    function rh_rate_plan_drop_eligible(array $b, ?array $plan): bool
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        if ((float)($b['rate_plan_discount'] ?? 0) <= $tol || (int)($b['rate_plan_id'] ?? 0) <= 0 || !$plan) {
            return false;
        }
        if ((int)($plan['id'] ?? 0) !== (int)$b['rate_plan_id']) {
            return false;
        }
        // A single plan only: "Long stay, Weekend" (stacked) is kept (ASSUMPTION RP-3).
        if (trim((string)($b['rate_plan_label'] ?? '')) !== trim((string)($plan['name'] ?? ''))) {
            return false;
        }
        return in_array((string)($plan['rule_type'] ?? ''), ['los_discount', 'weekend', 'seasonal'], true);
    }

    /** PURE. '' when the plan still matches the stay and the room type, else a plain reason why it does not. */
    function rh_rate_plan_drop_reason(array $plan, string $newIn, string $newOut, int $nights, int $roomTypeId): string
    {
        if (!_planAppliesToRoom($plan, $roomTypeId)) {
            return 'the plan no longer covers this room type';
        }
        $in = new DateTime(substr($newIn, 0, 10));
        $out = new DateTime(substr($newOut, 0, 10));
        if (_planMatchesStay($plan, $in, $out, $nights, 0)) {
            return '';
        }
        $nWord = $nights . ' night' . ($nights === 1 ? '' : 's');
        switch ((string)$plan['rule_type']) {
            case 'los_discount':
                $min = isset($plan['min_nights']) ? (int)$plan['min_nights'] : 1;
                $max = isset($plan['max_nights']) && $plan['max_nights'] > 0 ? (int)$plan['max_nights'] : 0;
                if ($nights < $min) {
                    return 'the stay is now ' . $nWord . ' and the plan needs at least ' . $min . ' night' . ($min === 1 ? '' : 's');
                }
                return 'the stay is now ' . $nWord . ' and the plan allows at most ' . $max . ' night' . ($max === 1 ? '' : 's');
            case 'weekend':
                $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                $days = [];
                foreach (array_map('intval', explode(',', (string)($plan['days_of_week'] ?? ''))) as $d) {
                    if (isset($names[$d])) {
                        $days[] = $names[$d];
                    }
                }
                return 'no night of the new stay falls on ' . ($days ? implode(', ', $days) : 'the plan\'s days');
            case 'seasonal':
            default:
                $s = !empty($plan['start_date']) ? date('j M Y', strtotime((string)$plan['start_date'])) : '';
                $e = !empty($plan['end_date']) ? date('j M Y', strtotime((string)$plan['end_date'])) : '';
                return 'the new check-in date (' . date('j M Y', strtotime(substr($newIn, 0, 10))) . ') is outside the plan\'s dates'
                    . ($s !== '' && $e !== '' ? ' (' . $s . ' to ' . $e . ')' : '');
        }
    }

    /** The plan row by id (any status: a switched-off plan still exists), or null when it was deleted. */
    function rh_rate_plan_row(PDO $pdo, int $planId): ?array
    {
        if ($planId <= 0) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM rate_plans WHERE id = ?');
        $st->execute([$planId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** rh_rate_plan_drop_check() with the plan read from the database (no query when the booking has no discount). */
    function rh_rate_plan_dropped(PDO $pdo, array $b, string $newIn, string $newOut, int $nights, ?int $roomTypeId = null): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $plan = ((float)($b['rate_plan_discount'] ?? 0) > $tol) ? rh_rate_plan_row($pdo, (int)($b['rate_plan_id'] ?? 0)) : null;
        return rh_rate_plan_drop_check($b, $plan, $newIn, $newOut, max(1, $nights), $roomTypeId ?? (int)($b['room_id'] ?? 0));
    }

    /**
     * What the Edit Booking preview needs to apply the same rule in the browser before saving, or null when the
     * booking's discount can never be removed by a date change (not eligible).
     */
    function rh_rate_plan_drop_js(PDO $pdo, array $b, ?int $roomTypeId = null): ?array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        if ((float)($b['rate_plan_discount'] ?? 0) <= $tol) {
            return null;
        }
        $plan = rh_rate_plan_row($pdo, (int)($b['rate_plan_id'] ?? 0));
        if (!rh_rate_plan_drop_eligible($b, $plan)) {
            return null;
        }
        $rt = $roomTypeId ?? (int)($b['room_id'] ?? 0);
        return [
            'perNight'      => round((float)$b['rate_plan_discount'], 2),
            'name'          => (string)$plan['name'],
            'rule'          => (string)$plan['rule_type'],
            'minNights'     => isset($plan['min_nights']) ? (int)$plan['min_nights'] : 1,
            'maxNights'     => isset($plan['max_nights']) && $plan['max_nights'] > 0 ? (int)$plan['max_nights'] : 0,
            'start'         => !empty($plan['start_date']) ? substr((string)$plan['start_date'], 0, 10) : '',
            'end'           => !empty($plan['end_date']) ? substr((string)$plan['end_date'], 0, 10) : '',
            'days'          => !empty($plan['days_of_week']) ? array_map('intval', explode(',', (string)$plan['days_of_week'])) : [],
            'appliesToRoom' => _planAppliesToRoom($plan, $rt),
        ];
    }

    /** Clear the removed plan from the booking row (inside the caller's transaction). */
    function rh_rate_plan_drop_clear(PDO $pdo, int $bookingId): void
    {
        $pdo->prepare('UPDATE bookings SET rate_plan_id = NULL, rate_plan_label = NULL, rate_plan_discount = NULL, updated_at = NOW() WHERE id = ?')
            ->execute([$bookingId]);
    }

    /**
     * One sentence for staff messages: "The Long stay discount was removed: the stay is now 3 nights ...".
     * HTML-escaped: every caller puts it in a flash / JSON message that Alert.show() inserts as HTML, and the plan name
     * is typed by staff. History (rh_rate_plan_drop_log) keeps its own plain-text note.
     */
    function rh_rate_plan_drop_sentence(array $drop): string
    {
        if (empty($drop['removed'])) {
            return '';
        }
        $e = static function ($v): string {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        };
        return 'The ' . $e($drop['plan_name']) . ' discount was removed: ' . $e($drop['reason']) . '.';
    }

    /**
     * History (booking_audit_log via logBookingAudit, written after the caller commits): who, the plan, old -> new
     * nightly rate and total. Never fatal.
     */
    function rh_rate_plan_drop_log(int $bookingId, ?string $ref, array $drop, float $oldNight, float $newNight, float $oldTotal, float $newTotal, string $who): void
    {
        if (empty($drop['removed']) || !function_exists('logBookingAudit')) {
            return;
        }
        $cur = function_exists('getSetting') ? (string)getSetting('currency_symbol', 'MWK') : 'MWK';
        $m = static function (float $v) use ($cur): string {
            return function_exists('rh_hist_money') ? rh_hist_money($v, $cur) : $cur . ' ' . number_format($v, 2);
        };
        $note = 'Discount ' . $drop['plan_name'] . ' removed by ' . ($who !== '' ? $who : 'staff') . ' after a date change: ' . $drop['reason'] . '.'
            . ' Nightly rate ' . $m(round($oldNight, 2)) . ' → ' . $m(round($newNight, 2))
            . ' (discount of ' . $m((float)$drop['per_night']) . ' a night added back);'
            . ' total ' . $m(round($oldTotal, 2)) . ' → ' . $m(round($newTotal, 2)) . '.';
        try {
            logBookingAudit($bookingId, 'discount_removed',
                ['rate_plan_label' => $drop['plan_name'], 'total_with_vat' => round($oldTotal, 2)],
                ['rate_plan_label' => 'None', 'total_with_vat' => round($newTotal, 2)],
                $note, $ref);
        } catch (Throwable $e) {
            error_log('rh_rate_plan_drop_log (booking ' . $bookingId . '): ' . $e->getMessage());
        }
    }
}

if (!function_exists('rh_doc_row_rates')) {
    /**
     * PURE. The VAT and levy rates (fractions) one bookings row was priced with, to split its all-in child and package
     * amounts into nets: the row's stored vat_rate / tourism_levy_percent, else worked out from the row's own amounts
     * (VAT is on every net, the levy on the room + child nets; packages carry VAT only). Never today's settings.
     * $netAll = gross - VAT - levy (room + child + packages, all net); $vatIn = the VAT inside the gross (0 when VAT is
     * off); $pkgAllIn = the packages all-in. pkg_net = the packages' net.
     *
     * @return array{vat:float,levy:float,pkg_net:float}
     */
    function rh_doc_row_rates(array $b, float $netAll, float $vatIn, float $levy, float $pkgAllIn): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $v = 0.0;
        if ($vatIn > $tol) {
            $v = max(0.0, (float)($b['vat_rate'] ?? 0)) / 100;
            if ($v <= 0 && $netAll > $tol) {
                $v = $vatIn / $netAll;
            }
        }
        $pkgNet = $pkgAllIn > $tol ? round($pkgAllIn / (1 + $v), 2) : 0.0;
        $l = 0.0;
        if ($levy > $tol) {
            $l = max(0.0, (float)($b['tourism_levy_percent'] ?? 0)) / 100;
            if ($l <= 0 && $netAll - $pkgNet > $tol) {
                $l = $levy / ($netAll - $pkgNet);
            }
        }
        return ['vat' => $v, 'levy' => $l, 'pkg_net' => $pkgNet];
    }
}

if (!function_exists('rh_doc_pkg_rows_on_basis')) {
    /**
     * PURE. booking_packages rows (price_amount, total_cost: all-in as stored) on the same basis as the document's
     * package subtotal ($docPkg = rh_doc_stay_lines(...)['pkg'], $pkgAllIn = the package_total passed to it).
     * Exclusive mode prints net lines, so each row is scaled by docPkg / pkgAllIn and, when the rows add up to the
     * stored package_total, the last row takes the rounding so the rows foot to $docPkg exactly. Any other mode, or
     * nothing to scale: the rows unchanged.
     */
    function rh_doc_pkg_rows_on_basis(array $rows, string $mode, float $docPkg, float $pkgAllIn): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        if ($mode !== 'exclusive' || !$rows || $docPkg <= $tol || $pkgAllIn <= $tol || abs($docPkg - $pkgAllIn) <= $tol / 2) {
            return $rows;
        }
        $f = $docPkg / $pkgAllIn;
        $allIn = 0.0;
        $sum = 0.0;
        $last = null;
        foreach ($rows as $k => $r) {
            $allIn += (float)($r['total_cost'] ?? 0);
            $rows[$k]['price_amount'] = round((float)($r['price_amount'] ?? 0) * $f, 2);
            $rows[$k]['total_cost'] = round((float)($r['total_cost'] ?? 0) * $f, 2);
            $sum += $rows[$k]['total_cost'];
            $last = $k;
        }
        if ($last !== null && abs($allIn - $pkgAllIn) <= $tol) {
            $rows[$last]['total_cost'] = round($rows[$last]['total_cost'] + round($docPkg - $sum, 2), 2);
        }
        return $rows;
    }
}

if (!function_exists('rh_doc_stay_lines')) {
    /**
     * The room figures a guest DOCUMENT (quotation, invoice, statement) prints for one booking row, built from the
     * stored columns so the printed lines foot to the booking total exactly (no recomputing from net).
     *
     * Gross basis = total_with_vat, falling back to total_amount + vat_amount (the same basis rh_booked_stay_split()
     * and rh_booking_gross_sql() use). total_amount is net + levy, so the display amounts are:
     *   inclusive / off : gross - levy   (VAT sits inside the lines; levy is its own line)
     *   exclusive       : gross - VAT - levy   (net lines; VAT is its own line)
     * The child supplement and packages are stored ALL-IN in every mode (owner 2026-10-24: price = net x (1 + VAT +
     * levy); the levy is on the room and child nets only, packages carry VAT only). inclusive / off: they print as
     * stored. exclusive: they print as their nets, child / (1 + v + l) and package / (1 + v), with the row's own rates
     * (rh_doc_row_rates: the stored vat_rate / tourism_levy_percent, else worked out from the row's amounts, never
     * today's settings), so every line is on the same net basis. The room line is what remains, so the lines foot.
     *
     * @param array $b           bookings row (total_amount, vat_amount, total_with_vat, tourism_levy_amount, child_supplement_total,
     *                           vat_rate, tourism_levy_percent)
     * @param float $pkgPriceTotal package add-ons inside this row, all-in as stored (package_total; primary booking only)
     * @return array{mode:string,gross:float,vat:float,levy:float,lines_total:float,room:float,child:float,pkg:float,subtotal:float,total:float}
     */
    function rh_doc_stay_lines(array $b, float $pkgPriceTotal = 0.0): array
    {
        $mode  = function_exists('vat_mode') ? vat_mode() : 'off';
        $gross = (float)($b['total_with_vat'] ?? 0);
        if ($gross <= 0) {
            $gross = (float)($b['total_amount'] ?? 0) + (float)($b['vat_amount'] ?? 0);
        }
        $vat   = max(0.0, (float)($b['vat_amount'] ?? 0));
        $levy  = max(0.0, (float)($b['tourism_levy_amount'] ?? 0));
        $lines = round($gross - $levy - ($mode === 'exclusive' ? $vat : 0.0), 2);
        $child = max(0.0, (float)($b['child_supplement_total'] ?? 0));
        $pkg   = max(0.0, $pkgPriceTotal);
        if ($mode === 'exclusive' && ($child > 0 || $pkg > 0)) {
            $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
            $rt = rh_doc_row_rates($b, $lines, $vat, $levy, $pkg);
            $pkg = $rt['pkg_net'];
            $child = $child > $tol ? round($child / (1 + $rt['vat'] + $rt['levy']), 2) : 0.0;
        }
        $room  = round($lines - $child - $pkg, 2);
        if ($room < 0) {
            // Stored child/package figures larger than the booking (legacy rows): fold into the room line.
            $room = round($lines - $pkg, 2);
            $child = 0.0;
            if ($room < 0) {
                $room = $lines;
                $pkg = 0.0;
            }
        }
        return [
            'mode' => $mode, 'gross' => round($gross, 2), 'vat' => $vat, 'levy' => $levy,
            'lines_total' => $lines, 'room' => $room, 'child' => $child, 'pkg' => $pkg,
            'subtotal' => $lines, 'total' => round($gross, 2),
        ];
    }
}

/*
 * Child charge rules (owner decision 2026-10-22): ONE rule, used by every booking path and document.
 *
 * Managers set, per room type and per individual room (the room overrides the type):
 *   - how children are charged: a % of the room rate (child_price_multiplier) or a fixed amount per child per night
 *     (child_charge_amount);
 *   - free_children: the first N children in a room are free;
 *   - max_children: most children in one room (NULL = no limit beyond the room's guest capacity).
 * 0% and a fixed amount of 0 are both valid and mean free.
 *
 * A rule is a plain array: mode ('percent'|'fixed'), percent, amount, free, max (int|null), source ('type'|'room').
 * A saved booking keeps the rule it was priced with (bookings.child_price_multiplier / child_charge_mode /
 * child_charge_amount / free_children): rh_child_rule_from_booking().
 */
if (!function_exists('rh_child_rule')) {
    /** Default child percentage when a room type row carries none (the old site-wide setting, else 50). */
    function rh_child_default_percent(): float
    {
        if (function_exists('getSetting')) {
            return max(0.0, (float)getSetting('booking_child_price_multiplier', getSetting('child_guest_price_multiplier', 50)));
        }
        return 50.0;
    }

    function rh_child_clean_mode($mode): ?string
    {
        $mode = is_string($mode) ? strtolower(trim($mode)) : '';
        return ($mode === 'percent' || $mode === 'fixed') ? $mode : null;
    }

    function rh_child_has($row, string $key): bool
    {
        return is_array($row) && array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '';
    }

    /**
     * Effective rule for a room type row (rooms.*) and, optionally, one individual room row (individual_rooms.*, whose
     * child_* columns are NULL where they inherit). Missing columns behave like a percent rule with no free children.
     */
    function rh_child_rule(array $type, ?array $individual = null): array
    {
        $rule = [
            'mode'    => rh_child_clean_mode($type['child_charge_mode'] ?? null) ?? 'percent',
            'percent' => rh_child_has($type, 'child_price_multiplier') ? max(0.0, (float)$type['child_price_multiplier']) : rh_child_default_percent(),
            'amount'  => rh_child_has($type, 'child_charge_amount') ? max(0.0, (float)$type['child_charge_amount']) : 0.0,
            'free'    => rh_child_has($type, 'free_children') ? max(0, (int)$type['free_children']) : 0,
            'max'     => rh_child_has($type, 'max_children') ? max(0, (int)$type['max_children']) : null,
            'source'  => 'type',
        ];
        if ($individual === null) {
            return $rule;
        }

        $indMode = rh_child_clean_mode($individual['child_charge_mode'] ?? null);
        if (rh_child_has($individual, 'child_price_multiplier') && ($indMode === null || $indMode === 'percent')) {
            // A room that carries its own percentage (including rooms set before fixed amounts existed).
            $rule['mode'] = 'percent';
            $rule['percent'] = max(0.0, (float)$individual['child_price_multiplier']);
            $rule['source'] = 'room';
        } elseif ($indMode === 'fixed') {
            $rule['mode'] = 'fixed';
            if (rh_child_has($individual, 'child_charge_amount')) {
                $rule['amount'] = max(0.0, (float)$individual['child_charge_amount']);
            }
            $rule['source'] = 'room';
        } elseif ($indMode === 'percent') {
            $rule['mode'] = 'percent';
            $rule['source'] = 'room';
        }
        if (rh_child_has($individual, 'free_children')) {
            $rule['free'] = max(0, (int)$individual['free_children']);
            $rule['source'] = 'room';
        }
        if (rh_child_has($individual, 'max_children')) {
            $rule['max'] = max(0, (int)$individual['max_children']);
            $rule['source'] = 'room';
        }
        return $rule;
    }

    /** Rule for a room type id (+ optional individual room id that belongs to it), read from the database. */
    function rh_child_rule_for(PDO $pdo, int $roomTypeId, ?int $individualRoomId = null): array
    {
        $st = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
        $st->execute([$roomTypeId]);
        $type = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $ind = null;
        if ($individualRoomId) {
            $st = $pdo->prepare('SELECT * FROM individual_rooms WHERE id = ? AND room_type_id = ?');
            $st->execute([$individualRoomId, $roomTypeId]);
            $ind = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return rh_child_rule($type, $ind);
    }

    /** The rule a saved booking was priced with (snapshot columns; older rows read as percent, nobody free). */
    function rh_child_rule_from_booking(array $b): array
    {
        return [
            'mode'    => rh_child_clean_mode($b['child_charge_mode'] ?? null) ?? 'percent',
            'percent' => rh_child_has($b, 'child_price_multiplier') ? max(0.0, (float)$b['child_price_multiplier']) : rh_child_default_percent(),
            'amount'  => rh_child_has($b, 'child_charge_amount') ? max(0.0, (float)$b['child_charge_amount']) : 0.0,
            'free'    => rh_child_has($b, 'free_children') ? max(0, (int)$b['free_children']) : 0,
            'max'     => null,
            'source'  => 'booking',
        ];
    }

    /** The bookings columns that store a rule (write these with the booking so it keeps its rule). */
    function rh_child_rule_snapshot(array $rule): array
    {
        return [
            'child_price_multiplier' => round((float)$rule['percent'], 2),
            'child_charge_mode'      => (string)$rule['mode'],
            'child_charge_amount'    => $rule['mode'] === 'fixed' ? round((float)$rule['amount'], 2) : null,
            'free_children'          => (int)$rule['free'],
        ];
    }

    /** Children who pay: the first N are free. */
    function rh_child_charged(array $rule, int $children): int
    {
        return max(0, max(0, $children) - (int)$rule['free']);
    }

    /** Child charge for ONE night for $children children in one room at nightly room rate $rate (not rounded). */
    function rh_child_night_charge(array $rule, int $children, float $rate): float
    {
        $charged = rh_child_charged($rule, $children);
        if ($charged <= 0) {
            return 0.0;
        }
        $each = $rule['mode'] === 'fixed' ? (float)$rule['amount'] : $rate * ((float)$rule['percent'] / 100);
        return max(0.0, $each) * $charged;
    }

    /**
     * Child supplement for one room over the stay, rounded to 2 decimals (what is saved and billed).
     * $rate = the room's nightly rate for its occupancy tier after rate plans (the % is taken off this).
     */
    function rh_child_supplement(array $rule, int $children, int $nights, float $rate): float
    {
        if ($children <= 0 || $nights <= 0) {
            return 0.0;
        }
        return round(rh_child_night_charge($rule, $children, $rate) * $nights, 2);
    }

    /**
     * Refusal text when $children cannot sleep in one room under this rule, or '' when fine.
     * $childrenAllowed is the room's own children policy (type, or individual override).
     */
    function rh_child_limit_error(array $rule, int $children, bool $childrenAllowed = true, string $roomName = 'this room'): string
    {
        if ($children <= 0) {
            return '';
        }
        if (!$childrenAllowed) {
            return "Children cannot stay in {$roomName}.";
        }
        if ($rule['max'] !== null && $children > (int)$rule['max']) {
            return (int)$rule['max'] === 0
                ? "Children cannot stay in {$roomName}."
                : "{$roomName} takes at most " . (int)$rule['max'] . ' child' . ((int)$rule['max'] === 1 ? '' : 'ren') . ' per room.';
        }
        return '';
    }

    /** Rule as a small array for JavaScript / data attributes. */
    function rh_child_rule_js(array $rule): array
    {
        return [
            'mode'    => (string)$rule['mode'],
            'percent' => round((float)$rule['percent'], 2),
            'amount'  => round((float)$rule['amount'], 2),
            'free'    => (int)$rule['free'],
            'max'     => $rule['max'],
        ];
    }

    /** Short plain description of a rule for staff screens, e.g. "50% of room rate, first 2 free, max 3 per room". */
    function rh_child_rule_summary(array $rule, string $currency = 'MWK'): string
    {
        if ($rule['mode'] === 'fixed') {
            $core = (float)$rule['amount'] > 0 ? $currency . ' ' . number_format((float)$rule['amount'], 0) . ' per child per night' : 'Free';
        } else {
            $p = (float)$rule['percent'];
            $core = $p > 0 ? rtrim(rtrim(number_format($p, 2), '0'), '.') . '% of room rate' : 'Free';
        }
        if ((int)$rule['free'] > 0) {
            $core .= ', first ' . (int)$rule['free'] . ' free';
        }
        if ($rule['max'] !== null) {
            $core .= ', max ' . (int)$rule['max'] . ' per room';
        }
        return $core;
    }

    /**
     * Line text for quotations, invoices, statements and emails, from a saved booking row (its snapshot rule):
     * "2 children, first 2 free" or "1 child x MWK 10,000 x 7 nights".
     * $children defaults to the booking's own count; $rooms > 1 describes a group (children spread over several
     * rooms, each room with its own free allowance).
     */
    function rh_child_charge_label(array $b, string $currency = 'MWK', ?int $children = null, int $rooms = 1): string
    {
        $children = $children ?? (int)($b['child_guests'] ?? 0);
        if ($children <= 0) {
            return '';
        }
        $rule = rh_child_rule_from_booking($b);
        $nights = max(1, (int)($b['number_of_nights'] ?? 1));
        $word = function (int $n): string {
            return $n . ' child' . ($n === 1 ? '' : 'ren');
        };
        $free = (int)$rule['free'];
        $each = $rule['mode'] === 'fixed' ? (float)$rule['amount'] : (float)$rule['percent'];
        $unit = $rule['mode'] === 'fixed'
            ? $currency . ' ' . number_format($each, 0)
            : rtrim(rtrim(number_format($each, 2), '0'), '.') . '% of room rate';
        $nightsTxt = $nights . ' night' . ($nights === 1 ? '' : 's');
        if ($rooms > 1 && !empty($b['child_rule_mixed'])) {
            // The rooms of this booking charge children differently (different room types): say so neutrally.
            return $word($children) . ' in ' . $rooms . ' rooms';
        }
        if ($rooms > 1) {
            $head = $word($children) . ' in ' . $rooms . ' rooms' . ($free > 0 ? ', first ' . $free . ' free in each room' : '');
            return $each > 0 ? $head . ', ' . $unit . ' x ' . $nightsTxt : $head . ($free > 0 ? '' : ', no charge');
        }
        $charged = rh_child_charged($rule, $children);
        if ($charged <= 0 || $each <= 0) {
            return $word($children) . ($free > 0 ? ', first ' . $free . ' free' : ', no charge');
        }
        $line = $word($charged) . ' x ' . $unit . ' x ' . $nightsTxt;
        if ($free > 0 && $children > $charged) {
            $line = $word($children) . ', first ' . $free . ' free: ' . $line;
        }
        return $line;
    }

    /**
     * Same label for a set of booking rows (a group booking's rooms): children are added up across the rooms that carry
     * them. When those rooms charge children by different rules (different room types) the label is neutral.
     */
    function rh_child_doc_label(array $rows, string $currency = 'MWK'): string
    {
        $kids = 0;
        $rooms = 0;
        $first = null;
        $sigs = [];
        foreach ($rows as $r) {
            $c = (int)($r['child_guests'] ?? 0);
            if ($c > 0) {
                $kids += $c;
                $rooms++;
                $first = $first ?? $r;
                $sigs[json_encode(rh_child_rule_snapshot(rh_child_rule_from_booking($r)))] = true;
            }
        }
        if ($kids <= 0) {
            return '';
        }
        return rh_child_charge_label($first + ['child_rule_mixed' => count($sigs) > 1], $currency, $kids, $rooms);
    }

    /** Children who actually pay across a set of booking rows (each room's own free allowance taken off). */
    function rh_child_doc_charged(array $rows, int $fallbackChildren = 0): int
    {
        $charged = 0;
        $any = false;
        foreach ($rows as $r) {
            $c = (int)($r['child_guests'] ?? 0);
            if ($c > 0) {
                $any = true;
                $charged += rh_child_charged(rh_child_rule_from_booking($r), $c);
            }
        }
        if (!$any && $fallbackChildren > 0 && $rows) {
            $charged = rh_child_charged(rh_child_rule_from_booking(reset($rows)), $fallbackChildren);
        }
        return $charged;
    }
}

if (!function_exists('rh_child_rule_post')) {
    /**
     * Read and validate the child charge fields of a room-type or individual-room form
     * (child_charge_mode, child_price_multiplier, child_charge_amount, free_children, max_children).
     *
     * Room type: mode is always set; free_children defaults to 0; max_children blank = no limit.
     * Individual room: a blank mode means "same as room type" and stores NULL for the mode, % and amount;
     * a blank free / max box also stores NULL (inherit).
     *
     * @return array{error:?string,mode:?string,percent:?float,amount:?float,free:?int,max:?int}
     */
    function rh_child_rule_post(array $p, bool $individual): array
    {
        $out = ['error' => null, 'mode' => null, 'percent' => null, 'amount' => null, 'free' => null, 'max' => null];
        $get = static function (string $k) use ($p): string {
            return trim(str_replace(',', '', (string)($p[$k] ?? '')));
        };
        $mode = strtolower(trim((string)($p['child_charge_mode'] ?? '')));
        if ($mode === '' && !$individual) {
            $mode = 'percent';
        }
        if ($mode !== '' && $mode !== 'percent' && $mode !== 'fixed') {
            $out['error'] = 'Choose how children are charged: a % of the room rate or a fixed amount per child per night.';
            return $out;
        }
        $pct = $get('child_price_multiplier');
        $amt = $get('child_charge_amount');
        $free = $get('free_children');
        $max = $get('max_children');

        if ($pct !== '' && (!is_numeric($pct) || (float)$pct < 0 || (float)$pct > 100)) {
            $out['error'] = 'Child price percentage must be between 0 and 100.';
            return $out;
        }
        if ($amt !== '' && (!is_numeric($amt) || (float)$amt < 0 || (float)$amt > 99999999)) {
            $out['error'] = 'The child amount per night must be 0 or more.';
            return $out;
        }
        if ($mode === 'fixed' && $amt === '') {
            $out['error'] = 'Enter the fixed amount per child per night (0 means children stay free).';
            return $out;
        }
        foreach (['free_children' => $free, 'max_children' => $max] as $label => $v) {
            if ($v !== '' && (!ctype_digit($v) || (int)$v > 20)) {
                $out['error'] = ($label === 'free_children' ? 'Free children' : 'Most children in one room') . ' must be a whole number from 0 to 20.';
                return $out;
            }
        }

        if ($individual) {
            $out['mode'] = $mode === '' ? null : $mode;
            $out['percent'] = ($mode === 'percent' && $pct !== '') ? (float)$pct : null;
            $out['amount'] = ($mode === 'fixed') ? (float)$amt : null;
            $out['free'] = $free === '' ? null : (int)$free;
        } else {
            $out['mode'] = $mode;
            $out['percent'] = $pct === '' ? rh_child_default_percent() : (float)$pct;
            $out['amount'] = $amt === '' ? null : (float)$amt;
            $out['free'] = $free === '' ? 0 : (int)$free;
        }
        $out['max'] = $max === '' ? null : (int)$max;
        return $out;
    }
}
