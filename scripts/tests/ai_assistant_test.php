<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * The staff Assistant (.claude/AI_ASSISTANT_PLAN.md). Phase 1 - Foundation (ships OFF):
 * fail-closed kill switch, key encryption, provider adapters (list models + test reply) against
 * fixtures, model catalog + ranking, ai_models sync/selection, permissions, the settings page and
 * the weekly refresh script.
 *
 * Usage (from the repo root): php scripts/tests/ai_assistant_test.php
 * No network: every provider call goes through a fake transport (scripts/tests/fixtures/ai/*.json).
 * Live DB: library checks run inside transactions that are rolled back; page checks run through
 * scripts/tests/page_harness.php (also rolled back). Before migration 070 has run (e.g. Rosalyn's
 * until deploy) the database checks are skipped and the "not set up / off" behaviour is checked.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0; $skip = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function skip(string $l): void { global $skip; $skip++; echo "SKIP $l\n"; }

require_once $root . '/config/database.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/includes/ai/ai-catalog.php';

/** A second connection that can rename tables in every statement (to make one look missing). */
class RhAiRewritePdo extends PDO
{
    public $rewrite = [];
    private function rw($sql): string { return strtr((string)$sql, $this->rewrite); }
    #[\ReturnTypeWillChange]
    public function query($statement, $mode = null, ...$args)
    {
        $s = $this->rw($statement);
        return $mode === null ? parent::query($s) : parent::query($s, $mode, ...$args);
    }
    #[\ReturnTypeWillChange]
    public function prepare($statement, $options = []) { return parent::prepare($this->rw($statement), $options); }
    #[\ReturnTypeWillChange]
    public function exec($statement) { return parent::exec($this->rw($statement)); }
}

$fx = static function (string $name) use ($root): string {
    return (string)file_get_contents($root . '/scripts/tests/fixtures/ai/' . $name . '_models.json');
};

// Fake provider: model lists from fixtures, "OK" test replies, optional failure modes. Records every call.
$GLOBALS['ai_calls'] = [];
$GLOBALS['ai_mode'] = 'ok';
$FAKE_KEY = 'sk-ant-api03-FAKEKEY0123456789abcdefghijklmnopWXYZ';
rh_ai_set_transport(static function ($method, $url, $headers, $body, $timeout) use ($fx, $FAKE_KEY) {
    $GLOBALS['ai_calls'][] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => (string)$body];
    if ($GLOBALS['ai_mode'] === 'refused') {
        return ['http' => 401, 'body' => json_encode(['error' => ['type' => 'authentication_error', 'message' => 'Incorrect API key provided: ' . $FAKE_KEY . '. You can find your key at console.']])];
    }
    if ($GLOBALS['ai_mode'] === 'down') {
        return ['http' => 0, 'body' => '', 'error' => 'Could not resolve host'];
    }
    $map = ['api.anthropic.com' => 'anthropic', 'api.openai.com' => 'openai', 'generativelanguage.googleapis.com' => 'google', 'api.x.ai' => 'xai', 'api.deepseek.com' => 'deepseek'];
    $prov = '';
    foreach ($map as $host => $p) { if (strpos($url, $host) !== false) { $prov = $p; } }
    if ($method === 'GET' && preg_match('#/models(\?|$)#', $url)) {
        return ['http' => 200, 'body' => $fx($prov)];
    }
    if ($prov === 'anthropic') { return ['http' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => 'OK']], 'usage' => ['input_tokens' => 9, 'output_tokens' => 1]])]; }
    if ($prov === 'google') { return ['http' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'OK']]]]]])]; }
    return ['http' => 200, 'body' => json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => 'OK']]]])];
});

$schemaReady = rh_ai_schema_ready($pdo);
echo $schemaReady ? "Migration 070 has run on this database.\n" : "Migration 070 has NOT run on this database: database checks are skipped.\n";

