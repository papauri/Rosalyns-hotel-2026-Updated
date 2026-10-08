<?php

/**
 * includes/guide-knowledge-base.php
 *
 * Turns the staff guides (docs/guides/*.html, plus the static part of the PHP guides and the
 * curated faq.html) into one searchable knowledge base. Nothing is maintained by hand: every
 * guide heading, sub-heading, "Problems and fixes" row and FAQ question becomes an entry, so
 * the search stays in step with the guides as they are edited.
 *
 * Used by:
 *   - docs/guides/assets/kb-index.php  (JSON index for the guide pages' search box and FAQ page)
 *   - admin/api/global-search.php      ("Help & guides" results in the admin Ctrl+K search)
 *
 * Entry types:
 *   section  a guide section (h2) or sub-section (h3); h3 entries carry `heading` for landing
 *   problem  one row of a "Problems and fixes" table: what you see / why / what to do
 *   faq      one curated question from faq.html
 *
 * The parsed index is cached in cache/ keyed on the guide files' sizes and modified times.
 */

declare(strict_types=1);

const RH_KB_VERSION = 3;

/** Guide files that make up the knowledge base, in reading order. */
function rh_kb_guide_files(): array
{
    $dir = rh_kb_dir();
    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        if (preg_match('/^\d\d-[\w.-]+\.(html|php)$/', $name) && is_file($dir . '/' . $name)) {
            $files[] = $name;
        }
    }
    sort($files, SORT_NATURAL);
    if (is_file($dir . '/faq.html')) {
        $files[] = 'faq.html';
    }
    return $files;
}

function rh_kb_dir(): string
{
    return dirname(__DIR__) . '/docs/guides';
}

