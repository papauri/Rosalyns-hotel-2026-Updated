<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Hotel Settings change log: includes/settings-change-log.php (context, mask, truncation, labels, parse) and how
 * System Logs > Staff activity reads 'setting_change' rows (admin/includes/staff-activity-lib.php).
 * Usage (from the repo root): php scripts/tests/settings_change_log.php
 * Pure: no database. Rows go to a test sink instead of admin_activity_log.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/includes/settings-change-log.php';
require_once $root . '/admin/includes/hotel-details-settings.php'; // registry labels + mirrors (functions only)
require_once $root . '/admin/includes/staff-activity-lib.php';

$rows = [];
$sink = function (array $row, string $store) use (&$rows) { $rows[] = ['row' => $row, 'store' => $store]; };

// ---- Context off: nothing happens ----
rh_settings_log_end();
check(!rh_settings_log_on(), 'context starts off');
check(rh_settings_log_record('site_settings', 'tourism_levy_percent', '1', '1.5') === 'off', 'context off: record does nothing');
check(rh_settings_log_old(null, 'site_settings', 'tourism_levy_percent') === null, 'context off: no old-value read');

// ---- Context on: only real changes ----
rh_settings_log_begin(['id' => 42, 'username' => 'mercy'], $sink);
check(rh_settings_log_on(), 'begin switches the context on');
check(rh_settings_log_record('site_settings', 'tourism_levy_percent', '1.5', '1.5') === 'same' && !$rows, 'old == new: no row');
check(rh_settings_log_record('site_settings', 'tourism_levy_percent', ' 1.5 ', '1.5') === 'same' && !$rows, 'compared as trimmed strings');
check(rh_settings_log_record('site_settings', 'tourism_levy_percent', '1', 1.5) === 'written' && count($rows) === 1, 'a change writes one row');
$r = $rows[0]['row'] ?? [];
check(($r[0] ?? null) === 42 && ($r[1] ?? '') === 'mercy' && ($r[2] ?? '') === 'setting_change', 'row: user id, username, action setting_change');
check(($r[3] ?? '') === 'key=tourism_levy_percent; label=Tourism levy %; old=1; new=1.5', 'details text', (string)($r[3] ?? ''));
check(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($r[5] ?? '')) === 1, 'created_at is a PHP time');
check(rh_settings_log_record('site_settings', 'tourism_levy_enabled', '', '1') === 'written', 'missing row (blank) -> 1 is a change');

// ---- Truncation and one line ----
$long = str_repeat('abcdefghij', 30);
$d = rh_settings_log_details('document_terms_text', "line one\nline two", $long);
$p = rh_settings_log_parse((string)$d);
check($p !== null && $p['old'] === 'line one line two', 'newlines become spaces', (string)$d);
check($p !== null && mb_strlen($p['new']) === 120 && substr($p['new'], -3) === '...', 'values cut at 120 characters with ...', (string)($p['new'] ?? ''));
check(rh_settings_log_clip('  a   b  ') === 'a b', 'clip: trimmed, spaces folded');

// ---- Secrets: exactly these keys are hidden, every other key Hotel Settings writes shows its values ----
$keys = array_keys(rh_settings_log_label_map());
foreach (rh_hotel_details_fields() as $fields) {
    $keys = array_merge($keys, array_keys($fields));
}
$hidden = [];
foreach (array_unique($keys) as $k) {
    $dd = rh_settings_log_details($k, 'a1', 'b2');
    $pp = $dd !== null ? rh_settings_log_parse($dd) : null;
    if ($pp !== null && $pp['hidden']) {
        $hidden[] = $k;
    } elseif ($pp === null || ($k !== 'bank_account_number' && ($pp['old'] !== 'a1' || $pp['new'] !== 'b2'))) {
        bad('mask: ' . $k . ' shows its values', (string)$dd);
    }
}
sort($hidden);
check($hidden === ['reviews_brave_search_key', 'reviews_google_places_key', 'smtp_password', 'smtp_username'], 'mask: hidden keys are exactly the passwords, SMTP user and API keys', implode(',', $hidden));
check(rh_settings_log_sentence((string)rh_settings_log_details('smtp_password', 'x', 'y')) === 'Changed Email server password (value hidden)', 'hidden sentence never has a value');
check(strpos((string)rh_settings_log_details('reviews_google_place_id', 'rk1:QUJD', 'rk1:REVG'), 'rk1:') === false, 'mask: an rk1: encrypted value is hidden whatever the key');
check(strpos((string)rh_settings_log_details('email_from_name', 'Old', 'New', true), 'Old') === false, 'mask: is_encrypted hides the value');
check(rh_settings_log_details('smtp_password', '', '') === null, 'a blank secret that stays blank is no change');
check(rh_settings_log_details('smtp_username', 'mail@x.invalid', 'mail@x.invalid') === null, 'a plain stored secret saved unchanged is no change');
check(rh_settings_log_details('smtp_password', 'same', 'same', true) !== null, 'an encrypted secret saved again reads "changed" (cannot be compared)');
check(rh_settings_log_details('reviews_google_places_key', 'rk1:QUJD', 'rk1:QUJD') !== null, 'an rk1: value saved again reads "changed" (cannot be compared)');
foreach (['some_api_key', 'apikey', 'x_token', 'client_secret', 'smtp_pass', 'db_password'] as $sk) {
    check(rh_settings_log_secret_key($sk), 'mask by name: ' . $sk);
}
foreach (['smtp_secure', 'booking_reference_prefix', 'passport_note', 'monkey_count', 'site_url'] as $nk) {
    check(!rh_settings_log_secret_key($nk), 'not a secret: ' . $nk);
}
$bank = rh_settings_log_parse((string)rh_settings_log_details('bank_account_number', '1234567890', '9876543210'));
check($bank !== null && $bank['old'] === '******7890' && $bank['new'] === '******3210', 'bank account number keeps the last 4 digits only');

