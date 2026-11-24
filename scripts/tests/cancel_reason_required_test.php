<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Staff cancel of a room booking needs a reason (owner 2026-11-24): includes/cancel-reason.php, used by
 * bookings_cancel_commit (All Bookings row / bulk / Assistant) and Booking details (booking_action=cancel).
 * Usage (repo root, TEST HOTEL ONLY): php scripts/tests/cancel_reason_required_test.php
 * Checks: the rule itself; empty / "Cancelled by admin" / 4 characters refused with nothing changed; a pending and a
 * confirmed booking cancelled with a reason show it ONCE in History (timeline + audit + cancellation log merge), for a
 * single room and for a whole group; Booking details and All Bookings pages refuse / accept through the page harness.
 * Builds its OWN rows (references GCRT-*, emails @example.invalid) inside ONE transaction that is rolled back (page runs
 * use page_harness.php, its own never-committing transaction). No email is sent: "Email the guest" is always unticked
 * and the test hotel's mail / WhatsApp guard must be on. A leak check counts every marker row afterwards.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false || basename($root) !== 'Liwonde_Sun_Hotel_2026') {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/includes/billing-accounts.php';
require_once $root . '/includes/cancellation-policy.php';
require_once $root . '/includes/booking-history.php';
require_once $root . '/config/email.php';
require_once $root . '/config/invoice.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/finance-account-sync.php';
require_once $root . '/admin/includes/booking-row-actions.php';
require_once $root . '/admin/includes/bookings-bulk.php';
require_once $root . '/includes/cancel-reason.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

if (!function_exists('rh_test_platform_active') && is_file($root . '/includes/test-platform-mail.php')) { require_once $root . '/includes/test-platform-mail.php'; }
$guard = function_exists('rh_test_platform_active') && rh_test_platform_active();
ok('mail guard: test platform mode is on (no real guest gets mail or WhatsApp)', $guard);
if (!$guard) { echo "\n$pass passed, $fail failed\n"; exit(1); }

// ---- the rule -------------------------------------------------------------------------------------------------------
$c = static fn($v): array => rh_cancel_reason_check($v);
ok('rule: empty / spaces / null / array refused', !$c('')['ok'] && !$c("  \n\t ")['ok'] && !$c(null)['ok'] && !$c(['x'])['ok']);
ok('rule: "Cancelled by admin" refused in any case, with or without a full stop', !$c('Cancelled by admin')['ok'] && !$c('  cancelled BY admin. ')['ok']
    && strpos($c('Cancelled by admin')['error'], 'Say why') === 0);
ok('rule: 4 characters refused, 5 accepted', !$c('abcd')['ok'] && strpos($c('abcd')['error'], 'at least 5') !== false && $c('abcde')['ok']);
ok('rule: 5 accented characters count as 5 (not bytes)', $c('ñáéíó')['ok'] && !$c('ñáéí')['ok']);
ok('rule: trimmed, runs of spaces / line breaks become one space', $c("  Guest   asked\r\n to\tcancel  ")['reason'] === 'Guest asked to cancel');
ok('rule: 500 accepted, 501 refused', $c(str_repeat('a', 500))['ok'] && !$c(str_repeat('a', 501))['ok'] && strpos($c(str_repeat('a', 501))['error'], '500') !== false);
ok('rule: every refusal says nothing was changed', strpos($c('')['error'], 'Nothing was changed') !== false && strpos($c('abc')['error'], 'Nothing was changed') !== false);
$long = str_repeat('word ', 60) . 'end';
ok('history line = the History builder\'s own clip (short and long reasons)', rh_cancel_reason_history_line('Guest asked to cancel') === 'Reason: ' . rh_hist_clip('Guest asked to cancel', 200)
    && rh_cancel_reason_history_line($c($long)['reason']) === 'Reason: ' . rh_hist_clip($c($long)['reason'], 200), rh_cancel_reason_history_line($c($long)['reason']));

// ---- ready-made reasons (owner 2026-11-24) --------------------------------------------------------------------------
$allPresetsOk = true;
foreach (['room', 'noshow', 'guest', 'conference', 'event', 'gym'] as $ctx) {
    $list = rh_cancel_reason_presets($ctx);
    $allPresetsOk = $allPresetsOk && count($list) >= 3 && end($list) === rh_cancel_reason_other();
    foreach (array_slice($list, 0, -1) as $p) {
        if (!rh_cancel_reason_check($p)['ok'] || !rh_noshow_note_check($p)['ok']) { $allPresetsOk = false; echo "  preset fails the rule: $ctx / $p\n"; }
    }
    ok("presets ($ctx): JSON round-trips to the same list", json_decode(rh_cancel_reason_presets_json($ctx), true) === $list);
}
ok('presets: every list ends with "Other" and every other preset passes the staff rule on its own', $allPresetsOk);
ok('presets: unknown context = empty list, no select drawn', rh_cancel_reason_presets('nope') === [] && rh_reason_preset_select('nope', 'a', 'b') === '');
ok('presets: "Prefer not to say" is a guest choice', in_array('Prefer not to say', rh_cancel_reason_presets('guest'), true));
$sel = rh_reason_preset_select('room', 'x"id', 'tgt');
ok('preset select: no name (never posted), points at its box, escaped, Other = __other', strpos($sel, ' name=') === false && strpos($sel, 'data-reason-preset-for="tgt"') !== false
    && strpos($sel, 'x&quot;id') !== false && strpos($sel, 'value="__other"') !== false);
