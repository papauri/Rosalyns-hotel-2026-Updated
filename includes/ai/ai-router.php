<?php

/**
 * The learned local router (owner 2026-10-29: "this system also learns from mistakes and is able to create some model of
 * faster, cheaper or free answering"). Part of the existing learning loop: trained in the daily run (rh_ai_reflect_run),
 * stored as ai_memory rows of kind 'route', shown on the usage page with "Forget".
 *
 *  - Training data: questions the model answered (privacy tokens only - the vault's own tokens, then stripped to a kind
 *    like #guest; no personal value is ever stored), each with the ONE read lookup it used and that lookup's arguments,
 *    or the ONE card it prepared. Answers marked Not right are left out. At most ai_route_train_rows rows a day.
 *  - Promotion: the same lookup with the same argument pattern (fixed values, today, a date said, a token said) behind
 *    ai_route_promote_n answers from at least two chats, none marked Not right, becomes a learned route.
 *  - Use: before any model call, the question's words (normalised tokens + word pairs) are compared with each route's
 *    examples (nearest neighbour, Jaccard). At or above ai_route_min_pct the lookup runs locally and the reply is written
 *    from that lookup's own result (its local wording, or the plain built-from-results template) - no tokens. Below it,
 *    the question goes on as before. Deterministic: same question, same routes -> same answer.
 *  - Demotion: a learned answer marked Not right, or a question-set failure answered by a route, retires the route.
 *  - Hard rails (code, not learning): a route can only run a READ lookup, or open a guided card (which still needs
 *    Confirm). Never a write, never money, never a message that reads as a change (typo-read words never count: card
 *    routes match the person's own words only). The person's own permissions and the business type are checked when it
 *    runs, like every other lookup.
 */

require_once __DIR__ . '/ai-core.php';

/** Words that carry no meaning for routing. */
function rh_ai_route_stop(): array
{
    static $s = null;
    if ($s === null) {
        $s = array_fill_keys(explode(' ', 'a an the is are was were be been do does did can could would should will please pls kindly me my i we our you your us it its to of in on at for and or with this that these those there here what whats which who how hi hello hey thanks thank'), true);
    }
    return $s;
}

/**
 * Normalised features of a (tokenised) question: lower-case words, privacy tokens as their kind (#guest, #bk ...),
 * numbers as #n, plus each pair of neighbouring words. No personal value survives this.
 */
function rh_ai_route_terms(string $tok): array
{
    $t = mb_strtolower(rh_ai_memory_strip_tokens_kind($tok));
    $t = (string)preg_replace('/\d{4}-\d{2}-\d{2}/u', ' #date ', $t);
    $t = (string)preg_replace('/\d+(?:[.,]\d+)*/u', ' #n ', $t);
    $words = [];
    foreach (preg_split('/[^\p{L}#]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (mb_strlen($w) < 2 || isset(rh_ai_route_stop()[$w])) {
            continue;
        }
        $words[] = function_exists('rh_kb_stem') && $w[0] !== '#' ? rh_kb_stem($w) : $w;
    }
    $out = $words;
    for ($i = 0; $i + 1 < count($words); $i++) {
        $out[] = $words[$i] . '_' . $words[$i + 1];
    }
    return array_values(array_unique($out));
}

/** Privacy tokens -> their kind ("[GUEST_3]" -> "#guest"); real values never reach the router's store. */
function rh_ai_memory_strip_tokens_kind(string $t): string
{
    return (string)preg_replace_callback('/\[(GUEST|EMAIL|PHONE|BK|STAFF|ADDR)_\d+\]/', static fn($m) => ' #' . strtolower($m[1]) . ' ', $t);
}

/** Jaccard similarity of two feature sets (0..1). */
function rh_ai_route_similarity(array $a, array $b): float
{
    if (!$a || !$b) {
        return 0.0;
    }
    $i = count(array_intersect($a, $b));
    $u = count(array_unique(array_merge($a, $b)));
    return $u > 0 ? $i / $u : 0.0;
}

