<?php
/**
 * Blocked dates: readable wording + the record of every block / unblock.
 *
 * Blocks take rooms off sale on nights that may already hold bookings, so every change made from the Blocked Dates
 * screen or the API is recorded twice:
 *   1. System Logs > Staff activity: one admin_activity_log row ('room_block' / 'room_unblock') saying who, which
 *      rooms, which nights, the block type and reason, and the bookings already sleeping on those nights.
 *   2. Booking History: a note on every booking that sleeps on a night that was just blocked (or unblocked), so the
 *      front desk sees it on the booking itself. Blocks never cancel or move a booking.
 * Logging never throws and never stops the block itself (a failed log line is written to the PHP error log).
 *
 * Requires config/database.php (getBookingsOverlappingBlockDates); loads includes/booking-timeline.php itself.
 */

if (!function_exists('rh_block_nights_text')) {

    /**
     * Nights as short readable text, back-to-back nights joined: "12 Dec 2026", "12 to 14 Dec 2026",
     * "30 Dec 2026 to 2 Jan 2027", several runs separated by commas.
     */
    function rh_block_nights_text(array $dates): string
    {
        $dates = array_values(array_unique(array_filter(array_map(static fn($d) => substr((string)$d, 0, 10), $dates))));
        sort($dates);
        $runs = [];
        foreach ($dates as $d) {
            $last = count($runs) - 1;
            if ($last >= 0 && date('Y-m-d', strtotime($runs[$last][1] . ' +1 day')) === $d) {
                $runs[$last][1] = $d;
            } else {
                $runs[] = [$d, $d];
            }
        }
        $out = [];
        foreach ($runs as [$a, $b]) {
            $ta = strtotime($a);
            $tb = strtotime($b);
            if ($a === $b) {
                $out[] = date('j M Y', $ta);
            } elseif (date('Y-m', $ta) === date('Y-m', $tb)) {
                $out[] = date('j', $ta) . ' to ' . date('j M Y', $tb);
            } elseif (date('Y', $ta) === date('Y', $tb)) {
                $out[] = date('j M', $ta) . ' to ' . date('j M Y', $tb);
            } else {
                $out[] = date('j M Y', $ta) . ' to ' . date('j M Y', $tb);
            }
        }
        return implode(', ', $out);
    }

    /** "Room 101", "Room 101 + 102", or the room's own name when it has one ("Villa 2", "Villa 2 + Villa 3"). */
    function rh_block_room_text(array $roomNumbers): string
    {
        $nums = array_values(array_map(static fn($n) => trim((string)$n), $roomNumbers));
        return (isset($nums[0][0]) && ctype_digit($nums[0][0]) ? 'Room ' : '') . implode(' + ', $nums);
    }

    /** Staff name for what a block covers: "Room 101" / "Room 101 + 102" (joined), "All Deluxe rooms", or "All rooms". */
    function rh_block_label(PDO $pdo, ?int $roomTypeId, array $roomIds = []): string
    {
        $roomIds = array_values(array_filter(array_map('intval', $roomIds)));
        try {
            if ($roomIds) {
                sort($roomIds);
                $ph = implode(',', array_fill(0, count($roomIds), '?'));
                $st = $pdo->prepare("SELECT room_number FROM individual_rooms WHERE id IN ($ph) ORDER BY id");
                $st->execute($roomIds);
                $nums = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
                return $nums ? rh_block_room_text($nums) : 'The room';
            }
            if ($roomTypeId !== null) {
                $st = $pdo->prepare('SELECT name FROM rooms WHERE id = ?');
                $st->execute([$roomTypeId]);
                $name = $st->fetchColumn();
                return $name !== false ? 'All ' . $name . ' rooms' : 'The room type';
            }
        } catch (Throwable $e) {
            error_log('rh_block_label: ' . $e->getMessage());
        }
        return 'All rooms';
    }

    /** "Room 101 on 12 to 14 Dec 2026; All rooms on 15 Dec 2026" from [label => nights], at most $max named. */
    function rh_block_where_text(array $byLabel, int $max = 8): string
    {
        $parts = [];
        foreach ($byLabel as $label => $dates) {
            if ($dates) {
                $parts[] = $label . ' on ' . rh_block_nights_text($dates);
            }
        }
        $more = count($parts) - $max;
        $parts = array_slice($parts, 0, $max);
        return implode('; ', $parts) . ($more > 0 ? '; and ' . $more . ' more' : '');
    }

    function rh_block_type_label(?string $type): string
    {
        $labels = ['manual' => 'Blocked', 'maintenance' => 'Maintenance', 'event' => 'Event', 'full' => 'Fully booked'];
        return $labels[(string)$type] ?? 'Blocked';
    }

    /**
     * What one block change covers, for the helpers below:
     * ['label' => "Room 101", 'scope' => 'room'|'type'|'all', 'type_id' => ?int, 'room_ids' => int[], 'dates' => [Y-m-d],
     *  'still' => [Y-m-d] (unblock only: nights a room-type / All rooms block still covers)].
     */
    function rh_block_entry(PDO $pdo, string $scope, ?int $typeId, array $roomIds, array $dates, ?string $label = null): array
    {
        $roomIds = array_values(array_map('intval', $roomIds));
        return [
            'label' => $label ?? rh_block_label($pdo, $scope === 'type' ? $typeId : null, $scope === 'room' ? $roomIds : []),
            'scope' => $scope,
            'type_id' => $typeId,
            'room_ids' => $roomIds,
            'dates' => array_values(array_unique(array_map(static fn($d) => substr((string)$d, 0, 10), $dates))),
            'still' => [],
        ];
    }

    /**
     * Bookings that sleep on the entries' nights (the same rule as the overlap warning: a holding status, the night
     * between check-in and check-out). @return array<int,array{id:int,ref:string,guest:string,where:array<string,string[]>}>
     */
    function rh_block_affected_bookings(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            if (empty($e['dates'])) {
                continue;
            }
            if ($e['scope'] === 'room') {
                $lists = array_map(static fn($rid) => getBookingsOverlappingBlockDates(null, (int)$rid, $e['dates']), $e['room_ids']);
            } else {
                $lists = [getBookingsOverlappingBlockDates($e['scope'] === 'type' ? (int)$e['type_id'] : null, null, $e['dates'])];
            }
            foreach ($lists as $rows) {
                foreach ($rows as $b) {
                    $id = (int)$b['id'];
                    $ci = substr((string)$b['check_in_date'], 0, 10);
                    $co = substr((string)$b['check_out_date'], 0, 10);
                    $nights = array_values(array_filter($e['dates'], static fn($n) => $n >= $ci && $n < $co));
                    if (!$nights) {
                        continue;
                    }
                    if (!isset($out[$id])) {
                        $out[$id] = ['id' => $id, 'ref' => (string)$b['booking_reference'], 'guest' => (string)($b['guest_name'] ?? ''), 'where' => []];
                    }
                    $prev = $out[$id]['where'][$e['label']] ?? [];
                    $merged = array_values(array_unique(array_merge($prev, $nights)));
                    sort($merged);
                    $out[$id]['where'][$e['label']] = $merged;
                }
            }
        }
        return $out;
    }

    /** "LSH-1001 (Jane Banda): Room 101 on 12 Dec 2026; LSH-1002 (...): ..." for messages, at most $max bookings. */
    function rh_block_bookings_text(array $affected, int $max = 5): string
    {
        $parts = [];
        foreach ($affected as $b) {
            $parts[] = $b['ref'] . ($b['guest'] !== '' ? ' (' . $b['guest'] . ')' : '') . ': ' . rh_block_where_text($b['where'], 3);
        }
        $more = count($parts) - $max;
        return implode('; ', array_slice($parts, 0, $max)) . ($more > 0 ? '; and ' . $more . ' more booking(s)' : '');
    }

    /**
     * Record one block or unblock. $op 'block' | 'unblock'. $entries: what changed (only nights really blocked /
     * unblocked). $updated (block only): entries whose existing block just got a new type / reason.
     * $meta: block_type, reason, user_id (0 for an API key), user_name, actor_type 'admin'|'api', via (e.g. "API key Booking.com").
     * Writes the Staff activity row and the Booking History notes. Returns the affected bookings.
     */
    function rh_block_log_change(PDO $pdo, string $op, array $entries, array $meta, array $updated = []): array
    {
        $entries = array_values(array_filter($entries, static fn($e) => !empty($e['dates'])));
        $updated = array_values(array_filter($updated, static fn($e) => !empty($e['dates'])));
        if (!$entries && !$updated) {
            return [];
        }
        $affected = [];
        try {
            // A re-block (new type / reason) is noted on the bookings too: their History shows the block as it now stands.
            $affected = rh_block_affected_bookings(array_merge($entries, $updated));
        } catch (Throwable $e) {
            error_log('rh_block_log_change (bookings): ' . $e->getMessage());
        }
        $byLabel = static function (array $list): array {
            $m = [];
            foreach ($list as $e) {
                $m[$e['label']] = array_merge($m[$e['label']] ?? [], $e['dates']);
            }
            return $m;
        };
        $isBlock = $op === 'block';
        $type = rh_block_type_label($meta['block_type'] ?? 'manual');
        $reason = trim((string)($meta['reason'] ?? ''));
        $userId = max(0, (int)($meta['user_id'] ?? 0));
        $userName = (string)($meta['user_name'] ?? '');
        $via = trim((string)($meta['via'] ?? ''));

        // 1. Staff activity (System Logs).
        $details = [];
        if (trim((string)($meta['note'] ?? '')) !== '') {
            $details[] = trim((string)$meta['note']); // e.g. Extend: "Extended (type and reason copied ...)"
        }
        if ($entries) {
            $details[] = ($isBlock ? 'Blocked ' : 'Unblocked ') . rh_block_where_text($byLabel($entries), 40);
        }
        if ($updated) {
            $details[] = 'Already blocked, type and reason updated: ' . rh_block_where_text($byLabel($updated), 40);
        }
        if ($isBlock && !empty($meta['kinds']) && is_array($meta['kinds']) && count($meta['kinds']) > 1) {
            $details[] = 'Types: ' . implode(' | ', array_map('strval', $meta['kinds'])); // one action copying several blocks
        } elseif ($isBlock) {
            $details[] = 'Type: ' . $type . ($reason !== '' ? '; reason: ' . $reason : '');
        }
        $still = [];
        foreach ($entries as $e) {
            if (!empty($e['still'])) {
                $still[$e['label']] = $e['still'];
            }
        }
        if ($still) {
            $details[] = 'Still blocked by the room type or All rooms: ' . rh_block_where_text($still, 20);
        }
        if ($affected) {
            $details[] = ($isBlock ? 'Bookings already on these nights (not moved): ' : 'Bookings on these nights: ') . rh_block_bookings_text($affected, 20);
        }
        if ($via !== '') {
            $details[] = 'Via ' . $via;
        }
        try {
            $st = $pdo->prepare("INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $st->execute([$userId, mb_substr($userName, 0, 100), $isBlock ? 'room_block' : 'room_unblock',
                mb_substr(implode(' | ', $details), 0, 60000), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        } catch (Throwable $e) {
            error_log('rh_block_log_change (activity): ' . $e->getMessage());
        }

        // 2. Booking History note on each booking that sleeps on those nights.
        if ($affected) {
            if (!function_exists('logBookingEvent')) {
                require_once __DIR__ . '/booking-timeline.php';
            }
            $actorType = ($meta['actor_type'] ?? 'admin') === 'api' ? 'api' : 'admin';
            foreach ($affected as $b) {
                $where = rh_block_where_text($b['where'], 10);
                if ($isBlock) {
                    $desc = 'Blocked: ' . $where . ' (' . $type . ($reason !== '' ? ': ' . $reason : '') . '). This booking already sleeps there '
                        . 'and was not moved or cancelled; reassign the room or contact the guest.';
                } else {
                    $desc = 'Block removed: ' . $where . '.';
                    $stillHere = [];
                    foreach ($b['where'] as $label => $nights) {
                        $s = array_values(array_intersect($nights, $still[$label] ?? []));
                        if ($s) {
                            $stillHere[$label] = $s;
                        }
                    }
                    if ($stillHere) {
                        $desc .= ' Still blocked by the room type or All rooms: ' . rh_block_where_text($stillHere, 10) . '.';
                    }
                }
                if ($via !== '') {
                    $desc .= ' Via ' . $via . '.';
                }
                try {
                    logBookingEvent($b['id'], $b['ref'], $isBlock ? 'Room blocked on a night of this stay' : 'Block removed from a night of this stay', 'note', $desc,
                        null, null, $actorType, $userId > 0 ? $userId : null, $userName !== '' ? $userName : null,
                        ['source' => 'blocked_dates', 'op' => $op, 'where' => $b['where']]);
                } catch (Throwable $e) {
                    error_log('rh_block_log_change (booking ' . $b['ref'] . '): ' . $e->getMessage());
                }
            }
        }
        return $affected;
    }
}
