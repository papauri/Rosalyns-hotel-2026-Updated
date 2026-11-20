<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Assistant Settings > Models: the choices after "Check for newer models" (owner fault 2026-11-20: "able to pick up a
 * new model and tell me about it but won't populate the new top models to pick from and replace the rest").
 *
 * Usage (from the repo root): php scripts/tests/ai_model_choices_test.php
 * No network and no AI spend: the provider's model list is a stubbed response (rh_ai_set_transport). Library checks run
 * inside one transaction that is rolled back; the page check runs through scripts/tests/page_harness.php (rolled back).
 * Test hotel only (Liwonde).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
if (!function_exists('rh_log_event')) { // never write the hotel's system log from a test
    function rh_log_event(string $source, string $level, string $message, array $context = []): void { $GLOBALS['__t_log'][] = $message; }
}

require $root . '/config/database.php';
require_once $root . '/includes/ai/ai-catalog.php';
require_once $root . '/includes/ai/ai-selfheal.php';

if (!rh_ai_schema_ready($pdo)) {
    echo "SKIP the Assistant tables are not set up here\n0 failed\n";
    exit(0);
}

$FAKE_KEY = 'AIzaFAKEKEY0123456789abcdefghijklmnopWXYZ';
$GLOBALS['stub_list'] = [];
$GLOBALS['stub_calls'] = 0;
rh_ai_set_transport(static function ($method, $url, $headers, $body, $timeout) {
    if ($method === 'GET' && preg_match('#/models(\?|$)#', $url)) {
        $GLOBALS['stub_calls']++;
        $models = array_map(static fn($m) => ['name' => 'models/' . $m[0], 'displayName' => $m[1], 'supportedGenerationMethods' => ['generateContent'], 'inputTokenLimit' => 1048576], $GLOBALS['stub_list']);
        return ['http' => 200, 'body' => json_encode(['models' => $models]), 'error' => ''];
    }
    return ['http' => 500, 'body' => '{}', 'error' => 'no chat call in this test'];
});
$ids = static fn(array $rows): array => array_map(static fn($m) => (string)$m['model_id'], $rows);
$sel = static function (PDO $pdo): array {
    return $pdo->query("SELECT model_id, role FROM ai_models WHERE provider = 'google' AND is_selected = 1 ORDER BY model_id")->fetchAll(PDO::FETCH_KEY_PAIR);
};

$adminId = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$base = [
    ['gemini-3.6-flash', 'Gemini 3.6 Flash'],
    ['gemini-3.5-flash-lite', 'Gemini 3.5 Flash-Lite'],
    ['gemini-3.1-pro-preview', 'Gemini 3.1 Pro Preview'],
    ['gemini-2.5-flash', 'Gemini 2.5 Flash'],
    ['deep-research-preview-04-2026', 'Deep Research Preview'],
];

$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE ai_providers SET api_key_enc = NULL, status = 'none' WHERE provider <> 'google'");
    $pdo->exec("UPDATE ai_models SET is_selected = 0, role = NULL");
    $pdo->exec("DELETE FROM ai_models WHERE provider = 'google'");
    rh_ai_save_settings(['ai_cloud_enabled' => '1', 'ai_notices_json' => '', 'ai_models_listed_at_google' => ''], $pdo);
    rh_ai_store_key('google', $FAKE_KEY, $adminId, $pdo);

    // 1. First list, nothing chosen yet: the suggestion only, older versions left out
    $GLOBALS['stub_list'] = $base;
    $r1 = rh_ai_refresh_provider($pdo, 'google');
    $c1 = rh_ai_model_choices($pdo, 'google');
    check($r1['ok'] && $GLOBALS['stub_calls'] === 1, 'the list check uses the stubbed response (one call, no network)', json_encode($r1));
    check($ids($c1['rows']) === ['gemini-3.6-flash', 'gemini-3.5-flash-lite'] && $c1['suggest'] === ['gemini-3.6-flash' => 'primary', 'gemini-3.5-flash-lite' => 'fast'],
        'first list: the choices are the suggested top models; the older Gemini 2.5 Flash, the big Pro and a research model are left out', json_encode([$ids($c1['rows']), $c1['suggest']]));
    rh_ai_save_selection($pdo, 'google', ['gemini-3.6-flash' => 'primary', 'gemini-3.5-flash-lite' => 'fast']);

    // 2. A newer model arrives: it becomes a choice, the older one in use stays chosen and is marked
    $GLOBALS['stub_list'] = array_merge($base, [
        ['gemini-3.8-flash', 'Gemini 3.8 Flash'],     // in the built-in list, priced: the new top model
        ['gemini-3.9-flash', 'Gemini 3.9 Flash'],     // newer than anything known, no price yet
        ['gemini-3.7-flash', 'Gemini 3.7 Flash'],     // unknown, older than 3.8: never a choice
    ]);
    $r2 = rh_ai_refresh_provider($pdo, 'google');
    $c2 = rh_ai_model_choices($pdo, 'google');
    $shown2 = $ids($c2['rows']);
    check(in_array('gemini-3.8-flash', $shown2, true) && ($c2['status']['gemini-3.8-flash'] ?? '') === 'top' && ($c2['suggest']['gemini-3.8-flash'] ?? '') === 'primary',
        'a newer priced model populates the choices as the suggested Primary', json_encode([$shown2, $c2['suggest']]));
    check(($c2['status']['gemini-3.9-flash'] ?? '') === 'new_price' && in_array('gemini-3.9-flash', $shown2, true),
        'a newer version with no price is offered so its price can be typed (it never pushes a priced model out)', json_encode($c2['status']));
    check(!array_intersect(['gemini-2.5-flash', 'gemini-3.7-flash', 'deep-research-preview-04-2026', 'gemini-3.1-pro-preview'], $shown2),
        'older, superseded, big and research models are removed from the choices', json_encode($shown2));
    check(($c2['status']['gemini-3.6-flash'] ?? '') === 'older' && $c2['older'] === ['gemini-3.6-flash'],
        'the model in use that is no longer a top choice is marked older, still in use', json_encode($c2['status']));
    check($sel($pdo) === ['gemini-3.5-flash-lite' => 'fast', 'gemini-3.6-flash' => 'primary'], 'nothing was switched: the chosen models and roles are unchanged', json_encode($sel($pdo)));
    $msg2 = (string)$r2['message'];
    $agree = true;
    foreach ($c2['rows'] as $m) {
        if (strpos($msg2, (string)$m['label']) === false) { $agree = false; }
    }
    foreach (['Gemini 2.5 Flash', 'Gemini 3.7 Flash', 'Deep Research', 'Pro Preview'] as $hid) {
        if (strpos($msg2, $hid) !== false) { $agree = false; }
    }
    check($agree && strpos($msg2, 'Gemini 3.8 Flash (new)') !== false && strpos($msg2, 'Older, still in use') !== false && strpos($msg2, 'Gemini 3.6 Flash (Primary)') !== false,
        'the notice names exactly the models the table offers', $msg2);
    $notes = json_encode(rh_ai_notices());
    check(strpos($notes, 'gemini-3.9-flash') !== false && strpos($notes, 'gemini-3.7-flash') === false && strpos($notes, 'deep-research') === false,
        'the "set a price" notice names only the new model the table offers', $notes);
    $c2b = rh_ai_model_choices($pdo, 'google');
    check($ids($c2b['rows']) === $shown2 && $c2b['status'] === $c2['status'], 'the choices survive a reload (worked out from the saved list)');

    // 3. The provider drops a model in use: a warning, nothing picked for it
    $pdo->exec("UPDATE ai_models SET last_seen_at = DATE_SUB(last_seen_at, INTERVAL 1 MINUTE) WHERE provider = 'google'"); // the earlier check, a minute ago
    $GLOBALS['stub_list'] = array_values(array_filter($GLOBALS['stub_list'], static fn($m) => $m[0] !== 'gemini-3.5-flash-lite'));
    $r3 = rh_ai_refresh_provider($pdo, 'google');
    $c3 = rh_ai_model_choices($pdo, 'google');
    check($r3['ok'] && $r3['retired'] === ['gemini-3.5-flash-lite'] && ($c3['status']['gemini-3.5-flash-lite'] ?? '') === 'retired' && strpos((string)$r3['message'], 'choose a replacement') !== false,
        'a model in use that the provider dropped is flagged with a warning to choose a replacement', json_encode([$r3['retired'] ?? null, $r3['message']]));
    check($sel($pdo) === ['gemini-3.5-flash-lite' => 'fast', 'gemini-3.6-flash' => 'primary'], 'a retired model is never replaced automatically', json_encode($sel($pdo)));
    check(strpos(json_encode(rh_ai_notices()), 'model_retired') !== false, 'the Assistant Settings people get a notice about the retired model');
    $refused = '';
    try { rh_ai_save_selection($pdo, 'google', ['gemini-3.8-flash' => 'fast']); } catch (Throwable $e) { $refused = $e->getMessage(); }
    check(stripos($refused, 'Primary') !== false && $sel($pdo)['gemini-3.6-flash'] === 'primary', 'saving with no Primary (e.g. a retired Primary left unticked) is refused, never saved empty', $refused);
    // A model the provider closed (404) is retired too, even though the plain model list hides it
    rh_ai_model_mark_gone($pdo, 'google', 'gemini-3.6-flash');
    $c4 = rh_ai_model_choices($pdo, 'google');
    check(($c4['status']['gemini-3.6-flash'] ?? '') === 'retired' && in_array('gemini-3.6-flash', $ids($c4['rows']), true), 'a closed model in use shows as retired (still listed so the warning has a row)', json_encode($c4['status']));

    // 4. A list saved before this fix (no saved list time): no false "no longer offered" warning
    rh_ai_save_settings(['ai_models_listed_at_google' => ''], $pdo);
    $pdo->exec("UPDATE ai_models SET last_seen_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE provider = 'google' AND model_id = 'gemini-3.5-flash-lite'");
    $c5 = rh_ai_model_choices($pdo, 'google');
    check(($c5['status']['gemini-3.5-flash-lite'] ?? '') !== 'retired', 'with no saved list time only a closed model counts as retired', json_encode($c5['status']));
} finally {
    $pdo->rollBack();
    rh_ai_set_transport(null);
    rh_ai_settings($pdo, true);
}
check(!$pdo->inTransaction() && (int)$pdo->query("SELECT COUNT(*) FROM ai_providers WHERE provider = 'google' AND key_last4 = 'WXYZ'")->fetchColumn() === 0, 'library checks rolled back');

