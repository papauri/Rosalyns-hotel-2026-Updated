<?php
/**
 * Stock count approval test (admin/includes/stock-count-approval-lib.php + the stock_count_approve permission).
 * Usage: php scripts/tests/stock_count_approval.php
 *
 * Covers: the permission and its role defaults (admin / manager keep approving with no permission rows saved,
 * resetting a user to defaults keeps it, other roles do not have it, an explicit grant or deny wins), who is
 * listed as an approver (active, holds the permission AND can open the Stock Count page, never the counter),
 * the second-person rule helper, the approver email (once per approver, not the counter, nobody without a valid
 * address, right contents, link, activity log), the 10-minute reminder limit, and that nothing is left behind.
 *
 * Runs on the live DB inside ONE transaction that is rolled back. Emails are CAPTURED (CLI-only seam
 * $GLOBALS['rh_test_email_capture']) and never sent. The existing counts are never touched.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../admin/includes/permissions.php';
require_once __DIR__ . '/../../admin/includes/stock-count-approval-lib.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

$run = strtoupper(bin2hex(random_bytes(3)));
$orphanPerm = (int)col($pdo, "SELECT COUNT(*) FROM user_permissions up LEFT JOIN admin_users u ON u.id = up.user_id WHERE u.id IS NULL");
$submittedBefore = $pdo->query("SELECT id, status, updated_at FROM stock_counts WHERE status = 'submitted' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$GLOBALS['rh_test_email_capture'] = [];
$pdo->beginTransaction();
try {
    $mkUser = function (string $tag, string $role, int $active = 1, ?string $email = null) use ($pdo, $run): int {
        $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active, created_at) VALUES (?,?,?,?,?,?,NOW())")
            ->execute(['zzt_sca_' . $run . '_' . $tag, $email ?? ('zzt-sca-' . strtolower($run) . '-' . $tag . '@example.invalid'), password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), 'ZZT ' . $tag . ' ' . $run, $role, $active]);
        return (int)$pdo->lastInsertId();
    };
    $perm = function (int $uid, string $key, int $granted) use ($pdo): void {
        $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?,?,?) ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)")->execute([$uid, $key, $granted]);
    };

    // --- Permission + role defaults ------------------------------------------------------------------
    check(isset(getAllPermissions()['stock_count_approve']), 'Permission: stock_count_approve exists and can be given in User Management');
    check(in_array('stock_count_approve', getDefaultPermissionsForRole('manager'), true), 'Permission: managers get it by default');
    check(in_array('stock_count_approve', getDefaultPermissionsForRole('admin'), true), 'Permission: administrators get it by default');
    foreach (['receptionist', 'housekeeping', 'accountant', 'viewer', 'restaurant_staff'] as $r) {
        check(!in_array('stock_count_approve', getDefaultPermissionsForRole($r), true), "Permission: role $r does not get it by default");
    }

    $counter = $mkUser('counter', 'manager');          // manager who does the counting
    $mgr2    = $mkUser('mgr2', 'manager');              // second manager
    $adm     = $mkUser('adm', 'admin');
    $staffOk = $mkUser('staffok', 'restaurant_staff'); // explicit grant (+ page access)
    $recOnly = $mkUser('reconly', 'receptionist');     // approve permission without page access
    $denied  = $mkUser('denied', 'manager');            // manager explicitly denied
    $inact   = $mkUser('inactive', 'manager', 0);
    $noMail  = $mkUser('nomail', 'manager', 1, 'not-an-address');
    $plain   = $mkUser('plain', 'restaurant_staff');   // nothing
    $perm($staffOk, 'stock_count', 1);
    $perm($staffOk, 'stock_count_approve', 1);
    $perm($recOnly, 'stock_count_approve', 1);
    $perm($denied, 'stock_count_approve', 0);

    check(col($pdo, "SELECT COUNT(*) FROM user_permissions WHERE user_id = ?", [$mgr2]) == 0 && hasPermission($mgr2, 'stock_count_approve'), 'Permission: an existing manager with no saved permission rows can approve');
    check(hasPermission($adm, 'stock_count_approve'), 'Permission: an administrator can approve');
    resetUserPermissionsToDefault($mgr2, null);
    check(hasPermission($mgr2, 'stock_count_approve'), 'Permission: resetting a manager to role defaults keeps approval');
    check(hasPermission($staffOk, 'stock_count_approve'), 'Permission: an explicit grant lets a non-manager approve');
    check(!hasPermission($denied, 'stock_count_approve'), 'Permission: an explicit deny removes it from a manager');
    check(!hasPermission($plain, 'stock_count_approve'), 'Permission: a user with nothing cannot approve');
    check(!hasPermission($inact, 'stock_count_approve'), 'Permission: an inactive user cannot approve');

    // --- Who is listed ---------------------------------------------------------------------------------
    $ids = static fn(array $a): array => array_map(static fn($x) => (int)$x['id'], $a);
    $list = rh_sca_approvers($pdo, $counter);
    $li = $ids($list);
    check(!in_array($counter, $li, true), 'List: the counter is never listed as an approver of their own count');
    check(in_array($mgr2, $li, true) && in_array($adm, $li, true), 'List: other managers and administrators are listed');
    check(in_array($staffOk, $li, true), 'List: a non-manager with the permission and the Stock Count page is listed');
    check(!in_array($recOnly, $li, true), 'List: approve permission without access to the Stock Count page is not listed');
    check(!in_array($denied, $li, true) && !in_array($inact, $li, true) && !in_array($plain, $li, true), 'List: denied, inactive and unrelated users are not listed');
    check(in_array($counter, $ids(rh_sca_approvers($pdo, 0)), true), 'List: with nobody excluded the counter (a manager) is an approver');
    check(strpos(rh_sca_approver_names($list), 'ZZT mgr2 ' . $run) !== false, 'List: names are shown for the screen');

    // --- Counts ----------------------------------------------------------------------------------------
    $ingIds = array_map('intval', $pdo->query("SELECT id FROM stock_ingredients WHERE is_archived = 0 ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN));
    if (count($ingIds) < 3) { throw new RuntimeException('Fixture: no ingredient'); }
    $mkCount = function (string $tag, int $opening, string $status, array $lines) use ($pdo, $counter, $run, $ingIds): array {
        $ref = '20991231-' . $tag . substr($run, 0, 2);
        $pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, is_opening) VALUES (?, CURDATE(), 'full', ?, ?, ?)")->execute([$ref, $status, $counter, $opening]);
        $id = (int)$pdo->lastInsertId();
        foreach (array_values($lines) as $li => [$sys, $act, $cost]) {
            $var = $act - $sys;
            $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost) VALUES (?,?,?,?,?,?,?)")
                ->execute([$id, $ingIds[$li], $sys, $act, $var, $cost, round($var * $cost, 4)]);
        }
        return [$id, $ref];
    };
    [$openId, $openRef] = $mkCount('OP', 1, 'submitted', [[0, 10, 100], [0, 5, 100], [0, 2, 100]]);
    [$bigId, $bigRef]   = $mkCount('BG', 0, 'submitted', [[100, 50, 200]]);   // short 50 (50%), worth 10,000
    [$smlId, $smlRef]   = $mkCount('SM', 0, 'submitted', [[100, 99, 10]]);    // short 1 (1%), worth 10
    [$drfId, ]          = $mkCount('DR', 0, 'draft', [[10, 10, 1]]);
    $cnt = static function (int $id) use ($pdo): array { $s = $pdo->prepare("SELECT * FROM stock_counts WHERE id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC); };

    check(rh_sca_needs_second_person($pdo, $cnt($openId), 10.0, 5000.0), 'Second person: an opening count always needs one');
    check(rh_sca_needs_second_person($pdo, $cnt($bigId), 10.0, 5000.0), 'Second person: a shortage over both limits needs one');
    check(!rh_sca_needs_second_person($pdo, $cnt($smlId), 10.0, 5000.0), 'Second person: a small shortage does not');
    check(!rh_sca_needs_second_person($pdo, $cnt($bigId), 60.0, 5000.0), 'Second person: under the percentage limit does not');
    check(!rh_sca_needs_second_person($pdo, $cnt($bigId), 10.0, 20000.0), 'Second person: under the minimum value does not');

    // --- Email on submit (captured) ----------------------------------------------------------------------
    $GLOBALS['rh_test_email_capture'] = [];
    $r = rh_sca_email_approvers($pdo, $openId, $counter, 'submit');
    $cap = $GLOBALS['rh_test_email_capture'];
    $capIds = array_map(static fn($c) => (int)$c['user_id'], $cap);
    check($r['ok'] && $r['emailed'] === count($cap) && $r['emailed'] > 0, 'Email: count of approvers emailed matches', json_encode($r));
    check(strpos($r['message'], 'Approvers emailed: ' . $r['emailed']) === 0, 'Email: flash says "Approvers emailed: N"', $r['message']);
    check(!in_array($counter, $capIds, true), 'Email: the counter is not emailed');
    check(!in_array($inact, $capIds, true) && !in_array($denied, $capIds, true) && !in_array($plain, $capIds, true) && !in_array($recOnly, $capIds, true), 'Email: inactive, denied, unrelated and no-page users are not emailed');
    check(in_array($mgr2, $capIds, true) && in_array($adm, $capIds, true) && in_array($staffOk, $capIds, true), 'Email: each other approver is emailed');
    check(!in_array($noMail, $capIds, true) && $r['skipped_no_email'] >= 1, 'Email: an approver with no valid address is skipped, not failed');
    check(count($capIds) === count(array_unique($capIds)), 'Email: nobody is emailed twice');
    $m = null; foreach ($cap as $c) { if ($c['user_id'] === $mgr2) { $m = $c; } }
    $body = $m ? ($m['html'] . ' ' . $m['text'] . ' ' . $m['subject']) : '';
    check($m !== null && strpos($body, $openRef) !== false, 'Email: has the count reference');
    check(strpos($body, 'Opening stock count') !== false, 'Email: says it is an opening count');
    check(strpos($body, 'ZZT counter ' . $run) !== false, 'Email: says who counted');
    check(strpos($m['text'] ?? '', 'Lines: 3') !== false, 'Email: has the number of lines');
    check(strpos($body, 'Shortage:') !== false && strpos($body, 'Surplus:') !== false, 'Email: has shortage and surplus value');
    check(strpos($body, 'second person must approve it, not ZZT counter ' . $run) !== false, 'Email: says a second person is needed, and not the counter');
    check(strpos($body, '/admin/stock-count.php?id=' . $openId) !== false, 'Email: links to the count');
    check(strpos($m['subject'] ?? '', 'waiting for your approval') !== false, 'Email: subject says it is waiting for approval');
    $logRow = col($pdo, "SELECT details FROM admin_activity_log WHERE user_id = ? AND action = 'stock_count_approvers_emailed' AND details LIKE ? ORDER BY id DESC LIMIT 1", [$counter, '%(#' . $openId . ')%']);
    check(is_string($logRow) && strpos($logRow, $openRef) !== false, 'Email: written to the activity log', (string)$logRow);

    $GLOBALS['rh_test_email_capture'] = [];
    $r = rh_sca_email_approvers($pdo, $smlId, $counter, 'submit');
    $body2 = $GLOBALS['rh_test_email_capture'][0]['text'] ?? '';
    check($r['ok'] && strpos($body2, 'Second person needed: No') !== false, 'Email: a small regular count says no second person is needed');
    $GLOBALS['rh_test_email_capture'] = [];
    $r = rh_sca_email_approvers($pdo, $bigId, $counter, 'submit');
    check($r['ok'] && strpos($GLOBALS['rh_test_email_capture'][0]['text'] ?? '', 'Second person needed: Yes') !== false, 'Email: a big-shortage count says a second person is needed');

    // --- Reminder limit (10 minutes per count) ---------------------------------------------------------------
    $GLOBALS['rh_test_email_capture'] = [];
    $rem = rh_sca_email_approvers($pdo, $openId, $counter, 'remind');
    check(!$rem['ok'] && $rem['rate_limited'] && $rem['wait'] > 0 && count($GLOBALS['rh_test_email_capture']) === 0, 'Remind: refused right after the submit email, nothing sent', json_encode($rem));
    check(strpos($rem['message'], 'again in') !== false, 'Remind: says how long to wait', $rem['message']);
    $rem = rh_sca_email_approvers($pdo, $drfId, $counter, 'remind');
    check(!$rem['ok'] && count($GLOBALS['rh_test_email_capture']) === 0, 'Remind: a count that is not waiting for approval is refused');
    $pdo->prepare("UPDATE admin_activity_log SET created_at = created_at - INTERVAL 11 MINUTE WHERE action IN ('stock_count_approvers_emailed','stock_count_approvers_reminded') AND details LIKE ?")->execute(['%(#' . $openId . ')%']);
    check(rh_sca_remind_wait($pdo, $openId) === 0, 'Remind: allowed again after 10 minutes');
    $rem = rh_sca_email_approvers($pdo, $openId, $staffOk, 'remind');
    check($rem['ok'] && count($GLOBALS['rh_test_email_capture']) === $rem['emailed'] && strpos($GLOBALS['rh_test_email_capture'][0]['subject'], 'Reminder') === 0, 'Remind: after 10 minutes anyone with the page can send it; subject says Reminder', json_encode($rem));
    check(rh_sca_remind_wait($pdo, $openId) > 0, 'Remind: the limit starts again after a reminder');
    check(rh_sca_remind_wait($pdo, $bigId) > 0 && rh_sca_remind_wait($pdo, $drfId) === 0, 'Remind: the limit is per count');
    $nf = rh_sca_email_approvers($pdo, 999999999, $counter, 'submit');
    check(!$nf['ok'] && $nf['message'] !== '', 'Email: an unknown count is refused without an error');

    // --- Page wiring (static) ------------------------------------------------------------------------------------
    $src = (string)file_get_contents(__DIR__ . '/../../admin/stock-count.php');
    check(strpos($src, "hasPermission((int)\$user['id'], 'stock_count_approve')") !== false && strpos($src, "['admin', 'manager'], true);\n\n\$message") === false, 'Page: $canApprove comes from the permission, not the role');
    check(strpos($src, "An opening stock count must be approved by a different manager") !== false, 'Page: an opening count still cannot be approved by its counter');
    check(strpos($src, 'name="email_approvers"') !== false && strpos($src, 'Remind approvers') !== false, 'Page: has the "Email the approvers" tickbox and the Remind button');
} catch (Throwable $e) {
    bad('Unexpected error', $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    unset($GLOBALS['rh_test_email_capture']);
}

// --- Leak check -----------------------------------------------------------------------------------------------------
check((int)col($pdo, "SELECT COUNT(*) FROM stock_counts WHERE reference LIKE '20991231-%'") === 0, 'Leak: no test stock counts left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM admin_users WHERE username LIKE 'zzt\_sca\_%'") === 0, 'Leak: no test users left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM admin_activity_log WHERE details LIKE '%20991231-%'") === 0, 'Leak: no test activity log rows left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM user_permissions up LEFT JOIN admin_users u ON u.id = up.user_id WHERE u.id IS NULL") === $orphanPerm, 'Leak: no stray permission rows left behind');
$submittedAfter = $pdo->query("SELECT id, status, updated_at FROM stock_counts WHERE status = 'submitted' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check($submittedAfter === $submittedBefore, 'Leak: the counts waiting for approval were not touched');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
