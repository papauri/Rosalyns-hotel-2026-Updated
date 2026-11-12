<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * System Logs > Staff activity: admin/includes/staff-activity-lib.php (filters, timeline, summary, CSV) and the
 * hook admin/includes/activity-hook.php.
 * Usage (from the repo root): php scripts/tests/system_logs_activity.php
 * Library checks insert fixture rows for two made-up staff ids (987654, 987655) on days in 2020 inside one
 * transaction that is rolled back (no DDL runs in the library). The hook checks run a child process per request and
 * remove the rows it wrote (marker address 203.0.113.77).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/config/database.php';
require_once $root . '/includes/licence-guard.php';
require_once $root . '/admin/includes/staff-activity-lib.php';
require_once $root . '/admin/includes/activity-hook.php';

const A = 987654;
const B = 987655;
const D1 = '2020-03-05';
const D2 = '2020-03-06';

// ---- Filters ----
$f = rh_sa_parse_filters([], '2026-11-07');
check($f['from'] === '2026-11-07' && $f['to'] === '2026-11-07' && $f['user'] === 0 && $f['type'] === 'all' && !$f['filtered'], 'default: today, everyone, everything, older panels unchanged');
$f = rh_sa_parse_filters(['sa' => '1', 'range' => 'yesterday'], '2026-11-07');
check($f['from'] === '2026-11-06' && $f['to'] === '2026-11-06' && $f['filtered'], 'yesterday button');
$f = rh_sa_parse_filters(['range' => '7d'], '2026-11-07');
check($f['from'] === '2026-11-01' && $f['to'] === '2026-11-07', 'last 7 days is 7 days including today');
$f = rh_sa_parse_filters(['from' => '2026-11-05', 'to' => '2026-11-02', 'user' => '12', 'type' => 'stock', 'p' => '3'], '2026-11-07');
check($f['from'] === '2026-11-02' && $f['to'] === '2026-11-05' && $f['user'] === 12 && $f['type'] === 'stock' && !isset($f['page']), 'range swapped when backwards; user, type read; an old ?p= is ignored (paging is sa_page)');
$f = rh_sa_parse_filters(['from' => '2026-13-45', 'to' => 'x', 'type' => 'evil', 'user' => '-4', 'p' => '9999'], '2026-11-07');
check($f['from'] === '2026-11-07' && $f['type'] === 'all' && $f['user'] === 0, 'bad date / type / user fall back safely');

// ---- Timeline pager (pure): shared pager, key 'sa', newest 1000 lines reachable ----
$pg = rh_sa_pager([], 25);
check($pg['size'] === 10 && $pg['pages'] === 3 && $pg['page'] === 1 && $pg['more'] && rh_lp_summary($pg, 10) === "Showing 1\u{2013}10 of 25", 'pager: 10 per page by default, total shown', rh_lp_summary($pg, 10));
$pg = rh_sa_pager(['sa_page' => '9'], 25);
check($pg['page'] === 3 && $pg['offset'] === 20 && !$pg['more'], 'pager: a page past the end moves to the last page');
$pg = rh_sa_pager(['sa_page' => '500'], 2345);
check($pg['pages'] === 100 && $pg['page'] === 100 && $pg['offset'] === 990 && $pg['beyond'] && rh_lp_summary($pg, 10) === "Showing 991\u{2013}1,000 of 2,345", 'pager: over 1000 lines, pages stop at 1000 but the real total is shown', rh_lp_summary($pg, 10));
check(strpos(rh_sa_pager_note($pg), 'Only the newest 1000 lines can be paged through') === 0 && rh_sa_pager_note(rh_sa_pager([], 25)) === '', 'pager: depth note only when there is more than can be paged');
$pg = rh_sa_pager(['sa_size' => '25', 'sa_page' => '2'], 2345);
check($pg['size'] === 25 && $pg['pages'] === 40 && $pg['offset'] === 25, 'pager: size picker 25');
$pg = rh_sa_pager(['sa_size' => '100', 'sa_page' => '99'], 5000);
check($pg['page'] === 10 && $pg['offset'] === 900, 'pager: size 100 reaches line 1000 and no further');
$pg = rh_sa_pager([], 20000);
check($pg['capped'] && $pg['total'] === RH_SA_COUNT_CAP && strpos(rh_lp_summary($pg, 10), '10,000+') !== false, 'pager: total above the count cap reads 10,000+');
$pg = rh_sa_pager(['sa_page' => '3'], -1);
$pgM = rh_sa_pager_more($pg, true);
$pgL = rh_sa_pager_more($pg, false);
check($pg['unknown'] && $pg['page'] === 3 && $pgM['more'] && $pgM['pages'] === 4 && !$pgL['more'] && strpos(rh_lp_summary($pgM, 10), 'count unavailable') !== false, 'pager: count failed, Next follows the timeline');
$pgD = rh_sa_pager_more(rh_sa_pager(['sa_page' => '100'], -1), true);
check($pgD['page'] === 100 && !$pgD['more'], 'pager: count failed, Next never goes past line 1000');
$q = rh_sa_query(rh_sa_parse_filters(['user' => 5, 'from' => D1, 'to' => D1]), ['range' => 'today'],
    ['source' => 'pos', 'ev_page' => '3', 'sa_page' => '4', 'sad_page' => '2', 'sa_size' => '25', 'p' => '2', 'export' => 'csv', 'r' => '1', 'user' => '9']);
