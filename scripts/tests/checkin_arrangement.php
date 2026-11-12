<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Pay-later check-in arrangement (owner 2026-11-12): the one check-in rule, validateCheckIn().
 * PURE: no database connection at all. The rule's functions are lifted out of config/database.php source and loaded
 * on their own (config/database.php itself is NOT included, so nothing connects). Usage: php scripts/tests/checkin_arrangement.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "[PASS] $n\n"; } else { $fail++; echo "[FAIL] $n" . ($d !== '' ? ": $d" : '') . "\n"; } }

/** Source of one top-level function from a PHP file (brace-matched with the tokenizer, so "{$x}" in strings is safe). */
function lift_function(string $src, string $name): string
{
    $tokens = token_get_all($src);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $name) {
            continue;
        }
        $out = '';
        $depth = 0;
        $opened = false;
        for ($k = $i; $k < $n; $k++) {
            $t = $tokens[$k];
            $text = is_array($t) ? $t[1] : $t;
            $out .= $text;
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $opened = true;
            } elseif ($t === '}') {
                $depth--;
                if ($opened && $depth === 0) {
                    return $out;
                }
            }
        }
    }
    throw new RuntimeException("function {$name} not found");
}

define('BALANCE_TOLERANCE', 0.01); // same value as config/database.php
$src = (string)file_get_contents($root . '/config/database.php');
foreach (['rh_checkin_owes_nothing', 'rh_checkin_payment_gate', 'validateCheckIn', 'getBookingActionErrorMessage'] as $fn) {
    eval(lift_function($src, $fn) . "\n");
}
ok('loaded without a database', !isset($GLOBALS['pdo']) && !function_exists('getSetting'));

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$yesterday = date('Y-m-d', strtotime('-1 day'));
$base = ['status' => 'confirmed', 'check_in_date' => $today, 'amount_due' => 150000, 'amount_paid' => 0, 'total_with_vat' => 150000, 'folio_charges_total' => 0];

// 1. paid / partial
$r = validateCheckIn(['payment_status' => 'paid', 'amount_due' => 0, 'amount_paid' => 150000] + $base);
ok('paid: allowed, not via arrangement', $r['allowed'] && !$r['via_arrangement'], json_encode($r));
$r = validateCheckIn(['payment_status' => 'partial', 'amount_due' => 100000, 'amount_paid' => 50000] + $base);
ok('partial: allowed', $r['allowed'] && !$r['via_arrangement'], json_encode($r));

// 2. unpaid, no arrangement -> blocked with the payment reason
$r = validateCheckIn(['payment_status' => 'unpaid'] + $base);
ok('unpaid: blocked', !$r['allowed'] && strpos($r['reason'], 'partial payment') !== false, json_encode($r));
$msg = getBookingActionErrorMessage('check_in', $r['reason']);
ok('unpaid: staff message says partial payment or Check in before payment', strpos($msg, 'partial payment') !== false && strpos($msg, 'Check in before payment') !== false, $msg);
$r = validateCheckIn(['payment_status' => ''] + $base);
ok('empty payment status: blocked', !$r['allowed'], json_encode($r));

// 3. unpaid + arrangement on -> allowed via arrangement, note carried
$r = validateCheckIn(['payment_status' => 'unpaid', 'checkin_without_payment' => 1, 'checkin_arrangement_note' => ' ICTAM - invoiced monthly '] + $base);
ok('unpaid + arrangement: allowed via arrangement', $r['allowed'] && $r['via_arrangement'], json_encode($r));
ok('unpaid + arrangement: note returned trimmed', $r['arrangement_note'] === 'ICTAM - invoiced monthly', json_encode($r));
$r = validateCheckIn(['payment_status' => 'unpaid', 'checkin_without_payment' => '1'] + $base);
ok('arrangement as string "1" (PDO) counts', $r['allowed'] && $r['via_arrangement'], json_encode($r));
$r = validateCheckIn(['payment_status' => 'unpaid', 'checkin_without_payment' => '0', 'checkin_arrangement_note' => 'old'] + $base);
ok('arrangement "0": blocked', !$r['allowed'], json_encode($r));
$r = validateCheckIn(['payment_status' => 'partial', 'checkin_without_payment' => 1, 'amount_due' => 100000, 'amount_paid' => 50000] + $base);
ok('partial + arrangement: allowed, NOT via arrangement (payment let it through)', $r['allowed'] && !$r['via_arrangement'], json_encode($r));

