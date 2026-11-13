<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Group charge on Add Charge (owner 2026-11-14). Usage (repo root, TEST HOTEL ONLY):
 *   php scripts/tests/group_charge.php [any booking id of a 3+ room group with a cancelled room, default 2100014674]
 * 1. The plan (admin/includes/group-charge.php rh_group_charge_plan): split in cents, remainder on the lead, exact sum,
 *    rooms that cannot take charges skipped and listed, description length, too-small amounts, a lead that cannot take
 *    charges refuses the one-line mode.
 * 2. Rehearsal on a mirrored group, each case in ONE transaction that is rolled back:
 *    (a) one group line -> on the lead's folio, "Group:" line on the group invoice, invoice total / Still owed / whole-group
 *        payment owed all +amount exactly, History on the lead;
 *    (b) 100,000.01 split -> one line per live room summing exactly, the cancelled room skipped, History on every room;
 *    a changed group since the preview refuses and posts nothing.
 * 3. Through the real page (scripts/tests/page_harness.php, rolled back): a group room shows the choice, a single booking
 *    does not; preview JSON; a split POST; "This room" = one line as today.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/admin/includes/finance-account-sync.php';
require_once $root . '/admin/includes/group-payment.php';
require_once $root . '/admin/includes/receivables.php';
require_once $root . '/admin/includes/group-charge.php';
require_once $root . '/config/invoice.php';
require_once $root . '/includes/booking-history.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= 0.005;

// ---- 1. the plan, on made-up rooms ------------------------------------------------------------------------------
$fake = static function (array $spec, int $lead): array {
    $rooms = [];
    foreach ($spec as $id => $st) {
        $a = bookingAllowsAction(['status' => $st], 'add_charge');
        $rooms[] = ['id' => $id, 'booking_reference' => 'R' . $id, 'guest' => 'G' . $id, 'status' => $st, 'room' => (string)$id, 'can' => $a['allowed'], 'reason' => $a['reason']];
    }
    return ['root' => $lead, 'lead' => $lead, 'rooms' => $rooms];
};
$g = $fake([1 => 'confirmed', 2 => 'confirmed', 3 => 'cancelled', 4 => 'checked-in', 5 => 'checked-out', 6 => 'no-show'], 1);
$p = rh_group_charge_plan($g, 'split', 'Boat trip', 1, 100000.01);
ok('split 100,000.01: one line per room that can take charges (3)', $p['ok'] && count($p['lines']) === 3, json_encode($p['message']));
ok('split: lines add up to the amount exactly', $near(array_sum(array_column($p['lines'], 'amount')), 100000.01));
ok('split: the leftover cent(s) on the lead (33,333.35 / 33,333.33 / 33,333.33)', $near($p['lines'][0]['amount'], 33333.35) && $p['lines'][0]['booking_id'] === 1
    && $near($p['lines'][1]['amount'], 33333.33) && $near($p['lines'][2]['amount'], 33333.33));
ok('split: cancelled / checked-out / no-show rooms listed as skipped', array_column($p['skipped'], 'id') === [3, 5, 6]);
ok('split: "Group: <desc> (share i of N)", quantity 1', $p['lines'][1]['description'] === 'Group: Boat trip (share 2 of 3)' && $p['lines'][1]['quantity'] == 1.0);
$p = rh_group_charge_plan($g, 'split', 'Boat trip', 3, 1000.00);
ok('split with quantity 3: 3,000.00 total, quantity in the line text', $p['ok'] && $near(array_sum(array_column($p['lines'], 'amount')), 3000) && strpos($p['lines'][0]['description'], 'Boat trip × 3 (share 1 of 3)') !== false);
$p = rh_group_charge_plan($g, 'split', 'X', 1, 0.02);
ok('split refuses an amount below 0.01 per room', !$p['ok'] && $p['lines'] === []);
$p = rh_group_charge_plan($g, 'split', str_repeat('a', 240), 1, 30);
ok('split refuses a description too long once "Group:" and the share are added', !$p['ok']);
$p = rh_group_charge_plan($g, 'line', 'Conference hall', 2, 50000.005);
ok('one line: on the lead, as entered, "Group: <desc>", nothing skipped', $p['ok'] && count($p['lines']) === 1 && $p['lines'][0]['booking_id'] === 1
    && $p['lines'][0]['description'] === 'Group: Conference hall' && $p['lines'][0]['quantity'] == 2.0 && $p['skipped'] === []);
