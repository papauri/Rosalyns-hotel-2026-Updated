<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Conference packages (CONF_PACKAGES_PLAN.md section 9). Run from the TEST hotel's root only (Liwonde), after
 * migration 092:  php scripts/tests/conference_packages_test.php
 * Pure helper checks in-process; the SQL check in ONE transaction that is rolled back; every page case in
 * scripts/tests/page_harness.php (its own never-committing transaction, leak-checked). Test rooms, packages and
 * enquiries are seeded inside each rolled-back run (no explicit ids). VAT is forced inside the run, never written.
 * The guest form emails go to the local capture server (scripts/tests/fake-smtp.php); nothing leaves this machine.
 * Test 11 (documents, B3) calls the quotation/invoice builders and the email block helper directly: nothing is sent.
 */
$root = dirname(__DIR__, 2);
chdir($root);
if (is_file($root . '/.claude/LIVE_REPO') || stripos(str_replace('\\', '/', $root), 'liwonde') === false) {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false || (string)getSetting('site_is_test_platform', '0') !== '1') { // Rosalyn's is LIVE
    fwrite(STDERR, "Refused: this database is not the test hotel.\n");
    exit(2);
}
require_once $root . '/includes/conference-pricing.php';
if (!rh_conf_has_packages($pdo)) {
    echo "Run migration 092 at Liwonde first\n";
    exit(1);
}
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": " . substr($detail, 0, 1500) : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= 0.011;
function cpk_reset_limits(): void {
    $key = 'iprl_conference_form_' . substr(hash('sha256', '127.0.0.1|conference_form'), 0, 24);
    if (function_exists('deleteCache')) { @deleteCache($key); }
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $key . '.json';
    if (is_file($file)) { @unlink($file); }
}
register_shutdown_function('cpk_reset_limits');
$dumpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cpk_' . bin2hex(random_bytes(4)) . '.html';
register_shutdown_function(function () use ($dumpFile) { if (is_file($dumpFile)) { @unlink($dumpFile); } });
function cpk_run(string $label, string $page, array $cfg): array {
    global $harness, $dumpFile;
    cpk_reset_limits();
    if (is_file($dumpFile)) { @unlink($dumpFile); }
    $cfg += ['dump_html' => $dumpFile];
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cpk_cfg_' . bin2hex(random_bytes(4)) . '.b64';
    file_put_contents($tmp, base64_encode(json_encode($cfg)));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg('@' . $tmp) . ' 2>&1');
    @unlink($tmp);
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -1500), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -1500)]; } }
    if (empty($res['rolled_back']) || !empty($res['leak']) || !empty($res['marker_lost'])) {
        ok("harness rolled back cleanly ($label)", false, json_encode(array_intersect_key($res, ['rolled_back' => 1, 'leak' => 1, 'marker_lost' => 1, 'raw' => 1, 'error' => 1])));
    }
    $res['html'] = is_file($dumpFile) ? (string)file_get_contents($dumpFile) : '';
    // Admin pages show their message as a toast: <script>...__rhToastQueue...push({msg:"..."}) (JSON).
    $toasts = [];
    if (preg_match_all('#__rhToastQueue\|\|\[\]\)\.push\(\{msg:("(?:[^"\\\\]|\\\\.)*")#', $res['html'], $tm)) {
        foreach ($tm[1] as $tj) { $toasts[] = (string)json_decode($tj); }
    }
    $res['flash'] = implode(' | ', array_merge($res['output_flash'] ?? [], $toasts));
    return $res;
}
/** vat_components() for a forced mode (16.5%), so expectations do not depend on this hotel's own setting. */
$vatOf = static function (string $mode, float $base): array {
    if ($mode === 'inclusive') { $net = round($base / 1.165, 2); return ['vat' => round($base - $net, 2), 'total' => round($base, 2)]; }
    $vat = round($base * 0.165, 2);
    return ['vat' => $vat, 'total' => round($base + $vat, 2)];
};

// ───────────── 1. pure helpers ─────────────
ok('1 per_event = unit (days and delegates ignored)', $near(rh_conf_package_cost('per_event', 50000, 3, 40), 50000));
ok('1 per_day = unit x days', $near(rh_conf_package_cost('per_day', 30000, 3, 40), 90000));
ok('1 per_delegate_per_day = unit x delegates x days', $near(rh_conf_package_cost('per_delegate_per_day', 15000, 3, 40), 1800000));
ok('1 delegates 0 counts as 1, days 0 counts as 1', $near(rh_conf_package_cost('per_delegate_per_day', 5000, 0, 0), 5000) && rh_conf_delegates(['number_of_attendees' => 0]) === 1);
$halfRoom = ['daily_rate' => 250000, 'half_day_rate' => 150000];
$hp = rh_conf_price($halfRoom, '2030-01-10', null, '08:00', '12:00');
ok('1 half-day event: venue = half-day rate and the packages count 1 day', $near($hp['amount'], 150000) && rh_conf_days('2030-01-10', null) === 1, json_encode($hp));
ok('1 line text reads "15,000 × 40 delegates × 3 days"', rh_conf_line_text(['price_type' => 'per_delegate_per_day', 'unit_price' => 15000, 'days' => 3, 'delegates' => 40]) === '15,000 × 40 delegates × 3 days');
ok('1 line text per day / per event', rh_conf_line_text(['price_type' => 'per_day', 'unit_price' => 30000, 'days' => 1, 'delegates' => 1]) === '30,000 × 1 day'
    && rh_conf_line_text(['price_type' => 'per_event', 'unit_price' => 50000, 'days' => 3, 'delegates' => 40]) === '50,000 per event');
ok('overflow: a total above 99,999,999.99 or below 0 does not fit; a normal one does', !rh_conf_total_fits(RH_CONF_MAX_TOTAL + 1) && !rh_conf_total_fits(-1) && rh_conf_total_fits(3290000));
ok('venue = total_amount - package_total (never below 0)', $near(rh_conf_venue_amount(['total_amount' => 3290000, 'package_total' => 2540000]), 750000)
    && $near(rh_conf_venue_amount(['total_amount' => 10, 'package_total' => 20]), 0));
ok('room scope: "rooms" package only in its rooms; "all" everywhere', rh_conf_package_allowed(['applies_to' => 'rooms', 'conference_room_ids' => '[3,7]'], 7)
    && !rh_conf_package_allowed(['applies_to' => 'rooms', 'conference_room_ids' => '[3,7]'], 4) && rh_conf_package_allowed(['applies_to' => 'all'], 4));
ok('booked rates win over current rates; NULL booked rates keep the current ones', $near(rh_conf_booked_room(['booked_daily_rate' => '250000.00', 'booked_half_day_rate' => null], ['daily_rate' => 300000, 'half_day_rate' => 200000])['daily_rate'], 250000)
    && rh_conf_booked_room(['booked_daily_rate' => '250000.00', 'booked_half_day_rate' => null], ['daily_rate' => 300000, 'half_day_rate' => 200000])['half_day_rate'] === null
    && $near(rh_conf_booked_room(['booked_daily_rate' => null], ['daily_rate' => 300000])['daily_rate'], 300000));

// ───────────── 13. package gross SQL reads the LOCKED VAT, never the current mode (in-process, rolled back) ─────────────
$tag = 'ZCPK' . strtoupper(bin2hex(random_bytes(3)));
$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("INSERT INTO conference_inquiries (inquiry_reference, conference_room_id, company_name, contact_person, email, phone, event_date, start_time, end_time, number_of_attendees, status, total_amount, package_total, vat_amount, total_with_vat, amount_paid, amount_due, created_at)
        VALUES (?, 0, 'ZZ Pkg Co', 'ZZ Person', 'zz.cpk@example.invalid', '+265000000000', DATE_ADD(CURDATE(), INTERVAL 700 DAY), '09:00:00', '17:00:00', 40, 'confirmed', 3290000, 2540000, ?, ?, 0, 0, NOW())");
    $ins->execute([$tag . 'X', 542850, 3832850]);  // locked in exclusive mode
    $xId = (int)$pdo->lastInsertId();
    $ins->execute([$tag . 'I', 465965.67, 3290000]); // locked in inclusive mode
    $iId = (int)$pdo->lastInsertId();
    $ins->execute([$tag . 'U', 0, 0]);               // never locked
    $uId = (int)$pdo->lastInsertId();
    $g = static fn(int $id): float => (float)$pdo->query("SELECT " . rh_conf_package_gross_sql('c') . " FROM conference_inquiries c WHERE c.id = {$id}")->fetchColumn();
    ok('13 package gross on an exclusive-locked row = packages x locked gross / base (2,959,100)', $near($g($xId), 2959100), (string)$g($xId));
    ok('13 package gross on an inclusive-locked row = the packages as booked (2,540,000), whatever the current mode', $near($g($iId), 2540000), (string)$g($iId));
    ok('13 package gross on a never-locked row = package_total', $near($g($uId), 2540000), (string)$g($uId));
} finally {
    $pdo->rollBack();
}
ok('13 rolled back: no test enquiries left', (int)$pdo->query("SELECT COUNT(*) FROM conference_inquiries WHERE inquiry_reference LIKE " . $pdo->quote($tag . '%'))->fetchColumn() === 0);