/**
 * The argument pattern of one lookup, from the question it answered: fixed values stay; a date equal to the day asked
 * becomes {today}/{tomorrow}, a date said in the question {date}; a privacy token said in the question {tok:KIND}.
 * null = an argument that cannot be filled from a new question (that answer is not learned).
 */
function rh_ai_route_pattern(string $qTok, array $args, string $day): ?array
{
    $out = [];
    foreach ($args as $k => $v) {
        if ($k === 'page' || $v === null || $v === '') {
            continue;
        }
        if (is_array($v)) {
            return null;
        }
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            if ($v === $day) {
                $out[$k] = '{today}';
            } elseif ($v === date('Y-m-d', strtotime($day . ' +1 day'))) {
                $out[$k] = '{tomorrow}';
            } elseif ($v === date('Y-m-d', strtotime($day . ' -1 day'))) {
                $out[$k] = '{yesterday}';
            } elseif (function_exists('rh_ai_guided_date') && rh_ai_guided_date($qTok, $day) === $v) {
                $out[$k] = '{date}';
            } else {
                return null;
            }
            continue;
        }
        if (is_string($v) && preg_match('/^\[(GUEST|EMAIL|PHONE|BK|STAFF)_\d+\]$/', $v, $m)) {
            if (strpos($qTok, $v) === false) {
                return null;
            }
            $out[$k] = '{tok:' . $m[1] . '}';
            continue;
        }
        if (is_string($v) && (preg_match('/\[(GUEST|EMAIL|PHONE|BK|STAFF)_\d+\]/', $v) || mb_strlen($v) > 40)) {
            return null;
        }
        if (is_string($v) && $v !== '' && mb_stripos($qTok, $v) !== false && !preg_match('/^(today|tomorrow|yesterday|week|month|all|arrivals|departures)$/i', $v)) {
            return null; // a word taken from this question (a name, a room): not a fixed value
        }
        $out[$k] = $v;
    }
    ksort($out);
    return $out;
}

/** Fill a pattern for a new question; null when a slot cannot be filled. */
function rh_ai_route_fill(array $pattern, string $tok): ?array
{
    $today = rh_ai_today();
    $out = [];
    foreach ($pattern as $k => $v) {
        if (!is_string($v) || $v === '' || $v[0] !== '{') {
            $out[$k] = $v;
            continue;
        }
        if ($v === '{today}') {
            $out[$k] = $today;
        } elseif ($v === '{tomorrow}') {
            $out[$k] = date('Y-m-d', strtotime($today . ' +1 day'));
        } elseif ($v === '{yesterday}') {
            $out[$k] = date('Y-m-d', strtotime($today . ' -1 day'));
        } elseif ($v === '{date}') {
            $d = function_exists('rh_ai_guided_date') ? rh_ai_guided_date($tok) : null;
            if ($d === null) {
                return null;
            }
            $out[$k] = $d;
        } elseif (preg_match('/^\{tok:([A-Z]+)\}$/', $v, $m) && preg_match('/\[' . $m[1] . '_\d+\]/', $tok, $tm)) {
            $out[$k] = $tm[0];
        } else {
            return null;
        }
    }
    return $out;
}

/** May this lookup / card be learned at all? READ lookups and guided cards only; nothing that moves money. */
function rh_ai_route_target_ok(string $target): bool
{
    if (strpos($target, 'card:') === 0) {
        $key = substr($target, 5);
        return function_exists('rh_ai_guided_keys') && in_array($key, rh_ai_guided_keys(), true) && !preg_match('/(pay|refund|no.?show|charge|void|credit|invoice|price|rate|deal|stock|pos)/i', $key);
    }
    return isset(rh_ai_tool_registry()[$target]) && rh_ai_action_key_for_tool($target) === '' && in_array($target, rh_ai_route_renderable(), true);
}