// ---- Side-effect keys never log ----
foreach (['reviews_google_calls_202611', 'scheduler_last_run', 'scheduler_job_last_x', 'last_backup_at', 'child_guest_price_multiplier'] as $ik) {
    check(rh_settings_log_record('site_settings', $ik, '1', '2') === 'same', 'ignored: ' . $ik);
}
check(rh_settings_log_record('site_settings', 'booking_child_price_multiplier', '50', '40') === 'written', 'the child price itself logs once (its mirror is ignored)');

// ---- Labels ----
check(rh_settings_log_label('tourism_levy_percent') === 'Tourism levy %', 'label from the map');
check(rh_settings_log_label('check_in_time') === 'Check-in from', 'label from the Hotel details registry');
check(rh_settings_log_label('some_new_setting') === 'Some new setting', 'label fallback: underscores to spaces, first letter capital');

// ---- Parse: values with ; and = ----
$dd = rh_settings_log_details('site_maintenance_message', 'Back soon; a=b', 'Closed; x=y; new=z');
$pp = rh_settings_log_parse((string)$dd);
check($pp !== null && $pp['key'] === 'site_maintenance_message' && $pp['old'] === 'Back soon; a=b' && $pp['new'] === 'Closed; x=y; new=z', 'parse: values with ; and =', (string)$dd);
$pp = rh_settings_log_parse((string)rh_settings_log_details('document_terms_text', 'Odd; new=old text', 'Plain'));
check($pp !== null && $pp['old'] === 'Odd, new=old text' && $pp['new'] === 'Plain', 'parse: "; new=" inside the old value cannot split it wrongly');
check(rh_settings_log_parse('page=booking-settings.php') === null, 'parse: a page_action text is not a change');
check(rh_settings_log_sentence((string)rh_settings_log_details('tourism_levy_percent', '1', '1.5')) === 'Changed Tourism levy % from 1 to 1.5', 'sentence');
check(rh_settings_log_sentence((string)rh_settings_log_details('booking_notification_cc_emails', '', 'a@x.com')) === 'Changed Booking alert copies (CC) from (blank) to a@x.com', 'sentence: blank before');

// ---- Never throws ----
rh_settings_log_begin(['id' => 42, 'username' => 'mercy'], function () { throw new RuntimeException('boom'); });
check(rh_settings_log_record('site_settings', 'gym_email', 'a@x.com', 'b@x.com') === 'failed', 'a failing write returns failed, never throws');

// ---- Fresh context and end ----
$rows = [];
rh_settings_log_begin(['id' => 7, 'username' => 'second'], $sink);
rh_settings_log_record('site_settings', 'gym_email', 'a@x.com', 'b@x.com');
check(($rows[0]['row'][1] ?? '') === 'second', 'begin always starts fresh (no older user or sink)');
rh_settings_log_end();
rh_settings_log_end();
check(!rh_settings_log_on() && rh_settings_log_record('site_settings', 'gym_email', 'b@x.com', 'c@x.com') === 'off' && count($rows) === 1, 'end switches it off (twice is safe); nothing after it');

// ---- Staff activity reads the rows ----
check((rh_sa_types()['setting_changes'] ?? '') === 'Hotel Settings changes', 'Staff activity type "Hotel Settings changes"');
check(rh_sa_action_type('setting_change') === 'setting_changes' && rh_sa_action_type('settings_saved') === 'settings', 'setting_change rows get their own type');
foreach (rh_sa_action_prefixes() as $t => $re) {
    check(preg_match('/^\^\([^()]+\)$/', $re) === 1, 'prefix shape ^(...) for ' . $t);
}
[$oSql, $oArgs] = rh_sa_activity_type_sql('other');
check(strpos((string)($oArgs[0] ?? ''), '|setting_change|') !== false || strpos((string)($oArgs[0] ?? ''), '^(setting_change|') === 0, '"Other" excludes setting_change rows', (string)($oArgs[0] ?? ''));
[$tSql, $tArgs] = rh_sa_activity_type_sql('setting_changes');
check(strpos($tSql, 'REGEXP') !== false && ($tArgs[0] ?? '') === '^(setting_change)', 'type filter Hotel Settings changes');
$desc = rh_sa_describe('activity', ['_ts' => '2026-11-14 10:00:00', '_id' => 1, '_uid' => 42, 'action' => 'setting_change',
    'details' => 'key=tourism_levy_percent; label=Tourism levy %; old=1; new=1.5'], [42 => ['name' => 'Mercy', 'username' => 'mercy', 'role' => 'manager']]);
check($desc['type'] === 'setting_changes' && $desc['what'] === 'Changed Tourism levy % from 1 to 1.5' && $desc['where'] === 'Hotel Settings' && !$desc['screen_save'], 'timeline sentence for a setting change', $desc['what']);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
