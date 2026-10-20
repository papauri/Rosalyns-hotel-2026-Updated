<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * "Block Rooms" permission test. Usage (from the repo root): php scripts/tests/block_rooms.php
 * Blocking a room from sale (blocked dates, setting a room to Maintenance / Out of Order) needs the
 * Block Rooms permission; the server must refuse without it and allow with it.
 *
 * Page scenarios run in page_harness.php (one outer transaction that can never commit, rolled back
 * at the end), signed in as an existing non-admin user whose permissions are forced inside that
 * transaction. The API checks run the real api files with a stub client and write nothing.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$h = __DIR__ . '/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function run_page(string $page, array $post, array $setup, array $verify, bool $ajax, string $asUser): array {
    global $h;
    $cfg = base64_encode(json_encode(['post' => $post, 'ajax' => $ajax, 'verify' => $verify, 'setup' => $setup, 'as_username' => $asUser]));
    $out = shell_exec('php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -400), 'rolled_back' => false];
}
function flash(array $r): string { return strtolower(implode(' | ', $r['output_flash'] ?? [])); }
function jmsg(array $r): string { return strtolower((string)($r['json']['message'] ?? '')); }

require_once $root . '/config/database.php';
require_once $root . '/admin/includes/permissions.php';

// ---- Role defaults: every default role that could block rooms before this permission keeps working ----
foreach (['manager', 'receptionist', 'housekeeping'] as $role) {
    check(in_array('block_rooms', getDefaultPermissionsForRole($role), true), "default role '$role' has block_rooms");
}
check(!in_array('block_rooms', getDefaultPermissionsForRole('accountant'), true), "default role 'accountant' does not have block_rooms");
check(rhStatusBlocksRoom('maintenance') && rhStatusBlocksRoom('out_of_order') && !rhStatusBlocksRoom('cleaning') && !rhStatusBlocksRoom('available'),
    'only Maintenance and Out of Order count as blocking a room');

// ---- Fixtures: an existing active non-admin user and two rooms ----
$u = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role <> 'admin' ORDER BY (role = 'receptionist') DESC, id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$rm = $pdo->query("SELECT id, room_number, status FROM individual_rooms WHERE status = 'available' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$u || !$rm) { bad('Fixture: an active non-admin user and an available room exist'); echo "\n$pass passed, $fail failed\n"; exit(1); }
$uid = (int)$u['id'];
$rid = (int)$rm['id'];

// hasPermission honours an explicit grant / deny (done inside a transaction that is rolled back)
$pdo->beginTransaction();
$pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission_key = 'block_rooms'")->execute([$uid]);
$pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'block_rooms', 0)")->execute([$uid]);
check(rhCanBlockRooms($uid) === false, 'rhCanBlockRooms is false when Block Rooms is switched off for the user');
$pdo->prepare("UPDATE user_permissions SET is_granted = 1 WHERE user_id = ? AND permission_key = 'block_rooms'")->execute([$uid]);
check(rhCanBlockRooms($uid) === true, 'rhCanBlockRooms is true when Block Rooms is switched on for the user');
$pdo->rollBack();

// ---- Admin pages (rolled-back harness, signed in as that user) ----
$grant = static function (bool $block) use ($uid): array {
    return [
        "DELETE FROM user_permissions WHERE user_id = $uid AND permission_key IN ('blocked_dates','individual_rooms','block_rooms')",
        "INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES ($uid, 'blocked_dates', 1), ($uid, 'individual_rooms', 1), ($uid, 'block_rooms', " . ($block ? 1 : 0) . ")",
    ];
};
$day = date('Y-m-d', strtotime('+400 days'));
$tag = 'ZZ block test ' . bin2hex(random_bytes(3));
$cntType = ["SELECT COUNT(*) AS c FROM blocked_dates WHERE reason = '$tag'"];
$cntInd = ["SELECT COUNT(*) AS c FROM individual_room_blocked_dates WHERE reason = '$tag'"];

echo "== Blocked Dates page ==\n";
$typePost = ['action' => 'block_date', 'block_scope' => 'type', 'room_id' => '', 'block_date' => $day, 'block_type' => 'manual', 'reason' => $tag];
$r = run_page('admin/blocked-dates.php', $typePost, $grant(false), $cntType, true, $u['username']);
check(($r['json']['success'] ?? true) === false && strpos(jmsg($r), 'block rooms permission') !== false && (int)($r['verify'][0][0]['c'] ?? 1) === 0, 'room-type block refused without Block Rooms (nothing saved)', json_encode($r['json'] ?? $r));
$r = run_page('admin/blocked-dates.php', $typePost, $grant(true), $cntType, true, $u['username']);
check(($r['json']['success'] ?? false) === true && (int)($r['verify'][0][0]['c'] ?? 0) === 1, 'room-type block saved with Block Rooms', json_encode($r['json'] ?? $r));
check(!empty($r['rolled_back']), 'blocked-dates test rolled back');

