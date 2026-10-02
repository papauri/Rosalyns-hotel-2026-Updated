<?php

/**
 * Guest allocation: party size -> rooms needed -> per-room split and occupancy tier.
 *
 * ONE rule set shared by booking.php (guest page + POST), check-availability.php
 * (AJAX), api/bookings.php and admin/create-booking.php. The JavaScript mirrors in
 * js/booking.js and admin/create-booking.php implement exactly the same steps.
 *
 * Rules
 *  1. Capacity per room = the room type's max_guests (callers pass the effective
 *     value, i.e. including joined-room / per-room overrides).
 *  2. Rooms needed = ceil(total guests / capacity). Rooms are filled greedily, so
 *     3 guests in a 2-guest room type = 2 rooms (2 + 1).
 *  3. Every room used must hold at least 1 adult.
 *  4. Children are refused outright if the room type does not allow children.
 *  5. Each room's occupancy tier (single/double/triple) comes from the guests in
 *     THAT room; a tier that is disabled makes the party impossible for the type.
 *  6. Whether that many rooms are free for every night is an availability question
 *     answered by checkRoomAvailability(); see ga_inventory_error().
 *
 * No money is calculated here; pricing stays in includes/pricing.php and callers.
 */

if (!function_exists('ga_resolve_policy')) {

    /**
     * Occupancy policy for a room type row (tiers enabled, children allowed).
     */
    function ga_resolve_policy(array $room): array
    {
        $policy = resolveOccupancyPolicy($room, null);

        // Occupancy price explicitly 0 (not NULL) disables that tier.
        if (array_key_exists('price_double_occupancy', $room)
            && ($room['price_double_occupancy'] === '0' || $room['price_double_occupancy'] === 0)) {
            $policy['double_enabled'] = 0;
        }
        if (array_key_exists('price_triple_occupancy', $room)
            && ($room['price_triple_occupancy'] === '0' || $room['price_triple_occupancy'] === 0)) {
            $policy['triple_enabled'] = 0;
        }

        return $policy;
    }

    /**
     * Occupancy tier for N guests sharing one room, or null if that tier is disabled.
     * More than 3 guests in one room (large-capacity rooms) use the highest enabled tier.
     */
    function ga_pick_occupancy(int $guestCount, array $policy): ?string
    {
        if ($guestCount === 1 && !empty($policy['single_enabled'])) return 'single';
        if ($guestCount === 2 && !empty($policy['double_enabled'])) return 'double';
        if ($guestCount === 3 && !empty($policy['triple_enabled'])) return 'triple';

        if ($guestCount > 3) {
            if (!empty($policy['triple_enabled'])) return 'triple';
            if (!empty($policy['double_enabled'])) return 'double';
            if (!empty($policy['single_enabled'])) return 'single';
        }

        return null;
    }

    /**
     * Nightly rate for one room at an occupancy tier (NULL/0 tier price = base price).
     */
    function ga_price_for_occupancy(array $room, string $occupancyType): float
    {
        if ($occupancyType === 'single') {
            return !empty($room['price_single_occupancy']) ? (float)$room['price_single_occupancy'] : (float)$room['price_per_night'];
        }
        if ($occupancyType === 'double') {
            return (isset($room['price_double_occupancy']) && (float)$room['price_double_occupancy'] > 0)
                ? (float)$room['price_double_occupancy']
                : (float)$room['price_per_night'];
        }
        if ($occupancyType === 'triple') {
            return (isset($room['price_triple_occupancy']) && (float)$room['price_triple_occupancy'] > 0)
                ? (float)$room['price_triple_occupancy']
                : (float)$room['price_per_night'];
        }
        return (float)$room['price_per_night'];
    }

    function ga_failure(string $reason, int $roomsNeeded, string $message): array
    {
        return [
            'valid' => false,
            'reason' => $reason,
            'rooms_needed' => $roomsNeeded,
            'allocation' => [],
            'message' => $message,
        ];
    }

    /**
     * Split a party across rooms of one type.
     *
     * @return array {valid, reason, rooms_needed, allocation[], message}
     *         reason is '' when valid, otherwise one of: invalid_party,
     *         children_not_allowed, adults_per_room, no_pricing, capacity.
     */
    function ga_build_allocation(int $totalGuests, int $childGuests, array $room, array $policy): array
    {
        $roomName = (string)($room['name'] ?? 'this room type');
        $maxGuestsPerRoom = max(1, (int)($room['max_guests'] ?? 1));
        $roomsNeeded = max(1, (int)ceil(max(1, $totalGuests) / $maxGuestsPerRoom));
        $childGuests = max(0, $childGuests);
        $adultGuests = $totalGuests - $childGuests;

        if ($totalGuests < 1) {
            return ga_failure('invalid_party', 1, 'Please choose at least 1 guest.');
        }
        if ($adultGuests < 1) {
            return ga_failure('invalid_party', $roomsNeeded, 'At least 1 adult is required for every booking.');
        }
        if ($childGuests > 0 && empty($policy['children_allowed'])) {
            return ga_failure('children_not_allowed', $roomsNeeded, "{$roomName} is adults only. Children cannot stay in this room type.");
        }
        if ($adultGuests < $roomsNeeded) {
            return ga_failure(
                'adults_per_room',
                $roomsNeeded,
                "{$totalGuests} guests need {$roomsNeeded} {$roomName} rooms (up to {$maxGuestsPerRoom} guests each) and every room needs at least 1 adult. Add adults or reduce the number of children."
            );
        }

        $allocation = [];
        $remainingGuests = $totalGuests;
        $remainingAdults = $adultGuests;
        $remainingChildren = $childGuests;

        for ($index = 0; $index < $roomsNeeded; $index++) {
            $roomsLeft = $roomsNeeded - $index;
            $laterRooms = max(0, $roomsLeft - 1);
            $guestsThisRoom = min($maxGuestsPerRoom, max(1, $remainingGuests - $laterRooms));
            $adultsAvailableThisRoom = $remainingAdults - $laterRooms;

            if ($adultsAvailableThisRoom < 1) {
                return ga_failure('adults_per_room', $roomsNeeded, 'At least one adult is required in each room.');
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
                return ga_failure('adults_per_room', $roomsNeeded, 'Unable to allocate guests while keeping at least one adult in each room.');
            }

            $occupancyType = ga_pick_occupancy($guestsThisRoom, $policy);
            if ($occupancyType === null) {
                return ga_failure(
                    'no_pricing',
                    $roomsNeeded,
                    "{$roomName} has no " . ($guestsThisRoom === 1 ? 'single' : ($guestsThisRoom === 2 ? 'double' : 'triple')) . " rate enabled, so a room with {$guestsThisRoom} guest" . ($guestsThisRoom === 1 ? '' : 's') . " cannot be booked."
                );
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
            return ga_failure('capacity', $roomsNeeded, 'Unable to allocate the full guest list across the required rooms.');
        }

        return [
            'valid' => true,
            'reason' => '',
            'rooms_needed' => $roomsNeeded,
            'allocation' => $allocation,
            'message' => '',
        ];
    }

    /**
     * Plain-language summary, e.g. "3 guests: 2 x Superior Suite (2 + 1 guests)".
     */
    function ga_describe_allocation(array $allocation, string $roomName, int $totalGuests = 0): string
    {
        if (empty($allocation)) {
            return '';
        }
        $counts = array_map(function ($part) {
            return (int)$part['guests'];
        }, $allocation);
        $rooms = count($allocation);
        $prefix = $totalGuests > 0 ? $totalGuests . ' guest' . ($totalGuests === 1 ? '' : 's') . ': ' : '';
        if ($rooms === 1) {
            return $prefix . '1 x ' . $roomName . ' (' . $counts[0] . ' guest' . ($counts[0] === 1 ? '' : 's') . ')';
        }
        return $prefix . $rooms . ' x ' . $roomName . ' (' . implode(' + ', $counts) . ' guests)';
    }

    /**
     * Number of rooms in an allocation that carry at least one child.
     */
    function ga_child_rooms(array $allocation): int
    {
        $n = 0;
        foreach ($allocation as $part) {
            if ((int)($part['children'] ?? 0) > 0) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Largest party sharing one room in this allocation (what the type-level
     * capacity check must be run against, not the whole party).
     */
    function ga_max_guests_in_one_room(array $allocation): int
    {
        $max = 0;
        foreach ($allocation as $part) {
            $max = max($max, (int)$part['guests']);
        }
        return $max;
    }

    /**
     * Seat a party in a given list of rooms (staff-chosen room lines, possibly of
     * different types). Same rules as ga_build_allocation: 1+ adult in every room,
     * nobody beyond a room's capacity, children only in rooms that allow them.
     * Children are seated first-room-first, then the remaining adults fill up.
     *
     * @param array $rows list of ['capacity' => int, 'children_allowed' => bool, 'name' => string]
     * @return array {valid, message, rows: [['adults' => int, 'children' => int, 'guests' => int], ...]}
     */
    function ga_distribute_party(array $rows, int $adults, int $children): array
    {
        $count = count($rows);
        $fail = function (string $message): array {
            return ['valid' => false, 'message' => $message, 'rows' => []];
        };
        if ($count < 1) {
            return $fail('Please select at least one room.');
        }
        if ($adults < $count) {
            return $fail("Each room needs at least one adult: {$adults} adult(s) cannot cover {$count} room(s). Add adults or remove rooms.");
        }

        $seated = [];
        foreach ($rows as $i => $row) {
            $seated[$i] = ['adults' => 1, 'children' => 0, 'left' => max(1, (int)($row['capacity'] ?? 1)) - 1];
        }

        $childrenLeft = $children;
        foreach ($rows as $i => $row) {
            if ($childrenLeft <= 0) {
                break;
            }
            if (empty($row['children_allowed'])) {
                continue;
            }
            $take = min($childrenLeft, $seated[$i]['left']);
            $seated[$i]['children'] += $take;
            $seated[$i]['left'] -= $take;
            $childrenLeft -= $take;
        }
        if ($childrenLeft > 0) {
            $anyAllowed = false;
            foreach ($rows as $row) {
                $anyAllowed = $anyAllowed || !empty($row['children_allowed']);
            }
            if (!$anyAllowed) {
                $names = array_unique(array_map(function ($row) {
                    return (string)($row['name'] ?? 'room');
                }, $rows));
                return $fail('Children are not allowed in ' . implode(', ', $names) . '. Remove the children or choose a family-friendly room type.');
            }
            return $fail("{$childrenLeft} child(ren) cannot be seated: the child-friendly rooms selected are full. Add a child-friendly room or reduce the party.");
        }

        $adultsLeft = $adults - $count;
        foreach ($rows as $i => $row) {
            if ($adultsLeft <= 0) {
                break;
            }
            $take = min($adultsLeft, $seated[$i]['left']);
            $seated[$i]['adults'] += $take;
            $seated[$i]['left'] -= $take;
            $adultsLeft -= $take;
        }
        if ($adultsLeft > 0) {
            $seats = 0;
            foreach ($rows as $row) {
                $seats += max(1, (int)($row['capacity'] ?? 1));
            }
            return $fail("The selected rooms hold up to {$seats} guest(s) in total (a room never takes more than its own capacity), but " . ($adults + $children) . ' were entered. Add more rooms (or a larger room type) so everyone is accommodated.');
        }

        $out = [];
        foreach ($seated as $part) {
            $out[] = [
                'adults' => $part['adults'],
                'children' => $part['children'],
                'guests' => $part['adults'] + $part['children'],
            ];
        }
        return ['valid' => true, 'message' => '', 'rows' => $out];
    }

    /**
     * One-call plan: policy + allocation. $room must carry the effective max_guests.
     */
    function ga_plan(array $room, int $totalGuests, int $childGuests): array
    {
        $policy = ga_resolve_policy($room);
        $plan = ga_build_allocation($totalGuests, $childGuests, $room, $policy);
        $plan['policy'] = $policy;
        $plan['summary'] = !empty($plan['valid'])
            ? ga_describe_allocation($plan['allocation'], (string)($room['name'] ?? 'room'), $totalGuests)
            : '';
        $plan['child_rooms_needed'] = ga_child_rooms($plan['allocation']);
        return $plan;
    }

    /**
     * Inventory gate for a plan: are enough rooms free for EVERY night?
     * $availability is the checkRoomAvailability() result for the allocation's
     * child-room count. Returns '' when fine, otherwise the guest-facing reason.
     */
    function ga_inventory_error(array $availability, int $roomsNeeded, string $roomName, string $checkIn, string $checkOut): string
    {
        if (empty($availability['available'])) {
            return (string)($availability['error'] ?? "{$roomName} is not available for {$checkIn} to {$checkOut}.");
        }
        $remaining = (int)($availability['remaining_rooms'] ?? 0);
        if ($roomsNeeded > $remaining) {
            if ($remaining <= 0) {
                return "{$roomName} is fully booked for {$checkIn} to {$checkOut}.";
            }
            return "Only {$remaining} {$roomName} room" . ($remaining === 1 ? '' : 's') . " free for every night of {$checkIn} to {$checkOut}, but your party needs {$roomsNeeded}.";
        }
        return '';
    }
}
