<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * The payment_receipt email template drives the receipt email (owner 2026-11-26). PURE: no database, no network,
 * nothing sent. The functions under test are cut from config/receipts.php and loaded on their own.
 *  1. {{receipt_details_html}}: every figure of the receipt in each VAT mode (exclusive / inclusive / off), levy split,
 *     tip, REFUND and CORRECTED banners, whole-group block, footer; guest data escaped; one row of the email table.
 *  2. The POS-style body (template switched off) is built from the same pieces, so both bodies print the same figures.
 *  3. Template on: the tag is filled once; a stored copy without the tag gets the receipt at the end; no phone = no
 *     "or ."; a guest name that reads like a {{tag}} is never filled again.
 *  4. The send path uses the template when it is on, the POS body when it is off; the plain text never gets the block.
 *  5. The default payment_receipt template carries the tag; the tag is listed for the template in the editor.
 *
 * Usage (repo root): php scripts/tests/receipt_email_template_check.php
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function cut(string $src, string $from, string $to): string
{
    $a = strpos($src, $from);
    $b = $a === false ? false : strpos($src, $to, $a + strlen($from));
    if ($a === false || $b === false) { echo "FAIL could not find '$from' in the source\n"; exit(1); }
    return substr($src, $a, $b - $a);
}

if (!defined('BALANCE_TOLERANCE')) { define('BALANCE_TOLERANCE', 0.01); }
$S = ['currency_symbol' => 'MWK', 'site_name' => 'Test Hotel', 'hotel_address' => '1 Lake Road', 'phone_main' => '+265 1 000',
      'receipt_footer' => 'Thank you & see you soon.', 'vat_enabled' => '1', 'vat_rate' => '17.5', 'vat_number' => 'VAT-123'];
$VM = 'exclusive';
function getSetting($k, $d = '') { global $S; return array_key_exists($k, $S) ? $S[$k] : $d; }
function vat_mode(): string { global $VM; return $VM; }
function rh_guest_contact_email(): string { return 'stay@example.invalid'; }
function hotel_email_logo_url(): string { return ''; }
function hotel_brand_tokens(): array
{
    $k = ['page', 'card', 'rule', 'accent', 'panel', 'danger_bg', 'text', 'muted_dark', 'muted', 'danger', 'success'];
    return ['colors' => array_fill_keys($k, '#000001')];
}

$rcSrc = (string)file_get_contents($root . '/config/receipts.php');
$emailSrc = (string)file_get_contents($root . '/config/email.php');
$theme = (string)file_get_contents($root . '/config/document-theme.php');
eval(cut($theme, "if (!function_exists('rh_rate_label'))", "if (!function_exists('rh_pdf_vat_rows'))"));
eval(cut($emailSrc, 'function rh_email_drop_empty_phone(', '/** " or call <phone>"'));
eval(cut($rcSrc, "if (!function_exists('receipt_group_payment_lines'))", "if (!function_exists('receipt_placeholders'))"));
eval(cut($rcSrc, "if (!function_exists('receipt_email_parts'))", "if (!function_exists('receipt_h'))"));
eval(cut($rcSrc, "if (!function_exists('receipt_apply_theme'))", "if (!function_exists('receipt_build_restaurant_email_html_raw'))"));
eval(cut($rcSrc, "if (!function_exists('receipt_email_details_html'))", "if (!function_exists('receipt_build_pos_style_html'))"));

$basePay = ['receipt_number' => 'RCP-2026-000042', 'payment_reference' => 'PAY-77', 'booking_reference' => 'LSH2026001',
    'payment_date' => '2026-11-20', 'payment_method' => 'bank_transfer', 'payment_amount' => 10000, 'vat_amount' => 1750,
    'total_amount' => 11750, 'vat_rate' => 17.5, 'tip_amount' => 0, 'payment_type' => 'full_payment', 'booking_type' => 'room',
    'recorded_by_name' => 'Front Desk'];
$baseCtx = ['guest_name' => 'Ana <b>Banda</b>', 'guest_email' => 'ana@example.invalid', 'guest_phone' => '+265 999',
    'description' => 'Room 4 stay'];
$pdo = new class extends PDO { public function __construct() {} };

