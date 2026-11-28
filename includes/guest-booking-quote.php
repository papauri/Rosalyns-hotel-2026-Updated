<?php
/**
 * Guest booking money, one place (booking.php and includes/mixed-booking.php).
 *
 * The figures a guest booking row STORES are worked out here, and the booking page's price check
 * (booking.php dry run, response=wizard) reads the very same functions, so the quote and the save
 * cannot drift apart. Rounding follows the save: per-row amounts rounded to 2 dp, the child
 * supplement stored unrounded (rounded only for display), group sums rounded once at the end.
 */

if (!function_exists('rh_guest_row_amounts')) {
    /**
     * Stored money for one booking row: the stay (room + child supplement, levy and VAT inside the
     * all-in price, from rh_stay_totals(..., 'price')) plus the row's packages (VAT, no levy).
     * @return array {levy, levy_rate, vat_rate, vat, total_with_vat, total_amount (net + levy, ex VAT), package_total}
     */
    function rh_guest_row_amounts(array $stay, float $pkgThis): array
    {
        $pkgTotals = $pkgThis > 0
            ? rh_stay_totals($pkgThis, 'price', false)
            : ['net' => 0.0, 'vat' => 0.0, 'levy' => 0.0, 'total_with_vat' => 0.0];
        $levy = round($stay['levy'], 2);
        return [
            'levy' => $levy,
            'levy_rate' => $stay['levy_rate'],
            'vat_rate' => $stay['vat_rate'],
            'vat' => round($stay['vat'] + $pkgTotals['vat'], 2),
            'total_with_vat' => round($stay['total_with_vat'] + $pkgTotals['total_with_vat'], 2),
            // total_amount convention: net of VAT, levy included (net + levy).
            'total_amount' => round($stay['net'] + $pkgTotals['net'] + $levy, 2),
            'package_total' => $pkgThis,
        ];
    }

    /**
     * One room of a single-type guest booking, priced exactly as booking.php stores it.
     * $room is the room type as priced for the headline (joined types already carry the first combination's
     * rate); $selectedRoom is the room type as loaded (its own nightly price is the fallback of a combination
     * without a price of its own). $combination is the joined-room combination this room will be given, or null.
     * @return array rh_guest_row_amounts() + {base_rate, dynamic, rate, child_supplement, stay}
     */
    function rh_guest_price_row(PDO $pdo, array $room, array $selectedRoom, ?array $combination, int $roomId,
        string $checkIn, string $checkOut, int $nights, string $occupancy, int $children, array $childRule, float $pkgThis): array
    {
        $roomForThis = $room;
        if ($combination !== null) {
            $comboRate = ($combination['price_override'] !== null && $combination['price_override'] !== '')
                ? (float)$combination['price_override']
                : (float)$selectedRoom['price_per_night'];
            $roomForThis['price_per_night'] = $comboRate;
            $roomForThis['price_single_occupancy'] = $comboRate;
            $roomForThis['price_double_occupancy'] = $comboRate;
            $roomForThis['price_triple_occupancy'] = $comboRate;
        }
        $baseRate = ga_price_for_occupancy($roomForThis, $occupancy);
        $dynamic = applyDynamicPricing($pdo, $roomId, $checkIn, $checkOut, $nights, $baseRate);
        $rate = (float)$dynamic['final_price'];
        $base = $rate * $nights;
        $childSupplement = rh_child_supplement($childRule, $children, $nights, $rate);
        // The tourism levy is outside the VAT base; it applies to room + child supplement only, packages carry VAT but no levy.
        $stay = rh_stay_totals($base + $childSupplement, 'price');
        return [
            'base_rate' => $baseRate,
            'dynamic' => $dynamic,
            'rate' => $rate,
            'child_supplement' => $childSupplement,
            'stay' => $stay,
        ] + rh_guest_row_amounts($stay, $pkgThis);
    }

    /**
     * The step of the booking page a validateBookingWithAvailability() refusal belongs to, and the field.
     * Dates closed for the room type (past check-in, blocked dates) are step 1; other availability and
     * capacity refusals are step 3 (room). Read-only.
     * @return array [int step, string field]
     */
    function rh_guest_availability_step(array $result, int $roomTypeId, string $checkIn, string $checkOut): array
    {
        $type = (string)($result['type'] ?? '');
        if ($type === 'validation') {
            $map = rh_guest_field_steps();
            $best = [9, ''];
            foreach (array_keys((array)($result['errors'] ?? [])) as $key) {
                $s = $map[$key] ?? 3;
                if ($s < $best[0]) {
                    $best = [$s, (string)$key];
                }
            }
            return $best[0] === 9 ? [3, 'room_id'] : $best;
        }
        if ($type === 'availability') {
            $today = date('Y-m-d');
            if ($checkIn < $today || ($roomTypeId > 0 && getRoomTypeBlockedDatesOnly($roomTypeId, $checkIn, $checkOut))) {
                return [1, 'check_in_date'];
            }
        }
        return [3, 'room_id'];
    }

    /** Booking page step of each booking.php field (1 When, 2 Who, 3 Room, 4 Your details). */
    function rh_guest_field_steps(): array
    {
        return [
            'check_in_date' => 1, 'check_out_date' => 1, 'dates' => 1,
            'number_of_guests' => 2, 'child_guests' => 2,
            'room_id' => 3, 'package_ids' => 3,
            'guest_name' => 4, 'guest_email' => 4, 'guest_phone' => 4,
            'guest_country' => 4, 'guest_address' => 4, 'special_requests' => 4,
        ];
    }

    /**
     * The price check reply's quote, from rows priced by the functions above.
     * $rows: list of {room_id, name, occupancy, guests, adults, children, priced (rh_guest_row_amounts() + rate,
     * child_supplement, dynamic)} in save order. Every figure is what the save stores, summed and rounded the same way.
     */
    function rh_guest_build_quote(array $rows, array $packageRows, float $packageTotal, int $nights, bool $tentative): array
    {
        $lines = [];
        $order = [];
        $sum = ['total_with_vat' => 0.0, 'net' => 0.0, 'vat' => 0.0, 'levy' => 0.0, 'child_supplement' => 0.0];
        $vatRate = null;
        $levyRate = null;
        foreach ($rows as $r) {
            $p = $r['priced'];
            if ($vatRate === null) {
                $vatRate = (float)$p['vat_rate'];
                $levyRate = (float)$p['levy_rate'];
            }
            $key = (string)(int)$r['room_id'];
            if (!isset($lines[$key])) {
                $lines[$key] = ['room_id' => (int)$r['room_id'], 'name' => (string)$r['name'], 'qty' => 0,
                    'occupancy' => (string)$r['occupancy'], 'rows' => [], 'line_total_with_vat' => 0.0];
                $order[] = $key;
            }
            $lines[$key]['qty']++;
            $lines[$key]['rows'][] = [
                'occupancy' => (string)$r['occupancy'],
                'guests' => (int)$r['guests'], 'adults' => (int)$r['adults'], 'children' => (int)$r['children'],
                'room_price' => round((float)$p['rate'], 2),
                'rate_plan_label' => (string)(($p['dynamic']['rate_plan_label'] ?? '') ?: ''),
                'child_supplement' => round((float)$p['child_supplement'], 2),
                'package_total' => round((float)$p['package_total'], 2),
                'net' => round($p['total_amount'] - $p['levy'], 2),
                'vat_amount' => $p['vat'],
                'levy_amount' => $p['levy'],
                'total_amount' => $p['total_amount'],
                'total_with_vat' => $p['total_with_vat'],
            ];
            $lines[$key]['line_total_with_vat'] += $p['total_with_vat'];
            $sum['total_with_vat'] += $p['total_with_vat'];
            $sum['net'] += $p['total_amount'] - $p['levy'];
            $sum['vat'] += $p['vat'];
            $sum['levy'] += $p['levy'];
            $sum['child_supplement'] += $p['child_supplement'];
        }
        $outLines = [];
        foreach ($order as $key) {
            $lines[$key]['line_total_with_vat'] = round($lines[$key]['line_total_with_vat'], 2);
            $outLines[] = $lines[$key];
        }
        $packages = [];
        foreach ($packageRows as $pk) {
            $packages[] = ['id' => (int)($pk['package_id'] ?? 0), 'name' => (string)($pk['package_name'] ?? ''), 'total' => round((float)($pk['total_cost'] ?? 0), 2)];
        }
        return [
            'nights' => $nights,
            'currency' => (string)getSetting('currency_symbol'),
            'vat_mode' => function_exists('vat_mode') ? (string)vat_mode() : '',
            'vat_rate' => (float)$vatRate,
            'levy_rate' => (float)$levyRate,
            'lines' => $outLines,
            'packages' => $packages,
            'totals' => [
                'total_with_vat' => round($sum['total_with_vat'], 2),
                'net' => round($sum['net'], 2),
                'vat' => round($sum['vat'], 2),
                'levy' => round($sum['levy'], 2),
                'child_supplement' => round($sum['child_supplement'], 2),
                'package_total' => round($packageTotal, 2),
            ],
            'booking_type' => $tentative ? 'tentative' : 'standard',
            'status' => $tentative ? 'tentative' : 'pending',
        ];
    }
}