$g2 = $fake([1 => 'checked-out', 2 => 'confirmed', 3 => 'confirmed'], 1);
$p = rh_group_charge_plan($g2, 'line', 'Hall', 1, 100);
ok('one line refused when the lead room cannot take charges', !$p['ok'] && stripos($p['message'], 'lead room') !== false);
$p = rh_group_charge_plan($g2, 'split', 'Hall', 1, 100.01);
ok('split with a closed lead: leftover cent on the first live room', $p['ok'] && $p['lines'][0]['booking_id'] === 2 && $near($p['lines'][0]['amount'], 50.01) && $near($p['lines'][1]['amount'], 50.00));
$p = rh_group_charge_plan($fake([9 => 'confirmed'], 9), 'split', 'Hall', 1, 100);
ok('a single booking is not a group', !$p['ok']);
$p1 = rh_group_charge_plan($g, 'split', 'Boat trip', 1, 90);
$g3 = $g; $g3['rooms'][3]['can'] = false; // room 4 checks out after the preview
$p2 = rh_group_charge_plan($g3, 'split', 'Boat trip', 1, 90);
ok('the preview signature changes when the room set changes', $p1['sig'] !== $p2['sig']);

// ---- 2. rehearsal on the mirrored group -------------------------------------------------------------------------
$any = (int)($argv[1] ?? 2100014674);
$rq = $pdo->prepare('SELECT COALESCE(NULLIF(primary_booking_id, 0), id) FROM bookings WHERE id = ?');
$rq->execute([$any]);
$root0 = (int)$rq->fetchColumn();
if ($root0 === 2100008837) { fwrite(STDERR, "Refused: group 2100008837 holds an applied rate correction.\n"); exit(1); }
$user = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$user = ['id' => (int)$user['id'], 'username' => (string)$user['username'], 'full_name' => (string)$user['full_name']];
$grp = rh_group_charge_rooms($pdo, $root0);
$gids = array_column($grp['rooms'], 'id');
$live = array_values(array_filter($grp['rooms'], static fn($r) => $r['can']));
$cancelled = array_values(array_filter($grp['rooms'], static fn($r) => $r['status'] === 'cancelled'));
ok("mirrored group $root0: 3+ rooms, a cancelled one, 2+ that can take charges", count($gids) >= 3 && $cancelled && count($live) >= 2,
    json_encode(array_map(static fn($r) => $r['id'] . ':' . $r['status'], $grp['rooms'])));
$lead = (int)$grp['lead'];
$idList = implode(',', array_map('intval', $gids));
$mods = ['conference' => false, 'gym' => false, 'events' => false, 'tabs' => false];
$snap = static function () use ($pdo, $root0, $idList, $gids, $mods, $lead): array {
    $owed = 0.0;
    foreach (rh_receivable_rows($pdo, $mods) as $r) {
        if ($r['type'] === 'room' && in_array((int)$r['id'], $gids, true)) { $owed += (float)$r['amount_due']; }
    }
    $rows = rh_invoice_doc_rows($pdo, $root0);
    $t = null;
    $html = buildInvoiceHTML($rows['booking'], 'TEST-GC', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $rows['group_bookings'], null, false, $t);
    $gp = rh_group_pay_preview($pdo, $lead, 1.0);
    return ['still_owed' => round($owed, 2), 'inv_total' => (float)($t['total'] ?? 0), 'inv_balance' => (float)($t['balance'] ?? 0), 'html' => (string)$html,
        'grp_owed' => (float)($gp['group_owed'] ?? 0),
        'charges' => (int)$pdo->query("SELECT COUNT(*) FROM booking_charges WHERE booking_id IN ($idList)")->fetchColumn(),
        'timeline' => (int)$pdo->query("SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_id IN ($idList)")->fetchColumn()];
};
$outside = $snap();