/* ---- 1 + 2. the block, each case; the POS body prints the same pieces ---- */
$cases = [
    'exclusive' => ['exclusive', [], [], ['Sub-total (net)', 'MWK 10,000.00', 'VAT (17.5%)', 'MWK 1,750.00', 'TOTAL RECEIVED', 'MWK 11,750.00', 'Bank Transfer', 'VAT Reg. No.: VAT-123'], ['All prices include VAT']],
    'inclusive' => ['inclusive', [], [], ['All prices include VAT at 17.5%', 'MWK 11,750.00', 'TOTAL RECEIVED'], ['VAT (17.5%)', 'Sub-total (net)']],
    'off'       => ['off', [], [], ['MWK 11,750.00', 'TOTAL RECEIVED'], ['VAT (', 'All prices include VAT', 'Sub-total (net)']],
    'levy'      => ['exclusive', ['payment_amount' => 10100, 'vat_amount' => 1750, 'total_amount' => 11850, 'vat_rate' => 17.33],
                    ['vat_split' => ['net' => 10000, 'levy' => 100, 'rate' => 17.5]],
                    ['Net (excl. VAT and levy)', 'MWK 10,000.00', 'VAT (17.5%)', 'Tourism levy', 'MWK 100.00', 'MWK 11,850.00'], ['VAT (17.33%)']],
    'tip'       => ['exclusive', ['tip_amount' => 500], [], ['Tip', '+ MWK 500.00', 'GRAND TOTAL', 'MWK 12,250.00'], ['TOTAL RECEIVED']],
    'refund'    => ['exclusive', ['payment_type' => 'refund'], [], ['>REFUND<', 'REFUNDED', 'MWK 11,750.00'], ['TOTAL RECEIVED', 'CORRECTED']],
    'corrected' => ['exclusive', [], ['corrected_at' => '2026-11-21 10:00:00'], ['CORRECTED RECEIPT', 'replaces the earlier copy of receipt RCP-2026-000042'], ['>REFUND<']],
    'group'     => ['exclusive', [], ['group_payment' => ['count' => 2, 'total' => 23500, 'legs' => [
                        ['payment_id' => 1, 'booking_reference' => 'LSH2026001', 'room' => 'Room 4', 'receipt_number' => 'RCP-2026-000042', 'total_amount' => 11750],
                        ['payment_id' => 2, 'booking_reference' => 'LSH2026002', 'room' => 'Room 5', 'receipt_number' => 'RCP-2026-000043', 'total_amount' => 11750]]]],
                    ['Whole-group payment: MWK 23,500.00 split across 2 rooms', 'RCP-2026-000043'], []],
];
foreach ($cases as $name => [$mode, $payX, $ctxX, $must, $mustNot]) {
    $VM = $mode;
    $pay = $payX + $basePay;
    $ctx = $ctxX + $baseCtx;
    $block = receipt_email_details_html($pay, $ctx);
    $missing = array_values(array_filter($must, static fn ($m) => strpos($block, $m) === false));
    check($missing === [], "block/$name: every figure and label is there", implode(' | ', $missing));
    $extra = array_values(array_filter($mustNot, static fn ($m) => strpos($block, $m) !== false));
    check($extra === [], "block/$name: nothing from another mode or case", implode(' | ', $extra));
    $p = receipt_email_parts($pay, $ctx);
    $raw = receipt_build_pos_style_html_raw($pay, $ctx, $pdo);
    check(strpos($raw, $p['details']) !== false && strpos($raw, $p['totalsHtml']) !== false && strpos($raw, $p['groupHtml']) !== false
        && strpos($raw, $p['voidBanner']) !== false && strpos($raw, $p['footerHtml']) !== false,
        "pos/$name: the switched-off body prints the same details, totals, banner, group block and footer");
}
$VM = 'exclusive';
$block = receipt_email_details_html($basePay, $baseCtx);
check(strpos($block, '<tr><td') === 0 && substr($block, -10) === '</td></tr>', 'block: one row of the email table');
check(stripos($block, '<html') === false && stripos($block, '<body') === false, 'block: no document shell inside the template');
check(strpos($block, 'Ana &lt;b&gt;Banda&lt;/b&gt;') !== false && strpos($block, '<b>Banda') === false, 'block: guest name escaped');
check(strpos($block, 'Thank you &amp; see you soon.') !== false, 'block: receipt footer shown, escaped');
check(strpos($block, 'LSH2026001') !== false && strpos($block, 'PAY-77') !== false && strpos($block, 'Front Desk') !== false
    && strpos($block, '20 Nov 2026') !== false, 'block: booking ref, payment ref, recorded by and date');
check(strpos($block, '#3f3933') === false && strpos($block, '#000001') !== false, 'block: hotel brand colours applied (receipt_apply_theme)');

