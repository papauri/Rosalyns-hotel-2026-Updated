<?php

/**
 * Assistant orchestrator, Phase 2: one agent with read tools, cheapest route per answer
 * (.claude/AI_ASSISTANT_PLAN.md "Owner direction" - "Cheapest per answer - no waste").
 *
 * Order for every message:
 *   1. local, zero tokens: off-topic decline, out-of-scope decline (owner wording), the answer cache
 *      (same question, same person and permission set, within two minutes), and exact lookups answered
 *      straight from a read tool (arrivals, departures, in-house, room status, who is online, my access);
 *   2. Fast model (cheapest) with the person's read tools, effort low - it may hand over with [[ESCALATE]];
 *   3. Primary model only for reasoning / analysis / planning, effort high.
 * Every outgoing request is de-identified (vault) and given a last safety pass; replies are turned back
 * into real values locally. Tokens and estimated cost are written per message and per day.
 * Phase 3 (the swarm) builds on rh_ai_turn(); Phase 4 adds action cards.
 */

require_once __DIR__ . '/ai-core.php';
require_once __DIR__ . '/ai-providers.php';
require_once __DIR__ . '/ai-vault.php';
require_once __DIR__ . '/ai-tools.php';
require_once __DIR__ . '/ai-context.php';

if (!defined('RH_AI_HISTORY_TURNS')) {
    define('RH_AI_HISTORY_TURNS', 12);
}
if (!defined('RH_AI_ANSWER_CACHE_SECONDS')) {
    define('RH_AI_ANSWER_CACHE_SECONDS', 120);
}
if (!defined('RH_AI_MAX_TOOL_ROUNDS')) {
    define('RH_AI_MAX_TOOL_ROUNDS', 5);
}

// ── Routing ──────────────────────────────────────────────────────────────────

/** Plainly not about the hotel or this system. */
function rh_ai_is_off_topic(string $t): bool
{
    $t = mb_strtolower($t);
    return (bool)preg_match('/\b(write|compose|make up|tell)\b.{0,30}\b(poem|song|lyrics|rap|joke|story|essay|novel|haiku)\b|\b(homework|assignment for school|python|javascript|java code|c\+\+|write (me )?(some )?code|football|soccer score|premier league|election|politic\w*|religio\w*|bitcoin|crypto\w*|stock market|forex|lottery|betting tips|dating advice|horoscope|celebrity)\b/u', $t);
}

/**
 * Areas a person may only ask about with the matching permission (any-of). Owner rule: one polite
 * line, no figure, no hint, no workaround - checked before any tool or model runs.
 */
function rh_ai_scope_areas(): array
{
    return [
        'money' => [
            'perms' => ['accounting', 'payments', 'reports', 'pos_accounting', 'invoices'],
            're' => [
                '/\b(revenue|takings|turnover|income|profit|loss(es)?|earnings|earned|banked|cash.?up|end of day|eod|adr|revpar|average daily rate|vat (collected|owed|return)|levy collected|sales (figures?|report|total))\b/u',
                '/\bhow much (money )?(did|have|has) (we|the hotel|the restaurant|the bar) (make|made|take|taken|earn|earned|bring|brought)\b/u',
                '/\b(total|sum|add up|added up|altogether|combined|average|averages|compare|comparison)\b.{0,60}\b(paid|payments?|money|amounts? paid|revenue|takings|income|spent|spend|cash|card|mobile money|refunds?)\b/u',
                '/\b(payments?|money|amounts? paid|refunds?)\b.{0,60}\b(total|sum|add up|altogether|combined|average|this (week|month|year)|last (week|month|year))\b/u',
                '/\b(refunds?|credit notes?|invoices? (total|register)|receipts? total)\b/u',
            ],
        ],
        'stock' => [
            'perms' => ['stock_dashboard', 'stock_management', 'stock_reports', 'stock_count'],
            're' => ['/\b(stock levels?|in stock|inventory|ingredients?|wastage|stock count|stock variance|purchase orders?|suppliers?)\b/u'],
        ],
        'staff' => [
            'perms' => ['user_management'],
            're' => ['/\b(salar(y|ies)|payroll|wages|staff (list|accounts?)|user accounts?|passwords?|who has (access|permission))\b/u'],
        ],
    ];
}

