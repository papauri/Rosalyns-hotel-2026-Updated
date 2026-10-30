<?php

/**
 * Menu & recipe advisor (owner 2026-10-30: "help with the menu and recipes based on the stock we have ... portions,
 * best pricing, wastage ... at the cheapest price"). READ ONLY: nothing here writes to the menu, recipes, prices or stock.
 *
 * One read tool, menu_advisor, does all the arithmetic locally and returns compact facts:
 *  - a dish idea + a one-portion recipe: each ingredient matched to stock (the real name; several plausible = one short
 *    question), on hand, cost, pack, portions the stock allows, what is not stocked (checked against EVERY stock item),
 *    batches expiring soon, cost per portion, a suggested till price at the target food cost (VAT inside, the till rule)
 *    and similar menu items with their food cost;
 *  - one menu item's food cost (the Recipes screen's own sum), the items to re-price, the items with no recipe, and what
 *    to cook from stock expiring soon.
 * Yield: needed = quantity per portion x portions / rh_stock_yield_factor(yield %) - the rule deductStockForMenuItem() uses.
 * Food cost % = recipe cost / till price, as the Recipes screen shows it. Targets, bands, windows and rounding are settings.
 *
 * Cheapest: structured questions are answered here with no model call (rh_ai_menu_local). Only a dish idea with no recipe
 * on file needs a model: the Fast model proposes one typical portion (a few hundred tokens), cached for the whole hotel;
 * the Primary model is tried once only when the Fast model's proposal cannot be read. The reply is written locally from
 * the tool result, so every figure in it comes from this question's lookup.
 *
 * Who: the Menu permission (Menu Management) AND stock visibility (Ingredients & Recipes or the Stock Dashboard), with the
 * POS (menu) and Stock modules on - decided here, server side, every call.
 */

require_once __DIR__ . '/ai-core.php';

function rh_ai_menu_tools(): array
{
    return [
        'menu_advisor' => [
            'description' => 'Menu and recipe advice from the stock on hand, read only. dish + recipe (one portion, "ingredient qty unit; ..." in g, ml or pcs, plain generic names): each ingredient matched to stock, on hand, cost, portions the stock allows, what is not stocked, batches to use up, cost per portion, a suggested till price at the target food cost (VAT included) and similar menu items. item: one menu item\'s food cost and portions. view: under_priced, no_recipe or use_up (what to cook from stock expiring soon). Nothing is changed.',
            'schema' => ['properties' => [
                'dish' => ['type' => 'string', 'maxLength' => 80, 'description' => 'A dish or drink idea'],
                'recipe' => ['type' => 'string', 'maxLength' => 800, 'description' => 'One portion: "chicken 180 g; onion 60 g; ..."'],
                'item' => ['type' => 'string', 'maxLength' => 80, 'description' => 'A menu item already on the menu'],
                'portions' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'description' => 'Portions planned'],
                'kind' => ['type' => 'string', 'enum' => ['food', 'drink']],
                'serving' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Serving size of the portion, e.g. "1 plate"'],
                'recipe_by' => ['type' => 'string', 'enum' => ['staff', 'assistant'], 'description' => 'assistant = a typical portion you proposed'],
                'view' => ['type' => 'string', 'enum' => ['under_priced', 'no_recipe', 'use_up']],
            ]],
            'perm' => ['menu'], 'module' => ['stock'], 'gate' => 'rh_ai_gate_menu_advisor', 'cache' => 120,
            'handler' => 'rh_ai_t_menu_advisor', 'step' => 'Checking the menu, recipes and stock',
        ],
    ];
}

/** Menu editors who can also see stock (Ingredients & Recipes or the Stock Dashboard), where the menu (POS) is switched on. */
function rh_ai_gate_menu_advisor(array $user): bool
{
    $uid = (int)($user['id'] ?? 0);
    return rh_ai_module_on('pos') && (rh_ai_has_perm($uid, 'stock_management') || rh_ai_has_perm($uid, 'stock_dashboard'));
}

// ── Settings (defaults live in rh_ai_setting_defaults; never a number written here) ──

function rh_ai_menu_pct(string $key): float
{
    $v = (float)rh_ai_setting($key, (string)(rh_ai_setting_defaults()[$key] ?? '0'));
    return max(0.1, min(99.0, $v));
}

function rh_ai_menu_target_pct(string $kind): float
{
    return rh_ai_menu_pct($kind === 'drink' ? 'ai_menu_drink_cost_target_pct' : 'ai_menu_food_cost_target_pct');
}

/** The Recipes screen's band for a food cost % (healthy / acceptable / re-price), with the setting's own limits. */
function rh_ai_menu_band(float $pct): string
{
    $ok = rh_ai_menu_pct('ai_menu_cost_ok_pct');
    $high = rh_ai_menu_pct('ai_menu_cost_high_pct');
    $f = static fn(float $x): string => rtrim(rtrim(number_format($x, 1), '0'), '.');
    if ($pct < $ok) {
        return 'healthy (under ' . $f($ok) . '%)';
    }
    return $pct <= $high ? 'acceptable (' . $f($ok) . '-' . $f($high) . '%)' : 're-price or rework (over ' . $f($high) . '%)';
}

/** A quantity for people: up to 3 decimals, no trailing zeros, with thousands commas ("12.5", "0.257", "1,200"). */
function rh_ai_menu_qty(float $n): string
{
    $s = number_format(round($n, 3), 3, '.', ',');
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '-0' ? '0' : $s;
}

/** Round a price UP to a sensible step: the setting when set, else about 1-5% of the price (11,333 -> 11,500; 4,320 -> 4,400). */
function rh_ai_menu_round_price(float $p): float
{
    if ($p <= 0) {
        return 0.0;
    }
    $step = (float)rh_ai_setting('ai_menu_price_round', '0');
    if ($step <= 0) {
        $step = 10 ** (floor(log10($p)) - 1);
        if ($p / $step < 20) {
            $step /= 2;
        }
        $step = max(0.01, $step);
    }
    return round(ceil(round($p / $step, 6)) * $step, 2);
}

/**
 * The till price that gives the target food cost (cost / price, as the Recipes screen works it out), rounded up, and the
 * VAT inside it by the till's own rule (restaurant prices include VAT whenever VAT is switched on).
 */
function rh_ai_menu_price(float $cost, string $kind): array
{
    if ($cost <= 0) {
        return [];
    }
    $target = rh_ai_menu_target_pct($kind);
    $price = rh_ai_menu_round_price($cost / ($target / 100));
    $pct = round($cost / $price * 100, 1);
    $out = ['target_food_cost_pct' => round($target, 1), 'suggested_till_price' => $price, 'food_cost_pct_at_that_price' => $pct, 'band' => rh_ai_menu_band($pct)];
    $rate = (function_exists('rh_vat_enabled') && rh_vat_enabled()) ? (float)getSetting('vat_rate', 0) : 0.0;
    if ($rate > 0) {
        $net = round($price / (1 + $rate / 100), 2);
        $out += ['vat_included' => true, 'vat_rate_pct' => round($rate, 2), 'vat_inside' => round($price - $net, 2), 'before_vat' => $net];
    } else {
        $out['vat_included'] = false;
    }
    $out['margin_per_portion'] = round($price - $cost, 2);
    return $out;
}

// ── Words, units and matching ───────────────────────────────────────────────

/** Words that describe an ingredient rather than name it (dropped from what is asked, never from stock names). */
function rh_ai_menu_descriptors(): array
{
    return ['fresh', 'frozen', 'chopped', 'diced', 'sliced', 'minced', 'ground', 'dried', 'dry', 'large', 'small', 'medium', 'boneless', 'skinless', 'cooking',
        'whole', 'raw', 'peeled', 'grated', 'crushed', 'finely', 'roughly', 'ripe', 'cooked', 'plain', 'extra', 'virgin', 'and', 'or', 'for', 'the', 'with', 'to',
        'taste', 'optional', 'pinch', 'handful', 'some', 'few', 'of', 'any', 'good', 'quality', 'local', 'organic', 'shredded', 'cubed', 'cut', 'into', 'pieces'];
}

function rh_ai_menu_singular(string $w): string
{
    if (mb_strlen($w) <= 3 || preg_match('/(ss|us|is)$/u', $w)) {
        return $w;
    }
    if (preg_match('/ies$/u', $w)) {
        return (string)preg_replace('/ies$/u', 'y', $w);
    }
    if (preg_match('/(oes|ches|shes|xes|sses)$/u', $w)) {
        return mb_substr($w, 0, -2);
    }
    if (preg_match('/ves$/u', $w) && mb_strlen($w) > 4) {
        return mb_substr($w, 0, -3) . 'f';
    }
    return (string)preg_replace('/s$/u', '', $w);
}

/** Name words, lower case and singular; $asked = true also drops describing words ("fresh chopped onions" -> ["onion"]). */
function rh_ai_menu_words(string $s, bool $asked): array
{
    $out = [];
    foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($s)) as $w) {
        if (mb_strlen($w) < 2 || ($asked && in_array($w, rh_ai_menu_descriptors(), true))) {
            continue;
        }
        $out[] = rh_ai_menu_singular($w);
    }
    return array_values(array_unique($out));
}

/** Unit families: [family, size in the family's base unit]; null = not a unit word. */
function rh_ai_menu_unit(string $u): ?array
{
    $u = mb_strtolower(trim($u, " .\t"));
    $map = [
        'mg' => ['mass', 0.001], 'g' => ['mass', 1], 'gr' => ['mass', 1], 'gram' => ['mass', 1], 'grams' => ['mass', 1], 'kg' => ['mass', 1000], 'kgs' => ['mass', 1000], 'kilo' => ['mass', 1000], 'kilos' => ['mass', 1000], 'kilogram' => ['mass', 1000], 'kilograms' => ['mass', 1000],
        'ml' => ['vol', 1], 'cl' => ['vol', 10], 'dl' => ['vol', 100], 'l' => ['vol', 1000], 'lt' => ['vol', 1000], 'ltr' => ['vol', 1000], 'ltrs' => ['vol', 1000], 'litre' => ['vol', 1000], 'litres' => ['vol', 1000], 'liter' => ['vol', 1000], 'liters' => ['vol', 1000],
        'tsp' => ['vol', 5], 'teaspoon' => ['vol', 5], 'teaspoons' => ['vol', 5], 'tbsp' => ['vol', 15], 'tablespoon' => ['vol', 15], 'tablespoons' => ['vol', 15],
        'pc' => ['count', 1], 'pcs' => ['count', 1], 'piece' => ['count', 1], 'pieces' => ['count', 1], 'each' => ['count', 1], 'ea' => ['count', 1], 'unit' => ['count', 1], 'units' => ['count', 1],
        'no' => ['count', 1], 'nos' => ['count', 1], 'item' => ['count', 1], 'items' => ['count', 1], 'slice' => ['count', 1], 'slices' => ['count', 1], 'dozen' => ['count', 12], 'dz' => ['count', 12], 'doz' => ['count', 12],
    ];
    return $map[$u] ?? null;
}

/**
 * How many of the ingredient's own stock units one recipe unit is (recipe quantities on file are in the stock unit).
 * Same family: by size (g -> kg); pieces into a packet of N: 1/N (rh_stock_pieces_per_unit); the same unit word: 1.
 * null = not comparable (e.g. grams of something counted in packets) - never guessed.
 */
