<?php

/**
 * Assistant privacy vault (.claude/AI_ASSISTANT_PLAN.md "Local-first data").
 *
 * The cloud model is a reasoning engine only. Before anything leaves the hotel:
 *  - every guest name, email, phone, booking reference and staff name in a tool result or in the
 *    staff member's own message is swapped for a token ([GUEST_7], [EMAIL_7], [PHONE_2], [BK_31],
 *    [STAFF_2]); the real value is kept in ai_vault on this server, per conversation;
 *  - card numbers, ID / passport numbers and addresses are dropped, never tokenised;
 *  - the reply comes back in tokens and is turned into real values here, before the panel shows it;
 *  - a last safety pass replaces any known real value still present in the outgoing payload.
 * Tokens the model sends back as tool arguments are turned into real values before the tool runs.
 */

require_once __DIR__ . '/ai-core.php';

/** Token kinds and the field names whose values they replace in tool results. */
function rh_ai_vault_field_kinds(): array
{
    return [
        'guest_name' => 'GUEST', 'room_guest_name' => 'GUEST', 'guest' => 'GUEST', 'contact_person' => 'GUEST',
        'guest_email' => 'EMAIL', 'email' => 'EMAIL',
        'guest_phone' => 'PHONE', 'phone' => 'PHONE',
        'booking_reference' => 'BK', 'reference' => 'BK',
        'staff' => 'STAFF', 'staff_name' => 'STAFF', 'full_name' => 'STAFF', 'username' => 'STAFF', 'assigned_to_name' => 'STAFF',
    ];
}

/** Field names whose values are dropped outright (never tokenised, never sent). */
function rh_ai_vault_drop_fields(): array
{
    return ['guest_address', 'address', 'id_number', 'passport', 'passport_number', 'national_id', 'card_number', 'card', 'ip', 'password', 'password_hash', 'api_key', 'api_key_enc'];
}

/** Free-text fields written by guests or staff: scrubbed, and marked as untrusted data. */
function rh_ai_vault_free_text_fields(): array
{
    return ['special_requests', 'notes', 'note', 'admin_notes', 'message', 'comment', 'housekeeping_notes', 'description', 'tentative_notes'];
}

/** Per-request memory of each conversation's vault: [conv => ['tok' => [token => value], 'hash' => [hash => token]]]. */
function &rh_ai_vault_memo(): array
{
    static $memo = [];
    return $memo;
}

function rh_ai_vault_hash(string $kind, string $value): string
{
    return hash('sha256', $kind . '|' . mb_strtolower(trim($value)));
}

/** Load a conversation's vault once per request. */
function rh_ai_vault_load(PDO $db, int $conv): array
{
    $memo = &rh_ai_vault_memo();
    if (!isset($memo[$conv])) {
        $memo[$conv] = ['tok' => [], 'hash' => []];
        if ($conv > 0) {
            try {
                $st = $db->prepare("SELECT kind, num, value_text, value_hash FROM ai_vault WHERE conversation_id = ?");
                $st->execute([$conv]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $t = '[' . $r['kind'] . '_' . (int)$r['num'] . ']';
                    $memo[$conv]['tok'][$t] = (string)$r['value_text'];
                    $memo[$conv]['hash'][(string)$r['value_hash']] = $t;
                }
            } catch (Throwable $e) {
                // empty vault: values simply get new tokens
            }
        }
    }
    return $memo[$conv];
}

/** Forget the per-request copy (tests, and after a rollback). */
function rh_ai_vault_forget(?int $conv = null): void
{
    $memo = &rh_ai_vault_memo();
    if ($conv === null) {
        $memo = [];
    } else {
        unset($memo[$conv]);
    }
}

