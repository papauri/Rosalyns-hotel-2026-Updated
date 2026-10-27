<?php

/**
 * Assistant core: the kill switch (fail-closed), settings, and provider-key encryption.
 *
 * Rules (.claude/AI_ASSISTANT_PLAN.md "Kill switches" + "Hard limits"):
 *  - rh_ai_enabled() is FAIL-CLOSED. It never uses moduleEnabled() (that fails open for unknown
 *    keys and a missing table). It is true only when the setting ai_enabled is '1' AND the
 *    enabled_modules row 'ai_assistant' exists with is_enabled = 1 AND the Assistant has not paused
 *    itself (setting ai_paused_reason empty) AND migration 070 has run. Any error -> false.
 *  - Settings are read straight from site_settings (not the hour-long settings file cache), so
 *    switching off takes effect on the next request.
 *  - Provider keys are encrypted (AES-256-CBC, prefix "ai1:"), never logged, never sent to the
 *    browser. Keys are refused while APP_ENCRYPTION_SALT is missing or still the fallback value.
 */

if (!defined('RH_AI_DEFAULT_SALT')) {
    define('RH_AI_DEFAULT_SALT', 'DEFAULT_SALT_CHANGE_IN_ENV');
}

/** The seven tables migration 070 creates. */
function rh_ai_tables(): array
{
    return ['ai_providers', 'ai_models', 'ai_conversations', 'ai_messages', 'ai_actions', 'ai_user_prefs', 'ai_usage_daily'];
}

/** True when migration 070 has run (all seven tables exist). Never throws. */
function rh_ai_schema_ready(?PDO $db = null): bool
{
    $db = $db ?? ($GLOBALS['pdo'] ?? null);
    if (!$db instanceof PDO) {
        return false;
    }
    try {
        $in = "'" . implode("','", rh_ai_tables()) . "'";
        $n = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)")->fetchColumn();
        return $n === count(rh_ai_tables());
    } catch (Throwable $e) {
        return false;
    }
}

/** True when migration 073 (alerts, audit chain, lost-demand log) has run. Never throws; memoised per request. */
function rh_ai_extras_ready(?PDO $db = null, bool $fresh = false): bool
{
    static $memo = null;
    if ($memo !== null && !$fresh) {
        return $memo;
    }
    $db = $db ?? ($GLOBALS['pdo'] ?? null);
    if (!$db instanceof PDO) {
        return false;
    }
    try {
        $n = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('ai_alerts', 'ai_audit', 'demand_log')")->fetchColumn();
        return $memo = ($n === 3);
    } catch (Throwable $e) {
        return $memo = false;
    }
}

/** Tables the chat needs on top of 070 (migration 071: privacy vault + answer cache). */
function rh_ai_chat_tables(): array
{
    return ['ai_vault', 'ai_tool_cache'];
}

