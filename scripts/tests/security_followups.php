<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Security follow-ups 2026-11-06. Usage (from the repo root): php scripts/tests/security_followups.php
 *  1. includes/secret-salt.php + Facebook / WhatsApp / review-source secrets: APP_ENCRYPTION_SALT is used when set;
 *     values saved under the old built-in salt still decrypt (nothing lost) and are re-encrypted under the real salt
 *     on the next save; with no salt set the old behaviour is kept.
 *  2. admin/document-download.php type=invoice: the 'bookings' permission only opens room payments.
 *  3. api/cancel-order.php: with the till module off, the stock module's order timeline Cancel still works for people
 *     holding pos_void AND stock_orders and nobody else; api/void-order.php is unchanged (its timeline button is admin-only).
 * Reads the live database only for settings lookups; writes nothing (settings are held in memory).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/config/database.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/includes/facebook-functions.php';
require_once $root . '/includes/whatsapp-functions.php';
require_once $root . '/includes/review-sources.php';

$origEnv = array_key_exists('APP_ENCRYPTION_SALT', $_ENV) ? $_ENV['APP_ENCRYPTION_SALT'] : null;
$origGet = getenv('APP_ENCRYPTION_SALT');
$setSalt = static function (?string $v): void {
    if ($v === null) { unset($_ENV['APP_ENCRYPTION_SALT']); putenv('APP_ENCRYPTION_SALT'); }
    else { $_ENV['APP_ENCRYPTION_SALT'] = $v; putenv('APP_ENCRYPTION_SALT=' . $v); }
};
$legacy = 'DEFAULT_SALT_CHANGE_IN_ENV';
$real = 'sec-test-salt-' . bin2hex(random_bytes(6));
$token = 'EAAB' . bin2hex(random_bytes(20));
global $_SITE_SETTINGS;
$_SITE_SETTINGS = is_array($_SITE_SETTINGS ?? null) ? $_SITE_SETTINGS : [];

// ---- 1. salt helper ----
$setSalt(null);
check(rhConfiguredSecretSalt() === '', 'no salt set: nothing configured');
check(rhSecretSalt() === $legacy, 'no salt set: old behaviour kept (legacy salt used so the site does not break)');
$setSalt($legacy);
check(rhConfiguredSecretSalt() === '', 'the placeholder value counts as not configured');
$setSalt($real);
check(rhSecretSalt() === $real, 'salt set: the real salt is used to encrypt');
check(rhSecretSaltCandidates() === [$real, $legacy], 'decrypt tries the real salt, then the legacy one');

// ---- Facebook ----
$setSalt(null);
$oldFb = facebookEncryptSecret($token);              // saved on a host that had no salt yet
check(strncmp($oldFb, 'fb1:', 4) === 0 && facebookDecryptSecret($oldFb) === $token, 'facebook: round trip with no salt');
$setSalt($real);
check(facebookDecryptSecret($oldFb) === $token, 'facebook: old value still decrypts after the salt is set (no credential lost)');
$newFb = facebookEncryptSecret($token);              // the next save
check(facebookDecryptSecret($newFb) === $token, 'facebook: new value decrypts with the real salt');
$data = base64_decode(substr($newFb, 4), true);
check(openssl_decrypt(substr($data, 16), 'AES-256-CBC', facebookSecretKey($real), OPENSSL_RAW_DATA, substr($data, 0, 16)) === $token, 'facebook: re-encrypted value is under the real salt');
check(openssl_decrypt(substr($data, 16), 'AES-256-CBC', facebookSecretKey($legacy), OPENSSL_RAW_DATA, substr($data, 0, 16)) !== $token, 'facebook: re-encrypted value no longer opens with the public default salt');
$setSalt($real . '-other');
check(facebookDecryptSecret($newFb) === '', 'facebook: a different salt cannot read it (never returns junk)');
$setSalt($real);

// ---- WhatsApp ----
$setSalt(null);
$_SITE_SETTINGS['whatsapp_meta_access_token'] = encryptWhatsAppToken($token);
check(getWhatsAppAccessToken() === $token, 'whatsapp: round trip with no salt');
$old = $_SITE_SETTINGS['whatsapp_meta_access_token'];
$setSalt($real);
check(getWhatsAppAccessToken() === $token, 'whatsapp: old value still decrypts after the salt is set');
$_SITE_SETTINGS['whatsapp_meta_access_token'] = encryptWhatsAppToken($token);
check(getWhatsAppAccessToken() === $token && $old !== $_SITE_SETTINGS['whatsapp_meta_access_token'], 'whatsapp: re-saved value decrypts');
$raw = base64_decode(substr($_SITE_SETTINGS['whatsapp_meta_access_token'], 4), true);
check(openssl_decrypt(substr($raw, 16), 'AES-256-CBC', whatsAppTokenKey($legacy), OPENSSL_RAW_DATA, substr($raw, 0, 16)) !== $token, 'whatsapp: re-saved value is not under the default salt');

// ---- Review sources ----
$setSalt(null);
$_SITE_SETTINGS['reviews_google_places_key'] = review_source_encrypt('AIzaTestKey' . bin2hex(random_bytes(8)));
$plainKey = review_source_secret('reviews_google_places_key');
check(strncmp($plainKey, 'AIzaTestKey', 11) === 0, 'review sources: round trip with no salt');
$setSalt($real);
check(review_source_secret('reviews_google_places_key') === $plainKey, 'review sources: old value still decrypts after the salt is set');
$_SITE_SETTINGS['reviews_google_places_key'] = review_source_encrypt($plainKey);
check(review_source_secret('reviews_google_places_key') === $plainKey, 'review sources: re-saved value decrypts');
$raw = base64_decode(substr($_SITE_SETTINGS['reviews_google_places_key'], 4), true);
check(openssl_decrypt(substr($raw, 16), 'AES-256-CBC', review_sources_key($legacy), OPENSSL_RAW_DATA, substr($raw, 0, 16)) !== $plainKey, 'review sources: re-saved value is not under the default salt');

