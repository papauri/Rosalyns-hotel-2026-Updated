<?php

/**
 * Assistant model choices (owner faults 2026-11-20: "won't populate the new top models to pick from and replace the
 * rest"; "show me the top 10 best cheapest and fastest and latest models maximum for the current day to pick from").
 *
 * One function works out what the Models table on Assistant Settings offers for a provider, and the refresh notice is
 * written from the same result, so the two always agree. Worked out from the saved ai_models rows, so it survives a
 * reload. Nothing here saves a selection: the models in use are only ever changed by a person clicking Save.
 *
 *  - Offered  = in the provider's latest full list (last_seen_at at or after ai_models_listed_at_<provider>, written by
 *    rh_ai_sync_models in one transaction) and not closed (source 'gone').
 *  - Left out = not a chat model (deep research, antigravity, computer use...), a "-latest" alias of a family that has a
 *    real id on offer, a preview of a version that is also offered as a release, and a superseded older version (a
 *    family with a newer PRICED version on offer; an unpriced newer version never pushes a priced model out).
 *  - Choices  = up to 10 (RH_AI_MODEL_CHOICES_MAX), best first by four equal parts: cheapest (price in + out), fastest
 *    (average reply time measured here, at least 5 replies in 30 days, else the kind: Flash-Lite/Haiku/mini, then
 *    Flash/Sonnet, then the rest), latest (version) and best (capability rank). Models with a price come first; one
 *    with no price only fills a free place and waits for a price. The suggestion (rh_ai_suggest_roles, up to 5) is
 *    always among them.
 *  - In use, not a choice any more: stays chosen, marked "older, still in use" (cost and answers change only when a
 *    person picks another). In use but closed or no longer listed: "retired" - nothing is picked for it; a warning asks
 *    for a replacement.
 */

require_once __DIR__ . '/ai-catalog.php';

const RH_AI_MODEL_CHOICES_MAX = 10;
const RH_AI_SPEED_MIN_REPLIES = 5;

/**
 * Average reply time per model measured on this system (last 30 days, models with enough replies only).
 * @return array<string,float> model id => average milliseconds
 */
