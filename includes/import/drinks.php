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
 * Answers: answers_json['_drinks'][item key] = {tick, cat, by, at}. Nothing about the price is stored: it is read again from the file's
 * cells every time, and at import only the rows that are still tickable are written (import_drinks_commit, inside the import's
 * transaction). Ticking and importing ticked rows need the Menu permission as well as Import.
 *
 *   import_job_drinks_view($pdo, $jobId, $userId, $p)    the paged list (q, show, page, size)
 *   import_job_drinks_set($pdo, $jobId, $userId, $p)     mode tick (key, on) | category (key, value) | category_all (value)
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
        $db = ['menu' => [], 'by_name' => [], 'cats' => [], 'links' => [], 'ing' => [], 'drink_ready' => function_exists('rh_stock_drink_ready') && rh_stock_drink_ready($pdo)];
        foreach ($pdo->query('SELECT id, item_name, description, price, category, is_available, display_order, tags, station, show_pos, show_room_service FROM drink_menu ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $db['menu'][(int)$r['id']] = $r;
            $db['by_name'][mb_strtolower(rh_clean_text((string)$r['item_name']))][] = (int)$r['id'];
            $ck = mb_strtolower(rh_clean_text((string)$r['category']));
            if ($ck !== '' && !isset($db['cats'][$ck])) {
                $db['cats'][$ck] = rh_clean_text((string)$r['category']);
            }
        }
        $ingIds = array_values(array_unique(array_filter(array_map('intval', $ingIds))));
        if ($ingIds) {
            $in = implode(',', array_fill(0, count($ingIds), '?'));
            $st = $pdo->prepare('SELECT id, name, unit, cost_per_unit, is_archived' . rh_drink_ing_cols('') . " FROM stock_ingredients WHERE id IN ($in)");
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

    /**
     * One row per item of the file that has a selling price (ready items only; a weight unit is food and never listed).
     * Returns ['rows' => [...], 'waiting' => n items with a price that still need an answer in step 3, 'cats' => existing menu categories].
     */
    function import_drinks_build(PDO $pdo, array $items, array $answers): array
    {
        $ingIds = [];
        foreach ($items as $it) {
            if (!empty($it['sells']) && $it['match']) {
                $ingIds[] = (int)$it['match']['id'];
            }
        }
        $db = import_drinks_db($pdo, $ingIds);
        $list = function_exists('rh_drink_measures_list') ? rh_drink_measures_list() : [];
        $ans = (array)($answers['_drinks'] ?? []);
        $rows = [];
        $waiting = 0;
        foreach ($items as $it) {
            if (empty($it['sells'])) {
                continue;
            }
            if (($it['status'] ?? '') !== 'ready') {
                if (($it['status'] ?? '') !== 'skipped') {
                    $waiting++;
                }
                continue;
            }
            $ex = $it['match'] ? ($db['ing'][(int)$it['match']['id']] ?? null) : null;
            $unit = $ex ? (string)$ex['unit'] : (string)($it['unit'] ?? '');
            if ($unit === '' || rh_drink_unit_is_weight($unit)) {
                continue;               // food (kg, g ...) never goes on the drinks menu
            }
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
            if (!$prices && !$unknown) {
                continue;               // a blank or 0 selling price: nothing to offer
            }
            $price = $prices ? $prices[0]['v'] : null;
            $priceSrc = $prices ? $prices[0]['src'] : (string)($it['sells'][0]['source'] ?? '');
            $other = [];
            foreach ($prices as $pp) {
                if (abs($pp['v'] - (float)$price) > BALANCE_TOLERANCE) {
                    $other[] = import_fmt_money($pp['v']) . ' (' . $pp['src'] . ')';
                }
            }
            if ($price === null) {
                $why = 'The selling price cell is a formula with no saved value, so the price is not known. Open the file in Excel, save it, and import it again.';
            } elseif ($other) {
                $why = 'Your file gives two different selling prices: ' . import_fmt_money($price) . ' (' . $priceSrc . ') and ' . implode(', ', $other) . '. Fix the file, or set the price on the Menu page.';
            } elseif (preg_match('/\.\d{3,}$/', rtrim(rtrim(sprintf('%.6F', $price), '0'), '.'))) {
                // decimals counted on the written number (6 places, trailing zeros dropped), not by float arithmetic
                $why = 'The price ' . import_fmt_money($price) . ' has more than 2 decimal places, and menu prices keep 2. Fix the cell or set the price on the Menu page.';
            } elseif ($price > 99999999) {
                $why = 'The price is too large for the menu.';
            }
            // ---- one measure: what one sale is (the "served by" cell; none = one main unit) and its cost now
            $served = trim((string)($it['served_by'] ?? ''));
            if ($ex) {
                $ing = $ex;
                $ing['cost_per_unit'] = $it['line_cost'] ?? null;         // the cost after this import (a ticked cost change counts)
                if (function_exists('rh_stock_measure_get') && rh_stock_measure_get($ing) === null && !empty($it['measure_save'])) {
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
            $mc = rh_drink_measure_cost($ing, $served, $list);
            $measureLabel = $mc ? '1 ' . $mc['name'] : ($served !== '' ? '1 ' . $served : '1 ' . $unit);
            if ($mc === null && $why === '') {
                $why = 'Sold by "' . $served . '", which "' . (string)$it['name'] . '" cannot be measured in yet (set its bottle size or count measure in Stock). Add it on the Menu page after the import.';
            }
            $cost = $mc['cost'] ?? null;
            $profit = ($cost !== null && $price !== null) ? round($price - $cost, 4) : null;
            $below = $cost !== null && $price !== null && rh_menu_cost_over_price((float)$cost, (float)$price);
            // ---- already on the drinks menu? Its stock link first, then the same name in any category
            $menuIds = [];
            $how = '';
            if ($ex) {
                foreach ($db['links'][(int)$ex['id']] ?? [] as $lk) {
                    foreach (import_drinks_menu_ids_for($db, $lk['name'], $lk['category']) as $mid) {
                        $menuIds[$mid] = true;
                    }
                }
                $how = $menuIds ? 'link' : '';
            }
            if (!$menuIds) {
                foreach ([(string)$it['name'], (string)($it['typed_name'] ?? '')] as $nm) {
                    foreach ($db['by_name'][mb_strtolower(rh_clean_text($nm))] ?? [] as $mid) {
                        $menuIds[$mid] = true;
                    }
                }
                $how = $menuIds ? 'name' : '';
            }
            $menuIds = array_keys($menuIds);
            $menu = null;
            $action = 'add';
            if (count($menuIds) > 1) {
                $names = array_map(function ($id) use ($db) {
                    return '"' . rh_clean_text((string)$db['menu'][$id]['item_name']) . '" in ' . rh_clean_text((string)$db['menu'][$id]['category']);
                }, array_slice($menuIds, 0, 3));
                $action = 'none';
                if ($why === '') {
                    $why = 'More than one drink on the menu matches it (' . implode(', ', $names) . '). Change the right one on the Menu page.';
                }
            } elseif ($menuIds) {
                $menu = $db['menu'][$menuIds[0]];
                $action = ($price !== null && abs((float)$menu['price'] - $price) <= BALANCE_TOLERANCE) ? 'same' : 'update';
            }
            // ---- category: the menu's own (an update never moves a drink); else the answer, the sheet's category column, or "Bar"
            $catDefault = !empty($it['category_col']) && trim((string)$it['category']) !== '' ? rh_clean_text((string)$it['category']) : import_drinks_default_category();
            $a = is_array($ans[$it['key']] ?? null) ? $ans[$it['key']] : [];
            $cat = isset($a['cat']) && rh_clean_text((string)$a['cat']) !== '' ? rh_clean_text((string)$a['cat']) : $catDefault;
            if ($menu) {
                $cat = rh_clean_text((string)$menu['category']);
            } else {
                $cat = $db['cats'][mb_strtolower($cat)] ?? $cat;      // "bar" joins an existing "Bar" (no two spellings of one category)
            }
            $rows[] = [
                'key' => (string)$it['key'], 'name' => mb_substr(rh_clean_text((string)$it['name']), 0, 200), 'name_source' => (string)($it['name_source'] ?? ''),
                'ing_id' => $ex ? (int)$ex['id'] : 0,
                'price' => $price, 'price_source' => $priceSrc,
                'measure' => $measureLabel, 'measure_source' => $served !== '' ? (string)($it['served_by_source'] ?? '') : '',
                'measure_note' => $served === '' ? 'No "served by" cell: one ' . $unit . ' (the main unit).' : '',
                'link' => $mc ? ['kind' => $mc['kind'], 'qty' => $mc['qty'], 'name' => $mc['name']] : null,
                'cost' => $cost, 'profit' => $profit, 'below' => $below,
                'cost_note' => $mc === null ? 'Not known: the measure cannot be worked out.' : ($cost === null ? 'Not known: this item has no cost yet.' : ''),
                'category' => $cat, 'category_default' => $catDefault, 'category_from' => $menu ? 'menu' : (!empty($it['category_col']) ? 'column' : 'default'),
                'action' => $action, 'match_how' => $how,
                'menu_id' => $menu ? (int)$menu['id'] : 0, 'menu_name' => $menu ? rh_clean_text((string)$menu['item_name']) : '',
                'old_price' => $menu ? (float)$menu['price'] : null,
                'why_not' => $why, 'tickable' => $why === '' && in_array($action, ['add', 'update'], true),
                'ticked' => !empty($a['tick']),
            ];
        }
        // two rows of the file that end up as the same menu drink: neither is offered (never a silent pick)
        $seen = [];
        foreach ($rows as $i => $r) {
            $k = $r['menu_id'] > 0 ? 'm' . $r['menu_id'] : 'n' . mb_strtolower($r['name']);
            $seen[$k][] = $i;
        }
        foreach ($seen as $idxs) {
            if (count($idxs) > 1) {
                foreach ($idxs as $i) {
                    $rows[$i]['tickable'] = false;
                    if ($rows[$i]['why_not'] === '') {
                        $rows[$i]['why_not'] = 'Two rows of your file are the same drink on the menu. Skip one of them in step 3, or set the price on the Menu page.';
                    }
                }
            }
        }
        foreach ($rows as &$r) {
            if ($r['action'] === 'same' && $r['why_not'] === '') {
                $r['why_not'] = 'Already on the menu at this price. Nothing to change.';
            }
            $r['ticked'] = $r['ticked'] && $r['tickable'];
        }
        unset($r);
        $cats = array_values($db['cats']);
        sort($cats, SORT_NATURAL | SORT_FLAG_CASE);
        return ['rows' => $rows, 'waiting' => $waiting, 'cats' => $cats];
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
        $q = mb_strtolower(trim(mb_substr((string)($p['q'] ?? ''), 0, 100)));
        $show = in_array($p['show'] ?? '', ['ticked', 'below', 'add', 'update'], true) ? (string)$p['show'] : 'all';
        $rows = array_values(array_filter($all, function ($r) use ($q, $show) {
            if ($q !== '' && mb_strpos(mb_strtolower($r['name'] . ' ' . $r['category'] . ' ' . $r['price_source']), $q) === false) {
                return false;
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

    /** Tick / untick one drink, set its category, or set one category for every drink that would be added. Needs the Menu permission. */
    function import_job_drinks_set(PDO $pdo, int $jobId, int $userId, array $p): array
    {
        if (!import_drinks_can_menu($userId)) {
            return ['ok' => false, 'error' => 'Your account cannot change the menu (it needs the Menu permission), so drinks cannot be ticked here.'];
        }
        $state = import_job_state($pdo, $jobId);
        if (!$state || !in_array($state['job']['status'], ['analysed', 'reviewing'], true)) {
            return ['ok' => false, 'error' => 'That import cannot be changed.'];
        }
        $b = import_drinks_build($pdo, (array)$state['res']['items'], (array)$state['answers']);
        $byKey = [];
        foreach ($b['rows'] as $r) {
            $byKey[$r['key']] = $r;
        }
        $mode = (string)($p['mode'] ?? '');
        $key = (string)($p['key'] ?? '');
        $cat = rh_clean_text(mb_substr((string)($p['value'] ?? ''), 0, 200));
        if (in_array($mode, ['category', 'category_all'], true) && ($cat === '' || mb_strlen($cat) > 100)) {
            return ['ok' => false, 'error' => 'Type a category (100 characters at most).'];
        }
        if ($mode === 'tick' || $mode === 'category') {
            $r = $byKey[$key] ?? null;
            if (!$r) {
                return ['ok' => false, 'error' => 'That drink is not in this list any more. Reload the list.'];
            }
            if ($mode === 'tick' && !empty($p['on']) && !$r['tickable']) {
                return ['ok' => false, 'error' => $r['why_not'] !== '' ? $r['why_not'] : 'That drink cannot be ticked.'];
            }
            if ($mode === 'category' && $r['action'] !== 'add') {
                return ['ok' => false, 'error' => 'This drink is already on the menu, so it keeps its category. Change it on the Menu page.'];
            }
        } elseif ($mode === 'category_all') {
            $keys = [];
            foreach ($b['rows'] as $r) {
                if ($r['action'] === 'add') {
                    $keys[] = $r['key'];
                }
            }
            if (!$keys) {
                return ['ok' => false, 'error' => 'No drink in this list would be added, so there is no category to set.'];
            }
        } else {
            return ['ok' => false, 'error' => 'Unknown change.'];
        }
        $now = date('Y-m-d H:i:s');
        return import_cont_save($pdo, $jobId, '', function (array &$answers) use ($mode, $key, $cat, $p, $userId, $now, &$keys) {
            $set = function (string $k, array $vals) use (&$answers, $userId, $now) {
                $cur = is_array($answers['_drinks'][$k] ?? null) ? $answers['_drinks'][$k] : [];
                $answers['_drinks'][$k] = array_merge($cur, $vals, ['by' => $userId > 0 ? $userId : null, 'at' => $now]);
            };
            if ($mode === 'tick') {
                $set($key, ['tick' => !empty($p['on']) ? 1 : 0]);
            } elseif ($mode === 'category') {
                $set($key, ['cat' => $cat]);
            } else {
                foreach ($keys as $k) {
                    $set($k, ['cat' => $cat]);
                }
                return ['changed' => count($keys)];
            }
            return ['changed' => 1];
        });
    }

    /** One line of the stock link: one measure of the stock item (in the measure itself when the hotel's database can keep it). */
    function import_drinks_link_line(int $ingId, array $link, bool $drinkReady): array
    {
        if ($link['kind'] === 'measure' && $drinkReady) {
            return ['ingredient_id' => $ingId, 'quantity' => 1, 'unit' => 'measure', 'measure' => (string)$link['name']];
        }
        return ['ingredient_id' => $ingId, 'quantity' => (float)$link['qty'], 'unit' => 'base'];
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
        $out = ['added' => 0, 'updated' => 0, 'linked' => 0, 'names' => []];
        if (!import_drinks_any_ticked($answers)) {
            return $out;
        }
        if (!import_drinks_can_menu($userId)) {
            throw new RuntimeException('drinks are ticked for the menu, and your account cannot change the menu (it needs the Menu permission). Untick them or ask a manager who has it.');
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
        $hasLink = $pdo->prepare('SELECT COUNT(*) FROM stock_recipes sr JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id WHERE sr.menu_item_id = ? AND sr.menu_type = ?');
        foreach ($b['rows'] as $r) {
            if (!$r['ticked']) {
                continue;
            }
            $ingId = $r['ing_id'];
            if ($ingId <= 0) {
                $find->execute([$r['name']]);
                $ingId = (int)$find->fetchColumn();
            }
            $price = number_format((float)$r['price'], 2, '.', '');
            if ($r['action'] === 'add') {
                $category = $r['category'];
                if ($category === '' || mb_strlen($category) > 100) {
                    throw new RuntimeException('"' . $r['name'] . '": its menu category is missing or too long.');
                }
                $dup = rh_find_duplicate($pdo, 'drink_menu', 'item_name', $r['name'], ['category' => $category]);
                if ($dup) {
                    throw new RuntimeException('A drink called "' . $dup['value'] . '" is already in "' . $category . '". Please review the drinks list again.');
                }
                $ord = $pdo->prepare('SELECT COALESCE(MAX(display_order), 0) + 1 FROM drink_menu WHERE category = ?');
                $ord->execute([$category]);
                $displayOrder = (int)$ord->fetchColumn();
                $pdo->prepare('INSERT INTO drink_menu (item_name, description, price, category, is_available, display_order, tags, station, show_pos, show_room_service) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$r['name'], '', $price, $category, 1, $displayOrder, '', 'bar', 1, 1]);
                $pos = rh_menu_pos_sync_item($pdo, 'drink', [
                    'item_name' => $r['name'], 'description' => '', 'price' => $price, 'category' => $category, 'station' => 'bar',
                    'is_available' => 1, 'display_order' => $displayOrder, 'show_pos' => 1, 'show_room_service' => 1, 'tags' => '',
                ], null, null);
                $out['added']++;
                $what = 'added "' . $r['name'] . '" to the drinks menu (' . $category . ') at ' . $price . ' per ' . $r['measure'] . ' (selling price from ' . $r['price_source'] . ', VAT included)';
            } else {
                $st = $pdo->prepare('SELECT * FROM drink_menu WHERE id = ? FOR UPDATE');
                $st->execute([$r['menu_id']]);
                $cur = $st->fetch(PDO::FETCH_ASSOC);
                if (!$cur) {
                    throw new RuntimeException('"' . $r['menu_name'] . '" is no longer on the drinks menu. Please review the drinks list again.');
                }
                $oldPrice = number_format((float)$cur['price'], 2, '.', '');
                $pdo->prepare('UPDATE drink_menu SET price = ? WHERE id = ?')->execute([$price, (int)$cur['id']]);
                // the till's copy, exactly as Menu Management does on an edit that changes only the price: same name and category (so an
                // item on an open order is never moved), its availability left as the till has it
                $pos = rh_menu_pos_sync_item($pdo, 'drink', [
                    'item_name' => (string)$cur['item_name'], 'description' => (string)($cur['description'] ?? ''), 'price' => $price, 'category' => (string)$cur['category'],
                    'station' => (string)($cur['station'] ?? 'bar'), 'is_available' => null, 'display_order' => (int)$cur['display_order'],
                    'show_pos' => (int)$cur['show_pos'], 'show_room_service' => (int)$cur['show_room_service'], 'tags' => (string)($cur['tags'] ?? ''),
                ], ['item_name' => (string)$cur['item_name'], 'category' => (string)$cur['category']], null);
                $out['updated']++;
                $what = 'changed the price of "' . rh_clean_text((string)$cur['item_name']) . '" (' . rh_clean_text((string)$cur['category']) . ') from ' . $oldPrice . ' to ' . $price
                    . ' (selling price from ' . $r['price_source'] . ', VAT included)';
            }
            // the stock link: one measure per sale, only when the drink has none (an existing link, maybe with more lines, is never replaced)
            $hasLink->execute([$pos['id'], $pos['slug']]);
            if ((int)$hasLink->fetchColumn() === 0) {
                if ($ingId <= 0 || !$r['link']) {
                    throw new RuntimeException('"' . $r['name'] . '": its stock item was not found, so its stock link could not be made. Nothing was imported.');
                }
                rh_menu_recipe_save($pdo, (int)$pos['id'], (string)$pos['slug'], null, null, [import_drinks_link_line($ingId, $r['link'], $drinkReady)], $userId > 0 ? $userId : null);
                $out['linked']++;
                $what .= '; each sale takes ' . $r['measure'] . ' off stock';
            }
            $out['names'][] = $r['name'];
            if (function_exists('logActivity')) {
                logActivity($userId, 'drink_menu_import', 'Import #' . $jobId . ': ' . $what . '.');
            }
        }
        return $out;
    }
}
