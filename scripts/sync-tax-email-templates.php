<?php
/**
 * Resync payment_invoice, conference_invoice, payment_receipt email templates
 * with updated VAT/levy rows. Idempotent — safe to re-run.
 *
 * Run: php scripts/sync-tax-email-templates.php
 */
define('ROSALYNS_HOTEL_BOOTSTRAP', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';

$keys = ['payment_invoice', 'conference_invoice', 'payment_receipt'];

// Pull the freshly-built defaults straight from the PHP code
$defaults = [];
$allDefaults = (function (): array {
    // Grab the defaults array by temporarily capturing the upsert calls.
    // Easier: just re-invoke the ensure function after clearing existing rows.
    // Instead we rebuild only the keys we need inline.
    return [];
})();

// We build the HTML by calling the helpers directly.
$invoice_html = hotel_premium_email_html(
    'Your invoice {{invoice_number}} from {{site_name}} is ready',
    hotel_premium_email_body('{{guest_name}}',
        '<p style="margin:0 0 16px;">Thank you for choosing to stay with us. We hope you had a wonderful experience. Please find attached the official invoice (<strong>{{invoice_number}}</strong>) for your recent stay.</p>'
        . '<p style="margin:0;">A brief summary is shown below. The full invoice PDF is attached to this email.</p>'
    )
    . hotel_premium_email_summary_rows('Stay Summary', [
        ['Booking Reference',              '{{booking_reference}}'],
        ['Invoice Number',                 '{{invoice_number}}'],
        ['Check-out',                      '{{check_out}}'],
        ['Sub-total',                      '{{subtotal_amount}}'],
        ['Tourism Levy ({{levy_rate}}%)',   '{{levy_amount}}'],
        ['VAT ({{vat_rate}}%)',             '{{vat_amount}}'],
        ['Total Due',                      '{{total_amount}}', true],
    ])
    . '<tr><td style="padding:0 48px 8px;text-align:center;">{{vat_number_html}}</td></tr>'
    . hotel_premium_email_cta('{{invoice_link}}', 'View Full Invoice')
);

$conference_invoice_html = hotel_premium_email_html(
    'Conference invoice for {{inquiry_reference}} — {{site_name}}',
    hotel_premium_email_body('{{contact_person}}',
        '<p style="margin:0 0 16px;">Thank you for hosting with us. Your conference invoice PDF is attached for your records.</p>'
        . '<p style="margin:0;">For any adjustments, contact us at <a href="mailto:{{contact_email}}" style="color:#524b3f;">{{contact_email}}</a> or {{contact_phone}}.</p>'
    )
    . hotel_premium_email_summary_rows('Conference Summary', [
        ['Reference',             '{{inquiry_reference}}'],
        ['Company',               '{{company_name}}'],
        ['Conference Room',        '{{conference_room}}'],
        ['Event Date',             '{{event_date}}'],
        ['Event Time',             '{{event_time}}'],
        ['Sub-total',              '{{subtotal_amount}}'],
        ['VAT ({{vat_rate}}%)',    '{{vat_amount}}'],
        ['Total',                  '{{total_amount}}', true],
    ])
    . '<tr><td style="padding:0 48px 8px;text-align:center;">{{vat_number_html}}</td></tr>',
    '{{contact_email}}'
);

$receipt_html = hotel_premium_email_html(
    'Your payment receipt {{receipt_number}} from {{site_name}}',
    hotel_premium_email_body('{{guest_name}}',
        '<p style="margin:0 0 16px;">Thank you for your payment. Your receipt PDF is attached for your records.</p>'
        . '<p style="margin:0;">Questions? Contact us at <a href="mailto:{{contact_email}}" style="color:#524b3f;">{{contact_email}}</a>.</p>'
    )
    . hotel_premium_email_summary_rows('Payment Summary', [
        ['Receipt No.',       '{{receipt_number}}'],
        ['Reference',         '{{payment_reference}}'],
        ['Type',              '{{booking_type}}'],
        ['Payment Date',      '{{payment_date}}'],
        ['VAT Incl.',         '{{vat_amount}}'],
        ['Amount Received',   '{{total_amount}}', true],
    ])
    . '<tr><td style="padding:0 48px 8px;text-align:center;">{{vat_number_html}}</td></tr>'
);

$updates = [
    'payment_invoice' => [
        'name'    => 'Room Invoice Email',
        'subject' => 'Your Invoice {{invoice_number}} — {{site_name}}',
        'html'    => $invoice_html,
    ],
    'conference_invoice' => [
        'name'    => 'Conference Invoice Email',
        'subject' => 'Conference Invoice — {{site_name}} · {{inquiry_reference}}',
        'html'    => $conference_invoice_html,
    ],
    'payment_receipt' => [
        'name'    => 'Payment Receipt Email',
        'subject' => 'Payment Receipt {{receipt_number}} — {{site_name}}',
        'html'    => $receipt_html,
    ],
];

foreach ($updates as $key => $def) {
    $existing = getBookingEmailTemplateConfig($key, []);
    upsertBookingEmailTemplateConfig(
        $key,
        $def['name'],
        $def['subject'],
        $def['html'],
        (string)($existing['text_body'] ?? ''),
        (int)($existing['is_active'] ?? 1)
    );
    echo "  Updated: {$key}" . PHP_EOL;
}

echo PHP_EOL . 'Done. 3 email templates synced with VAT/levy rows.' . PHP_EOL;
