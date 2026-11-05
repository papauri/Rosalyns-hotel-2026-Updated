<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * The Bookings "Decisions" panel (.claude/AI_REVIEW_PANELS_PARKED.md, owner go 2026-11-05): the Bookings page prints
 * nothing when the panel is off (AI off / no permission / bookings module off: empty string, nothing printed, engine
 * not loaded) and its source differs from before only by one require and one echo inside an existing PHP block; the
 * eight bookings checks (pace and pickup, high-demand nights, room types, cancellations and patterns, deposit exposure,
 * arrivals, short gaps, room clashes) on fixture rows, each figure equal to the shared read tools (room_performance,
 * booking_pace: the Reports screen's SQL) or the folio helper; joined rooms booked together are one stay (never a
 * clash); honest framing (rate and rule changes "Not valued", tier 3; forecasts need history; deferring is never a
 * saving); per-card permissions; the panel after one fake wording call (no guest name or reference leaves).
 *
 * Usage (from the repo root): php scripts/tests/ai_review_bookings_test.php
 * No network (fake transport). Live DB: every fixture (labelled DECTEST-) is written inside a transaction that is
 * rolled back; settings are overridden inside it only. The checks run on a fixed past day with no real bookings around it
 * so the figures are only the fixtures whatever the real data.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0; $skip = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function skip(string $l): void { global $skip; $skip++; echo "SKIP $l\n"; }
function near(float $a, float $b): bool { return abs($a - $b) < BALANCE_TOLERANCE; }

require_once $root . '/config/database.php';
require_once $root . '/config/security.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/includes/ai/ai-core.php';
require_once $root . '/admin/includes/review-panel.php';

$engineFile = realpath($root . '/includes/ai/ai-decisions.php');
$engineLoaded = static fn(): bool => in_array($engineFile, array_map('realpath', get_included_files()), true);
$onSql = ["UPDATE site_settings SET setting_value = '1' WHERE setting_key = 'ai_enabled'", "UPDATE site_settings SET setting_value = '' WHERE setting_key = 'ai_paused_reason'",
    "UPDATE enabled_modules SET is_enabled = 1 WHERE module_key IN ('ai_assistant', 'bookings', 'finance')"];
$adm = $pdo->query("SELECT id, role, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adm) {
    echo "No active admin account: nothing can be checked.\n";
    exit(1);
}
$adm['id'] = (int)$adm['id'];
$liveRows = static fn(): string => (string)json_encode($pdo->query("SELECT (SELECT COUNT(*) FROM ai_review_cache WHERE area = 'bookings'), (SELECT COUNT(*) FROM ai_review_proof WHERE area = 'bookings')")->fetch(PDO::FETCH_NUM));
$rowsBefore = $liveRows();
$_SESSION = ['admin_user_id' => $adm['id'], 'admin_role' => 'admin', 'admin_username' => (string)$adm['username'], 'admin_full_name' => (string)$adm['full_name']];
$mkUser = static function (PDO $db, string $tag, array $grant): int {
    $db->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 'receptionist', 1)")
        ->execute(['dectest_' . $tag . bin2hex(random_bytes(2)), 'dectest.' . $tag . '@example.com', password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), 'Dectest ' . ucfirst($tag)]);
    $id = (int)$db->lastInsertId();
    $g = $db->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)");
    foreach (array_keys(getAllPermissions()) as $k) {
        $g->execute([$id, $k, in_array($k, $grant, true) ? 1 : 0]);
    }
    return $id;
};
$render = static function (): array {
    ob_start();
    $h = rh_review_panel('bookings');
    return [$h, (string)ob_get_clean()];
};

// ════ 1. The Bookings page is unchanged when the panel is off ════
$page = 'admin/bookings.php';
$req = "require_once __DIR__ . '/includes/review-panel.php'; // the Decisions panel: prints nothing unless switched on and allowed\n";
$echoNew = "<?php echo rh_review_panel('bookings'); if (\$message): ?>";
$echoOld = "<?php if (\$message): ?>";
$norm = static fn(string $s): string => str_replace("\r\n", "\n", $s);
$src = $norm((string)file_get_contents($root . '/' . $page));
check(substr_count($src, "rh_review_panel('bookings')") === 1 && substr_count($src, $echoNew) === 1 && substr_count($src, $req) === 1,
    'bookings page: one require and one echo, added inside an existing PHP block (no new line, no whitespace printed)');
$hdr = strpos($src, "<?php require_once 'includes/admin-header.php'; ?>");
$content = strpos($src, '<div class="content">');
$at = strpos($src, $echoNew);
$banner = strpos($src, '<!-- Missed Check-ins Alert Banner -->');
check($hdr !== false && $content > $hdr && $at > $content && $banner !== false && $at < $banner, 'bookings page: the panel sits inside the page container, first in it, before the alerts and banners');
$git = static fn(string $args): string => (string)shell_exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'));
$intro = trim((string)preg_replace('/\s+/', ' ', $git('log --format=%H -S' . escapeshellarg("rh_review_panel('bookings')") . ' -- ' . $page)));
$intro = $intro !== '' ? (string)substr($intro, strrpos(' ' . $intro, ' ')) : '';
$after = $intro === '' ? $src : $norm($git('show ' . $intro . ':' . $page));
$before = $norm($git('show ' . ($intro === '' ? 'HEAD' : $intro . '~1') . ':' . $page));
if ($before === '') {
    skip('bookings page source before the panel could not be read from git');
} else {
    $stripped = str_replace([$req, $echoNew], ['', $echoOld], $after);
    check($stripped === $before, 'bookings page: with the one require and the one echo taken out, the source is byte-for-byte what it was before the panel', $intro === '' ? 'vs HEAD' : 'vs ' . $intro . '~1');
}

