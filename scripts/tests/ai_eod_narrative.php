<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * End of Day "The day in brief" (includes/ai/ai-eod-narrative.php). PURE: no database, no network.
 * A small in-memory stand-in answers the few queries the gate, cache, lock and usage make; the model is the fake provider
 * (RH_AI_FAKE_PROVIDER=eod_brief, scripts/tests/fixtures/ai/eod_brief.json), every request captured to a temp file.
 *
 * Usage (repo root): php scripts/tests/ai_eod_narrative.php
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

/* ---- In-memory database stand-in (only the queries this feature makes; anything else is recorded as unexpected) ---- */
class EodFakeStmt extends PDOStatement
{
    public $rows = [];
    private $run;
    public function __construct(callable $run) { $this->run = $run; }
    public function execute(?array $params = null): bool { $this->rows = (array)($this->run)((array)$params); return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $o = PDO::FETCH_ORI_NEXT, int $off = 0): mixed { return $this->rows ? array_shift($this->rows) : false; }
    public function fetchColumn(int $c = 0): mixed { $r = $this->rows[0] ?? null; return $r === null ? false : array_values($r)[$c]; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_KEY_PAIR) {
            $o = [];
            foreach ($this->rows as $r) { $v = array_values($r); $o[$v[0]] = $v[1]; }
            return $o;
        }
        return $this->rows;
    }
}
class EodFakeDb extends PDO
{
    public $settings = ['ai_enabled' => '1', 'ai_provider' => 'google', 'ai_cloud_enabled' => '1'];
    public $moduleOn = '1';
    public $hasKey = 1;
    public $models = 1;
    public $cache = [];
    public $usage = 0;
    public $unexpected = [];
    public function __construct() {}
    public function query(string $sql, ?int $m = null, mixed ...$a): PDOStatement|false
    {
        $st = $this->prepare($sql);
        $st->execute([]);
        return $st;
    }
    public function prepare(string $sql, array $o = []): PDOStatement|false
    {
        $db = $this;
        return new EodFakeStmt(static function (array $p) use ($db, $sql): array {
            if (strpos($sql, 'information_schema.TABLES') !== false) {
                preg_match('/IN \(([^)]*)\)/', $sql, $m);
                return [[count(explode(',', $m[1] ?? ''))]];
            }
            if (strpos($sql, "setting_key = 'ai_enabled'") !== false) {
                return [['en' => $db->settings['ai_enabled'] ?? '0', 'paused' => '', 'paused_kind' => '', 'paused_month' => '', 'module_on' => $db->moduleOn, 'schema_probe' => 0]];
            }
            if (strpos($sql, "LIKE 'ai\\\\_%'") !== false || strpos($sql, "LIKE 'ai\\_%'") !== false) {
                $r = [];
                foreach ($db->settings as $k => $v) { $r[] = ['setting_key' => $k, 'setting_value' => $v]; }
                return $r;
            }
            if (strpos($sql, 'AS has_key') !== false) {
                return [['has_key' => $db->hasKey, 'models' => $db->models]];
            }
            if (strpos($sql, 'SELECT result_json FROM ai_tool_cache') !== false) {
                $e = $db->cache[$p[0]] ?? null;
                return $e && $e['exp'] > time() ? [['result_json' => $e['json']]] : [];
            }
            if (strpos($sql, 'INSERT INTO ai_tool_cache') !== false) {
                $db->cache[$p[0]] = ['uid' => (int)$p[1], 'json' => (string)$p[2], 'exp' => time() + (int)$p[3]];
                return [];
            }
            if (strpos($sql, 'GET_LOCK') !== false || strpos($sql, 'RELEASE_LOCK') !== false) {
                return [[1]];
            }
            if (strpos($sql, 'INSERT INTO ai_usage_daily') !== false) {
                $db->usage++;
                return [];
            }
            if (strpos($sql, 'SUM(tokens_in + tokens_out)') !== false) {
                return [[0]];
            }
            if (strpos($sql, 'price_in_per_mtok') !== false || strpos($sql, 'SELECT api_key_enc FROM ai_providers') !== false) {
                return [];
            }
            $db->unexpected[] = $sql;
            throw new RuntimeException('unexpected query');
        });
    }
}

