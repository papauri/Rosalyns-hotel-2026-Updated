<?php
/**
 * Room-type change for a booking: paid upgrade, complimentary upgrade, downgrade.
 *
 * Front-desk rules this follows:
 *  - Paid upgrade: the guest pays the upgrade supplement = (new type's rack rate - current type's
 *    rack rate) per affected night, ADDED to the rate they booked. Their own discounts, rate plan and
 *    packages are kept (a guest on a promo rate is not repriced to full rack).
 *  - Complimentary upgrade: room changes, bill does not. The rack value given away is recorded in
 *    the audit trail (who authorised it and why) so comps are visible in reporting.
 *  - Downgrade: by default the bill is reduced by the same rack difference (a guest moved down for
 *    the hotel's availability, or by request, pays for the lower room). Overpayment becomes a credit
 *    on the booking (refund / credit note through the normal flows). "Keep the original rate" is a
 *    manager decision (e.g. a non-refundable rate the guest chose to downgrade on).
 *  - In-house guest: nights already slept stay at the booked rate; only tonight onward changes. The
 *    old room goes to housekeeping (cleaning) and the new room is marked occupied.
 *  - Keeping the price on a rack difference (comp / keep-rate) needs the Edit Booking Financials
 *    permission (or admin): it is a revenue decision, not a front-desk one.
 */

require_once __DIR__ . '/../config/database.php';
if (!function_exists('rh_stay_totals')) {
    require_once __DIR__ . '/pricing.php';
}
if (!function_exists('logBookingEvent')) {
    require_once __DIR__ . '/booking-timeline.php';
}

/** Statuses whose room type can be changed. */
function rh_room_change_statuses(): array
{
    return ['pending', 'tentative', 'confirmed', 'checked-in'];
}

/** Reason codes offered per direction (code => label). */
function rh_room_change_reasons(string $direction): array
{
    if ($direction === 'downgrade') {
        return [
            'downgrade_availability' => 'Hotel availability (overbooked, maintenance, room out of order)',
            'downgrade_guest_request' => 'Guest requested a different room',
            'downgrade_other' => 'Other (explain in the note)',
        ];
    }
    if ($direction === 'upgrade') {
        return [
            'upgrade_guest_request' => 'Guest requested / bought the upgrade',
            'upgrade_availability' => 'Hotel availability (booked type unavailable)',
            'upgrade_loyalty' => 'Loyalty / VIP / special occasion',
            'upgrade_service_recovery' => 'Service recovery (complaint)',
            'upgrade_other' => 'Other (explain in the note)',
        ];
    }
    return [
        'change_guest_request' => 'Guest requested a different room type',
        'change_availability' => 'Hotel availability',
        'change_other' => 'Other (explain in the note)',
    ];
}

/**
 * The joined pair a move of booking $b into joined type $typeId uses: the pair the booking already holds (same
 * type), else the FIRST pair getAvailableRoomCombinations() offers for the nights that move (an in-house guest:
 * tonight on, clean rooms only). rh_apply_room_type_change() assigns exactly this pair inside its transaction, so
 * the price shown, the price charged and the rooms given all come from one pair. Null when none is free.
 */
