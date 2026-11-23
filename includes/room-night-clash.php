<?php
/**
 * One booking-clash check for every save that takes a room off sale on a night (GRID_UNIFY_PLAN Phase 2).
 *
 * Owner rule 2026-11-23: a block or a room-closing maintenance job never lands on a booked night.
 *   - Blocks: booked nights are SKIPPED, the free ones saved, the bookings listed (includes/block-save.php).
 *     A room-type / All-rooms block on a night with bookings of that type blocks only the FREE rooms, one by one
 *     (owner answer 2), keeping enough free rooms for bookings that have no room yet.
 *   - Maintenance with Block room: a job over a booked night is REFUSED (rh_maint_clash_check below).
 *   - Generated jobs (auto, bulk assign, recurring) are created with block_room = 0 on booked nights.
 *
 * Rules (the same as checkIndividualRoomAvailability / getRoomTypeNightlyAvailability, config/database.php):
 *   R1 live booking = getBookingStatusesThatBlockAvailability(true), not an expired tentative; it sleeps night n when
 *      check_in_date <= n < rh_effective_checkout_sql (an overdue in-house guest holds the room through tomorrow).
 *   R2 room: n is booked when a live booking holds one of the rooms (individual_room_id or an unreleased booking_rooms row).
 *   R3 room, typed capacity: taking a free room off sale uses one unit of its type (a joined type: each free pair it
 *      breaks). A running budget per (type, night) starts at getRoomTypeNightlyAvailability and is shared by every
 *      room and target of the call (rooms in ascending id order), so one save can never oversell bookings that have
 *      no room yet. A room already off sale (own block row, a blocking job) uses nothing.
 *   R4 type T: n is booked when a live booking of type T sleeps there, or holds a room of type T, or is an unassigned
 *      booking of a joined type with an active pair that uses a room of type T.
 *   R5 all rooms: n is booked when any live booking sleeps there.
 *   R6 never checked: unblock, shortening, housekeeping.
 *
 * The SELECTs here are only valid inside the save transaction AFTER rh_room_nights_take_locks() (the same lock order
 * as booking creation: room types, then individual_rooms). Preview callers pass ['advisory' => true].
 */

require_once __DIR__ . '/block-log.php';

