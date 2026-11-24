<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Saved filter sets on the Bookings list (plan .claude/BOOKINGS_LIST_PLAN.md T9; admin/includes/bookings-saved-filters.php,
 * admin/api/bookings-saved-filters.php).
 *   php scripts/tests/bookings_saved_filters.php        pure: names, query rebuild (whitelist), caps, save / replace /
 *                                                       delete by name, unreadable stored values
 *   php scripts/tests/bookings_saved_filters.php --db   + Liwonde only (refuses a LIVE_TARGETS database or a site that is
 *       not Liwonde): the locked write in a transaction that is rolled back (round trip, corrupt value never overwritten,
 *       a held row lock makes a second writer give up), then the endpoint over real HTTP (php -S + dev-router): GET 405,
 *       signed out 401, no csrf 403, save / replace / delete 200, a posted user_id is ignored, a person who cannot open
 *       bookings.php 403. The signed-in admin's row is put back exactly as it was (or removed when there was none);
 *       activity-log rows this run's users wrote are removed.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$db = in_array('--db', array_slice($argv, 1), true);
$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } }
function eq($got, $want, string $l): void { check($got === $want, $l, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

require_once $root . '/admin/includes/bookings-saved-filters.php';

// ---- names
eq(rh_bk_saved_name('  Arrivals   this  week '), 'Arrivals this week', 'name: trimmed, spaces collapsed');
eq(rh_bk_saved_name("Unpaid\tgroups\n"), 'Unpaid groups', 'name: tabs / new lines become spaces');
eq(rh_bk_saved_name("Bad\x00name"), 'Bad name', 'name: control characters out');
eq(rh_bk_saved_name(''), null, 'name: empty refused');
eq(rh_bk_saved_name("   \t "), null, 'name: only spaces refused');
eq(rh_bk_saved_name(str_repeat('a', 60)), str_repeat('a', 60), 'name: 60 characters kept');
eq(rh_bk_saved_name(str_repeat('a', 61)), null, 'name: 61 characters refused (never cut)');
eq(rh_bk_saved_name(str_repeat('é', 60)), str_repeat('é', 60), 'name: 60 accented characters kept (characters, not bytes)');
eq(rh_bk_saved_name("\xC3\x28"), null, 'name: invalid UTF-8 refused');
eq(rh_bk_saved_name(['x']), null, 'name: an array refused');
eq(rh_bk_saved_name('<b>VIP</b>'), '<b>VIP</b>', 'name: kept as text (escaped where printed)');

// ---- query rebuild: whitelisted keys only, page dropped, sort only when known
eq(rh_bk_saved_query(''), '', 'query: empty = the plain list');
eq(rh_bk_saved_query('?filter_status=pending&page=3'), 'filter_status=pending', 'query: leading ? and page dropped');
eq(rh_bk_saved_query('status=pending'), 'filter_status=pending', 'query: ?status= alias stored as filter_status');
eq(rh_bk_saved_query('foo=bar&export=csv&action=assign-room&booking_id=5&csrf_token=x'), '', 'query: unknown and action keys dropped');
eq(rh_bk_saved_query('room=12&group=7&source=online&created_by=3&pay=part&room_type=2&kind=group'),
    'kind=group&room_type=2&pay=part&room=12&group=7&source=online&created_by=3', 'query: the new filters kept, fixed order');
eq(rh_bk_saved_query('room[]=1&group[x]=2&source[]=staff&search[]=a'), '', 'query: array values dropped');
eq(rh_bk_saved_query('room=-4&group=abc&source=nope&pay=nope&kind=nope'), '', 'query: junk values dropped');
eq(rh_bk_saved_query('date_from=2026-11-30&date_to=2026-11-01&date_type=staying'), 'date_from=2026-11-01&date_to=2026-11-30&date_type=staying', 'query: dates cleaned (swapped) and their field kept');
eq(rh_bk_saved_query('date_type=staying'), '', 'query: date field without dates dropped');
eq(rh_bk_saved_query('search=Banda%20%26%20Co&filter=unpaid&size=25'), 'search=Banda%20%26%20Co&filter=unpaid&size=25', 'query: search text encoded, tab and page size kept');
eq(rh_bk_saved_query('sort=balance&dir=asc'), 'sort=balance&dir=asc', 'query: known sort kept');
eq(rh_bk_saved_query('sort=balance'), 'sort=balance&dir=desc', 'query: sort without dir = desc');
eq(rh_bk_saved_query('sort=b.id;DROP&dir=asc'), '', 'query: unknown sort key dropped');
eq(rh_bk_saved_query(str_repeat('x', 4001)), null, 'query: text over the raw cap refused');
eq(rh_bk_saved_query('search=' . str_repeat('a', 1494)), null, 'query: rebuilt query over the cap refused');
eq(rh_bk_saved_query(['search' => 'x']), null, 'query: an array refused');

// ---- decode: unreadable values are null (never overwritten), bad entries left out
eq(rh_bk_saved_decode(null), [], 'decode: nothing stored = []');
eq(rh_bk_saved_decode('[]'), [], 'decode: the seed = []');
eq(rh_bk_saved_decode('{"bad'), null, 'decode: broken JSON = null');
eq(rh_bk_saved_decode('{"a":1}'), null, 'decode: an object = null');
eq(rh_bk_saved_decode('5'), null, 'decode: a number = null');
eq(rh_bk_saved_decode('"x"'), null, 'decode: a string = null');
eq(rh_bk_saved_decode('[{"name":"A","query":"filter_status=pending&foo=1"},{"name":"","query":""},7,{"name":"a","query":""},{"name":"B","query":"sort=nope"}]'),
    [['name' => 'A', 'query' => 'filter_status=pending'], ['name' => 'B', 'query' => '']], 'decode: queries rebuilt, nameless / non-array / duplicate-name entries left out');

// ---- change: save, replace by name, cap, delete by name
$l = [];
$r = rh_bk_saved_change($l, 'save', ['name' => 'Unpaid', 'query' => 'filter=unpaid']);
check($r['ok'] && $r['list'] === [['name' => 'Unpaid', 'query' => 'filter=unpaid']], 'save: added');
$r = rh_bk_saved_change($r['list'], 'save', ['name' => 'In house', 'query' => 'filter=checked_in']);
$r = rh_bk_saved_change($r['list'], 'save', ['name' => 'UNPAID', 'query' => 'filter=unpaid&sort=balance&dir=desc']);
eq($r['list'], [['name' => 'UNPAID', 'query' => 'filter=unpaid&sort=balance&dir=desc'], ['name' => 'In house', 'query' => 'filter=checked_in']], 'save: same name (any case) replaces it in place');
$r2 = rh_bk_saved_change($r['list'], 'delete', ['name' => 'unpaid']);
eq($r2['list'], [['name' => 'In house', 'query' => 'filter=checked_in']], 'delete: by name, any case');
$r3 = rh_bk_saved_change($r2['list'], 'delete', ['name' => 'Gone']);
check(!$r3['ok'] && $r3['status'] === 404 && $r3['list'] === $r2['list'], 'delete: unknown name = 404, list unchanged');
$full = [];
for ($i = 1; $i <= 20; $i++) { $full[] = ['name' => "Set $i", 'query' => '']; }
$r = rh_bk_saved_change($full, 'save', ['name' => 'Set 21', 'query' => '']);
check(!$r['ok'] && $r['status'] === 409 && count($r['list']) === 20, 'save: the 21st set refused');
$r = rh_bk_saved_change($full, 'save', ['name' => 'set 5', 'query' => 'filter=unpaid']);
check($r['ok'] && count($r['list']) === 20 && $r['list'][4]['query'] === 'filter=unpaid', 'save: replacing one when 20 are kept still works');
$r = rh_bk_saved_change([], 'save', ['name' => str_repeat('n', 61), 'query' => '']);
check(!$r['ok'] && $r['status'] === 400, 'save: long name refused');
$r = rh_bk_saved_change([], 'save', ['name' => 'Long', 'query' => 'search=' . str_repeat('a', 1494)]);
check(!$r['ok'] && $r['status'] === 400, 'save: long query refused');
$r = rh_bk_saved_change([], 'rename', ['name' => 'x']);
check(!$r['ok'] && $r['status'] === 400, 'unknown action refused');
// The whole value stays under the column's size at the caps (20 sets, 60-char names of 4-byte characters, longest queries).
$big = [];
for ($i = 0; $i < 20; $i++) { $big[] = ['name' => str_repeat("\u{1F600}", 59) . chr(65 + $i), 'query' => str_repeat('q', rh_bk_saved_query_max())]; }
check(strlen((string)json_encode($big, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) <= rh_bk_saved_value_max()
    && rh_bk_saved_value_max() < 65535, 'caps: 20 sets at the limits fit the whole-value cap, which fits TEXT');
eq(rh_bk_saved_links([['name' => 'A', 'query' => 'filter=unpaid'], ['name' => 'B', 'query' => '']]),
    [['name' => 'A', 'url' => 'bookings.php?filter=unpaid'], ['name' => 'B', 'url' => 'bookings.php']], 'links: bookings.php + the rebuilt query');

// ---- the endpoint file: order of the checks, no user id from the request
$ep = (string)file_get_contents($root . '/admin/api/bookings-saved-filters.php');
$pos = static function (string $needle) use ($ep): int { $p = strpos($ep, $needle); return $p === false ? PHP_INT_MAX : $p; };
check($pos("require_once __DIR__ . '/api-init.php'") < $pos('rhHasPagePermission($userId, \'bookings.php\')')
    && $pos('rhHasPagePermission($userId, \'bookings.php\')') < $pos("rhApiModuleGate('bookings.php')")
    && $pos("rhApiModuleGate('bookings.php')") < $pos('validateCsrfToken')
    && $pos('validateCsrfToken') < $pos('rh_bk_saved_write('), 'endpoint: sign-in, page permission, module, csrf, then the write');
check(strpos($ep, "\$_POST['user_id']") === false && strpos($ep, "\$_GET['user_id']") === false && strpos($ep, "\$userId = (int)(\$user['id']") !== false,
    'endpoint: the user id is the signed-in one only');
$vo = (string)file_get_contents($root . '/includes/view-only.php');
check(strpos($vo, 'bookings-saved-filters.php') === false, 'not on the view-only allowlist (Decision 3)');

if (!$db) {
    echo "bookings_saved_filters: $pass passed, $fail failed (pure; --db adds the Liwonde round trip)\n";
    exit($fail > 0 ? 1 : 0);
}

// ================= --db (Liwonde only) =================
if (stripos($root, 'Liwonde') === false) { fwrite(STDERR, "Refused: runs in the Liwonde repo only.\n"); exit(1); }
if (!function_exists('curl_init')) { echo "bookings_saved_filters: php curl extension missing.\n"; exit(1); }
require $root . '/config/database.php';
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$live = (string)@file_get_contents($root . '/.claude/LIVE_TARGETS');
if ($dbName === '' || stripos($live, $dbName) !== false) { fwrite(STDERR, "Refused: $dbName is a live target.\n"); exit(1); }
if (stripos((string)getSetting('site_name', ''), 'liwonde') === false) { fwrite(STDERR, "Refused: site is not Liwonde.\n"); exit(1); }
require_once $root . '/admin/includes/permissions.php';

$key = rh_bk_saved_key();
$admin = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$adminId = (int)$admin['id'];
$rowOf = static function (int $uid) use ($pdo, $key) {
    $st = $pdo->prepare('SELECT preference_value FROM admin_user_preferences WHERE user_id = ? AND preference_key = ?');
    $st->execute([$uid, $key]);
    $v = $st->fetch(PDO::FETCH_NUM);
    return $v === false ? false : $v[0]; // false = no row
};
$before = $rowOf($adminId);

// ---- 1. the locked write, inside a transaction that is rolled back
$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM admin_user_preferences WHERE user_id = ? AND preference_key = ?')->execute([$adminId, $key]);
    $r = rh_bk_saved_write($pdo, $adminId, 'save', ['name' => 'Test unpaid', 'query' => 'filter=unpaid&foo=1']);
    check($r['ok'] && $rowOf($adminId) === '[{"name":"Test unpaid","query":"filter=unpaid"}]', 'db: first save seeds the row and stores the rebuilt query', var_export($rowOf($adminId), true));
    $r = rh_bk_saved_write($pdo, $adminId, 'save', ['name' => 'test UNPAID', 'query' => 'filter=unpaid&sort=balance']);
    check($r['ok'] && $rowOf($adminId) === '[{"name":"test UNPAID","query":"filter=unpaid&sort=balance&dir=desc"}]', 'db: same name replaces');
    check(rh_bk_saved_list($pdo, $adminId) === [['name' => 'test UNPAID', 'query' => 'filter=unpaid&sort=balance&dir=desc']], 'db: the page reads the same list');
    $r = rh_bk_saved_write($pdo, $adminId, 'delete', ['name' => 'Test Unpaid']);
    check($r['ok'] && $rowOf($adminId) === '[]', 'db: delete by name');
    $pdo->prepare('UPDATE admin_user_preferences SET preference_value = ? WHERE user_id = ? AND preference_key = ?')->execute(['{"bad', $adminId, $key]);
    $r = rh_bk_saved_write($pdo, $adminId, 'save', ['name' => 'X', 'query' => '']);
    check(!$r['ok'] && $r['status'] === 409 && $rowOf($adminId) === '{"bad', 'db: an unreadable stored value is never overwritten');
    check(rh_bk_saved_list($pdo, $adminId) === [], 'db: an unreadable value shows as no sets');
    $r = rh_bk_saved_write($pdo, 0, 'save', ['name' => 'X', 'query' => '']);
    check(!$r['ok'] && $r['status'] === 401, 'db: no user, no write');
} finally {
    $pdo->rollBack();
}
check($rowOf($adminId) === $before, 'db: rolled back, the row is as it was');

// ---- 2. the row lock: while another connection holds the row FOR UPDATE, a second writer gives up and changes nothing
$seeded = false;
if ($before === false) {
    $pdo->prepare('INSERT INTO admin_user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)')->execute([$adminId, $key, '[]']);
    $seeded = true;
}
$valueNow = $rowOf($adminId);
$holder = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $holder->beginTransaction();
    $hs = $holder->prepare('SELECT preference_value FROM admin_user_preferences WHERE user_id = ? AND preference_key = ? FOR UPDATE');
    $hs->execute([$adminId, $key]);
    $hs->fetchAll();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $t0 = microtime(true);
    $r = rh_bk_saved_write($pdo, $adminId, 'save', ['name' => 'Lock test', 'query' => '']);
    check(!$r['ok'] && $r['status'] === 500 && microtime(true) - $t0 < 10, 'db: a locked row makes the second writer give up (no lost update)', json_encode($r));
    check(!$pdo->inTransaction(), 'db: the refused write rolled its own transaction back');
} finally {
    if ($holder->inTransaction()) { $holder->rollBack(); }
    $holder = null;
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 50');
}
check($rowOf($adminId) === $valueNow, 'db: value unchanged after the lock test');
if ($seeded) {
    $pdo->prepare('DELETE FROM admin_user_preferences WHERE user_id = ? AND preference_key = ?')->execute([$adminId, $key]);
}
check($rowOf($adminId) === $before, 'db: row as before the lock test');

// ---- 3. over real HTTP
$other = $pdo->prepare("SELECT id FROM admin_users WHERE is_active = 1 AND id <> ? ORDER BY id LIMIT 1");
$other->execute([$adminId]);
$otherId = (int)$other->fetchColumn();
$otherBefore = $otherId > 0 ? $rowOf($otherId) : false;
$noBk = '';
foreach ($pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role <> 'admin' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $u) {
    if (!rhHasPagePermission((int)$u['id'], 'bookings.php')) { $noBk = (string)$u['username']; $noBkId = (int)$u['id']; break; }
}
$maxLog = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM admin_activity_log')->fetchColumn();
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$base = "http://127.0.0.1:$port";
$null = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, $root . '/scripts/tests/dev-router.php'],
    [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $root);
