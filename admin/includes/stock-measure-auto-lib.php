<?php
/**
 * Fill count measures from bottle sizes (owner 2026-11-24, FEATURE; spec .claude/STOCK_MEASURE_AUTO_PLAN.md).
 *
 * Stock Items button "Fill from bottle sizes": for every active stock item, work out ONE count measure from what is already
 * known (the item's bottle size and the drink measures its menu items are sold in), show it per item first (nothing saved),
 * then save only the rows the person ticked. Sizes come only from the hotel's Drink measures list (rh_drink_measures_list()).
 * Hand-set measures always win: an item that already has another measure is shown UNticked and changes only when ticked.
 *
 * Per item (no guess work):
 *   - skip (with the reason): no stock unit; a weight or a size (ml / litre) as the stock unit; stock kept in the smaller measure;
 *     a crate / case unit; no bottle size; not sold in a drink measure on any menu item; sold in measures that are not all whole
 *     multiples of the smallest; the smallest is a size that is not on the list; N not exact (rh_smb_n_from_ml);
 *   - otherwise the candidate: bigger = the item's own unit, smaller = the smallest listed measure it is sold in,
 *     N = bottle ml / that measure's ml, stock kept in the bigger unit (quantities never change), decided by rh_smb_decide():
 *     set / same / different (kept unless ticked) / skip (lines on open counts typed in the old measure).
 * Apply: every ticked row through rh_stock_measure_save() in its own transaction under a row lock (as rh_smb_apply), the whole
 * apply refused when anything changed since the preview (fingerprint). Quantities, costs, batches and recipes never change.
 */

require_once __DIR__ . '/stock-measure-bulk-lib.php';
require_once __DIR__ . '/pos-drink-servings.php';

