<?php
/**
 * Import - "does this price look right for THIS hotel?" (pure: no database, no output, no model).
 *
 * There is no fixed ratio and no price limit written by policy anywhere in here. What counts as an unusual price comes from evidence
 * the hotel's own data gives, judged on a log scale (so "twice as much" and "half as much" are the same size of surprise):
 *   1. the item's OWN purchase history (batch unit costs, approved opening counts) - the item's own spread, when it has 3+ points;
 *   2. otherwise how far prices of SIMILAR items (same category) usually move between successive costs, then the same across the
 *      whole hotel - so a high-inflation hotel tolerates bigger moves on its own;
 *   3. for an item that is not in stock yet: the prices of items in the same category (and unit) that ARE in stock.
 * No evidence = no question from this check. Structural signals (an extra zero, the pack size, a thousands separator, columns
 * swapped, kg vs g) need only the current cost and make the question's wording more helpful; they are computed, not configured.
 * It only ever ASKS - a person decides. The numbers behind each question are returned as `evidence` so a helper (or the screen)
 * can explain it truthfully.
 *
 * Generic statistics only (none of these are hotel facts): "tolerance" = k x robust spread with k = 3 (the usual three-sigma
 * rule; spread = 1.4826 x median absolute deviation of the logs, or the median size of a successive price move); a spread under
 * 2 % in log terms is treated as 2 % (rounding noise, so identical history does not make every change look huge); evidence
 * needs 3 own points / 5 moves / 5 peers; a structural match is a ratio within 5 % of the pattern (average costs are never exact).
 */

