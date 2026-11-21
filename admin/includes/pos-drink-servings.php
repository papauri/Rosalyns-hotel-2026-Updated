<?php
/**
 * POS drink tiles: how one sale is served, and what the same drink costs in its other servings (owner 2026-11-21).
 *
 * A bar / coffee-bar till item whose stock link is exactly ONE line (a drink sold as itself, not a cocktail) is served as
 * that line says: "Shot · 25 ml", "Glass of wine · 175 ml", "Bottle · 750 ml". Other till items linked to the SAME stock
 * item are the same drink in another serving; their menu prices are listed in small on the tile ("Bottle MWK 40,000" on
 * the shot tile, "Shot MWK 4,000" on the bottle tile). Prices are only ever real menu prices, never worked out.
 *
 * Read-only. Uses the drink-measure helpers in config/database.php (rh_drink_line_entry, rh_drink_item_ml,
 * rh_stock_measure_get) so the till says what Stock and Menu Management say.
 *
 *   rh_pos_serving_of($line, $ing, $list)          ['name' => 'shot', 'ml' => 25.0] | null   (pure)
 *   rh_pos_serving_label($serving)                 "Shot · 25 ml"                             (pure)
 *   rh_pos_drink_tiles($rows, $list)               tile info per "type:id"                    (pure)
 *   rh_pos_drink_info($pdo, $menuList)             reads the stock links, returns rh_pos_drink_tiles()
 */

