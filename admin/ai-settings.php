<?php

/**
 * Assistant Settings (staff chat helper) - gated by the ai_settings permission.
 *
 * Six sections (.claude/AI_ASSISTANT_PLAN.md Phase 1):
 *  1. Master switch + plain status line (toggles the ai_enabled setting AND the ai_assistant module row)
 *  2. Providers: paste a key (stored encrypted, never shown again - only ••••last4), Test connection, Remove
 *  3. Models: the live list after a test, up to 5 chosen with a role, prices per million tokens
 *  4. Budgets and limits, chat retention
 *  5. Who has access (read-only; changed on Staff & Access)
 *  6. Usage per person, day and model (also needs ai_usage_view)
 *  7. Learning and daily checks (Phase 4b): learning / cloud switches, the completion guardrail (learning -> complete,
 *     with date and evidence), the daily checks (no cron needed), question-set runs, model change + undo, notices
 * Tabs (Phase 4b, after migration 072): "Assistant memory" (view, add, edit, pin, retire notes; Reset learning
 * archives, never deletes) and "Assistant faults" (problems written up for a code fix + recent incidents; marking
 * one Fixed adds a regression question to the nightly check).
 *
 * Every POST: CSRF check, prepared statements, logActivity, then redirect (a refresh never repeats it).
 * Before migration 070 has run the page only shows a "run the database update" notice.
 */

require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */
/** @var PDO $pdo */

require_once __DIR__ . '/../includes/ai/ai-catalog.php';
require_once __DIR__ . '/../includes/ai/ai-selfheal.php'; // Phase 4b: memory, faults, daily checks, guardrail

$csrf_token = $csrf_token ?? generateCsrfToken();
$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$uid = (int)$user['id'];
$providers = rh_ai_provider_list();
$schemaReady = rh_ai_schema_ready($pdo);
$canUsage = hasPermission($uid, 'ai_usage_view');
$memReady = $schemaReady && rh_ai_memory_ready($pdo);
$tab = in_array((string)($_GET['tab'] ?? ''), ['memory', 'faults'], true) && $memReady ? (string)$_GET['tab'] : 'setup';

