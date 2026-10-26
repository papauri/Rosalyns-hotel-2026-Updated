<?php

/**
 * Assistant model discovery: the shipped catalog (includes/ai/model-catalog.json), ranking of a
 * provider's live model list, the top-5 suggestion, and keeping the ai_models table in step.
 *
 * Ranking (plan Phase 1 "Model discovery"):
 *  - a model in the catalog keeps the catalog rank, label and prices;
 *  - an unknown id is ranked from its family and version (e.g. a newer "opus" sits just above the
 *    newest known opus) and its created date, and is flagged "new - set price" (prices editable);
 *  - the top 5 are offered pre-ticked; nothing is saved until a person clicks Save.
 */

require_once __DIR__ . '/ai-providers.php';

/** The catalog file, decoded once. Broken/missing file -> empty provider lists (never throws). */
function rh_ai_catalog(): array
{
    static $cat = null;
    if ($cat !== null) {
        return $cat;
    }
    $raw = @file_get_contents(__DIR__ . '/model-catalog.json');
    $j = is_string($raw) ? json_decode($raw, true) : null;
    $cat = is_array($j) && isset($j['providers']) && is_array($j['providers']) ? $j : ['providers' => []];
    return $cat;
}

/** Catalog entries for one provider, normalised and sorted best first. */
function rh_ai_catalog_models(string $provider): array
{
    $out = [];
    foreach ((array)(rh_ai_catalog()['providers'][$provider]['models'] ?? []) as $m) {
        if (!is_array($m) || empty($m['id']) || !rh_ai_is_chat_model((string)$m['id'])) {
            continue;
        }
        $out[] = [
            'model_id'       => (string)$m['id'],
            'label'          => (string)($m['label'] ?? $m['id']),
            'rank'           => (float)($m['rank'] ?? 0),
            'context_tokens' => isset($m['context_tokens']) ? (int)$m['context_tokens'] : null,
            'price_in'       => isset($m['price_in']) ? (float)$m['price_in'] : null,
            'price_out'      => isset($m['price_out']) ? (float)$m['price_out'] : null,
            'released_at'    => !empty($m['released_at']) ? (string)$m['released_at'] : null,
            'created'        => null,
            'verified'       => !empty($m['verified']),
            'note'           => (string)($m['note'] ?? ''),
            'is_new'         => false,
            'source'         => 'catalog',
        ];
    }
    usort($out, static fn($a, $b) => $b['rank'] <=> $a['rank']);
    return $out;
}

/** Id without a trailing date stamp ("claude-haiku-4-5-20251001" -> "claude-haiku-4-5"). */
function rh_ai_model_base_id(string $id): string
{
    return (string)preg_replace('/-(\d{8}|\d{4}-\d{2}-\d{2})$/', '', strtolower($id));
}

/** Family of a model id: the id without dates, versions and preview words ("gemini-2.5-flash" -> "gemini-flash"). */
function rh_ai_model_family(string $id): string
{
    $s = rh_ai_model_base_id($id);
    $s = (string)preg_replace('/-(\d{2})-(\d{2})$/', '', $s);                      // -05-20 preview dates
    $s = (string)preg_replace('/-(preview|exp|experimental|latest|beta)\b/', '', $s);
    $s = (string)preg_replace('/\d+([.\-]\d+)*/', '', $s);                         // version numbers
    $s = (string)preg_replace('/-+/', '-', $s);
    return trim($s, '-.');
}

/** Version number in a model id ("claude-opus-5-5" -> 5.5, "gemini-2.5-pro" -> 2.5, none -> 0). */
function rh_ai_model_version(string $id): float
{
    $s = rh_ai_model_base_id($id);
    $s = (string)preg_replace('/-(\d{2})-(\d{2})$/', '', $s);
    if (preg_match('/(\d+)(?:[.\-](\d{1,2}))?(?!\d)/', $s, $m)) {
        return (float)($m[1] . (isset($m[2]) && $m[2] !== '' ? '.' . $m[2] : ''));
    }
    return 0.0;
}