// 4. nothing owed (house stay priced 0) even if payment_status reads unpaid
$zero = ['payment_status' => 'unpaid', 'amount_due' => 0, 'amount_paid' => 0, 'total_with_vat' => 0, 'total_amount' => 0, 'vat_amount' => 0, 'folio_charges_total' => 0] + $base;
$r = validateCheckIn($zero);
ok('0-total, status unpaid: allowed (nothing owed), not via arrangement', $r['allowed'] && !$r['via_arrangement'], json_encode($r));
$r = validateCheckIn(['amount_due' => 0.004] + $zero);
ok('balance within BALANCE_TOLERANCE: allowed', $r['allowed'], json_encode($r));
$r = validateCheckIn(['amount_due' => 0.5] + $zero);
ok('balance 0.50 above tolerance: blocked', !$r['allowed'], json_encode($r));
$r = validateCheckIn(['amount_due' => 0, 'total_with_vat' => 150000, 'amount_paid' => 0] + $zero);
ok('stale amount_due 0 on an unpaid 150,000 bill: blocked', !$r['allowed'], json_encode($r));
$r = validateCheckIn(['folio_charges_total' => 5000] + $zero);
ok('0 room bill but 5,000 extras unpaid: blocked', !$r['allowed'], json_encode($r));
$r = validateCheckIn(['status' => 'confirmed', 'payment_status' => 'unpaid', 'check_in_date' => $today]);
ok('no money keys at all, unpaid: blocked (unknown is not "nothing owed")', !$r['allowed'], json_encode($r));

// 5. future date / not confirmed: the arrangement never overrides these
$r = validateCheckIn(['payment_status' => 'unpaid', 'checkin_without_payment' => 1, 'check_in_date' => $tomorrow] + $base);
ok('arrangement but arrival tomorrow: blocked on date', !$r['allowed'] && strpos($r['reason'], 'not been reached') !== false, json_encode($r));
$r = validateCheckIn(['payment_status' => 'unpaid', 'checkin_without_payment' => 1, 'check_in_date' => $yesterday] + $base);
ok('arrangement, late arrival (yesterday): allowed', $r['allowed'] && $r['via_arrangement'], json_encode($r));
foreach (['pending', 'tentative', 'checked-in', 'cancelled'] as $st) {
    $r = validateCheckIn(['status' => $st, 'payment_status' => 'unpaid', 'checkin_without_payment' => 1] + $base);
    ok("arrangement but status {$st}: blocked", !$r['allowed'] && !$r['via_arrangement'], json_encode($r));
}

// 6. missing key = off (callers that do not select the column keep today's behaviour)
$r = validateCheckIn(['payment_status' => 'unpaid'] + $base);
ok('missing checkin_without_payment key: arrangement off', !$r['allowed'], json_encode($r));
$r = validateCheckIn(['payment_status' => 'paid', 'status' => 'confirmed', 'check_in_date' => $today]);
ok('old callers (status/payment_status/date only): paid still allowed', $r['allowed'] && $r['via_arrangement'] === false, json_encode($r));
$r = validateCheckIn(['status' => 'confirmed', 'check_in_date' => $today]);
ok('missing payment_status: blocked as before', !$r['allowed'] && strpos($r['reason'], 'Missing required field') !== false, json_encode($r));

// 7. payment gate labels used by the screens
ok('gate paid', rh_checkin_payment_gate(['payment_status' => 'partial']) === 'paid');
ok('gate nothing_owed', rh_checkin_payment_gate($zero) === 'nothing_owed');
ok('gate arrangement', rh_checkin_payment_gate(['payment_status' => 'unpaid', 'checkin_without_payment' => 1] + $base) === 'arrangement');
ok('gate blocked', rh_checkin_payment_gate(['payment_status' => 'unpaid'] + $base) === 'blocked');

