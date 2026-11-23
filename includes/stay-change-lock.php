<?php
/**
 * Stay date / room changes lock like booking creation (GRID_UNIFY_PLAN Phase 2b, owner 2026-11-23: "Yes fix it so
 * there is overlaping errors and its clear").
 *
 * Lock order, the same as booking creation, room assignment, block saves and maintenance saves (no deadlock cycle):
 *   [booking row] -> room types (ascending, rh_lock_room_types) -> individual_rooms (ascending, FOR UPDATE)
 * The type + room set is worked out BEFORE beginTransaction() (rh_stay_lock_plan: plain reads). rh_stay_take_locks()
 * must be the FIRST statements of the save transaction: InnoDB opens the read snapshot at the first plain read, and a
 * snapshot taken before the locks would miss a block, job or booking saved while this save waited for them.
 * The availability re-check (rh_validate_booking_stay_change, config/database.php) then runs inside the locks, and a
 * clash is explained by rh_stay_clash_text(): "Not saved: Room 4 is blocked on 3 to 4 Jan 2027 (Maintenance: roof
 * leak). Choose other dates or another room." Messages carry guest names: show them as text, never as HTML.
 */

require_once __DIR__ . '/room-night-clash.php'; // rh_room_nights_take_locks, rh_maint_job_nights, rh_rnc_days
require_once __DIR__ . '/block-log.php';        // rh_block_nights_text, rh_block_label, rh_block_type_label