/** Estimated rank for an id the catalog does not know. */
function rh_ai_estimate_rank(string $provider, string $id, ?string $created = null): float
{
    $fam = rh_ai_model_family($id);
    $ver = rh_ai_model_version($id);
    $known = array_values(array_filter(rh_ai_catalog_models($provider), static fn($m) => rh_ai_model_family($m['model_id']) === $fam));
    if ($known) {
        $topRank = max(array_column($known, 'rank'));
        $minRank = min(array_column($known, 'rank'));
        $topVer = max(array_map(static fn($m) => rh_ai_model_version($m['model_id']), $known));
        if ($ver > $topVer) {
            return round($topRank + 1 + min($ver - $topVer, 3), 3); // a newer version sits just above its family's newest
        }
        return round($minRank - 1 + min($ver, 9.9) / 100, 3);     // an older one sits below the family
    }
    // Unknown family: below every catalog model, newer versions and newer dates first.
    $recency = 0.0;
    if ($created !== null && ($ts = strtotime($created)) !== false) {
        $recency = max(0, min(0.999, ($ts - strtotime('2023-01-01')) / (86400 * 4000)));
    }
    return round(10 + min($ver, 19) + $recency, 3);
}

/**
 * Merge a provider's live list with the catalog and rank it (best first).
 * Only models in the live list are returned: once a key exists, the live list is the truth.
 */
function rh_ai_rank_models(string $provider, array $live): array
{
    $cat = [];
    foreach (rh_ai_catalog_models($provider) as $c) {
        $cat[$c['model_id']] = $c;
    }
    $out = [];
    foreach ($live as $m) {
        $id = (string)($m['model_id'] ?? '');
        if ($id === '' || !rh_ai_is_chat_model($id)) {
            continue;
        }
        $c = $cat[$id] ?? $cat[rh_ai_model_base_id($id)] ?? null;
        if ($c !== null) {
            $row = $c;
            $row['model_id'] = $id;
            if (!empty($m['context_tokens'])) {
                $row['context_tokens'] = (int)$m['context_tokens'];
            }
            $row['created'] = $m['created'] ?? null;
        } else {
            $row = [
                'model_id'       => $id,
                'label'          => (string)($m['label'] ?? $id),
                'rank'           => rh_ai_estimate_rank($provider, $id, $m['created'] ?? null),
                'context_tokens' => !empty($m['context_tokens']) ? (int)$m['context_tokens'] : null,
                'price_in'       => null,
                'price_out'      => null,
                'released_at'    => null,
                'created'        => $m['created'] ?? null,
                'verified'       => false,
                'note'           => '',
                'is_new'         => true,
                'source'         => 'live',
            ];
        }
        $out[] = $row;
    }
    usort($out, static function ($a, $b) {
        return [$b['rank'], (string)$b['created']] <=> [$a['rank'], (string)$a['created']];
    });
    return $out;
}

/** The five best model ids (pre-ticked suggestions). */
function rh_ai_suggest_top5(array $ranked): array
{
    return array_slice(array_column($ranked, 'model_id'), 0, 5);
}

/**
 * Suggested roles for the top 5: best = Primary, second = Fallback, cheapest of the rest = Fast,
 * the others Extra. @return array<string,string> model_id => role
 */
function rh_ai_suggest_roles(array $ranked): array
{
    $top = array_slice($ranked, 0, 5);
    $roles = [];
    foreach ($top as $i => $m) {
        $roles[$m['model_id']] = $i === 0 ? 'primary' : ($i === 1 ? 'fallback' : 'extra');
    }
    $rest = array_slice($top, 2);
    if (!$rest && count($top) === 2) {
        $rest = [$top[1]];
    }
    if ($rest) {
        usort($rest, static function ($a, $b) {
            $pa = $a['price_in'] ?? PHP_FLOAT_MAX;
            $pb = $b['price_in'] ?? PHP_FLOAT_MAX;
            return $pa <=> $pb ?: $a['rank'] <=> $b['rank'];
        });
        $roles[$rest[0]['model_id']] = 'fast';
    }
    return $roles;
}

/** The roles a chosen model can have, with the plain wording the page shows. */
function rh_ai_model_roles(): array
{
    return [
        'primary'  => 'Primary - answers questions and plans',
        'fast'     => 'Fast - short replies and routing',
        'fallback' => 'Fallback - used if the primary fails',
        'extra'    => 'Extra - kept ready',
    ];
}

// ── ai_models table ───────────────────────────────────────────────────────────

