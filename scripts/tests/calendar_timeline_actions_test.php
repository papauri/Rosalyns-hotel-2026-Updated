<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Room Timeline step B: changing bookings from the timeline (admin/js/room-timeline.js + admin/includes/calendar-data.php).
 * Usage (repo root, TEST HOTEL):  php scripts/tests/calendar_timeline_actions_test.php
 * SELECT / code-path only: nothing is written (the nights quote runs without its availability transaction).
 * The timeline has no save code of its own: every change posts an existing admin/bookings.php action. Cases:
 *  (a) a move goes through assign_individual_room with the keys bookings.php reads; the precheck only lets through a
 *      room the server's own candidate list marks available; the server gates refuse a taken room / another type's room
 *  (b) closed, past, joined and group bookings carry drag move=false end=false (pure rules, synthetic item, live
 *      payload, rendered DOM attributes in the JS)
 *  (c) Confirm is single-flight (st.busy guard, disabled button) in the JS
 *  (d) the dates confirm shows and posts the stay_nights_quote figures unchanged (quote keys exist and are stable)
 *  (e) both JSON outputs use JSON_INVALID_UTF8_SUBSTITUTE; can.nights follows the stay_nights_apply permission
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/admin/includes/calendar-data.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

$js = (string)file_get_contents($root . '/admin/js/room-timeline.js');
$bk = (string)file_get_contents($root . '/admin/bookings.php');
$today = date('Y-m-d');
$plus = static fn (int $d): string => date('Y-m-d', strtotime($today . ' ' . ($d >= 0 ? '+' : '') . $d . ' day'));

// ---------------------------------------------------------------- (b) pure drag rules
$R = static fn (string $s, string $ci, string $co, bool $j = false, bool $g = false, bool $m = false): array
    => rh_cal_drag_rules($s, $ci, $co, $today, $j, $g, $m);
$locked = static fn (array $r): bool => $r['move'] === false && $r['end'] === false && empty($r['dates']) && $r['why'] !== '';
// Owner 2026-11-28: pending / tentative change dates by drag (Change dates hand-off) but never move room.
$datesOnly = static fn (array $r): bool => $r['move'] === false && $r['end'] === false && ($r['dates'] ?? false) === true;

$r = $R('confirmed', $plus(3), $plus(5));
ok('(b) confirmed future stay: move yes, end no (dates from the booking)', $r['move'] === true && $r['end'] === false);
$r = $R('checked-in', $plus(-1), $plus(2));
ok('(b) in-house stay: end grip yes, move no (clean-room rule lives in the booking)', $r['move'] === false && $r['end'] === true);
ok('(b) checked-out is locked', $locked($R('checked-out', $plus(-3), $plus(-1))));
ok('(b) cancelled is locked', $locked($R('cancelled', $plus(3), $plus(5))));
ok('(b) no-show is locked', $locked($R('no-show', $plus(-3), $plus(-1))));
ok('(b) joined booking is locked', $locked($R('confirmed', $plus(3), $plus(5), true)));
ok('(b) joined in-house booking is locked', $locked($R('checked-in', $plus(-1), $plus(2), true)));
ok('(b) group booking is locked', $locked($R('confirmed', $plus(3), $plus(5), false, true)));
ok('(b) group in-house booking is locked', $locked($R('checked-in', $plus(-1), $plus(2), false, true)));
ok('(b) missed arrival is locked', $locked($R('confirmed', $plus(-2), $plus(1), false, false, true)));
ok('(b) arrival in the past (not checked in) is locked', $locked($R('confirmed', $plus(-1), $plus(2))));
ok('(b) in-house past check-out (overdue) is locked', $locked($R('checked-in', $plus(-4), $plus(-1))));
ok('(b) pending: dates drag only (no room move)', $datesOnly($R('pending', $plus(3), $plus(5))));
ok('(b) tentative: dates drag only (no room move)', $datesOnly($R('tentative', $plus(3), $plus(5))));
ok('(b) pending with a passed arrival is locked', $locked($R('pending', $plus(-1), $plus(2))));
ok('(b) tentative group booking is locked', $locked($R('tentative', $plus(3), $plus(5), false, true)));
ok('(b) closed wins over everything (checked-out + joined + group)', $R('checked-out', $plus(-3), $plus(-1), true, true)['why'] === 'This stay is closed. Open the booking to see it.');