/** Queue a message for the next render and reload the page (on a tab when given). */
$aiFlash = static function (string $type, string $msg, string $anchor = '', string $toTab = ''): void {
    $_SESSION['ai_flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: ai-settings.php' . (in_array($toTab, ['memory', 'faults'], true) ? '?tab=' . $toTab : '') . ($anchor !== '' ? '#' . $anchor : ''));
    exit;
};

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $prov = (string)($_POST['provider'] ?? '');
    $provLabel = $providers[$prov]['label'] ?? '';
    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken((string)$_POST['csrf_token'])) {
            throw new RuntimeException('Invalid security token. Please refresh the page and try again.');
        }
        if (!$schemaReady) {
            throw new RuntimeException('The database update for the Assistant has not been run yet, so nothing can be saved.');
        }

        if ($action === 'master') {
            $on = (string)($_POST['state'] ?? '') === 'on';
            if ($on) {
                $active = rh_ai_active_provider();
                $st = rh_ai_provider_states($pdo)[$active] ?? null;
                $hasPrimary = false;
                foreach (($active !== '' ? rh_ai_models_for($pdo, $active) : []) as $m) {
                    if ((int)$m['is_selected'] === 1 && $m['role'] === 'primary') {
                        $hasPrimary = true;
                    }
                }
                if ($active === '' || !$st || !$st['has_key'] || !$hasPrimary) {
                    throw new RuntimeException('Before switching on: save a provider key, click Test connection, then choose the models (with one Primary) and save them.');
                }
            }
            rh_ai_set_master($on, $pdo);
            logActivity($uid, 'ai_settings', $on ? 'Assistant switched on' : 'Assistant switched off');
            $aiFlash('success', $on ? 'The Assistant is on for the people who have access.' : 'The Assistant is off. Chats, keys and settings are kept.', 'ai-master');
        }

        if (in_array($action, ['save_key', 'test', 'remove_key', 'refresh_models', 'save_models'], true) && $provLabel === '') {
            throw new RuntimeException('Unknown provider.');
        }

        if ($action === 'save_key') {
            $key = trim((string)($_POST['api_key'] ?? ''));
            unset($_POST['api_key']);
            if ($key === '') {
                throw new RuntimeException('Paste the key first.');
            }
            rh_ai_store_key($prov, $key, $uid, $pdo);
            logActivity($uid, 'ai_settings', 'Assistant key saved for ' . $provLabel);
            // Test straight away so the model list loads in the same step.
            $t = rh_ai_test_connection($prov, $key);
            $key = '';
            rh_ai_record_test($prov, $t['ok'], $t['ms'], $t['message'], $pdo);
            if ($t['ranked']) {
                rh_ai_sync_models($pdo, $prov, $t['ranked']);
            }
            $aiFlash($t['ok'] ? 'success' : 'warning', 'Key saved for ' . $provLabel . '. ' . $t['message'], 'ai-prov-' . $prov);
        }

        if ($action === 'test') {
            $key = rh_ai_get_key($prov, $pdo);
            if ($key === '') {
                throw new RuntimeException('No readable key is saved for ' . $provLabel . '. Paste the key again.');
            }
            $t = rh_ai_test_connection($prov, $key);
            $key = '';
            rh_ai_record_test($prov, $t['ok'], $t['ms'], $t['message'], $pdo);
            if ($t['ranked']) {
                rh_ai_sync_models($pdo, $prov, $t['ranked']);
            }
            logActivity($uid, 'ai_settings', 'Assistant connection test for ' . $provLabel . ': ' . ($t['ok'] ? 'ok' : 'failed'));
            $aiFlash($t['ok'] ? 'success' : 'error', $provLabel . ': ' . $t['message'], 'ai-prov-' . $prov);
        }

        if ($action === 'remove_key') {
            rh_ai_remove_key($prov, $pdo);
            logActivity($uid, 'ai_settings', 'Assistant key removed for ' . $provLabel);
            $aiFlash('success', 'The ' . $provLabel . ' key was removed.', 'ai-prov-' . $prov);
        }

        if ($action === 'refresh_models') {
            $r = rh_ai_refresh_provider($pdo, $prov);
            logActivity($uid, 'ai_settings', 'Assistant model list checked for ' . $provLabel);
            $aiFlash($r['ok'] ? 'success' : 'error', $provLabel . ': ' . $r['message'], 'ai-models-' . $prov);
        }

        if ($action === 'save_models') {
            $roles = [];
            foreach ((array)($_POST['pick'] ?? []) as $mid) {
                $mid = (string)$mid;
                $roles[$mid] = (string)($_POST['role'][$mid] ?? '');
            }
            $prices = [];
            foreach ((array)($_POST['price_in'] ?? []) as $mid => $pin) {
                $prices[(string)$mid] = [(string)$pin, (string)($_POST['price_out'][$mid] ?? '')];
            }
            rh_ai_save_selection($pdo, $prov, $roles, $prices);
            logActivity($uid, 'ai_settings', 'Assistant models saved for ' . $provLabel . ': ' . implode(', ', array_map(static fn($k, $v) => $k . ' (' . $v . ')', array_keys($roles), $roles)));
            $aiFlash('success', $roles ? 'Models saved. ' . $provLabel . ' is now the provider in use.' : 'No models chosen: the Assistant has no provider in use.', 'ai-models-' . $prov);
        }

        if ($action === 'save_limits') {
            $cap = trim((string)($_POST['ai_user_daily_token_cap'] ?? ''));
            $budget = trim((string)($_POST['ai_monthly_budget_usd'] ?? ''));
            $rate = trim((string)($_POST['ai_rate_limit_per_10min'] ?? ''));
            $ret = trim((string)($_POST['ai_retention_days'] ?? ''));
            if (!ctype_digit($cap) || (int)$cap < 1000 || (int)$cap > 50000000) {
                throw new RuntimeException('Daily limit per person: a whole number of tokens from 1,000 to 50,000,000.');
            }
            if (!is_numeric($budget) || (float)$budget < 1 || (float)$budget > 100000) {
                throw new RuntimeException('Monthly aim: a number of US dollars from 1 to 100,000.');
            }
            if (!ctype_digit($rate) || (int)$rate < 1 || (int)$rate > 500) {
                throw new RuntimeException('Messages per person in 10 minutes: a whole number from 1 to 500.');
            }
            if (!ctype_digit($ret) || ((int)$ret !== 0 && ((int)$ret < 7 || (int)$ret > 3650))) {
                throw new RuntimeException('Keep chats for: 0 (keep) or a number of days from 7 to 3650.');
            }
            $turnCap = trim((string)($_POST['ai_turn_token_cap'] ?? ''));
            if ($turnCap !== '' && (!ctype_digit($turnCap) || (int)$turnCap < 5000 || (int)$turnCap > 1000000)) {
                throw new RuntimeException('Most tokens for one question: a whole number from 5,000 to 1,000,000.');
            }
            $limitPairs = [
                'ai_user_daily_token_cap' => (string)(int)$cap,
                'ai_monthly_budget_usd'   => (string)round((float)$budget, 2),
                'ai_rate_limit_per_10min' => (string)(int)$rate,
                'ai_retention_days'       => (string)(int)$ret,
            ];
            if ($turnCap !== '') {
                $limitPairs['ai_turn_token_cap'] = (string)(int)$turnCap;
            }
            if (isset($_POST['ai_actions_enabled'])) {
                $limitPairs['ai_actions_enabled'] = (string)$_POST['ai_actions_enabled'] === '0' ? '0' : '1';
            }
            if (isset($_POST['ai_idle_lock_minutes'])) { // Phase 4c
                $idle = trim((string)$_POST['ai_idle_lock_minutes']);
                if (!ctype_digit($idle) || ((int)$idle !== 0 && ((int)$idle < 5 || (int)$idle > 240))) {
                    throw new RuntimeException('Lock the Assistant after: 0 (never) or 5 to 240 minutes.');
                }
                $limitPairs['ai_idle_lock_minutes'] = (string)(int)$idle;
            }
            if (isset($_POST['ai_digest_enabled'])) {
                $limitPairs['ai_digest_enabled'] = (string)$_POST['ai_digest_enabled'] === '0' ? '0' : '1';
            }
            rh_ai_save_settings($limitPairs, $pdo);
            logActivity($uid, 'ai_settings', 'Assistant limits saved: ' . (int)$cap . ' tokens/day, $' . round((float)$budget, 2) . '/month, ' . (int)$rate . ' messages/10 min, keep chats ' . ((int)$ret ?: 'always'));
            $aiFlash('success', 'Limits saved.', 'ai-limits');
        }

        // ── Phase 4b: learning, daily checks, memory, faults ──
        if ($action === 'save_learning') {
            $days = trim((string)($_POST['ai_complete_days'] ?? ''));
            $pct = trim((string)($_POST['ai_complete_eval_pct'] ?? ''));
            $smoke = trim((string)($_POST['ai_eval_smoke_min_pct'] ?? ''));
            if (!ctype_digit($days) || (int)$days < 3 || (int)$days > 60) {
                throw new RuntimeException('Clean days in a row: a whole number from 3 to 60.');
            }
            if (!is_numeric($pct) || (float)$pct < 50 || (float)$pct > 100) {
                throw new RuntimeException('Pass rate needed: a number from 50 to 100.');
            }
            if (!is_numeric($smoke) || (float)$smoke < 0 || (float)$smoke > 100) {
                throw new RuntimeException('Pause below: a number from 0 to 100.');
            }
            $pairs = [
                'ai_learning_enabled'   => (string)($_POST['ai_learning_enabled'] ?? '1') === '0' ? '0' : '1',
                'ai_cloud_enabled'      => (string)($_POST['ai_cloud_enabled'] ?? '1') === '0' ? '0' : '1',
                'ai_complete_days'      => (string)(int)$days,
                'ai_complete_eval_pct'  => (string)round((float)$pct, 1),
                'ai_eval_smoke_min_pct' => (string)round((float)$smoke, 1),
            ];
            rh_ai_save_settings($pairs, $pdo);
            logActivity($uid, 'ai_settings', 'Assistant learning settings saved: learning ' . ($pairs['ai_learning_enabled'] === '1' ? 'on' : 'frozen') . ', cloud providers ' . ($pairs['ai_cloud_enabled'] === '1' ? 'on' : 'off') . ', complete after ' . $pairs['ai_complete_days'] . ' clean days at ' . $pairs['ai_complete_eval_pct'] . '%');
            $aiFlash('success', 'Learning settings saved.', 'ai-learning');
        }

        if ($action === 'feature_state') {
            $to = (string)($_POST['state'] ?? '') === 'complete' ? 'complete' : 'learning';
            $who = trim((string)($user['full_name'] ?? '')) !== '' ? (string)$user['full_name'] : (string)$user['username'];
            rh_ai_feature_state_set($pdo, $to, ($to === 'complete' ? 'Marked complete by hand by ' : 'Reopened by hand by ') . $who . '.');
            logActivity($uid, 'ai_settings', 'Assistant feature state set by hand: ' . $to);
            $aiFlash('success', $to === 'complete' ? 'Marked complete. The nightly check keeps watching and reopens it on evidence.' : 'Back to learning.', 'ai-learning');
        }

        if ($action === 'run_checks') {
            @set_time_limit(300);
            session_write_close();
            $run = rh_ai_daily_run($pdo, true);
            logActivity($uid, 'ai_settings', 'Assistant daily checks run by hand');
            if ($run['status'] !== 'ran') {
                $why = ['locked' => 'The checks are already running. Try again in a minute.', 'off' => 'The Assistant is off, so the checks did not run.'][$run['status']] ?? 'The checks could not run just now.';
                session_start();
                $aiFlash('warning', $why, 'ai-learning');
            }
            $notes = [];
            foreach ($run['jobs'] as $job => $r) {
                $notes[] = ucfirst($job) . ': ' . (string)($r['note'] ?? '');
            }
            session_start();
            $aiFlash('success', 'Checks finished. ' . implode(' ', $notes), 'ai-learning');
        }

        if ($action === 'upgrade_undo') {
            $r = rh_ai_model_upgrade_undo($pdo);
            if (!$r['ok']) {
                throw new RuntimeException($r['error']);
            }
            logActivity($uid, 'ai_settings', 'Assistant automatic model change undone');
            $aiFlash('success', 'The model change was undone.', 'ai-learning');
        }

        if (in_array($action, ['memory_add', 'memory_edit', 'memory_set', 'memory_reset', 'fault_set'], true) && !$memReady) {
            throw new RuntimeException('The database update for the Assistant\'s learning (072) has not been run yet.');
        }

        if ($action === 'memory_add') {
            $kind = in_array((string)($_POST['kind'] ?? ''), ['fact', 'glossary', 'lesson'], true) ? (string)$_POST['kind'] : 'fact';
            $r = rh_ai_memory_add($pdo, ['kind' => $kind, 'scope' => 'hotel', 'text' => (string)($_POST['text'] ?? ''), 'source' => 'owner', 'created_by' => $uid]);
            if (!$r['ok']) {
                throw new RuntimeException($r['error']);
            }
            logActivity($uid, 'ai_settings', 'Assistant note added (#' . $r['id'] . ')');
            $aiFlash('success', $r['dup'] ? 'That note was already there.' : 'Note saved. The Assistant uses it from the next question.', 'ai-mem-add', 'memory');
        }

        if ($action === 'memory_edit') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT kind FROM ai_memory WHERE id = ?");
            $st->execute([$id]);
            $kind = (string)$st->fetchColumn();
            if ($kind === '') {
                throw new RuntimeException('That note was not found.');
            }
            $text = trim(preg_replace('/\s+/u', ' ', (string)($_POST['text'] ?? '')));
            $problem = rh_ai_memory_problem($text, $kind, $kind === 'playbook' ? 'reflect' : 'owner');
            if ($problem !== '') {
                throw new RuntimeException($problem);
            }
            $pdo->prepare("UPDATE ai_memory SET text = ?, terms = ?, source = IF(source = 'reflect' AND kind = 'playbook', source, 'owner'), status = IF(status = 'archived', status, 'active') WHERE id = ?")
                ->execute([$text, mb_substr(implode(' ', array_unique(rh_ai_memory_terms($text))), 0, 1000), $id]);
            logActivity($uid, 'ai_settings', 'Assistant note #' . $id . ' edited');
            $aiFlash('success', 'Note updated.', 'ai-mem-' . $id, 'memory');
        }

        if ($action === 'memory_set') {
            $id = (int)($_POST['id'] ?? 0);
            $op = (string)($_POST['op'] ?? '');
            $sql = ['pin' => "pinned = 1", 'unpin' => "pinned = 0", 'retire' => "status = 'retired'", 'restore' => "status = 'active', losses = 0"][$op] ?? '';
            if ($sql === '') {
                throw new RuntimeException('Unknown request.');
            }
            $pdo->prepare("UPDATE ai_memory SET $sql WHERE id = ?")->execute([$id]);
            logActivity($uid, 'ai_settings', 'Assistant note #' . $id . ': ' . $op);
            $aiFlash('success', ['pin' => 'Pinned: it is preferred when it fits and is never retired automatically.', 'unpin' => 'Unpinned.', 'retire' => 'Retired: it is no longer used.', 'restore' => 'Restored: it is used again.'][$op], 'ai-mem-' . $id, 'memory');
        }

        if ($action === 'memory_reset') {
            $st = $pdo->prepare("UPDATE ai_memory SET status = 'archived' WHERE source <> 'owner' AND status <> 'archived'");
            $st->execute();
            logActivity($uid, 'ai_settings', 'Assistant learning reset: ' . $st->rowCount() . ' learned notes archived');
            $aiFlash('success', $st->rowCount() . ' learned note(s) archived. Notes you typed yourself are kept. Nothing was deleted.', '', 'memory');
        }

        if ($action === 'fault_set') {
            $r = rh_ai_fault_set($pdo, (int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? ''), $uid);
            if (!$r['ok']) {
                throw new RuntimeException($r['error']);
            }
            logActivity($uid, 'ai_settings', 'Assistant fault #' . (int)$_POST['id'] . ' marked ' . (string)$_POST['status']);
            $aiFlash('success', $r['message'], 'ai-fault-' . (int)$_POST['id'], 'faults');
        }

        throw new RuntimeException('Unknown request.');
    } catch (RuntimeException $e) {
        $aiFlash('error', $e->getMessage(), '', (string)($_POST['return_tab'] ?? ''));
    } catch (Throwable $e) {
        error_log('ai-settings: ' . get_class($e) . ' ' . $e->getMessage());
        $aiFlash('error', 'Something went wrong while saving. Nothing secret was shown; please try again.', '', (string)($_POST['return_tab'] ?? ''));
    }
}