function rh_room_change_pair_choice(array $b, int $typeId): ?array
{
    global $pdo;
    if ((int)($b['room_id'] ?? 0) === $typeId && (int)($b['room_combination_id'] ?? 0) > 0) {
        $st = $pdo->prepare('SELECT rc.*, a.room_type_id AS room_a_type_id, bb.room_type_id AS room_b_type_id
            FROM room_combinations rc JOIN individual_rooms a ON a.id = rc.room_a_id JOIN individual_rooms bb ON bb.id = rc.room_b_id WHERE rc.id = ?');
        $st->execute([(int)$b['room_combination_id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }
    if (empty($b['check_in_date']) || empty($b['check_out_date'])) {
        return null;
    }
    $inHouse = (string)($b['status'] ?? '') === 'checked-in';
    $from = substr((string)$b['check_in_date'], 0, 10);
    if ($inHouse && $from < date('Y-m-d')) {
        $from = date('Y-m-d');
    }
    $to = substr((string)$b['check_out_date'], 0, 10);
    if ($from >= $to) {
        return null;
    }
    $free = getAvailableRoomCombinations($typeId, $from, $to, (int)($b['id'] ?? 0) ?: null, $inHouse);
    return $free[0] ?? null;
}

/**
 * Nightly rate of a joined ("Book As") room type for this booking, from the SAME source the create paths use
 * (booking.php / admin/create-booking.php / mixed booking / the quote): the pair's own rate override when set,
 * else the type's price per night (one rate whatever the occupancy), then the rate plans that target the joined
 * type and match the stay (applyDynamicPricing). The pair is $room['joined_pair'] when the caller already chose it
 * (rh_apply_room_type_change), else rh_room_change_pair_choice(). Null when the type is not sold through active
 * pairs (caller uses the normal occupancy prices).
 */
function rh_joined_type_rack_rate(array $room, array $b): ?float
{
    global $pdo;
    $typeId = (int)($room['id'] ?? 0);
    if ($typeId <= 0 || !function_exists('roomTypeHasActiveCombinations') || !roomTypeHasActiveCombinations($typeId)) {
        return null;
    }
    if (!function_exists('rh_joined_quote_rate')) {
        require_once __DIR__ . '/joined-quote.php';
    }
    $pair = array_key_exists('joined_pair', $room) ? $room['joined_pair'] : rh_room_change_pair_choice($b, $typeId);
    $base = rh_joined_quote_rate(is_array($pair) ? $pair : [], (float)($room['price_per_night'] ?? 0));
    return rh_room_change_rate_with_plans($typeId, $b, $base);
}

/** Catalogue room rate per night (price basis) of a room type at this booking's occupancy, children not included. */
function rh_room_type_base_rate(array $room, array $b): float
{
    $joinedRate = rh_joined_type_rack_rate($room, $b);
    if ($joinedRate !== null) {
        return $joinedRate; // a joined room is sold at one rate whatever the occupancy (as booking.php prices it)
    }
    $occ = (string)($b['occupancy_type'] ?? 'single');
    if ($occ === 'double' && (float)($room['price_double_occupancy'] ?? 0) > 0) {
        $rate = (float)$room['price_double_occupancy'];
    } elseif ($occ === 'triple' && (float)($room['price_triple_occupancy'] ?? 0) > 0) {
        $rate = (float)$room['price_triple_occupancy'];
    } elseif ($occ === 'single' && (float)($room['price_single_occupancy'] ?? 0) > 0) {
        $rate = (float)$room['price_single_occupancy'];
    } else {
        $rate = (float)($room['price_per_night'] ?? 0);
    }
    // A move to or from a joined type: the joined side is priced with its rate plans, so this side is too (same
    // dates and nights), or a plan covering both types would skew the rack difference. Other moves: catalogue price.
    if (!empty($room['with_rate_plans'])) {
        $rate = rh_room_change_rate_with_plans((int)($room['id'] ?? 0), $b, $rate);
    }
    return $rate;
}

/** $base after the rate plans that target room type $typeId and match the booking's stay (applyDynamicPricing). */
function rh_room_change_rate_with_plans(int $typeId, array $b, float $base): float
{
    global $pdo;
    $in = substr((string)($b['check_in_date'] ?? ''), 0, 10);
    $out = substr((string)($b['check_out_date'] ?? ''), 0, 10);
    if ($typeId <= 0 || $in === '' || $out === '' || $out <= $in || !function_exists('applyDynamicPricing')) {
        return $base;
    }
    $nights = max(1, (int)($b['number_of_nights'] ?? 0) ?: (int)round((strtotime($out) - strtotime($in)) / 86400));
    $dyn = applyDynamicPricing($pdo, $typeId, $in, $out, $nights, $base);
    return (float)($dyn['final_price'] ?? $base);
}

/**
 * Child charge per night for this booking's children, on the PRICE basis (the basis bookings.child_supplement_total is
 * stored on and documents split out). The room type's own child rule: percent or fixed, free children.
 */
function rh_room_type_child_night(array $room, array $b): float
{
    $children = max(0, (int)($b['child_guests'] ?? 0));
    return $children > 0 ? rh_child_night_charge(rh_child_rule($room), $children, rh_room_type_base_rate($room, $b)) : 0.0;
}

/** Rack (catalogue) GROSS rate per night of a room type for this booking's occupancy and children. */
function rh_room_type_rack_night(array $room, array $b): float
{
    return rh_stay_totals(rh_room_type_base_rate($room, $b) + rh_room_type_child_night($room, $b), 'price', rh_booking_has_levy($b))['total_with_vat'];
}

/** [stayed nights (history, kept at booked rate), affected nights (re-rated)] for the booking. */
function rh_room_change_nights(array $b, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $nights = max(1, (int)($b['number_of_nights'] ?? 1));
    if (($b['status'] ?? '') !== 'checked-in') {
        return [0, $nights];
    }
    $in = new DateTime(substr((string)$b['check_in_date'], 0, 10));
    $t = new DateTime($today);
    $stayed = $t > $in ? (int)$in->diff($t)->days : 0;
    $stayed = min($stayed, $nights - 1); // the departure night is never "history" while still in-house
    return [$stayed, max(1, $nights - $stayed)];
}

/**
 * Price a move of booking $b to room type $newRoom. Pure calculation.
 *
 * $pkgRows: the booking's room packages with their catalogue rows (rh_room_change_package_rows()); null = not checked
 * (pure callers). 'package_flags' lists the packages the new room type does not offer (owner 2026-11-26: staff are
 * warned and must confirm they told the guest; the package stays on the bill at its booked price).
 *
 * @return array{direction:string,stayed:int,affected:int,old_rack:float,new_rack:float,booked_night:float,
 *               current_total:float,charge_total:float,charge_delta:float,rack_value_delta:float,new_child_supplement:float,
 *               package_gross:float,package_flags:array<int,string>}
 */
function rh_room_change_quote(array $b, array $oldRoom, array $newRoom, ?array $pkgRows = null): array
{
    $split = rh_booked_stay_split($b);
    [$stayed, $affected] = rh_room_change_nights($b);
    // Joined type on either side: both racks include their rate plans for the same stay (equal footing).
    if (roomTypeHasActiveCombinations((int)($oldRoom['id'] ?? 0)) || roomTypeHasActiveCombinations((int)($newRoom['id'] ?? 0))) {
        $oldRoom['with_rate_plans'] = $newRoom['with_rate_plans'] = true;
    }
    // The old type is priced with the child rule the booking was saved with, the new type with its own current rule.
    $oldRack = rh_room_type_rack_night(array_merge($oldRoom, rh_child_rule_snapshot(rh_child_rule_from_booking($b))), $b);
    $newRack = rh_room_type_rack_night($newRoom, $b);
    $diff = round($newRack - $oldRack, 2);
    $direction = abs($diff) <= BALANCE_TOLERANCE ? 'same' : ($diff > 0 ? 'upgrade' : 'downgrade');

    $bookedNight = $split['per_night'];
    $newNight = max(0.0, $bookedNight + $diff);
    $chargeTotal = round($bookedNight * $stayed + $newNight * $affected + $split['package_gross'], 2);

    $oldChild = (float)($b['child_supplement_total'] ?? 0);
    $childNights = max(1, $split['nights']);
    $children = max(0, (int)($b['child_guests'] ?? 0));
    // Price basis, like the stored supplement it is added to (not the VAT/levy-inclusive rack figure).
    $newChildNight = $children > 0 ? rh_room_type_child_night($newRoom, $b) : 0.0;
    $newChild = round(($oldChild / $childNights) * $stayed + $newChildNight * $affected, 2);

    return [
        'direction' => $direction,
        'stayed' => $stayed,
        'affected' => $affected,
        'old_rack' => $oldRack,
        'new_rack' => $newRack,
        'booked_night' => round($bookedNight, 2),
        'current_total' => round($split['total_with_vat'], 2),
        'charge_total' => $chargeTotal,
        'charge_delta' => round($chargeTotal - $split['total_with_vat'], 2),
        'rack_value_delta' => round($diff * $affected, 2),
        'new_child_supplement' => $newChild,
        'package_gross' => $split['package_gross'],
        'package_flags' => rh_room_change_package_flags($pkgRows, (int)($newRoom['id'] ?? 0)),
    ];
}

/**
 * The room packages saved on booking $b with their catalogue rows (applies_to, room_type_ids; catalogue = null when the
 * package was deleted). Packages sit on a group's lead row only, so another room of a group has none to check.
 *
 * @return array<int,array{package_name:string,catalogue:?array}>
 */
function rh_room_change_package_rows(PDO $pdo, array $b): array
{
    if (!empty($b['primary_booking_id']) || (int)($b['id'] ?? 0) <= 0) {
        return [];
    }
    $st = $pdo->prepare('SELECT bp.package_name, rp.id AS rp_id, rp.applies_to, rp.room_type_ids
        FROM booking_packages bp LEFT JOIN room_packages rp ON rp.id = bp.package_id
        WHERE bp.booking_id = ? ORDER BY bp.id ASC');
    $st->execute([(int)$b['id']]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'package_name' => (string)$r['package_name'],
            'catalogue' => $r['rp_id'] !== null ? ['applies_to' => (string)$r['applies_to'], 'room_type_ids' => (string)$r['room_type_ids']] : null,
        ];
    }
    return $out;
}

/** PURE. Names of the packages in $pkgRows (rh_room_change_package_rows()) that room type $roomTypeId does not offer. */
function rh_room_change_package_flags(?array $pkgRows, int $roomTypeId): array
{
    if (!$pkgRows || !function_exists('rh_package_allowed_for_type')) {
        return [];
    }
    $names = [];
    foreach ($pkgRows as $r) {
        if (!rh_package_allowed_for_type($r['catalogue'] ?? null, $roomTypeId)) {
            $names[] = (string)($r['package_name'] ?? '') !== '' ? (string)$r['package_name'] : 'A package';
        }
    }
    return array_values(array_unique($names));
}

/** "Breakfast is not offered for Deluxe." / "Breakfast and Dinner are not offered for Deluxe." */
function rh_room_change_package_flag_text(array $names, string $roomName): string
{
    if (!$names) {
        return '';
    }
    $last = array_pop($names);
    $list = $names ? implode(', ', $names) . ' and ' . $last : $last;
    return $list . ($names ? ' are' : ' is') . ' not offered for ' . $roomName . '.';
}

/**
 * Room types this booking could move to, with availability and a quote for each.
 *
 * @return array{booking:array,options:array}
 */
function rh_room_change_options(PDO $pdo, int $bookingId): array
{
    $st = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?");
    $st->execute([$bookingId]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) {
        throw new RuntimeException('Booking not found.');
    }
    if (!empty($b['deleted_at'])) {
        throw new RuntimeException('This booking has been deleted.');
    }
    if (!in_array((string)$b['status'], rh_room_change_statuses(), true)) {
        throw new RuntimeException('The room type can only be changed before departure (pending, tentative, confirmed or in-house).');
    }
    $oldRoom = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $oldRoom->execute([(int)$b['room_id']]);
    $old = $oldRoom->fetch(PDO::FETCH_ASSOC) ?: [];

    $from = (string)$b['status'] === 'checked-in' ? max(date('Y-m-d'), substr((string)$b['check_in_date'], 0, 10)) : substr((string)$b['check_in_date'], 0, 10);
    $to = substr((string)$b['check_out_date'], 0, 10);
    if ($to < date('Y-m-d')) {
        throw new RuntimeException('The guest was due to leave on ' . $to . ' (overdue). Check them out, or extend the stay first.');
    }
    if ($from >= $to) {
        throw new RuntimeException('No nights are left to move: the guest departs today. Check them out instead.');
    }

    $rooms = $pdo->prepare("SELECT * FROM rooms WHERE is_active = 1 AND id <> ? ORDER BY price_per_night DESC, display_order ASC");
    $rooms->execute([(int)$b['room_id']]);
    $options = [];
    $pkgRows = rh_room_change_package_rows($pdo, $b);
    foreach ($rooms->fetchAll(PDO::FETCH_ASSOC) as $room) {
        $q = rh_room_change_quote($b, $old, $room, $pkgRows);
        $q['package_flag_text'] = rh_room_change_package_flag_text($q['package_flags'], (string)$room['name']);
        $why = '';
        if ((int)($room['max_guests'] ?? 0) > 0 && (int)$b['number_of_guests'] > (int)$room['max_guests']) {
            $why = 'Sleeps ' . (int)$room['max_guests'] . ' (booking has ' . (int)$b['number_of_guests'] . ' guests)';
        } elseif ((int)($b['child_guests'] ?? 0) > 0 && isset($room['children_allowed']) && (int)$room['children_allowed'] === 0) {
            $why = 'Children not allowed in this room type';
        } elseif ((int)($b['child_guests'] ?? 0) > 0 && rh_child_limit_error(rh_child_rule($room), (int)$b['child_guests'], true, 'This room type') !== '') {
            $why = rh_child_limit_error(rh_child_rule($room), (int)$b['child_guests'], true, 'This room type');
        } else {
            $av = checkRoomAvailability((int)$room['id'], $from, $to, $bookingId, (int)($b['child_guests'] ?? 0));
            if (empty($av['available'])) {
                $why = 'Fully booked for ' . date('j M', strtotime($from)) . ' - ' . date('j M', strtotime($to));
            } elseif (roomTypeHasActiveCombinations((int)$room['id']) && rh_room_change_pair_choice($b, (int)$room['id']) === null) {
                $why = 'No joined pair free' . ((string)$b['status'] === 'checked-in' ? ' and clean' : '') . ' for ' . date('j M', strtotime($from)) . ' - ' . date('j M', strtotime($to));
            }
        }
        $options[] = [
            'id' => (int)$room['id'],
            'name' => (string)$room['name'],
            'available' => $why === '',
            'unavailable_reason' => $why,
            'quote' => $q,
        ];
    }
    return [
        'booking' => [
            'id' => (int)$b['id'],
            'reference' => (string)$b['booking_reference'],
            'guest_name' => (string)$b['guest_name'],
            'status' => (string)$b['status'],
            'room_id' => (int)$b['room_id'],
            'room_name' => (string)($b['room_name'] ?? ''),
            'check_in' => substr((string)$b['check_in_date'], 0, 10),
            'check_out' => $to,
            'nights' => (int)$b['number_of_nights'],
            'from' => $from,
            'current_total' => round(rh_booked_stay_split($b)['total_with_vat'], 2),
            'amount_paid' => round((float)($b['amount_paid'] ?? 0), 2),
            'occupancy' => (string)($b['occupancy_type'] ?? ''),
            'guests' => (int)$b['number_of_guests'],
        ],
        'options' => $options,
    ];
}

/**
 * Apply a room-type change. $pricing: 'charge' (reprice by the rack difference) or 'keep' (bill unchanged).
 * $packageAck: staff confirmed they told the guest that a room package on the booking is not offered for the new type
 * (owner 2026-11-26). Without it such a change is refused; with it the package stays on the bill and History says so.
 *
 * @return array{success:bool,message:string,data?:array}
 */
function rh_apply_room_type_change(PDO $pdo, int $bookingId, int $newRoomId, string $pricing, string $reasonCode, string $note, array $actor, bool $canKeepPrice, bool $packageAck = false): array
{
    $pkgFlagText = '';
    $pricing = $pricing === 'keep' ? 'keep' : 'charge';
    $note = mb_substr(trim($note), 0, 500);
    $actorId = (int)($actor['id'] ?? 0);
    $actorName = (string)($actor['full_name'] ?? ($actor['username'] ?? 'Admin'));
    $currency = (string)getSetting('currency_symbol', 'MWK');
    $oldRoomIds = [];

    try {
        // Room types to lock (old + new, plus joined-room types sharing their rooms) are read BEFORE the
        // transaction: a plain read inside it would open the snapshot ahead of the room locks.
        $preSt = $pdo->prepare("SELECT room_id FROM bookings WHERE id = ?");
        $preSt->execute([$bookingId]);
        $lockTypeIds = rh_related_room_type_ids($pdo, [(int)$preSt->fetchColumn(), $newRoomId]);
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
        $lock->execute([$bookingId]);
        $b = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            throw new RuntimeException('Booking not found.');
        }
        // Room-type locks come before any plain read (ascending id, shared with the booking flows).
        rh_lock_room_types($pdo, $lockTypeIds);
        if (!in_array((int)$b['room_id'], $lockTypeIds, true)) {
            throw new RuntimeException('This booking was changed by someone else a moment ago. Refresh the page and try again.');
        }
        if (!empty($b['deleted_at'])) {
            throw new RuntimeException('This booking has been deleted. Restore it first.');
        }
        $status = (string)$b['status'];
        if (!in_array($status, rh_room_change_statuses(), true)) {
            throw new RuntimeException('The room type can only be changed before departure (pending, tentative, confirmed or in-house).');
        }
        if ($status === 'confirmed' && substr((string)$b['check_in_date'], 0, 10) < date('Y-m-d')) {
            throw new RuntimeException('The arrival date has passed. Check the guest in or mark a no-show first.');
        }
        if ($newRoomId === (int)$b['room_id']) {
            throw new RuntimeException('That is already the booking\'s room type.');
        }
        $rs = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $rs->execute([(int)$b['room_id']]);
        $oldRoom = $rs->fetch(PDO::FETCH_ASSOC) ?: [];
        $rs->execute([$newRoomId]);
        $newRoom = $rs->fetch(PDO::FETCH_ASSOC);
        if (!$newRoom || (int)$newRoom['is_active'] !== 1) {
            throw new RuntimeException('The selected room type does not exist or is not active.');
        }
        if ((int)($newRoom['max_guests'] ?? 0) > 0 && (int)$b['number_of_guests'] > (int)$newRoom['max_guests']) {
            throw new RuntimeException($newRoom['name'] . ' sleeps ' . (int)$newRoom['max_guests'] . '; this booking has ' . (int)$b['number_of_guests'] . ' guests.');
        }

        $childLimit = rh_child_limit_error(rh_child_rule($newRoom), (int)($b['child_guests'] ?? 0), true, (string)$newRoom['name']);
        if ($childLimit !== '') {
            throw new RuntimeException($childLimit);
        }

        // A joined ("Book As") target is priced from, and given, ONE pair chosen here under the room-type locks
        // (the same choice the options list quoted): never priced on one pair and handed another.
        $joinedPair = null;
        if (roomTypeHasActiveCombinations($newRoomId)) {
            $joinedPair = rh_room_change_pair_choice($b, $newRoomId);
            if ($joinedPair === null) {
                throw new RuntimeException('No ' . $newRoom['name'] . ' pair is free' . ($status === 'checked-in' ? ' and clean from tonight' : '') . ' to ' . substr((string)$b['check_out_date'], 0, 10) . ', so the booking stays as it is.');
            }
            $newRoom['joined_pair'] = $joinedPair;
        }

        $q = rh_room_change_quote($b, $oldRoom, $newRoom, rh_room_change_package_rows($pdo, $b));
        $pkgFlagText = rh_room_change_package_flag_text($q['package_flags'], (string)$newRoom['name']);
        if ($pkgFlagText !== '' && !$packageAck) {
            throw new RuntimeException($pkgFlagText . ' Tick "I have told the guest" to keep it on the bill at the booked price, or choose another room type. Nothing was changed.');
        }
        $direction = $q['direction'];
        if ($direction === 'same') {
            $pricing = 'keep'; // same rack rate: nothing to charge or credit
        }
        $reasons = rh_room_change_reasons($direction);
        if (!isset($reasons[$reasonCode])) {
            throw new RuntimeException('Choose a reason for the room change.');
        }
        $keepIsRevenueDecision = $pricing === 'keep' && $direction !== 'same';
        if ($keepIsRevenueDecision && !$canKeepPrice) {
            throw new RuntimeException($direction === 'upgrade'
                ? 'A complimentary upgrade needs a manager (Edit Booking Financials permission). Charge the upgrade, or ask a manager.'
                : 'Keeping the original rate on a downgrade needs a manager (Edit Booking Financials permission).');
        }
        $noteRequired = $keepIsRevenueDecision || $direction === 'downgrade' || substr($reasonCode, -6) === '_other';
        if ($noteRequired && mb_strlen($note) < 5) {
            throw new RuntimeException('Add a short note explaining the room change (who authorised it and why).');
        }

        // Availability of the new type for the nights that change (validator clips history for in-house guests).
        $gate = rh_validate_booking_stay_change($bookingId, (string)$b['check_in_date'], (string)$b['check_out_date'], $newRoomId);
        if (!$gate['ok']) {
            throw new RuntimeException($gate['error']);
        }

        $oldRoomIds = getBookingRoomIds($bookingId);
        if ($joinedPair !== null) {
            // The member room types must still have the two rooms to give (a member-type booking sold a moment ago
            // may hold no room yet). assignRoomCombinationToBooking() skips this when the caller owns the transaction.
            $stockFrom = max(date('Y-m-d'), substr((string)$b['check_in_date'], 0, 10));
            $stockTo = substr((string)$b['check_out_date'], 0, 10);
            $stockErr = $stockFrom < $stockTo ? rh_joined_members_stock_error([$joinedPair], $stockFrom, $stockTo, $bookingId) : '';
            if ($stockErr !== '') {
                throw new RuntimeException($stockErr);
            }
        } elseif ($status === 'checked-in') {
            // The guest is in the building: never take their room away unless a room of the new type is free.
            // Same rule auto-assign applies to a guest moving in now: the new room must be clean.
            $freeRooms = getAvailableIndividualRooms($newRoomId, max(date('Y-m-d'), substr((string)$b['check_in_date'], 0, 10)),
                substr((string)$b['check_out_date'], 0, 10), $bookingId, (int)($b['child_guests'] ?? 0) > 0, true);
            if (empty($freeRooms)) {
                throw new RuntimeException('No ' . $newRoom['name'] . ' room is free from tonight to ' . $b['check_out_date'] . ', so the guest stays where they are.');
            }
        }
        // The booking takes the new type's child rule only when the whole stay is re-priced on it (charge pricing and no
        // nights already stayed at the old rate). A complimentary move, or an in-house move that re-rates only the later
        // nights, keeps the rule the booking was priced with.
        if ($pricing === 'charge' && (int)$q['stayed'] === 0) {
            $newChildRule = rh_child_rule_snapshot(rh_child_rule($newRoom));
            $pdo->prepare("UPDATE bookings SET room_id = ?, child_price_multiplier = ?, child_charge_mode = ?, child_charge_amount = ?, free_children = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$newRoomId, $newChildRule['child_price_multiplier'], $newChildRule['child_charge_mode'], $newChildRule['child_charge_amount'], $newChildRule['free_children'], $bookingId]);
        } else {
            $pdo->prepare("UPDATE bookings SET room_id = ?, updated_at = NOW() WHERE id = ?")->execute([$newRoomId, $bookingId]);
        }
        if ($pricing === 'charge') {
            // Levy on the room part only, never on packages (as booking.php saves it).
            $tt = rh_stay_totals_with_package($q['charge_total'], (float)($q['package_gross'] ?? 0), rh_booking_has_levy($b));
            rh_write_booking_stay_totals($pdo, $bookingId, $tt, null, null, null, $q['new_child_supplement']);
        }
        if (!recalculateBookingFinancials($bookingId)) {
            throw new RuntimeException('Could not recalculate the booking balance.');
        }
        // Room-type stock counter follows confirmed / in-house bookings.
        if (in_array($status, ['confirmed', 'checked-in'], true)) {
            $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")->execute([(int)$b['room_id']]);
            $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0")->execute([$newRoomId]);
        }
        $assigned = '';
        if ($joinedPair !== null) {
            // The priced pair is assigned in this same transaction (its booking_rooms replace the old rooms).
            $pairRes = assignRoomCombinationToBooking($bookingId, (int)$joinedPair['id'], $actorId ?: null);
            if (empty($pairRes['success'])) {
                throw new RuntimeException((string)($pairRes['message'] ?? 'The joined rooms could not be reserved.'));
            }
            $assigned = (string)($pairRes['assigned_room_number'] ?? '');
        } elseif (!empty($oldRoomIds)) {
            // Physical room of the old type no longer fits: release it (a new one is picked after commit).
            rh_release_booking_room_assignment($bookingId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => rh_is_lock_conflict($e) ? rh_lock_conflict_message() : $e->getMessage()];
    }

    // Physical room: pick one of the new type. In-house: old room to housekeeping, new one occupied.
    if ($joinedPair === null && (!empty($oldRoomIds) || $status === 'checked-in')) {
        $as = autoAssignIndividualRoom($bookingId);
        if (!empty($as['success']) && !empty($as['assigned_room_number'])) {
            $assigned = (string)$as['assigned_room_number'];
        }
    }
    if ($status === 'checked-in') {
        // The room the guest left goes to housekeeping (status 'cleaning' + an open cleaning task, the same
        // as after a checkout). Only the NEW room(s) are marked occupied (the array_diff keeps the old room out
        // even if the new assignment is missing and getBookingRoomIds() falls back to the last released set).
        $outOfService = function_exists('rh_room_out_of_service_statuses') ? rh_room_out_of_service_statuses() : ['maintenance', 'out_of_order'];
        $roomStatusQ = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
        // A room the guest keeps (e.g. 7A when moving into the joined 7A + 7B) is not left.
        $keptRoomIds = $assigned !== '' ? array_map('intval', getBookingRoomIds($bookingId)) : [];
        foreach ($oldRoomIds as $rid) {
            if (in_array((int)$rid, $keptRoomIds, true)) {
                continue;
            }
            $roomStatusQ->execute([(int)$rid]);
            $oldStatusNow = (string)$roomStatusQ->fetchColumn();
            if (in_array($oldStatusNow, $outOfService, true)) {
                continue;
            }
            if ($oldStatusNow !== 'cleaning') { // the joined-pair assign already sent it to housekeeping
                updateIndividualRoomStatus((int)$rid, 'cleaning', 'Guest moved to ' . $newRoom['name'] . ' (' . $b['booking_reference'] . ')', $actorId ?: null);
            }
            rh_ensure_checkout_cleaning_task((int)$rid, $bookingId, $actorId ?: null);
        }
        if ($assigned !== '') {
            foreach (array_diff(getBookingRoomIds($bookingId), array_map('intval', $oldRoomIds)) as $rid) {
                $roomStatusQ->execute([(int)$rid]);
                if ((string)$roomStatusQ->fetchColumn() !== 'occupied') {
                    updateIndividualRoomStatus((int)$rid, 'occupied', 'Guest moved in: ' . $b['booking_reference'], $actorId ?: null);
                }
            }
        }
    }

    $after = $pdo->prepare("SELECT total_with_vat, amount_due, credit_balance FROM bookings WHERE id = ?");
    $after->execute([$bookingId]);
    $fin = $after->fetch(PDO::FETCH_ASSOC) ?: [];
    $newTotal = round((float)($fin['total_with_vat'] ?? 0), 2);
    $billDelta = round($newTotal - $q['current_total'], 2);

    $kind = $direction === 'upgrade'
        ? ($pricing === 'keep' ? 'Complimentary upgrade' : 'Paid upgrade')
        : ($direction === 'downgrade' ? ($pricing === 'keep' ? 'Downgrade (original rate kept)' : 'Downgrade (bill reduced)') : 'Room type change');
    $money = $billDelta > BALANCE_TOLERANCE ? ' Bill +' . $currency . ' ' . number_format($billDelta, 2) . '.'
        : ($billDelta < -BALANCE_TOLERANCE ? ' Bill -' . $currency . ' ' . number_format(-$billDelta, 2) . '.' : ' Bill unchanged.');
    if ($pricing === 'keep' && abs($q['rack_value_delta']) > BALANCE_TOLERANCE) {
        $money .= ' Rack value ' . ($q['rack_value_delta'] > 0 ? 'given (comp): ' : 'difference kept: ') . $currency . ' ' . number_format(abs($q['rack_value_delta']), 2) . '.';
    }
    $desc = $kind . ': ' . ($oldRoom['name'] ?? ('type #' . $b['room_id'])) . ' -> ' . $newRoom['name']
        . ' for ' . $q['affected'] . ' night(s)' . ($q['stayed'] > 0 ? ' (from tonight; ' . $q['stayed'] . ' night(s) already stayed kept at the booked rate)' : '') . '.'
        . $money . ' Reason: ' . (rh_room_change_reasons($direction)[$reasonCode] ?? $reasonCode) . ($note !== '' ? '. Note: ' . $note : '') . '.';
    if ($pkgFlagText !== '') {
        $desc .= ' Package check: ' . $pkgFlagText . ' Kept on the bill at the booked price; staff confirmed the guest was told.';
    }
    if ((float)($fin['credit_balance'] ?? 0) > BALANCE_TOLERANCE) {
        $desc .= ' Guest now in credit ' . $currency . ' ' . number_format((float)$fin['credit_balance'], 2) . ' (refund or credit note).';
    }

    $meta = [
        'kind' => $kind, 'direction' => $direction, 'pricing' => $pricing, 'reason_code' => $reasonCode, 'note' => $note,
        'old_room_type_id' => (int)$b['room_id'], 'new_room_type_id' => $newRoomId,
        'old_total' => $q['current_total'], 'new_total' => $newTotal, 'bill_delta' => $billDelta,
        'rack_value_delta' => $q['rack_value_delta'], 'nights_affected' => $q['affected'], 'nights_stayed' => $q['stayed'],
        'old_rack_night' => $q['old_rack'], 'new_rack_night' => $q['new_rack'], 'assigned_room' => $assigned,
        'packages_not_offered' => $q['package_flags'],
    ];
    if (function_exists('logBookingAudit')) {
        logBookingAudit($bookingId, $direction === 'downgrade' ? 'downgraded' : 'upgraded',
            ['room_id' => (int)$b['room_id'], 'total_with_vat' => $q['current_total']],
            ['room_id' => $newRoomId, 'total_with_vat' => $newTotal, 'pricing' => $pricing, 'reason' => $reasonCode],
            $desc, (string)$b['booking_reference']);
    }
    logBookingEvent($bookingId, (string)$b['booking_reference'], $kind, 'update', $desc,
        (string)($oldRoom['name'] ?? ''), (string)$newRoom['name'], 'admin', $actorId ?: null, $actorName, $meta);
    if (function_exists('rh_log_event')) {
        rh_log_event('bookings', $pricing === 'keep' && $direction === 'upgrade' ? 'warning' : 'info', $kind, $meta + ['booking_id' => $bookingId, 'by' => $actorName]);
    }

    return [
        'success' => true,
        'message' => $kind . ' done: now ' . $newRoom['name'] . '.' . $money . ($assigned !== '' ? ' Room ' . $assigned . ' assigned.' : (!empty($oldRoomIds) ? ' No room of the new type could be assigned automatically - assign one.' : '')),
        'data' => [
            'booking' => $b, 'old_room_name' => (string)($oldRoom['name'] ?? ''), 'new_room_name' => (string)$newRoom['name'],
            'old_total' => $q['current_total'], 'new_total' => $newTotal, 'price_difference' => $billDelta,
            'kind' => $kind, 'direction' => $direction, 'pricing' => $pricing,
        ],
    ];
}
