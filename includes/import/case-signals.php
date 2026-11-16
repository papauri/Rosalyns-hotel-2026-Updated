<?php
/**
 * "Is this cost the price of a whole case / crate?" - ONE shared judge (pure: no output) plus one set-based loader of this hotel's evidence.
 *
 * Used by
 *   - Import (includes/import/resolve.php, import_case_evidence_pass): a file's unit price BEFORE it is imported. Strong evidence AND the
 *     item's own pack size -> the "case or unit?" question is answered "per case" for the person (the reason is shown, the answer can be
 *     changed, a stored answer always wins). Evidence without a pack size, or evidence that disagrees -> the question is asked. None -> nothing.
 *   - Import review: an EXISTING matched item whose STORED cost already looks like a case price -> a plain warning on the item (never blocks).
 *   - Stock > Ingredients (admin/stock-ingredients.php): the same check as a read-only "May be a case price" note under Avg cost / unit.
 *
 * Signals (each one only when this hotel's data has it; no product names, prices or hotel facts in code):
 *   sell      the selling price per stock unit of a menu item LINKED BY A RECIPE: menu price x yield factor / quantity per portion (the same
 *             quantity stock deduction takes per portion). The highest such price is used (the most generous reading). A cost of at least
 *             twice that = strong. A menu item that only has the same NAME is never used: a spirit sold by the tot would look like a crate.
 *   receipts  unit costs of REAL deliveries (stock_in_log = Stock In and purchase-order receiving). Opening counts and the batches they create
 *             are NOT used: a wrong import writes those, so they would only confirm themselves.
 *   own pack  the item's own pack size: a price that is (within 5 %) pack x the current cost, or pack x the usual delivery cost = strong.
 *   value     the sheet's own value cell = quantity x price / N, used only when N is a pack size the hotel holds for the item (its own =
 *             strong and can decide; a barcode / order pack = strong, asked). Any other whole N says nothing: a count that changed after
 *             the value was worked out looks the same (value-check.php explains those).
 *   cluster   items of the same category AND unit, split where consecutive prices jump at least 2x (a case holds at least 2 units): a price
 *             far above the bulk (the part holding more than half, at least 4 items) = suspect only (asked, never decided).
 * Counter-signals (make strong evidence "unclear", so it is asked): a cost at or under the selling price; a cost in line with its deliveries.
 * Generic statistics only, the same as price-check.php: k = 3 x robust spread (2 % floor), a pattern matches within 5 %, groups of 4.
 */

