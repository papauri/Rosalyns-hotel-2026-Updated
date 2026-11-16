<?php
/**
 * Stock > Fix crate prices (owner 2026-11-17).
 *
 * An earlier import stored the price of a whole crate / case as the cost of ONE bottle or can. This corrects the items already
 * stored that way: cost per unit = old cost / N (rounded to 4 dp, the same as Import), pack = N, and every batch of the item that
 * still holds stock is divided by N too (that is the FIFO cost future sales use).
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
            }
            $lines[$id] = $line;
        }
        if (count($lines) > RH_SCF_MAX_LINES) {
            $errors[] = 'Too many items at once (' . RH_SCF_MAX_LINES . ' at most).';
        }
        return [$lines, $errors];
    }

    /**
     * Work out the correction for $lines (id => ['n','pack'(,'old')]). $lock = FOR UPDATE (inside the caller's transaction).
     * Returns ['rows' => [...], 'errors' => [...], 'before' => total value, 'after' => total value]. A row:
     * id, name, unit, category, qty, old_cost, new_cost, n, pack, old_value, new_value, batches => [id, number, remaining, old, new, differs].
     */
    function rh_scf_compute(PDO $pdo, array $lines, bool $lock, bool $checkOld): array
    {
        $out = ['rows' => [], 'errors' => [], 'before' => 0.0, 'after' => 0.0];
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
            $new = round($old / $n, 4);
            // Also refused when rounding leaves the cost as it was: a second apply must always see a changed cost.
            if ($new < 0.0001 - RH_SCF_COST_TOL || abs($old - $new) < RH_SCF_COST_TOL) {
                $out['errors'][] = $name . ': the cost is too small to divide by ' . $n . ' (it would become 0).';
                continue;
            }
            $qty = (float)$it['current_quantity'];
            $bRows = [];
            foreach ($batches[$id] ?? [] as $b) {
                $bOld = (float)$b['cost_per_unit'];
                $bRows[] = [
                    'id' => (int)$b['id'], 'number' => (string)$b['batch_number'], 'remaining' => (float)$b['quantity_remaining'],
                    'old' => $bOld, 'new' => round($bOld / $n, 4),
                    // A batch not at the item's cost may already be a per-unit price (a later delivery): shown so it is checked.
                    'differs' => abs($bOld - $old) > max(RH_SCF_COST_TOL, 0.05 * $old),
                ];
            }
            $row = [
                'id' => $id, 'name' => $name, 'unit' => (string)$it['unit'], 'category' => (string)$it['category'], 'qty' => $qty,
                'old_cost' => $old, 'new_cost' => $new, 'n' => $n, 'pack' => $pack,
                'old_value' => round($qty * $old, 2), 'new_value' => round($qty * $new, 2), 'batches' => $bRows,
            ];
            $out['rows'][] = $row;
            $out['before'] += $row['old_value'];
            $out['after'] += $row['new_value'];
        }
        return $out;
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
        foreach ($row['batches'] as $b) {
            $bat[] = '#' . $b['id'] . ' ' . rh_scf_fmt($b['old']) . ' → ' . rh_scf_fmt($b['new']);
        }
        return $row['name'] . ': cost per ' . $row['unit'] . ' ' . rh_scf_fmt($row['old_cost']) . ' → ' . rh_scf_fmt($row['new_cost'])
            . ' (' . $row['pack'] . ' of ' . $row['n'] . '); value ' . rh_scf_fmt($row['old_value']) . ' → ' . rh_scf_fmt($row['new_value'])
            . '; batches divided: ' . ($bat ? implode(', ', $bat) : 'none')
            . '; item #' . $row['id'] . '. Counts, adjustments, deliveries and used batches keep their numbers.';
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
            $log = $pdo->prepare('INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            foreach ($c['rows'] as $row) {
                $upItem->execute([sprintf('%.4F', $row['new_cost']), $row['pack'], $row['n'], $row['id']]);
                foreach ($row['batches'] as $b) {
                    $upBatch->execute([sprintf('%.4F', $b['new']), $b['id'], $row['id']]);
                }
                // Same row logActivity() writes, inside this transaction so the correction and its record commit together.
                $log->execute([$userId > 0 ? $userId : null, $username !== '' ? mb_substr($username, 0, 100) : null, 'stock_cost_correction',
                    rh_scf_log_details($row), (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
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
