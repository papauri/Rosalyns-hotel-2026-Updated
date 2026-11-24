<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * High-value settings lock (owner 2026-11-25): VAT, bank details, tourism levy, service charge need the user's own
 * password again (includes/settings-lock.php). Run from inside the Liwonde repo: php scripts/tests/settings_lock_test.php
 * In-process part: one transaction, rolled back (the temporary test admin, log rows and settings all go with it).
 * Page part: page_harness.php (its own rolled-back transaction per page). Nothing is left behind.
 */
$root = getcwd();
require_once $root . '/config/database.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/hotel-details-settings.php';
require_once $root . '/includes/settings-lock.php';
require_once $root . '/includes/settings-save-changed.php';
if (!function_exists('rh_log_event') && is_file($root . '/includes/system-logger.php')) { require_once $root . '/includes/system-logger.php'; }

$ok = 0; $bad = 0;
$t = function (bool $c, string $n, string $d = '') use (&$ok, &$bad) { echo ($c ? '  PASS  ' : '  FAIL  ') . $n . ($c || $d === '' ? '' : "  $d") . "\n"; $c ? $ok++ : $bad++; };
$PW = 'Zz-lock-test-' . bin2hex(random_bytes(6)); // the temporary admin's random password: must never reach a log allow-secret
$now = time();
$touched = ['check_in_time', 'bank_name', 'bank_account_number', 'restaurant_service_charge_pct', 'restaurant_tagline'];
$setting = function (string $k) use ($pdo) {
    $s = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string)$v;
};
$beforeAll = [];
foreach (array_merge($touched, ['vat_enabled', 'vat_rate', 'vat_number', 'vat_pricing_mode', 'tourism_levy_enabled', 'tourism_levy_percent']) as $k) { $beforeAll[$k] = $setting($k); }