/** Lower-case, unify spellings staff use interchangeably, and reduce to words. */
function rh_kb_normalize(string $s): string
{
    $s = mb_strtolower(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
    $s = strtr($s, ['’' => "'", '‘' => "'"]);
    // "check a guest in" -> "checkin a guest", so it matches "check-in"
    $s = preg_replace('/\bcheck((?:\s+(?:a|an|the|your|this|that|guests?|members?|him|her|them|someone|people))+)\s+(in|out)\b/u', 'check$2$1', $s) ?? $s;
    $s = preg_replace('/\b(check)[\s-]?(in|out)s?\b/u', '$1$2', $s) ?? $s; // one word: "in" is filler, "checkin" is not
    $s = preg_replace('/\b(log|sign)[\s-]?(in|on)\b/u', 'signin', $s) ?? $s;
    $s = preg_replace('/\b(log|sign)[\s-]?(out|off)\b/u', 'signout', $s) ?? $s;
    $s = preg_replace('/\b(e-?mail)s?\b/u', 'email', $s) ?? $s;
    $s = preg_replace('/\bset-?up\b/u', 'set up', $s) ?? $s;
    $s = preg_replace('/\bwalk[\s-]in(s?)\b/u', 'walkin$1', $s) ?? $s;
    $s = preg_replace('/\bno[\s-]shows?\b/u', 'noshow', $s) ?? $s;
    $s = preg_replace('/\bz[\s-]?reports?\b/u', 'zreport', $s) ?? $s;
    $s = preg_replace("/[^\p{L}\p{N}]+/u", ' ', $s) ?? $s;
    return ' ' . trim(preg_replace('/\s+/u', ' ', $s) ?? $s) . ' ';
}

/** Words staff use for the same thing. Any word in a group also finds the others. */
function rh_kb_synonym_groups(): array
{
    return [
        ['signin', 'login', 'logon'], ['signout', 'logout'], ['password', 'pwd', 'passcode'],
        ['pos', 'till', 'cashier', 'register'], ['folio', 'bill', 'charges'], ['vat', 'tax'],
        ['booking', 'reservation', 'reservations', 'bookings', 'rsvp', 'rsvps'], ['guest', 'customer', 'client', 'visitor'],
        ['housekeeping', 'cleaning', 'clean', 'dirty', 'housekeeper', 'cleaner'],
        ['maintenance', 'repair', 'broken', 'fault', 'faulty'],
        ['kds', 'kitchen', 'chef', 'cook'], ['bds', 'bar', 'drinks', 'bartender'], ['cds', 'coffee', 'barista'],
        ['staff', 'user', 'users', 'employee', 'employees', 'worker'],
        ['permission', 'permissions', 'access', 'rights', 'allow', 'privilege'],
        ['module', 'modules', 'feature', 'features'],
        ['refund', 'reimburse', 'reimbursement', 'repay'],
        ['quotation', 'quote', 'estimate', 'proforma'],
        ['deal', 'promo', 'promotion', 'coupon', 'offer'],
        ['ingredient', 'stock', 'inventory'], ['supplier', 'vendor'],
        ['wastage', 'waste', 'spoil', 'spoilage', 'spoiled', 'expired'],
        ['takings', 'revenue', 'sales', 'income', 'earnings'],
        ['price', 'rate', 'tariff', 'cost', 'pricing'],
        ['cancel', 'cancellation', 'cancelled'], ['delete', 'remove', 'erase'],
        ['edit', 'change', 'modify', 'update', 'amend'],
        ['print', 'printer', 'reprint'], ['offline', 'internet', 'connection', 'network', 'wifi'],
        ['email', 'mail'], ['backup', 'backups', 'restore'], ['member', 'membership', 'members'],
        ['conference', 'meeting', 'boardroom'], ['event', 'events', 'function', 'wedding', 'party'],
        ['checkin', 'arrival', 'arrive', 'arriving', 'arrivals'], ['checkout', 'departure', 'depart', 'leaving', 'departures'],
        ['photo', 'image', 'picture', 'gallery', 'media', 'video'], ['website', 'site', 'web'],
        ['invoice', 'invoices'], ['receipt', 'receipts'], ['payment', 'pay', 'paid', 'deposit'],
        ['discount', 'reduction'], ['debt', 'owe', 'owes', 'owing', 'outstanding', 'balance'],
        ['report', 'reports', 'summary', 'analytics'], ['role', 'roles', 'position', 'job'],
        ['create', 'make', 'new', 'add'], ['ticket', 'tickets', 'order', 'orders'],
        ['86', 'unavailable'], ['offline', 'online'], ['assign', 'allocate'],
        ['join', 'joined', 'merge', 'combine', 'adjoining', 'connecting'],
    ];
}

/** Everyday phrases rewritten to the words the guides use (query side only). */
function rh_kb_expand_phrases(string $q): string
{
    $map = [
        '/\b(sold out|out of stock|run out|ran out|finished)\b/i' => ' unavailable 86 ',
        '/\bmoney back\b/i' => ' refund ',
        '/\b(no internet|internet (is )?(down|off|gone|dropped|not working)|connection (lost|down|dropped)|wi-?fi (is )?down)\b/i' => ' offline ',
        '/\bwalk[\s-]?ins?\b/i' => ' walkin create booking ',
        '/\b(sign(ed)? me out|kicked out|logged out)\b/i' => ' signed out ',
        '/\b(room number|physical room)\b/i' => ' assign room ',
        '/\b(not working|doesn\'?t work|won\'?t work|broken|error)\b/i' => ' problem ',
    ];
    foreach ($map as $re => $add) {
        if (preg_match($re, $q)) {
            $q .= $add;
        }
    }
    return $q;
}

/**
 * Words of a search query with filler removed and a light stem applied.
 * Each term: ['stem' => stem, 'alts' => [synonym stems and a spelling correction]].
 */
function rh_kb_query_terms(string $q, ?array $vocab = null, array &$corrected = []): array
{
    static $stop = ['a', 'an', 'the', 'to', 'do', 'i', 'how', 'what', 'is', 'are', 'can', 'my', 'of', 'for', 'in', 'on',
        'and', 'or', 'does', 'why', 'when', 'where', 'it', 'me', 'you', 'with', 'be', 'we', 'our', 'this', 'that', 'at',
        'from', 'there', 'should', 'need', 'want', 'which', 'who', 'please', 'help', 'get', 'go', 'find', 'see', 'its', 'am', 'was', 'has', 'have', 'did',
        // words that say what kind of answer is wanted (see $want in rh_kb_search), not what it is about
        'cannot', 'cant', 't', 'not', 'dont', 'doesnt', 'isnt', 'wont', 'missing', 'hidden', 'appear', 'appears', 'show', 'shows', 'showing', 'locate', 'where', 'page', 'screen', 'down'];
    static $syn = null;
    if ($syn === null) {
        $syn = [];
        foreach (rh_kb_synonym_groups() as $group) {
            foreach ($group as $w) {
                foreach ($group as $other) {
                    if ($other !== $w) {
                        $syn[$w][] = rh_kb_stem($other);
                    }
                }
            }
        }
    }
    $split = static function (string $s): array {
        return array_values(array_unique(array_filter(explode(' ', trim(rh_kb_normalize($s))), 'strlen')));
    };
    $words = $split($q);
    // Words added by rh_kb_expand_phrases() only raise a result; they are never required.
    $extra = array_diff($split(rh_kb_expand_phrases($q)), $words);
    $kept = array_values(array_filter($words, static function ($w) use ($stop) {
        return !in_array($w, $stop, true);
    }));
    if (!$kept) {
        $kept = $words;
    }
    $optional = array_flip(array_diff($extra, $stop));
    $terms = [];
    foreach (array_map('strval', array_merge($kept, array_keys($optional))) as $w) { // "86" must stay a string
        $stem = rh_kb_stem($w);
        $alts = $syn[$w] ?? ($syn[$stem] ?? []);
        if ($vocab !== null && mb_strlen($w) >= 4 && !preg_match('/\d/', $w)) {
            foreach (rh_kb_correct($stem, $vocab) as $fix) {
                $alts[] = $fix;
                $corrected[$w][] = $fix;
                foreach ($syn[$fix] ?? [] as $s) {
                    $alts[] = $s;
                }
            }
        }
        $alts = array_values(array_unique(array_diff($alts, [$stem])));
        $terms[] = ['stem' => $stem, 'alts' => $alts, 'optional' => isset($optional[$w])];
    }
    return array_slice($terms, 0, 12);
}

/**
 * Closest words in the knowledge base for a misspelt word ("recieve" -> "receive").
 * Empty when the word is already known (some indexed word starts with it) or nothing is close.
 * Every candidate at the best distance is returned (at most 3), and the ranking decides.
 */
function rh_kb_correct(string $stem, array $vocab): array
{
    $len = strlen($stem);
    foreach ($vocab as $v) {
        if (strncmp($v, $stem, $len) === 0) {
            return [];
        }
    }
    $max = $len >= 7 ? 2 : 1;
    $best = [];
    $bestD = $max + 1;
    foreach ($vocab as $v) {
        if (abs(strlen($v) - $len) > $max) {
            continue;
        }
        $d = rh_kb_distance($stem, $v);
        if ($d < $bestD) {
            $bestD = $d;
            $best = [$v];
        } elseif ($d === $bestD) {
            $best[] = $v;
        }
    }
    return array_slice($best, 0, 3);
}

/** Edit distance where swapping two neighbouring letters ("ie" / "ei") counts as one mistake. */
function rh_kb_distance(string $a, string $b): int
{
    $la = strlen($a);
    $lb = strlen($b);
    $d = [];
    for ($i = 0; $i <= $la; $i++) {
        $d[$i][0] = $i;
    }
    for ($j = 0; $j <= $lb; $j++) {
        $d[0][$j] = $j;
    }
    for ($i = 1; $i <= $la; $i++) {
        for ($j = 1; $j <= $lb; $j++) {
            $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
            $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
            if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
            }
        }
    }
    return $d[$la][$lb];
}

