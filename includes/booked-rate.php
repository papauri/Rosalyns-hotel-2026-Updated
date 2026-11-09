<?php
/**
 * The rate a room booking was MADE at, and its nights, in one plain line (owner 2026-11-09: "show the rate that the
 * room was at the time of booking and also show how many nights ... x3"). One helper for every screen and document,
 * so staff never compare a payment against TODAY's room price by mistake.
 *
 *   rh_booked_rate_line(array $booking, ?float $todayPrice = null, array $opts = []): array   bookings row -> line
 *   rh_booked_rate_text(array $split, array $opts = []): array    PURE: rh_booked_stay_split() result -> line
 *   rh_room_today_price(array $b, ?array $combo = null): ?float    PURE: today's price for the room/occupancy/joined room
 *   rh_room_today_price_lookup(PDO $pdo, array $b): array{price:?float,combo_name:?string}   reads rooms/room_combinations
 *
 *   rh_booked_rate_for_booking(PDO $pdo, int $bookingId, bool $withToday = true): array   reads one booking -> line
 *   rh_booked_rate_group_texts(PDO $pdo, int $bookingId): string[]   guest_text of every room in the booking's group
 *   rh_booked_rate_group_lines(array $guestTexts): string[]   PURE: "Room: ..." / "Room 1: ..." / "6 rooms: ..."
 *
 * Line keys: ok, per_night, nights, room_total, child, package, total, text, compact, guest_text, today_text, cancelled,
 *            currency.
 *   text       "K356,000 × 3 nights = K1,068,000" (+ children / + package when present)
 *   compact    "K356,000 × 3"            (list rows)
 *   guest_text "Superior Suite - K356,000 × 3 nights (a night, incl. VAT and tourism levy)"   guest documents
 *   today_text staff only, set when today's price differs from the booked rate by more than BALANCE_TOLERANCE.
 *
 * Figures are the stored GROSS (incl. VAT and levy) from rh_booked_stay_split(): the booked rate, never today's price.
 * room_total is the stored room figure, never per_night × nights, so rounding never changes a total.
 */

require_once __DIR__ . '/booking-history-format.php'; // rh_hist_money(): "K356,000" / "MWK 356,000.50"

