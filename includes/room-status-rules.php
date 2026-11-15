<?php
/**
 * Room status rules (step 12a) - pure functions, no database, no other includes.
 * Loaded by config/database.php; scripts/tests/rooms_housekeeping_12a.php tests them without a database.
 */

if (!function_exists('rh_vacated_room_target')) {
    /**
     * What a room becomes when a guest leaves it (checkout or in-house room move):
     *  - null          leave the room as it is, no cleaning task: another in-house guest still holds it, or it is
     *                  out of service (out of order / maintenance) - the release from that state brings the clean;
     *  - 'maintenance' an active maintenance task that blocks the room is waiting for it (the clean waits too);
     *  - 'cleaning'    everything else.
     */
    function rh_vacated_room_target(string $currentStatus, bool $heldByOtherInHouse, bool $activeMaintenance): ?string
    {
        if ($heldByOtherInHouse) {
            return null;
        }
        if (in_array($currentStatus, ['out_of_order', 'maintenance'], true)) {
            return null;
        }
        if ($activeMaintenance) {
            return 'maintenance';
        }
        return 'cleaning';
    }
}

if (!function_exists('rh_checkin_room_hard_block')) {
    /**
     * Check-in clean-room gate: a room in one of these states can never be checked into, not even with the
     * "Check-in to Room Not Clean" permission (that override only covers a room that still needs cleaning).
     * Returns the plain reason, or null when the state is not a hard block.
     */
    function rh_checkin_room_hard_block(string $status): ?string
    {
        switch ($status) {
            case 'occupied':
                return 'another guest is still in the room';
            case 'maintenance':
                return 'under maintenance';
            case 'out_of_order':
                return 'out of order';
        }
        return null;
    }
}

if (!function_exists('rh_room_status_contradiction')) {
    /**
     * Individual Rooms status change: refuse a status that contradicts the bookings.
     * $inHouse = a checked-in booking holds the room right now. Returns the plain reason, or null when allowed.
     */
    function rh_room_status_contradiction(string $newStatus, bool $inHouse): ?string
    {
        if ($inHouse && in_array($newStatus, ['available', 'cleaning'], true)) {
            return 'a guest is checked in to it. Check the guest out (or move them) first';
        }
        if (!$inHouse && $newStatus === 'occupied') {
            return 'nobody is checked in to it. Rooms become Occupied when a guest is checked in';
        }
        return null;
    }
}
