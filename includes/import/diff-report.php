<?php
/**
 * Import: "Differences from the uploaded file" (owner 2026-11-23: "when the import is submitted for review it reports any discrepancies
 * with the file that was uploaded as an import too in summary").
 *
 * Built ONCE, at commit (commit-stock.php, inside the same transaction), from what the import really did, and stored FROZEN with the job:
 * import_jobs.result_json key 'diff_report'. Changing an item, a price or the menu later never changes it. The opening count the commit
 * files is linked to the job by stock_counts.import_job_id (nullable, added on first use the same way is_opening was; the notes still say
 * "Import #id" for people). An opening count filed before this change has no link and no report: the count page shows nothing extra.
 *
 * Only REAL differences are listed: an item imported exactly as the file says has no row. Kinds (in this order):
 *   count    counts converted (written in shots / glasses -> the stock unit, with N and where N came from)
 *   cost     costs worked out (a pack / case price divided by its pack size; the item's own cost kept because Cost was not ticked; no cost used)
 *   fixed    "Fixed for you" repairs (the cell as written -> as read), and fixes the person took back
 *   left     rows skipped, and stock in the file that is not in the opening count, with the reason
 *   value    the file's value column vs count x unit cost as imported (per row where they differ) + file total vs imported total
 *   answer   questions answered during the review (the question in short + the choice)
 *   menu     items put on the menu / given a new price; a price typed in step 3 vs the file's price
 *   measure  count measures saved on items / kept / not saved, with the reason
 * Row = ['k' kind, 'item', 'cell' (cells of the file), 'file' (what the file says), 'used' (what was imported), 'why'].
 *
 *   import_diff_build($in)                 pure: the report from what the commit knows (no database)
 *   import_diff_summary($report)           counts and totals only (screens' summary; no rows)
 *   import_diff_kinds()                    kind => [label, helper]
 *   import_diff_link_ready($pdo, $add)     is stock_counts.import_job_id there (optionally add it: DDL, run outside a transaction)
 *   import_diff_for_count($pdo, $count)    the report of an opening count (null = none: old count, no link, job deleted)
 *   import_diff_export($report, $format)   csv | xlsx download (values only)
 */

require_once __DIR__ . '/text-util.php';