echo "== Unlock (in-process, rolled back) ==\n";
$maxLog = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM system_event_log')->fetchColumn();
$tmpId = 0;
$pdo->beginTransaction();
try {
    $uname = 'zz_locktest_' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, 'Lock Test', 'admin', 1)")
        ->execute([$uname, $uname . '@example.invalid', password_hash($PW, PASSWORD_DEFAULT)]);
    $tmpId = (int)$pdo->lastInsertId();
    $staff = (int)$pdo->query("SELECT id FROM admin_users WHERE role NOT IN ('admin','manager','accountant') AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $t($tmpId > 0 && hasPermission($tmpId, 'finance_settings'), 'temporary test admin has finance_settings');

    $_SESSION = [];
    [$r1, $m1] = rh_settings_lock_unlock($pdo, $tmpId, 'vat', 'wrong-' . $PW, $now);
    $t(!$r1 && stripos($m1, 'not correct') !== false && !rh_settings_lock_is_open('vat', $tmpId, $now), 'wrong password refused, still locked', $m1);
    [$r2, $m2] = rh_settings_lock_unlock($pdo, $tmpId, 'vat', '', $now);
    $t(!$r2 && stripos($m2, 'enter your own password') !== false, 'empty password refused', $m2);
    [$r3, $m3] = rh_settings_lock_unlock($pdo, $tmpId, 'nonsense', $PW, $now);
    $t(!$r3 && !isset($_SESSION['rh_settings_lock']['nonsense']), 'unknown group refused', $m3);
    if ($staff) {
        [$r4, $m4] = rh_settings_lock_unlock($pdo, $staff, 'bank', $PW, $now);
        $t(!$r4 && stripos($m4, 'permission') !== false, 'user without finance_settings refused', $m4);
    }

    $_SESSION = [];
    [$r5, $m5, $ttl] = rh_settings_lock_unlock($pdo, $tmpId, 'bank', $PW, $now);
    $t($r5 && $ttl === 600 && rh_settings_lock_is_open('bank', $tmpId, $now), 'right password opens the bank group for 10 minutes', $m5);
    $t(!rh_settings_lock_is_open('vat', $tmpId, $now) && !rh_settings_lock_is_open('levy', $tmpId, $now) && !rh_settings_lock_is_open('service_charge', $tmpId, $now), 'a bank unlock does not open vat, levy or service charge');
    $t(!rh_settings_lock_is_open('bank', $tmpId + 1000000, $now), 'token belongs to that user only');
    $t(rh_settings_lock_is_open('bank', $tmpId, $now + 599) && !rh_settings_lock_is_open('bank', $tmpId, $now + 601), 'token expires after 10 minutes');

    // Rate limit: 5 wrong passwords in 15 minutes, then even the right one is refused until the window passes.
    $_SESSION = [];
    $hint = '';
    for ($i = 0; $i < 5; $i++) { [, $hint] = rh_settings_lock_unlock($pdo, $tmpId, 'vat', 'bad' . $i, $now + $i); }
    [$r6, $m6] = rh_settings_lock_unlock($pdo, $tmpId, 'vat', $PW, $now + 10);
    $t(!$r6 && stripos($m6, 'too many') !== false && !rh_settings_lock_is_open('vat', $tmpId, $now + 10), 'rate limit after 5 wrong passwords (right one refused too)', $m6);
    [$r7] = rh_settings_lock_unlock($pdo, $tmpId, 'vat', $PW, $now + 901);
    $t($r7 && rh_settings_lock_recent_fails($now + 901) === [], 'allowed again after 15 minutes, fails cleared');

    echo "== Hotel details save (in-process, rolled back) ==\n";
    $admin = ['id' => $tmpId, 'username' => $uname];
    $_SESSION = [];
    $ciBefore = (string)getSetting('check_in_time');
    [$msg, $err] = rh_hotel_details_save(['check_in_time' => '15:00', 'bank_name' => 'ZZ LOCK BANK'], $admin);
    $t($msg === '' && stripos($err, 'unlock the bank account details') !== false, 'save with a bank field and no unlock refused', $err);
    $t($setting('bank_name') === $beforeAll['bank_name'] && (string)getSetting('check_in_time') === $ciBefore, 'refused save changed nothing (not even the unlocked field)');
    [$msg, $err] = rh_hotel_details_save(['restaurant_service_charge_pct' => '3'], $admin);
    $t(stripos($err, 'unlock the restaurant service charge') !== false && $setting('restaurant_service_charge_pct') === $beforeAll['restaurant_service_charge_pct'], 'service charge without unlock refused, unchanged', $err);
    [$msg, $err] = rh_hotel_details_save(['restaurant_tagline' => 'ZZ lock tagline', '_settings_base' => json_encode(rh_settings_base_map($pdo, rh_hotel_details_base_spec()))], $admin); // the page's opened-with copy (task 19)
    $t($err === '' && $setting('restaurant_tagline') === 'ZZ lock tagline', 'normal save of unlocked fields works', $err);

    $_SESSION['rh_settings_lock'] = ['bank' => ['uid' => $tmpId, 'exp' => time() - 1]];
    [$msg, $err] = rh_hotel_details_save(['bank_name' => 'ZZ LOCK BANK'], $admin);
    $t(stripos($err, 'unlock the bank') !== false && $setting('bank_name') === $beforeAll['bank_name'], 'expired token refused', $err);

    $_SESSION['rh_settings_lock'] = ['vat' => ['uid' => $tmpId, 'exp' => time() + 600]];
    [$msg, $err] = rh_hotel_details_save(['bank_name' => 'ZZ LOCK BANK'], $admin);
    $t(stripos($err, 'unlock the bank') !== false, 'a vat token does not unlock bank fields', $err);

    $_SESSION['rh_settings_lock'] = ['bank' => ['uid' => $tmpId, 'exp' => time() + 600]];
    [$msg, $err] = rh_hotel_details_save(['bank_account_number' => 'not valid <x>', '_settings_base' => json_encode(rh_settings_base_map($pdo, rh_hotel_details_base_spec()))], $admin);
    $t($err !== '' && rh_settings_lock_is_open('bank', $tmpId), 'validation error keeps the unlock (fix and save again)', $err);
    [$msg, $err] = rh_hotel_details_save(['bank_name' => 'ZZ LOCK BANK', '_settings_base' => json_encode(rh_settings_base_map($pdo, rh_hotel_details_base_spec()))], $admin);
    $t($err === '' && $setting('bank_name') === 'ZZ LOCK BANK', 'unlocked bank save works', $err . $msg);
    $t(!rh_settings_lock_is_open('bank', $tmpId), 'token used up after one save');
    [$msg, $err] = rh_hotel_details_save(['bank_name' => 'ZZ LOCK BANK 2'], $admin);
    $t(stripos($err, 'unlock the bank') !== false && $setting('bank_name') === 'ZZ LOCK BANK', 'second save needs a new unlock', $err);

    // The password never reaches the system log (rows written in this transaction).
    $logs = $pdo->query('SELECT source, message, context_json FROM system_event_log WHERE id > ' . $maxLog)->fetchAll(PDO::FETCH_ASSOC);
    $lockRows = array_filter($logs, static fn($r) => strpos((string)$r['source'], 'settings') !== false);
    $leak = false;
    foreach ($logs as $row) { if (strpos(implode(' ', array_map('strval', $row)), $PW) !== false) { $leak = true; } }
    $t(count($lockRows) > 0, 'unlock attempts are logged (' . count($lockRows) . ' rows)');
    $t(!$leak, 'the password never appears in the log');
    $errLog = (string)ini_get('error_log');
    $t($errLog === '' || !is_file($errLog) || strpos((string)@file_get_contents($errLog, false, null, max(0, (int)@filesize($errLog) - 200000)), $PW) === false, 'the password never appears in the PHP error log');
} finally {
    $pdo->rollBack();
    $_SESSION = [];
}
// Safety net: the temporary admin must be gone after the rollback.
if ($tmpId > 0) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE id = ? AND username LIKE 'zz\\_locktest\\_%'");
    $s->execute([$tmpId]);
    if ((int)$s->fetchColumn() > 0) { $pdo->prepare("DELETE FROM admin_users WHERE id = ? AND username LIKE 'zz\\_locktest\\_%'")->execute([$tmpId]); }
    $s->execute([$tmpId]);
    $t((int)$s->fetchColumn() === 0, 'temporary test admin removed');
}

