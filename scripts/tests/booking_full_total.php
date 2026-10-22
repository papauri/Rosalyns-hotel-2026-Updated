<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Booking "Total" is the FULL amount (room + VAT + levy = total_with_vat), owner 2026-10-23.
 * Usage (project root): php scripts/tests/booking_full_total.php
 *   - rh_booking_full_total(): total_with_vat when set, else total_amount + vat_amount for old NULL/0 rows.
 *   - The Bookings list, the booking details ajax, the dashboard pending card, the booking details page (folio
 *     total + group total) and the payment-add booking search all show that figure, in VAT exclusive AND
 *     inclusive settings (forced inside a rolled-back run), and never the ex-VAT total_amount.
 *   - Balances (stored amount_paid / amount_due, folio balance_due) are untouched.
 * Everything runs in a transaction that is rolled back; the page runs use scripts/tests/page_harness.php.
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
function bft_run(string $page, array $cfg): array {
    global $harness;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -600)];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -600)]; } }
    return $res;
}

$roomId = (int)$pdo->query("SELECT id FROM rooms WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if ($roomId <= 0) { ok('skipped: no active room type to attach test bookings to', true); echo "\n$pass passed, $fail failed\n"; exit(0); }

// ---- 1. the helper (pure) ----
ok('full total = total_with_vat when set', abs(rh_booking_full_total(['total_amount' => 100000, 'vat_amount' => 16500, 'total_with_vat' => 116500]) - 116500.0) <= BALANCE_TOLERANCE);
ok('NULL total_with_vat falls back to total_amount + vat_amount', abs(rh_booking_full_total(['total_amount' => 50000, 'vat_amount' => 8250, 'total_with_vat' => null]) - 58250.0) <= BALANCE_TOLERANCE);
ok('zero total_with_vat falls back too', abs(rh_booking_full_total(['total_amount' => 50000, 'vat_amount' => 8250, 'total_with_vat' => 0]) - 58250.0) <= BALANCE_TOLERANCE);
ok('no VAT and no total_with_vat falls back to total_amount', abs(rh_booking_full_total(['total_amount' => 42000]) - 42000.0) <= BALANCE_TOLERANCE);

// ---- 2. seeded bookings (in-process, rolled back): SQL expression + folio balance unchanged ----
$tag = 'BFT' . random_int(100000, 999999);
$far = date('Y-m-d', strtotime('+700 day'));
$far2 = date('Y-m-d', strtotime('+702 day'));
$insert = static function (string $ref, float $net, float $vat, $gross, float $paid, float $due, string $extraCols = '', string $extraVals = '') use ($roomId, $far, $far2): string {
    $grossSql = $gross === null ? '0' : (string)$gross; // column is NOT NULL: old rows hold 0
    return "INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights,
                number_of_guests, adult_guests, child_guests, status, payment_status, total_amount, vat_amount, total_with_vat, amount_paid, amount_due, created_at {$extraCols})
            VALUES ('{$ref}', {$roomId}, 'BFT Guest {$ref}', 'bft@example.invalid', '000', '{$far}', '{$far2}', 2, 2, 2, 0, 'pending', 'unpaid',
                {$net}, {$vat}, {$grossSql}, {$paid}, {$due}, '2000-01-01 00:00:00' {$extraVals})";
};
// A: VAT-exclusive style, B: VAT-inclusive style (different numbers), C: old row, total_with_vat NULL
$A = $tag . 'A'; $B = $tag . 'B'; $C = $tag . 'C';
$rows = [
    [$A, 100000.0, 16500.0, 116500.0, 20000.0, 96500.0],
    [$B, 87000.0, 13000.0, 100000.0, 0.0, 100000.0],
    [$C, 50000.0, 8250.0, null, 0.0, 58250.0],
];
$pdo->beginTransaction();
try {
    foreach ($rows as $r) { $pdo->exec($insert(...$r)); }
    $ids = [];
    foreach ($pdo->query("SELECT id, booking_reference FROM bookings WHERE booking_reference LIKE '{$tag}%'")->fetchAll(PDO::FETCH_ASSOC) as $x) { $ids[$x['booking_reference']] = (int)$x['id']; }
    ok('three test bookings seeded', count($ids) === 3);
    foreach ($rows as [$ref, $net, $vat, $gross, $paid, $due]) {
        $expect = $gross ?? ($net + $vat);
        $row = $pdo->query("SELECT * FROM bookings WHERE id = " . (int)$ids[$ref])->fetch(PDO::FETCH_ASSOC);
        ok("$ref helper returns the full amount", abs(rh_booking_full_total($row) - $expect) <= BALANCE_TOLERANCE, (string)rh_booking_full_total($row));
        $sqlVal = (float)$pdo->query("SELECT " . rh_booking_gross_sql('b') . " FROM bookings b WHERE b.id = " . (int)$ids[$ref])->fetchColumn();
        ok("$ref SQL gross expression matches the helper", abs($sqlVal - $expect) <= BALANCE_TOLERANCE, (string)$sqlVal);
        $fs = getBookingFolioSummary((int)$ids[$ref]);
        ok("$ref folio grand total equals the booking total (no extras)", abs((float)$fs['grand_total'] - $expect) <= BALANCE_TOLERANCE, (string)($fs['grand_total'] ?? 'n/a'));
        ok("$ref stored paid/due untouched", abs((float)$row['amount_paid'] - $paid) <= BALANCE_TOLERANCE && abs((float)$row['amount_due'] - $due) <= BALANCE_TOLERANCE);
    }
} finally {
    $pdo->rollBack();
}
ok('rolled back: no test bookings left', (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE '{$tag}%'")->fetchColumn() === 0);

// ---- 3. pages, in both VAT modes (settings forced inside the rolled-back harness run) ----
foreach (['exclusive', 'inclusive'] as $mode) {
    $tg = $tag . strtoupper(substr($mode, 0, 1));
    $a = $tg . 'A'; $b = $tg . 'B'; $c = $tg . 'C';
    $baseId = 2099000000 + ($mode === 'exclusive' ? 10 : 20);
    $setup = [
        "INSERT INTO site_settings (setting_key, setting_value) VALUES ('vat_enabled','1'),('vat_rate','16.5'),('vat_pricing_mode','{$mode}') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        $insert($a, 100000.0, 16500.0, 116500.0, 20000.0, 96500.0, ", id", ", {$baseId}"),
        $insert($b, 87000.0, 13000.0, 100000.0, 0.0, 100000.0, ", id", ", " . ($baseId + 1)),
        $insert($c, 50000.0, 8250.0, null, 0.0, 58250.0, ", id", ", " . ($baseId + 2)),
        "UPDATE bookings SET primary_booking_id = (SELECT x.id FROM (SELECT id FROM bookings WHERE booking_reference = '{$a}') x) WHERE booking_reference = '{$b}'",
    ];
    $vars = ['a' => "SELECT id FROM bookings WHERE booking_reference = '{$a}'", 'c' => "SELECT id FROM bookings WHERE booking_reference = '{$c}'"];
    $verify = ["SELECT booking_reference, amount_paid, amount_due FROM bookings WHERE booking_reference LIKE '{$tg}%' ORDER BY booking_reference"];

    // Bookings list (search by reference): the Total column
    $r = bft_run('admin/bookings.php', ['method' => 'GET', 'get' => ['search' => $tg], 'setup' => $setup, 'verify' => $verify,
        'grep' => ['K 116,500.00', 'K 100,000.00', 'K 58,250.00', 'K 87,000.00', 'K 50,000.00']]);
    $f = $r['found'] ?? [];
    ok("[$mode] bookings list shows the full total (VAT-exclusive style row)", !empty($f['K 116,500.00']), $r['raw'] ?? '');
    ok("[$mode] bookings list shows the full total (VAT-inclusive style row)", !empty($f['K 100,000.00']));
    ok("[$mode] bookings list shows the fallback total for the NULL total_with_vat row", !empty($f['K 58,250.00']));
    ok("[$mode] bookings list no longer shows the ex-VAT figures", empty($f['K 87,000.00']) && empty($f['K 50,000.00']));
    $v = $r['verify'][0] ?? [];
    ok("[$mode] balances unchanged after the list renders", is_array($v) && count($v) === 3 && abs((float)$v[0]['amount_paid'] - 20000) <= BALANCE_TOLERANCE && abs((float)$v[0]['amount_due'] - 96500) <= BALANCE_TOLERANCE);

    // Booking summary popup (ajax)
    $r = bft_run('admin/bookings.php', ['ajax' => true, 'post' => ['action' => 'get_booking_details', 'booking_id' => '{a}'], 'setup' => $setup, 'vars' => $vars]);
    $tf = $r['json']['data']['total_formatted'] ?? null;
    ok("[$mode] booking summary popup total is the full amount", $tf === '116,500.00', json_encode($tf) . ' ' . ($r['raw'] ?? ''));

    // Dashboard "pending bookings" card
    $r = bft_run('admin/dashboard.php', ['method' => 'GET', 'get' => ['ajax' => 'card_insight', 'card' => 'pending_bookings'], 'setup' => $setup]);
    $totals = [];
    foreach (($r['json']['rows'] ?? []) as $row) { $totals[$row['reference']['label'] ?? ''] = (string)($row['total'] ?? ''); }
    ok("[$mode] dashboard pending card shows the full total", isset($totals[$a]) && strpos($totals[$a], '116,500') !== false, json_encode($totals) . ' ' . ($r['raw'] ?? ''));
    ok("[$mode] dashboard pending card uses the fallback for the old row", isset($totals[$c]) && strpos($totals[$c], '58,250') !== false, json_encode($totals));

    // Booking details page: folio total and the group total (A + B = 216,500)
    $r = bft_run('admin/booking-details.php', ['method' => 'GET', 'get' => ['id' => (string)$baseId], 'setup' => $setup, 'grep' => ['116,500.00', '216,500.00', 'Group total']]);
    ok("[$mode] booking details shows the full total", !empty($r['found']['116,500.00']), $r['raw'] ?? '');
    ok("[$mode] booking details shows the group total (all rooms, full amounts)", !empty($r['found']['216,500.00']) && !empty($r['found']['Group total']));

    // Payment-add booking search
    $r = bft_run('admin/api/search-bookings.php', ['method' => 'GET', 'get' => ['type' => 'room', 'q' => $tg], 'setup' => $setup]);
    $by = [];
    foreach (($r['json']['bookings'] ?? []) as $bk) { $by[$bk['booking_reference']] = (float)$bk['total_amount']; }
    ok("[$mode] payment-add booking search Total is the full amount", isset($by[$a], $by[$b], $by[$c]) && abs($by[$a] - 116500) <= BALANCE_TOLERANCE && abs($by[$b] - 100000) <= BALANCE_TOLERANCE && abs($by[$c] - 58250) <= BALANCE_TOLERANCE, json_encode($by) . ' ' . ($r['raw'] ?? ''));
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