function rh_kb_stem(string $w): string
{
    if (mb_strlen($w) <= 4) {
        return $w;
    }
    $s = preg_replace('/(ies)$/u', 'y', $w) ?? $w;
    if ($s === $w) {
        // "batches" -> "batch", "taxes" -> "tax", but "recipes" -> "recipe" (not "recip")
        $s = preg_replace('/((?:ss|x|z|ch|sh)es|ing|ed|(?<!s)s)$/u', '', $w) ?? $w;
        if ($s !== $w && preg_match('/(ss|x|z|ch|sh)es$/u', $w)) {
            $s = substr($w, 0, -2);
        }
    }
    return mb_strlen($s) >= 3 ? $s : $w;
}

/** Number of word-start matches of a stem in a normalized haystack (capped). */
function rh_kb_hits(string $hay, string $stem): int
{
    if ($stem === '') {
        return 0;
    }
    // Short words match whole words only ("tab" must not find "table", "vat" not "private").
    return min(5, substr_count($hay, ' ' . $stem . (mb_strlen($stem) <= 3 ? ' ' : '')));
}

// ---------------------------------------------------------------------------------------------
// Building the index
// ---------------------------------------------------------------------------------------------

/** The whole knowledge base: ['v' => signature, 'guides' => [...], 'entries' => [...]]. */
function rh_kb_index(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $dir = rh_kb_dir();
    $files = rh_kb_guide_files();
    require_once __DIR__ . '/guide-system-knowledge.php';
    $sigParts = [RH_KB_VERSION, (int)@filemtime(__FILE__)]; // a parser change rebuilds the cache too
    foreach ($files as $f) {
        $sigParts[] = $f . ':' . (int)@filesize($dir . '/' . $f) . ':' . (int)@filemtime($dir . '/' . $f);
    }
    foreach (rh_kb_sys_sources() as $f) { // the menu, permissions and modules the system knowledge reads
        $sigParts[] = basename($f) . ':' . (int)@filesize($f) . ':' . (int)@filemtime($f);
    }
    $sig = substr(md5(implode('|', $sigParts)), 0, 12);

    $cacheDir = dirname(__DIR__) . '/cache';
    $cacheFile = $cacheDir . '/guide-kb-index.json';
    if (is_file($cacheFile)) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['v'] ?? '') === $sig) {
            return $memo = $cached;
        }
    }

    $guides = [];
    $entries = [];
    foreach ($files as $file) {
        $parsed = rh_kb_parse_file($dir . '/' . $file, $file);
        if ($parsed === null) {
            continue;
        }
        $guides[] = $parsed['guide'];
        foreach ($parsed['entries'] as $e) {
            $e['id'] = count($entries);
            $entries[] = $e;
        }
    }
    try {
        foreach (rh_kb_sys_entries() as $e) {
            $e['id'] = count($entries);
            $entries[] = $e;
        }
        $guides[] = ['file' => 'system-map.php', 'title' => 'System map', 'intro' => 'Every menu page, permission, role and module, read from the system itself.', 'who' => 'Everyone'];
    } catch (Throwable $e) {
        error_log('guide kb: system knowledge unavailable: ' . $e->getMessage());
    }

    // Every indexed word of 4+ letters, for spelling correction.
    $vocab = [];
    foreach ($entries as $e) {
        foreach (explode(' ', rh_kb_normalize($e['title'] . ' ' . $e['text'])) as $w) {
            if (strlen($w) >= 4 && !ctype_digit($w)) {
                $vocab[rh_kb_stem($w)] = true;
            }
        }
    }
    $memo = ['v' => $sig, 'guides' => $guides, 'entries' => $entries, 'vocab' => array_keys($vocab)];

    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($memo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) !== false) {
            @rename($tmp, $cacheFile);
        }
    }
    return $memo;
}

