<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * The Assistant set up from the licence panel (owner 2026-11-11, includes/licence-ai.php). Pure: no hotel database, no
 * network, no email (an in-memory SQLite database stands in where a PDO is needed, when the extension is there).
 * Usage (from the repo root): php scripts/tests/licence_ai.php
 *  1. the AI key in the answer opens only with this site's licence key, this tenant and this command id (a fixed vector made
 *     by the panel's own code, so both sides stay in step);
 *  2. the set-up is checked before anything runs; ai_setup travels with the staff changes (it has no username);
 *  3. the files: the hotel tests before it saves, changes nothing on a failed test, and never logs or reports the key.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } }
function eq($want, $got, string $l): void { check($want === $got, $l, 'expected ' . var_export($want, true) . ' got ' . var_export($got, true)); }

require $root . '/includes/licence-staff.php';
require $root . '/includes/licence-ai.php';

// ---- 1. the key in the answer (vector made by msp-control-panel app/ai.php ai_transport_seal; the panel's test opens it too)
$vec = 'cpai1:mx+/a+XvUaIuY6MWllBud/gXZDJ7fjATg/EV/Gr6vYtVmRkgKHHwvLJJ7BEcyZ2bBZNwO2kdAOKivrRSZjI4hMs=';
eq('sk-ant-api03-FIXEDVECTORFORTESTS-0000', rh_licence_ai_open_key($vec, 'test-licence-key-0123456789', 'zz-test', 42), 'the panel\'s key opens with this site\'s licence key');
eq('sk-ant-api03-FIXEDVECTORFORTESTS-0000', rh_licence_ai_open_key($vec, 'test-licence-key-0123456789', ' ZZ-Test ', 42), 'the tenant name is not case sensitive (as on the panel)');
eq('', rh_licence_ai_open_key($vec, 'test-licence-key-0123456780', 'zz-test', 42), 'another licence key cannot open it');
eq('', rh_licence_ai_open_key($vec, 'test-licence-key-0123456789', 'liwonde', 42), 'another hotel cannot open it');
eq('', rh_licence_ai_open_key($vec, 'test-licence-key-0123456789', 'zz-test', 41), 'bound to its command id');
eq('', rh_licence_ai_open_key($vec, '', 'zz-test', 42), 'no licence key: nothing');
eq('', rh_licence_ai_open_key('sk-ant-plain-key-sent-by-mistake', 'test-licence-key-0123456789', 'zz-test', 42), 'a plain key is never accepted');
$bad = base64_decode(substr($vec, 6));
$bad[20] = chr(ord($bad[20]) ^ 1);
eq('', rh_licence_ai_open_key('cpai1:' . base64_encode($bad), 'test-licence-key-0123456789', 'zz-test', 42), 'a changed answer is refused');

// ---- 2. the set-up checked
$good = ['provider' => 'anthropic', 'key_enc' => $vec, 'key_last4' => '0000', 'models' => ['primary' => 'claude-sonnet-5-5', 'fast' => 'claude-haiku-5-5', 'fallback' => ''],
    'budget_usd' => 5, 'switch_on' => true];
[$e, $d] = rh_licence_ai_clean($good);
eq('', $e, 'a good set-up');
eq(['claude-sonnet-5-5' => 'primary', 'claude-haiku-5-5' => 'fast'], $d['roles'], 'one role per model, empty roles left out');
eq([5.0, true], [$d['budget_usd'], $d['switch_on']], 'aim and switch');
eq(false, rh_licence_ai_clean(['switch_on' => 'yes'] + $good)[1]['switch_on'], 'switch on only when exactly true');
eq('The set-up names a provider this system does not know.', rh_licence_ai_clean(['provider' => 'acme'] + $good)[0], 'unknown provider');
eq('The set-up carries no key.', rh_licence_ai_clean(['key_enc' => ''] + $good)[0], 'no key');
eq('The set-up has no Primary model.', rh_licence_ai_clean(['models' => ['fast' => 'claude-haiku-5-5']] + $good)[0], 'no Primary');
eq('The set-up gives one model two roles.', rh_licence_ai_clean(['models' => ['primary' => 'm-1', 'fast' => 'm-1']] + $good)[0], 'one model, two roles');
eq('The set-up names a model this system cannot use.', rh_licence_ai_clean(['models' => ['primary' => 'x" onclick="y']] + $good)[0], 'odd model name');
eq('The set-up has a monthly aim this system cannot use.', rh_licence_ai_clean(['budget_usd' => -1] + $good)[0], 'negative aim');
eq('The set-up names a provider this system does not know.', rh_licence_ai_clean('junk')[0], 'not an object');