echo "== Pages (page_harness, rolled back) ==\n";
$h = __DIR__ . '/page_harness.php';
function lock_run_page(string $h, string $page, array $post, array $verify = [], array $grep = [], array $session = [], array $sessionKeys = []): array {
    $cfg = ['post' => $post, 'ajax' => false, 'verify' => $verify, 'grep' => $grep];
    if ($session) { $cfg['session'] = $session; }
    if ($sessionKeys) { $cfg['session_keys'] = $sessionKeys; }
    $cmd = 'php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1';
    $out = shell_exec($cmd);
    if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -400), 'rolled_back' => false];
}
$sv = fn(array $keys) => ["SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('" . implode("','", $keys) . "') ORDER BY setting_key"];
$asMap = function ($rows) { $o = []; foreach ((array)$rows as $r) { if (is_array($r)) { $o[$r['setting_key']] = (string)$r['setting_value']; } } return $o; };
$pick = function (array $keys) use ($beforeAll) { $o = []; foreach ($keys as $k) { if ($beforeAll[$k] !== null) { $o[$k] = $beforeAll[$k]; } } ksort($o); return $o; };
$harnessAdmin = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();

$levyKeys = ['tourism_levy_enabled', 'tourism_levy_percent'];
$newPct = ((string)$beforeAll['tourism_levy_percent'] === '9.99') ? '8.88' : '9.99';
$r = lock_run_page($h, 'admin/booking-settings.php', ['tourism_levy_settings' => '1', 'tourism_levy_percent' => $newPct], $sv($levyKeys));
$t(strpos(strtolower(implode(' ', $r['output_flash'] ?? [])), 'unlock the tourism levy') !== false && $asMap($r['verify'][0] ?? []) === $pick($levyKeys), 'levy post without unlock: enabled and percent both unchanged', json_encode($r['output_flash'] ?? $r));
$t(!empty($r['rolled_back']), 'levy page rolled back');

$vatKeys = ['vat_enabled', 'vat_number', 'vat_pricing_mode', 'vat_rate'];
$vatPost = ['save_vat_settings' => '1', 'vat_mode' => 'exclusive', 'vat_rate' => '33', 'vat_number' => 'ZZ-LOCK'];
$r = lock_run_page($h, 'admin/accounting-dashboard.php', $vatPost, $sv($vatKeys), ['Unlock the VAT settings with your password first']);
$t(!empty($r['found']['Unlock the VAT settings with your password first']) && $asMap($r['verify'][0] ?? []) === $pick($vatKeys), 'VAT post without unlock: refused, nothing changed', json_encode($r['verify'] ?? $r));
$r = lock_run_page($h, 'admin/accounting-dashboard.php', $vatPost, $sv($vatKeys), [], ['rh_settings_lock' => ['bank' => ['uid' => $harnessAdmin, 'exp' => time() + 600]]]);
$t($asMap($r['verify'][0] ?? []) === $pick($vatKeys), 'VAT post with only a bank unlock: nothing changed', json_encode($r['verify'] ?? $r));
$r = lock_run_page($h, 'admin/accounting-dashboard.php', $vatPost, $sv($vatKeys), [], ['rh_settings_lock' => ['vat' => ['uid' => $harnessAdmin, 'exp' => time() + 600]]], ['rh_settings_lock']);
$got = $asMap($r['verify'][0] ?? []);
$t(($got['vat_rate'] ?? '') === '33' && ($got['vat_number'] ?? '') === 'ZZ-LOCK' && empty($r['session']['rh_settings_lock']['vat']), 'VAT post with a vat unlock saves, token used up (rolled back)', json_encode([$got, $r['session'] ?? null]));
$t(!empty($r['rolled_back']), 'VAT page rolled back');

$r = lock_run_page($h, 'admin/api/settings-unlock.php', ['group' => 'vat', 'password' => 'not-the-password-' . bin2hex(random_bytes(3))], [], [], [], ['rh_settings_lock']); // random wrong password allow-secret
$t(isset($r['json']) && empty($r['json']['success']) && empty($r['session']['rh_settings_lock']['vat']), 'unlock endpoint: wrong password refused, nothing opened', json_encode($r['json'] ?? ($r['raw'] ?? $r)));

// Settings cache may hold rolled-back values: clear the keys touched, then confirm the stored values are as before.
foreach (array_keys($beforeAll) as $k) {
    if (function_exists('deleteCache')) { deleteCache("setting_$k"); }
    foreach (glob("cache/setting_{$k}_*.cache") ?: [] as $cf) { @unlink($cf); }
}
$after = [];
foreach (array_keys($beforeAll) as $k) { $after[$k] = $setting($k); }
$t($after === $beforeAll, 'every setting touched is back to its value before the test');
echo "RESULT: $ok passed, $bad failed\n";
exit($bad ? 1 : 0);