if (!function_exists('import_cs_judge')) {

    function import_cs_params(array $o = []): array
    {
        return array_merge(['k' => 3.0, 'spread_floor' => 0.02, 'struct_tol' => 0.05, 'min_group' => 4, 'jump' => 2.0], $o);
    }

    function import_cs_median(array $a): ?float
    {
        $n = count($a);
        if ($n === 0) {
            return null;
        }
        sort($a);
        $m = intdiv($n, 2);
        return $n % 2 ? (float)$a[$m] : ((float)$a[$m - 1] + (float)$a[$m]) / 2;
    }

    /** 1.4826 x median absolute deviation of the logs (0 when fewer than 3 points: not enough to measure a spread). */
    function import_cs_spread(array $vals): float
    {
        if (count($vals) < 3) {
            return 0.0;
        }
        $l = array_map('log', $vals);
        $m = import_cs_median($l);
        return 1.4826 * (float)import_cs_median(array_map(function ($x) use ($m) {
            return abs($x - $m);
        }, $l));
    }

    function import_cs_money($v): string
    {
        if (function_exists('import_fmt_money')) {
            return import_fmt_money($v);
        }
        $v = (float)$v;
        return abs($v - round($v)) < 0.005 ? number_format($v, 0) : number_format($v, 2);
    }

    function import_cs_times(float $r): string
    {
        return $r >= 10 ? (string)(int)round($r) : rtrim(rtrim(number_format($r, 1, '.', ''), '0'), '.');
    }

    /** A whole pack size 2..100000 (the range the per-case answer accepts), else null. */
    function import_cs_whole_pack($p): ?int
    {
        if (!is_numeric($p)) {
            return null;
        }
        $f = (float)$p;
        $i = (int)round($f);
        return (abs($f - $i) < 0.0005 && $i >= 2 && $i <= 100000) ? $i : null;
    }

    /**
     * Judge one cost. $in keys (all optional except cost):
     *   cost      the cost per stock unit as it would be stored (a file price NOT divided; or the cost already in stock)
     *   unit      the stock unit, for the wording
     *   current   the matched item's cost in stock now (only when judging a FILE price)
     *   own_pack  the item's own pack size
     *   sell      ['per_unit', 'item', 'price', 'qty'] from import_cs_load_evidence()
     *   receipts  unit costs of real deliveries
     *   value_pack ['n', 'value', 'qty', 'source']: the sheet's value cell is qty x price / n
     *   cluster   ['median', 'n'] of the lower group this cost sits far above (import_cs_cluster_refs())
     * Returns null (nothing to say) or ['verdict' => 'case' | 'ask', 'pack' => ?int (only with 'case'), 'pack_hint' => ?int,
     *   'strong' => [codes], 'weak' => [codes], 'against' => [codes], 'reasons' => [plain sentences], 'reason' => one line].
     */
    function import_cs_judge(array $in, array $p = []): ?array
    {
        $p = import_cs_params($p);
        $cost = (float)($in['cost'] ?? 0);
        if (!($cost > 0) || !is_finite($cost)) {
            return null;
        }
        $u = trim((string)($in['unit'] ?? ''));
        $per = $u !== '' ? $u : 'unit';
        $strong = [];
        $weak = [];
        $against = [];
        $reasons = [];
        $againstWhy = [];
        $packTol = log(1 + (float)$p['struct_tol']);
        $ownPack = import_cs_whole_pack($in['own_pack'] ?? null);

        $sell = $in['sell'] ?? null;
        $sellPer = is_array($sell) && isset($sell['per_unit']) && (float)$sell['per_unit'] > 0 ? (float)$sell['per_unit'] : null;
        if ($sellPer !== null) {
            $how = '"' . (string)($sell['item'] ?? '') . '" sells for ' . import_cs_money($sell['price'] ?? 0) . ' using '
                . rtrim(rtrim(number_format((float)($sell['qty'] ?? 0), 4, '.', ''), '0'), '.') . ' ' . $per;
            if ($cost >= 2 * $sellPer) {
                $strong[] = 'sell';
                $reasons[] = 'the menu sells it for about ' . import_cs_money(round($sellPer, 2)) . ' per ' . $per . ' (' . $how . '), and '
                    . import_cs_money($cost) . ' is ' . import_cs_times($cost / $sellPer) . ' times that';
            } elseif ($cost <= $sellPer) {
                $against[] = 'sell';
                $againstWhy[] = 'it is not above the menu selling price of ' . import_cs_money(round($sellPer, 2)) . ' per ' . $per;
            }
        }

        $rec = [];
        foreach ((array)($in['receipts'] ?? []) as $r) {
            if (is_numeric($r) && (float)$r > 0 && is_finite((float)$r)) {
                $rec[] = (float)$r;
            }
        }
        $recMed = $rec ? import_cs_median($rec) : null;
        if ($recMed !== null) {
            $tol = (float)$p['k'] * max(import_cs_spread($rec), (float)$p['spread_floor']);
            $lr = log($cost / $recMed);
            if ($cost >= 2 * $recMed && $lr > $tol) {
                $strong[] = 'receipts';
                $reasons[] = 'it is ' . import_cs_times($cost / $recMed) . ' times its usual delivery cost of ' . import_cs_money($recMed) . ' per ' . $per
                    . ' (' . count($rec) . ' deliver' . (count($rec) === 1 ? 'y' : 'ies') . ')';
            } elseif (abs($lr) <= $tol) {
                $against[] = 'receipts';
                $againstWhy[] = 'it is in line with its deliveries (' . import_cs_money($recMed) . ' per ' . $per . ')';
            }
        }

        if ($ownPack !== null) {
            $cur = isset($in['current']) && is_numeric($in['current']) && (float)$in['current'] > 0 ? (float)$in['current'] : null;
            foreach ([['current', $cur, 'the cost in stock now'], ['receipts', $recMed, 'its usual delivery cost']] as [$code, $base, $word]) {
                if ($base !== null && abs(log($cost / ($ownPack * $base))) <= $packTol) {
                    $strong[] = 'own-pack';
                    $reasons[] = import_cs_money($cost) . ' is its pack of ' . $ownPack . ' times ' . $word . ' (' . import_cs_money($base) . ' per ' . $per . ')';
                    break;
                }
            }
        }

        $packHint = null;
        if ($ownPack === null) {
            // pack sizes from the item's barcodes / purchase orders: evidence and a hint for the pack box, never applied by themselves
            $cur = isset($in['current']) && is_numeric($in['current']) && (float)$in['current'] > 0 ? (float)$in['current'] : null;
            foreach ((array)($in['other_packs'] ?? []) as $op) {
                $op = import_cs_whole_pack($op);
                foreach ([[$cur, 'the cost in stock now'], [$recMed, 'its usual delivery cost']] as [$base, $word]) {
                    if ($op !== null && $base !== null && abs(log($cost / ($op * $base))) <= $packTol) {
                        $strong[] = 'other-pack';
                        $reasons[] = import_cs_money($cost) . ' is a pack of ' . $op . ' (from its barcodes or orders) times ' . $word . ' (' . import_cs_money($base) . ' per ' . $per . ')';
                        $packHint = $op;
                        break 2;
                    }
                }
            }
            if ($packHint === null && count((array)($in['other_packs'] ?? [])) === 1) {
                $packHint = import_cs_whole_pack(array_values((array)$in['other_packs'])[0]);
            }
        }
        $vp = $in['value_pack'] ?? null;
        // Only when N is a pack size this hotel already holds for the item: any other whole N is as likely a count that changed after the value
        // was worked out (value-check.php explains those), and small counts give whole ratios by chance.
        $otherPacks = array_filter(array_map('import_cs_whole_pack', (array)($in['other_packs'] ?? [])));
        if (is_array($vp) && ($vn = import_cs_whole_pack($vp['n'] ?? null)) !== null && ($vn === $ownPack || in_array($vn, $otherPacks, true))) {
            $packHint = $vn;
            $strong[] = 'value';
            $reasons[] = 'the sheet\'s value ' . import_cs_money($vp['value'] ?? 0) . (isset($vp['source']) && $vp['source'] !== '' ? ' (' . $vp['source'] . ')' : '')
                . ' is ' . rtrim(rtrim(number_format((float)($vp['qty'] ?? 0), 3, '.', ''), '0'), '.') . ' x ' . import_cs_money($cost) . ' / ' . $vn
                . ', and ' . $vn . ' is ' . ($vn === $ownPack ? 'its own pack size' : 'a pack size on its barcodes or orders');
        }

        $cl = $in['cluster'] ?? null;
        if (is_array($cl) && isset($cl['median']) && (float)$cl['median'] > 0) {
            $weak[] = 'cluster';
            $reasons[] = 'it is ' . import_cs_times($cost / (float)$cl['median']) . ' times the usual ' . import_cs_money($cl['median']) . ' of '
                . (int)$cl['n'] . ' other items counted in ' . $per . ' in its category';
        }

        if (!$strong && !$weak) {
            return null;
        }
        $verdict = 'ask';
        $pack = null;
        if ($strong && !$against && $ownPack !== null) {
            $fitsSell = $sellPer === null || $cost / $ownPack <= $sellPer;
            $fitsValue = $packHint === null || $packHint === $ownPack;
            if ($fitsSell && $fitsValue) {
                $verdict = 'case';
                $pack = $ownPack;
            }
        }
        if ($packHint === null && $ownPack !== null) {
            $packHint = $ownPack;
        }
        $line = ucfirst(implode('; ', $reasons)) . '.';
        if ($againstWhy) {
            $line .= ' But ' . implode(' and ', $againstWhy) . '.';
        }
        return ['verdict' => $verdict, 'pack' => $pack, 'pack_hint' => $packHint, 'strong' => array_values(array_unique($strong)), 'weak' => $weak,
            'against' => $against, 'reasons' => $reasons, 'reason' => $line];
    }

    /**
     * Same-category, same-unit groups: which members sit far above the bulk of their group. $items = key => ['group' => string, 'cost' => float].
     * Per group: sort once, split where the next price is at least `jump` times the previous one. The bulk = the one part holding more than
     * half of the group and at least `min_group` items. A price above the bulk is flagged when it is >= 2x the bulk's median and outside k x
     * its spread (a few odd prices far above most items, not a second kind of product: spirits next to beers have no bulk).
     * Returns key => ['median' => float, 'n' => int].
     */
    function import_cs_cluster_refs(array $items, array $p = []): array
    {
        $p = import_cs_params($p);
        $groups = [];
        foreach ($items as $k => $it) {
            $c = (float)($it['cost'] ?? 0);
            if ($c > 0 && is_finite($c)) {
                $groups[(string)$it['group']][] = [$c, $k];
            }
        }
        $out = [];
        foreach ($groups as $list) {
            if (count($list) < (int)$p['min_group'] + 1) {
                continue;
            }
            usort($list, function ($a, $b) {
                return $a[0] <=> $b[0];
            });
            $clusters = [[$list[0]]];
            for ($i = 1, $n = count($list); $i < $n; $i++) {
                if ($list[$i][0] >= (float)$p['jump'] * $list[$i - 1][0]) {
                    $clusters[] = [];
                }
                $clusters[count($clusters) - 1][] = $list[$i];
            }
            $nc = count($clusters);
            if ($nc < 2) {
                continue;
            }
            // the bulk: the one part holding MORE than half of the group (and at least min_group); only prices above it are suspect
            $total = count($list);
            for ($i = 0; $i < $nc - 1; $i++) {
                if (count($clusters[$i]) * 2 <= $total || count($clusters[$i]) < (int)$p['min_group']) {
                    continue;
                }
                $vals = array_column($clusters[$i], 0);
                $med = import_cs_median($vals);
                $tol = (float)$p['k'] * max(import_cs_spread($vals), (float)$p['spread_floor']);
                for ($j = $i + 1; $j < $nc; $j++) {
                    foreach ($clusters[$j] as [$c, $k]) {
                        if ($c >= 2 * $med && log($c / $med) > $tol) {
                            $out[$k] = ['median' => $med, 'n' => count($vals)];
                        }
                    }
                }
                break;
            }
        }
        return $out;
    }

    function import_cs_group_key($category, $unit): string
    {
        return mb_strtolower(trim((string)$category)) . '|' . mb_strtolower(trim((string)$unit));
    }

    /**
     * The read-only stock check: which ingredients' STORED cost may be a case price. $ingredients = rows with id, category, unit,
     * cost_per_unit, pack_size, is_archived; $ev = import_cs_load_evidence(). Returns id => judge result (archived and cost-less items skipped).
     */
    function import_cs_stock_flags(array $ingredients, array $ev, array $p = []): array
    {
        $items = [];
        foreach ($ingredients as $e) {
            if ((int)($e['is_archived'] ?? 0) === 0 && (float)($e['cost_per_unit'] ?? 0) > 0) {
                $items[(int)$e['id']] = ['group' => import_cs_group_key($e['category'] ?? '', $e['unit'] ?? ''), 'cost' => (float)$e['cost_per_unit']];
            }
        }
        $refs = import_cs_cluster_refs($items, $p);
        $out = [];
        foreach ($ingredients as $e) {
            $id = (int)$e['id'];
            if (!isset($items[$id])) {
                continue;
            }
            $j = import_cs_judge([
                'cost' => (float)$e['cost_per_unit'], 'unit' => (string)($e['unit'] ?? ''), 'own_pack' => $e['pack_size'] ?? null,
                'sell' => $ev['sell'][$id] ?? null, 'receipts' => $ev['receipts'][$id] ?? [], 'cluster' => $refs[$id] ?? null,
                'other_packs' => array_values($ev['packs'][$id] ?? []),
            ], $p);
            if ($j !== null) {
                $out[$id] = $j;
            }
        }
        return $out;
    }

    /**
     * This hotel's evidence, a few bounded queries (no per-item lookups); every part is optional (a missing table = no evidence of that kind).
     * Returns ['sell' => id => ['per_unit','item','price','qty'], 'receipts' => id => [unit costs, newest first], 'packs' => id => [sizes]].
     */
    function import_cs_load_evidence(PDO $pdo): array
    {
        $ev = ['sell' => [], 'receipts' => [], 'packs' => []];
        try {
            $rows = $pdo->query('SELECT ri.ingredient_id, mi.item_name, mi.price, ri.quantity_per_portion, ri.yield_percent
                                   FROM stock_recipe_ingredients ri
                                   INNER JOIN stock_recipes sr ON sr.id = ri.recipe_id
                                   INNER JOIN menu_items mi ON mi.id = sr.menu_item_id
                                  WHERE mi.price > 0 AND ri.quantity_per_portion > 0
                                  LIMIT 60000')->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $r) {
                $y = (float)$r[4];
                $perUnit = (float)$r[2] * ($y > 0 ? $y / 100.0 : 1.0) / (float)$r[3];
                $id = (int)$r[0];
                if (!isset($ev['sell'][$id]) || $perUnit > $ev['sell'][$id]['per_unit']) {
                    $ev['sell'][$id] = ['per_unit' => $perUnit, 'item' => (string)$r[1], 'price' => (float)$r[2], 'qty' => (float)$r[3]];
                }
            }
        } catch (Throwable $e) {
            // no menu / recipe tables: no selling-price evidence
        }
        try {
            foreach ($pdo->query('SELECT ingredient_id, cost_per_unit FROM stock_in_log WHERE cost_per_unit > 0 ORDER BY id DESC LIMIT 60000')->fetchAll(PDO::FETCH_NUM) as $r) {
                if (count($ev['receipts'][(int)$r[0]] ?? []) < 30) {
                    $ev['receipts'][(int)$r[0]][] = (float)$r[1];
                }
            }
        } catch (Throwable $e) {
            // no deliveries recorded
        }
        foreach (['SELECT ingredient_id, pack_size FROM stock_ingredient_barcodes WHERE pack_size >= 2',
                     'SELECT ingredient_id, pack_size_snapshot FROM stock_purchase_order_items WHERE pack_size_snapshot >= 2 AND ingredient_id IS NOT NULL'] as $sql) {
            try {
                foreach ($pdo->query($sql . ' LIMIT 60000')->fetchAll(PDO::FETCH_NUM) as $r) {
                    $w = import_cs_whole_pack($r[1]);
                    if ($w !== null) {
                        $ev['packs'][(int)$r[0]][$w] = $w;
                    }
                }
            } catch (Throwable $e) {
                // no barcodes / purchase orders
            }
        }
        return $ev;
    }
}
