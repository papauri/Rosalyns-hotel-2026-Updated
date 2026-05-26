<?php

/**
 * Room Availability Check Endpoint (AJAX)
 * Called by booking.php JavaScript to check live availability
 * Returns JSON response with availability status
 */

require_once 'config/database.php';
require_once 'includes/pricing.php';

header('Content-Type: application/json');

// Rate limiting: max 30 availability checks per minute per session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$rate_key = 'avail_checks';
if (!isset($_SESSION[$rate_key])) {
    $_SESSION[$rate_key] = [];
}
$_SESSION[$rate_key] = array_filter($_SESSION[$rate_key], function ($t) {
    return $t > time() - 60;
});
if (count($_SESSION[$rate_key]) >= 30) {
    echo json_encode(['available' => false, 'message' => 'Too many requests. Please wait a moment.']);
    exit;
}
$_SESSION[$rate_key][] = time();

function availabilityResolveOccupancyPolicy(array $room): array
{
    $policy = resolveOccupancyPolicy($room, null);

    if (array_key_exists('price_double_occupancy', $room) && ($room['price_double_occupancy'] === '0' || $room['price_double_occupancy'] === 0)) {
        $policy['double_enabled'] = 0;
    }

    if (array_key_exists('price_triple_occupancy', $room) && ($room['price_triple_occupancy'] === '0' || $room['price_triple_occupancy'] === 0)) {
        $policy['triple_enabled'] = 0;
    }

    return $policy;
}

function availabilityPickOccupancyByGuestCount(int $guestCount, array $policy): ?string
{
    if ($guestCount === 1 && !empty($policy['single_enabled'])) {
        return 'single';
    }

    if ($guestCount === 2 && !empty($policy['double_enabled'])) {
        return 'double';
    }

    if ($guestCount === 3 && !empty($policy['triple_enabled'])) {
        return 'triple';
    }

    if ($guestCount > 3) {
        if (!empty($policy['triple_enabled'])) {
            return 'triple';
        }
        if (!empty($policy['double_enabled'])) {
            return 'double';
        }
        if (!empty($policy['single_enabled'])) {
            return 'single';
        }
    }

    return null;
}

function availabilityPriceForOccupancy(array $room, string $occupancyType): float
{
    if ($occupancyType === 'single') {
        return !empty($room['price_single_occupancy']) ? (float)$room['price_single_occupancy'] : (float)$room['price_per_night'];
    }

    if ($occupancyType === 'double') {
        return ($room['price_double_occupancy'] !== null && (float)$room['price_double_occupancy'] > 0)
            ? (float)$room['price_double_occupancy']
            : (float)$room['price_per_night'];
    }

    if ($occupancyType === 'triple') {
        return ($room['price_triple_occupancy'] !== null && (float)$room['price_triple_occupancy'] > 0)
            ? (float)$room['price_triple_occupancy']
            : (float)$room['price_per_night'];
    }

    return (float)$room['price_per_night'];
}

