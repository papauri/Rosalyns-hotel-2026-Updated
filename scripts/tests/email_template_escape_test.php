<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * loadEmailTemplate() escapes every value it puts into templates/emails/*.html (conference + gym emails), except the
 * allow-listed HTML blocks built by our own code (packages_html, logo_html). Run from the TEST hotel's root only:
 *   php scripts/tests/email_template_escape_test.php
 * Sends NO email and writes NOTHING: only renders the templates in memory (getSetting reads only).
 *
 * Checks, for every .html template rendered through loadEmailTemplate:
 *  - a value with <script>, quotes and & is escaped at every placeholder (and nothing raw gets through);
 *  - an allow-listed HTML block still renders as given;
 *  - values already run through sanitizeString() by the public forms are escaped once, never twice;
 *  - normal values give byte-identical output to the old (unescaped) function;
 *  - the plain-text part (rh_email_html_to_text) shows the typed characters, no HTML entities;
 *  - a typed "{{/if}}" cannot open or close a conditional block.
 */
$root = dirname(__DIR__, 2);
chdir($root);
if (stripos(str_replace('\\', '/', $root), 'liwonde') === false) {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
require_once $root . '/config/email.php'; // only after both refusals
require_once $root . '/includes/validation.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$warn = 0;
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0) use (&$warn): bool {
    $warn++;
    echo "  (warning: $str at " . basename($file) . ":$line)\n";
    return true;
}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);

/** The function as it was before the fix (values inserted raw, placeholders before blocks): the byte-identity reference. */
$oldLoad = static function (string $template, array $data): string {
    $template_path = __DIR__ . '/../../templates/emails/' . $template;
    if (!file_exists($template_path)) {
        return '';
    }
    $html = file_get_contents($template_path);
    $site_url = getSetting('site_url');
    $common_data = [
        'site_name' => getSetting('site_name'),
        'site_url' => $site_url,
        'contact_email' => getSetting('email_main'),
        'phone' => getSetting('phone_main'),
        'currency_symbol' => getSetting('currency_symbol'),
        'submission_source' => $_SERVER['HTTP_HOST'] ?? 'localhost',
        'admin_url' => $site_url . '/admin/conference-management.php'
    ];
    $data = array_merge($common_data, $data);
    $html = preg_replace_callback('/\{\{(\w+)\}\}/', function (array $m) use ($data) {
        return $data[$m[1]] ?? '';
    }, $html);
    $html = preg_replace_callback('/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s', function (array $m) use ($data) {
        return !empty($data[$m[1]]) ? $m[2] : '';
    }, $html);
    return $html;
};

$raw = ['packages_html' => true, 'logo_html' => true];
$templates = glob($root . '/templates/emails/*.html') ?: [];
ok('found the .html templates rendered by loadEmailTemplate', count($templates) >= 4, (string)count($templates));

$hostile = 'Tom & "Jerry" <script>alert(\'x\')</script>';
$hostileEsc = htmlspecialchars($hostile, ENT_QUOTES, 'UTF-8');
$block = '<table data-t="pk"><tr><td>Half day &amp; tea</td><td>MWK 10,000</td></tr></table>';
$logo = '<img src="https://example.invalid/logo.png" alt="Hotel">';
// Every common key is overridden so the hotel's settings never affect the comparisons.
$commonKeys = ['site_name', 'site_url', 'contact_email', 'phone', 'currency_symbol', 'submission_source', 'admin_url'];

