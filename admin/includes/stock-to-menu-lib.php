<?php
/**
 * Put stock items on the drinks menu (owner 2026-11-22: "add menu items from stock imported in the simplest way").
 * Used by admin/stock-to-menu.php (opened from Stock Items > Put on the menu, and Menu Management > Drinks > Add from stock).
 * Tested by scripts/tests/stock_to_menu_test.php.
 *
 * One row per stock item: drink name (default the stock name), "Sold as" (one stock unit, or a measure the item can be
 * measured in: its own count measure, the hotel's Drink measures list when the item has a bottle size), price (VAT INCLUDED,
 * written exactly, 2 dp, > 0), category (an existing drinks menu category or "Bar"). Already on the drinks menu (its stock link
 * first, then the same name in any category: the same match as Import step 4) = a price change that keeps the drink's category
 * and stock link. The writes are Import step 4's own (import_drinks_menu_write in includes/import/drinks.php): drinks menu row,
 * till copy, a one-measure stock link only when the drink has none. One transaction, under the menu lock Menu Management takes,
 * Menu permission required, every change logged (who, what, old -> new price). Cost over price warns, never blocks.
 *
 *   stm_can_menu($uid)                   Menu permission
 *   stm_can_see_cost($uid)               Stock permission (stock_management): cost of one, profit and the cost warning are only for them
 *   stm_parse_ids($raw)                  "1,2,3" from Stock Items -> unique ids; over STM_MAX_ITEMS is said, never cut
 *   stm_items($pdo, ?array $ids)          stock items to show (ids given: those; null: every item not archived and not kept by weight)
 *   stm_build($pdo, array $inputs, bool $showCost)   the rows as the screen shows them (nothing written; no costs unless $showCost)
 *   stm_save($pdo, int $uid, array $inputs)   writes the ticked rows that are ready; ['ok', 'error', 'added', 'updated', 'skipped']
 */

require_once dirname(__DIR__, 2) . '/includes/import/drinks.php';

