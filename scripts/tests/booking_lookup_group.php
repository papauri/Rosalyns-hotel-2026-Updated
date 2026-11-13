<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Guest Check Booking Status (booking-lookup.php) and the confirmation heading (booking-confirmation.php).
 * Run from the TEST hotel's root only (Liwonde):  php scripts/tests/booking_lookup_group.php
 * Every case runs in scripts/tests/page_harness.php (one never-committing transaction, rolled back); its test
 * bookings are made there on existing rooms with a made-up @example.invalid guest.
 * Cases: a same-type split party (REF, REF-2) lists both rooms with the whole party's total and guests; mixed
 * types; a cancelled room stays listed but is left out of the total; every room cancelled; a soft-deleted room is
 * neither listed nor found; cancel POSTs on an expired or soft-deleted booking and with a bad form token are
 * refused (every POST here is a refusal, none reaches the cancel path; backstop: a 3650-day notice window, and
 * the test hotel's own mail/WhatsApp muting stays on); the confirmation heading and title for pending / confirmed / tentative.
 * The per-IP limiter files for 127.0.0.1 are cleared before each run and at the end so reruns stay green.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

/** Clear the per-IP limiter state (includes/public-csrf.php pub_ip_rate_limit) the harness runs build up as 127.0.0.1. */
function blr_reset_limits(): void {
    foreach (['lookup_form', 'cancel_booking'] as $action) {
        $key = 'iprl_' . $action . '_' . substr(hash('sha256', '127.0.0.1|' . $action), 0, 24);
        if (function_exists('deleteCache')) { @deleteCache($key); }
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $key . '.json';
        if (is_file($file)) { @unlink($file); }
    }
}
register_shutdown_function('blr_reset_limits');

$dumpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blr_' . bin2hex(random_bytes(4)) . '.html';
register_shutdown_function(function () use ($dumpFile) { if (is_file($dumpFile)) { @unlink($dumpFile); } });

/** Run one page in the harness; returns the result plus 'html' (the rendered page). */
function blr_run(string $page, array $cfg): array {
    global $harness, $dumpFile;
    blr_reset_limits();
    if (is_file($dumpFile)) { @unlink($dumpFile); }
    $cfg += ['anon' => true, 'dump_html' => $dumpFile];
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -1200), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    $clean = !empty($res['rolled_back']) && empty($res['leak']) && empty($res['marker_lost']);
    ok("harness rolled back cleanly ($page)", $clean, json_encode(array_intersect_key($res, ['rolled_back' => 1, 'leak' => 1, 'marker_lost' => 1, 'raw' => 1, 'error' => 1])));
    $res['html'] = is_file($dumpFile) ? (string)file_get_contents($dumpFile) : '';
    return $res;
}

/** The text of a lookup result field (<label>X</label><div class="value">...</div>). */
function blr_field(string $html, string $label): string {
    if (!preg_match('#<label>' . preg_quote($label, '#') . '</label>\s*<div class="value"[^>]*>(.*?)</div>\s*</div>#s', $html, $m)) { return '<missing>'; }
    return trim(html_entity_decode((string)preg_replace('/\s+/', ' ', strip_tags($m[1])), ENT_QUOTES, 'UTF-8'));
}
function blr_digits(string $s): string { return (string)preg_replace('/[^0-9]/', '', $s); }