if (!function_exists('rh_booked_rate_text')) {
    /**
     * PURE. $split: rh_booked_stay_split() result (room_gross, package_gross, nights).
     * $opts: currency, today (?float list price today), child (child supplement inside room_gross, ALREADY on the all-in
 *        basis: rh_booked_rate_child_gross()),
     *        discount_per_night (rate_plan_discount), room_name, has_vat, has_levy, status.
     */
    function rh_booked_rate_text(array $split, array $opts = []): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $cur = trim((string)($opts['currency'] ?? '')) !== '' ? trim((string)$opts['currency']) : 'MWK';
        $nights = max(1, (int)($split['nights'] ?? 1));
        $roomGross = max(0.0, round((float)($split['room_gross'] ?? 0), 2));
        $pkg = max(0.0, round((float)($split['package_gross'] ?? 0), 2));
        $child = max(0.0, round((float)($opts['child'] ?? 0), 2));
        if ($child >= $roomGross - $tol) {
            $child = 0.0; // legacy rows: a child figure as big as the room is folded back into the room
        }
        $roomOnly = round($roomGross - $child, 2);
        $out = [
            'ok' => false, 'per_night' => 0.0, 'nights' => $nights, 'room_total' => $roomOnly, 'child' => $child,
            'package' => $pkg, 'total' => round($roomGross + $pkg, 2), 'text' => '', 'compact' => '', 'guest_text' => '',
            'today_text' => '', 'cancelled' => false, 'currency' => $cur,
        ];
        if ($roomOnly <= $tol) {
            return $out;
        }
        $perNight = round($roomOnly / $nights, 2);
        $nightWord = $nights === 1 ? '1 night' : $nights . ' nights';
        $m = static function (float $v) use ($cur): string {
            return rh_hist_money($v, $cur);
        };

        $text = $m($perNight) . ' × ' . $nightWord . ' = ' . $m($roomOnly);
        $extras = '';
        if ($child > $tol) {
            $extras .= ' + children ' . $m($child);
        }
        if ($pkg > $tol) {
            $extras .= ' + package ' . $m($pkg);
        }
        if ($extras !== '') {
            $text .= $extras . ' = ' . $m($out['total']);
        }

        $incl = [];
        if (!empty($opts['has_vat'])) {
            $incl[] = 'VAT';
        }
        if (!empty($opts['has_levy'])) {
            $incl[] = 'tourism levy';
        }
        $room = trim((string)($opts['room_name'] ?? ''));
        // Plain hyphen: the PDF core fonts (helvetica) have no em dash.
        $guest = ($room !== '' ? $room . ' - ' : '') . $m($perNight) . ' × ' . $nightWord
            . ($incl ? ' (a night, incl. ' . implode(' and ', $incl) . ')' : '')
            . ($child > $tol ? ' + children ' . $m($child) : '')
            . ($pkg > $tol ? ' + package ' . $m($pkg) : '');

        // Today's list price vs the booked rate. A rate plan lowers the booked rate below the list price of the
        // time, so with a discount the note shows only when today's price matches neither figure.
        $todayText = '';
        $today = isset($opts['today']) && $opts['today'] !== null ? (float)$opts['today'] : null;
        if ($today !== null && $today > $tol) {
            $disc = max(0.0, (float)($opts['discount_per_night'] ?? 0));
            $differs = abs($today - $perNight) > $tol && ($disc <= $tol || abs($today - ($perNight + $disc)) > $tol);
            if ($differs) {
                $todayText = "Today's price is " . $m($today) . ' a night — this booking keeps the price it was booked at.';
            }
        }

        $out['ok'] = true;
        $out['per_night'] = $perNight;
        $out['text'] = $text;
        $out['compact'] = $m($perNight) . ' × ' . $nights;
        $out['guest_text'] = $guest;
        $out['today_text'] = $todayText;
        $out['cancelled'] = strtolower((string)($opts['status'] ?? '')) === 'cancelled';
        return $out;
    }
}

if (!function_exists('rh_room_today_price')) {
    /**
     * PURE. Today's price for a booking's room: the occupancy price when the booking has one and the room sets it,
     * else price_per_night; a joined-rooms booking uses the combination's price (its override, else price_per_night).
     * $b carries rh_pp_night / rh_pp_single / rh_pp_double / rh_pp_triple and occupancy_type; $combo the
     * room_combinations row (price_override) or null. Returns null when unknown.
     */
    function rh_room_today_price(array $b, ?array $combo = null): ?float
    {
        $occ = strtolower((string)($b['occupancy_type'] ?? ''));
        $occVal = ['single' => 'rh_pp_single', 'double' => 'rh_pp_double', 'triple' => 'rh_pp_triple'][$occ] ?? '';
        $today = ($occVal !== '' && !empty($b[$occVal])) ? $b[$occVal] : ($b['rh_pp_night'] ?? null);
        if ($combo !== null) {
            $over = $combo['price_override'] ?? null;
            $today = ($over !== null && $over !== '') ? $over : ($b['rh_pp_night'] ?? null);
        }
        return ($today !== null && $today !== '') ? (float)$today : null;
    }
}

