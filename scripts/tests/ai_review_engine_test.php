<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * The in-page Decisions panels, Phase 1 engine (.claude/AI_REVIEW_PANELS_PARKED.md): the gate prints nothing when off
 * (AI off / no permission / module off: empty string, no engine loaded, no cache read), scoring and the impact
 * threshold, the fixed checks on this database, the cache, the one wording call (names never leave, a reply that adds
 * a figure or sounds artificial is ignored), the no-model path (silent), the proof log and Today's brief.
 *
 * Usage (from the repo root): php scripts/tests/ai_review_engine_test.php
 * No network: every model call goes through a fake transport. Live DB: every check runs inside a transaction that is
 * rolled back (the tables come from migration 075, never from this file).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0; $skip = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function skip(string $l): void { global $skip; $skip++; echo "SKIP $l\n"; }

require_once $root . '/config/database.php';
require_once $root . '/config/security.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/includes/ai/ai-core.php';
require_once $root . '/admin/includes/review-panel.php';

/** A connection that records every statement (to prove what a gated-off render reads). */
class RhDecCountPdo extends PDO
{
    public $sql = [];
    #[\ReturnTypeWillChange]
    public function query($statement, $mode = null, ...$args)
    {
        $this->sql[] = (string)$statement;
        return $mode === null ? parent::query($statement) : parent::query($statement, $mode, ...$args);
    }
    #[\ReturnTypeWillChange]
    public function prepare($statement, $options = []) { $this->sql[] = (string)$statement; return parent::prepare($statement, $options); }
    #[\ReturnTypeWillChange]
    public function exec($statement) { $this->sql[] = (string)$statement; return parent::exec($statement); }
}
$conn = static fn(): RhDecCountPdo => new RhDecCountPdo('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$engineFile = realpath($root . '/includes/ai/ai-decisions.php');
$engineLoaded = static fn(): bool => in_array($engineFile, array_map('realpath', get_included_files()), true);
$touchesCache = static fn(array $sql): bool => (bool)preg_grep('/ai_review_/i', $sql);
$onSql = ["UPDATE site_settings SET setting_value = '1' WHERE setting_key = 'ai_enabled'", "UPDATE site_settings SET setting_value = '' WHERE setting_key = 'ai_paused_reason'",
    "UPDATE enabled_modules SET is_enabled = 1 WHERE module_key = 'ai_assistant'"];
$adm = $pdo->query("SELECT id, role, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adm) {
    echo "No active admin account: nothing can be checked.\n";
    exit(1);
}
$adm['id'] = (int)$adm['id'];
$_SESSION = ['admin_user_id' => $adm['id'], 'admin_role' => 'admin', 'admin_username' => (string)$adm['username'], 'admin_full_name' => (string)$adm['full_name']];
$mkUser = static function (PDO $db, string $tag, array $grant): int {
    $db->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 'receptionist', 1)")
        ->execute(['dectest_' . $tag . bin2hex(random_bytes(2)), 'dectest.' . $tag . '@example.com', password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), 'Dectest ' . ucfirst($tag)]);
    $id = (int)$db->lastInsertId();
    $g = $db->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)");
    foreach (array_keys(getAllPermissions()) as $k) {
        $g->execute([$id, $k, in_array($k, $grant, true) ? 1 : 0]);
    }
    return $id;
};

