<?php

/**
 * Drinks profit P3 "Projected vs actual" (owner 2026-11-19/20): between two approved stock counts, how much of each drink
 * went with nothing to show for it (free pours, spillage, missing stock), and what that is worth. Read-only: SELECT only.
 *
 *   rh_spva_load(PDO $pdo, string $from, string $to)   the drinks (P2's rh_spp_load), their approved counts in the range,
 *                                                      the stock movements between each item's counts and the till lines
 *   rh_spva_build(array $src, float $vatRate, bool $vatOut)   rows and totals (pure arithmetic, no database)
 *
 * Why counts: a till sale is what takes a drink off stock, so "used x menu price" against "till takings" can never show a free
 * pour. Only a count can. Per stock item (snapped PER ITEM: spot and category counts cover only some items):
 *   A = its first approved count line in the range, B = its last (count time = the line's counted_at, else the approval time);
 *   movements = every stock_adjustments row in (A, B] (the same window rule as stock-count.php scMovementsBetween), leaving out
 *               count postings ('variance') and count measure corrections (rh_stock_measure_fix_sql): the counts ARE the readings;
 *   gap (unaccounted) = A's reading + movements - B's reading. Approval posts each line's variance against the stock at its count
 *               time, so this equals minus the variances of the approved counts after A up to and including B (A's own variance,
 *               e.g. an opening count, is not in it). Both are worked out; 'check_units' carries the second for the test.
 *   sold      = till + room service ('pos_order'/'room_service' not voided-to-wastage) minus 'void_restore' (Stock Reports usage rule);
 *   wastage   = 'wastage', 'recall', 'expiry' + voided-to-wastage sales (Stock Reports yield rule); recorded wastage is NOT gap;
 *   received  = 'stock_in' + stock added by hand; taken off by hand = negative 'manual' that is not a count correction;
 *   actual used = A + received - B = sold + wastage + taken off by hand + other + gap.
 * Value: each stock item once, at the menu price of its single-stock drink that brings in the least per stock unit (P2's rule,
 * picked on price x measures per unit, so a sold-out item is not left to the lowest till id). Cost = the item's current cost per
 * unit (null = not known, never 0). VAT out = gross / (1 + rate) like the till (rh_spp_net).
 * Till line: the till lines whose stock came off in the window (stock_order_items via pos_order source_id; booking_charges via
 * room_service source_id), only for till items that are this item's single-stock drinks: takings = line_total less the order's
 * discount share; menu = quantity x today's menu price. Mixed drinks (cocktails) take stock too but cannot be priced per item.
 */

require_once __DIR__ . '/stock-projected-profit.php';

