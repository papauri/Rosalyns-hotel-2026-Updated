<?php
/**
 * Import - Step 3 on the server: paging, filtering, category counts and bulk answers for files with thousands of items.
 *
 * The browser never receives the whole review any more. It asks for ONE page of items (with only the questions that belong to those
 * items) plus the facet counts computed over the WHOLE job, and sends back plain filter values that are validated here.
 *
 *   import_rv_params($in)                         validate a request's filter values (allow-lists, lengths); nothing from the browser is trusted
 *   import_rv_build($state, $stockById)           PURE: one light entry per item / skipped row (what the old screen listed), question links, progress
 *   import_rv_query($V, $p, $facets)              PURE: filter + facets (each facet ignores its own filter) + sort
 *   import_rv_bulk_groups($V, $hits)              PURE: open questions of the same type and the same answer roles inside a filtered set
 *   import_job_review_view($pdo, $jobId, ...)     DB: overview (lean, no items) and/or one page, from ONE resolve of the job
 *   import_job_bulk($pdo, $jobId, ...)            DB: preview / apply ONE answer role to exactly the filtered set, validated question by question
 *
 * Nothing here decides a status or invents a value: statuses come from import_resolve(), answers are checked with import_answer_allowed().
 */

require_once __DIR__ . '/review.php';

if (!defined('IMPORT_RV_BIT_Q')) {
    foreach (['Q' => 1, 'STATUS' => 2, 'SHEET' => 4, 'CAT' => 8, 'TYPE' => 16, 'KIND' => 32, 'WARN' => 64, 'COST' => 128, 'STOCK' => 256, 'ANSWERED' => 512] as $k => $v) {
        define('IMPORT_RV_BIT_' . $k, $v);
    }
}

