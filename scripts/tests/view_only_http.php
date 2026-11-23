<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * View only over real HTTP (dashboard handover notes, first-login plan C2). view_only.php checks the rule functions;
 * this checks the whole request path (api-init -> rh_licence_guard_request -> rh_view_only_guard), which never applies
 * on the command line (rh_view_only_reason() is '' under CLI).
 *
 *   php scripts/tests/view_only_http.php --db     (Liwonde test DB only; refuses a DB listed in .claude/LIVE_TARGETS)
 *
 * Starts `php -S` with scripts/tests/dev-router.php on a free local port, signs in as a test user through /__devlogin,
 * grants that user the view_only permission for the run, then:
 *   - POST admin/api/handover-notes.php (write)       -> 403, view_only true, X-RH-View-Only header, no note saved
 *   - POST admin/api/dashboard-seen.php (Got it)      -> allowed (success)
 *   - with view_only removed, the same write          -> saved (proves the refusal came from view only)
 * Afterwards the user's view_only row, welcome preference and any note/read/log rows this run made are put back.
 */
$root = dirname(__DIR__, 2);
chdir($root);
if (!in_array('--db', $argv, true)) { echo "view_only_http: needs --db (Liwonde only). Nothing run.\n"; exit(0); }
if (!function_exists('curl_init')) { echo "view_only_http: php curl extension missing. Nothing run.\n"; exit(1); }

require $root . '/config/database.php';
$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$live = (string)@file_get_contents($root . '/.claude/LIVE_TARGETS');
if ($db === '' || stripos($live, $db) !== false) { fwrite(STDERR, "Refused: $db is a live target.\n"); exit(1); }

$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } }

$user = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role <> 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$user) { echo "view_only_http: no active non-admin user on $db. Nothing run.\n"; exit(1); }
$uid = (int)$user['id'];

// State to put back.
$permRow = $pdo->prepare("SELECT id, is_granted FROM user_permissions WHERE user_id = ? AND permission_key = 'view_only'");
$permRow->execute([$uid]);
$permBefore = $permRow->fetch(PDO::FETCH_ASSOC) ?: null;
$prefSt = $pdo->prepare("SELECT preference_value FROM admin_user_preferences WHERE user_id = ? AND preference_key = 'dashboard_welcome_dismissed'");
$prefSt->execute([$uid]);
$prefBefore = $prefSt->fetchColumn();
$maxNote = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM shift_handover_notes')->fetchColumn();
$maxLog = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM admin_activity_log')->fetchColumn();
$marker = 'zz view_only_http ' . bin2hex(random_bytes(4));

// An existing view_only row is switched in place (granted_by / created_at kept); otherwise a row is added and removed.
$setViewOnly = function (bool $on) use ($pdo, $uid, $permBefore): void {
    if ($permBefore !== null) {
        $pdo->prepare("UPDATE user_permissions SET is_granted = ? WHERE id = ?")->execute([$on ? 1 : 0, (int)$permBefore['id']]);
    } elseif ($on) {
        $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'view_only', 1)")->execute([$uid]);
    } else {
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission_key = 'view_only'")->execute([$uid]);
    }
};

// Free port + server.
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$base = "http://127.0.0.1:$port";
$null = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, $root . '/scripts/tests/dev-router.php'],
    [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $root);
$jar = tempnam(sys_get_temp_dir(), 'voh');

$req = function (string $path, ?array $post = null) use ($base, $jar): array {
    $ch = curl_init($base . $path);
    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$headers) { $headers[] = trim($h); return strlen($h); },
    ]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body, $headers];
};

try {
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) { usleep(200000); $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); $up = true; } }
    check($up, 'dev server started');

    $setViewOnly(true);
    [$code, $html] = $req('/__devlogin?to=dashboard.php&as=' . rawurlencode($user['username']));
    check($code === 200 && strpos($html, 'rhViewOnlyBanner') !== false, 'signed in as a view-only user (banner shows)', "HTTP $code");
    preg_match('/const _dashCsrf = "([^"]+)"/', $html, $m);
    $csrf = $m[1] ?? '';
    check($csrf !== '', 'dashboard gives a CSRF token');

    [$code, $body, $hdr] = $req('/admin/api/handover-notes.php', ['action' => 'write', 'body' => $marker, 'csrf_token' => $csrf]);
    $j = json_decode($body, true);
    check($code === 403, 'view only: writing a handover note answers 403', "HTTP $code $body");
    check(is_array($j) && ($j['view_only'] ?? null) === true && ($j['success'] ?? null) === false, 'view only: the answer says view only', $body);
    check(in_array('X-RH-View-Only: 1', $hdr, true), 'view only: X-RH-View-Only header sent');
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM shift_handover_notes WHERE body = ?');
    $cnt->execute([$marker]);
    check((int)$cnt->fetchColumn() === 0, 'view only: no note saved');

    [$code, $body] = $req('/admin/api/dashboard-seen.php', ['action' => 'welcome_dismissed', 'csrf_token' => $csrf]);
    $j = json_decode($body, true);
    check($code === 200 && is_array($j) && ($j['success'] ?? null) === true, 'view only: Got it on the welcome card still works', "HTTP $code $body");

    $setViewOnly(false);
    [$code, $body] = $req('/admin/api/handover-notes.php', ['action' => 'write', 'body' => $marker, 'csrf_token' => $csrf]);
    $j = json_decode($body, true);
    check($code === 200 && is_array($j) && ($j['success'] ?? null) === true, 'not view only: the same write is saved (the refusal was view only)', "HTTP $code $body");
} finally {
    // Put everything back.
    if ($permBefore !== null) {
        $pdo->prepare("UPDATE user_permissions SET is_granted = ? WHERE id = ?")->execute([$permBefore['is_granted'], (int)$permBefore['id']]);
    } else {
        $setViewOnly(false);
    }
    if ($prefBefore === false) {
        $pdo->prepare("DELETE FROM admin_user_preferences WHERE user_id = ? AND preference_key = 'dashboard_welcome_dismissed'")->execute([$uid]);
    } else {
        $pdo->prepare("UPDATE admin_user_preferences SET preference_value = ? WHERE user_id = ? AND preference_key = 'dashboard_welcome_dismissed'")->execute([$prefBefore, $uid]);
    }
    $ids = $pdo->prepare('SELECT id FROM shift_handover_notes WHERE id > ? AND body = ?');
    $ids->execute([$maxNote, $marker]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $nid) {
        $pdo->prepare('DELETE FROM shift_handover_reads WHERE note_id = ?')->execute([(int)$nid]);
        $pdo->prepare('DELETE FROM shift_handover_notes WHERE id = ?')->execute([(int)$nid]);
    }
    // This run's rows: newer than the start and this test user (test DB: no one else signs in as them meanwhile).
    $pdo->prepare("DELETE FROM admin_activity_log WHERE id > ? AND user_id = ?")->execute([$maxLog, $uid]);
    if (is_resource($srv)) {
        proc_terminate($srv);
        proc_close($srv);
    }
    @unlink($jar);
}

echo "view_only_http: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
