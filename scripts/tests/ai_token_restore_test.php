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
check((string)@file_get_contents($logFile) === '', 'nothing is logged until the reply is finished');
rh_ai_vault_show_flush();
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

// ── Follow-up (owner "Fix them all", 2026-11-11) ──────────────────────────────────────────────────────────────────

/** A PDO stand-in that records every statement and answers the vault / message reads from arrays (no database). */
final class RhFakeStmt
{
    private $p; private $q; private $rows; private $i = 0;
    public function __construct(RhFakePdo $p, string $q) { $this->p = $p; $this->q = $q; $this->rows = $p->rowsFor($q); }
    public function execute($params = null) { $this->p->log[] = [$this->q, $params]; return true; }
    public function fetchAll($mode = null, ...$a) { return $this->rows; }
    public function fetch($mode = null, ...$a) { return $this->rows[$this->i++] ?? false; }
    public function fetchColumn($c = 0) { return false; }
    public function rowCount() { return 0; }
}
final class RhFakePdo extends PDO
{
    public $log = []; public $vaultRows = []; public $msgRows = [];
    public function __construct()
    {
    }
    #[\ReturnTypeWillChange]
    public function prepare($q, $o = [])
    {
        return new RhFakeStmt($this, (string)$q);
    }
    public function rowsFor(string $q): array
    {
        if (strpos($q, 'SELECT') === 0 && strpos($q, 'FROM ai_vault') !== false) {
            return $this->vaultRows;
        }
        if (strpos($q, 'SELECT') === 0 && strpos($q, 'FROM ai_messages') !== false) {
            return $this->msgRows;
        }
        return [];
    }
    public function inserts(): array
    {
        return array_values(array_filter($this->log, static fn($l) => strpos($l[0], 'INSERT INTO ai_vault') === 0));
    }
}
$people = static function (array $full, array $part): void {
    $GLOBALS['__rh_ai_people'] = ['full' => $full, 'part' => $part, 'shared' => []];
};

// 6. Token numbers are never reused within a chat (purge then a new guest)
check(rh_ai_vault_next_num(['tok' => ['[GUEST_2]' => 'Ann'], 'max' => ['GUEST' => 4], 'floor' => ['GUEST' => 7]], 'GUEST') === 8, 'a new number is above the held, cleared and written ones');
check(rh_ai_vault_next_num(['tok' => [], 'hash' => []], 'BK') === 1 && rh_ai_vault_next_num(['tok' => ['[GUEST_3]' => 'x'], 'max' => ['GUEST' => 3]], 'BK') === 1, 'kinds are numbered on their own; an empty chat starts at 1');
check(rh_ai_vault_max_nums(["Mr [GUEST_1]'s stay", '{"g":"\\\\[GUEST_4\\\\]","s":"[staff 2]"}', 'GUEST_3 and [bk-9]', null]) === ['GUEST' => 4, 'STAFF' => 2, 'BK' => 9], 'numbers are read from every written form of a token', json_encode(rh_ai_vault_max_nums(["Mr [GUEST_1]'s stay", '{"g":"\\\\[GUEST_4\\\\]","s":"[staff 2]"}', 'GUEST_3 and [bk-9]'])));

$people(['john baluwa' => 'GUEST'], ['john' => 'GUEST', 'baluwa' => 'GUEST']);
$f = new RhFakePdo();
$f->vaultRows = [['kind' => 'GUEST', 'num' => 1, 'value_text' => 'John Baluwa', 'value_hash' => rh_ai_vault_hash('GUEST', 'John Baluwa')]];
rh_ai_vault_forget();
$old = "Mr [GUEST_1]'s stay is for 3 nights, from 2026-11-08 to 2026-11-11.";
check(rh_ai_vault_show($f, 60, $old) === "Mr John Baluwa's stay is for 3 nights, from 2026-11-08 to 2026-11-11.", 'before sign-out the old message shows the name');
$f->log = [];
rh_ai_signout_purge($f, 7);
$vsql = array_values(array_filter(array_column($f->log, 0), static fn($q) => strpos($q, 'ai_vault') !== false));
check(count($vsql) === 1 && strpos($vsql[0], 'UPDATE ai_vault') === 0 && strpos($vsql[0], "SET v.value_text = '', v.value_hash = ''") !== false && stripos($vsql[0], 'DELETE') === false,
    'sign-out clears the values but keeps the rows (numbers stay taken)', json_encode($vsql));
