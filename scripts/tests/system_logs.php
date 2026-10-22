<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * System Logs: the "Needs attention" summary and the grouping of repeated events
 * (admin/includes/system-log-attention.php, admin/system-logs.php).
 * Usage (from the repo root): php scripts/tests/system_logs.php
 * The helper checks work on plain arrays (no database). The page checks run in
 * scripts/tests/page_harness.php: rows are seeded and rolled back, nothing is left behind.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/admin/includes/system-log-attention.php';

// ---- Normalising and classifying ----
check(rh_log_normalize_message('Ingredient #60 went negative by 0.0010 units') === rh_log_normalize_message('Ingredient #22 went negative by 0.0400 units'), 'messages that differ only by numbers normalise the same');
check(rh_log_normalize_message('failed: a@b.com x') === rh_log_normalize_message('failed: zz@example.org x'), 'email addresses are ignored when grouping');
check(rh_log_is_validation_refusal('whatsapp_settings', 'error', 'Failed saving WhatsApp settings'), 'old WhatsApp save refusal is recognised');
check(rh_log_is_validation_refusal('facebook_settings', 'error', 'Failed saving Facebook settings'), 'old Facebook save refusal is recognised');
check(!rh_log_is_validation_refusal('whatsapp_settings', 'error', 'Failed saving WhatsApp settings (database error)'), 'a database failure saving settings is a real error');
check(rh_log_is_validation_refusal('create-booking', 'error', 'Booking creation failed: Valid email is required.'), 'booking refusal (email required) is not a fault');
check(!rh_log_is_validation_refusal('create-booking', 'error', 'Booking creation failed: SQLSTATE[HY000]: General error'), 'booking SQL failure is a real error');
check(!rh_log_is_validation_refusal('api', 'warning', 'Failed saving WhatsApp settings'), 'only error/critical rows can be refusals');
check(rh_log_classify_event('api', 'warning', 'API authentication failed') === 'api_auth', 'API auth failure classified');
check(rh_log_classify_event('stock', 'warning', 'Ingredient #4 went negative by 1.0000 units') === 'stock_negative', 'negative stock classified');
check(rh_log_classify_event('stock', 'warning', 'Oversell at KDS (order #1): Rice short 2 kg') === 'stock_negative', 'oversell classified');
check(rh_log_classify_event('credit-notes', 'error', 'Credit note email failed: SMTP Error: x') === 'email_failed', 'failed email classified (not a generic error)');
check(rh_log_classify_event('x', 'error', 'Something broke') === 'error', 'a real error is classified as error');
check(rh_log_classify_event('whatsapp_settings', 'error', 'Failed saving WhatsApp settings') === null, 'a refused entry is not an error');
check(rh_log_classify_event('x', 'info', 'Something broke') === null, 'info rows never need attention');
check(rh_log_classify_event('api_keys', 'warning', 'API key deleted') === null, 'ordinary warnings are not attention items');

// ---- Burst detection (3 or more inside one hour) ----
$t0 = strtotime('2026-10-20 10:00:00');
check(rh_log_burst([$t0, $t0 + 600, $t0 + 3000]), '3 tries inside an hour is a burst');
check(!rh_log_burst([$t0, $t0 + 600, $t0 + 3700]), '3 tries spread over more than an hour is not a burst');
check(!rh_log_burst([$t0, $t0 + 60]), '2 tries is not a burst');
check(rh_log_burst([$t0 + 7200, $t0, $t0 + 7300, $t0 + 7400]), 'order of the times does not matter');

// ---- Building the summary ----
$empty = rh_log_attention_build([], [], [], []);
check($empty === [], 'nothing to report gives an empty list (page shows All clear)');

$events = [];
for ($i = 0; $i < 5; $i++) {
    $events[] = ['source' => 'whatsapp_settings', 'level' => 'error', 'message' => 'Failed saving WhatsApp settings', 'created_at' => '2026-10-18 15:0' . $i . ':00'];
}
$events[] = ['source' => 'create-booking', 'level' => 'error', 'message' => 'Booking creation failed: All rooms of this type are unavailable for the selected dates', 'created_at' => '2026-10-22 12:11:55'];
check(rh_log_attention_build($events, [], [], []) === [], 'refused entries logged as errors do not appear in Needs attention');