$indPost = ['action' => 'block_date', 'block_scope' => 'individual', 'individual_room_id' => (string)$rid, 'block_date' => $day, 'block_type' => 'manual', 'reason' => $tag];
$r = run_page('admin/blocked-dates.php', $indPost, $grant(false), $cntInd, true, $u['username']);
check(($r['json']['success'] ?? true) === false && strpos(jmsg($r), 'block rooms permission') !== false && (int)($r['verify'][0][0]['c'] ?? 1) === 0, 'single-room block refused without Block Rooms', json_encode($r['json'] ?? $r));
$r = run_page('admin/blocked-dates.php', $indPost, $grant(true), $cntInd, true, $u['username']);
check(($r['json']['success'] ?? false) === true && (int)($r['verify'][0][0]['c'] ?? 0) === 1, 'single-room block saved with Block Rooms', json_encode($r['json'] ?? $r));

$multi = ['action' => 'block_multiple', 'block_scope' => 'type', 'room_id' => '', 'dates' => json_encode([$day]), 'block_type' => 'manual', 'reason' => $tag];
$r = run_page('admin/blocked-dates.php', $multi, $grant(false), $cntType, true, $u['username']);
check(($r['json']['success'] ?? true) === false && (int)($r['verify'][0][0]['c'] ?? 1) === 0, 'date-range block refused without Block Rooms', json_encode($r['json'] ?? $r));
$r = run_page('admin/blocked-dates.php', $multi, $grant(true), $cntType, true, $u['username']);
check(($r['json']['success'] ?? false) === true && (int)($r['verify'][0][0]['c'] ?? 0) === 1, 'date-range block saved with Block Rooms', json_encode($r['json'] ?? $r));

// Unblock: seed a block (explicit id) inside the rolled-back transaction, then try to remove it.
foreach ([false, true] as $withPerm) {
    $seedId = (int)$pdo->query("SELECT COALESCE(MAX(id),0) + 5000 FROM blocked_dates")->fetchColumn();
    $seedSql = "INSERT INTO blocked_dates (id, room_id, block_date, block_type, reason) VALUES ($seedId, NULL, '$day', 'manual', '$tag')";
    $setup = array_merge($grant($withPerm), [$seedSql]);
    $post = ['action' => 'unblock_date', 'id' => (string)$seedId, 'block_scope' => 'type'];
    $r = run_page('admin/blocked-dates.php', $post, $setup, $cntType, false, $u['username']);
    $left = (int)($r['verify'][0][0]['c'] ?? -1);
    if ($withPerm) {
        check($left === 0, 'unblock works with Block Rooms (block removed)', flash($r) . ' left=' . $left);
    } else {
        check($left === 1 && strpos(flash($r), 'block rooms permission') !== false, 'unblock refused without Block Rooms (block kept)', flash($r) . ' left=' . $left);
    }
    $setupB = array_merge($grant($withPerm), [$seedSql]);
    $r = run_page('admin/blocked-dates.php', ['action' => 'bulk_unblock', 'bulk_ids' => ['type-' . $seedId]], $setupB, $cntType, false, $u['username']);
    $left = (int)($r['verify'][0][0]['c'] ?? -1);
    if ($withPerm) {
        check($left === 0, 'bulk unblock works with Block Rooms', flash($r) . ' left=' . $left);
    } else {
        check($left === 1 && strpos(flash($r), 'block rooms permission') !== false, 'bulk unblock refused without Block Rooms (block kept)', flash($r) . ' left=' . $left);
    }
}

// What the user sees: buttons disabled with the plain tooltip, no bulk bar.
$cfgG = static function (bool $block) use ($h, $grant, $u): array {
    $cfg = base64_encode(json_encode(['post' => [], 'grep' => ['Needs the Block Rooms permission', 'data-modal-open="blockSingleDateModal"'], 'setup' => $grant($block), 'as_username' => $u['username']]));
    $out = shell_exec('php ' . escapeshellarg($h) . ' ' . escapeshellarg('admin/blocked-dates.php') . ' ' . escapeshellarg($cfg) . ' 2>&1');
    return preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m) ? (json_decode($m[1], true) ?: []) : [];
};
$g0 = $cfgG(false); $g1 = $cfgG(true);
check(!empty($g0['found']['Needs the Block Rooms permission']) && empty($g0['found']['data-modal-open="blockSingleDateModal"']), 'page without Block Rooms shows the tooltip and no active Block button');
check(empty($g1['found']['Needs the Block Rooms permission']) && !empty($g1['found']['data-modal-open="blockSingleDateModal"']), 'page with Block Rooms shows the working Block button');

