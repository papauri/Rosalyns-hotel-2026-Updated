<?php

/**
 * Guided cards: one short question at a time, read from the database, no model call (owner 2026-10-29: "For things like
 * making a booking that need a sequence of answers ... be more helpful by asking in a sequence, without using credit,
 * since we are reading from the DB. Do this for other things too").
 *
 *  - Which fields are asked, and in which order, come from the card itself (rh_ai_action_registry): the schema's
 *    required fields plus the card's 'ask' list (the optional fields its screen's form also takes), in the schema's
 *    property order. Nothing here lists a card's fields again; this file holds only the wording of each question and
 *    how to read an answer of each kind (dates, party, room type, booking, room, member, ...).
 *  - What was already said is taken first ("book a deluxe for John Banda 5-7 Nov, 2 adults" fills those), answers may
 *    hold several fields in any order, and each answer is checked against the database as it comes in (free rooms,
 *    party size, children, dates, blocked dates, the booking's status ...). A failed check says why in one line and
 *    offers real alternatives as tap answers.
 *  - "back", "skip", "change the dates", "start again" and "cancel" work. The state is kept with the chat (privacy
 *    tokens only, like the rest of the chat), survives a page change and lapses after ai_guided_minutes.
 *  - The end is the card's normal preview: nothing is saved until the person taps Confirm (password where the card is
 *    risky). Permissions and the business type are checked before the first question and again at Confirm.
 *  - A real question in the middle ("what time is breakfast?") is answered as usual and the step is asked again.
 *    A request for a different change in the middle is not started (a typo never jumps to another action): the person
 *    finishes or cancels first. Money is never taken inside a flow.
 */

require_once __DIR__ . '/ai-core.php';
require_once __DIR__ . '/ai-fuzzy.php';

/** Cards that can be walked step by step: every card except drafts (written by the model), screen hand-offs and free edits. */
function rh_ai_guided_keys(): array
{
    return array_values(array_diff(array_keys(rh_ai_action_registry()), ['reply_review', 'reply_enquiry', 'whatsapp', 'edit_booking']));
}

/** The kind of answer a field takes (how it is asked and read). */
function rh_ai_guided_kind(string $name, array $p): string
{
    $byName = ['check_in' => 'dates', 'check_out' => 'dates', 'adults' => 'party', 'children' => 'party', 'room_type' => 'room_type', 'new_room_type' => 'room_type',
        'booking_id' => 'booking', 'room' => 'room', 'member' => 'member', 'what' => 'target', 'from' => 'span', 'to' => 'span',
        'guest_email' => 'email', 'guest_phone' => 'phone', 'guest_name' => 'name', 'hours' => 'number', 'valid_days' => 'number'];
    if (isset($byName[$name])) {
        return $byName[$name];
    }
    if (isset($p['enum'])) {
        return 'choice';
    }
    $t = (string)($p['type'] ?? 'string');
    if (($p['format'] ?? '') === 'date') {
        return 'date';
    }
    return $t === 'boolean' ? 'yesno' : ($t === 'integer' ? 'number' : 'text');
}

/**
 * The steps of one card, in order: step id => ['fields' => [...], 'kind', 'need' => needed|optional]. Grouped: the two
 * stay dates, the party (adults + children), the from/to of a block, the booking (id or reference).
 */
function rh_ai_guided_steps(string $key): array
{
    $def = rh_ai_action_registry()[$key] ?? null;
    if ($def === null) {
        return [];
    }
    $props = (array)($def['schema']['properties'] ?? []);
    $req = (array)($def['schema']['required'] ?? []);
    $ask = (array)($def['ask'] ?? []);
    $groups = ['check_out' => 'check_in', 'children' => 'adults', 'to' => 'from', 'reference' => 'booking_id'];
    $steps = [];
    foreach ($props as $name => $p) {
        $name = (string)$name;
        $isSubject = $name === 'booking_id' || $name === 'reference';
        $need = $isSubject || in_array($name, $req, true) ? 'needed' : (isset($ask[$name]) ? (string)$ask[$name] : null);
        $sid = $groups[$name] ?? $name;
        if ($need === null && !isset($steps[$sid])) {
            continue;
        }
        if ($name === 'reference' || $name === 'job_id') {
            continue;
        }
        if (!isset($steps[$sid])) {
            $steps[$sid] = ['fields' => [], 'kind' => rh_ai_guided_kind($name, (array)$p), 'need' => $need ?? 'optional'];
        }
        $steps[$sid]['fields'][] = $name;
        if ($need === 'needed') {
            $steps[$sid]['need'] = 'needed';
        }
    }
    return $steps;
}

/** Is a step answered? (the stay needs both dates; the party its adults; a block its first date) */
function rh_ai_guided_filled(array $g, string $sid, array $step): bool
{
    $a = (array)$g['args'];
    switch ($step['kind']) {
        case 'dates':
            return !empty($a['check_in']) && !empty($a['check_out']);
        case 'party':
            return !empty($a['adults']);
        case 'span':
            return !empty($a['from']);
        default:
            return isset($a[$sid]) && $a[$sid] !== '';
    }
}

// ── State (kept with the chat, privacy tokens only) ──────────────────────────

/** Real values -> tokens before the state is stored with the chat (names, emails, phones, references, free text). */
function rh_ai_guided_pack(PDO $db, int $conv, array $g): array
{
    $kinds = ['guest_name' => 'GUEST', 'room_guest' => 'GUEST', 'guest_email' => 'EMAIL', 'guest_phone' => 'PHONE', 'who' => 'GUEST', 'member' => 'GUEST'];
    foreach ((array)$g['args'] as $k => $v) {
        if (is_string($v) && $v !== '') {
            $g['args'][$k] = isset($kinds[$k]) ? rh_ai_vault_token($db, $conv, $kinds[$k], $v) : rh_ai_scrub_outbound($db, $conv, $v);
        }
    }
    if (!empty($g['offer']['value']) && is_string($g['offer']['value'])) {
        $g['offer']['value'] = rh_ai_scrub_outbound($db, $conv, $g['offer']['value']);
    }
    return $g;
}

/** The active step-by-step card of this chat (real values back), null when there is none; ['expired' => label] when it lapsed. */
function rh_ai_guided_load(PDO $db, int $conv): ?array
{
    if ($conv <= 0) {
        return null;
    }
    try {
        $st = $db->prepare("SELECT tool_args_json FROM ai_messages WHERE conversation_id = ? AND role = 'assistant' ORDER BY id DESC LIMIT 40");
        $st->execute([$conv]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $j) {
            $m = json_decode((string)$j, true);
            if (!is_array($m) || !isset($m['guided'])) {
                continue;
            }
            $g = (array)$m['guided'];
            if (!empty($g['done']) || empty($g['key']) || !isset(rh_ai_action_registry()[(string)$g['key']])) {
                return null;
            }
            if (time() - (int)($g['at'] ?? 0) > rh_ai_num('ai_guided_minutes') * 60) {
                return ['expired' => (string)rh_ai_action_registry()[(string)$g['key']]['label'], 'key' => (string)$g['key']];
            }
            $g['args'] = rh_ai_vault_resolve_args($db, $conv, (array)($g['args'] ?? []));
            if (!empty($g['offer']['value']) && is_string($g['offer']['value'])) {
                $g['offer']['value'] = rh_ai_vault_rehydrate($db, $conv, $g['offer']['value']);
            }
            return $g + ['skip' => [], 'hist' => [], 'step' => '', 'offer' => null];
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

// ── Reading answers ──────────────────────────────────────────────────────────

/** One date in words: today, tonight, tomorrow, a weekday, "5 Nov", "Nov 5", "the 5th", 2026-11-05, 5/11. Never in the past (no year: the next one). */
function rh_ai_guided_date(string $t, ?string $today = null): ?string
{
    $today = $today ?? rh_ai_today();
    $t = mb_strtolower(trim($t));
    if (preg_match('/\b(today|tonight|this evening)\b/u', $t)) {
        return $today;
    }
    if (preg_match('/\bday after tomorrow\b/u', $t)) {
        return date('Y-m-d', strtotime($today . ' +2 days'));
    }
    if (preg_match('/\btomorrow\b/u', $t)) {
        return date('Y-m-d', strtotime($today . ' +1 day'));
    }
    if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/u', $t, $m)) {
        return checkdate((int)substr($m[1], 5, 2), (int)substr($m[1], 8, 2), (int)substr($m[1], 0, 4)) ? $m[1] : null;
    }
    $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    $M = '(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';
    $d = $mo = $y = 0;
    if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?' . $M . '(?:,?\s+(\d{4}))?\b/u', $t, $m)) {
        [$d, $mo, $y] = [(int)$m[1], $months[mb_substr($m[2], 0, 3)], (int)($m[3] ?? 0)];
    } elseif (preg_match('/\b' . $M . '\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?\b/u', $t, $m)) {
        [$d, $mo, $y] = [(int)$m[2], $months[mb_substr($m[1], 0, 3)], (int)($m[3] ?? 0)];
    } elseif (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/u', $t, $m)) { // day/month, as written here
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], isset($m[3]) ? ((int)$m[3] < 100 ? 2000 + (int)$m[3] : (int)$m[3]) : 0];
    } elseif (preg_match('/\b(?:on )?the (\d{1,2})(?:st|nd|rd|th)\b/u', $t, $m)) {
        $d = (int)$m[1];
        $mo = (int)date('n', strtotime($today));
        if ($d < (int)date('j', strtotime($today))) {
            $mo = $mo === 12 ? 1 : $mo + 1;
        }
    } elseif (preg_match('/\b(?:(next|this) )?(mon(?:day)?|tue(?:s|sday)?|wed(?:nesday)?|thu(?:rs|rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)\b/u', $t, $m)) {
        $names = ['mon' => 'monday', 'tue' => 'tuesday', 'wed' => 'wednesday', 'thu' => 'thursday', 'fri' => 'friday', 'sat' => 'saturday', 'sun' => 'sunday'];
        $base = strtotime($today);
        $wd = $names[mb_substr($m[2], 0, 3)];
        $cand = date('l', $base) === ucfirst($wd) ? $today : date('Y-m-d', strtotime('next ' . $wd, $base));
        if (($m[1] ?? '') === 'next' && $cand === $today) {
            $cand = date('Y-m-d', strtotime($cand . ' +7 days'));
        }
        return $cand;
    } else {
        return null;
    }
    $yy = $y ?: (int)substr($today, 0, 4);
    if (!$mo || !checkdate($mo, $d, $yy)) {
        return null;
    }
    $out = sprintf('%04d-%02d-%02d', $yy, $mo, $d);
    if (!$y && $out < $today) {
        $out = checkdate($mo, $d, $yy + 1) ? sprintf('%04d-%02d-%02d', $yy + 1, $mo, $d) : null;
    }
    return $out;
}

