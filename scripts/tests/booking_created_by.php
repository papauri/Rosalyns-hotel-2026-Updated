<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * "Who created this booking" line on the Bookings list (includes/booking-created-by.php). NO DATABASE: the pure
 * picker is fed fixture rows shaped like the one page query's columns. Usage (repo root):
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

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