// ════ 2. Gate off: nothing at all ════
$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE site_settings SET setting_value = '0' WHERE setting_key = 'ai_enabled'");
    [$h, $printed] = $render();
    check($h === '' && $printed === '' && !$engineLoaded(), 'AI off: the bookings panel is an empty string, nothing printed, the engine not loaded');
    foreach ($onSql as $q) { $pdo->exec($q); }
    if (!rh_ai_enabled($pdo)) {
        skip('AI could not be switched on inside the test transaction: permission / module cases skipped');
    } else {
        $noUse = $mkUser($pdo, 'nouse', ['dashboard', 'bookings', 'reports', 'accounting']);
        $noBk = $mkUser($pdo, 'nobk', ['dashboard', 'ai_assistant_use', 'stock_dashboard', 'accounting']);
        foreach ([[$noUse, 'no Assistant use permission'], [$noBk, 'no bookings permission']] as [$u, $why]) {
            $_SESSION['admin_user_id'] = $u;
            $_SESSION['admin_role'] = 'receptionist';
            [$h, $printed] = $render();
            check($h === '' && $printed === '' && !$engineLoaded(), "permission off ($why): empty string, nothing printed, engine not loaded");
        }
        $_SESSION['admin_user_id'] = $adm['id'];
        $_SESSION['admin_role'] = 'admin';
        $GLOBALS['__rh_ai_module_override'] = ['bookings' => false];
        [$h, $printed] = $render();
        check($h === '' && $printed === '' && !$engineLoaded(), 'bookings module off: empty string, nothing printed, engine not loaded');
        unset($GLOBALS['__rh_ai_module_override']);
    }
} finally {
    $pdo->rollBack();
    $_SESSION['admin_user_id'] = $adm['id'];
    $_SESSION['admin_role'] = 'admin';
}

// ════ 3. The bookings checks ════
require_once $root . '/includes/ai/ai-decisions.php';
if (!rh_dec_ready($pdo, true)) {
    skip('migration 075 has not run on this database: bookings checks skipped');
    echo "\n$pass passed, $fail failed, $skip skipped\n";
    exit($fail > 0 ? 1 : 0);
}
$ids = ['bk_pace', 'bk_demand', 'bk_room_types', 'bk_cancellations', 'bk_deposits', 'bk_arrivals', 'bk_gaps', 'bk_conflicts'];
$fr = rh_dec_finders()['bookings'];
check(array_keys($fr) === $ids, 'bookings has the eight checks: pace, high-demand nights, room types, cancellations, deposits, arrivals, short gaps, room clashes', implode(',', array_keys($fr)));
$b0 = rh_dec_build($pdo, 'bookings', rh_dec_context($pdo, true));
check(count($b0['checks']) === 8 && !array_filter($b0['checks'], static fn($k) => empty($k['ok'])), 'every bookings check runs on this database as it is', json_encode(array_column($b0['checks'], 'ok', 'id')));
$money = ['accounting', 'reports'];
check($fr['bk_pace']['requires']['perm'] === $money && $fr['bk_demand']['requires']['perm'] === $money && $fr['bk_room_types']['requires']['perm'] === $money
    && $fr['bk_deposits']['requires']['perm'] === ['payments', 'accounting'] && $fr['bk_arrivals']['requires']['perm'] === ['bookings'] && $fr['bk_gaps']['requires']['perm'] === ['bookings']
    && $fr['bk_conflicts']['requires']['perm'] === ['bookings'], 'each card asks for the permission of what it shows (revenue: Reports/Accounting; folio balances ahead: Payments/Accounting; arrivals, gaps, clashes: Bookings)');
$engSrc = (string)file_get_contents($engineFile);
$s0 = strpos($engSrc, '// ── Finders: bookings');
$s1 = strpos($engSrc, '// ── Finders: accounting');
$bkSrc = ($s0 !== false && $s1 !== false) ? substr($engSrc, $s0, $s1 - $s0) : '';
preg_match_all('/(INSERT INTO|(?<!KEY )UPDATE|DELETE FROM)\s+`?([a-z_]+)/i', $engSrc, $wm);
check(!array_values(array_filter(array_unique($wm[2]), static fn($t) => strpos($t, 'ai_review_') !== 0)), 'the engine still writes only to the ai_review_* tables');
check($bkSrc !== '' && !preg_match('/\b(MRA|fiscal)\b/i', $bkSrc) && !preg_match('/[^=!<>]==[^=]\s*[0-9.]+|\$\w+\s*==\s*0\.0/', $bkSrc), 'no MRA wording and no float equality in the bookings checks');
check($bkSrc !== '' && !preg_match('/rh_ai_mod\(/', $bkSrc), 'the bookings checks read the module switch through rh_dec_mod (unreadable = partial, never "off")');
check(substr_count($bkSrc, 'special_requests') === 1 && strpos($bkSrc, "(TRIM(COALESCE(b.special_requests, '')) != '') AS has_req") !== false, 'special requests are only counted (a yes/no), their text is never read');
check(strpos($bkSrc, 'getBookingStatusesThatBlockAvailability(true)') !== false && strpos($bkSrc, 'rh_effective_checkout_sql') !== false && strpos($bkSrc, 'br.released_at IS NULL') !== false
    && strpos($bkSrc, 'checkIndividualRoomAvailability(') !== false, 'rooms held follow the room availability check (its statuses, effective check-out, held joined rooms; a gap must pass the check itself)');
// Module off: every check runs and finds nothing; module switch unreadable: read anyway and marked partial
$GLOBALS['__rh_ai_module_override'] = ['bookings' => false];
$offCtx = rh_dec_context($pdo, true);
$offOk = true;
foreach ($fr as $id => $def) {
    $r = call_user_func($def['fn'], $pdo, $offCtx);
    $offOk = $offOk && !empty($r['ok']) && $r['cards'] === [];
}
unset($GLOBALS['__rh_ai_module_override']);
check($offOk, 'bookings module off: every bookings check returns no card (and counts as run)');
$GLOBALS['__rh_dec_module_unreadable'] = ['bookings'];
$pb = rh_dec_build($pdo, 'bookings', rh_dec_context($pdo, true));
unset($GLOBALS['__rh_dec_module_unreadable']);
check(!array_filter($pb['checks'], static fn($k) => !empty($k['ok']) && empty($k['partial'])), 'bookings module switch unreadable: every check reads anyway and is marked partial (never treated as off)', json_encode(array_column($pb['checks'], 'partial', 'id')));