/**
 * Read lookups whose reply can be written locally from their own result (a wording kept per lookup, the same one its
 * local answers use). Only these are learned: a learned route always answers with no model call, and a lookup with no
 * local wording never has its result dumped (a filtered "0" is never sent out as "none").
 */
function rh_ai_route_renderable(): array
{
    return ['hotel_info', 'order_status', 'gym_watch', 'search_bookings', 'room_rates', 'room_performance', 'arrivals_departures', 'in_house', 'room_board', 'who_is_online', 'my_access',
        'get_booking', 'booking_history', 'upcoming_arrivals', 'booking_holds', 'blocked_dates', 'staff_activity'];
}

/**
 * Training samples from the chat log (privacy tokens only), newest first, at most ai_route_train_rows answers read.
 * @return array<int,array{q:string,target:string,pattern:array,conv:int,msg:int}>
 */
function rh_ai_route_samples(PDO $db): array
{
    $limit = rh_ai_num('ai_route_train_rows');
    $days = rh_ai_num('ai_route_train_days');
    $out = [];
    try {
        $st = $db->prepare("SELECT a.id, a.conversation_id, a.created_at FROM ai_messages a JOIN ai_conversations c ON c.id = a.conversation_id
                            LEFT JOIN ai_feedback f ON f.message_id = a.id AND f.rating < 0
                            WHERE a.role = 'assistant' AND a.agent IN ('fast', 'primary') AND a.tokens_in > 0 AND f.id IS NULL
                              AND COALESCE(a.tool_args_json, '') NOT LIKE '%\"claim_fixed\":1%'
                              AND a.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND COALESCE(c.page_path, '') <> ?
                            ORDER BY a.id DESC LIMIT " . (int)$limit);
        $st->execute([$days, defined('RH_AI_CHECK_PAGE') ? RH_AI_CHECK_PAGE : ':assistant-check']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $uq = $db->prepare("SELECT id, content FROM ai_messages WHERE conversation_id = ? AND role = 'user' AND id < ? ORDER BY id DESC LIMIT 1");
        $tq = $db->prepare("SELECT tool_name, tool_args_json FROM ai_messages WHERE conversation_id = ? AND role = 'tool' AND id > ? AND id < ? AND (agent IS NULL OR agent NOT LIKE 'specialist:%') ORDER BY id LIMIT 12");
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as $r) {
        $uq->execute([(int)$r['conversation_id'], (int)$r['id']]);
        $u = $uq->fetch(PDO::FETCH_ASSOC);
        if (!$u || mb_strlen((string)$u['content']) > 200 || trim((string)$u['content']) === '') {
            continue;
        }
        $tq->execute([(int)$r['conversation_id'], (int)$u['id'], (int)$r['id']]);
        $reads = [];
        $cards = [];
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $n = (string)$t['tool_name'];
            if (strpos($n, 'propose_') === 0) {
                $cards[rh_ai_action_key_for_tool($n)] = true;
            } elseif ($n !== '' && $n !== 'consult_team') {
                $reads[$n][] = (array)(json_decode((string)$t['tool_args_json'], true) ?: []);
            }
        }
        $q = (string)$u['content'];
        if (count($cards) === 1 && !$reads) {
            $target = 'card:' . (string)array_key_first($cards);
            $pattern = [];
        } elseif (!$cards && count($reads) === 1) {
            $target = (string)array_key_first($reads);
            $argsets = array_map('json_encode', $reads[$target]);
            if (count(array_unique($argsets)) !== 1) {
                continue; // the same lookup with different arguments: not one plain route
            }
            $pattern = rh_ai_route_pattern($q, $reads[$target][0], substr((string)$r['created_at'], 0, 10));
            if ($pattern === null) {
                continue;
            }
        } else {
            continue;
        }
        if (!rh_ai_route_target_ok($target) || (function_exists('rh_ai_text_is_write') && strpos($target, 'card:') !== 0 && rh_ai_text_is_write($q))) {
            continue;
        }
        $out[] = ['q' => $q, 'target' => $target, 'pattern' => $pattern, 'conv' => (int)$r['conversation_id'], 'msg' => (int)$r['id']];
    }
    return $out;
}

/**
 * The daily training (bounded: one read of at most ai_route_train_rows answers, grouping in memory, at most
 * ai_route_max routes kept). New groups with enough support become routes; existing routes get the new examples.
 * @return array{ok:bool,note:string,promoted:int,updated:int}
 */
function rh_ai_route_train(PDO $db): array
{
    $res = ['ok' => true, 'note' => '', 'promoted' => 0, 'updated' => 0];
    if (!rh_ai_memory_ready($db) || !rh_ai_learning_on() || rh_ai_setting('ai_router_enabled', '1') === '0') {
        $res['note'] = 'Local router learning is off.';
        return $res;
    }
    $started = microtime(true);
    $need = rh_ai_num('ai_route_promote_n');
    $groups = [];
    foreach (rh_ai_route_samples($db) as $s) {
        $sig = $s['target'] . '|' . json_encode($s['pattern']);
        $groups[$sig] = $groups[$sig] ?? ['target' => $s['target'], 'pattern' => $s['pattern'], 'qs' => [], 'convs' => [], 'msgs' => []];
        $groups[$sig]['qs'][] = $s['q'];
        $groups[$sig]['convs'][$s['conv']] = true;
        $groups[$sig]['msgs'][] = $s['msg'];
        if (microtime(true) - $started > 20) {
            break; // bounded in time
        }
    }
    $existing = [];
    foreach ($db->query("SELECT id, status, meta_json FROM ai_memory WHERE kind = 'route' ORDER BY id LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = json_decode((string)$r['meta_json'], true) ?: [];
        $existing[(string)($m['sig'] ?? '')] = ['id' => (int)$r['id'], 'status' => (string)$r['status'], 'meta' => $m];
    }
    $active = (int)$db->query("SELECT COUNT(*) FROM ai_memory WHERE kind = 'route' AND status = 'active'")->fetchColumn();
    foreach ($groups as $sig => $g) {
        $examples = [];
        foreach (array_unique($g['qs']) as $q) {
            $examples[] = implode(' ', rh_ai_route_terms($q));
        }
        $examples = array_slice(array_values(array_unique($examples)), 0, 12);
        if (isset($existing[$sig])) {
            $e = $existing[$sig];
            if ($e['status'] !== 'active') {
                continue; // retired (Not right) or forgotten: never re-learned on its own
            }
            $meta = $e['meta'];
            $meta['examples'] = array_slice(array_values(array_unique(array_merge((array)($meta['examples'] ?? []), $examples))), 0, 20);
            $meta['support'] = max((int)($meta['support'] ?? 0), count($g['qs']));
            $db->prepare("UPDATE ai_memory SET meta_json = ? WHERE id = ?")->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $e['id']]);
            $res['updated']++;
            continue;
        }
        if (count($g['qs']) < $need || count($g['convs']) < 2 || $active >= rh_ai_num('ai_route_max')) {
            continue;
        }
        $label = strpos($g['target'], 'card:') === 0 ? 'opens the ' . mb_strtolower((string)(rh_ai_action_registry()[substr($g['target'], 5)]['label'] ?? 'card')) . ' steps' : 'answered from ' . rh_ai_tool_step($g['target']);
        $text = 'Questions like "' . mb_substr(trim(rh_ai_memory_redact(rh_ai_memory_strip_tokens((string)$g['qs'][0]))), 0, 120) . '" are ' . $label . ' with no model call.';
        $meta = ['sig' => $sig, 'target' => $g['target'], 'pattern' => $g['pattern'], 'examples' => $examples, 'support' => count($g['qs']), 'from_messages' => array_slice($g['msgs'], 0, 10)];
        $db->prepare("INSERT INTO ai_memory (kind, scope, text, terms, meta_json, source, source_ref, confidence, status) VALUES ('route', 'hotel', ?, ?, ?, 'reflect', ?, 0.80, 'active')")
            ->execute([mb_substr($text, 0, 600), mb_substr(implode(' ', $examples), 0, 1000), json_encode($meta, JSON_UNESCAPED_UNICODE), 'router:' . date('Y-m-d')]);
        $active++;
        $res['promoted']++;
    }
    $res['note'] = $res['promoted'] . ' route(s) learned, ' . $res['updated'] . ' updated (' . count($groups) . ' question shape(s) seen).';
    return $res;
}

