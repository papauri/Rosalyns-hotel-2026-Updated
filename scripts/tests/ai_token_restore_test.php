<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Assistant privacy tokens never reach the screen raw (owner fault 2026-11-11, Rosalyn's): "How many nights is mr baluwa
 * in for" was answered "Mr [GUEST_1]'s stay is for 3 nights, from 2026-11-08 to 2026-11-11." The chat had carried on
 * after its vault was purged (sign-out / ended-session sweep), so [GUEST_1] from the stored history had no value left.
 * NO DATABASE: only includes/ai/ai-vault.php (+ ai-core.php) is loaded; the per-request vault memo is seeded by hand and
 * a PDO stand-in that is never called is passed. Usage (repo root): php scripts/tests/ai_token_restore_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/includes/ai/ai-vault.php';
check(!isset($GLOBALS['pdo']) && !function_exists('getSetting'), 'the vault helpers load without the database');

/** A PDO that is never connected: any call would fail the test (the seeded memo means none is made). */
final class RhNoDbPdo extends PDO
{
    public function __construct()
    {
    }
    #[\ReturnTypeWillChange]
    public function prepare($q, $o = [])
    {
        throw new RuntimeException('database touched');
    }
}
$db = new RhNoDbPdo();
$seed = static function (int $conv, array $tok): void {
    $memo = &rh_ai_vault_memo();
    $memo[$conv] = ['tok' => $tok, 'hash' => []];
};
$logFile = (string)tempnam(sys_get_temp_dir(), 'rhaitok'); // the fault line is logged here (kept off the test output), read back below
ini_set('error_log', $logFile);

$question = 'How many nights is mr baluwa in for';
$reply = "Mr [GUEST_1]'s stay is for 3 nights, from 2026-11-08 to 2026-11-11.";

// 1. The value is in this chat's vault: the real name is shown (the honorific case "Mr [GUEST_1]'s")
$seed(41, ['[GUEST_1]' => 'John Baluwa']);
$shown = rh_ai_vault_show($db, 41, $reply, 'reply:primary');
check(strpos($shown, 'John Baluwa') !== false && strpos($shown, '[GUEST_1]') === false, 'the reply to "' . $question . '" shows the real name', $shown);
check($shown === "Mr John Baluwa's stay is for 3 nights, from 2026-11-08 to 2026-11-11.", 'the rest of the reply is unchanged', $shown);

// 2. Other forms a model writes a token in are restored too
foreach (["Mr \\[GUEST_1\\]'s stay" => 'escaped brackets', "Mr GUEST_1's stay" => 'no brackets', "Mr [guest_1]'s stay" => 'lower case', "Mr [GUEST 1]'s stay" => 'a space'] as $t => $l) {
    $s = rh_ai_vault_show($db, 41, $t);
    check($s === "Mr John Baluwa's stay", 'a token written with ' . $l . ' is restored', $s);
}
check(rh_ai_vault_canon('Ref [BK2026227892] and RBH2026227892') === 'Ref [BK2026227892] and RBH2026227892', 'a bracketed booking reference is not read as a token');
check(rh_ai_vault_canon("Mr \\[GUEST_1\\]'s") === "Mr [GUEST_1]'s", 'the stored copy is canonical tokens only (never a real name)');

// 3. The failing path: the chat carried on after its vault was purged - the token has no value
$seed(42, []);
$shown = rh_ai_vault_show($db, 42, $reply, 'reply:primary');
check(!preg_match('/\[(GUEST|EMAIL|PHONE|BK|STAFF)_\d+\]/', $shown), 'a token with no value is never shown raw', $shown);
check($shown === "Mr (guest)'s stay is for 3 nights, from 2026-11-08 to 2026-11-11.", 'it is shown as the plain word', $shown);
$logged = (string)@file_get_contents($logFile);
check(substr_count($logged, 'privacy token(s) with no stored value') === 1 && strpos($logged, 'conversation 42, reply:primary') !== false && strpos($logged, 'GUEST x1') !== false,
    'the unresolved token is logged once (conversation, where, kind and count)', $logged);
check(stripos($logged, 'baluwa') === false && strpos($logged, '[GUEST_1]') === false, 'the log line holds no name and no token');
$r = rh_ai_vault_show_map([], 'Card for [GUEST_9] ([BK_2]), called [STAFF_3] on [PHONE_1] / [EMAIL_4]');
check($r['text'] === 'Card for (guest) ((booking)), called (staff) on (phone) / (email)', 'every kind has its plain word', $r['text']);
check($r['unresolved'] === ['GUEST' => 1, 'BK' => 1, 'STAFF' => 1, 'PHONE' => 1, 'EMAIL' => 1], 'the unresolved kinds are counted for the log (no values)', json_encode($r['unresolved']));
$r = rh_ai_vault_show_map(['[GUEST_1]' => 'John Baluwa'], 'For [GUEST_1] and [GUEST_9]');
check($r['text'] === 'For John Baluwa and (guest)' && $r['unresolved'] === ['GUEST' => 1], 'a known and an unknown token in one reply', $r['text']);

// 4. Root cause: a purged token in the stored history never goes back to the model as a token, so it cannot be repeated
$history = "[GUEST_1] ([BK_1]) - Deluxe - arrives Sun 8 Nov 2026, leaves Wed 11 Nov 2026 (3 nights)";
check(rh_ai_vault_orphans_map([], $history) === '(guest) ((booking)) - Deluxe - arrives Sun 8 Nov 2026, leaves Wed 11 Nov 2026 (3 nights)', 'purged tokens in history go as plain words', rh_ai_vault_orphans_map([], $history));
check(rh_ai_vault_orphans_map(['[GUEST_1]' => 'John Baluwa', '[BK_1]' => 'RBH2026000001'], $history) === $history, 'tokens still in the vault stay tokens (no real value goes to the model)');
$seed(43, ['[GUEST_2]' => 'Ann Phiri']);
$o = rh_ai_vault_orphans_out($db, 43, '{"guest_name":"[GUEST_1]","other":"[GUEST_2]","check_in":"2026-11-08"}');
check($o === '{"guest_name":"(guest)","other":"[GUEST_2]","check_in":"2026-11-08"}' && is_array(json_decode($o, true)), 'earlier lookups stay valid JSON with only the purged token replaced', $o);

// 5. Nothing changed for the existing rules
check(rh_ai_vault_display('Card for [GUEST_3] ([BK_2])') === 'Card for (guest) ((booking))', 'after sign-out, old chats show plain words for names');
check(rh_ai_vault_show($db, 41, '') === '' && rh_ai_vault_show($db, 41, 'No items are waiting.') === 'No items are waiting.', 'text without tokens is untouched');

@unlink($logFile);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
