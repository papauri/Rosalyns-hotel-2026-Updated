<?php
/**
 * Import engine - small pure text/number/date helpers shared by the passes.
 * No database, no hotel-specific words.
 */

if (!function_exists('import_norm_text')) {

    function import_fold_accents(string $s): string
    {
        static $map = null;
        if ($map === null) {
            $map = [
                'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
                'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
                'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
                'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
                'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
                'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
            ];
        }
        return strtr($s, $map);
    }

    /** lowercase, accents folded, every run of non letter/digit (emoji, punctuation, newlines) becomes one space. */
    function import_norm_text($s): string
    {
        $s = mb_strtolower((string)$s, 'UTF-8');
        $s = import_fold_accents($s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        return trim((string)$s);
    }

    /** "As written": trimmed with every run of whitespace (incl. NBSP and newlines) collapsed to one space. */
    function import_norm_ws($s): string
    {
        $s = (string)$s;
        $s = preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $s);
        return trim((string)$s);
    }

    function import_is_empty_marker($s): bool
    {
        if ($s === null) {
            return true;
        }
        $t = mb_strtolower(trim(str_replace("\xC2\xA0", ' ', (string)$s)), 'UTF-8');
        return in_array($t, ['', '-', '--', '---', "\xE2\x80\x94", "\xE2\x80\x93", "\xE2\x88\x92", 'n/a', 'na', 'nil', '.', '...'], true);
    }

    /**
     * Parse a number written by a person.
     * Returns ['status'=>'ok'|'empty'|'invalid', 'value'=>int|float|null, 'neg'=>bool]
     * (a negative number is reported with neg=true and its negative value; callers decide).
     */
    function import_parse_number($v): array
    {
        if ($v === null) {
            return ['status' => 'empty', 'value' => null, 'neg' => false];
        }
        if (is_bool($v)) {
            return ['status' => 'invalid', 'value' => null, 'neg' => false];
        }
        if (is_int($v) || is_float($v)) {
            if (is_float($v) && (is_nan($v) || is_infinite($v))) {
                return ['status' => 'invalid', 'value' => null, 'neg' => false];
            }
            return ['status' => 'ok', 'value' => $v, 'neg' => $v < 0];
        }
        $s = trim(str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', (string)$v));
        if (import_is_empty_marker($s)) {
            return ['status' => 'empty', 'value' => null, 'neg' => false];
        }
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            $neg = true;
            $s = trim($m[1]);
        }
        $s = str_replace("\xE2\x88\x92", '-', $s);
        if ($s !== '' && $s[0] === '-') {
            $neg = true;
            $s = ltrim(substr($s, 1));
        } elseif ($s !== '' && $s[0] === '+') {
            $s = ltrim(substr($s, 1));
        }
        $s = preg_replace('/^(?:mwk|mk|usd|zar|kes|tzs|gbp|eur|\$|£|€|k)\s*(?=[\d.])/iu', '', $s);
        $s = preg_replace('/(?<=\d)\s*(?:mwk|mk|usd|zar|kes|tzs|gbp|eur)$/iu', '', $s);
        $s = trim($s);
        if (preg_match('/^\d{1,3}(?:[, ]\d{3})+(?:\.\d+)?$/', $s)) {
            $n = str_replace([',', ' '], '', $s);
        } elseif (preg_match('/^\d+(?:\.\d+)?$/', $s) || preg_match('/^\.\d+$/', $s)) {
            $n = $s;
        } elseif (preg_match('/^\d+,\d{1,2}$/', $s)) {
            $n = str_replace(',', '.', $s);
        } else {
            return ['status' => 'invalid', 'value' => null, 'neg' => false];
        }
        $f = (float)$n;
        $val = (floor($f) == $f && abs($f) < 1e15 && strpos($n, '.') === false) ? (int)$f : $f;
        if ($neg) {
            $val = -$val;
        }
        return ['status' => 'ok', 'value' => $val, 'neg' => $neg && $val != 0];
    }

    /**
     * Parse a date written as text. Returns
     * ['ok'=>bool,'iso'=>string|null,'ambiguous'=>bool,'alt'=>string|null,'how'=>'as-written'|'converted-date']
     */
    function import_parse_date_string(string $s): array
    {
        $s = trim($s);
        $none = ['ok' => false, 'iso' => null, 'ambiguous' => false, 'alt' => null, 'how' => 'as-written'];
        if ($s === '') {
            return $none;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ]\d{1,2}:\d{2}(?::\d{2})?)?$/', $s, $m)) {
            if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                $iso = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                return ['ok' => true, 'iso' => $iso, 'ambiguous' => false, 'alt' => null, 'how' => (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? 'as-written' : 'converted-date')];
            }
            return $none;
        }
        if (preg_match('/^(\d{4})[\/.](\d{1,2})[\/.](\d{1,2})$/', $s, $m)) {
            if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return ['ok' => true, 'iso' => sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), 'ambiguous' => false, 'alt' => null, 'how' => 'converted-date'];
            }
            return $none;
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/', $s, $m)) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            $y = (int)$m[3];
            if ($y < 100) {
                $y += 2000;
            }
            $dmy = checkdate($b, $a, $y) ? sprintf('%04d-%02d-%02d', $y, $b, $a) : null;
            $mdy = checkdate($a, $b, $y) ? sprintf('%04d-%02d-%02d', $y, $a, $b) : null;
            if ($dmy && $mdy && $dmy !== $mdy) {
                return ['ok' => true, 'iso' => $dmy, 'ambiguous' => true, 'alt' => $mdy, 'how' => 'converted-date'];
            }
            $iso = $dmy ?: $mdy;
            return $iso ? ['ok' => true, 'iso' => $iso, 'ambiguous' => false, 'alt' => null, 'how' => 'converted-date'] : $none;
        }
        foreach (['j M Y', 'j F Y', 'j-M-Y', 'j-M-y', 'M j, Y', 'F j, Y', 'M j Y', 'F j Y'] as $fmt) {
            $d = DateTime::createFromFormat('!' . $fmt, $s);
            $err = DateTime::getLastErrors();
            if ($d && (!$err || ($err['warning_count'] == 0 && $err['error_count'] == 0))) {
                return ['ok' => true, 'iso' => $d->format('Y-m-d'), 'ambiguous' => false, 'alt' => null, 'how' => 'converted-date'];
            }
        }
        return $none;
    }

    /** 0..1 similarity from the Levenshtein distance of the normalised strings. */
    function import_similarity(string $a, string $b): float
    {
        $a = import_norm_text($a);
        $b = import_norm_text($b);
        if ($a === $b) {
            return 1.0;
        }
        $max = max(strlen($a), strlen($b));
        if ($max === 0 || $max > 250) {
            return 0.0;
        }
        return 1.0 - (levenshtein($a, $b) / $max);
    }

    function import_median(array $nums): ?float
    {
        $nums = array_values($nums);
        $n = count($nums);
        if ($n === 0) {
            return null;
        }
        sort($nums);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float)$nums[$mid] : ((float)$nums[$mid - 1] + (float)$nums[$mid]) / 2;
    }

    /**
     * Tolerance for UNIT prices. Unit costs are stored as decimal(12,4), so the app's 0.01 BALANCE_TOLERANCE (whole-currency
     * totals) would merge 0.004 with 0.009 and read 0.008 as zero. Half of the smallest stored step, as resolve.php uses for cost diffs.
     */
    function import_money_tolerance(): float
    {
        return 0.00005;
    }

    /** Two unit prices are the same value (never raw float equality). */
    function import_money_equal($a, $b): bool
    {
        return abs((float)$a - (float)$b) < import_money_tolerance();
    }

    /** Is this number exactly zero? (0.008 is a price of 0.008, not "no price".) */
    function import_is_zero($v): bool
    {
        return (float)$v == 0.0;
    }

    /** 'Sheet name!C12' */
    function import_source_ref(string $sheetName, string $ref): string
    {
        return $sheetName . '!' . $ref;
    }

    /** ['Sheet name','C12'] (splits on the LAST '!') */
    function import_split_source(string $source): array
    {
        $p = strrpos($source, '!');
        if ($p === false) {
            return ['', $source];
        }
        return [substr($source, 0, $p), substr($source, $p + 1)];
    }
}

if (!function_exists('import_q')) {
    /**
     * Question descriptor (ids are assigned when the analysis registers it).
     * type: mapping-unsure | no-item-column | sheet-unknown | section-or-item | duplicate-no-batch |
     *       duplicate-batch-clash | conflict | case-price | bad-value | ambiguous-date | formula-name | possible-typo
     * options: [['key','label'], ...]; a person must pick one while 'blocking' is true.
     */
    function import_q(string $type, string $text, array $o = []): array
    {
        return array_merge([
            'type' => $type, 'text' => $text, 'blocking' => true, 'sheet' => null, 'row' => null,
            'rows' => [], 'cells' => [], 'options' => [], 'default' => null,
        ], $o);
    }

    function import_is_upper_label(string $s): bool
    {
        if (!preg_match_all('/\p{L}/u', $s, $m) || count($m[0]) < 3) {
            return false;
        }
        return mb_strtoupper($s, 'UTF-8') === $s;
    }
}
