<?php

/**
 * Assistant self-healing and the daily checks (.claude/AI_ASSISTANT_PLAN.md "Self-healing", "Learning loop",
 * "Continuous train & test + completion guardrail", Phase 4b + the owner add "no cron dependency").
 *
 * The daily checks run without cron: the first admin page load of the day by ANY signed-in account (the
 * Assistant panel include calls rh_ai_daily_maybe()) schedules them AFTER the page has been sent
 * (fastcgi_finish_request / litespeed_finish_request), or, where that is not available, starts a
 * fire-and-forget background request to admin/api/ai-daily.php with a single-use token. Single flight: a
 * MySQL lock plus an atomic "claimed today" setting. Every step is wrapped: a failure is logged quietly and
 * never reaches the page. The scripts in scripts/ai-*.php run the same steps from cron when wanted.
 *
 * Daily steps: privacy vault sweep (chats of signed-out people), model list refresh when a key is saved and
 * the last refresh was before today (once per calendar day; then a model upgrade check with the question set as the gate),
 * the nightly review (lessons, playbooks, scores, fault write-ups), the health check (provider test, every
 * read tool's self-test, budget) and the full question set, which feeds the completion guardrail.
 * Code is never changed here: problems that need a code fix are written up for the owner (ai_faults).
 */

require_once __DIR__ . '/ai-evals.php';
require_once __DIR__ . '/ai-catalog.php';

// ── Job bookkeeping (in a setting, so it works before migration 072) ─────────

function rh_ai_jobs(): array
{
    $j = json_decode(rh_ai_setting('ai_jobs_json', ''), true);
    return is_array($j) ? $j : [];
}

function rh_ai_job_record(PDO $db, string $job, bool $ok, string $note): void
{
    try {
        $j = rh_ai_jobs();
        $j[$job] = ['ts' => time(), 'at' => date('Y-m-d H:i'), 'ok' => $ok, 'note' => mb_substr($note, 0, 240)];
        rh_ai_save_settings(['ai_jobs_json' => json_encode($j, JSON_UNESCAPED_UNICODE)], $db);
    } catch (Throwable $e) {
        error_log('Assistant job not recorded: ' . $e->getMessage());
    }
}

/** A daily job has not run in the last 24 hours (5 minutes' grace so a cron at the same time each day still runs). */
function rh_ai_job_due(string $job, int $every = 86400): bool
{
    $j = rh_ai_jobs()[$job] ?? null;
    return !is_array($j) || time() - (int)($j['ts'] ?? 0) >= $every - 300;
}

// ── The daily trigger (no cron needed) ───────────────────────────────────────

/** On, and today's checks have not been claimed yet. */
function rh_ai_daily_is_due(PDO $db): bool
{
    return rh_ai_enabled($db) && rh_ai_setting('ai_daily_ran_on', '') !== date('Y-m-d');
}

/**
 * Called on every admin page (from the panel include) for ANY signed-in account. Costs one settings read when
 * nothing is due. When the day's checks are due it only schedules them; they run after the page is sent.
 * Returns 'after_response', 'background' or ''. Never throws, never prints.
 */
function rh_ai_daily_maybe(PDO $db): string
{
    static $done = false;
    $test = !empty($GLOBALS['__rh_ai_daily_test']); // tests: decide, but schedule nothing
    if ($done && !$test) {
        return '';
    }
    $done = true;
    try {
        if ((PHP_SAPI === 'cli' && !$test) || !rh_ai_daily_is_due($db)) {
            return '';
        }
        if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
            if (!$test) {
                register_shutdown_function('rh_ai_daily_after_response');
            }
            return 'after_response';
        }
        $token = rh_ai_daily_token_new($db);
        if ($token === '') {
            return '';
        }
        $GLOBALS['__rh_ai_daily_kick'] = $token;
        if (!$test) {
            register_shutdown_function('rh_ai_daily_kick');
        }
        return 'background';
    } catch (Throwable $e) {
        error_log('Assistant daily check not scheduled: ' . $e->getMessage());
        return '';
    }
}

/** Shutdown hook (PHP-FPM / LiteSpeed): close the visitor's connection first, then run the checks. */
function rh_ai_daily_after_response(): void
{
    try {
        $db = $GLOBALS['pdo'] ?? null;
        if (!$db instanceof PDO) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close(); // never hold the person's session while the checks run
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        @ignore_user_abort(true);
        @set_time_limit(300);
        rh_ai_daily_run($db);
    } catch (Throwable $e) {
        error_log('Assistant daily check failed: ' . $e->getMessage());
    }
}

/** The background endpoint for this install (admin/api/ai-daily.php), from the current admin page's path. */
function rh_ai_daily_endpoint_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'))), '/');
    $p = strpos($dir . '/', '/admin/');
    if ($p !== false) {
        $dir = substr($dir, 0, $p) . '/admin';
    }
    return $dir . '/api/ai-daily.php';
}

/**
 * Shutdown hook where the connection cannot be closed early: send one request to our own server and hang up
 * at once (fire and forget). The endpoint keeps running on its own (ignore_user_abort).
 */
function rh_ai_daily_kick(): void
{
    try {
        $token = (string)($GLOBALS['__rh_ai_daily_kick'] ?? '');
        if ($token === '') {
            return;
        }
        $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        $port = (int)($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $addr = (string)($_SERVER['SERVER_ADDR'] ?? '');
        if ($addr === '' || $addr === '0.0.0.0' || $addr === '::' || $addr === '::1') {
            $addr = '127.0.0.1';
        }
        $body = http_build_query(['token' => $token]);
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => (string)preg_replace('/:\d+$/', '', $host)]]);
        $fp = @stream_socket_client(($https ? 'ssl://' : 'tcp://') . $addr . ':' . $port, $errno, $errstr, 0.5, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            error_log('Assistant daily check could not start in the background: ' . $errstr);
            return;
        }
        stream_set_timeout($fp, 1);
        fwrite($fp, 'POST ' . rh_ai_daily_endpoint_path() . " HTTP/1.1\r\nHost: " . $host . "\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fclose($fp);
    } catch (Throwable $e) {
        error_log('Assistant daily check could not start: ' . $e->getMessage());
    }
}

