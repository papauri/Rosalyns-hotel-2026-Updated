<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Menu Management: Room service tab switch and "Move selected to category" (owner 2026-11-21).
 * Usage (project root, Liwonde only):
 *   php scripts/tests/menu_room_service.php                 the checks below (every write rolled back by page_harness.php)
 *   php scripts/tests/menu_room_service.php --seed-browser  adds 3 real "ZZ Test Browser" drinks for the headless browser check
 *   php scripts/tests/menu_room_service.php --clean-browser removes them again by id (and the till rows / categories they made)
 *
 *  1. Move: two drinks out of one category into another (typed in other capitals: joins the existing one). The moved
 *     item's website row, till copy (category + category slug) and stock link follow; display_order = end of the target;
 *     an item whose name is already in the target is skipped and named; an untouched item stays; one System Logs row.
 *  2. Move refusals: no category, no ticked items.
 *  3. Room service switch: value 0 / 1 sets the website row AND the till copy; no value flips; a missing item is refused.
 *  4. Rendered page: ?tab=room-service opens the Room service tab with its rows, badge and filter; the Drinks tab has the
 *     category buttons. A non-administrator (granted Menu Management) gets tick boxes + Move on Drinks, no Delete
 *     selected / Delete all, and may move.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';

$liveGuard = $root . '/.claude/LIVE_TARGETS';
if (is_file($liveGuard) && defined('DB_NAME') && stripos((string)file_get_contents($liveGuard), (string)DB_NAME) !== false) {
    exit("Refused: " . DB_NAME . " is listed in .claude/LIVE_TARGETS.\n");
}