/** The token for a real value (created on first sight). Empty values stay empty. */
function rh_ai_vault_token(PDO $db, int $conv, string $kind, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = mb_substr($value, 0, 500);
    $kind = strtoupper(preg_replace('/[^A-Z]/i', '', $kind));
    rh_ai_vault_load($db, $conv);
    $memo = &rh_ai_vault_memo();
    $h = rh_ai_vault_hash($kind, $value);
    if (isset($memo[$conv]['hash'][$h])) {
        return $memo[$conv]['hash'][$h];
    }
    $num = 1;
    foreach (array_keys($memo[$conv]['tok']) as $t) {
        if (preg_match('/^\[' . $kind . '_(\d+)\]$/', $t, $m)) {
            $num = max($num, (int)$m[1] + 1);
        }
    }
    for ($try = 0; $try < 5; $try++) {
        try {
            $db->prepare("INSERT INTO ai_vault (conversation_id, kind, num, value_text, value_hash) VALUES (?, ?, ?, ?, ?)")
                ->execute([$conv, $kind, $num, $value, $h]);
            break;
        } catch (Throwable $e) {
            $num++; // another request took that number: take the next
        }
    }
    $t = '[' . $kind . '_' . $num . ']';
    $memo[$conv]['tok'][$t] = $value;
    $memo[$conv]['hash'][$h] = $t;
    return $t;
}

/** Real values back in place of tokens (for display and for tool arguments). Unknown tokens stay. */
function rh_ai_vault_rehydrate(PDO $db, int $conv, string $text): string
{
    if (strpos($text, '[') === false) {
        return $text;
    }
    $v = rh_ai_vault_load($db, $conv);
    return (string)preg_replace_callback('/\[(GUEST|EMAIL|PHONE|BK|STAFF)_(\d+)\]/', static function ($m) use ($v) {
        return $v['tok'][$m[0]] ?? $m[0];
    }, $text);
}

/** Tool arguments from the model: every token inside a string becomes the real value. */
function rh_ai_vault_resolve_args(PDO $db, int $conv, array $args): array
{
    foreach ($args as $k => $v) {
        if (is_string($v)) {
            $args[$k] = rh_ai_vault_rehydrate($db, $conv, $v);
        } elseif (is_array($v)) {
            $args[$k] = rh_ai_vault_resolve_args($db, $conv, $v);
        }
    }
    return $args;
}

/** Luhn check for card-number-looking digit runs. */
function rh_ai_vault_luhn(string $digits): bool
{
    $sum = 0;
    $alt = false;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $n = (int)$digits[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
        $alt = !$alt;
    }
    return $sum % 10 === 0;
}

/** Words never treated as a person's name on their own (common English, hotel words, months, days). */
function rh_ai_vault_stopwords(): array
{
    static $s = null;
    if ($s === null) {
        $s = array_fill_keys(explode(' ',
            'the a an and or but if then of to in on at by for with from up out is are was were be been am do does did have has had can could will would should may might must '
            . 'i me my we us our you your he she it they them his her its their this that these those what which who whom whose when where why how all any each every some no not '
            . 'yes ok okay please thanks thank hello hi hey good morning afternoon evening night today tomorrow yesterday tonight week month year next last first new old '
            . 'room rooms suite deluxe standard family double single triple twin king queen executive superior junior presidential view garden pool lake river sun '
            . 'booking bookings guest guests arrival arrivals departure departures check checkin checkout in-house house stay stays night nights rate rates price prices '
            . 'deposit balance paid payment payments invoice receipt breakfast dinner lunch restaurant bar kitchen conference gym hotel lodge reception front desk '
            . 'housekeeping maintenance clean dirty ready available occupied blocked tentative confirmed pending cancelled cancel noshow no-show '
            . 'january february march april may june july august september october november december jan feb mar apr jun jul aug sep sept oct nov dec '
            . 'monday tuesday wednesday thursday friday saturday sunday mon tue wed thu fri sat sun mr mrs ms miss dr sir madam '
            . 'show tell find give list open book move change add make get set see help how much many who'), true);
    }
    return $s;
}

/**
 * Known people from the local database (guests from bookings, staff from admin_users): lowercase
 * full name => kind, and lowercase name part => kind. Loaded once per request.
 */
