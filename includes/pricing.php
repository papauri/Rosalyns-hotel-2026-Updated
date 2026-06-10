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

        $currentPrice   = $base_price;
        $appliedPlan    = null;
        $totalDiscount  = 0.0;

        foreach ($plans as $plan) {
            if (!_planAppliesToRoom($plan, $room_id)) {
                continue;
            }
            if (!_planMatchesStay($plan, $checkInDate, $checkOutDate, $nights, $daysUntilArrival)) {
                continue;
            }

            // Plan matches
            $adjustedPrice = applyRatePlanToPrice($plan, $currentPrice);
            $diff          = $currentPrice - $adjustedPrice; // positive = discount, negative = surcharge

            $totalDiscount += $diff;
            $currentPrice   = $adjustedPrice;

            if ($appliedPlan === null) {
                $appliedPlan = $plan; // Track first match for label / ID
            }

            if (empty($plan['is_stacking'])) {
                break; // Non-stacking: stop after first match
            }
        }

        if ($appliedPlan === null) {
            return $empty;
        }

        return [
            'final_price'     => round($currentPrice, 2),
            'original_price'  => $base_price,
            'discount_amount' => round($totalDiscount, 2),
            'rate_plan_id'    => (int)$appliedPlan['id'],
            'rate_plan_label' => $appliedPlan['name'],
            'rate_plan_row'   => $appliedPlan,
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
