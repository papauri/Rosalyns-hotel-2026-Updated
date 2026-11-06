<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Licence client (owner 2026-11-07). Usage (from the repo root): php scripts/tests/licence_client.php
 *  1. unconfigured: no key / no tenant = licensing off, status 'unconfigured', no database touched, no banner, guard does nothing.
 *  2. status rules with a throw-away P-256 key pair: first run (no token) = active; token edited / record deleted = grace;
 *     older issued_at, other domain, other tenant, other key all rejected; the date schedule steps grace -> read_only -> suspended.
 *  3. checking: a local stub server plays the licence panel; only a verified answer is stored (and never an older, other-domain,
 *     other-tenant or badly signed one); errors are recorded without secrets; nothing throws; http to a remote host refused.
 *  4. the guard decisions per status for representative scripts (read-only allowlist, suspended lock, guest site never blocked).
 *  5. every file that starts a staff session calls the guard; the public API files do not.
 * Writes the four licence_* rows of site_settings while it runs (the web server of this site is unlicensed, so it never reads
 * them) and puts the previous values back / deletes them at the end. Does not touch any other row.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function eq($want, $got, string $l): bool { return check($want === $got, $l, 'expected ' . var_export($want, true) . ' got ' . var_export($got, true)); }

// ---- a throw-away key pair; the constant is defined BEFORE the licence files load (the real one is empty) ----
function t_keypair(): array
{
    $opts = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
    $cfg = null;
    $k = @openssl_pkey_new($opts);
    if ($k === false) { // Windows PHP without openssl.cnf
        $cfg = tempnam(sys_get_temp_dir(), 'ossl');
        file_put_contents($cfg, "[req]\ndefault_bits = 2048\ndistinguished_name = req_dn\n[req_dn]\n");
        $opts['config'] = $cfg;
        $k = @openssl_pkey_new($opts);
    }
    if ($k === false) { echo "FAIL cannot generate a P-256 key here\n"; exit(1); }
    @openssl_pkey_export($k, $priv, null, $cfg ? ['config' => $cfg] : null);
    if ($cfg) { @unlink($cfg); }
    return ['private' => $k, 'public' => openssl_pkey_get_details($k)['key']];
}
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
$KP = t_keypair();
$OTHER = t_keypair();
define('RH_LICENCE_PUBLIC_KEY', $KP['public']);

require_once $root . '/config/database.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/includes/licence-guard.php';
$pdo = $GLOBALS['pdo'];

$TENANT = 'lw-test';
$DOMAIN = 'test-hotel.example';
$NOW = time();
$DAY = 86400;
$day = static function (int $offset) { return date('Y-m-d', time() + $offset * 86400); };
function mk_token(array $over, $privKey): array
{
    global $TENANT, $DOMAIN, $NOW, $DAY, $day;
    $p = array_merge([
        'v' => 1, 'tenant' => $TENANT, 'domain' => $DOMAIN, 'status' => 'active', 'override' => false,
        'paid_until' => $day(30), 'grace_days' => 14, 'readonly_days' => 14,
        'issued_at' => $NOW - 60, 'valid_until' => $NOW + 35 * $DAY,
    ], $over);
    $json = json_encode($p, JSON_UNESCAPED_SLASHES);
    openssl_sign($json, $sig, $privKey, OPENSSL_ALGO_SHA256);
    return ['payload' => b64u($json), 'sig' => b64u($sig)];
}
$ev = static function ($tok, int $lastIssued = 0) use ($KP, $TENANT, $DOMAIN, $NOW) {
    return rh_licence_evaluate(['token' => is_array($tok) ? json_encode($tok) : (string)$tok, 'last_issued_at' => $lastIssued], $KP['public'], $TENANT, $DOMAIN, $NOW);
};

// ================= 1. unconfigured =================
check(!rh_licence_is_enabled('', 'x'), 'no key: licensing off');
check(!rh_licence_is_enabled('KEY', ''), 'no tenant: licensing off');
check(!rh_licence_is_enabled('KEY', '  '), 'blank tenant: licensing off');
check(rh_licence_is_enabled('KEY', 'x'), 'key + tenant: licensing on');
$child = static function (string $code) use ($root): string {
    $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
    return trim((string)shell_exec($cmd));
};
$out = $child("putenv('LICENCE_TENANT=abc'); \$_ENV['LICENCE_TENANT']='abc'; require '" . $root . "/includes/licence-guard.php'; \$GLOBALS['pdo']=null; echo rh_licence_status()['status'], '|', var_export(rh_licence_enabled(), true), '|'; ob_start(); rh_licence_render_banner(); echo strlen(ob_get_clean()); rh_licence_guard_request(); echo '|guard-returned';");
eq('active|true|0|guard-returned', $out, 'real build (panel key committed) with a tenant set but no token yet: active, no banner, guard returns (an outage never locks a new install)');
// The real build on a host WITHOUT LICENCE_TENANT (Rosalyn's): licensing stays off whatever the key.
$out = $child("putenv('LICENCE_TENANT='); \$_ENV['LICENCE_TENANT']=''; \$_SERVER['LICENCE_TENANT']=''; require '" . $root . "/includes/licence-guard.php'; \$GLOBALS['pdo']=null; echo rh_licence_status()['status'], '|', var_export(rh_licence_enabled(), true), '|'; ob_start(); rh_licence_render_banner(); echo strlen(ob_get_clean()); rh_licence_guard_request(); echo '|guard-returned';");
eq('unconfigured|false|0|guard-returned', $out, 'real build without LICENCE_TENANT (no licence settings): unconfigured, no banner, guard returns');

