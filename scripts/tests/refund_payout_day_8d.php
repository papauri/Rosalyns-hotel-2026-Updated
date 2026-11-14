<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Step 8d: a refund counts on the day it was PAID OUT in the last places that still read payment_date (owner rule
 * 2026-11-14/15): Dashboard "Total Revenue Today" feed, api/payments.php list + summary date filters, company money
 * received net (rh_billing_received_net), Accounting dashboard payment-method counts, Payments list day + sort.
 * A refund still pending counts in no period. Runs at LIWONDE only (the test platform; Rosalyn's is live).
 * Every write is inside a transaction that is rolled back (library checks here; pages in page_harness.php; the API
 * case in a child process of this file whose shutdown rolls back). Nothing is committed.
 * Usage (repo root): php scripts/tests/refund_payout_day_8d.php
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require $root . '/config/database.php';
if (stripos((string)getSetting('site_name', ''), 'liwonde') === false && stripos($root, 'Liwonde') === false) {
    echo "SKIP refund_payout_day_8d: runs at Liwonde (the test platform) only; Rosalyn's is live.\n";
    exit(0);
}
require_once $root . '/admin/includes/finance-refunds.php';

// Far-past days (no real rows) for the API / Accounting cases: raised on D1, paid out on D2.
const T8D_D1 = '2001-03-10';
const T8D_D2 = '2001-03-12';

/** Fixture SQL: refund PAID (raised $raised, paid out at $paidAt), refund PEND (pending, dated $pendDay). Comment-free. */
function t8d_fixture_sql(string $mk, array $orig, string $raised, string $paidAt, string $pendDay, string $method = 'cash'): array
{
    $bid = (int)$orig['booking_id']; $oid = (int)$orig['id'];
    $bref = preg_replace('/[^A-Za-z0-9\-_]/', '', (string)$orig['booking_reference']);
    $m = preg_replace('/[^a-z_]/', '', $method);
    $ins = "INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, original_payment_id, refund_reason, refund_status, refund_amount, recorded_by, created_at) VALUES ";
    return [
        $ins . "('T8D-PAID-$mk', 'room', $bid, '$bref', $raised, 1.00, 0, 0, 1.00, '$m', 'refund', 'completed', $oid, 'other', 'completed', 1.00, NULL, CONCAT($raised, ' 09:00:00'))",
        $ins . "('T8D-PEND-$mk', 'room', $bid, '$bref', $pendDay, 1.00, 0, 0, 1.00, '$m', 'refund', 'pending', $oid, 'other', 'pending', 1.00, NULL, CONCAT($pendDay, ' 09:00:00'))",
        "INSERT INTO payment_audit_log (payment_id, payment_reference, action, field_name, old_value, new_value, performed_by_name, created_at)
            SELECT id, payment_reference, 'refund_completed', 'refund_status', 'pending', 'completed', 'test', $paidAt FROM payments WHERE payment_reference = 'T8D-PAID-$mk'",
    ];
}
function t8d_orig(PDO $pdo): ?array
{
    $r = $pdo->query("SELECT * FROM payments WHERE booking_type = 'room' AND deleted_at IS NULL AND COALESCE(payment_type,'') <> 'refund'
        AND payment_status IN ('completed','paid') ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

// ───────────── child mode: one GET /api/payments through the real endpoint code, rolled back at shutdown ─────────────
if (($argv[1] ?? '') === '--api') {
    $day = (string)($argv[2] ?? '');
    define('API_ACCESS_ALLOWED', true);
    $auth = new class { public function checkPermission($client, string $perm): bool { return true; } };
    $client = ['id' => 0, 'client_name' => 'refund payout test'];
    $GLOBALS['rh_api_client'] = $client;
    final class ApiResponse {
        public static function success($data = null, $message = 'Success', $code = 200) { $GLOBALS['t8d_resp'] = ['ok' => true, 'data' => $data]; exit; }
        public static function error(string $message, $code = 400, $details = null) { $GLOBALS['t8d_resp'] = ['ok' => false, 'error' => $message]; exit; }
        public static function validationError($errors) { $GLOBALS['t8d_resp'] = ['ok' => false, 'error' => json_encode($errors)]; exit; }
    }
    require_once $root . '/includes/finance-sequences.php';
    finance_ensure_sequence_tables($pdo); // SELECT-only when provisioned: safe before the transaction
    $orig = t8d_orig($pdo);
    $mk = (string)random_int(10000, 99999);
    $pdo->beginTransaction();
    register_shutdown_function(function () use ($pdo, $mk) {
        $resp = $GLOBALS['t8d_resp'] ?? ['ok' => false, 'error' => 'no response'];
        $out = ['ok' => !empty($resp['ok']), 'error' => $resp['error'] ?? ''];
        if (!empty($resp['ok'])) {
            $out['summary'] = $resp['data']['summary'] ?? null;
            $out['refs'] = array_values(array_filter(array_map(static function ($p) { return (string)($p['payment_reference'] ?? ''); }, (array)($resp['data']['payments'] ?? [])),
                static function ($r) use ($mk) { return substr($r, -strlen($mk)) === $mk; }));
        }
        $leak = true;
        try { if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $s = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference LIKE ?"); $s->execute(['T8D-%-' . $mk]);
            $leak = (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) { $out['error'] .= ' rollback: ' . $e->getMessage(); }
        $out['leak'] = $leak;
        echo '@@T8D@@' . json_encode($out) . "\n";
    });
    if (!$orig) { $GLOBALS['t8d_resp'] = ['ok' => false, 'error' => 'no room payment at Liwonde']; exit; }
    foreach (t8d_fixture_sql($mk, $orig, "'" . T8D_D1 . "'", "'" . T8D_D2 . " 15:00:00'", "'" . T8D_D2 . "'") as $q) { $pdo->exec($q); }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/payments';
    $_GET = ['start_date' => $day, 'end_date' => $day, 'limit' => 100];
    require $root . '/api/payments.php';
    exit;
}

// ─────────────────────────────────────────────── parent ───────────────────────────────────────────────
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function near(float $a, float $b): bool { return abs($a - $b) <= 0.011; }

$orig = t8d_orig($pdo);
if (!$orig) { echo "SKIP refund_payout_day_8d: no completed room payment at Liwonde.\n"; exit(0); }

$h = $root . '/scripts/tests/page_harness.php';
function run_page(string $page, array $cfg): array {
    global $h;
    $out = (string)shell_exec('php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $r['raw'] = $out; return $r; } }
    return ['error' => 'no result', 'raw' => substr($out, -400), 'rolled_back' => false];
}
function harness_clean(array $r, string $l): void {
    check(!empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']), $l . ': rolled back, no leak', substr((string)($r['raw'] ?? json_encode($r)), -300));
    $raw = (string)($r['raw'] ?? '');
    check(stripos($raw, 'SQLSTATE') === false && stripos($raw, 'Fatal error') === false, $l . ': no SQL / PHP error', substr($raw, 0, 400));
}

// ---- 1. Dashboard "Total Revenue Today" feed: raised yesterday + paid out today is listed; pending dated today is not
$mk = (string)random_int(10000, 99999);
$setup = t8d_fixture_sql($mk, $orig, 'DATE_SUB(CURDATE(), INTERVAL 1 DAY)', 'NOW()', 'CURDATE()');
$r = run_page('admin/dashboard.php', ['method' => 'GET', 'get' => ['ajax' => 'card_insight', 'card' => 'total_revenue_today'], 'setup' => $setup,
    'grep' => ["T8D-PAID-$mk", "T8D-PEND-$mk"]]);
harness_clean($r, 'Dashboard feed');
check(!empty($r['found']["T8D-PAID-$mk"]), 'Dashboard feed: a refund raised yesterday and paid out today is listed today', substr((string)($r['raw'] ?? ''), -300));
check(empty($r['found']["T8D-PEND-$mk"]), 'Dashboard feed: a pending refund dated today is not listed');

// ---- 2. api/payments.php list + summary: counted on D2 (payout), not D1 (raised); the pending one nowhere
foreach ([T8D_D1 => 0.0, T8D_D2 => 1.0] as $day => $want) {
    $out = (string)shell_exec('php ' . escapeshellarg(__FILE__) . ' --api ' . escapeshellarg($day) . ' 2>&1');
    $res = preg_match('/@@T8D@@(.*)$/m', $out, $m) ? json_decode($m[1], true) : null;
    if (!check(is_array($res) && !empty($res['ok']), "API $day: endpoint answered", substr($out, -400))) { continue; }
    check(empty($res['leak']), "API $day: rolled back, nothing left behind");
    $refs = (array)($res['refs'] ?? []);
    $sum = (array)($res['summary'] ?? []);
    if ($want > 0) {
        check(near((float)($sum['total_refunded'] ?? -1), 1.0), "API summary $day (paid out): refunds total 1.00", json_encode($sum));
        check(count(array_filter($refs, static function ($x) { return strpos($x, 'T8D-PAID-') === 0; })) === 1, "API list $day: the paid-out refund is listed on its payout day", json_encode($refs));
    } else {
        check(near((float)($sum['total_refunded'] ?? -1), 0.0), "API summary $day (raised): refunds total 0.00", json_encode($sum));
        check(!in_array(true, array_map(static function ($x) { return strpos($x, 'T8D-PAID-') === 0; }, $refs), true), "API list $day: the paid-out refund is not on the day it was raised", json_encode($refs));
    }
}

// ---- 3 + 4. library / query checks in one rolled-back transaction
require_once $root . '/includes/billing-accounts.php';
$mk2 = (string)random_int(10000, 99999);
$dA = date('Y-m-d', strtotime('-20 days')); $dB = date('Y-m-d', strtotime('-18 days')); $dB1 = date('Y-m-d', strtotime('-17 days'));
$pdo->beginTransaction();
try {
    foreach (t8d_fixture_sql($mk2, $orig, "'$dA'", "'$dB 15:00:00'", "'$dB'") as $q) { $pdo->exec($q); }
    // 3. company money received net: a company account, one remittance line for the refunded payment (received before D1)
    if (rh_billing_ready($pdo)) {
        $pdo->prepare("INSERT INTO billing_accounts (name, billing_email, terms_days, active, created_at) VALUES (?, NULL, 30, 1, NOW())")->execute(['ZZ T8D CO ' . $mk2]);
        $acc = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO billing_remittances (billing_account_id, remittance_reference, received_date, payment_method, amount) VALUES (?, ?, ?, 'bank_transfer', 1000)")
            ->execute([$acc, 'T8D-REM-' . $mk2, date('Y-m-d', strtotime('-25 days'))]);
        $rem = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO billing_remittance_lines (remittance_id, booking_id, payment_id, amount) VALUES (?, ?, ?, 1000)")
            ->execute([$rem, (int)$orig['booking_id'], (int)$orig['id']]);
        check(near(rh_billing_received_net($pdo, $dB, $acc), -1.0), 'Company received net from the payout day: the refund (paid out that day) is taken off', (string)rh_billing_received_net($pdo, $dB, $acc));
        check(near(rh_billing_received_net($pdo, $dB1, $acc), 0.0), 'Company received net from the day after payout: the refund is not in it');
        check(near(rh_billing_received_net($pdo, $dA, $acc), -1.0), 'Company received net from the day raised: the refund once, the pending one never');
    } else {
        ok('Company received net: billing accounts not installed here (skipped)');
    }
    // 4. Accounting dashboard method breakdown (same SQL as admin/accounting-dashboard.php), limited to the fixture rows
    $mq = $pdo->prepare("SELECT payment_method,
            COALESCE(SUM(CASE WHEN COALESCE(payment_type, '') = 'refund' AND COALESCE(refund_status, '') NOT IN ('completed','processing') THEN 0 ELSE 1 END), 0) as count
        FROM payments WHERE " . rh_pay_count_day_sql() . " BETWEEN ? AND ? AND deleted_at IS NULL AND payment_reference LIKE ? GROUP BY payment_method");
    $mq->execute([$dB, $dB, 'T8D-%-' . $mk2]);
    check($mq->fetchAll(PDO::FETCH_KEY_PAIR) == ['cash' => 1], 'Accounting method count on the payout day: the paid-out refund once, the pending one not');
    $mq->execute([$dA, $dA, 'T8D-%-' . $mk2]);
    check($mq->fetchAll(PDO::FETCH_KEY_PAIR) == [], 'Accounting method count on the day raised: nothing');
    // 5. Payments list: shows / sorts the payout day (pay_day alias = rh_pay_count_day_sql('p'))
    $pq = $pdo->prepare("SELECT p.payment_reference, " . rh_pay_count_day_sql('p') . " AS pay_day FROM payments p WHERE p.payment_reference LIKE ? ORDER BY pay_day DESC, p.id DESC");
    $pq->execute(['T8D-%-' . $mk2]);
    $rows = $pq->fetchAll(PDO::FETCH_KEY_PAIR);
    check(($rows["T8D-PAID-$mk2"] ?? '') === $dB && ($rows["T8D-PEND-$mk2"] ?? '') === $dB, 'Payments list day: paid-out refund shows its payout day; pending keeps its own date', json_encode($rows));
} catch (Throwable $e) {
    bad('library checks threw', $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
$s = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference LIKE ?"); $s->execute(['T8D-%-' . $mk2]);
check((int)$s->fetchColumn() === 0, 'library checks rolled back, nothing left behind');

// ---- 4b. Accounting dashboard page: method panel on D2 counts the paid-out refund only (1), on D1 shows no payments
$mk3 = (string)random_int(10000, 99999);
$setup = t8d_fixture_sql($mk3, $orig, "'" . T8D_D1 . "'", "'" . T8D_D2 . " 15:00:00'", "'" . T8D_D2 . "'", 'cheque');
$r = run_page('admin/accounting-dashboard.php', ['method' => 'GET', 'get' => ['start_date' => T8D_D2, 'end_date' => T8D_D2], 'setup' => $setup,
    'grep' => ['&middot; 0.0% &middot; 1</span>', '&middot; 0.0% &middot; 2</span>', 'No payments in this period.']]);
harness_clean($r, 'Accounting dashboard ' . T8D_D2);
check(!empty($r['found']['&middot; 0.0% &middot; 1</span>']) && empty($r['found']['&middot; 0.0% &middot; 2</span>']), 'Accounting page payout day: method count 1 (the pending refund not counted)');
$r = run_page('admin/accounting-dashboard.php', ['method' => 'GET', 'get' => ['start_date' => T8D_D1, 'end_date' => T8D_D1], 'setup' => $setup,
    'grep' => ['No payments in this period.']]);
harness_clean($r, 'Accounting dashboard ' . T8D_D1);
check(!empty($r['found']['No payments in this period.']), 'Accounting page day raised: no payments in the method panel');

// ---- 5b. Payments page renders sorted by date (pay_day alias) with no SQL error
foreach ([[], ['sort' => 'date', 'dir' => 'asc'], ['start_date' => date('Y-m-01'), 'end_date' => date('Y-m-d')]] as $get) {
    $r = run_page('admin/payments.php', ['method' => 'GET', 'get' => $get]);
    harness_clean($r, 'Payments page ' . (json_encode($get) ?: ''));
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