/** True when migrations 070 and 071 have both run. Never throws. */
function rh_ai_chat_ready(?PDO $db = null): bool
{
    $db = $db ?? ($GLOBALS['pdo'] ?? null);
    if (!$db instanceof PDO || !rh_ai_schema_ready($db)) {
        return false;
    }
    try {
        $in = "'" . implode("','", rh_ai_chat_tables()) . "'";
        $n = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)")->fetchColumn();
        return $n === count(rh_ai_chat_tables());
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * The master kill switch, fail-closed. One query; any exception (missing table, lost connection,
 * odd value) means OFF.
 */
function rh_ai_enabled(?PDO $db = null): bool
{
    $db = $db ?? ($GLOBALS['pdo'] ?? null);
    if (!$db instanceof PDO) {
        return false;
    }
    try {
        $row = $db->query(
            "SELECT
                (SELECT setting_value FROM site_settings WHERE setting_key = 'ai_enabled') AS en,
                (SELECT setting_value FROM site_settings WHERE setting_key = 'ai_paused_reason') AS paused,
                (SELECT setting_value FROM site_settings WHERE setting_key = 'ai_paused_kind') AS paused_kind,
                (SELECT setting_value FROM site_settings WHERE setting_key = 'ai_paused_month') AS paused_month,
                (SELECT is_enabled FROM enabled_modules WHERE module_key = 'ai_assistant') AS module_on,
                (SELECT COUNT(*) FROM ai_providers WHERE 1 = 0) AS schema_probe"
        )->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
    if (!is_array($row)) {
        return false;
    }
    if ((string)($row['en'] ?? '') !== '1') {
        return false;
    }
    if ($row['module_on'] === null || (string)$row['module_on'] !== '1') {
        return false;
    }
    if (trim((string)($row['paused'] ?? '')) !== '') {
        // A pause for this month's spending limit lifts itself when the month changes (the limit counts
        // from the 1st). Every other automatic pause waits for a person (master switch on, or ai-kill.php on).
        if ((string)($row['paused_kind'] ?? '') === 'budget' && (string)($row['paused_month'] ?? '') !== '' && (string)$row['paused_month'] !== date('Y-m')) {
            try {
                $db->prepare("UPDATE site_settings SET setting_value = '' WHERE setting_key IN ('ai_paused_reason', 'ai_paused_kind', 'ai_paused_month')")->execute();
                rh_ai_settings($db, true);
                return true;
            } catch (Throwable $e) {
                return false;
            }
        }
        return false;
    }
    return true;
}

/** Why the Assistant is off, in plain words ('' when it is on). */
function rh_ai_off_reason(?PDO $db = null): string
{
    $db = $db ?? ($GLOBALS['pdo'] ?? null);
    if (!rh_ai_schema_ready($db)) {
        return 'The database update for the Assistant has not been run yet.';
    }
    if (rh_ai_enabled($db)) {
        return '';
    }
    $s = rh_ai_settings($db, true);
    if (trim((string)($s['ai_paused_reason'] ?? '')) !== '') {
        return 'Paused automatically: ' . (string)$s['ai_paused_reason'];
    }
    return 'Switched off.';
}

/** Defaults for the settings group 'ai' (the migration seeds the same values). */
function rh_ai_setting_defaults(): array
{
    return [
        'ai_enabled'              => '0',
        'ai_provider'             => '',
        'ai_user_daily_token_cap' => '200000',
        'ai_monthly_budget_usd'   => '20',
        'ai_rate_limit_per_10min' => '30',
        'ai_turn_token_cap'       => '60000',
        'ai_retention_days'       => '0',
        'ai_paused_reason'        => '',
        'ai_actions_enabled'      => '1', // action cards (Phase 4); '0' = chat only
        // Phase 4b - learning loop + self-healing (kill-switch layer 4: learning frozen / cloud providers off)
        'ai_learning_enabled'     => '1',
        'ai_cloud_enabled'        => '1',
        'ai_paused_kind'          => '',  // budget | actions | eval | cli (who paused it)
        'ai_paused_month'         => '',  // Y-m of a budget pause (it lifts itself next month)
        'ai_feature_state'        => 'learning', // learning -> complete (completion guardrail)
        'ai_feature_state_at'     => '',
        'ai_feature_state_note'   => '',
        'ai_complete_days'        => '14',
        'ai_complete_eval_pct'    => '95',
        'ai_eval_smoke_min_pct'   => '80',
        'ai_memory_promote_wins'  => '3',
        'ai_models_refreshed_at'  => '',
        'ai_daily_ran_on'         => '',
        'ai_jobs_json'            => '',
        'ai_health_json'          => '',
        'ai_notices_json'         => '',
        'ai_model_upgrade_json'   => '',
        'ai_daily_token'          => '',
        // Phases 4c + 5 - owner extras and polish (migration 073 seeds the same values)
        'ai_idle_lock_minutes'    => '15', // the panel locks after this many minutes without portal use (0 = never)
        'ai_digest_enabled'       => '1',  // weekly digest email (each person can opt out in their reply settings)
        'ai_digest_sent_week'     => '',   // ISO week (o-W) of the last digest sent
        'ai_budget_warned'        => '',   // Y-m:80 / Y-m:100 - the budget notice already given this month
    ];
}

/**
 * All ai_* settings, straight from site_settings (one query, kept for the request).
 * Missing keys get their defaults. $fresh re-reads.
 */
function rh_ai_settings(?PDO $db = null, bool $fresh = false): array
{
    static $memo = null;
    if ($memo !== null && !$fresh) {
        return $memo;
    }
    $out = rh_ai_setting_defaults();
    $db = $db ?? ($GLOBALS['pdo'] ?? null);
    if ($db instanceof PDO) {
        try {
            $rows = $db->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'ai\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $k => $v) {
                $out[(string)$k] = (string)$v;
            }
        } catch (Throwable $e) {
            // defaults stand
        }
    }
    return $memo = $out;
}

function rh_ai_setting(string $key, string $default = ''): string
{
    $s = rh_ai_settings();
    return array_key_exists($key, $s) ? (string)$s[$key] : $default;
}

/** Daily token cap per person (tokens in + out). */
function rh_ai_daily_token_cap(): int
{
    return max(0, (int)rh_ai_setting('ai_user_daily_token_cap', '200000'));
}

/** Monthly estimated-cost cap in US dollars. */
function rh_ai_monthly_budget_usd(): float
{
    return max(0.0, (float)rh_ai_setting('ai_monthly_budget_usd', '20'));
}

/** Most tokens (in + out, every model call: lead, specialists, merge) one question may use. */
function rh_ai_turn_token_cap(): int
{
    return max(5000, (int)rh_ai_setting('ai_turn_token_cap', '60000'));
}

/** Messages a person may send in 10 minutes. */
function rh_ai_rate_limit_per_10min(): int
{
    return max(1, (int)rh_ai_setting('ai_rate_limit_per_10min', '30'));
}

/** Days chats are kept; 0 = keep. */
function rh_ai_retention_days(): int
{
    return max(0, (int)rh_ai_setting('ai_retention_days', '0'));
}

/** The provider whose models are in use ('' = none chosen). */
function rh_ai_active_provider(): string
{
    return rh_ai_setting('ai_provider', '');
}

/** Upsert ai_* settings (group 'ai') and drop cached copies. Keys must start with "ai_". */
function rh_ai_save_settings(array $pairs, ?PDO $db = null): void
{
    $db = $db ?? $GLOBALS['pdo'];
    $st = $db->prepare(
        "INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai')
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    foreach ($pairs as $k => $v) {
        $k = (string)$k;
        if (strncmp($k, 'ai_', 3) !== 0) {
            throw new InvalidArgumentException('Only Assistant settings can be saved here.');
        }
        $st->execute([$k, (string)$v]);
        if (isset($GLOBALS['_SITE_SETTINGS']) && is_array($GLOBALS['_SITE_SETTINGS'])) {
            unset($GLOBALS['_SITE_SETTINGS'][$k]);
        }
    }
    if (function_exists('clearSettingsCache')) {
        clearSettingsCache();
    }
    rh_ai_settings($db, true);
}

/**
 * Master switch: the setting AND the module row together (switching off never deletes anything).
 * The enabled_modules table is created/seeded by moduleEnabled() first when it does not exist yet.
 */
function rh_ai_set_master(bool $on, ?PDO $db = null): void
{
    $db = $db ?? $GLOBALS['pdo'];
    if (function_exists('moduleEnabled')) {
        moduleEnabled('bookings'); // makes sure enabled_modules exists (it seeds the standard modules)
    }
    $db->prepare(
        "INSERT INTO enabled_modules (module_key, module_name, description, is_enabled, sort_order)
         VALUES ('ai_assistant', 'Assistant', 'Staff chat helper in the admin portal (off until set up in Assistant Settings)', ?, 90)
         ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)"
    )->execute([$on ? 1 : 0]);
    $pairs = ['ai_enabled' => $on ? '1' : '0'];
    if ($on) {
        $pairs['ai_paused_reason'] = ''; // a person switching it on again lifts an automatic pause
        $pairs['ai_paused_kind'] = '';
        $pairs['ai_paused_month'] = '';
    }
    rh_ai_save_settings($pairs, $db);
}

// ── Key encryption ────────────────────────────────────────────────────────────

/** The server encryption salt ('' when not configured). */
function rh_ai_salt(): string
{
    $salt = $_ENV['APP_ENCRYPTION_SALT'] ?? getenv('APP_ENCRYPTION_SALT');
    return is_string($salt) ? $salt : '';
}

/** True when keys may not be stored: the salt is missing or still the fallback value. */
function rh_ai_salt_is_default(): bool
{
    $salt = rh_ai_salt();
    return $salt === '' || $salt === RH_AI_DEFAULT_SALT;
}

/**
 * Encrypt a provider key. Mirrors facebookEncryptSecret() (AES-256-CBC, random IV) with its own
 * context "|ai" and prefix "ai1:". Throws when the salt is the fallback (keys are then refused).
 */
function rh_ai_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    if (rh_ai_salt_is_default()) {
        throw new RuntimeException('Keys cannot be saved until the server encryption setting (APP_ENCRYPTION_SALT) is set. Ask whoever hosts the system to add it.');
    }
    $key = hash('sha256', rh_ai_salt() . '|ai', true);
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($enc === false) {
        throw new RuntimeException('The key could not be encrypted, so it was not saved.');
    }
    return 'ai1:' . base64_encode($iv . $enc);
}