/** Active learned routes (id order: ties always resolve the same way). */
function rh_ai_routes_active(PDO $db): array
{
    if (isset($GLOBALS['__rh_ai_memo']['routes'])) {
        return $GLOBALS['__rh_ai_memo']['routes'];
    }
    $out = [];
    try {
        foreach ($db->query("SELECT id, meta_json FROM ai_memory WHERE kind = 'route' AND status = 'active' ORDER BY id LIMIT " . rh_ai_num('ai_route_max'))->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m = json_decode((string)$r['meta_json'], true) ?: [];
            if (empty($m['target']) || !rh_ai_route_target_ok((string)$m['target'])) {
                continue; // a route that would now reach something it may not (a rule changed): never used
            }
            $ex = [];
            foreach ((array)($m['examples'] ?? []) as $e) {
                $ex[] = array_values(array_filter(explode(' ', (string)$e)));
            }
            $out[] = ['id' => (int)$r['id'], 'target' => (string)$m['target'], 'pattern' => (array)($m['pattern'] ?? []), 'examples' => $ex];
        }
    } catch (Throwable $e) {
        $out = [];
    }
    if (isset($GLOBALS['__rh_ai_memo'])) {
        $GLOBALS['__rh_ai_memo']['routes'] = $out;
    }
    return $out;
}

