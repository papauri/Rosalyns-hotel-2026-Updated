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

/** Rack (catalogue) GROSS rate per night of a room type for this booking's occupancy and children. */
function rh_room_type_rack_night(array $room, array $b): float
{
    $occ = (string)($b['occupancy_type'] ?? 'single');
    if ($occ === 'double' && (float)($room['price_double_occupancy'] ?? 0) > 0) {
        $base = (float)$room['price_double_occupancy'];
    } elseif ($occ === 'triple' && (float)($room['price_triple_occupancy'] ?? 0) > 0) {
        $base = (float)$room['price_triple_occupancy'];
    } elseif ($occ === 'single' && (float)($room['price_single_occupancy'] ?? 0) > 0) {
        $base = (float)$room['price_single_occupancy'];
    } else {
        $base = (float)($room['price_per_night'] ?? 0);
    }
    $children = max(0, (int)($b['child_guests'] ?? 0));
    $child = $children > 0 ? $base * ((float)($room['child_price_multiplier'] ?? 50) / 100) * $children : 0.0;
    return rh_stay_totals($base + $child, 'price', rh_booking_has_levy($b))['total_with_vat'];
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
 * @return array{direction:string,stayed:int,affected:int,old_rack:float,new_rack:float,booked_night:float,
 *               current_total:float,charge_total:float,charge_delta:float,rack_value_delta:float,new_child_supplement:float}
 */
function rh_room_change_quote(array $b, array $oldRoom, array $newRoom): array
{
    $split = rh_booked_stay_split($b);
    [$stayed, $affected] = rh_room_change_nights($b);
    $oldRack = rh_room_type_rack_night($oldRoom, $b);
    $newRack = rh_room_type_rack_night($newRoom, $b);
    $diff = round($newRack - $oldRack, 2);
    $direction = abs($diff) <= BALANCE_TOLERANCE ? 'same' : ($diff > 0 ? 'upgrade' : 'downgrade');

    $bookedNight = $split['per_night'];
    $newNight = max(0.0, $bookedNight + $diff);
    $chargeTotal = round($bookedNight * $stayed + $newNight * $affected + $split['package_gross'], 2);

    $oldChild = (float)($b['child_supplement_total'] ?? 0);
    $childNights = max(1, $split['nights']);
    $children = max(0, (int)($b['child_guests'] ?? 0));
    $newChildNight = 0.0;
    if ($children > 0) {
        $occBase = rh_room_type_rack_night(array_merge($newRoom, ['child_price_multiplier' => 0]), $b);
        $newChildNight = max(0.0, $newRack - $occBase);
    }
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
    ];
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
    foreach ($rooms->fetchAll(PDO::FETCH_ASSOC) as $room) {
        $q = rh_room_change_quote($b, $old, $room);
        $why = '';
        if ((int)($room['max_guests'] ?? 0) > 0 && (int)$b['number_of_guests'] > (int)$room['max_guests']) {
            $why = 'Sleeps ' . (int)$room['max_guests'] . ' (booking has ' . (int)$b['number_of_guests'] . ' guests)';
        } elseif ((int)($b['child_guests'] ?? 0) > 0 && isset($room['children_allowed']) && (int)$room['children_allowed'] === 0) {
            $why = 'Children not allowed in this room type';
        } else {
            $av = checkRoomAvailability((int)$room['id'], $from, $to, $bookingId, (int)($b['child_guests'] ?? 0));
            if (empty($av['available'])) {
                $why = 'Fully booked for ' . date('j M', strtotime($from)) . ' - ' . date('j M', strtotime($to));
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
 *
 * @return array{success:bool,message:string,data?:array}
 */
function rh_apply_room_type_change(PDO $pdo, int $bookingId, int $newRoomId, string $pricing, string $reasonCode, string $note, array $actor, bool $canKeepPrice): array
{
    $pricing = $pricing === 'keep' ? 'keep' : 'charge';
    $note = mb_substr(trim($note), 0, 500);
    $actorId = (int)($actor['id'] ?? 0);
    $actorName = (string)($actor['full_name'] ?? ($actor['username'] ?? 'Admin'));
    $currency = (string)getSetting('currency_symbol', 'MWK');
    $oldRoomIds = [];

    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
        $lock->execute([$bookingId]);
        $b = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            throw new RuntimeException('Booking not found.');
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

        $q = rh_room_change_quote($b, $oldRoom, $newRoom);
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
        if ($status === 'checked-in') {
            // The guest is in the building: never take their room away unless a room of the new type is free.
            $freeRooms = getAvailableIndividualRooms($newRoomId, max(date('Y-m-d'), substr((string)$b['check_in_date'], 0, 10)),
                substr((string)$b['check_out_date'], 0, 10), $bookingId, (int)($b['child_guests'] ?? 0) > 0);
            if (empty($freeRooms)) {
                throw new RuntimeException('No ' . $newRoom['name'] . ' room is free from tonight to ' . $b['check_out_date'] . ', so the guest stays where they are.');
            }
        }
        $pdo->prepare("UPDATE bookings SET room_id = ?, child_price_multiplier = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$newRoomId, $newRoom['child_price_multiplier'] ?? 50, $bookingId]);
        if ($pricing === 'charge') {
            $tt = rh_stay_totals($q['charge_total'], 'final', rh_booking_has_levy($b));
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
        // Physical room of the old type no longer fits: release it (a new one is picked after commit).
        if (!empty($oldRoomIds)) {
            rh_release_booking_room_assignment($bookingId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }

    // Physical room: pick one of the new type. In-house: old room to housekeeping, new one occupied.
    $assigned = '';
    if (!empty($oldRoomIds) || $status === 'checked-in') {
        $as = autoAssignIndividualRoom($bookingId);
        if (!empty($as['success']) && !empty($as['assigned_room_number'])) {
            $assigned = (string)$as['assigned_room_number'];
        }
    }
    if ($status === 'checked-in') {
        foreach ($oldRoomIds as $rid) {
            updateIndividualRoomStatus((int)$rid, 'cleaning', 'Guest moved to ' . $newRoom['name'] . ' (' . $b['booking_reference'] . ')', $actorId ?: null);
        }
        if ($assigned !== '') {
            updateBookingRoomsStatus($bookingId, 'occupied', 'Guest moved in: ' . $b['booking_reference'], $actorId ?: null);
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
    if ((float)($fin['credit_balance'] ?? 0) > BALANCE_TOLERANCE) {
        $desc .= ' Guest now in credit ' . $currency . ' ' . number_format((float)$fin['credit_balance'], 2) . ' (refund or credit note).';
    }

    $meta = [
        'kind' => $kind, 'direction' => $direction, 'pricing' => $pricing, 'reason_code' => $reasonCode, 'note' => $note,
        'old_room_type_id' => (int)$b['room_id'], 'new_room_type_id' => $newRoomId,
        'old_total' => $q['current_total'], 'new_total' => $newTotal, 'bill_delta' => $billDelta,
        'rack_value_delta' => $q['rack_value_delta'], 'nights_affected' => $q['affected'], 'nights_stayed' => $q['stayed'],
        'old_rack_night' => $q['old_rack'], 'new_rack_night' => $q['new_rack'], 'assigned_room' => $assigned,
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