if (!function_exists('rh_sma_lines')) {

    /** Every recipe line (menu items' stock links) of these stock items: [ingredient_id => [line, ...]]. */
    function rh_sma_lines(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
        $entry = rh_stock_drink_ready($pdo) ? ', sri.entry_measure, sri.entry_qty, sri.entry_ml' : '';
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $st = $pdo->prepare("SELECT sri.ingredient_id, sri.quantity_per_portion $entry
                FROM stock_recipe_ingredients sri INNER JOIN stock_recipes sr ON sr.id = sri.recipe_id
                WHERE sri.quantity_per_portion > 0 AND sri.ingredient_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')
                ORDER BY sri.ingredient_id, sri.id');
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['ingredient_id']][] = $r;
            }
        }
        return $out;
    }

    /** The item without its count measure: what it is sold in is read from sizes and the list only, never from a hand-set measure. */
    function rh_sma_strip(array $ing): array
    {
        return array_merge($ing, ['measure_big_name' => null, 'measure_small_name' => null, 'measure_per_big' => null, 'measure_stock_side' => null]);
    }

    /** "30" / "12.5" with no thousands separator (rh_smb_num refuses "1,000"). */
    function rh_sma_num(float $v): string
    {
        return rh_stock_qty_fmt($v, 0, '');
    }

    /**
     * What one recipe line is sold in: ['kind' => 'named', 'name', 'ml' (one measure), 'idx' (place on the list)] | ['kind' => 'ml',
     * 'name' => '', 'ml' (the whole sale)] | null (a whole bottle or more, or nothing worth reading). $ing is already stripped.
     * The serving comes from rh_pos_serving_of (typed measure first, else the size); its name is never trusted on its own: a typed
     * list measure is used when it still gives the saved figure, else the list measure of the same size (first on the list).
     */
    function rh_sma_line_serving(array $line, array $ing, array $list): ?array
    {
        $q = (float)($line['quantity_per_portion'] ?? 0);
        if ($q <= 0 || $q >= 1 - 0.0000015) {
            return null; // a whole stock unit (one bottle) or more is not a pour
        }
        $s = rh_pos_serving_of($line, $ing, $list);
        if ($s === null || !isset($s['ml']) || $s['ml'] === null || (float)$s['ml'] <= 0) {
            return null;
        }
        if (trim((string)$s['name']) !== '' && rh_smb_word_same((string)$s['name'], (string)($ing['unit'] ?? ''))) {
            return null;
        }
        $ml = (float)$s['ml'];
        $e = rh_drink_line_entry($line, $ing, $list);
        if ($e !== null && !preg_match('/^(ml|mls)$/i', (string)$e['measure'])) {
            foreach ($list as $k => $dm) {
                if (mb_strtolower((string)$dm['name']) === mb_strtolower((string)$e['measure'])) {
                    return ['kind' => 'named', 'name' => (string)$dm['name'], 'ml' => (float)$dm['ml'], 'idx' => (int)$k];
                }
            }
        }
        foreach ($list as $k => $dm) {
            if (abs((float)$dm['ml'] - $ml) < 0.005) {
                return ['kind' => 'named', 'name' => (string)$dm['name'], 'ml' => (float)$dm['ml'], 'idx' => (int)$k];
            }
        }
        return ['kind' => 'ml', 'name' => '', 'ml' => round($ml, 2), 'idx' => PHP_INT_MAX];
    }

    /** "shot", "50 ml" */
    function rh_sma_serving_word(array $s): string
    {
        return $s['kind'] === 'named' ? (string)$s['name'] : rh_sma_num((float)$s['ml']) . ' ml';
    }

    /**
     * The one measure for this item, or why there is none: ['ok' => bool, 'why' => string, 'small' => ?string, 'n' => ?float,
     * 'sold' => "shot, double"]. $lines = its recipe lines; $list = the hotel's Drink measures list.
     */
    function rh_sma_candidate(array $ing, array $lines, array $list): array
    {
        $unit = trim((string)($ing['unit'] ?? ''));
        $bad = static fn(string $why, string $sold = ''): array => ['ok' => false, 'why' => $why, 'small' => null, 'n' => null, 'sold' => $sold];
        if ($unit === '') {
            return $bad('it has no stock unit');
        }
        if (rh_drink_unit_is_weight($unit)) {
            return $bad('its stock is kept in ' . $unit . ', a weight: drink measures do not apply');
        }
        if (rh_drink_unit_ml($unit) !== null) {
            return $bad('its stock is kept in ' . $unit . ', not in bottles: there is nothing to count in smaller measures');
        }
        $own = rh_stock_measure_get($ing);
        if ($own !== null && $own['side'] === 'small') {
            return $bad('its stock is kept in ' . rh_stock_measure_plural((string)$own['small'], 2) . ' (a count measure set by hand): left as it is');
        }
        if (rh_stock_unit_is_container($unit)) {
            return $bad('one ' . $unit . ' holds several bottles, so a bottle size does not say how many measures are in it: set it yourself');
        }
        $plain = rh_sma_strip($ing);
        $size = rh_drink_item_ml($plain);
        if ($size === null || $size['container'] === null || (float)$size['container'] <= 0) {
            return $bad('No bottle size: set Bottle size on the item, then run again');
        }
        $servings = [];
        foreach ($lines as $line) {
            $s = rh_sma_line_serving($line, $plain, $list);
            if ($s === null) {
                continue;
            }
            $key = $s['kind'] === 'named' ? 'n:' . mb_strtolower($s['name']) : 'm:' . rh_sma_num((float)$s['ml']);
            $servings[$key] = $s;
        }
        if (!$servings) {
            return $bad('Not sold in a drink measure on any menu item');
        }
        uasort($servings, static fn(array $a, array $b): int => ($a['ml'] <=> $b['ml']) ?: ($a['idx'] <=> $b['idx']));
        $servings = array_values($servings);
        $sold = implode(', ', array_map('rh_sma_serving_word', $servings));
        $minMl = (float)$servings[0]['ml'];
        foreach ($servings as $s) {
            $r = (float)$s['ml'] / $minMl;
            if (abs($r - round($r)) > 0.000001) {
                $words = array_map('rh_sma_serving_word', $servings);
                $last = array_pop($words);
                return $bad('Sold as ' . ($words ? implode(', ', $words) . ' and ' : '') . $last . ': set it yourself', $sold);
            }
        }
        $pick = null;
        foreach ($servings as $s) {
            if (abs((float)$s['ml'] - $minMl) < 0.005 && $s['kind'] === 'named') {
                $pick = $s; // sorted by list place within the same size: the first on the list
                break;
            }
        }
        if ($pick === null) {
            return $bad('Sold in ' . rh_sma_num($minMl) . ' ml, which is not on the Drink measures list: set it yourself', $sold);
        }
        [$n, $err] = rh_smb_n_from_ml(rh_sma_num((float)$size['container']), rh_sma_num((float)$pick['ml']), (string)$pick['name'], mb_strtolower($unit));
        if ($n === null) {
            return $bad(rtrim((string)$err, '.'), $sold);
        }
        // Sold in more than one measure (shot + double, shot + glass of wine...): two possible answers, so the row starts unticked
        // with this note (orchestrator decision 2026-11-24 on the owner's "it skips / you decide").
        $also = [];
        foreach ($servings as $s) {
            if ($s === $pick) {
                continue;
            }
            $times = round((float)$s['ml'] / $minMl);
            $also[] = rh_sma_serving_word($s) . ' (= ' . rh_sma_num($times) . ' ' . rh_drink_plural((string)$pick['name'], $times) . ')';
        }
        $note = '';
        if ($also) {
            $lastAlso = array_pop($also);
            $note = 'Also sold as ' . ($also ? implode(', ', $also) . ' and ' : '') . $lastAlso . ': check before ticking';
        }
        return ['ok' => true, 'why' => '', 'small' => (string)$pick['name'], 'n' => $n, 'sold' => $sold, 'note' => $note];
    }

    /** Every active stock item (the rows the list shows), archived left out. */
    function rh_sma_items(PDO $pdo): array
    {
        return array_values(array_filter(rh_smb_list_rows($pdo), static fn(array $r): bool => (int)$r['is_archived'] !== 1));
    }

    /**
     * The preview: one row per item. status set (ticked) | same | different (unticked, "currently X, would become Y") | skip.
     * Returns ['rows', 'counts', 'fingerprint'].
     */
    function rh_sma_plan(PDO $pdo, array $items, array $list): array
    {
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $items);
        $lines = rh_sma_lines($pdo, $ids);
        $open = rh_smb_open_lines($pdo, $ids);
        $rows = [];
        $counts = ['set' => 0, 'same' => 0, 'different' => 0, 'skip' => 0];
        $fp = [];
        foreach ($items as $ing) {
            $id = (int)$ing['id'];
            $c = rh_sma_candidate($ing, $lines[$id] ?? [], $list);
            $old = rh_stock_measure_get($ing);
            $d = ['status' => 'skip', 'why' => $c['why'], 'old' => $old, 'new' => null];
            $spec = null;
            if ($c['ok']) {
                $spec = ['big' => '', 'small' => $c['small'], 'n' => $c['n'], 'side' => 'big', 'change_too' => true];
                $d = rh_smb_decide($ing, $spec, (int)($open[$id] ?? 0));
                if ($d['status'] === 'change') {
                    $d['status'] = 'different'; // hand-set measures stay unless this row is ticked
                }
            }
            $counts[$d['status']]++;
            $qty = (float)$ing['current_quantity'];
            $after = $d['new'] !== null && in_array($d['status'], ['set', 'different'], true) ? array_merge($ing, ['measure_big_name' => $d['new']['big'],
                'measure_small_name' => $d['new']['small'], 'measure_per_big' => $d['new']['n'], 'measure_stock_side' => $d['new']['side']]) : $ing;
            $rows[] = [
                'id' => $id,
                'name' => (string)$ing['name'],
                'unit' => (string)$ing['unit'],
                'status' => $d['status'],
                'tick' => $d['status'] === 'set' && ($c['note'] ?? '') === '', // more than one measure: unticked, check first
                'why' => (string)$d['why'],
                'note' => in_array($d['status'], ['set', 'different'], true) ? (string)($c['note'] ?? '') : '',
                'sold' => $c['sold'],
                'old' => $d['old'] ? rh_smb_measure_text($d['old']) : '',
                'new' => $d['new'] ? rh_smb_measure_text($d['new']) : '',
                'qty_now' => rh_stock_qty_words($qty, $ing, (string)$ing['unit']),
                'qty_after' => rh_stock_qty_words($qty, $after, (string)$ing['unit']),
                'qty_exact' => rh_stock_qty_fmt($qty, 0) . ' ' . (string)$ing['unit'],
                '_old' => $d['old'],
                '_new' => $d['new'],
                '_spec' => $spec,
                '_vol' => isset($ing['volume_ml']) && $ing['volume_ml'] !== null ? rh_sma_num((float)$ing['volume_ml']) : '',
            ];
            $fp[] = [$id, $d['status'], (string)$ing['unit'], rh_stock_measure_describe($d['old']), rh_stock_measure_describe($d['new']),
                $rows[count($rows) - 1]['_vol'], (string)$d['why'], $rows[count($rows) - 1]['note']];
        }
        $fingerprint = hash('sha256', json_encode([rh_drink_measures_describe($list), $fp]));
        return ['rows' => $rows, 'counts' => $counts, 'fingerprint' => $fingerprint];
    }

    /** Preview rows for the screen (the internal arrays left out). */
    function rh_sma_public_rows(array $rows): array
    {
        return array_map(static function (array $r): array {
            unset($r['_old'], $r['_new'], $r['_spec'], $r['_vol']);
            return $r;
        }, $rows);
    }

    /**
     * Apply the ticked rows ($tickedCsv "1,2,3"). Refused as a whole when the preview is out of date (fingerprint) or a ticked row is
     * not one that can be saved (set / different). Each ticked row: row lock, unit / measure / bottle size re-checked as previewed,
     * no open count lines when changing, rh_stock_measure_save, commit. Unticked rows are never saved.
     * Returns ['ok', 'errors', 'set' => names, 'changed' => names, 'unchanged' => names, 'skipped' => [[name, why]], 'rows'].
     */
    function rh_sma_apply(PDO $pdo, array $items, array $list, string $fingerprint, string $tickedCsv, int $userId): array
    {
        $res = ['ok' => false, 'errors' => [], 'set' => [], 'changed' => [], 'unchanged' => [], 'skipped' => [], 'rows' => []];
        $ticked = rh_smb_id_list($tickedCsv);
        if ($ticked === null) {
            $res['errors'][] = 'The ticked items were not sent correctly. Press Preview again.';
            return $res;
        }
        if (!$ticked) {
            $res['errors'][] = 'No items are ticked.';
            return $res;
        }
        if (count($ticked) > RH_SMB_MAX_ITEMS) {
            $res['errors'][] = 'At most ' . number_format(RH_SMB_MAX_ITEMS) . ' items can be saved in one go.';
            return $res;
        }
        $plan = rh_sma_plan($pdo, $items, $list);
        if ($fingerprint === '' || !hash_equals($plan['fingerprint'], $fingerprint)) {
            $res['errors'][] = 'Something changed since the preview (an item, its unit, bottle size, count measure, menu links or the Drink measures list). Press Preview again and check the list.';
            return $res;
        }
        $tickSet = array_flip($ticked);
        $byId = [];
        foreach ($plan['rows'] as $row) {
            $byId[$row['id']] = $row;
        }
        foreach ($ticked as $tid) {
            if (!isset($byId[$tid]) || !in_array($byId[$tid]['status'], ['set', 'different'], true)) {
                $res['errors'][] = 'A ticked item cannot be saved (it is not one marked Will be set or Has another measure). Press Preview again.';
                return $res;
            }
        }
        $drink = rh_stock_drink_ready($pdo);
        $lock = $pdo->prepare('SELECT id, name, unit, measure_big_name, measure_small_name, measure_per_big, measure_stock_side' . ($drink ? ', volume_ml' : '')
            . ' FROM stock_ingredients WHERE id = ? FOR UPDATE');
        foreach ($plan['rows'] as $row) {
            $st = $row['status'];
            $outRow = $row;
            if (!isset($tickSet[$row['id']])) {
                if ($st === 'skip') {
                    $res['skipped'][] = [$row['name'], $row['why']];
                } else {
                    $res['unchanged'][] = $row['name'];
                    if ($st !== 'same') {
                        $outRow['status'] = 'not_ticked';
                    }
                }
                $res['rows'][] = $outRow;
                continue;
            }
            try {
                $pdo->beginTransaction();
                $lock->execute([$row['id']]);
                $cur = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$cur) {
                    throw new RuntimeException('it no longer exists');
                }
                $curM = rh_stock_measure_get($cur);
                $sameOld = ($curM === null && $row['_old'] === null) || ($curM !== null && $row['_old'] !== null && rh_stock_measure_describe($curM) === rh_stock_measure_describe($row['_old']));
                $curVol = $drink && $cur['volume_ml'] !== null ? rh_sma_num((float)$cur['volume_ml']) : '';
                if (trim((string)$cur['unit']) !== trim($row['unit']) || !$sameOld || ($drink && $curVol !== $row['_vol'])) {
                    throw new RuntimeException('its unit, bottle size or count measure was changed by someone else while this was saved; it is not changed');
                }
                if ($st === 'different') {
                    $ol = (int)(rh_smb_open_lines($pdo, [$row['id']], true)[$row['id']] ?? 0);
                    if ($ol > 0) {
                        throw new RuntimeException($ol . ' line' . ($ol === 1 ? '' : 's') . ' on open stock counts ' . ($ol === 1 ? 'was' : 'were') . ' counted in the old measure; finish those counts first');
                    }
                }
                rh_stock_measure_save($pdo, (int)$row['id'], $row['_new'], $userId, 'Stock Items (filled from bottle sizes)');
                $pdo->commit();
                $res[$st === 'set' ? 'set' : 'changed'][] = $row['name'];
                $outRow['status'] = $st === 'set' ? 'done_set' : 'done_change';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // PDOException extends RuntimeException: database text (SQLSTATE, lock waits) is logged, never shown.
                $friendly = $e instanceof RuntimeException && !($e instanceof PDOException);
                if (!$friendly) {
                    error_log('stock measure auto apply (item ' . (int)$row['id'] . '): ' . $e->getMessage());
                }
                $why = $friendly ? $e->getMessage() : 'it could not be saved';
                $res['skipped'][] = [$row['name'], $why];
                $outRow['status'] = 'skip';
                $outRow['why'] = $why;
            }
            $res['rows'][] = $outRow;
        }
        $res['ok'] = true;
        $res['rows'] = rh_sma_public_rows($res['rows']);
        return $res;
    }

    /** "30 shots / bottle" for the list column, or null when the item has no count measure. */
    function rh_sma_column_text(array $ing): ?string
    {
        $m = rh_stock_measure_get($ing);
        if ($m === null) {
            return null;
        }
        return rh_stock_qty_fmt((float)$m['n'], 0) . ' ' . rh_drink_plural((string)$m['small'], (float)$m['n']) . ' / ' . $m['big'];
    }
}
