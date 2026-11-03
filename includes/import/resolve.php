<?php
/**
 * Import - answers -> final rows (pure: no database, no output). Phase 1b.
 *
 *   import_q_key($q)                              stable key of a question (survives re-analysis; the engine's ids are positional)
 *   import_resolve($analysis, $answers, $ctx)     applies a person's answers to an analysis and returns the final picture:
 *                                                 per-row status (ready / needs-answer / blocked / skipped), the items that would be
 *                                                 created or updated, extra questions the engine cannot know (no unit, the same item on
 *                                                 two sheets, archived / unit-clash matches ...) and whether the job can be confirmed.
 *   import_resolve_csv($res)                      the "what will be imported" CSV (formula-looking cells are prefixed, shown as text)
 *   import_answer_allowed($q, $answer, $ctx)      validates ONE answer against the question's own options
 *
 * Nothing here invents a value. A final value is either the (already verified) value of a source cell, a value picked from the
 * question's options (which are themselves source values), or a number the person typed for a pack size / a unit they chose.
 * Status is always computed here from the stored answers; a status sent by a browser is never read.
 *
 * $answers = ['<qkey>' => ['key'=>'use:Name', 'pack_size'=>12, ...], '_overrides'=>[...], '_updates'=>['<existing key>'=>['cost','par']]]
 * $ctx     = ['existing' => [stock_ingredients rows + 'recipes'], 'unit_options' => [unit names], 'corrections' => [norm => name]]
 */

require_once __DIR__ . '/analyze.php';
require_once dirname(__DIR__, 2) . '/admin/includes/stock-sheet-import-lib.php';   // rh_ssi_key / rh_ssi_norm_unit / rh_ssi_units_compatible
require_once dirname(__DIR__) . '/form-validation.php';                           // rh_clean_text (same cleaning as the manual Add form)

