<?php
/**
 * "When was this counted?" for a stock import (critical patch 2026-11-25).
 *
 * A stock sheet is a physical count taken at a moment T. Sales, receipts and wastage between T and the import are already
 * in stock_ingredients.current_quantity, so the file is compared with the stock AT T, never with the stock now:
 *   stock at T = current_quantity - SUM(stock_adjustments.quantity_change WHERE created_at > T AND created_at <= NOW())
 * (every stock write path records a stock_adjustments row; the unit-change path does not, so a T before an item's unit change
 * is refused). The count the import files carries snapshot_at = T and counted_at = T on every line, so the approval's own
 * movement window (stock-count.php) starts at T as well.
 *
 * T lives on the job: answers_json['_counted_at'] = ['at' => 'Y-m-d H:i:s', 'by' => user id, 'set_at' => when it was typed].
 * There is no default: until a person gives it the job cannot be imported.
 *
 * The kind of count (owner 2026-11-25): once an approved opening count exists, an import files a REGULAR stock count.
 *
 *   import_count_time_parse($raw)                     'Y-m-d H:i:s' or null (accepts the browser's 'Y-m-d\TH:i')
 *   import_count_time_get($answers)                   the stored T or null
 *   import_count_kind($pdo, $lock)                    'opening' | 'regular'
 *   import_count_term($kind)                          'opening count' | 'stock count' (the one word every screen uses)
 *   import_count_time_problem($pdo, $T, $ids, $lock)  '' when T can be used for these items, else a plain sentence
 *   import_count_movements_since($pdo, $ids, $T, $lock)  ingredient id => net movement after T
 *   import_count_ctx_at($pdo, $ctx, $answers)          the resolver context with current_quantity = stock at T (qty_now = the stock now)
 */

