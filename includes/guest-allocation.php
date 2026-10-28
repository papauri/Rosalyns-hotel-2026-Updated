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

require_once __DIR__ . '/pricing.php';

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

    /** Cheapest-to-dearest order of the occupancy price tiers. */
    function ga_tier_rank(string $tier): int
    {
        return ['single' => 1, 'double' => 2, 'triple' => 3][$tier] ?? 0;
    }

    /**
     * The cheapest tier a room with this many guests may be priced at: the cheapest ENABLED tier whose rank is at
     * least the guest count's (1 guest = single, 2 = double, 3 or more = triple), else the highest enabled tier
     * (e.g. 3 guests with Triple off floor at Double). Null when no tier is enabled.
     */
    function ga_tier_floor(int $guests, array $policy): ?string
    {
        $need = max(1, min(3, $guests));
        $enabled = [];
        foreach (['single', 'double', 'triple'] as $t) {
            if (!empty($policy[$t . '_enabled'])) $enabled[ga_tier_rank($t)] = $t;
        }
        if (!$enabled) return null;
        ksort($enabled);
        foreach ($enabled as $rank => $t) {
            if ($rank >= $need) return $t;
        }
        return end($enabled);
    }

    /**
     * How many guests one room can be priced for at a tier: Single 1, Double 2, Triple the room's capacity. The HIGHEST
     * ENABLED tier always counts as the full capacity (so with Triple off, Double covers a 3-guest room).
     */
    function ga_tier_capacity(string $tier, int $capacity, array $policy): int
    {
        $rank = ga_tier_rank($tier);
        if ($rank === 0) return 0;
        $capacity = max(1, $capacity);
        $highest = 0;
        foreach (['single', 'double', 'triple'] as $t) {
            if (!empty($policy[$t . '_enabled'])) $highest = ga_tier_rank($t);
        }
        if ($rank === 3 || $rank === $highest) return $capacity;
        return min($capacity, $rank);
    }

    /**
     * Owner rule (2026-10-23): the tiers chosen for the rooms of ONE room type in a booking must be able to seat that
     * type's guests: sum of ga_tier_capacity() >= guests. So 4 guests in 2 rooms (cap 3) may be Double+Double or
     * Triple+Single, never Double+Single or Single+Single. A dearer tier than needed is fine. Returns the refusal text
     * or null. $tiers = chosen tier per room, $labels = how each room is named ("Room 2"), $capacity = one room's capacity,
     * $policy = the policy the price uses. Whoever may override prices (ga_can_under_tier) saves anyway, with an audit note.
     */
    function ga_group_tier_message(string $typeName, array $labels, array $tiers, int $guests, int $capacity, array $policy): ?string
    {
        $tiers = array_values($tiers);
        $labels = array_values($labels);
        if (!$tiers || $guests < 1) return null;
        $label = function_exists('rh_occupancy_tier_label') ? 'rh_occupancy_tier_label' : 'ucfirst';
        $caps = [];
        foreach ($tiers as $t) {
            if (ga_tier_rank((string)$t) === 0) return null;
            $caps[] = ga_tier_capacity((string)$t, $capacity, $policy);
        }
        $priced = array_sum($caps);
        if ($priced >= $guests) return null;
        $enabledRanks = [];
        foreach (['single', 'double', 'triple'] as $t) {
            if (!empty($policy[$t . '_enabled'])) $enabledRanks[ga_tier_rank($t)] = $t;
        }
        ksort($enabledRanks);
        $guestWord = $guests . ' guest' . ($guests === 1 ? '' : 's');
        if (count($tiers) === 1) {
            foreach ($enabledRanks as $t) {
                if (ga_tier_capacity($t, $capacity, $policy) >= $guests) {
                    return $labels[0] . ' has ' . $guestWord . ' but is priced as ' . $label($tiers[0]) . '. Choose ' . $label($t) . ', or ask someone who can override prices.';
                }
            }
            return null;
        }
        // Several rooms: raise the cheapest room that can still go up to its next enabled tier.
        $order = array_keys($tiers);
        usort($order, fn($x, $y) => [ga_tier_rank((string)$tiers[$x]), $x] <=> [ga_tier_rank((string)$tiers[$y]), $y]);
        foreach ($order as $i) {
            foreach ($enabledRanks as $rank => $t) {
                if ($rank > ga_tier_rank((string)$tiers[$i])) {
                    return 'These ' . count($tiers) . ' ' . $typeName . ' rooms hold ' . $guestWord . ' but are priced for ' . $priced
                        . '. Raise ' . $labels[$i] . ' to ' . $label($t) . ', or ask someone who can override prices.';
                }
            }
        }
        return null;
    }

    /** One room: the same rule as ga_group_tier_message() for a group of one. $capacity 0 = unknown (assume the guests fit). */
    function ga_under_tier_message(string $roomLabel, int $seatedGuests, string $chosenTier, array $policy, int $capacity = 0): ?string
    {
        return ga_group_tier_message('', [$roomLabel], [$chosenTier], $seatedGuests, $capacity > 0 ? $capacity : max(3, $seatedGuests), $policy);
    }

    /** Who may deliberately price a room below its guests' tier: the same people who may override a room price. */
    function ga_can_under_tier(?array $user): bool
    {
        return in_array((string)($user['role'] ?? ''), ['admin', 'manager'], true);
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
        // Most children one room of this type takes (rooms.max_children; NULL = no limit beyond capacity).
        $maxChildrenPerRoom = null;
        if ($childGuests > 0 && function_exists('rh_child_rule')) {
            $maxChildrenPerRoom = rh_child_rule($room)['max'];
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
            if ($maxChildrenPerRoom !== null) {
                $childrenThisRoom = min($childrenThisRoom, $maxChildrenPerRoom);
            }
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
            if ($maxChildrenPerRoom !== null && $childrenThisRoom > $maxChildrenPerRoom) {
                return ga_failure('children_max', $roomsNeeded, rh_child_limit_error(rh_child_rule($room), $childrenThisRoom, true, $roomName) . ' Add adults, reduce the children or book another room type.');
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

        if ($maxChildrenPerRoom !== null && $remainingChildren > 0) {
            return ga_failure('children_max', $roomsNeeded, rh_child_limit_error(rh_child_rule($room), $maxChildrenPerRoom + 1, true, $roomName) . ' Add adults, reduce the children or book another room type.');
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
     * @param array $rows list of ['capacity' => int, 'children_allowed' => bool, 'name' => string, 'max_children' => ?int]
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
            if (isset($row['max_children']) && $row['max_children'] !== null) {
                $take = min($take, max(0, (int)$row['max_children']));
            }
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
            $capped = false;
            foreach ($rows as $row) {
                $capped = $capped || (!empty($row['children_allowed']) && isset($row['max_children']) && $row['max_children'] !== null);
            }
            if ($capped) {
                return $fail("{$childrenLeft} child(ren) cannot be seated: the rooms selected take only a limited number of children each (maximum children per room). Add another room or reduce the children.");
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
     * Seat as many guests as fit in an ordered list of rooms (one entry per room). Every room gets 1 adult first;
     * children then go to the first rooms that take children (respecting each room's capacity and child limit);
     * the remaining adults fill the rooms in order. Nothing is invented: guests that do not fit are reported.
     *
     * @param array $slots list of ['capacity' => int, 'children_allowed' => bool, 'max_children' => ?int]
     * @return array {rows: [['adults','children','guests'], ...], left_adults, left_children, message}
     *         message is '' when everyone is seated, otherwise one plain line saying why not.
     */
    function ga_seat_slots(array $slots, int $adults, int $children): array
    {
        $count = count($slots);
        $adults = max(0, $adults);
        $children = max(0, $children);
        if ($count < 1) {
            return ['rows' => [], 'left_adults' => $adults, 'left_children' => $children, 'message' => 'Please select at least one room.'];
        }
        if ($adults < $count) {
            return ['rows' => [], 'left_adults' => $adults, 'left_children' => $children,
                'message' => "Every room needs at least 1 adult, and there " . ($adults === 1 ? 'is only 1 adult' : "are only {$adults} adults") . " for {$count} rooms. Add adults or remove a room."];
        }
        $seated = [];
        foreach ($slots as $i => $slot) {
            $seated[$i] = ['adults' => 1, 'children' => 0, 'left' => max(1, (int)($slot['capacity'] ?? 1)) - 1];
        }
        $adultsLeft = $adults - $count;
        $childrenLeft = $children;
        $anyChildRoom = false;
        foreach ($slots as $i => $slot) {
            if (empty($slot['children_allowed'])) {
                continue;
            }
            $anyChildRoom = true;
            if ($childrenLeft <= 0) {
                continue;
            }
            $take = min($childrenLeft, $seated[$i]['left']);
            if (isset($slot['max_children']) && $slot['max_children'] !== null) {
                $take = min($take, max(0, (int)$slot['max_children']));
            }
            $seated[$i]['children'] += $take;
            $seated[$i]['left'] -= $take;
            $childrenLeft -= $take;
        }
        foreach ($slots as $i => $slot) {
            if ($adultsLeft <= 0) {
                break;
            }
            $take = min($adultsLeft, $seated[$i]['left']);
            $seated[$i]['adults'] += $take;
            $seated[$i]['left'] -= $take;
            $adultsLeft -= $take;
        }
        $rows = [];
        foreach ($seated as $part) {
            $rows[] = ['adults' => $part['adults'], 'children' => $part['children'], 'guests' => $part['adults'] + $part['children']];
        }
        $message = '';
        if ($childrenLeft > 0 && !$anyChildRoom) {
            $message = 'Children cannot stay in the rooms chosen so far. Add a room that takes children.';
        } elseif ($childrenLeft > 0) {
            $message = $childrenLeft === 1 ? '1 child still needs a room that takes children.' : "{$childrenLeft} children still need a room that takes children.";
        } elseif ($adultsLeft > 0) {
            $message = $adultsLeft === 1 ? '1 guest still needs a room.' : "{$adultsLeft} guests still need a room.";
        }
        return ['rows' => $rows, 'left_adults' => $adultsLeft, 'left_children' => $childrenLeft, 'message' => $message];
    }

    /**
     * One party over several room TYPES (owner request 2026-10-28). The chosen type is filled first: as many of its
     * rooms as are free (never more than the party needs), each to capacity. The overflow goes to the added rooms in
     * the order they were added (one room each). Children only go to rooms whose type takes children, every room has
     * at least 1 adult. Pure: availability is passed in, nothing is read or priced here.
     *
     * @param array $chosenRoom room-type row with the effective max_guests
     * @param int   $chosenFree rooms of the chosen type free for every night
     * @param array $extraRooms room-type rows in the order the guest added them (one entry per added room)
     * @return array {valid, reason, message, left_guests, chosen_rooms, rows[]} where each row is
     *         {room_id, room_name, is_extra, extra_index, guests, adults, children, occupancy_type}
     */
    function ga_plan_mixed(array $chosenRoom, int $chosenFree, array $extraRooms, int $totalGuests, int $childGuests): array
    {
        $fail = function (string $reason, string $message, array $rows = [], int $left = 0, int $chosenRooms = 0): array {
            return ['valid' => false, 'reason' => $reason, 'message' => $message, 'left_guests' => $left, 'chosen_rooms' => $chosenRooms, 'rows' => $rows];
        };
        $childGuests = max(0, $childGuests);
        $adultGuests = $totalGuests - $childGuests;
        if ($totalGuests < 1 || $adultGuests < 1) {
            return $fail('invalid_party', 'At least 1 adult is required for every booking.', [], max(0, $totalGuests));
        }
        $chosenCap = max(1, (int)($chosenRoom['max_guests'] ?? 1));
        $chosenRooms = min(max(0, $chosenFree), max(1, (int)ceil($totalGuests / $chosenCap)));
        if ($chosenRooms < 1) {
            return $fail('chosen_full', (string)($chosenRoom['name'] ?? 'This room type') . ' is fully booked for your dates.', [], $totalGuests);
        }

        $roomsInOrder = [];
        for ($i = 0; $i < $chosenRooms; $i++) {
            $roomsInOrder[] = ['room' => $chosenRoom, 'is_extra' => false, 'extra_index' => null];
        }
        foreach (array_values($extraRooms) as $k => $extra) {
            $roomsInOrder[] = ['room' => $extra, 'is_extra' => true, 'extra_index' => $k];
        }

        $slots = [];
        $policies = [];
        foreach ($roomsInOrder as $i => $entry) {
            $policy = ga_resolve_policy($entry['room']);
            $policies[$i] = $policy;
            $max = null;
            if ($childGuests > 0 && function_exists('rh_child_rule')) {
                $max = rh_child_rule($entry['room'])['max'];
            }
            $slots[] = [
                'capacity' => max(1, (int)($entry['room']['max_guests'] ?? 1)),
                'children_allowed' => !empty($policy['children_allowed']),
                'max_children' => $max,
            ];
        }

        $seat = ga_seat_slots($slots, $adultGuests, $childGuests);
        $left = (int)$seat['left_adults'] + (int)$seat['left_children'];
        if (!$seat['rows']) {
            return $fail('adults_per_room', (string)$seat['message'], [], $left, $chosenRooms);
        }

        $rows = [];
        foreach ($seat['rows'] as $i => $part) {
            $entry = $roomsInOrder[$i];
            $room = $entry['room'];
            $occupancy = ga_pick_occupancy((int)$part['guests'], $policies[$i]);
            if ($occupancy === null) {
                $tier = $part['guests'] === 1 ? 'single' : ($part['guests'] === 2 ? 'double' : 'triple');
                return $fail('no_pricing', (string)$room['name'] . " has no {$tier} rate enabled, so a room with {$part['guests']} guest" . ($part['guests'] === 1 ? '' : 's') . ' cannot be booked.', [], $left, $chosenRooms);
            }
            $rows[] = [
                'room_id' => (int)$room['id'],
                'room_name' => (string)$room['name'],
                'is_extra' => $entry['is_extra'],
                'extra_index' => $entry['extra_index'],
                'guests' => (int)$part['guests'],
                'adults' => (int)$part['adults'],
                'children' => (int)$part['children'],
                'occupancy_type' => $occupancy,
            ];
        }
        if ($left > 0) {
            return $fail('seats', (string)$seat['message'], $rows, $left, $chosenRooms);
        }
        return ['valid' => true, 'reason' => '', 'message' => '', 'left_guests' => 0, 'chosen_rooms' => $chosenRooms, 'rows' => $rows];
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