/** The best route for a question: ['route', 'score'] or null (below ai_route_min_pct). Deterministic. */
function rh_ai_route_match(PDO $db, string $tok): ?array
{
    $q = rh_ai_route_terms($tok);
    if (count(array_filter($q, static fn($w) => strpos($w, '_') === false)) < 2) {
        return null;
    }
    $best = null;
    foreach (rh_ai_routes_active($db) as $r) {
        foreach ($r['examples'] as $ex) {
            $s = rh_ai_route_similarity($q, $ex);
            if ($best === null || $s > $best['score'] + 1e-9) {
                $best = ['route' => $r, 'score' => $s];
            }
        }
    }
    return $best !== null && $best['score'] * 100 >= rh_ai_num('ai_route_min_pct') ? $best : null;
}

/**
 * Answer with a learned route, before any model call. $tok: the person's own words (tokenised, never typo-read).
 * null = no route fits, or it may not run here (permission, business type, the message reads as a change).
 */
function rh_ai_route_answer(PDO $db, array $user, int $conv, string $tok, string $raw, array $focus, int $pageBooking): ?array
{
    if (rh_ai_setting('ai_router_enabled', '1') === '0' || !rh_ai_memory_ready($db)) {
        return null;
    }
    if (function_exists('rh_ai_money_action_intent') && rh_ai_money_action_intent($raw) !== null) {
        return null;
    }
    $m = rh_ai_route_match($db, $tok);
    if ($m === null) {
        return null;
    }
    $r = $m['route'];
    if (strpos($r['target'], 'card:') === 0) {
        $key = substr($r['target'], 5);
        if (!rh_ai_action_allowed($user, $key)) {
            return null; // falls through: the usual path gives the usual answer
        }
        $res = rh_ai_guided_begin($db, $user, $conv, $key, $raw, mb_strtolower($raw), [], $focus, $pageBooking);
        return $res + ['learned_route' => $r['id']];
    }
    if (function_exists('rh_ai_text_is_write') && rh_ai_text_is_write($tok)) {
        return null; // a change is never answered by a lookup
    }
    $tool = $r['target'];
    if (!rh_ai_tool_allowed($user, $tool)) {
        return null;
    }
    $args = rh_ai_route_fill($r['pattern'], $tok);
    if ($args === null) {
        return null;
    }
    $args = rh_ai_vault_resolve_args($db, $conv, $args);
    $t = rh_ai_run_tool($db, $user, $conv, $tool, $args);
    if (!$t['ok']) {
        return null;
    }
    rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => $tool, 'args' => rh_ai_vault_protect($db, $conv, $args), 'result' => $t['json'], 'agent' => 'learned']);
    $text = rh_ai_template_render($tool, $args, (array)$t['result']);
    if ($text === '') {
        return null;
    }
    $db->prepare("UPDATE ai_memory SET uses = uses + 1, last_used_at = NOW() WHERE id = ?")->execute([$r['id']]);
    return ['text' => $text, 'links' => $t['links'], 'chips' => function_exists('rh_ai_followup_chips') ? rh_ai_followup_chips($user, [$tool]) : [], 'route' => 'learned', 'learned_route' => $r['id'],
        'local' => ['tool' => $tool, 'view' => ['args' => $args]]];
}

