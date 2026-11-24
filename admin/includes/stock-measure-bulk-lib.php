<?php
/**
 * Set one count measure on many stock items at once (owner 2026-11-23, "Build #2"; Stock Items list, admin/stock-ingredients.php).
 *
 * Why: items imported before count measures were saved on import show "2.28 bottle" instead of "2 bottles + 7 shots (57 shots)".
 * The person ticks items (or "Tick all shown": the server re-reads the same search / category / status / low-stock filter, never a
 * long id list), types the measure once (bigger unit, smaller measure, N, which one stock is kept in), sees a preview per item and
 * applies it. Each item is saved through rh_stock_measure_save() (config/database.php) in its own transaction: the same checks and
 * the same staff-activity log row as the per-item editor. No guess work (owner rule):
 *   - set       the item has no count measure yet: it gets this one;
 *   - same      it already has this measure (names without case / one-many, N to 4 decimals, same side): nothing happens;
 *   - different it has another measure: kept, unless the person ticks "Change these too" (then 'change');
 *   - skip      never saved, with the reason: the unit stock is kept in is not the item's own unit (a quantity would change its
 *               meaning: never converted here), the names cannot be kept as a measure, or (when changing) lines on open stock counts
 *               were typed in the old measure and would be re-read in the new one (the import uses the same guard).
 * Quantities, costs, batches and stock never change: only the four measure columns, plus what rh_stock_measure_save itself re-works.
 */

require_once __DIR__ . '/stock-list-filters.php';

if (!defined('RH_SMB_MAX_ITEMS')) {
    define('RH_SMB_MAX_ITEMS', 5000);
}
if (!defined('RH_SMB_MAX_BOTTLE_ML')) {
    define('RH_SMB_MAX_BOTTLE_ML', 100000);
}