parse_str($q, $qa);
check(($qa['source'] ?? '') === 'pos' && ($qa['ev_page'] ?? '') === '3' && ($qa['sa_size'] ?? '') === '25' && ($qa['user'] ?? '') === '5'
    && !isset($qa['sa_page']) && !isset($qa['sad_page']) && !isset($qa['p']) && !isset($qa['export']) && !isset($qa['r']), 'links keep the other sections and sizes, restart the pages', $q);
check(rh_sa_counter_type('refunds') === 'payments' && rh_sa_counter_type('orders') === 'till' && rh_sa_counter_type('saves') === 'all', 'day-card figures open the matching kind of work');
check(rh_sa_who(['_uid' => 0, '_name' => ''], []) === 'System / unknown user' && rh_sa_who(['_uid' => 0, '_name' => 'Guest website'], []) === 'System / unknown user (Guest website)', 'lines with no staff account are labelled System / unknown user');
$f = rh_sa_parse_filters(['from' => '2026-01-01', 'to' => '2026-11-07'], '2026-11-07');
check($f['clamped'] && $f['to'] === '2026-11-07' && $f['from'] === '2026-10-08', 'range clamped to 31 days, newest days kept');
check(rh_sa_panel_clause(rh_sa_parse_filters([]), 'created_at', 'user_id') === ['', []], 'older panels: no extra condition until the filters are used');
$pc = rh_sa_panel_clause(rh_sa_parse_filters(['sa' => 1, 'user' => 5, 'from' => D1, 'to' => D1]), 'created_at', 'user_id', 'WHERE');
check(strpos($pc[0], 'WHERE') !== false && count($pc[1]) === 3 && $pc[1][2] === 5, 'older panels: person and day condition when used');
check(strpos(rh_sa_query(rh_sa_parse_filters(['user' => 5, 'from' => D1, 'to' => D1]), ['range' => 'today']), 'from=') === false, 'quick-date link carries range, not stale dates');