function rh_kb_text(?DOMNode $n): string
{
    if ($n === null) {
        return '';
    }
    return trim(preg_replace('/\s+/u', ' ', $n->textContent) ?? '');
}

/** Parse one guide file into its guide record and entries. Null when unreadable. */
function rh_kb_parse_file(string $path, string $file): ?array
{
    $html = @file_get_contents($path);
    if ($html === false || $html === '') {
        return null;
    }
    // PHP guides: index only their static HTML (their PHP output depends on live settings).
    $html = preg_replace('/<\?(php|=).*?(\?>|$)/s', ' ', $html) ?? $html;

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($doc);

    $h1 = $xp->query('//h1')->item(0);
    $title = rh_kb_text($h1);
    if ($title === '') {
        $title = preg_replace('/\s+[—-]\s+.*$/u', '', rh_kb_text($xp->query('//title')->item(0))) ?? $file;
    }
    $intro = '';
    for ($n = $h1 ? $h1->nextSibling : null; $n; $n = $n->nextSibling) {
        if ($n instanceof DOMElement && $n->tagName === 'p') {
            $intro = rh_kb_text($n);
            break;
        }
    }
    $who = '';
    foreach ($xp->query('//div[contains(@class,"facts")]//dt') as $dt) {
        if (stripos(rh_kb_text($dt), 'who uses') === 0 && $dt->nextSibling) {
            $dd = $dt->nextSibling;
            while ($dd && !($dd instanceof DOMElement)) {
                $dd = $dd->nextSibling;
            }
            $who = rh_kb_text($dd);
        }
    }

    $guide = ['file' => $file, 'title' => $title, 'intro' => mb_substr($intro, 0, 300), 'who' => $who];
    $entries = [];

    if ($file === 'faq.html') {
        foreach ($xp->query('//details[@id]') as $d) {
            /** @var DOMElement $d */
            $summary = $xp->query('./summary', $d)->item(0);
            $q = rh_kb_text($summary);
            if ($summary) {
                $d->removeChild($summary);
            }
            $groupEl = $xp->query('preceding::h2[1]', $d)->item(0);
            $entries[] = [
                'type' => 'faq', 'file' => $file, 'guide' => $title, 'anchor' => $d->getAttribute('id'),
                'title' => $q, 'group' => rh_kb_text($groupEl), 'text' => rh_kb_text($d),
            ];
        }
        return ['guide' => $guide, 'entries' => $entries];
    }

    $wrap = $xp->query('//div[contains(concat(" ",normalize-space(@class)," ")," wrap ")]')->item(0)
        ?: $xp->query('//body')->item(0);
    if (!$wrap) {
        return ['guide' => $guide, 'entries' => $entries];
    }

    // The guide itself, so a search for its subject finds the guide's front page.
    // Its facts box (who uses it, where to find it, permission, module) says which pages it covers.
    $facts = rh_kb_text($xp->query('//div[contains(@class,"facts")]')->item(0));
    $entries[] = ['type' => 'section', 'file' => $file, 'guide' => $title, 'anchor' => '', 'title' => $title,
        'text' => trim($intro . ' ' . ($facts !== '' ? $facts : $who))];

    $h2 = null;          // current section entry
    $h3 = null;          // current sub-section entry
    $flush = static function (?array &$e) use (&$entries) {
        if ($e !== null) {
            $e['text'] = trim(preg_replace('/\s+/u', ' ', $e['text']) ?? '');
            if ($e['text'] !== '' || ($e['steps'] ?? [])) {
                $entries[] = $e;
            }
        }
        $e = null;
    };

    foreach ($wrap->childNodes as $n) {
        if (!($n instanceof DOMElement)) {
            continue;
        }
        $tag = strtolower($n->tagName);
        if ($tag === 'h2') {
            $flush($h3);
            $flush($h2);
            $id = $n->getAttribute('id');
            if ($id === '' || $id === 'related') {
                continue;
            }
            $h2 = ['type' => 'section', 'file' => $file, 'guide' => $title, 'anchor' => $id, 'title' => rh_kb_text($n), 'text' => ''];
            continue;
        }
        if ($h2 === null || in_array($tag, ['nav', 'footer', 'script', 'style'], true)) {
            continue;
        }
        if ($tag === 'h3') {
            $flush($h3);
            $h3 = ['type' => 'section', 'file' => $file, 'guide' => $title, 'anchor' => $h2['anchor'],
                'parent' => $h2['title'], 'heading' => rh_kb_text($n), 'title' => rh_kb_text($n), 'text' => ''];
            $h2['text'] .= ' ' . $h3['title'] . '.';
            continue;
        }

        // "Problems and fixes" tables: one entry per row.
        if ($tag === 'table' && $h2['anchor'] === 'problems') {
            foreach ($xp->query('.//tbody/tr', $n) as $tr) {
                $cells = $xp->query('./td', $tr);
                if ($cells->length < 2) {
                    continue;
                }
                // Several messages in one cell are quoted: "A." / "B." or "A." or "B." -> A. / B.
                $see = rh_kb_text($cells->item(0));
                $see = preg_replace('/["“”]\s*(\/|or)\s*["“”]/u', ' $1 ', $see) ?? $see;
                $why = $cells->length >= 3 ? rh_kb_text($cells->item(1)) : '';
                $fix = rh_kb_text($cells->item($cells->length - 1));
                $entries[] = ['type' => 'problem', 'file' => $file, 'guide' => $title, 'anchor' => 'problems',
                    'title' => preg_replace('/^[\s"“”]+|[\s"“”]+$/u', '', $see) ?? $see, 'why' => $why, 'fix' => $fix, 'text' => $why . ' ' . $fix];
                $h2['text'] .= ' ' . $see . '.';
            }
            continue;
        }

        $target = $h3 !== null ? 'h3' : 'h2';
        $text = rh_kb_text($n);
        if ($target === 'h3') {
            $h3['text'] .= ' ' . $text;
            $h2['text'] .= ' ' . $text;
            if ($tag === 'ol' && empty($h3['steps'])) {
                $h3['steps'] = rh_kb_list_items($xp, $n);
            }
        } else {
            $h2['text'] .= ' ' . $text;
            if ($tag === 'ol' && empty($h2['steps'])) {
                $h2['steps'] = rh_kb_list_items($xp, $n);
            }
        }
    }
    $flush($h3);
    $flush($h2);

    // Keep the payload sensible: long reference tables are still searchable by their first part.
    foreach ($entries as &$e) {
        if (mb_strlen($e['text']) > 4000) {
            $e['text'] = mb_substr($e['text'], 0, 4000);
        }
    }
    unset($e);

    return ['guide' => $guide, 'entries' => $entries];
}

