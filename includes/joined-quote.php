<?php

/**
 * Joined-room live quote helpers (check-availability.php).
 *
 * The saved booking (booking.php) reserves ONE joined pair per room the party needs and
 * prices each room off its own pair. The live quote must do the same, or a party that
 * needs 2+ pairs with different prices sees a quote that differs from the saved total.
 * Pure functions: no database, no settings.
 */

/**
 * The pairs a party would be given, in the order booking.php takes them: walk the
 * available pairs in list order and keep each one that shares no physical room with a
 * pair already kept (101+102 and 102+103 cannot both be sold).
 *
 * @param array $availableCombinations rows from getAvailableRoomCombinations()
 * @param int   $limit                 stop after this many pairs (0 = no limit)
 */
function rh_joined_quote_queue(array $availableCombinations, int $limit = 0): array
{
    $queue = [];
    $claimedPhysicalRooms = [];
    foreach ($availableCombinations as $candidate) {
        if ($limit > 0 && count($queue) >= $limit) {
            break;
        }
        $candidateRoomIds = [(int)($candidate['room_a_id'] ?? 0), (int)($candidate['room_b_id'] ?? 0)];
        if (array_intersect($candidateRoomIds, $claimedPhysicalRooms)) {
            continue;
        }
        $claimedPhysicalRooms = array_merge($claimedPhysicalRooms, $candidateRoomIds);
        $queue[] = $candidate;
    }
    return $queue;
}

/**
 * Nightly rate of one joined pair: its own price when one is set, otherwise the room
 * type's price per night.
 */
function rh_joined_quote_rate(array $combination, float $typePricePerNight): float
{
    $override = $combination['price_override'] ?? null;
    return ($override !== null && $override !== '') ? (float)$override : $typePricePerNight;
}

/**
 * The room type row with every occupancy price set to one joined pair's rate (a joined
 * pair is sold at one price whatever the occupancy), as booking.php does per room.
 */
function rh_joined_quote_room(array $room, array $combination, float $typePricePerNight): array
{
    $rate = rh_joined_quote_rate($combination, $typePricePerNight);
    $room['price_per_night'] = $rate;
    $room['price_single_occupancy'] = $rate;
    $room['price_double_occupancy'] = $rate;
    $room['price_triple_occupancy'] = $rate;
    return $room;
}