/**
 * A stay in words: "5 to 7 Nov", "tonight", "Fri to Sun", "this weekend", "2 nights from tomorrow", "tomorrow for 3
 * nights", "from the 5th until the 8th". Returns [check_in, check_out|null] (null check-out = only the arrival was said).
 */
function rh_ai_guided_stay(string $t): ?array
{
    $l = mb_strtolower(trim($t));
    $today = rh_ai_today();
    $n = '(\d{1,2}|one|two|three|four|five|six|seven|eight|nine|ten)';
    if (function_exists('rh_ai_parse_stay_dates') && ($r = rh_ai_parse_stay_dates($l)) !== null) {
        return $r;
    }
    if (preg_match('/\bthis weekend\b/u', $l)) {
        $fri = date('l', strtotime($today)) === 'Friday' ? $today : date('Y-m-d', strtotime('next friday', strtotime($today)));
        return [$fri, date('Y-m-d', strtotime($fri . ' +2 days'))];
    }
    $nights = null;
    if (preg_match('/\b' . $n . ' nights?\b/u', $l, $m)) {
        $nights = (int)rh_ai_count_word($m[1]);
    } elseif (preg_match('/\b(?:a|one) night\b|\bovernight\b|^tonight$/u', $l)) {
        $nights = 1;
    }
    $parts = preg_split('/\s+(?:to|until|till|through|thru|-|–)\s+/u', $l, 2);
    if (count($parts) === 2 && ($a = rh_ai_guided_date((string)preg_replace('/^from\s+/u', '', $parts[0]))) !== null && ($b = rh_ai_guided_date($parts[1], $a)) !== null) {
        if ($b <= $a && preg_match('/\b(mon(day)?|tues?(day)?|wed(nesday)?|thu(rs)?(day)?|fri(day)?|sat(urday)?|sun(day)?)\b/u', $parts[1])) {
            $b = date('Y-m-d', strtotime($b . ' +7 days'));
        }
        return $b > $a ? [$a, $b] : null;
    }
    $start = rh_ai_guided_date($l);
    if ($start === null && $nights !== null && preg_match('/^(?:for )?' . $n . ' nights?$/u', $l)) {
        return null; // only a length: the caller adds it to a known arrival
    }
    if ($start === null) {
        return null;
    }
    if ($nights === null && preg_match('/\btonight\b/u', $l)) {
        $nights = 1;
    }
    return [$start, $nights !== null && $nights >= 1 && $nights <= 60 ? date('Y-m-d', strtotime($start . ' +' . $nights . ' days')) : null];
}

/** "2 nights", "for 3 nights" alone: the number of nights, or null. */
function rh_ai_guided_nights(string $t): ?int
{
    if (preg_match('/^(?:for |just |only )?(\d{1,2}|one|two|three|four|five|six|seven|eight|nine|ten|a) nights?$/u', mb_strtolower(trim($t)), $m)) {
        $n = $m[1] === 'a' ? 1 : (int)rh_ai_count_word($m[1]);
        return $n >= 1 && $n <= 60 ? $n : null;
    }
    return null;
}

/** A room type named in the text (the longest active type name it contains, or one clear close match). */
function rh_ai_guided_room_type(PDO $db, string $t, bool $loose): ?array
{
    $l = mb_strtolower($t);
    $best = null;
    foreach (rh_ai_room_type_names($db) as $r) {
        $name = mb_strtolower((string)$r['name']);
        if ($name !== '' && mb_strpos($l, $name) !== false && ($best === null || mb_strlen($name) > mb_strlen(mb_strtolower((string)$best['name'])))) {
            $best = $r;
        }
    }
    if ($best !== null) {
        return rh_ai_find_room_type($db, (string)$best['name']);
    }
    if (!$loose) { // inside a longer answer: a type's own distinctive word ("a deluxe", "the suite") when only one type has it
        $words = [];
        foreach (rh_ai_room_type_names($db) as $r) {
            foreach (preg_split('/[\s\-]+/u', mb_strtolower((string)$r['name'])) as $w) {
                if (mb_strlen($w) >= 4 && !in_array($w, ['room', 'rooms', 'with', 'view'], true)) {
                    $words[$w][(int)$r['id']] = (string)$r['name'];
                }
            }
        }
        foreach ($words as $w => $ids) {
            if (count($ids) === 1 && preg_match('/\b' . preg_quote($w, '/') . '\b/u', $l)) {
                return rh_ai_find_room_type($db, (string)reset($ids));
            }
        }
        return null;
    }
    $clean = trim((string)preg_replace('/\(.*$/u', '', $t));
    return $clean !== '' ? rh_ai_find_room_type($db, $clean) : null;
}

/** Control words: cancel / restart / back / skip / change <step>. ['do' => ..., 'step' => ...] or null. */
function rh_ai_guided_control(string $l, array $steps): ?array
{
    $l = trim((string)preg_replace('/[.!?]+$/u', '', mb_strtolower(trim($l))));
    if (preg_match('/^(cancel|cancel (it|this|that)|stop|never ?mind|forget (it|that)|quit|abort|no thanks|leave it)$/u', $l)) {
        return ['do' => 'cancel'];
    }
    if (preg_match('/^(start (again|over)|restart|from the (start|beginning)|start from scratch)$/u', $l)) {
        return ['do' => 'restart'];
    }
    if (preg_match('/^(back|go back|previous|undo( that)?|one step back)$/u', $l)) {
        return ['do' => 'back'];
    }
    if (preg_match('/^(skip|skip (it|this|that)|none|nothing|n\/?a|not needed|no (special )?requests?|no notes?|no reason|same as (the )?guest|it\'?s the guest|the same|leave (it )?blank)$/u', $l)) {
        return ['do' => 'skip'];
    }
    if (preg_match('/^(?:change|edit|fix|redo|correct) (?:the |their |his |her )?(.+)$/u', $l, $m)) {
        $map = ['dates?|stay|nights?|check.?in|check.?out|arrival' => ['check_in', 'from', 'new_checkout', 'due_date'], 'party|guests?|people|adults?|children|kids' => ['adults'],
            'room ?types?|type' => ['room_type', 'new_room_type'], 'room|room number' => ['room'], 'name|guest name|guest' => ['guest_name', 'booking_id'], 'phone|number|phone number' => ['guest_phone'],
            'email|e-mail|email address' => ['guest_email'], 'requests?|special requests?' => ['special_requests'], 'reason' => ['reason'], 'note|notes' => ['note', 'notes'],
            'hours|time|hold' => ['hours'], 'booking' => ['booking_id'], 'member' => ['member'], 'what|rooms?|block' => ['what']];
        foreach ($map as $re => $sids) {
            if (preg_match('/^(?:' . $re . ')$/u', trim($m[1]))) {
                foreach ($sids as $sid) {
                    if (isset($steps[$sid])) {
                        return ['do' => 'change', 'step' => $sid];
                    }
                }
            }
        }
    }
    return null;
}

/** A yes / no answer, or null. */
function rh_ai_guided_yesno(string $l): ?bool
{
    $l = trim((string)preg_replace('/[.!?]+$/u', '', mb_strtolower(trim($l))));
    if (preg_match('/^(yes|yeah|yep|yup|y|ok|okay|sure|please do|yes please|correct|right|that\'?s (them|him|her|it|right)|do it|go ahead)\b/u', $l)) {
        return true;
    }
    if (preg_match('/^(no|nope|n|not (them|him|her|that one)|no,? (a )?new guest|new guest|don\'?t|do not)\b/u', $l)) {
        return false;
    }
    return null;
}

/** Plain words for an option value ("out_of_order" -> "Out of order"). */
function rh_ai_guided_label(string $v): string
{
    return ucfirst(str_replace(['_', '-'], ' ', $v));
}

/**
 * Read an answer. $loose: the step being asked (a bare "2", "12", a name, free text count for it). Other fields are taken
 * only from clear patterns ("2 adults", "5 to 7 Nov", "room 12", an email, a phone). Returns field => value (unchecked).
 */