// ════ Permissions ════
$all = getAllPermissions();
foreach (['ai_assistant_use', 'ai_assistant_actions', 'ai_settings', 'ai_usage_view'] as $k) {
    check(isset($all[$k]) && isset(getPermissionGroups()[$all[$k]['group']]), "permission $k exists in a known group");
    check(isset(getNavCategories()[$all[$k]['category']]), "permission $k has a category the Staff & Access screen shows");
}
$adminDef = getDefaultPermissionsForRole('admin');
check(!array_diff(['ai_assistant_use', 'ai_assistant_actions', 'ai_settings', 'ai_usage_view'], $adminDef), 'admin role has all four Assistant permissions');
$mgrDef = getDefaultPermissionsForRole('manager');
check(!array_diff(['ai_assistant_use', 'ai_assistant_actions', 'ai_usage_view'], $mgrDef) && !in_array('ai_settings', $mgrDef, true), 'manager role: use + actions + usage, not settings');
$leak = [];
foreach (array_keys(getAllRoles()) as $role) {
    if (in_array($role, ['admin', 'manager'], true)) { continue; }
    foreach (getDefaultPermissionsForRole($role) as $p) { if (strncmp($p, 'ai_', 3) === 0) { $leak[] = "$role:$p"; } }
}
check(!$leak, 'no other role gets an Assistant permission by default', implode(', ', $leak));
check(getPermissionForPage('ai-settings.php') === 'ai_settings', 'ai-settings.php needs ai_settings');
check(getModuleForPage('ai-settings.php') === null, 'ai-settings.php is not module-gated (it holds the master switch)');
check(getModuleForPage('ai-chat.php') === 'ai_assistant' && getModuleForPage('ai-action.php') === 'ai_assistant', 'Assistant endpoints follow the ai_assistant module');
check(in_array('ai_assistant_use', getPermissionsForModule('ai_assistant'), true) && in_array('ai_assistant_actions', getPermissionsForModule('ai_assistant'), true), 'getPermissionsForModule(ai_assistant) lists the chat permissions');
$recep = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role NOT IN ('admin','manager') ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$mgr = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role = 'manager' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$adminId = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if ($recep) {
    check(!hasPermission((int)$recep['id'], 'ai_settings'), 'other staff do not hold ai_settings');
}
$navOk = false;
require_once $root . '/admin/includes/admin-nav-items.php';
foreach (rh_admin_nav_groups() as $items) { foreach ($items as $it) { if ($it[0] === 'ai-settings.php' && $it[3] === 'ai_settings' && $it[1] === 'fas fa-comments' && $it[5] === null) { $navOk = true; } } }
check($navOk, 'menu has Assistant Settings (fa-comments, ai_settings, not module-gated)');
check(strpos((string)file_get_contents($root . '/config/database.php'), 'ai_assistant') === false, 'config/database.php is untouched (no ai_assistant in the fail-open module seed)');

// ════ Encryption ════
$origEnv = array_key_exists('APP_ENCRYPTION_SALT', $_ENV) ? $_ENV['APP_ENCRYPTION_SALT'] : null;
$origGet = getenv('APP_ENCRYPTION_SALT');
if (rh_ai_salt_is_default()) {
    // This install has no salt yet: use a throw-away one for this process (and its page runs).
    $_ENV['APP_ENCRYPTION_SALT'] = 'ai-test-salt-' . bin2hex(random_bytes(8));
    putenv('APP_ENCRYPTION_SALT=' . $_ENV['APP_ENCRYPTION_SALT']);
    echo "NOTE: APP_ENCRYPTION_SALT is not set on this install; the page will refuse keys until it is.\n";
}
$enc = rh_ai_encrypt($FAKE_KEY);
check(strncmp($enc, 'ai1:', 4) === 0, 'encrypted key starts with ai1:');
check(strpos($enc, $FAKE_KEY) === false && strpos($enc, 'WXYZ') === false, 'encrypted value does not contain the key');
check(rh_ai_decrypt($enc) === $FAKE_KEY, 'encrypt/decrypt round trip');
check(rh_ai_encrypt($FAKE_KEY) !== $enc, 'each encryption uses a fresh IV');
check(rh_ai_decrypt('ai1:' . base64_encode('short')) === '' && rh_ai_decrypt('fb1:abc') === '' && rh_ai_decrypt($FAKE_KEY) === '', 'unreadable / foreign / plaintext values decrypt to empty');
$fbStyle = 'fb1:' . substr($enc, 4);
check(rh_ai_decrypt($fbStyle) === '', 'a Facebook-prefixed value is not accepted');
$saved = $_ENV['APP_ENCRYPTION_SALT'] ?? null;
$_ENV['APP_ENCRYPTION_SALT'] = 'DEFAULT_SALT_CHANGE_IN_ENV';
$threw = false;
try { rh_ai_encrypt($FAKE_KEY); } catch (RuntimeException $e) { $threw = true; }
check($threw && rh_ai_salt_is_default(), 'keys are refused while the salt is the DEFAULT_SALT_CHANGE_IN_ENV fallback');
check(rh_ai_decrypt($enc) === '', 'nothing decrypts with the fallback salt');
$_ENV['APP_ENCRYPTION_SALT'] = '';
$threw = false;
try { rh_ai_encrypt($FAKE_KEY); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'keys are refused when the salt is empty');
$_ENV['APP_ENCRYPTION_SALT'] = $saved;
check(rh_ai_mask_last4('WXYZ') === "\u{2022}\u{2022}\u{2022}\u{2022}WXYZ" && rh_ai_mask_last4('') === '', 'key mask shows only the last four');
check(!rh_ai_key_looks_valid('short') && !rh_ai_key_looks_valid("sk-ant has spaces in it 1234567890") && rh_ai_key_looks_valid($FAKE_KEY), 'key shape check');

