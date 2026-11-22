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
 * $ctx     = ['existing' => [stock_ingredients rows + 'recipes'], 'unit_options' => [unit names], 'corrections' => [norm => name],
 *             optional: 'price_evidence' => ['history' => [ingredient id => [unit costs, oldest first]], 'hotel_move' => ['v','n'], 'category_move' => [catKey => ['v','n']]],
 *                       'cost_limits' => ['cost' => max storable unit cost, 'line_value' => max opening-count line value] (see price-check.php)]
 *             Without evidence the price check asks nothing (except structural signals); without limits the stock-count cap is the fallback.
 */

require_once __DIR__ . '/analyze.php';
require_once dirname(__DIR__, 2) . '/admin/includes/stock-sheet-import-lib.php';   // rh_ssi_key / rh_ssi_norm_unit / rh_ssi_units_compatible
require_once dirname(__DIR__) . '/form-validation.php';                           // rh_clean_text (same cleaning as the manual Add form)
require_once __DIR__ . '/price-check.php';                                         // plausibility of a price (evidence from this hotel's own data) + storage limits
require_once __DIR__ . '/infer.php';                                               // evidence-based unit / category suggestions + unit spelling
require_once __DIR__ . '/case-signals.php';                                        // "is this a case price?" - the one shared judge (also used by Stock > Ingredients)
require_once __DIR__ . '/measure.php';                                             // counts in shots / tots / glasses -> the stock unit (Step U2 d)

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
        // "Skip this row/item" is a valid answer to ANY question that belongs to rows (blocked ones included), whatever options it lists.
        // It is stored as skipped rows (import_job_answer), not as an answer to the question. Questions about how a sheet is read
        // (no rows) have their own skip / ignore options and are answered in step 2.
        if ($a['key'] === '_skip') {
            return empty($q['row_uids']) ? 'This question is about how a sheet is read, not about rows. Use one of its own choices.' : '';
        }
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
        if (($q['type'] ?? '') === 'measure-n' && $a['key'] === 'n' && import_measure_n_valid($a['pack_size'] ?? null) === null) {
            return 'Enter how many are in one (a number from 1 to ' . import_measure_fmt(import_measure_max_n()) . ').';
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
            // decisive evidence from this hotel's own data (import_case_evidence_pass): answered "per case" for the person, shown with its
            // reason, changeable (a stored answer above always wins); the cost still goes through the price check and confirm
            if (($q['type'] ?? '') === 'case-price' && !empty($q['pre']['pack'])) {
                $q['answer'] = 'per-case';
                $q['answer_detail'] = ['key' => 'per-case', 'pack_size' => (int)$q['pre']['pack'], 'auto' => true];
                $q['auto'] = true;
                $q['auto_reason'] = (string)$q['pre']['reason'];
                continue;
            }
            // the same name under two different headings (A85): two items, each named with its heading - picked for the person (visible, changeable)
            // only when no stock item already has that name (then which heading it belongs to is the person's call) and every new name fits
            if (($q['type'] ?? '') === 'duplicate-no-batch' && !empty($q['section_split'])) {
                $typed = $rowName[($q['row_uids'][0] ?? '')] ?? '';
                $fits = true;
                foreach ($q['section_split'] as $cat) {
                    if (mb_strlen(import_section_item_name($typed, (string)$cat)) > 100) {
                        $fits = false;
                    }
                }
                if ($typed !== '' && $fits && import_match_existing($typed, $ctx)['row'] === null) {
                    $q['answer'] = 'section-items';
                    $q['answer_detail'] = ['key' => 'section-items', 'auto' => true];
                    $q['auto'] = true;
                    $q['auto_reason'] = '"' . $typed . '" is under ' . count($q['section_split']) . ' different headings and is not in stock yet, so each is its own item, named with its heading.';
                    continue;
                }
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
     * "Per case or per unit?" from this hotel's own data (case-signals.php), BEFORE the answers are applied, so the rows' statuses count it.
     * For every priced row: the matched existing item (typed name, or the stored "did you mean" answer), its pack size, current cost, recipe
     * selling price, real deliveries, the row's own value cell and the same-category same-unit groups of the file. Then:
     *   - nothing to say -> nothing (the engine's own case-price question, if any, stays as it is);
     *   - the row already has the engine's case-price question -> its text gets the reason; decisive evidence pre-answers it;
     *   - otherwise a case-price question is added to the row (same type, options and stable key as the engine's), pre-answered when decisive;
     *     for an EXISTING item an open (not decisive) question is added only once Cost or Pack is ticked (its file cost is not written otherwise).
     * Decisive = strong evidence, nothing against it, and the item's OWN whole pack size (import_cs_judge). Never pre-answered when the row's
     * price is in a conflict question (the chosen price may come from another cell, A29). Registration appends to the row directly (no scan).
     * ctx['case_evidence'] = import_cs_load_evidence() (optional: without it only the pack, cost, value and group signals are used).
     */
    function import_case_evidence_pass(array &$analysis, array $answers, array $ctx, array $synonyms): void
    {
        $ev = (array)($ctx['case_evidence'] ?? []);
        $csp = (array)($ctx['case_signal_params'] ?? []);
        $qIndex = [];
        foreach ($analysis['questions'] as $i => $q) {
            $qIndex[$q['id']] = $i;
        }
        $rowsInfo = [];          // [shIdx, rowKey, ex|null, unit, group]
        $autoInfo = [];          // rows whose price the pack rule divides with no selling price to check it: [shIdx, rowKey, ex|null, unit, value pack, pack]
        $checkedPeers = [];      // sheet + pack word + pack => prices of rows whose division a selling price on the same row checked (A83)
        $groupItems = [];
        foreach ($analysis['sheets'] as $shIdx => $sh) {
            if (($sh['triage']['action'] ?? '') !== 'import' || empty($sh['rows'])) {
                continue;
            }
            $hasPq = false;
            foreach ((array)($sh['columns'] ?? []) as $c) {
                if (($c['state'] ?? '') === 'mapped' && in_array(($c['field'] ?? ''), ['purchase_qty', 'sell_price'], true)) {
                    $hasPq = true;          // a value column next to a Purchase Qty may be a buying list (A74), next to a selling price it may be valued
                }                           // at the selling price: not read as a stock value here
            }
            $cleanSheet = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', (string)$sh['name']));
            $cleanSheet = $cleanSheet !== '' ? $cleanSheet : (string)$sh['name'];
            foreach ($sh['rows'] as $rk => $row) {
                $f = $row['fields'];
                if (!isset($f['unit_price']) || !is_numeric($f['unit_price']['value']) || (float)$f['unit_price']['value'] <= 0) {
                    continue;
                }
                $autoPack = 0;
                if (!empty($row['pack_rule']) && (!empty($row['pack_rule']['decided']) || (string)($row['pack_rule']['ask'] ?? '') !== '')) {
                    // the row's own bought-as / pack / served-by cells decide (or ask about) its price basis. One case is still judged below (A83):
                    // a price DIVIDED on those words alone, with no selling price of the same unit beside it to check the division against
                    $prr = $row['pack_rule'];
                    if (!empty($prr['decided']) && (int)($prr['pack'] ?? 0) >= 2 && !empty($prr['sell']) && empty($prr['served_portion'])) {
                        // a division its own selling price checked: kept as a peer for the rows of the same sheet + pack that have no selling price
                        $checkedPeers[$shIdx . "\x1f" . mb_strtolower((string)($prr['pack_name'] ?? '')) . "\x1f" . (int)$prr['pack']][] = (float)$f['unit_price']['value'];
                    }
                    if (empty($prr['decided']) || empty($prr['pack']) || (int)$prr['pack'] < 2 || (!empty($prr['sell']) && empty($prr['served_portion']))) {
                        continue;
                    }
                    $autoPack = (int)$prr['pack'];
                }
                $name = (string)($f['item_name']['value'] ?? '');
                $hasConflict = false;
                foreach ((array)$row['question_ids'] as $qid) {
                    $q = $analysis['questions'][$qIndex[$qid] ?? -1] ?? null;
                    if (!$q) {
                        continue;
                    }
                    if ($q['type'] === 'conflict') {
                        $hasConflict = true;
                    } elseif ($q['type'] === 'possible-typo' && ($q['against'] ?? '') === 'existing') {
                        $a = $answers[import_q_key($q)]['key'] ?? null;
                        if (is_string($a) && strpos($a, 'use:') === 0) {
                            $name = substr($a, 4);
                        }
                    }
                }
                $m = import_match_existing($name, $ctx);
                $ex = $m['row'];
                $rowUnit = isset($f['unit']) ? import_unit_canon((string)$f['unit']['value'], $synonyms) : '';
                $unit = $ex ? (string)$ex['unit'] : $rowUnit;
                $cat = $row['category'] !== null && $row['category'] !== '' ? $row['category'] : $cleanSheet;
                $vp = null;
                $qty = isset($row['computed']['qty_total']) && is_numeric($row['computed']['qty_total']['value']) ? (float)$row['computed']['qty_total']['value'] : null;
                if (!$hasPq && $qty !== null && $qty > 0 && empty($row['qty_measure'])) {
                    foreach ((array)$row['ignored'] as $ig) {
                        if (($ig['field'] ?? '') !== 'total_cost' || !empty($ig['unknown']) || $ig['value'] === null) {
                            continue;
                        }
                        $pv = import_parse_number($ig['value']);
                        if ($pv['status'] !== 'ok' || (float)$pv['value'] <= 0) {
                            continue;
                        }
                        $val = (float)$pv['value'];
                        $n = (int)round($qty * (float)$f['unit_price']['value'] / $val);
                        if ($n >= 2 && $n <= 100000 && abs($val - $qty * (float)$f['unit_price']['value'] / $n) <= max(0.5, 0.005 * $val)) {
                            $vp = ['n' => $n, 'value' => $val, 'qty' => $qty, 'source' => (string)$ig['source']];
                        }
                        break;
                    }
                }
                $uid = (string)$row['uid'];
                if ($autoPack) {
                    $autoInfo[$uid] = [$shIdx, $rk, $ex, $unit, $vp, $autoPack];      // kept out of the same-category groups: its price is a pack price
                    continue;
                }
                $rowsInfo[$uid] = [$shIdx, $rk, $ex, $unit, $vp, $hasConflict];
                $groupItems[$uid] = ['group' => import_cs_group_key($cat, $rowUnit), 'cost' => (float)$f['unit_price']['value']];
            }
        }
        if (!$rowsInfo && !$autoInfo) {
            return;
        }
        $refs = import_cs_cluster_refs($groupItems, $csp);
        unset($groupItems);
        $short = $analysis['file']['short'] ?? 'f';
        // A83: a pack price divided with NO selling price beside it. Divided silently only when this hotel's own data agrees with the division:
        // the cost in stock or its deliveries are about price / N, the sheet's value cell is qty x price / N, the judge's own decisive "case of N",
        // or the menu sells one unit for less than the price and more than price / N, or checked rows of the same sheet + pack price alike. Otherwise it is asked (pack pre-filled; like rows share one question).
        // An existing item with neither Cost nor Pack ticked is not asked: its file cost is not written (A38), and the opening count never uses
        // a stored crate price (B2).
        $agreeTol = log(1 + (float)import_cs_params($csp)['agree_tol']);
        $near = function (float $a, ?float $b) use ($agreeTol) {
            return $b !== null && $a > 0 && $b > 0 && abs(log($a / $b)) <= $agreeTol;
        };
        $askGroups = [];
        foreach ($autoInfo as $uid => [$shIdx, $rk, $ex, $unit, $vp, $n]) {
            $row = &$analysis['sheets'][$shIdx]['rows'][$rk];
            $price = (float)$row['fields']['unit_price']['value'];
            $each = $price / $n;
            $id = $ex ? (int)$ex['id'] : 0;
            $cur = $ex && (float)$ex['cost_per_unit'] > 0 ? (float)$ex['cost_per_unit'] : null;
            $rec = [];
            foreach ($id ? (array)($ev['receipts'][$id] ?? []) : [] as $rv) {
                if (is_numeric($rv) && (float)$rv > 0) {
                    $rec[] = (float)$rv;
                }
            }
            $recMed = $rec ? import_cs_median($rec) : null;
            $sellEv = $id ? ($ev['sell'][$id] ?? null) : null;
            $sellPer = is_array($sellEv) && (float)($sellEv['per_unit'] ?? 0) > 0 ? (float)$sellEv['per_unit'] : null;
            $j = import_cs_judge([
                'cost' => $price, 'unit' => $unit, 'current' => $cur, 'own_pack' => $ex['pack_size'] ?? null, 'sell' => $sellEv, 'receipts' => $rec,
                'other_packs' => $id ? array_values($ev['packs'][$id] ?? []) : [], 'value_pack' => $vp, 'cluster' => null,
            ], $csp);
            // ... or at least two rows of the same sheet bought by the same pack had their division checked by their own selling price, and this
            // price sits among theirs (Heineken 110,000 next to Castle / Amstel / Windhoek cases at 115,000-156,000, each checked: a case price too)
            $peers = $checkedPeers[$shIdx . "\x1f" . mb_strtolower((string)($row['pack_rule']['pack_name'] ?? '')) . "\x1f" . $n] ?? [];
            $peerAgree = count($peers) >= 2 && $price >= min($peers) / (1 + (float)import_cs_params($csp)['agree_tol'])
                && $price <= max($peers) * (1 + (float)import_cs_params($csp)['agree_tol']);
            $agree = $peerAgree || $near($each, $cur) || $near($each, $recMed) || (is_array($vp) && (int)$vp['n'] === $n)
                || ($j !== null && $j['verdict'] === 'case' && (int)$j['pack'] === $n)
                || ($sellPer !== null && $price > $sellPer && $each <= $sellPer);
            if ($agree && !($j !== null && $j['against'])) {
                unset($row);
                continue;
            }
            $ticks = $ex ? array_map('strval', (array)($answers['_updates'][rh_ssi_key((string)$ex['name'])] ?? [])) : [];
            if ($ex && !in_array('cost', $ticks, true) && !in_array('pack', $ticks, true)) {
                unset($row);
                continue;
            }
            $src = (string)$row['fields']['unit_price']['source'];
            $u = trim((string)($row['fields']['unit']['value'] ?? '')) !== '' ? mb_strtolower(trim((string)$row['fields']['unit']['value'])) : ($unit !== '' ? $unit : 'unit');
            $bw = mb_strtolower((string)($row['pack_rule']['pack_name'] ?? '')) ?: 'pack';
            // Rows with nothing for or against are asked TOGETHER: one question per sheet + pack word + pack size + unit (a bar sheet of 40
            // crate rows and no selling price is one question, not 40). A row this hotel's own data argues against keeps its own question.
            $against = ($j !== null && $j['against']) ? (string)$j['reason'] : '';
            $gk = $against !== '' ? 'own:' . $uid : implode("\x1f", [$shIdx, $bw, $n, $u]);
            $askGroups[$gk][] = ['uid' => $uid, 'rk' => $rk, 'price' => $price, 'each' => $each, 'src' => $src, 'u' => $u, 'bw' => $bw, 'n' => $n,
                'shIdx' => $shIdx, 'against' => $against];
            unset($row);
        }
        foreach ($askGroups as $members) {
            $m0 = $members[0];
            $shIdx = $m0['shIdx'];
            [$u, $bw, $n] = [$m0['u'], $m0['bw'], $m0['n']];
            $row0 = $analysis['sheets'][$shIdx]['rows'][$m0['rk']];
            $name0 = (string)$row0['fields']['item_name']['value'];
            if (count($members) === 1) {
                $text = 'Row ' . $row0['row'] . ' "' . $name0 . '": the price ' . import_fmt_money($m0['price']) . ' (' . $m0['src'] . ') would be divided by the ' . $bw . ' of ' . $n
                    . ' = ' . import_fmt_money(round($m0['each'], 4)) . ' per ' . $u . ', because the row says it is bought by the ' . $bw . '. The sheet has no selling price to check that against, and '
                    . ($m0['against'] !== '' ? 'this hotel\'s own data points the other way: ' . $m0['against'] : 'nothing this hotel already holds (cost in stock, deliveries, the sheet\'s value) confirms it.')
                    . ' Is ' . import_fmt_money($m0['price']) . ' the price of one ' . $u . ', or of the whole ' . $bw . '?';
                $options = import_cc_case_options($row0, $m0['price'], $m0['each']);
            } else {
                $list = [];
                foreach (array_slice($members, 0, 5) as $m) {
                    $r = $analysis['sheets'][$shIdx]['rows'][$m['rk']];
                    $list[] = '"' . $r['fields']['item_name']['value'] . '" row ' . $r['row'] . ' ' . import_fmt_money($m['price']) . ' -> ' . import_fmt_money(round($m['each'], 4));
                }
                $more = count($members) > 5 ? ' and ' . (count($members) - 5) . ' more' : '';
                $text = count($members) . ' rows of "' . $analysis['sheets'][$shIdx]['name'] . '" say they are bought by the ' . $bw . ' of ' . $n . ', so each price would be divided by ' . $n
                    . ' to get the price of one ' . $u . ' (' . implode('; ', $list) . $more . '). The sheet has no selling price to check that against, and nothing this hotel already holds'
                    . ' (cost in stock, deliveries, the sheet\'s value) confirms it. Is each price the price of one ' . $u . ', or of the whole ' . $bw . '? Your answer is used for all ' . count($members) . ' rows.';
                $options = [
                    ['key' => 'per-unit', 'label' => 'Each price is for one ' . $u . ' (import as written: "' . $name0 . '" ' . import_fmt_money($m0['price']) . ' per ' . $u . ')'],
                    ['key' => 'per-case', 'label' => 'Each price is for a whole ' . $bw . ': I will type how many ' . $u . ' one ' . $bw . ' holds (for example ' . $n . ' -> "'
                        . $name0 . '" ' . import_fmt_money(round($m0['each'], 4)) . ' per ' . $u . ')'],
                ];
            }
            $q = import_q('case-price', $text, [
                'sheet' => $analysis['sheets'][$shIdx]['name'], 'row' => $row0['row'], 'cells' => array_values(array_column($members, 'src')),
                'options' => $options, 'case_ratio' => null,
            ]);
            $q['id'] = 'q' . $short . '-' . (count($analysis['questions']) + 1);
            $q['answer'] = null;
            $q['row_uids'] = array_values(array_column($members, 'uid'));
            $q['group_rows'] = [];                      // every row the answer covers (value-check.php reads it)
            foreach ($members as $m) {
                $q['group_rows'][] = (int)$analysis['sheets'][$shIdx]['rows'][$m['rk']]['row'];
            }
            $q['pack_hint'] = $n;
            $q['pack_rule'] = true;
            $q['no_sell_check'] = true;
            $analysis['questions'][] = $q;
            $qIndex[$q['id']] = count($analysis['questions']) - 1;
            foreach ($members as $m) {
                $analysis['sheets'][$shIdx]['rows'][$m['rk']]['question_ids'][] = $q['id'];
            }
        }
        foreach ($rowsInfo as $uid => [$shIdx, $rk, $ex, $unit, $vp, $hasConflict]) {
            $row = &$analysis['sheets'][$shIdx]['rows'][$rk];
            $price = (float)$row['fields']['unit_price']['value'];
            $id = $ex ? (int)$ex['id'] : 0;
            $j = import_cs_judge([
                'cost' => $price, 'unit' => $unit, 'current' => $ex ? (float)$ex['cost_per_unit'] : null, 'own_pack' => $ex['pack_size'] ?? null,
                'sell' => $id ? ($ev['sell'][$id] ?? null) : null, 'receipts' => $id ? ($ev['receipts'][$id] ?? []) : [],
                'other_packs' => $id ? array_values($ev['packs'][$id] ?? []) : [], 'value_pack' => $vp, 'cluster' => $refs[$uid] ?? null,
            ], $csp);
            if ($j === null) {
                unset($row);
                continue;
            }
            $pre = ($j['verdict'] === 'case' && !$hasConflict) ? ['pack' => (int)$j['pack'], 'reason' => $j['reason']] : null;
            $slim = ['verdict' => $j['verdict'], 'strong' => $j['strong'], 'weak' => $j['weak'], 'against' => $j['against'], 'reason' => $j['reason'], 'pack_hint' => $j['pack_hint']];
            $found = null;
            foreach ((array)$row['question_ids'] as $qid) {
                if (isset($qIndex[$qid]) && $analysis['questions'][$qIndex[$qid]]['type'] === 'case-price') {
                    $found = $qIndex[$qid];
                    break;
                }
            }
            // An existing item's file cost is only written when Cost (or Pack) is ticked (A38): an open question for an unticked item would
            // block Confirm for nothing, so it is added then (recomputed); a decisive answer is shown always (it changes no field by itself).
            $ticks = $ex ? array_map('strval', (array)($answers['_updates'][rh_ssi_key((string)$ex['name'])] ?? [])) : [];
            if ($found === null && $ex && !$pre && !in_array('cost', $ticks, true) && !in_array('pack', $ticks, true)) {
                unset($row);
                continue;
            }
            if ($found !== null) {
                $analysis['questions'][$found]['text'] .= ' What this hotel\'s own data says: ' . $j['reason'];
            } else {
                $src = (string)$row['fields']['unit_price']['source'];
                $hint = $j['pack_hint'] ?? $j['pack'] ?? 2;
                $q = import_q('case-price', import_case_q_text($row, $price, $unit, $src, $j['reason']), [
                    'sheet' => $analysis['sheets'][$shIdx]['name'], 'row' => $row['row'], 'cells' => [$src],
                    'options' => import_cc_case_options($row, $price, $price / max(2, (int)$hint)),
                    'case_ratio' => null,
                ]);
                $q['id'] = 'q' . $short . '-' . (count($analysis['questions']) + 1);
                $q['answer'] = null;
                $q['row_uids'] = [$uid];
                $analysis['questions'][] = $q;
                $found = count($analysis['questions']) - 1;
                $qIndex[$q['id']] = $found;
                $row['question_ids'][] = $q['id'];
            }
            $analysis['questions'][$found]['case_evidence'] = $slim;
            $analysis['questions'][$found]['pack_hint'] = $j['pack_hint'] ?? ($analysis['questions'][$found]['pack_hint'] ?? null);   // a size from the name stays when the evidence has none
            if ($pre) {
                $analysis['questions'][$found]['pre'] = $pre;
            }
            unset($row);
        }
    }

    /** Text of a case-price question added from the hotel's evidence: the row's unit as written, the reason, what each answer does. */
    function import_case_q_text(array $row, float $price, string $unit, string $src, string $reason): string
    {
        $u = trim((string)($row['fields']['unit']['value'] ?? '')) !== '' ? trim((string)$row['fields']['unit']['value']) : $unit;
        $per = $u !== '' ? 'per ' . $u : 'per unit';
        $qty = empty($row['qty_measure']) ? ($row['computed']['qty_total']['value'] ?? null) : null;       // a count in tots is not in the price's unit
        $t = 'Row ' . $row['row'] . ' "' . $row['fields']['item_name']['value'] . '": the unit price is ' . import_fmt_money($price) . ' ' . $per . ' (' . $src . '). '
            . 'This hotel\'s own data says it may be a case price: ' . $reason;
        if ($qty !== null && is_numeric($qty) && (float)$qty > 0) {
            $t .= ' The sheet counts ' . import_cc_qty((float)$qty) . ($u !== '' ? ' ' . $u : '') . ', so as written this line is worth ' . import_fmt_money(round((float)$qty * $price, 2)) . '.';
        }
        return $t . ' Is that the price of one unit, or of a whole case/pack? The quantity stays in the sheet\'s unit' . ($u !== '' ? ' (' . $u . ')' : '')
            . ', so choose "whole pack" only if the quantity was counted in single units, not in packs.';
    }

    /**
     * The whole picture after the person's answers. See the file header for the shapes. Works on its own copy of $analysis.
     */
    function import_resolve(array $analysis, array $answers, array $ctx = []): array
    {
        return import_resolve_inplace($analysis, $answers, $ctx);
    }

    /**
     * Same as import_resolve(), but the answers are written into the caller's $analysis itself instead of into a private copy. On a 20,000-row
     * file that copy (every row separated by the status recompute) was a third of the memory of a review request; callers that do not need the
     * untouched analysis afterwards use this one.
     */
    function import_resolve_inplace(array &$analysis, array $answers, array $ctx = [], bool $freeRows = false): array
    {
        $ctx['_ex_index'] = import_index_existing((array)($ctx['existing'] ?? []));
        $unitOptions = array_values(array_unique(array_map('strval', (array)($ctx['unit_options'] ?? []))));
        $updates = (array)($answers['_updates'] ?? []);
        $skipRows = (array)($answers['_skip_rows'] ?? []);         // uid => ['why','code','by','at']: rows a person chose to leave out (blocked or unanswered)
        $unitFor = (array)($answers['_unit_for'] ?? []);           // item key => ['unit','src','by','at']: a unit chosen for ONE new item
        $healUndo = (array)($answers['_heal_undo'] ?? []);         // heal id => meta: a self-healed value the person took back
        $synonyms = (array)($ctx['unit_synonyms'] ?? import_default_aliases()['unit_synonyms']);
        // the count-unit question (owner 2026-11-22): a plain count of a drink sold by the shot / glass may be in bottles or in shots
        $countIn = (array)($answers['_count_in'] ?? []);           // uid => ['key' => stock|measure, 'by', 'at']: one row's own answer (a row may differ)
        $sizeAnswers = (array)($answers['_sizes'] ?? []);          // step 3 sizes (drinks.php): they give N for a count answered "in shots"
        $ciDefaults = import_default_aliases();
        $ciList = (array)($ctx['drink_measures'] ?? []);
        $ciShot = import_word_set((array)($ctx['shot_words'] ?? $ciDefaults['shot_words']));
        $ciGlass = import_word_set((array)($ctx['glass_words'] ?? $ciDefaults['glass_words']));
        $ciPour = import_word_set((array)($ctx['pour_containers'] ?? $ciDefaults['pour_containers']));
        $countGroups = [];          // question key part => the rows of one served-by group
        $resolveHeals = [];
        $resolveHealN = 0;
        import_case_evidence_pass($analysis, $answers, $ctx, $synonyms);
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

        // evidence for the price check (all optional; none = no question from it): the hotel's own prices, see price-check.php
        $pcp = import_pc_params($ctx);
        $pcEv = (array)($ctx['price_evidence'] ?? []);
        $costLimits = import_cost_limits_from_ctx($ctx);
        $pcPackSizes = [];
        $pcPeers = [];          // category key => [['unit' => normalised unit, 'cost' => float], ...] of items in stock
        $pcCatById = [];
        foreach ((array)($ctx['existing'] ?? []) as $e) {
            $pcCatById[(int)$e['id']] = (string)$e['category'];
            if (isset($e['pack_size']) && is_numeric($e['pack_size']) && (float)$e['pack_size'] >= 2) {
                $pcPackSizes[(string)(float)$e['pack_size']] = (float)$e['pack_size'];
            }
            if ((int)($e['is_archived'] ?? 0) === 0 && (float)$e['cost_per_unit'] > 0) {
                $pcPeers[import_pc_cat_key($e['category'])][] = ['unit' => mb_strtolower(import_unit_canon((string)$e['unit'], $synonyms)), 'cost' => (float)$e['cost_per_unit'], 'id' => (int)$e['id']];
            }
        }
        // stock items whose STORED cost may already be a case price (the same judge as the Stock > Ingredients note): a warning on the item, never a question
        $costSuspects = import_cs_stock_flags((array)($ctx['existing'] ?? []), (array)($ctx['case_evidence'] ?? []), (array)($ctx['case_signal_params'] ?? []));

        $packLabels = [];       // question id => [option key => "60,000 per case of 12 = 5,000 each"]
        $rowsOut = [];          // uid => record
        $cands = [];            // surviving rows with their final values
        $openSheetQs = [];
        foreach (array_keys($analysis['sheets']) as $shIdx) {
            $sh = $analysis['sheets'][$shIdx];
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
            $catColNo = 0;          // the sheet's own category column (a heading row's cell is in the item-name column, so the two never mix)
            foreach ((array)($sh['columns'] ?? []) as $shCol) {
                if (($shCol['field'] ?? '') === 'category' && empty($shCol['derived']) && isset($shCol['letter'])) {
                    $catColNo = import_col_to_num((string)$shCol['letter']);
                    break;
                }
            }
            // freeRows: each row is released as soon as it has been read (the rows of a 20,000-row file are most of the memory of the analysis), so
            // the rows and the candidates built from them never both exist in full.
            $rowsHolder = [];
            if ($freeRows) {
                unset($sh['rows']);
                $rowsHolder = &$analysis['sheets'][$shIdx]['rows'];
            } else {
                $rowsHolder = $sh['rows'];
            }
            $prevKey = null;
            foreach ($rowsHolder as $rowKey => &$row) {
                if ($freeRows && $prevKey !== null) {
                    unset($rowsHolder[$prevKey]);
                }
                $prevKey = $rowKey;
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
                    'price_raw' => isset($f['unit_price']['raw']) ? $f['unit_price']['raw'] : null,
                    'unit_raw' => isset($f['unit']) ? $f['unit']['value'] : null, 'unit_source' => isset($f['unit']) ? $f['unit']['source'] : null,
                    'pack' => null, 'pack_source' => null, 'pack_cell' => null, 'pack_name' => null, 'pack_info' => null, 'pack_reason' => '', 'pack_auto' => false, 'pack_sell' => null, 'expiry' => isset($f['expiry']) ? $f['expiry']['value'] : null, 'expiry_source' => isset($f['expiry']) ? $f['expiry']['source'] : null,
                    'batch' => isset($f['batch']) ? $f['batch']['value'] : null, 'max' => isset($f['max_level']) ? $f['max_level']['value'] : null,
                    'min' => isset($f['min_level']) ? $f['min_level']['value'] : null, 'remarks' => isset($f['remarks']) ? $f['remarks']['value'] : null,
                    'qty' => isset($row['computed']['qty_total']) ? $row['computed']['qty_total']['value'] : null,
                    'qty_unknown' => (bool)array_intersect(array_keys($row['unknown']), ['qty_primary', 'qty_secondary', 'qty_single']),
                    'qty_meas' => import_measure_row_split($row),           // part of the count in shots / tots / glasses (Step U2 d): turned into the stock unit below
                    'price_meas' => $row['price_measure'] ?? null,          // the price column is headed as a price per tot (asked below, never multiplied)
                    'category' => $row['category'] !== null && $row['category'] !== '' ? $row['category'] : $cleanSheet,
                    'category_how' => $row['category'] !== null && $row['category'] !== '' ? 'section-row' : 'sheet-name',
                    'category_source' => $row['category'] !== null && $row['category'] !== '' ? $row['category_source'] : null,
                    'category_col' => $catColNo > 0 && $row['category'] !== null && $row['category'] !== ''
                        && (import_parse_ref(import_split_source((string)$row['category_source'])[1])[1] ?? 0) === $catColNo,
                    // the selling price is never imported into stock (a derived column); it is only carried, with its cell, to the "Drinks for the
                    // menu" list (drinks.php), where nothing reaches the menu without a person's tick. unknown = a formula with no saved value.
                    'sells' => array_values(array_map(function ($ig) {
                        return ['value' => $ig['value'] ?? null, 'source' => (string)($ig['source'] ?? ''), 'unknown' => !empty($ig['unknown'])];
                    }, array_filter((array)($row['ignored'] ?? []), function ($ig) {
                        return ($ig['field'] ?? '') === 'sell_price';
                    }))),
                    'served_by' => isset($f['served_by']) ? $f['served_by']['value'] : null, 'served_by_source' => isset($f['served_by']) ? $f['served_by']['source'] : null,
                ];
                $skip = '';
                $dupQ = null;
                $needPack = false;
                $origPriceSource = isset($f['unit_price']) ? $f['unit_price']['source'] : null;
                $pr = $row['pack_rule'] ?? null;
                if ($pr) {
                    // the row's own bought-as / pack size / served-by cells (validator, pack-rule.php): a decided per-pack price is divided by the
                    // pack size from the SAME row (the pack belongs to this row's price cell); a "per case or per unit?" answer below overrides it
                    $fin['pack_name'] = $pr['pack_name'] ?? null;
                    $fin['pack_info'] = $pr['info_pack'] ?? null;
                    $fin['pack_reason'] = (string)($pr['reason'] ?? '');
                    $fin['pack_sell'] = isset($pr['sell']) && !empty($pr['sell']) && empty($pr['served_portion']) ? (float)$pr['sell'] : null;
                    if (!empty($pr['pack']) && $origPriceSource !== null) {
                        $fin['pack'] = (int)$pr['pack'];
                        $fin['pack_source'] = $origPriceSource;
                        $fin['pack_cell'] = $pr['pack_src'] ?? null;
                        $fin['pack_auto'] = true;
                    }
                }
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
                                            $fin['price_raw'] = null;
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
                                    $fin['pack_auto'] = false;
                                    $fin['pack_reason'] = '';
                                } else {
                                    $needPack = true;
                                }
                            } elseif ($a === 'per-unit' && !empty($fin['pack_auto'])) {
                                $fin['pack'] = null;            // the person said the price is for one unit: the row's pack is not divided into it
                                $fin['pack_source'] = null;
                                $fin['pack_auto'] = false;
                                $fin['pack_reason'] = '';
                            }
                            break;
                        case 'count-unit':
                            // bought by the case, served by the tot: counted in single units -> the price is divided by the row's own pack size and the
                            // person picks the unit (the sheet names none for one single); counted in cases -> as written (QA Q3)
                            if ($a === 'single' && $pr && !empty($pr['info_pack']) && $origPriceSource !== null) {
                                $fin['unit_raw'] = null;
                                $fin['unit_source'] = null;
                                $fin['pack'] = (int)$pr['info_pack'];
                                $fin['pack_source'] = $origPriceSource;
                                $fin['pack_cell'] = $pr['pack_src'] ?? ($f['pack_size']['source'] ?? null);
                                $fin['pack_auto'] = false;
                                $fin['pack_reason'] = '';
                            } elseif ($a === 'portion' && $pr && !empty($pr['info_pack']) && $origPriceSource !== null && isset($f['served_by'])) {
                                // counted in the served measure itself (Step U2 d): single units as above, and the whole count is in that measure
                                $fin['unit_raw'] = null;
                                $fin['unit_source'] = null;
                                $fin['pack'] = (int)$pr['info_pack'];
                                $fin['pack_source'] = $origPriceSource;
                                $fin['pack_cell'] = $pr['pack_src'] ?? ($f['pack_size']['source'] ?? null);
                                $fin['pack_auto'] = false;
                                $fin['pack_reason'] = '';
                                if ($fin['qty'] !== null && is_numeric($fin['qty'])) {
                                    $fin['qty_meas'] = ['word' => mb_strtolower(trim((string)$f['served_by']['value'])), 'amount' => (float)$fin['qty'], 'plain' => 0.0,
                                        'srcs' => (array)($row['computed']['qty_total']['sources'] ?? [])];
                                }
                            } elseif ($a === 'skip') {
                                $skip = 'Skipped by you.';
                            }
                            break;
                        case 'pack-price-check':
                            // both a per-unit and a per-pack price, and they disagree: the person picks one (the per-unit price is the default reading)
                            if ($a === 'pack' && isset($f['pack_price']) && !empty($pr['info_pack'])) {
                                $fin['price'] = $f['pack_price']['value'];
                                $fin['price_source'] = $f['pack_price']['source'];
                                $fin['price_how'] = 'chosen-pack-price';
                                $fin['price_raw'] = null;
                                $fin['pack'] = (int)$pr['info_pack'];
                                $fin['pack_source'] = $f['pack_price']['source'];
                                $fin['pack_cell'] = $pr['pack_src'] ?? ($f['pack_size']['source'] ?? null);
                            } elseif ($a === 'skip') {
                                $skip = 'Skipped by you.';
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
                                    $fin['typo_merged'] = true;      // the person said the two spellings are one item (rows of one sheet are added together below)
                                }
                            }
                            break;
                        case 'duplicate-no-batch':
                        case 'duplicate-batch-clash':
                            if ($a === 'section-items' && isset($q['section_split'][$row['uid']])) {
                                $fin['name'] = import_section_item_name((string)$fin['name'], (string)$q['section_split'][$row['uid']]);
                                $fin['name_how'] = 'name + heading';
                                $rec['warnings'][] = 'Named "' . $fin['name'] . '": the same name is under another heading too (' . $fin['name_source'] . ' + ' . (string)$row['category_source'] . ').';
                                break;
                            }
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
                if (isset($skipRows[$row['uid']])) {
                    // a person chose to leave this row out (even though it was blocked / unanswered): never reaches the database
                    $sr = (array)$skipRows[$row['uid']];
                    $skip = 'Skipped by you.';
                    $rec['skipped_why'] = (string)($sr['why'] ?? '');
                    $rec['skip_code'] = (string)($sr['code'] ?? 'chosen');
                } elseif ($skip !== '') {
                    $rec['skip_code'] = 'answered-skip';
                }
                if ($skip !== '') {
                    $rec['status'] = 'skipped';
                    $rec['reason'] = $skip;
                    $rowsOut[$row['uid']] = $rec;
                    continue;
                }
                // ---- the count-unit question (owner 2026-11-22: "57 = 57 shots, not bottles"; "100% confident"): a row served by a measure (shot,
                // tot, glass) whose count is a plain number may be counted in its stock unit or in that measure. Never guessed: asked once per
                // served-by group, a row may differ. "In shots" = the Step U2 d conversion below (the same path as a cell that says "66 shots").
                // A cell or heading that already names the measure, a row with the bought-by-the-case question, or 0 is not asked.
                $hasCountUnitQ = false;
                foreach ($row['question_ids'] as $qid) {
                    if (($qById[$qid]['type'] ?? '') === 'count-unit') {
                        $hasCountUnitQ = true;
                    }
                }
                if (!$hasCountUnitQ && empty($fin['qty_meas']) && !$fin['qty_unknown'] && is_numeric($fin['qty']) && (float)$fin['qty'] > 0
                    && trim((string)($fin['served_by'] ?? '')) !== '') {
                    $ciUnit = trim((string)($fin['unit_raw'] ?? ''));
                    $rd = import_count_in_read((string)$fin['served_by'], $ciUnit, $ciList, $synonyms, $ciShot, $ciGlass, $ciPour);
                    if ($rd !== null && !import_measure_in_name((string)$fin['name'], $rd['word'])) {
                        $ciUnitS = $ciUnit !== '' ? import_measure_singular(mb_strtolower((string)($synonyms[mb_strtolower($ciUnit)] ?? $ciUnit))) : '';
                        $cuKey = $sh['name'] . '|' . $rd['kind'] . '|' . $rd['word'] . '|' . $ciUnitS;
                        $cuQ = import_xq('count-in', $cuKey, '', []);
                        $rowAns = (string)($countIn[$row['uid']]['key'] ?? '');
                        $rowAns = in_array($rowAns, ['stock', 'measure'], true) ? $rowAns : '';
                        $grpAns = (string)($answers[$cuQ['qkey']]['key'] ?? '');
                        $eff = $rowAns !== '' ? $rowAns : (in_array($grpAns, ['stock', 'measure'], true) ? $grpAns : '');
                        $qSrcs = (array)($row['computed']['qty_total']['sources'] ?? []);
                        if (!isset($countGroups[$cuKey])) {
                            $countGroups[$cuKey] = ['rd' => $rd, 'sheet' => $sh['name'], 'unit' => $ciUnit, 'served' => trim((string)$fin['served_by']),
                                'served_src' => (string)($fin['served_by_source'] ?? ''), 'rows' => [], 'fit_m' => 0, 'fit_s' => 0, 'compared' => 0, 'ex_m' => null, 'ex_s' => null,
                                'value_head' => '', 'value_letter' => ''];
                        }
                        $cg = &$countGroups[$cuKey];
                        $cg['rows'][] = ['uid' => (string)$row['uid'], 'name' => (string)$fin['name'], 'cell' => (string)($qSrcs[0] ?? $row['ref']), 'qty' => (float)$fin['qty'], 'answer' => $rowAns];
                        // evidence (a suggestion only, one click still needed): the sheet's value column = count x the price of ONE SALE (the selling
                        // price of one shot) on these rows, or = count x the price of one stock unit (the unit price)
                        $vv = null;
                        $ss = null;
                        foreach ((array)($row['ignored'] ?? []) as $ig) {
                            if (!empty($ig['unknown']) || ($ig['value'] ?? null) === null) {
                                continue;
                            }
                            $pn = import_parse_number($ig['value']);
                            if ($pn['status'] !== 'ok') {
                                continue;
                            }
                            if (($ig['field'] ?? '') === 'total_cost' && $vv === null) {
                                $vv = (float)$pn['value'];
                                if ($cg['value_head'] === '') {
                                    $cg['value_head'] = (string)($ig['header'] ?? '');
                                    $cg['value_letter'] = preg_replace('/\d+$/', '', import_split_source((string)$ig['source'])[1]);
                                }
                            } elseif (($ig['field'] ?? '') === 'sell_price' && $ss === null) {
                                $ss = (float)$pn['value'];
                            }
                        }
                        $pu = is_numeric($fin['price']) && (float)$fin['price'] > 0 ? (float)$fin['price'] / (!empty($fin['pack']) ? (float)$fin['pack'] : 1.0) : null;
                        if ($vv !== null && $vv > 0 && ($ss !== null || $pu !== null)) {
                            $qv = (float)$fin['qty'];
                            $tol = function (float $x): float {
                                return max(0.5, 0.005 * abs($x));
                            };
                            $fm = $ss !== null && $ss > 0 && abs($vv - $qv * $ss) <= $tol($qv * $ss);
                            $fs = $pu !== null && abs($vv - $qv * $pu) <= $tol($qv * $pu);
                            $cg['compared']++;
                            if ($fm && !$fs) {
                                $cg['fit_m']++;
                                $cg['ex_m'] = $cg['ex_m'] ?? [(string)$fin['name'], $qv, $ss, $vv];
                            } elseif ($fs && !$fm) {
                                $cg['fit_s']++;
                                $cg['ex_s'] = $cg['ex_s'] ?? [(string)$fin['name'], $qv, $pu, $vv];
                            }
                        }
                        unset($cg);
                        if ($eff === 'measure') {
                            // the whole count is in that measure: turned into the stock unit with N below (item measure, step 3 sizes, or asked)
                            $fin['qty_meas'] = ['word' => import_measure_plural($rd['word'], 2), 'amount' => (float)$fin['qty'], 'plain' => 0.0, 'srcs' => $qSrcs, 'count_in' => $rd];
                        } elseif ($eff === '') {
                            $fin['count_in_q'] = $cuQ['id'];          // waits for the answer (the item stays "needs an answer")
                        }
                    }
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
            unset($row);
            if ($freeRows && $prevKey !== null) {
                unset($rowsHolder[$prevKey]);
            }
            unset($rowsHolder);
        }
        unset($sh, $f, $rec, $fin);
        foreach ($countGroups as $cuKey => $g) {
            $rd = $g['rd'];
            $n = count($g['rows']);
            $unitW = $g['unit'] !== '' ? mb_strtolower($g['unit']) : 'stock unit';
            $units = import_measure_plural($unitW, 2);
            $meas = import_measure_plural($rd['word'], 2);
            $ex0 = $g['rows'][0];
            $col = preg_replace('/\d+$/', '', import_split_source($ex0['cell'])[1]);
            $head = '';
            foreach ($analysis['sheets'] as $sh0) {
                if ($sh0['name'] === $g['sheet']) {
                    foreach ((array)($sh0['columns'] ?? []) as $c0) {
                        if (($c0['letter'] ?? '') === $col) {
                            $head = (string)$c0['header'];
                        }
                    }
                }
            }
            $q0 = import_measure_fmt($ex0['qty']);
            $text = 'Your count for ' . ($n === 1 ? 'this drink' : 'these ' . $n . ' drinks') . ' (column ' . $col . ($head !== '' ? ' "' . $head . '"' : '') . '): is it in '
                . $units . ' or in ' . $meas . '? ' . ($n === 1 ? 'It is' : 'They are') . ' sold by the ' . $rd['word'] . ' (' . ($g['served_src'] !== '' ? $g['served_src'] : 'served-by column')
                . ') and kept in ' . $units . '. Answer once for the group; a drink below can have its own answer.';
            $opts = [['key' => 'stock', 'label' => 'In ' . $units . ': "' . $ex0['name'] . '" ' . $q0 . ' means ' . $q0 . ' ' . import_measure_plural($unitW, (float)$ex0['qty'])],
                ['key' => 'measure', 'label' => 'In ' . $meas . ': "' . $ex0['name'] . '" ' . $q0 . ' means ' . $q0 . ' ' . import_measure_plural($rd['word'], (float)$ex0['qty'])
                    . ', turned into ' . $units . ' (' . $meas . ' in one ' . $unitW . ': the item\'s own count measure, else the ' . $rd['word']
                    . ' and ' . $unitW . ' sizes in step 3, else you are asked)']];
            $sug = null;
            $fmtM = function ($x): string {
                return rtrim(rtrim(number_format((float)$x, 2, '.', ','), '0'), '.');
            };
            $vcol = $g['value_head'] !== '' ? '"' . $g['value_head'] . '" column' . ($g['value_letter'] !== '' ? ' (' . $g['value_letter'] . ')' : '') : 'value column';
            if ($g['compared'] > 0 && $g['fit_m'] > 0 && $g['fit_s'] === 0 && $g['fit_m'] * 10 >= $g['compared'] * 8) {
                [$en, $eq, $ep, $ev] = $g['ex_m'];
                $sug = ['key' => 'measure', 'text' => 'Your ' . $vcol . ' is the count x the price of one ' . $rd['word'] . ' on ' . $g['fit_m'] . ' of ' . $g['compared']
                    . ' row' . ($g['compared'] === 1 ? '' : 's') . ' ("' . $en . '": ' . $fmtM($eq) . ' x ' . $fmtM($ep) . ' = ' . $fmtM($ev) . '), so these look like ' . $meas . '.'];
            } elseif ($g['compared'] > 0 && $g['fit_s'] > 0 && $g['fit_m'] === 0 && $g['fit_s'] * 10 >= $g['compared'] * 8) {
                [$en, $eq, $ep, $ev] = $g['ex_s'];
                $sug = ['key' => 'stock', 'text' => 'Your ' . $vcol . ' is the count x the price of one ' . $unitW . ' on ' . $g['fit_s'] . ' of ' . $g['compared']
                    . ' row' . ($g['compared'] === 1 ? '' : 's') . ' ("' . $en . '": ' . $fmtM($eq) . ' x ' . $fmtM($ep) . ' = ' . $fmtM($ev) . '), so these look like ' . $units . '.'];
            }
            if ($sug !== null) {
                foreach ($opts as &$o0) {
                    if ($o0['key'] === $sug['key']) {
                        $o0['label'] .= ' (suggested)';
                    }
                }
                unset($o0);
            }
            $xq = $ans(import_xq('count-in', $cuKey, $text, $opts, ['sheet' => $g['sheet'], 'cells' => array_slice(array_column($g['rows'], 'cell'), 0, 3),
                'row_uids' => array_column($g['rows'], 'uid'), 'count_rows' => array_slice($g['rows'], 0, 500), 'count_rows_n' => $n, 'suggest' => $sug,
                'count_words' => ['stock' => 'In ' . $units, 'measure' => 'In ' . $meas]]));
            if ($xq['answer'] === null && !in_array('', array_column($g['rows'], 'answer'), true)) {
                $xq['answer'] = 'per-row';      // every drink has its own answer: nothing left to ask
            }
            $extra[$xq['qkey']] = $xq;
        }
        if ($freeRows) {
            // the rows of the file are not read again below: let go of them (the caller asked for this and keeps its own slim copy of what it needs)
            foreach (array_keys($analysis['sheets']) as $si) {
                $analysis['sheets'][$si]['rows'] = [];
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
                        $rowsOut[$uid]['blocked_code'] = 'separate-items';
                        $cands[$j]['dropped'] = true;
                    }
                } elseif ($a === null && $res === null && count(array_filter($idxs, function ($j) use ($cands) {
                    return $cands[$j]['dup'] !== null || ($cands[$j]['group']['resolution'] ?? null) !== null;
                })) === 0 && count(array_filter($idxs, function ($j) use ($cands) {
                    return !empty($cands[$j]['fin']['typo_merged']);
                })) > 0) {
                    // the rows share a name only because the person answered a possible-typo question "Use X for both": one item, quantities
                    // added (that answer is the person's decision; there is no duplicate question on these rows to wait for).
                    // Counts in different units are never added (4 bottle + 2 crate is not 6 of either): those rows are blocked with a plain reason.
                    $uk = [];
                    $ur = [];
                    foreach ($idxs as $j) {
                        $raw = trim((string)($cands[$j]['fin']['unit_raw'] ?? ''));
                        if ($raw !== '') {
                            $uk[import_unit_key($raw)] = true;
                            $ur[import_unit_key($raw)] = $raw;
                        }
                    }
                    if (count($uk) > 1) {
                        foreach ($idxs as $j) {
                            $uid = $cands[$j]['rec']['uid'];
                            $rowsOut[$uid]['status'] = 'blocked';
                            $rowsOut[$uid]['reason'] = 'The two spellings have different units (' . implode(' / ', array_values($ur)) . '), so their counts cannot be added. Keep them separate (answer "keep both" on the spelling question), or fix the unit in the sheet and upload again.';
                            $rowsOut[$uid]['blocked_code'] = 'typo-units-differ';
                            $cands[$j]['dropped'] = true;
                        }
                    } else {
                        $sheetItems[] = ['members' => $idxs, 'sheet' => $sheetName, 'nk' => $nk];
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
            if ($freeRows) {
                foreach ($it['members'] as $mi) {          // each candidate belongs to exactly one item: let go of it now ({$ms} holds what this item needs)
                    unset($cands[$mi]);
                }
            }
            $first = $ms[0]['fin'];
            $open = $it['open'];
            foreach ($ms as $mm) {
                if (!empty($mm['fin']['count_in_q'])) {
                    $open[] = (string)$mm['fin']['count_in_q'];        // the count-unit question is not answered for this row yet
                }
            }
            $open = array_values(array_unique($open));
            $warn = [];
            $costSuspect = null;
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
                        ['sheet' => $ms[0]['sheet'], 'conflict_field' => $fld === 'price' ? 'unit_price' : 'unit', 'cells' => array_column($vals, 'source'), 'row_uids' => array_map(function ($m) {
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
            $packAuto = false;
            $packName = null;
            $packInfo = null;
            $packReason = '';
            foreach ($ms as $m) {
                if ($m['fin']['pack_name'] !== null && $packName === null) {
                    $packName = (string)$m['fin']['pack_name'];
                }
                if ($m['fin']['pack_info'] && $packInfo === null) {
                    $packInfo = (int)$m['fin']['pack_info'];
                }
                if ($m['fin']['pack_reason'] !== '' && $packReason === '' && $m['fin']['price_source'] === $first['price_source']) {
                    $packReason = $m['fin']['pack_reason'];
                }
                if ($m['fin']['pack']) {
                    if ($m['fin']['pack_source'] !== null && $m['fin']['pack_source'] === $first['price_source']) {
                        $pack = $m['fin']['pack'];
                        $packAuto = !empty($m['fin']['pack_auto']);
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
                    $costHow = $packAuto && $packReason !== '' ? $packReason : 'case price / pack size ' . $pack;
                } else {
                    $cost = round((float)$first['price'], 4);
                    $costHow = ($packReason !== '' && ($first['price_how'] ?? 'as-written') === 'as-written') ? $packReason : ($first['price_how'] ?? 'as-written');
                }
            }
            // ---- a price column headed "per tot": the price of ONE measure. Never multiplied by N into a stock-unit cost; asked (Step U2 d, accounting gate) ----
            $pm = null;
            foreach ($ms as $mm) {
                if (!empty($mm['fin']['price_meas']) && $mm['fin']['price_source'] !== null && $mm['fin']['price_source'] === $first['price_source']) {
                    $pm = $mm['fin']['price_meas'];
                    break;
                }
            }
            if ($pm !== null && $cost !== null) {
                $pw = import_measure_singular((string)$pm['word']);
                $xq = $ans(import_xq('price-per-measure', $ms[0]['sheet'] . '|' . $pw,
                    'Sheet "' . $ms[0]['sheet'] . '": the price column is headed as a price per ' . $pw . ' (' . $pm['src'] . '). A cost is kept per stock unit (bottle, can ...) and a price per '
                    . $pw . ' is never multiplied up into one. Leave the cost blank for these items (add it later in Stock), or use each price exactly as written (not divided by a pack size, not multiplied) as the cost of one stock unit?',
                    [['key' => 'drop', 'label' => 'Leave the cost blank (add it later in Stock)'], ['key' => 'keep', 'label' => 'Use each price exactly as written as the cost of one stock unit, not divided or multiplied by anything (the heading is wrong)']],
                    ['sheet' => $ms[0]['sheet'], 'cells' => [(string)$pm['src']]]));
                $xq = import_measure_share_xq($extra, $xq, array_map(function ($x) {
                    return $x['rec']['uid'];
                }, $ms), [(string)$first['price_source']]);
                if ($xq['answer'] === null) {
                    $open[] = $xq['id'];
                } elseif ($xq['answer'] === 'drop') {
                    $cost = null;
                    $costHow = null;
                    $first['price'] = null;
                    $pack = null;
                    $warn[] = 'The price in ' . $first['price_source'] . ' is a price per ' . $pw . ', so no cost is set: add the cost of one stock unit later in Stock.';
                } elseif ($xq['answer'] === 'keep' && $pack) {
                    // "exactly as written": the pack-price division above is undone; the pack size itself stays as the sheet gave it
                    $cost = round((float)$first['price'], 4);
                    $costHow = 'as-written';
                    $warn[] = 'The price in ' . $first['price_source'] . ' is used exactly as written as the cost of one stock unit, not divided by the pack of ' . $pack . '.';
                }
            }
            // match with what is already in stock
            $lookupName = $first['match_name'] ?? $first['name'];
            $m = import_match_existing($lookupName, $ctx);
            $matchHow = $m['how'];
            $ex = $m['row'];
            $rawUnit = $first['unit_raw'];
            $unit = null;
            $unitHow = null;
            if ($rawUnit !== null && $rawUnit !== '') {
                // self-healing "unit-spelling": btl / Bottles -> bottle, LTR -> L (the same unit, spelled the way stock spells it; never a conversion)
                $unit = import_unit_canon((string)$rawUnit, $synonyms);
                if ($unitOptions && !in_array($unit, $unitOptions, true)) {
                    // "Can" / "Each": the same word as a unit this hotel already uses, only in other capitals -> the hotel's spelling
                    foreach ($unitOptions as $uo) {
                        if (mb_strtolower($uo) === mb_strtolower($unit)) {
                            $unit = $uo;
                            break;
                        }
                    }
                }
                $unitHow = 'from the sheet';
                if (trim((string)$rawUnit) !== $unit) {
                    $hid = substr(sha1('unit-spelling|' . ($first['unit_source'] ?? $it['nk'])), 0, 12);
                    if (isset($healUndo[$hid])) {
                        $unit = mb_substr(trim((string)$rawUnit), 0, 50);          // undone: the unit exactly as written
                    } elseif (!$ex) {
                        $resolveHealN++;
                        if ($resolveHealN <= 2000) {
                        $resolveHeals[] = ['id' => $hid, 'rule' => 'unit-spelling', 'field' => 'unit', 'sheet' => $ms[0]['sheet'], 'row' => $ms[0]['rec']['row'], 'uid' => $ms[0]['rec']['uid'],
                            'source' => (string)($first['unit_source'] ?? ''), 'before' => trim((string)$rawUnit), 'after' => $unit, 'undoable' => true, 'item' => $first['name'],
                            'text' => 'Read the unit "' . trim((string)$rawUnit) . '" as "' . $unit . '" (the same unit, spelled the way your stock spells it).'];
                        }
                    }
                }
            }
            // ---- a count in shots / tots / glasses: turned into the stock unit with N (Step U2 d, measure.php). Never a guess: the item's own measure,
            // its size / the hotel's measure size, or the person's answer. Costs are never divided by N. ----
            $qtyHow = null;
            $measureSave = null;
            $countN = null;         // N of the measure the count was in (display: "57 shots = 2 bottles + 7 shots")
            $countRows = [];        // sheet \x1f row => the count in the stock unit, for rows answered "in shots" (the value check compares these)
            $measWords = [];
            $ciRd = null;           // a count answered "in shots": the step 3 sizes give N (import_count_in_step3)
            $ciWait = false;        // ... and N is not known yet: its sizes can still be typed in step 3 (import_drinks_build lists it)
            foreach ($ms as $mm) {
                if (!empty($mm['fin']['qty_meas']['count_in'])) {
                    $ciRd = $mm['fin']['qty_meas']['count_in'];
                }
            }
            foreach ($ms as $mm) {
                if (!empty($mm['fin']['qty_meas']) && (float)$mm['fin']['qty_meas']['amount'] > 0) {    // 0 tots is 0 bottles whatever N is: nothing to ask
                    $measWords[import_measure_singular((string)$mm['fin']['qty_meas']['word'])] = (string)$mm['fin']['qty_meas']['word'];
                }
            }
            if ($measWords && !$unknownQty) {
                $stockUnit = $ex ? (string)$ex['unit'] : (string)($unit ?? '');
                if ($stockUnit === '') {
                    // a new item with no unit in the file: the unit the person already chose for it (or for its sheet), the same answers the no-unit pass reads
                    $cand = '';
                    $uf = $unitFor[(string)$it['nk']] ?? null;
                    if (is_array($uf) && isset($uf['unit'])) {
                        $cand = (string)$uf['unit'];
                    } else {
                        $nu = $answers[import_xq('no-unit', (string)$ms[0]['sheet'], '', [])['qkey']]['key'] ?? '';
                        $cand = (is_string($nu) && strpos($nu, 'unit:') === 0) ? substr($nu, 5) : '';
                    }
                    foreach ($unitOptions as $uo) {
                        if ($cand !== '' && mb_strtolower((string)$uo) === mb_strtolower($cand)) {
                            $stockUnit = (string)$uo;
                            break;
                        }
                    }
                }
                $nBy = [];
                $howBy = [];
                $waitMeas = false;
                $askOpen = false;
                $step3 = null;
                if ($ciRd !== null && $stockUnit !== '') {
                    // read again with the stock unit the item ends up with (an existing item's own unit), so the step 3 group is the one step 3 shows
                    $rd2 = import_count_in_read((string)($first['served_by'] ?? ''), $stockUnit, $ciList, $synonyms, $ciShot, $ciGlass, $ciPour);
                    $step3 = import_count_in_step3($rd2 !== null && $rd2['word'] === $ciRd['word'] ? $rd2 : $ciRd, (string)$it['nk'], $sizeAnswers);
                }
                foreach ($measWords as $ws => $wWritten) {
                    $d = import_measure_decide(['word' => $wWritten, 'stock_unit' => $stockUnit, 'name' => (string)$first['name'], 'existing' => $ex,
                        'list' => (array)($ctx['drink_measures'] ?? []), 'synonyms' => $synonyms, 'nk' => (string)$it['nk'],
                        'containers' => (array)($ctx['container_words'] ?? import_default_aliases()['container_words']),
                        'step3' => ($step3 !== null && $ws === import_measure_singular((string)$ciRd['word'])) ? $step3 : null]);
                    if ($d['mode'] === 'wait') {
                        $waitMeas = true;
                        continue;
                    }
                    if ($d['mode'] === 'none') {
                        $nBy[$ws] = 1.0;
                        $howBy[$ws] = '';
                        continue;
                    }
                    if ($d['kept'] !== null) {
                        $warn[] = '"' . ($ex['name'] ?? $first['name']) . '" already has its own count measure (' . import_measure_fmt((float)$d['kept']['n']) . ' ' . import_measure_plural((string)$d['kept']['small'], (float)$d['kept']['n']) . ' in one '
                            . $d['kept']['big'] . '), not in ' . $wWritten . ': it is kept as it is.';
                    }
                    if ($d['mode'] === 'n') {
                        $nBy[$ws] = (float)$d['n'];
                        if ($d['how'] === 'item') {
                            $howBy[$ws] = 'this item\'s own count measure';
                        } elseif (($d['size_from'] ?? '') !== '' && in_array($d['size_from'], ['row', 'group'], true) || !in_array($d['measure_from'] ?? 'list', ['list', ''], true)) {
                            // the step 3 sizes (a count answered "in shots"): say where each size came from
                            $fromW = ['row' => ' typed for this drink in step 3', 'group' => ' set for the group in step 3', 'name' => ' in its name', 'item' => ' saved on the item',
                                'unit' => '', 'cell' => ' written in your file', 'list' => ' on your Drink measures list'];
                            $howBy[$ws] = import_measure_fmt((float)$d['size_ml']) . ' ml ' . mb_strtolower($stockUnit) . ($fromW[$d['size_from']] ?? '') . ' / '
                                . import_measure_fmt((float)$d['measure_ml']) . ' ml ' . $ws . ($fromW[$d['measure_from']] ?? '');
                        } else {
                            $howBy[$ws] = import_measure_fmt((float)$d['size_ml']) . ' ml / ' . import_measure_fmt((float)$d['measure_ml']) . ' ml ' . $ws . ' on your measures list';
                        }
                        continue;
                    }
                    // ask: one question per measure + size + stock unit (items without a size are asked one by one)
                    $sizeTxt = $d['size_ml'] !== null ? ' ' . import_measure_fmt((float)$d['size_ml']) . ' ml' : '';
                    $wp = import_measure_plural($ws, 2);
                    $xq = $ans(import_xq('measure-n', $d['key'],
                        'How many ' . $wp . ' are in one' . $sizeTxt . ' ' . $stockUnit . ($d['size_ml'] === null ? ' of "' . ($ex['name'] ?? $first['name']) . '"' : '') . '? The count'
                        . ' is written in ' . $wWritten . ' and stock is kept in ' . import_measure_plural($stockUnit, 2) . '. Type a number from 1 to ' . import_measure_fmt(import_measure_max_n())
                        . '; it is saved on the item as its count measure. Costs are not changed by it.',
                        [['key' => 'n', 'label' => 'Use this number'], ['key' => 'skip', 'label' => 'Skip these rows']],
                        ['sheet' => $ms[0]['sheet'], 'measure_word' => $ws, 'measure_unit' => $stockUnit, 'measure_size_ml' => $d['size_ml']]));
                    $cellsM = [];
                    foreach ($ms as $mm) {
                        if (!empty($mm['fin']['qty_meas'])) {
                            $cellsM = array_merge($cellsM, (array)$mm['fin']['qty_meas']['srcs']);
                        }
                    }
                    $nAns = ($xq['answer'] === 'n') ? import_measure_n_valid($xq['answer_detail']['pack_size'] ?? null) : null;
                    if ($xq['answer'] === 'n' && $nAns === null) {
                        $xq['answer'] = null;               // a number outside 1..max is no answer: asked again
                        $xq['answer_detail'] = null;
                    }
                    $xq = import_measure_share_xq($extra, $xq, array_map(function ($x) {
                        return $x['rec']['uid'];
                    }, $ms), $cellsM);
                    if ($xq['answer'] === 'skip') {
                        foreach ($ms as $mm) {
                            $rowsOut[$mm['rec']['uid']]['status'] = 'skipped';
                            $rowsOut[$mm['rec']['uid']]['reason'] = 'Skipped by you.';
                        }
                        continue 2;
                    }
                    if ($nAns === null) {
                        $open[] = $xq['id'];
                        $askOpen = true;
                        continue;
                    }
                    $nBy[$ws] = $nAns;
                    $howBy[$ws] = 'your answer';
                    if ($measureSave === null) {
                        $measureSave = ['big' => $stockUnit, 'small' => $ws, 'n' => $nAns, 'side' => 'big', 'qkey' => $xq['qkey']];
                    }
                }
                $ciWait = $ciRd !== null && $askOpen && !$waitMeas;
                if ($waitMeas || $askOpen) {
                    $balance = null;
                    $batches = [];
                    $measureSave = null;
                    $warn[] = 'Part of the count is in ' . implode(' / ', array_values($measWords)) . ': the stock is worked out once '
                        . ($waitMeas ? 'the unit is chosen' : 'you say how many are in one ' . $stockUnit) . '.';
                } else {
                    $balance = null;
                    $how = [];
                    $conv = [];
                    foreach ($ms as $k => $mm) {
                        $q = $mm['fin']['qty'];
                        $qm = $mm['fin']['qty_meas'];
                        if ($q !== null && !empty($qm) && (float)$qm['amount'] > 0) {
                            $ws = import_measure_singular((string)$qm['word']);
                            $q = round((float)$qm['plain'] + (float)$qm['amount'] / $nBy[$ws], 6);
                            if (!empty($qm['count_in'])) {
                                $countRows[$mm['sheet'] . "\x1f" . (int)$mm['rec']['row']] = $q;
                            }
                            if ($howBy[$ws] !== '') {
                                $how[] = import_measure_text((float)$qm['plain'], (float)$qm['amount'], (string)$qm['word'], $nBy[$ws], $stockUnit, $q, $howBy[$ws]);
                            }
                        }
                        $conv[$k] = $q;
                        if ($q !== null) {
                            $balance = round(($balance ?? 0) + $q, 6);
                        }
                    }
                    $qtyHow = $how ? implode(' ', $how) : null;
                    if (count($nBy) === 1 && ($howBy[array_key_first($nBy)] ?? '') !== '' && $stockUnit !== '') {
                        $countN = ['n' => (float)$nBy[array_key_first($nBy)], 'small' => (string)array_key_first($nBy), 'big' => mb_strtolower($stockUnit)];
                    }
                    $batches = [];
                    foreach ($ms as $k => $mm) {
                        $q = $conv[$k];
                        $label = $mm['fin']['batch'];
                        $exp = $mm['fin']['expiry'];
                        if ($q !== null && $q > 0 && ($exp !== null || ($label !== null && $label !== ''))) {
                            $batches[] = ['label' => $label !== null ? mb_substr($label, 0, 50) : '', 'qty' => $q, 'expiry' => $exp, 'source' => $mm['fin']['expiry_source'] ?? $mm['fin']['name_source']];
                        }
                    }
                    if ($ex && $measureSave !== null && !empty($ex['_measure'])) {
                        $measureSave = null;                // an item's own measure is never replaced by an import
                    }
                }
            }
            $action = $ex ? 'update' : 'create';
            $diff = [];
            $priceCheckOk = true;           // false when the match itself is in doubt (the price of a different thing means nothing)
            $unitHint = '';
            $itemOpen = $open;
            $uidList = array_map(function ($x) {
                return $x['rec']['uid'];
            }, $ms);
            $rowRefs = array_map(function ($x) {
                return $x['rec']['ref'];
            }, $ms);
            if ($ex) {
                if ($m['ambiguous']) {
                    $priceCheckOk = false;
                    $xq = $ans(import_xq('ambiguous-match', $it['nk'], '"' . $first['name'] . '" could be several existing ingredients that differ only in punctuation or accents. Rename it in the sheet to the exact stock name, or skip it.',
                        [['key' => 'skip', 'label' => 'Skip this item']], ['bad' => true, 'sheet' => $ms[0]['sheet'], 'cells' => $rowRefs, 'row_uids' => $uidList]));
                    $extra[$xq['qkey']] = $xq;
                    $itemOpen[] = $xq['id'];
                }
                if ((int)$ex['is_archived'] === 1) {
                    $priceCheckOk = false;
                    $xq = $ans(import_xq('archived-match', $it['nk'], '"' . $first['name'] . '" matches an ARCHIVED ingredient ("' . $ex['name'] . '"). It is left alone: restore or rename that ingredient, then upload again, or skip this row.',
                        [['key' => 'skip', 'label' => 'Skip this item']], ['bad' => true, 'sheet' => $ms[0]['sheet'], 'cells' => $rowRefs, 'row_uids' => $uidList]));
                    $extra[$xq['qkey']] = $xq;
                    $itemOpen[] = $xq['id'];          // always: an answered "skip" is what takes the item out (the final loop looks for it)
                } elseif ($unit !== null && !import_units_compatible((string)$unit, (string)$ex['unit'], $synonyms)) {
                    $priceCheckOk = false;
                    $unitHint = ($cost !== null) ? import_pc_unit_hint((string)$unit, (string)$ex['unit'], $cost, $ex['cost_per_unit']) : '';
                    $xq = $ans(import_xq('unit-clash', $it['nk'], '"' . $first['name'] . '" is in stock in "' . $ex['unit'] . '" but the sheet says "' . $rawUnit . '". Nothing is changed for it. Fix the sheet, or skip this row.' . $unitHint,
                        [['key' => 'skip', 'label' => 'Skip this item']], ['bad' => true, 'sheet' => $ms[0]['sheet'], 'cells' => $rowRefs, 'row_uids' => $uidList,
                            'evidence' => $unitHint !== '' ? ['reason' => 'unit-differs', 'old' => (float)$ex['cost_per_unit'], 'new' => (float)$cost, 'unit_sheet' => (string)$unit, 'unit_existing' => (string)$ex['unit']] : null]));
                    $extra[$xq['qkey']] = $xq;
                    $itemOpen[] = $xq['id'];          // always: an answered "skip" is what takes the item out (the final loop looks for it)
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
                if (isset($costSuspects[(int)$ex['id']])) {
                    $cs = $costSuspects[(int)$ex['id']];
                    $warn[] = 'The cost now in stock (' . import_fmt_money($ex['cost_per_unit']) . ' per ' . $ex['unit'] . ') may be the price of a whole case: '
                        . $cs['reason'] . ' Check it; to replace it with the file\'s cost, tick Cost.';
                    $costSuspect = ['reason' => $cs['reason'], 'verdict' => $cs['verdict'], 'cost' => (float)$ex['cost_per_unit']];
                }
            } elseif ($unit === null) {
                $noUnitByItem[] = count($outItems);
            }
            // ---- is this price plausible for THIS hotel? Asks, never rejects (price-check.php) -------------------------------
            // Only once the row itself is settled (a pending per-case answer or price disagreement would make the number provisional).
            $rowRank = max(array_map(function ($x) {
                return import_status_rank($x['rec']['status']);
            }, $ms));
            $priceRaw = null;
            foreach ($ms as $mm) {
                if ($mm['fin']['price_source'] === $first['price_source'] && $mm['fin']['price_raw'] !== null) {
                    $priceRaw = (string)$mm['fin']['price_raw'];
                }
            }
            // The file's cost is only judged (and size-checked) when it will actually be applied: a new item, or an existing item whose
            // cost the person ticked. An unticked cost changes nothing; if it is ticked later, the question appears then (recomputed).
            $ukey = rh_ssi_key((string)($ex['name'] ?? ''));
            $tickedRaw = array_map('strval', (array)($updates[$ukey] ?? []));
            // a pack size from a per-case answer is only right together with its divided cost (else the case price stays the cost of one unit):
            // ticking Pack also ticks Cost, so the carried cost is price-checked and size-checked like a hand tick
            $carryCost = $ex && $pack && isset($diff['pack']) && isset($diff['cost']) && in_array('pack', $tickedRaw, true) && !in_array('cost', $tickedRaw, true);
            $keptCurrent = false;
            $costApplied = !$ex || ($cost !== null && isset($diff['cost']) && (in_array('cost', $tickedRaw, true) || $carryCost));
            $storeProblems = ($cost !== null && $costApplied) ? import_cost_storage_problems(['cost' => $cost, 'pack' => $pack], $costLimits) : [];
            // an existing item whose stored cost IS this row's whole-pack price (a crate price kept as the cost of one bottle) and the row's own cells
            // divide it: the drop is explained by the row, so it is shown with that reason instead of being asked about (A84)
            $packExplained = false;
            // ... and only when something says the row's price really is a pack price: it is above what one unit sells for, or the stored cost is
            // already flagged as a case price by this hotel's own data (QA Q2). Otherwise the price check asks as before.
            $packSell = null;
            foreach ($ms as $mm) {
                if ($mm['fin']['price_source'] === $first['price_source'] && ($mm['fin']['pack_sell'] ?? null) !== null) {
                    $packSell = (float)$mm['fin']['pack_sell'];
                }
            }
            // the stored cost IS the whole pack's price, decided from the file alone (no selling price needed): it equals this row's undivided pack
            // price, or it is at least the divided cost x the pack size (2% slack). Then the quantity is never counted at it unless Cost is ticked (B2).
            $storedIsPack = $ex && $pack && $cost !== null && $cost > 0 && (float)$ex['cost_per_unit'] > 0 && (
                ($first['price'] !== null && abs((float)$ex['cost_per_unit'] - (float)$first['price']) <= max(0.5, 0.005 * (float)$first['price']))
                || (float)$ex['cost_per_unit'] >= $cost * $pack * 0.98);
            if ($ex && $pack && $packAuto && $cost !== null && (float)$ex['cost_per_unit'] > 0 && $first['price'] !== null
                && abs((float)$ex['cost_per_unit'] - (float)$first['price']) <= max(0.5, 0.005 * (float)$first['price'])
                && (($packSell !== null && (float)$first['price'] > $packSell) || isset($costSuspects[(int)$ex['id']]))) {
                $packExplained = true;
                $warn[] = 'The cost now in stock (' . import_fmt_money($ex['cost_per_unit']) . ' per ' . $ex['unit'] . ') is the price of the whole ' . mb_strtolower((string)($packName ?? 'pack'))
                    . ' of ' . (int)$pack . '. ' . $packReason . ' Tick Cost to change it.';
            }
            if ($cost !== null && $costApplied && $priceCheckOk && !$storeProblems && $rowRank === 0 && !$open && !$packExplained) {
                $exCost = $ex ? (float)$ex['cost_per_unit'] : 0.0;
                $exId = $ex ? (int)$ex['id'] : 0;
                $catKey = import_pc_cat_key($ex ? $ex['category'] : $first['category']);
                $peers = [];
                if (!$ex || $exCost <= 0) {       // no price of its own to compare with: similar items in stock are the evidence
                    foreach ($pcPeers[$catKey] ?? [] as $pe) {
                        if ($unit === null || $pe['unit'] === mb_strtolower((string)$unit)) {
                            $peers[] = $pe['cost'];
                        }
                    }
                }
                $firstQty = null;
                foreach ($ms as $mm) {
                    if ($mm['fin']['price_source'] === $first['price_source'] && $mm['fin']['qty'] !== null) {
                        $firstQty = (float)$mm['fin']['qty'];
                    }
                }
                $ev = import_pc_assess([
                    'new' => $cost, 'old' => $exCost > 0 ? $exCost : null, 'history' => $exId ? ($pcEv['history'][$exId] ?? []) : [], 'peers' => $peers,
                    'cat_move' => $pcEv['category_move'][$catKey] ?? null, 'hotel_move' => $pcEv['hotel_move'] ?? null,
                    'own_pack' => $ex && isset($ex['pack_size']) ? $ex['pack_size'] : null, 'pack_sizes' => array_values($pcPackSizes),
                    'raw' => $priceRaw, 'qty' => $firstQty,
                ], $pcp);
                if ($ev !== null) {
                    $srcCell = (string)($first['price_source'] ?? '');
                    $ev['source'] = $srcCell;
                    $ev['pack'] = $pack;
                    $ev['no_cost_yet'] = $ex && $exCost <= 0;
                    $newLabel = $pack ? import_pack_label($first['price'], $pack) : import_fmt_money($cost);
                    $text = import_pc_question_text($ev, $ex ? (string)$ex['name'] : $first['name'], $srcCell, (string)$first['category']);
                    if ($pack) {
                        $text .= ' (The file price is ' . $newLabel . '.)';
                    }
                    $opts = [['key' => 'file', 'label' => 'The file\'s price (' . import_fmt_money($cost) . ') is right']];
                    if ($ex && $exCost > 0) {
                        $opts[] = ['key' => 'current', 'label' => 'Keep the current price (' . import_fmt_money($exCost) . ') - the quantity is still imported'];
                    }
                    $opts[] = ['key' => 'skip', 'label' => 'Skip this row'];
                    $xq = $ans(import_xq('price-check', $it['nk'] . '|' . $exId . '|' . number_format($cost, 4, '.', '') . '|' . number_format($exCost, 4, '.', ''), $text, $opts,
                        ['sheet' => $ms[0]['sheet'], 'cells' => $rowRefs, 'row_uids' => $uidList, 'evidence' => $ev]));
                    $extra[$xq['qkey']] = $xq;
                    $itemOpen[] = $xq['id'];
                    if ($ex && $exCost > 0 && $xq['answer'] === 'current') {
                        // the cost field is left alone for this item; the opening balance (if any) still imports at the current cost
                        unset($diff['cost']);
                        $keptCurrent = true;
                        $warn[] = 'You chose to keep the current cost ' . import_fmt_money($exCost) . '; the file\'s ' . import_fmt_money($cost) . ' is not used.';
                    }
                }
            }
            $ticked = array_values(array_intersect(import_update_fields(), array_map('strval', (array)($updates[rh_ssi_key((string)($ex['name'] ?? ''))] ?? []))));
            $ticked = $ex ? array_values(array_filter($ticked, function ($f) use ($diff) {
                return isset($diff[$f]);
            })) : [];
            if ($ex && $pack && in_array('pack', $ticked, true) && !in_array('cost', $ticked, true)) {
                if (isset($diff['cost'])) {
                    $ticked[] = 'cost';
                    $warn[] = 'Pack is ticked, so Cost is changed with it: ' . import_pack_label($first['price'], $pack) . ' ' . $unit
                        . '. A pack size without its divided cost would leave the case price as the cost of one ' . $unit . '. Untick Pack to keep the current cost.';
                } elseif ($keptCurrent) {
                    $ticked = array_values(array_diff($ticked, ['pack']));
                    $warn[] = 'The pack size of ' . (int)$pack . ' is not saved: you kept the current cost, and a pack size goes in only with the cost divided by it.';
                }
            }
            if ($ex && $measureSave !== null && !in_array('quantity', $ticked, true)) {
                $measureSave = null;                        // the answer is saved on an existing item only together with the count it converted
            }
            // costs and stock on hand (accounting rule A11): stock with no cost is left out of the opening count
            $effCost = $cost;
            if ($ex && !in_array('cost', $ticked, true)) {
                $effCost = (float)$ex['cost_per_unit'] > 0 ? (float)$ex['cost_per_unit'] : null;
            }
            $inCount = false;
            // a 0 written in the file and ticked for an existing item is a count of nothing (a shortage line); a blank cell is null, never 0
            $zeroTicked = $ex && $balance !== null && $balance <= 0.00005 && in_array('quantity', $ticked, true);
            if ($balance !== null && ($balance > 0.00005 || $zeroTicked)) {
                if ($ex) {
                    $inCount = in_array('quantity', $ticked, true) && $effCost !== null && $effCost > 0;
                    $packHeld = false;
                    if ($inCount && $storedIsPack && !in_array('cost', $ticked, true)) {
                        // the stored cost is the whole crate's price: counting at it would value every bottle at a crate (accounting A2 / B2). Never auto-ticked.
                        $inCount = false;
                        $packHeld = true;
                        $warn[] = 'The quantity stays out of the opening count until Cost is ticked: the cost now in stock is the price of the whole ' . mb_strtolower((string)($packName ?? 'pack')) . '.';
                    }
                    if (in_array('quantity', $ticked, true) && !$inCount && !$packHeld) {
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
            // the PHYSICAL limit: a cost (or an opening-count value) that cannot be stored in its column. Re-checked at commit.
            $costFinal = !$ex || in_array('cost', $ticked, true);          // is the file's cost really going to be written?
            $storeProblems = import_cost_storage_problems(['cost' => $cost, 'cost_applied' => $costFinal, 'pack' => $pack, 'in_count' => $inCount, 'balance' => $balance, 'line_cost' => $effCost], $costLimits);
            if ($storeProblems) {
                $xq = $ans(import_xq('cost-too-large', (string)$it['nk'],
                    'Row ' . $ms[0]['rec']['row'] . ' "' . ($ex ? (string)$ex['name'] : $first['name']) . '" ' . implode(' and ', $storeProblems)
                    . '. Fix the cell in your file and upload again, or skip this row. (This is a storage limit, not a price rule.)',
                    [['key' => 'skip', 'label' => 'Skip this row']],
                    ['bad' => true, 'sheet' => $ms[0]['sheet'], 'row' => null, 'cells' => $rowRefs, 'row_uids' => $uidList,
                        'evidence' => ['reason' => 'too-large-to-store', 'new' => $cost, 'max_cost' => $costLimits['cost'], 'max_line_value' => $costLimits['line_value'], 'from_database' => $costLimits['from_database']]]));
                $extra[$xq['qkey']] = $xq;
                $itemOpen[] = $xq['id'];
            }
            $outItems[] = [
                'line_cost' => $effCost, 'cost_applied' => $costFinal,
                'key' => $it['nk'], 'name' => $ex ? (string)$ex['name'] : $first['name'], 'typed_name' => $first['name'], 'name_source' => $first['name_source'],
                'category' => $first['category'], 'category_how' => $first['category_how'], 'category_source' => $first['category_source'],
                'unit' => $unit, 'unit_raw' => $rawUnit, 'unit_how' => $unitHow, 'unit_source' => $first['unit_source'],
                'price' => $first['price'], 'price_source' => $first['price_source'], 'cost' => $cost, 'cost_how' => $costHow, 'pack' => $pack,
                'pack_name' => $pack ? $packName : null, 'pack_info' => $packInfo, 'pack_reason' => $packReason, 'pack_auto' => $packAuto,
                'balance' => $balance, 'max' => $max, 'min' => $min, 'remarks' => $remarks, 'batches' => $batches,
                'qty_how' => $qtyHow, 'measure_save' => $measureSave,
                // counted in a measure with a known N (display only, owner 2026-11-22): "57 shots = 2 bottles + 7 shots", the exact figure on hover
                'count_n' => $countN, 'count_rows' => $countRows, 'count_in_wait' => $ciWait,
                'qty_words' => ($countN !== null && $balance !== null) ? import_qty_words((float)$balance, $countN, (string)$countN['big']) : null,
                'rows' => $rowRefs, 'uids' => $uidList, 'sheet' => $ms[0]['sheet'], 'clean_sheet' => $ms[0]['cleanSheet'],
                'match' => $ex ? ['id' => (int)$ex['id'], 'name' => (string)$ex['name'], 'how' => $first['match_name'] !== null ? (!empty($first['match_auto']) ? 'learned' : 'chosen') : $matchHow, 'archived' => (int)$ex['is_archived'] === 1] : null,
                'action' => $action, 'diff' => $diff, 'ticked' => $ticked, 'in_count' => $inCount, 'warnings' => $warn, 'open' => $itemOpen, 'cost_suspect' => $costSuspect,
                'row_status' => max(array_map(function ($x) {
                    return import_status_rank($x['rec']['status']);
                }, $ms)),
                'unanswered' => $it['unanswered'],
                // carried as read (never used for stock): every selling-price cell of the item's rows, how it is served, where the category came from
                'sells' => array_merge(...array_map(function ($x) {
                    return (array)($x['fin']['sells'] ?? []);
                }, $ms)),
                'served_by' => $first['served_by'] ?? null, 'served_by_source' => $first['served_by_source'] ?? null, 'category_col' => !empty($first['category_col']),
            ];
        }
        // one "which unit?" question per sheet for NEW items with no unit anywhere in the file
        // Each of those items also gets a SUGGESTED unit (infer.php: evidence only, never applied until the person clicks) and can be answered on its own
        // (answers['_unit_for'][item key]); an item answered on its own does not wait for the sheet-wide answer.
        $bySheetNoUnit = [];
        foreach ($noUnitByItem as $oi) {
            $bySheetNoUnit[$outItems[$oi]['sheet']][] = $oi;
        }
        $noUnitIndex = [];
        $learned = (array)($ctx['learned'] ?? []);
        $ix = null;
        $needCatIx = false;
        foreach ($outItems as $it0) {
            if (empty($it0['match']) && ($it0['category_how'] ?? '') === 'sheet-name') {
                $needCatIx = true;
                break;
            }
        }
        if ($bySheetNoUnit || ($needCatIx && ($learned || !empty($ctx['existing'])))) {
            $ix = import_infer_index((array)($ctx['existing'] ?? []), $learned, $outItems, $synonyms);
        }
        $inferBudget = 5000;            // suggestions are worked out for at most this many items per request (a huge sheet is just asked)
        foreach ($bySheetNoUnit as $sheetName => $ois) {
            $xq = $ans(import_xq('no-unit', $sheetName,
                'Sheet "' . $sheetName . '" has no unit for ' . count($ois) . ' new item' . (count($ois) === 1 ? '' : 's') . '. Which unit are they counted in?',
                array_map(function ($u) {
                    return ['key' => 'unit:' . $u, 'label' => $u];
                }, $unitOptions),
                ['sheet' => $sheetName, 'row_uids' => array_merge(...array_map(function ($oi) use ($outItems) {
                    return $outItems[$oi]['uids'];
                }, $ois))]));
            $noUnitIndex[$sheetName] = $ois;
            $pending = 0;
            foreach ($ois as $oi) {
                $nkItem = (string)$outItems[$oi]['key'];
                $uf = $unitFor[$nkItem] ?? null;
                $own = null;
                if (is_array($uf) && isset($uf['unit'])) {
                    foreach ($unitOptions as $uo) {
                        if (mb_strtolower((string)$uo) === mb_strtolower((string)$uf['unit'])) {
                            $own = (string)$uo;
                            break;
                        }
                    }
                }
                if ($ix !== null && $inferBudget-- > 0) {
                    $sg = import_infer_unit($ix, $outItems[$oi], $unitOptions);
                    $outItems[$oi]['unit_suggestion'] = ['value' => $sg['value'], 'reason' => $sg['reason'], 'confidence' => $sg['confidence'], 'mixed' => $sg['mixed'],
                        'evidence' => $sg['evidence'], 'candidates' => $sg['candidates']];
                }
                if ($own !== null) {
                    $outItems[$oi]['unit'] = $own;
                    $outItems[$oi]['unit_how'] = ($uf['src'] ?? '') === 'suggestion' ? 'suggestion you accepted' : 'chosen by you for this item';
                    $outItems[$oi]['unit_confirmed'] = true;
                } elseif ($xq['answer'] !== null && strpos($xq['answer'], 'unit:') === 0) {
                    $outItems[$oi]['unit'] = substr($xq['answer'], 5);
                    $outItems[$oi]['unit_how'] = 'chosen by you for the whole sheet';
                    $outItems[$oi]['unit_confirmed'] = true;
                } else {
                    $pending++;
                    $outItems[$oi]['open'][] = $xq['id'];
                }
            }
            if ($pending === 0 && $xq['answer'] === null) {
                $xq['answer'] = 'per-item';        // every item was answered on its own: the sheet-wide question has nothing left to ask
            }
            $xq['pending'] = $pending;
            $extra[$xq['qkey']] = $xq;
        }
        // a heading is missing and the sheet's name stands in for the category: say so when this hotel has evidence for a better one (optional)
        if ($ix !== null) {
            foreach (array_keys($outItems) as $oi) {
                $it0 = $outItems[$oi];
                if (!empty($it0['match']) || ($it0['category_how'] ?? '') !== 'sheet-name') {
                    continue;
                }
                $cs = import_infer_category($ix, $it0);
                if ($cs === null) {
                    continue;
                }
                $xq = $ans(import_xq('category-suggest', $it0['key'] . '|' . $cs['value'], '"' . $it0['name'] . '" has no category heading in the file, so the sheet name "' . $it0['category'] . '" would be used. ' . $cs['reason']
                    . ' Use "' . $cs['value'] . '" instead?',
                    [['key' => 'use:' . $cs['value'], 'label' => 'Use "' . $cs['value'] . '"'], ['key' => 'keep', 'label' => 'Keep "' . $it0['category'] . '"']],
                    ['blocking' => false, 'sheet' => $it0['sheet'], 'cells' => $it0['rows'], 'row_uids' => $it0['uids'], 'evidence' => ['reason' => 'category', 'confidence' => $cs['confidence'], 'items' => $cs['evidence']]]));
                $extra[$xq['qkey']] = $xq;
                if ($xq['answer'] === 'use:' . $cs['value']) {
                    $outItems[$oi]['category'] = $cs['value'];
                    $outItems[$oi]['category_how'] = 'suggestion you accepted';
                    $outItems[$oi]['category_confirmed'] = true;
                }
            }
        }
        unset($ix);

        // the same cleaning and length limits as the manual Add form; over-long = blocked with a plain reason (skip is the way out)
        foreach (array_keys($outItems) as $oi) {          // by key: a foreach over the array itself would keep a second copy of every item while they are rewritten
            $it = $outItems[$oi];
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
            // a count answered "in shots" that only waits for "how many shots in one bottle?": its bottle / shot sizes can be typed in step 3 (which
            // then gives N), so the sizes list shows it (never tickable for the menu until it is ready)
            $oi['size_wait'] = false;
            if ($st === 'needs-answer' && !empty($oi['count_in_wait']) && (int)$oi['row_status'] === 0 && empty($oi['unanswered'])) {
                $only = true;
                foreach ($oi['open'] as $xid) {
                    $x = $extraById[$xid] ?? null;
                    if ($x && $x['answer'] === null && $x['type'] !== 'measure-n') {
                        $only = false;
                    }
                }
                $oi['size_wait'] = $only;
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
            $why = $counts['blocked'] > 0 ? 'Some rows are blocked: fix the cell in your sheet and upload again, or skip them (use "Skip this row" on a row, or "Continue without them" in this step).'
                : ($counts['needs-answer'] > 0 || $openSheetQs ? 'Some questions still need an answer. You can answer them, or leave those rows out with "Continue without them".' : 'There is nothing ready to import.');
        }
        // Fixed for you: format-only repairs, each with the cell it came from. Taken back ones (the person's undo) stay listed so they can be redone.
        $heals = array_merge(array_values((array)($analysis['heals'] ?? [])), $resolveHeals);
        $healCounts = (array)($analysis['heal_totals'] ?? []);
        if (!$healCounts) {
            foreach ($heals as $h0) {
                if ($h0['rule'] !== 'unit-spelling') {
                    $healCounts[$h0['rule']] = ($healCounts[$h0['rule']] ?? 0) + 1;
                }
            }
        }
        if ($resolveHealN > 0) {
            $healCounts['unit-spelling'] = $resolveHealN;
        }
        $healsUndone = [];
        foreach ($healUndo as $hid => $meta) {
            if (is_array($meta)) {
                $healsUndone[] = ['id' => (string)$hid, 'undone' => true] + $meta;
            }
        }
        return [
            'items' => $outItems, 'rows' => $rowsOut, 'extra_questions' => array_values($extra), 'counts' => $counts, 'by_sheet' => $bySheet,
            'open_sheet_questions' => $openSheetQs, 'can_confirm' => $canConfirm, 'why_not' => $why, 'committable_items' => $committable,
            'questions' => $analysis['questions'], 'no_unit_index' => $noUnitIndex ?? [],
            'heals' => array_slice($heals, 0, 3000), 'heal_counts' => $healCounts, 'heals_undone' => $healsUndone,
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
                implode('; ', $b), implode(', ', $it['rows']),
                trim((!empty($it['qty_words']['text']) && $it['qty_words']['text'] !== $it['qty_words']['exact'] ? 'Opening balance ' . $it['qty_words']['text'] . ' (exactly ' . $it['qty_words']['exact'] . '). ' : '') . implode(' ', $it['warnings'])),
            ]));
        }
        rewind($fh);
        return (string)stream_get_contents($fh);
    }
}