function availabilityBuildGuestAllocation(int $totalGuests, int $childGuests, array $room, array $policy): array
{
    $maxGuestsPerRoom = max(1, (int)($room['max_guests'] ?? 1));
    $roomsNeeded = max(1, (int)ceil($totalGuests / $maxGuestsPerRoom));
    $adultGuests = $totalGuests - $childGuests;

    if ($adultGuests < $roomsNeeded) {
        return [
            'valid' => false,
            'rooms_needed' => $roomsNeeded,
            'allocation' => [],
            'message' => "At least one adult is required in each room. This group needs {$roomsNeeded} rooms, so please add more adult guests or reduce the number of children."
        ];
    }

    $allocation = [];
    $remainingGuests = $totalGuests;
    $remainingAdults = $adultGuests;
    $remainingChildren = $childGuests;

    for ($index = 0; $index < $roomsNeeded; $index++) {
        $roomsLeft = $roomsNeeded - $index;
        $minGuestsForLaterRooms = max(0, $roomsLeft - 1);
        $guestsThisRoom = min($maxGuestsPerRoom, max(1, $remainingGuests - $minGuestsForLaterRooms));
        $adultReserveForLaterRooms = max(0, $roomsLeft - 1);
        $adultsAvailableThisRoom = $remainingAdults - $adultReserveForLaterRooms;

        if ($adultsAvailableThisRoom < 1) {
            return [
                'valid' => false,
                'rooms_needed' => $roomsNeeded,
                'allocation' => [],
                'message' => 'At least one adult is required in each room.'
            ];
        }

        $childrenThisRoom = min($remainingChildren, max(0, $guestsThisRoom - 1));
        $adultsThisRoom = $guestsThisRoom - $childrenThisRoom;

        if ($adultsThisRoom > $adultsAvailableThisRoom) {
            $adultsThisRoom = $adultsAvailableThisRoom;
            $childrenThisRoom = $guestsThisRoom - $adultsThisRoom;
        }

        if ($childrenThisRoom > $remainingChildren) {
            $childrenThisRoom = $remainingChildren;
            $adultsThisRoom = $guestsThisRoom - $childrenThisRoom;
        }

        if ($adultsThisRoom < 1 || $childrenThisRoom < 0) {
            return [
                'valid' => false,
                'rooms_needed' => $roomsNeeded,
                'allocation' => [],
                'message' => 'Unable to allocate guests while keeping at least one adult in each room.'
            ];
        }

        $occupancyType = availabilityPickOccupancyByGuestCount($guestsThisRoom, $policy);
        if ($occupancyType === null) {
            return [
                'valid' => false,
                'rooms_needed' => $roomsNeeded,
                'allocation' => [],
                'message' => "No enabled occupancy pricing can fit {$guestsThisRoom} guests in one {$room['name']} room."
            ];
        }

        $allocation[] = [
            'room_number' => $index + 1,
            'guests' => $guestsThisRoom,
            'adults' => $adultsThisRoom,
            'children' => $childrenThisRoom,
            'occupancy_type' => $occupancyType,
        ];

        $remainingGuests -= $guestsThisRoom;
        $remainingAdults -= $adultsThisRoom;
        $remainingChildren -= $childrenThisRoom;
    }

    if ($remainingGuests !== 0 || $remainingAdults !== 0 || $remainingChildren !== 0) {
        return [
            'valid' => false,
            'rooms_needed' => $roomsNeeded,
            'allocation' => [],
            'message' => 'Unable to allocate the full guest list across the required rooms.'
        ];
    }

    return [
        'valid' => true,
        'rooms_needed' => $roomsNeeded,
        'allocation' => $allocation,
    ];
}

function availabilityBuildSplitPricing(PDO $pdo, array $room, string $checkIn, string $checkOut, int $nights, int $totalGuests, int $childGuests): array
{
    $policy = availabilityResolveOccupancyPolicy($room);
    $allocationResult = availabilityBuildGuestAllocation($totalGuests, $childGuests, $room, $policy);
    $roomsNeeded = (int)($allocationResult['rooms_needed'] ?? 1);

    if (empty($allocationResult['valid'])) {
        return [
            'valid' => false,
            'rooms_needed' => $roomsNeeded,
            'message' => $allocationResult['message'] ?? 'This guest mix cannot be allocated across the selected room type.'
        ];
    }

    $childPriceMultiplier = isset($room['child_price_multiplier'])
        ? (float)$room['child_price_multiplier']
        : (float)getSetting('booking_child_price_multiplier', getSetting('child_guest_price_multiplier', 50));
    $childPriceMultiplier = max(0.0, $childPriceMultiplier);

    $allocation = [];
    $roomRateTotalPerNight = 0.0;
    $baseTotal = 0.0;
    $childSupplementTotal = 0.0;
    $discountPerNightTotal = 0.0;
    $ratePlan = null;

    foreach ($allocationResult['allocation'] as $index => $allocationRoom) {
        $guestsThisRoom = (int)$allocationRoom['guests'];
        $childrenThisRoom = (int)$allocationRoom['children'];
        $adultsThisRoom = (int)$allocationRoom['adults'];
        $occupancyType = $allocationRoom['occupancy_type'];
        $baseRate = availabilityPriceForOccupancy($room, $occupancyType);
        $dynamicResult = applyDynamicPricing($pdo, (int)$room['id'], $checkIn, $checkOut, $nights, $baseRate);
        $rateThisRoom = (float)$dynamicResult['final_price'];
        $childSupplementThisRoom = $childrenThisRoom > 0
            ? ($rateThisRoom * ($childPriceMultiplier / 100) * $childrenThisRoom * $nights)
            : 0.0;

        if (!empty($dynamicResult['rate_plan_id'])) {
            $ratePlan = [
                'rate_plan_id' => (int)$dynamicResult['rate_plan_id'],
                'rate_plan_label' => $dynamicResult['rate_plan_label'],
            ];
            $discountPerNightTotal += (float)$dynamicResult['discount_amount'];
        }

        $roomRateTotalPerNight += $rateThisRoom;
        $baseTotal += $rateThisRoom * $nights;
        $childSupplementTotal += $childSupplementThisRoom;
        $allocation[] = [
            'room_number' => $index + 1,
            'guests' => $guestsThisRoom,
            'adults' => $adultsThisRoom,
            'children' => $childrenThisRoom,
            'occupancy_type' => $occupancyType,
            'rate_per_night' => $rateThisRoom,
            'base_total' => $rateThisRoom * $nights,
            'child_supplement_total' => $childSupplementThisRoom,
        ];
    }

    $tourismLevyEnabled = (bool)getSetting('tourism_levy_enabled', false);
    $tourismLevyPercent = (float)getSetting('tourism_levy_percent', 0);
    $tourismLevyAmount = 0.0;
    if ($tourismLevyEnabled && $tourismLevyPercent > 0) {
        $tourismLevyAmount = ($baseTotal + $childSupplementTotal) * ($tourismLevyPercent / 100);
    }

    if ($ratePlan !== null) {
        $ratePlan['discount_amount_per_night_total'] = round($discountPerNightTotal, 2);
    }

    return [
        'valid' => true,
        'rooms_needed' => $roomsNeeded,
        'allocation' => $allocation,
        'room_rate_total_per_night' => round($roomRateTotalPerNight, 2),
        'base_total' => round($baseTotal, 2),
        'child_supplement_total' => round($childSupplementTotal, 2),
        'tourism_levy_amount' => round($tourismLevyAmount, 2),
        'tourism_levy_percent' => $tourismLevyPercent,
        'total_before_packages' => round($baseTotal + $childSupplementTotal + $tourismLevyAmount, 2),
        'rate_plan' => $ratePlan,
    ];
}