/* ---- Browser check seed / clean-up (real rows, named "ZZ Test Browser", removed by id) ---- */
if (in_array('--seed-browser', $argv, true)) {
    $ins = $pdo->prepare("INSERT INTO drink_menu (item_name, price, category, station, is_available, display_order, show_pos, show_room_service) VALUES (?, ?, 'ZZ Test Drinks', 'bar', 1, ?, 1, ?)");
    $ids = [];
    foreach ([['ZZ Test Browser A', 1000, 1, 1], ['ZZ Test Browser B', 1200, 2, 0], ['ZZ Test Browser C', 1500, 3, 1]] as [$n, $p, $o, $rs]) {
        $ins->execute([$n, $p, $o, $rs]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    echo json_encode(['drink_ids' => $ids]) . "\n";
    exit(0);
}
if (in_array('--clean-browser', $argv, true)) {
    $ids = static fn(string $sql): array => array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    $in = static fn(array $a): string => implode(',', $a ?: [0]);
    $drinks = $ids("SELECT id FROM drink_menu WHERE item_name LIKE 'ZZ Test Browser %'");
    $till = $ids("SELECT id FROM menu_items WHERE item_name LIKE 'ZZ Test Browser %'");
    $recipes = $ids("SELECT id FROM stock_recipes WHERE menu_item_id IN (" . $in($till) . ")");
    $logs = $ids("SELECT id FROM admin_activity_log WHERE action = 'menu_items_category_moved' AND details LIKE '%ZZ Test Browser %'");
    if ($recipes) { $pdo->exec("DELETE FROM stock_recipes WHERE id IN (" . $in($recipes) . ")"); }
    if ($till) { $pdo->exec("DELETE FROM menu_items WHERE id IN (" . $in($till) . ")"); }
    if ($drinks) { $pdo->exec("DELETE FROM drink_menu WHERE id IN (" . $in($drinks) . ")"); }
    if ($logs) { $pdo->exec("DELETE FROM admin_activity_log WHERE id IN (" . $in($logs) . ")"); }
    // Till categories the browser check makes (the seed's "ZZ Test Drinks" and the "ZZ Test Juice" it moves to), only when nothing uses them.
    $cats = $ids("SELECT c.id FROM menu_categories c WHERE c.name IN ('ZZ Test Drinks', 'ZZ Test Juice') AND NOT EXISTS (SELECT 1 FROM menu_items mi WHERE mi.category_id = c.id)");
    if ($cats) { $pdo->exec("DELETE FROM menu_categories WHERE id IN (" . $in($cats) . ")"); }
    echo json_encode(['drink_menu' => $drinks, 'menu_items' => $till, 'stock_recipes' => $recipes, 'activity_log' => $logs, 'menu_categories' => $cats]) . "\n";
    exit(0);
}

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

$harness = __DIR__ . '/page_harness.php';
$run = static function (array $cfg) use ($harness): array {
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' admin/menu-management.php ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr($out, -700), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $res = $x; } }
    return $res;
};
$clean = static fn(array $r): bool => !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']);
$v = static fn(array $r, int $i, string $k = 'q') => ($r['verify'][$i][0] ?? [])[$k] ?? null;

$hr = strtoupper(bin2hex(random_bytes(3)));
$src = "ZZ Test Src $hr";
$dst = "ZZ Test Dst $hr";
$srcSlug = 'zz-test-src-' . strtolower($hr);
$dstSlug = 'zz-test-dst-' . strtolower($hr);
$staff = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();

// Seed (rolled back): drinks A, B, C in $src with till copies; A has a stock link; a drink named B is already in $dst.
$setup = [
    "INSERT INTO menu_categories (name, slug, business_context, default_station, sort_order, shows_on_pos, shows_on_room_service, display_order, is_active) VALUES ('$src', '$srcSlug', 'food_service', 'bar', 999, 1, 1, 999, 1), ('$dst', '$dstSlug', 'food_service', 'bar', 999, 1, 1, 999, 1)",
    "INSERT INTO drink_menu (item_name, price, category, station, display_order, show_pos, show_room_service) VALUES ('ZZ Test $hr A', 1000, '$src', 'bar', 1, 1, 1), ('ZZ Test $hr B', 1100, '$src', 'bar', 2, 1, 1), ('ZZ Test $hr C', 1200, '$src', 'bar', 3, 1, 1), ('ZZ Test $hr B', 1100, '$dst', 'bar', 1, 1, 0)",
    "INSERT INTO menu_items (category_id, item_name, price, category, station, show_pos, show_room_service) SELECT c.id, d.item_name, d.price, d.category, 'bar', 1, d.show_room_service FROM drink_menu d JOIN menu_categories c ON c.name = d.category WHERE d.item_name LIKE 'ZZ Test $hr %'",
    "INSERT INTO stock_recipes (menu_item_id, menu_type) SELECT id, '$srcSlug' FROM menu_items WHERE item_name = 'ZZ Test $hr A' AND category = '$src'",
];
$vars = [
    'a' => "SELECT id FROM drink_menu WHERE item_name = 'ZZ Test $hr A'",
    'b' => "SELECT id FROM drink_menu WHERE item_name = 'ZZ Test $hr B' AND category = '$src'",
    'c' => "SELECT id FROM drink_menu WHERE item_name = 'ZZ Test $hr C'",
];

/* ---- 1. Move ---- */
$verify = [
    "SELECT category AS q, display_order AS o FROM drink_menu WHERE item_name = 'ZZ Test $hr A'",
    "SELECT mi.category AS q, mc.slug AS s FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mi.item_name = 'ZZ Test $hr A'",
    "SELECT COUNT(*) AS q FROM stock_recipes sr JOIN menu_items mi ON mi.id = sr.menu_item_id WHERE mi.item_name = 'ZZ Test $hr A' AND sr.menu_type = '$dstSlug'",
    "SELECT COUNT(*) AS q FROM stock_recipes WHERE menu_type = '$srcSlug'",
    "SELECT COUNT(*) AS q FROM drink_menu WHERE item_name = 'ZZ Test $hr B' AND category = '$src'",
    "SELECT category AS q FROM drink_menu WHERE item_name = 'ZZ Test $hr C'",
    "SELECT COUNT(*) AS q FROM menu_items WHERE item_name = 'ZZ Test $hr A'",
    "SELECT COUNT(*) AS q FROM admin_activity_log WHERE action = 'menu_items_category_moved' AND details LIKE '%Moved: ZZ Test $hr A.%' AND details LIKE '%Skipped: ZZ Test $hr B.%'",
    "SELECT show_room_service AS q FROM menu_items WHERE item_name = 'ZZ Test $hr A'",
];
$r = $run(['post' => ['action' => 'menu_move_category', 'menu_type' => 'drinks', 'category' => '__new__', 'new_category' => strtolower($dst), 'ids' => ['{a}', '{b}']], 'ajax' => true, 'setup' => $setup, 'vars' => $vars, 'verify' => $verify]);
$j = $r['json'] ?? [];
check($clean($r) && ($j['success'] ?? false) === true && (int)($j['moved'] ?? -1) === 1 && (int)($j['skipped'] ?? -1) === 1, 'Move: one moved, one skipped (rolled back)', json_encode($j ?: ($r['raw'] ?? $r)));
check(($j['skipped_names'] ?? []) === ["ZZ Test $hr B"] && strpos((string)($j['message'] ?? ''), "ZZ Test $hr B") !== false, 'Move: the skipped item is named in the reply', json_encode($j));
check(($j['category'] ?? '') === $dst, 'Move: a category typed in other capitals joins the existing one', json_encode($j));
check($v($r, 0) === $dst && (int)$v($r, 0, 'o') === 2, 'Move: website row is in the target category, at its end (display order 2)', json_encode($r['verify'][0] ?? ''));
check($v($r, 1) === $dst && $v($r, 1, 's') === $dstSlug, 'Move: the till copy follows (category label and category slug)', json_encode($r['verify'][1] ?? ''));
check((int)$v($r, 2) === 1 && (int)$v($r, 3) === 0, 'Move: the stock link follows the item to the new slug (nothing left on the old one)', json_encode([$r['verify'][2] ?? '', $r['verify'][3] ?? '']));
check((int)$v($r, 6) === 1, 'Move: still exactly one till row for the moved item (updated, not duplicated)', json_encode($r['verify'][6] ?? ''));
check((int)$v($r, 4) === 1, 'Move: the item whose name is already in the target stays where it was', json_encode($r['verify'][4] ?? ''));
check($v($r, 5) === $src, 'Move: an item that was not ticked is untouched', json_encode($r['verify'][5] ?? ''));
check((int)$v($r, 7) === 1, 'Move: one System Logs row names what moved and what was skipped', json_encode($r['verify'][7] ?? ''));
check((int)$v($r, 8) === 1, 'Move: room service setting kept on the till copy', json_encode($r['verify'][8] ?? ''));

/* ---- 2. Move refusals ---- */
$r = $run(['post' => ['action' => 'menu_move_category', 'menu_type' => 'drinks', 'category' => '', 'ids' => ['{a}']], 'ajax' => true, 'setup' => $setup, 'vars' => $vars, 'verify' => [$verify[0]]]);
check($clean($r) && ($r['json']['success'] ?? true) === false && $v($r, 0) === $src, 'Move: refused without a category, nothing changes', json_encode($r['json'] ?? $r['raw'] ?? $r));
$r = $run(['post' => ['action' => 'menu_move_category', 'menu_type' => 'drinks', 'category' => $dst], 'ajax' => true, 'setup' => $setup, 'vars' => $vars, 'verify' => [$verify[0]]]);
check($clean($r) && ($r['json']['success'] ?? true) === false && $v($r, 0) === $src, 'Move: refused with nothing ticked', json_encode($r['json'] ?? $r['raw'] ?? $r));

/* ---- 3. Room service switch ---- */
$rsVerify = [
    "SELECT show_room_service AS q FROM drink_menu WHERE item_name = 'ZZ Test $hr A'",
    "SELECT show_room_service AS q FROM menu_items WHERE item_name = 'ZZ Test $hr A'",
];
$r = $run(['post' => ['action' => 'toggle_room_service', 'menu_type' => 'drinks', 'id' => '{a}', 'value' => '0'], 'ajax' => true, 'setup' => $setup, 'vars' => $vars, 'verify' => $rsVerify]);
check($clean($r) && ($r['json']['success'] ?? false) === true && (int)($r['json']['show_room_service'] ?? -1) === 0 && (int)$v($r, 0) === 0 && (int)$v($r, 1) === 0,
    'Room service: off sets the website row AND the till copy', json_encode($r['json'] ?? $r['raw'] ?? $r) . json_encode($r['verify'] ?? ''));
$r = $run(['post' => ['action' => 'toggle_room_service', 'menu_type' => 'drinks', 'id' => '{a}'], 'ajax' => true, 'setup' => $setup, 'vars' => $vars, 'verify' => $rsVerify]);
check($clean($r) && (int)($r['json']['show_room_service'] ?? -1) === 0 && (int)$v($r, 0) === 0 && (int)$v($r, 1) === 0, 'Room service: no value flips it (on -> off)', json_encode($r['json'] ?? $r));
$setupOff = array_merge($setup, ["UPDATE drink_menu SET show_room_service = 0 WHERE item_name = 'ZZ Test $hr A'", "UPDATE menu_items SET show_room_service = 0 WHERE item_name = 'ZZ Test $hr A'"]);
$r = $run(['post' => ['action' => 'toggle_room_service', 'menu_type' => 'drinks', 'id' => '{a}', 'value' => '1'], 'ajax' => true, 'setup' => $setupOff, 'vars' => $vars, 'verify' => $rsVerify]);
check($clean($r) && (int)($r['json']['show_room_service'] ?? -1) === 1 && (int)$v($r, 0) === 1 && (int)$v($r, 1) === 1, 'Room service: on sets both again', json_encode($r['json'] ?? $r));
$r = $run(['post' => ['action' => 'toggle_room_service', 'menu_type' => 'drinks', 'id' => '0', 'value' => '1'], 'ajax' => true, 'setup' => [], 'vars' => [], 'verify' => []]);
check($clean($r) && ($r['json']['success'] ?? true) === false, 'Room service: a missing item is refused', json_encode($r['json'] ?? $r));
$r = $run(['post' => ['action' => 'toggle_room_service', 'menu_type' => 'drinks', 'id' => '{a}', 'value' => '0', 'csrf_token' => 'wrong'], 'ajax' => true, 'setup' => $setup, 'vars' => $vars, 'verify' => $rsVerify]);
check($clean($r) && ($r['json']['success'] ?? true) === false && (int)$v($r, 0) === 1, 'Room service: a wrong security token changes nothing', json_encode($r['json'] ?? $r));

/* ---- 4. Rendered page ---- */
$r = $run(['post' => [], 'ajax' => false, 'method' => 'GET', 'get' => ['tab' => 'room-service'], 'setup' => $setup, 'vars' => $vars, 'verify' => [],
    'grep' => ['<div class="tab-content active" id="room-service-tab">', 'id="mmTabRs"', 'id="mmRsCount"', 'id="rs-drink-row-', 'class="mm-switch" aria-checked="true"', 'data-rsf="off"', 'data-catbtn="' . $src . '"', 'id="mmMoveBtn"', 'id="mmBulkDel"']]);
$f = $r['found'] ?? [];
check($clean($r) && !empty($f) && !in_array(false, $f, true), 'Page: ?tab=room-service opens the Room service tab (rows, switch, badge, filter); Drinks has category buttons and Move', json_encode($f ?: ($r['raw'] ?? $r)));

$na = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role <> 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
if (!empty($na['username'])) {
    $naId = (int)$na['id'];
    $naSetup = array_merge($setup, ["INSERT INTO user_permissions (user_id, permission_key, is_granted, granted_by) VALUES ($naId, 'menu', 1, $staff) ON DUPLICATE KEY UPDATE is_granted = 1"]);
    $r = $run(['post' => [], 'ajax' => false, 'method' => 'GET', 'get' => ['tab' => 'drinks'], 'setup' => $naSetup, 'vars' => $vars, 'verify' => [], 'as_username' => (string)$na['username'],
        'grep' => ['class="mm-sel"', 'id="mmMoveBtn"', 'id="mmBulkDel"', 'id="mmDelAll"']]);
    $f = $r['found'] ?? [];
    check($clean($r) && ($r['bytes'] ?? 0) > 5000 && ($f['class="mm-sel"'] ?? false) && ($f['id="mmMoveBtn"'] ?? false) && !($f['id="mmBulkDel"'] ?? true) && !($f['id="mmDelAll"'] ?? true),
        'Page: a non-administrator gets tick boxes and Move on Drinks, but no Delete selected / Delete all', json_encode($f ?: ($r['raw'] ?? $r)));
    $r = $run(['post' => ['action' => 'menu_move_category', 'menu_type' => 'drinks', 'category' => $dst, 'ids' => ['{c}']], 'ajax' => true, 'setup' => $naSetup, 'vars' => $vars, 'as_username' => (string)$na['username'],
        'verify' => ["SELECT category AS q FROM drink_menu WHERE item_name = 'ZZ Test $hr C'"]]);
    check($clean($r) && ($r['json']['success'] ?? false) === true && $v($r, 0) === $dst, 'Move: a non-administrator who can edit menu items may move (rolled back)', json_encode($r['json'] ?? $r['raw'] ?? $r));
    $r = $run(['post' => ['action' => 'menu_bulk_delete', 'menu_type' => 'drinks', 'ids' => ['{c}']], 'ajax' => true, 'setup' => $naSetup, 'vars' => $vars, 'as_username' => (string)$na['username'],
        'verify' => ["SELECT COUNT(*) AS q FROM drink_menu WHERE item_name = 'ZZ Test $hr C'"]]);
    check($clean($r) && ($r['json']['success'] ?? true) === false && (int)$v($r, 0) === 1, 'Delete selected stays refused for a non-administrator', json_encode($r['json'] ?? $r['raw'] ?? $r));
    $r = $run(['post' => ['action' => 'menu_move_category', 'menu_type' => 'food', 'category' => $dst, 'ids' => ['{c}']], 'ajax' => true, 'setup' => $naSetup, 'vars' => $vars, 'as_username' => (string)$na['username'],
        'verify' => ["SELECT category AS q FROM drink_menu WHERE item_name = 'ZZ Test $hr C'"]]);
    check($clean($r) && ($r['json']['success'] ?? true) === false && strpos((string)($r['json']['message'] ?? $r['json']['error'] ?? ''), 'Clear Stock Data') !== false,
        'Move: a non-administrator posting menu_type=food is refused (food moves are admin-only)', json_encode($r['json'] ?? $r['raw'] ?? $r));
} else {
    echo "SKIP non-administrator checks: no active non-admin account\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
