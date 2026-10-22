<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Cache logic (config/cache.php): on/off switches, per-type switches, clear helpers, file names, protection.
 * Usage (from the repo root): php scripts/tests/cache_logic.php
 * Switches are changed inside a transaction that is rolled back; the only files written are two
 * "zz_" cache entries and a scratch folder, all removed before the end.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/config/database.php';
require_once $root . '/config/cache.php';
require_once $root . '/config/page-cache.php';

// ---- Which switch governs which key ----
$map = ['email_setting_smtp_host' => 'email', 'booking_email_template_x' => 'email', 'setting_site_name' => 'settings', 'settings_group_a' => 'settings',
    'rooms_abc' => 'rooms', 'room_1' => 'rooms', 'facilities_x' => 'rooms', 'gallery_images' => 'rooms', 'page_hero_home' => 'rooms',
    'table_email_settings' => 'tables', 'testimonials_6' => 'content', 'policies' => 'content', 'about_us' => 'content',
    'footer_links' => 'settings', 'iprl_x' => 'settings'];
foreach ($map as $k => $t) { check(cacheTypeForKey($k) === $t, "key '$k' is governed by the '$t' switch", cacheTypeForKey($k)); }
check(cacheTypeForKey('rooms_x', 'ratelimit') === 'ratelimit', 'an explicit type is kept');

// ---- Cache file names cannot be steered ----
foreach (['../../etc/passwd', '..\\..\\config\\database.php', "a\0b", str_repeat('k', 400), 'a/b/c'] as $k) {
    $f = getReadableCacheFilename($k);
    check((bool)preg_match('/^[A-Za-z0-9_-]{1,150}_[0-9a-f]{8}\.cache$/', $f), 'cache file name for ' . json_encode(substr($k, 0, 20)) . ' is a plain name', $f);
}

// ---- Switches really bypass reads and writes ----
$k1 = 'zz_cachetest_' . bin2hex(random_bytes(3));
$k2 = 'email_setting_zz_cachetest_' . bin2hex(random_bytes(3));
$put = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
$pdo->beginTransaction();
try {
    $put->execute(['cache_global_enabled', '1']);
    $put->execute(['cache_email_enabled', '1']);
    $put->execute(['cache_settings_enabled', '1']);
    rhCacheFlagsReset();
    check(isCacheEnabled('settings') && isCacheEnabled('email'), 'with every switch on, caching is on');
    check(setCache($k1, ['v' => 1], 60) === true && getCache($k1) === ['v' => 1], 'a write then a read works when on');
    check(is_file(CACHE_DIR . '/.htaccess'), 'cache folder has a deny-all .htaccess');

    $put->execute(['cache_email_enabled', '0']);
    rhCacheFlagsReset();
    check(setCache($k2, 'secret', 60) === false && getCache($k2, 'dflt') === 'dflt', 'Email switch off: email_* keys are neither written nor read');
    check(getCache($k1) === ['v' => 1], 'Email switch off does not affect other cache keys');

    $put->execute(['cache_global_enabled', '0']);
    rhCacheFlagsReset();
    check(getCache($k1, 'dflt') === 'dflt' && setCache($k1, ['v' => 2], 60) === false, 'global switch off: an existing entry is not served and nothing is written');
    $put->execute(['cache_global_enabled', '1']);
    rhCacheFlagsReset();
    check(getCache($k1) === ['v' => 1], 'switching back on: the entry (not overwritten while off) is served again');
} finally {
    $pdo->rollBack();
    rhCacheFlagsReset();
    deleteCache($k1);
    deleteCache($k2);
}

// ---- Clear helpers ----
global $_SITE_SETTINGS;
$_SITE_SETTINGS = ['zz_key' => 'old'];
clearSettingsCache();
check($_SITE_SETTINGS === [], 'clearSettingsCache also empties the in-memory settings copy');

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_cache_test_' . bin2hex(random_bytes(4));
mkdir($tmp . '/sub', 0777, true);
file_put_contents($tmp . '/a.cache', 'x'); file_put_contents($tmp . '/sub/b.html', 'y'); file_put_contents($tmp . '/sub/c.html', 'z'); file_put_contents($tmp . '/.htaccess', 'keep');
$n = emptyDirectory($tmp);
check($n === 3, 'emptyDirectory counts the files it removed (including inside sub-folders)', (string)$n);
check(is_file($tmp . '/.htaccess') && !file_exists($tmp . '/a.cache') && !is_dir($tmp . '/sub'), 'emptyDirectory keeps the .htaccess and the folder itself');
@unlink($tmp . '/.htaccess'); @rmdir($tmp);

// ---- Web exposure ----
$ht = (string)file_get_contents($root . '/.htaccess');
check(preg_match('/FilesMatch "\(\?i\)\\\\\.\([^"]*\|cache\|/', $ht) === 1, 'root .htaccess denies *.cache files');
check(preg_match('/RewriteRule \^\(invoices\|quotations\|cache\)\(\/\|\$\) - \[F,L\]/', $ht) === 1, 'root .htaccess denies the /cache/ folder');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