// ---------------------------------------------------------------- (b) synthetic booking item
$synth = [
    'id' => 999999001, 'booking_reference' => 'TEST-TL', 'guest_name' => 'Test Guest', 'status' => 'confirmed',
    'room_id' => 1, 'room_name' => 'Test', 'check_in_date' => $plus(3), 'check_out_date' => $plus(5),
    'effective_check_out' => $plus(5), 'individual_room_id' => 1, 'individual_room_number' => '1',
    'number_of_guests' => 2, 'total_amount' => 100, 'amount_paid' => 0, 'amount_due' => 100,
];
$cur = (string)getSetting('currency_symbol');
$plain = rh_cal_booking_item($synth, [999999001 => [1 => true]], [1 => 1], [1 => [1]], [], [], $today, $cur);
ok('(b) synthetic single-room confirmed item is movable', !empty($plain['drag']['move']), json_encode($plain['drag']));
$j = rh_cal_booking_item($synth + ['room_combination_id' => 5], [999999001 => [1 => true]], [1 => 1], [1 => [1]], [], [], $today, $cur);
ok('(b) synthetic item with room_combination_id: drag locked', $locked($j['drag']), json_encode($j['drag']));
$jl = rh_cal_booking_item($synth, [999999001 => [1 => true, 2 => true]], [1 => 1, 2 => 1], [1 => [1, 2]], [999999001 => '1 + 2'], [], $today, $cur);
ok('(b) synthetic item on a joined lane: drag locked', $locked($jl['drag']), json_encode($jl['drag']));
$gr = rh_cal_booking_item($synth, [999999001 => [1 => true]], [1 => 1], [1 => [1]], [], [999999001 => ['size' => 3, 'ref' => 'G1']], $today, $cur);
ok('(b) synthetic group item: drag locked', $locked($gr['drag']), json_encode($gr['drag']));
// A booking of a joined-room type with no pair yet (no lane): the move would post a pair id as a room id. Locked.
$comboTypeId = (int)$pdo->query("SELECT combined_room_type_id FROM room_combinations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if ($comboTypeId > 0) {
    $un = rh_cal_booking_item(['room_id' => $comboTypeId, 'individual_room_id' => null, 'individual_room_number' => ''] + $synth, [], [], [], [], [], $today, $cur);
    ok('(b) synthetic unassigned booking of a joined-room type: drag locked', $locked($un['drag']) && $un['joined'] === true, json_encode($un['drag']));
} else {
    ok('(b) no joined-room type on this hotel (joined-type case skipped)', true);
}
ok('(b) JS: a joined-pair candidate list (mode combinations) is refused, never posted as a room id',
    strpos($js, "if (j.mode && j.mode !== 'rooms') throw new Error(") !== false);

// ---------------------------------------------------------------- (b) live payload (this fortnight, read-only)
$payload = rh_cal_timeline_payload($pdo, $today, 14, ['today' => $today]);
$bad = [];
$movable = [];
foreach ($payload['bookings'] as $b) {
    $d = $b['drag'] ?? null;
    if (!is_array($d) || !array_key_exists('move', $d) || !array_key_exists('end', $d)) {
        $bad[] = $b['id'] . ' no drag block';
        continue;
    }
    $mustLock = $b['joined'] || $b['group'] || $b['missed'] || $b['overdue']
        || in_array($b['status'], ['checked-out', 'cancelled', 'no-show', 'pending', 'tentative'], true)
        || ($b['status'] !== 'checked-in' && $b['ci'] < $today);
    if ($mustLock && ($d['move'] || $d['end'])) {
        $bad[] = $b['id'] . ' ' . $b['status'];
    }
    $mustLockDates = $b['joined'] || $b['group'] || $b['missed'] || $b['overdue']
        || !in_array($b['status'], ['confirmed', 'pending', 'tentative'], true) || $b['ci'] < $today;
    if ($mustLockDates && !empty($d['dates'])) {
        $bad[] = $b['id'] . ' dates on ' . $b['status'];
    }
    if ($d['move'] && $b['status'] !== 'confirmed') {
        $bad[] = $b['id'] . ' move on ' . $b['status'];
    }
    if ($d['end'] && $b['status'] !== 'checked-in') {
        $bad[] = $b['id'] . ' end on ' . $b['status'];
    }
    if ($d['move'] && count($b['lanes']) === 1) {
        $movable[] = $b;
    }
}
ok('(b) live payload: ' . count($payload['bookings']) . ' bookings, every closed / past / joined / group bar is locked', $bad === [], implode(', ', array_slice($bad, 0, 8)));
ok('(b) payload can.nights exists and is false for nobody signed in', array_key_exists('nights', $payload['can']) && $payload['can']['nights'] === false);
ok('(e) can.nights uses the stay_nights_apply permission', (bool)preg_match("/'nights'\\s*=>\\s*\\\$bookingsPage && \\\$perm\\(\\(string\\)rhBookingActionPermission\\('stay_nights_apply'\\)\\)/", (string)file_get_contents($root . '/admin/includes/calendar-data.php')));