/* ---- Setup: fake provider + capture; nothing reaches the network ---- */
$cap = tempnam(sys_get_temp_dir(), 'eodcap');
putenv('RH_AI_FAKE_CAPTURE=' . $cap);
putenv('RH_AI_FAKE_PROVIDER=eod_brief');
$db = new EodFakeDb();
$GLOBALS['pdo'] = $db;
require_once $root . '/includes/ai/ai-eod-narrative.php';
rh_ai_fake_reset();
@file_put_contents($cap, '');
$GLOBALS['__rh_ai_an_models'] = ['provider' => 'google', 'fast' => ['model_id' => 'test-fast-model', 'price_in_per_mtok' => null, 'price_out_per_mtok' => null]];
$calls = static function () use ($cap): int { return count(array_filter(explode("\n", (string)@file_get_contents($cap)), 'strlen')); };
$refresh = static function () use ($db): void { rh_ai_settings($db, true); };
$refresh();

$facts = [
    ['key' => 'revenue_earned', 'label' => 'Revenue earned', 'value' => 1500000, 'unit' => 'money', 'basis' => 'Room nights stayed, guest-bill charges, till sales; VAT and levy included'],
    ['key' => 'revenue_vs_previous_day', 'label' => 'Revenue earned compared with the day before', 'value' => -120000, 'unit' => 'money', 'basis' => 'This day\'s revenue earned less the day before\'s'],
    ['key' => 'cash_collected', 'label' => 'Cash collected (all methods, after refunds)', 'value' => 980000, 'unit' => 'money', 'basis' => 'Payments received on this day less refunds paid out on this day'],
    ['key' => 'refunds_paid_out', 'label' => 'Refunds paid out', 'value' => 0, 'unit' => 'money', 'basis' => 'The full amount paid back, on the day it was paid out'],
    ['key' => 'still_owed', 'label' => 'Still owed', 'value' => 250000, 'unit' => 'money', 'basis' => 'Room folios, conference / event / gym accounts and open tabs, as of now'],
    ['key' => 'occupancy', 'label' => 'Occupancy', 'value' => 45.83, 'unit' => 'percent', 'basis' => 'Rooms occupied this night out of sellable rooms'],
    ['key' => 'rooms_occupied', 'label' => 'Rooms occupied', 'value' => 11, 'unit' => 'count', 'basis' => 'Checked-in or checked-out bookings staying this night'],
    ['key' => 'pos_voids', 'label' => 'Till voids', 'value' => 2, 'unit' => 'count', 'basis' => 'Orders voided on the trading day'],
    ['key' => 'no_shows', 'label' => 'No-shows', 'value' => 0, 'unit' => 'count', 'basis' => 'Bookings arriving this day marked no-show'],
    ['key' => 'close_score', 'label' => 'Close score (out of 100)', 'value' => 82, 'unit' => 'count', 'basis' => 'Starts at 100; open arrivals, departures, voids lower it'],
];
$cur = 'MWK ';
$view = rh_ai_eod_view(rh_ai_eod_clean($facts), $cur);
$yesterday = date('Y-m-d', strtotime('-1 day'));
$twoAgo = date('Y-m-d', strtotime('-2 days'));
$threeAgo = date('Y-m-d', strtotime('-3 days'));
$yes = static fn(string $k): bool => true;
$noReports = static fn(string $k): bool => $k !== 'reports';
$noAssistant = static fn(string $k): bool => $k !== 'ai_assistant_use';
$J = static fn(string $story, array $look = []): string => (string)json_encode(['story' => $story, 'worth_a_look' => $look]);
$good = 'Revenue earned was MWK 1,500,000.00, which is MWK 120,000.00 lower than the day before. Occupancy was 45.8% with 11 rooms occupied. Cash collected came to MWK 980,000.00. No refunds were paid out.';

/* ---- 1. Facts: shape, money as the report writes it, direction, no figure leaves without its label ---- */
check(rh_ai_eod_clean([]) === [] && rh_ai_eod_clean([$facts[0]]) === [], 'a missing or one-figure facts list gives nothing');
check(count(rh_ai_eod_clean(array_merge($facts, [['key' => 'Bad Key', 'label' => 'x', 'value' => 1, 'unit' => 'count'], ['key' => 'x2', 'label' => 'y', 'value' => 'abc', 'unit' => 'count'], ['key' => 'x3', 'label' => 'z', 'value' => 1, 'unit' => 'euros']]))) === count($facts),
    'facts with a bad key, a non-number or an unknown unit are left out');
$byId = array_column($view, null, 'id');
check($byId['revenue_earned']['shown'] === 'MWK 1,500,000.00' && $byId['occupancy']['shown'] === '45.8%' && $byId['rooms_occupied']['shown'] === '11',
    'figures shown exactly as the report writes them (currency, 2 decimals; percent 1 place)');
