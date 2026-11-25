<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Email / PDF wording checks (owner 2026-11-25). PURE: no database, no network, nothing sent.
 * The functions under test are cut from their source files and loaded on their own (the files need the database).
 *  1. rh_rate_label(): a VAT / levy rate prints as written (17.5, 16.5, 17), never rounded to a whole number.
 *  2. booking_reminder: the {{urgency_notice}} value never carries a {{tag}} (tag values are not filled again).
 *  3. tentative_quotation: the VAT rate is never number_format(x, 0) ("18" for 17.5).
 *  4. payment_invoice: {{total_amount}} stays the balance (owner rule 2026-11-15), the default is unchanged.
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

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