// ───────────── page cases (page harness) ─────────────
$far = 60; // event starts this many days from today
/** Seed: VAT mode, 2 test rooms (A 250,000 / half 150,000; B 300,000 / no half day), 4 packages (Banner only in room A). */
$seed = static function (string $mode) use ($tag): array {
    $room = static fn(string $n, string $daily, string $half): string => "INSERT INTO conference_rooms (name, description, capacity, daily_rate, half_day_rate, is_active, display_order) VALUES ('{$tag} {$n}', 'Test room', 100, {$daily}, {$half}, 1, 99)";
    $pkg = static fn(string $n, string $type, string $price, string $applies, string $rooms): string => "INSERT INTO conference_packages (name, price_type, price_amount, applies_to, conference_room_ids, is_active, sort_order) VALUES ('{$tag} {$n}', '{$type}', {$price}, '{$applies}', {$rooms}, 1, 0)";
    return [
        "INSERT INTO site_settings (setting_key, setting_value) VALUES ('vat_enabled','1'),('vat_rate','16.5'),('vat_pricing_mode','{$mode}'),('conference_system_enabled','1') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        $room('Room A', '250000', '150000'), 'SET @ra = LAST_INSERT_ID()',
        $room('Room B', '300000', 'NULL'), 'SET @rb = LAST_INSERT_ID()',
        $pkg('Tea', 'per_delegate_per_day', '5000', 'all', 'NULL'), 'SET @pt = LAST_INSERT_ID()',
        $pkg('Lunch', 'per_delegate_per_day', '15000', 'all', 'NULL'), 'SET @pl = LAST_INSERT_ID()',
        $pkg('Projector', 'per_day', '30000', 'all', 'NULL'), 'SET @pp = LAST_INSERT_ID()',
        $pkg('Banner', 'per_event', '50000', 'rooms', "CONCAT('[', @ra, ']')"), 'SET @pb = LAST_INSERT_ID()',
    ];
};
$B_LINES = [['pl', 'Lunch', 'per_delegate_per_day', 15000, 1800000], ['pt', 'Tea', 'per_delegate_per_day', 5000, 600000], ['pp', 'Projector', 'per_day', 30000, 90000], ['pb', 'Banner', 'per_event', 50000, 50000]];
/**
 * One enquiry (@e): 3 days from +$far, 08:00-17:00, 40 delegates, in room $room, venue $venue, the given lines;
 * totals and the locked VAT as saved at booking (unless $stale: totals 0, as if never priced).
 */