ok('rule: "Other" picked with nothing typed refused', !$c('Other (type below)')['ok'] && !$c('other')['ok'] && strpos($c('Other (type below)')['error'], 'Other') !== false
    && $c('Other: guest moved to a sister hotel')['ok']);
$ns = static fn($v): array => rh_noshow_note_check($v);
ok('no-show rule: empty / 4 characters refused with no-show wording, 5 accepted', !$ns('')['ok'] && strpos($ns('')['error'], 'no-show') !== false
    && !$ns('abcd')['ok'] && strpos($ns('abcd')['error'], 'no-show note') !== false && $ns('No contact')['ok']);
$g = static fn(array $p): array => rh_guest_cancel_reason_from_post($p);
ok('guest: no choice refused (even with text typed)', !$g([])['ok'] && !$g(['cancel_reason' => 'Plans changed a lot'])['ok'] && $g([])['error'] === 'Please choose a reason for cancelling.');
ok('guest: a choice not in the list refused', !$g(['cancel_reason_choice' => 'Cancelled by admin'])['ok'] && !$g(['cancel_reason_choice' => ['x']])['ok']);
ok('guest: "Prefer not to say" alone accepted as it is', $g(['cancel_reason_choice' => 'Prefer not to say'])['reason'] === 'Prefer not to say');
ok('guest: choice + text joined, spaces collapsed', $g(['cancel_reason_choice' => 'I need different dates', 'cancel_reason' => "  moving  to\nMarch "])['reason'] === 'I need different dates - moving to March');
ok('guest: "Other" needs text; with text the text alone is kept', !$g(['cancel_reason_choice' => 'Other (type below)'])['ok'] && $g(['cancel_reason_choice' => 'Other (type below)', 'cancel_reason' => 'Wedding moved'])['reason'] === 'Wedding moved');
ok('guest: capped at 300 characters', mb_strlen($g(['cancel_reason_choice' => 'My travel plans changed', 'cancel_reason' => str_repeat('a', 400)])['reason']) === 300);

// ---- setup ----------------------------------------------------------------------------------------------------------
$adminId = 0;
foreach ($pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 10")->fetchAll(PDO::FETCH_COLUMN) as $aid) {
    if (hasPermission((int)$aid, 'edit_booking')) { $adminId = (int)$aid; break; }
}
ok('setup: an administrator with Edit Booking exists', $adminId > 0);
$admin = ['id' => $adminId, 'full_name' => 'cancel_reason_test', 'username' => 'cancel_reason_test'];