$jars = [];
$req = function (string $jarName, string $path, ?array $post = null) use ($base, &$jars): array {
    if (!isset($jars[$jarName])) { $jars[$jarName] = tempnam(sys_get_temp_dir(), 'bksf'); }
    $ch = curl_init($base . $path);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jars[$jarName], CURLOPT_COOKIEFILE => $jars[$jarName], CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120];
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
    curl_setopt_array($ch, $o);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body];
};
$api = '/admin/api/bookings-saved-filters.php';
try {
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) { usleep(200000); $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); $up = true; } }
    check($up, 'http: dev server started');
    [$code] = $req('anon', $api, ['action' => 'save', 'name' => 'x', 'query' => '']);
    check($code === 401, 'http: signed out = 401', "HTTP $code");
    $req('admin', '/__devlogin?to=dashboard.php');
    [$code, $html] = $req('admin', '/admin/bookings.php');
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m);
    $csrf = html_entity_decode($m[1] ?? '', ENT_QUOTES);
    check($code === 200 && $csrf !== '', 'http: signed in, page token read');
    [$code] = $req('admin', $api);
    check($code === 405, 'http: GET = 405', "HTTP $code");
    [$code] = $req('admin', $api, ['action' => 'save', 'name' => 'HTTP test', 'query' => 'filter=unpaid']);
    check($code === 403 && $rowOf($adminId) === $before, 'http: no csrf = 403, nothing written', "HTTP $code");
    [$code] = $req('admin', $api, ['action' => 'save', 'name' => 'HTTP test', 'query' => 'filter=unpaid', 'csrf_token' => 'wrong']);
    check($code === 403 && $rowOf($adminId) === $before, 'http: wrong csrf = 403, nothing written', "HTTP $code");
    [$code, $body] = $req('admin', $api, ['action' => 'save', 'name' => 'HTTP test', 'query' => '?filter=unpaid&page=4&evil=1', 'csrf_token' => $csrf, 'user_id' => (string)$otherId]);
    $j = json_decode($body, true);
    check($code === 200 && ($j['success'] ?? false) === true, 'http: save = 200', "HTTP $code $body");
    $mine = rh_bk_saved_list($pdo, $adminId);
    $hit = array_values(array_filter($mine, static function ($e) { return $e['name'] === 'HTTP test'; }));
    check(count($hit) === 1 && $hit[0]['query'] === 'filter=unpaid', 'http: stored in the signed-in person\'s own row, query rebuilt');
    check(in_array(['name' => 'HTTP test', 'url' => 'bookings.php?filter=unpaid'], $j['data']['sets'] ?? [], true), 'http: answer lists the set as a link');
    check($otherId <= 0 || $rowOf($otherId) === $otherBefore, 'http: a posted user_id is ignored (the other person\'s row is untouched)');
    [$code] = $req('admin', $api, ['action' => 'save', 'name' => str_repeat('n', 61), 'query' => '', 'csrf_token' => $csrf]);
    check($code === 400, 'http: long name = 400', "HTTP $code");
    [$code] = $req('admin', $api, ['action' => 'rename', 'name' => 'HTTP test', 'csrf_token' => $csrf]);
    check($code === 400, 'http: unknown action = 400', "HTTP $code");
    [$code, $html] = $req('admin', '/admin/bookings.php');
    check(strpos($html, 'href="bookings.php?filter=unpaid"') !== false && strpos($html, 'HTTP test') !== false, 'http: the page lists the saved set');
    [$code, $body] = $req('admin', $api, ['action' => 'delete', 'name' => 'http TEST', 'csrf_token' => $csrf]);
    check($code === 200 && !in_array('HTTP test', array_column(rh_bk_saved_list($pdo, $adminId), 'name'), true), 'http: delete by name = 200, gone', "HTTP $code $body");
    [$code] = $req('admin', $api, ['action' => 'delete', 'name' => 'HTTP test', 'csrf_token' => $csrf]);
    check($code === 404, 'http: deleting it again = 404', "HTTP $code");
    if ($noBk !== '') {
        $req('nobk', '/__devlogin?to=dashboard.php&as=' . rawurlencode($noBk));
        $rowNoBk = $rowOf($noBkId);
        [$code] = $req('nobk', $api, ['action' => 'save', 'name' => 'x', 'query' => '', 'csrf_token' => 'any']);
        check($code === 403 && $rowOf($noBkId) === $rowNoBk, 'http: a person who cannot open bookings.php = 403, nothing written', "HTTP $code");
    } else {
        echo "INFO no active non-admin user without the Bookings page on Liwonde: the 403-for-no-access case was not run over HTTP\n";
    }
} finally {
    // Put the admin's row back exactly as it was.
    if ($before === false) {
        $pdo->prepare('DELETE FROM admin_user_preferences WHERE user_id = ? AND preference_key = ?')->execute([$adminId, $key]);
    } else {
        $pdo->prepare('UPDATE admin_user_preferences SET preference_value = ? WHERE user_id = ? AND preference_key = ?')->execute([$before, $adminId, $key]);
    }
    $cl = $pdo->prepare('DELETE FROM admin_activity_log WHERE id > ? AND user_id IN (?, ?)');
    $cl->execute([$maxLog, $adminId, isset($noBkId) ? $noBkId : $adminId]);
    echo 'activity-log rows from this run removed: ' . $cl->rowCount() . "\n";
    if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
    foreach ($jars as $j) { @unlink($j); }
}
check($rowOf($adminId) === $before, 'db: the admin\'s row is back as it was');

echo "bookings_saved_filters: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