function rh_ai_guided_read(PDO $db, array $g, array $steps, string $raw, string $lq, string $loose): array
{
    $set = [];
    $a = (array)$g['args'];
    $key = (string)$g['key'];
    $props = (array)(rh_ai_action_registry()[$key]['schema']['properties'] ?? []);
    $l = mb_strtolower(trim($lq));
    $rawT = trim($raw);
    // Clear patterns, for any field the card has
    if (isset($steps['check_in'])) {
        $nOnly = rh_ai_guided_nights($l);
        if ($nOnly !== null && !empty($a['check_in'])) {
            $set['check_out'] = date('Y-m-d', strtotime($a['check_in'] . ' +' . $nOnly . ' days'));
        } elseif (($s = rh_ai_guided_stay($l)) !== null && ($loose === 'check_in' || preg_match('/\d|tonight|tomorrow|today|weekend|night|\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tue|wed|thu|fri|sat|sun)\b/u', $l))) {
            if ($s[1] === null && !empty($a['check_in']) && $loose === 'check_in' && empty($a['check_out']) && $s[0] > $a['check_in']) {
                $set['check_out'] = $s[0]; // "until Friday" after the arrival was given
            } else {
                $set['check_in'] = $s[0];
                if ($s[1] !== null) {
                    $set['check_out'] = $s[1];
                } elseif (!empty($a['check_out']) && $a['check_out'] <= $s[0]) {
                    $set['check_out'] = '';
                }
            }
        }
    }
    if (isset($steps['adults'])) {
        $p = function_exists('rh_ai_parse_party') ? rh_ai_parse_party($l) : null;
        if ($p !== null) {
            $set['adults'] = (int)$p['adults'];
            $set['children'] = (int)$p['children'];
            if (isset($props['rooms']) && (int)$p['rooms'] > 1) {
                $set['rooms'] = (int)$p['rooms'];
            }
        } elseif ($loose === 'adults' && preg_match('/^(\d{1,2}|one|two|three|four|five|six|seven|eight|nine|ten)(?:\s+(?:and|\+|plus)\s+(\d{1,2}|one|two|three|four|five|six)(?:\s+(?:child(?:ren)?|kids?))?)?$/u', $l, $m)) {
            $set['adults'] = (int)rh_ai_count_word($m[1]);
            $set['children'] = isset($m[2]) ? (int)rh_ai_count_word($m[2]) : 0;
        }
    }
    if (isset($props['rooms']) && preg_match('/\b(\d{1,2}|two|three|four|five) rooms\b/u', $l, $m)) {
        $set['rooms'] = (int)rh_ai_count_word($m[1]);
    }
    foreach (['room_type', 'new_room_type'] as $rf) {
        if (isset($steps[$rf]) && ($t = rh_ai_guided_room_type($db, $rawT, $loose === $rf)) !== null) {
            $set[$rf] = (string)$t['name'];
        }
    }
    if (isset($steps['guest_email']) && preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $rawT, $m)) {
        $set['guest_email'] = $m[0];
    }
    if (isset($steps['guest_phone']) && preg_match('/(?<![\w])\+?\(?\d[\d\s().\-]{6,18}\d(?![\w])/', $rawT, $m) && strlen((string)preg_replace('/\D/', '', $m[0])) >= 7
        && !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($m[0])) && ($loose === 'guest_phone' || preg_match('/^(\+|0)/', trim($m[0])))) {
        $set['guest_phone'] = trim($m[0]);
    }
    if (isset($steps['guest_name']) && preg_match('/\b(?:for|under|in the name of|name is|named|guest is|guest:)\s+((?:mr|mrs|ms|miss|dr)\.?\s+)?(\p{Lu}[\p{L}\'\-]+(?:\s+\p{Lu}[\p{L}\'\-]+){0,3})/u', $rawT, $m)) {
        $set['guest_name'] = trim($m[1] . $m[2]);
    }
    // The booking a card is about, said in the same words: a reference, "for / to / of Mr X", "X's hold"
    if (isset($steps['booking_id']) && $loose !== 'booking_id') {
        if (preg_match('/\b([A-Z]{2,6}20\d{6,10})\b/i', $rawT, $m)) {
            $set['booking_id'] = strtoupper($m[1]);
        } elseif (preg_match('/\b(?:for|to|of|on)\s+((?:(?i:mr|mrs|ms|miss|dr)\.?\s+)?\p{Lu}[\p{L}\'\-]+(?:\s+\p{Lu}[\p{L}\'\-]+){0,2})/u', $rawT, $m)
            || preg_match('/\b((?:(?i:mr|mrs|ms|miss|dr)\.?\s+)?\p{Lu}[\p{L}\'\-]+(?:\s+\p{Lu}[\p{L}\'\-]+){0,2})(?:\'s|’s)\s+(?:hold|booking|reservation|stay)\b/u', $rawT, $m)) {
            if (function_exists('rh_ai_desk_name') && rh_ai_desk_name($m[1]) !== null) {
                $set['booking_id'] = (string)rh_ai_desk_name($m[1]);
            }
        }
    }
    if (isset($steps['room']) && preg_match('/\b(?:room|rm|#)\s*([a-z0-9][a-z0-9\-]{0,9})\b/iu', $rawT, $m)) {
        $set['room'] = $m[1];
    }
    if (isset($props['hours']) && preg_match('/\b(\d{1,3})\s*(?:hours?|hrs?|h)\b/u', $l, $m)) {
        $set['hours'] = (int)$m[1];
    } elseif (isset($props['hours']) && preg_match('/\b(a|one|two|three) days?\b/u', $l, $m)) {
        $set['hours'] = 24 * ($m[1] === 'a' ? 1 : (int)rh_ai_count_word($m[1]));
    }
    if (isset($props['valid_days']) && preg_match('/\b(\d{1,2})\s*days?\b/u', $l, $m)) {
        $set['valid_days'] = (int)$m[1];
    }
    if (isset($props['status']) && isset($props['status']['enum']) && preg_match('/\b(tentative|hold|pending|confirmed)\b/u', $l, $m) && $loose !== 'special_requests') {
        $set['status'] = $m[1] === 'hold' ? 'tentative' : $m[1];
    }
    if (isset($steps['reason']) && preg_match('/\b(?:because|reason(?: is)?:?|as)\s+(.{3,200})$/iu', $rawT, $m) && $loose !== 'reason') {
        $set['reason'] = trim($m[1]);
    }
    if (isset($steps['what']) && $loose !== 'what') {
        if (preg_match('/\b(whole hotel|entire hotel|the hotel|all rooms|all room types|every room|all of it)\b/u', $l, $m)) {
            $set['what'] = 'all rooms';
        } elseif (isset($set['room']) || preg_match('/\b(?:room|rm)\s+([a-z0-9][a-z0-9\-]{0,9})\b/iu', $rawT, $rm)) {
            $set['what'] = 'room ' . ($set['room'] ?? $rm[1]);
        } elseif (($t = rh_ai_guided_room_type($db, $rawT, false)) !== null) {
            $set['what'] = (string)$t['name'];
        }
    }
    if (isset($steps['from']) && $loose !== 'from') {
        $span = rh_ai_guided_span($l);
        if ($span !== null) {
            [$set['from'], $set['to']] = $span;
        }
    }
    // The step being asked: a bare answer counts for it
    if ($loose !== '' && !array_key_exists($loose, $set)) {
        $step = $steps[$loose] ?? null;
        $p = (array)($props[$loose] ?? []);
        if ($step !== null) {
            switch ($step['kind']) {
                case 'number':
                    if (preg_match('/^(?:\+)?(\d{1,3})(?:\s*(?:hours?|hrs?|days?))?$/u', $l, $m)) {
                        $set[$loose] = (int)$m[1];
                    }
                    break;
                case 'choice':
                    foreach ((array)($p['enum'] ?? []) as $opt) {
                        $o = mb_strtolower(str_replace('_', ' ', (string)$opt));
                        if ($l === $o || $l === (string)$opt || ($o !== '' && mb_strpos($l, $o) !== false)) {
                            $set[$loose] = (string)$opt;
                            break;
                        }
                    }
                    if (!isset($set[$loose]) && $loose === 'step') {
                        if (preg_match('/\b(start|begin|starting|started)\b/u', $l)) {
                            $set[$loose] = 'start';
                        } elseif (preg_match('/\b(done|finish\w*|complete\w*|clean|fixed|ready)\b/u', $l)) {
                            $set[$loose] = 'complete';
                        }
                    }
                    break;
                case 'yesno':
                    $yn = rh_ai_guided_yesno($l);
                    if ($yn !== null) {
                        $set[$loose] = $yn;
                    }
                    break;
                case 'date':
                    if (($d = rh_ai_guided_date($l)) !== null) {
                        $set[$loose] = $d;
                    }
                    break;
                case 'span':
                    if (($span = rh_ai_guided_span($l)) !== null) {
                        [$set['from'], $set['to']] = $span;
                    }
                    break;
                case 'room':
                    if (preg_match('/^(?:room\s+)?([a-z0-9][a-z0-9\-]{0,9})$/iu', $rawT, $m)) {
                        $set['room'] = $m[1];
                    }
                    break;
                case 'target':
                case 'member':
                case 'booking':
                case 'name':
                case 'text':
                    if ($rawT !== '' && mb_strlen($rawT) <= (int)($p['maxLength'] ?? 500)) {
                        $set[$loose] = $rawT;
                    }
                    break;
                case 'email':
                case 'phone':
                    break; // only a real email / phone (above)
            }
        }
    }
    return $set;
}

