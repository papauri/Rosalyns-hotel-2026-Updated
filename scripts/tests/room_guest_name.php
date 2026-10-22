<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Optional "guest name for this room" on group bookings (owner request 2026-10-23). Usage (project root): php scripts/tests/room_guest_name.php
 *   - The lead booker stays the booking's guest; every room of a group may carry its own optional name (bookings.room_guest_name).
 *   - create-booking.php: a 3-room group with 2 names saves one name per room row (the blank one stays empty); names are cleaned
 *     (trimmed, control characters out, 150 characters) and the totals are exactly what the same group costs with no names.
 *   - edit-booking.php: the name can be changed and cleared, and the change is written to the booking audit.
 *   - Shown, escaped, on Booking Details (header, stay details, linked rooms), the Bookings list (and found by its search), the
 *     calendar (unassigned list and hover tooltip), and printed on the invoice and quotation room lines - labels only, no money.
 * Page runs use scripts/tests/page_harness.php (outer transaction that never commits, rolled back); the document run is
 * scripts/tests/room_guest_name_child.php under the same harness.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function rg_run(string $page, array $cfg): array {
    global $harness;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1';
    $out = shell_exec($cmd);
    if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -500), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -500)]; } }
    return $res;
}
function rg_clean(array $r): bool { return !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']); }
function rg_flash(array $r): string { return implode(' | ', $r['output_flash'] ?? []); }
function rg_q(string $v): string { return "'" . str_replace("'", "''", $v) . "'"; }

// ── 1. The cleaning rule itself ──────────────────────────────────────────────────
ok('trimmed, control characters out, spaces collapsed', rh_clean_room_guest_name("  Mr &\x07 Mrs \t Banda\n ") === 'Mr & Mrs Banda', rh_clean_room_guest_name("  Mr &\x07 Mrs \t Banda\n "));
ok('empty, whitespace-only and non-text input give an empty name', rh_clean_room_guest_name('') === '' && rh_clean_room_guest_name("  \n ") === '' && rh_clean_room_guest_name(null) === '' && rh_clean_room_guest_name(['x']) === '');
ok('at most 150 characters', mb_strlen(rh_clean_room_guest_name(str_repeat('a', 400))) === 150 && mb_strlen(rh_clean_room_guest_name(str_repeat('é', 400))) === 150);
ok('the document label joins room and name, and is the plain room name with no name', rh_room_label_with_guest('Deluxe Room', 'Mr & Mrs Banda') === 'Deluxe Room – Mr & Mrs Banda' && rh_room_label_with_guest('Deluxe Room', '') === 'Deluxe Room' && rh_room_label_with_guest('Deluxe Room', null) === 'Deluxe Room');
ok('the column exists (migration 066)', $pdo->query("SHOW COLUMNS FROM bookings LIKE 'room_guest_name'")->rowCount() === 1);
ok('the API returns the field', strpos((string)file_get_contents($root . '/api/booking-details.php'), 'room_guest_name') !== false);

// ── 2. create-booking.php: 3 rooms, 2 names ──────────────────────────────────────
$ci = date('Y-m-d', strtotime('+640 day'));
$co = date('Y-m-d', strtotime('+642 day'));
$type = null; $free = 0;
foreach ($pdo->query("SELECT r.* FROM rooms r WHERE r.is_active = 1 ORDER BY r.id")->fetchAll(PDO::FETCH_ASSOC) as $cand) {
    if (roomTypeHasActiveCombinations((int)$cand['id'])) { continue; }
    $av = checkRoomAvailability((int)$cand['id'], $ci, $co);
    $rem = (int)($av['remaining_rooms'] ?? 0);
    if (!empty($av['available']) && $rem >= 3 && $rem > $free) { $type = $cand; $free = $rem; }
}
if (!$type) {
    ok('skipped: no plain room type with 3 free rooms on this database (nothing to check)', true);
    echo "\n$pass passed, $fail failed\n";
    exit(0);
}
$rid = (int)$type['id'];
$evil = '<script>alert(1)</script> "Q" O\'Neil';
$mk = fn(string $guest, array $names) => [
    'create_booking' => '1', 'guest_name' => $guest, 'guest_email' => 'rgntest@example.invalid', 'guest_phone' => '+265000000004',
    'number_of_guests' => '6', 'child_guests' => '0', 'check_in_date' => $ci, 'check_out_date' => $co, 'booking_status' => 'confirmed', 'auto_assign_room' => '1',
    'room_line_room_id' => [(string)$rid, (string)$rid, (string)$rid], 'room_line_qty' => ['1', '1', '1'], 'room_line_occupancy' => ['double', 'double', 'double'],
    'room_line_override' => ['', '', ''], 'room_line_guest_name' => $names,
];
$verify = fn(string $guest) => ["SELECT id, guest_name, room_guest_name, total_with_vat FROM bookings WHERE guest_name = " . rg_q($guest) . " ORDER BY id"];
$a = rg_run('admin/create-booking.php', ['post' => $mk('RGNTEST named', ["  Mr &\x07 Mrs   Banda ", $evil, '']), 'verify' => $verify('RGNTEST named')]);
$rowsA = is_array($a['verify'][0] ?? null) ? $a['verify'][0] : [];
ok('create: the run rolled back, nothing left behind', rg_clean($a), json_encode([$a['error'] ?? null, $a['raw'] ?? null]));
ok('create: 3 rooms saved', count($rowsA) === 3, rg_flash($a) . ' ' . count($rowsA));
ok('create: each room row carries its own name (cleaned), the blank one stays empty', ($rowsA[0]['room_guest_name'] ?? 'x') === 'Mr & Mrs Banda' && ($rowsA[1]['room_guest_name'] ?? 'x') === $evil && (array_key_exists('room_guest_name', $rowsA[2] ?? []) && $rowsA[2]['room_guest_name'] === null), json_encode($rowsA));
ok('create: the lead booker is still every row\'s guest', count(array_filter($rowsA, fn($r) => $r['guest_name'] === 'RGNTEST named')) === 3);
$b = rg_run('admin/create-booking.php', ['post' => $mk('RGNTEST plain', ['', '', '']), 'verify' => $verify('RGNTEST plain')]);
$rowsB = is_array($b['verify'][0] ?? null) ? $b['verify'][0] : [];
ok('create: the same group with no names saves with all names empty', count($rowsB) === 3 && count(array_filter($rowsB, fn($r) => $r['room_guest_name'] !== null)) === 0, json_encode($rowsB));
ok('create: totals are exactly what the unnamed group costs', count($rowsA) === 3 && count($rowsB) === 3 && array_map(fn($r) => number_format((float)$r['total_with_vat'], 2, '.', ''), $rowsA) === array_map(fn($r) => number_format((float)$r['total_with_vat'], 2, '.', ''), $rowsB), json_encode([array_column($rowsA, 'total_with_vat'), array_column($rowsB, 'total_with_vat')]));
ok('  run rolled back', rg_clean($b));
$tooLong = rg_run('admin/create-booking.php', ['post' => $mk('RGNTEST long', [str_repeat('Z', 300), '', '']), 'verify' => ["SELECT CHAR_LENGTH(room_guest_name) AS n FROM bookings WHERE guest_name = 'RGNTEST long' ORDER BY id LIMIT 1"]]);
ok('create: a 300-character name is cut to 150', (int)($tooLong['verify'][0][0]['n'] ?? 0) === 150, json_encode($tooLong['verify'] ?? ''));
ok('  run rolled back', rg_clean($tooLong));

// ── 3. seeded group (primary + second room), for edit / details / list / calendar ─────────────────────────────
$pid = 2098100001; $sid = 2098100002;
$irSql = "(SELECT id FROM individual_rooms WHERE room_type_id = $rid AND is_active = 1 ORDER BY id LIMIT 1)";
$seedRow = function (int $id, string $ref, ?int $primary, ?string $roomGuest, bool $assigned) use ($rid, $ci, $co): string {
    return "INSERT INTO bookings (id, booking_reference, room_id, individual_room_id, primary_booking_id, guest_name, room_guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights,
            number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, status, payment_status, occupancy_type)
            VALUES ($id, " . rg_q($ref) . ", $rid, " . ($assigned ? "(SELECT id FROM individual_rooms WHERE room_type_id = $rid AND is_active = 1 ORDER BY id LIMIT 1)" : 'NULL') . ", " . ($primary === null ? 'NULL' : $primary) . ",
            'RGNTEST lead', " . ($roomGuest === null ? 'NULL' : rg_q($roomGuest)) . ", 'rgntest@example.invalid', '+265000000004', " . rg_q($ci) . ", " . rg_q($co) . ", 2, 2, 2, 0, 200, 200, 'confirmed', 'unpaid', 'double')";
};
$seed = [$seedRow($pid, 'RGNPRIM' . $pid, null, 'Mr & Mrs Banda', false), $seedRow($sid, 'RGNSEC' . $sid, $pid, $evil, false)];