echo "== Individual Rooms page ==\n";
$st = "SELECT status FROM individual_rooms WHERE id = $rid";
foreach (['maintenance', 'out_of_order'] as $bad) {
    $r = run_page('admin/individual-rooms.php', ['action' => 'update_status', 'id' => (string)$rid, 'new_status' => $bad, 'reason' => 'ZZ'], $grant(false), [$st], false, $u['username']);
    check(($r['verify'][0][0]['status'] ?? '') === 'available' && strpos(flash($r), 'block rooms permission') !== false, "status '$bad' refused without Block Rooms (room unchanged)", flash($r));
    $r = run_page('admin/individual-rooms.php', ['action' => 'update_status', 'id' => (string)$rid, 'new_status' => $bad, 'reason' => 'ZZ'], $grant(true), [$st], false, $u['username']);
    check(($r['verify'][0][0]['status'] ?? '') === $bad, "status '$bad' allowed with Block Rooms", flash($r) . ' ' . json_encode($r['verify'][0] ?? null));
}
$r = run_page('admin/individual-rooms.php', ['action' => 'update_status', 'id' => (string)$rid, 'new_status' => 'cleaning', 'reason' => 'ZZ'], $grant(false), [$st], false, $u['username']);
check(($r['verify'][0][0]['status'] ?? '') === 'cleaning', "status 'cleaning' still allowed without Block Rooms (housekeeping flow untouched)", flash($r));
$r = run_page('admin/individual-rooms.php', ['action' => 'bulk_status_change', 'bulk_ids' => [(string)$rid], 'bulk_status' => 'maintenance'], $grant(false), [$st], false, $u['username']);
check(($r['verify'][0][0]['status'] ?? '') === 'available' && strpos(flash($r), 'block rooms permission') !== false, 'bulk status to Maintenance refused without Block Rooms', flash($r));
$r = run_page('admin/individual-rooms.php', ['action' => 'bulk_status_change', 'bulk_ids' => [(string)$rid], 'bulk_status' => 'maintenance'], $grant(true), [$st], false, $u['username']);
check(($r['verify'][0][0]['status'] ?? '') === 'maintenance', 'bulk status to Maintenance allowed with Block Rooms', flash($r));
$r = run_page('admin/individual-rooms.php', ['action' => 'bulk_status_change', 'bulk_ids' => [(string)$rid], 'bulk_status' => 'cleaning'], $grant(false), [$st], false, $u['username']);
check(($r['verify'][0][0]['status'] ?? '') === 'cleaning', 'bulk status to Cleaning still allowed without Block Rooms', flash($r));
$newNum = 'ZZB' . strtoupper(bin2hex(random_bytes(2)));
$typeId = (int)$pdo->query("SELECT room_type_id FROM individual_rooms WHERE id = $rid")->fetchColumn();
$addPost = ['action' => 'add_individual_room', 'room_type_id' => (string)$typeId, 'room_number' => $newNum, 'status' => 'out_of_order'];
$cntAdd = ["SELECT COUNT(*) AS c FROM individual_rooms WHERE room_number = '$newNum'"];
$r = run_page('admin/individual-rooms.php', $addPost, $grant(false), $cntAdd, false, $u['username']);
check((int)($r['verify'][0][0]['c'] ?? 1) === 0 && strpos(flash($r), 'block rooms permission') !== false, 'adding a room as Out of Order refused without Block Rooms', flash($r));
$r = run_page('admin/individual-rooms.php', $addPost, $grant(true), $cntAdd, false, $u['username']);
check((int)($r['verify'][0][0]['c'] ?? 0) === 1, 'adding a room as Out of Order allowed with Block Rooms', flash($r));
check(!empty($r['rolled_back']), 'individual-rooms test rolled back');

// Page markup: the Maintenance / Out of Order choices are disabled without the permission.
$cfg = base64_encode(json_encode(['post' => [], 'grep' => ['data-needs-block-rooms'], 'setup' => $grant(false), 'as_username' => $u['username']]));
$out = shell_exec('php ' . escapeshellarg($h) . ' ' . escapeshellarg('admin/individual-rooms.php') . ' ' . escapeshellarg($cfg) . ' 2>&1');
$gr = preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m) ? (json_decode($m[1], true) ?: []) : [];
check(!empty($gr['found']['data-needs-block-rooms']), 'Maintenance / Out of Order choices are disabled in the status lists without Block Rooms');

