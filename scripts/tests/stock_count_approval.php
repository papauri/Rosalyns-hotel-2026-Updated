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
 * Also: who may press "Remind approvers" (the counter and holders of the permission only), the global-admin switch
 * for the two-person rule (setting stock_count_second_approver: only role 'admin' can change it, the change is
 * logged, default ON), and the real stock-count.php page in scripts/tests/page_harness.php (rolled back) for
 * counter self-approval of an opening count and of an over-threshold count with the rule OFF, refused with it ON.
 * The setting is put back exactly (value or absence) and checked at the end.
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
require_once __DIR__ . '/../../admin/includes/hotel-details-settings.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

$run = strtoupper(bin2hex(random_bytes(3)));
$orphanPerm = (int)col($pdo, "SELECT COUNT(*) FROM user_permissions up LEFT JOIN admin_users u ON u.id = up.user_id WHERE u.id IS NULL");
$submittedBefore = $pdo->query("SELECT id, status, updated_at FROM stock_counts WHERE status = 'submitted' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$setRowBefore = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'stock_count_second_approver'")->fetch(PDO::FETCH_NUM);
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
    // These checks assume the two-person rule is ON. Set it inside this rolled-back run, whatever the hotel's real setting is.
    updateSetting('stock_count_second_approver', '1');
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

    // --- Who may press "Remind approvers" -----------------------------------------------------------------------------
    [$rmId, $rmRef] = $mkCount('RM', 0, 'submitted', [[100, 99, 10]]);
    $pdo->prepare("UPDATE stock_counts SET counted_by = ? WHERE id = ?")->execute([$denied, $rmId]); // counted by a user WITHOUT the permission
    $GLOBALS['rh_test_email_capture'] = [];
    $rem = rh_sca_email_approvers($pdo, $rmId, $plain, 'remind');
    check(!$rem['ok'] && count($GLOBALS['rh_test_email_capture']) === 0 && strpos($rem['message'], 'You can’t remind the approvers') === 0, 'Remind: refused for someone who is neither the counter nor an approver, nothing sent', json_encode($rem));
    check(rh_sca_remind_wait($pdo, $rmId) === 0, 'Remind: a refused reminder does not start the 10 minute limit');
    $rem = rh_sca_email_approvers($pdo, $rmId, $denied, 'remind');
    check($rem['ok'] && count($GLOBALS['rh_test_email_capture']) === $rem['emailed'] && $rem['emailed'] > 0, 'Remind: allowed for the person who counted it, even without the approve permission', json_encode($rem));
    $rem = rh_sca_email_approvers($pdo, $rmId, $mgr2, 'remind');
    check(!$rem['ok'] && $rem['rate_limited'], 'Remind: an approver is not refused (only held back by the 10 minute limit)', json_encode($rem));
    check(rh_sca_may_remind($mgr2, $denied, true) && rh_sca_may_remind($denied, $denied, false) && !rh_sca_may_remind($plain, $denied, false) && !rh_sca_may_remind(0, 0, false), 'Remind: helper allows the counter and approvers only');

    // --- The administrator switch for the two-person rule ------------------------------------------------------------------
    // With no saved setting the rule is ON (checked inside this rolled-back run by taking away whatever the hotel has saved).
    $pdo->prepare("DELETE FROM site_settings WHERE setting_key = 'stock_count_second_approver'")->execute();
    unset($GLOBALS['_SITE_SETTINGS']['stock_count_second_approver']);
    if (function_exists('deleteCache')) { deleteCache('setting_stock_count_second_approver'); }
    check(rh_sca_second_approver_required(), 'Switch: with no saved setting the rule is ON (default)');
    updateSetting('stock_count_second_approver', '1');
    check(rh_sca_second_approver_required(), 'Switch: value 1 means ON');
    $fld = rh_hotel_details_fields()['Restaurant & stock']['stock_count_second_approver'] ?? null;
    check($fld && !empty($fld['admin_only']) && $fld['default'] === '1', 'Switch: it is an administrator-only setting that defaults to ON');
    $before = (int)col($pdo, "SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_count_second_approver_changed'");
    foreach ([$mgr2 => 'manager', $staffOk => 'staff member with the approve permission', $denied => 'manager without the approve permission'] as $uid => $nm) {
        [$msg, $err] = rh_hotel_details_save(['stock_count_second_approver' => '0'], ['id' => $uid, 'username' => 'x', 'role' => 'admin']);
        check(rh_sca_second_approver_required() && $err === '', "Switch: a $nm cannot change it (even when the request claims role admin)", $msg . $err);
    }
    check((int)col($pdo, "SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_count_second_approver_changed'") === $before, 'Switch: a refused change is not logged as a change');
    ob_start(); rh_hotel_details_render(['id' => $mgr2], 'tok'); $htmlMgr = ob_get_clean();
    ob_start(); rh_hotel_details_render(['id' => $adm], 'tok'); $htmlAdm = ob_get_clean();
    check(strpos($htmlMgr, 'stock_count_second_approver') === false, 'Switch: not shown to a non-administrator');
    check(strpos($htmlAdm, 'name="stock_count_second_approver"') !== false && strpos($htmlAdm, 'whoever counts can approve') !== false, 'Switch: shown to an administrator');
    [$msg, $err] = rh_hotel_details_save(['stock_count_second_approver' => 'maybe'], ['id' => $adm, 'username' => 'zzt_adm']);
    check($err !== '' && rh_sca_second_approver_required(), 'Switch: a value other than On or Off is refused and saves nothing', $msg . $err);
    [$msg, $err] = rh_hotel_details_save(['stock_count_second_approver' => '0'], ['id' => $adm, 'username' => 'zzt_adm']);
    check($err === '' && !rh_sca_second_approver_required(), 'Switch: an administrator can switch it OFF', $msg . $err);
    $lg = col($pdo, "SELECT details FROM admin_activity_log WHERE action = 'stock_count_second_approver_changed' AND user_id = ? ORDER BY id DESC LIMIT 1", [$adm]);
    check(is_string($lg) && strpos($lg, 'zzt_adm') !== false && strpos($lg, 'Off') !== false && strpos($lg, 'On') !== false, 'Switch: the change is logged with who made it, from On to Off', (string)$lg);

    // Rule OFF: the counter may decide their own count; someone else's still needs the permission.
    check(rh_sca_may_decide($denied, $denied, false), 'Rule OFF: the counter may approve or reject their own count without the approve permission');
    check(!rh_sca_may_decide($plain, $denied, false), 'Rule OFF: someone who did not count it still needs the approve permission');
    check(rh_sca_may_decide($mgr2, $denied, true), 'Rule OFF: an approver can still approve someone else\'s count');
    check(!rh_sca_self_approve_blocked($counter, $counter, true) && !rh_sca_self_approve_blocked($counter, $counter, false), 'Rule OFF: an opening or over-threshold count is not blocked for its counter');
    $GLOBALS['rh_test_email_capture'] = [];
    $r = rh_sca_email_approvers($pdo, $bigId, $counter, 'submit');
    check($r['ok'] && strpos($GLOBALS['rh_test_email_capture'][0]['text'] ?? '', 'Second person needed: No') !== false, 'Rule OFF: the submit email still goes to approvers and no longer says a second person is needed');
    [$msg, $err] = rh_hotel_details_save(['stock_count_second_approver' => '1'], ['id' => $adm, 'username' => 'zzt_adm']);
    check($err === '' && rh_sca_second_approver_required(), 'Switch: an administrator can switch it back ON', $msg . $err);

    // Rule ON: unchanged.
    check(!rh_sca_may_decide($denied, $denied, false), 'Rule ON: a counter without the approve permission cannot decide their own count');
    check(rh_sca_self_approve_blocked($counter, $counter, true) && !rh_sca_self_approve_blocked($counter, $counter, false) && !rh_sca_self_approve_blocked($mgr2, $counter, true), 'Rule ON: the counter is blocked from approving an opening/over-threshold count; others and small counts are not');
    $GLOBALS['rh_test_email_capture'] = [];
    $r = rh_sca_email_approvers($pdo, $bigId, $counter, 'submit');
    check($r['ok'] && strpos($GLOBALS['rh_test_email_capture'][0]['text'] ?? '', 'Second person needed: Yes') !== false, 'Rule ON: the email says a second person is needed');

    // --- Page wiring (static) ------------------------------------------------------------------------------------
    $src = (string)file_get_contents(__DIR__ . '/../../admin/stock-count.php');
    check(strpos($src, "hasPermission((int)\$user['id'], 'stock_count_approve')") !== false && strpos($src, "['admin', 'manager'], true);\n\n\$message") === false, 'Page: $canApprove comes from the permission, not the role');
    check(strpos($src, "An opening count must be approved by a different person") !== false, 'Page: an opening count still cannot be approved by its counter');
    check(strpos($src, 'rh_sca_may_remind(') !== false && strpos($src, 'rh_sca_may_decide(') !== false && strpos($src, 'approved own count - second approver switched off') !== false, 'Page: reminders, own-count approval and the activity-log note are wired');
    check(strpos($src, 'name="email_approvers"') !== false && strpos($src, 'Remind approvers') !== false, 'Page: has the "Email the approvers" tickbox and the Remind button');
} catch (Throwable $e) {
    bad('Unexpected error', $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    unset($GLOBALS['rh_test_email_capture']);
    // The settings read cache survives a rollback: drop it so the real value is read again.
    unset($GLOBALS['_SITE_SETTINGS']['stock_count_second_approver']);
    if (function_exists('deleteCache')) { deleteCache('setting_stock_count_second_approver'); }
}

// --- The real page, as the signed-in admin (page_harness.php: one outer transaction that never commits) ----------------
$harness = __DIR__ . '/page_harness.php';
$hRun = strtoupper(bin2hex(random_bytes(3)));
$hScenario = function (array $post, array $grep = []) use ($harness, $hRun): array {
    $refLike = "'%20991231-CH" . $hRun . "%'";
    $verify = [
        "SELECT status, approved_by, counted_by FROM stock_counts WHERE reference LIKE '20991231-CH" . $hRun . "%'",
        "SELECT COUNT(*) AS n FROM admin_activity_log WHERE action = 'stock_count_self_approved' AND details LIKE $refLike",
        "SELECT COUNT(*) AS n FROM admin_activity_log WHERE action IN ('stock_count_approvers_emailed','stock_count_approvers_reminded') AND details LIKE $refLike",
        // 3: the lines the count holds now (partial-submit scenarios)
        "SELECT i.name, scl.actual_quantity FROM stock_count_lines scl INNER JOIN stock_counts sc ON sc.id = scl.count_id INNER JOIN stock_ingredients i ON i.id = scl.ingredient_id WHERE sc.reference LIKE '20991231-CH" . $hRun . "%' ORDER BY i.name",
        // 4: is the left-out item still inside any open opening count? (what scOpeningOverlap looks at)
        "SELECT COUNT(*) AS n FROM stock_count_lines l INNER JOIN stock_counts sc ON sc.id = l.count_id INNER JOIN stock_ingredients i ON i.id = l.ingredient_id WHERE i.name = 'ZZT " . $hRun . " Blank' AND sc.is_opening = 1 AND sc.status IN ('draft','submitted')",
        // 5: the left-out names were logged
        "SELECT COUNT(*) AS n FROM admin_activity_log WHERE action = 'stock_count_lines_left_out' AND details LIKE $refLike AND details LIKE '%ZZT " . $hRun . " Blank%'",
    ];
    // "perm no" needs a signed-in user the admin role does not bypass: an existing active manager (the child denies them the permission).
    $asUser = '';
    if (($post['perm'] ?? '') === 'no') {
        global $pdo;
        $asUser = (string)$pdo->query("SELECT username FROM admin_users WHERE is_active = 1 AND role = 'manager' ORDER BY id LIMIT 1")->fetchColumn();
    }
    $cfg = base64_encode(json_encode(['post' => $post + ['run' => $hRun], 'verify' => $verify, 'grep' => $grep] + ($asUser !== '' ? ['as_username' => $asUser] : [])));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' scripts/tests/stock_count_approval_child.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -600), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $res = $x; } }
    return $res;
};
$hs = [
    ['opening, rule OFF, counter without the approve permission approves own', ['kind' => 'opening', 'rule' => 'off', 'perm' => 'no', 'counter' => 'me', 'do' => 'approve_count'], 'approved', 1],
    ['opening, rule OFF, counter with the approve permission approves own', ['kind' => 'opening', 'rule' => 'off', 'perm' => 'yes', 'counter' => 'me', 'do' => 'approve_count'], 'approved', 1],
    ['over-threshold shortage, rule OFF, counter approves own', ['kind' => 'big', 'rule' => 'off', 'perm' => 'yes', 'counter' => 'me', 'do' => 'approve_count'], 'approved', 1],
    ['opening, rule ON, counter cannot approve own', ['kind' => 'opening', 'rule' => 'on', 'perm' => 'yes', 'counter' => 'me', 'do' => 'approve_count'], 'submitted', 0],
    ['over-threshold shortage, rule ON, counter cannot approve own', ['kind' => 'big', 'rule' => 'on', 'perm' => 'yes', 'counter' => 'me', 'do' => 'approve_count'], 'submitted', 0],
    ['rule ON, counter without the permission cannot approve own', ['kind' => 'opening', 'rule' => 'on', 'perm' => 'no', 'counter' => 'me', 'do' => 'approve_count'], 'submitted', 0],
    ['rule OFF, someone else\'s count still needs the permission', ['kind' => 'opening', 'rule' => 'off', 'perm' => 'no', 'counter' => 'other', 'do' => 'approve_count'], 'submitted', 0],
    ['rule OFF, an approver approves someone else\'s count (no self-approval note)', ['kind' => 'opening', 'rule' => 'off', 'perm' => 'yes', 'counter' => 'other', 'do' => 'approve_count'], 'approved', 0],
    ['rule OFF, counter without the permission rejects own', ['kind' => 'big', 'rule' => 'off', 'perm' => 'no', 'counter' => 'me', 'do' => 'reject_count'], 'rejected', 0],
    ['rule ON, counter without the permission cannot reject own', ['kind' => 'big', 'rule' => 'on', 'perm' => 'no', 'counter' => 'me', 'do' => 'reject_count'], 'submitted', 0],
    ['remind refused for someone who is neither counter nor approver', ['kind' => 'big', 'rule' => 'on', 'perm' => 'no', 'counter' => 'other', 'do' => 'remind_count'], 'submitted', 0],
    // One step when the two-person rule is OFF: submitting also approves. Rule ON: submit only.
    ['one step: rule OFF, counter without the permission submits own count', ['kind' => 'opening', 'rule' => 'off', 'perm' => 'no', 'counter' => 'me', 'do' => 'submit_count'], 'approved', 1],
    ['one step: rule OFF, approver submits an over-threshold count', ['kind' => 'big', 'rule' => 'off', 'perm' => 'yes', 'counter' => 'me', 'do' => 'submit_count'], 'approved', 1],
    ['one step: rule ON, submit only (still waits for approval)', ['kind' => 'opening', 'rule' => 'on', 'perm' => 'yes', 'counter' => 'me', 'do' => 'submit_count'], 'submitted', 0],
    ['one step: rule OFF, non-approver cannot submit someone else\'s draft', ['kind' => 'opening', 'rule' => 'off', 'perm' => 'no', 'counter' => 'other', 'do' => 'submit_count'], 'draft', 0],
];
foreach ($hs as [$label, $post, $wantStatus, $wantLog]) {
    $res = $hScenario($post);
    check(!empty($res['rolled_back']) && empty($res['leak']) && empty($res['marker_lost']), "Page ($label): ran and rolled back", json_encode($res['raw'] ?? $res));
    $st = (string)($res['verify'][0][0]['status'] ?? '?');
    check($st === $wantStatus, "Page ($label): count ends $wantStatus", $st . ' ' . json_encode($res['output_flash'] ?? []));
    check((int)($res['verify'][1][0]['n'] ?? -1) === $wantLog, "Page ($label): own-count approval note " . ($wantLog ? 'written to the activity log' : 'not written'), json_encode($res['verify'][1] ?? null));
    if ($post['do'] === 'remind_count') {
        check((int)($res['verify'][2][0]['n'] ?? -1) === 0, 'Page (remind refused): no email was logged or sent');
    }
}

// --- Submit only the items that have a reading (items with no reading are left out of the count) -----------------------
$np = static fn(array $r, int $i): array => is_array($r['verify'][$i] ?? null) ? $r['verify'][$i] : [];
$names = static fn(array $rows): array => array_map(static fn($x) => (string)$x['name'], $rows);
$partial = [
    // label, post, wanted status, lines the count must hold afterwards (name after "ZZT <run> "), left-out log rows, text the page must show
    ['rule ON, opening count: blank left out, typed 0 and counted line kept', ['kind' => 'opening', 'rule' => 'on', 'perm' => 'yes', 'counter' => 'me', 'do' => 'submit_count', 'blanks' => 'yes'], 'submitted', ['Item', 'Zero'], 1, null],
    ['rule OFF one step: blank left out, approved with the read lines only', ['kind' => 'opening', 'rule' => 'off', 'perm' => 'no', 'counter' => 'me', 'do' => 'submit_count', 'blanks' => 'yes'], 'approved', ['Item', 'Zero'], 1, null],
    ['rule ON, regular count: blank left out', ['kind' => 'big', 'rule' => 'on', 'perm' => 'yes', 'counter' => 'me', 'do' => 'submit_count', 'blanks' => 'yes'], 'submitted', ['Item', 'Zero'], 1, null],
    ['all lines blank: refused, nothing deleted', ['kind' => 'big', 'rule' => 'on', 'perm' => 'yes', 'counter' => 'me', 'do' => 'submit_count', 'blanks' => 'allblank'], 'draft', ['Blank', 'Item'], 0, 'Nothing to submit: no item has a reading yet'],
    ['no blanks: unchanged, nothing logged as left out', ['kind' => 'big', 'rule' => 'on', 'perm' => 'yes', 'counter' => 'me', 'do' => 'submit_count'], 'submitted', ['Item'], 0, null],
];
foreach ($partial as [$label, $post, $wantStatus, $wantNames, $wantLog, $needle]) {
    $res = $hScenario($post, $needle === null ? [] : [$needle]);   // a success redirects before the page prints, so only a refusal can be read from the page
    check(!empty($res['rolled_back']) && empty($res['leak']) && empty($res['marker_lost']), "Partial submit ($label): ran and rolled back", json_encode($res['raw'] ?? $res));
    check((string)($res['verify'][0][0]['status'] ?? '?') === $wantStatus, "Partial submit ($label): count ends $wantStatus", json_encode($res['verify'][0] ?? null));
    $want = array_map(static fn($n) => 'ZZT ' . $hRun . ' ' . $n, $wantNames); sort($want);
    $got = $names($np($res, 3));
    check($got === $want, "Partial submit ($label): the count holds exactly the lines " . implode(', ', $wantNames), json_encode($got));
    check((int)($np($res, 5)[0]['n'] ?? -1) === $wantLog, "Partial submit ($label): left-out items " . ($wantLog ? 'logged' : 'not logged'), json_encode($np($res, 5)));
    if ($needle !== null) check(!empty($res['found'][$needle]), "Partial submit ($label): page says \"$needle\"", json_encode($res['found'] ?? null));
    if (in_array('Zero', $wantNames, true)) {
        $zero = null;
        foreach ($np($res, 3) as $row) { if (substr((string)$row['name'], -5) === ' Zero') { $zero = (float)$row['actual_quantity']; } }
        check($zero !== null && abs($zero) < 0.00005, "Partial submit ($label): the typed 0 stays as a reading of 0");
    }
    if (strpos($label, 'opening count') !== false) {
        check((int)($np($res, 4)[0]['n'] ?? -1) === 0, "Partial submit ($label): the left-out item is in no open opening count, so a new opening count can start with it", json_encode($np($res, 4)));
    }
}

// The wording helpers of the page, taken from its source (the page itself redirects before printing on success).
$pageSrc = (string)file_get_contents(__DIR__ . '/../../admin/stock-count.php');
foreach (['scLeftOutSentence', 'scBannerTitle'] as $fn) {
    if (preg_match('/^function ' . $fn . '\(.*?^\}/ms', $pageSrc, $fm)) { eval($fm[0]); }
}
if (function_exists('scLeftOutSentence') && function_exists('scBannerTitle')) {
    $one = scLeftOutSentence(['Flour']);
    $many = scLeftOutSentence(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J']);
    check(scLeftOutSentence([]) === '', 'Wording: nothing left out says nothing');
    check(strpos($one, '1 item had no reading and was left out of this count: Flour.') === 0 && strpos($one, 'later count once you have it') !== false, 'Wording: one item left out', $one);
    check(strpos($many, '10 items had no reading and were left out of this count: A, B, C, D, E, F, G, H and 2 more.') === 0 && strpos($many, 'once you have them') !== false, 'Wording: many items left out, first eight named', $many);
    check(scBannerTitle('x', true, 'submit_count') === 'Can’t submit yet' && scBannerTitle('x', true, 'approve_count') === 'Not approved' && scBannerTitle('x', true, '') === 'Something needs fixing', 'Banner: error titles come from the action, generic fallback');
    check(scBannerTitle('Count 1 submitted with 3 items.', false, '') === 'Count submitted' && scBannerTitle('Count 1 submitted and approved. Variances applied to stock.', false, '') === 'Count submitted and approved' && scBannerTitle('Count 1 approved. Variances applied to stock.', false, '') === 'Count approved' && scBannerTitle('Not approved, and nothing was posted', false, '') === 'Not approved' && scBannerTitle('whatever', false, '') === 'Done', 'Banner: success titles come from the message, generic fallback');
} else {
    bad('Wording: scLeftOutSentence / scBannerTitle not found in admin/stock-count.php');
}
check(strpos($pageSrc, 'Every line must have a reading') === false && strpos($pageSrc, "showAlert(htmlspecialchars((string)\$message") === false && strpos($pageSrc, 'id="scBannerOk"') !== false, 'Page: old "every line must have a reading" wording and the timed toast are gone; the banner has its OK button');

// The setting must end exactly as it started (the harness and this test both roll back).
$setRowAfter = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'stock_count_second_approver'")->fetch(PDO::FETCH_NUM);
check($setRowAfter === $setRowBefore, 'Leak: the stock_count_second_approver setting is exactly as it was', json_encode([$setRowBefore, $setRowAfter]));

// --- Leak check -----------------------------------------------------------------------------------------------------
check((int)col($pdo, "SELECT COUNT(*) FROM stock_counts WHERE reference LIKE '20991231-%'") === 0, 'Leak: no test stock counts left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM admin_users WHERE username LIKE 'zzt\_sca\_%'") === 0, 'Leak: no test users left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM admin_activity_log WHERE details LIKE '%20991231-%'") === 0, 'Leak: no test activity log rows left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM user_permissions up LEFT JOIN admin_users u ON u.id = up.user_id WHERE u.id IS NULL") === $orphanPerm, 'Leak: no stray permission rows left behind');
$submittedAfter = $pdo->query("SELECT id, status, updated_at FROM stock_counts WHERE status = 'submitted' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check($submittedAfter === $submittedBefore, 'Leak: the counts waiting for approval were not touched');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