/** Block dates in words: one date ("24 Dec") or from - to ("24 to 26 Dec"). [from, to] or null. */
function rh_ai_guided_span(string $l): ?array
{
    if (function_exists('rh_ai_parse_stay_dates') && ($r = rh_ai_parse_stay_dates($l)) !== null) {
        return [$r[0], $r[1]]; // blocked dates are dates, not nights: both ends included
    }
    $parts = preg_split('/\s+(?:to|until|till|through|thru|-|–)\s+/u', $l, 2);
    if (count($parts) === 2 && ($a = rh_ai_guided_date((string)preg_replace('/^(?:from|on)\s+/u', '', $parts[0]))) !== null && ($b = rh_ai_guided_date($parts[1], $a)) !== null && $b >= $a) {
        return [$a, $b];
    }
    $d = rh_ai_guided_date($l);
    return $d !== null ? [$d, $d] : null;
}

// ── Checking an answer against the database ──────────────────────────────────

/**
 * Check what was just set. Returns ['ok' => true] or ['error' => one line, 'chips' => real alternatives, 'clear' => fields].
 * Bookings, members and rooms are resolved here (a name becomes the booking id).
 */
function rh_ai_guided_check(PDO $db, array $user, int $conv, array &$g, array $set, array $focus, int $pageBooking): array
{
    $key = (string)$g['key'];
    $a = &$g['args'];
    $today = rh_ai_today();
    if (isset($set['check_in']) && $set['check_in'] !== '' && $set['check_in'] < $today) {
        return ['error' => 'That arrival date is in the past - which dates?', 'clear' => ['check_in', 'check_out']];
    }
    if (!empty($a['check_in']) && !empty($a['check_out'])) {
        if ($a['check_out'] <= $a['check_in']) {
            return ['error' => 'The check-out date must be after the check-in date.', 'clear' => ['check_out']];
        }
        if (rh_ai_nights($a['check_in'], $a['check_out']) > 60) {
            return ['error' => 'That is more than 60 nights - a stay that long is made on the Create Booking screen.', 'clear' => ['check_in', 'check_out']];
        }
    }
    if (isset($set['adults']) && ((int)$a['adults'] < 1 || (int)$a['adults'] > 40 || (int)($a['children'] ?? 0) > 20)) {
        return ['error' => 'How many adults (at least 1), and any children?', 'clear' => ['adults', 'children']];
    }
    // Create booking: the room type must be free for the dates and take the party (the Create Booking price and rules)
    if ($key === 'create_booking' && !empty($a['room_type']) && !empty($a['check_in']) && !empty($a['check_out']) && !empty($a['adults'])
        && (isset($set['room_type']) || isset($set['check_in']) || isset($set['check_out']) || isset($set['adults']) || isset($set['children']) || isset($set['rooms']))) {
        $q = rh_ai_t_quote_stay($db, $user, ['room_type' => $a['room_type'], 'check_in' => $a['check_in'], 'check_out' => $a['check_out'], 'adults' => (int)$a['adults'],
            'children' => (int)($a['children'] ?? 0), 'rooms' => max(1, (int)($a['rooms'] ?? 1))]);
        if (!empty($q['error']) || empty($q['available'])) {
            $why = !empty($q['error']) ? (string)$q['error'] : 'Not available: ' . implode(' ', (array)($q['notes'] ?? []));
            $chips = rh_ai_guided_alt_chips((array)($q['alternatives'] ?? []));
            return ['error' => $why, 'chips' => $chips, 'clear' => ['room_type']];
        }
        $a['room_type'] = (string)$q['room_type'];
    }
    foreach (['room_type', 'new_room_type'] as $rf) {
        if (isset($set[$rf]) && $key !== 'create_booking' && rh_ai_find_room_type($db, (string)$a[$rf]) === null) {
            return ['error' => 'No room type called "' . $set[$rf] . '".' . rh_ai_room_type_ask($db, (string)$set[$rf]), 'clear' => [$rf]];
        }
        if (isset($set[$rf]) && $key === 'create_booking' && rh_ai_find_room_type($db, (string)$a[$rf]) === null) {
            return ['error' => 'No room type called "' . $set[$rf] . '".' . rh_ai_room_type_ask($db, (string)$set[$rf]), 'clear' => [$rf]];
        }
    }
    if (isset($set['guest_email']) && !filter_var((string)$a['guest_email'], FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'That email address does not look right - what is it?', 'clear' => ['guest_email']];
    }
    if (isset($set['guest_phone'])) {
        $dg = strlen((string)preg_replace('/\D/', '', (string)$a['guest_phone']));
        if ($dg < 7 || $dg > 15) {
            return ['error' => 'That phone number does not look right - what is it?', 'clear' => ['guest_phone']];
        }
    }
    if (isset($set['guest_name']) && !preg_match('/\p{L}{2,}/u', (string)$a['guest_name'])) {
        return ['error' => "The guest's full name?", 'clear' => ['guest_name']];
    }
    foreach (['new_checkout', 'due_date'] as $df) {
        if (isset($set[$df]) && (string)$a[$df] < $today && $key !== 'change_checkout') {
            return ['error' => 'That date is in the past.', 'clear' => [$df]];
        }
    }
    if (isset($set['from']) && $key === 'block_dates' && (string)$a['from'] < $today) {
        return ['error' => 'A date in the past cannot be blocked: choose today or later.', 'clear' => ['from', 'to']];
    }
    if (isset($set['what'])) {
        $t = rh_ai_block_target($db, (string)$a['what']);
        if (!empty($t['error'])) {
            return ['error' => $t['error'], 'chips' => rh_ai_guided_chips_for($db, $user, $g, 'what'), 'clear' => ['what']];
        }
    }
    if (isset($set['room'])) {
        if (!rh_ai_act_room($db, (string)$a['room'])) {
            return ['error' => 'No active room numbered "' . $set['room'] . '".', 'chips' => rh_ai_guided_chips_for($db, $user, $g, 'room'), 'clear' => ['room']];
        }
    }
    if (isset($set['member'])) {
        $m = rh_ai_gym_member($db, (string)$a['member']);
        if (!empty($m['error'])) {
            $opts = array_slice(array_map('strval', (array)($m['options'] ?? [])), 0, rh_ai_num('ai_guided_chips'));
            return ['error' => $m['error'], 'chips' => array_map(static fn($o) => empty($m['by_number']) ? rh_ai_vault_token($db, $conv, 'GUEST', $o) : $o, $opts), 'clear' => ['member']];
        }
        $a['member'] = (string)$m['member']['member_number'];
        $g['member_label'] = (string)$m['member']['full_name'];
    }
    if (isset($set['booking_id'])) {
        $r = rh_ai_guided_booking($db, $user, $conv, $g, (string)$set['booking_id'], $focus, $pageBooking);
        if (!empty($r['error']) || !empty($r['offer'])) {
            return $r + ['clear' => ['booking_id']];
        }
        $a['booking_id'] = (int)$r['id'];
    }
    // The card's own read-only check, as soon as its subject is known (no point asking the rest for a booking it cannot use)
    $subject = isset($set['booking_id']) || isset($set['room']) || isset($set['member']);
    if ($subject) {
        foreach (rh_ai_guided_steps($key) as $osid => $ost) { // the rest of what the card needs must be there for its check
            if ($ost['need'] === 'needed' && !in_array($osid, ['booking_id', 'room', 'member', 'reason', 'note', 'title'], true) && !rh_ai_guided_filled($g, $osid, $ost)) {
                $subject = false;
            }
        }
    }
    if ($subject) {
        $pv = rh_ai_action_preview($db, $user, $key, rh_ai_guided_card_args($g, true));
        if (!empty($pv['error'])) {
            $sid = isset($set['booking_id']) ? 'booking_id' : (isset($set['room']) ? 'room' : 'member');
            return ['error' => (string)$pv['error'], 'chips' => rh_ai_guided_chips_for($db, $user, $g, $sid, true), 'clear' => [$sid]];
        }
    }
    return ['ok' => true];
}

/** Tap answers from a "not free" result: the other room types and dates that are free (real, priced by quote_stay). */
function rh_ai_guided_alt_chips(array $alt): array
{
    $chips = [];
    foreach ((array)($alt['other_room_types'] ?? $alt['room_types'] ?? []) as $o) {
        if (is_array($o) && !empty($o['room_type'])) {
            $chips[] = (string)$o['room_type'];
        }
    }
    foreach ((array)($alt['joined_rooms'] ?? []) as $o) {
        if (is_array($o) && !empty($o['room_type'])) {
            $chips[] = (string)$o['room_type'];
        }
    }
    foreach ((array)($alt['other_dates'] ?? $alt['dates'] ?? []) as $o) {
        if (is_array($o) && !empty($o['check_in'])) {
            $chips[] = date('j M', strtotime((string)$o['check_in'])) . ' to ' . date('j M', strtotime((string)$o['check_out']));
        }
    }
    return array_slice(array_values(array_unique($chips)), 0, rh_ai_num('ai_guided_chips'));
}

/**
 * The booking an answer means: focus / on screen (a pronoun or "yes"), a reference, a tapped "Name · REF", or a name
 * (the shared close-name search). ['id'] | ['error','chips'] | ['offer' => id, 'error' => "Do you mean ...?"]
 */