// (a) one line for the group
$amtA = 12345.67;
$descA = 'GCTEST hall hire ' . bin2hex(random_bytes(3));
$pdo->beginTransaction();
try {
    $before = $snap();
    $res = rh_group_charge_post($pdo, $root0, 'line', 'service', $descA, 1, $amtA, $user);
    $after = $snap();
    $row = $res['charges'] ? $pdo->query('SELECT booking_id, description, line_total FROM booking_charges WHERE id = ' . (int)$res['charges'][0]['charge_id'])->fetch(PDO::FETCH_ASSOC) : [];
    ok('(a) posted one line, on the group\'s lead room ' . $lead, $res['success'] && count($res['charges']) === 1 && (int)($row['booking_id'] ?? 0) === $lead, $res['message']);
    ok('(a) line reads "Group: <desc>", amount exact', ($row['description'] ?? '') === 'Group: ' . $descA && $near($row['line_total'] ?? 0, $amtA));
    ok('(a) group invoice shows the "Group:" line', strpos($after['html'], htmlspecialchars('Group: ' . $descA)) !== false || strpos($after['html'], 'Group: ' . $descA) !== false);
    ok('(a) group invoice total +amount exactly', $near($after['inv_total'] - $before['inv_total'], $amtA), (string)($after['inv_total'] - $before['inv_total']));
    ok('(a) Still owed +amount exactly', $near($after['still_owed'] - $before['still_owed'], $amtA), (string)($after['still_owed'] - $before['still_owed']));
    ok('(a) whole-group payment owed +amount exactly', $near($after['grp_owed'] - $before['grp_owed'], $amtA), (string)($after['grp_owed'] - $before['grp_owed']));
    ok('(a) History on the lead', $after['timeline'] - $before['timeline'] === 1);
    echo '    rehearsal (a): lead ' . $lead . ', invoice total ' . number_format($before['inv_total'], 2) . ' -> ' . number_format($after['inv_total'], 2)
        . ', still owed ' . number_format($before['still_owed'], 2) . ' -> ' . number_format($after['still_owed'], 2) . "\n";
} finally {
    $pdo->rollBack();
}

// (b) 100,000.01 split across the live rooms
$amtB = 100000.01;
$pdo->beginTransaction();
try {
    $before = $snap();
    $prev = rh_group_charge_plan(rh_group_charge_rooms($pdo, $root0), 'split', 'GCTEST boat trip', 1, $amtB);
    $res = rh_group_charge_post($pdo, $root0, 'split', 'service', 'GCTEST boat trip', 1, $amtB, $user, $prev['sig']);
    $after = $snap();
    $n = count($live);
    $sum = array_sum(array_column($res['charges'], 'amount'));
    $onRooms = array_column($res['charges'], 'booking_id');
    sort($onRooms);
    $liveIds = array_column($live, 'id');
    sort($liveIds);
    ok("(b) one line per live room ($n)", $res['success'] && count($res['charges']) === $n && $onRooms === $liveIds, $res['message']);
    ok('(b) lines add up to 100,000.01 exactly', $near($sum, $amtB), (string)$sum);
    ok('(b) cancelled room not charged, listed in the result', !in_array((int)$cancelled[0]['id'], $onRooms, true) && strpos($res['message'], $cancelled[0]['booking_reference']) !== false, $res['message']);
    $leadShare = 0.0;
    foreach ($res['charges'] as $c) { if ($c['booking_id'] === $lead) { $leadShare = $c['amount']; } }
    ok('(b) leftover cents on the lead', in_array($lead, $onRooms, true) ? $near($leadShare, (10000001 - intdiv(10000001, $n) * ($n - 1)) / 100) : true, (string)$leadShare);
    ok('(b) Still owed and group invoice total +100,000.01 exactly', $near($after['still_owed'] - $before['still_owed'], $amtB) && $near($after['inv_total'] - $before['inv_total'], $amtB),
        ($after['still_owed'] - $before['still_owed']) . ' / ' . ($after['inv_total'] - $before['inv_total']));
    ok('(b) History on every room touched', $after['timeline'] - $before['timeline'] === $n);
    $histJson = json_encode(rh_booking_history($pdo, (int)$res['charges'][count($res['charges']) - 1]['booking_id']), JSON_UNESCAPED_UNICODE);
    ok('(b) the History panel shows "Group charge added" with the share and who', strpos((string)$histJson, 'Group charge added') !== false && strpos((string)$histJson, 'share ' . $n . ' of ' . $n) !== false);
    echo '    rehearsal (b): ' . $n . ' lines (' . implode(' + ', array_map(static fn($c) => number_format($c['amount'], 2), $res['charges'])) . ') = ' . number_format($sum, 2)
        . '; skipped ' . implode(', ', array_map(static fn($r) => $r['booking_reference'] . ' (' . $r['status'] . ')', array_filter($grp['rooms'], static fn($r) => !$r['can']))) . "\n";

    // A stale preview (signature of another plan) refuses and adds nothing.
    $mid = $snap();
    $stale = rh_group_charge_post($pdo, $root0, 'split', 'service', 'GCTEST boat trip', 1, $amtB, $user, hash('sha256', 'other'));
    ok('stale preview: refused, nothing added', !$stale['success'] && stripos($stale['message'], 'changed') !== false && $snap()['charges'] === $mid['charges'], $stale['message']);
} finally {
    $pdo->rollBack();
}

