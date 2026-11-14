<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Cancellation & no-show policies (CANCELLATION_POLICY_SPEC.md §7 tests 1, 3-7 + F2 guest whole-party cancel).
 * Run from the TEST hotel's root only (Liwonde), after migration 085:  php scripts/tests/cancellation_policy.php
 * In-process cases run inside ONE transaction that is rolled back (a second connection checks nothing leaked); the
 * policy settings are switched IN MEMORY only ($_SITE_SETTINGS), never written to site_settings. The guest lookup cases
 * run in scripts/tests/page_harness.php (its own never-committing transaction) and are all refusals or read-only
 * views, so no cancellation email is ever sent.
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
require_once $root . '/admin/includes/permissions.php';
// Belt and braces: no policy setting value may outlive this run in the settings file cache.
register_shutdown_function(static function (): void {
    foreach (['cancellation_policy_enabled', 'cancellation_policy_individual', 'cancellation_policy_group', 'cancellation_policy_group_min_rooms',
              'cancellation_policy_note', 'cancellation_policy_version', 'no_show_refund_policy'] as $k) {
        if (function_exists('deleteCache')) { @deleteCache('setting_' . $k); }
    }
});
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= 0.011;
$set = static function (string $k, ?string $v): void { if ($v === null) { unset($GLOBALS['_SITE_SETTINGS'][$k]); } else { $GLOBALS['_SITE_SETTINGS'][$k] = $v; } };
$pol = static fn(array $tiers, float $ns = 100): string => json_encode(['tiers' => array_map(static fn($t) => ['days' => $t[0], 'pct' => $t[1]], $tiers), 'noshow_pct' => $ns]);

// ───────────────────────── 1. Pure unit tests ─────────────────────────
$tiers = [['days' => 7, 'pct' => 50.0], ['days' => 2, 'pct' => 100.0]];
$pick = static fn(int $d) => rh_cancel_policy_tier($tiers, $d);
ok('tier: 10 days before = no fee', $pick(10) === null);
ok('tier: exactly 7 days before = still free (strict)', $pick(7) === null);
ok('tier: 6 days before = 50%', ($pick(6)['pct'] ?? null) === 50.0);
ok('tier: 5 days before = 50%', ($pick(5)['pct'] ?? null) === 50.0);
ok('tier: 2 days before = 50% (fewer than 7, not fewer than 2)', ($pick(2)['pct'] ?? null) === 50.0);
ok('tier: 1 day before = 100%', ($pick(1)['pct'] ?? null) === 100.0);
ok('tier: arrival day (0) = 100%', ($pick(0)['pct'] ?? null) === 100.0);
ok('tier: after arrival (-1) = smallest tier 100%', ($pick(-1)['pct'] ?? null) === 100.0);
ok('tier: no tiers = no fee', rh_cancel_policy_tier([], 0) === null);
$errs = [];
ok('validate: blank rows dropped, sorted by days desc', rh_cancel_policy_validate(['tiers' => [['days' => '2', 'pct' => '100'], ['days' => '', 'pct' => ''], ['days' => '7', 'pct' => '50']], 'noshow_pct' => ''], $errs) === ['tiers' => [['days' => 7, 'pct' => 50.0], ['days' => 2, 'pct' => 100.0]], 'noshow_pct' => 100.0]);
foreach ([
    'days 0' => [['days' => '0', 'pct' => '50']], 'days 366' => [['days' => '366', 'pct' => '50']], 'pct 101' => [['days' => '3', 'pct' => '101']],
    'pct negative' => [['days' => '3', 'pct' => '-1']], 'duplicate days' => [['days' => '3', 'pct' => '10'], ['days' => '3', 'pct' => '20']],
    'days not whole' => [['days' => '2.5', 'pct' => '10']], 'six rows' => array_map(static fn($i) => ['days' => (string)$i, 'pct' => '10'], range(1, 6)),
] as $label => $rows) {
    ok("validate rejects: $label", rh_cancel_policy_validate(['tiers' => $rows, 'noshow_pct' => '100'], $errs) === null && $errs !== []);
}
ok('validate rejects: no-show 101', rh_cancel_policy_validate(['tiers' => [], 'noshow_pct' => '101'], $errs) === null);
ok('validate: pct kept to 2 dp', (rh_cancel_policy_validate(['tiers' => [['days' => '3', 'pct' => '12.345']], 'noshow_pct' => '0'], $errs)['tiers'][0]['pct'] ?? null) === 12.35);
$text = rh_cancel_policy_text(['tiers' => $tiers, 'noshow_pct' => 100, 'has_group' => false, 'group_min_rooms' => 2, 'note' => ''], 'individual');
$want = "Cancellation policy. Cancelled less than 7 days before arrival: 50% of the stay is charged. Cancelled less than 2 days before arrival: 100%. Cancelled 7 or more days before arrival: no charge.\n"
    . "No-show: 100% of the stay. Percentages are of the whole stay including VAT and tourism levy; extras already used stay payable.";
ok('generator: exact wording', $text === $want, $text);
ok('generator: plain ASCII only (PDF core fonts)', !preg_match('/[^\x0A\x20-\x7E]/', $text));
$tg = rh_cancel_policy_text(['tiers' => [['days' => 1, 'pct' => 30]], 'noshow_pct' => 0, 'has_group' => true, 'group_min_rooms' => 4, 'note' => 'Extra note.'], 'group');
ok('generator: group heading, arrival-day tier, no-show none, note last', strpos($tg, 'Cancellation policy (group bookings of 4 rooms or more).') === 0
    && strpos($tg, 'Cancelled on the arrival day: 30% of the stay is charged.') !== false && strpos($tg, 'No-show: no charge.') !== false && substr($tg, -12) === "\nExtra note.", $tg);
ok('generator: individual heading when a group policy exists', strpos(rh_cancel_policy_text(['tiers' => [], 'noshow_pct' => 100, 'has_group' => true, 'group_min_rooms' => 3], 'individual'), 'Cancellation policy (individual bookings, fewer than 3 rooms).') === 0);
$tz = new DateTimeZone(RH_TIMEZONE);
ok('days before: same calendar day = 0', rh_days_before_arrival('2026-11-20', new DateTimeImmutable('2026-11-20 23:59:00', $tz)) === 0);
ok('days before: one minute before midnight = 1, after = 0', rh_days_before_arrival('2026-11-20', new DateTimeImmutable('2026-11-19 23:59:00', $tz)) === 1
    && rh_days_before_arrival('2026-11-20', new DateTimeImmutable('2026-11-20 00:01:00', $tz)) === 0);
ok('days before: counted in the hotel timezone, not UTC (22:30 UTC on the 19th = the 20th in ' . RH_TIMEZONE . ')',
    rh_days_before_arrival('2026-11-20', new DateTimeImmutable('2026-11-19 22:30:00', new DateTimeZone('UTC'))) === (RH_TIMEZONE === 'Africa/Blantyre' ? 0 : rh_days_before_arrival('2026-11-20', (new DateTimeImmutable('2026-11-19 22:30:00', new DateTimeZone('UTC')))->setTimezone($tz))));
ok('days before: past arrival is negative', rh_days_before_arrival('2026-11-18', new DateTimeImmutable('2026-11-20 10:00:00', $tz)) === -2);
ok('days before: unreadable date = PHP_INT_MAX (never inside a window)', rh_days_before_arrival('not-a-date') === PHP_INT_MAX && rh_days_before_arrival('2026-02-31') === PHP_INT_MAX);
ok('example: the strict boundary with dates', rh_cancel_policy_example(['tiers' => $tiers], new DateTimeImmutable('2026-11-20', $tz))
    === 'Example: arrival Fri 20 Nov 2026. Cancelled on Fri 13 Nov (7 days before): no charge. Cancelled on Sat 14 Nov (6 days before): 50% of the stay.');
