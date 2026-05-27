<?php
/**
 * One-shot script: push premium email theme HTML to all 14 email
 * (non-document) rows in the live booking_email_templates table.
 *
 * Run once: php scripts/sync-premium-email-templates.php
 */
define('ROSALYNS_HOTEL_BOOTSTRAP', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';

if (!function_exists('ensureBookingEmailTemplatesTable') || !function_exists('upsertBookingEmailTemplateConfig')) {
    echo 'ERROR: DB helpers not available.' . PHP_EOL;
    exit(1);
}

ensureBookingEmailTemplatesTable();

// Helper to build the standard guest greeting + body row
function _p(string $html): string
{
    return '<p style="margin:0 0 16px;">' . $html . '</p>';
}

function _p0(string $html): string
{
    return '<p style="margin:0;">' . $html . '</p>';
}

$muted    = 'color:#9b8f7e;text-align:center;font-style:italic;';
$policyRow = '<tr><td style="padding:0 48px 48px;font-size:12px;line-height:1.8;' . $muted . '">{{payment_policy}}</td></tr>';

$defaults = [

    /* ── Room booking emails ────────────────────────────────────── */

    'booking_received' => [
        'name'    => 'Booking Received (Customer)',
        'subject' => 'Booking Received — {{site_name}} · {{booking_reference}}',
        'html'    => hotel_premium_email_html(
            'Your booking request has been received — Reference: {{booking_reference}}',
            hotel_premium_email_body('{{guest_name}}',
                _p('Thank you for choosing <strong>{{site_name}}</strong>. We have received your booking request for <strong>{{room_name}}</strong> and will confirm it shortly.')
                . _p0('We will be in touch within 24 hours. For immediate assistance contact us at <a href="mailto:{{contact_email}}" style="color:#524b3f;">{{contact_email}}</a>.')
            )
            . hotel_premium_email_summary_rows('Booking Summary', [
                ['Reference',  '{{booking_reference}}'],
                ['Room',       '{{room_name}}'],
                ['Check-in',   '{{check_in_date_formatted}}'],
                ['Check-out',  '{{check_out_date_formatted}}'],
                ['Nights',     '{{number_of_nights}}'],
                ['Guests',     '{{number_of_guests}}'],
                ['Total',      '{{currency_symbol}} {{total_amount_formatted}}', true],
            ])
            . $policyRow
        ),
    ],

    'booking_confirmed' => [
        'name'    => 'Booking Confirmed (Customer)',
        'subject' => 'Booking Confirmed — {{site_name}} · {{booking_reference}}',
        'html'    => hotel_premium_email_html(
            'Your booking is confirmed — Reference: {{booking_reference}}',
            hotel_premium_email_body('{{guest_name}}',
                _p('Your booking at <strong>{{site_name}}</strong> is confirmed. We look forward to welcoming you to <strong>{{room_name}}</strong>.')
                . _p0('Check-in from <strong>{{check_in_time}}</strong> &middot; Check-out by <strong>{{check_out_time}}</strong>.')
            )
            . hotel_premium_email_summary_rows('Confirmed Booking', [
                ['Reference',  '{{booking_reference}}'],
                ['Room',       '{{room_name}}'],
                ['Check-in',   '{{check_in_date_formatted}}'],
                ['Check-out',  '{{check_out_date_formatted}}'],
                ['Nights',     '{{number_of_nights}}'],
                ['Guests',     '{{number_of_guests}}'],
                ['Total',      '{{currency_symbol}} {{total_amount_formatted}}', true],
            ])
            . $policyRow
        ),
    ],

    'booking_cancelled' => [
        'name'    => 'Booking Cancelled (Customer)',
        'subject' => 'Booking Cancelled — {{booking_reference}}',
        'html'    => hotel_premium_email_html(
            'Your booking {{booking_reference}} has been cancelled',
            hotel_premium_email_body('{{guest_name}}',
                _p('<span style="color:#b0552b;font-weight:600;">Your booking has been cancelled.</span> If you believe this is an error or wish to rebook, please contact us.')
                . _p0('We apologise for any inconvenience. For assistance: <a href="mailto:{{contact_email}}" style="color:#524b3f;">{{contact_email}}</a> &middot; {{phone_main}}.')
            )
            . hotel_premium_email_summary_rows('Cancelled Booking', [
                ['Reference',  '{{booking_reference}}'],
                ['Room',       '{{room_name}}'],
                ['Check-in',   '{{check_in_date_formatted}}'],
                ['Check-out',  '{{check_out_date_formatted}}'],
                ['Reason',     '{{cancellation_reason}}'],
            ])
        ),
    ],

    /* ── Invoice emails ──────────────────────────────────────────── */

    'payment_invoice' => [
        'name'    => 'Room Invoice Email',
        'subject' => 'Your Invoice {{invoice_number}} — {{site_name}}',
        'html'    => hotel_premium_email_html(
            'Your invoice {{invoice_number}} from {{site_name}} is ready',
            hotel_premium_email_body('{{guest_name}}',
                _p('Thank you for choosing to stay with us. We hope you had a wonderful experience. Please find attached the official invoice (<strong>{{invoice_number}}</strong>) for your recent stay.')
                . _p0('A brief summary is shown below. The full invoice PDF is attached to this email.')
            )
            . hotel_premium_email_summary_rows('Stay Summary', [
                ['Booking Reference', '{{booking_reference}}'],
                ['Invoice Number',    '{{invoice_number}}'],
                ['Check-out',         '{{check_out}}'],
                ['Total Due',         '{{total_amount}}', true],
            ])
            . hotel_premium_email_cta('{{invoice_link}}', 'View Full Invoice')
        ),
    ],

    'conference_invoice' => [
        'name'    => 'Conference Invoice Email',
        'subject' => 'Conference Invoice — {{site_name}} · {{inquiry_reference}}',
        'html'    => hotel_premium_email_html(
            'Conference invoice for {{inquiry_reference}} — {{site_name}}',
            hotel_premium_email_body('{{contact_person}}',
                _p('Thank you for hosting with us. Your conference invoice PDF is attached for your records.')
                . _p0('For any adjustments, contact us at <a href="mailto:{{contact_email}}" style="color:#524b3f;">{{contact_email}}</a> or {{contact_phone}}.')
            )
            . hotel_premium_email_summary_rows('Conference Summary', [
                ['Reference',       '{{inquiry_reference}}'],
                ['Company',         '{{company_name}}'],
                ['Conference Room', '{{conference_room}}'],
                ['Event Date',      '{{event_date}}'],
                ['Event Time',      '{{event_time}}'],
                ['Total',           '{{total_amount}}', true],
            ]),
            '{{contact_email}}'
        ),
    ],

    /* ── Tentative booking emails ────────────────────────────────── */

    'tentative_booking_created' => [
        'name'    => 'Tentative Booking Created',
        'subject' => 'Tentative Hold Confirmed — {{site_name}} · {{booking_reference}}',
        'html'    => hotel_premium_email_html(
            'Your room is on tentative hold — Reference: {{booking_reference}}',
            hotel_premium_email_body('{{guest_name}}',
                _p('Your room has been placed on a tentative hold with <strong>{{site_name}}</strong>. Please confirm your booking before the hold expires to secure your stay.')
                . _p0('Contact us at <a href="mailto:{{contact_email}}" style="color:#524b3f;">{{contact_email}}</a> or {{phone_main}} to confirm.')
            )
            . hotel_premium_email_summary_rows('Tentative Hold', [
                ['Reference',    '{{booking_reference}}'],
                ['Room',         '{{room_name}}'],
                ['Check-in',     '{{check_in_date_formatted}}'],
                ['Check-out',    '{{check_out_date_formatted}}'],
                ['Nights',       '{{number_of_nights}}'],
                ['Hold Expires', '{{tentative_expires_at_formatted}}'],
                ['Total',        '{{currency_symbol}} {{total_amount_formatted}}', true],
            ])
        ),
    ],

    'tentative_booking_reminder' => [
        'name'    => 'Tentative Booking Reminder',
        'subject' => 'Reminder: Your Tentative Hold Expires Soon — {{booking_reference}}',
        'html'    => hotel_premium_email_html(
            'Reminder: Your tentative hold expires soon — {{booking_reference}}',
            hotel_premium_email_body('{{guest_name}}',
                _p('This is a friendly reminder that your tentative booking hold at <strong>{{site_name}}</strong> is expiring soon. Please confirm to secure your stay.')
                . _p0('Contact us at <a href="mailto:{{contact_email}}" style="color:#524b3f;">{{contact_email}}</a> or {{phone_main}} to confirm.')
            )
            . hotel_premium_email_summary_rows('Hold Reminder', [
                ['Reference',    '{{booking_reference}}'],
                ['Room',         '{{room_name}}'],
                ['Hold Expires', '{{tentative_expires_at_formatted}}', true],
            ])
        ),
    ],

    'tentative_booking_expired' => [
        'name'    => 'Tentative Booking Expired',
        'subject' => 'Tentative Hold Expired — {{booking_reference}}',
        'html'    => hotel_premium_email_html(
            'Your tentative hold for {{booking_reference}} has expired',
            hotel_premium_email_body('{{guest_name}}',
                _p('Your tentative booking hold has expired and the room has been released. We hope to see you again soon.')
                . _p0('To make a new booking, please contact us at <a href="mailto:{{contact_email}}" style="color:#524b3f;">{{contact_email}}</a> or {{phone_main}}.')
            )
            . hotel_premium_email_summary_rows('Expired Hold', [
                ['Reference',    '{{booking_reference}}'],
                ['Room',         '{{room_name}}'],
                ['Was Check-in', '{{check_in_date_formatted}}'],
                ['Was Check-out','{{check_out_date_formatted}}'],
            ])
        ),
    ],

    'tentative_booking_converted' => [
        'name'    => 'Tentative Booking Converted',
        'subject' => 'Booking Now Confirmed — {{site_name}} · {{booking_reference}}',
        'html'    => hotel_premium_email_html(
            'Your booking is now confirmed — Reference: {{booking_reference}}',
            hotel_premium_email_body('{{guest_name}}',
                _p('Great news — your tentative booking has been confirmed at <strong>{{site_name}}</strong>. We look forward to welcoming you.')
                . _p0('Check-in from <strong>{{check_in_time}}</strong> &middot; Check-out by <strong>{{check_out_time}}</strong>.')
            )
            . hotel_premium_email_summary_rows('Confirmed Stay', [
                ['Reference',  '{{booking_reference}}'],
                ['Room',       '{{room_name}}'],
                ['Check-in',   '{{check_in_date_formatted}}'],
                ['Check-out',  '{{check_out_date_formatted}}'],
                ['Nights',     '{{number_of_nights}}'],
                ['Total',      '{{currency_symbol}} {{total_amount_formatted}}', true],
            ])
            . $policyRow
        ),
    ],

    /* ── Quotation emails ────────────────────────────────────────── */

    'tentative_quotation' => [
        'name'    => 'Room Quotation Email',
        'subject' => 'Your Stay Quotation — {{site_name}} · {{quotation_reference}}',
        'html'    => hotel_premium_email_html(
            'Your stay quotation {{quotation_reference}} from {{site_name}} is ready',
            hotel_premium_email_body('{{guest_name}}',
                _p('Please find your stay quotation from <strong>{{site_name}}</strong>. Your quotation PDF is attached for review.')
                . _p0('{{quotation_notes}}')
            )
            . hotel_premium_email_summary_rows('Quotation Summary', [
                ['Quote Reference',   '{{quotation_reference}}'],
                ['Booking Reference', '{{booking_reference}}'],
                ['Room',              '{{room_name}}'],
                ['Check-in',          '{{check_in_date}}'],
                ['Check-out',         '{{check_out_date}}'],
                ['Valid Until',        '{{valid_until}}'],
                ['Total',             '{{total_amount}}', true],
            ])
        ),
    ],

    'conference_quotation' => [
        'name'    => 'Conference Quotation Email',
        'subject' => 'Conference Quotation — {{site_name}} · {{inquiry_reference}}',
        'html'    => hotel_premium_email_html(
            'Conference quotation {{quotation_reference}} from {{site_name}}',
            hotel_premium_email_body('{{contact_person}}',
                _p('Your conference quotation from <strong>{{site_name}}</strong> is ready. The quotation PDF is attached for your records.')
                . _p0('{{quotation_notes}}')
            )
            . hotel_premium_email_summary_rows('Conference Quotation', [
                ['Inquiry Reference', '{{inquiry_reference}}'],
                ['Quote Reference',   '{{quotation_reference}}'],
                ['Company',           '{{company_name}}'],
                ['Conference Room',   '{{conference_room}}'],
                ['Event Date',        '{{event_date}}'],
                ['Attendees',         '{{attendees}}'],
                ['Valid Until',        '{{valid_until}}'],
                ['Total',             '{{total_amount}}', true],
            ]),
            '{{contact_email}}'
        ),
    ],

    'event_quotation' => [
        'name'    => 'Event Quotation Email',
        'subject' => 'Event Quotation — {{site_name}} · {{quotation_reference}}',
        'html'    => hotel_premium_email_html(
            'Your event quotation {{quotation_reference}} from {{site_name}}',
            hotel_premium_email_body('{{recipient_name}}',
                _p('Your event quotation from <strong>{{site_name}}</strong> is ready. Please find the quotation PDF attached.')
                . _p0('{{quotation_notes}}')
            )
            . hotel_premium_email_summary_rows('Event Quotation', [
                ['Quote Reference', '{{quotation_reference}}'],
                ['Event',           '{{event_title}}'],
                ['Date',            '{{event_date}}'],
                ['Time',            '{{event_time}}'],
                ['Location',        '{{event_location}}'],
                ['Attendees',       '{{attendee_count}}'],
                ['Valid Until',      '{{valid_until}}'],
                ['Total',           '{{total_amount}}', true],
            ]),
            '{{guest_email}}'
        ),
    ],

    /* ── Credit note ──────────────────────────────────────────────── */

    'credit_note' => [
        'name'    => 'Credit Note Email',
        'subject' => 'Credit Note {{credit_note_number}} — {{site_name}}',
        'html'    => hotel_premium_email_html(
            'Credit note {{credit_note_number}} has been issued to your account',
            hotel_premium_email_body('{{guest_name}}',
                _p('A credit note has been issued to your account with <strong>{{site_name}}</strong>. The credit note PDF is attached for your records.')
                . _p0('Please quote <strong>{{credit_note_number}}</strong> when making your next booking. This credit note is non-transferable and cannot be exchanged for cash.')
            )
            . hotel_premium_email_summary_rows('Credit Note Summary', [
                ['Credit Note No.',   '{{credit_note_number}}'],
                ['Face Value',        '{{amount}}'],
                ['Reason',            '{{reason}}'],
                ['Valid Until',        '{{expires_at}}'],
                ['Available Balance', '{{balance}}', true],
            ])
        ),
    ],

    /* ── Receipt ──────────────────────────────────────────────────── */

    'payment_receipt' => [
        'name'    => 'Payment Receipt Email',
        'subject' => 'Payment Receipt {{receipt_number}} — {{site_name}}',
        'html'    => hotel_premium_email_html(
            'Your payment receipt {{receipt_number}} from {{site_name}}',
            hotel_premium_email_body('{{guest_name}}',
                _p('Thank you for your payment. Your receipt PDF is attached for your records.')
                . _p0('Questions? Contact us at <a href="mailto:{{contact_email}}" style="color:#524b3f;">{{contact_email}}</a>.')
            )
            . hotel_premium_email_summary_rows('Payment Summary', [
                ['Receipt No.',     '{{receipt_number}}'],
                ['Reference',       '{{payment_reference}}'],
                ['Type',            '{{booking_type}}'],
                ['Payment Date',    '{{payment_date}}'],
                ['Amount Received', '{{total_amount}}', true],
            ])
        ),
    ],
];

$updated = 0;
foreach ($defaults as $key => $def) {
    upsertBookingEmailTemplateConfig($key, $def['name'], $def['subject'], $def['html'], '', 1);
    $updated++;
    echo '  ✓ ' . $key . PHP_EOL;
}
echo PHP_EOL . 'Updated ' . $updated . ' premium email templates.' . PHP_EOL;