// ════ 1. Gate off: nothing at all ════
$pdoKeep = $pdo;
$c = $conn();
$c->beginTransaction();
try {
    $GLOBALS['pdo'] = $c;
    $pdo = $c;
    $c->exec("UPDATE site_settings SET setting_value = '0' WHERE setting_key = 'ai_enabled'");
    $c->sql = [];
    ob_start();
    $h = rh_review_panel('stock');
    $printed = (string)ob_get_clean();
    check($h === '' && $printed === '', 'AI off: the panel helper returns an empty string and prints nothing');
    check(count($c->sql) <= 1 && !$touchesCache($c->sql), 'AI off: at most the one master-switch query, no Decisions table read', json_encode($c->sql));
    check(!$engineLoaded(), 'AI off: the engine is not even loaded');
    foreach ($onSql as $q) { $c->exec($q); }
    if (!rh_ai_enabled($c)) {
        skip('AI could not be switched on inside the test transaction: permission / module cases skipped');
    } else {
        $noUse = $mkUser($c, 'nouse', ['dashboard', 'stock_dashboard', 'stock_management', 'accounting', 'bookings']);
        $noArea = $mkUser($c, 'noarea', ['dashboard', 'ai_assistant_use', 'bookings']);
        foreach ([[$noUse, 'stock', 'no Assistant use permission'], [$noArea, 'stock', 'no stock permission'], [$noArea, 'accounting', 'no accounting permission']] as [$u, $area, $why]) {
            $_SESSION['admin_user_id'] = $u;
            $_SESSION['admin_role'] = 'receptionist';
            $c->sql = [];
            ob_start();
            $h = rh_review_panel($area);
            $printed = (string)ob_get_clean();
            check($h === '' && $printed === '' && !$touchesCache($c->sql) && !$engineLoaded(), "gate off ($why, $area): empty string, nothing printed, no Decisions read, engine not loaded", json_encode([$h, $c->sql]));
        }
        $_SESSION['admin_user_id'] = $adm['id'];
        $_SESSION['admin_role'] = 'admin';
        $GLOBALS['__rh_ai_module_override'] = ['stock' => false, 'finance' => false, 'bookings' => false];
        foreach (['stock', 'accounting', 'bookings'] as $area) {
            $c->sql = [];
            $h = rh_review_panel($area);
            check($h === '' && !$touchesCache($c->sql) && !$engineLoaded(), "gate off (module off, $area): empty string, no Decisions read, engine not loaded");
        }
        unset($GLOBALS['__rh_ai_module_override']);
        check(rh_review_panel('nonsense') === '' && !$engineLoaded(), 'an unknown area gives an empty string');
    }
} finally {
    $c->rollBack();
    $GLOBALS['pdo'] = $pdoKeep;
    $pdo = $pdoKeep;
    $_SESSION['admin_user_id'] = $adm['id'];
    $_SESSION['admin_role'] = 'admin';
}

// ════ 2. The engine ════
require_once $root . '/includes/ai/ai-decisions.php';
require_once $root . '/includes/ai/ai-selfheal.php';
if (!rh_dec_ready($pdo, true)) {
    skip('migration 075 has not run on this database: engine checks skipped');
    echo "\n$pass passed, $fail failed, $skip skipped\n";
    exit($fail > 0 ? 1 : 0);
}
check(rh_ai_hard_limit_problems() === [], 'the hard-limit self-check passes (the new endpoint and panel check the master switch)', json_encode(rh_ai_hard_limit_problems()));

// Settings: every size is a setting with its default in the registry and seeded by migration 075 (no numbers in the code)
$mig = (string)file_get_contents($root . '/admin/migrations/075_ai_decisions.php');
$keys = ['ai_review_impact_min_pct', 'ai_review_base_days', 'ai_review_cards_max', 'ai_review_history_min_days', 'ai_review_writer_enabled', 'ai_review_writer_tokens', 'ai_review_refresh_minutes', 'ai_review_keep_days'];
$miss = array_values(array_filter($keys, static fn($k) => !isset(rh_ai_setting_defaults()[$k]) || strpos($mig, "'" . $k . "'") === false));
check(!$miss, 'every Decisions size is a setting (registry default + seeded by migration 075)', implode(', ', $miss));
$engSrc = (string)file_get_contents($root . '/includes/ai/ai-decisions.php');
check(!preg_match('/gemini|claude-|gpt-\d|deepseek|grok|per_mtok\'\]\s*\*\s*\d|max_tokens\'\s*=>\s*\d/i', $engSrc), 'no model name, price or token limit written in the engine');
preg_match_all('/(INSERT INTO|(?<!KEY )UPDATE|DELETE FROM)\s+`?([a-z_]+)/i', $engSrc, $wm);
$writes = array_values(array_unique(array_filter($wm[2], static fn($t) => strpos($t, 'ai_review_') !== 0)));
check(!$writes, 'the engine writes only to the ai_review_* tables', implode(', ', $writes));

