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
 */

if (!function_exists('applyDynamicPricing')) {

    /**
     * Find and apply the best applicable rate plan to a base room price.
     *
     * Rules evaluated in priority order (highest first). First non-stacking match wins.
     * Stacking plans are cumulative.
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

        if (empty($plans)) {
            return $empty;
        }

        $checkInDate  = new DateTime($check_in);
        $checkOutDate = new DateTime($check_out);
        $today        = new DateTime('today');
        $daysUntilArrival = (int)$today->diff($checkInDate)->days;
        if ($checkInDate < $today) {
            $daysUntilArrival = 0;
        }

        // Collect every plan matching this room + stay (already priority-ordered).
        $matching = [];
        foreach ($plans as $plan) {
            if (!_planAppliesToRoom($plan, $room_id)) {
                continue;
            }
            if (!_planMatchesStay($plan, $checkInDate, $checkOutDate, $nights, $daysUntilArrival)) {
                continue;
            }
            $matching[] = $plan;
        }

        if (empty($matching)) {
            return $empty;
        }

        $hasNonStacking = false;
        foreach ($matching as $plan) {
            if (empty($plan['is_stacking'])) {
                $hasNonStacking = true;
                break;
            }
        }

        $applied = [];
        if ($hasNonStacking) {
            // Any non-stacking plan in play: only the single best (lowest resulting
            // price) plan among ALL matching plans applies.
            $bestPlan  = null;
            $bestPrice = null;
            foreach ($matching as $plan) {
                $candidate = applyRatePlanToPrice($plan, $base_price);
                if ($bestPrice === null || $candidate < $bestPrice - 0.00001) {
                    $bestPrice = $candidate;
                    $bestPlan  = $plan;
                }
            }
            $applied = [$bestPlan];
        } else {
            $applied = $matching; // all stacking: combine cumulatively
        }

        $currentPrice = $base_price;
        foreach ($applied as $plan) {
            $currentPrice = applyRatePlanToPrice($plan, $currentPrice);
        }
        $totalDiscount = $base_price - $currentPrice; // positive = discount, negative = surcharge

        $appliedPlan = $applied[0];
        $labels = [];
        $ids    = [];
        foreach ($applied as $plan) {
            $labels[] = (string)$plan['name'];
            $ids[]    = (int)$plan['id'];
        }

        return [
            'final_price'     => round($currentPrice, 2),
            'original_price'  => $base_price,
            'discount_amount' => round($totalDiscount, 2),
            'rate_plan_id'    => (int)$appliedPlan['id'],       // primary plan
            'rate_plan_label' => implode(', ', $labels),        // every applied plan
            'rate_plan_ids'   => $ids,
            'rate_plan_row'   => $appliedPlan,
        ];
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

if (!function_exists('rh_doc_stay_lines')) {
    /**
     * The room figures a guest DOCUMENT (quotation, invoice, statement) prints for one booking row, built from the
     * stored columns so the printed lines foot to the booking total exactly (no recomputing from net).
     *
     * Gross basis = total_with_vat, falling back to total_amount + vat_amount (the same basis rh_booked_stay_split()
     * and rh_booking_gross_sql() use). total_amount is net + levy, so the display amounts are:
     *   inclusive / off : gross - levy   (VAT sits inside the lines; levy is its own line)
     *   exclusive       : gross - VAT - levy   (net lines; VAT is its own line)
     * Packages and the child supplement are stored on the price basis (the same basis the lines print on) and are
     * split out of the display amount; the room line is what remains.
     *
     * @param array $b           bookings row (total_amount, vat_amount, total_with_vat, tourism_levy_amount, child_supplement_total)
     * @param float $pkgPriceTotal package add-ons inside this row, on the price basis (primary booking only)
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