// A far-off day with no real bookings, payments or history around it, so the figures are only the fixtures
$T = '';
foreach (['2001-06-30', '1996-06-30', '1991-06-30'] as $cand) {
    $lo = date('Y-m-d', strtotime($cand . ' -800 days'));
    $hi = date('Y-m-d', strtotime($cand . ' +400 days'));
    $st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM bookings WHERE (check_in_date BETWEEN ? AND ?) OR (check_out_date BETWEEN ? AND ?) OR (created_at BETWEEN ? AND ?))
        + (SELECT COUNT(*) FROM payments WHERE payment_date BETWEEN ? AND ?) + (SELECT COUNT(*) FROM booking_timeline_logs WHERE created_at BETWEEN ? AND ?)");
    $st->execute([$lo, $hi, $lo, $hi, $lo, $hi, $lo, $hi, $lo, $hi]);
    if ((int)$st->fetchColumn() === 0) {
        $T = $cand;
        break;
    }
}
$sellNow = rh_sellable_room_units();
$byType = rh_sellable_room_units_by_type();
$roomsByType = [];
foreach ($pdo->query("SELECT id, room_type_id, room_number FROM individual_rooms WHERE is_active = 1 AND status NOT IN ('maintenance', 'out_of_order') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $roomsByType[(int)$r['room_type_id']][] = (int)$r['id'];
}
$t1 = 0;
foreach ($byType as $t => $u) {
    if ((int)$u >= 2 && count($roomsByType[$t] ?? []) === (int)$u && ($t1 === 0 || (int)$u < (int)$byType[$t1])) {
        $t1 = (int)$t;
    }
}
$k = $t1 > 0 ? max(0, (int)ceil($sellNow / 2) - (int)$byType[$t1]) : 0;
$others = [];
foreach ($roomsByType as $t => $list) {
    if ((int)$t !== $t1) {
        foreach ($list as $rid) { $others[] = ['id' => $rid, 'type' => (int)$t]; }
    }
}

if ($T === '' || $t1 === 0 || count($others) < $k + 5 || count(array_filter($byType)) < 2) {
    skip('fixture figures: no empty far-off period, or too few rooms / room types to lay out the fixtures');
} else {
$settingsKeep = $GLOBALS['_SITE_SETTINGS'] ?? [];
$GLOBALS['dec_calls'] = [];
putenv('RH_AI_FAKE_PROVIDER=dectest');
rh_ai_set_transport(static function ($method, $url, $headers, $body, $timeout) {
    $GLOBALS['dec_calls'][] = ['body' => (string)$body];
    $find = static function ($v) use (&$find) {
        if (is_string($v) && strpos($v, '"cards"') !== false) {
            $j = json_decode($v, true);
            if (is_array($j) && isset($j['cards'])) { return $j; }
        }
        if (is_array($v)) { foreach ($v as $x) { $r = $find($x); if ($r !== null) { return $r; } } }
        return null;
    };
    $p = $find(json_decode((string)$body, true));
    $out = ['order' => [], 'cards' => []];
    foreach ((array)($p['cards'] ?? []) as $c) {
        $out['order'][] = $c['id'];
        $out['cards'][$c['id']] = ['decision' => $c['decision'], 'why_now' => $c['why_now'], 'recommendation' => $c['recommendation']];
    }
    return ['http' => 200, 'body' => json_encode(rh_ai_fake_body('google', ['text' => json_encode($out), 'usage' => ['in' => 90, 'out' => 60]], 'test-fast-model'))];
});
$GLOBALS['__rh_dec_models'] = ['provider' => 'google', 'fast' => ['model_id' => 'test-fast-model', 'price_in_per_mtok' => null, 'price_out_per_mtok' => null]];
$pdo->beginTransaction();
try {
    foreach ($onSql as $q) { $pdo->exec($q); }
    $set = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach (['ai_cloud_enabled' => '1', 'ai_review_writer_enabled' => '1', 'ai_review_impact_min_pct' => '0.01', 'ai_review_cards_max' => '8', 'ai_review_high_occ_pct' => '50'] as $kk => $v) { $set->execute([$kk, $v]); }
    rh_ai_settings($pdo, true);
    $pdo->exec("DELETE FROM ai_review_cache WHERE cache_day = CURDATE() AND area = 'bookings'"); // rolled back below
    $D = static fn(int $n): string => date('Y-m-d', strtotime($T . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));
    $ahead = rh_dec_num('ai_upcoming_days', 7, 366);
    $end = $D($ahead - 1);
    $arrDays = rh_dec_num('ai_review_arrival_days', 1, 14);
    $baseDays = rh_dec_num('ai_review_base_days', 7, 92);
    $made = $D(-60) . ' 10:00:00';
    $fx = [];
    $ins = $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, individual_room_id, room_combination_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights,
                              total_amount, vat_amount, total_with_vat, status, special_requests, deposit_required, deposit_amount, deposit_paid, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, '000', ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 0, ?)");
    $mk = static function (string $ref, int $type, ?int $room, string $in, int $nights, float $gross, string $status, array $o = []) use ($ins, $pdo, &$fx, $made): int {
        $ins->execute(['DECTEST-' . $ref, $type, $room, $o['combo'] ?? null, 'Dectest Guest ' . $ref, 'dectest.' . strtolower($ref) . '@example.com', $in, date('Y-m-d', strtotime($in . ' +' . $nights . ' days')), $nights,
            $gross, $gross, $status, $o['req'] ?? null, isset($o['dep']) ? 1 : 0, $o['dep'] ?? null, $o['created'] ?? $made]);
        $id = (int)$pdo->lastInsertId();
        $fx[$ref] = ['id' => $id, 'ref' => 'DECTEST-' . $ref, 'type' => $type, 'room' => $room, 'in' => $in, 'nights' => $nights, 'gross' => $gross, 'status' => $status, 'paid' => 0.0, 'dep' => $o['dep'] ?? null, 'factor' => $o['factor'] ?? 1];
        return $id;
    };
    $pay = $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, total_amount, payment_method, payment_type, payment_status)
                          VALUES (?, 'room', ?, ?, ?, ?, ?, 'cash', ?, 'completed')");
    $mkPay = static function (string $ref, float $amt) use ($pay, &$fx, $T): void {
        $pay->execute(['DECTEST-P' . $ref, $fx[$ref]['id'], $fx[$ref]['ref'], $T, $amt, $amt, $amt + BALANCE_TOLERANCE >= $fx[$ref]['gross'] ? 'full_payment' : 'deposit']);
        $fx[$ref]['paid'] += $amt;
    };
    $tl = $pdo->prepare("INSERT INTO booking_timeline_logs (booking_id, booking_reference, action, action_type, old_value, new_value, performed_by_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $R1 = $roomsByType[$t1];
    $u1 = count($R1);
    $O = $others;

    // Last year: 3 room-nights on the same nights, booked long ago
    $mk('LY1', $t1, $R1[0], date('Y-m-d', strtotime($T . ' -1 year +5 days')), 3, 300000, 'checked-out', ['created' => $D(-400) . ' 10:00:00']);
    // The base period: 600,000.00 of room revenue in the smallest room type (today's arrival adds 120,000.00), a no-show and a cancellation
    $mk('P1', $t1, $R1[0], $D(-20), 4, 400000, 'checked-out');
    $mk('P2', $t1, $R1[1], $D(-15), 2, 200000, 'checked-out');
    $mk('NS1', $O[0]['type'], $O[0]['id'], $D(-10), 1, 70000, 'no-show');
    $mk('C1', $O[1]['type'], $O[1]['id'], $D(-8), 2, 160000, 'cancelled');
    $tl->execute([$fx['C1']['id'], $fx['C1']['ref'], 'Cancelled', 'status_change', 'confirmed', 'cancelled', 'admin', $D(-9) . ' 09:00:00']);
    // A high-demand night: every room of the smallest type sold out, plus enough others for half the hotel; one paid
    $N1 = $D(10);
    foreach ($R1 as $i => $rid) { $mk('N1R' . $i, $t1, $rid, $N1, 1, 100000, 'confirmed'); }
    for ($i = 0; $i < $k; $i++) { $mk('N1O' . $i, $O[$i]['type'], $O[$i]['id'], $N1, 1, 100000, 'confirmed'); }
    $mkPay('N1R1', 100000);
    // Short gaps: 1 night between two stays (listed), 3 nights (too long to list)
    $G1 = $O[$k]; $G2 = $O[$k + 1];
    $mk('G1A', $G1['type'], $G1['id'], $D(30), 2, 100000, 'confirmed');
    $mk('G1B', $G1['type'], $G1['id'], $D(33), 2, 100000, 'confirmed');
    $mk('G2A', $G2['type'], $G2['id'], $D(40), 2, 100000, 'confirmed');
    $mk('G2B', $G2['type'], $G2['id'], $D(45), 2, 100000, 'confirmed');
    // Joined rooms: a pair of two rooms of different types sold together as one stay (both rooms held), then a stay after it
    $Ja = $O[$k + 2]; $Jb = $R1[0];
    $pdo->prepare("INSERT INTO room_combinations (combined_name, combined_room_type_id, room_a_id, room_b_id, is_active) VALUES ('DECTEST pair', ?, ?, ?, 1)")->execute([$Ja['type'], $Ja['id'], $Jb]);
    $combo = (int)$pdo->lastInsertId();
    $mk('J', $Ja['type'], $Ja['id'], $D(50), 2, 200000, 'confirmed', ['combo' => $combo, 'factor' => 2]);
    $br = $pdo->prepare("INSERT INTO booking_rooms (booking_id, individual_room_id, room_combination_id, is_primary, assigned_at) VALUES (?, ?, ?, ?, NOW())");
    $br->execute([$fx['J']['id'], $Ja['id'], $combo, 1]);
    $br->execute([$fx['J']['id'], $Jb, $combo, 0]);
    $mk('K', $t1, $Jb, $D(53), 2, 100000, 'confirmed');
    // A deposit asked for and only partly paid; arrivals: no room + a request, paid in full, money owed
    $mk('D1', $O[$k + 3]['type'], $O[$k + 3]['id'], $D(20), 1, 150000, 'confirmed', ['dep' => 50000]);
    $mkPay('D1', 20000);
    $mk('A1', $O[0]['type'], null, $T, 1, 120000, 'confirmed', ['req' => 'DECTEST quiet room near the lift please']);
    $mk('A2', $O[$k + 3]['type'], $O[$k + 3]['id'], $D(1), 1, 80000, 'confirmed');
    $mkPay('A2', 80000);
    $mk('A4', $O[1]['type'], null, $D(1), 1, 60000, 'confirmed'); // no room yet, but paid in full: counted, adds no money
    $mkPay('A4', 60000);
    $mk('A3', $O[$k + 4]['type'], $O[$k + 4]['id'], $D(2), 1, 90000, 'confirmed');
    foreach (['P1', 'P2', 'C1'] as $ref) { $tl->execute([$fx[$ref]['id'], $fx[$ref]['ref'], 'Booking created', 'create', null, null, 'guest', $made]); }

    $ctx = rh_dec_context_new($pdo, $T);
    // A valid joined booking is ONE stay: no clash anywhere, and its second room is held (the gap after it is real)
    $cf0 = rh_dec_f_bk_conflicts($pdo, $ctx);
    check(!empty($cf0['ok']) && $cf0['cards'] === [], 'joined rooms: a valid combination booked together (both rooms held by one booking) is never a clash');
    // Now a second booking on the pair's second room on one of its nights: a real clash
    $mk('X', $t1, $Jb, $D(51), 1, 100000, 'confirmed', ['created' => $D(-1) . ' 10:00:00']);

    $GLOBALS['__rh_dec_ctx'] = $ctx = rh_dec_context_new($pdo, $T);
    $b = rh_dec_build($pdo, 'bookings', $ctx);
    $cards = [];
    foreach (array_merge($b['main'], $b['more']) as $c) { $cards[$c['key']] = $c; }
    check(!array_filter($b['checks'], static fn($kk) => empty($kk['ok']) || !empty($kk['partial'])), 'with the fixture rows every bookings check runs in full', json_encode(array_column($b['checks'], 'ok', 'id')));
    check(count($cards) === 8, 'every bookings check finds its fixture decision', implode(',', array_keys($cards)));
    $sys = rh_dec_system_user($pdo);
    $bp = rh_ai_t_booking_pace($pdo, $sys, ['from' => $T, 'to' => $end]);
    $rp = rh_ai_t_room_performance($pdo, $sys, ['from' => (string)$ctx['from'], 'to' => (string)$ctx['to']]);
    $sell = (int)$bp['sellable_rooms'];
    $fwd = 0;
    foreach ($fx as $f) {
        if (in_array($f['status'], ['confirmed', 'checked-in', 'checked-out'], true) && $f['in'] <= $end && date('Y-m-d', strtotime($f['in'] . ' +' . $f['nights'] . ' days')) > $T) {
            $fwd += $f['nights'] * $f['factor'];
        }
    }
    $nt = rh_dec_bk_nights($pdo, $T, $end);
    check((int)$bp['room_nights_on_the_books'] === $fwd && (int)$nt['total_nights'] === $fwd, 'room-nights ahead: per night and room type they add up to booking_pace (the Reports basis), the joined pair counted as its two rooms (' . $fwd . ')', json_encode([$bp['room_nights_on_the_books'], $nt['total_nights'], $fwd]));
    $ntB = rh_dec_bk_nights($pdo, (string)$ctx['from'], (string)$ctx['to']);
    check(near((float)$ntB['total_rev'], (float)$rp['room_revenue_incl_vat']) && (int)$ntB['total_nights'] === (int)$rp['room_nights_sold'] && near((float)$rp['room_revenue_incl_vat'], 720000.0),
        'base period: room revenue and room-nights by room type add up to room_performance (Reports): 720,000.00 over 7 room-nights (today\'s arrival included)', json_encode([$ntB['total_rev'], $rp['room_revenue_incl_vat'], $ntB['total_nights'], $rp['room_nights_sold']]));
    $adr = (float)$rp['adr_incl_vat'];

    // Pace and pickup
    $pc = $cards['bk_pace'] ?? null;
    $ev = static fn(?array $c, string $label) => array_values(array_filter((array)($c['evidence']['rows'] ?? []), static fn($r) => strpos((string)$r['label'], $label) === 0))[0] ?? null;
    check($pc !== null && near((float)$pc['at_stake'], round((int)$bp['unsold_room_nights'] * $adr, 2)) && (string)($ev($pc, 'On the books')['value'] ?? '') === (int)$bp['room_nights_on_the_books'] . ' room-nights'
        && (string)($ev($pc, 'Same nights last year')['value'] ?? '') === '3 room-nights', 'pace: unsold nights x the average rate, on-the-books and last year (3 room-nights) are booking_pace\'s own figures', json_encode([$pc['at_stake'] ?? null, $bp['unsold_room_nights'], $adr]));
    $blocks = array_values(array_filter((array)($pc['evidence']['rows'] ?? []), static fn($r) => strpos((string)$r['label'], 'Nights ') === 0));
    $sumBlocks = 0;
    foreach ($blocks as $r) { preg_match('/: (\d+) room-nights on the books/', (string)$r['label'], $mm); $sumBlocks += (int)($mm[1] ?? -9999); }
    check(count($blocks) === (int)ceil($ahead / 30) && $sumBlocks === (int)$bp['room_nights_on_the_books'], 'pace: the 30-night blocks (where pickup lags last year) add up to the whole period', json_encode([count($blocks), $sumBlocks]));
    check($pc !== null && !empty($pc['options'][1]['not_valued']) && (int)$pc['options'][1]['tier'] === 3 && stripos((string)$pc['options'][1]['label'], 'rates') !== false, 'pace: a rate or offer change is "Not valued" and a manager\'s (tier 3)');

    // High-demand nights
    $dm = $cards['bk_demand'] ?? null;
    $free = $sell - ($u1 + $k);
    $n1Row = $ev($dm, $N1 . ':');
    $t1Name = (string)$pdo->query("SELECT name FROM rooms WHERE id = " . (int)$t1)->fetchColumn();
    check($dm !== null && near((float)$dm['at_stake'], round($free * $adr, 2)) && $n1Row !== null && strpos((string)$n1Row['label'], ($u1 + $k) . ' of ' . $sell . ' rooms booked') !== false
        && strpos((string)$n1Row['label'], 'sold out: ' . $t1Name) !== false && (string)$n1Row['value'] === $free . ' room(s) left',
        'demand: the busy night (' . ($u1 + $k) . ' of ' . $sell . ' booked, ' . $t1Name . ' sold out) and the ' . $free . ' rooms left on it at the average rate', json_encode([$dm['at_stake'] ?? null, $n1Row]));
    check($dm !== null && !array_filter((array)$dm['options'], static fn($o) => empty($o['not_valued'])) && (int)$dm['options'][0]['tier'] === 3 && stripos((string)$dm['at_stake_note'], 'not a gain') !== false,
        'demand: raising rates and keeping them are both "Not valued"; a rate change is tier 3; the money is what a rate change applies to, not a gain');
    $typeRows = array_values(array_filter((array)($dm['evidence']['rows'] ?? []), static fn($r) => strpos((string)$r['label'], ': share of its rooms booked') !== false));
    check(count($typeRows) === count(array_filter($byType)), 'demand: every room type with rooms shows its share booked ahead');

    // Room types that earn and lag
    $rt = $cards['bk_room_types'] ?? null;
    // Worked out here from the fixture rows: each type's revenue on the nights inside the base period
    $typeRev = [];
    $bTo = date('Y-m-d', strtotime((string)$ctx['to'] . ' +1 day'));
    foreach ($fx as $f) {
        $fOut = date('Y-m-d', strtotime($f['in'] . ' +' . $f['nights'] . ' days'));
        $n = rh_dec_bk_diff(max($f['in'], (string)$ctx['from']), min($fOut, $bTo));
        if ($n > 0 && in_array($f['status'], ['confirmed', 'checked-in', 'checked-out'], true)) {
            $typeRev[$f['type']] = ($typeRev[$f['type']] ?? 0.0) + $f['gross'] * $n / $f['nights'];
        }
    }
    $hotelPar = array_sum($typeRev) / ($sell * $baseDays);
    $gapExp = 0.0;
    foreach ($byType as $t => $u) {
        $par = (int)$u > 0 ? ($typeRev[$t] ?? 0.0) / ((int)$u * $baseDays) : null;
        if ($par !== null && $par < $hotelPar * (1 - rh_dec_num('ai_review_drag_pct', 1, 100) / 100)) {
            $gapExp += ($hotelPar - $par) * (int)$u * $baseDays;
        }
    }
    $gapExp = round($gapExp, 2);
    $hotelRow = $ev($rt, 'The whole hotel');
    check($rt !== null && abs((float)$rt['at_stake'] - $gapExp) < 0.05 && $hotelRow !== null && near((float)$hotelRow['amount'], (float)$rp['room_revenue_incl_vat']),
        'room types: the lagging types\' gap to the hotel\'s average per available room-night (' . number_format($gapExp, 2) . '); the hotel row is the Reports figure', json_encode([$rt['at_stake'] ?? null, $gapExp]));
    check($rt !== null && !array_filter((array)$rt['options'], static fn($o) => empty($o['not_valued'])) && stripos((string)$rt['at_stake_note'], 'not money lost') !== false && strpos((string)$rt['why_now'], $t1Name . ' earned the most') !== false,
        'room types: offering first and reviewing the rate are "Not valued"; the gap is not called a loss; the earning type is named');

    // Cancellations, no-shows and patterns
    $cx = $cards['bk_cancellations'] ?? null;
    $pn = count(array_filter($fx, static fn($f) => $f['ref'] !== 'DECTEST-LY1' && in_array($f['status'], ['confirmed', 'checked-in', 'checked-out', 'cancelled', 'no-show'], true)));
    $allRow = $ev($cx, 'All bookings made in the last');
    check($cx !== null && near((float)$cx['at_stake'], 70000.0) && (string)($ev($cx, 'Cancellations')['value'] ?? '') === '1' && $allRow !== null && strpos((string)$allRow['value'], '2 of ' . $pn . ' ') === 0,
        'cancellations: the no-show is valued at its booked 70,000.00, the cancellation counted, and the pattern over all bookings made (2 of ' . $pn . ' fell through)', json_encode([$cx['at_stake'] ?? null, $allRow]));
    $webRow = $ev($cx, 'Booked through Website');
    check($webRow !== null && strpos((string)$webRow['value'], '1 of 3 ') === 0, 'cancellations: the pattern by where bookings came from (Website: 1 of 3 fell through)', json_encode($webRow));
    check($cx !== null && !array_filter((array)$cx['options'], static fn($o) => empty($o['not_valued'])) && (int)$cx['options'][1]['tier'] === 3, 'cancellations: calling ahead and a deposit rule are "Not valued"; the rule change is tier 3');

    // Deposit exposure on upcoming stays (each booking's folio)
    $dp = $cards['bk_deposits'] ?? null;
    $expAmt = 0.0;
    $expN = 0;
    foreach ($fx as $f) {
        if (in_array($f['status'], ['pending', 'confirmed'], true) && $f['in'] >= $D($arrDays) && $f['in'] <= $end) {
            $bal = $f['gross'] - $f['paid'];
            $fs = getBookingFolioSummary($f['id']);
            if (!near((float)$fs['balance_due'], $bal)) { $expAmt = -1.0; break; }
            if ($bal > BALANCE_TOLERANCE && ($f['paid'] <= BALANCE_TOLERANCE || ($f['dep'] !== null && $f['paid'] + BALANCE_TOLERANCE < $f['dep']))) { $expAmt += $bal; $expN++; }
        }
    }
    check($dp !== null && near((float)$dp['at_stake'], $expAmt) && strpos((string)$dp['decision'], 'ask ' . $expN . ' upcoming') !== false, 'deposits: what is still unpaid on stays with nothing paid or an unpaid deposit equals the folio balances (' . number_format($expAmt, 2) . ', ' . $expN . ' stays; the paid one left out)', json_encode([$dp['at_stake'] ?? null, $expAmt]));
    $d1Row = array_values(array_filter((array)($dp['evidence']['rows'] ?? []), static fn($r) => ($r['reference'] ?? '') === 'DECTEST-D1'))[0] ?? null;
    check($d1Row !== null && near((float)$d1Row['amount'], 130000.0) && strpos((string)$d1Row['label'], 'deposit asked for and not paid') !== false, 'deposits: a deposit asked for and only partly paid is listed with what is still owed (130,000.00)');
    check($dp !== null && !array_filter((array)$dp['options'], static fn($o) => empty($o['not_valued']) || $o['best'] !== null || $o['expected'] !== null || $o['worst'] !== null)
        && stripos((string)$dp['at_stake_note'], 'not a saving') !== false, 'deposits: asking for a deposit brings money in sooner, it is not a saving: every option "Not valued" (no best, expected or worst)', json_encode($dp['options'] ?? null));
    $nsRow = $ev($dp, 'Stays due in over the last');
    $dpRows = array_column((array)($dp['evidence']['rows'] ?? []), 'value', 'label');
    $cxLabel = array_values(array_filter(array_keys($dpRows), static fn($l) => strpos($l, 'that were cancelled (refunded in full)') !== false))[0] ?? '';
    check($nsRow !== null && strpos((string)$nsRow['label'], 'did not show') !== false && strpos((string)$nsRow['value'], '1 of 4 ') === 0 && $cxLabel !== '' && strpos((string)$dpRows[$cxLabel], '1 of 4 ') === 0 && strpos((string)$dp['why_now'], '25.0% of the hotel') !== false,
        'deposits: no-shows (1 of 4, the only ones that keep money) and cancellations (1 of 4, refunded in full) are shown apart', json_encode($dpRows));
    // Below the history needed: forecasts are blank ("Not enough history"), never invented
    $short = $ctx;
    $short['history_min'] = 1000000;
    $dpS = rh_dec_f_bk_deposits($pdo, $short)['cards'][0] ?? null;
    $pcS = rh_dec_f_pace($pdo, $short)['cards'][0] ?? null;
    check($dpS !== null && $ev($dpS, 'Stays due in over the last') === null && strpos((string)$dpS['why_now'], 'did not show') === false && $pcS !== null && $pcS['options'][0]['expected'] === null && empty($pcS['options'][0]['not_valued']),
        'not enough history: no no-show share is shown and the expected pickup figure is blank ("Not enough history")');

    // Arrivals needing action
    $ar = $cards['bk_arrivals'] ?? null;
    $arRefs = array_column((array)($ar['evidence']['rows'] ?? []), 'reference');
    $a4Row = array_values(array_filter((array)($ar['evidence']['rows'] ?? []), static fn($r) => ($r['reference'] ?? '') === 'DECTEST-A4'))[0] ?? null;
    check($ar !== null && near((float)$ar['at_stake'], 210000.0) && (int)$ar['evidence']['total'] === 4 && $arRefs === ['DECTEST-A1', 'DECTEST-A4', 'DECTEST-A3'] && $a4Row !== null && $a4Row['amount'] === null,
        'arrivals: at stake is only what is still owed (120,000.00 + 90,000.00); the paid arrival with no room is listed but adds nothing; the paid one with a room is not listed', json_encode([$ar['at_stake'] ?? null, $arRefs, $a4Row]));
    $a1Row = array_values(array_filter((array)($ar['evidence']['rows'] ?? []), static fn($r) => ($r['reference'] ?? '') === 'DECTEST-A1'))[0] ?? null;
    check($a1Row !== null && strpos((string)$a1Row['label'], 'no room yet') !== false && strpos((string)$a1Row['label'], 'special request to read') !== false && stripos(json_encode($ar), 'quiet room') === false,
        'arrivals: a special request is flagged to read, its text appears nowhere in the card');
    check($ar !== null && (int)$ar['tier'] === 1 && !empty($ar['options'][1]['not_valued']), 'arrivals: work it now (tier 1); leaving it to check-in is "Not valued"');

    // Short gaps
    $gp = $cards['bk_gaps'] ?? null;
    $price = static fn(int $t): float => (float)$pdo->query("SELECT price_per_night FROM rooms WHERE id = " . $t)->fetchColumn();
    $gRows = array_column((array)($gp['evidence']['rows'] ?? []), 'label');
    $jbNo = (string)$pdo->query("SELECT room_number FROM individual_rooms WHERE id = " . (int)$Jb)->fetchColumn();
    $g1No = (string)$pdo->query("SELECT room_number FROM individual_rooms WHERE id = " . (int)$G1['id'])->fetchColumn();
    check($gp !== null && count($gRows) === 2 && near((float)$gp['at_stake'], $price($G1['type']) + $price($t1))
        && strpos($gRows[0], 'Room ' . $g1No . ' ') === 0 && strpos($gRows[0], '1 night(s) free from ' . $D(32)) !== false && strpos($gRows[1], 'Room ' . $jbNo . ' ') === 0 && strpos($gRows[1], 'free from ' . $D(52)) !== false,
        'gaps: the 1-night gaps are listed (one after the joined stay, because the pair\'s second room is held), the 3-night gap is not; valued at the listed nightly rate', json_encode([$gp['at_stake'] ?? null, $gRows]));
    check($gp !== null && !empty($gp['options'][1]['not_valued']) && (int)$gp['options'][1]['tier'] === 3 && $gp['options'][0]['expected'] === null, 'gaps: a short-stay rate is "Not valued" (tier 3); the extra-night offer has no expected figure without history');

    // Room clashes: only the second booking on the pair's room, never the pair itself
    $cf = $cards['bk_conflicts'] ?? null;
    $cfRows = (array)($cf['evidence']['rows'] ?? []);
    check($cf !== null && count($cfRows) === 1 && (string)$cfRows[0]['reference'] === 'DECTEST-X / DECTEST-J' && near((float)$cf['at_stake'], 100000.0) && strpos((string)$cfRows[0]['label'], 'Room ' . $jbNo . ':') === 0,
        'clashes: the booking made on the joined pair\'s second room is the one clash (the later booking, 100,000.00); the pair\'s own two rooms are not a clash', json_encode($cfRows));

    // Every bookings card: shape, plain wording, never surer than its figures, honest options
    foreach ($cards as $kk => $cd) {
        check($cd['decision'] !== '' && count($cd['options']) >= 2 && in_array((int)$cd['tier'], [1, 2, 3], true) && $cd['evidence']['total'] > 0 && $cd['at_stake_note'] !== '',
            "card $kk: decision, options, tier, a checked total and a note on what the money figure uses");
        $txt = $cd['decision'] . ' ' . $cd['why_now'] . ' ' . $cd['recommendation'] . ' ' . implode(' ', array_column($cd['options'], 'label'));
        check(!preg_match('/\b(AI|smart|insights?|assistant|generated|powered|model|MRA)\b/i', $txt) && stripos($txt, 'dectest') === false, "card $kk: plain wording, no guest name or booking reference in the worded text");
        check(!array_filter($cd['options'], static fn($o) => (float)$o['confidence'] > (float)$cd['confidence'] + 0.0001), "card $kk: no option's likelihood is above the card's confidence");
        check(!array_filter($cd['options'], static fn($o) => preg_match('/\b(rates?|price|minimum stay|rule)\b/i', (string)$o['label']) && strpos((string)$o['label'], 'Keep rates') !== 0 && (empty($o['not_valued']) || (int)$o['tier'] !== 3)),
            "card $kk: every rate, price or rule change is \"Not valued\" and tier 3");
        check(!array_filter($cd['options'], static fn($o) => preg_match('/^(Leave|Wait|Collect at check-in)/', (string)$o['label']) && empty($o['not_valued'])), "card $kk: waiting or leaving it is never valued as money saved");
    }
    check(rh_dec_proof_log($pdo, $cards['bk_demand'] ?? [], 'ran', $adm['id']) === 0, 'a tier 3 card (rates) can never be logged as run by itself');

    // A front-desk account (Bookings only) sees the arrival, gap and clash cards, never revenue or folio balances ahead
    $desk = $mkUser($pdo, 'desk', ['dashboard', 'bookings', 'ai_assistant_use']);
    $du = ['id' => $desk, 'role' => 'receptionist'];
    $seen = array_keys(array_filter($fr, static fn($def) => rh_dec_requires_ok($du, $def['requires'])));
    check($seen === ['bk_arrivals', 'bk_gaps', 'bk_conflicts'] && count(array_filter($fr, static fn($def) => rh_dec_requires_ok($adm, $def['requires']))) === 8,
        'permission per card: Bookings only = arrivals, gaps and clashes; an admin sees all eight', implode(',', $seen));

    // The panel after one fake wording call
    $GLOBALS['dec_calls'] = [];
    [$hp, $pp] = $render();
    check($pp === '' && strpos($hp, 'data-dec-pending') !== false && trim(strip_tags($hp)) === '' && !$GLOBALS['dec_calls'], 'first page load: only a hidden, empty hook (no model call while the page loads)');
    $r = rh_dec_refresh($pdo, 'bookings', true, $adm['id'], false);
    $body = (string)($GLOBALS['dec_calls'][0]['body'] ?? '');
    check($r['status'] === 'built' && $r['model_called'] && count($GLOBALS['dec_calls']) === 1, 'the first ask words the bookings cards with one call');
    check($body !== '' && stripos($body, 'dectest') === false && stripos($body, 'example.com') === false && stripos($body, 'quiet room') === false && strpos($body, $jbNo) === false,
        'no personal data leaves: no guest name, email, booking reference, room number or request text in the call');
    [$html, $printed] = $render();
    if ((string)getenv('RH_DEC_DUMP') !== '') {
        file_put_contents((string)getenv('RH_DEC_DUMP'), $html); // for the headless look check only (fixture figures)
    }
    check($html !== '' && $printed === '' && strpos($html, '>Decisions</h2>') !== false && strpos($html, 'rh-dec-tab') !== false && strpos($html, 'id="rhDecDrawer-bookings"') !== false,
        'a good review: the bookings panel is returned (printed only by the page\'s echo), closed behind its tab');
    foreach ($ids as $kk) {
        check(strpos($html, 'data-key="' . $kk . '"') !== false || strpos($html, $cards[$kk]['decision'] ?? "\0") !== false, "panel shows $kk (as a card, or under More)");
    }
    $ths = substr_count($html, '<th scope="col" class="no-sort">');
    check($ths > 0 && $ths === preg_match_all('/<th scope="col" class="no-sort">[^<]+<span class="rh-dec__help">/', $html), 'every column header in the panel has a plain helper line');
    check(preg_match_all('/<dt>[^<]+<\/dt><dd[^>]*>[^<]*<\/dd><dd class="rh-dec__help">/', $html) === substr_count($html, '<dt>'), 'every figure on every card has a plain helper line');
    check(strpos($html, '>Not valued<') !== false && strpos($html, '>Likelihood<') !== false, 'the panel shows "Not valued" where an option is not money, and Likelihood for options');
    check(!preg_match('/\b(AI|smart|insights?|assistant|generated|powered by|thinking)\b/i', strip_tags($html)) && !preg_match('/fa-(magic|wand|robot|brain|star|microchip|hat-wizard)|sparkle|gradient|\bMRA\b/i', $html), 'the panel has no AI-looking words or icons and no MRA wording');
    check(strpos($html, 'Dectest Guest A1') !== false && stripos($html, 'quiet room') === false, 'guest names are shown on the hotel\'s own screen (they never left it); the request text is not shown');
    $dv = rh_dec_for_viewer($pdo, $du, 'bookings');
    $dk = $dv !== null ? array_merge(array_column($dv['main'], 'key'), array_column($dv['more'], 'key')) : [];
    check($dv !== null && !array_diff($dk, ['bk_arrivals', 'bk_gaps', 'bk_conflicts']) && $dk !== [], 'the front-desk account\'s panel holds only its own cards', implode(',', $dk));
    $GLOBALS['__rh_dec_models'] = ['provider' => ''];
    [$h2, $p2] = $render();
    check($h2 === '' && $p2 === '', 'no model set up: the bookings panel disappears (empty string, no templated cards, no hook)');
} finally {
    $pdo->rollBack();
    $GLOBALS['_SITE_SETTINGS'] = $settingsKeep;
    rh_ai_settings($pdo, true);
    unset($GLOBALS['__rh_dec_models'], $GLOBALS['__rh_dec_ctx']);
    rh_ai_set_transport(null);
    putenv('RH_AI_FAKE_PROVIDER');
}
}
$cnt = static fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
check($cnt("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE 'DECTEST-%'") === 0 && $cnt("SELECT COUNT(*) FROM payments WHERE payment_reference LIKE 'DECTEST-%'") === 0
    && $cnt("SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_reference LIKE 'DECTEST-%'") === 0 && $cnt("SELECT COUNT(*) FROM room_combinations WHERE combined_name LIKE 'DECTEST%'") === 0
    && $cnt("SELECT COUNT(*) FROM admin_users WHERE username LIKE 'dectest\\_%'") === 0 && $cnt("SELECT COUNT(*) FROM ai_usage_daily WHERE model_id = 'test-fast-model'") === 0
    && $liveRows() === $rowsBefore, 'everything the checks wrote was rolled back (no fixture, cache or proof row left)');

echo "\n$pass passed, $fail failed, $skip skipped\n";
exit($fail > 0 ? 1 : 0);
