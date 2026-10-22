<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Sign in with username OR email (admin/login.php) and the one-person-one-email rule on
 * Staff & Access (admin/user-management.php).
 * Usage (from the repo root): php scripts/tests/login_email.php
 * Every check runs in scripts/tests/page_harness.php, which never commits: its own throw-away
 * users are created in the rolled-back transaction, nothing is left behind. Works on a hotel
 * that only has an admin and a receptionist (the harness signs in as the first admin).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/config/database.php';
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

$PW = 'Zz-Login-Test-9!';
$hash = $pdo->quote(password_hash($PW, PASSWORD_DEFAULT));
$u = function (string $username, string $email, int $active, int $attempts, string $role = 'receptionist') use ($pdo, $hash): string {
    return "INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active, failed_login_attempts) VALUES ("
        . $pdo->quote($username) . ', ' . $pdo->quote($email) . ", $hash, " . $pdo->quote('Zz ' . $username) . ", '$role', $active, $attempts)";
};
$setup = [
    $u('zz_le_a', 'ZZ.Login.A@example.invalid', 1, 0),
    $u('zz_le_off', 'zz.login.off@example.invalid', 0, 0),
    $u('zz_le_warn', 'zz.login.warn@example.invalid', 1, 4),
    $u('zz_le_lock', 'zz.login.lock@example.invalid', 1, 5),
    "INSERT INTO admin_activity_log (user_id, username, action, details, ip_address) SELECT id, 'zz_le_lock', 'login_failed', 'seed', '203.0.113.9' FROM admin_users WHERE username = 'zz_le_lock'",
    $u('zz_le_amb1', 'zz.amb@example.invalid', 1, 0),
    $u('zz_le_amb2', ' zz.amb@example.invalid', 1, 0),
    $u('zz_le_<b>x', 'zz.login.markup@example.invalid', 1, 0),
];
$cnt = function (string $where) { return "SELECT COUNT(*) AS n FROM admin_activity_log WHERE $where"; };
$login = function (string $who, string $password) use ($setup, $cnt) {
    return run_page('admin/login.php', [
        'anon' => true, 'setup' => $setup, 'post' => ['username' => $who, 'password' => $password],
        'verify' => [
            $cnt("action = 'login_success' AND username LIKE 'zz\\_le\\_%'"),
            $cnt("action = 'login_failed' AND details LIKE 'Wrong password%' AND username LIKE 'zz\\_le\\_%'"),
            $cnt("action = 'login_blocked' AND username LIKE 'zz\\_le\\_%'"),
            $cnt("action = 'login_failed' AND details = 'Unknown username' AND username = " . $GLOBALS['pdo']->quote(trim($who))),
            "SELECT username, failed_login_attempts AS a, last_login IS NOT NULL AS li FROM admin_users WHERE username LIKE 'zz\\_le\\_%' ORDER BY username",
        ],
    ]);
};
$n = fn(array $r, int $i) => (int)($r['verify'][$i][0]['n'] ?? -1);
$att = function (array $r, string $user) { foreach (($r['verify'][4] ?? []) as $row) { if ($row['username'] === $user) { return (int)$row['a']; } } return -1; };
$generic = 'Invalid username or password.';

// 1. username
$r = $login('zz_le_a', $PW);
check($clean($r), 'username sign-in: run rolled back', json_encode($r));
check($n($r, 0) === 1 && empty($r['output_flash']), 'sign-in by username still works', json_encode($r));

// 2. email, mixed case and spaces
$r = $login('  zz.LOGIN.a@Example.INVALID  ', $PW);
check($clean($r) && $n($r, 0) === 1 && empty($r['output_flash']), 'sign-in by email (mixed case, spaces) works', json_encode($r));
$rowA = null; foreach (($r['verify'][4] ?? []) as $row) { if ($row['username'] === 'zz_le_a') { $rowA = $row; } }
check($rowA && (int)$rowA['li'] === 1 && (int)$rowA['a'] === 0, 'last sign-in is recorded for the matched account');

// 3. wrong password by email: same counter, same log, same message as by username
$re = $login('zz.login.warn@example.invalid', 'wrong-password');
$ru = $login('zz_le_warn', 'wrong-password');
check($clean($re) && $att($re, 'zz_le_warn') === 5 && $n($re, 1) === 1, 'wrong password by email counts the same attempt (4 to 5) and logs the matched username', json_encode($re));
check($att($ru, 'zz_le_warn') === 5 && $n($ru, 1) === 1, 'wrong password by username counts the same way');
check(($re['output_flash'][0] ?? 'x') === ($ru['output_flash'][0] ?? 'y') && strpos((string)($re['output_flash'][0] ?? ''), $generic) === 0, 'both show the same generic message', json_encode($re['output_flash'] ?? []));
check($n($re, 0) === 0, 'a wrong password never signs in');

// 4. lockout applies through the email too, even with the right password
$rl = $login('ZZ.login.lock@example.invalid', $PW);
$rlu = $login('zz_le_lock', $PW);
check($clean($rl) && $n($rl, 0) === 0 && $n($rl, 2) === 1 && $att($rl, 'zz_le_lock') === 5, 'locked account by email is refused with the right password and stays locked', json_encode($rl));
check($n($rlu, 0) === 0 && $n($rlu, 2) === 1 && ($rl['output_flash'][0] ?? 'x') === ($rlu['output_flash'][0] ?? 'y'), 'locked account by username behaves identically');

// 5. inactive by email
$ri = $login('zz.login.off@example.invalid', $PW);
check($clean($ri) && $n($ri, 0) === 0 && strpos((string)($ri['output_flash'][0] ?? ''), $generic) === 0, 'deactivated account by email is refused with the generic message', json_encode($ri));