if (!function_exists('rh_spva_load')) {

    /** Does a table have a column (cached per request)? */
    function rh_spva_has_col(PDO $pdo, string $table, string $col): bool
    {
        static $cache = [];
        $k = $table . '.' . $col;
        if (!isset($cache[$k])) {
            $t = preg_replace('/[^a-z0-9_]/i', '', $table);
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$t, $col]);
            $cache[$k] = (int)$st->fetchColumn() > 0;
        }
        return $cache[$k];
    }

    /**
     * Read what the report needs (SELECT only). $from / $to are Y-m-d (inclusive days).
     * @return array{spp:array, counts:array<int,array>, moves:array<int,array>, till:array<int,array>, from:string, to:string}
     */
    function rh_spva_load(PDO $pdo, string $from, string $to): array
    {
        $spp = rh_spp_load($pdo);
        $priced = [];
        foreach ($spp['links'] as $l) {
            $priced[(int)$l['ingredient_id']] = true;
        }

        // Approved count lines in the range, per stock item, oldest first.
        $at = rh_spva_has_col($pdo, 'stock_count_lines', 'counted_at') ? 'COALESCE(scl.counted_at, sc.approved_at)' : 'sc.approved_at';
        $cq = $pdo->prepare(
            "SELECT scl.ingredient_id, scl.count_id, scl.actual_quantity, scl.variance, sc.reference, sc.is_opening, {$at} AS counted
               FROM stock_count_lines scl
               JOIN stock_counts sc ON sc.id = scl.count_id AND sc.status = 'approved'
              WHERE {$at} >= ? AND {$at} <= ?
              ORDER BY scl.ingredient_id, counted, scl.count_id, scl.id"
        );
        $cq->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        $counts = [];
        foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['ingredient_id'];
            if (!isset($priced[$id])) {
                continue;
            }
            $counts[$id][] = [
                'count_id' => (int)$r['count_id'],
                'reference' => (string)$r['reference'],
                'is_opening' => (int)($r['is_opening'] ?? 0) === 1,
                'at' => (string)$r['counted'],
                'actual' => (float)$r['actual_quantity'],
                'variance' => (float)$r['variance'],
            ];
        }

        $vw = rh_voided_wastage_sql('sa');
        $fix = rh_stock_measure_fix_sql('sa');
        $mq = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN (sa.source_type IN ('pos_order','room_service') AND NOT {$vw}) OR sa.source_type = 'void_restore' THEN -sa.quantity_change ELSE 0 END), 0) AS sold,
                COALESCE(SUM(CASE WHEN sa.source_type IN ('wastage','recall','expiry') OR (sa.source_type IN ('pos_order','room_service') AND {$vw}) THEN -sa.quantity_change ELSE 0 END), 0) AS wasted,
                COALESCE(SUM(CASE WHEN sa.source_type = 'stock_in' OR (sa.source_type = 'manual' AND sa.quantity_change > 0) THEN sa.quantity_change ELSE 0 END), 0) AS received,
                COALESCE(SUM(CASE WHEN sa.source_type = 'manual' AND sa.quantity_change < 0 THEN -sa.quantity_change ELSE 0 END), 0) AS manual_out,
                COALESCE(SUM(CASE WHEN sa.source_type NOT IN ('pos_order','room_service','void_restore','wastage','recall','expiry','stock_in','manual') THEN -sa.quantity_change ELSE 0 END), 0) AS other_out,
                COALESCE(SUM(sa.quantity_change), 0) AS net
               FROM stock_adjustments sa
              WHERE sa.ingredient_id = ? AND sa.created_at > ? AND sa.created_at <= ?
                AND sa.source_type <> 'variance' AND NOT {$fix}"
        );
        $hasRestoreOf = rh_spva_has_col($pdo, 'stock_adjustments', 'restore_of_type');
        $lq = $pdo->prepare(
            "SELECT sa.source_type, sa.source_id, " . ($hasRestoreOf ? 'sa.restore_of_type' : 'NULL') . " AS restore_of_type,
                    SUM(sa.quantity_change) AS qty
               FROM stock_adjustments sa
              WHERE sa.ingredient_id = ? AND sa.created_at > ? AND sa.created_at <= ? AND sa.source_id IS NOT NULL
                AND ((sa.source_type IN ('pos_order','room_service') AND NOT {$vw}) OR sa.source_type = 'void_restore')
              GROUP BY sa.source_type, sa.source_id, restore_of_type"
        );

        $moves = [];
        $till = [];
        foreach ($counts as $id => $cl) {
            if (count($cl) < 2) {
                continue;
            }
            $tA = $cl[0]['at'];
            $tB = $cl[count($cl) - 1]['at'];
            $mq->execute([$id, $tA, $tB]);
            $m = $mq->fetch(PDO::FETCH_ASSOC) ?: [];
            $moves[$id] = array_map('floatval', $m);

            // Till lines whose stock came off in (A, B]: units taken and put back, per line.
            $lq->execute([$id, $tA, $tB]);
            $per = [];
            foreach ($lq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $type = (string)$r['source_type'];
                if ($type === 'void_restore') {
                    $of = (string)($r['restore_of_type'] ?? '');
                    $type = $of === 'room_service' ? 'room_service' : 'pos_order'; // NULL (older rows) = till, as pos-order-delete.php reads it
                    $key = $type . ':' . (int)$r['source_id'];
                    $per[$key]['restored'] = ($per[$key]['restored'] ?? 0.0) + (float)$r['qty'];
                } else {
                    $key = $type . ':' . (int)$r['source_id'];
                    $per[$key]['taken'] = ($per[$key]['taken'] ?? 0.0) - (float)$r['qty'];
                }
                $per[$key]['kind'] = $type;
                $per[$key]['line_id'] = (int)$r['source_id'];
            }
            $till[$id] = rh_spva_till_lines($pdo, $per);
        }

        return ['spp' => $spp, 'counts' => $counts, 'moves' => $moves, 'till' => $till, 'from' => $from, 'to' => $to];
    }

    /** The till / room-service lines behind $per (key "kind:id" => taken, restored), with what was charged for each. */
    function rh_spva_till_lines(PDO $pdo, array $per): array
    {
        $pos = [];
        $rs = [];
        foreach ($per as $p) {
            if (($p['kind'] ?? '') === 'room_service') {
                $rs[] = (int)$p['line_id'];
            } else {
                $pos[] = (int)$p['line_id'];
            }
        }
        $info = [];
        if ($pos) {
            $st = $pdo->prepare(
                "SELECT soi.id, soi.menu_item_id, soi.quantity, soi.line_total, soi.kds_status, so.subtotal, so.discount_amount, so.status
                   FROM stock_order_items soi
                   LEFT JOIN stock_orders so ON so.id = soi.order_id
                  WHERE soi.id IN (" . implode(',', array_fill(0, count($pos), '?')) . ")"
            );
            $st->execute($pos);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sub = (float)($r['subtotal'] ?? 0);
                $disc = (float)($r['discount_amount'] ?? 0);
                $paid = !in_array((string)($r['status'] ?? ''), ['voided', 'cancelled', 'refunded'], true) && (string)($r['kds_status'] ?? '') !== 'void';
                $info['pos_order:' . (int)$r['id']] = [
                    'menu_item_id' => (int)$r['menu_item_id'], 'quantity' => (float)$r['quantity'], 'line_total' => (float)$r['line_total'],
                    'discount_share' => ($sub > BALANCE_TOLERANCE && $disc > BALANCE_TOLERANCE) ? min(1.0, $disc / $sub) : 0.0,
                    'kept' => $paid,
                ];
            }
        }
        if ($rs) {
            $st = $pdo->prepare(
                "SELECT id, source_item_id, quantity, line_total, voided FROM booking_charges
                  WHERE id IN (" . implode(',', array_fill(0, count($rs), '?')) . ")"
            );
            $st->execute($rs);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $info['room_service:' . (int)$r['id']] = [
                    'menu_item_id' => (int)($r['source_item_id'] ?? 0), 'quantity' => (float)$r['quantity'], 'line_total' => (float)$r['line_total'],
                    'discount_share' => 0.0, 'kept' => (int)($r['voided'] ?? 0) !== 1,
                ];
            }
        }
        $out = [];
        foreach ($per as $key => $p) {
            $out[] = [
                'kind' => (string)$p['kind'],
                'line_id' => (int)$p['line_id'],
                'taken' => (float)($p['taken'] ?? 0),
                'restored' => (float)($p['restored'] ?? 0),
                'line' => $info[$key] ?? null, // null = the till line is gone: its stock still counts as sold, it cannot be priced
            ];
        }
        return $out;
    }

    /**
     * Which of the item's drink lines values it: the one that brings in the least per stock unit (measures per unit x menu
     * price), ties = the lowest till id. $lines = rh_spp_build lines of ONE stock item.
     */
    function rh_spva_pick(array $lines): ?array
    {
        $best = null;
        foreach ($lines as $ln) {
            if ($best === null) {
                $best = $ln;
                continue;
            }
            $d = $ln['per_unit'] * $ln['price_gross'] - $best['per_unit'] * $best['price_gross'];
            if ($d < -BALANCE_TOLERANCE || (abs($d) <= BALANCE_TOLERANCE && $ln['menu_item_id'] < $best['menu_item_id'])) {
                $best = $ln;
            }
        }
        return $best;
    }

    /**
     * Rows and totals. Pure: no database.
     * @param array $src rh_spva_load() result (or the same shape built by hand)
     * @return array{rows:array, totals:array}
     */
    function rh_spva_build(array $src, float $vatRate, bool $vatOut): array
    {
        $rate = $vatOut ? max(0.0, $vatRate) : 0.0;
        $money = static fn(float $gross): float => $vatOut ? rh_spp_net($gross, $rate) : round($gross, 2);
        $spp = $src['spp'];
        $built = rh_spp_build($spp['items'], $spp['links'], $spp['kitchen'], 0.0, false, $spp['list'] ?? []);
        $byItem = [];
        foreach ($built['lines'] as $ln) {
            $byItem[$ln['ingredient_id']][] = $ln;
        }
        $eps = 0.000001;

        $rows = [];
        $t = ['gap_measures' => 0.0, 'gap_menu' => 0.0, 'gap_cost' => 0.0, 'cost_unknown' => 0, 'till_taken' => 0.0, 'till_menu' => 0.0, 'till_rows' => 0,
            'qualified' => 0, 'needs' => 0, 'with_gap' => 0, 'till_differs' => 0, 'till_partly' => 0];
        foreach ($byItem as $id => $lines) {
            $pick = rh_spva_pick($lines);
            $ing = $spp['items'][$id] ?? [];
            $cl = $src['counts'][$id] ?? [];
            $row = [
                'ingredient_id' => (int)$id,
                'stock_name' => (string)($ing['name'] ?? ''),
                'unit' => (string)($ing['unit'] ?? ''),
                'drink' => (string)$pick['drink'],
                'sale' => (string)$pick['sale'],
                'per_unit' => (float)$pick['per_unit'],
                'price_gross' => (float)$pick['price_gross'],
                'price' => $money((float)$pick['price_gross']),
                'drinks' => count($lines),
                'counts_in_range' => count($cl),
                'qualified' => count($cl) >= 2,
            ];
            if (!$row['qualified']) {
                $row['last_count'] = $cl ? $cl[count($cl) - 1] : null;
                $rows[] = $row;
                $t['needs']++;
                continue;
            }
            $A = $cl[0];
            $B = $cl[count($cl) - 1];
            $m = $src['moves'][$id] ?? [];
            $received = (float)($m['received'] ?? 0);
            $sold = (float)($m['sold'] ?? 0);
            $wasted = (float)($m['wasted'] ?? 0);
            $manualOut = (float)($m['manual_out'] ?? 0);
            $other = (float)($m['other_out'] ?? 0);
            $net = (float)($m['net'] ?? ($received - $sold - $wasted - $manualOut - $other));
            $gap = round($A['actual'] + $net - $B['actual'], 6);
            $check = 0.0;
            for ($i = 1, $n = count($cl); $i < $n; $i++) {
                $check -= $cl[$i]['variance'];
            }
            if (abs($gap) < $eps) {
                $gap = 0.0;
            }
            $used = round($A['actual'] + $received - $B['actual'], 6);
            $pu = $row['per_unit'];
            $cpu = (isset($ing['cost_per_unit']) && is_numeric($ing['cost_per_unit']) && (float)$ing['cost_per_unit'] > 0) ? (float)$ing['cost_per_unit'] : null;
            $gapMenu = $money($gap * $pu * $row['price_gross']);
            $gapCost = $cpu !== null ? round($gap * $cpu, 2) : null;

            // Till line: only lines of this item's own single-stock drinks can be priced.
            $prices = [];
            foreach ($lines as $ln) {
                $prices[$ln['menu_item_id']] = (float)$ln['price_gross'];
            }
            $taken = 0.0;
            $menu = 0.0;
            $tied = 0;
            $untiedUnits = 0.0;
            foreach ($src['till'][$id] ?? [] as $tl) {
                $gross = (float)$tl['taken'];
                $left = $gross - (float)$tl['restored'];
                if ($gross <= $eps || $left <= $eps) {
                    continue; // put back in full, or a put-back of an earlier sale
                }
                $frac = min(1.0, $left / $gross);
                $line = $tl['line'];
                if ($line === null || !isset($prices[(int)$line['menu_item_id']])) {
                    $untiedUnits += $left;
                    continue;
                }
                $tied++;
                if ($line['kept']) {
                    $taken += (float)$line['line_total'] * (1 - (float)$line['discount_share']) * $frac;
                }
                $menu += (float)$line['quantity'] * $frac * $prices[(int)$line['menu_item_id']];
            }
            $tillTaken = $tied ? $money($taken) : null;
            $tillMenu = $tied ? $money($menu) : null;
            $drift = $tied ? round($tillTaken - $tillMenu, 2) : null;

            $row += [
                'opening' => $A, 'closing' => $B,
                'opening_qty' => $A['actual'], 'closing_qty' => $B['actual'],
                'received' => $received, 'sold_units' => $sold, 'wasted_units' => $wasted, 'manual_out_units' => $manualOut, 'other_units' => $other,
                'used_units' => $used, 'gap_units' => $gap, 'check_units' => round($check, 6),
                'used' => $used * $pu, 'sold' => $sold * $pu, 'wasted' => $wasted * $pu, 'manual_out' => ($manualOut + $other) * $pu, 'gap' => $gap * $pu,
                'gap_menu' => $gapMenu, 'gap_cost' => $gapCost, 'cost_known' => $cpu !== null, 'cost_per_unit' => $cpu,
                'till_taken' => $tillTaken, 'till_menu' => $tillMenu, 'till_drift' => $drift, 'till_lines' => $tied,
                'till_untied' => $untiedUnits * $pu, 'till_partly' => $untiedUnits > $eps,
            ];
            $rows[] = $row;
            $t['qualified']++;
            $t['gap_measures'] += $row['gap'];
            $t['gap_menu'] += $gapMenu;
            if ($gapCost !== null) {
                $t['gap_cost'] += $gapCost;
            } else {
                $t['cost_unknown']++;
            }
            if ($gap > $eps) {
                $t['with_gap']++;
            }
            if ($tied) {
                $t['till_rows']++;
                $t['till_taken'] += $tillTaken;
                $t['till_menu'] += $tillMenu;
                if (abs($drift) > BALANCE_TOLERANCE) {
                    $t['till_differs']++;
                }
            }
            if ($row['till_partly']) {
                $t['till_partly']++;
            }
        }

        // Counted items first, the biggest unaccounted value at menu price first; then the ones that need a count, A to Z.
        usort($rows, static function (array $a, array $b): int {
            if ($a['qualified'] !== $b['qualified']) {
                return $a['qualified'] ? -1 : 1;
            }
            if ($a['qualified']) {
                $d = $b['gap_menu'] - $a['gap_menu'];
                if (abs($d) > BALANCE_TOLERANCE) {
                    return $d > 0 ? 1 : -1;
                }
            }
            return strcasecmp($a['stock_name'], $b['stock_name']) ?: ($a['ingredient_id'] <=> $b['ingredient_id']);
        });

        $t['gap_measures'] = round($t['gap_measures'], 2);
        $t['gap_menu'] = round($t['gap_menu'], 2);
        $t['gap_cost'] = round($t['gap_cost'], 2);
        $t['till_taken'] = round($t['till_taken'], 2);
        $t['till_menu'] = round($t['till_menu'], 2);
        $t['till_drift'] = round($t['till_taken'] - $t['till_menu'], 2);
        $t['vat_out'] = $vatOut && $rate > 0;
        $t['vat_rate'] = $vatRate;
        $t['items'] = count($rows);
        return ['rows' => $rows, 'totals' => $t];
    }
}