// ---------------------------------------------------------------- (b) DOM attributes come from the rules
ok('(b) JS: every bar is draggable="false" (no native drag) and data-tl-draggable comes from the rules and the device',
    strpos($js, "bar.setAttribute('draggable', 'false')") !== false
    && strpos($js, "bar.setAttribute('data-tl-draggable', (dMove || dEnd) ? 'true' : 'false')") !== false
    // GRID_UNIFY_PLAN Phase 3: a confirmed stay's date drag (rules.dates) also makes the bar and its edges draggable.
    && (bool)preg_match('/dMove\s*=\s*desk\s*&&\s*\(rules\.move\s*\|\|\s*rules\.dates\)/', $js)
    && (bool)preg_match('/dEnd\s*=\s*desk\s*&&\s*\(rules\.end\s*\|\|\s*rules\.dates\)/', $js)
    && (bool)preg_match('/dStart\s*=\s*desk\s*&&\s*rules\.dates/', $js));
ok('(b) JS: a date drag on a confirmed stay only opens the booking\'s own Change dates (prefilled), nothing is saved on the timeline',
    (bool)preg_match("/dates: !!dr\\.dates && !!can\\.edit_dates/", $js)
    && strpos($js, "'booking-details.php?id=' + encodeURIComponent(b.id) + '&open=dates&ci=' + ci + '&co=' + co") !== false
    && !preg_match('/function handOffDates[\s\S]{0,1200}postAction\(/', $js));
ok('(b) JS: a drag starts only on a bar marked data-tl-draggable="true", mouse only, desktop only',
    strpos($js, "if (bar.getAttribute('data-tl-draggable') !== 'true') return;") !== false
    && strpos($js, "if (e.pointerType !== 'mouse' || !canDrag() || st.busy || st.pending) return;") !== false);
ok('(b) JS: rulesOf applies the user permissions on top of the server rules',
    (bool)preg_match('/function rulesOf\(st, b\)[\s\S]{0,400}can\.move[\s\S]{0,200}can\.nights/', $js)
    || (bool)preg_match('/function rulesOf\(st, b\)[\s\S]{0,400}can\.nights[\s\S]{0,200}can\.move/', $js));

// ---------------------------------------------------------------- (a) move wiring + server gates
ok('(a) JS: same-type move posts assign_individual_room with booking_id + individual_room_id',
    strpos($js, "postAction({ action: 'assign_individual_room', booking_id: b.id, individual_room_id: lane.id })") !== false);
ok('(a) bookings.php reads booking_id + individual_room_id for assign_individual_room',
    (bool)preg_match("/action === 'assign_individual_room'[\\s\\S]{0,600}\\\$_POST\\['booking_id'\\][\\s\\S]{0,120}\\\$_POST\\['individual_room_id'\\]/", $bk));
ok('(a) JS: the precheck asks get_available_rooms for the booking\'s own dates, excluding itself',
    strpos($js, "postAction({ action: 'get_available_rooms', room_type_id: typeId, check_in: b.ci, check_out: b.co, exclude_booking_id: b.id })") !== false);
ok('(a) JS: a room not in the list, the current room, a taken room or a no-children room is refused before any save',
    strpos($js, "if (!hit) throw new Error(") !== false && strpos($js, 'if (hit.current) throw') !== false
    && strpos($js, 'if (!hit.available) throw') !== false && strpos($js, 'if (hit.requires_child_override) throw') !== false);
ok('(a) JS: a type change posts upgrade_room_type (the repricing flow) with the keys bookings.php reads, no email',
    strpos($js, "action: 'upgrade_room_type', booking_id: b.id, new_room_id: lane.type_id, pricing: 'charge',") !== false
    && strpos($js, "reason_code: vals.reason, note: vals.note, send_email: '0'") !== false
    && (bool)preg_match("/action === 'upgrade_room_type'[\\s\\S]{0,800}\\\$_POST\\['new_room_id'\\][\\s\\S]{0,200}\\\$_POST\\['pricing'\\][\\s\\S]{0,200}\\\$_POST\\['reason_code'\\][\\s\\S]{0,200}\\\$_POST\\['note'\\]/", $bk));