function rh_ai_vault_people(PDO $db): array
{
    if (isset($GLOBALS['__rh_ai_people']) && is_array($GLOBALS['__rh_ai_people'])) {
        return $GLOBALS['__rh_ai_people'];
    }
    $people = ['full' => [], 'part' => []];
    $add = static function (string $name, string $kind) use (&$people): void {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 3) {
            return;
        }
        $l = mb_strtolower($name);
        if (!isset($people['full'][$l]) || $kind === 'STAFF') {
            $people['full'][$l] = $kind;
        }
        foreach (preg_split('/[\s,]+/u', $l) as $part) {
            $part = trim($part, ".'-");
            if (mb_strlen($part) >= 3 && !isset($people['part'][$part])) {
                $people['part'][$part] = $kind;
            }
        }
    };
    try {
        foreach ($db->query("SELECT full_name, username FROM admin_users")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add((string)$r['full_name'], 'STAFF');
        }
        foreach ($db->query("SELECT DISTINCT guest_name FROM bookings WHERE guest_name <> '' ORDER BY id DESC LIMIT 20000")->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $add((string)$n, 'GUEST');
        }
        foreach ($db->query("SELECT DISTINCT room_guest_name FROM bookings WHERE room_guest_name IS NOT NULL AND room_guest_name <> '' ORDER BY id DESC LIMIT 5000")->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $add((string)$n, 'GUEST');
        }
    } catch (Throwable $e) {
        // patterns below still apply
    }
    $stop = rh_ai_vault_stopwords();
    try { // words of room type names and the hotel's own name are never treated as a person
        $names = $db->query("SELECT name FROM rooms")->fetchAll(PDO::FETCH_COLUMN);
        $names[] = function_exists('getSetting') ? (string)getSetting('site_name', '') : '';
        foreach ($names as $n) {
            foreach (preg_split('/[\s,\-]+/u', mb_strtolower((string)$n)) as $w) {
                if ($w !== '') {
                    $stop[$w] = true;
                }
            }
        }
    } catch (Throwable $e) {
    }
    foreach (array_keys($people['part']) as $p) {
        if (isset($stop[$p])) {
            unset($people['part'][$p]);
        }
    }
    return $GLOBALS['__rh_ai_people'] = $people;
}

/** Forget the cached list of people (tests that add a guest inside a transaction). */
function rh_ai_vault_people_reset(): void
{
    unset($GLOBALS['__rh_ai_people']);
}

/**
 * Scrub free text before it leaves (the staff member's message, guest-written notes, summaries):
 * cards, ID/passport numbers dropped; emails, phones, booking references and known people tokenised;
 * any value already in this conversation's vault replaced by its token.
 */