$sweep = (string)file_get_contents($root . '/includes/ai/ai-selfheal.php');
check(substr_count($sweep, "SET v.value_text = '', v.value_hash = ''") === 2 && strpos($sweep, 'DELETE v FROM ai_vault') === false, 'the 8-hour sweep clears values the same way (no rows deleted)');
// the database after the purge: the row is there, empty
$f->vaultRows = [['kind' => 'GUEST', 'num' => 1, 'value_text' => '', 'value_hash' => '']];
$f->msgRows = [];
$f->log = [];
$tokNew = rh_ai_scrub_outbound($f, 60, 'Is mr phiri in?', true);
check($tokNew === 'Is mr [GUEST_2] in?', 'the next guest in the chat gets a fresh number, not the cleared one', $tokNew);
$ins = $f->inserts();
check(count($ins) === 1 && $ins[0][1][1] === 'GUEST' && (int)$ins[0][1][2] === 2 && $ins[0][1][3] === 'phiri', 'one vault row is written, number 2', json_encode($ins));
$shownOld = rh_ai_vault_show($f, 60, $old, 'load', false);
check($shownOld === "Mr (guest)'s stay is for 3 nights, from 2026-11-08 to 2026-11-11.", 'the old message shows "(guest)", never the new guest\'s name', $shownOld);
check(rh_ai_vault_show($f, 60, 'Mr [GUEST_2] arrives today') === 'Mr phiri arrives today', 'the new guest\'s token shows the new name');
check(rh_ai_vault_rehydrate($f, 60, '[GUEST_1]') === '[GUEST_1]' && rh_ai_vault_orphans_out($f, 60, '[GUEST_1] / [GUEST_2]') === '(guest) / [GUEST_2]', 'a cleared value is never an empty string in arguments, and goes to the model as a plain word');
// a chat cleared before rows were kept (rows deleted): the numbers written in its messages and lookups still count
$f2 = new RhFakePdo();
$f2->msgRows = [["How many nights is mr [GUEST_1] in for", null, null], ['', '{"query":"[GUEST_3]"}', '{"bookings":[{"guest_name":"\\\\[GUEST_5\\\\]","reference":"[bk 2]"}]}']];
rh_ai_vault_forget();
$t2 = rh_ai_vault_token($f2, 61, 'GUEST', 'Ann Phiri');
check($t2 === '[GUEST_6]', 'an old chat whose rows were deleted: the new number is above every one in its messages and lookups', $t2);
check(rh_ai_vault_token($f2, 61, 'BK', 'RBH2026000001') === '[BK_3]' && rh_ai_vault_token($f2, 61, 'GUEST', 'Ann Phiri') === '[GUEST_6]', 'each kind counts its own; the same value keeps its token');
check(count(array_filter($f2->log, static fn($l) => strpos($l[0], 'FROM ai_messages') !== false)) === 1, 'the stored chat is read once per request');