// The same confirm sent twice (network retry, second tab): the one-time token is used up before posting, so the second
// is refused and only one set of lines exists. Same steps as the page handler, in one rolled-back transaction.
$pdo->beginTransaction();
try {
    $before = $snap();
    $store = [];
    $plan = rh_group_charge_plan(rh_group_charge_rooms($pdo, $root0), 'split', 'GCTEST replay', 1, $amtB);
    $nonce = rh_group_charge_nonce_issue($store, $root0, $plan['sig']);
    $posted = 0; $refusals = [];
    for ($i = 0; $i < 2; $i++) {
        $why = rh_group_charge_nonce_take($store, $root0, $nonce, $plan['sig']);
        if ($why !== '') { $refusals[] = $why; continue; }
        $res = rh_group_charge_post($pdo, $root0, 'split', 'service', 'GCTEST replay', 1, $amtB, $user, $plan['sig']);
        $posted += $res['success'] ? 1 : 0;
    }
    $lines = (int)$pdo->query("SELECT COUNT(*) FROM booking_charges WHERE description LIKE 'Group: GCTEST replay%' AND booking_id IN ($idList)")->fetchColumn();
    ok('replay: the same confirm twice -> first posts, second refused ("already posted"), one set of lines', $posted === 1 && count($refusals) === 1
        && stripos($refusals[0], 'already posted') !== false && $lines === count($live) && $snap()['charges'] - $before['charges'] === count($live), $lines . ' lines');
    $store = [];
    $n1 = rh_group_charge_nonce_issue($store, $root0, $plan['sig']);
    ok('token: a wrong sig is refused and the token is gone', rh_group_charge_nonce_take($store, $root0, $n1, 'other') !== '' && rh_group_charge_nonce_take($store, $root0, $n1, $plan['sig']) !== '');
    $n2 = rh_group_charge_nonce_issue($store, $root0, $plan['sig']);
    ok('token: a token issued for one booking does not confirm another', rh_group_charge_nonce_take($store, $root0 + 1, $n2, $plan['sig']) !== '');
} finally {
    $pdo->rollBack();
}
$end = $snap();
ok('everything rolled back (charges, History, totals as before the test)', $end['charges'] === $outside['charges'] && $end['timeline'] === $outside['timeline'] && $near($end['inv_total'], $outside['inv_total']));

