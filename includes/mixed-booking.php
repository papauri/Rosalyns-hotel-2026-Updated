<?php

/**
 * One party in rooms of DIFFERENT types (owner request 2026-10-28).
 *
 * The guest chose a room type that cannot seat the whole party (not enough rooms of that type are free). They add
 * rooms of other types for the overflow. The seating rule lives in ga_plan_mixed() (includes/guest-allocation.php);
 * this file adds the database side that booking.php, check-availability.php and the tests share:
 *   rh_mixed_resolve()     free rooms per type + the seating + why it does not work (read only)
 *   rh_mixed_price_plan()  every seated room priced exactly like a split row of its own type
 *   rh_mixed_options()     the other room types the guest may add next, with their nightly price
 *   rh_mixed_create()      the booking itself: lock every related type, re-check each, insert one row per room,
 *                          link them to the lead row (primary_booking_id), all in one transaction
 * No emails or redirects here; booking.php does those.
 */

require_once __DIR__ . '/guest-allocation.php';
require_once __DIR__ . '/pricing.php';

/** Most rooms of other types one booking may add (keeps a crafted POST small). */
if (!defined('RH_MIXED_MAX_EXTRA')) {
    define('RH_MIXED_MAX_EXTRA', 8);
}

if (!function_exists('rh_mixed_load_room')) {

    /** An active room type with its effective capacity (same SELECT booking.php and check-availability.php use). */
    function rh_mixed_load_room(PDO $pdo, int $typeId): ?array
    {
        if ($typeId < 1) {
            return null;
        }
        $st = $pdo->prepare("
            SELECT r.*,
                   GREATEST(
                       r.max_guests,
                       COALESCE((SELECT MAX(ir.max_guests_override) FROM individual_rooms ir WHERE ir.room_type_id = r.id AND ir.is_active = 1), 0),
                       COALESCE((SELECT MAX(rc.max_guests_combined) FROM room_combinations rc WHERE rc.combined_room_type_id = r.id AND rc.is_active = 1), 0)
                   ) AS max_guests
            FROM rooms r
            WHERE r.id = ? AND r.is_active = 1
        ");
        $st->execute([$typeId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** extra_room_types[] from a POST/GET (array or comma list) -> list of positive ints in add order. */
    function rh_mixed_parse_extras($raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            $id = (int)$v;
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return array_slice($out, 0, RH_MIXED_MAX_EXTRA + 1); // one over the limit so rh_mixed_resolve() can refuse it
    }

    /** Rooms of a type free for EVERY night of the stay (0 when blocked or unavailable). */
    function rh_mixed_free_rooms(int $typeId, string $checkIn, string $checkOut): int
    {
        $av = checkRoomAvailability($typeId, $checkIn, $checkOut, null, 0, 0);
        return !empty($av['available']) ? max(0, (int)($av['remaining_rooms'] ?? 0)) : 0;
    }

    /** A joined-room type is priced and given rooms through a combination, not a room of its own. */
    function rh_mixed_apply_combination(array $room, array $combination): array
    {
        $rate = ($combination['price_override'] !== null && $combination['price_override'] !== '')
            ? (float)$combination['price_override']
            : (float)$room['price_per_night'];
        $room['price_per_night'] = $rate;
        $room['price_single_occupancy'] = $rate;
        $room['price_double_occupancy'] = $rate;
        $room['price_triple_occupancy'] = $rate;
        $room['max_guests'] = max((int)$room['max_guests'], (int)($combination['max_guests_combined'] ?? 0));
        return $room;
    }

    /**
     * N joined-room combinations of one type that share no physical room (same rule as the single-type booking).
     * @return array {queue: array, error: string}
     */
    function rh_mixed_combination_queue(int $typeId, int $n, string $checkIn, string $checkOut): array
    {
        $available = getAvailableRoomCombinations($typeId, $checkIn, $checkOut);
        if (!$available) {
            return ['queue' => [], 'error' => 'All joined rooms for this room type are already reserved for those dates.'];
        }
        $queue = [];
        $claimed = [];
        foreach ($available as $candidate) {
            if (count($queue) >= $n) {
                break;
            }
            $ids = [(int)$candidate['room_a_id'], (int)$candidate['room_b_id']];
            if (array_intersect($ids, $claimed)) {
                continue;
            }
            $claimed = array_merge($claimed, $ids);
            $queue[] = $candidate;
        }
        if (count($queue) < $n) {
            $have = count($queue);
            return ['queue' => $queue, 'error' => "Only {$have} joined-room combination" . ($have === 1 ? '' : 's') . " available for {$checkIn} to {$checkOut}, but this booking needs {$n}."];
        }
        return ['queue' => $queue, 'error' => ''];
    }

    /**
     * Resolve the chosen type + added rooms into a seating, reading availability (nothing is written).
     *
     * @return array {ok, message, chosen, chosen_free, extras[], types[id=>row], free[id=>int], need[id=>int],
     *                plan (ga_plan_mixed result or null), nights}
     *         ok is true only when everyone is seated and every type has enough free rooms.
     */
    function rh_mixed_resolve(PDO $pdo, int $chosenId, array $extraIds, string $checkIn, string $checkOut, int $totalGuests, int $childGuests): array
    {
        $out = ['ok' => false, 'message' => '', 'chosen' => null, 'chosen_free' => 0, 'extras' => [], 'types' => [], 'free' => [], 'need' => [], 'plan' => null, 'nights' => 0];
        try {
            $nights = (int)(new DateTime($checkIn))->diff(new DateTime($checkOut))->days;
        } catch (Exception $e) {
            $out['message'] = 'Please choose valid dates.';
            return $out;
        }
        $out['nights'] = $nights;
        if ($nights < 1) {
            $out['message'] = 'Check-out must be after check-in.';
            return $out;
        }
        if (count($extraIds) > RH_MIXED_MAX_EXTRA) {
            $out['message'] = 'You can add at most ' . RH_MIXED_MAX_EXTRA . ' extra rooms to one booking.';
            return $out;
        }
        $chosen = rh_mixed_load_room($pdo, $chosenId);
        if (!$chosen) {
            $out['message'] = 'Selected room not found or inactive.';
            return $out;
        }
        $out['chosen'] = $chosen;
        $out['types'][(int)$chosen['id']] = $chosen;
        $extras = [];
        foreach ($extraIds as $id) {
            $row = $out['types'][(int)$id] ?? rh_mixed_load_room($pdo, (int)$id);
            if (!$row) {
                $out['message'] = 'A room type you added is no longer offered. Please remove it and choose another.';
                return $out;
            }
            $out['types'][(int)$id] = $row;
            $extras[] = $row;
        }
        $out['extras'] = $extras;

        // Room types that share physical rooms (a joined type and its member types) cannot be mixed in one booking:
        // one booking would then count the same rooms twice.
        $typeIds = array_keys($out['types']);
        foreach ($typeIds as $a) {
            $related = rh_related_room_type_ids($pdo, [$a]);
            foreach ($typeIds as $b) {
                if ($a !== $b && in_array($b, $related, true)) {
                    $out['message'] = $out['types'][$a]['name'] . ' and ' . $out['types'][$b]['name'] . ' share the same rooms, so they cannot be booked together. Choose a different room to add.';
                    return $out;
                }
            }
        }

        foreach ($typeIds as $t) {
            $out['free'][$t] = rh_mixed_free_rooms((int)$t, $checkIn, $checkOut);
        }
        $out['chosen_free'] = $out['free'][(int)$chosen['id']];
        if ($out['chosen_free'] < 1) {
            $out['message'] = $chosen['name'] . " is fully booked for {$checkIn} to {$checkOut}.";
            return $out;
        }

        $plan = ga_plan_mixed($chosen, $out['chosen_free'], $extras, $totalGuests, $childGuests);
        $out['plan'] = $plan;
        foreach ($plan['rows'] as $row) {
            $out['need'][$row['room_id']] = ($out['need'][$row['room_id']] ?? 0) + 1;
        }
        if (!$plan['valid']) {
            $out['message'] = (string)$plan['message'];
            return $out;
        }

        // Each type must have the rooms the seating uses, and (when children are seated there) child-friendly ones.
        foreach ($out['need'] as $t => $n) {
            $childGuestsHere = 0;
            $childRoomsHere = 0;
            foreach ($plan['rows'] as $row) {
                if ($row['room_id'] === (int)$t && $row['children'] > 0) {
                    $childGuestsHere += $row['children'];
                    $childRoomsHere++;
                }
            }
            $av = checkRoomAvailability((int)$t, $checkIn, $checkOut, null, $childGuestsHere, $childRoomsHere);
            $err = ga_inventory_error($av, (int)$n, (string)$out['types'][$t]['name'], $checkIn, $checkOut);
            if ($err !== '') {
                $out['message'] = $err;
                return $out;
            }
            if ($childRoomsHere > 0 && isset($av['child_eligible_remaining_rooms']) && (int)$av['child_eligible_remaining_rooms'] < $childRoomsHere) {
                $out['message'] = 'Not enough ' . $out['types'][$t]['name'] . ' rooms take children for your dates.';
                return $out;
            }
        }
        $out['ok'] = true;
        return $out;
    }

    /** Price one seated room exactly as an existing split row of its own type is priced (booking.php insert loop). */
    function rh_mixed_price_row(PDO $pdo, array $room, array $part, string $checkIn, string $checkOut, int $nights): array
    {
        $baseRate = ga_price_for_occupancy($room, (string)$part['occupancy_type']);
        $dynamic = applyDynamicPricing($pdo, (int)$room['id'], $checkIn, $checkOut, $nights, $baseRate);
        $rate = (float)$dynamic['final_price'];
        $childRule = rh_child_rule($room);
        $childSupplement = rh_child_supplement($childRule, (int)$part['children'], $nights, $rate);
        $stay = rh_stay_totals($rate * $nights + $childSupplement, 'price');
        return [
            'rate_per_night' => $rate,
            'base_total' => $rate * $nights,
            'child_supplement' => $childSupplement,
            'stay' => $stay,
            'dynamic' => $dynamic,
            'child_snapshot' => rh_child_rule_snapshot($childRule),
        ];
    }

    /**
     * Price every seated room of a resolved plan. Joined-room types take one combination per room, in order.
     * @return array {error, rows[] (plan row + 'priced' + 'combination'), net, levy, vat, total_with_vat}
     */
    function rh_mixed_price_plan(PDO $pdo, array $resolved, string $checkIn, string $checkOut): array
    {
        $out = ['error' => '', 'rows' => [], 'net' => 0.0, 'levy' => 0.0, 'vat' => 0.0, 'total_with_vat' => 0.0];
        $nights = (int)$resolved['nights'];
        $queues = [];
        foreach ($resolved['need'] as $typeId => $n) {
            if (roomTypeHasActiveCombinations((int)$typeId)) {
                $q = rh_mixed_combination_queue((int)$typeId, (int)$n, $checkIn, $checkOut);
                if ($q['error'] !== '') {
                    $out['error'] = $q['error'];
                    return $out;
                }
                $queues[$typeId] = $q['queue'];
            }
        }
        $used = [];
        foreach ($resolved['plan']['rows'] as $row) {
            $typeId = (int)$row['room_id'];
            $room = $resolved['types'][$typeId];
            $combination = null;
            if (isset($queues[$typeId])) {
                $k = $used[$typeId] ?? 0;
                $combination = $queues[$typeId][$k];
                $room = rh_mixed_apply_combination($room, $combination);
            }
            $used[$typeId] = ($used[$typeId] ?? 0) + 1;
            $priced = rh_mixed_price_row($pdo, $room, $row, $checkIn, $checkOut, $nights);
            $row['priced'] = $priced;
            $row['combination'] = $combination;
            $out['rows'][] = $row;
            $out['net'] += $priced['stay']['net'];
            $out['levy'] += $priced['stay']['levy'];
            $out['vat'] += $priced['stay']['vat'];
            $out['total_with_vat'] += $priced['stay']['total_with_vat'];
        }
        return $out;
    }

    /**
     * The other room types the guest may add next: free for the dates, not already used up by the rooms added so
     * far, and able to take at least one of the guests still without a room. Each carries its nightly price for the
     * guests it would take.
     * @return array list of {room_id, name, rate_per_night, guests_taken, max_guests, rooms_free, children_allowed}
     */
    function rh_mixed_options(PDO $pdo, array $resolved, string $checkIn, string $checkOut, int $totalGuests, int $childGuests): array
    {
        $chosen = $resolved['chosen'];
        if (!$chosen || !$resolved['plan'] || ($resolved['plan']['reason'] ?? '') === 'chosen_full' || count($resolved['extras']) >= RH_MIXED_MAX_EXTRA) {
            return [];
        }
        $leftBefore = (int)($resolved['plan']['left_guests'] ?? 0);
        if ($leftBefore < 1 || !empty($resolved['plan']['valid'])) {
            return [];
        }
        $nights = (int)$resolved['nights'];
        $options = [];
        $ids = $pdo->query("SELECT id FROM rooms WHERE is_active = 1 ORDER BY display_order ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
        $usedTypes = array_keys($resolved['types']);
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id === (int)$chosen['id']) {
                continue;
            }
            $cand = rh_mixed_load_room($pdo, $id);
            if (!$cand || (float)$cand['price_per_night'] <= 0) {
                continue;
            }
            // Never offer a type that shares rooms with a type already in the booking.
            $related = rh_related_room_type_ids($pdo, [$id]);
            if (array_intersect($related, array_diff($usedTypes, [$id]))) {
                continue;
            }
            $alreadyAdded = isset($resolved['need'][$id]) ? (int)$resolved['need'][$id] : count(array_filter($resolved['extras'], static fn($e) => (int)$e['id'] === $id));
            $free = rh_mixed_free_rooms($id, $checkIn, $checkOut);
            if ($free - $alreadyAdded < 1) {
                continue;
            }
            $plan = ga_plan_mixed($chosen, (int)$resolved['chosen_free'], array_merge($resolved['extras'], [$cand]), $totalGuests, $childGuests);
            if (!$plan['rows'] || !in_array($plan['reason'], ['', 'seats'], true)) {
                continue;
            }
            if ((int)$plan['left_guests'] >= $leftBefore) {
                continue; // adding it would seat nobody new (for example only children are left and it takes no children)
            }
            $last = $plan['rows'][count($plan['rows']) - 1];
            $room = $cand;
            if (roomTypeHasActiveCombinations($id)) {
                $combos = getAvailableRoomCombinations($id, $checkIn, $checkOut);
                if (!$combos) {
                    continue;
                }
                $room = rh_mixed_apply_combination($cand, $combos[0]);
            }
            $priced = rh_mixed_price_row($pdo, $room, $last, $checkIn, $checkOut, $nights);
            $options[] = [
                'room_id' => $id,
                'name' => (string)$cand['name'],
                'rate_per_night' => round($priced['rate_per_night'], 2),
                'guests_taken' => (int)$last['guests'],
                'max_guests' => (int)$room['max_guests'],
                'rooms_free' => $free - $alreadyAdded,
                'children_allowed' => !empty(ga_resolve_policy($cand)['children_allowed']),
                'takes_all' => !empty($plan['valid']),
            ];
        }
        return $options;
    }

    /** "VIP Beach Front Villa (4 guests) + Superior Suite (1 guest)" for emails and the confirmation. */
    function rh_mixed_rooms_summary(array $rows): string
    {
        $parts = [];
        foreach ($rows as $r) {
            $g = (int)$r['guests'];
            $parts[] = (string)$r['room_name'] . ' (' . $g . ' guest' . ($g === 1 ? '' : 's') . ')';
        }
        return implode(' + ', $parts);
    }

    /**
     * Create the mixed booking: one bookings row per room, each priced for its own type and the guests seated in it,
     * all linked to the first row (primary_booking_id NULL on the lead). Validation, lock, per-type re-check and
     * insert happen as one transaction. Emails, timeline and redirect stay with the caller.
     *
     * $in: chosen_id, extra_ids[], guest_name, guest_email, guest_phone, guest_country, guest_address,
     *      total_guests, child_guests, check_in, check_out, special_requests, status ('pending'|'tentative'),
     *      is_tentative (0|1), tentative_expires_at (?string), reference (base), client_uuid (?string, normalised),
     *      package_total (float, charged on the lead row), package_rows[] (written against the lead row),
     *      validation_data (the sanitised booking fields validateBookingWithAvailability() reads).
     * @return array {success, error, ids[], references[], rows[], group{total, vat, total_with_vat, child_supplement, levy}}
     */
    function rh_mixed_create(PDO $pdo, array $in): array
    {
        $fail = static fn(string $m): array => ['success' => false, 'error' => $m, 'ids' => [], 'references' => [], 'rows' => [], 'group' => []];
        $ci = (string)$in['check_in'];
        $co = (string)$in['check_out'];
        $total = (int)$in['total_guests'];
        $children = (int)$in['child_guests'];

        $resolved = rh_mixed_resolve($pdo, (int)$in['chosen_id'], $in['extra_ids'], $ci, $co, $total, $children);
        if (!$resolved['ok']) {
            return $fail($resolved['message'] !== '' ? $resolved['message'] : 'These rooms cannot seat your party.');
        }
        // Added rooms are only for the overflow: refuse a room the party does not need.
        $chosenRoom = $resolved['chosen'];
        $alone = ga_plan($chosenRoom, $total, $children);
        if (!empty($alone['valid']) && (int)$alone['rooms_needed'] <= (int)$resolved['chosen_free']) {
            return $fail($chosenRoom['name'] . ' has enough free rooms for your whole party. Please remove the rooms you added.');
        }
        if (count($resolved['extras']) > 1) {
            $fewer = ga_plan_mixed($chosenRoom, (int)$resolved['chosen_free'], array_slice($resolved['extras'], 0, -1), $total, $children);
            if (!empty($fewer['valid'])) {
                return $fail('Your party already has a room for everyone. Please remove the last room you added.');
            }
        }
        // Per type: the same validation the single-type booking runs (blocked dates, capacity of the fullest room).
        foreach ($resolved['need'] as $typeId => $n) {
            $biggest = 0;
            $childRooms = 0;
            foreach ($resolved['plan']['rows'] as $row) {
                if ($row['room_id'] === (int)$typeId) {
                    $biggest = max($biggest, $row['guests']);
                    $childRooms += $row['children'] > 0 ? 1 : 0;
                }
            }
            $payload = $in['validation_data'];
            $payload['room_id'] = (int)$typeId;
            $payload['number_of_guests'] = $biggest;
            $payload['child_rooms_needed'] = $childRooms;
            $payload['child_guests'] = 0;
            foreach ($resolved['plan']['rows'] as $row) {
                if ($row['room_id'] === (int)$typeId) {
                    $payload['child_guests'] += $row['children'];
                }
            }
            $check = validateBookingWithAvailability($payload);
            if (empty($check['valid'])) {
                $msg = $check['errors']['availability'] ?? ($check['errors']['number_of_guests'] ?? implode('; ', array_map('strval', $check['errors'] ?? [])));
                return $fail((string)$msg);
            }
        }
        $priced = rh_mixed_price_plan($pdo, $resolved, $ci, $co);
        if ($priced['error'] !== '') {
            return $fail($priced['error']);
        }
        $nights = (int)$resolved['nights'];

        $lockTypeIds = rh_related_room_type_ids($pdo, array_keys($resolved['types'])); // ascending, read BEFORE the transaction
        $pdo->beginTransaction();
        try {
            rh_lock_room_types($pdo, $lockTypeIds);

            // Re-check every type under the lock, for all the rooms of that type this booking takes.
            foreach ($resolved['need'] as $typeId => $n) {
                $childGuestsHere = 0;
                $childRoomsHere = 0;
                foreach ($resolved['plan']['rows'] as $row) {
                    if ($row['room_id'] === (int)$typeId && $row['children'] > 0) {
                        $childGuestsHere += $row['children'];
                        $childRoomsHere++;
                    }
                }
                $av = checkRoomAvailability((int)$typeId, $ci, $co, null, $childGuestsHere, $childRoomsHere);
                $err = ga_inventory_error($av, (int)$n, (string)$resolved['types'][$typeId]['name'], $ci, $co);
                if ($err !== '') {
                    throw new Exception($err . ' Please adjust your dates, guest count or rooms.');
                }
                if (roomTypeHasActiveCombinations((int)$typeId)) {
                    $queue = [];
                    foreach ($priced['rows'] as $r) {
                        if ($r['room_id'] === (int)$typeId && $r['combination']) {
                            $queue[] = $r['combination'];
                        }
                    }
                    $joinedErr = rh_joined_members_stock_error($queue, $ci, $co);
                    if ($joinedErr !== '') {
                        throw new Exception($joinedErr . ' Please adjust your dates or rooms.');
                    }
                }
            }

            $insert = $pdo->prepare("
                INSERT INTO bookings (
                    booking_reference, room_id, guest_name, guest_email, guest_phone,
                    guest_country, guest_address, number_of_guests, adult_guests, child_guests,
                    child_price_multiplier, child_charge_mode, child_charge_amount, free_children,
                    check_in_date, check_out_date, number_of_nights,
                    total_amount, amount_due, vat_rate, vat_amount, total_with_vat, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
                    special_requests, status,
                    is_tentative, tentative_expires_at, occupancy_type, client_uuid,
                    rate_plan_id, rate_plan_label, rate_plan_discount, package_total
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $uniqueRef = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE booking_reference = ?");
            $count = count($priced['rows']);
            $ids = [];
            $refs = [];
            $outRows = [];
            $group = ['total' => 0.0, 'vat' => 0.0, 'total_with_vat' => 0.0, 'child_supplement' => 0.0, 'levy' => 0.0];

            foreach ($priced['rows'] as $i => $row) {
                $p = $row['priced'];
                $pkgThis = ($i === 0) ? (float)$in['package_total'] : 0.0;
                $pkgTotals = $pkgThis > 0
                    ? rh_stay_totals($pkgThis, 'price', false)
                    : ['net' => 0.0, 'vat' => 0.0, 'levy' => 0.0, 'total_with_vat' => 0.0];
                $levy = round($p['stay']['levy'], 2);
                $vatAmt = round($p['stay']['vat'] + $pkgTotals['vat'], 2);
                $withVat = round($p['stay']['total_with_vat'] + $pkgTotals['total_with_vat'], 2);
                $totalAmount = round($p['stay']['net'] + $pkgTotals['net'] + $levy, 2);

                $ref = ($i === 0) ? (string)$in['reference'] : ((string)$in['reference'] . '-' . ($i + 1));
                if ($i > 0) {
                    while (true) {
                        $uniqueRef->execute([$ref]);
                        if ((int)$uniqueRef->fetchColumn() === 0) {
                            break;
                        }
                        $ref .= 'X';
                    }
                }
                $requests = (string)$in['special_requests'];
                if ($count > 1) {
                    $requests = trim($requests . ' | Split booking part ' . ($i + 1) . '/' . $count);
                }
                $uuid = $in['client_uuid'] ? ($i === 0 ? $in['client_uuid'] : $in['client_uuid'] . '-' . ($i + 1)) : null;

                $insert->execute([
                    $ref, $row['room_id'], $in['guest_name'], $in['guest_email'], $in['guest_phone'],
                    $in['guest_country'], $in['guest_address'], $row['guests'], $row['adults'], $row['children'],
                    $p['child_snapshot']['child_price_multiplier'], $p['child_snapshot']['child_charge_mode'],
                    $p['child_snapshot']['child_charge_amount'], $p['child_snapshot']['free_children'],
                    $ci, $co, $nights,
                    $totalAmount, $withVat, $p['stay']['vat_rate'], $vatAmt, $withVat, $p['child_supplement'], $levy, $p['stay']['levy_rate'],
                    $requests, $in['status'],
                    (int)$in['is_tentative'], $in['tentative_expires_at'], $row['occupancy_type'], $uuid,
                    $p['dynamic']['rate_plan_id'], $p['dynamic']['rate_plan_label'] ?: null, $p['dynamic']['discount_amount'] ?: null, $pkgThis,
                ]);
                $newId = (int)$pdo->lastInsertId();
                if ($row['combination']) {
                    $assignment = assignRoomCombinationToBooking($newId, (int)$row['combination']['id']);
                    if (empty($assignment['success'])) {
                        throw new Exception($assignment['message'] ?: 'Joined rooms are no longer available for those dates. Please choose another date or room type.');
                    }
                }
                $ids[] = $newId;
                $refs[] = $ref;
                $outRows[] = [
                    'id' => $newId, 'reference' => $ref, 'room_id' => $row['room_id'], 'room_name' => $row['room_name'],
                    'guests' => $row['guests'], 'adults' => $row['adults'], 'children' => $row['children'],
                    'occupancy_type' => $row['occupancy_type'], 'rate_per_night' => $p['rate_per_night'],
                    'total_amount' => $totalAmount, 'total_with_vat' => $withVat, 'vat' => $vatAmt, 'levy' => $levy,
                    'child_supplement' => $p['child_supplement'],
                ];
                $group['total'] += $totalAmount;
                $group['vat'] += $vatAmt;
                $group['total_with_vat'] += $withVat;
                $group['child_supplement'] += $p['child_supplement'];
                $group['levy'] += $levy;
            }

            // Every later room is linked to the lead row, exactly as a split party of one type is.
            $link = $pdo->prepare("UPDATE bookings SET primary_booking_id = ? WHERE id = ?");
            foreach (array_slice($ids, 1) as $secondaryId) {
                $link->execute([$ids[0], $secondaryId]);
            }
            if (!empty($in['package_rows'])) {
                $pkgStmt = $pdo->prepare("INSERT INTO booking_packages
                    (booking_id, package_id, package_name, price_type, price_amount, quantity, total_cost)
                    VALUES (?, ?, ?, ?, ?, 1, ?)");
                foreach ($in['package_rows'] as $pk) {
                    $pkgStmt->execute([$ids[0], $pk['package_id'], $pk['package_name'], $pk['price_type'], $pk['price_amount'], $pk['total_cost']]);
                }
            }
            $pdo->commit();
            return ['success' => true, 'error' => '', 'ids' => $ids, 'references' => $refs, 'rows' => $outRows, 'group' => $group, 'resolved' => $resolved];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (rh_is_lock_conflict($e)) {
                return $fail(rh_lock_conflict_message());
            }
            return $fail($e->getMessage());
        }
    }

    /**
     * After rh_mixed_create() succeeded: timeline log, guest + admin emails, the confirmation session, redirect.
     * Mirrors the tail of the single-type flow in booking.php (that flow stays untouched).
     *
     * $c: created (the rh_mixed_create result), lead_room (rooms row of the chosen type), guest_name, guest_email,
     *     guest_phone, check_in, check_out, nights, total_guests, adult_guests, child_guests, special_requests,
     *     status, is_tentative, is_tentative_booking, tentative_expires_at, reference
     */
    function rh_mixed_finish(array $c): void
    {
        $created = $c['created'];
        $rows = $created['rows'];
        $summary = rh_mixed_rooms_summary($rows);
        $nights = (int)$c['nights'];
        $group = $created['group'];

        foreach ($rows as $r) {
            logBookingCreated([
                'id' => $r['id'], 'booking_reference' => $r['reference'], 'room_id' => $r['room_id'],
                'guest_name' => $c['guest_name'], 'guest_email' => $c['guest_email'],
                'check_in_date' => $c['check_in'], 'check_out_date' => $c['check_out'], 'number_of_nights' => $nights,
                'number_of_guests' => $r['guests'], 'total_amount' => $r['total_amount'],
                'status' => $c['status'], 'is_tentative' => $c['is_tentative'],
            ], 'guest', null, $c['guest_name']);
            logBookingCreatedAudit($r['id'], $r['reference'], 'guest', $c['guest_name']);
        }

        $lead = $rows[0];
        $childRule = rh_child_rule($c['lead_room']);
        $childSnapshot = rh_child_rule_snapshot($childRule);
        $bookingData = [
            'id' => $lead['id'],
            'booking_reference' => $lead['reference'],
            'room_id' => $lead['room_id'],
            'room_name' => $summary,
            'rooms_summary' => $summary,
            'guest_name' => $c['guest_name'],
            'guest_email' => $c['guest_email'],
            'guest_phone' => $c['guest_phone'],
            'check_in_date' => $c['check_in'],
            'check_out_date' => $c['check_out'],
            'number_of_nights' => $nights,
            'number_of_guests' => $c['total_guests'],
            'adult_guests' => $c['adult_guests'],
            'child_guests' => $c['child_guests'],
            'child_price_multiplier' => $childRule['percent'],
            'child_charge_mode' => $childSnapshot['child_charge_mode'],
            'child_charge_amount' => $childSnapshot['child_charge_amount'],
            'free_children' => $childSnapshot['free_children'],
            'rooms_in_group' => max(1, count(array_filter($rows, static fn($p) => (int)$p['children'] > 0))),
            'child_supplement_total' => $group['child_supplement'],
            'tourism_levy_amount' => $group['levy'],
            'tourism_levy_percent' => (float)getSetting('tourism_levy_percent', 0),
            'total_amount' => $group['total'],
            'vat_amount' => round($group['vat'], 2),
            'total_with_vat' => round($group['total_with_vat'], 2),
            'amount_due' => round($group['total_with_vat'], 2),
            'special_requests' => $c['special_requests'],
            'status' => $c['status'],
            'is_tentative' => $c['is_tentative'],
            'tentative_expires_at' => $c['tentative_expires_at'],
            'occupancy_type' => $lead['occupancy_type'],
            'room_price' => round($group['total'] / max(1, $nights), 2),
            'room_price_per_room' => $lead['rate_per_night'],
            'rooms_needed' => count($rows),
            'split_count' => count($rows),
            'all_references' => $created['references'],
        ];
        $emailResult = !empty($c['is_tentative_booking'])
            ? sendTentativeBookingConfirmedEmail($bookingData)
            : sendBookingReceivedEmail($bookingData);
        if (empty($emailResult['success'])) {
            error_log('Failed to send mixed-room booking email: ' . ($emailResult['message'] ?? ''));
        }
        $adminResult = sendAdminNotificationEmail($bookingData);
        if (empty($adminResult['success'])) {
            error_log('Failed to send admin notification: ' . ($adminResult['message'] ?? ''));
        }

        unset($_SESSION['booking_form_uuid']);
        $_SESSION['booking_success'] = [
            'reference' => $lead['reference'],
            'guest_name' => $c['guest_name'],
            'room_name' => $summary,
            'check_in' => $c['check_in'],
            'check_out' => $c['check_out'],
            'nights' => $nights,
            'total' => round($group['total_with_vat'], 2),
            'vat_amount' => round($group['vat'], 2),
            'levy_amount' => round($group['levy'], 2),
            'email_sent' => !empty($emailResult['success']),
            'is_tentative' => $c['is_tentative'],
            'tentative_expires_at' => $c['tentative_expires_at'],
            'split_count' => count($rows),
            'all_references' => $created['references'],
        ];
        pub_confirm_remember('booking', (string)$lead['reference']);
        header('Location: booking-confirmation.php?ref=' . urlencode((string)$lead['reference']));
        exit;
    }
}
