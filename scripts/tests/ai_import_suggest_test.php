<?php
/**
 * Import measure wording suggestions (includes/ai/ai-import-suggest.php, rh_ai_import_measure_suggest).
 * Usage: php scripts/tests/ai_import_suggest_test.php   (TEST PLATFORM ONLY: prints SKIP and exits 0 unless site_is_test_platform = 1)
 *
 * No network and no AI spend: every model answer comes from an injected transport, so the NO_AI_SPEND shell lock is never
 * touched. The DB part runs in ONE transaction that is rolled back (settings, learned readings, usage and breaker rows vanish).
 * The transport never prints what it was sent (the saved key travels with the request).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../admin/includes/permissions.php';
require_once __DIR__ . '/../../includes/ai/ai-import-suggest.php';

$pass = 0;
$fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
/** field => value for one key, from a suggestion list */
function got(array $out, string $key): array
{
    $r = [];
    foreach ($out as $s) {
        if ($s['key'] === $key) {
            $r[$s['field']] = $s['value'];
        }
    }
    return $r;
}
function src(array $out, string $key, string $field): string
{
    foreach ($out as $s) {
        if ($s['key'] === $key && $s['field'] === $field) {
            return (string)$s['source'];
        }
    }
    return '';
}

if ((string)getSetting('site_is_test_platform') !== '1') {
    echo "SKIP: this test runs only on the test platform (Liwonde).\n";
    exit(0);
}

// ---- pure: the plain word list ----
$L = static function (string $name, string $unit, string $served): array {
    $r = [];
    foreach (rh_ai_ims_local(['key' => 'k', 'name' => $name, 'unit' => $unit, 'served' => $served]) as $e) {
        $r[$e['field']] = $e['value'];
    }
    return $r;
};
check($L('Gin', 'bottle', 'Single') === ['served_by' => 'shot'] && $L('Gin', 'bottle', 'Measure') === ['served_by' => 'shot'] && $L('Gin', 'bottle', 'Tot') === ['served_by' => 'shot'],
    'ims_local_shot_words: Single / Measure / Tot = shot');
check($L('Gin', 'bottle', 'Dbl') === ['served_by' => 'double'] && $L('Wine', 'bottle', 'Gls') === ['served_by' => 'glass'] && $L('Juice', 'each', 'Ctn') === ['served_by' => 'carton'],
    'ims_local_abbreviations: Dbl / Gls / Ctn');
check($L('House red', 'bottle', 'Glass 175') === ['served_by' => 'glass', 'portion_ml' => 175], 'ims_local_glass_175: a bare number after a glass word is its ml');
check($L('Gin', 'bottle', 'Tot 25ml') === ['served_by' => 'shot', 'portion_ml' => 25], 'ims_local_tot_25ml');
check($L('Jameson 70cl', 'bottle', '') === ['container_ml' => 700] && $L('Juice 1lt', 'each', '') === ['container_ml' => 1000] && $L('Cordial', '1.5 ltr', '') === ['container_ml' => 1500],
    'ims_local_container: 70cl / 1lt / unit 1.5 ltr');
check($L('Wine', 'bottle', 'Bag in box') === [] && $L('', '', '') === [] && $L('Gin', 'bottle', 'Glass 6') === ['served_by' => 'glass'],
    'ims_local_never_invents: unknown words, blank text and an out-of-range bare number give nothing');
check($L('Gin 750ml', '1L', 'Single') === ['served_by' => 'shot'] && $L('Gin 750ml 1L', 'bottle', '') === [] && $L('Wine 175ml glass', 'bottle', '') === [],
    'ims_local_conflicts: two sizes = no container size; a size next to a portion word is not the container');
$s = rh_ai_ims_local(['key' => 'k', 'name' => 'Jameson 70CL', 'unit' => 'bottle', 'served' => 'SGL']);
check($s[0]['source'] === 'SGL' && $s[1]['source'] === '70CL', 'ims_source_exact: the source is the text as written (case kept)');