if (!function_exists('import_count_time_parse')) {
    function import_count_time_parse($raw): ?string
    {
        $s = trim(str_replace('T', ' ', (string)$raw));
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $m) !== 1) {
            return null;
        }
        [$y, $mo, $d, $h, $mi, $se] = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5], isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0];
        if ($y < 2000 || $y > 2100 || !checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $se > 59) {
            return null;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $se);
    }

    function import_count_time_get(array $answers): ?string
    {
        $c = $answers['_counted_at'] ?? null;
        return is_array($c) ? import_count_time_parse((string)($c['at'] ?? '')) : null;
    }

    /** 'regular' once an approved opening count exists, else 'opening'. $lock = a locking read (inside the commit). */
    function import_count_kind(PDO $pdo, bool $lock = false): string
    {
        try {
            $q = $pdo->query("SELECT id FROM stock_counts WHERE is_opening = 1 AND status = 'approved' LIMIT 1" . ($lock ? ' LOCK IN SHARE MODE' : ''));
            return $q->fetchColumn() !== false ? 'regular' : 'opening';
        } catch (Throwable $e) {
            return 'opening';            // no is_opening column yet = no opening count was ever approved
        }
    }

    function import_count_term(string $kind): string
    {
        return $kind === 'regular' ? 'stock count' : 'opening count';
    }

    function import_count_fmt(string $ts): string
    {
        $t = strtotime($ts);
        return $t ? date('j M Y H:i', $t) : $ts;
    }

    /** Net stock movement after $T (up to now) per ingredient. $lock = LOCK IN SHARE MODE (sees rows committed since the snapshot). */
    function import_count_movements_since(PDO $pdo, array $ids, string $T, bool $lock = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $q = $pdo->prepare("SELECT ingredient_id, COALESCE(SUM(quantity_change), 0) FROM stock_adjustments
                                WHERE ingredient_id IN ($ph) AND created_at > ? AND created_at <= NOW() GROUP BY ingredient_id" . ($lock ? ' LOCK IN SHARE MODE' : ''));
            $q->execute(array_merge($chunk, [$T]));
            foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
                $out[(int)$r[0]] = (float)$r[1];
            }
        }
        return $out;
    }

    /**
     * '' when $T can be used as the count time of these existing items, else the reason in plain words.
     * Checks: given; a real date and time; not in the future (the database clock); the count lines can record it; no unit change
     * of one of the items after T (its history before is in the old unit); no count of one of the items approved after T
     * (the same rule as the stale-count guard of Stock Count approval: approved_at > the line's count time, posted lines only).
     */
    function import_count_time_problem(PDO $pdo, ?string $T, array $ids, bool $lock = false): string
    {
        if ($T === null || $T === '') {
            return 'Enter when the stock in this file was counted (date and time). It is needed so sales and deliveries since then are not lost.';
        }
        $now = (string)$pdo->query('SELECT NOW()')->fetchColumn();
        if ($T > $now) {
            return 'The count time ' . import_count_fmt($T) . ' is in the future. Enter the date and time the stock was really counted.';
        }
        $hasCountedAt = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_count_lines' AND COLUMN_NAME = 'counted_at'")->fetchColumn() > 0;
        if (!$hasCountedAt) {
            return 'Stock counts on this system cannot record when an item was counted yet, so a count from the file cannot be imported. Ask your administrator to run the database updates.';
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return '';
        }
        $sfx = $lock ? ' LOCK IN SHARE MODE' : '';
        $hasUc = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_unit_changes'")->fetchColumn() > 0;
        foreach (array_chunk($ids, 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            if ($hasUc) {
                $q = $pdo->prepare("SELECT c.changed_at, i.name FROM stock_unit_changes c INNER JOIN stock_ingredients i ON i.id = c.ingredient_id
                                    WHERE c.ingredient_id IN ($ph) AND c.changed_at > ? ORDER BY c.changed_at DESC LIMIT 1" . $sfx);
                $q->execute(array_merge($chunk, [$T]));
                $u = $q->fetch(PDO::FETCH_ASSOC);
                if ($u) {
                    return 'The unit of "' . (string)$u['name'] . '" was changed on ' . import_count_fmt((string)$u['changed_at']) . ', after the count time you gave ('
                        . import_count_fmt($T) . '), so its stock at that time is not known in the new unit. Count it again and enter a time after the change, or untick its quantity.';
                }
            }
            $q = $pdo->prepare("SELECT sc.reference, sc.approved_at, i.name FROM stock_counts sc
                                INNER JOIN stock_count_lines l ON l.count_id = sc.id AND l.adjustment_id IS NOT NULL
                                INNER JOIN stock_ingredients i ON i.id = l.ingredient_id
                                WHERE sc.status = 'approved' AND sc.approved_at > ? AND l.ingredient_id IN ($ph)
                                ORDER BY sc.approved_at DESC LIMIT 1" . $sfx);
            $q->execute(array_merge([$T], $chunk));
            $a = $q->fetch(PDO::FETCH_ASSOC);
            if ($a) {
                $ref = (string)$a['reference'];
                $ref = function_exists('displayCountReference') ? displayCountReference($ref) : $ref;
                return 'Stock count ' . $ref . ' was approved on ' . import_count_fmt((string)$a['approved_at']) . ', after the count time you gave (' . import_count_fmt($T)
                    . '), and it covers "' . (string)$a['name'] . '". A count taken before that approval would add the same stock twice. Enter a later count time (count again if needed), or untick the quantity of the items in that count.';
            }
        }
        return '';
    }

    /**
     * The resolver context seen at the count time: each existing item's current_quantity becomes its stock at T (qty_now keeps the
     * stock now, for the on-hand warnings). Without a usable T (none given, unreadable, in the future) the stock now is used, and the
     * job cannot be imported anyway. Also sets count_kind / count_term / counted_at for the wording.
     */
    function import_count_ctx_at(PDO $pdo, array $ctx, array $answers): array
    {
        $kind = import_count_kind($pdo);
        $ctx['count_kind'] = $kind;
        $ctx['count_term'] = import_count_term($kind);
        $T = import_count_time_get($answers);
        $ctx['counted_at'] = $T;
        $existing = (array)($ctx['existing'] ?? []);
        foreach ($existing as &$e) {
            $e['qty_now'] = (float)$e['current_quantity'];
        }
        unset($e);
        if ($T !== null && $existing && $T <= (string)$pdo->query('SELECT NOW()')->fetchColumn()) {
            $mv = import_count_movements_since($pdo, array_column($existing, 'id'), $T);
            foreach ($existing as &$e) {
                if (isset($mv[(int)$e['id']])) {
                    $e['current_quantity'] = round((float)$e['current_quantity'] - $mv[(int)$e['id']], 6);
                }
            }
            unset($e);
        }
        $ctx['existing'] = $existing;
        return $ctx;
    }

    /**
     * Save the count time a person typed (Step 1 with the file, or Step 4). Only a readable time that is not in the future is kept;
     * the item checks run on the review and again at commit. Returns ['ok' => bool, 'error' => string, 'at' => ?string].
     */
    function import_count_time_set(PDO $pdo, int $jobId, string $raw, int $userId): array
    {
        $T = import_count_time_parse($raw);
        if ($T === null) {
            return ['ok' => false, 'error' => trim($raw) === '' ? import_count_time_problem($pdo, null, []) : 'That is not a date and time that can be read. Pick the date and the time the stock was counted.', 'at' => null];
        }
        $p = import_count_time_problem($pdo, $T, []);
        if ($p !== '') {
            return ['ok' => false, 'error' => $p, 'at' => null];
        }
        $r = import_cont_save($pdo, $jobId, '', function (array &$a) use ($T, $userId) {
            $a['_counted_at'] = ['at' => $T, 'by' => $userId, 'set_at' => date('Y-m-d H:i:s')];
            return [];
        });
        return ['ok' => !empty($r['ok']), 'error' => (string)($r['error'] ?? ''), 'at' => !empty($r['ok']) ? $T : null];
    }

    /** What the review shows about the count time: the stored time, the kind of count, and the reason it cannot be used yet ('' = fine). */
    function import_count_time_view(PDO $pdo, array $state, array $res): array
    {
        $T = import_count_time_get((array)$state['answers']);
        $ids = [];
        foreach ((array)($res['items'] ?? []) as $it) {
            if (!empty($it['in_count']) && !empty($it['match']) && ($it['status'] ?? '') === 'ready') {
                $ids[] = (int)$it['match']['id'];
            }
        }
        $kind = (string)($state['ctx']['count_kind'] ?? import_count_kind($pdo));
        $now = (string)$pdo->query('SELECT NOW()')->fetchColumn();
        return ['at' => $T, 'input' => $T !== null ? str_replace(' ', 'T', substr($T, 0, 16)) : '', 'shown' => $T !== null ? import_count_fmt($T) : '',
            'max' => str_replace(' ', 'T', substr($now, 0, 16)), 'kind' => $kind, 'term' => import_count_term($kind),
            'problem' => import_count_time_problem($pdo, $T, $ids)];
    }
}
