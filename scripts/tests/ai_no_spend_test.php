<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * NO_AI_SPEND guard (owner 2026-11-11: "make 100% sure all token use is blocked" on the developer laptop).
 * Shells there carry NO_AI_SPEND=1: no model call may leave the machine, the blocked call is never recorded as a
 * provider failure, and tests that inject a fake transport or name a fake provider still run.
 * NO DATABASE and NO NETWORK: settings come from a PDO stand-in; any real request would fail the test.
 * Usage (repo root): php scripts/tests/ai_no_spend_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

$envBefore = getenv('NO_AI_SPEND');
$fakeBefore = getenv('RH_AI_FAKE_PROVIDER');
putenv('RH_AI_FAKE_PROVIDER');

require_once $root . '/includes/ai/ai-providers.php';
check(!isset($GLOBALS['pdo']), 'the provider helpers load without the database');

/** Settings stand-in: query() returns the given ai_* rows; prepare() (any write) fails the test. */
final class RhNoSpendPdo extends PDO
{
    public $rows = [];
    public function __construct()
    {
    }
    #[\ReturnTypeWillChange]
    public function query($q, $m = null, ...$a)
    {
        $rows = $this->rows;
        return new class($rows) {
            private $r;
            public function __construct(array $r) { $this->r = $r; }
            public function fetchAll($m = null) { return $this->r; }
        };
    }
    #[\ReturnTypeWillChange]
    public function prepare($q, $o = [])
    {
        throw new RuntimeException('database written');
    }
}
$db = new RhNoSpendPdo();
$cloud = static function (string $v) use ($db): void {
    $db->rows = ['ai_cloud_enabled' => $v];
    rh_ai_settings($db, true);
};
$offMsg = 'Model calls are blocked on this machine (NO_AI_SPEND), so only quick local answers work.';

try {
    // 1. Unset: the setting decides.
    putenv('NO_AI_SPEND');
    $cloud('1');
    check(rh_ai_cloud_on() === true, 'NO_AI_SPEND unset + cloud setting on: cloud on');
    $cloud('0');
    check(rh_ai_cloud_on() === false, 'NO_AI_SPEND unset + cloud setting off: cloud off');

    // 2. NO_AI_SPEND=1: blocked whatever the setting says.
    putenv('NO_AI_SPEND=1');
    $cloud('1');
    check(rh_ai_cloud_on() === false, 'NO_AI_SPEND=1 + cloud setting on: cloud off');
    check(rh_ai_cloud_off_message() === $offMsg, 'the blocked message names NO_AI_SPEND, not Assistant Settings');
    $cloud('0');
    check(rh_ai_cloud_on() === false && strpos(rh_ai_cloud_off_message(), 'Assistant Settings') !== false, 'NO_AI_SPEND=1 + cloud setting off: the Settings message (the setting is what blocks)');
    $cloud('1');

    $r = rh_ai_http('POST', 'https://example.invalid/v1/chat', ['Content-Type: application/json'], '{}', 5);
    check($r['http'] === 0 && $r['error'] === $offMsg && $r['ms'] === 0 && $r['body'] === '', 'rh_ai_http returns at once with the blocked message', json_encode($r));
    check(!function_exists('facebookCaBundle'), 'rh_ai_http never reached the cURL setup (cURL options never built)');

    $c = rh_ai_chat(['provider' => 'google', 'model' => 'gemini-test', 'messages' => [['role' => 'user', 'content' => 'hi']]]);
    check($c['error'] === $offMsg && $c['http'] === 0 && $c['retryable'] === false && $c['model_missing'] === false,
        'rh_ai_chat stops before any request: http 0, not retryable, no model-missing (no failure counted)', json_encode($c));

    $m = rh_ai_chat_multi([
        'a' => ['provider' => 'google', 'model' => 'gemini-test', 'key' => 'x-test-key-not-real', 'messages' => [['role' => 'user', 'content' => 'a']]],
        'b' => ['provider' => 'google', 'model' => 'gemini-test', 'key' => 'x-test-key-not-real', 'messages' => [['role' => 'user', 'content' => 'b']]],
    ], 'gemini-fallback');
    check(($m['a']['error'] ?? '') === $offMsg && ($m['b']['error'] ?? '') === $offMsg && empty($m['a']['used_fallback']) && empty($m['b']['used_fallback']),
        'rh_ai_chat_multi (two leads) never opens a cURL multi call and tries no fallback', json_encode($m));
    check(!function_exists('facebookCaBundle'), 'still no cURL setup after the chat calls');

    $l = rh_ai_list_models('google', 'x-test-key-not-real');
    check($l['ok'] === false && $l['models'] === [], 'model list refresh is blocked too (no request)');

    $threw = '';
    try {
        rh_ai_record_test('google', false, 0, 'Could not reach the provider.', $db);
    } catch (Throwable $e) {
        $threw = $e->getMessage();
    }
    check($threw === '', 'a blocked test result is not written as a provider failure', $threw);
    check(rh_ai_no_spend_env() === true, 'rh_ai_no_spend_env reads the process environment');

    // 3. Tests keep working: an injected transport or a fake provider is not blocked.
    rh_ai_set_transport(static function ($method, $url, $headers, $body, $timeout) {
        return ['http' => 200, 'body' => '{"ok":true}', 'error' => ''];
    });
    check(rh_ai_cloud_on() === true, 'NO_AI_SPEND=1 + injected test transport: cloud on');
    $r = rh_ai_http('GET', 'https://example.invalid/v1/models', []);
    check($r['http'] === 200 && $r['body'] === '{"ok":true}', 'rh_ai_http answers from the injected transport', json_encode($r));
    $threw = '';
    try {
        rh_ai_record_test('google', false, 0, 'The provider refused the key.', $db);
    } catch (Throwable $e) {
        $threw = $e->getMessage();
    }
    check($threw === 'database written', 'with a test transport a failed result is still recorded (tests keep their checks)', $threw);
    $cloud('0');
    check(rh_ai_cloud_on() === false, 'injected transport + cloud setting off: still off (the setting decides)');
    rh_ai_set_transport(null);
    $cloud('1');

    putenv('RH_AI_FAKE_PROVIDER=fake_chat');
    check(rh_ai_cloud_on() === true, 'NO_AI_SPEND=1 + RH_AI_FAKE_PROVIDER (command line): cloud on');
    putenv('RH_AI_FAKE_PROVIDER');
    check(rh_ai_cloud_on() === false, 'fake provider removed: blocked again');

    // 4. Unset again: the setting decides, the normal message is back.
    putenv('NO_AI_SPEND');
    check(rh_ai_cloud_on() === true && rh_ai_no_spend_env() === false, 'NO_AI_SPEND unset again: the setting decides');
    check(strpos(rh_ai_cloud_off_message(), 'Assistant Settings') !== false, 'without NO_AI_SPEND the message points to Assistant Settings');
} finally {
    rh_ai_set_transport(null);
    putenv($envBefore === false ? 'NO_AI_SPEND' : 'NO_AI_SPEND=' . $envBefore);
    putenv($fakeBefore === false ? 'RH_AI_FAKE_PROVIDER' : 'RH_AI_FAKE_PROVIDER=' . $fakeBefore);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