// ================= 2. status rules =================
$tok = mk_token([], $KP['private']);
$r = $ev($tok);
eq('active', $r['status'], 'valid token, paid: active');
$r = rh_licence_evaluate(['token' => '', 'last_issued_at' => 0], $KP['public'], $TENANT, $DOMAIN, $NOW);
eq('active', $r['status'], 'first run, no token ever stored: active (an outage never locks a new install)');
eq('no_token', $r['reason'], 'first run reason');
$r = rh_licence_evaluate(['token' => '', 'last_issued_at' => $NOW - 100], $KP['public'], $TENANT, $DOMAIN, $NOW);
eq('grace', $r['status'], 'token record deleted after one was accepted: grace, not active');
// tampered stored token (payload edited, signature kept)
$bad = $tok; $pl = json_decode(base64_decode(strtr($bad['payload'], '-_', '+/')), true); $pl['paid_until'] = $day(3000);
$bad['payload'] = b64u(json_encode($pl, JSON_UNESCAPED_SLASHES));
$r = $ev($bad);
eq('grace', $r['status'], 'tampered stored token: grace');
eq('bad_signature', $r['reason'], 'tampered reason');
check(strpos($r['message'], 'could not be verified') !== false, 'tampered message says it could not be verified');
$r = $ev('{not json');
eq('grace', $r['status'], 'damaged stored token: grace');
// replay / mismatch / other key through the accept step (what check_now uses before storing)
$acc = static function (array $t, int $last = 0, ?string $pub = null, ?string $tenant = null, ?string $dom = null) use ($KP, $TENANT, $DOMAIN, $NOW) {
    return rh_licence_accept($t, $pub ?? $KP['public'], $tenant ?? $TENANT, $dom ?? $DOMAIN, $last, $NOW);
};
check($acc($tok)['ok'], 'accept: genuine answer');
check($acc($tok, $NOW - 60)['ok'], 'accept: same issued_at again is fine');
eq('replayed', $acc($tok, $NOW)['reason'], 'accept: older issued_at than the stored one is rejected');
eq('domain_mismatch', $acc($tok, 0, null, null, 'other-hotel.example')['reason'], 'accept: other domain rejected');
check($acc($tok, 0, null, null, 'https://www.TEST-HOTEL.example/some/page')['ok'], 'accept: site_url form is normalised like the panel (scheme, www, case, path)');
eq('tenant_mismatch', $acc($tok, 0, null, 'someone-else')['reason'], 'accept: other tenant rejected');
eq('bad_signature', $acc($tok, 0, $OTHER['public'])['reason'], 'accept: signed by another key rejected');
eq('malformed', $acc(['payload' => 'x'])['reason'], 'accept: malformed rejected');
// stored token that is older than the highest accepted: not usable (anti-replay)
eq('grace', $ev($tok, $NOW)['status'], 'stored token older than last accepted issued_at: grace');
// the date schedule
eq('grace', $ev(mk_token(['paid_until' => $day(-3)], $KP['private']))['status'], 'paid_until 3 days ago: grace');
eq('read_only', $ev(mk_token(['paid_until' => $day(-20)], $KP['private']))['status'], 'paid_until 20 days ago: read_only');
eq('suspended', $ev(mk_token(['paid_until' => $day(-40)], $KP['private']))['status'], 'paid_until 40 days ago: suspended');
eq('read_only', $ev(mk_token(['status' => 'read_only'], $KP['private']))['status'], 'server says read_only while dates are fine: read_only');
eq('suspended', $ev(mk_token(['status' => 'suspended', 'override' => true], $KP['private']))['status'], 'owner forced suspended: suspended');
eq('active', $ev(mk_token(['status' => 'active', 'override' => true, 'paid_until' => $day(-90)], $KP['private']))['status'], 'owner forced active: active');
// old token (valid_until passed): the schedule alone keeps stepping down
$old = mk_token(['paid_until' => $day(-3), 'issued_at' => $NOW - 40 * $DAY, 'valid_until' => $NOW - 5 * $DAY], $KP['private']);
eq('grace', $ev($old)['status'], 'expired token, 3 days overdue by the schedule: grace');
// dates
$dts = rh_licence_dates(json_decode(base64_decode(strtr(mk_token(['paid_until' => '2026-06-30'], $KP['private'])['payload'], '-_', '+/')), true));
eq(['paid_until' => '2026-06-30', 'grace_end' => '2026-07-14', 'read_only_from' => '2026-07-15', 'read_only_end' => '2026-07-28', 'suspended_from' => '2026-07-29'], $dts, 'dates worked out from paid_until + grace + read-only days');
// lazy-check schedule
check(rh_licence_check_due(['last_check_at' => 0, 'last_error' => ''], $NOW), 'check due: never checked');
check(!rh_licence_check_due(['last_check_at' => $NOW - 1800, 'last_error' => ''], $NOW), 'check not due: half an hour ago (hourly by default)');
check(rh_licence_check_due(['last_check_at' => $NOW - 3700, 'last_error' => ''], $NOW), 'check due: over an hour ago (hourly by default)');
check(rh_licence_check_interval_seconds() === 3600, 'default check interval is one hour');
check(rh_licence_check_due(['last_check_at' => $NOW - 90000, 'last_error' => ''], $NOW), 'check due: over a day ago');
check(rh_licence_check_due(['last_check_at' => $NOW - 2000, 'last_error' => 'x'], $NOW), 'check due: failed, retry after 30 minutes');
check(!rh_licence_check_due(['last_check_at' => $NOW - 600, 'last_error' => 'x'], $NOW), 'check not due: failed 10 minutes ago');