$src = (int)$pdo->query("SELECT id FROM bookings WHERE deleted_at IS NULL AND (primary_booking_id IS NULL OR primary_booking_id = 0)
    AND id <> 2100008837 AND NOT EXISTS (SELECT 1 FROM bookings m WHERE m.primary_booking_id = bookings.id) ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($src <= 0) { fwrite(STDERR, "No source booking to clone.\n"); exit(1); }
// Warm up anything that might run DDL (an implicit commit inside the transaction would leak rows).
getBookingFolioSummary($src);
rh_billing_ready($pdo);
rh_cancel_policy_column_ready($pdo);
rh_invoice_paid_refusal($pdo, 'room', $src);
ensureBookingAuditTable($pdo);
rh_booking_history($pdo, $src);
$auditBefore = (int)$pdo->query("SELECT COUNT(*) FROM booking_audit_log WHERE booking_reference LIKE 'GCRT-%'")->fetchColumn();
$clBefore = (int)$pdo->query("SELECT COUNT(*) FROM cancellation_log WHERE booking_reference LIKE 'GCRT-%'")->fetchColumn();

$cols = array_column($pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_ASSOC), 'Field');
$n = 0;
$today = date('Y-m-d');
$tag = strtoupper(bin2hex(random_bytes(3)));
// One INSERT ... SELECT that clones $src with these values (used in-process and as page-harness setup SQL).
$cloneSql = static function (array $over) use ($pdo, $cols, $src, $today): string {
    $over += ['guest_name' => 'Gcrt Test', 'primary_booking_id' => null, 'billing_account_id' => null, 'billing_reference' => null, 'billing_due_date' => null,
        'billing_covers' => null, 'final_invoice_number' => null, 'deleted_at' => null, 'status' => 'pending', 'client_uuid' => null,
        'cancellation_policy_snapshot' => null, 'individual_room_id' => null, 'room_combination_id' => null, 'room_guest_name' => null,
        'amount_paid' => 0, 'check_in_date' => date('Y-m-d', strtotime($today . ' +5 days')), 'check_out_date' => date('Y-m-d', strtotime($today . ' +7 days'))];
    $sel = [];
    $ins = [];
    foreach ($cols as $col) {
        if ($col === 'id') { continue; }
        $ins[] = '`' . $col . '`';
        if (array_key_exists($col, $over)) {
            $v = $over[$col];
            $sel[] = $v === null ? 'NULL' : $pdo->quote((string)$v);
        } else {
            $sel[] = '`' . $col . '`';
        }
    }
    return 'INSERT INTO bookings (' . implode(',', $ins) . ') SELECT ' . implode(',', $sel) . ' FROM bookings WHERE id = ' . (int)$src;
};
$mk = static function (array $over = []) use ($pdo, $cloneSql, &$n, $tag): int {
    $n++;
    $over += ['booking_reference' => 'GCRT-' . $tag . '-' . $n, 'guest_email' => 'gcrt' . $n . '@example.invalid'];
    $pdo->exec($cloneSql($over));
    return (int)$pdo->lastInsertId();
};
$get = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$timelineWith = static function (int $id, string $needle) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_id = ? AND description LIKE ?');
    $st->execute([$id, '%' . $needle . '%']);
    return (int)$st->fetchColumn();
};
$rows = static function (string $table, int $id) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE booking_id = ?");
    $st->execute([$id]);
    return (int)$st->fetchColumn();
};
// History steps whose text carries the reason line (must be exactly one), plus how often it appears at all.
$histReason = static function (int $id, string $reason) use ($pdo): array {
    $line = rh_cancel_reason_history_line($reason);
    $steps = 0; $hits = 0;
    foreach (rh_booking_history($pdo, $id) as $s) {
        $text = (string)($s['what'] ?? '') . "\n" . (string)($s['detail'] ?? '');
        $k = substr_count($text, $line);
        if ($k > 0) { $steps++; $hits += $k; }
    }
    return [$steps, $hits];
};
$commit = static function (int $id, array $in) use ($pdo, $get, $admin): array {
    $b = $get($id);
    try {
        return ['ok' => true, 'r' => bookings_cancel_commit($pdo, $id, (string)$b['status'], (string)$b['booking_reference'], $admin, $in + ['email_guest' => '0'])];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
};

$pdo->beginTransaction();
try {
    // ---- (a) (b) (c) refusals: nothing changes --------------------------------------------------------------------
    $R = $mk();
    $tl0 = $rows('booking_timeline_logs', $R); $au0 = $rows('booking_audit_log', $R);
    foreach (['(a) empty' => '', '(a) only spaces' => '    ', '(b) "Cancelled by admin"' => 'Cancelled by admin', '(c) 4 characters' => 'abcd'] as $label => $why) {
        $r = $commit($R, ['cancellation_reason' => $why]);
        ok("All Bookings cancel: $label reason refused, booking untouched", !$r['ok'] && strpos((string)$r['error'], 'Nothing was changed') !== false
            && $get($R)['status'] === 'pending' && $rows('booking_timeline_logs', $R) === $tl0 && $rows('booking_audit_log', $R) === $au0, json_encode($r));
    }
    $r = $commit($R, []);
    ok('All Bookings cancel: no reason field at all refused', !$r['ok'] && $get($R)['status'] === 'pending', json_encode($r));

    // ---- (d) pending, valid reason ---------------------------------------------------------------------------------
    $why = "Guest asked  to cancel\n (GCRT $tag pending)";
    $whyN = 'Guest asked to cancel (GCRT ' . $tag . ' pending)';
    $P = $mk();
    $r = $commit($P, ['cancellation_reason' => $why]);
    ok('(d) pending + reason -> cancelled', $r['ok'] && $get($P)['status'] === 'cancelled', json_encode($r));
    ok('(d) timeline status change carries the (normalised) reason', $timelineWith($P, 'Reason: ' . $whyN) === 1, (string)$timelineWith($P, 'Reason: ' . $whyN));
    $st = $pdo->prepare("SELECT note FROM booking_audit_log WHERE booking_id = ? AND action = 'cancelled' ORDER BY id DESC LIMIT 1");
    $st->execute([$P]);
    ok('(d) audit note is the reason line', (string)$st->fetchColumn() === 'Reason: ' . $whyN);
    [$steps, $hits] = $histReason($P, $whyN);
    ok('(d) History shows the reason once', $steps === 1 && $hits === 1, json_encode([$steps, $hits, rh_booking_history($pdo, $P)]));

    // ---- (e) confirmed, valid reason, guest not emailed -----------------------------------------------------------
    $whyC = 'Double booking found (GCRT ' . $tag . ' confirmed)';
    $C = $mk(['status' => 'confirmed']);
    $r = $commit($C, ['cancellation_reason' => $whyC]);
    ok('(e) confirmed + reason -> cancelled, no email', $r['ok'] && $get($C)['status'] === 'cancelled' && strpos((string)($r['r']['message'] ?? ''), 'No email sent') !== false, json_encode($r));
    $st = $pdo->prepare('SELECT cancellation_reason FROM cancellation_log WHERE booking_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$C]);
    ok('(e) cancellation log keeps the reason', (string)$st->fetchColumn() === $whyC);
    [$steps, $hits] = $histReason($C, $whyC);
    ok('(e) History shows the reason once', $steps === 1 && $hits === 1, json_encode([$steps, $hits, rh_booking_history($pdo, $C)]));
    // The log row written long after the cancel (a slow guest email): still once.
    $late = $pdo->prepare('UPDATE cancellation_log SET created_at = DATE_ADD(created_at, INTERVAL 90 SECOND) WHERE booking_id = ?');
    $late->execute([$C]);
    [$steps, $hits] = $histReason($C, $whyC);
    ok('(e) log row 90 seconds after the cancel: History still shows the reason once', $steps === 1 && $hits === 1, json_encode([$steps, $hits, rh_booking_history($pdo, $C)]));

    // Email ticked (test hotel: outbound mail muted): reason once.
    $whyE = 'Guest asked by email to cancel (GCRT ' . $tag . ' emailed)';
    $E = $mk(['status' => 'confirmed']);
    $r = $commit($E, ['cancellation_reason' => $whyE, 'email_guest' => '1']);
    ok('(e) email ticked: cancelled', $r['ok'] && $get($E)['status'] === 'cancelled', json_encode($r));
    $late->execute([$E]);
    [$steps, $hits] = $histReason($E, $whyE);
    ok('(e) email ticked, log row 90 seconds later: History shows the reason once', $steps === 1 && $hits === 1, json_encode([$steps, $hits, rh_booking_history($pdo, $E)]));

    // ---- a whole group: every room's History has the reason once -------------------------------------------------
    $whyG = 'Company cancelled the trip (GCRT ' . $tag . ' group)';
    $G1 = $mk(['status' => 'confirmed']);
    $G2 = $mk(['status' => 'pending', 'primary_booking_id' => $G1]);
    $r = $commit($G1, ['cancellation_reason' => 'no']);
    ok('group cancel: short reason refused, both rooms untouched', !$r['ok'] && $get($G1)['status'] === 'confirmed' && $get($G2)['status'] === 'pending', json_encode($r));
    $r = $commit($G1, ['cancellation_reason' => $whyG, 'scope' => 'group']);
    ok('group cancel: both rooms cancelled', $r['ok'] && $get($G1)['status'] === 'cancelled' && $get($G2)['status'] === 'cancelled', json_encode($r));
    foreach (['lead (was confirmed)' => $G1, 'member (was pending)' => $G2] as $label => $gid) {
        [$steps, $hits] = $histReason($gid, $whyG);
        ok("group cancel: $label History shows the reason once", $steps === 1 && $hits === 1, json_encode([$steps, $hits, rh_booking_history($pdo, $gid)]));
        ok("group cancel: $label timeline carries the reason", $timelineWith($gid, 'Reason: ' . $whyG) === 1);
        $late->execute([$gid]);
        [$steps, $hits] = $histReason($gid, $whyG);
        ok("group cancel: $label log row 90 seconds later, History still shows the reason once", $steps === 1 && $hits === 1, json_encode([$steps, $hits, rh_booking_history($pdo, $gid)]));
    }

    // ---- the Tentative holds screen and the check-in endpoint write the same History rows as their pages (the pages
    // themselves are run through the harness below); the reason text must appear once in History, log row late too.
    if (!function_exists('logBookingAudit')) { require_once $root . '/admin/includes/audit-functions.php'; }
    $rawHits = static function (int $id, string $reason) use ($pdo): int {
        $k = 0;
        foreach (rh_booking_history($pdo, $id) as $s) { $k += substr_count((string)($s['what'] ?? '') . "\n" . (string)($s['detail'] ?? ''), $reason); }
        return $k;
    };
    $whyT = 'No deposit received for the hold (GCRT ' . $tag . ' mirror)';
    $T = $mk(['status' => 'tentative', 'is_tentative' => 1, 'tentative_expires_at' => date('Y-m-d H:i:s', strtotime('+2 days'))]);
    $tb = $get($T);
    $sr = cancelRoomBookingSettled($pdo, $T, (int)$admin['id'], $whyT);
    $pdo->prepare("INSERT INTO tentative_booking_log (booking_id, action, performed_by, action_reason) VALUES (?, 'cancelled', ?, ?)")
        ->execute([$T, (int)$admin['id'], 'Cancelled by ' . ($admin['full_name'] ?? 'Admin')]);
    logBookingStatusChange($T, (string)$tb['booking_reference'], 'tentative', 'cancelled', 'admin', (int)$admin['id'], (string)($admin['full_name'] ?? ''), $whyT);
    logBookingAudit($T, 'cancelled', ['status' => 'tentative'], ['status' => 'cancelled'], rh_cancel_reason_history_line($whyT), (string)$tb['booking_reference']);
    logCancellationToDatabase($T, (string)$tb['booking_reference'], 'room', (string)$tb['guest_email'], (int)$admin['id'], $whyT, false, 'muted');
    $late->execute([$T]);
    [$steps, $hits] = $histReason($T, $whyT);
    ok('Tentative hold cancel (page writes, log row 90 s late): History shows the reason once', !empty($sr['success']) && $steps === 1 && $hits === 1 && $rawHits($T, $whyT) === 1,
        json_encode([$sr, $steps, $hits, rh_booking_history($pdo, $T)]));
    $whyP = 'Guest phoned to cancel at the desk (GCRT ' . $tag . ' checkin)';
    $P2 = $mk(['status' => 'confirmed']);
    $pb = $get($P2);
    $sr = cancelRoomBookingSettled($pdo, $P2, (int)$admin['id'], $whyP);
    logBookingStatusChange($P2, (string)$pb['booking_reference'], 'confirmed', 'cancelled', 'admin', (int)$admin['id'], null, $whyP);
    logBookingAudit($P2, 'cancelled', ['status' => 'confirmed'], ['status' => 'cancelled'], rh_cancel_reason_history_line($whyP), (string)$pb['booking_reference']);
    [$steps, $hits] = $histReason($P2, $whyP);
    ok('Check-in endpoint cancel (page writes): History shows the reason once', !empty($sr['success']) && $steps === 1 && $hits === 1 && $rawHits($P2, $whyP) === 1,
        json_encode([$sr, $steps, $hits, rh_booking_history($pdo, $P2)]));

    // ---- bulk: no-show without a note / cancel without a reason refused before anything is looked at ---------------
    $NB = $mk(['status' => 'confirmed', 'check_in_date' => date('Y-m-d', strtotime($today . ' -1 day')), 'check_out_date' => date('Y-m-d', strtotime($today . ' +1 day'))]);
    foreach (['no note' => [], 'empty note' => ['note' => '  '], '4 characters' => ['note' => 'abcd']] as $label => $in) {
        $br = rh_bk_bulk_apply($pdo, 'noshow', [$NB], $admin, 'any-state', null, [], $in);
        ok("bulk Mark no-show: $label refused, booking untouched", empty($br['success']) && strpos((string)$br['error'], 'no-show') !== false && $get($NB)['status'] === 'confirmed', json_encode($br));
    }
    $br = rh_bk_bulk_apply($pdo, 'cancel', [$NB], $admin, 'any-state', null, [], ['cancellation_reason' => 'Other (type below)']);
    ok('bulk Cancel: "Other" alone refused, booking untouched', empty($br['success']) && strpos((string)$br['error'], 'Other') !== false && $get($NB)['status'] === 'confirmed', json_encode($br));
} catch (Throwable $e) {
    ok('no exception', false, $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// ---- the pages (page harness: its own never-committing transaction) ---------------------------------------------------
$harness = $root . '/scripts/tests/page_harness.php';
function cr_run(string $page, array $cfg): array {
    global $harness;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -1200), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    ok("harness rolled back cleanly ($page)", !empty($res['rolled_back']) && empty($res['leak']) && empty($res['marker_lost']), json_encode(array_intersect_key($res, ['rolled_back' => 1, 'leak' => 1, 'marker_lost' => 1, 'raw' => 1, 'error' => 1])));
    return $res;
}
$q = static fn(string $s): string => $pdo->quote($s);
$hRef = 'GCRT-' . $tag . '-H';
$hSetup = [$cloneSql(['booking_reference' => $hRef, 'guest_email' => 'gcrth@example.invalid', 'status' => 'pending'])];
$hVars = ['p' => 'SELECT id FROM bookings WHERE booking_reference = ' . $q($hRef)];
$hWhy = 'Guest asked to cancel by phone (GCRT ' . $tag . ' details)';
$hVerify = static fn(string $why): array => [
    'SELECT status FROM bookings WHERE booking_reference = ' . $q($hRef),
    'SELECT COUNT(*) AS n FROM booking_timeline_logs WHERE booking_reference = ' . $q($hRef) . ' AND description LIKE ' . $q('%Reason: ' . $why . '%'),
    "SELECT COUNT(*) AS n FROM booking_audit_log WHERE booking_reference = " . $q($hRef) . " AND action = 'cancelled' AND note = " . $q('Reason: ' . $why),
    'SELECT COUNT(*) AS n FROM cancellation_log WHERE booking_reference = ' . $q($hRef) . ' AND cancellation_reason = ' . $q($why),
];
$st0 = static fn(array $r): string => (string)($r['verify'][0][0]['status'] ?? '?');
$n1 = static fn(array $r, int $i): int => (int)($r['verify'][$i][0]['n'] ?? -1);

$r = cr_run('admin/booking-details.php', ['anon' => false, 'setup' => $hSetup, 'vars' => $hVars, 'get' => ['id' => '{p}'],
    'post' => ['booking_action' => 'cancel', 'cancellation_reason' => 'Cancelled by admin', 'email_guest' => '0'], 'verify' => $hVerify('Cancelled by admin')]);
ok('Booking details: "Cancelled by admin" refused, booking still pending', $st0($r) === 'pending' && $n1($r, 1) === 0, json_encode($r['verify'] ?? $r));
$r = cr_run('admin/booking-details.php', ['anon' => false, 'setup' => $hSetup, 'vars' => $hVars, 'get' => ['id' => '{p}'],
    'post' => ['booking_action' => 'cancel', 'cancellation_reason' => 'abcd', 'email_guest' => '0'], 'verify' => $hVerify('abcd')]);
ok('Booking details: 4 characters refused, booking still pending', $st0($r) === 'pending', json_encode($r['verify'] ?? $r));
$r = cr_run('admin/booking-details.php', ['anon' => false, 'setup' => $hSetup, 'vars' => $hVars, 'get' => ['id' => '{p}'],
    'post' => ['booking_action' => 'cancel', 'cancellation_reason' => '  ' . $hWhy . ' ', 'email_guest' => '0'], 'verify' => $hVerify($hWhy)]);
ok('Booking details: valid reason -> cancelled; timeline, audit note and cancellation log each carry it once',
    $st0($r) === 'cancelled' && $n1($r, 1) === 1 && $n1($r, 2) === 1 && $n1($r, 3) === 1, json_encode($r['verify'] ?? $r));

$r = cr_run('admin/bookings.php', ['anon' => false, 'ajax' => true, 'setup' => $hSetup, 'vars' => $hVars,
    'post' => ['action' => 'update_status', 'id' => '{p}', 'status' => 'cancelled', 'cancellation_reason' => '', 'email_guest' => '0'], 'verify' => $hVerify('x')]);
ok('All Bookings page: empty reason -> JSON refusal, booking still pending', $st0($r) === 'pending' && empty($r['json']['success'])
    && strpos((string)($r['json']['message'] ?? ''), 'Say why') === 0, json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
$r = cr_run('admin/bookings.php', ['anon' => false, 'ajax' => true, 'setup' => $hSetup, 'vars' => $hVars,
    'post' => ['action' => 'update_status', 'id' => '{p}', 'status' => 'cancelled', 'cancellation_reason' => $hWhy, 'email_guest' => '0'], 'verify' => $hVerify($hWhy)]);
ok('All Bookings page: valid reason -> cancelled, timeline + audit carry it', $st0($r) === 'cancelled' && !empty($r['json']['success']) && $n1($r, 1) === 1 && $n1($r, 2) === 1,
    json_encode([$r['json'] ?? null, $r['verify'] ?? null]));

// ---- Tentative holds screen and the check-in endpoint: the same required reason ------------------------------------
$tRef = 'GCRT-' . $tag . '-T';
$tSetup = [$cloneSql(['booking_reference' => $tRef, 'guest_email' => 'gcrtt@example.invalid', 'status' => 'tentative', 'is_tentative' => 1,
    'tentative_expires_at' => date('Y-m-d H:i:s', strtotime('+2 days'))])];
$tVars = ['p' => 'SELECT id FROM bookings WHERE booking_reference = ' . $q($tRef)];
$tWhy = 'No payment received for the hold (GCRT ' . $tag . ')';
$tVerify = [
    'SELECT status FROM bookings WHERE booking_reference = ' . $q($tRef),
    'SELECT COUNT(*) AS n FROM booking_timeline_logs WHERE booking_reference = ' . $q($tRef) . ' AND description LIKE ' . $q('%Reason: ' . $tWhy . '%'),
    "SELECT COUNT(*) AS n FROM booking_audit_log WHERE booking_reference = " . $q($tRef) . " AND action = 'cancelled' AND note = " . $q('Reason: ' . $tWhy),
    'SELECT COUNT(*) AS n FROM cancellation_log WHERE booking_reference = ' . $q($tRef) . ' AND cancellation_reason = ' . $q($tWhy),
    'SELECT COUNT(*) AS n FROM tentative_booking_log l JOIN bookings b ON b.id = l.booking_id WHERE b.booking_reference = ' . $q($tRef) . ' AND l.action_reason LIKE ' . $q('%' . $tWhy . '%'),
];
$r = cr_run('admin/tentative-bookings.php', ['anon' => false, 'setup' => $tSetup, 'vars' => $tVars,
    'post' => ['action' => 'cancel', 'id' => '{p}', 'email_guest' => '0'], 'verify' => $tVerify]);
ok('Tentative holds: Cancel Hold with no reason refused, hold untouched', $st0($r) === 'tentative' && $n1($r, 3) === 0, json_encode([$r['verify'] ?? null, $r['output_flash'] ?? null]));
$r = cr_run('admin/tentative-bookings.php', ['anon' => false, 'setup' => $tSetup, 'vars' => $tVars,
    'post' => ['action' => 'cancel', 'id' => '{p}', 'email_guest' => '0', 'cancellation_reason' => 'Other (type below)'], 'verify' => $tVerify]);
ok('Tentative holds: "Other" alone refused, hold untouched', $st0($r) === 'tentative', json_encode($r['verify'] ?? null));
$r = cr_run('admin/tentative-bookings.php', ['anon' => false, 'setup' => $tSetup, 'vars' => $tVars,
    'post' => ['action' => 'cancel', 'id' => '{p}', 'email_guest' => '1', 'cancellation_reason' => $tWhy], 'verify' => $tVerify]);
ok('Tentative holds (email ticked): with a reason -> cancelled; timeline, audit note and cancellation log each carry it once, hold log does not repeat it',
    $st0($r) === 'cancelled' && $n1($r, 1) === 1 && $n1($r, 2) === 1 && $n1($r, 3) === 1 && $n1($r, 4) === 0, json_encode([$r['verify'] ?? null, $r['output_flash'] ?? null]));

$r = cr_run('admin/process-checkin.php', ['anon' => false, 'ajax' => true, 'setup' => $hSetup, 'vars' => $hVars,
    'post' => ['action' => 'cancel', 'booking_id' => '{p}'], 'verify' => $hVerify('x')]);
ok('Check-in endpoint cancel: no reason -> JSON refusal, booking still pending', $st0($r) === 'pending' && empty($r['json']['success'])
    && strpos((string)($r['json']['message'] ?? ''), 'Say why') === 0, json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
$r = cr_run('admin/process-checkin.php', ['anon' => false, 'ajax' => true, 'setup' => $hSetup, 'vars' => $hVars,
    'post' => ['action' => 'cancel', 'booking_id' => '{p}', 'cancellation_reason' => $hWhy], 'verify' => $hVerify($hWhy)]);
ok('Check-in endpoint cancel: with a reason -> cancelled, timeline + audit carry it once', $st0($r) === 'cancelled' && !empty($r['json']['success'])
    && $n1($r, 1) === 1 && $n1($r, 2) === 1, json_encode([$r['json'] ?? null, $r['verify'] ?? null]));

// ---- Mark no-show on the pages: the note is required -----------------------------------------------------------------
$nRef = 'GCRT-' . $tag . '-N';
$nSetup = [$cloneSql(['booking_reference' => $nRef, 'guest_email' => 'gcrtn@example.invalid', 'status' => 'confirmed',
    'check_in_date' => date('Y-m-d', strtotime($today . ' -1 day')), 'check_out_date' => date('Y-m-d', strtotime($today . ' +1 day'))])];
$nVars = ['p' => 'SELECT id FROM bookings WHERE booking_reference = ' . $q($nRef)];
$nWhy = 'Guest did not arrive, no contact (GCRT ' . $tag . ')';
$nVerify = [
    'SELECT status FROM bookings WHERE booking_reference = ' . $q($nRef),
    "SELECT COUNT(*) AS n FROM booking_audit_log WHERE booking_reference = " . $q($nRef) . " AND note LIKE " . $q('%No-show note: ' . $nWhy),
];
$r = cr_run('admin/bookings.php', ['anon' => false, 'ajax' => true, 'setup' => $nSetup, 'vars' => $nVars,
    'post' => ['action' => 'noshow', 'id' => '{p}', 'checkin_note' => ''], 'verify' => $nVerify]);
ok('All Bookings Mark no-show: empty note -> JSON refusal, booking still confirmed', $st0($r) === 'confirmed' && empty($r['json']['success'])
    && strpos((string)($r['json']['message'] ?? ''), 'no-show') !== false, json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
$r = cr_run('admin/bookings.php', ['anon' => false, 'ajax' => true, 'setup' => $nSetup, 'vars' => $nVars,
    'post' => ['action' => 'noshow', 'id' => '{p}', 'checkin_note' => 'abcd'], 'verify' => $nVerify]);
ok('All Bookings Mark no-show: 4 characters refused, booking still confirmed', $st0($r) === 'confirmed' && empty($r['json']['success']), json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
$r = cr_run('admin/booking-details.php', ['anon' => false, 'setup' => $nSetup, 'vars' => $nVars, 'get' => ['id' => '{p}'],
    'post' => ['booking_action' => 'noshow', 'noshow_note' => ''], 'verify' => $nVerify]);
ok('Booking details Mark no-show: empty note refused, booking still confirmed', $st0($r) === 'confirmed', json_encode($r['verify'] ?? $r));
$r = cr_run('admin/booking-details.php', ['anon' => false, 'setup' => $nSetup, 'vars' => $nVars, 'get' => ['id' => '{p}'],
    'post' => ['booking_action' => 'noshow', 'noshow_note' => $nWhy], 'verify' => $nVerify]);
ok('Booking details Mark no-show: with a note -> no-show, audit keeps the note', $st0($r) === 'no-show' && $n1($r, 1) === 1, json_encode($r['verify'] ?? $r));

// ---- guest cancel on the website: a reason must be picked ------------------------------------------------------------
foreach (['lookup_form', 'cancel_booking'] as $rlAction) { // the page's per-IP limit (same reset as cancellation_policy.php)
    $rlKey = 'iprl_' . $rlAction . '_' . substr(hash('sha256', '127.0.0.1|' . $rlAction), 0, 24);
    if (function_exists('deleteCache')) { @deleteCache($rlKey); }
    if (is_file(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $rlKey . '.json')) { @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $rlKey . '.json'); }
}
$gRef = 'GCRT-' . $tag . '-W';
$gMail = 'gcrtw@example.invalid';
$gTok = bin2hex(random_bytes(32));
$gSetup = [$cloneSql(['booking_reference' => $gRef, 'guest_email' => $gMail, 'status' => 'pending',
    'check_in_date' => date('Y-m-d', strtotime($today . ' +60 days')), 'check_out_date' => date('Y-m-d', strtotime($today . ' +62 days'))])];
$gVerify = ['SELECT status FROM bookings WHERE booking_reference = ' . $q($gRef),
    'SELECT COUNT(*) AS n FROM cancellation_log WHERE booking_reference = ' . $q($gRef)];
$gRun = static function (array $extra) use ($gSetup, $gVerify, $gTok, $gRef, $gMail): array {
    return cr_run('booking-lookup.php', ['anon' => true, 'setup' => $gSetup, 'session' => ['_csrf_pub_lookup' => $gTok], 'verify' => $gVerify,
        'post' => ['cancel_booking' => '1', 'csrf_token' => $gTok, 'booking_reference' => $gRef, 'guest_email' => $gMail] + $extra]);
};
$r = $gRun(['cancel_reason' => 'Plans changed']);
ok('guest website cancel: no reason picked -> refused, booking still pending, nothing logged', $st0($r) === 'pending' && $n1($r, 1) === 0, json_encode($r['verify'] ?? $r));
$r = $gRun(['cancel_reason_choice' => 'Not a real choice']);
ok('guest website cancel: a made-up choice -> refused, booking still pending', $st0($r) === 'pending' && $n1($r, 1) === 0, json_encode($r['verify'] ?? $r));

// ---- leak check ----------------------------------------------------------------------------------------------------
$leak = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE 'GCRT-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_reference LIKE 'GCRT-%'")->fetchColumn()
    + ((int)$pdo->query("SELECT COUNT(*) FROM booking_audit_log WHERE booking_reference LIKE 'GCRT-%'")->fetchColumn() - $auditBefore)
    + ((int)$pdo->query("SELECT COUNT(*) FROM cancellation_log WHERE booking_reference LIKE 'GCRT-%'")->fetchColumn() - $clBefore);
ok('leak check: no test row left behind', $leak === 0, (string)$leak);

// Owner 2026-11-25: the staff reason stays internal; guest emails keep the old fixed text.
ok('guest email text: room cancel keeps "Cancelled by admin"', rh_cancel_guest_email_reason() === 'Cancelled by admin');
ok('guest email text: hold keeps "Tentative hold cancelled by hotel"', rh_cancel_guest_email_reason('hold') === 'Tentative hold cancelled by hotel');
foreach (['admin/booking-details.php' => 2, 'admin/includes/booking-row-actions.php' => 1, 'admin/tentative-bookings.php' => 1] as $f => $n) {
    $src = (string)file_get_contents($root . '/' . $f);
    preg_match_all('/sendBookingCancelledEmail\(\$\w+,\s*([^;]*?)\);/', $src, $m);
    $fixed = count(array_filter($m[1], function ($a) { return strpos($a, 'rh_cancel_guest_email_reason(') === 0; }));
    ok("guest email text: $f never emails the staff reason", count($m[1]) === $n && $fixed === $n, json_encode($m[1]));
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
