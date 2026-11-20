<?php

/**
 * Assistant model choices (owner fault 2026-11-20: "the AI settings are able to pick up a new model and tell me about
 * it but won't populate the new top models to pick from and replace the rest").
 *
 * One function works out what the Models table on Assistant Settings offers for a provider, and the refresh notice is
 * written from the same result, so the two always agree. Worked out from the saved ai_models rows, so it survives a
 * reload. Nothing here saves a selection: the models in use are only ever changed by a person clicking Save.
 *
 *  - Offered  = in the provider's latest full list (last_seen_at at or after ai_models_listed_at_<provider>, written by
 *    rh_ai_sync_models in one transaction) and not closed (source 'gone').
 *  - Superseded = an older version of a family that has a newer PRICED version on offer (gemini-3.6-flash once
 *    gemini-3.8-flash is listed with a price). An unpriced newer version never pushes a priced model out.
 *  - Choices = the existing suggestion (rh_ai_suggest_roles: the 3 cheapest that perform, then cheapest Extras, up to
 *    5) over the offered, not superseded models, plus a newer version of a known model that still needs a price.
 *  - In use, not a choice any more: stays chosen, marked "older, still in use" (cost and answers change only when a
 *    person picks another). In use but closed or no longer listed: "retired" - nothing is picked for it; a warning asks
 *    for a replacement.
 */

require_once __DIR__ . '/ai-catalog.php';

/**
 * @return array{rows:list<array>,suggest:array<string,string>,status:array<string,string>,older:list<string>,
 *               retired:list<string>,new_unpriced:list<string>}
 * status per shown model id: top | new_price | older | retired
 */
function rh_ai_model_choices(PDO $db, string $provider): array
{
    $out = ['rows' => [], 'suggest' => [], 'status' => [], 'older' => [], 'retired' => [], 'new_unpriced' => []];
    try {
        $st = $db->prepare("SELECT * FROM ai_models WHERE provider = ? ORDER BY capability_rank DESC, sort ASC, model_id ASC");
        $st->execute([$provider]);
        $all = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $out;
    }
    $all = array_values(array_filter($all, static fn($m) => !rh_ai_model_banned((string)$m['model_id'])));
    if (!$all) {
        return $out;
    }
    $priced = static fn(array $m): bool => $m['price_in_per_mtok'] !== null && $m['price_out_per_mtok'] !== null;
    $fam = static fn(array $m): string => rh_ai_model_family((string)$m['model_id']);
    $ver = static fn(array $m): float => rh_ai_model_version((string)$m['model_id']);

    // When the provider's last full list was saved (rh_ai_sync_models). Unknown (a list saved before this fix): only a
    // model the provider closed counts as not offered, so no false "no longer offered" warning.
    $listedAt = 0;
    try {
        $st = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $st->execute(['ai_models_listed_at_' . $provider]);
        $listedAt = (int)strtotime((string)$st->fetchColumn());
    } catch (Throwable $e) {
        $listedAt = 0;
    }
    $listed = static function (array $m) use ($listedAt): bool {
        if ((string)$m['source'] === 'gone') {
            return false;
        }
        return $listedAt <= 0 || $m['last_seen_at'] === null || (int)strtotime((string)$m['last_seen_at']) >= $listedAt;
    };
    $offered = array_values(array_filter($all, $listed));

    // Newest priced version on offer per family: anything older in that family is superseded.
    $topVer = [];
    foreach ($offered as $m) {
        if ($priced($m)) {
            $f = $fam($m);
            $topVer[$f] = max($topVer[$f] ?? -1.0, $ver($m));
        }
    }
    $cands = array_values(array_filter($offered, static fn($m) => !(isset($topVer[$fam($m)]) && $ver($m) < $topVer[$fam($m)])));
    $ranked = array_map(static fn($m) => [
        'model_id'  => (string)$m['model_id'],
        'rank'      => (float)$m['capability_rank'],
        'price_in'  => $m['price_in_per_mtok'] !== null ? (float)$m['price_in_per_mtok'] : null,
        'price_out' => $m['price_out_per_mtok'] !== null ? (float)$m['price_out_per_mtok'] : null,
    ], $cands);
    $suggest = $ranked ? rh_ai_suggest_roles($ranked) : [];

    // A newer version of a model the built-in list knows, still without a price: offered so its price can be typed.
    $catVer = [];
    foreach (rh_ai_catalog_models($provider) as $c) {
        $f = rh_ai_model_family($c['model_id']);
        $catVer[$f] = max($catVer[$f] ?? -1.0, rh_ai_model_version($c['model_id']));
    }
    $newUnpriced = [];
    foreach ($cands as $m) {
        $id = (string)$m['model_id'];
        if ($priced($m) || isset($suggest[$id]) || (int)$m['is_new'] !== 1) {
            continue;
        }
        $f = $fam($m);
        if (isset($catVer[$f]) && $ver($m) > $catVer[$f] && !rh_ai_model_is_big(['model_id' => $id, 'price_out' => null])) {
            $newUnpriced[] = $id;
        }
    }

    $status = [];
    foreach (array_keys($suggest) as $id) {
        $status[$id] = 'top';
    }
    foreach ($newUnpriced as $id) {
        $status[$id] = 'new_price';
    }
    foreach ($all as $m) {
        if ((int)$m['is_selected'] !== 1) {
            continue;
        }
        $id = (string)$m['model_id'];
        if (!$listed($m)) {
            $status[$id] = 'retired';
            $out['retired'][] = $id;
        } elseif (!isset($status[$id])) {
            $status[$id] = 'older';
            $out['older'][] = $id;
        }
    }
    foreach ($all as $m) {
        $id = (string)$m['model_id'];
        if (isset($status[$id])) {
            $out['rows'][] = $m;
        }
    }
    $out['suggest'] = $suggest;
    $out['status'] = $status;
    $out['new_unpriced'] = $newUnpriced;
    return $out;
}