$events = [
    ['source' => 'stock', 'level' => 'warning', 'message' => 'Ingredient #60 went negative by 0.0010 units — stock needs reconciliation.', 'created_at' => '2026-10-19 11:51:59'],
    ['source' => 'stock', 'level' => 'warning', 'message' => 'Ingredient #22 went negative by 0.0400 units — stock needs reconciliation.', 'created_at' => '2026-10-19 11:52:59'],
    ['source' => 'stock', 'level' => 'warning', 'message' => 'Ingredient #60 went negative by 0.0020 units — stock needs reconciliation.', 'created_at' => '2026-10-19 12:05:12'],
    ['source' => 'api', 'level' => 'warning', 'message' => 'API authentication failed', 'created_at' => '2026-10-21 21:44:50'],
    ['source' => 'api', 'level' => 'warning', 'message' => 'API authentication failed', 'created_at' => '2026-10-21 21:40:50'],
    ['source' => 'credit-notes', 'level' => 'error', 'message' => 'Credit note email failed: SMTP Error: recipients failed: t@t.t', 'created_at' => '2026-10-17 22:35:00'],
    ['source' => 'credit-notes', 'level' => 'error', 'message' => 'Credit note email failed: SMTP Error: recipients failed: e2e-a@example.com', 'created_at' => '2026-10-18 02:05:04'],
    ['source' => 'pos', 'level' => 'critical', 'message' => 'Till write failed', 'created_at' => '2026-10-22 09:00:00'],
    ['source' => 'api_keys', 'level' => 'warning', 'message' => 'API key deleted', 'created_at' => '2026-10-18 13:23:22'],
];
$items = rh_log_attention_build($events, [], [], [['recipient' => 'g@x.com', 'error' => 'refused', 'at' => '2026-10-23 08:00:00']]);
$byKey = [];
foreach ($items as $it) { $byKey[$it['key']] = $it; }
check(isset($byKey['stock_negative']) && $byKey['stock_negative']['count'] === 3, 'stock events counted', json_encode($byKey['stock_negative'] ?? null));
check(strpos($byKey['stock_negative']['meaning'] ?? '', '2 ingredients') !== false, 'stock line says how many ingredients are affected');
check(($byKey['stock_negative']['link']['page'] ?? '') === 'stock-count.php', 'stock line links to Stock Count');
check(isset($byKey['api_auth']) && $byKey['api_auth']['count'] === 2 && $byKey['api_auth']['last_seen'] === '2026-10-21 21:44:50', 'API failures counted with the latest time');
check(isset($byKey['email_failed']) && $byKey['email_failed']['count'] === 3, 'failed emails: two from the event log plus one automated email', (string)($byKey['email_failed']['count'] ?? ''));
check(isset($byKey['error']) && $byKey['error']['count'] === 1 && $byKey['error']['level'] === 'danger', 'a critical event is a real error');
check(!isset($byKey['offline_fail']) && !isset($byKey['failed_login']), 'lines with nothing to report are absent');
check($items[0]['level'] === 'danger', 'most serious lines come first');
check(count($items) === 4, 'ordinary warnings (API key deleted) add no line', (string)count($items));

// Failed sign-ins
$one = fn($ip, $at, $d = 'Wrong password (attempt 1/5)', $a = 'login_failed') => ['username' => 'x', 'action' => $a, 'details' => $d, 'ip_address' => $ip, 'created_at' => $at];
$logins = [$one('1.1.1.1', '2026-10-20 10:00:00'), $one('2.2.2.2', '2026-10-20 12:00:00', 'Unknown username')];
$it = rh_log_attention_build([], $logins, [], [])[0] ?? null;
check($it && $it['key'] === 'failed_login' && $it['count'] === 2 && !$it['highlight'] && $it['level'] === 'warning', 'two scattered failed sign-ins are listed but not highlighted');
$logins = [$one('9.9.9.9', '2026-10-20 10:00:00'), $one('9.9.9.9', '2026-10-20 10:20:00', 'Unknown username'), $one('9.9.9.9', '2026-10-20 10:40:00', 'Unknown username'), $one('3.3.3.3', '2026-10-20 11:00:00')];
$it = rh_log_attention_build([], $logins, [], [])[0] ?? null;
check($it && $it['highlight'] && $it['level'] === 'danger' && strpos(implode(' ', $it['examples']), '9.9.9.9') !== false, '3 failures from one address inside an hour are highlighted with the address');
check($it && strpos($it['examples'][0], '2 wrong password') !== false && strpos($it['examples'][0], '2 unknown username') !== false, 'wrong password and unknown username are counted apart', (string)($it['examples'][0] ?? ''));