// edit-booking.php
$post = ['guest_name' => 'RGNTEST lead', 'guest_email' => 'rgntest@example.invalid', 'guest_phone' => '+265000000004', 'check_in_date' => $ci, 'check_out_date' => $co,
    'number_of_guests' => '2', 'child_guests' => '0', 'occupancy_type' => 'double', 'individual_room_id' => '', 'notify_guest' => '0', 'room_guest_name' => '  Mr & Mrs Chirwa '];
$v = ["SELECT room_guest_name FROM bookings WHERE id = $pid", "SELECT old_values, new_values FROM booking_audit_log WHERE booking_id = $pid ORDER BY id DESC LIMIT 1"];
$e = rg_run('admin/edit-booking.php', ['post' => $post, 'get' => ['id' => (string)$pid], 'setup' => $seed, 'verify' => $v]);
ok('edit: the room guest name is changed', ($e['verify'][0][0]['room_guest_name'] ?? '') === 'Mr & Mrs Chirwa', rg_flash($e) . ' ' . json_encode($e['verify'][0] ?? ''));
ok('edit: the change is in the booking audit', strpos(json_encode($e['verify'][1] ?? []), 'room_guest_name') !== false && strpos(json_encode($e['verify'][1] ?? []), 'Chirwa') !== false, json_encode($e['verify'][1] ?? ''));
ok('  run rolled back', rg_clean($e));
$post['room_guest_name'] = '';
$e = rg_run('admin/edit-booking.php', ['post' => $post, 'get' => ['id' => (string)$pid], 'setup' => $seed, 'verify' => $v]);
ok('edit: clearing the field removes the name (NULL)', array_key_exists('room_guest_name', $e['verify'][0][0] ?? []) && $e['verify'][0][0]['room_guest_name'] === null, rg_flash($e) . ' ' . json_encode($e['verify'][0] ?? ''));
ok('  run rolled back', rg_clean($e));
$g = rg_run('admin/edit-booking.php', ['method' => 'GET', 'get' => ['id' => (string)$sid], 'setup' => $seed, 'grep' => ['name="room_guest_name"', '&lt;script&gt;alert(1)&lt;/script&gt;', '<script>alert(1)</script>']]);
ok('edit form: the field is there with the saved name, escaped', !empty($g['found']['name="room_guest_name"']) && !empty($g['found']['&lt;script&gt;alert(1)&lt;/script&gt;']) && empty($g['found']['<script>alert(1)</script>']), json_encode($g['found'] ?? $g['raw']));
ok('  run rolled back', rg_clean($g));