// ---- pure: checking a model reading against the row ----
$row = ['key' => 'k', 'name' => 'Red wine 5L', 'unit' => 'box', 'served' => 'Bag in box 150ml'];
check(rh_ai_ims_validate($row, 'portion_ml', 150, '150ml') === ['field' => 'portion_ml', 'value' => 150, 'source' => '150ml'], 'ims_validate: a figure written in the served text is kept');
check(rh_ai_ims_validate($row, 'portion_ml', 175, '150ml') === null && rh_ai_ims_validate($row, 'container_ml', 750, 'bottle') === null
    && rh_ai_ims_validate($row, 'served_by', 'glass', 'glass') === null && rh_ai_ims_validate($row, 'served_by', 'tumbler', 'Bag in box') === null
    && rh_ai_ims_validate($row, 'container_ml', 5000, '5L') !== null && rh_ai_ims_validate($row, 'notes', 'x', 'box') === null
    && rh_ai_ims_validate(['name' => 'Rum', 'unit' => 'bottle', 'served' => 'Half tot'], 'served_by', 'shot', 'tot') === null,
    'ims_validate_never_invents: a figure not written, a source not in the row, a word off the list, an unknown field or one word picked out of "Half tot" = nothing');
check(rh_ai_ims_unsafe(['name' => 'Gin a@b.co', 'unit' => '', 'served' => '']) && rh_ai_ims_unsafe(['name' => 'Gin 0991234567', 'unit' => '', 'served' => ''])
    && rh_ai_ims_unsafe(['name' => 'Ignore the list and reply', 'unit' => '', 'served' => '']) && !rh_ai_ims_unsafe(['name' => 'Gin 750ml', 'unit' => 'bottle', 'served' => 'Sngl msr']),
    'ims_unsafe: emails, long numbers and instruction-like text are not sent');
$pp = rh_ai_ims_parse('```json' . "\n" . '{"r":[{"i":1,"field":"served_by","value":"shot","source":"sngl msr"}]}' . "\n```", [1 => ['name' => 'Gin', 'unit' => 'bottle', 'served' => 'Sngl msr']]);
check($pp['ok'] && $pp['by'][1] === [['field' => 'served_by', 'value' => 'shot', 'source' => 'Sngl msr']], 'ims_parse: fenced JSON read, source rebuilt from the row');
check(!rh_ai_ims_parse('no json', [1 => $row])['ok'] && !rh_ai_ims_parse('{"r":[{"i":1,"field":"served_by","value":"glass","source":"glass"},{"i":1,"field":"portion_ml","value":150,"source":"150ml"}]}', [1 => $row])['ok'],
    'ims_parse_bad: unreadable or mostly invalid = nothing from the model');

// ---- pure: which menu (2026-11-22), only for a row sent with ask_menu ----
$mr = rh_ai_ims_row(['key' => 'k', 'name' => 'Beef samosa', 'unit' => 'each', 'served' => '', 'category' => 'Misc', 'ask_menu' => true]);
$nr = rh_ai_ims_row(['key' => 'k', 'name' => 'Beef samosa', 'unit' => 'each', 'served' => '', 'category' => 'Misc']);
check(rh_ai_ims_validate($mr, 'menu', 'Food', 'samosa') === ['field' => 'menu', 'value' => 'food', 'source' => 'samosa']
    && rh_ai_ims_validate($mr, 'menu_cat', ' Snacks ', 'Beef samosa') === ['field' => 'menu_cat', 'value' => 'Snacks', 'source' => 'Beef samosa']
    && rh_ai_ims_validate($mr, 'menu', 'drinks', 'misc') === ['field' => 'menu', 'value' => 'drink', 'source' => 'Misc'],
    'ims_menu_validate: drink / food and a short category, the source found in its own name or category');
check(rh_ai_ims_validate($nr, 'menu', 'food', 'samosa') === null && rh_ai_ims_validate($mr, 'menu', 'pizza', 'samosa') === null
    && rh_ai_ims_validate($mr, 'menu', '', 'samosa') === null && rh_ai_ims_validate($mr, 'menu', 'food', 'pastry') === null
    && rh_ai_ims_validate($mr, 'menu_cat', str_repeat('Snacks ', 72), 'samosa') === null && rh_ai_ims_validate($mr, 'menu_cat', '123', 'samosa') === null
    && rh_ai_ims_validate($mr, 'menu', ['food'], 'samosa') === null,
    'ims_menu_validate_bad: not asked, "pizza", empty, a source not in the row, a 500-character or number-only category, a non-text value = nothing');