check($byId['revenue_vs_previous_day']['shown'] === 'MWK 120,000.00' && $byId['revenue_vs_previous_day']['versus_day_before'] === 'lower',
    'a change against the day before is shown without a minus sign, with its direction');
$pay = rh_ai_eod_payload($view, 'closed', $yesterday);
check(strpos($pay, '"value"') === false && strpos($pay, substr($yesterday, 0, 4)) === false && strpos($pay, '"day":"closed"') !== false,
    'payload: labels and shown figures only, no raw values, no date digits');
check(rh_ai_eod_day_status(date('Y-m-d')) === 'open' && rh_ai_eod_day_status($yesterday) === 'closed'
    && rh_ai_eod_day_status(date('Y-m-d', strtotime('+1 day'))) === '' && rh_ai_eod_day_status('2026-02-30') === '' && rh_ai_eod_day_status('x') === '',
    'today open, past closed, future or invalid date refused');

/* ---- 2. The checks ---- */
[$b, $why] = rh_ai_eod_check($J($good, ['MWK 250,000.00 is still owed.', 'There were 2 till voids.']), $view, $cur);
check(is_array($b) && $b['text'] === $good && count($b['points']) === 2, 'a good brief passes with its Worth-a-look lines', $why);
[$b, $why] = rh_ai_eod_check('```json' . "\n" . $J($good) . "\n```", $view, $cur);
check(is_array($b), 'a brief wrapped in a code fence is read', $why);
$drops = [
    'a bad number' => [$J('Revenue earned was MWK 1,600,000.00. Occupancy was 45.8%. Cash collected came to MWK 980,000.00.'), 'number'],
    'a number written as a word' => [$J('Revenue earned was MWK 1,500,000.00. Occupancy was 45.8% with twelve rooms occupied. Cash collected came to MWK 980,000.00.'), 'number'],
    'a rounded money figure' => [$J('Revenue earned was MWK 1,500,000. Occupancy was 45.8%. Cash collected came to MWK 980,000.00.'), 'money'],
    'an absence claim with no zero figure' => [$J('Revenue earned was MWK 1,500,000.00. Occupancy was 45.8%. There were no till voids.'), 'did not happen'],
    'nothing owed while money is owed' => [$J('Revenue earned was MWK 1,500,000.00. Occupancy was 45.8%. Nothing is still owed.'), 'did not happen'],
    'a vague absence claim' => [$J('Revenue earned was MWK 1,500,000.00. Occupancy was 45.8%. Nothing else stood out.'), 'did not happen'],
    'review: no no-shows and nothing voided (2 voids)' => [$J('Revenue earned was MWK 1,500,000.00. Occupancy was 45.8%. There were no no-shows and nothing was voided.'), 'did not happen'],
    'review: no refunds and nothing else stood out' => [$J('Revenue earned was MWK 1,500,000.00. Occupancy was 45.8%. No refunds were paid out and nothing else stood out.'), 'did not happen'],
    'word form: no orders were voided' => [$J('Revenue earned was MWK 1,500,000.00. Occupancy was 45.8%. No refunds were paid out, no orders were voided.'), 'did not happen'],
    'word form: no voiding at the till' => [$J('Revenue earned was MWK 1,500,000.00. Occupancy was 45.8%. No refunds were paid out; there was no voiding at the till.'), 'did not happen'],
    'otherwise quiet' => [$J('Revenue earned was MWK 1,500,000.00. Occupancy was 45.8%. It was otherwise quiet.'), 'did not happen'],
    'up when the figures say down' => [$J('Revenue earned was MWK 1,500,000.00, higher than the day before. Occupancy was 45.8%. Cash collected came to MWK 980,000.00.'), 'went up'],
    'down with no comparison figure' => [$J('Revenue earned was MWK 1,500,000.00. Occupancy fell to 45.8%. Cash collected came to MWK 980,000.00.'), 'went down'],
    'hype' => [$J('Revenue earned was an amazing MWK 1,500,000.00. Occupancy was 45.8%. Cash collected came to MWK 980,000.00.'), 'hype'],
    'advice' => [$J('Revenue earned was MWK 1,500,000.00. Occupancy was 45.8%. You should run a promotion.'), 'advice'],
    'an emoji' => [$J("Revenue earned was MWK 1,500,000.00. Occupancy was 45.8%. Cash collected came to MWK 980,000.00 \u{1F389}."), 'emoji'],
    'self-talk' => [$J('The Assistant read the figures. Revenue earned was MWK 1,500,000.00. Occupancy was 45.8%.'), 'written'],
    'too few sentences' => [$J('Revenue earned was MWK 1,500,000.00. Occupancy was 45.8%.'), 'sentences'],
    'four Worth-a-look lines' => [$J($good, ['A.', 'B.', 'C.', 'D.']), 'three'],
    'a bad Worth-a-look line' => [$J($good, ['MWK 999.00 is still owed.']), 'number'],
    'not JSON' => ['Revenue was fine today.', 'JSON'],
    'a link' => [$J('Revenue earned was MWK 1,500,000.00. See https://x.example now. Occupancy was 45.8%.'), 'link'],
];
foreach ($drops as $label => [$reply, $reason]) {
    [$b, $why] = rh_ai_eod_check($reply, $view, $cur);
    check($b === null && stripos($why, $reason) !== false, "dropped: $label", 'reason: ' . $why);
}
[$b, $why] = rh_ai_eod_check($J('Revenue earned was MWK 1,500,000.00. Occupancy was 45.8% with 11 rooms occupied. There were no no-shows.'), $view, $cur);
check(is_array($b), 'an absence backed by a figure of 0 (no-shows) is allowed', $why);

