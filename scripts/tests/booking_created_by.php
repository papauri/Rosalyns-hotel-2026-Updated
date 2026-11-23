<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * "Who created this booking" line on the Bookings list (includes/booking-created-by.php). NO DATABASE: the pure
 * picker is fed fixture rows shaped like the one page query's columns. Last block (Liwonde only, read-only): the
 * Bookings list Source / Made-by SQL equals the picker for every booking. Usage (repo root):
 *   php scripts/tests/booking_created_by.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function eq($got, $want, string $l): void { check($got === $want, $l, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

require_once $root . '/includes/booking-created-by.php';
check(!function_exists('getSetting') && !isset($GLOBALS['pdo']), 'loads without the database or settings');

// 1. Staff via the audit row's id (admin create-booking): short name on the card, role only in the title.
$p = rh_created_by_pick(['performed_by' => 5, 'performed_by_name' => 'reservations', 'note' => 'Created manually by admin',
    'au_staff_name' => 'Mary Phiri', 'au_staff_username' => 'mary', 'au_staff_role' => 'front_desk'], null);
eq($p['label'], 'by Mary Phiri', 'staff id -> by Full Name');
eq($p['title'], 'Created by Mary Phiri (front desk)', 'staff title carries the role');
eq($p['kind'], 'staff', 'staff kind');

// Staff id wins over a guest timeline row.
$p = rh_created_by_pick(['performed_by' => 5, 'performed_by_name' => 'Mary', 'au_staff_name' => 'Mary Phiri'], ['performed_by_type' => 'guest']);
eq($p['label'], 'by Mary Phiri', 'audit staff id beats timeline');

// Staff id whose account is gone: stored name, else "Staff member #id".
eq(rh_created_by_pick(['performed_by' => 9, 'performed_by_name' => 'Old Clerk', 'au_staff_name' => null], null)['label'], 'by Old Clerk', 'deleted staff -> stored name');
eq(rh_created_by_pick(['performed_by' => 9, 'performed_by_name' => '', 'au_staff_name' => null], null)['label'], 'by Staff member #9', 'deleted staff, no name -> id');

// 2. Timeline only: admin with id / admin with name only.
$p = rh_created_by_pick(null, ['performed_by_type' => 'admin', 'performed_by_id' => 3, 'performed_by_name' => 'x', 'tl_staff_name' => 'John Banda', 'tl_staff_role' => 'manager']);
eq($p['label'], 'by John Banda', 'timeline admin id -> staff name');
eq(rh_created_by_pick(null, ['performed_by_type' => 'admin', 'performed_by_id' => null, 'performed_by_name' => 'Night Auditor'])['label'], 'by Night Auditor', 'timeline admin, stored name only');

// Guest / api / system.
$onlineAu = ['performed_by' => null, 'performed_by_name' => 'Jane Guest', 'note' => 'Booking made online by Jane Guest'];
eq(rh_created_by_pick($onlineAu, ['performed_by_type' => 'guest', 'performed_by_name' => 'Jane Guest'])['label'], 'Online booking', 'guest -> Online booking');
eq(rh_created_by_pick($onlineAu, ['performed_by_type' => 'api', 'performed_by_name' => 'Jane Guest'])['label'], 'Online booking', 'api -> Online booking');
eq(rh_created_by_pick(null, ['performed_by_type' => 'system'])['label'], 'System', 'system -> System');
eq(rh_created_by_pick(null, ['performed_by_type' => 'admin'])['label'], 'System', 'admin with no id or name -> System');

// 3. Online audit row with the GUEST's name and no timeline row must never read "by <guest>".
$p = rh_created_by_pick($onlineAu, null);
eq($p['label'], 'Online booking', 'online audit note, no timeline -> Online booking');
check(strpos($p['label'], 'Jane') === false, 'guest name never shown as creator');

// 4. Audit stored name only (no id, not online).
eq(rh_created_by_pick(['performed_by' => null, 'performed_by_name' => 'Front Desk', 'note' => 'Imported'], null)['label'], 'by Front Desk', 'stored name only -> by name');

// 5. Nothing recorded -> no line.
eq(rh_created_by_pick(null, null)['label'], '', 'no rows -> empty');
eq(rh_created_by_pick(['performed_by' => null, 'performed_by_name' => null, 'note' => 'Imported'], null)['label'], '', 'audit with no id or name -> empty');
eq(rh_created_by_html(rh_created_by_pick(null, null)), '', 'empty pick -> no markup');
eq(rh_created_by_html(['label' => '']), '', 'missing map entry -> no markup');

// Escaping.
$h = rh_created_by_html(rh_created_by_pick(['performed_by' => 4, 'au_staff_name' => '<b>O\'Neil & "Co"</b>', 'au_staff_role' => 'x<y'], null));
check(strpos($h, '<b>') === false && strpos($h, '&lt;b&gt;O&#039;Neil &amp; &quot;Co&quot;&lt;/b&gt;') !== false, 'name escaped in text', $h);
check(strpos($h, 'title="Created by &lt;b&gt;') !== false && strpos($h, 'x&lt;y') !== false, 'title escaped', $h);
check(strpos($h, 'class="bk-created-by bk-created-by--staff"') === 0 || strpos($h, '<span class="bk-created-by bk-created-by--staff"') === 0, 'class on the span', $h);

// ---- Bookings list Source / Made by filters (plan BOOKINGS_LIST_PLAN T1b). LIWONDE only, READ-ONLY: for every booking
// the SQL twins (rh_bk_source_sql / rh_bk_creator_sql) equal rh_created_by_pick via rh_created_by_map (the line the list
// shows). Path check FIRST: at Rosalyn's (live) the database is never even opened by this test.
require_once $root . '/admin/includes/bookings-list-filters.php';
if (stripos($root, 'Liwonde') !== false) { require $root . '/config/database.php'; }
if (stripos($root, 'Liwonde') === false || stripos((string)getSetting('site_name', ''), 'liwonde') === false) {
    echo "SKIP source cross-check: runs at Liwonde (the test platform) only; Rosalyn's is live.\n";
} else {
    $xs = $pdo->query("SELECT b.id,
            " . rh_bk_first_audit_sql('bkau.id') . " AS au_id, " . rh_bk_first_timeline_sql('bktl.id') . " AS tl_id,
            " . rh_bk_source_sql() . " AS sql_src, " . rh_bk_creator_sql() . " AS sql_by
        FROM bookings b ORDER BY b.id")->fetchAll(PDO::FETCH_ASSOC);
    $picks = rh_created_by_map($pdo, array_column($xs, 'id'));
    $auSt = $pdo->prepare('SELECT performed_by FROM booking_audit_log WHERE id = ?');
    $tlSt = $pdo->prepare('SELECT performed_by_type, performed_by_id FROM booking_timeline_logs WHERE id = ?');
    $mis = []; $kinds = []; $byIds = 0;
    foreach ($xs as $r) {
        $kind = $picks[(int)$r['id']]['kind'] ?? 'missing';
        // The id behind a staff pick (rh_created_by_pick's order): the audit row's id, else the timeline admin's id.
        $wantBy = 0;
        $au = null; $tl = null;
        if ($r['au_id'] !== null) { $auSt->execute([(int)$r['au_id']]); $au = $auSt->fetch(PDO::FETCH_ASSOC); }
        if ($r['tl_id'] !== null) { $tlSt->execute([(int)$r['tl_id']]); $tl = $tlSt->fetch(PDO::FETCH_ASSOC); }
        if ($au && (int)$au['performed_by'] > 0) {
            $wantBy = (int)$au['performed_by'];
        } elseif ($tl && in_array(strtolower(trim((string)$tl['performed_by_type'])), ['admin', 'staff'], true)) {
            $wantBy = max(0, (int)$tl['performed_by_id']);
        }
        $gotBy = max(0, (int)$r['sql_by']);
        if ($kind !== (string)$r['sql_src'] || $wantBy !== $gotBy) {
            $mis[] = '#' . $r['id'] . ' ' . json_encode(['pick' => $kind, 'sql' => $r['sql_src'], 'want_by' => $wantBy, 'sql_by' => $gotBy]);
        }
        $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        $byIds += $gotBy > 0 ? 1 : 0;
    }
    ksort($kinds);
    echo 'source cross-check: ' . count($xs) . ' bookings, kinds ' . json_encode($kinds) . ', with a staff id ' . $byIds . '; mismatches ' . count($mis) . "\n";
    check(count($xs) > 0 && count($picks) === count($xs), 'source cross-check: every Liwonde booking has a pick');
    check(count($mis) === 0, 'source / made-by SQL = rh_created_by_pick for every booking', implode('; ', array_slice($mis, 0, 5)));
    check(count($kinds) >= 3 && $byIds > 0, 'source cross-check covers staff, online and system rows and staff ids', json_encode($kinds));
    // The filter clauses split the list exactly (each booking in exactly one source).
    $srcCnt = 0;
    foreach (array_keys(rh_bk_source_options()) as $s) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM bookings b WHERE ' . rh_bk_source_sql() . ' = ?');
        $c->execute([$s]);
        $srcCnt += (int)$c->fetchColumn();
    }
    check($srcCnt === count($xs), 'source filter: staff + online + system + not recorded = every booking', "$srcCnt vs " . count($xs));
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
