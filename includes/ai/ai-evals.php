<?php

/**
 * The Assistant's fixed question set and its runner (.claude/AI_ASSISTANT_PLAN.md "Local eval set",
 * "Continuous train & test + completion guardrail", Phase 4b).
 *
 *  - Questions: includes/ai/evals.json (shipped) + ai_eval_cases (regression questions added when a fault is
 *    marked fixed, source = incident). Expected answers are worked out from the hotel's own data at run
 *    time (figure_from runs the same read tool), so the set never goes stale.
 *  - A run asks each question as the system's first active administrator through the normal chat path
 *    (rh_ai_turn: same routing, tools, vault and reply-check), with "eval mode" on: the tokens count
 *    against the monthly limit (user 0), never against a person's daily allowance, and nothing is learned
 *    from it. Cards a question prepares are expired at once; the run's chats are archived and their
 *    privacy vault removed. Questions that need a model are skipped when none can be used.
 *  - Every run is logged to ai_eval_runs with the pass rate and tokens per answer. Without migration 072
 *    nothing runs (there is nowhere to log it) and nothing errors.
 */

require_once __DIR__ . '/ai-agents.php';

/** The shipped questions (evals.json), decoded once. */
function rh_ai_eval_seed_cases(): array
{
    static $cases = null;
    if ($cases !== null) {
        return $cases;
    }
    $j = json_decode((string)@file_get_contents(__DIR__ . '/evals.json'), true);
    $cases = [];
    foreach ((array)($j['cases'] ?? []) as $c) {
        if (is_array($c) && !empty($c['id']) && isset($c['q'])) {
            $cases[] = ['id' => (string)$c['id'], 'q' => (string)$c['q'], 'smoke' => !empty($c['smoke']), 'model' => !empty($c['model']),
                'page' => (string)($c['page'] ?? 'dashboard.php'), 'expect' => (array)($c['expect'] ?? []), 'source' => 'seed'];
        }
    }
    return $cases;
}

/**
 * The questions for a run. $set: full | smoke | model (only the model questions, for a model upgrade check).
 * Regression questions from ai_eval_cases are part of full and model runs.
 */