/**
 * A single-use token for the background request (stored hashed, 5 minutes). A new one is made at most every
 * 30 minutes, so a server that cannot call itself never slows page after page. '' = not now.
 */
function rh_ai_daily_token_new(PDO $db): string
{
    $parts = explode('|', rh_ai_setting('ai_daily_token', '')) + ['', '0', '0'];
    if ((int)$parts[2] > time() - 1800) {
        return '';
    }
    $t = bin2hex(random_bytes(24));
    rh_ai_save_settings(['ai_daily_token' => hash('sha256', $t) . '|' . (time() + 300) . '|' . time()], $db);
    return $t;
}

/** Use up a background-request token (true once, within 5 minutes). */
function rh_ai_daily_token_take(PDO $db, string $token): bool
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return false;
    }
    try {
        $cur = (string)(rh_ai_settings($db, true)['ai_daily_token'] ?? '');
        $p = explode('|', $cur) + ['', '0', '0'];
        if ($p[0] === '' || (int)$p[1] < time() || !hash_equals($p[0], hash('sha256', $token))) {
            return false;
        }
        $st = $db->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'ai_daily_token' AND setting_value = ?");
        $st->execute(['|0|' . $p[2], $cur]);
        rh_ai_settings($db, true);
        return $st->rowCount() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/** Claim today's checks (atomic: only one request of the day gets true). */
function rh_ai_daily_claim(PDO $db): bool
{
    $today = date('Y-m-d');
    $db->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group) VALUES ('ai_daily_ran_on', '', 'ai')")->execute();
    $st = $db->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'ai_daily_ran_on' AND setting_value <> ?");
    $st->execute([$today, $today]);
    rh_ai_settings($db, true);
    return $st->rowCount() === 1;
}

/**
 * The day's checks. Single flight (MySQL lock + claim). $force (scripts, "Run the checks now") runs every
 * step whatever its last run. Each step is wrapped; failures are logged quietly.
 * @return array{status:string,jobs:array<string,array>}
 */
function rh_ai_daily_run(PDO $db, bool $force = false): array
{
    $out = ['status' => 'ran', 'jobs' => []];
    $lock = false;
    try {
        $lock = (int)$db->query("SELECT GET_LOCK('rh_ai_daily', 0)")->fetchColumn() === 1;
        if (!$lock) {
            return ['status' => 'locked', 'jobs' => []];
        }
        if (!rh_ai_enabled($db)) {
            return ['status' => 'off', 'jobs' => []];
        }
        if (!$force && !rh_ai_daily_claim($db)) {
            return ['status' => 'already', 'jobs' => []];
        }
        $steps = [
            'vault'   => static fn() => rh_ai_vault_sweep($db),
            'models'  => static fn() => rh_ai_daily_models($db, $force),
            'reflect' => static fn() => rh_ai_reflect_run($db),
            'health'  => static fn() => rh_ai_health_run($db),
            'eval'    => static fn() => rh_ai_daily_eval($db),
            // Phases 4c + 5 (no cron needed): tamper check of confirmed cards, budget notices, weekly digest, retention
            'audit'     => static fn() => rh_ai_audit_report($db),
            'digest'    => static fn() => rh_ai_digest_run($db, false),
            'retention' => static fn() => rh_ai_retention_run($db),
        ];
        foreach ($steps as $job => $fn) {
            if (!$force && in_array($job, ['reflect', 'health', 'eval'], true) && !rh_ai_job_due($job)) {
                $out['jobs'][$job] = ['ok' => true, 'note' => 'ran in the last 24 hours', 'skipped' => true];
                continue;
            }
            try {
                $r = (array)$fn();
                $out['jobs'][$job] = $r;
                rh_ai_job_record($db, $job, (bool)($r['ok'] ?? true), (string)($r['note'] ?? ''));
            } catch (Throwable $e) {
                error_log('Assistant daily step ' . $job . ' failed: ' . $e->getMessage());
                if (function_exists('rh_log_event')) {
                    rh_log_event('assistant', 'warning', 'Assistant daily step failed', ['step' => $job, 'error' => mb_substr($e->getMessage(), 0, 200)]);
                }
                $out['jobs'][$job] = ['ok' => false, 'note' => 'failed (see the system log)'];
                rh_ai_job_record($db, $job, false, 'failed (see the system log)');
            }
        }
    } catch (Throwable $e) {
        error_log('Assistant daily checks failed: ' . $e->getMessage());
        $out['status'] = 'error';
    } finally {
        if ($lock) {
            try {
                $db->query("SELECT RELEASE_LOCK('rh_ai_daily')");
            } catch (Throwable $e) {
            }
        }
    }
    return $out;
}

// ── Vault sweep ──────────────────────────────────────────────────────────────

/**
 * Privacy vault of chats whose owner's session has ended (idle sign-out is 8 hours: no portal activity and no
 * chat activity for 8 hours) is removed, like a sign-out; unconfirmed cards past their time expire; expired
 * cache rows go. Old chats then show "(guest)" in place of names.
 */
