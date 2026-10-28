<?php

/**
 * The front desk without clicking (owner 2026-10-29, a real chat at Rosalyn's: "I need employees to almost have no need
 * for GUI clicking. Assistant should be a master.").
 *
 *  - Reads (read only, each behind the same permission and module as its screen): who made a booking and what has
 *    happened to it since (the booking timeline), the next arrivals, bookings waiting to be confirmed (pending and
 *    tentative holds), and blocked dates.
 *  - Local answers, no model and no tokens: "Who booked Mr Nthani in", "Who made the booking for ...", "booking history
 *    for ...", "When is the next arrival", "Any other day" after an arrivals answer, "tentative bookings", "blocked
 *    dates"; and cards straight from the words: "check him in", "check out Mr Banda", "confirm their booking",
 *    "assign a room to them", "room 12 is clean", "start cleaning room 12", "room 12 is fixed". A card is only ever
 *    prepared (rh_ai_propose: the person's own permissions, then Confirm replays the screen's own endpoint).
 *  - Fault of 2026-10-29 ("No booking was found for Mr Nthani" with the booking on file): a named "no booking for X" in
 *    a model reply is checked with the same close-name search the lookups use; a booking that is there replaces the
 *    claim before the reply goes out. A single-word name that is part of more than one known name (a staff member and
 *    a guest sharing a surname) is pointed out to the model, so it never reads them as two different people.
 * Nothing here writes business data: lookups go through rh_ai_run_tool, changes through rh_ai_propose (a card).
 */

require_once __DIR__ . '/ai-core.php';
require_once __DIR__ . '/ai-fuzzy.php';

// ── Read tools ──────────────────────────────────────────────────────────────