/** Decrypt a value from rh_ai_encrypt(); '' when it cannot be read. Server-side use only. */
function rh_ai_decrypt(string $stored): string
{
    if ($stored === '' || strncmp($stored, 'ai1:', 4) !== 0 || rh_ai_salt_is_default()) {
        return '';
    }
    $data = base64_decode(substr($stored, 4), true);
    if ($data === false || strlen($data) < 17) {
        return '';
    }
    $key = hash('sha256', rh_ai_salt() . '|ai', true);
    $dec = openssl_decrypt(substr($data, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($data, 0, 16));
    return $dec === false ? '' : $dec;
}

/** "••••abcd" for a key's last four characters ('' when none). */
function rh_ai_mask_last4(string $last4): string
{
    return $last4 === '' ? '' : "\u{2022}\u{2022}\u{2022}\u{2022}" . $last4;
}

/** A pasted key is plausible: no spaces, 20-300 printable characters. */
function rh_ai_key_looks_valid(string $key): bool
{
    return (bool)preg_match('/^[\x21-\x7E]{20,300}$/', $key);
}

/** Store a provider key encrypted (status back to "untested"). Throws on default salt / bad key. */
function rh_ai_store_key(string $provider, string $plainKey, int $userId, ?PDO $db = null): void
{
    $db = $db ?? $GLOBALS['pdo'];
    $plainKey = trim($plainKey);
    if (!rh_ai_key_looks_valid($plainKey)) {
        throw new RuntimeException('That key does not look right (no spaces; 20 to 300 characters). Copy it again from the provider.');
    }
    $enc = rh_ai_encrypt($plainKey);
    $db->prepare(
        "INSERT INTO ai_providers (provider, api_key_enc, key_last4, status, last_test_at, last_test_ms, last_test_message, updated_by)
         VALUES (?, ?, ?, 'untested', NULL, NULL, NULL, ?)
         ON DUPLICATE KEY UPDATE api_key_enc = VALUES(api_key_enc), key_last4 = VALUES(key_last4), status = 'untested',
             last_test_at = NULL, last_test_ms = NULL, last_test_message = NULL, updated_by = VALUES(updated_by)"
    )->execute([$provider, $enc, substr($plainKey, -4), $userId ?: null]);
}

/** The decrypted key for server-side calls ('' when none or unreadable). Never send this anywhere. */
function rh_ai_get_key(string $provider, ?PDO $db = null): string
{
    $db = $db ?? ($GLOBALS['pdo'] ?? null);
    if (!$db instanceof PDO) {
        return '';
    }
    try {
        $st = $db->prepare("SELECT api_key_enc FROM ai_providers WHERE provider = ?");
        $st->execute([$provider]);
        return rh_ai_decrypt((string)$st->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
}

/** Remove a provider key (its models stay listed; chosen models of that provider are un-chosen). */
function rh_ai_remove_key(string $provider, ?PDO $db = null): void
{
    $db = $db ?? $GLOBALS['pdo'];
    $db->prepare("UPDATE ai_providers SET api_key_enc = NULL, key_last4 = NULL, status = 'none', last_test_message = NULL WHERE provider = ?")
        ->execute([$provider]);
    $db->prepare("UPDATE ai_models SET is_selected = 0, role = NULL WHERE provider = ?")->execute([$provider]);
    if (rh_ai_settings($db, true)['ai_provider'] === $provider) {
        rh_ai_save_settings(['ai_provider' => ''], $db);
    }
}

/** Record a connection test result (message is already scrubbed of any key). */
function rh_ai_record_test(string $provider, bool $ok, int $ms, string $message, ?PDO $db = null): void
{
    $db = $db ?? $GLOBALS['pdo'];
    $db->prepare("UPDATE ai_providers SET status = ?, last_test_at = NOW(), last_test_ms = ?, last_test_message = ? WHERE provider = ?")
        ->execute([$ok ? 'ok' : 'error', max(0, $ms), mb_substr($message, 0, 250), $provider]);
}

/**
 * Provider rows for display: never contains the key or its encrypted form.
 * @return array<string, array{provider:string,has_key:bool,key_last4:string,status:string,last_test_at:?string,last_test_ms:?int,last_test_message:string}>
 */
function rh_ai_provider_states(?PDO $db = null): array
{
    $db = $db ?? ($GLOBALS['pdo'] ?? null);
    $out = [];
    if (!$db instanceof PDO) {
        return $out;
    }
    try {
        $rows = $db->query("SELECT provider, (api_key_enc IS NOT NULL AND api_key_enc <> '') AS has_key, key_last4, status, last_test_at, last_test_ms, last_test_message FROM ai_providers")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $out;
    }
    foreach ($rows as $r) {
        $out[(string)$r['provider']] = [
            'provider'          => (string)$r['provider'],
            'has_key'           => (bool)(int)$r['has_key'],
            'key_last4'         => (bool)(int)$r['has_key'] ? (string)$r['key_last4'] : '',
            'status'            => (string)$r['status'],
            'last_test_at'      => $r['last_test_at'] !== null ? (string)$r['last_test_at'] : null,
            'last_test_ms'      => $r['last_test_ms'] !== null ? (int)$r['last_test_ms'] : null,
            'last_test_message' => (string)($r['last_test_message'] ?? ''),
        ];
    }
    return $out;
}