// ── Page data ─────────────────────────────────────────────────────────────────
$message = $warning = $error = '';
if (!empty($_SESSION['ai_flash']) && is_array($_SESSION['ai_flash'])) {
    $f = $_SESSION['ai_flash'];
    unset($_SESSION['ai_flash']);
    if (($f['type'] ?? '') === 'success') {
        $message = (string)$f['msg'];
    } elseif (($f['type'] ?? '') === 'warning') {
        $warning = (string)$f['msg'];
    } else {
        $error = (string)($f['msg'] ?? '');
    }
}

$saltMissing = rh_ai_salt_is_default();
$settings = $schemaReady ? rh_ai_settings($pdo, true) : rh_ai_setting_defaults();
$isOn = $schemaReady && rh_ai_enabled($pdo);
$paused = trim((string)($settings['ai_paused_reason'] ?? ''));
$active = (string)($settings['ai_provider'] ?? '');
$states = $schemaReady ? rh_ai_provider_states($pdo) : [];
$modelsBy = [];
$primaryLabel = '';
if ($schemaReady) {
    foreach (array_keys($providers) as $p) {
        $modelsBy[$p] = rh_ai_models_for($pdo, $p);
        foreach ($modelsBy[$p] as $m) {
            if ($p === $active && (int)$m['is_selected'] === 1 && $m['role'] === 'primary') {
                $primaryLabel = (string)$m['label'];
            }
        }
    }
}

// Who has access: active staff holding ai_assistant_use
$access = [];
if ($schemaReady) {
    try {
        foreach ($pdo->query("SELECT id, username, full_name, role FROM admin_users WHERE is_active = 1 ORDER BY full_name, username")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            if (hasPermission((int)$u['id'], 'ai_assistant_use')) {
                $u['can_act'] = hasPermission((int)$u['id'], 'ai_assistant_actions');
                $access[] = $u;
            }
        }
    } catch (Throwable $e) {
        $access = [];
    }
}
$roleLabels = array_map(static fn($r) => (string)($r['label'] ?? ''), getAllRoles());