if (!function_exists('rh_pos_serving_of')) {

    /** Most other servings listed on one tile (keeps the tile small). */
    if (!defined('RH_POS_DRINK_OTHERS_MAX')) {
        define('RH_POS_DRINK_OTHERS_MAX', 3);
    }

    /**
     * How one sale is served, from its single stock-link line and the stock item:
     *  1) the measure typed on the link ("1 shot", "2 shots", "50 ml") while it still gives the saved figure;
     *  2) the item's own count measure (one bottle, or one shot of a bottle of N shots);
     *  3) one whole stock unit with a real name (bottle, can, carton), not "each" / "pcs";
     *  4) the size alone in ml, named after the hotel's measure of that same size when there is one.
     * ml is the size of the whole sale when the item's size is known, else null. null = nothing worth showing.
     * @return ?array{name:string, ml:?float}
     */
    function rh_pos_serving_of(array $line, array $ing, array $list): ?array
    {
        $unit = trim((string)($ing['unit'] ?? ''));
        $q = (float)($line['quantity_per_portion'] ?? 0);
        if ($q <= 0 || rh_drink_unit_is_weight($unit)) {
            return null;
        }
        $size = rh_drink_item_ml($ing);
        $ml = $size !== null ? round($q * (float)$size['per_unit'], 2) : null;

        $e = rh_drink_line_entry($line, $ing, $list);
        if ($e !== null) {
            if (preg_match('/^(ml|mls)$/i', (string)$e['measure'])) {
                return ['name' => '', 'ml' => $ml ?? round((float)$e['qty'], 2)];
            }
            $name = abs((float)$e['qty'] - 1.0) < 1e-9
                ? (string)$e['measure']
                : rh_stock_qty_fmt((float)$e['qty'], 0) . ' ' . rh_drink_plural((string)$e['measure'], (float)$e['qty']);
            return ['name' => $name, 'ml' => $ml];
        }

        $m = rh_stock_measure_get($ing);
        if ($m !== null) {
            $bigQ = $m['side'] === 'big' ? 1.0 : (float)$m['n'];
            $smallQ = $m['side'] === 'big' ? round(1.0 / (float)$m['n'], 6) : 1.0;
            if (abs($q - $bigQ) <= 0.0000015 * max(1.0, $bigQ)) {
                return ['name' => $m['big'], 'ml' => $ml];
            }
            if (abs($q - $smallQ) <= 0.0000015) {
                return ['name' => $m['small'], 'ml' => $ml];
            }
        }

        if (abs($q - 1.0) < 1e-9 && rh_drink_unit_ml($unit) === null
            && $unit !== '' && !preg_match('/^(each|ea|pcs?|pieces?|units?|items?|nos?\.?|qty)$/i', $unit)) {
            return ['name' => $unit, 'ml' => $ml];
        }

        if ($ml !== null) {
            foreach ($list as $dm) {
                if (abs((float)$dm['ml'] - $ml) < 0.005) {
                    return ['name' => (string)$dm['name'], 'ml' => $ml];
                }
            }
            return ['name' => '', 'ml' => $ml];
        }
        return null;
    }

    /** "Shot · 25 ml", "Bottle" (size unknown), "330 ml" (no name). */
    function rh_pos_serving_label(array $s): string
    {
        $name = trim((string)($s['name'] ?? ''));
        $name = $name === '' ? '' : mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        $ml = isset($s['ml']) && $s['ml'] !== null ? rh_stock_qty_fmt((float)$s['ml'], 0) . ' ml' : '';
        if ($name !== '' && $ml !== '') {
            return $name . ' · ' . $ml;
        }
        return $name !== '' ? $name : $ml;
    }

    /** Short name of a serving for the small price list: "Shot", "Bottle", "50 ml". */
    function rh_pos_serving_short(array $s): string
    {
        $name = trim((string)($s['name'] ?? ''));
        if ($name !== '') {
            return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        }
        return isset($s['ml']) && $s['ml'] !== null ? rh_stock_qty_fmt((float)$s['ml'], 0) . ' ml' : '';
    }

    /**
     * Tile info from the drink items' stock links. $rows: one per till item with a single-line link:
     *   ['key' => 'type:id', 'price' => float, 'sellable' => bool, 'ing_id' => int, 'line' => [...], 'ing' => [...]]
     * 'sellable' = on the till and not 86'd (only those are listed as another serving).
     * Returns key => ['serving' => "Shot · 25 ml", 'others' => [['name' => 'Bottle', 'price' => 40000.0], ...]],
     * others biggest serving first, at most RH_POS_DRINK_OTHERS_MAX, the same name + price once.
     */
    function rh_pos_drink_tiles(array $rows, array $list): array
    {
        $byIng = [];
        foreach ($rows as $r) {
            $s = rh_pos_serving_of((array)$r['line'], (array)$r['ing'], $list);
            if ($s === null) {
                continue;
            }
            $byIng[(int)$r['ing_id']][] = [
                'key' => (string)$r['key'],
                'label' => rh_pos_serving_label($s),
                'short' => rh_pos_serving_short($s),
                'qty' => (float)($r['line']['quantity_per_portion'] ?? 0),
                'price' => (float)$r['price'],
                'sellable' => !empty($r['sellable']),
            ];
        }
        $out = [];
        foreach ($byIng as $items) {
            usort($items, static fn(array $a, array $b): int => $b['qty'] <=> $a['qty'] ?: strcmp($a['key'], $b['key']));
            foreach ($items as $it) {
                if ($it['label'] === '') {
                    continue;
                }
                $others = [];
                $seen = [];
                foreach ($items as $o) {
                    if ($o['key'] === $it['key'] || !$o['sellable'] || $o['short'] === '') {
                        continue;
                    }
                    $k = mb_strtolower($o['short']) . '|' . number_format($o['price'], 2, '.', '');
                    if (isset($seen[$k]) || count($others) >= RH_POS_DRINK_OTHERS_MAX) {
                        continue;
                    }
                    $seen[$k] = true;
                    $others[] = ['name' => $o['short'], 'price' => $o['price']];
                }
                $out[$it['key']] = ['serving' => $it['label'], 'others' => $others];
            }
        }
        return $out;
    }

    /**
     * Reads the single-line stock links of the bar / coffee-bar items in $menuList (pos.php's list) and returns
     * rh_pos_drink_tiles(). [] when there are none. Throws on a database error (the caller keeps the till working).
     */
    function rh_pos_drink_info(PDO $pdo, array $menuList): array
    {
        $items = [];
        foreach ($menuList as $m) {
            if (in_array($m['station'] ?? '', ['bar', 'coffee_bar'], true)) {
                $items[(string)$m['type'] . ':' . (int)$m['id']] = $m;
            }
        }
        if (!$items) {
            return [];
        }
        $ids = array_values(array_unique(array_map(static fn(array $m): int => (int)$m['id'], $items)));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $entry = rh_stock_drink_ready($pdo) ? ', sri.entry_measure, sri.entry_qty, sri.entry_ml' : '';
        $st = $pdo->prepare(
            "SELECT sr.menu_item_id, sr.menu_type, sri.ingredient_id, sri.quantity_per_portion $entry,
                    i.name, i.unit" . rh_drink_ing_cols('i') . "
               FROM stock_recipes sr
               JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
               JOIN stock_ingredients i ON i.id = sri.ingredient_id
              WHERE sr.menu_item_id IN ($in)
                AND sri.quantity_per_portion > 0
                AND (SELECT COUNT(*) FROM stock_recipe_ingredients x WHERE x.recipe_id = sr.id) = 1"
        );
        $st->execute($ids);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = (string)$r['menu_type'] . ':' . (int)$r['menu_item_id'];
            if (!isset($items[$key])) {
                continue;
            }
            $m = $items[$key];
            $rows[] = [
                'key' => $key,
                'price' => (float)$m['price'],
                'sellable' => !empty($m['show_pos']) && (int)($m['is_available'] ?? 1) === 1,
                'ing_id' => (int)$r['ingredient_id'],
                'line' => $r,
                'ing' => $r,
            ];
        }
        return rh_pos_drink_tiles($rows, rh_drink_measures_list());
    }
}