if (!function_exists('rh_stay_lock_plan')) {

    /** Shown when the booking's room changed between the plan and the locks (the locks would not cover it). */
    function rh_stay_lock_changed_message(): string
    {
        return 'This booking was changed by someone else a moment ago. Nothing was saved. Refresh the page and try again.';
    }

    /**
     * What a stay change must lock, worked out BEFORE beginTransaction() (plain reads): the booking's room type and
     * every room it holds (individual_room_id + unreleased booking_rooms), plus $extraTypeIds / $extraRoomIds (a room
     * the booking moves to, with its joined-pair partner rooms). Types are widened through joined rooms
     * (rh_related_room_type_ids), as booking creation does.
     *
     * @return array{booking_id:int,type_ids:int[],room_ids:int[]}
     */
    function rh_stay_lock_plan(PDO $pdo, int $bookingId, array $extraTypeIds = [], array $extraRoomIds = []): array
    {
        $st = $pdo->prepare('SELECT room_id, individual_room_id FROM bookings WHERE id = ?');
        $st->execute([$bookingId]);
        $b = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $types = array_merge([(int)($b['room_id'] ?? 0)], array_map('intval', $extraTypeIds));
        $rooms = array_merge([(int)($b['individual_room_id'] ?? 0)], $b ? getBookingRoomIds($bookingId) : []);
        $extra = array_values(array_filter(array_map('intval', $extraRoomIds), static fn($r) => $r > 0));
        if ($extra && bookingAvailabilityTableExists('room_combinations')) {
            // A room of a joined type is taken as its PAIR (rh_validate_booking_stay_change checks the partner too).
            $ph = implode(',', array_fill(0, count($extra), '?'));
            $pc = $pdo->prepare("SELECT room_a_id, room_b_id FROM room_combinations WHERE is_active = 1 AND (room_a_id IN ($ph) OR room_b_id IN ($ph))");
            $pc->execute(array_merge($extra, $extra));
            foreach ($pc->fetchAll(PDO::FETCH_NUM) as [$ra, $rb]) {
                $extra[] = (int)$ra;
                $extra[] = (int)$rb;
            }
        }
        $rooms = array_values(array_unique(array_filter(array_merge($rooms, $extra), static fn($r) => (int)$r > 0)));
        $rooms = array_map('intval', $rooms);
        sort($rooms, SORT_NUMERIC);
        if ($rooms) {
            $ph = implode(',', array_fill(0, count($rooms), '?'));
            $rt = $pdo->prepare("SELECT DISTINCT room_type_id FROM individual_rooms WHERE id IN ($ph)");
            $rt->execute($rooms);
            foreach ($rt->fetchAll(PDO::FETCH_COLUMN) as $t) {
                $types[] = (int)$t;
            }
        }
        return ['booking_id' => $bookingId, 'type_ids' => rh_related_room_type_ids($pdo, $types), 'room_ids' => $rooms];
    }

    /** True when the plan's locks cover these types and rooms. */
    function rh_stay_plan_covers(array $plan, array $typeIds, array $roomIds): bool
    {
        $t = array_flip(array_map('intval', (array)($plan['type_ids'] ?? [])));
        $r = array_flip(array_map('intval', (array)($plan['room_ids'] ?? [])));
        foreach ($typeIds as $id) {
            if ((int)$id > 0 && !isset($t[(int)$id])) {
                return false;
            }
        }
        foreach ($roomIds as $id) {
            if ((int)$id > 0 && !isset($r[(int)$id])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Take the plan's locks: the FIRST statements of the save transaction. Booking row (FOR UPDATE), then the room
     * types, then the rooms (rh_room_nights_take_locks). Returns the locked booking row ([] when it does not exist).
     * Throws RuntimeException (rh_stay_lock_changed_message) when the booking now holds a type or room the plan did
     * not lock (it changed between the plan and the lock): nothing has been written yet, the caller rolls back.
     */
    function rh_stay_take_locks(PDO $pdo, array $plan): array
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('rh_stay_take_locks() needs an open transaction.');
        }
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
        $st->execute([(int)($plan['booking_id'] ?? 0)]);
        $b = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        rh_room_nights_take_locks($pdo, $plan);
        if (!$b) {
            return [];
        }
        // Plain reads from here on are safe: every row the check depends on is locked.
        $now = array_merge([(int)($b['individual_room_id'] ?? 0)], getBookingRoomIds((int)$b['id']));
        if (!rh_stay_plan_covers($plan, [(int)$b['room_id']], $now)) {
            throw new RuntimeException(rh_stay_lock_changed_message());
        }
        return $b;
    }

    /** Nights of the stay segment [$a, $b) (check-out day excluded). */
    function rh_stay_seg_nights(string $a, string $b): array
    {
        $a = substr($a, 0, 10);
        $b = substr($b, 0, 10);
        return $a < $b ? rh_rnc_days($a, date('Y-m-d', strtotime($b . ' -1 day'))) : [];
    }

    /**
     * What is in the way, from a checkIndividualRoomAvailability() result ($label = "Room 4") or a
     * checkRoomAvailability() result (room-type / All rooms blocks), for the nights [$a, $b). Plain text without the
     * "Not saved: " lead, e.g. "Room 4 is blocked on 3 to 4 Jan 2027 (Maintenance: roof leak); Room 4 is booked on
     * 5 Jan 2027 by Jane Banda (LSH-123)". '' when the result names nothing.
     */
    function rh_stay_clash_parts(PDO $pdo, string $label, array $c, string $a, string $b): string
    {
        $seg = array_flip(rh_stay_seg_nights($a, $b));
        $inSeg = static function (array $ns) use ($seg): array {
            return array_values(array_filter($ns, static fn($n) => isset($seg[$n])));
        };
        $parts = [];

        // Out of service for every night (room status).
        if (empty($c['blocked_dates']) && empty($c['maintenance']) && empty($c['conflicts'])
            && stripos((string)($c['error'] ?? ''), 'out of service') !== false) {
            $why = preg_match('/\(([^)]+)\)/', (string)$c['error'], $m) ? $m[1] : 'out of service';
            return $label . ' is out of service (' . $why . ')';
        }

        // Blocks: the room's own (individual_room_blocked_dates) or a room-type / All rooms block (blocked_dates).
        $groups = [];
        foreach ((array)($c['blocked_dates'] ?? []) as $r) {
            $d = substr((string)($r['block_date'] ?? ''), 0, 10);
            if (!isset($seg[$d])) {
                continue;
            }
            $who = $label . ' is';
            if (($r['block_scope'] ?? '') === 'type') {
                $who = (!empty($r['room_id']) ? rh_block_label($pdo, (int)$r['room_id']) : 'All rooms') . ' are';
            }
            $reason = trim((string)($r['reason'] ?? ''));
            $why = rh_block_type_label((string)($r['block_type'] ?? 'manual')) . ($reason !== '' ? ': ' . $reason : '');
            $groups[$who . "\0" . $why][] = $d;
        }
        foreach ($groups as $k => $ns) {
            [$who, $why] = explode("\0", $k, 2);
            $parts[] = $who . ' blocked on ' . rh_block_nights_text($ns) . ' (' . $why . ')';
        }

        // Room-closing maintenance: a job (DATE(start)..DATE(end - 1 s)) or a maintenance block (dates included).
        foreach ((array)($c['maintenance'] ?? []) as $m) {
            if (array_key_exists('title', $m)) {
                $ns = rh_maint_job_nights((string)($m['start_date'] ?? ''), (string)($m['end_date'] ?? ''));
                $what = trim((string)($m['title'] ?? ''));
            } else {
                $ns = rh_rnc_days(substr((string)($m['start_date'] ?? ''), 0, 10), substr((string)($m['end_date'] ?? ''), 0, 10));
                $what = trim((string)($m['reason'] ?? ''));
            }
            $ns = $inSeg($ns);
            if ($ns) {
                $parts[] = $label . ' is blocked on ' . rh_block_nights_text($ns) . ' (Maintenance' . ($what !== '' ? ': ' . $what : '') . ')';
            }
        }

        // Other bookings holding the room (check_out_date here = the effective check-out).
        foreach ((array)($c['conflicts'] ?? []) as $k) {
            $ci = substr((string)($k['check_in_date'] ?? ''), 0, 10);
            $co = substr((string)($k['check_out_date'] ?? ''), 0, 10);
            $ns = $inSeg(rh_stay_seg_nights($ci, $co));
            if (!$ns || !isset($k['booking_reference'])) {
                continue;
            }
            $guest = trim((string)($k['guest_name'] ?? ''));
            $parts[] = $label . ' is booked on ' . rh_block_nights_text($ns) . ' by ' . ($guest !== '' ? $guest . ' (' . (string)$k['booking_reference'] . ')' : (string)$k['booking_reference']);
        }
        return implode('; ', $parts);
    }

    /** The full refusal: "Not saved: <what is in the way>. Choose other dates or another room." */
    function rh_stay_clash_text(PDO $pdo, string $label, array $c, string $a, string $b): string
    {
        $parts = rh_stay_clash_parts($pdo, $label, $c, $a, $b);
        if ($parts === '') {
            $parts = $label . ' is not free on ' . rh_block_nights_text(rh_stay_seg_nights($a, $b))
                . ' (' . rtrim((string)($c['error'] ?? 'unavailable'), '.') . (!empty($c['conflict_message']) ? ': ' . rtrim((string)$c['conflict_message'], '.') : '') . ')';
        }
        return 'Not saved: ' . $parts . '. Choose other dates or another room.';
    }

    /**
     * The room-type refusal (checkRoomAvailability failed for the new nights [$a, $b)): blocks are named; otherwise
     * the nights with no room of the type left, e.g. "Not saved: no Deluxe room is free on 5 Jan 2027 (every room is
     * booked, blocked or held by another booking). Choose other dates or another room."
     */
    function rh_stay_type_clash_text(PDO $pdo, int $typeId, array $c, string $a, string $b): string
    {
        $parts = rh_stay_clash_parts($pdo, 'The room', ['blocked_dates' => (array)($c['blocked_dates'] ?? [])], $a, $b);
        if ($parts !== '') {
            return 'Not saved: ' . $parts . '. Choose other dates or another room.';
        }
        $err = (string)($c['error'] ?? '');
        $nm = $pdo->prepare('SELECT name FROM rooms WHERE id = ?');
        $nm->execute([$typeId]);
        $type = (string)($nm->fetchColumn() ?: 'room type');
        if (stripos($err, 'unavailable') !== false || stripos($err, 'not available') !== false) {
            $full = [];
            $nightly = getRoomTypeNightlyAvailability($typeId, $a, $b);
            foreach (rh_stay_seg_nights($a, $b) as $n) {
                if (is_array($nightly) && isset($nightly[$n]) && is_numeric($nightly[$n]) && (int)$nightly[$n] <= 0) {
                    $full[] = $n;
                }
            }
            return 'Not saved: no ' . $type . ' room is free on ' . rh_block_nights_text($full ?: rh_stay_seg_nights($a, $b))
                . ' (every room is booked, blocked or held by another booking). Choose other dates or another room.';
        }
        return 'Not saved: ' . $type . ', ' . rh_block_nights_text(rh_stay_seg_nights($a, $b)) . ': ' . rtrim($err !== '' ? $err : 'not available', '.') . '.';
    }
}
