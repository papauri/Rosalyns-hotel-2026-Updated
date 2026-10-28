<?php

/**
 * Misspellings, handled the best way possible (owner 2026-10-29: "Handle all sorts of misspellings"). PHP only - no
 * model call, no extra cost.
 *
 *  1. One shared close-match helper for names in lookups (guests, room types, menu items, stock items, suppliers, gym
 *     members and classes, conference and event names, staff, order tabs). The same rule everywhere: ONE clearly
 *     closest match -> use it and show the real name; several plausible ones (or only a weak one) -> one short "Do you
 *     mean ...?"; none -> say so with the totals. Each lookup reads a fixed-size candidate set (ai_close_match_pool).
 *  2. Words in the question, before the local answers are matched ("arivals", "ocupancy", "brekfast", "tommorow"):
 *     corrected against the local answers' own words (read from those functions) and the month and day names. A word is
 *     corrected only when exactly one candidate is close, the word is not a real word (the guides' vocabulary, common
 *     words), and the correction never turns a question into a change. The person's own text is what is stored.
 * Thresholds and sizes are settings (rh_ai_setting_defaults), never numbers written here.
 */

require_once __DIR__ . '/ai-core.php';

// ── Distances ───────────────────────────────────────────────────────────────

/** Edit distance where two neighbouring letters swapped count as one slip ("Ntahni" / "Nthani"). Multibyte-safe. */
function rh_ai_edit_distance(string $a, string $b): int
{
    $x = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
    $y = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
    $la = count($x);
    $lb = count($y);
    if ($la === 0 || $lb === 0) {
        return max($la, $lb);
    }
    $d = [];
    for ($i = 0; $i <= $la; $i++) {
        $d[$i] = [$i];
    }
    for ($j = 0; $j <= $lb; $j++) {
        $d[0][$j] = $j;
    }
    for ($i = 1; $i <= $la; $i++) {
        for ($j = 1; $j <= $lb; $j++) {
            $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
            $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
            if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
                $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
            }
        }
    }
    return $d[$la][$lb];
}

/** Words before a name that are not part of it. */
function rh_ai_name_titles(): array
{
    return ['mr', 'mrs', 'ms', 'miss', 'mx', 'dr', 'prof', 'sir', 'madam', 'mister', 'missus', 'master'];
}

/** The words of a name or label (lower case, letters only, titles dropped, 3+ letters). */
function rh_ai_name_words(string $s): array
{
    $s = mb_strtolower((string)preg_replace('/\[[A-Z]+_\d+\]/', ' ', $s));
    $titles = array_flip(rh_ai_name_titles());
    $out = [];
    foreach (preg_split('/[^\p{L}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (mb_strlen($w) >= 3 && !isset($titles[$w])) {
            $out[] = $w;
        }
    }
    return array_values(array_unique($out));
}

/**
 * How close two words are, 0 to 1 (length-scaled: one slip in a 4-letter word counts more than in a 9-letter one):
 * 1 - edit distance / length, raised to "sounds the same" (soundex or metaphone, same first letter) when that is higher.
 */
function rh_ai_word_closeness(string $a, string $b): float
{
    $a = mb_strtolower(trim($a));
    $b = mb_strtolower(trim($b));
    if ($a === '' || $b === '') {
        return 0.0;
    }
    if ($a === $b) {
        return 1.0;
    }
    $la = mb_strlen($a);
    $lb = mb_strlen($b);
    $s = max(0.0, 1 - rh_ai_edit_distance($a, $b) / max($la, $lb));
    if (abs($la - $lb) <= 2 && mb_substr($a, 0, 1) === mb_substr($b, 0, 1) && min($la, $lb) >= 4) {
        $sa = soundex($a);
        $ma = metaphone($a);
        if (($sa !== '' && $sa === soundex($b)) || ($ma !== '' && $ma === metaphone($b))) {
            $s = max($s, rh_ai_num('ai_close_match_min_pct') / 100); // sounds the same: plausible, never "clear" on sound alone
        }
    }
    return round($s, 3);
}