/**
 * The reply for a read lookup without the model: the lookup's own local wording where it has one, else the plain
 * template built from its result (every figure is the lookup's own). '' = nothing to say from it.
 */
function rh_ai_template_render(string $tool, array $args, array $result): string
{
    if (!in_array($tool, rh_ai_route_renderable(), true)) {
        return '';
    }
    $desk = ['booking_history' => static fn() => rh_ai_desk_history_text($result), 'upcoming_arrivals' => static fn() => rh_ai_desk_upcoming_text($result),
        'booking_holds' => static fn() => rh_ai_desk_holds_text($result), 'blocked_dates' => static fn() => rh_ai_desk_blocked_text($result),
        'staff_activity' => static fn() => rh_ai_desk_staff_text($result, ($args['from'] ?? '') === rh_ai_today() ? 'today' : 'in that time')];
    if (isset($desk[$tool])) {
        return empty($result['error']) ? trim((string)$desk[$tool]()) : '';
    }
    $text = function_exists('rh_ai_local_format') ? rh_ai_local_format($tool, ['args' => $args] + (isset($args['date']) ? ['date' => $args['date']] : []), $result) : '';
    if ($text === '' && function_exists('rh_ai_answer_from_results')) {
        $text = rh_ai_answer_from_results([[$tool, $result, $args]]);
    }
    return trim($text);
}

/** Retire a learned route (a Not right on its answer, or a question-set failure). */
function rh_ai_route_demote(PDO $db, int $id, string $why): void
{
    if ($id <= 0) {
        return;
    }
    try {
        $db->prepare("UPDATE ai_memory SET status = 'retired', losses = losses + 1 WHERE id = ? AND kind = 'route' AND status = 'active'")->execute([$id]);
        rh_ai_incident_add($db, 'route_demoted', 'A learned local answer was retired', ['route_id' => $id, 'why' => mb_substr($why, 0, 200)]);
    } catch (Throwable $e) {
    }
    unset($GLOBALS['__rh_ai_memo']['routes']);
}

/** "Forget this" on the usage page: the route is archived (kept for the record, never used again). */
function rh_ai_route_forget(PDO $db, int $id): bool
{
    $st = $db->prepare("UPDATE ai_memory SET status = 'archived' WHERE id = ? AND kind = 'route'");
    $st->execute([$id]);
    unset($GLOBALS['__rh_ai_memo']['routes']);
    return $st->rowCount() > 0;
}

/**
 * Answers given locally vs by the model since a date, the tokens they would have cost (the average model answer of the
 * same period), and the learned routes. For the usage page (administrators).
 */