$cmds = rh_licence_staff_commands(['staff_commands' => [
    ['id' => 11, 'kind' => 'ai_setup', 'username' => '', 'user_id' => 0, 'data' => $good],
    ['id' => 12, 'kind' => 'activate', 'username' => ''],
]]);
eq([11], array_column($cmds, 'id'), 'ai_setup travels with the staff changes without a username; other changes still need one');
eq('anthropic', $cmds[0]['data']['provider'], 'its data is kept');
eq(true, rh_licence_ai_load(), 'the Assistant\'s own code loads without a database');

if (extension_loaded('pdo_sqlite')) {
    $mem = new PDO('sqlite::memory:');
    $mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r = rh_licence_ai_apply($mem, ['id' => 11, 'kind' => 'ai_setup', 'data' => ['provider' => 'acme'] + $good]);
    eq(false, $r['ok'], 'a bad set-up is refused');
    check(strpos($r['message'], 'Nothing was changed.') !== false, 'and says nothing was changed');
    $r = rh_licence_ai_apply($mem, ['id' => 11, 'kind' => 'ai_setup', 'data' => $good]);
    eq(['ok' => false, 'message' => 'The Assistant\'s database update has not been run on this system yet. Nothing was changed.'], $r, 'no Assistant tables: refused, nothing changed');
    eq(['ready' => false, 'enabled' => false], rh_licence_ai_state($mem), 'the report says when the Assistant is not installed');
    eq(null, rh_licence_ai_usage($mem), 'no usage without the Assistant');
}

// ---- 3. the files
$src = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$ai = $src('includes/licence-ai.php');
$apply = substr($ai, strpos($ai, 'function rh_licence_ai_apply'), strpos($ai, 'function rh_licence_ai_state') - strpos($ai, 'function rh_licence_ai_apply'));
check(strpos($apply, 'rh_ai_list_models(') < strpos($apply, 'rh_ai_store_key(') && strpos($apply, 'rh_ai_ping(') < strpos($apply, 'rh_ai_store_key('),
    'the hotel tests the key and every chosen model BEFORE it saves anything');
check(substr_count($apply, 'Nothing was changed.') >= 6 && substr_count($apply, 'so nothing was changed') >= 3, 'every refusal before saving says nothing was changed');
check(!preg_match('/error_log\([^;]*\$key/', $ai) && !preg_match('/rh_licence_staff_log\([^;]*\$key/', $ai), 'the key is never logged');
check(strpos($apply, "rh_ai_store_key(\$d['provider'], \$key, 0, \$pdo)") !== false, 'the key is saved only the Assistant\'s usual, encrypted way');
$state = substr($ai, strpos($ai, 'function rh_licence_ai_state'));
check(strpos($state, 'api_key_enc') === false && strpos($state, 'rh_ai_get_key') === false, 'the report never reads the key');
$staffSrc = $src('includes/licence-staff.php');
check(strpos($staffSrc, "'ai' => \$ai + ['usage' => rh_licence_ai_usage(\$pdo)]") !== false, 'the staff report carries the Assistant\'s state and usage');
check(strpos($staffSrc, '$hash = sha1((string)json_encode([$staff, $catalog, $ai]));') !== false, 'a change to the Assistant makes a report; usage alone does not');
check(strpos($staffSrc, "if (\$c['kind'] === 'ai_setup') {") < strpos($staffSrc, '$roles = getAllRoles();', strpos($staffSrc, 'function rh_licence_staff_apply')),
    'ai_setup is handled before any staff lookup');

echo "licence_ai: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
