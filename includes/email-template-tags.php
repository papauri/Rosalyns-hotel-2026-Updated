<?php
/**
 * Tag glossary for the email + PDF templates (Email Templates page).
 *
 * Which {{tags}} each template really fills, and what each one means in plain words. The template lists are
 * taken from the variables each sender passes (config/email.php buildBookingEmailVariables() and its callers,
 * the conference / event quotation senders, config/invoice.php, config/credit-notes.php, config/receipts.php,
 * includes/auto-email-jobs.php, includes/quotation-pdf.php). A tag that is not filled for a template reaches
 * the guest as the literal "{{tag}}", so only the filled ones are listed. Update this file when a sender's
 * variables change.
 */

if (!function_exists('rh_email_tag_groups')) {

    /** Group key => heading, in display order. */
    function rh_email_tag_groups(): array
    {
        return [
            'guest'  => 'Guest & booking',
            'dates'  => 'Dates & stay',
            'money'  => 'Money',
            'hotel'  => 'Hotel details',
            'doc'    => 'Document-specific',
        ];
    }

    /** Tag => [group, plain-English meaning with an example]. */
    function rh_email_tag_definitions(): array
    {
        return [
            // Guest & booking
            'guest_name'           => ['guest', 'Guest\'s full name, e.g. Mary Banda'],
            'guest_email'          => ['guest', 'Guest\'s email address'],
            'guest_phone'          => ['guest', 'Guest\'s phone number'],
            'booking_reference'    => ['guest', 'Booking reference, e.g. RBH-1042'],
            'room_name'            => ['guest', 'Room type booked, e.g. Deluxe Room'],
            'room_assignment'      => ['guest', 'Room number(s) given to the guest, e.g. 12'],
            'room_numbers'         => ['guest', 'Same as room_assignment: the room number(s)'],
            'occupancy_type'       => ['guest', 'Single or double occupancy'],
            'number_of_guests'     => ['guest', 'Total guests, e.g. 3'],
            'adult_guests'         => ['guest', 'Number of adults'],
            'child_guests'         => ['guest', 'Number of children'],
            'guests'               => ['guest', 'Guests on the quotation, e.g. 2 adults, 1 child'],
            'special_requests'     => ['guest', 'What the guest wrote under special requests'],
            'rate_plan_label'      => ['guest', 'Rate plan name, e.g. Early Bird'],
            'packages_html'        => ['guest', 'List of packages added to the booking'],
            'booked_rate_block'    => ['guest', 'Box with the booked rate x nights and every room\'s reference (added automatically when left out)'],
            'group_rooms_block'    => ['guest', 'List of every room in a group booking (added automatically when left out)'],
            'tentative_status'     => ['guest', 'Booking status, e.g. Tentative'],
            'cancellation_reason'  => ['guest', 'Reason given when the booking was cancelled'],
            'cancellation_policy_text' => ['guest', 'The cancellation policy the booking was made under (added automatically when left out)'],
            'cancellation_fee_charged' => ['money', 'Line saying what cancellation fee was charged'],
            'conversion_status'    => ['guest', 'What the tentative booking became, e.g. converted'],
            'urgency_notice'       => ['guest', 'Short line about how soon payment is needed'],
            'whatsapp_link'        => ['hotel', 'Link that opens a WhatsApp chat with the hotel'],
            'contact_person'       => ['guest', 'Contact person for the conference, e.g. John Phiri'],
            'company_name'         => ['guest', 'Company or organisation name'],
            'inquiry_reference'    => ['guest', 'Conference enquiry reference, e.g. CONF-2026-014'],
            'conference_room'      => ['guest', 'Conference room booked, e.g. Shire Hall'],
            'event_type'           => ['guest', 'Kind of event, e.g. Workshop'],
            'attendees'            => ['guest', 'Number of people attending the conference'],
            'recipient_name'       => ['guest', 'Name the event quotation is addressed to'],
            'event_title'          => ['guest', 'Name of the event, e.g. Christmas Dinner'],
            'event_location'       => ['guest', 'Where the event takes place'],
            'attendee_count'       => ['guest', 'Number of people attending the event'],
            'client_email'         => ['guest', 'Conference client\'s email address'],
            'client_phone'         => ['guest', 'Conference client\'s phone number'],
            'booking_type'         => ['guest', 'What the payment was for, e.g. Room booking'],
            'booking_type_label'   => ['guest', 'What the refund was for, e.g. Room'],
            'account_reference'    => ['guest', 'Reference of the unpaid account, e.g. RBH-1042'],
            // Dates & stay
            'check_in_date_formatted'  => ['dates', 'Arrival date, e.g. December 1, 2026'],
            'check_out_date_formatted' => ['dates', 'Departure date, e.g. December 4, 2026'],
            'check_in_date'        => ['dates', 'Arrival date on the quotation'],
            'check_out_date'       => ['dates', 'Departure date on the quotation'],
            'check_in'             => ['dates', 'Arrival date on the invoice'],
            'check_out'            => ['dates', 'Departure date on the invoice'],
            'number_of_nights'     => ['dates', 'Nights booked, e.g. 3'],
            'nights'               => ['dates', 'Nights on the quotation or invoice, e.g. 3'],
            'check_in_time'        => ['dates', 'Check-in time from Hotel Settings, e.g. 2:00 PM'],
            'check_out_time'       => ['dates', 'Check-out time from Hotel Settings, e.g. 11:00 AM'],
            'tentative_expires_at_formatted' => ['dates', 'When the held booking runs out, e.g. December 1, 2026 2:00 PM'],
            'tentative_duration_hours' => ['dates', 'How many hours the booking is held, e.g. 48'],
            'hours_until_expiry'   => ['dates', 'Hours left before the held booking runs out'],
            'event_date'           => ['dates', 'Date(s) of the event or conference'],
            'event_time'           => ['dates', 'Start and end time, e.g. 09:00 - 17:00'],
            'valid_until'          => ['dates', 'Last day the quotation can be accepted'],
            'days_left'            => ['dates', 'When the quotation runs out, e.g. in 3 days, tomorrow or today'],
            'due_date'             => ['dates', 'Date the payment was due'],
            'days_overdue'         => ['dates', 'Payment reminders: days the payment is late, e.g. 7. Booking reminder: days past the check-in or check-out date (0 for an upcoming stay)'],
            'days_until_arrival'   => ['dates', 'Days left before the guest arrives, e.g. 15 days'],
            'issued_date'          => ['dates', 'Date the document was issued'],
            'payment_date'         => ['dates', 'Date the payment was received'],
            'refund_date_formatted' => ['dates', 'Date the refund was issued'],
            'expires_at'           => ['dates', 'Date the credit note runs out, or No expiry'],
            // Money
            'currency_symbol'      => ['money', 'Currency, e.g. MWK'],
            'total_amount'         => ['money', 'Total including VAT, e.g. MWK 300,000.00'],
            'total_amount_formatted' => ['money', 'Amount owed as a number, e.g. 300,000'],
            'subtotal_amount'      => ['money', 'Amount before VAT and levy'],
            'vat_rate'             => ['money', 'VAT rate, e.g. 17.5'],
            'vat_amount'           => ['money', 'VAT amount'],
            'levy_rate'            => ['money', 'Tourism levy rate, e.g. 1.0'],
            'levy_amount'          => ['money', 'Tourism levy amount'],
            'vat_number'           => ['money', 'Hotel VAT number'],
            'vat_number_html'      => ['money', 'Hotel VAT number line (empty when none is set)'],
            'rate_plan_discount_formatted' => ['money', 'Discount from the rate plan'],
            'rate_plan_surcharge_formatted' => ['money', 'Extra charge from the rate plan'],
            'package_total_formatted' => ['money', 'Total of the packages added'],
            'child_price_multiplier' => ['money', 'Percentage of the adult rate charged for a child, e.g. 50'],
            'child_charge_rule'    => ['money', 'How children are charged, in words'],
            'child_supplement_total_formatted' => ['money', 'Total charged for children'],
            'child_supplement'     => ['money', 'Charge for children on the quotation'],
            'rate_per_night'       => ['money', 'Room rate per night'],
            'room_subtotal'        => ['money', 'Room charge for all nights'],
            'deposit_amount'       => ['money', 'Deposit asked for'],
            'balance_due'          => ['money', 'Amount still to pay'],
            'payment_policy'       => ['money', 'Payment policy from Hotel Settings'],
            'invoice_total_amount' => ['money', 'Invoice total'],
            'amount_paid_amount'   => ['money', 'Amount already paid'],
            'balance_due_amount'   => ['money', 'Balance still to pay on the invoice'],
            'amount_due'           => ['money', 'Amount still unpaid, number only, e.g. 292,000.00 (put the currency tag before it)'],
            'pay_instructions'     => ['money', 'How to pay (bank and mobile money details)'],
            'quote_total'          => ['money', 'Quotation total, number only, e.g. 292,000.00'],
            'refund_amount_formatted' => ['money', 'Amount refunded, e.g. 50,000.00'],
            'amount'               => ['money', 'Credit note value'],
            'amount_used'          => ['money', 'Part of the credit note already used'],
            'balance'              => ['money', 'Credit note amount left to use'],
            'payment_amount'       => ['money', 'Amount paid, before VAT'],
            'total_due'            => ['money', 'Invoice total due'],
            'amount_paid'          => ['money', 'Amount already paid'],
            'rate_per_attendee'    => ['money', 'Price per person for the event'],
            'booked_rate'          => ['money', 'Rate booked, e.g. MWK 100,000.00 x 3 nights'],
            // Hotel details
            'site_name'            => ['hotel', 'Hotel name'],
            'site_url'             => ['hotel', 'Hotel website address'],
            'contact_email'        => ['hotel', 'Hotel email address for guests'],
            'contact_phone'        => ['hotel', 'Hotel phone number'],
            'phone_main'           => ['hotel', 'Hotel main phone number'],
            'hotel_phone'          => ['hotel', 'Hotel phone number'],
            'address'              => ['hotel', 'Hotel postal address'],
            'hotel_address'        => ['hotel', 'Hotel postal address'],
            'logo_html'            => ['hotel', 'Hotel logo picture'],
            // Document-specific
            'quotation_reference'  => ['doc', 'Quotation number, e.g. QT-1042'],
            'quote_reference'      => ['doc', 'Quotation number, e.g. QT-1042'],
            'quotation_notes'      => ['doc', 'Note typed by staff when sending the quotation (empty when none)'],
            'invoice_number'       => ['doc', 'Invoice number'],
            'invoice_link'         => ['doc', 'Link to view the invoice online'],
            'status_text'          => ['doc', 'Payment status, e.g. Paid'],
            'status_bg'            => ['doc', 'Background colour of the status label'],
            'status_fg'            => ['doc', 'Text colour of the status label'],
            'room_icon_html'       => ['doc', 'Small room picture on the invoice'],
            'charges_table_rows'   => ['doc', 'Rows of the charges table'],
            'totals_rows'          => ['doc', 'Rows of the totals table'],
            'payment_history_section' => ['doc', 'Table of payments received'],
            'bank_details'         => ['doc', 'Hotel bank details'],
            'invoice_terms'        => ['doc', 'Invoice terms from the invoice settings'],
            'cancellation_terms'   => ['doc', 'Cancellation terms'],
            'credit_note_number'   => ['doc', 'Credit note number'],
            'reason'               => ['doc', 'Reason for the credit note'],
            'reason_notes'         => ['doc', 'Extra notes on the credit note'],
            'refund_reference'     => ['doc', 'Refund reference'],
            'refund_reason_display' => ['doc', 'Reason for the refund'],
            'receipt_number'       => ['doc', 'Receipt number'],
            'payment_reference'    => ['doc', 'Payment reference'],
            'payment_method'       => ['doc', 'How the guest paid, e.g. Cash'],
            'payment_type'         => ['doc', 'Kind of payment, e.g. Deposit'],
            'payment_status'       => ['doc', 'Payment status, e.g. Completed'],
            'description'          => ['doc', 'What the payment was for'],
        ];
    }

    /** Template key => the tags its sender really fills. */
    function rh_email_template_tag_map(): array
    {
        // Hotel and guest details every buildBookingEmailVariables() sender gives (reminders, expiry, refund pass a
        // guest only, so their booking fields would be empty).
        $hotel = ['site_name', 'site_url', 'contact_email', 'contact_phone', 'phone_main', 'address', 'logo_html',
            'currency_symbol', 'payment_policy', 'check_in_time', 'check_out_time', 'vat_number', 'vat_number_html',
            'guest_name', 'guest_email'];
        // A real booking passed to buildBookingEmailVariables().
        $booking = array_merge($hotel, ['booking_reference', 'guest_phone', 'room_name', 'room_assignment', 'room_numbers',
            'occupancy_type', 'check_in_date_formatted', 'check_out_date_formatted', 'number_of_nights', 'number_of_guests',
            'adult_guests', 'child_guests', 'special_requests', 'rate_plan_label', 'rate_plan_discount_formatted',
            'rate_plan_surcharge_formatted', 'package_total_formatted', 'packages_html', 'child_price_multiplier',
            'child_charge_rule', 'child_supplement_total_formatted', 'total_amount_formatted', 'subtotal_amount',
            'vat_rate', 'vat_amount', 'levy_rate', 'levy_amount', 'total_amount', 'booked_rate_block',
            'cancellation_policy_text']);
        $tentative = array_merge($booking, ['tentative_status', 'tentative_expires_at_formatted']);
        $reminder = array_merge($hotel, ['amount_due', 'due_date', 'days_overdue', 'invoice_number', 'account_reference', 'pay_instructions']);

        return [
            'booking_received'            => $booking,
            'booking_confirmed'           => $booking,
            'booking_cancelled'           => array_merge($booking, ['cancellation_reason', 'cancellation_fee_charged', 'group_rooms_block']),
            'booking_reminder'            => array_merge($booking, ['days_overdue', 'urgency_notice', 'group_rooms_block']),
            'tentative_booking_created'   => array_merge($tentative, ['tentative_duration_hours', 'hours_until_expiry', 'whatsapp_link']),
            'tentative_booking_reminder'  => array_merge($tentative, ['whatsapp_link']),
            'tentative_booking_expired'   => $tentative,
            'tentative_booking_converted' => array_merge($tentative, ['conversion_status']),
            'tentative_quotation'         => array_merge($tentative, ['quotation_reference', 'quote_reference', 'check_in_date',
                'check_out_date', 'nights', 'guests', 'rate_per_night', 'room_subtotal', 'child_supplement', 'deposit_amount',
                'balance_due', 'valid_until', 'quotation_notes']),
            'payment_invoice'             => array_merge($booking, ['invoice_number', 'check_out', 'invoice_link',
                'invoice_total_amount', 'amount_paid_amount', 'balance_due_amount', 'group_rooms_block']),
            'refund_notification'         => array_merge($hotel, ['booking_reference', 'refund_reference', 'refund_amount_formatted',
                'refund_reason_display', 'refund_date_formatted', 'booking_type_label']),
            'payment_reminder_1'          => $reminder,
            'payment_reminder_2'          => $reminder,
            'payment_reminder_3'          => $reminder,
            'quotation_expiry_reminder'   => array_merge($hotel, ['quote_reference', 'valid_until', 'days_left', 'quote_total']),
            'prearrival_payment_reminder' => array_merge($booking, ['days_until_arrival', 'amount_paid', 'balance_due', 'amount_due',
                'pay_instructions', 'group_rooms_block']),
            'conference_invoice'          => ['site_name', 'logo_html', 'address', 'contact_email', 'contact_phone', 'payment_policy',
                'inquiry_reference', 'company_name', 'contact_person', 'conference_room', 'event_date', 'event_time', 'attendees',
                'subtotal_amount', 'vat_rate', 'vat_amount', 'total_amount', 'vat_number', 'vat_number_html'],
            'conference_quotation'        => ['site_name', 'logo_html', 'address', 'contact_email', 'contact_phone', 'payment_policy',
                'guest_name', 'guest_email', 'contact_person', 'company_name', 'inquiry_reference', 'quotation_reference',
                'quote_reference', 'conference_room', 'event_type', 'event_date', 'event_time', 'attendees', 'total_amount',
                'total_amount_formatted', 'currency_symbol', 'valid_until', 'quotation_notes'],
            'event_quotation'             => ['site_name', 'logo_html', 'address', 'contact_email', 'contact_phone', 'payment_policy',
                'guest_email', 'recipient_name', 'quotation_reference', 'quote_reference', 'event_title', 'event_date', 'event_time',
                'event_location', 'attendee_count', 'total_amount', 'total_amount_formatted', 'currency_symbol', 'valid_until',
                'quotation_notes'],
            'credit_note'                 => ['site_name', 'logo_html', 'address', 'hotel_address', 'contact_email', 'contact_phone',
                'hotel_phone', 'currency_symbol', 'payment_policy', 'guest_name', 'guest_email', 'booking_reference',
                'credit_note_number', 'amount', 'balance', 'amount_used', 'reason', 'reason_notes', 'expires_at', 'booked_rate'],
            'payment_receipt'             => ['site_name', 'logo_html', 'address', 'hotel_address', 'contact_email', 'contact_phone',
                'guest_name', 'guest_email', 'guest_phone', 'receipt_number', 'booking_type', 'payment_reference',
                'booking_reference', 'payment_date', 'payment_method', 'payment_type', 'payment_status', 'payment_amount',
                'vat_amount', 'total_amount', 'description', 'vat_number', 'vat_rate', 'vat_number_html', 'payment_policy'],
            // PDF documents
            'tentative_quotation_document' => ['logo_html', 'site_name', 'address', 'contact_phone', 'contact_email',
                'quotation_reference', 'valid_until', 'guest_name', 'booking_reference', 'room_name', 'check_in_date',
                'check_out_date', 'nights', 'guests', 'rate_per_night', 'room_subtotal', 'vat_amount', 'deposit_amount',
                'total_amount', 'balance_due', 'payment_policy', 'quotation_notes'],
            'conference_quotation_document' => ['logo_html', 'site_name', 'address', 'contact_phone', 'contact_email',
                'quotation_reference', 'valid_until', 'inquiry_reference', 'company_name', 'contact_person', 'conference_room',
                'event_date', 'event_time', 'attendees', 'total_amount', 'vat_amount', 'deposit_amount', 'payment_policy',
                'quotation_notes'],
            'event_quotation_document'    => ['logo_html', 'site_name', 'address', 'contact_phone', 'contact_email',
                'quotation_reference', 'valid_until', 'recipient_name', 'event_title', 'event_date', 'event_time',
                'event_location', 'attendee_count', 'rate_per_attendee', 'total_amount', 'quotation_notes'],
            'payment_invoice_document'    => ['booked_rate', 'invoice_number', 'issued_date', 'guest_name', 'guest_email',
                'guest_phone', 'booking_reference', 'room_icon_html', 'room_name', 'check_in', 'check_out', 'nights', 'guests',
                'status_text', 'status_bg', 'status_fg', 'total_due', 'amount_paid', 'balance_due', 'site_name', 'address',
                'contact_email', 'contact_phone', 'vat_number_html', 'logo_html', 'currency_symbol', 'charges_table_rows',
                'totals_rows', 'payment_history_section', 'bank_details', 'invoice_terms', 'cancellation_terms'],
            'conference_invoice_document' => ['logo_html', 'site_name', 'address', 'contact_email', 'contact_phone',
                'invoice_number', 'issued_date', 'status_text', 'inquiry_reference', 'company_name', 'contact_person',
                'client_email', 'client_phone', 'conference_room', 'event_date', 'event_time', 'attendees', 'event_type',
                'total_amount', 'amount_paid', 'balance_due', 'booked_rate'],
            'credit_note_document'        => ['logo_html', 'site_name', 'address', 'contact_email', 'contact_phone',
                'credit_note_number', 'issued_date', 'guest_name', 'guest_email', 'booking_reference', 'reason',
                'reason_notes', 'expires_at', 'amount', 'amount_used', 'balance', 'booked_rate'],
            // Not used: the receipt PDF is laid out automatically (see rh_email_template_tag_note()).
            'payment_receipt_document'    => [],
        ];
    }

    /**
     * Note shown above a template's tags when its layout is not built from the template ('' = none).
     */
    function rh_email_template_tag_note(string $templateKey): string
    {
        $notes = [
            'payment_receipt' => 'The receipt email body is laid out automatically. These tags work in the subject and the plain text version.',
            'payment_receipt_document' => 'The receipt PDF is laid out automatically; this template is not used for it.',
        ];
        return $notes[$templateKey] ?? '';
    }

    /**
     * The tags a template fills, grouped for display: [group heading => [tag => meaning]]. Null when the template
     * is not in the glossary (the page then shows its general list).
     */
    function rh_email_template_tags(string $templateKey): ?array
    {
        $map = rh_email_template_tag_map();
        if (!isset($map[$templateKey])) {
            return null;
        }
        $defs = rh_email_tag_definitions();
        $groups = rh_email_tag_groups();
        $out = array_fill_keys(array_values($groups), []);
        foreach (array_values(array_unique($map[$templateKey])) as $tag) {
            $def = $defs[$tag] ?? ['doc', ''];
            $heading = $groups[$def[0]] ?? $groups['doc'];
            $out[$heading][$tag] = $def[1];
        }
        return array_filter($out);
    }
}