// ---- 3. through the real page (rolled back) ---------------------------------------------------------------------
$harness = $root . '/scripts/tests/page_harness.php';
function gc_run(array $post, array $opts = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => $post, 'get' => $opts['get'] ?? [], 'method' => $opts['method'] ?? 'POST', 'verify' => $opts['verify'] ?? [],
        'grep' => $opts['grep'] ?? [], 'ajax' => !empty($opts['ajax']), 'session' => $opts['session'] ?? []]));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' admin/booking-details.php ' . escapeshellarg($cfg) . ' 2>&1';
    $out = shell_exec($cmd);
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    return $res;
}
$clean = static fn(array $r): bool => !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']);
$from = (int)$live[count($live) - 1]['id']; // open the dialog from a room that is not the lead
$single = (int)$pdo->query("SELECT b.id FROM bookings b WHERE b.deleted_at IS NULL AND b.status IN ('confirmed','checked-in')
    AND COALESCE(b.primary_booking_id, 0) = 0 AND NOT EXISTS (SELECT 1 FROM bookings c WHERE c.primary_booking_id = b.id) ORDER BY b.id DESC LIMIT 1")->fetchColumn();

$r = gc_run([], ['method' => 'GET', 'get' => ['id' => (string)$from], 'grep' => ['id="gcScope"', 'value="group_split"']]);
ok('page: a group room shows "This room / Whole group"', $clean($r) && !empty($r['found']['id="gcScope"']) && !empty($r['found']['value="group_split"']), substr(json_encode($r), 0, 300));
$r = gc_run([], ['method' => 'GET', 'get' => ['id' => (string)$single], 'grep' => ['id="gcScope"', 'id="addChargeForm"']]);
ok('page: a single booking never sees the question', $clean($r) && empty($r['found']['id="gcScope"']) && !empty($r['found']['id="addChargeForm"']), substr(json_encode($r), 0, 300));

$base = ['charge_action' => 'add_charge', 'charge_type' => 'service', 'description' => 'GCTEST page split', 'quantity' => '1', 'unit_price' => '100000.01'];
$r = gc_run($base + ['charge_scope' => 'group_split', 'group_preview' => '1'], ['get' => ['id' => (string)$from], 'ajax' => true,
    'verify' => ["SELECT COUNT(*) AS n FROM booking_charges WHERE description LIKE 'Group: GCTEST page split%'"]]);
$j = $r['json'] ?? [];
ok('page: preview JSON = one row per live room, sums exactly, writes nothing', $clean($r) && !empty($j['preview']) && count($j['lines'] ?? []) === count($live)
    && $near(array_sum(array_column($j['lines'] ?? [], 'amount')), 100000.01) && (int)($r['verify'][0][0]['n'] ?? -1) === 0 && count($j['skipped'] ?? []) === count($grp['rooms']) - count($live),
    substr(json_encode($r), 0, 400));
ok('page: the preview carries a one-time confirm token', strlen((string)($j['nonce'] ?? '')) === 32);
$r = gc_run($base + ['charge_scope' => 'group_split'], ['get' => ['id' => (string)$from], 'ajax' => true,
    'verify' => ["SELECT COUNT(*) AS n FROM booking_charges WHERE description LIKE 'Group: GCTEST page split%'"]]);
ok('page: a group POST without the preview is refused, nothing added', $clean($r) && empty($r['json']['success']) && (int)($r['verify'][0][0]['n'] ?? -1) === 0
    && stripos((string)($r['json']['message'] ?? ''), 'preview') !== false, substr(json_encode($r['json'] ?? ''), 0, 200));
$confirm = $base + ['charge_scope' => 'group_split', 'group_sig' => (string)($j['sig'] ?? ''), 'group_nonce' => str_repeat('a', 32)];
$held = ['rh_gc_nonce' => [(string)$from => ['n' => str_repeat('a', 32), 'sig' => (string)($j['sig'] ?? ''), 't' => time()]]];
$r = gc_run($confirm, ['get' => ['id' => (string)$from], 'ajax' => true, 'session' => $held,
    'verify' => ["SELECT COUNT(*) AS n, COALESCE(SUM(line_total), 0) AS s FROM booking_charges WHERE description LIKE 'Group: GCTEST page split%' AND booking_id IN ($idList)"]]);
$v = $r['verify'][0][0] ?? [];
ok('page: confirm with the preview token posts the split (N lines, sum exact)', $clean($r) && !empty($r['json']['success']) && (int)($v['n'] ?? 0) === count($live) && $near($v['s'] ?? 0, 100000.01),
    json_encode($v) . ' ' . substr(json_encode($r['json'] ?? ($r['raw'] ?? '')), 0, 300));
$r = gc_run($confirm, ['get' => ['id' => (string)$from], 'ajax' => true,
    'verify' => ["SELECT COUNT(*) AS n FROM booking_charges WHERE description LIKE 'Group: GCTEST page split%'"]]);
ok('page: the same confirm resent (token used up) is refused, nothing added', $clean($r) && empty($r['json']['success']) && (int)($r['verify'][0][0]['n'] ?? -1) === 0
    && stripos((string)($r['json']['message'] ?? ''), 'already posted') !== false, substr(json_encode($r['json'] ?? ''), 0, 200));
$r = gc_run(['charge_action' => 'add_charge', 'charge_type' => 'service', 'description' => 'GCTEST page room', 'quantity' => '2', 'unit_price' => '150.50', 'charge_scope' => 'room'],
    ['get' => ['id' => (string)$from], 'ajax' => true, 'verify' => ["SELECT booking_id, quantity, unit_price, line_total FROM booking_charges WHERE description = 'GCTEST page room'"]]);
$v = $r['verify'][0] ?? [];
ok('page: "This room" = one line on this room only, as today', $clean($r) && !empty($r['json']['success']) && count($v) === 1 && (int)$v[0]['booking_id'] === $from && $near($v[0]['line_total'], 301.00),
    json_encode($v));
$r = gc_run(['charge_action' => 'add_charge', 'charge_type' => 'service', 'description' => 'GCTEST page single', 'quantity' => '1', 'unit_price' => '99.99'],
    ['get' => ['id' => (string)$single], 'ajax' => true, 'verify' => ["SELECT booking_id, line_total FROM booking_charges WHERE description = 'GCTEST page single'"]]);
$v = $r['verify'][0] ?? [];
ok('page: single booking Add Charge unchanged (no scope posted)', $clean($r) && !empty($r['json']['success']) && count($v) === 1 && (int)$v[0]['booking_id'] === $single, json_encode($v));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