// Offline sync
$it = rh_log_attention_build([], [], [['endpoint' => 'api/x', 'action' => 'pos_sale', 'response_status' => 422, 'response_summary' => 'Refused', 'replayed_at' => '2026-10-21 10:00:00']], [])[0] ?? null;
check($it && $it['key'] === 'offline_fail' && $it['count'] === 1 && $it['link']['page'] === 'offline-log.php', 'an offline sync failure is listed and links to the Offline Log');

// ---- Grouping repeats ----
$rows = [
    ['id' => 5, 'source' => 'stock', 'level' => 'warning', 'message' => 'Ingredient #60 went negative by 0.0020 units', 'created_at' => '2026-10-19 12:05:12'],
    ['id' => 4, 'source' => 'auth', 'level' => 'warning', 'message' => 'Admin login failed — wrong password', 'created_at' => '2026-10-19 11:00:00'],
    ['id' => 3, 'source' => 'stock', 'level' => 'warning', 'message' => 'Ingredient #22 went negative by 0.0400 units', 'created_at' => '2026-10-19 10:00:00'],
    ['id' => 2, 'source' => 'stock', 'level' => 'error', 'message' => 'Ingredient #22 went negative by 0.0400 units', 'created_at' => '2026-10-18 10:00:00'],
];
$g = rh_log_group_events($rows);
check(count($g) === 3, 'repeats fold into groups (level is part of the group)', (string)count($g));
check($g[0]['repeat_count'] === 2 && $g[0]['id'] === 5 && $g[0]['first_seen'] === '2026-10-19 10:00:00' && $g[0]['last_seen'] === '2026-10-19 12:05:12', 'a group keeps the newest row and its first and last time');
check($g[1]['repeat_count'] === 1 && $g[2]['repeat_count'] === 1, 'single events stay single');