/** Save a ranked list for a provider; keeps chosen models and hand-edited prices. */
function rh_ai_sync_models(PDO $db, string $provider, array $ranked): void
{
    $up = $db->prepare(
        "INSERT INTO ai_models (provider, model_id, label, released_at, context_tokens, price_in_per_mtok, price_out_per_mtok,
                                capability_rank, source, is_new, sort, last_seen_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE label = VALUES(label), released_at = VALUES(released_at), context_tokens = VALUES(context_tokens),
             price_in_per_mtok = IF(price_edited = 1, price_in_per_mtok, VALUES(price_in_per_mtok)),
             price_out_per_mtok = IF(price_edited = 1, price_out_per_mtok, VALUES(price_out_per_mtok)),
             capability_rank = VALUES(capability_rank), source = VALUES(source),
             is_new = IF(price_edited = 1, 0, VALUES(is_new)), sort = VALUES(sort), last_seen_at = NOW()"
    );
    $ids = [];
    foreach (array_values($ranked) as $i => $m) {
        $ids[] = $m['model_id'];
        $up->execute([
            $provider, mb_substr($m['model_id'], 0, 120), mb_substr((string)$m['label'], 0, 160),
            $m['released_at'] ?? $m['created'] ?? null, $m['context_tokens'],
            $m['price_in'], $m['price_out'], $m['rank'], $m['source'], $m['is_new'] ? 1 : 0, $i,
        ]);
    }
    // Models the provider no longer offers go, unless someone chose them (they then show as "not offered").
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM ai_models WHERE provider = ? AND is_selected = 0 AND model_id NOT IN ($in)")
            ->execute(array_merge([$provider], $ids));
    }
}