// Scoring and the threshold (fixture cards, no database)
$base = ['ok' => true, 'amount' => 100000.0, 'days' => 30];
$fx = static fn(string $k, ?float $amt, float $u = 1.0, float $cf = 1.0): array => rh_dec_card(['key' => $k, 'decision' => 'Fixture ' . $k, 'at_stake' => $amt, 'urgency' => $u, 'confidence' => $cf]);
$s = rh_dec_score($fx('a', 5000, 0.8, 0.5), $base);
check(abs($s['share_pct'] - 5.0) < 0.001 && abs($s['score'] - 0.05 * 0.8 * 0.5) < 0.000001, 'score = impact (share of takings) x urgency x confidence', json_encode($s));
$z = rh_dec_score($fx('z', 5000), ['ok' => true, 'amount' => 0.0, 'days' => 30]);
check($z['share_pct'] === null && $z['score'] > 0 && $z['score'] < 0.001, 'no money collected: no share is invented (null), no division by zero, ranked after real shares');
$pdo->beginTransaction();
try {
    $set = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $set->execute(['ai_review_impact_min_pct', '1']);
    $set->execute(['ai_review_cards_max', '2']);
    rh_ai_settings($pdo, true);
    $sel = rh_dec_select([rh_dec_score($fx('big', 20000), $base), rh_dec_score($fx('mid', 10000), $base), rh_dec_score($fx('also', 3000), $base), rh_dec_score($fx('small', 500), $base), rh_dec_score($fx('noval', null), $base)]);
    check(array_column($sel['main'], 'key') === ['big', 'mid'], 'the top cards by score are shown, at most ai_review_cards_max', json_encode(array_column($sel['main'], 'key')));
    check(array_column($sel['more'], 'key') === ['also', 'small', 'noval'], 'cards over the cap, under the threshold (0.5% < 1%) or with no money figure go under More', json_encode(array_column($sel['more'], 'key')));
    $set->execute(['ai_review_impact_min_pct', '0.4']);
    $set->execute(['ai_review_cards_max', '8']);
    rh_ai_settings($pdo, true);
    $sel = rh_dec_select([rh_dec_score($fx('small', 500), $base)]);
    check(array_column($sel['main'], 'key') === ['small'], 'the threshold is the setting (0.4%: the 0.5% card now shows)');
} finally {
    $pdo->rollBack();
    rh_ai_settings($pdo, true);
}

// The fixed checks on this database: each runs, and the figures are the read tools' own
$ctx = rh_dec_context($pdo);
check(!empty($ctx['base']['ok']), 'the base (money collected in the base period) is read with the Accounting dashboard rule');
foreach (array_keys(rh_review_areas()) as $area) {
    $b = rh_dec_build($pdo, $area, $ctx);
    $bad = array_values(array_filter($b['checks'], static fn($k) => empty($k['ok'])));
    check(!$bad && count($b['checks']) === count(rh_dec_finders()[$area]), "$area: every fixed check ran on this database", json_encode($bad));
    foreach (array_merge($b['main'], $b['more']) as $card) {
        $okShape = $card['key'] !== '' && $card['decision'] !== '' && in_array((int)$card['tier'], [1, 2, 3], true) && count($card['options']) >= 1 && array_key_exists('share_pct', $card) && $card['area'] === $area;
        check($okShape, "$area: card {$card['key']} has decision, options, tier and share");
        check(!preg_match('/\b(AI|smart|insight|assistant|generated|powered|MRA)\b/i', $card['decision'] . ' ' . $card['why_now'] . ' ' . $card['recommendation']), "$area: card {$card['key']} wording is plain");
    }
}
$ml = $ctx['run']('money_leaks', ['from' => $ctx['from'], 'to' => $ctx['to']]);
$acc = rh_dec_build($pdo, 'accounting', $ctx);
$coll = array_values(array_filter(array_merge($acc['main'], $acc['more']), static fn($x) => $x['key'] === 'acc_collections'))[0] ?? null;
if ($ml !== null && isset($ml['still_owed']['left_or_overdue_total']) && (float)$ml['still_owed']['left_or_overdue_total'] > BALANCE_TOLERANCE) {
    check($coll !== null && abs((float)$coll['at_stake'] - (float)$ml['still_owed']['left_or_overdue_total']) < BALANCE_TOLERANCE, 'money owed card: at stake equals the money-owed figure of the shared read (same rule as the Payments screen)');
} else {
    check($coll === null, 'nothing owed after departure: no money-owed card (and the check still counts as run)');
}