// ---- The page, through the rolled-back harness ----
$h = $root . '/scripts/tests/page_harness.php';
function run_page(string $page, array $cfg): array {
    global $h;
    $cmd = 'php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1';
    $out = shell_exec($cmd);
    if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -300), 'rolled_back' => false];
}
$clean = function (array $r): bool { return !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']); };
$seed = [
    "INSERT INTO system_event_log (source, level, message, created_at) VALUES ('zz_logtest', 'error', 'ZZ real failure alpha', NOW())",
    "INSERT INTO system_event_log (source, level, message, created_at) VALUES ('zz_logtest', 'warning', 'ZZ repeat 1', NOW())",
    "INSERT INTO system_event_log (source, level, message, created_at) VALUES ('zz_logtest', 'warning', 'ZZ repeat 2', NOW())",
    "INSERT INTO system_event_log (source, level, message, created_at) VALUES ('zz_logtest', 'info', 'ZZ plain info', NOW())",
    "INSERT INTO system_event_log (source, level, message, created_at) VALUES ('whatsapp_settings', 'error', 'Failed saving WhatsApp settings', NOW())",
];
$r = run_page('admin/system-logs.php', ['method' => 'GET', 'get' => [], 'setup' => $seed, 'grep' => ['Needs attention', 'Errors the system logged', 'ZZ real failure alpha', 'ZZ plain info', 'x2 &middot; last']]);
check(!isset($r['error']), 'system-logs page renders', json_encode($r));
check(!empty($r['found']['Needs attention']), 'page shows the Needs attention panel');
check(!empty($r['found']['Errors the system logged']), 'a real error shows as a Needs attention line');
check(!empty($r['found']['ZZ real failure alpha']), 'default view lists the error in the stream');
check(empty($r['found']['ZZ plain info']), 'default view (warnings and errors) hides info rows');
check(!empty($r['found']['x2 &middot; last']), 'identical warnings are grouped as "x2 - last ..." in the stream');
check($clean($r), 'page run rolled back', json_encode($r));

$r = run_page('admin/system-logs.php', ['method' => 'GET', 'get' => ['level' => 'all', 'group' => '0'], 'setup' => $seed, 'grep' => ['ZZ plain info', 'ZZ repeat 1', 'ZZ repeat 2', 'x2 &middot; last']]);
check(!empty($r['found']['ZZ plain info']) && !empty($r['found']['ZZ repeat 1']) && !empty($r['found']['ZZ repeat 2']), 'Everything, ungrouped lists every row');
check(empty($r['found']['x2 &middot; last']), 'ungrouped view has no repeat counts');
check($clean($r), 'second page run rolled back', json_encode($r));

// ---- Page-view row: only the page's own reload (r=1) is skipped ----
$viewCount = "SELECT COUNT(*) AS n FROM system_event_log WHERE source = 'system_logs' AND message = 'System logs viewed' AND context_json LIKE '%\"limit\":137%'";
foreach ([
    ['first load', ['limit' => '137'], 1],
    ['first load with auto-refresh ticked (?auto=1)', ['limit' => '137', 'auto' => '1'], 1],
    ['the page\'s own reload (?auto=1&r=1)', ['limit' => '137', 'auto' => '1', 'r' => '1'], 0],
    ['?r=1 typed without auto-refresh', ['limit' => '137', 'r' => '1'], 1],
] as [$label, $get, $want]) {
    $r = run_page('admin/system-logs.php', ['method' => 'GET', 'get' => $get, 'verify' => [$viewCount]]);
    $n = (int)($r['verify'][0][0]['n'] ?? -1);
    check($n === $want, "view row: $label logs $want", "got $n " . json_encode($r['verify'] ?? $r));
    check($clean($r), "view-row run rolled back ($label)");
}

// ---- Refused vs failed settings saves log at the right level ----
$lvl = ["SELECT level FROM system_event_log WHERE source = 'whatsapp_settings' AND message LIKE 'WhatsApp settings not saved%' ORDER BY id DESC LIMIT 1"];
$r = run_page('admin/whatsapp-settings.php', ['post' => ['whatsapp_number' => 'hello', 'whatsapp_api_token' => '', 'whatsapp_phone_id' => '', 'whatsapp_business_id' => '', 'whatsapp_admin_numbers' => '', 'whatsapp_confirmed_template' => 'booking_confirmed', 'whatsapp_cancelled_template' => 'booking_cancelled'], 'verify' => $lvl]);
check(($r['verify'][0][0]['level'] ?? '') === 'warning', 'a refused WhatsApp number is logged as a warning, not an error', json_encode($r['verify'] ?? $r));
check($clean($r), 'refused WhatsApp save rolled back');
$lvl = ["SELECT level FROM system_event_log WHERE source = 'facebook_settings' AND message LIKE 'Facebook settings not saved%' ORDER BY id DESC LIMIT 1"];
$r = run_page('admin/facebook-settings.php', ['post' => ['facebook_page_id' => '12ab', 'facebook_page_name' => 'ZZ', 'facebook_default_hashtags' => '', 'facebook_page_access_token' => ''], 'verify' => $lvl]);
check(($r['verify'][0][0]['level'] ?? '') === 'warning', 'a refused Facebook Page ID is logged as a warning, not an error', json_encode($r['verify'] ?? $r));
check($clean($r), 'refused Facebook save rolled back');
foreach (['admin/whatsapp-settings.php', 'admin/facebook-settings.php'] as $pf) {
    $src = file_get_contents($root . '/' . $pf);
    check(strpos($src, '$e instanceof PDOException || !($e instanceof RuntimeException || $e instanceof InvalidArgumentException)') !== false, "$pf: only RuntimeException/InvalidArgumentException refusals are warnings, anything else stays error");
}
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
