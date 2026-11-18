<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Group reconcile (group bookings plan step 6). Usage (repo root, TEST HOTEL ONLY):
 *   php scripts/tests/group_reconcile_test.php
 * Builds its OWN ICTAm-like group (references GRCT-*, emails @example.invalid, no real rows) inside ONE outer transaction
 * that is rolled back, one savepoint per scenario. Every scenario goes through the real helpers: early arrival, late
 * checkout, early departure with the unused-nights fee (stay correction), a no-show room that still owes, a payment on one
 * room, a whole-group payment, a group charge, a credit move, a primary switch, bundle / unbundle (with a company room).
 * After EVERY scenario:
 *   - identity: each room of rh_group_room_table() = its own folio summary = bookings.amount_due / amount_paid = the
 *     canonical net paid of its payment rows; the room set is exactly the group; owed never negative unless shown as credit;
 *   - deltas: End of Day (rh_eod_report_data: cash in, refunds paid out, net, Still owed) and rh_eod_outstanding_split move by
 *     exactly the test's own money (Liwonde has other rows today); earlier payment rows never change.
 * The connection is a copy of scripts/tests/page_harness.php's TxSafePdo: DDL is skipped (no implicit commit), a helper's
 * own begin / commit / rollback become savepoints. A marker row is probed from a second connection after every scenario.
 * Nothing is emailed, no PDF is written, nothing is registered in run_tests.php. Known LATER items print "KNOWN:" lines.
 */
$root = dirname(__DIR__, 2);
chdir($root);
if (basename($root) !== 'Liwonde_Sun_Hotel_2026') { // before ANY require: config/database.php connects on load
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/audit-functions.php';
require_once $root . '/admin/includes/finance-schema.php';
require_once $root . '/admin/includes/finance-account-sync.php';
require_once $root . '/admin/includes/finance-refunds.php';
require_once $root . '/includes/finance-sequences.php';
require_once $root . '/config/credit-notes.php';
require_once $root . '/config/invoice.php';
require_once $root . '/includes/booking-functions.php';
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/includes/booking-history-format.php';
require_once $root . '/includes/billing-accounts.php';
require_once $root . '/includes/idempotency.php';
require_once $root . '/includes/cancellation-policy.php';
require_once $root . '/includes/system-logger.php';
require_once $root . '/includes/stay-correction.php';
require_once $root . '/includes/group-room-table.php';
require_once $root . '/includes/booking-group-ops.php';
require_once $root . '/includes/group-credit-move.php';
require_once $root . '/admin/includes/group-payment.php';
require_once $root . '/admin/includes/charge-add.php'; // loads group-charge.php
require_once $root . '/admin/includes/receivables.php';
require_once $root . '/admin/includes/eod-revenue.php';
require_once $root . '/admin/includes/eod-report-data.php';

$pass = 0; $fail = 0; $findings = []; $notes = [];
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$tol = (float)BALANCE_TOLERANCE;
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= (float)BALANCE_TOLERANCE;
$f2 = static fn($v): string => number_format((float)$v, 2, '.', '');

/** Copy of scripts/tests/page_harness.php TxSafePdo: no statement can commit the outer transaction. */
class GrctTxSafePdo extends PDO
{
    private $savepoints = 0;
    public static function commitsImplicitly(string $sql): bool
    {
        $s = ltrim((string)preg_replace('#^(\s+|/\*(?!!).*?\*/|(--|\#)[^\n]*\n)+#s', '', $sql));
        $bare = (string)preg_replace('#\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`#s', "''", $s);
        if (preg_match('#;\s*\S#', rtrim($bare, "; \t\r\n")) || strpos($bare, '/*!') !== false) {
            return true;
        }
        if (preg_match('#^(CREATE|DROP)\s+TEMPORARY\s#i', $s)) {
            return false;
        }
        return (bool)preg_match('#^(CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES|COMMIT|START\s+TRANSACTION|BEGIN|ANALYZE|OPTIMIZE|REPAIR|CHECK\s+TABLE|FLUSH|RESET|CACHE\s+INDEX|LOAD\s+INDEX|GRANT|REVOKE|SET\s+PASSWORD|INSTALL|UNINSTALL)\b#i', $s)
            || (bool)preg_match('#^SET\s+(GLOBAL\s+|SESSION\s+|LOCAL\s+)?(@@(global\.|session\.|local\.)?)?autocommit\b#i', $s);
    }
    private function skip(string $sql): void { $GLOBALS['__grct_ddl'][] = substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 120); }
    public function beginTransaction(): bool
    {
        if (!$this->inTransaction()) {
            return parent::beginTransaction();
        }
        $this->savepoints++;
        parent::exec('SAVEPOINT grct_h' . $this->savepoints);
        return true;
    }
    public function commit(): bool
    {
        if ($this->savepoints > 0) {
            parent::exec('RELEASE SAVEPOINT grct_h' . $this->savepoints);
            $this->savepoints--;
        }
        return true;
    }
    #[\ReturnTypeWillChange]
    public function rollBack()
    {
        if ($this->savepoints > 0) {
            parent::exec('ROLLBACK TO SAVEPOINT grct_h' . $this->savepoints);
            parent::exec('RELEASE SAVEPOINT grct_h' . $this->savepoints);
            $this->savepoints--;
        } else {
            $GLOBALS['__grct_ddl'][] = 'rollBack() with no open helper transaction - ignored';
        }
        return true;
    }
    public function realRollback(): void { if ($this->inTransaction()) { parent::rollBack(); } }
    #[\ReturnTypeWillChange]
    public function exec($statement) { if (self::commitsImplicitly((string)$statement)) { $this->skip((string)$statement); return 0; } return parent::exec($statement); }
    #[\ReturnTypeWillChange]
    public function query($statement, $mode = null, ...$args)
    {
        if (self::commitsImplicitly((string)$statement)) { $this->skip((string)$statement); return parent::query('SELECT 0'); }
        return $mode === null ? parent::query($statement) : parent::query($statement, $mode, ...$args);
    }
    #[\ReturnTypeWillChange]
    public function prepare($statement, $options = [])
    {
        if (self::commitsImplicitly((string)$statement)) { $this->skip((string)$statement); return parent::prepare('SELECT 0'); }
        return parent::prepare($statement, $options);
    }
}
$GLOBALS['__grct_ddl'] = [];