// The sync is all or nothing (a lock wait part-way must not leave only the first rows marked as listed)
$src = (string)file_get_contents($root . '/includes/ai/ai-catalog.php');
$fn = substr($src, (int)strpos($src, 'function rh_ai_sync_models('), 3000);
check(strpos($fn, '$db->beginTransaction()') !== false && strpos($fn, '$db->rollBack()') !== false && strpos($fn, "'ai_models_listed_at_' . \$provider") !== false,
    'the model list is saved in one transaction with its list time');

// ── The page (rolled-back harness): older kept and marked, retired warned, superseded hidden ──
$h = $root . '/scripts/tests/page_harness.php';
$tmpHtml = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ai_choices_test_' . bin2hex(random_bytes(4)) . '.html';
$cfg = ['method' => 'GET', 'dump_html' => $tmpHtml, 'setup' => [
    "UPDATE ai_models SET is_selected = 0, role = NULL",
    "DELETE FROM ai_models WHERE provider = 'google'",
    "INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('ai_models_listed_at_google', DATE_SUB(NOW(), INTERVAL 1 MINUTE), 'ai') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
    "INSERT INTO ai_models (provider, model_id, label, capability_rank, price_in_per_mtok, price_out_per_mtok, source, is_new, is_selected, role, last_seen_at) VALUES
        ('google', 'gemini-3.8-flash', 'Gemini 3.8 Flash', 92, 0.75, 3.75, 'catalog', 0, 0, NULL, NOW()),
        ('google', 'gemini-3.6-flash', 'Gemini 3.6 Flash', 90, 0.75, 3.75, 'catalog', 0, 1, 'primary', NOW()),
        ('google', 'gemini-3.5-flash-lite', 'Gemini 3.5 Flash-Lite', 86, 0.3, 2.5, 'catalog', 0, 1, 'fast', DATE_SUB(NOW(), INTERVAL 1 DAY)),
        ('google', 'gemini-3.1-flash-lite', 'Gemini 3.1 Flash-Lite', 84, 0.25, 1.5, 'catalog', 0, 0, NULL, NOW()),
        ('google', 'gemini-2.5-flash', 'Gemini 2.5 Flash', 89, NULL, NULL, 'live', 1, 0, NULL, NOW())",
]];
$out = shell_exec('php ' . escapeshellarg($h) . ' ' . escapeshellarg('admin/ai-settings.php') . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
$res = preg_match('/@@RESULT@@(.*)$/m', (string)$out, $mm) ? (array)json_decode($mm[1], true) : [];
$html = (string)@file_get_contents($tmpHtml);
@unlink($tmpHtml);
$s = strpos($html, 'id="ai-models-google"');
$form = $s === false ? '' : substr($html, $s, (int)strpos($html, '</form>', $s) - $s);
check($form !== '' && strpos($form, 'Gemini 3.6 Flash</strong> <span class="ai-muted">(older, still in use)</span>') !== false && preg_match('/value="gemini-3\.6-flash" data-ai-pick checked/', $form) === 1,
    'page: the model in use stays ticked and is shown as (older, still in use)', substr($form, 0, 300));
check(strpos($form, 'value="gemini-3.8-flash" data-ai-pick aria-label') !== false && strpos($form, 'Suggested: Primary') !== false,
    'page: the new top model is offered (unticked, marked Suggested: Primary)');
check(strpos($form, 'data-ai-retired') !== false && strpos($form, 'value="gemini-3.5-flash-lite"') === false && strpos($form, 'No longer offered - choose a replacement') !== false,
    'page: a retired model in use shows a warning and is never submitted');
check(strpos($form, 'gemini-2.5-flash') === false && strpos($form, 'value="gemini-3.1-flash-lite" data-ai-pick aria-label') !== false && strpos($form, 'Suggested: Fast') !== false,
    'page: a superseded model is not offered; the best other Fast model is suggested as the replacement');
check(!empty($res['rolled_back']) && empty($res['leak']), 'page run rolled back', json_encode($res['error'] ?? ''));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