function rh_ai_guided_booking(PDO $db, array $user, int $conv, array &$g, string $ans, array $focus, int $pageBooking): array
{
    $ans = trim($ans);
    if (preg_match('/\b([A-Z]{2,6}20\d{6,10})\b/i', $ans, $m)) {
        $st = $db->prepare("SELECT id FROM bookings WHERE deleted_at IS NULL AND booking_reference = ? LIMIT 1");
        $st->execute([strtoupper($m[1])]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? ['id' => $id] : ['error' => 'No booking has the reference ' . strtoupper($m[1]) . '.'];
    }
    if ($ans === '' || (function_exists('rh_ai_desk_is_pronoun') && rh_ai_desk_is_pronoun($ans)) || rh_ai_guided_yesno($ans) === true) {
        $id = (int)($focus['focus'] ?? 0) > 0 ? (int)$focus['focus'] : $pageBooking;
        return $id > 0 ? ['id' => $id] : ['error' => 'Which booking? Tap one, or give the guest\'s name or the reference.'];
    }
    $who = function_exists('rh_ai_desk_name') ? (rh_ai_desk_name($ans) ?? rh_ai_desk_name(mb_convert_case($ans, MB_CASE_TITLE))) : $ans;
    if ($who === null) {
        return ['error' => 'Which booking? Tap one, or give the guest\'s name or the reference.'];
    }
    $prefer = rh_ai_guided_booking_filter((string)$g['key'])[0];
    $pick = rh_ai_desk_pick($db, $user, $conv, $who, $focus, $pageBooking, $prefer);
    if (isset($pick['id'])) {
        return ['id' => (int)$pick['id']];
    }
    $rep = (array)$pick['reply'];
    if (!empty($rep['focus_offer'])) {
        return ['offer' => (int)$rep['focus_offer'], 'error' => (string)$rep['text'], 'chips' => ['Yes, that\'s them', 'No']];
    }
    return ['error' => (string)$rep['text']];
}

/** Which bookings fit a card (for its tap answers and to pick among a repeat guest's bookings): [statuses, extra SQL, order]. */
function rh_ai_guided_booking_filter(string $key): array
{
    $map = [
        'check_in' => [['confirmed', 'pending'], "b.check_in_date <= CURDATE() AND b.check_out_date > CURDATE()", 'b.check_in_date, b.id'],
        'check_out' => [['checked-in'], '1=1', 'b.check_out_date, b.id'],
        'confirm_booking' => [['pending', 'tentative'], 'b.check_out_date >= CURDATE()', 'b.check_in_date, b.id'],
        'extend_hold' => [['tentative'], 'b.is_tentative = 1', 'b.tentative_expires_at, b.id'],
        'send_quotation' => [['pending', 'tentative', 'confirmed'], 'b.check_in_date >= CURDATE() AND COALESCE(b.amount_paid, 0) <= 0.01', 'b.check_in_date, b.id'],
        'assign_room' => [['confirmed', 'pending', 'checked-in'], 'b.individual_room_id IS NULL AND b.check_out_date >= CURDATE()', 'b.check_in_date, b.id'],
        'extend_stay' => [['checked-in'], '1=1', 'b.check_out_date, b.id'],
        'change_checkout' => [['checked-in'], '1=1', 'b.check_out_date, b.id'],
        'cancel_booking' => [['pending', 'tentative', 'confirmed'], 'b.check_out_date >= CURDATE()', 'b.check_in_date, b.id'],
    ];
    return $map[$key] ?? [['pending', 'tentative', 'confirmed', 'checked-in'], 'b.check_out_date >= CURDATE()', 'b.check_in_date, b.id'];
}

// ── Questions and tap answers ────────────────────────────────────────────────

/** Tap answers for one step, from the database (bookings that fit, rooms with jobs, free room types with prices ...). */
function rh_ai_guided_chips_for(PDO $db, array $user, array $g, string $sid, bool $afterError = false): array
{
    $key = (string)$g['key'];
    $a = (array)$g['args'];
    $max = rh_ai_num('ai_guided_chips');
    $props = (array)(rh_ai_action_registry()[$key]['schema']['properties'] ?? []);
    $chips = [];
    try {
        switch ($sid) {
            case 'booking_id':
                [$statuses, $where, $order] = rh_ai_guided_booking_filter($key);
                $st = $db->prepare("SELECT b.booking_reference, b.guest_name FROM bookings b WHERE b.deleted_at IS NULL AND b.status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ") AND $where ORDER BY $order LIMIT " . $max);
                $st->execute($statuses);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $chips[] = $r['guest_name'] . ' · ' . $r['booking_reference'];
                }
                break;
            case 'room':
                $sql = ['housekeeping_progress' => "SELECT DISTINCT ir.room_number FROM housekeeping_assignments h JOIN individual_rooms ir ON ir.id = h.individual_room_id WHERE h.status IN ('pending','in_progress') ORDER BY ir.room_number",
                    'maintenance_progress' => "SELECT DISTINCT ir.room_number FROM room_maintenance_schedules m JOIN individual_rooms ir ON ir.id = m.individual_room_id WHERE m.status IN ('planned','pending','in_progress') ORDER BY ir.room_number",
                    'housekeeping_verify' => "SELECT DISTINCT ir.room_number FROM housekeeping_assignments h JOIN individual_rooms ir ON ir.id = h.individual_room_id WHERE h.status = 'completed' AND h.verified_at IS NULL ORDER BY ir.room_number",
                    'maintenance_verify' => "SELECT DISTINCT ir.room_number FROM room_maintenance_schedules m JOIN individual_rooms ir ON ir.id = m.individual_room_id WHERE m.status = 'completed' AND m.verified_at IS NULL ORDER BY ir.room_number"][$key] ?? null;
                if ($sql !== null) {
                    foreach ($db->query($sql . " LIMIT " . $max)->fetchAll(PDO::FETCH_COLUMN) as $n) {
                        $chips[] = 'Room ' . $n;
                    }
                }
                break;
            case 'room_type':
                if ($key === 'create_booking' && !empty($a['check_in']) && !empty($a['check_out']) && !empty($a['adults'])) {
                    foreach (rh_ai_guided_free_types($db, $user, $a) as $t) {
                        $chips[] = $t['chip'];
                    }
                    break;
                }
                // no break: every active type
            case 'new_room_type':
                foreach (rh_ai_room_type_names($db) as $r) {
                    $chips[] = (string)$r['name'];
                }
                break;
            case 'what':
                $chips[] = 'Whole hotel';
                foreach (rh_ai_room_type_names($db) as $r) {
                    $chips[] = (string)$r['name'];
                }
                break;
            case 'check_in':
                if (!empty($a['check_in']) && empty($a['check_out'])) {
                    $chips = ['1 night', '2 nights', '3 nights'];
                } else {
                    $chips = ['Tonight', 'Tomorrow for 1 night', 'This weekend'];
                }
                break;
            case 'adults':
                $chips = ['1 adult', '2 adults', '2 adults and 1 child', '2 adults and 2 children'];
                break;
            case 'from':
                $chips = ['Today', 'Tomorrow'];
                break;
            case 'hours':
                $chips = array_map(static fn($h) => $h . ' hours', array_values(array_unique([rh_ai_num('ai_hold_extend_hours'), 12, 24, 48, 72])));
                break;
            case 'valid_days':
                $chips = array_map(static fn($d) => $d . ' days', array_values(array_unique([rh_ai_num('ai_quote_valid_days'), 3, 7, 14])));
                break;
            case 'new_checkout':
                $b = !empty($a['booking_id']) ? rh_ai_act_booking($db, ['booking_id' => (int)$a['booking_id']]) : [];
                if (empty($b['error']) && !empty($b['check_out_date'])) {
                    for ($i = 1; $i <= 3; $i++) {
                        $chips[] = date('D j M', strtotime((string)$b['check_out_date'] . ' +' . $i . ' days'));
                    }
                }
                break;
            case 'due_date':
                $chips = ['Today', 'Tomorrow'];
                break;
            default:
                $p = (array)($props[$sid] ?? []);
                if (isset($p['enum'])) {
                    $chips = array_map('rh_ai_guided_label', array_map('strval', $p['enum']));
                } elseif (($p['type'] ?? '') === 'boolean') {
                    $chips = ['Yes', 'No'];
                }
        }
    } catch (Throwable $e) {
        $chips = [];
    }
    return array_slice($chips, 0, $max);
}

/** Room types free for the stay that take the party, each priced by quote_stay (the Create Booking price). */
function rh_ai_guided_free_types(PDO $db, array $user, array $a): array
{
    $out = [];
    $priced = rh_ai_tool_allowed($user, 'quote_stay');
    foreach (rh_ai_room_type_names($db) as $r) {
        if (count($out) >= rh_ai_num('ai_guided_chips')) {
            break;
        }
        $q = rh_ai_t_quote_stay($db, $user, ['room_type' => (string)$r['name'], 'check_in' => $a['check_in'], 'check_out' => $a['check_out'], 'adults' => (int)$a['adults'],
            'children' => (int)($a['children'] ?? 0), 'rooms' => max(1, (int)($a['rooms'] ?? 1)), '_alt' => false]);
        if (!empty($q['error']) || empty($q['available'])) {
            continue;
        }
        $out[] = ['name' => (string)$q['room_type'], 'total' => (float)$q['total'], 'chip' => (string)$q['room_type'] . ($priced ? ' (' . rh_ai_fmt_money((float)$q['price_per_night']) . ' a night)' : '')];
    }
    return $out;
}