// 8. automatic payment chasers leave approved arrangements alone (includes/auto-email-jobs.php)
eval(lift_function($src, 'rh_checkin_arrangement_exclude_sql') . "\n");
ok('exclude SQL: columns missing -> nothing appended (today\'s behaviour)', rh_checkin_arrangement_exclude_sql('b', false) === '');
ok('exclude SQL: columns present -> leaves out arrangements (own switch, and a group member whose primary has it)', rh_checkin_arrangement_exclude_sql('b', true)
    === ' AND COALESCE(b.checkin_without_payment, 0) = 0 AND NOT EXISTS (SELECT 1 FROM bookings arr_pb WHERE arr_pb.id = b.primary_booking_id AND COALESCE(arr_pb.checkin_without_payment, 0) = 1)');
ok('exclude SQL: alias is sanitised', strpos(rh_checkin_arrangement_exclude_sql("b; DROP", true), ' AND COALESCE(bDROP.checkin_without_payment, 0) = 0 AND NOT EXISTS (SELECT 1 FROM bookings arr_pb WHERE arr_pb.id = bDROP.primary_booking_id') === 0);
$GLOBALS['rh_test_settings'] = ['unpaid_confirmed_release_hours' => 48];
if (!function_exists('getSetting')) { // declared here (not hoisted): in-memory stub, no DB
    function getSetting($k, $d = null) { return $GLOBALS['rh_test_settings'][$k] ?? $d; }
}
eval(lift_function($src, 'rh_unpaid_release_hours') . "\n");
eval(lift_function($src, 'rh_unpaid_confirmed_deadline') . "\n");
$future = ['status' => 'confirmed', 'check_in_date' => date('Y-m-d', strtotime('+5 days')), 'amount_paid' => 0, 'payment_status' => 'unpaid',
    'deposit_paid' => 0, 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')), 'converted_to_confirmed_at' => null];
ok('unpaid confirmed booking gets a pay-by deadline', rh_unpaid_confirmed_deadline($future) !== null);
ok('same booking with the arrangement: no pay-by deadline (no reminder, no auto-release)', rh_unpaid_confirmed_deadline(['checkin_without_payment' => 1] + $future) === null);
ok('arrangement "0": deadline as before', rh_unpaid_confirmed_deadline(['checkin_without_payment' => '0'] + $future) !== null);

// 9. check-in "Email the guest a check-in confirmation" box (includes/email-send-log.php), owner 2026-11-12
$esrc = (string)file_get_contents($root . '/includes/email-send-log.php');
eval(lift_function($esrc, 'rh_cancel_email_choice') . "\n");
eval(lift_function($esrc, 'rh_checkin_email_choice') . "\n");
ok('box ticked (hidden 0 then 1 -> PHP keeps 1): send', rh_checkin_email_choice(['email_guest' => '1'], false) === true);
ok('box unticked (only hidden 0): no email', rh_checkin_email_choice(['email_guest' => '0'], true) === false);
ok('array form: last value wins', rh_checkin_email_choice(['email_guest' => ['0', '1']], false) === true);
ok('no field on the dashboard path (old page / Assistant): sends as today', rh_checkin_email_choice([], true) === true);
ok('no field on booking page / Bookings list: no email as today', rh_checkin_email_choice(['booking_action' => 'checkin'], false) === false);
ok('junk value: no email', rh_checkin_email_choice(['email_guest' => 'yes'], true) === false);

// 10. the migration file is additive and loads without running
$m = require $root . '/admin/migrations/083_booking_checkin_arrangement.php';
ok('migration 083 returns name/check/up', is_array($m) && ($m['name'] ?? '') === 'booking_checkin_arrangement' && is_callable($m['check']) && is_callable($m['up']));
$msrc = (string)file_get_contents($root . '/admin/migrations/083_booking_checkin_arrangement.php');
ok('migration 083 only adds columns', stripos($msrc, 'DROP') === false && stripos($msrc, 'UPDATE ') === false && stripos($msrc, 'DELETE') === false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