/* ---- 3. template on ---- */
$ph = ['{{site_name}}' => 'Test Hotel', '{{guest_name}}' => 'Ana', '{{receipt_number}}' => 'RCP-2026-000042',
       '{{contact_email}}' => 'stay@example.invalid', '{{contact_phone}}' => '+265 1 000'];
$tpl = '<html><body><p>Dear {{guest_name}}, receipt {{receipt_number}} from {{site_name}}.</p><table>{{receipt_details_html}}</table></body></html>';
$out = receipt_email_body_from_template($tpl, $ph, '<tr><td>BLOCK</td></tr>');
check(substr_count($out, 'BLOCK') === 1 && strpos($out, '{{') === false && strpos($out, 'Dear Ana, receipt RCP-2026-000042 from Test Hotel.') !== false,
    'template: tag filled once, every tag filled');
$out = receipt_email_body_from_template('<html><body><p>Hi {{guest_name}}</p></body></html>', $ph, '<tr><td>BLOCK</td></tr>');
check(substr_count($out, 'BLOCK') === 1 && preg_match('~BLOCK</td></tr></table></body></html>$~', $out) === 1,
    'template: a stored copy without the tag gets the receipt before </body>');
$out = receipt_email_body_from_template('<p>Hi {{guest_name}}</p>', $ph, '<tr><td>BLOCK</td></tr>');
check(preg_match('~^<p>Hi Ana</p><table[^>]*><tr><td>BLOCK</td></tr></table>$~', $out) === 1, 'template: no </body> = receipt added at the end');
$noPhone = ['{{contact_phone}}' => ''] + $ph;
$out = receipt_email_body_from_template('<p>Call <a href="mailto:{{contact_email}}">{{contact_email}}</a> or {{contact_phone}}.</p>{{receipt_details_html}}', $noPhone, 'X');
check(strpos($out, ' or .') === false && strpos($out, '</a>.') !== false, 'template: no phone set = no "or ."');
$out = receipt_email_body_from_template('{{receipt_details_html}}<p>{{guest_name}}</p>', ['{{guest_name}}' => '{{site_name}}'] + $ph, '<td>{{receipt_number}} $1 \\0</td>');
check(strpos($out, '<td>{{receipt_number}} $1 \\0</td>') !== false && strpos($out, '<p>{{site_name}}</p>') !== false,
    'template: guest data that reads like a {{tag}} or $1 is printed as written, never filled again');

/* ---- 4. the send path ---- */
$send = cut($rcSrc, 'function receipt_send_email(', "if (!function_exists('receipt_auto_send')");
check(preg_match("~is_active'\] \?\? 1\) === 1\) \{\s*\\\$emailBody = receipt_email_body_from_template\(\(string\)\\\$receiptTemplate\['html_body'\], \\\$placeholders, receipt_email_details_html\(\\\$payment, \\\$context\)\);~", $send) === 1,
    'send: template on = the template body with the receipt block');
check(preg_match("~\} else \{\s*\\\$emailBody = receipt_build_pos_style_html\(\\\$payment, \\\$context, \\\$pdo\);~", $send) === 1,
    'send: template off or empty = the POS-style body as before');
check(strpos($send, "\$textBody = str_replace('{{receipt_details_html}}', '', \$textBody);") !== false, 'send: the plain text never gets the block');
check(strpos(cut($rcSrc, "if (!function_exists('receipt_placeholders'))", "if (!function_exists('receipt_log_event'))"), 'receipt_details_html') === false,
    'send: the tag is not in receipt_placeholders() (that list also feeds the WhatsApp receipt)');

/* ---- 5. default template + editor tag list ---- */
$def = cut($emailSrc, "'payment_receipt' => [", "'payment_receipt_document' => [");
check(substr_count($def, '{{receipt_details_html}}') === 1, 'default: the receipt email template carries {{receipt_details_html}} once');
check(strpos($def, 'Thank you for your payment') === false && strpos($def, 'Payment Summary') === false,
    'default: no fixed "thank you for your payment" (refunds use it too) and no second summary table');
require_once $root . '/includes/email-template-tags.php';
check(in_array('receipt_details_html', rh_email_template_tag_map()['payment_receipt'] ?? [], true)
    && isset(rh_email_tag_definitions()['receipt_details_html']), 'editor: the tag is listed and explained for the receipt email');
check(strpos(rh_email_template_tag_note('payment_receipt'), 'laid out automatically') === false, 'editor: the note no longer says the body is automatic');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