function rh_ai_eval_cases(PDO $db, string $set = 'full'): array
{
    $cases = rh_ai_eval_seed_cases();
    if ($set !== 'smoke' && rh_ai_memory_ready($db)) {
        try {
            foreach ($db->query("SELECT case_key, question, expect_json, smoke FROM ai_eval_cases WHERE active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $exp = json_decode((string)$r['expect_json'], true) ?: [];
                $cases[] = ['id' => (string)$r['case_key'], 'q' => (string)$r['question'], 'smoke' => (int)$r['smoke'] === 1, 'model' => !empty($exp['model']),
                    'page' => 'dashboard.php', 'expect' => $exp, 'source' => 'incident'];
            }
        } catch (Throwable $e) {
        }
    }
    if ($set === 'smoke') {
        $cases = array_values(array_filter($cases, static fn($c) => $c['smoke']));
    } elseif ($set === 'model') {
        $cases = array_values(array_filter($cases, static fn($c) => $c['model']));
    }
    return $cases;
}

/**
 * Add a regression question (source = incident). The question is stored without privacy tokens (they belong
 * to one chat). A case with expect.tool_ok checks that one read tool runs without an error instead.
 * Returns the case id (0 = not added).
 */
function rh_ai_eval_case_add(PDO $db, string $key, string $question, array $expect, int $faultId = 0): int
{
    if (!rh_ai_memory_ready($db)) {
        return 0;
    }
    $question = trim(preg_replace('/\s+/u', ' ', rh_ai_memory_strip_tokens($question)));
    if ($question === '' && empty($expect['tool_ok'])) {
        return 0;
    }
    try {
        $db->prepare("INSERT INTO ai_eval_cases (case_key, question, expect_json, smoke, source, fault_id) VALUES (?, ?, ?, 0, 'incident', ?)
                      ON DUPLICATE KEY UPDATE question = VALUES(question), expect_json = VALUES(expect_json), active = 1")
            ->execute([mb_substr($key, 0, 80), mb_substr($question !== '' ? $question : '(tool check)', 0, 600), json_encode($expect, JSON_UNESCAPED_UNICODE), $faultId ?: null]);
        $st = $db->prepare("SELECT id FROM ai_eval_cases WHERE case_key = ?");
        $st->execute([mb_substr($key, 0, 80)]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('Assistant regression question not added: ' . $e->getMessage());
        return 0;
    }
}

/** The person the checks run as: the first active administrator (null when there is none). */
function rh_ai_system_user(PDO $db): ?array
{
    try {
        $u = $db->query("SELECT id, role, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $u ? ['id' => (int)$u['id'], 'role' => (string)$u['role'], 'username' => (string)$u['username'], 'full_name' => (string)($u['full_name'] ?? '')] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Can model questions run now? (a chosen model, cloud providers on, a key, monthly limit not reached) */
function rh_ai_eval_model_ready(PDO $db): bool
{
    $m = rh_ai_role_models($db);
    if (empty($m['primary']) && empty($m['fast'])) {
        return false;
    }
    if (!rh_ai_cloud_on()) {
        return false;
    }
    if (rh_ai_get_key((string)$m['provider'], $db) === '' && rh_ai_fake_name() === '' && !is_callable($GLOBALS['__rh_ai_transport'] ?? null)) {
        return false;
    }
    $budget = rh_ai_monthly_budget_usd();
    return $budget <= 0 || rh_ai_month_spent($db) < $budget;
}

/** A value inside a nested array by dotted path ("arrivals.total"). */
function rh_ai_eval_path(array $a, string $path)
{
    foreach (explode('.', $path) as $k) {
        if (!is_array($a) || !array_key_exists($k, $a)) {
            return null;
        }
        $a = $a[$k];
    }
    return $a;
}

/** Arguments for figure_from: relative dates ("+1 day") become YYYY-MM-DD. */
function rh_ai_eval_args(array $args): array
{
    foreach ($args as $k => $v) {
        if (is_string($v) && preg_match('/^[+-]\d+ days?$/', $v)) {
            $args[$k] = date('Y-m-d', strtotime($v));
        }
    }
    return $args;
}

/** Run one read tool's handler directly (no cache, no vault) for checks. */
function rh_ai_eval_tool(PDO $db, array $user, string $tool, array $args): ?array
{
    $def = rh_ai_tool_registry()[$tool] ?? null;
    if ($def === null || !rh_ai_tool_allowed($user, $tool, $def)) {
        return null;
    }
    [$ok, $clean] = rh_ai_tool_validate($def['schema'], rh_ai_eval_args($args));
    if (!$ok) {
        return null;
    }
    return (array)call_user_func($GLOBALS['__rh_ai_tool_override'][$tool] ?? $def['handler'], $db, $user, $clean);
}

/**
 * Check one answer against its expectations. @return array{0:string,1:string} [pass|fail|skip, why]
 */
function rh_ai_eval_check(PDO $db, array $user, array $case, array $res): array
{
    $e = $case['expect'];
    $reply = (string)($res['reply'] ?? '');
    $route = (string)($res['route'] ?? '');
    if (empty($res['ok'])) {
        return ['fail', 'no answer: ' . mb_substr((string)($res['error'] ?? ''), 0, 120)];
    }
    foreach (array_merge(['could not answer just now', 'Something went wrong'], (array)($e['not_contains'] ?? [])) as $bad) {
        if ($bad !== '' && stripos($reply, (string)$bad) !== false) {
            return ['fail', 'the answer contains "' . $bad . '"'];
        }
    }
    if (!empty($e['route']) && !in_array($route, (array)$e['route'], true)) {
        return ['fail', 'answered by "' . $route . '", expected ' . implode(' or ', (array)$e['route'])];
    }
    if (!empty($e['route_not']) && in_array($route, (array)$e['route_not'], true)) {
        return ['fail', 'answered by "' . $route . '"'];
    }
    if (!empty($e['no_card']) && !empty($res['cards'])) {
        return ['fail', 'it prepared a card'];
    }
    if (!empty($e['contains_any'])) {
        $hit = false;
        foreach ((array)$e['contains_any'] as $w) {
            if (stripos($reply, (string)$w) !== false) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return ['fail', 'the answer does not mention any of: ' . implode(', ', (array)$e['contains_any'])];
        }
    }
    if (!empty($e['max_tokens']) && ((int)$res['tokens_in'] + (int)$res['tokens_out']) > (int)$e['max_tokens']) {
        return ['fail', 'used ' . ((int)$res['tokens_in'] + (int)$res['tokens_out']) . ' tokens'];
    }
    if (!empty($e['figure_from']['tool'])) {
        $f = $e['figure_from'];
        $r = rh_ai_eval_tool($db, $user, (string)$f['tool'], (array)($f['args'] ?? []));
        if ($r === null) {
            return ['skip', 'the lookup is not available here'];
        }
        $v = rh_ai_eval_path($r, (string)($f['path'] ?? ''));
        if (!is_numeric($v)) {
            return ['fail', 'the lookup gave no figure at ' . (string)($f['path'] ?? '')];
        }
        $want = (string)(int)round((float)$v);
        if (!preg_match('/(?<![\d.,])' . preg_quote($want, '/') . '(?![\d])/', $reply)) {
            return ['fail', 'the answer does not show ' . $want . ' (the screen figure)'];
        }
    }
    return ['pass', ''];
}

/**
 * Run the question set. $kind: full | smoke | upgrade. $opt: daily (bool, counts for the completion
 * guardrail), set (full|smoke|model; default from kind), role_override (role => model row: test a model
 * before switching), no_pause (bool), user (array: run as this person).
 * @return array{ran:bool,why:string,id:int,kind:string,total:int,run:int,passed:int,failed:int,skipped:int,pass_pct:?float,passed_ids:array,failed_ids:array,smoke_run:int,smoke_passed:int,smoke_pct:?float,tokens_total:int,tokens_per_answer:int,cost_usd:float,details:array,model:string}
 */
function rh_ai_eval_run(PDO $db, string $kind = 'full', array $opt = []): array
{
    $out = ['ran' => false, 'why' => '', 'id' => 0, 'kind' => $kind, 'total' => 0, 'run' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0, 'pass_pct' => null,
        'passed_ids' => [], 'failed_ids' => [], 'smoke_run' => 0, 'smoke_passed' => 0, 'smoke_pct' => null, 'tokens_total' => 0, 'tokens_per_answer' => 0, 'cost_usd' => 0.0, 'details' => [], 'model' => ''];
    if (!rh_ai_memory_ready($db)) {
        $out['why'] = 'The database update for the Assistant\'s learning (072) has not been run.';
        return $out;
    }
    if (!rh_ai_enabled($db)) {
        $out['why'] = 'The Assistant is off.';
        return $out;
    }
    $user = $opt['user'] ?? rh_ai_system_user($db);
    if (!$user) {
        $out['why'] = 'There is no active administrator to run the checks as.';
        return $out;
    }
    $set = (string)($opt['set'] ?? ($kind === 'upgrade' ? 'model' : ($kind === 'smoke' ? 'smoke' : 'full')));
    $cases = rh_ai_eval_cases($db, $set);
    $prevOverride = $GLOBALS['__rh_ai_role_override'] ?? null;
    if (!empty($opt['role_override'])) {
        $GLOBALS['__rh_ai_role_override'] = (array)$opt['role_override'];
    }
    $models = rh_ai_role_models($db);
    $out['model'] = (string)(($models['primary']['model_id'] ?? '') ?: ($models['fast']['model_id'] ?? ''));
    $modelOk = rh_ai_eval_model_ready($db);
    $started = date('Y-m-d H:i:s');
    $convs = [];
    $GLOBALS['__rh_ai_eval_mode'] = true;
    try {
        foreach ($cases as $c) {
            $out['total']++;
            $id = $c['id'];
            if (!empty($c['expect']['tool_ok'])) { // regression check for a lookup that used to fail
                $tool = (string)$c['expect']['tool_ok'];
                try {
                    $r = rh_ai_eval_tool($db, $user, $tool, rh_ai_selftest_args($db, $tool));
                    $st = $r === null ? ['skip', 'not available here'] : ['pass', ''];
                } catch (Throwable $ex) {
                    $st = ['fail', 'the lookup failed: ' . mb_substr($ex->getMessage(), 0, 120)];
                }
                $res = ['tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0, 'route' => 'tool'];
            } elseif ($c['model'] && !$modelOk) {
                $st = ['skip', 'no model can be used right now'];
                $res = ['tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0, 'route' => ''];
            } elseif (!rh_ai_enabled($db)) {
                $st = ['skip', 'the Assistant paused during the run'];
                $res = ['tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0, 'route' => ''];
            } else {
                try {
                    rh_ai_vault_people_reset();
                    $res = rh_ai_turn($db, $user, ['conversation_id' => 0, 'message' => $c['q'], 'page' => $c['page']]);
                    if (!empty($res['conversation_id'])) {
                        $convs[] = (int)$res['conversation_id'];
                        // a card a check prepared is never left waiting
                        $db->prepare("UPDATE ai_actions SET status = 'expired' WHERE conversation_id = ? AND status = 'proposed'")->execute([(int)$res['conversation_id']]);
                    }
                    $st = rh_ai_eval_check($db, $user, $c, $res);
                } catch (Throwable $ex) {
                    $res = ['tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0, 'route' => 'error'];
                    $st = ['fail', 'error: ' . mb_substr($ex->getMessage(), 0, 120)];
                }
            }
            $tokens = (int)($res['tokens_in'] ?? 0) + (int)($res['tokens_out'] ?? 0);
            if ($st[0] === 'skip') {
                $out['skipped']++;
            } else {
                $out['run']++;
                $out['tokens_total'] += $tokens;
                $out['cost_usd'] += (float)($res['cost_usd'] ?? 0);
                if ($st[0] === 'pass') {
                    $out['passed']++;
                    $out['passed_ids'][] = $id;
                } else {
                    $out['failed']++;
                    $out['failed_ids'][] = $id;
                }
                if ($c['smoke']) {
                    $out['smoke_run']++;
                    $out['smoke_passed'] += $st[0] === 'pass' ? 1 : 0;
                }
            }
            $out['details'][$id] = ['status' => $st[0], 'why' => $st[1], 'route' => (string)($res['route'] ?? ''), 'tokens' => $tokens];
        }
    } finally {
        unset($GLOBALS['__rh_ai_eval_mode']);
        if ($prevOverride === null) {
            unset($GLOBALS['__rh_ai_role_override']);
        } else {
            $GLOBALS['__rh_ai_role_override'] = $prevOverride;
        }
        if ($convs) { // the run's chats are hidden and their privacy vault removed (like a sign-out)
            $in = implode(',', array_fill(0, count($convs), '?'));
            try {
                $db->prepare("UPDATE ai_conversations SET archived = 1, title = 'Assistant check' WHERE id IN ($in)")->execute($convs);
                $db->prepare("DELETE FROM ai_vault WHERE conversation_id IN ($in)")->execute($convs);
            } catch (Throwable $e) {
            }
            rh_ai_vault_forget();
        }
    }
    $out['ran'] = true;
    $out['pass_pct'] = $out['run'] > 0 ? round($out['passed'] * 100 / $out['run'], 2) : null;
    $out['smoke_pct'] = $out['smoke_run'] > 0 ? round($out['smoke_passed'] * 100 / $out['smoke_run'], 2) : null;
    $out['tokens_per_answer'] = $out['run'] > 0 ? (int)round($out['tokens_total'] / $out['run']) : 0;
    $out['cost_usd'] = round($out['cost_usd'], 6);
    try {
        $db->prepare("INSERT INTO ai_eval_runs (kind, run_date, is_daily, provider, model_id, total, run, passed, failed, skipped, pass_pct, tokens_total, tokens_per_answer, cost_usd, passed_ids, failed_ids, details_json, started_at, finished_at)
                      VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$kind, !empty($opt['daily']) ? 1 : 0, (string)($models['provider'] ?? ''), $out['model'], $out['total'], $out['run'], $out['passed'], $out['failed'], $out['skipped'],
                $out['pass_pct'], $out['tokens_total'], $out['tokens_per_answer'], $out['cost_usd'], implode(',', $out['passed_ids']), implode(',', $out['failed_ids']),
                json_encode($out['details'], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), $started]);
        $out['id'] = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('Assistant check run not logged: ' . $e->getMessage());
    }
    // Kill switch 6: the smoke questions falling below the threshold pause the Assistant.
    if ($kind !== 'upgrade' && empty($opt['no_pause']) && $out['smoke_run'] >= 3 && $out['smoke_pct'] !== null
        && $out['smoke_pct'] < (float)rh_ai_setting('ai_eval_smoke_min_pct', '80')) {
        rh_ai_pause($db, 'eval', 'the daily check scored ' . rtrim(rtrim(number_format($out['smoke_pct'], 1), '0'), '.') . '% on its five key questions (below ' . (int)rh_ai_setting('ai_eval_smoke_min_pct', '80') . '%).');
    }
    return $out;
}

/** Safe sample arguments for a read tool's self-test (dates ahead, the first room type, harmless words). */
function rh_ai_selftest_args(PDO $db, string $tool): array
{
    $def = rh_ai_tool_registry()[$tool] ?? null;
    if ($def === null) {
        return [];
    }
    static $room = null;
    if ($room === null) {
        try {
            $room = (string)(int)$db->query("SELECT id FROM rooms WHERE is_active = 1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
        } catch (Throwable $e) {
            $room = '1';
        }
    }
    $args = [];
    foreach ((array)($def['schema']['required'] ?? []) as $k) {
        $p = (array)($def['schema']['properties'][$k] ?? []);
        if ($k === 'check_in') {
            $args[$k] = date('Y-m-d', strtotime('+30 days'));
        } elseif ($k === 'check_out') {
            $args[$k] = date('Y-m-d', strtotime('+32 days'));
        } elseif (($p['format'] ?? '') === 'date') {
            $args[$k] = date('Y-m-d');
        } elseif ($k === 'room_type') {
            $args[$k] = $room;
        } elseif (($p['type'] ?? '') === 'integer') {
            $args[$k] = $k === 'adults' ? 2 : max(1, (int)($p['minimum'] ?? 1));
        } elseif (!empty($p['enum'])) {
            $args[$k] = (string)$p['enum'][0];
        } else {
            $args[$k] = in_array($k, ['query', 'q', 'topic', 'question'], true) ? 'check in' : 'test';
        }
    }
    return $args;
}
