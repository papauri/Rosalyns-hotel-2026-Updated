<?php

/**
 * Drinks profit projection P2 (owner 2026-11-19): what the drinks in stock would sell for at today's menu prices,
 * what that stock cost, and the profit. Read-only: nothing here writes to the database.
 *
 * Shared by Stock Reports > Projected profit (and P3 later):
 *   rh_spp_vat_rate()                        VAT % to take out of a menu price (0 when VAT is off)
 *   rh_spp_net(float $gross, float $rate)    a VAT-included amount without its VAT (the till's own rule)
 *   rh_spp_load(PDO $pdo)                    the stock items, the drinks' stock links and the kitchen's stock items
 *   rh_spp_build(...)                        lines, totals and the "No selling price yet" list (pure arithmetic, no database)
 *
 * Rules (kept the same as the till, so the projection matches what a sale really takes off stock):
 *  - a "drink on the menu" is a till item that is not a kitchen item whose stock link is exactly ONE stock line (a drink sold
 *    as itself; a cocktail that mixes several stock items is left out), same rule as the import's "Drinks for the menu";
 *    the link must sit under the item's current category (the till looks it up there; one left under an old category takes
 *    nothing off stock, so it projects nothing);
 *  - one sale takes quantity_per_portion / yield of the stock unit off stock (deductStockForMenuItem), so measures in one
 *    stock unit = yield / quantity_per_portion, and the cost of one sale = rh_menu_cost_check (the till's arithmetic);
 *  - menu prices INCLUDE VAT and the till never adds VAT: with "Take VAT out" the VAT is taken out of each line's total as
 *    gross / (1 + rate / 100), whatever the hotel's pricing mode (the same as pos_calculateRestaurantVatParts);
 *  - a price at or below BALANCE_TOLERANCE is not a selling price; a cost per unit at or below 0 is "cost not known", never 0;
 *  - stock below zero counts as nothing (the same as the Inventory tab).
 * Totals: each stock item's stock cost is counted ONCE. Its sales come from ONE of its lines: the one that would bring in the
 * least (it never overstates). Items whose cost is not known are left out of every total, so profit = sales - cost always.
 */

require_once __DIR__ . '/menu-recipe-lib.php'; // rh_menu_cost_check / rh_menu_cost_over_price: the till's cost of one sale

