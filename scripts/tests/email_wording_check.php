<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Email / PDF wording checks (owner 2026-11-25). PURE: no database, no network, nothing sent.
 * The functions under test are cut from their source files and loaded on their own (the files need the database).
 *  1. rh_rate_label(): a VAT / levy rate prints as written (17.5, 16.5, 17), never rounded to a whole number.
 *  2. booking_reminder: the {{urgency_notice}} value never carries a {{tag}} (tag values are not filled again).
 *  3. tentative_quotation: the VAT rate is never number_format(x, 0) ("18" for 17.5).
 *  4. payment_invoice: {{total_amount}} stays the balance (owner rule 2026-11-15), the default is unchanged.
 *  5. No phone number set: no "a@b.c or ." left (template joiners dropped, hard-coded sentences via rh_email_or_call()).
 *  6. Invoice email {{check_out}} = the attached PDF's format; the receipt email never says "VAT Incl." in every mode.
 *  7. Template previews: this hotel's own reference prefixes, one sample balance; pre-arrival reminder wording.
 *
 * Usage (repo root): php scripts/tests/email_wording_check.php
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
/** The source between two markers (the second one excluded). */
function cut(string $src, string $from, string $to): string
{
    $a = strpos($src, $from);
    $b = $a === false ? false : strpos($src, $to, $a + strlen($from));
    if ($a === false || $b === false) { echo "FAIL could not find '$from' in the source\n"; exit(1); }
    return substr($src, $a, $b - $a);
}

if (!defined('BALANCE_TOLERANCE')) { define('BALANCE_TOLERANCE', 0.01); }
$theme = (string)file_get_contents($root . '/config/document-theme.php');
$email = (string)file_get_contents($root . '/config/email.php');
$invoice = (string)file_get_contents($root . '/config/invoice.php');
$settingsPage = (string)file_get_contents($root . '/admin/booking-settings.php');
$S = []; // settings the getSetting() stub reads
function getSetting($k, $d = '') { global $S; return array_key_exists($k, $S) ? $S[$k] : $d; }

/* ---- 1. rh_rate_label ---- */
eval(cut($theme, "if (!function_exists('rh_rate_label'))", "if (!function_exists('rh_pdf_vat_rows'))"));
check(rh_rate_label(17.5) === '17.5', 'rate: 17.5 prints 17.5 (not 18)', rh_rate_label(17.5));
check(rh_rate_label(16.5) === '16.5', 'rate: 16.5 prints 16.5 (not 17)', rh_rate_label(16.5));
check(rh_rate_label(17.0) === '17', 'rate: a whole rate prints with no decimals', rh_rate_label(17.0));
check(rh_rate_label(16.4999) === '16.5', 'rate: float noise is rounded to 2 places', rh_rate_label(16.4999));
check(rh_rate_label(0.0) === '0', 'rate: 0 prints 0 (no empty string)', rh_rate_label(0.0));
check(rh_rate_label(1.25) === '1.25', 'rate: a levy of 1.25 keeps both decimals', rh_rate_label(1.25));
check(strpos(cut($theme, "function rh_pdf_vat_rows", "if (!function_exists('rh_pdf_template_is_stock'))"), 'rh_rate_label($rate)') !== false,
    'rate: the PDF VAT row uses the same helper (email and PDF agree)');

/* ---- 2. booking_reminder: no tag inside the urgency_notice value ---- */
$rem = cut($email, 'function sendBookingReminderEmail(', 'function sendTentativeBookingConfirmedEmail(');
preg_match_all('/\$urgencyLine\s*=\s*(.*?);\s*$/ms', $rem, $m);
check(count($m[1]) >= 4, 'reminder: every urgency line is found', (string)count($m[1]));
$withTag = array_filter($m[1], static function ($s) { return preg_match('/\{\{[a-z_]+\}\}/', $s) === 1; });
check($withTag === [], 'reminder: no urgency line carries a {{tag}} (guests would see it literally)', implode(' | ', $withTag));
check(strpos($rem, "htmlspecialchars((string)\$email_site_name, ENT_QUOTES, 'UTF-8')") !== false && preg_match('/global \$pdo, \$email_site_name;/', $rem) === 1,
    'reminder: the upcoming-stay line names the hotel itself, escaped');
check(strpos($rem, "htmlspecialchars(\$referenceDate)") === false, 'reminder fallback: the date is formatted as in the template, not raw Y-m-d');

/* ---- 3. tentative_quotation: VAT rate never rounded to a whole number ---- */
$tq = cut($email, "\$vat_rate    = (float)(\$booking['vat_rate'] ?? 0);", "renderBookingEmailTemplate('tentative_quotation'");
check(strpos($tq, 'number_format($vat_rate, 0)') === false, 'quotation: no number_format($vat_rate, 0) left');
check(substr_count($tq, '$vat_rate_label') >= 3, 'quotation: the fallback row and {{vat_rate}} both use the one label');
check(strpos($tq, "getSetting('vat_rate', 0)") !== false, 'quotation: a booking without a stored rate falls back to Hotel Settings, as the PDF does');