// 7. A name after a title, in any case, is tokenised; the reply still shows the name
$f3 = new RhFakePdo();
rh_ai_vault_forget();
$q = 'how many nights is mr baluwa in for';
$t3 = rh_ai_scrub_outbound($f3, 62, $q, true);
check(stripos($t3, 'baluwa') === false && (bool)preg_match('/^how many nights is mr \[GUEST_\d+\] in for$/', $t3), 'the exact message "' . $q . '" leaves without the name ("mr" stays readable)', $t3);
preg_match('/\[GUEST_\d+\]/', $t3, $tm3);
check(rh_ai_vault_show($f3, 62, 'Mr ' . ($tm3[0] ?? '?') . "'s stay is for 3 nights.") === "Mr baluwa's stay is for 3 nights.", 'the reply shows the name back');
$people([], []); // nobody known: the title alone marks the name
rh_ai_vault_forget();
foreach ([
    'How many nights is Mr. kachingwe in for' => 'kachingwe', 'MRS BANDA checked out?' => 'BANDA', 'call dr phiri' => 'phiri', 'is madam chirwa in' => 'chirwa',
    'sir mwale wants tea' => 'mwale', "mr mbewe's bill" => 'mbewe', 'ms. Tembo' => 'Tembo', 'Miss nyirenda' => 'nyirenda', 'mister kumwenda' => 'kumwenda', 'mx gondwe' => 'gondwe',
] as $text => $name) {
    $t = rh_ai_scrub_outbound($f3, 63, $text, false);
    check(stripos($t, $name) === false && preg_match('/(mr|mrs|dr|madam|sir|ms|miss|mister|mx)\.? \[GUEST_\d+\]/i', $t), 'title + name leaves tokenised: "' . $text . '"', $t);
}
check(rh_ai_scrub_outbound($f3, 63, "mr mbewe's bill") === "mr [GUEST_6]'s bill" || (bool)preg_match("/^mr \\[GUEST_\\d+\\]'s bill$/", rh_ai_scrub_outbound($f3, 63, "mr mbewe's bill")), 'the possessive stays outside the token');
$t = rh_ai_scrub_outbound($f3, 64, 'Mr John Banda arrives');
check((bool)preg_match('/^Mr \[GUEST_\d+\] arrives$/', $t), 'a Capitalised second word is part of the same name', $t);
foreach (['did we miss any arrivals', 'mr', 'Mr and Mrs', 'yes sir, can you', 'did housekeeping miss room 12', 'did we miss anything', 'did we miss him', 'did we miss breakfast'] as $text) {
    $t = rh_ai_scrub_outbound($f3, 65, $text, true);
    check($t === $text, 'ordinary words after a title stay: "' . $text . '"', $t);
}
$t = rh_ai_scrub_outbound($f3, 65, 'mr and mrs zgambo');
check((bool)preg_match('/^mr and mrs \[GUEST_\d+\]$/', $t), '"mr and mrs zgambo": the name after the second title is caught', $t);
// a token the model wrote without brackets is never read as a name after a title (stored canonical, shown as the name)
$seed(68, ['[STAFF_2]' => 'Mary Mwale', '[GUEST_1]' => 'John Baluwa']);
$t = rh_ai_scrub_outbound($db, 68, "Mr STAFF_2 is on shift; Mr GUEST_1's stay is 3 nights");
check($t === "Mr [STAFF_2] is on shift; Mr [GUEST_1]'s stay is 3 nights", 'a bare token after a title stays that token (canonical)', $t);
check(rh_ai_vault_show($db, 68, $t) === "Mr Mary Mwale is on shift; Mr John Baluwa's stay is 3 nights", 'and shows the real names');
// a known name typed in lower case anywhere; guide/lookup text keeps ordinary words
$people(['grace hall' => 'GUEST'], ['grace' => 'GUEST', 'hall' => 'GUEST', 'baluwa' => 'GUEST', 'mwale' => 'STAFF']);
rh_ai_vault_forget();
$t = rh_ai_scrub_outbound($f3, 66, 'is baluwa in house and is mwale on shift', true);
check((bool)preg_match('/^is \[GUEST_\d+\] in house and is \[STAFF_\d+\] on shift$/', $t), 'typed: a known guest and staff name in lower case are tokenised (with their kind)', $t);
$t = rh_ai_scrub_outbound($f3, 67, 'The conference hall has a grace period of 15 minutes', false);
check($t === 'The conference hall has a grace period of 15 minutes', 'lookup and guide text: ordinary words that are also a name stay readable', $t);
$GLOBALS['__rh_ai_people'] = null;
unset($GLOBALS['__rh_ai_people']);

// 8. Cards and lookups refuse a name this chat no longer holds
check(rh_ai_vault_unresolved_in(['guest_name' => '[GUEST_1]']) && rh_ai_vault_unresolved_in(['x' => ['y' => 'For (guest) tonight']]) && rh_ai_vault_unresolved_in('\\[GUEST_1\\]')
    && rh_ai_vault_unresolved_in('GUEST_1') && rh_ai_vault_unresolved_in('(Staff)'), 'a raw token (any form) or a plain word is unresolved');