function rh_ai_menu_unit_factor(string $from, array $ing): ?float
{
    $to = (string)($ing['unit'] ?? '');
    $a = rh_ai_menu_unit($from);
    $b = rh_ai_menu_unit($to);
    if ($a !== null && $b !== null && $a[0] === $b[0]) {
        return $a[1] / $b[1];
    }
    if ($a !== null && $a[0] === 'count' && function_exists('rh_stock_pieces_per_unit')) {
        $pieces = rh_stock_pieces_per_unit($to, $ing['pieces_per_unit'] ?? null);
        if ($pieces !== null && $pieces > 0) {
            return $a[1] / $pieces;
        }
    }
    $fw = rh_ai_menu_singular(mb_strtolower(trim($from)));
    return $fw !== '' && $fw === rh_ai_menu_singular(mb_strtolower(trim($to))) ? 1.0 : null;
}

/**
 * A recipe written as text: "chicken 180 g; onion 60 g; 2 eggs; rice: 90 g". One portion per line, separated by ; , or new
 * lines. @return array<int,array{0:string,1:float,2:string}> [name, quantity, unit]
 */
function rh_ai_menu_parse_recipe(string $text): array
{
    $text = trim($text);
    $parts = preg_split('/\s*[;\n]+\s*/u', $text);
    if (count($parts) === 1 && strpos($text, ',') !== false) {
        $parts = preg_split('/\s*,\s*(?!\d)/u', $text);
    }
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)preg_replace('/\s+/u', ' ', (string)$p), " .-*\t");
        if ($p === '') {
            continue;
        }
        $num = '(\d+(?:[.,]\d+)?|\d+\/\d+)';
        $name = '';
        $qty = 0.0;
        $unit = 'pcs';
        if (preg_match('/^(.+?)\s*[:=]?\s+' . $num . '\s*([\p{L}]+)?$/u', $p, $m) && preg_match('/\p{L}/u', $m[1])) {
            [$name, $qty, $unit] = [$m[1], $m[2], ($m[3] ?? '') !== '' ? $m[3] : 'pcs'];
            if (rh_ai_menu_unit($unit) === null && !preg_match('/^(bottles?|tins?|cans?|cups?|packets?|bunch(es)?|heads?|portions?|sachets?|rolls?|trays?|boxe?s?|bags?)$/u', mb_strtolower($unit))) {
                continue; // "salt to taste 2 pinches" and the like: not a quantity we can use
            }
        } elseif (preg_match('/^' . $num . '\s*([\p{L}]+)?\s+(?:of\s+)?(.+)$/u', $p, $m)) {
            $u = (string)($m[2] ?? '');
            if ($u !== '' && rh_ai_menu_unit($u) === null && !preg_match('/^(bottles?|tins?|cans?|cups?|packets?|bunch(es)?|heads?|portions?|sachets?|rolls?|trays?|boxe?s?|bags?)$/u', mb_strtolower($u))) {
                [$name, $qty, $unit] = [$u . ' ' . $m[3], $m[1], 'pcs']; // "2 eggs": the word is the ingredient
            } else {
                [$name, $qty, $unit] = [$m[3], $m[1], $u !== '' ? $u : 'pcs'];
            }
        } else {
            continue;
        }
        $qty = strpos((string)$qty, '/') !== false ? (static function (string $f): float { [$a, $b] = explode('/', $f); return (float)$b > 0 ? (float)$a / (float)$b : 0.0; })((string)$qty) : (float)str_replace(',', '.', (string)$qty);
        $name = trim(mb_substr((string)preg_replace('/[^\p{L}\p{N} \'\-]/u', '', (string)$name), 0, 60));
        if ($name === '' || $qty <= 0 || $qty > 100000) {
            continue;
        }
        $out[] = [$name, round($qty, 4), mb_strtolower($unit)];
        if (count($out) >= 20) {
            break;
        }
    }
    return $out;
}

/** Every stock item (archived too), read once per lookup: the whole list, so "not stocked" is a checked answer. */
function rh_ai_menu_stock_rows(PDO $db): array
{
    $thr = function_exists('rh_stock_threshold_sql') ? rh_stock_threshold_sql('i') : 'i.min_quantity';
    return $db->query("SELECT i.id, i.name, i.unit, i.current_quantity, i.cost_per_unit, i.yield_percent, i.is_archived, i.pack_name, i.pack_size, i.pieces_per_unit, $thr AS threshold
                       FROM stock_ingredients i ORDER BY i.is_archived, i.name")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * One asked ingredient against the whole stock list. Containment (every asked word is in the stock name, small slips
 * allowed) wins; then the shared close-match rule over every name; a stock name sharing only one word is offered as
 * "close", never used. @return array{status:string,row:?array,others:array,close:array}
 */
function rh_ai_menu_match_ingredient(string $asked, array $rows): array
{
    $aw = rh_ai_menu_words($asked, true);
    if (!$aw) {
        $aw = rh_ai_menu_words($asked, false);
    }
    $res = ['status' => 'not_stocked', 'row' => null, 'others' => [], 'close' => []];
    if (!$aw) {
        return $res;
    }
    $phrase = implode(' ', $aw);
    $whole = implode(' ', rh_ai_menu_words($asked, false)); // "cooking oil" is the stock item called Cooking oil, not every oil
    foreach ([0, 1] as $archived) {
        foreach ($rows as $r) {
            if ((int)$r['is_archived'] === $archived && $whole !== '' && implode(' ', rh_ai_menu_words((string)$r['name'], false)) === $whole) {
                return ['status' => $archived ? 'archived' : 'matched', 'row' => $r, 'others' => [], 'close' => []];
            }
        }
    }
    foreach ([0, 1] as $archived) {
        $full = [];
        $part = [];
        $slips = [];
        foreach ($rows as $r) {
            if ((int)$r['is_archived'] !== $archived) {
                continue;
            }
            $iw = rh_ai_menu_words((string)$r['name'], false);
            if (!$iw) {
                continue;
            }
            $hit = 0;
            $slip = false;
            foreach ($aw as $a) {
                foreach ($iw as $w) {
                    if ($a === $w || (mb_strlen($a) >= 5 && function_exists('rh_ai_word_closeness') && rh_ai_word_closeness($a, $w) >= 0.85)) {
                        $hit++;
                        $slip = $slip || $a !== $w;
                        break;
                    }
                }
            }
            if ($hit === count($aw) && count($aw) / count($iw) >= 0.5) { // "banana" is not "Essence Banana flavour": a word inside a longer name is only close
                $full[(int)$r['id']] = (implode(' ', $iw) === $phrase ? 2.0 : 1.0) + count($aw) / max(count($aw), count($iw)) / 10;
                if ($slip) {
                    $slips[(int)$r['id']] = true;
                }
            } elseif ($hit > 0) {
                $part[(int)$r['id']] = $hit / count($aw);
            }
        }
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int)$r['id']] = $r;
        }
        if ($full) {
            arsort($full);
            $ids = array_keys($full);
            $top = $byId[$ids[0]];
            $others = [];
            $topScore = $full[$ids[0]];
            foreach (array_slice($ids, 1) as $id) {
                if ($topScore < 2.0 || $full[$id] >= 2.0) { // an exact name is the one meant; otherwise every other full match is plausible
                    $others[] = (string)$byId[$id]['name'];
                }
            }
            return ['status' => $archived ? 'archived' : ($others ? 'several' : (isset($slips[$ids[0]]) ? 'read_as' : 'matched')), 'row' => $top, 'others' => array_slice($others, 0, 3), 'close' => []];
        }
        if (!$archived && function_exists('rh_ai_fuzzy_pick')) { // a slip in the whole name ("tomatos", "cabage")
            $labels = [];
            foreach ($rows as $r) {
                if ((int)$r['is_archived'] === 0) {
                    $labels[(int)$r['id']] = (string)$r['name'];
                }
            }
            $p = rh_ai_fuzzy_pick($asked, $labels);
            if ($p['clear'] !== null && isset($byId[(int)$p['clear']]) && count($aw) / max(1, count(rh_ai_menu_words((string)$byId[(int)$p['clear']]['name'], false))) < 0.5) {
                $p['options'] = [(string)$p['clear']]; // one word inside a longer name ("banana" / Essence Banana flavour): close, never used
                $p['clear'] = null;
            }
            if ($p['clear'] !== null && isset($byId[(int)$p['clear']])) {
                return ['status' => 'read_as', 'row' => $byId[(int)$p['clear']], 'others' => [], 'close' => []];
            }
            if ($p['options']) {
                return ['status' => 'not_stocked', 'row' => null, 'others' => [], 'close' => array_slice(array_map(static fn($k) => (string)$labels[(int)$k], $p['options']), 0, 3)];
            }
        }
        if (!$archived && $part) {
            arsort($part);
            $res['close'] = array_slice(array_map(static fn($id) => (string)$byId[$id]['name'], array_keys($part)), 0, 3);
        }
    }
    return $res;
}

// ── Menu reads (the Recipes screen's own SQL) ───────────────────────────────

/** Every menu item of an active category with its recipe cost per portion, exactly as the Recipes screen sums it. */
function rh_ai_menu_items(PDO $db): array
{
    $y = function_exists('rh_stock_yield_sql') ? rh_stock_yield_sql('sri.yield_percent') : '1';
    try {
        return $db->query("SELECT mc.slug AS menu_type, mc.name AS category, mi.id, mi.item_name AS name, mi.price, mi.station, mi.is_available, sr.id AS recipe_id,
                                  COALESCE(SUM(sri.quantity_per_portion / $y * i.cost_per_unit), 0) AS recipe_cost,
                                  COUNT(sri.id) AS recipe_lines, MAX(i.unit) AS line_unit, MAX(sri.quantity_per_portion) AS line_qty
                           FROM menu_items mi
                           JOIN menu_categories mc ON mc.id = mi.category_id
                           LEFT JOIN stock_recipes sr ON sr.menu_item_id = mi.id AND sr.menu_type = mc.slug
                           LEFT JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
                           LEFT JOIN stock_ingredients i ON i.id = sri.ingredient_id
                           WHERE mc.is_active = 1
                           GROUP BY mc.slug, mc.name, mc.sort_order, mi.id, mi.item_name, mi.price, mi.station, mi.is_available, sr.id
                           ORDER BY mc.sort_order ASC, mi.item_name ASC LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function rh_ai_menu_kind_of(array $item): string
{
    return in_array((string)($item['station'] ?? ''), ['bar', 'coffee_bar'], true) ? 'drink' : 'food';
}

/** One menu item's line for lists: name, till price, food cost % (or no recipe). */
/** A drink or item bought in and sold as is: a recipe of one stock item, one bottle / can / piece per sale. */
function rh_ai_menu_is_resale(array $m): bool
{
    if ((int)($m['recipe_lines'] ?? 0) !== 1 || abs((float)($m['line_qty'] ?? 0) - 1.0) > 0.0001) {
        return false;
    }
    $u = (string)($m['line_unit'] ?? '');
    $fam = rh_ai_menu_unit($u);
    return ($fam !== null && $fam[0] === 'count') || (bool)preg_match('/^(bottles?|cans?|tins?|sachets?|packets?|packs?|boxe?s?|cartons?|bars?)$/iu', trim($u));
}

function rh_ai_menu_item_line(array $m): array
{
    $price = round((float)$m['price'], 2);
    $cost = round((float)$m['recipe_cost'], 2);
    $line = ['item' => rh_ai_plain((string)$m['name'], 60), 'till_price' => $price];
    if (!empty($m['recipe_id'])) {
        $line['cost_per_portion'] = $cost;
        $line['food_cost_pct'] = $price > 0 ? round($cost / $price * 100, 1) : null;
    } else {
        $line['recipe'] = 'none yet';
    }
    return $line;
}

/** A menu item by name: exact, then every word contained, then the shared close-match rule. */
function rh_ai_menu_find_item(array $items, string $q): array
{
    $q = trim($q);
    $ql = mb_strtolower($q);
    foreach ($items as $m) {
        if (mb_strtolower(trim((string)$m['name'])) === $ql) {
            return ['status' => 'one', 'item' => $m];
        }
    }
    $qw = rh_ai_menu_words($q, false);
    $hits = [];
    foreach ($items as $m) {
        $iw = rh_ai_menu_words((string)$m['name'], false);
        if ($qw && !array_diff($qw, $iw)) {
            $hits[] = $m;
        }
    }
    if (count($hits) === 1) {
        return ['status' => 'one', 'item' => $hits[0]];
    }
    if (count($hits) > 1) {
        return ['status' => 'several', 'options' => array_slice(array_map(static fn($m) => (string)$m['name'], $hits), 0, rh_ai_num('ai_lookup_matches'))];
    }
    if (function_exists('rh_ai_fuzzy_pick') && $items) {
        $labels = [];
        foreach ($items as $k => $m) {
            $labels[(string)$k] = (string)$m['name'];
        }
        $p = rh_ai_fuzzy_pick($q, $labels);
        if ($p['clear'] !== null) {
            return ['status' => 'one', 'item' => $items[(int)$p['clear']], 'read_as' => true];
        }
        if ($p['options']) {
            return ['status' => 'several', 'options' => array_map(static fn($k) => $labels[$k], $p['options'])];
        }
    }
    return ['status' => 'none'];
}

/** Menu items whose recipes use these stock items: [ingredient_id => [menu item names]] (to share a slow ingredient). */
function rh_ai_menu_users_of(PDO $db, array $ids, int $skipItem = 0): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT DISTINCT sri.ingredient_id, mi.id, mi.item_name FROM stock_recipe_ingredients sri
                        JOIN stock_recipes sr ON sr.id = sri.recipe_id
                        JOIN menu_items mi ON mi.id = sr.menu_item_id
                        JOIN menu_categories mc ON mc.id = mi.category_id AND mc.slug = sr.menu_type AND mc.is_active = 1
                        WHERE sri.ingredient_id IN ($in) ORDER BY mi.item_name LIMIT 500");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['id'] === $skipItem) {
            continue;
        }
        $out[(int)$r['ingredient_id']][] = rh_ai_plain((string)$r['item_name'], 60);
    }
    return $out;
}

