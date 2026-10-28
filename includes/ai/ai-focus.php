<?php

/**
 * The booking a chat is about, close-name matches and what a booking includes (owner fault 2026-10-29, a real chat at
 * Rosalyn's: "When is Mr Ntahni coming" found nothing for "Charles Nthani"; "How much was Mr Nthanis room" was answered
 * from a date-range revenue report; "Are they getting free breakfast" used an invented booking id).
 *
 *  - Close names: a name search that finds nothing looks for names that are close (letters swapped, a near spelling,
 *    the same sound) in a fixed-size set of bookings (the latest and the upcoming ones, ai_close_match_pool each, plus
 *    the same-sounding ones). One query whatever the number of bookings. A close match is only ever OFFERED ("Did you
 *    mean ...?"); it never stands for the person until they say yes or name them.
 *  - Focus: once a turn settles on exactly one booking it is stored on that reply (ai_messages meta "focus"); later
 *    questions about "they / him / her / the guest / the room / Mr X" use it. Booking money questions are answered from
 *    the booking's own bill (get_booking: total, paid, balance due), locally and with no tokens.
 *  - Booking ids or references the model passes to get_booking must come from this chat (its lookups, the vault, what
 *    the person typed, the booking open on screen); an invented one is refused and the booking in focus is used.
 *  - What's included: the booking's packages and their inclusions, its rate plan, and the room type's listed extras.
 *    Breakfast is matched by the words in the ai_breakfast_words setting; nothing about breakfast is assumed.
 * Read only throughout: no write path, every lookup goes through rh_ai_run_tool (permission and module checks).
 */

require_once __DIR__ . '/ai-core.php';
require_once __DIR__ . '/ai-fuzzy.php'; // the shared close-match helper

/**
 * Bookings whose guest name is close to $q (a name search that found nothing), by the shared rule (rh_ai_fuzzy_pick,
 * owner 2026-10-29): ONE clearly closest name is the guest meant - use it and show the name as on the booking; several
 * plausible names, or only a weak one, are listed for one short "Do you mean ...?". One query, a fixed-size candidate
 * set: the latest ai_close_match_pool bookings, the next ai_close_match_pool arrivals, and up to ai_close_match_pool whose
 * first or last name sounds the same (SOUNDEX). A clear name with several bookings lists those bookings to pick from.
 * @return array{clear:?array,options:array} clear = the one booking meant (or null); options = bookings to ask about
 */
function rh_ai_close_name_search(PDO $db, string $q, string $status = ''): array
{
    $none = ['clear' => null, 'options' => []];
    if (preg_match('/[@\d]/', $q)) {
        return $none; // an email, phone or reference is not a name
    }
    $asked = rh_ai_name_words($q);
    if (!$asked) {
        return $none;
    }
    $n = rh_ai_num('ai_close_match_pool');
    $key = $asked;
    usort($key, static fn($x, $y) => mb_strlen($y) <=> mb_strlen($x));
    $key = $key[0];
    $cols = "SELECT b.id, b.booking_reference, b.guest_name, b.room_guest_name, b.check_in_date, b.check_out_date, b.status, r.name AS room_name
             FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.deleted_at IS NULL" . ($status !== '' ? ' AND b.status = ?' : '');
    $sp = $status !== '' ? [$status] : [];
    $sql = "($cols ORDER BY b.id DESC LIMIT $n)
            UNION ($cols AND b.check_in_date >= CURDATE() ORDER BY b.check_in_date, b.id LIMIT $n)
            UNION ($cols AND (SOUNDEX(SUBSTRING_INDEX(TRIM(b.guest_name), ' ', -1)) = SOUNDEX(?) OR SOUNDEX(SUBSTRING_INDEX(TRIM(b.guest_name), ' ', 1)) = SOUNDEX(?)
                              OR SOUNDEX(SUBSTRING_INDEX(TRIM(COALESCE(b.room_guest_name, '')), ' ', -1)) = SOUNDEX(?)) ORDER BY b.id DESC LIMIT $n)";
    $st = $db->prepare($sql);
    $st->execute(array_merge($sp, $sp, $sp, [$key, $key, $key]));
    $byName = [];  // one entry per distinct name (a repeat guest is one name with several bookings)
    $labels = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach (array_unique(array_filter([trim((string)$r['guest_name']), trim((string)($r['room_guest_name'] ?? ''))])) as $name) {
            $k = mb_strtolower((string)preg_replace('/\s+/u', ' ', $name));
            $labels[$k] = $name;
            $byName[$k][(int)$r['id']] = ['booking_id' => (int)$r['id'], 'reference' => $r['booking_reference'], 'guest_name' => $r['guest_name'], 'room_type' => $r['room_name'],
                'check_in' => $r['check_in_date'], 'check_out' => $r['check_out_date'], 'status' => $r['status']];
        }
    }
    $p = rh_ai_fuzzy_pick($q, $labels);
    $top = rh_ai_num('ai_lookup_matches');
    if ($p['clear'] !== null) {
        $rows = array_values($byName[$p['clear']]);
        usort($rows, static fn($x, $y) => (int)$y['booking_id'] <=> (int)$x['booking_id']);
        return count($rows) === 1 ? ['clear' => $rows[0], 'options' => []] : ['clear' => null, 'options' => array_slice($rows, 0, $top)];
    }
    $opts = [];
    foreach ($p['options'] as $k) {
        foreach ($byName[$k] as $id => $row) {
            $opts[$id] = $row;
        }
    }
    return ['clear' => null, 'options' => array_slice(array_values($opts), 0, $top)];
}
/** The word of a booking's guest name closest to what was asked ("Ntahni" -> "Nthani"), as written on the booking. */
function rh_ai_closest_name_word(string $asked, string $guestName): string
{
    $words = rh_ai_name_words($asked);
    $best = '';
    $bestS = -1.0;
    foreach (preg_split('/\s+/u', trim($guestName)) as $w) {
        $plain = (string)preg_replace('/[^\p{L}\'\-]/u', '', $w);
        if (mb_strlen($plain) < 3) {
            continue;
        }
        $s = rh_ai_name_closeness($words, $plain)[0];
        if ($s > $bestS) {
            $bestS = $s;
            $best = $plain;
        }
    }
    return $best;
}