// ================= 3. checking against a stub panel =================
$keys = rh_licence_keys('licence_');
$saved = [];
foreach ($keys as $k) {
    $s = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?'); $s->execute([$k]);
    $v = $s->fetchColumn(); $saved[$k] = ($v === false) ? null : (string)$v;
}
// Start clean even on a site that already holds a real licence answer (Liwonde): the rows saved above are put back
// exactly as they were by $cleanup below, so the real token survives the test.
foreach ($keys as $k) {
    $pdo->prepare('DELETE FROM site_settings WHERE setting_key = ?')->execute([$k]);
    if (function_exists('deleteCache')) { deleteCache('setting_' . $k); }
}
$stubDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_licence_stub_' . bin2hex(random_bytes(4));
mkdir($stubDir);
file_put_contents($stubDir . '/router.php', '<?php $d=getenv("STUB_DIR"); file_put_contents($d."/last.json", json_encode(["body"=>file_get_contents("php://input"),"ctype"=>$_SERVER["CONTENT_TYPE"]??"","method"=>$_SERVER["REQUEST_METHOD"]])); $c=(int)@file_get_contents($d."/code.txt"); http_response_code($c?:200); header("Content-Type: application/json"); echo (string)@file_get_contents($d."/answer.json");');
$port = random_int(18200, 18899);
putenv('STUB_DIR=' . $stubDir);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $stubDir . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $stubDir . '/out.txt', 'w'], 2 => ['file', $stubDir . '/err.txt', 'w']], $pipes, $stubDir);
$up = false;
for ($i = 0; $i < 60 && !$up; $i++) { $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($c) { fclose($c); $up = true; } else { usleep(100000); } }
$cleanup = function () use ($pdo, $keys, $saved, $proc, $stubDir): void {
    foreach ($keys as $k) {
        if ($saved[$k] === null) { $pdo->prepare('DELETE FROM site_settings WHERE setting_key = ?')->execute([$k]); }
        else { $pdo->prepare('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?')->execute([$saved[$k], $k]); }
        if (function_exists('deleteCache')) { deleteCache('setting_' . $k); }
    }
    if (is_resource($proc)) { @proc_terminate($proc); @proc_close($proc); }
    foreach (glob($stubDir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($stubDir);
};
register_shutdown_function($cleanup);
check($up, 'stub panel started on 127.0.0.1:' . $port);

if ($up) {
    $_ENV['LICENCE_TENANT'] = $TENANT; putenv('LICENCE_TENANT=' . $TENANT);
    $_ENV['LICENCE_KEY'] = 'secret-test-key-123'; putenv('LICENCE_KEY=secret-test-key-123');
    $_ENV['LICENCE_URL'] = 'http://127.0.0.1:' . $port . '/api/licence.php'; putenv('LICENCE_URL=' . $_ENV['LICENCE_URL']);
    $GLOBALS['_SITE_SETTINGS']['site_url'] = 'https://www.' . $DOMAIN . '/';
    check(rh_licence_enabled(), 'configured: licensing on');
    eq($DOMAIN, rh_licence_site_domain(), 'site domain = normalised host of site_url');

    $serve = static function (?array $token, int $code = 200, ?string $raw = null) use ($stubDir) {
        file_put_contents($stubDir . '/code.txt', (string)$code);
        file_put_contents($stubDir . '/answer.json', $raw ?? json_encode($token));
        @unlink($stubDir . '/last.json');
    };
    $store = static function () use ($pdo) { return rh_licence_store_read($pdo); };

    // first run with nothing stored
    $s0 = $store();
    eq('', $s0['token'], 'start: no token stored');
    eq('active', rh_licence_status(true)['status'], 'configured, nothing stored yet: active');

    // 3a. the panel is down / refuses: nothing stored, still active, error recorded, key never in it
    $serve(null, 403, '{"error":"denied"}');
    $r = rh_licence_check_now();
    check(!$r['ok'] && strpos($r['error'], 'HTTP 403') !== false && strpos($r['error'], '(denied)') !== false, 'denied: error recorded with code and reason', $r['error']);
    $s = $store();
    eq('', $s['token'], 'denied: nothing stored');
    check($s['last_check_at'] >= $NOW - 5, 'denied: last check time written');
    check(strpos($s['last_error'], 'secret-test-key') === false, 'error text never contains the key');
    eq('active', rh_licence_status(true)['status'], 'denied on first run: still active');
    $last = json_decode((string)file_get_contents($stubDir . '/last.json'), true);
    $req = json_decode((string)($last['body'] ?? ''), true);
    eq('POST', $last['method'] ?? '', 'request is a POST');
    check(stripos((string)($last['ctype'] ?? ''), 'application/json') === 0, 'request sent as application/json');
    eq(['tenant', 'domain', 'key', 'version'], array_keys((array)$req), 'request fields');
    eq([$TENANT, $DOMAIN], [$req['tenant'] ?? '', $req['domain'] ?? ''], 'request tenant and domain');

    // 3b. answers that must NOT be stored
    $good = mk_token(['paid_until' => $day(-3), 'issued_at' => $NOW - 30], $KP['private']);
    foreach ([
        'bad signature (other key)' => mk_token(['issued_at' => $NOW - 30], $OTHER['private']),
        'other domain' => mk_token(['domain' => 'elsewhere.example'], $KP['private']),
        'other tenant' => mk_token(['tenant' => 'someone-else'], $KP['private']),
    ] as $why => $t) {
        $serve($t);
        $r = rh_licence_check_now();
        $s = $store();
        check(!$r['ok'] && $s['token'] === '' && $s['last_issued_at'] === 0 && $s['last_error'] !== '', "not stored: $why", $r['error']);
    }
    $serve(null, 200, 'this is not json');
    $r = rh_licence_check_now();
    check(!$r['ok'] && $store()['token'] === '', 'not stored: garbage answer');

    // 3c. a good answer is stored and used
    $serve($good);
    $r = rh_licence_check_now();
    check($r['ok'] && $r['status'] === 'grace', 'good answer accepted (3 days overdue = grace)', json_encode($r));
    $s = $store();
    check($s['token'] !== '' && $s['last_issued_at'] === $NOW - 30 && $s['last_error'] === '', 'good answer stored, issued_at recorded, error cleared');
    eq('grace', rh_licence_status(true)['status'], 'status now comes from the stored token');
    eq(json_decode($s['token'], true)['payload'], $good['payload'], 'stored token is exactly the verified answer');

    // 3d. an older answer afterwards (a replay of an older state) is refused; the stored one stays
    $older = mk_token(['paid_until' => $day(30), 'issued_at' => $NOW - 5000], $KP['private']);
    $serve($older);
    $r = rh_licence_check_now();
    $s2 = $store();
    check(!$r['ok'] && $s2['token'] === $s['token'] && $s2['last_issued_at'] === $s['last_issued_at'], 'older issued_at refused, stored token unchanged', $r['error']);
    eq('grace', rh_licence_status(true)['status'], 'status unchanged after a replay attempt');

    // 3e. a newer answer replaces it
    $serve(mk_token(['paid_until' => $day(25), 'issued_at' => $NOW - 10], $KP['private']));
    $r = rh_licence_check_now();
    check($r['ok'] && $r['status'] === 'active' && $store()['last_issued_at'] === $NOW - 10, 'newer answer accepted (paid again): active');

    // 3f. the owner switches the hotel off at the panel: read_only then suspended answers
    $serve(mk_token(['paid_until' => $day(-20), 'issued_at' => $NOW - 5], $KP['private']));
    check(rh_licence_check_now()['status'] === 'read_only', 'panel answer read-only dates: read_only');
    $serve(mk_token(['status' => 'suspended', 'override' => true, 'issued_at' => $NOW - 2], $KP['private']));
    check(rh_licence_check_now()['status'] === 'suspended', 'panel forced suspended: suspended');

    // 3g. the panel unreachable: nothing changes, no throw
    $_ENV['LICENCE_URL'] = 'http://127.0.0.1:1/api/licence.php'; putenv('LICENCE_URL=' . $_ENV['LICENCE_URL']);
    $before = $store();
    $r = rh_licence_check_now();
    $after = $store();
    check(!$r['ok'] && $after['token'] === $before['token'] && $after['last_error'] !== '', 'unreachable: error recorded, stored token kept', $r['error']);
    eq('suspended', rh_licence_status(true)['status'], 'unreachable panel never loosens a suspended status');
    $_ENV['LICENCE_URL'] = 'http://example.com/api/licence.php'; putenv('LICENCE_URL=' . $_ENV['LICENCE_URL']);
    $r = rh_licence_check_now();
    check(!$r['ok'] && strpos($r['error'], 'https') !== false, 'plain http to a remote host is refused', $r['error']);
    $_ENV['LICENCE_URL'] = 'http://127.0.0.1:' . $port . '/api/licence.php'; putenv('LICENCE_URL=' . $_ENV['LICENCE_URL']);

    // 3h. onlyIfDue skips a check that is not due
    $serve(mk_token(['paid_until' => $day(25), 'issued_at' => $NOW - 1], $KP['private']));
    rh_licence_store_write($pdo, 'last_check_at', (string)time());
    rh_licence_store_write($pdo, 'last_error', '');
    $r = rh_licence_check_now(true);
    check(!$r['ran'] && !file_exists($stubDir . '/last.json'), 'lazy check not due: panel not contacted');
    rh_licence_store_write($pdo, 'last_check_at', (string)(time() - 90000));
    $r = rh_licence_check_now(true);
    check($r['ran'] && $r['ok'], 'lazy check due: ran and accepted');

    // 3i. editing the stored record by hand: grace; deleting it: grace (not active)
    $row = $store();
    $t = json_decode($row['token'], true);
    $pl = json_decode(base64_decode(strtr($t['payload'], '-_', '+/')), true); $pl['status'] = 'active'; $pl['paid_until'] = $day(900);
    $t['payload'] = b64u(json_encode($pl, JSON_UNESCAPED_SLASHES));
    rh_licence_store_write($pdo, 'token', json_encode($t));
    eq('grace', rh_licence_status(true)['status'], 'stored token edited in the database: grace');
    rh_licence_store_write($pdo, 'token', '');
    eq('grace', rh_licence_status(true)['status'], 'stored token deleted from the database: grace');
    // domain change: the same stored token no longer matches this site
    rh_licence_store_write($pdo, 'token', $row['token']);
    eq('active', rh_licence_status(true)['status'], 'restored genuine token: active');
    $GLOBALS['_SITE_SETTINGS']['site_url'] = 'https://another-site.example/';
    eq('grace', rh_licence_status(true)['status'], 'site address changed: stored token no longer matches, grace');
    $GLOBALS['_SITE_SETTINGS']['site_url'] = 'https://www.' . $DOMAIN . '/';

    // the lazy check only registers for staff pages, never in CLI
    rh_licence_maybe_check_after_response();
    check(true, 'lazy registration is a no-op in CLI');

    // 3j. banner and words per status (token stored as read_only, grace, suspended)
    $words = static function (array $over) use ($KP, $pdo) {
        rh_licence_store_write($pdo, 'token', json_encode(mk_token($over, $KP['private'])));
        rh_licence_store_write($pdo, 'last_issued_at', '0');
        $st = rh_licence_status(true);
        ob_start(); rh_licence_render_banner(); $html = ob_get_clean();
        return [$st, $html];
    };
    [$st, $html] = $words(['paid_until' => $day(-3)]);
    check($st['status'] === 'grace' && strpos($html, 'Payment overdue') !== false && strpos($html, 'will become read-only on') !== false
        && strpos($html, rh_licence_date_words($st['dates']['read_only_from'])) !== false && strpos($html, 'days left') !== false, 'grace banner: overdue, read-only date, days left', $html);
    check(strpos($html, 'alert-warning') !== false && stripos($html, 'sparkle') === false && stripos($html, 'magic') === false, 'grace banner uses the calm warning style');
    [$st, $html] = $words(['paid_until' => $day(-20)]);
    check($st['status'] === 'read_only' && strpos($html, 'read-only') !== false && strpos($html, 'check guests out') !== false, 'read-only banner says what still works');
    [$st, $html] = $words(['paid_until' => $day(-40)]);
    check($st['status'] === 'suspended' && $html === '', 'suspended: no banner (the page is locked instead)');
    [$st, $html] = $words(['paid_until' => $day(30)]);
    check($st['status'] === 'active' && $html === '', 'active: no banner');
    check(strpos(rh_licence_refusal_message(['status' => 'read_only']), 'Not saved') === 0, 'read-only refusal says it was not saved');
check(rh_licence_refusal_message(['status' => 'suspended', 'contact' => 'ProManaged']) === 'Your system is paused because the subscription payment is overdue. Your data is safe. Please contact ProManaged to switch it back on.', 'suspended refusal is the short friendly message');
$ping = is_file($root . '/api/licence-ping.php') ? (string)file_get_contents($root . '/api/licence-ping.php') : '';
check(strpos($ping, 'rh_licence_nudge();') !== false && strpos($ping, "echo '{\"ok\":true}';") !== false && strpos($ping, 'rh_licence_status') === false, 'ping endpoint only nudges and always answers the same (reveals nothing)');
    check(strpos(rh_licence_refusal_message(['status' => 'suspended']), 'data is safe') !== false, 'locked message says the data is safe');
}

// ================= 4. guard decisions =================
$D = static function (string $status, string $area, string $script, string $method = 'POST', array $get = [], array $post = []): bool {
    return rh_licence_decide($status, $area, $script, $method, $get, $post)['allow'];
};
foreach (['unconfigured', 'active', 'grace'] as $s) {
    check($D($s, 'admin', 'user-management.php', 'POST', [], ['action' => 'delete_user']) && $D($s, 'admin_api', 'toggle-module.php') && $D($s, 'station', 'void-order.php'), "$s: nothing is blocked");
}
$RO = 'read_only';
// allowed in read-only
check($D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'checkout']), 'ro: check a guest out (Bookings)');
check($D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'checkout_settle']), 'ro: settle a checkout');
check($D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'update_status', 'status' => 'checked-out']), 'ro: status change to checked-out');
check(!$D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'update_status', 'status' => 'checked-in']), 'ro: check-in refused');
check(!$D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'update_status', 'status' => 'cancelled']), 'ro: cancelling refused');
check($D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'consolidation_record']) && $D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'update_payment']), 'ro: record a payment on a booking');
check($D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'get_booking_details']) && $D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'checkout_assess']), 'ro: reads sent as POST still work');
check(!$D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'delete_booking']) && !$D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'modify_booking']) && !$D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'extend_stay']), 'ro: delete / modify / extend refused');
check(!$D($RO, 'admin', 'bookings.php', 'POST', [], ['action' => 'get_booking_details', 'booking_action' => 'cancel']), 'ro: a read action cannot smuggle a second, changing one');
check($D($RO, 'admin', 'booking-details.php', 'POST', [], ['booking_action' => 'checkout']), 'ro: Booking details checkout');
check(!$D($RO, 'admin', 'booking-details.php', 'POST', [], ['booking_action' => 'cancel']) && !$D($RO, 'admin', 'booking-details.php', 'POST', [], ['booking_action' => 'noshow']), 'ro: Booking details cancel / no-show refused');
check($D($RO, 'admin', 'booking-details.php', 'POST', [], ['update_payment' => '1', 'payment_status' => 'paid']), 'ro: Booking details record payment (bare field)');
check(!$D($RO, 'admin', 'booking-details.php', 'POST', [], ['add_note' => '1']) && !$D($RO, 'admin', 'booking-details.php', 'POST', [], ['adjust_dates' => '1']) && !$D($RO, 'admin', 'booking-details.php', 'POST', [], ['charge_action' => 'add_charge']), 'ro: notes, date changes, new charges refused');
check(!$D($RO, 'admin', 'booking-details.php', 'POST', [], ['update_payment' => '1', 'booking_action' => 'cancel']), 'ro: payment field cannot smuggle a cancel');
check($D($RO, 'admin', 'payment-add.php') && $D($RO, 'admin', 'ajax-receipt.php') && $D($RO, 'admin', 'document-download.php') && $D($RO, 'admin', 'licence.php') && $D($RO, 'admin', 'change-password.php'), 'ro: payments, receipts, downloads, licence page, own password');
check($D($RO, 'admin', 'pos.php', 'POST', [], ['action' => 'pay_existing']) && $D($RO, 'admin', 'stock-orders.php', 'POST', [], ['action' => 'settle_order']) && $D($RO, 'admin', 'restaurant-tables.php', 'POST', [], ['action' => 'settle_table_order']), 'ro: settle an existing tab / order / table');
check($D($RO, 'admin', 'conference-management.php', 'POST', [], ['action' => 'record_payment']) && $D($RO, 'admin', 'events-inquiries.php', 'POST', [], ['action' => 'record_payment']) && $D($RO, 'admin', 'gym-inquiries.php', 'POST', [], ['action' => 'record_payment']) && !$D($RO, 'admin', 'conference-management.php', 'POST', [], ['action' => 'update_amount']) && !$D($RO, 'admin', 'conference-management.php', 'POST', [], ['action' => 'cancel']), 'ro: record a payment on a conference / event / gym account; amounts and cancels refused');
check(!$D($RO, 'admin', 'pos.php', 'POST', [], []) && !$D($RO, 'admin', 'pos.php', 'POST', [], ['action' => 'add_to_tab']) && !$D($RO, 'admin', 'pos.php', 'POST', [], ['action' => 'park']), 'ro: a NEW sale (till default action) / new tab line refused');
check(!$D($RO, 'admin', 'pos.php', 'POST', [], ['action' => 'refund_order']) && !$D($RO, 'admin', 'pos.php', 'POST', [], ['action' => 'set_float']), 'ro: refunds and opening a float refused');
check($D($RO, 'admin', 'pos.php', 'POST', [], ['action' => 'close_shift']) && $D($RO, 'admin', 'pos-accounting.php', 'POST', [], ['action' => 'close_shift']), 'ro: closing an open shift (cashing up) allowed');
check(!$D($RO, 'admin', 'pos.php', 'GET', ['ajax' => 'toggle_item']) && $D($RO, 'admin', 'pos.php', 'GET', ['ajax' => 'tabs']) && $D($RO, 'admin', 'pos.php', 'GET'), 'ro: the till GET item toggle refused, other till GETs fine');
check($D($RO, 'admin', 'bookings.php', 'GET', ['export' => 'csv']) && $D($RO, 'admin', 'dashboard.php', 'GET') && $D($RO, 'admin', 'bookings.php', 'GET', ['action' => 'checkout']), 'ro: pages and exports open');
check($D($RO, 'admin', 'backup-management.php', 'POST', [], ['action' => 'run_backup']) && !$D($RO, 'admin', 'backup-management.php', 'POST', [], ['action' => 'restore']) && !$D($RO, 'admin', 'backup-management.php', 'POST', [], ['action' => 'delete_backups']), 'ro: backups run; restore and delete refused');
foreach (['create-booking.php', 'edit-booking.php', 'user-management.php', 'booking-settings.php', 'room-management.php', 'menu-management.php', 'stock-ingredients.php', 'data-import.php', 'stock-clear.php', 'invoices.php', 'payment-refund.php', 'cache-management.php', 'api-keys.php'] as $pg) {
    check(!$D($RO, 'admin', $pg, 'POST', [], ['action' => 'save', 'x' => '1']) && !$D($RO, 'admin', $pg, 'POST', [], ['name' => 'x']), "ro: $pg change refused");
}
check($D($RO, 'admin_api', 'presence.php') && $D($RO, 'admin_api', 'global-search.php') && $D($RO, 'admin_api', 'nav-favorites.php'), 'ro: presence / search / favourites bookkeeping');
check(!$D($RO, 'admin_api', 'ai-action.php', 'POST', [], ['action' => 'confirm']) && !$D($RO, 'admin_api', 'toggle-module.php') && !$D($RO, 'admin_api', 'apply-preset.php') && !$D($RO, 'admin_api', 'menu-recipe.php', 'POST', [], ['action' => 'save_recipe']) && !$D($RO, 'admin_api', 'room-pictures.php', 'DELETE'), 'ro: Assistant confirmed actions and admin/api writes refused');
check($D($RO, 'admin_api', 'credit-notes.php', 'GET', ['action' => 'lookup']) && $D($RO, 'admin_api', 'menu-recipe.php', 'GET', ['action' => 'get_recipe']) && $D($RO, 'admin_api', 'review-stats.php', 'GET'), 'ro: admin/api reads work');
check(!$D($RO, 'station', 'kds-action.php', 'POST', [], ['action' => 'bump_ticket']) && !$D($RO, 'station', 'kds-action.php', 'GET', ['action' => 'bump_ticket']), 'ro: kitchen bump refused whatever the method');
check($D($RO, 'station', 'kds-action.php', 'GET', ['action' => 'feed']) && $D($RO, 'station', 'kds-action.php', 'GET') && $D($RO, 'station', 'kds-action.php', 'POST', [], ['action' => 'ack_message']), 'ro: KDS feed and acknowledgements work');
check(!$D($RO, 'station', 'cancel-order.php') && !$D($RO, 'station', 'void-order.php') && !$D($RO, 'station', 'delete-order.php'), 'ro: cancel / void / delete order refused');
check($D($RO, 'station', 'pos-notifications.php', 'POST', [], ['action' => 'poll']) && $D($RO, 'station', 'reports-export.php', 'GET') && $D($RO, 'station', 'pos-tab-detail.php', 'GET'), 'ro: notifications, report export, tab detail');
check($D($RO, 'other', 'booking.php') && $D($RO, 'other', 'submit-review.php') && $D($RO, 'other', 'bookings.php'), 'ro: guest website never gated');
// suspended
$SU = 'suspended';
check(!$D($SU, 'admin', 'dashboard.php', 'GET') && !$D($SU, 'admin', 'bookings.php', 'GET') && !$D($SU, 'admin', 'bookings.php', 'POST', [], ['action' => 'checkout']) && !$D($SU, 'admin', 'pos.php', 'POST', [], ['action' => 'pay_existing']), 'suspended: admin pages locked, even checkout and payments');
check($D($SU, 'admin', 'licence.php', 'GET') && $D($SU, 'admin', 'licence.php', 'POST', [], ['action' => 'check_now']), 'suspended: the Licence page and its Check now stay open');
check($D($SU, 'admin', 'document-download.php', 'GET') && $D($SU, 'admin', 'bookings.php', 'GET', ['export' => 'csv']) && $D($SU, 'admin', 'payments.php', 'GET', ['export' => 'csv']) && $D($SU, 'admin', 'kds-report.php', 'GET', ['format' => 'csv']), 'suspended: data export stays open');
check(!$D($SU, 'admin', 'dashboard.php', 'GET', ['download' => '1']) && !$D($SU, 'admin', 'bookings.php', 'GET', ['export' => 'pdf']) && !$D($SU, 'admin', 'pos.php', 'GET', ['format' => 'csv']) && !$D($SU, 'admin', 'bookings.php', 'GET', ['export' => 'x']) && !$D($SU, 'admin', 'user-management.php', 'GET', ['export' => 'csv']) && !$D($SU, 'admin', 'kds-report.php', 'GET', ['export' => 'csv']), 'suspended: a made-up ?export / ?download / ?format cannot open a page that has no such export');
check($D($SU, 'admin', 'quotations.php', 'GET', ['download' => '12']) && $D($SU, 'admin', 'receipts.php', 'GET', ['export' => 'csv']) && $D($SU, 'admin', 'stock-reports.php', 'GET', ['export' => 'csv']) && $D($SU, 'admin', 'end-of-day-report.php', 'GET', ['export' => 'csv']), 'suspended: the other real exports stay open');
check($D($SU, 'admin', 'backup-management.php', 'GET') && $D($SU, 'admin', 'backup-management.php', 'GET', ['download' => 'x']) && $D($SU, 'admin', 'backup-management.php', 'POST', [], ['action' => 'run_backup']) && !$D($SU, 'admin', 'backup-management.php', 'POST', [], ['action' => 'restore']), 'suspended: backups can be taken and downloaded, not restored');
check(!$D($SU, 'admin', 'bookings.php', 'POST', [], ['export' => 'csv']), 'suspended: a POST cannot pose as an export');
check(!$D($SU, 'admin_api', 'ai-chat.php') && !$D($SU, 'admin_api', 'presence.php') && !$D($SU, 'station', 'kds-action.php', 'GET', ['action' => 'feed']) && !$D($SU, 'station', 'void-order.php'), 'suspended: admin/api and station APIs refused (423)');
check($D($SU, 'admin_api', 'end-of-day-pdf.php', 'GET') && $D($SU, 'station', 'reports-export.php', 'GET'), 'suspended: report exports stay open');
check($D($SU, 'other', 'index.php') && $D($SU, 'other', 'booking.php', 'POST') && $D($SU, 'other', 'contact.php', 'POST'), 'suspended: guest website never gated');
// targets
eq(['admin', 'bookings.php'], rh_licence_request_target('/admin/bookings.php'), 'target: admin page');
eq(['admin', 'bookings.php'], rh_licence_request_target('/hotel/admin/Bookings.php'), 'target: admin page in a sub-folder install');
eq(['admin_api', 'presence.php'], rh_licence_request_target('/admin/api/presence.php'), 'target: admin/api');
eq(['station', 'kds-action.php'], rh_licence_request_target('/api/kds-action.php'), 'target: station api');
eq(['other', 'booking.php'], rh_licence_request_target('/booking.php'), 'target: guest page');
eq(['other', ''], rh_licence_request_target(''), 'target: none');
// reply format
$sv = $_SERVER;
$_POST = []; $_SERVER = ['HTTP_SEC_FETCH_MODE' => 'navigate'];
check(rh_licence_wants_html(), 'browser form post: page / redirect');
$_SERVER = ['HTTP_SEC_FETCH_MODE' => 'cors'];
check(!rh_licence_wants_html(), "a page's own fetch(): JSON");
$_SERVER = ['HTTP_X_RH_ACTIVITY' => '1', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_SEC_FETCH_MODE' => 'cors'];
check(rh_licence_wants_html(), 'admin shell navigation: page / redirect');
$_POST = ['client_uuid' => 'abc'];
$_SERVER = ['HTTP_SEC_FETCH_MODE' => 'navigate'];
check(rh_licence_wants_html(), 'till form post carries client_uuid on every post: still a normal page / redirect');
$_POST = ['client_uuid' => 'abc', 'client_queued_at' => '2026-11-07 10:00:00'];
$_SERVER = ['HTTP_SEC_FETCH_MODE' => 'cors'];
check(!rh_licence_wants_html(), 'offline-queue replay (client_queued_at): never a redirect (JSON status code)');
$_SERVER = [];
check(!rh_licence_wants_html(), 'replay from an old browser without fetch metadata: JSON');
$_POST = []; $_SERVER = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
check(!rh_licence_wants_html(), 'XHR without the shell header: JSON');
$_SERVER = $sv;

// ================= 5. coverage of entry points + registry =================
$missing = [];
$scan = array_merge(glob($root . '/admin/*.php') ?: [], glob($root . '/admin/api/*.php') ?: [], glob($root . '/api/*.php') ?: []);
foreach ($scan as $f) {
    $src = (string)file_get_contents($f);
    $base = str_replace('\\', '/', substr($f, strlen($root) + 1));
    $startsSession = (bool)preg_match('/rh_admin_session_start\(\)\s*;/', $src) && $base !== 'includes/admin-session.php';
    if ($startsSession && strpos($src, 'rh_licence_guard_request') === false && $base !== 'admin/login.php') {
        $missing[] = $base;
    }
}
check($missing === [], 'every file that starts a staff session calls the licence guard (login.php excepted: nobody is signed in yet)', implode(', ', $missing));
foreach (['admin/admin-init.php', 'admin/api/api-init.php'] as $f) {
    check(strpos((string)file_get_contents($root . '/' . $f), 'rh_licence_guard_request();') !== false, "$f calls the guard");
}
$pub = [];
foreach (['api/index.php', 'api/bookings.php', 'api/availability.php', 'api/rooms.php', 'api/reviews.php', 'api/site-settings.php', 'api/cookie-consent.php', 'booking.php', 'index.php', 'submit-review.php'] as $f) {
    if (is_file($root . '/' . $f) && strpos((string)file_get_contents($root . '/' . $f), 'licence') !== false) { $pub[] = $f; }
}
check($pub === [], 'guest website and public API files do not touch the licence code', implode(', ', $pub));
check(isset(getAllPermissions()['licence']) && getPermissionForPage('licence.php') === 'licence', 'permission "licence" registered and mapped to licence.php');
$defaultsHave = false;
foreach (['admin', 'manager', 'receptionist', 'staff'] as $role) {
    if ($role !== 'admin' && in_array('licence', getDefaultPermissionsForRole($role), true)) { $defaultsHave = true; }
}
check(!$defaultsHave, 'no role except administrator gets the licence permission by default');
check(is_file($root . '/admin/licence.php') && strpos((string)file_get_contents($root . '/admin/licence.php'), "!== 'admin'") !== false && strpos((string)file_get_contents($root . '/admin/licence.php'), 'validateCsrfToken') !== false, 'Licence page: role check and CSRF present');
check(trim(rh_licence_public_key()) !== '' && (string)constant('RH_LICENCE_PUBLIC_KEY') === $KP['public'], 'test key in force for this run');
$real = (string)file_get_contents($root . '/includes/licence-public-key.php');
check(strpos($real, "define('RH_LICENCE_PUBLIC_KEY'") !== false
    && $child("require '" . $root . "/includes/licence-public-key.php'; \$k = openssl_pkey_get_public(RH_LICENCE_PUBLIC_KEY); \$d = \$k ? openssl_pkey_get_details(\$k) : []; echo (\$d['ec']['curve_name'] ?? 'none');") === 'prime256v1',
    'public key file defines the panel key as a valid P-256 public key');

// Page load vs script fetch (the installed app's service worker re-fetches page loads as mode "same-origin").
$hdr = static function (array $set): void {
    foreach (['HTTP_SEC_FETCH_MODE', 'HTTP_SEC_FETCH_DEST', 'HTTP_X_REQUESTED_WITH', 'HTTP_X_RH_ACTIVITY', 'HTTP_ACCEPT'] as $k) { unset($_SERVER[$k]); }
    foreach ($set as $k => $v) { $_SERVER[$k] = $v; }
};
$hdr(['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'document']);
check(rh_licence_is_page_load() && rh_licence_wants_html(), 'plain browser page load = page');
$hdr(['HTTP_SEC_FETCH_MODE' => 'same-origin', 'HTTP_SEC_FETCH_DEST' => 'document']);
check(rh_licence_is_page_load() && rh_licence_wants_html(), 'page load through the installed app (service worker) = page, not JSON');
$hdr(['HTTP_SEC_FETCH_MODE' => 'same-origin', 'HTTP_SEC_FETCH_DEST' => 'empty', 'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);
check(rh_licence_is_page_load() && rh_licence_wants_html(), 'service-worker re-fetch marked as a script but carrying the page Accept = page');
$hdr(['HTTP_SEC_FETCH_MODE' => 'same-origin', 'HTTP_SEC_FETCH_DEST' => 'empty', 'HTTP_ACCEPT' => '*/*']);
check(!rh_licence_is_page_load() && !rh_licence_wants_html(), 'a plain fetch (Accept */*) = JSON');
$hdr(['HTTP_SEC_FETCH_MODE' => 'same-origin', 'HTTP_SEC_FETCH_DEST' => 'empty', 'HTTP_ACCEPT' => 'application/json']);
check(!rh_licence_is_page_load() && !rh_licence_wants_html(), 'a JSON fetch = JSON');
$hdr(['HTTP_SEC_FETCH_MODE' => 'cors', 'HTTP_SEC_FETCH_DEST' => 'empty', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/html, */*']);
check(!rh_licence_is_page_load(), 'an XHR asking for HTML is still not a page load');
$hdr(['HTTP_SEC_FETCH_MODE' => 'cors', 'HTTP_SEC_FETCH_DEST' => 'empty', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
check(!rh_licence_is_page_load() && !rh_licence_wants_html(), 'a script fetch = JSON');
$hdr(['HTTP_SEC_FETCH_MODE' => 'same-origin', 'HTTP_SEC_FETCH_DEST' => 'document']);
$_POST['client_queued_at'] = '1';
check(!rh_licence_is_page_load() && !rh_licence_wants_html(), 'offline queue replay = JSON even from a document');
unset($_POST['client_queued_at']);
$hdr([]);

echo "Licence client: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