foreach ($templates as $path) {
    $name = basename($path);
    $src = (string)file_get_contents($path);
    preg_match_all('/\{\{(\w+)\}\}/', $src, $pm);
    preg_match_all('/\{\{#if\s+(\w+)\}\}/', $src, $im);
    $keys = array_values(array_unique(array_merge($pm[1], $im[1], $commonKeys)));
    $occ = array_count_values($pm[1]);
    $escSlots = 0;
    foreach ($occ as $k => $n) {
        if (!isset($raw[$k])) { $escSlots += $n; }
    }
    ok("$name: has placeholders", $escSlots > 0);
    ok("$name: template itself holds no <script", stripos($src, '<script') === false);

    // 1. Hostile value at every placeholder; allow-listed blocks get real HTML.
    $d = [];
    foreach ($keys as $k) {
        $d[$k] = $k === 'packages_html' ? $block : ($k === 'logo_html' ? $logo : $hostile);
    }
    $out = loadEmailTemplate($name, $d);
    ok("$name: no raw <script> anywhere", stripos($out, '<script') === false);
    ok("$name: no raw quote-breaking value", strpos($out, $hostile) === false && strpos($out, '"Jerry"') === false);
    ok("$name: escaped value at every placeholder ($escSlots)", substr_count($out, $hostileEsc) === $escSlots,
        'found ' . substr_count($out, $hostileEsc));
    if (isset($occ['packages_html'])) {
        ok("$name: packages_html block renders as given", strpos($out, $block) !== false);
    }
    if (isset($occ['logo_html'])) {
        ok("$name: logo_html tag renders as given", strpos($out, $logo) !== false);
    }
    $txt = rh_email_html_to_text($out);
    ok("$name: plain text shows the typed characters", strpos($txt, 'Tom & "Jerry"') !== false);
    ok("$name: plain text has no HTML entities", !preg_match('/&(amp|quot|lt|gt|#0?39|apos);/', $txt));

    // 2. Normal values: byte-identical to the old function.
    $n = [];
    foreach ($keys as $k) {
        $n[$k] = $k === 'packages_html' ? $block : ($k === 'logo_html' ? $logo : 'Value ' . $k . ' 12, 3:45 PM');
    }
    $n['site_url'] = 'https://hotel.example.invalid';
    $n['admin_url'] = 'https://hotel.example.invalid/admin/conference-management.php';
    $n['contact_email'] = 'info@example.invalid';
    $n['currency_symbol'] = 'MWK';
    ok("$name: normal values byte-identical to before", loadEmailTemplate($name, $n) === $oldLoad($name, $n));
    $e = $n;
    foreach (array_keys($im[1] ? array_flip($im[1]) : []) as $k) { $e[$k] = ''; }
    ok("$name: empty conditional values byte-identical to before", loadEmailTemplate($name, $e) === $oldLoad($name, $e));

    // 3. Already sanitized by the public form (sanitizeString): escaped once, renders the same as before.
    $s = $n;
    foreach (['company_name', 'contact_person', 'special_requirements', 'av_equipment', 'goals', 'name'] as $k) {
        if (in_array($k, $keys, true)) { $s[$k] = sanitizeString("O'Brien & Sons \"Ltd\""); }
    }
    $outS = loadEmailTemplate($name, $s);
    $oldS = $oldLoad($name, $s);
    if ($s !== $n) {
        ok("$name: sanitized value escaped once", strpos($outS, 'O&#039;Brien &amp; Sons &quot;Ltd&quot;') !== false);
        ok("$name: sanitized value never double-escaped", strpos($outS, '&amp;apos;') === false && strpos($outS, '&amp;amp;') === false
            && strpos($outS, '&amp;quot;') === false && strpos($outS, '&amp;#') === false);
        ok("$name: sanitized value reads the same as before",
            html_entity_decode($outS, ENT_QUOTES | ENT_HTML5, 'UTF-8') === html_entity_decode($oldS, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    // 4. A typed "{{/if}}" or "{{#if x}}" cannot change which blocks show.
    if ($im[1]) {
        $t = $n;
        foreach ($keys as $k) {
            if (!isset($raw[$k]) && !in_array($k, $commonKeys, true)) { $t[$k] = 'a{{/if}}b{{#if zz}}c'; }
        }
        $outT = loadEmailTemplate($name, $t);
        ok("$name: typed block tags stay literal text", strpos($outT, 'a{{/if}}b{{#if zz}}c') !== false
            && substr_count($outT, 'a{{/if}}b{{#if zz}}c') === array_sum(array_intersect_key($occ, array_flip(array_diff($keys, array_keys($raw), $commonKeys)))));
    }
}

// The real senders' template data shapes (in-memory, no send): conference customer + gym admin.
$conf = loadEmailTemplate('conference-enquiry-customer.html', [
    'contact_person' => '<b>x</b>', 'company_name' => 'A&B', 'inquiry_reference' => 'CONF-1', 'room_name' => 'Hall',
    'event_date' => 'May 1, 2027', 'start_time' => '09:00', 'end_time' => '17:00', 'number_of_attendees' => 20,
    'total_amount' => '1,000', 'event_type' => '', 'catering_required' => true, 'av_equipment' => '',
    'special_requirements' => '', 'packages_html' => '',
]);
ok('conference customer: integer and boolean values still render', strpos($conf, '20') !== false && strpos($conf, '&lt;b&gt;x&lt;/b&gt;') !== false
    && strpos($conf, 'A&amp;B') !== false);

// The four senders: escape-once helper, plain-text subjects, gym staff link.
ok('rh_email_h: sanitized value escaped once', rh_email_h(sanitizeString("O'Brien & \"Co\"")) === 'O&#039;Brien &amp; &quot;Co&quot;');
ok('rh_email_h: raw markup escaped', rh_email_h('<b onclick="x">A & B</b>') === '&lt;b onclick=&quot;x&quot;&gt;A &amp; B&lt;/b&gt;');
ok('rh_email_h: plain value unchanged', rh_email_h('Liwonde Sun Hotel 12') === 'Liwonde Sun Hotel 12');
ok('rh_email_h: integer and non-scalar', rh_email_h(20) === '20' && rh_email_h(['x']) === '' && rh_email_h(null) === '');
ok('rh_email_plain: entities decoded for a subject', rh_email_plain('New Gym Booking Request - O&apos;Brien &amp; Sons') === "New Gym Booking Request - O'Brien & Sons");
ok('rh_email_plain: Rosalyn&#039;s reads as text', rh_email_plain('Rosalyn&#039;s Hotel') === "Rosalyn's Hotel");
ok('rh_email_plain: no line break can reach the header', !preg_match('/[\x00-\x1F\x7F]/', rh_email_plain("A&#13;&#10;Bcc: x@example.invalid\r\nB&#x0A;C"))
    && rh_email_plain("A\r\nBcc: x@example.invalid") === 'A Bcc: x@example.invalid');
ok('rh_email_plain: plain value unchanged', rh_email_plain('Conference Enquiry Received - Hotel [CONF-1]') === 'Conference Enquiry Received - Hotel [CONF-1]');

$srcOf = static function (string $fn): string {
    $r = new ReflectionFunction($fn);
    $lines = file($r->getFileName());
    return implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
};
foreach (['sendConferenceEnquiryEmail', 'sendConferenceAdminNotificationEmail', 'sendGymBookingEmail', 'sendGymAdminNotificationEmail'] as $fn) {
    $src = $srcOf($fn);
    ok("$fn: fallback HTML escapes once (no bare htmlspecialchars)", strpos($src, 'htmlspecialchars(') === false && strpos($src, 'rh_email_h(') !== false);
    ok("$fn: subject is plain text", (bool)preg_match('/return sendEmail\(\s*[^,]+,\s*[^,]+,\s*(?:\/\/[^\n]*\s*)?rh_email_plain\(/s', $src));
}
$gymAdminSrc = $srcOf('sendGymAdminNotificationEmail');
ok('gym staff email: link points at the gym enquiries page', strpos($gymAdminSrc, "'/admin/gym-inquiries.php'") !== false
    && strpos($gymAdminSrc, 'conference-management') === false);
$gymOut = loadEmailTemplate('gym-booking-admin.html', ['name' => 'X', 'admin_url' => 'https://hotel.example.invalid/admin/gym-inquiries.php']);
ok('gym staff email: rendered button opens gym-inquiries.php', strpos($gymOut, 'href="https://hotel.example.invalid/admin/gym-inquiries.php"') !== false
    && strpos($gymOut, 'conference-management') === false);
ok('conference staff email: still opens conference management', strpos($srcOf('sendConferenceAdminNotificationEmail'), '/admin/conference-management.php') !== false);

// Gym staff email shows the phone: the sender passes the template's {{phone_number}} tag.
ok('gym staff email: sender passes phone_number', strpos($gymAdminSrc, "'phone_number' => \$data['phone']") !== false);
$gymPh = loadEmailTemplate('gym-booking-admin.html', ['name' => 'X', 'phone_number' => '+265 999 123 456']);
ok('gym staff email: phone renders', strpos($gymPh, '+265 999 123 456') !== false);

// Recipient display names are plain text (decoded), like the subjects.
foreach (['sendConferenceEnquiryEmail' => 'contact_person', 'sendGymBookingEmail' => 'name'] as $fn => $nameKey) {
    ok("$fn: recipient name is plain text", (bool)preg_match('/return sendEmail\(\s*[^,]+,\s*rh_email_plain\(\$data\[\'' . $nameKey . '\'\]\),/s', $srcOf($fn)));
}
ok('rh_email_plain: sanitized name reads as typed', rh_email_plain(sanitizeString("Seán O'Brien & Co")) === "Seán O'Brien & Co");

restore_error_handler();
echo "\nemail_template_escape_test: $pass passed, $fail failed, $warn warnings\n";
exit($fail === 0 && $warn === 0 ? 0 : 1);
