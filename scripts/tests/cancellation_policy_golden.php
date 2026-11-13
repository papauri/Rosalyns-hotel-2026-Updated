<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Cancellation & no-show policies - "Off = zero diff" gate (CANCELLATION_POLICY_SPEC.md §7 test 2).
 * Run from the TEST hotel's root only (Liwonde), once on the code before the change and once after it:
 *   php scripts/tests/cancellation_policy_golden.php > golden_before.txt
 *   php scripts/tests/cancellation_policy_golden.php > golden_after.txt
 * The two outputs must be identical (the policy switch is Off by code default, so every path runs today's rules).
 * For every booking (not deleted), for each of the 3 cancellation modes x 3 no-show refund rules (switched IN MEMORY
 * only, never written to site_settings), inside a transaction that is ALWAYS rolled back: cancel it, dump the money;
 * roll back; mark it no-show (check-in moved to yesterday inside the transaction when it is not yet past), dump; roll
 * back. A second connection checks before and after that nothing leaked. Writes nothing, sends nothing.
 */
$root = dirname(__DIR__, 2);
chdir($root);
if (stripos(str_replace('\\', '/', $root), 'liwonde') === false) {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
require_once $root . '/includes/booking-functions.php';
require_once $root . '/includes/booking-timeline.php';

$admin = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
// A fresh second connection each time (an idle one times out during the long run).
$fingerprint = static function (): string {
    $probe = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $a = $probe->query("SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, COALESCE(cancellation_retained_amount,''), amount_due, amount_paid, credit_balance, payment_status, check_in_date))),0) FROM bookings")->fetch(PDO::FETCH_NUM);
    $b = $probe->query("SELECT COUNT(*), COALESCE(MAX(id),0) FROM payments")->fetch(PDO::FETCH_NUM);
    $c = $probe->query("SELECT COUNT(*), COALESCE(MAX(id),0) FROM booking_charges")->fetch(PDO::FETCH_NUM);
    return implode(',', array_merge($a, $b, $c));
};
$fpBefore = $fingerprint();

$ids = array_map('intval', $pdo->query("SELECT id FROM bookings WHERE deleted_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

$dump = static function (PDO $pdo, int $id): string {
    $st = $pdo->prepare('SELECT status, COALESCE(cancellation_retained_amount, 0) AS retained, credit_balance, amount_due, amount_paid, payment_status FROM bookings WHERE id = ?');
    $st->execute([$id]);
    $b = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $r = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS t FROM payments WHERE booking_type = 'room' AND booking_id = ? AND payment_type = 'refund' AND deleted_at IS NULL");
    $r->execute([$id]);
    $rf = $r->fetch(PDO::FETCH_ASSOC) ?: [];
    $t = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN voided = 0 THEN line_total ELSE 0 END), 0) AS t FROM booking_charges WHERE booking_id = ? AND description LIKE ?');
    $t->execute([$id, '%' . rh_noshow_fee_tag($id)]);
    $tg = $t->fetch(PDO::FETCH_ASSOC) ?: [];
    return sprintf('status=%s retained=%.2f refunds=%d/%.2f credit=%.2f due=%.2f paid=%.2f pay=%s tagged=%d/%.2f',
        $b['status'] ?? '?', (float)($b['retained'] ?? 0), (int)($rf['n'] ?? 0), (float)($rf['t'] ?? 0), (float)($b['credit_balance'] ?? 0),
        (float)($b['amount_due'] ?? 0), (float)($b['amount_paid'] ?? 0), $b['payment_status'] ?? '?', (int)($tg['n'] ?? 0), (float)($tg['t'] ?? 0));
};
// Money lines in summaries carry refund references (sequence numbers) - mask them so before/after compare the money only.
$mask = static fn(string $s): string => (string)preg_replace('/\b[A-Z]{2,}-?R(?:EF|F)?[-A-Z0-9]*\d{3,}\b|\(Ref: [^)]*\)/', '(ref)', $s);

$modes = ['void_refund_all', 'void_keep_credit', 'keep_first_night'];
$rules = ['none', 'first_night', 'full'];
$lines = 0;
$docsOnly = in_array('--docs-only', $argv ?? [], true); // quick check of the documents part alone
foreach ($docsOnly ? [] : $modes as $mode) {
    foreach ($rules as $rule) {
        $GLOBALS['_SITE_SETTINGS']['cancellation_refund_mode'] = $mode;
        $GLOBALS['_SITE_SETTINGS']['no_show_refund_policy'] = $rule;
        echo "== mode=$mode rule=$rule\n";
        foreach ($ids as $id) {
            // Cancel
            $pdo->beginTransaction();
            try {
                $res = cancelRoomBookingSettled($pdo, $id, $admin, 'golden');
                $out = !empty($res['success'])
                    ? 'ok retained=' . number_format((float)$res['retained'], 2, '.', '') . ' refund=' . number_format((float)$res['refund_total'], 2, '.', '') . ' credit=' . number_format((float)$res['credit_balance'], 2, '.', '') . ' | ' . $dump($pdo, $id) . ' | ' . $mask((string)$res['summary'])
                    : 'refused: ' . $res['error'];
            } catch (Throwable $e) {
                $out = 'threw: ' . $e->getMessage();
            }
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            echo "C $id $out\n";
            // No-show
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE bookings SET check_in_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ? AND check_in_date >= CURDATE()')->execute([$id]);
                $res = rh_mark_booking_no_show($pdo, $id, $admin, 'Golden');
                $out = !empty($res['success'])
                    ? 'ok refund=' . number_format((float)($res['refund']['refund_amount'] ?? 0), 2, '.', '') . ' rerr=' . ($res['refund']['error'] ?? '')
                        . ' fee=' . json_encode($res['group_fee'] ?? null) . ' | ' . $dump($pdo, $id)
                    : 'refused: ' . $res['error'];
            } catch (Throwable $e) {
                $out = 'threw: ' . $e->getMessage();
            }
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            echo "N $id $out\n";
            $lines += 2;
        }
    }
}
// Documents: nothing new is printed for a booking without a policy snapshot. Per booking, a hash of the invoice HTML
// and of each booking email template as rendered (today's dates masked so a run after midnight still compares).
require_once $root . '/config/invoice.php';
require_once $root . '/config/email.php';
$today = [date('d M Y'), date('F j, Y'), date('M j, Y'), date('Y-m-d'), date('d/m/Y'), date('j F Y'), date('d F Y')];
$maskDoc = static fn(string $s): string => str_replace($today, 'TODAY', $s);
$emailKeys = ['booking_received', 'booking_confirmed', 'tentative_booking_created', 'tentative_booking_converted', 'tentative_quotation', 'booking_cancelled'];
echo "== documents\n";
foreach ($ids as $id) {
    $pdo->beginTransaction();
    try {
        $rows = rh_invoice_doc_rows($pdo, $id);
        $t = null;
        $inv = buildInvoiceHTML($rows['booking'], 'GOLDEN-INV', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $rows['group_bookings'], null, false, $t);
        $st = $pdo->prepare('SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?');
        $st->execute([$id]);
        $b = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $rm = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
        $rm->execute([(int)($b['room_id'] ?? 0)]);
        $room = $rm->fetch(PDO::FETCH_ASSOC) ?: null;
        $vars = buildBookingEmailVariables($b, $room, ['cancellation_reason' => 'golden']);
        $mails = [];
        foreach ($emailKeys as $k) {
            $r = renderBookingEmailTemplate($k, $vars);
            $mails[] = $k . '=' . ($r ? substr(md5($maskDoc($r['subject'] . '|' . $r['html_body'] . '|' . $r['text_body'])), 0, 12) : 'none');
        }
        $out = 'inv=' . substr(md5($maskDoc((string)$inv)), 0, 12) . ' ' . implode(' ', $mails);
    } catch (Throwable $e) {
        $out = 'threw: ' . $e->getMessage();
    }
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "D $id $out\n";
}

$fpAfter = $fingerprint();
echo "== bookings=" . count($ids) . " lines=$lines\n";
echo $fpBefore === $fpAfter ? "== nothing leaked\n" : "== LEAK: database changed ($fpBefore -> $fpAfter)\n";
exit($fpBefore === $fpAfter ? 0 : 1);