ok('(a) JS: posts carry the CSRF token and the XMLHttpRequest header (bookings.php AJAX actions)',
    strpos($js, "'X-Requested-With': 'XMLHttpRequest'") !== false && strpos($js, 'csrf_token') !== false);
ok('(a) JS: no price, folio or payment action of its own',
    !preg_match("/action:\\s*'(record_payment|add_payment|payment|add_folio|folio_|adjust_total|update_price)/", $js));

if ($movable === []) {
    ok('(a) live: a movable single-room booking exists this fortnight (skipped: none)', true);
} else {
    $mb = $movable[0];
    $cand = rh_room_assignment_candidates((int)$mb['type_id'], $mb['ci'], $mb['co'], (int)$mb['id']);
    $items = $cand['items'] ?? [];
    ok('(a) live: candidate list for ' . $mb['ref'] . ' is the rooms mode (not a joined type)', ($cand['mode'] ?? '') === 'rooms');
    $byId = [];
    foreach ($items as $it) { $byId[(int)$it['id']] = $it; }
    $currentOk = true;
    foreach (getBookingRoomIds((int)$mb['id']) as $rid) {
        if (isset($byId[(int)$rid]) && empty($byId[(int)$rid]['current'])) { $currentOk = false; }
    }
    ok('(a) live: the booking\'s own room(s) are marked current (the drag refuses them)', $currentOk);
    $allowed = array_values(array_filter($items, static fn ($i) => $i['available'] && !$i['current']));
    $refused = array_values(array_filter($items, static fn ($i) => !$i['available'] && !$i['current']));
    if ($allowed !== []) {
        $chk = checkIndividualRoomAvailability((int)$allowed[0]['id'], $mb['ci'], $mb['co'], (int)$mb['id']);
        $pol = getIndividualRoomEffectivePolicy((int)$mb['type_id'], (int)$allowed[0]['id']);
        ok('(a) live: an allowed room (' . $allowed[0]['label'] . ') passes the same gates assignIndividualRoomToBooking runs',
            !empty($chk['available']) && $pol !== null);
    } else {
        ok('(a) live: no free same-type room for ' . $mb['ref'] . ' (allowed-room gate skipped)', true);
    }
    if ($refused !== []) {
        $chk = checkIndividualRoomAvailability((int)$refused[0]['id'], $mb['ci'], $mb['co'], (int)$mb['id']);
        $need = rh_room_needed_for_joined((int)$refused[0]['id'], $mb['ci'], $mb['co'], (int)$mb['id']);
        ok('(a) live: a room not allowed (' . $refused[0]['label'] . ': ' . $refused[0]['reason'] . ') is refused by the server too',
            empty($chk['available']) || $need !== null);
    } else {
        ok('(a) live: every same-type room is free (refused-room gate covered by the other-type case)', true);
    }
    $other = $pdo->prepare("SELECT id FROM individual_rooms WHERE room_type_id <> ? AND is_active = 1 ORDER BY id LIMIT 1");
    $other->execute([(int)$mb['type_id']]);
    $otherId = (int)$other->fetchColumn();
    if ($otherId > 0) {
        ok('(a) live: another type\'s room is refused by the assign gate (no policy for this type)',
            getIndividualRoomEffectivePolicy((int)$mb['type_id'], $otherId) === null);
        ok('(a) live: another type\'s room is not in this booking\'s candidate list', !isset($byId[$otherId]));
    }
}

// ---------------------------------------------------------------- (c) single flight
ok('(c) JS: Confirm returns at once while a save runs, then sets st.busy and disables the button',
    (bool)preg_match("/st\\.pending\\.confirm = function \\(\\) \\{\\s*if \\(st\\.busy\\) return;[\\s\\S]{0,300}st\\.busy = true;\\s*btn\\.disabled = true;/", $js));
ok('(c) JS: Cancel is disabled while saving and the drawer cannot close mid-save',
    strpos($js, 'if (cancelBtn) cancelBtn.disabled = true;') !== false
    && substr_count($js, 'if (st.busy) return') >= 2);
ok('(c) JS: a failed save re-enables Confirm and shows the error in the panel (no silent retry)',
    (bool)preg_match('/st\.busy = false;\s*btn\.disabled = false;[\s\S]{0,200}showErr\(/', $js));
