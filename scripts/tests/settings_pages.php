<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.
"); } // never runnable over the web
/**
 * settings_check.php - rolled-back verification of the admin Settings area.
 * Run from INSIDE a repo root:  php scripts/tests/settings_pages.php
 * Every page scenario runs in page_harness.php (one outer transaction on a PDO that can never commit,
 * rolled back at the end). Static checks only read source. No emails are sent (scheduler master switch is
 * forced off inside the transaction; send actions are never posted).
 */
$root = getcwd();
$h = __DIR__ . '/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name  $detail\n"; }
}
function run_page(string $page, array $post, array $verify = [], bool $ajax = false, array $grep = []): array {
    global $h;
    $cfg = base64_encode(json_encode(['post' => $post, 'ajax' => $ajax, 'verify' => $verify, 'grep' => $grep]));
    $out = shell_exec('php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -400), 'rolled_back' => false];
}
function flash(array $r): string { return strtolower(implode(' | ', $r['output_flash'] ?? [])); }
function jmsg(array $r): string { return strtolower((string)($r['json']['message'] ?? $r['json']['error'] ?? '')); }
require_once $root . '/config/database.php';
function pdo_live(): PDO { global $pdo; return $pdo; }

echo "== Rate plans (admin/rate-plans.php) ==\n";
$base = ['action' => 'save', 'name' => 'ZZ Harness', 'rule_type' => 'promotion', 'adjustment_type' => 'percentage', 'adjustment_value' => '-10', 'applies_to' => 'all', 'priority' => '1', 'is_active' => '1'];
$v = ["SELECT name,rule_type,start_date,end_date,days_of_week,min_nights,max_nights,days_before_min,days_before_max,adjustment_value FROM rate_plans WHERE name LIKE 'ZZ Harness%'"];
$r = run_page('admin/rate-plans.php', array_merge($base, ['rule_type' => 'last_minute', 'days_before_min' => '0', 'days_before_max' => '0', 'eb_days_before_min' => '', 'eb_days_before_max' => '']), $v, true);
ok('last-minute window 0..0 saved (was lost to a duplicate field name)', ($r['verify'][0][0]['days_before_max'] ?? null) === 0 && ($r['verify'][0][0]['days_before_min'] ?? null) === 0, json_encode($r['verify'][0] ?? $r));
ok('rate plan test rolled back', !empty($r['rolled_back']));
$r = run_page('admin/rate-plans.php', array_merge($base, ['rule_type' => 'early_bird', 'days_before_min' => '', 'eb_days_before_min' => '45', 'eb_days_before_max' => '']), $v, true);
ok('early-bird window read from its own fields', ($r['verify'][0][0]['days_before_min'] ?? null) === 45 && array_key_exists('days_before_max', $r['verify'][0][0] ?? []) && $r['verify'][0][0]['days_before_max'] === null, json_encode($r['verify'][0] ?? $r));
$cases = [
    'seasonal without dates rejected' => [['rule_type' => 'seasonal'], 'seasonal'],
    'end before start rejected' => [['rule_type' => 'seasonal', 'start_date' => '2027-02-10', 'end_date' => '2027-02-01'], 'end date'],
    'impossible date rejected' => [['rule_type' => 'seasonal', 'start_date' => '2027-02-30', 'end_date' => '2027-03-05'], 'valid'],
    'percentage -100 rejected' => [['adjustment_value' => '-100'], 'above -100'],
    'percentage 1001 rejected' => [['adjustment_value' => '1001'], 'at most 1000'],
    'non-numeric adjustment rejected' => [['adjustment_value' => 'abc'], 'non-zero'],
    'zero adjustment rejected' => [['adjustment_value' => '0'], 'non-zero'],
    'fixed > 9,999,999 rejected' => [['adjustment_type' => 'fixed', 'adjustment_value' => '100000000'], 'cannot exceed'],
    'weekend without days rejected' => [['rule_type' => 'weekend'], 'day of the week'],
    'min > max nights rejected' => [['rule_type' => 'los_discount', 'min_nights' => '5', 'max_nights' => '3'], 'maximum nights'],
    'los without nights rejected' => [['rule_type' => 'los_discount'], 'length-of-stay'],
    'last-minute min > max rejected' => [['rule_type' => 'last_minute', 'days_before_min' => '10', 'days_before_max' => '2'], 'maximum days'],
    'early-bird without window rejected' => [['rule_type' => 'early_bird'], 'window'],
    'room_types with no rooms rejected' => [['applies_to' => 'room_types'], 'room type'],
    'room_types with unknown room rejected' => [['applies_to' => 'room_types', 'room_type_ids' => ['999999']], 'room type'],
    'name too long rejected' => [['name' => str_repeat('x', 101)], '100 characters'],
];
foreach ($cases as $label => [$over, $needle]) {
    $r = run_page('admin/rate-plans.php', array_merge($base, $over), $v, true);
    ok($label, ($r['json']['success'] ?? true) === false && strpos(jmsg($r), $needle) !== false && count($r['verify'][0] ?? [1]) === 0, jmsg($r));
}
$r = run_page('admin/rate-plans.php', array_merge($base, ['rule_type' => 'los_discount', 'min_nights' => '300', 'start_date' => '2027-01-01']), $v, true);
ok('nights clamped to 255 and foreign start_date dropped', ($r['verify'][0][0]['min_nights'] ?? null) === 255 && array_key_exists('start_date', $r['verify'][0][0] ?? []) && $r['verify'][0][0]['start_date'] === null, json_encode($r['verify'][0] ?? $r));

echo "== Pricing consumer (includes/pricing.php, inside a rolled-back transaction) ==\n";
$pdo = pdo_live();
require_once $root . '/includes/pricing.php';
$rooms = $pdo->query("SELECT id FROM rooms WHERE is_active = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($rooms) >= 2) {
    [$roomA, $roomB] = [(int)$rooms[0], (int)$rooms[1]];
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO rate_plans (name, rule_type, adjustment_type, adjustment_value, applies_to, room_type_ids, priority, is_stacking, is_active) VALUES (?,?,?,?,?,?,?,?,?)");
    $ins->execute(['ZZ NS 10', 'promotion', 'percentage', -10, 'room_types', json_encode([$roomA]), 5, 0, 1]);
    $ins->execute(['ZZ NS 20', 'promotion', 'percentage', -20, 'room_types', json_encode([$roomA]), 4, 0, 1]);
    $ins->execute(['ZZ ST 5', 'promotion', 'percentage', -5, 'room_types', json_encode([$roomA]), 3, 1, 1]);
    $ins->execute(['ZZ OFF 50', 'promotion', 'percentage', -50, 'room_types', json_encode([$roomA]), 9, 0, 0]);
    $ins->execute(['ZZ STK a', 'promotion', 'percentage', -10, 'room_types', json_encode([$roomB]), 3, 1, 1]);
    $ins->execute(['ZZ STK b', 'promotion', 'percentage', -10, 'room_types', json_encode([$roomB]), 2, 1, 1]);
    $in = date('Y-m-d', strtotime('+5 days')); $out = date('Y-m-d', strtotime('+7 days'));
    $a = applyDynamicPricing($pdo, $roomA, $in, $out, 2, 100.0);
    ok('any non-stacking plan: single best discount (-20%), stacking ignored, inactive ignored', abs($a['final_price'] - 80.0) < 0.005, json_encode($a['final_price']));
    $b = applyDynamicPricing($pdo, $roomB, $in, $out, 2, 100.0);
    ok('only stacking plans: combined (-10% then -10% = 81)', abs($b['final_price'] - 81.0) < 0.005, json_encode($b['final_price']));
    $pdo->rollBack();
    ok('pricing test rolled back', (int)$pdo->query("SELECT COUNT(*) FROM rate_plans WHERE name LIKE 'ZZ%'")->fetchColumn() === 0);
} else { ok('pricing test needs 2 active rooms', false); }

echo "== Packages (admin/packages.php) ==\n";
$pb = ['action' => 'save', 'name' => 'ZZ Pkg', 'price_type' => 'per_night', 'price_amount' => '5000', 'applies_to' => 'all', 'is_active' => '1', 'inclusions' => "Breakfast\nWifi"];
$pv = ["SELECT name,price_amount,applies_to,inclusions FROM room_packages WHERE name LIKE 'ZZ Pkg%'"];
$r = run_page('admin/packages.php', $pb, $pv, true);
ok('valid package saved', ($r['json']['success'] ?? false) === true && count($r['verify'][0] ?? []) === 1, jmsg($r));
foreach ([['price_amount' => '1000000000', 'between 0'], ['price_amount' => 'abc', 'between 0'], ['price_amount' => '-5', 'between 0'], ['name' => str_repeat('p', 101), '100 characters'], ['applies_to' => 'room_types', 'room type']] as $c) {
    $needle = array_pop($c);
    $r = run_page('admin/packages.php', array_merge($pb, $c), $pv, true);
    ok('package rejected: ' . json_encode($c), ($r['json']['success'] ?? true) === false && strpos(jmsg($r), $needle) !== false && count($r['verify'][0] ?? [1]) === 0, jmsg($r));
}

echo "== Deals (admin/deals.php) ==\n";
$db = ['ajax_action' => 'save', 'name' => 'ZZ Deal', 'deal_type' => 'happy_hour', 'discount_percent' => '20', 'start_time' => '17:00', 'end_time' => '19:00', 'applies_to' => 'all', 'is_active' => '1'];
$dv = ["SELECT name,deal_type,discount_percent,start_time,end_time FROM pos_deals WHERE name LIKE 'ZZ Deal%'"];
$r = run_page('admin/deals.php', $db, $dv, true);
ok('valid happy hour saved', ($r['json']['ok'] ?? false) === true && count($r['verify'][0] ?? []) === 1, json_encode($r['json'] ?? $r));
foreach ([
    'happy hour with 0% rejected' => [['discount_percent' => '0'], 'percentage above 0'],
    'window across midnight rejected' => [['start_time' => '22:00', 'end_time' => '02:00'], 'midnight'],
    'only start time rejected' => [['end_time' => ''], 'both'],
    'bad time rejected' => [['start_time' => '25:00'], 'hh:mm'],
    'valid_to before valid_from rejected' => [['valid_from' => '2027-02-10', 'valid_to' => '2027-02-01'], 'valid to'],
    'combo without groups rejected' => [['deal_type' => 'combo', 'discount_percent' => '10', 'start_time' => '', 'end_time' => ''], 'combo'],
    'fixed_off without amount rejected' => [['deal_type' => 'fixed_off', 'discount_fixed' => '0', 'start_time' => '', 'end_time' => ''], 'fixed discount'],
    'item_types scope with none chosen rejected' => [['applies_to' => 'item_types', 'item_types' => ''], 'category'],
] as $label => [$over, $needle]) {
    $r = run_page('admin/deals.php', array_merge($db, $over), $dv, true);
    ok($label, ($r['json']['ok'] ?? true) === false && strpos(strtolower((string)($r['json']['error'] ?? '')), $needle) !== false && count($r['verify'][0] ?? [1]) === 0, json_encode($r['json'] ?? $r));
}

echo "== WhatsApp settings ==\n";
$wb = ['whatsapp_number' => '+265888123456', 'whatsapp_api_token' => '', 'whatsapp_phone_id' => '', 'whatsapp_business_id' => '', 'whatsapp_admin_numbers' => '', 'whatsapp_confirmed_template' => 'booking_confirmed', 'whatsapp_cancelled_template' => 'booking_cancelled'];
$wv = ["SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('whatsapp_provider','whatsapp_number')"];
$r = run_page('admin/whatsapp-settings.php', $wb, $wv);
$wmap = []; foreach (($r['verify'][0] ?? []) as $row) { $wmap[$row['setting_key']] = $row['setting_value']; }
ok('valid WhatsApp save writes number', ($wmap['whatsapp_number'] ?? '') === '+265888123456', json_encode($r['output_flash'] ?? $r));
ok('WhatsApp save no longer overwrites whatsapp_provider', !array_key_exists('whatsapp_provider', $wmap) || $wmap['whatsapp_provider'] === (string)(pdo_live()->query("SELECT setting_value FROM site_settings WHERE setting_key='whatsapp_provider'")->fetchColumn()));
foreach ([['whatsapp_number' => 'hello', 'phone number'], ['whatsapp_phone_id' => 'abc', 'numeric'], ['whatsapp_confirmed_template' => 'bad name!', 'underscores'], ['whatsapp_admin_numbers' => '+265888123456, nope', 'phone numbers'], ['whatsapp_enabled' => '1', 'access token']] as $c) {
    $needle = array_pop($c);
    $r = run_page('admin/whatsapp-settings.php', array_merge($wb, $c), $wv);
    ok('WhatsApp rejected: ' . json_encode($c), strpos(flash($r), $needle) !== false && !array_key_exists(0, array_filter($r['verify'][0] ?? [], fn($x) => $x['setting_key'] === 'whatsapp_number' && $x['setting_value'] === '+265888123456')), flash($r));
}

echo "== Facebook settings ==\n";
$fb = ['facebook_page_id' => '123456789', 'facebook_page_name' => 'ZZ Page', 'facebook_default_hashtags' => '#hotel #malawi', 'facebook_page_access_token' => ''];
$fv = ["SELECT setting_value FROM site_settings WHERE setting_key='facebook_page_name'"];
$r = run_page('admin/facebook-settings.php', $fb, $fv);
ok('valid Facebook save', (($r['verify'][0][0]['setting_value'] ?? '') === 'ZZ Page'), json_encode($r['output_flash'] ?? $r));
foreach ([['facebook_page_id' => '12ab', 'numeric'], ['facebook_default_hashtags' => 'hotel malawi', 'start with #'], ['facebook_page_access_token' => 'has space', 'token looks invalid']] as $c) {
    $needle = array_pop($c);
    $r = run_page('admin/facebook-settings.php', array_merge($fb, $c), $fv);
    ok('Facebook rejected: ' . json_encode($c), strpos(flash($r), $needle) !== false && (($r['verify'][0][0]['setting_value'] ?? '') !== 'ZZ Page'), flash($r));
}

echo "== Booking settings (admin/booking-settings.php) ==\n";
$bv = fn(string $k) => ["SELECT setting_value FROM site_settings WHERE setting_key='$k'"];
$r = run_page('admin/booking-settings.php', ['save_booking_reference_prefix' => '1', 'booking_reference_prefix' => 'zz9'], $bv('booking_reference_prefix'));
ok('reference prefix saved upper-case', ($r['verify'][0][0]['setting_value'] ?? '') === 'ZZ9', flash($r));
$r = run_page('admin/booking-settings.php', ['save_booking_reference_prefix' => '1', 'booking_reference_prefix' => 'A'], $bv('booking_reference_prefix'));
ok('reference prefix too short rejected', strpos(flash($r), '2 to 6') !== false && empty($r['verify'][0]));
$r = run_page('admin/booking-settings.php', ['max_advance_booking_days' => '90'], $bv('max_advance_booking_days'));
ok('advance days 90 saved', ($r['verify'][0][0]['setting_value'] ?? '') === '90', flash($r));
$r = run_page('admin/booking-settings.php', ['max_advance_booking_days' => '400'], $bv('max_advance_booking_days'));
ok('advance days 400 rejected (live value unchanged)', strpos(flash($r), 'cannot exceed') !== false && ($r['verify'][0][0]['setting_value'] ?? '') !== '400', flash($r));
$r = run_page('admin/booking-settings.php', ['tourism_levy_settings' => '1', 'tourism_levy_enabled' => '1', 'tourism_levy_percent' => '12.345'], $bv('tourism_levy_percent'));
ok('tourism levy rounded to 2dp', ($r['verify'][0][0]['setting_value'] ?? '') === '12.35', flash($r) . json_encode($r['verify'] ?? ''));
$r = run_page('admin/booking-settings.php', ['tourism_levy_settings' => '1', 'tourism_levy_percent' => '101'], $bv('tourism_levy_percent'));
ok('tourism levy 101% rejected', strpos(flash($r), 'cannot exceed 100') !== false && ($r['verify'][0][0]['setting_value'] ?? '') !== '101', flash($r));
$r = run_page('admin/booking-settings.php', ['booking_disabled_action' => 'redirect', 'booking_disabled_redirect_url' => 'javascript:alert(1)', 'booking_disabled_message' => 'x'], $bv('booking_disabled_redirect_url'));
ok('javascript: redirect rejected', strpos(flash($r), 'redirect url must start') !== false && ($r['verify'][0][0]['setting_value'] ?? '') !== 'javascript:alert(1)', flash($r));
$r = run_page('admin/booking-settings.php', ['save_site_maintenance_message' => '1', 'site_maintenance_message' => str_repeat('m', 2000)], ["SELECT CHAR_LENGTH(setting_value) l FROM site_settings WHERE setting_key='site_maintenance_message'"]);
ok('maintenance message capped at 1000 chars', (int)($r['verify'][0][0]['l'] ?? 0) === 1000, json_encode($r['verify'] ?? $r));
$r = run_page('admin/booking-settings.php', ['save_cancellation_refund_mode' => '1', 'cancellation_refund_mode' => 'nonsense'], $bv('cancellation_refund_mode'));
ok('invalid cancellation mode rejected', strpos(flash($r), 'invalid refund') !== false, flash($r));
$r = run_page('admin/booking-settings.php', ['save_document_branding' => '1', 'brand_primary_color' => 'red', 'brand_accent_color' => '', 'document_footer_text' => '', 'document_terms_text' => '', 'document_logo_height_mm' => ''], $bv('brand_primary_color'));
ok('invalid brand colour rejected', strpos(flash($r), 'hex colour') !== false && empty($r['verify'][0]), flash($r));
$es = ['email_settings' => '1', 'smtp_host' => 'mail.example.com', 'smtp_port' => '465', 'smtp_username' => 'u', 'smtp_password' => '', 'smtp_secure' => 'ssl', 'email_from_name' => 'Hotel', 'email_from_email' => 'from@example.com', 'email_admin_email' => '', 'invoice_recipients' => 'a@x.com; b@y.com , A@x.com'];
$ev = ["SELECT setting_value FROM email_settings WHERE setting_key='invoice_recipients'"];
$r = run_page('admin/booking-settings.php', $es, $ev);
ok('invoice recipients normalised to a de-duplicated comma list', ($r['verify'][0][0]['setting_value'] ?? '') === 'a@x.com, b@y.com', flash($r) . json_encode($r['verify'] ?? ''));
$r = run_page('admin/booking-settings.php', array_merge($es, ['smtp_host' => 'bad host!']), $ev);
ok('bad SMTP host rejected', strpos(flash($r), 'smtp host') !== false, flash($r));
$r = run_page('admin/booking-settings.php', array_merge($es, ['email_from_name' => "A\r\nBcc: x@y.com"]), $ev);
ok('header-injection from name rejected', strpos(flash($r), 'from name') !== false, flash($r));

echo "== Station hours, automated emails, cache management ==\n";
$r = run_page('admin/station-settings.php', ['stations' => ['kitchen' => ['opens_at' => '25:99', 'closes_at' => '10:00']]], ["SELECT COUNT(*) c FROM site_settings WHERE setting_value='25:99'"], false, ['Use 24-hour HH:MM format']);
ok('bad station time rejected, nothing written', !empty($r['found']['Use 24-hour HH:MM format']) && (int)($r['verify'][0][0]['c'] ?? 1) === 0, json_encode($r['found'] ?? $r));
$r = run_page('admin/automated-emails.php', ['action' => 'save', 'automated_email_reminder_stages' => '9, 2, 400, 2', 'automated_email_test_recipient' => 'nope', 'automated_email_interval_minutes' => '1'], ["SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('automated_email_reminder_stages','automated_email_interval_minutes','automated_email_test_recipient')"]);
$am = []; foreach (($r['verify'][0] ?? []) as $row) { $am[$row['setting_key']] = $row['setting_value']; }
ok('reminder stages sanitised to 2,9; interval floored to 5; bad test recipient cleared', ($am['automated_email_reminder_stages'] ?? '') === '2,9' && ($am['automated_email_interval_minutes'] ?? '') === '5' && ($am['automated_email_test_recipient'] ?? 'x') === '', json_encode($am));
$r = run_page('admin/cache-management.php', ['action' => 'set_schedule', 'schedule_enabled' => '1', 'schedule_interval' => 'bogus', 'schedule_time' => '99:99', 'custom_seconds' => '1'], ["SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('cache_schedule_interval','cache_schedule_time','cache_custom_seconds')"]);
$cm = []; foreach (($r['verify'][0] ?? []) as $row) { $cm[$row['setting_key']] = $row['setting_value']; }
ok('cache schedule: bad interval/time/seconds normalised', ($cm['cache_schedule_interval'] ?? '') === 'daily' && ($cm['cache_schedule_time'] ?? '') === '00:00' && ($cm['cache_custom_seconds'] ?? '') === '10', json_encode($cm));

echo "== Modules (admin/api/toggle-module.php, apply-preset.php) ==\n";
$r = run_page('admin/api/toggle-module.php', ['module_key' => 'finance', 'is_enabled' => '0'], ["SELECT is_enabled FROM enabled_modules WHERE module_key='finance'"]);
ok('finance cannot be switched off', ($r['json']['success'] ?? true) === false && (int)($r['verify'][0][0]['is_enabled'] ?? 0) === 1, json_encode($r['json'] ?? $r));
$r = run_page('admin/api/toggle-module.php', ['module_key' => 'nonsense', 'is_enabled' => '0']);
ok('unknown module key rejected', ($r['json']['success'] ?? true) === false);
$r = run_page('admin/api/apply-preset.php', ['preset_key' => 'bar_restaurant'], ["SELECT module_key,is_enabled FROM enabled_modules WHERE module_key IN ('bookings','pos','finance','station_kds','station_cds')", "SELECT setting_value FROM site_settings WHERE setting_key='events_system_enabled'"]);
$mm = []; foreach (($r['verify'][0] ?? []) as $row) { $mm[$row['module_key']] = (int)$row['is_enabled']; }
ok('preset bar_restaurant applies cleanly (bookings off, pos/finance/kds on, cds off, events page off)', ($r['json']['success'] ?? false) === true && $mm['bookings'] === 0 && $mm['pos'] === 1 && $mm['finance'] === 1 && $mm['station_kds'] === 1 && $mm['station_cds'] === 0 && ($r['verify'][1][0]['setting_value'] ?? '') === '0', json_encode([$mm, $r['json'] ?? '']));
$live = pdo_live()->query("SELECT module_key, is_enabled FROM enabled_modules WHERE module_key IN ('bookings','pos')")->fetchAll(PDO::FETCH_KEY_PAIR);
ok('live modules unchanged after preset test (rolled back)', (int)$live['bookings'] === 1 && (int)$live['pos'] === 1, json_encode($live));
$p = require_once $root . '/admin/includes/module-presets.php';
$presets = getBusinessPresets();
$allowed = ['bookings', 'housekeeping', 'pos', 'stock', 'conference', 'gym', 'finance', 'website_cms', 'station_kds', 'station_bds', 'station_cds', 'station_room_service'];
$bad = [];
foreach ($presets as $k => $pr) {
    if (array_diff(array_keys($pr['modules']), $allowed) || array_diff($allowed, array_keys($pr['modules']))) { $bad[] = "$k keys"; }
    if (empty($pr['modules']['finance'])) { $bad[] = "$k finance off"; }
    if (empty($pr['modules']['pos']) && array_filter([$pr['modules']['station_kds'], $pr['modules']['station_bds'], $pr['modules']['station_cds'], $pr['modules']['station_room_service']])) { $bad[] = "$k stations without pos"; }
}
ok('every preset lists all modules, keeps finance on, no stations without POS', !$bad, json_encode($bad));

echo "== Key/default consistency (source scan) ==\n";
$skip = ['vendor', 'PHPMailer', 'node_modules', '.git', 'logs', 'cache', 'backups', 'images', 'Database', 'docs', 'scripts'];
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), function ($f) use ($skip) { return !($f->isDir() && in_array($f->getFilename(), $skip)); }));
$defs = [];
foreach ($it as $f) {
    if (substr($f, -4) !== '.php' || basename($f) === 'footer-management.php') { continue; }
    $src = file_get_contents($f);
    if (preg_match_all('/getSetting\(\s*([\'"])(currency_symbol|currency_code|site_name|hotel_name|check_in_time|check_out_time|max_advance_booking_days|tourism_levy_enabled|tourism_levy_percent|facebook_default_hashtags|site_timezone|facebook_page_name)\1\s*,\s*([\'"])((?:\\\\.|(?!\3).)*)\3\s*\)/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) { $d = $x[4]; if ($d === '' ) { continue; } $defs[$x[2]][is_numeric($d) ? (string)(float)$d : $d] = 1; }
    }
    if (preg_match_all('/getSetting\(\s*([\'"])(max_advance_booking_days|tourism_levy_percent|site_timezone)\1\s*,\s*([A-Za-z0-9_]+(?:\(\))?)\s*\)/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) { $d = $x[3]; $defs[$x[2]][is_numeric($d) ? (string)(float)$d : $d] = 1; }
    }
}
foreach ($defs as $k => $set) { ok("single default for $k", count($set) === 1, json_encode(array_keys($set))); }

echo "\nsettings_check: PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