/** The question for one step, in plain words. */
function rh_ai_guided_question(PDO $db, array $user, array $g, string $sid): string
{
    $key = (string)$g['key'];
    $a = (array)$g['args'];
    $label = (string)rh_ai_action_registry()[$key]['label'];
    $q = [
        'check_in' => !empty($a['check_in']) && empty($a['check_out']) ? 'Arriving ' . rh_ai_fmt_date((string)$a['check_in']) . ' - until when (the check-out date, or how many nights)?' : 'Which dates? (for example "5 to 7 Nov", "tonight", "Fri to Sun" or "2 nights from tomorrow")',
        'adults' => 'How many guests - adults, and any children?',
        'room_type' => $key === 'create_booking' ? 'Which room type?' : 'Which room type?',
        'new_room_type' => 'Which room type should it move to?',
        'guest_name' => "The guest's full name?",
        'room_guest' => 'Who will sleep in the room, if not the guest? (Skip if it is the guest.)',
        'guest_phone' => "The guest's phone number?",
        'guest_email' => "The guest's email address?",
        'special_requests' => 'Any special requests? (Skip if none.)',
        'booking_id' => 'Which booking? Tap one, or give the guest\'s name or the reference.',
        'room' => 'Which room number?',
        'member' => 'Which member? Their name or member number.',
        'what' => $key === 'unblock_dates' ? 'What should be opened again - the whole hotel, a room type, or one room?' : 'What should be blocked - the whole hotel, a room type, or one room?',
        'from' => 'Which date, or from - to? (for example "24 Dec" or "24 to 26 Dec")',
        'reason' => $key === 'cancel_booking' ? 'Why is it being cancelled?' : 'The reason?',
        'note' => 'What should the note say?',
        'notes' => $key === 'send_quotation' ? 'A note on the quotation? (Skip if none.)' : 'Any notes? (Skip if none.)',
        'hours' => 'How much more time on the hold?',
        'valid_days' => 'How many days should the quotation stay valid?',
        'new_checkout' => 'The new check-out date?',
        'due_date' => 'When is it due?',
        'title' => 'What needs fixing (a short title)?',
        'step' => 'Start it, or mark it done?',
        'status' => $key === 'room_status' ? 'Which status?' : 'Which status?',
        'email_type' => 'Which email?',
        'job' => 'Which kind of job?',
        'priority' => 'How urgent?',
        'block_room' => 'Take the room off sale until it is fixed?',
        'type' => 'Which type?',
    ][$sid] ?? (rh_ai_guided_label($sid) . '?');
    if ($sid === 'room_type' && $key === 'create_booking' && !empty($a['check_in']) && !empty($a['check_out'])) {
        $q = 'Which room type? Free ' . rh_ai_fmt_date((string)$a['check_in']) . ' to ' . rh_ai_fmt_date((string)$a['check_out']) . ' for ' . (int)$a['adults'] . ' adult' . ((int)$a['adults'] === 1 ? '' : 's')
            . ((int)($a['children'] ?? 0) > 0 ? ' and ' . (int)$a['children'] . ' child' . ((int)$a['children'] === 1 ? '' : 'ren') : '') . ':';
    }
    return $q;
}

/** The card's arguments from the answers (+ the card's own defaults for a preview while still asking). */
function rh_ai_guided_card_args(array $g, bool $forCheck = false): array
{
    $a = array_filter((array)$g['args'], static fn($v) => $v !== '' && $v !== null);
    $props = (array)(rh_ai_action_registry()[(string)$g['key']]['schema']['properties'] ?? []);
    $out = [];
    foreach ($a as $k => $v) {
        if (isset($props[$k])) {
            $out[$k] = $v;
        }
    }
    if ($forCheck) { // the subject is checked early; a text still to come does not stop the check
        foreach (['note', 'title'] as $tf) {
            if (isset($props[$tf]) && empty($out[$tf])) {
                $out[$tf] = '-';
            }
        }
    }
    return $out;
}

// ── The flow ─────────────────────────────────────────────────────────────────

/** A reply of the flow (stored with the chat: the state goes with it). */
function rh_ai_guided_reply(string $text, array $chips, ?array $g, array $extra = []): array
{
    if ($g !== null) {
        $g['at'] = time();
    }
    return ['text' => $text, 'links' => [], 'chips' => $chips, 'route' => $g !== null ? 'guided' : 'local', 'guided' => $g ?? ['done' => 1]] + $extra;
}

/**
 * Ask the next open step, or make the card when everything is there. $lead: a line before the question (what was
 * taken, or why an answer did not fit).
 */
function rh_ai_guided_next(PDO $db, array $user, int $conv, array $g, string $lead = '', array $leadChips = []): array
{
    $steps = rh_ai_guided_steps((string)$g['key']);
    foreach ($steps as $sid => $st) {
        if (rh_ai_guided_filled($g, $sid, $st) || in_array($sid, (array)$g['skip'], true)) {
            continue;
        }
        $g['step'] = $sid;
        if (!in_array($sid, (array)$g['hist'], true)) {
            $g['hist'][] = $sid;
        }
        $chips = $leadChips ?: rh_ai_guided_chips_for($db, $user, $g, $sid);
        if ($st['need'] !== 'needed') {
            $chips[] = 'Skip';
        }
        $chips[] = 'Cancel';
        $q = rh_ai_guided_question($db, $user, $g, $sid);
        return rh_ai_guided_reply($lead !== '' && strpos($lead, $q) !== false ? $lead : trim($lead . "\n\n" . $q), array_values(array_unique($chips)), $g);
    }
    // Everything is there: the card (nothing changes until Confirm)
    $key = (string)$g['key'];
    $args = rh_ai_guided_card_args($g);
    rh_ai_step_set($db, (int)$user['id'], 'Preparing a card for you to check...');
    $p = rh_ai_propose($db, $user, $conv, $key, $args, false);
    rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => (string)rh_ai_action_registry()[$key]['tool'], 'args' => rh_ai_vault_protect($db, $conv, $args), 'result' => $p['json'], 'agent' => 'local']);
    if (!$p['ok']) {
        // Reopen the step the card could not use (the subject, or the room type for a stay) and ask it again
        $reopen = isset($steps['booking_id']) ? 'booking_id' : (isset($steps['room']) ? 'room' : (isset($steps['member']) ? 'member' : (isset($steps['room_type']) ? 'room_type' : (isset($steps['what']) ? 'what' : ''))));
        if ($reopen === '' || (int)($g['fails'] ?? 0) >= 2) {
            return rh_ai_guided_reply('Not prepared: ' . rh_ai_scrub_outbound($db, $conv, (string)$p['error']), [], null);
        }
        $g['fails'] = (int)($g['fails'] ?? 0) + 1;
        foreach ($steps[$reopen]['fields'] as $f) {
            unset($g['args'][$f]);
        }
        return rh_ai_guided_next($db, $user, $conv, $g, rh_ai_scrub_outbound($db, $conv, (string)$p['error']));
    }
    $focus = !empty($args['booking_id']) ? (int)$args['booking_id'] : null;
    return rh_ai_guided_reply(trim(($lead !== '' ? $lead . "\n\n" : '') . 'Check the card below and tap **Confirm** - nothing changes until you do.'), [], null, ['cards' => [(int)$p['card']['id']], 'focus' => $focus]);
}

/** Lines saying what an answer filled, in plain words (tokens for names). */
function rh_ai_guided_took(PDO $db, int $conv, array $g, array $set): string
{
    $bits = [];
    $a = (array)$g['args'];
    if (isset($set['check_in']) && !empty($a['check_in'])) {
        $bits[] = !empty($a['check_out']) ? rh_ai_fmt_date((string)$a['check_in']) . ' to ' . rh_ai_fmt_date((string)$a['check_out']) . ' (' . rh_ai_nights((string)$a['check_in'], (string)$a['check_out']) . ' night' . (rh_ai_nights((string)$a['check_in'], (string)$a['check_out']) === 1 ? '' : 's') . ')' : 'arriving ' . rh_ai_fmt_date((string)$a['check_in']);
    } elseif (isset($set['check_out']) && !empty($a['check_out']) && !empty($a['check_in'])) {
        $bits[] = rh_ai_fmt_date((string)$a['check_in']) . ' to ' . rh_ai_fmt_date((string)$a['check_out']);
    }
    if (isset($set['adults'])) {
        $bits[] = (int)$a['adults'] . ' adult' . ((int)$a['adults'] === 1 ? '' : 's') . ((int)($a['children'] ?? 0) > 0 ? ', ' . (int)$a['children'] . ' child' . ((int)$a['children'] === 1 ? '' : 'ren') : '');
    }
    foreach (['room_type', 'new_room_type'] as $rf) {
        if (isset($set[$rf]) && !empty($a[$rf])) {
            $bits[] = (string)$a[$rf];
        }
    }
    if (isset($set['guest_name']) && !empty($a['guest_name'])) {
        $bits[] = rh_ai_vault_token($db, $conv, 'GUEST', (string)$a['guest_name']);
    }
    if (isset($set['booking_id']) && !empty($a['booking_id'])) {
        $b = rh_ai_act_booking($db, ['booking_id' => (int)$a['booking_id']]);
        if (empty($b['error'])) {
            $bits[] = rh_ai_vault_token($db, $conv, 'GUEST', (string)$b['guest_name']) . ' (' . rh_ai_vault_token($db, $conv, 'BK', (string)$b['booking_reference']) . ')';
        }
    }
    if (isset($set['member']) && !empty($g['member_label'])) {
        $bits[] = rh_ai_vault_token($db, $conv, 'GUEST', (string)$g['member_label']);
    }
    if (isset($set['room']) && !empty($a['room'])) {
        $bits[] = 'room ' . $a['room'];
    }
    if (isset($set['from']) && !empty($a['from'])) {
        $bits[] = (string)$a['from'] === (string)($a['to'] ?? $a['from']) ? rh_ai_fmt_date((string)$a['from']) : rh_ai_fmt_date((string)$a['from']) . ' to ' . rh_ai_fmt_date((string)$a['to']);
    }
    return $bits ? 'Got it: ' . implode(', ', $bits) . '.' : '';
}