function rh_ai_scrub_outbound(PDO $db, int $conv, string $text): string
{
    if ($text === '') {
        return '';
    }
    // Card numbers (13-19 digits, spaces/dashes allowed, Luhn-valid): dropped
    $text = (string)preg_replace_callback('/(?<![\w])(?:\d[ \-]?){12,18}\d(?![\w])/', static function ($m) {
        $d = preg_replace('/\D/', '', $m[0]);
        return (strlen($d) >= 13 && strlen($d) <= 19 && rh_ai_vault_luhn($d)) ? '[card number removed]' : $m[0];
    }, $text);
    // ID / passport / licence numbers after their label: dropped
    $text = (string)preg_replace('/\b(passport|national\s+id|id\s+card|id|identity|licen[cs]e)(\s*(no\.?|number|num|#))?\s*[:\-]?\s*[A-Z0-9][A-Z0-9\-]{4,}\b/i', '$1 [number removed]', $text);
    // Emails
    $text = (string)preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', static function ($m) use ($db, $conv) {
        return rh_ai_vault_token($db, $conv, 'EMAIL', $m[0]);
    }, $text);
    // Booking references (prefix letters + year + digits, e.g. RBH2026227892)
    $text = (string)preg_replace_callback('/\b[A-Z]{2,6}20\d{2}\d{4,8}\b/', static function ($m) use ($db, $conv) {
        return rh_ai_vault_token($db, $conv, 'BK', $m[0]);
    }, $text);
    // Phone numbers: start with + or 0, or grouped digits; 9-15 digits
    $text = (string)preg_replace_callback('/(?<![\w.\/\-])(\+?\(?\d[\d\s().\-]{7,18}\d)(?![\w\/]|\.\d)/', static function ($m) use ($db, $conv) {
        $raw = $m[1];
        $d = preg_replace('/\D/', '', $raw);
        if (strlen($d) < 9 || strlen($d) > 15) {
            return $m[0];
        }
        $grouped = (bool)preg_match('/[\s().\-]/', trim($raw));
        if ($raw[0] !== '+' && $raw[0] !== '0' && !($grouped && !preg_match('/^\d{4}-\d{2}-\d{2}/', $raw))) {
            return $m[0];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            return $m[0]; // a date
        }
        return rh_ai_vault_token($db, $conv, 'PHONE', trim($raw));
    }, $text);
    // Values already known in this conversation (longest first)
    $v = rh_ai_vault_load($db, $conv);
    $vals = $v['tok'];
    uasort($vals, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach ($vals as $tok => $val) {
        if (mb_strlen($val) >= 3) {
            $text = (string)preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($val, '/') . '(?![\p{L}\p{N}])/iu', $tok, $text);
        }
    }
    // People from the local database: full names in any case, single name parts when Capitalised
    $people = rh_ai_vault_people($db);
    if ($people['full']) {
        if (preg_match_all('/\p{L}[\p{L}\'\-]*(?:\s+\p{L}[\p{L}\'\-]*){1,3}/u', $text, $mm)) {
            // try every 2-4 word window, longest first
            $words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            $wordIdx = [];
            foreach ($words as $i => $w) {
                if (!preg_match('/^\s+$/u', $w)) {
                    $wordIdx[] = $i;
                }
            }
            $n = count($wordIdx);
            $replace = [];
            for ($len = 4; $len >= 2; $len--) {
                for ($s = 0; $s + $len <= $n; $s++) {
                    $parts = [];
                    for ($k = 0; $k < $len; $k++) {
                        $parts[] = trim($words[$wordIdx[$s + $k]], ".,;:!?\"()");
                    }
                    $cand = implode(' ', $parts);
                    $kind = $people['full'][mb_strtolower($cand)] ?? null;
                    if ($kind !== null) {
                        $replace[$cand] = $kind;
                    }
                }
            }
            uksort($replace, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            foreach ($replace as $cand => $kind) {
                $tok = rh_ai_vault_token($db, $conv, $kind, $cand);
                $text = (string)preg_replace('/(?<![\p{L}])' . preg_quote($cand, '/') . '(?![\p{L}])/iu', $tok, $text);
            }
        }
    }
    if ($people['part']) {
        $text = (string)preg_replace_callback('/(?<![\p{L}\[_])(\p{Lu}[\p{Ll}\'\-]{2,})(?![\p{L}\]])/u', static function ($m) use ($db, $conv, $people) {
            $kind = $people['part'][mb_strtolower($m[1])] ?? null;
            return $kind === null ? $m[0] : rh_ai_vault_token($db, $conv, $kind, $m[1]);
        }, $text);
    }
    return $text;
}

/**
 * De-identify a tool result before it is sent: PII fields become tokens, drop-fields vanish,
 * free text is scrubbed. Returns the same structure.
 */
function rh_ai_vault_protect(PDO $db, int $conv, $data, string $field = '')
{
    $kinds = rh_ai_vault_field_kinds();
    if (is_array($data)) {
        $out = [];
        $drop = rh_ai_vault_drop_fields();
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), $drop, true)) {
                continue;
            }
            $out[$k] = rh_ai_vault_protect($db, $conv, $v, is_string($k) ? strtolower($k) : $field);
        }
        return $out;
    }
    if (!is_string($data) || $data === '') {
        return $data;
    }
    if (isset($kinds[$field])) {
        return rh_ai_vault_token($db, $conv, $kinds[$field], $data);
    }
    return rh_ai_scrub_outbound($db, $conv, $data);
}

/**
 * Last safety pass over a whole outgoing request: any real value of this conversation's vault still
 * present in a string is replaced by its token. Returns the cleaned request.
 */
function rh_ai_vault_final_pass(PDO $db, int $conv, array $req): array
{
    $v = rh_ai_vault_load($db, $conv);
    if (!$v['tok']) {
        return $req;
    }
    $vals = array_filter($v['tok'], static fn($s) => mb_strlen($s) >= 3);
    uasort($vals, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $walk = static function ($x) use (&$walk, $vals) {
        if (is_array($x)) {
            foreach ($x as $k => $y) {
                if ($k === 'key' || $k === 'provider' || $k === 'model') {
                    continue;
                }
                $x[$k] = $walk($y);
            }
            return $x;
        }
        if (!is_string($x) || $x === '') {
            return $x;
        }
        foreach ($vals as $tok => $val) {
            if (stripos($x, $val) !== false) {
                $x = (string)preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($val, '/') . '(?![\p{L}\p{N}])/iu', $tok, $x);
            }
        }
        return $x;
    };
    return $walk($req);
}
