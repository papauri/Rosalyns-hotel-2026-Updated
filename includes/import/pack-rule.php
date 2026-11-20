<?php
/**
 * Import engine - packs and stock units from a row's OWN cells (pure: no database). Owner 2026-11-17 ("read files like this without so many
 * questions"). Generic rules, no hotel words: container / portion / unit words come from the alias set (import_aliases kinds container_word,
 * portion_word, unit_synonym; seeded in default-aliases.php).
 *
 *   import_pack_vocab($aliases)                 container / portion words + unit spellings, prepared once
 *   import_pack_parse_text($text, $vocab)       "Case of 24", "Crate(20)", "ctn/12", "24's", "6 pack", "24x300ml" -> ['word','n','kind']
 *   import_pack_decide($in, $vocab)             the row's stock unit, whether its price is per pack (divided by the pack size) or per one,
 *                                               and whether its cost still makes sense next to its selling price
 *
 * The decide rule (A83): the price is per PACK, and the cost of one stock unit = price / pack size, when the bought-as word is a container
 * (crate, case, carton, box, tray, pack ...), the pack size is 2 or more, and the bought-as word is not the stock unit. When the bought-as
 * word IS the stock unit (Can / Can, Bottle / Bottle) the price is per one and the pack size is information only. A price column headed
 * "per case / per crate" is per pack by its heading. Stock unit = the served unit when it is a whole thing (bottle, can, each); the bought
 * unit when it is served by a portion (shot, glass ...). Every value used is a source cell; the divided cost is shown with its reason.
 */

require_once __DIR__ . '/text-util.php';
require_once __DIR__ . '/measure.php';   // import_served_volume_in / import_measure_singular (pure)