/** '' when the person may ask this; otherwise the area name they may not see. */
function rh_ai_scope_block(array $user, string $tokenisedText): string
{
    $t = mb_strtolower($tokenisedText);
    $uid = (int)$user['id'];
    foreach (rh_ai_scope_areas() as $area => $def) {
        $hit = false;
        foreach ($def['re'] as $re) {
            if (preg_match($re, $t)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            continue;
        }
        // One booking's own total/balance is visible on the booking screen: not an aggregate.
        if ($area === 'money' && preg_match('/\[bk_\d+\]|\bbooking (id |number |#)?\d+\b/u', $t) && !preg_match('/\b(revenue|takings|turnover|income|profit|eod|end of day)\b/u', $t)) {
            continue;
        }
        foreach ($def['perms'] as $p) {
            if (hasPermission($uid, $p)) {
                continue 2;
            }
        }
        return $area;
    }
    return '';
}

/** Needs reasoning (Primary model) rather than a lookup (Fast model). */
function rh_ai_needs_reasoning(string $t): bool
{
    $t = mb_strtolower($t);
    return (bool)preg_match('/\b(why|analy[sz]e|analysis|strategy|strategi\w*|plan (for|out)|forecast|trend|trends|improve|increase|lift|boost|grow|reduce|cut|optimi[sz]e|recommend|suggestions?|should (we|i)|what if|compare|pros and cons|best way|explain why)\b/u', $t)
        && mb_strlen($t) > 12;
}

/**
 * Exact lookups answered locally from one read tool (no model, no tokens).
 * @return array{0:string,1:array}|null [tool, args]
 */
function rh_ai_local_intent(string $t): ?array
{
    $t = trim(mb_strtolower($t));
    if (mb_strlen($t) > 70 || rh_ai_needs_reasoning($t) || preg_match('/\b(tomorrow|yesterday|next|last|on \d|\d{4}-\d{2}-\d{2}|monday|tuesday|wednesday|thursday|friday|saturday|sunday|weekend)\b/u', $t)) {
        return null;
    }
    if (preg_match('/^(show |list |who are |who is |what are )?(the |today\'?s |todays )?(arrivals?|check.?ins?)( (for |due )?today)?\??$|^who(\'s| is) (arriving|checking in)( today)?\??$/u', $t)) {
        return ['arrivals_departures', ['_view' => 'arrivals']];
    }
    if (preg_match('/^(show |list |who are |what are )?(the |today\'?s |todays )?(departures?|check.?outs?)( (for |due )?today)?\??$|^who(\'s| is) (leaving|checking out|due out)( today)?\??$/u', $t)) {
        return ['arrivals_departures', ['_view' => 'departures']];
    }
    if (preg_match('/^(who(\'s| is) (in.?house|staying( with us)?( now| tonight)?)|in.?house( guests)?( now)?|(show |list )?(the )?in.?house( guests| list)?)\??$/u', $t)) {
        return ['in_house', []];
    }
    if (preg_match('/^(show |what(\'s| is) the )?(room (board|status(es)?)|rooms? status)\??$|^which rooms are (not ready|dirty|being cleaned|ready|clean)\??$/u', $t)) {
        return ['room_board', []];
    }
    if (preg_match('/^who(\'s| is) (online|logged in|signed in)( now)?\??$/u', $t)) {
        return ['who_is_online', []];
    }
    if (preg_match('/^(what can i (do|see|access)( here)?|my (access|permissions)|what am i allowed to (do|see))\??$/u', $t)) {
        return ['my_access', []];
    }
    return null;
}

/** Format a local lookup (from the de-identified result, so the stored text holds tokens only). */
function rh_ai_local_format(string $tool, array $view, array $r): string
{
    $room = static function (array $x): string {
        return ($x['room_type'] ?? '') . (!empty($x['room_number']) ? ' · room ' . $x['room_number'] : ' · no room yet');
    };
    $cur = (string)($r['currency'] ?? '');
    $fmt = static fn($v) => ($cur !== '' ? $cur . ' ' : '') . number_format((float)$v, 2);
    if ($tool === 'arrivals_departures' && ($view['_view'] ?? '') === 'departures') {
        $d = $r['departures'];
        $out = '**Departures today: ' . (int)$d['total'] . '** (' . (int)$d['due_out'] . ' still due out, ' . (int)$d['checked_out'] . ' checked out)';
        foreach ($d['list'] as $x) {
            $owe = isset($x['balance_due']) && (float)$x['balance_due'] > BALANCE_TOLERANCE ? ' · owes ' . $fmt($x['balance_due']) : '';
            $out .= "\n- " . $x['guest_name'] . ' · ' . $room($x) . ' · ' . str_replace('-', ' ', (string)$x['status']) . $owe;
        }
        if (!empty($r['overdue_departures'])) {
            $out .= "\n\n" . (int)$r['overdue_departures'] . ' guest(s) are past their check-out date and still checked in.';
        }
        return $out;
    }
    if ($tool === 'arrivals_departures') {
        $a = $r['arrivals'];
        $out = '**Arrivals today: ' . (int)$a['total'] . '** (' . (int)$a['still_to_arrive'] . ' still to arrive' . ((int)$a['no_room_assigned'] > 0 ? ', ' . (int)$a['no_room_assigned'] . ' with no room assigned' : '') . ')';
        foreach ($a['list'] as $x) {
            $out .= "\n- " . $x['guest_name'] . ' · ' . $room($x) . ' · ' . str_replace('-', ' ', (string)$x['status']) . ' · leaves ' . $x['leaves'];
        }
        if (!empty($r['overdue_arrivals'])) {
            $out .= "\n\n" . (int)$r['overdue_arrivals'] . ' earlier arrival(s) never checked in (possible no-shows).';
        }
        return $out;
    }
    if ($tool === 'in_house') {
        $out = '**In house now: ' . (int)$r['in_house'] . '**';
        foreach ($r['list'] as $x) {
            $owe = isset($x['balance_due']) && (float)$x['balance_due'] > BALANCE_TOLERANCE ? ' · owes ' . $fmt($x['balance_due']) : '';
            $out .= "\n- " . $x['guest_name'] . ' · ' . $room($x) . ' · leaves ' . $x['leaves'] . $owe;
        }
        return $out;
    }
    if ($tool === 'room_board') {
        $parts = [];
        foreach ((array)$r['by_status'] as $s => $n) {
            $parts[] = (int)$n . ' ' . str_replace('_', ' ', (string)$s);
        }
        $out = '**Rooms: ' . (int)$r['rooms'] . '** (' . implode(', ', $parts) . ')' . ($r['occupancy_pct'] !== null ? ' · occupancy ' . $r['occupancy_pct'] . '%' : '');
        foreach ($r['list'] as $x) {
            if (($x['status'] ?? '') === 'available' && empty($x['arrival_today'])) {
                continue; // list only the rooms that need attention or are in use
            }
            $out .= "\n- Room " . $x['room'] . ' · ' . str_replace('_', ' ', (string)$x['status']) . (!empty($x['guest_name']) ? ' · ' . $x['guest_name'] . ' leaves ' . $x['leaves'] : '') . (!empty($x['arrival_today']) ? ' · arrival today' : '');
        }
        return $out;
    }
    if ($tool === 'who_is_online') {
        $out = '**Online now: ' . (int)$r['online'] . '**';
        foreach ($r['list'] as $x) {
            $out .= "\n- " . $x['staff_name'] . ' (' . $x['role'] . ') · ' . $x['status'] . ' · ' . $x['screen'];
        }
        return $out;
    }
    if ($tool === 'my_access') {
        $out = 'You are signed in as **' . $r['role'] . '**. You can:';
        foreach ((array)$r['can_see_and_do'] as $cat => $labels) {
            $out .= "\n- **" . $cat . ':** ' . implode(', ', (array)$labels);
        }
        $out .= "\n\nAsk me about any of these. Anything else needs a manager or admin.";
        return $out;
    }
    return '';
}

/** Suggested next replies (tap chips) after an answer. */
function rh_ai_followup_chips(array $user, array $toolsUsed): array
{
    $tools = rh_ai_tools_for_user($user);
    $chips = [];
    $add = static function (string $tool, string $text) use (&$chips, $tools): void {
        if (isset($tools[$tool]) && !in_array($text, $chips, true)) {
            $chips[] = $text;
        }
    };
    foreach ($toolsUsed as $t) {
        if ($t === 'arrivals_departures') {
            $add('arrivals_departures', 'Who is due out today?');
            $add('room_board', 'Which rooms are not ready?');
        } elseif ($t === 'in_house') {
            $add('arrivals_departures', 'Who is due out today?');
        } elseif ($t === 'room_board') {
            $add('arrivals_departures', 'Arrivals today');
        } elseif ($t === 'check_availability') {
            $add('quote_stay', 'Quote that stay');
        } elseif ($t === 'how_to' || $t === 'summarise_guide') {
            $add('how_to', 'Show me the steps again');
        }
    }
    return array_slice($chips, 0, 3);
}

// ── Conversations, history, usage ────────────────────────────────────────────

/** The conversation if it belongs to this person (null otherwise). */
function rh_ai_conversation(PDO $db, int $uid, int $conv): ?array
{
    if ($conv <= 0) {
        return null;
    }
    $st = $db->prepare("SELECT * FROM ai_conversations WHERE id = ? AND user_id = ?");
    $st->execute([$conv, $uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function rh_ai_conversation_create(PDO $db, int $uid, string $page): int
{
    $db->prepare("INSERT INTO ai_conversations (user_id, title, page_path) VALUES (?, '', ?)")->execute([$uid, mb_substr(basename($page), 0, 120)]);
    return (int)$db->lastInsertId();
}

function rh_ai_message_add(PDO $db, int $conv, string $role, string $content, array $extra = []): int
{
    $db->prepare("INSERT INTO ai_messages (conversation_id, role, agent, content, tool_name, tool_args_json, tool_result_json, provider, model_id, tokens_in, tokens_out, cost_usd, latency_ms)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $conv, $role, $extra['agent'] ?? null, $content, $extra['tool_name'] ?? null,
            isset($extra['args']) ? mb_strcut(json_encode($extra['args'], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 8000) : null,
            isset($extra['result']) ? mb_strcut((string)$extra['result'], 0, 8192) : null,
            $extra['provider'] ?? null, $extra['model'] ?? null,
            (int)($extra['tokens_in'] ?? 0), (int)($extra['tokens_out'] ?? 0), (float)($extra['cost'] ?? 0), $extra['ms'] ?? null,
        ]);
    $db->prepare("UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?")->execute([$conv]);
    return (int)$db->lastInsertId();
}

/** Last turns (tokenised) after the latest summary, plus that summary. */
function rh_ai_history(PDO $db, int $conv, int $beforeId): array
{
    $sum = '';
    $upto = 0;
    $st = $db->prepare("SELECT content, tool_args_json FROM ai_messages WHERE conversation_id = ? AND role = 'summary' ORDER BY id DESC LIMIT 1");
    $st->execute([$conv]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $sum = (string)$r['content'];
        $upto = (int)(json_decode((string)$r['tool_args_json'], true)['upto'] ?? 0);
    }
    $st = $db->prepare("SELECT id, role, content FROM ai_messages WHERE conversation_id = ? AND role IN ('user', 'assistant') AND id > ? AND id < ? ORDER BY id DESC LIMIT " . (RH_AI_HISTORY_TURNS * 2));
    $st->execute([$conv, $upto, $beforeId]);
    $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    $msgs = [];
    foreach ($rows as $r) {
        if (trim((string)$r['content']) !== '') {
            $msgs[] = ['role' => $r['role'], 'content' => (string)$r['content']];
        }
    }
    while ($msgs && $msgs[0]['role'] !== 'user') {
        array_shift($msgs);
    }
    return ['summary' => $sum, 'messages' => $msgs];
}

/** The chosen models of the provider in use, by role. */
function rh_ai_role_models(PDO $db): array
{
    $prov = rh_ai_active_provider();
    $out = ['provider' => $prov];
    if ($prov === '') {
        return $out;
    }
    try {
        $st = $db->prepare("SELECT model_id, role, price_in_per_mtok, price_out_per_mtok FROM ai_models WHERE provider = ? AND is_selected = 1");
        $st->execute([$prov]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if ($m['role'] !== null && !isset($out[$m['role']])) {
                $out[$m['role']] = $m;
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Price of one model (per million tokens). */
function rh_ai_model_price(PDO $db, string $provider, string $model): array
{
    try {
        $st = $db->prepare("SELECT price_in_per_mtok, price_out_per_mtok FROM ai_models WHERE provider = ? AND model_id = ?");
        $st->execute([$provider, $model]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            return [$r['price_in_per_mtok'] !== null ? (float)$r['price_in_per_mtok'] : null, $r['price_out_per_mtok'] !== null ? (float)$r['price_out_per_mtok'] : null];
        }
    } catch (Throwable $e) {
    }
    return [null, null];
}

function rh_ai_record_usage(PDO $db, int $uid, string $provider, string $model, array $usage, float $cost): void
{
    $in = (int)($usage['in'] ?? 0) + (int)($usage['cache_read'] ?? 0) + (int)($usage['cache_write'] ?? 0);
    try {
        $db->prepare("INSERT INTO ai_usage_daily (day, user_id, provider, model_id, requests, tokens_in, tokens_out, cost_usd) VALUES (CURDATE(), ?, ?, ?, 1, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE requests = requests + 1, tokens_in = tokens_in + VALUES(tokens_in), tokens_out = tokens_out + VALUES(tokens_out), cost_usd = cost_usd + VALUES(cost_usd)")
            ->execute([$uid, $provider, $model, $in, (int)($usage['out'] ?? 0), $cost]);
    } catch (Throwable $e) {
        error_log('Assistant usage not recorded: ' . $e->getMessage());
    }
}

/** Plain reason this person may not send right now ('' = fine). Rate limit applies to every message. */
function rh_ai_rate_problem(PDO $db, int $uid): string
{
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM ai_messages m JOIN ai_conversations c ON c.id = m.conversation_id
                            WHERE c.user_id = ? AND m.role = 'user' AND m.created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() >= rh_ai_rate_limit_per_10min()) {
            return 'You have sent a lot of messages in the last 10 minutes. Please wait a few minutes and try again.';
        }
    } catch (Throwable $e) {
        return 'The Assistant could not check its limits just now. Please try again.';
    }
    return '';
}

/** Plain reason a model call is not allowed (daily token cap per person, monthly cost cap). '' = fine. */
function rh_ai_budget_problem(PDO $db, int $uid): string
{
    try {
        $cap = rh_ai_daily_token_cap();
        if ($cap > 0) {
            $st = $db->prepare("SELECT COALESCE(SUM(tokens_in + tokens_out), 0) FROM ai_usage_daily WHERE user_id = ? AND day = CURDATE()");
            $st->execute([$uid]);
            if ((int)$st->fetchColumn() >= $cap) {
                return 'You have reached your daily Assistant allowance. Quick lookups (arrivals, departures, in-house, room status) still work; everything else is back tomorrow.';
            }
        }
        $budget = rh_ai_monthly_budget_usd();
        if ($budget > 0) {
            $spent = (float)$db->query("SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage_daily WHERE day >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
            if ($spent >= $budget) {
                return 'The Assistant has reached this month\'s spending limit. Quick lookups still work; ask an administrator to raise the limit in Assistant Settings.';
            }
        }
    } catch (Throwable $e) {
        return 'The Assistant could not check its limits just now. Please try again.';
    }
    return '';
}

/** Short title for a new conversation (tokenised; shown rehydrated). */
function rh_ai_title_from(string $tokenised): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $tokenised));
    return mb_strlen($t) > 60 ? mb_substr($t, 0, 57) . '...' : $t;
}

/** Fold older turns into a rolling summary made by the Fast model (tokenised text only). */
function rh_ai_maybe_summarise(PDO $db, int $uid, int $conv, array $models): void
{
    $st = $db->prepare("SELECT tool_args_json FROM ai_messages WHERE conversation_id = ? AND role = 'summary' ORDER BY id DESC LIMIT 1");
    $st->execute([$conv]);
    $upto = (int)(json_decode((string)$st->fetchColumn(), true)['upto'] ?? 0);
    $st = $db->prepare("SELECT id, role, content FROM ai_messages WHERE conversation_id = ? AND role IN ('user', 'assistant') AND id > ? ORDER BY id");
    $st->execute([$conv, $upto]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) <= (RH_AI_HISTORY_TURNS + 4) || empty($models['provider'])) {
        return;
    }
    $old = array_slice($rows, 0, count($rows) - 8);
    $prev = '';
    $st = $db->prepare("SELECT content FROM ai_messages WHERE conversation_id = ? AND role = 'summary' ORDER BY id DESC LIMIT 1");
    $st->execute([$conv]);
    $prev = (string)$st->fetchColumn();
    $text = ($prev !== '' ? "Earlier summary: $prev\n\n" : '');
    foreach ($old as $r) {
        $text .= ($r['role'] === 'user' ? 'Staff: ' : 'Assistant: ') . mb_substr((string)$r['content'], 0, 600) . "\n";
    }
    $m = $models['fast'] ?? ($models['primary'] ?? null);
    if (!$m) {
        return;
    }
    $req = ['provider' => $models['provider'], 'model' => $m['model_id'], 'max_tokens' => 300, 'effort' => 'low',
        'system_frozen' => 'Summarise this hotel staff chat in under 100 words: facts, figures, decisions and open questions only. Copy tokens like [GUEST_1] exactly.',
        'messages' => [['role' => 'user', 'content' => $text]]];
    $req = rh_ai_vault_final_pass($db, $conv, $req);
    $r = rh_ai_chat($req);
    if ($r['error'] !== '' || trim($r['text']) === '') {
        return;
    }
    [$pi, $po] = [$m['price_in_per_mtok'] !== null ? (float)$m['price_in_per_mtok'] : null, $m['price_out_per_mtok'] !== null ? (float)$m['price_out_per_mtok'] : null];
    $cost = rh_ai_cost_usd($pi, $po, $r['usage']);
    rh_ai_record_usage($db, $uid, $models['provider'], $m['model_id'], $r['usage'], $cost);
    rh_ai_message_add($db, $conv, 'summary', rh_ai_scrub_outbound($db, $conv, trim($r['text'])), [
        'args' => ['upto' => (int)end($old)['id']], 'provider' => $models['provider'], 'model' => $m['model_id'],
        'tokens_in' => $r['usage']['in'] + $r['usage']['cache_read'] + $r['usage']['cache_write'], 'tokens_out' => $r['usage']['out'], 'cost' => $cost, 'ms' => $r['ms'],
    ]);
}

// ── One turn ─────────────────────────────────────────────────────────────────

/**
 * Answer one message. $in: conversation_id, message, page, booking_id.
 * @return array{ok:bool, conversation_id:int, reply:string, route:string, model:string, links:array, chips:array, tokens_in:int, tokens_out:int, cost_usd:float, notice:string, error:string}
 */
function rh_ai_turn(PDO $db, array $user, array $in): array
{
    $uid = (int)$user['id'];
    $out = ['ok' => false, 'conversation_id' => 0, 'reply' => '', 'route' => '', 'model' => '', 'links' => [], 'chips' => [], 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0.0, 'notice' => '', 'error' => ''];
    if (!rh_ai_enabled($db) || !rh_ai_chat_ready($db)) {
        $out['error'] = 'The Assistant is switched off.';
        return $out;
    }
    if (!hasPermission($uid, 'ai_assistant_use')) {
        $out['error'] = 'You do not have access to the Assistant.';
        return $out;
    }
    $message = trim((string)($in['message'] ?? ''));
    if ($message === '' || mb_strlen($message) > 2000) {
        $out['error'] = $message === '' ? 'Type a question first.' : 'That message is too long (2,000 characters at most).';
        return $out;
    }
    $page = basename((string)($in['page'] ?? ''));
    $bookingId = max(0, (int)($in['booking_id'] ?? 0));
    $conv = (int)($in['conversation_id'] ?? 0);
    if ($conv > 0 && !rh_ai_conversation($db, $uid, $conv)) {
        $out['error'] = 'That conversation was not found.';
        return $out;
    }
    $new = $conv <= 0;
    if ($new) {
        $conv = rh_ai_conversation_create($db, $uid, $page);
    }
    $out['conversation_id'] = $conv;

    $tok = rh_ai_scrub_outbound($db, $conv, $message);
    $userMsgId = rh_ai_message_add($db, $conv, 'user', $tok, ['args' => ['page' => $page, 'booking_id' => $bookingId]]);
    if ($new) {
        $db->prepare("UPDATE ai_conversations SET title = ? WHERE id = ?")->execute([rh_ai_title_from($tok), $conv]);
    }

    $finish = static function (string $tokReply, string $route, array $extra = []) use ($db, $conv, $user, &$out): array {
        $links = (array)($extra['links'] ?? []);
        $chips = (array)($extra['chips'] ?? []);
        rh_ai_message_add($db, $conv, 'assistant', $tokReply, [
            'agent' => $route, 'args' => ['links' => $links, 'chips' => $chips, 'route' => $route, 'notice' => (string)($extra['notice'] ?? '')],
            'provider' => $extra['provider'] ?? null, 'model' => $extra['model'] ?? null,
            'tokens_in' => $extra['tokens_in'] ?? 0, 'tokens_out' => $extra['tokens_out'] ?? 0, 'cost' => $extra['cost'] ?? 0, 'ms' => $extra['ms'] ?? null,
        ]);
        $out['ok'] = true;
        $out['reply'] = rh_ai_vault_rehydrate($db, $conv, $tokReply);
        $out['route'] = $route;
        $out['model'] = (string)($extra['model'] ?? '');
        $out['links'] = $links;
        $out['chips'] = $chips;
        $out['tokens_in'] = (int)($extra['tokens_in'] ?? 0);
        $out['tokens_out'] = (int)($extra['tokens_out'] ?? 0);
        $out['cost_usd'] = (float)($extra['cost'] ?? 0);
        $out['notice'] = (string)($extra['notice'] ?? '');
        return $out;
    };

    // 1a. Off-topic: one line, no tokens
    if (rh_ai_is_off_topic($tok)) {
        return $finish(rh_ai_off_topic_line(), 'declined');
    }
    // 1b. Outside this person's permissions: owner's one line, no figure, no tokens
    if (rh_ai_scope_block($user, $tok) !== '') {
        return $finish(rh_ai_out_of_scope_line(), 'declined', ['chips' => ['What can I do here?']]);
    }
    // 1c. Same question asked moments ago in this chat (same person, permission set and screen).
    // Kept per conversation so the cached reply's tokens always belong to this chat's vault.
    $norm = trim(preg_replace('/[^\p{L}\p{N} ]+/u', '', mb_strtolower($tok)));
    $answerKey = rh_ai_cache_key($user, 'answer:' . $conv . ':' . $page . ':' . $bookingId, $norm);
    $hit = rh_ai_cache_get($db, $answerKey);
    if ($hit !== null && isset($hit['reply_tok'])) {
        return $finish((string)$hit['reply_tok'], 'cache', ['links' => (array)($hit['links'] ?? []), 'chips' => (array)($hit['chips'] ?? [])]);
    }
    // 1d. Exact lookups straight from a read tool
    $intent = rh_ai_local_intent($tok);
    if ($intent !== null) {
        [$tool, $view] = $intent;
        if (!rh_ai_tool_allowed($user, $tool)) {
            return $finish(rh_ai_out_of_scope_line(), 'declined', ['chips' => ['What can I do here?']]);
        }
        $r = rh_ai_run_tool($db, $user, $conv, $tool, []);
        if ($r['ok']) {
            $text = rh_ai_local_format($tool, $view, $r['result']);
            if ($text !== '') {
                rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => $tool, 'args' => [], 'result' => $r['json'], 'agent' => 'local']);
                $chips = rh_ai_followup_chips($user, [$tool]);
                rh_ai_cache_put($db, $answerKey, $uid, ['reply_tok' => $text, 'links' => $r['links'], 'chips' => $chips], RH_AI_ANSWER_CACHE_SECONDS);
                return $finish($text, 'local', ['links' => $r['links'], 'chips' => $chips]);
            }
        }
    }

    // 2/3. A model is needed
    $budget = rh_ai_budget_problem($db, $uid);
    if ($budget !== '') {
        return $finish($budget, 'limit');
    }
    $models = rh_ai_role_models($db);
    $primary = $models['primary'] ?? null;
    $fast = $models['fast'] ?? null;
    if (!$primary && !$fast) {
        $out['error'] = 'No model is chosen for the Assistant yet. Ask an administrator to finish Assistant Settings.';
        return $out;
    }
    $reason = rh_ai_needs_reasoning($tok);
    $route = ($reason || !$fast) ? 'primary' : 'fast';
    $prefs = rh_ai_prefs_get($db, $uid);
    $hist = rh_ai_history($db, $conv, $userMsgId);
    $specs = rh_ai_tool_specs($user);
    $frozen = rh_ai_context_frozen($db);
    $userBlock = rh_ai_context_user($db, $conv, $user, $prefs);

    $messages = $hist['messages'];
    $messages[] = ['role' => 'user', 'content' => $tok];
    $totIn = 0;
    $totOut = 0;
    $totCost = 0.0;
    $totMs = 0;
    $links = [];
    $toolsUsed = [];
    $notice = '';
    $usedModel = '';
    $final = null;
    $escalated = false;

    for ($round = 0; $round <= RH_AI_MAX_TOOL_ROUNDS; $round++) {
        $m = $route === 'fast' ? $fast : ($primary ?? $fast);
        $note = $route === 'fast'
            ? 'ROUTE: quick lookup. If answering well needs deeper analysis, planning or several steps of reasoning, reply with exactly [[ESCALATE]] and nothing else.'
            : 'ROUTE: analysis. Think it through, use the tools for every figure, then give a short, practical answer with the reasons.';
        $req = [
            'provider' => $models['provider'], 'model' => $m['model_id'],
            'system_frozen' => $frozen, 'system_user' => $userBlock,
            'system_volatile' => rh_ai_context_volatile($db, $conv, $user, $page, $bookingId, $hist['summary'], $note),
            'messages' => $messages, 'tools' => $round < RH_AI_MAX_TOOL_ROUNDS ? $specs : [],
            'max_tokens' => $route === 'fast' ? 1024 : 4096, 'effort' => $route === 'fast' ? 'low' : 'high',
        ];
        $req = rh_ai_vault_final_pass($db, $conv, $req);
        $fallback = $models['fallback']['model_id'] ?? null;
        $r = rh_ai_chat_resilient($req, $fallback);
        $usedModel = (string)$r['model'];
        $totMs += (int)$r['ms'];
        if ($r['error'] === '' || array_sum($r['usage']) > 0) {
            [$pi, $po] = rh_ai_model_price($db, $models['provider'], $usedModel);
            $cost = rh_ai_cost_usd($pi, $po, $r['usage']);
            rh_ai_record_usage($db, $uid, $models['provider'], $usedModel, $r['usage'], $cost);
            $totIn += $r['usage']['in'] + $r['usage']['cache_read'] + $r['usage']['cache_write'];
            $totOut += $r['usage']['out'];
            $totCost += $cost;
        }
        if (!empty($r['used_fallback'])) {
            $notice = 'Running on the backup model.';
        }
        if ($r['error'] !== '') {
            $final = 'Sorry - the Assistant could not answer just now. ' . $r['error'];
            break;
        }
        if ($r['stop'] === 'refusal') {
            $final = "I can't help with that one.";
            break;
        }
        if ($r['tool_calls']) {
            $messages[] = ['role' => 'assistant', 'content' => $r['text'], 'tool_calls' => $r['tool_calls']];
            foreach (array_slice($r['tool_calls'], 0, 6) as $c) {
                $tr = rh_ai_run_tool($db, $user, $conv, (string)$c['name'], (array)$c['args']);
                $toolsUsed[] = (string)$c['name'];
                foreach ($tr['links'] as $l) {
                    if (count($links) < 3 && !in_array($l, $links, true)) {
                        $links[] = $l;
                    }
                }
                rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => (string)$c['name'], 'args' => rh_ai_vault_protect($db, $conv, (array)$c['args']), 'result' => $tr['json'], 'agent' => $route]);
                $messages[] = ['role' => 'tool', 'tool_call_id' => (string)$c['id'], 'name' => (string)$c['name'], 'content' => $tr['json']];
            }
            foreach (array_slice($r['tool_calls'], 6) as $c) { // every call needs an answer, even the ones not run
                $messages[] = ['role' => 'tool', 'tool_call_id' => (string)$c['id'], 'name' => (string)$c['name'], 'content' => '{"error":"Too many lookups at once; ask again."}'];
            }
            continue;
        }
        $text = trim($r['text']);
        if ($route === 'fast' && strpos($text, '[[ESCALATE]]') !== false && $primary && !$escalated) {
            $escalated = true;
            $route = 'primary';
            continue;
        }
        $text = trim(str_replace('[[ESCALATE]]', '', $text));
        if ($r['stop'] === 'max_tokens') {
            $text .= "\n\n(The answer was cut short - ask me to continue.)";
        }
        $final = $text !== '' ? $text : "I couldn't find an answer to that. Try asking it another way.";
        break;
    }
    if ($final === null) {
        $final = 'That needed more lookups than I can do in one go. Try a narrower question.';
    }
    $final = rh_ai_scrub_outbound($db, $conv, $final); // stored tokenised (model text should already be)
    $chips = rh_ai_followup_chips($user, array_unique($toolsUsed));
    $res = $finish($final, $route, ['links' => $links, 'chips' => $chips, 'provider' => $models['provider'], 'model' => $usedModel,
        'tokens_in' => $totIn, 'tokens_out' => $totOut, 'cost' => round($totCost, 6), 'ms' => $totMs, 'notice' => $notice]);
    if (strpos($final, 'Sorry - the Assistant could not answer') !== 0) {
        rh_ai_cache_put($db, $answerKey, $uid, ['reply_tok' => $final, 'links' => $links, 'chips' => $chips], RH_AI_ANSWER_CACHE_SECONDS);
    }
    try {
        rh_ai_maybe_summarise($db, $uid, $conv, $models);
    } catch (Throwable $e) {
        error_log('Assistant summary skipped: ' . $e->getMessage());
    }
    return $res;
}

/** Messages of one conversation for the panel (real values restored locally). */
function rh_ai_conversation_messages(PDO $db, int $conv): array
{
    $st = $db->prepare("SELECT id, role, content, tool_args_json, created_at FROM ai_messages WHERE conversation_id = ? AND role IN ('user', 'assistant') ORDER BY id DESC LIMIT 60");
    $st->execute([$conv]);
    $out = [];
    foreach (array_reverse($st->fetchAll(PDO::FETCH_ASSOC)) as $r) {
        $meta = json_decode((string)$r['tool_args_json'], true) ?: [];
        $out[] = [
            'id' => (int)$r['id'], 'role' => $r['role'], 'text' => rh_ai_vault_rehydrate($db, $conv, (string)$r['content']),
            'links' => $r['role'] === 'assistant' ? array_values((array)($meta['links'] ?? [])) : [],
            'notice' => $r['role'] === 'assistant' ? (string)($meta['notice'] ?? '') : '',
            'at' => (string)$r['created_at'],
        ];
    }
    return $out;
}
