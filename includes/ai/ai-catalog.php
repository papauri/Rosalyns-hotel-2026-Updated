<?php

/**
 * Assistant model discovery: the shipped catalog (includes/ai/model-catalog.json), ranking of a
 * provider's live model list, the top-5 suggestion, and keeping the ai_models table in step.
 *
 * Ranking (plan Phase 1 "Model discovery"):
 *  - a model in the catalog keeps the catalog rank, label and prices;
 *  - an unknown id is ranked from its family and version (e.g. a newer "opus" sits just above the
 *    newest known opus) and its created date, and is flagged new until a price is found automatically;
 *  - the top 5 are offered pre-ticked; nothing is saved until a person clicks Save.
 */

require_once __DIR__ . '/ai-providers.php';
require_once __DIR__ . '/ai-price-page.php'; // prices read from the provider's own price page (Google)

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

/**
 * A model this hotel never uses: never listed, suggested, saved or adopted. Kept here, beside the big-model rule, as
 * the one place an owner ban goes. None at present: the owner lifted the 2026-10-28 "never Gemini 4" rule on
 * 2026-11-20 ("show and allow suggesting"); Gemini 4 models follow the same price and size rules as every other model.
 */
function rh_ai_model_banned(string $id): bool
{
    return false;
}

/** The plain refusal when someone picks a banned model. */
function rh_ai_model_banned_message(): string
{
    return 'This model is not used by this Assistant (owner rule). Choose another model.';
}

/**
 * A model the provider has closed (a test reply or a chat got 404 / "no longer available"): marked source = 'gone' in
 * ai_models so it is never suggested, chosen, adopted or pinged again (a model-list refresh keeps the mark).
 */
function rh_ai_model_mark_gone(?PDO $db, string $provider, string $model): void
{
    if (!$db || $model === '') {
        return;
    }
    try {
        $db->prepare("INSERT INTO ai_models (provider, model_id, label, source, is_new) VALUES (?, ?, ?, 'gone', 0) ON DUPLICATE KEY UPDATE source = 'gone'")
            ->execute([$provider, mb_substr($model, 0, 120), mb_substr($model, 0, 160)]);
    } catch (Throwable $e) {
        error_log('Assistant: could not mark a closed model: ' . $e->getMessage());
    }
}