/* ---- 4. payment_invoice: {{total_amount}} stays the BALANCE (owner rule a148654, 2026-11-15: the last row is the balance) ---- */
if (!function_exists('vat_document_value')) { function vat_document_value(string $v): string { return 'Included ' . $v; } }
eval(cut($invoice, 'function rh_invoice_email_money_vars(', '/**'));
$v = rh_invoice_email_money_vars(['total' => 1250.0, 'paid' => 500.0, 'balance' => 750.0, 'subtotal' => 1000.0, 'vat' => 150.0, 'levy' => 100.0, 'vat_mode' => 'exclusive'], 'MWK');
check($v['total_amount'] === 'MWK 750.00' && $v['balance_due_amount'] === 'MWK 750.00' && $v['invoice_total_amount'] === 'MWK 1,250.00', 'invoice: Balance due row ({{total_amount}}) = the balance; the invoice total has its own tag', $v['total_amount']);
$def = cut($email, "'payment_invoice' => [", "'conference_invoice' => [");
check(preg_match("/\['Balance due',\s*'\{\{total_amount\}\}', true\]/", $def) === 1, 'default: unchanged, so stored copies stay stock');

/* ---- 5. Empty phone: no dangling "or ." ---- */
eval(cut($email, 'function rh_email_drop_empty_phone(', '/**' . "\n" . ' * PURE. Fill one booking email template row'));
$t = 'contact us at <a href="mailto:x">x</a> or {{phone_main}}.';
check(rh_email_drop_empty_phone($t, '') === 'contact us at <a href="mailto:x">x</a>.', 'phone: empty -> "or {{phone_main}}" dropped', rh_email_drop_empty_phone($t, ''));
check(rh_email_drop_empty_phone($t, '+265 1 234') === $t, 'phone: set -> template unchanged');
check(rh_email_drop_empty_phone('<a>x</a> &middot; {{phone_main}}.', ' ') === '<a>x</a>.', 'phone: blank -> "&middot; {{phone_main}}" dropped');
check(rh_email_drop_empty_phone('{{address}}<br>{{contact_phone}}&nbsp;|&nbsp;<a>e</a>', '') === '{{address}}<br><a>e</a>', 'phone: footer "phone | email" keeps only the email');
check(rh_email_drop_empty_phone('<a>x</a> or {{contact_phone}} to confirm.', '') === '<a>x</a> to confirm.', 'phone: conference "or {{contact_phone}}" dropped');
$S = ['phone_main' => ''];
check(rh_email_or_call() === '', 'phone: rh_email_or_call() empty when no phone is set');
$S = ['phone_main' => '+265 <1>'];
check(rh_email_or_call() === ' or call +265 &lt;1&gt;', 'phone: rh_email_or_call() escapes the number', rh_email_or_call());
$S = [];
check(strpos($email, "or call ' . getSetting('phone_main')") === false && strpos($email, "or call ' . htmlspecialchars((string)getSetting('phone_main')) . '.</p>") === false,
    'phone: no hard-coded "or call <phone>." sentence left');
check(preg_match("/contact us at '\\s*\\.\\s*htmlspecialchars\\(\\(string\\)getSetting\\('phone_main'/", $email) === 0, 'phone: the quotation sentence no longer ends "contact us at ."');
check(strpos(cut($email, 'function rh_render_booking_email_template_row(', '$replace = [];'), 'rh_email_drop_empty_phone(') !== false, 'phone: every booking template goes through the drop');

/* ---- 6. Invoice check-out date; receipt VAT label ---- */
check(strpos($invoice, "\$checkOut = !empty(\$booking['check_out_date']) ? date('j F Y'") !== false
    && strpos($invoice, "\$check_out = date('j F Y'") !== false, 'invoice: email {{check_out}} = the PDF format (j F Y)');
$rcpt = cut($email, "'payment_receipt' => [", "'payment_receipt_document' => [");
check(strpos($rcpt, 'VAT Incl.') === false && strpos($rcpt, '{{receipt_details_html}}') !== false,
    'receipt: no "VAT Incl." label; the VAT line comes from the receipt block in the hotel\'s VAT mode (receipt_email_template_check.php)');

/* ---- 7. Previews and the pre-arrival reminder ---- */
check(strpos($settingsPage, 'LSH') === false, 'preview: no Liwonde prefix hard-coded (it showed at the sister hotel too)');
require_once $root . '/includes/finance-sequences.php';
eval(cut($settingsPage, "if (!function_exists('rh_template_preview_refs'))", '// Handle enable/disable via GET parameter'));
function rh_booking_reference_prefix(): string { return 'RBH'; }
$S = ['credit_note_prefix' => 'CN', 'document_number_prefix' => 'RBH'];
$r = rh_template_preview_refs();
$y = date('Y');
check($r['booking'] === 'RBH' . $y . '423468' && $r['quote'] === 'QT-RBH' . $y . '423468', 'preview: booking + quotation reference use the hotel prefix', json_encode($r));
check($r['credit_note'] === 'RBH-CN-' . $y . '-000001', 'preview: credit note number in the real format', $r['credit_note']);
$S = [];
check(strpos($settingsPage, 'number_format(3500, 2)') === false && substr_count($settingsPage, 'number_format($pvTotal - 1000, 2)') >= 4, 'preview: balance_due and amount_due agree in both preview lists');
check(substr_count($settingsPage, "'{{days_until_arrival}}'       => '14 days'") === 2, 'preview: days until arrival matches the +14 day check-in sample');
$pre = cut($email, 'function rh_prearrival_payment_email_build(', 'function sendPrearrivalPaymentReminderEmail(');
check(strpos($pre, "str_replace(', as set out in our payment policy below', ''") !== false, 'pre-arrival: no "payment policy below" when no policy is set');
$preDef = cut($email, "\$defaults['prearrival_payment_reminder'] = [", "\$GLOBALS['hotel_booking_template_defaults']");
check(strpos($preDef, ', as set out in our payment policy below') !== false, 'pre-arrival: the default still holds the exact phrase the build drops');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