check(!rh_ai_vault_unresolved_in(['guest_name' => 'John Baluwa', 'nights' => 3, 'ref' => 'RBH2026000001', 'note' => 'Late arrival [after 10pm]']) && !rh_ai_vault_unresolved_in(''), 'real values are not');
$f->vaultRows = [['kind' => 'GUEST', 'num' => 1, 'value_text' => '', 'value_hash' => ''], ['kind' => 'GUEST', 'num' => 2, 'value_text' => 'phiri', 'value_hash' => rh_ai_vault_hash('GUEST', 'phiri')]];
rh_ai_vault_forget(60);
$a = rh_ai_vault_resolve_args($f, 60, ['guest_name' => '[GUEST_1]', 'other' => '\\[GUEST_2\\]', 'list' => ['GUEST_2']]);
check($a === ['guest_name' => '[GUEST_1]', 'other' => 'phiri', 'list' => ['phiri']], 'arguments: every written form resolves; a cleared one stays a token (never "" and never another name)', json_encode($a));
check(rh_ai_vault_unresolved_in($a) && !rh_ai_vault_unresolved_in(['other' => $a['other']]), 'so the card is refused on the cleared one only');
check(strpos(rh_ai_vault_unresolved_msg(), 'name the guest again') !== false, 'the refusal asks staff to name the guest again');
$src = static fn(string $p) => (string)file_get_contents($root . '/' . $p);
$act = $src('includes/ai/ai-actions.php');
$pp = strpos($act, 'function rh_ai_propose(');
$pc = strpos($act, 'function rh_ai_confirm(');
check($pp !== false && ($u = strpos($act, 'rh_ai_vault_unresolved_in($args)', $pp)) !== false && $u < strpos($act, 'rh_ai_tool_validate(', $pp) && $u < strpos($act, 'if ($fromModel)', $pp) + 200,
    'propose refuses an unresolved argument on every path (model, guided, tap) before anything is checked or stored');
check($pc !== false && ($u = strpos($act, 'rh_ai_vault_unresolved_in($args)', $pc)) !== false && $u < strpos($act, 'rh_ai_action_preview(', $pc), 'confirm refuses it too, after the card\'s edits');
$tl = $src('includes/ai/ai-tools.php');
check(($r1 = strpos($tl, 'rh_ai_vault_unresolved_in($args)')) !== false && $r1 < strpos($tl, 'rh_ai_tool_validate($def'), 'a lookup is never run for a cleared name (no unverified "no booking")');

// 9. No bare rehydrate reaches the screen
$gd = $src('includes/ai/ai-guided.php');
check(strpos($gd, "rh_ai_vault_show(\$db, \$conv, \$g['offer']['value'], 'guided', false)") !== false && strpos($gd, "\$g['offer']['value'] = rh_ai_vault_rehydrate(") === false, 'the guided offer goes through the display path (dropped when it holds a cleared name)');
check(strpos($src('admin/api/ai-chat.php'), 'rh_ai_vault_rehydrate(') === false, 'chat titles go through the display path');
$bare = [];
foreach (glob($root . '/includes/ai/*.php') as $p) {
    if (basename($p) !== 'ai-vault.php') {
        $bare[basename($p)] = substr_count((string)file_get_contents($p), 'rh_ai_vault_rehydrate(');
    }
}
$bare = array_filter($bare);
check($bare === ['ai-desk.php' => 1, 'ai-focus.php' => 2, 'ai-guided.php' => 1], 'the bare calls left only compare or check values, never show them', json_encode($bare));
check(strpos($src('includes/ai/ai-desk.php'), 'if (rh_ai_vault_unresolved_in($q))') !== false, 'the absence recheck never searches or keeps a "no booking" for a cleared name');

// 10. One incident per reply (reply text + chips + cards together)
file_put_contents($logFile, '');
rh_ai_vault_forget();
$seed(70, []);
rh_ai_vault_show($db, 70, 'Booked for [GUEST_1]', 'reply:model');
rh_ai_vault_show($db, 70, 'Show [GUEST_1]\'s bill', 'chip:model');
rh_ai_vault_show($db, 70, 'Call [STAFF_2]', 'item');
rh_ai_vault_show($db, 70, 'Old [GUEST_1]', 'load', false);
rh_ai_vault_show_flush();
$logged = (string)file_get_contents($logFile);
check(substr_count($logged, 'privacy token(s) with no stored value') === 1 && strpos($logged, 'GUEST x2, STAFF x1') !== false && strpos($logged, 'reply:model, chip:model, item') !== false,
    'one log line for the reply: kinds and counts added up, every place listed', $logged);
rh_ai_vault_show_flush();
check(substr_count((string)file_get_contents($logFile), 'privacy token(s)') === 1, 'a second flush logs nothing new');
$ag = $src('includes/ai/ai-agents.php');
check(substr_count($ag, 'rh_ai_vault_show_flush();') === 2, 'the chat reply and the briefing each flush once');

@unlink($logFile);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