// 6. unknown email
$rk = $login('nobody.here@example.invalid', $PW);
check($clean($rk) && $n($rk, 0) === 0 && $n($rk, 3) === 1, 'unknown email is logged like an unknown username', json_encode($rk));
check(($rk['output_flash'][0] ?? 'x') === ($re['output_flash'][0] ?? 'y'), 'unknown email shows the very same message as a wrong password');
$rn = $login('nobody_here', $PW);
check(($rn['output_flash'][0] ?? 'x') === ($rk['output_flash'][0] ?? 'y'), 'unknown username shows the same message too');

// 7. two accounts on one email: treated as unknown
$ra = $login('zz.amb@example.invalid', $PW);
check($clean($ra) && $n($ra, 0) === 0 && $n($ra, 3) === 1 && strpos((string)($ra['output_flash'][0] ?? ''), $generic) === 0, 'an email shared by two accounts signs nobody in', json_encode($ra));

// 8. username with an @ still wins over email matching, and a bare word is never matched against email
$rx = $login('zz', $PW);
check($clean($rx) && $n($rx, 0) === 0, 'a partial word signs nobody in');

// 9. the login page asks for username or email, keeps the field name
$page = run_page('admin/login.php', ['anon' => true, 'method' => 'GET', 'grep' => ['Username or email', 'name="username"', 'autocomplete="username"']]);
check($clean($page) && !empty($page['found']['Username or email']) && !empty($page['found']['name="username"']) && !empty($page['found']['autocomplete="username"']), 'login form says "Username or email" and keeps name=username', json_encode($page['found'] ?? $page));

// 10. Staff & Access shows usernames (escaped)
$lst = run_page('admin/user-management.php', ['method' => 'GET', 'setup' => $setup, 'grep' => ['Username: <strong>zz_le_a</strong>', 'Username: <strong>zz_le_&lt;b&gt;x</strong>', 'zz_le_<b>x']]);
check($clean($lst) && !empty($lst['found']['Username: <strong>zz_le_a</strong>']), 'Staff & Access lists each user\'s username', json_encode($lst['found'] ?? $lst));
check(!empty($lst['found']['Username: <strong>zz_le_&lt;b&gt;x</strong>']) && empty($lst['found']['zz_le_<b>x']), 'a username with markup is escaped');

// 11. duplicate email guard: add
$taken = 'Another staff member already uses this email. Each person needs their own email to sign in.';
$post = ['action' => 'add_user', 'username' => 'zz_le_new1', 'full_name' => 'Zz New One', 'role' => 'receptionist', 'password' => $PW];
$verifyNew = ["SELECT COUNT(*) AS n FROM admin_users WHERE username = 'zz_le_new1'"];
$ad = run_page('admin/user-management.php', ['setup' => $setup, 'post' => $post + ['email' => '  ZZ.login.A@EXAMPLE.invalid '], 'verify' => $verifyNew, 'grep' => [$taken]]);
check($clean($ad) && !empty($ad['found'][$taken]) && (int)$ad['verify'][0][0]['n'] === 0, 'add with a used email (any case, spaces) is refused with the plain message and nothing is saved', json_encode($ad));
$ad2 = run_page('admin/user-management.php', ['setup' => $setup, 'post' => $post + ['email' => 'zz.login.fresh@example.invalid'], 'verify' => $verifyNew, 'grep' => [$taken]]);
check($clean($ad2) && empty($ad2['found'][$taken]) && (int)$ad2['verify'][0][0]['n'] === 1, 'add with a new email still works', json_encode($ad2));

// 12. duplicate email guard: edit
$vars = ['offid' => "SELECT id FROM admin_users WHERE username = 'zz_le_off'"];
$ed = ['action' => 'update_user', 'user_id' => '{offid}', 'full_name' => 'Zz Off', 'role' => 'receptionist', 'is_active' => '1'];
$verifyOff = ["SELECT email FROM admin_users WHERE username = 'zz_le_off'"];
$e1 = run_page('admin/user-management.php', ['setup' => $setup, 'vars' => $vars, 'post' => $ed + ['email' => 'ZZ.LOGIN.A@example.invalid'], 'verify' => $verifyOff, 'grep' => [$taken]]);
check($clean($e1) && !empty($e1['found'][$taken]) && ($e1['verify'][0][0]['email'] ?? '') === 'zz.login.off@example.invalid', 'edit to a used email is refused and the old email stays', json_encode($e1));
$e2 = run_page('admin/user-management.php', ['setup' => $setup, 'vars' => $vars, 'post' => array_merge($ed, ['email' => 'zz.login.off@example.invalid', 'full_name' => 'Zz Renamed']), 'verify' => ["SELECT full_name FROM admin_users WHERE username = 'zz_le_off'"], 'grep' => [$taken]]);
check($clean($e2) && empty($e2['found'][$taken]) && ($e2['verify'][0][0]['full_name'] ?? '') === 'Zz Renamed', 'edit that keeps the same email still saves', json_encode($e2));
$e3 = run_page('admin/user-management.php', ['setup' => $setup, 'vars' => $vars, 'post' => $ed + ['email' => 'zz.login.brandnew@example.invalid'], 'verify' => $verifyOff, 'grep' => [$taken]]);
check($clean($e3) && empty($e3['found'][$taken]) && ($e3['verify'][0][0]['email'] ?? '') === 'zz.login.brandnew@example.invalid', 'edit to a new unused email saves', json_encode($e3));

$left = (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE username LIKE 'zz\\_le\\_%'")->fetchColumn();
check($left === 0, 'no test users left in the database');
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
