<?php

/**
 * Every claim checked before the answer shows (owner 2026-10-29: "Check every claim before replying"; "I need it to be
 * flawless with every answer it faces").
 *
 * One claim checker, run on every model-written reply just before the last guard (local answers are built from the
 * data already; the tests run them through it as a self-test). Local PHP only: no model call, no tokens.
 *  1. EXTRACT the claims: money (any currency format, "712k"), counts (digits and number words), percentages, dates
 *     (ISO, "Thu 29 Oct", today / tonight / tomorrow / yesterday from the hotel's own today), weekdays, times, booking
 *     and order references, privacy tokens, names, room numbers and durations ("2 nights").
 *  2. SUPPORT: a claim stands when this turn's evidence holds it (tool results, the context sent, the booking in focus,
 *     earlier lookups still in the chat, the person's own message) or it follows from it: a sum or difference of two
 *     figures, the count of a list, the nights between two dates, a percentage of two figures, rounding (money within
 *     BALANCE_TOLERANCE or the shown precision, percentages within a settings tolerance), a weekday of a date, a number
 *     word. Names: only a name in the evidence, or the real value of a privacy token that is in the evidence.
 *     The reply must also agree with itself ("1 of 11" beside a list of 2; parts that do not add up to their total).
 *  3. CORRECT, cheapest first, never another model call here: (a) drop a sentence that is not the core answer; (b) put
 *     the evidence value in when it is plainly the one meant (same kind, same label, one candidate); (c) rebuild the
 *     reply from the lookups' own local wording; (d) only then the caller asks the Primary model again (counted).
 *     A claim found unsupported is never shown, and no hedging is added.
 *  4. LEARN: each correction is an incident 'claim_corrected' (privacy tokens only) feeding the lesson -> regression
 *     question loop; a corrected answer never trains a learned route.
 * A permission refusal is a claim too: it stands only when a real permission check failed (rh_ai_claim_refusal_*).
 * Nothing here writes business data; the only rows written are AI incidents.
 */

require_once __DIR__ . '/ai-core.php';

// ── Settings ────────────────────────────────────────────────────────────────

function rh_ai_claim_on(): bool
{
    return rh_ai_setting('ai_claim_check_enabled', '1') !== '0';
}

/** Percentage points a shown percentage may differ from the worked one (rounding). */
function rh_ai_claim_pct_tolerance(): float
{
    $v = rh_ai_setting('ai_claim_pct_tolerance', (string)(rh_ai_setting_defaults()['ai_claim_pct_tolerance'] ?? '0.5'));
    return is_numeric($v) && (float)$v >= 0 ? (float)$v : 0.5;
}

// ── Words ───────────────────────────────────────────────────────────────────

function rh_ai_claim_number_words(): array
{
    static $w = null;
    if ($w === null) {
        $w = ['zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
            'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
            'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
            'hundred' => 100, 'a hundred' => 100, 'dozen' => 12, 'a dozen' => 12, 'single' => 1, 'a couple of' => 2, 'a pair of' => 2];
    }
    return $w;
}

/** The value of a number word or phrase ("twenty-five", "a dozen"), or null. */
function rh_ai_claim_word_value(string $w): ?float
{
    $w = trim(mb_strtolower($w));
    $map = rh_ai_claim_number_words();
    if (isset($map[$w])) {
        return (float)$map[$w];
    }
    if (preg_match('/^(twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety)[\s-](one|two|three|four|five|six|seven|eight|nine)$/u', $w, $m)) {
        return (float)($map[$m[1]] + $map[$m[2]]);
    }
    return null;
}

function rh_ai_claim_word_re(): string
{
    return '(?:(?:twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety)[\s-](?:one|two|three|four|five|six|seven|eight|nine)|a dozen|a hundred|a couple of|a pair of|zero|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|dozen)';
}

/** What is counted: a number followed by one of these (up to two words between) states data. */
function rh_ai_claim_count_nouns(): string
{
    return 'guests?|bookings?|reservations?|rooms?|room-nights|arrivals?|departures?|people|persons?|orders?|items?|reviews?|members?|memberships?|enquir\w*|inquir\w*|tickets?|jobs?|tasks?|accounts?'
        . '|payments?|refunds?|voids?|stays?|adults?|child|children|kids|infants?|covers|tables?|batches|counts?|no-shows?|cancellations?|messages?|staff|employees?|users?|actions?|sign-ins?|logins?'
        . '|classes|slots?|spaces?|places?|seats?|events?|conferences?|tabs|bottles?|portions?|units?|suppliers?|deliver(?:y|ies)|holds?|matches|results?|invoices?|receipts?|records?|entries|times|visits?|nights?';
}

function rh_ai_claim_months(): array
{
    return ['jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6,
        'jul' => 7, 'july' => 7, 'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9, 'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12];
}

function rh_ai_claim_weekdays(): array
{
    return ['mon' => 1, 'monday' => 1, 'tue' => 2, 'tues' => 2, 'tuesday' => 2, 'wed' => 3, 'wednesday' => 3, 'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4,
        'fri' => 5, 'friday' => 5, 'sat' => 6, 'saturday' => 6, 'sun' => 7, 'sunday' => 7];
}

/** A day and month (year optional: the one nearest the hotel's today) as Y-m-d, or ''. */
function rh_ai_claim_date_ymd(int $day, int $month, ?int $year, string $today): string
{
    if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return '';
    }
    if ($year !== null) {
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : '';
    }
    $ty = (int)substr($today, 0, 4);
    $best = '';
    $bestGap = PHP_INT_MAX;
    foreach ([$ty - 1, $ty, $ty + 1] as $y) {
        if (!checkdate($month, $day, $y)) {
            continue;
        }
        $d = sprintf('%04d-%02d-%02d', $y, $month, $day);
        $gap = abs((int)strtotime($d) - (int)strtotime($today));
        if ($gap < $bestGap) {
            $bestGap = $gap;
            $best = $d;
        }
    }
    return $best;
}

/** "6:30 pm" / "18:00" / "6pm" as HH:MM, or ''. */
function rh_ai_claim_hhmm(int $h, int $m, string $ampm): string
{
    $ampm = strtolower(str_replace('.', '', $ampm));
    if ($ampm === 'pm' && $h < 12) {
        $h += 12;
    } elseif ($ampm === 'am' && $h === 12) {
        $h = 0;
    }
    return $h >= 0 && $h <= 23 && $m >= 0 && $m <= 59 ? sprintf('%02d:%02d', $h, $m) : '';
}

// ── 1. Extract ──────────────────────────────────────────────────────────────

/** Sentence spans of a text: [[start, end], ...] (byte offsets; sentences end at . ! ? or a line break). */
function rh_ai_claim_sentences(string $text): array
{
    $out = [];
    $pos = 0;
    $len = strlen($text);
    if (preg_match_all('/(?<=[.!?])\s+|\n+/u', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$sep, $off]) {
            if ($off > $pos) {
                $out[] = [$pos, $off];
            }
            $pos = $off + strlen($sep);
        }
    }
    if ($pos < $len) {
        $out[] = [$pos, $len];
    }
    return $out;
}

function rh_ai_claim_sentence_of(array $sents, int $off): int
{
    foreach ($sents as $i => [$a, $b]) {
        if ($off >= $a && $off < $b) {
            return $i;
        }
    }
    return max(0, count($sents) - 1);
}

/**
 * The checkable claims of a text. Each: kind (money|count|pct|date|weekday|reldate|time|token|ref|name|room|duration),
 * raw (as written), value, start/end (byte offsets), sent (sentence number), plus kind-specific keys (tol, unit, date,
 * day). Bounded: ai_claim_max_claims at most.
 */