/* ---- 3. Gate off: nothing at all, and the e-mail gets null ---- */
$db->settings['ai_enabled'] = '0';
$refresh();
$slot = [];
check(rh_ai_eod_mount_html($db, $yesterday, $facts, 5, $yes, $slot) === '' && $slot === [], 'kill switch off: the page prints nothing and keeps nothing');
check(rh_ai_eod_email_brief($db, $yesterday, $facts) === null && $calls() === 0, 'kill switch off: the e-mail gets null, no call');
$db->settings['ai_enabled'] = '1';
$db->moduleOn = '0';
check(rh_ai_eod_mount_html($db, $yesterday, $facts, 5, $yes, $slot) === '' && rh_ai_eod_email_brief($db, $yesterday, $facts) === null, 'Assistant module off: nothing');
$db->moduleOn = '1';
$db->settings['ai_cloud_enabled'] = '0';
$refresh();
check(rh_ai_eod_mount_html($db, $yesterday, $facts, 5, $yes, $slot) === '' && rh_ai_eod_email_brief($db, $yesterday, $facts) === null, 'cloud providers off: nothing');
$db->settings['ai_cloud_enabled'] = '1';
$refresh();
$db->models = 0;
check(rh_ai_eod_mount_html($db, $yesterday, $facts, 5, $yes, $slot) === '' && rh_ai_eod_email_brief($db, $yesterday, $facts) === null, 'no Fast or Primary model: nothing');
$db->models = 1;
check(!rh_ai_eod_system_gate(null), 'no database: the gate is closed');
ob_start();
rh_ai_eod_mount($yesterday, $facts, 5); // no session in this run: nothing can be kept, so nothing is printed
check(ob_get_clean() === '', 'no session: the real mount prints nothing');
check($calls() === 0 && $db->usage === 0, 'no model call while the gate was off');

/* ---- 4. Permissions: reports and ai_assistant_use both needed ---- */
check(rh_ai_eod_mount_html($db, $yesterday, $facts, 5, $noReports, $slot) === '' && !isset($slot[$yesterday]), 'no reports permission: nothing printed, nothing kept');
check(rh_ai_eod_mount_html($db, $yesterday, $facts, 5, $noAssistant, $slot) === '', 'no Assistant permission: nothing printed');
check(rh_ai_eod_mount_html($db, $yesterday, $facts, 0, $yes, $slot) === '', 'nobody signed in: nothing printed');
check(rh_ai_eod_mount_html($db, $yesterday, [], 5, $yes, $slot) === '', 'a missing facts list: nothing printed');
check(rh_ai_eod_mount_html($db, date('Y-m-d', strtotime('+1 day')), $facts, 5, $yes, $slot) === '', 'a future date: nothing printed');
$html = rh_ai_eod_mount_html($db, $yesterday, $facts, 5, $yes, $slot);
check(strpos($html, 'data-eod-brief') !== false && strpos($html, ' hidden>') !== false && strpos($html, 'js/eod-narrative.js') !== false
    && strpos($html, 'window.rhEodBrief && window.rhEodBrief();') !== false && strpos($html, 'css/eod-narrative.css') !== false,
    'gate passed: a hidden section, its stylesheet, the loader and the in-app re-run call');
