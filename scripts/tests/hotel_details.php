<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.
"); } // never runnable over the web
// Hotel details & policies: validation + save round trip, all inside a rolled-back transaction.
// Run from inside a repo folder: php scripts/tests/hotel_details.php
require 'config/database.php';
require 'admin/includes/permissions.php';
require 'admin/includes/hotel-details-settings.php';
$ok = 0; $bad = 0;
$t = function (bool $c, string $n) use (&$ok, &$bad) { echo ($c ? 'PASS ' : 'FAIL ') . $n . "\n"; $c ? $ok++ : $bad++; };
$f = [];
foreach (rh_hotel_details_fields() as $g) { $f += $g; }
$c = fn($k, $v) => rh_hotel_details_clean($k, $f[$k], $v);

// validation
$t($c('check_in_time', '14:00')[0] === '2:00 PM', 'time 14:00 -> 2:00 PM');
$t($c('check_out_time', '11:00 am')[0] === '11:00 AM', 'time 11:00 am normalised');
$t($c('check_in_time', '25:99')[1] !== null, 'bad time rejected');
$t($c('hotel_star_rating', '6')[1] !== null && $c('hotel_star_rating', '4')[0] === '4', 'star rating bounds');
$t($c('restaurant_service_charge_pct', '12.5')[0] === '12.5' && $c('restaurant_service_charge_pct', '30')[1] !== null, 'service charge 0-25');
$t($c('stock_variance_min_cost', '5,000')[0] === '5000', 'thousands separator accepted');
$t($c('admin_notification_email', 'not-an-email')[1] !== null && $c('admin_notification_email', 'Ops@Hotel.mw')[0] === 'ops@hotel.mw', 'email validated + lowercased');
$t($c('eod_report_cc_emails', 'a@x.com; b@y.com,a@x.com')[0] === 'a@x.com,b@y.com', 'email list normalised + de-duplicated');
$t($c('eod_report_cc_emails', 'a@x.com, nope')[1] !== null, 'bad address in list rejected');
$t($c('bank_account_number', '1001-234 567')[0] === '1001-234 567' && $c('bank_account_number', "123<script>")[1] !== null, 'account number charset');
$t($c('phone_secondary', '+265 999 123 456')[1] === null && $c('phone_secondary', 'call me')[1] !== null, 'phone charset');
$t($c('price_range_indicator', '$$')[0] === '$$' && $c('price_range_indicator', '$$$$$')[1] !== null, 'price range options');
$t($c('restaurant_menu_pdf_url', 'javascript:alert(1)')[1] !== null && $c('restaurant_menu_pdf_url', 'https://x.mw/menu.pdf')[1] === null, 'url scheme checked');
$t(mb_strlen($c('hotel_address', str_repeat('a', 256))[1] ?? '') > 0, 'length limit enforced');
$m = $c('google_maps_embed', '<iframe src="https://www.google.com/maps/embed?pb=!1m18!abc" width="600" onload="evil()"></iframe>');
$t($m[1] === null && strpos($m[0], 'onload') === false && strpos($m[0], 'https://www.google.com/maps/embed?pb=!1m18!abc') !== false, 'maps iframe rebuilt (attributes dropped)');
$t($c('google_maps_embed', '<iframe src="https://evil.example/maps/embed"></iframe>')[1] !== null, 'non-Google map refused');
$t($c('google_maps_embed', '<script>alert(1)</script>')[1] !== null, 'script refused');

// save round trip (rolled back)
$admin = (int)$pdo->query("SELECT id FROM admin_users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$staff = (int)$pdo->query("SELECT id FROM admin_users WHERE role NOT IN ('admin','manager') AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$before = rh_hotel_details_value('bank_name', $f['bank_name']);
$pdo->beginTransaction();
try {
    [$msg, $err] = rh_hotel_details_save(['check_in_time' => '15:00', 'tentative_reminder_hours' => '12', 'booking_child_price_multiplier' => '40', 'bank_name' => 'TEST BANK'], ['id' => $admin, 'username' => 'test']);
    $t($err === '' && strpos($msg, 'saved') !== false, "admin save ok ($msg)");
    $t(getSetting('check_in_time') === '3:00 PM' && getSetting('tentative_reminder_hours') === '12', 'values stored and read back');
    $t(getSetting('booking_child_price_multiplier') === '40' && getSetting('child_guest_price_multiplier') === '40', 'child % mirrored to the older key');
    $t(getSetting('bank_name') === 'TEST BANK', 'finance user can change bank details');
    if ($staff) {
        [$msg2, $err2] = rh_hotel_details_save(['bank_name' => 'HACKED', 'restaurant_tagline' => 'Tag test'], ['id' => $staff, 'username' => 'staff']);
        $t(getSetting('bank_name') === 'TEST BANK' && getSetting('restaurant_tagline') === 'Tag test', 'non-finance user: bank ignored, other fields saved');
    }
    [$msg3, $err3] = rh_hotel_details_save(['check_in_time' => 'nonsense', 'restaurant_tagline' => 'Should not save'], ['id' => $admin]);
    $t($err3 !== '' && getSetting('restaurant_tagline') !== 'Should not save', 'any invalid field -> nothing saved');
    ob_start(); rh_hotel_details_render(['id' => $admin], 'tok'); $html = ob_get_clean();
    $t(strpos($html, 'name="check_in_time"') !== false && strpos($html, 'value="3:00 PM"') !== false, 'card renders saved values');
} finally {
    $pdo->rollBack();
}
// The settings cache may hold the rolled-back values: clear the keys touched.
foreach (['check_in_time', 'tentative_reminder_hours', 'booking_child_price_multiplier', 'child_guest_price_multiplier', 'bank_name', 'restaurant_tagline'] as $k) {
    if (function_exists('deleteCache')) deleteCache("setting_$k");
    foreach (glob("cache/setting_{$k}_*.cache") ?: [] as $cf) @unlink($cf);
}
$GLOBALS['_SITE_SETTINGS'] = [];
$t(rh_hotel_details_value('bank_name', $f['bank_name']) === $before, 'rolled back: bank name unchanged');
echo "RESULT: $ok passed, $bad failed\n";