if (!function_exists('import_pc_params')) {

    /** The generic statistics parameters (a caller or test may pass `price_check_params` in the ctx to change one). */
    function import_pc_params(array $ctx = []): array
    {
        return array_merge(['k' => 3.0, 'min_own' => 3, 'min_moves' => 5, 'min_peers' => 5, 'spread_floor' => 0.02, 'struct_tol' => 0.05, 'history_cap' => 30],
            (array)($ctx['price_check_params'] ?? []));
    }

    /** 1.4826 x median absolute deviation: a spread that one odd point does not distort. */
    function import_pc_spread(array $logs): float
    {
        $m = import_median($logs);
        if ($m === null) {
            return 0.0;
        }
        $dev = array_map(function ($x) use ($m) {
            return abs($x - $m);
        }, $logs);
        return 1.4826 * (float)import_median($dev);
    }

    /** Only positive finite numbers, as floats. */
    function import_pc_positive(array $nums): array
    {
        $o = [];
        foreach ($nums as $n) {
            if (is_numeric($n) && (float)$n > 0 && is_finite((float)$n)) {
                $o[] = (float)$n;
            }
        }
        return $o;
    }

    function import_pc_cat_key($category): string
    {
        return mb_strtolower(trim((string)$category));
    }

    /**
     * How big a successive price move usually is: the median size (log) of the non-zero moves between consecutive costs of each item.
     * $historyById: id => costs, oldest first. $categoryById: id => category.
     * Returns ['hotel' => ['v' => ?float, 'n' => int], 'category' => [catKey => ['v' => ?float, 'n' => int]]]; v is null below min_moves.
     */
    function import_pc_typical_moves(array $historyById, array $categoryById, array $p): array
    {
        $all = [];
        $byCat = [];
        foreach ($historyById as $id => $costs) {
            $c = array_values(import_pc_positive((array)$costs));
            for ($i = 1; $i < count($c); $i++) {
                $d = abs(log($c[$i] / $c[$i - 1]));
                if ($d < 1e-9) {
                    continue;
                }
                $all[] = $d;
                $byCat[import_pc_cat_key($categoryById[$id] ?? '')][] = $d;
            }
        }
        $pack = function (array $d) use ($p) {
            return ['v' => count($d) >= $p['min_moves'] ? import_median($d) : null, 'n' => count($d)];
        };
        $out = ['hotel' => $pack($all), 'category' => []];
        foreach ($byCat as $k => $d) {
            $out['category'][$k] = $pack($d);
        }
        return $out;
    }

    /** Is the number a whole count of 10 (an extra or missing zero, a misplaced decimal point)? Returns the exponent or null. */
    function import_pc_power_of_ten(float $lr, float $tol): ?int
    {
        $k = (int)round($lr / log(10));
        return ($k !== 0 && abs($lr - $k * log(10)) <= $tol) ? $k : null;
    }

    /** kg <-> g and L <-> ml: the same goods priced per a unit 1000 times smaller or larger (generic units of measure). */
    function import_pc_metric_pair(string $a, string $b): bool
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));
        return in_array([$a, $b], [['kg', 'g'], ['g', 'kg'], ['l', 'ml'], ['ml', 'l']], true);
    }

    /**
     * Judge one price. Input keys: new (per-unit price, after any per-case conversion), old (current stock cost or null),
     * history (the item's own earlier unit costs, oldest first), peers (costs of similar items already in stock - used for a NEW item
     * only), cat_move / hotel_move (['v','n'] from import_pc_typical_moves), own_pack (the item's pack size), pack_sizes (pack sizes
     * seen in this hotel), raw (the price cell text as written), qty (the quantity on the same row), unit_sheet / unit_existing.
     * Returns null when nothing is worth asking, else the evidence array (reason code, basis and every number used).
     */
    function import_pc_assess(array $in, array $p): ?array
    {
        $new = (float)($in['new'] ?? 0);
        if (!($new > 0)) {
            return null;
        }
        $old = (isset($in['old']) && is_numeric($in['old']) && (float)$in['old'] > 0) ? (float)$in['old'] : null;
        if ($old !== null && abs($new - $old) <= import_money_tolerance()) {
            return null;        // the same price as now: nothing to ask
        }
        $hist = import_pc_positive((array)($in['history'] ?? []));
        $hist = array_slice($hist, -$p['history_cap']);
        $peers = import_pc_positive((array)($in['peers'] ?? []));
        $k = (float)$p['k'];
        $floor = (float)$p['spread_floor'];

        // ---- how far from usual is "too far" (log scale), from the best evidence available ----
        $basis = 'none';
        $center = null;
        $tol = null;
        $evN = 0;
        $peerMedian = null;
        if ($old !== null) {
            if (count($hist) >= $p['min_own']) {
                $logs = array_map('log', $hist);
                $sp = import_pc_spread($logs);
                if ($sp > 1e-9) {
                    $basis = 'own-history';
                    $center = exp((float)import_median($logs));
                    $tol = $k * max($sp, $floor);
                    $evN = count($hist);
                }
            }
            if ($basis === 'none') {
                foreach ([['category-moves', $in['cat_move'] ?? null], ['hotel-moves', $in['hotel_move'] ?? null]] as [$b, $mv]) {
                    if (is_array($mv) && $mv['v'] !== null) {
                        $basis = $b;
                        $center = $old;
                        $tol = $k * max((float)$mv['v'], $floor);
                        $evN = (int)$mv['n'];
                        break;
                    }
                }
            }
        } elseif (count($peers) >= $p['min_peers']) {
            $logs = array_map('log', $peers);
            $basis = 'category-peers';
            $center = exp((float)import_median($logs));
            $peerMedian = $center;
            $tol = $k * max(import_pc_spread($logs), $floor);
            $evN = count($peers);
        }
        $outside = ($tol !== null && $center > 0) ? abs(log($new / $center)) > $tol : false;

        // ---- structural signals (need the current cost) ----
        $reason = null;
        $extra = [];
        $r = $old !== null ? $new / $old : null;
        if ($old !== null) {
            $lr = log($r);
            $st = (float)$p['struct_tol'];
            $raw = trim((string)($in['raw'] ?? ''));
            $ownPack = isset($in['own_pack']) ? (float)$in['own_pack'] : 0.0;
            $packs = array_values(array_unique(array_filter(array_map('floatval', (array)($in['pack_sizes'] ?? [])), function ($x) {
                return $x >= 2;
            })));
            $isThousands = $raw !== '' && preg_match('/^\d{1,3}\.\d{3}$/', $raw) && abs(log($new * 1000 / $old)) <= $st;
            if ($isThousands) {
                $reason = 'thousands-separator';
            } elseif ($ownPack >= 2 && abs($lr - log($ownPack)) <= $st) {
                $reason = 'pack-size-up';
                $extra = ['pack_size' => $ownPack, 'pack_is_own' => true];
            } elseif ($ownPack >= 2 && abs($lr + log($ownPack)) <= $st) {
                $reason = 'pack-size-down';
                $extra = ['pack_size' => $ownPack, 'pack_is_own' => true];
            } elseif (($k10 = import_pc_power_of_ten($lr, $st)) !== null) {
                $reason = $k10 === 1 ? 'extra-zero' : ($k10 === -1 ? 'missing-zero' : 'power-of-ten');
                $extra['exponent'] = $k10;
            } else {
                foreach ($packs as $ps) {
                    if (abs($lr - log($ps)) <= $st) {
                        $reason = 'pack-size-up';
                        $extra = ['pack_size' => $ps, 'pack_is_own' => false];
                        break;
                    }
                    if (abs($lr + log($ps)) <= $st) {
                        $reason = 'pack-size-down';
                        $extra = ['pack_size' => $ps, 'pack_is_own' => false];
                        break;
                    }
                }
            }
        }
        if ($reason === null && !$outside) {
            return null;
        }
        // the price equals the quantity on the same row: only worth saying when the price already looks off
        $qty = isset($in['qty']) && is_numeric($in['qty']) ? (float)$in['qty'] : null;
        if ($qty !== null && $qty > 0 && abs($new - $qty) < import_money_tolerance()) {
            $reason = 'swapped-columns';
        }
        if ($reason === null) {
            $reason = 'outside-usual-range';
        }
        $ev = [
            'reason' => $reason, 'basis' => $basis, 'old' => $old, 'new' => $new, 'ratio' => $r,
            'factor' => $r !== null ? max($r, 1 / $r) : null, 'direction' => $r !== null ? ($r >= 1 ? 'higher' : 'lower') : null,
            'history' => array_slice($hist, -12), 'history_n' => count($hist), 'history_min' => $hist ? min($hist) : null, 'history_max' => $hist ? max($hist) : null,
            'center' => $center, 'peer_median' => $peerMedian, 'peer_n' => $basis === 'category-peers' ? $evN : 0,
            'evidence_n' => $evN, 'tolerance_log' => $tol, 'tolerance_factor' => $tol !== null ? exp($tol) : null, 'outside_usual' => $outside,
            'qty' => $qty,
        ];
        return $ev + $extra;
    }

    /** "10", "1.5", "about 1,000": how many times, as people say it. */
    function import_pc_times($f): string
    {
        $f = (float)$f;
        if ($f >= 100) {
            return number_format(round($f), 0, '.', ',');
        }
        $s = number_format($f, 1, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    /**
     * The plain-English question for an assessment. $name is the stock name, $newTxt the formatted per-unit price, $src its cell,
     * $category the category (new items). Always says the prices are as written (VAT neither added nor removed).
     */
    function import_pc_question_text(array $ev, string $name, string $src, string $category = ''): string
    {
        $new = import_fmt_money($ev['new']);
        $vat = ' Prices are shown as written, VAT not added or removed.';
        if ($ev['old'] === null) {
            return '"' . $name . '" ' . (!empty($ev['no_cost_yet']) ? 'has no cost in stock yet' : 'is new in stock') . '. The file says ' . $new . ' (' . $src . '), far from what similar items in "' . $category . '" cost (around '
                . import_fmt_money(round((float)$ev['peer_median'], 4)) . ', from ' . (int)$ev['peer_n'] . ' items already in stock). Is ' . $new . ' right?' . $vat;
        }
        $head = '"' . $name . '" costs ' . import_fmt_money($ev['old']) . ' now; the file says ' . $new . ' (' . $src . ') - that is ' . import_pc_times($ev['factor']) . ' times ' . $ev['direction'];
        switch ($ev['reason']) {
            case 'extra-zero':
                $why = ', which often means an extra zero';
                break;
            case 'missing-zero':
                $why = ', which often means a missing zero';
                break;
            case 'power-of-ten':
                $why = ', which often means a misplaced decimal point or extra or missing zeros';
                break;
            case 'thousands-separator':
                $why = ', which often means a thousands separator was read as a decimal point (2.875 instead of 2,875)';
                break;
            case 'pack-size-up':
                // a pack size is named only when THIS item has its own pack size; another item's pack count is just a hint
                $why = !empty($ev['pack_is_own'])
                    ? ', about the ' . (int)$ev['pack_size'] . ' units in its pack: this may be a case price typed as a unit price'
                    : ', which may be a case price or a typing slip';
                break;
            case 'pack-size-down':
                $why = !empty($ev['pack_is_own'])
                    ? ', about one ' . (int)$ev['pack_size'] . 'th: this may be a unit price where the item is priced per case'
                    : ', which may be a unit price where the item is priced per case, or a typing slip';
                break;
            case 'swapped-columns':
                $why = ', and it equals the quantity on the same row: the price and quantity columns may be swapped';
                break;
            default:
                if ($ev['basis'] === 'own-history') {
                    $why = ', more than its price usually varies (this hotel has paid ' . import_fmt_money($ev['history_min']) . ' to ' . import_fmt_money($ev['history_max']) . ' over ' . (int)$ev['history_n'] . ' deliveries)';
                } else {
                    $why = ', a bigger move than this hotel usually sees between prices of ' . ($ev['basis'] === 'category-moves' ? 'similar items' : 'its items');
                }
        }
        return $head . $why . '. Is ' . $new . ' right?' . $vat;
    }

    /** A kg/g (or L/ml) unit mix-up: a short plain hint to add to the unit question, or ''. */
    function import_pc_unit_hint(string $sheetUnit, string $existingUnit, $newCost, $oldCost): string
    {
        if (!import_pc_metric_pair($sheetUnit, $existingUnit) || !is_numeric($newCost) || !is_numeric($oldCost) || (float)$newCost <= 0 || (float)$oldCost <= 0) {
            return '';
        }
        $lr = abs(log((float)$newCost / (float)$oldCost));
        if (abs($lr - 3 * log(10)) > 0.25) {
            return '';
        }
        return ' The price also differs by about 1,000 times, which is what mixing ' . $sheetUnit . ' and ' . $existingUnit . ' looks like: the file may price per ' . $sheetUnit . ' while stock is counted per ' . $existingUnit . '.';
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The PHYSICAL limit. This is a storage fact, not a policy: a cost that does not fit the database column cannot be saved
    // (and one overflowing value rolls the whole commit back), so only that is blocked. The limits are read from the real column
    // definitions (INFORMATION_SCHEMA, see import_cost_limits() in review.php) and passed in `ctx['cost_limits']`.

    /** Largest value a DECIMAL(p,s) column holds: 10^(p-s) - 10^-s. */
    function import_decimal_max(int $precision, int $scale): float
    {
        return pow(10, $precision - $scale) - pow(10, -$scale);
    }

    /**
     * The storable maximums: ['cost' => unit cost, 'line_value' => balance x cost of an opening-count line (null = unknown)].
     * Without a readable column the old stock-count rule (99,999,999) is the fallback for the cost; the line value is then not checked.
     */
    function import_cost_limits_from_ctx(array $ctx): array
    {
        $l = (array)($ctx['cost_limits'] ?? []);
        return [
            'cost' => isset($l['cost']) && is_numeric($l['cost']) && $l['cost'] > 0 ? (float)$l['cost'] : 99999999.0,
            'line_value' => isset($l['line_value']) && is_numeric($l['line_value']) && $l['line_value'] > 0 ? (float)$l['line_value'] : null,
            'from_database' => !empty($l['from_database']),
        ];
    }

    /** Plain reasons a ready/resolved item cannot be stored (empty = fits). Used on the review screen AND again at commit. */
    function import_cost_storage_problems(array $it, array $lim): array
    {
        $p = [];
        $cost = ($it['cost_applied'] ?? true) ? ($it['cost'] ?? null) : null;      // a cost that will not be written cannot overflow anything
        $tol = import_money_tolerance();
        if ($cost !== null && is_numeric($cost) && (float)$cost > $lim['cost'] + $tol) {
            $p[] = 'price ' . import_fmt_money($cost) . (!empty($it['pack']) ? ' each (the case price divided by ' . (int)$it['pack'] . ')' : '')
                . ' is too large for a stock cost (the cost column cannot hold more than ' . import_fmt_money($lim['cost']) . ')';
        }
        if (!empty($it['in_count']) && $lim['line_value'] !== null) {
            $lc = $it['line_cost'] ?? $cost;
            $bal = $it['balance'] ?? null;
            if ($lc !== null && $bal !== null && is_numeric($lc) && is_numeric($bal) && (float)$bal * (float)$lc > $lim['line_value'] + $tol) {
                $p[] = 'the opening stock value (' . import_fmt_qty($bal) . ' x ' . import_fmt_money($lc) . ') is too large to store in the opening count';
            }
        }
        return $p;
    }
}
