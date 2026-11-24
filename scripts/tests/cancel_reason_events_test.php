<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Staff cancel of a conference enquiry, event booking, gym inquiry, gym member or gym slot booking needs a reason
 * (owner 2026-11-24: "the timeline, history, logs don't show the whys"; same rule as room bookings,
 * includes/cancel-reason.php). Helper: admin/includes/account-cancel-reason.php.
 * Usage (repo root, TEST HOTEL ONLY): php scripts/tests/cancel_reason_events_test.php
 * Checks, on every page: empty / 4 characters / "Cancelled by admin" refused with nothing changed (status, notes and
 * System Logs untouched); a valid reason cancels and is kept where staff see it later (the record's Notes, the gym
 * member's change history, System Logs > Admin activity), earlier notes (and the gym [member:...] marker) kept.
 * Every page run goes through page_harness.php (its own never-committing transaction); the in-process part runs in ONE
 * transaction that is rolled back. Seed rows use references CRET-<tag>-* and @example.invalid emails; the test
 * hotel's mail / WhatsApp guard must be on. A leak check counts every marker row afterwards.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false || basename($root) !== 'Liwonde_Sun_Hotel_2026') {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/admin/includes/account-cancel-reason.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

if (!function_exists('rh_test_platform_active') && is_file($root . '/includes/test-platform-mail.php')) { require_once $root . '/includes/test-platform-mail.php'; }
$guard = function_exists('rh_test_platform_active') && rh_test_platform_active();
ok('mail guard: test platform mode is on (no real client gets mail or WhatsApp)', $guard);
if (!$guard) { echo "\n$pass passed, $fail failed\n"; exit(1); }

$tag = strtoupper(bin2hex(random_bytes(3)));
$q = static fn(string $s): string => $pdo->quote($s);

// ---- the helper -----------------------------------------------------------------------------------------------------
ok('note: date, who and the reason', (bool)preg_match('/^\[\d{4}-\d\d-\d\d \d\d:\d\d\] Cancelled by Ann Staff\. Reason: Client cancelled$/',
    rh_account_cancel_note('Client cancelled', rh_account_cancel_actor(['full_name' => 'Ann Staff', 'username' => 'ann']))));
ok('actor: full name, then username, then user #id', rh_account_cancel_actor(['full_name' => ' ', 'username' => 'ann']) === 'ann'
    && rh_account_cancel_actor(['id' => 7]) === 'user #7');
$threw = false;
try { rh_account_cancel_append_note($pdo, 'bookings', 1, 'x'); } catch (InvalidArgumentException $e) { $threw = true; }
ok('append: a table outside the fixed list is refused before any SQL', $threw);
ok('presets: conference / event / gym lists exist and end with "Other"', count(rh_cancel_reason_presets('conference')) >= 3
    && array_slice(rh_cancel_reason_presets('event'), -1) === [rh_cancel_reason_other()] && count(rh_cancel_reason_presets('gym')) >= 3);

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO gym_inquiries (reference_number, name, email, phone, status, notes) VALUES (?, 'Cret Notes', 'cretn@example.invalid', '0999000009', 'new', ?)")
        ->execute(['CRET-' . $tag . '-N', '[member:CRET-' . $tag . '-M]']);
    $nid = (int)$pdo->lastInsertId();
    rh_account_cancel_append_note($pdo, 'gym', $nid, 'line one');
    rh_account_cancel_append_note($pdo, 'gym', $nid, 'line two');
    $st = $pdo->prepare('SELECT notes FROM gym_inquiries WHERE id = ?');
    $st->execute([$nid]);
    ok('append: added on new lines, the [member:...] marker kept first', (string)$st->fetchColumn() === '[member:CRET-' . $tag . "-M]\nline one\nline two");
    $pdo->prepare("UPDATE gym_inquiries SET notes = NULL WHERE id = ?")->execute([$nid]);
    rh_account_cancel_append_note($pdo, 'gym', $nid, 'only line');
    $st->execute([$nid]);
    ok('append: empty notes get the line with no blank line before it', (string)$st->fetchColumn() === 'only line');
} catch (Throwable $e) {
    ok('helper: no exception', false, $e->getMessage());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

// ---- the pages (page harness: its own never-committing transaction) -------------------------------------------------
$harness = $root . '/scripts/tests/page_harness.php';
function ce_run(string $page, array $cfg): array {
    global $harness;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -1200), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    ok("harness rolled back cleanly ($page)", !empty($res['rolled_back']) && empty($res['leak']) && empty($res['marker_lost']),
        json_encode(array_intersect_key($res, ['rolled_back' => 1, 'leak' => 1, 'marker_lost' => 1, 'raw' => 1, 'error' => 1])));
    return $res;
}