if (!function_exists('rh_smb_list_rows')) {

    /** Every stock item as the Stock Items list reads it (flags for the status column). The page and the bulk selection share it. */
    function rh_smb_list_rows(PDO $pdo): array
    {
        $histSql = rh_stock_history_sql($pdo, 'i'); // shared "has stock history" rule
        $attentionSql = rh_stock_attention_sql(rh_stock_threshold_sql('i'), $histSql, 'i'); // the low-stock alert rule
        return $pdo->query("
            SELECT i.*,
                   (CASE WHEN {$histSql} THEN 0 ELSE 1 END) AS never_stocked,
                   (CASE WHEN {$attentionSql} THEN 1 ELSE 0 END) AS needs_attention,
                   (SELECT MIN(b.expiry_date) FROM stock_batches b WHERE b.ingredient_id = i.id AND b.status = 'active' AND b.expiry_date IS NOT NULL AND b.quantity_remaining > 0) AS next_expiry
            FROM stock_ingredients i
            ORDER BY i.is_archived ASC, i.category ASC, i.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Days to the item's next batch expiry (negative = expired), or null. */
    function rh_smb_exp_days(array $i): ?int
    {
        if (empty($i['next_expiry'])) {
            return null;
        }
        return (int)(new DateTime('today'))->diff(new DateTime((string)$i['next_expiry']))->format('%r%a');
    }

    /** The list's status filter value of one row (ok / low / critical / unstocked / expiring / archived): the one rule the page and the selection use. */
    function rh_smb_row_status(array $i): string
    {
        $cur = (float)$i['current_quantity'];
        $min = (float)$i['reorder_point'] > 0 ? (float)$i['reorder_point'] : (float)$i['min_quantity']; // shared threshold rule
        $neverStocked = ($cur <= 0 && !empty($i['never_stocked']));
        $expDays = rh_smb_exp_days($i);
        $isExpiringSoon = ($expDays !== null && $expDays >= 0 && $expDays <= 7);
        $isExpired = ($expDays !== null && $expDays < 0);
        return ((int)$i['is_archived'] === 1) ? 'archived'
            : ($neverStocked ? 'unstocked'
            : ($cur <= 0 ? 'critical'
            : ($isExpired ? 'critical'
            : ($min > 0 && $cur <= $min ? 'low'
            : ($isExpiringSoon ? 'expiring' : 'ok')))));
    }

    /** "bottle" and "bottles" are one word; case does not count. */
    function rh_smb_word_same(string $a, string $b): bool
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));
        if ($a === '' || $b === '') {
            return false;
        }
        return $a === $b || rh_stock_measure_plural($a, 2) === $b || rh_stock_measure_plural($b, 2) === $a;
    }

    /** Two measures are the same: names as one word (case, one / many), N to 4 decimals, same stock side. */
    function rh_smb_measure_same(array $a, array $b): bool
    {
        return rh_smb_word_same((string)$a['big'], (string)$b['big']) && rh_smb_word_same((string)$a['small'], (string)$b['small'])
            && abs((float)$a['n'] - (float)$b['n']) < 0.00005 && (string)$a['side'] === (string)$b['side'];
    }

    /** "25 shots in one bottle, stock kept in bottles" (plain words for the preview). */
    function rh_smb_measure_text(?array $m): string
    {
        if (!$m) {
            return 'none';
        }
        $n = (float)$m['n'];
        $kept = $m['side'] === 'big' ? $m['big'] : $m['small'];
        return rh_stock_qty_fmt($n, 0) . ' ' . rh_stock_measure_plural((string)$m['small'], $n) . ' in one ' . $m['big'] . ', stock kept in ' . rh_stock_measure_plural((string)$kept, 2);
    }

    /** A number typed with a dot ("750", "12.5"), or null. */
    function rh_smb_num(string $raw): ?float
    {
        $raw = trim($raw);
        if ($raw === '' || !preg_match('/^\d+(\.\d+)?$/', $raw)) {
            return null;
        }
        $v = (float)$raw;
        return is_finite($v) ? $v : null;
    }

    /**
     * N from two sizes in ml (bottle ml / measure ml), only when exact at 4 decimals (750 / 25 = 30, 750 / 60 = 12.5; 1000 / 30 and
     * 750 / 35 are refused). Returns [?float n, ?string why-not].
     */
    function rh_smb_n_from_ml(string $bottleRaw, string $measureRaw, string $small = 'measure', string $big = 'bottle'): array
    {
        $b = rh_smb_num($bottleRaw);
        $m = rh_smb_num($measureRaw);
        if ($b === null || $b <= 0 || $b > RH_SMB_MAX_BOTTLE_ML) {
            return [null, 'Type the size of one ' . $big . ' in ml as a number above 0 (at most ' . number_format(RH_SMB_MAX_BOTTLE_ML) . ').'];
        }
        $maxM = defined('RH_DRINK_MEASURE_MAX_ML') ? (float)RH_DRINK_MEASURE_MAX_ML : 5000.0;
        if ($m === null || $m <= 0 || $m > $maxM) {
            return [null, 'Type the size of one ' . $small . ' in ml as a number above 0 (at most ' . number_format($maxM) . ').'];
        }
        $raw = $b / $m;
        $n = round($raw, 4);
        if (abs($n * $m - $b) > 0.000001) {
            return [null, rh_stock_qty_fmt($b, 0) . ' ml / ' . rh_stock_qty_fmt($m, 0) . ' ml = ' . rh_stock_qty_fmt($raw, 0) . '... ' . rh_stock_measure_plural($small, 2)
                . ': not an exact number (at most 4 decimals), so it is not used. Type how many ' . rh_stock_measure_plural($small, 2) . ' are in one ' . $big . ' yourself.'];
        }
        if ($n < 1 || $n > RH_STOCK_MEASURE_MAX_N) {
            return [null, rh_stock_qty_fmt($b, 0) . ' ml / ' . rh_stock_qty_fmt($m, 0) . ' ml = ' . rh_stock_qty_fmt($n, 0) . ': it must be between 1 and ' . RH_STOCK_MEASURE_MAX_N . '.'];
        }
        return [$n, null];
    }

    /**
     * The measure the person typed once for all ticked items. $in keys: big_name ('' = each item's own unit), small_name, per_big,
     * bottle_ml, measure_ml (both or neither), side ('big' | 'small'), change_too ('1').
     * Returns ['ok' => bool, 'errors' => [], 'spec' => ['big' (''= each item's unit), 'small', 'n', 'side', 'change_too', 'from_ml']].
     */
    function rh_smb_read_spec(array $in): array
    {
        $clean = static fn($v): string => trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v));
        $big = $clean($in['big_name'] ?? '');
        $small = $clean($in['small_name'] ?? '');
        $side = (string)($in['side'] ?? 'big');
        $nRaw = trim((string)($in['per_big'] ?? ''));
        $bMl = trim((string)($in['bottle_ml'] ?? ''));
        $mMl = trim((string)($in['measure_ml'] ?? ''));
        $errors = [];
        if ($small === '') {
            $errors[] = 'Type the smaller measure, for example shot, tot, glass or double.';
        } elseif (mb_strlen($small) > 30) {
            $errors[] = 'The smaller measure can be at most 30 characters.';
        }
        if (mb_strlen($big) > 30) {
            $errors[] = 'The bigger unit can be at most 30 characters.';
        }
        if ($side !== 'big' && $side !== 'small') {
            $errors[] = 'Choose which one stock is kept in.';
        }
        if ($side === 'small' && $big === '') {
            $errors[] = 'Stock kept in the smaller measure needs the bigger unit typed (for example bottle).';
        }
        if ($big !== '' && $small !== '' && rh_smb_word_same($big, $small)) {
            $errors[] = 'The bigger unit and the smaller measure need different names.';
        }
        $smallW = $small !== '' ? $small : 'measure';
        $bigW = $big !== '' ? $big : 'bottle';
        $n = null;
        $fromMl = false;
        if (($bMl === '') !== ($mMl === '')) {
            $errors[] = 'Type both sizes in ml (one ' . $bigW . ' and one ' . $smallW . '), or leave both empty.';
        } elseif ($bMl !== '') {
            [$mlN, $mlErr] = rh_smb_n_from_ml($bMl, $mMl, $smallW, $bigW);
            if ($mlErr !== null) {
                $errors[] = $mlErr;
            } elseif ($nRaw === '') {
                $n = $mlN;
                $fromMl = true;
            } else {
                $typed = rh_smb_num($nRaw);
                if ($typed === null || abs($typed - $mlN) > 0.00005) {
                    $errors[] = 'How many ' . rh_stock_measure_plural($smallW, 2) . ' in one ' . $bigW . ' (' . $nRaw . ') does not match the sizes in ml ('
                        . rh_stock_qty_fmt((float)$bMl, 0) . ' / ' . rh_stock_qty_fmt((float)$mMl, 0) . ' = ' . rh_stock_qty_fmt($mlN, 0) . '). Correct one of them.';
                } else {
                    $n = $mlN;
                    $fromMl = true;
                }
            }
        }
        if ($n === null && !$fromMl && $bMl === '') {
            if ($nRaw === '') {
                $errors[] = 'Type how many ' . rh_stock_measure_plural($smallW, 2) . ' are in one ' . $bigW . ', or both sizes in ml.';
            } else {
                $typed = rh_smb_num($nRaw);
                if ($typed === null) {
                    $errors[] = 'How many ' . rh_stock_measure_plural($smallW, 2) . ' in one ' . $bigW . ' must be a number (use a dot for decimals).';
                } elseif ($typed < 1 || $typed > RH_STOCK_MEASURE_MAX_N) {
                    $errors[] = 'How many ' . rh_stock_measure_plural($smallW, 2) . ' in one ' . $bigW . ' must be between 1 and ' . RH_STOCK_MEASURE_MAX_N . '.';
                } elseif (abs(round($typed, 4) - $typed) > 1e-9) {
                    $errors[] = 'How many ' . rh_stock_measure_plural($smallW, 2) . ' in one ' . $bigW . ' can have at most 4 decimals.';
                } else {
                    $n = round($typed, 4);
                }
            }
        }
        $ok = !$errors && $n !== null;
        return ['ok' => $ok, 'errors' => $errors, 'spec' => $ok ? ['big' => $big, 'small' => $small, 'n' => $n, 'side' => $side,
            'change_too' => !empty($in['change_too']) && (string)$in['change_too'] !== '0', 'from_ml' => $fromMl] : null];
    }

    /** "1,2,3" -> [1,2,3] (positive whole numbers, no repeats); null when anything else is in it. */
    function rh_smb_id_list(string $csv): ?array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }
        if (!preg_match('/^\d+(,\d+)*$/', $csv)) {
            return null;
        }
        $ids = [];
        foreach (explode(',', $csv) as $p) {
            $v = (int)$p;
            if ($v > 0) {
                $ids[$v] = $v;
            }
        }
        return array_values($ids);
    }

    /**
     * The ticked items. $in: mode 'ids' (ids = "1,2,3") or 'all' ("Tick all shown": q = the search text lower-cased as the page
     * matched it, category, status, list_filter 'low', except = "4,5" the boxes unticked since, shown = how many the page showed).
     * Archived items are never ticked. Returns ['ok', 'errors', 'items' => rows of rh_smb_list_rows()].
     */
    function rh_smb_select(PDO $pdo, array $in): array
    {
        $mode = (string)($in['mode'] ?? '');
        $bad = static fn(string $why): array => ['ok' => false, 'errors' => [$why], 'items' => []];
        if ($mode !== 'ids' && $mode !== 'all') {
            return $bad('No items are ticked.');
        }
        $rows = rh_smb_list_rows($pdo);
        $items = [];
        if ($mode === 'ids') {
            $ids = rh_smb_id_list((string)($in['ids'] ?? ''));
            if ($ids === null) {
                return $bad('The ticked items were not sent correctly. Reload the page and tick them again.');
            }
            if (!$ids) {
                return $bad('No items are ticked.');
            }
            if (count($ids) > RH_SMB_MAX_ITEMS) {
                return $bad('You ticked ' . number_format(count($ids)) . ' items. At most ' . number_format(RH_SMB_MAX_ITEMS) . ' can be set in one go.');
            }
            $want = array_flip($ids);
            foreach ($rows as $r) {
                if (isset($want[(int)$r['id']]) && (int)$r['is_archived'] !== 1) {
                    $items[] = $r;
                    unset($want[(int)$r['id']]);
                }
            }
            if ($want) {
                return $bad(count($want) . ' ticked item' . (count($want) === 1 ? ' no longer exists or is' : 's no longer exist or are') . ' archived. Reload the page and tick again.');
            }
            return ['ok' => true, 'errors' => [], 'items' => $items];
        }
        $q = (string)($in['q'] ?? '');
        $cat = (string)($in['category'] ?? '');
        $st = (string)($in['status'] ?? '');
        $low = (string)($in['list_filter'] ?? '') === 'low';
        $except = rh_smb_id_list((string)($in['except'] ?? ''));
        if ($except === null) {
            return $bad('The unticked items were not sent correctly. Reload the page and tick again.');
        }
        $exceptSet = array_flip($except);
        $shownByPage = (string)($in['shown'] ?? '') === '' ? null : (int)$in['shown'];
        $shown = 0;
        foreach ($rows as $r) {
            if ((int)$r['is_archived'] === 1) {
                continue;
            }
            if ($low && (int)$r['needs_attention'] !== 1) {
                continue;
            }
            if ($q !== '' && strpos(strtolower((string)$r['name']), $q) === false) { // the page matches the search inside the lower-cased name
                continue;
            }
            if ($cat !== '' && (string)$r['category'] !== $cat) {
                continue;
            }
            if ($st !== '' && rh_smb_row_status($r) !== $st) {
                continue;
            }
            $shown++;
            if (!isset($exceptSet[(int)$r['id']])) {
                $items[] = $r;
            }
        }
        if ($shownByPage !== null && $shownByPage !== $shown) {
            return $bad('The list changed since the page was opened: the page showed ' . number_format($shownByPage) . ' items, now ' . number_format($shown)
                . ' match the same search and filters. Reload the page and tick again.');
        }
        if (!$items) {
            return $bad('No items are ticked.');
        }
        if (count($items) > RH_SMB_MAX_ITEMS) {
            return $bad(number_format(count($items)) . ' items are ticked. At most ' . number_format(RH_SMB_MAX_ITEMS) . ' can be set in one go: narrow the search or filters.');
        }
        return ['ok' => true, 'errors' => [], 'items' => $items];
    }

    /** Lines on open (draft / submitted) stock counts typed in a measure, per item: [id => lines]. The same rule as rh_stock_measure_save re-works. */
    function rh_smb_open_lines(PDO $pdo, array $ids, bool $lock = false): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $st = $pdo->prepare("SELECT scl.ingredient_id, COUNT(*) FROM stock_count_lines scl INNER JOIN stock_counts sc ON sc.id = scl.count_id
                WHERE sc.status IN ('draft','submitted') AND scl.entry_mode IN ('big','small','both') AND scl.ingredient_id IN ("
                . implode(',', array_fill(0, count($chunk), '?')) . ') GROUP BY scl.ingredient_id' . ($lock ? ' LOCK IN SHARE MODE' : ''));
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
                $out[(int)$r[0]] = (int)$r[1];
            }
        }
        return $out;
    }

    /**
     * What happens to one item: ['status' => set|same|different|change|skip, 'why', 'old' (?array), 'new' (?array)]. $openLines = lines on
     * open counts typed in the item's current measure.
     */
    function rh_smb_decide(array $ing, array $spec, int $openLines): array
    {
        $unit = trim((string)$ing['unit']);
        $old = rh_stock_measure_get($ing);
        $out = ['status' => 'skip', 'why' => '', 'old' => $old, 'new' => null];
        if ($unit === '') {
            $out['why'] = 'it has no stock unit';
            return $out;
        }
        $big = $spec['big'] !== '' ? $spec['big'] : mb_strtolower($unit);
        $keptName = $spec['side'] === 'big' ? $big : $spec['small'];
        if (!rh_smb_word_same($keptName, $unit)) {
            $out['why'] = 'its stock is kept in "' . $unit . '", not in "' . $keptName . '". Its quantity would mean something else, so it is not changed (nothing is converted here)';
            return $out;
        }
        [$new, $err] = rh_stock_measure_from_input(['measure_big_name' => $big, 'measure_small_name' => $spec['small'],
            'measure_per_big' => (string)$spec['n'], 'measure_stock_side' => $spec['side']]);
        if ($new === null) {
            $out['why'] = rtrim((string)$err, '.');
            return $out;
        }
        $out['new'] = $new;
        if ($old === null) {
            $out['status'] = 'set';
            return $out;
        }
        if (rh_smb_measure_same($old, $new)) {
            $out['status'] = 'same';
            return $out;
        }
        if (empty($spec['change_too'])) {
            $out['status'] = 'different';
            return $out;
        }
        if ($openLines > 0) {
            $out['why'] = $openLines . ' line' . ($openLines === 1 ? '' : 's') . ' on open stock counts ' . ($openLines === 1 ? 'was' : 'were') . ' counted in '
                . rh_stock_measure_plural((string)$old['small'], 2) . ' and would be read in the new measure. Finish those counts first, then change it';
            return $out;
        }
        $out['status'] = 'change';
        return $out;
    }

    /** The preview: one row per ticked item plus the counts and a fingerprint that Apply must match. */
    function rh_smb_plan(PDO $pdo, array $items, array $spec): array
    {
        $open = rh_smb_open_lines($pdo, array_map(static fn(array $r): int => (int)$r['id'], $items));
        $rows = [];
        $counts = ['set' => 0, 'same' => 0, 'different' => 0, 'change' => 0, 'skip' => 0];
        $fp = [];
        foreach ($items as $ing) {
            $d = rh_smb_decide($ing, $spec, (int)($open[(int)$ing['id']] ?? 0));
            $counts[$d['status']]++;
            $qty = (float)$ing['current_quantity'];
            $after = in_array($d['status'], ['set', 'change'], true) ? array_merge($ing, ['measure_big_name' => $d['new']['big'], 'measure_small_name' => $d['new']['small'],
                'measure_per_big' => $d['new']['n'], 'measure_stock_side' => $d['new']['side']]) : $ing;
            $rows[] = [
                'id' => (int)$ing['id'],
                'name' => (string)$ing['name'],
                'unit' => (string)$ing['unit'],
                'status' => $d['status'],
                'why' => $d['why'],
                'old' => $d['old'] ? rh_smb_measure_text($d['old']) : '',
                'new' => $d['new'] ? rh_smb_measure_text($d['new']) : '',
                'qty_now' => rh_stock_qty_words($qty, $ing, (string)$ing['unit']),
                'qty_after' => rh_stock_qty_words($qty, $after, (string)$ing['unit']),
                'qty_exact' => rh_stock_qty_fmt($qty, 0) . ' ' . (string)$ing['unit'],
                '_old' => $d['old'],
                '_new' => $d['new'],
            ];
            $fp[] = [(int)$ing['id'], $d['status'], (string)$ing['unit'], rh_stock_measure_describe($d['old'])];
        }
        $fingerprint = hash('sha256', json_encode([$spec['big'], $spec['small'], (string)$spec['n'], $spec['side'], !empty($spec['change_too']), $fp]));
        return ['rows' => $rows, 'counts' => $counts, 'fingerprint' => $fingerprint];
    }

    /** Preview rows for the screen (the internal measure arrays left out). */
    function rh_smb_public_rows(array $rows): array
    {
        return array_map(static function (array $r): array {
            unset($r['_old'], $r['_new']);
            return $r;
        }, $rows);
    }

    /**
     * Apply: plan again, refuse when anything changed since the preview ($fingerprint), then each 'set' / 'change' item in its own
     * transaction through rh_stock_measure_save (row lock, re-checked: unit and measure as previewed, no open count lines when changing).
     * Returns ['ok', 'errors', 'set' => names, 'changed' => names, 'unchanged' => names, 'skipped' => [[name, why]], 'rows'].
     */
    function rh_smb_apply(PDO $pdo, array $items, array $spec, string $fingerprint, int $userId): array
    {
        $plan = rh_smb_plan($pdo, $items, $spec);
        $res = ['ok' => false, 'errors' => [], 'set' => [], 'changed' => [], 'unchanged' => [], 'skipped' => [], 'rows' => []];
        if ($fingerprint === '' || !hash_equals($plan['fingerprint'], $fingerprint)) {
            $res['errors'][] = 'Something changed since the preview (an item, its unit or its count measure). Press Preview again and check the list.';
            return $res;
        }
        $lock = $pdo->prepare('SELECT id, name, unit, measure_big_name, measure_small_name, measure_per_big, measure_stock_side FROM stock_ingredients WHERE id = ? FOR UPDATE');
        foreach ($plan['rows'] as $row) {
            $st = $row['status'];
            $outRow = $row;
            if ($st === 'same' || $st === 'different') {
                $res['unchanged'][] = $row['name'];
                $res['rows'][] = $outRow;
                continue;
            }
            if ($st === 'skip') {
                $res['skipped'][] = [$row['name'], $row['why']];
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
                if (trim((string)$cur['unit']) !== $row['unit'] || !$sameOld) {
                    throw new RuntimeException('its unit or count measure was changed by someone else while this was saved; it is not changed');
                }
                if ($st === 'change') {
                    $ol = (int)(rh_smb_open_lines($pdo, [$row['id']], true)[$row['id']] ?? 0);
                    if ($ol > 0) {
                        throw new RuntimeException($ol . ' line' . ($ol === 1 ? '' : 's') . ' on open stock counts ' . ($ol === 1 ? 'was' : 'were') . ' counted in the old measure; finish those counts first');
                    }
                }
                rh_stock_measure_save($pdo, (int)$row['id'], $row['_new'], $userId, 'Stock Items (set for several items)');
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
                    error_log('stock measure bulk apply (item ' . (int)$row['id'] . '): ' . $e->getMessage());
                }
                $why = $friendly ? $e->getMessage() : 'it could not be saved';
                $res['skipped'][] = [$row['name'], $why];
                $outRow['status'] = 'skip';
                $outRow['why'] = $why;
            }
            $res['rows'][] = $outRow;
        }
        $res['ok'] = true;
        $res['rows'] = rh_smb_public_rows($res['rows']);
        return $res;
    }
}
