<?php

/**
 * Smarter free-text search for admin lists and the universal search (pure: no database, no settings).
 *
 * - Every word must match somewhere, in any of the columns: "john banda", "banda john" and "BK123 john" all find
 *   the same booking, where the old single LIKE '%john banda%' needed the words side by side in one field.
 * - Phone numbers match whatever the spacing or prefix: "0999 123 456", "+265 999 123 456" and "999-123-456" find
 *   the same guest (digits compared with spaces, dashes, dots, brackets and + taken out; a leading 00 / 265 / 0 is
 *   dropped from what was typed).
 * - % and _ typed by staff match literally (escape character '!', which works whatever MySQL's backslash mode is).
 * - One word gives exactly the old clause: one LIKE per column, all bound to %word%.
 *
 * Column lists are trusted SQL expressions written in code, never user text; user text only ever reaches the
 * bound parameters.
 */

if (!function_exists('rh_search_where')) {

    /** Most words looked at per search (the rest are ignored), so a pasted paragraph cannot build a huge query. */
    if (!defined('RH_SEARCH_MAX_TERMS')) {
        define('RH_SEARCH_MAX_TERMS', 6);
    }

    /** LIKE pattern for "contains $term", with % _ and ! matched literally (pair with ESCAPE '!'). */
    function rh_search_like(string $term): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
    }

    /** LIKE pattern for "starts with $term" (pair with ESCAPE '!'). */
    function rh_search_prefix(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
    }

    /**
     * The digits to look for when $term is a phone number, or '' when it is not one. A phone number is only digits
     * and phone punctuation (+ space - . brackets), with at least 6 digits once the country / trunk prefix is gone.
     */
    function rh_search_phone_core(string $term): string
    {
        $term = trim($term);
        if ($term === '' || !preg_match('/^\+?[0-9][0-9\s().\-]*$/', $term)) {
            return '';
        }
        $d = (string)preg_replace('/\D+/', '', $term);
        if (strpos($d, '00') === 0) {
            $d = substr($d, 2);
        }
        if (strpos($d, '265') === 0 && strlen($d) >= 12) {
            $d = substr($d, 3);
        }
        $d = ltrim($d, '0');
        return strlen($d) >= 6 ? $d : '';
    }

    /**
     * The words to match. A phone number typed with spaces ("0999 123 456") stays one term; repeated words count
     * once; at most RH_SEARCH_MAX_TERMS words.
     * @return string[]
     */
    function rh_search_terms(string $q): array
    {
        $q = trim((string)preg_replace('/\s+/u', ' ', $q));
        if ($q === '') {
            return [];
        }
        if (rh_search_phone_core($q) !== '') {
            return [$q];
        }
        $out = [];
        foreach (explode(' ', $q) as $w) {
            $w = trim($w, ',;');
            if ($w === '') {
                continue;
            }
            $k = function_exists('mb_strtolower') ? mb_strtolower($w) : strtolower($w);
            if (!isset($out[$k])) {
                $out[$k] = $w;
            }
        }
        return array_slice(array_values($out), 0, RH_SEARCH_MAX_TERMS);
    }

    /** A trusted column expression with phone punctuation taken out, for the digits-only comparison. */
    function rh_search_digits_sql(string $col): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$col}, ''), ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), '.', '')";
    }

    /**
     * WHERE fragment: every word matches at least one column.
     *
     * @param string   $q         what staff typed
     * @param string[] $cols      trusted expressions, each tested with LIKE %word%
     * @param string[] $phoneCols trusted phone columns, also compared digits-only when a word is a phone number
     * @param string[] $preds     trusted predicates in which every ? takes the word's %word% pattern (EXISTS sub-queries)
     * @return array{sql:string, params:array} sql is '' when there is nothing to search for
     */
    function rh_search_where(string $q, array $cols, array $phoneCols = [], array $preds = []): array
    {
        $groups = [];
        $params = [];
        foreach (rh_search_terms($q) as $term) {
            $like = rh_search_like($term);
            $parts = [];
            foreach ($cols as $c) {
                $parts[] = $c . " LIKE ? ESCAPE '!'";
                $params[] = $like;
            }
            foreach ($preds as $p) {
                $parts[] = $p;
                for ($i = substr_count($p, '?'); $i > 0; $i--) {
                    $params[] = $like;
                }
            }
            $core = $phoneCols ? rh_search_phone_core($term) : '';
            if ($core !== '') {
                foreach ($phoneCols as $c) {
                    $parts[] = rh_search_digits_sql($c) . " LIKE ? ESCAPE '!'";
                    $params[] = '%' . $core . '%';
                }
            }
            if ($parts) {
                $groups[] = "(\n        " . implode("\n        OR ", $parts) . "\n    )";
            }
        }
        return ['sql' => $groups ? implode(' AND ', $groups) : '', 'params' => $params];
    }
}