/** How close the words asked are to a name or label: [best single word, average over the words asked]. */
function rh_ai_name_closeness(array $asked, string $name): array
{
    $words = rh_ai_name_words($name);
    if (!$asked || !$words) {
        return [0.0, 0.0];
    }
    $per = [];
    foreach ($asked as $w) {
        $best = 0.0;
        foreach ($words as $x) {
            $best = max($best, rh_ai_word_closeness($w, $x));
        }
        $per[] = $best;
    }
    return [max($per), array_sum($per) / count($per)];
}

/** One label against what was asked: [plausible (0..1), score (0..1)] - the average over the words asked, or the whole text when that is closer. */
function rh_ai_fuzzy_score(string $asked, string $label): array
{
    [$best, $avg] = rh_ai_name_closeness(rh_ai_name_words($asked), $label);
    $a = (string)preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($asked));
    $b = (string)preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($label));
    $whole = ($a !== '' && $b !== '') ? max(0.0, 1 - rh_ai_edit_distance($a, $b) / max(mb_strlen($a), mb_strlen($b))) : 0.0;
    $sc = max($avg, $whole); // every word asked counts: "AITest Zzyzx" is not close to "AITest Thandi" just for sharing a word
    return [$sc, $sc];
}

/**
 * THE shared rule. $labels: [key => label]. Plausible = at least ai_close_match_min_pct close; clear = the top one is at
 * least ai_close_match_strong_pct close and ai_close_match_lead_pct ahead of the next plausible one.
 * @return array{clear:?string,options:array,scores:array} clear = key of the one meant; options = keys to ask about (closest first)
 */
function rh_ai_fuzzy_pick(string $asked, array $labels): array
{
    $min = min(100, rh_ai_num('ai_close_match_min_pct')) / 100;
    $strong = min(100, rh_ai_num('ai_close_match_strong_pct')) / 100;
    $lead = min(100, rh_ai_num('ai_close_match_lead_pct')) / 100;
    $scored = [];
    foreach ($labels as $k => $label) {
        [$pl, $sc] = rh_ai_fuzzy_score($asked, (string)$label);
        if ($pl >= $min) {
            $scored[(string)$k] = round($sc, 3);
        }
    }
    arsort($scored);
    $keys = array_keys($scored);
    if (!$keys) {
        return ['clear' => null, 'options' => [], 'scores' => []];
    }
    $top = $scored[$keys[0]];
    $next = isset($keys[1]) ? $scored[$keys[1]] : null;
    if ($top >= $strong && ($next === null || $top - $next >= $lead)) {
        return ['clear' => (string)$keys[0], 'options' => [], 'scores' => $scored];
    }
    return ['clear' => null, 'options' => array_map('strval', array_slice($keys, 0, rh_ai_num('ai_lookup_matches'))), 'scores' => $scored];
}

/**
 * Candidate labels from one table for a name that found nothing, then the shared rule. One query: the latest
 * ai_close_match_pool rows ($order) plus up to ai_close_match_pool whose first or last word sounds the same.
 * $table, $col, $where and $order are written in code (never from a person); $params fill $where's placeholders.
 * @return array{clear:?string,options:array} the matching labels as written in the table
 */
function rh_ai_fuzzy_from_table(PDO $db, string $asked, string $table, string $col, string $where = '1=1', array $params = [], string $order = 'id DESC'): array
{
    $labels = rh_ai_fuzzy_labels($db, $asked, $table, $col, $where, $params, $order);
    $p = rh_ai_fuzzy_pick($asked, $labels ? array_combine($labels, $labels) : []);
    return ['clear' => $p['clear'], 'options' => $p['options']];
}