check(strpos($html, 'The day in brief') !== false && strpos($html, 'Written by the Assistant from the figures on this page') !== false, 'heading and footnote');
check(!preg_match('/wand|magic|sparkle|robot|brain|smart|AI-powered/i', $html), 'nothing AI-looking in the markup');
check(strpos($html, '1,500,000') === false && strpos($html, '1500000') === false, 'no figure is printed for the browser to send back');
check(isset($slot[$yesterday]) && $slot[$yesterday]['uid'] === 5 && count($slot[$yesterday]['facts']) === count($facts), 'the facts are kept in the session for that date');
check($calls() === 0, 'drawing the page makes no model call');

/* ---- 5. The browser cannot inject facts ---- */
$_POST = ['action' => 'get_brief', 'date' => $twoAgo, 'facts' => json_encode($facts)];
$_REQUEST = $_POST;
$r = rh_ai_eod_for_request($db, 5, $twoAgo, $slot, $yes);
check($r['text'] === null && $calls() === 0, 'facts posted by the browser for a date the page did not keep: ignored, no call');
check(rh_ai_eod_for_request($db, 6, $yesterday, $slot, $yes)['text'] === null, 'another person cannot use this person\'s kept facts');
check(rh_ai_eod_for_request($db, 5, $yesterday, $slot, $yes, time() + RH_AI_EOD_MAX_AGE + 5)['text'] === null, 'facts kept too long ago are not used');
check(rh_ai_eod_for_request($db, 5, $yesterday, $slot, $noReports)['text'] === null, 'reports taken away after the page drew: nothing');
check($calls() === 0, 'none of those reached the model');
$api = (string)file_get_contents($root . '/admin/api/ai-eod-narrative.php');
preg_match_all('/\$_(POST|GET|REQUEST|COOKIE)\[\'([a-z_]+)\'\]/', $api, $m);
check(array_values(array_unique($m[2])) === ['action', 'csrf_token', 'date'] && strpos($api, '$_REQUEST') === false && strpos($api, 'json_decode') === false,
    'endpoint reads only action, csrf_token and date from the request');
check(strpos($api, "validateCsrfToken") !== false && strpos($api, "hasPermission(\$uid, 'reports')") !== false && strpos($api, 'rh_ai_enabled($pdo)') !== false
    && strpos($api, 'session_write_close()') < strpos($api, 'rh_ai_eod_for_request('), 'endpoint: kill switch, CSRF, reports; session closed before the model call');
$_POST = []; $_REQUEST = [];

/* ---- 6. Writing: a bad reply is asked again once; one call serves page and e-mail; cache hit = no call ---- */
$mail = rh_ai_eod_email_brief($db, $yesterday, $facts); // replies 0 (bad number) then 1 (good)
check($calls() === 2 && $db->usage === 2, 'a reply with a bad number is asked for once more (2 calls, usage recorded for both)', 'calls ' . $calls() . ', usage ' . $db->usage);
check(is_string($mail) && strpos($mail, $good) === 0 && strpos($mail, "\n\nWorth a look:\n- MWK 250,000.00 is still owed.\n- There were 2 till voids.") !== false, 'e-mail: the story then the Worth-a-look lines as plain text', (string)$mail);
$lines = array_values(array_filter(explode("\n", (string)file_get_contents($cap)), 'strlen'));
$req2 = json_decode((string)(json_decode($lines[1], true)['body'] ?? ''), true);
check(strpos(json_encode($req2), 'was not used because it used a number that is not in the figures') !== false, 'the second ask says why the first was not used');
$capRaw = (string)file_get_contents($cap);
check(strpos($capRaw, '1500000') === false && strpos($capRaw, '45.83') === false && strpos($capRaw, 'MWK 1,500,000.00') !== false, 'the requests carry the figures as shown, never raw values');
check(rh_ai_eod_email_brief($db, $yesterday, $facts) === $mail && $calls() === 2, 'the same e-mail again: from the cache, no call');
$r = rh_ai_eod_for_request($db, 5, $yesterday, $slot, $yes);
check($r['text'] === $good && $r['points'] === ['MWK 250,000.00 is still owed.', 'There were 2 till voids.'] && $calls() === 2, 'the page for the same day and figures: the same brief, no call (one call serves both)');
$entry = $db->cache[rh_ai_eod_cache_key($yesterday, rh_ai_eod_hash($view, 'closed'))] ?? null;
check($entry !== null && $entry['uid'] === 0 && $entry['exp'] - time() > 29 * 86400, 'a closed day is kept about 30 days, shared (user 0)');
$changed = $facts;
$changed[6]['value'] = 12;
$slotC = [];
rh_ai_eod_mount_html($db, $yesterday, $changed, 5, $yes, $slotC);
check(rh_ai_eod_for_request($db, 5, $yesterday, $slotC, $yes)['text'] === null && $calls() === 2, 'page: changed figures inside the gap show nothing (never an old brief), no call');