function availabilityBuildResponse(PDO $pdo, array $room, string $checkIn, string $checkOut, DateTime $checkInDate, DateTime $checkOutDate, int $childGuests, int $totalGuests, ?int $requestedRoomsNeeded = null): array
{
    $nights = $checkInDate->diff($checkOutDate)->days;

    if (roomTypeHasActiveCombinations((int)$room['id'])) {
        $combinations = getAvailableRoomCombinations((int)$room['id'], $checkIn, $checkOut);
        if (empty($combinations)) {
            return [
                'available' => false,
                'message' => 'All joined rooms for this room type are unavailable for the selected dates.',
                'remaining_rooms' => 0,
                'rooms_needed' => 1,
            ];
        }

        $combination = $combinations[0];
        $combinedRate = $combination['price_override'] !== null && $combination['price_override'] !== ''
            ? (float)$combination['price_override']
            : (float)$room['price_per_night'];
        $room['price_per_night'] = $combinedRate;
        $room['price_single_occupancy'] = $combinedRate;
        $room['price_double_occupancy'] = $combinedRate;
        $room['price_triple_occupancy'] = $combinedRate;
        $room['max_guests'] = max((int)($room['max_guests'] ?? 0), (int)($combination['max_guests_combined'] ?? 0));
    }

    $splitPricing = availabilityBuildSplitPricing($pdo, $room, $checkIn, $checkOut, $nights, $totalGuests, $childGuests);
    $roomsNeeded = (int)($splitPricing['rooms_needed'] ?? max(1, ceil($totalGuests / max(1, (int)($room['max_guests'] ?? 1)))));
    $childRoomsNeeded = 0;

    if (empty($splitPricing['valid'])) {
        return [
            'available' => false,
            'message' => $splitPricing['message'] ?? 'This guest count is not supported by the selected room type.',
            'remaining_rooms' => 0,
            'rooms_needed' => $roomsNeeded,
            'split_pricing' => $splitPricing,
        ];
    }

    foreach ($splitPricing['allocation'] as $allocatedRoom) {
        if ((int)($allocatedRoom['children'] ?? 0) > 0) {
            $childRoomsNeeded++;
        }
    }

    $availability = checkRoomAvailability((int)$room['id'], $checkIn, $checkOut, null, $childGuests, $childRoomsNeeded);
    $remainingRooms = (int)($availability['remaining_rooms'] ?? 0);
    $childEligibleRemainingRooms = (int)($availability['child_eligible_remaining_rooms'] ?? 0);

    if (empty($availability['available'])) {
        return [
            'available' => false,
            'message' => $availability['error'] ?? 'This room is not available for the selected dates.',
            'remaining_rooms' => $remainingRooms,
            'child_eligible_remaining_rooms' => $childEligibleRemainingRooms,
            'child_eligible_available_count' => (int)($availability['child_eligible_available_count'] ?? 0),
            'child_rooms_needed' => $childRoomsNeeded,
            'children_required' => $childRoomsNeeded > 0,
            'rooms_needed' => $roomsNeeded,
            'split_pricing' => $splitPricing,
            'conflicts' => $availability['conflicts'] ?? [],
            'conflict_message' => $availability['conflict_message'] ?? ''
        ];
    }

    if ($remainingRooms < $roomsNeeded) {
        return [
            'available' => false,
            'message' => "Only {$remainingRooms} {$room['name']} room" . ($remainingRooms === 1 ? '' : 's') . " available, but your group requires {$roomsNeeded}.",
            'remaining_rooms' => $remainingRooms,
            'child_eligible_remaining_rooms' => $childEligibleRemainingRooms,
            'child_eligible_available_count' => (int)($availability['child_eligible_available_count'] ?? 0),
            'child_rooms_needed' => $childRoomsNeeded,
            'children_required' => $childRoomsNeeded > 0,
            'rooms_needed' => $roomsNeeded,
            'split_pricing' => $splitPricing,
            'conflicts' => $availability['conflicts'] ?? [],
            'conflict_message' => $availability['conflict_message'] ?? ''
        ];
    }

    $primaryAllocation = $splitPricing['allocation'][0] ?? null;
    $pricingBasePerNight = $primaryAllocation
        ? availabilityPriceForOccupancy($room, $primaryAllocation['occupancy_type'])
        : (float)$room['price_per_night'];
    $preview = getDynamicPricingPreview($pdo, (int)$room['id'], $checkIn, $checkOut, $nights, $pricingBasePerNight);

    return [
        'available' => true,
        'room' => [
            'id' => (int)$room['id'],
            'name' => $room['name'],
            'price_per_night' => (float)$room['price_per_night'],
            'price_single_occupancy' => (float)($room['price_single_occupancy'] ?? $room['price_per_night']),
            'price_double_occupancy' => (float)($room['price_double_occupancy'] ?? $room['price_per_night']),
            'price_triple_occupancy' => (float)($room['price_triple_occupancy'] ?? $room['price_per_night']),
            'max_guests' => (int)$room['max_guests'],
            'rooms_available' => (int)$room['rooms_available'],
            'children_allowed' => (int)$room['children_allowed']
        ],
        'nights' => $nights,
        'total' => (float)$splitPricing['total_before_packages'],
        'remaining_rooms' => $remainingRooms,
        'child_eligible_remaining_rooms' => (int)($availability['child_eligible_remaining_rooms'] ?? $remainingRooms),
        'child_eligible_available_count' => (int)($availability['child_eligible_available_count'] ?? 0),
        'child_rooms_needed' => $childRoomsNeeded,
        'children_required' => $childRoomsNeeded > 0,
        'rooms_needed' => $roomsNeeded,
        'message' => 'Room is available for your selected dates',
        'dynamic_pricing' => $preview['dynamic_pricing'],
        'packages' => $preview['packages'],
        'split_pricing' => $splitPricing,
    ];
}