ok('(c) JS: after a save the range is fetched again (force) keeping the scroll position',
    strpos($js, 'st.keepScroll') !== false && (bool)preg_match('/function finishChange[\s\S]{0,600}go\([^)]*true\)/', $js));

// ---------------------------------------------------------------- (d) quote shown = quote posted = stay_nights_quote
ok('(d) JS: the quote is asked with stay_nights_quote (booking_id, delta)',
    strpos($js, "postAction({ action: 'stay_nights_quote', booking_id: b.id, delta: delta, un_fee_override: '', un_fee_reason: '' })") !== false);
ok('(d) JS: the confirm shows the quote\'s old_total -> new_total and balance figures as returned',
    strpos($js, "['Price', money(st, qt.old_total) + ' to ' + money(st, qt.new_total), ") !== false
    && strpos($js, "['Balance owed', money(st, qt.old_due) + ' to ' + money(st, qt.new_due), ") !== false);
ok('(d) JS: the confirm money rows carry a "?" helper', strpos($js, "if (ln[2]) list.lastChild.querySelector('dt').appendChild(help(ln[2]));") !== false);
ok('(d) JS: apply posts the exact quoted figures (bookings.php re-quotes and refuses a mismatch)',
    strpos($js, "action: 'stay_nights_apply', booking_id: b.id, delta: qt.delta,") !== false
    && strpos($js, 'expected_new_total: qt.new_total, expected_total_delta: qt.total_delta, reason: vals.reason') !== false
    && strpos($bk, "'expected_new_total' => \$_POST['expected_new_total'] ?? null") !== false
    && strpos($bk, "'expected_total_delta' => \$_POST['expected_total_delta'] ?? null") !== false
    && strpos($bk, "'un_fee_quoted' => (string)(\$_POST['un_fee_quoted'] ?? '')") !== false);
ok('(d) JS: money going back to the guest is refused here (done from the booking, where refund / credit is chosen)',
    strpos($js, 'if (Number(qt.money_back || 0) > 0.01) {') !== false);

require_once $root . '/includes/stay-nights.php';
$ih = $pdo->query("SELECT * FROM bookings WHERE status = 'checked-in' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$ih) {
    ok('(d) live: no in-house booking to quote (skipped)', true);
} else {
    // As if today were the first night, so the stay is current; no availability transaction (pure read).
    $qDay = (string)$ih['check_in_date'];
    $q1 = rh_stay_nights_quote($pdo, $ih, 1, $qDay, false, $cur);
    $q2 = rh_stay_nights_quote($pdo, $ih, 1, $qDay, false, $cur);
    if (empty($q1['ok'])) {
        ok('(d) live: quote for ' . $ih['booking_reference'] . ' not available (' . $q1['error'] . '), key check skipped', true);
    } else {
        $need = ['delta', 'old_check_out', 'new_check_out', 'old_nights', 'new_nights', 'old_total', 'new_total', 'total_delta',
            'folio', 'paid', 'old_due', 'new_due', 'money_back', 'plan_note', 'unused'];
        $missing = array_values(array_diff($need, array_keys($q1)));
        ok('(d) live: stay_nights_quote returns every figure the confirm shows', $missing === [], implode(',', $missing));
        ok('(d) live: the same quote twice gives the same new_total / total_delta (what apply checks against)',
            abs((float)$q1['new_total'] - (float)$q2['new_total']) < BALANCE_TOLERANCE
            && abs((float)$q1['total_delta'] - (float)$q2['total_delta']) < BALANCE_TOLERANCE);
        ok('(d) live: total_delta = new_total - old_total',
            abs(((float)$q1['new_total'] - (float)$q1['old_total']) - (float)$q1['total_delta']) < BALANCE_TOLERANCE);
    }
}

// ---------------------------------------------------------------- (e) JSON flags
ok('(e) range API json_encode uses JSON_INVALID_UTF8_SUBSTITUTE',
    strpos((string)file_get_contents($root . '/admin/api/calendar-timeline.php'), 'json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)') !== false);
ok('(e) first-paint JSON in the view uses JSON_INVALID_UTF8_SUBSTITUTE',
    strpos((string)file_get_contents($root . '/admin/includes/calendar-timeline-view.php'), 'JSON_INVALID_UTF8_SUBSTITUTE') !== false);
$enc = json_encode(['g' => "bad \xB1 byte"], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
ok('(e) a stray non-UTF-8 byte in a guest name no longer blanks the payload', is_string($enc) && $enc !== '');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