// Two existing active rooms of different names (Liwonde has several). Read only.
$rooms = $pdo->query("SELECT id, name FROM rooms WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$roomA = $rooms[0] ?? null; $roomB = null;
foreach ($rooms as $r) { if ($roomA && $r['name'] !== $roomA['name']) { $roomB = $r; break; } }
if (!$roomA || !$roomB) { fwrite(STDERR, "Needs two active rooms with different names.\n"); exit(1); }

$prefix = 'TLG' . strtoupper(bin2hex(random_bytes(3)));   // 9 chars; refs stay well under varchar(20)
$email = 'zz.lookup.' . strtolower(bin2hex(random_bytes(3))) . '@example.invalid';
$tok = bin2hex(random_bytes(32));
$A = 123457; $B = 234568; $C = 45679; $D = 9876;           // distinct totals so a wrong sum cannot match by chance

$q = function (string $s) use ($pdo): string { return $pdo->quote($s); };
/** One booking row; $link = a later room of the party (primary_booking_id = the lead inserted just before). */
$row = function (string $ref, array $room, int $guests, int $total, string $status = 'confirmed', bool $link = false, bool $deleted = false) use ($q, $email): string {
    return "INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, amount_paid, amount_due, status, is_tentative, primary_booking_id, deleted_at) VALUES ("
        . $q($ref) . ', ' . (int)$room['id'] . ", 'Zz Lookup Test', " . $q($email) . ", '+265000000000', DATE_ADD(CURDATE(), INTERVAL 60 DAY), DATE_ADD(CURDATE(), INTERVAL 62 DAY), 2, "
        . "$guests, $guests, 0, $total, $total, 0, $total, " . $q($status) . ', ' . ($status === 'tentative' ? 1 : 0) . ', ' . ($link ? '@blr_lead' : 'NULL') . ', ' . ($deleted ? 'NOW()' : 'NULL') . ')';
};
$lead = 'SET @blr_lead = LAST_INSERT_ID()';
// Backstop for the cancel cases: a notice window no test booking can be inside stops the cancel before it runs.
$base = ["INSERT INTO site_settings (setting_key, setting_value) VALUES ('cancellation_notice_days','3650') ON DUPLICATE KEY UPDATE setting_value = '3650'"];

$lookup = function (string $ref, array $setup) use ($email, $tok, $base): array {
    return blr_run('booking-lookup.php', ['setup' => array_merge($base, $setup), 'session' => ['_csrf_pub_lookup' => $tok],
        'post' => ['lookup' => '1', 'csrf_token' => $tok, 'booking_reference' => $ref, 'guest_email' => $email]]);
};
$cancel = function (string $ref, array $setup, string $postTok = '') use ($email, $tok, $base): array {
    return blr_run('booking-lookup.php', ['setup' => array_merge($base, $setup), 'session' => ['_csrf_pub_lookup' => $tok],
        'post' => ['cancel_booking' => '1', 'csrf_token' => $postTok !== '' ? $postTok : $tok, 'booking_reference' => $ref, 'guest_email' => $email, 'cancel_reason' => 'test'],
        'verify' => ["SELECT status, deleted_at IS NOT NULL AS del FROM bookings WHERE booking_reference = " . $GLOBALS['pdo']->quote($ref),
                     "SELECT COUNT(*) AS n FROM cancellation_log WHERE booking_reference = " . $GLOBALS['pdo']->quote($ref)]]);
};
$flash = fn(array $r): string => implode(' | ', $r['output_flash'] ?? []);

// 1. Same-type split party (REF, REF-2): both rooms, the whole party's guests and total, from either reference.
$R = $prefix . 'S';
$setup = [$row($R, $roomA, 2, $A), $lead, $row($R . '-2', $roomA, 2, $B, 'confirmed', true)];
foreach ([$R, $R . '-2'] as $looked) {
    $r = $lookup($looked, $setup);
    $room = blr_field($r['html'], 'Room');
    ok("same-type party via $looked: both rooms listed", strpos($room, $R . ')') !== false && strpos($room, $R . '-2)') !== false, $room);
    ok("same-type party via $looked: total is the whole party", blr_digits(blr_field($r['html'], 'Total Amount')) === (string)($A + $B), blr_field($r['html'], 'Total Amount'));
    ok("same-type party via $looked: guests are the whole party", blr_field($r['html'], 'Guests') === '4', blr_field($r['html'], 'Guests'));
}

// 2. Mixed room types.
$R = $prefix . 'M';
$r = $lookup($R, [$row($R, $roomA, 2, $A), $lead, $row($R . '-2', $roomB, 1, $B, 'confirmed', true)]);
$room = blr_field($r['html'], 'Room');
ok('mixed types: both room names listed', strpos($room, htmlspecialchars($roomA['name'])) !== false || strpos($room, $roomA['name']) !== false, $room);
ok('mixed types: second room listed', strpos($room, $roomB['name']) !== false && strpos($room, $R . '-2') !== false, $room);
ok('mixed types: total is the whole party', blr_digits(blr_field($r['html'], 'Total Amount')) === (string)($A + $B), blr_field($r['html'], 'Total Amount'));
ok('mixed types: guests are the whole party', blr_field($r['html'], 'Guests') === '3', blr_field($r['html'], 'Guests'));

// 3. One room cancelled: still listed (with its status), left out of the total and guests; an expired room still counts.
$R = $prefix . 'C';
$r = $lookup($R, [$row($R, $roomA, 2, $A), $lead, $row($R . '-2', $roomA, 2, $B, 'cancelled', true), $row($R . '-3', $roomA, 1, $C, 'confirmed', true), $row($R . '-4', $roomB, 1, $D, 'expired', true)]);
$room = blr_field($r['html'], 'Room');
ok('one room cancelled: still listed, marked Cancelled', (bool)preg_match('#' . preg_quote($R . '-2', '#') . '\)\s*Cancelled#', $room), $room);
ok('one room cancelled: left out of the total', blr_digits(blr_field($r['html'], 'Total Amount')) === (string)($A + $C + $D), blr_field($r['html'], 'Total Amount'));
ok('one room cancelled: left out of the guests', blr_field($r['html'], 'Guests') === '4', blr_field($r['html'], 'Guests'));

// 3b. Every room cancelled: the party's original total (as a single cancelled booking shows its own).
$R = $prefix . 'X';
$r = $lookup($R, [$row($R, $roomA, 2, $A, 'cancelled'), $lead, $row($R . '-2', $roomA, 2, $B, 'cancelled', true)]);
ok('every room cancelled: total is the party total', blr_digits(blr_field($r['html'], 'Total Amount')) === (string)($A + $B), blr_field($r['html'], 'Total Amount'));
ok('every room cancelled: no cancel button', strpos($r['html'], 'id="cancel-form"') === false);

// 4. Soft-deleted room: not listed, not counted; looking it up finds nothing.
$R = $prefix . 'D';
$setup = [$row($R, $roomA, 2, $A), $lead, $row($R . '-2', $roomA, 2, $B, 'confirmed', true, true)];
$r = $lookup($R, $setup);
ok('soft-deleted room: not listed', strpos($r['html'], $R . '-2') === false);
ok('soft-deleted room: total is the live room only', blr_digits(blr_field($r['html'], 'Total Amount')) === (string)$A, blr_field($r['html'], 'Total Amount'));
ok('soft-deleted room: guests are the live room only', blr_field($r['html'], 'Guests') === '2', blr_field($r['html'], 'Guests'));
$r = $lookup($R . '-2', $setup);
ok('soft-deleted room: lookup finds nothing', strpos($flash($r), 'No booking found') !== false && strpos($r['html'], 'class="booking-result"') === false, $flash($r));

// 5. Single booking: unchanged (its own total and guests).
$R = $prefix . 'O';
$r = $lookup($R, [$row($R, $roomA, 3, $A)]);
ok('single booking: own total', blr_digits(blr_field($r['html'], 'Total Amount')) === (string)$A, blr_field($r['html'], 'Total Amount'));
ok('single booking: own guests and room', blr_field($r['html'], 'Guests') === '3' && blr_field($r['html'], 'Room') === $roomA['name'], blr_field($r['html'], 'Room'));

// 6. Cancel POSTs: every one must be refused, nothing changes.
$R = $prefix . 'E';
$r = $cancel($R, [$row($R, $roomA, 2, $A, 'expired')]);
ok('expired: cancel refused', strpos($flash($r), 'cannot be cancelled (current status: Expired)') !== false, $flash($r));
ok('expired: status unchanged, no cancellation logged', ($r['verify'][0][0]['status'] ?? '') === 'expired' && (int)($r['verify'][1][0]['n'] ?? -1) === 0, json_encode($r['verify'] ?? []));

$R = $prefix . 'R';
$r = $cancel($R, [$row($R, $roomA, 2, $A, 'confirmed', false, true)]);
ok('soft-deleted: cancel refused (not found)', strpos($flash($r), 'Booking not found.') !== false, $flash($r));
ok('soft-deleted: status unchanged, no cancellation logged', ($r['verify'][0][0]['status'] ?? '') === 'confirmed' && (int)($r['verify'][1][0]['n'] ?? -1) === 0, json_encode($r['verify'] ?? []));

$R = $prefix . 'T';
$r = $cancel($R, [$row($R, $roomA, 2, $A)], 'not-the-token');
ok('bad form token: cancel refused', strpos($flash($r), 'Invalid request') !== false, $flash($r));
ok('bad form token: status unchanged, no cancellation logged', ($r['verify'][0][0]['status'] ?? '') === 'confirmed' && (int)($r['verify'][1][0]['n'] ?? -1) === 0, json_encode($r['verify'] ?? []));

// 7. Confirmation heading + title (shown only on the device that made the booking: seed that session entry).
foreach (['pending' => 'Booking Received', 'confirmed' => 'Booking Confirmed', 'tentative' => 'Tentative Booking Received'] as $status => $want) {
    $R = $prefix . 'H' . substr($status, 0, 1);
    $r = blr_run('booking-confirmation.php', ['method' => 'GET', 'get' => ['ref' => $R], 'setup' => [$row($R, $roomA, 2, $A, $status)],
        'session' => ['_confirm_refs' => [['k' => 'booking', 'r' => $R, 't' => time()]]]]);
    $h1 = preg_match('#<h1 class="conf-heading">(.*?)</h1>#s', $r['html'], $m) ? trim($m[1]) : '<missing>';
    $title = preg_match('#<title>(.*?)</title>#s', $r['html'], $m) ? trim(html_entity_decode($m[1])) : '<missing>';
    ok("confirmation $status: heading '$want'", $h1 === $want, $h1);
    ok("confirmation $status: title starts '$want |'", strpos($title, $want . ' |') === 0, $title);
}

// Nothing leaked: a fresh connection sees none of the test rows.
$probe = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$left = $probe->prepare("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE ? OR guest_email = ?");
$left->execute([$prefix . '%', $email]);
ok('no test bookings left behind', (int)$left->fetchColumn() === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