try {
    $room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
    $room_ids_raw = $_GET['room_ids'] ?? '';
    $room_ids = [];
    if ($room_ids_raw !== '') {
        $room_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $room_ids_raw)), function ($value) {
            return $value > 0;
        })));
    }
    $check_in = $_GET['check_in'] ?? '';
    $check_out = $_GET['check_out'] ?? '';
    $child_guests = max(0, (int)($_GET['child_guests'] ?? $_GET['children'] ?? 0));
    $total_guests = max(1, (int)($_GET['number_of_guests'] ?? 0));
    $adult_guests = max(1, (int)($_GET['adult_guests'] ?? max(1, $total_guests - $child_guests)));
    $requested_rooms_needed = isset($_GET['rooms_needed']) ? max(1, (int)$_GET['rooms_needed']) : null;

    // Validate inputs
    if ((!$room_id && empty($room_ids)) || empty($check_in) || empty($check_out)) {
        echo json_encode([
            'available' => false,
            'message' => 'Missing required parameters: room_id or room_ids, check_in, check_out'
        ]);
        exit;
    }

    // Validate dates
    $checkInDate = new DateTime($check_in);
    $checkOutDate = new DateTime($check_out);
    $today = new DateTime('today');

    if ($checkInDate < $today) {
        echo json_encode([
            'available' => false,
            'message' => 'Check-in date cannot be in the past'
        ]);
        exit;
    }

    if ($checkOutDate <= $checkInDate) {
        echo json_encode([
            'available' => false,
            'message' => 'Check-out date must be after check-in date'
        ]);
        exit;
    }

    // Check advance booking restriction
    $maxAdvanceDays = (int)getSetting('max_advance_booking_days', 365);
    $maxAdvanceDate = new DateTime();
    $maxAdvanceDate->modify('+' . $maxAdvanceDays . ' days');

    if ($checkInDate > $maxAdvanceDate) {
        echo json_encode([
            'available' => false,
            'message' => "Bookings can only be made up to {$maxAdvanceDays} days in advance."
        ]);
        exit;
    }

    if (!empty($room_ids)) {
        $placeholders = implode(',', array_fill(0, count($room_ids), '?'));
        $batchStmt = $pdo->prepare("
                 SELECT id, name, price_per_night, price_single_occupancy, price_double_occupancy,
                     price_triple_occupancy, child_price_multiplier,
                     GREATEST(
                      max_guests,
                      COALESCE((SELECT MAX(ir.max_guests_override) FROM individual_rooms ir WHERE ir.room_type_id = rooms.id AND ir.is_active = 1), 0),
                      COALESCE((SELECT MAX(rc.max_guests_combined) FROM room_combinations rc WHERE rc.combined_room_type_id = rooms.id AND rc.is_active = 1), 0)
                     ) AS max_guests,
                     rooms_available,
                   total_rooms, children_allowed, single_occupancy_enabled, double_occupancy_enabled,
                   triple_occupancy_enabled
            FROM rooms
            WHERE id IN ({$placeholders}) AND is_active = 1
        ");
        $batchStmt->execute($room_ids);
        $rooms = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
        $responses = [];

        foreach ($rooms as $roomRow) {
            $responses[(int)$roomRow['id']] = availabilityBuildResponse(
                $pdo,
                $roomRow,
                $check_in,
                $check_out,
                $checkInDate,
                $checkOutDate,
                $child_guests,
                $total_guests,
                null
            );
        }

        foreach ($room_ids as $requestedRoomId) {
            if (!isset($responses[$requestedRoomId])) {
                $responses[$requestedRoomId] = [
                    'available' => false,
                    'message' => 'Room not found or not available',
                    'remaining_rooms' => 0,
                    'rooms_needed' => 0,
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'batch' => true,
            'rooms' => $responses,
        ]);
        exit;
    }

    // Check if room exists and is active
    $roomStmt = $pdo->prepare("
         SELECT id, name, price_per_night, price_single_occupancy, price_double_occupancy,
             price_triple_occupancy, child_price_multiplier,
             GREATEST(
                 max_guests,
                 COALESCE((SELECT MAX(ir.max_guests_override) FROM individual_rooms ir WHERE ir.room_type_id = rooms.id AND ir.is_active = 1), 0),
                 COALESCE((SELECT MAX(rc.max_guests_combined) FROM room_combinations rc WHERE rc.combined_room_type_id = rooms.id AND rc.is_active = 1), 0)
             ) AS max_guests,
             rooms_available,
               total_rooms, children_allowed, single_occupancy_enabled, double_occupancy_enabled,
               triple_occupancy_enabled
        FROM rooms
        WHERE id = ? AND is_active = 1
    ");
    $roomStmt->execute([$room_id]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        echo json_encode([
            'available' => false,
            'message' => 'Room not found or not available'
        ]);
        exit;
    }

    echo json_encode(availabilityBuildResponse(
        $pdo,
        $room,
        $check_in,
        $check_out,
        $checkInDate,
        $checkOutDate,
        $child_guests,
        $total_guests,
        $requested_rooms_needed
    ));
} catch (Exception $e) {
    error_log("Availability check error: " . $e->getMessage());
    echo json_encode([
        'available' => false,
        'message' => 'Unable to check availability. Please try again.'
    ]);
}
