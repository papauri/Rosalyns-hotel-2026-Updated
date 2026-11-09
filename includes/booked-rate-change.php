<?php
/**
 * The booked-rate line for a stay whose nights change (extend, shorten, change dates, early / late checkout):
 * "K356,000 × 4 nights = K1,424,000" — the rate the booking was MADE at, times the new nights, equal to the new total
 * rh_reprice_at_booked_rate() charges (owner 2026-11-09: show the booked rate and the nights, e.g. "x3").
 *
 *   rh_booked_rate_change_line(array $rp, float $packageGross, int $newNights, array $opts = []): array   PURE
 *   rh_booked_rate_change_for_booking(array $b, array $rp, int $newNights, array $opts = []): array      bookings row
 *
 * $rp: rh_reprice_at_booked_rate() result (new_gross, new_child_supplement). $packageGross: rh_booked_stay_split()
 * package_gross (packages are kept as they are, never multiplied by nights). $opts: as rh_booked_rate_text()
 * (currency, status); 'child' = the repriced children charge on the all-in basis (rh_booked_rate_child_gross());
 * without it $rp['new_child_supplement'] is used as it is.
 *
 * rh_reprice_at_booked_rate()'s per_night includes the children charge; rh_booked_rate_text() shows the room rate
 * WITHOUT it and the children charge as its own "+ children" part. Feeding the repriced room figure and the repriced
 * children charge back through rh_booked_rate_text() keeps the printed rate equal to the original booked rate and the
 * "=" equal to what is actually charged.
 */

require_once __DIR__ . '/booked-rate.php';

if (!function_exists('rh_booked_rate_change_line')) {
    function rh_booked_rate_change_line(array $rp, float $packageGross, int $newNights, array $opts = []): array
    {
        $pkg = max(0.0, round($packageGross, 2));
        $newGross = round((float)($rp['new_gross'] ?? 0), 2);
        $child = array_key_exists('child', $opts) ? (float)$opts['child'] : (float)($rp['new_child_supplement'] ?? 0);
        return rh_booked_rate_text(
            ['room_gross' => max(0.0, round($newGross - $pkg, 2)), 'package_gross' => $pkg, 'nights' => max(1, $newNights)],
            array_merge($opts, ['child' => $child])
        );
    }
}

if (!function_exists('rh_booked_rate_change_for_booking')) {
    /**
     * The same line from a bookings row (the row BEFORE the change) and its rh_reprice_at_booked_rate() result: the
     * package from rh_booked_stay_split() and the children charge on the same all-in basis rh_booked_rate_line() uses.
     * Needs config/database.php (rh_booked_stay_split, rh_booking_has_levy); without it ok = false.
     */
    function rh_booked_rate_change_for_booking(array $b, array $rp, int $newNights, array $opts = []): array
    {
        if (!function_exists('rh_booked_stay_split') || !function_exists('rh_booking_has_levy')) {
            return rh_booked_rate_text(['room_gross' => 0, 'nights' => 1]);
        }
        $opts['child'] = rh_booked_rate_child_gross((float)($rp['new_child_supplement'] ?? 0), rh_booking_has_levy($b));
        return rh_booked_rate_change_line($rp, (float)rh_booked_stay_split($b)['package_gross'], $newNights, $opts);
    }
}