$today = date('Y-m-d');
$future = date('Y-m-d', strtotime($today . ' +20 days'));
$roomId = (int)$pdo->query('SELECT COALESCE(MIN(id), 0) FROM conference_rooms')->fetchColumn();
$ref = static fn(string $k): string => 'CRET-' . $tag . '-' . $k;

/*
 * One entry per cancel surface:
 *   page, ajax, setup SQL, id SQL, post (with {id}), the status before / after, and the verify SQL:
 *   [0] status, [1] notes (or ''), [2] Admin activity rows carrying the reason, [3] gym member history rows carrying it.
 */
$actLog = static fn(string $action, string $needle): string => 'SELECT COUNT(*) AS n FROM admin_activity_log WHERE action = ' . $q($action)
    . ' AND details LIKE ' . $q('%' . $needle . '%');
$surfaces = [
    'Conference enquiry' => [
        'page' => 'admin/conference-management.php', 'ajax' => false,
        'setup' => ["INSERT INTO conference_inquiries (inquiry_reference, conference_room_id, company_name, contact_person, email, phone, event_date, start_time, end_time, number_of_attendees, status, notes)
            VALUES (" . $q($ref('C')) . ", $roomId, 'Cret Test Co', 'Cret Test', 'cretc@example.invalid', '0999000001', " . $q($future) . ", '09:00:00', '12:00:00', 10, 'pending', 'Earlier staff note')"],
        'id' => 'SELECT id FROM conference_inquiries WHERE inquiry_reference = ' . $q($ref('C')),
        'post' => ['enquiry_action' => 'cancel', 'enquiry_id' => '{id}'],
        'before' => 'pending', 'notes_start' => "Earlier staff note\n[",
        'status' => 'SELECT status, notes FROM conference_inquiries WHERE inquiry_reference = ' . $q($ref('C')),
        'log' => ['conference_cancelled', $ref('C')], 'preset' => rh_cancel_reason_presets('conference')[0],
    ],
    'Event booking' => [
        'page' => 'admin/events-inquiries.php', 'ajax' => false,
        'setup' => ["INSERT INTO event_inquiries (reference_number, event_id, name, email, phone, guests, status, notes)
            VALUES (" . $q($ref('E')) . ", 0, 'Cret Guest', 'crete@example.invalid', '0999000002', 2, 'pending', NULL)"],
        'id' => 'SELECT id FROM event_inquiries WHERE reference_number = ' . $q($ref('E')),
        'post' => ['inquiry_action' => 'cancel', 'inquiry_id' => '{id}'],
        'before' => 'pending', 'notes_start' => '[',
        'status' => 'SELECT status, notes FROM event_inquiries WHERE reference_number = ' . $q($ref('E')),
        'log' => ['event_cancelled', $ref('E')], 'preset' => rh_cancel_reason_presets('event')[0],
    ],
    'Gym inquiry' => [
        'page' => 'admin/gym-inquiries.php', 'ajax' => false,
        'setup' => ["INSERT INTO gym_inquiries (reference_number, name, email, phone, membership_type, status, notes)
            VALUES (" . $q($ref('G')) . ", 'Cret Gym', 'cretg@example.invalid', '0999000003', 'Monthly', 'new', " . $q('[member:' . $ref('X') . ']') . ")"],
        'id' => 'SELECT id FROM gym_inquiries WHERE reference_number = ' . $q($ref('G')),
        'post' => ['inquiry_action' => 'cancel', 'inquiry_id' => '{id}'],
        'before' => 'new', 'notes_start' => '[member:' . $ref('X') . "]\n[",
        'status' => 'SELECT status, notes FROM gym_inquiries WHERE reference_number = ' . $q($ref('G')),
        'log' => ['gym_cancelled', $ref('G')], 'preset' => rh_cancel_reason_presets('gym')[0],
    ],
];
$memberSetup = ["INSERT INTO gym_members (member_number, full_name, email, phone, membership_type, start_date, expiry_date, status, monthly_fee, is_complimentary)
    VALUES (" . $q($ref('M')) . ", 'Cret Member', 'cretm@example.invalid', NULL, 'Complimentary', " . $q($today) . ", " . $q($future) . ", 'active', 0, 1)"];
$memberId = 'SELECT id FROM gym_members WHERE member_number = ' . $q($ref('M'));
$memberStatus = 'SELECT status, notes FROM gym_members WHERE member_number = ' . $q($ref('M'));
$surfaces['Gym member (status action)'] = [
    'page' => 'admin/gym-members.php', 'ajax' => true, 'setup' => $memberSetup, 'id' => $memberId,
    'post' => ['gm_action' => 'member_status', 'id' => '{id}', 'status' => 'cancelled'],
    'before' => 'active', 'notes_start' => null, 'status' => $memberStatus,
    'log' => ['gym_member_cancelled', $ref('M')], 'history' => true, 'preset' => rh_cancel_reason_presets('gym')[1],
];
$surfaces['Gym member (Edit, status set to Cancelled)'] = [
    'page' => 'admin/gym-members.php', 'ajax' => true, 'setup' => $memberSetup, 'id' => $memberId,
    'post' => ['gm_action' => 'member_save', 'id' => '{id}', 'full_name' => 'Cret Member', 'email' => 'cretm@example.invalid', 'phone' => '',
        'package_id' => '0', 'membership_type' => 'Complimentary', 'is_complimentary' => '1', 'start_date' => $today, 'expiry_date' => $future,
        'monthly_fee' => '0', 'status' => 'cancelled', 'bill_renewal' => '0', 'change_reason' => '', 'notes' => ''],
    'before' => 'active', 'notes_start' => null, 'status' => $memberStatus,
    'log' => ['gym_member_cancelled', $ref('M')], 'history' => true, 'preset' => rh_cancel_reason_presets('gym')[2],
];
$surfaces['Gym slot booking'] = [
    'page' => 'admin/gym-schedule.php', 'ajax' => true,
    'setup' => ["INSERT INTO gym_slot_reservations (reference, slot_date, slot_time, member_number, full_name, email, party_size, status, source)
        VALUES (" . $q($ref('S')) . ", " . $q(date('Y-m-d', strtotime($today . ' +2 days'))) . ", '07:00:00', NULL, 'Cret Slot', 'crets@example.invalid', 1, 'booked', 'admin')"],
    'id' => 'SELECT id FROM gym_slot_reservations WHERE reference = ' . $q($ref('S')),
    'post' => ['gs_action' => 'cancel_reservation', 'id' => '{id}'],
    'before' => 'booked', 'notes_start' => null,
    'status' => 'SELECT status, NULL AS notes FROM gym_slot_reservations WHERE reference = ' . $q($ref('S')),
    'log' => ['gym_slot_cancelled', $ref('S')], 'preset' => 'Member called to cancel the slot',
];

foreach ($surfaces as $label => $s) {
    $verify = static function (string $why) use ($s, $actLog, $q, $ref): array {
        return [
            $s['status'],
            $actLog($s['log'][0], $s['log'][1] . ' '),
            $actLog($s['log'][0], 'Reason: ' . $why),
            'SELECT COUNT(*) AS n FROM gym_member_audit_log WHERE member_number = ' . $q($ref('M')) . ' AND note LIKE ' . $q('%Cancellation reason: ' . $why . '%'),
        ];
    };
    $run = static function (array $extra, string $why) use ($s, $verify): array {
        return ce_run($s['page'], ['anon' => false, 'ajax' => $s['ajax'], 'setup' => $s['setup'], 'vars' => ['id' => $s['id']],
            'post' => $s['post'] + $extra, 'verify' => $verify($why)]);
    };
    $status = static fn(array $r): string => (string)($r['verify'][0][0]['status'] ?? '?');
    $notes = static fn(array $r): string => (string)($r['verify'][0][0]['notes'] ?? '');
    $n = static fn(array $r, int $i): int => (int)($r['verify'][$i][0]['n'] ?? -1);

    // Refusals: nothing written (status, notes, System Logs).
    $origNotes = null;
    foreach (['empty' => '', '4 characters' => 'abcd', '"Cancelled by admin"' => 'Cancelled by admin'] as $kind => $bad) {
        $r = $run(['cancellation_reason' => $bad], $bad === '' ? '__none__' : $bad);
        if ($origNotes === null) { $origNotes = $notes($r); }
        $msgOk = !$s['ajax'] || (empty($r['json']['success']) && strpos((string)($r['json']['message'] ?? ''), 'Nothing was changed') !== false);
        ok("$label: $kind reason refused, nothing changed", $status($r) === $s['before'] && $notes($r) === $origNotes && $n($r, 1) === 0 && $msgOk,
            json_encode([$r['json'] ?? null, $r['verify'] ?? null, $r['output_flash'] ?? null, $r['error'] ?? null]));
    }
    // No reason field at all.
    $r = $run([], '__none__');
    ok("$label: no reason field refused", $status($r) === $s['before'] && $n($r, 1) === 0, json_encode([$r['json'] ?? null, $r['verify'] ?? null]));

    // A valid reason (a ready-made reason with a few words added): cancelled and kept where staff see it.
    $why = $s['preset'] . ' - CRET ' . $tag;
    $r = $run(['cancellation_reason' => '  ' . $s['preset'] . " -  CRET\n" . $tag . ' '], $why);
    $okStatus = $status($r) === 'cancelled' && (!$s['ajax'] || !empty($r['json']['success']));
    ok("$label: valid reason -> cancelled", $okStatus, json_encode([$r['json'] ?? null, $r['verify'] ?? null, $r['output_flash'] ?? null, $r['raw'] ?? null]));
    ok("$label: System Logs > Admin activity has one row with the reference and the reason", $n($r, 1) === 1 && $n($r, 2) === 1, json_encode($r['verify'] ?? null));
    if ($s['notes_start'] !== null) {
        $nt = $notes($r);
        ok("$label: the record's Notes keep what was there and add who / why", strpos($nt, $s['notes_start']) === 0
            && substr($nt, -strlen('. Reason: ' . $why)) === '. Reason: ' . $why && strpos($nt, 'Cancelled by ') !== false, $nt);
    }
    if (!empty($s['history'])) {
        ok("$label: the member change history carries the reason", $n($r, 3) === 1, json_encode($r['verify'] ?? null));
    }
}

// Cancelling a gym member who is already cancelled (status buttons): no reason asked, nothing logged twice.
$r = ce_run('admin/gym-members.php', ['anon' => false, 'ajax' => true,
    'setup' => array_merge($memberSetup, ['UPDATE gym_members SET status = \'cancelled\' WHERE member_number = ' . $q($ref('M'))]),
    'vars' => ['id' => $memberId], 'post' => ['gm_action' => 'member_status', 'id' => '{id}', 'status' => 'cancelled'],
    'verify' => [$actLog('gym_member_cancelled', $ref('M') . ' ')]]);
ok('Gym member already cancelled: no reason needed, no new log row', !empty($r['json']['success']) && (int)($r['verify'][0][0]['n'] ?? -1) === 0, json_encode([$r['json'] ?? null, $r['verify'] ?? null]));

// Suspending a member needs no reason (only cancelling does).
$r = ce_run('admin/gym-members.php', ['anon' => false, 'ajax' => true, 'setup' => $memberSetup, 'vars' => ['id' => $memberId],
    'post' => ['gm_action' => 'member_status', 'id' => '{id}', 'status' => 'suspended'], 'verify' => [$memberStatus]]);
ok('Gym member Suspend: no reason needed', !empty($r['json']['success']) && (string)($r['verify'][0][0]['status'] ?? '') === 'suspended', json_encode([$r['json'] ?? null, $r['verify'] ?? null]));

// ---- leak check: nothing from this run reached the database -----------------------------------------------------------
$like = $q('CRET-' . $tag . '-%');
$left = (int)$pdo->query("SELECT
    (SELECT COUNT(*) FROM conference_inquiries WHERE inquiry_reference LIKE $like)
  + (SELECT COUNT(*) FROM event_inquiries WHERE reference_number LIKE $like)
  + (SELECT COUNT(*) FROM gym_inquiries WHERE reference_number LIKE $like)
  + (SELECT COUNT(*) FROM gym_members WHERE member_number LIKE $like)
  + (SELECT COUNT(*) FROM gym_slot_reservations WHERE reference LIKE $like)
  + (SELECT COUNT(*) FROM gym_member_audit_log WHERE member_number LIKE $like)
  + (SELECT COUNT(*) FROM admin_activity_log WHERE details LIKE " . $q('%CRET-' . $tag . '-%') . ")")->fetchColumn();
ok('leak check: no CRET-' . $tag . ' row left anywhere', $left === 0, (string)$left);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
