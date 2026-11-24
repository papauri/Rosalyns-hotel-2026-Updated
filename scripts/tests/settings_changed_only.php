<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * settings_changed_only.php - settings forms save only what the person changed (concurrency guardrails 12-14,
 * includes/settings-save-changed.php; pages: Automated Emails, WhatsApp settings, Facebook settings; task 19a: Hotel
 * Settings -> Hotel details & policies and Review sources).
 *   php scripts/tests/settings_changed_only.php [--page=helper|automated-emails|whatsapp|facebook|social|hotel-settings]
 * Liwonde (the test platform) only. Helper cases run on the app $pdo inside a transaction that is rolled back;
 * page cases run in page_harness.php (one never-committing transaction). Nothing is sent: no test/send action is
 * posted, and the Facebook "connect" case stops at the conflict before any Facebook address.
 */
chdir(dirname(__DIR__, 2));
$root = getcwd();
if (stripos($root, 'rosalyn') !== false) { fwrite(STDERR, "Refused: tests never run in the Rosalyn's folder.\n"); exit(2); }
$h = __DIR__ . '/page_harness.php';
$only = 'all';
foreach (array_slice($argv, 1) as $a) { if (strpos($a, '--page=') === 0) { $only = substr($a, 7); } }
$want = static function (string $part) use ($only): bool {
    return $only === 'all' || $only === $part || ($only === 'social' && in_array($part, ['whatsapp', 'facebook'], true));
};
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name  " . substr($detail, 0, 600) . "\n"; }
}
require_once $root . '/config/database.php';
require_once $root . '/includes/settings-save-changed.php';
if ((string)$pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'site_is_test_platform'")->fetchColumn() !== '1') {
    fwrite(STDERR, "Refused: this database is not the test platform (site_is_test_platform != 1).\n");
    exit(2);
}
try { $pdo->exec('SET SESSION wait_timeout = 1800'); } catch (Throwable $e) {}