function rh_ai_local_share(PDO $db, string $from): array
{
    $out = ['answers' => 0, 'local' => 0, 'model' => 0, 'share_pct' => null, 'avg_model_tokens' => 0, 'tokens_saved' => 0, 'routes' => []];
    try {
        $st = $db->prepare("SELECT SUM(tokens_in + tokens_out > 0) AS model, SUM(tokens_in + tokens_out = 0) AS local, COALESCE(SUM(tokens_in + tokens_out), 0) AS tok
                            FROM ai_messages m JOIN ai_conversations c ON c.id = m.conversation_id
                            WHERE m.role = 'assistant' AND m.created_at >= ? AND COALESCE(c.page_path, '') <> ?");
        $st->execute([$from, defined('RH_AI_CHECK_PAGE') ? RH_AI_CHECK_PAGE : ':assistant-check']);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['model'] = (int)($r['model'] ?? 0);
        $out['local'] = (int)($r['local'] ?? 0);
        $out['answers'] = $out['model'] + $out['local'];
        $out['share_pct'] = $out['answers'] > 0 ? round(100 * $out['local'] / $out['answers'], 1) : null;
        $out['avg_model_tokens'] = $out['model'] > 0 ? (int)round((int)$r['tok'] / $out['model']) : 0;
        $out['tokens_saved'] = $out['avg_model_tokens'] * $out['local'];
        foreach ($db->query("SELECT id, text, status, uses, created_at, last_used_at FROM ai_memory WHERE kind = 'route' AND status IN ('active', 'retired') ORDER BY status = 'active' DESC, uses DESC, id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $out['routes'][] = $x;
        }
    } catch (Throwable $e) {
    }
    return $out;
}

// ── Mistake signals: each becomes a lesson line and a regression question ───

/**
 * Record one mistake signal (owner 2026-10-29): kind = reply_not_right | absence_fixed | contradiction | false_refusal |
 * reask. Stores the question shape (privacy tokens only), the wrong path (route, lookups, the first words of the wrong
 * answer) and, once known, the right path. The daily review turns it into a lesson and a regression question.
 */
function rh_ai_mistake_signal(PDO $db, string $kind, int $conv, string $questionTok, array $wrong, array $refs = []): int
{
    if (!rh_ai_memory_ready($db) || !rh_ai_learning_on()) {
        return 0;
    }
    $ctx = ['question' => mb_substr($questionTok, 0, 300), 'shape' => implode(' ', array_slice(rh_ai_route_terms($questionTok), 0, 20)), 'wrong' => $wrong];
    return rh_ai_incident_add($db, $kind, 'Learning signal: ' . str_replace('_', ' ', $kind), $ctx, $refs + ['conversation_id' => $conv]);
}

/** The first sentence of a reply, privacy tokens out (what a regression question checks never comes back). */
function rh_ai_wrong_head(string $reply): string
{
    $s = trim((string)preg_split('/(?<=[.!?])\s+|\n+/u', trim(rh_ai_memory_strip_tokens(str_replace('*', '', $reply))))[0]);
    return mb_strlen($s) >= 12 ? mb_substr($s, 0, 80) : '';
}

/**
 * The daily part (inside rh_ai_reflect_run): each open signal gets its right path (the next answer in that chat that was
 * not marked Not right: its route and lookups) and a regression question that fails if the wrong answer comes back.
 * Bounded: at most 50 signals a day. @return array{signals:int,evals:int}
 */
function rh_ai_learn_from_signals(PDO $db): array
{
    $out = ['signals' => 0, 'evals' => 0];
    try {
        $rows = $db->query("SELECT id, kind, conversation_id, context_json, created_at FROM ai_incidents WHERE status = 'open'
                            AND kind IN ('reply_not_right', 'absence_fixed', 'contradiction', 'false_refusal', 'reask', 'claim_corrected') ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $out;
    }
    $next = $db->prepare("SELECT a.id, a.agent, a.tool_args_json FROM ai_messages a LEFT JOIN ai_feedback f ON f.message_id = a.id AND f.rating < 0
                          WHERE a.conversation_id = ? AND a.role = 'assistant' AND a.created_at >= ? AND f.id IS NULL ORDER BY a.id LIMIT 1 OFFSET 1");
    $tools = $db->prepare("SELECT DISTINCT tool_name FROM ai_messages WHERE conversation_id = ? AND role = 'tool' AND id < ? AND id > ? ORDER BY tool_name LIMIT 6");
    foreach ($rows as $r) {
        $c = json_decode((string)$r['context_json'], true) ?: [];
        $q = trim(rh_ai_memory_strip_tokens((string)($c['question'] ?? '')));
        if ($q === '') {
            continue;
        }
        $out['signals']++;
        if ((int)$r['conversation_id'] > 0) {
            $next->execute([(int)$r['conversation_id'], (string)$r['created_at']]);
            if ($n = $next->fetch(PDO::FETCH_ASSOC)) {
                $tools->execute([(int)$r['conversation_id'], (int)$n['id'], max(0, (int)$n['id'] - 40)]);
                $c['right'] = ['route' => (string)$n['agent'], 'tools' => array_values(array_filter($tools->fetchAll(PDO::FETCH_COLUMN), static fn($t) => strpos((string)$t, 'propose_') !== 0))];
                $db->prepare("UPDATE ai_incidents SET context_json = ? WHERE id = ?")->execute([mb_strcut(json_encode($c, JSON_UNESCAPED_UNICODE), 0, 8000), (int)$r['id']]);
            }
        }
        $head = (string)($c['wrong']['head'] ?? '');
        $expect = ['route_not' => ['error']];
        if ($r['kind'] === 'false_refusal') {
            $expect['not_contains'] = ["outside what your account can see"];
        } elseif ($head !== '') {
            $expect['not_contains'] = [$head];
        }
        if (empty($expect['not_contains'])) {
            continue;
        }
        if (rh_ai_eval_case_add($db, 'auto:' . $r['kind'] . ':' . (int)$r['id'], $q, $expect) > 0) {
            $out['evals']++;
        }
    }
    return $out;
}

/** A signal about the answer before this question (its route and first words): contradiction, re-ask. */
function rh_ai_signal_from_last(PDO $db, string $kind, int $conv, int $userMsgId, string $tok, int $uid): int
{
    try {
        $st = $db->prepare("SELECT content, agent FROM ai_messages WHERE conversation_id = ? AND role = 'assistant' AND id < ? ORDER BY id DESC LIMIT 1");
        $st->execute([$conv, $userMsgId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return 0;
    }
    if (!$a) {
        return 0;
    }
    return rh_ai_mistake_signal($db, $kind, $conv, $tok, ['route' => (string)$a['agent'], 'head' => rh_ai_wrong_head((string)$a['content'])], ['user_id' => $uid]);
}

/**
 * The person asks the same thing again in other words right after an answer (within ai_reask_seconds, most words shared,
 * not the same words): a sign the answer missed. The earlier question is the one learned from.
 */
function rh_ai_reask_check(PDO $db, int $conv, int $userMsgId, string $tok, int $uid): void
{
    try {
        $st = $db->prepare("SELECT content, created_at FROM ai_messages WHERE conversation_id = ? AND role = 'user' AND id < ? ORDER BY id DESC LIMIT 1");
        $st->execute([$conv, $userMsgId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;
    }
    if (!$p || time() - (int)strtotime((string)$p['created_at']) > rh_ai_num('ai_reask_seconds')) {
        return;
    }
    $a = array_values(array_filter(rh_ai_route_terms((string)$p['content']), static fn($w) => strpos($w, '_') === false));
    $b = array_values(array_filter(rh_ai_route_terms($tok), static fn($w) => strpos($w, '_') === false));
    if (count($a) < 3 || count($b) < 3 || $a === $b) {
        return;
    }
    if (rh_ai_route_similarity($a, $b) >= 0.5) {
        rh_ai_signal_from_last($db, 'reask', $conv, $userMsgId, (string)$p['content'], $uid);
    }
}