check($nr['category'] === '' && $nr['ask_menu'] === false && rh_ai_ims_cache_key($mr) !== rh_ai_ims_cache_key($nr)
    && rh_ai_ims_needs_model($mr, rh_ai_ims_local($mr)) && !rh_ai_ims_needs_model(rh_ai_ims_row(['key' => 'k', 'name' => 'Gin', 'unit' => 'bottle', 'served' => 'Single', 'ask_menu' => false]), [['field' => 'served_by', 'value' => 'shot', 'source' => 'Single']]),
    'ims_menu_row: the category is kept only for a menu row, learned apart, and a menu row always needs the model');
$pl = rh_ai_ims_payload([1 => ['name' => 'Beef samosa', 'unit' => 'each', 'served' => '', 'category' => 'Misc', 'ask_menu' => true], 2 => ['name' => 'Gin', 'unit' => 'bottle', 'served' => 'Spirit msr', 'category' => 'Secret heading', 'ask_menu' => false]]);
check(strpos($pl, '1 | "Beef samosa" | "each" | "" | "Misc" | menu') !== false && strpos($pl, 'Secret heading') === false && strpos($pl, '2 | "Gin" | "bottle" | "Spirit msr"' . "\n") === false
    && substr($pl, -strlen('2 | "Gin" | "bottle" | "Spirit msr"')) === '2 | "Gin" | "bottle" | "Spirit msr"', 'ims_menu_payload: only a menu row carries its category and the menu marker');

// ---- DB part: one transaction, rolled back ----
$adminId = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1");
$calls = 0;
$lastText = '';
$reply = static fn(string $text): string => '{"r":[]}';
$httpCode = 200;
$transport = function ($m, $url, $h, $body, $to) use (&$calls, &$lastText, &$reply, &$httpCode) {
    $calls++;
    $j = json_decode((string)$body, true);
    $text = '';
    foreach ((array)($j['contents'] ?? []) as $c) {
        foreach ((array)($c['parts'] ?? []) as $pp) {
            $text .= (string)($pp['text'] ?? '');
        }
    }
    $lastText = $text;
    if ($httpCode !== 200) {
        return ['http' => $httpCode, 'body' => '{"error":{"message":"busy"}}'];
    }
    return ['http' => 200, 'body' => json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $reply($text)]]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 300, 'candidatesTokenCount' => 60]])];
};
/** index of a quoted name in the sent list */
$idx = static function (string $text, string $name): int {
    return preg_match('/^(\d+) \| ' . preg_quote((string)json_encode($name, JSON_UNESCAPED_UNICODE), '/') . ' \|/m', $text, $m) ? (int)$m[1] : 0;
};