function rh_ai_vault_sweep(PDO $db): array
{
    $n = 0;
    try {
        $st = $db->prepare("DELETE v FROM ai_vault v JOIN ai_conversations c ON c.id = v.conversation_id
                            LEFT JOIN admin_presence p ON p.user_id = c.user_id
                            WHERE c.updated_at < DATE_SUB(NOW(), INTERVAL 8 HOUR) AND (p.user_id IS NULL OR p.last_seen_at < DATE_SUB(NOW(), INTERVAL 8 HOUR))");
        $st->execute();
        $n = $st->rowCount();
    } catch (Throwable $e) {
        try { // no presence table on this install: chat activity alone decides
            $st = $db->prepare("DELETE v FROM ai_vault v JOIN ai_conversations c ON c.id = v.conversation_id WHERE c.updated_at < DATE_SUB(NOW(), INTERVAL 8 HOUR)");
            $st->execute();
            $n = $st->rowCount();
        } catch (Throwable $e2) {
            return ['ok' => false, 'note' => 'vault sweep failed'];
        }
    }
    $exp = 0;
    try {
        $st = $db->prepare("UPDATE ai_actions SET status = 'expired' WHERE status = 'proposed' AND expires_at < NOW()");
        $st->execute();
        $exp = $st->rowCount();
        $db->exec("DELETE FROM ai_tool_cache WHERE expires_at < NOW() LIMIT 5000");
    } catch (Throwable $e) {
    }
    rh_ai_vault_forget();
    return ['ok' => true, 'note' => $n . ' privacy value(s) removed from ended sessions, ' . $exp . ' old card(s) expired'];
}

// ── Model list refresh + upgrade (eval-gated) ────────────────────────────────

/** Refresh the model lists once per calendar day when a key is saved (last refresh before today, or never). */
function rh_ai_daily_models(PDO $db, bool $force = false): array
{
    $has = false;
    foreach (rh_ai_provider_states($db) as $p => $st) {
        if ($st['has_key'] && rh_ai_is_provider($p)) {
            $has = true;
        }
    }
    if (!$has) {
        return ['ok' => true, 'note' => 'No provider key saved: nothing to refresh.'];
    }
    if (!rh_ai_cloud_on()) {
        return ['ok' => true, 'note' => 'Cloud providers are switched off: not refreshed.'];
    }
    $last = strtotime(rh_ai_setting('ai_models_refreshed_at', '')) ?: 0;
    if (!$force && $last > 0 && date('Y-m-d', $last) === date('Y-m-d')) {
        return ['ok' => true, 'note' => 'Model lists were already checked today (' . date('H:i', $last) . ').'];
    }
    $run = rh_ai_model_refresh_run($db);
    $bad = [];
    foreach ($run['results'] as $p => $r) {
        if (!$r['ok']) {
            $bad[] = $p;
            if (function_exists('rh_log_event')) {
                rh_log_event('ai_model_refresh', 'warning', 'Assistant model list refresh failed', ['provider' => $p, 'message' => $r['message']]);
            }
        }
    }
    $up = rh_ai_model_upgrade_check($db);
    return ['ok' => !$bad, 'note' => 'Model lists checked' . ($bad ? ' (failed: ' . implode(', ', $bad) . ')' : '') . '. ' . $up['note']];
}

/**
 * A newer model in the same family as a chosen one (with known prices) is tried on the model questions; it
 * takes the role only if it scores at least as well AND either scores higher or costs no more (a change that
 * raises cost without raising the score is rejected). One role per check. Logged, one-click undo.
 * @return array{changed:bool,note:string}
 */
function rh_ai_model_upgrade_check(PDO $db): array
{
    $prov = rh_ai_active_provider();
    if ($prov === '' || !rh_ai_cloud_on() || !rh_ai_memory_ready($db)) {
        return ['changed' => false, 'note' => ''];
    }
    $sel = rh_ai_role_models($db);
    $models = rh_ai_models_for($db, $prov);
    foreach (['primary', 'fast', 'fallback'] as $role) {
        $cur = $sel[$role] ?? null;
        if (!$cur) {
            continue;
        }
        $curRow = null;
        foreach ($models as $m) {
            if ($m['model_id'] === $cur['model_id']) {
                $curRow = $m;
            }
        }
        if (!$curRow) {
            continue;
        }
        $fam = rh_ai_model_family((string)$cur['model_id']);
        $cand = null;
        foreach ($models as $m) {
            if ((int)$m['is_selected'] === 0 && !rh_ai_model_banned((string)$m['model_id']) && rh_ai_model_family((string)$m['model_id']) === $fam && (float)$m['capability_rank'] > (float)$curRow['capability_rank']
                && $m['price_in_per_mtok'] !== null && $m['price_out_per_mtok'] !== null && (!$cand || (float)$m['capability_rank'] > (float)$cand['capability_rank'])) {
                $cand = $m;
            }
        }
        if (!$cand) {
            continue;
        }
        $a = rh_ai_eval_run($db, 'upgrade', ['no_pause' => true]);
        $b = rh_ai_eval_run($db, 'upgrade', ['no_pause' => true, 'role_override' => [$role => ['model_id' => $cand['model_id'], 'role' => $role, 'price_in_per_mtok' => $cand['price_in_per_mtok'], 'price_out_per_mtok' => $cand['price_out_per_mtok']]]]);
        $log = ['role' => $role, 'from' => (string)$cur['model_id'], 'to' => (string)$cand['model_id'], 'at' => date('Y-m-d H:i'),
            'from_pct' => $a['pass_pct'], 'to_pct' => $b['pass_pct'], 'from_cost' => $a['cost_usd'], 'to_cost' => $b['cost_usd'], 'from_tokens' => $a['tokens_per_answer'], 'to_tokens' => $b['tokens_per_answer']];
        if ($a['pass_pct'] === null || $b['pass_pct'] === null) {
            return ['changed' => false, 'note' => 'A newer model (' . $cand['model_id'] . ') could not be checked: no model questions ran.'];
        }
        $better = $b['pass_pct'] > $a['pass_pct'] + 0.001;
        $same = abs($b['pass_pct'] - $a['pass_pct']) <= 0.001;
        $accept = $better || ($same && $b['cost_usd'] <= $a['cost_usd'] + 0.000001);
        $log['decision'] = $accept ? 'switched' : 'kept';
        if ($accept) {
            $roles = [];
            foreach ($models as $m) {
                if ((int)$m['is_selected'] === 1 && $m['role'] !== null) {
                    $roles[(string)$m['model_id']] = (string)$m['role'];
                }
            }
            unset($roles[(string)$cur['model_id']]);
            $roles[(string)$cand['model_id']] = $role;
            rh_ai_save_selection($db, $prov, $roles);
            rh_ai_notify_settings($db, 'model_upgrade', 'The Assistant now uses ' . $cand['model_id'] . ' as its ' . $role . ' model (it scored ' . $b['pass_pct'] . '% against ' . $a['pass_pct'] . '%). Undo it in Assistant Settings if needed.');
        }
        rh_ai_save_settings(['ai_model_upgrade_json' => json_encode($log, JSON_UNESCAPED_UNICODE)], $db);
        return ['changed' => $accept, 'note' => $accept ? 'Switched ' . $role . ' to ' . $cand['model_id'] . '.' : 'A newer model (' . $cand['model_id'] . ') did not do better, so nothing changed.'];
    }
    return ['changed' => false, 'note' => 'No newer model to try.'];
}

/** Undo the last automatic model switch (if both models are still listed). */
function rh_ai_model_upgrade_undo(PDO $db): array
{
    $log = json_decode(rh_ai_setting('ai_model_upgrade_json', ''), true);
    if (!is_array($log) || ($log['decision'] ?? '') !== 'switched' || !empty($log['undone'])) {
        return ['ok' => false, 'error' => 'There is no automatic model change to undo.'];
    }
    $prov = rh_ai_active_provider();
    $roles = [];
    foreach (rh_ai_models_for($db, $prov) as $m) {
        if ((int)$m['is_selected'] === 1 && $m['role'] !== null) {
            $roles[(string)$m['model_id']] = (string)$m['role'];
        }
    }
    if (($roles[(string)$log['to']] ?? '') !== (string)$log['role']) {
        return ['ok' => false, 'error' => 'The models were changed by hand since then, so there is nothing to undo.'];
    }
    unset($roles[(string)$log['to']]);
    $roles[(string)$log['from']] = (string)$log['role'];
    rh_ai_save_selection($db, $prov, $roles); // throws a plain message if the old model is no longer offered
    $log['undone'] = date('Y-m-d H:i');
    rh_ai_save_settings(['ai_model_upgrade_json' => json_encode($log, JSON_UNESCAPED_UNICODE)], $db);
    return ['ok' => true, 'error' => ''];
}

// ── The nightly review: lessons, playbooks, scores, fault write-ups ──────────

/**
 * Review the last two days' incidents and wins. Scores move notes in and out of use; a card type that keeps
 * failing is written up as a fault; the Fast model (tokenised text only) turns mistakes into at most 3 short
 * lessons; thumbs-up answers that used the same lookups become a playbook. Stops when learning is frozen or
 * the feature is complete.
 */
function rh_ai_reflect_run(PDO $db): array
{
    if (!rh_ai_memory_ready($db)) {
        return ['ok' => true, 'note' => 'Needs the learning database update (072).'];
    }
    if (!rh_ai_learning_on()) {
        return ['ok' => true, 'note' => 'Learning is frozen: nothing reviewed.'];
    }
    if (rh_ai_feature_complete()) {
        return ['ok' => true, 'note' => 'The feature is complete: no new lessons are written.'];
    }
    $out = ['ok' => true, 'lessons' => 0, 'playbooks' => 0, 'faults' => 0];
    $sc = rh_ai_memory_rescore($db);
    $inc = $db->query("SELECT id, kind, tool_name, summary, context_json FROM ai_incidents WHERE status = 'open' AND created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY) ORDER BY id LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    // A card type that failed 3+ times (not the "something is owed" guard) needs looking at.
    $fails = [];
    foreach ($inc as $i) {
        $c = json_decode((string)$i['context_json'], true) ?: [];
        if ($i['kind'] === 'action_failed' && empty($c['guard'])) {
            $fails[(string)($c['action'] ?? '')][] = mb_substr((string)($c['screen_message'] ?? ''), 0, 120);
        }
    }
    foreach ($fails as $a => $msgs) {
        if ($a !== '' && count($msgs) >= 3) {
            $label = rh_ai_action_registry()[$a]['label'] ?? $a;
            if (rh_ai_fault_add($db, 'action:' . $a, 'Assistant "' . $label . '" cards keep failing', count($msgs) . ' "' . $label . '" cards were confirmed but the screen did not save them. Screen messages: ' . implode(' | ', array_unique(array_filter($msgs))), ['action' => $a, 'failures' => count($msgs), 'messages' => array_values(array_unique($msgs))])) {
                $out['faults']++;
            }
        }
    }
    // Lessons from mistakes (Fast model, tokenised data only)
    $lines = [];
    foreach ($inc as $i) {
        if (!in_array($i['kind'], ['reply_not_right', 'action_failed', 'tool_breaker', 'absence_fixed', 'contradiction', 'false_refusal', 'reask'], true)) {
            continue;
        }
        $c = json_decode((string)$i['context_json'], true) ?: [];
        $bits = array_filter([(string)$i['summary'], isset($c['question']) ? 'question: ' . $c['question'] : '', isset($c['correction']) && $c['correction'] !== '' ? 'staff said: ' . $c['correction'] : '', isset($c['screen_message']) ? 'screen said: ' . $c['screen_message'] : '']);
        $lines[] = '- ' . mb_substr(rh_ai_memory_redact(implode('; ', $bits)), 0, 240);
        if (count($lines) >= 15) {
            break;
        }
    }
    if ($lines && rh_ai_eval_model_ready($db)) {
        $models = rh_ai_role_models($db);
        $m = $models['fast'] ?? ($models['primary'] ?? null);
        if ($m) {
            $req = rh_ai_vault_final_pass($db, 0, [
                'provider' => $models['provider'], 'model' => $m['model_id'], 'max_tokens' => 300, 'effort' => 'low',
                'system_frozen' => "You review a hotel staff Assistant's mistakes from the last day. Write at most 3 short lessons, one per line starting with '- ', each a fact about the hotel or a wording habit that would have avoided a mistake (for example: '- Check joined rooms before quoting the Family Suite.'). Never write rules about tools, permissions, limits, confirming, prices or amounts, and never name a person. If nothing is worth learning, reply NONE.",
                'messages' => [['role' => 'user', 'content' => implode("\n", $lines)]],
            ]);
            $r = rh_ai_chat($req);
            if ($r['error'] === '' || array_sum($r['usage']) > 0) {
                [$pi, $po] = rh_ai_model_price($db, (string)$models['provider'], (string)$r['model']);
                rh_ai_record_usage($db, 0, (string)$models['provider'], (string)$r['model'], $r['usage'], rh_ai_cost_usd($pi, $po, $r['usage']));
            }
            if ($r['error'] === '' && stripos(trim($r['text']), 'NONE') !== 0) {
                foreach (preg_split('/\r?\n/', (string)$r['text']) as $ln) {
                    $ln = trim(preg_replace('/^[-*\d.)\s]+/u', '', trim($ln)));
                    if ($ln === '' || $out['lessons'] >= 3) {
                        continue;
                    }
                    if (rh_ai_memory_add($db, ['kind' => 'lesson', 'scope' => 'hotel', 'text' => $ln, 'source' => 'reflect', 'source_ref' => 'review:' . date('Y-m-d')])['ok']) {
                        $out['lessons']++;
                    }
                }
            }
        }
    }
    $out['playbooks'] = rh_ai_reflect_playbooks($db);
    // Owner 2026-10-29: every mistake signal gets its right path and a regression question; the local router learns
    $sig = function_exists('rh_ai_learn_from_signals') ? rh_ai_learn_from_signals($db) : ['signals' => 0, 'evals' => 0];
    $rt = function_exists('rh_ai_route_train') ? rh_ai_route_train($db) : ['promoted' => 0, 'updated' => 0, 'note' => ''];
    $out['signals'] = $sig['signals'];
    $out['regression_questions'] = $sig['evals'];
    $out['routes'] = $rt['promoted'];
    if ($inc) {
        $ids = array_map('intval', array_column($inc, 'id'));
        $db->prepare("UPDATE ai_incidents SET status = 'reviewed', reviewed_at = NOW() WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")->execute($ids);
    }
    $out['note'] = count($inc) . ' incident(s) reviewed; ' . $out['lessons'] . ' lesson(s), ' . $out['playbooks'] . ' playbook(s), ' . $out['faults'] . ' fault write-up(s); '
        . $sc['promoted'] . ' note(s) promoted, ' . $sc['retired'] . ' retired; ' . $sig['evals'] . ' regression question(s) from ' . $sig['signals'] . ' signal(s); ' . (string)$rt['note'];
    return $out;
}

/**
 * Thumbs-up answers from the last 14 days that needed the Primary model (or the team) and used the same
 * lookups become a playbook: "questions like these: look up X then Y, answer in two or three lines". A matched
 * playbook later lets the Fast model answer (cheaper). Returns the number of playbooks added.
 */
function rh_ai_reflect_playbooks(PDO $db): int
{
    $rows = $db->query("SELECT f.message_id, m.conversation_id FROM ai_feedback f JOIN ai_messages m ON m.id = f.message_id
                        WHERE f.rating = 1 AND f.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND m.agent IN ('primary', 'swarm') ORDER BY f.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $groups = [];
    $q = $db->prepare("SELECT id, content FROM ai_messages WHERE conversation_id = ? AND role = 'user' AND id < ? ORDER BY id DESC LIMIT 1");
    $t = $db->prepare("SELECT tool_name FROM ai_messages WHERE conversation_id = ? AND role = 'tool' AND id > ? AND id < ? AND (agent IS NULL OR agent NOT LIKE 'specialist:%') ORDER BY id");
    foreach ($rows as $r) {
        $q->execute([(int)$r['conversation_id'], (int)$r['message_id']]);
        $um = $q->fetch(PDO::FETCH_ASSOC);
        if (!$um) {
            continue;
        }
        $t->execute([(int)$r['conversation_id'], (int)$um['id'], (int)$r['message_id']]);
        $tools = [];
        foreach ($t->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $n = (string)$n;
            if ($n !== '' && $n !== 'consult_team' && strpos($n, 'propose_') !== 0 && isset(rh_ai_tool_registry()[$n]) && !in_array($n, $tools, true)) {
                $tools[] = $n;
            }
        }
        if (!$tools || count($tools) > 4) {
            continue;
        }
        $groups[implode('>', $tools)][] = rh_ai_memory_strip_tokens((string)$um['content']);
    }
    $added = 0;
    foreach ($groups as $sig => $qs) {
        $qs = array_values(array_unique(array_filter($qs)));
        if (count($qs) < 2) {
            continue;
        }
        $freq = [];
        foreach ($qs as $qq) {
            foreach (array_unique(rh_ai_memory_terms($qq)) as $w) {
                $freq[$w] = ($freq[$w] ?? 0) + 1;
            }
        }
        arsort($freq);
        $words = array_slice(array_keys(array_filter($freq, static fn($n) => $n >= 2)), 0, 6);
        if (!$words) {
            continue;
        }
        $tools = explode('>', $sig);
        $text = 'Questions about ' . implode(', ', $words) . ': look up ' . implode(', then ', $tools) . ', and answer in two or three lines with the figures those lookups give.';
        if (rh_ai_memory_add($db, ['kind' => 'playbook', 'scope' => 'hotel', 'text' => $text, 'source' => 'reflect', 'source_ref' => 'review:' . date('Y-m-d'),
            'meta' => ['tools' => $tools, 'questions' => array_slice($qs, 0, 5)]])['ok']) {
            $added++;
        }
    }
    return $added;
}

// ── Health check ─────────────────────────────────────────────────────────────

/** Last health check result (array; empty when none). */
function rh_ai_health_last(): array
{
    $h = json_decode(rh_ai_setting('ai_health_json', ''), true);
    return is_array($h) ? $h : [];
}

/**
 * Every read tool once, with safe sample arguments, as the first active administrator (handlers are called
 * directly: nothing is cached, nothing leaves the hotel). A tool that throws is a failure.
 * @return array{ok:int,failed:array<string,string>,skipped:array<int,string>}
 */
function rh_ai_tool_selftest(PDO $db, ?array $user = null): array
{
    $out = ['ok' => 0, 'failed' => [], 'skipped' => []];
    $user = $user ?? rh_ai_system_user($db);
    if (!$user) {
        $out['skipped'] = array_keys(rh_ai_tool_registry());
        return $out;
    }
    foreach (rh_ai_tool_registry() as $name => $def) {
        if (!rh_ai_tool_allowed($user, $name, $def)) {
            $out['skipped'][] = $name;
            continue;
        }
        [$ok, $clean] = rh_ai_tool_validate($def['schema'], rh_ai_selftest_args($db, $name));
        if (!$ok) {
            $out['skipped'][] = $name;
            continue;
        }
        try {
            call_user_func($GLOBALS['__rh_ai_tool_override'][$name] ?? $def['handler'], $db, $user, $clean);
            $out['ok']++;
        } catch (Throwable $e) {
            $out['failed'][$name] = mb_substr(rh_ai_memory_redact($e->getMessage()), 0, 200);
        }
    }
    return $out;
}

/** Daily health: a test call to the provider in use, every read tool's self-test, the monthly budget. */
function rh_ai_health_run(PDO $db): array
{
    $h = ['at' => date('Y-m-d H:i'), 'date' => date('Y-m-d'), 'providers' => [], 'tools' => [], 'budget' => [], 'ok' => true];
    $prov = rh_ai_active_provider();
    if ($prov !== '' && rh_ai_cloud_on()) {
        $models = rh_ai_role_models($db);
        $m = $models['fast'] ?? ($models['primary'] ?? null);
        $key = rh_ai_get_key($prov, $db);
        if ($m && $key !== '') {
            $p = rh_ai_ping($prov, $key, (string)$m['model_id']);
            $key = '';
            rh_ai_record_test($prov, $p['ok'], (int)$p['ms'], $p['ok'] ? 'Daily check: answered in ' . (int)$p['ms'] . ' ms.' : 'Daily check failed: ' . $p['message'], $db);
            $h['providers'][$prov] = ['ok' => $p['ok'], 'ms' => (int)$p['ms'], 'message' => mb_substr((string)$p['message'], 0, 160)];
            if (!$p['ok']) {
                $h['ok'] = false;
                rh_ai_incident_add($db, 'provider_check_failed', 'The daily provider check failed', ['provider' => $prov, 'message' => mb_substr((string)$p['message'], 0, 160)], ['severity' => 'warning']);
                rh_ai_notify_settings($db, 'provider_check', 'The Assistant\'s daily check could not reach ' . (rh_ai_provider_list()[$prov]['label'] ?? $prov) . ': ' . mb_substr((string)$p['message'], 0, 120));
            }
        }
    }
    $h['tools'] = rh_ai_tool_selftest($db);
    foreach ($h['tools']['failed'] as $name => $err) {
        $h['ok'] = false;
        rh_ai_incident_add($db, 'tool_selftest', 'The daily self-test of "' . $name . '" failed', ['tool' => $name, 'error' => $err], ['severity' => 'warning', 'tool_name' => $name]);
        rh_ai_fault_add($db, 'tool:' . $name, 'The Assistant lookup "' . $name . '" fails its daily self-test', 'The read tool "' . $name . '" threw an error with safe sample arguments: ' . $err, ['tool' => $name, 'error' => $err]);
    }
    $h['budget'] = ['spent' => round(rh_ai_month_spent($db), 4), 'aim' => rh_ai_monthly_budget_usd()]; // information only
    rh_ai_save_settings(['ai_health_json' => json_encode($h, JSON_UNESCAPED_UNICODE)], $db);
    return ['ok' => $h['ok'], 'note' => $h['tools']['ok'] . ' lookup(s) passed' . ($h['tools']['failed'] ? ', failed: ' . implode(', ', array_keys($h['tools']['failed'])) : '') . ($h['providers'] ? ($h['providers'][$prov]['ok'] ? '; provider answered' : '; provider check failed') : '')];
}

// ── Question set + the completion guardrail ──────────────────────────────────

/** The daily full question set, then the guardrail's verdict for the day. */
function rh_ai_daily_eval(PDO $db): array
{
    if (!rh_ai_memory_ready($db)) {
        return ['ok' => true, 'note' => 'Needs the learning database update (072).'];
    }
    // Owner 2026-10-28 ($5 a month a hotel): each day asks only the 5 key questions; the whole set runs once a
    // week. Both run on the Fast model (rh_ai_eval_run); only the model-upgrade check uses other models.
    $kind = rh_ai_full_eval_due($db) ? 'full' : 'smoke';
    $run = rh_ai_eval_run($db, $kind, ['daily' => true]);
    if (!$run['ran']) {
        return ['ok' => true, 'note' => $run['why']];
    }
    $g = rh_ai_guardrail_day($db, $run, rh_ai_health_last());
    return ['ok' => $run['failed'] === 0, 'note' => $run['passed'] . ' of ' . $run['run'] . ' questions passed (' . $run['skipped'] . ' skipped), ' . $run['tokens_per_answer'] . ' tokens per answer; '
        . ($g['clean'] ? 'a clean day' : 'not a clean day') . ' (' . $g['streak'] . ' in a row).'];
}

/** Is the weekly run of the whole question set due (none in the last 7 days)? */
function rh_ai_full_eval_due(PDO $db): bool
{
    try {
        $last = (string)$db->query("SELECT MAX(run_date) FROM ai_eval_runs WHERE kind = 'full'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
    return $last === '' || $last <= date('Y-m-d', strtotime('-7 days'));
}

/** Built-in hard limits still hold (no forbidden tool or card exists; the kill switches are wired). [] = fine. */
function rh_ai_hard_limit_problems(): array
{
    $p = [];
    foreach (array_keys(rh_ai_tool_registry()) as $n) {
        if (preg_match('/(sql|shell|exec|file|upload|setting|user_admin|permission|backup|delete|write|update_stock|adjust|payment_add|refund_add)/i', $n)) {
            $p[] = 'read tool ' . $n;
        }
    }
    // Owner 2026-10-29 ("Assistant should be a master"): confirm a pending booking or hold, start / finish a room's job
    $want = ['create_booking', 'edit_booking', 'extend_stay', 'change_checkout', 'room_type_change', 'cancel_booking', 'check_in', 'assign_room', 'confirm_booking', 'booking_note', 'check_out',
        'housekeeping_job', 'maintenance_job', 'housekeeping_progress', 'maintenance_progress', 'room_status', 'send_email', 'send_invoice', 'whatsapp', 'reply_review', 'reply_enquiry',
        // Owner 2026-10-29 ("do it all"; money stays out): dates, holds, quotations, verifying jobs, gym check-in
        'block_dates', 'unblock_dates', 'extend_hold', 'send_quotation', 'housekeeping_verify', 'maintenance_verify', 'gym_checkin'];
    foreach (rh_ai_action_registry() as $k => $d) { // no card ever moves money (owner 2026-10-29: "do not touch money")
        if (preg_match('/(pay|refund|no.?show|deposit|invoice_add|credit|void|charge|price|rate|deal|stock|pos_)/i', $k . ' ' . $d['tool'] . ' ' . $d['page'])) {
            $p[] = 'money or forbidden card ' . $k;
        }
    }
    if (array_keys(rh_ai_action_registry()) !== $want) {
        $p[] = 'the card list changed';
    }
    $root = dirname(__DIR__, 2);
    foreach (['admin/api/ai-chat.php', 'admin/api/ai-action.php', 'admin/includes/ai-panel.php'] as $f) {
        if (strpos((string)@file_get_contents($root . '/' . $f), 'rh_ai_enabled(') === false) {
            $p[] = $f . ' does not check the master switch';
        }
    }
    if (!is_file($root . '/scripts/ai-kill.php')) {
        $p[] = 'scripts/ai-kill.php is missing';
    }
    return $p;
}

/** Consecutive clean days (one verdict per day: the latest daily full run of that day). */
function rh_ai_guardrail_streak(PDO $db): int
{
    try {
        $rows = $db->query("SELECT run_date, clean_day FROM ai_eval_runs WHERE kind IN ('full', 'smoke') AND is_daily = 1 AND clean_day IS NOT NULL ORDER BY run_date DESC, id DESC LIMIT 400")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return 0;
    }
    $byDay = [];
    foreach ($rows as $r) {
        if (!isset($byDay[$r['run_date']])) {
            $byDay[$r['run_date']] = (int)$r['clean_day'];
        }
    }
    // Consecutive daily RUNS (round 2026-10-30, ASSUMPTION in BUILD_PLAN): a day with no run (nobody signed in, so the
    // daily check never started) neither counts nor resets; a run that was not clean resets. One run per distinct day.
    $n = 0;
    foreach ($byDay as $clean) {
        if ($clean !== 1) {
            break;
        }
        $n++;
    }
    return $n;
}

/** Set the feature state by hand or by the guardrail (logged; complete tells the managers). */
function rh_ai_feature_state_set(PDO $db, string $state, string $note): void
{
    $state = $state === 'complete' ? 'complete' : 'learning';
    rh_ai_save_settings(['ai_feature_state' => $state, 'ai_feature_state_at' => date('Y-m-d H:i'), 'ai_feature_state_note' => mb_substr($note, 0, 900)], $db);
    if ($state === 'complete') {
        rh_ai_notify_settings($db, 'complete', 'This feature is complete - there is nothing else to patch. ' . mb_substr($note, 0, 160));
    }
}

/**
 * The day's verdict for the completion guardrail. A clean day: question set pass rate at or above
 * ai_complete_eval_pct, no question that passed the day before now fails, 0 open faults, no incident kind seen
 * for the first time today, every read tool's self-test green today, the hard limits hold, no automatic pause.
 * After ai_complete_days clean days in a row: learning -> complete. While complete, a regression, a new
 * incident kind or a failed self-test reopens it (back to learning, one fault with the evidence, notice).
 * @return array{clean:bool,streak:int,state:string,evidence:array}
 */
function rh_ai_guardrail_day(PDO $db, array $run, array $health): array
{
    $ev = ['date' => date('Y-m-d'), 'pass_pct' => $run['pass_pct'], 'questions_run' => $run['run'], 'regressions' => [], 'open_faults' => rh_ai_open_faults($db),
        'new_incident_kinds' => [], 'selftest_failed' => array_keys((array)($health['tools']['failed'] ?? [])), 'selftest_ran' => ($health['date'] ?? '') === date('Y-m-d'),
        'hard_limit_problems' => rh_ai_hard_limit_problems(), 'pause_events' => 0];
    try {
        $st = $db->prepare("SELECT passed_ids FROM ai_eval_runs WHERE kind = ? AND is_daily = 1 AND id < ? AND run_date < CURDATE() ORDER BY id DESC LIMIT 1");
        $st->execute([(string)($run['kind'] ?? 'full'), (int)$run['id']]);
        $before = array_filter(explode(',', (string)$st->fetchColumn()));
        $ev['regressions'] = array_values(array_intersect($before, (array)$run['failed_ids']));
        // A key-questions day also needs the latest weekly run of the whole set (within 8 days) at the bar.
        $fp = $db->query("SELECT pass_pct FROM ai_eval_runs WHERE kind = 'full' AND run_date >= DATE_SUB(CURDATE(), INTERVAL 8 DAY) ORDER BY id DESC LIMIT 1")->fetchColumn();
        $ev['full_pass_pct'] = $fp === false || $fp === null ? null : (float)$fp;
        $ev['new_incident_kinds'] = array_values(array_diff($db->query("SELECT DISTINCT kind FROM ai_incidents WHERE created_at >= CURDATE() AND kind NOT IN (SELECT DISTINCT kind FROM ai_incidents WHERE created_at < CURDATE())")->fetchAll(PDO::FETCH_COLUMN), ['auto_pause']));
        $ev['pause_events'] = (int)$db->query("SELECT COUNT(*) FROM ai_incidents WHERE kind = 'auto_pause' AND created_at >= CURDATE()")->fetchColumn()
            + (trim(rh_ai_settings($db, true)['ai_paused_reason'] ?? '') !== '' ? 1 : 0);
    } catch (Throwable $e) {
        $ev['error'] = 'evidence could not be read';
    }
    $min = (float)rh_ai_setting('ai_complete_eval_pct', '95');
    $clean = $run['run'] > 0 && $run['pass_pct'] !== null && $run['pass_pct'] >= $min && !$ev['regressions'] && $ev['open_faults'] === 0
        && (($run['kind'] ?? 'full') === 'full' || (isset($ev['full_pass_pct']) && $ev['full_pass_pct'] >= $min))
        && !$ev['new_incident_kinds'] && $ev['selftest_ran'] && !$ev['selftest_failed'] && !$ev['hard_limit_problems'] && $ev['pause_events'] === 0 && empty($ev['error']);
    try {
        $db->prepare("UPDATE ai_eval_runs SET clean_day = ?, evidence_json = ? WHERE id = ?")->execute([$clean ? 1 : 0, json_encode($ev, JSON_UNESCAPED_UNICODE), (int)$run['id']]);
    } catch (Throwable $e) {
    }
    $streak = rh_ai_guardrail_streak($db);
    $days = max(1, (int)rh_ai_setting('ai_complete_days', '14'));
    $state = rh_ai_setting('ai_feature_state', 'learning');
    if ($state !== 'complete' && $clean && $streak >= $days) {
        $state = 'complete';
        rh_ai_feature_state_set($db, 'complete', $streak . ' clean days in a row: question set at ' . $run['pass_pct'] . '% (at least ' . $min . '%), no regressions, no open faults, no new kinds of incident, every lookup\'s self-test green, hard limits hold, no automatic pause.');
    } elseif ($state === 'complete' && ($ev['regressions'] || $ev['new_incident_kinds'] || $ev['selftest_failed'])) {
        $state = 'learning';
        $why = ($ev['regressions'] ? 'questions that passed before now fail: ' . implode(', ', $ev['regressions']) . '. ' : '')
            . ($ev['new_incident_kinds'] ? 'new kinds of incident: ' . implode(', ', $ev['new_incident_kinds']) . '. ' : '')
            . ($ev['selftest_failed'] ? 'lookups failing their self-test: ' . implode(', ', $ev['selftest_failed']) . '.' : '');
        rh_ai_feature_state_set($db, 'learning', 'Reopened on evidence: ' . $why);
        rh_ai_fault_add($db, 'reopen:' . date('Y-m-d'), 'The Assistant was reopened after a problem in its daily check', 'Reopened on evidence: ' . $why, $ev, true);
        rh_ai_notify_settings($db, 'reopened', 'The Assistant is back to learning: ' . $why);
    }
    return ['clean' => $clean, 'streak' => $streak, 'state' => $state, 'evidence' => $ev];
}

// ── Faults: status by hand; a fixed fault adds a regression question ──────────

/**
 * Mark a fault open / forwarded / fixed / dismissed. Fixed adds a regression question to the nightly check
 * (source = incident): the lookup's own check when the fault is about one lookup, otherwise the question that
 * went wrong (asked again when a model can be used). @return array{ok:bool,error:string,message:string,case_id:int}
 */
function rh_ai_fault_set(PDO $db, int $id, string $status, int $uid): array
{
    if (!in_array($status, ['open', 'forwarded', 'fixed', 'dismissed'], true)) {
        return ['ok' => false, 'error' => 'Unknown status.', 'message' => '', 'case_id' => 0];
    }
    $st = $db->prepare("SELECT * FROM ai_faults WHERE id = ?");
    $st->execute([$id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) {
        return ['ok' => false, 'error' => 'That fault was not found.', 'message' => '', 'case_id' => 0];
    }
    $case = 0;
    if ($status === 'fixed') {
        $ev = json_decode((string)$f['evidence_json'], true) ?: [];
        if (!empty($ev['tool']) && isset(rh_ai_tool_registry()[(string)$ev['tool']])) {
            $case = rh_ai_eval_case_add($db, 'fault_' . $id, '', ['tool_ok' => (string)$ev['tool']], $id);
        } elseif (trim(rh_ai_memory_strip_tokens((string)($ev['question'] ?? ''))) !== '') {
            $case = rh_ai_eval_case_add($db, 'fault_' . $id, (string)$ev['question'], ['model' => true, 'not_contains' => ['could not be read', 'cannot be reached']], $id);
        }
    }
    $db->prepare("UPDATE ai_faults SET status = ?, resolved_at = IF(? IN ('fixed', 'dismissed'), NOW(), NULL), resolved_by = IF(? IN ('fixed', 'dismissed'), ?, NULL),
                  regression_case_id = COALESCE(?, regression_case_id) WHERE id = ?")
        ->execute([$status, $status, $status, $uid ?: null, $case ?: null, $id]);
    $msg = ['open' => 'Reopened.', 'forwarded' => 'Marked as forwarded for a fix.', 'dismissed' => 'Dismissed.',
        'fixed' => $case ? 'Marked fixed. A question was added to the nightly check so it cannot quietly come back.' : 'Marked fixed. (There was no question to add to the nightly check for this one.)'][$status];
    return ['ok' => true, 'error' => '', 'message' => $msg, 'case_id' => $case];
}
