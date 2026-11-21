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
$GLOBALS['stub_page'] = null; // Google's price page: null = cannot be read
$GLOBALS['stub_page_calls'] = 0;
rh_ai_set_transport(static function ($method, $url, $headers, $body, $timeout) {
    if ($method === 'GET' && strpos($url, 'ai.google.dev/gemini-api/docs/pricing') !== false) {
        $GLOBALS['stub_page_calls']++;
        return $GLOBALS['stub_page'] !== null ? ['http' => 200, 'body' => $GLOBALS['stub_page'], 'error' => ''] : ['http' => 503, 'body' => '', 'error' => 'unavailable'];
    }
    if ($method === 'GET' && strpos($url, 'model_prices_and_context_window.json') !== false) { // the public price list (2026-11-21)
        $GLOBALS['stub_pricelist_calls'] = ($GLOBALS['stub_pricelist_calls'] ?? 0) + 1;
        $GLOBALS['stub_pricelist_headers'] = $headers;
        $pl = $GLOBALS['stub_pricelist'] ?? null;
        return $pl !== null ? ['http' => 200, 'body' => $pl, 'error' => ''] : ['http' => 503, 'body' => '', 'error' => 'unavailable'];
    }
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
    check($ids($c1['rows']) === ['gemini-3.6-flash', 'gemini-3.5-flash-lite', 'gemini-3.1-pro-preview'] && $c1['suggest'] === ['gemini-3.6-flash' => 'primary', 'gemini-3.5-flash-lite' => 'fast'],
        'first list: best first; the big Pro is shown but never suggested; the older Gemini 2.5 Flash and a research model are left out', json_encode([$ids($c1['rows']), $c1['suggest']]));
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
    check(!array_intersect(['gemini-2.5-flash', 'gemini-3.7-flash', 'deep-research-preview-04-2026'], $shown2) && in_array('gemini-3.1-pro-preview', $shown2, true),
        'older, superseded and research models are removed from the choices; a big model stays on show', json_encode($shown2));
    check(($c2['status']['gemini-3.6-flash'] ?? '') === 'older' && $c2['older'] === ['gemini-3.6-flash'],
        'the model in use that is no longer a top choice is marked older, still in use', json_encode($c2['status']));
    check($sel($pdo) === ['gemini-3.5-flash-lite' => 'fast', 'gemini-3.6-flash' => 'primary'], 'nothing was switched: the chosen models and roles are unchanged', json_encode($sel($pdo)));
    $msg2 = (string)$r2['message'];
    $agree = true;
    foreach ($c2['rows'] as $m) {
        if (strpos($msg2, (string)$m['label']) === false) { $agree = false; }
    }
    foreach (['Gemini 2.5 Flash', 'Gemini 3.7 Flash', 'Deep Research'] as $hid) {
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

    // 5. Google's price page (owner 2026-11-20 "should be able to scrape automatically"): read with the list check
    $page = (string)file_get_contents($root . '/scripts/tests/fixtures/ai/google_pricing.html');
    $parsed = rh_ai_price_page_parse($page);
    $p1120 = rh_ai_price_page_prices(null, 'google', '2026-11-20');
    check(count($parsed) === 5 && !isset($parsed['imagen-5.0-generate']), 'price page: one entry per chat model id with an input and output price (an image price is skipped)', json_encode(array_keys($parsed)));
    $rd = static function (array $p, string $id, string $day): ?array {
        $in = rh_ai_price_page_resolve($p[$id]['in'] ?? [], $day);
        $out = rh_ai_price_page_resolve($p[$id]['out'] ?? [], $day);
        return $in === null || $out === null ? null : [$in, $out];
    };
    check($rd($parsed, 'gemini-4-argon', '2026-11-20') === [2.0, 10.0] && $rd($parsed, 'gemini-4-argon', '2026-11-30') === [2.0, 10.0] && $rd($parsed, 'gemini-4-argon', '2026-12-01') === [4.0, 20.0],
        'price page: a dated price is worked out on the day (Argon 2/10 to 30 Nov, 4/20 from 1 Dec; the Batch table is ignored)', json_encode($parsed['gemini-4-argon'] ?? null));
    check($rd($parsed, 'gemini-3.8-flash', '2026-11-20') === [0.75, 3.75] && $rd($parsed, 'gemini-3.8-flash', '2027-01-02') === [1.5, 7.5],
        'price page: the text price, not the audio price; "until 31 Dec 2026, then" moves on 1 Jan', json_encode($parsed['gemini-3.8-flash'] ?? null));
    check($rd($parsed, 'gemini-3.1-pro-preview', '2026-11-20') === [2.0, 12.0], 'price page: the lowest prompt tier (up to 200k tokens), the paid column not the free one');
    check(!rh_ai_model_is_big(['model_id' => 'gemini-4-argon', 'price_out' => $rd($parsed, 'gemini-4-argon', '2026-11-20')[1]])
        && rh_ai_model_is_big(['model_id' => 'gemini-4-argon', 'price_out' => $rd($parsed, 'gemini-4-argon', '2026-12-01')[1]]),
        'Argon is not big at US$10 out; from 1 Dec (US$20 out) it is big by price and no longer suggested');

    // Owner 2026-11-21 ("no need for us to set AI prices, you do it automatically"): the other providers read the public price list.
    $listJson = json_encode([
        'sample_spec' => ['input_cost_per_token' => 0, 'output_cost_per_token' => 0],
        'claude-haiku-5-5' => ['litellm_provider' => 'anthropic', 'mode' => 'chat', 'input_cost_per_token' => 0.0000000999999, 'output_cost_per_token' => 0.0000005],
        'claude-opus-5-5' => ['litellm_provider' => 'anthropic', 'mode' => 'chat', 'input_cost_per_token' => 0.000004, 'output_cost_per_token' => 0.00002],
        'anthropic/claude-opus-5-5' => ['litellm_provider' => 'bedrock', 'mode' => 'chat', 'input_cost_per_token' => 0.000009, 'output_cost_per_token' => 0.00009],
        'claude-embed-1' => ['litellm_provider' => 'anthropic', 'mode' => 'embedding', 'input_cost_per_token' => 0.000001, 'output_cost_per_token' => 0],
        'claude-no-out' => ['litellm_provider' => 'anthropic', 'mode' => 'chat', 'input_cost_per_token' => 0.000001],
        'xai/grok-5' => ['litellm_provider' => 'xai', 'mode' => 'chat', 'input_cost_per_token' => 0.000003, 'output_cost_per_token' => 0.000015],
        'gpt-6-sol' => ['litellm_provider' => 'openai', 'mode' => 'responses', 'input_cost_per_token' => 0.000002, 'output_cost_per_token' => 0.00001],
        'gemini/gemini-4-neon' => ['litellm_provider' => 'gemini', 'mode' => 'chat', 'input_cost_per_token' => 0.000003, 'output_cost_per_token' => 0.000015],
        'gemini/gemma-4-31b-it' => ['litellm_provider' => 'gemini', 'mode' => 'chat', 'input_cost_per_token' => 0, 'output_cost_per_token' => 0],
        'gemini/gemini-3.9-flash' => ['litellm_provider' => 'gemini', 'mode' => 'chat', 'input_cost_per_token' => 0.000009, 'output_cost_per_token' => 0.000009],
    ]);
    $an = rh_ai_price_list_parse($listJson, 'anthropic');
    $an1 = rh_ai_price_page_prices(null, 'anthropic'); // no DB: empty, never throws
    check(array_keys($an) === ['claude-haiku-5-5', 'claude-opus-5-5'] && $an['claude-haiku-5-5']['in'][0][0] === 0.1 && $an['claude-haiku-5-5']['out'][0][0] === 0.5
        && $an['claude-opus-5-5']['in'][0][0] === 4.0 && $an['claude-opus-5-5']['out'][0][0] === 20.0 && $an1 === [],
        'price list: Anthropic chat models only (US$ per million, rounded; another host, an embedding and a model with no out price are skipped)', json_encode($an));
    check(array_keys(rh_ai_price_list_parse($listJson, 'xai')) === ['grok-5'] && array_keys(rh_ai_price_list_parse($listJson, 'openai')) === ['gpt-6-sol']
        && array_keys(rh_ai_price_list_parse($listJson, 'google')) === ['gemini-4-neon', 'gemini-3.9-flash'] && rh_ai_price_list_parse('not json', 'anthropic') === [],
        'price list: the "xai/" and "gemini/" prefixes are dropped; a Responses model counts; a US$0/US$0 free tier is not a price; a broken list reads as nothing');
    check(rh_ai_price_page_url('google') !== rh_ai_price_page_url('anthropic') && strpos(rh_ai_price_page_url('anthropic'), 'model_prices_and_context_window.json') !== false
        && rh_ai_price_page_url('nobody') === '', 'price sources: Google its page, every other provider the public list, an unknown provider none');

    $GLOBALS['stub_page'] = $page;
    $GLOBALS['stub_pricelist'] = $listJson;
    $GLOBALS['stub_list'] = array_merge($base, [['gemini-3.8-flash', 'Gemini 3.8 Flash'], ['gemini-3.9-flash', 'Gemini 3.9 Flash'], ['gemini-4-argon', 'Gemini 4 Argon'], ['gemini-flash-latest', 'Gemini Flash Latest'], ['gemini-4-neon', 'Gemini 4 Neon'], ['gemma-4-31b-it', 'Gemma 4 31B']]);
    $pdo->exec("UPDATE ai_models SET price_in_per_mtok = 0.31, price_out_per_mtok = 2.51, price_edited = 1 WHERE provider = 'google' AND model_id = 'gemini-3.5-flash-lite'");
    $calls = $GLOBALS['stub_page_calls'];
    $r6 = rh_ai_refresh_provider($pdo, 'google');
    $today = rh_ai_price_page_prices($pdo, 'google');
    $row = static fn(string $id): array => (array)$pdo->query("SELECT price_in_per_mtok, price_out_per_mtok, price_edited, is_new FROM ai_models WHERE provider = 'google' AND model_id = " . $pdo->quote($id))->fetch(PDO::FETCH_ASSOC);
    check(!empty($r6['prices_read']) && $GLOBALS['stub_page_calls'] === $calls + 1 && strpos((string)$r6['message'], "Prices read from Google's price page") !== false,
        'price page: read once with the list check (through the stubbed transport, no network)', (string)$r6['message']);
    $r39 = $row('gemini-3.9-flash');
    check(abs((float)$r39['price_in_per_mtok'] - $today['gemini-3.9-flash'][0]) < 0.0001 && abs((float)$r39['price_out_per_mtok'] - $today['gemini-3.9-flash'][1]) < 0.0001 && (int)$r39['is_new'] === 0,
        'price page: a new model with no built-in price gets the page price and stops waiting for one', json_encode($r39));
    $r35 = $row('gemini-3.5-flash-lite');
    // Owner 2026-11-21 ("no need for us to set AI prices, you do it automatically"): the automatic price replaces an old typed one.
    check($r35['price_in_per_mtok'] !== null && $r35['price_out_per_mtok'] !== null
        && !(abs((float)$r35['price_in_per_mtok'] - 0.31) < 0.0001 && abs((float)$r35['price_out_per_mtok'] - 2.51) < 0.0001) && (int)$r35['price_edited'] === 0,
        'prices are automatic: an old price typed by hand is replaced by the known price (page or built-in list)', json_encode($r35));
    $rNeon = $row('gemini-4-neon');
    $rGemma = $row('gemma-4-31b-it');
    $plHeaders = strtolower(implode("\n", (array)($GLOBALS['stub_pricelist_headers'] ?? [])));
    check(abs((float)$rNeon['price_in_per_mtok'] - 3) < 0.0001 && abs((float)$rNeon['price_out_per_mtok'] - 15) < 0.0001 && $rGemma['price_in_per_mtok'] === null
        && abs((float)$r39['price_in_per_mtok'] - 9) >= 0.0001 && strpos((string)$r6['message'], 'from the public model price list') !== false
        && strpos($plHeaders, 'key') === false && strpos($plHeaders, 'authorization') === false,
        'Google: the public list fills a model its page does not show (Gemini 4 Neon); the page price wins; a free Gemma stays unpriced; no key is sent to the list', json_encode([$rNeon, $rGemma, (string)$r6['message']]));
    $c6 = rh_ai_model_choices($pdo, 'google');
    $shown6 = $ids($c6['rows']);
    check(in_array('gemini-4-argon', $shown6, true) && !rh_ai_model_banned('gemini-4-argon') && !in_array('gemini-flash-latest', $shown6, true),
        'Gemini 4 is shown (the owner lifted the ban); a "-latest" alias of a family on offer is left out', json_encode($shown6));
    $allBadges = array_merge(...array_values($c6['badges'] ?: [[]]));
    sort($allBadges);
    check($allBadges === ['cheapest', 'fastest', 'newest'] && in_array('newest', $c6['badges']['gemini-4-argon'] ?? [], true),
        'badges: one Newest (Gemini 4 Argon), one Cheapest, one Fastest', json_encode($c6['badges']));

    // A later read fails: the last prices stay, one notice, nothing breaks
    $GLOBALS['stub_page'] = null;
    $savedBefore = rh_ai_price_page_saved($pdo, 'google');
    $r7 = rh_ai_refresh_provider($pdo, 'google');
    $r7b = rh_ai_refresh_provider($pdo, 'google');
    $r39b = $row('gemini-3.9-flash');
    $noticeText = json_encode(rh_ai_notices());
    check($r7['ok'] && empty($r7['prices_read']) && strpos((string)$r7['message'], 'last known prices were kept') !== false && rh_ai_price_page_saved($pdo, 'google') === $savedBefore
        && (float)$r39b['price_in_per_mtok'] === (float)$r39['price_in_per_mtok'], 'price page unreadable: the list check still works and the last prices are kept', (string)$r7['message']);
    check(substr_count($noticeText, 'could not be read today, so the last known prices were kept (last read') === 1, 'price page unreadable twice: one notice, not two', $noticeText);

    // At most 10 choices, priced ones first
    $ins = $pdo->prepare("INSERT INTO ai_models (provider, model_id, label, capability_rank, price_in_per_mtok, price_out_per_mtok, source, is_new, is_selected, role, last_seen_at) VALUES ('google', ?, ?, ?, ?, ?, 'live', 0, 0, NULL, NOW())");
    foreach (range('a', 'l') as $k => $l) {
        $ins->execute(['mdl-' . $l . '-2.0', 'Test model ' . strtoupper($l), 60 + $k, 0.1 + $k / 10, 1 + $k / 2]);
    }
    $pdo->prepare("INSERT INTO ai_models (provider, model_id, label, capability_rank, source, is_new, is_selected, role, last_seen_at) VALUES ('google', 'mdl-z-9.0', 'Test model Z', 99, 'live', 1, 0, NULL, NOW())")->execute();
    $c8 = rh_ai_model_choices($pdo, 'google');
    $choiceIds = array_keys(array_filter($c8['status'], static fn($s) => $s === 'top' || $s === 'new_price'));
    check(count($choiceIds) === RH_AI_MODEL_CHOICES_MAX && !in_array('mdl-z-9.0', $choiceIds, true) && !array_diff(array_keys($c8['suggest']), $choiceIds),
        'at most 10 choices; a model with no price never pushes a priced one out; the suggestion is always among them', json_encode($choiceIds));
    $inUse = array_keys($sel($pdo));
    check(!array_diff($inUse, $ids($c8['rows'])), 'the models in use are always shown, even outside the top 10', json_encode($inUse));

    // Fit limits to the cap (owner 2026-11-21: "auto set to the best capacity to fit budget")
    $pdo->exec("UPDATE ai_models SET is_selected = 0, role = NULL");
    $pdo->exec("UPDATE ai_models SET is_selected = 1, role = 'primary', price_in_per_mtok = 0.75, price_out_per_mtok = 3.75 WHERE provider = 'google' AND model_id = 'gemini-3.8-flash'");
    rh_ai_save_settings(['ai_provider' => 'google', 'ai_turn_token_cap' => '60000'], $pdo);
    $hist = $pdo->query("SELECT COALESCE(SUM(tokens_in), 0) tin, COALESCE(SUM(tokens_out), 0) tout FROM ai_usage_daily WHERE day >= CURDATE() - INTERVAL 30 DAY")->fetch(PDO::FETCH_ASSOC);
    $share = ((float)$hist['tin'] + (float)$hist['tout']) >= 20000 ? min(0.5, max(0.02, (float)$hist['tout'] / ((float)$hist['tin'] + (float)$hist['tout']))) : 0.1;
    $perTok = (0.75 * (1 - $share) + 3.75 * $share) / 1000000;
    $f5 = rh_ai_fit_limits($pdo, 5.0, 1);
    // A person can go past the daily limit by one whole question: each share pays for daily + turn, 10 % kept back (review 2026-11-21)
    $allow = 5.0 * 0.9 / $perTok / 31 / 2;
    $expTurn = (int)max(5000, min(60000, floor($allow / 2 / 1000) * 1000));
    $expDaily = (int)max(1000, floor(($allow - $expTurn) / 1000) * 1000);
    check($f5['ok'] && $f5['slots'] === 2 && $f5['turn'] === $expTurn && $f5['daily'] === $expDaily && $f5['month_max'] <= 5.0
        && ($f5['daily'] + $f5['turn']) * 2 * 31 * $perTok <= 5.0 * 0.9 + 0.0001 && ($f5['daily'] + $f5['turn'] + 1000) * 2 * 31 * $perTok > 5.0 * 0.9 && $f5['model'] !== '',
        'fit: 1 person + nightly checks at US$5 on 0.75/3.75: daily limit plus one whole question past it, used every day for 31 days, stays within 90 % of the cap', json_encode($f5));
    $f50 = rh_ai_fit_limits($pdo, 50.0, 4);
    check($f50['ok'] && $f50['slots'] === 5 && $f50['daily'] > $f5['daily'] && $f50['month_max'] <= 50.0 && $f50['turn'] <= 60000, 'fit: a bigger cap and more people still stay within the cap', json_encode($f50));
    $fTiny = rh_ai_fit_limits($pdo, 0.0, 1);
    check(!$fTiny['ok'] && $fTiny['reason'] !== '', 'fit: no cap, no fit (a plain reason)', json_encode($fTiny));
    $pdo->exec("UPDATE ai_models SET price_in_per_mtok = NULL, price_out_per_mtok = NULL WHERE provider = 'google' AND model_id = 'gemini-3.8-flash'");
    $fNo = rh_ai_fit_limits($pdo, 5.0, 1);
    check(!$fNo['ok'] && strpos($fNo['reason'], 'no price yet') !== false, 'fit: a Primary with no price yet gives a plain reason, never a guess', $fNo['reason']);
    $pdo->exec("UPDATE ai_models SET is_selected = 0, role = NULL");
    $fNone = rh_ai_fit_limits($pdo, 5.0, 1);
    check(!$fNone['ok'] && strpos($fNone['reason'], 'Primary') !== false, 'fit: no Primary chosen gives a plain reason', $fNone['reason']);
} finally {
    $pdo->rollBack();
    rh_ai_set_transport(null);
    rh_ai_settings($pdo, true);
}
check(!$pdo->inTransaction() && (int)$pdo->query("SELECT COUNT(*) FROM ai_providers WHERE provider = 'google' AND key_last4 = 'WXYZ'")->fetchColumn() === 0, 'library checks rolled back');

// Unticking and saving (owner fault 2026-11-20 "failing to untick models and save"): the ticks are tidied, never refused
$rk = ['a' => 92, 'b' => 86, 'c' => 84, 'd' => 80];
$n1 = rh_ai_normalise_picks(['b', 'c'], ['b' => 'fast', 'c' => ''], $rk);
check($n1['roles'] === ['b' => 'primary', 'c' => 'extra'] && count($n1['notes']) === 2, 'the Primary unticked and a ticked row with no role: the best ticked becomes Primary, the other Extra', json_encode($n1));
$n2 = rh_ai_normalise_picks(['a', 'b', 'c'], ['a' => 'primary', 'b' => 'fast', 'c' => 'fast'], $rk);
check($n2['roles'] === ['a' => 'primary', 'b' => 'fast', 'c' => 'extra'], 'a second Fast becomes Extra', json_encode($n2));
$n3 = rh_ai_normalise_picks(['c', 'a'], ['c' => 'primary', 'a' => 'primary'], $rk);
check($n3['roles'] === ['c' => 'extra', 'a' => 'primary'], 'two Primary: the best keeps it, the other is Extra', json_encode($n3));
$n4 = rh_ai_normalise_picks(['a', 'b'], ['a' => 'primary', 'b' => 'fallback'], $rk);
check($n4['roles'] === ['a' => 'primary', 'b' => 'fallback'] && $n4['notes'] === [], 'a valid choice is saved as it is, with no notes');
check(rh_ai_normalise_picks([], [], $rk)['roles'] === [], 'nothing ticked stays nothing (never invented)');
check(rh_ai_model_speed_tier('x', 1779.0) === 3 && rh_ai_model_speed_tier('x', 15075.0) === 1 && rh_ai_model_speed_tier('gemini-3.5-flash-lite', null) === 3
    && rh_ai_model_speed_tier('gemini-3.8-flash', null) === 2 && rh_ai_model_speed_tier('gemini-4-argon', null) === 1, 'fastest: the measured reply time when known, else the kind of model');

// The sync is all or nothing (a lock wait part-way must not leave only the first rows marked as listed)
$src = (string)file_get_contents($root . '/includes/ai/ai-catalog.php');
$fn = substr($src, (int)strpos($src, 'function rh_ai_sync_models('), 4500);
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