if (!function_exists('import_pack_vocab')) {

    function import_pack_vocab(array $aliases): array
    {
        static $cache = [];
        $key = md5(json_encode([$aliases['container_words'] ?? null, $aliases['portion_words'] ?? null, $aliases['unit_synonyms'] ?? null, $aliases['units'] ?? null]));
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $set = function ($list) {
            $o = [];
            foreach ((array)$list as $w) {
                $n = import_norm_text((string)$w);
                if ($n !== '') {
                    $o[$n] = true;
                }
            }
            return $o;
        };
        $syn = [];
        foreach ((array)($aliases['unit_synonyms'] ?? []) as $k => $v) {
            $syn[mb_strtolower(trim((string)$k))] = mb_strtolower(trim((string)$v));
        }
        return $cache[$key] = ['container' => $set($aliases['container_words'] ?? []), 'portion' => $set($aliases['portion_words'] ?? []), 'syn' => $syn,
            'unit' => $set(array_merge((array)($aliases['units'] ?? []), array_keys($syn)))];
    }

    /** One unit word in the stock's spelling, lower case ("Bottles" -> "bottle", "ctn" -> "carton", "Each" -> "pcs"). */
    function import_pack_canon(string $w, array $vocab): string
    {
        $k = mb_strtolower(import_norm_ws($w));
        if ($k === '') {
            return '';
        }
        if (isset($vocab['syn'][$k])) {
            $k = $vocab['syn'][$k];
        } elseif (mb_strlen($k) >= 4 && substr($k, -1) === 's' && isset($vocab['syn'][substr($k, 0, -1)])) {
            $k = $vocab['syn'][substr($k, 0, -1)];
        } elseif (mb_strlen($k) >= 4 && substr($k, -1) === 's' && substr($k, -2) !== 'ss') {
            $k = substr($k, 0, -1);
        }
        return in_array($k, ['each', 'ea', 'pcs', 'pc', 'piece', 'unit'], true) ? 'pcs' : $k;
    }

    /** container | portion | '' for a cell / word as written */
    function import_pack_word_kind(string $w, array $vocab): string
    {
        $n = import_norm_text($w);
        if ($n === '') {
            return '';
        }
        if (isset($vocab['container'][$n])) {
            return 'container';
        }
        if (isset($vocab['portion'][$n])) {
            return 'portion';
        }
        return '';
    }

    /**
     * The kind of a "served by" cell as hotels write it (owner 2026-11-20, plain rules): "Shot" as before; also "Per shot", "1 Shot", "a tot",
     * "Tot 25ml", "Glass 175 ml", "Glass of wine" (the word before "of") -> the kind of its measure word; a volume alone ("25ml") -> portion.
     * "Btl 750ml" stays '' (a unit word), "Glass 175" (no ml) stays '' (never a guess). Same reading as import_served_read (measure.php).
     */
    function import_served_kind(string $served, array $vocab): string
    {
        $k = import_pack_word_kind($served, $vocab);
        if ($k !== '') {
            return $k;
        }
        $t = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $served)));
        if ($t === '') {
            return '';
        }
        $vol = import_served_volume_in($t);
        $rest = $vol ? $vol[1] : $t;
        $rest = trim((string)preg_replace('/^(?:per|a|an|one|1|x)\s+/u', '', $rest));
        $rest = trim((string)preg_replace('/\s+(?:of|x|@)$/u', '', $rest), " .-/()@");
        if ($rest === '') {
            return $vol ? 'portion' : '';
        }
        if (preg_match('/^(.+?)\s+of\s+.+$/u', $rest, $p)) {
            $rest = trim($p[1]);
        }
        foreach ([$rest, import_measure_singular($rest)] as $w) {
            $k = import_pack_word_kind($w, $vocab);
            if ($k !== '') {
                return $k;
            }
        }
        return '';
    }

    /**
     * A pack written inside ONE cell: "Case of 24", "crate (20)", "Crate x 20", "ctn/12", "6 pack", "6-pk" (word + number), "24's", "24x300ml",
     * "12 x 1L" (number only). Returns ['word' => container word as written|null, 'n' => int, 'match' => the text matched] or null.
     * The number must be a whole 2..10000; a word must be a container word of the alias set.
     */
    function import_pack_parse_text(string $text, array $vocab): ?array
    {
        $t = import_norm_ws($text);
        if ($t === '' || mb_strlen($t) > 190) {
            return null;
        }
        $ok = function ($n) {
            return ctype_digit((string)$n) && (int)$n >= 2 && (int)$n <= 10000;
        };
        $w = '([A-Za-z]{2,12})';
        $pats = [
            '/\b' . $w . '\s*(?:of|x|\*|\/|-)\s*(\d{1,5})\b/iu',                 // case of 24 / crate x 20 / ctn/12 / case-24
            '/\b' . $w . '\s*\(\s*(\d{1,5})\s*(?:s|pcs|units?)?\s*\)/iu',          // crate(20) / box (12 pcs)
        ];
        foreach ($pats as $p) {
            if (preg_match_all($p, $t, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    if ($ok($m[2]) && import_pack_word_kind($m[1], $vocab) === 'container') {
                        return ['word' => $m[1], 'n' => (int)$m[2], 'match' => $m[0]];
                    }
                }
            }
        }
        if (preg_match_all('/\b(\d{1,5})\s*(?:-|\s)?\s*([A-Za-z]{2,12})\b/u', $t, $mm, PREG_SET_ORDER)) {       // 6 pack / 6-pk / 24 cans? (container only)
            foreach ($mm as $m) {
                if ($ok($m[1]) && import_pack_word_kind($m[2], $vocab) === 'container') {
                    return ['word' => $m[2], 'n' => (int)$m[1], 'match' => $m[0]];
                }
            }
        }
        if (preg_match('/(?:^|\s)(\d{1,5})\s*\'?s\b/iu', $t, $m) && $ok($m[1])) {                                 // 24's
            return ['word' => null, 'n' => (int)$m[1], 'match' => $m[0]];
        }
        if (preg_match('/\b(\d{1,5})\s*[x\*]\s*\d+(?:[.,]\d+)?\s*(?:ml|cl|l|ltr|g|kg|gm|oz)\b/iu', $t, $m) && $ok($m[1])) {   // 24x300ml
            return ['word' => null, 'n' => (int)$m[1], 'match' => $m[0]];
        }
        return null;
    }

    function import_pack_num($v): string
    {
        $v = (float)$v;
        return abs($v - round($v)) < 1e-9 ? number_format($v, 0, '.', ',') : rtrim(rtrim(number_format($v, 4, '.', ','), '0'), '.');
    }

    /**
     * The row's stock unit and price basis from its own cells. $in (all optional; each value with its source cell):
     *   price, price_src, price_is_pack (the price column is headed per case / crate / pack), pack_name, pack_name_src, pack_size, pack_size_src,
     *   served, served_src, unit, unit_src (a plain unit column), sell, sell_src, unit_price (a per-one price when a per-pack price is also there)
     * Returns null when the row has nothing to decide with, else:
     *   decided   bool: the stock unit and the price basis come from the row (no "per case or per unit?" question is needed)
     *   unit, unit_src, unit_how ('served' | 'bought' | 'unit' | 'pack-cell')  the stock unit AS WRITTEN in that cell
     *   pack      pack size the price is divided by (null = the price is per one stock unit), pack_src, pack_name (as written), pack_name_src
     *   info_pack pack size kept as information only (same unit / bought as one)
     *   cost      price / pack (or the price), reason  one plain line
     *   ask       '' | 'above-sell' | 'pack-price-differs' | 'no-pack-size': the row does not add up -> asked (that row only)
     */
    function import_pack_decide(array $in, array $vocab): ?array
    {
        $txt = function ($k) use ($in) {
            $v = $in[$k] ?? null;
            return ($v === null || is_bool($v)) ? '' : import_norm_ws((string)$v);
        };
        $packName = $txt('pack_name');
        $served = $txt('served');
        $unitCol = $txt('unit');
        $size = isset($in['pack_size']) && is_numeric($in['pack_size']) ? (float)$in['pack_size'] : null;
        $sizeSrc = $in['pack_size_src'] ?? null;
        $price = isset($in['price']) && is_numeric($in['price']) ? (float)$in['price'] : null;
        $priceIsPack = !empty($in['price_is_pack']);
        if ($packName === '' && $served === '' && $size === null && !$priceIsPack && $unitCol === '') {
            return null;
        }
        // a bought-as cell is only a unit when it is a unit / container / portion word ("Packing Date" values or "500ml" never become the stock unit)
        if ($packName !== '' && import_pack_word_kind($packName, $vocab) === '' && !isset($vocab['unit'][import_norm_text($packName)]) && import_pack_parse_text($packName, $vocab) === null) {
            $packName = '';
        }
        $bought = $packName;
        $boughtSrc = $in['pack_name_src'] ?? null;
        $from = '';
        // a pack written inside the bought-as cell or the unit cell ("Case of 24", "Crate(20)", "ctn/12")
        foreach ([['pack_name', $packName, $in['pack_name_src'] ?? null], ['unit', $unitCol, $in['unit_src'] ?? null]] as [$k, $t, $src]) {
            if ($t === '' || ($k === 'unit' && $packName !== '')) {
                continue;
            }
            $pp = import_pack_parse_text($t, $vocab);
            if ($pp !== null && $pp['word'] !== null) {
                $bought = $pp['word'];
                $boughtSrc = $src;
                if ($size === null) {
                    $size = (float)$pp['n'];
                    $sizeSrc = $src;
                }
                $from = $k;
                if ($k === 'unit') {
                    $unitCol = '';            // the unit cell held the pack, not a stock unit
                }
                break;
            }
        }
        // ---- stock unit ----
        $unit = '';
        $unitSrc = null;
        $unitHow = '';
        $servedKind = $served !== '' ? import_served_kind($served, $vocab) : '';   // "Per shot", "Tot 25ml" are portions too (owner 2026-11-20)
        $unitColOk = $unitCol !== '' && import_pack_word_kind($unitCol, $vocab) !== 'portion';
        if ($unitColOk) {
            // a Unit cell that is there is the stock unit: a served-by / measure cell only ADDS how it is sold, it never replaces it
            // (review 2026-11-20: Unit "Crate" + "Serving Size" "Bottle" stays 3 crates at the crate price, never 3 bottles at it)
            [$unit, $unitSrc, $unitHow] = [$unitCol, $in['unit_src'] ?? null, 'unit'];
        } elseif ($served !== '' && $servedKind !== 'portion') {
            [$unit, $unitSrc, $unitHow] = [$served, $in['served_src'] ?? null, 'served'];
        } elseif ($bought !== '') {
            // counted in what it is bought in; a pack read out of a cell ("Case of 24") gives the word only (how = pack-cell, re-parsed by the invariant)
            [$unit, $unitSrc, $unitHow] = $from !== '' ? [$bought, $boughtSrc, 'pack-cell'] : [$packName, $boughtSrc, 'bought'];
        }
        $out = ['decided' => false, 'unit' => $unit !== '' ? $unit : null, 'unit_src' => $unitSrc, 'unit_how' => $unitHow, 'pack' => null, 'pack_src' => null,
            'pack_name' => $bought !== '' ? $bought : null, 'pack_name_src' => $boughtSrc, 'info_pack' => null, 'cost' => $price, 'reason' => '', 'ask' => '',
            'served_portion' => $servedKind === 'portion'];
        if ($unit === '' || $price === null || $price <= 0) {
            // nothing to divide: the unit (if any) is still read from the row
            $out['decided'] = $unit !== '' && ($price === null || $price <= 0);
            return $out;
        }
        $uName = mb_strtolower($unit);
        $sameUnit = $bought !== '' && import_pack_canon($bought, $vocab) === import_pack_canon($unit, $vocab);
        $isContainer = $bought !== '' && import_pack_word_kind($bought, $vocab) === 'container';
        $wholeSize = $size !== null && abs($size - round($size)) < 1e-9 ? (int)round($size) : null;
        $sell = isset($in['sell']) && is_numeric($in['sell']) ? (float)$in['sell'] : null;
        if ($sell !== null && $sell > 0) {
            $out['sell'] = $sell;
            $out['sell_src'] = $in['sell_src'] ?? null;
        }
        if ($size !== null && $size > 1000) {
            // "2026" in the pack column is a year or a typing slip, not a pack size: never divided by, asked (QA Q1)
            $out['ask'] = 'odd-pack-size';
            $out['odd_size'] = $size;
            return $out;
        }
        if ($out['served_portion'] && $isContainer && $wholeSize !== null && $wholeSize >= 2) {
            // bought by the case of 12 and served by the tot: the count is most likely in bottles, not cases - the person says which (QA Q3)
            $out['ask'] = 'count-unit';
            $out['info_pack'] = $wholeSize;
            return $out;
        }
        if ($priceIsPack && !$sameUnit) {
            if ($wholeSize !== null && $wholeSize >= 2) {
                $out['pack'] = $wholeSize;
                $out['pack_src'] = $sizeSrc;
                $out['reason'] = 'The price column is a price per ' . ($bought !== '' ? mb_strtolower($bought) : 'pack') . ': ' . import_pack_num($price) . ' / ' . $wholeSize . ' = '
                    . import_pack_num(round($price / $wholeSize, 4)) . ' per ' . $uName . '.';
            } else {
                $out['ask'] = 'no-pack-size';
                return $out;
            }
        } elseif ($isContainer && $wholeSize !== null && $wholeSize >= 2 && !$sameUnit) {
            $out['pack'] = $wholeSize;
            $out['pack_src'] = $sizeSrc;
            $out['reason'] = 'Bought by the ' . mb_strtolower($bought) . ' of ' . $wholeSize . ' and counted by the ' . $uName . ': ' . import_pack_num($price) . ' / ' . $wholeSize
                . ' = ' . import_pack_num(round($price / $wholeSize, 4)) . ' per ' . $uName . '.';
        } elseif ($bought !== '' && ($sameUnit || ($wholeSize !== null && $wholeSize <= 1))) {
            // per one only when the row SAYS so (bought as the stock unit, or one to a pack); a row with no bought-as word decides nothing
            $out['info_pack'] = ($wholeSize !== null && $wholeSize >= 2) ? $wholeSize : null;
            $out['reason'] = 'The price is per ' . $uName . ($sameUnit && $out['info_pack'] ? ' (bought as ' . mb_strtolower($bought) . ', ' . $out['info_pack'] . ' to a pack: information only)' : '')
                . ($out['served_portion'] ? ' (served by the ' . mb_strtolower($served) . ')' : '') . '.';
        } else {
            // a container with no usable pack size, or a bought-as word that is not a container: the row does not say
            return $out;
        }
        $out['decided'] = true;
        $cost = $out['pack'] ? $price / $out['pack'] : $price;
        $out['cost'] = round($cost, 4);
        // the per-one price column next to a per-pack one: the per-one price is used, the pack price only checks it
        if (isset($in['unit_price']) && is_numeric($in['unit_price']) && (float)$in['unit_price'] > 0 && $out['pack']) {
            $one = (float)$in['unit_price'];
            if (abs($one * $out['pack'] - $price) > max(1.0, 0.02 * $price)) {
                $out['ask'] = 'pack-price-differs';
            }
        }
        // cross-check with the selling price of the same unit (a shot / glass is not comparable 1:1 with a bottle / casket):
        //  - a price the row would DIVIDE that is already at or below what ONE unit sells for is most likely a price per unit already (QA Q1)
        //  - a cost per unit (after any division) above what one unit sells for is most likely the price of a bigger pack
        $comparable = $sell !== null && $sell > 0 && !$out['served_portion'];
        if ($out['ask'] === '' && $comparable && $out['pack'] && !isset($in['unit_price']) && $price <= $sell + max(0.5, 0.005 * $sell)) {
            $out['ask'] = 'price-below-sell';
        } elseif ($out['ask'] === '' && $comparable && $cost > $sell + max(0.5, 0.005 * $sell)) {
            $out['ask'] = 'above-sell';
        }
        return $out;
    }
}
