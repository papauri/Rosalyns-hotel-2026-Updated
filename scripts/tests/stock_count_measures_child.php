<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/stock_count_measures.php (Step U1: stock counted in shots or glasses). Run by
 * page_harness.php: ONE outer transaction that can never commit, rolled back at the end. The harness signs in as an admin;
 * the permission scenarios switch the session to a made-up ZZT user created (and rolled back) here.
 *
 * POST fields: run, scenario. Builds ZZT items, counts, lines, batches and adjustments, then posts to (or renders) the
 * REAL admin/stock-count.php or admin/stock-ingredients.php page as a browser would.
 */
$run = (string)($_POST['run'] ?? 'X');
$scn = (string)($_POST['scenario'] ?? '');
$csrfTok = (string)($_POST['csrf_token'] ?? '');
$me = (int)($_SESSION['admin_user_id'] ?? 0);
$P = 'ZZT MS ' . $run . ' ';
$R = '20991231-MS' . $run;

$mkIng = function (string $tag, string $unit, float $qty, float $cost, ?array $m) use ($pdo, $P): int {
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, measure_big_name, measure_small_name, measure_per_big, measure_stock_side) VALUES (?, 'Bar', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$P . $tag, $unit, $qty, $cost, $m[0] ?? null, $m[1] ?? null, $m[2] ?? null, $m[3] ?? null]);
    return (int)$pdo->lastInsertId();
};
$mkCount = function (string $tag, string $status, int $opening = 0) use ($pdo, $R, $me): int {
    $appr = $status === 'approved';
    $pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, is_opening, created_at, snapshot_at, approved_by, approved_at)
        VALUES (?, CURDATE(), 'full', ?, ?, ?, NOW() - INTERVAL 20 MINUTE, NOW() - INTERVAL 20 MINUTE, ?, " . ($appr ? 'NOW() - INTERVAL 10 MINUTE' : 'NULL') . ")")
        ->execute([$R . $tag, $status, $me, $opening, $appr ? $me : null]);
    return (int)$pdo->lastInsertId();
};
/** $e = [mode, big, small, n] or null */
$mkLine = function (int $cid, int $ing, float $sys, ?float $act, float $cost, ?array $e = null, ?int $adj = null) use ($pdo): int {
    $var = $act === null ? 0 : round($act - $sys, 6);
    $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost, reason_code, adjustment_id, counted_at, entry_mode, entry_big, entry_small, entry_per_big)
        VALUES (?,?,?,?,?,?,?,'',?," . ($act === null ? 'NULL' : 'NOW() - INTERVAL 15 MINUTE') . ",?,?,?,?)")
        ->execute([$cid, $ing, $sys, $act ?? 0, $var, $cost, round($var * $cost, 4), $adj, $e[0] ?? null, $e[1] ?? null, $e[2] ?? null, $e[3] ?? null]);
    return (int)$pdo->lastInsertId();
};
/** An approved count line that added $var to stock: batch "Stock count surplus REF", +variance adjustment, totals. */
$mkApproved = function (string $tag, int $ing, float $sys, float $act, float $cost, int $opening = 0, ?array $e = null) use ($pdo, $mkCount, $mkLine, $R, $me): array {
    $cid = $mkCount($tag, 'approved', $opening);
    $var = round($act - $sys, 6);
    $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by, created_at) VALUES (?, ?, ?, 'variance', ?, ?, ?, NOW() - INTERVAL 10 MINUTE)")
        ->execute([$ing, $var, 'Stock count ' . $R . $tag . ' (no_code)', $cid, $cost, $me]);
    $adj = (int)$pdo->lastInsertId();
    $lid = $mkLine($cid, $ing, $sys, $act, $cost, $e, $adj);
    $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, expiry_alert_days, status, notes, created_by) VALUES (?, ?, ?, ?, ?, CURDATE(), 7, 'active', ?, ?)")
        ->execute([$ing, 'ZZT-MS-' . $tag . '-' . $lid, $var, $var, $cost, 'Stock count surplus ' . $R . $tag, $me]);
    $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + ? WHERE id = ?")->execute([$var, $ing]);
    $pdo->prepare("UPDATE stock_counts SET total_variance_cost = ?, surplus_cost = ?, shortage_cost = 0 WHERE id = ?")->execute([round($var * $cost, 4), round($var * $cost, 4), $cid]);
    return [$cid, $lid];
};
/** A sale after the count: -$q off the newest batch of $ing and the item. */
$sell = function (int $ing, float $q) use ($pdo, $me): void {
    $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by) VALUES (?, ?, 'ZZT sale', 'manual', NULL, 300, ?)")->execute([$ing, -$q, $me]);
    $pdo->prepare("UPDATE stock_batches SET quantity_remaining = quantity_remaining - ? WHERE ingredient_id = ? ORDER BY id DESC LIMIT 1")->execute([$q, $ing]);
    $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity - ? WHERE id = ?")->execute([$q, $ing]);
};
/** Sign the session in as a made-up user with only the given permissions (rolled back with everything else). */
$asUser = function (string $role, array $grants) use ($pdo, $run): void {
    $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, 'ZZT Measure Tester', ?, 1)")
        ->execute(['zztms' . strtolower($run) . $role, 'zztms' . strtolower($run) . $role . '@example.invalid', password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), $role]);
    $uid = (int)$pdo->lastInsertId();
    $gp = $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, ?, ?)");
    foreach ($grants as $k => $g) { $gp->execute([$uid, $k, $g ? 1 : 0]); }
    session_start();
    $_SESSION['admin_user_id'] = $uid;
    $_SESSION['admin_username'] = 'zztms' . strtolower($run) . $role;
    $_SESSION['admin_role'] = $role;
    $_SESSION['admin_full_name'] = 'ZZT Measure Tester';
    $_SESSION['admin_user'] = ['id' => $uid, 'username' => $_SESSION['admin_username'], 'full_name' => 'ZZT Measure Tester', 'role' => $role];
    $_SESSION['admin_last_activity'] = time();
    session_write_close();
};