// ---- Fixtures (rolled back) ----
$pdo->beginTransaction();
try {
    $act = $pdo->prepare("INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, created_at) VALUES (?,?,?,?,?,?)");
    $act->execute([A, 'zz_a', 'login_success', 'Role: admin', '10.0.0.1', D1 . ' 08:00:00']);
    $act->execute([A, 'zz_a', 'page_action', 'page=bookings.php; action=update_status', '10.0.0.1', D1 . ' 09:00:00']);
    $act->execute([A, 'zz_a', 'page_action', 'page=booking-details.php; booking_action=checkin', '10.0.0.1', D1 . ' 09:05:00']);
    $act->execute([A, 'zz_a', 'page_action', 'page=gym-members.php', '10.0.0.1', D1 . ' 09:06:00']);
    $act->execute([A, 'zz_a', 'user_updated', 'Updated user 5 password=hunter2 token=abc', '10.0.0.1', D1 . ' 09:07:00']);
    $act->execute([A, 'zz_a', 'logout', '', '10.0.0.1', D1 . ' 17:00:00']);
    $act->execute([B, 'zz_b', 'login_success', 'Role: manager', '10.0.0.2', D1 . ' 10:00:00']);
    $act->execute([A, 'zz_a', 'login_success', 'Role: admin', '10.0.0.1', D2 . ' 08:30:00']);

    // booking_audit_log.booking_id is a foreign key to bookings (migration 078): give the audit row a real (rolled back) booking.
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES ('ZZ-1', (SELECT id FROM rooms ORDER BY id LIMIT 1), 'ZZ Activity Test', 'zz@example.invalid', '+265000000000', 'Malawi', 1, 1, 0, 50, '2020-03-05', '2020-03-06', 1, 100, 0, 0, 0, 0, 0, 100,
            'system logs test', 'pending', 'unpaid', 0, 'single', ?, NOW())")->execute([bin2hex(random_bytes(16))]);
    $zzBid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO booking_audit_log (booking_id,booking_reference,action,old_values,new_values,changed_fields,performed_by,performed_by_name,performed_at) VALUES (?,'ZZ-1','updated',?,?,?,?,?,?)")
        ->execute([$zzBid, '{"secret":"SECRET-OLD-VALUE"}', '{"secret":"SECRET-NEW-VALUE"}', '["check_in_date","password_hash","guest_name"]', A, 'Zed A', D1 . ' 09:30:00']);
    $pay = $pdo->prepare("INSERT INTO payments (payment_reference,booking_type,booking_id,booking_reference,payment_date,payment_amount,payment_method,payment_status,payment_type,recorded_by,created_at,total_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $pay->execute(['ZZP1', 'room', 1, 'ZZ-1', D1, 100000, 'cash', 'completed', 'full_payment', A, D1 . ' 10:00:00', 120000]);
    $pay->execute(['ZZP2', 'room', 1, 'ZZ-1', D1, 50000, 'cash', 'pending', 'full_payment', A, D1 . ' 10:01:00', 50000]);
    $pdo->prepare("INSERT INTO payments (payment_reference,booking_type,booking_id,booking_reference,payment_date,payment_amount,payment_method,payment_status,payment_type,recorded_by,created_at,total_amount,refund_amount,refund_status,refund_reason,original_payment_id) VALUES ('ZZR1','room',1,'ZZ-1',?,5000,'cash','completed','refund',?,?,6000,6000,'completed','overpayment',(SELECT id FROM (SELECT id FROM payments WHERE payment_reference = 'ZZP1') x))")
        ->execute([D1, A, D1 . ' 10:30:00']);
    $pdo->prepare("INSERT INTO stock_shift_closes (user_id,user_name,shift_date,closed_at,expected_cash,declared_cash) VALUES (?,?,?,?,?,?)")
        ->execute([A, 'Zed A', D1, D1 . ' 16:00:00', 100000, 90000]);
    $pdo->prepare("INSERT INTO stock_kds_events (order_id,order_item_id,event,from_status,to_status,user_id,user_name,created_at) VALUES (1,1,'ready','started','ready',?,?,?)")
        ->execute([B, 'Zed B', D1 . ' 11:00:00']);
    $pdo->prepare("INSERT INTO stock_orders (reference,order_type,status,total_amount,created_by,created_at) VALUES ('ZZO1','walk_in','placed',50,?,?)")
        ->execute([A, D1 . ' 12:00:00']);

    $fA = rh_sa_parse_filters(['user' => A, 'from' => D1, 'to' => D1]);
    $fAll = rh_sa_parse_filters(['from' => D1, 'to' => D1]);

    // ---- Timeline: person and day filters ----
    $t = rh_sa_timeline($pdo, $fA, 0, 100);
    $uids = array_unique(array_map(function ($r) { return $r['uid']; }, $t['rows']));
    check($t['rows'] && $uids === [A], 'person filter: only that person\'s rows', json_encode($uids));
    $days = array_unique(array_map(function ($r) { return substr($r['ts'], 0, 10); }, $t['rows']));
    check($days === [D1], 'day filter: only that day', json_encode($days));
    $tAll = rh_sa_timeline($pdo, $fAll, 0, 100);
    $uAll = array_unique(array_map(function ($r) { return $r['uid']; }, $tAll['rows']));
    sort($uAll);
    check(in_array(A, $uAll, true) && in_array(B, $uAll, true), 'Everyone: both people present');
    $t2 = rh_sa_timeline($pdo, rh_sa_parse_filters(['user' => A, 'from' => D2, 'to' => D2]), 0, 100);
    check(count($t2['rows']) === 1 && $t2['rows'][0]['what'] === 'Signed in', 'next day holds only that day\'s row');
    $tB = rh_sa_timeline($pdo, rh_sa_parse_filters(['user' => B, 'from' => D1, 'to' => D1]), 0, 100);
    check(count($tB['rows']) === 2, 'other person: sign-in and kitchen move only', json_encode(array_column($tB['rows'], 'what')));
    $tR = rh_sa_timeline($pdo, rh_sa_parse_filters(['user' => A, 'from' => D1, 'to' => D2]), 0, 100);
    check(count($tR['rows']) === count($t['rows']) + 1, 'two-day range adds the second day');

    // ---- Merge order, sources ----
    $times = array_column($t['rows'], 'ts');
    $sorted = $times; rsort($sorted);
    check($times === $sorted, 'timeline is newest first');
    $srcs = array_unique(array_column($t['rows'], 'src'));
    check(count(array_intersect($srcs, ['activity', 'booking', 'payment', 'refund', 'shift_close', 'order'])) === 6, 'timeline merges activity, booking, payment, refund, shift and order sources', implode(',', $srcs));
    $byWhat = array_column($t['rows'], 'what');
    check(in_array('Changed booking ZZ-1: check in date, guest name', $byWhat, true), 'booking sentence uses field names and drops secret-looking ones', implode(' | ', $byWhat));
    check(in_array('Recorded payment MWK 120,000 on ZZ-1 (cash)', $byWhat, true), 'payment sentence has the full amount', implode(' | ', $byWhat));
    check(in_array('Recorded refund MWK 6,000 on ZZ-1 (cash)', $byWhat, true), 'refund shown on its own with the full amount');
    check(in_array('Closed shift: expected MWK 100,000, declared MWK 90,000', $byWhat, true), 'shift close sentence');
    check(in_array('Checked a guest in (Booking Details)', $byWhat, true) && in_array('Changed a booking status (Bookings)', $byWhat, true), 'screen save sentences are friendly');
    $pend = array_filter($t['rows'], function ($r) { return strpos($r['what'], '50,000') !== false; });
    check(!$pend, 'a pending payment is not shown as recorded');
    $link = null;
    foreach ($t['rows'] as $r) { if ($r['src'] === 'booking') { $link = $r['link']; } }
    check($link && $link['page'] === 'booking-details.php' && $link['query'] === 'id=' . $zzBid, 'booking line links to the booking');

    // ---- Secrets never shown ----
    $dump = json_encode($t['rows']) . json_encode($tAll['rows']);
    check(strpos($dump, 'SECRET-OLD-VALUE') === false && strpos($dump, 'SECRET-NEW-VALUE') === false, 'old / new values never reach the timeline');
    check(strpos($dump, 'hunter2') === false && strpos($dump, 'password_hash') === false && stripos($dump, 'token=abc') === false, 'password / token text is dropped from details and field names');
    $mem = fopen('php://memory', 'w+');
    $n = rh_sa_write_csv($pdo, $fA, $mem);
    rewind($mem); $csv = stream_get_contents($mem); fclose($mem);
    check($n === count($t['rows']) && strpos($csv, 'Time,Person,Type,What,Where') !== false, 'CSV has the same rows as the filtered timeline', "$n vs " . count($t['rows']));
    check(strpos($csv, 'SECRET-') === false && strpos($csv, 'hunter2') === false, 'CSV carries no secrets');
    check(rh_sa_csv_cell('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && rh_sa_csv_cell('+1') === "'+1" && rh_sa_csv_cell('-2') === "'-2" && rh_sa_csv_cell('@a') === "'@a" && rh_sa_csv_cell('plain') === 'plain', 'CSV cells that start like a formula are neutralised');

    // ---- Types ----
    foreach (['signins' => ['activity'], 'payments' => ['payment', 'refund'], 'bookings' => ['booking', 'activity'], 'till' => ['shift_close', 'order']] as $ty => $allowed) {
        $tt = rh_sa_timeline($pdo, $fA + [], 0, 100);
        $ff = $fA; $ff['type'] = $ty;
        $tt = rh_sa_timeline($pdo, $ff, 0, 100);
        $bad = array_diff(array_unique(array_column($tt['rows'], 'src')), $allowed);
        check($tt['rows'] && !$bad, "type filter $ty: only its sources", implode(',', $bad));
        $wrongType = array_filter($tt['rows'], function ($r) use ($ty) { return $r['type'] !== $ty; });
        check(!$wrongType, "type filter $ty: every row has that type");
    }
    $ff = $fA; $ff['type'] = 'signins';
    $tt = rh_sa_timeline($pdo, $ff, 0, 100);
    check(count($tt['rows']) === 2 && $tt['rows'][0]['what'] === 'Signed out', 'type Sign-ins: sign-in and sign-out');
    $ff = $fA; $ff['type'] = 'bookings';
    $tt = rh_sa_timeline($pdo, $ff, 0, 100);
    $wh = array_column($tt['rows'], 'what');
    check(in_array('Changed a booking status (Bookings)', $wh, true) && !in_array('Saved on ' . rh_sa_page_title('gym-members.php'), $wh, true), 'type Bookings includes booking screen saves, not gym screens');
    $ff = $fA; $ff['type'] = 'other';
    $tt = rh_sa_timeline($pdo, $ff, 0, 100);
    $wh = array_column($tt['rows'], 'what');
    check(in_array('Saved on ' . rh_sa_page_title('gym-members.php'), $wh, true) && !in_array('Changed a booking status (Bookings)', $wh, true), 'type Other includes unmapped screens, not booking screens', implode(' | ', $wh));
    $ff = $fA; $ff['type'] = 'settings';
    $tt = rh_sa_timeline($pdo, $ff, 0, 100);
    check(count($tt['rows']) === 1 && strpos($tt['rows'][0]['what'], 'Updated a staff member') === 0, 'type Settings & users: named audit events', json_encode(array_column($tt['rows'], 'what')));

    // ---- Paging ----
    $p1 = rh_sa_timeline($pdo, $fA, 0, 3);
    $p2 = rh_sa_timeline($pdo, $fA, 3, 3);
    $whole = array_column($t['rows'], 'what');
    check($p1['has_more'] && array_merge(array_column($p1['rows'], 'what'), array_column($p2['rows'], 'what')) === array_slice($whole, 0, 6), 'paging: pages join up with the full list');
    $last = rh_sa_timeline($pdo, $fA, 0, 100);
    check(!$last['has_more'], 'paging: no further page after the last row');

    // ---- Count: same filters as the timeline ----
    $cA = rh_sa_count($pdo, $fA);
    check($cA === count($last['rows']), 'count: equals the timeline lines for the person and day', "$cA vs " . count($last['rows']));
    $ff = $fA; $ff['type'] = 'payments';
    $cP = rh_sa_count($pdo, $ff);
    check($cP === count(rh_sa_timeline($pdo, $ff, 0, 100)['rows']) && $cP > 0, 'count: equals the timeline lines for one type', (string)$cP);
    check(rh_sa_count($pdo, $fA, 3) === 4, 'count: stops just past its cap');

    // ---- Summary ----
    $s = rh_sa_summary($pdo, $fA);
    $row = $s['rows'][0] ?? null;
    check(count($s['rows']) === 1 && $row && $row['uid'] === A && $row['day'] === D1, 'summary: one card for the person and day');
    if ($row) {
        $c = $row['counts'];
        check($c['signins'] === 1 && $c['bookings'] === 1 && $c['payments'] === 1 && $c['refunds'] === 1 && $c['orders'] === 1 && $c['till'] === 1, 'summary counts by type', json_encode($c));
        check($c['saves'] === 3 && $c['settings'] === 1, 'summary: screen saves counted apart from named events', json_encode($c));
        check(abs($row['payments_total'] - 120000) < BALANCE_TOLERANCE && abs($row['refunds_total'] - 6000) < BALANCE_TOLERANCE, 'summary money: payments and refunds separate, full amounts');
        check($row['first_in'] === D1 . ' 08:00:00' && $row['last_out'] === D1 . ' 17:00:00' && $row['last_seen'] === D1 . ' 17:00:00', 'summary: first sign-in, last sign-out, last activity');
    }
    $sAll = rh_sa_summary($pdo, $fAll);
    $keys = array_map(function ($r) { return $r['uid']; }, $sAll['rows']);
    check(in_array(A, $keys, true) && in_array(B, $keys, true), 'summary Everyone: a card per person');
    $sB = rh_sa_summary($pdo, rh_sa_parse_filters(['user' => B, 'from' => D1, 'to' => D1]));
    check(count($sB['rows']) === 1 && $sB['rows'][0]['counts']['kitchen'] === 1 && $sB['rows'][0]['counts']['bookings'] === 0, 'summary: kitchen move counted for the right person only');
    $sR = rh_sa_summary($pdo, rh_sa_parse_filters(['user' => A, 'from' => D1, 'to' => D2]));
    check(count($sR['rows']) === 2 && $sR['rows'][0]['day'] === D2, 'summary: one card per day, newest day first');
} finally {
    $pdo->rollBack();
}
check(!$pdo->inTransaction(), 'library checks rolled back');
$left = $pdo->prepare("SELECT (SELECT COUNT(*) FROM admin_activity_log WHERE user_id IN (?,?)) + (SELECT COUNT(*) FROM booking_audit_log WHERE performed_by = ?) + (SELECT COUNT(*) FROM payments WHERE recorded_by = ?) + (SELECT COUNT(*) FROM stock_orders WHERE created_by = ?)");
$left->execute([A, B, A, A, A]);
check((int)$left->fetchColumn() === 0, 'no fixture rows left behind');

// ---- Hook: what is recorded, pure decision ----
$d = function (string $script, string $method, array $get, array $post) { return rh_sa_hook_describe($script, $method, $get, $post); };
check($d('/admin/bookings.php', 'GET', ['action' => 'update_status'], []) === null, 'hook: a GET records nothing');
check($d('/admin/bookings.php', 'POST', [], ['action' => 'update_status', 'status' => 'confirmed', 'csrf_token' => 'TOKEN123']) === 'page=bookings.php; action=update_status', 'hook: a POST records page and action name only');
$det = $d('/admin/booking-details.php', 'POST', [], ['booking_action' => 'checkin', 'action' => 'x', 'guest_password' => 'hunter2', 'amount' => '99999']);
check($det === 'page=booking-details.php; action=x; booking_action=checkin', 'hook: several action keys, in fixed order', (string)$det);
check(strpos((string)$det, 'hunter2') === false && strpos((string)$det, '99999') === false, 'hook: no posted value appears');
check($d('/admin/create-booking.php', 'POST', [], ['guest_name' => 'A Person', 'csrf_token' => 'T']) === 'page=create-booking.php', 'hook: a form with no action key records just the page');
foreach (['get_rooms', 'search_guest', 'poll', 'preview', 'load_more', 'list_orders', 'feed', 'status'] as $readName) {
    check($d('/admin/pos.php', 'POST', [], ['action' => $readName]) === null, "hook: read action '$readName' is skipped");
}
check($d('/admin/pos.php', 'POST', [], ['action' => 'get_menu', 'ajax' => 'poll']) === null, 'hook: every action key a read means skipped');
check($d('/admin/pos.php', 'POST', [], ['action' => 'get_menu', 'booking_action' => 'checkin']) !== null, 'hook: a write hiding behind a read name is still recorded');
foreach (['presence.php', 'nav-favorites.php', 'global-search.php', 'system-health.php', 'ai-chat.php', 'guest-lookup.php', 'decisions.php'] as $bg) {
    check($d('/admin/api/' . $bg, 'POST', [], ['action' => 'save']) === null, "hook: background endpoint $bg skipped");
}
check($d('/admin/pos.php', 'POST', [], ['action' => "x'; DROP TABLE y;--"]) === 'page=pos.php; action=other', 'hook: free text in an action field is stored as "other"');
check($d('/admin/pos.php', 'POST', [], ['action' => str_repeat('a', 80)]) === 'page=pos.php; action=other', 'hook: over-long action stored as "other"');
check($d('/admin/../evil/..%2f.php', 'POST', [], ['action' => 'x']) === null, 'hook: odd script names record nothing');
check($d('/admin/pos.php', 'POST', [], ['action' => ['a', 'b']]) === 'page=pos.php', 'hook: array value ignored');

// ---- Hook: the real shutdown step in its own process ----
$childFile = $root . '/scripts/tests/system_logs_activity_child.php';
$uidFixture = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$cleanup = function () use ($pdo) { $pdo->exec("DELETE FROM admin_activity_log WHERE ip_address = '203.0.113.77' AND username = 'zz_sa_test'"); };
$count = function () use ($pdo) { return (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE ip_address = '203.0.113.77' AND username = 'zz_sa_test'")->fetchColumn(); };
$run = function (array $cfg) use ($childFile, $uidFixture) {
    $cfg += ['uid' => $uidFixture, 'method' => 'POST', 'script' => '/admin/bookings.php'];
    return shell_exec('php ' . escapeshellarg($childFile) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
};
$cleanup();
if ($uidFixture <= 0) {
    echo "SKIP hook process checks: no active staff account\n";
} else {
    $out = $run(['post' => ['action' => 'update_status', 'password' => 'hunter2', 'price' => '77777']]);
    check(strpos((string)$out, 'done') !== false && $count() === 1, 'hook: a successful POST writes exactly one row (even when asked to register twice)', (string)$out);
    $row = $pdo->query("SELECT user_id, username, action, details FROM admin_activity_log WHERE ip_address = '203.0.113.77' AND username = 'zz_sa_test'")->fetch(PDO::FETCH_ASSOC);
    check($row && (int)$row['user_id'] === $uidFixture && $row['action'] === 'page_action' && $row['details'] === 'page=bookings.php; action=update_status', 'hook: row holds the person, page_action and page + action name', json_encode($row));
    check($row && strpos($row['details'], 'hunter2') === false && strpos($row['details'], '77777') === false, 'hook: stored details contain no posted values');
    $cleanup();
    $out = $run(['post' => ['action' => 'update_status'], 'slow' => true]);
    preg_match('/start=(\S+ \S+)/', (string)$out, $mm);
    $stamp = (string)$pdo->query("SELECT created_at FROM admin_activity_log WHERE ip_address = '203.0.113.77' AND username = 'zz_sa_test'")->fetchColumn();
    check($count() === 1 && isset($mm[1]) && abs(strtotime($stamp) - strtotime($mm[1])) <= 1 && time() - strtotime($stamp) >= 2, 'hook: the row carries the time of the request, not the time a slow shutdown step let it run', "$stamp vs " . ($mm[1] ?? '?'));
    $cleanup();
    $run(['method' => 'GET', 'get' => ['action' => 'update_status']]);
    check($count() === 0, 'hook: a GET writes nothing');
    $run(['post' => ['action' => 'get_rooms']]);
    check($count() === 0, 'hook: a read sent as POST writes nothing');
    $run(['script' => '/admin/api/presence.php', 'post' => ['action' => 'ping']]);
    check($count() === 0, 'hook: a presence heartbeat writes nothing');
    $run(['post' => ['action' => 'update_status'], 'status' => 403]);
    check($count() === 0, 'hook: a refused response (403) writes nothing');
    $run(['post' => ['action' => 'update_status'], 'status' => 500]);
    check($count() === 0, 'hook: a server error writes nothing');
    $run(['post' => ['action' => 'update_status'], 'refused' => true]);
    check($count() === 0, 'hook: a request refused by a permission / CSRF check writes nothing');
    $run(['post' => ['action' => 'update_status'], 'status' => 302]);
    check($count() === 1, 'hook: a redirect after saving is recorded');
    $cleanup();
    $run(['uid' => 0, 'post' => ['action' => 'update_status']]);
    check($count() === 0, 'hook: nobody signed in writes nothing');
}
$cleanup();
check($count() === 0, 'no hook rows left behind');

// ---- The page itself, through the rolled-back harness (GET, nothing written) ----
$h = $root . '/scripts/tests/page_harness.php';
$runPage = function (array $cfg) use ($h) {
    $cmd = 'php ' . escapeshellarg($h) . ' admin/system-logs.php ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1';
    $out = shell_exec($cmd);
    if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -300), 'rolled_back' => false];
};
$clean = function (array $r): bool { return !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']); };
$r = $runPage(['method' => 'GET', 'get' => [], 'grep' => ['id="staff-activity"', 'Each person, each day', 'What each figure counts', 'Download this list (CSV)', 'Fatal error', 'Warning:']]);
check(!empty($r['found']['id="staff-activity"']) && !empty($r['found']['Each person, each day']) && !empty($r['found']['What each figure counts']) && !empty($r['found']['Download this list (CSV)']), 'page: Staff activity section renders with its filters, cards, helper list and CSV link', json_encode($r['found'] ?? $r));
check(empty($r['found']['Fatal error']) && empty($r['found']['Warning:']), 'page: no PHP errors on the default view');
check($clean($r), 'page run rolled back', json_encode($r));
$r = $runPage(['method' => 'GET', 'get' => ['sa' => '1', 'user' => '1', 'from' => '2020-03-05', 'to' => '2020-03-05', 'type' => 'payments', 'sa_page' => '2'], 'grep' => ['older panels further down follow', 'Fatal error', 'Warning:']]);
check(!empty($r['found']['older panels further down follow']) && empty($r['found']['Fatal error']) && empty($r['found']['Warning:']), 'page: filtered view (person, day, type, page 2) renders and says the older panels follow it', json_encode($r['found'] ?? $r));
$r = $runPage(['method' => 'GET', 'get' => ['export' => 'csv', 'from' => '2020-03-05', 'to' => '2020-03-05'], 'grep' => ['Time,Person,Type,What,Where', '<html']]);
check(!empty($r['found']['Time,Person,Type,What,Where']) && empty($r['found']['<html']), 'page: CSV export sends only the CSV', json_encode($r['found'] ?? $r));
check($clean($r), 'CSV export run rolled back', json_encode($r));

// A failing database never raises out of the hook.
$GLOBALS['pdo'] = null;
$threw = false;
try { rh_sa_hook_finish(1, 'x', 'page=a.php'); } catch (Throwable $e) { $threw = true; }
check(!$threw, 'hook: a missing database connection never raises');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