// ── Helper ────────────────────────────────────────────────────────────────────
if ($want('helper')) {
    echo "== Helper (includes/settings-save-changed.php) ==\n";
    ok('norm: 16.50 = 16.5, 16.0 = 16, -0.00 = 0', rh_settings_base_norm('16.50') === '16.5' && rh_settings_base_norm('16.0') === '16' && rh_settings_base_norm('-0.00') === '0');
    ok('norm: leading zeros kept, text trimmed, CRLF as LF', rh_settings_base_norm('0999') === '0999' && rh_settings_base_norm(" a\r\nb ") === "a\nb");
    ok('parse: missing / empty / not JSON / list / bad hash -> null',
        rh_settings_base_parse(null) === null && rh_settings_base_parse('') === null && rh_settings_base_parse('x') === null
        && rh_settings_base_parse('[]') === null && rh_settings_base_parse('{"a":"zz"}') === null && rh_settings_base_parse('{"a b":"' . md5('') . '"}') === null);
    ok('parse: a valid map', rh_settings_base_parse('{"zz_a":"' . md5('1') . '"}') === ['zz_a' => md5('1')]);

    $pdo->beginTransaction();
    try {
        $set = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach (['zz_scs_a' => 'one', 'zz_scs_b' => '16.50', 'zz_scs_old' => 'X', 'zz_scs_enc' => 'wa1:ZZHELPERMARKER', 'zz_scs_api_token' => 'plainZZ'] as $k => $v) {
            $set->execute([$k, $v]);
        }
        $pdo->exec("DELETE FROM site_settings WHERE setting_key IN ('zz_scs_c', 'zz_scs_new')");
        $spec = ['zz_scs_a' => '', 'zz_scs_b' => '', 'zz_scs_c' => '1', 'zz_scs_new' => ['default' => 'D', 'fallback' => 'zz_scs_old'],
            'zz_scs_enc' => '', 'zz_scs_api_token' => ''];
        $map = rh_settings_base_map($pdo, $spec);
        ok('map leaves out a secret key and an encrypted value', isset($map['zz_scs_a']) && !isset($map['zz_scs_enc']) && !isset($map['zz_scs_api_token']), json_encode(array_keys($map)));
        ok('map: no row = the default; no row but an older key = its value', ($map['zz_scs_c'] ?? '') === md5('1') && ($map['zz_scs_new'] ?? '') === md5('X'));
        $field = rh_settings_base_field($pdo, $spec);
        ok('hidden field carries no secret, not even its hash', strpos($field, 'ZZHELPERMARKER') === false && strpos($field, md5('wa1:ZZHELPERMARKER')) === false
            && strpos($field, md5('plainZZ')) === false && strpos($field, 'plainZZ') === false);
        $base = preg_match('/value="([^"]*)"/', $field, $fm) ? rh_settings_base_parse(html_entity_decode($fm[1], ENT_QUOTES, 'UTF-8')) : null;
        ok('hidden field round-trips to the map', $base === $map);
        $dec = static function (string $k, $v, $o = '') use ($pdo, &$base): array {
            return rh_settings_base_decide($pdo, $base, $k, $v, $o);
        };
        ok('left as opened -> skip', $dec('zz_scs_a', 'one')['outcome'] === 'skip');
        ok('surrounding spaces only -> skip', $dec('zz_scs_a', ' one ')['outcome'] === 'skip');
        ok('changed by the person only -> write', $dec('zz_scs_a', 'two')['outcome'] === 'write');
        ok('16.5 posted for a stored 16.50 -> skip', $dec('zz_scs_b', '16.5')['outcome'] === 'skip');
        ok('no row, default 1, ticked -> skip; checkbox absent (0) -> write', $dec('zz_scs_c', '1', '1')['outcome'] === 'skip' && $dec('zz_scs_c', '0', '1')['outcome'] === 'write');
        $fbE = ['default' => 'D', 'fallback' => 'zz_scs_old'];
        ok('older key read the same way on save', $dec('zz_scs_new', 'X', $fbE)['outcome'] === 'skip' && $dec('zz_scs_new', 'Y', $fbE)['outcome'] === 'write');
        ok('field as opened but cleaned differently (raw) -> skip', $dec('zz_scs_a', 'ONE', ['raw' => 'one'])['outcome'] === 'skip');
        ok('missing base / key not in base / secret key -> no_base',
            rh_settings_base_decide($pdo, null, 'zz_scs_a', 'two')['outcome'] === 'no_base'
            && $dec('zz_scs_missing', 'two')['outcome'] === 'no_base'
            && rh_settings_base_decide($pdo, ['zz_scs_api_token' => md5('plainZZ')], 'zz_scs_api_token', 'x')['outcome'] === 'no_base');

        // Someone else changes A after the page was opened.
        $set->execute(['zz_scs_a', 'three']);
        $r = $dec('zz_scs_a', 'two');
        ok('both changed, no log row -> conflict, "someone else"', $r['outcome'] === 'conflict' && strpos($r['message'], 'changed by someone else since you opened this page') !== false && $r['current'] === 'three', json_encode($r));
        ok('the person left it as opened -> skip, the other change kept', $dec('zz_scs_a', 'one')['outcome'] === 'skip');
        ok('the person typed what is stored now -> skip', $dec('zz_scs_a', 'three')['outcome'] === 'skip');
        $log = $pdo->prepare("INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, 'setting_change', ?, '127.0.0.1', ?)");
        $log->execute([null, 'zz_other', 'page=automated-emails.php; key=zz_scs_a; label=ZZ A; old=one; new=three', '2026-11-20 09:15:00']);
        $log->execute([null, 'zz_decoy', 'page=automated-emails.php; key=zzXscsXa; label=ZZ A; old=one; new=three', '2026-11-21 10:00:00']);
        $r = $dec('zz_scs_a', 'two');
        ok('conflict names the person and time from the real setting_change row (LIKE wildcards escaped)', $r['outcome'] === 'conflict'
            && strpos($r['message'], 'changed by zz_other at 20 Nov 2026 09:15') !== false && strpos($r['message'], 'zz_decoy') === false, $r['message']);
        $log->execute([null, 'zz_stale', 'page=automated-emails.php; key=zz_scs_a; label=ZZ A; old=three; new=four', '2026-11-22 11:00:00']);
        $r = $dec('zz_scs_a', 'two');
        ok('a latest row whose new value is not what is stored never names anyone', $r['outcome'] === 'conflict'
            && strpos($r['message'], 'someone else') !== false && strpos($r['message'], 'zz_stale') === false, $r['message']);
        $adm = $pdo->query("SELECT id, full_name FROM admin_users WHERE is_active = 1 AND full_name IS NOT NULL AND full_name <> '' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($adm) {
            $set->execute(['zz_scs_a', 'five']);
            $log->execute([(int)$adm['id'], 'zz_login', 'page=automated-emails.php; key=zz_scs_a; label=ZZ A; old=three; new=five', '2026-11-23 08:05:00']);
            $r = $dec('zz_scs_a', 'two');
            ok('the name is the staff full name when the row has a user', strpos($r['message'], 'changed by ' . trim((string)$adm['full_name']) . ' at 23 Nov 2026 08:05') !== false, $r['message']);
        }
        $set->execute(['zz_scs_b', '16.5']);
        ok('someone re-saved 16.50 as 16.5 and the person left it -> skip', $dec('zz_scs_b', '16.50')['outcome'] === 'skip');

        // Facebook Page ID + pasted token stay a pair. The page path needs a live Facebook check of the token (never
        // run from a test), so the pair rule is tested here on the real rows, plus a source check of the page below.
        $set->execute(['facebook_page_id', '1111111']);
        $set->execute(['facebook_page_access_token', 'fb1:ZZP1TOKEN']);
        $fbBaseMap = rh_settings_base_map($pdo, ['facebook_page_id' => '']);
        // Someone else connects Page P2 the OAuth way (facebookStoreConnection writes the ID and token together).
        $set->execute(['facebook_page_id', '2222222']);
        $set->execute(['facebook_page_access_token', 'fb1:ZZP2TOKEN']);
        $d = rh_settings_base_decide($pdo, $fbBaseMap, 'facebook_page_id', '1111111', '');
        $msg = rh_settings_base_pair_refusal($pdo, 'facebook_page_id', $d, '1111111');
        $rowsNow = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('facebook_page_id','facebook_page_access_token')")->fetchAll(PDO::FETCH_KEY_PAIR);
        ok('pasted P1 token after someone connected P2 -> whole save refused (ID left as opened is not enough)', $d['outcome'] === 'skip' && is_string($msg)
            && strpos($msg, 'someone else') !== false && strpos($msg, 'Nothing was saved') !== false, json_encode([$d, $msg]));
        ok('... and P2 ID + token stay as stored', ($rowsNow['facebook_page_id'] ?? '') === '2222222' && ($rowsNow['facebook_page_access_token'] ?? '') === 'fb1:ZZP2TOKEN');
        $log->execute([null, 'zz_other', 'page=facebook-settings.php; key=facebook_page_id; label=Facebook Page ID; old=1111111; new=2222222', '2026-11-20 09:15:00']);
        $msg = rh_settings_base_pair_refusal($pdo, 'facebook_page_id', $d, '1111111');
        ok('pair refusal names who and when from a real setting_change row', is_string($msg) && strpos($msg, 'changed by zz_other at 20 Nov 2026 09:15') !== false, (string)$msg);
        ok('pasted token for the Page stored now -> allowed', rh_settings_base_pair_refusal($pdo, 'facebook_page_id', $d, '2222222') === null);
        $set->execute(['facebook_page_id', '1111111']);
        $dw = rh_settings_base_decide($pdo, $fbBaseMap, 'facebook_page_id', '3333333', '');
        ok('pasted token whose Page ID is written now -> allowed', $dw['outcome'] === 'write' && rh_settings_base_pair_refusal($pdo, 'facebook_page_id', $dw, '3333333') === null);
        $fbSrc = (string)file_get_contents($root . '/admin/facebook-settings.php');
        ok('Facebook page refuses the whole save when the pair rule says so, before anything is written',
            preg_match('/if \(\$manual !== null && \$key === \'facebook_page_id\'\) \{.*?rh_settings_base_pair_refusal\(\$pdo, \$key, \$d, \(string\)\$value\);\s*if \(\$fbPairRefusal !== null\) \{\s*throw new RuntimeException\(\$fbPairRefusal\);/s', $fbSrc) === 1
            && strpos($fbSrc, 'rh_settings_base_pair_refusal') < strpos($fbSrc, '$stmt->execute([$key, $value]);'));
    } finally {
        $pdo->rollBack();
    }
    ok('helper rows rolled back', (int)$pdo->query("SELECT COUNT(*) FROM site_settings WHERE setting_key LIKE 'zz\\_scs\\_%'")->fetchColumn() === 0);
}

