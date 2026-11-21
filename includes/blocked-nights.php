<?php
/**
 * Nights on which a room type has NO room left to sell because of blocks (not bookings), for the public
 * booking calendar. A room-type or all-rooms block already greys a night out; this adds the nights where
 * every room of the type is off on its own: blocked one by one (Blocked Dates), switched to Maintenance /
 * Out of Order, or inside a maintenance window that blocks the room. A joined ("Book As") type sells
 * PAIRS: a pair is off when either of its rooms is off or a member room's type is blocked that night.
 *
 * Same per-night rules as checkIndividualRoomAvailability(), which stays the real check at booking time;
 * this is only the calendar hint, so any database error returns [] (nothing extra greyed out).
 *
 * @return array<int,string[]> room type id => sorted list of Y-m-d nights in [$from, $to]
 */
if (!function_exists('rh_fully_blocked_type_nights')) {
    function rh_fully_blocked_type_nights(PDO $pdo, string $from, string $to): array
    {
        $from = substr($from, 0, 10);
        $to = substr($to, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            return [];
        }
        $nights = [];
        for ($d = new DateTime($from), $end = new DateTime($to); $d <= $end && count($nights) < 1100; $d->modify('+1 day')) {
            $nights[] = $d->format('Y-m-d');
        }

        try {
            // Active rooms of active types.
            $rooms = $pdo->query("SELECT ir.id, ir.room_type_id, ir.status FROM individual_rooms ir JOIN rooms r ON r.id = ir.room_type_id AND r.is_active = 1 WHERE ir.is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
            $roomsByType = [];
            $alwaysOff = [];
            $outStatuses = function_exists('rh_room_out_of_service_statuses') ? rh_room_out_of_service_statuses() : ['maintenance', 'out_of_order'];
            foreach ($rooms as $r) {
                $roomsByType[(int)$r['room_type_id']][] = (int)$r['id'];
                if (in_array((string)$r['status'], $outStatuses, true)) {
                    $alwaysOff[(int)$r['id']] = true;
                }
            }

            // Room => night => off.
            $off = [];
            $st = $pdo->prepare("SELECT individual_room_id, block_date FROM individual_room_blocked_dates WHERE block_date >= ? AND block_date <= ?");
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$rid, $bd]) {
                $off[(int)$rid][substr((string)$bd, 0, 10)] = true;
            }
            $windows = [];
            $st = $pdo->prepare("
                SELECT individual_room_id, DATE(start_date), DATE(DATE_SUB(end_date, INTERVAL 1 SECOND))
                FROM room_maintenance_schedules
                WHERE individual_room_id IS NOT NULL AND block_room = 1
                AND COALESCE(status, '') NOT IN ('completed', 'cancelled', 'verified')
                AND DATE(start_date) <= ? AND DATE(DATE_SUB(end_date, INTERVAL 1 SECOND)) >= ?
            ");
            $st->execute([$to, $from]);
            $windows = $st->fetchAll(PDO::FETCH_NUM);
            if (!function_exists('bookingAvailabilityTableExists') || bookingAvailabilityTableExists('room_maintenance_blocks')) {
                $st = $pdo->prepare("SELECT individual_room_id, DATE(block_start_date), DATE(block_end_date) FROM room_maintenance_blocks WHERE individual_room_id IS NOT NULL AND DATE(block_start_date) <= ? AND DATE(block_end_date) >= ?");
                $st->execute([$to, $from]);
                $windows = array_merge($windows, $st->fetchAll(PDO::FETCH_NUM));
            }
            foreach ($windows as [$rid, $s, $e]) {
                foreach ($nights as $n) {
                    if ($n >= (string)$s && $n <= (string)$e) {
                        $off[(int)$rid][$n] = true;
                    }
                }
            }
            $roomOff = static function (int $rid, string $n) use ($off, $alwaysOff): bool {
                return isset($alwaysOff[$rid]) || isset($off[$rid][$n]);
            };

            // Room-type blocks (a pair is off when a member room's type is blocked).
            $typeBlocked = [];
            $st = $pdo->prepare("SELECT room_id, block_date FROM blocked_dates WHERE room_id IS NOT NULL AND block_date >= ? AND block_date <= ?");
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$tid, $bd]) {
                $typeBlocked[(int)$tid][substr((string)$bd, 0, 10)] = true;
            }

            // Joined types sell pairs (active pair, both rooms active, joined type active).
            // A type with an active pair sells pairs only (as roomTypeHasActiveCombinations decides).
            $pairsByType = [];
            $pairTypes = [];
            $hasCombos = !function_exists('bookingAvailabilityTableExists') || bookingAvailabilityTableExists('room_combinations');
            if ($hasCombos) {
                foreach ($pdo->query("SELECT DISTINCT combined_room_type_id FROM room_combinations WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $t) {
                    $pairTypes[(int)$t] = true;
                }
                $pairs = $pdo->query("
                    SELECT rc.combined_room_type_id, rc.room_a_id, rc.room_b_id, a.room_type_id, b.room_type_id
                    FROM room_combinations rc
                    JOIN individual_rooms a ON a.id = rc.room_a_id AND a.is_active = 1
                    JOIN individual_rooms b ON b.id = rc.room_b_id AND b.is_active = 1
                    JOIN rooms rt ON rt.id = rc.combined_room_type_id AND rt.is_active = 1
                    WHERE rc.is_active = 1
                ")->fetchAll(PDO::FETCH_NUM);
                foreach ($pairs as [$ct, $a, $b, $at, $bt]) {
                    $pairsByType[(int)$ct][] = [(int)$a, (int)$b, (int)$at, (int)$bt];
                }
            }
        } catch (Throwable $e) {
            error_log('rh_fully_blocked_type_nights: ' . $e->getMessage());
            return [];
        }

        $out = [];
        // Types selling pairs.
        foreach ($pairsByType as $ct => $pairs) {
            foreach ($nights as $n) {
                $free = false;
                foreach ($pairs as [$a, $b, $at, $bt]) {
                    if ($roomOff($a, $n) || $roomOff($b, $n)
                        || ($at !== $ct && isset($typeBlocked[$at][$n])) || ($bt !== $ct && isset($typeBlocked[$bt][$n]))) {
                        continue;
                    }
                    $free = true;
                    break;
                }
                if (!$free) {
                    $out[$ct][] = $n;
                }
            }
        }
        // Types selling their own rooms. A type with an active pair but no usable pair, or with no rooms at all
        // (legacy room count), is left to the booking check and not greyed here.
        foreach ($roomsByType as $tid => $ids) {
            if (isset($pairTypes[$tid])) {
                continue;
            }
            foreach ($nights as $n) {
                $free = false;
                foreach ($ids as $rid) {
                    if (!$roomOff($rid, $n)) {
                        $free = true;
                        break;
                    }
                }
                if (!$free) {
                    $out[$tid][] = $n;
                }
            }
        }
        return $out;
    }
}