if (!function_exists('import_rv_build')) {

    /** Question families the filter offers (code => plain label). */
    function import_rv_groups(): array
    {
        return [
            'did-you-mean' => 'Did you mean (possible typo)',
            'batch-or-duplicate' => 'Batch or duplicate',
            'price-conflict' => 'Two prices for one item',
            'case-or-unit' => 'Case price or price per unit',
            'price-check' => 'Price looks unusual',
            'storage-limit' => 'Too large or too long to store',
            'same-existing' => 'Two names for one stock item',
            'other' => 'Other',
        ];
    }

    function import_rv_group_of(array $q): string
    {
        switch ((string)$q['type']) {
            case 'possible-typo':
                return 'did-you-mean';
            case 'duplicate-no-batch':
            case 'duplicate-batch-clash':
            case 'same-item-two-sheets':
                return 'batch-or-duplicate';
            case 'conflict':
            case 'merge-conflict':
                return ($q['conflict_field'] ?? 'unit_price') === 'unit' ? 'other' : 'price-conflict';
            case 'case-price':
                return 'case-or-unit';
            case 'price-check':
                return 'price-check';
            case 'cost-too-large':
            case 'value-too-long':
                return 'storage-limit';
            case 'same-existing-match':
                return 'same-existing';
        }
        return 'other';
    }

    /** structural (a sheet / column question, answered in step 2) | sheet (one answer for a whole sheet) | entry (belongs to items on the list). */
    function import_rv_scope(array $q): string
    {
        $hasUids = !empty($q['row_uids']);
        if (!$hasUids && ($q['row'] === null || $q['row'] === '')) {
            return 'structural';
        }
        return (!$hasUids || (string)$q['type'] === 'no-unit') ? 'sheet' : 'entry';
    }

    /** Question types a person may answer in bulk (never the ones that re-read the file, never the per-sheet unit question). */
    function import_rv_bulk_types(): array
    {
        return ['possible-typo', 'duplicate-no-batch', 'duplicate-batch-clash', 'conflict', 'merge-conflict', 'case-price', 'price-check', 'same-item-two-sheets',
            'unit-clash', 'archived-match', 'ambiguous-match', 'cost-too-large', 'value-too-long', 'bad-value', 'formula-name', 'section-or-item'];
        // same-existing-match is left out on purpose: its text says "skip all but one", so skipping the whole group would drop every one of them
    }

    function import_rv_sorts(): array
    {
        return ['order', 'name', 'status', 'category', 'price', 'price_desc'];
    }

    function import_rv_clean_text($v, int $max): string
    {
        $v = is_scalar($v) ? (string)$v : '';
        $v = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
        return mb_substr(trim($v), 0, $max);
    }

    /**
     * Validate a request. Returns the normalised filter set. status: null = "the default" (decided from the counts in import_rv_resolve_status),
     * [] = everything, else a list of the four statuses.
     */
    function import_rv_params(array $in): array
    {
        $flag = function ($k) use ($in) {
            return in_array(strtolower(trim((string)($in[$k] ?? ''))), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
        };
        $allStatus = ['needs-answer', 'blocked', 'ready', 'skipped'];
        $status = null;
        if (isset($in['status']) && is_scalar($in['status'])) {
            $raw = strtolower(trim((string)$in['status']));
            if ($raw === 'all') {
                $status = [];
            } elseif ($raw !== '' && $raw !== 'default') {
                $list = array_values(array_intersect($allStatus, array_map('trim', explode(',', $raw))));
                $status = $list ?: null;
            }
        }
        $type = strtolower(trim((string)($in['type'] ?? '')));
        if (!isset(import_rv_groups()[$type])) {
            $type = '';
        }
        $kind = strtolower(trim((string)($in['kind'] ?? '')));
        if (!in_array($kind, ['new', 'matched'], true)) {
            $kind = '';
        }
        $sort = strtolower(trim((string)($in['sort'] ?? '')));
        if (!in_array($sort, import_rv_sorts(), true)) {
            $sort = 'order';
        }
        $size = (int)($in['size'] ?? 50);
        if (!in_array($size, [25, 50, 100, 200], true)) {
            $size = 50;
        }
        $view = strtolower(trim((string)($in['view'] ?? '')));
        return [
            'q' => import_rv_clean_text($in['q'] ?? '', 100), 'sheet' => import_rv_clean_text($in['sheet'] ?? '', 120), 'cat' => import_rv_clean_text($in['cat'] ?? '', 120),
            'status' => $status, 'type' => $type, 'kind' => $kind, 'warn' => $flag('warn'), 'cost' => $flag('cost'), 'stock' => $flag('stock'), 'answered' => $flag('answered'),
            'sort' => $sort, 'size' => $size, 'page' => max(1, min(100000, (int)($in['page'] ?? 1))), 'view' => $view === 'cat' ? 'cat' : 'list',
        ];
    }

    /** "Needs answer" is the default when anything is left to do; otherwise everything is shown. Returns the params with status as a list. */
    function import_rv_resolve_status(array $p, array $entryCounts): array
    {
        $p['status_default'] = $p['status'] === null;
        if ($p['status'] === null) {
            $p['status'] = (($entryCounts['needs-answer'] ?? 0) + ($entryCounts['blocked'] ?? 0)) > 0 ? ['needs-answer', 'blocked'] : [];
        }
        return $p;
    }

    /**
     * One light entry per item and per skipped row (the list the person works through), the question links and the progress numbers.
     * $stockById: existing ingredient id => has stock on hand. PURE (no database).
     */
    function import_rv_build(array $state, array $stockById = []): array
    {
        $res = $state['res'];
        $an = $state['analysis'];
        $rowSrc = $state['row_src'] ?? import_review_row_sources($an);
        $qs = [];
        foreach (import_review_question_payloads($state) as $q) {
            $q['scope'] = import_rv_scope($q);
            $q['group'] = import_rv_group_of($q);
            $q['is_open'] = $q['answer'] === null || $q['answer'] === '';
            $qs[$q['id']] = $q;
        }
        $qByUid = [];
        foreach ($qs as $id => $q) {
            if ($q['scope'] === 'entry') {
                foreach ((array)$q['row_uids'] as $u) {
                    $qByUid[(string)$u][] = $id;
                }
            }
        }
        $uidCat = $state['uid_cat'] ?? import_review_uid_categories($an);
        $entries = [];
        $itemOf = [];
        $rowOf = [];
        $linked = [];
        $inItem = [];
        $mk = function (array $e) {
            return $e + ['tg' => [], 'qi' => [], 'qsheet' => [], 'refs' => [], 'p' => null, 'new' => false, 'm' => false, 'w' => false, 'c' => false, 's' => false, 'a' => false];
        };
        $note = function (array &$e, array $qids, array $sheetQids) use ($qs, &$linked) {
            foreach ($qids as $id) {
                $q = $qs[$id];
                $e['qi'][] = $id;
                $linked[$id] = true;
                if ($q['is_open']) {
                    $e['tg'][$q['group']] = true;
                } elseif (empty($q['auto_filled'])) {
                    $e['a'] = true;
                }
            }
            foreach ($sheetQids as $id) {
                $q = $qs[$id];
                $e['qsheet'][] = $id;
                if ($q['is_open']) {
                    $e['tg'][$q['group']] = true;
                }
            }
        };
        foreach ($res['items'] as $k => $it) {
            $qids = [];
            $sq = [];
            $ord = null;
            $refs = [(string)$it['name_source'], (string)($it['price_source'] ?? ''), (string)($it['unit_source'] ?? ''), (string)($it['category_source'] ?? '')];
            foreach ((array)$it['rows'] as $rf) {
                $refs[] = (string)$rf;
            }
            foreach ($it['uids'] as $u) {
                $inItem[$u] = true;
                foreach ($qByUid[$u] ?? [] as $id) {
                    $qids[$id] = $id;
                }
                $rr = $res['rows'][$u] ?? null;
                if ($rr) {
                    $o = (int)$rr['sheet_index'] * 10000000 + (int)$rr['row'];
                    $ord = $ord === null ? $o : min($ord, $o);
                }
                foreach ($rowSrc[$u]['qty_sources'] ?? [] as $s) {
                    $refs[] = (string)$s;
                }
            }
            foreach ((array)$it['open'] as $id) {
                if (!isset($qs[$id])) {
                    continue;
                }
                if ($qs[$id]['scope'] === 'entry') {
                    $qids[$id] = $id;
                } elseif ($qs[$id]['scope'] === 'sheet') {
                    $sq[$id] = $id;
                }
            }
            $e = $mk(['i' => count($entries), 'k' => 'item', 'st' => (string)$it['status'], 'sh' => (string)$it['sheet'], 'nm' => (string)$it['name'], 'cat' => (string)$it['category'],
                'ord' => $ord ?? PHP_INT_MAX]);
            $e['ns'] = mb_strtolower($e['nm']);
            $e['new'] = !$it['match'];
            $e['m'] = (bool)$it['match'];
            $e['w'] = !empty($it['warnings']);
            $e['c'] = in_array('cost', (array)$it['ticked'], true);
            $e['s'] = $it['match'] ? !empty($stockById[(int)$it['match']['id']]) : false;
            $e['p'] = $it['cost'] !== null ? (float)$it['cost'] : null;
            $e['hay'] = mb_strtolower($e['nm'] . "\n" . (string)$it['typed_name'] . "\n" . $e['cat'] . "\n" . $e['sh']);
            $e['refs'] = array_values(array_unique(array_filter(array_map('mb_strtolower', $refs), function ($x) {
                return $x !== '';
            })));
            $note($e, array_values($qids), array_values($sq));
            $itemOf[$e['i']] = $k;
            $entries[] = $e;
        }
        foreach ($res['rows'] as $uid => $r) {
            if (isset($inItem[$uid])) {
                continue;
            }
            $qids = $qByUid[(string)$uid] ?? [];
            $e = $mk(['i' => count($entries), 'k' => 'row', 'st' => (string)$r['status'], 'sh' => (string)$r['sheet'], 'nm' => (string)$r['name'], 'cat' => (string)($uidCat[$uid] ?? ''),
                'ord' => (int)$r['sheet_index'] * 10000000 + (int)$r['row']]);
            $e['ns'] = mb_strtolower($e['nm']);
            $e['w'] = !empty($r['warnings']);
            $e['hay'] = mb_strtolower($e['nm'] . "\n" . $e['cat'] . "\n" . $e['sh']);
            $e['refs'] = [mb_strtolower((string)$r['ref'])];
            $note($e, $qids, []);
            $rowOf[$e['i']] = $uid;
            $entries[] = $e;
        }
        // every question the person can still answer must be reachable: one that no entry shows (odd links) is promoted to the sheet-level box
        $sheetQ = [];
        $structQ = [];
        foreach ($qs as $id => &$q) {
            if ($q['scope'] === 'entry' && empty($linked[$id])) {
                $q['scope'] = 'sheet';
            }
            if ($q['scope'] === 'sheet') {
                $sheetQ[] = $id;
            } elseif ($q['scope'] === 'structural') {
                $structQ[] = $id;
            }
        }
        unset($q);
        $counts = ['all' => count($entries), 'needs-answer' => 0, 'ready' => 0, 'skipped' => 0, 'blocked' => 0];
        foreach ($entries as $e) {
            $counts[$e['st']] = ($counts[$e['st']] ?? 0) + 1;
        }
        $tot = 0;
        $done = 0;
        foreach ($qs as $q) {
            if ($q['blocking']) {
                $tot++;
                if (!$q['is_open']) {
                    $done++;
                }
            }
        }
        $confirm = ['creates' => 0, 'updates' => 0, 'kept' => 0, 'in_count' => 0, 'no_price' => 0, 'skipped' => $counts['skipped'], 'to_answer' => $counts['needs-answer'] + $counts['blocked'],
            'cost_with_stock' => 0, 'cost_with_stock_names' => []];
        foreach ($entries as $e) {
            if ($e['k'] !== 'item' || $e['st'] !== 'ready') {
                continue;
            }
            $it = $res['items'][$itemOf[$e['i']]];
            if (!$it['match']) {
                $confirm['creates']++;
            } elseif (!empty($it['ticked'])) {
                $confirm['updates']++;
            } else {
                $confirm['kept']++;
            }
            if (!empty($it['in_count'])) {
                $confirm['in_count']++;
            } elseif (!empty($it['balance'])) {
                $confirm['no_price']++;
            }
            if ($it['match'] && isset($it['diff']['cost']) && in_array('cost', (array)$it['ticked'], true) && $e['s']) {
                $confirm['cost_with_stock']++;
                if (count($confirm['cost_with_stock_names']) < 40) {
                    $confirm['cost_with_stock_names'][] = (string)$it['match']['name'];
                }
            }
        }
        return ['entries' => $entries, 'qs' => $qs, 'item_of' => $itemOf, 'row_of' => $rowOf, 'sheet_qids' => $sheetQ, 'struct_qids' => $structQ, 'row_src' => $rowSrc,
            'entry_counts' => $counts, 'progress' => ['answered' => $done, 'total' => $tot, 'open' => $tot - $done], 'confirm' => $confirm];
    }

    /** Search terms: every word must match. A word with "!" is a cell reference (sheet part loose, cell part exact); a word like C12 matches that cell only. */
    function import_rv_compile_search(string $q): array
    {
        $terms = [];
        foreach (preg_split('/\s+/u', mb_strtolower(trim($q)), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $pos = mb_strrpos($w, '!');
            if ($pos !== false) {
                $terms[] = ['m' => 'ref', 's' => (string)preg_replace('/[^\p{L}\p{N}]+/u', '', mb_substr($w, 0, $pos)), 'c' => mb_substr($w, $pos + 1)];
            } elseif (preg_match('/^[a-z]{1,3}[0-9]{1,7}$/', $w)) {
                $terms[] = ['m' => 'cell', 'c' => $w, 'w' => $w];
            } else {
                $terms[] = ['m' => 'text', 'w' => $w];
            }
        }
        return $terms;
    }

    function import_rv_search_match(array $e, array $terms): bool
    {
        foreach ($terms as $t) {
            $hit = false;
            if ($t['m'] === 'text' || $t['m'] === 'cell') {
                $hit = mb_strpos($e['hay'], $t['w']) !== false;
            }
            if (!$hit) {
                foreach ($e['refs'] as $r) {
                    $pos = mb_strrpos($r, '!');
                    $cell = $pos === false ? $r : mb_substr($r, $pos + 1);
                    if ($t['m'] === 'text') {
                        if (mb_strpos($r, $t['w']) !== false) {
                            $hit = true;
                            break;
                        }
                    } elseif ($t['m'] === 'cell') {
                        if ($cell === $t['c']) {
                            $hit = true;
                            break;
                        }
                    } else {
                        $sp = $pos === false ? '' : (string)preg_replace('/[^\p{L}\p{N}]+/u', '', mb_substr($r, 0, $pos));
                        if (($t['c'] === '' || $cell === $t['c']) && ($t['s'] === '' || mb_strpos($sp, $t['s']) !== false)) {
                            $hit = true;
                            break;
                        }
                    }
                }
            }
            if (!$hit) {
                return false;
            }
        }
        return true;
    }

    function import_rv_fail_mask(array $e, array $p, array $terms): int
    {
        $m = 0;
        if ($terms && !import_rv_search_match($e, $terms)) {
            $m |= IMPORT_RV_BIT_Q;
        }
        if ($p['status'] && !in_array($e['st'], $p['status'], true)) {
            $m |= IMPORT_RV_BIT_STATUS;
        }
        if ($p['sheet'] !== '' && $e['sh'] !== $p['sheet']) {
            $m |= IMPORT_RV_BIT_SHEET;
        }
        if ($p['cat'] !== '' && $e['cat'] !== $p['cat']) {
            $m |= IMPORT_RV_BIT_CAT;
        }
        if ($p['type'] !== '' && empty($e['tg'][$p['type']])) {
            $m |= IMPORT_RV_BIT_TYPE;
        }
        if ($p['kind'] === 'new' && !$e['new']) {
            $m |= IMPORT_RV_BIT_KIND;
        } elseif ($p['kind'] === 'matched' && !$e['m']) {
            $m |= IMPORT_RV_BIT_KIND;
        }
        if ($p['warn'] && !$e['w']) {
            $m |= IMPORT_RV_BIT_WARN;
        }
        if ($p['cost'] && !$e['c']) {
            $m |= IMPORT_RV_BIT_COST;
        }
        if ($p['stock'] && !$e['s']) {
            $m |= IMPORT_RV_BIT_STOCK;
        }
        if ($p['answered'] && !$e['a']) {
            $m |= IMPORT_RV_BIT_ANSWERED;
        }
        return $m;
    }

    /**
     * Filter + sort. $p must have its status resolved. Facets: the count next to each choice is what the list WOULD show if that choice were
     * picked with every OTHER filter kept, so choosing a value can never lead to an empty list by surprise.
     * Returns ['hits' => [entry index, ...] sorted, 'facets' => ...|null].
     */
    function import_rv_query(array $V, array $p, bool $withFacets): array
    {
        $terms = import_rv_compile_search($p['q']);
        $E = $V['entries'];
        $hits = [];
        $f = null;
        if ($withFacets) {
            $f = ['status' => ['needs-answer' => 0, 'blocked' => 0, 'ready' => 0, 'skipped' => 0], 'sheet' => [], 'type' => [], 'kind' => ['new' => 0, 'matched' => 0],
                'warn' => 0, 'cost' => 0, 'stock' => 0, 'answered' => 0, 'cat' => [], 'cat_first' => []];
            foreach (array_keys(import_rv_groups()) as $g) {
                $f['type'][$g] = 0;
            }
        }
        $notCat = ~IMPORT_RV_BIT_CAT;
        $notCatStatus = ~(IMPORT_RV_BIT_CAT | IMPORT_RV_BIT_STATUS);
        foreach ($E as $e) {
            $m = import_rv_fail_mask($e, $p, $terms);
            if ($m === 0) {
                $hits[] = $e['i'];
            }
            if (!$withFacets) {
                continue;
            }
            if (($m & ~IMPORT_RV_BIT_STATUS) === 0) {
                $f['status'][$e['st']] = ($f['status'][$e['st']] ?? 0) + 1;
            }
            if (($m & ~IMPORT_RV_BIT_SHEET) === 0) {
                $f['sheet'][$e['sh']] = ($f['sheet'][$e['sh']] ?? 0) + 1;
            }
            if (($m & ~IMPORT_RV_BIT_TYPE) === 0) {
                foreach ($e['tg'] as $g => $_) {
                    $f['type'][$g] = ($f['type'][$g] ?? 0) + 1;
                }
            }
            if (($m & ~IMPORT_RV_BIT_KIND) === 0) {
                if ($e['new']) {
                    $f['kind']['new']++;
                } elseif ($e['m']) {
                    $f['kind']['matched']++;
                }
            }
            if (($m & ~IMPORT_RV_BIT_WARN) === 0 && $e['w']) {
                $f['warn']++;
            }
            if (($m & ~IMPORT_RV_BIT_COST) === 0 && $e['c']) {
                $f['cost']++;
            }
            if (($m & ~IMPORT_RV_BIT_STOCK) === 0 && $e['s']) {
                $f['stock']++;
            }
            if (($m & ~IMPORT_RV_BIT_ANSWERED) === 0 && $e['a']) {
                $f['answered']++;
            }
            if (($m & $notCatStatus) === 0) {              // category headers: every filter except status and category
                $c = $e['cat'];
                if (!isset($f['cat'][$c])) {
                    $f['cat'][$c] = ['name' => $c, 'items' => 0, 'needs' => 0, 'blocked' => 0, 'ready' => 0, 'skipped' => 0, 'match' => 0];
                    $f['cat_first'][$c] = $e['ord'];
                }
                $f['cat'][$c]['items']++;
                $k = $e['st'] === 'needs-answer' ? 'needs' : $e['st'];
                $f['cat'][$c][$k] = ($f['cat'][$c][$k] ?? 0) + 1;
                $f['cat_first'][$c] = min($f['cat_first'][$c], $e['ord']);
                if (($m & $notCat) === 0) {
                    $f['cat'][$c]['match']++;
                }
            }
        }
        if ($f) {
            uasort($f['cat'], function ($a, $b) use ($f) {
                return ($f['cat_first'][$a['name']] <=> $f['cat_first'][$b['name']]) ?: strnatcasecmp($a['name'], $b['name']);
            });
            unset($f['cat_first']);
        }
        $rank = ['blocked' => 0, 'needs-answer' => 1, 'ready' => 2, 'skipped' => 3];
        $sort = $p['sort'];
        usort($hits, function ($a, $b) use ($E, $sort, $rank) {
            $x = $E[$a];
            $y = $E[$b];
            $c = 0;
            if ($sort === 'name') {
                $c = strnatcasecmp($x['ns'], $y['ns']);
            } elseif ($sort === 'status') {
                $c = ($rank[$x['st']] ?? 9) <=> ($rank[$y['st']] ?? 9);
            } elseif ($sort === 'category') {
                $c = strnatcasecmp($x['cat'], $y['cat']);
            } elseif ($sort === 'price' || $sort === 'price_desc') {
                if ($x['p'] === null || $y['p'] === null) {
                    $c = ($x['p'] === null ? 1 : 0) <=> ($y['p'] === null ? 1 : 0);     // no price always last
                } else {
                    $c = $sort === 'price' ? $x['p'] <=> $y['p'] : $y['p'] <=> $x['p'];
                }
            }
            return $c ?: (($x['ord'] <=> $y['ord']) ?: ($x['i'] <=> $y['i']));
        });
        return ['hits' => $hits, 'facets' => $f];
    }

    /** answer role => this question's own option key. The role is what the person picks once; the key is what each question accepts. */
    function import_rv_roles(array $q): array
    {
        $out = [];
        $sug = isset($q['suggestion']['name']) ? (string)$q['suggestion']['name'] : null;
        foreach ((array)$q['options'] as $o) {
            $k = (string)$o['key'];
            if ($k === 'per-case') {
                continue;                       // needs a pack size typed per question: never bulk
            }
            if ($sug !== null && $k === 'use:' . $sug) {
                $out['use-suggestion'] = $k;
                continue;
            }
            if (preg_match('/^(use|date|unit|col):/', $k)) {
                continue;                       // a value that differs from question to question
            }
            $out[$k] = $k;
        }
        return $out;
    }

    function import_rv_role_label(string $role, string $type): string
    {
        $m = ['skip' => 'Skip all of them', 'use-suggestion' => 'Use the suggested match for all', 'file' => 'Keep the file\'s price for all', 'current' => 'Keep the current price for all',
            'per-unit' => 'The price is for one unit, for all', 'two-batches' => 'Separate batches of the same item, for all', 'duplicate' => 'A duplicate: import once, for all',
            'separate-items' => 'Different items, for all', 'item' => 'An item (out of stock), for all', 'section' => 'A category heading, for all',
            'first' => 'The same stock written twice: first sheet only, for all', 'add' => 'Separate stock: add the balances, for all'];
        if ($role === 'keep') {
            return $type === 'possible-typo' ? 'Create each as a new item (keep the name as written in the file)' : 'Keep as written in the file, for all';
        }
        return $m[$role] ?? $role;
    }

    /** Groups of open, bulk-able questions inside a filtered set: same type and the same list of answer roles. 2 or more questions only. */
    function import_rv_bulk_groups(array $V, array $hits, bool $withTargets = false): array
    {
        $allowed = array_flip(import_rv_bulk_types());
        $seen = [];
        $g = [];
        foreach ($hits as $i) {
            $e = $V['entries'][$i];
            foreach ($e['qi'] as $id) {
                if (isset($seen[$id])) {
                    continue;
                }
                $q = $V['qs'][$id];
                if (!$q['is_open'] || !isset($allowed[$q['type']]) || $q['scope'] !== 'entry' || !$q['options']) {
                    continue;
                }
                $roles = import_rv_roles($q);
                if (!$roles) {
                    continue;
                }
                $seen[$id] = true;
                $ks = array_keys($roles);
                sort($ks);
                $sig = implode(',', $ks);
                $gk = $q['type'] . '|' . $sig;
                if (!isset($g[$gk])) {
                    $g[$gk] = ['type' => $q['type'], 'sig' => $sig, 'group' => $q['group'], 'n' => 0, 'samples' => [], 'roles' => $ks, 'qids' => []];
                }
                $g[$gk]['n']++;
                $g[$gk]['qids'][] = $id;
                if (count($g[$gk]['samples']) < 5 && !in_array($e['nm'], $g[$gk]['samples'], true)) {
                    $g[$gk]['samples'][] = $e['nm'];
                }
            }
        }
        $out = [];
        foreach ($g as $x) {
            if ($x['n'] < 2) {
                continue;
            }
            $x['answers'] = array_map(function ($r) use ($x) {
                return ['role' => $r, 'label' => import_rv_role_label($r, $x['type'])];
            }, $x['roles']);
            unset($x['roles']);
            if (!$withTargets) {
                unset($x['qids']);
            }
            $out[] = $x;
        }
        usort($out, function ($a, $b) {
            return $b['n'] <=> $a['n'];
        });
        return $out;
    }

    /** Where the next entry that still needs an answer is, after the entries on the shown page (page number + entry index), or null. */
    function import_rv_next_open(array $V, array $hits, int $page, int $size): ?array
    {
        for ($pos = $page * $size, $n = count($hits); $pos < $n; $pos++) {
            $e = $V['entries'][$hits[$pos]];
            if ($e['st'] === 'needs-answer' || $e['st'] === 'blocked') {
                return ['page' => (int)floor($pos / $size) + 1, 'i' => $e['i']];
            }
        }
        return null;
    }

    /** One entry as the browser gets it: the item (or skipped row) and ONLY the questions that belong to it. */
    function import_rv_entry_payload(array $V, array $state, int $i, array $existingById, array $batchTotals): array
    {
        $e = $V['entries'][$i];
        $qs = [];
        foreach ($e['qi'] as $id) {
            $qs[] = $V['qs'][$id];
        }
        usort($qs, function ($a, $b) {
            return ($b['is_open'] ? 1 : 0) <=> ($a['is_open'] ? 1 : 0);
        });
        $out = ['i' => $i, 'kind' => $e['k'], 'status' => $e['st'], 'sheet' => $e['sh'], 'qs' => $qs, 'wait_sheet' => false];
        if ($e['k'] === 'item') {
            $out['it'] = import_review_enrich_item($state['res']['items'][$V['item_of'][$i]], $V['row_src'], $existingById, $batchTotals);
            foreach ($e['qsheet'] as $id) {
                if ($V['qs'][$id]['is_open']) {
                    $out['wait_sheet'] = true;
                }
            }
        } else {
            $out['r'] = $state['res']['rows'][$V['row_of'][$i]];
        }
        return $out;
    }

    /** Existing ingredient id => has stock on hand (quantity or active batches), for every ingredient the file matches: a few chunked queries. */
    function import_rv_stock_inputs(PDO $pdo, array $state): array
    {
        $existingById = [];
        foreach ($state['ctx']['existing'] as $e) {
            $existingById[(int)$e['id']] = $e;
        }
        $ids = [];
        foreach ($state['res']['items'] as $it) {
            if ($it['match']) {
                $ids[(int)$it['match']['id']] = true;
            }
        }
        $bt = import_batch_totals($pdo, array_keys($ids));
        $has = [];
        foreach (array_keys($ids) as $id) {
            $oh = import_item_on_hand($existingById, $bt, $id);
            $has[$id] = $oh ? (bool)$oh['has_stock'] : false;
        }
        return [$has, $existingById, $bt];
    }

    /**
     * The lean overview (no items, no rows, no page-level questions) and/or one page of the review, from ONE resolve of the job.
     * $params: null = overview only; an array = the request's filters (see import_rv_params) and the page is added.
     * $opts: overview (bool, default true), facets (bool), entries (bool, default true: false = facets only, used by the category view).
     */
    function import_job_review_view(PDO $pdo, int $jobId, ?array $params = null, array $opts = []): array
    {
        $t0 = microtime(true);
        $state = import_job_state($pdo, $jobId);
        if (!$state) {
            $j = import_job_get($pdo, $jobId);
            return ['ok' => false, 'error' => $j && $j['error_text'] ? (string)$j['error_text'] : 'That import cannot be reviewed.'];
        }
        [$stockById, $existingById, $batchTotals] = import_rv_stock_inputs($pdo, $state);
        $V = import_rv_build($state, $stockById);
        $res = $state['res'];
        $an = $state['analysis'];
        $out = ['ok' => true];
        if (($opts['overview'] ?? true) !== false) {
            $out += [
                'job' => ['id' => (int)$state['job']['id'], 'file' => $state['job']['original_name'], 'status' => $state['job']['status'], 'target' => $state['job']['target'],
                    'created_at' => $state['job']['created_at']],
                'file' => $an['file'], 'warnings' => $an['warnings'] ?? [], 'totals' => $an['totals'] ?? [], 'counts' => $res['counts'], 'by_sheet' => $res['by_sheet'],
                'can_confirm' => $res['can_confirm'], 'why_not' => $res['why_not'], 'open_sheet_questions' => $res['open_sheet_questions'],
                'sheets' => import_review_sheets_payload($an), 'unit_options' => $state['ctx']['unit_options'], 'lean' => true,
                'questions' => array_map(function ($id) use ($V) {
                    return $V['qs'][$id];
                }, $V['struct_qids']),
                'entry_counts' => $V['entry_counts'], 'progress' => $V['progress'], 'confirm' => $V['confirm'],
            ] + import_cont_overview($res) + ['handled' => import_cont_handled_rows($an)];
        } else {
            $out += ['can_confirm' => $res['can_confirm'], 'why_not' => $res['why_not'], 'entry_counts' => $V['entry_counts'], 'progress' => $V['progress']] + import_cont_overview($res);
        }
        if ($params !== null) {
            $p = import_rv_resolve_status(import_rv_params($params), $V['entry_counts']);
            $withFacets = !empty($opts['facets']);
            $q = import_rv_query($V, $p, $withFacets);
            $hits = $q['hits'];
            $total = count($hits);
            $pages = max(1, (int)ceil($total / $p['size']));
            $p['page'] = min($p['page'], $pages);
            $page = ['filters' => $p, 'total' => $total, 'pages' => $pages, 'page' => $p['page'], 'size' => $p['size'], 'entries' => [], 'bulk' => [], 'next_open' => null];
            if (($opts['entries'] ?? true) !== false) {
                foreach (array_slice($hits, ($p['page'] - 1) * $p['size'], $p['size']) as $i) {
                    $page['entries'][] = import_rv_entry_payload($V, $state, $i, $existingById, $batchTotals);
                }
                $page['next_open'] = import_rv_next_open($V, $hits, $p['page'], $p['size']);
            }
            $page['bulk'] = import_rv_bulk_groups($V, $hits);        // always for the WHOLE filtered set, not just the shown page
            $sq = [];
            foreach ($V['sheet_qids'] as $id) {
                $s = $V['qs'][$id]['sheet'];
                if ($p['sheet'] === '' || $s === null || $s === $p['sheet']) {
                    $sq[] = $V['qs'][$id];
                }
            }
            $page['sheet_questions'] = $sq;
            if ($withFacets) {
                $f = $q['facets'];
                $f['sheet_list'] = array_map(function ($sh) use ($f) {
                    return ['name' => $sh['name'], 'n' => (int)($f['sheet'][$sh['name']] ?? 0)];
                }, array_values(array_filter($an['sheets'], function ($sh) {
                    return ($sh['triage']['action'] ?? '') === 'import';
                })));
                $cats = array_values($f['cat']);
                $f['cat_total'] = count($cats);
                $f['cat'] = array_slice($cats, 0, 500);
                $f['type_list'] = [];
                foreach (import_rv_groups() as $code => $label) {
                    $f['type_list'][] = ['code' => $code, 'label' => $label, 'n' => (int)($f['type'][$code] ?? 0)];
                }
                unset($f['sheet'], $f['type']);
                $page['facets'] = $f;
            }
            $page['ms'] = (int)round((microtime(true) - $t0) * 1000);
            $out['page'] = $page;
        }
        return $out;
    }

    /**
     * Apply ONE answer role to exactly the questions of one group inside the filtered set - or only describe it ($dry).
     * The set is recomputed here from the posted filters (the browser's list is never trusted); the preview returns a fingerprint of the
     * questions it saw and the apply refuses when the set has changed since. Every question is checked with import_answer_allowed();
     * all accepted answers are written in ONE update. Returns the numbers: n (in the set), applied, refused, reasons.
     */
    function import_job_bulk(PDO $pdo, int $jobId, array $params, string $type, string $sig, string $role, bool $dry, string $fingerprint, int $userId = 0, ?callable $afterPlan = null): array
    {
        $fail = function (string $m) {
            return ['ok' => false, 'error' => $m];
        };
        if (!in_array($type, import_rv_bulk_types(), true)) {
            return $fail('That kind of question cannot be answered in bulk.');
        }
        if ($role === '' || $role === 'per-case' || strlen($role) > 60) {
            return $fail('Choose one of the answers offered.');
        }
        $state = import_job_state($pdo, $jobId);
        if (!$state) {
            return $fail('That import cannot be reviewed.');
        }
        if (!in_array($state['job']['status'], ['analysed', 'reviewing'], true)) {
            return $fail('This import is already ' . $state['job']['status'] . ' and cannot be changed.');
        }
        [$stockById] = import_rv_stock_inputs($pdo, $state);
        $V = import_rv_build($state, $stockById);
        $p = import_rv_resolve_status(import_rv_params($params), $V['entry_counts']);
        $hits = import_rv_query($V, $p, false)['hits'];
        $target = null;
        foreach (import_rv_bulk_groups($V, $hits, true) as $g) {
            if ($g['type'] === $type && $g['sig'] === $sig) {
                $target = $g;
                break;
            }
        }
        if (!$target) {
            return $fail('There are no matching questions left in this list. Reload the list and look again.');
        }
        $keys = [];
        foreach ($target['qids'] as $id) {
            $keys[] = $V['qs'][$id]['key'];
        }
        sort($keys);
        $fp = substr(sha1($type . '|' . $sig . '|' . $role . '|' . implode(',', $keys)), 0, 24);
        // question by question: the role becomes this question's own option, which must pass the same check as a single answer
        $ok = [];
        $why = [];
        foreach ($target['qids'] as $id) {
            $q = $V['qs'][$id];
            $roles = import_rv_roles($q);
            if (!isset($roles[$role])) {
                $why['This answer is not one of the choices for this question.'] = ($why['This answer is not one of the choices for this question.'] ?? 0) + 1;
                continue;
            }
            $reason = import_answer_allowed($q, $roles[$role]);
            if ($reason !== '') {
                $why[$reason] = ($why[$reason] ?? 0) + 1;
                continue;
            }
            $ok[$id] = $roles[$role];
        }
        $sample = array_slice($target['samples'], 0, 5);
        if ($dry) {
            return ['ok' => true, 'dry' => true, 'n' => (int)$target['n'], 'would_apply' => count($ok), 'would_refuse' => (int)$target['n'] - count($ok), 'reasons' => $why,
                'samples' => $sample, 'fingerprint' => $fp, 'label' => import_rv_role_label($role, $type)];
        }
        if (!hash_equals($fp, $fingerprint)) {
            return $fail('The list changed since you looked at it, so nothing was applied. Check the new count and apply again.');
        }
        if (!$ok) {
            return ['ok' => true, 'applied' => 0, 'refused' => (int)$target['n'], 'reasons' => $why, 'n' => (int)$target['n']];
        }
        if ($afterPlan) {
            $afterPlan();                  // tests only: lets another person answer between the plan above and the lock below
        }
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $st = $pdo->prepare('SELECT answers_json, status FROM import_jobs WHERE id = ? FOR UPDATE');
            $st->execute([$jobId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || !in_array($row['status'], ['analysed', 'reviewing'], true)) {
                throw new RuntimeException('This import can no longer be changed.');
            }
            $answers = $row['answers_json'] !== null && $row['answers_json'] !== '' ? json_decode((string)$row['answers_json'], true) : [];
            $answers = is_array($answers) ? $answers : [];
            $now = date('Y-m-d H:i:s');
            // Decided HERE, under the row lock, against the answers as they are right now: a question that somebody else answered after
            // the plan was made is left exactly as they answered it (never overwritten) and is reported as refused.
            $taken = 0;
            foreach ($ok as $id => $key) {
                $qk = $V['qs'][$id]['key'];
                if (isset($answers[$qk])) {
                    unset($ok[$id]);
                    $taken++;
                    continue;
                }
                $answers[$qk] = ['key' => $key, 'by' => $userId > 0 ? $userId : null, 'at' => $now];
            }
            if ($taken) {
                $why['Answered by someone else while this was being applied: their answer was kept.'] = $taken;
            }
            $pdo->prepare("UPDATE import_jobs SET answers_json = ?, status = 'reviewing' WHERE id = ? AND status IN ('analysed','reviewing')")->execute([import_json($answers), $jobId]);
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $ex) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $fail($ex instanceof RuntimeException ? $ex->getMessage() : 'The answers could not be saved. Nothing was changed.');
        }
        return ['ok' => true, 'applied' => count($ok), 'refused' => (int)$target['n'] - count($ok), 'reasons' => $why, 'n' => (int)$target['n']];
    }

    /**
     * The job row WITHOUT the stored analysis text (3.5 MB per 5,000 rows, held for the whole request otherwise): analysis_json is only 'stored'
     * or null, and analysis_bytes says how big it is (used to check that this server can open it).
     */
    function import_job_get_light(PDO $pdo, int $jobId): ?array
    {
        $st = $pdo->prepare("SELECT id, target, original_name, stored_path, sha256, size_bytes, status, answers_json, result_json, error_text, created_by, created_at, updated_at,
                                    committed_by, committed_at, expires_at, IF(analysis_json IS NULL OR analysis_json = '', NULL, 'stored') AS analysis_json,
                                    COALESCE(LENGTH(analysis_json), 0) AS analysis_bytes
                             FROM import_jobs WHERE id = ?");
        $st->execute([$jobId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** The job a person may open: their own, or any job for an admin (target stock only). */
    function import_job_for_user(PDO $pdo, int $jobId, int $uid, bool $isAdmin): ?array
    {
        $job = $jobId > 0 ? import_job_get_light($pdo, $jobId) : null;
        if (!$job || ($job['target'] ?? '') !== 'stock') {
            return null;
        }
        if (!$isAdmin && (int)($job['created_by'] ?? 0) !== $uid) {
            return null;
        }
        return $job;
    }
}