function rh_ai_claim_extract(string $text, ?string $today = null): array
{
    $today = $today ?? rh_ai_today();
    $max = rh_ai_num('ai_claim_max_claims');
    $mask = $text;
    $claims = [];
    $blank = static function (int $off, int $len) use (&$mask): void {
        $mask = substr_replace($mask, str_repeat(' ', $len), $off, $len);
    };
    $add = static function (array $c) use (&$claims, $blank): void {
        $claims[] = $c;
        $blank($c['start'], $c['end'] - $c['start']);
    };
    // Not claims: link targets, file names and addresses, list markers, step / page numbers, "24/7"
    foreach (['/\]\([^)\s]*\)/u', '/https?:\/\/\S+/u', '/\b[\w\-]+\.(?:php|html?)\S*/u', '/^[ \t]*(?:\d{1,2}[.)]|[-*•])[ \t]/mu', '/\b(?:step|page|option|part|phase|section|tip|line|point|no\.)\s+\d{1,2}\b/iu', '/\b24\s*\/\s*7\b/u',
              // examples of what to type ("for example "5 to 7 Nov" or "2 nights from tomorrow"") are not claims
              '/\((?:for example|for instance|e\.g\.|such as)[^)\n]*\)/iu', '/\b(?:for example|for instance|e\.g\.|such as|say|try|ask)\s*[:,]?\s*(?:"[^"\n]*"|“[^”\n]*”)(?:\s*(?:,|or|and)\s*(?:"[^"\n]*"|“[^”\n]*”))*/iu'] as $re) {
        if (preg_match_all($re, $mask, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$s, $o]) {
                $blank($o, strlen($s));
            }
        }
    }
    // Privacy tokens
    if (preg_match_all('/\[(GUEST|EMAIL|PHONE|BK|STAFF)_\d+\]/', $mask, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => [$s, $o]) {
            $add(['kind' => 'token', 'raw' => $s, 'value' => $s, 'type' => $m[1][$i][0], 'start' => $o, 'end' => $o + strlen($s)]);
        }
    }
    // ISO dates (with a time)
    if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2})?)?\b/', $mask, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $g) {
            $d = checkdate((int)$g[2][0], (int)$g[3][0], (int)$g[1][0]) ? $g[1][0] . '-' . $g[2][0] . '-' . $g[3][0] : '';
            if ($d === '') {
                continue;
            }
            $o = (int)$g[0][1];
            $dEnd = (int)$g[3][1] + 2;
            $add(['kind' => 'date', 'raw' => substr($text, $o, $dEnd - $o), 'value' => $d, 'start' => $o, 'end' => $dEnd]);
            if (isset($g[4]) && $g[4][1] >= 0) {
                $tEnd = (int)$g[0][1] + strlen($g[0][0]);
                $add(['kind' => 'time', 'raw' => substr($text, (int)$g[4][1], $tEnd - (int)$g[4][1]), 'value' => $g[4][0] . ':' . $g[5][0], 'start' => (int)$g[4][1], 'end' => $tEnd]);
            }
        }
    }
    // Natural dates, with or without a weekday: "Thu 29 Oct", "29 October 2026", "Oct 29", "Friday, 30th of October"
    $wd = '(?<wd>monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tues|tue|wed|thurs|thur|thu|fri|sat|sun)';
    $mo = '(?<mo>january|february|march|april|may|june|july|august|september|october|november|december|sept|jan|feb|mar|apr|jun|jul|aug|sep|oct|nov|dec)';
    $months = rh_ai_claim_months();
    foreach (['/\b(?:' . $wd . '\.?,?\s+)?(?:the\s+)?(?<d>\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?' . $mo . '\b(?:,?\s+(?<y>\d{4})\b)?/iu',
              '/\b(?:' . $wd . '\.?,?\s+)?' . $mo . '\.?\s+(?<d>\d{1,2})(?:st|nd|rd|th)?\b(?:,?\s+(?<y>\d{4})\b)?/iu'] as $re) {
        if (!preg_match_all($re, $mask, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            continue;
        }
        foreach ($m as $g) {
            $moRaw = (string)$g['mo'][0];
            if (strtolower($moRaw) === 'may' && $moRaw !== 'May') {
                continue; // "may" the verb
            }
            $y = $g['y'][0] !== null ? (int)$g['y'][0] : null;
            $d = rh_ai_claim_date_ymd((int)$g['d'][0], (int)$months[strtolower(rtrim($moRaw, '.'))], $y, $today);
            if ($d === '') {
                continue;
            }
            $o = (int)$g[0][1];
            $end = $o + strlen(rtrim($g[0][0]));
            $dateStart = $g['wd'][0] !== null ? min((int)$g['d'][1], (int)$g['mo'][1]) : $o;
            $add(['kind' => 'date', 'raw' => substr($text, $dateStart, $end - $dateStart), 'value' => $d, 'noyear' => $y === null, 'start' => $dateStart, 'end' => $end]);
            if ($g['wd'][0] !== null) {
                $wo = (int)$g['wd'][1];
                $add(['kind' => 'weekday', 'raw' => $g['wd'][0], 'value' => rh_ai_claim_weekdays()[strtolower($g['wd'][0])], 'date' => $d, 'noyear' => $y === null, 'start' => $wo, 'end' => $wo + strlen($g['wd'][0])]);
            }
        }
    }
    // Today / tonight / tomorrow / yesterday (the hotel's own today)
    if (preg_match_all('/\b(today|tonight|tomorrow|yesterday)\b/iu', $mask, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as [$s, $o]) {
            $w = strtolower($s);
            $d = $w === 'tomorrow' ? date('Y-m-d', strtotime($today . ' +1 day')) : ($w === 'yesterday' ? date('Y-m-d', strtotime($today . ' -1 day')) : $today);
            $add(['kind' => 'reldate', 'raw' => $s, 'value' => $d, 'start' => $o, 'end' => $o + strlen($s)]);
        }
    }
    // Times: "18:00", "6:30 pm", "6pm"
    if (preg_match_all('/(?<![\d:.])([01]?\d|2[0-3]):([0-5]\d)(?::\d{2})?(?:\s?([ap]\.?m\.?))?(?![\d:])/iu', $mask, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
        foreach ($m as $g) {
            $v = rh_ai_claim_hhmm((int)$g[1][0], (int)$g[2][0], (string)($g[3][0] ?? ''));
            if ($v !== '') {
                $add(['kind' => 'time', 'raw' => $g[0][0], 'value' => $v, 'start' => (int)$g[0][1], 'end' => (int)$g[0][1] + strlen($g[0][0])]);
            }
        }
    }
    if (preg_match_all('/(?<![\d:.])(1[0-2]|0?[1-9])\s?([ap]\.?m\.?)(?![a-z])/iu', $mask, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $g) {
            $add(['kind' => 'time', 'raw' => $g[0][0], 'value' => rh_ai_claim_hhmm((int)$g[1][0], 0, $g[2][0]), 'start' => (int)$g[0][1], 'end' => (int)$g[0][1] + strlen($g[0][0])]);
        }
    }
    // References: booking / order / member numbers written as they are ("ORD-20261028-003", "GYM-0012", "LSH2026999999")
    if (preg_match_all('/\b[A-Z]{2,8}-?\d[\dA-Z]*(?:-[\dA-Z]+)*\b/', $mask, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$s, $o]) {
            if (preg_match('/\d{3,}/', $s) || strpos($s, '-') !== false) {
                $add(['kind' => 'ref', 'raw' => $s, 'value' => $s, 'start' => $o, 'end' => $o + strlen($s)]);
            }
        }
    }
    // Money: currency before or after; "712k", "1.2m"
    $cur = 'MWK|MK|USD|US\$|ZAR|GBP|EUR|\$|£|€|K';
    $sym = trim(function_exists('rh_ai_currency') ? rh_ai_currency() : '');
    if ($sym !== '' && mb_strlen($sym) <= 5) {
        $cur = preg_quote($sym, '/') . '|' . $cur;
    }
    $numRe = '(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)';
    $scaleRe = '(?:\s?(k|m|bn|mn|million|thousand)\b)?';
    $money = [];
    if (preg_match_all('/(?<![\w.])(?:' . $cur . ')\s?-?' . $numRe . $scaleRe . '/u', $mask, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
        foreach ($m as $g) {
            $money[] = [$g[0][0], (int)$g[0][1], $g[1][0], (string)($g[2][0] ?? '')];
        }
    }
    foreach ($money as [$raw, $o, $n, $sc]) {
        $add(rh_ai_claim_money($raw, $o, $n, $sc));
    }
    $money = [];
    if (preg_match_all('/(?<![\w.,])' . $numRe . $scaleRe . '\s?(?:MWK|kwacha|dollars|usd|rand)\b/iu', $mask, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
        foreach ($m as $g) {
            $money[] = [$g[0][0], (int)$g[0][1], $g[1][0], (string)($g[2][0] ?? '')];
        }
    }
    if (preg_match_all('/(?<![\w.,])(\d+(?:\.\d+)?)(k|m|bn)\b/u', $mask, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $g) {
            $money[] = [$g[0][0], (int)$g[0][1], $g[1][0], $g[2][0]];
        }
    }
    usort($money, static fn($a, $b) => $a[1] <=> $b[1]);
    $last = -1;
    foreach ($money as [$raw, $o, $n, $sc]) {
        if ($o > $last) {
            $add(rh_ai_claim_money($raw, $o, $n, $sc));
            $last = $o + strlen($raw);
        }
    }
    // Percentages
    if (preg_match_all('/(?<![\w.,])(-?\d+(?:\.\d+)?)\s?(%|percent\b|per cent\b)/iu', $mask, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $g) {
            $dec = strpos($g[1][0], '.') !== false ? strlen(substr(strrchr($g[1][0], '.'), 1)) : 0;
            $add(['kind' => 'pct', 'raw' => $g[0][0], 'value' => abs((float)$g[1][0]), 'tol' => max(rh_ai_claim_pct_tolerance(), 0.5 * 10 ** -$dec), 'start' => (int)$g[0][1], 'end' => (int)$g[0][1] + strlen($g[0][0])]);
        }
    }
    // Durations: "2 nights", "3 days", "2 h 5 min", "45 minutes"
    $nw = rh_ai_claim_word_re();
    if (preg_match_all('/(?<![\w.,])(\d+) h(?:ours?|rs?)? (\d+) min(?:utes?|s)?\b/iu', $mask, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $g) {
            $add(['kind' => 'duration', 'raw' => $g[0][0], 'value' => (float)((int)$g[1][0] * 60 + (int)$g[2][0]), 'unit' => 'minute', 'start' => (int)$g[0][1], 'end' => (int)$g[0][1] + strlen($g[0][0])]);
        }
    }
    if (preg_match_all('/(?<![\w.,])(\d+(?:\.\d+)?|' . $nw . ')[\s-](nights?|days?|weeks?|months?|hours?|hrs?|h|minutes?|mins?|min)\b/iu', $mask, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $g) {
            $v = is_numeric($g[1][0]) ? (float)$g[1][0] : rh_ai_claim_word_value($g[1][0]);
            if ($v === null) {
                continue;
            }
            $u = strtolower($g[2][0]);
            $unit = $u[0] === 'n' ? 'night' : ($u[0] === 'd' ? 'day' : ($u[0] === 'w' ? 'week' : ($u[0] === 'h' ? 'hour' : ($u === 'month' || $u === 'months' ? 'month' : 'minute'))));
            $add(['kind' => 'duration', 'raw' => $g[0][0], 'value' => $v, 'unit' => $unit, 'start' => (int)$g[0][1], 'end' => (int)$g[0][1] + strlen($g[0][0])]);
        }
    }
    // Room numbers: "room 12", "Room GD301"
    if (preg_match_all('/\b(?:room|rm)\.?\s*(?:no\.?\s*|number\s*|#)?([A-Z]{0,3}\d{1,4}[A-Z]?)\b/iu', $mask, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $g) {
            $add(['kind' => 'room', 'raw' => $g[1][0], 'value' => $g[1][0], 'start' => (int)$g[1][1], 'end' => (int)$g[1][1] + strlen($g[1][0])]);
        }
    }
    // Names: after a title, after "booked by" and the like, after a role word, a bold name before "(role)"
    $nm = '(\p{Lu}[\p{L}\'’\-]+(?:\s+\p{Lu}[\p{L}\'’\-]+){0,2})';
    $nameRes = [
        '/\b(?:Mr|Mrs|Ms|Miss|Mx|Dr|Prof|Madam|Sir)\.?\s+' . $nm . '/u',
        '/\b(?:booked|made|created|entered|handled|confirmed|cancelled|checked (?:in|out)|assigned|served|opened|added|recorded|changed|updated|done|approved|signed in|logged in)(?:\s+(?:in|out|up|on))?\s+by\s+' . $nm . '/u',
        '/\b(?:guest|staff member|receptionist|manager|housekeeper|waiter|waitress|chef|cashier|colleague|member)\s+(?:named\s+|called\s+)?' . $nm . '/u',
        '/^[ \t]*(?:[-*•][ \t]*)?\*\*' . $nm . '\*\*\s*\(/mu',
    ];
    $notNames = '/^(?:the|this|that|room|rooms|today|tonight|tomorrow|yesterday|booking|bookings|screen|portal|system|administrator|admin|manager|assistant|reception|front|desk|housekeeping|restaurant|bar|kitchen|check|confirm|open|guest|guests|staff|total|none|no|yes|online|offline|active|occupancy|arrivals?|departures?|in|house|status|deluxe|standard|executive|family|suite|single|double|twin|king|queen|monday|tuesday|wednesday|thursday|friday|saturday|sunday|january|february|march|april|may|june|july|august|september|october|november|december|money|stock|reports?|settings|bookings|payments?|invoices?|i|we|you|they|he|she|it|please|thanks|note|tip|step|account|accounts)$/iu';
    foreach ($nameRes as $re) {
        if (preg_match_all($re, $mask, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $i => [$s, $o]) {
                $words = preg_split('/\s+/u', trim($s));
                while ($words && preg_match($notNames, (string)end($words))) {
                    array_pop($words); // "Mr Banda Room" -> "Banda"
                }
                if (!$words || preg_match($notNames, $words[0])) {
                    continue;
                }
                $name = implode(' ', $words);
                $title = $m[0][$i][1];
                $add(['kind' => 'name', 'raw' => $name, 'value' => $name, 'whole' => substr($text, $title, $o + strlen($name) - $title), 'whole_start' => $title, 'start' => $o, 'end' => $o + strlen($name)]);
            }
        }
    }
    // Counts: "N of M", a number (digits or words) before what it counts, "Label: N"
    $nouns = rh_ai_claim_count_nouns();
    if (preg_match_all('/(?<![\w.,\-\/])(\d{1,3}(?:,\d{3})+|\d+)(?:\*\*)?\s+(?:of|out of)\s+(\d{1,3}(?:,\d{3})+|\d+)\b/u', $mask, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $g) {
            foreach ([1, 2] as $k) {
                $add(['kind' => 'count', 'raw' => $g[$k][0], 'value' => (float)str_replace(',', '', $g[$k][0]), 'start' => (int)$g[$k][1], 'end' => (int)$g[$k][1] + strlen($g[$k][0])]);
            }
        }
    }
    if (preg_match_all('/(?<![\w.,\-\/])(\d{1,3}(?:,\d{3})+|\d+|' . $nw . ')(?=(?:\*\*)?\s+(?:[a-z][\w\-]*\s+){0,2}(?:' . $nouns . ')\b)/iu', $mask, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as [$s, $o]) {
            $v = is_numeric(str_replace(',', '', $s)) ? (float)str_replace(',', '', $s) : rh_ai_claim_word_value($s);
            if ($v !== null && !($s === 'one' && preg_match('/^one\s+(?:of|thing|more|moment|at|by|way|day)\b/iu', substr($mask, $o, 20)))) {
                $add(['kind' => 'count', 'raw' => $s, 'value' => $v, 'start' => $o, 'end' => $o + strlen($s)]);
            }
        }
    }
    if (preg_match_all('/\b[\p{L}][\p{L}\d \'’()\-]{1,60}:\s*(?:\*\*)?\s*(\d{1,3}(?:,\d{3})+|\d+)(?:\*\*)?(?=\s*(?:$|[\n(·,.;]|\*\*|of\b))/mu', $mask, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as [$s, $o]) {
            $add(['kind' => 'count', 'raw' => $s, 'value' => (float)str_replace(',', '', $s), 'start' => $o, 'end' => $o + strlen($s)]);
        }
    }
    usort($claims, static fn($a, $b) => $a['start'] <=> $b['start']);
    $sents = rh_ai_claim_sentences($text);
    foreach ($claims as &$c) {
        $c['sent'] = rh_ai_claim_sentence_of($sents, $c['start']);
    }
    unset($c);
    return array_slice($claims, 0, $max);
}

/** One money claim from its parts (value scaled, tolerance = the shown precision, at least BALANCE_TOLERANCE). */
function rh_ai_claim_money(string $raw, int $off, string $num, string $scale): array
{
    $n = str_replace(',', '', $num);
    $mult = ['k' => 1e3, 'thousand' => 1e3, 'm' => 1e6, 'mn' => 1e6, 'million' => 1e6, 'bn' => 1e9][strtolower($scale)] ?? 1.0;
    $dec = strpos($n, '.') !== false ? strlen(substr(strrchr($n, '.'), 1)) : 0;
    $unit = (10 ** -$dec) * $mult;
    $tol = max(BALANCE_TOLERANCE, $dec >= 2 && $mult === 1.0 ? BALANCE_TOLERANCE : $unit / 2);
    return ['kind' => 'money', 'raw' => rtrim($raw), 'value' => abs((float)$n * $mult), 'tol' => $tol + 1e-9, 'start' => $off, 'end' => $off + strlen(rtrim($raw))];
}

// ── 2. Evidence ─────────────────────────────────────────────────────────────

/** A key that holds a record id (never a figure a reply may quote as a count or an amount). */
function rh_ai_claim_is_id_key(string $k): bool
{
    return (bool)preg_match('/(?:^|_)id$|^id_|(?:^|_)(?:num|uuid|hash)$/i', $k);
}

/**
 * Walk decoded JSON once: numeric leaves with their key path (for step b), list sizes, and per object the sums,
 * differences and ratios of its own figures and the day gaps between its own dates (the allowed derivations).
 */
function rh_ai_claim_walk($v, string $path, array &$acc, int &$budget): void
{
    if ($budget-- <= 0 || !is_array($v)) {
        return;
    }
    $isList = $v === [] || array_keys($v) === range(0, count($v) - 1);
    if ($isList) {
        if ($v) {
            $acc['nums'][(string)count($v)] = true;
            if ($path !== '') {
                $acc['leaves'][] = [$path . '.count', (float)count($v)]; // "3 arrivals" may count the arrivals list
            }
        }
        foreach ($v as $x) {
            rh_ai_claim_walk($x, $path, $acc, $budget);
        }
        return;
    }
    $sib = [];
    $sd = [];
    foreach ($v as $k => $x) {
        $p = $path === '' ? (string)$k : $path . '.' . $k;
        if (is_array($x)) {
            rh_ai_claim_walk($x, $p, $acc, $budget);
            continue;
        }
        if (is_bool($x) || $x === null) {
            continue;
        }
        $s = (string)$x;
        if ((is_int($x) || is_float($x) || is_numeric($s)) && !rh_ai_claim_is_id_key((string)$k)) {
            $f = abs((float)$s);
            $acc['nums'][(string)$f] = true;
            $acc['leaves'][] = [$p, $f];
            $acc['doc'][(string)$f] = $f;
            if (count($sib) < 30) {
                $sib[] = $f;
            }
        } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $dm)) {
            $acc['leaves'][] = [$p, $dm[1]];
            if (count($sd) < 12) {
                $sd[] = $dm[1];
            }
        } elseif (preg_match('/^\[(?:GUEST|STAFF|BK|EMAIL|PHONE)_\d+\]$/', $s)) {
            $acc['leaves'][] = [$p, $s];
        }
    }
    $n = count($sib);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $sib[$i];
            $b = $sib[$j];
            $acc['derived'][] = $a + $b;
            $acc['derived'][] = abs($a - $b);
            if ($a > 0 && $b > 0) {
                $acc['pcts'][] = min($a, $b) / max($a, $b) * 100;
            }
        }
    }
    rh_ai_claim_gaps($sd, $acc);
}