// booking-details.php (primary shows the second room's name in the linked rooms; the second room shows its own, escaped)
$g = rg_run('admin/booking-details.php', ['method' => 'GET', 'get' => ['id' => (string)$pid], 'setup' => $seed, 'grep' => ['Room guest: <strong>Mr &amp; Mrs Banda</strong>', 'Guest name for this room', '&lt;script&gt;alert(1)&lt;/script&gt;', '<script>alert(1)</script>']]);
ok('details: the room guest is in the header and the stay details', !empty($g['found']['Room guest: <strong>Mr &amp; Mrs Banda</strong>']) && !empty($g['found']['Guest name for this room']), json_encode($g['found'] ?? $g['raw']));
ok('details: the linked-rooms notice lists the other room\'s name, escaped (a <script> name is not run)', !empty($g['found']['&lt;script&gt;alert(1)&lt;/script&gt;']) && empty($g['found']['<script>alert(1)</script>']), json_encode($g['found'] ?? $g['raw']));
ok('  run rolled back', rg_clean($g));

// bookings.php: the list line and the search
$g = rg_run('admin/bookings.php', ['method' => 'GET', 'get' => ['search' => 'Mrs Banda'], 'setup' => $seed, 'grep' => ['Room guest: Mr &amp; Mrs Banda', 'RGNPRIM' . $pid, 'RGNSEC' . $sid]]);
ok('bookings list: searching the room guest name finds that room and shows "Room guest: ..."', !empty($g['found']['Room guest: Mr &amp; Mrs Banda']) && !empty($g['found']['RGNPRIM' . $pid]) && empty($g['found']['RGNSEC' . $sid]), json_encode($g['found'] ?? $g['raw']));
ok('  run rolled back', rg_clean($g));
$g = rg_run('admin/bookings.php', ['method' => 'GET', 'get' => ['search' => 'RGNSEC' . $sid], 'setup' => $seed, 'grep' => ['&lt;script&gt;alert(1)&lt;/script&gt;', '<script>alert(1)</script>']]);
ok('bookings list: a <script> room guest name is shown escaped', !empty($g['found']['&lt;script&gt;alert(1)&lt;/script&gt;']) && empty($g['found']['<script>alert(1)</script>']), json_encode($g['found'] ?? $g['raw']));
ok('  run rolled back', rg_clean($g));

