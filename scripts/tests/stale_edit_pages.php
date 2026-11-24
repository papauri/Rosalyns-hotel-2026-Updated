<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Stale-edit guard on the edit pages (.claude/CONCURRENCY_PLAN.md tasks 7, 10, 11): a Save made after someone else changed
 * the record's form fields is refused with nothing written; background changes (payments, rooms_available from bookings,
 * housekeeping status) never refuse it.
 * Usage (repo root): php scripts/tests/stale_edit_pages.php [--page=edit-booking|room-type|room|menu|ingredient]   (no --page = all)
 * Menu (task 8, food AND drinks): the menu cases seed their own website rows and till copies inside each rolled-back run.
 * Stock item (task 9, --page=ingredient): seeds its own ZZSE item inside each rolled-back run.
 * Every page run goes through scripts/tests/page_harness.php (one outer transaction that never commits, rolled back).
 * Room-type and room cases use one EXISTING row each and only change it inside those rolled-back runs; the booking cases
 * seed their booking inside the run. Reads in this process are plain SELECTs, except reserving a booking id (insert +
 * rollback, the same as room_guest_name.php). Refuses a LIVE database.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
require_once $root . '/includes/stale-edit.php';
$liveGuard = $root . '/.claude/LIVE_TARGETS';
if (!defined('DB_NAME') || (string)DB_NAME === '') {
    exit("Refused: no database name.\n");
}
if (is_file($liveGuard) && stripos((string)file_get_contents($liveGuard), (string)DB_NAME) !== false) {
    exit("Refused: " . DB_NAME . " is listed in .claude/LIVE_TARGETS.\n");
}
$harness = $root . '/scripts/tests/page_harness.php';
$only = '';
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--page=') === 0) { $only = substr($a, 7); }
}
if ($only !== '' && !in_array($only, ['edit-booking', 'room-type', 'room', 'menu', 'ingredient'], true)) {
    exit("Unknown --page (use edit-booking, room-type, room, menu or ingredient).\n");
}

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": " . substr($detail, 0, 600) : '') . "\n"; }
}
function se_run(string $page, array $cfg): array {
    global $harness;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1';
    $out = shell_exec($cmd);
    if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -800)]; } }
    if (!empty($res['leak']) || !empty($res['marker_lost'])) {
        echo "HARNESS LEAK on $page: stopping.\n";
        exit(2);
    }
    return $res;
}
function se_clean(array $r): bool { return !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']); }
function se_q(string $v): string { return "'" . str_replace("'", "''", $v) . "'"; }
/** SQL that computes rh_stale_hash() of one row (md5 of PHP serialize() of the string values, NULL kept). */
function se_hash_sql(string $table, array $cols, string $idExpr): string {
    $parts = ["'a:" . count($cols) . ":{'"];
    foreach ($cols as $i => $c) {
        $v = "CONVERT(`$c` USING utf8mb4)";
        $parts[] = "'i:$i;'";
        $parts[] = "IF(`$c` IS NULL, 'N;', CONCAT('s:', LENGTH($v), ':\"', $v, '\";'))";
    }
    $parts[] = "'}'";
    return "SELECT MD5(CONCAT(" . implode(', ', $parts) . ")) FROM `$table` WHERE id = $idExpr";
}
function se_row(PDO $pdo, string $table, array $cols, int $id): ?array {
    $st = $pdo->prepare('SELECT `' . implode('`, `', $cols) . "` FROM `$table` WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
/** The _row_hash a rendered page put into the openEditModal(...) data for record $id ('' when not found). */
function se_page_hash(string $html, int $id): string {
    if (!preg_match_all("/openEditModal\\(([^']*?)\\)'/s", $html, $m)) { return ''; }
    foreach ($m[1] as $arg) {
        $arg = html_entity_decode($arg, ENT_QUOTES, 'UTF-8');
        $end = strpos($arg, '}, [');
        $json = $end !== false ? substr($arg, 0, $end + 1) : $arg;
        $d = json_decode($json, true);
        if (is_array($d) && (int)($d['id'] ?? 0) === $id) { return (string)($d['_row_hash'] ?? ''); }
    }
    return '';
}
$tmpDir = sys_get_temp_dir();
$dumpFile = $tmpDir . '/stale_edit_pages_' . getmypid() . '.html';
register_shutdown_function(function () use ($dumpFile) { if (is_file($dumpFile)) { @unlink($dumpFile); } });
function se_get_html(string $page, array $cfg): string {
    global $dumpFile;
    if (is_file($dumpFile)) { @unlink($dumpFile); }
    $r = se_run($page, $cfg + ['method' => 'GET', 'dump_html' => $dumpFile]);
    ok("  GET $page rolled back", se_clean($r), json_encode([$r['error'] ?? null, $r['raw'] ?? null]));
    return is_file($dumpFile) ? (string)file_get_contents($dumpFile) : '';
}

$ebCols = ['guest_name', 'room_guest_name', 'guest_email', 'guest_phone', 'guest_country', 'special_requests',
    'check_in_date', 'check_out_date', 'number_of_guests', 'child_guests', 'occupancy_type', 'individual_room_id'];
$rmCols = ['name', 'description', 'short_description', 'price_per_night', 'price_single_occupancy', 'price_double_occupancy',
    'price_triple_occupancy', 'child_price_multiplier', 'child_charge_mode', 'child_charge_amount', 'free_children',
    'max_children', 'single_occupancy_enabled', 'double_occupancy_enabled', 'triple_occupancy_enabled', 'children_allowed',
    'size_sqm', 'max_guests', 'total_rooms', 'bed_type', 'amenities', 'is_featured', 'is_active'];
$irCols = ['room_type_id', 'room_number', 'room_name', 'floor', 'max_guests_override', 'child_price_multiplier',
    'child_charge_mode', 'child_charge_amount', 'free_children', 'max_children', 'single_occupancy_enabled_override',
    'double_occupancy_enabled_override', 'triple_occupancy_enabled_override', 'children_allowed_override', 'notes',
    'is_active', 'display_order'];

// The pages must use the same column lists as this test (a changed list has to change the test too).
$src = [
    'edit-booking' => (string)file_get_contents($root . '/admin/edit-booking.php'),
    'room-type' => (string)file_get_contents($root . '/admin/room-management.php'),
    'room' => (string)file_get_contents($root . '/admin/individual-rooms.php'),
];
$staleMsgTail = 'since you opened it, so nothing was saved. Reload the page to see the new details, then make your change again.';
$oldPageMsg = 'This page was opened before a change to how saving works. Reload it and try again.';

/* ========================= Edit booking (task 7) ========================= */
if ($only === '' || $only === 'edit-booking') {
    echo "== edit-booking.php ==\n";
    ok('page hashes the 12 form columns', strpos($src['edit-booking'], "'occupancy_type', 'individual_room_id'];") !== false && strpos($src['edit-booking'], 'rh_stale_field($eb_row_hash)') !== false);
    // SQL hash = PHP hash on a real row (read-only), so the seeded cases below can compute the hash inside the run.
    $realB = (int)$pdo->query("SELECT id FROM bookings WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($realB > 0) {
        $phpH = rh_stale_hash(se_row($pdo, 'bookings', $ebCols, $realB), $ebCols);
        ok('SQL-computed booking hash equals rh_stale_hash', (string)$pdo->query(se_hash_sql('bookings', $ebCols, (string)$realB))->fetchColumn() === $phpH);
    }

    $ci = date('Y-m-d', strtotime('+655 day'));
    $co = date('Y-m-d', strtotime('+657 day'));
    $type = null;
    foreach ($pdo->query("SELECT r.* FROM rooms r WHERE r.is_active = 1 ORDER BY r.id")->fetchAll(PDO::FETCH_ASSOC) as $cand) {
        if (roomTypeHasActiveCombinations((int)$cand['id'])) { continue; }
        $av = checkRoomAvailability((int)$cand['id'], $ci, $co);
        if (!empty($av['available']) && (int)($av['remaining_rooms'] ?? 0) >= 1 && (int)$cand['max_guests'] >= 2) { $type = $cand; break; }
    }
    if (!$type) {
        ok('skipped: no plain room type with a free room on this database', true);
    } else {
        $rid = (int)$type['id'];
        $seedSql = function (?int $id, string $ref) use ($rid, $ci, $co): string {
            return "INSERT INTO bookings (" . ($id === null ? '' : 'id, ') . "booking_reference, room_id, individual_room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights,
                number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, status, payment_status, occupancy_type)
                VALUES (" . ($id === null ? '' : "$id, ") . se_q($ref) . ", $rid, NULL, 'SEPTEST guest', 'septest@example.invalid', '+265000000071', " . se_q($ci) . ", " . se_q($co) . ", 2,
                2, 2, 0, 200, 200, 'confirmed', 'unpaid', 'double')";
        };
        // Reserve a real booking id below the counter (insert + rollback): naming it in the seed cannot move AUTO_INCREMENT.
        $pdo->beginTransaction();
        try {
            $pdo->exec($seedSql(null, 'SEPR' . random_int(100000, 999999)));
            $bid = (int)$pdo->lastInsertId();
        } finally { $pdo->rollBack(); }
        ok('reserved a booking id', $bid > 0);
        $ref = 'SEPTEST' . $bid;
        $seed = [$seedSql($bid, $ref)];
        $hashSql = se_hash_sql('bookings', $ebCols, (string)$bid);
        $post = ['guest_name' => 'SEPTEST guest edited', 'guest_email' => 'septest@example.invalid', 'guest_phone' => '+265000000072', 'guest_country' => '',
            'check_in_date' => $ci, 'check_out_date' => $co, 'number_of_guests' => '2', 'child_guests' => '0', 'occupancy_type' => 'double',
            'individual_room_id' => '', 'notify_guest' => '0', 'room_guest_name' => '', 'special_requests' => ''];
        $vRow = "SELECT guest_name, guest_phone, amount_paid FROM bookings WHERE id = $bid";
        $vAudit = "SELECT COUNT(*) AS n FROM booking_audit_log WHERE booking_id = $bid AND action = 'modified'";
        $auditRow = function (string $who, string $field, string $at) use ($bid, $ref): string {
            return "INSERT INTO booking_audit_log (booking_id, booking_reference, action, old_values, new_values, changed_fields, note, performed_by, performed_by_name, performed_at)
                VALUES ($bid, " . se_q($ref) . ", 'modified', '{}', " . se_q(json_encode([$field => 'x'])) . ", " . se_q(json_encode([$field])) . ", 'stale-edit test', NULL, " . se_q($who) . ", " . se_q($at) . ")";
        };

        // 1. Fresh hash: saved.
        $r = se_run('admin/edit-booking.php', ['post' => $post + ['_row_hash' => '{h}'], 'get' => ['id' => (string)$bid], 'setup' => $seed, 'vars' => ['h' => $hashSql], 'verify' => [$vRow, $vAudit]]);
        ok('fresh hash: the edit is saved', ($r['verify'][0][0]['guest_name'] ?? '') === 'SEPTEST guest edited' && ($r['verify'][0][0]['guest_phone'] ?? '') === '+265000000072', json_encode([$r['output_flash'] ?? null, $r['verify'] ?? null, $r['raw'] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 2. Stale: someone else changed the phone (and later a payment person touched the booking) after the form was opened.
        $setupStale = array_merge($seed, [
            "SET @se_h0 = (" . $hashSql . ")",
            "UPDATE bookings SET guest_phone = '+265000000999' WHERE id = $bid",
            $auditRow('Stale Tester', 'guest_phone', '2026-11-20 09:15:00'),
            $auditRow('Payment Person', 'payment_status', '2026-11-21 10:00:00'),
        ]);
        $r = se_run('admin/edit-booking.php', ['post' => $post + ['_row_hash' => '{h}'], 'get' => ['id' => (string)$bid], 'setup' => $setupStale, 'vars' => ['h' => 'SELECT @se_h0'], 'verify' => [$vRow, $vAudit],
            'grep' => ['This booking was changed by Stale Tester on 20 Nov 2026 at 09:15 ' . $staleMsgTail, 'Payment Person']]);
        ok('stale: refused, nothing written (row keeps the other person\'s phone and the old name)', ($r['verify'][0][0]['guest_phone'] ?? '') === '+265000000999' && ($r['verify'][0][0]['guest_name'] ?? '') === 'SEPTEST guest', json_encode([$r['output_flash'] ?? null, $r['verify'] ?? null]));
        ok('stale: no "modified" audit row from the refused save', (int)($r['verify'][1][0]['n'] ?? -1) === 2, json_encode($r['verify'][1] ?? null));
        ok('stale: the message names who changed a form field and when (not the later payment person)', !empty($r['found']['This booking was changed by Stale Tester on 20 Nov 2026 at 09:15 ' . $staleMsgTail]) && empty($r['found']['Payment Person']), json_encode([$r['found'] ?? null, $r['output_flash'] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 2b. Stale with no audit row: the message names nobody.
        $r = se_run('admin/edit-booking.php', ['post' => $post + ['_row_hash' => '{h}'], 'get' => ['id' => (string)$bid],
            'setup' => array_merge($seed, ["SET @se_h0 = (" . $hashSql . ")", "UPDATE bookings SET special_requests = 'late arrival' WHERE id = $bid"]),
            'vars' => ['h' => 'SELECT @se_h0'], 'verify' => [$vRow], 'grep' => ['This booking was changed by someone else ' . $staleMsgTail]]);
        ok('stale without an audit row: refused, names nobody', ($r['verify'][0][0]['guest_name'] ?? '') === 'SEPTEST guest' && !empty($r['found']['This booking was changed by someone else ' . $staleMsgTail]), json_encode([$r['output_flash'] ?? null, $r['verify'] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 3. A payment in between is not a form change: saved.
        $r = se_run('admin/edit-booking.php', ['post' => $post + ['_row_hash' => '{h}'], 'get' => ['id' => (string)$bid],
            'setup' => array_merge($seed, ["SET @se_h0 = (" . $hashSql . ")", "UPDATE bookings SET amount_paid = 100, payment_status = 'partial' WHERE id = $bid",
                $auditRow('Payment Person', 'payment_status', '2026-11-21 10:00:00')]),
            'vars' => ['h' => 'SELECT @se_h0'], 'verify' => [$vRow]]);
        // (amount_paid itself is then re-derived from the payments ledger by recalculateBookingFinancials, as before.)
        ok('payment / amount_paid change in between: NOT refused, saved', ($r['verify'][0][0]['guest_name'] ?? '') === 'SEPTEST guest edited' && ($r['verify'][0][0]['guest_phone'] ?? '') === '+265000000072', json_encode([$r['output_flash'] ?? null, $r['verify'] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 4. Missing hash: refused.
        $r = se_run('admin/edit-booking.php', ['post' => $post, 'get' => ['id' => (string)$bid], 'setup' => $seed, 'verify' => [$vRow], 'grep' => [$oldPageMsg]]);
        ok('missing hash: refused with the reload message, nothing written', ($r['verify'][0][0]['guest_name'] ?? '') === 'SEPTEST guest' && !empty($r['found'][$oldPageMsg]), json_encode([$r['output_flash'] ?? null, $r['verify'] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 5. Round trip: the hash the page renders is the one its Save accepts; two loads give the same hash.
        $html1 = se_get_html('admin/edit-booking.php', ['get' => ['id' => (string)$bid], 'setup' => $seed]);
        $html2 = se_get_html('admin/edit-booking.php', ['get' => ['id' => (string)$bid], 'setup' => $seed]);
        $h1 = preg_match('/name="_row_hash" value="([a-f0-9]{32})"/', $html1, $m1) ? $m1[1] : '';
        $h2 = preg_match('/name="_row_hash" value="([a-f0-9]{32})"/', $html2, $m2) ? $m2[1] : '';
        ok('form renders a hash, the same on two loads', $h1 !== '' && $h1 === $h2, "$h1 / $h2");
        $r = se_run('admin/edit-booking.php', ['post' => $post + ['_row_hash' => $h1], 'get' => ['id' => (string)$bid], 'setup' => $seed, 'verify' => [$vRow]]);
        ok('round trip: the rendered hash saves', ($r['verify'][0][0]['guest_name'] ?? '') === 'SEPTEST guest edited', json_encode([$r['output_flash'] ?? null, $r['verify'] ?? null]));
        ok('  run rolled back', se_clean($r));
    }
}

/* ========================= Room type (task 10) ========================= */
if ($only === '' || $only === 'room-type') {
    echo "== room-management.php ==\n";
    $rt = $pdo->query("SELECT * FROM rooms WHERE is_active = 1 AND rooms_available > 0 AND total_rooms > 0 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$rt) {
        ok('skipped: no active room type with rooms available', true);
    } else {
        $rtId = (int)$rt['id'];
        $h0 = rh_stale_hash(se_row($pdo, 'rooms', $rmCols, $rtId), $rmCols);
        ok('SQL-computed room-type hash equals rh_stale_hash', (string)$pdo->query(se_hash_sql('rooms', $rmCols, (string)$rtId))->fetchColumn() === $h0);
        $newPrice = number_format((float)$rt['price_per_night'] + 1, 2, '.', '');
        $base = ['action' => 'update', 'id' => (string)$rtId, 'name' => (string)$rt['name'], 'description' => (string)$rt['description'],
            'short_description' => (string)$rt['short_description'], 'price_per_night' => $newPrice,
            'price_single_occupancy' => (string)$rt['price_single_occupancy'], 'price_double_occupancy' => (string)$rt['price_double_occupancy'],
            'price_triple_occupancy' => (string)$rt['price_triple_occupancy'], 'child_charge_mode' => (string)($rt['child_charge_mode'] ?? 'percent'),
            'child_price_multiplier' => (string)$rt['child_price_multiplier'], 'child_charge_amount' => (string)$rt['child_charge_amount'],
            'free_children' => (string)$rt['free_children'], 'max_children' => (string)$rt['max_children'], 'size_sqm' => (string)$rt['size_sqm'],
            'max_guests' => (string)$rt['max_guests'], 'rooms_available' => (string)$rt['rooms_available'], 'rooms_available_loaded' => (string)$rt['rooms_available'],
            'total_rooms' => (string)$rt['total_rooms'], 'bed_type' => (string)$rt['bed_type'], 'amenities' => (string)$rt['amenities'],
            'image_url' => (string)$rt['image_url'], 'display_order' => (string)$rt['display_order']];
        foreach (['triple_occupancy_enabled', 'children_allowed', 'is_featured', 'is_active'] as $cb) {
            if ((int)$rt[$cb] === 1) { $base[$cb] = '1'; }
        }
        $vRt = "SELECT price_per_night, rooms_available, is_featured FROM rooms WHERE id = $rtId";
        $vHist = "SELECT COUNT(*) AS n FROM room_price_history WHERE entity_type = 'room_type' AND entity_id = $rtId";
        $histBefore = (int)$pdo->query($vHist)->fetchColumn();
        $hashSql = se_hash_sql('rooms', $rmCols, (string)$rtId);

        // 1. Fresh hash: saved, and the reply carries the saved row's hash (so a second Save from the open window passes).
        $r = se_run('admin/room-management.php', ['post' => $base + ['_row_hash' => $h0], 'ajax' => true, 'verify' => [$vRt, $vHist, $hashSql]]);
        ok('fresh hash: saved', !empty($r['json']['success']) && ($r['verify'][0][0]['price_per_night'] ?? '') === $newPrice, json_encode([$r['json'] ?? null, $r['verify'][0] ?? null, $r['raw'] ?? null]));
        $afterHash = (string)(array_values($r['verify'][2][0] ?? [])[0] ?? '');
        ok('reply row_hash = the saved row\'s hash (save twice in a row works)', ($r['json']['row_hash'] ?? '') !== '' && ($r['json']['row_hash'] ?? '') === $afterHash, json_encode([$r['json'] ?? null, $afterHash]));
        ok('reply carries the kept rooms_available', (string)($r['json']['rooms_available'] ?? '') === (string)$rt['rooms_available']);
        ok('  run rolled back', se_clean($r));

        // 2. Stale price edit: refused, price unchanged, no price-history row.
        $r = se_run('admin/room-management.php', ['post' => $base + ['_row_hash' => $h0], 'ajax' => true,
            'setup' => ["UPDATE rooms SET price_per_night = price_per_night + 7 WHERE id = $rtId"], 'verify' => [$vRt, $vHist]]);
        $exp = number_format((float)$rt['price_per_night'] + 7, 2, '.', '');
        ok('stale: refused with the room-type message', empty($r['json']['success']) && strpos((string)($r['json']['message'] ?? ''), 'This room type was changed by someone else ' . $staleMsgTail) === 0, json_encode($r['json'] ?? $r['raw']));
        ok('stale: price keeps the other person\'s value', ($r['verify'][0][0]['price_per_night'] ?? '') === $exp, json_encode($r['verify'][0] ?? null));
        ok('stale: no price-history row', (int)($r['verify'][1][0]['n'] ?? -1) === $histBefore, json_encode($r['verify'][1] ?? null));
        ok('  run rolled back', se_clean($r));

        // 3. A booking takes a room in between (rooms_available - 1): NOT refused, and the save does not put it back.
        $r = se_run('admin/room-management.php', ['post' => $base + ['_row_hash' => $h0], 'ajax' => true,
            'setup' => ["UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = $rtId AND rooms_available > 0"], 'verify' => [$vRt]]);
        ok('rooms_available -1 in between: saved', !empty($r['json']['success']) && ($r['verify'][0][0]['price_per_night'] ?? '') === $newPrice, json_encode([$r['json'] ?? null, $r['verify'][0] ?? null]));
        ok('rooms_available -1 in between: the booking\'s figure is kept (not overwritten)', (int)($r['verify'][0][0]['rooms_available'] ?? -1) === (int)$rt['rooms_available'] - 1, json_encode($r['verify'][0] ?? null));
        ok('  run rolled back', se_clean($r));

        // 3b. The person changes availability themself: the posted figure is written.
        $mine = max(0, (int)$rt['rooms_available'] - 1);
        $r = se_run('admin/room-management.php', ['post' => ['rooms_available' => (string)$mine] + $base + ['_row_hash' => $h0], 'ajax' => true, 'verify' => [$vRt]]);
        ok('availability changed in the window: written', !empty($r['json']['success']) && (int)($r['verify'][0][0]['rooms_available'] ?? -1) === $mine, json_encode([$r['json'] ?? null, $r['verify'][0] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 4. Own toggle, then Save with the hash the toggle returned: saved.
        $r = se_run('admin/room-management.php', ['post' => ['action' => 'toggle_featured', 'id' => (string)$rtId], 'ajax' => true, 'verify' => [$hashSql]]);
        $toggleHash = (string)($r['json']['row_hash'] ?? '');
        ok('toggle reply carries the new hash', $toggleHash !== '' && $toggleHash === (string)(array_values($r['verify'][0][0] ?? [])[0] ?? ''), json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
        ok('  run rolled back', se_clean($r));
        $postT = $base + ['_row_hash' => $toggleHash];
        if ((int)$rt['is_featured'] === 1) { unset($postT['is_featured']); } else { $postT['is_featured'] = '1'; }
        $r = se_run('admin/room-management.php', ['post' => $postT, 'ajax' => true, 'setup' => ["UPDATE rooms SET is_featured = NOT is_featured WHERE id = $rtId"], 'verify' => [$vRt]]);
        ok('own toggle then Save: saved', !empty($r['json']['success']) && ($r['verify'][0][0]['price_per_night'] ?? '') === $newPrice, json_encode([$r['json'] ?? null, $r['verify'][0] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 5. Missing hash: refused.
        $r = se_run('admin/room-management.php', ['post' => $base, 'ajax' => true, 'verify' => [$vRt]]);
        ok('missing hash: refused, nothing written', empty($r['json']['success']) && ($r['json']['message'] ?? '') === $oldPageMsg && ($r['verify'][0][0]['price_per_night'] ?? '') === (string)$rt['price_per_night'], json_encode([$r['json'] ?? null, $r['verify'][0] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 6. Round trip: the hash in the Edit button is the stored row's hash, the same on two loads.
        $html1 = se_get_html('admin/room-management.php', []);
        $html2 = se_get_html('admin/room-management.php', []);
        $p1 = se_page_hash($html1, $rtId);
        ok('Edit button carries the row hash, the same on two loads', $p1 === $h0 && se_page_hash($html2, $rtId) === $h0, "$p1 / $h0");
        ok('Edit window has the hash and loaded-availability fields', strpos($html1, 'name="_row_hash" id="editRowHash"') !== false && strpos($html1, 'name="rooms_available_loaded"') !== false);
    }
}

/* ========================= Room (task 11) ========================= */
if ($only === '' || $only === 'room') {
    echo "== individual-rooms.php ==\n";
    // A room that is not part of an active joined pair (its Edit button is the plain room one).
    $ir = $pdo->query("SELECT * FROM individual_rooms ir WHERE ir.is_active = 1
        AND ir.id NOT IN (SELECT room_a_id FROM room_combinations WHERE is_active = 1 UNION SELECT room_b_id FROM room_combinations WHERE is_active = 1)
        ORDER BY ir.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$ir) {
        ok('skipped: no active room outside a joined pair', true);
    } else {
        $irId = (int)$ir['id'];
        $h0 = rh_stale_hash(se_row($pdo, 'individual_rooms', $irCols, $irId), $irCols);
        $hashSql = se_hash_sql('individual_rooms', $irCols, (string)$irId);
        ok('SQL-computed room hash equals rh_stale_hash', (string)$pdo->query($hashSql)->fetchColumn() === $h0);
        $newNum = substr((string)$ir['room_number'], 0, 40) . 'SE' . random_int(100, 999);
        $str = fn($v) => $v === null ? '' : (string)$v;
        $base = ['action' => 'update_individual_room', 'id' => (string)$irId, 'room_type_id' => (string)$ir['room_type_id'], 'room_number' => $newNum,
            'room_name' => $str($ir['room_name']), 'floor' => $str($ir['floor']), 'max_guests_override' => $str($ir['max_guests_override']),
            'child_charge_mode' => $str($ir['child_charge_mode']), 'child_price_multiplier' => $str($ir['child_price_multiplier']),
            'child_charge_amount' => $str($ir['child_charge_amount']), 'free_children' => $str($ir['free_children']), 'max_children' => $str($ir['max_children']),
            'single_occupancy_enabled_override' => $str($ir['single_occupancy_enabled_override']), 'double_occupancy_enabled_override' => $str($ir['double_occupancy_enabled_override']),
            'triple_occupancy_enabled_override' => $str($ir['triple_occupancy_enabled_override']), 'children_allowed_override' => $str($ir['children_allowed_override']),
            'notes' => $str($ir['notes']), 'is_active' => '1', 'display_order' => (string)(int)$ir['display_order'], 'status' => (string)$ir['status'],
            'amenities_list' => '', 'photos_list' => ''];
        // The page saves amenities / photos from the window: post the stored ones so the save leaves them as they are.
        $am = $pdo->prepare("SELECT amenity_label FROM individual_room_amenities WHERE individual_room_id = ? ORDER BY display_order, id");
        $am->execute([$irId]);
        $base['amenities_list'] = implode(', ', $am->fetchAll(PDO::FETCH_COLUMN));
        $ph = $pdo->prepare("SELECT image_path FROM individual_room_photos WHERE individual_room_id = ? ORDER BY display_order, id");
        $ph->execute([$irId]);
        $base['photos_list'] = implode("\n", $ph->fetchAll(PDO::FETCH_COLUMN));
        $vIr = "SELECT room_number, status, children_allowed_override FROM individual_rooms WHERE id = $irId";
        $reopenNeedle = fn(string $h) => '"_row_hash":"' . $h . '"';
        $irStaleMsg = 'This room was changed by someone else ' . $staleMsgTail;

        // 1. Fresh hash: saved.
        $r = se_run('admin/individual-rooms.php', ['post' => $base + ['_row_hash' => $h0], 'verify' => [$vIr]]);
        ok('fresh hash: saved', ($r['verify'][0][0]['room_number'] ?? '') === $newNum, json_encode([$r['output_flash'] ?? null, $r['verify'] ?? null, $r['raw'] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 2. Stale room_number edit (someone else changed the notes): refused, reopened with the POSTED hash.
        $r = se_run('admin/individual-rooms.php', ['post' => $base + ['_row_hash' => $h0], 'setup' => ["UPDATE individual_rooms SET notes = CONCAT(COALESCE(notes, ''), ' x') WHERE id = $irId"],
            'verify' => [$vIr], 'grep' => [$irStaleMsg, $reopenNeedle($h0)]]);
        ok('stale: refused, room number unchanged', ($r['verify'][0][0]['room_number'] ?? '') === (string)$ir['room_number'] && !empty($r['found'][$irStaleMsg]), json_encode([$r['found'] ?? null, $r['output_flash'] ?? null, $r['verify'] ?? null]));
        ok('stale: the reopened window keeps the posted hash', !empty($r['found'][$reopenNeedle($h0)]), json_encode($r['found'] ?? null));
        ok('  run rolled back', se_clean($r));

        // 3. Housekeeping status change in between: NOT refused.
        $r = se_run('admin/individual-rooms.php', ['post' => $base + ['_row_hash' => $h0],
            'setup' => ["UPDATE individual_rooms SET status = IF(status = 'cleaning', 'available', 'cleaning'), housekeeping_status = 'pending' WHERE id = $irId"], 'verify' => [$vIr]]);
        ok('housekeeping status change in between: saved', ($r['verify'][0][0]['room_number'] ?? '') === $newNum, json_encode([$r['output_flash'] ?? null, $r['verify'] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 4. Another user's children override in between: refused.
        $otherOverride = $ir['children_allowed_override'] === null ? '1' : ((int)$ir['children_allowed_override'] === 1 ? '0' : 'NULL');
        $r = se_run('admin/individual-rooms.php', ['post' => $base + ['_row_hash' => $h0], 'setup' => ["UPDATE individual_rooms SET children_allowed_override = $otherOverride WHERE id = $irId"],
            'verify' => [$vIr], 'grep' => [$irStaleMsg]]);
        ok('another user\'s children override: refused', ($r['verify'][0][0]['room_number'] ?? '') === (string)$ir['room_number'] && !empty($r['found'][$irStaleMsg]), json_encode([$r['found'] ?? null, $r['verify'] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 4b. Own children override: the reply carries the room's new hash, and a Save with it passes.
        $ovPost = $otherOverride === 'NULL' ? 'null' : $otherOverride;
        $r = se_run('admin/individual-rooms.php', ['post' => ['action' => 'set_children_override', 'id' => (string)$irId, 'children_allowed_override' => $ovPost], 'ajax' => true, 'verify' => [$hashSql]]);
        $ovHash = (string)($r['json']['row_hashes'][(string)$irId] ?? '');
        ok('children override reply carries the new hash', $ovHash !== '' && $ovHash === (string)(array_values($r['verify'][0][0] ?? [])[0] ?? ''), json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
        ok('  run rolled back', se_clean($r));
        $r = se_run('admin/individual-rooms.php', ['post' => ['children_allowed_override' => $otherOverride === 'NULL' ? '' : $otherOverride] + $base + ['_row_hash' => $ovHash],
            'setup' => ["UPDATE individual_rooms SET children_allowed_override = $otherOverride WHERE id = $irId"], 'verify' => [$vIr]]);
        ok('own children override then Save with the returned hash: saved', ($r['verify'][0][0]['room_number'] ?? '') === $newNum, json_encode([$r['output_flash'] ?? null, $r['verify'] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 4c. A joined pair: one click returns a hash for BOTH rooms.
        $pair = $pdo->query("SELECT room_a_id, room_b_id FROM room_combinations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($pair) {
            $a = (int)$pair['room_a_id']; $b = (int)$pair['room_b_id'];
            $r = se_run('admin/individual-rooms.php', ['post' => ['action' => 'set_children_override', 'id' => "$a,$b", 'children_allowed_override' => '1'], 'ajax' => true,
                // Read both rows back and hash them here (two hash SQLs overflow the Windows command line).
                'verify' => ["SELECT id, `" . implode('`, `', $irCols) . "` FROM individual_rooms WHERE id IN ($a, $b)"]]);
            $rh = $r['json']['row_hashes'] ?? [];
            $want = [];
            foreach ((array)($r['verify'][0] ?? []) as $vr) { $want[(string)$vr['id']] = rh_stale_hash($vr, $irCols); }
            ok('pair children override: one new hash per room', count($want) === 2 && ($rh[(string)$a] ?? '') === ($want[(string)$a] ?? 'x') && ($rh[(string)$b] ?? '') === ($want[(string)$b] ?? 'x'), json_encode([$r['json'] ?? null, $want]));
            ok('  run rolled back', se_clean($r));
        } else {
            ok('skipped: no active joined pair for the pair children-override case', true);
        }

        // 5. Missing hash: refused.
        $r = se_run('admin/individual-rooms.php', ['post' => $base, 'verify' => [$vIr], 'grep' => [$oldPageMsg]]);
        ok('missing hash: refused, nothing written', ($r['verify'][0][0]['room_number'] ?? '') === (string)$ir['room_number'] && !empty($r['found'][$oldPageMsg]), json_encode([$r['found'] ?? null, $r['output_flash'] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 6. A validation error reopens the window with the POSTED hash (the person's typed values stay, so must the hash).
        $r = se_run('admin/individual-rooms.php', ['post' => ['room_number' => ''] + $base + ['_row_hash' => $h0], 'verify' => [$vIr], 'grep' => [$reopenNeedle($h0)]]);
        ok('validation error: reopened with the posted hash, nothing written', ($r['verify'][0][0]['room_number'] ?? '') === (string)$ir['room_number'] && !empty($r['found'][$reopenNeedle($h0)]), json_encode([$r['found'] ?? null, $r['output_flash'] ?? null]));
        ok('  run rolled back', se_clean($r));

        // 7. Round trip: Edit button and joined-pair member rooms carry the stored hash, the same on two loads.
        $html1 = se_get_html('admin/individual-rooms.php', []);
        $html2 = se_get_html('admin/individual-rooms.php', []);
        $p1 = se_page_hash($html1, $irId);
        ok('Edit button carries the row hash, the same on two loads', $p1 === $h0 && se_page_hash($html2, $irId) === $h0, "$p1 / $h0");
        // Member rooms are listed only for joined-only pairs (a shared-mode pair stays two separate rows).
        // A pair is joined-only when both rooms are of the pair's own type; when none is, make the first active pair one
        // inside the rolled-back run, and read the two rows back in that same run to compare.
        $jp = rh_joined_only_pairs(true)[0] ?? null;
        $jpSetup = [];
        if (!$jp && $pair) {
            $jpc = $pdo->query("SELECT room_a_id, room_b_id, combined_room_type_id FROM room_combinations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $jp = ['room_a_id' => (int)$jpc['room_a_id'], 'room_b_id' => (int)$jpc['room_b_id']];
            $jpSetup = ["UPDATE individual_rooms SET room_type_id = " . (int)$jpc['combined_room_type_id'] . " WHERE id IN ({$jp['room_a_id']}, {$jp['room_b_id']})"];
        }
        if ($jp) {
            if (is_file($dumpFile)) { @unlink($dumpFile); }
            $r = se_run('admin/individual-rooms.php', ['method' => 'GET', 'dump_html' => $dumpFile, 'setup' => $jpSetup,
                'verify' => ["SELECT id, `" . implode('`, `', $irCols) . "` FROM individual_rooms WHERE id IN ({$jp['room_a_id']}, {$jp['room_b_id']})"]]);
            ok('  GET with a joined-only pair rolled back', se_clean($r));
            $want = [];
            foreach ((array)($r['verify'][0] ?? []) as $vr) { $want[(int)$vr['id']] = rh_stale_hash($vr, $irCols); }
            $htmlJ = is_file($dumpFile) ? (string)file_get_contents($dumpFile) : '';
            $members = preg_match('/const _irMemberRooms = (\{.*?\});\s*\n/s', $htmlJ, $mm) ? json_decode($mm[1], true) : null;
            $mOk = count($want) === 2;
            foreach ($want as $mid => $wh) {
                $mOk = $mOk && ($members[(string)$mid]['room']['_row_hash'] ?? '') === $wh;
            }
            ok('joined-pair member rooms (_irMemberRooms) carry their own hash', $mOk, substr((string)($mm[1] ?? ''), 0, 300));
        } else {
            ok('skipped: no joined pair for the member-room hash case', true);
        }
        $r = se_run('admin/individual-rooms.php', ['post' => $base + ['_row_hash' => $p1], 'verify' => [$vIr]]);
        ok('round trip: the rendered hash saves', ($r['verify'][0][0]['room_number'] ?? '') === $newNum, json_encode([$r['output_flash'] ?? null, $r['verify'] ?? null]));
        ok('  run rolled back', se_clean($r));
    }
}

/* ========================= Menu items, food AND drinks (task 8) ========================= */
if ($only === '' || $only === 'menu') {
    echo "== menu-management.php ==\n";
    $mmSrc = (string)file_get_contents($root . '/admin/menu-management.php');
    $fCols = ['item_name', 'description', 'price', 'category', 'is_available', 'station', 'show_pos', 'show_room_service'];
    $dCols = array_merge($fCols, ['tags']);
    ok('page hashes the 8 form columns (+ tags on drinks)', strpos($mmSrc, "\$cols = ['item_name', 'description', 'price', 'category', 'is_available', 'station', 'show_pos', 'show_room_service'];") !== false
        && strpos($mmSrc, "\$cols[] = 'tags';") !== false);

    $mh = strtoupper(bin2hex(random_bytes(3)));
    $fName = "ZZSE $mh Food"; $fCat = "ZZSE Cat $mh"; $fSlug = 'zzse-cat-' . strtolower($mh);
    $dName = "ZZSE $mh Drink"; $dCat = "ZZSE DCat $mh"; $dSlug = 'zzse-dcat-' . strtolower($mh);
    // Seed (inside every rolled-back run): one food and one drink, each with its till copy.
    $mSeed = [
        "INSERT INTO menu_categories (name, slug, business_context, default_station, sort_order, shows_on_pos, shows_on_room_service, display_order, is_active) VALUES (" . se_q($fCat) . ", '$fSlug', 'food_service', 'kitchen', 999, 1, 1, 999, 1), (" . se_q($dCat) . ", '$dSlug', 'food_service', 'bar', 999, 1, 1, 999, 1)",
        "INSERT INTO food_menu (item_name, price, category, station, is_available, show_pos, show_room_service, display_order) VALUES (" . se_q($fName) . ", 100, " . se_q($fCat) . ", 'kitchen', 1, 1, 1, 1)",
        "INSERT INTO drink_menu (item_name, price, category, station, is_available, show_pos, show_room_service, display_order, tags) VALUES (" . se_q($dName) . ", 200, " . se_q($dCat) . ", 'bar', 1, 1, 1, 1, 'cold')",
        "INSERT INTO menu_items (category_id, item_name, price, category, station) SELECT id, " . se_q($fName) . ", 100, " . se_q($fCat) . ", 'kitchen' FROM menu_categories WHERE slug = '$fSlug'",
        "INSERT INTO menu_items (category_id, item_name, price, category, station) SELECT id, " . se_q($dName) . ", 200, " . se_q($dCat) . ", 'bar' FROM menu_categories WHERE slug = '$dSlug'",
    ];
    $fIdSql = "(SELECT id FROM food_menu WHERE item_name = " . se_q($fName) . ")";
    $dIdSql = "(SELECT id FROM drink_menu WHERE item_name = " . se_q($dName) . ")";
    $mVars = ['f' => "SELECT id FROM food_menu WHERE item_name = " . se_q($fName), 'd' => "SELECT id FROM drink_menu WHERE item_name = " . se_q($dName)];
    $fHashSql = se_hash_sql('food_menu', $fCols, $fIdSql);
    $dHashSql = se_hash_sql('drink_menu', $dCols, $dIdSql);
    $vFood = "SELECT f.price AS web, (SELECT mi.price FROM menu_items mi WHERE mi.item_name = f.item_name AND mi.category = f.category) AS till, f.is_available AS avail FROM food_menu f WHERE f.item_name = " . se_q($fName);
    $vDrink = "SELECT d.price AS web, d.tags AS tags, (SELECT mi.price FROM menu_items mi WHERE mi.item_name = d.item_name AND mi.category = d.category) AS till FROM drink_menu d WHERE d.item_name = " . se_q($dName);
    $fPost = ['action' => 'update', 'menu_type' => 'food', 'id' => '{f}', 'name' => $fName, 'category' => $fCat, 'price' => '150', 'station' => 'kitchen',
        'is_available' => '1', 'show_pos' => '1', 'show_room_service' => '1', 'description' => ''];
    $dPost = ['action' => 'update', 'menu_type' => 'drinks', 'id' => '{d}', 'name' => $dName, 'category' => $dCat, 'price' => '250', 'station' => 'bar',
        'is_available' => '1', 'show_pos' => '1', 'show_room_service' => '1', 'description' => '', 'tags' => 'cold'];
    $mRun = static function (array $post, array $extraSetup = [], array $verify = []) use ($mSeed, $mVars): array {
        return se_run('admin/menu-management.php', ['post' => $post, 'ajax' => true, 'setup' => array_merge($mSeed, $extraSetup), 'vars' => $mVars, 'verify' => $verify]);
    };
    $vv = static fn(array $r, int $i, string $k) => (($r['verify'][$i] ?? [])[0] ?? [])[$k] ?? null;
    $msg = static fn(array $r): string => (string)($r['json']['message'] ?? '');
    $refused = static fn(array $r): bool => se_clean($r) && ($r['json']['success'] ?? true) === false;
    $saved = static fn(array $r): bool => se_clean($r) && ($r['json']['success'] ?? false) === true;

    // The hash of the fresh seed (the hash holds no id, so it is the same in every run that seeds the same rows).
    $r = $mRun(['action' => 'none'], [], ["SELECT ($fHashSql) AS h", "SELECT ($dHashSql) AS h"]);
    $h0 = (string)$vv($r, 0, 'h'); $h0d = (string)$vv($r, 1, 'h');
    ok('seed hashes read (food + drink)', strlen($h0) === 32 && strlen($h0d) === 32 && se_clean($r), json_encode($r['verify'] ?? $r));

    // 1. Round trip: the hash the page renders (from the raw row, price "100.00") is the one its Save accepts.
    $html = se_get_html('admin/menu-management.php', ['post' => [], 'setup' => $mSeed, 'vars' => $mVars]);
    $pageHash = static function (string $html, string $name): string {
        if (!preg_match_all('/<tr id="(?:food|drink)-row-\d+"[^>]*data-item="([^"]*)"/s', $html, $m)) { return ''; }
        foreach ($m[1] as $attr) {
            $d = json_decode(html_entity_decode($attr, ENT_QUOTES, 'UTF-8'), true);
            if (is_array($d) && ($d['name'] ?? '') === $name) { return (string)($d['row_hash'] ?? ''); }
        }
        return '';
    };
    $pf = $pageHash($html, $fName); $pd = $pageHash($html, $dName);
    ok('the page renders the raw-row hash (food)', $pf !== '' && hash_equals($h0, $pf), "page=$pf sql=$h0");
    ok('the page renders the raw-row hash (drink, tags included)', $pd !== '' && hash_equals($h0d, $pd), "page=$pd sql=$h0d");
    ok('the rendered hash is not the float-price hash', $pf !== rh_stale_hash(['item_name' => $fName, 'description' => null, 'price' => '100', 'category' => $fCat, 'is_available' => 1, 'station' => 'kitchen', 'show_pos' => 1, 'show_room_service' => 1], $fCols));
    ok('the Edit form has the hidden _row_hash field', strpos($html, 'name="_row_hash" id="mmRowHash"') !== false);
    $r = $mRun($fPost + ['_row_hash' => $pf], [], [$vFood, "SELECT ($fHashSql) AS h"]);
    ok('food: the rendered hash saves (price 150 on the website row and the till copy)', $saved($r) && (float)$vv($r, 0, 'web') === 150.0 && (float)$vv($r, 0, 'till') === 150.0, json_encode([$r['json'] ?? $r['raw'] ?? null, $r['verify'] ?? null]));
    ok('food: the Save reply carries the new hash', ($r['json']['row_hash'] ?? '') === (string)$vv($r, 1, 'h'), json_encode($r['json'] ?? null));
    $r = $mRun($dPost + ['_row_hash' => $pd], [], [$vDrink]);
    ok('drink: the rendered hash saves (price 250 on both)', $saved($r) && (float)$vv($r, 0, 'web') === 250.0 && (float)$vv($r, 0, 'till') === 250.0, json_encode([$r['json'] ?? $r['raw'] ?? null, $r['verify'] ?? null]));

    // 2. Someone else changed the price (website row and till copy) after the form was opened: refused, both prices stay theirs.
    $other = ["UPDATE food_menu SET price = 130 WHERE item_name = " . se_q($fName), "UPDATE menu_items SET price = 130 WHERE item_name = " . se_q($fName)];
    $r = $mRun($fPost + ['_row_hash' => $h0], $other, [$vFood]);
    ok('stale price edit refused', $refused($r) && strpos($msg($r), 'This menu item was changed by someone else ' . $staleMsgTail) === 0, json_encode($r['json'] ?? $r['raw'] ?? null));
    ok('  price unchanged (website 130.00) and till copy unchanged (130)', (string)$vv($r, 0, 'web') === '130.00' && (float)$vv($r, 0, 'till') === 130.0, json_encode($r['verify'] ?? null));
    $r = $mRun($dPost + ['_row_hash' => $h0d], ["UPDATE drink_menu SET tags = 'hot' WHERE item_name = " . se_q($dName)], [$vDrink]);
    ok('drink: tags changed by someone else -> refused, nothing written', $refused($r) && (string)$vv($r, 0, 'web') === '200.00' && (string)$vv($r, 0, 'tags') === 'hot' && (float)$vv($r, 0, 'till') === 200.0, json_encode([$r['json'] ?? null, $r['verify'] ?? null]));

    // 3. Own availability switch, then Save: the reply's hash is the row after the switch, and a Save with it goes through.
    $r = $mRun(['action' => 'toggle_availability', 'menu_type' => 'food', 'id' => '{f}', '_row_hash' => $h0], [], [$vFood, "SELECT ($fHashSql) AS h"]);
    $h1 = (string)($r['json']['row_hash'] ?? '');
    ok('own availability switch: done, reply hash = the row after the switch', $saved($r) && (int)$vv($r, 0, 'avail') === 0 && $h1 !== '' && $h1 === (string)$vv($r, 1, 'h'), json_encode([$r['json'] ?? $r['raw'] ?? null, $r['verify'] ?? null]));
    $afterOwnToggle = ["UPDATE food_menu SET is_available = 0 WHERE item_name = " . se_q($fName), "UPDATE menu_items SET is_available = 0 WHERE item_name = " . se_q($fName)];
    $r = $mRun(['is_available' => ''] + $fPost + ['_row_hash' => $h1], $afterOwnToggle, [$vFood]);
    ok('own toggle then Save -> saved', $saved($r) && (float)$vv($r, 0, 'web') === 150.0 && (float)$vv($r, 0, 'till') === 150.0, json_encode([$r['json'] ?? $r['raw'] ?? null, $r['verify'] ?? null]));
    // ... while another person's switch (same change, made by someone else) refuses the Save of a form opened before it.
    $r = $mRun($fPost + ['_row_hash' => $h0], $afterOwnToggle, [$vFood]);
    ok("another user's toggle then Save -> refused", $refused($r) && (string)$vv($r, 0, 'web') === '100.00' && (int)$vv($r, 0, 'avail') === 0, json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
    // A switch is never refused; it withholds the new hash when the item had changed under the page (or no hash was sent).
    $r = $mRun(['action' => 'toggle_availability', 'menu_type' => 'food', 'id' => '{f}', '_row_hash' => $h0], $other, [$vFood]);
    ok('switch after someone else changed the price: done, no new hash (the next Save is refused)', $saved($r) && (int)$vv($r, 0, 'avail') === 0 && ($r['json']['row_hash'] ?? 'x') === '', json_encode($r['json'] ?? null));
    $r = $mRun(['action' => 'toggle_availability', 'menu_type' => 'food', 'id' => '{f}'], [], [$vFood]);
    ok('switch without a hash: done, no new hash', $saved($r) && (int)$vv($r, 0, 'avail') === 0 && ($r['json']['row_hash'] ?? 'x') === '', json_encode($r['json'] ?? null));

    // 4. Own room service switch (drinks), then Save.
    $r = $mRun(['action' => 'toggle_room_service', 'menu_type' => 'drinks', 'id' => '{d}', 'value' => '0', '_row_hash' => $h0d], [], ["SELECT ($dHashSql) AS h"]);
    $h2 = (string)($r['json']['row_hash'] ?? '');
    ok('own room service switch: reply hash = the row after the switch', $saved($r) && $h2 !== '' && $h2 === (string)$vv($r, 0, 'h'), json_encode([$r['json'] ?? $r['raw'] ?? null, $r['verify'] ?? null]));
    $r = $mRun(['show_room_service' => ''] + $dPost + ['_row_hash' => $h2], ["UPDATE drink_menu SET show_room_service = 0 WHERE item_name = " . se_q($dName), "UPDATE menu_items SET show_room_service = 0 WHERE item_name = " . se_q($dName)], [$vDrink]);
    ok('own room service switch then Save -> saved', $saved($r) && (float)$vv($r, 0, 'web') === 250.0, json_encode([$r['json'] ?? $r['raw'] ?? null, $r['verify'] ?? null]));
    $r = $mRun(['action' => 'toggle_room_service', 'menu_type' => 'drinks', 'id' => '{d}', 'value' => '0', '_row_hash' => $h0d], ["UPDATE drink_menu SET price = 220 WHERE item_name = " . se_q($dName)], []);
    ok('room service switch after someone else changed the price: done, no new hash', $saved($r) && ($r['json']['row_hash'] ?? 'x') === '', json_encode($r['json'] ?? null));

    // 5. A till sale, a till-side "86", a reorder and updated_at never refuse a Save.
    $staffId = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $sale = [
        "INSERT INTO stock_orders (reference, order_type, customer_name, status, total_amount, subtotal, kitchen_status, created_by, created_at) VALUES ('ZZSE$mh', 'walk_in', 'ZZSE', 'paid', 100, 100, 'served', $staffId, NOW())",
        "INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status) SELECT (SELECT id FROM stock_orders WHERE reference = 'ZZSE$mh'), mi.id, '$fSlug', mi.item_name, 1, 100, 100, 'kitchen', 'served' FROM menu_items mi WHERE mi.item_name = " . se_q($fName),
        "UPDATE menu_items SET is_available = 0 WHERE item_name = " . se_q($fName),
        "UPDATE food_menu SET display_order = 7, updated_at = NOW() - INTERVAL 1 DAY WHERE item_name = " . se_q($fName),
    ];
    $r = $mRun($fPost + ['_row_hash' => $h0], $sale, [$vFood]);
    ok('a POS sale (plus a till-side 86, a reorder, updated_at) -> not refused', $saved($r) && (float)$vv($r, 0, 'web') === 150.0 && (float)$vv($r, 0, 'till') === 150.0, json_encode([$r['json'] ?? $r['raw'] ?? null, $r['verify'] ?? null]));

    // 6. No hash, or not a hash: refused, nothing written.
    $r = $mRun($fPost, [], [$vFood]);
    ok('missing hash -> refused, nothing written', $refused($r) && $msg($r) === $oldPageMsg && (string)$vv($r, 0, 'web') === '100.00' && (float)$vv($r, 0, 'till') === 100.0, json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
    $r = $mRun($dPost + ['_row_hash' => 'not-a-hash'], [], [$vDrink]);
    ok('a malformed hash -> refused, nothing written (drink)', $refused($r) && $msg($r) === $oldPageMsg && (string)$vv($r, 0, 'web') === '200.00', json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
    // Adding an item needs no hash.
    $r = $mRun(['action' => 'add', 'menu_type' => 'food', 'name' => "ZZSE $mh New", 'category' => $fCat, 'price' => '90', 'station' => 'kitchen', 'is_available' => '1', 'show_pos' => '1', 'show_room_service' => '1', 'description' => ''], [],
        ["SELECT COUNT(*) AS n FROM food_menu WHERE item_name = " . se_q("ZZSE $mh New")]);
    ok('add (no hash) still saves', $saved($r) && (int)$vv($r, 0, 'n') === 1 && strlen((string)($r['json']['row_hash'] ?? '')) === 32, json_encode($r['json'] ?? $r['raw'] ?? null));
}

/* ========================= Stock item (task 9) ========================= */
if ($only === '' || $only === 'ingredient') {
    echo "== stock-ingredients.php ==\n";
    $siSrc = (string)file_get_contents($root . '/admin/stock-ingredients.php');
    $iCols = ['name', 'category', 'unit', 'min_quantity', 'yield_percent', 'notes', 'reorder_point', 'par_level', 'lead_time_days',
        'preferred_supplier_id', 'pack_name', 'pack_size', 'pieces_per_unit'];
    $iMeasure = rh_stock_measure_ready($pdo);
    if ($iMeasure) { array_push($iCols, 'measure_big_name', 'measure_small_name', 'measure_per_big', 'measure_stock_side'); }
    $iDrink = rh_stock_drink_ready($pdo);
    if ($iDrink) { $iCols[] = 'volume_ml'; }
    ok('page hashes the 13 form columns (+ measure, + bottle size when installed), never quantity / cost / updated_at / archived',
        strpos($siSrc, "'preferred_supplier_id', 'pack_name', 'pack_size', 'pieces_per_unit'];") !== false
        && strpos($siSrc, "array_push(\$cols, 'measure_big_name', 'measure_small_name', 'measure_per_big', 'measure_stock_side');") !== false
        && strpos($siSrc, "\$cols[] = 'volume_ml';") !== false
        && preg_match('/function rh_stock_item_stale_cols\(PDO \$pdo\): array\s*\{(.*?)return \$cols;/s', $siSrc, $fm) === 1
        && !preg_match('/current_quantity|cost_per_unit|updated_at|is_archived/', $fm[1]));
    $realI = (int)$pdo->query("SELECT id FROM stock_ingredients ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($realI > 0) {
        ok('SQL-computed stock item hash equals rh_stale_hash', (string)$pdo->query(se_hash_sql('stock_ingredients', $iCols, (string)$realI))->fetchColumn() === rh_stale_hash(se_row($pdo, 'stock_ingredients', $iCols, $realI), $iCols));
    }

    $ih = strtoupper(bin2hex(random_bytes(3)));
    $iName = "ZZSE $ih Item";
    $iSeed = ["INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, min_quantity, reorder_point, par_level)
        VALUES (" . se_q($iName) . ", 'ZZSE', 'bottle', 10, 300, 1, 2, 5)"];
    $iIdSql = "(SELECT id FROM stock_ingredients WHERE name = " . se_q($iName) . ")";
    $iVars = ['i' => "SELECT id FROM stock_ingredients WHERE name = " . se_q($iName)];
    $iHashSql = se_hash_sql('stock_ingredients', $iCols, $iIdSql);
    $vIng = "SELECT par_level, current_quantity, cost_per_unit, is_archived, measure_per_big FROM stock_ingredients WHERE name = " . se_q($iName);
    $iPost = ['action' => 'update', 'id' => '{i}', 'name' => $iName, 'category' => 'ZZSE', 'unit' => 'bottle', 'min_quantity' => '1', 'reorder_point' => '2',
        'par_level' => '7', 'yield_percent' => '100', 'lead_time_days' => '0', 'notes' => '', 'preferred_supplier_id' => '', 'pack_name' => '', 'pack_size' => '',
        'pieces_per_unit' => ''];
    $iRun = static function (array $post, array $extraSetup = [], array $verify = []) use ($iSeed, $iVars, $vIng): array {
        return se_run('admin/stock-ingredients.php', ['post' => $post, 'setup' => array_merge($iSeed, $extraSetup), 'vars' => $iVars,
            'verify' => array_merge([$vIng], $verify), 'session_keys' => ['stock_msg', 'stock_err', 'stock_ingredient_form_keep']]);
    };
    $iv = static fn(array $r, string $k) => (($r['verify'][0] ?? [])[0] ?? [])[$k] ?? null;
    $iErr = static fn(array $r): string => (string)($r['session']['stock_err'] ?? '');
    $iSaved = static fn(array $r): bool => se_clean($r) && $iErr($r) === '' && (string)($r['session']['stock_msg'] ?? '') !== '';
    $iStaleMsg = 'This stock item was changed by someone else ' . $staleMsgTail;

    // 1. Round trip: the hash the Edit button carries (More menu on an active row, row button on an archived one) is the SQL
    //    hash of the stored row, and a Save with it goes through.
    $iPageHash = static function (string $html) use ($iName): string {
        if (!preg_match_all("/openIngredientModal\\((\\{[^']*\\})\\)'/", $html, $mm)) { return ''; }
        $seen = [];
        foreach ($mm[1] as $js) {
            $d = json_decode($js, true);
            if (is_array($d) && ($d['name'] ?? '') === $iName) { $seen[] = (string)($d['_row_hash'] ?? ''); }
        }
        return count($seen) === 1 ? $seen[0] : '';
    };
    $html = se_get_html('admin/stock-ingredients.php', ['setup' => $iSeed]);
    $pageH = $iPageHash($html);
    $pageHArch = $iPageHash(se_get_html('admin/stock-ingredients.php', ['setup' => array_merge($iSeed, ["UPDATE stock_ingredients SET is_archived = 1 WHERE name = " . se_q($iName)])]));
    $r = se_run('admin/stock-ingredients.php', ['post' => ['action' => 'none'], 'setup' => $iSeed, 'verify' => ["SELECT ($iHashSql) AS h"]]);
    $h0 = (string)((($r['verify'][0] ?? [])[0] ?? [])['h'] ?? '');
    ok('the Edit button carries the stored row\'s hash (active row)', strlen($h0) === 32 && $pageH !== '' && hash_equals($h0, $pageH), "page=$pageH sql=$h0");
    ok('the Edit button carries the same hash on an archived row (archiving is not hashed)', $pageHArch !== '' && hash_equals($h0, $pageHArch), "page=$pageHArch sql=$h0");
    ok('the Edit form has the hidden _row_hash field', strpos($html, 'name="_row_hash" id="ing_row_hash"') !== false);
    $r = $iRun($iPost + ['_row_hash' => $pageH]);
    ok('fresh hash: saved (par level 7)', $iSaved($r) && (float)$iv($r, 'par_level') === 7.0, json_encode([$r['session'] ?? null, $r['verify'] ?? null, $r['raw'] ?? null]));

    // 2. Someone else changed the par level after the form was opened: refused, theirs kept, the reopen carries the POSTED hash.
    $r = $iRun($iPost + ['_row_hash' => $h0], ["UPDATE stock_ingredients SET par_level = 9 WHERE name = " . se_q($iName)]);
    ok('stale par level edit refused with the stock item message', se_clean($r) && $iErr($r) === $iStaleMsg, $iErr($r));
    ok('  nothing written (par level keeps the other person\'s 9)', (float)$iv($r, 'par_level') === 9.0, json_encode($r['verify'] ?? null));
    $keep = (array)($r['session']['stock_ingredient_form_keep'] ?? []);
    ok('  the reopened form keeps the POSTED hash', ($keep['post']['_row_hash'] ?? '') === $h0 && ($keep['post']['par_level'] ?? '') === '7', json_encode($keep));
    $fake = str_repeat('ab', 16);
    $r = se_run('admin/stock-ingredients.php', ['method' => 'GET', 'setup' => $iSeed, 'session' => ['stock_ingredient_form_keep' => ['action' => 'update', 'error' => $iStaleMsg,
        'post' => ['id' => '999999999', 'name' => $iName, 'par_level' => '7', '_row_hash' => $fake]]], 'grep' => ['"_row_hash":"' . $fake . '"', 'openIngredientModal(r.data)']]);
    ok('  the reopen data (r.data) carries the posted hash, not a fresh one', se_clean($r) && !empty($r['found']['"_row_hash":"' . $fake . '"']) && !empty($r['found']['openIngredientModal(r.data)']), json_encode($r['found'] ?? $r['raw'] ?? null));

    // 3. Stock-in (quantity + average cost), an adjustment, a POS sale, a count, archiving and updated_at never refuse a Save.
    $r = $iRun($iPost + ['_row_hash' => $h0], ["UPDATE stock_ingredients SET current_quantity = current_quantity + 5, cost_per_unit = 350, updated_at = NOW() - INTERVAL 1 DAY WHERE name = " . se_q($iName)]);
    ok('stock-in (quantity + cost) in between -> NOT refused, saved', $iSaved($r) && (float)$iv($r, 'par_level') === 7.0, json_encode([$r['session'] ?? null, $r['verify'] ?? null]));
    ok('  the stock-in quantity and cost are kept', abs((float)$iv($r, 'current_quantity') - 15) < 0.000001 && abs((float)$iv($r, 'cost_per_unit') - 350) < 0.00001, json_encode($r['verify'] ?? null));
    $r = $iRun($iPost + ['_row_hash' => $h0], [
        "UPDATE stock_ingredients SET current_quantity = current_quantity - 1.5, updated_at = NOW() WHERE name = " . se_q($iName), // adjustment
        "UPDATE stock_ingredients SET current_quantity = current_quantity - 2 WHERE name = " . se_q($iName),                       // POS sale / count
        "UPDATE stock_ingredients SET is_archived = 1 WHERE name = " . se_q($iName),
        "UPDATE stock_ingredients SET is_archived = 0 WHERE name = " . se_q($iName),
    ]);
    ok('adjustment + POS sale + archive/restore in between -> NOT refused, saved', $iSaved($r) && (float)$iv($r, 'par_level') === 7.0 && abs((float)$iv($r, 'current_quantity') - 6.5) < 0.000001, json_encode([$r['session'] ?? null, $r['verify'] ?? null]));

    // 4. A count measure set from the bulk tool, or a bottle size, in between -> refused.
    if ($iMeasure) {
        $r = $iRun($iPost + ['_row_hash' => $h0], ["UPDATE stock_ingredients SET measure_big_name = 'bottle', measure_small_name = 'shot', measure_per_big = 30, measure_stock_side = 'big' WHERE name = " . se_q($iName)]);
        ok('bulk measure set in between -> refused, nothing written', se_clean($r) && $iErr($r) === $iStaleMsg && (float)$iv($r, 'par_level') === 5.0 && (float)$iv($r, 'measure_per_big') === 30.0, json_encode([$iErr($r), $r['verify'] ?? null]));
    } else {
        ok('skipped: count measures not installed', true);
    }
    if ($iDrink) {
        $r = $iRun($iPost + ['_row_hash' => $h0], ["UPDATE stock_ingredients SET volume_ml = 750 WHERE name = " . se_q($iName)]);
        ok('bottle size set in between -> refused', se_clean($r) && $iErr($r) === $iStaleMsg && (float)$iv($r, 'par_level') === 5.0, json_encode([$iErr($r), $r['verify'] ?? null]));
    }

    // 5. No hash, or not a hash: refused, nothing written.
    $r = $iRun($iPost);
    ok('missing hash -> refused, nothing written', se_clean($r) && $iErr($r) === $oldPageMsg && (float)$iv($r, 'par_level') === 5.0, json_encode([$iErr($r), $r['verify'] ?? null]));
    $r = $iRun($iPost + ['_row_hash' => 'not-a-hash']);
    ok('a malformed hash -> refused, nothing written', se_clean($r) && $iErr($r) === $oldPageMsg && (float)$iv($r, 'par_level') === 5.0, json_encode([$iErr($r), $r['verify'] ?? null]));

    // 6. Adding an item needs no hash.
    $r = $iRun(['action' => 'add', 'name' => "ZZSE $ih New", 'category' => 'ZZSE', 'unit' => 'g', 'min_quantity' => '0', 'reorder_point' => '0', 'par_level' => '0', 'yield_percent' => '100'], [],
        ["SELECT COUNT(*) AS n FROM stock_ingredients WHERE name = " . se_q("ZZSE $ih New")]);
    ok('add (no hash) still saves', $iSaved($r) && (int)((($r['verify'][1] ?? [])[0] ?? [])['n'] ?? 0) === 1, json_encode([$r['session'] ?? null, $r['verify'] ?? null]));
    ok('no ZZSE stock items left behind', (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZSE $ih %'")->fetchColumn() === 0);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
