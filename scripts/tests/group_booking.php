<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Group booking on admin/create-booking.php (owner report 2026-10-22). Usage (project root): php scripts/tests/group_booking.php
 *   - Each room row is priced for the guests seated in THAT room: two rooms of the same type, occupancy and
 *     seating cost the same (the whole party's child charge used to land on Room 1 and double its price).
 *   - Per-row totals equal the rule the live preview uses (rate x nights + rate x child% x own children x nights,
 *     then rh_stay_totals), the booking total is the sum of the rows, and the children are seated per room.
 *   - The page's room-type availability answer still reports what is free (the screen then subtracts rows).
 * Every scenario runs in scripts/tests/page_harness.php (outer transaction that never commits, rolled back).
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
function gb_run(string $page, array $post, array $opts = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => $post, 'ajax' => !empty($opts['ajax']), 'verify' => $opts['verify'] ?? [], 'setup' => $opts['setup'] ?? []]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -600), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -600)]; } }
    return $res;
}

// A plain room type (no joined pairs) that allows children and has enough free rooms for a far-future week.
$ci = date('Y-m-d', strtotime('+620 day'));
$co = date('Y-m-d', strtotime('+627 day'));
$nights = 7;
$type = null; $free = 0;
foreach ($pdo->query("SELECT r.*, GREATEST(r.max_guests, COALESCE((SELECT MAX(ir.max_guests_override) FROM individual_rooms ir WHERE ir.room_type_id = r.id AND ir.is_active = 1), 0)) AS cap
                      FROM rooms r WHERE r.is_active = 1 AND r.children_allowed = 1 ORDER BY r.id")->fetchAll(PDO::FETCH_ASSOC) as $cand) {
    if (roomTypeHasActiveCombinations((int)$cand['id'])) { continue; }
    $av = checkRoomAvailability((int)$cand['id'], $ci, $co);
    $rem = (int)($av['remaining_rooms'] ?? 0);
    if (!empty($av['available']) && (int)$cand['cap'] >= 2 && $rem >= 7 && $rem > $free) { $type = $cand; $free = $rem; }
}
if (!$type) {
    ok('skipped: no child-friendly plain room type with 7 free rooms on this database (nothing to check)', true);
    echo "\n$pass passed, $fail failed\n";
    exit(0);
}
$rid = (int)$type['id'];
$cap = (int)$type['cap'];
$cpm = 50.0; // forced to 50% inside the rolled-back run so the child charge is real
echo "== type #$rid {$type['name']} (holds $cap, $free free), $ci -> $co ($nights nights) ==\n";

/** What the live preview / server charges one row: rate x nights + that room's children, through rh_stay_totals. */
function gb_expected_row(array $type, string $occ, int $children, string $ci, string $co, int $nights, float $cpm): float {
    global $pdo;
    $col = ['single' => 'price_single_occupancy', 'double' => 'price_double_occupancy', 'triple' => 'price_triple_occupancy'][$occ] ?? '';
    $rate = ($col !== '' && !empty($type[$col])) ? (float)$type[$col] : (float)$type['price_per_night'];
    $dyn = applyDynamicPricing($pdo, (int)$type['id'], $ci, $co, $nights, $rate);
    $rate = (float)$dyn['final_price'];
    $child = $children > 0 ? $rate * ($cpm / 100) * $children * $nights : 0.0;
    return rh_stay_totals($rate * $nights + $child, 'price')['total_with_vat'];
}

foreach ([['rooms' => 7, 'guests' => 14, 'children' => 2], ['rooms' => 10, 'guests' => 14, 'children' => 2]] as $sc) {
    $rooms = $sc['rooms'];
    $label = "{$sc['guests']} guests / {$sc['children']} children / $rooms rooms";
    if ($free < $rooms) { echo "[SKIP] $label: only $free rooms free\n"; continue; }
    $party = [];
    for ($i = 0; $i < $rooms; $i++) { $party[] = ['capacity' => $cap, 'children_allowed' => true, 'name' => $type['name']]; }
    $seat = ga_distribute_party($party, $sc['guests'] - $sc['children'], $sc['children']);
    ok("$label: the party can be seated", !empty($seat['valid']), (string)($seat['message'] ?? ''));
    if (empty($seat['valid'])) { continue; }
    $post = [
        'create_booking' => '1', 'guest_name' => 'GROUPTEST ' . $rooms, 'guest_email' => 'grouptest@example.invalid', 'guest_phone' => '+265000000001',
        'number_of_guests' => (string)$sc['guests'], 'child_guests' => (string)$sc['children'],
        'check_in_date' => $ci, 'check_out_date' => $co, 'booking_status' => 'confirmed', 'auto_assign_room' => '1',
        'room_line_room_id' => [], 'room_line_qty' => [], 'room_line_occupancy' => [], 'room_line_override' => [],
    ];
    foreach ($seat['rows'] as $row) {
        $post['room_line_room_id'][] = (string)$rid;
        $post['room_line_qty'][] = '1';
        $post['room_line_occupancy'][] = $row['guests'] <= 1 ? 'single' : 'double';
        $post['room_line_override'][] = '';
    }
    $r = gb_run('admin/create-booking.php', $post, [
        'setup' => ["UPDATE rooms SET child_price_multiplier = 50 WHERE id = $rid"],
        'verify' => ["SELECT id, number_of_guests, adult_guests, child_guests, occupancy_type, total_with_vat, child_supplement_total FROM bookings WHERE guest_name = " . $GLOBALS['pdo']->quote('GROUPTEST ' . $rooms) . " ORDER BY id"],
    ]);
    $rows = is_array($r['verify'][0] ?? null) ? $r['verify'][0] : [];
    ok("$label: harness rolled back, nothing left behind", empty($r['leak']) && empty($r['marker_lost']) && !empty($r['rolled_back']), json_encode([$r['error'] ?? null, $r['raw'] ?? null]));
    ok("$label: $rooms bookings were saved (one per room)", count($rows) === $rooms, 'saved ' . count($rows) . ' ' . json_encode($r['output_flash'] ?? []));
    if (count($rows) !== $rooms) { continue; }

    $sumRows = 0.0; $sumExpected = 0.0; $childSupSum = 0.0; $seatOk = true; $rowOk = true; $byKey = [];
    foreach ($rows as $i => $row) {
        $s = $seat['rows'][$i];
        $occ = $s['guests'] <= 1 ? 'single' : 'double';
        $exp = gb_expected_row($type, $occ, $s['children'], $ci, $co, $nights, $cpm);
        $sumExpected += $exp;
        $sumRows += (float)$row['total_with_vat'];
        $childSupSum += (float)$row['child_supplement_total'];
        $seatOk = $seatOk && (int)$row['adult_guests'] === $s['adults'] && (int)$row['child_guests'] === $s['children'];
        $rowOk = $rowOk && abs((float)$row['total_with_vat'] - $exp) < 0.01;
        $byKey[$occ . '|' . $s['adults'] . 'a' . $s['children'] . 'c'][] = (float)$row['total_with_vat'];
    }
    ok("$label: guests are seated per room exactly as the screen shows", $seatOk, json_encode($rows));
    ok("$label: every room total equals the preview rule (own children only)", $rowOk, json_encode(array_column($rows, 'total_with_vat')));
    $same = true;
    foreach ($byKey as $k => $totals) { $same = $same && count(array_unique(array_map(fn($t) => number_format($t, 2, '.', ''), $totals))) === 1; }
    ok("$label: identical rooms (same type, occupancy, seating) cost exactly the same", $same, json_encode($byKey));
    ok("$label: the booking total is the sum of the rooms", abs($sumRows - $sumExpected) < 0.01, "$sumRows vs $sumExpected");
    $expChildSup = 0.0;
    foreach ($seat['rows'] as $i => $s) {
        $occ = $s['guests'] <= 1 ? 'single' : 'double';
        $rate = $occ === 'single' ? (!empty($type['price_single_occupancy']) ? (float)$type['price_single_occupancy'] : (float)$type['price_per_night']) : (!empty($type['price_double_occupancy']) ? (float)$type['price_double_occupancy'] : (float)$type['price_per_night']);
        $rate = (float)applyDynamicPricing($pdo, $rid, $ci, $co, $nights, $rate)['final_price'];
        $expChildSup += $s['children'] > 0 ? round($rate * ($cpm / 100) * $s['children'] * $nights, 2) : 0.0;
    }
    ok("$label: the child charge is split by where the children sleep (total unchanged)", abs($childSupSum - $expChildSup) < 0.05, "$childSupSum vs $expChildSup");
}

// The availability answer the screen starts from: remaining rooms per type for the dates.
$r = gb_run('admin/create-booking.php', ['action' => 'check_room_type_availability', 'check_in' => $ci, 'check_out' => $co], ['ajax' => true]);
$map = [];
foreach (($r['json']['data'] ?? []) as $row) { $map[(int)$row['room_id']] = $row; }
ok('availability answer: the type reports its free rooms for the dates', isset($map[$rid]) && (int)$map[$rid]['rooms_left'] === $free && !empty($map[$rid]['available']), json_encode($map[$rid] ?? $r));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