if (!function_exists('rh_room_today_price_lookup')) {
    /**
     * Today's price for a bookings row (reads rooms when the row has no rh_pp_* columns, and room_combinations for a
     * joined-rooms booking). Read-only; any read problem gives price null. combo_name is set only for joined rooms.
     *
     * @return array{price:?float,combo_name:?string}
     */
    function rh_room_today_price_lookup(PDO $pdo, array $b): array
    {
        $comboName = null;
        $combo = null;
        try {
            if (!array_key_exists('rh_pp_night', $b) && (int)($b['room_id'] ?? 0) > 0) {
                $st = $pdo->prepare('SELECT price_per_night AS rh_pp_night, price_single_occupancy AS rh_pp_single,
                        price_double_occupancy AS rh_pp_double, price_triple_occupancy AS rh_pp_triple FROM rooms WHERE id = ?');
                $st->execute([(int)$b['room_id']]);
                $b = array_merge($b, $st->fetch(PDO::FETCH_ASSOC) ?: []);
            }
            $comboId = (int)($b['room_combination_id'] ?? 0);
            if ($comboId > 0) {
                $st = $pdo->prepare('SELECT combined_name, price_override FROM room_combinations WHERE id = ?');
                $st->execute([$comboId]);
                $c = $st->fetch(PDO::FETCH_ASSOC);
                if ($c) {
                    $combo = $c;
                    $comboName = (string)$c['combined_name'];
                }
            }
        } catch (Throwable $e) {
            error_log('booked-rate today price: ' . $e->getMessage());
            return ['price' => null, 'combo_name' => $comboName];
        }
        return ['price' => rh_room_today_price($b, $combo), 'combo_name' => $comboName];
    }
}

if (!function_exists('rh_booked_rate_child_gross')) {
    /**
     * The child supplement on the same all-in basis as room_gross: grossed up exactly like the package in
     * rh_booked_stay_split() (rh_stay_totals 'price' basis, which follows vat_pricing_mode; levy only when the booking
     * had levy). Under the all-in price rule (owner 2026-10-24) the supplement is priced inside the all-in amount
     * (create-booking.php: rh_stay_totals($base_row + $child_sup, 'price')), so today this returns it unchanged; going
     * through the same function as the package keeps both right if the price basis ever changes.
     */
    function rh_booked_rate_child_gross(float $child, bool $hasLevy): float
    {
        if ($child <= 0) {
            return 0.0;
        }
        if (!function_exists('rh_stay_totals')) {
            require_once __DIR__ . '/pricing.php';
        }
        return round((float)rh_stay_totals($child, 'price', $hasLevy)['total_with_vat'], 2);
    }
}

if (!function_exists('rh_booked_rate_line')) {
    /**
     * The booked-rate line for one bookings row (needs total_with_vat / total_amount / vat_amount, number_of_nights,
     * package_total, child_supplement_total; optional rate_plan_discount, tourism_levy_amount, status, room_name).
     * $todayPrice: today's list price (staff screens; rh_room_today_price_lookup()), null = no today note.
     * $opts: currency (default the currency_symbol setting), room_name.
     */
    function rh_booked_rate_line(array $booking, ?float $todayPrice = null, array $opts = []): array
    {
        if (!function_exists('rh_booked_stay_split')) {
            return rh_booked_rate_text(['room_gross' => 0, 'nights' => 1]);
        }
        $cur = (string)($opts['currency'] ?? '');
        if ($cur === '') {
            try {
                $cur = function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
            } catch (Throwable $e) {
                $cur = 'MWK';
            }
        }
        return rh_booked_rate_text(rh_booked_stay_split($booking), [
            'currency' => $cur,
            'today' => $todayPrice,
            'child' => rh_booked_rate_child_gross((float)($booking['child_supplement_total'] ?? 0), rh_booking_has_levy($booking)),
            'discount_per_night' => (float)($booking['rate_plan_discount'] ?? 0),
            'room_name' => (string)($opts['room_name'] ?? ($booking['room_name'] ?? '')),
            'has_vat' => (float)($booking['vat_amount'] ?? 0) > 0,
            'has_levy' => (float)($booking['tourism_levy_amount'] ?? 0) > 0,
            'status' => (string)($booking['status'] ?? ''),
        ]);
    }
}

if (!function_exists('rh_booked_rate_for_booking')) {
    /**
     * Read one room booking and return its booked-rate line (see rh_booked_rate_line). $withToday: add today's price
     * note (staff screens only, never guest documents). Read-only; a missing booking or read error gives ok = false.
     */
    function rh_booked_rate_for_booking(PDO $pdo, int $bookingId, bool $withToday = true): array
    {
        $empty = rh_booked_rate_text(['room_gross' => 0, 'nights' => 1]);
        if ($bookingId <= 0) {
            return $empty;
        }
        try {
            $st = $pdo->prepare('SELECT b.*, r.name AS room_name, r.price_per_night AS rh_pp_night, r.price_single_occupancy AS rh_pp_single,
                    r.price_double_occupancy AS rh_pp_double, r.price_triple_occupancy AS rh_pp_triple
                FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?');
            $st->execute([$bookingId]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('booked-rate read: ' . $e->getMessage());
            return $empty;
        }
        if (!$b) {
            return $empty;
        }
        $today = $withToday ? rh_room_today_price_lookup($pdo, $b)['price'] : null;
        return rh_booked_rate_line($b, $today);
    }
}

if (!function_exists('rh_booked_rate_group_lines')) {
    /**
     * PURE. Guest lines for the room(s) a document covers, from each room's guest_text (blank ones skipped).
     *   1 room      ["Room: Superior Suite - K356,000 × 3 nights ..."]           (same as a single booking)
     *   2-4 rooms   ["Room 1: ...", "Room 2: ...", ...]                          one line per room
     *   5+ rooms    ["6 rooms: Superior Suite - ...; Deluxe Room - ...; ..."]    one line
     */
    function rh_booked_rate_group_lines(array $guestTexts): array
    {
        $texts = array_values(array_filter(array_map(static function ($t): string {
            return trim((string)$t);
        }, $guestTexts), static function (string $t): bool {
            return $t !== '';
        }));
        $n = count($texts);
        if ($n === 0) {
            return [];
        }
        if ($n === 1) {
            return ['Room: ' . $texts[0]];
        }
        if ($n > 4) {
            return [$n . ' rooms: ' . implode('; ', $texts)];
        }
        $out = [];
        foreach ($texts as $i => $t) {
            $out[] = 'Room ' . ($i + 1) . ': ' . $t;
        }
        return $out;
    }
}

if (!function_exists('rh_booked_rate_group_texts')) {
    /**
     * guest_text of every room in a booking's group (bookings.primary_booking_id: the lead, then its secondaries
     * in id order; the same rooms the group invoice lists), never today's price. A booking outside a group gives
     * just its own line. Deleted rooms are left out, and so is another room of the group that was cancelled,
     * expired or a no-show. Read-only; a read error falls back to the booking's own line.
     *
     * @return string[]
     */
    function rh_booked_rate_group_texts(PDO $pdo, int $bookingId): array
    {
        if ($bookingId <= 0) {
            return [];
        }
        $ids = [$bookingId];
        try {
            $st = $pdo->prepare('SELECT primary_booking_id FROM bookings WHERE id = ?');
            $st->execute([$bookingId]);
            $lead = (int)($st->fetchColumn() ?: 0);
            $lead = $lead > 0 ? $lead : $bookingId;
            $q = $pdo->prepare("SELECT id FROM bookings
                WHERE (id = ? OR primary_booking_id = ?) AND deleted_at IS NULL
                  AND (id = ? OR status NOT IN ('cancelled', 'expired', 'no-show'))
                ORDER BY (id = ?) DESC, id ASC");
            $q->execute([$lead, $lead, $bookingId, $lead]);
            $found = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            if ($found) {
                $ids = $found;
            }
        } catch (Throwable $e) {
            error_log('booked-rate group read: ' . $e->getMessage());
        }
        $out = [];
        foreach ($ids as $id) {
            $br = rh_booked_rate_for_booking($pdo, $id, false);
            if ($br['ok']) {
                $out[] = $br['guest_text'];
            }
        }
        return $out;
    }
}