if (!function_exists('stm_build')) {

    /** Most stock items the screen opens at once (Stock Items sends them in one field; more is refused with a message, never cut). */
    define('STM_MAX_ITEMS', 5000);
    define('STM_PRICE_COMMA_MSG', 'Use a dot for decimals, e.g. 1.50, and commas only between thousands, e.g. 1,500.');

    function stm_can_menu(int $userId): bool
    {
        return import_drinks_can_menu($userId);
    }

    /** Cost of one, profit and "costs more than its price" are stock costs: only for people with the Stock permission (as Stock Items). */
    function stm_can_see_cost(int $userId): bool
    {
        return $userId > 0 && function_exists('hasPermission') && hasPermission($userId, 'stock_management');
    }

    /**
     * The ticked ids from Stock Items: one comma-joined field (or a list). Whole numbers above 0, each once, order kept.
     * @return array{ids:int[], count:int, over:bool}  over = more than STM_MAX_ITEMS (ids is then empty: the caller says so)
     */
    function stm_parse_ids($raw): array
    {
        $parts = is_array($raw) ? $raw : explode(',', (string)$raw);
        $ids = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p !== '' && ctype_digit($p) && strlen($p) <= 10 && (int)$p > 0) {
                $ids[(int)$p] = true;
            }
        }
        $ids = array_keys($ids);
        $over = count($ids) > STM_MAX_ITEMS;
        return ['ids' => $over ? [] : $ids, 'count' => count($ids), 'over' => $over];
    }

    /** Stock items for the screen: the ids asked for (any state, so the screen can say why one cannot go), else every live drink-like item. */
    function stm_items(PDO $pdo, ?array $ids): array
    {
        $cols = 'id, name, category, unit, cost_per_unit, is_archived' . rh_drink_ing_cols('');
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) {
                return $v > 0;
            })));
            if (!$ids) {
                return [];
            }
            if (count($ids) > STM_MAX_ITEMS) {
                throw new InvalidArgumentException('More than ' . number_format(STM_MAX_ITEMS) . ' stock items were asked for. Tick fewer and try again.');
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT $cols FROM stock_ingredients WHERE id IN ($in) ORDER BY category, name");
            $st->execute($ids);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $out = [];
        foreach ($pdo->query("SELECT $cols FROM stock_ingredients WHERE is_archived = 0 ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!rh_drink_unit_is_weight((string)$r['unit'])) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** A comma that is not between thousands ("1,50", "15,0", "1,5000", "0,500"): refused with STM_PRICE_COMMA_MSG, never read as 150 / 500. */
    function stm_price_comma_wrong($raw): bool
    {
        $s = trim(str_replace(' ', '', (string)$raw));
        return strpos($s, ',') !== false && !preg_match('/^[1-9]\d{0,2}(,\d{3})+(\.\d{1,2})?$/', $s);
    }

    /** "1,500.50" / "12,500.50" / "1500" -> 1500.5 (2 dp); null when blank; false when not a price. Commas only as thousands groups. */
    function stm_parse_price($raw)
    {
        $s = trim(str_replace(' ', '', (string)$raw));
        if ($s === '') {
            return null;
        }
        if (strpos($s, ',') !== false) {
            if (stm_price_comma_wrong($s)) {
                return false;
            }
            $s = str_replace(',', '', $s);
        }
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $s)) {
            return false;
        }
        $v = round((float)$s, 2);
        return $v > 0 ? $v : false;
    }

    /** ml shown in a label: 25, 17.5. */
    function stm_fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /**
     * The "Sold as" choices for one item, each with what one sale takes off stock and costs. 'unit' (one stock unit) is always there
     * for a drink item; the rest only when the item can be measured in them. [v => [label, qty, cost, name, kind]] + a note when only
     * the unit is possible (what to set to sell it by the shot / glass).
     * @return array{opts:array, note:string}
     */
    function stm_sold_options(array $ing, array $list): array
    {
        $unit = trim((string)$ing['unit']);
        $opts = [];
        $add = function (string $v, string $label, ?array $mc) use (&$opts) {
            if ($mc !== null) {
                $opts[$v] = ['label' => $label, 'qty' => (float)$mc['qty'], 'cost' => $mc['cost'], 'name' => (string)$mc['name'], 'kind' => (string)$mc['kind']];
            }
        };
        $add('unit', '1 ' . ($unit !== '' ? $unit : 'unit'), rh_drink_measure_cost($ing, '', $list));
        $own = rh_stock_measure_get($ing);
        if ($own !== null && $own['side'] === 'small') {      // stock kept in shots: a whole bottle is N of them
            $add('big', '1 ' . $own['big'] . ' (' . stm_fmt((float)$own['n']) . ' ' . rh_drink_plural($own['small'], 2) . ')', rh_drink_measure_cost($ing, $own['big'], $list));
        }
        foreach (rh_drink_measure_options($ing, $list) as $k => $o) {
            if ($o['kind'] === 'ml' || $k === mb_strtolower($unit)) {
                continue;
            }
            $label = '1 ' . $o['name'] . ($o['kind'] === 'item'
                ? ' (' . stm_fmt((float)$o['n']) . ' in one ' . ($own['big'] ?? $unit) . ')'
                : ' (' . stm_fmt((float)$o['ml']) . ' ml)');
            $add('m:' . $o['name'], $label, rh_drink_measure_cost($ing, (string)$o['name'], $list));
        }
        $note = '';
        if (count($opts) === 1 && !rh_drink_unit_is_weight($unit)) {
            if (function_exists('rh_stock_unit_is_container') && rh_stock_unit_is_container($unit)) {
                $note = 'Only by the whole ' . $unit . '. To sell single bottles, set its count measure (bottles in one ' . $unit . ') in Stock Items.';
            } elseif (rh_drink_unit_ml($unit) === null) {
                $note = 'Only by the whole ' . $unit . '. To sell shots or glasses, set its size in ml in Stock Items.';
            }
        }
        return ['opts' => $opts, 'note' => $note];
    }

    /**
     * The rows as the screen shows them. $inputs: [{ing_id, name?, sold?, price?, category?, tick?}] (missing = the default).
     * $showCost: stm_can_see_cost() of the person. Without it cost, cost_note, profit, below and warn are left empty (never sent).
     * @return array{rows:array, cats:array}
     */
    function stm_build(PDO $pdo, array $inputs, bool $showCost = false): array
    {
        $byId = [];
        foreach ($inputs as $in) {
            $id = (int)($in['ing_id'] ?? 0);
            if ($id > 0 && !isset($byId[$id])) {
                $byId[$id] = is_array($in) ? $in : [];
            }
        }
        $ings = [];
        foreach (stm_items($pdo, array_keys($byId)) as $r) {
            $ings[(int)$r['id']] = $r;
        }
        $db = import_drinks_db($pdo, array_keys($ings));
        $list = function_exists('rh_drink_measures_list') ? rh_drink_measures_list() : [];
        $rows = [];
        foreach ($byId as $id => $in) {
            $ing = $ings[$id] ?? null;
            if ($ing === null) {
                continue;               // not a stock item (any more)
            }
            $unit = trim((string)$ing['unit']);
            $so = stm_sold_options($ing, $list);
            $opts = $so['opts'];
            $own = rh_stock_measure_get($ing);
            $soldDefault = ($own !== null && $own['side'] === 'big' && isset($opts['m:' . $own['small']])) ? 'm:' . $own['small'] : 'unit';
            $soldAsked = isset($in['sold']) && (string)$in['sold'] !== '' ? (string)$in['sold'] : '';
            $sold = $soldAsked !== '' ? $soldAsked : $soldDefault;
            $name = array_key_exists('name', $in) ? mb_substr(rh_clean_text((string)$in['name']), 0, 200) : mb_substr(rh_clean_text((string)$ing['name']), 0, 200);
            $priceRaw = (string)($in['price'] ?? '');
            $price = stm_parse_price($priceRaw);
            $why = '';
            if ((int)$ing['is_archived'] === 1) {
                $why = 'It is archived in Stock Items. Restore it first.';
            } elseif (rh_drink_unit_is_weight($unit)) {
                $why = 'It is kept by weight (' . $unit . '), so it is not a drink. Add food on the Menu page.';
            } elseif (!$opts) {
                $why = 'One sale of it cannot be worked out. Check its unit in Stock Items.';
            } elseif (!isset($opts[$sold])) {
                $what = strpos($sold, 'm:') === 0 ? substr($sold, 2) : $sold;
                $why = 'It cannot be sold by "' . $what . '". ' . ($so['note'] !== '' ? $so['note'] : 'Pick another "Sold as".');
            } elseif ($name === '') {
                $why = 'Type the drink name.';
            }
            // ---- already on the drinks menu? Its stock link first, then the same name in any category (Import step 4's match)
            $menuIds = [];
            foreach ($db['links'][$id] ?? [] as $lk) {
                foreach (import_drinks_menu_ids_for($db, $lk['name'], $lk['category']) as $mid) {
                    $menuIds[$mid] = true;
                }
            }
            if (!$menuIds && $name !== '') {
                foreach ($db['by_name'][mb_strtolower($name)] ?? [] as $mid) {
                    $menuIds[$mid] = true;
                }
            }
            $menuIds = array_keys($menuIds);
            $menu = null;
            if (count($menuIds) > 1) {
                if ($why === '') {
                    $names = array_map(function ($mid) use ($db) {
                        return '"' . rh_clean_text((string)$db['menu'][$mid]['item_name']) . '" in ' . rh_clean_text((string)$db['menu'][$mid]['category']);
                    }, array_slice($menuIds, 0, 3));
                    $why = 'More than one drink on the menu matches it (' . implode(', ', $names) . '). Change the right one on the Menu page.';
                }
            } elseif ($menuIds) {
                $menu = $db['menu'][$menuIds[0]];
            }
            // ---- category: the menu's own for a drink already on it; else typed, else the stock category when it is a drinks menu category, else "Bar"
            $stockCat = mb_strtolower(rh_clean_text((string)$ing['category']));
            $catDefault = $db['cats'][$stockCat] ?? ($db['cats'][mb_strtolower(import_drinks_default_category())] ?? import_drinks_default_category());
            $cat = isset($in['category']) && rh_clean_text((string)$in['category']) !== '' ? mb_substr(rh_clean_text((string)$in['category']), 0, 100) : $catDefault;
            $cat = $db['cats'][mb_strtolower($cat)] ?? $cat;        // "bar" joins an existing "Bar"
            $existingLink = null;
            if ($menu) {
                $cat = rh_clean_text((string)$menu['category']);
                $pos = rh_menu_pos_find($pdo, (string)$menu['item_name'], (string)$menu['category']);
                if ($pos) {
                    $lines = rh_menu_recipe_lines($pdo, (int)$pos['id'], (string)$pos['slug']);
                    if ($lines) {
                        $existingLink = rh_menu_cost_check($lines, $price ? (float)$price : 0.0)['cost'];
                    }
                }
            }
            $o = $opts[$sold] ?? null;
            $measure = $o ? $o['label'] : '';
            $cost = $existingLink !== null ? $existingLink : ($o['cost'] ?? null);
            $action = $why !== '' ? 'none' : ($menu ? (($price && abs((float)$menu['price'] - $price) <= BALANCE_TOLERANCE) ? 'same' : 'update') : 'add');
            if ($why === '' && $price === false) {
                $why = stm_price_comma_wrong($priceRaw) ? STM_PRICE_COMMA_MSG : 'The price must be a number above 0 with at most 2 decimals (VAT included).';
                $action = 'none';
            }
            $rows[] = [
                'ing_id' => $id, 'stock_name' => (string)$ing['name'], 'stock_category' => (string)$ing['category'], 'unit' => $unit,
                'name' => $menu ? rh_clean_text((string)$menu['item_name']) : $name, 'name_typed' => $name,
                'sold' => $sold, 'sold_options' => array_map(function ($v, $x) {
                    return ['v' => $v, 'label' => $x['label']];
                }, array_keys($opts), array_values($opts)),
                'sold_note' => $so['note'], 'sold_locked' => $existingLink !== null,
                'measure' => $measure,
                'link' => $o ? ['kind' => $o['kind'] === 'measure' ? 'measure' : 'unit', 'qty' => $o['qty'], 'name' => $o['name']] : null,
                'price' => is_float($price) ? $price : null, 'price_raw' => $priceRaw,
                'category' => $cat, 'category_locked' => (bool)$menu,
                'cost' => $cost !== null ? round((float)$cost, 2) : null, 'cost_note' => $cost === null ? 'Not known: the item has no cost yet.' : ($existingLink !== null ? 'From its stock link on the menu.' : ''),
                'profit' => ($cost !== null && is_float($price)) ? round($price - round((float)$cost, 2), 2) : null,     // shown money: cost to 2 dp, price - cost = profit on screen
                'below' => $cost !== null && is_float($price) && rh_menu_cost_over_price((float)$cost, $price),
                'action' => $action, 'menu_id' => $menu ? (int)$menu['id'] : 0, 'menu_name' => $menu ? rh_clean_text((string)$menu['item_name']) : '',
                'old_price' => $menu ? round((float)$menu['price'], 2) : null,
                'why_not' => $why, 'tick' => !empty($in['tick']),
            ];
        }
        // two ticked rows that end up as the same menu drink: neither goes (never a silent pick)
        $seen = [];
        foreach ($rows as $i => $r) {
            if ($r['tick'] && $r['why_not'] === '') {
                $seen[$r['menu_id'] > 0 ? 'm' . $r['menu_id'] : 'n' . mb_strtolower($r['name'])][] = $i;
            }
        }
        foreach ($seen as $idx) {
            if (count($idx) > 1) {
                foreach ($idx as $i) {
                    $rows[$i]['why_not'] = 'Another ticked row is the same drink on the menu. Untick one of them.';
                    $rows[$i]['action'] = 'none';
                }
            }
        }
        foreach ($rows as &$r) {
            $r['ready'] = in_array($r['action'], ['add', 'update'], true) && $r['price'] !== null && $r['why_not'] === '';
            if ($r['action'] === 'add') {
                $r['status'] = 'new';
                $r['status_text'] = $r['price'] === null ? 'New on the menu. Type the selling price.' : 'New on the menu';
            } elseif ($r['action'] === 'update') {
                $r['status'] = 'update';
                $r['status_text'] = $r['price'] === null
                    ? 'On the menu at ' . number_format((float)$r['old_price'], 2) . '. Type the new price.'
                    : 'Price change: ' . number_format((float)$r['old_price'], 2) . ' -> ' . number_format((float)$r['price'], 2);
            } elseif ($r['action'] === 'same') {
                $r['status'] = 'same';
                $r['status_text'] = 'Already on the menu at this price';
            } else {
                $r['status'] = 'blocked';
                $r['status_text'] = 'Cannot go on the menu: ' . $r['why_not'];
            }
            $r['warn'] = $r['below'] ? 'It costs more than its price. It can still be saved.' : '';
            if (!$showCost) {           // no Stock permission: no stock cost leaves the server
                $r['cost'] = null;
                $r['cost_note'] = '';
                $r['profit'] = null;
                $r['below'] = false;
                $r['warn'] = '';
            }
        }
        unset($r);
        $cats = array_values($db['cats']);
        if (!in_array(import_drinks_default_category(), $cats, true)) {
            $cats[] = import_drinks_default_category();
        }
        sort($cats, SORT_NATURAL | SORT_FLAG_CASE);
        return ['rows' => $rows, 'cats' => $cats];
    }

    /**
     * Write the ticked rows that are ready (others are listed as skipped with the reason). Takes the menu lock before the transaction
     * (so its reads see any menu save that finished first), re-reads everything inside it, then writes like Import step 4. Any write
     * error rolls the whole save back.
     * @return array{ok:bool, error:string, added:array, updated:array, skipped:array}
     */
    function stm_save(PDO $pdo, int $userId, array $inputs): array
    {
        $res = ['ok' => false, 'error' => '', 'added' => [], 'updated' => [], 'skipped' => []];
        if (!stm_can_menu($userId)) {
            $res['error'] = 'Your account cannot change the menu (it needs the Menu permission). Nothing was saved.';
            return $res;
        }
        $ticked = array_values(array_filter($inputs, function ($in) {
            return is_array($in) && !empty($in['tick']) && (int)($in['ing_id'] ?? 0) > 0;
        }));
        if (!$ticked) {
            $res['error'] = 'Tick at least one row. Nothing was saved.';
            return $res;
        }
        $lock = import_drinks_menu_lock_name();
        if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 10)')->fetchColumn() !== 1) {
            $res['error'] = 'Somebody is saving a menu item right now, so nothing was saved. Wait a few seconds and try again.';
            return $res;
        }
        try {
            $pdo->beginTransaction();
            try {
                $b = stm_build($pdo, $ticked);
                $drinkReady = function_exists('rh_stock_drink_ready') && rh_stock_drink_ready($pdo);
                foreach ($b['rows'] as $r) {
                    if (!$r['ready']) {
                        $res['skipped'][] = ['name' => $r['name'], 'why' => $r['status'] === 'same' ? 'Already on the menu at this price.' : ($r['why_not'] !== '' ? $r['why_not'] : $r['status_text'])];
                        continue;
                    }
                    $w = import_drinks_menu_write($pdo, $r, (int)$r['ing_id'], $userId, $drinkReady, 'VAT included', '');     // '': " Nothing was saved." is added below
                    if (function_exists('logActivity')) {
                        logActivity($userId, 'drink_menu_from_stock', 'Put on the menu from stock item "' . $r['stock_name'] . '": ' . $w['what'] . '.');
                    }
                    if ($w['action'] === 'add') {
                        $res['added'][] = ['name' => $r['name'], 'category' => $r['category'], 'price' => $w['price'], 'measure' => $r['measure']];
                    } else {
                        $res['updated'][] = ['name' => $r['name'], 'category' => $r['category'], 'old_price' => $w['old_price'], 'price' => $w['price']];
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('stock-to-menu save: ' . $e->getMessage());
                $res['error'] = ($e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'The menu could not be saved.') . ' Nothing was saved.';
                $res['added'] = $res['updated'] = $res['skipped'] = [];
                return $res;
            }
        } finally {
            try {
                $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
            } catch (Throwable $e) {
                // closing the connection releases it
            }
        }
        $res['ok'] = true;
        return $res;
    }
}