/** Day gaps between a few dates (nights; days of a period count both ends). */
function rh_ai_claim_gaps(array $dates, array &$acc): void
{
    $ts = array_values(array_unique(array_map(static fn($d) => (int)floor((int)strtotime($d . ' 12:00:00') / 86400), $dates)));
    $c = count($ts);
    for ($i = 0; $i < $c; $i++) {
        for ($j = $i + 1; $j < $c; $j++) {
            $acc['gaps'][(string)abs($ts[$i] - $ts[$j])] = true;
        }
    }
}

/**
 * The evidence index. $evidence: the text the reply may draw on (lookup results as JSON lines, the context sent, the
 * chat so far, the person's own words). $opt: today, vault (token => real value: only tokens that appear in the
 * evidence are used), json (decoded lookup results, also walked). Bounded by ai_claim_evidence_cap.
 */
function rh_ai_claim_index(string $evidence, array $opt = []): array
{
    $today = (string)($opt['today'] ?? rh_ai_today());
    $cap = rh_ai_num('ai_claim_evidence_cap');
    $evidence = strlen($evidence) > 600000 ? substr($evidence, 0, 600000) : $evidence;
    $acc = ['nums' => [], 'leaves' => [], 'derived' => [], 'pcts' => [], 'gaps' => []];
    $jsons = (array)($opt['json'] ?? []);
    $plain = [];
    foreach (preg_split('/\n/', $evidence) as $line) {
        $t = trim($line);
        if ($t !== '' && ($t[0] === '{' || $t[0] === '[') && count($jsons) < 120 && is_array($d = json_decode($t, true))) {
            $jsons[] = $d;
        } else {
            $plain[] = $line;
        }
    }
    $budget = 40000;
    foreach ($jsons as $j) {
        $acc['doc'] = [];
        rh_ai_claim_walk($j, '', $acc, $budget);
        // Two figures of the same lookup may be added or compared ("arrivals and departures together: 5")
        $dv = array_values(array_slice($acc['doc'], 0, 40));
        for ($i = 0, $n = count($dv); $i < $n; $i++) {
            for ($k = $i + 1; $k < $n; $k++) {
                $acc['derived'][] = $dv[$i] + $dv[$k];
                $acc['derived'][] = abs($dv[$i] - $dv[$k]);
                if ($dv[$i] > 0 && $dv[$k] > 0) {
                    $acc['pcts'][] = min($dv[$i], $dv[$k]) / max($dv[$i], $dv[$k]) * 100;
                }
            }
        }
    }
    // Figures written as text (the context, earlier answers, the person's words): dates, times, ids and references
    // are taken out first, so "29" from "29 Oct" or "12" from "[GUEST_12]" never back a count.
    $masked = (string)preg_replace(['/\[(?:GUEST|EMAIL|PHONE|BK|STAFF)_\d+\]/', '/\b\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?\b/', '/\b\d{1,2}:\d{2}(?::\d{2})?\b/',
        '/"[a-z_]*id"\s*:\s*"?\d+"?/i', '/\b[A-Z]{2,8}-?\d[\dA-Z]*(?:-[\dA-Z]+)*\b/'], ' ', $evidence);
    $masked = (string)preg_replace('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?(?:,?\s+\d{4})?/iu', ' ', $masked);
    $masked = (string)preg_replace('/\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+\d{1,2}(?:st|nd|rd|th)?\b(?:,?\s+\d{4})?/iu', ' ', $masked);
    if (preg_match_all('/\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?/', $masked, $m)) {
        foreach ($m[0] as $x) {
            if (count($acc['nums']) >= $cap) {
                break;
            }
            $acc['nums'][(string)(float)str_replace(',', '', $x)] = true;
        }
    }
    $recent = implode("\n", array_slice($plain, -60));
    foreach (rh_ai_claim_number_words() as $w => $v) { // "two guests" in the person's words backs "2 guests"
        if (strpos($w, ' ') === false && preg_match('/\b' . $w . '\b/i', $recent)) {
            $acc['nums'][(string)(float)$v] = true;
        }
    }
    $vals = array_map('floatval', array_keys($acc['nums']));
    sort($vals);
    $derived = array_slice(array_values(array_unique($acc['derived'])), 0, $cap * 4);
    sort($derived);
    $pcts = array_slice($acc['pcts'], 0, $cap * 4);
    sort($pcts);
    $dates = [$today => true];
    if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/', $evidence, $m, PREG_SET_ORDER)) {
        foreach ($m as $g) {
            if (checkdate((int)$g[2], (int)$g[3], (int)$g[1])) {
                $dates[$g[0]] = true;
            }
        }
    }
    $plainText = implode("\n", $plain);
    $textDates = rh_ai_claim_extract_dates_only($plainText, $today);
    if (preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/', $plainText, $m)) {
        $textDates = array_merge($textDates, $m[0]);
    }
    foreach ($textDates as $d) {
        $dates[$d] = true;
    }
    rh_ai_claim_gaps(array_slice(array_values(array_unique($textDates)), 0, 20), $acc); // "30 Oct to 2 Nov" in the question -> 3 nights
    $times = [];
    if (preg_match_all('/(?<![\d:])([01]?\d|2[0-3]):([0-5]\d)(?::\d{2})?(?:\s?([ap]\.?m\.?))?/iu', $evidence, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
        foreach ($m as $g) {
            $times[rh_ai_claim_hhmm((int)$g[1], (int)$g[2], (string)($g[3] ?? ''))] = true;
        }
    }
    if (preg_match_all('/(?<![\d:.])(1[0-2]|0?[1-9])\s?([ap]\.?m\.?)(?![a-z])/iu', $evidence, $m, PREG_SET_ORDER)) {
        foreach ($m as $g) {
            $times[rh_ai_claim_hhmm((int)$g[1], 0, $g[2])] = true;
        }
    }
    $tokens = [];
    if (preg_match_all('/\[(?:GUEST|EMAIL|PHONE|BK|STAFF)_\d+\]/', $evidence, $m)) {
        $tokens = array_fill_keys($m[0], true);
    }
    $names = [];
    foreach ((array)($opt['vault'] ?? []) as $t => $real) {
        if (isset($tokens[$t]) && trim((string)$real) !== '') {
            $names[] = mb_strtolower((string)$real);
        }
    }
    return ['nums' => $vals, 'derived' => $derived, 'pcts' => $pcts, 'gaps' => $acc['gaps'], 'dates' => $dates, 'times' => $times, 'tokens' => $tokens, 'names' => $names,
        'text' => mb_strtolower($evidence), 'leaves' => array_slice($acc['leaves'], 0, $cap), 'today' => $today];
}

