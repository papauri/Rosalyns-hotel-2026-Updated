<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Check-in audit 2026-11-18: an inherited pay-later arrangement must let the SAVE points check in, not only the list.
 * rh_checkin_rule_select() now carries id / primary_booking_id / billing_account_id, which
 * rh_checkin_arrangement_inherited() reads (a group member whose primary has "Check in before payment", a booking on
 * its own company account). Also: Cancel check-in on admin/process-checkin.php writes its History row.
 * Usage (repo root, TEST HOTEL ONLY): php scripts/tests/checkin_inherit_test.php
 *   A  the exact SELECTs of admin/process-checkin.php and admin/booking-details.php + validateCheckIn (one transaction,
 *      rolled back).
 *   B  admin/process-checkin.php through scripts/tests/page_harness.php (rolled back): check in an unpaid member of a
 *      pay-later group; cancel a check-in -> History "check-in cancelled" (checked-in -> confirmed, who).
 * Rows: references ZC?<tag>, emails @example.invalid, a made-up company "ZZ CI TEST CO <tag>".
 */
$root = dirname(__DIR__, 2);
chdir($root);
if (stripos(str_replace('\\', '/', $root), 'Rosalyns-hotel-2026') !== false) {
    fwrite(STDERR, "Refusing to run in the live hotel's repo. Copy the changed files to the test hotel and run it there.\n");
    exit(2);
}
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false || basename($root) !== 'Liwonde_Sun_Hotel_2026') {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/includes/billing-accounts.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

$arrCols = rh_checkin_arrangement_columns_present($pdo);
$billing = rh_billing_ready($pdo);
ok('migrations 083 (arrangement) + 084 (company accounts) have run here', $arrCols && $billing);
if (!$arrCols || !$billing) {
    echo "\n$pass passed, $fail failed\n";
    exit(1);
}
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$tags = [];

/** Room type + rooms + bookings for tag $t:
 *  ZCP primary (unpaid, "Check in before payment" ON) + ZCM its member (unpaid, own switch OFF)
 *  ZCC own company account (unpaid, switch OFF)
 *  ZCQ primary with nothing + ZCR its member (unpaid): must stay blocked. */
function ci_fixture(string $t, string $memberStatus = 'confirmed', string $keys = 'PMCQR'): array {
    $type = "(SELECT id FROM rooms WHERE slug = 'zz-ci-" . strtolower($t) . "')";
    $room = static fn(string $n): string => "(SELECT id FROM individual_rooms WHERE room_number = 'ZC{$n}{$t}')";
    $sql = [
        "INSERT INTO rooms (name, description, short_description, price_per_night, max_guests, rooms_available, total_rooms, is_active, slug) VALUES ('ZZ CI TEST {$t}', '', '', 100, 2, 5, 5, 1, 'zz-ci-" . strtolower($t) . "')",
        "INSERT INTO billing_accounts (name) VALUES ('ZZ CI TEST CO {$t}')",
    ];
    $rows = ['P' => ['confirmed', 1, 'zz invoiced monthly', 'NULL'], 'M' => [$memberStatus, 0, null, 'NULL'],
        'C' => ['confirmed', 0, null, "(SELECT id FROM billing_accounts WHERE name = 'ZZ CI TEST CO {$t}')"],
        'Q' => ['confirmed', 0, null, 'NULL'], 'R' => ['confirmed', 0, null, 'NULL']];
    foreach ($rows as $k => [$status, $sw, $note, $acc]) {
        if (strpos($keys, $k) === false) { continue; }
        $sql[] = "INSERT INTO individual_rooms (room_type_id, room_number, room_name, floor, status, housekeeping_status, is_active) VALUES ($type, 'ZC{$k}{$t}', 'zz test', 1, '"
            . ($status === 'checked-in' ? 'occupied' : 'available') . "', 'completed', 1)";
        $sql[] = "INSERT INTO bookings (booking_reference, room_id, individual_room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, amount_due, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, final_invoice_generated,
            checkin_without_payment, checkin_arrangement_note, billing_account_id, created_at)
         VALUES ('ZC{$k}{$t}', $type, " . $room($k) . ", 'ZZ CI INHERIT TEST', 'zc" . strtolower($k . $t) . "@example.invalid', '+265000000009', 'Malawi', 2, 2, 0, 50,
            '" . date('Y-m-d') . "', '" . date('Y-m-d', strtotime('+1 day')) . "', 1, 100, 0, 0, 0, 0, 0, 100, 100, 'checkin inherit test', '{$status}', 'unpaid', 0, 'double', '"
            . bin2hex(random_bytes(16)) . "', 1, {$sw}, " . ($note === null ? 'NULL' : "'{$note}'") . ", {$acc}, NOW())";
        $sql[] = "INSERT INTO booking_rooms (booking_id, individual_room_id, room_combination_id, is_primary, assigned_at, status_snapshot) VALUES ((SELECT id FROM bookings WHERE booking_reference = 'ZC{$k}{$t}'), " . $room($k) . ", NULL, 1, NOW(), 'assigned')";
    }
    // Members point at their primaries (derived table: MySQL refuses a direct self-subquery in an UPDATE).
    foreach (['M' => 'P', 'R' => 'Q'] as $m => $p) {
        if (strpos($keys, $m) === false) { continue; }
        $sql[] = "UPDATE bookings SET primary_booking_id = (SELECT id FROM (SELECT id FROM bookings WHERE booking_reference = 'ZC{$p}{$t}') x) WHERE booking_reference = 'ZC{$m}{$t}'";
    }
    // One line each: the harness gets the fixture on the command line (8 KB limit on Windows).
    return array_map(static fn(string $q): string => (string)preg_replace('/\s+/', ' ', $q), $sql);
}
$idOf = static function (string $ref) use ($pdo): int {
    $st = $pdo->prepare('SELECT id FROM bookings WHERE booking_reference = ?');
    $st->execute([$ref]);
    return (int)$st->fetchColumn();
};

echo "== A: the save points' own SELECT + validateCheckIn (rolled back) ==\n";
$t = strtoupper(bin2hex(random_bytes(3)));
$tags[] = $t;
$pdo->beginTransaction();
try {
    foreach (ci_fixture($t) as $s) { $pdo->exec($s); }
    // admin/process-checkin.php line ~67 and admin/booking-details.php line ~763, word for word.
    $pcSel = $pdo->prepare("SELECT status, payment_status, check_in_date" . rh_checkin_rule_select() . " FROM bookings WHERE id = ?");
    $bdSel = $pdo->prepare("SELECT b.status, b.payment_status, b.individual_room_id, b.room_id, b.booking_reference, b.check_in_date" . rh_checkin_rule_select('b') . ", ir.status as room_status FROM bookings b LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id WHERE b.id = ?");
    $rule = static function (PDOStatement $st, int $id): array { $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC) ?: []; return [$row, validateCheckIn($row)]; };

    [$row] = $rule($pcSel, $idOf("ZCM{$t}"));
    ok('A0 the rule SELECT carries id, primary_booking_id and billing_account_id', array_key_exists('id', $row) && array_key_exists('primary_booking_id', $row) && array_key_exists('billing_account_id', $row), json_encode(array_keys($row)));
    foreach (['process-checkin' => $pcSel, 'booking-details' => $bdSel] as $where => $st) {
        [$row, $v] = $rule($st, $idOf("ZCM{$t}"));
        ok("A1 {$where}: unpaid member of a pay-later primary may check in (via the arrangement)", $v['allowed'] && $v['via_arrangement'], json_encode([$v, $row]));
        [$row, $v] = $rule($st, $idOf("ZCC{$t}"));
        ok("A2 {$where}: unpaid booking on its own company account may check in (via the arrangement)", $v['allowed'] && $v['via_arrangement'], json_encode([$v, $row]));
        [$row, $v] = $rule($st, $idOf("ZCR{$t}"));
        ok("A3 {$where}: unpaid member of a primary with no arrangement stays blocked (partial payment message)", !$v['allowed'] && strpos($v['reason'], 'partial payment') !== false, json_encode($v));
        [$row, $v] = $rule($st, $idOf("ZCP{$t}"));
        ok("A4 {$where}: the primary itself, own switch on, may check in", $v['allowed'] && $v['via_arrangement'] && $v['arrangement_note'] === 'zz invoiced monthly', json_encode($v));
    }
    ok('A5 the list and the save points agree (rh_checkin_payment_gate on the full row)', rh_checkin_payment_gate($pdo->query("SELECT * FROM bookings WHERE booking_reference = 'ZCM{$t}'")->fetch(PDO::FETCH_ASSOC)) === 'arrangement');
} catch (Throwable $e) {
    ok('A no exception', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

echo "== B: admin/process-checkin.php through the rolled-back harness ==\n";
$harness = $root . '/scripts/tests/page_harness.php';
$run = static function (array $cfg) use ($harness): array {
    $cfg += ['ajax' => true, 'fake_smtp' => 9];
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' admin/process-checkin.php ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -800)]; } }
    return $res;
};
$clean = static function (array $r, string $label): void {
    ok("$label: harness rolled back, nothing left behind", empty($r['leak']) && empty($r['marker_lost']) && !empty($r['rolled_back']), json_encode([$r['error'] ?? null, $r['raw'] ?? null]));
};
$t = strtoupper(bin2hex(random_bytes(3)));
$tags[] = $t;
$mid = "SELECT id FROM bookings WHERE booking_reference = 'ZCM{$t}'";
$r = $run(['post' => ['action' => 'checkin', 'booking_id' => '{b}', 'email_guest' => '0'], 'setup' => ci_fixture($t, 'confirmed', 'PM'), 'vars' => ['b' => $mid],
    'verify' => ["SELECT status, payment_status FROM bookings WHERE booking_reference = 'ZCM{$t}'"]]);
$clean($r, 'B1');
ok('B1 unpaid member of a pay-later group: Check in saves (checked in, still unpaid)', !empty($r['json']['success']) && ($r['verify'][0][0]['status'] ?? '') === 'checked-in' && ($r['verify'][0][0]['payment_status'] ?? '') === 'unpaid',
    json_encode([$r['json'] ?? null, $r['verify'] ?? null, $r['raw'] ?? null]));
$t = strtoupper(bin2hex(random_bytes(3)));
$tags[] = $t;
$r = $run(['post' => ['action' => 'checkin', 'booking_id' => '{b}', 'email_guest' => '0'], 'setup' => ci_fixture($t, 'confirmed', 'QR'), 'vars' => ['b' => "SELECT id FROM bookings WHERE booking_reference = 'ZCR{$t}'"],
    'verify' => ["SELECT status FROM bookings WHERE booking_reference = 'ZCR{$t}'"]]);
$clean($r, 'B2');
ok('B2 unpaid member of a group with no arrangement: refused, still confirmed', empty($r['json']['success']) && stripos((string)($r['json']['message'] ?? ''), 'partial payment') !== false && ($r['verify'][0][0]['status'] ?? '') === 'confirmed',
    json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
$t = strtoupper(bin2hex(random_bytes(3)));
$tags[] = $t;
$r = $run(['post' => ['action' => 'cancel_checkin', 'booking_id' => '{b}'], 'setup' => ci_fixture($t, 'checked-in', 'PM'), 'vars' => ['b' => "SELECT id FROM bookings WHERE booking_reference = 'ZCM{$t}'"],
    'verify' => ["SELECT status FROM bookings WHERE booking_reference = 'ZCM{$t}'",
        "SELECT action, old_values, new_values, performed_by, performed_by_name FROM booking_audit_log WHERE booking_reference = 'ZCM{$t}' ORDER BY id"]]);
$clean($r, 'B3');
$aud = array_values(array_filter(is_array($r['verify'][1] ?? null) ? $r['verify'][1] : [], static fn($a) => ($a['action'] ?? '') === 'check-in cancelled'));
$ov = json_decode((string)($aud[0]['old_values'] ?? ''), true) ?: [];
$nv = json_decode((string)($aud[0]['new_values'] ?? ''), true) ?: [];
ok('B3 Cancel check-in: back to confirmed', !empty($r['json']['success']) && ($r['verify'][0][0]['status'] ?? '') === 'confirmed', json_encode([$r['json'] ?? null, $r['raw'] ?? null]));
ok('B3 Cancel check-in History: one "check-in cancelled" row, checked-in -> confirmed, staff named', count($aud) === 1 && ($ov['status'] ?? '') === 'checked-in' && ($nv['status'] ?? '') === 'confirmed'
    && (int)($aud[0]['performed_by'] ?? 0) > 0 && trim((string)($aud[0]['performed_by_name'] ?? '')) !== '', json_encode($r['verify'][1] ?? null));

// ---- leak check (DB rolled back in A and B) -----------------------------------------------------------------------
$leak = 0;
foreach ($tags as $t) {
    $st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE ?) + (SELECT COUNT(*) FROM billing_accounts WHERE name = ?) + (SELECT COUNT(*) FROM rooms WHERE slug = ?)");
    $st->execute(['ZC_' . $t, 'ZZ CI TEST CO ' . $t, 'zz-ci-' . strtolower($t)]);
    $leak += (int)$st->fetchColumn();
}
ok('leak check: no test row left behind', $leak === 0, (string)$leak);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