$mB = ['bottle', 'shot', 30, 'big'];
$page = 'stock-count.php';
$post = ['csrf_token' => $csrfTok];
$get = [];

switch ($scn) {
    case 'save_modes': // typed in shots / both / stock on a draft count, sent through lines_json like the page does
        $gin = $mkIng('Gin 750ml', 'bottle', 10, 300, $mB);
        $tot = $mkIng('Tot rum', 'shot', 40, 10, ['bottle', 'shot', 30, 'small']);
        $vod = $mkIng('Vodka', 'bottle', 4, 300, $mB);
        $wat = $mkIng('Water', 'bottle', 8, 50, null);
        $cid = $mkCount('A', 'draft');
        $l1 = $mkLine($cid, $gin, 10, null, 300);
        $l2 = $mkLine($cid, $tot, 40, null, 10);
        $l3 = $mkLine($cid, $vod, 4, null, 300);
        $l4 = $mkLine($cid, $wat, 8, null, 50);
        $post += ['action' => 'save_lines', 'count_id' => (string)$cid, 'lines_json' => [json_encode([
            ['id' => $l1, 'actual' => '999', 'touched' => '1', 'mode' => 'small', 'big' => '', 'small' => '602'], // page figure ignored
            ['id' => $l2, 'actual' => '67', 'touched' => '1', 'mode' => 'both', 'big' => '2', 'small' => '7'],
            ['id' => $l3, 'actual' => '3', 'touched' => '1', 'mode' => 'stock', 'big' => '', 'small' => ''],
            ['id' => $l4, 'actual' => '6', 'touched' => '1', 'mode' => 'small', 'big' => '', 'small' => '99'], // no measure: stock unit
        ])]];
        break;

    case 'save_bad_mode':
        $gin = $mkIng('Gin', 'bottle', 10, 300, $mB);
        $cid = $mkCount('A', 'draft');
        $l1 = $mkLine($cid, $gin, 10, null, 300);
        $post += ['action' => 'save_lines', 'count_id' => (string)$cid, 'lines_json' => [json_encode([['id' => $l1, 'actual' => '1', 'touched' => '1', 'mode' => 'litre', 'small' => '5']])]];
        break;

    case 'reconvert': // N 30 -> 20 from a draft line: draft + submitted lines re-worked, approved one untouched
        $gin = $mkIng('Gin', 'bottle', 10, 300, $mB);
        $d = $mkCount('A', 'draft');
        $dl = $mkLine($d, $gin, 10, 2, 300, ['small', null, 60, 30]);
        $s = $mkCount('B', 'submitted');
        $mkLine($s, $gin, 10, 3, 300, ['small', null, 90, 30]);
        $pdo->prepare("UPDATE stock_counts SET total_variance_cost = -2100, shortage_cost = -2100 WHERE id = ?")->execute([$s]);
        $a = $mkCount('C', 'approved');
        $mkLine($a, $gin, 10, 1, 300, ['small', null, 30, 30]);
        $post += ['action' => 'set_measure', 'count_id' => (string)$d, 'line_id' => (string)$dl, 'measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '20', 'measure_stock_side' => 'big'];
        break;

    case 'line_mode': // approver: a submitted line typed as 602 bottles was really 602 shots
        $gin = $mkIng('Gin', 'bottle', 10, 300, $mB);
        $s = $mkCount('A', 'submitted');
        $sl = $mkLine($s, $gin, 10, 602, 300, ['stock', null, null, 30]);
        $pdo->prepare("UPDATE stock_counts SET total_variance_cost = 177600, surplus_cost = 177600 WHERE id = ?")->execute([$s]);
        $post += ['action' => 'set_measure', 'count_id' => (string)$s, 'line_id' => (string)$sl, 'measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '30', 'measure_stock_side' => 'big', 'line_mode' => 'small'];
        break;

    case 'remove_refused':
        $gin = $mkIng('Gin', 'bottle', 10, 300, $mB);
        $d = $mkCount('A', 'draft');
        $dl = $mkLine($d, $gin, 10, 2, 300, ['small', null, 60, 30]);
        $post += ['action' => 'set_measure', 'count_id' => (string)$d, 'line_id' => (string)$dl, 'measure_big_name' => '', 'measure_small_name' => '', 'measure_per_big' => '', 'measure_stock_side' => 'big'];
        break;

    case 'bad_n':
        $gin = $mkIng('Gin', 'bottle', 10, 300, $mB);
        $d = $mkCount('A', 'draft');
        $dl = $mkLine($d, $gin, 10, null, 300);
        $post += ['action' => 'set_measure', 'count_id' => (string)$d, 'line_id' => (string)$dl, 'measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '1001', 'measure_stock_side' => 'big'];
        break;

    case 'approved_set_refused': // Set measure on an approved count: nothing re-worked
        $gin = $mkIng('Gin', 'bottle', 10, 300, $mB);
        [$cid, $lid] = $mkApproved('A', $gin, 10, 602, 300);
        $post += ['action' => 'set_measure', 'count_id' => (string)$cid, 'line_id' => (string)$lid, 'measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '20', 'measure_stock_side' => 'big'];
        break;

    case 'fix_preview':
    case 'fix_apply':
    case 'fix_csrf':
    case 'perm_fix':
        // Counted 602 typed as bottles (really shots); 10 should have been there; 5 sold since approval.
        $gin = $mkIng('Gin', 'bottle', 10, 300, $mB);
        [$cid, $lid] = $mkApproved('A', $gin, 10, 602, 300);
        $sell($gin, 5);
        if ($scn === 'fix_preview') {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $post = [];
            $get = ['id' => (string)$cid, 'fix' => (string)$lid];
        } else {
            $post += ['action' => 'measure_fix', 'count_id' => (string)$cid, 'line_id' => (string)$lid];
            if ($scn === 'fix_csrf') { $post['csrf_token'] = 'not-the-token'; }
            if ($scn === 'perm_fix') { $asUser('manager', ['stock_count' => 1, 'stock_management' => 1, 'stock_count_approve' => 0]); }
        }
        break;

    case 'fix_avg': // the item already held 10 bottles at 500; the 592 surplus came in at 300 (avg 303.3223); the line has no cost
        $gin = $mkIng('Gin', 'bottle', 10, 303.3223, $mB);
        [$cid, $lid] = $mkApproved('A', $gin, 10, 602, 300);
        $pdo->prepare("UPDATE stock_count_lines SET cost_per_unit = 0, variance_cost = 0 WHERE id = ?")->execute([$lid]);
        $post += ['action' => 'measure_fix', 'count_id' => (string)$cid, 'line_id' => (string)$lid];
        break;

    case 'radar': // Missing stock + Needs review leave out a measure correction, but not a hand take-off (even one typed like it)
        $gin = $mkIng('Gin', 'bottle', 20.066667, 300, $mB);
        [$cid, $lid] = $mkApproved('A', $gin, 10, 602, 300);
        $adjQ = $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by) VALUES (?, ?, ?, 'manual', ?, ?, ?)");
        $adjQ->execute([$gin, -581.933333, 'Stock count ' . $R . 'A correction: counted in shots, not bottles (602 shots = 20.066667 bottle)', $cid, 300, $me]);
        $ginAdj = (int)$pdo->lastInsertId();
        $lime = $mkIng('Lime', 'kg', 7, 1000000, null);
        $adjQ->execute([$lime, -3, 'ZZT taken off by hand', null, 1000000, $me]);
        $limeAdj = (int)$pdo->lastInsertId();
        $rum = $mkIng('Rum', 'bottle', 7, 900000, null);
        $adjQ->execute([$rum, -3, 'Stock count X correction: typed by hand', null, 900000, $me]);
        $rumAdj = (int)$pdo->lastInsertId();
        require_once $root . '/includes/stock-loss.php';
        $rvKeys = array_column(rh_stock_review_items($pdo, 10, 5000, ''), 'key');
        foreach (['GIN' => $ginAdj, 'LIME' => $limeAdj, 'RUM' => $rumAdj] as $k => $aid) {
            echo in_array('adj:' . $aid, $rvKeys, true) ? "@@RV_{$k}_IN@@\n" : "@@RV_{$k}_OUT@@\n";
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $post = [];
        $get = [];
        break;

    case 'fix_opening': // opening count (nothing on the system) counted in shots
        $gin = $mkIng('Gin', 'bottle', 0, 300, $mB);
        [$cid, $lid] = $mkApproved('A', $gin, 0, 602, 300, 1);
        $post += ['action' => 'measure_fix', 'count_id' => (string)$cid, 'line_id' => (string)$lid];
        break;

    case 'fix_again': // already corrected (line now in shots): refused, nothing changes
        $gin = $mkIng('Gin', 'bottle', 10, 300, $mB);
        [$cid, $lid] = $mkApproved('A', $gin, 10, 20.066667, 300, 0, ['small', null, 602, 30]);
        $post += ['action' => 'measure_fix', 'count_id' => (string)$cid, 'line_id' => (string)$lid];
        break;

    case 'fix_shortage': // 31 counted vs 30 expected: read as shots it is a shortage -> refused
        $gin = $mkIng('Gin', 'bottle', 30, 300, $mB);
        [$cid, $lid] = $mkApproved('A', $gin, 30, 31, 300);
        $post += ['action' => 'measure_fix', 'count_id' => (string)$cid, 'line_id' => (string)$lid];
        break;

    case 'fix_sold': // sales since the count used more than the corrected count leaves in the batch -> refused
        $gin = $mkIng('Gin', 'bottle', 10, 300, $mB);
        [$cid, $lid] = $mkApproved('A', $gin, 10, 602, 300);
        $sell($gin, 15);
        $post += ['action' => 'measure_fix', 'count_id' => (string)$cid, 'line_id' => (string)$lid];
        break;

    case 'perm_measure': // a counter without Stock Items or Approve permission can't set a measure
        $gin = $mkIng('Gin', 'bottle', 10, 300, null);
        $d = $mkCount('A', 'draft');
        $dl = $mkLine($d, $gin, 10, null, 300);
        $asUser('manager', ['stock_count' => 1, 'stock_management' => 0, 'stock_count_approve' => 0]);
        $post += ['action' => 'set_measure', 'count_id' => (string)$d, 'line_id' => (string)$dl, 'measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '30', 'measure_stock_side' => 'big'];
        break;

    case 'render_draft': // the count page shows the measure controls on a draft line
        $gin = $mkIng('Gin 750ml', 'bottle', 10, 300, $mB);
        $wat = $mkIng('Water', 'bottle', 8, 50, null);
        $d = $mkCount('A', 'draft');
        $mkLine($d, $gin, 10, 2, 300, ['both', 1, 30, 30]);
        $mkLine($d, $wat, 8, null, 50);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $post = [];
        $get = ['id' => (string)$d];
        break;

    case 'ing_update': // Stock Items edit: measure saved, logged; the open draft line typed in shots is re-worked
    case 'ing_bad':
    case 'ing_no_fields':
        $gin = $mkIng('Gin', 'bottle', 10, 300, $scn === 'ing_update' ? $mB : $mB);
        $d = $mkCount('A', 'draft');
        $mkLine($d, $gin, 10, 2, 300, ['small', null, 60, 30]);
        $page = 'stock-ingredients.php';
        $post += ['action' => 'update', 'id' => (string)$gin, 'name' => $P . 'Gin', 'category' => 'Bar', 'unit' => 'bottle', 'min_quantity' => '0', 'reorder_point' => '0', 'par_level' => '0',
            'yield_percent' => '100', 'lead_time_days' => '0', 'notes' => '', 'preferred_supplier_id' => '', 'pack_name' => '', 'pack_size' => '', 'pieces_per_unit' => ''];
        if ($scn !== 'ing_no_fields') {
            $post += ['measure_present' => '1', 'measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => $scn === 'ing_bad' ? '0' : '25', 'measure_stock_side' => 'big'];
        }
        break;

    default:
        echo "unknown scenario\n";
        exit;
}

$_POST = $post;
$_GET = $get;
$_REQUEST = $_POST + $_GET;
chdir($root . '/admin');
require $root . '/admin/' . $page;