$share = rh_room_night_share(['total_with_vat' => 1195, 'vat_amount' => 165, 'tourism_levy_amount' => 30], 597.5);
ok('VAT/levy split of a fee follows rh_room_night_share (half the stay = half the VAT and levy)', $near($share['vat'], 82.5) && $near($share['levy'], 15) && $near($share['net'], 500));
ok('pct label drops zeros', rh_cancel_policy_pct_label(100) === '100' && rh_cancel_policy_pct_label(12.5) === '12.5' && rh_cancel_policy_pct_label(0) === '0');
ok('fee choice: nothing posted = null (bookings without a policy run as before)', rh_cancel_fee_choice_from_post([], false) === null && rh_cancel_fee_choice_from_post(['fee_override' => '5'], true) === null);
ok('fee choice: single / group shapes', rh_cancel_fee_choice_from_post(['fee_quoted' => '10.00', 'fee_override' => '5', 'fee_override_reason' => 'x'], false) === ['quoted' => '10.00', 'reason' => 'x', 'override' => '5']
    && rh_cancel_fee_choice_from_post(['fee_quoted' => '10.00', 'fee_override_pct' => '5'], true) === ['quoted' => '10.00', 'reason' => '', 'override_pct' => '5']);

// ───────────────────────── DB rehearsals (rolled back) ─────────────────────────
if (!rh_cancel_policy_column_ready($pdo)) {
    fwrite(STDERR, "Migration 085 has not run here: run php admin/migrations/migrate.php --run first.\n");
    echo "\n$pass passed, $fail failed (DB part skipped)\n";
    exit(1);
}
$admin = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$roomId = (int)$pdo->query("SELECT id FROM rooms ORDER BY id LIMIT 1")->fetchColumn();
$noPerm = 2000000000; // no such user: holds no permission

