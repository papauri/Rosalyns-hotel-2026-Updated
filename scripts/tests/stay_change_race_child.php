<?php
/**
 * Child process for scripts/tests/stay_change_clash_test.php (race part): extends ONE stay by one night through a real
 * save path with a normal (committing) connection, starting at the shared start time so it races a block saved at the
 * same moment (block_booking_race_child.php). mode "nights" = rh_stay_nights_apply (+1 night on an in-house stay: the
 * timeline end-grip / Add nights); mode "dates" = processBookingDateAdjustment (Change dates).
 * Refuses any booking whose guest name does not start with "CONCTEST STAYRACE" (the parent's throwaway bookings).
 * Usage: php stay_change_race_child.php <base64 json {booking_id, mode, new_check_out, start_at}>
 * Prints one line: @@RESULT@@{ok, message}.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
$root = dirname(__DIR__, 2);
chdir($root);
$cfg = json_decode(base64_decode((string)($argv[1] ?? '')), true);
require $root . '/config/database.php';
require_once $root . '/includes/stay-nights.php';
$bid = (int)($cfg['booking_id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
$st->execute([$bid]);
$b = $st->fetch(PDO::FETCH_ASSOC);
if (!$b || strpos((string)$b['guest_name'], 'CONCTEST STAYRACE') !== 0) {
    fwrite(STDERR, "refusing to run: not a CONCTEST STAYRACE booking\n");
    exit(2);
}
$mode = (string)($cfg['mode'] ?? 'dates');
$adminId = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn(); // adjusted_by FK
// The review figures staff would have seen (worked out before the start, like the quote card).
$q = $mode === 'nights' ? rh_stay_nights_quote($pdo, $b, 1, null, false, 'MWK') : null;
$startAt = (float)($cfg['start_at'] ?? 0);
while (microtime(true) < $startAt) { usleep(100); }
$ok = false;
$msg = '';
try {
    if ($mode === 'nights') {
        rh_stay_nights_apply($pdo, $bid, ['delta' => 1, 'expected_new_total' => $q['new_total'], 'expected_total_delta' => $q['total_delta'],
            'reason' => 'race test', 'can_refund' => false], ['id' => $adminId, 'full_name' => 'race test']);
        $ok = true;
    } else {
        $r = processBookingDateAdjustment($bid, substr((string)$b['check_in_date'], 0, 10), (string)$cfg['new_check_out'], 'race test', $adminId, 'race test');
        $ok = !empty($r['success']);
        $msg = (string)($r['message'] ?? '');
    }
} catch (Throwable $e) {
    $msg = $e->getMessage();
}
fwrite(STDOUT, "\n@@RESULT@@" . json_encode(['ok' => $ok, 'message' => $msg]) . "\n");