if (!function_exists('rh_spp_vat_rate')) {

    /** The VAT % inside a menu price: the hotel's rate when VAT is on, else 0. */
    function rh_spp_vat_rate(): float
    {
        if (!function_exists('rh_vat_enabled') || !rh_vat_enabled()) {
            return 0.0;
        }
        $rate = (float)getSetting('vat_rate', 0);
        return $rate > 0 ? $rate : 0.0;
    }

    /** A VAT-included amount without its VAT, 2 dp (gross / (1 + rate / 100); rate 0 = unchanged). */
    function rh_spp_net(float $gross, float $rate): float
    {
        if ($rate <= 0) {
            return round($gross, 2);
        }
        return round($gross / (1 + ($rate / 100)), 2);
    }

    /**
     * Is this stock item a drink (for the "No selling price yet" list, drinks only)? Yes when a drink measure can be poured
     * from it: a size is known (a volume unit such as L / ml, a bottle size typed on the item, or a size in its name, "Coke
     * 330ml"), or it has its own count measure (bottle -> shot), or its unit is a drink container (bottle, can, carton, keg, casket,
     * crate, tot, shot, glass). Weights never; cleaning and other items counted in each / pcs / rolls are not drinks.
     */
    function rh_spp_is_drink_stock(array $ing): bool
    {
        $unit = trim((string)($ing['unit'] ?? ''));
        if (function_exists('rh_drink_unit_is_weight') && rh_drink_unit_is_weight($unit)) {
            return false;
        }
        if (function_exists('rh_drink_item_ml') && rh_drink_item_ml($ing) !== null) {
            return true;
        }
        if (function_exists('rh_stock_measure_get') && rh_stock_measure_get($ing) !== null) {
            return true;
        }
        return (bool)preg_match('/^(bottles?|btls?|cans?|cartons?|kegs?|caskets?|crates?|tots?|shots?|glass(es)?)$/iu', $unit);
    }

    /**
     * Read what the projection needs (SELECT only).
     * @return array{items:array<int,array>, links:array<int,array>, kitchen:array<int,bool>, list:array}
     */
    function rh_spp_load(PDO $pdo): array
    {
        $drink = function_exists('rh_stock_drink_ready') && rh_stock_drink_ready($pdo);
        $items = [];
        $q = $pdo->query(
            "SELECT id, name, category, unit, current_quantity, cost_per_unit" . ($drink ? rh_drink_ing_cols('') : '') . "
               FROM stock_ingredients
              WHERE is_archived = 0
              ORDER BY name, id"
        );
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[(int)$r['id']] = $r;
        }

        $links = $pdo->query(
            "SELECT mi.id AS menu_item_id, mi.item_name, mi.category AS menu_category, mi.price, mi.is_available,
                    sri.ingredient_id, sri.quantity_per_portion, sri.yield_percent"
                . ($drink ? ", sri.entry_measure, sri.entry_qty, sri.entry_ml" : '') . "
               FROM menu_items mi
               JOIN menu_categories mc ON mc.id = mi.category_id
               JOIN stock_recipes sr ON sr.menu_item_id = mi.id AND sr.menu_type = mc.slug
               JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
               JOIN stock_ingredients i ON i.id = sri.ingredient_id AND i.is_archived = 0
              WHERE COALESCE(mc.business_context, 'food_service') = 'food_service'
                AND COALESCE(mi.station, '') <> 'kitchen'
                AND NOT (mi.is_available = 0 AND mi.show_pos = 0 AND mi.show_room_service = 0)
                AND (SELECT COUNT(*) FROM stock_recipe_ingredients x WHERE x.recipe_id = sr.id) = 1
              ORDER BY mi.item_name, mi.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $kitchen = [];
        $k = $pdo->query(
            "SELECT DISTINCT sri.ingredient_id
               FROM stock_recipe_ingredients sri
               JOIN stock_recipes sr ON sr.id = sri.recipe_id
               JOIN menu_items mi ON mi.id = sr.menu_item_id
              WHERE mi.station = 'kitchen'"
        );
        foreach ($k->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $kitchen[(int)$id] = true;
        }

        return ['items' => $items, 'links' => $links, 'kitchen' => $kitchen, 'list' => $drink ? rh_drink_measures_list() : []];
    }

    /**
     * Stock units one sale takes, for counting measures in a unit. The link keeps it rounded to 6 dp (25 ml of a 750 ml bottle is
     * saved as 0.033333, which would count 30.0003 shots a bottle); when the link was typed as a measure and that measure still gives
     * the saved figure (rh_drink_line_entry), the same conversion as rh_drink_measure_to_stock is used without the rounding, so a
     * bottle gives exactly 30 shots. Otherwise the saved figure.
     */
    function rh_spp_stock_per_sale(array $link, array $ing, array $list): float
    {
        $qpp = (float)($link['quantity_per_portion'] ?? 0);
        $e = function_exists('rh_drink_line_entry') ? rh_drink_line_entry($link, $ing, $list) : null;
        if ($e === null) {
            return $qpp;
        }
        $opts = rh_drink_measure_options($ing, $list);
        $key = rh_drink_option_key($opts, (string)$e['measure']);
        if ($key === null) {
            return $qpp;
        }
        $o = $opts[$key];
        $exact = null;
        if ($o['kind'] === 'item' && (float)$o['n'] > 0) {
            $exact = (float)$e['qty'] / (float)$o['n'];
        } elseif ($o['ml'] !== null) {
            $size = rh_drink_item_ml($ing);
            if ($size !== null && (float)$size['per_unit'] > 0) {
                $exact = (float)$e['qty'] * (float)$o['ml'] / (float)$size['per_unit'];
            }
        }
        // Only ever a refinement of the saved figure, never a different amount.
        return ($exact !== null && $exact > 0 && abs($exact - $qpp) <= 0.0000015) ? $exact : $qpp;
    }

    /** How one sale is written: "1 shot (25 ml)" when the link was typed as a measure, else "0.033333 bottle". */
    function rh_spp_sale_label(array $link, array $ing, array $list): string
    {
        $e = function_exists('rh_drink_line_entry') ? rh_drink_line_entry($link, $ing, $list) : null;
        if ($e !== null) {
            $ml = (isset($link['entry_ml']) && is_numeric($link['entry_ml']) && (float)$link['entry_ml'] > 0) ? (float)$link['entry_ml'] : null;
            $q = (float)$e['qty'];
            return rh_stock_qty_fmt($q, 0) . ' ' . rh_drink_plural((string)$e['measure'], $q)
                . ($ml !== null && mb_strtolower((string)$e['measure']) !== 'ml' ? ' (' . rh_stock_qty_fmt($ml * $q, 0) . ' ml)' : '');
        }
        $qpp = (float)($link['quantity_per_portion'] ?? 0);
        $unit = (string)($ing['unit'] ?? '');
        return rh_stock_qty_fmt($qpp, 0) . ' ' . (function_exists('rh_stock_measure_plural') ? rh_stock_measure_plural($unit, $qpp) : $unit);
    }

    /**
     * The projection. Pure: no database.
     * @param array $items    stock items keyed by id (id, name, unit, current_quantity, cost_per_unit [+ drink columns])
     * @param array $links    one row per till item: menu_item_id, item_name, price, ingredient_id, quantity_per_portion, yield_percent
     * @param array $kitchen  stock item ids used by kitchen items (kept off the "No selling price yet" list)
     * @param float $vatRate  VAT % inside the menu price (rh_spp_vat_rate)
     * @param bool  $vatOut   true = sales, profit and the below-cost check use prices without VAT
     * @return array{lines:array, no_price:array, totals:array}
     */
    function rh_spp_build(array $items, array $links, array $kitchen, float $vatRate, bool $vatOut, array $list = []): array
    {
        $rate = $vatOut ? max(0.0, $vatRate) : 0.0;
        $lines = [];
        $priced = [];
        foreach ($links as $l) {
            $ingId = (int)($l['ingredient_id'] ?? 0);
            $ing = $items[$ingId] ?? null;
            if ($ing === null) {
                continue;
            }
            $unit = (string)($ing['unit'] ?? '');
            if (function_exists('rh_drink_unit_is_weight') && rh_drink_unit_is_weight($unit)) {
                continue;               // food (kg, g ...) is not a drink
            }
            $qpp = (float)($l['quantity_per_portion'] ?? 0);
            $gross = round((float)($l['price'] ?? 0), 2);
            if ($qpp <= 0 || $gross <= BALANCE_TOLERANCE) {
                continue;               // takes nothing off stock, or no selling price: not a line
            }
            $priced[$ingId] = true;
            $onHand = max(0.0, (float)($ing['current_quantity'] ?? 0));
            $cpu = (isset($ing['cost_per_unit']) && is_numeric($ing['cost_per_unit']) && (float)$ing['cost_per_unit'] > 0) ? (float)$ing['cost_per_unit'] : null;
            $yield = isset($l['yield_percent']) ? (float)$l['yield_percent'] : 100.0;
            $perUnit = rh_stock_yield_factor($yield) / rh_spp_stock_per_sale($l, $ing, $list); // sales one stock unit gives
            $salesGross = $onHand * $perUnit * $gross;
            $sales = $vatOut ? rh_spp_net($salesGross, $rate) : round($salesGross, 2);
            $price = $vatOut ? rh_spp_net($gross, $rate) : $gross;
            $costPerSale = null;
            $stockCost = null;
            $profit = null;
            $margin = null;
            $below = false;
            if ($cpu !== null) {
                $chk = rh_menu_cost_check([['quantity_per_portion' => $qpp, 'yield_percent' => $yield, 'cost_per_unit' => $cpu, 'ingredient_id' => $ingId]], $price);
                $costPerSale = round((float)$chk['cost'], 4);
                $below = (bool)$chk['over'];
                $stockCost = round($onHand * $cpu, 2);
                $profit = round($sales - $stockCost, 2);
                $margin = $sales > BALANCE_TOLERANCE ? round($profit / $sales * 100, 1) : null;
            }
            $lines[] = [
                'menu_item_id' => (int)($l['menu_item_id'] ?? 0),
                'drink' => (string)($l['item_name'] ?? ''),
                'menu_category' => (string)($l['menu_category'] ?? ''),
                'available' => (int)($l['is_available'] ?? 1) === 1,
                'ingredient_id' => $ingId,
                'stock_name' => (string)($ing['name'] ?? ''),
                'unit' => $unit,
                'on_hand' => $onHand,
                'sale' => rh_spp_sale_label($l, $ing, $list),
                'per_unit' => $perUnit,
                'price_gross' => $gross,
                'price' => $price,
                'sales' => $sales,
                'cost_per_unit' => $cpu,
                'cost_per_sale' => $costPerSale,
                'stock_cost' => $stockCost,
                'profit' => $profit,
                'margin' => $margin,
                'below_cost' => $below,
                'cost_known' => $cpu !== null,
                'in_totals' => false,
            ];
        }

        // Totals: one line per stock item (the lowest projected sales; ties: the lowest till id), stock cost once per item.
        $pick = [];
        foreach ($lines as $i => $ln) {
            if (!$ln['cost_known']) {
                continue;
            }
            $id = $ln['ingredient_id'];
            if (!isset($pick[$id])) {
                $pick[$id] = $i;
                continue;
            }
            $cur = $lines[$pick[$id]];
            $d = $ln['sales'] - $cur['sales'];
            if ($d < -BALANCE_TOLERANCE || (abs($d) <= BALANCE_TOLERANCE && $ln['menu_item_id'] < $cur['menu_item_id'])) {
                $pick[$id] = $i;
            }
        }
        $tSales = 0.0;
        $tCost = 0.0;
        foreach ($pick as $i) {
            $lines[$i]['in_totals'] = true;
            $tSales += $lines[$i]['sales'];
            $tCost += (float)$lines[$i]['stock_cost'];
        }
        $tSales = round($tSales, 2);
        $tCost = round($tCost, 2);
        $tProfit = round($tSales - $tCost, 2);

        $unknown = [];
        $belowN = 0;
        foreach ($lines as $ln) {
            if (!$ln['cost_known']) {
                $unknown[$ln['ingredient_id']] = true;
            }
            if ($ln['below_cost']) {
                $belowN++;
            }
        }

        $noPrice = [];
        foreach ($items as $id => $ing) {
            if (isset($priced[(int)$id]) || isset($kitchen[(int)$id])) {
                continue;
            }
            if (!rh_spp_is_drink_stock($ing)) { // drinks only: kitchen, cleaning and other stock is not listed
                continue;
            }
            $cpu = (isset($ing['cost_per_unit']) && is_numeric($ing['cost_per_unit']) && (float)$ing['cost_per_unit'] > 0) ? (float)$ing['cost_per_unit'] : null;
            $onHand = max(0.0, (float)($ing['current_quantity'] ?? 0));
            $noPrice[] = [
                'ingredient_id' => (int)$id,
                'stock_name' => (string)($ing['name'] ?? ''),
                'category' => (string)($ing['category'] ?? ''),
                'unit' => (string)($ing['unit'] ?? ''),
                'on_hand' => $onHand,
                'cost_per_unit' => $cpu,
                'stock_cost' => $cpu !== null ? round($onHand * $cpu, 2) : null,
            ];
        }

        return [
            'lines' => $lines,
            'no_price' => $noPrice,
            'totals' => [
                'sales' => $tSales,
                'cost' => $tCost,
                'profit' => $tProfit,
                'margin' => $tSales > BALANCE_TOLERANCE ? round($tProfit / $tSales * 100, 1) : null,
                'items' => count($pick),
                'lines' => count($lines),
                'below_cost' => $belowN,
                'cost_unknown' => count($unknown),
                'no_price' => count($noPrice),
                'vat_out' => $vatOut && $rate > 0,
                'vat_rate' => $vatRate,
            ],
        ];
    }
}