// Usage (last 30 days) and month-to-date estimated cost
$usage = [];
$monthCost = 0.0;
if ($schemaReady && $canUsage) {
    try {
        $usage = $pdo->query(
            "SELECT d.day, d.provider, d.model_id, d.requests, d.tokens_in, d.tokens_out, d.cost_usd,
                    COALESCE(NULLIF(u.full_name, ''), u.username, CONCAT('User #', d.user_id)) AS person
             FROM ai_usage_daily d LEFT JOIN admin_users u ON u.id = d.user_id
             WHERE d.day >= CURDATE() - INTERVAL 30 DAY
             ORDER BY d.day DESC, person ASC, d.model_id ASC
             LIMIT 300"
        )->fetchAll(PDO::FETCH_ASSOC);
        $monthCost = (float)$pdo->query("SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage_daily WHERE day >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
    } catch (Throwable $e) {
        $usage = [];
    }
}

// Phase 4b: learning, daily checks, memory, faults
$jobs = $schemaReady ? rh_ai_jobs() : [];
$health = $schemaReady ? rh_ai_health_last() : [];
$featureState = (string)($settings['ai_feature_state'] ?? 'learning');
$streak = $memReady ? rh_ai_guardrail_streak($pdo) : 0;
$upgrade = json_decode((string)($settings['ai_model_upgrade_json'] ?? ''), true);
$notices = $schemaReady ? rh_ai_notices() : [];
$evalRuns = [];
$openFaults = 0;
$memRows = $faultRows = $incRows = [];
$memKind = (string)($_GET['kind'] ?? '');
$memStatus = (string)($_GET['status'] ?? 'in_use');
if ($memReady) {
    try {
        $evalRuns = $pdo->query("SELECT run_date, kind, run, passed, failed, skipped, pass_pct, tokens_per_answer, clean_day, finished_at FROM ai_eval_runs ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
        $openFaults = rh_ai_open_faults($pdo);
        if ($tab === 'memory') {
            $where = ["m.kind <> 'route'"]; // learned routes are listed on the usage page (with Forget)
            $args = [];
            if (isset(rh_ai_memory_kinds()[$memKind])) {
                $where[] = 'kind = ?';
                $args[] = $memKind;
            }
            $where[] = ['in_use' => "status IN ('active', 'probation')", 'retired' => "status = 'retired'", 'archived' => "status = 'archived'", 'all' => '1 = 1'][$memStatus] ?? "status IN ('active', 'probation')";
            $st = $pdo->prepare("SELECT m.*, COALESCE(NULLIF(u.full_name, ''), u.username) AS person FROM ai_memory m LEFT JOIN admin_users u ON m.scope = 'user' AND u.id = m.scope_ref
                                 WHERE " . implode(' AND ', $where) . " ORDER BY m.pinned DESC, FIELD(m.status, 'active', 'probation', 'retired', 'archived'), m.wins DESC, m.id DESC LIMIT 200");
            $st->execute($args);
            $memRows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($tab === 'faults') {
            rh_ai_audit_report($pdo); // Phase 4c: a hand-edited card shows up here straight away (no-op before migration 073)
            $faultRows = $pdo->query("SELECT * FROM ai_faults ORDER BY FIELD(status, 'open', 'forwarded', 'fixed', 'dismissed'), id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            $incRows = $pdo->query("SELECT id, kind, severity, tool_name, summary, status, created_at FROM ai_incidents ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log('ai-settings learning data: ' . $e->getMessage());
    }
}
if ($schemaReady) {
    rh_ai_notices_mark_seen($pdo, $uid); // opening this page counts as seeing the notices
}
$jobLabels = ['vault' => 'Privacy vault sweep', 'models' => 'Model list refresh', 'reflect' => 'Nightly review (lessons)', 'health' => 'Health check', 'eval' => 'Question set',
    'audit' => 'Card record check', 'digest' => 'Weekly digest', 'retention' => 'Old chats removed'];
$jobHelp = [
    'vault' => 'Removes the real names kept for chats of people whose session has ended (like signing out).',
    'models' => 'Checks each provider for newer models once a day and tries a newer one on the question set before using it.',
    'reflect' => 'Turns the day\'s mistakes and thumbs-up answers into short lessons and playbooks; scores notes.',
    'health' => 'A test call to the provider, every lookup run once with safe sample values, and the monthly budget.',
    'eval' => 'Each day the 5 key questions, once a week the whole set (both on the Fast model, to keep cost low); answers worked out from your own data; feeds the "complete" check.',
    'audit' => 'Checks that no confirmed Assistant card was edited or deleted by hand since it was recorded.',
    'digest' => 'Once a week: a short email to people who manage Assistant Settings (each can switch it off).',
    'retention' => 'Removes Assistant chats, cards and logs older than "Keep chats for" (only when it is above 0).',
];
$kindLabels = rh_ai_memory_kinds();
$incLabels = ['reply_not_right' => 'Reply marked Not right', 'action_failed' => 'Card not done', 'action_cancelled' => 'Card cancelled', 'price_mismatch' => 'Card total differed',
    'tool_breaker' => 'Lookup switched off for an hour', 'tool_selftest' => 'Lookup failed its self-test', 'auto_pause' => 'Paused itself', 'key_rejected' => 'Key refused',
    'model_retired' => 'Model retired', 'provider_check_failed' => 'Provider check failed', 'password_lockout' => 'Wrong password 5 times'];

// Status line
if (!$schemaReady) {
    $statusLine = 'Not set up yet: the database update has not been run.';
} elseif ($isOn) {
    $statusLine = 'On - using ' . ($providers[$active]['label'] ?? 'no provider') . ($primaryLabel !== '' ? ' (' . $primaryLabel . ')' : '') . ', ' . count($access) . ' ' . (count($access) === 1 ? 'person has' : 'people have') . ' access.';
} elseif ($paused !== '' && (string)$settings['ai_enabled'] === '1') {
    $statusLine = 'Paused automatically: ' . $paused;
} else {
    $statusLine = 'Off - nobody sees the Assistant. Switching it off keeps chats, keys and settings.';
}
$fmtCtx = static function ($n): string {
    if ($n === null || $n === '' || (int)$n <= 0) {
        return '—';
    }
    $n = (int)$n;
    return $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 2), '0'), '.') . 'M' : (int)round($n / 1000) . 'K';
};
$fmtPrice = static function ($v): string {
    return $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $h($csrf_token); ?>">
    <title>Assistant Settings — Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-booking-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-booking-settings.css'); ?>">
    <link rel="stylesheet" href="css/ai-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/ai-settings.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content ai-settings">
        <header class="rh-page-head">
            <div class="rh-page-head__main">
                <div class="rh-page-head__title">
                    <h1>Assistant Settings</h1>
                </div>
            </div>
            <div class="rh-page-head__actions">
                <a href="booking-settings.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Settings</a>
            </div>
        </header>
        <p class="help-text ai-intro">The Assistant is a chat helper for staff. It can only see and do what the person using it can already see and do, and every change it suggests waits for that person to confirm.</p>

        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><div><?php echo $h($message); ?></div></div>
        <?php endif; ?>
        <?php if ($warning): ?>
            <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i><div><?php echo $h($warning); ?></div></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><div><?php echo $h($error); ?></div></div>
        <?php endif; ?>

        <?php if (!$schemaReady): ?>
            <div class="rh-panel ai-card" id="ai-db-update">
                <div class="rh-panel__head"><h2 class="rh-panel__title">Database update needed</h2></div>
                <div class="rh-panel__body">
                    <p>The Assistant needs a database update before it can be set up. Ask whoever installs updates on the server to run the database update:</p>
                    <p><code>php admin/migrations/migrate.php --run</code></p>
                    <p class="help-text">Until then the Assistant stays off and nothing else in the system changes.</p>
                </div>
            </div>
        <?php else: ?>

        <?php if ($saltMissing): ?>
            <div class="alert alert-warning"><i class="fas fa-lock"></i><div>Keys cannot be saved yet: the server encryption setting (APP_ENCRYPTION_SALT) is not set. Ask whoever hosts the system to add it, then come back.</div></div>
        <?php endif; ?>

        <?php if ($memReady): ?>
        <nav class="report-tabs ai-tabs" aria-label="Assistant Settings sections">
            <a class="report-tab<?php echo $tab === 'setup' ? ' active' : ''; ?>" href="ai-settings.php"<?php echo $tab === 'setup' ? ' aria-current="page"' : ''; ?>><i class="fas fa-sliders" aria-hidden="true"></i> Setup</a>
            <a class="report-tab<?php echo $tab === 'memory' ? ' active' : ''; ?>" href="ai-settings.php?tab=memory"<?php echo $tab === 'memory' ? ' aria-current="page"' : ''; ?>><i class="fas fa-book" aria-hidden="true"></i> Assistant memory</a>
            <a class="report-tab<?php echo $tab === 'faults' ? ' active' : ''; ?>" href="ai-settings.php?tab=faults"<?php echo $tab === 'faults' ? ' aria-current="page"' : ''; ?>><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Assistant faults<?php if ($openFaults > 0): ?> <span class="tab-count"><?php echo (int)$openFaults; ?></span><?php endif; ?></a>
        </nav>
        <p class="help-text ai-tabs-help">Setup: switches, providers, models, limits and the daily checks. Assistant memory: what it has learned and what you have taught it. Assistant faults: problems it has written up for a fix.</p>
        <?php endif; ?>

        <?php if ($tab === 'setup'): ?>
        <!-- 1. Master switch -->
        <div class="rh-panel ai-card" id="ai-master">
            <div class="rh-panel__head"><h2 class="rh-panel__title">On or off <i class="help" data-tip="One switch for everyone. When off, nobody sees the Assistant and nothing is sent to any provider. Chats, keys and settings are kept.">?</i></h2></div>
            <div class="rh-panel__body">
                <div class="ai-status ai-status--<?php echo $isOn ? 'on' : ($paused !== '' && (string)$settings['ai_enabled'] === '1' ? 'paused' : 'off'); ?>">
                    <i class="fas <?php echo $isOn ? 'fa-circle-check' : 'fa-circle-pause'; ?>" aria-hidden="true"></i>
                    <span><?php echo $h($statusLine); ?></span>
                </div>
                <form method="POST" action="ai-settings.php" class="ai-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="master">
                    <?php if ($isOn): ?>
                        <button type="submit" name="state" value="off" class="btn btn-secondary"><i class="fas fa-power-off"></i> Switch off</button>
                    <?php else: ?>
                        <button type="submit" name="state" value="on" class="btn btn-primary"><i class="fas fa-power-off"></i> Switch on</button>
                    <?php endif; ?>
                </form>
                <p class="help-text">To switch on you need a provider key that passed Test connection and saved models with one Primary. Who may use it is set per person on Staff &amp; Access.</p>
            </div>
        </div>

        <!-- 2. Providers -->
        <div class="rh-panel ai-card" id="ai-providers">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Providers <i class="help" data-tip="The companies whose models answer the questions. You need a key from at least one. Keys are stored encrypted and are never shown again, only their last four characters.">?</i></h2></div>
            <div class="rh-panel__body">
                <p class="help-text">Paste a key and click Save and test. Test connection lists the provider's models and sends one short test message (at most 5 tokens, a fraction of a cent).</p>
                <div class="ai-provider-grid">
                <?php foreach ($providers as $p => $meta):
                    $st = $states[$p] ?? null;
                    $hasKey = $st && $st['has_key'];
                    $status = $st['status'] ?? 'none';
                ?>
                    <div class="ai-provider" id="ai-prov-<?php echo $h($p); ?>">
                        <div class="ai-provider__head">
                            <strong><?php echo $h($meta['label']); ?></strong>
                            <span class="ai-provider__product"><?php echo $h($meta['product']); ?></span>
                            <?php if ($p === $active): ?><span class="badge badge-success">In use</span><?php endif; ?>
                        </div>
                        <?php if ($meta['note'] !== ''): ?>
                            <p class="help-text ai-provider__note"><i class="fas fa-info-circle"></i> <?php echo $h($meta['note']); ?></p>
                        <?php endif; ?>
                        <p class="ai-provider__key">
                            Key: <?php echo $hasKey ? '<code>' . $h(rh_ai_mask_last4((string)$st['key_last4'])) . '</code>' : '<span class="ai-muted">none saved</span>'; ?>
                            <i class="help" data-tip="Only the last four characters of the saved key are ever shown. The key itself stays encrypted on the server.">?</i>
                        </p>
                        <?php if ($hasKey): ?>
                            <p class="ai-provider__test ai-provider__test--<?php echo $h($status); ?>">
                                <?php if ($status === 'ok'): ?>
                                    <i class="fas fa-check-circle"></i>
                                <?php elseif ($status === 'error'): ?>
                                    <i class="fas fa-exclamation-triangle"></i>
                                <?php else: ?>
                                    <i class="fas fa-circle-question"></i>
                                <?php endif; ?>
                                <?php
                                if ($st['last_test_at']) {
                                    echo $h('Last test ' . date('j M Y H:i', strtotime((string)$st['last_test_at'])) . ($st['last_test_ms'] !== null ? ' (' . $st['last_test_ms'] . ' ms)' : '') . ': ' . ($st['last_test_message'] !== '' ? $st['last_test_message'] : ($status === 'ok' ? 'OK' : 'failed')));
                                } else {
                                    echo 'Not tested yet.';
                                }
                                ?>
                            </p>
                        <?php endif; ?>
                        <form method="POST" action="ai-settings.php" autocomplete="off" class="ai-key-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                            <input type="hidden" name="provider" value="<?php echo $h($p); ?>">
                            <label class="ai-sr" for="ai-key-<?php echo $h($p); ?>"><?php echo $h($meta['label']); ?> key</label>
                            <input type="password" id="ai-key-<?php echo $h($p); ?>" name="api_key" class="form-control" autocomplete="new-password" spellcheck="false"
                                placeholder="<?php echo $hasKey ? 'Paste a new key to replace it' : 'Paste the key'; ?>"<?php echo $saltMissing ? ' disabled' : ''; ?>>
                            <p class="help-text"><?php echo $h($meta['key_hint']); ?></p>
                            <div class="ai-actions">
                                <button type="submit" name="action" value="save_key" class="btn btn-primary btn-sm"<?php echo $saltMissing ? ' disabled' : ''; ?>><i class="fas fa-key"></i> Save and test</button>
                                <?php if ($hasKey): ?>
                                    <button type="submit" name="action" value="test" class="btn btn-secondary btn-sm"><i class="fas fa-plug"></i> Test connection</button>
                                    <button type="submit" name="action" value="remove_key" class="btn btn-secondary btn-sm" onclick="return confirm('Remove the <?php echo $h($meta['label']); ?> key? Its chosen models will be un-chosen.');"><i class="fas fa-trash-alt"></i> Remove key</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 3. Models -->
        <div class="rh-panel ai-card" id="ai-models">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Models <i class="help" data-tip="Each provider offers several models. Choose up to 5 from one provider and give each a role. Only the provider whose models you save is used.">?</i></h2></div>
            <div class="rh-panel__body">
            <?php
            $anyModels = false;
            foreach ($modelsBy as $p => $rows) {
                if ($rows) {
                    $anyModels = true;
                }
            }
            ?>
            <?php if (!$anyModels): ?>
                <p class="help-text">No live list yet. After a key passes Test connection, that provider's current models appear here. These are the models known when this version was made:</p>
                <?php foreach ($providers as $p => $meta): $cat = rh_ai_catalog_models($p); if (!$cat) { continue; } ?>
                    <h3 class="ai-subhead"><?php echo $h($meta['label']); ?></h3>
                    <div class="table-container"><div class="table-responsive">
                    <table class="table ai-table">
                        <thead><tr>
                            <th>Model</th>
                            <th>Context <i class="help" data-tip="How much text the model can read at once, in tokens (1 token is about 3/4 of a word). 1M = one million.">?</i></th>
                            <th>Price in <i class="help" data-tip="US dollars per million tokens sent to the model (the question plus the hotel facts it needs).">?</i></th>
                            <th>Price out <i class="help" data-tip="US dollars per million tokens the model writes back.">?</i></th>
                            <th>Notes <i class="help" data-tip="Checked = id and price were checked against the provider's own pages when this list was written. Unchecked ones are confirmed by Test connection; prices stay editable.">?</i></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($cat as $m): ?>
                            <tr>
                                <td><strong><?php echo $h($m['label']); ?></strong><br><code class="ai-id"><?php echo $h($m['model_id']); ?></code></td>
                                <td><?php echo $h($fmtCtx($m['context_tokens'])); ?></td>
                                <td><?php echo $m['price_in'] !== null ? '$' . $h($fmtPrice($m['price_in'])) : '—'; ?></td>
                                <td><?php echo $m['price_out'] !== null ? '$' . $h($fmtPrice($m['price_out'])) : '—'; ?></td>
                                <td>
                                    <?php echo $m['verified'] ? '<span class="badge badge-success">Checked</span>' : '<span class="badge badge-muted">Unchecked</span>'; ?>
                                    <?php if ($m['note'] !== ''): ?><span class="ai-muted"><?php echo $h($m['note']); ?></span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div></div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="help-text">Ticked rows are the suggestion until you save your own choice: the three cheapest models that still perform (within 15 of the best priced model's rank) as Primary, Fallback and Fast, and the next cheapest priced models as Extra. Big models (Opus, Fable, Pro, Argon, or US$15+ per million out) and models with no price are never suggested for chat. Primary answers questions; Fast handles short replies; Fallback takes over if Primary fails; Extra is kept ready.</p>
                <?php foreach ($modelsBy as $p => $rows): if (!$rows) { continue; }
                    $hasSel = (bool)array_filter($rows, static fn($r) => (int)$r['is_selected'] === 1);
                    $rankedForSuggest = array_map(static fn($r) => ['model_id' => $r['model_id'], 'rank' => (float)$r['capability_rank'], 'price_in' => $r['price_in_per_mtok'] !== null ? (float)$r['price_in_per_mtok'] : null, 'price_out' => $r['price_out_per_mtok'] !== null ? (float)$r['price_out_per_mtok'] : null], $rows);
                    $suggest = $hasSel ? [] : rh_ai_suggest_roles($rankedForSuggest);
                    $newestDate = max(array_map(static fn($r) => (string)($r['released_at'] ?? ''), $rows));
                    $hasKeyP = !empty($states[$p]['has_key']);
                ?>
                    <form method="POST" action="ai-settings.php" class="ai-models-form" id="ai-models-<?php echo $h($p); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                        <input type="hidden" name="provider" value="<?php echo $h($p); ?>">
                        <h3 class="ai-subhead">
                            <?php echo $h($providers[$p]['label']); ?>
                            <?php if ($p === $active): ?><span class="badge badge-success">In use</span><?php endif; ?>
                            <span class="ai-pick-count" data-ai-count>0 of 5 chosen</span>
                        </h3>
                        <div class="table-container"><div class="table-responsive">
                        <table class="table ai-table">
                            <thead><tr>
                                <th>Use <i class="help" data-tip="Tick up to 5 models. Unticked models are never used.">?</i></th>
                                <th>Model</th>
                                <th>Role <i class="help" data-tip="Primary answers questions and plans. Fast does short replies and routing. Fallback is used when Primary fails. Extra is kept ready.">?</i></th>
                                <th>Context <i class="help" data-tip="How much text the model can read at once, in tokens (1 token is about 3/4 of a word). 1M = one million.">?</i></th>
                                <th>Price in <i class="help" data-tip="US dollars per million tokens sent to the model. Used only to estimate cost on this page and against the monthly aim. Leave blank if unknown.">?</i></th>
                                <th>Price out <i class="help" data-tip="US dollars per million tokens the model writes back. Used only for cost estimates and the monthly aim.">?</i></th>
                                <th>Notes <i class="help" data-tip="Newest = most recently released in this list. New - set price = not in the built-in list; type its price from the provider's price page.">?</i></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($rows as $m):
                                $mid = (string)$m['model_id'];
                                $ticked = $hasSel ? (int)$m['is_selected'] === 1 : isset($suggest[$mid]);
                                $role = $hasSel ? (string)($m['role'] ?? '') : (string)($suggest[$mid] ?? '');
                                $stale = $m['last_seen_at'] !== null && strtotime((string)$m['last_seen_at']) < time() - 86400 * 21 && (int)$m['is_selected'] === 1;
                            ?>
                                <tr>
                                    <td><input type="checkbox" name="pick[]" value="<?php echo $h($mid); ?>" data-ai-pick<?php echo $ticked ? ' checked' : ''; ?> aria-label="Use <?php echo $h($m['label']); ?>"></td>
                                    <td><strong><?php echo $h($m['label']); ?></strong><br><code class="ai-id"><?php echo $h($mid); ?></code></td>
                                    <td>
                                        <select name="role[<?php echo $h($mid); ?>]" class="form-control ai-role" aria-label="Role for <?php echo $h($m['label']); ?>">
                                            <option value="">—</option>
                                            <?php foreach (rh_ai_model_roles() as $rk => $rl): ?>
                                                <option value="<?php echo $h($rk); ?>"<?php echo $role === $rk ? ' selected' : ''; ?>><?php echo $h(ucfirst($rk)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><?php echo $h($fmtCtx($m['context_tokens'])); ?></td>
                                    <td><input type="text" inputmode="decimal" name="price_in[<?php echo $h($mid); ?>]" value="<?php echo $h($fmtPrice($m['price_in_per_mtok'])); ?>" class="form-control ai-price" aria-label="Price in for <?php echo $h($m['label']); ?>" placeholder="$"></td>
                                    <td><input type="text" inputmode="decimal" name="price_out[<?php echo $h($mid); ?>]" value="<?php echo $h($fmtPrice($m['price_out_per_mtok'])); ?>" class="form-control ai-price" aria-label="Price out for <?php echo $h($m['label']); ?>" placeholder="$"></td>
                                    <td>
                                        <?php if ($newestDate !== '' && (string)($m['released_at'] ?? '') === $newestDate): ?><span class="badge badge-info">Newest</span><?php endif; ?>
                                        <?php if ((int)$m['is_new'] === 1): ?><span class="badge badge-warning">New - set price</span><?php endif; ?>
                                        <?php if ($stale): ?><span class="badge badge-muted">Not offered any more</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div></div>
                        <div class="ai-actions">
                            <button type="submit" name="action" value="save_models" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save models</button>
                            <?php if ($hasKeyP): ?>
                                <button type="submit" name="action" value="refresh_models" class="btn btn-secondary btn-sm"><i class="fas fa-rotate"></i> Check for newer models</button>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </div>

        <!-- 4. Budgets and limits -->
        <div class="rh-panel ai-card" id="ai-limits">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Budgets and limits <i class="help" data-tip="Caps that stop the Assistant before it costs more than you want. The provider bills your account directly; these are estimates from the prices above.">?</i></h2></div>
            <div class="rh-panel__body">
                <form method="POST" action="ai-settings.php" class="ai-limits-grid">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="save_limits">
                    <div class="form-group">
                        <label for="ai_cap"><strong>Daily limit per person (tokens)</strong></label>
                        <input type="number" min="1000" max="50000000" step="1000" id="ai_cap" name="ai_user_daily_token_cap" class="form-control" value="<?php echo $h((int)$settings['ai_user_daily_token_cap']); ?>">
                        <p class="help-text">Tokens read plus written per person per day. Only a guard against a runaway loop: 2,000,000 is many times a very busy day, so normal use never reaches it.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_budget"><strong>Monthly aim (US$)</strong></label>
                        <input type="number" min="1" max="100000" step="0.01" id="ai_budget" name="ai_monthly_budget_usd" class="form-control" value="<?php echo $h($settings['ai_monthly_budget_usd']); ?>">
                        <p class="help-text">What the Assistant aims to cost a month. It is shown next to this month's cost as information only: chat never pauses and nobody is warned when it is passed.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_rate"><strong>Messages per person in 10 minutes</strong></label>
                        <input type="number" min="1" max="500" step="1" id="ai_rate" name="ai_rate_limit_per_10min" class="form-control" value="<?php echo $h((int)$settings['ai_rate_limit_per_10min']); ?>">
                        <p class="help-text">Stops a stuck page or a mistake from sending hundreds of messages.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_ret"><strong>Keep chats for (days)</strong></label>
                        <input type="number" min="0" max="3650" step="1" id="ai_ret" name="ai_retention_days" class="form-control" value="<?php echo $h((int)$settings['ai_retention_days']); ?>">
                        <p class="help-text">0 keeps chats until someone deletes them. Otherwise chats older than this are removed each night.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_turn_cap"><strong>Most tokens for one question</strong></label>
                        <input type="number" min="5000" max="1000000" step="1000" id="ai_turn_cap" name="ai_turn_token_cap" class="form-control" value="<?php echo $h((int)($settings['ai_turn_token_cap'] ?? 60000)); ?>">
                        <p class="help-text">One question may ask up to three department leads. This caps all of their work together; when it is reached the answer is given with what was found so far.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_actions_on"><strong>Action cards</strong></label>
                        <select id="ai_actions_on" name="ai_actions_enabled" class="form-control">
                            <option value="1"<?php echo (($settings['ai_actions_enabled'] ?? '1') !== '0') ? ' selected' : ''; ?>>On - it can prepare changes for people to confirm</option>
                            <option value="0"<?php echo (($settings['ai_actions_enabled'] ?? '1') === '0') ? ' selected' : ''; ?>>Off - answers only, no cards</option>
                        </select>
                        <p class="help-text">A card (new booking, check-in, check-out, housekeeping job...) changes nothing until the person taps Confirm, and then runs through the normal screen. Only people with "Confirm Assistant actions" (Staff &amp; Access) see cards.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_idle"><strong>Lock the Assistant after (minutes without use)</strong></label>
                        <input type="number" min="0" max="240" step="1" id="ai_idle" name="ai_idle_lock_minutes" class="form-control" value="<?php echo $h((int)($settings['ai_idle_lock_minutes'] ?? 15)); ?>">
                        <p class="help-text">After this long with no use of the portal the chat hides and asks for the person's own password. 0 never locks. Cancelling, checking out, correcting a check-out date, taking a room off sale and sending to a guest (booking email, invoice, enquiry reply, or a review reply that is also emailed) always ask for the password. WhatsApp cards only open the booking screen.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_digest"><strong>Weekly digest email</strong></label>
                        <select id="ai_digest" name="ai_digest_enabled" class="form-control">
                            <option value="1"<?php echo (($settings['ai_digest_enabled'] ?? '1') !== '0') ? ' selected' : ''; ?>>On - once a week to people who manage Assistant Settings</option>
                            <option value="0"<?php echo (($settings['ai_digest_enabled'] ?? '1') === '0') ? ' selected' : ''; ?>>Off - no digest emails</option>
                        </select>
                        <p class="help-text">A short email with what needs attention and last week's room figures, worked out from what each person's account can see. Each person can switch it off in the Assistant's Reply settings.</p>
                    </div>
                    <div class="ai-actions ai-limits-save">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save limits</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 4b. Learning and daily checks -->
        <div class="rh-panel ai-card" id="ai-learning">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Learning and daily checks <i class="help" data-tip="The Assistant learns from corrections, Helpful / Not right and its own mistakes, and checks itself every day. It never changes its own code or what it is allowed to do.">?</i></h2></div>
            <div class="rh-panel__body">
                <?php if ($memReady): ?>
                <div class="ai-status ai-status--<?php echo $featureState === 'complete' ? 'on' : 'off'; ?>">
                    <i class="fas <?php echo $featureState === 'complete' ? 'fa-circle-check' : 'fa-clipboard-list'; ?>" aria-hidden="true"></i>
                    <span>
                        <?php if ($featureState === 'complete'): ?>
                            Complete since <?php echo $h($settings['ai_feature_state_at'] ?? ''); ?>: this feature is complete - there is nothing else to patch. The nightly check keeps watching and reopens it if a problem shows up.
                        <?php else: ?>
                            Learning: <?php echo (int)$streak; ?> of <?php echo (int)($settings['ai_complete_days'] ?? 14); ?> clean days in a row needed before it declares itself complete.
                        <?php endif; ?>
                    </span>
                </div>
                <?php if (trim((string)($settings['ai_feature_state_note'] ?? '')) !== ''): ?>
                    <p class="help-text"><strong>Evidence:</strong> <?php echo $h($settings['ai_feature_state_note']); ?></p>
                <?php endif; ?>
                <p class="help-text">A clean day: the question set passes at the rate below, nothing that passed before fails, no open faults, no new kind of problem, every lookup's self-test passes, and it did not pause itself.</p>
                <form method="POST" action="ai-settings.php" class="ai-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="feature_state">
                    <?php if ($featureState === 'complete'): ?>
                        <button type="submit" name="state" value="learning" class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left"></i> Reopen (back to learning)</button>
                    <?php else: ?>
                        <button type="submit" name="state" value="complete" class="btn btn-secondary btn-sm" onclick="return confirm('Mark the Assistant complete by hand? It stops writing new lessons; the nightly check still watches it.');"><i class="fas fa-flag-checkered"></i> Mark complete by hand</button>
                    <?php endif; ?>
                </form>
                <?php else: ?>
                    <p class="help-text">Learning, the fault list and the daily question set need one more database update (072). Until it is run, the Assistant answers as before and the checks below that do not need it still run.</p>
                <?php endif; ?>

                <form method="POST" action="ai-settings.php" class="ai-limits-grid ai-learning-grid">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="save_learning">
                    <div class="form-group">
                        <label for="ai_learn_on"><strong>Learning</strong></label>
                        <select id="ai_learn_on" name="ai_learning_enabled" class="form-control">
                            <option value="1"<?php echo ($settings['ai_learning_enabled'] ?? '1') !== '0' ? ' selected' : ''; ?>>On - it keeps notes from corrections and its mistakes</option>
                            <option value="0"<?php echo ($settings['ai_learning_enabled'] ?? '1') === '0' ? ' selected' : ''; ?>>Frozen - it uses what it knows but keeps nothing new</option>
                        </select>
                        <p class="help-text">Notes only ever change what it knows and how it words things - never what it may see or do.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_cloud_on"><strong>Cloud providers</strong></label>
                        <select id="ai_cloud_on" name="ai_cloud_enabled" class="form-control">
                            <option value="1"<?php echo ($settings['ai_cloud_enabled'] ?? '1') !== '0' ? ' selected' : ''; ?>>On - questions that need a model go to the provider</option>
                            <option value="0"<?php echo ($settings['ai_cloud_enabled'] ?? '1') === '0' ? ' selected' : ''; ?>>Off - nothing leaves the hotel; quick local answers only</option>
                        </select>
                        <p class="help-text">Off stops every call to a provider at once. Arrivals, departures, in house, room status and how-to answers still work.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_c_days"><strong>Clean days in a row to be complete</strong></label>
                        <input type="number" min="3" max="60" step="1" id="ai_c_days" name="ai_complete_days" class="form-control" value="<?php echo $h((int)($settings['ai_complete_days'] ?? 14)); ?>">
                        <p class="help-text">How many clean daily checks in a row before it says it is complete. 14 by default.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_c_pct"><strong>Pass rate needed (%)</strong></label>
                        <input type="number" min="50" max="100" step="0.1" id="ai_c_pct" name="ai_complete_eval_pct" class="form-control" value="<?php echo $h($settings['ai_complete_eval_pct'] ?? '95'); ?>">
                        <p class="help-text">Share of the questions that must pass for a day to count as clean.</p>
                    </div>
                    <div class="form-group">
                        <label for="ai_s_pct"><strong>Pause below (%) on the 5 key questions</strong></label>
                        <input type="number" min="0" max="100" step="0.1" id="ai_s_pct" name="ai_eval_smoke_min_pct" class="form-control" value="<?php echo $h($settings['ai_eval_smoke_min_pct'] ?? '80'); ?>">
                        <p class="help-text">If the five key questions score below this in a daily check, it pauses itself and tells you.</p>
                    </div>
                    <div class="ai-actions ai-limits-save">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save learning settings</button>
                    </div>
                </form>

                <h3 class="ai-subhead">Daily checks</h3>
                <p class="help-text">These run by themselves after the first page load of each day by anyone signed in - no scheduled task is needed. Nobody waits for them.</p>
                <div class="table-container"><div class="table-responsive">
                <table class="table ai-table">
                    <thead><tr>
                        <th>Check <i class="help" data-tip="What runs each day, in this order.">?</i></th>
                        <th>Last run <i class="help" data-tip="When this check last finished, by the daily run, a scheduled script or Run the checks now.">?</i></th>
                        <th>Result <i class="help" data-tip="What it found, in a line. Failures are also in the system log.">?</i></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($jobLabels as $jk => $jl): $j = $jobs[$jk] ?? null; ?>
                        <tr>
                            <td><strong><?php echo $h($jl); ?></strong><br><span class="ai-muted"><?php echo $h($jobHelp[$jk]); ?></span></td>
                            <td><?php echo $j ? $h($j['at']) : 'Not yet'; ?></td>
                            <td><?php echo $j ? (empty($j['ok']) ? '<span class="ai-bad">Needs a look:</span> ' : '') . $h($j['note']) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div></div>
                <form method="POST" action="ai-settings.php" class="ai-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="run_checks">
                    <button type="submit" class="btn btn-secondary btn-sm"<?php echo $isOn ? '' : ' disabled'; ?>><i class="fas fa-play"></i> Run the checks now</button>
                    <span class="help-text">Takes up to a minute. The model questions use a few tokens.<?php echo $isOn ? '' : ' Switch the Assistant on first.'; ?></span>
                </form>

                <?php if ($memReady): ?>
                <h3 class="ai-subhead">Question set runs</h3>
                <?php if (!$evalRuns): ?>
                    <p class="help-text">No runs yet. The first one happens with the daily checks.</p>
                <?php else: ?>
                <div class="table-container"><div class="table-responsive">
                <table class="table ai-table">
                    <thead><tr>
                        <th>Date</th>
                        <th>Run <i class="help" data-tip="Full = every question (daily). Smoke = the 5 key questions. Upgrade = the model questions, run to test a newer model before using it.">?</i></th>
                        <th>Passed <i class="help" data-tip="Questions answered as expected, out of those asked. Skipped ones (no model can be used) are not counted.">?</i></th>
                        <th>Pass rate <i class="help" data-tip="Passed divided by asked, as a percentage.">?</i></th>
                        <th>Tokens per answer <i class="help" data-tip="Average tokens a question used. Local answers use none; a lower figure at the same pass rate means cheaper answers.">?</i></th>
                        <th>Clean day <i class="help" data-tip="Whether that day counted towards the clean days needed to be complete (daily full runs only).">?</i></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($evalRuns as $er): ?>
                        <tr>
                            <td><?php echo $h(date('j M Y', strtotime((string)$er['run_date']))); ?></td>
                            <td><?php echo $h(ucfirst((string)$er['kind'])); ?></td>
                            <td><?php echo (int)$er['passed']; ?> of <?php echo (int)$er['run']; ?><?php echo (int)$er['skipped'] ? ' (' . (int)$er['skipped'] . ' skipped)' : ''; ?></td>
                            <td><?php echo $er['pass_pct'] !== null ? $h(rtrim(rtrim(number_format((float)$er['pass_pct'], 1), '0'), '.')) . '%' : '—'; ?></td>
                            <td><?php echo $h(number_format((int)$er['tokens_per_answer'])); ?></td>
                            <td><?php echo $er['clean_day'] === null ? '—' : ((int)$er['clean_day'] === 1 ? 'Yes' : 'No'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div></div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if (is_array($upgrade) && !empty($upgrade['to'])): ?>
                <h3 class="ai-subhead">Last model check</h3>
                <p class="help-text">
                    <?php echo $h($upgrade['at'] ?? ''); ?>: a newer <?php echo $h($upgrade['role']); ?> model (<code class="ai-id"><?php echo $h($upgrade['to']); ?></code>) was tried against <code class="ai-id"><?php echo $h($upgrade['from']); ?></code> -
                    <?php echo $h(($upgrade['to_pct'] ?? '?') . '% against ' . ($upgrade['from_pct'] ?? '?') . '%'); ?>.
                    <?php echo ($upgrade['decision'] ?? '') === 'switched' ? (!empty($upgrade['undone']) ? 'It was switched, then undone on ' . $h($upgrade['undone']) . '.' : 'It now does that job.') : 'It did not do better, so nothing changed.'; ?>
                </p>
                <?php if (($upgrade['decision'] ?? '') === 'switched' && empty($upgrade['undone'])): ?>
                <form method="POST" action="ai-settings.php" class="ai-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="upgrade_undo">
                    <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left"></i> Undo this model change</button>
                </form>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($notices): ?>
                <h3 class="ai-subhead">Notices</h3>
                <ul class="ai-notices">
                    <?php foreach (array_slice($notices, 0, 8) as $n): ?>
                        <li><span class="ai-muted"><?php echo $h($n['at'] ?? ''); ?></span> <?php echo $h($n['text'] ?? ''); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- 5. Who has access -->
        <div class="rh-panel ai-card" id="ai-access">
            <div class="rh-panel__head">
                <h2 class="rh-panel__title">Who has access <i class="help" data-tip="Active staff whose permissions include Use the Assistant. Administrators always have it; managers have it unless removed.">?</i></h2>
                <div class="rh-panel__actions"><a href="user-management.php" class="btn btn-secondary btn-sm"><i class="fas fa-users-cog"></i> Change on Staff &amp; Access</a></div>
            </div>
            <div class="rh-panel__body">
                <?php if (!$access): ?>
                    <p class="help-text">Nobody has the Use the Assistant permission yet.</p>
                <?php else: ?>
                    <div class="table-container"><div class="table-responsive">
                    <table class="table ai-table">
                        <thead><tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Can confirm actions <i class="help" data-tip="Yes = holds Confirm Assistant actions: may confirm the changes the Assistant suggests (each still runs the normal screen action and its checks). No = chat only.">?</i></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($access as $u): ?>
                            <tr>
                                <td><?php echo $h($u['full_name'] !== '' ? $u['full_name'] : $u['username']); ?></td>
                                <td><?php echo $h($roleLabels[$u['role']] ?? $u['role']); ?></td>
                                <td><?php echo $u['can_act'] ? 'Yes' : 'No'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 6. Usage -->
        <?php if ($canUsage): ?>
        <div class="rh-panel ai-card" id="ai-usage">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Usage <i class="help" data-tip="Questions asked in the last 30 days, per person, day and model, with the estimated cost from the prices in Models.">?</i></h2></div>
            <div class="rh-panel__body">
                <div class="ai-stat">
                    <span class="ai-stat__label">Estimated cost this month <i class="help" data-tip="Sum of the estimated cost of every Assistant reply since the 1st of this month, from the prices in Models. Your provider's bill is the real figure.">?</i></span>
                    <span class="ai-stat__value">$<?php echo $h(number_format($monthCost, 2)); ?></span>
                    <span class="ai-stat__sub">monthly aim $<?php echo $h(number_format((float)$settings['ai_monthly_budget_usd'], 2)); ?></span>
                </div>
                <p class="help-text"><a href="ai-usage.php"><i class="fas fa-chart-bar" aria-hidden="true"></i> Open the usage dashboard</a> - cost per answer, answers given without a model call, and totals by person, day and model.</p>
                <?php if (!$usage): ?>
                    <p class="help-text">No questions asked in the last 30 days.</p>
                <?php else: ?>
                    <div class="table-container"><div class="table-responsive">
                    <table class="table ai-table">
                        <thead><tr>
                            <th>Day</th>
                            <th>Person</th>
                            <th>Model</th>
                            <th>Questions <i class="help" data-tip="Requests sent to the provider that day (one question can need more than one request).">?</i></th>
                            <th>Tokens in <i class="help" data-tip="Tokens sent to the model: the questions plus the hotel facts each answer needed.">?</i></th>
                            <th>Tokens out <i class="help" data-tip="Tokens the model wrote back.">?</i></th>
                            <th>Est. cost <i class="help" data-tip="Tokens in and out times the model's prices in Models, in US dollars. An estimate; the provider's bill is the real figure.">?</i></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($usage as $r): ?>
                            <tr>
                                <td><?php echo $h(date('j M Y', strtotime((string)$r['day']))); ?></td>
                                <td><?php echo $h($r['person']); ?></td>
                                <td><code class="ai-id"><?php echo $h($r['model_id']); ?></code></td>
                                <td><?php echo $h(number_format((int)$r['requests'])); ?></td>
                                <td><?php echo $h(number_format((int)$r['tokens_in'])); ?></td>
                                <td><?php echo $h(number_format((int)$r['tokens_out'])); ?></td>
                                <td>$<?php echo $h(number_format((float)$r['cost_usd'], 4)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; /* setup tab */ ?>

        <?php if ($tab === 'memory'): ?>
        <!-- Assistant memory -->
        <div class="rh-panel ai-card" id="ai-mem-add">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Teach it something <i class="help" data-tip="A fact or a house word the Assistant should know, for example breakfast times or what staff call a room. Notes you type are used straight away.">?</i></h2></div>
            <div class="rh-panel__body">
                <form method="POST" action="ai-settings.php" class="ai-mem-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="memory_add">
                    <input type="hidden" name="return_tab" value="memory">
                    <div class="form-group">
                        <label for="ai_mem_kind"><strong>Kind</strong></label>
                        <select id="ai_mem_kind" name="kind" class="form-control">
                            <option value="fact">Fact - something true about the hotel</option>
                            <option value="glossary">Word meaning - a house word and what it means</option>
                            <option value="lesson">Lesson - a habit that avoids a mistake</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ai_mem_text"><strong>Note</strong></label>
                        <textarea id="ai_mem_text" name="text" class="form-control" rows="2" maxlength="500" required placeholder="Breakfast is served from 6:30 to 10:00 in the garden restaurant."></textarea>
                        <p class="help-text">Facts and wording only. Contact details, prices and amounts (they always come from the system) and rules about what the Assistant may do are refused.</p>
                    </div>
                    <div class="ai-actions"><button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save note</button></div>
                </form>
            </div>
        </div>

        <div class="rh-panel ai-card" id="ai-mem-list">
            <div class="rh-panel__head">
                <h2 class="rh-panel__title">Assistant memory <i class="help" data-tip="Everything it has learned or been taught. Up to 8 notes that fit a question go with it; new learned notes start on trial and are used fully after a few helpful replies.">?</i></h2>
            </div>
            <div class="rh-panel__body">
                <form method="GET" action="ai-settings.php" class="ai-actions ai-mem-filter">
                    <input type="hidden" name="tab" value="memory">
                    <label for="ai_mf_kind" class="ai-muted">Kind</label>
                    <select id="ai_mf_kind" name="kind" class="form-control">
                        <option value="">All kinds</option>
                        <?php foreach ($kindLabels as $kk => $kl): ?>
                            <option value="<?php echo $h($kk); ?>"<?php echo $memKind === $kk ? ' selected' : ''; ?>><?php echo $h($kl); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="ai_mf_status" class="ai-muted">Showing</label>
                    <select id="ai_mf_status" name="status" class="form-control">
                        <?php foreach (['in_use' => 'In use (active and on trial)', 'retired' => 'Retired', 'archived' => 'Archived', 'all' => 'All'] as $sk => $sl): ?>
                            <option value="<?php echo $h($sk); ?>"<?php echo $memStatus === $sk ? ' selected' : ''; ?>><?php echo $h($sl); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary btn-sm">Show</button>
                </form>
                <?php if (!$memRows): ?>
                    <p class="help-text">No notes here yet. Corrections staff type, Helpful / Not right taps and the nightly review fill this in over time.</p>
                <?php else: ?>
                <div class="table-container"><div class="table-responsive">
                <table class="table ai-table">
                    <thead><tr>
                        <th>Kind <i class="help" data-tip="Fact, reply style (one person's), lesson (from a mistake), playbook (which lookups answered a kind of question - lets the cheaper model answer) or word meaning.">?</i></th>
                        <th>Note <i class="help" data-tip="The note exactly as the Assistant reads it.">?</i></th>
                        <th>Applies to <i class="help" data-tip="Everyone (the hotel), or one person's replies only.">?</i></th>
                        <th>From <i class="help" data-tip="You (typed here), a staff correction, a Helpful / Not right tap, or the nightly review.">?</i></th>
                        <th>Used <i class="help" data-tip="How many replies used this note.">?</i></th>
                        <th>Helped / not <i class="help" data-tip="Replies that used it and were marked Helpful, against Not right. Three more Not right than Helpful retires it (unless pinned).">?</i></th>
                        <th>Status <i class="help" data-tip="Active = used. On trial = new, used now and then until it has helped a few times. Retired = no longer used. Archived = put away by Reset learning.">?</i></th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($memRows as $m): $mid = (int)$m['id']; ?>
                        <tr id="ai-mem-<?php echo $mid; ?>">
                            <td><?php echo $h($kindLabels[$m['kind']] ?? $m['kind']); ?></td>
                            <td class="ai-mem-text"><?php echo $h($m['text']); ?><?php echo (int)$m['pinned'] ? ' <span class="ai-tag">Pinned</span>' : ''; ?></td>
                            <td><?php echo $m['scope'] === 'user' ? $h($m['person'] ?: 'One person') : 'Everyone'; ?></td>
                            <td><?php echo $h(['owner' => 'You', 'staff' => 'Staff', 'feedback' => 'A correction', 'chat' => 'A correction in chat', 'reflect' => 'Nightly review'][$m['source']] ?? $m['source']); ?></td>
                            <td><?php echo (int)$m['uses']; ?></td>
                            <td><?php echo (int)$m['wins']; ?> / <?php echo (int)$m['losses']; ?></td>
                            <td><?php echo $h(['active' => 'Active', 'probation' => 'On trial', 'retired' => 'Retired', 'archived' => 'Archived'][$m['status']] ?? $m['status']); ?></td>
                            <td class="ai-mem-actions">
                                <details>
                                    <summary class="btn btn-secondary btn-sm">Edit</summary>
                                    <form method="POST" action="ai-settings.php">
                                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                        <input type="hidden" name="action" value="memory_edit">
                                        <input type="hidden" name="return_tab" value="memory">
                                        <input type="hidden" name="id" value="<?php echo $mid; ?>">
                                        <label class="sr-only" for="ai_me_<?php echo $mid; ?>">Note text</label>
                                        <textarea id="ai_me_<?php echo $mid; ?>" name="text" class="form-control" rows="3" maxlength="500"><?php echo $h($m['text']); ?></textarea>
                                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                    </form>
                                </details>
                                <form method="POST" action="ai-settings.php">
                                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                    <input type="hidden" name="action" value="memory_set">
                                    <input type="hidden" name="return_tab" value="memory">
                                    <input type="hidden" name="id" value="<?php echo $mid; ?>">
                                    <button type="submit" name="op" value="<?php echo (int)$m['pinned'] ? 'unpin' : 'pin'; ?>" class="btn btn-secondary btn-sm"><?php echo (int)$m['pinned'] ? 'Unpin' : 'Pin'; ?></button>
                                    <?php if (in_array($m['status'], ['active', 'probation'], true)): ?>
                                        <button type="submit" name="op" value="retire" class="btn btn-secondary btn-sm">Retire</button>
                                    <?php else: ?>
                                        <button type="submit" name="op" value="restore" class="btn btn-secondary btn-sm">Restore</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div></div>
                <?php endif; ?>
                <form method="POST" action="ai-settings.php" class="ai-actions ai-mem-reset">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="memory_reset">
                    <input type="hidden" name="return_tab" value="memory">
                    <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Archive everything the Assistant has learned? Notes you typed yourself are kept. Nothing is deleted.');"><i class="fas fa-box-archive"></i> Reset learning</button>
                    <span class="help-text">Archives every learned note (nothing is deleted). Notes you typed here are kept.</span>
                </form>
            </div>
        </div>
        <?php endif; /* memory tab */ ?>

        <?php if ($tab === 'faults'): ?>
        <!-- Assistant faults -->
        <div class="rh-panel ai-card" id="ai-faults">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Assistant faults <i class="help" data-tip="Problems the Assistant cannot fix by itself - it never changes its own code. Each one is written up in plain words with the evidence, ready to forward as a PATCH request.">?</i></h2></div>
            <div class="rh-panel__body">
                <p class="help-text">Copy a fault into a PATCH request for the developer and mark it Forwarded. When the fix is in, mark it Fixed: a question is added to the nightly check so the problem cannot quietly come back.</p>
                <?php if (!$faultRows): ?>
                    <p class="help-text">No faults written up.</p>
                <?php else: ?>
                <div class="table-container"><div class="table-responsive">
                <table class="table ai-table">
                    <thead><tr>
                        <th>Raised <i class="help" data-tip="When it was first written up (it is updated if the same problem happens again).">?</i></th>
                        <th>Fault <i class="help" data-tip="The problem in plain words, and the evidence to forward.">?</i></th>
                        <th>Status <i class="help" data-tip="Open = needs forwarding. Forwarded = sent for a fix. Fixed = fixed, with a question added to the nightly check. Dismissed = not a real problem.">?</i></th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($faultRows as $f): $fid = (int)$f['id']; ?>
                        <tr id="ai-fault-<?php echo $fid; ?>">
                            <td><?php echo $h(date('j M Y H:i', strtotime((string)$f['created_at']))); ?></td>
                            <td>
                                <strong><?php echo $h($f['title']); ?></strong>
                                <p class="ai-muted"><?php echo $h($f['detail']); ?></p>
                                <details>
                                    <summary class="ai-muted">Copy for a PATCH request</summary>
                                    <label class="sr-only" for="ai_fc_<?php echo $fid; ?>">Fault write-up</label>
                                    <textarea id="ai_fc_<?php echo $fid; ?>" class="form-control ai-fault-copy" rows="5" readonly><?php echo $h("PATCH (Assistant fault #" . $fid . "): " . $f['title'] . "\n" . $f['detail'] . "\nEvidence: " . $f['evidence_json']); ?></textarea>
                                </details>
                            </td>
                            <td><?php echo $h(['open' => 'Open', 'forwarded' => 'Forwarded', 'fixed' => 'Fixed', 'dismissed' => 'Dismissed'][$f['status']] ?? $f['status']); ?><?php echo (int)$f['regression_case_id'] ? '<br><span class="ai-muted">checked nightly</span>' : ''; ?></td>
                            <td class="ai-mem-actions">
                                <form method="POST" action="ai-settings.php">
                                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                    <input type="hidden" name="action" value="fault_set">
                                    <input type="hidden" name="return_tab" value="faults">
                                    <input type="hidden" name="id" value="<?php echo $fid; ?>">
                                    <?php if (in_array($f['status'], ['open', 'forwarded'], true)): ?>
                                        <?php if ($f['status'] === 'open'): ?><button type="submit" name="status" value="forwarded" class="btn btn-secondary btn-sm">Forwarded</button><?php endif; ?>
                                        <button type="submit" name="status" value="fixed" class="btn btn-primary btn-sm">Fixed</button>
                                        <button type="submit" name="status" value="dismissed" class="btn btn-secondary btn-sm">Dismiss</button>
                                    <?php else: ?>
                                        <button type="submit" name="status" value="open" class="btn btn-secondary btn-sm">Reopen</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="rh-panel ai-card" id="ai-incidents">
            <div class="rh-panel__head"><h2 class="rh-panel__title">Recent problems <i class="help" data-tip="The last 50 things that went wrong or were corrected. The nightly review learns from these; most never need a fix.">?</i></h2></div>
            <div class="rh-panel__body">
                <?php if (!$incRows): ?>
                    <p class="help-text">Nothing has gone wrong yet.</p>
                <?php else: ?>
                <div class="table-container"><div class="table-responsive">
                <table class="table ai-table">
                    <thead><tr>
                        <th>When</th>
                        <th>What <i class="help" data-tip="The kind of problem: a reply marked Not right, a card not done or cancelled, a lookup that failed, an automatic pause ...">?</i></th>
                        <th>Details <i class="help" data-tip="What happened, without guest or staff names.">?</i></th>
                        <th>Reviewed <i class="help" data-tip="Yes once the nightly review has looked at it.">?</i></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($incRows as $i): ?>
                        <tr>
                            <td><?php echo $h(date('j M H:i', strtotime((string)$i['created_at']))); ?></td>
                            <td><?php echo $h($incLabels[$i['kind']] ?? str_replace('_', ' ', (string)$i['kind'])); ?></td>
                            <td><?php echo $h($i['summary']); ?><?php echo $i['tool_name'] ? ' <span class="ai-muted">(' . $h($i['tool_name']) . ')</span>' : ''; ?></td>
                            <td><?php echo $i['status'] === 'open' ? 'Not yet' : 'Yes'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; /* faults tab */ ?>

        <?php endif; /* schemaReady */ ?>
    </div>

    <script>
    (function () {
        // Up to 5 models per provider: count the ticks and stop a sixth (the server checks again).
        function sync(form) {
            var boxes = form.querySelectorAll('[data-ai-pick]');
            var n = 0;
            boxes.forEach(function (b) { if (b.checked) n++; });
            boxes.forEach(function (b) { b.disabled = !b.checked && n >= 5; });
            var c = form.querySelector('[data-ai-count]');
            if (c) c.textContent = n + ' of 5 chosen';
        }
        document.querySelectorAll('.ai-models-form').forEach(sync);
        if (!window.__rhAiSettingsBound) {
            window.__rhAiSettingsBound = true;
            document.addEventListener('change', function (e) {
                var t = e.target;
                if (t && t.matches && t.matches('[data-ai-pick]')) {
                    var form = t.closest('.ai-models-form');
                    if (form) sync(form);
                }
            });
        }
    })();
    </script>
<?php require_once 'includes/admin-footer.php'; ?>