/* ---- 7. Two bad replies: nothing, and the failure is kept (no call on the next ask) ---- */
$slot2 = [];
rh_ai_eod_mount_html($db, $twoAgo, $facts, 5, $yes, $slot2);
$r = rh_ai_eod_for_request($db, 5, $twoAgo, $slot2, $yes); // replies 2 (absence) then 3 (bad number)
check($r['text'] === null && $r['points'] === [] && $calls() === 4, 'two replies that break the rules: no section', 'calls ' . $calls());
check(rh_ai_eod_for_request($db, 5, $twoAgo, $slot2, $yes)['text'] === null && rh_ai_eod_email_brief($db, $twoAgo, $facts) === null && $calls() === 4,
    'the failure is kept: page and e-mail ask nothing more');

/* ---- 7b. The e-mail is sent once: a page view's gap never costs it its brief ---- */
$mail2 = rh_ai_eod_email_brief($db, $yesterday, $changed); // reply 4
check(is_string($mail2) && strpos($mail2, '12 rooms occupied') !== false && $calls() === 5, 'e-mail with figures that moved after a page view: still written (gap does not hold an e-mail back)', 'calls ' . $calls());
check(rh_ai_eod_for_request($db, 5, $yesterday, $slotC, $yes)['text'] !== null && $calls() === 5, 'and the page with those figures then reads the same brief, no call');

/* ---- 8. Missing facts and other refusals ---- */
check(rh_ai_eod_email_brief($db, $threeAgo, []) === null && rh_ai_eod_email_brief($db, $threeAgo, [$facts[0]]) === null, 'a missing facts list: the e-mail gets null');
check(rh_ai_eod_brief($db, $threeAgo, [], 0, $cur)['reason'] === 'facts' && rh_ai_eod_email_brief($db, date('Y-m-d', strtotime('+1 day')), $facts) === null, 'no facts / a future date: refused before any call');
$db->usage = 0;
unset($GLOBALS['__rh_ai_an_models']);
$GLOBALS['__rh_ai_an_models'] = ['provider' => ''];
check(rh_ai_eod_email_brief($db, $threeAgo, $facts) === null && $calls() === 5, 'no usable model: null, no call');
$GLOBALS['__rh_ai_an_models'] = ['provider' => 'google', 'fast' => ['model_id' => 'test-fast-model']];
check(rh_ai_eod_plain(null) === null && rh_ai_eod_plain(['text' => ' ', 'points' => []]) === null && rh_ai_eod_plain(['text' => 'A.', 'points' => []]) === 'A.', 'plain text: null for nothing, no empty heading');

/* ---- 9. The page script and styles ---- */
$js = (string)file_get_contents($root . '/admin/js/eod-narrative.js');
check(strpos($js, 'innerHTML') === false && strpos($js, 'textContent') !== false, 'script writes text only (no HTML from the server)');
check(strpos($js, "data-eod-asked") !== false && strpos($js, 'window.rhEodBrief = function') !== false && strpos($js, 'drop(el)') !== false, 'script: asked once per section, removes the section when no text arrives');
check(strpos($js, "body.append('date'") !== false && !preg_match('/append\(\'(facts|figures|value)/', $js), 'script sends the date only');
$css = (string)file_get_contents($root . '/admin/css/eod-narrative.css');
check(strpos($css, '.eod-brief[hidden]') !== false && strpos($css, 'prefers-reduced-motion') !== false && !preg_match('/#[0-9a-f]{3,6}\b/i', $css), 'styles: hidden really hides, calm motion, shared tokens only (no raw colours)');

check($db->unexpected === [], 'no unexpected query', implode(' | ', $db->unexpected));

rh_ai_fake_reset();
@unlink($cap);
putenv('RH_AI_FAKE_PROVIDER');
putenv('RH_AI_FAKE_CAPTURE');
echo "ai_eod_narrative: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