// ---- no literal default salt left in the three helpers ----
foreach (['includes/facebook-functions.php', 'includes/whatsapp-functions.php', 'includes/review-sources.php'] as $f) {
    $src = (string)file_get_contents($root . '/' . $f);
    check(strpos($src, 'DEFAULT_SALT_CHANGE_IN_ENV') === false && strpos($src, 'rhSecretSalt') !== false, "$f takes its salt from rhSecretSalt()");
}

if ($origEnv === null) { unset($_ENV['APP_ENCRYPTION_SALT']); } else { $_ENV['APP_ENCRYPTION_SALT'] = $origEnv; }
putenv($origGet === false ? 'APP_ENCRYPTION_SALT' : 'APP_ENCRYPTION_SALT=' . $origGet);
unset($_SITE_SETTINGS['whatsapp_meta_access_token'], $_SITE_SETTINGS['reviews_google_places_key']);

// ---- 2. document-download: bookings permission opens room payments only ----
$dd = (string)file_get_contents($root . '/admin/document-download.php');
check(preg_match('/function rh_doc_account_allows\(bool \$hasInvoices, string \$accountType\): bool\s*\{.*?\n\}/s', $dd, $m) === 1, 'document-download has the account-type rule');
if (!empty($m[0])) {
    eval($m[0]);
    check(rh_doc_account_allows(true, 'conference') && rh_doc_account_allows(true, 'gym') && rh_doc_account_allows(true, 'event') && rh_doc_account_allows(true, ''), 'invoices permission: every account type');
    check(rh_doc_account_allows(false, 'room'), 'bookings only: room payment invoice opens');
    check(!rh_doc_account_allows(false, 'conference') && !rh_doc_account_allows(false, 'gym') && !rh_doc_account_allows(false, 'event') && !rh_doc_account_allows(false, '') && !rh_doc_account_allows(false, 'other'), 'bookings only: conference / gym / event / unknown payments refused');
}
check(strpos($dd, "SELECT invoice_path AS p, booking_type AS t FROM payments") !== false, 'invoice lookup reads the payment account type');
check(strpos($dd, "\$type === 'invoice' && !rh_doc_account_allows(") !== false, 'the rule is applied to type=invoice');
check(strpos($dd, "'receipt'") !== false && preg_match("/'receipt'\s*=>\s*\['SELECT receipt_path AS p FROM payments WHERE id = \?', \['receipts'\]/", $dd) === 1, 'receipt keeps its own permission');

// ---- 3. void / cancel module gate ----
check(rhModuleAllowsAnyPage(['pos.php', 'order-lifecycle.php'], 'admin'), 'gate: admin always passes');
check(rhModuleAllowsAnyPage(['page-with-no-module.php'], 'manager'), 'gate: a page with no module passes');
$posOn = rh_module_key_enabled('pos');
$stockOn = rh_module_key_enabled('stock');
check(rhModuleAllowsAnyPage(['pos.php', 'order-lifecycle.php'], 'manager') === ($posOn || $stockOn), 'gate helper: passes when either page module is on (pos=' . (int)$posOn . ', stock=' . (int)$stockOn . ')');
check(rhModuleAllowsAnyPage(['pos.php'], 'manager') === $posOn, 'gate: the till alone still follows the pos module');
$v = (string)file_get_contents($root . '/api/void-order.php');
check(strpos($v, "rhApiModuleGate('pos.php')") !== false && strpos($v, 'rhModuleAllowsAnyPage') === false, 'void-order is unchanged: still follows the till module (its timeline button is admin-only and admins are never gated)');
check(strpos($v, "hasPermission((int)\$user['id'], 'pos_void')") !== false && strpos($v, "hasPermission((int)\$user['id'], 'stock_orders')") !== false && strpos($v, 'validateCsrfToken') !== false, 'void-order still needs pos_void, stock_orders and a CSRF token');
$c = (string)file_get_contents($root . '/api/cancel-order.php');
check(strpos($c, "!rhModuleAllowsAnyPage(['pos.php'], \$userRole)") !== false && strpos($c, "rhModuleAllowsAnyPage(['order-lifecycle.php'], \$userRole)") !== false, 'cancel-order: till module first, then the stock timeline exception');
check(strpos($c, "hasPermission(\$userId, 'pos_void') && hasPermission(\$userId, 'stock_orders'))) {") !== false && strpos($c, "cjerr('This feature is switched off in Modules.', 403);") !== false, 'cancel-order: the exception needs pos_void AND stock_orders, otherwise 403');
check(strpos($c, '$canOperate') !== false && strpos($c, "cjerr('Forbidden', 403)") !== false && strpos($c, 'validateCsrfToken') !== false, 'cancel-order still needs a session, CSRF and a till/KDS/stock-orders permission');
check(strpos($c, 'rhApiModuleGateAny') === false && !function_exists('rhApiModuleGateAny'), 'no leftover either-module gate');

// ---- root .htaccess: private folders are refused by the web server ----
$ht = (string)file_get_contents($root . '/.htaccess');
check(preg_match('/RewriteRule \^\((?:[A-Za-z]+\|)*storage(?:\|[A-Za-z]+)*\)\(\/\|\$\) - \[F,L\]/', $ht) === 1 && strpos($ht, '|backups|') !== false && strpos($ht, '|logs|') !== false && strpos($ht, '|Database)') !== false, 'root .htaccess denies storage, backups, logs and Database folders');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