/** "Charles Nthani (arriving 30 Oct)" / "(in house)" / "(left 3 Oct)" / "(cancelled)": a short option for "Do you mean ...?". */
function rh_ai_booking_option(array $r): string
{
    $s = (string)($r['status'] ?? '');
    $d = static fn($x) => $x ? date('j M', strtotime((string)$x)) : '';
    if ($s === 'checked-in') {
        $when = 'in house, leaves ' . $d($r['check_out'] ?? '');
    } elseif (in_array($s, ['cancelled', 'no-show', 'expired'], true)) {
        $when = str_replace('-', ' ', $s);
    } elseif ($s === 'checked-out' || (string)($r['check_out'] ?? '') < date('Y-m-d')) {
        $when = 'left ' . $d($r['check_out'] ?? '');
    } else {
        $when = 'arriving ' . $d($r['check_in'] ?? '');
    }
    return '**' . ($r['guest_name'] ?? '') . '** (' . ($r['reference'] ?? '') . ', ' . $when . ')';
}

/** The words that mean breakfast (setting ai_breakfast_words, comma separated; the plain word when empty). */
function rh_ai_breakfast_words(): array
{
    $w = array_values(array_filter(array_map(static fn($x) => mb_strtolower(trim($x)), explode(',', rh_ai_setting('ai_breakfast_words', 'breakfast')))));
    return $w ?: ['breakfast'];
}

/** True when $text mentions breakfast (any word of the setting, as a whole word or its start). */
function rh_ai_mentions_breakfast(string $text): bool
{
    foreach (rh_ai_breakfast_words() as $w) {
        if (preg_match('/(?<![\p{L}])' . preg_quote($w, '/') . '/iu', $text)) {
            return true;
        }
    }
    return false;
}

/**
 * What one booking includes: its packages with their inclusions, its rate plan (name and description), the room
 * type's listed extras, and whether any of them mentions breakfast (and where). Two small queries.
 */