// ---- warm-up on the normal connection (anything that might run DDL runs here, outside any transaction) ----
$warm = (int)$pdo->query("SELECT id FROM bookings WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($warm > 0) { getBookingFolioSummary($warm); }
rh_billing_ready($pdo);
finance_ensure_sequence_tables($pdo);
if (function_exists('rh_cancel_policy_column_ready')) { rh_cancel_policy_column_ready($pdo); }
if (function_exists('receipt_ensure_schema')) { receipt_ensure_schema($pdo); }
if (function_exists('ensureBookingAuditTable')) { ensureBookingAuditTable($pdo); }
if (function_exists('rh_ensure_system_event_log_table')) { rh_ensure_system_event_log_table($pdo); }
rh_receivable_rows($pdo, rh_receivable_mods());

// The original connection stays in autocommit: it is the SECOND connection (leak probe, final leak check).
$chk = $pdo;
$pdoOpts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true];
$pdo = new GrctTxSafePdo('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, $pdoOpts);
$pdo->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
$sqlMode = (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
if (stripos($sqlMode, 'STRICT_TRANS_TABLES') === false) {
    $pdo->exec("SET SESSION sql_mode = " . $pdo->quote(trim($sqlMode . ',STRICT_TRANS_TABLES', ',')));
}
$GLOBALS['pdo'] = $pdo;

// ---- the app's day ----
$today = rh_eod_today();
$dbToday = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
ok("dates agree: app day $today = PHP " . date('Y-m-d') . " = DB $dbToday", $today === $dbToday && $today === date('Y-m-d'));
$day = static fn(int $off): string => date('Y-m-d', strtotime($today . ' ' . ($off >= 0 ? '+' : '') . $off . ' day'));

$admin = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$user = ['id' => (int)$admin['id'], 'username' => (string)$admin['username'], 'full_name' => (string)$admin['full_name']];
$GLOBALS['user'] = $user;
$opts = ['user_id' => $user['id'], 'user_name' => 'group_reconcile_test'];
$ROOM = (int)$pdo->query('SELECT id FROM rooms WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$HAS_SNAP = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'cancellation_policy_snapshot'")->fetchColumn();
$BILL_ON = rh_billing_ready($pdo);
$vatRate = rh_vat_enabled() ? (float)getSetting('vat_rate') : 0.0;
$txnCol = finance_payment_transaction_column($pdo);
$mods = rh_receivable_mods();
$ictamBefore = $chk->query("SELECT GROUP_CONCAT(CONCAT(id, ':', COALESCE(primary_booking_id, 0), ':', amount_paid) ORDER BY id) FROM bookings WHERE id = 2100008837 OR primary_booking_id = 2100008837")->fetchColumn();
function grct_pdf_set(string $root): array {
    $set = [];
    foreach (['invoices', 'credit-notes', 'receipts'] as $d) {
        if (!is_dir($root . '/' . $d)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') { $set[$f->getPathname()] = (int)$f->getMTime(); }
        }
    }
    return $set;
}
$pdfBefore = grct_pdf_set($root);
$settingsBefore = $GLOBALS['_SITE_SETTINGS'] ?? null;
$setSetting = static function (string $k, ?string $v): void { if ($v === null) { unset($GLOBALS['_SITE_SETTINGS'][$k]); } else { $GLOBALS['_SITE_SETTINGS'][$k] = $v; } };
$keepNoshowMode = $GLOBALS['_SITE_SETTINGS']['group_noshow_charge'] ?? null;
$keepNoshowPolicy = $GLOBALS['_SITE_SETTINGS']['no_show_refund_policy'] ?? null;

// ---- fixtures (gs_booking pattern: 1,165 a night all-in = 1,000 net + 165 VAT, no levy) ----
$PID = getmypid();
$n = 0;
$ids = [];        // every marker booking id (for snapshots and the leak check)
$mkRoom = static function (string $status, string $in, string $out, ?int $primary, ?string $snap = null) use ($pdo, $ROOM, $HAS_SNAP, $PID, &$n, &$ids): int {
    $n++;
    $ref = 'GRCT-' . $PID . '-' . $n;
    $nights = (int)(new DateTime($in))->diff(new DateTime($out))->days;
    $cols = 'booking_reference, room_id, primary_booking_id, guest_name, room_guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
        child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
        vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at';
    $vals = "?,?,?,'GRCT TEST',?,?,'+265000000009','Malawi',1,1,0,50,?,?,?,?,0,0,0,16.5,?,?,'group reconcile test GRCT',?,'unpaid',0,'single',?,NOW()";
    $args = [$ref, $ROOM, $primary, 'Grct Guest ' . $n, 'grct' . $n . '@example.invalid', $in, $out, $nights, 1000 * $nights, 165 * $nights, 1165 * $nights, $status, bin2hex(random_bytes(16))];
    if ($HAS_SNAP) { $cols .= ', cancellation_policy_snapshot'; $vals .= ',?'; $args[] = $snap; }
    $pdo->prepare("INSERT INTO bookings ($cols) VALUES ($vals)")->execute($args);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    $ids[] = $id;
    return $id;
};
$refOf = static function (int $id) use ($pdo): string { $st = $pdo->prepare('SELECT booking_reference FROM bookings WHERE id = ?'); $st->execute([$id]); return (string)$st->fetchColumn(); };
/** gs_pay pattern: one completed bank payment dated the app's day, VAT split as the app splits it. */
$payRoom = static function (int $bid, float $gross, string $method = 'bank_transfer') use ($pdo, $refOf, $today): int {
    $sp = rh_account_vat_split($pdo, 'room', $bid, $gross, 16.5);
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, receipt_number, invoice_generated, created_at)
        VALUES (?,'room',?,?,?,?,?,?,?,?,'partial_payment','completed','completed',?,0,NOW())")
        ->execute(['GRCTP' . random_int(10000000, 99999999), $bid, $refOf($bid), $today, $sp['net'], $sp['rate'], $sp['vat'], $gross, $method, 'RGRCT' . random_int(10000000, 99999999)]);
    $pid = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($bid);
    return $pid;
};
$bk = static function (int $id) use ($pdo): array { $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?'); $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: []; };
$hasCol = static function (string $t, string $c) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    return (int)$st->fetchColumn() > 0;
};
$PAY_DELETED = $hasCol('payments', 'deleted_at');
$hasReceipt = $hasCol('payments', 'receipt_number');

/** Canonical net paid (owner rule): + non-refund rows completed/paid/refunded/partially_refunded, - refunds completed/processing. Gross (total_amount). */
$canonPaid = static function (int $id) use ($pdo, $PAY_DELETED): float {
    $st = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN COALESCE(payment_type,'') <> 'refund' AND payment_status IN ('completed','paid','refunded','partially_refunded') THEN total_amount ELSE 0 END),0)
          - COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN ABS(total_amount) ELSE 0 END),0)
        FROM payments WHERE booking_type = 'room' AND booking_id = ?" . ($PAY_DELETED ? ' AND deleted_at IS NULL' : ''));
    $st->execute([$id]);
    return round((float)$st->fetchColumn(), 2);
};
$paySnap = static function () use ($pdo, &$ids, $hasReceipt): array {
    if (!$ids) { return []; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, CONCAT(booking_id, ':', payment_amount, ':', vat_amount, ':', total_amount, ':', COALESCE(payment_method, ''), ':', COALESCE(payment_type, '')"
        . ($hasReceipt ? ", ':', COALESCE(receipt_number, '')" : '') . ") AS k FROM payments WHERE booking_type = 'room' AND booking_id IN ($ph) ORDER BY id");
    $st->execute($ids);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
};

/** One reading of everything: EOD, Still owed, the marker rooms, their payment rows. */
$snap = static function () use ($pdo, &$ids, $today, $mods, $bk, $canonPaid, $paySnap): array {
    $eod = rh_eod_report_data($pdo, $today);
    $split = rh_eod_outstanding_split($pdo, $mods);
    $recv = []; $recvCo = 0.0;
    foreach (rh_receivable_rows($pdo, $mods) as $r) {
        if ($r['type'] === 'room' && in_array((int)$r['id'], $ids, true)) { $recv[(int)$r['id']] = round((float)$r['amount_due'], 2); $recvCo += (float)($r['company_due'] ?? 0); }
    }
    $rooms = [];
    foreach ($ids as $id) {
        $fs = getBookingFolioSummary($id);
        $b = $bk($id);
        $rooms[$id] = ['billed' => round((float)($fs['grand_total'] ?? 0), 2), 'paid' => round((float)($fs['amount_paid'] ?? 0), 2), 'owed' => round((float)($fs['balance_due'] ?? 0), 2),
            'amount_due' => round((float)$b['amount_due'], 2), 'amount_paid' => round((float)$b['amount_paid'], 2), 'credit_balance' => round((float)$b['credit_balance'], 2),
            'status' => (string)$b['status'], 'primary' => (int)($b['primary_booking_id'] ?? 0), 'canon' => $canonPaid($id)];
    }
    $gc = $eod['guest_credit'] ?? [];
    return [
        'eod' => ['gross' => (float)$eod['gross'], 'refunds' => (float)($eod['rev']['refunds'] ?? 0), 'net' => (float)$eod['net'], 'outstanding' => (float)$eod['outstanding'],
            'earned' => (float)$eod['earned_total'], 'gc_issued' => (float)($gc['issued'] ?? 0), 'gc_used' => (float)($gc['used'] ?? 0), 'txn' => (int)($eod['rev']['txn_count'] ?? 0)],
        'still' => (float)$split['folio'], 'still_co' => (float)$split['company'], 'recv' => $recv, 'recv_sum' => round(array_sum($recv), 2), 'recv_co' => round($recvCo, 2),
        'rooms' => $rooms, 'pays' => $paySnap(),
        'paid_sum' => round(array_sum(array_column($rooms, 'canon')), 2), 'owed_sum' => round(array_sum(array_column($rooms, 'owed')), 2),
        'billed_sum' => round(array_sum(array_column($rooms, 'billed')), 2),
        'credit_sum' => round(array_sum(array_map(static fn($r) => max(0.0, $r['paid'] - $r['billed']), $rooms)), 2),
    ];
};

/** Identity of the group as it stands: $members = the room ids that must be in the group, $groupRoot its root. */
$identity = static function (string $label, array $members, int $groupRoot, array $s) use ($pdo, $near, $tol, $f2, &$findings): void {
    $t = rh_group_room_table($pdo, $groupRoot);
    $tIds = array_column($t['rows'], 'id'); sort($tIds);
    $m = $members; sort($m);
    ok("$label: group table holds exactly the group's rooms (root " . $groupRoot . ')', $tIds === $m && $t['root'] === $groupRoot, json_encode([$t['root'], $tIds, $m]));
    $bad = [];
    $owedSum = 0.0; $creditSum = 0.0; $recvExpect = 0.0; $recvGot = 0.0;
    foreach ($t['rows'] as $r) {
        $id = (int)$r['id'];
        $x = $s['rooms'][$id] ?? null;
        if ($x === null) { $bad[] = "$id not a marker room"; continue; }
        if (!$near($r['billed'], $x['billed']) || !$near($r['paid'], $x['paid']) || !$near($r['owed'], $x['owed'])) { $bad[] = "$id table!=folio " . json_encode([$r['billed'], $r['paid'], $r['owed'], $x]); }
        if (!$near($x['paid'], $x['canon']) || !$near($x['paid'], $x['amount_paid'])) { $bad[] = "$id paid: folio {$f2($x['paid'])} / payment rows {$f2($x['canon'])} / bookings.amount_paid {$f2($x['amount_paid'])}"; }
        if (!$near($x['amount_due'], max(0.0, $x['owed']))) { $bad[] = "$id amount_due {$f2($x['amount_due'])} != owed {$f2($x['owed'])}"; }
        if ($r['owed'] < -$tol && !($r['credit'] > $tol && $near($r['credit'], -$r['owed']))) { $bad[] = "$id owed {$f2($r['owed'])} negative, credit shown {$f2($r['credit'])}"; }
        $owedSum += $r['owed']; $creditSum += $r['credit'];
        if ($r['owed'] > $tol) { $recvExpect += $r['owed']; }
        $recvGot += $s['recv'][$id] ?? 0.0;
    }
    ok("$label: every room = its folio = bookings row = its payment rows (canonical net paid); owed never negative unless shown as credit", !$bad, implode(' | ', $bad));
    if ($bad) { $findings[] = "$label: " . implode(' | ', $bad); }
    ok("$label: group balance " . $f2($t['totals']['owed']) . ' = sum of room owed ' . $f2($owedSum) . '; net = owed - credit',
        $near($t['totals']['owed'], $owedSum) && $near($t['totals']['net'], $t['totals']['owed'] - $t['totals']['credit']) && $near($t['totals']['credit'], $creditSum));
    ok("$label: Still owed counts this group at its owing rooms (" . $f2($recvExpect) . ')', $near($recvGot, $recvExpect), $f2($recvGot) . ' vs ' . $f2($recvExpect));
};

/** Deltas between two readings against what the scenario says it did. */
$deltas = static function (string $label, array $a, array $b, array $e) use ($near, $f2, &$findings): void {
    $e += ['still' => 0.0, 'cash_in' => 0.0, 'refunds_out' => 0.0];
    $e += ['paid' => $e['cash_in'] - $e['refunds_out'], 'owed' => $e['still'], 'credit' => 0.0];
    $dStill = round($b['still'] - $a['still'], 2);
    $dRecv = round($b['recv_sum'] - $a['recv_sum'], 2);
    $dGross = round($b['eod']['gross'] - $a['eod']['gross'], 2);
    $dRef = round($b['eod']['refunds'] - $a['eod']['refunds'], 2);
    $dNet = round($b['eod']['net'] - $a['eod']['net'], 2);
    $dOut = round($b['eod']['outstanding'] - $a['eod']['outstanding'], 2);
    $dPaid = round($b['paid_sum'] - $a['paid_sum'], 2);
    $dOwed = round($b['owed_sum'] - $a['owed_sum'], 2);
    $dCredit = round($b['credit_sum'] - $a['credit_sum'], 2);
    $chk = [
        ['Still owed (rh_eod_outstanding_split) moves by ' . $f2($e['still']), $dStill, $e['still']],
        ['End of Day Still owed (rh_eod_report_data) moves the same', $dOut, $e['still']],
        ['only this test\'s rooms moved Still owed', $dRecv, $dStill],
        ['End of Day money received moves by ' . $f2($e['cash_in']), $dGross, $e['cash_in']],
        ['End of Day refunds paid out move by ' . $f2($e['refunds_out']), $dRef, $e['refunds_out']],
        ['End of Day net moves by ' . $f2($e['cash_in'] - $e['refunds_out']), $dNet, $e['cash_in'] - $e['refunds_out']],
        ['net paid over the test rooms moves by ' . $f2($e['paid']), $dPaid, $e['paid']],
        ['owed over the test rooms moves by ' . $f2($e['owed']), $dOwed, $e['owed']],
        ['credit shown over the test rooms moves by ' . $f2($e['credit']), $dCredit, $e['credit']],
    ];
    foreach ($chk as [$name, $got, $want]) {
        ok("$label: $name", $near($got, $want), 'got ' . $f2($got) . ', expected ' . $f2($want));
        if (!$near($got, $want)) { $findings[] = "$label: $name - got " . $f2($got) . ', expected ' . $f2($want); }
    }
    $kept = array_intersect_key($b['pays'], $a['pays']);
    ok("$label: no earlier payment row lost or changed", $kept === $a['pays'], json_encode(array_diff_assoc($a['pays'], $kept)));
    if (isset($e['new_rows'])) {
        $new = array_diff_key($b['pays'], $a['pays']);
        ok("$label: " . $e['new_rows'] . ' new payment row(s)', count($new) === $e['new_rows'], (string)count($new));
    }
};

// ---- the outer transaction ----
$marker = '__grct_marker_' . bin2hex(random_bytes(6));
$probe = static function (string $label) use ($chk, $pdo, $marker): bool {
    $st = $chk->prepare('SELECT COUNT(*) FROM site_settings WHERE setting_key = ?');
    $st->execute([$marker]);
    $leaked = (int)$st->fetchColumn() > 0;
    $own = $pdo->prepare('SELECT COUNT(*) FROM site_settings WHERE setting_key = ?');
    $own->execute([$marker]);
    $lost = (int)$own->fetchColumn() === 0;
    if ($leaked || $lost || !$pdo->inTransaction()) {
        ok("$label: outer transaction intact (marker not visible outside, still visible inside)", false, json_encode(['leaked' => $leaked, 'lost' => $lost, 'in_tx' => $pdo->inTransaction()]));
        return false;
    }
    return true;
};
$spN = 0;
$members = []; $groupRoot = 0;
$stop = false;
$S = null;
$scenario = static function (string $name, callable $fn) use ($pdo, $snap, $identity, $deltas, $probe, &$spN, &$members, &$groupRoot, &$stop, &$S): void {
    if ($stop) { echo "[SKIP] $name (stopped after a transaction problem)\n"; return; }
    echo "== $name ==\n";
    $sp = 'grct_sc' . (++$spN);
    $pdo->exec("SAVEPOINT $sp");
    $a = $S ?? $snap();
    try {
        $e = $fn($a);
        $b = $snap();
        $deltas($name, $a, $b, $e);
        $identity($name, $members, $groupRoot, $b);
        $pdo->exec("RELEASE SAVEPOINT $sp");
        $S = $b;
    } catch (Throwable $ex) {
        ok("$name: no exception", false, get_class($ex) . ': ' . $ex->getMessage() . ' @' . basename($ex->getFile()) . ':' . $ex->getLine());
        try { $pdo->exec("ROLLBACK TO SAVEPOINT $sp"); } catch (Throwable $e2) { echo "savepoint rollback failed: " . $e2->getMessage() . "\n"; }
        $S = null;
    }
    if (!$probe($name)) { $stop = true; }
};

$known = [];
$earned0 = null;
$cnIds = [];
$payIds = [];
$acc = 0;
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, '1')")->execute([$marker]);
    $S = $snap(); // first reading inside the transaction (REPEATABLE READ: later commits by others stay out)
    $setSetting('group_noshow_charge', '0');
    $setSetting('no_show_refund_policy', 'none');

    // ===== F: build the group (ICTAm-like, mixed nights) + a single company room =====
    $L = $R2 = $R3 = $R4 = $R5 = $R6 = $R7 = 0;
    $scenario('F fixtures: lead + 5 rooms (mixed nights / statuses) + one single room on a company account', function (array $a) use (
        $pdo, $mkRoom, $payRoom, $day, $BILL_ON, $HAS_SNAP, &$L, &$R2, &$R3, &$R4, &$R5, &$R6, &$R7, &$members, &$groupRoot, &$acc
    ): array {
        $snapGrp = json_encode(['kind' => 'group', 'policy_version' => 4, 'tiers' => [['days' => 14, 'pct' => 40]], 'noshow_pct' => 100, 'text' => 'GRCT test']);
        $L = $mkRoom('checked-out', $day(-6), $day(-3), null);           // 3 nights 3,495, paid 3,000 -> owes 495
        $R2 = $mkRoom('checked-out', $day(-6), $day(-3), $L);            // 3 nights, paid 3,000 -> owes 495 (early arrival later)
        $R3 = $mkRoom('checked-out', $day(-5), $day(-3), $L);            // 2 nights 2,330, paid in full (late checkout later)
        $R4 = $mkRoom('checked-out', $day(-7), $day(-2), $L, $snapGrp);  // 5 nights 5,825, paid in full (early departure + fee later)
        $R5 = $mkRoom('confirmed', $day(-2), $day(1), $L);               // 3 nights, paid 1,000 -> owes 2,495 (no-show later)
        $R6 = $mkRoom('checked-in', $day(-1), $day(2), $L);              // 3 nights, unpaid -> owes 3,495
        $R7 = $mkRoom('confirmed', $day(3), $day(5), null);              // single, 2 nights, unpaid -> owes 2,330 (company)
        $payRoom($L, 3000); $payRoom($R2, 3000); $payRoom($R3, 2330); $payRoom($R4, 5825); $payRoom($R5, 1000);
        if ($BILL_ON) {
            $pdo->prepare('INSERT INTO billing_accounts (name) VALUES (?)')->execute(['GRCT Company ' . getmypid()]);
            $acc = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE bookings SET billing_account_id = ? WHERE id = ?')->execute([$acc, $R7]);
            recalculateBookingFinancials($R7);
        }
        $members = [$L, $R2, $R3, $R4, $R5, $R6];
        $groupRoot = $L;
        ok('F: snapshot column present (unused-nights fee is exercised)', $HAS_SNAP);
        $cash = 3000 + 3000 + 2330 + 5825 + 1000;
        return ['still' => 495 + 495 + 2495 + 3495 + 2330, 'cash_in' => $cash, 'paid' => $cash, 'owed' => 495 + 495 + 2495 + 3495 + 2330, 'new_rows' => 5];
    });

    $sc = static function (int $id, string $in, string $out, string $reason, string $mode = '') use ($pdo, $bk, $user): array {
        $b = $bk($id);
        $q = rh_stay_correction_quote($pdo, $b, $in, $out, null, null, 'MWK');
        if (empty($q['ok'])) { throw new RuntimeException('quote refused: ' . ($q['error'] ?? '')); }
        $in2 = ['new_check_in' => $in, 'new_check_out' => $out, 'reason' => $reason, 'expected_new_bill' => $q['new_bill'], 'expected_money_back' => $q['money_back'],
            'can_correct' => true, 'can_refund' => true, 'clash_ack' => 1];
        if ($mode !== '') { $in2 += ['money_back_mode' => $mode, 'money_back_typed' => number_format((float)$q['money_back'], 2, '.', '')]; }
        $ap = rh_stay_correction_apply($pdo, $id, $in2, $user);
        return ['q' => $q, 'ap' => $ap];
    };

    // ===== S1 early arrival (R2 arrived a day earlier: +1 night at the booked rate) =====
    $scenario('S1 early arrival (R2 +1 night at the start)', function (array $a) use ($sc, $day, &$R2, $near, $f2): array {
        $r = $sc($R2, $day(-7), $day(-3), 'GRCT guest arrived a day early');
        $q = $r['q'];
        ok('S1: quote +1 night at the booked 1,165, nothing back', (int)$q['new_nights'] === 4 && $near($q['new_bill'] - $q['old_bill'], 1165) && $near($q['money_back'], 0),
            json_encode(rh_stay_correction_public($q)));
        return ['still' => 1165];
    });

    // ===== S2 late checkout (R3 left a day later) =====
    $scenario('S2 late checkout (R3 +1 night at the end)', function (array $a) use ($sc, $day, &$R3, $near): array {
        $r = $sc($R3, $day(-5), $day(-2), 'GRCT guest left a day later');
        ok('S2: quote 3 nights, +1,165 owed', (int)$r['q']['new_nights'] === 3 && $near($r['q']['new_due'], 1165), json_encode(rh_stay_correction_public($r['q'])));
        return ['still' => 1165];
    });

    // ===== S3 early departure with the unused-nights fee, money back as a refund =====
    $scenario('S3 early departure (R4 5 -> 3 nights, unused-nights fee, refund)', function (array $a) use ($sc, $day, &$R4, $near, $f2, $pdo): array {
        $r = $sc($R4, $day(-7), $day(-4), 'GRCT guest left two days early', 'refund');
        $q = $r['q'];
        $fee = (float)$q['fee_kept'];
        $back = (float)$q['money_back'];
        echo "  fee kept {$f2($fee)}, new bill {$f2($q['new_bill'])}, money back {$f2($back)}\n";
        ok('S3: unused-nights fee 40% of 2 x 1,165 = 932; new bill 3,495 + 932 = 4,427; 1,398 back', $near($fee, 932) && $near($q['new_bill'], 4427) && $near($back, 1398),
            json_encode([$fee, $q['new_bill'], $back]));
        $st = $pdo->prepare('SELECT COALESCE(SUM(line_total),0) FROM booking_charges WHERE booking_id = ? AND voided = 0 AND description LIKE ?');
        $st->execute([$R4, '%' . rh_unused_nights_tag($R4)]);
        ok('S3: the fee is ONE folio row on the room', $near($st->fetchColumn(), $fee));
        ok('S3: refunded in full to the original payment', $near($r['ap']['refund_total'], $back), $f2($r['ap']['refund_total']));
        return ['refunds_out' => $back, 'new_rows' => 1];
    });

    // ===== S4 a no-show room that still owes (group no-show charge = first night) =====
    $scenario('S4 no-show room that still owes (R5, group charge first night)', function (array $a) use ($pdo, $setSetting, $user, &$R5, $near, $f2): array {
        $setSetting('group_noshow_charge', 'first_night');
        $r = rh_mark_booking_no_show($pdo, $R5, $user['id'], $user['full_name'] !== '' ? $user['full_name'] : $user['username'], 'GRCT test');
        ok('S4: marked no-show with the group charge posted', !empty($r['success']) && !empty($r['group_fee']['posted']), json_encode($r));
        $fs = getBookingFolioSummary($R5);
        ok('S4: bills the first night 1,165, paid 1,000, still owes 165', $near($fs['grand_total'], 1165) && $near($fs['balance_due'], 165), json_encode([$fs['grand_total'], $fs['balance_due']]));
        return ['still' => 165 - 2495];
    });

    // ===== S5 a payment on one room (the lead overpays by 500) =====
    // Real path = admin/payment-add.php:727-751: payment row, then excess = min(rh_account_credit_owed, amount) goes into an
    // overpayment credit note (rh_issue_overpay_credit_notes: credit-note refund legs) and rh_sync_account_payments.
    // Owner rule: the overpay lives ONLY in the credit note; the room reads settled (owed 0, credit 0, credit_balance 0).
    $scenario('S5 payment on one room (lead pays 995 = 495 owed + 500 over -> credit note)', function (array $a) use ($pdo, $payRoom, $user, $vatRate, $bk, &$L, $near, $f2): array {
        $pid = $payRoom($L, 995, 'cash');
        $pref = (string)$pdo->query('SELECT payment_reference FROM payments WHERE id = ' . (int)$pid)->fetchColumn();
        $b = $bk($L);
        $excess = round(min(rh_account_credit_owed($pdo, 'room', $L), 995.0), 2);
        ok('S5: the overpay found by rh_account_credit_owed is 500', $near($excess, 500), $f2($excess));
        $notesOut = rh_issue_overpay_credit_notes($pdo, 'room', $L, $excess, ['ref' => (string)$b['booking_reference'], 'name' => (string)$b['guest_name'], 'email' => (string)$b['guest_email']],
            $pref, (int)$user['id'], (float)$vatRate);
        ok('S5: account resynced', rh_sync_account_payments($pdo, 'room', $L));
        $cn = $pdo->prepare("SELECT COALESCE(SUM(original_amount),0), COALESCE(SUM(balance),0), COUNT(*) FROM credit_notes WHERE booking_id = ? AND reason = 'overpayment'");
        $cn->execute([$L]);
        [$cnAmt, $cnBal, $cnN] = $cn->fetch(PDO::FETCH_NUM);
        ok('S5: one overpayment credit note of 500, all of it open', (int)$cnN === 1 && count($notesOut) === 1 && $near($cnAmt, 500) && $near($cnBal, 500), json_encode([$cnN, $cnAmt, $cnBal]));
        $t = rh_group_room_table($pdo, $L);
        $row = array_values(array_filter($t['rows'], static fn($r) => $r['id'] === $L))[0];
        $b2 = $bk($L);
        ok('S5: the room reads settled (owed 0, credit 0, credit_balance 0)', $near($row['owed'], 0) && $near($row['credit'], 0) && $near($b2['credit_balance'], 0),
            json_encode([$row['owed'], $row['credit'], $b2['credit_balance']]));
        $GLOBALS['__grct_s5_gc'] = $a['eod']['gc_issued'];
        // 995 received in cash; 500 of it leaves the room into the note (credit-note legs are not a payout): room net paid +495.
        return ['still' => -495, 'cash_in' => 995, 'paid' => 495, 'owed' => -495, 'credit' => 0.0];
    });
    if ($S !== null && isset($GLOBALS['__grct_s5_gc'])) {
        $d = round($S['eod']['gc_issued'] - $GLOBALS['__grct_s5_gc'], 2);
        ok('S5: End of Day guest credit issued moves by 500 (the note)', $near($d, 500), $f2($d));
        if (!$near($d, 500)) { $findings[] = 'S5: EOD guest credit issued moved ' . $f2($d) . ', expected 500.00'; }
    }

    // ===== S6 a whole-group payment =====
    $scenario('S6 whole-group payment (2,000 split by balance)', function (array $a) use ($pdo, $user, $today, $vatRate, $txnCol, &$L, &$R5, $near, $f2, $tol): array {
        $amt = 2000.00;
        $pre = rh_group_pay_preview($pdo, $L, $amt);
        ok('S6: preview: a group that can take a whole-group payment', !empty($pre['is_group']) && !empty($pre['can_group']), (string)($pre['reason'] ?? ''));
        $in = ['amount' => $amt, 'method' => 'cash', 'date' => $today, 'status' => 'completed', 'reference' => 'GRCT', 'notes' => '', 'cc_emails' => '',
            'client_uuid' => 'grct-' . bin2hex(random_bytes(8)), 'confirm_overpay' => false, 'expect' => $pre['expect'], 'vat_rate' => $vatRate,
            'processed_by' => 'group reconcile test', 'txn_column' => $txnCol];
        $res = rh_group_pay_record($pdo, $L, $in, $user);
        $legs = $res['legs'];
        $capOk = true;
        foreach ($legs as $l) { if ($l['amount'] > $l['owed_before'] + $tol) { $capOk = false; } }
        echo '  legs: ' . implode(', ', array_map(static fn($l) => $l['booking_id'] . '=' . $f2($l['amount']), $legs)) . "\n";
        ok('S6: legs sum exactly 2,000, none above its room\'s balance, no excess', $near(array_sum(array_column($legs, 'amount')), $amt) && $capOk && $near($res['excess'], 0), json_encode([$res['excess'], $legs]));
        $noshowLeg = array_filter($legs, static fn($l) => (int)$l['booking_id'] === (int)$R5);
        ok('S6: the no-show room still owing takes its share', count($noshowLeg) === 1, json_encode($legs));
        return ['still' => -$amt, 'cash_in' => $amt, 'new_rows' => count($legs)];
    });

    // ===== S7 a group charge (split across the rooms that can take it) =====
    $chargeId = 0; $chargeRoom = 0;
    $scenario('S7 group charge (600 split)', function (array $a) use ($pdo, $user, &$L, $near, $f2, &$notes, &$chargeId, &$chargeRoom): array {
        $plan = rh_group_charge_plan(rh_group_charge_rooms($pdo, $L), 'split', 'GRCT boat trip', 1.0, 600.0);
        ok('S7: plan ok, total 600', !empty($plan['ok']) && $near($plan['total'], 600), json_encode($plan['message'] ?? ''));
        $r = rh_group_charge_post($pdo, $L, 'split', 'service', 'GRCT boat trip', 1.0, 600.0, $user, (string)$plan['sig']);
        ok('S7: posted', !empty($r['success']), (string)($r['message'] ?? ''));
        $sum = array_sum(array_column($r['charges'], 'amount'));
        echo '  charged rooms: ' . implode(', ', array_map(static fn($c) => $c['booking_id'] . '=' . $f2($c['amount']), $r['charges'])) . "\n";
        ok('S7: charges posted sum to 600', $near($sum, 600), $f2($sum));
        if (count($r['charges']) === 1) { $chargeId = (int)$r['charges'][0]['charge_id']; $chargeRoom = (int)$r['charges'][0]['booking_id']; }
        return ['still' => 600];
    });
    if ($S !== null) {
        $earned0 = $S['eod']['earned'];
    }

    // ===== S8 credit move. The credit comes from a real path: the charged room pays its balance (S8a), then the 600 charge
    // is voided (voidBookingCharge, S8b) -> paid above billed by 600. S8c moves it with rh_group_credit_move. =====
    $scenario('S8a the charged room pays its whole balance', function (array $a) use ($payRoom, &$chargeRoom, $near): array {
        if ($chargeRoom <= 0) { throw new RuntimeException('S7 did not leave one charged room'); }
        $owed = round((float)getBookingFolioSummary($chargeRoom)['balance_due'], 2);
        $payRoom($chargeRoom, $owed, 'cash');
        ok('S8a: room settled', $near(getBookingFolioSummary($chargeRoom)['balance_due'], 0));
        return ['still' => -$owed, 'cash_in' => $owed, 'new_rows' => 1];
    });
    $scenario('S8b the paid 600 charge is voided (paid above billed by 600)', function (array $a) use ($user, &$chargeId, &$chargeRoom, $near, $f2): array {
        $r = voidBookingCharge($chargeId, 'GRCT test void', (int)$user['id']);
        ok('S8b: voided', !empty($r['success']), (string)($r['message'] ?? ''));
        $fs = getBookingFolioSummary($chargeRoom);
        ok('S8b: room now 600 in credit', $near((float)$fs['amount_paid'] - (float)$fs['grand_total'], 600), $f2((float)$fs['amount_paid'] - (float)$fs['grand_total']));
        return ['still' => 0.0, 'credit' => 600];
    });
    $scenario('S8c credit move (600 credit -> the rooms that owe)', function (array $a) use ($pdo, $opts, &$L, &$chargeRoom, $near, $f2, &$known, &$members): array {
        $GLOBALS['__grct_gc_before'] = ['i' => $a['eod']['gc_issued'], 'u' => $a['eod']['gc_used']];
        $tB = rh_group_room_table($pdo, $L);
        $canTake = 0.0;
        foreach ($tB['rows'] as $r) {
            if ((int)$r['id'] !== $chargeRoom && !rh_gcm_closed((string)$r['status']) && $r['owed'] > 0) { $canTake += $r['owed']; }
        }
        $want = round(min(600.0, $canTake), 2);
        $pv = rh_group_credit_move($pdo, $chargeRoom, $opts + ['dry_run' => true]);
        ok('S8c: preview ok', !empty($pv['success']), (string)($pv['error'] ?? ''));
        $res = rh_group_credit_move($pdo, $chargeRoom, $opts + ['expect_state' => (string)($pv['state'] ?? '')]);
        ok('S8c: confirm ok, moves min(600, what the open rooms owe ' . $f2($canTake) . ') = ' . $f2($want), !empty($res['success']) && $near($res['moved'], $want),
            json_encode([$res['error'] ?? '', $res['moved'] ?? null]));
        echo '  receivers: ' . implode(', ', array_map(static fn($r) => $r['id'] . '=' . $f2($r['apply']), $pv['rooms'] ?? [])) . "\n";
        $tA = rh_group_room_table($pdo, $L);
        ok('S8: group net position unchanged (' . $f2($tB['totals']['net']) . ')', $near($tA['totals']['net'], $tB['totals']['net']), $f2($tA['totals']['net']));
        $moved = (float)($res['moved'] ?? 0);
        $GLOBALS['__grct_moved'] = $moved;
        return ['still' => -$moved, 'paid' => 0.0, 'owed' => -$moved, 'credit' => -$moved]; // receivers owe less, the giver's credit is used up
    });
    if ($S !== null && isset($GLOBALS['__grct_moved'])) {
        $known[] = 'KNOWN: EOD on a move day shows guest credit issued +' . $f2($S['eod']['gc_issued'] - ($GLOBALS['__grct_gc_before']['i'] ?? $S['eod']['gc_issued'])) . ' / used +'
            . $f2($S['eod']['gc_used'] - ($GLOBALS['__grct_gc_before']['u'] ?? $S['eod']['gc_used'])) . ' for a ' . $f2($GLOBALS['__grct_moved']) . ' move (refunds-to-credit / redeemed), cash view unchanged.';
    }

    // ===== S9 primary switch (R6 becomes the primary room) =====
    $scenario('S9 primary switch (lead -> R6)', function (array $a) use ($pdo, $opts, &$L, &$R6, &$groupRoot, $near): array {
        $pv = rh_group_repoint($pdo, $L, $R6, $opts + ['dry_run' => true]);
        ok('S9: preview ok', !empty($pv['success']), (string)($pv['error'] ?? ''));
        $r = rh_group_repoint($pdo, $L, $R6, $opts + ['expect_state' => $pv['state']]);
        ok('S9: re-pointed', !empty($r['success']), (string)($r['error'] ?? ''));
        $groupRoot = $R6;
        return [];
    });

    // ===== S10 bundle (the single company room joins), then a company payment on it =====
    $scenario('S10 bundle (R7 on a company account joins via Make a group)', function (array $a) use ($pdo, $opts, &$R6, &$R7, &$members): array {
        $pv = rh_group_make($pdo, $R6, [$R6, $R7], $opts + ['dry_run' => true]);
        ok('S10: preview ok', !empty($pv['success']), (string)($pv['error'] ?? ''));
        $r = rh_group_make($pdo, $R6, [$R6, $R7], $opts + ['expect_state' => (string)$pv['state']]);
        ok('S10: joined', !empty($r['success']) && in_array($R7, $r['joined'], true), (string)($r['error'] ?? ''));
        $members[] = $R7;
        return [];
    });
    if ($BILL_ON && $acc > 0) {
        $scenario('S10b company payment on the company room (1,000 remittance)', function (array $a) use ($pdo, $today, $user, $snap, &$R7, $acc, $near, $f2): array {
            $rem = rh_billing_record_remittance($pdo, $acc, ['amount' => '1000.00', 'payment_method' => 'bank_transfer', 'received_date' => $today,
                'bank_reference' => 'GRCT-REM', 'notes' => 'GRCT test', 'lines' => [$R7 => '1000.00']], $user);
            ok('S10b: recorded as one leg on the room', count($rem['legs']) === 1, json_encode($rem['legs']));
            $pr = $pdo->prepare("SELECT payment_amount, vat_amount, total_amount FROM payments WHERE booking_id = ? AND booking_type = 'room' ORDER BY id DESC LIMIT 1");
            $pr->execute([$R7]);
            $row = $pr->fetch(PDO::FETCH_ASSOC);
            ok('S10b: payment row is 1,000 gross with net + VAT = gross', $near($row['total_amount'], 1000) && $near((float)$row['payment_amount'] + (float)$row['vat_amount'], 1000), json_encode($row));
            $b = $snap();
            ok('S10b: company part of Still owed moves by -1,000', $near($b['still_co'] - $a['still_co'], -1000) && $near($b['recv_co'] - $a['recv_co'], -1000),
                json_encode([$a['still_co'], $b['still_co'], $a['recv_co'], $b['recv_co']]));
            return ['still' => -1000, 'cash_in' => 1000, 'new_rows' => 1];
        });
    }

    // ===== S11 unbundle (R7 leaves the group) =====
    $scenario('S11 unbundle (R7 leaves via rh_group_remove)', function (array $a) use ($pdo, $opts, &$R7, &$members, &$groupRoot): array {
        $pv = rh_group_remove($pdo, $R7, $opts + ['dry_run' => true]);
        ok('S11: preview ok', !empty($pv['success']), (string)($pv['error'] ?? ''));
        $r = rh_group_remove($pdo, $R7, $opts + ['expect_state' => (string)$pv['state']]);
        ok('S11: removed, group root unchanged', !empty($r['success']) && (int)$r['new_root'] === $groupRoot, json_encode([$r['error'] ?? '', $r['new_root'] ?? null]));
        $members = array_values(array_diff($members, [$R7]));
        $tR7 = rh_group_room_table($pdo, $R7);
        ok('S11: R7 now stands alone', $tR7['root'] === $R7 && count($tR7['rows']) === 1);
        return [];
    });

    // ===== whole-run totals: money in = money on the rooms =====
    if ($S !== null) {
        $bill = $S['billed_sum']; $paid = $S['paid_sum']; $owed = $S['owed_sum'];
        ok('end: over all test rooms, billed - net paid = owed - credit (' . $f2($bill) . ' - ' . $f2($paid) . ' = ' . $f2($owed) . ' - ' . $f2($S['credit_sum']) . ')', $near($bill - $paid, $owed - $S['credit_sum']));
        if ($earned0 !== null && $near($S['eod']['earned'], $earned0)) {
            $notes[] = 'NOTE: rh_eod_earned_revenue memoises per process (static $memo by date + modules), so End of Day "earned" cannot be re-read after a change in one run; earned deltas are not asserted.';
        }
    }
} catch (Throwable $e) {
    ok('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    try { // collected however the run ended, so the leak check knows every payment / credit note id
        if ($ids && $pdo->inTransaction()) {
            $inIds = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id FROM payments WHERE booking_type = 'room' AND booking_id IN ($inIds)");
            $st->execute($ids);
            $payIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $cs = $pdo->prepare("SELECT id FROM credit_notes WHERE booking_id IN ($inIds)");
            $cs->execute($ids);
            $cnIds = array_map('intval', $cs->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (Throwable $e) {
        echo 'NOTE: could not list the test payment ids: ' . $e->getMessage() . "\n";
    }
    $pdo->realRollback();
    $setSetting('group_noshow_charge', $keepNoshowMode);
    $setSetting('no_show_refund_policy', $keepNoshowPolicy);
}
$GLOBALS['rh_payment_audit_queue'] = []; // the rolled-back rows must not reach History

// ---- KNOWN (LATER items: printed, never failing) ----
$known[] = 'KNOWN: a primary room that turns no-show still takes the group overpay (rh_group_pay_record excess room).';
$known[] = 'KNOWN: rh_group_remove fallback can hand the group to an expired room.';
$known[] = 'KNOWN: a credit application can be dated after the credit note\'s end day.';
$known[] = 'KNOWN: money moved twice through a chain is not counted as company money.';
foreach ($known as $k) { echo $k . "\n"; }
foreach ($notes as $k) { echo $k . "\n"; }
if (!empty($GLOBALS['__grct_ddl'])) { echo 'NOTE: statements skipped by the safe connection: ' . json_encode(array_values(array_unique($GLOBALS['__grct_ddl']))) . "\n"; }

// ---- leak check from the second connection, by marker / test ids; anything found is deleted by marker and reported ----
$leaks = [];
$leakQ = static function (string $table, string $where, array $args) use ($chk, &$leaks): void {
    try {
        $st = $chk->prepare("SELECT COUNT(*) FROM $table WHERE $where");
        $st->execute($args);
        $c = (int)$st->fetchColumn();
        if ($c > 0) {
            $leaks[] = "$table: $c";
            $chk->prepare("DELETE FROM $table WHERE $where")->execute($args);
        }
    } catch (Throwable $e) {
        echo "NOTE: leak check on $table could not run: " . $e->getMessage() . "\n"; // never silently 'clean'
    }
};
$idPh = $ids ? implode(',', array_map('intval', $ids)) : '0';
$payPh = $payIds ? implode(',', $payIds) : '0';
$leakQ('site_settings', 'setting_key = ?', [$marker]);
$leakQ('payment_audit_log', "payment_id IN ($payPh)", []);
$leakQ('payments', "booking_type = 'room' AND (booking_id IN ($idPh) OR booking_reference LIKE ? OR payment_reference LIKE ?)", ['GRCT%', 'GRCT%']);
$leakQ('booking_charges', "booking_id IN ($idPh)", []);
$leakQ('credit_notes', "booking_reference LIKE ? OR booking_id IN ($idPh)", ['GRCT-%']);
$leakQ('billing_remittance_lines', "booking_id IN ($idPh)", []);
$leakQ('billing_remittances', 'bank_reference LIKE ? OR remittance_reference LIKE ?', ['GRCT%', 'GRCT-%']);
$cnPh = $cnIds ? implode(',', $cnIds) : '0';
$leakQ('credit_note_applications', "applied_to_booking_id IN ($idPh) OR credit_note_id IN ($cnPh) OR payment_id IN ($payPh) OR applied_to_booking_reference LIKE ?", ['GRCT-%']);
$leakQ('idempotency_keys', "client_uuid LIKE 'grct-%' OR entity_reference LIKE ? OR (entity_type IN ('payment', 'room', 'booking') AND entity_id IN ($payPh, $idPh))", ['GRCT%']);
$leakQ('billing_accounts', 'name LIKE ?', ['GRCT Company%']);
$leakQ('booking_timeline_logs', "booking_id IN ($idPh)", []);
$leakQ('booking_audit_log', "booking_id IN ($idPh)", []);
$leakQ('booking_date_adjustments', "booking_id IN ($idPh)", []);
$leakQ('system_event_log', 'context_json LIKE ?', ['%GRCT-' . $PID . '-%']);
$leakQ('bookings', 'booking_reference LIKE ?', ['GRCT-' . $PID . '-%']);
ok('leak check: no test rows left after the rollback', !$leaks, implode(', ', $leaks) . ' (deleted by marker)');
$ictamAfter = $chk->query("SELECT GROUP_CONCAT(CONCAT(id, ':', COALESCE(primary_booking_id, 0), ':', amount_paid) ORDER BY id) FROM bookings WHERE id = 2100008837 OR primary_booking_id = 2100008837")->fetchColumn();
ok('ICTAm group untouched', $ictamAfter === $ictamBefore);
$pdfAfter = grct_pdf_set($root);
$changed = array_keys(array_diff_assoc($pdfAfter, $pdfBefore) + array_diff_key($pdfBefore, $pdfAfter));
ok('no PDF written, overwritten or deleted', !$changed, implode(', ', $changed));

if ($findings) {
    echo "\nFINDINGS (money mismatches, not patched here):\n";
    foreach (array_unique($findings) as $fd) { echo ' - ' . $fd . "\n"; }
}
echo "\ngroup_reconcile: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