/**
 * Has this guest stayed before? An exact name on file fills the phone and email from their latest booking (said on the
 * card); a clearly close name is offered first ("Is that ...?"). Never a guess.
 */
function rh_ai_guided_known_guest(PDO $db, array &$g): string
{
    $name = trim((string)($g['args']['guest_name'] ?? ''));
    if ($name === '' || (string)$g['key'] !== 'create_booking') {
        return '';
    }
    $st = $db->prepare("SELECT guest_name, guest_email, guest_phone FROM bookings WHERE deleted_at IS NULL AND LOWER(TRIM(guest_name)) = LOWER(?) ORDER BY id DESC LIMIT 1");
    $st->execute([$name]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        $fz = rh_ai_fuzzy_from_table($db, $name, 'bookings', 'guest_name', 'deleted_at IS NULL', [], 'id DESC');
        if (!empty($fz['clear']) && mb_strtolower((string)$fz['clear']) !== mb_strtolower($name)) {
            $g['offer'] = ['field' => 'guest_name', 'value' => (string)$fz['clear']];
        }
        return '';
    }
    $filled = [];
    if (empty($g['args']['guest_email']) && filter_var((string)$r['guest_email'], FILTER_VALIDATE_EMAIL)) {
        $g['args']['guest_email'] = (string)$r['guest_email'];
        $filled[] = 'email';
    }
    if (empty($g['args']['guest_phone']) && trim((string)$r['guest_phone']) !== '') {
        $g['args']['guest_phone'] = trim((string)$r['guest_phone']);
        $filled[] = 'phone';
    }
    return $filled ? 'They have stayed before: their ' . implode(' and ', $filled) . ' are taken from their last booking (change them on the card if needed).' : '';
}

/** Start a flow for one card: checks first, then what the words already say, then the first open step. */
function rh_ai_guided_begin(PDO $db, array $user, int $conv, string $key, string $raw, string $lq, array $prefill, array $focus, int $pageBooking): array
{
    $def = rh_ai_action_registry()[$key] ?? null;
    if ($def === null || !in_array($key, rh_ai_guided_keys(), true)) {
        return rh_ai_guided_reply('That one needs the screen.', [], null);
    }
    if (!rh_ai_page_modules_on((string)$def['page'])) {
        $mod = function_exists('getModuleForPage') ? getModuleForPage((string)$def['page']) : null;
        $first = is_array($mod) ? (string)($mod[0] ?? '') : (string)$mod;
        foreach ((array)$mod as $mm) {
            if (!rh_ai_module_on((string)$mm)) {
                $first = (string)$mm;
                break;
            }
        }
        $line = rh_ai_not_offered_lines()[$first] ?? "That isn't offered here.";
        $help = rh_ai_can_help_line($user);
        return ['text' => $line . ($help !== '' ? "\n\n" . $help : ''), 'links' => [], 'chips' => ['What can I do here?'], 'route' => 'declined'];
    }
    $problem = rh_ai_action_problem($user, $key);
    if ($problem !== '') {
        $help = rh_ai_can_help_line($user);
        return ['text' => $problem . ($help !== '' ? "\n\n" . $help : ''), 'links' => [], 'chips' => ['What can I do here?'], 'route' => 'declined'];
    }
    $g = ['key' => $key, 'args' => [], 'skip' => [], 'hist' => [], 'step' => '', 'offer' => null, 'at' => time(), 'v' => 1];
    $steps = rh_ai_guided_steps($key);
    $set = rh_ai_guided_read($db, $g, $steps, $raw, $lq, '');
    foreach ($prefill as $k => $v) {
        if (($v !== '' && $v !== null) || $k === 'booking_id') { // booking_id '' = the booking in focus (or on screen)
            $set[$k] = (string)$v;
        }
    }
    return rh_ai_guided_apply($db, $user, $conv, $g, $set, $focus, $pageBooking, true);
}

/** Put answers in, check them, then ask the next step (or make the card). */
function rh_ai_guided_apply(PDO $db, array $user, int $conv, array $g, array $set, array $focus, int $pageBooking, bool $first = false): array
{
    if (!$set && !$first) {
        return rh_ai_guided_next($db, $user, $conv, $g, "I didn't catch that.");
    }
    foreach ($set as $k => $v) {
        $g['args'][$k] = $v;
        $g['skip'] = array_values(array_diff((array)$g['skip'], [$k]));
    }
    $chk = rh_ai_guided_check($db, $user, $conv, $g, $set, $focus, $pageBooking);
    if (empty($chk['ok'])) {
        foreach ((array)($chk['clear'] ?? []) as $f) {
            unset($g['args'][$f]);
        }
        if (!empty($chk['offer'])) {
            $g['offer'] = ['field' => 'booking_id', 'value' => (string)(int)$chk['offer']];
            $g['step'] = 'booking_id';
            return rh_ai_guided_reply(rh_ai_scrub_outbound($db, $conv, (string)$chk['error']), ['Yes, that\'s them', 'No', 'Cancel'], $g);
        }
        $took = rh_ai_guided_took($db, $conv, $g, array_diff_key($set, array_flip((array)($chk['clear'] ?? []))));
        return rh_ai_guided_next($db, $user, $conv, $g, trim($took . ' ' . rh_ai_scrub_outbound($db, $conv, (string)$chk['error'])), (array)($chk['chips'] ?? []));
    }
    $lead = rh_ai_guided_took($db, $conv, $g, $set);
    if (isset($set['guest_name'])) {
        $known = rh_ai_guided_known_guest($db, $g);
        if (!empty($g['offer']) && $g['offer']['field'] === 'guest_name') {
            $g['step'] = 'guest_name';
            $tok = rh_ai_vault_token($db, $conv, 'GUEST', (string)$g['offer']['value']);
            return rh_ai_guided_reply(trim($lead . "\n\nIs that **" . $tok . '**, who has stayed before?'), ['Yes, that\'s them', 'No, a new guest', 'Cancel'], $g);
        }
        $lead = trim($lead . ' ' . $known);
    }
    return rh_ai_guided_next($db, $user, $conv, $g, $lead);
}

/**
 * One turn while a flow is open. Returns a reply, or ['offscript' => true] when the message is a real question to
 * answer as usual (the step is asked again after it).
 */
function rh_ai_guided_continue(PDO $db, array $user, int $conv, array $g, string $raw, string $lq, array $focus, int $pageBooking): array
{
    $steps = rh_ai_guided_steps((string)$g['key']);
    $label = (string)rh_ai_action_registry()[(string)$g['key']]['label'];
    $l = mb_strtolower(trim($lq));
    // A pending "Do you mean ...?"
    if (!empty($g['offer'])) {
        $yn = rh_ai_guided_yesno($l);
        $offer = (array)$g['offer'];
        $g['offer'] = null;
        if ($yn === true) {
            if ($offer['field'] === 'booking_id') {
                return rh_ai_guided_apply($db, $user, $conv, $g, ['booking_id' => (string)$offer['value']], ['focus' => (int)$offer['value'], 'offer' => 0], $pageBooking);
            }
            $g['args']['guest_name'] = (string)$offer['value'];
            $known = rh_ai_guided_known_guest($db, $g);
            return rh_ai_guided_next($db, $user, $conv, $g, trim('Got it: ' . rh_ai_vault_token($db, $conv, 'GUEST', (string)$offer['value']) . '. ' . $known));
        }
        if ($yn === false) {
            if ($offer['field'] === 'booking_id') {
                return rh_ai_guided_next($db, $user, $conv, $g, 'OK.');
            }
            return rh_ai_guided_next($db, $user, $conv, $g, 'OK - a new guest.');
        }
    }
    $c = rh_ai_guided_control($l, $steps);
    if ($c !== null) {
        switch ($c['do']) {
            case 'cancel':
                return rh_ai_guided_reply('Stopped - nothing was saved. (' . $label . ')', [], null);
            case 'restart':
                $g['args'] = [];
                $g['skip'] = [];
                $g['hist'] = [];
                return rh_ai_guided_next($db, $user, $conv, $g, 'Starting again.');
            case 'back':
                $hist = (array)$g['hist'];
                $cur = array_pop($hist); // the step being asked
                $prev = array_pop($hist);
                if ($prev === null) {
                    return rh_ai_guided_next($db, $user, $conv, $g, 'This is the first question.');
                }
                foreach ((array)($steps[$prev]['fields'] ?? [$prev]) as $f) {
                    unset($g['args'][$f]);
                }
                $g['skip'] = array_values(array_diff((array)$g['skip'], [$prev]));
                $g['hist'] = $hist;
                return rh_ai_guided_next($db, $user, $conv, $g);
            case 'skip':
                $sid = (string)$g['step'];
                if ($sid === '' || ($steps[$sid]['need'] ?? 'needed') === 'needed') {
                    return rh_ai_guided_next($db, $user, $conv, $g, 'That one is needed.');
                }
                $g['skip'][] = $sid;
                return rh_ai_guided_next($db, $user, $conv, $g);
            case 'change':
                foreach ((array)$steps[$c['step']]['fields'] as $f) {
                    unset($g['args'][$f]);
                }
                $g['skip'] = array_values(array_diff((array)$g['skip'], [$c['step']]));
                $g['hist'] = array_values(array_diff((array)$g['hist'], [$c['step']]));
                return rh_ai_guided_next($db, $user, $conv, $g);
        }
    }
    $sid = (string)$g['step'];
    $kind = (string)($steps[$sid]['kind'] ?? 'text');
    $isQuestion = function_exists('rh_ai_money_is_question') && rh_ai_money_is_question($l) && !preg_match('/^(no|none)\b/u', $l);
    $freeText = in_array($kind, ['text', 'name', 'booking', 'member', 'target'], true);
    // A question in the middle: answered as usual, then this step again (no answer is taken from a question)
    if ($isQuestion && !rh_ai_guided_read($db, $g, $steps, $raw, $lq, in_array($kind, ['text', 'name'], true) ? '' : $sid)) {
        return ['offscript' => true];
    }
    // Another change asked in the middle: never started on top (a typo never jumps to another action)
    $other = $freeText ? null : rh_ai_guided_start_intent($raw);
    if (($other !== null && $other['key'] !== $g['key']) || (!$freeText && function_exists('rh_ai_text_is_write') && rh_ai_text_is_write($l))) {
        return rh_ai_guided_next($db, $user, $conv, $g, 'We are in the middle of: ' . $label . '. Finish it, or say "cancel" first.');
    }
    $set = rh_ai_guided_read($db, $g, $steps, $raw, $lq, $sid);
    if (!$set && !$freeText && !$isQuestion && mb_strlen($l) > 40) {
        return ['offscript' => true]; // a longer message that answers nothing: let the rest of the chat answer it
    }
    return rh_ai_guided_apply($db, $user, $conv, $g, $set, $focus, $pageBooking);
}