function rh_ai_booking_inclusions(PDO $db, array $b): array
{
    $packages = [];
    try {
        $st = $db->prepare("SELECT bp.package_name, p.name, p.inclusions FROM booking_packages bp LEFT JOIN room_packages p ON p.id = bp.package_id WHERE bp.booking_id = ? ORDER BY bp.id LIMIT 20");
        $st->execute([(int)$b['id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $inc = json_decode((string)($p['inclusions'] ?? ''), true);
            $packages[] = ['package' => (string)(($p['package_name'] ?? '') !== '' ? $p['package_name'] : ($p['name'] ?? 'Package')),
                'includes' => is_array($inc) ? array_values(array_filter(array_map(static fn($x) => trim((string)$x), $inc))) : []];
        }
    } catch (Throwable $e) {
        $packages = []; // no packages table here
    }
    $plan = null;
    if (!empty($b['rate_plan_id'])) {
        try {
            $st = $db->prepare("SELECT name, description FROM rate_plans WHERE id = ? LIMIT 1");
            $st->execute([(int)$b['rate_plan_id']]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if ($p) {
                $plan = array_filter(['name' => (string)$p['name'], 'about' => trim((string)($p['description'] ?? ''))], static fn($v) => $v !== '');
            }
        } catch (Throwable $e) {
            $plan = null;
        }
    }
    if ($plan === null && trim((string)($b['rate_plan_label'] ?? '')) !== '') {
        $plan = ['name' => trim((string)$b['rate_plan_label'])];
    }
    $extras = array_values(array_filter(array_map('trim', explode(',', (string)($b['room_amenities'] ?? '')))));
    $from = [];
    foreach ($packages as $p) {
        foreach (array_merge([$p['package']], $p['includes']) as $line) {
            if (rh_ai_mentions_breakfast($line)) {
                $from[] = 'package "' . $p['package'] . '"' . ($line !== $p['package'] ? ': ' . $line : '');
                break;
            }
        }
    }
    if ($plan && rh_ai_mentions_breakfast(implode(' ', $plan))) {
        $from[] = 'rate plan "' . $plan['name'] . '"' . (!empty($plan['about']) && rh_ai_mentions_breakfast($plan['about']) ? ': ' . $plan['about'] : '');
    }
    foreach ($extras as $x) {
        if (rh_ai_mentions_breakfast($x)) {
            $from[] = 'room extras: ' . $x;
            break;
        }
    }
    $out = ['packages' => $packages ?: 'none', 'rate_plan' => $plan ?: 'none', 'room_extras' => $extras ?: 'none listed', 'breakfast' => $from ? true : false];
    if ($from) {
        $out['breakfast_from'] = $from;
    }
    return $out;
}

// ── Focus: the one booking this chat is about ───────────────────────────────

/**
 * The chat's booking in focus and a close match waiting for a yes, from the latest replies (one bounded query).
 * @return array{focus:int,offer:int}
 */
function rh_ai_focus_state(PDO $db, int $conv): array
{
    $out = ['focus' => 0, 'offer' => 0];
    if ($conv <= 0) {
        return $out;
    }
    try {
        $st = $db->prepare("SELECT tool_args_json FROM ai_messages WHERE conversation_id = ? AND role = 'assistant' ORDER BY id DESC LIMIT 20");
        $st->execute([$conv]);
        $first = true;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $j) {
            $m = json_decode((string)$j, true);
            $m = is_array($m) ? $m : [];
            if ($first) {
                $out['offer'] = (int)($m['focus_offer'] ?? 0); // only the last reply's offer is waiting for an answer
                $first = false;
            }
            if (array_key_exists('focus', $m)) {
                $out['focus'] = (int)$m['focus'];
                break;
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/**
 * The booking a model-run turn settled on, from its lookups in order (the last one decides):
 * get_booking or a search with one result -> focus; a search with several or none -> no focus; a search with no
 * exact match and one close match -> offered (focus_offer). @return array [] when no booking lookup ran.
 */
function rh_ai_focus_from_results(array $results): array
{
    $out = [];
    foreach ($results as $r) {
        [$tool, $res] = [$r[0], (array)$r[1]];
        if (!empty($res['error'])) {
            continue;
        }
        if ($tool === 'get_booking' && !empty($res['booking_id'])) {
            $out = ['focus' => (int)$res['booking_id'], 'focus_offer' => 0];
        } elseif ($tool === 'search_bookings' && isset($res['count']) && !isset($res['match'])) {
            $rows = is_array($res['bookings'] ?? null) ? $res['bookings'] : [];
            $close = is_array($res['close_matches'] ?? null) ? $res['close_matches'] : [];
            if ((int)$res['count'] === 1 && !empty($rows[0]['booking_id'])) {
                $out = ['focus' => (int)$rows[0]['booking_id'], 'focus_offer' => 0];
            } else {
                $out = ['focus' => 0, 'focus_offer' => count($close) === 1 ? (int)$close[0]['booking_id'] : 0];
            }
        }
    }
    return $out;
}

/**
 * Check the booking a model asked get_booking for: it must come from this chat (its lookups, a vault token, what the
 * person typed, the booking open on screen, the booking in focus). An invented id is refused; the focus is used if set.
 * @return array{0:?array,1:string} [arguments to run (null = refuse), note for the model]
 */
function rh_ai_booking_args_guard(PDO $db, int $conv, array $args, int $focus, int $pageBooking = 0, string $rawMsg = ''): array
{
    $id = isset($args['booking_id']) && is_numeric($args['booking_id']) ? (int)$args['booking_id'] : 0;
    $ref = trim((string)($args['reference'] ?? ''));
    if ($id <= 0 && $ref === '') {
        return $focus > 0 ? [['booking_id' => $focus], 'No booking was given; this is the booking in focus in this chat.'] : [$args, ''];
    }
    $known = false;
    try {
        if ($id > 0) {
            // the person's own words this turn (before scrubbing: an id after "id" is dropped from the stored copy)
            $known = $id === $focus || $id === $pageBooking || ($rawMsg !== '' && preg_match('/(?<!\d)' . $id . '(?!\d)/', $rawMsg));
            if (!$known) {
                $st = $db->prepare("SELECT role, content, tool_args_json, tool_result_json FROM ai_messages WHERE conversation_id = ? AND role IN ('user', 'tool') ORDER BY id DESC LIMIT 60");
                $st->execute([$conv]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                    if ($m['role'] === 'tool' && preg_match('/"booking_id":' . $id . '(?!\d)/', (string)$m['tool_result_json'])) {
                        $known = true;
                    } elseif ($m['role'] === 'user') {
                        $ua = json_decode((string)$m['tool_args_json'], true);
                        $known = (is_array($ua) && (int)($ua['booking_id'] ?? 0) === $id)
                            || (bool)preg_match('/\b(booking|id|no\.?|number|#)\s*(id\s*)?#?\s*' . $id . '(?!\d)/i', (string)$m['content']);
                    }
                    if ($known) {
                        break;
                    }
                }
            }
        } else {
            $isToken = (bool)preg_match('/^\[BK_\d+\]$/', $ref);
            $real = rh_ai_vault_rehydrate($db, $conv, $ref);
            $v = rh_ai_vault_load($db, $conv);
            $known = ($isToken && $real !== $ref) || isset($v['hash'][rh_ai_vault_hash('BK', $real)]) || ($rawMsg !== '' && mb_stripos($rawMsg, $real) !== false);
            if (!$known) {
                $st = $db->prepare("SELECT 1 FROM ai_messages WHERE conversation_id = ? AND role = 'user' AND content LIKE ? LIMIT 1");
                $st->execute([$conv, '%' . rh_ai_like($real) . '%']);
                $known = (bool)$st->fetchColumn();
            }
        }
    } catch (Throwable $e) {
        $known = false;
    }
    if ($known) {
        return [$args, ''];
    }
    if ($focus > 0) {
        return [['booking_id' => $focus], 'That booking ' . ($id > 0 ? 'id' : 'reference') . ' did not come from this chat, so it was not used. This is the booking in focus in this chat.'];
    }
    return [null, 'That booking ' . ($id > 0 ? 'id' : 'reference') . ' did not come from this chat. Find the booking first with search_bookings (name, email, phone or reference), then use the booking_id it gives.'];
}

/** The line about the booking in focus for the model (volatile part, tokens only); '' when none or not allowed. */
function rh_ai_focus_note(PDO $db, array $user, int $conv, int $focus): string
{
    if ($focus <= 0 || !rh_ai_tool_allowed($user, 'get_booking')) {
        return '';
    }
    try {
        $st = $db->prepare("SELECT booking_reference, guest_name FROM bookings WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$focus]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $b = false;
    }
    if (!$b) {
        return '';
    }
    return 'BOOKING IN FOCUS: booking_id ' . $focus . ' (' . rh_ai_vault_token($db, $conv, 'BK', (string)$b['booking_reference']) . ', ' . rh_ai_vault_token($db, $conv, 'GUEST', (string)$b['guest_name'])
        . '). "They", "the guest" and "the room" mean this booking unless the person names someone else. Its price, payments and balance come from get_booking money, never from a date-range report.';
}

// ── Local answers about one booking (no model, no tokens) ───────────────────

/** A request to change something: never answered by a local read. */
function rh_ai_focus_is_change(string $lower): bool
{
    return (bool)preg_match('/\b(cancel\w*|change|modify|move|amend|extend|shorten|upgrade|downgrade|assign|allocate|send|e-?mail|resend|whatsapp|text|refund\w*|void|charge|add|record|take|mark|set|update|book|reserve|create|delete|remove|apply|edit|note)\b|^\s*(please\s+)?check\b/u', $lower);
}

/** "When is Mr Ntahni coming", "is Mrs Phiri arriving today", "Mr Banda's booking": the name, or null. "Find the booking for ..." stays with the model (its search handles misspelt names too). */
function rh_ai_person_search_intent(string $message): ?string
{
    $m = trim((string)preg_replace('/\s+/u', ' ', $message));
    $m = rtrim($m, " ?.!");
    if ($m === '' || mb_strlen($m) > 90) {
        return null;
    }
    $title = '(?:(mr|mrs|ms|miss|mx|dr|prof|mister|madam)\.?\s+)?';
    $name = '(\p{L}[\p{L}\'\-]+(?:\s\p{L}[\p{L}\'\-]+){0,3}?)';
    $res = [
        '/^(?:please\s+)?when\s+(?:is|are|does|do|will|\'s)\s+' . $title . $name . '\s+(?:coming(?:\s+in)?|arriving|due(?:\s+in)?|getting\s+(?:here|in)|arrive|come|get\s+here|checking\s+in|check\s+in|leaving|leave|checking\s+out|check\s+out|departing|depart)(?:\s+(?:here|today|tomorrow))?$/iu',
        '/^(?:is|has)\s+' . $title . $name . '\s+(?:arrived|checked\s+in|checked\s+out|here|in\s+house|coming|arriving|booked|staying|still\s+here)(?:\s+(?:yet|today|tonight))?$/iu',
        '/^' . $title . $name . '(?:\'s|s\')\s+(?:booking|reservation|stay|arrival|dates)$/iu',
    ];
    foreach ($res as $re) {
        if (!preg_match($re, $m, $g)) {
            continue;
        }
        $hasTitle = ($g[1] ?? '') !== '';
        $nm = trim((string)($g[2] ?? ''));
        $words = preg_split('/\s+/u', mb_strtolower($nm));
        $generic = '/^(they|them|their|he|him|his|she|her|it|we|you|i|the|a|an|our|this|that|guest|guests|everyone|anyone|someone|somebody|people|group|room|rooms|staff|booking|bookings|next|first|last|bus|shuttle|taxi|driver|delivery|breakfast|lunch|dinner|restaurant|bar|kitchen|gym|pool|order|food)$/u';
        foreach ($words as $w) {
            if (preg_match($generic, $w)) {
                return null;
            }
        }
        // Without a title the name must be written as a name (capital letter), so "when is the bus coming" is not one
        if (!$hasTitle && !preg_match('/^\p{Lu}/u', $nm)) {
            return null;
        }
        return $nm;
    }
    return null;
}

/** What a follow-up asks about the booking in focus: money | included | when, or null. */
function rh_ai_focus_kind(string $lower): ?string
{
    if (preg_match('/\b(revenue|occupancy|adr|revpar|report|this month|last month|this week|last week|between|per night|a night|nights?|adults?|children|kids|quote|rates?|all bookings|every booking)\b/u', $lower)) {
        return null; // a period, a price list or a stay to price: not one booking's bill
    }
    if (preg_match('/\b(how much|cost|costs|price|paid|pay|payments?|total|bill|balance|owe|owes|owing|outstanding|amount|charged|deposit)\b/u', $lower)) {
        return 'money';
    }
    if (rh_ai_mentions_breakfast($lower) || preg_match('/\b(included|include|includes|inclusions?|packages?|meal plan|half board|full board|b ?& ?b|bed and breakfast)\b/u', $lower)) {
        return 'included';
    }
    if (preg_match('/^(when|what time|what date|which day)\b.*\b(coming|arriv\w*|due|getting (here|in)|leav\w*|check(ing)? (in|out)|depart\w*|come|go(ing)?)\b|\b(their|his|her) dates\b|\bhow long (are|is) (they|he|she)\b/u', $lower)) {
        return 'when';
    }
    return null;
}

/** "yes" to "Did you mean ...?" */
function rh_ai_is_yes(string $lower): bool
{
    return (bool)preg_match('/^(yes|yeah|yep|yup|yes please|correct|right|exactly|that\'?s (him|her|them|it|the one|right|correct)|it\'?s (him|her|them)|(yes|yeah|yep),? (that\'?s|it\'?s) (him|her|them|it|the one|right))[!.\s]*$/u', trim($lower));
}

/** "no" to "Did you mean ...?" */
function rh_ai_is_no(string $lower): bool
{
    return (bool)preg_match('/^(no|nope|not (him|her|them|that one|that guest)|no,? (not (him|her|them|that one)|that\'?s not (him|her|them|it|the one)|wrong (person|guest|one))|wrong (person|guest|one))[!.\s]*$/u', trim($lower));
}

/** Names the person wrote after a title ("Mr Nthanis" -> nthanis) and the names behind GUEST tokens in the question. */
function rh_ai_focus_named(PDO $db, int $conv, string $message, string $tok): array
{
    $names = [];
    if (preg_match_all('/\b(?:mr|mrs|ms|miss|mx|dr|prof|mister|madam)\.?\s+(\p{L}[\p{L}\'\-]+)/iu', $message, $mm)) {
        foreach ($mm[1] as $n) {
            $names[] = (string)preg_replace('/\'s?$/u', '', $n);
        }
    }
    if (preg_match_all('/\[GUEST_\d+\]/', $tok, $tm)) {
        foreach ($tm[0] as $t) {
            $v = rh_ai_vault_rehydrate($db, $conv, $t);
            if ($v !== $t) {
                $names[] = $v;
            }
        }
    }
    return $names;
}

/** Does a name the person wrote fit the booking's guest? (close, so "Mr Nthanis" fits "Charles Nthani") */
function rh_ai_name_fits(string $named, array $raw): bool
{
    $min = min(100, rh_ai_num('ai_close_match_min_pct')) / 100;
    $asked = rh_ai_name_words($named);
    foreach ([(string)($raw['guest_name'] ?? ''), (string)($raw['room_guest_name'] ?? '')] as $n) {
        if ($n !== '' && rh_ai_name_closeness($asked, $n)[0] >= $min) {
            return true;
        }
    }
    return false;
}

/** "[GUEST_1] ([BK_1]) · Deluxe Room · room 12 · arrives Fri 30 Oct 2026, leaves Sun 1 Nov 2026 (2 nights) · confirmed" (de-identified); $who replaces the name part. */
function rh_ai_booking_line(array $r, string $who = ''): string
{
    $nights = (int)($r['nights'] ?? 0);
    $d = static fn($x) => $x ? date('D j M Y', strtotime((string)$x)) : '';
    return ($who !== '' ? $who : '**' . ($r['guest_name'] ?? '') . '** (' . ($r['reference'] ?? '') . ')') . (!empty($r['room_type']) ? ' · ' . $r['room_type'] : '')
        . (!empty($r['room_number']) ? ' · room ' . $r['room_number'] : '')
        . (!empty($r['check_in']) ? ' · arrives ' . $d($r['check_in']) . ', leaves ' . $d($r['check_out']) . ($nights > 0 ? ' (' . $nights . ' night' . ($nights === 1 ? '' : 's') . ')' : '') : '')
        . (!empty($r['status']) ? ' · ' . str_replace('-', ' ', (string)$r['status']) : '');
}

/** The booking's own bill in plain words (the get_booking money, the same figures as the booking screen). */
function rh_ai_booking_money_text(array $r): string
{
    $m = is_array($r['money'] ?? null) ? $r['money'] : [];
    if (!isset($m['total'])) {
        return 'The bill for this booking could not be read just now. Open the booking to see it.';
    }
    $cur = (string)($r['currency'] ?? '');
    $owed = (float)$m['balance_due'];
    return '**Total:** ' . rh_ai_fmt_cur($cur, $m['total']) . ' · **Paid:** ' . rh_ai_fmt_cur($cur, $m['paid']) . ' · **Balance due:** ' . rh_ai_fmt_cur($cur, $owed)
        . ($owed <= BALANCE_TOLERANCE ? ' (nothing is owed)' : '') . "\n\nThis is the booking's own bill, the same figures as the booking screen.";
}

/** What's included / is breakfast included, from the get_booking result. $kitchen: the kitchen hours line ('' = not set). */
function rh_ai_booking_included_text(array $r, bool $breakfastAsked, string $kitchen = ''): string
{
    $inc = is_array($r['included'] ?? null) ? $r['included'] : [];
    $who = rh_ai_booking_line($r);
    $pk = is_array($inc['packages'] ?? null) ? $inc['packages'] : [];
    $plan = is_array($inc['rate_plan'] ?? null) ? $inc['rate_plan'] : null;
    $extras = is_array($inc['room_extras'] ?? null) ? $inc['room_extras'] : [];
    $pkText = $pk ? implode('; ', array_map(static fn($p) => $p['package'] . (!empty($p['includes']) ? ' (' . implode(', ', (array)$p['includes']) . ')' : ''), $pk)) : 'none';
    $planText = $plan ? ($plan['name'] ?? '') . (!empty($plan['about']) ? ' (' . $plan['about'] . ')' : '') : 'none';
    $lines = ['Packages: ' . $pkText, 'Rate plan: ' . $planText, 'Room extras' . (!empty($r['room_type']) ? ' (' . $r['room_type'] . ')' : '') . ': ' . ($extras ? implode(', ', $extras) : 'none listed')];
    if ($breakfastAsked) {
        $out = !empty($inc['breakfast'])
            ? 'Yes - breakfast is included on this booking, from the ' . implode('; the ', (array)($inc['breakfast_from'] ?? [])) . '.'
            : 'Nothing on this booking includes breakfast (no package or rate plan with breakfast; the room\'s listed extras don\'t mention it).';
        $out .= "\n\n" . $who . rh_ai_local_lines($lines);
    } else {
        $out = '**What\'s included** on ' . $who . ':' . rh_ai_local_lines($lines);
    }
    if ($kitchen !== '') {
        $out .= "\n\nKitchen hours: " . $kitchen . '.';
    }
    return $out;
}

/** The kitchen hours from hotel_info ('' when the kitchen is not offered or its hours are not set). */
function rh_ai_kitchen_hours(PDO $db, array $user, int $conv): string
{
    $h = rh_ai_run_tool($db, $user, $conv, 'hotel_info', ['topic' => 'hours']);
    $k = (string)($h['result']['hours']['kitchen'] ?? '');
    return $h['ok'] && $k !== '' && stripos($k, 'not set') === false ? $k : '';
}

/**
 * Local answers about one booking (owner fault 2026-10-29), checked before the model:
 *  - a person search ("When is Mr Ntahni coming"): one booking -> its line (focus); several -> which one; none ->
 *    "Did you mean ...?" with the close names (one close name is offered, never assumed);
 *  - "yes" / "no" to that offer;
 *  - money, what's included / breakfast, and dates for the booking in focus ("how much was the room", "are they
 *    getting free breakfast", "Mr Nthanis room" when that name fits the booking).
 * Never a change request. $state: rh_ai_focus_state() for this chat. @return array|null ['text','links','chips','focus'?,'focus_offer'?,'tools']
 */
function rh_ai_focus_local(PDO $db, array $user, int $conv, string $message, string $tok, array $state): ?array
{
    $lower = mb_strtolower(trim($message));
    if ($lower === '' || mb_strlen($lower) > 160 || rh_ai_focus_is_change($lower)) {
        return null;
    }
    // 1. A person search
    if (($name = rh_ai_person_search_intent($message)) !== null) {
        $r = rh_ai_run_tool($db, $user, $conv, 'search_bookings', ['query' => $name]);
        if (!$r['ok']) {
            return null;
        }
        rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => 'search_bookings', 'args' => rh_ai_vault_protect($db, $conv, ['query' => $name]), 'result' => $r['json'], 'agent' => 'local']);
        $res = (array)$r['result'];
        $rows = is_array($res['bookings'] ?? null) ? $res['bookings'] : [];
        $asked = rh_ai_scrub_outbound($db, $conv, $name);
        $chips1 = ['How much is their booking?', 'What is included?'];
        if ((int)($res['count'] ?? 0) === 1 && $rows) {
            $line = rh_ai_booking_line($rows[0]);
            if (($res['close_match'] ?? '') === 'clear') {
                // One clearly closest name (owner 2026-10-29): use it, and show the name as on the booking so staff see who was meant
                $rawRow = (array)(((array)$r['raw'])['bookings'][0] ?? []);
                $word = rh_ai_closest_name_word($name, (string)($rawRow['guest_name'] ?? ''));
                $title = preg_match('/\b(mr|mrs|ms|miss|mx|dr|prof)\.?\s+' . preg_quote((string)explode(' ', $name)[0], '/') . '/iu', $message, $tm) ? ucfirst(mb_strtolower($tm[1])) . ' ' : '';
                $label = $word !== '' ? rh_ai_scrub_outbound($db, $conv, $title . $word) : '';
                if ($label !== '') { // "Mr Nthani (Charles Nthani, RBH...) · arrives ..."
                    $line = rh_ai_booking_line($rows[0], '**' . $label . '** (' . $rows[0]['guest_name'] . ', ' . $rows[0]['reference'] . ')');
                }
            }
            return ['text' => $line, 'links' => $r['links'], 'chips' => $chips1, 'focus' => (int)$rows[0]['booking_id'], 'focus_offer' => 0, 'tools' => ['search_bookings']];
        }
        if ($rows) {
            $lines = array_map('rh_ai_booking_line', $rows);
            return ['text' => (int)$res['count'] . ' bookings match "' . $asked . '" - which one?' . rh_ai_local_lines($lines), 'links' => $r['links'], 'chips' => [], 'focus' => 0, 'focus_offer' => 0, 'tools' => ['search_bookings']];
        }
        $close = is_array($res['close_matches'] ?? null) ? $res['close_matches'] : [];
        if ($close) {
            // Several plausible names, or only a weak one: a short clarifying question listing them (never a guess)
            $opts = array_map('rh_ai_booking_option', $close);
            $last = array_pop($opts);
            return ['text' => 'No booking is under exactly "' . $asked . '". Do you mean ' . ($opts ? implode(', ', $opts) . ' or ' : '') . $last . '?',
                'links' => $r['links'], 'chips' => count($close) === 1 ? ['Yes, that\'s them'] : [],
                'focus' => 0, 'focus_offer' => count($close) === 1 ? (int)$close[0]['booking_id'] : 0, 'tools' => ['search_bookings']];
        }
        // No plausible match: say so, with the total in the system and the latest bookings (a miss is not "none")
        $total = (int)($res['total_in_system'] ?? 0);
        $latest = is_array($res['latest_bookings'] ?? null) ? $res['latest_bookings'] : [];
        return ['text' => 'No booking matches "' . $asked . '", and no guest name is close to it. '
            . ($total > 0 ? 'There are ' . $total . ' bookings in the system' . ($latest ? '. The latest:' . rh_ai_local_lines(array_map('rh_ai_booking_line', $latest)) : '.') : 'There are no bookings in the system yet.'),
            'links' => $r['links'], 'chips' => [], 'focus' => 0, 'focus_offer' => 0, 'tools' => ['search_bookings']];
    }
    // 2. The answer to "Did you mean ...?"
    if ($state['offer'] > 0 && rh_ai_is_no($lower)) {
        return ['text' => 'OK - which guest? Give me their name as it is on the booking, an email, a phone number or the booking reference.', 'links' => [], 'chips' => [], 'focus' => 0, 'focus_offer' => 0, 'tools' => []];
    }
    $yes = $state['offer'] > 0 && rh_ai_is_yes($lower);
    $kind = $yes ? 'yes' : rh_ai_focus_kind($lower);
    if ($kind === null) {
        return null;
    }
    $target = $yes ? $state['offer'] : ($state['focus'] > 0 ? $state['focus'] : $state['offer']);
    if ($target <= 0) {
        return null;
    }
    $r = rh_ai_run_tool($db, $user, $conv, 'get_booking', ['booking_id' => $target]);
    if (!$r['ok']) {
        return null;
    }
    $res = (array)$r['result'];
    $raw = (array)$r['raw'];
    $named = rh_ai_focus_named($db, $conv, $message, $tok);
    $refers = (bool)preg_match('/\b(they|them|their|theirs|he|him|his|she|her|hers|the guest|this guest|that guest|the room|their room|his room|her room|the booking|this booking|that booking|their booking|the stay|this stay|that one)\b/u', $lower);
    $fits = false;
    foreach ($named as $n) {
        if (!rh_ai_name_fits($n, $raw)) {
            return null; // the person named someone else: not the booking in focus
        }
        $fits = true;
    }
    $offered = !$yes && $state['focus'] <= 0; // only a close match was offered so far
    if (!$yes && !$fits && !$refers && ($offered || $kind !== 'included')) {
        return null; // nothing ties the question to this booking
    }
    rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => 'get_booking', 'args' => ['booking_id' => $target], 'result' => $r['json'], 'agent' => 'local']);
    $lead = $offered && !$fits ? 'Taking ' . '**' . ($res['guest_name'] ?? '') . '** (' . ($res['reference'] ?? '') . ') as the guest you meant. ' : '';
    if ($kind === 'yes') {
        $text = 'OK - ' . rh_ai_booking_line($res) . '.';
        $chips = ['How much is their booking?', 'What is included?'];
    } elseif ($kind === 'money') {
        $text = $lead . rh_ai_booking_line($res) . "\n\n" . rh_ai_booking_money_text($res);
        $chips = ['What is included?'];
    } elseif ($kind === 'included') {
        $bf = rh_ai_mentions_breakfast($lower);
        $text = $lead . rh_ai_booking_included_text($res, $bf, $bf ? rh_ai_kitchen_hours($db, $user, $conv) : '');
        $chips = ['How much is their booking?'];
    } else {
        $text = $lead . rh_ai_booking_line($res) . '.';
        $chips = ['How much is their booking?', 'What is included?'];
    }
    return ['text' => $text, 'links' => $r['links'], 'chips' => $chips, 'focus' => $target, 'focus_offer' => 0, 'tools' => ['get_booking']];
}