function rh_ai_model_speeds(PDO $db, string $provider): array
{
    try {
        $st = $db->prepare("SELECT model_id, AVG(latency_ms) AS ms FROM ai_messages
                            WHERE provider = ? AND model_id IS NOT NULL AND model_id <> '' AND latency_ms > 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                            GROUP BY model_id HAVING COUNT(*) >= ?");
        $st->execute([$provider, RH_AI_SPEED_MIN_REPLIES]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['model_id']] = (float)$r['ms'];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Speed class 3 (quickest) .. 1: from the measured reply time when known, else from the kind of model. */
function rh_ai_model_speed_tier(string $id, ?float $ms): int
{
    if ($ms !== null) {
        return $ms < 4000 ? 3 : ($ms < 10000 ? 2 : 1);
    }
    $id = strtolower($id);
    // Whole words only: "gemini" holds "mini".
    if (preg_match('/(^|[-_.])(lite|nano|haiku|mini|small|instant)([-_.]|$)/', $id)) {
        return 3;
    }
    if (preg_match('/(^|[-_.])(flash|sonnet|fast|turbo|chat)([-_.]|$)/', $id)) {
        return 2;
    }
    return 1;
}

/**
 * @return array{rows:list<array>,suggest:array<string,string>,status:array<string,string>,older:list<string>,
 *               retired:list<string>,new_unpriced:list<string>,badges:array<string,list<string>>,speed_ms:array<string,float>}
 * status per shown model id: top | new_price | older | retired. badges: newest | cheapest | fastest.
 */
function rh_ai_model_choices(PDO $db, string $provider): array
{
    $out = ['rows' => [], 'suggest' => [], 'status' => [], 'older' => [], 'retired' => [], 'new_unpriced' => [], 'badges' => [], 'speed_ms' => []];
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
    $isPreview = static fn(array $m): bool => (bool)preg_match('/-(preview|exp|experimental)\b/i', (string)$m['model_id']);
    $isAlias = static fn(array $m): bool => (bool)preg_match('/-latest$/i', (string)$m['model_id']);

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
    $offered = array_values(array_filter($all, static fn($m) => $listed($m) && rh_ai_is_chat_model((string)$m['model_id'])));

    // Newest priced version on offer per family: anything older in that family is superseded. A release beats a
    // preview of the same version; a "-latest" alias goes when its family has a real id on offer.
    $topVer = [];
    $release = [];
    $realFam = [];
    foreach ($offered as $m) {
        $f = $fam($m);
        if ($priced($m)) {
            $topVer[$f] = max($topVer[$f] ?? -1.0, $ver($m));
        }
        if (!$isPreview($m) && !$isAlias($m)) {
            $release[$f . '|' . $ver($m)] = true;
        }
        if (!$isAlias($m)) {
            $realFam[$f] = true;
        }
    }
    $cands = array_values(array_filter($offered, static function ($m) use ($topVer, $release, $realFam, $fam, $ver, $isPreview, $isAlias): bool {
        $f = $fam($m);
        if ($isAlias($m)) {
            return !isset($realFam[$f]);
        }
        if (isset($topVer[$f]) && $ver($m) < $topVer[$f]) {
            return false;
        }
        return !($isPreview($m) && isset($release[$f . '|' . $ver($m)]));
    }));
    $ranked = array_map(static fn($m) => [
        'model_id'  => (string)$m['model_id'],
        'rank'      => (float)$m['capability_rank'],
        'price_in'  => $m['price_in_per_mtok'] !== null ? (float)$m['price_in_per_mtok'] : null,
        'price_out' => $m['price_out_per_mtok'] !== null ? (float)$m['price_out_per_mtok'] : null,
    ], $cands);
    $suggest = $ranked ? rh_ai_suggest_roles($ranked) : [];

    // Score: cheapest, fastest, latest and best, each 0..1 by place among the candidates (equal values share a place).
    $speeds = rh_ai_model_speeds($db, $provider);
    $info = [];
    foreach ($cands as $m) {
        $id = (string)$m['model_id'];
        $ms = $speeds[$id] ?? null;
        $info[$id] = [
            'cost'  => $priced($m) ? (float)$m['price_in_per_mtok'] + (float)$m['price_out_per_mtok'] : null,
            'speed' => rh_ai_model_speed_tier($id, $ms) * 1000000 - (int)round($ms ?? 0),
            'ms'    => $ms,
            'ver'   => $ver($m) * 100000 + (int)(strtotime((string)($m['released_at'] ?? '')) ?: 0) / 100000,
            'rank'  => (float)$m['capability_rank'],
        ];
    }
    $place = static function (string $key, bool $lowerBetter) use ($info): array {
        $vals = array_filter(array_column(array_map(static fn($i, $id) => ['id' => $id, 'v' => $i[$key]], $info, array_keys($info)), 'v', 'id'), static fn($v) => $v !== null);
        $n = count($vals);
        $res = [];
        foreach ($vals as $id => $v) {
            $worse = 0;
            foreach ($vals as $id2 => $v2) {
                if ($id2 !== $id && ($lowerBetter ? $v2 > $v : $v2 < $v)) {
                    $worse++;
                }
            }
            $res[$id] = $n > 1 ? $worse / ($n - 1) : 1.0;
        }
        return $res;
    };
    $pCheap = $place('cost', true);
    $pFast = $place('speed', false);
    $pNew = $place('ver', false);
    $pBest = $place('rank', false);
    $order = array_keys($info);
    usort($order, static function ($a, $b) use ($info, $pCheap, $pFast, $pNew, $pBest) {
        $sa = ($pCheap[$a] ?? 0) + ($pFast[$a] ?? 0) + ($pNew[$a] ?? 0) + ($pBest[$a] ?? 0);
        $sb = ($pCheap[$b] ?? 0) + ($pFast[$b] ?? 0) + ($pNew[$b] ?? 0) + ($pBest[$b] ?? 0);
        return [($info[$b]['cost'] !== null), $sb, $info[$b]['rank'], $b] <=> [($info[$a]['cost'] !== null), $sa, $info[$a]['rank'], $a];
    });
    // The suggestion (up to 5) is always on offer; the best of the rest fill the places left, shown best first.
    $keep = array_flip(array_keys($suggest));
    foreach ($order as $id) {
        if (count($keep) >= RH_AI_MODEL_CHOICES_MAX) {
            break;
        }
        $keep[$id] = true;
    }
    $top = array_values(array_filter($order, static fn($id) => isset($keep[$id])));

    $status = [];
    $newUnpriced = [];
    foreach ($top as $id) {
        if ($info[$id]['cost'] === null && !isset($suggest[$id])) {
            $status[$id] = 'new_price';
            $newUnpriced[] = $id;
        } else {
            $status[$id] = 'top';
        }
    }
    $byId = array_column($all, null, 'model_id');
    $shown = $top;
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
        if (!in_array($id, $shown, true)) {
            $shown[] = $id;
        }
    }
    foreach ($shown as $id) {
        if (isset($byId[$id])) {
            $out['rows'][] = $byId[$id];
        }
    }

    // Plain badges among the choices: the newest version, the cheapest, the fastest (one model each).
    $pick = static function (callable $better) use ($top, $info): ?string {
        $best = null;
        foreach ($top as $id) {
            if ($best === null || $better($info[$id], $info[$best]) > 0) {
                $best = $id;
            }
        }
        return $best;
    };
    $badges = [];
    $newest = $pick(static fn($a, $b) => [$a['ver'], $a['rank']] <=> [$b['ver'], $b['rank']]);
    $pricedTop = array_values(array_filter($top, static fn($id) => $info[$id]['cost'] !== null));
    $cheapest = null;
    foreach ($pricedTop as $id) {
        if ($cheapest === null || [$info[$id]['cost'], -$info[$id]['rank']] < [$info[$cheapest]['cost'], -$info[$cheapest]['rank']]) {
            $cheapest = $id;
        }
    }
    $fastest = $pick(static fn($a, $b) => [$a['speed'], -($a['cost'] ?? PHP_FLOAT_MAX)] <=> [$b['speed'], -($b['cost'] ?? PHP_FLOAT_MAX)]);
    foreach (['newest' => $newest, 'cheapest' => $cheapest, 'fastest' => $fastest] as $b => $id) {
        if ($id !== null) {
            $badges[$id][] = $b;
        }
    }

    $out['suggest'] = $suggest;
    $out['status'] = $status;
    $out['new_unpriced'] = $newUnpriced;
    $out['badges'] = $badges;
    $out['speed_ms'] = array_intersect_key($speeds, $status);
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
    $msg .= $choice ? ' Choices now (best first): ' . implode(', ', $choice) . '.' : ' No model can be suggested yet (no prices known).';
    if ($older) {
        $msg .= ' Older, still in use until someone picks another and saves: ' . implode(', ', $older) . '.';
    }
    if ($retired) {
        $msg .= ' No longer offered by the provider, choose a replacement and save: ' . implode(', ', $retired) . '.';
    }
    $leftOut = count(array_diff($newIds, array_map(static fn($m) => (string)$m['model_id'], $ch['rows'])));
    if ($leftOut > 0) {
        $msg .= ' ' . $leftOut . ' other new listing' . ($leftOut === 1 ? ' is' : 's are') . ' left out (an older version, a preview or alias of one on offer, or below the top ' . RH_AI_MODEL_CHOICES_MAX . ').';
    }
    return $msg;
}

/**
 * Turn the ticked rows and roles from the Models form into a choice that saves (owner fault 2026-11-20: "I am failing to
 * untick models and save"). A ticked row with no role becomes Extra; a second Fast or Fallback becomes Extra; with no
 * Primary (or more than one) the best-ranked of them is Primary and any other is Extra. Nothing is invented: only
 * ticked rows are kept. rh_ai_save_selection still checks the result.
 * @param list<string> $picks ticked model ids, in the order of the form
 * @param array<string,string> $roles model id => role as posted ('' = none)
 * @param array<string,float> $rankById model id => capability rank
 * @return array{roles:array<string,string>,notes:list<string>}
 */
function rh_ai_normalise_picks(array $picks, array $roles, array $rankById, array $labelById = []): array
{
    $allowed = array_keys(rh_ai_model_roles());
    $name = static fn(string $id): string => (string)($labelById[$id] ?? '') !== '' ? (string)$labelById[$id] : $id;
    $out = [];
    $notes = [];
    foreach ($picks as $id) {
        $id = (string)$id;
        if ($id === '' || isset($out[$id])) {
            continue;
        }
        $r = (string)($roles[$id] ?? '');
        if (!in_array($r, $allowed, true)) {
            $r = 'extra';
            $notes[] = $name($id) . ' had no role, so it was saved as Extra.';
        }
        $out[$id] = $r;
    }
    foreach (['fast' => 'Fast', 'fallback' => 'Fallback'] as $role => $label) {
        $seen = false;
        foreach ($out as $id => $r) {
            if ($r !== $role) {
                continue;
            }
            if ($seen) {
                $out[$id] = 'extra';
                $notes[] = 'Only one model can be ' . $label . ', so ' . $name($id) . ' was saved as Extra.';
            }
            $seen = true;
        }
    }
    if ($out) {
        $primaries = array_keys(array_filter($out, static fn($r) => $r === 'primary'));
        $pool = $primaries ?: array_keys($out);
        usort($pool, static fn($a, $b) => ((float)($rankById[$b] ?? 0) <=> (float)($rankById[$a] ?? 0)) ?: strcmp($a, $b));
        $keep = $pool[0];
        if (count($primaries) !== 1) {
            foreach ($primaries as $id) {
                if ($id !== $keep) {
                    $out[$id] = 'extra';
                    $notes[] = 'Only one model can be Primary, so ' . $name($id) . ' was saved as Extra.';
                }
            }
            if (!$primaries) {
                $was = ucfirst($out[$keep]);
                $notes[] = 'No model was Primary, so ' . $name($keep) . ' (the best of those ticked) is now Primary' . ($was !== 'Extra' ? ' instead of ' . $was : '') . '.';
            }
            $out[$keep] = 'primary';
        }
    }
    return ['roles' => $out, 'notes' => $notes];
}