if (!function_exists('import_resolve')) {

    /** Fields of an existing ingredient a person may tick to change (everything else is kept as it is). */
    function import_update_fields(): array
    {
        return ['category', 'cost', 'par', 'pack', 'quantity'];
    }

    function import_q_key(array $q): string
    {
        $uids = array_map('strval', (array)($q['row_uids'] ?? []));
        sort($uids);
        $cells = array_map('strval', (array)($q['cells'] ?? []));
        sort($cells);
        return substr(sha1(implode("\x1e", [(string)$q['type'], (string)($q['sheet'] ?? ''), (string)($q['row'] ?? ''), implode(',', $uids), implode(',', $cells), (string)($q['conflict_field'] ?? '')])), 0, 16);
    }

    /** Normalise a posted answer to ['key'=>string, ...detail]. */
    function import_answer_shape($answer): array
    {
        if (is_array($answer)) {
            $a = $answer;
            $a['key'] = isset($a['key']) ? (string)$a['key'] : '';
            return $a;
        }
        return ['key' => (string)$answer];
    }

    /**
     * Is this answer one of the question's allowed options? Returns '' when fine, else a plain reason.
     * case-price "per-case" also needs a whole pack size 2..100000 typed by the person.
     */
    function import_answer_allowed(array $q, $answer): string
    {
        $a = import_answer_shape($answer);
        $keys = array_map(function ($o) {
            return (string)$o['key'];
        }, (array)($q['options'] ?? []));
        if ($a['key'] === '' || !in_array($a['key'], $keys, true)) {
            return 'That is not one of the choices for this question.';
        }
        if (($q['type'] ?? '') === 'case-price' && $a['key'] === 'per-case') {
            $p = $a['pack_size'] ?? null;
            if (!is_int($p) && !(is_string($p) && ctype_digit($p))) {
                return 'Enter how many units are in the case/pack (a whole number).';
            }
            $p = (int)$p;
            if ($p < 2 || $p > 100000) {
                return 'The pack size must be a whole number from 2 to 100000.';
            }
        }
        return '';
    }

    /** Write the stored answers into the analysis' questions and let the engine recompute row statuses. */
    function import_apply_answers(array &$analysis, array $answers, array $ctx = []): void
    {
        $rowName = [];
        foreach ($analysis['sheets'] as $sh) {
            foreach ($sh['rows'] as $r) {
                $rowName[$r['uid']] = $r['fields']['item_name']['value'] ?? '';
            }
        }
        $corr = (array)($ctx['corrections'] ?? []);
        foreach ($analysis['questions'] as &$q) {
            $k = import_q_key($q);
            $q['qkey'] = $k;
            $q['answer'] = null;
            $q['answer_detail'] = null;
            $q['auto'] = false;
            if (isset($answers[$k]) && is_array($answers[$k])) {
                $q['answer'] = (string)($answers[$k]['key'] ?? '');
                $q['answer_detail'] = $answers[$k];
                continue;
            }
            // a correction this hotel made before ("Heinken" is "Heineken"): offered as the answer, visible, and changeable
            if (($q['type'] ?? '') === 'possible-typo' && ($q['against'] ?? '') === 'existing' && !empty($q['suggestion']['name'])) {
                $typed = $rowName[($q['row_uids'][0] ?? '')] ?? '';
                $nk = import_norm_text($typed);
                if ($nk !== '' && isset($corr[$nk]) && $corr[$nk] === $q['suggestion']['name']) {
                    $q['answer'] = 'use:' . $q['suggestion']['name'];
                    $q['auto'] = true;
                }
            }
        }
        unset($q);
        import_recompute_statuses($analysis);
    }

    function import_status_rank(string $s): int
    {
        return ['ready' => 0, 'needs-answer' => 1, 'blocked' => 2, 'skipped' => -1][$s] ?? 1;
    }

    /** The synthetic question the engine cannot know (needs the stock tables or several sheets together). */
    function import_xq(string $type, string $keyPart, string $text, array $options, array $o = []): array
    {
        $q = [
            'id' => 'x' . substr(sha1($type . '|' . $keyPart), 0, 10), 'qkey' => substr(sha1($type . '|' . $keyPart), 0, 16),
            'type' => $type, 'text' => $text, 'blocking' => true, 'bad' => false, 'synthetic' => true,
            'sheet' => null, 'row' => null, 'rows' => [], 'cells' => [], 'options' => $options, 'default' => null,
            'answer' => null, 'answer_detail' => null, 'auto' => false, 'row_uids' => [],
        ];
        return array_merge($q, $o);
    }

    /**
     * The same limits as the manual Add / Edit form of admin/stock-ingredients.php: name 100, category 50, unit 20 characters.
     * Returns plain reasons (empty = fine). A new item's name and unit are checked; a category is checked when it will be written.
     */
    function import_limit_problems(array $it): array
    {
        $p = [];
        $isNew = empty($it['match']);
        if ($isNew) {
            $n = (string)$it['name'];
            if ($n === '') {
                $p[] = 'the name is empty';
            } elseif (mb_strlen($n) > 100) {
                $p[] = 'the name is longer than 100 characters';
            }
            if ($it['unit'] !== null && mb_strlen((string)$it['unit']) > 20) {
                $p[] = 'the unit is longer than 20 characters';
            }
        }
        if ($isNew || in_array('category', (array)($it['ticked'] ?? []), true)) {
            $c = (string)$it['category'];
            if ($c === '') {
                $p[] = 'the category is empty';
            } elseif (mb_strlen($c) > 50) {
                $p[] = 'the category is longer than 50 characters';
            }
        }
        return $p;
    }

    /** "60,000 per case of 12 = 5,000 each": a case price shown next to the per-unit cost it turns into. */
    function import_pack_label($price, $pack): string
    {
        return import_fmt_money($price) . ' per case of ' . (int)$pack . ' = ' . import_fmt_money(round((float)$price / max(1, (int)$pack), 4)) . ' each';
    }

    function import_fmt_qty($n): string
    {
        return rtrim(rtrim(number_format((float)$n, 3, '.', ','), '0'), '.');
    }

    /** Existing ingredient by name: exact (case/space) first, then normalised; ambiguous when several match only by normalising. */
    function import_match_existing(string $name, array $ctx): array
    {
        $none = ['row' => null, 'how' => '', 'ambiguous' => false];
        $k = rh_ssi_key($name);
        $n = import_norm_text($name);
        if ($k === '') {
            return $none;
        }
        $idx = $ctx['_ex_index'] ?? null;
        if ($idx === null) {
            $idx = import_index_existing((array)($ctx['existing'] ?? []));
        }
        if (isset($idx['exact'][$k])) {
            return ['row' => $idx['exact'][$k], 'how' => 'exact', 'ambiguous' => false];
        }
        if (isset($idx['norm'][$n])) {
            $c = $idx['norm'][$n];
            return count($c) === 1 ? ['row' => $c[0], 'how' => 'normalised', 'ambiguous' => false] : ['row' => $c[0], 'how' => 'normalised', 'ambiguous' => true];
        }
        return $none;
    }

    function import_index_existing(array $existing): array
    {
        $idx = ['exact' => [], 'norm' => []];
        foreach ($existing as $e) {
            $idx['exact'][rh_ssi_key((string)$e['name'])] = $idx['exact'][rh_ssi_key((string)$e['name'])] ?? $e;
            $idx['norm'][import_norm_text((string)$e['name'])][] = $e;
        }
        return $idx;
    }

    /**
     * The whole picture after the person's answers. See the file header for the shapes.
     */
    function import_resolve(array $analysis, array $answers, array $ctx = []): array
    {
        $ctx['_ex_index'] = import_index_existing((array)($ctx['existing'] ?? []));
        $unitOptions = array_values(array_unique(array_map('strval', (array)($ctx['unit_options'] ?? []))));
        $updates = (array)($answers['_updates'] ?? []);
        import_apply_answers($analysis, $answers, $ctx);

        $qById = [];
        foreach ($analysis['questions'] as $q) {
            $qById[$q['id']] = $q;
        }
        $convByQ = [];
        foreach ($analysis['conflicts'] as $c) {
            $convByQ[$c['question_id']] = $c;
        }
        $extra = [];            // qkey => synthetic question
        $ans = function (array $xq) use ($answers) {      // pick up a stored answer for a synthetic question
            $a = $answers[$xq['qkey']] ?? null;
            if (is_array($a) && isset($a['key'])) {
                foreach ($xq['options'] as $o) {
                    if ((string)$o['key'] === (string)$a['key']) {
                        $xq['answer'] = (string)$a['key'];
                        $xq['answer_detail'] = $a;
                        break;
                    }
                }
            }
            return $xq;
        };

        $packLabels = [];       // question id => [option key => "60,000 per case of 12 = 5,000 each"]
        $rowsOut = [];          // uid => record
        $cands = [];            // surviving rows with their final values
        $openSheetQs = [];
        foreach ($analysis['sheets'] as $sh) {
            $act = $sh['triage']['action'] ?? 'skip';
            foreach ($analysis['questions'] as $q) {
                if ($q['sheet'] === $sh['name'] && $q['row'] === null && empty($q['row_uids']) && $q['blocking'] && $q['answer'] === null && in_array($act, ['import', 'ask'], true)) {
                    $openSheetQs[] = $q['id'];
                }
            }
            if ($act !== 'import') {
                continue;
            }
            $cleanSheet = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', (string)$sh['name']));
            $cleanSheet = mb_substr($cleanSheet !== '' ? $cleanSheet : (string)$sh['name'], 0, 100);
            foreach ($sh['rows'] as $row) {
                $f = $row['fields'];
                $rec = [
                    'uid' => $row['uid'], 'sheet' => $sh['name'], 'sheet_index' => $row['sheet_index'], 'row' => $row['row'], 'ref' => $row['ref'],
                    'name' => $f['item_name']['value'], 'status' => $row['status'], 'reason' => '', 'warnings' => array_map(function ($w) {
                        return $w['text'];
                    }, $row['warnings']),
                ];
                $fin = [
                    'name' => $f['item_name']['value'], 'name_source' => $f['item_name']['source'], 'match_name' => null, 'price' => isset($f['unit_price']) ? $f['unit_price']['value'] : null,
                    'price_source' => isset($f['unit_price']) ? $f['unit_price']['source'] : null, 'price_how' => isset($f['unit_price']) ? $f['unit_price']['how'] : null,
                    'unit_raw' => isset($f['unit']) ? $f['unit']['value'] : null, 'unit_source' => isset($f['unit']) ? $f['unit']['source'] : null,
                    'pack' => null, 'pack_source' => null, 'expiry' => isset($f['expiry']) ? $f['expiry']['value'] : null, 'expiry_source' => isset($f['expiry']) ? $f['expiry']['source'] : null,
                    'batch' => isset($f['batch']) ? $f['batch']['value'] : null, 'max' => isset($f['max_level']) ? $f['max_level']['value'] : null,
                    'min' => isset($f['min_level']) ? $f['min_level']['value'] : null, 'remarks' => isset($f['remarks']) ? $f['remarks']['value'] : null,
                    'qty' => isset($row['computed']['qty_total']) ? $row['computed']['qty_total']['value'] : null,
                    'qty_unknown' => (bool)array_intersect(array_keys($row['unknown']), ['qty_primary', 'qty_secondary', 'qty_single']),
                    'category' => $row['category'] !== null && $row['category'] !== '' ? $row['category'] : $cleanSheet,
                    'category_how' => $row['category'] !== null && $row['category'] !== '' ? 'section-row' : 'sheet-name',
                    'category_source' => $row['category'] !== null && $row['category'] !== '' ? $row['category_source'] : null,
                ];
                $skip = '';
                $dupQ = null;
                $needPack = false;
                $origPriceSource = isset($f['unit_price']) ? $f['unit_price']['source'] : null;
                foreach ($row['question_ids'] as $qid) {
                    $q = $qById[$qid] ?? null;
                    if (!$q) {
                        continue;
                    }
                    $a = $q['answer'];
                    switch ($q['type']) {
                        case 'bad-value':
                        case 'formula-name':
                            if ($a === 'skip') {
                                $skip = 'Skipped by you.';
                            }
                            break;
                        case 'section-or-item':
                            if ($a === 'section') {
                                $skip = 'Treated as a category heading, not an item.';
                            }
                            break;
                        case 'ambiguous-date':
                            if ($a !== null && strpos($a, 'date:') === 0) {
                                $fin['expiry'] = substr($a, 5);
                            }
                            break;
                        case 'conflict':
                            if ($a === 'skip') {
                                $skip = 'Skipped by you (the values disagreed).';
                            } elseif ($a !== null && strpos($a, 'use:') === 0 && isset($convByQ[$q['id']])) {
                                foreach ($convByQ[$q['id']]['values'] as $v) {
                                    if ('use:' . $v['label'] === $a) {
                                        if ($convByQ[$q['id']]['field'] === 'unit_price') {
                                            $fin['price'] = $v['value'];
                                            $fin['price_source'] = $v['source'];
                                            $fin['price_how'] = 'chosen-from-conflict';
                                        } else {
                                            $fin['unit_raw'] = (string)$v['value'];
                                            $fin['unit_source'] = $v['source'];
                                        }
                                        break;
                                    }
                                }
                            }
                            break;
                        case 'case-price':
                            if ($a === 'per-case') {
                                $p = (int)($q['answer_detail']['pack_size'] ?? 0);
                                if ($p >= 2) {
                                    // the pack belongs to the price in THIS cell only (money rule): it is never divided into another cell's price
                                    $fin['pack'] = $p;
                                    $fin['pack_source'] = $origPriceSource ?? (string)($q['cells'][0] ?? '');
                                } else {
                                    $needPack = true;
                                }
                            }
                            break;
                        case 'possible-typo':
                            if ($a !== null && strpos($a, 'use:') === 0) {
                                $chosen = substr($a, 4);
                                if (($q['against'] ?? '') === 'existing') {
                                    $fin['match_name'] = $chosen;
                                    $fin['match_auto'] = !empty($q['auto']);
                                } else {
                                    $fin['name'] = $chosen;
                                }
                            }
                            break;
                        case 'duplicate-no-batch':
                        case 'duplicate-batch-clash':
                            $dupQ = $q;
                            break;
                        default:
                            if ($a === 'skip') {
                                $skip = 'Skipped by you.';
                            }
                    }
                }
                if ($fin['pack'] && $fin['pack_source'] !== null) {
                    // conflict questions about this row's price: show the per-unit value next to the case price it comes from
                    foreach ($row['question_ids'] as $qid) {
                        $cq = $qById[$qid] ?? null;
                        if ($cq && $cq['type'] === 'conflict' && isset($convByQ[$qid]) && $convByQ[$qid]['field'] === 'unit_price') {
                            foreach ($convByQ[$qid]['values'] as $v) {
                                if ($v['source'] === $fin['pack_source'] && is_numeric($v['value'])) {
                                    $packLabels[$qid]['use:' . $v['label']] = import_pack_label($v['value'], $fin['pack']);
                                }
                            }
                        }
                    }
                }
                if ($skip !== '') {
                    $rec['status'] = 'skipped';
                    $rec['reason'] = $skip;
                    $rowsOut[$row['uid']] = $rec;
                    continue;
                }
                if ($needPack && $rec['status'] === 'ready') {
                    $rec['status'] = 'needs-answer';
                    $rec['reason'] = 'Enter the pack size for the case price.';
                }
                // a zero price means "no price" (assumption A11), never a cost of 0
                if ($fin['price'] !== null && import_is_zero($fin['price'])) {
                    $fin['price'] = null;
                    $rec['warnings'][] = 'The price is 0, so it is treated as no price.';
                }
                $cands[] = ['rec' => $rec, 'fin' => $fin, 'sheet' => $sh['name'], 'cleanSheet' => $cleanSheet, 'dup' => $dupQ, 'group' => $row['group'] ?? null];
                $rowsOut[$row['uid']] = $rec;
            }
        }

        // ---- group rows into items --------------------------------------------------------------------
        // 1) inside one sheet: the engine's own duplicate answer decides (batches / duplicate / separate items)
        $bySheetName = [];
        foreach ($cands as $i => $c) {
            $bySheetName[$c['sheet']][import_norm_text($c['fin']['name'])][] = $i;
        }
        $sheetItems = [];     // list of ['members'=>[cand indexes], 'sheet'=>..]
        foreach ($bySheetName as $sheetName => $groups) {
            foreach ($groups as $nk => $idxs) {
                if (count($idxs) === 1) {
                    $sheetItems[] = ['members' => $idxs, 'sheet' => $sheetName, 'nk' => $nk];
                    continue;
                }
                $first = $cands[$idxs[0]];
                $res = $first['group']['resolution'] ?? null;
                $a = $first['dup']['answer'] ?? null;
                if ($res === 'batches' || $a === 'two-batches') {
                    $sheetItems[] = ['members' => $idxs, 'sheet' => $sheetName, 'nk' => $nk];
                } elseif ($a === 'duplicate') {
                    $sheetItems[] = ['members' => [$idxs[0]], 'sheet' => $sheetName, 'nk' => $nk];
                    foreach (array_slice($idxs, 1) as $j) {
                        $uid = $cands[$j]['rec']['uid'];
                        $rowsOut[$uid]['status'] = 'skipped';
                        $rowsOut[$uid]['reason'] = 'A duplicate of row ' . $cands[$idxs[0]]['rec']['row'] . ' (you chose to import it once).';
                        $cands[$j]['dropped'] = true;
                    }
                } elseif ($a === 'separate-items') {
                    foreach ($idxs as $j) {
                        $uid = $cands[$j]['rec']['uid'];
                        $rowsOut[$uid]['status'] = 'blocked';
                        $rowsOut[$uid]['reason'] = 'Two different items cannot share one name in stock. Rename one in the sheet and upload again, or skip it.';
                        $cands[$j]['dropped'] = true;
                    }
                } else {
                    // unanswered: shown merged, but not importable until answered (the engine already marks the rows needs-answer)
                    $sheetItems[] = ['members' => $idxs, 'sheet' => $sheetName, 'nk' => $nk, 'unanswered' => true];
                }
            }
        }
        // 2) across sheets: the same item on two sheets is a question (the balances might be the same stock listed twice)
        $byNk = [];
        foreach ($sheetItems as $si => $it) {
            $byNk[$it['nk']][] = $si;
        }
        $items = [];
        foreach ($byNk as $nk => $sis) {
            $members = [];
            $sheetsInvolved = [];
            foreach ($sis as $si) {
                $members = array_merge($members, $sheetItems[$si]['members']);
                $sheetsInvolved[$sheetItems[$si]['sheet']] = true;
            }
            $open = [];
            $unanswered = false;
            foreach ($sis as $si) {
                $unanswered = $unanswered || !empty($sheetItems[$si]['unanswered']);
            }
            if (count($sis) > 1) {
                $firstName = $cands[$members[0]]['fin']['name'];
                $xq = $ans(import_xq('same-item-two-sheets', $nk,
                    '"' . $firstName . '" is listed on ' . count($sis) . ' sheets (' . implode(', ', array_keys($sheetsInvolved)) . '). Is it the same stock written twice, or separate stock to add together?',
                    [['key' => 'add', 'label' => 'Separate stock: add the balances together'], ['key' => 'first', 'label' => 'The same stock written twice: use the first sheet only'], ['key' => 'skip', 'label' => 'Skip this item']],
                    ['sheet' => array_key_first($sheetsInvolved), 'cells' => array_map(function ($i) use ($cands) {
                        return $cands[$i]['rec']['ref'];
                    }, $members), 'row_uids' => array_map(function ($i) use ($cands) {
                        return $cands[$i]['rec']['uid'];
                    }, $members)]));
                $extra[$xq['qkey']] = $xq;
                if ($xq['answer'] === 'first') {
                    $keep = $sheetItems[$sis[0]]['members'];
                    foreach ($members as $j) {
                        if (!in_array($j, $keep, true)) {
                            $uid = $cands[$j]['rec']['uid'];
                            $rowsOut[$uid]['status'] = 'skipped';
                            $rowsOut[$uid]['reason'] = 'The same item is on an earlier sheet (you chose to use that one only).';
                        }
                    }
                    $members = $keep;
                } elseif ($xq['answer'] === 'skip') {
                    foreach ($members as $j) {
                        $uid = $cands[$j]['rec']['uid'];
                        $rowsOut[$uid]['status'] = 'skipped';
                        $rowsOut[$uid]['reason'] = 'Skipped by you.';
                    }
                    continue;
                } elseif ($xq['answer'] === null) {
                    $open[] = $xq['id'];
                }
            }
            $items[] = ['nk' => $nk, 'members' => $members, 'open' => $open, 'unanswered' => $unanswered];
        }

        // ---- build each item ---------------------------------------------------------------------------
        $outItems = [];
        $noUnitByItem = [];
        foreach ($items as $it) {
            $ms = array_map(function ($i) use ($cands) {
                return $cands[$i];
            }, $it['members']);
            $first = $ms[0]['fin'];
            $open = $it['open'];
            $warn = [];
            // price / unit must agree across the merged rows, else a question
            foreach (['price' => 'unit price', 'unit_raw' => 'unit'] as $fld => $word) {
                $vals = [];
                foreach ($ms as $m) {
                    $v = $m['fin'][$fld];
                    if ($v === null || $v === '') {
                        continue;
                    }
                    $label = $fld === 'price' ? import_fmt_money($v) : (string)$v;
                    if ($fld === 'price' && $m['fin']['pack'] && $m['fin']['pack_source'] === $m['fin']['price_source']) {
                        $label = import_pack_label($v, $m['fin']['pack']);      // a case price: shown with what it comes to per unit
                    }
                    $vals[$fld === 'price' ? $label : import_unit_key((string)$v)] = ['label' => $label, 'value' => $v, 'source' => $m['fin'][$fld === 'price' ? 'price_source' : 'unit_source']];
                }
                if (count($vals) > 1) {
                    $xq = $ans(import_xq('merge-conflict', $it['nk'] . '|' . $fld,
                        '"' . $first['name'] . '" has different ' . $word . 's on its rows: ' . implode(' vs ', array_map(function ($v) {
                            return $v['label'] . ' (' . $v['source'] . ')';
                        }, $vals)) . '. Which is right?',
                        array_merge(array_map(function ($v) {
                            return ['key' => 'use:' . $v['label'], 'label' => 'Use ' . $v['label']];
                        }, array_values($vals)), [['key' => 'skip', 'label' => 'Skip this item']]),
                        ['sheet' => $ms[0]['sheet'], 'cells' => array_column($vals, 'source'), 'row_uids' => array_map(function ($m) {
                            return $m['rec']['uid'];
                        }, $ms)]));
                    $extra[$xq['qkey']] = $xq;
                    if ($xq['answer'] === null) {
                        $open[] = $xq['id'];
                    } elseif ($xq['answer'] === 'skip') {
                        foreach ($ms as $m) {
                            $rowsOut[$m['rec']['uid']]['status'] = 'skipped';
                            $rowsOut[$m['rec']['uid']]['reason'] = 'Skipped by you (the values disagreed).';
                        }
                        continue 2;
                    } else {
                        foreach ($vals as $v) {
                            if ('use:' . $v['label'] === $xq['answer']) {
                                $first[$fld] = $v['value'];
                                $first[$fld === 'price' ? 'price_source' : 'unit_source'] = $v['source'];
                                $first[$fld === 'price' ? 'price_how' : 'unit_how'] = 'chosen';
                            }
                        }
                    }
                } elseif (count($vals) === 1) {
                    $v = array_values($vals)[0];
                    $first[$fld] = $v['value'];
                    $first[$fld === 'price' ? 'price_source' : 'unit_source'] = $v['source'];
                }
            }
            // the pack is divided only into the price of the cell whose per-case answer set it (money rule)
            $pack = null;
            foreach ($ms as $m) {
                if ($m['fin']['pack']) {
                    if ($m['fin']['pack_source'] !== null && $m['fin']['pack_source'] === $first['price_source']) {
                        $pack = $m['fin']['pack'];
                    } else {
                        $warn[] = 'A pack size of ' . (int)$m['fin']['pack'] . ' was entered for the case price in ' . $m['fin']['pack_source'] . ', but the price used is the one in '
                            . ($first['price_source'] ?? 'another cell') . ', so the pack size is not applied to it.';
                    }
                }
            }
            $balance = null;
            $unknownQty = false;
            foreach ($ms as $m) {
                if ($m['fin']['qty_unknown']) {
                    $unknownQty = true;
                }
                if ($m['fin']['qty'] !== null) {
                    $balance = ($balance ?? 0) + $m['fin']['qty'];
                }
            }
            if ($unknownQty) {
                $balance = null;
                $warn[] = 'A balance on this item is a formula that was never calculated, so no opening stock is proposed (it is not read as 0).';
            }
            $batches = [];
            foreach ($ms as $m) {
                $q = $m['fin']['qty'];
                $label = $m['fin']['batch'];
                $exp = $m['fin']['expiry'];
                if ($q !== null && $q > 0 && !$unknownQty && ($exp !== null || ($label !== null && $label !== ''))) {
                    $batches[] = ['label' => $label !== null ? mb_substr($label, 0, 50) : '', 'qty' => $q, 'expiry' => $exp, 'source' => $m['fin']['expiry_source'] ?? $m['fin']['name_source']];
                }
            }
            $max = null;
            $min = null;
            $remarks = null;
            foreach ($ms as $m) {
                if ($m['fin']['max'] !== null) {
                    $max = $max === null ? $m['fin']['max'] : max($max, $m['fin']['max']);
                }
                if ($m['fin']['min'] !== null) {
                    $min = $min === null ? $m['fin']['min'] : max($min, $m['fin']['min']);
                }
                if ($remarks === null && $m['fin']['remarks'] !== null) {
                    $remarks = $m['fin']['remarks'];
                }
            }
            $cost = null;
            $costHow = null;
            if ($first['price'] !== null) {
                if ($pack) {
                    $cost = round((float)$first['price'] / $pack, 4);
                    $costHow = 'case price / pack size ' . $pack;
                } else {
                    $cost = round((float)$first['price'], 4);
                    $costHow = $first['price_how'] ?? 'as-written';
                }
            }
            // match with what is already in stock
            $lookupName = $first['match_name'] ?? $first['name'];
            $m = import_match_existing($lookupName, $ctx);
            $matchHow = $m['how'];
            $ex = $m['row'];
            $rawUnit = $first['unit_raw'];
            $unit = ($rawUnit !== null && $rawUnit !== '') ? rh_ssi_norm_unit((string)$rawUnit) : null;
            $unitHow = $unit !== null ? 'from the sheet' : null;
            $action = $ex ? 'update' : 'create';
            $diff = [];
            $itemOpen = $open;
            $uidList = array_map(function ($x) {
                return $x['rec']['uid'];
            }, $ms);
            $rowRefs = array_map(function ($x) {
                return $x['rec']['ref'];
            }, $ms);
            if ($ex) {
                if ($m['ambiguous']) {
                    $xq = $ans(import_xq('ambiguous-match', $it['nk'], '"' . $first['name'] . '" could be several existing ingredients that differ only in punctuation or accents. Rename it in the sheet to the exact stock name, or skip it.',
                        [['key' => 'skip', 'label' => 'Skip this item']], ['bad' => true, 'sheet' => $ms[0]['sheet'], 'cells' => $rowRefs, 'row_uids' => $uidList]));
                    $extra[$xq['qkey']] = $xq;
                    $itemOpen[] = $xq['id'];
                }
                if ((int)$ex['is_archived'] === 1) {
                    $xq = $ans(import_xq('archived-match', $it['nk'], '"' . $first['name'] . '" matches an ARCHIVED ingredient ("' . $ex['name'] . '"). It is left alone: restore or rename that ingredient, then upload again, or skip this row.',
                        [['key' => 'skip', 'label' => 'Skip this item']], ['bad' => true, 'sheet' => $ms[0]['sheet'], 'cells' => $rowRefs, 'row_uids' => $uidList]));
                    $extra[$xq['qkey']] = $xq;
                    if ($xq['answer'] === null) {
                        $itemOpen[] = $xq['id'];
                    }
                } elseif ($unit !== null && !rh_ssi_units_compatible((string)$rawUnit, (string)$ex['unit'])) {
                    $xq = $ans(import_xq('unit-clash', $it['nk'], '"' . $first['name'] . '" is in stock in "' . $ex['unit'] . '" but the sheet says "' . $rawUnit . '". Nothing is changed for it. Fix the sheet, or skip this row.',
                        [['key' => 'skip', 'label' => 'Skip this item']], ['bad' => true, 'sheet' => $ms[0]['sheet'], 'cells' => $rowRefs, 'row_uids' => $uidList]));
                    $extra[$xq['qkey']] = $xq;
                    if ($xq['answer'] === null) {
                        $itemOpen[] = $xq['id'];
                    }
                }
                $unit = (string)$ex['unit'];     // an existing ingredient keeps its unit
                $unitHow = 'existing ingredient';
                // what the sheet would change (shown old -> new; nothing is applied unless ticked)
                if ($first['category'] !== null && (string)$ex['category'] !== (string)$first['category']) {
                    $diff['category'] = [(string)$ex['category'], (string)$first['category']];
                }
                if ($cost !== null && abs((float)$ex['cost_per_unit'] - $cost) > 0.00005) {
                    $diff['cost'] = [(float)$ex['cost_per_unit'], $cost];
                }
                if ($max !== null && abs((float)$ex['par_level'] - (float)$max) > 0.00005) {
                    $diff['par'] = [(float)$ex['par_level'], (float)$max];
                }
                if ($pack && abs((float)($ex['pack_size'] ?? 0) - (float)$pack) > 0.0005) {
                    $diff['pack'] = [$ex['pack_size'] !== null ? (float)$ex['pack_size'] : null, (float)$pack];
                }
                if ($balance !== null && abs((float)$ex['current_quantity'] - $balance) > 0.00005) {
                    $diff['quantity'] = [(float)$ex['current_quantity'], $balance];
                }
            } elseif ($unit === null) {
                $noUnitByItem[] = count($outItems);
            }
            $ticked = array_values(array_intersect(import_update_fields(), array_map('strval', (array)($updates[rh_ssi_key((string)($ex['name'] ?? ''))] ?? []))));
            $ticked = $ex ? array_values(array_filter($ticked, function ($f) use ($diff) {
                return isset($diff[$f]);
            })) : [];
            // costs and stock on hand (accounting rule A11): stock with no cost is left out of the opening count
            $effCost = $cost;
            if ($ex && !in_array('cost', $ticked, true)) {
                $effCost = (float)$ex['cost_per_unit'] > 0 ? (float)$ex['cost_per_unit'] : null;
            }
            $inCount = false;
            if ($balance !== null && $balance > 0.00005) {
                if ($ex) {
                    $inCount = in_array('quantity', $ticked, true) && $effCost !== null && $effCost > 0;
                    if (in_array('quantity', $ticked, true) && !$inCount) {
                        $warn[] = 'Ticked to set the stock quantity, but there is no cost, so it is left out of the opening count.';
                    }
                } else {
                    $inCount = $effCost !== null && $effCost > 0;
                    if (!$inCount) {
                        $warn[] = 'There is stock on hand (' . import_fmt_qty($balance) . ') but no price, so the ingredient is created with a blank cost and its stock is NOT put in the opening count. Add the cost later.';
                    }
                }
            } elseif ($first['price'] === null && !$ex) {
                $warn[] = 'No price in the file: the ingredient is created with a blank cost.';
            }
            if ($ex && isset($diff['cost']) && in_array('cost', $ticked, true) && (int)($ex['recipes'] ?? 0) > 0 && (float)$ex['cost_per_unit'] > 0) {
                $ratio = $cost / (float)$ex['cost_per_unit'];
                if ($ratio >= 2 || $ratio <= 0.5) {
                    $warn[] = 'Ticked to change the cost from ' . import_fmt_money($ex['cost_per_unit']) . ' to ' . import_fmt_money($cost) . ' and ' . (int)$ex['recipes'] . ' recipe(s) use it. Check the unit is the same.';
                }
            }
            $outItems[] = [
                'key' => $it['nk'], 'name' => $ex ? (string)$ex['name'] : $first['name'], 'typed_name' => $first['name'], 'name_source' => $first['name_source'],
                'category' => $first['category'], 'category_how' => $first['category_how'], 'category_source' => $first['category_source'],
                'unit' => $unit, 'unit_raw' => $rawUnit, 'unit_how' => $unitHow, 'unit_source' => $first['unit_source'],
                'price' => $first['price'], 'price_source' => $first['price_source'], 'cost' => $cost, 'cost_how' => $costHow, 'pack' => $pack,
                'balance' => $balance, 'max' => $max, 'min' => $min, 'remarks' => $remarks, 'batches' => $batches,
                'rows' => $rowRefs, 'uids' => $uidList, 'sheet' => $ms[0]['sheet'], 'clean_sheet' => $ms[0]['cleanSheet'],
                'match' => $ex ? ['id' => (int)$ex['id'], 'name' => (string)$ex['name'], 'how' => $first['match_name'] !== null ? (!empty($first['match_auto']) ? 'learned' : 'chosen') : $matchHow, 'archived' => (int)$ex['is_archived'] === 1] : null,
                'action' => $action, 'diff' => $diff, 'ticked' => $ticked, 'in_count' => $inCount, 'warnings' => $warn, 'open' => $itemOpen,
                'row_status' => max(array_map(function ($x) {
                    return import_status_rank($x['rec']['status']);
                }, $ms)),
                'unanswered' => $it['unanswered'],
            ];
        }
        // one "which unit?" question per sheet for NEW items with no unit anywhere in the file
        $bySheetNoUnit = [];
        foreach ($noUnitByItem as $oi) {
            $bySheetNoUnit[$outItems[$oi]['sheet']][] = $oi;
        }
        foreach ($bySheetNoUnit as $sheetName => $ois) {
            $xq = $ans(import_xq('no-unit', $sheetName,
                'Sheet "' . $sheetName . '" has no unit for ' . count($ois) . ' new item' . (count($ois) === 1 ? '' : 's') . '. Which unit are they counted in?',
                array_map(function ($u) {
                    return ['key' => 'unit:' . $u, 'label' => $u];
                }, $unitOptions),
                ['sheet' => $sheetName, 'row_uids' => array_merge(...array_map(function ($oi) use ($outItems) {
                    return $outItems[$oi]['uids'];
                }, $ois))]));
            $extra[$xq['qkey']] = $xq;
            foreach ($ois as $oi) {
                if ($xq['answer'] !== null && strpos($xq['answer'], 'unit:') === 0) {
                    $outItems[$oi]['unit'] = substr($xq['answer'], 5);
                    $outItems[$oi]['unit_how'] = 'chosen by you for the whole sheet';
                } else {
                    $outItems[$oi]['open'][] = $xq['id'];
                }
            }
        }

        // the same cleaning and length limits as the manual Add form; over-long = blocked with a plain reason (skip is the way out)
        foreach ($outItems as $oi => $it) {
            if (empty($it['match'])) {
                $outItems[$oi]['name'] = rh_clean_text($it['name']);
                if ($it['unit'] !== null) {
                    $outItems[$oi]['unit'] = rh_clean_text($it['unit']);
                }
            }
            $outItems[$oi]['category'] = rh_clean_text((string)$it['category']);
            $why = import_limit_problems($outItems[$oi]);
            if ($why) {
                $xq = $ans(import_xq('value-too-long', (string)$it['key'],
                    '"' . $it['typed_name'] . '": ' . implode(' and ', $why) . ' (the same limits as adding an ingredient by hand). Shorten it in the sheet and upload again, or skip this item.',
                    [['key' => 'skip', 'label' => 'Skip this item']], ['bad' => true, 'sheet' => $it['sheet'], 'cells' => $it['rows'], 'row_uids' => $it['uids']]));
                $extra[$xq['qkey']] = $xq;
                $outItems[$oi]['open'][] = $xq['id'];
            }
        }

        // two different typed names that both match the SAME existing ingredient cannot both update it: asked here, not at commit
        $byMatchId = [];
        foreach ($outItems as $oi => $it) {
            if ($it['match']) {
                $byMatchId[(int)$it['match']['id']][] = $oi;
            }
        }
        foreach ($byMatchId as $mid => $ois) {
            if (count($ois) < 2) {
                continue;
            }
            $typedNames = array_map(function ($oi) use ($outItems) {
                return '"' . $outItems[$oi]['typed_name'] . '"';
            }, $ois);
            $xqs = [];
            foreach ($ois as $oi) {
                $xqs[$oi] = $ans(import_xq('same-existing-match', $outItems[$oi]['key'] . '|' . $mid,
                    implode(' and ', $typedNames) . ' in your file all match the one existing ingredient "' . $outItems[$oi]['name'] . '", and one ingredient cannot take two rows. Skip all but one of them, or rename them in the sheet and upload again.',
                    [['key' => 'skip', 'label' => 'Skip this item']],
                    ['bad' => true, 'sheet' => $outItems[$oi]['sheet'], 'cells' => $outItems[$oi]['rows'], 'row_uids' => $outItems[$oi]['uids']]));
            }
            $live = 0;
            foreach ($xqs as $xq) {
                if ($xq['answer'] !== 'skip') {
                    $live++;
                }
            }
            foreach ($xqs as $oi => $xq) {
                if ($xq['answer'] === 'skip' || $live > 1) {
                    $extra[$xq['qkey']] = $xq;
                    $outItems[$oi]['open'][] = $xq['id'];
                }
            }
        }

        // case-price answers: the conflict choices show what the case price comes to per unit (labels only; the option keys stay)
        if ($packLabels) {
            foreach ($analysis['questions'] as &$pq) {
                if (empty($packLabels[$pq['id']])) {
                    continue;
                }
                $pq['pack_labels'] = $packLabels[$pq['id']];
                foreach ($pq['options'] as &$po) {
                    if (isset($packLabels[$pq['id']][$po['key']])) {
                        $po['label'] = 'Use ' . $packLabels[$pq['id']][$po['key']];
                    }
                }
                unset($po);
                $pq['text'] .= ' (' . implode('; ', array_values($packLabels[$pq['id']])) . '.)';
            }
            unset($pq);
        }

        // ---- final statuses ----------------------------------------------------------------------------
        $extraById = [];
        foreach ($extra as $xq) {
            $extraById[$xq['id']] = $xq;
        }
        foreach ($outItems as &$oi) {
            $st = ['ready', 'needs-answer', 'blocked'][min(2, max(0, (int)$oi['row_status']))];
            foreach ($oi['open'] as $xid) {
                $x = $extraById[$xid] ?? null;
                if ($x && $x['answer'] === null) {
                    $st = !empty($x['bad']) ? 'blocked' : ($st === 'blocked' ? 'blocked' : 'needs-answer');
                }
            }
            if (!empty($oi['unanswered']) && $st === 'ready') {
                $st = 'needs-answer';
            }
            // a "skip" given on a blocking synthetic question skips the item
            foreach ($oi['open'] as $xid) {
                $x = $extraById[$xid] ?? null;
                if ($x && $x['answer'] === 'skip') {
                    $st = 'skipped';
                }
            }
            $oi['status'] = $st;
            foreach ($oi['uids'] as $uid) {
                if (($rowsOut[$uid]['status'] ?? '') === 'skipped') {
                    continue;
                }
                if ($st === 'skipped') {
                    $rowsOut[$uid]['status'] = 'skipped';
                    $rowsOut[$uid]['reason'] = 'Skipped by you.';
                } else {
                    $rowsOut[$uid]['status'] = $st;
                    $rowsOut[$uid]['open_questions'] = $oi['open'];
                }
            }
        }
        unset($oi);
        // answered "skip" on a synthetic question whose item was not built (xsheet skip / merge skip) are already marked above

        $counts = ['ready' => 0, 'needs-answer' => 0, 'blocked' => 0, 'skipped' => 0];
        $bySheet = [];
        foreach ($analysis['sheets'] as $sh) {
            $bySheet[$sh['name']] = ['action' => $sh['triage']['action'] ?? 'skip', 'ready' => 0, 'needs-answer' => 0, 'blocked' => 0, 'skipped' => 0,
                'non_item_rows' => count($sh['skipped_rows'])];
        }
        foreach ($rowsOut as $r) {
            $s = $r['status'];
            if (!isset($counts[$s])) {
                $s = 'needs-answer';
            }
            $counts[$s]++;
            $bySheet[$r['sheet']][$s]++;
        }
        $committable = 0;
        foreach ($outItems as $oi) {
            if ($oi['status'] === 'ready') {
                $committable++;
            }
        }
        $canConfirm = $counts['needs-answer'] === 0 && $counts['blocked'] === 0 && !$openSheetQs && $committable > 0;
        $why = '';
        if (!$canConfirm) {
            $why = $counts['blocked'] > 0 ? 'Some rows are blocked: fix the sheet and upload again, or skip them.'
                : ($counts['needs-answer'] > 0 || $openSheetQs ? 'Some questions still need an answer.' : 'There is nothing ready to import.');
        }
        return [
            'items' => $outItems, 'rows' => $rowsOut, 'extra_questions' => array_values($extra), 'counts' => $counts, 'by_sheet' => $bySheet,
            'open_sheet_questions' => $openSheetQs, 'can_confirm' => $canConfirm, 'why_not' => $why, 'committable_items' => $committable,
            'questions' => $analysis['questions'],
        ];
    }

    /** CSV cell that a spreadsheet will show as text, never run as a formula. */
    function import_csv_safe($v): string
    {
        $s = (string)$v;
        if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) {
            return "'" . $s;
        }
        return $s;
    }

    /** The "what will be imported" CSV: one line per item that is ready. */
    function import_resolve_csv(array $res): string
    {
        $fh = fopen('php://memory', 'w+');
        fputcsv($fh, ['Status', 'Item', 'Category', 'Unit', 'Opening balance', 'Cost per unit', 'Par level', 'What happens', 'Fields you ticked', 'Expiry batches', 'Where it came from', 'Notes']);
        foreach ($res['items'] as $it) {
            $b = array_map(function ($x) {
                return ($x['label'] !== '' ? $x['label'] . ' ' : '') . import_fmt_qty($x['qty']) . ($x['expiry'] ? ' exp ' . $x['expiry'] : '');
            }, $it['batches']);
            $what = $it['action'] === 'create' ? 'Create new ingredient' : 'Matches existing "' . ($it['match']['name'] ?? '') . '" (' . ($it['match']['how'] ?? '') . ')';
            $what .= $it['in_count'] ? '; line in the opening count' : '; not in the opening count';
            fputcsv($fh, array_map('import_csv_safe', [
                $it['status'], $it['name'], $it['category'], $it['unit'] ?? '', $it['balance'] !== null ? import_fmt_qty($it['balance']) : '',
                $it['cost'] !== null ? $it['cost'] : '', $it['max'] !== null ? import_fmt_qty($it['max']) : '', $what, implode(', ', $it['ticked']),
                implode('; ', $b), implode(', ', $it['rows']), implode(' ', $it['warnings']),
            ]));
        }
        rewind($fh);
        return (string)stream_get_contents($fh);
    }
}
