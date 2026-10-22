<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Occupancy tier floor (owner decision 2026-10-23). Usage (project root): php scripts/tests/occupancy_tier.php
 *   A room cannot be priced at a CHEAPER tier than the guests seated in it (Single with 2 guests, Double with 3 or more)
 *   unless the user may override room prices (admin / manager: the same people as the Price override box). A dearer tier is fine.
 *   - create-booking.php: refused for a receptionist with a plain message and nothing saved; saved for an admin with a
 *     "Deliberate under-tier" note in the booking audit; Triple for 2 guests and the right tier are allowed.
 *   - edit-booking.php: same rule when occupancy or the guest count changes.
 *   - The public booking page and the API derive the tier from the guest count (nobody picks it), so they cannot under-tier.
 * Every page run is in scripts/tests/page_harness.php (outer transaction that never commits, rolled back). A hotel with no
 * receptionist account skips the refusal runs; it still checks the rule itself, the admin path and the public derivation.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
require_once $root . '/includes/pricing.php';
require_once $root . '/includes/guest-allocation.php';
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function ot_run(string $page, array $cfg): array {
    global $harness;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1';
    $out = shell_exec($cmd);
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -500), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -500)]; } }
    return $res;
}
function ot_clean(array $r): bool { return !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']); }
function ot_flash(array $r): string { return implode(' | ', $r['output_flash'] ?? []); }

// ── 1. The rule itself ──────────────────────────────────────────────────────────
$all = ['single_enabled' => 1, 'double_enabled' => 1, 'triple_enabled' => 1];
ok('Single with 2 guests is refused', ga_under_tier_message('Room 2', 2, 'single', $all) !== null);
$msg = (string)ga_under_tier_message('Room 2', 2, 'single', $all);
ok('the refusal says which room, how many guests, the tier and what to choose', strpos($msg, 'Room 2 has 2 guests but is priced as Single (1 guest). Choose Double (2 guests), or ask someone who can override prices.') === 0, $msg);
ok('Double with 3 guests is refused', ga_under_tier_message('Room 1', 3, 'double', $all) !== null);
ok('Single with 4 guests is refused', ga_under_tier_message('Room 1', 4, 'single', $all) !== null);
ok('Double with 4 guests is refused (4 uses the 3+ tier)', ga_under_tier_message('Room 1', 4, 'double', $all) !== null);
ok('Triple with 4 guests is fine', ga_under_tier_message('Room 1', 4, 'triple', $all) === null);
ok('Triple for 2 guests (a dearer tier) stays allowed', ga_under_tier_message('Room 1', 2, 'triple', $all) === null);
ok('Double for 1 guest (a dearer tier) stays allowed', ga_under_tier_message('Room 1', 1, 'double', $all) === null);
ok('the matching tier is fine for 1, 2 and 3 guests', ga_under_tier_message('R', 1, 'single', $all) === null && ga_under_tier_message('R', 2, 'double', $all) === null && ga_under_tier_message('R', 3, 'triple', $all) === null);
ok('when the 3+ tier is switched off, Double for 4 guests is not called cheap', ga_under_tier_message('R', 4, 'double', ['single_enabled' => 1, 'double_enabled' => 1, 'triple_enabled' => 0]) === null);
ok('only admin and manager may under-tier', ga_can_under_tier(['role' => 'admin']) && ga_can_under_tier(['role' => 'manager']) && !ga_can_under_tier(['role' => 'receptionist']) && !ga_can_under_tier(['role' => 'accountant']) && !ga_can_under_tier(null));

// Floor with tiers switched off, and the even-split judgement
$noTriple = ['single_enabled' => 1, 'double_enabled' => 1, 'triple_enabled' => 0];
$noDouble = ['single_enabled' => 1, 'double_enabled' => 0, 'triple_enabled' => 1];
ok('Triple off: the floor for 3 guests is Double, so Single for 3 is refused', ga_tier_floor(3, $noTriple) === 'double' && ga_under_tier_message('R', 3, 'single', $noTriple) !== null);
ok('Triple off: Double for 3 guests is allowed', ga_under_tier_message('R', 3, 'double', $noTriple) === null);
ok('Double off: the floor for 2 guests is Triple, so Single for 2 is refused and Triple allowed', ga_tier_floor(2, $noDouble) === 'triple' && ga_under_tier_message('R', 2, 'single', $noDouble) !== null && ga_under_tier_message('R', 2, 'triple', $noDouble) === null);
ok('only Single enabled: nothing is "cheaper", Single is the floor', ga_tier_floor(4, ['single_enabled' => 1, 'double_enabled' => 0, 'triple_enabled' => 0]) === 'single');
$grp = fn(array $tiers, int $guests, int $cap = 3, ?array $pol = null) => ga_group_tier_message('Deluxe', array_map(fn($i) => 'Room ' . ($i + 1), array_keys($tiers)), $tiers, $guests, $cap, $pol ?? $all);
ok('group: 4 guests in 2 rooms of 3 as Double + Double is allowed', $grp(['double', 'double'], 4) === null);
ok('group: 4 guests as Triple + Single is allowed', $grp(['triple', 'single'], 4) === null && $grp(['single', 'triple'], 4) === null);
$gm = (string)$grp(['double', 'single'], 4);
ok('group: 4 guests as Double + Single is refused, naming the cheapest room', strpos($gm, 'These 2 Deluxe rooms hold 4 guests but are priced for 3. Raise Room 2 to Double (2 guests), or ask someone who can override prices.') === 0, $gm);
ok('group: 4 guests as Single + Single is refused', $grp(['single', 'single'], 4) !== null);
ok('group: a dearer pair (Triple + Triple) is allowed', $grp(['triple', 'triple'], 4) === null);
ok('group: 2 guests in 2 rooms as Single + Single is allowed (1 each)', $grp(['single', 'single'], 2) === null);
ok('group: Triple off, 3 guests in one room: Double allowed, Single refused', $grp(['double'], 3, 3, $noTriple) === null && $grp(['single'], 3, 3, $noTriple) !== null);
ok('tier capacity: Single 1, Double 2, Triple = capacity, highest enabled tier = capacity', ga_tier_capacity('single', 4, $all) === 1 && ga_tier_capacity('double', 4, $all) === 2 && ga_tier_capacity('triple', 4, $all) === 4 && ga_tier_capacity('double', 3, $noTriple) === 3);

// ── 2. Public site / API: the tier follows the guest count, never a choice ─────
$type = null;
$ci = date('Y-m-d', strtotime('+700 day'));
$co = date('Y-m-d', strtotime('+702 day'));
foreach ($pdo->query("SELECT r.*, GREATEST(r.max_guests, COALESCE((SELECT MAX(ir.max_guests_override) FROM individual_rooms ir WHERE ir.room_type_id = r.id AND ir.is_active = 1), 0)) AS cap
                      FROM rooms r WHERE r.is_active = 1 ORDER BY r.id")->fetchAll(PDO::FETCH_ASSOC) as $cand) {
    if (roomTypeHasActiveCombinations((int)$cand['id'])) { continue; }
    $pol = resolveOccupancyPolicy($cand, null);
    if (empty($pol['single_enabled']) || empty($pol['double_enabled']) || (int)$cand['cap'] < 2) { continue; }
    $av = checkRoomAvailability((int)$cand['id'], $ci, $co);
    if (!empty($av['available']) && (int)($av['remaining_rooms'] ?? 0) >= 1) {
        if (!$type) { $type = $cand; }                      // first usable type
        if (!empty($pol['triple_enabled'])) { $type = $cand; break; } // prefer one with the 3+ tier on, to test a dearer tier
    }
}
if ($type) {
    $room = $type; $room['max_guests'] = (int)$type['cap'];
    $derivedOk = true; $detail = [];
    foreach ([1, 2, 3, 4] as $g) {
        if ($g > (int)$type['cap']) { break; }
        $plan = ga_plan($room, $g, 0);
        if (empty($plan['valid'])) { continue; }
        $row = $plan['allocation'][0];
        $detail[] = $g . '=' . $row['occupancy_type'];
        $derivedOk = $derivedOk && ga_under_tier_message('R', (int)$row['guests'], (string)$row['occupancy_type'], $plan['policy']) === null;
    }
    ok('public booking page + API: the derived tier is never cheaper than the guests (' . implode(', ', $detail) . ')', $derivedOk);
} else {
    ok('skipped: no plain room type with 3 free rooms on this database', true);
}

// ── 3. create-booking.php ───────────────────────────────────────────────────────
if ($type) {
    $rid = (int)$type['id'];
    $pol = resolveOccupancyPolicy($type, null);
    $recep = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role = 'receptionist' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $tag = 'TIERTEST ' . bin2hex(random_bytes(3));
    $grant = $recep ? [
        "DELETE FROM user_permissions WHERE user_id = " . (int)$recep['id'] . " AND permission_key IN ('create_booking','edit_booking','bookings')",
        "INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (" . (int)$recep['id'] . ", 'create_booking', 1), (" . (int)$recep['id'] . ", 'edit_booking', 1), (" . (int)$recep['id'] . ", 'bookings', 1)",
    ] : [];
    $mk = function (int $guests, string $occ) use ($rid, $ci, $co, $tag): array {
        return ['create_booking' => '1', 'guest_name' => $tag, 'guest_email' => 'tiertest@example.invalid', 'guest_phone' => '+265000000002',
            'number_of_guests' => (string)$guests, 'child_guests' => '0', 'check_in_date' => $ci, 'check_out_date' => $co,
            'booking_status' => 'confirmed', 'auto_assign_room' => '1',
            'room_line_room_id' => [(string)$rid], 'room_line_qty' => ['1'], 'room_line_occupancy' => [$occ], 'room_line_override' => ['']];
    };
    $verify = [
        "SELECT COUNT(*) AS c FROM bookings WHERE guest_name = " . $pdo->quote($tag),
        "SELECT a.note FROM booking_audit_log a JOIN bookings b ON b.id = a.booking_id WHERE b.guest_name = " . $pdo->quote($tag) . " ORDER BY a.id",
        "SELECT occupancy_type FROM bookings WHERE guest_name = " . $pdo->quote($tag) . " ORDER BY id",
    ];
    $count = fn(array $r): int => (int)($r['verify'][0][0]['c'] ?? -1);
    $notes = fn(array $r): string => json_encode($r['verify'][1] ?? []);

    echo "== create-booking.php, type #$rid {$type['name']}, $ci -> $co ==\n";
    if ($recep) {
        $r = ot_run('admin/create-booking.php', ['post' => $mk(2, 'single'), 'as_username' => $recep['username'], 'setup' => $grant, 'verify' => $verify]);
        ok('receptionist: Single for 2 guests is refused, nothing saved', $count($r) === 0 && strpos(ot_flash($r), 'Room 1 has 2 guests but is priced as Single (1 guest). Choose Double (2 guests)') !== false, ot_flash($r) . ' ' . json_encode($r['error'] ?? ''));
        ok('  run rolled back', ot_clean($r));
        $r = ot_run('admin/create-booking.php', ['post' => $mk(2, 'double'), 'as_username' => $recep['username'], 'setup' => $grant, 'verify' => $verify]);
        ok('receptionist: Double for 2 guests (the right tier) is saved', $count($r) === 1 && strpos($notes($r), 'under-tier') === false, ot_flash($r));
        ok('  run rolled back', ot_clean($r));
        if (!empty($pol['triple_enabled'])) {
            $r = ot_run('admin/create-booking.php', ['post' => $mk(2, 'triple'), 'as_username' => $recep['username'], 'setup' => $grant, 'verify' => $verify]);
            ok('receptionist: Triple for 2 guests (a dearer tier) is still allowed', $count($r) === 1 && ($r['verify'][2][0]['occupancy_type'] ?? '') === 'triple', ot_flash($r));
            ok('  run rolled back', ot_clean($r));
        }
    } else {
        echo "[SKIP] no receptionist account on this install: refusal runs skipped\n";
    }
    $r = ot_run('admin/create-booking.php', ['post' => $mk(2, 'single'), 'verify' => $verify]);
    ok('admin: Single for 2 guests is saved', $count($r) === 1 && ($r['verify'][2][0]['occupancy_type'] ?? '') === 'single', ot_flash($r) . ' ' . json_encode($r['error'] ?? ''));
    ok('admin: the booking audit records the deliberate under-tier', strpos($notes($r), 'Deliberate under-tier') !== false && strpos($notes($r), 'Single (1 guest)') !== false, $notes($r));
    ok('  run rolled back', ot_clean($r));
    $r = ot_run('admin/create-booking.php', ['post' => $mk(1, 'single'), 'verify' => $verify]);
    ok('admin: Single for 1 guest saves with no under-tier note', $count($r) === 1 && strpos($notes($r), 'under-tier') === false, ot_flash($r));
    ok('  run rolled back', ot_clean($r));

}
// ── 3b. create-booking.php with room settings changed inside the rolled-back run (greedy seating, tiers switched off) ──
$t2 = null;
foreach ($pdo->query("SELECT r.id FROM rooms r WHERE r.is_active = 1 ORDER BY r.id")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
    if (roomTypeHasActiveCombinations((int)$cid)) { continue; }
    $av2 = checkRoomAvailability((int)$cid, $ci, $co);
    if (!empty($av2['available']) && (int)($av2['remaining_rooms'] ?? 0) >= 2) { $t2 = (int)$cid; break; }
}
$recep2 = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role = 'receptionist' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($t2 && $recep2) {
    echo "== create-booking.php, rooms of type #$t2 set to hold 3 ==\n";
    $tag2 = 'TIERTEST2 ' . bin2hex(random_bytes(3));
    $uid2 = (int)$recep2['id'];
    $g2 = ["DELETE FROM user_permissions WHERE user_id = $uid2 AND permission_key IN ('create_booking','bookings')",
           "INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES ($uid2, 'create_booking', 1), ($uid2, 'bookings', 1)",
           "UPDATE individual_rooms SET max_guests_override = NULL WHERE room_type_id = $t2"];
    $set = fn(int $s, int $d, int $t) => "UPDATE rooms SET max_guests = 3, single_occupancy_enabled = $s, double_occupancy_enabled = $d, triple_occupancy_enabled = $t, price_double_occupancy = IF(price_double_occupancy = 0, price_per_night, price_double_occupancy), price_triple_occupancy = IF(price_triple_occupancy = 0, price_per_night, price_triple_occupancy) WHERE id = $t2";
    $mk2 = function (int $guests, array $occs) use ($t2, $ci, $co, $tag2): array {
        return ['create_booking' => '1', 'guest_name' => $tag2, 'guest_email' => 'tiertest@example.invalid', 'guest_phone' => '+265000000004',
            'number_of_guests' => (string)$guests, 'child_guests' => '0', 'check_in_date' => $ci, 'check_out_date' => $co,
            'booking_status' => 'confirmed', 'auto_assign_room' => '1',
            'room_line_room_id' => array_fill(0, count($occs), (string)$t2), 'room_line_qty' => array_fill(0, count($occs), '1'),
            'room_line_occupancy' => $occs, 'room_line_override' => array_fill(0, count($occs), '')];
    };
    $ver2 = ["SELECT COUNT(*) AS c FROM bookings WHERE guest_name = " . $pdo->quote($tag2)];
    $run2 = fn(array $post, array $setup) => ot_run('admin/create-booking.php', ['post' => $post, 'as_username' => $recep2['username'], 'setup' => array_merge($g2, $setup), 'verify' => $ver2, 'grep' => ['ask someone who can override prices']]);
    $n2 = fn(array $r): int => (int)($r['verify'][0][0]['c'] ?? -1);
    $r = $run2($mk2(4, ['double', 'double']), [$set(1, 1, 1)]);
    ok('receptionist: 4 adults in 2 Double rooms that hold 3 (2+2) is allowed, not forced up to Triple', $n2($r) === 2, ot_flash($r) . ' ' . json_encode($r['error'] ?? ''));
    ok('  run rolled back', ot_clean($r));
    foreach ([[['triple', 'single'], true, 'Triple + Single'], [['double', 'single'], false, 'Double + Single'], [['single', 'single'], false, 'Single + Single']] as [$combo, $allowed, $what]) {
        $r = $run2($mk2(4, $combo), [$set(1, 1, 1)]);
        ok("receptionist: 4 guests in 2 rooms that hold 3 as $what is " . ($allowed ? 'allowed' : 'refused, nothing saved'), $allowed ? $n2($r) === 2 : ($n2($r) === 0 && !empty($r['found']['ask someone who can override prices'])), ot_flash($r));
        ok('  run rolled back', ot_clean($r));
    }
    $r = $run2($mk2(3, ['single']), [$set(1, 1, 0)]);
    ok('receptionist: Single for 3 guests with Triple off is refused, nothing saved', $n2($r) === 0 && !empty($r['found']['ask someone who can override prices']), ot_flash($r));
    ok('  run rolled back', ot_clean($r));
    $r = $run2($mk2(3, ['double']), [$set(1, 1, 0)]);
    ok('receptionist: Double for 3 guests with Triple off is allowed', $n2($r) === 1, ot_flash($r));
    ok('  run rolled back', ot_clean($r));
    $r = $run2($mk2(2, ['single']), [$set(1, 0, 1)]);
    ok('receptionist: Single for 2 guests with Double off is refused', $n2($r) === 0 && !empty($r['found']['ask someone who can override prices']), ot_flash($r));
    ok('  run rolled back', ot_clean($r));
    $r = $run2($mk2(2, ['triple']), [$set(1, 0, 1)]);
    ok('receptionist: Triple for 2 guests with Double off is allowed', $n2($r) === 1, ot_flash($r));
    ok('  run rolled back', ot_clean($r));
} else {
    echo "[SKIP] needs a plain room type with 2 free rooms and a receptionist account\n";
}

// ── 4. edit-booking.php: a 1-guest Single reservation seeded inside each rolled-back run (works on an empty install) ──
if ($type) {
    echo "== edit-booking.php ==\n";
    {
        $bid = 2098000001;
        $rate = (float)(!empty($type['price_single_occupancy']) ? $type['price_single_occupancy'] : $type['price_per_night']);
        $seed = ["INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, status, occupancy_type)
                  VALUES ($bid, 'TIERTEST$bid', " . (int)$type['id'] . ", 'TIERTEST edit', 'tiertest@example.invalid', '+265000000003', '$ci', '$co', 2, 1, 1, 0, " . ($rate * 2) . ", 'confirmed', 'single')"];
        $post = ['guest_name' => 'TIERTEST edit', 'guest_email' => 'tiertest@example.invalid', 'check_in_date' => $ci, 'check_out_date' => $co,
            'number_of_guests' => '2', 'child_guests' => '0', 'occupancy_type' => 'single', 'individual_room_id' => '', 'notify_guest' => '0'];
        $verifyE = ["SELECT number_of_guests, occupancy_type FROM bookings WHERE id = $bid", "SELECT note FROM booking_audit_log WHERE booking_id = $bid ORDER BY id DESC LIMIT 1"];
        if ($recep) {
            $grantE = [
                "DELETE FROM user_permissions WHERE user_id = " . (int)$recep['id'] . " AND permission_key IN ('edit_booking','bookings')",
                "INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (" . (int)$recep['id'] . ", 'edit_booking', 1), (" . (int)$recep['id'] . ", 'bookings', 1)",
            ];
            $r = ot_run('admin/edit-booking.php', ['post' => $post, 'get' => ['id' => (string)$bid], 'as_username' => $recep['username'], 'setup' => array_merge($grantE, $seed), 'verify' => $verifyE, 'grep' => ['priced as Single (1 guest). Choose Double (2 guests)']]);
            ok('receptionist edit: 2 guests left on Single is refused, nothing saved', (int)($r['verify'][0][0]['number_of_guests'] ?? 0) === 1 && !empty($r['found']['priced as Single (1 guest). Choose Double (2 guests)']), ot_flash($r) . ' ' . json_encode($r['verify'][0] ?? ''));
            ok('  run rolled back', ot_clean($r));
        }
        $r = ot_run('admin/edit-booking.php', ['post' => $post, 'get' => ['id' => (string)$bid], 'setup' => $seed, 'verify' => $verifyE]);
        ok('admin edit: 2 guests on Single is saved', (int)($r['verify'][0][0]['number_of_guests'] ?? 0) === 2 && ($r['verify'][0][0]['occupancy_type'] ?? '') === 'single', ot_flash($r) . ' ' . json_encode($r['verify'][0] ?? ''));
        ok('admin edit: the audit records the deliberate under-tier', strpos(json_encode($r['verify'][1] ?? []), 'Deliberate under-tier') !== false, json_encode($r['verify'][1] ?? ''));
        ok('  run rolled back', ot_clean($r));
        $post['occupancy_type'] = 'double';
        $r = ot_run('admin/edit-booking.php', ['post' => $post, 'get' => ['id' => (string)$bid], 'as_username' => $recep['username'] ?? null, 'setup' => array_merge($recep ? $grantE : [], $seed), 'verify' => $verifyE]);
        ok('edit: 2 guests moved to Double is saved for everyone', (int)($r['verify'][0][0]['number_of_guests'] ?? 0) === 2 && ($r['verify'][0][0]['occupancy_type'] ?? '') === 'double', ot_flash($r));
        ok('  run rolled back', ot_clean($r));
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