// ════ 3. Cache, the wording call, the no-model path ════
$GLOBALS['dec_calls'] = [];
$GLOBALS['dec_reply'] = null;
putenv('RH_AI_FAKE_PROVIDER=dectest'); // a key-less fake: the transport below answers, nothing goes on the network
rh_ai_set_transport(static function ($method, $url, $headers, $body, $timeout) {
    $GLOBALS['dec_calls'][] = ['url' => $url, 'body' => (string)$body];
    if (($GLOBALS['dec_mode'] ?? '') === 'down') {
        return ['http' => 0, 'body' => '', 'error' => 'Could not resolve host'];
    }
    $text = is_callable($GLOBALS['dec_reply']) ? (string)($GLOBALS['dec_reply'])((string)$body) : '{}';
    return ['http' => 200, 'body' => json_encode(rh_ai_fake_body('google', ['text' => $text, 'usage' => ['in' => 80, 'out' => 40]], 'test-fast-model'))];
});
$guestName = 'Zebedee Qwertyson';
$staffName = 'Dectest Mvulastaff';
$GLOBALS['dec_fx_amt'] = 0.0;
$GLOBALS['__rh_dec_extra_finders'] = ['stock' => ['tst_fixture' => ['label' => 'Fixture check', 'requires' => [], 'fn' => static function (PDO $db, array $ctx) use ($guestName, $staffName): array {
    $amt = (float)$GLOBALS['dec_fx_amt'];
    return ['ok' => true, 'total' => 3, 'cards' => [rh_dec_card([
        'key' => 'tst_fixture', 'covers' => ['wastage'], 'decision' => 'Fixture decision worth ' . rh_dec_fmt($ctx['cur'], $amt),
        'why_now' => 'Raised by ' . $staffName . ' on 3 counts.', 'at_stake' => $amt, 'at_stake_note' => 'Fixture amount.', 'urgency' => 1.0, 'confidence' => 0.9,
        'evidence' => ['total' => 3, 'total_label' => 'fixture rows', 'rows' => [['label' => 'Fixture row', 'guest_name' => $guestName, 'staff_name' => $staffName, 'amount' => $amt]]],
        'options' => [rh_dec_option('Fixture option', 'Fixture effect.', $amt, null, 0.0, 0.9, 0, 2)], 'recommend' => 0, 'recommendation' => 'Take the fixture option.',
        'tier' => 2, 'link' => ['stock-dashboard.php', 'Open Stock'],
    ])]];
}]]];
$pdo->beginTransaction();
try {
    foreach ($onSql as $q) { $pdo->exec($q); }
    $set = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach (['ai_cloud_enabled' => '1', 'ai_review_writer_enabled' => '1', 'ai_review_impact_min_pct' => '1', 'ai_review_cards_max' => '8'] as $k => $v) { $set->execute([$k, $v]); }
    rh_ai_settings($pdo, true);
    $pdo->exec("DELETE FROM ai_review_cache WHERE cache_day = CURDATE() AND area = 'stock'"); // rolled back below
    $pdo->exec("DELETE FROM ai_review_brief WHERE brief_day = CURDATE() AND user_id = " . $adm['id']);
    $base = rh_dec_base($pdo, rh_dec_num('ai_review_base_days', 7, 92));
    $GLOBALS['dec_fx_amt'] = max(1000.0, round((float)$base['amount'] * 0.5, 2)); // half the takings: always over the threshold

    $models = ['provider' => 'google', 'fast' => ['model_id' => 'test-fast-model', 'price_in_per_mtok' => null, 'price_out_per_mtok' => null]];
    $goodReply = static function (string $body): string {
        preg_match('/\[STAFF_\d+\]/', $body, $m);
        return json_encode(['order' => ['tst_fixture'], 'cards' => ['tst_fixture' => ['decision' => 'Decide on the fixture now', 'why_now' => 'It was raised by ' . ($m[0] ?? '') . ' on 3 counts.', 'recommendation' => 'Take the fixture option today.'],
            'not_a_card' => ['decision' => 'x', 'why_now' => 'y', 'recommendation' => 'z']]]);
    };
    $narrowBrief = static fn(): array => rh_dec_brief_merge($pdo, $adm, ['findings' => [['id' => 'wastage', 'kind' => 'money', 'title' => 'Stock wasted']]]);

    // Owner 2026-11-05: no model => NOTHING (no cards, no panel, no fixed wording, no note), and no call
    $GLOBALS['__rh_dec_models'] = ['provider' => ''];
    $r0 = rh_dec_refresh($pdo, 'stock', true, $adm['id'], true);
    $html0 = rh_review_panel('stock');
    check(!$r0['model_called'] && !$GLOBALS['dec_calls'] && rh_dec_for_viewer($pdo, $adm, 'stock') === null && $html0 === '', 'no model set up: the panel helper returns an empty string (no templated cards) and no call is made');
    check(rh_dec_brief($pdo, $adm, true) === null && array_column($narrowBrief()['findings'], 'id') === ['wastage'], 'no model set up: Today\'s brief has no decisions and the briefing is unchanged (no note)');

    // Model unreachable (the connection fails): nothing shown
    $GLOBALS['__rh_dec_models'] = $models;
    $GLOBALS['dec_mode'] = 'down';
    $rd = rh_dec_refresh($pdo, 'stock', true, $adm['id'], true);
    check($rd['model_called'] && count($GLOBALS['dec_calls']) >= 1 && rh_review_panel('stock') === '' && rh_dec_brief($pdo, $adm, true) === null,
        'model unreachable: the panel helper returns an empty string and the brief has no decisions', json_encode([$rd['status'], $rd['model_called'], count($GLOBALS['dec_calls']), strlen(rh_review_panel('stock')), rh_dec_brief($pdo, $adm, true) === null]));
    $nDown = count($GLOBALS['dec_calls']);
    rh_dec_refresh($pdo, 'stock', true, $adm['id'], false);
    check(count($GLOBALS['dec_calls']) === $nDown, 'model unreachable: pressing Refresh again within ai_review_refresh_minutes makes no new call');
    $GLOBALS['dec_mode'] = '';

    // An unreadable reply: nothing shown either
    $GLOBALS['dec_reply'] = static fn(string $b): string => 'not json at all';
    $rx = rh_dec_refresh($pdo, 'stock', true, $adm['id'], true);
    check($rx['model_called'] && $rx['cache'] !== null && $rx['cache']['wording'] === null && rh_review_panel('stock') === '', 'an unreadable reply: nothing is shown (never breaks, never a half review)');
    $GLOBALS['dec_calls'] = [];

    // The wording call: one call, names never leave, wording applied and names put back here
    $GLOBALS['dec_reply'] = $goodReply;
    $r1 = rh_dec_refresh($pdo, 'stock', true, $adm['id'], true);
    $body = (string)($GLOBALS['dec_calls'][0]['body'] ?? '');
    check($r1['model_called'] && count($GLOBALS['dec_calls']) === 1, 'figures not yet worded: ONE model call for the area');
    check($body !== '' && stripos($body, $guestName) === false && stripos($body, $staffName) === false && strpos($body, '[STAFF_') !== false && strpos($body, 'Fixture row') === false,
        'names never leave: no evidence rows sent, the staff name in the text went as a token');
    $v1 = rh_dec_for_viewer($pdo, $adm, 'stock');
    $fx1 = $v1 ? (array_values(array_filter($v1['main'], static fn($x) => $x['key'] === 'tst_fixture'))[0] ?? null) : null;
    check($fx1 !== null && $fx1['decision'] === 'Decide on the fixture now' && strpos($fx1['why_now'], $staffName) !== false && $v1['main'][0]['key'] === 'tst_fixture',
        'the wording is used (in the order given) and the name is put back on the server', json_encode($fx1['why_now'] ?? null));
    $c1 = rh_dec_cache_get($pdo, 'stock');
    check($c1 !== null && $c1['wording_hash'] === $c1['hash'] && !isset($c1['wording']['cards']['not_a_card']), 'the wording is stored with the figures\' hash; an unknown card in the reply is ignored');
    $usage = (int)$pdo->query("SELECT COALESCE(SUM(requests), 0) FROM ai_usage_daily WHERE day = CURDATE() AND model_id = 'test-fast-model'")->fetchColumn();
    check($usage >= 1, 'the call is counted in the usage like every model call');
    $html0 = rh_review_panel('stock');
    check($html0 !== '' && strpos($html0, 'Decide on the fixture now') !== false, 'a good review from today: the panel shows');

    // Invisible AI and helper lines on the rendered panel
    check(!preg_match('/\b(AI|smart|insights?|assistant|generated|powered by|thinking)\b/i', strip_tags($html0)) && !preg_match('/fa-(magic|wand|robot|brain|star|microchip|hat-wizard)|sparkle/i', $html0),
        'the rendered panel has no AI-looking words or icons');
    check(strpos($html0, '<h2 class="rh-dec__title"') !== false && strpos($html0, '>Decisions</h2>') !== false, 'the panel is titled plainly "Decisions"');
    $ths = substr_count($html0, '<th scope="col" class="no-sort">');
    $thHelp = preg_match_all('/<th scope="col" class="no-sort">[^<]+<span class="rh-dec__help">/', $html0);
    check($ths > 0 && $ths === $thHelp, 'every column header has a plain helper line', $ths . ' vs ' . $thHelp);
    check(preg_match_all('/<dt>[^<]+<\/dt><dd[^>]*>[^<]*<\/dd><dd class="rh-dec__help">/', $html0) >= 3, 'every figure on a card has a plain helper line');
    check(substr_count($html0, 'css/review-panel.css') === 1 && substr_count($html0, 'js/review-panel.js') === 1, 'the CSS and JS are linked once, only when the panel is printed');
    check(strpos($html0, $guestName) !== false, 'evidence rows (with names) are shown on the hotel\'s own screen');

    // Cache hit: same figures, no call
    $r2 = rh_dec_refresh($pdo, 'stock', true, $adm['id'], true);
    check(!$r2['model_called'] && count($GLOBALS['dec_calls']) === 1, 'same figures on the next refresh: the stored wording is kept and no model call is made');
    $r3 = rh_dec_refresh($pdo, 'stock', true, $adm['id'], false);
    check($r3['status'] === 'recent' && count($GLOBALS['dec_calls']) === 1, 'the Refresh button within ai_review_refresh_minutes returns the cached cards (no rebuild, no call)');

    // Only cards the model worded are shown (a card the reply left out, e.g. a real stock card, is not shown in fixed wording)
    check(array_column((array)rh_dec_for_viewer($pdo, $adm, 'stock')['main'], 'key') === ['tst_fixture'], 'only cards worded by the call are shown (no card in fixed wording)');

    // A reply whose cards are all refused (added figure / AI words) or empty is a failed call: nothing shown
    $failCases = [
        'an added figure' => json_encode(['order' => ['tst_fixture'], 'cards' => ['tst_fixture' => ['decision' => 'Save 987654 by acting now', 'why_now' => 'Because.', 'recommendation' => 'Act.']]]),
        'AI-sounding words' => json_encode(['cards' => ['tst_fixture' => ['decision' => 'Our AI insight says act', 'why_now' => 'Because.', 'recommendation' => 'Act.']]]),
        'empty cards {}' => '{"order":[],"cards":{}}',
        'no cards key' => '{"order":["tst_fixture"]}',
    ];
    foreach ($failCases as $why => $reply) {
        $GLOBALS['dec_fx_amt'] += 1000.0;
        $n0 = count($GLOBALS['dec_calls']);
        $GLOBALS['dec_reply'] = static fn(string $b): string => $reply;
        $rf = rh_dec_refresh($pdo, 'stock', true, $adm['id'], true);
        check(count($GLOBALS['dec_calls']) > $n0 && $rf['cache']['wording'] === null && rh_dec_for_viewer($pdo, $adm, 'stock') === null && rh_review_panel('stock') === '',
            "a reply with $why is a failed call: no wording stored, nothing shown (never the fixed wording)");
    }

    // Nothing over the threshold: nothing printed (no empty-state panel), with the model up or the connection dead
    // (the nothing-to-show state as the engine records it: every check ran, no card shown, nothing to word)
    $GLOBALS['dec_reply'] = $goodReply;
    $emptyBuilt = ['main' => [], 'more' => [rh_dec_score($fx('small_more', 1.0), ['ok' => true, 'amount' => 100000.0])], 'checks' => [['id' => 'tst_fixture', 'label' => 'Fixture check', 'ok' => true, 'partial' => false, 'total' => 3, 'requires' => []]],
        'base' => ['ok' => true, 'amount' => 100000.0, 'days' => 30], 'cur' => '', 'hash' => hash('sha256', 'empty-fixture')];
    rh_dec_cache_put($pdo, 'stock', $emptyBuilt, rh_dec_nothing_to_word(), '');
    $ce = rh_dec_cache_get($pdo, 'stock');
    check($ce !== null && rh_dec_is_worded($ce) && $ce['main'] === [] && rh_review_panel('stock') === '' && rh_dec_for_viewer($pdo, $adm, 'stock') === null,
        'no card over the threshold: the state is cached but the panel helper returns an empty string (no "all checks ran" panel, no More list)');
    $GLOBALS['dec_mode'] = 'down';
    check(rh_review_panel('stock') === '' && rh_dec_brief($pdo, $adm, true) === null, 'no card over the threshold and a dead connection: empty string, no brief');
    $GLOBALS['dec_mode'] = '';
    $GLOBALS['dec_fx_amt'] += 1000.0;
    rh_dec_refresh($pdo, 'stock', true, $adm['id'], true);
    check(rh_review_panel('stock') !== '', 'a good call again: the panel shows');

    // A good review from earlier today is not shown once the model is not reachable or the Assistant is off
    $set->execute(['ai_cloud_enabled', '0']);
    rh_ai_settings($pdo, true);
    check(rh_dec_is_worded((array)rh_dec_cache_get($pdo, 'stock')) && rh_review_panel('stock') === '' && rh_dec_brief($pdo, $adm) === null, 'cloud providers off: today\'s good review is not shown (empty string, no brief)');
    $set->execute(['ai_cloud_enabled', '1']);
    rh_ai_settings($pdo, true);
    $GLOBALS['__rh_dec_models'] = ['provider' => ''];
    check(rh_review_panel('stock') === '', 'no model chosen any more: today\'s good review is not shown');
    $GLOBALS['__rh_dec_models'] = $models;
    $pdo->exec("UPDATE site_settings SET setting_value = '0' WHERE setting_key = 'ai_enabled'");
    check(rh_review_panel('stock') === '' && rh_dec_brief($pdo, $adm) === null, 'Assistant off: today\'s good review is not shown and the brief has no decisions');
    foreach ($onSql as $q) { $pdo->exec($q); }
    // The figures change and the next call fails: the older wording is not shown for the new figures
    $GLOBALS['dec_fx_amt'] += 1000.0;
    $GLOBALS['dec_mode'] = 'down';
    rh_dec_refresh($pdo, 'stock', true, $adm['id'], true);
    check(rh_review_panel('stock') === '', 'new figures and a failed call: nothing is shown (no stale cards)');
    $GLOBALS['dec_mode'] = '';
    $nCalls = count($GLOBALS['dec_calls']);
    $set->execute(['ai_review_writer_enabled', '0']);
    rh_ai_settings($pdo, true);
    $GLOBALS['dec_fx_amt'] += 1000.0;
    rh_dec_refresh($pdo, 'stock', true, $adm['id'], true);
    check(count($GLOBALS['dec_calls']) === $nCalls && rh_review_panel('stock') === '', 'wording switched off in settings: no model call and nothing shown');
    $set->execute(['ai_review_writer_enabled', '1']);
    rh_ai_settings($pdo, true);
    $GLOBALS['dec_reply'] = $goodReply;
    rh_dec_refresh($pdo, 'stock', true, $adm['id'], true);
    check(rh_review_panel('stock') !== '', 'the model is back: the next refresh words the cards and the panel shows again');

    // ════ 4. Proof log ════
    $card = rh_dec_card_for($pdo, $adm, 'stock', 'tst_fixture');
    $pid = $card ? rh_dec_proof_log($pdo, $card, 'noted', $adm['id']) : 0;
    $row = $pid ? $pdo->query("SELECT * FROM ai_review_proof WHERE id = " . (int)$pid)->fetch(PDO::FETCH_ASSOC) : null;
    check($row && $row['card_key'] === 'tst_fixture' && $row['action'] === 'noted' && (int)$row['user_id'] === $adm['id'] && (int)$row['tier'] === 2 && abs((float)$row['at_stake'] - (float)$card['at_stake']) < BALANCE_TOLERANCE,
        'proof log: the finding, the action, who and the money at stake are recorded');
    check(rh_dec_proof_outcome($pdo, $pid, 'worked', 'Fixture outcome') && !rh_dec_proof_outcome($pdo, $pid, 'no_change'), 'proof log: the outcome is recorded once and never overwritten');
    check(rh_dec_proof_log($pdo, $card, 'ran', $adm['id']) === 0 && rh_dec_proof_log($pdo, array_merge($card, ['tier' => 3]), 'ran', $adm['id']) === 0, 'proof log: a tier 2 or 3 card can never be logged as run by itself');
    check(rh_dec_proof_log($pdo, $card, 'delete_everything', $adm['id']) === 0, 'proof log: an unknown action is refused');

    // ════ 5. Today's brief ════
    $pdo->exec("DELETE FROM ai_review_proof WHERE card_key = 'tst_fixture' AND card_day = CURDATE()"); // fresh start for the waiting list (rolled back)
    $br = rh_dec_brief($pdo, $adm, true);
    $keys = array_column((array)($br['decisions'] ?? []), 'key');
    check($br !== null && in_array('tst_fixture', $keys, true) && in_array('stock', $br['areas'], true), 'Today\'s brief: built from the same decision cards, every area this person may see');
    check(in_array('tst_fixture', array_column($br['waiting_approval'], 'key'), true), 'Today\'s brief: a tier 2 decision waits for approval');
    $stored = (string)$pdo->query("SELECT brief_json FROM ai_review_brief WHERE user_id = " . $adm['id'] . " AND brief_day = CURDATE()")->fetchColumn();
    check($stored !== '' && stripos($stored, $guestName) === false, 'Today\'s brief: stored once for the day, with no guest names');
    $GLOBALS['dec_fx_amt'] += 1000.0;
    $again = rh_dec_brief($pdo, $adm);
    check(json_encode($again) === json_encode(json_decode($stored, true)), 'Today\'s brief: one per person per day (the stored brief is returned)');
    rh_dec_proof_log($pdo, $card, 'approved', $adm['id'], $adm['id']);
    $br2 = rh_dec_brief($pdo, $adm, true);
    check(!in_array('tst_fixture', array_column($br2['waiting_approval'], 'key'), true), 'Today\'s brief: once approved it no longer waits');
    $merged = rh_dec_brief_merge($pdo, $adm, ['lens' => 'owner', 'lens_label' => 'x', 'date' => date('Y-m-d'), 'currency' => '', 'findings' => [['id' => 'wastage', 'kind' => 'money', 'title' => 'Stock wasted'], ['id' => 'hk_queue', 'kind' => 'queue', 'title' => 'Housekeeping jobs waiting']]]);
    $ids = array_column($merged['findings'], 'id');
    check(in_array('dec_tst_fixture', $ids, true) && !in_array('wastage', $ids, true) && in_array('hk_queue', $ids, true) && isset($merged['lens'], $merged['lens_label'], $merged['date'], $merged['currency']),
        'the existing briefing keeps its shape: a covered finding is replaced by its decision, the others stay', json_encode($ids));
    $narrow = $mkUser($pdo, 'narrow', ['dashboard', 'ai_assistant_use', 'bookings']);
    $nb = rh_dec_brief($pdo, ['id' => $narrow, 'role' => 'receptionist'], true);
    check($nb === null || !in_array('tst_fixture', array_column((array)$nb['decisions'], 'key'), true), 'Today\'s brief: shaped by permission (no stock decisions without a stock permission)');
    $same = rh_dec_brief_merge($pdo, ['id' => $narrow, 'role' => 'receptionist'], ['findings' => [['id' => 'wastage']]]);
    check(array_column($same['findings'], 'id') === ['wastage'], 'nothing to add for this person: the briefing is returned unchanged');
    // A stored brief is not served once the figures behind it changed and the new call failed (no stale decisions)
    rh_dec_brief($pdo, $adm, true);
    $GLOBALS['dec_fx_amt'] += 1000.0;
    $GLOBALS['dec_mode'] = 'down';
    rh_dec_refresh($pdo, 'stock', true, $adm['id'], true);
    $GLOBALS['dec_mode'] = '';
    $stale = rh_dec_brief($pdo, $adm);
    check($stale === null || !in_array('tst_fixture', array_column((array)$stale['decisions'], 'key'), true), 'Today\'s brief: after new figures and a failed call the stored decisions are not served');
    $jsSrc = (string)file_get_contents($root . '/admin/js/review-panel.js');
    check(strpos($jsSrc, "j.html === '') { sec.remove();") !== false, 'Refresh with nothing to show removes the panel (no stale cards on screen)');
} finally {
    $pdo->rollBack();
    rh_ai_settings($pdo, true);
    unset($GLOBALS['__rh_dec_models'], $GLOBALS['__rh_dec_extra_finders']);
    rh_ai_set_transport(null);
    putenv('RH_AI_FAKE_PROVIDER');
}
check((int)$pdo->query("SELECT COUNT(*) FROM ai_review_proof WHERE card_key = 'tst_fixture'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE username LIKE 'dectest\\_%'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM ai_usage_daily WHERE model_id = 'test-fast-model'")->fetchColumn() === 0, 'everything the checks wrote was rolled back');

