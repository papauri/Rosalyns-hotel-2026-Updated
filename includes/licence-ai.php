<?php

/**
 * The Assistant set up from the licence panel (owner 2026-11-11). The Assistant comes as a package with the HMS: the owner
 * enters this hotel's AI provider key and chooses its models on the panel, tests them there, then sends the set-up. It
 * reaches this system like a staff change (includes/licence-staff.php): one command of kind 'ai_setup' inside the panel's
 * SIGNED licence answer, run once (its id is recorded in licence_staff_done_id), only straight after a successful check-in
 * and only from an answer at most 10 minutes old. The result goes back in the staff report.
 *
 * Running it:
 *   1. the AI key in the answer is encrypted for this hotel only (AES-256-GCM, "cpai1:") under a key derived from this
 *      site's licence key, so the answer kept in site_settings never holds it readable; it is opened here, in memory;
 *   2. this hotel TESTS it itself first (lists the provider's models with it, checks every chosen model is offered, sends
 *      each a 5-token "Reply OK"). If anything fails, NOTHING is changed and the panel is told why;
 *   3. then the key is saved the Assistant's usual way (rh_ai_store_key: encrypted with APP_ENCRYPTION_SALT), the model list
 *      is refreshed, the chosen models get their roles (rh_ai_save_selection), the monthly aim is set and, when asked, the
 *      Assistant is switched on. Written to the activity log as "by the system provider (licence panel)".
 *
 * The hotel's own administrators may change any of it afterwards in Assistant Settings; the panel never re-applies it
 * until the owner sends a new set-up. The staff report also carries this hotel's Assistant state (rh_licence_ai_state):
 * on/off, provider, the key's last 4 characters and test result, the chosen models, the monthly aim and this month's usage.
 * NEVER the key itself, and nothing about guests, bookings or money.
 *
 * Nothing here throws to the caller.
 */

require_once __DIR__ . '/licence.php';

