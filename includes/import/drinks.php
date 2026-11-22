<?php
/**
 * Import - "Drinks for the menu" (drinks profit projection P1, owner 2026-11-19).
 *
 * A stock list's SELLING price is never imported into stock (it is a derived column). This file only LISTS the rows that have one, so a
 * manager can tick the drinks that should go on the drinks menu and the till:
 *   - nothing reaches the menu without a tick (unticked by default); unticked rows change nothing;
 *   - the selling price INCLUDES VAT: the menu price is the sheet's price exactly, never with VAT added (the till takes VAT out of it);
 *   - category: the sheet's own category column when it has one, else "Bar"; editable per row and "set for all"; station = bar;
 *   - an item already on the drinks menu (its stock link first, else the same name in any category) shows "Update price X -> Y" and keeps
 *     its category; a new one is added with a one-measure stock link (what one sale takes off stock);
 *   - cost of one measure comes from the item's current main unit and cost (rh_drink_measure_cost in config/database.php).
 * Since 2026-11-22 (owner: "not just drinks, any sort of menu item ... 100% confident ... grouped"): step 3 "Put items on the menu" lists ANY
 * item with a selling price, a price typed in step 3, or a served-by cell, grouped by the file's category heading; each row goes to the Drinks
 * or the Food menu (food_menu, station kitchen) only from certain signals (already on a menu, the strongest: never changed by a group
 * answer, and a row answer for the other menu makes the row untickable; a kilo unit; a drink-only measure in the file's own served-by cell,
 * never the Assistant's reading of it; import_menu_words_hit on menu_drink_words / menu_food_words minus import_menu_ambiguous_words), then
 * the person's answer for the row, then for its group (rows with no certain signal only); else it cannot be ticked.
 * Profit = price - cost of one to 2 dp (as Stock Items > Put on the menu). A typed price changes the menu price only.
 * Answers: answers_json['_drinks'][item key] = {tick, cat, price, menu, by, at}; ['_menu_g'][group key] = {menu, cat, by, at}; Assistant
 * suggestions (never applied until accepted) in ['_sizes']['ai'][key]['menu' | 'menu_cat']. The file's price is read again from its cells
 * every time, and at import only the rows that are still tickable are written (import_drinks_commit, inside the import's transaction).
 * Ticking, typing a price, choosing a menu and importing ticked rows need the Menu permission as well as Import.
 *
 *   import_job_menu_view($pdo, $jobId, $userId, $p)      step 3: counts, groups, one group's rows (q, show, mgroup, page, size 10|25|50|0=all)
 *   import_job_drinks_view($pdo, $jobId, $userId, $p)    the flat paged list (q, show, page, size)
 *   import_job_drinks_set($pdo, $jobId, $userId, $p)     mode tick | tick_all | price | menu | menu_keys | category | category_keys | category_all
 *                                                        | group_menu | group_cat | accept (see the function)
 *   import_drinks_commit($pdo, $items, $answers, $jobId, $userId)   writes the ticked rows (caller holds the transaction + menu lock)
 */

require_once __DIR__ . '/review.php';
require_once dirname(__DIR__, 2) . '/admin/includes/menu-pos-sync.php';
require_once dirname(__DIR__, 2) . '/admin/includes/menu-recipe-lib.php';

