<?php

/**
 * Room Availability Check Endpoint (AJAX)
 * Called by booking.php JavaScript to check live availability
 * Returns JSON response with availability status
 */

require_once 'config/database.php';
require_once 'includes/pricing.php';
require_once 'includes/guest-allocation.php';
require_once 'includes/mixed-booking.php';
require_once __DIR__ . '/includes/demand-log.php'; // lost-demand log (never affects the answer)

header('Content-Type: application/json');

// Rate limiting: max 30 availability checks per minute per session
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/includes/session-boot.php'; rh_session_start();
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

// Allocation rules (rooms needed, per-room split, occupancy tier, children policy,
// 1+ adult per room) live in includes/guest-allocation.php and are shared with
// booking.php, api/bookings.php and admin/create-booking.php.

function availabilityBuildSplitPricing(PDO $pdo, array $room, string $checkIn, string $checkOut, int $nights, int $totalGuests, int $childGuests): array
{
    $allocationResult = ga_plan($room, $totalGuests, $childGuests);
    $roomsNeeded = (int)($allocationResult['rooms_needed'] ?? 1);

    if (empty($allocationResult['valid'])) {
        return [
            'valid' => false,
            'reason' => $allocationResult['reason'] ?? 'invalid_party',
            'rooms_needed' => $roomsNeeded,
            'message' => $allocationResult['message'] ?? 'This guest mix cannot be allocated across the selected room type.'
        ];
    }

    // One child-charge rule per room type (includes/pricing.php), the same one booking.php saves with.
    $childRule = rh_child_rule($room);

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
        $baseRate = ga_price_for_occupancy($room, $occupancyType);
        $dynamicResult = applyDynamicPricing($pdo, (int)$room['id'], $checkIn, $checkOut, $nights, $baseRate);
        $rateThisRoom = (float)$dynamicResult['final_price'];
        $childSupplementThisRoom = rh_child_supplement($childRule, $childrenThisRoom, (int)$nights, $rateThisRoom);

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

    // Totals via the shared helper, per allocated room exactly as booking.php does
    // (levy outside the VAT base; packages are added on the client with VAT, no levy).
    $netTotal = 0.0;
    $vatTotal = 0.0;
    $tourismLevyAmount = 0.0;
    $totalWithVat = 0.0;
    $vatRate = 0.0;
    $tourismLevyPercent = 0.0;
    foreach ($allocation as $i => $part) {
        $t = rh_stay_totals((float)($part['base_total'] + $part['child_supplement_total']), 'price');
        $netTotal += $t['net'];
        $vatTotal += $t['vat'];
        $tourismLevyAmount += $t['levy'];
        $totalWithVat += $t['total_with_vat'];
        $vatRate = $t['vat_rate'];
        $tourismLevyPercent = $t['levy_rate'];
        $allocation[$i]['net'] = $t['net'];
        $allocation[$i]['levy'] = $t['levy'];
        $allocation[$i]['vat'] = $t['vat'];
        $allocation[$i]['total_with_vat'] = $t['total_with_vat'];
    }

    if ($ratePlan !== null) {
        $ratePlan['discount_amount_per_night_total'] = round($discountPerNightTotal, 2);
    }

    return [
        'valid' => true,
        'reason' => '',
        'rooms_needed' => $roomsNeeded,
        'summary' => $allocationResult['summary'] ?? '',
        'allocation' => $allocation,
        'room_rate_total_per_night' => round($roomRateTotalPerNight, 2),
        'base_total' => round($baseTotal, 2),
        'child_supplement_total' => round($childSupplementTotal, 2),
        'tourism_levy_amount' => round($tourismLevyAmount, 2),
        'tourism_levy_percent' => $tourismLevyPercent,
        'net' => round($netTotal, 2),
        'levy' => round($tourismLevyAmount, 2),
        'vat' => round($vatTotal, 2),
        'total_with_vat' => round($totalWithVat, 2),
        'vat_rate' => $vatRate,
        'vat_mode' => function_exists('vat_mode') ? vat_mode() : 'off',
        'vat_shows_amount' => function_exists('vat_shows_amount') ? vat_shows_amount() : false,
        'total_before_packages' => round($totalWithVat, 2),
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
                'reason' => 'not_enough_rooms',
                'remaining_rooms' => 0,
                'rooms_free' => 0,
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
        // The party itself is impossible for this room type. Still report how many
        // rooms of the type are free so the page can explain the whole picture.
        $inventoryOnly = checkRoomAvailability((int)$room['id'], $checkIn, $checkOut, null, 0, 0);
        $freeRooms = !empty($inventoryOnly['available']) ? (int)($inventoryOnly['remaining_rooms'] ?? 0) : 0;
        return [
            'available' => false,
            'message' => $splitPricing['message'] ?? 'This guest count is not supported by the selected room type.',
            'reason' => $splitPricing['reason'] ?? 'invalid_party',
            'remaining_rooms' => $freeRooms,
            'rooms_free' => $freeRooms,
            'rooms_needed' => $roomsNeeded,
            'split_pricing' => $splitPricing,
        ];
    }

    $childRoomsNeeded = ga_child_rooms($splitPricing['allocation']);

    $availability = checkRoomAvailability((int)$room['id'], $checkIn, $checkOut, null, $childGuests, $childRoomsNeeded);
    $remainingRooms = (int)($availability['remaining_rooms'] ?? 0);
    $childEligibleRemainingRooms = (int)($availability['child_eligible_remaining_rooms'] ?? 0);

    if (empty($availability['available'])) {
        $unavailableReason = 'unavailable';
        if ($childRoomsNeeded > 0 && $remainingRooms > 0 && $childEligibleRemainingRooms < $childRoomsNeeded) {
            $unavailableReason = 'child_rooms';
        } elseif ($remainingRooms <= 0 && empty($availability['blocked_dates'])) {
            $unavailableReason = 'not_enough_rooms';
        }
        return [
            'available' => false,
            'message' => $availability['error'] ?? 'This room is not available for the selected dates.',
            'reason' => $unavailableReason,
            'rooms_free' => $remainingRooms,
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
            'message' => ga_inventory_error($availability, $roomsNeeded, (string)$room['name'], $checkIn, $checkOut),
            'reason' => 'not_enough_rooms',
            'rooms_free' => $remainingRooms,
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
        ? ga_price_for_occupancy($room, $primaryAllocation['occupancy_type'])
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
        'reason' => '',
        'plan_summary' => $splitPricing['summary'] ?? '',
        'rooms_free' => $remainingRooms,
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
    $maxAdvanceDays = (int)getSetting('max_advance_booking_days', 30);
    $maxAdvanceDate = new DateTime();
    $maxAdvanceDate->modify('+' . $maxAdvanceDays . ' days');

    if ($checkInDate > $maxAdvanceDate) {
        echo json_encode([
            'available' => false,
            'message' => "Bookings can only be made up to {$maxAdvanceDays} days in advance."
        ]);
        exit;
    }

    // Mixed-room mode (one party in rooms of different types): the chosen type cannot seat everyone, so the guest adds
    // rooms of other types. The seating, the alternatives and the prices all come from the shared helpers; the page
    // only displays them.  GET: room_id, check_in, check_out, number_of_guests, child_guests, extra_room_types=1,2,2
    if (($_GET['mode'] ?? '') === 'mixed') {
        $extraIds = rh_mixed_parse_extras($_GET['extra_room_types'] ?? '');
        $chosenRoom = rh_mixed_load_room($pdo, $room_id);
        if (!$chosenRoom) {
            echo json_encode(['success' => true, 'mode' => 'mixed', 'needed' => false, 'message' => 'Room not found or not available']);
            exit;
        }
        $mixedNights = (int)$checkInDate->diff($checkOutDate)->days;
        $resolved = rh_mixed_resolve($pdo, $room_id, $extraIds, $check_in, $check_out, $total_guests, $child_guests);

        // Needed only when the party itself is fine for the chosen type and just too few of its rooms are free.
        $ordinary = ga_plan($chosenRoom, $total_guests, $child_guests);
        $chosenFree = (int)$resolved['chosen_free'];
        if ($chosenFree === 0) {
            $chosenFree = rh_mixed_free_rooms($room_id, $check_in, $check_out);
        }
        // Also needed when the chosen type is adults only and children are coming: the children go in an added room.
        $why = rh_mixed_reason($chosenRoom, $chosenFree, $total_guests, $child_guests);
        $needed = $why !== '';
        $mixedMessage = $needed ? (string)$resolved['message'] : '';
        $adultsOnlyBlocked = $why === 'children' && ($total_guests - $child_guests) < 2;
        if ($adultsOnlyBlocked) {
            $mixedMessage = $chosenRoom['name'] . ' is adults only, so this party needs at least 2 adults: 1 to stay in ' . $chosenRoom['name'] . ' and 1 with the children in a room that takes children.';
        }

        $resp = [
            'success' => true,
            'mode' => 'mixed',
            'needed' => $needed,
            'why' => $why,
            'ok' => $needed && !empty($resolved['ok']),
            'message' => $mixedMessage,
            'nights' => $mixedNights,
            'chosen' => ['id' => (int)$chosenRoom['id'], 'name' => (string)$chosenRoom['name'], 'rooms_free' => $chosenFree, 'max_guests' => (int)$chosenRoom['max_guests'], 'rooms_needed_alone' => (int)$ordinary['rooms_needed']],
            'party' => ['guests' => $total_guests, 'children' => $child_guests],
            'rows' => [],
            'options' => [],
            'left_guests' => $total_guests,
        ];
        if ($needed && $resolved['plan']) {
            $resp['left_guests'] = (int)$resolved['plan']['left_guests'];
            $priced = rh_mixed_price_plan($pdo, $resolved, $check_in, $check_out);
            $rowsOut = [];
            if ($priced['error'] === '') {
                foreach ($priced['rows'] as $r) {
                    $rowsOut[] = [
                        'room_id' => $r['room_id'], 'room_name' => $r['room_name'], 'is_extra' => $r['is_extra'], 'extra_index' => $r['extra_index'],
                        'guests' => $r['guests'], 'adults' => $r['adults'], 'children' => $r['children'], 'occupancy_type' => $r['occupancy_type'],
                        'rate_per_night' => round($r['priced']['rate_per_night'], 2),
                        'child_supplement_total' => round($r['priced']['child_supplement'], 2),
                        'total_with_vat' => round($r['priced']['stay']['total_with_vat'], 2),
                    ];
                }
                $ratePlan = null;
                $discountPerNight = 0.0;
                foreach ($priced['rows'] as $r) {
                    if (!empty($r['priced']['dynamic']['rate_plan_id'])) {
                        $ratePlan = $ratePlan ?: ['rate_plan_id' => (int)$r['priced']['dynamic']['rate_plan_id'], 'rate_plan_label' => $r['priced']['dynamic']['rate_plan_label']];
                        $discountPerNight += (float)$r['priced']['dynamic']['discount_amount'];
                    }
                }
                if ($ratePlan) {
                    $ratePlan['discount_amount_per_night_total'] = round($discountPerNight, 2);
                }
                $first = $priced['rows'] ? $priced['rows'][0]['priced']['stay'] : null;
                $leadBase = $priced['rows'] ? ga_price_for_occupancy($chosenRoom, $priced['rows'][0]['occupancy_type']) : (float)$chosenRoom['price_per_night'];
                $preview = getDynamicPricingPreview($pdo, (int)$chosenRoom['id'], $check_in, $check_out, $mixedNights, $leadBase);
                $resp['total_with_vat'] = round($priced['total_with_vat'], 2);
                $resp['net'] = round($priced['net'], 2);
                $resp['levy'] = round($priced['levy'], 2);
                $resp['vat'] = round($priced['vat'], 2);
                $resp['vat_rate'] = $first ? $first['vat_rate'] : 0;
                $resp['tourism_levy_percent'] = $first ? $first['levy_rate'] : 0;
                $resp['vat_mode'] = function_exists('vat_mode') ? vat_mode() : 'off';
                $resp['vat_shows_amount'] = function_exists('vat_shows_amount') ? vat_shows_amount() : false;
                $resp['rate_plan'] = $ratePlan;
                $resp['packages'] = $preview['packages'];
                $resp['dynamic_pricing'] = $preview['dynamic_pricing'];
            } elseif ($resp['message'] === '') {
                $resp['message'] = $priced['error'];
            }
            $resp['rows'] = $rowsOut;
            $resp['options'] = $adultsOnlyBlocked ? [] : rh_mixed_options($pdo, $resolved, $check_in, $check_out, $total_guests, $child_guests);
        }
        echo json_encode($resp);
        exit;
    }

    if (!empty($room_ids)) {
        $placeholders = implode(',', array_fill(0, count($room_ids), '?'));
        $batchStmt = $pdo->prepare("
                 SELECT id, name, price_per_night, price_single_occupancy, price_double_occupancy,
                     price_triple_occupancy, child_price_multiplier, child_charge_mode, child_charge_amount, free_children, max_children,
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
        // Lost demand (Assistant rate suggestions): logged after the answer is sent; errors never reach the guest.
        $demandRows = [];
        foreach ($responses as $demandRoomId => $demandResp) {
            $demandRows[] = ['room_type_id' => (int)$demandRoomId, 'check_in' => $check_in, 'check_out' => $check_out, 'guests' => $total_guests, 'available' => !empty($demandResp['available']), 'reason' => (string)($demandResp['reason'] ?? 'unavailable')];
        }
        rh_demand_log($pdo, $demandRows);
        exit;
    }

    // Check if room exists and is active
    $roomStmt = $pdo->prepare("
         SELECT id, name, price_per_night, price_single_occupancy, price_double_occupancy,
             price_triple_occupancy, child_price_multiplier, child_charge_mode, child_charge_amount, free_children, max_children,
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

    $availabilityResponse = availabilityBuildResponse(
        $pdo,
        $room,
        $check_in,
        $check_out,
        $checkInDate,
        $checkOutDate,
        $child_guests,
        $total_guests,
        $requested_rooms_needed
    );
    echo json_encode($availabilityResponse);
    // Lost demand (Assistant rate suggestions): logged after the answer is sent; errors never reach the guest.
    rh_demand_log($pdo, [['room_type_id' => (int)$room['id'], 'check_in' => $check_in, 'check_out' => $check_out, 'guests' => $total_guests, 'available' => !empty($availabilityResponse['available']), 'reason' => (string)($availabilityResponse['reason'] ?? 'unavailable')]]);
} catch (Exception $e) {
    error_log("Availability check error: " . $e->getMessage());
    echo json_encode([
        'available' => false,
        'message' => 'Unable to check availability. Please try again.'
    ]);
}