/** Saved models of a provider, best first. */
function rh_ai_models_for(PDO $db, string $provider): array
{
    try {
        $st = $db->prepare("SELECT * FROM ai_models WHERE provider = ? ORDER BY capability_rank DESC, sort ASC, model_id ASC");
        $st->execute([$provider]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Save the chosen models (up to 5) for one provider and make it the provider in use. Every other
 * provider's choice is cleared (one provider at a time).
 * @param array<string,string> $roles model_id => role
 * @param array<string,array{0:?string,1:?string}> $prices model_id => [in, out] as typed ('' = unknown)
 * @throws RuntimeException with a plain message when the choice is not valid
 */
function rh_ai_save_selection(PDO $db, string $provider, array $roles, array $prices = []): void
{
    if (!rh_ai_is_provider($provider)) {
        throw new RuntimeException('Unknown provider.');
    }
    $allowed = array_keys(rh_ai_model_roles());
    if (count($roles) > 5) {
        throw new RuntimeException('Choose at most 5 models.');
    }
    $counts = array_count_values(array_values($roles));
    foreach ($roles as $id => $role) {
        if (!in_array($role, $allowed, true)) {
            throw new RuntimeException('Each chosen model needs a role.');
        }
    }
    if ($roles && ($counts['primary'] ?? 0) !== 1) {
        throw new RuntimeException('Choose exactly one Primary model.');
    }
    foreach (['fast' => 'Fast', 'fallback' => 'Fallback'] as $r => $label) {
        if (($counts[$r] ?? 0) > 1) {
            throw new RuntimeException('Only one model can be ' . $label . '.');
        }
    }
    $known = array_column(rh_ai_models_for($db, $provider), null, 'model_id');
    foreach (array_keys($roles) as $id) {
        if (!isset($known[$id])) {
            throw new RuntimeException('One of the chosen models is not offered by this provider any more. Click Check for newer models.');
        }
    }
    $num = static function ($v): ?float {
        $v = trim((string)$v);
        if ($v === '') {
            return null;
        }
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 10000) {
            throw new RuntimeException('Prices must be numbers between 0 and 10000 (US dollars per million tokens).');
        }
        return round((float)$v, 4);
    };
    $db->prepare("UPDATE ai_models SET is_selected = 0, role = NULL")->execute();
    $sel = $db->prepare("UPDATE ai_models SET is_selected = 1, role = ? WHERE provider = ? AND model_id = ?");
    foreach ($roles as $id => $role) {
        $sel->execute([$role, $provider, $id]);
    }
    $pr = $db->prepare("UPDATE ai_models SET price_in_per_mtok = ?, price_out_per_mtok = ?, price_edited = 1, is_new = 0 WHERE provider = ? AND model_id = ?");
    foreach ($prices as $id => $pair) {
        if (!isset($known[$id])) {
            continue;
        }
        $in = $num($pair[0] ?? '');
        $out = $num($pair[1] ?? '');
        $oldIn = $known[$id]['price_in_per_mtok'] !== null ? round((float)$known[$id]['price_in_per_mtok'], 4) : null;
        $oldOut = $known[$id]['price_out_per_mtok'] !== null ? round((float)$known[$id]['price_out_per_mtok'], 4) : null;
        if ($in === $oldIn && $out === $oldOut) {
            continue; // unchanged: keep following the catalog
        }
        $pr->execute([$in, $out, $provider, $id]);
    }
    rh_ai_save_settings(['ai_provider' => $roles ? $provider : ''], $db);
}

// ── Connection test and refresh ───────────────────────────────────────────────

/**
 * Test a key: list the models, then send a 5-token "Reply OK" to the best one.
 * @return array{ok:bool,ms:int,message:string,ranked:array,ping_model:string}
 * The message never contains the key.
 */
function rh_ai_test_connection(string $provider, string $key): array
{
    $list = rh_ai_list_models($provider, $key);
    if (!$list['ok']) {
        return ['ok' => false, 'ms' => $list['ms'], 'message' => $list['message'], 'ranked' => [], 'ping_model' => ''];
    }
    $ranked = rh_ai_rank_models($provider, $list['models']);
    if (!$ranked) {
        return ['ok' => false, 'ms' => $list['ms'], 'message' => 'The key works, but the provider offers no chat models to it.', 'ranked' => [], 'ping_model' => ''];
    }
    $model = $ranked[0]['model_id'];
    $ping = rh_ai_ping($provider, $key, $model);
    $n = count($ranked);
    if (!$ping['ok']) {
        return ['ok' => false, 'ms' => $ping['ms'], 'message' => $n . ' chat models found, but the test reply failed. ' . $ping['message'], 'ranked' => $ranked, 'ping_model' => $model];
    }
    $msg = 'Connected. ' . $n . ' chat model' . ($n === 1 ? '' : 's') . ' found; ' . $ranked[0]['label'] . ' answered the test in ' . $ping['ms'] . ' ms.';
    return ['ok' => true, 'ms' => $ping['ms'], 'message' => $msg, 'ranked' => $ranked, 'ping_model' => $model];
}

/**
 * Refresh one provider's model list with its saved key (no test reply).
 * @return array{ok:bool,count:int,new:int,message:string}
 */
function rh_ai_refresh_provider(PDO $db, string $provider): array
{
    $key = rh_ai_get_key($provider, $db);
    if ($key === '') {
        return ['ok' => false, 'count' => 0, 'new' => 0, 'message' => 'No readable key saved for ' . (rh_ai_provider_list()[$provider]['label'] ?? $provider) . '.'];
    }
    $list = rh_ai_list_models($provider, $key);
    if (!$list['ok']) {
        return ['ok' => false, 'count' => 0, 'new' => 0, 'message' => $list['message']];
    }
    $ranked = rh_ai_rank_models($provider, $list['models']);
    $before = array_column(rh_ai_models_for($db, $provider), 'model_id');
    rh_ai_sync_models($db, $provider, $ranked);
    $new = count(array_diff(array_column($ranked, 'model_id'), $before));
    return ['ok' => true, 'count' => count($ranked), 'new' => $new, 'message' => count($ranked) . ' chat models listed' . ($new ? ', ' . $new . ' new since the last check.' : '; nothing new.')];
}

/**
 * The weekly refresh (scripts/ai-model-refresh.php, or the daily check on the first admin page load of the day
 * when the last refresh is over 7 days old). Does nothing at all when the Assistant is off.
 * @return array{ran:bool,results:array<string,array>}
 */
function rh_ai_model_refresh_run(PDO $db): array
{
    if (!rh_ai_enabled($db)) {
        return ['ran' => false, 'results' => []];
    }
    $results = [];
    foreach (rh_ai_provider_states($db) as $p => $st) {
        if (!$st['has_key'] || !rh_ai_is_provider($p)) {
            continue;
        }
        $results[$p] = rh_ai_refresh_provider($db, $p);
    }
    if ($results) {
        try { // when the lists were last checked (the daily check refreshes again after 7 days; cron is optional)
            rh_ai_save_settings(['ai_models_refreshed_at' => date('Y-m-d H:i:s')], $db);
        } catch (Throwable $e) {
        }
    }
    return ['ran' => true, 'results' => $results];
}