function rh_ai_desk_tools(): array
{
    $date = ['type' => 'string', 'description' => 'Date as YYYY-MM-DD', 'format' => 'date'];
    $days = ['type' => 'integer', 'minimum' => 1, 'maximum' => 366, 'description' => 'How many days ahead (default ' . rh_ai_num('ai_upcoming_days') . ')'];
    return [
        'booking_history' => [
            'description' => 'Who made one booking and how (online on the website by the guest, by a staff member in the portal, through another system) and what has happened to it since (status changes, check-in and out, emails, date changes, money entries), each with who did it and when. The answer to "who booked X". Give a booking_id or reference from this chat\'s results.',
            'schema' => ['properties' => ['booking_id' => ['type' => 'integer', 'minimum' => 1], 'reference' => ['type' => 'string', 'maxLength' => 40]]],
            'perm' => ['bookings'], 'module' => ['bookings'], 'cache' => 30, 'handler' => 'rh_ai_t_booking_history', 'step' => 'Reading the booking\'s history',
        ],
        'upcoming_arrivals' => [
            'description' => 'The next arrivals from a date (default today), soonest first: date, guest, room type, nights and status, and the date of the very next one. For "when is the next arrival" and arrivals on other days.',
            'schema' => ['properties' => ['from' => $date, 'days' => $days]],
            'perm' => ['bookings'], 'module' => ['bookings'], 'cache' => 60, 'handler' => 'rh_ai_t_upcoming_arrivals', 'step' => 'Looking for the next arrivals',
        ],
        'booking_holds' => [
            'description' => 'Bookings waiting to be confirmed: pending bookings and tentative holds, when each hold ends, the arrival date and what is still owed, the soonest to end first.',
            'schema' => ['properties' => (object)[]],
            'perm' => ['bookings'], 'module' => ['bookings'], 'cache' => 60, 'handler' => 'rh_ai_t_booking_holds', 'step' => 'Checking bookings waiting to be confirmed',
        ],
        'blocked_dates' => [
            'description' => 'Dates closed for sale (the Blocked Dates screen): a whole room type, all rooms or one room, with the type of block and the note, from a date (default today) for a number of days.',
            'schema' => ['properties' => ['from' => $date, 'days' => $days, 'room_type' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Optional room type name']]],
            'perm' => ['blocked_dates', 'calendar'], 'module' => ['bookings'], 'cache' => 60, 'handler' => 'rh_ai_t_blocked_dates', 'step' => 'Checking blocked dates',
        ],
    ];
}

/** One booking row (live) by id or reference, with its room type and room. */
function rh_ai_desk_booking_row(PDO $db, array $a): ?array
{
    if (empty($a['booking_id']) && empty($a['reference'])) {
        return null;
    }
    $st = $db->prepare("SELECT b.id, b.booking_reference, b.guest_name, b.status, b.check_in_date, b.check_out_date, b.number_of_nights, b.created_at, r.name AS room_name, ir.room_number
                        FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                        WHERE b.deleted_at IS NULL AND " . (!empty($a['booking_id']) ? 'b.id = ?' : 'b.booking_reference = ?') . " LIMIT 1");
    $st->execute([!empty($a['booking_id']) ? (int)$a['booking_id'] : (string)$a['reference']]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** "Status changed: pending -> confirmed" / "credit_note_issued" -> plain words. */
function rh_ai_desk_event_text(string $action): string
{
    $t = trim($action);
    if ($t !== '' && strpos($t, ' ') === false && strpos($t, '_') !== false) {
        $t = str_replace('_', ' ', $t);
    }
    return $t === '' ? 'Change' : mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
}

/**
 * How a booking was made, from what the system records (owner 2026-10-29: never guess). Order: the booking audit's
 * "created" row with a staff id (Create Booking writes it), the timeline's "create" entry (website, staff, another system,
 * the system), the audit note of an online booking; otherwise "not recorded" with the date it was entered. The first
 * confirmation by staff is added when the timeline has it.
 */
function rh_ai_booking_made(PDO $db, array $b): array
{
    $id = (int)$b['id'];
    $out = ['channel' => 'not recorded', 'how' => 'with no record of who made it', 'at' => (string)($b['created_at'] ?? '')];
    $au = null;
    $tl = null;
    try {
        $st = $db->prepare("SELECT performed_by, performed_by_name, note, performed_at FROM booking_audit_log WHERE booking_id = ? AND action = 'created' ORDER BY id LIMIT 1");
        $st->execute([$id]);
        $au = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $au = null;
    }
    try {
        $st = $db->prepare("SELECT performed_by_type, performed_by_id, performed_by_name, created_at FROM booking_timeline_logs WHERE booking_id = ? AND action_type = 'create' ORDER BY id LIMIT 1");
        $st->execute([$id]);
        $tl = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $tl = null;
    }
    $staffName = static function (?int $sid, string $name) use ($db): string {
        $name = trim($name);
        if ($name === '' && $sid) {
            $name = (string)(rh_ai_staff_names($db, [$sid])[$sid] ?? '');
        }
        return $name;
    };
    if ($au && (int)$au['performed_by'] > 0) {
        $out = ['channel' => 'staff', 'how' => 'by a staff member in the portal', 'at' => (string)$au['performed_at'],
            'staff_name' => $staffName((int)$au['performed_by'], (string)$au['performed_by_name'])];
    } elseif ($tl) {
        $type = (string)$tl['performed_by_type'];
        $at = (string)$tl['created_at'];
        if ($type === 'guest') {
            $out = ['channel' => 'website', 'how' => 'online on the website by the guest', 'at' => $at, 'guest_name' => trim((string)$tl['performed_by_name']) !== '' ? trim((string)$tl['performed_by_name']) : (string)$b['guest_name']];
        } elseif ($type === 'admin') {
            $out = ['channel' => 'staff', 'how' => 'by a staff member in the portal', 'at' => $at, 'staff_name' => $staffName((int)$tl['performed_by_id'], (string)$tl['performed_by_name'])];
        } elseif ($type === 'api') {
            $out = ['channel' => 'another system', 'how' => 'through another system connected to the hotel', 'at' => $at];
        } else {
            $out = ['channel' => 'system', 'how' => 'by the system itself', 'at' => $at];
        }
    } elseif ($au && stripos((string)$au['note'], 'online') !== false) {
        $out = ['channel' => 'website', 'how' => 'online on the website by the guest', 'at' => (string)$au['performed_at'], 'guest_name' => trim((string)$au['performed_by_name']) !== '' ? trim((string)$au['performed_by_name']) : (string)$b['guest_name']];
    }
    if (isset($out['staff_name']) && $out['staff_name'] === '') {
        unset($out['staff_name']);
    }
    try {
        $st = $db->prepare("SELECT performed_by_type, performed_by_id, performed_by_name, created_at FROM booking_timeline_logs WHERE booking_id = ? AND action_type IN ('status_change', 'conversion') AND new_value = 'confirmed' ORDER BY id LIMIT 1");
        $st->execute([$id]);
        if (($c = $st->fetch(PDO::FETCH_ASSOC)) && (string)$c['performed_by_type'] === 'admin') {
            $n = $staffName((int)$c['performed_by_id'], (string)$c['performed_by_name']);
            if ($n !== '') {
                $out['confirmed_by'] = ['staff_name' => $n, 'at' => (string)$c['created_at']];
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

function rh_ai_t_booking_history(PDO $db, array $user, array $a): array
{
    $b = rh_ai_desk_booking_row($db, $a);
    if (!$b) {
        return ['error' => empty($a['booking_id']) && empty($a['reference']) ? 'Give a booking id or reference.' : 'No booking found.'];
    }
    $id = (int)$b['id'];
    $n = rh_ai_num('ai_history_rows');
    $events = [];
    $total = 0;
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_id = ?");
        $st->execute([$id]);
        $total = (int)$st->fetchColumn();
        $st = $db->prepare("SELECT action, performed_by_type, performed_by_id, performed_by_name, created_at FROM booking_timeline_logs WHERE booking_id = ? ORDER BY created_at DESC, id DESC LIMIT " . $n);
        $st->execute([$id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }
    if (!$rows) { // no timeline here: the booking's audit record
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM booking_audit_log WHERE booking_id = ?");
            $st->execute([$id]);
            $total = (int)$st->fetchColumn();
            $st = $db->prepare("SELECT action, IF(performed_by IS NULL, 'guest', 'admin') AS performed_by_type, performed_by AS performed_by_id, performed_by_name, performed_at AS created_at
                                FROM booking_audit_log WHERE booking_id = ? ORDER BY performed_at DESC, id DESC LIMIT " . $n);
            $st->execute([$id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $rows = [];
        }
    }
    $names = rh_ai_staff_names($db, array_map(static fn($r) => (int)$r['performed_by_id'], array_filter($rows, static fn($r) => (string)$r['performed_by_type'] === 'admin' && trim((string)$r['performed_by_name']) === '')));
    foreach ($rows as $r) {
        $type = (string)$r['performed_by_type'];
        $e = ['at' => substr((string)$r['created_at'], 0, 16), 'what' => rh_ai_desk_event_text((string)$r['action'])];
        $name = trim((string)$r['performed_by_name']);
        if ($type === 'admin') {
            $e['by'] = 'staff';
            $name = $name !== '' ? $name : (string)($names[(int)$r['performed_by_id']] ?? '');
            if ($name !== '') {
                $e['staff_name'] = $name;
            }
        } elseif ($type === 'guest') {
            $e['by'] = 'the guest';
        } elseif ($type === 'api') {
            $e['by'] = 'another system';
        } else {
            $e['by'] = 'the system';
        }
        $events[] = $e;
    }
    return ['booking_id' => $id, 'reference' => $b['booking_reference'], 'guest_name' => $b['guest_name'], 'room_type' => $b['room_name'],
        'room_number' => $b['room_number'] !== null ? (string)$b['room_number'] : null, 'check_in' => $b['check_in_date'], 'check_out' => $b['check_out_date'],
        'nights' => (int)$b['number_of_nights'], 'status' => $b['status'], 'made' => rh_ai_booking_made($db, $b),
        'history' => $events ?: 'none recorded', 'history_total' => $total,
        'link' => 'booking-details.php?id=' . $id, 'link_label' => 'Open booking'];
}

/** A from/days window (from defaults to today; days to ai_upcoming_days). @return array{0:string,1:string,2:int} */
function rh_ai_desk_window(array $a): array
{
    $from = (string)($a['from'] ?? '') !== '' ? (string)$a['from'] : rh_ai_today();
    $days = max(1, min(366, (int)($a['days'] ?? rh_ai_num('ai_upcoming_days'))));
    return [$from, date('Y-m-d', strtotime($from . ' +' . $days . ' days')), $days];
}

/** The next arrivals: the Dashboard's arrivals (confirmed and pending, still to come) from a date, soonest first. */
function rh_ai_t_upcoming_arrivals(PDO $db, array $user, array $a): array
{
    [$from, $to] = rh_ai_desk_window($a);
    $where = "b.deleted_at IS NULL AND b.status IN ('confirmed', 'pending') AND b.check_in_date >= ? AND b.check_in_date <= ?";
    $st = $db->prepare("SELECT COUNT(*) FROM bookings b WHERE $where");
    $st->execute([$from, $to]);
    $count = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT b.id, b.booking_reference, b.guest_name, b.status, b.check_in_date, b.check_out_date, b.number_of_nights, r.name AS room_name, ir.room_number
                        FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                        WHERE $where ORDER BY b.check_in_date, b.id LIMIT " . RH_AI_TOOL_ROWS);
    $st->execute([$from, $to]);
    $list = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $list[] = ['booking_id' => (int)$r['id'], 'reference' => $r['booking_reference'], 'guest_name' => $r['guest_name'], 'room_type' => $r['room_name'],
            'room_number' => $r['room_number'] !== null ? (string)$r['room_number'] : null, 'check_in' => $r['check_in_date'], 'check_out' => $r['check_out_date'],
            'nights' => (int)$r['number_of_nights'], 'status' => $r['status']];
    }
    $st = $db->prepare("SELECT COUNT(*) FROM bookings WHERE deleted_at IS NULL AND status = 'tentative' AND check_in_date >= ? AND check_in_date <= ?");
    $st->execute([$from, $to]);
    return ['from' => $from, 'to' => $to, 'count' => $count, 'next_date' => $list[0]['check_in'] ?? null, 'list' => $list ?: 'none',
        'tentative_holds_in_window' => (int)$st->fetchColumn(), 'link' => 'bookings.php', 'link_label' => 'Open on Bookings'];
}

/** Bookings waiting to be confirmed (pending and tentative, not yet left), the soonest hold end first. */
function rh_ai_t_booking_holds(PDO $db, array $user, array $a): array
{
    $where = "b.deleted_at IS NULL AND b.status IN ('pending', 'tentative') AND b.check_out_date >= CURDATE()";
    $c = $db->query("SELECT SUM(b.status = 'pending') AS p, SUM(b.status = 'tentative') AS t,
                            SUM(b.status = 'tentative' AND b.tentative_expires_at IS NOT NULL AND b.tentative_expires_at <= DATE_ADD(NOW(), INTERVAL 1 DAY)) AS soon
                     FROM bookings b WHERE $where")->fetch(PDO::FETCH_ASSOC) ?: [];
    $rows = $db->query("SELECT b.id, b.booking_reference, b.guest_name, b.status, b.check_in_date, b.check_out_date, b.tentative_expires_at, b.expires_at, r.name AS room_name
                        FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE $where
                        ORDER BY COALESCE(b.tentative_expires_at, b.expires_at, b.check_in_date), b.id LIMIT " . rh_ai_num('ai_list_page_rows'))->fetchAll(PDO::FETCH_ASSOC);
    $list = [];
    foreach ($rows as $r) {
        $ends = (string)($r['status'] === 'tentative' ? ($r['tentative_expires_at'] ?? '') : ($r['expires_at'] ?? ''));
        $f = rh_ai_folio((int)$r['id']);
        $list[] = array_filter(['booking_id' => (int)$r['id'], 'reference' => $r['booking_reference'], 'guest_name' => $r['guest_name'], 'status' => $r['status'], 'room_type' => $r['room_name'],
            'check_in' => $r['check_in_date'], 'check_out' => $r['check_out_date'], 'hold_ends' => $ends !== '' ? substr($ends, 0, 16) : null, 'balance_due' => $f['balance_due'] ?? null], static fn($v) => $v !== null);
    }
    return ['pending' => (int)($c['p'] ?? 0), 'tentative' => (int)($c['t'] ?? 0), 'holds_ending_within_24h' => (int)($c['soon'] ?? 0), 'currency' => rh_ai_currency(),
        'list' => $list ?: 'none', 'link' => 'bookings.php', 'link_label' => 'Open on Bookings'];
}

/** Dates closed for sale, from the Blocked Dates screen's own helper (getBlockedDates). */
function rh_ai_t_blocked_dates(PDO $db, array $user, array $a): array
{
    [$from, $to] = rh_ai_desk_window($a);
    $typeId = null;
    if (!empty($a['room_type'])) {
        $rt = rh_ai_find_room_type($db, (string)$a['room_type']);
        if (!$rt) {
            return ['error' => 'No room type called "' . $a['room_type'] . '".' . rh_ai_room_type_ask($db, (string)$a['room_type'])];
        }
        $typeId = (int)$rt['id'];
    }
    $rows = function_exists('getBlockedDates') ? (array)getBlockedDates($typeId, $from, $to) : [];
    $list = [];
    foreach ($rows as $r) {
        if ((string)($r['block_scope'] ?? '') === 'individual') {
            $what = 'room ' . (string)($r['individual_room_number'] ?? '') . ' (' . (string)($r['room_name'] ?? '') . ')';
        } else {
            $what = $r['room_id'] === null || (string)$r['room_id'] === '' ? 'all room types' : (string)($r['room_name'] ?? '') . ' (whole type)';
        }
        $list[] = array_filter(['date' => (string)$r['block_date'], 'what' => $what, 'type' => (string)($r['block_type'] ?? 'manual'), 'note' => trim((string)($r['reason'] ?? '')) !== '' ? trim((string)$r['reason']) : null], static fn($v) => $v !== null);
    }
    return ['from' => $from, 'to' => $to, 'count' => count($list), 'blocked' => $list ?: 'none', 'link' => 'blocked-dates.php', 'link_label' => 'Open Blocked Dates'];
}

// ── Words -> what is asked (case kept for names) ─────────────────────────────

/** The message without polite wrappers and end punctuation; case kept (names). */
function rh_ai_desk_clean(string $m): string
{
    $m = (string)preg_replace('/\b(please|pls|kindly|can you|could you|would you|will you|go ahead and|for me)\b/iu', ' ', $m);
    $m = trim((string)preg_replace('/\s+/u', ' ', $m));
    return trim($m, " \t\n\r\0\x0B,.!?");
}

/** "him", "them", "this booking", "the same guest" ... (the booking in focus or on screen). */
function rh_ai_desk_is_pronoun(string $who): bool
{
    return (bool)preg_match('/^(him|her|them|they|their|his|it|this|that|this one|that one|the guest|this guest|that guest|the same guest|same guest|the booking|this booking|that booking|the same booking|their booking|his booking|her booking|the reservation|this reservation)$/iu', trim($who));
}

/** A person's name or a booking reference as written (a title, or a capital letter, or a reference), or null. */
function rh_ai_desk_name(string $who): ?string
{
    $who = trim((string)preg_replace('/(?:\'s|s\'|’s)$/u', '', trim($who)), " \t\"'");
    if ($who === '' || mb_strlen($who) > 60) {
        return null;
    }
    if (preg_match('/^[A-Z]{2,6}20\d{6,10}$/i', $who)) {
        return strtoupper($who); // a booking reference
    }
    if (preg_match('/\d/', $who)) {
        return null;
    }
    $titled = (bool)preg_match('/^(?:mr|mrs|ms|miss|mx|dr|prof|mister|madam|sir)\.?\s+/iu', $who);
    $name = trim((string)preg_replace('/^(?:mr|mrs|ms|miss|mx|dr|prof|mister|madam|sir)\.?\s+/iu', '', $who));
    if ($name === '' || !preg_match('/^\p{L}[\p{L}\'\-]+(?:\s\p{L}[\p{L}\'\-]+){0,3}$/u', $name)) {
        return null;
    }
    $generic = '/^(they|them|their|he|him|his|she|her|it|we|you|i|the|a|an|our|this|that|guest|guests|everyone|anyone|someone|people|group|room|rooms|staff|booking|bookings|next|first|last|today|tomorrow|tonight|time|times|date|dates|now|here|all|any|most|more|some|in|out)$/iu';
    foreach (preg_split('/\s+/u', $name) as $w) {
        if (preg_match($generic, $w)) {
            return null;
        }
    }
    return $titled || preg_match('/^\p{Lu}/u', $name) ? $name : null;
}

/**
 * "Who booked Mr Nthani in", "Who made the booking for Mr Nthani", "who booked them", "how was Banda booked",
 * "booking history for Phiri", "their history": ['kind' => made|history, 'who' => name ('' = the booking in focus)], or null.
 */
function rh_ai_desk_booking_intent(string $m): ?array
{
    $who = '(?<who>.+?)';
    $pron = '(?:it|this|that|them|him|her|this one|that one|the booking|this booking|that booking|their booking|his booking|her booking)';
    $made = [
        '/^who (?:booked|reserved) ' . $who . '(?: in| for| here)?$/iu',
        '/^who (?:made|created|did|took|entered|put in|placed|keyed in|added) (?:the |this |that |their |his |her )?(?:room )?(?:booking|reservation)(?: (?:for|of|under) ' . $who . ')?$/iu',
        '/^who (?:made|created|did|took|entered) ' . $who . '(?:\'s|s\'|’s) (?:booking|reservation)$/iu',
        '/^(?:how|where) (?:was|did) ' . $who . ' (?:get )?(?:booked|book|make (?:the|their|his|her) booking)(?: from)?$/iu',
        '/^(?:was|did) ' . $who . ' (?:booked|book) (?:online|on the website|through the website|by (?:staff|reception|phone|the front desk|us))$/iu',
    ];
    $hist = [
        '/^(?:show |what(?:\'s| is) )?(?:the )?(?:booking )?(?:history|timeline|audit(?: log| trail)?) (?:of|for|on) ' . $who . '(?:\'s booking)?$/iu',
        '/^' . $who . '(?:\'s|s\'|’s) (?:booking )?(?:history|timeline|audit(?: log| trail)?)$/iu',
        '/^(?:show |what(?:\'s| is) )?(?:the |its |their |his |her )?(?:booking )?(?:history|timeline)$/iu',
        '/^(?:who|what) (?:has )?(?:changed|happened (?:to|with|on)|edited|touched) (?:the |this |that |their |his |her )?booking(?: (?:for|of) ' . $who . ')?$/iu',
    ];
    foreach (['made' => $made, 'history' => $hist] as $kind => $res) {
        foreach ($res as $re) {
            if (!preg_match($re, $m, $g)) {
                continue;
            }
            $w = trim((string)($g['who'] ?? ''));
            if ($w === '' || preg_match('/^' . $pron . '$/iu', $w) || rh_ai_desk_is_pronoun($w)) {
                return ['kind' => $kind, 'who' => ''];
            }
            $name = rh_ai_desk_name($w);
            return $name === null ? null : ['kind' => $kind, 'who' => $name];
        }
    }
    return null;
}

/** "When is the next arrival", "upcoming arrivals", "arrivals next week": ['from','days','when'] or null. $l: lower case. */
function rh_ai_desk_upcoming_intent(string $l): ?array
{
    $l = trim($l);
    if (preg_match('/^(?:when(?:\'s| is| are)|what(?:\'s| is)|who(?:\'s| is)) (?:the |our )?next (?:arrivals?|check.?ins?|guests?(?: arriving| coming(?: in)?)?|booking)(?: due| coming)?$/u', $l)
        || preg_match('/^(?:the |our )?(?:next|upcoming|future|coming) (?:arrivals?|check.?ins?|guests? (?:arriving|coming))$/u', $l)
        || preg_match('/^who(?:\'s| is) (?:arriving|coming(?: in)?|checking in) next$/u', $l)) {
        return ['from' => rh_ai_today(), 'days' => rh_ai_num('ai_upcoming_days'), 'when' => ''];
    }
    if (preg_match('/^(?:(?:any |the )?(?:arrivals?|check.?ins?)|who(?:\'s| is) (?:arriving|coming(?: in)?|checking in)) (this|next) (week|month)$/u', $l, $g)) {
        if ($g[2] === 'week') {
            $from = $g[1] === 'this' ? rh_ai_today() : date('Y-m-d', strtotime('monday next week'));
            $to = date('Y-m-d', strtotime(($g[1] === 'this' ? 'sunday this week' : 'sunday next week')));
        } else {
            $from = $g[1] === 'this' ? rh_ai_today() : date('Y-m-d', strtotime('first day of next month'));
            $to = $g[1] === 'this' ? date('Y-m-t') : date('Y-m-d', strtotime('last day of next month'));
        }
        return ['from' => $from, 'days' => max(1, (int)((strtotime($to) - strtotime($from)) / 86400)), 'when' => $g[1] . ' ' . $g[2]];
    }
    return null;
}

/** "Any other day", "what about other days", "any later arrivals": a follow-up of an arrivals answer. $l: lower case. */
function rh_ai_desk_other_days(string $l): bool
{
    return (bool)preg_match('/^(?:and |what about |how about |are there |is there |on )?(?:any |an )?(?:other|another|later|different|next) (?:days?|dates?|arrivals?)(?: then)?$|^(?:any )?(?:arrivals? )?(?:after that|later on|on other days|on another day)$/u', trim($l));
}

/** The day an arrivals answer in the last turn was about (local or looked up by the model), or null. */
function rh_ai_desk_last_arrivals_day(PDO $db, int $conv): ?string
{
    if ($conv <= 0) {
        return null;
    }
    $st = $db->prepare("SELECT role, tool_name, tool_args_json, tool_result_json FROM ai_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 16");
    $st->execute([$conv]);
    $users = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['role'] === 'user' && ++$users > 1) {
            break; // only the last turn counts (the first user row is this question)
        }
        if ($r['role'] === 'assistant') {
            $meta = json_decode((string)$r['tool_args_json'], true);
            $loc = is_array($meta) ? (array)($meta['local'] ?? []) : [];
            if (($loc['tool'] ?? '') === 'arrivals_departures') {
                return (string)($loc['view']['date'] ?? rh_ai_today());
            }
            if (($loc['tool'] ?? '') === 'upcoming_arrivals') {
                return (string)($loc['view']['next_date'] ?? ($loc['view']['from'] ?? rh_ai_today()));
            }
        }
        if ($r['role'] === 'tool' && in_array((string)$r['tool_name'], ['arrivals_departures', 'upcoming_arrivals'], true)) {
            $args = json_decode((string)$r['tool_args_json'], true) ?: [];
            $res = json_decode((string)$r['tool_result_json'], true) ?: [];
            if ((string)$r['tool_name'] === 'upcoming_arrivals') {
                return (string)($res['next_date'] ?? ($args['from'] ?? rh_ai_today()));
            }
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($args['date'] ?? '')) ? (string)$args['date'] : rh_ai_today();
        }
    }
    return null;
}

/** "tentative bookings", "pending bookings", "which bookings need confirming", "holds expiring". $l: lower case. */
function rh_ai_desk_holds_intent(string $l): bool
{
    $l = trim($l);
    return (bool)preg_match('/^(?:which |what |any |list |show )?(?:the |our |all )?(?:pending|tentative|unconfirmed|provisional)(?: (?:and|or) (?:pending|tentative))? (?:bookings?|holds?|reservations?)(?: (?:waiting|to confirm|to be confirmed|expiring(?: soon| today)?))?$/u', $l)
        || preg_match('/^(?:which |what |any )?(?:bookings?|holds?|reservations?) (?:are |is )?(?:waiting(?: to be confirmed)?|to confirm|to be confirmed|need(?:ing)? (?:confirming|confirmation|to be confirmed)|not confirmed(?: yet)?|expiring(?: soon| today)?)$/u', $l)
        || preg_match('/^(?:any |the )?(?:tentative )?holds?(?: (?:expiring|ending))?(?: soon| today)?$/u', $l);
}

/** "blocked dates", "which dates are blocked next month", "is anything blocked": ['from','days','when'] or null. */
function rh_ai_desk_blocked_intent(string $l): ?array
{
    $l = trim($l);
    if (!preg_match('/^(?:show |list |which |what |any |are there (?:any )?)?(?:the )?(?:blocked|closed) (?:dates?|days?|nights?)(?: (this|next) (week|month))?$/u', $l, $g)
        && !preg_match('/^(?:which|what) (?:dates?|days?) (?:are|is) (?:blocked|closed)(?: (this|next) (week|month))?$/u', $l, $g)
        && !preg_match('/^(?:is|are) (?:anything|any (?:dates?|days?|rooms?)) blocked(?: (this|next) (week|month))?$/u', $l, $g)) {
        return null;
    }
    if (empty($g[1])) {
        return ['from' => rh_ai_today(), 'days' => rh_ai_num('ai_upcoming_days'), 'when' => ''];
    }
    if ($g[2] === 'week') {
        $from = $g[1] === 'this' ? rh_ai_today() : date('Y-m-d', strtotime('monday next week'));
        $to = date('Y-m-d', strtotime($g[1] === 'this' ? 'sunday this week' : 'sunday next week'));
    } else {
        $from = $g[1] === 'this' ? rh_ai_today() : date('Y-m-d', strtotime('first day of next month'));
        $to = $g[1] === 'this' ? date('Y-m-t') : date('Y-m-d', strtotime('last day of next month'));
    }
    return ['from' => $from, 'days' => max(1, (int)((strtotime($to) - strtotime($from)) / 86400)), 'when' => $g[1] . ' ' . $g[2]];
}

/**
 * A change asked in plain words that one card does: ['key', 'who' (booking cards; '' = the booking in focus), 'room',
 * 'step' (job cards)] or null. "check him in", "check in Mr Banda", "check them out", "confirm their booking", "assign a
 * room to Mrs Phiri", "put them in room 12", "room 12 is clean", "start cleaning room 12", "room 12 is fixed".
 */
function rh_ai_desk_card_intent(string $m): ?array
{
    $who = '(?<who>.+?)';
    $booking = [
        'check_in' => ['/^check ' . $who . ' in$/iu', '/^check[- ]?in ' . $who . '$/iu'],
        'check_out' => ['/^check ' . $who . ' out$/iu', '/^check[- ]?out ' . $who . '$/iu'],
        'confirm_booking' => ['/^confirm (?:the |this |that |their |his |her )?(?:booking|reservation|hold)(?: (?:for|of) ' . $who . ')?$/iu', '/^confirm ' . $who . '(?:\'s|s\'|’s) (?:booking|reservation|hold)$/iu'],
        'assign_room' => ['/^(?:assign|allocate|give) (?:a |the )?room(?: (?<room>[a-z0-9][a-z0-9\-]{0,9}))? (?:to|for) ' . $who . '$/iu',
            '/^(?:assign|allocate|put|move) ' . $who . ' (?:to|in|into) room (?<room>[a-z0-9][a-z0-9\- ]{0,14})$/iu', '/^(?:assign|allocate) (?:a |the )?room$/iu'],
        // A staff note: "add a note to Mr Banda's booking: late arrival", "add a note: wants a cot" (the booking in focus)
        'booking_note' => ['/^(?:add|put|leave|write|make) (?:a )?(?:booking )?note (?:to|on|for|in) ' . $who . '(?:\'s|s\'|’s)?(?: booking| reservation)?\s*(?::|\s[-–])\s*(?<note>\S.*)$/isu',
            '/^(?:add|put|leave|write|make) (?:a )?(?:booking )?note\s*(?::|\s[-–])\s*(?<note>\S.*)$/isu', '/^note (?:on|for) ' . $who . '(?:\'s|s\'|’s)?(?: booking)?\s*(?::|\s[-–])\s*(?<note>\S.*)$/isu'],
    ];
    foreach ($booking as $key => $res) {
        foreach ($res as $re) {
            if (!preg_match($re, $m, $g)) {
                continue;
            }
            $w = trim((string)($g['who'] ?? ''));
            $room = trim((string)($g['room'] ?? ''));
            $extra = isset($g['note']) ? ['note' => trim((string)$g['note'])] : [];
            if ($w === '' || rh_ai_desk_is_pronoun($w)) {
                return ['key' => $key, 'who' => '', 'room' => $room] + $extra;
            }
            $name = rh_ai_desk_name($w);
            return $name === null ? null : ['key' => $key, 'who' => $name, 'room' => $room] + $extra;
        }
    }
    $l = mb_strtolower($m);
    $rm = '(?:room |rm |#)(?<room>[a-z0-9][a-z0-9\-]{0,9}(?: \d{1,4})?)';
    $jobs = [
        ['housekeeping_progress', 'complete', '/^(?:mark |set )?' . $rm . ' (?:as )?(?:clean(?:ed)?|done|finished|ready)$/u'],
        ['housekeeping_progress', 'complete', '/^' . $rm . ' is (?:clean(?:ed)?|done|finished|ready)(?: now)?$/u'],
        ['housekeeping_progress', 'complete', '/^(?:i |we )?(?:finished|done|completed?) (?:cleaning|the cleaning (?:of|in)|housekeeping (?:in|for)) ' . $rm . '$/u'],
        ['housekeeping_progress', 'complete', '/^(?:mark |set )?(?:the )?(?:cleaning|housekeeping(?: job| task)?) (?:in|for|of) ' . $rm . ' (?:as )?(?:done|finished|complete(?:d)?)$/u'],
        ['housekeeping_progress', 'start', '/^(?:i(?:\'m| am) |we(?:\'re| are) )?(?:start(?:ing)?|begin(?:ning)?) (?:to clean |cleaning |the cleaning (?:of |in )|housekeeping (?:in |for ))' . $rm . '$/u'],
        ['maintenance_progress', 'complete', '/^' . $rm . ' (?:is )?(?:fixed|repaired)(?: now)?$/u'],
        ['maintenance_progress', 'complete', '/^(?:mark |set )?(?:the )?(?:repair|maintenance(?: job| task)?|fix) (?:in|on|for|of) ' . $rm . ' (?:as )?(?:done|finished|complete(?:d)?)$/u'],
        ['maintenance_progress', 'complete', '/^(?:i |we )?(?:finished|done|completed?) (?:the )?(?:repair|maintenance|fixing) (?:in |on |of )?' . $rm . '$/u'],
        ['maintenance_progress', 'start', '/^(?:start(?:ing)?|begin(?:ning)?) (?:the )?(?:repair|maintenance(?: job| task)?|fixing|work) (?:in|on|for|of) ' . $rm . '$/u'],
    ];
    foreach ($jobs as [$key, $step, $re]) {
        if (preg_match($re, $l, $g)) {
            return ['key' => $key, 'who' => '', 'room' => trim((string)$g['room']), 'step' => $step];
        }
    }
    return null;
}

// ── Local answers ───────────────────────────────────────────────────────────

/** One plain "can't" line for a lookup this person may not use here (not offered / outside their account), plus what they can do. */
function rh_ai_desk_declined(array $user, string $tool): array
{
    $off = rh_ai_not_offered_line($tool);
    $help = rh_ai_can_help_line($user);
    return ['text' => ($off !== '' ? $off : rh_ai_out_of_scope_line()) . ($help !== '' ? "\n\n" . $help : ''), 'links' => [], 'chips' => ['What can I do here?'], 'route' => 'declined'];
}

/**
 * The booking a desk question is about: '' = the booking in focus (or the one open on screen); a name or reference =
 * search_bookings (exact, else the one clearly closest name). $prefer: statuses that fit the request (a repeat guest
 * with several bookings: the one that fits). @return array ['id' => int] or ['reply' => desk answer]
 */
function rh_ai_desk_pick(PDO $db, array $user, int $conv, string $who, array $state, int $pageBooking, array $prefer = []): array
{
    if ($who === '') {
        $id = (int)($state['focus'] ?? 0) > 0 ? (int)$state['focus'] : $pageBooking;
        if ($id > 0) {
            return ['id' => $id];
        }
        return ['reply' => ['text' => 'Which guest? Give me their name as it is on the booking, the booking reference, an email or a phone number.', 'links' => [], 'chips' => []]];
    }
    if (!rh_ai_tool_allowed($user, 'search_bookings')) {
        return ['reply' => rh_ai_desk_declined($user, 'search_bookings')];
    }
    $r = rh_ai_run_tool($db, $user, $conv, 'search_bookings', ['query' => $who]);
    if (!$r['ok']) {
        return ['reply' => ['text' => 'I could not look that booking up just now. Try again, or open Bookings.', 'links' => [['label' => 'Open on Bookings', 'url' => 'bookings.php']], 'chips' => []]];
    }
    rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => 'search_bookings', 'args' => rh_ai_vault_protect($db, $conv, ['query' => $who]), 'result' => $r['json'], 'agent' => 'local']);
    $res = (array)$r['result'];
    $rows = is_array($res['bookings'] ?? null) ? $res['bookings'] : [];
    $asked = rh_ai_scrub_outbound($db, $conv, $who);
    if ($rows) {
        if (count($rows) === 1) {
            return ['id' => (int)$rows[0]['booking_id'], 'row' => $rows[0], 'read_as' => ($res['close_match'] ?? '') === 'clear'];
        }
        $fit = $prefer ? array_values(array_filter($rows, static fn($x) => in_array((string)($x['status'] ?? ''), $prefer, true))) : [];
        if (count($fit) === 1) {
            return ['id' => (int)$fit[0]['booking_id'], 'row' => $fit[0]];
        }
        return ['reply' => ['text' => count($rows) . ' bookings match "' . $asked . '" - which one? Tell me the reference.' . rh_ai_local_lines(array_map('rh_ai_booking_line', $rows)), 'links' => $r['links'], 'chips' => []]];
    }
    $close = is_array($res['close_matches'] ?? null) ? $res['close_matches'] : [];
    if ($close) {
        $opts = array_map('rh_ai_booking_option', $close);
        $last = array_pop($opts);
        return ['reply' => ['text' => 'No booking is under exactly "' . $asked . '". Do you mean ' . ($opts ? implode(', ', $opts) . ' or ' : '') . $last . '?', 'links' => $r['links'],
            'chips' => count($close) === 1 ? ['Yes, that\'s them'] : [], 'focus' => 0, 'focus_offer' => count($close) === 1 ? (int)$close[0]['booking_id'] : 0]];
    }
    $total = (int)($res['total_in_system'] ?? 0);
    return ['reply' => ['text' => 'No booking matches "' . $asked . '", and no guest name is close to it. ' . ($total > 0 ? 'There are ' . $total . ' bookings in the system - check the spelling, or give me the booking reference.' : 'There are no bookings in the system yet.'),
        'links' => $r['links'], 'chips' => [], 'focus' => 0]];
}

/** "X was booked online on the website by the guest on ..." from booking_history (de-identified: names stay tokens). */
function rh_ai_desk_made_text(array $h): string
{
    $md = is_array($h['made'] ?? null) ? $h['made'] : [];
    $who = '**' . ($h['guest_name'] ?? '') . '** (' . ($h['reference'] ?? '') . ')';
    $at = !empty($md['at']) && strtotime((string)$md['at']) ? ' on ' . date('D j M Y, H:i', strtotime((string)$md['at'])) : '';
    $by = '';
    if (!empty($md['staff_name'])) {
        $by = ': **' . $md['staff_name'] . '**';
    } elseif (!empty($md['guest_name']) && $md['guest_name'] !== ($h['guest_name'] ?? '')) {
        $by = ' (as **' . $md['guest_name'] . '**)';
    }
    $out = $who . ' was booked ' . (string)($md['how'] ?? 'with no record of who made it') . $by . $at . '.';
    if (($md['channel'] ?? '') === 'not recorded') {
        $out .= ' The system did not record who made it.';
    }
    if (!empty($md['confirmed_by']['staff_name'])) {
        $cat = (string)($md['confirmed_by']['at'] ?? '');
        $out .= ' Confirmed by **' . $md['confirmed_by']['staff_name'] . '**' . ($cat !== '' && strtotime($cat) ? ' on ' . date('D j M Y', strtotime($cat)) : '') . '.';
    }
    return $out . "\n\n" . rh_ai_booking_line($h);
}

/** The booking's history lines (newest first) from booking_history. */
function rh_ai_desk_history_text(array $h): string
{
    $ev = is_array($h['history'] ?? null) ? $h['history'] : [];
    if (!$ev) {
        return rh_ai_desk_made_text($h) . "\n\nNothing else is recorded on its history yet.";
    }
    $lines = [];
    foreach ($ev as $e) {
        if (!is_array($e)) {
            continue;
        }
        $by = (string)($e['by'] ?? '');
        $lines[] = (strtotime((string)($e['at'] ?? '')) ? date('D j M, H:i', strtotime((string)$e['at'])) : (string)($e['at'] ?? '')) . ' · ' . ($e['what'] ?? '')
            . ' · ' . ($by === 'staff' && !empty($e['staff_name']) ? $e['staff_name'] . ' (staff)' : $by);
    }
    $total = (int)($h['history_total'] ?? count($lines));
    return '**History of ' . ($h['guest_name'] ?? '') . '** (' . ($h['reference'] ?? '') . ')' . ($total > count($lines) ? ' - the latest ' . count($lines) . ' of ' . $total : '') . ', newest first:'
        . rh_ai_local_lines($lines) . "\n\n" . strtok(rh_ai_desk_made_text($h), "\n");
}

/** The next arrivals in plain words (de-identified result). */
function rh_ai_desk_upcoming_text(array $r, string $when = ''): string
{
    $list = is_array($r['list'] ?? null) ? $r['list'] : [];
    $d = static fn($x) => $x && strtotime((string)$x) ? date('D j M Y', strtotime((string)$x)) : (string)$x;
    $span = $when !== '' ? $when . ' (' . $d($r['from']) . ' to ' . $d($r['to']) . ')' : 'from ' . $d($r['from']) . ' to ' . $d($r['to']);
    $tent = (int)($r['tentative_holds_in_window'] ?? 0);
    $tentLine = $tent > 0 ? ' There ' . ($tent === 1 ? 'is 1 tentative hold' : 'are ' . $tent . ' tentative holds') . ' in that time, not confirmed yet.' : '';
    if (!$list) {
        return 'No arrivals are booked ' . $span . '.' . $tentLine;
    }
    $first = $list[0];
    $lines = [];
    foreach ($list as $x) {
        $lines[] = $d($x['check_in']) . ' · **' . $x['guest_name'] . '** (' . $x['reference'] . ')' . (!empty($x['room_type']) ? ' · ' . $x['room_type'] : '')
            . ((int)($x['nights'] ?? 0) > 0 ? ' · ' . (int)$x['nights'] . ' night' . ((int)$x['nights'] === 1 ? '' : 's') : '') . ' · ' . str_replace('-', ' ', (string)$x['status']);
    }
    $n = (int)($r['count'] ?? count($list));
    return '**Next arrival: ' . $d($first['check_in']) . '** - **' . $first['guest_name'] . '** (' . $first['reference'] . ')' . (!empty($first['room_type']) ? ', ' . $first['room_type'] : '') . '.'
        . ($n > 1 ? "\n\n" . $n . ' arrivals ' . $span . ':' . rh_ai_local_lines($lines) : '') . $tentLine;
}

function rh_ai_desk_holds_text(array $r): string
{
    $p = (int)($r['pending'] ?? 0);
    $t = (int)($r['tentative'] ?? 0);
    if ($p + $t === 0) {
        return 'No bookings are waiting to be confirmed: there are no pending bookings or tentative holds.';
    }
    $cur = (string)($r['currency'] ?? '');
    $lines = [];
    foreach (is_array($r['list'] ?? null) ? $r['list'] : [] as $x) {
        $lines[] = '**' . $x['guest_name'] . '** (' . $x['reference'] . ') · ' . $x['status'] . ' · arrives ' . date('D j M', strtotime((string)$x['check_in']))
            . (!empty($x['hold_ends']) ? ' · hold ends ' . date('D j M, H:i', strtotime((string)$x['hold_ends'])) : '')
            . (isset($x['balance_due']) && (float)$x['balance_due'] > BALANCE_TOLERANCE ? ' · owes ' . rh_ai_fmt_cur($cur, $x['balance_due']) : '');
    }
    $soon = (int)($r['holds_ending_within_24h'] ?? 0);
    return '**Waiting to be confirmed: ' . ($p + $t) . '** (' . $p . ' pending, ' . $t . ' tentative' . ($soon > 0 ? '; ' . $soon . ' hold' . ($soon === 1 ? ' ends' : 's end') . ' within 24 hours' : '') . ')'
        . rh_ai_local_lines($lines) . "\n\nSay \"confirm the booking for ...\" and I'll prepare the card.";
}

function rh_ai_desk_blocked_text(array $r, string $when = ''): string
{
    $d = static fn($x) => strtotime((string)$x) ? date('D j M Y', strtotime((string)$x)) : (string)$x;
    $span = $when !== '' ? $when . ' (' . $d($r['from']) . ' to ' . $d($r['to']) . ')' : 'from ' . $d($r['from']) . ' to ' . $d($r['to']);
    $list = is_array($r['blocked'] ?? null) ? $r['blocked'] : [];
    if (!$list) {
        return 'No dates are blocked ' . $span . '.';
    }
    $lines = [];
    foreach ($list as $x) {
        $lines[] = date('D j M', strtotime((string)$x['date'])) . ' · ' . $x['what'] . ' · ' . str_replace('_', ' ', (string)$x['type']) . (!empty($x['note']) ? ' · ' . $x['note'] : '');
    }
    return '**Blocked ' . $span . ': ' . (int)$r['count'] . '**' . rh_ai_local_lines($lines);
}

/** Run one desk read locally and keep it with the chat (the same evidence a model turn keeps). null when it fails. */
function rh_ai_desk_read(PDO $db, array $user, int $conv, string $tool, array $args): ?array
{
    rh_ai_step_set($db, (int)$user['id'], rh_ai_tool_step($tool));
    $r = rh_ai_run_tool($db, $user, $conv, $tool, $args);
    if (!$r['ok']) {
        return null;
    }
    rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => $tool, 'args' => rh_ai_vault_protect($db, $conv, $args), 'result' => $r['json'], 'agent' => 'local']);
    return $r;
}

/**
 * The desk's local answers (owner 2026-10-29), checked before the model: cards from plain words, who made a booking and
 * its history, the next arrivals and "any other day", bookings waiting to be confirmed, blocked dates. Zero tokens.
 * $message: the person's own words (typos read); $state: rh_ai_focus_state(); $pageBooking: the booking open on screen.
 * @return array|null ['text','links','chips','cards'?,'focus'?,'focus_offer'?,'local'?,'route'?]
 */
function rh_ai_desk_local(PDO $db, array $user, int $conv, string $message, array $state, int $pageBooking = 0, string $raw = ''): ?array
{
    $m = rh_ai_desk_clean($message);
    $isNote = (bool)preg_match('/^(?:add|put|leave|write|make) (?:a )?(?:booking )?note\b|^note (?:on|for)\b/iu', $m);
    if ($m === '' || mb_strlen($m) > ($isNote ? 1100 : 140)) {
        return null;
    }
    $l = mb_strtolower($m);
    // 1. A change one card does, straight from the words
    if (($ci = rh_ai_desk_card_intent($m)) !== null) {
        if ($ci['key'] === 'booking_note' && $raw !== '' && preg_match('/^[^:]*:\s*(\S.*)$/su', trim($raw), $nm)) {
            $ci['note'] = trim($nm[1]); // the note exactly as typed (no wording dropped, no spelling changed)
        }
        return rh_ai_desk_card($db, $user, $conv, $ci, $state, $pageBooking);
    }
    // 2. Who made a booking / its history
    if (($bi = rh_ai_desk_booking_intent($m)) !== null) {
        if (!rh_ai_tool_allowed($user, 'booking_history')) {
            return rh_ai_desk_declined($user, 'booking_history');
        }
        $pick = rh_ai_desk_pick($db, $user, $conv, $bi['who'], $state, $pageBooking);
        if (isset($pick['reply'])) {
            return $pick['reply'];
        }
        $r = rh_ai_desk_read($db, $user, $conv, 'booking_history', ['booking_id' => (int)$pick['id']]);
        if ($r === null) {
            return null;
        }
        $h = (array)$r['result'];
        $text = $bi['kind'] === 'history' ? rh_ai_desk_history_text($h) : rh_ai_desk_made_text($h);
        if (!empty($pick['read_as']) && $bi['who'] !== '') {
            $text = 'Taking "' . rh_ai_scrub_outbound($db, $conv, $bi['who']) . '" as **' . ($h['guest_name'] ?? '') . '** (the closest name on file).' . "\n\n" . $text;
        }
        return ['text' => $text, 'links' => $r['links'], 'chips' => $bi['kind'] === 'history' ? ['How much is their booking?'] : ['Show its history', 'How much is their booking?'], 'focus' => (int)$pick['id'], 'focus_offer' => 0];
    }
    // 3. The next arrivals, and "any other day" after an arrivals answer
    $up = rh_ai_desk_upcoming_intent($l);
    if ($up === null && rh_ai_desk_other_days($l) && ($day = rh_ai_desk_last_arrivals_day($db, $conv)) !== null) {
        $up = ['from' => date('Y-m-d', strtotime($day . ' +1 day')), 'days' => rh_ai_num('ai_upcoming_days'), 'when' => ''];
    }
    if ($up !== null) {
        if (!rh_ai_tool_allowed($user, 'upcoming_arrivals')) {
            return rh_ai_desk_declined($user, 'upcoming_arrivals');
        }
        $r = rh_ai_desk_read($db, $user, $conv, 'upcoming_arrivals', ['from' => $up['from'], 'days' => $up['days']]);
        if ($r === null) {
            return null;
        }
        $res = (array)$r['result'];
        $list = is_array($res['list'] ?? null) ? $res['list'] : [];
        return ['text' => rh_ai_desk_upcoming_text($res, $up['when']), 'links' => $r['links'], 'chips' => count($list) === 1 ? ['Who booked them?', 'Arrivals today'] : ['Arrivals today'],
            'focus' => count($list) === 1 ? (int)$list[0]['booking_id'] : null, 'local' => ['tool' => 'upcoming_arrivals', 'view' => ['from' => $up['from'], 'next_date' => $res['next_date'] ?? null]]];
    }
    // 4. Bookings waiting to be confirmed
    if (rh_ai_desk_holds_intent($l)) {
        if (!rh_ai_tool_allowed($user, 'booking_holds')) {
            return rh_ai_desk_declined($user, 'booking_holds');
        }
        $r = rh_ai_desk_read($db, $user, $conv, 'booking_holds', []);
        if ($r === null) {
            return null;
        }
        return ['text' => rh_ai_desk_holds_text((array)$r['result']), 'links' => $r['links'], 'chips' => []];
    }
    // 5. Blocked dates
    if (($bl = rh_ai_desk_blocked_intent($l)) !== null) {
        if (!rh_ai_tool_allowed($user, 'blocked_dates')) {
            return rh_ai_desk_declined($user, 'blocked_dates');
        }
        $r = rh_ai_desk_read($db, $user, $conv, 'blocked_dates', ['from' => $bl['from'], 'days' => $bl['days']]);
        if ($r === null) {
            return null;
        }
        return ['text' => rh_ai_desk_blocked_text((array)$r['result'], $bl['when']), 'links' => $r['links'], 'chips' => []];
    }
    return null;
}

/**
 * A card prepared from plain words: the booking is found (focus, screen, name or reference), then rh_ai_propose checks
 * this person's own permissions and the business type and works out the read-only preview. Nothing changes until Confirm.
 */
function rh_ai_desk_card(PDO $db, array $user, int $conv, array $ci, array $state, int $pageBooking): array
{
    $key = (string)$ci['key'];
    $def = rh_ai_action_registry()[$key] ?? null;
    if ($def === null) {
        return ['text' => 'That one needs the screen.', 'links' => [], 'chips' => []];
    }
    $mod = function_exists('getModuleForPage') ? getModuleForPage((string)$def['page']) : null;
    if ($mod !== null && !rh_ai_module_on((string)$mod)) {
        $line = rh_ai_not_offered_lines()[(string)$mod] ?? "That isn't offered here.";
        $help = rh_ai_can_help_line($user);
        return ['text' => $line . ($help !== '' ? "\n\n" . $help : ''), 'links' => [], 'chips' => ['What can I do here?'], 'route' => 'declined'];
    }
    $problem = rh_ai_action_problem($user, $key);
    if ($problem !== '') {
        $help = rh_ai_can_help_line($user);
        return ['text' => $problem . ($help !== '' ? "\n\n" . $help : ''), 'links' => [], 'chips' => ['What can I do here?'], 'route' => 'declined'];
    }
    $args = [];
    $focus = null;
    if (in_array($key, ['housekeeping_progress', 'maintenance_progress'], true)) {
        $args = ['room' => (string)$ci['room'], 'step' => (string)$ci['step']];
    } else {
        $prefer = ['check_in' => ['confirmed', 'pending'], 'check_out' => ['checked-in'], 'confirm_booking' => ['pending', 'tentative'], 'assign_room' => ['confirmed', 'pending', 'checked-in']][$key] ?? [];
        $pick = rh_ai_desk_pick($db, $user, $conv, (string)$ci['who'], $state, $pageBooking, $prefer);
        if (isset($pick['reply'])) {
            return $pick['reply'];
        }
        $args = ['booking_id' => (int)$pick['id']];
        if ((string)$ci['room'] !== '' && in_array($key, ['assign_room', 'check_in'], true)) {
            $args['room'] = (string)$ci['room'];
        }
        if ($key === 'booking_note') {
            $args['note'] = mb_substr((string)($ci['note'] ?? ''), 0, 1000);
        }
        $focus = (int)$pick['id'];
    }
    rh_ai_step_set($db, (int)$user['id'], 'Preparing a card for you to check...');
    $p = rh_ai_propose($db, $user, $conv, $key, $args, false);
    rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => (string)$def['tool'], 'args' => rh_ai_vault_protect($db, $conv, $args), 'result' => $p['json'], 'agent' => 'local']);
    if (!$p['ok']) {
        return ['text' => 'Not prepared: ' . rh_ai_scrub_outbound($db, $conv, (string)$p['error']), 'links' => [], 'chips' => [], 'focus' => $focus];
    }
    return ['text' => 'Check the card below and tap **Confirm** - nothing changes until you do.', 'links' => [], 'chips' => [], 'cards' => [(int)$p['card']['id']], 'focus' => $focus];
}

// ── "No booking for X" is checked before it goes out (owner fault 2026-10-29) ──

/**
 * Sentences of a reply that say one NAMED person or record has nothing: "No booking was found for Mr [STAFF_2]",
 * "I couldn't find a booking under X", "X has no bookings", "No gym member called X", "No event booking for X".
 * A sentence about a date or a period ("no booking for today") is a narrower answer and is left alone.
 * @return array<int,array{0:string,1:string}> sentence number => [subject, name as written (tokens kept)]
 */
function rh_ai_named_absence_claims(string $reply): array
{
    $subjects = ['bookings' => '(?:room )?(?:bookings?|reservations?|stays?)', 'gym_members' => '(?:gym )?(?:members?|memberships?)', 'conference' => '(?:conference|event) (?:bookings?|enquir(?:y|ies)|inquir(?:y|ies))'];
    $lead = '(?:(?:sorry|unfortunately|it (?:looks|seems) like|i\'m afraid|i checked(?: and)?|however),? )?';
    $prep = '(?:for|under|in the name of|named|called|matching|with the name|by the name(?: of)?)';
    $out = [];
    foreach (preg_split('/(?<=[.!?;])\s+|\n+/u', $reply) as $i => $s) {
        $s = trim((string)preg_replace('/[\s.!?;]+$/u', '', str_replace(['’', '‘', '*'], ["'", "'", ''], trim((string)$s))));
        if ($s === '') {
            continue;
        }
        foreach (['conference', 'gym_members', 'bookings'] as $k) {
            $x = '(?:' . $subjects[$k] . ')';
            $shapes = [
                '/^' . $lead . '(?:there (?:is|are|was|were) )?no ' . $x . '(?: (?:was|were|has been|have been|is|are|could be))?(?: (?:found|on file|on record|in the system|listed|booked|made))? ' . $prep . ' (?<name>.+)$/iu',
                '/^' . $lead . 'i (?:couldn\'t|could not|can\'t|cannot|didn\'t|did not|was unable to|wasn\'t able to) (?:find|see|locate) (?:a |an |any )?' . $x . ' ' . $prep . ' (?<name>.+)$/iu',
                '/^' . $lead . 'no ' . $x . ' match(?:es|ed)? (?<name>.+)$/iu',
                '/^' . $lead . '(?<name>.+?) (?:has|have) no ' . $x . '(?: (?:on file|on record|in the system|yet))?$/iu',
                '/^' . $lead . '(?<name>.+?) (?:does not|doesn\'t|did not|didn\'t) have (?:a |an |any )' . $x . '(?: (?:on file|on record|in the system|yet))?$/iu',
            ];
            if ($k === 'bookings') {
                $shapes[] = '/^' . $lead . 'there (?:is|are|was) no guest (?:named|called|by the name(?: of)?) (?<name>.+)$/iu';
            }
            foreach ($shapes as $re) {
                if (!preg_match($re, $s, $g)) {
                    continue;
                }
                $name = trim((string)preg_replace('/(?:,? (?:in the system|on file|on record|yet|at all|so far|in our records|right now))+$/iu', '', trim((string)$g['name'])), " \"'");
                if (rh_ai_named_absence_name_ok($name)) {
                    $out[$i] = [$k, $name];
                }
                continue 3;
            }
        }
    }
    return $out;
}

/** Is this a person's or record's name (a token or a capitalised word), not a date, period or pronoun? */
function rh_ai_named_absence_name_ok(string $name): bool
{
    if ($name === '' || mb_strlen($name) > 70) {
        return false;
    }
    if (preg_match('/\b(today|tomorrow|tonight|yesterday|this|next|last|week|month|year|weekend|those|these|that date|the dates?|them|him|her|it|then|period|range|between|from|until|night|nights)\b/iu', $name)
        || preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\b|\d{4}-\d{2}-\d{2}/iu', $name)) {
        return false;
    }
    $plain = (string)preg_replace('/\[(?:GUEST|STAFF|BK|EMAIL|PHONE)_\d+\]/', '', $name);
    if (preg_match('/\d/', $plain)) {
        return false;
    }
    // "We have no bookings", "The hotel has no bookings", "The guest has no booking": not a name
    if (preg_match('/^(?:we|you|they|i|he|she|it|there|the|this|that|our|your|their|his|her|its|guests?|staff|hotel|system|someone|anyone|nobody|no one|everyone)\b/iu', trim($plain))) {
        return false;
    }
    return $plain !== $name || (bool)preg_match('/(?:^|\s)\p{Lu}\p{L}+/u', $plain);
}

/**
 * The reply with each false "no booking / member / event booking for X" replaced by what IS there, found with the same
 * search the lookups use (exact, then the clearly closest name, then close names to ask about). A claim the search backs
 * (nothing close either) is left as it is. Lookups go through rh_ai_run_tool (this person's permissions); results join
 * $budget (evidence, and the booking a turn settles on).
 */
function rh_ai_named_absence_fix(PDO $db, array $user, int $conv, string $reply, array &$budget): string
{
    $claims = rh_ai_named_absence_claims($reply);
    if (!$claims) {
        return $reply;
    }
    $parts = preg_split('/(?<=[.!?;])\s+|\n+/u', $reply); // the same split as the claims
    $swap = [];
    foreach ($claims as $i => [$subject, $name]) {
        $q = trim((string)preg_replace('/^(?:mr|mrs|ms|miss|mx|dr|prof|mister|madam|sir)\.?\s+/iu', '', rh_ai_vault_rehydrate($db, $conv, $name)));
        if ($q === '') {
            continue;
        }
        $tool = ['bookings' => 'search_bookings', 'gym_members' => 'gym_watch', 'conference' => 'events_pipeline'][$subject];
        $args = ['bookings' => ['query' => $q], 'gym_members' => ['member' => $q], 'conference' => ['query' => $q]][$subject];
        if (!rh_ai_tool_allowed($user, $tool)) {
            continue; // cannot be checked for this person: their lookup said what it said
        }
        $r = rh_ai_run_tool($db, $user, $conv, $tool, $args);
        if (!$r['ok']) {
            continue;
        }
        $budget['evidence'] = (string)($budget['evidence'] ?? '') . "\n" . $r['json'];
        $budget['results'][] = [$tool, (array)$r['result'], $args];
        $res = (array)$r['result'];
        $new = '';
        if ($subject === 'bookings') {
            $rows = is_array($res['bookings'] ?? null) ? $res['bookings'] : [];
            $close = is_array($res['close_matches'] ?? null) ? $res['close_matches'] : [];
            if (count($rows) === 1) {
                $new = 'Checked again: there is a booking for ' . $name . ' - ' . rh_ai_booking_line($rows[0]) . '.';
            } elseif ($rows) {
                $new = 'Checked again: ' . count($rows) . ' bookings match ' . $name . ':' . rh_ai_local_lines(array_map('rh_ai_booking_line', array_slice($rows, 0, 3)));
            } elseif ($close) {
                $opts = array_map('rh_ai_booking_option', $close);
                $last = array_pop($opts);
                $new = 'No booking is under exactly ' . $name . ', but these are close: ' . ($opts ? implode(', ', $opts) . ' or ' : '') . $last . '.';
            }
        } else {
            $match = (string)($res['match'] ?? '');
            if ($match === 'one' || $match === 'several' || !empty($res['read_as'])) {
                $what = $subject === 'gym_members' ? 'a gym member' : 'a conference or event booking';
                $label = $subject === 'gym_members' ? (string)($res['member']['guest_name'] ?? '') : (string)($res['booking']['guest_name'] ?? ($res['booking']['company'] ?? ''));
                $new = 'Checked again: ' . ($match === 'several' ? 'several records match ' . $name : $what . ' does match ' . $name . ($label !== '' ? ' (' . $label . ')' : '')) . ' - ask me to show it.';
            }
        }
        if ($new !== '' && isset($parts[$i]) && trim((string)$parts[$i]) !== '') {
            $swap[] = [trim((string)$parts[$i]), $new];
        }
    }
    foreach ($swap as [$raw, $new]) { // replaced where it stands (the rest of the reply keeps its lines)
        $p = strpos($reply, $raw);
        if ($p !== false) {
            $reply = substr_replace($reply, $new, $p, strlen($raw));
        }
    }
    return $reply;
}

// ── One word of a name that belongs to more than one person ─────────────────

/**
 * The turn note for single-word name tokens in the question that are also part of another name in this chat, or that
 * staff and guests share (owner fault 2026-10-29: "Mr Nthani" left as a staff token while the booking's guest was
 * "Charles Nthani", so the model said there was no booking). '' when there is nothing to point out.
 */
function rh_ai_name_part_note(PDO $db, int $conv, string $tok): string
{
    if (!preg_match_all('/\[(GUEST|STAFF)_\d+\]/', $tok, $mm)) {
        return '';
    }
    $v = rh_ai_vault_load($db, $conv);
    $people = rh_ai_vault_people($db);
    $notes = [];
    foreach (array_unique($mm[0]) as $t) {
        $val = (string)($v['tok'][$t] ?? '');
        if ($val === '' || preg_match('/\s/u', trim($val))) {
            continue; // a full name is not ambiguous
        }
        $also = [];
        foreach ($v['tok'] as $ot => $ov) {
            if ($ot !== $t && preg_match('/^\[(GUEST|STAFF)_/', (string)$ot) && preg_match('/(?<![\p{L}])' . preg_quote($val, '/') . '(?![\p{L}])/iu', (string)$ov)) {
                $also[] = $ot;
            }
        }
        $shared = isset($people['shared'][mb_strtolower($val)]);
        if ($also || $shared) {
            $notes[] = $t . ' is one word of a name' . ($also ? ': it is also part of ' . implode(', ', array_slice($also, 0, 4)) : '') . ($shared ? ($also ? ', and' : ':') . ' a guest and a staff member share it' : '');
        }
    }
    return $notes ? 'NAMES: ' . implode('; ', $notes) . '. It may mean any of them: look it up (search_bookings for a guest) before saying anything about it, and never say "no booking" for it without that lookup.' : '';
}