if (!function_exists('rh_room_nights_clash')) {

    /** Every date from $from to $to (both Y-m-d, both included). */
    function rh_rnc_days(string $from, string $to, int $cap = 4000): array
    {
        $out = [];
        for ($d = $from; $d <= $to && count($out) < $cap; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $out[] = $d;
        }
        return $out;
    }

    /**
     * The nights a maintenance job takes the room off sale: DATE(start) .. DATE(end - 1 second), the rule of
     * checkIndividualRoomAvailability (an end of exactly 00:00 means the previous day). [] for a bad range.
     */
    function rh_maint_job_nights(string $start, string $end, int $cap = 1100): array
    {
        $s = strtotime($start);
        $e = strtotime($end);
        if ($s === false || $e === false || $e <= $s) {
            return [];
        }
        return rh_rnc_days(date('Y-m-d', $s), date('Y-m-d', $e - 1), $cap);
    }

    /** "Open" maintenance job, from the stored (DB) status: the rule of the availability checks. */
    function rh_maint_status_is_open(?string $dbStatus): bool
    {
        return !in_array((string)$dbStatus, ['completed', 'cancelled', 'verified'], true);
    }

    /**
     * What to lock for these targets, worked out BEFORE beginTransaction() (plain reads).
     * Room targets lock their rooms; a room-type target its type's active rooms; All rooms every active room.
     * Type ids are widened through joined rooms (rh_related_room_type_ids), as booking creation does.
     *
     * @return array{type_ids:int[],room_ids:int[]}
     */
    function rh_room_nights_lock_plan(PDO $pdo, array $targets): array
    {
        $typeIds = [];
        $roomIds = [];
        $allTypes = false;
        foreach ($targets as $t) {
            $scope = (string)($t['scope'] ?? 'room');
            if ($scope === 'room') {
                foreach ((array)($t['room_ids'] ?? []) as $r) {
                    $roomIds[(int)$r] = (int)$r;
                }
            } elseif ($scope === 'type') {
                $tid = (int)($t['type_id'] ?? ($t['id'] ?? 0));
                if ($tid > 0) {
                    $typeIds[$tid] = $tid;
                    $st = $pdo->prepare('SELECT id FROM individual_rooms WHERE room_type_id = ? AND is_active = 1');
                    $st->execute([$tid]);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $r) {
                        $roomIds[(int)$r] = (int)$r;
                    }
                }
            } else {
                $allTypes = true;
            }
        }
        if ($allTypes) {
            foreach ($pdo->query('SELECT id FROM rooms')->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                $typeIds[(int)$tid] = (int)$tid;
            }
            foreach ($pdo->query('SELECT id FROM individual_rooms WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN) as $r) {
                $roomIds[(int)$r] = (int)$r;
            }
        }
        $roomIds = array_values(array_filter($roomIds, static fn($r) => $r > 0));
        if ($roomIds) {
            $ph = implode(',', array_fill(0, count($roomIds), '?'));
            $st = $pdo->prepare("SELECT DISTINCT room_type_id FROM individual_rooms WHERE id IN ($ph)");
            $st->execute($roomIds);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                if ((int)$tid > 0) {
                    $typeIds[(int)$tid] = (int)$tid;
                }
            }
        }
        sort($roomIds, SORT_NUMERIC);
        return ['type_ids' => rh_related_room_type_ids($pdo, array_values($typeIds)), 'room_ids' => $roomIds];
    }

    /**
     * Take the locks of a plan: the FIRST statements of the save transaction (no plain read before them).
     * Room types (ascending, rh_lock_room_types), then the rooms (ascending, FOR UPDATE).
     */
    function rh_room_nights_take_locks(PDO $pdo, array $plan): void
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('rh_room_nights_take_locks() needs an open transaction.');
        }
        rh_lock_room_types($pdo, (array)($plan['type_ids'] ?? []));
        $rooms = array_values(array_unique(array_filter(array_map('intval', (array)($plan['room_ids'] ?? [])), static fn($r) => $r > 0)));
        sort($rooms, SORT_NUMERIC);
        foreach (array_chunk($rooms, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT id FROM individual_rooms WHERE id IN ($ph) ORDER BY id FOR UPDATE");
            $st->execute($chunk);
            $st->fetchAll();
        }
    }

    /** What staff see when a booking holds the same rows at that moment (lock wait / deadlock). */
    function rh_room_nights_lock_message(): string
    {
        return 'Someone is booking these rooms right now. Nothing was changed. Try again.';
    }

    /**
     * The clash check. $targets[i] = ['scope' => 'room'|'type'|'all', 'room_ids' => int[] (room: a joined pair = both),
     * 'type_id' => ?int (type scope: the type; room scope: the room's type), 'label' => string, 'nights' => [Y-m-d]].
     * $opt: 'exclude_maint_id' (the job being edited), 'advisory' (preview: no transaction needed),
     * 'convert' (type / all targets: on a night with bookings, block the free rooms one by one - owner answer 2).
     *
     * @return array{clash:bool,
     *   targets: array<int,array{free:string[],booked:string[],singles:array<string,array<int,array{room_ids:int[],label:string,type_id:int}>>,left_open:array<string,string[]>}>,
     *   bookings: array<int,array{id:int,ref:string,guest:string,room_text:string,ci:string,co:string,status:string,no_room:bool,where:array<string,string[]>}>}
     *   free = nights the target saves at its own level; booked = nights nothing is saved for it; singles (type / all
     *   with convert) = night => the rooms saved one by one; left_open = night => unit labels left open there.
     */
    function rh_room_nights_clash(PDO $pdo, array $targets, array $opt = []): array
    {
        if (empty($opt['advisory']) && !$pdo->inTransaction()) {
            throw new RuntimeException('rh_room_nights_clash() must run inside the save transaction, after rh_room_nights_take_locks().');
        }
        if (empty($opt['advisory']) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && $GLOBALS['pdo'] !== $pdo) {
            // The availability functions it calls read the global connection: both must see the same transaction.
            throw new RuntimeException('rh_room_nights_clash(): pass the global $pdo connection.');
        }
        $excludeMaint = (int)($opt['exclude_maint_id'] ?? 0);
        $convert = !empty($opt['convert']);

        $out = ['clash' => false, 'targets' => [], 'bookings' => []];
        $all = [];
        foreach ($targets as $i => $t) {
            $nights = [];
            foreach ((array)($t['nights'] ?? ($t['dates'] ?? [])) as $d) {
                $n = rh_normalize_block_date(substr((string)$d, 0, 10));
                if ($n !== null) {
                    $nights[$n] = true;
                }
            }
            ksort($nights);
            $targets[$i]['nights_set'] = $nights;
            $targets[$i]['scope'] = in_array($t['scope'] ?? '', ['room', 'type', 'all'], true) ? $t['scope'] : 'room';
            $targets[$i]['room_ids'] = array_values(array_unique(array_map('intval', (array)($t['room_ids'] ?? []))));
            sort($targets[$i]['room_ids'], SORT_NUMERIC);
            $out['targets'][$i] = ['free' => [], 'booked' => [], 'singles' => [], 'left_open' => []];
            $all += $nights;
        }
        if (!$all) {
            return $out;
        }
        ksort($all);
        $first = array_key_first($all);
        $last = array_key_last($all);
        $lastEx = date('Y-m-d', strtotime($last . ' +1 day'));

        // Rooms, types, joined pairs.
        // A room whose status is out of service is already not counted by getRoomTypeNightlyAvailability, so taking
        // it off sale uses no unit (R3); it is still checked for bookings that hold it (R2).
        $oos = array_flip(rh_room_out_of_service_statuses());
        $rooms = [];
        foreach ($pdo->query('SELECT id, room_type_id, room_number, is_active, status FROM individual_rooms ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rooms[(int)$r['id']] = ['type' => (int)$r['room_type_id'], 'number' => (string)$r['room_number'], 'active' => (int)$r['is_active'] === 1,
                'oos' => isset($oos[(string)$r['status']])];
        }
        $typeNames = [];
        foreach ($pdo->query('SELECT id, name FROM rooms')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $typeNames[(int)$r['id']] = (string)$r['name'];
        }
        $pairs = [];          // idx => [a, b, type]
        $pairsOfRoom = [];    // room => [idx]
        $pairCounted = [];    // joined types: sold (and counted) as pairs, as getRoomTypeNightlyAvailability does
        $jointUsesType = [];  // joined type => member type => true
        if (bookingAvailabilityTableExists('room_combinations')) {
            foreach ($pdo->query('SELECT DISTINCT combined_room_type_id FROM room_combinations WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN) as $ct) {
                $pairCounted[(int)$ct] = true;
            }
            $rows = $pdo->query('SELECT rc.room_a_id, rc.room_b_id, rc.combined_room_type_id FROM room_combinations rc
                JOIN individual_rooms a ON a.id = rc.room_a_id AND a.is_active = 1
                JOIN individual_rooms b ON b.id = rc.room_b_id AND b.is_active = 1
                WHERE rc.is_active = 1 AND rc.room_a_id <> rc.room_b_id ORDER BY rc.id')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $k => $p) {
                $a = (int)$p['room_a_id'];
                $b = (int)$p['room_b_id'];
                $ct = (int)$p['combined_room_type_id'];
                $pairs[$k] = [$a, $b, $ct];
                $pairsOfRoom[$a][] = $k;
                $pairsOfRoom[$b][] = $k;
                foreach ([$a, $b] as $x) {
                    if (isset($rooms[$x])) {
                        $jointUsesType[$ct][$rooms[$x]['type']] = true;
                    }
                }
            }
        }
        $pairMap = rh_joined_only_pair_map(); // room => its joined-only pair (ONE room to staff)

        // Live bookings sleeping in the range (R1).
        $statuses = getBookingStatusesThatBlockAvailability(true);
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $effCo = rh_effective_checkout_sql('b');
        $live = "b.status IN ($ph) AND NOT (b.status = 'tentative' AND b.tentative_expires_at IS NOT NULL AND b.tentative_expires_at < NOW()) AND b.check_in_date < ? AND {$effCo} > ?";
        $params = array_merge($statuses, [$lastEx, $first]);
        $st = $pdo->prepare("SELECT b.id, b.booking_reference, b.guest_name, b.room_id, b.individual_room_id, b.check_in_date, b.check_out_date AS real_co, {$effCo} AS co, b.status FROM bookings b WHERE $live");
        $st->execute($params);
        $bk = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $id = (int)$b['id'];
            $bk[$id] = ['id' => $id, 'ref' => (string)$b['booking_reference'], 'guest' => (string)($b['guest_name'] ?? ''), 'type' => (int)$b['room_id'],
                'rooms' => [], 'ci' => substr((string)$b['check_in_date'], 0, 10), 'co' => substr((string)$b['co'], 0, 10),
                'real_co' => substr((string)$b['real_co'], 0, 10), 'status' => (string)$b['status'], 'unassigned' => $b['individual_room_id'] === null];
            if ((int)$b['individual_room_id'] > 0) {
                $bk[$id]['rooms'][(int)$b['individual_room_id']] = true;
            }
        }
        if (bookingAvailabilityTableExists('booking_rooms')) {
            $st = $pdo->prepare("SELECT br.booking_id, br.individual_room_id FROM booking_rooms br JOIN bookings b ON b.id = br.booking_id WHERE br.released_at IS NULL AND $live");
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int)$r['booking_id'];
                if (isset($bk[$id])) {
                    $bk[$id]['rooms'][(int)$r['individual_room_id']] = true;
                    $bk[$id]['unassigned'] = false; // the getRoomTypeNightlyAvailability rule: no room AND no unreleased link
                }
            }
        }
        $roomNight = [];  // room => night => [booking ids]
        $nightBk = [];    // night => [booking ids]
        foreach ($bk as $id => $b) {
            for ($d = max($b['ci'], $first); $d < $b['co'] && $d < $lastEx; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                if (!isset($all[$d])) {
                    continue;
                }
                $nightBk[$d][] = $id;
                foreach (array_keys($b['rooms']) as $r) {
                    $roomNight[$r][$d][] = $id;
                }
            }
        }

        // Off sale already: the room's own block rows, blocking maintenance (not the job being edited).
        $blockRow = [];
        $off = [];
        $st = $pdo->prepare('SELECT individual_room_id, block_date FROM individual_room_blocked_dates WHERE block_date >= ? AND block_date < ?');
        $st->execute([$first, $lastEx]);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$r, $d]) {
            $d = substr((string)$d, 0, 10);
            $blockRow[(int)$r][$d] = true;
            $off[(int)$r][$d] = true;
        }
        $st = $pdo->prepare("SELECT individual_room_id, DATE(start_date) AS s, DATE(DATE_SUB(end_date, INTERVAL 1 SECOND)) AS e FROM room_maintenance_schedules
            WHERE block_room = 1 AND COALESCE(status, '') NOT IN ('completed', 'cancelled', 'verified')
              AND DATE(start_date) < ? AND DATE(DATE_SUB(end_date, INTERVAL 1 SECOND)) >= ? AND id <> ?");
        $st->execute([$lastEx, $first, $excludeMaint]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            foreach (rh_rnc_days(max((string)$m['s'], $first), min((string)$m['e'], $last)) as $d) {
                $off[(int)$m['individual_room_id']][$d] = true;
            }
        }
        // Existing room-type / All-rooms rows (a booked night that already has one is left as it is).
        $typeRow = [];
        $st = $pdo->prepare('SELECT room_id, block_date FROM blocked_dates WHERE block_date >= ? AND block_date < ?');
        $st->execute([$first, $lastEx]);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$tid, $d]) {
            $typeRow[$tid === null ? 0 : (int)$tid][substr((string)$d, 0, 10)] = true;
        }

        $avail = [];   // type => night => units (null: legacy type, R3 skipped)
        $budget = static function (int $type) use (&$avail, $first, $lastEx): ?array {
            if (!array_key_exists($type, $avail)) {
                $avail[$type] = getRoomTypeNightlyAvailability($type, $first, $lastEx);
            }
            return $avail[$type];
        };
        $unitLabel = static function (array $ids) use ($rooms): string {
            return rh_block_room_text(array_map(static fn($r) => $rooms[$r]['number'] ?? (string)$r, $ids));
        };
        // Units a type / All-rooms target is made of: active rooms, a joined-only pair as ONE unit.
        $unitsCache = [];
        $unitsOf = static function (array $t) use (&$unitsCache, $rooms, $pairMap): array {
            $key = $t['scope'] === 'all' ? 'all' : 'type:' . (int)$t['type_id'];
            if (!isset($unitsCache[$key])) {
                $units = [];
                foreach ($rooms as $id => $r) {
                    if (!$r['active'] || ($t['scope'] === 'type' && $r['type'] !== (int)$t['type_id'])) {
                        continue;
                    }
                    $ids = isset($pairMap[$id]) ? $pairMap[$id]['room_ids'] : [$id];
                    sort($ids, SORT_NUMERIC);
                    $units[implode('+', $ids)] = $ids;
                }
                $unitsCache[$key] = array_values($units);
            }
            return $unitsCache[$key];
        };
        $touchesType = static function (array $b, int $tid) use ($rooms, $jointUsesType): bool {
            if ($b['type'] === $tid) {
                return true;
            }
            foreach (array_keys($b['rooms']) as $r) {
                if (($rooms[$r]['type'] ?? 0) === $tid) {
                    return true;
                }
            }
            return $b['unassigned'] && !empty($jointUsesType[$b['type']][$tid]);
        };
        $note = static function (int $bid, string $label, string $n) use (&$out, $bk, $rooms, $typeNames): void {
            if (!isset($bk[$bid])) {
                return;
            }
            if (!isset($out['bookings'][$bid])) {
                $b = $bk[$bid];
                $nums = [];
                foreach (array_keys($b['rooms']) as $r) {
                    $nums[] = $rooms[$r]['number'] ?? (string)$r;
                }
                $out['bookings'][$bid] = ['id' => $bid, 'ref' => $b['ref'], 'guest' => $b['guest'],
                    'room_text' => $nums ? rh_block_room_text($nums) : ($typeNames[$b['type']] ?? 'Room type ' . $b['type']),
                    'ci' => $b['ci'], 'co' => $b['real_co'] !== '' ? $b['real_co'] : $b['co'], 'status' => $b['status'], 'no_room' => !$nums, 'where' => []];
            }
            if (!in_array($n, $out['bookings'][$bid]['where'][$label] ?? [], true)) {
                $out['bookings'][$bid]['where'][$label][] = $n;
                sort($out['bookings'][$bid]['where'][$label]);
            }
        };
        // Bookings with no room yet that this type (or a joined type using its rooms) is keeping a unit for on night n.
        $typedOn = static function (int $tid, string $n) use ($bk, $nightBk, $jointUsesType): array {
            $ids = [];
            foreach ($nightBk[$n] ?? [] as $bid) {
                $b = $bk[$bid];
                if ($b['unassigned'] && ($b['type'] === $tid || !empty($jointUsesType[$b['type']][$tid]))) {
                    $ids[] = $bid;
                }
            }
            return $ids;
        };

        $left = [];      // type => night => units left after this call's earlier rooms (R3 running budget)
        foreach (array_keys($all) as $n) {
            $demands = [];
            $converting = [];
            foreach ($targets as $i => $t) {
                if (!isset($t['nights_set'][$n])) {
                    continue;
                }
                if ($t['scope'] === 'room') {
                    if ($t['room_ids']) {
                        $demands[] = ['i' => $i, 'rooms' => $t['room_ids'], 'label' => (string)$t['label'], 'kind' => 'room'];
                    }
                    continue;
                }
                $tid = (int)($t['type_id'] ?? ($t['id'] ?? 0));
                $hits = [];
                foreach ($nightBk[$n] ?? [] as $bid) {
                    if ($t['scope'] === 'all' || $touchesType($bk[$bid], $tid)) {
                        $hits[] = $bid;
                    }
                }
                if (!$hits) {
                    $out['targets'][$i]['free'][] = $n;   // R4 / R5: nothing of this row sleeps here
                    continue;
                }
                $hasRow = !empty($typeRow[$t['scope'] === 'all' ? 0 : $tid][$n]);
                $units = (!$convert || $hasRow) ? [] : $unitsOf($t);
                if (!$units) {
                    $out['targets'][$i]['booked'][] = $n;
                    foreach ($hits as $bid) {
                        $note($bid, (string)$t['label'], $n);
                    }
                    continue;
                }
                $converting[$i] = true;
                foreach ($units as $ids) {
                    $demands[] = ['i' => $i, 'rooms' => $ids, 'label' => $unitLabel($ids), 'kind' => 'single'];
                }
            }
            usort($demands, static function ($x, $y) {
                return [min($x['rooms']), $x['kind'] === 'room' ? 0 : 1, $x['i']] <=> [min($y['rooms']), $y['kind'] === 'room' ? 0 : 1, $y['i']];
            });
            $taken = [];
            foreach ($demands as $dm) {
                $write = $dm['rooms'];
                if ($dm['kind'] === 'single') {
                    // Rooms already blocked on their own row, or saved by an earlier item of this call, need nothing.
                    $write = array_values(array_filter($write, static fn($r) => empty($blockRow[$r][$n]) && empty($taken[$r])));
                    if (!$write) {
                        continue;
                    }
                }
                // R2: a live booking holds one of the rooms (a joined pair: either room).
                $holders = [];
                foreach ($dm['rooms'] as $r) {
                    foreach ($roomNight[$r][$n] ?? [] as $bid) {
                        $holders[$bid] = true;
                    }
                }
                $failLabel = $dm['kind'] === 'room' ? $dm['label'] : (string)$targets[$dm['i']]['label'];
                if ($holders) {
                    foreach (array_keys($holders) as $bid) {
                        $note($bid, $dm['label'], $n);
                    }
                    if ($dm['kind'] === 'room') {
                        $out['targets'][$dm['i']]['booked'][] = $n;
                    } else {
                        $out['targets'][$dm['i']]['left_open'][$n][] = $dm['label'];
                    }
                    continue;
                }
                // R3: units this item takes from each type, against the running budget.
                $S = array_values(array_filter($write, static fn($r) => empty($off[$r][$n]) && empty($rooms[$r]['oos'])));
                $need = [];
                foreach ($S as $r) {
                    $tp = $rooms[$r]['type'] ?? 0;
                    if ($tp > 0 && empty($pairCounted[$tp])) {
                        $need[$tp] = ($need[$tp] ?? 0) + 1;
                    }
                }
                $seen = [];
                foreach ($S as $r) {
                    foreach ($pairsOfRoom[$r] ?? [] as $pi) {
                        if (isset($seen[$pi])) {
                            continue;
                        }
                        $seen[$pi] = true;
                        [$a, $b2, $ct] = $pairs[$pi];
                        $pairFree = empty($off[$a][$n]) && empty($off[$b2][$n]) && empty($roomNight[$a][$n]) && empty($roomNight[$b2][$n])
                            && empty($rooms[$a]['oos']) && empty($rooms[$b2]['oos']);
                        if ($pairFree && !empty($pairCounted[$ct])) {
                            $need[$ct] = ($need[$ct] ?? 0) + 1;
                        }
                    }
                }
                ksort($need);
                $short = [];
                foreach ($need as $tp => $units) {
                    if (!isset($left[$tp][$n])) {
                        $av = $budget((int)$tp);
                        if ($av === null || !array_key_exists($n, $av)) {
                            continue; // legacy type counted by total_rooms: R3 does not apply
                        }
                        $left[$tp][$n] = (int)$av[$n];
                    }
                    if ($left[$tp][$n] < $units) {
                        $short[] = (int)$tp;
                    }
                }
                if ($short) {
                    foreach ($short as $tp) {
                        foreach ($typedOn($tp, $n) as $bid) {
                            $note($bid, $failLabel, $n);
                        }
                    }
                    if ($dm['kind'] === 'room') {
                        $out['targets'][$dm['i']]['booked'][] = $n;
                    } else {
                        $out['targets'][$dm['i']]['left_open'][$n][] = $dm['label'];
                    }
                    continue;
                }
                foreach ($need as $tp => $units) {
                    if (isset($left[$tp][$n])) {
                        $left[$tp][$n] -= $units;
                    }
                }
                foreach ($write as $r) {
                    $off[$r][$n] = true;
                    $taken[$r] = true;
                }
                if ($dm['kind'] === 'room') {
                    $out['targets'][$dm['i']]['free'][] = $n;
                } else {
                    $out['targets'][$dm['i']]['singles'][$n][] = ['room_ids' => $write, 'label' => $dm['label'], 'type_id' => (int)($rooms[$write[0]]['type'] ?? 0)];
                }
            }
            foreach (array_keys($converting) as $i) {
                if (empty($out['targets'][$i]['singles'][$n])) {
                    // No room of the row could be blocked that night: the whole night is skipped.
                    unset($out['targets'][$i]['left_open'][$n]);
                    $out['targets'][$i]['booked'][] = $n;
                    foreach ($nightBk[$n] ?? [] as $bid) {
                        $t = $targets[$i];
                        if ($t['scope'] === 'all' || $touchesType($bk[$bid], (int)($t['type_id'] ?? ($t['id'] ?? 0)))) {
                            $note($bid, (string)$t['label'], $n);
                        }
                    }
                }
            }
        }
        foreach ($out['targets'] as $i => $r) {
            if ($r['booked'] || $r['singles']) {
                $out['clash'] = true;
            }
        }
        return $out;
    }

    /**
     * "Jane Banda LSH-123, Room 4, stay 2 to 6 Jan 2027 (booked 3 to 5 Jan 2027)"; a booking with no room yet:
     * "Peter Phiri LSH-130, Deluxe, no room yet, stay 9 to 11 Jan 2027 (booked 9 Jan 2027)". At most $max named.
     */
    function rh_room_nights_clash_text(array $bookings, int $max = 8): string
    {
        $parts = [];
        foreach ($bookings as $b) {
            $nights = [];
            foreach ((array)($b['where'] ?? []) as $ns) {
                $nights = array_merge($nights, (array)$ns);
            }
            $ci = (string)($b['ci'] ?? '');
            $co = (string)($b['co'] ?? '');
            $stay = $ci !== '' && $co !== '' ? (date('Y', strtotime($ci)) === date('Y', strtotime($co)) ? date('j M', strtotime($ci)) : date('j M Y', strtotime($ci))) . ' to ' . date('j M Y', strtotime($co)) : '';
            $who = trim((string)($b['guest'] ?? ''));
            $parts[] = trim(($who !== '' ? $who . ' ' : '') . (string)($b['ref'] ?? '')) . ', ' . (string)($b['room_text'] ?? '')
                . (!empty($b['no_room']) ? ', no room yet' : '') . ($stay !== '' ? ', stay ' . $stay : '')
                . ($nights ? ' (booked ' . rh_block_nights_text($nights) . ')' : '');
        }
        $more = count($parts) - $max;
        return implode('; ', array_slice($parts, 0, $max)) . ($more > 0 ? '; and ' . $more . ' more booking(s)' : '');
    }

    /**
     * Maintenance with Block room: may this job close $roomId over its nights? Call INSIDE the save transaction after
     * rh_room_nights_take_locks(). $old = the stored row being edited (null for a new job): when it was an open
     * block_room job on the same room, only the NEWLY covered nights are checked (editing the assignee of an old
     * job that already overlaps a booking still saves). Owner answer 1: a clash refuses the save, nothing is saved.
     *
     * @return array{ok:bool,message:string,booked:string[],free:string[],checked:string[]}
     */
    function rh_maint_clash_check(PDO $pdo, int $roomId, string $start, string $end, ?array $old = null, bool $advisory = false): array
    {
        $nights = rh_maint_job_nights($start, $end);
        $checked = $nights;
        if ($old && (int)($old['block_room'] ?? 1) === 1 && rh_maint_status_is_open($old['status'] ?? '') && (int)($old['individual_room_id'] ?? 0) === $roomId) {
            $checked = array_values(array_diff($nights, rh_maint_job_nights((string)($old['start_date'] ?? ''), (string)($old['end_date'] ?? ''))));
        }
        if (!$checked) {
            return ['ok' => true, 'message' => '', 'booked' => [], 'free' => $nights, 'checked' => []];
        }
        $label = rh_block_label($pdo, null, [$roomId]);
        $st = $pdo->prepare('SELECT room_type_id FROM individual_rooms WHERE id = ?');
        $st->execute([$roomId]);
        $res = rh_room_nights_clash($pdo, [['scope' => 'room', 'room_ids' => [$roomId], 'type_id' => (int)$st->fetchColumn(), 'label' => $label, 'nights' => $checked]],
            ['exclude_maint_id' => (int)($old['id'] ?? 0), 'advisory' => $advisory]);
        $booked = $res['targets'][0]['booked'] ?? [];
        if (!$booked) {
            return ['ok' => true, 'message' => '', 'booked' => [], 'free' => $nights, 'checked' => $checked];
        }
        $free = array_values(array_diff($nights, $booked));
        $msg = 'Not saved: ' . $label . ' is booked on ' . rh_block_nights_text($booked) . ' (' . rh_room_nights_clash_text($res['bookings']) . ').'
            . ($free ? ' Free: ' . rh_block_nights_text($free) . '.' : ' None of the job\'s nights are free.')
            . ' Change the dates, untick Block room, or move the booking first.';
        return ['ok' => false, 'message' => $msg, 'booked' => $booked, 'free' => $free, 'checked' => $checked, 'bookings' => $res['bookings']];
    }

    /**
     * Generated jobs (auto-create, bulk assign, recurring): may the job close the room? true = no live booking or
     * typed-capacity clash on its nights. Same lock rule as rh_maint_clash_check.
     */
    function rh_maint_job_can_close_room(PDO $pdo, int $roomId, string $start, string $end): bool
    {
        return rh_maint_clash_check($pdo, $roomId, $start, $end)['ok'];
    }
}