// ---- API (stub client, real files, nothing written) ----
echo "== API ==\n";
$driver = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_block_rooms_api_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($driver, <<<'PHP'
<?php
// argv: file method hasPerm
chdir($argv[4]);
require $argv[4] . '/config/database.php';
define('API_ACCESS_ALLOWED', true);
class ApiResponse {
    public static function error($m, $c = 400, $d = null) { echo json_encode(['success' => false, 'message' => $m, 'code' => $c]); exit; }
    public static function success($d = null, $m = 'ok', $c = 200) { echo json_encode(['success' => true, 'message' => $m, 'code' => $c]); exit; }
    public static function validationError($e) { echo json_encode(['success' => false, 'message' => 'validation', 'code' => 422]); exit; }
}
class StubAuth { public $perm; function checkPermission($client, $p) { return $this->perm && $p === 'rooms.block'; } }
$auth = new StubAuth(); $auth->perm = $argv[3] === '1';
$client = ['id' => 0, 'permissions' => []];
$_SERVER['REQUEST_METHOD'] = $argv[2];
$_SERVER['PATH_INFO'] = $argv[5] ?? '';
require $argv[4] . '/api/' . $argv[1];
PHP);
$api = static function (string $file, string $method, bool $perm, string $path = '') use ($driver, $root): array {
    $out = shell_exec('php ' . escapeshellarg($driver) . ' ' . escapeshellarg($file) . ' ' . $method . ' ' . ($perm ? '1' : '0') . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($path) . ' 2>&1 < ' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'));
    $j = json_decode((string)$out, true);
    return is_array($j) ? $j : ['raw' => substr((string)$out, -200)];
};
foreach (['POST', 'PUT', 'DELETE'] as $m) {
    $r = $api('blocked-dates.php', $m, false);
    check(($r['success'] ?? null) === false && strpos((string)($r['message'] ?? ''), 'rooms.block') !== false, "API blocked-dates $m refused for a key without rooms.block", json_encode($r));
    $r = $api('blocked-dates.php', $m, true);
    check(strpos((string)($r['message'] ?? ''), 'rooms.block') === false, "API blocked-dates $m passes the permission check with rooms.block", json_encode($r));
}
$r = $api('blocked-dates.php', 'GET', false);
check(($r['success'] ?? null) === true, 'API blocked-dates GET (read) still open without rooms.block', json_encode($r));
// individual-rooms status gate: load the file with an unsupported method to define its functions, then call the gate directly.
$gateDriver = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_block_rooms_gate_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($gateDriver, <<<'PHP'
<?php
chdir($argv[2]);
require $argv[2] . '/config/database.php';
define('API_ACCESS_ALLOWED', true);
class ApiResponse {
    public static function error($m, $c = 400, $d = null) { throw new RuntimeException($m, $c); }
    public static function success($d = null, $m = 'ok', $c = 200) { throw new RuntimeException('success', 200); }
    public static function validationError($e) { throw new RuntimeException('validation', 422); }
}
class StubAuth { public $perm; function checkPermission($client, $p) { return $this->perm && $p === 'rooms.block'; } }
$auth = new StubAuth(); $auth->perm = $argv[1] === '1';
$client = ['id' => 0, 'permissions' => []];
$_SERVER['REQUEST_METHOD'] = 'PATCH'; // unsupported: the file only defines its functions and answers 405
try { require $argv[2] . '/api/individual-rooms.php'; } catch (Throwable $e) {}
$res = [];
foreach (['maintenance', 'out_of_order', 'available', 'cleaning'] as $s) {
    try { requireBlockRoomsForStatus($s); $res[$s] = 'allowed'; } catch (RuntimeException $e) { $res[$s] = 'refused:' . $e->getCode(); }
}
echo json_encode($res);
PHP);
foreach ([false, true] as $perm) {
    $out = shell_exec('php ' . escapeshellarg($gateDriver) . ' ' . ($perm ? '1' : '0') . ' ' . escapeshellarg($root) . ' 2>&1');
    // the api file logs its 405 to stderr (merged into $out): read only the JSON line
    $j = preg_match('/(\{[^\r\n]*\})\s*$/', (string)$out, $jm) ? (json_decode($jm[1], true) ?: []) : [];
    if ($perm) {
        check(($j['maintenance'] ?? '') === 'allowed' && ($j['out_of_order'] ?? '') === 'allowed', 'API individual-rooms: Maintenance / Out of Order allowed with rooms.block', (string)$out);
    } else {
        check(($j['maintenance'] ?? '') === 'refused:403' && ($j['out_of_order'] ?? '') === 'refused:403', 'API individual-rooms: Maintenance / Out of Order refused without rooms.block', (string)$out);
        check(($j['available'] ?? '') === 'allowed' && ($j['cleaning'] ?? '') === 'allowed', 'API individual-rooms: other statuses unaffected', (string)$out);
    }
}
@unlink($driver);
@unlink($gateDriver);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