/** Ids of a provider's models marked closed. */
function rh_ai_models_gone(?PDO $db, string $provider): array
{
    if (!$db) {
        return [];
    }
    try {
        $st = $db->prepare("SELECT model_id FROM ai_models WHERE provider = ? AND source = 'gone'");
        $st->execute([$provider]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

/** A provider's answer that means "this model is closed to you" (404, or the words "no longer available"). */
function rh_ai_model_closed_reply(int $http, string $message): bool
{
    return $http === 404 || stripos($message, 'no longer available') !== false;
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
    // A size after the version is not a minor version ("gemma-4-31b-it" -> 4, not 4.31).
    if (preg_match('/(\d+)(?:[.\-](\d{1,2})(?![\da-z]))?(?!\d)/', $s, $m)) {
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
    return array_slice(array_values(array_filter(array_column($ranked, 'model_id'), static fn($id) => !rh_ai_model_banned((string)$id))), 0, 5);
}

/**
 * A big (flagship) model: never suggested for chat (owner 2026-10-28: "never use big models like Opus 5.5 just
 * for chat"). Big = an Opus / Fable / Pro / Ultra family, or a price out of US$15+ per million tokens. Argon is no
 * longer big by name (owner 2026-11-20): it is judged by its price (US$10 out until 30 Nov 2026, US$20 from 1 Dec).
 */
function rh_ai_model_is_big(array $m): bool
{
    $fam = '-' . rh_ai_model_family((string)($m['model_id'] ?? '')) . '-';
    if (preg_match('/-(opus|fable|pro|ultra)-/', $fam)) {
        return true;
    }
    return isset($m['price_out']) && $m['price_out'] !== null && (float)$m['price_out'] + 0.0001 >= 15;
}

/**
 * Suggested roles: the cheapest models that still perform, never a big model. The bar is the best priced model's
 * rank (big ones included); among priced models that are not big and rank within 15 of that bar, take the 3
 * cheapest (price in + price out), topped up with the next best non-big priced models if fewer than 3 clear it.
 * Of those the highest rank is Primary, the next Fallback, the cheapest Fast. Up to 5 are ticked: the rest are the
 * cheapest other non-big priced models as Extra. A model with no price is never suggested (it waits for a price).
 * No priced model at all: rank order without big models (best Primary, second Fallback, cheapest of the rest Fast).
 * @return array<string,string> model_id => role
 */
function rh_ai_suggest_roles(array $ranked): array
{
    // The bar may come from any priced model on offer; a banned (owner rule, none at present) or big model is never picked.
    $cost = static fn(array $m): float => (float)$m['price_in'] + (float)$m['price_out'];
    $priced = array_values(array_filter($ranked, static fn($m) => isset($m['price_in'], $m['price_out']) && $m['price_in'] !== null && $m['price_out'] !== null));
    $small = array_values(array_filter($priced, static fn($m) => !rh_ai_model_is_big($m) && !rh_ai_model_banned((string)$m['model_id'])));
    if (!$small) {
        $notBanned = array_values(array_filter($ranked, static fn($m) => !rh_ai_model_banned((string)($m['model_id'] ?? ''))));
        $pool = array_values(array_filter($notBanned, static fn($m) => !rh_ai_model_is_big($m))) ?: $notBanned;
        $top = array_slice($pool, 0, 5);
        $roles = [];
        foreach ($top as $i => $m) {
            $roles[$m['model_id']] = $i === 0 ? 'primary' : ($i === 1 ? 'fallback' : 'extra');
        }
        $rest = array_slice($top, 2) ?: (count($top) === 2 ? [$top[1]] : []);
        if ($rest) {
            usort($rest, static fn($a, $b) => (($a['price_in'] ?? PHP_FLOAT_MAX) <=> ($b['price_in'] ?? PHP_FLOAT_MAX)) ?: $a['rank'] <=> $b['rank']);
            $roles[$rest[0]['model_id']] = 'fast';
        }
        return $roles;
    }
    $bar = max(array_map(static fn($m) => (float)$m['rank'], $priced));
    $perform = array_values(array_filter($small, static fn($m) => (float)$m['rank'] + 0.0001 >= $bar - 15));
    // Cheapest first; on a tie the lower rank counts as the cheaper pick (keeps the better one for Primary/Fallback).
    usort($perform, static fn($a, $b) => ($cost($a) <=> $cost($b)) ?: ((float)$a['rank'] <=> (float)$b['rank']));
    $pick = array_slice($perform, 0, 3);
    foreach ($small as $m) { // fewer than 3 clear the bar: the next best non-big priced models (already best first)
        if (count($pick) >= 3) {
            break;
        }
        if (!in_array($m['model_id'], array_column($pick, 'model_id'), true)) {
            $pick[] = $m;
        }
    }
    usort($pick, static fn($a, $b) => ($cost($a) <=> $cost($b)) ?: ((float)$a['rank'] <=> (float)$b['rank']));
    $roles = [];
    if (count($pick) === 1) {
        $roles[$pick[0]['model_id']] = 'primary';
    } else {
        $fast = $pick[0];
        $others = array_slice($pick, 1);
        usort($others, static fn($a, $b) => (float)$b['rank'] <=> (float)$a['rank']);
        $roles[$others[0]['model_id']] = 'primary';
        if (isset($others[1])) {
            $roles[$others[1]['model_id']] = 'fallback';
        }
        $roles[$fast['model_id']] = 'fast';
    }
    $restP = $small;
    usort($restP, static fn($a, $b) => ($cost($a) <=> $cost($b)) ?: ((float)$b['rank'] <=> (float)$a['rank'])); // Extras: cheapest first
    foreach ($restP as $m) {
        if (count($roles) >= 5) {
            break;
        }
        if (!isset($roles[$m['model_id']])) {
            $roles[$m['model_id']] = 'extra';
        }
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

/**
 * Save a ranked list for a provider; keeps chosen models.
 * Prices are automatic (owner 2026-11-21: "no need for us to set AI prices, you do it automatically, we just set max
 * cap"): a known price (provider price page, else the built-in list) always replaces the stored one, also one typed
 * by hand before this change (price_edited goes back to 0). With no known price the stored one stays (the last known
 * price, never wiped to blank).
 */
function rh_ai_sync_models(PDO $db, string $provider, array $ranked): void
{
    $up = $db->prepare(
        "INSERT INTO ai_models (provider, model_id, label, released_at, context_tokens, price_in_per_mtok, price_out_per_mtok,
                                capability_rank, source, is_new, sort, last_seen_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE label = VALUES(label), released_at = VALUES(released_at), context_tokens = VALUES(context_tokens),
             price_edited = IF(VALUES(price_in_per_mtok) IS NOT NULL AND VALUES(price_out_per_mtok) IS NOT NULL, 0, price_edited),
             is_new = IF(VALUES(price_in_per_mtok) IS NOT NULL AND VALUES(price_out_per_mtok) IS NOT NULL, VALUES(is_new),
                         IF(price_in_per_mtok IS NOT NULL AND price_out_per_mtok IS NOT NULL, 0, VALUES(is_new))),
             price_in_per_mtok = IF(VALUES(price_in_per_mtok) IS NOT NULL AND VALUES(price_out_per_mtok) IS NOT NULL, VALUES(price_in_per_mtok), price_in_per_mtok),
             price_out_per_mtok = IF(VALUES(price_in_per_mtok) IS NOT NULL AND VALUES(price_out_per_mtok) IS NOT NULL, VALUES(price_out_per_mtok), price_out_per_mtok),
             capability_rank = VALUES(capability_rank), source = IF(source = 'gone', 'gone', VALUES(source)),
             sort = VALUES(sort), last_seen_at = NOW()"
    );
    $ids = [];
    $ranked = array_values(array_filter($ranked, static fn($m) => !rh_ai_model_banned((string)($m['model_id'] ?? '')))); // a banned model (none at present) is never stored
    // Prices read from the provider's own price page (owner 2026-11-20), in effect today, laid over the built-in list.
    // Every save keeps them (also when today's read failed). Nothing typed by hand wins any more (owner 2026-11-21).
    $pagePrices = rh_ai_price_page_prices($db, $provider);
    if ($pagePrices) {
        foreach ($ranked as $i => $m) {
            $pp = rh_ai_price_page_for($pagePrices, (string)$m['model_id']);
            if ($pp !== null) {
                $ranked[$i]['price_in'] = $pp[0];
                $ranked[$i]['price_out'] = $pp[1];
                $ranked[$i]['is_new'] = false;
            }
        }
    }
    // All or nothing (fault 2026-11-20): a lock wait part-way used to leave only the first rows marked as listed.
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        $listedAt = (string)$db->query("SELECT NOW()")->fetchColumn(); // every row of this list is seen at or after this
        foreach (array_values($ranked) as $i => $m) {
            $ids[] = $m['model_id'];
            $up->execute([
                $provider, mb_substr($m['model_id'], 0, 120), mb_substr((string)$m['label'], 0, 160),
                $m['released_at'] ?? $m['created'] ?? null, $m['context_tokens'],
                $m['price_in'], $m['price_out'], $m['rank'], $m['source'], $m['is_new'] ? 1 : 0, $i,
            ]);
        }
        // Models the provider no longer offers go, unless someone chose them (they then show as retired).
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM ai_models WHERE provider = ? AND is_selected = 0 AND model_id NOT IN ($in)")
                ->execute(array_merge([$provider], $ids));
            // When this full list was saved: a chosen model seen before it is no longer offered (rh_ai_model_choices).
            rh_ai_save_settings(['ai_models_listed_at_' . $provider => $listedAt], $db);
        }
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** Saved models of a provider, best first. */
function rh_ai_models_for(PDO $db, string $provider): array
{
    try {
        $st = $db->prepare("SELECT * FROM ai_models WHERE provider = ? ORDER BY capability_rank DESC, sort ASC, model_id ASC");
        $st->execute([$provider]);
        return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), static fn($m) => !rh_ai_model_banned((string)$m['model_id']) && (string)($m['source'] ?? '') !== 'gone'));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Save the chosen models (up to 5) for one provider and make it the provider in use. Every other
 * provider's choice is cleared (one provider at a time).
 * @param array<string,string> $roles model_id => role
 * Prices are never typed (owner 2026-11-21): they come from rh_ai_sync_models only.
 * @throws RuntimeException with a plain message when the choice is not valid
 */
function rh_ai_save_selection(PDO $db, string $provider, array $roles): void
{
    if (!rh_ai_is_provider($provider)) {
        throw new RuntimeException('Unknown provider.');
    }
    $allowed = array_keys(rh_ai_model_roles());
    if (count($roles) > 5) {
        throw new RuntimeException('Choose at most 5 models.');
    }
    foreach (array_keys($roles) as $id) {
        if (rh_ai_model_banned((string)$id)) {
            throw new RuntimeException(rh_ai_model_banned_message());
        }
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
    $db->prepare("UPDATE ai_models SET is_selected = 0, role = NULL")->execute();
    $sel = $db->prepare("UPDATE ai_models SET is_selected = 1, role = ? WHERE provider = ? AND model_id = ?");
    foreach ($roles as $id => $role) {
        $sel->execute([$role, $provider, $id]);
    }
    rh_ai_save_settings(['ai_provider' => $roles ? $provider : ''], $db);
}

// ── Connection test and refresh ───────────────────────────────────────────────

/**
 * Test a key: list the models, then send a 5-token "Reply OK" to the cheapest priced chat model (fast; the best
 * ranked one if none has a price).
 * @return array{ok:bool,ms:int,message:string,ranked:array,ping_model:string}
 * The message never contains the key.
 */
function rh_ai_test_connection(string $provider, string $key, ?PDO $db = null): array
{
    $db = $db ?? (($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null);
    $list = rh_ai_list_models($provider, $key);
    if (!$list['ok']) {
        return ['ok' => false, 'ms' => $list['ms'], 'message' => $list['message'], 'ranked' => [], 'ping_model' => ''];
    }
    $ranked = rh_ai_rank_models($provider, $list['models']);
    if (!$ranked) {
        return ['ok' => false, 'ms' => $list['ms'], 'message' => 'The key works, but the provider offers no chat models to it.', 'ranked' => [], 'ping_model' => ''];
    }
    // Which model answers the test (owner 2026-10-28): the chosen Fast model when the provider still lists it, else the
    // suggested Fast model, then the cheapest other suggested one. Never a model outside that, never a banned one, never one marked closed.
    $gone = rh_ai_models_gone($db, $provider);
    $usable = array_values(array_filter($ranked, static fn($m) => !rh_ai_model_banned((string)$m['model_id']) && !in_array((string)$m['model_id'], $gone, true)));
    if (!$usable) {
        return ['ok' => false, 'ms' => $list['ms'], 'message' => 'The key works, but none of the chat models offered to it can be used here. ' . rh_ai_model_banned_message(), 'ranked' => $ranked, 'ping_model' => ''];
    }
    $byId = array_column($usable, null, 'model_id');
    $cands = [];
    if ($db) {
        try {
            $st = $db->prepare("SELECT model_id FROM ai_models WHERE provider = ? AND is_selected = 1 AND role = 'fast' AND source <> 'gone' LIMIT 1");
            $st->execute([$provider]);
            $fastId = (string)$st->fetchColumn();
            if ($fastId !== '' && isset($byId[$fastId])) {
                $cands[] = $byId[$fastId];
            }
        } catch (Throwable $e) {
        }
    }
    $roles = rh_ai_suggest_roles($usable);
    $sugg = array_values(array_filter(array_map(static fn($id) => $byId[$id] ?? null, array_keys($roles))));
    usort($sugg, static function ($a, $b) use ($roles) { // the suggested Fast model first, then the cheapest
        $fa = ($roles[$a['model_id']] ?? '') === 'fast' ? 0 : 1;
        $fb = ($roles[$b['model_id']] ?? '') === 'fast' ? 0 : 1;
        if ($fa !== $fb) {
            return $fa <=> $fb;
        }
        $pa = isset($a['price_in'], $a['price_out']) ? (float)$a['price_in'] + (float)$a['price_out'] : PHP_FLOAT_MAX;
        $pb = isset($b['price_in'], $b['price_out']) ? (float)$b['price_in'] + (float)$b['price_out'] : PHP_FLOAT_MAX;
        return ($pa <=> $pb) ?: ((float)$b['rank'] <=> (float)$a['rank']);
    });
    foreach ($sugg as $m) {
        if (!in_array($m['model_id'], array_column($cands, 'model_id'), true)) {
            $cands[] = $m;
        }
    }
    if (!$cands) {
        return ['ok' => false, 'ms' => $list['ms'], 'message' => count($ranked) . ' chat models found, but none can be suggested yet (no prices known). Type a price for one, then test again.', 'ranked' => $ranked, 'ping_model' => ''];
    }
    $ping = ['ok' => false, 'ms' => 0, 'message' => '', 'reply' => ''];
    $pingRow = $cands[0];
    foreach (array_slice($cands, 0, 2) as $cand) { // one retry, on the next suggested model
        $pingRow = $cand;
        $ping = rh_ai_ping($provider, $key, (string)$cand['model_id']);
        if ($ping['ok'] || !rh_ai_model_closed_reply((int)($ping['http'] ?? 0), (string)$ping['message'])) {
            break;
        }
        // Closed to this key: never suggested or adopted again; a chosen one hands its role to the next model.
        rh_ai_model_mark_gone($db, $provider, (string)$cand['model_id']);
        if ($db && function_exists('rh_ai_model_missing')) {
            rh_ai_model_missing($db, $provider, (string)$cand['model_id']);
        }
    }
    $model = (string)$pingRow['model_id'];
    $n = count($ranked);
    if (!$ping['ok']) {
        return ['ok' => false, 'ms' => $ping['ms'], 'message' => $n . ' chat models found, but the test reply failed. ' . $ping['message'], 'ranked' => $ranked, 'ping_model' => $model];
    }
    $msg = 'Connected. ' . $n . ' chat model' . ($n === 1 ? '' : 's') . ' found; ' . $pingRow['label'] . ' answered the test in ' . $ping['ms'] . ' ms.';
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
    // The provider's price page first (Google only), so the list is saved with today's prices.
    $pr = rh_ai_price_page_url($provider) !== '' ? rh_ai_price_page_refresh($db, $provider) : ['ok' => false, 'message' => ''];
    rh_ai_sync_models($db, $provider, $ranked);
    $newIds = array_values(array_diff(array_column($ranked, 'model_id'), $before));
    $new = count($newIds);
    // The notice and the Models table come from the same choices (fault 2026-11-20), so they always agree.
    $ch = rh_ai_model_choices($db, $provider);
    $pLabel = rh_ai_provider_list()[$provider]['label'] ?? $provider;
    if (function_exists('rh_ai_notify_settings')) {
        // Only a new model that is now a choice but still needs a price is announced (one the table shows).
        $unpriced = array_values(array_intersect($ch['new_unpriced'], $newIds));
        if ($unpriced) {
            rh_ai_notify_settings($db, 'model_new_price', 'New ' . $pLabel . ' model(s) offered as a choice but with no price yet: '
                . implode(', ', $unpriced) . '. They are not used until a price is found; prices are read automatically each day (no need to type one).');
        }
        if ($ch['retired']) {
            rh_ai_notify_settings($db, 'model_retired', $pLabel . ' no longer offers ' . implode(', ', $ch['retired'])
                . ', still chosen. Nothing was switched: choose a replacement in Assistant Settings > Models and save.');
        }
    }
    $msg = rh_ai_model_choices_notice($ch, count($ranked), $newIds);
    if ((string)$pr['message'] !== '') {
        $msg .= ' ' . $pr['message'];
    }
    return ['ok' => true, 'count' => count($ranked), 'new' => $new, 'new_ids' => $newIds, 'retired' => $ch['retired'], 'prices_read' => !empty($pr['ok']),
        'message' => $msg];
}

/**
 * The daily refresh (scripts/ai-model-refresh.php, or the daily check on the first admin page load of the day
 * when the last refresh was before today). Does nothing at all when the Assistant is off.
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
        try { // when the lists were last checked (the daily check refreshes once per calendar day; cron is optional)
            rh_ai_save_settings(['ai_models_refreshed_at' => date('Y-m-d H:i:s')], $db);
        } catch (Throwable $e) {
        }
    }
    return ['ran' => true, 'results' => $results];
}

/**
 * Limits that fit the monthly cap (owner 2026-11-21: "for Budgets and limits be able to auto set to the best capacity
 * to fit budget"). Worst case on purpose: every person with access, plus the nightly checks, uses the whole daily
 * allowance every day of a 31-day month, and every token is priced at the Primary model's price (the Fast model is
 * cheaper, so the real month costs less). The split of tokens read / written comes from the last 30 days (10 % written
 * when there is too little history). Nothing is saved here: the page fills the fields and a person clicks Save limits.
 * @return array{ok:bool,reason:string,model:string,price_in:float,price_out:float,out_share:float,per_token:float,
 *               people:int,slots:int,daily:int,turn:int,avg_question:int,questions:int,month_max:float}
 */
function rh_ai_fit_limits(PDO $db, float $capUsd, int $people): array
{
    $out = ['ok' => false, 'reason' => '', 'model' => '', 'price_in' => 0.0, 'price_out' => 0.0, 'out_share' => 0.1, 'per_token' => 0.0,
        'people' => max(0, $people), 'slots' => max(1, $people) + 1, 'daily' => 0, 'turn' => 0, 'avg_question' => 5000, 'questions' => 0, 'month_max' => 0.0];
    $prov = rh_ai_active_provider();
    $primary = null;
    foreach ($prov !== '' ? rh_ai_models_for($db, $prov) : [] as $m) {
        if ((int)$m['is_selected'] === 1 && $m['role'] === 'primary') {
            $primary = $m;
        }
    }
    if ($primary === null) {
        $out['reason'] = 'Choose and save models (with one Primary) first: the fit is worked out from the Primary model\'s price.';
        return $out;
    }
    $out['model'] = (string)$primary['label'] !== '' ? (string)$primary['label'] : (string)$primary['model_id'];
    if ($primary['price_in_per_mtok'] === null || $primary['price_out_per_mtok'] === null) {
        $out['reason'] = $out['model'] . ' has no price yet, so the cost of a token is not known. Prices are filled in automatically each day.';
        return $out;
    }
    if ($capUsd <= 0) {
        $out['reason'] = 'Set a monthly cap first.';
        return $out;
    }
    $out['price_in'] = (float)$primary['price_in_per_mtok'];
    $out['price_out'] = (float)$primary['price_out_per_mtok'];
    $tin = $tout = $req = 0.0;
    try {
        $h = $db->query("SELECT COALESCE(SUM(tokens_in), 0) AS tin, COALESCE(SUM(tokens_out), 0) AS tout, COALESCE(SUM(requests), 0) AS req
                         FROM ai_usage_daily WHERE day >= CURDATE() - INTERVAL 30 DAY")->fetch(PDO::FETCH_ASSOC);
        [$tin, $tout, $req] = [(float)($h['tin'] ?? 0), (float)($h['tout'] ?? 0), (float)($h['req'] ?? 0)];
    } catch (Throwable $e) {
        // no history: the defaults below
    }
    if ($tin + $tout >= 20000) {
        $out['out_share'] = min(0.5, max(0.02, $tout / ($tin + $tout)));
    }
    if ($req >= 10) {
        $out['avg_question'] = (int)max(500, round(($tin + $tout) / $req));
    }
    $perToken = ($out['price_in'] * (1 - $out['out_share']) + $out['price_out'] * $out['out_share']) / 1000000;
    if ($perToken <= 0) {
        $out['reason'] = $out['model'] . ' is priced at US$0, so no limit is needed to stay within the cap.';
        return $out;
    }
    $out['per_token'] = $perToken;
    // The daily limit is checked before each call, so a person can go past it by one whole question (at most the
    // per-question limit): each slot's share pays for daily + turn. 10 % is kept back for the step already running
    // when a limit is reached (review 2026-11-21).
    $allow = $capUsd * 0.9 / $perToken / 31 / $out['slots'];
    $out['turn'] = (int)max(5000, min(rh_ai_turn_token_cap(), floor($allow / 2 / 1000) * 1000));
    $out['daily'] = (int)min(50000000, max(1000, floor(($allow - $out['turn']) / 1000) * 1000)); // the field's own range
    $out['questions'] = (int)floor($out['daily'] / $out['avg_question']);
    $out['month_max'] = round(($out['daily'] + $out['turn']) * $out['slots'] * 31 * $perToken, 2);
    $out['ok'] = true;
    return $out;
}

// The Models table choices and the refresh notice (fault 2026-11-20).
require_once __DIR__ . '/ai-model-choices.php';