/**
 * Starting a card in plain words (the person's own words only - never words read as typos): ['key', 'prefill' => [...]]
 * or null. The desk's own card words ("check him in", "room 12 is clean"...) start flows too (ai-desk.php).
 */
function rh_ai_guided_start_intent(string $raw): ?array
{
    $l = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', function_exists('rh_ai_desk_clean') ? rh_ai_desk_clean($raw) : $raw)));
    if ($l === '' || mb_strlen($l) > 300) {
        return null;
    }
    if (preg_match('/^(how|what|why|when|where|which|who|can i|could i|should|did|does|do i|is|are|was|were|has|have)\b/u', $l)) {
        return null;
    }
    $notRoom = '/\b(table|spa|massage|conference|meeting room|event|gym|class|transfer|taxi|tour|airport)\b/u';
    if (preg_match('/^(?:make|create|add|do|take|start|open)\s+(?:a |an )?(?:new )?(?:room )?(?:booking|reservation)\b|^new (?:room )?(?:booking|reservation)\b|^book\b(?! ?(?:ing|ed))|^reserve\b|^i (?:want|need|would like|\'d like) to (?:book|reserve)\b/u', $l)
        && !preg_match($notRoom, $l)) {
        return ['key' => 'create_booking', 'prefill' => []];
    }
    if (preg_match('/^(?:unblock|re-?open|open up)\b/u', $l)) {
        return ['key' => 'unblock_dates', 'prefill' => []];
    }
    if (preg_match('/^(?:block|close)\b(?! (?:the )?(?:shift|day|till|tab|order|account))/u', $l) && !preg_match('/^close (?:the )?(?:booking|reservation)/u', $l)) {
        return ['key' => 'block_dates', 'prefill' => []];
    }
    if (preg_match('/^(?:extend|lengthen|give (?:more|extra) time (?:to|on))\s+(?:the |their |his |her |this |that )?(?:tentative )?hold\b|^(?:extend|lengthen) (?:the )?hold\b|^extend (?:\p{L}+\'?s? ){1,3}hold\b/u', $l)) {
        return ['key' => 'extend_hold', 'prefill' => []];
    }
    if (preg_match('/^(?:send|email|e-mail|resend)\b.{0,40}\b(?:quotation|quote)\b/u', $l)) {
        return ['key' => 'send_quotation', 'prefill' => []];
    }
    if (preg_match('/^(?:verify|sign off|signoff|approve|pass)\b.{0,40}\b(?:room|job|cleaning|repair|maintenance)\b|^room \S+ (?:is |has been )?(?:verified|signed off|inspected|passed)$/u', $l)) {
        return ['key' => preg_match('/\b(repair|maintenance|fix\w*|plumb\w*|electric\w*)\b/u', $l) ? 'maintenance_verify' : 'housekeeping_verify', 'prefill' => []];
    }
    if (preg_match('/^(?:gym )?check[- ]?in\b.{0,60}\b(?:at|to|into|for) the gym$|^gym check[- ]?in\b|^check (.+?) in(?:to)? (?:at |to )?the gym$|^(.+?) is (?:at|in) the gym$/u', $l, $m)) {
        $who = '';
        if (preg_match('/^gym check[- ]?in(?: for)? (.+)$/u', $l, $mm) || preg_match('/^check[- ]?in (.+?) (?:at|to|into|for) the gym$/u', $l, $mm) || preg_match('/^check (.+?) in(?:to)? (?:at |to )?the gym$/u', $l, $mm) || preg_match('/^(.+?) is (?:at|in) the gym$/u', $l, $mm)) {
            $who = trim($mm[1]);
        }
        return ['key' => 'gym_checkin', 'prefill' => $who !== '' && !preg_match('/^(a|the|a gym|someone|a member|member)$/u', $who) ? ['member' => (string)preg_replace('/^(?:member )/u', '', $who)] : []];
    }
    if (preg_match('/^cancel (?:a |the |this |that |their |his |her )?(?:booking|reservation)$/u', $l)) {
        return ['key' => 'cancel_booking', 'prefill' => []];
    }
    if (preg_match('/^extend (?:a |the |their |his |her )?stay$/u', $l)) {
        return ['key' => 'extend_stay', 'prefill' => []];
    }
    if (preg_match('/^(?:add|create|new|log|report|raise|open) (?:a )?(?:new )?(?:maintenance|repair) (?:job|task|request)\b/u', $l)) {
        return ['key' => 'maintenance_job', 'prefill' => []];
    }
    if (preg_match('/^(?:add|create|new|schedule) (?:a )?(?:new )?housekeeping (?:job|task)\b/u', $l)) {
        return ['key' => 'housekeeping_job', 'prefill' => []];
    }
    if (preg_match('/^(?:change|set|update) (?:a |the )?room(?:\'s)? status\b/u', $l)) {
        return ['key' => 'room_status', 'prefill' => []];
    }
    if (preg_match('/^(?:check (?:a |the )?guest in|check[- ]?in (?:a |the )?guest)$/u', $l)) {
        return ['key' => 'check_in', 'prefill' => []];
    }
    if (preg_match('/^(?:check (?:a |the )?guest out|check[- ]?out (?:a |the )?guest)$/u', $l)) {
        return ['key' => 'check_out', 'prefill' => []];
    }
    if (preg_match('/^confirm (?:a |the )?(?:booking|reservation|hold)$/u', $l)) {
        return ['key' => 'confirm_booking', 'prefill' => []];
    }
    if (preg_match('/^(?:add|write|make|leave) (?:a )?(?:booking )?note$/u', $l)) {
        return ['key' => 'booking_note', 'prefill' => []];
    }
    if (preg_match('/^(?:assign|allocate) (?:a )?room$/u', $l)) {
        return ['key' => 'assign_room', 'prefill' => []];
    }
    if (preg_match('/^(?:send|resend|email) (?:the |an |a )?invoice$/u', $l)) {
        return ['key' => 'send_invoice', 'prefill' => []];
    }
    if (preg_match('/^(?:change|upgrade|downgrade|move) (?:the |a )?room type$/u', $l)) {
        return ['key' => 'room_type_change', 'prefill' => []];
    }
    return null;
}

/**
 * The guided part of a turn, before anything else local or the model. Returns a reply, ['offscript' => resume] when an
 * open flow should be asked again after the usual answer, or null (no flow here).
 */
function rh_ai_guided_turn(PDO $db, array $user, int $conv, string $raw, string $lq, array $focus, int $pageBooking, bool $new): ?array
{
    $g = $new ? null : rh_ai_guided_load($db, $conv);
    if ($g !== null && isset($g['expired'])) {
        $l = mb_strtolower(trim($raw));
        if (rh_ai_guided_control($l, rh_ai_guided_steps((string)$g['key'])) !== null || (mb_strlen($l) <= 40 && !rh_ai_money_is_question($l) && rh_ai_guided_start_intent($raw) === null)) {
            return rh_ai_guided_reply('The step-by-step ' . mb_strtolower((string)$g['expired']) . ' was left for over ' . rh_ai_num('ai_guided_minutes') . ' minutes, so it was dropped - nothing was saved. Say what you would like to do and we will start again.', [], null);
        }
        $g = null;
    }
    if ($g !== null) {
        $r = rh_ai_guided_continue($db, $user, $conv, $g, $raw, $lq, $focus, $pageBooking);
        if (!empty($r['offscript'])) {
            $steps = rh_ai_guided_steps((string)$g['key']);
            $sid = (string)$g['step'];
            $chips = $sid !== '' ? rh_ai_guided_chips_for($db, $user, $g, $sid) : [];
            if ($sid !== '' && ($steps[$sid]['need'] ?? 'needed') !== 'needed') {
                $chips[] = 'Skip';
            }
            $chips[] = 'Cancel';
            return ['offscript' => true, 'resume' => 'Back to the ' . mb_strtolower((string)rh_ai_action_registry()[(string)$g['key']]['label']) . ': ' . ($sid !== '' ? rh_ai_guided_question($db, $user, $g, $sid) : ''), 'chips' => $chips];
        }
        return $r;
    }
    $si = rh_ai_guided_start_intent($raw);
    if ($si === null) {
        return null;
    }
    return rh_ai_guided_begin($db, $user, $conv, (string)$si['key'], $raw, $lq, (array)$si['prefill'], $focus, $pageBooking);
}