function rh_kb_list_items(DOMXPath $xp, DOMElement $ol): array
{
    $out = [];
    foreach ($xp->query('./li', $ol) as $li) {
        $out[] = rh_kb_text($li);
        if (count($out) >= 8) {
            break;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
// Searching
// ---------------------------------------------------------------------------------------------

/**
 * Ranked matches for a query. Every query word must match (title, guide name or text);
 * when nothing matches all words, the closest partial matches are returned with 'partial' => true.
 *
 * @return array{results: array, partial: bool}
 */
function rh_kb_search(string $q, int $limit = 10): array
{
    $index = rh_kb_index();
    $corrected = [];
    $terms = rh_kb_query_terms($q, $index['vocab'] ?? null, $corrected);
    if (!$terms) {
        return ['results' => [], 'partial' => false, 'corrected' => []];
    }
    $required = count(array_filter($terms, static function ($t) {
        return empty($t['optional']);
    })) ?: 1;
    $phrase = trim(rh_kb_normalize($q));
    $howTo = (bool)preg_match('/^(how|can i|what do i|steps)\b/i', trim($q));
    // What kind of answer the question is after.
    $want = [
        'page' => (bool)preg_match('/\b(where|find|locate|menu|page|screen|open|get to|navigate)\b/i', $q),
        'permission' => (bool)preg_match("/\b(permission|access|allow|allowed|rights|grant|who can|can'?t see|cannot see|missing|not see|hidden|greyed)\b/i", $q),
        'role' => (bool)preg_match('/\b(role|roles|what can an?)\b/i', $q),
        'module' => (bool)preg_match('/\b(module|modules|switch(ed)? (on|off)|turn (on|off)|enable|disable|feature)\b/i', $q),
        'hint' => (bool)preg_match('/\b(what (does|is|are)|button|field|tick ?box|toggle|mean|means|for\?)\b/i', $q),
    ];
    $full = [];
    $part = [];
    foreach ($index['entries'] as $e) {
        $t = rh_kb_normalize($e['title'] . ' ' . ($e['parent'] ?? ''));
        $g = rh_kb_normalize($e['guide']);
        $x = rh_kb_normalize($e['text']);
        $score = 0;
        $matched = 0;
        $inTitle = 0;
        foreach ($terms as $term) {
            $best = 0;
            $titleHit = false;
            $candidates = array_merge([[$term['stem'], 1.0]], array_map(static function ($a) {
                return [$a, 0.7]; // a synonym or spelling fix counts, but less than the word typed
            }, $term['alts']));
            foreach ($candidates as [$stem, $weight]) {
                $th = min(2, rh_kb_hits($t, $stem)); // a word repeated in a long error message is no stronger
                $titleHit = $titleHit || $th > 0;
                $whole = strpos($t, ' ' . $stem . ' ') !== false ? 6 : 0; // "tab" the word, not "table"
                $best = max($best, $weight * ($th * 12 + $whole + min(1, rh_kb_hits($g, $stem)) * 4 + rh_kb_hits($x, $stem) * 2));
            }
            if (!empty($term['optional'])) {
                $score += $best * 0.6; // a word the question implied, not one it said
                continue;
            }
            if ($best > 0) {
                $matched++;
                $score += $best;
            }
            $inTitle += $titleHit ? 1 : 0;
        }
        if ($matched === 0) {
            continue;
        }
        if ($inTitle === $required) {
            $score += 20; // every word is in the heading itself
            if ($e['type'] === 'section' && stripos($e['title'], 'how to') === 0) {
                $score += 10; // the step-by-step section beats an error message mentioning the same word
            }
        }
        if (trim($t) === $phrase || trim(rh_kb_normalize($e['title'])) === $phrase) {
            $score += 30; // typed the exact name of a page, permission or section
        }
        if ($e['type'] === 'problem' && $howTo) {
            $score -= 12; // "how do I…" wants the steps, not an error message
        }
        if (mb_strlen($phrase) > 3 && strpos($t, ' ' . $phrase) !== false) {
            $score += 25;
        } elseif (mb_strlen($phrase) > 3 && strpos($x, ' ' . $phrase) !== false) {
            $score += 8;
        }
        if (isset($want[$e['type']])) {
            // Generated system answers lead only when the question asks for them.
            if ($e['type'] === 'hint') {
                $score = $want['hint'] ? $score + 20 : $score * 0.7; // one-line hints lead only for "what does X do"
            } else {
                $score = $want[$e['type']] ? $score + 35 : $score * 0.5 - 10;
            }
        } elseif ($e['type'] === 'faq') {
            $score += 4;
        } elseif ($e['type'] === 'problem' && mb_strlen($q) > 18) {
            $score += 6; // a pasted error message
        } elseif ($e['anchor'] === '') {
            $score -= 4; // whole-guide entry only when nothing more specific
        }
        if ($matched === $required) {
            $full[] = [$score, $e];
        } else {
            $part[] = [$score * $matched / $required, $e];
        }
    }
    $partial = !$full;
    $pool = $partial ? $part : $full;
    usort($pool, static function ($a, $b) {
        return $b[0] <=> $a[0];
    });
    $out = [];
    foreach (array_slice($pool, 0, $limit) as [$score, $e]) {
        $e['score'] = round($score, 1);
        $e['snippet'] = rh_kb_snippet($e, $terms);
        $e['url'] = rh_kb_entry_url($e, $q);
        $out[] = $e;
    }
    return ['results' => $out, 'partial' => $partial, 'corrected' => $corrected];
}

/** Short plain-text excerpt around the first query word found. */
function rh_kb_snippet(array $e, array $terms): string
{
    if ($e['type'] === 'problem') {
        return trim(($e['fix'] ?? '') !== '' ? 'Fix: ' . $e['fix'] : ($e['why'] ?? ''));
    }
    if ($e['type'] === 'hint') {
        return (string)$e['text'];
    }
    if (in_array($e['type'], ['page', 'permission', 'role', 'module'], true)) {
        // Generated answers: drop the question phrasings they carry for matching, keep the answer.
        $a = preg_replace('/^(Where is .*?\? Where do I find .*?\? |What can an? .*?\? )/u', '', (string)$e['text']) ?? $e['text'];
        return mb_strlen($a) > 260 ? rtrim(mb_substr($a, 0, 258)) . '…' : $a;
    }
    $text = (string)$e['text'];
    $lower = mb_strtolower($text);
    $pos = false;
    foreach ($terms as $term) {
        // "checkin" in the index is "check-in" / "check in" in the guide text.
        $re = preg_replace('/^(check|sign)(in|out)$/', '$1[\s-]?$2', preg_quote($term['stem'], '/'));
        $p = preg_match('/' . $re . '/u', $lower, $m, PREG_OFFSET_CAPTURE) ? mb_strlen(substr($lower, 0, $m[0][1])) : false;
        if ($p !== false && ($pos === false || $p < $pos)) {
            $pos = $p;
        }
    }
    $start = $pos === false ? 0 : max(0, $pos - 60);
    $snip = mb_substr($text, $start, 180);
    return ($start > 0 ? '…' : '') . trim($snip) . (mb_strlen($text) > $start + 180 ? '…' : '');
}

/** Link to an entry, relative to docs/guides/. ?kb= highlights the words on arrival. */
function rh_kb_entry_url(array $e, string $q = ''): string
{
    $params = [];
    if ($q !== '') {
        $params['kb'] = $q;
    }
    if (!empty($e['heading'])) {
        $params['kbh'] = $e['heading'];
    }
    if ($e['type'] === 'problem') {
        $params['kbh'] = $e['title'];
    }
    return $e['file'] . ($params ? (strpos($e['file'], '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '')
        . ($e['anchor'] !== '' ? '#' . rawurlencode($e['anchor']) : '');
}