if (!function_exists('rh_licence_ai_apply')) {

    define('RH_LICENCE_AI_PROVIDERS', ['anthropic', 'openai', 'google', 'xai', 'deepseek']);
    define('RH_LICENCE_AI_ROLES', ['primary', 'fast', 'fallback']);

    // ------------------------------------------------------------------ pure

    /** The key that protects the AI key inside this hotel's licence answer (the panel derives the same one). */
    function rh_licence_ai_transport_key(string $licenceKey, string $tenant): string
    {
        return hash_hkdf('sha256', $licenceKey, 32, 'cp-ai-deploy-v1|' . strtolower(trim($tenant)));
    }

    /** The AI key from the answer ("cpai1:" + base64(nonce . ciphertext . tag), bound to the command id); '' when it cannot be opened. */
    function rh_licence_ai_open_key(string $blob, string $licenceKey, string $tenant, int $commandId): string
    {
        if ($licenceKey === '' || $commandId <= 0 || strncmp($blob, 'cpai1:', 6) !== 0) {
            return '';
        }
        $raw = base64_decode(substr($blob, 6), true);
        if ($raw === false || strlen($raw) < 12 + 16 + 1) {
            return '';
        }
        $pt = openssl_decrypt(substr($raw, 12, -16), 'aes-256-gcm', rh_licence_ai_transport_key($licenceKey, $tenant), OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, -16), 'ai_setup:' . $commandId);
        return $pt === false ? '' : $pt;
    }

    /**
     * The set-up as sent, checked. @return array{0:string,1:array} [problem in plain words ('' = fine), clean]
     * clean: provider, key_enc, models (role => model id, '' = none), roles (model id => role), budget_usd, switch_on
     */
    function rh_licence_ai_clean($data): array
    {
        $d = is_array($data) ? $data : [];
        $provider = is_string($d['provider'] ?? null) ? $d['provider'] : '';
        if (!in_array($provider, RH_LICENCE_AI_PROVIDERS, true)) {
            return ['The set-up names a provider this system does not know.', []];
        }
        $enc = is_string($d['key_enc'] ?? null) ? $d['key_enc'] : '';
        if ($enc === '' || strlen($enc) > 2000) {
            return ['The set-up carries no key.', []];
        }
        $models = [];
        $roles = [];
        foreach (RH_LICENCE_AI_ROLES as $r) {
            $m = is_array($d['models'] ?? null) && is_string($d['models'][$r] ?? null) ? trim($d['models'][$r]) : '';
            if ($m !== '' && !preg_match('/^[A-Za-z0-9._:\/@-]{1,120}$/D', $m)) {
                return ['The set-up names a model this system cannot use.', []];
            }
            if ($m !== '' && isset($roles[$m])) {
                return ['The set-up gives one model two roles.', []];
            }
            $models[$r] = $m;
            if ($m !== '') {
                $roles[$m] = $r;
            }
        }
        if ($models['primary'] === '') {
            return ['The set-up has no Primary model.', []];
        }
        $budget = $d['budget_usd'] ?? 5;
        $budget = is_int($budget) || is_float($budget) || (is_string($budget) && is_numeric($budget)) ? (float)$budget : -1.0;
        if ($budget < 0 || $budget > 10000) {
            return ['The set-up has a monthly aim this system cannot use.', []];
        }
        return ['', ['provider' => $provider, 'key_enc' => $enc, 'models' => $models, 'roles' => $roles,
            'budget_usd' => round($budget, 2), 'switch_on' => ($d['switch_on'] ?? false) === true]];
    }

    // ------------------------------------------------------------------ the Assistant's own code

    /** Load the Assistant (includes/ai). False on a system without it. */
    function rh_licence_ai_load(): bool
    {
        $f = __DIR__ . '/ai/ai-catalog.php';
        if (!is_file($f)) {
            return false;
        }
        require_once $f;
        return function_exists('rh_ai_save_selection') && function_exists('rh_ai_list_models') && function_exists('rh_ai_store_key');
    }

    /**
     * Run one set-up from the panel. Tests first; changes nothing unless the test passed.
     * @param array $c a command from rh_licence_staff_commands() (id, kind 'ai_setup', data)
     * @return array{ok:bool,message:string}
     */
    function rh_licence_ai_apply(PDO $pdo, array $c): array
    {
        $fail = static fn(string $m): array => ['ok' => false, 'message' => mb_substr($m, 0, 250)];
        [$err, $d] = rh_licence_ai_clean($c['data'] ?? []);
        if ($err !== '') {
            return $fail($err . ' Nothing was changed.');
        }
        if (!rh_licence_ai_load()) {
            return $fail('This system has no Assistant yet (an older version). Nothing was changed.');
        }
        if (!rh_ai_schema_ready($pdo)) {
            return $fail('The Assistant\'s database update has not been run on this system yet. Nothing was changed.');
        }
        if (rh_ai_salt_is_default()) {
            return $fail('Keys cannot be saved here until the server encryption setting (APP_ENCRYPTION_SALT) is set in .env. Nothing was changed.');
        }
        if (!rh_ai_cloud_on()) {
            return $fail('Cloud providers are switched off in this hotel\'s Assistant Settings, so the key cannot be tested. Nothing was changed.');
        }
        foreach ($d['roles'] as $model => $role) {
            if (rh_ai_model_banned((string)$model)) {
                return $fail(rh_ai_model_banned_message() . ' Nothing was changed.');
            }
        }
        $cfg = rh_licence_config();
        $key = rh_licence_ai_open_key($d['key_enc'], $cfg['key'], $cfg['tenant'], (int)$c['id']);
        if ($key === '' || !rh_ai_key_looks_valid($key)) {
            return $fail('The key could not be opened here (was this site\'s licence key changed?). Nothing was changed.');
        }
        $label = rh_ai_provider_list()[$d['provider']]['label'] ?? $d['provider'];

        // 1. This hotel's own test, with exactly what was sent.
        $list = rh_ai_list_models($d['provider'], $key);
        if (!$list['ok']) {
            $key = '';
            return $fail('This hotel\'s own test failed, so nothing was changed: ' . $list['message']);
        }
        $offered = array_column($list['models'], 'model_id');
        $ms = 0;
        foreach ($d['models'] as $role => $model) {
            if ($model === '') {
                continue;
            }
            if (!in_array($model, $offered, true)) {
                $key = '';
                return $fail('This hotel\'s own test failed, so nothing was changed: the ' . ucfirst($role) . ' model ' . $model . ' is not offered to this key here.');
            }
            $p = rh_ai_ping($d['provider'], $key, $model);
            if (!$p['ok']) {
                $key = '';
                return $fail('This hotel\'s own test failed, so nothing was changed: ' . ucfirst($role) . ' ' . $model . ': ' . $p['message']);
            }
            $ms = $role === 'primary' ? (int)$p['ms'] : $ms;
        }

        // 2. Passed: save it the Assistant's usual way.
        try {
            rh_ai_store_key($d['provider'], $key, 0, $pdo);
            $key = '';
            rh_ai_record_test($d['provider'], true, $ms, 'Set up from the licence panel: the Primary model answered the test in ' . $ms . ' ms.', $pdo);
            rh_ai_sync_models($pdo, $d['provider'], rh_ai_rank_models($d['provider'], $list['models']));
        } catch (Throwable $e) {
            $key = '';
            error_log('licence ai setup (key): ' . get_class($e));
            return $fail('The key passed the test but could not be saved here: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'an internal error') . '.');
        }
        try {
            rh_ai_save_selection($pdo, $d['provider'], $d['roles']);
        } catch (Throwable $e) {
            error_log('licence ai setup (models): ' . get_class($e));
            return $fail('The key was saved, but the models could not be chosen here: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'an internal error')
                . ' Choose them in Assistant Settings, or send the set-up again.');
        }
        $notes = [];
        try {
            rh_ai_save_settings(['ai_monthly_budget_usd' => rtrim(rtrim(number_format($d['budget_usd'], 2, '.', ''), '0'), '.') ?: '0'], $pdo);
        } catch (Throwable $e) {
            $notes[] = 'the monthly aim could not be saved';
        }
        if ($d['switch_on']) {
            try {
                rh_ai_set_master(true, $pdo);
                if (!rh_ai_enabled($pdo)) {
                    $notes[] = 'switched on, but it is still off: ' . rh_ai_off_reason($pdo);
                }
            } catch (Throwable $e) {
                $notes[] = 'it could not be switched on';
            }
        }
        $models = [];
        foreach ($d['models'] as $role => $model) {
            if ($model !== '') {
                $models[] = ucfirst($role) . ' ' . $model;
            }
        }
        $what = 'Assistant set up: ' . $label . ' key ending ' . (string)(rh_ai_provider_states($pdo)[$d['provider']]['key_last4'] ?? '?')
            . ', ' . implode(', ', $models) . ($d['switch_on'] ? ', switched on' : '') . '.';
        if (function_exists('rh_licence_staff_log')) {
            rh_licence_staff_log($what);
        }
        return ['ok' => true, 'message' => mb_substr('Set up; this hotel\'s test answered in ' . $ms . ' ms' . ($notes ? ' (' . implode('; ', $notes) . ')' : '') . '.', 0, 250)];
    }

    // ------------------------------------------------------------------ the report

    /** This hotel's Assistant for the staff report, without usage (that part changes all the time). Never a key. */
    function rh_licence_ai_state(PDO $pdo): array
    {
        try {
            if (!rh_licence_ai_load() || !rh_ai_schema_ready($pdo)) {
                return ['ready' => false, 'enabled' => false];
            }
            $s = rh_ai_settings($pdo, true);
            $prov = (string)($s['ai_provider'] ?? '');
            $ps = rh_ai_provider_states($pdo)[$prov] ?? null;
            $models = [];
            foreach ($pdo->query("SELECT provider, model_id, role FROM ai_models WHERE is_selected = 1 ORDER BY FIELD(role, 'primary', 'fast', 'fallback', 'extra'), model_id")
                ->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $models[] = ['provider' => (string)$m['provider'], 'model_id' => (string)$m['model_id'], 'role' => (string)$m['role']];
            }
            $on = rh_ai_enabled($pdo);
            return [
                'ready' => true,
                'enabled' => $on,
                'off_reason' => $on ? '' : rh_ai_off_reason($pdo),
                'cloud_on' => (string)($s['ai_cloud_enabled'] ?? '1') !== '0',
                'provider' => $prov,
                'key_last4' => $ps ? (string)$ps['key_last4'] : '',
                'key_status' => $ps ? (string)$ps['status'] : '',
                'last_test_at' => $ps ? (string)($ps['last_test_at'] ?? '') : '',
                'last_test_message' => $ps ? rh_ai_scrub((string)$ps['last_test_message']) : '',
                'budget_usd' => round(max(0.0, (float)($s['ai_monthly_budget_usd'] ?? 5)), 2),
                'models' => $models,
            ];
        } catch (Throwable $e) {
            error_log('licence ai state: ' . get_class($e));
            return ['ready' => false, 'enabled' => false];
        }
    }

    /** This month's Assistant use (all staff together): questions, tokens and the estimated cost. Null when unknown. */
    function rh_licence_ai_usage(PDO $pdo): ?array
    {
        try {
            if (!rh_licence_ai_load() || !rh_ai_schema_ready($pdo)) {
                return null;
            }
            $r = $pdo->query("SELECT COALESCE(SUM(requests), 0) AS q, COALESCE(SUM(tokens_in), 0) AS ti, COALESCE(SUM(tokens_out), 0) AS t_o, COALESCE(SUM(cost_usd), 0) AS c
                              FROM ai_usage_daily WHERE day >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetch(PDO::FETCH_ASSOC);
            return ['month' => date('Y-m'), 'requests' => (int)$r['q'], 'tokens_in' => (int)$r['ti'], 'tokens_out' => (int)$r['t_o'], 'cost_usd' => round((float)$r['c'], 4)];
        } catch (Throwable $e) {
            return null;
        }
    }
}