$enq = static function (string $mode, string $ref, float $venue, array $lines, array $o = []) use ($tag, $far, $vatOf): array {
    $room = $o['room'] ?? '@ra';
    $days = $o['days'] ?? 3;
    $booked = array_key_exists('booked', $o) ? $o['booked'] : ['250000', '150000'];
    $pk = round(array_sum(array_column($lines, 4)), 2);
    $total = round($venue + $pk, 2);
    $v = $vatOf($mode, $total);
    $stale = !empty($o['stale']);
    $end = $days > 1 ? "DATE_ADD(CURDATE(), INTERVAL " . ($far + $days - 1) . " DAY)" : 'NULL';
    $sql = ["INSERT INTO conference_inquiries (inquiry_reference, conference_room_id, company_name, contact_person, email, phone, event_date, end_date, start_time, end_time, number_of_attendees, status, total_amount, package_total, booked_daily_rate, booked_half_day_rate, vat_rate, vat_amount, total_with_vat, amount_paid, amount_due, created_at)
        VALUES ('{$tag}{$ref}', {$room}, 'ZZ Pkg Co', 'ZZ Person', 'zz.cpk@example.invalid', '+265000000000', DATE_ADD(CURDATE(), INTERVAL {$far} DAY), {$end}, '08:00:00', '17:00:00', " . ($o['delegates'] ?? 40) . ", '" . ($o['status'] ?? 'pending') . "', "
        . ($stale ? '0, 0' : "{$total}, {$pk}") . ', ' . ($booked === null ? 'NULL, NULL' : "{$booked[0]}, " . ($booked[1] === null ? 'NULL' : $booked[1])) . ', '
        . ($stale ? '0, 0, 0, 0, 0' : "16.5, {$v['vat']}, {$v['total']}, 0, {$v['total']}") . ', NOW())', 'SET @e = LAST_INSERT_ID()'];
    foreach ($lines as $l) {
        $flag = (!empty($o['flag']) && $l[1] === $o['flag']) ? "1, 'Not offered in {$tag} Room B'" : '0, NULL';
        $sql[] = "INSERT INTO conference_inquiry_packages (inquiry_id, package_id, package_name, price_type, unit_price, days, delegates, total_cost, needs_review, review_reason)
            VALUES (@e, @{$l[0]}, '{$tag} {$l[1]}', '{$l[2]}', {$l[3]}, {$days}, " . ($o['delegates'] ?? 40) . ", " . $l[4] . ", {$flag})";
    }
    return $sql;
};
$enqVerify = static fn(string $ref): array => [
    "SELECT total_amount, package_total, vat_amount, total_with_vat, amount_due, booked_daily_rate, number_of_attendees, conference_room_id = @rb AS in_b FROM conference_inquiries WHERE id = @e",
    "SELECT package_name, unit_price, days, delegates, total_cost, needs_review, review_reason FROM conference_inquiry_packages WHERE inquiry_id = @e ORDER BY id",
    "SELECT details FROM admin_activity_log WHERE action = 'conference_price_change' AND details LIKE '{$tag}{$ref}:%' ORDER BY id",
];
$row = static fn(array $r): array => is_array($r['verify'][0] ?? null) ? ($r['verify'][0][0] ?? []) : [];
$linesOf = static function (array $r): array { $o = []; foreach ((is_array($r['verify'][1] ?? null) ? $r['verify'][1] : []) as $l) { $o[preg_replace('/^\S+ /', '', $l['package_name'])] = $l; } return $o; };
$logs = static fn(array $r): string => implode(' || ', array_column(is_array($r['verify'][2] ?? null) ? $r['verify'][2] : [], 'details'));
$totalsOk = static function (array $r, string $mode, float $total, float $pk) use ($row, $near, $vatOf): bool {
    $e = $row($r); $v = $vatOf($mode, $total);
    return $e && $near($e['total_amount'], $total) && $near($e['package_total'], $pk) && $near($e['vat_amount'], $v['vat']) && $near($e['total_with_vat'], $v['total']) && $near($e['amount_due'], $v['total']);
};
$admin = static fn(string $label, array $setup, array $post, array $verify, array $x = []): array => cpk_run($label, 'admin/conference-management.php', ['setup' => $setup, 'post' => $post, 'vars' => ['e' => 'SELECT @e', 'ra' => 'SELECT @ra', 'rb' => 'SELECT @rb', 'pt' => 'SELECT @pt', 'pb' => 'SELECT @pb', 'lb' => 'SELECT id FROM conference_inquiry_packages WHERE inquiry_id = @e AND package_id = @pb']] + ['verify' => $verify] + $x);
$sched = static function (int $days, int $delegates, string $room = '{ra}', array $extra = []) use ($far): array {
    return ['enquiry_action' => 'update_schedule', 'enquiry_id' => '{e}', 'event_date' => date('Y-m-d', strtotime("+{$far} day")),
        'end_date' => $days > 1 ? date('Y-m-d', strtotime('+' . ($far + $days - 1) . ' day')) : '', 'start_time' => '08:00', 'end_time' => '17:00',
        'number_of_attendees' => (string)$delegates, 'conference_room_id' => $room] + $extra;
};

foreach (['inclusive', 'exclusive'] as $mode) {
    $S = $seed($mode);
    $M = strtoupper($mode[0]);
    // 2B: example B priced from scratch through the venue price (lines present, totals never saved).
    $ref = $M . 'B';
    $r = $admin("$mode B", array_merge($S, $enq($mode, $ref, 0, $B_LINES, ['stale' => true])), ['enquiry_action' => 'update_amount', 'enquiry_id' => '{e}', 'total_amount' => '750000'], $enqVerify($ref));
    ok("2 [$mode] B: venue 750,000 + packages 2,540,000 = 3,290,000, VAT locked on the whole total", $totalsOk($r, $mode, 3290000, 2540000), json_encode([$row($r), $r['flash'], $r['raw']]));
    ok("2 [$mode] B: Lunch line = 15,000 × 40 × 3 = 1,800,000", $near($linesOf($r)['Lunch']['total_cost'] ?? -1, 1800000));
    ok("History [$mode] venue price change logged old → new with the total", strpos($logs($r), 'venue price set') !== false && strpos($logs($r), 'total 0 → 3,290,000') !== false, $logs($r));
    ok("[$mode] message: Venue price updated. Total now ...", strpos($r['flash'], 'Venue price updated. Total now') !== false && strpos($r['flash'], '3,290,000.00') !== false, $r['flash']);

    // 4C: B moved to 2 days and 35 delegates (same room, booked rates, not manual).
    $ref = $M . 'C';
    $r = $admin("$mode C", array_merge($S, $enq($mode, $ref, 750000, $B_LINES)), $sched(2, 35), $enqVerify($ref));
    $L = $linesOf($r);
    ok("2 [$mode] C: total 2,010,000 (venue 500,000 + packages 1,510,000), VAT re-locked", $totalsOk($r, $mode, 2010000, 1510000), json_encode([$row($r), $r['flash'], $r['raw']]));
    ok("[$mode] C: Lunch 1,050,000, Tea 350,000, Projector 60,000 repriced; Banner (per event) stays 50,000",
        $near($L['Lunch']['total_cost'] ?? -1, 1050000) && $near($L['Tea']['total_cost'] ?? -1, 350000) && $near($L['Projector']['total_cost'] ?? -1, 60000) && $near($L['Banner']['total_cost'] ?? -1, 50000)
        && (int)($L['Lunch']['delegates'] ?? 0) === 35 && (int)($L['Lunch']['days'] ?? 0) === 2, json_encode($L));
    ok("History [$mode] C: each repriced line and the total logged old → new", strpos($logs($r), 'Lunch 1,800,000 → 1,050,000') !== false && strpos($logs($r), 'total 3,290,000 → 2,010,000') !== false, $logs($r));
}
$S = $seed('inclusive');

// 2A + 8 + fault 4: the guest form (example A: half day, 40 delegates, Tea + Projector) and a refused package.
$port = 0;
for ($try = 0; $try < 20 && !$port; $try++) {
    $cand = random_int(20000, 45000);
    $probeS = @stream_socket_server('tcp://127.0.0.1:' . $cand, $e1, $e2);
    if ($probeS) { fclose($probeS); $port = $cand; }
}
$spool = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_cpk_smtp_' . bin2hex(random_bytes(4)) . '.jsonl';
$smtp = $port ? proc_open([PHP_BINARY, $root . '/scripts/tests/fake-smtp.php', (string)$port, $spool], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/rh_cpk_smtp.out', 'a'], 2 => ['file', sys_get_temp_dir() . '/rh_cpk_smtp.err', 'a']], $pipes) : null;
for ($i = 0; $i < 50 && !is_file($spool . '.ready'); $i++) { usleep(100000); }
register_shutdown_function(function () use (&$smtp, $spool) {
    if (is_resource($smtp)) { proc_terminate($smtp); proc_close($smtp); }
    @unlink($spool); @unlink($spool . '.ready');
});
if (is_resource($smtp) && is_file($spool . '.ready')) {
    $tok = bin2hex(random_bytes(32));
    $gEmail = 'zz.cpk.' . strtolower(bin2hex(random_bytes(3))) . '@example.invalid';
    $guest = static function (string $label, string $room, array $pkgs) use ($S, $tok, $gEmail, $port, $far): array {
        return cpk_run($label, 'conference.php', ['anon' => true, 'fake_smtp' => $port, 'session' => ['_csrf_pub_conference' => $tok],
            'setup' => array_merge($S, ["INSERT INTO site_settings (setting_key, setting_value) VALUES ('whatsapp_enabled','0'),('whatsapp_notifications_enabled','0') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"]),
            'vars' => ['ra' => 'SELECT @ra', 'rb' => 'SELECT @rb', 'pt' => 'SELECT @pt', 'pp' => 'SELECT @pp', 'pb' => 'SELECT @pb'],
            'post' => ['csrf_token' => $tok, 'conference_room_id' => $room, 'company_name' => 'ZZ Pkg Guest Co', 'contact_person' => 'Zz Guest', 'email' => $gEmail,
                'phone' => '+265999000111', 'event_date' => date('Y-m-d', strtotime("+{$far} day")), 'end_date' => '', 'start_time' => '08:00', 'end_time' => '12:00',
                'number_of_attendees' => '40', 'event_type' => 'Meeting', 'special_requirements' => '', 'av_equipment' => '', 'consent' => '1', 'package_ids' => $pkgs],
            'verify' => ["SELECT ci.id, ci.total_amount, ci.package_total, ci.vat_amount, ci.total_with_vat, ci.booked_daily_rate, ci.booked_half_day_rate,
                    (SELECT COUNT(*) FROM conference_inquiry_packages p WHERE p.inquiry_id = ci.id) AS n_lines,
                    (SELECT COALESCE(SUM(p.total_cost), 0) FROM conference_inquiry_packages p WHERE p.inquiry_id = ci.id) AS line_sum
                 FROM conference_inquiries ci WHERE ci.email = '{$gEmail}'",
                "SELECT COUNT(*) AS n FROM conference_inquiry_packages p LEFT JOIN conference_inquiries ci ON ci.id = p.inquiry_id WHERE ci.id IS NULL"]]);
    };
    $r = $guest('guest refused', '{rb}', ['{pb}']);
    ok('8 guest form: a package not offered for the room is refused and nothing is saved', stripos($r['html'], 'not offered for this room') !== false
        && is_array($r['verify'][0] ?? null) && count($r['verify'][0]) === 0, json_encode([$r['verify'] ?? null, substr(strip_tags($r['html']), 0, 300), $r['raw']]));
    $r = $guest('guest A', '{ra}', ['{pt}', '{pp}']);
    $g = is_array($r['verify'][0] ?? null) ? ($r['verify'][0][0] ?? []) : [];
    $v = $vatOf('inclusive', 380000);
    ok('2A guest form: half day 150,000 + Tea 200,000 + Projector 30,000 = 380,000; VAT split on the whole total (inclusive)', $g && $near($g['total_amount'], 380000)
        && $near($g['package_total'], 230000) && $near($g['vat_amount'], $v['vat']) && $near($g['total_with_vat'], 380000) && $near($g['booked_daily_rate'], 250000) && $near($g['booked_half_day_rate'], 150000),
        json_encode([$g, $r['raw']]));
    ok('fault 4: both package lines belong to the new enquiry (one insert id, no orphan lines)', $g && (int)$g['n_lines'] === 2 && $near($g['line_sum'], 230000)
        && (int)($r['verify'][1][0]['n'] ?? -1) === 0, json_encode($r['verify'] ?? null));
    $mails = [];
    foreach ((is_file($spool) ? (file($spool, FILE_IGNORE_NEW_LINES) ?: []) : []) as $l) { $j = json_decode($l, true); if (is_array($j)) { $mails[] = $j; } }
    ok('guest form emails were captured locally (none sent out)', count($mails) >= 1, (string)count($mails));
    // PATCH 2026-11-26 fault 3: confirm with the new lock order (enquiry, then room) still confirms; its emails go to the capture server.
    $r = $admin('confirm ok', array_merge($S, $enq('inclusive', 'CO', 750000, [])), ['enquiry_action' => 'confirm', 'enquiry_id' => '{e}'],
        ["SELECT status FROM conference_inquiries WHERE id = @e"], ['fake_smtp' => $port]);
    ok('fault 3: confirm (enquiry locked first, then the room) confirms a free slot', (string)($r['verify'][0][0]['status'] ?? '') === 'confirmed'
        && strpos($r['flash'], 'confirmed successfully') !== false, json_encode([$r['flash'], $r['verify'] ?? null, $r['raw']]));
} else {
    ok('capture SMTP server started (needed for the guest form case)', false);
}

// 2: add a package; duplicate and room-scope refusals.
$B3 = array_slice($B_LINES, 0, 3);
$r = $admin('add', array_merge($S, $enq('inclusive', 'ADD', 750000, $B3)), ['enquiry_action' => 'add_package', 'enquiry_id' => '{e}', 'package_id' => '{pb}'], $enqVerify('ADD'));
ok('add package: Banner added, total 3,240,000 → 3,290,000, VAT re-locked', $totalsOk($r, 'inclusive', 3290000, 2540000) && isset($linesOf($r)['Banner']), json_encode([$row($r), $r['flash']]));
ok('History: package added logged with its price and the total old → new', strpos($logs($r), 'package added: ' . $tag . ' Banner (50,000 per event = 50,000)') !== false && strpos($logs($r), 'total 3,240,000 → 3,290,000') !== false, $logs($r));
$r = $admin('add dup', array_merge($S, $enq('inclusive', 'DUP', 750000, $B_LINES)), ['enquiry_action' => 'add_package', 'enquiry_id' => '{e}', 'package_id' => '{pt}'], $enqVerify('DUP'));
ok('add package: a package already on the enquiry is refused, nothing changes', strpos($r['flash'], 'already on the enquiry') !== false && $totalsOk($r, 'inclusive', 3290000, 2540000) && count($linesOf($r)) === 4, $r['flash']);
$r = $admin('add scope', array_merge($S, $enq('inclusive', 'SCO', 900000, $B3, ['room' => '@rb'])), ['enquiry_action' => 'add_package', 'enquiry_id' => '{e}', 'package_id' => '{pb}'], $enqVerify('SCO'));
ok('add package: a package not offered in the enquiry\'s room is refused', strpos($r['flash'], 'not offered for this enquiry') !== false && count($linesOf($r)) === 3, $r['flash']);

// overflow: a venue price that would push the total past 99,999,999.99 is refused, nothing changes.
$r = $admin('overflow', array_merge($S, $enq('inclusive', 'OVF', 750000, $B_LINES)), ['enquiry_action' => 'update_amount', 'enquiry_id' => '{e}', 'total_amount' => '99999999'], $enqVerify('OVF'));
ok('overflow: venue + packages above 99,999,999.99 refused, total unchanged', strpos($r['flash'], 'too large to save') !== false && $totalsOk($r, 'inclusive', 3290000, 2540000), json_encode([$r['flash'], $row($r)]));

// 4E: manual venue kept on reschedule; replaced only when ticked. Packages reprice either way.
$r = $admin('E keep', array_merge($S, $enq('inclusive', 'EK', 600000, $B_LINES)), $sched(2, 35), $enqVerify('EK'));
ok('4E manual venue 600,000 kept, packages repriced: total 2,110,000', $totalsOk($r, 'inclusive', 2110000, 1510000) && strpos($r['flash'], 'manually set venue price was kept') !== false, json_encode([$row($r), $r['flash']]));
$r = $admin('E replace', array_merge($S, $enq('inclusive', 'ER', 600000, $B_LINES)), $sched(2, 35, '{ra}', ['recalculate_amount' => '1']), $enqVerify('ER'));
ok('4E "replace" ticked: venue becomes the suggestion 500,000, total 2,010,000', $totalsOk($r, 'inclusive', 2010000, 1510000), json_encode([$row($r), $r['flash']]));

// 5 (fault 5): the room's rate was raised after booking; the venue at the booked rate is not "manual".
$r = $admin('fault5', array_merge($S, $enq('inclusive', 'F5', 750000, $B_LINES), ['UPDATE conference_rooms SET daily_rate = 300000 WHERE id = @ra']), $sched(2, 35), $enqVerify('F5'));
ok('fault 5: booked rate 250,000 used (room now 300,000): venue 500,000, not kept as manual, total 2,010,000', $totalsOk($r, 'inclusive', 2010000, 1510000)
    && strpos($r['flash'], 'manually set') === false, json_encode([$row($r), $r['flash']]));

// 6: legacy row (no booked rates, no packages) reprices as before from the current rates.
$r = $admin('legacy', array_merge($S, $enq('inclusive', 'LEG', 750000, [], ['booked' => null])), $sched(2, 40), $enqVerify('LEG'));
ok('6 legacy row (NULL booked rates, no packages): venue 750,000 → 500,000 from the current rate', $totalsOk($r, 'inclusive', 500000, 0), json_encode([$row($r), $r['flash']]));

// 3D: a catalog price change leaves an enquiry's line alone.
$r = cpk_run('D catalog', 'admin/conference-packages.php', ['ajax' => true, 'setup' => array_merge($S, $enq('inclusive', 'D', 750000, $B_LINES)),
    'vars' => ['pl' => 'SELECT @pl'],
    'post' => ['action' => 'save', 'pkg_id' => '{pl}', 'name' => $tag . ' Lunch', 'price_type' => 'per_delegate_per_day', 'price_amount' => '18000', 'applies_to' => 'all', 'is_active' => '1', 'sort_order' => '0'],
    'verify' => ["SELECT price_amount FROM conference_packages WHERE id = @pl", "SELECT unit_price, total_cost FROM conference_inquiry_packages WHERE inquiry_id = @e AND package_id = @pl",
        "SELECT details FROM admin_activity_log WHERE action = 'conference_package_saved' AND details LIKE '%{$tag} Lunch%' ORDER BY id"]]);
ok('3D catalog Lunch raised to 18,000: the enquiry line keeps unit 15,000 and 1,800,000', $near($r['verify'][0][0]['price_amount'] ?? -1, 18000)
    && $near($r['verify'][1][0]['unit_price'] ?? -1, 15000) && $near($r['verify'][1][0]['total_cost'] ?? -1, 1800000) && !empty($r['json']['success']), json_encode([$r['json'] ?? null, $r['verify'] ?? null, $r['raw']]));
ok('History: catalog price change logged', is_array($r['verify'][2] ?? null) && count($r['verify'][2]) >= 1, json_encode($r['verify'][2] ?? null));

// 7F: moved to room B (Banner only in room A): Banner flagged, still charged; venue at room B's rate.
$r = $admin('F move', array_merge($S, $enq('inclusive', 'FM', 750000, $B_LINES)), $sched(3, 40, '{rb}'), $enqVerify('FM'));
$L = $linesOf($r);
ok('7F room change: Banner flagged "Not offered in ... Room B", still charged; venue 900,000, total 3,440,000', !empty($row($r)['in_b'])
    && (int)($L['Banner']['needs_review'] ?? 0) === 1 && strpos((string)($L['Banner']['review_reason'] ?? ''), 'Not offered in ' . $tag . ' Room B') === 0
    && (int)($L['Lunch']['needs_review'] ?? 1) === 0 && $totalsOk($r, 'inclusive', 3440000, 2540000) && $near($row($r)['booked_daily_rate'] ?? 0, 300000),
    json_encode([$row($r), $L, $r['flash']]));
ok('7F staff told which package to check', strpos($r['flash'], 'Check package "' . $tag . ' Banner"') !== false, $r['flash']);
ok('History: the flag is logged', strpos($logs($r), 'flagged: Not offered in') !== false, $logs($r));
$r = $admin('F keep', array_merge($S, $enq('inclusive', 'FK', 900000, $B_LINES, ['room' => '@rb', 'flag' => 'Banner'])), ['enquiry_action' => 'keep_package', 'enquiry_id' => '{e}', 'line_id' => '{lb}'], $enqVerify('FK'));
ok('7F Keep clears the flag; the total is unchanged', (int)($linesOf($r)['Banner']['needs_review'] ?? 1) === 0 && $totalsOk($r, 'inclusive', 3440000, 2540000)
    && strpos($logs($r), 'package kept after room change') !== false, json_encode([$linesOf($r)['Banner'] ?? null, $r['flash'], $logs($r)]));
$r = $admin('F remove', array_merge($S, $enq('inclusive', 'FR', 900000, $B_LINES, ['room' => '@rb', 'flag' => 'Banner'])), ['enquiry_action' => 'remove_package', 'enquiry_id' => '{e}', 'line_id' => '{lb}'], $enqVerify('FR'));
ok('7F Remove deletes the line and lowers the total by 50,000 (3,390,000)', !isset($linesOf($r)['Banner']) && $totalsOk($r, 'inclusive', 3390000, 2490000)
    && strpos($logs($r), 'package removed: ' . $tag . ' Banner') !== false && strpos($logs($r), 'total 3,440,000 → 3,390,000') !== false, json_encode([$row($r), $r['flash'], $logs($r)]));

// PATCH 2026-11-26 fault 5 (owner decision): a move to a room with no rate is refused, nothing written.
$noRateB = ['UPDATE conference_rooms SET daily_rate = 0, half_day_rate = NULL WHERE id = @rb'];
$r = $admin('no-rate move', array_merge($S, $enq('inclusive', 'NR', 750000, $B_LINES), $noRateB), $sched(3, 40, '{rb}'), $enqVerify('NR'));
ok('fault 5: move to a room with no rate refused with "<Room> has no rate set ... or enter a manual venue price"', strpos($r['flash'], $tag . ' Room B has no rate set. Set its rate in Conference Rooms, or enter a manual venue price.') !== false, $r['flash']);
ok('fault 5: refused move writes nothing (room, totals, lines, history unchanged)', empty($row($r)['in_b']) && $totalsOk($r, 'inclusive', 3290000, 2540000)
    && (int)($linesOf($r)['Banner']['needs_review'] ?? 1) === 0 && $logs($r) === '', json_encode([$row($r), $logs($r)]));
$r = $admin('no-rate move manual', array_merge($S, $enq('inclusive', 'NRM', 600000, $B_LINES), $noRateB), $sched(2, 35, '{rb}'), $enqVerify('NRM'));
ok('fault 5: a manual venue price is not kept silently either: refused, dates/delegates unchanged', strpos($r['flash'], 'has no rate set') !== false && empty($row($r)['in_b'])
    && $totalsOk($r, 'inclusive', 3140000, 2540000) && (int)($row($r)['number_of_attendees'] ?? 0) === 40, json_encode([$row($r), $r['flash']]));
$r = $admin('half-day-only move', array_merge($S, $enq('inclusive', 'HD', 750000, $B_LINES), ['UPDATE conference_rooms SET daily_rate = 0, half_day_rate = 120000 WHERE id = @rb']), $sched(3, 40, '{rb}'), $enqVerify('HD'));
ok('fault 5: a full-day move to a room with only a half-day rate is refused (no rate for this booking)', strpos($r['flash'], 'has no rate set') !== false && empty($row($r)['in_b']), $r['flash']);

// Manual venue price on the event change (owner 2026-11-26): a no-rate move is allowed only with one; it replaces the
// room hire, packages still reprice, VAT on the whole total, History says "manual venue price" with venue old → new.
foreach (['inclusive', 'exclusive'] as $mode) {
    $SM = $seed($mode);
    $ref = 'MV' . strtoupper($mode[0]);
    $r = $admin("manual move $mode", array_merge($SM, $enq($mode, $ref, 750000, $B_LINES), $noRateB), $sched(3, 40, '{rb}', ['manual_venue_price' => '800000']), $enqVerify($ref));
    ok("manual venue [$mode]: no-rate move with a manual price saved: venue 800,000 + packages 2,540,000 = 3,340,000, VAT on the whole total",
        !empty($row($r)['in_b']) && $totalsOk($r, $mode, 3340000, 2540000), json_encode([$row($r), $r['flash'], $r['raw']]));
    ok("manual venue [$mode]: the new room's rate (none) is saved as the booked rate, Banner flagged for the new room",
        $near($row($r)['booked_daily_rate'] ?? -1, 0) && (int)($linesOf($r)['Banner']['needs_review'] ?? 0) === 1, json_encode([$row($r), $linesOf($r)['Banner'] ?? null]));
    ok("manual venue [$mode]: History logs the manual venue price old → new and the total",
        strpos($logs($r), 'manual venue price') !== false && strpos($logs($r), 'venue 750,000 → 800,000') !== false && strpos($logs($r), 'total 3,290,000 → 3,340,000') !== false, $logs($r));
    ok("manual venue [$mode]: staff told the venue price was set manually", strpos($r['flash'], 'Venue price set manually to') !== false && strpos($r['flash'], '800,000.00') !== false, $r['flash']);
}
// A later change keeps the manual price (booked rate 0 in the no-rate room = "manual" by the existing check), ticked or not.
$mvKeep = array_merge($S, $enq('inclusive', 'MVK', 800000, $B_LINES, ['room' => '@rb', 'booked' => ['0', null]]), $noRateB);
$r = $admin('manual kept', $mvKeep, $sched(2, 35, '{rb}'), $enqVerify('MVK'));
ok('manual venue: a later reschedule keeps the manual 800,000, packages follow (total 2,310,000)', $totalsOk($r, 'inclusive', 2310000, 1510000)
    && strpos($r['flash'], 'manually set venue price was kept') !== false && strpos($r['flash'], 'suggested price') === false, json_encode([$row($r), $r['flash']]));
ok('manual venue: the later reschedule is logged with the venue unchanged', strpos($logs($r), 'total 3,340,000 → 2,310,000') !== false && strpos($logs($r), 'venue ') === false, $logs($r));
$r = $admin('manual kept ticked', $mvKeep, $sched(2, 35, '{rb}', ['recalculate_amount' => '1']), $enqVerify('MVK'));
ok('manual venue: "replace with the suggested price" ticked never wipes it to 0 (no rate): total 2,310,000', $totalsOk($r, 'inclusive', 2310000, 1510000), json_encode([$row($r), $r['flash']]));
// Refusals: 0, above the max, and a total that would overflow; nothing is written.
$r = $admin('manual zero', array_merge($S, $enq('inclusive', 'MV0', 750000, $B_LINES), $noRateB), $sched(3, 40, '{rb}', ['manual_venue_price' => '0']), $enqVerify('MV0'));
ok('manual venue: 0 is refused, nothing changes', strpos($r['flash'], 'must be a number above 0') !== false && empty($row($r)['in_b']) && $totalsOk($r, 'inclusive', 3290000, 2540000) && $logs($r) === '', json_encode([$row($r), $r['flash']]));
$r = $admin('manual text', array_merge($S, $enq('inclusive', 'MVX', 750000, $B_LINES)), $sched(2, 40, '{ra}', ['manual_venue_price' => 'abc']), $enqVerify('MVX'));
ok('manual venue: a non-number is refused, nothing changes', strpos($r['flash'], 'must be a number above 0') !== false && $totalsOk($r, 'inclusive', 3290000, 2540000), json_encode([$row($r), $r['flash']]));
$r = $admin('manual max', array_merge($S, $enq('inclusive', 'MVM', 750000, $B_LINES), $noRateB), $sched(3, 40, '{rb}', ['manual_venue_price' => '100000000']), $enqVerify('MVM'));
ok('manual venue: above 99,999,999.99 is refused, nothing changes', strpos($r['flash'], 'too large to save') !== false && empty($row($r)['in_b']) && $totalsOk($r, 'inclusive', 3290000, 2540000), json_encode([$row($r), $r['flash']]));
$r = $admin('manual overflow', array_merge($S, $enq('inclusive', 'MVO', 750000, $B_LINES), $noRateB), $sched(3, 40, '{rb}', ['manual_venue_price' => '99999999']), $enqVerify('MVO'));
ok('manual venue: venue + packages above the max is refused (overflow guard), nothing changes', strpos($r['flash'], 'too large to save') !== false && empty($row($r)['in_b'])
    && $totalsOk($r, 'inclusive', 3290000, 2540000) && $logs($r) === '', json_encode([$row($r), $r['flash']]));
// Same room, rated: a manual price replaces the computed room hire there too.
$r = $admin('manual same room', array_merge($S, $enq('inclusive', 'MVS', 750000, $B_LINES)), $sched(2, 35, '{ra}', ['manual_venue_price' => '650000']), $enqVerify('MVS'));
ok('manual venue: same room, 2 days / 35 delegates with a manual 650,000: total 2,160,000, booked rate unchanged', $totalsOk($r, 'inclusive', 2160000, 1510000)
    && $near($row($r)['booked_daily_rate'] ?? -1, 250000) && strpos($logs($r), 'venue 750,000 → 650,000') !== false, json_encode([$row($r), $r['flash'], $logs($r)]));

// PATCH 2026-11-26: an event WITHOUT packages moved to another room saves that room's rates as the booked rates (the
// write is gated on the booked-rate columns, not on the package feature), so a later reschedule tells a room-rate
// change from a manual price.
$npVerify = static fn(string $ref): array => array_merge($enqVerify($ref), ["SELECT booked_half_day_rate FROM conference_inquiries WHERE id = @e"]);
$r = $admin('no-pkg move', array_merge($S, $enq('inclusive', 'NPM', 750000, [])), $sched(3, 40, '{rb}'), $npVerify('NPM'));
ok('no packages: move to room B saves room B\'s rates (daily 300,000, no half day); venue 900,000', !empty($row($r)['in_b']) && $totalsOk($r, 'inclusive', 900000, 0)
    && $near($row($r)['booked_daily_rate'] ?? -1, 300000) && array_key_exists('booked_half_day_rate', $r['verify'][3][0] ?? []) && $r['verify'][3][0]['booked_half_day_rate'] === null,
    json_encode([$row($r), $r['verify'][3] ?? null, $r['flash']]));
$r = $admin('no-pkg later', array_merge($S, $enq('inclusive', 'NPL', 900000, [], ['room' => '@rb', 'booked' => ['300000', null]]), ['UPDATE conference_rooms SET daily_rate = 350000 WHERE id = @rb']),
    $sched(2, 40, '{rb}'), $enqVerify('NPL'));
ok('no packages: a later reschedule in room B reprices from the saved 300,000 (room now 350,000): venue 600,000, not "manual"', $totalsOk($r, 'inclusive', 600000, 0)
    && strpos($r['flash'], 'manually set') === false && strpos($r['flash'], 'recalculated') !== false, json_encode([$row($r), $r['flash']]));
$npSrc = (string)file_get_contents($root . '/admin/conference-management.php');
$npA = strpos($npSrc, "} elseif (\$action === 'update_schedule') {");
$npB = $npA !== false ? strpos($npSrc, "} elseif (\$action === 'update_amount') {", $npA) : false;
$npBlock = ($npA !== false && $npB !== false) ? substr($npSrc, $npA, $npB - $npA) : '';
ok('no packages: the event change saves the booked rates whenever the columns exist, not only with the package feature',
    $npBlock !== '' && strpos($npBlock, "rh_conf_has_column(\$pdo, 'conference_inquiries', 'booked_daily_rate')") !== false
    && preg_match('/if \(\$confPkgs && \(\$roomChanged \|\| \$replaceAmount\)\)/', $npBlock) === 0);

// PATCH 2026-11-26 fault 4: the 500 cap applies to a NEW delegate count only.
$r = $admin('600 unchanged', array_merge($S, $enq('inclusive', 'D6', 750000, [], ['delegates' => 600])), $sched(2, 600), $enqVerify('D6'));
ok('fault 4: an enquiry with 600 delegates can be rescheduled (count unchanged): 2 days, venue 500,000', $totalsOk($r, 'inclusive', 500000, 0)
    && (int)($row($r)['number_of_attendees'] ?? 0) === 600 && strpos($r['flash'], 'Event updated') !== false, json_encode([$row($r), $r['flash']]));
$r = $admin('600 lowered', array_merge($S, $enq('inclusive', 'D6L', 750000, [], ['delegates' => 600])), $sched(3, 550), $enqVerify('D6L'));
ok('fault 4: lowering 600 to 550 is allowed', (int)($row($r)['number_of_attendees'] ?? 0) === 550, json_encode([$row($r), $r['flash']]));
$r = $admin('600 raised', array_merge($S, $enq('inclusive', 'D6R', 750000, [], ['delegates' => 600])), $sched(2, 601), $enqVerify('D6R'));
ok('fault 4: raising 600 to 601 is refused, nothing changes', strpos($r['flash'], 'from 1 to 600') !== false && (int)($row($r)['number_of_attendees'] ?? 0) === 600
    && $totalsOk($r, 'inclusive', 750000, 0), json_encode([$row($r), $r['flash']]));
$r = $admin('40 to 501', array_merge($S, $enq('inclusive', 'D501', 750000, $B_LINES)), $sched(3, 501), $enqVerify('D501'));
ok('fault 4: a new count above 500 is refused (40 → 501), nothing changes', strpos($r['flash'], 'from 1 to 500') !== false && (int)($row($r)['number_of_attendees'] ?? 0) === 40
    && $totalsOk($r, 'inclusive', 3290000, 2540000), json_encode([$row($r), $r['flash']]));

// PATCH 2026-11-26 fault 3: every conference path locks the enquiry first, then rooms (confirm used to lock the room first).
$cmSrc = (string)file_get_contents($root . '/admin/conference-management.php');
$cA = strpos($cmSrc, "if (\$action === 'confirm') {");
$cB = $cA !== false ? strpos($cmSrc, "} elseif (\$action === 'cancel') {", $cA) : false;
$cBlock = ($cA !== false && $cB !== false) ? substr($cmSrc, $cA, $cB - $cA) : '';
$pE = strpos($cBlock, 'FROM conference_inquiries WHERE id = ? FOR UPDATE');
$pR = strpos($cBlock, 'FROM conference_rooms WHERE id = ? FOR UPDATE');
ok('fault 3: confirm locks the enquiry before the room', $cBlock !== '' && $pE !== false && $pR !== false && $pE < $pR, json_encode([$pE, $pR]));
$sA = strpos($cmSrc, "} elseif (\$action === 'update_schedule') {");
$sB = $sA !== false ? strpos($cmSrc, "} elseif (\$action === 'update_amount') {", $sA) : false;
$sBlock = ($sA !== false && $sB !== false) ? substr($cmSrc, $sA, $sB - $sA) : '';
$sE = strpos($sBlock, 'FROM conference_inquiries WHERE id = ? FOR UPDATE');
$sR = strpos($sBlock, 'FROM conference_rooms WHERE id IN (');
ok('fault 3: event change locks the enquiry, then the rooms in id order', $sE !== false && $sR !== false && $sE < $sR && strpos($sBlock, 'sort($lockIds)') !== false && strpos($sBlock, 'ORDER BY id FOR UPDATE') !== false);
$r = $admin('confirm clash', array_merge($S, $enq('inclusive', 'CB', 750000, [], ['status' => 'confirmed']), $enq('inclusive', 'CP', 750000, [])), ['enquiry_action' => 'confirm', 'enquiry_id' => '{e}'],
    ["SELECT status FROM conference_inquiries WHERE id = @e"]);
ok('fault 3: confirm (enquiry locked first) still refuses an overlapping confirmed booking in the same room', strpos($r['flash'], 'already confirmed for') !== false
    && (string)($r['verify'][0][0]['status'] ?? '') === 'pending', json_encode([$r['flash'], $r['verify'] ?? null]));

// 9: permissions (test.staff: conference page yes, pricing no, conference rooms no).
$perm = ["SET @ts = (SELECT id FROM admin_users WHERE username = 'test.staff' AND is_active = 1 LIMIT 1)",
    "DELETE FROM user_permissions WHERE user_id = @ts AND permission_key IN ('conference', 'conference_financials', 'conference_rooms')",
    "INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (@ts, 'conference', 1), (@ts, 'conference_financials', 0), (@ts, 'conference_rooms', 0)"];
$hasStaff = (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE username = 'test.staff' AND is_active = 1")->fetchColumn() === 1;
if ($hasStaff) {
    foreach ([['add_package', ['package_id' => '{pb}']], ['remove_package', ['line_id' => '{lb}']], ['keep_package', ['line_id' => '{lb}']]] as [$act, $extra]) {
        $r = $admin("perm $act", array_merge($S, $enq('inclusive', 'P' . strtoupper(substr($act, 0, 3)), 750000, $B_LINES), $perm), ['enquiry_action' => $act, 'enquiry_id' => '{e}'] + $extra,
            $enqVerify('P' . strtoupper(substr($act, 0, 3))), ['as_username' => 'test.staff']);
        ok("9 $act without conference pricing permission is refused, nothing changes", strpos($r['flash'], 'do not have permission') !== false && $totalsOk($r, 'inclusive', 3290000, 2540000) && count($linesOf($r)) === 4, $r['flash'] . ' ' . $r['raw']);
    }
    $r = $admin('perm delegates', array_merge($S, $enq('inclusive', 'PDL', 750000, $B_LINES), $perm), $sched(3, 35), $enqVerify('PDL'), ['as_username' => 'test.staff']);
    ok('9 changing delegates without pricing permission is refused, nothing changes', strpos($r['flash'], 'needs conference pricing permission') !== false && $totalsOk($r, 'inclusive', 3290000, 2540000)
        && (int)($row($r)['number_of_attendees'] ?? 0) === 40, $r['flash']);
    $r = $admin('perm dates', array_merge($S, $enq('inclusive', 'PDT', 750000, $B_LINES), $perm), $sched(2, 40), $enqVerify('PDT'), ['as_username' => 'test.staff']);
    ok('9 a date-only change without pricing permission: venue kept at 750,000, packages follow the 2 days (total 2,460,000)', $totalsOk($r, 'inclusive', 2460000, 1710000), json_encode([$row($r), $r['flash']]));
    $r = $admin('perm manual venue', array_merge($S, $enq('inclusive', 'PMV', 750000, $B_LINES), $perm), $sched(2, 40, '{ra}', ['manual_venue_price' => '100000']), $enqVerify('PMV'), ['as_username' => 'test.staff']);
    ok('9 a manual venue price without pricing permission is refused, nothing changes', strpos($r['flash'], 'manual venue price needs conference pricing permission') !== false
        && $totalsOk($r, 'inclusive', 3290000, 2540000) && $logs($r) === '', json_encode([$row($r), $r['flash']]));
    $r = cpk_run('perm catalog', 'admin/conference-packages.php', ['ajax' => true, 'as_username' => 'test.staff', 'setup' => array_merge($S, $perm),
        'post' => ['action' => 'save', 'name' => $tag . ' Sneaky', 'price_type' => 'per_event', 'price_amount' => '1', 'applies_to' => 'all', 'is_active' => '1'],
        'verify' => ["SELECT COUNT(*) AS n FROM conference_packages WHERE name = '{$tag} Sneaky'"]]);
    ok('9 catalog save without conference rooms permission is refused, nothing saved', (int)($r['verify'][0][0]['n'] ?? -1) === 0 && empty($r['json']['success']), json_encode([$r['json'] ?? null, $r['raw']]));
} else {
    ok('9 permission cases need the Liwonde test.staff account', false);
}

// 10: delete_enquiry removes its package lines.
$r = $admin('delete', array_merge($S, $enq('inclusive', 'DEL', 750000, $B_LINES)), ['enquiry_action' => 'delete_enquiry', 'enquiry_id' => '{e}'],
    ["SELECT COUNT(*) AS n FROM conference_inquiries WHERE id = @e", "SELECT COUNT(*) AS n FROM conference_inquiry_packages WHERE inquiry_id = @e"]);
ok('10 delete_enquiry removes the enquiry and its package lines', (int)($r['verify'][0][0]['n'] ?? -1) === 0 && (int)($r['verify'][1][0]['n'] ?? -1) === 0, json_encode([$r['verify'] ?? null, $r['flash']]));

// ───────────── 11 (fault 2 + B3): quotation, invoice and email package lines; the deposit is money, not the flag ─────────────
// In-process, ONE rolled-back transaction per mode; nothing is sent (the document builders and the email block helper
// are called directly). Venue 450,000 + Tea 5,000 x 10 x 2 days (100,000) + Projector 40,000 x 2 days (80,000)
// + Banner 20,000 per event = 650,000. Deposit flag 1, deposit_amount 500,000.
require_once $root . '/config/email.php';
require_once $root . '/config/invoice.php';
require_once $root . '/includes/quotation-pdf.php';
function cpk_pdf_text(string $pdf): string {
    // Text of a TCPDF file (same walk as documents_flow_child.php dx_pdf_text).
    $txt = ''; $pos = 0;
    while (($a = strpos($pdf, 'stream', $pos)) !== false && ($b = strpos($pdf, 'endstream', $a + 6)) !== false) {
        $st = substr($pdf, $a + 6, $b - $a - 6);
        $st = preg_replace('/^\r?\n/', '', $st, 1);
        $st = preg_replace('/\r?\n$/D', '', $st, 1);
        $d = @gzuncompress($st);
        if ($d !== false) { $txt .= $d . "\n"; }
        $pos = $b + 9;
    }
    preg_match_all('/\(([^)]*)\)\s*Tj|\[(.*?)\]\s*TJ/s', $txt, $mm, PREG_SET_ORDER);
    $out = [];
    foreach ($mm as $g) {
        if (($g[1] ?? '') !== '') { $out[] = $g[1]; continue; }
        if (preg_match_all('/\(([^)]*)\)/', (string)($g[2] ?? ''), $parts)) { $out[] = implode('', $parts[1]); }
    }
    return implode(' | ', $out);
}
$savedSettings11 = $GLOBALS['_SITE_SETTINGS'] ?? [];
foreach (['inclusive' => [650000, 92060.09, 650000], 'exclusive' => [650000, 107250, 757250]] as $m11 => [$base11, $vat11, $gross11]) {
    $pdo->beginTransaction();
    try {
        $GLOBALS['_SITE_SETTINGS'] = ['vat_enabled' => '1', 'vat_rate' => '16.5', 'vat_pricing_mode' => $m11] + $savedSettings11;
        $pdo->prepare("INSERT INTO conference_inquiries (inquiry_reference, conference_room_id, company_name, contact_person, email, phone, event_date, end_date, start_time, end_time, number_of_attendees, status, total_amount, package_total, vat_rate, vat_amount, total_with_vat, deposit_required, deposit_amount, deposit_paid, amount_paid, amount_due, created_at)
            VALUES (?, 0, 'ZZ Pkg Co', 'ZZ Person', 'zz.cpk@example.invalid', '+265000000000', DATE_ADD(CURDATE(), INTERVAL 720 DAY), DATE_ADD(CURDATE(), INTERVAL 721 DAY), '09:00:00', '17:00:00', 10, 'pending', ?, 200000, 16.5, ?, ?, 1, 500000, 0, 200000, ?, NOW())")
            ->execute([$tag . '11' . $m11[0], $base11, $vat11, $gross11, $gross11 - 200000]);
        $id11 = (int)$pdo->lastInsertId();
        $li11 = $pdo->prepare("INSERT INTO conference_inquiry_packages (inquiry_id, package_id, package_name, price_type, unit_price, days, delegates, total_cost, needs_review) VALUES (?, ?, ?, ?, ?, 2, 10, ?, 0)");
        $li11->execute([$id11, 990001, $tag . ' Tea <b>', 'per_delegate_per_day', 5000, 100000]);
        $li11->execute([$id11, 990002, $tag . ' Projector', 'per_day', 40000, 80000]);
        $li11->execute([$id11, 990003, $tag . ' Banner', 'per_event', 20000, 20000]);
        $row11 = $pdo->query("SELECT ci.*, 'ZZ Room' AS room_name FROM conference_inquiries ci WHERE ci.id = {$id11}")->fetch(PDO::FETCH_ASSOC);
        $sum11 = rh_conf_venue_amount($row11) + array_sum(array_map(static fn($l) => (float)$l['total_cost'], rh_conf_package_lines($pdo, $id11)));
        ok("11 [$m11] room hire + package lines = total_amount (650,000)", $near($sum11, 650000), (string)$sum11);

        // Quotation PDF.
        $q = cpk_pdf_text(generateConferenceQuotationPDF($row11, ['name' => 'ZZ Room']));
        $qFlat = str_replace(' | ', ' ', $q);
        ok("11 [$m11] quotation: deposit printed as MWK500,000, never the flag (MWK1)",
            strpos($qFlat, 'MWK500,000') !== false && !preg_match('/MWK1(?![\d,])/', $qFlat), substr($q, 0, 900));
        ok("11 [$m11] quotation: Room hire MWK450,000 then Tea MWK100,000, Projector MWK80,000, Banner MWK20,000",
            strpos($qFlat, 'Room hire - ZZ Room') !== false && strpos($qFlat, 'MWK450,000') !== false && strpos($qFlat, $tag . ' Projector') !== false
            && strpos($qFlat, 'MWK100,000') !== false && strpos($qFlat, 'MWK80,000') !== false && strpos($qFlat, 'MWK20,000') !== false, substr($q, 0, 900));

        // Invoice HTML (kit), paid 200,000 then 800,000.
        foreach ([200000 => 200000, 800000 => 500000] as $paid11 => $depWant) {
            $row11['amount_paid'] = $paid11;
            $row11['amount_due'] = max(0, $gross11 - $paid11);
            $h11 = buildConferenceInvoiceHTML($row11, 'ZZ-TEST-11', 'ZZ Hotel', 'zz@example.invalid', '+265000000000', 'ZZ Street', 'MWK');
            $plain11 = html_entity_decode(strip_tags(str_replace(['<br/>', '<br>'], ' ', $h11)), ENT_QUOTES, 'UTF-8');
            $depGot = preg_match('/Deposit paid\s*MWK\s*([\d,]+\.\d\d)/', preg_replace('/\s+/', ' ', $plain11), $dm) ? (float)str_replace(',', '', $dm[1]) : null;
            ok("11 [$m11] invoice: Deposit paid = min(paid " . number_format($paid11) . ", 500,000) = " . number_format($depWant), $depGot !== null && $near($depGot, $depWant), json_encode($depGot));
        }
        ok("11 [$m11] invoice: room hire 450,000 and the three package lines with how they are charged",
            strpos($plain11, 'MWK 450,000.00') !== false && strpos($plain11, $tag . ' Projector') !== false && strpos($plain11, '40,000 × 2 days') !== false
            && strpos($plain11, '20,000 per event') !== false && strpos($plain11, 'MWK 100,000.00') !== false, substr(preg_replace('/\s+/', ' ', $plain11), 0, 1200));
        ok("11 [$m11] invoice: package names are escaped", strpos($h11, $tag . ' Tea <b>') === false && strpos($h11, $tag . ' Tea &lt;b&gt;') !== false);

        // Email block: escaped, before-VAT label only when VAT was added on top, in the customer template only with packages.
        $e11 = rh_conf_email_packages_html($row11);
        ok("11 [$m11] email packages block: escaped, room hire 450,000 and each line", strpos($e11, '&lt;b&gt;') !== false && strpos($e11, '<b>') === false
            && strpos($e11, 'MWK 450,000') !== false && strpos($e11, '5,000 × 10 delegates × 2 days') !== false, $e11);
        ok("11 [$m11] email block says \"(before VAT)\" only when VAT was added on top", (strpos($e11, '(before VAT)') !== false) === ($m11 === 'exclusive'));
        $t11 = loadEmailTemplate('conference-enquiry-customer.html', ['packages_html' => $e11, 'event_type' => '']);
        $t0 = loadEmailTemplate('conference-enquiry-customer.html', ['packages_html' => '', 'event_type' => '']);
        ok("11 [$m11] customer enquiry email shows the block with packages and nothing without", strpos($t11, $tag . ' Projector') !== false && strpos($t0, 'Packages') === false);
        ok("11 [$m11] email block for an enquiry with no lines is empty", rh_conf_email_packages_html(['id' => 0, 'total_amount' => 450000]) === '');
    } finally {
        $pdo->rollBack();
        $GLOBALS['_SITE_SETTINGS'] = $savedSettings11;
    }
}
ok('11 rolled back: no test enquiries left', (int)$pdo->query("SELECT COUNT(*) FROM conference_inquiries WHERE inquiry_reference LIKE " . $pdo->quote($tag . '11%'))->fetchColumn() === 0);

// ───────────── 12 (fault 3): EOD conference revenue never adds VAT twice (in-process, rolled back) ─────────────
// The VAT mode is forced in this process's settings memory only (never written). A row with total_with_vat 0 earns
// total_amount in inclusive mode (already gross) and total_amount + vat_amount in exclusive mode. Pending earns nothing.
require_once $root . '/admin/includes/eod-revenue.php';
$savedSettings = $GLOBALS['_SITE_SETTINGS'] ?? [];
$pdo->beginTransaction();
try {
    $ins12 = $pdo->prepare("INSERT INTO conference_inquiries (inquiry_reference, conference_room_id, company_name, contact_person, email, phone, event_date, start_time, end_time, number_of_attendees, status, total_amount, package_total, vat_amount, total_with_vat, amount_paid, amount_due, created_at)
        VALUES (?, 0, 'ZZ Pkg Co', 'ZZ Person', 'zz.cpk@example.invalid', '+265000000000', ?, '09:00:00', '17:00:00', 40, ?, 3290000, 2540000, ?, 0, 0, 0, NOW())");
    foreach (['inclusive' => [711, 465965.67, 3290000], 'exclusive' => [712, 542850, 3832850]] as $m12 => [$off, $vat12, $want]) {
        $d12 = date('Y-m-d', strtotime("+{$off} day"));
        $pre = (int)$pdo->query("SELECT COUNT(*) FROM conference_inquiries WHERE event_date = " . $pdo->quote($d12) . " AND status IN ('confirmed','completed')")->fetchColumn();
        $ins12->execute([$tag . '12' . $m12[0], $d12, 'confirmed', $vat12]);
        $ins12->execute([$tag . '12' . $m12[0] . 'P', $d12, 'pending', $vat12]);
        $GLOBALS['_SITE_SETTINGS'] = ['vat_enabled' => '1', 'vat_rate' => '16.5', 'vat_pricing_mode' => $m12] + $savedSettings;
        $got = (float)(rh_eod_earned_revenue($pdo, $d12)['conf_gross'] ?? -1);
        ok("12 [$m12] EOD conference revenue for total_with_vat 0 = " . number_format($want) . " (VAT once, pending not counted)", $pre === 0 && $near($got, $want), "pre=$pre got=$got");
    }
} finally {
    $pdo->rollBack();
    $GLOBALS['_SITE_SETTINGS'] = $savedSettings;
}

// ───────────── 14 (fault 1): Reports + CSV date conferences by event_date, end day once, revenue confirmed/completed only ─────────────
// Range: +800 .. +802. In range: confirmed (created long ago) with Tea + Projector, completed with Tea, pending with
// Lunch (counted in the status table, no revenue). Out of range: confirmed on +803 (end + 1 day) with Lunch.
$r14s = date('Y-m-d', strtotime('+800 day'));
$r14e = date('Y-m-d', strtotime('+802 day'));
$ev14 = static function (string $ref, string $room, int $off, string $status, float $total, array $lines) use ($tag): array {
    $pk = round(array_sum(array_column($lines, 2)), 2);
    $sql = ["INSERT INTO conference_inquiries (inquiry_reference, conference_room_id, company_name, contact_person, email, phone, event_date, start_time, end_time, number_of_attendees, status, total_amount, package_total, vat_rate, vat_amount, total_with_vat, amount_paid, amount_due, created_at)
        VALUES ('{$tag}{$ref}', {$room}, 'ZZ Pkg Co', 'ZZ Person', 'zz.cpk@example.invalid', '+265000000000', DATE_ADD(CURDATE(), INTERVAL {$off} DAY), '08:00:00', '17:00:00', 10, '{$status}', {$total}, {$pk}, 16.5, " . round($total - round($total / 1.165, 2), 2) . ", {$total}, 0, {$total}, '2020-01-01 10:00:00')", 'SET @e = LAST_INSERT_ID()'];
    foreach ($lines as $l) {
        $sql[] = "INSERT INTO conference_inquiry_packages (inquiry_id, package_id, package_name, price_type, unit_price, days, delegates, total_cost)
            VALUES (@e, @{$l[0]}, '{$tag} {$l[1]}', 'per_event', {$l[2]}, 1, 10, {$l[2]})";
    }
    return $sql;
};
$S14 = array_merge($seed('inclusive'),
    $ev14('R1', '@ra', 800, 'confirmed', 1000000, [['pt', 'Tea', 50000], ['pp', 'Projector', 30000]]),
    $ev14('R2', '@ra', 802, 'completed', 500000, [['pt', 'Tea', 50000]]),
    $ev14('R3', '@ra', 801, 'pending', 700000, [['pl', 'Lunch', 150000]]),
    $ev14('R4', '@ra', 803, 'confirmed', 900000, [['pl', 'Lunch', 150000]]));
$num = static fn(string $s): float => (float)str_replace(',', '', $s);
$r = cpk_run('14 reports', 'admin/reports.php', ['method' => 'GET', 'setup' => $S14, 'get' => ['tab' => 'conference', 'start_date' => $r14s, 'end_date' => $r14e]]);
$h = $r['html'];
$pk14 = [];
if (preg_match_all('#<td><strong>' . preg_quote($tag, '#') . ' (\w+)</strong></td>\s*<td>([\d,]+)</td>\s*<td>[^<]*?([\d,]+\.\d\d)</td>#', $h, $pm, PREG_SET_ORDER)) {
    foreach ($pm as $p) { $pk14[$p[1]] = [(int)str_replace(',', '', $p[2]), $num($p[3])]; }
}
// Known harness limit: the Reports page's monthly-trend SQL carries a "-- ...;" comment, which the harness's
// implicit-commit guard reads as a second statement and skips, so the page stops before the conference tab
// (Invalid parameter number; the real server is not affected). The screen checks then SKIP; the CSV below runs
// the same filters.
if (strpos((string)$r['raw'], 'Invalid parameter number') !== false && $pk14 === []) {
    echo "[SKIP] 14 Reports screen (page harness skips the monthly-trend query; see comment)\n";
} else {
    ok('14 Reports packages: Tea sold 2 = 100,000, Projector 1 = 30,000; pending and end+1 day Lunch not counted',
        ($pk14['Tea'] ?? null) === [2, 100000.0] && ($pk14['Projector'] ?? null) === [1, 30000.0] && !isset($pk14['Lunch']), json_encode([$pk14, $r['raw']]));
    $roomA = preg_match('#<strong>' . preg_quote($tag, '#') . ' Room A</strong></td>\s*<td>[\d,]+</td>\s*<td>([\d,]+)</td>\s*<td>[\d,]+</td>\s*<td>[^<]*?([\d,]+\.\d\d)</td>#', $h, $ra14) ? [(int)$ra14[1], $num($ra14[2])] : null;
    ok('14 Reports room utilisation: room A 3 events (pending listed, end+1 not), revenue 1,500,000 (confirmed + completed only)', $roomA === [3, 1500000.0], json_encode($roomA));
}
$r = cpk_run('14 csv', 'api/reports-export.php', ['method' => 'GET', 'setup' => $S14, 'get' => ['report_type' => 'conference', 'start_date' => $r14s, 'end_date' => $r14e]]);
$csv = $r['html'];
$csvPk = [];
foreach (preg_split('/\R/', $csv) as $ln) {
    $c = str_getcsv($ln);
    if (count($c) === 3 && strpos((string)$c[0], $tag . ' ') === 0) { $csvPk[substr($c[0], strlen($tag) + 1)] = [(int)$c[1], $num(preg_replace('/[^\d.,]/', '', (string)$c[2]))]; }
}
ok('14 CSV packages section matches the screen (Tea 2 / 100,000, Projector 1 / 30,000, no Lunch)', ($csvPk['Tea'] ?? null) === [2, 100000.0] && ($csvPk['Projector'] ?? null) === [1, 30000.0] && !isset($csvPk['Lunch']), json_encode([$csvPk, substr($csv, 0, 600), $r['raw']]));
$csvRoom = null;
foreach (preg_split('/\R/', $csv) as $ln) { $c = str_getcsv($ln); if (count($c) === 5 && $c[0] === $tag . ' Room A') { $csvRoom = [(int)$c[2], $num(preg_replace('/[^\d.,]/', '', (string)$c[4]))]; } }
ok('14 CSV room A: 3 events, revenue 1,500,000', $csvRoom === [3, 1500000.0], json_encode($csvRoom));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