/**
 * Use, loss and shelf life of these stock items: sold through the till / room service (less void restores - the stock
 * count screen's rule), thrown away, expired or recalled, missing at counts, and the usual shelf life from batches.
 */
function rh_ai_menu_movement(PDO $db, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $short = rh_ai_num('ai_menu_waste_days_short');
    $long = max($short, rh_ai_num('ai_menu_waste_days_long'));
    $fromS = date('Y-m-d H:i:s', strtotime('-' . $short . ' days'));
    $fromL = date('Y-m-d H:i:s', strtotime('-' . $long . ' days'));
    $cost = function_exists('rh_stock_movement_cost_sql') ? rh_stock_movement_cost_sql('sa', 'i') : 'i.cost_per_unit';
    $in = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    try {
        $st = $db->prepare("SELECT sa.ingredient_id,
               COALESCE(SUM(CASE WHEN sa.created_at >= ? AND sa.source_type IN ('pos_order','room_service') THEN ABS(sa.quantity_change) WHEN sa.created_at >= ? AND sa.source_type = 'void_restore' THEN -ABS(sa.quantity_change) ELSE 0 END), 0) AS used_s,
               COALESCE(SUM(CASE WHEN sa.source_type IN ('pos_order','room_service') THEN ABS(sa.quantity_change) WHEN sa.source_type = 'void_restore' THEN -ABS(sa.quantity_change) ELSE 0 END), 0) AS used_l,
               COALESCE(SUM(CASE WHEN sa.created_at >= ? AND sa.source_type IN ('wastage','expiry','recall') THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS lost_s,
               COALESCE(SUM(CASE WHEN sa.source_type IN ('wastage','expiry','recall') THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS lost_l,
               COALESCE(SUM(CASE WHEN sa.source_type IN ('wastage','expiry','recall') THEN ABS(sa.quantity_change) * $cost ELSE 0 END), 0) AS lost_cost_l,
               COALESCE(SUM(CASE WHEN sa.source_type = 'variance' AND sa.quantity_change < 0 THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS missing_l
            FROM stock_adjustments sa JOIN stock_ingredients i ON i.id = sa.ingredient_id
            WHERE sa.ingredient_id IN ($in) AND sa.created_at >= ? GROUP BY sa.ingredient_id");
        $st->execute(array_merge([$fromS, $fromS, $fromS], $ids, [$fromL]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['ingredient_id']] = ['used_s' => max(0.0, (float)$r['used_s']), 'used_l' => max(0.0, (float)$r['used_l']), 'lost_s' => (float)$r['lost_s'],
                'lost_l' => (float)$r['lost_l'], 'lost_cost_l' => (float)$r['lost_cost_l'], 'missing_l' => (float)$r['missing_l']];
        }
    } catch (Throwable $e) {
        // no adjustments table: no history to show
    }
    try {
        $st = $db->prepare("SELECT ingredient_id, ROUND(AVG(DATEDIFF(expiry_date, received_date))) AS life FROM stock_batches
                            WHERE ingredient_id IN ($in) AND expiry_date IS NOT NULL AND received_date IS NOT NULL AND expiry_date >= received_date
                              AND received_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY) GROUP BY ingredient_id");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['ingredient_id']]['life'] = (int)$r['life'];
        }
    } catch (Throwable $e) {
        // no batches
    }
    return $out + ['_days' => [$short, $long]];
}

/**
 * Batches of these stock items (or all, $ids = null) expiring from today up to ai_menu_expiry_days ahead, soonest first;
 * and those already past their date but still marked active (to check, never to cook).
 * @return array{soon:array,past:array,days:int}
 */
function rh_ai_menu_expiring(PDO $db, ?array $ids = null): array
{
    $days = rh_ai_num('ai_menu_expiry_days');
    $today = rh_ai_today();
    $until = date('Y-m-d', strtotime($today . ' +' . $days . ' days'));
    $where = '';
    $params = [$until];
    if ($ids !== null) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return ['soon' => [], 'past' => [], 'days' => $days];
        }
        $where = ' AND b.ingredient_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_merge($params, $ids);
    }
    try {
        $st = $db->prepare("SELECT b.ingredient_id, b.batch_number, b.quantity_remaining, b.expiry_date, i.name, i.unit, i.cost_per_unit
                            FROM stock_batches b JOIN stock_ingredients i ON i.id = b.ingredient_id
                            WHERE b.status = 'active' AND b.quantity_remaining > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date <= ? AND i.is_archived = 0 $where
                            ORDER BY b.expiry_date, i.name LIMIT 300");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['soon' => [], 'past' => [], 'days' => $days];
    }
    $soon = [];
    $past = [];
    foreach ($rows as $r) {
        $line = ['ingredient_id' => (int)$r['ingredient_id'], 'stock_item' => rh_ai_plain((string)$r['name'], 60), 'batch' => (string)$r['batch_number'],
            'left' => rh_ai_menu_qty((float)$r['quantity_remaining']) . ' ' . (string)$r['unit'], 'qty' => (float)$r['quantity_remaining'], 'expires' => (string)$r['expiry_date'],
            'value' => round((float)$r['quantity_remaining'] * (float)$r['cost_per_unit'], 2)];
        if ((string)$r['expiry_date'] < $today) {
            $past[] = $line;
        } else {
            $soon[] = $line;
        }
    }
    return ['soon' => $soon, 'past' => $past, 'days' => $days];
}

function rh_ai_menu_pack(array $row): string
{
    $name = trim((string)($row['pack_name'] ?? ''));
    $size = (float)($row['pack_size'] ?? 0);
    if ($name === '' && $size <= 0) {
        return '';
    }
    return 'bought by the ' . ($name !== '' ? rh_ai_plain($name, 30) : 'pack') . ($size > 0 ? ' of ' . rh_ai_menu_qty($size) . ' ' . (string)$row['unit'] : '');
}

// ── The analysis ────────────────────────────────────────────────────────────

/**
 * The core: recipe lines [[name, qty, unit, ?row(already matched), ?yield]] checked against stock.
 * $fromMenu: lines from a recipe on file (already in stock units, matched, with the recipe line's own yield).
 */
function rh_ai_menu_analyse(PDO $db, string $dish, array $lines, string $kind, ?int $portions, array $ctx = []): array
{
    $stock = rh_ai_menu_stock_rows($db);
    $active = count(array_filter($stock, static fn($r) => (int)$r['is_archived'] === 0));
    $cur = rh_ai_currency();
    $out = ['dish' => rh_ai_plain($dish, 60), 'kind' => $kind, 'recipe_from' => (string)($ctx['recipe_from'] ?? 'given')];
    if (!empty($ctx['portion'])) {
        $out['portion'] = rh_ai_plain((string)$ctx['portion'], 60);
    }
    $ing = [];
    $need = []; // ingredient id => raw stock units per portion (lines of the same item added up)
    $byId = [];
    $missing = [];
    $notComparable = [];
    $asks = [];
    $cost = 0.0;
    $costLeftOut = [];
    foreach ($lines as $ln) {
        [$name, $qty, $unit] = [(string)$ln[0], (float)$ln[1], (string)$ln[2]];
        $row = $ln[3] ?? null;
        $line = ['asked' => rh_ai_plain($name, 60)];
        if ($row === null) {
            $m = rh_ai_menu_match_ingredient($name, $stock);
            $line['per_portion'] = rh_ai_menu_qty($qty) . ' ' . $unit;
            if (in_array($m['status'], ['not_stocked', 'archived'], true)) {
                $line['match'] = $m['status'] === 'archived' ? 'no longer stocked (archived: ' . rh_ai_plain((string)$m['row']['name'], 60) . ')' : 'not stocked';
                if ($m['close']) {
                    $line['close'] = $m['close'];
                }
                $missing[] = $line['asked'];
                $costLeftOut[] = $line['asked'];
                $ing[] = $line;
                continue;
            }
            $row = $m['row'];
            $line['match'] = $m['status'] === 'read_as' ? 'read as' : ($m['status'] === 'several' ? 'best of several' : 'matched');
            if ($m['others']) {
                $line['also_could_be'] = $m['others'];
                $asks[] = $line['asked'] . ': ' . rh_ai_plain((string)$row['name'], 60) . ' or ' . implode(' or ', $m['others']);
            }
            $factor = rh_ai_menu_unit_factor($unit, $row);
            $yield = (float)$row['yield_percent'];
        } else {
            $factor = 1.0; // a recipe on file is already in the stock unit
            $yield = (float)($ln[4] ?? $row['yield_percent']);
            $line['per_portion'] = rh_ai_menu_qty($qty) . ' ' . (string)$row['unit'];
            $line['match'] = 'on the recipe';
        }
        $line['stock_item'] = rh_ai_plain((string)$row['name'], 60);
        $onHand = (float)$row['current_quantity'];
        $line['on_hand'] = rh_ai_menu_qty($onHand) . ' ' . (string)$row['unit'];
        $line['level'] = function_exists('rh_ai_stock_level_word') ? rh_ai_stock_level_word($onHand, (float)$row['threshold']) : ($onHand > 0 ? 'ok' : 'out');
        if (($pk = rh_ai_menu_pack($row)) !== '') {
            $line['pack'] = $pk;
        }
        $byId[(int)$row['id']] = $row;
        if ($factor === null) {
            $line['unit_differs'] = 'the recipe uses ' . $unit . ', stock is counted in ' . (string)$row['unit'] . ' - set the amount on Stock > Recipes';
            $notComparable[] = $line['asked'];
            $costLeftOut[] = $line['asked'];
            $ing[] = $line;
            continue;
        }
        $perStock = $qty * $factor;
        $raw = $perStock / rh_stock_yield_factor($yield); // the Recipes screen and the deduction use the unrounded figure
        if ($yield > 0 && $yield < 100) {
            $line['yield_pct'] = round($yield, 1);
        }
        $line['uses_from_stock'] = rh_ai_menu_qty($raw) . ' ' . (string)$row['unit'];
        $cpu = (float)$row['cost_per_unit'];
        if ($cpu > 0) {
            $line['cost'] = round($raw * $cpu, 2);
            $cost += $raw * $cpu;
        } else {
            $line['cost'] = 'no cost on file';
            $costLeftOut[] = $line['asked'];
        }
        $need[(int)$row['id']] = ($need[(int)$row['id']] ?? 0.0) + $raw;
        $ing[] = $line;
    }
    // Portions the stock allows: the first ingredient to run out sets it (each item's need added up over its lines)
    $possible = null;
    $limitedBy = '';
    foreach ($need as $id => $raw) {
        if ($raw <= 0) {
            continue;
        }
        $n = (int)floor(max(0.0, (float)$byId[$id]['current_quantity']) / $raw + 1e-9);
        foreach ($ing as $k => $l) {
            if (($l['stock_item'] ?? null) === rh_ai_plain((string)$byId[$id]['name'], 60) && !isset($l['unit_differs'])) {
                $ing[$k]['portions_it_allows'] = $n;
            }
        }
        if ($possible === null || $n < $possible) {
            $possible = $n;
            $limitedBy = rh_ai_plain((string)$byId[$id]['name'], 60);
        }
    }
    $out['ingredients'] = $ing;
    $out['ingredients_total'] = count($ing);
    $out['not_stocked_total'] = count($missing);
    $out['unit_differs_total'] = count($notComparable);
    $out['stock_items_checked'] = $active;
    $out['portions_possible'] = $possible ?? 0;
    if ($limitedBy !== '') {
        $out['first_to_run_out'] = $limitedBy;
    }
    if ($missing) {
        $out['not_stocked'] = $missing;
        $out['not_stocked_checked'] = 'checked against all ' . $active . ' stock items, archived ones too';
    }
    if ($notComparable) {
        $out['unit_differs'] = $notComparable;
    }
    $cost = round($cost, 2);
    $out['cost_per_portion'] = $cost;
    if ($costLeftOut) {
        $out['cost_leaves_out'] = $costLeftOut;
        $out['cost_is'] = 'only for the ingredients that could be costed';
    }
    if ($portions !== null && $portions > 0) {
        $short = [];
        foreach ($need as $id => $raw) {
            $total = round($raw * $portions, 3);
            $have = (float)$byId[$id]['current_quantity'];
            if ($total > $have + 0.0005) {
                $short[] = ['stock_item' => rh_ai_plain((string)$byId[$id]['name'], 60), 'needed' => rh_ai_menu_qty($total) . ' ' . (string)$byId[$id]['unit'],
                    'short_by' => rh_ai_menu_qty($total - max(0.0, $have)) . ' ' . (string)$byId[$id]['unit']];
            }
        }
        $out['for_portions'] = ['portions' => $portions, 'enough' => !$short && !$missing && !$notComparable, 'short' => $short];
        if ($cost > 0) {
            $out['for_portions']['ingredient_cost'] = round($cost * $portions, 2);
        }
    }
    if ($cost > 0 && count($costLeftOut) * 2 > count($ing)) { // most of the recipe has no comparable cost: no price from the rest
        $out['price_needs'] = 'a price needs the cost of ' . implode(', ', $costLeftOut) . ' first';
    } elseif ($cost > 0) {
        $out['price'] = rh_ai_menu_price($cost, $kind);
        if ($costLeftOut) { // a price worked from part of the recipe is a floor, never the answer
            $out['price']['at_least'] = true;
            $out['price']['why_at_least'] = 'the items left out of the cost will raise it';
        }
        if (isset($ctx['till_price'])) {
            $tp = (float)$ctx['till_price'];
            $out['now'] = ['till_price' => round($tp, 2), 'food_cost_pct' => $tp > 0 ? round($cost / $tp * 100, 1) : null];
            if ($tp > 0) {
                $out['now']['band'] = rh_ai_menu_band(round($cost / $tp * 100, 1));
            }
        }
    }
    // Similar items on the menu: a shared name word, or a recipe using the costliest ingredient
    $items = $ctx['items'] ?? rh_ai_menu_items($db);
    $skip = (int)($ctx['item_id'] ?? 0);
    $dw = array_values(array_filter(rh_ai_menu_words($dish, true), static fn($w) => mb_strlen($w) >= 4));
    $topIng = 0;
    $topCost = -1.0;
    foreach ($need as $id => $raw) {
        $c = $raw * (float)$byId[$id]['cost_per_unit'];
        if ($c > $topCost) {
            $topCost = $c;
            $topIng = $id;
        }
    }
    $users = rh_ai_menu_users_of($db, array_keys($need), $skip);
    $sim = [];
    foreach ($items as $m) {
        if ((int)$m['id'] === $skip) {
            continue;
        }
        $mw = rh_ai_menu_words((string)$m['name'], false);
        if ($dw && array_intersect($dw, $mw)) {
            $sim[(int)$m['id']] = rh_ai_menu_item_line($m) + ['why' => 'similar name'];
        }
    }
    if ($topIng > 0) {
        foreach ($items as $m) {
            if ((int)$m['id'] !== $skip && !isset($sim[(int)$m['id']]) && in_array(rh_ai_plain((string)$m['name'], 60), $users[$topIng] ?? [], true)) {
                $sim[(int)$m['id']] = rh_ai_menu_item_line($m) + ['why' => 'also uses ' . rh_ai_plain((string)$byId[$topIng]['name'], 60)];
            }
        }
    }
    $out['similar_on_menu'] = array_values(array_slice($sim, 0, rh_ai_num('ai_alt_menu_max')));
    $out['menu_items_checked'] = count($items);
    // Batches to use first, and the wastage / shelf-life advice
    $exp = rh_ai_menu_expiring($db, array_keys($need));
    if ($exp['soon']) {
        $out['use_first'] = array_map(static fn($b) => ['stock_item' => $b['stock_item'], 'batch' => $b['batch'], 'left' => $b['left'], 'expires' => $b['expires']], array_slice($exp['soon'], 0, 5));
    }
    if ($exp['past']) {
        $out['past_expiry'] = array_map(static fn($b) => ['stock_item' => $b['stock_item'], 'batch' => $b['batch'], 'left' => $b['left'], 'expired' => $b['expires']], array_slice($exp['past'], 0, 3));
    }
    $out['advice'] = rh_ai_menu_advice($db, $need, $byId, $users, $cur);
    if ($asks) {
        $out['ask'] = 'Which one do you use? ' . implode('; ', array_slice($asks, 0, 3)) . '.';
    }
    $out['to_add_it'] = 'Menu Management: add the dish and its till price, then set what it uses from stock (or Stock > Recipes). Nothing is changed from the chat.';
    return $out;
}

/** Plain wastage, count and shelf-life advice per stock item, worked out from its history (numbers as shown). */
function rh_ai_menu_advice(PDO $db, array $need, array $byId, array $users, string $cur): array
{
    $mv = rh_ai_menu_movement($db, array_keys($need));
    [$short, $long] = $mv['_days'] ?? [rh_ai_num('ai_menu_waste_days_short'), rh_ai_num('ai_menu_waste_days_long')];
    $highPct = rh_ai_menu_pct('ai_menu_waste_high_pct');
    $lifeShort = rh_ai_num('ai_menu_short_life_days');
    $out = [];
    $unused = [];
    foreach ($need as $id => $raw) {
        $row = $byId[$id];
        $n = rh_ai_plain((string)$row['name'], 60);
        $u = (string)$row['unit'];
        $m = (array)($mv[$id] ?? []) + ['used_s' => 0.0, 'used_l' => 0.0, 'lost_s' => 0.0, 'lost_l' => 0.0, 'lost_cost_l' => 0.0, 'missing_l' => 0.0];
        $share = array_slice($users[$id] ?? [], 0, 2);
        if ($m['lost_l'] > 0) {
            $base = $m['used_l'] + $m['lost_l'];
            $pct = $base > 0 ? round($m['lost_l'] / $base * 100, 1) : 100.0;
            $txt = $n . ': ' . rh_ai_menu_qty($m['lost_l']) . ' ' . $u . ' thrown away, expired or recalled in the last ' . $long . ' days (' . rh_ai_fmt_cur($cur, round($m['lost_cost_l'], 2)) . ', '
                . rtrim(rtrim(number_format($pct, 1), '0'), '.') . '% of what went out)';
            $out[] = $pct >= $highPct ? $txt . ' - buy smaller amounts more often and use the oldest first.' : $txt . '.';
        }
        if ($m['missing_l'] > 0) {
            $out[] = $n . ': ' . rh_ai_menu_qty($m['missing_l']) . ' ' . $u . ' missing at stock counts in the last ' . $long . ' days - check portioning and storage.';
        }
        $life = isset($m['life']) ? (int)$m['life'] : null;
        $daily = $m['used_s'] / max(1, $short);
        if ($life !== null && $life <= $lifeShort) {
            if ($daily <= 0) {
                $out[] = $n . ': keeps about ' . $life . ' days and nothing was used in the last ' . $short . ' days - buy small for this dish' . ($share ? ', or share it with ' . implode(' and ', $share) : '') . '.';
            } else {
                $cover = (float)$row['current_quantity'] / $daily;
                if ($cover > $life) {
                    $out[] = $n . ': keeps about ' . $life . ' days but the stock on hand lasts about ' . (int)round($cover) . ' days at the last ' . $short . ' days\' use - buy small' . ($share ? ', or share it with ' . implode(' and ', $share) : '') . '.';
                }
            }
        }
        if ($m['used_l'] <= 0 && empty($users[$id])) {
            $unused[] = $n;
        }
    }
    if ($unused) {
        $many = count($unused) > 1;
        $last = array_pop($unused);
        $out[] = ($unused ? implode(', ', $unused) . ' and ' . $last : $last) . ': no dish on the menu uses ' . ($many ? 'them' : 'it') . ' and nothing was sold from ' . ($many ? 'them' : 'it')
            . ' in the last ' . $long . ' days, so this dish would be the only use - start with small orders.';
    }
    return array_slice($out, 0, 8);
}

/** One menu item: its recipe on file, food cost now, portions, the price at the target and similar items. */
function rh_ai_menu_item_report(PDO $db, array $item, array $items, ?int $portions): array
{
    $kind = rh_ai_menu_kind_of($item);
    $base = ['item' => rh_ai_plain((string)$item['name'], 60), 'till_price' => round((float)$item['price'], 2), 'category' => rh_ai_plain((string)$item['category'], 40),
        'on_sale' => (int)$item['is_available'] === 1, 'menu_items_checked' => count($items)];
    if (empty($item['recipe_id'])) {
        return $base + ['recipe' => 'none yet', 'food_cost' => 'cannot be worked out without a recipe - add one on Stock > Recipes or "What it uses from stock" on Menu Management'];
    }
    $st = $db->prepare("SELECT sri.quantity_per_portion, sri.yield_percent, i.id, i.name, i.unit, i.current_quantity, i.cost_per_unit, i.yield_percent AS item_yield, i.is_archived, i.pack_name, i.pack_size, i.pieces_per_unit, "
        . (function_exists('rh_stock_threshold_sql') ? rh_stock_threshold_sql('i') : 'i.min_quantity') . " AS threshold
        FROM stock_recipe_ingredients sri JOIN stock_ingredients i ON i.id = sri.ingredient_id WHERE sri.recipe_id = ? ORDER BY sri.id");
    $st->execute([(int)$item['recipe_id']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lines[] = [(string)$r['name'], (float)$r['quantity_per_portion'], (string)$r['unit'], $r, (float)$r['yield_percent']];
    }
    if (!$lines) {
        return $base + ['recipe' => 'on file but empty', 'food_cost' => 'cannot be worked out - the recipe lists no ingredients (Stock > Recipes)'];
    }
    $a = rh_ai_menu_analyse($db, (string)$item['name'], $lines, $kind, $portions, ['recipe_from' => 'the recipe on file', 'till_price' => (float)$item['price'], 'items' => $items, 'item_id' => (int)$item['id']]);
    unset($a['dish'], $a['to_add_it']);
    $a['to_change_it'] = 'Price: Menu Management. Recipe: Stock > Recipes. Nothing is changed from the chat.';
    return $base + $a;
}

// ── Views over the whole menu ───────────────────────────────────────────────

function rh_ai_menu_view_under_priced(array $items): array
{
    $ok = rh_ai_menu_pct('ai_menu_cost_ok_pct');
    $high = rh_ai_menu_pct('ai_menu_cost_high_pct');
    $with = array_values(array_filter($items, static fn($m) => !empty($m['recipe_id'])));
    $re = [];
    $tight = [];
    $noPrice = [];
    $resale = [];
    foreach ($with as $m) {
        $price = (float)$m['price'];
        $cost = round((float)$m['recipe_cost'], 2);
        if ($price <= 0) {
            $noPrice[] = rh_ai_plain((string)$m['name'], 60);
            continue;
        }
        if ($cost <= 0) {
            continue;
        }
        $pct = round($cost / $price * 100, 1);
        $line = rh_ai_menu_item_line($m);
        if (rh_ai_menu_is_resale($m)) { // bought in and sold as is: the dish bands do not fit; compared with the shop price instead
            if ($pct > $high) {
                $resale[] = $line;
            }
            continue;
        }
        $p = rh_ai_menu_price($cost, rh_ai_menu_kind_of($m));
        if ($p) {
            $line['price_at_target'] = $p['suggested_till_price'];
        }
        if ($pct > $high) {
            $re[] = $line;
        } elseif ($pct >= $ok) {
            $tight[] = $line;
        }
    }
    $sort = static fn($a, $b) => ($b['food_cost_pct'] ?? 0) <=> ($a['food_cost_pct'] ?? 0);
    usort($re, $sort);
    usort($tight, $sort);
    usort($resale, $sort);
    return ['view' => 'under_priced', 'bought_in' => $resale, 'bought_in_total' => count($resale), 'checked' => ['menu_items' => count($items), 'with_recipe' => count($with)], 're_price' => $re, 're_price_total' => count($re),
        'tight' => $tight, 'tight_total' => count($tight), 'no_price' => $noPrice, 'bands' => ['re_price_over_pct' => round($high, 1), 'tight_from_pct' => round($ok, 1), 'tight_to_pct' => round($high, 1), 'from' => 'the Recipes screen'],
        'items_without_recipe' => count($items) - count($with)];
}

function rh_ai_menu_view_no_recipe(array $items): array
{
    $none = array_values(array_filter($items, static fn($m) => empty($m['recipe_id'])));
    return ['view' => 'no_recipe', 'menu_items_checked' => count($items), 'no_recipe_total' => count($none),
        'no_recipe' => array_map(static fn($m) => rh_ai_plain((string)$m['name'], 60) . ' (' . rh_ai_plain((string)$m['category'], 30) . ')', $none),
        'why_it_matters' => 'Without a recipe a sale takes nothing off stock and its food cost is unknown.'];
}

function rh_ai_menu_view_use_up(PDO $db, array $items): array
{
    $exp = rh_ai_menu_expiring($db, null);
    $ids = array_column($exp['soon'], 'ingredient_id');
    $users = [];
    $perPortion = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $y = function_exists('rh_stock_yield_sql') ? rh_stock_yield_sql('sri.yield_percent') : '1';
        $st = $db->prepare("SELECT sri.ingredient_id, mi.id AS item_id, mi.item_name, mi.is_available, SUM(sri.quantity_per_portion / $y) AS raw
                            FROM stock_recipe_ingredients sri JOIN stock_recipes sr ON sr.id = sri.recipe_id JOIN menu_items mi ON mi.id = sr.menu_item_id
                            JOIN menu_categories mc ON mc.id = mi.category_id AND mc.slug = sr.menu_type AND mc.is_active = 1
                            WHERE sri.ingredient_id IN ($in) GROUP BY sri.ingredient_id, mi.id, mi.item_name, mi.is_available ORDER BY mi.item_name");
        $st->execute(array_values(array_unique($ids)));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $users[(int)$r['ingredient_id']][] = ['name' => rh_ai_plain((string)$r['item_name'], 60), 'on_sale' => (int)$r['is_available'] === 1];
            $perPortion[(int)$r['ingredient_id']][rh_ai_plain((string)$r['item_name'], 60)] = (float)$r['raw'];
        }
    }
    $cur = rh_ai_currency();
    $lines = [];
    $value = 0.0;
    foreach ($exp['soon'] as $b) {
        $value += $b['value'];
        $dishes = [];
        foreach ($users[$b['ingredient_id']] ?? [] as $d) {
            $raw = $perPortion[$b['ingredient_id']][$d['name']] ?? 0.0;
            $dishes[] = $d['name'] . ($raw > 0 ? ' (this batch covers about ' . (int)floor($b['qty'] / $raw + 1e-9) . ' portions)' : '') . ($d['on_sale'] ? '' : ' - not on sale now');
        }
        $lines[] = ['stock_item' => $b['stock_item'], 'batch' => $b['batch'], 'left' => $b['left'], 'expires' => $b['expires'], 'value' => $b['value'],
            'dishes_using_it' => $dishes ?: 'no dish on the menu uses it - a special could'];
    }
    return ['view' => 'use_up', 'expiring' => ['total' => count($exp['soon']), 'within_days' => (int)$exp['days'], 'value' => round($value, 2), 'batches' => $lines],
        'past_expiry' => array_map(static fn($b) => ['stock_item' => $b['stock_item'], 'batch' => $b['batch'], 'left' => $b['left'], 'expired' => $b['expires']], $exp['past']),
        'past_expiry_total' => count($exp['past']), 'menu_items_checked' => count($items), 'currency' => $cur,
        'safety' => 'Food past its expiry date is checked, and recorded on Stock > Wastage if spoiled - never cooked.'];
}

// ── The tool ────────────────────────────────────────────────────────────────

function rh_ai_t_menu_advisor(PDO $db, array $user, array $a): array
{
    $items = rh_ai_menu_items($db);
    $portions = isset($a['portions']) ? (int)$a['portions'] : null;
    $view = (string)($a['view'] ?? '');
    if ($view === 'under_priced') {
        return rh_ai_menu_view_under_priced($items);
    }
    if ($view === 'no_recipe') {
        return rh_ai_menu_view_no_recipe($items);
    }
    if ($view === 'use_up') {
        return rh_ai_menu_view_use_up($db, $items);
    }
    $recipe = trim((string)($a['recipe'] ?? ''));
    $dish = trim((string)($a['dish'] ?? ''));
    if ($recipe !== '') {
        $lines = rh_ai_menu_parse_recipe($recipe);
        if (!$lines) {
            return ['error' => 'The recipe could not be read. Give one portion as "ingredient quantity unit; ..." (g, ml or pcs).'];
        }
        $kind = (string)($a['kind'] ?? 'food');
        $res = rh_ai_menu_analyse($db, $dish !== '' ? $dish : 'this dish', $lines, $kind === 'drink' ? 'drink' : 'food', $portions,
            ['recipe_from' => ($a['recipe_by'] ?? '') === 'assistant' ? 'a typical portion' : 'given', 'portion' => (string)($a['serving'] ?? ''), 'items' => $items]);
        if ($dish !== '' && ($f = rh_ai_menu_find_item($items, $dish))['status'] === 'one' && empty($f['read_as'])) {
            $res['already_on_menu'] = rh_ai_menu_item_line($f['item']);
        }
        return $res;
    }
    $name = (string)($a['item'] ?? $dish);
    if ($name !== '') {
        $f = rh_ai_menu_find_item($items, $name);
        if ($f['status'] === 'one') {
            $r = rh_ai_menu_item_report($db, $f['item'], $items, $portions);
            return !empty($f['read_as']) ? ['read_as' => 'Nothing on the menu is called exactly that; read_as_name is clearly the one meant. Show that real name.', 'read_as_name' => rh_ai_plain((string)$f['item']['name'], 60)] + $r : $r;
        }
        if ($f['status'] === 'several') {
            return ['asked' => rh_ai_plain($name, 60), 'on_menu' => 'several items match', 'close_matches' => $f['options'], 'ask' => 'Ask which one ("Do you mean ... or ...?"); never pick one yourself.', 'menu_items_checked' => count($items)];
        }
        return ['asked' => rh_ai_plain($name, 60), 'on_menu' => 'not on the menu', 'menu_items_checked' => count($items),
            'needs_recipe' => 'To check stock and price for a new dish, give a one-portion recipe in recipe ("ingredient qty unit; ...").'];
    }
    // No arguments: the menu at a glance
    $up = rh_ai_menu_view_under_priced($items);
    $nr = rh_ai_menu_view_no_recipe($items);
    $uu = rh_ai_menu_view_use_up($db, $items);
    return ['menu_items_checked' => count($items), 'with_recipe' => $up['checked']['with_recipe'], 'no_recipe_total' => $nr['no_recipe_total'],
        're_price_total' => $up['re_price_total'], 're_price' => array_slice($up['re_price'], 0, 3), 'tight_total' => $up['tight_total'],
        'expiring_batches' => $uu['expiring']['total'], 'expiring_within_days' => $uu['expiring']['within_days'], 'use_up' => array_slice($uu['expiring']['batches'], 0, 3)];
}

// ── Local answers (no model) and the one-portion proposal ───────────────────

/**
 * Menu questions recognised from the person's own words (scrubbed, never typo-read). [] = not a menu question.
 * @return array{mode:string,dish?:string,view?:string,portions?:int}|null
 */
function rh_ai_menu_intent(string $tok): ?array
{
    $t = trim(mb_strtolower($tok));
    if ($t === '' || mb_strlen($t) > 240) {
        return null;
    }
    $t = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/\b(please|pls|kindly|quickly|hey|hi|ok|okay)\b|[!]+/u', ' ', $t)));
    $portions = null;
    if (preg_match('/\bfor (\d{1,4}) (portions?|people|persons|guests|covers|plates|servings|pax)\b/u', $t, $pm)) {
        $portions = max(1, min(2000, (int)$pm[1]));
        $t = trim((string)preg_replace('/\s+/u', ' ', str_replace($pm[0], ' ', $t)));
    }
    $ret = static function (string $mode, array $x = []) use ($portions): array {
        return ['mode' => $mode] + $x + ($portions !== null ? ['portions' => $portions] : []);
    };
    // Changing a menu price, a dish or a recipe is never done here (rail 1): one plain line and the right screens. Any other
    // change request goes on to the general "can't change" line.
    if (preg_match('/^\s*(change|set|update|edit|raise|lower|increase|decrease|reduce|delete|remove|adjust|put up|bring down|fix|correct)\b/u', $t)) {
        return preg_match('/\b(menu|recipes?|dish(es)?|food cost|portion sizes?|what it uses)\b/u', $t) ? ['mode' => 'change'] : null;
    }
    if (preg_match('/\b(what|which|anything)\b.{0,40}\b(make|cook|use|serve|prepare|run|put on)\b.{0,50}\b(expir\w*|going off|use up|short.?dated|before it goes off|about to go off|go off)\b/u', $t)
        || preg_match('/\buse up\b.{0,40}\b(stock|ingredients?|expir\w*|batches)\b|^(what(\'s| is) )?expiring\b.{0,40}\b(make|cook|dish|menu|special)\b/u', $t)) {
        return $ret('view', ['view' => 'use_up']);
    }
    if (preg_match('/\b(under.?priced|too cheap|priced too low|losing money on|(high|too high) food cost|food cost (is )?too high|need(s)? (a )?re.?pric\w*|should (we|i) re.?price|re.?price)\b/u', $t)
        && preg_match('/\b(menu|items?|dishes|drinks|which|what|anything)\b/u', $t)) {
        return $ret('view', ['view' => 'under_priced']);
    }
    if (preg_match('/\b(no|without( a)?|missing( a)?|lack( a)?) recipes?\b|\b(have|has)(n\'t| not)( got)?( a)? recipes?\b|\brecipes? (is |are )?missing\b/u', $t)
        && preg_match('/\b(menu|items?|dishes|drinks|which|what|anything)\b/u', $t)) {
        return $ret('view', ['view' => 'no_recipe']);
    }
    $clean = static function (string $d): string {
        $d = trim((string)preg_replace('/\b(on|to|for|in) (the|our) (menu|kitchen|restaurant|bar)\b|\b(today|tonight|now|this week|with (what|the stock|the ingredients) we have|from (our|the) stock|from what we have)\b|[?.,:;]+$/u', ' ', $d));
        $d = trim((string)preg_replace('/^(a|an|some|the|our|my)\s+/u', '', trim((string)preg_replace('/\s+/u', ' ', $d))));
        $d = trim((string)preg_replace('/\s+for (the|a|an|our|their|his|her|this|that) [\p{L}\- ]{2,30}$/u', '', $d)); // "cake for the wedding" -> "cake"
        return trim($d, " ?.,'\"");
    };
    $valid = static function (string $d): bool {
        return $d !== '' && mb_strlen($d) >= 3 && mb_strlen($d) <= 60 && strpos($d, '[') === false && preg_match('/\p{L}{3}/u', $d)
            // a period is never a dish ("how should we price the weekend?" is about room rates)
            && !preg_match('/^(this |next |the |our )?(weekends?|weekdays?|week|month|year|season|high season|low season|peak season|off season|holidays?|christmas|easter|new year|tonight|tomorrow|summer|winter|spring|autumn|festive season|long weekend)$/u', $d)
            && !preg_match('/\b(bookings?|reservations?|rooms?|beds?|payments?|refunds?|deposits?|invoices?|receipts?|orders?|tabs?|tables?|changes?|notes?|reports?|deals?|discounts?|offers?|upgrades?|rates?|prices?|it|that|this|them|money|sales?|profit|transfers?|appointments?|calls?|backups?|accounts?|logins?|keys?|cards?|shifts?|list|menu|recipe|recipes|mistake|difference|decision|plan|it work|sense|time|guests?|staff|exceptions?|announcements?|copy|copies|arrangements?|requests?|complaints?|check.?ins?|check.?outs?|stays?|nights?|events?|meetings?|space|progress|profit|budget|count|stock take|stock count)\b/u', $d);
    };
    $art = '(?:(?:an?|the|our|one|some) )?';
    $pick = static function (array $res) use ($t): ?string {
        foreach ($res as $re) {
            if (preg_match($re, $t, $m) && isset($m['d']) && $m['d'] !== '') {
                return (string)$m['d'];
            }
        }
        return null;
    };
    // One menu item's food cost: "food cost of X", "what does X cost to make", "margin on X"
    if (($d = $pick(['/\b(?:food cost|cost per portion|recipe cost|ingredient cost|margin|gross profit|gp)(?: %| percent(?:age)?)? (?:of|for|on) ' . $art . '(?<d>.+)$/u',
        '/^(?:how much )?(?:does|do|did) ' . $art . '(?<d>.+?) cost (?:us )?(?:to make|to cook|to prepare|per portion|a portion)\??$/u'])) !== null) {
        $d = $clean($d);
        return $valid($d) ? $ret('item', ['dish' => $d]) : null;
    }
    // Portions: "how many portions of X can we make (with the stock)"
    if (($d = $pick(['/^how many (?:portions?|plates|servings|meals|bowls|cups|glasses) (?:of )?' . $art . '(?<d>.+?) (?:can|could) (?:we|i|the kitchen) (?:make|do|serve|cook|prepare)\b.*$/u'])) !== null) {
        $d = $clean($d);
        return $valid($d) ? $ret('item', ['dish' => $d, 'ask' => 'portions']) : null;
    }
    // A price: "what should we charge for X", "suggest a price for X", "how much should X sell for", "pricing for X"
    if (($d = $pick(['/^(?:what|how much) (?:price )?should (?:we|i) (?:charge|ask|sell|price)(?: for| it at)? ' . $art . '(?<d>.+?)(?: at| for)?\??$/u',
        '/\b(?:suggest(?:ed)?|best|right|good|fair|ideal|recommended) (?:an? |the )?(?:selling |sale |till |menu )?price (?:for|of|on) ' . $art . '(?<d>.+)$/u',
        '/^(?:what|how much) should ' . $art . '(?<d>.+?) (?:sell|go) for\??$/u',
        '/^(?:how (?:should|do) (?:we|i) price|pricing for|price for) ' . $art . '(?<d>.+)$/u'])) !== null) {
        $d = $clean($d);
        return $valid($d) ? $ret('dish', ['dish' => $d, 'ask' => 'price']) : null;
    }
    // A dish idea: "can we make X", "do we have the ingredients for X", "I want to add X to the menu", "what do we need to make X"
    if (($d = $pick(['/^(?:can|could|should) (?:we|i|the kitchen|the chef) (?:make|cook|serve|prepare|bake|grill|fry|roast|brew|mix|start (?:selling|serving))' . $art . '(?<d>.+)$/u',
        '/\b(?:do|have|did) (?:we|i) (?:have|got) (?:all )?(?:the |enough )?(?:ingredients|stock|everything|what we need) (?:for|to make|to cook|to do) ' . $art . '(?<d>.+)$/u',
        '/\b(?:i|we)(?:\'d| would| want| wanna| plan| am planning| are planning| are thinking of| am thinking of| was thinking of| were thinking of)(?: like)?(?: to)? (?:add|adding|put|putting|start selling|start serving|introduce|introducing|offer|offering|try|trying|launch|launching) ' . $art . '(?<d>.+?) (?:to|on|onto) (?:the|our) menu\b/u',
        '/^(?:add|put) ' . $art . '(?<d>.+?) (?:to|on|onto) (?:the|our) menu\b/u',
        '/\b(?:what|which) (?:ingredients )?(?:do|would) (?:we|i) need (?:to make|for) ' . $art . '(?<d>.+)$/u',
        '/^(?:ingredients|a recipe|recipe|portions?) for ' . $art . '(?<d>.+)$/u'])) !== null) {
        $d = $clean((string)preg_replace('/\s*(?:,|\b(?:and|but|so) (?:how|what|is|are|do|does|can|could|would|should|which|will|then|also|if)\b|\b(?:do|have) we (?:have|got)\b|\bis (?:it|that) (?:worth|possible|ok)\b).*$/u', '', $d));
        return $valid($d) ? $ret('dish', ['dish' => $d]) : null;
    }
    return null;
}

/** The frozen prompt of the one-portion proposal (cache friendly; the dish is the only thing that changes). */
function rh_ai_menu_propose_prompt(): string
{
    return 'You plan recipes for a hotel kitchen and bar. For the dish or drink named, give ONE typical portion as compact JSON only, no other text: '
        . '{"dish":"name","kind":"food or drink","portion":"short serving size","ingredients":[["ingredient",quantity,"unit"]]}. '
        . 'Units: g, ml or pcs only (weigh produce and meat in g; pcs only for eggs, bread slices, buns and similar). Plain generic ingredient names '
        . '(chicken, onion, rice, cooking oil), no brands or cuts. The main and costly ingredients only, at most 12; leave out water and salt. '
        . 'If it is not food or a drink, reply {"error":"not a dish"}.';
}

/** Read the proposal: strict JSON, whitelisted units, bounded numbers and names. [] = unreadable. */
function rh_ai_menu_read_proposal(string $text): array
{
    $t = trim($text);
    $t = (string)preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $t);
    if (($p = strpos($t, '{')) !== false) {
        $t = substr($t, $p, (int)strrpos($t, '}') - $p + 1);
    }
    $j = json_decode($t, true);
    if (!is_array($j) || !empty($j['error']) || !is_array($j['ingredients'] ?? null)) {
        return !empty($j['error']) ? ['error' => 'not a dish'] : [];
    }
    $lines = [];
    foreach ($j['ingredients'] as $x) {
        if (is_array($x) && isset($x['name'])) {
            $x = [$x['name'], $x['qty'] ?? ($x['quantity'] ?? 0), $x['unit'] ?? ''];
        }
        if (!is_array($x) || count($x) < 3 || !is_string($x[0]) || !is_numeric($x[1]) || !is_string($x[2])) {
            continue;
        }
        $name = trim(mb_substr((string)preg_replace('/[^\p{L} \'\-]/u', '', $x[0]), 0, 40));
        $unit = mb_strtolower(trim($x[2]));
        $unit = ['gram' => 'g', 'grams' => 'g', 'gr' => 'g', 'piece' => 'pcs', 'pieces' => 'pcs', 'pc' => 'pcs'][$unit] ?? $unit;
        $qty = (float)$x[1];
        if ($name === '' || !in_array($unit, ['g', 'ml', 'pcs'], true) || $qty <= 0 || $qty > 3000) {
            continue;
        }
        $lines[] = [$name, round($qty, 2), $unit];
        if (count($lines) >= 12) {
            break;
        }
    }
    if (!$lines) {
        return [];
    }
    $kind = ($j['kind'] ?? '') === 'drink' ? 'drink' : 'food';
    $recipe = implode('; ', array_map(static fn($l) => $l[0] . ' ' . rh_ai_menu_qty((float)$l[1]) . ' ' . $l[2], $lines));
    return ['recipe' => $recipe, 'kind' => $kind, 'portion' => is_string($j['portion'] ?? null) ? mb_substr(trim((string)preg_replace('/[^\p{L}\p{N} ().,\-]/u', '', $j['portion'])), 0, 60) : ''];
}

function rh_ai_menu_recipe_cache_key(string $dish): string
{
    return hash('sha256', 'menu_recipe|v1|' . implode(' ', rh_ai_menu_words($dish, false)));
}

/**
 * One typical portion of a dish idea: from the hotel-wide cache, else the Fast model (Primary once if unreadable).
 * The request carries the dish name only (it went through the privacy pass); usage is recorded like every model call.
 * @return array{ok:bool,recipe?:string,kind?:string,portion?:string,cached?:bool,tokens_in:int,tokens_out:int,cost:float,model:string,provider:string,ms:int,error:string}
 */
function rh_ai_menu_propose(PDO $db, array $user, int $conv, string $dish): array
{
    $out = ['ok' => false, 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0, 'model' => '', 'provider' => '', 'ms' => 0, 'error' => '', 'cached' => false];
    $key = rh_ai_menu_recipe_cache_key($dish);
    try {
        $st = $db->prepare("SELECT result_json FROM ai_tool_cache WHERE cache_key = ? AND expires_at > NOW()");
        $st->execute([$key]);
        $hit = json_decode((string)$st->fetchColumn(), true);
        if (is_array($hit) && !empty($hit['recipe'])) {
            return array_merge($out, $hit, ['ok' => true, 'cached' => true]);
        }
    } catch (Throwable $e) {
        // no cache: ask
    }
    if (rh_ai_budget_problem($db, (int)$user['id']) !== '') {
        $out['error'] = 'budget';
        return $out;
    }
    $models = rh_ai_role_models($db);
    $tries = array_values(array_filter([$models['fast'] ?? null, $models['primary'] ?? null]));
    if (!$tries || empty($models['provider'])) {
        $out['error'] = 'no model';
        return $out;
    }
    if (count($tries) === 2 && $tries[0]['model_id'] === $tries[1]['model_id']) {
        $tries = [$tries[0]];
    }
    foreach ($tries as $m) {
        $req = ['provider' => $models['provider'], 'model' => $m['model_id'], 'max_tokens' => 400, 'effort' => 'minimal',
            'system_frozen' => rh_ai_menu_propose_prompt(), 'messages' => [['role' => 'user', 'content' => 'Dish: ' . mb_substr($dish, 0, 60)]]];
        $req = rh_ai_vault_final_pass($db, $conv, $req);
        $r = rh_ai_chat($req);
        $usage = (array)($r['usage'] ?? []);
        $in = (int)($usage['in'] ?? 0) + (int)($usage['cache_read'] ?? 0) + (int)($usage['cache_write'] ?? 0);
        $cost = rh_ai_cost_usd($m['price_in_per_mtok'] !== null ? (float)$m['price_in_per_mtok'] : null, $m['price_out_per_mtok'] !== null ? (float)$m['price_out_per_mtok'] : null, $usage);
        if ($in > 0 || (int)($usage['out'] ?? 0) > 0) {
            rh_ai_record_usage($db, (int)$user['id'], $models['provider'], (string)$m['model_id'], $usage, $cost);
        }
        $out['tokens_in'] += $in;
        $out['tokens_out'] += (int)($usage['out'] ?? 0);
        $out['cost'] += $cost;
        $out['ms'] += (int)($r['ms'] ?? 0);
        $out['model'] = (string)$m['model_id'];
        $out['role'] = ($models['fast']['model_id'] ?? '') === $m['model_id'] ? 'fast' : 'primary';
        $out['provider'] = (string)$models['provider'];
        if (($r['error'] ?? '') !== '') {
            $out['error'] = (string)$r['error'];
            continue;
        }
        $p = rh_ai_menu_read_proposal((string)($r['text'] ?? ''));
        if (!empty($p['error'])) {
            $out['error'] = 'not a dish';
            return $out;
        }
        if ($p) {
            try {
                $db->prepare("INSERT INTO ai_tool_cache (cache_key, user_id, result_json, expires_at) VALUES (?, 0, ?, DATE_ADD(NOW(), INTERVAL ? DAY))
                              ON DUPLICATE KEY UPDATE result_json = VALUES(result_json), expires_at = VALUES(expires_at)")
                    ->execute([$key, json_encode($p, JSON_UNESCAPED_UNICODE), rh_ai_num('ai_menu_recipe_cache_days')]);
            } catch (Throwable $e) {
                // the cache is optional
            }
            return array_merge($out, $p, ['ok' => true, 'error' => '']);
        }
        $out['error'] = 'unreadable';
    }
    return $out;
}

/** The person's links: Menu Management and Recipes, only the screens they may open. */
function rh_ai_menu_links(array $user): array
{
    $uid = (int)($user['id'] ?? 0);
    $out = [];
    foreach ([['menu-management.php', 'Open Menu Management'], ['stock-recipes.php', 'Open Recipes']] as [$p, $l]) {
        if (function_exists('rhCanOpenPage') && rhCanOpenPage($uid, $p)) {
            $out[] = ['label' => $l, 'url' => $p];
        }
    }
    return $out;
}

/**
 * A menu question answered here: the lookup locally, the reply written from its result. A dish idea with no recipe on file
 * gets one small Fast-model proposal (or the cached one). null = not a menu question (the rest of the turn carries on).
 * @return array{text:string,route:string,links:array,chips:array,tokens_in?:int,tokens_out?:int,cost?:float,model?:string,provider?:string,ms?:int}|null
 */
function rh_ai_menu_local(PDO $db, array $user, int $conv, string $tok): ?array
{
    $mi = rh_ai_menu_intent($tok);
    if ($mi === null) {
        return null;
    }
    if (!rh_ai_tool_allowed($user, 'menu_advisor')) {
        $help = function_exists('rh_ai_can_help_line') ? rh_ai_can_help_line($user) : '';
        $line = (!rh_ai_module_on('pos') || !rh_ai_module_on('stock')) ? "There's no restaurant menu with stock kept here." : rh_ai_out_of_scope_line();
        return ['text' => $line . ($help !== '' ? "\n\n" . $help : ''), 'route' => 'declined', 'links' => [], 'chips' => ['What can I do here?']];
    }
    if ($mi['mode'] === 'change') { // the same line for everyone the advisor is open to (others get the decline above): nothing on the menu or in a recipe is changed from the chat
        return ['text' => "I can't change menu prices, dishes or recipes - change the price on Menu Management and what a dish uses on Stock > Recipes. I can work out food cost, portions and a suggested price first if that helps.",
            'route' => 'declined', 'links' => rh_ai_menu_links($user), 'chips' => []];
    }
    $uid = (int)$user['id'];
    $links = rh_ai_menu_links($user);
    $run = static function (array $args) use ($db, $user, $conv, $uid): array {
        rh_ai_step_set($db, $uid, rh_ai_tool_step('menu_advisor'));
        $r = rh_ai_run_tool($db, $user, $conv, 'menu_advisor', $args);
        if ($r['ok']) {
            rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => 'menu_advisor', 'args' => rh_ai_vault_protect($db, $conv, $args), 'result' => $r['json'], 'agent' => 'local']);
        }
        return $r;
    };
    $portions = isset($mi['portions']) ? ['portions' => (int)$mi['portions']] : [];
    $extra = [];
    if ($mi['mode'] === 'view') {
        $r = $run(['view' => $mi['view']]);
    } elseif ($mi['mode'] === 'item') {
        $r = $run(['item' => $mi['dish']] + $portions);
    } else {
        // A dish: its recipe on file when it is on the menu with one; otherwise a typical portion (cached or Fast model)
        $r = $run(['item' => $mi['dish']] + $portions);
        $onFile = $r['ok'] && isset($r['result']['ingredients']);
        if (!$onFile && !($r['ok'] && ($r['result']['on_menu'] ?? '') === 'several items match')) {
            $p = rh_ai_menu_propose($db, $user, $conv, (string)$mi['dish']);
            $extra = ['role' => (string)($p['role'] ?? 'fast'), 'tokens_in' => $p['tokens_in'], 'tokens_out' => $p['tokens_out'], 'cost' => round((float)$p['cost'], 6), 'model' => $p['model'], 'provider' => $p['provider'], 'ms' => $p['ms']];
            if ($p['ok']) {
                $r = $run(['dish' => $mi['dish'], 'recipe' => (string)$p['recipe'], 'kind' => (string)$p['kind'], 'recipe_by' => 'assistant', 'serving' => (string)$p['portion']] + $portions);
            } elseif ($p['error'] === 'not a dish') {
                return ['text' => 'I can only check dishes and drinks against stock - tell me the dish you have in mind.', 'route' => ($p['tokens_in'] + $p['tokens_out']) > 0 ? (string)($p['role'] ?? 'fast') : 'local', 'links' => $links, 'chips' => []] + $extra;
            } else {
                $why = $p['error'] === 'budget' ? 'today\'s Assistant allowance is used up' : ($p['error'] === 'no model' ? 'no model is chosen for the Assistant' : 'I could not put a typical recipe together just now');
                $text = (($r['result']['on_menu'] ?? '') === 'not on the menu' ? '**' . rh_ai_plain((string)$mi['dish'], 60) . "** isn't on the menu yet (checked " . (int)($r['result']['menu_items_checked'] ?? 0) . ' menu items). ' : '')
                    . 'To check stock, cost and a price, send one portion\'s recipe, e.g. "' . rh_ai_plain((string)$mi['dish'], 40) . ': chicken 180 g; onion 60 g; rice 90 g" (' . $why . ').';
                return ['text' => $text, 'route' => ($p['tokens_in'] + $p['tokens_out']) > 0 ? (string)($p['role'] ?? 'fast') : 'local', 'links' => $links, 'chips' => []] + $extra;
            }
        }
    }
    if (!$r['ok']) {
        return ['text' => 'I could not read the menu and stock just now - ' . rtrim((string)$r['error'], '.') . '.', 'route' => 'local', 'links' => $links, 'chips' => []] + $extra;
    }
    $text = rh_ai_menu_local_text($r['result'], $mi);
    $chips = [];
    if ($mi['mode'] !== 'view') {
        $chips = ['Which menu items are under-priced?', 'What can we make from what is expiring?'];
    }
    $route = ((int)($extra['tokens_in'] ?? 0) + (int)($extra['tokens_out'] ?? 0)) > 0 ? (string)($extra['role'] ?? 'fast') : 'local';
    return ['text' => $text, 'route' => $route, 'links' => $links, 'chips' => $chips, 'local' => ['tool' => 'menu_advisor']] + $extra;
}

// ── Writing the reply (from the de-identified tool result; every figure is the result's own) ──

function rh_ai_menu_list(array $xs, string $sep = ', '): string
{
    return implode($sep, array_map(static fn($x) => is_array($x) ? implode(' ', array_map('strval', array_filter($x, 'is_scalar'))) : (string)$x, $xs));
}

function rh_ai_menu_local_text(array $r, array $mi): string
{
    $cur = rh_ai_currency();
    $money = static fn($v): string => rh_ai_fmt_cur($cur, (float)$v);
    $pct = static fn($v): string => number_format((float)$v, 1) . '%';
    $view = (string)($r['view'] ?? '');
    if ($view === 'under_priced') {
        $c = (array)$r['checked'];
        if ((int)$c['with_recipe'] === 0) {
            return 'No menu item has a recipe yet (' . (int)$c['menu_items'] . ' menu items checked), so food cost cannot be worked out for any of them. Add recipes on Stock > Recipes.';
        }
        $out = '**Re-price: ' . (int)$r['re_price_total'] . '** · tight: ' . (int)$r['tight_total'] . ' · ' . (int)$c['with_recipe'] . ' of ' . (int)$c['menu_items'] . ' menu items with a recipe checked. The Recipes screen\'s bands: re-price over ' . rtrim(rtrim(number_format((float)$r['bands']['re_price_over_pct'], 1), '0'), '.') . '%, tight from '
            . rtrim(rtrim(number_format((float)$r['bands']['tight_from_pct'], 1), '0'), '.') . '% to ' . rtrim(rtrim(number_format((float)$r['bands']['tight_to_pct'], 1), '0'), '.') . '%.';
        $ln = static fn($x): string => $x['item'] . ' · ' . $money($x['till_price']) . ' · food cost ' . $pct($x['food_cost_pct']) . (isset($x['price_at_target']) ? ' · at the target: ' . $money($x['price_at_target']) : '');
        if ($r['re_price']) {
            $out .= "\n\nRe-price:" . rh_ai_local_lines(array_map($ln, $r['re_price']));
        }
        if ($r['tight']) {
            $out .= "\n\nTight:" . rh_ai_local_lines(array_map($ln, $r['tight']));
        }
        if (!empty($r['bought_in'])) {
            $out .= "

Bought in and sold as is (" . (int)$r['bought_in_total'] . ' over the re-price band - the dish bands do not fit these, so compare each with what the bottle or can costs us and the shop price nearby):'
                . rh_ai_local_lines(array_map(static fn($x): string => $x['item'] . ' · ' . $money($x['till_price']) . ' · cost ' . $money($x['cost_per_portion']) . ' (' . $pct($x['food_cost_pct']) . ')', $r['bought_in']));
        }
        if (!$r['re_price'] && !$r['tight']) {
            $out .= "\n\nEvery item with a recipe is in the healthy band.";
        }
        if ($r['no_price']) {
            $out .= "\n\nNo till price set: " . implode(', ', $r['no_price']) . '.';
        }
        if ((int)$r['items_without_recipe'] > 0) {
            $out .= "\n\n" . (int)$r['items_without_recipe'] . ' menu items have no recipe, so their food cost is unknown.';
        }
        return $out;
    }
    if ($view === 'no_recipe') {
        if ((int)$r['menu_items_checked'] === 0) {
            return 'There is nothing on the menu yet (0 menu items checked) - add dishes on Menu Management first.';
        }
        if ((int)$r['no_recipe_total'] === 0) {
            return 'Every menu item has a recipe (' . (int)$r['menu_items_checked'] . ' menu items checked).';
        }
        return '**' . (int)$r['no_recipe_total'] . ' of ' . (int)$r['menu_items_checked'] . ' menu items have no recipe** - ' . (string)$r['why_it_matters'] . rh_ai_local_lines((array)$r['no_recipe']);
    }
    if ($view === 'use_up') {
        $e = (array)$r['expiring'];
        $out = (int)$e['total'] === 0
            ? 'No stock batch expires in the next ' . (int)$e['within_days'] . ' days (0 batches found; ' . (int)$r['menu_items_checked'] . ' menu items checked).'
            : '**' . (int)$e['total'] . ((int)$e['total'] === 1 ? ' batch expires' : ' batches expire') . ' in the next ' . (int)$e['within_days'] . ' days** (worth ' . $money($e['value']) . '). Push these dishes first:'
            . rh_ai_local_lines(array_map(static fn($b) => $b['stock_item'] . ' · ' . $b['left'] . ' · expires ' . $b['expires'] . ' → ' . (is_array($b['dishes_using_it']) ? implode('; ', $b['dishes_using_it']) : $b['dishes_using_it']), (array)$e['batches']));
        if ((int)$r['past_expiry_total'] > 0) {
            $out .= "\n\n" . (int)$r['past_expiry_total'] . ((int)$r['past_expiry_total'] === 1 ? ' batch is' : ' batches are') . ' already past ' . ((int)$r['past_expiry_total'] === 1 ? 'its' : 'their') . ' date: ' . rh_ai_menu_list(array_map(static fn($b) => $b['stock_item'] . ' batch ' . $b['batch'] . ' (' . $b['left'] . ', ' . $b['expired'] . ')', $r['past_expiry'])) . '. ' . (string)$r['safety'];
        }
        return $out;
    }
    if (isset($r['on_menu'])) { // not on the menu, or several items match
        if (!empty($r['close_matches'])) {
            return 'Several menu items match: ' . (function_exists('rh_ai_did_you_mean') ? rh_ai_did_you_mean((array)$r['close_matches']) : implode(', ', (array)$r['close_matches']));
        }
        return '**' . $r['asked'] . "** isn't on the menu (checked " . (int)$r['menu_items_checked'] . ' menu items). Ask "can we make ' . $r['asked'] . '?" and I will check a typical recipe against stock, cost it and suggest a price.';
    }
    $name = (string)($r['item'] ?? $r['dish'] ?? 'This dish');
    $out = !empty($r['read_as_name']) ? '(Read as **' . $r['read_as_name'] . "**.)\n" : '';
    if (isset($r['food_cost']) && !isset($r['ingredients'])) { // on the menu, no recipe
        return $out . '**' . $name . '** is on the menu, till price ' . $money($r['till_price']) . ', but has no recipe yet, so its food cost ' . (string)$r['food_cost'] . '. Ask "can we make ' . $name . '?" for a typical recipe checked against stock.';
    }
    if (isset($r['item'], $r['till_price'])) { // on the menu: say so first
        $out .= '**' . $r['item'] . '** is on the menu, till price ' . $money($r['till_price']) . (empty($r['on_sale']) ? ' (not on sale now)' : '') . ".
";
    }
    $ing = (array)($r['ingredients'] ?? []);
    $missing = (array)($r['not_stocked'] ?? []);
    $differs = (array)($r['unit_differs'] ?? []);
    $possible = (int)($r['portions_possible'] ?? 0);
    // The answer first
    if ($missing) {
        $out .= '**Not fully: ' . count($missing) . ' of ' . count($ing) . (count($missing) === 1 ? ' ingredients is not stocked** (' : ' ingredients are not stocked** (') . implode(', ', $missing) . '; ' . (string)$r['not_stocked_checked'] . ').';
        if (count($missing) < count($ing) && empty($differs)) {
            $out .= $possible > 0 ? ' What we do have would cover ' . $possible . ' portions.' : (!empty($r['first_to_run_out']) ? ' Of the rest, ' . $r['first_to_run_out'] . ' is out of stock (0 portions now).' : '');
        }
    } elseif ($possible > 0) {
        $out .= '**Yes - stock covers ' . $possible . ' portions of ' . $name . '**' . (!empty($r['first_to_run_out']) ? ' (' . $r['first_to_run_out'] . ' runs out first)' : '') . '.';
    } elseif (!empty($r['first_to_run_out'])) {
        $out .= '**Not right now - ' . $r['first_to_run_out'] . ' is out of stock** (0 portions).';
    } else {
        $out .= '**' . $name . '**: the stock amounts could not be compared (see below).';
    }
    if ($differs) {
        $out .= ' ' . count($differs) . ' ingredient' . (count($differs) === 1 ? '' : 's') . ' could not be compared (' . implode(', ', $differs) . ': different units).';
    }
    if (!empty($r['for_portions'])) {
        $fp = (array)$r['for_portions'];
        $out .= "\n\n**For " . (int)$fp['portions'] . ' portions:** ' . (!empty($fp['enough']) ? 'enough stock' : ($fp['short'] ? 'short of ' . rh_ai_menu_list(array_map(static fn($s) => $s['stock_item'] . ' by ' . $s['short_by'] . ' (needs ' . $s['needed'] . ')', $fp['short'])) : 'see the missing items above'))
            . (isset($fp['ingredient_cost']) ? ' · cost for them ' . $money($fp['ingredient_cost']) : '') . '.';
    }
    // The recipe
    $from = (string)($r['recipe_from'] ?? '');
    $head = $from === 'the recipe on file' ? 'Recipe on file' : ($from === 'given' ? 'Your recipe' : 'A typical recipe, one portion' . (!empty($r['portion']) ? ' (' . $r['portion'] . ')' : '') . ' - a starting point, adjust to your kitchen');
    $lines = [];
    foreach ($ing as $l) {
        $s = $l['asked'] . ' ' . $l['per_portion'];
        if (!isset($l['stock_item'])) {
            $s .= ' → ' . $l['match'] . (!empty($l['close']) ? ' (close: ' . implode(', ', $l['close']) . ')' : '');
        } else {
            $s .= ($l['stock_item'] !== $l['asked'] ? ' → **' . $l['stock_item'] . '**' : '') . ' · on hand ' . $l['on_hand'] . ($l['level'] !== 'ok' ? ' (' . $l['level'] . ')' : '');
            if (isset($l['unit_differs'])) {
                $s .= ' · ' . $l['unit_differs'];
            } else {
                $s .= (isset($l['yield_pct']) ? ' · uses ' . $l['uses_from_stock'] . ' at ' . rtrim(rtrim(number_format((float)$l['yield_pct'], 1), '0'), '.') . '% yield' : '')
                    . ' · ' . (is_numeric($l['cost']) ? 'cost ' . $money($l['cost']) : $l['cost']);
            }
            if (!empty($l['pack'])) {
                $s .= ' · ' . $l['pack'];
            }
        }
        $lines[] = $s;
    }
    $out .= "\n\n" . $head . ':' . "\n- " . implode("\n- ", $lines);
    // Cost and price
    $cost = (float)($r['cost_per_portion'] ?? 0);
    if ($cost > 0) {
        $out .= "\n\n**Cost per portion: " . (!empty($r['cost_leaves_out']) ? 'at least ' : '') . $money($cost) . '**' . (!empty($r['cost_leaves_out']) ? ' (leaves out ' . implode(', ', $r['cost_leaves_out']) . ')' : '');
        if (!empty($r['now'])) {
            $n = (array)$r['now'];
            $out .= ' · till price ' . $money($n['till_price']) . ($n['food_cost_pct'] !== null ? ' → food cost **' . $pct($n['food_cost_pct']) . '** (' . (string)($n['band'] ?? '') . ')' : '');
        }
        $p = (array)($r['price'] ?? []);
        if ($p) {
            $below = !empty($r['now']) && $r['now']['food_cost_pct'] !== null && (float)$r['now']['food_cost_pct'] <= (float)$p['target_food_cost_pct'];
            $out .= "\n**" . (!empty($r['now']) ? ($below ? 'Already within the target; the lowest price that would still meet it' : 'Price at the target') : 'Suggested till price') . ': ' . (!empty($p['at_least']) ? 'at least ' : '') . $money($p['suggested_till_price']) . '**'
                . (!empty($p['vat_included']) ? ', VAT included (' . rtrim(rtrim(number_format((float)$p['vat_rate_pct'], 2), '0'), '.') . '%: ' . $money($p['vat_inside']) . ' of it is VAT)' : ' (VAT is off)')
                . ' - food cost ' . $pct($p['food_cost_pct_at_that_price']) . ' of the till price (target ' . rtrim(rtrim(number_format((float)$p['target_food_cost_pct'], 1), '0'), '.') . '%), ' . $money($p['margin_per_portion']) . ' left per portion before labour'
                . (!empty($p['at_least']) ? ' - ' . (string)$p['why_at_least'] : '') . '.';
        } elseif (!empty($r['price_needs'])) {
            $out .= "\nNo price yet: " . (string)$r['price_needs'] . ' (set those amounts on Stock > Recipes, or tell me the weights in the stock units).';
        }
    } elseif ($ing) {
        $out .= "\n\nCost per portion can't be worked out yet" . (!empty($r['cost_leaves_out']) ? ' (no comparable cost for ' . implode(', ', $r['cost_leaves_out']) . ')' : '') . '.';
    }
    $sim = (array)($r['similar_on_menu'] ?? []);
    if ($sim) {
        $out .= "\n\nOn the menu now: " . implode('; ', array_map(static fn($s) => $s['item'] . ' ' . $money($s['till_price']) . (isset($s['food_cost_pct']) && $s['food_cost_pct'] !== null ? ' (food cost ' . $pct($s['food_cost_pct']) . (strpos((string)($s['why'] ?? ''), 'also uses') === 0 ? ', ' . $s['why'] : '') . ')' : ' (no recipe)'), array_slice($sim, 0, 4))) . '.';
    }
    if (!empty($r['already_on_menu'])) {
        $a = (array)$r['already_on_menu'];
        $out .= "\n\n" . $a['item'] . ' is already on the menu, till price ' . $money($a['till_price']) . '.';
    }
    $risk = [];
    foreach ((array)($r['use_first'] ?? []) as $b) {
        $risk[] = $b['stock_item'] . ': batch ' . $b['batch'] . ' (' . $b['left'] . ') expires ' . $b['expires'] . ' - use it first.';
    }
    foreach ((array)($r['past_expiry'] ?? []) as $b) {
        $risk[] = $b['stock_item'] . ': batch ' . $b['batch'] . ' (' . $b['left'] . ') passed its date on ' . $b['expired'] . ' - check it, never cook it.';
    }
    $risk = array_merge($risk, (array)($r['advice'] ?? []));
    if ($risk) {
        $out .= "\n\n**Wastage and risk:**\n- " . implode("\n- ", array_slice($risk, 0, 6));
    }
    if (!empty($r['ask'])) {
        $out .= "\n\n" . $r['ask'];
    }
    $out .= "\n\n" . (string)($r['to_add_it'] ?? $r['to_change_it'] ?? '');
    return rtrim($out);
}