/**
 * The plain notice after a list check, written from the same choices the table shows.
 * @param int $listed how many chat models the provider listed
 * @param list<string> $newIds ids that were not in the saved list before this check
 */
function rh_ai_model_choices_notice(array $ch, int $listed, array $newIds = []): string
{
    $roles = rh_ai_model_roles();
    $roleName = static fn(string $r): string => isset($roles[$r]) ? ucfirst($r) : 'chosen';
    $choice = [];
    $older = [];
    $retired = [];
    foreach ($ch['rows'] as $m) {
        $id = (string)$m['model_id'];
        $label = (string)$m['label'] !== '' ? (string)$m['label'] : $id;
        $st = $ch['status'][$id] ?? '';
        if ($st === 'top') {
            $choice[] = $label . (in_array($id, $newIds, true) ? ' (new)' : '');
        } elseif ($st === 'new_price') {
            $choice[] = $label . ' (new - type its price before it can be used)';
        } elseif ($st === 'older') {
            $older[] = $label . ' (' . $roleName((string)$m['role']) . ')';
        } elseif ($st === 'retired') {
            $retired[] = $label . ' (' . $roleName((string)$m['role']) . ')';
        }
    }
    $msg = $listed . ' chat model' . ($listed === 1 ? '' : 's') . ' listed.';
    $msg .= $choice ? ' Choices now: ' . implode(', ', $choice) . '.' : ' No model can be suggested yet (no prices known).';
    if ($older) {
        $msg .= ' Older, still in use until someone picks another and saves: ' . implode(', ', $older) . '.';
    }
    if ($retired) {
        $msg .= ' No longer offered by the provider, choose a replacement and save: ' . implode(', ', $retired) . '.';
    }
    $leftOut = count(array_diff($newIds, array_map(static fn($m) => (string)$m['model_id'], $ch['rows'])));
    if ($leftOut > 0) {
        $msg .= ' ' . $leftOut . ' other new listing' . ($leftOut === 1 ? ' is' : 's are') . ' left out (older, big, or not a newer version of a known model).';
    }
    return $msg;
}