// ════ 6. Static checks on the new files ════
$look = (string)file_get_contents($root . '/admin/includes/review-panel.php') . (string)file_get_contents($root . '/admin/js/review-panel.js') . (string)file_get_contents($root . '/admin/css/review-panel.css');
check(!preg_match('/fa-(magic|wand|robot|brain|star|microchip|hat-wizard)|sparkle|gradient|shimmer|glow|@keyframes|animation\s*:|\bMRA\b/i', $look), 'the panel files: no AI-looking icons or effects, no MRA');
check(!preg_match('/#[0-9a-f]{3,6}\b|rgba?\(/i', (string)file_get_contents($root . '/admin/css/review-panel.css')), 'the panel CSS uses the existing admin tokens only (no new colours)');
$incFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_dec_include_' . getmypid() . '.php';
file_put_contents($incFile, '<?php ob_start(); require ' . var_export($root . '/admin/includes/review-panel.php', true) . '; echo strlen(ob_get_clean());');
$inc = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($incFile));
@unlink($incFile);
check(trim($inc) === '0', 'including the panel helper prints nothing', $inc);
$api = (string)file_get_contents($root . '/admin/api/decisions.php');
check(strpos($api, 'rh_ai_enabled($pdo)') < strpos($api, 'validateCsrfToken') && strpos($api, 'validateCsrfToken') < strpos($api, 'rh_review_gate(') && strpos($api, "!== 'POST'") !== false,
    'endpoint: master switch (404) first, then POST only and the security token, then the panel\'s own gate');

echo "\n$pass passed, $fail failed, $skip skipped\n";
exit($fail > 0 ? 1 : 0);