// ════ Adapters (fixtures, fake transport) ════
$expect = [
    'anthropic' => ['claude-opus-5-5', 'claude-haiku-4-5-20251001', 'claude-opus-6', 'claude-fable-5-1', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-mystery-1'],
    'openai'    => ['gpt-5', 'gpt-5-mini'],
    'google'    => ['gemini-2.5-pro', 'gemini-2.5-flash'],
    'xai'       => ['grok-4', 'grok-3-mini'],
    'deepseek'  => ['deepseek-chat', 'deepseek-reasoner'],
];
foreach ($expect as $p => $ids) {
    $GLOBALS['ai_calls'] = [];
    $r = rh_ai_list_models($p, $FAKE_KEY);
    $got = array_column($r['models'], 'model_id');
    check($r['ok'] && $got === $ids, "$p: model list normalises to chat models only", json_encode($got));
    $shapeOk = true;
    foreach ($r['models'] as $m) {
        if (array_keys($m) !== ['model_id', 'label', 'created', 'context_tokens', 'max_output']) { $shapeOk = false; }
    }
    check($shapeOk, "$p: every model has the normalised shape");
    $c = $GLOBALS['ai_calls'][0] ?? [];
    check(isset($c['url']) && strpos($c['url'], $FAKE_KEY) === false && strpos(implode("\n", $c['headers']), $FAKE_KEY) !== false, "$p: key travels in a header, never in the URL");
}
$a = rh_ai_list_models('anthropic', $FAKE_KEY)['models'];
$op = array_values(array_filter($a, static fn($m) => $m['model_id'] === 'claude-opus-5-5'))[0];
check($op['label'] === 'Claude Opus 5.5' && $op['context_tokens'] === 1000000 && $op['max_output'] === 128000 && $op['created'] === '2026-09-15', 'anthropic: display_name, max_input_tokens, max_tokens, created_at mapped');
$GLOBALS['ai_calls'] = [];
rh_ai_list_models('google', $FAKE_KEY);
check(strpos($GLOBALS['ai_calls'][0]['url'], '/v1beta/models') !== false, 'google: lists /v1beta/models');
foreach (['anthropic' => 'https://api.anthropic.com/v1/models', 'openai' => 'https://api.openai.com/v1/models', 'xai' => 'https://api.x.ai/v1/models', 'deepseek' => 'https://api.deepseek.com/models'] as $p => $u) {
    $GLOBALS['ai_calls'] = [];
    rh_ai_list_models($p, $FAKE_KEY);
    check(strncmp($GLOBALS['ai_calls'][0]['url'], $u, strlen($u)) === 0, "$p: official model-list endpoint");
}
$GLOBALS['ai_calls'] = [];
rh_ai_list_models('anthropic', $FAKE_KEY);
check(in_array('anthropic-version: 2023-06-01', $GLOBALS['ai_calls'][0]['headers'], true), 'anthropic: anthropic-version header sent');
foreach (['anthropic', 'openai', 'google', 'xai', 'deepseek'] as $p) {
    $GLOBALS['ai_calls'] = [];
    $pg = rh_ai_ping($p, $FAKE_KEY, $expect[$p][0]);
    $b = json_decode($GLOBALS['ai_calls'][0]['body'] ?? '', true);
    $lim = $b['max_tokens'] ?? $b['max_completion_tokens'] ?? $b['generationConfig']['maxOutputTokens'] ?? null;
    check($pg['ok'] && $pg['reply'] === 'OK' && $lim === 5 && strpos(json_encode($b), 'Reply OK') !== false, "$p: test reply is a 5-token \"Reply OK\" call");
}
foreach (['text-embedding-3-small', 'tts-1-hd', 'whisper-1', 'dall-e-3', 'gpt-image-1', 'omni-moderation-latest', 'gpt-4o-realtime-preview', 'imagen-4.0', 'gemini-embedding-001', 'gpt-4o-transcribe', 'veo-3'] as $id) {
    if (rh_ai_is_chat_model($id)) { bad("$id must be filtered out as not a chat model"); }
}
ok('embedding/tts/image/audio/moderation/realtime models are filtered out');
check(rh_ai_is_chat_model('claude-opus-5-5') && rh_ai_is_chat_model('gpt-5') && rh_ai_is_chat_model('gemini-2.5-pro') && rh_ai_is_chat_model('deepseek-chat'), 'chat models pass the filter');

// Failures never carry the key
$GLOBALS['ai_mode'] = 'refused';
$t = rh_ai_test_connection('openai', $FAKE_KEY);
$tj = json_encode($t);
check(!$t['ok'] && strpos($t['message'], 'HTTP 401') !== false, 'refused key: plain message with the HTTP code', $t['message']);
check(strpos($tj, $FAKE_KEY) === false && strpos($tj, substr($FAKE_KEY, 0, 12)) === false && strpos($tj, substr($FAKE_KEY, -8)) === false, 'refused key: the key (even echoed back by the provider) is not in the result');
$GLOBALS['ai_mode'] = 'down';
$t = rh_ai_test_connection('anthropic', $FAKE_KEY);
check(!$t['ok'] && strpos($t['message'], 'Could not reach') !== false, 'no internet: plain message');
$GLOBALS['ai_mode'] = 'ok';
check(strpos(rh_ai_scrub('token xai-ABCDEFGHIJKLMNOP and AIzaSyA1234567890abcdef', ''), 'xai-ABC') === false, 'scrub removes key-shaped strings');
$t = rh_ai_test_connection('anthropic', $FAKE_KEY);
check($t['ok'] && $t['ping_model'] === 'claude-fable-5-1' && strpos($t['message'], 'Connected. 7 chat models found') === 0, 'test connection lists models and pings the best one', $t['message']);
check(strpos(json_encode($t), $FAKE_KEY) === false, 'successful test result does not contain the key');

// ════ Catalog and ranking ════
$cat = rh_ai_catalog();
check(count($cat['providers']) === 5 && !array_diff(array_keys(rh_ai_provider_list()), array_keys($cat['providers'])), 'catalog covers the five providers');
$want = [
    'claude-fable-5-1' => [1000000, 10, 50], 'claude-opus-5-5' => [1000000, 4, 20], 'claude-sonnet-5-5' => [1000000, 2, 10],
    'claude-haiku-5-5' => [1000000, 0.10, 0.50], 'claude-opus-5' => [1000000, 5, 25], 'claude-sonnet-5' => [1000000, 2, 10], 'claude-haiku-4-5' => [200000, 1, 5],
];
$an = rh_ai_catalog_models('anthropic');
check(array_column($an, 'model_id') === array_keys($want), 'anthropic catalog ranks Fable 5.1 > Opus 5.5 > Sonnet 5.5 > Haiku 5.5 > older', json_encode(array_column($an, 'model_id')));
$priceOk = true;
foreach ($an as $m) {
    [$ctx, $pi, $po] = $want[$m['model_id']];
    if ($m['context_tokens'] !== $ctx || abs($m['price_in'] - $pi) > 0.0001 || abs($m['price_out'] - $po) > 0.0001 || !$m['verified']) { $priceOk = false; }
}
check($priceOk, 'anthropic catalog context and prices match the verified list');
$unv = true;
foreach (['openai', 'google', 'xai', 'deepseek'] as $p) { foreach (rh_ai_catalog_models($p) as $m) { if ($m['verified']) { $unv = false; } } }
check($unv, 'other providers\' catalog entries are marked unverified');
check(rh_ai_model_version('claude-opus-5-5') === 5.5 && rh_ai_model_version('claude-haiku-4-5-20251001') === 4.5 && rh_ai_model_version('gemini-2.5-flash-preview-05-20') === 2.5 && rh_ai_model_version('grok-4-0709') === 4.0 && rh_ai_model_version('gpt-4o-mini') === 4.0, 'version parsing');
check(rh_ai_model_family('claude-opus-5-5') === 'claude-opus' && rh_ai_model_family('claude-haiku-4-5-20251001') === 'claude-haiku' && rh_ai_model_family('gemini-2.5-flash-preview-05-20') === 'gemini-flash' && rh_ai_model_family('gpt-5-mini') === 'gpt-mini', 'family parsing');
$ranked = rh_ai_rank_models('anthropic', rh_ai_list_models('anthropic', $FAKE_KEY)['models']);
$order = array_column($ranked, 'model_id');
check(array_slice($order, 0, 3) === ['claude-fable-5-1', 'claude-opus-6', 'claude-opus-5-5'], 'an unknown newer Opus ranks just above the newest known Opus', json_encode($order));
check(end($order) === 'claude-mystery-1', 'an unknown family ranks below the known models');
$byId = array_column($ranked, null, 'model_id');
check($byId['claude-opus-6']['is_new'] && $byId['claude-opus-6']['price_in'] === null && $byId['claude-opus-6']['source'] === 'live', 'unknown id is flagged new with no price (set price)');
check(!$byId['claude-haiku-4-5-20251001']['is_new'] && abs($byId['claude-haiku-4-5-20251001']['price_in'] - 1) < 0.0001, 'a dated id matches its catalog entry');
check(rh_ai_suggest_top5($ranked) === ['claude-fable-5-1', 'claude-opus-6', 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'], 'top-5 suggestion');
$roles = rh_ai_suggest_roles($ranked);
check(($roles['claude-fable-5-1'] ?? '') === 'primary' && ($roles['claude-opus-6'] ?? '') === 'fallback' && ($roles['claude-haiku-5-5'] ?? '') === 'fast' && count($roles) === 5, 'suggested roles: best Primary, second Fallback, cheapest Fast', json_encode($roles));
$rk = rh_ai_rank_models('openai', rh_ai_list_models('openai', $FAKE_KEY)['models']);
check(array_column($rk, 'model_id') === ['gpt-5', 'gpt-5-mini'], 'openai live list ranks with the catalog');

// ════ Kill switch (fail-closed) ════
$GLOBALS['ai_calls'] = [];
if (!$schemaReady) {
    check(!rh_ai_enabled($pdo), 'before migration 070: the Assistant is off');
    $r = rh_ai_model_refresh_run($pdo);
    check(!$r['ran'] && !$GLOBALS['ai_calls'], 'before migration 070: the refresh does nothing (no network)');
    check(rh_ai_off_reason($pdo) !== '' && rh_ai_provider_states($pdo) === [], 'before migration 070: plain off reason, no provider rows');
} else {
    $t2 = new RhAiRewritePdo('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $t2->beginTransaction();
    try {
        $setS = $t2->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $setM = $t2->prepare("INSERT INTO enabled_modules (module_key, module_name, description, is_enabled, sort_order) VALUES ('ai_assistant', 'Assistant', '', ?, 90) ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)");
        $live = (string)$t2->query("SELECT setting_value FROM site_settings WHERE setting_key = 'ai_enabled'")->fetchColumn();
        check($live === '0', 'shipped state: ai_enabled is 0');
        $mrow = $t2->query("SELECT is_enabled FROM enabled_modules WHERE module_key = 'ai_assistant'")->fetchColumn();
        check($mrow !== false && (int)$mrow === 0, 'shipped state: module row ai_assistant exists and is off');
        check(!rh_ai_enabled($t2), 'shipped state: the Assistant is off');

        $setS->execute(['ai_enabled', '1']); $setS->execute(['ai_paused_reason', '']); $setM->execute([1]);
        check(rh_ai_enabled($t2), 'setting on + module on + not paused = on');
        $setS->execute(['ai_enabled', '0']);
        check(!rh_ai_enabled($t2), 'setting off = off');
        $setS->execute(['ai_enabled', 'yes']);
        check(!rh_ai_enabled($t2), 'an odd setting value = off');
        $setS->execute(['ai_enabled', '1']); $setM->execute([0]);
        check(!rh_ai_enabled($t2), 'module off = off');
        $setM->execute([1]); $setS->execute(['ai_paused_reason', 'monthly cost limit reached']);
        check(!rh_ai_enabled($t2), 'automatic pause = off');
        $setS->execute(['ai_paused_reason', '']);
        check(rh_ai_enabled($t2), 'back on once the pause is lifted');
        $t2->exec("DELETE FROM enabled_modules WHERE module_key = 'ai_assistant'");
        check(!rh_ai_enabled($t2), 'module row missing = off (moduleEnabled() would say on)');
        $setM->execute([1]);
        $t2->exec("DELETE FROM site_settings WHERE setting_key = 'ai_enabled'");
        check(!rh_ai_enabled($t2), 'setting row missing = off');
        $setS->execute(['ai_enabled', '1']);
        check(rh_ai_enabled($t2), 'on again (sanity)');
        $t2->rewrite = ['enabled_modules' => 'zz_missing_enabled_modules'];
        check(!rh_ai_enabled($t2), 'enabled_modules table missing = off');
        $t2->rewrite = ['site_settings' => 'zz_missing_site_settings'];
        check(!rh_ai_enabled($t2), 'site_settings table missing = off');
        $t2->rewrite = ['ai_providers' => 'zz_missing_ai_providers'];
        check(!rh_ai_enabled($t2), 'migration 070 tables missing = off');
        $t2->rewrite = ['ai_usage_daily' => 'zz_missing_ai_usage_daily'];
        check(!rh_ai_schema_ready($t2), 'schema check notices a missing Assistant table');
        $t2->rewrite = ['SELECT' => 'SELEKT'];
        check(!rh_ai_enabled($t2), 'any database error = off');
        $t2->rewrite = [];
        check(!rh_ai_enabled($pdo), 'the main connection still sees the shipped OFF state');
    } finally {
        $t2->rollBack();
    }

    // ════ Keys, models and the refresh, inside a rolled-back transaction ════
    $pdo->beginTransaction();
    try {
        rh_ai_store_key('anthropic', $FAKE_KEY, $adminId, $pdo);
        $row = $pdo->query("SELECT * FROM ai_providers WHERE provider = 'anthropic'")->fetch(PDO::FETCH_ASSOC);
        check(strncmp((string)$row['api_key_enc'], 'ai1:', 4) === 0 && strpos((string)$row['api_key_enc'], $FAKE_KEY) === false, 'stored key is encrypted (ai1:)');
        check($row['key_last4'] === 'WXYZ' && $row['status'] === 'untested', 'stored key keeps only its last four in clear');
        check(rh_ai_get_key('anthropic', $pdo) === $FAKE_KEY, 'server-side read of the key works');
        $states = rh_ai_provider_states($pdo);
        $sj = json_encode($states);
        check($states['anthropic']['has_key'] && strpos($sj, $FAKE_KEY) === false && strpos($sj, (string)$row['api_key_enc']) === false && strpos($sj, 'api_key_enc') === false, 'provider states for display never carry the key or its encrypted form');
        $threw = false;
        try { rh_ai_store_key('anthropic', 'has spaces in it 1234567890', $adminId, $pdo); } catch (RuntimeException $e) { $threw = true; }
        check($threw, 'a malformed key is refused');

        // Off: the refresh makes no call at all
        rh_ai_save_settings(['ai_enabled' => '0'], $pdo);
        $GLOBALS['ai_calls'] = [];
        $r = rh_ai_model_refresh_run($pdo);
        check(!$r['ran'] && count($GLOBALS['ai_calls']) === 0, 'refresh is a no-op (no network) while the Assistant is off');

        // Test result + model sync
        $t = rh_ai_test_connection('anthropic', rh_ai_get_key('anthropic', $pdo));
        rh_ai_record_test('anthropic', $t['ok'], $t['ms'], $t['message'], $pdo);
        rh_ai_sync_models($pdo, 'anthropic', $t['ranked']);
        $rows = rh_ai_models_for($pdo, 'anthropic');
        check(count($rows) === 7 && $rows[0]['model_id'] === 'claude-fable-5-1', 'live list saved to ai_models, best first');
        $byId = array_column($rows, null, 'model_id');
        check((int)$byId['claude-opus-6']['is_new'] === 1 && $byId['claude-opus-6']['source'] === 'live' && $byId['claude-opus-6']['price_in_per_mtok'] === null, 'unknown model saved as new with no price');
        check($byId['claude-opus-5-5']['source'] === 'catalog' && abs((float)$byId['claude-opus-5-5']['price_in_per_mtok'] - 4) < 0.0001, 'catalog model saved with catalog price');
        check($pdo->query("SELECT status FROM ai_providers WHERE provider = 'anthropic'")->fetchColumn() === 'ok', 'test result recorded');

        // Selection rules
        $six = array_fill_keys(array_slice(array_column($rows, 'model_id'), 0, 6), 'extra');
        $six['claude-fable-5-1'] = 'primary';
        $e1 = '';
        try { rh_ai_save_selection($pdo, 'anthropic', $six); } catch (RuntimeException $e) { $e1 = $e->getMessage(); }
        check($e1 === 'Choose at most 5 models.', 'more than 5 models is refused');
        $e2 = '';
        try { rh_ai_save_selection($pdo, 'anthropic', ['claude-opus-5-5' => 'fast']); } catch (RuntimeException $e) { $e2 = $e->getMessage(); }
        check($e2 === 'Choose exactly one Primary model.', 'a choice without Primary is refused');
        $e3 = '';
        try { rh_ai_save_selection($pdo, 'anthropic', ['claude-nope' => 'primary']); } catch (RuntimeException $e) { $e3 = $e->getMessage(); }
        check($e3 !== '', 'a model the provider does not offer is refused');
        rh_ai_save_selection($pdo, 'anthropic', ['claude-opus-5-5' => 'primary', 'claude-haiku-5-5' => 'fast', 'claude-opus-6' => 'fallback'], ['claude-opus-6' => ['6', '30'], 'claude-opus-5-5' => ['4', '20']]);
        $sel = $pdo->query("SELECT model_id, role, price_edited, price_in_per_mtok, is_new FROM ai_models WHERE is_selected = 1 ORDER BY model_id")->fetchAll(PDO::FETCH_ASSOC);
        check(count($sel) === 3, 'three models chosen');
        $sb = array_column($sel, null, 'model_id');
        check((int)$sb['claude-opus-6']['price_edited'] === 1 && abs((float)$sb['claude-opus-6']['price_in_per_mtok'] - 6) < 0.0001 && (int)$sb['claude-opus-6']['is_new'] === 0, 'a typed price is kept and clears "set price"');
        check((int)$sb['claude-opus-5-5']['price_edited'] === 0, 'an unchanged price keeps following the catalog');
        check($pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'ai_provider'")->fetchColumn() === 'anthropic' && rh_ai_active_provider() === 'anthropic', 'saving models makes that provider the one in use');
        rh_ai_sync_models($pdo, 'anthropic', $t['ranked']);
        $p6 = (float)$pdo->query("SELECT price_in_per_mtok FROM ai_models WHERE model_id = 'claude-opus-6'")->fetchColumn();
        check(abs($p6 - 6) < 0.0001, 'a later refresh keeps the hand-typed price');
        rh_ai_sync_models($pdo, 'anthropic', array_values(array_filter($t['ranked'], static fn($m) => $m['model_id'] !== 'claude-opus-6' && $m['model_id'] !== 'claude-mystery-1')));
        $left = $pdo->query("SELECT model_id FROM ai_models WHERE provider = 'anthropic'")->fetchAll(PDO::FETCH_COLUMN);
        check(in_array('claude-opus-6', $left, true) && !in_array('claude-mystery-1', $left, true), 'a dropped model stays only if it was chosen');

        // On: the refresh runs with the saved key
        rh_ai_set_master(true, $pdo);
        check(rh_ai_enabled($pdo), 'master switch on sets the setting and the module row');
        $GLOBALS['ai_calls'] = [];
        $r = rh_ai_model_refresh_run($pdo);
        check($r['ran'] && ($r['results']['anthropic']['ok'] ?? false) && count($GLOBALS['ai_calls']) === 1 && strpos(json_encode($r), $FAKE_KEY) === false, 'refresh runs when on, one list call, no key in the result');
        rh_ai_set_master(false, $pdo);
        check(!rh_ai_enabled($pdo) && (int)$pdo->query("SELECT is_enabled FROM enabled_modules WHERE module_key = 'ai_assistant'")->fetchColumn() === 0, 'master switch off turns both off');
        check((int)$pdo->query("SELECT COUNT(*) FROM ai_models WHERE is_selected = 1")->fetchColumn() === 3 && rh_ai_get_key('anthropic', $pdo) === $FAKE_KEY, 'switching off keeps keys and choices');
        rh_ai_remove_key('anthropic', $pdo);
        check(rh_ai_get_key('anthropic', $pdo) === '' && (int)$pdo->query("SELECT COUNT(*) FROM ai_models WHERE is_selected = 1")->fetchColumn() === 0 && rh_ai_active_provider() === '', 'removing a key un-chooses its models and clears the provider in use');
    } finally {
        $pdo->rollBack();
        rh_ai_settings($pdo, true);
    }
    check(!$pdo->inTransaction() && (int)$pdo->query("SELECT COUNT(*) FROM ai_providers WHERE provider = 'anthropic' AND key_last4 = 'WXYZ'")->fetchColumn() === 0, 'library checks rolled back');
}

// The real script, as cron runs it: with the Assistant off it exits at once.
if (!rh_ai_enabled($pdo)) {
    $out = (string)shell_exec('php ' . escapeshellarg($root . '/scripts/ai-model-refresh.php') . ' 2>&1');
    check(strpos($out, 'Assistant is off - nothing to do.') !== false, 'scripts/ai-model-refresh.php exits straight away while off', trim($out));
} else {
    skip('refresh script run: the Assistant is switched on here');
}

// ════ Settings page (rolled-back harness) ════
$h = $root . '/scripts/tests/page_harness.php';
function run_page(string $page, array $cfg): array {
    global $h;
    $cmd = 'php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1';
    $out = shell_exec($cmd);
    if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -400), 'rolled_back' => false];
}
$clean = static function (array $r): bool { return !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']); };
$tmpHtml = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ai_settings_test_' . bin2hex(random_bytes(4)) . '.html';
$pageBody = static function (string $file): string {
    $html = (string)@file_get_contents($file);
    $s = strpos($html, '<div class="content ai-settings">');
    $e = strpos($html, 'rh-shared-footer-start');
    return $s === false ? '' : substr($html, $s, ($e !== false ? $e : strlen($html)) - $s);
};

if (!$schemaReady) {
    $r = run_page('admin/ai-settings.php', ['method' => 'GET', 'grep' => ['id="ai-db-update"', 'id="ai-providers"'], 'dump_html' => $tmpHtml]);
    check(($r['found']['id="ai-db-update"'] ?? false) && !($r['found']['id="ai-providers"'] ?? true), 'before migration 070: the page shows the "database update" notice instead of erroring', json_encode($r['error'] ?? $r['output_flash'] ?? ''));
    check(strpos((string)@file_get_contents($tmpHtml), 'Fatal error') === false, 'before migration 070: no PHP error on the page');
    check($clean($r), 'page run rolled back');
} else {
    $encRow = rh_ai_encrypt($FAKE_KEY);
    $seed = [
        "INSERT INTO ai_providers (provider, api_key_enc, key_last4, status, last_test_at, last_test_ms, last_test_message) VALUES ('anthropic', '" . $encRow . "', 'WXYZ', 'ok', NOW(), 321, 'Connected. 7 chat models found.') ON DUPLICATE KEY UPDATE api_key_enc = VALUES(api_key_enc), key_last4 = 'WXYZ', status = 'ok'",
        "INSERT INTO ai_models (provider, model_id, label, capability_rank, source, is_new, released_at) VALUES ('anthropic', 'claude-opus-5-5', 'Claude Opus 5.5', 95, 'catalog', 0, '2026-09-15'), ('anthropic', 'claude-opus-6', 'Claude Opus 6', 96.5, 'live', 1, '2026-10-20') ON DUPLICATE KEY UPDATE label = VALUES(label)",
    ];
    // Admin view with a saved key
    $r = run_page('admin/ai-settings.php', ['method' => 'GET', 'setup' => $seed, 'dump_html' => $tmpHtml]);
    $html = (string)@file_get_contents($tmpHtml);
    $body = $pageBody($tmpHtml);
    foreach (['ai-master', 'ai-providers', 'ai-models', 'ai-limits', 'ai-access', 'ai-usage'] as $sec) {
        if (strpos($body, 'id="' . $sec . '"') === false) { bad("admin page: section $sec missing"); }
    }
    check($body !== '' && strpos($body, 'id="ai-usage"') !== false, 'admin page renders all six sections');
    check(strpos($html, $FAKE_KEY) === false && strpos($html, $encRow) === false && strpos($html, substr($FAKE_KEY, 0, 12)) === false, 'page output never contains the key or its encrypted form');
    check(strpos($body, "\u{2022}\u{2022}\u{2022}\u{2022}WXYZ") !== false, 'page shows the key as ••••last4');
    check(strpos($body, 'New - set price') !== false && strpos($body, 'Newest') !== false, 'models table flags new models and the newest');
    check(strpos($body, 'Off - nobody sees the Assistant') !== false, 'status line says it is off');
    check(strpos($body, 'DeepSeek is hosted in China') !== false, 'DeepSeek card notes China hosting');
    check(!preg_match('/fa-(magic|wand|robot|hat-wizard)|sparkle|\bsmart\b|AI magic/i', $body), 'no sparkle/wand/robot icons or "smart" wording');
    check(substr_count($body, 'class="help" data-tip=') >= 15, 'columns and cards carry plain helpers', (string)substr_count($body, 'class="help" data-tip='));
    check(stripos($body, 'MRA') === false, 'no MRA claims on the page');
    check($clean($r), 'admin page run rolled back');

    // Other staff: no access
    if ($recep) {
        $r = run_page('admin/ai-settings.php', ['method' => 'GET', 'as_username' => $recep['username'], 'grep' => ['id="ai-master"', 'id="ai-providers"']]);
        check(!($r['found']['id="ai-master"'] ?? true) && !($r['found']['id="ai-providers"'] ?? true), 'staff without ai_settings cannot open the page');
        check($clean($r), 'staff page run rolled back');
        $r = run_page('admin/ai-settings.php', ['as_username' => $recep['username'], 'post' => ['action' => 'master', 'state' => 'on'],
            'verify' => ["SELECT setting_value FROM site_settings WHERE setting_key = 'ai_enabled'"]]);
        check(($r['verify'][0][0]['setting_value'] ?? '') === '0', 'staff without ai_settings cannot switch it on');
    } else {
        skip('no non-manager staff user on this install');
    }
    if ($mgr) {
        $r = run_page('admin/ai-settings.php', ['method' => 'GET', 'as_username' => $mgr['username'], 'grep' => ['id="ai-master"']]);
        check(!($r['found']['id="ai-master"'] ?? true), 'a manager (no ai_settings by default) cannot open the page');
    } else {
        skip('no manager user on this install');
    }

    // Switching on is refused until a provider is ready
    $r = run_page('admin/ai-settings.php', ['post' => ['action' => 'master', 'state' => 'on'],
        'verify' => ["SELECT setting_value FROM site_settings WHERE setting_key = 'ai_enabled'", "SELECT is_enabled FROM enabled_modules WHERE module_key = 'ai_assistant'"]]);
    check(($r['verify'][0][0]['setting_value'] ?? '') === '0' && (int)($r['verify'][1][0]['is_enabled'] ?? 1) === 0, 'switch on is refused with no provider ready');
    check($clean($r), 'refused switch-on rolled back');
    // ... and works once one is
    $ready = array_merge($seed, [
        "UPDATE ai_models SET is_selected = 1, role = 'primary' WHERE provider = 'anthropic' AND model_id = 'claude-opus-5-5'",
        "INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('ai_provider', 'anthropic', 'ai') ON DUPLICATE KEY UPDATE setting_value = 'anthropic'",
    ]);
    $r = run_page('admin/ai-settings.php', ['post' => ['action' => 'master', 'state' => 'on'], 'setup' => $ready,
        'verify' => ["SELECT setting_value FROM site_settings WHERE setting_key = 'ai_enabled'", "SELECT is_enabled FROM enabled_modules WHERE module_key = 'ai_assistant'", "SELECT COUNT(*) AS n FROM admin_activity_log WHERE action = 'ai_settings' AND details = 'Assistant switched on' AND created_at >= NOW() - INTERVAL 1 MINUTE"]]);
    check(($r['verify'][0][0]['setting_value'] ?? '') === '1' && (int)($r['verify'][1][0]['is_enabled'] ?? 0) === 1, 'switch on sets the setting and the module row', json_encode($r['verify'] ?? $r));
    check((int)($r['verify'][2][0]['n'] ?? 0) >= 1, 'switching on is written to the activity log');
    check($clean($r), 'switch-on run rolled back');
    check((string)$pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'ai_enabled'")->fetchColumn() === '0', 'live setting is still off after the page runs');

    // Limits: valid saves, invalid is refused
    $r = run_page('admin/ai-settings.php', ['post' => ['action' => 'save_limits', 'ai_user_daily_token_cap' => '150000', 'ai_monthly_budget_usd' => '35.5', 'ai_rate_limit_per_10min' => '20', 'ai_retention_days' => '90'],
        'verify' => ["SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('ai_user_daily_token_cap','ai_monthly_budget_usd','ai_rate_limit_per_10min','ai_retention_days') ORDER BY setting_key"]]);
    $v = array_column($r['verify'][0] ?? [], 'setting_value', 'setting_key');
    check(($v['ai_user_daily_token_cap'] ?? '') === '150000' && ($v['ai_monthly_budget_usd'] ?? '') === '35.5' && ($v['ai_rate_limit_per_10min'] ?? '') === '20' && ($v['ai_retention_days'] ?? '') === '90', 'limits save', json_encode($v));
    $r = run_page('admin/ai-settings.php', ['post' => ['action' => 'save_limits', 'ai_user_daily_token_cap' => '5', 'ai_monthly_budget_usd' => '35', 'ai_rate_limit_per_10min' => '20', 'ai_retention_days' => '0'],
        'verify' => ["SELECT setting_value FROM site_settings WHERE setting_key = 'ai_user_daily_token_cap'"]]);
    check(($r['verify'][0][0]['setting_value'] ?? '') !== '5', 'an out-of-range limit is refused');
    // Bad CSRF
    $r = run_page('admin/ai-settings.php', ['post' => ['action' => 'master', 'state' => 'off', 'csrf_token' => 'wrong'], 'setup' => array_merge($ready, ["UPDATE site_settings SET setting_value = '1' WHERE setting_key = 'ai_enabled'", "UPDATE enabled_modules SET is_enabled = 1 WHERE module_key = 'ai_assistant'"]),
        'verify' => ["SELECT setting_value FROM site_settings WHERE setting_key = 'ai_enabled'"]]);
    check(($r['verify'][0][0]['setting_value'] ?? '') === '1', 'a POST with a wrong security token changes nothing');
    // Remove key through the page
    $r = run_page('admin/ai-settings.php', ['post' => ['action' => 'remove_key', 'provider' => 'anthropic'], 'setup' => $ready,
        'verify' => ["SELECT (api_key_enc IS NULL) AS gone FROM ai_providers WHERE provider = 'anthropic'"]]);
    check((int)($r['verify'][0][0]['gone'] ?? 0) === 1, 'Remove key clears the stored key');
    check($clean($r), 'remove-key run rolled back');
}
@unlink($tmpHtml);
rh_ai_set_transport(null);
if ($origEnv === null) { unset($_ENV['APP_ENCRYPTION_SALT']); } else { $_ENV['APP_ENCRYPTION_SALT'] = $origEnv; }

echo "$pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail ? 1 : 0);