// ── Page harness helpers ──────────────────────────────────────────────────────
function harness(string $page, array $cfg): array {
    global $h;
    $enc = base64_encode(json_encode($cfg));
    $cfgFile = '';
    if (strlen($enc) > 6000) { // a whole Hotel details form is longer than the Windows command line allows: pass it in a file
        $cfgFile = (string)tempnam(sys_get_temp_dir(), 'scc');
        file_put_contents($cfgFile, $enc);
        $enc = '@' . $cfgFile;
    }
    try {
        return harness_run($page, $enc);
    } finally {
        if ($cfgFile !== '') { @unlink($cfgFile); }
    }
}
function harness_run(string $page, string $enc): array {
    global $h;
    $cmd = 'php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($enc) . ' 2>&1';
    $out = shell_exec($cmd);
    if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
    // Every harness run first locks the same two site_settings rows (page_harness.php line 106), so a run started while
    // another session's harness test is open waits and can time out before the page ran (nothing was written). Retry.
    for ($try = 0; $try < 8 && strpos((string)$out, '@@RESULT@@') === false && (stripos((string)$out, 'Lock wait timeout') !== false || strpos((string)$out, 'page_harness.php(106)') !== false); $try++) {
        sleep(5);
        $out = shell_exec($cmd);
    }
    if (stripos((string)$out, 'HARNESS LEAK') !== false) { fwrite(STDERR, "HARNESS LEAK reported - stopping.\n"); exit(3); }
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) {
        if (!empty($r['leak']) || !empty($r['marker_lost'])) { fwrite(STDERR, "HARNESS LEAK (\"leak\":true) - stopping.\n"); exit(3); }
        return $r;
    } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -400), 'rolled_back' => false];
}
/**
 * The posted fields of the form holding _settings_base (or the hidden input named $marker, for a page with several
 * such forms), as a browser would send them (csrf left to the harness).
 */
