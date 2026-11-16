<?php
/**
 * Stock > Fix crate prices (owner 2026-11-17).
 *
 * An earlier import stored the price of a whole crate / case as the cost of ONE bottle or can. This corrects the items already
 * stored that way: cost per unit = the weighted cost of the stock held after the change (old cost / N when no batch holds stock; 4 dp), pack = N, and each batch of the item that
 * still holds stock and is ticked "Divide this batch" is divided by N too (that is the FIFO cost future sales use; a batch whose
 * cost is not the item's cost starts unticked). Lines of open purchase orders still to receive at the old cost are divided too
 * (2026-11-17 patch; rules at rh_scf_compute()).
 *
 * Unchanged on purpose: the unit, quantities, par / reorder / min, pieces per unit and recipe quantities.
 * ASSUMPTION (owner 2026-11-17, reviewed by the accounting lead after): history is NOT rewritten. Approved count lines,
 * stock_adjustments.cost_at_time, stock_in_log and fully used batches keep their numbers; the correction itself is one
 * admin_activity_log row per item (action 'stock_cost_correction': old -> new cost, N, pack, batches, value before -> after).
 *
 * Used by admin/stock-cost-fix.php; tested by scripts/tests/stock_cost_fix_test.php.
 */

if (!function_exists('rh_scf_apply')) {

    /** Costs are DECIMAL(12,4): two costs closer than half the last digit are the same stored cost. */
    if (!defined('RH_SCF_COST_TOL')) {
        define('RH_SCF_COST_TOL', 0.00005);
    }
    if (!defined('RH_SCF_MAX_PACK')) {
        define('RH_SCF_MAX_PACK', 99999);
    }
    if (!defined('RH_SCF_MAX_LINES')) {
        define('RH_SCF_MAX_LINES', 2000);
    }

    /** Units in one crate/case: a whole number from 2 to 99999 ("24", "24.0"), else null. */
    function rh_scf_parse_n($raw): ?int
    {
        if (is_int($raw)) {
            $v = $raw;
        } else {
            $s = str_replace([',', ' '], '', trim((string)$raw));
            if (!preg_match('/^\d{1,6}(\.0+)?$/', $s)) {
                return null;
            }
            $v = (int)$s;
        }
        return ($v >= 2 && $v <= RH_SCF_MAX_PACK) ? $v : null;
    }

    /** Pack name: cleaned, "crate" when blank, null when longer than the 30-character column. */
    function rh_scf_clean_pack($raw): ?string
    {
        $v = function_exists('rh_clean_text') ? rh_clean_text($raw) : trim(preg_replace('/\s+/u', ' ', (string)$raw) ?? '');
        if ($v === '') {
            return 'crate';
        }
        return mb_strlen($v) > 30 ? null : $v;
    }

    /** Item name for matching: outer spaces (also non-breaking) off, inner runs of spaces as one, lower case. */
    function rh_scf_norm_name(string $s): string
    {
        $s = preg_replace('/[\s\x{00A0}]+/u', ' ', $s) ?? $s;
        return mb_strtolower(trim($s));
    }

    /** Money / cost for staff: 2 decimals, up to 4 when the cost has them. */
    function rh_scf_fmt($v): string
    {
        $v = (float)$v;
        $s = number_format($v, 4, '.', ',');
        $s = rtrim($s, '0');
        $dot = strpos($s, '.');
        $dec = $dot === false ? 0 : strlen($s) - $dot - 1;
        return $dec < 2 ? number_format($v, 2, '.', ',') : $s;
    }

    /** Every active item that has a cost (the grid). */
    function rh_scf_grid_items(PDO $pdo): array
    {
        return $pdo->query("SELECT id, name, category, unit, current_quantity, cost_per_unit, pack_name, pack_size, is_archived
                              FROM stock_ingredients
                             WHERE is_archived = 0 AND cost_per_unit > 0
                             ORDER BY name, id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Normalise posted lines: [['id' => .., 'n' => .., 'pack' => .., 'old' => ..], ...] -> id => line (blank N dropped).
     * Returns [lines, errors]; an error names the item id only (the caller names the item when it can).
     */
    function rh_scf_lines_from_json(string $json, bool $withOld): array
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            return [[], $json === '' || $json === '[]' ? [] : ['The list could not be read. Please reload the page.']];
        }
        $lines = [];
        $errors = [];
        foreach ($raw as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = (int)($r['id'] ?? 0);
            $n = trim((string)($r['n'] ?? ''));
            if ($id <= 0 || $n === '') {
                continue;
            }
            if (isset($lines[$id])) {
                $errors[] = 'Item #' . $id . ' is in the list twice.';
                continue;
            }
            $line = ['id' => $id, 'n' => $n, 'pack' => (string)($r['pack'] ?? '')];
            if ($withOld) {
                $line['old'] = (string)($r['old'] ?? '');
                // What the preview showed: every batch holding stock [id, cost] and every PO line it divides [id, cost, ordered, received].
                // Missing (a page opened before this existed) stays null and is refused: never divide what was not shown.
                $line['bseen'] = null;
                if (isset($r['b']) && is_array($r['b'])) {
                    $line['bseen'] = [];
                    foreach ($r['b'] as $b) {
                        if (is_array($b) && (int)($b[0] ?? 0) > 0 && is_numeric($b[1] ?? null)) {
                            $line['bseen'][(int)$b[0]] = (string)$b[1];
                        }
                    }
                }
                $line['poseen'] = null;
                if (isset($r['po']) && is_array($r['po'])) {
                    $line['poseen'] = [];
                    foreach ($r['po'] as $p) {
                        if (is_array($p) && (int)($p[0] ?? 0) > 0) {
                            $line['poseen'][(int)$p[0]] = implode('|', array_map('strval', array_slice($p, 1, 3)));
                        }
                    }
                }
                $line['divide'] = [];
            }
            $lines[$id] = $line;
        }
        if (count($lines) > RH_SCF_MAX_LINES) {
            $errors[] = 'Too many items at once (' . RH_SCF_MAX_LINES . ' at most).';
        }
        return [$lines, $errors];
    }

    /**
     * The "Divide this batch" tick boxes: values "itemId:batchId" (unticked boxes are not posted). Adds 'divide' => [batch ids]
     * to each line. Returns [lines, errors]; a box for an item not in the list is refused.
     */
    function rh_scf_attach_divide(array $lines, $posted): array
    {
        $errors = [];
        foreach ($lines as $id => $l) {
            $lines[$id]['divide'] = [];
        }
        if ($posted === null || $posted === '') {
            return [$lines, $errors];
        }
        if (!is_array($posted)) {
            return [$lines, ['The batch choice could not be read. Preview again.']];
        }
        foreach ($posted as $v) {
            if (!is_string($v) || !preg_match('/^(\d{1,10}):(\d{1,10})$/', $v, $m)) {
                $errors[] = 'The batch choice could not be read. Preview again.';
                continue;
            }
            $item = (int)$m[1];
            $batch = (int)$m[2];
            if (!isset($lines[$item])) {
                $errors[] = 'Batch #' . $batch . ' was ticked for item #' . $item . ', which is not in this correction. Preview again.';
                continue;
            }
            if (!in_array($batch, $lines[$item]['divide'], true)) {
                $lines[$item]['divide'][] = $batch;
            }
        }
        return [$lines, array_values(array_unique($errors))];
    }

    /** Quantity for staff: up to 3 decimals, no trailing zeros. */
    function rh_scf_qty($q): string
    {
        $s = rtrim(rtrim(number_format((float)$q, 3, '.', ','), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    /** "48 bottles": the stock unit worded for the number (short codes such as kg left alone). */
    function rh_scf_qty_unit($q, string $unit): string
    {
        $u = trim($unit);
        if (function_exists('rh_unit_label')) {
            $u = rh_unit_label($u, (float)$q);
        } elseif ($u !== '' && abs((float)$q - 1.0) >= 0.0005 && preg_match('/^[a-z]{4,}$/i', $u) && !preg_match('/s$/i', $u)) {
            $u .= 's';
        }
        return rh_scf_qty($q) . ($u !== '' ? ' ' . $u : '');
    }

    /** What Confirm posts back (the preview it was shown): one entry per item, see rh_scf_lines_from_json(.., true). */
    function rh_scf_apply_payload(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $b = [];
            foreach ($r['batches'] as $x) {
                $b[] = [$x['id'], sprintf('%.4F', $x['old'])];
            }
            $po = [];
            foreach ($r['po_lines'] as $p) {
                if ($p['kind'] === 'divide') {
                    $po[] = [$p['id'], sprintf('%.4F', $p['old_cost']), sprintf('%.6F', $p['ordered']), sprintf('%.6F', $p['received'])];
                }
            }
            $out[] = ['id' => $r['id'], 'n' => (string)$r['n'], 'pack' => $r['pack'], 'old' => sprintf('%.4F', $r['old_cost']), 'b' => $b, 'po' => $po];
        }
        return $out;
    }

    /**
     * Work out the correction for $lines (id => ['n','pack'(,'old')]). $lock = FOR UPDATE (inside the caller's transaction).
     * Returns ['rows' => [...], 'errors' => [...], 'before' => total value, 'after' => total value, 'pos' => po id => PO total change]. A row:
     * id, name, unit, category, qty, old_cost, new_cost, n, pack, old_value, new_value,
     * batches => [id, number, remaining, old, divided (old / N), new (what it will be), differs, default, divide, reason],
     * held_cost (weighted cost of the stock held after) + held_mixed (a batch is left as it is),
     * po_lines => [id, po_id, ref, status, ordered, received, outstanding, order_in, old_cost, new_cost, old_total, new_total, kind].
     *
     * Batches: in Preview a batch is ticked ("Divide this batch") when its cost is at or above the item's cost less 5% (owner 2026-11-17:
     * the item cost is a weighted average, so a crate batch sits above it); clearly below = unticked. On Confirm ($checkOld) the ticked ids come in $line['divide'] and
     * $line['bseen'] holds every batch the preview showed with its cost: a batch not of this item / not holding stock, a batch
     * that arrived or changed since the preview, or a missing list, refuses the item.
     *
     * Purchase orders (stock_purchase_order_items: ordered_qty, unit_cost and line_total are ALWAYS in the stock unit; order_in
     * only remembers how the line was typed). Lines on a draft / sent / partial order with something still to receive:
     *  - unit_cost within 5% of a crate-level cost (a batch that starts ticked, else the item's cost) -> 'divide' (unit_cost / N, line total again);
     *  - else order_in 'pack' -> 'pack' (ordered by the crate, unchanged); no price -> 'noprice'; else 'perunit' (unchanged).
     * A 'divide' line already partly received refuses the item: one line has one price, and dividing it would reprice what
     * was received. Received, closed and cancelled orders are never read.
     */
    function rh_scf_compute(PDO $pdo, array $lines, bool $lock, bool $checkOld): array
    {
        $out = ['rows' => [], 'errors' => [], 'before' => 0.0, 'after' => 0.0, 'pos' => []];
        if (!$lines) {
            return $out;
        }
        $ids = array_map('intval', array_keys($lines));
        sort($ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $forUpdate = $lock ? ' FOR UPDATE' : '';
        $st = $pdo->prepare("SELECT id, name, category, unit, current_quantity, cost_per_unit, is_archived
                               FROM stock_ingredients WHERE id IN ($ph) ORDER BY id" . $forUpdate);
        $st->execute($ids);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[(int)$r['id']] = $r;
        }
        $bst = $pdo->prepare("SELECT id, ingredient_id, batch_number, quantity_remaining, cost_per_unit
                                FROM stock_batches WHERE ingredient_id IN ($ph) AND quantity_remaining > 0
                               ORDER BY ingredient_id, id" . $forUpdate);
        $bst->execute($ids);
        $batches = [];
        foreach ($bst->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $batches[(int)$b['ingredient_id']][] = $b;
        }
        // A count not yet approved keeps its own cost per line, and approving it later would bring the old cost back
        // (stock-count.php approval uses the line's cost; a rejected count can be reopened). Such items are refused.
        $openCounts = [];
        $ost = $pdo->prepare("SELECT l.ingredient_id, sc.reference, sc.status
                                FROM stock_count_lines l JOIN stock_counts sc ON sc.id = l.count_id
                               WHERE sc.status <> 'approved' AND l.ingredient_id IN ($ph)
                               ORDER BY l.ingredient_id, sc.id");
        $ost->execute($ids);
        foreach ($ost->fetchAll(PDO::FETCH_ASSOC) as $oc) {
            $openCounts[(int)$oc['ingredient_id']][] = (string)$oc['reference'] . ' (' . (string)$oc['status'] . ')';
        }
        // Open purchase-order lines (and their order, locked with them on Confirm).
        $poLines = [];
        $pst = $pdo->prepare("SELECT poi.id, poi.purchase_order_id, poi.ingredient_id, poi.ordered_qty, poi.received_qty, poi.unit_cost,
                                     poi.line_total, poi.order_in, poi.pack_size_snapshot, poi.pack_name_snapshot,
                                     po.reference, po.status, po.total_cost
                                FROM stock_purchase_order_items poi
                                JOIN stock_purchase_orders po ON po.id = poi.purchase_order_id
                               WHERE poi.ingredient_id IN ($ph) AND po.status IN ('draft','sent','partial')
                                 AND poi.ordered_qty - poi.received_qty > 0.0001
                               ORDER BY poi.ingredient_id, po.id, poi.id" . $forUpdate);
        $pst->execute($ids);
        foreach ($pst->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $poLines[(int)$p['ingredient_id']][] = $p;
        }
        $poDelta = [];   // po id => sum of line total changes over every item in this correction
        $poInfo = [];    // po id => [ref, status, old total (stored)]

        foreach ($ids as $id) {
            $line = $lines[$id];
            $it = $items[$id] ?? null;
            if ($it === null || (int)$it['is_archived'] !== 0) {
                $out['errors'][] = 'Item #' . $id . ' is no longer an active stock item.';
                continue;
            }
            $name = (string)$it['name'];
            if (!empty($openCounts[$id])) {
                $out['errors'][] = $name . ': it is on stock count ' . implode(', ', array_unique($openCounts[$id]))
                    . ' that still carries the old cost. Approve or delete that count first, then Preview again.';
                continue;
            }
            $n = rh_scf_parse_n($line['n']);
            if ($n === null) {
                $out['errors'][] = $name . ': units in one crate/case must be a whole number of 2 or more.';
                continue;
            }
            $pack = rh_scf_clean_pack($line['pack']);
            if ($pack === null) {
                $out['errors'][] = $name . ': the pack name is too long (30 characters at most).';
                continue;
            }
            $old = (float)$it['cost_per_unit'];
            if ($old < RH_SCF_COST_TOL) {
                $out['errors'][] = $name . ': it has no cost to correct.';
                continue;
            }
            if ($checkOld) {
                $seen = trim((string)($line['old'] ?? ''));
                if ($seen === '' || !is_numeric($seen)) {
                    $out['errors'][] = $name . ': the previewed cost is missing. Preview again.';
                    continue;
                }
                if (abs($old - (float)$seen) >= RH_SCF_COST_TOL) {
                    $out['errors'][] = $name . ': its cost changed since the preview (previewed ' . rh_scf_fmt($seen) . ', now ' . rh_scf_fmt($old)
                        . '). It may already be corrected. Preview again.';
                    continue;
                }
            }
            $divN = round($old / $n, 4);
            // Also refused when rounding leaves the cost as it was: a second apply must always see a changed cost.
            if ($divN < 0.0001 - RH_SCF_COST_TOL || abs($old - $divN) < RH_SCF_COST_TOL) {
                $out['errors'][] = $name . ': the cost is too small to divide by ' . $n . ' (it would become 0).';
                continue;
            }
            $near = function (float $v, float $target): bool {
                return abs($v - $target) <= max(RH_SCF_COST_TOL, 0.05 * $target);
            };
            $qty = (float)$it['current_quantity'];

            // ---- batches holding stock: the tick boxes ----
            $bRows = [];
            $bErr = '';
            $cur = [];
            foreach ($batches[$id] ?? [] as $b) {
                $cur[(int)$b['id']] = $b;
            }
            if ($checkOld) {
                $seen = $line['bseen'] ?? null;
                $chosen = array_map('intval', (array)($line['divide'] ?? []));
                if (!is_array($seen)) {
                    $bErr = $name . ': the batch choice is missing. Preview again.';
                } else {
                    foreach ($chosen as $bid) {
                        if (!isset($cur[$bid])) {
                            $bErr = $name . ': batch #' . $bid . ' is not a batch of this item that still holds stock. Nothing was changed. Preview again.';
                            break;
                        }
                    }
                    if ($bErr === '') {
                        foreach ($cur as $bid => $b) {
                            $label = (string)$b['batch_number'] !== '' ? (string)$b['batch_number'] : ('#' . $bid);
                            if (!array_key_exists($bid, $seen)) {
                                $bErr = $name . ': batch ' . $label . ' was not in the preview (a delivery since then?). Preview again.';
                                break;
                            }
                            if (abs((float)$b['cost_per_unit'] - (float)$seen[$bid]) >= RH_SCF_COST_TOL) {
                                $bErr = $name . ': the cost of batch ' . $label . ' changed since the preview (previewed ' . rh_scf_fmt($seen[$bid])
                                    . ', now ' . rh_scf_fmt($b['cost_per_unit']) . '). Preview again.';
                                break;
                            }
                        }
                    }
                    if ($bErr === '') {
                        foreach (array_keys($seen) as $bid) {
                            if (!isset($cur[$bid])) {
                                $bErr = $name . ': batch #' . $bid . ' no longer holds stock since the preview. Preview again.';
                                break;
                            }
                        }
                    }
                }
            }
            if ($bErr !== '') {
                $out['errors'][] = $bErr;
                continue;
            }
            $heldQty = 0.0;
            $heldVal = 0.0;
            $mixed = false;
            $refs = [];       // crate-level costs: the batches that start ticked (by the rule, not the owner's ticks, so Confirm matches Preview)
            $anyDivided = false;
            foreach ($cur as $bid => $b) {
                $bOld = (float)$b['cost_per_unit'];
                $bDiv = round($bOld / $n, 4);
                // Ticked when at or above the item's cost less 5%. The item's cost is the weighted average of deliveries, so a
                // crate-priced batch sits at or above it; only a batch clearly below it may already be a price for one unit.
                $crate = $bOld >= $old * 0.95 - RH_SCF_COST_TOL;
                $reason = $crate ? '' : 'Not ticked: its cost is well below the item\'s cost, so it may already be a price for one '
                    . (string)$it['unit'] . '. Check it.';
                if ($crate) {
                    $refs[] = $bOld;
                }
                $divide = $checkOld ? in_array($bid, $chosen, true) : $crate;
                $differs = !$crate;
                $mixed = $mixed || !$divide;
                $anyDivided = $anyDivided || $divide;
                $rem = (float)$b['quantity_remaining'];
                $bNew = $divide ? $bDiv : $bOld;
                $heldQty += $rem;
                $heldVal += $rem * $bNew;
                $bRows[] = [
                    'id' => $bid, 'number' => (string)$b['batch_number'], 'remaining' => $rem,
                    'old' => $bOld, 'divided' => $bDiv, 'new' => $bNew,
                    'differs' => $differs, 'default' => !$differs, 'divide' => $divide, 'reason' => $reason,
                ];
            }
            // New item cost: the weighted cost of the stock held after the change (Σ left × batch cost after ÷ Σ left);
            // with no stock held in batches, the old cost ÷ N. One crate-priced batch gives exactly old ÷ N.
            if ($heldQty > 0.0000005) {
                $new = round($heldVal / $heldQty, 4);
                $basis = 'Average of the ' . rh_scf_qty_unit($heldQty, (string)$it['unit']) . ' held after the change, batch by batch.';
            } else {
                $new = $divN;
                $basis = 'Old cost divided by ' . $n . ' (no batch holds stock).';
            }
            if ($new > $old - RH_SCF_COST_TOL) {
                $out['errors'][] = $name . ': ' . ($anyDivided
                    ? 'with these batches the cost would not go down (now ' . rh_scf_fmt($old) . ', after ' . rh_scf_fmt($new) . '). Check the ticks.'
                    : 'no batch is ticked, so its cost would not change. Tick the batches priced for a whole crate, or leave the item out.');
                continue;
            }
            if ($new < 0.0001 - RH_SCF_COST_TOL) {
                $out['errors'][] = $name . ': the cost is too small to divide by ' . $n . ' (it would become 0).';
                continue;
            }
            if (!$refs) {
                $refs[] = $old;
            }

            // ---- open purchase-order lines ----
            $pRows = [];
            $pErr = '';
            foreach ($poLines[$id] ?? [] as $p) {
                $uc = (float)$p['unit_cost'];
                $ordered = (float)$p['ordered_qty'];
                $received = (float)$p['received_qty'];
                // Compared with the crate-level cost (the batches that start ticked, else the item's cost), not the item's average.
                $atCrate = false;
                foreach ($refs as $ref) {
                    $atCrate = $atCrate || $near($uc, $ref);
                }
                $kind = $uc < RH_SCF_COST_TOL ? 'noprice'
                    : ($atCrate ? 'divide' : ((string)$p['order_in'] === 'pack' ? 'pack' : 'perunit'));
                if ($kind === 'divide' && $received > 0.0001) {
                    $pErr = $name . ': ' . (string)$p['reference'] . ' is partly received (' . rh_scf_qty_unit($received, (string)$it['unit'])
                        . ' of ' . rh_scf_qty_unit($ordered, (string)$it['unit']) . ') at the old price. One line has one price, so it cannot be divided '
                        . 'without changing what was received. Receive the rest or close that order first, then Preview again.';
                    break;
                }
                $newUc = $kind === 'divide' ? round($uc / $n, 4) : $uc;
                $oldTotal = (float)$p['line_total'];
                $newTotal = $kind === 'divide' ? round($ordered * $newUc, 2) : $oldTotal;
                $pRows[] = [
                    'id' => (int)$p['id'], 'po_id' => (int)$p['purchase_order_id'], 'ref' => (string)$p['reference'], 'status' => (string)$p['status'],
                    'ordered' => $ordered, 'received' => $received, 'outstanding' => $ordered - $received, 'order_in' => (string)$p['order_in'],
                    'pack_size' => $p['pack_size_snapshot'] !== null ? (float)$p['pack_size_snapshot'] : null, 'pack_name' => (string)($p['pack_name_snapshot'] ?? ''),
                    'old_cost' => $uc, 'new_cost' => $newUc, 'old_total' => $oldTotal, 'new_total' => $newTotal, 'kind' => $kind,
                ];
            }
            if ($pErr === '' && $checkOld) {
                $seenPo = $line['poseen'] ?? null;
                $nowPo = [];
                foreach ($pRows as $pr) {
                    if ($pr['kind'] === 'divide') {
                        $nowPo[$pr['id']] = sprintf('%.4F', $pr['old_cost']) . '|' . sprintf('%.6F', $pr['ordered']) . '|' . sprintf('%.6F', $pr['received']);
                    }
                }
                if (!is_array($seenPo)) {
                    $pErr = $name . ': the purchase order check is missing. Preview again.';
                } else {
                    ksort($seenPo);
                    ksort($nowPo);
                    $norm = function (array $a): array {
                        $o = [];
                        foreach ($a as $k => $v) {
                            $parts = explode('|', (string)$v);
                            $o[(int)$k] = sprintf('%.4F', (float)($parts[0] ?? 0)) . '|' . sprintf('%.6F', (float)($parts[1] ?? 0)) . '|' . sprintf('%.6F', (float)($parts[2] ?? 0));
                        }
                        return $o;
                    };
                    if ($norm($seenPo) !== $nowPo) {
                        $pErr = $name . ': its open purchase order lines changed since the preview. Preview again.';
                    }
                }
            }
            if ($pErr !== '') {
                $out['errors'][] = $pErr;
                continue;
            }
            foreach ($pRows as $pr) {
                if ($pr['kind'] !== 'divide') {
                    continue;
                }
                $poDelta[$pr['po_id']] = ($poDelta[$pr['po_id']] ?? 0.0) + ($pr['new_total'] - $pr['old_total']);
                $poInfo[$pr['po_id']] = ['ref' => $pr['ref'], 'status' => $pr['status']];
            }

            $row = [
                'id' => $id, 'name' => $name, 'unit' => (string)$it['unit'], 'category' => (string)$it['category'], 'qty' => $qty,
                'old_cost' => $old, 'new_cost' => $new, 'n' => $n, 'pack' => $pack,
                'old_value' => round($qty * $old, 2), 'new_value' => round($qty * $new, 2), 'batches' => $bRows,
                'held_cost' => $new, 'held_mixed' => $mixed, 'basis' => $basis, 'div_n' => $divN, 'held_qty' => $heldQty,
                'po_lines' => $pRows,
            ];
            $out['rows'][] = $row;
            $out['before'] += $row['old_value'];
            $out['after'] += $row['new_value'];
        }
        // PO totals before -> after (the order total is the sum of its line totals, as on the purchase order screen).
        if ($poDelta) {
            $pids = array_keys($poDelta);
            $pph = implode(',', array_fill(0, count($pids), '?'));
            $sst = $pdo->prepare("SELECT po.id, po.total_cost, COALESCE((SELECT SUM(x.line_total) FROM stock_purchase_order_items x WHERE x.purchase_order_id = po.id), 0) AS lines_total
                                    FROM stock_purchase_orders po WHERE po.id IN ($pph)");
            $sst->execute($pids);
            foreach ($sst->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $pid = (int)$s['id'];
                $out['pos'][$pid] = $poInfo[$pid] + [
                    'old_total' => (float)$s['total_cost'], 'new_total' => round((float)$s['lines_total'] + $poDelta[$pid], 2),
                ];
            }
            foreach ($out['rows'] as &$r) {
                foreach ($r['po_lines'] as $pl) {
                    if ($pl['kind'] === 'divide' && isset($out['pos'][$pl['po_id']])) {
                        $r['po_totals'][$pl['po_id']] = $out['pos'][$pl['po_id']];
                    }
                }
            }
            unset($r);
        }
        return $out;
    }

    /** "PO-12: 48 bottles at 41,322.31 → 1,721.7629, line total 1,983,470.88 → 82,644.62" (currency from the caller). */
    function rh_scf_po_line_text(array $pl, string $unit, string $cur = ''): string
    {
        $c = $cur !== '' ? $cur . ' ' : '';
        $txt = $pl['ref'] . ': ' . rh_scf_qty_unit($pl['outstanding'], $unit) . ' at ' . $c . rh_scf_fmt($pl['old_cost']);
        if ($pl['kind'] === 'divide') {
            // The line total is shown too: a hand-typed quantity may have meant crates, and then the new total would be far too low.
            return $txt . ' → ' . $c . rh_scf_fmt($pl['new_cost']) . ', line total ' . $c . number_format((float)$pl['old_total'], 2) . ' → ' . $c . number_format((float)$pl['new_total'], 2);
        }
        $why = ['pack' => 'ordered by the crate, unchanged', 'noprice' => 'no price on the line, unchanged', 'perunit' => 'already a price for one ' . $unit . ', unchanged'];
        return $txt . ' (' . ($why[$pl['kind']] ?? 'unchanged') . ')';
    }

    /** Preview (read-only). $lines from rh_scf_lines_from_json(.., false). */
    function rh_scf_preview(PDO $pdo, array $lines): array
    {
        return rh_scf_compute($pdo, $lines, false, false);
    }

    /** The log line: the important part first (Staff activity shows 140 characters). */
    function rh_scf_log_details(array $row): string
    {
        $bat = [];
        $kept = [];
        foreach ($row['batches'] as $b) {
            if ($b['divide']) {
                $bat[] = '#' . $b['id'] . ' ' . rh_scf_fmt($b['old']) . ' → ' . rh_scf_fmt($b['new']);
            } else {
                $kept[] = '#' . $b['id'] . ' ' . rh_scf_fmt($b['old']);
            }
        }
        $po = [];
        foreach ($row['po_lines'] ?? [] as $pl) {
            if ($pl['kind'] === 'divide') {
                $po[] = $pl['ref'] . ' line #' . $pl['id'] . ' ' . rh_scf_qty_unit($pl['ordered'], $row['unit']) . ' at ' . rh_scf_fmt($pl['old_cost'])
                    . ' → ' . rh_scf_fmt($pl['new_cost']) . ', line total ' . rh_scf_fmt($pl['old_total']) . ' → ' . rh_scf_fmt($pl['new_total']);
            }
        }
        foreach ($row['po_totals'] ?? [] as $pt) {
            $po[] = $pt['ref'] . ' total ' . rh_scf_fmt($pt['old_total']) . ' → ' . rh_scf_fmt($pt['new_total']);
        }
        return $row['name'] . ': cost per ' . $row['unit'] . ' ' . rh_scf_fmt($row['old_cost']) . ' → ' . rh_scf_fmt($row['new_cost'])
            . ' (' . $row['pack'] . ' of ' . $row['n'] . '); value ' . rh_scf_fmt($row['old_value']) . ' → ' . rh_scf_fmt($row['new_value'])
            . '; batches divided: ' . ($bat ? implode(', ', $bat) : 'none')
            . ($kept ? '; batches left as they were: ' . implode(', ', $kept) . '; new cost = average of the stock held' : '')
            . '; purchase order lines: ' . ($po ? implode('; ', $po) : 'none changed')
            . '; item #' . $row['id'] . '. Counts, adjustments, deliveries, received order lines and used batches keep their numbers.';
    }

    /**
     * Apply in ONE transaction, all or nothing. $lines from rh_scf_lines_from_json(.., true): each carries the previewed old cost.
     * Any refused item (stale or second apply, N < 2, archived, ...) refuses the whole set and is named.
     * Returns ['ok' => bool, 'errors' => [...], 'rows' => [...], 'before' => x, 'after' => x].
     */
    function rh_scf_apply(PDO $pdo, array $lines, int $userId, string $username = ''): array
    {
        if (!$lines) {
            return ['ok' => false, 'errors' => ['Nothing to correct.'], 'rows' => [], 'before' => 0.0, 'after' => 0.0];
        }
        if (count($lines) > RH_SCF_MAX_LINES) {
            return ['ok' => false, 'errors' => ['Too many items at once (' . RH_SCF_MAX_LINES . ' at most).'], 'rows' => [], 'before' => 0.0, 'after' => 0.0];
        }
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT rh_scf');
        }
        try {
            $c = rh_scf_compute($pdo, $lines, true, true);
            if ($c['errors'] || !$c['rows']) {
                if ($own) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT rh_scf');
                }
                return ['ok' => false, 'errors' => $c['errors'] ?: ['Nothing to correct.'], 'rows' => [], 'before' => 0.0, 'after' => 0.0];
            }
            $upItem = $pdo->prepare('UPDATE stock_ingredients SET cost_per_unit = ?, pack_name = ?, pack_size = ?, updated_at = NOW() WHERE id = ?');
            $upBatch = $pdo->prepare('UPDATE stock_batches SET cost_per_unit = ? WHERE id = ? AND ingredient_id = ?');
            // A PO line is changed only while nothing of it is received (checked again here, under the lock).
            $upLine = $pdo->prepare('UPDATE stock_purchase_order_items SET unit_cost = ?, line_total = ? WHERE id = ? AND ingredient_id = ? AND received_qty <= 0.0001');
            $upPo = $pdo->prepare('UPDATE stock_purchase_orders po
                                      SET po.subtotal = (SELECT COALESCE(SUM(x.line_total), 0) FROM stock_purchase_order_items x WHERE x.purchase_order_id = po.id),
                                          po.total_cost = (SELECT COALESCE(SUM(y.line_total), 0) FROM stock_purchase_order_items y WHERE y.purchase_order_id = po.id)
                                    WHERE po.id = ?');
            $log = $pdo->prepare('INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            foreach ($c['rows'] as $row) {
                $upItem->execute([sprintf('%.4F', $row['new_cost']), $row['pack'], $row['n'], $row['id']]);
                foreach ($row['batches'] as $b) {
                    if ($b['divide']) {
                        $upBatch->execute([sprintf('%.4F', $b['new']), $b['id'], $row['id']]);
                    }
                }
                foreach ($row['po_lines'] as $pl) {
                    if ($pl['kind'] === 'divide') {
                        $upLine->execute([sprintf('%.4F', $pl['new_cost']), sprintf('%.2F', $pl['new_total']), $pl['id'], $row['id']]);
                        if ($upLine->rowCount() !== 1) {
                            throw new RuntimeException('PO line ' . $pl['id'] . ' was not updated');
                        }
                    }
                }
                // Same row logActivity() writes, inside this transaction so the correction and its record commit together.
                $log->execute([$userId > 0 ? $userId : null, $username !== '' ? mb_substr($username, 0, 100) : null, 'stock_cost_correction',
                    rh_scf_log_details($row), (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
            }
            foreach (array_keys($c['pos']) as $pid) {
                $upPo->execute([$pid]);
            }
            if ($own) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT rh_scf');
            }
        } catch (Throwable $e) {
            if ($own) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT rh_scf');
                } catch (Throwable $e2) {
                }
            }
            error_log('stock cost fix: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['Nothing was changed: the correction could not be saved. Please try again.'], 'rows' => [], 'before' => 0.0, 'after' => 0.0];
        }
        if ($own && function_exists('rh_clear_stock_dashboard_cache')) {
            try {
                rh_clear_stock_dashboard_cache();
            } catch (Throwable $e) {
            }
        }
        return ['ok' => true, 'errors' => [], 'rows' => $c['rows'], 'before' => $c['before'], 'after' => $c['after']];
    }

    /**
     * "Fill from list": the owner's CSV (columns "Item", "Units in one crate/case (FILL IN)", optional "Real per-unit price? (Y ...)").
     * Only prefills; nothing is saved. $items = rows with id + name (the grid). Exact name match, case and outer spaces ignored.
     * Returns ['ok', 'error', 'fill' => id => N, 'unmatched' => [names], 'ambiguous' => [names], 'real' => [names], 'bad' => [names]].
     */
    function rh_scf_csv_prefill(string $data, array $items): array
    {
        $res = ['ok' => false, 'error' => '', 'fill' => [], 'unmatched' => [], 'ambiguous' => [], 'real' => [], 'bad' => []];
        require_once __DIR__ . '/../../includes/import/csv-reader.php';
        if ($data === '') {
            $res['error'] = 'The file is empty.';
            return $res;
        }
        if (strlen($data) > 2 * 1024 * 1024) {
            $res['error'] = 'The file is too large (2 MB at most).';
            return $res;
        }
        if (substr($data, 0, 2) === 'PK') {
            $res['error'] = 'This is an Excel file. Save it as CSV first (File, Save As, CSV).';
            return $res;
        }
        [$text] = import_csv_to_utf8($data);
        $parsed = import_csv_parse($text, import_csv_sniff_delimiter($text, ','), 20000, 60, 2000);
        if (is_string($parsed)) {
            $res['error'] = $parsed;
            return $res;
        }
        $hdr = function ($v): string {
            return mb_strtolower(trim(preg_replace('/[\s\x{00A0}]+/u', ' ', (string)$v) ?? ''));
        };
        $headRow = null;
        $cItem = $cN = $cReal = null;
        foreach (array_slice($parsed, 0, 10, true) as $ri => $cells) {
            $ci = $cn = $cr = null;
            foreach ($cells as $k => $v) {
                $h = $hdr($v);
                if ($h === 'item' && $ci === null) {
                    $ci = $k;
                } elseif ($cn === null && strpos($h, 'units in one crate') === 0) {
                    $cn = $k;
                } elseif ($cr === null && (strpos($h, 'real per-unit price') === 0 || strpos($h, 'real per unit price') === 0)) {
                    $cr = $k;
                }
            }
            if ($ci !== null && $cn !== null) {
                $headRow = $ri;
                $cItem = $ci;
                $cN = $cn;
                $cReal = $cr;
                break;
            }
        }
        if ($headRow === null) {
            $res['error'] = 'The file needs the columns "Item" and "Units in one crate/case (FILL IN)" in its first rows.';
            return $res;
        }
        $byName = [];
        foreach ($items as $it) {
            $byName[rh_scf_norm_name((string)$it['name'])][] = (int)$it['id'];
        }
        foreach ($parsed as $ri => $cells) {
            if ($ri <= $headRow) {
                continue;
            }
            $name = trim((string)($cells[$cItem] ?? ''));
            if ($name === '') {
                continue;
            }
            $nRaw = trim((string)($cells[$cN] ?? ''));
            if ($cReal !== null && preg_match('/^\s*y/i', (string)($cells[$cReal] ?? ''))) {
                $res['real'][] = $name;
                continue;
            }
            if ($nRaw === '') {
                continue;
            }
            $key = rh_scf_norm_name($name);
            if (!isset($byName[$key])) {
                $res['unmatched'][] = $name;
                continue;
            }
            if (count($byName[$key]) > 1) {
                $res['ambiguous'][] = $name;
                continue;
            }
            $n = rh_scf_parse_n($nRaw);
            if ($n === null) {
                $res['bad'][] = $name;
                continue;
            }
            $res['fill'][$byName[$key][0]] = $n;
        }
        foreach (['unmatched', 'ambiguous', 'real', 'bad'] as $k) {
            $res[$k] = array_values(array_unique($res[$k]));
        }
        $res['ok'] = true;
        return $res;
    }
}