// calendar.php: unassigned list and the hover tooltip of an assigned room
$ym = ['year' => date('Y', strtotime($ci)), 'month' => date('n', strtotime($ci))];
$seedCal = [$seedRow($pid, 'RGNPRIM' . $pid, null, 'Mr & Mrs Banda', false), $seedRow($sid, 'RGNSEC' . $sid, $pid, 'Mr & Mrs Phiri', true)];
$g = rg_run('admin/calendar.php', ['method' => 'GET', 'get' => $ym, 'setup' => $seedCal, 'grep' => ['(Mr &amp; Mrs Banda)', 'data-room-guest="Mr &amp; Mrs Phiri"']]);
ok('calendar: the unassigned list and the booking hover carry the room guest', !empty($g['found']['(Mr &amp; Mrs Banda)']) && !empty($g['found']['data-room-guest="Mr &amp; Mrs Phiri"']), json_encode($g['found'] ?? $g['raw']));
ok('  run rolled back', rg_clean($g));

// ── 4. documents: invoice (group) and quotation room lines ───────────────────────────────────────────────────
$d = rg_run('scripts/tests/room_guest_name_child.php', ['post' => ['rid' => (string)$rid]]);
$j = $d['json'] ?? [];
ok('documents: run rolled back', rg_clean($d), json_encode([$d['raw'] ?? null]));
ok('invoice: the group invoice room lines carry "Room - name"', !empty($j['invoice_has_names']), json_encode($j));
ok('invoice: a <script> name is escaped in the document HTML', !empty($j['invoice_escaped']), json_encode($j));
ok('invoice: both group invoices were built', !empty($j['invoice_built']), json_encode($j));
ok('invoice: the money on it is exactly the same with or without names', !empty($j['invoice_money_same']), json_encode($j));
ok('quotation: the room line carries the name and the money is unchanged', !empty($j['quote_has_name']) && !empty($j['quote_money_same']), json_encode($j));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