if (!function_exists('import_diff_build')) {

    /** kind => [chip label, plain helper]. The order is the order shown. */
    function import_diff_kinds(): array
    {
        return [
            'count' => ['Counts converted', 'Counts your file wrote in shots, glasses or another measure, turned into the stock unit (bottles, cartons ...). Shows how many make one and where that number came from.'],
            'cost' => ['Costs worked out', 'Unit costs that are not the file\'s price as written: a case or pack price divided by its pack size, the cost already in stock kept because Cost was not ticked, or a price not used as a cost.'],
            'fixed' => ['Fixed for you', 'Small spelling or number-format fixes made while reading the file (the cell as written and as read), and fixes someone took back.'],
            'left' => ['Left out', 'Rows that were skipped, and stock in the file that is not in this opening count, with the reason.'],
            'value' => ['Value check', 'Rows where the file\'s own value column (Stock Value / Total) is not the count times the unit cost that was imported.'],
            'answer' => ['Answers given', 'Questions answered during the review, in short, with the choice that was made.'],
            'menu' => ['Menu', 'Items put on the menu or given a new menu price, and prices typed in step 3 instead of the file\'s price.'],
            'measure' => ['Count measures', 'Count measures saved on items (stock counts can then be typed in shots or glasses), kept as they were, or not saved, with the reason.'],
        ];
    }

    function import_diff_num($x, int $dp = 4): string
    {
        $s = number_format((float)$x, $dp, '.', ',');
        return strpos($s, '.') !== false ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    /** Money as stored: up to 4 decimals for a unit cost, at least 2 when there is a fraction. */
    function import_diff_money($x): string
    {
        if (function_exists('import_fmt_money')) {
            return import_fmt_money($x);
        }
        $s = import_diff_num($x, 4);
        $p = strpos($s, '.');
        return ($p !== false && strlen($s) - $p - 1 < 2) ? number_format((float)$x, 2, '.', ',') : $s;
    }

    function import_diff_money2($x): string
    {
        return number_format(round((float)$x, 2), 2, '.', ',');
    }

    function import_diff_plural(string $word, float $n): string
    {
        if ($word === '') {
            return '';
        }
        return function_exists('import_measure_plural') ? import_measure_plural($word, $n) : ($n == 1.0 ? $word : $word . 's');
    }

    function import_diff_cells(array $cells, int $max = 3): string
    {
        $cells = array_values(array_unique(array_filter(array_map('strval', $cells), function ($c) {
            return $c !== '';
        })));
        if (count($cells) <= $max) {
            return implode(', ', $cells);
        }
        return implode(', ', array_slice($cells, 0, $max)) . ' and ' . (count($cells) - $max) . ' more';
    }

    function import_diff_short(string $t, int $max = 220): string
    {
        $t = trim((string)preg_replace('/\s+/u', ' ', $t));
        return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
    }

    /** "Sheet1!C12" -> [sheet, row] (row 0 when it cannot be read). */
    function import_diff_cell_row(string $src): array
    {
        $p = strrpos($src, '!');
        $sheet = $p === false ? '' : substr($src, 0, $p);
        $ref = $p === false ? $src : substr($src, $p + 1);
        return [$sheet, preg_match('/(\d+)\s*$/', $ref, $m) ? (int)$m[1] : 0];
    }

    /**
     * The report. $in:
     *   file, job_id, count_id, count_ref   about the job and the opening count it filed
     *   res                                 the resolved review at commit (items, rows, heals, heals_undone, questions, extra_questions)
     *   planned                             item key => true: the ready items the commit wrote
     *   lines                               item key => ['qty' => float, 'cost' => float]: that item's line on the opening count, as written
     *   vc_col, vc_fin                      the value-check collection and its finish (value-check.php); optional
     *   menu_rows                           import_drinks_commit()['rows']
     *   measures_list, measures_not         what the commit saved / did not save as count measures
     *   cap                                 rows kept (default 5000; counts stay exact)
     */
    function import_diff_build(array $in): array
    {
        $res = (array)($in['res'] ?? []);
        $items = (array)($res['items'] ?? []);
        $rowsRes = (array)($res['rows'] ?? []);
        $planned = (array)($in['planned'] ?? []);
        $lines = (array)($in['lines'] ?? []);
        $page = import_stock_page_name();
        $out = [];
        $add = function (string $k, string $item, string $cell, string $file, string $used, string $why) use (&$out) {
            $out[$k][] = ['k' => $k, 'item' => $item, 'cell' => $cell, 'file' => $file, 'used' => $used, 'why' => $why];
        };
        // lookups: uid -> item index; sheet + row -> uid
        $itemOfUid = [];
        foreach ($items as $i => $it) {
            foreach ((array)($it['uids'] ?? []) as $u) {
                $itemOfUid[(string)$u] = $i;
            }
        }
        $uidAt = [];
        foreach ($rowsRes as $uid => $r) {
            $uidAt[(string)($r['sheet'] ?? '') . "\x1f" . (int)($r['row'] ?? 0)] = (string)$uid;
        }
        $isPlanned = function (array $it) use ($planned): bool {
            return ($it['status'] ?? '') === 'ready' && isset($planned[(string)$it['key']]);
        };
        $rowQty = [];       // sheet \x1f row => the count of that row in the stock unit, when it was converted from a measure

        // ---- counts converted + costs worked out + stock left out of the count (one pass over the items) ----
        foreach ($items as $it) {
            if (!$isPlanned($it)) {
                continue;
            }
            $name = (string)$it['name'];
            $unit = (string)($it['unit'] ?? '');
            $line = $lines[(string)$it['key']] ?? null;
            foreach ((array)($it['qty_conv'] ?? []) as $cv) {
                foreach ((array)$cv['cells'] as $c) {
                    [$cs, $cr] = import_diff_cell_row((string)$c);
                    if ($cr > 0) {
                        $rowQty[$cs . "\x1f" . $cr] = (float)$cv['q'];
                    }
                }
                if ((string)($cv['how'] ?? '') === '' || $line === null) {
                    continue;           // the measure is the stock unit itself (nothing converted), or the count did not go in (listed under Left out)
                }
                $fileTxt = ((float)$cv['plain'] > 0 ? import_diff_num($cv['plain']) . ' ' . import_diff_plural($unit, (float)$cv['plain']) . ' + ' : '')
                    . import_diff_num($cv['amount']) . ' ' . (string)$cv['word'];
                $w1 = function_exists('import_measure_singular') ? import_measure_singular((string)$cv['word']) : (string)$cv['word'];
                $add('count', $name, import_diff_cells((array)$cv['cells']), $fileTxt,
                    import_diff_num($cv['q'], 6) . ' ' . import_diff_plural($unit, (float)$cv['q']),
                    import_diff_num($cv['n']) . ' ' . import_diff_plural($w1, (float)$cv['n']) . ' in one ' . $unit . ' (' . (string)$cv['how'] . ').');
            }
            // costs
            $price = $it['price'] ?? null;
            $cost = $it['cost'] ?? null;
            $priceCell = (string)($it['price_source'] ?? '');
            if ($price !== null) {
                $applied = !empty($it['cost_applied']);
                if ($cost === null) {
                    $add('cost', $name, $priceCell, import_diff_money($price), 'No cost',
                        'The price was not used as the cost of one ' . $unit . ' (your answer: it is a price per measure). Add the cost later in ' . $page . '.');
                } elseif ($applied && (!empty($it['pack']) || abs((float)$cost - (float)$price) > 0.00005)) {
                    $pack = (int)($it['pack'] ?? 0);
                    $packName = trim((string)($it['pack_name'] ?? ''));
                    $how = (string)($it['cost_how'] ?? '');
                    $why = $pack > 0
                        ? 'The price is for a whole ' . ($packName !== '' ? mb_strtolower($packName) : 'pack') . ' of ' . $pack . ': ' . import_diff_money($price) . ' / ' . $pack . ' = ' . import_diff_money($cost) . ' per ' . $unit . '.'
                        : 'Worked out from the price.';
                    if ($pack <= 0 && $how !== '' && $how !== 'as-written' && stripos($how, 'case price / pack size') !== 0) {
                        $why = ucfirst(rtrim($how, '. ')) . '.';     // the reader's own reason ("Bought by the crate of 24 and counted by the bottle: ...")
                    }
                    $add('cost', $name, $priceCell, import_diff_money($price) . ($pack > 0 ? ' for ' . $pack : ''), import_diff_money($cost) . ' per ' . $unit, $why);
                } elseif (!$applied && !empty($it['match']) && isset($it['diff']['cost']) && $line !== null && abs((float)$line['cost'] - (float)$cost) > 0.00005) {
                    $kept = false;
                    foreach ((array)($it['warnings'] ?? []) as $w) {
                        if (stripos((string)$w, 'You chose to keep the current cost') === 0) {
                            $kept = true;
                        }
                    }
                    $add('cost', $name, $priceCell, import_diff_money($cost) . ' per ' . $unit, import_diff_money($line['cost']) . ' per ' . $unit . ' (the cost already in stock)',
                        $kept ? 'You chose to keep the current cost, so the count uses the cost the item already has.' : 'Cost was not ticked, so the count uses the cost the item already has.');
                }
            }
            // stock in the file that is not in the opening count
            $bal = $it['balance'] ?? null;
            if ($line === null && $bal !== null && (float)$bal > 0.00005) {
                $code = !empty($it['in_count']) ? 'overlap' : (function_exists('import_cont_not_counted_reason') ? import_cont_not_counted_reason($it) : null);
                $whyMap = [
                    'no_cost' => !empty($it['match'])
                        ? 'Not in the opening count: there is no cost for it (none in the file is used and none in stock).'
                        : 'Not in the opening count: no price in the file, so it is created with a blank cost. Add the cost in ' . $page . ' and count it later.',
                    'not_ticked' => 'Not in the opening count: it is already in stock and its quantity was not ticked, so its stock stays as it is.',
                    'cost_not_ticked' => 'Not in the opening count: it is already in stock with no cost, and the file\'s cost was not ticked.',
                    'overlap' => 'Not in this opening count: it is already in another opening count waiting for approval.',
                ];
                if ($code !== null && isset($whyMap[$code])) {
                    $cnt = $it['qty_words']['text'] ?? null;
                    $add('left', $name, import_diff_cells((array)($it['rows'] ?? [])), ($cnt !== null && $cnt !== '' ? (string)$cnt : import_diff_num($bal, 6) . ' ' . import_diff_plural($unit, (float)$bal)),
                        'Not in the count', $whyMap[$code]);
                }
            }
        }

        // ---- rows skipped (by a person or by the file's own state) ----
        foreach ($rowsRes as $uid => $r) {
            if (($r['status'] ?? '') !== 'skipped') {
                continue;
            }
            $code = (string)($r['skip_code'] ?? 'by-answer');
            $why = (string)($r['skipped_why'] ?? '') !== '' ? (string)$r['skipped_why'] : (string)($r['reason'] ?? '');
            $label = function_exists('import_cont_code_label') ? import_cont_code_label($code) : '';
            $why = $why !== '' ? $why : ($label !== '' && $label !== $code ? $label : 'Skipped.');
            $add('left', (string)($r['name'] ?? ''), (string)($r['ref'] ?? ''), 'Row ' . (int)($r['row'] ?? 0) . ' of "' . (string)($r['sheet'] ?? '') . '"', 'Not imported',
                'Skipped: ' . $why);
        }

        // ---- Fixed for you (only on rows that were imported) ----
        $rowImported = function (string $uid) use ($itemOfUid, $items, $isPlanned, $rowsRes): bool {
            if ($uid === '') {
                return true;
            }
            if (($rowsRes[$uid]['status'] ?? '') === 'skipped') {
                return false;
            }
            return isset($itemOfUid[$uid]) && $isPlanned($items[$itemOfUid[$uid]]);
        };
        $itemNameOf = function (string $uid, string $fallback) use ($itemOfUid, $items, $rowsRes): string {
            if ($uid !== '' && isset($itemOfUid[$uid])) {
                return (string)$items[$itemOfUid[$uid]]['name'];
            }
            return $fallback !== '' ? $fallback : (string)($rowsRes[$uid]['name'] ?? '');
        };
        foreach ((array)($res['heals'] ?? []) as $h) {
            $uid = (string)($h['uid'] ?? '');
            if (!$rowImported($uid) || mb_strtolower(trim((string)($h['before'] ?? ''))) === mb_strtolower(trim((string)($h['after'] ?? '')))) {
                continue;       // a skipped row, or only the capitals changed ("Bottle" read as "bottle"): not a difference
            }
            $add('fixed', $itemNameOf($uid, (string)($h['item'] ?? '')), (string)($h['source'] ?? ''), (string)($h['before'] ?? ''), (string)($h['after'] ?? ''),
                (string)($h['text'] ?? '') !== '' ? (string)$h['text'] : 'Fixed while reading the file.');
        }
        foreach ((array)($res['heals_undone'] ?? []) as $h) {
            $uid = $uidAt[(string)($h['sheet'] ?? '') . "\x1f" . (int)($h['row'] ?? 0)] ?? '';
            if (!$rowImported($uid)) {
                continue;
            }
            $add('fixed', $itemNameOf($uid, (string)($h['item'] ?? '')), (string)($h['source'] ?? ''), (string)($h['before'] ?? ''), (string)($h['before'] ?? '') . ' (as written)',
                'The fix was taken back, so the cell was used as written (the fix would have read it as "' . (string)($h['after'] ?? '') . '").');
        }

        // ---- value check: the file's value column vs count x unit cost as imported ----
        $value = null;
        $col = (array)($in['vc_col'] ?? []);
        $fin = (array)($in['vc_fin'] ?? []);
        if ($col && !empty($fin['groups'])) {
            $sheets = [];
            foreach ((array)($col['sheets'] ?? []) as $sh) {
                $sheets[(string)$sh['name']] = $sh;
            }
            $vcWhy = [];
            foreach ((array)($fin['rows'] ?? []) as $vr) {
                $vcWhy[(string)($vr['cell'] ?? '')] = (string)($vr['why'] ?? '');
            }
            $value = ['headers' => [], 'compared' => 0, 'differ' => 0, 'file_total' => 0.0, 'imported_total' => 0.0];
            foreach ($fin['groups'] as $g) {
                $sh = $sheets[(string)$g['sheet']] ?? null;
                if (!$sh) {
                    continue;
                }
                $letter = (string)$g['letter'];
                $value['headers'][(string)$g['header']] = true;
                foreach ((array)$sh['rows'] as $r) {
                    $v = is_array($r[8]) ? ($r[8][$letter] ?? null) : $r[8];
                    if ($v === null) {
                        continue;
                    }
                    $k = (string)$sh['name'] . "\x1f" . (int)$r[1];
                    $fileQty = $r[5];
                    $q = $rowQty[$k] ?? $fileQty;
                    if ($q === null) {
                        continue;                       // the file's count is unknown (blank / a formula with no value): not compared, as the value check
                    }
                    $uid = $uidAt[$k] ?? '';
                    $it = ($uid !== '' && isset($itemOfUid[$uid])) ? $items[$itemOfUid[$uid]] : null;
                    $skipped = $uid === '' || ($rowsRes[$uid]['status'] ?? '') === 'skipped' || !$it || !$isPlanned($it);
                    $line = (!$skipped && $it) ? ($lines[(string)$it['key']] ?? null) : null;
                    $imp = $line !== null ? round((float)$q * (float)$line['cost'], 2) : 0.0;
                    $value['compared']++;
                    $value['file_total'] += (float)$v;
                    $value['imported_total'] += $imp;
                    $tol = function_exists('import_vc_tolerance') ? import_vc_tolerance($imp) : max(0.5, 0.005 * abs($imp));
                    if (abs((float)$v - $imp) <= $tol) {
                        continue;
                    }
                    $value['differ']++;
                    $cell = import_source_ref((string)$sh['name'], $letter . (int)$r[1]);
                    $nm = $it ? (string)$it['name'] : (string)$r[2];
                    $unit = $it ? (string)($it['unit'] ?? '') : (string)$r[4];
                    if ($line === null) {
                        $why = ($skipped && ($uid === '' || ($rowsRes[$uid]['status'] ?? '') === 'skipped') ? 'The row was skipped' : 'Not in the opening count') . ', so nothing of it is valued (see Left out).';
                        $used = '0.00 (not in the count)';
                    } else {
                        $parts = [];
                        if ($fileQty !== null && abs((float)$q - (float)$fileQty) > 0.0000005) {
                            $parts[] = 'the count imported is ' . import_diff_num($q, 6) . ' ' . import_diff_plural($unit, (float)$q) . ' (the file says ' . import_diff_num($fileQty, 6) . ')';
                        }
                        if ($it['cost'] !== null && abs((float)$line['cost'] - (float)$it['cost']) > 0.00005) {
                            $parts[] = 'the unit cost imported is ' . import_diff_money($line['cost']) . ' (the file\'s is ' . import_diff_money($it['cost']) . ')';
                        }
                        if ($parts) {
                            $why = ucfirst(implode('; ', $parts)) . '.';
                        } else {
                            $why = ($vcWhy[$cell] ?? '') !== '' ? $vcWhy[$cell] : 'The file\'s value does not equal its own count x unit cost (a formula not updated, or a typing mistake).';
                        }
                        $used = import_diff_num($q, 6) . ' x ' . import_diff_money($line['cost']) . ' = ' . import_diff_money2($imp);
                    }
                    $add('value', $nm, $cell, import_diff_money2($v), $used, $why);
                }
            }
            $value['headers'] = array_keys($value['headers']);
            $value['file_total'] = round($value['file_total'], 2);
            $value['imported_total'] = round($value['imported_total'], 2);
            if ($value['compared'] === 0) {
                $value = null;
            }
        }

        // ---- answers given ----
        $qs = array_merge(array_values((array)($res['questions'] ?? [])), array_values((array)($res['extra_questions'] ?? [])));
        $seenQ = [];
        foreach ($qs as $q) {
            $ans = $q['answer'] ?? null;
            if ($ans === null || $ans === '' || isset($seenQ[(string)($q['id'] ?? '')])) {
                continue;
            }
            $seenQ[(string)($q['id'] ?? '')] = true;
            $choice = '';
            foreach ((array)($q['options'] ?? []) as $o) {
                if ((string)($o['key'] ?? '') === (string)$ans) {
                    $choice = (string)($o['label'] ?? '');
                }
            }
            if ($choice === '') {
                $choice = $ans === 'per-row' ? 'Answered row by row' : (is_scalar($ans) ? (string)$ans : 'Answered');
            }
            $pk = $q['answer_detail']['pack_size'] ?? null;
            if (($q['type'] ?? '') === 'measure-n' && is_numeric($pk)) {
                $choice = 'Typed: ' . import_diff_num($pk);       // "how many shots in one bottle?": the number the person typed
            } elseif (is_numeric($pk) && (float)$pk >= 2 && strpos($choice, (string)$pk) === false) {
                $choice .= ' (pack of ' . import_diff_num($pk) . ')';
            }
            $uids = array_map('strval', (array)($q['row_uids'] ?? []));
            $who = (string)($q['item_name'] ?? '');
            if ($who === '' && count($uids) === 1) {
                $who = $itemNameOf($uids[0], '');
            }
            if ($who === '') {
                $who = count($uids) > 1 ? count($uids) . ' rows' : ((string)($q['sheet'] ?? '') !== '' ? 'Sheet "' . $q['sheet'] . '"' : 'The whole file');
            }
            $add('answer', $who, import_diff_cells((array)($q['cells'] ?? [])), import_diff_short((string)($q['text'] ?? '')), import_diff_short($choice, 160),
                !empty($q['auto']) ? 'Answered for you from this hotel\'s own earlier data (it could be changed before the import).' : 'Answered during the review.');
        }

        // ---- menu ----
        foreach ((array)($in['menu_rows'] ?? []) as $m) {
            $menuWord = ($m['menu'] ?? '') === 'food' ? 'food menu' : 'drinks menu';
            $price = (float)$m['price'];
            $fp = $m['file_price'] ?? null;
            $old = $m['old_price'] ?? null;
            $typed = ($m['price_from'] ?? '') === 'typed';
            $isAdd = ($m['action'] ?? '') === 'add';
            if (!$typed && !$isAdd && $old !== null && abs((float)$old - $price) < 0.005) {
                continue;           // already on the menu at this price: nothing changed
            }
            $what = $isAdd ? 'Put on the ' . $menuWord . (($m['category'] ?? '') !== '' ? ' (' . $m['category'] . ')' : '') . ' as a new item'
                : 'Menu price changed from ' . import_diff_money2($old) . ' to ' . import_diff_money2($price);
            $why = $typed ? 'Price typed in step 3' . ($fp !== null ? ' instead of the file\'s ' . import_diff_money2($fp) : ' (no price in the file)') . '. ' . $what . '.'
                : $what . ' at the file\'s price.';
            $add('menu', (string)$m['name'], (string)($m['price_source'] ?? ''), $fp !== null ? import_diff_money2($fp) : 'No price', import_diff_money2($price) . ' on the ' . $menuWord, $why);
        }

        // ---- count measures ----
        $itemByName = [];
        foreach ($items as $it) {
            $itemByName[mb_strtolower((string)$it['name'])] = $it;
        }
        $cellsOf = function (string $name) use ($itemByName): string {
            $it = $itemByName[mb_strtolower($name)] ?? null;
            return $it ? import_diff_cells((array)($it['rows'] ?? [])) : '';
        };
        foreach ((array)($in['measures_list'] ?? []) as $m) {
            $small = (string)($m['small'] ?? '');
            $add('measure', (string)$m['name'], $cellsOf((string)$m['name']), 'Counted in ' . ($small !== '' ? import_diff_plural($small, 2) : 'a measure'), 'Saved: ' . (string)$m['text'],
                !empty($m['was']) ? 'Saved on the item, replacing ' . $m['was'] . ' (your choice).' : 'Saved on the item as its count measure, so stock counts can be typed in ' . ($small !== '' ? import_diff_plural($small, 2) : 'that measure') . '.');
        }
        foreach ((array)($in['measures_not'] ?? []) as $m) {
            $why = (string)($m['why'] ?? '');
            $kept = stripos($why, 'keep') !== false;
            $add('measure', (string)$m['name'], $cellsOf((string)$m['name']), 'Counted in a measure', $kept ? 'Kept as it was' : 'Not saved', $why !== '' ? ucfirst($why) . '.' : 'Not saved.');
        }

        // ---- the report ----
        $cap = max(1, (int)($in['cap'] ?? 5000));
        $perKind = max(1, (int)floor($cap / 4));
        $kinds = [];
        $rows = [];
        foreach (array_keys(import_diff_kinds()) as $k) {
            $list = $out[$k] ?? [];
            $kinds[$k] = count($list);
            foreach (array_slice($list, 0, $perKind) as $r) {
                if (count($rows) < $cap) {
                    $rows[] = $r;
                }
            }
        }
        $countValue = 0.0;
        foreach ($lines as $l) {
            $countValue += (float)$l['qty'] * (float)$l['cost'];
        }
        return [
            'v' => 1, 'built_at' => date('Y-m-d H:i:s'), 'file' => (string)($in['file'] ?? ''), 'job_id' => (int)($in['job_id'] ?? 0),
            'count_id' => isset($in['count_id']) ? (int)$in['count_id'] : null, 'count_ref' => (string)($in['count_ref'] ?? ''),
            'kinds' => $kinds, 'total' => array_sum($kinds), 'stored' => count($rows), 'value' => $value, 'count_value' => round($countValue, 2),
            'count_lines' => count($lines), 'rows' => $rows,
        ];
    }

    /** Counts and totals only (result screen, Import history): no rows. */
    function import_diff_summary(?array $rep): ?array
    {
        if (!is_array($rep) || !isset($rep['kinds'])) {
            return null;
        }
        $s = $rep;
        unset($s['rows']);
        $s['labels'] = import_diff_kinds();     // the chip names and helpers come from here (the screens never spell them out themselves)
        return $s;
    }

    /** Is stock_counts.import_job_id there? $add = add it now (DDL: commits on its own, so never inside a transaction). */
    function import_diff_link_ready(PDO $pdo, bool $add = false): bool
    {
        try {
            $has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_counts' AND COLUMN_NAME = 'import_job_id'")->fetchColumn() > 0;
            if (!$has && $add) {
                $pdo->exec('ALTER TABLE stock_counts ADD COLUMN import_job_id INT UNSIGNED NULL DEFAULT NULL');
                $has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_counts' AND COLUMN_NAME = 'import_job_id'")->fetchColumn() > 0;
            }
            return $has;
        } catch (Throwable $e) {
            error_log('import diff report: stock_counts.import_job_id: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The frozen report of an opening count, or null: no link column, not an opening count, no job linked, the job was deleted from Import
     * history, or the job was committed before this report existed. Never throws.
     * Returns ['job' => ['id', 'file', 'status'], 'report' => array].
     */
    function import_diff_for_count(PDO $pdo, array $count): ?array
    {
        try {
            $jid = (int)($count['import_job_id'] ?? 0);
            if ($jid <= 0 || empty($count['is_opening'])) {
                return null;
            }
            $st = $pdo->prepare("SELECT id, original_name, status, result_json FROM import_jobs WHERE id = ? AND status = 'committed'");
            $st->execute([$jid]);
            $j = $st->fetch(PDO::FETCH_ASSOC);
            if (!$j) {
                return null;
            }
            $res = json_decode((string)$j['result_json'], true);
            $rep = is_array($res) ? ($res['diff_report'] ?? null) : null;
            if (!is_array($rep) || !isset($rep['kinds']) || (int)($rep['count_id'] ?? 0) !== (int)$count['id']) {
                return null;
            }
            return ['job' => ['id' => (int)$j['id'], 'file' => (string)$j['original_name'], 'status' => (string)$j['status']], 'report' => $rep];
        } catch (Throwable $e) {
            error_log('import diff report for count #' . (int)($count['id'] ?? 0) . ': ' . $e->getMessage());
            return null;
        }
    }

    /** The download: csv | xlsx. Values only. Returns ['ok', 'name', 'mime', 'body'] or ['ok' => false, 'error']. */
    function import_diff_export(array $rep, string $format): array
    {
        $kinds = import_diff_kinds();
        $file = (string)($rep['file'] ?? 'stock list');
        $base = trim((string)preg_replace('/[^A-Za-z0-9 ._()-]+/', '_', (string)pathinfo($file, PATHINFO_FILENAME)));
        $base = ($base !== '' ? $base : 'stock list') . ' differences import ' . (int)($rep['job_id'] ?? 0);
        $head = ['What', 'Item', 'Cell in your file', 'In your file', 'Imported', 'Why'];
        $line = function (array $r) use ($kinds): array {
            return [$kinds[$r['k']][0] ?? $r['k'], (string)$r['item'], (string)$r['cell'], (string)$r['file'], (string)$r['used'], (string)$r['why']];
        };
        $summary = [['Differences from the uploaded file', $file], ['Import', '#' . (int)($rep['job_id'] ?? 0)],
            ['Opening count', (string)($rep['count_ref'] ?? '')], ['Made at', (string)($rep['built_at'] ?? '')]];
        foreach ($kinds as $k => $kl) {
            $summary[] = [$kl[0], (string)(int)($rep['kinds'][$k] ?? 0)];
        }
        if (!empty($rep['value'])) {
            $summary[] = ['Value column total in your file', import_diff_money2($rep['value']['file_total'])];
            $summary[] = ['Count x unit cost imported (same rows)', import_diff_money2($rep['value']['imported_total'])];
        }
        $summary[] = ['Opening count value (all lines)', import_diff_money2($rep['count_value'] ?? 0)];
        if ((int)($rep['stored'] ?? 0) < (int)($rep['total'] ?? 0)) {
            $summary[] = ['Note', 'Only the first ' . (int)$rep['stored'] . ' of ' . (int)$rep['total'] . ' differences are listed.'];
        }
        $safe = function ($v): string {
            $s = (string)$v;
            return ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) ? "'" . $s : $s;
        };
        if ($format === 'csv') {
            $fh = fopen('php://memory', 'w+');
            fputcsv($fh, $head);
            foreach ((array)($rep['rows'] ?? []) as $r) {
                fputcsv($fh, array_map($safe, $line($r)));
            }
            fputcsv($fh, []);
            foreach ($summary as $s) {
                fputcsv($fh, array_map($safe, $s));
            }
            rewind($fh);
            $body = "\xEF\xBB\xBF" . stream_get_contents($fh);
            fclose($fh);
            return ['ok' => true, 'name' => $base . '.csv', 'mime' => 'text/csv; charset=utf-8', 'body' => $body];
        }
        require_once __DIR__ . '/export-xlsx.php';
        $W = new ImportXlsxWriter(['title' => 'Differences from the uploaded file', 'creator' => 'Import']);
        $hs = $W->style(['font' => ['b' => true], 'fill' => 'FFE9EEF5', 'border' => 'FFBFC7D5', 'wrap' => true, 'v' => 'center']);
        $ws = $W->style(['wrap' => true, 'v' => 'top']);
        $si = $W->addSheet('Differences', ['cols' => [18, 28, 18, 22, 26, 60], 'freeze' => [0, 1], 'gridlines' => true]);
        $c = [];
        foreach ($head as $i => $t) {
            $c[$i + 1] = ['str' => $t, 's' => $hs];
        }
        $W->row($si, 1, $c);
        $n = 1;
        foreach ((array)($rep['rows'] ?? []) as $r) {
            $n++;
            $c = [];
            foreach ($line($r) as $i => $t) {
                $c[$i + 1] = ['str' => $t, 's' => $ws];
            }
            $W->row($si, $n, $c);
        }
        if ($n > 1) {
            $W->filter($si, 'A1:F' . $n);
        }
        $s2 = $W->addSheet('Summary', ['cols' => [40, 40], 'gridlines' => false]);
        foreach ($summary as $i => $s) {
            $W->row($s2, $i + 1, [1 => ['str' => $s[0], 's' => $i === 0 ? $hs : $ws], 2 => ['str' => $s[1], 's' => $i === 0 ? $hs : $ws]]);
        }
        $o = $W->finish();
        if (empty($o['ok'])) {
            return ['ok' => false, 'error' => 'The workbook could not be built.'];
        }
        rewind($o['stream']);
        $body = (string)stream_get_contents($o['stream']);
        fclose($o['stream']);
        return ['ok' => true, 'name' => $base . '.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'body' => $body];
    }

    /** Send a download and stop (the two pages that offer it). */
    function import_diff_send(array $f): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $ascii = trim((string)preg_replace('/[^A-Za-z0-9._ ()-]+/', '_', (string)$f['name']));
        header('Content-Type: ' . $f['mime']);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode((string)$f['name']));
        header('Cache-Control: no-store');
        echo $f['body'];
        exit;
    }
}