/** Booking: 1,165 a night all-in (1,000 net + 165 VAT, no levy); check-in $inDays from today (negative = past). */
function cp_booking(PDO $pdo, string $status, int $nights, int $inDays, ?int $primaryId = null): int {
    global $roomId;
    $in = date('Y-m-d', strtotime(($inDays >= 0 ? '+' : '') . $inDays . ' day'));
    $out = date('Y-m-d', strtotime($in . ' +' . $nights . ' day'));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, primary_booking_id, created_at)
        VALUES (?,?,'CP TEST','zz.cp@example.invalid','+265000000009','Malawi',1,1,0,50,?,?,?,?,0,0,0,16.5,?,?,'cancellation policy test',?,'unpaid',?,'single',?,?,NOW())")
        ->execute(['ZCPT' . random_int(100000, 999999), $roomId, $in, $out, $nights, 1000 * $nights, 165 * $nights, 1165 * $nights, $status, $status === 'tentative' ? 1 : 0, bin2hex(random_bytes(16)), $primaryId]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return $id;
}
function cp_pay(PDO $pdo, int $bid, float $amt): void {
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, 0, 0, ?, 'cash', 'partial_payment', 'completed', NOW())")
        ->execute(['ZCPP' . random_int(100000, 999999), $bid, $amt, $amt]);
    recalculateBookingFinancials($bid);
}
function cp_row(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}
function cp_refunds(PDO $pdo, int $bid): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM payments WHERE booking_id = ? AND booking_type = 'room' AND payment_type = 'refund'");
    $st->execute([$bid]);
    return round((float)$st->fetchColumn(), 2);
}
function cp_tagged(PDO $pdo, int $bid): array {
    $st = $pdo->prepare('SELECT line_total, vat_amount, voided, description FROM booking_charges WHERE booking_id = ? AND description LIKE ?');
    $st->execute([$bid, '%' . rh_noshow_fee_tag($bid)]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function cp_hist(PDO $pdo, int $bid, string $action): array {
    $st = $pdo->prepare('SELECT description, old_value, new_value, performed_by_id FROM booking_timeline_logs WHERE booking_id = ? AND action = ? ORDER BY id');
    $st->execute([$bid, $action]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
/** Turn the policy on (in memory) and make a booking stamped under it. */
function cp_policy_booking(PDO $pdo, string $status, int $nights, int $inDays): int {
    $id = cp_booking($pdo, $status, $nights, $inDays);
    rh_cancel_policy_stamp($pdo, [$id]);
    return $id;
}

$pdo->beginTransaction();
try {
    // ---- Stamping: Off (code default) writes nothing; On writes once, never overwrites ----
    foreach (array_keys(rh_cancel_policy_defaults()) as $k) { $set($k, null); }
    $GLOBALS['_SITE_SETTINGS']['cancellation_policy_enabled'] = '0';
    $off = cp_booking($pdo, 'confirmed', 4, 5);
    rh_cancel_policy_stamp($pdo, [$off]);
    ok('Off: no snapshot written', cp_row($pdo, $off)['cancellation_policy_snapshot'] === null);
    $set('cancellation_policy_enabled', '1');
    $set('cancellation_policy_individual', $pol([[7, 50], [2, 100]], 100));
    $set('cancellation_policy_group', '');
    $set('cancellation_policy_group_min_rooms', '3');
    $set('cancellation_policy_version', '7');
    $set('cancellation_policy_note', '');
    $one = cp_policy_booking($pdo, 'confirmed', 4, 5);
    $snap = rh_cancel_policy_snapshot(cp_row($pdo, $one));
    ok('On: snapshot written (individual, v7, text = generator)', $snap !== null && $snap['kind'] === 'individual' && $snap['policy_version'] === 7 && $snap['text'] === rh_current_policy_text('individual'), json_encode($snap));
    $set('cancellation_policy_individual', $pol([[30, 10]], 100));
    $set('cancellation_policy_version', '8');
    rh_cancel_policy_stamp($pdo, [$one]);
    ok('a snapshot is never overwritten (policy edited later)', rh_cancel_policy_snapshot(cp_row($pdo, $one))['policy_version'] === 7);
    $set('cancellation_policy_individual', $pol([[7, 50], [2, 100]], 100));
    $set('cancellation_policy_version', '7');

    // ---- 3. Per-tier rehearsals: 4 nights x 1,165 = 4,660 whole stay ----
    $cases = [ // [label, days before arrival, expected fee]
        ['10 days before (outside every window)', 10, 0.0],
        ['exactly 7 days before (still free)', 7, 0.0],
        ['5 days before (less than 7: 50%)', 5, 2330.0],
        ['1 day before (less than 2: 100%)', 1, 4660.0],
        ['arrival day (100%)', 0, 4660.0],
    ];
    foreach ($cases as [$label, $days, $fee]) {
        $id = cp_policy_booking($pdo, 'confirmed', 4, $days);
        $q = rh_cancellation_fee_quote($pdo, cp_row($pdo, $id));
        ok("quote $label = " . number_format($fee, 2), $q['source'] === 'policy' && $near($q['amount'], $fee) && $q['days_before'] === $days, json_encode($q));
    }
    foreach (['void_refund_all', 'keep_first_night', 'void_keep_credit'] as $mode) {
        $set('cancellation_refund_mode', $mode);
        foreach (['paid in full' => 4660.0, 'part-paid' => 1000.0, 'unpaid' => 0.0] as $payLabel => $paid) {
            $id = cp_policy_booking($pdo, 'confirmed', 4, 5);
            if ($paid > 0) { cp_pay($pdo, $id, $paid); }
            $r = cancelRoomBookingSettled($pdo, $id, $admin, 'test', 'completed', ['quoted' => 2330]);
            $b = cp_row($pdo, $id);
            $left = max(0, $paid - 2330);
            $wantRefund = $mode === 'void_keep_credit' ? 0.0 : $left;
            $wantCredit = $mode === 'void_keep_credit' ? $left : 0.0;
            $wantDue = max(0, 2330 - $paid);
            ok("$mode, $payLabel, 5 days before: fee 2,330 kept (never the first night), refund " . number_format($wantRefund) . ', credit ' . number_format($wantCredit) . ', still owed ' . number_format($wantDue),
                !empty($r['success']) && $r['fee_source'] === 'policy' && $near($b['cancellation_retained_amount'], 2330) && $near(cp_refunds($pdo, $id), $wantRefund)
                && $near($b['credit_balance'], $wantCredit) && $near($b['amount_due'], $wantDue), json_encode([$r['error'] ?? '', $b['cancellation_retained_amount'], cp_refunds($pdo, $id), $b['credit_balance'], $b['amount_due']]));
        }
        $id = cp_policy_booking($pdo, 'confirmed', 4, 10);
        cp_pay($pdo, $id, 4660);
        $r = cancelRoomBookingSettled($pdo, $id, $admin, 'test');
        $b = cp_row($pdo, $id);
        ok("$mode, outside every window: no fee (no quote needed), " . ($mode === 'void_keep_credit' ? 'all kept as credit' : 'all refunded'),
            !empty($r['success']) && $near($b['cancellation_retained_amount'], 0) && ($mode === 'void_keep_credit' ? $near($b['credit_balance'], 4660) : $near(cp_refunds($pdo, $id), 4660)), json_encode([$r['error'] ?? '', $b['cancellation_retained_amount']]));
    }
    $set('cancellation_refund_mode', 'keep_first_night');
    $id = cp_policy_booking($pdo, 'pending', 4, 1);
    cp_pay($pdo, $id, 4660);
    $r = cancelRoomBookingSettled($pdo, $id, $admin, 'test');
    ok('pending under a policy: no fee even inside a window and under keep_first_night; all refunded', !empty($r['success']) && $near(cp_row($pdo, $id)['cancellation_retained_amount'], 0) && $near(cp_refunds($pdo, $id), 4660), json_encode($r));
    $id = cp_policy_booking($pdo, 'tentative', 4, 1);
    $r = cancelRoomBookingSettled($pdo, $id, $admin, 'test');
    ok('tentative under a policy: no fee', !empty($r['success']) && $near(cp_row($pdo, $id)['cancellation_retained_amount'], 0));
    $leg = cp_booking($pdo, 'confirmed', 4, 1);
    cp_pay($pdo, $leg, 4660);
    $r = cancelRoomBookingSettled($pdo, $leg, $admin, 'test', 'completed', ['quoted' => 999]);
    ok('no snapshot: today\'s rule (keep_first_night keeps 1,165), any posted fee ignored, "(first night)" summary', !empty($r['success']) && $r['fee_source'] === 'legacy'
        && $near(cp_row($pdo, $leg)['cancellation_retained_amount'], 1165) && strpos($r['summary'], '(first night)') !== false, json_encode($r));
    $set('cancellation_refund_mode', 'void_refund_all');

    // ---- VAT / levy inside a kept fee follow rh_room_night_share (invoice / EOD consistency) ----
    $id = cp_policy_booking($pdo, 'confirmed', 4, 5);
    cancelRoomBookingSettled($pdo, $id, $admin, 'test', 'completed', ['quoted' => 2330]);
    $fs = getBookingFolioSummary($id);
    ok('cancelled with a fee: the folio bills the fee only (2,330); its VAT share is half the stay\'s (330)', $near($fs['grand_total'] ?? -1, 2330) && $near(rh_room_night_share(cp_row($pdo, $id), 2330)['vat'], 330), json_encode($fs));

    // ---- 6. Refusals and overrides ----
    $id = cp_policy_booking($pdo, 'confirmed', 4, 5);
    $r = cancelRoomBookingSettled($pdo, $id, $admin, 'test');
    ok('a fee above tolerance with no quoted figure is refused ("review the fee"), nothing changes', empty($r['success']) && strpos($r['error'], 'Review the cancellation fee') !== false && cp_row($pdo, $id)['status'] === 'confirmed', $r['error']);
    $r = cancelRoomBookingSettled($pdo, $id, $admin, 'test', 'completed', ['quoted' => 100]);
    ok('a stale quote is refused ("the fee changed (now ...)"), nothing changes', empty($r['success']) && strpos($r['error'], 'The cancellation fee changed (now') !== false && cp_row($pdo, $id)['status'] === 'confirmed', $r['error']);
    $r = cancelRoomBookingSettled($pdo, $id, $noPerm, 'test', 'completed', ['quoted' => 2330, 'override' => 1000, 'reason' => 'x']);
    ok('override refused without the permission', empty($r['success']) && strpos($r['error'], 'permission') !== false, $r['error']);
    $r = cancelRoomBookingSettled($pdo, $id, 0, 'test', 'completed', ['quoted' => 2330, 'override' => 0, 'reason' => 'x']);
    ok('override refused for a guest (no admin user)', empty($r['success']) && strpos($r['error'], 'permission') !== false, $r['error']);
    $r = cancelRoomBookingSettled($pdo, $id, $admin, 'test', 'completed', ['quoted' => 2330, 'override' => 3000, 'reason' => 'x']);
    ok('override refused above the fee (never a raise)', empty($r['success']) && strpos($r['error'], 'only be lowered') !== false, $r['error']);
    $r = cancelRoomBookingSettled($pdo, $id, $admin, 'test', 'completed', ['quoted' => 2330, 'override' => 1000, 'reason' => '  ']);
    ok('override refused without a reason', empty($r['success']) && strpos($r['error'], 'reason') !== false && cp_row($pdo, $id)['status'] === 'confirmed', $r['error']);
    $r = cancelRoomBookingSettled($pdo, $id, $admin, 'test', 'completed', ['quoted' => 2330, 'override' => 1000, 'reason' => 'Manager agreed']);
    $hs = cp_hist($pdo, $id, 'Cancellation settled');
    $hl = cp_hist($pdo, $id, 'Cancellation fee lowered');
    ok('override 1,000 with permission + reason: retained 1,000; History "Cancellation settled" (policy, tier, %) + "Cancellation fee lowered" (old -> new, who, why)',
        !empty($r['success']) && $near(cp_row($pdo, $id)['cancellation_retained_amount'], 1000) && $near($r['fee_override'], 1000)
        && count($hs) === 1 && strpos($hs[0]['description'], 'Cancellation policy v7') !== false && strpos($hs[0]['description'], '50% of the stay') !== false
        && count($hl) === 1 && strpos($hl[0]['description'], 'Manager agreed') !== false && (int)$hl[0]['performed_by_id'] === $admin && strpos((string)$hl[0]['old_value'], '2,330.00') !== false,
        json_encode([$r, $hs, $hl]));
    // Lowering after cancellation (booking-details.php does exactly this under the row lock).
    $id = cp_policy_booking($pdo, 'confirmed', 4, 5);
    cp_pay($pdo, $id, 2330);
    cancelRoomBookingSettled($pdo, $id, $admin, 'test', 'completed', ['quoted' => 2330]);
    $pdo->prepare('UPDATE bookings SET cancellation_retained_amount = ? WHERE id = ?')->execute([500, $id]);
    recalculateBookingFinancials($id);
    ok('lowering a kept fee after cancellation (2,330 -> 500) turns the difference into credit owed back (1,830)', $near(cp_row($pdo, $id)['credit_balance'], 1830) && $near(cp_row($pdo, $id)['amount_due'], 0), json_encode(cp_row($pdo, $id)));

    // ---- 4. Groups ----
    $set('cancellation_policy_group', $pol([[30, 25]], 50));
    $g1 = cp_booking($pdo, 'confirmed', 4, 10);
    $g2 = cp_booking($pdo, 'confirmed', 4, 10, $g1);
    $g3 = cp_booking($pdo, 'confirmed', 4, 10, $g1);
    rh_cancel_policy_stamp($pdo, [$g1, $g2, $g3]);
    $kinds = array_map(static fn($i) => rh_cancel_policy_snapshot(cp_row($pdo, $i))['kind'] ?? '', [$g1, $g2, $g3]);
    ok('group of 3 (minimum 3): every room stamped "group" with the group policy', $kinds === ['group', 'group', 'group'] && strpos(rh_booking_policy_text(cp_row($pdo, $g2)), 'group bookings of 3 rooms') !== false, json_encode($kinds));
    $two = cp_booking($pdo, 'confirmed', 4, 10);
    $two2 = cp_booking($pdo, 'confirmed', 4, 10, $two);
    rh_cancel_policy_stamp($pdo, [$two, $two2]);
    ok('2 rooms under a minimum of 3: individual', (rh_cancel_policy_snapshot(cp_row($pdo, $two))['kind'] ?? '') === 'individual');
    $r = cancelRoomBookingSettled($pdo, $g3, $admin, 'test', 'completed', ['quoted' => 1165]);
    ok('cancel 1 of 3: that room pays its own 25% (1,165); the others are untouched', !empty($r['success']) && $near(cp_row($pdo, $g3)['cancellation_retained_amount'], 1165)
        && cp_row($pdo, $g1)['status'] === 'confirmed' && cp_row($pdo, $g2)['status'] === 'confirmed', json_encode($r));
    $gr = rh_cancel_booking_group($pdo, [$g1, $g2], $admin, 'test', 'completed', ['quoted' => 100, 'override_pct' => null, 'reason' => '']);
    ok('group cancel with a stale total is refused, nothing changes', empty($gr['success']) && strpos($gr['error'], 'The cancellation fee changed') !== false && cp_row($pdo, $g1)['status'] === 'confirmed', $gr['error']);
    $gr = rh_cancel_booking_group($pdo, [$g1, $g2], $admin, 'test', 'completed', ['quoted' => 2330, 'override_pct' => 30, 'reason' => 'x']);
    ok('group override above the rooms\' % is refused', empty($gr['success']) && strpos($gr['error'], 'only be lowered') !== false, $gr['error']);
    $gr = rh_cancel_booking_group($pdo, [$g1, $g2], $admin, 'test', 'completed', ['quoted' => 2330, 'override_pct' => 10, 'reason' => 'Event moved']);
    ok('group cancel with override 10%: each room keeps 466 (10% of its stay), History "Cancellation fee lowered" on each',
        !empty($gr['success']) && $near(cp_row($pdo, $g1)['cancellation_retained_amount'], 466) && $near(cp_row($pdo, $g2)['cancellation_retained_amount'], 466)
        && count(cp_hist($pdo, $g1, 'Cancellation fee lowered')) === 1 && count(cp_hist($pdo, $g2, 'Cancellation fee lowered')) === 1, json_encode($gr));
    // Group no-show with group_noshow_charge On: the switch never posts; one tagged row, the policy's 50%.
    $GLOBALS['_SITE_SETTINGS']['group_noshow_charge'] = 'first_night';
    $n1 = cp_booking($pdo, 'confirmed', 4, -2);
    $n2 = cp_booking($pdo, 'confirmed', 4, -2, $n1);
    $n3 = cp_booking($pdo, 'confirmed', 4, -2, $n1);
    rh_cancel_policy_stamp($pdo, [$n1, $n2, $n3]);
    $ns = rh_mark_booking_no_show($pdo, $n2, $admin, 'Test');
    $rows = cp_tagged($pdo, $n2);
    ok('group no-show (group_noshow_charge first_night On): ONE tagged row = the policy\'s 50% (2,330), never the first night',
        !empty($ns['success']) && count($rows) === 1 && $near($rows[0]['line_total'], 2330) && (int)$rows[0]['voided'] === 0 && ($ns['group_fee']['mode'] ?? '') === 'policy'
        && strpos($rows[0]['description'], '50% of the stay (policy v7)') !== false && $near($rows[0]['vat_amount'], rh_room_night_share(cp_row($pdo, $n2), 2330)['vat']), json_encode([$ns, $rows]));
    $again = rh_policy_noshow_fee_apply($pdo, $n2, $admin);
    ok('the policy no-show charge never posts twice', !$again['posted'] && count(cp_tagged($pdo, $n2)) === 1);
    $legG = cp_booking($pdo, 'confirmed', 4, -2);
    $legG2 = cp_booking($pdo, 'confirmed', 4, -2, $legG);
    $nsL = rh_mark_booking_no_show($pdo, $legG2, $admin, 'Test');
    ok('a group without a snapshot still gets today\'s group charge (first night 1,165)', !empty($nsL['success']) && count(cp_tagged($pdo, $legG2)) === 1 && $near(cp_tagged($pdo, $legG2)[0]['line_total'], 1165));
    $GLOBALS['_SITE_SETTINGS']['group_noshow_charge'] = '0';
    $set('cancellation_policy_group', '');

    // ---- 5. No-show (individual) ----
    $set('cancellation_policy_individual', $pol([[7, 50]], 100));
    $set('no_show_refund_policy', 'first_night');
    $id = cp_policy_booking($pdo, 'confirmed', 4, -2);
    cp_pay($pdo, $id, 4660);
    $ns = rh_mark_booking_no_show($pdo, $id, $admin, 'Test');
    ok('no-show 100% under rule first_night: no charge row, the stay keeps billing, NO first-night refund', !empty($ns['success']) && cp_tagged($pdo, $id) === []
        && $near(cp_refunds($pdo, $id), 0) && $near(cp_row($pdo, $id)['amount_due'], 0) && !$ns['refund']['created'], json_encode($ns));
    $id = cp_policy_booking($pdo, 'confirmed', 4, -2);
    cp_pay($pdo, $id, 5000);
    $ns = rh_mark_booking_no_show($pdo, $id, $admin, 'Test');
    ok('no-show 100%, paid 5,000 on a 4,660 stay: refund only the 340 above the stay', !empty($ns['success']) && $near(cp_refunds($pdo, $id), 340), json_encode($ns['refund']));
    $set('cancellation_policy_individual', $pol([[7, 50]], 50));
    $set('no_show_refund_policy', 'none');
    $id = cp_policy_booking($pdo, 'confirmed', 4, -2);
    cp_pay($pdo, $id, 4660);
    $ns = rh_mark_booking_no_show($pdo, $id, $admin, 'Test');
    $b = cp_row($pdo, $id);
    ok('no-show 50% under rule none: tagged row 2,330, no refund, 2,330 paid above it is credit owed back', !empty($ns['success']) && count(cp_tagged($pdo, $id)) === 1
        && $near(cp_tagged($pdo, $id)[0]['line_total'], 2330) && $near(cp_refunds($pdo, $id), 0) && $near($b['credit_balance'], 2330), json_encode([$ns, $b['credit_balance']]));
    $set('no_show_refund_policy', 'full');
    $id = cp_policy_booking($pdo, 'confirmed', 4, -2);
    cp_pay($pdo, $id, 4660);
    $ns = rh_mark_booking_no_show($pdo, $id, $admin, 'Test');
    ok('no-show 50% under rule full: refund what was paid above the charge (2,330)', !empty($ns['success']) && $near(cp_refunds($pdo, $id), 2330), json_encode($ns['refund']));
    $set('cancellation_policy_individual', $pol([[7, 50]], 0));
    $id = cp_policy_booking($pdo, 'confirmed', 4, -2);
    $ns = rh_mark_booking_no_show($pdo, $id, $admin, 'Test');
    $rows = cp_tagged($pdo, $id);
    ok('no-show 0%: a VOIDED zero row, nothing owed', !empty($ns['success']) && count($rows) === 1 && (int)$rows[0]['voided'] === 1 && $near($rows[0]['line_total'], 0) && $near(cp_row($pdo, $id)['amount_due'], 0), json_encode($rows));
    $set('no_show_refund_policy', null);

    // ---- Documents: stored text printed; nothing for a booking without a policy ----
    $set('cancellation_policy_individual', $pol([[7, 50], [2, 100]], 100));
    require_once $root . '/config/email.php';
    require_once $root . '/config/invoice.php';
    $dp = cp_policy_booking($pdo, 'confirmed', 4, 5);
    $dl = cp_booking($pdo, 'confirmed', 4, 5);
    $roomRow = $pdo->query('SELECT * FROM rooms WHERE id = ' . (int)$roomId)->fetch(PDO::FETCH_ASSOC);
    $vp = buildBookingEmailVariables(cp_row($pdo, $dp), $roomRow);
    $vl = buildBookingEmailVariables(cp_row($pdo, $dl), $roomRow);
    ok('email tag: the stored text for a policy booking, empty without one', strpos($vp['cancellation_policy_text'], 'Cancelled less than 7 days before arrival') !== false && $vl['cancellation_policy_text'] === '');
    $rp = renderBookingEmailTemplate('booking_confirmed', $vp);
    $rl = renderBookingEmailTemplate('booking_confirmed', $vl);
    ok('booking_confirmed email: policy block added for the policy booking only', $rp === null || (strpos($rp['html_body'], 'Cancelled less than 7 days') !== false && strpos((string)$rl['html_body'], 'Cancelled less than 7 days') === false));
    $set('cancellation_policy_individual', $pol([[30, 10]], 100)); // a later edit never rewrites an old booking's documents
    ok('documents print the STORED text, not a regenerated one', strpos(rh_booking_policy_text(cp_row($pdo, $dp)), 'less than 7 days') !== false && strpos(rh_booking_policy_text_by_id($pdo, $dp), 'less than 7 days') !== false);
    $rowsP = rh_invoice_doc_rows($pdo, $dp);
    $t = null;
    $invP = buildInvoiceHTML($rowsP['booking'], 'T-CP', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $rowsP['group_bookings'], null, false, $t);
    $rowsL = rh_invoice_doc_rows($pdo, $dl);
    $invL = buildInvoiceHTML($rowsL['booking'], 'T-CP', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $rowsL['group_bookings'], null, false, $t);
    ok('invoice: "Cancellation policy" section only for the policy booking', strpos($invP, 'Cancelled less than 7 days') !== false && strpos($invL, 'Cancelled less than 7 days') === false);
    cancelRoomBookingSettled($pdo, $dp, $admin, 'test', 'completed', ['quoted' => 2330]);
    $vc = buildBookingEmailVariables(cp_row($pdo, $dp), $roomRow, ['cancellation_reason' => 'x']);
    ok('cancelled email: "Cancellation fee charged: MWK 2,330.00" for the policy booking', strpos($vc['cancellation_fee_charged'], '2,330.00') !== false);

    // ---- 7. Pre-migration: column treated as absent (rows without the key), no warnings ----
    $GLOBALS['rh_cancel_policy_column_override'] = false;
    $warn = 0;
    set_error_handler(static function (int $no) use (&$warn) { if (in_array($no, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_DEPRECATED], true)) { $warn++; } return true; });
    $pm = cp_booking($pdo, 'confirmed', 4, 5);
    $set('cancellation_policy_individual', $pol([[7, 50]], 100));
    rh_cancel_policy_stamp($pdo, [$pm]);
    $GLOBALS['rh_cancel_policy_column_override'] = null;
    $stampedAnyway = cp_row($pdo, $pm)['cancellation_policy_snapshot'] !== null;
    $GLOBALS['rh_cancel_policy_column_override'] = false;
    $noKey = cp_row($pdo, $one);
    unset($noKey['cancellation_policy_snapshot']);
    $q = rh_cancellation_fee_quote($pdo, $noKey);
    $txt = rh_booking_policy_text($noKey) . rh_booking_policy_text_by_id($pdo, $one);
    $r = cancelRoomBookingSettled($pdo, $pm, $admin, 'test');
    restore_error_handler();
    $GLOBALS['rh_cancel_policy_column_override'] = null;
    ok('column absent: nothing stamped, quote legacy, no text, a cancel runs today\'s code, no warnings', !$stampedAnyway && $q['source'] === 'legacy' && $txt === '' && !empty($r['success']) && $warn === 0,
        json_encode([$stampedAnyway, $q['source'], $txt, $r['error'] ?? '', $warn]));
} catch (Throwable $e) {
    ok('no exception in the DB rehearsals', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
foreach (array_keys(rh_cancel_policy_defaults()) as $k) { unset($GLOBALS['_SITE_SETTINGS'][$k]); }

// ───────────────────────── F2: guest whole-party cancel (page harness, refusals + views only) ─────────────────────────
$harness = $root . '/scripts/tests/page_harness.php';
function cp_reset_limits(): void {
    foreach (['lookup_form', 'cancel_booking'] as $action) {
        $key = 'iprl_' . $action . '_' . substr(hash('sha256', '127.0.0.1|' . $action), 0, 24);
        if (function_exists('deleteCache')) { @deleteCache($key); }
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $key . '.json';
        if (is_file($file)) { @unlink($file); }
    }
}
register_shutdown_function('cp_reset_limits');
$dumpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cpl_' . bin2hex(random_bytes(4)) . '.html';
register_shutdown_function(function () use ($dumpFile) { if (is_file($dumpFile)) { @unlink($dumpFile); } });
function cp_run(string $page, array $cfg): array {
    global $harness, $dumpFile;
    cp_reset_limits();
    if (is_file($dumpFile)) { @unlink($dumpFile); }
    $cfg += ['anon' => true, 'dump_html' => $dumpFile];
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -1200), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    ok("harness rolled back cleanly ($page)", !empty($res['rolled_back']) && empty($res['leak']) && empty($res['marker_lost']), json_encode(array_intersect_key($res, ['rolled_back' => 1, 'leak' => 1, 'marker_lost' => 1, 'raw' => 1, 'error' => 1])));
    $res['html'] = is_file($dumpFile) ? (string)file_get_contents($dumpFile) : '';
    return $res;
}
$prefix = 'ZCL' . strtoupper(bin2hex(random_bytes(3)));
$email = 'zz.cpl.' . strtolower(bin2hex(random_bytes(3))) . '@example.invalid';
$tok = bin2hex(random_bytes(32));
$snapJson = json_encode(['v' => 1, 'kind' => 'individual', 'group_min_rooms' => 3, 'rooms_at_booking' => 2, 'tiers' => [['days' => 30, 'pct' => 50]], 'noshow_pct' => 100,
    'policy_version' => 3, 'taken_at' => date('Y-m-d H:i:s'), 'text' => "Cancellation policy. Cancelled less than 30 days before arrival: 50% of the stay is charged. Cancelled 30 or more days before arrival: no charge.\nNo-show: 100% of the stay."]);
$q = static fn(?string $s): string => $s === null ? 'NULL' : $GLOBALS['pdo']->quote($s);
$row = static function (string $ref, int $inDays, bool $link, ?string $snap, string $status = 'confirmed', ?int $billing = null) use ($q, $email, $roomId): string {
    return "INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, amount_paid, amount_due, status, is_tentative, primary_booking_id, cancellation_policy_snapshot"
        . ($billing !== null ? ', billing_account_id' : '') . ") VALUES ("
        . $q($ref) . ', ' . (int)$roomId . ", 'Zz Policy Test', " . $q($email) . ", '+265000000000', DATE_ADD(CURDATE(), INTERVAL $inDays DAY), DATE_ADD(CURDATE(), INTERVAL " . ($inDays + 2) . " DAY), 2, "
        . "2, 2, 0, 10000, 10000, 0, 10000, " . $q($status) . ", 0, " . ($link ? '@cpl_lead' : 'NULL') . ', ' . $q($snap) . ($billing !== null ? ', ' . (int)$billing : '') . ')';
};
$lead = 'SET @cpl_lead = LAST_INSERT_ID()';
$notice = static fn(int $d): string => "INSERT INTO site_settings (setting_key, setting_value) VALUES ('cancellation_notice_days','$d') ON DUPLICATE KEY UPDATE setting_value = '$d'";
$lookup = static function (string $ref, array $setup) use ($email, $tok): array {
    return cp_run('booking-lookup.php', ['setup' => $setup, 'session' => ['_csrf_pub_lookup' => $tok],
        'post' => ['lookup' => '1', 'csrf_token' => $tok, 'booking_reference' => $ref, 'guest_email' => $email]]);
};
$cancel = static function (string $ref, array $setup, array $extra) use ($email, $tok): array {
    return cp_run('booking-lookup.php', ['setup' => $setup, 'session' => ['_csrf_pub_lookup' => $tok],
        'post' => ['cancel_booking' => '1', 'csrf_token' => $tok, 'booking_reference' => $ref, 'guest_email' => $email, 'cancel_reason' => 'test'] + $extra,
        'verify' => ["SELECT booking_reference, status FROM bookings WHERE booking_reference LIKE " . $GLOBALS['pdo']->quote($ref . '%') . " ORDER BY id",
                     "SELECT COUNT(*) AS n FROM cancellation_log WHERE booking_reference LIKE " . $GLOBALS['pdo']->quote($ref . '%')]]);
};
$flash = fn(array $r): string => implode(' | ', $r['output_flash'] ?? []);
$unchanged = static fn(array $r): bool => is_array($r['verify'][0] ?? null) && count($r['verify'][0]) >= 1 && !in_array('cancelled', array_column($r['verify'][0], 'status'), true) && (int)($r['verify'][1][0]['n'] ?? -1) === 0;

// Party of 2 under a policy, 10 days out: 50% of 10,000 each = 10,000 in total.
$R = $prefix . 'P';
$party = [$notice(0), $row($R, 10, false, $snapJson), $lead, $row($R . '-2', 10, true, $snapJson)];
$r = $lookup($R . '-2', $party);
ok('lookup (party under a policy): both rooms listed with the total fee', strpos($r['html'], 'Cancel My Booking (all 2 rooms)') !== false
    && strpos($r['html'], $R . '-2') !== false && (bool)preg_match('#Cancellation fee: [^<]*10,000\.00#', $r['html']), (string)($r['raw'] ?? ''));
ok('lookup: accept tick and fee_quoted 10000.00 in the form; stored policy text shown', strpos($r['html'], 'name="accept_fee"') !== false && strpos($r['html'], 'name="fee_quoted" value="10000.00"') !== false
    && strpos($r['html'], 'Cancelled less than 30 days before arrival') !== false);
$r = $cancel($R, $party, ['fee_quoted' => '10000.00']);
ok('cancel without the "I accept" tick is refused, nothing changes', strpos($flash($r), 'tick the box to accept the cancellation fee') !== false && $unchanged($r), $flash($r) . json_encode($r['verify'] ?? []));
$r = $cancel($R, $party, ['fee_quoted' => '1.00', 'accept_fee' => '1']);
ok('cancel with a stale fee is refused for the whole party, nothing changes', strpos($flash($r), 'cancellation fee has changed') !== false && $unchanged($r), $flash($r) . json_encode($r['verify'] ?? []));
// Company-billed lead: online cancel hidden and refused.
$R = $prefix . 'B';
$billed = [$notice(0), $row($R, 10, false, null, 'confirmed', 999999), $lead, $row($R . '-2', 10, true, null)];
$r = $lookup($R . '-2', $billed);
ok('company-billed party: "contact us" instead of the cancel form', strpos($r['html'], 'billed to a company account') !== false && strpos($r['html'], 'id="cancel-form"') === false);
$r = $cancel($R . '-2', $billed, []);
ok('company-billed party: an online cancel POST is refused, nothing changes', strpos($flash($r), 'billed to a company account') !== false && $unchanged($r), $flash($r));
// Notice window checked on the party's earliest check-in (the looked-up room is far out, its partner is 2 days out).
$R = $prefix . 'N';
$win = [$notice(5), $row($R, 60, false, null), $lead, $row($R . '-2', 2, true, null)];
$r = $cancel($R, $win, []);
ok('notice window on the earliest check-in of the party refuses, nothing changes', strpos($flash($r), 'not available within 5 day(s)') !== false && $unchanged($r), $flash($r));
// A checked-in room is not part of what the guest can cancel; a legacy single booking shows the old form unchanged.
$R = $prefix . 'C';
$r = $lookup($R, [$notice(0), $row($R, 10, false, null), $lead, $row($R . '-2', 0, true, null, 'checked-in')]);
ok('a checked-in room is left out of the party (single "Cancel My Booking", no fee block)', strpos($r['html'], 'Cancel My Booking (all') === false && strpos($r['html'], 'id="cancel-form"') !== false
    && strpos($r['html'], 'name="fee_quoted"') === false && strpos($r['html'], 'name="accept_fee"') === false);
// The full guest success path, end to end: every email goes to a local capture SMTP server (scripts/tests/fake-smtp.php,
// harness fake_smtp), WhatsApp is switched off inside the rolled-back run, so nothing leaves this machine.
$port = 0;
for ($try = 0; $try < 20 && !$port; $try++) {
    $cand = random_int(20000, 45000);
    $probeS = @stream_socket_server('tcp://127.0.0.1:' . $cand, $e1, $e2);
    if ($probeS) { fclose($probeS); $port = $cand; }
}
$spool = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_cp_smtp_' . bin2hex(random_bytes(4)) . '.jsonl';
$smtp = $port ? proc_open([PHP_BINARY, $root . '/scripts/tests/fake-smtp.php', (string)$port, $spool], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/rh_cp_smtp.out', 'a'], 2 => ['file', sys_get_temp_dir() . '/rh_cp_smtp.err', 'a']], $pipes) : null;
for ($i = 0; $i < 50 && !is_file($spool . '.ready'); $i++) { usleep(100000); }
register_shutdown_function(function () use (&$smtp, $spool) {
    if (is_resource($smtp)) { proc_terminate($smtp); proc_close($smtp); }
    @unlink($spool); @unlink($spool . '.ready');
});
if (is_resource($smtp) && is_file($spool . '.ready')) {
    $R = $prefix . 'S';
    $okSetup = [$notice(0), "INSERT INTO site_settings (setting_key, setting_value) VALUES ('whatsapp_enabled','0'),('whatsapp_notifications_enabled','0'),('guest_self_cancel_refund','pending') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        $row($R, 10, false, $snapJson), $lead, $row($R . '-2', 10, true, $snapJson),
        "INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount, payment_method, payment_type, payment_status, created_at) VALUES ("
            . $q($prefix . 'PAY') . ", 'room', @cpl_lead, " . $q($R) . ", CURDATE(), 6000, 0, 0, 6000, 'cash', 'partial_payment', 'completed', NOW())"];
    $r = cp_run('booking-lookup.php', ['setup' => $okSetup, 'session' => ['_csrf_pub_lookup' => $tok], 'fake_smtp' => $port,
        'post' => ['cancel_booking' => '1', 'csrf_token' => $tok, 'booking_reference' => $R . '-2', 'guest_email' => $email, 'cancel_reason' => 'test', 'fee_quoted' => '10000.00', 'accept_fee' => '1'],
        'verify' => ["SELECT booking_reference, status, cancellation_retained_amount AS kept FROM bookings WHERE booking_reference LIKE " . $pdo->quote($R . '%') . " ORDER BY id",
                     "SELECT COUNT(*) AS n FROM cancellation_log WHERE booking_reference LIKE " . $pdo->quote($R . '%'),
                     "SELECT COALESCE(SUM(p.total_amount),0) AS t FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE b.booking_reference = " . $pdo->quote($R) . " AND p.payment_type = 'refund' AND p.refund_status = 'pending'"]]);
    $rowsS = is_array($r['verify'][0] ?? null) ? $r['verify'][0] : [];
    $mails = [];
    foreach ((is_file($spool) ? (file($spool, FILE_IGNORE_NEW_LINES) ?: []) : []) as $l) { $j = json_decode($l, true); if (is_array($j)) { $mails[] = $j; } }
    $cancelMails = array_values(array_filter($mails, static fn($m) => stripos((string)($m['subject'] ?? ''), 'cancel') !== false));
    ok('guest whole-party cancel (accepted fee): both rooms cancelled, each keeps its 5,000 fee, one cancellation_log row per room',
        count($rowsS) === 2 && array_column($rowsS, 'status') === ['cancelled', 'cancelled'] && $near($rowsS[0]['kept'] ?? -1, 5000) && $near($rowsS[1]['kept'] ?? -1, 5000)
        && (int)($r['verify'][1][0]['n'] ?? -1) === 2, json_encode([$r['verify'] ?? [], $flash($r), $r['raw'] ?? '']));
    ok('guest whole-party cancel: the 1,000 paid above the lead room\'s fee is queued as a pending refund; message says all 2 rooms + refund pending',
        $near($r['verify'][2][0]['t'] ?? -1, 1000) && strpos($flash($r), 'all 2 rooms') !== false && strpos($flash($r), 'pending approval') !== false, json_encode([$r['verify'][2] ?? null, $flash($r)]));
    ok('guest whole-party cancel: one cancellation email per room, captured locally, carrying the fee charged', count($cancelMails) === 2
        && strpos(json_encode($cancelMails), '5,000.00') !== false, json_encode(array_map(static fn($m) => [$m['subject'] ?? '', $m['to'] ?? ''], $mails)));
} else {
    ok('capture SMTP server started (needed for the guest success path)', false);
}

// Whole-party settle (what the page calls after the checks): rolled back, no email (called in-process).
$pdo->beginTransaction();
try {
    $p1 = cp_booking($pdo, 'confirmed', 2, 10);
    $p2 = cp_booking($pdo, 'confirmed', 2, 10, $p1);
    $p3 = cp_booking($pdo, 'checked-in', 2, 0, $p1);
    $pdo->prepare("UPDATE bookings SET guest_email = 'zz.party@example.invalid' WHERE id IN (?,?,?)")->execute([$p1, $p2, $p3]);
    $partyRows = rh_guest_cancel_party($pdo, cp_row($pdo, $p2), 'zz.party@example.invalid');
    ok('party = the rows with the proven email, not checked in, the looked-up row first', array_map('intval', array_column($partyRows, 'id')) === [$p2, $p1], json_encode(array_column($partyRows, 'id')));
    cp_pay($pdo, $p1, 1000);
    $gr = rh_cancel_booking_group($pdo, array_column($partyRows, 'id'), 0, 'Guest cancelled: test', 'pending', null);
    $pend = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE booking_id = " . (int)$p1 . " AND payment_type = 'refund' AND refund_status = 'pending'")->fetchColumn();
    ok('whole-party cancel: both rooms cancelled together, the guest refund queued as pending', !empty($gr['success']) && cp_row($pdo, $p1)['status'] === 'cancelled' && cp_row($pdo, $p2)['status'] === 'cancelled'
        && cp_row($pdo, $p3)['status'] === 'checked-in' && $pend >= 1, json_encode($gr));
} catch (Throwable $e) {
    ok('no exception in the party settle', false, $e->getMessage());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

// ───────────────────────── Admin screens (page harness, rolled back; no email) ─────────────────────────
$A = $prefix . 'A';
$adm = [$row($A . 'P', 10, false, $snapJson), $row($A . 'L', 10, false, null),
    "UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = 5000, amount_paid = 5000 WHERE booking_reference IN (" . $q($A . 'P') . ', ' . $q($A . 'L') . ')'];
$vars = ['p' => "SELECT id FROM bookings WHERE booking_reference = " . $q($A . 'P'), 'l' => "SELECT id FROM bookings WHERE booking_reference = " . $q($A . 'L')];
$lowerVerify = static fn(string $ref): array => ["SELECT cancellation_retained_amount AS kept FROM bookings WHERE booking_reference = " . $GLOBALS['pdo']->quote($ref),
    "SELECT COUNT(*) AS n FROM booking_timeline_logs t JOIN bookings b ON b.id = t.booking_id WHERE b.booking_reference = " . $GLOBALS['pdo']->quote($ref) . " AND t.action = 'Cancellation fee lowered'"];
$kept = static fn(array $r): float => (float)($r['verify'][0][0]['kept'] ?? -1);
$r = cp_run('admin/booking-details.php', ['anon' => false, 'setup' => $adm, 'vars' => $vars, 'get' => ['id' => '{p}'], 'post' => ['lower_cancel_fee' => '1', 'new_fee' => '1500', 'fee_reason' => 'Rebooked'], 'verify' => $lowerVerify($A . 'P')]);
ok('booking details: lower a kept fee 5,000 -> 1,500 with a reason (admin): saved, History row written', $near($kept($r), 1500) && (int)($r['verify'][1][0]['n'] ?? 0) === 1, json_encode($r['verify'] ?? $r));
$r = cp_run('admin/booking-details.php', ['anon' => false, 'setup' => $adm, 'vars' => $vars, 'get' => ['id' => '{p}'], 'post' => ['lower_cancel_fee' => '1', 'new_fee' => '6000', 'fee_reason' => 'x'], 'verify' => $lowerVerify($A . 'P')]);
ok('booking details: raising the fee is refused', $near($kept($r), 5000) && (int)($r['verify'][1][0]['n'] ?? 1) === 0, json_encode($r['verify'] ?? []));
$r = cp_run('admin/booking-details.php', ['anon' => false, 'setup' => $adm, 'vars' => $vars, 'get' => ['id' => '{p}'], 'post' => ['lower_cancel_fee' => '1', 'new_fee' => '100', 'fee_reason' => ''], 'verify' => $lowerVerify($A . 'P')]);
ok('booking details: lowering without a reason is refused', $near($kept($r), 5000), json_encode($r['verify'] ?? []));
$r = cp_run('admin/booking-details.php', ['anon' => false, 'setup' => $adm, 'vars' => $vars, 'get' => ['id' => '{l}'], 'post' => ['lower_cancel_fee' => '1', 'new_fee' => '0', 'fee_reason' => 'x'], 'verify' => $lowerVerify($A . 'L')]);
ok('booking details: a booking without a policy cannot be lowered here (not built for legacy fees)', $near($kept($r), 5000), json_encode($r['verify'] ?? []));
$noPermUser = (string)$pdo->query("SELECT u.username FROM admin_users u WHERE u.is_active = 1 AND u.role NOT IN ('admin','manager') ORDER BY u.id LIMIT 1")->fetchColumn();
if ($noPermUser !== '' && !hasPermission((int)$pdo->query("SELECT id FROM admin_users WHERE username = " . $pdo->quote($noPermUser))->fetchColumn(), 'waive_cancellation_fee')) {
    $r = cp_run('admin/booking-details.php', ['anon' => false, 'as_username' => $noPermUser, 'setup' => $adm, 'vars' => $vars, 'get' => ['id' => '{p}'], 'post' => ['lower_cancel_fee' => '1', 'new_fee' => '0', 'fee_reason' => 'x'], 'verify' => $lowerVerify($A . 'P')]);
    ok('booking details: a user without "Lower or waive cancellation fees" is refused', $near($kept($r), 5000), json_encode($r['verify'] ?? []));
} else {
    echo "[SKIP] no active user without the permission to try the refusal with\n";
}
$r = cp_run('admin/booking-details.php', ['anon' => false, 'method' => 'GET', 'setup' => $adm, 'vars' => $vars, 'get' => ['id' => '{p}']]);
ok('booking details (policy booking): "Cancellation policy (locked at booking, v3)" panel with the stored text and the lower-fee form', strpos($r['html'], 'locked at booking, v3') !== false
    && strpos($r['html'], 'Cancelled less than 30 days before arrival') !== false && strpos($r['html'], 'name="lower_cancel_fee"') !== false);
$r = cp_run('admin/booking-details.php', ['anon' => false, 'method' => 'GET', 'setup' => $adm, 'vars' => $vars, 'get' => ['id' => '{l}']]);
ok('booking details (no policy): no policy panel, no lower-fee form', strpos($r['html'], 'locked at booking') === false && strpos($r['html'], 'name="lower_cancel_fee"') === false);
$Q = $prefix . 'Q';
$qSetup = [$row($Q . 'P', 10, false, $snapJson), $row($Q . 'L', 10, false, null)];
$qVars = ['p' => "SELECT id FROM bookings WHERE booking_reference = " . $q($Q . 'P'), 'l' => "SELECT id FROM bookings WHERE booking_reference = " . $q($Q . 'L')];
$r = cp_run('admin/bookings.php', ['anon' => false, 'method' => 'GET', 'ajax' => true, 'setup' => $qSetup, 'vars' => $qVars, 'get' => ['action' => 'cancel_quote', 'id' => '{p}']]);
ok('bookings list cancel_quote (policy booking): policy true, fee 5,000 (50%)', !empty($r['json']['policy']) && $near($r['json']['single']['fee'] ?? -1, 5000) && $near($r['json']['single']['pct'] ?? -1, 50), json_encode($r['json'] ?? $r['raw'] ?? ''));
$r = cp_run('admin/bookings.php', ['anon' => false, 'method' => 'GET', 'ajax' => true, 'setup' => $qSetup, 'vars' => $qVars, 'get' => ['action' => 'cancel_quote', 'id' => '{l}']]);
ok('bookings list cancel_quote (no policy): policy false (the dialog shows nothing new)', isset($r['json']['policy']) && $r['json']['policy'] === false, json_encode($r['json'] ?? ''));
$r = cp_run('admin/booking-settings.php', ['anon' => false, 'method' => 'GET']);
ok('Hotel Settings: the cancellation policy card renders with its switch, tier rows and preview', strpos($r['html'], 'Use cancellation policies for new bookings') !== false
    && strpos($r['html'], 'name="cp_ind_days[]"') !== false && strpos($r['html'], 'id="cp-preview"') !== false && strpos($r['html'], 'name="save_no_show_refund_policy"') !== false);
// A hotel may already hold some of these rows (a save or migration 085): compare the rows before and after, never assume none.
$cpRowsSql = "SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('cancellation_policy_enabled','cancellation_policy_individual','cancellation_policy_group','cancellation_policy_group_min_rooms','cancellation_policy_note','cancellation_policy_version') ORDER BY setting_key";
$cpRows = static fn($rows): array => is_array($rows) ? array_column($rows, 'setting_value', 'setting_key') : ['error' => $rows];
$cpRowsBefore = $cpRows($pdo->query($cpRowsSql)->fetchAll(PDO::FETCH_ASSOC));
$r = cp_run('admin/booking-settings.php', ['anon' => false, 'post' => ['save_cancellation_policy' => '1', 'cp_enabled' => '1', 'cp_ind_days' => ['0'], 'cp_ind_pct' => ['50'], 'cp_ind_noshow' => '100', 'cp_group_min' => '2', 'cp_note' => ''],
    'verify' => [$cpRowsSql]]);
ok('Hotel Settings: an invalid tier (0 days) is refused and writes nothing', $cpRows($r['verify'][0] ?? null) === $cpRowsBefore, json_encode([$cpRowsBefore, $r['verify'] ?? []]));
$r = cp_run('admin/booking-settings.php', ['anon' => false, 'post' => ['save_cancellation_policy' => '1', 'cp_enabled' => '1', 'cp_ind_days' => ['7', '2'], 'cp_ind_pct' => ['50', '100'], 'cp_ind_noshow' => '100', 'cp_group_min' => '3', 'cp_note' => ''],
    'verify' => ["SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('cancellation_policy_enabled','cancellation_policy_individual','cancellation_policy_group','cancellation_policy_group_min_rooms','cancellation_policy_note','cancellation_policy_version') ORDER BY setting_key"]]);
$saved = array_column(is_array($r['verify'][0] ?? null) ? $r['verify'][0] : [], 'setting_value', 'setting_key');
ok('Hotel Settings: a valid save writes only what changed and bumps the version to 1 (rolled back)', ($saved['cancellation_policy_enabled'] ?? '') === '1' && ($saved['cancellation_policy_version'] ?? '') === '1'
    && ($saved['cancellation_policy_group_min_rooms'] ?? '') === '3' && !isset($saved['cancellation_policy_note']) && !isset($saved['cancellation_policy_group'])
    && rh_cancel_policy_parse($saved['cancellation_policy_individual'] ?? '') === ['tiers' => [['days' => 7, 'pct' => 50.0], ['days' => 2, 'pct' => 100.0]], 'noshow_pct' => 100.0], json_encode($saved));

// Nothing leaked: a fresh connection sees none of the test rows.
$probe = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$left = $probe->prepare("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE 'ZCPT%' OR booking_reference LIKE ? OR guest_email IN (?, 'zz.cp@example.invalid', 'zz.party@example.invalid')");
$left->execute([$prefix . '%', $email]);
ok('no test bookings left behind', (int)$left->fetchColumn() === 0);
$leftS = $cpRows($probe->query($cpRowsSql)->fetchAll(PDO::FETCH_ASSOC));
ok('no cancellation policy setting row written (rows exactly as before)', $leftS === $cpRowsBefore, json_encode([$cpRowsBefore, $leftS]));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