/** The candidate labels behind rh_ai_fuzzy_from_table() (one query, at most 2 x ai_close_match_pool). */
function rh_ai_fuzzy_labels(PDO $db, string $asked, string $table, string $col, string $where = '1=1', array $params = [], string $order = 'id DESC'): array
{
    $words = rh_ai_name_words($asked);
    if (!$words || preg_match('/[@]/', $asked)) {
        return [];
    }
    usort($words, static fn($x, $y) => mb_strlen($y) <=> mb_strlen($x));
    $n = rh_ai_num('ai_close_match_pool');
    $sql = "(SELECT $col AS label FROM $table WHERE ($where) AND $col IS NOT NULL AND $col <> '' ORDER BY $order LIMIT $n)
            UNION (SELECT $col AS label FROM $table WHERE ($where) AND $col IS NOT NULL AND $col <> ''
                   AND (SOUNDEX(SUBSTRING_INDEX(TRIM($col), ' ', 1)) = SOUNDEX(?) OR SOUNDEX(SUBSTRING_INDEX(TRIM($col), ' ', -1)) = SOUNDEX(?)) LIMIT $n)";
    try {
        $st = $db->prepare($sql);
        $st->execute(array_merge($params, $params, [$words[0], $words[0]]));
        return array_values(array_unique(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $e) {
        return [];
    }
}

/** "Do you mean A, B or C?" from labels. */
function rh_ai_did_you_mean(array $labels): string
{
    $labels = array_values(array_map('strval', $labels));
    if (!$labels) {
        return '';
    }
    $last = array_pop($labels);
    return 'Do you mean ' . ($labels ? implode(', ', $labels) . ' or ' : '') . $last . '?';
}

/**
 * The fields a lookup adds when it read a misspelt name as the one meant, or found several close ones. $field: the
 * privacy field for a person's name ('guest_name' or 'staff_name', so the name leaves as a token like [GUEST_3]); ''
 * for things (a room type, a dish, a stock item). The words the person typed are not repeated here.
 */
function rh_ai_fuzzy_note(string $asked, ?string $readAs, array $options = [], string $field = ''): array
{
    $wrap = static fn(string $label) => $field !== '' ? [$field => $label] : $label;
    if ($readAs !== null) {
        return ['read_as' => 'Nothing is on file exactly as typed; read_as_name is clearly the one meant (a slip in the spelling), so it is used. Show that real name in the answer.',
            'read_as_name' => $wrap($readAs)];
    }
    if ($options) {
        return ['close_matches' => array_map($wrap, array_values($options)),
            'ask' => 'Nothing is called exactly that, but close_matches are close. Ask one short question listing them ("Do you mean ... or ...?"); never pick one yourself.'];
    }
    return [];
}

// ── Typos in the question ───────────────────────────────────────────────────

/**
 * The local answers' own words: every word (4+ letters) in the patterns of the functions that recognise a question
 * locally, plus the month and day names and the breakfast words. Read from those functions' source once per request,
 * so the list follows the patterns (no separate word list to keep up to date).
 */
function rh_ai_intent_vocab(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $fns = ['rh_ai_local_intent', 'rh_ai_lookup_intent', 'rh_ai_hotel_fact_intent', 'rh_ai_booking_totals_intent', 'rh_ai_room_rates_intent', 'rh_ai_rates_not_list_re',
        'rh_ai_hotel_fact_question', 'rh_ai_is_briefing_request', 'rh_ai_focus_kind', 'rh_ai_person_search_intent', 'rh_ai_parse_stay_dates', 'rh_ai_parse_party',
        'rh_ai_lane_words', 'rh_ai_occupancy_intent', 'rh_ai_small_talk', 'rh_ai_followup_local', 'rh_ai_count_word',
        'rh_ai_desk_booking_intent', 'rh_ai_desk_upcoming_intent', 'rh_ai_desk_other_days', 'rh_ai_desk_holds_intent', 'rh_ai_desk_blocked_intent'];
    $vocab = [];
    $files = [];
    foreach ($fns as $fn) {
        if (!function_exists($fn)) {
            continue;
        }
        try {
            $rf = new ReflectionFunction($fn);
            $file = (string)$rf->getFileName();
            $files[$file] = $files[$file] ?? @file($file);
            if (!is_array($files[$file])) {
                continue;
            }
            $src = implode('', array_slice($files[$file], $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1));
        } catch (Throwable $e) {
            continue;
        }
        foreach (token_get_all('<?php ' . $src) as $t) {
            if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $s = mb_strtolower(substr($t[1], 1, -1));
            $s = (string)preg_replace('/\\\\[wWpP](\{[^}]*\})?/u', '#', $s); // \w, \p{L}: the word goes on - a fragment, not a word
            $s = (string)preg_replace('/\\\\./u', ' ', $s);                 // \b, \s, \d ...
            if (preg_match_all('/(?<![a-z])[a-z]{4,}(?![a-z#])/u', $s, $m)) {
                foreach ($m[0] as $w) {
                    $vocab[$w] = true;
                }
            }
        }
    }
    if (function_exists('rh_ai_tool_registry')) { // the read tools' own fixed values ("available", "occupied", "overview" ...)
        foreach (rh_ai_tool_registry() as $def) {
            foreach ((array)($def['schema']['properties'] ?? []) as $p) {
                foreach ((array)($p['enum'] ?? []) as $e) {
                    foreach (preg_split('/[^a-z]+/', mb_strtolower((string)$e), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                        if (mb_strlen($w) >= 4) {
                            $vocab[$w] = true;
                        }
                    }
                }
            }
        }
    }
    for ($i = 1; $i <= 12; $i++) {
        $vocab[mb_strtolower(date('F', mktime(0, 0, 0, $i, 1, 2026)))] = true;
    }
    for ($i = 0; $i < 7; $i++) {
        $vocab[mb_strtolower(date('l', strtotime('2026-11-01 +' . $i . ' days')))] = true;
    }
    foreach (rh_ai_breakfast_words() as $w) {
        foreach (preg_split('/\s+/u', $w) as $x) {
            if (mb_strlen($x) >= 4) {
                $vocab[$x] = true;
            }
        }
    }
    return $memo = $vocab;
}

/** The guides' own vocabulary (real words used in this system), read only when a word might be a typo. */
function rh_ai_real_words(): array
{
    static $memo = null;
    if ($memo === null) {
        $memo = [];
        // The guides' index as last saved (cache/guide-kb-index.json). Never built here: building it takes long, and a
        // slightly old word list is fine for telling a real word from a slip.
        $file = dirname(__DIR__, 2) . '/cache/guide-kb-index.json';
        $j = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        $memo = rh_ai_real_words_from(is_array($j) ? (array)($j['entries'] ?? []) : []);
    }
    return $memo;
}

/**
 * Real words from guide entries (title + text). Quoted text is left out: the guides quote what staff type, typos
 * included ("arivals tomorow"), and a quoted typo must never count as a real word (owner fault 2026-10-29).
 */
function rh_ai_real_words_from(array $entries): array
{
    $out = [];
    foreach ($entries as $e) {
        $t = mb_strtolower(html_entity_decode((string)($e['title'] ?? '') . ' ' . (string)($e['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $t = (string)preg_replace('/"[^"\n]{1,120}"|“[^”\n]{1,120}”/u', ' ', $t);
        foreach (preg_split('/[^\p{L}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (mb_strlen($w) >= 4) {
                $out[$w] = true;
                if (function_exists('rh_kb_stem')) {
                    $out[rh_kb_stem($w)] = true;
                }
            }
        }
    }
    return $out;
}

/** Is this text a request to change something? (the same tests the Assistant uses to decide on cards and refusals) */
function rh_ai_text_is_write(string $t): bool
{
    $l = mb_strtolower($t);
    $l = (string)preg_replace('/\bcheck.?(in|out)\s+(time|times|hours?)\b/u', 'checktime', $l); // "check-in times" is a question
    if (preg_match('/\bcheck(ed|ing)?\s+(him|her|them|the guest|this guest|\[guest_\d+\]|room\s+\S+|[a-z]+\s+[a-z]+)?\s*(in|out)\b/u', $l)) {
        return true; // checking someone in or out
    }
    return function_exists('rh_ai_focus_is_change') && rh_ai_focus_is_change((string)preg_replace('/^\s*(please\s+)?check\b/u', 'look at', $l));
}

/** The one vocabulary word a typo is meant to be, or null (none close, or more than one). */
function rh_ai_typo_candidate(string $w, array $vocab): ?string
{
    $len = mb_strlen($w);
    $allowed = $len >= rh_ai_num('ai_typo_two_edit_len') ? 2 : 1;
    $best = [];
    $bestD = $allowed + 1;
    foreach (array_keys($vocab) as $v) {
        $v = (string)$v;
        $lv = mb_strlen($v);
        if (abs($lv - $len) > $allowed || ($len < 5 && mb_substr($v, 0, 1) !== mb_substr($w, 0, 1))) {
            continue;
        }
        $d = rh_ai_edit_distance($w, $v);
        if ($d < 1 || $d > $allowed) {
            continue;
        }
        // A slip keeps the sound of the word ("arivals" / "arrivals"); a different word does not ("towels" / "totals")
        if (metaphone($v) !== metaphone($w) && soundex($v) !== soundex($w)) {
            continue;
        }
        if ($d < $bestD) {
            $bestD = $d;
            $best = [$v];
        } elseif ($d === $bestD) {
            $best[] = $v;
        }
    }
    if (count($best) > 1) { // a tie: the one that sounds exactly the same, if only one does
        $mw = metaphone($w);
        $best = array_values(array_filter($best, static fn($v) => metaphone($v) === $mw));
    }
    return count($best) === 1 ? $best[0] : null;
}

/**
 * Correct typos in the question's own words. Names (a capital letter after the first word, after a title, privacy
 * tokens) and short words are left alone; a real word is never changed; a change that would turn the question into a
 * request to change something is never made. $extra: more words the hotel uses (its room type names).
 * @return array{text:string,fixed:array} fixed = [typed => read as]
 */
function rh_ai_typo_fix(string $text, array $extra = []): array
{
    $out = ['text' => $text, 'fixed' => []];
    if (trim($text) === '' || mb_strlen($text) > 400 || !preg_match_all('/\[[A-Z]+_\d+\]|\p{L}[\p{L}\'’]*/u', $text, $m, PREG_OFFSET_CAPTURE)) {
        return $out;
    }
    $vocab = rh_ai_intent_vocab();
    foreach ($extra as $x) {
        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower((string)$x), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (mb_strlen($w) >= 3) {
                $vocab[$w] = true;
            }
        }
    }
    $known = rh_ai_vault_stopwords() + $vocab;
    $minLen = rh_ai_num('ai_typo_min_len');
    $titles = array_flip(rh_ai_name_titles());
    $edits = [];
    $prev = '';
    foreach ($m[0] as $i => [$word, $off]) {
        $lw = mb_strtolower($word);
        $after = $prev;
        $prev = $lw;
        if ($word[0] === '[' || isset($titles[$after]) || preg_match('/[\'’]/u', $word) || mb_strlen($lw) < $minLen || isset($known[$lw])) {
            continue;
        }
        if ($i > 0 && preg_match('/^\p{Lu}/u', $word)) {
            continue; // a name
        }
        $cand = rh_ai_typo_candidate($lw, $vocab);
        if ($cand === null) {
            continue;
        }
        $real = rh_ai_real_words();
        if (isset($real[$lw]) || (function_exists('rh_kb_stem') && isset($real[rh_kb_stem($lw)]))) {
            continue; // a real word: never "corrected"
        }
        $edits[] = [$off, strlen($word), preg_match('/^\p{Lu}/u', $word) ? mb_strtoupper(mb_substr($cand, 0, 1)) . mb_substr($cand, 1) : $cand, $word];
    }
    $fixed = $text;
    foreach (array_reverse($edits) as [$off, $blen, $new, $old]) {
        $try = substr($fixed, 0, $off) . $new . substr($fixed, $off + $blen);
        if (!rh_ai_text_is_write($fixed) && rh_ai_text_is_write($try)) {
            continue; // never turn a question into a change ("boko" is never "book")
        }
        $fixed = $try;
        $out['fixed'][$old] = $new;
    }
    $out['text'] = $fixed;
    $out['fixed'] = array_reverse($out['fixed'], true);
    return $out;
}

/** The short line for the model about words read as typos ('' when none). */
function rh_ai_typo_note(array $fixed): string
{
    if (!$fixed) {
        return '';
    }
    $p = [];
    foreach ($fixed as $a => $b) {
        $p[] = '"' . $a . '" = "' . $b . '"';
    }
    return 'TYPOS READ AS: ' . implode(', ', array_slice($p, 0, 8)) . '.';
}