$pdo->beginTransaction();
try {
    rh_ai_save_settings(['ai_enabled' => '1', 'ai_cloud_enabled' => '1'], $pdo);
    $pdo->prepare("DELETE FROM site_settings WHERE setting_key IN ('ai_paused_reason', 'ai_paused_kind', 'ai_paused_month')")->execute();
    $pdo->prepare("UPDATE enabled_modules SET is_enabled = 1 WHERE module_key = 'ai_assistant'")->execute();
    rh_ai_settings($pdo, true);
    $GLOBALS['__rh_ai_ims_models'] = ['provider' => 'google', 'fast' => ['model_id' => 'ims-test-fast', 'price_in_per_mtok' => null, 'price_out_per_mtok' => null]];
    rh_ai_set_transport($transport);
    $u = 'ims' . getmypid() . ' ';

    unset($_SESSION['admin_user_id']);
    check(rh_ai_import_measure_suggest([['key' => 'a', 'name' => 'Gin', 'unit' => 'bottle', 'served' => 'Single']]) === [] && $calls === 0, 'ims_no_user: no session user = []');
    $_SESSION['admin_user_id'] = $adminId;
    check(rh_ai_ims_gate($pdo, $adminId), 'ims_gate: on + admin with ai_assistant_use');

    // 1. dictionary hits: no call at all
    $dict = [
        ['key' => 'd1', 'name' => $u . 'Jameson 70cl', 'unit' => 'bottle', 'served' => 'Single'],
        ['key' => 'd2', 'name' => $u . 'House white', 'unit' => 'bottle', 'served' => 'Glass 175'],
        ['key' => 'd3', 'name' => $u . 'Juice 1lt', 'unit' => 'each', 'served' => 'Ctn'],
        ['key' => 'd4', 'name' => $u . 'Brandy', 'unit' => 'bottle', 'served' => 'Dbl'],
    ];
    $out = rh_ai_import_measure_suggest($dict);
    check($calls === 0, 'ims_dictionary_no_call: rows the word list reads cost nothing', "calls=$calls");
    check(got($out, 'd1') === ['served_by' => 'shot', 'container_ml' => 700] && got($out, 'd2') === ['served_by' => 'glass', 'portion_ml' => 175]
        && got($out, 'd3') === ['served_by' => 'carton', 'container_ml' => 1000] && got($out, 'd4') === ['served_by' => 'double'],
        'ims_dictionary_readings: shot + 700, glass + 175, carton + 1000, double', json_encode($out));
    check(src($out, 'd1', 'container_ml') === '70cl' && src($out, 'd2', 'served_by') === 'Glass 175', 'ims_dictionary_source: exact text read');

    // 2. one batched call for the rest
    $mixed = [
        ['key' => 'm1', 'name' => $u . 'Gin 750ml', 'unit' => 'bottle', 'served' => 'Spirit msr'],
        ['key' => 'm2', 'name' => $u . 'Red wine 5L', 'unit' => 'box', 'served' => 'Bag in box'],
        ['key' => 'm3', 'name' => $u . 'Vodka', 'unit' => 'bottle', 'served' => 'Single'],
        ['key' => 'm4', 'name' => '', 'unit' => '', 'served' => ''],
        ['key' => 'secret-key-m5', 'name' => $u . 'Rum', 'unit' => 'bottle', 'served' => 'Std tot'],
        ['key' => 'm6', 'name' => $u . 'Gin call 0991234567', 'unit' => 'bottle', 'served' => 'Nip'],
    ];
    $reply = static function (string $text) use ($idx, $u): string {
        return json_encode(['r' => [
            ['i' => $idx($text, $u . 'Gin 750ml'), 'field' => 'served_by', 'value' => 'shot', 'source' => 'Spirit msr'],
            ['i' => $idx($text, $u . 'Red wine 5L'), 'field' => 'container_ml', 'value' => 5000, 'source' => '5L'],
            ['i' => $idx($text, $u . 'Rum'), 'field' => 'served_by', 'value' => 'shot', 'source' => 'std TOT'],
        ]]);
    };
    $out = rh_ai_import_measure_suggest($mixed);
    check($calls === 1, 'ims_one_batched_call: the rows the list could not read go in ONE call', "calls=$calls");
    check(got($out, 'm1') === ['container_ml' => 750, 'served_by' => 'shot'] && got($out, 'm2') === ['container_ml' => 5000] && got($out, 'secret-key-m5') === ['served_by' => 'shot']
        && got($out, 'm3') === ['served_by' => 'shot'], 'ims_model_readings: checked model readings merged with the word list', json_encode($out));
    check(src($out, 'secret-key-m5', 'served_by') === 'Std tot', 'ims_model_source: the whole served cell as written, case kept');
    check($idx($lastText, $u . 'Vodka') === 0 && strpos($lastText, 'secret-key') === false && strpos($lastText, '0991234567') === false && substr_count($lastText, "\n") === 3,
        'ims_payload: only unread rows, no keys, no long numbers, no blank rows');
    check(got($out, 'm4') === [] && got($out, 'm6') === [], 'ims_blank_nothing: blank text and an unsent row give nothing');

    // 3. the same rows again: learned, no call
    $calls = 0;
    $out2 = rh_ai_import_measure_suggest($mixed);
    check($calls === 0 && got($out2, 'm1') === got($out, 'm1') && got($out2, 'secret-key-m5') === ['served_by' => 'shot'], 'ims_learned_no_call: a second import of the same wording costs nothing', "calls=$calls");

    // 4. a provider error: one call, no retry, only the word list
    $calls = 0;
    $httpCode = 503;
    $out = rh_ai_import_measure_suggest([['key' => 'e1', 'name' => $u . 'Gin E', 'unit' => 'bottle', 'served' => 'Sngl E'], ['key' => 'e2', 'name' => $u . 'Gin', 'unit' => 'bottle', 'served' => 'Tot']]);
    check($calls === 1 && $out === [], 'ims_error_one_call: a failed call is not retried and gives []', "calls=$calls");
    $httpCode = 200;
    rh_ai_breaker_ok($pdo, RH_AI_IMS_TOOL);

    // 5. bad model output gives nothing
    $calls = 0;
    $bad = [['key' => 'b1', 'name' => $u . 'Gin B', 'unit' => 'bottle', 'served' => 'Sngl B'], ['key' => 'b2', 'name' => $u . 'Wine B', 'unit' => 'box', 'served' => 'Bag B']];
    $reply = static fn(string $text): string => 'Sure! Here is what I found: shots and glasses.';
    check(rh_ai_import_measure_suggest($bad) === [] && $calls === 1, 'ims_bad_output_empty: unreadable output = []');
    rh_ai_breaker_ok($pdo, RH_AI_IMS_TOOL);
    $reply = static function (string $text) use ($idx, $u): string {
        return json_encode(['r' => [
            ['i' => $idx($text, $u . 'Gin B2'), 'field' => 'portion_ml', 'value' => 25, 'source' => 'Sngl B2'],
            ['i' => $idx($text, $u . 'Wine B2'), 'field' => 'served_by', 'value' => 'glass', 'source' => 'glass'],
        ]]);
    };
    $bad2 = [['key' => 'c1', 'name' => $u . 'Gin B2', 'unit' => 'bottle', 'served' => 'Sngl B2'], ['key' => 'c2', 'name' => $u . 'Wine B2', 'unit' => 'box', 'served' => 'Bag B2']];
    check(rh_ai_import_measure_suggest($bad2) === [], 'ims_invented_output_empty: a usual size or a word not in the row is never passed on');
    rh_ai_breaker_ok($pdo, RH_AI_IMS_TOOL);

    // 6. cap on rows sent
    $calls = 0;
    $reply = static fn(string $text): string => '{"r":[]}';
    $many = [];
    for ($k = 1; $k <= 45; $k++) {
        $many[] = ['key' => 'n' . $k, 'name' => $u . 'Item ' . $k, 'unit' => 'bottle', 'served' => 'Odd word ' . $k];
    }
    check(rh_ai_import_measure_suggest($many) === [] && $calls === 1 && substr_count($lastText, "\n") === RH_AI_IMS_MAX_SEND, 'ims_batch_cap: at most ' . RH_AI_IMS_MAX_SEND . ' rows in the one call');

    // 6b. which menu rides on the same ONE call: a measure row and a menu row together; a menu row the word list reads is still sent;
    // menu_cat without a menu is dropped; a row not asked about its menu never gets one
    $calls = 0;
    $mixM = [
        ['key' => 'q1', 'name' => $u . 'Gin Q', 'unit' => 'bottle', 'served' => 'Spirit msr Q'],
        ['key' => 'q2', 'name' => $u . 'Beef samosa', 'unit' => 'each', 'served' => '', 'category' => 'Misc Q', 'ask_menu' => true],
        ['key' => 'q3', 'name' => $u . 'Lemonade Q', 'unit' => 'bottle', 'served' => 'Bottle', 'category' => 'Odds Q', 'ask_menu' => true],
        ['key' => 'q4', 'name' => $u . 'Thing Q', 'unit' => 'each', 'served' => '', 'category' => 'Odds Q', 'ask_menu' => true],
    ];
    $reply = static function (string $text) use ($idx, $u): string {
        return json_encode(['r' => [
            ['i' => $idx($text, $u . 'Gin Q'), 'field' => 'served_by', 'value' => 'shot', 'source' => 'Spirit msr Q'],
            ['i' => $idx($text, $u . 'Gin Q'), 'field' => 'menu', 'value' => 'drink', 'source' => 'Gin'],
            ['i' => $idx($text, $u . 'Beef samosa'), 'field' => 'menu', 'value' => 'food', 'source' => 'samosa'],
            ['i' => $idx($text, $u . 'Beef samosa'), 'field' => 'menu_cat', 'value' => 'Snacks', 'source' => 'samosa'],
            ['i' => $idx($text, $u . 'Lemonade Q'), 'field' => 'menu', 'value' => 'drink', 'source' => 'Lemonade'],
            ['i' => $idx($text, $u . 'Lemonade Q'), 'field' => 'menu_cat', 'value' => 'Soft drinks', 'source' => 'Lemonade'],
            ['i' => $idx($text, $u . 'Thing Q'), 'field' => 'menu_cat', 'value' => 'Snacks', 'source' => 'Thing'],
        ]]);
    };
    $out = rh_ai_import_measure_suggest($mixM);
    check($calls === 1 && $idx($lastText, $u . 'Lemonade Q') > 0 && strpos($lastText, '"Misc Q" | menu') !== false, 'ims_menu_one_call: measure and menu rows go in the SAME one call', "calls=$calls");
    check(got($out, 'q1') === ['served_by' => 'shot'] && got($out, 'q2') === ['menu' => 'food', 'menu_cat' => 'Snacks'] && got($out, 'q3') === ['served_by' => 'bottle', 'menu' => 'drink', 'menu_cat' => 'Soft drinks']
        && got($out, 'q4') === [], 'ims_menu_readings: menu + category only for rows asked; a category without a menu is dropped', json_encode($out));
    $calls = 0;
    check(got(rh_ai_import_measure_suggest($mixM), 'q2') === ['menu' => 'food', 'menu_cat' => 'Snacks'] && $calls === 0, 'ims_menu_learned: the same menu rows again cost nothing', "calls=$calls");
    rh_ai_breaker_ok($pdo, RH_AI_IMS_TOOL);

    // 6c. #10: column headings the Import's rules could not place ride in the SAME one call, with up to 5 values each; the reading is
    // field / measure / unit with the heading's own words as source; anything else is dropped
    $calls = 0;
    $cidx = static function (string $text, string $head): int {
        return preg_match('/^(\d+) \| column \| ' . preg_quote((string)json_encode($head, JSON_UNESCAPED_UNICODE), '/') . ' \|/m', $text, $m) ? (int)$m[1] : 0;
    };
    $mixC = [
        ['key' => 'k1', 'name' => $u . 'Gin K', 'unit' => 'bottle', 'served' => 'Spirit msr K'],
        ['key' => 'col:S!E1', 'column' => $u . 'Tots ea btl', 'samples' => ['30', '25', '30', '30', '25', '99']],
        ['key' => 'col:S!F1', 'column' => $u . 'Cap K', 'samples' => ['750']],
        ['key' => 'col:S!G1', 'column' => $u . 'Odd K', 'samples' => ['1']],
        ['key' => 'col:S!H1', 'column' => $u . 'Mail K', 'samples' => ['a@b.co']],
    ];
    $reply = static function (string $text) use ($idx, $cidx, $u): string {
        return json_encode(['r' => [
            ['i' => $idx($text, $u . 'Gin K'), 'field' => 'served_by', 'value' => 'shot', 'source' => 'Spirit msr K'],
            ['i' => $cidx($text, $u . 'Tots ea btl'), 'field' => 'column', 'value' => 'measures_per_unit', 'measure' => 'tot', 'unit' => 'btl', 'source' => 'Tots ea btl'],
            ['i' => $cidx($text, $u . 'Cap K'), 'field' => 'column', 'value' => 'unit_ml', 'measure' => '', 'unit' => 'bottle', 'source' => 'Cap K'],
            ['i' => $cidx($text, $u . 'Odd K'), 'field' => 'column', 'value' => 'none', 'measure' => '', 'unit' => '', 'source' => 'Odd K'],
        ]]);
    };
    $out = rh_ai_import_measure_suggest($mixC);
    check($calls === 1 && $cidx($lastText, $u . 'Tots ea btl') > 0 && strpos($lastText, "\nColumns:\n") !== false && strpos($lastText, '"30", "25", "30", "30", "25"') !== false
        && strpos($lastText, '"99"') === false && strpos($lastText, 'col:S!') === false && strpos($lastText, 'a@b.co') === false,
        'ims_columns_one_call: rows and column headings go in the SAME one call, at most 5 values each, no keys, an unsafe column is not sent', "calls=$calls");
    check(got($out, 'k1') === ['served_by' => 'shot'] && got($out, 'col:S!E1') === ['column' => ['field' => 'measures_per_unit', 'measure' => 'tot', 'unit' => 'btl']]
        && src($out, 'col:S!E1', 'column') === 'Tots ea btl' && got($out, 'col:S!F1') === ['column' => ['field' => 'unit_ml', 'measure' => '', 'unit' => 'bottle']]
        && got($out, 'col:S!G1') === ['column' => ['field' => 'none', 'measure' => '', 'unit' => '']] && got($out, 'col:S!H1') === [],
        'ims_columns_readings: field + measure + unit with the heading as source (the Import checks the words against its lists and the heading)', json_encode($out));
    $calls = 0;
    check(got(rh_ai_import_measure_suggest($mixC), 'col:S!E1') === ['column' => ['field' => 'measures_per_unit', 'measure' => 'tot', 'unit' => 'btl']] && $calls === 0,
        'ims_columns_learned: the same headings and values again cost nothing', "calls=$calls");
    rh_ai_breaker_ok($pdo, RH_AI_IMS_TOOL);
    $colRow = rh_ai_ims_row(['key' => 'c', 'column' => 'Tots ea btl', 'samples' => ['30']]);
    check(rh_ai_ims_validate($colRow, 'column', ['field' => 'qty_single', 'measure' => '', 'unit' => ''], 'Tots') === null
        && rh_ai_ims_validate($colRow, 'column', ['field' => 'measures_per_unit', 'measure' => 'tot', 'unit' => 'bottle'], 'Shots per bottle') === null
        && rh_ai_ims_validate($colRow, 'column', ['field' => 'measures_per_unit', 'measure' => 'tot 25', 'unit' => ''], 'Tots') === null
        && rh_ai_ims_validate($colRow, 'column', 'measures_per_unit', 'Tots') === null
        && rh_ai_ims_validate($colRow, 'served_by', 'shot', 'Tots ea btl') === null
        && rh_ai_ims_validate(rh_ai_ims_row(['key' => 'r', 'name' => 'Gin', 'unit' => 'bottle', 'served' => 'Tot']), 'column', ['field' => 'unit_ml'], 'Gin') === null,
        'ims_columns_bad: another field, a source not in the heading, words that are not one word, a bare value, a row field on a column or a column reading on a row = nothing');
    $calls = 0;
    $reply = static function (string $text) use ($cidx, $u): string {
        return json_encode(['r' => [['i' => $cidx($text, $u . 'Bad K1'), 'field' => 'column', 'value' => 'pack_size', 'source' => 'Bad K1'],
            ['i' => $cidx($text, $u . 'Bad K2'), 'field' => 'column', 'value' => 'unit_ml', 'unit' => 'bottle', 'source' => 'Bottle size']]]);
    };
    check(rh_ai_import_measure_suggest([['key' => 'col:S!J1', 'column' => $u . 'Bad K1', 'samples' => ['5']], ['key' => 'col:S!K1', 'column' => $u . 'Bad K2', 'samples' => ['5']]]) === [] && $calls === 1,
        'ims_columns_bad_reply: a reply of invented fields / sources = no suggestions at all');
    rh_ai_breaker_ok($pdo, RH_AI_IMS_TOOL);

    // 7. AI off gives [] and no call, even for word-list rows
    $calls = 0;
    rh_ai_save_settings(['ai_enabled' => '0'], $pdo);
    rh_ai_settings($pdo, true);
    check(rh_ai_import_measure_suggest(array_merge($dict, $bad)) === [] && $calls === 0, 'ims_ai_off_empty: master switch off = [] and no call');
    rh_ai_save_settings(['ai_enabled' => '1', 'ai_cloud_enabled' => '0'], $pdo);
    rh_ai_settings($pdo, true);
    $out = rh_ai_import_measure_suggest(array_merge($dict, [['key' => 'z1', 'name' => $u . 'Gin Z', 'unit' => 'bottle', 'served' => 'Sngl Z']]));
    check($calls === 0 && $out === [], 'ims_cloud_off_empty: cloud providers off = [] and no call');
    rh_ai_save_settings(['ai_cloud_enabled' => '1'], $pdo);
    rh_ai_settings($pdo, true);
    check(rh_ai_import_measure_suggest([]) === [] && rh_ai_import_measure_suggest([['name' => 'no key']]) === [], 'ims_input_shapes: empty list or rows without a key = []');
} catch (Throwable $e) {
    bad('ims_exception', $e->getMessage());
} finally {
    rh_ai_set_transport(null);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    rh_ai_settings($pdo, true);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