/** Dates (Y-m-d) in a text, natural ("30 Oct", "Thu 29 Oct 2026") and relative (today, tomorrow...). Bounded. */
function rh_ai_claim_extract_dates_only(string $text, string $today): array
{
    $out = [];
    $mo = '(january|february|march|april|may|june|july|august|september|october|november|december|sept|jan|feb|mar|apr|jun|jul|aug|sep|oct|nov|dec)';
    $months = rh_ai_claim_months();
    if (preg_match_all('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?' . $mo . '\b\.?(?:,?\s+(\d{4})\b)?/iu', $text, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
        foreach (array_slice($m, 0, 200) as $g) {
            $d = rh_ai_claim_date_ymd((int)$g[1], (int)$months[strtolower($g[2])], $g[3] !== null ? (int)$g[3] : null, $today);
            if ($d !== '') {
                $out[] = $d;
            }
        }
    }
    if (preg_match_all('/\b' . $mo . '\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b(?:,?\s+(\d{4})\b)?/iu', $text, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
        foreach (array_slice($m, 0, 200) as $g) {
            $d = rh_ai_claim_date_ymd((int)$g[2], (int)$months[strtolower($g[1])], $g[3] !== null ? (int)$g[3] : null, $today);
            if ($d !== '') {
                $out[] = $d;
            }
        }
    }
    if (preg_match_all('/\b(today|tonight|tomorrow|yesterday)\b/iu', $text, $m)) {
        foreach ($m[1] as $w) {
            $w = strtolower($w);
            $out[] = $w === 'tomorrow' ? date('Y-m-d', strtotime($today . ' +1 day')) : ($w === 'yesterday' ? date('Y-m-d', strtotime($today . ' -1 day')) : $today);
        }
    }
    return $out;
}

/** Is there a number within $tol of $v in a sorted list? (binary search) */
function rh_ai_claim_has_num(array $vals, float $v, float $tol): bool
{
    $lo = 0;
    $hi = count($vals) - 1;
    $want = $v - $tol;
    while ($lo <= $hi) { // the first index holding a value >= v - tol
        $mid = ($lo + $hi) >> 1;
        if ($vals[$mid] < $want) {
            $lo = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }
    return $lo < count($vals) && $vals[$lo] <= $v + $tol;
}

/** Evidence dates with the same day and month as $ymd (a date written without its year, e.g. "Wed 1 Jan"). */
function rh_ai_claim_same_day(array $ix, string $ymd): array
{
    $md = substr($ymd, 4);
    $out = [];
    foreach (array_keys($ix['dates']) as $d) {
        if (substr((string)$d, 4) === $md) {
            $out[] = (string)$d;
        }
    }
    return $out;
}

/** Is one claim backed by the evidence, directly or by an allowed derivation? */
function rh_ai_claim_supported(array $c, array $ix): bool
{
    switch ($c['kind']) {
        case 'token':
            return isset($ix['tokens'][$c['value']]);
        case 'date':
        case 'reldate':
            return isset($ix['dates'][$c['value']]) || (!empty($c['noyear']) && rh_ai_claim_same_day($ix, (string)$c['value']) !== []);
        case 'weekday':
            foreach (array_merge([(string)$c['date']], !empty($c['noyear']) ? rh_ai_claim_same_day($ix, (string)$c['date']) : []) as $d) {
                if ((int)date('N', (int)strtotime($d)) === (int)$c['value']) {
                    return true; // a date written without its year: any evidence date with that day and month
                }
            }
            return false;
        case 'time':
            return isset($ix['times'][$c['value']]);
        case 'ref':
        case 'room':
            return (bool)preg_match('/(?<![\w])' . preg_quote(mb_strtolower((string)$c['value']), '/') . '(?![\w])/u', $ix['text']);
        case 'name':
            $n = mb_strtolower((string)$c['value']);
            if (preg_match('/(?<![\p{L}])' . preg_quote($n, '/') . '(?![\p{L}])/u', $ix['text'])) {
                return true;
            }
            foreach ($ix['names'] as $real) { // the real value behind a token that is in the evidence
                $all = true;
                foreach (preg_split('/\s+/u', $n) as $w) {
                    if (!preg_match('/(?<![\p{L}])' . preg_quote($w, '/') . '/u', $real)) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    return true;
                }
            }
            return false;
        case 'money':
            return rh_ai_claim_has_num($ix['nums'], (float)$c['value'], (float)$c['tol']) || rh_ai_claim_has_num($ix['derived'], (float)$c['value'], (float)$c['tol']);
        case 'pct':
            return rh_ai_claim_has_num($ix['nums'], (float)$c['value'], (float)$c['tol']) || rh_ai_claim_has_num($ix['pcts'], (float)$c['value'], (float)$c['tol']);
        case 'count':
            return rh_ai_claim_has_num($ix['nums'], (float)$c['value'], 1e-9) || rh_ai_claim_has_num($ix['derived'], (float)$c['value'], 1e-9);
        case 'duration':
            $v = (float)$c['value'];
            if (rh_ai_claim_has_num($ix['nums'], $v, 1e-9)) {
                return true;
            }
            switch ($c['unit']) {
                case 'night':
                    return isset($ix['gaps'][(string)(int)$v]);
                case 'day':
                    return isset($ix['gaps'][(string)(int)$v]) || isset($ix['gaps'][(string)((int)$v - 1)]);
                case 'week':
                    return isset($ix['gaps'][(string)((int)$v * 7)]) || isset($ix['gaps'][(string)((int)$v * 7 - 1)]);
                case 'hour':
                    return rh_ai_claim_has_num($ix['nums'], $v * 60, 1e-9);
            }
            return rh_ai_claim_has_num($ix['derived'], $v, 1e-9);
    }
    return true;
}

/**
 * Which claims to hold to the evidence. A relative day ("tomorrow") and an hours / days duration count only beside
 * another data claim in the same sentence ("check again tomorrow", "reply within 24 hours" are advice, not data).
 */
function rh_ai_claim_checkable(array $claims): array
{
    $dataIn = [];
    foreach ($claims as $c) {
        if (in_array($c['kind'], ['money', 'count', 'pct', 'token', 'ref', 'date', 'name', 'room'], true) || ($c['kind'] === 'duration' && $c['unit'] === 'night')) {
            $dataIn[$c['sent']] = true;
        }
    }
    return array_values(array_filter($claims, static function ($c) use ($dataIn) {
        if ($c['kind'] === 'reldate') {
            return isset($dataIn[$c['sent']]);
        }
        if ($c['kind'] === 'duration' && $c['unit'] !== 'night') {
            return isset($dataIn[$c['sent']]);
        }
        return true;
    }));
}

// ── Internal consistency ────────────────────────────────────────────────────

/** Words that name booking / room statuses (a breakdown made only of these must add up to its total). */
function rh_ai_claim_status_words(): string
{
    return 'pending|confirmed|tentative|checked[ _-]?in|checked[ _-]?out|cancelled|canceled|no[ _-]?shows?|available|occupied|cleaning|dirty|clean|inspecting|maintenance|out of order|out of service|blocked|reserved|vacant';
}

/**
 * Places where a reply disagrees with itself: "N of M" with N > M; a list longer than the count it follows; parts that
 * do not add up to the total they name; a status breakdown that does not add up; "N of M (P%)" with the wrong P; a
 * person counted as active with nothing that made them active. @return list of [raw, start, why, fix (or null)]
 */
function rh_ai_claim_consistency(string $text): array
{
    $out = [];
    $tol = rh_ai_claim_pct_tolerance();
    // N of M (P%)
    if (preg_match_all('/(?<![\w.,\-])(\d{1,3}(?:,\d{3})+|\d+)(?:\*\*)?\s+(?:of|out of)\s+(\d{1,3}(?:,\d{3})+|\d+)\b(?:[^\n(]{0,40}\((\d+(?:\.\d+)?)\s?%\))?/u', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
        foreach ($m as $g) {
            $n = (float)str_replace(',', '', $g[1][0]);
            $t = (float)str_replace(',', '', $g[2][0]);
            if ($n > $t) {
                $out[] = [$g[1][0], (int)$g[1][1], 'more than its total', null];
            } elseif ($g[3][0] !== null && $t > 0 && abs($n / $t * 100 - (float)$g[3][0]) > $tol + 0.5 * 10 ** -(strpos($g[3][0], '.') !== false ? strlen(substr(strrchr($g[3][0], '.'), 1)) : 0)) {
                $out[] = [$g[3][0] . '%', (int)$g[3][1], 'percentage does not match', (string)round($n / $t * 100, 1) . '%'];
            }
        }
    }
    // A bullet list longer than the count it follows (no "and N more" paging)
    $lines = preg_split('/\n/', $text);
    $offs = [];
    $p = 0;
    foreach ($lines as $l) {
        $offs[] = $p;
        $p += strlen($l) + 1;
    }
    $nouns = rh_ai_claim_count_nouns();
    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        if (preg_match('/^\s*[-*•]\s/u', $lines[$i]) || trim($lines[$i]) === '') {
            continue;
        }
        $k = $i + 1;
        while ($k < $n && trim($lines[$k]) === '') {
            $k++;
        }
        $items = 0;
        $more = 0;
        while ($k < $n && preg_match('/^\s*[-*•]\s/u', $lines[$k])) {
            if (preg_match('/^\s*[-*•]\s+and (\d+) more/u', $lines[$k], $mm)) {
                $more = (int)$mm[1];
            } else {
                $items++;
            }
            $k++;
        }
        if ($items === 0) {
            continue;
        }
        $head = $lines[$i];
        // The count the list belongs to: "only N", a bold headline "**X: N**", "N <noun>" at the end of a line that leads into the list
        if (preg_match('/\b(?:only|just)\s+(\d+|one|two|three|four|five|six|seven|eight|nine|ten)\b/iu', $head, $hm, PREG_OFFSET_CAPTURE)
            || preg_match('/^\s*\*\*[^*\n]*?:\s*(\d+)\*\*/u', $head, $hm, PREG_OFFSET_CAPTURE)
            || preg_match('/(?<![\w.,])(\d+)\s+(?:[a-z\-]+\s+){0,2}(?:' . $nouns . ')\b[^\n.]*:\s*$/u', $head, $hm, PREG_OFFSET_CAPTURE)) {
            $count = is_numeric($hm[1][0]) ? (int)$hm[1][0] : (int)rh_ai_claim_word_value($hm[1][0]);
            if ($items + $more > $count) {
                $out[] = [$hm[1][0], $offs[$i] + (int)$hm[1][1], 'the list beside it is longer', (string)($items + $more)];
            }
        }
    }
    // Parts that do not add up to the total they name ("3 pending and 9 confirmed, 12 in total")
    foreach (rh_ai_claim_sentences($text) as [$a, $b]) {
        $s = substr($text, $a, $b - $a);
        if (!preg_match('/(?<![\w.,])(\d+)\s*(?:in total|in all|altogether)\b|\b(?:a )?total of\s+(\d+)\b/iu', $s, $tm, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            continue;
        }
        $tg = $tm[1][0] !== null ? $tm[1] : $tm[2];
        $total = (int)$tg[0];
        if (!preg_match_all('/(?<![\w.,])(\d+)\s+(' . rh_ai_claim_status_words() . '|[a-z]+)\b/iu', $s, $pm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $parts = [];
        foreach ($pm as $g) {
            if ((int)$g[1][1] !== (int)$tg[1] && !preg_match('/^(in|of|out|total|more|days?|nights?|hours?|minutes?)$/iu', $g[2][0])) {
                $parts[] = (int)$g[1][0];
            }
        }
        if (count($parts) >= 2 && array_sum($parts) !== $total) {
            $out[] = [$tg[0], $a + (int)$tg[1], 'parts do not add up', (string)array_sum($parts)];
        }
    }
    // A status breakdown in brackets after its total: "Rooms: 12 (3 dirty, 9 available)"
    if (preg_match_all('/(?<![\w.,])(\d+)(?:\*\*)?\s*\(((?:\d+\s+[a-z _\-]+(?:,\s*|\)))+)/iu', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $g) {
            $inside = rtrim($g[2][0], ')');
            $sum = 0;
            $all = true;
            foreach (preg_split('/,\s*/', $inside) as $part) {
                if (!preg_match('/^(\d+)\s+(' . rh_ai_claim_status_words() . ')$/iu', trim($part), $pp)) {
                    $all = false;
                    break;
                }
                $sum += (int)$pp[1];
            }
            if ($all && $sum !== (int)$g[1][0]) {
                $out[] = [$g[1][0], (int)$g[1][1], 'status parts do not add up', (string)$sum];
            }
        }
    }
    // Counted as active with nothing that made them active ("· 0 logged actions" and no sign-in or visit named)
    if (preg_match('/\bactive\b/iu', $text)) {
        foreach ($lines as $i => $l) {
            if (preg_match('/(?<![\w.,])0\s+(?:logged\s+)?actions?\b/iu', $l, $am, PREG_OFFSET_CAPTURE) && !preg_match('/\b(signed in|logged in|seen in the portal|visited|sign-in|online now)\b/iu', $l)) { // "last seen" alone is not a reason
                $out[] = ['0', $offs[$i] + (int)$am[0][1], 'counted as active with nothing that made them active', null];
            }
        }
    }
    return $out;
}

// ── The check ───────────────────────────────────────────────────────────────

/**
 * Check a reply against its evidence. @return array{claims:int, bad:list<array>, inconsistent:list<array>, ms:float}
 * bad = unsupported claims (each the claim array); inconsistent = rh_ai_claim_consistency() rows.
 */
function rh_ai_claim_check(string $reply, string $evidence, array $opt = []): array
{
    $t0 = microtime(true);
    $today = (string)($opt['today'] ?? rh_ai_today());
    $claims = rh_ai_claim_checkable(rh_ai_claim_extract($reply, $today));
    $bad = [];
    if ($claims) {
        $ix = $opt['index'] ?? rh_ai_claim_index($evidence, ['today' => $today, 'vault' => (array)($opt['vault'] ?? []), 'json' => (array)($opt['json'] ?? [])]);
        $sents = rh_ai_claim_sentences($reply);
        $numeric = ['money' => 1, 'count' => 1, 'pct' => 1, 'duration' => 1];
        foreach ($claims as $i => $c) {
            // The label window: from the figure before it in the same sentence to the figure after it (a date or a
            // weekday in between does not end a label: "Staff active today (Thu 29 Oct): 2")
            [$sa, $sb] = $sents[$c['sent']] ?? [0, strlen($reply)];
            $from = $sa;
            $to = $sb;
            for ($k = $i - 1; $k >= 0; $k--) {
                if ($claims[$k]['sent'] === $c['sent'] && isset($numeric[$claims[$k]['kind']])) {
                    $from = $claims[$k]['end'];
                    break;
                }
            }
            for ($k = $i + 1, $n = count($claims); $k < $n; $k++) {
                if ($claims[$k]['sent'] === $c['sent'] && isset($numeric[$claims[$k]['kind']])) {
                    $to = $claims[$k]['start'];
                    break;
                }
            }
            $c['from'] = $from;
            $c['to'] = $to;
            $claims[$i] = $c;
            if (!rh_ai_claim_supported($c, $ix)) {
                $bad[] = $c;
                continue;
            }
            if (!in_array($c['kind'], ['money', 'count', 'pct'], true)) {
                continue;
            }
            // A figure whose own label names lookup fields ("3 arrivals", "balance due MWK ...", "Staff active: 1") must
            // be one of THOSE fields (or a sum / difference of two of them), not just any number found somewhere.
            if ($c['kind'] === 'count' && preg_match('/^[\s*]*(\p{L}[\p{L}\-]*)/u', substr($reply, $c['end'], 40), $nw2)
                && preg_match('/(?<![\d.,])' . preg_quote(mb_strtolower($c['raw']), '/') . '\s+' . preg_quote(mb_strtolower($nw2[1]), '/') . '\b/u', $ix['text'])) {
                continue; // the very phrase is in the data ("15 slots" written by the lookup itself)
            }
            $lv = rh_ai_claim_labelled($reply, $c, $ix);
            if ($lv && !rh_ai_claim_in_set((float)$c['value'], $lv, (float)($c['tol'] ?? 1e-9), $c['kind'] === 'pct')) {
                $bad[] = $c;
                continue;
            }
            if (!$lv && !rh_ai_claim_has_num($ix['nums'], (float)$c['value'], (float)($c['tol'] ?? 1e-9))) {
                // Backed only by a sum / difference / ratio: a field plainly named beside it ("5 arrivals" next to
                // arrivals.total = 3) wins over an accidental derivation. A sentence that says it adds, compares or
                // leaves something over keeps its derivation.
                if (preg_match('/\b(together|combined|altogether|in all|in total|total of|plus|sum|between them|overall|more than|fewer than|less than|difference|left|remaining|still|up from|down from|rest|other|besides|apart from|including|of which|out of|share|per cent|percent|rate)\b/iu', substr($reply, $sa, $sb - $sa))) {
                    continue;
                }
                $cand = rh_ai_claim_candidate($reply, $c, $ix);
                if ($cand !== null && abs((float)preg_replace('/[^\d.]/', '', $cand) - (float)$c['value']) > (float)($c['tol'] ?? 1e-9)) {
                    $bad[] = $c;
                }
            }
        }
    }
    $inc = rh_ai_claim_consistency($reply);
    $core = $claims ? (int)$claims[0]['sent'] : 0; // the core answer: the first sentence that states data
    return ['claims' => count($claims), 'bad' => $bad, 'inconsistent' => $inc, 'core' => $core, 'ms' => round((microtime(true) - $t0) * 1000, 2)];
}

/** Field names that can hold money, or a percentage (a money claim is never matched to a count field). */
function rh_ai_claim_kind_field_re(string $kind): string
{
    return $kind === 'pct' ? '/pct|percent|rate|share/'
        : '/total|paid|balance|amount|price|rate|revenue|adr|revpar|due|owe|owing|deposit|cost|sales|net|vat|levy|charge|value|spend|refund|takings|discount|left|outstanding|bill|fee|tariff|budget|money|cash|takings/';
}

/**
 * The figures of the lookup fields a claim's own label names (a field matched by a word before the figure, or by a
 * count's own noun), or [] when its label names no field. Percentages only match percentage / rate fields.
 */
function rh_ai_claim_labelled(string $text, array $c, array $ix): array
{
    $label = rh_ai_claim_label_words($text, $c, (int)($c['from'] ?? -1), (int)($c['to'] ?? -1));
    if (!$label) {
        return [];
    }
    $vals = [];
    foreach ($ix['leaves'] as [$path, $v]) {
        if (!is_float($v) && !is_int($v)) {
            continue; // dates and tokens
        }
        $p = mb_strtolower((string)$path);
        if (in_array($c['kind'], ['money', 'pct'], true) && !preg_match(rh_ai_claim_kind_field_re($c['kind']), $p)) {
            continue;
        }
        $keys = preg_split('/[._]+/', $p);
        $score = 0;
        foreach ($label as $w => $wt) {
            $w = (string)$w;
            if (in_array($w, $keys, true) || ($wt >= 2 && mb_strlen($w) > 3 && strpos($p, $w) !== false)) {
                $score += $wt;
            }
        }
        if ($score >= 2) {
            $vals[(string)$v] = (float)$v;
        }
    }
    return array_values($vals);
}

/** Is $v one of the labelled figures, or a sum / difference of two of them (a ratio of two for a percentage)? */
function rh_ai_claim_in_set(float $v, array $set, float $tol, bool $pct): bool
{
    $n = count($set);
    for ($i = 0; $i < $n; $i++) {
        if (abs($set[$i] - $v) <= $tol) {
            return true;
        }
        for ($k = $i + 1; $k < $n && $k < 60; $k++) {
            if ($pct ? ($set[$i] > 0 && $set[$k] > 0 && abs(min($set[$i], $set[$k]) / max($set[$i], $set[$k]) * 100 - $v) <= $tol)
                : (abs($set[$i] + $set[$k] - $v) <= $tol || abs(abs($set[$i] - $set[$k]) - $v) <= $tol)) {
                return true;
            }
        }
    }
    return false;
}

/** The raw strings of the unsupported claims (the older reply-check's shape: tokens, references, figures). */
function rh_ai_claim_bad_raws(string $reply, string $evidence, array $opt = []): array
{
    $r = rh_ai_claim_check($reply, $evidence, $opt);
    $out = [];
    foreach ($r['bad'] as $c) {
        $out[$c['raw']] = true;
    }
    foreach ($r['inconsistent'] as $x) {
        $out[$x[0]] = true;
    }
    return array_map('strval', array_keys($out));
}

// ── 3. Correct ──────────────────────────────────────────────────────────────

/**
 * Label words near a claim (up to 4 before, 3 after), lower case; synonyms map them to lookup field words. $from / $to
 * bound the window (the previous and next claim in the sentence), so a label never belongs to a neighbouring figure.
 * A count's own noun ("3 arrivals") weighs as much as the words before it. @return array word => weight
 */
function rh_ai_claim_label_words(string $text, array $c, int $from = -1, int $to = -1): array
{
    $bStart = max(0, $c['start'] - 60, $from);
    $before = mb_strtolower(substr($text, $bStart, max(0, $c['start'] - $bStart)));
    $aEnd = $to >= 0 ? min($to, $c['end'] + 40) : $c['end'] + 40;
    $after = mb_strtolower(substr($text, $c['end'], max(0, $aEnd - $c['end'])));
    if ($aEnd < strlen($text) && preg_match('/^\p{L}/u', substr($text, $aEnd, 4))) {
        $after = (string)preg_replace('/\p{L}+$/u', '', $after); // never half a word at the window's end
    }
    // What follows a figure labels it only up to the next mark ("2** (1 pending" -> nothing)
    $after = (string)preg_split('/[(),;.:\n]|\x{00B7}/u', (string)preg_replace('/^[\s*]+/u', '', $after))[0];
    preg_match_all('/\p{L}+/u', $before, $b);
    preg_match_all('/\p{L}+/u', $after, $a);
    // Words before the figure weigh more; words after it only when they are not the "of 11 ..." of a part-of-whole
    $wts = [];
    foreach (array_slice($b[0], -4) as $w) {
        $wts[] = [$w, 2];
    }
    if (!preg_match('/^\s*(?:\*\*)?\s*(?:of|out of)\s+\d/u', $after)) {
        foreach (array_slice($a[0], 0, 3) as $w) {
            $wts[] = [$w, ($c['kind'] ?? '') === 'count' ? 2 : 1];
        }
    }
    $syn = ['owe' => 'balance due owed outstanding', 'owes' => 'balance due owed outstanding', 'owed' => 'balance due owed outstanding', 'owing' => 'balance due outstanding',
        'due' => 'balance due', 'outstanding' => 'balance outstanding due', 'balance' => 'balance due', 'paid' => 'paid payment', 'payment' => 'paid payment', 'payments' => 'paid payment',
        'total' => 'total amount', 'cost' => 'total amount price', 'price' => 'price total amount rate', 'amount' => 'amount total', 'charge' => 'total amount', 'bill' => 'total amount',
        'arrival' => 'arrivals arrival check_in', 'arrivals' => 'arrivals total arrival', 'arriving' => 'arrivals check_in', 'arrives' => 'check_in arrival', 'departure' => 'departures check_out',
        'departures' => 'departures total', 'leaves' => 'check_out leaves', 'leaving' => 'check_out departures', 'night' => 'nights number_of_nights', 'nights' => 'nights number_of_nights',
        'guest' => 'guests adults', 'guests' => 'guests adults number_of_guests', 'people' => 'guests people adults', 'adults' => 'adults', 'children' => 'children child_guests',
        'active' => 'active_staff active', 'staff' => 'active_staff staff', 'account' => 'staff_accounts accounts', 'accounts' => 'staff_accounts accounts', 'action' => 'actions', 'actions' => 'actions',
        'occupancy' => 'occupancy_pct occupancy', 'rooms' => 'rooms', 'room' => 'rooms room', 'adr' => 'adr', 'revpar' => 'revpar', 'house' => 'in_house', 'online' => 'online',
        'bookings' => 'total_in_system count bookings', 'booking' => 'count bookings', 'revenue' => 'revenue net total sales', 'sales' => 'sales total revenue', 'takings' => 'total sales',
        'check' => 'check_in check_out', 'checkin' => 'check_in', 'checkout' => 'check_out', 'in' => 'check_in', 'out' => 'check_out', 'deposit' => 'deposit paid', 'members' => 'members active', 'orders' => 'open_count orders'];
    $out = [];
    foreach ($wts as [$w, $wt]) {
        foreach (preg_split('/\s+/', $syn[$w] ?? '') as $s) {
            if ($s !== '') {
                $out[$s] = max($out[$s] ?? 0, $wt);
            }
        }
        if (mb_strlen($w) > 3) {
            $out[$w] = max($out[$w] ?? 0, $wt);
        }
    }
    return $out; // word => weight
}

/**
 * Step b: the evidence value plainly meant for an unsupported claim (same kind, a field whose name matches the label
 * beside it, exactly one distinct value), formatted like the claim. null = no single clear value.
 */
function rh_ai_claim_candidate(string $text, array $c, array $ix): ?string
{
    if ($c['kind'] === 'weekday') {
        $real = (int)date('N', (int)strtotime($c['date']));
        $long = mb_strlen($c['raw']) > 3;
        $name = date($long ? 'l' : 'D', (int)strtotime($c['date']));
        return $real !== (int)$c['value'] ? (ctype_upper(mb_substr($c['raw'], 0, 1)) ? $name : mb_strtolower($name)) : null;
    }
    if ($c['kind'] === 'token' || $c['kind'] === 'name') {
        $type = $c['kind'] === 'token' ? (string)$c['type'] : (preg_match('/\b(staff|receptionist|manager|colleague|by)\b/iu', substr($text, max(0, $c['start'] - 40), 40)) ? 'STAFF' : 'GUEST');
        $same = [];
        foreach (array_keys($ix['tokens']) as $t) {
            if (strpos($t, '[' . $type . '_') === 0) {
                $same[$t] = true;
            }
        }
        return count($same) === 1 ? (string)array_key_first($same) : null;
    }
    if (!in_array($c['kind'], ['money', 'count', 'pct', 'date'], true) && !($c['kind'] === 'duration' && ($c['unit'] ?? '') === 'night')) {
        return null; // a reference, room, time or other duration is never guessed from a neighbouring field
    }
    $label = rh_ai_claim_label_words($text, $c, (int)($c['from'] ?? -1), (int)($c['to'] ?? -1));
    if (!$label) {
        return null;
    }
    $best = 1; // a field must be named by a strong label word (before the figure, or a count's own noun)
    $vals = [];
    foreach ($ix['leaves'] as [$path, $v]) {
        $isDate = is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v);
        if ($c['kind'] === 'date' ? !$isDate : (!is_numeric($v) || $isDate)) {
            continue;
        }
        if (in_array($c['kind'], ['money', 'pct'], true) && !preg_match(rh_ai_claim_kind_field_re($c['kind']), mb_strtolower((string)$path))) {
            continue;
        }
        $keys = preg_split('/[._]+/', mb_strtolower((string)$path));
        $keys[] = mb_strtolower((string)$path);
        $last = (string)end($keys);
        $score = 0;
        foreach ($label as $w => $wt) {
            if (in_array((string)$w, $keys, true) || strpos(mb_strtolower((string)$path), (string)$w) !== false) {
                $score += $wt;
            }
        }
        if ($score < 2) {
            continue;
        }
        if ($c['kind'] === 'count' && is_numeric($v) && floor((float)$v) != (float)$v) {
            continue;
        }
        if ($score > $best) {
            $best = $score;
            $vals = [];
        }
        if ($score === $best) {
            $vals[$c['kind'] === 'date' ? substr((string)$v, 0, 10) : (string)round((float)$v, 2)] = $last;
        }
    }
    if (count($vals) !== 1) {
        return null;
    }
    $v = (string)array_key_first($vals);
    if ($c['kind'] === 'date') {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($c['raw'])) ? $v : date(preg_match('/\d{4}/', $c['raw']) ? 'j M Y' : 'j M', (int)strtotime($v));
    }
    if ($c['kind'] === 'money') {
        $prefix = '';
        if (preg_match('/^([^\d\-]*)/u', $c['raw'], $pm)) {
            $prefix = $pm[1];
        }
        return $prefix . number_format((float)$v, strpos($c['raw'], '.') !== false ? 2 : (floor((float)$v) == (float)$v ? 0 : 2));
    }
    if ($c['kind'] === 'pct') {
        return rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.') . '%';
    }
    return (string)(floor((float)$v) == (float)$v ? (int)$v : $v);
}

/**
 * Step c: the reply built from this question's own lookups, with their local wording (and the absence guard after it
 * in the caller). '' = nothing safe to build from. A filtered search is never turned into "none": its tool's own
 * not-found line and the checked total are used.
 */
function rh_ai_claim_rebuild(array $results): string
{
    foreach (array_reverse($results) as $row) {
        [$tool, $res, $args] = array_pad((array)$row, 3, []);
        $res = (array)$res;
        $args = (array)$args;
        if (!empty($res['error'])) {
            continue;
        }
        if (isset($res['not_found']) && is_string($res['not_found'])) {
            $first = trim((string)preg_split('/(?<=[.!?])\s+/u', $res['not_found'])[0]);
            $total = isset($res['total_in_system']) ? (int)$res['total_in_system'] : null;
            return rtrim($first, '.') . '.' . ($total !== null && $total > 0 ? ' The system holds ' . $total . ' booking' . ($total === 1 ? '' : 's') . ' in all.' : '');
        }
        if ($tool === 'search_bookings' && !empty($args['query'])) {
            continue; // a filtered search: its rows are not the whole picture
        }
        $txt = function_exists('rh_ai_template_render') ? rh_ai_template_render((string)$tool, $args, $res) : '';
        if ($txt === '' && function_exists('rh_ai_answer_from_results')) {
            $txt = rh_ai_answer_from_results([[$tool, $res, $args]]);
        }
        if (trim($txt) !== '') {
            return trim($txt);
        }
    }
    return '';
}

/**
 * Correct a reply whose claims the evidence does not back, cheapest first (a drop / b replace / c rebuild); d = the
 * caller's Primary recheck when nothing here can answer. @return array{text:string, steps:array<string,int>,
 * fixes:list<array>, need_recheck:bool}
 */
function rh_ai_claim_correct(string $reply, array $check, array $ix, array $results): array
{
    $steps = ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0];
    $fixes = [];
    $problems = $check['bad'];
    foreach ($check['inconsistent'] as [$raw, $start, $why, $fix]) {
        $problems[] = ['kind' => 'inconsistent', 'raw' => $raw, 'value' => $raw, 'start' => $start, 'end' => $start + strlen($raw), 'why' => $why, 'fix' => $fix];
    }
    if (!$problems) {
        return ['text' => $reply, 'steps' => $steps, 'fixes' => [], 'need_recheck' => false];
    }
    $sents = rh_ai_claim_sentences($reply);
    $core = (int)($check['core'] ?? 0);
    foreach ($problems as &$p) {
        $p['sent'] = rh_ai_claim_sentence_of($sents, (int)$p['start']);
    }
    unset($p);
    $drop = [];
    $repl = []; // start => [end, new]
    $rebuild = false;
    foreach ($problems as $p) {
        if ($p['sent'] !== $core && count($sents) > 1) {
            $drop[$p['sent']] = true;
            $steps['a']++;
            $fixes[] = ['step' => 'a', 'claim' => $p['raw'], 'kind' => $p['kind'], 'field' => ''];
            continue;
        }
        $new = $p['kind'] === 'inconsistent' ? $p['fix'] : rh_ai_claim_candidate($reply, $p, $ix);
        if ($new !== null && $new !== '') {
            $s = $p['kind'] === 'name' ? (int)($p['whole_start'] ?? $p['start']) : (int)$p['start'];
            $repl[$s] = [(int)$p['end'], $new];
            $steps['b']++;
            $fixes[] = ['step' => 'b', 'claim' => $p['raw'], 'kind' => $p['kind'], 'field' => $new];
            continue;
        }
        $rebuild = true;
        $fixes[] = ['step' => 'c', 'claim' => $p['raw'], 'kind' => $p['kind'], 'field' => ''];
    }
    if ($rebuild) {
        $built = rh_ai_claim_rebuild($results);
        if ($built !== '') {
            $steps['c']++;
            return ['text' => $built, 'steps' => $steps, 'fixes' => $fixes, 'need_recheck' => false];
        }
        $steps['d']++;
        foreach ($fixes as &$f) {
            if ($f['step'] === 'c') {
                $f['step'] = 'd';
            }
        }
        unset($f);
        return ['text' => '', 'steps' => $steps, 'fixes' => $fixes, 'need_recheck' => true];
    }
    krsort($repl);
    $text = $reply;
    foreach ($repl as $s => [$e, $new]) {
        $text = substr_replace($text, $new, $s, $e - $s);
    }
    if ($drop) { // sentence offsets are from the original: drop on the original spans, then re-apply replacements inside kept ones
        $text = '';
        $out = [];
        foreach ($sents as $i => [$a, $b]) {
            if (isset($drop[$i])) {
                continue;
            }
            $seg = substr($reply, $a, $b - $a);
            $segRepl = array_filter($repl, static fn($r, $s) => $s >= $a && $s < $b, ARRAY_FILTER_USE_BOTH);
            krsort($segRepl);
            foreach ($segRepl as $s => [$e, $new]) {
                $seg = substr_replace($seg, $new, $s - $a, $e - $s);
            }
            $out[] = [$a, $seg];
        }
        // keep the original separators (a line break stays a line break)
        $prevEnd = null;
        foreach ($out as [$a, $seg]) {
            if ($prevEnd !== null) {
                $sep = substr($reply, $prevEnd, $a - $prevEnd);
                $text .= strpos($sep, "\n") !== false ? "\n" : ' ';
            }
            $text .= $seg;
            $prevEnd = $a + strlen($seg);
            foreach ($sents as [$sa, $sb]) {
                if ($sa === $a) {
                    $prevEnd = $sb;
                    break;
                }
            }
        }
    }
    $text = trim((string)preg_replace("/\n{3,}/", "\n\n", (string)preg_replace('/^[ \t]*(?:[-*•]|\d+[.)])[ \t]*$/mu', '', $text)));
    return ['text' => $text, 'steps' => $steps, 'fixes' => $fixes, 'need_recheck' => $text === ''];
}

/**
 * The whole guard for one model-written reply: check, then correct (a/b/c), re-check the corrected text, and if a claim
 * is still unsupported drop its sentence. @return array{text:string, changed:bool, need_recheck:bool, steps:array,
 * fixes:list, claims:int, ms:float}
 */
function rh_ai_claim_guard(string $reply, string $evidence, array $opt = []): array
{
    $t0 = microtime(true);
    $today = (string)($opt['today'] ?? rh_ai_today());
    $ix = rh_ai_claim_index($evidence, ['today' => $today, 'vault' => (array)($opt['vault'] ?? []), 'json' => (array)($opt['json'] ?? [])]);
    $chk = rh_ai_claim_check($reply, '', ['today' => $today, 'index' => $ix]);
    $res = ['text' => $reply, 'changed' => false, 'need_recheck' => false, 'steps' => ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0], 'fixes' => [], 'claims' => $chk['claims']];
    if (!$chk['bad'] && !$chk['inconsistent']) {
        $res['ms'] = round((microtime(true) - $t0) * 1000, 2);
        return $res;
    }
    $fx = rh_ai_claim_correct($reply, $chk, $ix, (array)($opt['results'] ?? []));
    $res['steps'] = $fx['steps'];
    $res['fixes'] = $fx['fixes'];
    $res['changed'] = true;
    if ($fx['need_recheck']) {
        $res['need_recheck'] = true;
        $res['text'] = rh_ai_claim_strip($reply, $chk, $ix);
    } else {
        $res['text'] = $fx['text'];
        if ($fx['steps']['c'] === 0) { // a replaced or trimmed reply is checked again; anything still unbacked goes
            $again = rh_ai_claim_check($res['text'], '', ['today' => $today, 'index' => $ix]);
            if ($again['bad'] || $again['inconsistent']) {
                $res['text'] = rh_ai_claim_strip($res['text'], $again, $ix);
                $res['need_recheck'] = $res['text'] === '';
            }
        }
    }
    $res['ms'] = round((microtime(true) - $t0) * 1000, 2);
    return $res;
}

/** Every sentence holding an unbacked claim removed (the last resort; '' when nothing is left). No note is added. */
function rh_ai_claim_strip(string $reply, array $chk, array $ix): string
{
    $sents = rh_ai_claim_sentences($reply);
    $drop = [];
    foreach ($chk['bad'] as $c) {
        $drop[rh_ai_claim_sentence_of($sents, (int)$c['start'])] = true;
    }
    foreach ($chk['inconsistent'] as $x) {
        $drop[rh_ai_claim_sentence_of($sents, (int)$x[1])] = true;
    }
    $parts = [];
    $prevEnd = null;
    $text = '';
    foreach ($sents as $i => [$a, $b]) {
        if (isset($drop[$i])) {
            continue;
        }
        if ($prevEnd !== null) {
            $text .= strpos(substr($reply, $prevEnd, $a - $prevEnd), "\n") !== false ? "\n" : ' ';
        }
        $text .= substr($reply, $a, $b - $a);
        $prevEnd = $b;
    }
    return trim((string)preg_replace("/\n{3,}/", "\n\n", (string)preg_replace('/^[ \t]*(?:[-*•]|\d+[.)])[ \t]*$/mu', '', $text)));
}

// ── 4. Learn ────────────────────────────────────────────────────────────────

/**
 * One incident per corrected reply: kind 'claim_corrected', the claims (privacy tokens only: real names never stored),
 * the field each should have used, the step, the route and model. Feeds the daily lesson -> regression-question loop.
 */
function rh_ai_claim_incident(PDO $db, array $guard, array $ctx, array $refs = []): int
{
    if (empty($guard['fixes']) || !function_exists('rh_ai_incident_add')) {
        return 0;
    }
    $fixes = [];
    $reply = (string)($ctx['reply'] ?? '');
    foreach ((array)$guard['fixes'] as $f) {
        if (($f['kind'] ?? '') === 'name') {
            $reply = str_replace((string)$f['claim'], '[name]', $reply); // an invented name is never stored, even in the reply's first words
        }
    }
    foreach (array_slice((array)$guard['fixes'], 0, 12) as $f) {
        $isName = ($f['kind'] ?? '') === 'name';
        $fixes[] = ['step' => (string)$f['step'], 'kind' => (string)$f['kind'], 'claim' => $isName ? '[name]' : mb_substr((string)$f['claim'], 0, 60), 'should_use' => mb_substr((string)$f['field'], 0, 60)];
    }
    $steps = array_filter((array)$guard['steps']);
    return rh_ai_incident_add($db, 'claim_corrected', 'A reply claim was not in the data and was corrected (' . implode(', ', array_map(static fn($k, $n) => $k . ':' . $n, array_keys($steps), $steps)) . ')',
        ['question' => mb_substr((string)($ctx['question'] ?? ''), 0, 300), 'route' => (string)($ctx['route'] ?? ''), 'model' => (string)($ctx['model'] ?? ''), 'fixes' => $fixes,
            'head' => function_exists('rh_ai_wrong_head') ? rh_ai_wrong_head($reply) : ''], $refs);
}

/** The question asked just before this one in the chat (privacy tokens), for a follow-up's topic. */
function rh_ai_claim_prev_question(PDO $db, int $conv, int $beforeId): string
{
    try {
        $st = $db->prepare("SELECT content FROM ai_messages WHERE conversation_id = ? AND role = 'user' AND id < ? ORDER BY id DESC LIMIT 1");
        $st->execute([$conv, $beforeId]);
        return (string)$st->fetchColumn();
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Earlier lookups of this chat still in context (after the rolling summary), newest first, as JSON lines: what a
 * follow-up answer may also draw on. Only this chat's own results, which were permission-checked when they ran.
 */
function rh_ai_claim_prior_evidence(PDO $db, int $conv, int $beforeId): string
{
    try {
        $st = $db->prepare("SELECT tool_args_json FROM ai_messages WHERE conversation_id = ? AND role = 'summary' ORDER BY id DESC LIMIT 1");
        $st->execute([$conv]);
        $upto = (int)(json_decode((string)$st->fetchColumn(), true)['upto'] ?? 0);
        $st = $db->prepare("SELECT tool_result_json FROM ai_messages WHERE conversation_id = ? AND role = 'tool' AND id > ? AND id < ? ORDER BY id DESC LIMIT 12");
        $st->execute([$conv, $upto, $beforeId]);
        return implode("\n", array_map(static fn($j) => str_replace(["\r", "\n"], ' ', (string)$j), $st->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) {
        return '';
    }
}

/** Claims corrected since a date (the usage page): total and per step. */
function rh_ai_claim_stats(PDO $db, string $from): array
{
    $out = ['total' => 0, 'a' => 0, 'b' => 0, 'c' => 0, 'd' => 0];
    try {
        $st = $db->prepare("SELECT context_json FROM ai_incidents WHERE kind = 'claim_corrected' AND created_at >= ? ORDER BY id DESC LIMIT 5000");
        $st->execute([$from]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $j) {
            $c = json_decode((string)$j, true) ?: [];
            foreach ((array)($c['fixes'] ?? []) as $f) {
                $k = (string)($f['step'] ?? '');
                if (isset($out[$k])) {
                    $out[$k]++;
                    $out['total']++;
                }
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

// ── A permission refusal is a claim ────────────────────────────────────────

/** The reply is (or holds) the permission refusal line. */
function rh_ai_claim_is_refusal(string $reply): bool
{
    return (bool)preg_match("/outside what (?:your|this) account can see|(?:you|your account) (?:do(?:es)?n't|do(?:es)? not) have (?:access|permission)|not (?:allowed|permitted) to (?:see|view)/iu", str_replace('’', "'", $reply));
}

/**
 * Is a refusal backed by a real permission check? It is only when a lookup this turn was refused by the server, or the
 * topic of this question (and the one before it) belongs only to departments this person does not have. A topic no
 * department covers was never refused by a permission check, so a refusal there is false too.
 * @return array{false:bool, lanes:list<string>} lanes = this person's departments that cover the topic
 */
function rh_ai_claim_refusal_verdict(array $user, string $tokQ, string $prevTok, bool $serverRefused): array
{
    if ($serverRefused) {
        return ['false' => false, 'lanes' => []];
    }
    $mine = rh_ai_lanes_for(rh_ai_specialists_for_user($user), $tokQ);
    if (!$mine && $prevTok !== '') {
        $mine = rh_ai_lanes_for(rh_ai_specialists_for_user($user), $prevTok);
    }
    if ($mine) {
        return ['false' => true, 'lanes' => $mine];
    }
    $all = rh_ai_lanes_for(rh_ai_specialists(), $tokQ . ' ' . $prevTok);
    return ['false' => !$all, 'lanes' => []];
}