function form_post(string $html, string $marker = '_settings_base'): array {
    if ($html === '') { return []; }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($doc);
    $form = $xp->query('//input[@name="' . $marker . '"]')->item(0);
    while ($form && strtolower($form->nodeName) !== 'form') { $form = $form->parentNode; }
    if (!$form) { return []; }
    $post = [];
    foreach ($xp->query('.//input|.//textarea|.//select', $form) as $el) {
        $name = $el->getAttribute('name');
        if ($name === '' || $name === 'csrf_token' || $el->hasAttribute('disabled')) { continue; }
        for ($p = $el->parentNode; $p && $p !== $form; $p = $p->parentNode) {
            if ($p instanceof DOMElement && strtolower($p->nodeName) === 'fieldset' && $p->hasAttribute('disabled')) { continue 2; }
        }
        $tag = strtolower($el->nodeName);
        if ($tag === 'input') {
            $type = strtolower($el->getAttribute('type') ?: 'text');
            if (in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true)) { continue; }
            if (in_array($type, ['checkbox', 'radio'], true) && !$el->hasAttribute('checked')) { continue; }
            $val = $el->hasAttribute('value') ? $el->getAttribute('value') : (in_array($type, ['checkbox', 'radio'], true) ? 'on' : '');
        } elseif ($tag === 'textarea') {
            $val = preg_replace('/^\r?\n/', '', $el->textContent);
        } else {
            $val = null;
            foreach ($xp->query('.//option', $el) as $o) {
                $ov = $o->hasAttribute('value') ? $o->getAttribute('value') : $o->textContent;
                if ($val === null || $o->hasAttribute('selected')) { $val = $ov; }
                if ($o->hasAttribute('selected')) { break; }
            }
            $val = (string)$val;
        }
        if (substr($name, -2) === '[]') { $post[substr($name, 0, -2)][] = $val; } else { $post[$name] = $val; }
    }
    return $post;
}
/** GET render (rolled back): [result, html, posted fields, base map]. $marker: see form_post(); $session: extra session state. */
function render(string $page, array $setup, string $marker = '_settings_base', array $session = []): array {
    $file = tempnam(sys_get_temp_dir(), 'scs');
    $cfg = ['method' => 'GET', 'setup' => $setup, 'dump_html' => $file];
    if ($session) { $cfg['session'] = $session; }
    $r = harness($page, $cfg);
    $html = (string)@file_get_contents($file);
    @unlink($file);
    $post = form_post($html, $marker);
    return [$r, $html, $post, rh_settings_base_parse($post['_settings_base'] ?? null)];
}
function flash(array $r): string { return strtolower(implode(' | ', $r['output_flash'] ?? [])); }
function kv(array $r, int $i = 0): array { $o = []; foreach ((array)($r['verify'][$i] ?? []) as $row) { if (is_array($row)) { $o[$row['setting_key']] = $row['setting_value']; } } return $o; }
function sql_set(array $kv): array {
    $out = [];
    foreach ($kv as $k => $v) {
        $out[] = "INSERT INTO site_settings (setting_key, setting_value) VALUES ('" . addslashes($k) . "', '" . addslashes($v) . "') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    }
    return $out;
}
function sql_in(array $keys): string { return "'" . implode("','", array_map('addslashes', $keys)) . "'"; }
function other_log(string $key, string $old, string $new): string {
    return "INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, created_at) VALUES (NULL, 'zz_other', 'setting_change', "
        . "'page=zz.php; key=" . addslashes($key) . "; label=ZZ; old=" . addslashes($old) . "; new=" . addslashes($new) . "', '127.0.0.1', '2026-11-20 09:15:00')";
}
const SCS_MARKER = "INSERT INTO admin_activity_log (user_id, username, action, details, ip_address) VALUES (NULL, 'zz_scs', 'zz_scs_marker', 'test marker', '127.0.0.1')";
const SCS_CHANGES = "SELECT COUNT(*) n FROM admin_activity_log WHERE action = 'setting_change' AND id > (SELECT MAX(id) FROM admin_activity_log WHERE action = 'zz_scs_marker')";
$clean = static function (array $r): bool { return !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']); };

/**
 * The shared page cases. $c: page, s0 (seed SQL for every run) and s0kv (the seeded values), extra (posted besides the form), a/otherA/mineA (the key
 * both change), b/mineB (the key only the person changes), keys (rows to watch), secrets (markers that must never show).
 */
$pageCases = static function (string $title, array $c) use ($clean) {
    echo "== $title ==\n";
    [$g, $html, $form, $base] = render($c['page'], $c['s0']);
    ok("$title: page renders the opened-with copy", is_array($base) && $form !== [], json_encode($g['error'] ?? $g['raw'] ?? '') . ' bytes=' . strlen($html));
    if (!is_array($base)) { return; }
    $secretKeys = array_filter(array_keys($base), 'rh_settings_log_secret_key');
    $leak = [];
    foreach ($c['secrets'] as $s) {
        foreach ([$s, md5($s), md5(rh_settings_base_norm($s))] as $needle) { if (strpos($html, $needle) !== false) { $leak[] = $needle === $s ? 'value' : 'hash'; } }
    }
    ok("$title: no secret key in the copy and no secret (or its hash) in the page source", !$secretKeys && !$leak && !array_intersect(array_keys($base), $c['secret_keys']), json_encode([$secretKeys, $leak]));
    ok("$title: every key the save decides is in the copy", !array_diff($c['decided'], array_keys($base)), json_encode(array_values(array_diff($c['decided'], array_keys($base)))));
    $post = $form + $c['extra'];
    $watch = ["SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (" . sql_in($c['keys']) . ")", SCS_CHANGES,
        "SELECT COUNT(*) n FROM site_settings WHERE setting_key IN (" . sql_in($c['keys']) . ")"];
    $before = harness($c['page'], ['method' => 'GET', 'setup' => $c['s0'], 'verify' => [$watch[0], $watch[2]]]);
    $rowsBefore = (int)($before['verify'][1][0]['n'] ?? -1);

    // 1. Unchanged form, posted as opened: nothing written, nothing logged.
    $r = harness($c['page'], ['post' => $post, 'setup' => array_merge($c['s0'], $c['sentinel_setup'] ?? [], [SCS_MARKER]), 'verify' => array_merge($watch, $c['sentinel_verify'] ?? [])]);
    $now = kv($r);
    ok("$title: unchanged form -> 'Nothing had changed', no setting_change row, no row added", strpos(flash($r), 'nothing had changed') !== false
        && (int)($r['verify'][1][0]['n'] ?? -1) === 0 && (int)($r['verify'][2][0]['n'] ?? -2) === $rowsBefore, flash($r) . ' ' . json_encode([$r['verify'][1] ?? null, $r['verify'][2] ?? null, $rowsBefore, $r['raw'] ?? '']));
    foreach ($c['sentinel'] as $sk => $sv) {
        ok("$title: unchanged form leaves $sk exactly as stored", ($now[$sk] ?? null) === $sv, json_encode($now[$sk] ?? null));
    }
    if (!empty($c['sentinel_verify'])) {
        ok("$title: unchanged form rewrote no setting (updated_at untouched)", (int)($r['verify'][3][0]['n'] ?? -1) === 0, json_encode($r['verify'][3] ?? $r));
    }
    ok("$title: unchanged run rolled back", $clean($r));

    // 2. Someone else changed A; the person changed only B: B saved, A kept.
    $r = harness($c['page'], ['post' => array_merge($post, [$c['b'] => $c['mineB']]), 'setup' => array_merge($c['s0'], sql_set([$c['a'] => $c['otherA']]), [SCS_MARKER]), 'verify' => $watch]);
    $now = kv($r);
    ok("$title: other changed A, person changed B -> B saved, A kept, no refusal", ($now[$c['a']] ?? null) === $c['otherA'] && ($now[$c['b']] ?? null) === $c['storedB']
        && strpos(flash($r), 'not saved') === false && (int)($r['verify'][1][0]['n'] ?? 0) === 1, flash($r) . ' ' . json_encode($now));

    // 3. Both changed A: A refused with who and when, B saved.
    $r = harness($c['page'], ['post' => array_merge($post, [$c['a'] => $c['mineA'], $c['b'] => $c['mineB']]),
        'setup' => array_merge($c['s0'], sql_set([$c['a'] => $c['otherA']]), [other_log($c['a'], $c['s0A'], $c['otherA']), SCS_MARKER]), 'verify' => $watch]);
    $now = kv($r);
    ok("$title: both changed A -> refused, naming zz_other at 20 Nov 2026 09:15; B still saved", ($now[$c['a']] ?? null) === $c['otherA'] && ($now[$c['b']] ?? null) === $c['storedB']
        && strpos(flash($r), 'not saved') !== false && strpos(flash($r), 'changed by zz_other at 20 nov 2026 09:15') !== false
        && strpos(flash($r), 'your other changes were saved') !== false, flash($r) . ' ' . json_encode($now));

    // 4. No copy / a broken copy / a copy missing a setting: the whole save is refused.
    $noBase = $post; unset($noBase['_settings_base']);
    $partial = $base; unset($partial[$c['b']]);
    foreach (['no copy' => $noBase, 'broken copy' => array_merge($post, ['_settings_base' => '{"x":"y"}']),
              'copy without one setting' => array_merge($post, ['_settings_base' => json_encode($partial)])] as $lbl => $p) {
        $r = harness($c['page'], ['post' => array_merge($p, [$c['b'] => $c['mineB']]), 'setup' => array_merge($c['s0'], [SCS_MARKER]), 'verify' => $watch]);
        $now = kv($r);
        ok("$title: $lbl -> reload message, nothing saved", strpos(flash($r), 'reload it and try again') !== false && ($now[$c['b']] ?? null) === ($c['s0kv'][$c['b']] ?? null)
            && (int)($r['verify'][1][0]['n'] ?? -1) === 0, flash($r) . ' ' . json_encode($now[$c['b']] ?? null));
    }
    return ['post' => $post, 'watch' => $watch];
};

// ── Automated Emails ──────────────────────────────────────────────────────────
if ($want('automated-emails')) {
    $aeKeys = ['automated_email_master', 'automated_email_reminder_stages', 'automated_email_test_recipient', 'automated_email_payment_terms_days',
        'automated_email_lookback_days', 'automated_email_quotation_days', 'automated_email_interval_minutes', 'automated_email_bcc_hotel',
        'automated_job_daily_reports_cc', 'automated_job_station_reports_to', 'automated_job_station_reports_cc', 'automated_job_company_statements_cc'];
    $s0 = sql_set(['automated_email_payment_terms_days' => '7', 'automated_email_reminder_stages' => '1,3,7']);
    $pageCases('Automated Emails (admin/automated-emails.php)', [
        'page' => 'admin/automated-emails.php', 's0' => $s0, 'extra' => [],
        'a' => 'automated_email_payment_terms_days', 's0A' => '7', 'otherA' => '30', 'mineA' => '14',
        'b' => 'automated_email_reminder_stages', 'mineB' => '2, 5', 'storedB' => '2,5',
        'keys' => $aeKeys, 'decided' => $aeKeys, 'secrets' => [], 'secret_keys' => [],
        'sentinel' => ['automated_email_payment_terms_days' => '7'],
        'sentinel_setup' => ["UPDATE site_settings SET updated_at = '2000-01-01 00:00:00' WHERE setting_key IN (" . sql_in($aeKeys) . ") OR setting_key LIKE 'automated\\_job\\_%'"],
        'sentinel_verify' => ["SELECT COUNT(*) n FROM site_settings WHERE (setting_key IN (" . sql_in($aeKeys) . ") OR setting_key LIKE 'automated\\_job\\_%') AND updated_at > '2000-01-02'"],
        's0kv' => ['automated_email_payment_terms_days' => '7', 'automated_email_reminder_stages' => '1,3,7'],
    ]);
}

// ── WhatsApp ──────────────────────────────────────────────────────────────────
if ($want('whatsapp')) {
    $waS0kv = ['whatsapp_enabled' => '0', 'whatsapp_notifications_enabled' => '0', 'whatsapp_number' => '+265888000111', 'whatsapp_hotel_number' => 'ZZSTALE',
        'whatsapp_phone_id' => '', 'whatsapp_meta_phone_number_id' => '', 'whatsapp_business_id' => '', 'whatsapp_meta_business_account_id' => '',
        'whatsapp_admin_numbers' => '+265888000222', 'whatsapp_confirmed_template' => 'booking_confirmed', 'whatsapp_cancelled_template' => 'booking_cancelled',
        'whatsapp_api_token' => 'wa1:ZZTOKENMARKER', 'whatsapp_meta_access_token' => 'wa1:ZZTOKENMARKER'];
    $waKeys = array_keys($waS0kv);
    $s0 = sql_set($waS0kv);
    $c = [
        'page' => 'admin/whatsapp-settings.php', 's0' => $s0, 'extra' => [],
        'a' => 'whatsapp_admin_numbers', 's0A' => '+265888000222', 'otherA' => '+265999000111', 'mineA' => '+265999000222',
        'b' => 'whatsapp_confirmed_template', 'mineB' => 'zz_confirmed', 'storedB' => 'zz_confirmed',
        'keys' => $waKeys, 'secrets' => ['wa1:ZZTOKENMARKER', 'ZZTOKENMARKER'], 'secret_keys' => ['whatsapp_api_token', 'whatsapp_meta_access_token'],
        'decided' => ['whatsapp_enabled', 'whatsapp_phone_id', 'whatsapp_business_id', 'whatsapp_number', 'whatsapp_notify_on_booking', 'whatsapp_notify_on_confirmation',
            'whatsapp_notify_on_cancellation', 'whatsapp_notify_on_checkin', 'whatsapp_notify_on_checkout', 'whatsapp_guest_notifications',
            'whatsapp_hotel_notifications', 'whatsapp_admin_numbers', 'whatsapp_confirmed_template', 'whatsapp_cancelled_template'],
        'sentinel' => ['whatsapp_hotel_number' => 'ZZSTALE', 'whatsapp_api_token' => 'wa1:ZZTOKENMARKER'],
    ];
    $out = $pageCases('WhatsApp settings (admin/whatsapp-settings.php)', $c + ['s0kv' => $waS0kv]);
    if (is_array($out)) {
        // Mirrors: an old-name copy someone else changed is never a conflict; it follows its setting when that is written.
        $r = harness($c['page'], ['post' => array_merge($out['post'], ['whatsapp_confirmed_template' => 'zz_confirmed']),
            'setup' => array_merge($s0, sql_set(['whatsapp_hotel_number' => 'ZZOTHER']), [SCS_MARKER]), 'verify' => $out['watch']]);
        $now = kv($r);
        ok('WhatsApp: old-name copy changed by someone else never refuses a save', strpos(flash($r), 'not saved') === false && ($now['whatsapp_hotel_number'] ?? '') === 'ZZOTHER'
            && ($now['whatsapp_confirmed_template'] ?? '') === 'zz_confirmed', flash($r) . json_encode($now));
        $r = harness($c['page'], ['post' => array_merge($out['post'], ['whatsapp_number' => '+265888000999']), 'setup' => array_merge($s0, [SCS_MARKER]), 'verify' => $out['watch']]);
        $now = kv($r);
        ok('WhatsApp: a changed number also updates its old-name copy, logged once', ($now['whatsapp_number'] ?? '') === '+265888000999' && ($now['whatsapp_hotel_number'] ?? '') === '+265888000999'
            && (int)($r['verify'][1][0]['n'] ?? 0) === 1 && ($now['whatsapp_api_token'] ?? '') === 'wa1:ZZTOKENMARKER', flash($r) . json_encode($now));
    }
}

// ── Facebook ──────────────────────────────────────────────────────────────────
if ($want('facebook')) {
    $fbS0kv = ['facebook_posting_enabled' => '0', 'facebook_page_id' => '1234567', 'facebook_page_name' => 'ZZ Name ', 'facebook_default_hashtags' => '#zz #test',
        'facebook_app_id' => '', 'facebook_login_config_id' => '', 'facebook_app_secret' => 'fb1:ZZSECRETMARKER', 'facebook_page_access_token' => 'fb1:ZZTOKENMARKERFB'];
    $s0 = sql_set($fbS0kv);
    $c = [
        'page' => 'admin/facebook-settings.php', 's0' => $s0, 'extra' => ['action' => 'save'],
        'a' => 'facebook_default_hashtags', 's0A' => '#zz #test', 'otherA' => '#other', 'mineA' => '#mine',
        'b' => 'facebook_page_name', 'mineB' => 'ZZ Mine', 'storedB' => 'ZZ Mine',
        'keys' => array_keys($fbS0kv), 'secrets' => ['fb1:ZZSECRETMARKER', 'ZZSECRETMARKER', 'fb1:ZZTOKENMARKERFB', 'ZZTOKENMARKERFB'],
        'secret_keys' => ['facebook_app_secret', 'facebook_page_access_token'],
        'decided' => ['facebook_posting_enabled', 'facebook_page_id', 'facebook_page_name', 'facebook_default_hashtags', 'facebook_rooms_enabled', 'facebook_events_enabled',
            'facebook_conference_enabled', 'facebook_gym_enabled', 'facebook_menu_enabled', 'facebook_post_log_enabled', 'facebook_app_id', 'facebook_login_config_id'],
        'sentinel' => ['facebook_page_name' => 'ZZ Name ', 'facebook_app_secret' => 'fb1:ZZSECRETMARKER', 'facebook_page_access_token' => 'fb1:ZZTOKENMARKERFB'],
    ];
    $out = $pageCases('Facebook settings (admin/facebook-settings.php)', $c + ['s0kv' => $fbS0kv]);
    if (is_array($out)) {
        // Connect with a refused setting: the refusal is shown and the page never goes on to Facebook.
        $r = harness($c['page'], ['post' => array_merge($out['post'], ['action' => 'connect', 'facebook_default_hashtags' => '#mine']),
            'setup' => array_merge($s0, sql_set(['facebook_default_hashtags' => '#other']), [SCS_MARKER]), 'verify' => $out['watch'], 'session_keys' => ['fb_flash']]);
        $fl = $r['session']['fb_flash'] ?? null;
        ok('Facebook: Connect with a refused setting shows the refusal and stops before Facebook', is_array($fl) && ($fl['type'] ?? '') === 'error'
            && stripos((string)($fl['msg'] ?? ''), 'not saved') !== false && (kv($r)['facebook_default_hashtags'] ?? '') === '#other', json_encode($fl) . json_encode($r['raw'] ?? ''));
        ok('Facebook: connect run rolled back', $clean($r));
    }
}

// ── Hotel Settings (task 19a): Hotel details & policies + Review sources on admin/booking-settings.php ─────────────
// Only these two forms are posted here (the rest of the page is task 19b). The password lock on bank details and the
// restaurant service charge (includes/settings-lock.php) is checked before "save only what changed".
if ($want('hotel-settings')) {
    require_once $root . '/admin/includes/permissions.php';
    require_once $root . '/admin/includes/hotel-details-settings.php';
    echo "== Hotel Settings: money values compared as numbers (helper, rolled back) ==\n";
    // VAT is saved on the accounting dashboard and the levy on this page's own form (task 19b): their keys are proven here.
    $pdo->beginTransaction();
    try {
        foreach (sql_set(['vat_rate' => '16.50', 'tourism_levy_percent' => '1.00']) as $q) { $pdo->exec($q); }
        $mSpec = ['vat_rate' => '0', 'tourism_levy_percent' => '0'];
        $mb = rh_settings_base_map($pdo, $mSpec);
        $dec = static function (string $k, string $v) use ($pdo, &$mb, $mSpec) { return rh_settings_base_decide($pdo, $mb, $k, $v, $mSpec[$k])['outcome']; };
        ok('VAT rate 16.50 stored, 16.5 typed -> no change', $dec('vat_rate', '16.5') === 'skip' && $dec('vat_rate', '16.500') === 'skip');
        ok('VAT rate 16.50 stored, 17 typed -> written', $dec('vat_rate', '17') === 'write');
        ok('Tourism levy 1.00 stored, 1 typed -> no change; 1.5 -> written', $dec('tourism_levy_percent', '1') === 'skip' && $dec('tourism_levy_percent', '1.5') === 'write');
        $pdo->exec(sql_set(['vat_rate' => '16.5'])[0]);
        ok('VAT rate re-stored as 16.5 by someone else (same number) -> 17 still written, no conflict', $dec('vat_rate', '17') === 'write');
        $pdo->exec(sql_set(['vat_rate' => '18'])[0]);
        ok('VAT rate changed to 18 by someone else -> 17 refused', $dec('vat_rate', '17') === 'conflict');
    } finally {
        $pdo->rollBack();
    }

    $bs = 'admin/booking-settings.php';
    $hdS0kv = ['restaurant_tagline' => 'ZZ tag 0', 'restaurant_receipt_footer' => 'ZZ foot 0', 'restaurant_service_charge_pct' => '5.50',
        'bank_name' => 'ZZ Bank', 'bank_account_number' => '0012345', 'reviews_google_place_id' => 'ChIJzzSeed000001'];
    $hdKeys = array_keys($hdS0kv);
    $s0 = sql_set($hdS0kv);
    $hAdmin = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn(); // the harness user
    $unlock = static function (array $groups) use ($hAdmin): array {
        $o = [];
        foreach ($groups as $gname) { $o[$gname] = ['uid' => $hAdmin, 'exp' => time() + 600]; }
        return ['rh_settings_lock' => $o];
    };
    $hdWatch = ["SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (" . sql_in($hdKeys) . ")",
        "SELECT details FROM admin_activity_log WHERE action = 'setting_change' AND id > (SELECT MAX(id) FROM admin_activity_log WHERE action = 'zz_scs_marker')",
        "SELECT MD5(COALESCE((SELECT setting_value FROM site_settings WHERE setting_key = 'reviews_google_places_key'), '')) h"]; // the key itself is never read out
    $hdRun = static function (array $post, array $setup = [], array $session = []) use ($bs, $s0, $hdWatch): array {
        unset($post['test_review_sources']); // never the live connection test
        $cfg = ['post' => $post, 'setup' => array_merge($s0, $setup, [SCS_MARKER]), 'verify' => $hdWatch];
        if ($session) { $cfg['session'] = $session; }
        return harness($bs, $cfg);
    };
    $nChg = static function (array $r): int { return isset($r['verify'][1]) && is_array($r['verify'][1]) ? count($r['verify'][1]) : -1; };
    $chgList = static function (array $r): string { return json_encode(array_column((array)($r['verify'][1] ?? []), 'details')); };
    $same = static function (array $r, array $keys) use ($hdS0kv): bool {
        $now = kv($r);
        foreach ($keys as $k) { if (($now[$k] ?? null) !== $hdS0kv[$k]) { return false; } }
        return true;
    };

    echo "== Hotel Settings: Hotel details & policies (admin/booking-settings.php) ==\n";
    [$g, $html, $form, $base] = render($bs, $s0, 'save_hotel_details');
    ok('Hotel details: form renders the opened-with copy', is_array($base) && isset($form['save_hotel_details']), json_encode($g['error'] ?? $g['raw'] ?? '') . ' bytes=' . strlen($html));
    if (is_array($base)) {
        ok('Hotel details: form shows the stored value (not a stale cached one)', ($form['restaurant_tagline'] ?? null) === 'ZZ tag 0' && ($form['restaurant_receipt_footer'] ?? null) === 'ZZ foot 0', json_encode([$form['restaurant_tagline'] ?? null, $form['restaurant_receipt_footer'] ?? null]));
        ok('Hotel details: locked bank and service charge fields are not posted without an unlock', !isset($form['bank_name']) && !isset($form['restaurant_service_charge_pct']));
        ok('Hotel details: every field the save decides is in the copy', !array_diff(array_keys(rh_hotel_details_base_spec()), array_keys($base)), json_encode(array_values(array_diff(array_keys(rh_hotel_details_base_spec()), array_keys($base)))));

        // 1. skip: the whole form posted as opened.
        $r = $hdRun($form);
        ok('Hotel details: unchanged form -> "No changes to save", no setting_change row, nothing changed', strpos(flash($r), 'no changes to save') !== false && $nChg($r) === 0
            && $same($r, $hdKeys), flash($r) . ' changed=' . $chgList($r) . ' ' . json_encode($r['raw'] ?? ''));
        ok('Hotel details: unchanged run rolled back', $clean($r));

        // 2. write: someone else changed the tagline; this person changed only the receipt footer.
        $r = $hdRun(array_merge($form, ['restaurant_receipt_footer' => 'ZZ foot mine']), sql_set(['restaurant_tagline' => 'ZZ tag other']));
        $now = kv($r);
        ok('Hotel details: other changed tagline, person changed footer -> footer saved, tagline kept', ($now['restaurant_tagline'] ?? null) === 'ZZ tag other'
            && ($now['restaurant_receipt_footer'] ?? null) === 'ZZ foot mine' && strpos(flash($r), '1 setting(s) saved') !== false && strpos(flash($r), 'not saved') === false
            && $nChg($r) === 1, flash($r) . ' ' . json_encode($now) . ' changed=' . $chgList($r));

        // 3. conflict: both changed the tagline.
        $r = $hdRun(array_merge($form, ['restaurant_tagline' => 'ZZ tag mine', 'restaurant_receipt_footer' => 'ZZ foot mine']),
            array_merge(sql_set(['restaurant_tagline' => 'ZZ tag other']), [other_log('restaurant_tagline', 'ZZ tag 0', 'ZZ tag other')]));
        $now = kv($r);
        ok('Hotel details: both changed tagline -> refused naming zz_other at 20 Nov 2026 09:15; footer still saved', ($now['restaurant_tagline'] ?? null) === 'ZZ tag other'
            && ($now['restaurant_receipt_footer'] ?? null) === 'ZZ foot mine' && strpos(flash($r), 'not saved: restaurant tagline was changed by zz_other at 20 nov 2026 09:15') !== false
            && strpos(flash($r), 'your other changes were saved (1 setting(s))') !== false, flash($r) . ' ' . json_encode($now));

        // 4. no_base: no copy, a broken copy, a copy missing the footer -> the whole save refused.
        $noBase = $form; unset($noBase['_settings_base']);
        $partial = $base; unset($partial['restaurant_receipt_footer']);
        foreach (['no copy' => $noBase, 'broken copy' => array_merge($form, ['_settings_base' => '{"x":"y"}']),
                  'copy without one setting' => array_merge($form, ['_settings_base' => json_encode($partial)])] as $lbl => $p) {
            $r = $hdRun(array_merge($p, ['restaurant_tagline' => 'ZZ tag mine', 'restaurant_receipt_footer' => 'ZZ foot mine']));
            ok("Hotel details: $lbl -> reload message, nothing saved", strpos(flash($r), 'reload it and try again') !== false && $same($r, $hdKeys) && $nChg($r) === 0,
                flash($r) . ' changed=' . $chgList($r));
        }

        // 5. Locked money keys: the lock check comes first (even with no copy, or a copy someone else made stale).
        $r = $hdRun(array_merge($noBase, ['bank_name' => 'ZZ Bank mine', 'restaurant_tagline' => 'ZZ tag mine']));
        ok('Hotel details: bank name without unlock and no copy -> unlock message (not reload), nothing saved', strpos(flash($r), 'unlock the bank') !== false
            && strpos(flash($r), 'reload it') === false && $same($r, $hdKeys) && $nChg($r) === 0, flash($r) . ' changed=' . $chgList($r));
        $r = $hdRun(array_merge($form, ['restaurant_service_charge_pct' => '6', 'restaurant_tagline' => 'ZZ tag mine']),
            array_merge(sql_set(['restaurant_tagline' => 'ZZ tag other']), [other_log('restaurant_tagline', 'ZZ tag 0', 'ZZ tag other')]));
        $now = kv($r);
        ok('Hotel details: service charge without unlock, stale copy -> unlock message (not the conflict), nothing saved', strpos(flash($r), 'unlock the restaurant service charge') !== false
            && strpos(flash($r), 'changed by') === false && ($now['restaurant_service_charge_pct'] ?? null) === '5.50' && ($now['restaurant_tagline'] ?? null) === 'ZZ tag other'
            && $nChg($r) === 0, flash($r) . ' ' . json_encode($now));

        // 6. Unlocked money fields: numbers compared as numbers, bank text compared trimmed, leading zeros kept.
        $ul = $unlock(['bank', 'service_charge']);
        [$gU, , $formU, $baseU] = render($bs, $s0, 'save_hotel_details', $ul);
        ok('Hotel details (unlocked): bank and service charge fields are in the form and the copy', isset($formU['bank_name'], $formU['restaurant_service_charge_pct'], $formU['bank_account_number'])
            && is_array($baseU) && isset($baseU['restaurant_service_charge_pct'], $baseU['bank_name']), json_encode(array_keys($formU)));
        if (is_array($baseU)) {
            $r = $hdRun($formU, [], $ul);
            ok('Hotel details (unlocked): unchanged -> no change, 5.50 and 0012345 kept exactly', strpos(flash($r), 'no changes to save') !== false && $same($r, $hdKeys) && $nChg($r) === 0,
                flash($r) . ' shown=' . json_encode($formU['restaurant_service_charge_pct']) . ' changed=' . $chgList($r));
            $r = $hdRun(array_merge($formU, ['restaurant_service_charge_pct' => '5.5', 'bank_name' => ' ZZ Bank ']), [], $ul);
            ok('Hotel details (unlocked): service charge 5.5 typed over 5.50, bank name with spaces -> no change, stored values kept', strpos(flash($r), 'no changes to save') !== false
                && $same($r, $hdKeys) && $nChg($r) === 0, flash($r) . ' changed=' . $chgList($r));
            $r = $hdRun(array_merge($formU, ['bank_account_number' => '12345']), [], $ul);
            $now = kv($r);
            ok('Hotel details (unlocked): account 0012345 -> 12345 is a real change (leading zeros count), saved', ($now['bank_account_number'] ?? null) === '12345'
                && strpos(flash($r), '1 setting(s) saved') !== false && $nChg($r) === 1, flash($r) . ' ' . json_encode($now));
            $r = $hdRun(array_merge($formU, ['restaurant_service_charge_pct' => '6']), sql_set(['restaurant_service_charge_pct' => '5.5']), $ul);
            $now = kv($r);
            ok('Hotel details (unlocked): service charge re-stored as 5.5 by someone else (same number) -> 6 saved, no conflict', ($now['restaurant_service_charge_pct'] ?? null) === '6'
                && strpos(flash($r), 'not saved') === false && $nChg($r) === 1, flash($r) . ' ' . json_encode($now));
            $r = $hdRun(array_merge($formU, ['restaurant_service_charge_pct' => '6']), array_merge(sql_set(['restaurant_service_charge_pct' => '7']),
                [other_log('restaurant_service_charge_pct', '5.50', '7')]), $ul);
            $now = kv($r);
            ok('Hotel details (unlocked): service charge changed to 7 by someone else -> 6 refused naming zz_other, 7 kept', ($now['restaurant_service_charge_pct'] ?? null) === '7'
                && strpos(flash($r), 'changed by zz_other at 20 nov 2026 09:15') !== false && $nChg($r) === 0, flash($r) . ' ' . json_encode($now));
            ok('Hotel details (unlocked): runs rolled back', $clean($r));
        }
    }

    echo "== Hotel Settings: Review sources (admin/booking-settings.php) ==\n";
    [$g, $html, $rform, $rbase] = render($bs, $s0, 'save_review_sources');
    ok('Review sources: form renders the opened-with copy (Place ID only, never an API key)', is_array($rbase) && array_keys($rbase) === ['reviews_google_place_id']
        && ($rform['google_place_id'] ?? null) === 'ChIJzzSeed000001', json_encode([$rbase, $rform['google_place_id'] ?? null, $g['error'] ?? $g['raw'] ?? '']));
    if (is_array($rbase)) {
        $r = $hdRun($rform);
        ok('Review sources: unchanged -> "No changes to save", nothing written', strpos(flash($r), 'no changes to save') !== false && $same($r, $hdKeys) && $nChg($r) === 0,
            flash($r) . ' changed=' . $chgList($r));
        $keyHash = (string)($r['verify'][2][0]['h'] ?? '');
        $r = $hdRun(array_merge($rform, ['google_place_id' => 'ChIJzzMine0000001']));
        ok('Review sources: Place ID changed -> saved', (kv($r)['reviews_google_place_id'] ?? null) === 'ChIJzzMine0000001' && strpos(flash($r), 'place id saved') !== false
            && $nChg($r) === 1, flash($r));
        $r = $hdRun(array_merge($rform, ['google_place_id' => 'ChIJzzMine0000001']),
            array_merge(sql_set(['reviews_google_place_id' => 'ChIJzzOther000001']), [other_log('reviews_google_place_id', 'ChIJzzSeed000001', 'ChIJzzOther000001')]));
        ok('Review sources: both changed Place ID -> refused naming zz_other at 20 Nov 2026 09:15', (kv($r)['reviews_google_place_id'] ?? null) === 'ChIJzzOther000001'
            && strpos(flash($r), 'not saved: google place id was changed by zz_other at 20 nov 2026 09:15') !== false && $nChg($r) === 0, flash($r));
        $rNo = $rform; unset($rNo['_settings_base']);
        $r = $hdRun(array_merge($rNo, ['google_place_id' => 'ChIJzzMine0000001', 'google' => 'ZZDUMMYKEYzz0123456789abcdef'])); // made-up key, never sent anywhere allow-secret
        ok('Review sources: no copy -> reload message; neither the Place ID nor a typed key is saved', strpos(flash($r), 'reload it and try again') !== false
            && strpos(flash($r), 'key saved') === false && $same($r, $hdKeys) && (string)($r['verify'][2][0]['h'] ?? '') === $keyHash && $keyHash !== '' && $nChg($r) === 0, flash($r));
        ok('Review sources: runs rolled back', $clean($r));
    }
}

echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