if (!function_exists('import_drinks_build')) {

    /** The category a drink goes to when the sheet has no category column (owner 2026-11-19). */
    function import_drinks_default_category(): string
    {
        return 'Bar';
    }

    /** Can this person put drinks on the menu? (Import itself is checked by the page; this is the menu's own permission.) */
    function import_drinks_can_menu(int $userId): bool
    {
        return $userId > 0 && function_exists('hasPermission') && hasPermission($userId, 'menu');
    }

    /** Name of the menu lock (the same one Menu Management takes for an add / edit). */
    function import_drinks_menu_lock_name(): string
    {
        return substr('rh_form_menu_item_' . (defined('DB_NAME') ? DB_NAME : 'db'), 0, 64);
    }

    /** Does this job's answers tick any drink? (Decides whether the import must take the menu lock and check the Menu permission.) */
    function import_drinks_any_ticked(array $answers): bool
    {
        foreach ((array)($answers['_drinks'] ?? []) as $a) {
            if (is_array($a) && !empty($a['tick'])) {
                return true;
            }
        }
        return false;
    }

    /** What the database says about the drinks menu and the stock items this list touches (read once per build). */
    function import_drinks_db(PDO $pdo, array $ingIds): array
    {
        // drinks menu: menu / by_name / cats (as before); food menu (2026-11-22, any item on the menu): fmenu / fby_name / fcats
        $db = ['menu' => [], 'by_name' => [], 'cats' => [], 'fmenu' => [], 'fby_name' => [], 'fcats' => [], 'links' => [], 'ing' => [],
            'drink_ready' => function_exists('rh_stock_drink_ready') && rh_stock_drink_ready($pdo)];
        foreach ($pdo->query('SELECT id, item_name, description, price, category, is_available, display_order, tags, station, show_pos, show_room_service FROM drink_menu ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $db['menu'][(int)$r['id']] = $r;
            $db['by_name'][mb_strtolower(rh_clean_text((string)$r['item_name']))][] = (int)$r['id'];
            $ck = mb_strtolower(rh_clean_text((string)$r['category']));
            if ($ck !== '' && !isset($db['cats'][$ck])) {
                $db['cats'][$ck] = rh_clean_text((string)$r['category']);
            }
        }
        try {
            $food = $pdo->query('SELECT id, item_name, description, price, category, is_available, display_order, station, show_pos, show_room_service FROM food_menu ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $food = [];         // no food menu table in this database: nothing to match against
        }
        foreach ($food as $r) {
            $db['fmenu'][(int)$r['id']] = $r;
            $db['fby_name'][mb_strtolower(rh_clean_text((string)$r['item_name']))][] = (int)$r['id'];
            $ck = mb_strtolower(rh_clean_text((string)$r['category']));
            if ($ck !== '' && !isset($db['fcats'][$ck])) {
                $db['fcats'][$ck] = rh_clean_text((string)$r['category']);
            }
        }
        $ingIds = array_values(array_unique(array_filter(array_map('intval', $ingIds))));
        if ($ingIds) {
            $in = implode(',', array_fill(0, count($ingIds), '?'));
            $st = $pdo->prepare('SELECT id, name, category, unit, cost_per_unit, is_archived' . rh_drink_ing_cols('') . " FROM stock_ingredients WHERE id IN ($in)");
            $st->execute($ingIds);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $db['ing'][(int)$r['id']] = $r;
            }
            // till items whose stock link is exactly ONE line of this stock item (a drink sold as itself; a cocktail that uses it is not "it")
            $st = $pdo->prepare(
                "SELECT sri.ingredient_id, mi.item_name, mi.category
                   FROM stock_recipe_ingredients sri
                   JOIN stock_recipes sr ON sr.id = sri.recipe_id
                   JOIN menu_items mi ON mi.id = sr.menu_item_id
                  WHERE sri.ingredient_id IN ($in)
                    AND (SELECT COUNT(*) FROM stock_recipe_ingredients x WHERE x.recipe_id = sr.id) = 1"
            );
            $st->execute($ingIds);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $db['links'][(int)$r['ingredient_id']][] = ['name' => (string)$r['item_name'], 'category' => (string)$r['category']];
            }
        }
        return $db;
    }

    /** The drinks-menu rows a till item (name + category) belongs to. */
    function import_drinks_menu_ids_for(array $db, string $name, string $category): array
    {
        $out = [];
        foreach ($db['by_name'][mb_strtolower(rh_clean_text($name))] ?? [] as $id) {
            if (mb_strtolower(rh_clean_text((string)$db['menu'][$id]['category'])) === mb_strtolower(rh_clean_text($category))) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** The food-menu rows a till item (name + category) belongs to (the food twin of import_drinks_menu_ids_for). */
    function import_food_menu_ids_for(array $db, string $name, string $category): array
    {
        $out = [];
        foreach ($db['fby_name'][mb_strtolower(rh_clean_text($name))] ?? [] as $id) {
            if (mb_strtolower(rh_clean_text((string)$db['fmenu'][$id]['category'])) === mb_strtolower(rh_clean_text($category))) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Which menu a text says for certain (a category heading, a stock category, a served-by cell): its whole wording, any two words together or
     * any one word on the alias lists menu_drink_words / menu_food_words (import_aliases, seed v10). ['drink' => bool, 'food' => bool]; both or
     * neither = not certain (the person chooses).
     */
    function import_menu_words_hit(string $text, array $drinkSet, array $foodSet): array
    {
        $out = ['drink' => false, 'food' => false];
        $n = import_norm_text($text);
        if ($n === '') {
            return $out;
        }
        $w = preg_split('/\s+/u', $n, -1, PREG_SPLIT_NO_EMPTY);
        $cands = [$n];
        foreach ($w as $i => $t) {
            $cands[] = $t;
            $cands[] = import_measure_singular($t);
            if (isset($w[$i + 1])) {
                $cands[] = $t . ' ' . $w[$i + 1];
            }
        }
        foreach ($cands as $c) {
            $out['drink'] = $out['drink'] || isset($drinkSet[$c]);
            $out['food'] = $out['food'] || isset($foodSet[$c]);
        }
        return $out;
    }

    /**
     * Words that never say Drinks or Food on their own (QA 2026-11-22, owner rule "100% confident"): containers and words used on both menus
     * ("can" of Coke or of baked beans, "bottle", "water", "hot chocolate", "side", "double" ...). Taken out of menu_drink_words / menu_food_words
     * even when a hotel's saved alias list still has them, and never a drink measure for the menu choice. Two words together still count
     * ("soft drinks" is not here).
     */
    function import_menu_ambiguous_words(): array
    {
        return import_word_set([
            'can', 'cans', 'tin', 'tins', 'bottle', 'bottles', 'btl', 'btls', 'jar', 'jars', 'jug', 'jugs', 'carton', 'cartons', 'box', 'boxes',
            'pack', 'packs', 'packet', 'packets', 'sachet', 'sachets', 'tub', 'tubs', 'bag', 'bags', 'each', 'piece', 'pieces', 'unit', 'units',
            'water', 'waters', 'soda', 'sodas', 'bar', 'bars', 'chocolate', 'chocolates', 'side', 'sides', 'main', 'cup', 'cups', 'bowl', 'bowls',
            'portion', 'portions', 'serving', 'servings', 'measure', 'measures', 'single', 'singles', 'double', 'doubles', 'half', 'scoop', 'scoops',
            'slice', 'slices', 'pour', 'pours',
        ]);
    }

    /**
     * A served-by cell that is a drink-only measure (QA 2026-11-22): a glass ("Glass", "Glass of wine 175ml"), a shot / tot, or a measure whose
     * word is a drink word (pint, carafe ...) or a name on the hotel's Drink measures list, never an ambiguous word (import_menu_ambiguous_words)
     * and never a bare volume ("330ml" can be soup). $sv = import_served_read() of the same cell.
     */
    function import_menu_drink_measure(string $served, array $sv, array $glassSet, array $shotSet, array $drinkWords, array $ambWords): bool
    {
        if ($served === '') {
            return false;
        }
        if (import_glass_read($served, $glassSet) !== null) {
            return true;
        }
        if (!in_array($sv['kind'] ?? '', ['list', 'ml'], true)) {
            return false;
        }
        $w = import_norm_text($sv['kind'] === 'list' ? (string)$sv['measure'] : (string)$sv['word']);
        if ($w === '') {
            return false;
        }
        $w1 = import_measure_singular($w);
        if (isset($ambWords[$w]) || isset($ambWords[$w1])) {
            return false;
        }
        return $sv['kind'] === 'list' || isset($shotSet[$w1]) || isset($glassSet[$w1]) || isset($drinkWords[$w]) || isset($drinkWords[$w1]);
    }

    /** A typed menu price ("1,500.50"): the parser of Stock Items > Put on the menu (commas only between thousands, > 0, 2 dp, at most 8 digits). */
    function import_menu_price_parse($raw)
    {
        if (!function_exists('stm_parse_price')) {
            require_once dirname(__DIR__, 2) . '/admin/includes/stock-to-menu-lib.php';
        }
        return stm_parse_price($raw);
    }

    /** Menu money as the screens show it: profit = price - (cost of one to 2 dp), so the shown cost + the shown profit always add up to the price. */
    function import_menu_profit(?float $price, ?float $cost): ?float
    {
        return ($price !== null && $cost !== null) ? round($price - round($cost, 2), 2) : null;
    }

    /** The hotel's VAT setting as the profit screens use it (vat_mode()): mode off / inclusive / exclusive and the rate (0 when off). */
    function import_menu_vat(): array
    {
        $mode = function_exists('vat_mode') ? vat_mode() : 'off';
        return ['mode' => $mode, 'rate' => $mode === 'off' ? 0.0 : (float)getSetting('vat_rate', 0)];
    }

    /**
     * Profit without VAT (owner 2026-11-22, the "Show profit without VAT" tick box; the profit shown stays VAT included).
     * inclusive: the price without its VAT (vat_components' rounding: price / (1 + rate/100) to 2 dp) minus cost of one to 2 dp.
     * exclusive: the menu price holds no VAT, so the same as import_menu_profit(). off: null (no figure, no tick box).
     * $vat: import_menu_vat() (tests pass their own).
     */
    function import_menu_profit_ex_vat(?float $price, ?float $cost, ?array $vat = null): ?float
    {
        $vat = $vat ?? import_menu_vat();
        if ($price === null || $cost === null || ($vat['mode'] ?? 'off') === 'off') {
            return null;
        }
        $rate = (float)($vat['rate'] ?? 0);
        if ($vat['mode'] === 'inclusive' && $rate > 0) {
            return round(round($price / (1 + ($rate / 100)), 2) - round($cost, 2), 2);
        }
        return import_menu_profit($price, $cost);
    }

    /** What the screens send about VAT for the tick box: null when VAT is off (no tick box), else {mode, rate}. */
    function import_menu_vat_view(): ?array
    {
        $vat = import_menu_vat();
        return $vat['mode'] === 'off' ? null : $vat;
    }

    /**
     * One sale of an item and its cost: rh_drink_measure_cost (the shared helper) for a unit / a list measure / the cell as written; for a
     * volume written in the cell, the same ml conversion a recipe line typed in ml uses (rh_drink_measure_to_stock). null = cannot be worked out.
     * @return ?array{qty:float, cost:?float, name:string, kind:string}
     */
    function import_drinks_sale_cost(array $ing, array $sv, string $served, array $list, ?float $pourMl = null): ?array
    {
        if ($sv['kind'] !== 'ml') {
            return rh_drink_measure_cost($ing, $sv['kind'] === 'as_is' ? $served : (string)$sv['measure'], $list);
        }
        $ml = (float)$sv['ml'];
        $name = import_measure_fmt($ml) . ' ml';
        $mc = null;
        try {
            $c = rh_drink_measure_to_stock($ml, 'ml', $ing, $list);
            $mc = ['qty' => (float)$c['qty'], 'name' => $name, 'kind' => 'ml', 'ml' => $ml];
        } catch (RuntimeException $e) {
            $uMl = rh_drink_unit_ml((string)($ing['unit'] ?? ''));
            if ($uMl !== null) {                // stock kept in ml / cl / L itself: no "ml" measure, the amount is in the stock unit
                $mc = ['qty' => round($ml / $uMl, 6), 'name' => $name, 'kind' => 'unit', 'ml' => $ml];
            } elseif ($pourMl !== null && ($q = import_glass_qty($ml, $pourMl)) !== null) {
                // a carton / box the shared helper keeps no ml for (owner 2026-11-20: a carton of juice by the glass): the same sum as an ml
                // line (glass ml / container ml, 6 dp), stored as a plain amount of the stock unit
                $mc = ['qty' => $q, 'name' => $name, 'kind' => 'unit', 'ml' => $ml];
            }
        }
        if ($mc === null) {
            return null;
        }
        $cp = (isset($ing['cost_per_unit']) && is_numeric($ing['cost_per_unit']) && (float)$ing['cost_per_unit'] > 0) ? (float)$ing['cost_per_unit'] : null;
        $mc['cost'] = $cp !== null ? round($mc['qty'] * $cp, 4) : null;
        return $mc;
    }

    /** The bottle-size question group: one per measure + stock unit ("shot|bottle"); the label says it in words. */
    function import_drinks_size_group(array $sv, string $unit): array
    {
        $u = mb_strtolower(trim($unit));
        if ($sv['kind'] === 'ml') {
            $what = import_measure_fmt((float)$sv['ml']) . ' ml' . ($sv['word'] !== '' ? ' (' . $sv['word'] . ')' : '');
            return ['key' => 'ml:' . import_measure_fmt((float)$sv['ml']) . '|' . $u, 'label' => 'Sold by ' . $what . ', kept in ' . import_measure_plural($unit, 2)];
        }
        return ['key' => 'm:' . $sv['word'] . '|' . $u, 'label' => 'Sold by the ' . $sv['word'] . ', kept in ' . import_measure_plural($unit, 2)];
    }

    /**
     * The "Sold as" choices of step 3 (owner 2026-11-21: "change within whether by glass or shot"): the hotel's shot (its first measure named by a
     * shot word on the Drink measures list), a glass, one whole unit (per row: its own stock unit), then the other measures of that list.
     * Each is applied exactly as if the "served by" cell said it, on the MENU side only (import_drinks_build). [['v','label','served'|null]].
     */
    function import_drinks_sold_options(array $list, array $shotSet, array $glassSet = []): array
    {
        $out = [];
        $shotName = null;
        foreach ([true, false] as $exact) {
            foreach ($list as $dm) {
                $dn = is_array($dm) ? import_measure_singular(mb_strtolower(trim((string)($dm['name'] ?? '')))) : '';
                if ($dn !== '' && is_numeric($dm['ml'] ?? null) && (float)$dm['ml'] > 0 && ($exact ? $dn === 'shot' : isset($shotSet[$dn]))) {
                    $shotName = (string)$dm['name'];
                    $out[] = ['v' => 'shot', 'label' => 'Shot (' . import_measure_fmt((float)$dm['ml']) . ' ml, your Drink measures list)', 'served' => $shotName];
                    break 2;
                }
            }
        }
        $out[] = ['v' => 'glass', 'label' => 'Glass', 'served' => 'glass'];
        $out[] = ['v' => 'unit', 'label' => 'One whole unit (bottle, can ...)', 'served' => null];
        foreach ($list as $dm) {
            $nm = is_array($dm) ? trim((string)($dm['name'] ?? '')) : '';
            $dn = import_measure_singular(mb_strtolower($nm));
            if ($nm === '' || ($shotName !== null && $nm === $shotName) || $dn === 'glass' || isset($glassSet[$dn])) {
                continue;
            }
            $ml = is_numeric($dm['ml'] ?? null) && (float)$dm['ml'] > 0 ? ' (' . import_measure_fmt((float)$dm['ml']) . ' ml)' : '';
            $out[] = ['v' => 'm:' . $nm, 'label' => $nm . $ml, 'served' => $nm];
        }
        return $out;
    }

    /** The served-by text a "Sold as" answer stands for on this row (its stock unit for "unit"); null = not a choice (any more). */
    function import_drinks_sold_served(string $v, string $unit, array $opts): ?string
    {
        foreach ($opts as $o) {
            if ($o['v'] === $v) {
                return $o['served'] !== null ? (string)$o['served'] : ($unit !== '' ? $unit : null);
            }
        }
        return null;
    }

    /**
     * One row per item of the file that has a selling price (ready items only; a weight unit is food and never listed).
     * Returns ['rows' => [...], 'waiting' => n items with a price that still need an answer in step 3, 'cats' => existing menu categories].
     */
    function import_drinks_build(PDO $pdo, array $items, array $answers): array
    {
        $vat = import_menu_vat();
        $ingIds = [];
        foreach ($items as $it) {
            if ($it['match']) {
                $ingIds[] = (int)$it['match']['id'];
            }
        }
        $db = import_drinks_db($pdo, $ingIds);
        $list = function_exists('rh_drink_measures_list') ? rh_drink_measures_list() : [];
        $ans = (array)($answers['_drinks'] ?? []);
        $grpMenu = (array)($answers['_menu_g'] ?? []);       // one answer per group (the file's category heading): menu (drink | food) + category
        $sizeAns = (array)($answers['_sizes'] ?? []);      // step 3 bottle sizes: g = per group, r = per row (optional, never guessed)
        $aliases = import_load_aliases($pdo);
        $synonyms = (array)($aliases['unit_synonyms'] ?? []);      // "btl" = bottle (import_aliases, seeded)
        $glassSet = import_word_set((array)($aliases['glass_words'] ?? []));        // "glass", "gls", "tumbler" (v7)
        $pourSet = import_word_set((array)($aliases['pour_containers'] ?? []));     // "carton", "casket", "jug" ... (v7)
        $qtySet = import_word_set((array)($aliases['quantity_words'] ?? []));      // "half", "dbl", "triple" ... (v7): an optional reading must agree with them
        // every served / unit word the alias lists know ("pint", "scoop", "tot", "can" ...): a cell word from here must be the reading's own word
        $vocabSet = import_word_set(array_merge((array)($aliases['portion_words'] ?? []), (array)($aliases['glass_words'] ?? []), (array)($aliases['container_words'] ?? []), (array)($aliases['pour_containers'] ?? []), (array)($aliases['units'] ?? []), array_keys((array)($aliases['unit_synonyms'] ?? []))));
        $shotSet = import_word_set((array)($aliases['shot_words'] ?? []));       // "shot", "tot" (v9): a drink sold by one gets a shot size in step 3
        $soldOpts = import_drinks_sold_options($list, $shotSet, $glassSet);      // step 3 "Sold as" answers (menu side only)
        $ambWords = import_menu_ambiguous_words();      // "can", "bottle", "water", "chocolate", "side" ...: never decide a menu on their own
        $drinkWords = array_diff_key(import_word_set((array)($aliases['menu_drink_words'] ?? [])), $ambWords);      // v10: a word that says "Drinks menu" for certain
        $foodWords = array_diff_key(import_word_set((array)($aliases['menu_food_words'] ?? [])), $ambWords);        // ... and "Food menu"
        $rows = [];
        $waiting = 0;
        foreach ($items as $it) {
            $key = (string)$it['key'];
            $a = is_array($ans[$key] ?? null) ? $ans[$key] : [];
            $typedPrice = isset($a['price']) && is_numeric($a['price']) && (float)$a['price'] > 0 ? round((float)$a['price'], 2) : null;
            // listed (owner 2026-11-22, any item on the menu): a selling price in the file, a price typed here, or sold by something (a served-by cell)
            if (empty($it['sells']) && $typedPrice === null && trim((string)($it['served_by'] ?? '')) === '') {
                continue;
            }
            // a drink whose count is in shots and only waits for "how many in one bottle?" is listed so its sizes can be typed here (they give that
            // number: the count-unit question, owner 2026-11-22); it cannot be ticked until it is ready
            $sizeWait = ($it['status'] ?? '') === 'needs-answer' && !empty($it['size_wait']);
            if (($it['status'] ?? '') !== 'ready' && !$sizeWait) {
                if (($it['status'] ?? '') !== 'skipped') {
                    $waiting++;
                }
                continue;
            }
            $ex = $it['match'] ? ($db['ing'][(int)$it['match']['id']] ?? null) : null;
            $unit = $ex ? (string)$ex['unit'] : (string)($it['unit'] ?? '');
            if ($unit === '') {
                continue;               // no stock unit: one sale cannot be worked out
            }
            $isWeight = rh_drink_unit_is_weight($unit);      // kept by weight (kg, g): never a drink; one sale = one stock unit on the food menu
            $why = '';
            // ---- the price: every selling-price cell of the item, exactly as written; two different ones are shown, never picked
            $prices = [];
            $unknown = false;
            foreach ($it['sells'] as $s) {
                if (!empty($s['unknown'])) {
                    $unknown = true;
                    continue;
                }
                $pn = import_parse_number($s['value']);
                if ($pn['status'] === 'ok' && $pn['value'] > 0) {
                    $prices[] = ['v' => (float)$pn['value'], 'src' => (string)$s['source']];
                }
            }
            if (!$prices && !$unknown && $typedPrice === null && trim((string)($it['served_by'] ?? '')) === '') {
                continue;               // a blank or 0 selling price and nothing else to go on: nothing to offer
            }
            $price = $prices ? $prices[0]['v'] : null;
            $priceSrc = $prices ? $prices[0]['src'] : (string)($it['sells'][0]['source'] ?? '');
            $other = [];
            foreach ($prices as $pp) {
                if (abs($pp['v'] - (float)$price) > BALANCE_TOLERANCE) {
                    $other[] = import_fmt_money($pp['v']) . ' (' . $pp['src'] . ')';
                }
            }
            $filePrice = $price;
            if ($typedPrice !== null) {
                // the price typed in step 3 wins over the file's (the menu price only: never stock cost, the count, sizes or measures)
                $price = $typedPrice;
            } elseif ($price === null && $unknown) {
                $why = 'The selling price cell is a formula with no saved value, so the price is not known. Type the selling price here, or save the file in Excel and import it again.';
            } elseif ($price !== null && $other) {
                $why = 'Your file gives two different selling prices: ' . import_fmt_money($price) . ' (' . $priceSrc . ') and ' . implode(', ', $other) . '. Type the right selling price here.';
            } elseif ($price !== null && preg_match('/\.\d{3,}$/', rtrim(rtrim(sprintf('%.6F', $price), '0'), '.'))) {
                // decimals counted on the written number (6 places, trailing zeros dropped), not by float arithmetic
                $why = 'The price ' . import_fmt_money($price) . ' has more than 2 decimal places, and menu prices keep 2. Type the selling price here (2 decimals).';
            } elseif ($price !== null && $price > 99999999) {
                $why = 'The price is too large for the menu. Type the selling price here.';
            }
            $priceFrom = $typedPrice !== null ? 'typed' : ($filePrice !== null ? 'file' : '');
            // ---- one measure: what one sale is (the "served by" cell; none = one main unit) and its cost now
            $served = trim((string)($it['served_by'] ?? ''));
            if ($ex) {
                $ing = $ex;
                $ing['cost_per_unit'] = $it['line_cost'] ?? null;         // the cost after this import (a ticked cost change counts)
                if (function_exists('rh_stock_measure_get') && !empty($it['measure_save']) && (rh_stock_measure_get($ing) === null || !empty($it['measure_save']['replace']))) {
                    $ms = $it['measure_save'];      // the count measure this import sets on it
                    $ing['measure_big_name'] = $ms['big'];
                    $ing['measure_small_name'] = $ms['small'];
                    $ing['measure_per_big'] = $ms['n'];
                    $ing['measure_stock_side'] = $ms['side'];
                }
            } else {
                $ing = ['name' => (string)$it['name'], 'unit' => $unit, 'cost_per_unit' => $it['cost'] ?? null, 'volume_ml' => null];
                if (!empty($it['measure_save'])) {
                    $ms = $it['measure_save'];
                    $ing += ['measure_big_name' => $ms['big'], 'measure_small_name' => $ms['small'], 'measure_per_big' => $ms['n'], 'measure_stock_side' => $ms['side']];
                }
            }
            // ---- the bottle size (optional, owner 2026-11-20): asked in step 3 for a drink sold by a measure whose size is not known. The item's
            // own saved size (or a volume stock unit) always wins and is never asked; then the person's answer for this row, then a size written
            // in its name ("Gin 750ml", read from your file), then the answer for its group. A blank stays blank: the drink is simply not tickable.
            $key = (string)$it['key'];
            $rowA = is_array($sizeAns['r'][$key] ?? null) ? $sizeAns['r'][$key] : [];
            $aiA = is_array($sizeAns['ai'][$key] ?? null) ? $sizeAns['ai'][$key] : [];      // optional readings (only ever pre-fills, step 3)
            $servedSrc = $served !== '' ? (string)($it['served_by_source'] ?? '') : '';
            $servedFrom = $served !== '' ? 'cell' : '';
            $sv = import_served_read($served, $unit, $list, $synonyms);
            $own = rh_drink_item_ml($ing);
            $isCont = rh_stock_unit_is_container($unit);
            // a stock unit poured from by the glass (carton, box, casket, jug, can, tetra: alias pour_containers); a volume written in the unit
            // cell ("Carton 1 L") is its size, read from that cell. Crates, cases and packs are never here (they hold bottles).
            $pour = import_pour_container($unit, $pourSet);
            if ($pour !== null && $pour['ml'] === null && ($pr = import_pour_container((string)($it['unit_raw'] ?? ''), $pourSet)) !== null && $pr['ml'] !== null) {
                $pour['ml'] = $pr['ml'];
            }
            $size = ['need' => false, 'kind' => '', 'group' => '', 'group_label' => '', 'ml' => null, 'from' => '', 'src' => '', 'row_ml' => null, 'name_ml' => null,
                'cont_need' => false, 'cont_optional' => false, 'word' => $pour !== null ? $pour['word'] : 'bottle',
                'glass_need' => false, 'glass_ml' => null, 'glass_from' => '', 'glass_src' => '', 'glass_row_ml' => null, 'glass_file_ml' => null, 'warn' => '',
                'shot_need' => false, 'shot_ml' => null, 'shot_from' => '', 'shot_src' => '', 'shot_row_ml' => null, 'shot_list_ml' => null, 'shot_list_name' => '',
                'bottle_ml' => null];
            $num = function ($v): ?float {
                return is_numeric($v) && (float)$v > 0 ? (float)$v : null;
            };
            $aiPortion = $num($aiA['portion_ml']['v'] ?? null);
            // v11 (owner 2026-11-23): the file's own "Bottle size" / "Shot size" for this item (checked by the resolver: only values nothing certain
            // disagrees with), shown as "from your file" with its cell; a size typed on the row still wins
            $fs = is_array($it['file_sizes'] ?? null) ? $it['file_sizes'] : [];
            $fileUnitMl = $num($fs['unit_ml'] ?? null);
            $fileMeasMl = $num($fs['measure_ml'] ?? null);
            $fileMeasFirst = !empty($fs['measure_first']);
            // QA 2026-11-23: the person answered "use the file's size" over a size typed in step 3 (import_file_typed_check): the menu side
            // follows that answer too, so the count and the item's saved size never differ ("use the typed size" drops the file value instead).
            $fileUnitOverTyped = $fileUnitMl !== null && !empty($fs['unit_first']);
            $fileMeasOverTyped = $fileMeasMl !== null && !empty($fs['measure_over_typed']);
            // an item whose own count measure already gives one sale ("25 shots in one bottle", "4 glasses in one carton") needs no size
            $worksNow = $own === null && import_drinks_sale_cost($ing, $sv, $served, $list) !== null;
            $sizeable = $db['drink_ready'] && !$worksNow && (!$isCont || $pour !== null);
            $gr = $sizeable && in_array($sv['kind'], ['ml', 'as_is'], true) ? import_glass_read($served, $glassSet) : null;
            $unread = $served !== '' && !$worksNow && $sv['kind'] === 'as_is' && $gr === null;      // the plain rules could not read the cell
            if ($unread && isset($aiA['served_by']['v'])) {
                // an optional reading of that cell: used only when it reads as a measure the hotel has, the stock unit or a glass ("double" is
                // only ever the hotel's own "Double" measure, never a shot); shown as read from the file, cleared in step 3
                $aiServed = (string)$aiA['served_by']['v'];
                $sv2 = import_served_read($aiServed, $unit, $list, $synonyms);
                $gr2 = $sizeable && $sv2['kind'] === 'as_is' ? import_glass_read($aiServed, $glassSet) : null;
                // a shot read together with its size from the same cell ("Shot 35": one shot, 35 ml): that one size is checked on its own (it
                // must be what the cell writes), so the words are checked without it; any other number still drops the reading
                $fitCell = $served;
                if ($aiPortion !== null && $sv2['kind'] === 'list' && isset($shotSet[import_measure_singular(mb_strtolower((string)$sv2['measure']))])
                    && import_cell_shows_size($served, $aiPortion) && import_cell_strip_size($served, $aiPortion) !== '') {
                    $fitCell = import_cell_strip_size($served, $aiPortion);     // a bare "35" is never read as a shot: some word must say it
                }
                // the cell's own words decide first ("Half tot" / "Dbl tot" are never one shot): a reading that disagrees is dropped, left blank
                if (($sv2['kind'] !== 'as_is' || $gr2 !== null) && import_served_ai_fits($fitCell, $aiServed, $unit, $list, $synonyms, $glassSet, $qtySet, $vocabSet)) {
                    [$served, $sv, $gr, $servedFrom, $unread] = [$aiServed, $sv2, $gr2, 'ai', false];
                    $servedSrc = (string)$aiA['served_by']['src'];
                    $worksNow = $own === null && import_drinks_sale_cost($ing, $sv, $served, $list) !== null;
                    $sizeable = $db['drink_ready'] && !$worksNow && (!$isCont || $pour !== null);
                    $gr = $sizeable ? $gr : null;
                }
            }
            // ---- the person's "Sold as" answer for this row (owner 2026-11-21: "change within whether by glass or shot"): one sale is read as if the
            // served-by cell said it, HERE ONLY (the menu side: the sale measure, the cost of one, whether it can be ticked, the stock link at import).
            // The item ($it) is never changed, so the opening count, its count measure and the Drink measures list read the file's own cell as before.
            $soldV = is_string($rowA['sold'] ?? null) ? (string)$rowA['sold'] : '';
            $soldServed = $soldV !== '' ? import_drinks_sold_served($soldV, $unit, $soldOpts) : null;
            if ($soldServed !== null) {
                [$served, $servedFrom, $servedSrc, $unread] = [$soldServed, 'answer', '', false];
                $sv = import_served_read($served, $unit, $list, $synonyms);
                $worksNow = $own === null && import_drinks_sale_cost($ing, $sv, $served, $list) !== null;
                $sizeable = $db['drink_ready'] && !$worksNow && (!$isCont || $pour !== null);
                $gr = $sizeable && in_array($sv['kind'], ['ml', 'as_is'], true) ? import_glass_read($served, $glassSet) : null;
            }
            // ---- which menu (owner 2026-11-22: "any sort of menu item ... 100% confident"): see below for the order (certain signals, the
            // row answer, the group answer). Anything else stays blank: the person chooses (never a guess).
            $fileCat = rh_clean_text((string)($it['category'] ?? ''));
            $gKey = 'c:' . mb_strtolower($fileCat);
            $gA = is_array($grpMenu[$gKey] ?? null) ? $grpMenu[$gKey] : [];
            $ansMenu = in_array($a['menu'] ?? '', ['drink', 'food'], true) ? (string)$a['menu'] : '';
            $grpKind = in_array($gA['menu'] ?? '', ['drink', 'food'], true) ? (string)$gA['menu'] : '';
            // already on a menu: its stock link first (a till item of exactly one line of this item), then the same name, on either menu
            $cand = ['drink' => [], 'food' => []];
            $how = '';
            if ($ex) {
                foreach ($db['links'][(int)$ex['id']] ?? [] as $lk) {
                    foreach (import_drinks_menu_ids_for($db, $lk['name'], $lk['category']) as $mid) {
                        $cand['drink'][$mid] = true;
                    }
                    foreach (import_food_menu_ids_for($db, $lk['name'], $lk['category']) as $mid) {
                        $cand['food'][$mid] = true;
                    }
                }
                $how = ($cand['drink'] || $cand['food']) ? 'link' : '';
            }
            if (!$cand['drink'] && !$cand['food']) {
                foreach ([(string)$it['name'], (string)($it['typed_name'] ?? '')] as $nm) {
                    foreach ($db['by_name'][mb_strtolower(rh_clean_text($nm))] ?? [] as $mid) {
                        $cand['drink'][$mid] = true;
                    }
                    foreach ($db['fby_name'][mb_strtolower(rh_clean_text($nm))] ?? [] as $mid) {
                        $cand['food'][$mid] = true;
                    }
                }
                $how = ($cand['drink'] || $cand['food']) ? 'name' : '';
            }
            // the CERTAIN signals first (QA 2026-11-22): already on exactly one menu is the strongest and is never changed by a group answer;
            // then kept by weight, a drink-only measure or word in the served-by cell (the file's own cell: never the Assistant's reading of it),
            // a drink / food word in the category heading or the stock category, or that heading being a category of exactly one menu
            $onKind = ($cand['drink'] xor $cand['food']) ? ($cand['drink'] ? 'drink' : 'food') : '';
            $certKind = '';
            $certFrom = '';
            if ($onKind !== '') {
                [$certKind, $certFrom] = [$onKind, 'menu'];
            } elseif ($isWeight) {
                [$certKind, $certFrom] = ['food', 'unit'];
            } else {
                $sigServed = $servedFrom === 'ai' ? trim((string)($it['served_by'] ?? '')) : $served;
                $sigSv = $servedFrom === 'ai' ? import_served_read($sigServed, $unit, $list, $synonyms) : $sv;
                $servedHit = import_menu_words_hit($sigServed, $drinkWords, $foodWords);
                $drinkServed = import_menu_drink_measure($sigServed, $sigSv, $glassSet, $shotSet, $drinkWords, $ambWords);
                if ($drinkServed || ($servedHit['drink'] && !$servedHit['food'])) {
                    [$certKind, $certFrom] = ['drink', 'served'];
                } elseif ($servedHit['food'] && !$servedHit['drink']) {
                    [$certKind, $certFrom] = ['food', 'served'];
                } else {
                    $inD = $fileCat !== '' && isset($db['cats'][mb_strtolower($fileCat)]);
                    $inF = $fileCat !== '' && isset($db['fcats'][mb_strtolower($fileCat)]);
                    $hit = import_menu_words_hit($fileCat, $drinkWords, $foodWords);
                    $sHit = $ex ? import_menu_words_hit((string)($ex['category'] ?? ''), $drinkWords, $foodWords) : ['drink' => false, 'food' => false];
                    $d = $inD || $hit['drink'] || $sHit['drink'];
                    $f = $inF || $hit['food'] || $sHit['food'];
                    if ($d xor $f) {
                        [$certKind, $certFrom] = [$d ? 'drink' : 'food', ($inD || $inF) ? 'menu_cat' : 'words'];
                    }
                }
            }
            // then the person: a row answer (never moving an item off the menu it is already on: that row cannot be ticked, it is changed in
            // Menu Management), and a group answer only for a row with no certain signal (an item on both menus needs its own row answer)
            $kind = '';
            $kindFrom = '';
            $menuClash = '';
            if ($onKind !== '' && $ansMenu !== '' && $ansMenu !== $onKind) {
                [$kind, $kindFrom] = [$onKind, 'menu'];
                $menuClash = 'Already on the ' . ($onKind === 'drink' ? 'Drinks' : 'Food') . ' menu; change it in Menu Management.';
            } elseif ($ansMenu !== '') {
                [$kind, $kindFrom] = [$ansMenu, 'row'];
            } elseif ($certKind !== '') {
                [$kind, $kindFrom] = [$certKind, $certFrom];
            } elseif ($grpKind !== '' && !($cand['drink'] && $cand['food'])) {
                [$kind, $kindFrom] = [$grpKind, 'group'];
            }
            if ($kind === 'drink' && $isWeight) {
                $why = $why !== '' ? $why : 'It is kept by weight (' . $unit . '), so it cannot go on the Drinks menu. Choose Food.';
            }
            if ($kind === 'food') {
                $gr = null;             // food: no glass, shot or bottle sizes; one sale is one stock unit (or its Sold-as / count measure)
                $sizeable = false;
            }
            $isGlass = $gr !== null;
            // ---- a SHOT (owner 2026-11-21: "set the shot size in advance in step 3 ... same as Glass size"): sold by a measure named by a shot
            // word (alias shot_words: the hotel's "shot" on its Drink measures list, or "Tot 35ml" written in the cell). Listed in step 3 even when
            // the bottle size is known, so the shot size can be set before the drink goes on the menu. The shot size only decides the cost of one
            // shot and what each sale takes off stock: never the opening count, the item's count measure or the Drink measures list.
            $isShot = false;
            $shotName = '';
            $cmShot = null;         // the item's own count measure of a shot ("bottle of 25 shots"), kept as it is
            $cmOwn = false;         // ... and it is the very measure this drink is sold by
            if (!$isGlass && $kind !== 'food' && $db['drink_ready'] && (!$isCont || $pour !== null) && in_array($sv['kind'], ['list', 'ml'], true) && $shotSet) {
                $sw = import_measure_singular(mb_strtolower(trim($sv['kind'] === 'list' ? (string)$sv['measure'] : (string)$sv['word'])));
                if ($sw !== '' && isset($shotSet[$sw])) {
                    $isShot = true;
                    $shotName = $sw;
                    // its default size: the hotel's measure of that name, else its first shot measure ("Tot 35ml" with "shot 25 ml" on the list)
                    foreach ([true, false] as $sameName) {
                        foreach ($list as $dm) {
                            $dn = is_array($dm) ? import_measure_singular(mb_strtolower(trim((string)($dm['name'] ?? '')))) : '';
                            if ($dn !== '' && is_numeric($dm['ml'] ?? null) && (float)$dm['ml'] > 0 && ($sameName ? $dn === $sw : isset($shotSet[$dn]))) {
                                [$size['shot_list_ml'], $size['shot_list_name'], $shotName] = [(float)$dm['ml'], (string)$dm['name'], mb_strtolower((string)$dm['name'])];
                                break 2;
                            }
                        }
                    }
                }
            }
            if ($isGlass || $isShot || (in_array($sv['kind'], ['list', 'ml'], true) && $sizeable && ($own === null || $own['source'] === 'name'))) {
                // a container unit with its size in it ("Carton 1 L") groups with the other cartons, not on its own
                $u = $pour !== null ? (string)$pour['word'] : mb_strtolower(trim($unit));
                if ($isGlass) {
                    $g = ['key' => 'gl:' . $u, 'label' => 'Sold by the glass, kept in ' . import_measure_plural($pour !== null ? $u : $unit, 2)];
                } elseif ($isShot) {
                    // one group per shot measure + stock unit (the same key as before for a list shot: "m:shot|bottle"); "Tot 35ml" joins it
                    $g = ['key' => 'm:' . $shotName . '|' . mb_strtolower(trim($unit)), 'label' => 'Sold by the ' . $shotName . ', kept in ' . import_measure_plural($unit, 2)];
                } else {
                    $g = import_drinks_size_group($sv, $unit);
                }
                $size['need'] = true;
                $size['kind'] = $isGlass ? 'glass' : ($isShot ? 'shot' : 'measure');
                $size['group'] = $g['key'];
                $size['group_label'] = $g['label'];
                $grpA = is_array($sizeAns['g'][$g['key']] ?? null) ? $sizeAns['g'][$g['key']] : [];
                // ---- the shot size: the person's answer for this row, the size written in the served-by cell ("Tot 35ml"), an optional reading of
                // that cell ("Shot 35": kept only when the cell writes that very number), the group's answer, the item's own count measure (that
                // same word: "25 shots in one bottle"), then the hotel's Drink measures list. Clearing a row answer falls back to the next one.
                if ($isShot) {
                    $cm = function_exists('rh_stock_measure_get') ? rh_stock_measure_get($ing) : null;
                    if ($cm !== null && $cm['side'] === 'big' && isset($shotSet[import_measure_singular(mb_strtolower((string)$cm['small']))])) {
                        $cmShot = $cm;
                    }
                    $cmOwn = $cmShot !== null && $sv['kind'] === 'list'
                        && import_measure_singular(mb_strtolower((string)$cmShot['small'])) === import_measure_singular(mb_strtolower((string)$sv['measure']));
                    $size['shot_need'] = true;
                    $size['shot_row_ml'] = $num($rowA['shot'] ?? null);
                    $cellRaw = trim((string)($it['served_by'] ?? ''));
                    if ($fileMeasOverTyped) {
                        [$size['shot_ml'], $size['shot_from'], $size['shot_src']] = [$fileMeasMl, 'file', (string)($fs['measure_ml_src'] ?? '')];
                    } elseif ($size['shot_row_ml'] !== null) {
                        [$size['shot_ml'], $size['shot_from']] = [$size['shot_row_ml'], 'row'];
                    } elseif ($fileMeasMl !== null && ($fileMeasFirst || $sv['kind'] !== 'ml') && $servedFrom !== 'answer') {
                        // v11: the file's "Shot size" (a served-by size that disagrees was asked about: measure_first = the person chose the column)
                        [$size['shot_ml'], $size['shot_from'], $size['shot_src']] = [$fileMeasMl, 'file', (string)($fs['measure_ml_src'] ?? '')];
                    } elseif ($sv['kind'] === 'ml') {
                        [$size['shot_ml'], $size['shot_from'], $size['shot_src']] = [(float)$sv['ml'], $servedFrom === 'ai' ? 'ai' : 'cell', $servedSrc];
                    } elseif ($servedFrom !== 'answer' && $aiPortion !== null && $cellRaw !== '' && import_cell_shows_size($cellRaw, $aiPortion)) {
                        [$size['shot_ml'], $size['shot_from'], $size['shot_src']] = [$aiPortion, 'ai', (string)($aiA['portion_ml']['src'] ?? '')];
                    } elseif ($num($grpA['shot'] ?? null) !== null) {
                        [$size['shot_ml'], $size['shot_from']] = [(float)$grpA['shot'], 'group'];
                    } elseif ($cmOwn) {
                        $size['shot_from'] = 'count';       // ml filled in below, once the bottle size is known (none = not known; a sale is still 1/N)
                    } elseif ($size['shot_list_ml'] !== null) {
                        [$size['shot_ml'], $size['shot_from']] = [$size['shot_list_ml'], 'list'];
                    }
                    $size['sale_ml'] = $size['shot_ml'];
                }
                // ---- the glass (owner 2026-11-20: any served glass, milk or juice too): the person's answer for this row, then the size written
                // in the served-by cell ("Glass 250", "250ml glass"), then an optional reading, then the group's answer. Never guessed.
                if ($isGlass) {
                    $size['glass_need'] = true;
                    $size['glass_row_ml'] = $num($rowA['glass'] ?? null);
                    $size['glass_file_ml'] = $gr['ml'] !== null && $servedFrom === 'cell' ? (float)$gr['ml'] : null;
                    if ($fileMeasOverTyped) {
                        [$size['glass_ml'], $size['glass_from'], $size['glass_src']] = [$fileMeasMl, 'file', (string)($fs['measure_ml_src'] ?? '')];
                    } elseif ($size['glass_row_ml'] !== null) {
                        [$size['glass_ml'], $size['glass_from']] = [$size['glass_row_ml'], 'row'];
                    } elseif ($fileMeasMl !== null && ($fileMeasFirst || $gr['ml'] === null) && $servedFrom !== 'answer') {
                        [$size['glass_ml'], $size['glass_from'], $size['glass_src']] = [$fileMeasMl, 'file', (string)($fs['measure_ml_src'] ?? '')];      // v11: "Glass size"
                    } elseif ($gr['ml'] !== null) {
                        [$size['glass_ml'], $size['glass_from'], $size['glass_src']] = [(float)$gr['ml'], $servedFrom === 'ai' ? 'ai' : 'cell', $servedSrc];
                    } elseif ($servedFrom !== 'answer' && $num($aiA['portion_ml']['v'] ?? null) !== null) {
                        [$size['glass_ml'], $size['glass_from'], $size['glass_src']] = [(float)$aiA['portion_ml']['v'], 'ai', (string)($aiA['portion_ml']['src'] ?? '')];
                    } elseif ($num($grpA['glass'] ?? null) !== null) {
                        [$size['glass_ml'], $size['glass_from']] = [(float)$grpA['glass'], 'group'];
                    }
                    $size['sale_ml'] = $size['glass_ml'];
                } elseif (!$isShot) {
                    $size['sale_ml'] = $sv['kind'] === 'ml' ? (float)$sv['ml'] : null;   // ml of one sale (for the "smaller than one shot" warning)
                    if ($sv['kind'] === 'list') {
                        foreach ($list as $dm) {
                            if (is_array($dm) && mb_strtolower((string)($dm['name'] ?? '')) === mb_strtolower((string)$sv['measure']) && is_numeric($dm['ml'] ?? null)) {
                                $size['sale_ml'] = (float)$dm['ml'];
                            }
                        }
                    }
                }
                // ---- the bottle / container size: the item's own saved size (or a volume stock unit) always wins and is never asked; then the
                // person's answer for this row, a size written in its name ("Gin 750ml"), in its unit cell ("Carton 1 L"), an optional reading,
                // then the answer for its group. A blank stays blank: the drink is simply not tickable.
                if ($own !== null && $own['source'] !== 'name') {
                    [$size['ml'], $size['from']] = [$own['container'] !== null ? (float)$own['container'] : null, 'own'];
                } elseif ($isCont && $num($ing['volume_ml'] ?? null) !== null) {
                    [$size['ml'], $size['from']] = [(float)$ing['volume_ml'], 'own'];     // a carton whose size is saved on it already
                } else {
                    $size['cont_need'] = true;
                    // a drink whose own count measure gives one shot needs no bottle size: only its own row answer is used (a group's bottle size
                    // or a reading is not put on it, so nothing about the item changes unless the person types it on that row)
                    $contOptional = $isShot && $size['shot_from'] === 'count';
                    $size['row_ml'] = $num($rowA['ml'] ?? null);
                    $size['name_ml'] = $own !== null ? (float)$own['container'] : ($isCont ? import_measure_name_ml((string)$ing['name']) : null);
                    if ($fileUnitOverTyped) {
                        [$size['ml'], $size['from'], $size['src']] = [$fileUnitMl, 'file', (string)($fs['unit_ml_src'] ?? '')];
                    } elseif ($size['row_ml'] !== null) {
                        [$size['ml'], $size['from']] = [$size['row_ml'], 'row'];
                    } elseif ($fileUnitMl !== null) {
                        // v11: the file's "Bottle size" (a size in the name that disagrees was asked about first)
                        [$size['ml'], $size['from'], $size['src']] = [$fileUnitMl, 'file', (string)($fs['unit_ml_src'] ?? '')];
                    } elseif ($size['name_ml'] !== null) {
                        [$size['ml'], $size['from'], $size['src']] = [$size['name_ml'], 'name', (string)($it['name_source'] ?? '')];
                    } elseif ($pour !== null && $pour['ml'] !== null) {
                        [$size['ml'], $size['from'], $size['src']] = [(float)$pour['ml'], 'cell', (string)($it['unit_source'] ?? '')];
                    } elseif (!$contOptional && $num($aiA['container_ml']['v'] ?? null) !== null) {
                        [$size['ml'], $size['from'], $size['src']] = [(float)$aiA['container_ml']['v'], 'ai', (string)($aiA['container_ml']['src'] ?? '')];
                    } elseif (!$contOptional && $num($grpA['ml'] ?? null) !== null) {
                        [$size['ml'], $size['from']] = [(float)$grpA['ml'], 'group'];
                    }
                    if ($size['ml'] !== null) {
                        $ing['volume_ml'] = $size['ml'];
                    }
                }
                // a glass bigger than its bottle or container, whatever the sizes came from (typed, the file, a reading, the item's own size): a
                // warning on the row, never a refusal; such a row stays listed in step 3 so the person sees it
                $contMl = $size['ml'] !== null ? (float)$size['ml'] : ($own !== null ? (float)($own['container'] ?? $own['per_unit']) : null);
                if ($isGlass && $size['glass_ml'] !== null && $contMl !== null && $contMl > 0 && (float)$size['glass_ml'] > $contMl) {
                    $size['warn'] = 'Check these sizes: a ' . import_measure_fmt((float)$size['glass_ml']) . ' ml glass is more than one ' . $size['word'] . ' ('
                        . import_measure_fmt($contMl) . ' ml). They are kept; change one if it is a slip.';
                }
                if ($isGlass && $size['from'] === 'own' && $size['glass_from'] === 'cell' && $size['warn'] === '') {
                    $size['need'] = false;      // both sizes known (its own size + the glass written in the file): nothing to ask, as before
                }
                if ($isGlass) {
                    // cost of one glass = the same ml line as a shot: glass ml of a container of known ml (none known yet = not worked out)
                    $sv = $size['glass_ml'] !== null && ($size['ml'] !== null || $size['from'] === 'own')
                        ? ['kind' => 'ml', 'measure' => 'ml', 'word' => $gr['word'], 'ml' => $size['glass_ml'], 'size_ml' => null] : ['kind' => 'none'];
                }
                if ($isShot) {
                    $bottleMl = $size['ml'] !== null ? (float)$size['ml'] : ($own !== null && $own['container'] !== null ? (float)$own['container'] : null);
                    $size['bottle_ml'] = $bottleMl;
                    if ($size['shot_from'] === 'count') {
                        // the item's own count measure gives one shot (1/N of a bottle) exactly as before; its ml is shown when the bottle size is known
                        $size['shot_ml'] = $bottleMl !== null ? round($bottleMl / (float)$cmShot['n'], 2) : null;
                        $size['sale_ml'] = $size['shot_ml'];
                        $size['cont_optional'] = $own === null;     // the sale works without a bottle size: it is optional here
                    }
                    // which link one sale gets. Unchanged (the list's "shot", or the count measure) while the shot size is the list's own size or
                    // comes from the count measure: the exact link and figure as before. Any other size = one sale is that many ml of a bottle of
                    // known size (the same ml line as a glass: shot ml / bottle ml); no bottle size yet = not worked out ("Add bottle size").
                    $sameAsList = $sv['kind'] === 'list' && $size['shot_ml'] !== null && $size['shot_list_ml'] !== null
                        && abs((float)$size['shot_ml'] - (float)$size['shot_list_ml']) < 0.005 && $size['shot_from'] !== 'count' && !$cmOwn;
                    if ($size['shot_from'] !== 'count' && !$sameAsList && $size['shot_ml'] !== null) {
                        $sv = ['kind' => 'ml', 'measure' => 'ml', 'word' => $shotName, 'ml' => (float)$size['shot_ml'], 'size_ml' => null];
                    }
                    // the item's own count measure and its bottle size disagree with the shot size in use: a warning only (nothing is changed:
                    // the opening count keeps using the count measure)
                    if ($cmShot !== null && $bottleMl !== null && $bottleMl > 0 && $size['shot_ml'] !== null && $size['shot_from'] !== 'count') {
                        $implied = $bottleMl / (float)$size['shot_ml'];
                        if (abs($implied - (float)$cmShot['n']) > 0.5) {
                            $size['warn'] = 'Check these sizes: this item\'s count measure says ' . import_measure_fmt((float)$cmShot['n']) . ' ' . import_measure_plural((string)$cmShot['small'], 2)
                                . ' in one ' . (string)$cmShot['big'] . ', but a ' . import_measure_fmt((float)$size['shot_ml']) . ' ml ' . $shotName . ' gives '
                                . import_measure_fmt(round($implied, 1)) . ' in a ' . import_measure_fmt($bottleMl) . ' ml ' . $size['word'] . '. Each sale takes '
                                . import_measure_fmt((float)$size['shot_ml']) . ' ml off stock; the opening count still uses ' . import_measure_fmt((float)$cmShot['n']) . ' '
                                . import_measure_plural((string)$cmShot['small'], 2) . ' in one ' . (string)$cmShot['big'] . '. Change one if it is a slip.';
                        }
                    }
                }
            }
            $mc = ($sv['kind'] ?? '') === 'none' ? null : import_drinks_sale_cost($ing, $sv, $served, $list, $pour !== null && $size['ml'] !== null ? (float)$size['ml'] : null);
            $measureLabel = $mc ? ($sv['kind'] === 'ml' ? $mc['name'] . ($sv['word'] !== '' ? ' (' . $sv['word'] . ')' : '') : '1 ' . $mc['name']) : ($served !== '' ? '1 ' . $served : '1 ' . $unit);
            if ($mc === null && $why === '') {
                $saleTxt = $isGlass ? 'glass' : ($isShot ? $shotName : ($sv['kind'] === 'ml' ? import_measure_fmt((float)$sv['ml']) . ' ml' : (string)($sv['word'] ?? '')));
                $contTxt = $size['word'] === 'bottle' ? 'bottle' : $size['word'];
                if ($size['need'] && $isGlass && $size['glass_ml'] === null && $size['cont_need'] && $size['ml'] === null) {
                    $why = 'Add glass size and ' . $contTxt . ' size in Step 3 (optional): how many ml one glass holds, and how many ml one ' . $unit . ' of it holds. Without them the cost of one glass is not known.';
                } elseif ($size['need'] && $isGlass && $size['glass_ml'] === null) {
                    $why = 'Add glass size in Step 3 (optional): how many ml one glass of it holds. Without it the cost of one glass is not known.';
                } elseif ($size['need']) {
                    $why = ($contTxt === 'bottle' ? 'Add bottle size in Step 3 (optional)' : 'Add ' . $contTxt . ' size in Step 3 (optional)') . ': how many ml one ' . $unit . ' of it holds. Without it the cost of one ' . $saleTxt . ' is not known.';
                } elseif ($isCont && $sv['kind'] !== 'unit' && $served !== '') {
                    $why = 'Kept in ' . import_measure_plural($unit, 2) . ': one ' . $unit . ' holds several, so a bottle size cannot be used to work out one ' . ($sv['word'] !== '' ? $sv['word'] : 'sale') . '. Set a count measure on the item in Stock, or add it on the Menu page after the import.';
                } elseif ($kind === 'food' && $served !== '') {
                    $why = 'Sold by "' . $served . '", which cannot be worked out for "' . (string)$it['name'] . '". If one sale is one ' . $unit . ', choose "One whole unit" under Sold as.';
                } elseif ($sv['kind'] === 'as_is' && $served !== '') {
                    $why = 'Sold by "' . $served . '", which is not on your Drink measures list (' . import_stock_page_name() . '), so one sale of "' . (string)$it['name'] . '" cannot be worked out. Add "' . ($sv['word'] !== '' ? $sv['word'] : $served) . '" to that list with its ml, or set a count measure on the item in Stock, then open this list again.';
                } else {
                    $why = 'Sold by "' . $served . '", which "' . (string)$it['name'] . '" cannot be measured in yet (set its bottle size or count measure in Stock). Add it on the Menu page after the import.';
                }
            }
            $cost = $mc['cost'] ?? null;
            $profit = import_menu_profit($price, $cost !== null ? (float)$cost : null);      // as Stock Items > Put on the menu: price - cost to 2 dp
            $below = $cost !== null && $price !== null && rh_menu_cost_over_price((float)$cost, (float)$price);
            // ---- already on a menu? (the candidates found above; a menu the person chose narrows them to that menu)
            $menuIds = [];
            foreach (['drink', 'food'] as $mk) {
                if ($kind === '' || $kind === $mk) {
                    foreach (array_keys($cand[$mk]) as $mid) {
                        $menuIds[] = [$mk, $mid];
                    }
                }
            }
            $menu = null;
            $menuKind = '';
            $action = 'add';
            $rowOf = function (array $c) use ($db) {
                return $c[0] === 'food' ? $db['fmenu'][$c[1]] : $db['menu'][$c[1]];
            };
            if (count($menuIds) > 1) {
                $names = array_map(function ($c) use ($rowOf) {
                    $m = $rowOf($c);
                    return '"' . rh_clean_text((string)$m['item_name']) . '" in ' . rh_clean_text((string)$m['category']) . ($c[0] === 'food' ? ' (Food)' : ' (Drinks)');
                }, array_slice($menuIds, 0, 3));
                $action = 'none';
                if ($why === '') {
                    $why = 'More than one item on the menu matches it (' . implode(', ', $names) . '). Change the right one on the Menu page.';
                }
            } elseif ($menuIds) {
                $menu = $rowOf($menuIds[0]);
                $menuKind = $menuIds[0][0];
                $action = ($price !== null && abs((float)$menu['price'] - $price) <= BALANCE_TOLERANCE) ? 'same' : 'update';
            }
            if ($menu === null) {
                $how = '';
            }
            // ---- menu category: the menu's own (an update never moves an item); else the person's answer for the row, for its group, then
            // the file's category when it is a category of that menu or the file has a category column, else "Bar" for a drink (owner
            // 2026-11-19); a food item with none of these is asked (never guessed)
            $catsOf = $kind === 'food' ? $db['fcats'] : $db['cats'];
            $catDefault = '';
            $catFrom = '';
            if ($kind !== '') {
                if ($fileCat !== '' && isset($catsOf[mb_strtolower($fileCat)])) {
                    [$catDefault, $catFrom] = [$catsOf[mb_strtolower($fileCat)], 'menu_cat'];
                } elseif (!empty($it['category_col']) && $fileCat !== '') {
                    [$catDefault, $catFrom] = [$fileCat, 'column'];
                } elseif ($kind === 'food' && ($it['category_how'] ?? '') === 'section-row' && $fileCat !== '') {
                    [$catDefault, $catFrom] = [$fileCat, 'column'];
                } elseif ($kind === 'drink') {
                    [$catDefault, $catFrom] = [import_drinks_default_category(), 'default'];
                }
            }
            $cat = $catDefault;
            if (isset($a['cat']) && rh_clean_text((string)$a['cat']) !== '') {
                [$cat, $catFrom] = [mb_substr(rh_clean_text((string)$a['cat']), 0, 100), 'row'];
            } elseif (isset($gA['cat']) && rh_clean_text((string)$gA['cat']) !== '') {
                [$cat, $catFrom] = [mb_substr(rh_clean_text((string)$gA['cat']), 0, 100), 'group'];
            }
            if ($menu) {
                [$cat, $catFrom] = [rh_clean_text((string)$menu['category']), 'menu'];
                if ($kind === '') {
                    [$kind, $kindFrom] = [$menuKind, 'menu'];
                }
            } elseif ($cat !== '') {
                $cat = $catsOf[mb_strtolower($cat)] ?? $cat;      // "bar" joins an existing "Bar" (no two spellings of one category)
            }
            // what is still missing, in the order the person fixes it: which menu, its category, the price (the sizes said above)
            $needSize = !empty($size['need']) && !import_drinks_size_done(['size_need' => $size['need'], 'cont_need' => $size['cont_need'], 'size_ml' => $size['ml'],
                'cont_optional' => $size['cont_optional'], 'glass_need' => $size['glass_need'], 'glass_ml' => $size['glass_ml'], 'shot_need' => $size['shot_need'],
                'shot_ml' => $size['shot_ml'], 'shot_from' => $size['shot_from']]);
            $sizeWhy = $needSize ? $why : '';
            if ($sizeWait && $why === '') {
                $why = 'Its count in your file is in ' . ($size['kind'] === 'glass' ? 'glasses' : 'shots') . ' and is not worked out yet: add its sizes here, or answer "How many in one '
                    . $unit . '?" above. It can go on the menu once that is done.';
            }
            if ($kind === '') {
                $why = 'Choose Drinks or Food for it (or for its group "' . ($fileCat !== '' ? $fileCat : 'no category') . '"). Its name and category do not say for certain which menu it belongs to.';
            } elseif ($cat === '' && $action === 'add' && $why === '') {
                $why = 'Choose its menu category (or one for its group).';
            } elseif ($price === null && $why === '') {
                $why = 'Type the selling price (VAT included).';
            }
            if ($menuClash !== '') {
                $why = $menuClash;      // the person chose the other menu for an item already on this one: never moved, never doubled
            }
            // a suggestion (the Assistant, when it is set up): shown, never used until a person accepts it
            // only for a row the rules leave unclear; never against a menu already known for certain (it only fills the category then), never
            // Drinks for an item kept by weight; the category joins an existing one of that menu whatever its case, else it is shown as new
            $sug = null;
            if (($kind === '' || ($cat === '' && $action === 'add')) && isset($aiA['menu']['v']) && in_array($aiA['menu']['v'], ['drink', 'food'], true)) {
                $sMenu = (string)$aiA['menu']['v'];
                $sCat = isset($aiA['menu_cat']['v']) && is_string($aiA['menu_cat']['v']) ? mb_substr(rh_clean_text($aiA['menu_cat']['v']), 0, 100) : '';
                $sCats = $sMenu === 'food' ? $db['fcats'] : $db['cats'];
                $sCatOld = $sCat !== '' && isset($sCats[mb_strtolower($sCat)]);
                $sCat = $sCatOld ? $sCats[mb_strtolower($sCat)] : $sCat;
                if (!($isWeight && $sMenu === 'drink') && ($kind === '' || ($kind === $sMenu && $sCat !== ''))) {
                    $sug = ['menu' => $sMenu, 'cat' => $sCat, 'cat_new' => $sCat !== '' && !$sCatOld, 'src' => (string)($aiA['menu']['src'] ?? '')];
                }
            }
            // the Assistant's reading of the served-by cell (a drink measure) is never a certain signal: at most "Suggested: Drinks", used by "Use this"
            if ($sug === null && $kind === '' && !$isWeight && $servedFrom === 'ai' && import_menu_drink_measure($served, $sv, $glassSet, $shotSet, $drinkWords, $ambWords)) {
                $sug = ['menu' => 'drink', 'cat' => '', 'cat_new' => false, 'src' => $servedSrc];
            }
            $rows[] = [
                'key' => (string)$it['key'], 'name' => mb_substr(rh_clean_text((string)$it['name']), 0, 200), 'name_source' => (string)($it['name_source'] ?? ''),
                'ing_id' => $ex ? (int)$ex['id'] : 0,
                'price' => $price, 'price_source' => $priceSrc, 'price_from' => $priceFrom, 'file_price' => $filePrice, 'typed_price' => $typedPrice,
                'menu' => $kind, 'menu_from' => $kindFrom, 'group' => $gKey, 'group_label' => $fileCat !== '' ? $fileCat : 'No category',
                'group_menu' => $grpKind, 'group_cat' => isset($gA['cat']) ? rh_clean_text((string)$gA['cat']) : '', 'row_menu' => $ansMenu,
                'suggest' => $sug, 'weight' => $isWeight, 'menu_clash' => $menuClash !== '',
                'need_size' => $needSize, 'size_why' => $sizeWhy, 'need_price' => $price === null, 'need_menu' => $kind === '',
                'need_cat' => $kind !== '' && $cat === '' && $action === 'add',
                'measure' => $measureLabel, 'measure_source' => $servedSrc, 'measure_from' => $servedFrom, 'served' => $served,
                'served_cell' => trim((string)($it['served_by'] ?? '')), 'served_unread' => $unread,
                'served_cell_source' => (string)($it['served_by_source'] ?? ''), 'sold' => $soldServed !== null ? $soldV : '',
                'measure_note' => $served === '' ? 'No "served by" cell: one ' . $unit . ' (the main unit).' : '',
                'link' => $mc ? ['kind' => $mc['kind'], 'qty' => $mc['qty'], 'name' => $mc['name'], 'ml' => $mc['ml'] ?? null] : null,
                'unit' => $unit, 'size_need' => $size['need'], 'size_group' => $size['group'], 'size_group_label' => $size['group_label'],
                'size_ml' => $size['ml'], 'size_from' => $size['from'], 'size_row_ml' => $size['row_ml'], 'size_name_ml' => $size['name_ml'],
                'size_sale_ml' => $size['sale_ml'] ?? null, 'size_kind' => $size['kind'], 'size_src' => $size['src'], 'size_word' => $size['word'],
                'cont_need' => $size['cont_need'], 'glass_need' => $size['glass_need'], 'glass_ml' => $size['glass_ml'], 'glass_from' => $size['glass_from'],
                'glass_src' => $size['glass_src'], 'glass_row_ml' => $size['glass_row_ml'], 'glass_file_ml' => $size['glass_file_ml'], 'size_warn' => $size['warn'],
                'cont_optional' => $size['cont_optional'], 'size_bottle_ml' => $size['bottle_ml'],
                'shot_need' => $size['shot_need'], 'shot_ml' => $size['shot_ml'], 'shot_from' => $size['shot_from'], 'shot_src' => $size['shot_src'],
                'shot_row_ml' => $size['shot_row_ml'], 'shot_list_ml' => $size['shot_list_ml'], 'shot_list_name' => $size['shot_list_name'],
                'shot_count_n' => $isShot && $cmShot !== null ? (float)$cmShot['n'] : null,
                'cost' => $cost, 'profit' => $profit, 'below' => $below,
                'cost_note' => $mc === null ? 'Not known: the measure cannot be worked out.' : ($cost === null ? 'Not known: this item has no cost yet.' : ''),
                'category' => $cat, 'category_default' => $catDefault, 'category_from' => $catFrom,
                'action' => $action, 'match_how' => $how,
                'menu_id' => $menu ? (int)$menu['id'] : 0, 'menu_name' => $menu ? rh_clean_text((string)$menu['item_name']) : '',
                'old_price' => $menu ? (float)$menu['price'] : null,
                'why_not' => $why, 'tickable' => $why === '' && $kind !== '' && $price !== null && in_array($action, ['add', 'update'], true),
                'ticked' => !empty($a['tick']),
            ];
            if ($vat['mode'] !== 'off') {       // "Show profit without VAT" (display only; below cost keeps using the VAT-included profit)
                $rows[count($rows) - 1]['profit_ex_vat'] = import_menu_profit_ex_vat($price, $cost !== null ? (float)$cost : null, $vat);
            }
        }
        // two rows of the file that end up as the same menu item: neither is offered (never a silent pick)
        $seen = [];
        foreach ($rows as $i => $r) {
            if ($r['menu'] === '') {
                continue;
            }
            $k = $r['menu'] . ($r['menu_id'] > 0 ? 'm' . $r['menu_id'] : 'n' . mb_strtolower($r['name']));
            $seen[$k][] = $i;
        }
        foreach ($seen as $idxs) {
            if (count($idxs) > 1) {
                foreach ($idxs as $i) {
                    $rows[$i]['tickable'] = false;
                    if ($rows[$i]['why_not'] === '') {
                        $rows[$i]['why_not'] = 'Two rows of your file are the same item on the menu. Skip one of them in step 3, or set the price on the Menu page.';
                    }
                }
            }
        }
        foreach ($rows as &$r) {
            if ($r['action'] === 'same' && $r['why_not'] === '') {
                $r['why_not'] = 'Already on the menu at this price. Nothing to change.';
            }
            $r['ticked'] = $r['ticked'] && $r['tickable'];
            [$r['status'], $r['status_text']] = import_menu_row_status($r);
        }
        unset($r);
        $cats = array_values($db['cats']);
        sort($cats, SORT_NATURAL | SORT_FLAG_CASE);
        $fcats = array_values($db['fcats']);
        sort($fcats, SORT_NATURAL | SORT_FLAG_CASE);
        return ['rows' => $rows, 'waiting' => $waiting, 'cats' => $cats, 'fcats' => $fcats];
    }

    /**
     * The short status of one row of "Put items on the menu" (the full reason is why_not): [code, text]. code: menu | size | price | cat |
     * blocked | same | below | update | ready. A row that needs more than one thing names the first one to fix.
     */
    function import_menu_row_status(array $r): array
    {
        if (!empty($r['need_menu'])) {
            return ['menu', 'Choose menu'];
        }
        if (!empty($r['menu_clash'])) {
            return ['blocked', 'Already on the ' . (($r['menu'] ?? '') === 'food' ? 'Food' : 'Drinks') . ' menu'];
        }
        if (!empty($r['need_size']) && !empty($r['need_price'])) {
            return ['size', 'Needs size and price'];
        }
        if (!empty($r['need_size'])) {
            return ['size', 'Needs size'];
        }
        if (!empty($r['need_cat'])) {
            return ['cat', 'Choose category'];
        }
        if (!empty($r['need_price'])) {
            return ['price', 'Needs price'];
        }
        if (($r['action'] ?? '') === 'same') {
            return ['same', 'Already on the menu'];
        }
        if (empty($r['tickable'])) {
            return ['blocked', 'Cannot go on the menu'];
        }
        if (!empty($r['below'])) {
            return ['below', 'Sells below cost'];
        }
        if (($r['action'] ?? '') === 'update') {
            return ['update', 'Price change'];
        }
        return ['ready', 'Ready'];
    }

    /** The rows the screen shows for a search ($p['q']) and a Show filter ($p['show']), every page. Also what "Tick all" acts on. */
    function import_drinks_filter(array $all, array $p): array
    {
        $q = mb_strtolower(trim(mb_substr((string)($p['q'] ?? ''), 0, 100)));
        $show = in_array($p['show'] ?? '', ['ticked', 'below', 'add', 'update', 'ready', 'need_size', 'need_price', 'need_menu', 'todo', 'drink', 'food'], true) ? (string)$p['show'] : 'all';
        $grp = (string)($p['mgroup'] ?? '');        // one group of "Put items on the menu" (the file's category heading)
        return array_values(array_filter($all, function ($r) use ($q, $show, $grp) {
            if ($grp !== '' && ($r['group'] ?? '') !== $grp) {
                return false;
            }
            if ($q !== '' && mb_strpos(mb_strtolower($r['name'] . ' ' . $r['category'] . ' ' . $r['price_source'] . ' ' . $r['name_source'] . ' ' . ($r['group_label'] ?? '')), $q) === false) {
                return false;
            }
            if ($show === 'ready') {
                return $r['tickable'] && !$r['ticked'];
            }
            if ($show === 'need_size' || $show === 'need_price' || $show === 'need_menu') {
                return !empty($r[$show]);
            }
            if ($show === 'todo') {       // still needs something from the person
                return !empty($r['need_menu']) || !empty($r['need_cat']) || !empty($r['need_size']) || !empty($r['need_price']);
            }
            if ($show === 'drink' || $show === 'food') {
                return ($r['menu'] ?? '') === $show;
            }
            if ($show === 'ticked') {
                return $r['ticked'];
            }
            if ($show === 'below') {
                return $r['below'];
            }
            if ($show === 'add' || $show === 'update') {
                return $r['action'] === $show;
            }
            return true;
        }));
    }

    /** The list for the screen: search, a show filter, 10 per page by default (10 / 25 / 50 / 100). */
    function import_job_drinks_view(PDO $pdo, int $jobId, int $userId, array $p): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state || !in_array($state['job']['status'], ['analysed', 'reviewing', 'confirmed'], true)) {
            return ['ok' => false, 'error' => 'This import cannot be reviewed any more.'];
        }
        $b = import_drinks_build($pdo, (array)$state['res']['items'], (array)$state['answers']);
        $all = $b['rows'];
        $counts = ['listed' => count($all), 'ticked' => 0, 'add' => 0, 'update' => 0, 'below' => 0, 'not_tickable' => 0];
        foreach ($all as $r) {
            $counts['ticked'] += $r['ticked'] ? 1 : 0;
            $counts['add'] += ($r['ticked'] && $r['action'] === 'add') ? 1 : 0;
            $counts['update'] += ($r['ticked'] && $r['action'] === 'update') ? 1 : 0;
            $counts['below'] += $r['below'] ? 1 : 0;
            $counts['not_tickable'] += $r['tickable'] ? 0 : 1;
        }
        $rows = import_drinks_filter($all, $p);
        $size = in_array((int)($p['size'] ?? 10), [10, 25, 50, 100], true) ? (int)$p['size'] : 10;
        $pages = max(1, (int)ceil(count($rows) / $size));
        $page = min($pages, max(1, (int)($p['page'] ?? 1)));
        $out = array_map(function ($r) {
            unset($r['link'], $r['ing_id']);
            return $r;
        }, array_slice($rows, ($page - 1) * $size, $size));
        return ['ok' => true, 'rows' => $out, 'total' => count($rows), 'page' => $page, 'pages' => $pages, 'size' => $size, 'counts' => $counts,
            'waiting' => $b['waiting'], 'cats' => $b['cats'], 'default_category' => import_drinks_default_category(), 'can_menu' => import_drinks_can_menu($userId),
            'currency' => function_exists('getSetting') ? (string)getSetting('currency_symbol', '') : ''];
    }

    /** Whole-list counts of "Put items on the menu" (step 3 live line, step 4 summary line). */
    function import_menu_counts(array $all): array
    {
        $c = ['listed' => count($all), 'ticked' => 0, 'ticked_drink' => 0, 'ticked_food' => 0, 'add' => 0, 'update' => 0, 'below' => 0, 'below_all' => 0,
            'tickable' => 0, 'need_size' => 0, 'need_price' => 0, 'need_menu' => 0, 'need_cat' => 0, 'todo' => 0, 'drink' => 0, 'food' => 0];
        foreach ($all as $r) {
            $t = !empty($r['ticked']);
            $c['ticked'] += $t ? 1 : 0;
            $c['ticked_drink'] += ($t && $r['menu'] === 'drink') ? 1 : 0;
            $c['ticked_food'] += ($t && $r['menu'] === 'food') ? 1 : 0;
            $c['add'] += ($t && $r['action'] === 'add') ? 1 : 0;
            $c['update'] += ($t && $r['action'] === 'update') ? 1 : 0;
            $c['below'] += ($t && $r['below']) ? 1 : 0;
            $c['below_all'] += $r['below'] ? 1 : 0;
            $c['tickable'] += $r['tickable'] ? 1 : 0;
            foreach (['need_size', 'need_price', 'need_menu', 'need_cat'] as $k) {
                $c[$k] += !empty($r[$k]) ? 1 : 0;
            }
            $c['todo'] += (!empty($r['need_menu']) || !empty($r['need_cat']) || !empty($r['need_size']) || !empty($r['need_price'])) ? 1 : 0;
            if (in_array($r['menu'], ['drink', 'food'], true)) {
                $c[$r['menu']]++;
            }
        }
        return $c;
    }

    /** The size groups of the drinks sold by a measure (one shot / glass / bottle size for many drinks at once), as the sizes list gives them. */
    function import_sizes_groups(array $all, array $gAns): array
    {
        $pos = function ($v): ?float {
            return is_numeric($v) && (float)$v > 0 ? (float)$v : null;
        };
        $groups = [];
        foreach ($all as $r) {
            $g = !empty($r['size_need']) ? (string)$r['size_group'] : '';
            if ($g === '') {
                continue;
            }
            if (!isset($groups[$g])) {
                $groups[$g] = ['key' => $g, 'label' => $r['size_group_label'], 'unit' => $r['unit'], 'count' => 0, 'filled' => 0, 'blank' => 0,
                    'kind' => $r['size_kind'], 'word' => $r['size_word'], 'ml' => $pos($gAns[$g]['ml'] ?? null), 'glass' => $pos($gAns[$g]['glass'] ?? null),
                    'cont' => false, 'glass_q' => $r['size_kind'] === 'glass',
                    'shot' => $pos($gAns[$g]['shot'] ?? null), 'shot_q' => !empty($r['shot_need']), 'shot_list_ml' => $r['shot_list_ml'] ?? null, 'shot_list_name' => (string)($r['shot_list_name'] ?? '')];
            }
            $groups[$g]['count']++;
            $groups[$g]['cont'] = $groups[$g]['cont'] || (!empty($r['cont_need']) && empty($r['cont_optional']));
            $groups[$g][import_drinks_size_done($r) ? 'filled' : 'blank']++;
        }
        $groups = array_values($groups);
        usort($groups, function ($a, $b) {
            return $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']);
        });
        return $groups;
    }

    /**
     * Step 3 "Put items on the menu" (owner 2026-11-22: one table, any item, grouped by the file's category heading). $p: q, show, mgroup (a group
     * key: its rows come back paged; '' = the overview only), page, size (10 / 25 / 50, 0 = all), ai (false = never ask the optional reader:
     * tests only). Always: the counts, the groups (name, count, on the menu, what is still needed, the group's answer, a suggestion when every
     * suggestion of its rows agrees), the size groups and choices the size boxes use.
     */
    function import_job_menu_view(PDO $pdo, int $jobId, int $userId, array $p): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state || !in_array($state['job']['status'], ['analysed', 'reviewing', 'confirmed'], true)) {
            return ['ok' => false, 'error' => 'This import cannot be reviewed any more.'];
        }
        $editable = in_array($state['job']['status'], ['analysed', 'reviewing'], true);
        if ($editable && ($p['ai'] ?? true) !== false && import_job_sizes_ai_fill($pdo, $jobId, null, true) > 0) {
            $state = import_job_state($pdo, $jobId) ?: $state;
        }
        $answers = (array)$state['answers'];
        $b = import_drinks_build($pdo, (array)$state['res']['items'], $answers);
        $all = $b['rows'];
        $shown = import_drinks_filter($all, ['q' => $p['q'] ?? '', 'show' => $p['show'] ?? 'all']);
        $shownKeys = array_flip(array_column($shown, 'key'));
        $groups = [];
        foreach ($all as $r) {
            $g = $r['group'];
            if (!isset($groups[$g])) {
                $groups[$g] = ['key' => $g, 'label' => $r['group_label'], 'count' => 0, 'shown' => 0, 'ticked' => 0, 'tickable' => 0, 'tickable_shown' => 0,
                    'todo' => 0, 'need_menu' => 0, 'need_cat' => 0, 'drink' => 0, 'food' => 0, 'add' => 0, 'menu_answer' => $r['group_menu'], 'cat_answer' => $r['group_cat'],
                    'cats' => [], 'sugs' => [], 'weight' => 0];
            }
            $G = &$groups[$g];
            $G['count']++;
            $isShown = isset($shownKeys[$r['key']]);
            $G['shown'] += $isShown ? 1 : 0;
            $G['ticked'] += $r['ticked'] ? 1 : 0;
            $G['tickable'] += $r['tickable'] ? 1 : 0;
            $G['tickable_shown'] += ($r['tickable'] && !$r['ticked'] && $isShown) ? 1 : 0;
            $G['todo'] += (!empty($r['need_menu']) || !empty($r['need_cat']) || !empty($r['need_size']) || !empty($r['need_price'])) ? 1 : 0;
            $G['need_menu'] += $r['need_menu'] ? 1 : 0;
            $G['need_cat'] += $r['need_cat'] ? 1 : 0;
            $G['add'] += $r['action'] === 'add' ? 1 : 0;
            $G['weight'] += !empty($r['weight']) ? 1 : 0;
            if (in_array($r['menu'], ['drink', 'food'], true)) {
                $G[$r['menu']]++;
            }
            if ($r['category'] !== '') {
                $G['cats'][$r['category']] = true;
            }
            $G['sugs'][] = $r;
            unset($G);
        }
        foreach ($groups as &$G) {
            $G['menu'] = $G['drink'] === $G['count'] ? 'drink' : ($G['food'] === $G['count'] ? 'food' : (($G['drink'] || $G['food']) ? 'mixed' : ''));
            $G['category'] = count($G['cats']) === 1 ? (string)array_key_first($G['cats']) : '';
            $G['suggest'] = import_menu_group_suggest($G['sugs']);
            unset($G['cats'], $G['sugs']);
        }
        unset($G);
        $groups = array_values($groups);
        $out = ['ok' => true, 'counts' => import_menu_counts($all), 'groups' => $groups, 'waiting' => $b['waiting'], 'cats' => $b['cats'], 'fcats' => $b['fcats'],
            'default_category' => import_drinks_default_category(), 'can_menu' => import_drinks_can_menu($userId), 'editable' => $editable,
            'drink_ready' => function_exists('rh_stock_drink_ready') && rh_stock_drink_ready($pdo),
            'size_groups' => import_sizes_groups($all, (array)($answers['_sizes']['g'] ?? [])),
            'currency' => function_exists('getSetting') ? (string)getSetting('currency_symbol', '') : '', 'shown' => count($shown), 'vat' => import_menu_vat_view(),
            'tickable_shown' => count(array_filter($shown, function ($r) {
                return $r['tickable'] && !$r['ticked'];
            })), 'ticked_shown' => count(array_filter($shown, function ($r) {
                return $r['ticked'];
            }))];
        $aliases = import_load_aliases($pdo);
        $out['sold_options'] = array_map(function ($o) {
            return ['v' => $o['v'], 'label' => $o['label']];
        }, import_drinks_sold_options(function_exists('rh_drink_measures_list') ? rh_drink_measures_list() : [],
            import_word_set((array)($aliases['shot_words'] ?? [])), import_word_set((array)($aliases['glass_words'] ?? []))));
        $mg = (string)($p['mgroup'] ?? '');
        if ($mg !== '') {
            $rows = import_drinks_filter($all, ['q' => $p['q'] ?? '', 'show' => $p['show'] ?? 'all', 'mgroup' => $mg]);
            $size = (int)($p['size'] ?? 10);
            $size = in_array($size, [10, 25, 50, 0], true) ? $size : 10;
            $per = $size === 0 ? max(1, count($rows)) : $size;
            $pages = max(1, (int)ceil(count($rows) / $per));
            $page = min($pages, max(1, (int)($p['page'] ?? 1)));
            $out['rows'] = array_map(function ($r) {
                unset($r['link'], $r['ing_id']);
                $r['size_done'] = import_drinks_size_done($r);
                return $r;
            }, array_slice($rows, ($page - 1) * $per, $per));
            $out += ['mgroup' => $mg, 'total' => count($rows), 'page' => $page, 'pages' => $pages, 'size' => $size, 'keys' => array_column($rows, 'key')];
        }
        return $out;
    }

    /**
     * The group's suggestion (shown as "Suggested: Food · Snacks"; used only by its "Use this" click, as the person's group answer): only for a
     * group where EVERY row is unclear (needs its menu or its category) and every row carries the very same suggestion. A group with one row
     * known for certain, one row without a suggestion or two suggestions that differ gets none (never a pick, never over a certain answer).
     */
    function import_menu_group_suggest(array $rows): ?array
    {
        $one = null;
        foreach ($rows as $r) {
            if ((empty($r['need_menu']) && empty($r['need_cat'])) || empty($r['suggest'])) {
                return null;
            }
            $k = $r['suggest']['menu'] . '|' . $r['suggest']['cat'];
            if ($one !== null && $one[0] !== $k) {
                return null;
            }
            $one = $one ?? [$k, $r['suggest']];
        }
        return $one[1] ?? null;
    }

    /**
     * Change "Put items on the menu" (step 3). Needs the Menu permission (and Import: the page checks it). Modes:
     *   tick (key, on) | tick_all (on, q, show, mgroup: every row the list shows, all pages; on = only rows that can be ticked)
     *   price (key, value; '' = back to the file's price): the menu price only, checked as Stock Items > Put on the menu checks it
     *   menu (key, value drink | food | '' = clear) | menu_keys (keys[], value) : which menu, the person's answer for rows
     *   category (key, value) | category_keys (keys[], value) | category_all (value: every item that would be added)
     *   group_menu (group, value drink | food | '') | group_cat (group, value; '' = clear): one answer for every row of a group (a row answer wins)
     *   accept (group or key): use the suggestion shown for it (a suggestion is never used until this)
     * Any ticked row that can no longer go on the menu after the change is unticked in the same save.
     */
    function import_job_drinks_set(PDO $pdo, int $jobId, int $userId, array $p): array
    {
        if (!import_drinks_can_menu($userId)) {
            return ['ok' => false, 'error' => 'Your account cannot change the menu (it needs the Menu permission), so items cannot be ticked or priced here.'];
        }
        $state = import_job_state($pdo, $jobId);
        if (!$state || !in_array($state['job']['status'], ['analysed', 'reviewing'], true)) {
            return ['ok' => false, 'error' => 'That import cannot be changed.'];
        }
        $items = (array)$state['res']['items'];
        $b = import_drinks_build($pdo, $items, (array)$state['answers']);
        $byKey = [];
        $groupKeys = [];
        foreach ($b['rows'] as $r) {
            $byKey[$r['key']] = $r;
            $groupKeys[$r['group']][] = $r['key'];
        }
        $mode = (string)($p['mode'] ?? '');
        $key = (string)($p['key'] ?? '');
        $grp = (string)($p['group'] ?? '');
        $raw = (string)($p['value'] ?? '');
        $cat = rh_clean_text(mb_substr($raw, 0, 200));
        $keys = [];
        $vals = [];         // what is written on each row of $keys
        if (in_array($mode, ['category', 'category_all', 'category_keys'], true) && ($cat === '' || mb_strlen($cat) > 100)) {
            return ['ok' => false, 'error' => 'Type a category (100 characters at most).'];
        }
        if ($mode === 'group_cat' && mb_strlen($cat) > 100) {
            return ['ok' => false, 'error' => 'Type a category (100 characters at most).'];
        }
        if (in_array($mode, ['menu', 'menu_keys', 'group_menu'], true) && !in_array($raw, ['drink', 'food', ''], true)) {
            return ['ok' => false, 'error' => 'Choose Drinks or Food.'];
        }
        $oneRow = function (string $k) use ($byKey) {
            return $byKey[$k] ?? null;
        };
        $pickKeys = function () use ($p, $byKey): array {
            $o = [];
            foreach ((array)($p['keys'] ?? []) as $k) {
                if (is_string($k) && isset($byKey[$k])) {
                    $o[$k] = true;
                }
            }
            return array_slice(array_keys($o), 0, 5000);
        };
        if (in_array($mode, ['tick', 'category', 'price', 'menu'], true) || ($mode === 'accept' && $grp === '')) {
            $r = $oneRow($key);
            if (!$r) {
                return ['ok' => false, 'error' => 'That item is not in this list any more. Reload the list.'];
            }
            if ($mode === 'tick' && !empty($p['on']) && !$r['tickable']) {
                return ['ok' => false, 'error' => $r['why_not'] !== '' ? $r['why_not'] : 'That item cannot be ticked.'];
            }
            if ($mode === 'category' && $r['action'] !== 'add') {
                return ['ok' => false, 'error' => 'This item is already on the menu, so it keeps its category. Change it on the Menu page.'];
            }
            if ($mode === 'menu' && $raw === 'drink' && !empty($r['weight'])) {
                return ['ok' => false, 'error' => 'It is kept by weight (' . $r['unit'] . '), so it cannot go on the Drinks menu.'];
            }
            $keys = [$key];
            if ($mode === 'tick') {
                $vals = ['tick' => !empty($p['on']) ? 1 : 0];
            } elseif ($mode === 'category') {
                $vals = ['cat' => $cat];
            } elseif ($mode === 'menu') {
                $vals = ['menu' => $raw === '' ? null : $raw];
            } elseif ($mode === 'accept') {
                if (!$r['suggest']) {
                    return ['ok' => false, 'error' => 'There is no suggestion for this item any more. Reload the list.'];
                }
                $vals = ['menu' => $r['suggest']['menu']] + ($r['suggest']['cat'] !== '' && $r['action'] === 'add' ? ['cat' => mb_substr($r['suggest']['cat'], 0, 100)] : []);
            } else {
                // price: blank = back to the file's own price; else a price above 0 with at most 2 decimals (VAT included)
                $pv = import_menu_price_parse($raw);
                if ($pv === false) {
                    return ['ok' => false, 'error' => function_exists('stm_price_comma_wrong') && stm_price_comma_wrong($raw)
                        ? 'Use a dot for decimals, e.g. 1.50, and commas only between thousands, e.g. 1,500.'
                        : 'The price must be a number above 0 with at most 2 decimals (VAT included), up to 99,999,999.99.'];
                }
                $same = $pv !== null && $r['file_price'] !== null && abs((float)$r['file_price'] - $pv) <= BALANCE_TOLERANCE;
                $vals = ['price' => ($pv === null || $same) ? null : $pv];
            }
        } elseif ($mode === 'menu_keys' || $mode === 'category_keys') {
            $keys = $pickKeys();
            if (!$keys) {
                return ['ok' => false, 'error' => 'Select at least one item first.'];
            }
            if ($mode === 'menu_keys' && $raw === 'drink') {
                $keys = array_values(array_filter($keys, function ($k) use ($byKey) {
                    return empty($byKey[$k]['weight']);
                }));
            } elseif ($mode === 'category_keys') {
                $keys = array_values(array_filter($keys, function ($k) use ($byKey) {
                    return $byKey[$k]['action'] === 'add';
                }));
            }
            if (!$keys) {
                return ['ok' => false, 'error' => $mode === 'menu_keys' ? 'Items kept by weight cannot go on the Drinks menu.' : 'Every selected item is already on the menu, so each keeps its category.'];
            }
            $vals = $mode === 'menu_keys' ? ['menu' => $raw === '' ? null : $raw] : ['cat' => $cat];
        } elseif (in_array($mode, ['group_menu', 'group_cat'], true) || ($mode === 'accept' && $grp !== '')) {
            if (!isset($groupKeys[$grp])) {
                return ['ok' => false, 'error' => 'That group is not in this list any more. Reload the list.'];
            }
        } elseif ($mode === 'category_all') {
            foreach ($b['rows'] as $r) {
                if ($r['action'] === 'add') {
                    $keys[] = $r['key'];
                }
            }
            if (!$keys) {
                return ['ok' => false, 'error' => 'No item in this list would be added, so there is no category to set.'];
            }
            $vals = ['cat' => $cat];
        } elseif ($mode === 'tick_all') {
            // every row the list shows now (search + Show + group, all pages): on = tick the ones that can be ticked; off = untick them all
            foreach (import_drinks_filter($b['rows'], $p) as $r) {
                if (!empty($p['on']) ? ($r['tickable'] && !$r['ticked']) : $r['ticked']) {
                    $keys[] = $r['key'];
                }
            }
            if (!$keys) {
                return ['ok' => false, 'error' => !empty($p['on']) ? 'Every item in this list that can go on the menu is already ticked.' : 'No item in this list is ticked.'];
            }
            $vals = ['tick' => !empty($p['on']) ? 1 : 0];
        } else {
            return ['ok' => false, 'error' => 'Unknown change.'];
        }
        // a group's suggestion: only when every suggestion of its rows says the same thing (as the list shows it)
        $gSug = null;
        if ($mode === 'accept' && $grp !== '') {
            $gSug = import_menu_group_suggest(array_map(function ($k) use ($byKey) {
                return $byKey[$k];
            }, $groupKeys[$grp]));
            if ($gSug === null) {
                return ['ok' => false, 'error' => 'There is no single suggestion for this group. Choose Drinks or Food yourself.'];
            }
        }
        $now = date('Y-m-d H:i:s');
        return import_cont_save($pdo, $jobId, '', function (array &$answers) use ($pdo, $items, $mode, $grp, $raw, $cat, $keys, $vals, $gSug, $userId, $now) {
            $who = ['by' => $userId > 0 ? $userId : null, 'at' => $now];
            foreach ($keys as $k) {
                $cur = is_array($answers['_drinks'][$k] ?? null) ? $answers['_drinks'][$k] : [];
                foreach ($vals as $f => $v) {
                    if ($v === null) {
                        unset($cur[$f]);
                    } else {
                        $cur[$f] = $v;
                    }
                }
                $answers['_drinks'][$k] = array_merge($cur, $who);
            }
            if ($grp !== '' && in_array($mode, ['group_menu', 'group_cat', 'accept'], true)) {
                $g = is_array($answers['_menu_g'][$grp] ?? null) ? $answers['_menu_g'][$grp] : [];
                if ($mode === 'group_menu') {
                    if ($raw === '') {
                        unset($g['menu']);
                    } else {
                        $g['menu'] = $raw;
                    }
                } elseif ($mode === 'group_cat') {
                    if ($cat === '') {
                        unset($g['cat']);
                    } else {
                        $g['cat'] = mb_substr($cat, 0, 100);
                    }
                } else {
                    $g['menu'] = $gSug['menu'];
                    if ($gSug['cat'] !== '') {
                        $g['cat'] = mb_substr($gSug['cat'], 0, 100);
                    }
                }
                if (isset($g['menu']) || isset($g['cat'])) {
                    $answers['_menu_g'][$grp] = array_merge(array_diff_key($g, ['by' => 1, 'at' => 1]), $who);
                } else {
                    unset($answers['_menu_g'][$grp]);
                }
            }
            // a ticked row that can no longer go on the menu (a cleared price, another menu ...) is unticked in the same save
            $unticked = 0;
            if ($mode !== 'tick' && $mode !== 'tick_all') {
                foreach (import_drinks_build($pdo, $items, $answers)['rows'] as $r) {
                    if (!$r['tickable'] && !empty($answers['_drinks'][$r['key']]['tick'])) {
                        $answers['_drinks'][$r['key']]['tick'] = 0;
                        $unticked++;
                    }
                }
            }
            return ['changed' => $keys ? count($keys) : 1, 'unticked' => $unticked];
        });
    }

    /**
     * Step 3 "bottle size" list (optional, owner 2026-11-20): the drinks sold by a measure (shot, glass, 25 ml ...) whose bottle size is not
     * known, grouped by measure + stock unit (one answer per group, a different one per row). A size written in the item's name is shown
     * as read from the file; nothing is ever filled in by a guess. Never blocks anything: a blank just leaves that drink off the menu list.
     */
    /**
     * The rows of the step 3 sizes list (every page): a drink that needs a size, or whose "served by" was read for it (an optional reading or the
     * person's own "Sold as" answer, so it can always be changed back), each with size_done.
     */
    function import_sizes_all(array $rows): array
    {
        $all = [];
        foreach ($rows as $r) {
            if (empty($r['size_need']) && !in_array($r['measure_from'], ['ai', 'answer'], true)) {
                continue;
            }
            $r['size_done'] = import_drinks_size_done($r);
            $all[] = $r;
        }
        return $all;
    }

    /** The rows the step 3 sizes list shows for a search (q), a group and a Show filter (all | blank | filled), every page. Also what "Tick all shown" takes. */
    function import_sizes_filter(array $all, array $p): array
    {
        $q = mb_strtolower(trim(mb_substr((string)($p['q'] ?? ''), 0, 100)));
        $grp = (string)($p['group'] ?? '');
        $show = in_array($p['show'] ?? '', ['blank', 'filled'], true) ? (string)$p['show'] : 'all';
        return array_values(array_filter($all, function ($r) use ($q, $grp, $show) {
            if ($q !== '' && mb_strpos(mb_strtolower($r['name'] . ' ' . $r['measure'] . ' ' . $r['name_source']), $q) === false) {
                return false;
            }
            if ($grp !== '' && (!$r['size_need'] || $r['size_group'] !== $grp)) {
                return false;
            }
            return $show === 'all' || ($show === 'blank' ? !$r['size_done'] : $r['size_done']);
        }));
    }

    /**
     * A short status for the "Menu in step 4" column (the full reason is why_not): [text, kind] with kind ok | need | off.
     * "Needs bottle size", "Needs glass size", "Needs shot size", "Needs glass and carton size" ...
     */
    function import_sizes_menu_status(array $r): array
    {
        if (!empty($r['tickable'])) {
            return ['Ready for step 4', 'ok'];
        }
        $need = [];
        if (!empty($r['size_need'])) {
            if (!empty($r['glass_need']) && $r['glass_ml'] === null) {
                $need[] = 'glass';
            }
            if (!empty($r['shot_need']) && ($r['shot_ml'] ?? null) === null && ($r['shot_from'] ?? '') !== 'count') {
                $need[] = (string)($r['shot_list_name'] ?? '') !== '' ? mb_strtolower((string)$r['shot_list_name']) : 'shot';
            }
            if (!empty($r['cont_need']) && $r['size_ml'] === null && empty($r['cont_optional'])) {
                $need[] = (string)($r['size_word'] ?? '') !== '' ? (string)$r['size_word'] : 'bottle';
            }
        }
        if ($need) {
            return ['Needs ' . implode(' and ', $need) . ' size', 'need'];
        }
        if (($r['action'] ?? '') === 'same') {
            return ['Already on the menu', 'off'];
        }
        return ['Not for step 4', 'off'];
    }

    function import_job_sizes_view(PDO $pdo, int $jobId, int $userId, array $p): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state || !in_array($state['job']['status'], ['analysed', 'reviewing', 'confirmed'], true)) {
            return ['ok' => false, 'error' => 'This import cannot be reviewed any more.'];
        }
        $editable = in_array($state['job']['status'], ['analysed', 'reviewing'], true);
        // $p['ai'] === false (tests and scripts only; the page never sends it) = never ask the optional reader
        if ($editable && ($p['ai'] ?? true) !== false && import_job_sizes_ai_fill($pdo, $jobId, null, true) > 0) {
            $state = import_job_state($pdo, $jobId) ?: $state;     // optional readings were added: read the answers again
        }
        $b = import_drinks_build($pdo, (array)$state['res']['items'], (array)$state['answers']);
        $gAns = (array)($state['answers']['_sizes']['g'] ?? []);
        $groups = [];
        $all = import_sizes_all($b['rows']);
        $pos = function ($v): ?float {
            return is_numeric($v) && (float)$v > 0 ? (float)$v : null;
        };
        $cols = ['glass' => false, 'shot' => false, 'cont' => false];      // a size column no drink of the list needs is left out
        foreach ($all as $r) {
            $cols['glass'] = $cols['glass'] || !empty($r['glass_need']);
            $cols['shot'] = $cols['shot'] || !empty($r['shot_need']);
            $cols['cont'] = $cols['cont'] || !empty($r['cont_need']) || ($r['size_from'] ?? '') === 'own';
            $g = $r['size_need'] ? $r['size_group'] : '';
            if ($g !== '' && !isset($groups[$g])) {
                $groups[$g] = ['key' => $g, 'label' => $r['size_group_label'], 'unit' => $r['unit'], 'count' => 0, 'filled' => 0, 'blank' => 0,
                    'kind' => $r['size_kind'], 'word' => $r['size_word'], 'ml' => $pos($gAns[$g]['ml'] ?? null), 'glass' => $pos($gAns[$g]['glass'] ?? null),
                    'cont' => false, 'glass_q' => $r['size_kind'] === 'glass',
                    'shot' => $pos($gAns[$g]['shot'] ?? null), 'shot_q' => !empty($r['shot_need']), 'shot_list_ml' => $r['shot_list_ml'] ?? null, 'shot_list_name' => (string)($r['shot_list_name'] ?? '')];
            }
            if ($g !== '') {
                $groups[$g]['count']++;
                $groups[$g]['cont'] = $groups[$g]['cont'] || (!empty($r['cont_need']) && empty($r['cont_optional']));
                $groups[$g][$r['size_done'] ? 'filled' : 'blank']++;
            }
        }
        $groups = array_values($groups);
        usort($groups, function ($a, $b) {
            return $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']);
        });
        $rows = import_sizes_filter($all, $p);
        $size = (int)($p['size'] ?? 10);
        $size = in_array($size, [10, 25, 50, 100], true) ? $size : 10;
        $pages = max(1, (int)ceil(count($rows) / $size));
        $page = min($pages, max(1, (int)($p['page'] ?? 1)));
        $out = array_map(function ($r) {
            [$mText, $mKind] = import_sizes_menu_status($r);
            return ['key' => $r['key'], 'name' => $r['name'], 'name_source' => $r['name_source'], 'unit' => $r['unit'], 'measure' => $r['measure'],
                'measure_source' => $r['measure_source'], 'measure_from' => $r['measure_from'], 'served_cell' => $r['served_cell'],
                'served_cell_source' => (string)($r['served_cell_source'] ?? ''), 'sold' => (string)($r['sold'] ?? ''), 'menu_status' => $mText, 'menu_kind' => $mKind,
                'group' => $r['size_need'] ? $r['size_group'] : '', 'group_label' => $r['size_group_label'], 'kind' => $r['size_kind'], 'word' => $r['size_word'],
                'cont_need' => !empty($r['cont_need']), 'ml' => $r['size_ml'], 'from' => $r['size_from'], 'src' => $r['size_src'], 'row_ml' => $r['size_row_ml'],
                'name_ml' => $r['size_name_ml'], 'glass_need' => !empty($r['glass_need']), 'glass_ml' => $r['glass_ml'], 'glass_from' => $r['glass_from'],
                'glass_src' => $r['glass_src'], 'glass_row_ml' => $r['glass_row_ml'], 'done' => $r['size_done'], 'warn' => (string)($r['size_warn'] ?? ''),
                'cont_optional' => !empty($r['cont_optional']), 'bottle_ml' => $r['size_bottle_ml'] ?? null,
                'shot_need' => !empty($r['shot_need']), 'shot_ml' => $r['shot_ml'] ?? null, 'shot_from' => (string)($r['shot_from'] ?? ''), 'shot_src' => (string)($r['shot_src'] ?? ''),
                'shot_row_ml' => $r['shot_row_ml'] ?? null, 'shot_list_ml' => $r['shot_list_ml'] ?? null, 'shot_list_name' => (string)($r['shot_list_name'] ?? ''),
                'shot_count_n' => $r['shot_count_n'] ?? null,
                'tickable' => $r['tickable'], 'why_not' => $r['why_not']];
        }, array_slice($rows, ($page - 1) * $size, $size));
        $filled = $typed = 0;
        foreach ($all as $r) {
            $filled += $r['size_done'] ? 1 : 0;
            // the sizes the import will save on stock items (same rule as import_drinks_sizes_commit)
            $typed += (!empty($r['size_need']) && !empty($r['cont_need']) && $r['size_ml'] !== null && (in_array($r['size_from'], ['row', 'group'], true)
                || (in_array($r['size_from'], ['cell', 'ai', 'file'], true) && !empty($r['ticked'])))) ? 1 : 0;
        }
        $aliases = import_load_aliases($pdo);
        $opts = array_map(function ($o) {
            return ['v' => $o['v'], 'label' => $o['label']];
        }, import_drinks_sold_options(function_exists('rh_drink_measures_list') ? rh_drink_measures_list() : [],
            import_word_set((array)($aliases['shot_words'] ?? [])), import_word_set((array)($aliases['glass_words'] ?? []))));
        return ['ok' => true, 'groups' => $groups, 'rows' => $out, 'total' => count($rows), 'page' => $page, 'pages' => $pages, 'size' => $size,
            'counts' => ['need' => count($all), 'filled' => $filled, 'blank' => count($all) - $filled, 'typed' => $typed], 'drink_ready' => function_exists('rh_stock_drink_ready') && rh_stock_drink_ready($pdo),
            'editable' => $editable, 'cols' => $cols, 'sold_options' => $opts,
            // every drink the list shows now (search + group + Show, ALL pages): what "Tick all shown" ticks
            'keys' => array_map(function ($r) {
                return (string)$r['key'];
            }, $rows)];
    }

    /**
     * Every size a row of step 3 needs is there (the glass, when sold by the glass; the shot, when sold by the shot; the bottle / container,
     * unless the item has its own or its own count measure already gives one shot).
     */
    function import_drinks_size_done(array $r): bool
    {
        $cont = empty($r['cont_need']) || $r['size_ml'] !== null || !empty($r['cont_optional']);
        $glass = empty($r['glass_need']) || $r['glass_ml'] !== null;
        $shot = empty($r['shot_need']) || ($r['shot_ml'] ?? null) !== null || ($r['shot_from'] ?? '') === 'count';
        return empty($r['size_need']) ? true : ($cont && $glass && $shot);
    }

    /**
     * The optional Assistant reader (wiring only; includes/ai is the AI lead's): rh_ai_import_measure_suggest() from
     * includes/ai/ai-import-suggest.php when that file is there and the Assistant is switched on (fail-closed). null = off: everything
     * works exactly as without it.
     */
    function import_measure_ai_hook(PDO $pdo): ?callable
    {
        try {
            $f = dirname(__DIR__) . '/ai/ai-import-suggest.php';
            if (!function_exists('rh_ai_import_measure_suggest') && is_file($f)) {
                require_once $f;
            }
            if (!function_exists('rh_ai_import_measure_suggest') || !function_exists('rh_ai_enabled') || !rh_ai_enabled($pdo)) {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }
        return function (array $items): array {
            $r = rh_ai_import_measure_suggest($items);
            return is_array($r) ? $r : [];
        };
    }

    /**
     * Ask the optional reader about the rows the plain rules could not read (a served-by cell not understood, a glass size or a bottle /
     * container size still blank): ONE call per import (job marker _sizes.ai_done), at most 40 rows; rows past 40 stay for the person. The checked readings are kept in the job's answers (_sizes.ai) and
     * only ever pre-fill step 3 as read from the file: the person's own answers always win, and clearing a row clears its reading.
     * $fn null + $useHook = the Assistant hook (none = nothing asked). Returns the number of readings kept.
     */
    function import_job_sizes_ai_fill(PDO $pdo, int $jobId, ?callable $fn = null, bool $useHook = false, bool $colsOnly = false): int
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state || !in_array($state['job']['status'], ['analysed', 'reviewing'], true)) {
            return 0;
        }
        $answers = (array)$state['answers'];
        if (!empty($answers['_sizes']['ai_done'])) {
            return 0;               // one call per import, ever (each call costs money): never again on a reload or a repeated request
        }
        // #10: the columns the rules could not place ride in the SAME one call (step 2 makes it only when there is such a column; step 3 as before)
        if ($fn === null) {
            $fn = $useHook ? import_measure_ai_hook($pdo) : null;
            if ($fn === null) {
                return 0;           // off: nothing asked, nothing marked (asked again once it is on)
            }
        }
        $colAliases = import_job_engine_opts($pdo, $answers, (array)$state['ctx'])['aliases'];
        $cols = import_cols_ai_ask($state, $colAliases);
        if ($colsOnly && !$cols) {
            return 0;
        }
        $asked = (array)($answers['_sizes']['ai_asked'] ?? []);
        $ask = [];
        $allowed = [];
        $colByKey = [];
        foreach ($cols as $cq) {
            $ask[] = ['key' => $cq['key'], 'column' => $cq['column'], 'samples' => $cq['samples']];
            $allowed[$cq['key']] = ['column' => true];
            $colByKey[$cq['key']] = $cq;
        }
        $nCols = count($ask);
        foreach (import_drinks_build($pdo, (array)$state['res']['items'], $answers)['rows'] as $r) {
            if (isset($asked[$r['key']]) || count($ask) - $nCols >= 40) {
                continue;
            }
            $f = [];
            if (!empty($r['served_unread'])) {
                $f['served_by'] = true;
            }
            if (!empty($r['size_need']) && !empty($r['glass_need']) && $r['glass_ml'] === null) {
                $f['portion_ml'] = true;
            }
            if (!empty($r['size_need']) && !empty($r['cont_need']) && $r['size_ml'] === null && empty($r['cont_optional'])) {
                $f['container_ml'] = true;
            }
            // a shot size the plain rules did not read, in a served-by cell that writes a number ("Shot 35", or an unread cell that may be one):
            // ml in one sale = the shot size (the same portion_ml field as a glass). Kept later only when the cell writes that very number.
            $cellNums = array_filter(import_cell_numbers((string)$r['served_cell']), function ($v) {
                return abs($v - 1.0) >= 1e-9;
            });
            if ($cellNums && (!empty($r['served_unread']) || (!empty($r['shot_need']) && !in_array($r['shot_from'], ['row', 'cell'], true)))) {
                $f['portion_ml'] = true;
            }
            if (($r['measure_from'] ?? '') === 'answer') {
                unset($f['portion_ml']);    // the person said how it is sold: a reading of the file's cell is not used for its glass / shot size
            }
            if (!empty($r['need_menu']) || !empty($r['need_cat'])) {
                // which menu (Drinks or Food) and its category, when the rules are not certain (2026-11-22): a suggestion only, accepted by a click
                $f['menu'] = true;
                $f['menu_cat'] = true;
            }
            if ($f) {
                // ask_menu: only a row the rules leave unclear is asked which menu (a certain row is never sent for it, never overridden)
                $ask[] = ['key' => $r['key'], 'name' => $r['name'], 'unit' => $r['unit'], 'served' => $r['served_cell'], 'category' => (string)($r['group_label'] ?? '')]
                    + (!empty($f['menu']) ? ['ask_menu' => true] : []);
                $allowed[$r['key']] = $f;
            }
        }
        if (!$ask) {
            return 0;
        }
        // claim the one call for this import under the job's row lock BEFORE asking, so two loads at once never both pay for a call
        $claimed = false;
        $cl = import_cont_save($pdo, $jobId, '', function (array &$a) use (&$claimed) {
            $claimed = empty($a['_sizes']['ai_done']);
            $a['_sizes']['ai_done'] = 1;
            return ['changed' => $claimed ? 1 : 0];
        });
        if (empty($cl['ok']) || !$claimed) {
            return 0;
        }
        $got = import_measure_ai_merge($ask, $allowed, $fn);
        // #10: a column reading is kept only when the hotel's words, the heading and the column's values all agree with it (else dropped)
        $colGot = [];
        foreach ($colByKey as $ck => $cq) {
            $s = $got[$ck]['column'] ?? null;
            unset($got[$ck]);
            $chk = is_array($s) ? import_col_ai_check((array)$s['v'], ['header' => $cq['column'], 'samples' => $cq['samples']], $colAliases) : null;
            if ($chk !== null) {
                $colGot[$cq['ref']] = $chk + ['src' => (string)$s['src'], 'at' => date('Y-m-d H:i:s')];
            }
        }
        $n = count($colGot);
        foreach ($got as $f) {
            $n += count($f);
        }
        $res = import_cont_save($pdo, $jobId, '', function (array &$a) use ($ask, $got, $colByKey, $colGot) {
            foreach ($ask as $row) {
                if (isset($colByKey[$row['key']])) {
                    $a['_cols_ai_asked'][$colByKey[$row['key']]['ref']] = 1;
                    continue;
                }
                $a['_sizes']['ai_asked'][$row['key']] = 1;
            }
            foreach ($got as $k => $fields) {
                foreach ($fields as $field => $s) {
                    $a['_sizes']['ai'][$k][$field] = $s;
                }
            }
            foreach ($colGot as $ref => $s) {
                $a['_cols_ai'][$ref] = $s;          // shown as "Suggested: ..." in step 2; used only by a person's "Use this"
            }
            return ['changed' => 1];
        });
        return !empty($res['ok']) ? $n : 0;
    }

    /**
     * Set or clear a size: mode group (group key) | row (item key); field ml (the bottle / container size) | glass (the glass size) |
     * shot (the shot size, owner 2026-11-21) | served (row only, value '' = clear the "served by" read for it). value '' = blank (cleared; on a
     * row it also clears what was read for it, so the blank stays; a shot row with its own answer falls back to the next size instead).
     * Validated as the item form does (rh_drink_volume_from_input). Odd sizes are warned about, never refused; only a shot bigger than the
     * bottle it would be used for is refused. A shot size never changes the opening count, a count measure or the Drink measures list.
     * A ticked drink that can no longer go on the menu after the change is unticked in the same save.
     */
    function import_job_sizes_set(PDO $pdo, int $jobId, int $userId, array $p): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state || !in_array($state['job']['status'], ['analysed', 'reviewing'], true)) {
            return ['ok' => false, 'error' => 'That import cannot be changed.'];
        }
        $mode = (string)($p['mode'] ?? '');
        $field = (string)($p['field'] ?? 'ml');
        $key = mb_substr((string)($p['key'] ?? ''), 0, 200);
        if (!in_array($mode, ['group', 'row'], true) || !in_array($field, ['ml', 'glass', 'shot', 'served', 'sold'], true) || (in_array($field, ['served', 'sold'], true) && $mode !== 'row')) {
            return ['ok' => false, 'error' => 'Unknown change.'];
        }
        if ($field === 'sold') {
            // the person's "Sold as" for one row (value '' = back to the file's own cell); menu side only, never the opening count
            return import_job_sizes_bulk($pdo, $jobId, $userId, ['keys' => [$key], 'sold' => (string)($p['value'] ?? '') === '' ? 'file' : (string)$p['value'], 'one' => true]);
        }
        $raw = mb_substr((string)($p['value'] ?? ''), 0, 20);
        if ($field === 'served' && trim($raw) !== '') {
            return ['ok' => false, 'error' => 'Unknown change.'];
        }
        [$ml, $err] = $field === 'served' ? [null, null] : rh_drink_volume_from_input($raw);
        if ($err !== null) {
            return ['ok' => false, 'error' => $field === 'glass' ? str_replace('Bottle size', 'Glass size', $err) : ($field === 'shot' ? 'Shot size must be a number of ml above 0, for example 25.' : $err)];
        }
        $items = (array)$state['res']['items'];
        $b = import_drinks_build($pdo, $items, (array)$state['answers']);
        $known = false;
        $saleMl = 0.0;
        $contMl = null;
        $word = 'bottle';
        $shotWord = 'shot';
        $bottleMin = null;      // shot: the smallest bottle the new size would be used for (a shot can never be more than one bottle)
        $bottleName = '';
        foreach ($b['rows'] as $r) {
            $hit = ($mode === 'group' && !empty($r['size_need']) && $r['size_group'] === $key) || ($mode === 'row' && $r['key'] === $key);
            if (!$hit || ($field === 'ml' && empty($r['cont_need'])) || ($field === 'glass' && empty($r['glass_need'])) || ($field === 'shot' && empty($r['shot_need']))
                || ($field === 'served' && $r['measure_from'] !== 'ai')) {
                continue;
            }
            $known = true;
            $word = (string)($r['size_word'] ?? 'bottle');
            if ($field === 'ml') {
                $saleMl = max($saleMl, (float)($r['size_sale_ml'] ?? 0));
            } elseif ($field === 'shot') {
                $shotWord = $r['shot_list_name'] !== '' ? mb_strtolower((string)$r['shot_list_name']) : 'shot';
                // a group answer is used only where no row answer, file cell or reading gives a size
                $uses = $mode === 'row' || in_array($r['shot_from'], ['group', 'count', 'list', ''], true);
                if ($uses && $r['size_bottle_ml'] !== null && ($bottleMin === null || (float)$r['size_bottle_ml'] < $bottleMin)) {
                    [$bottleMin, $bottleName] = [(float)$r['size_bottle_ml'], (string)$r['name']];
                }
            } elseif ($r['size_ml'] !== null && ($contMl === null || (float)$r['size_ml'] < $contMl)) {
                $contMl = (float)$r['size_ml'];
            }
        }
        if (!$known) {
            return ['ok' => false, 'error' => 'That drink or group is not in this list any more. Reload the list.'];
        }
        if ($field === 'shot' && $ml !== null && $bottleMin !== null && $ml > $bottleMin) {
            return ['ok' => false, 'error' => 'A ' . $shotWord . ' cannot hold more than the whole bottle: ' . import_measure_fmt((float)$ml) . ' ml is more than one bottle of "'
                . $bottleName . '" (' . import_measure_fmt($bottleMin) . ' ml). Type how many ml one ' . $shotWord . ' holds, for example 25.'];
        }
        // a warning only (never refused): a container smaller than one sale or under 50 ml, a glass bigger than its container or under
        // 30 ml, is most likely a typing slip ("75" for 750)
        $warn = '';
        $mlTxt = $ml !== null ? import_measure_fmt((float)$ml) . ' ml' : '';
        if ($field === 'ml' && $ml !== null && $saleMl > 0 && $ml < $saleMl) {
            $warn = 'Check this size: ' . $mlTxt . ' is less than one sale (' . import_measure_fmt($saleMl) . ' ml). It is kept; change it if it is a typing slip.';
        } elseif ($field === 'ml' && $ml !== null && $ml < 50) {
            $warn = 'Check this size: ' . $mlTxt . ' is very small for a ' . $word . '. It is kept; change it if it is a typing slip.';
        } elseif ($field === 'glass' && $ml !== null && $contMl !== null && $ml > $contMl) {
            $warn = 'Check this size: a ' . $mlTxt . ' glass is more than one ' . $word . ' (' . import_measure_fmt($contMl) . ' ml). It is kept; change it if it is a typing slip.';
        } elseif ($field === 'glass' && $ml !== null && $ml < 30) {
            $warn = 'Check this size: ' . $mlTxt . ' is very small for a glass. It is kept; change it if it is a typing slip.';
        } elseif ($field === 'shot' && $ml !== null && $ml < 10) {
            $warn = 'Check this size: ' . $mlTxt . ' is very small for one ' . $shotWord . '. It is kept; change it if it is a typing slip.';
        } elseif ($field === 'shot' && $ml !== null && $ml > 100) {
            $warn = 'Check this size: ' . $mlTxt . ' is a lot for one ' . $shotWord . '. It is kept; change it if it is a typing slip.';
        }
        $now = date('Y-m-d H:i:s');
        $aiField = ['ml' => 'container_ml', 'glass' => 'portion_ml', 'shot' => 'portion_ml', 'served' => 'served_by'][$field];
        return import_cont_save($pdo, $jobId, '', function (array &$answers) use ($pdo, $items, $mode, $field, $aiField, $key, $ml, $userId, $now) {
            $slot = $mode === 'group' ? 'g' : 'r';
            // a shot row answer that is cleared falls back to the next size (the file, a reading, the group, the list); only clearing a row
            // that has no answer of its own clears what was read for it
            $hadRowShot = $field === 'shot' && $mode === 'row' && isset($answers['_sizes']['r'][$key]['shot']);
            if ($field !== 'served') {
                $cur = is_array($answers['_sizes'][$slot][$key] ?? null) ? $answers['_sizes'][$slot][$key] : [];
                if ($ml === null) {
                    unset($cur[$field]);
                } else {
                    $cur[$field] = $ml;
                }
                if (isset($cur['ml']) || isset($cur['glass']) || isset($cur['shot']) || isset($cur['sold'])) {
                    $answers['_sizes'][$slot][$key] = ['by' => $userId > 0 ? $userId : null, 'at' => $now] + $cur;
                } else {
                    unset($answers['_sizes'][$slot][$key]);
                }
            }
            if ($mode === 'row' && $ml === null && !$hadRowShot) {
                unset($answers['_sizes']['ai'][$key][$aiField]);        // cleared on purpose: the read value is not put back
                if (empty($answers['_sizes']['ai'][$key])) {
                    unset($answers['_sizes']['ai'][$key]);
                }
            }
            $unticked = 0;
            foreach (import_drinks_build($pdo, $items, $answers)['rows'] as $r) {
                if (!$r['tickable'] && !empty($answers['_drinks'][$r['key']]['tick'])) {
                    $answers['_drinks'][$r['key']]['tick'] = 0;
                    $unticked++;
                }
            }
            return ['changed' => 1, 'unticked' => $unticked];
        }) + ['warning' => $warn];
    }

    /**
     * Change several drinks of the step 3 sizes list at once (owner 2026-11-21: "mass set like if default shot size is 30ml and you have a lot of
     * bottles to bundle or even change within whether by glass or shot"). $p: keys (item keys, at most 5000) or all = 1 with q / group / show
     * (every drink the list shows now, on every page); then the values, each optional (blank = left as it is): sold (a "Sold as" choice, or
     * 'file' = back to the file's own cell), ml (bottle / container size), glass, shot (ml). Applied as row answers in ONE save, in this order:
     * Sold as, then the bottle size, then the glass and shot sizes (so a drink changed to "glass" takes the glass size given with it). Each value
     * is checked as the one-row save checks it: text is refused for all (nothing changed); a shot bigger than that drink's bottle is refused for
     * that drink, which is then left exactly as it was (skipped, with the reason); odd sizes are kept with a warning. A size a drink does not
     * use (a glass size on a shot drink) is not put on it. A ticked drink that can no longer go on the menu is unticked in the same save.
     * Menu side only: the opening count, count measures and the Drink measures list are never changed.
     * @return array{ok:bool, changed?:int, same?:int, skipped?:int, reasons?:array, warnings?:array, unticked?:int, error?:string}
     */
    function import_job_sizes_bulk(PDO $pdo, int $jobId, int $userId, array $p): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state || !in_array($state['job']['status'], ['analysed', 'reviewing'], true)) {
            return ['ok' => false, 'error' => 'That import cannot be changed.'];
        }
        $vals = [];
        foreach (['ml' => ['Bottle or container size', '750'], 'glass' => ['Glass size', '250'], 'shot' => ['Shot size', '25']] as $f => $lab) {
            $raw = trim(mb_substr((string)($p[$f] ?? ''), 0, 20));
            if ($raw === '') {
                continue;
            }
            [$ml, $err] = rh_drink_volume_from_input(preg_replace('/\s*ml$/i', '', $raw));
            if ($err !== null || $ml === null) {
                return ['ok' => false, 'error' => $lab[0] . ' must be a number of ml above 0, for example ' . $lab[1] . '. Nothing was changed.'];
            }
            $vals[$f] = (float)$ml;
        }
        $sold = trim(mb_substr((string)($p['sold'] ?? ''), 0, 120));
        if ($sold === '' && !$vals) {
            return ['ok' => false, 'error' => 'Choose how they are sold, or type a size, first. Nothing was changed.'];
        }
        $aliases = import_load_aliases($pdo);
        $opts = import_drinks_sold_options(function_exists('rh_drink_measures_list') ? rh_drink_measures_list() : [],
            import_word_set((array)($aliases['shot_words'] ?? [])), import_word_set((array)($aliases['glass_words'] ?? [])));
        if ($sold !== '' && $sold !== 'file' && import_drinks_sold_served($sold, 'unit', $opts) === null) {
            return ['ok' => false, 'error' => 'That "Sold as" choice is not on the list any more. Reload the list. Nothing was changed.'];
        }
        $items = (array)$state['res']['items'];
        $allRows = import_drinks_build($pdo, $items, (array)$state['answers'])['rows'];
        $all = import_sizes_all($allRows);
        if (!empty($p['all'])) {
            $keys = array_column(import_sizes_filter($all, $p), 'key');
        } else {
            $keys = [];
            foreach ((array)($p['keys'] ?? []) as $k) {
                if (is_string($k) && $k !== '' && strlen($k) <= 200) {
                    $keys[$k] = true;
                }
            }
            $keys = array_slice(array_keys($keys), 0, 5000);
        }
        if (!$keys) {
            return ['ok' => false, 'error' => 'Tick at least one drink first. Nothing was changed.'];
        }
        // any row of "Put items on the menu" can take a Sold as answer or a size it uses (a size it does not use is skipped with the reason)
        if (!empty($p['one']) && !in_array($keys[0], array_column($allRows, 'key'), true)) {
            return ['ok' => false, 'error' => 'That drink is not in this list any more. Reload the list.'];
        }
        $now = date('Y-m-d H:i:s');
        return import_cont_save($pdo, $jobId, '', function (array &$answers) use ($pdo, $items, $keys, $vals, $sold, $userId, $now) {
            $byKey = function () use ($pdo, $items, &$answers): array {
                $o = [];
                foreach (import_drinks_build($pdo, $items, $answers)['rows'] as $r) {
                    $o[(string)$r['key']] = $r;
                }
                return $o;
            };
            $put = function (string $k, string $f, $v) use (&$answers, $userId, $now) {
                $cur = is_array($answers['_sizes']['r'][$k] ?? null) ? $answers['_sizes']['r'][$k] : [];
                if ($v === null) {
                    unset($cur[$f]);
                } else {
                    $cur[$f] = $v;
                }
                if (isset($cur['ml']) || isset($cur['glass']) || isset($cur['shot']) || isset($cur['sold'])) {
                    $answers['_sizes']['r'][$k] = ['by' => $userId > 0 ? $userId : null, 'at' => $now] + $cur;
                } else {
                    unset($answers['_sizes']['r'][$k]);
                }
            };
            $strip = function ($a): array {
                return is_array($a) ? array_diff_key($a, ['by' => 1, 'at' => 1]) : [];
            };
            $inList = array_flip(array_keys($byKey()));
            $orig = [];
            $skip = [];         // key => why
            $applied = [];      // key => true when at least one value is for it
            $na = [];           // key => [what it does not use]
            $warns = [];
            foreach ($keys as $k) {
                if (!isset($inList[$k])) {
                    $skip[$k] = 'it is not in this list any more';
                    continue;
                }
                $orig[$k] = $answers['_sizes']['r'][$k] ?? null;
            }
            // 1) Sold as (menu side only)
            if ($sold !== '') {
                foreach (array_keys($orig) as $k) {
                    $put($k, 'sold', $sold === 'file' ? null : $sold);
                    $applied[$k] = true;
                }
            }
            // 2) the bottle / container size, for the drinks as they are sold now
            $by = $byKey();
            $name = function (string $k) use (&$by): string {
                return (string)($by[$k]['name'] ?? $k);
            };
            if (isset($vals['ml'])) {
                $ml = $vals['ml'];
                foreach (array_keys($orig) as $k) {
                    $r = $by[$k] ?? null;
                    if (!$r || empty($r['cont_need'])) {
                        $na[$k][] = ($r['size_from'] ?? '') === 'own' ? 'its size is saved on the stock item' : 'it needs no bottle size';
                        continue;
                    }
                    $put($k, 'ml', $ml);
                    $applied[$k] = true;
                    $saleMl = (float)($r['size_sale_ml'] ?? 0);
                    if ($saleMl > 0 && $ml < $saleMl) {
                        $warns[] = '"' . $name($k) . '": ' . import_measure_fmt($ml) . ' ml is less than one sale (' . import_measure_fmt($saleMl) . ' ml)';
                    } elseif ($ml < 50) {
                        $warns[] = '"' . $name($k) . '": ' . import_measure_fmt($ml) . ' ml is very small for a ' . (string)($r['size_word'] ?? 'bottle');
                    }
                }
                $by = $byKey();
            }
            // 3) the glass and shot sizes
            $fail = [];
            foreach (array_keys($orig) as $k) {
                $r = $by[$k] ?? null;
                if (isset($vals['glass'])) {
                    $g = $vals['glass'];
                    if (!$r || empty($r['glass_need'])) {
                        $na[$k][] = 'it is not sold by the glass';
                    } else {
                        $put($k, 'glass', $g);
                        $applied[$k] = true;
                        $contMl = $r['size_ml'] !== null ? (float)$r['size_ml'] : null;
                        if ($contMl !== null && $g > $contMl) {
                            $warns[] = '"' . $name($k) . '": a ' . import_measure_fmt($g) . ' ml glass is more than one ' . (string)($r['size_word'] ?? 'bottle') . ' (' . import_measure_fmt($contMl) . ' ml)';
                        } elseif ($g < 30) {
                            $warns[] = '"' . $name($k) . '": ' . import_measure_fmt($g) . ' ml is very small for a glass';
                        }
                    }
                }
                if (isset($vals['shot'])) {
                    $s = $vals['shot'];
                    $word = $r && (string)($r['shot_list_name'] ?? '') !== '' ? mb_strtolower((string)$r['shot_list_name']) : 'shot';
                    if (!$r || empty($r['shot_need'])) {
                        $na[$k][] = 'it is not sold by the shot';
                    } elseif ($r['size_bottle_ml'] !== null && $s > (float)$r['size_bottle_ml']) {
                        $fail[$k] = 'a ' . import_measure_fmt($s) . ' ml ' . $word . ' is more than one whole bottle (' . import_measure_fmt((float)$r['size_bottle_ml']) . ' ml)';
                    } else {
                        $put($k, 'shot', $s);
                        $applied[$k] = true;
                        if ($s < 10) {
                            $warns[] = '"' . $name($k) . '": ' . import_measure_fmt($s) . ' ml is very small for one ' . $word;
                        } elseif ($s > 100) {
                            $warns[] = '"' . $name($k) . '": ' . import_measure_fmt($s) . ' ml is a lot for one ' . $word;
                        }
                    }
                }
            }
            // a drink with a refused value, or with nothing it uses, is left exactly as it was
            $changed = $same = 0;
            foreach ($orig as $k => $o) {
                $why = $fail[$k] ?? (empty($applied[$k]) ? implode(', ', array_unique($na[$k] ?? ['nothing to change'])) : '');
                if ($why !== '') {
                    if ($o === null) {
                        unset($answers['_sizes']['r'][$k]);
                    } else {
                        $answers['_sizes']['r'][$k] = $o;
                    }
                    $skip[$k] = $why;
                    continue;
                }
                if ($strip($answers['_sizes']['r'][$k] ?? null) == $strip($o)) {
                    if ($o === null) {
                        unset($answers['_sizes']['r'][$k]);
                    } else {
                        $answers['_sizes']['r'][$k] = $o;     // nothing new: who / when stay as they were
                    }
                    $same++;
                } else {
                    $changed++;
                }
            }
            if (isset($answers['_sizes']['r']) && !$answers['_sizes']['r']) {
                unset($answers['_sizes']['r']);
            }
            $reasons = [];
            foreach ($skip as $k => $why) {
                $reasons[$why][] = $name((string)$k);
            }
            $unticked = 0;
            foreach (import_drinks_build($pdo, $items, $answers)['rows'] as $r) {
                if (!$r['tickable'] && !empty($answers['_drinks'][$r['key']]['tick'])) {
                    $answers['_drinks'][$r['key']]['tick'] = 0;
                    $unticked++;
                }
            }
            return ['changed' => $changed, 'same' => $same, 'skipped' => count($skip),
                'reasons' => array_map(function ($why, $names) {
                    return ['why' => $why, 'names' => array_slice($names, 0, 20), 'n' => count($names)];
                }, array_keys($reasons), array_values($reasons)),
                'warnings' => array_slice($warns, 0, 20), 'warnings_n' => count($warns), 'unticked' => $unticked];
        });
    }

    /**
     * At import (inside its transaction, after the stock part, before the drinks): save each bottle size a person gave in step 3 on its
     * stock item (rh_drink_volume_save: logged old -> new; stock quantity, cost and recipes untouched). A size the item already has is
     * never replaced; a size read from the item's name is not written (it is already known from the name).
     * @return array{saved:int, names:array}
     */
    function import_drinks_sizes_commit(PDO $pdo, array $items, array $answers, int $jobId, int $userId): array
    {
        $out = ['saved' => 0, 'names' => []];
        if (empty($answers['_sizes']) || !function_exists('rh_stock_drink_ready') || !rh_stock_drink_ready($pdo)) {
            return $out;
        }
        $find = $pdo->prepare('SELECT id FROM stock_ingredients WHERE name = ? ORDER BY id DESC LIMIT 1');
        $cur = $pdo->prepare('SELECT volume_ml FROM stock_ingredients WHERE id = ? FOR UPDATE');
        foreach (import_drinks_build($pdo, $items, $answers)['rows'] as $r) {
            // typed in step 3 (row / group), read from the unit cell ("Carton 1 L") or a checked reading the person kept: saved on the item so
            // the menu line keeps working after the import; a size from the name is known from the name and not written
            // (a size only read, never typed, is written only for a drink ticked for the menu: the one that needs it)
            if (empty($r['size_need']) || empty($r['cont_need']) || $r['size_ml'] === null || !in_array($r['size_from'], ['row', 'group', 'cell', 'ai', 'file'], true)
                || (in_array($r['size_from'], ['cell', 'ai', 'file'], true) && empty($answers['_drinks'][$r['key']]['tick']))) {
                continue;
            }
            $ingId = (int)$r['ing_id'];
            if ($ingId <= 0) {
                $find->execute([$r['name']]);
                $ingId = (int)$find->fetchColumn();
            }
            if ($ingId <= 0) {
                throw new RuntimeException('"' . $r['name'] . '": its stock item was not found, so its bottle size could not be saved.');
            }
            $cur->execute([$ingId]);
            $v = $cur->fetchColumn();
            if ($v !== false && $v !== null && (float)$v > 0) {
                continue;               // the item has a size already: never replaced by the import
            }
            if (rh_drink_volume_save($pdo, $ingId, (float)$r['size_ml'], $userId, 'Import #' . $jobId)) {
                $out['saved']++;
                $out['names'][] = $r['name'];
            }
        }
        return $out;
    }

    /** One line of the stock link: one measure of the stock item (in the measure itself when the hotel's database can keep it). */
    function import_drinks_link_line(int $ingId, array $link, bool $drinkReady): array
    {
        if ($link['kind'] === 'measure' && $drinkReady) {
            return ['ingredient_id' => $ingId, 'quantity' => 1, 'unit' => 'measure', 'measure' => (string)$link['name']];
        }
        if ($link['kind'] === 'ml' && $drinkReady && isset($link['ml']) && (float)$link['ml'] > 0) {
            return ['ingredient_id' => $ingId, 'quantity' => (float)$link['ml'], 'unit' => 'measure', 'measure' => 'ml'];   // "25 ml" a sale
        }
        return ['ingredient_id' => $ingId, 'quantity' => (float)$link['qty'], 'unit' => 'base'];
    }

    /**
     * Write ONE drink to the drinks menu, its till row and (when it has none) its one-measure stock link: the writes import_drinks_commit
     * makes for a ticked row, shared with Stock Items > Put on the menu (admin/includes/stock-to-menu-lib.php) so both write the same way.
     * The caller holds the transaction and the menu lock and has checked the Menu permission. $r: action 'add' | 'update', name, price
     * (VAT included, written exactly to 2 dp), category (add only; an update keeps the drink's own), measure (words), menu_id + menu_name
     * (update), link ({kind, qty, name, ml}) for a new stock link. $priceNote goes in the log text ("VAT included" ...). $stopped ends the
     * missing-stock-item error: none by default (the import's commit already starts it with "Nothing was imported: "); Put on the menu adds its own "Nothing was saved.".
     * @return array{action:string, linked:bool, what:string, pos:array, menu_id:int, old_price:?string, price:string}
     * @throws RuntimeException
     */
    function import_drinks_menu_write(PDO $pdo, array $r, int $ingId, int $userId, bool $drinkReady, string $priceNote, string $stopped = ''): array
    {
        static $hasLink = null, $hasLinkPdo = null;
        if ($hasLink === null || $hasLinkPdo !== $pdo) {
            $hasLink = $pdo->prepare('SELECT COUNT(*) FROM stock_recipes sr JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id WHERE sr.menu_item_id = ? AND sr.menu_type = ?');
            $hasLinkPdo = $pdo;
        }
        $linked = false;
        $oldPrice = null;
        $menuId = 0;
        $price = number_format((float)$r['price'], 2, '.', '');
        // which menu (2026-11-22): 'food' = the food menu (station kitchen), else the drinks menu (station bar), as Menu Management writes them
        $food = ($r['menu'] ?? 'drink') === 'food';
        $table = $food ? 'food_menu' : 'drink_menu';
        $menuWord = $food ? 'food menu' : 'drinks menu';
        if ($r['action'] === 'add') {
            $category = $r['category'];
            if ($category === '' || mb_strlen($category) > 100) {
                throw new RuntimeException('"' . $r['name'] . '": its menu category is missing or too long.');
            }
            $dup = rh_find_duplicate($pdo, $table, 'item_name', $r['name'], ['category' => $category]);
            if ($dup) {
                throw new RuntimeException('An item called "' . $dup['value'] . '" is already in "' . $category . '" on the ' . $menuWord . '. Please review the menu list again.');
            }
            $ord = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 FROM `$table` WHERE category = ?");
            $ord->execute([$category]);
            $displayOrder = (int)$ord->fetchColumn();
            if ($food) {
                $pdo->prepare('INSERT INTO food_menu (item_name, description, price, category, is_available, display_order, station, show_pos, show_room_service) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$r['name'], '', $price, $category, 1, $displayOrder, 'kitchen', 1, 1]);
            } else {
                $pdo->prepare('INSERT INTO drink_menu (item_name, description, price, category, is_available, display_order, tags, station, show_pos, show_room_service) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$r['name'], '', $price, $category, 1, $displayOrder, '', 'bar', 1, 1]);
            }
            $menuId = (int)$pdo->lastInsertId();
            $pos = rh_menu_pos_sync_item($pdo, $food ? 'food' : 'drink', [
                'item_name' => $r['name'], 'description' => '', 'price' => $price, 'category' => $category, 'station' => $food ? 'kitchen' : 'bar',
                'is_available' => 1, 'display_order' => $displayOrder, 'show_pos' => 1, 'show_room_service' => 1, 'tags' => '',
            ], null, null);
            $what = 'added "' . $r['name'] . '" to the ' . $menuWord . ' (' . $category . ') at ' . $price . ' per ' . $r['measure'] . ' (' . $priceNote . ')';
        } else {
            $st = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? FOR UPDATE");
            $st->execute([$r['menu_id']]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) {
                throw new RuntimeException('"' . $r['menu_name'] . '" is no longer on the ' . $menuWord . '. Please review the menu list again.');
            }
            $oldPrice = number_format((float)$cur['price'], 2, '.', '');
            $pdo->prepare("UPDATE `$table` SET price = ? WHERE id = ?")->execute([$price, (int)$cur['id']]);
            // the till's copy, exactly as Menu Management does on an edit that changes only the price: same name and category (so an
            // item on an open order is never moved), its availability left as the till has it
            $pos = rh_menu_pos_sync_item($pdo, $food ? 'food' : 'drink', [
                'item_name' => (string)$cur['item_name'], 'description' => (string)($cur['description'] ?? ''), 'price' => $price, 'category' => (string)$cur['category'],
                'station' => (string)($cur['station'] ?? ($food ? 'kitchen' : 'bar')), 'is_available' => null, 'display_order' => (int)$cur['display_order'],
                'show_pos' => (int)$cur['show_pos'], 'show_room_service' => (int)$cur['show_room_service'], 'tags' => (string)($cur['tags'] ?? ''),
            ], ['item_name' => (string)$cur['item_name'], 'category' => (string)$cur['category']], null);
            $menuId = (int)$cur['id'];
            $what = 'changed the price of "' . rh_clean_text((string)$cur['item_name']) . '" (' . rh_clean_text((string)$cur['category']) . ', ' . $menuWord . ') from ' . $oldPrice . ' to ' . $price
                . ' (' . $priceNote . ')';
        }
        // the stock link: one measure per sale, only when the drink has none (an existing link, maybe with more lines, is never replaced)
        $hasLink->execute([$pos['id'], $pos['slug']]);
        if ((int)$hasLink->fetchColumn() === 0) {
            if ($ingId <= 0 || !$r['link']) {
                throw new RuntimeException('"' . $r['name'] . '": its stock item was not found, so its stock link could not be made.' . ($stopped !== '' ? ' ' . $stopped : ''));
            }
            rh_menu_recipe_save($pdo, (int)$pos['id'], (string)$pos['slug'], null, null, [import_drinks_link_line($ingId, $r['link'], $drinkReady)], $userId > 0 ? $userId : null);
            $linked = true;
            $what .= '; each sale takes ' . $r['measure'] . ' off stock';
        }
        return ['action' => $r['action'] === 'add' ? 'add' : 'update', 'linked' => $linked, 'what' => $what, 'pos' => $pos, 'menu_id' => $menuId, 'old_price' => $oldPrice, 'price' => $price];
    }

    /**
     * Write the ticked drinks. The caller (import_commit_stock_locked) holds the transaction, the import lock and the menu lock, and has
     * already applied the stock part (so a new stock item exists). Everything is re-read here; a ticked row that is no longer tickable stops
     * the whole import with a plain message. Same writes as Menu Management: drink_menu, then its till row (rh_menu_pos_sync_item), then
     * the stock link (rh_menu_recipe_save) only when the drink has none yet (an existing link is never replaced).
     * @return array{added:int, updated:int, linked:int, names:array}
     * @throws RuntimeException
     */
    function import_drinks_commit(PDO $pdo, array $items, array $answers, int $jobId, int $userId): array
    {
        $out = ['added' => 0, 'updated' => 0, 'linked' => 0, 'names' => [], 'food_added' => 0, 'food_updated' => 0, 'rows' => []];
        if (!import_drinks_any_ticked($answers)) {
            return $out;
        }
        if (!import_drinks_can_menu($userId)) {
            throw new RuntimeException('items are ticked for the menu, and your account cannot change the menu (it needs the Menu permission). Untick them or ask a manager who has it.');
        }
        $b = import_drinks_build($pdo, $items, $answers);
        $drinkReady = function_exists('rh_stock_drink_ready') && rh_stock_drink_ready($pdo);
        $ticked = array_filter((array)($answers['_drinks'] ?? []), function ($a) {
            return is_array($a) && !empty($a['tick']);
        });
        $byKey = [];
        foreach ($b['rows'] as $r) {
            $byKey[$r['key']] = $r;
        }
        foreach (array_keys($ticked) as $k) {
            $r = $byKey[(string)$k] ?? null;
            if (!$r) {
                continue;               // the item left the list (skipped since): its tick means nothing now
            }
            if (!$r['tickable']) {
                throw new RuntimeException('"' . $r['name'] . '" is ticked for the menu but can no longer go on it: ' . ($r['why_not'] !== '' ? $r['why_not'] : 'please review it again.'));
            }
        }
        $find = $pdo->prepare('SELECT id FROM stock_ingredients WHERE name = ? ORDER BY id DESC LIMIT 1');
        foreach ($b['rows'] as $r) {
            if (!$r['ticked']) {
                continue;
            }
            $ingId = $r['ing_id'];
            if ($ingId <= 0) {
                $find->execute([$r['name']]);
                $ingId = (int)$find->fetchColumn();
            }
            $note = ($r['price_from'] ?? '') === 'typed'
                ? 'selling price typed in step 3' . ($r['file_price'] !== null ? ' (file: ' . number_format((float)$r['file_price'], 2, '.', '') . ')' : '') . ', VAT included'
                : 'selling price from ' . $r['price_source'] . ', VAT included';
            $w = import_drinks_menu_write($pdo, $r, $ingId, $userId, $drinkReady, $note);
            $out[$w['action'] === 'add' ? 'added' : 'updated']++;
            if (($r['menu'] ?? '') === 'food') {
                $out[$w['action'] === 'add' ? 'food_added' : 'food_updated']++;
            }
            if ($w['linked']) {
                $out['linked']++;
            }
            $what = $w['what'];
            $out['names'][] = $r['name'];
            // what was written, for the "Differences from the uploaded file" report (commit-stock.php): the file's price vs the one used
            $out['rows'][] = ['name' => (string)$r['name'], 'menu' => (string)($r['menu'] ?? ''), 'action' => (string)$w['action'], 'price' => $w['price'] ?? $r['price'],
                'price_from' => (string)($r['price_from'] ?? ''), 'file_price' => $r['file_price'] ?? null, 'price_source' => (string)($r['price_source'] ?? ''),
                'old_price' => $w['old_price'] ?? null, 'category' => (string)($r['category'] ?? '')];
            if (function_exists('logActivity')) {
                logActivity($userId, 'drink_menu_import', 'Import #' . $jobId . ': ' . $what . '.');
            }
        }
        return $out;
    }
}
