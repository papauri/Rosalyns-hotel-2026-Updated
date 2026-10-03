<?php

require_once __DIR__ . '/../config/email.php';

/**
 * Quotation PDFs (room, conference, event).
 *
 * The code default for every quotation is the shared PDF kit (config/document-theme.php), so they
 * match receipts, credit notes and invoices. An admin-customised *_quotation_document template
 * (wording edited under Booking settings) is still honoured; a stored copy of the old default is not.
 * Presentation only - every figure is computed exactly as before.
 */

if (!function_exists('quotationPdfLogoHtml')) {
    function quotationPdfLogoHtml(): string
    {
        $logoSrc = function_exists('hotel_invoice_logo_src') ? hotel_invoice_logo_src() : '';
        if ($logoSrc === '') {
            return '';
        }

        return '<img src="' . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') . '" alt="Logo" height="22mm">';
    }
}

if (!function_exists('quotationPdfRenderDocument')) {
    /**
     * @param string $templateKey  DB template key (e.g. tentative_quotation_document)
     * @param string $legacyHtml   placeholder-based code default (used only if no kit HTML is given)
     * @param array  $vars         placeholder => escaped value, for a customised template
     * @param string $kitHtml      kit layout used unless the admin customised the template
     * @param array  $opts         bookingRenderPdfFromHtml options (watermark)
     */
    function quotationPdfRenderDocument(string $templateKey, string $legacyHtml, array $vars, string $title, string $kitHtml = '', array $opts = []): string
    {
        $html = function_exists('rh_pdf_custom_template_html')
            ? rh_pdf_custom_template_html($templateKey, $vars, $legacyHtml)
            : null;
        if ($html === null) {
            $html = $kitHtml !== '' ? $kitHtml : strtr($legacyHtml, bookingTemplateReplaceMap($vars));
        }

        return bookingRenderPdfFromHtml($html, $title, $opts);
    }
}

if (!function_exists('quotationPdfClosingNotes')) {
    /** Validity line + optional document_terms_text, shared by all quotations. */
    function quotationPdfClosingNotes(string $validityText): string
    {
        $terms = hotel_brand_tokens()['terms_text'];
        return rh_pdf_spacer(3) . rh_pdf_note($validityText . ($terms !== '' ? "\n" . $terms : ''));
    }
}

/**
 * Room quotation PDF for tentative bookings.
 *
 * @param array $booking  Full row from bookings table
 * @param array $room     Row from rooms table
 * @param array $options {
 *   valid_days       int     Days quotation is valid (default 7)
 *   quotation_notes  string  Admin note (default '')
 *   watermark        string  Diagonal watermark text (tests, e.g. SAMPLE)
 * }
 * @return string  Raw PDF binary string
 */
function generateQuotationPDF(array $booking, array $room, array $options = []): string
{
    if (!hotel_load_tcpdf()) {
        throw new RuntimeException('The PDF engine (TCPDF) is not installed on this server. Upload the vendor/ folder (composer install) or a TCPDF/ folder to enable PDF documents.');
    }

    // ── Config ────────────────────────────────────────────────────────────────
    $site_name      = getSetting('site_name', 'Hotel');
    $site_address   = getSetting('address_line1', '') . (getSetting('address_line2', '') ? ', ' . getSetting('address_line2', '') : '');
    $site_phone     = getSetting('phone_main', '');
    $site_email     = getSetting('email_main', getSetting('email_from_email', ''));
    $currency       = getSetting('currency_symbol', 'MWK');
    $vat_enabled    = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $check_in_time  = getSetting('check_in_time', '2:00 PM');
    $check_out_time = getSetting('check_out_time', '11:00 AM');
    $payment_policy = getSetting('payment_policy', 'Full payment is due on arrival.');

    $valid_days   = max(1, (int)($options['valid_days'] ?? 7));
    $notes        = trim((string)($options['quotation_notes'] ?? ''));
    $valid_until  = (new DateTime())->modify("+{$valid_days} days");
    $quote_ref    = 'QT-' . strtoupper((string)$booking['booking_reference']);

    // ── Pricing ───────────────────────────────────────────────────────────────
    $nights       = (int)$booking['number_of_nights'];
    $adults       = (int)($booking['adult_guests'] ?? $booking['number_of_guests'] ?? 1);
    $children     = (int)($booking['child_guests'] ?? 0);
    $total        = (float)$booking['total_amount'];
    $vat_amount   = (float)($booking['vat_amount'] ?? 0);
    $vat_rate     = (float)($booking['vat_rate'] ?? 0);
    $child_supp   = (float)($booking['child_supplement_total'] ?? 0);
    $deposit_req  = !empty($booking['deposit_required']);
    $deposit_amt  = (float)($booking['deposit_amount'] ?? 0);

    // Exclusive: VAT was added on top, so strip it to recover the room line.
    // Inclusive/off: the priced total IS the room line (VAT, if any, is inside it).
    $room_subtotal  = $total - (vat_mode() === 'exclusive' ? $vat_amount : 0.0) - $child_supp;
    $rate_per_night = $nights > 0 ? $room_subtotal / $nights : (float)$room['price_per_night'];

    $fmt = static function (float $v) use ($currency): string {
        return $currency . number_format($v, 0);
    };

    $guestsLabel = $adults . ' adult' . ($adults !== 1 ? 's' : '');
    if ($children > 0) {
        $guestsLabel .= ', ' . $children . ' child' . ($children !== 1 ? 'ren' : '');
    }

    // ── Kit layout (code default) ─────────────────────────────────────────────
    $roomMeta = array_filter([
        !empty($room['bed_type']) ? (string)$room['bed_type'] : '',
        !empty($room['size_sqm']) ? $room['size_sqm'] . ' sqm' : '',
        !empty($room['max_guests']) ? 'Max ' . (int)$room['max_guests'] . ' guests' : '',
    ]);
    $meta = [
        ['Quotation ref', $quote_ref],
        ['Date issued', date('F j, Y')],
        ['Valid until', $valid_until->format('F j, Y')],
        ['Prepared for', (string)($booking['guest_name'] ?? '')],
        ['Booking ref', (string)($booking['booking_reference'] ?? '')],
        ['Guests', $guestsLabel],
        ['Room', (string)($room['name'] ?? '') . ($roomMeta ? '  (' . implode(', ', $roomMeta) . ')' : ''), true],
        ['Check-in', !empty($booking['check_in_date']) ? date('D, d M Y', strtotime((string)$booking['check_in_date'])) . ' from ' . $check_in_time : ''],
        ['Check-out', !empty($booking['check_out_date']) ? date('D, d M Y', strtotime((string)$booking['check_out_date'])) . ' by ' . $check_out_time : ''],
        ['Duration', $nights . ' night' . ($nights !== 1 ? 's' : '')],
    ];
    if (!empty($booking['special_requests'])) {
        $meta[] = ['Requests', trim((string)preg_replace('/\s+/', ' ', (string)$booking['special_requests'])), true];
    }

    $itemRows = [[
        rh_pdf_e((string)($room['name'] ?? 'Room')),
        rh_pdf_e((string)$nights),
        rh_pdf_e($fmt($rate_per_night)),
        rh_pdf_e($fmt($room_subtotal)),
    ]];
    if ($children > 0 && $child_supp > 0) {
        $itemRows[] = [rh_pdf_e('Child supplement (' . $children . ' child' . ($children !== 1 ? 'ren' : '') . ')'), '', '', rh_pdf_e($fmt($child_supp))];
    }
    $totals = [];
    if ($vat_enabled && $vat_amount > 0) {
        $totals = array_merge($totals, rh_pdf_vat_rows($vat_amount, $vat_rate, trim($currency), $fmt));
    }
    $totals[] = ['label' => 'Total amount', 'value' => $fmt($total), 'type' => 'total'];
    if ($deposit_req && $deposit_amt > 0) {
        $totals[] = ['label' => 'Deposit to confirm', 'value' => $fmt($deposit_amt), 'type' => 'strong'];
        $totals[] = ['label' => 'Balance on arrival', 'value' => $fmt($total - $deposit_amt), 'type' => 'normal'];
    }

    $body = rh_pdf_section_title('Pricing breakdown') . rh_pdf_spacer(2)
        . rh_pdf_items_table(
            [
                ['label' => 'Description', 'width' => 46],
                ['label' => 'Nights', 'width' => 12, 'align' => 'center'],
                ['label' => 'Rate / night', 'width' => 20, 'align' => 'right'],
                ['label' => 'Amount', 'width' => 22, 'align' => 'right'],
            ],
            $itemRows
        )
        . rh_pdf_spacer(2) . rh_pdf_totals_table($totals);

    if ($deposit_req && $deposit_amt > 0) {
        $paymentText = 'A deposit of ' . $fmt($deposit_amt) . ' is required to confirm this booking. The remaining balance of ' . $fmt($total - $deposit_amt) . ' is due on arrival.';
    } else {
        $paymentText = (string)$payment_policy;
    }
    $body .= rh_pdf_spacer(3) . rh_pdf_section_title('Payment terms', 25) . rh_pdf_spacer(2) . rh_pdf_note($paymentText);
    if ($notes !== '') {
        $body .= rh_pdf_spacer(3) . rh_pdf_section_title('Note from our team', 25) . rh_pdf_spacer(2) . rh_pdf_note($notes);
    }
    $body .= quotationPdfClosingNotes('This quotation is valid until ' . $valid_until->format('F j, Y') . '. Rates and availability are subject to change after this date. We look forward to welcoming you.');

    $kitHtml = rh_pdf_document_shell('Hotel Quotation', $meta, $body);

    $legacyHtml = function_exists('hotel_default_room_quotation_document_html') ? hotel_default_room_quotation_document_html() : '';

    return quotationPdfRenderDocument(
        'tentative_quotation_document',
        $legacyHtml,
        [
            'logo_html' => quotationPdfLogoHtml(),
            'site_name' => htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'),
            'address' => htmlspecialchars($site_address, ENT_QUOTES, 'UTF-8'),
            'contact_phone' => htmlspecialchars($site_phone, ENT_QUOTES, 'UTF-8'),
            'contact_email' => htmlspecialchars($site_email, ENT_QUOTES, 'UTF-8'),
            'quotation_reference' => htmlspecialchars($quote_ref, ENT_QUOTES, 'UTF-8'),
            'valid_until' => htmlspecialchars($valid_until->format('F j, Y'), ENT_QUOTES, 'UTF-8'),
            'guest_name' => htmlspecialchars((string)($booking['guest_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'booking_reference' => htmlspecialchars((string)($booking['booking_reference'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'room_name' => htmlspecialchars((string)($room['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'check_in_date' => htmlspecialchars(!empty($booking['check_in_date']) ? date('l, F j, Y', strtotime((string)$booking['check_in_date'])) : '', ENT_QUOTES, 'UTF-8'),
            'check_out_date' => htmlspecialchars(!empty($booking['check_out_date']) ? date('l, F j, Y', strtotime((string)$booking['check_out_date'])) : '', ENT_QUOTES, 'UTF-8'),
            'nights' => (string)$nights,
            'guests' => htmlspecialchars($guestsLabel, ENT_QUOTES, 'UTF-8'),
            'rate_per_night' => htmlspecialchars($fmt($rate_per_night), ENT_QUOTES, 'UTF-8'),
            'room_subtotal' => htmlspecialchars($fmt($room_subtotal), ENT_QUOTES, 'UTF-8'),
            'vat_amount' => htmlspecialchars(vat_document_value($fmt($vat_amount)), ENT_QUOTES, 'UTF-8'),
            'deposit_amount' => htmlspecialchars($fmt($deposit_amt), ENT_QUOTES, 'UTF-8'),
            'total_amount' => htmlspecialchars($fmt($total), ENT_QUOTES, 'UTF-8'),
            'balance_due' => htmlspecialchars($fmt(max(0, $total - $deposit_amt)), ENT_QUOTES, 'UTF-8'),
            'payment_policy' => nl2br(htmlspecialchars((string)$payment_policy, ENT_QUOTES, 'UTF-8')),
            'quotation_notes' => nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')),
        ],
        'Quotation ' . $quote_ref,
        $kitHtml,
        !empty($options['watermark']) ? ['watermark' => (string)$options['watermark']] : []
    );
}

/**
 * Generate a conference quotation PDF.
 *
 * @param array $enquiry Conference enquiry row.
 * @param array $room    Conference room row.
 * @param array $options Optional keys: valid_days, quotation_notes, quote_reference, watermark.
 */
function generateConferenceQuotationPDF(array $enquiry, array $room, array $options = []): string
{
    if (!hotel_load_tcpdf()) {
        throw new RuntimeException('The PDF engine (TCPDF) is not installed on this server. Upload the vendor/ folder (composer install) or a TCPDF/ folder to enable PDF documents.');
    }

    $siteName = (string)getSetting('site_name', 'Hotel');
    $sitePhone = (string)getSetting('phone_main', '');
    $siteEmail = (string)getSetting('email_main', getSetting('email_from_email', ''));
    $siteAddress = trim((string)getSetting('address_line1', ''));
    $currency = (string)getSetting('currency_symbol', 'MWK');
    $paymentPolicy = (string)getSetting('payment_policy', 'Payment terms apply as agreed with our reservations team.');

    $validDays = max(1, (int)($options['valid_days'] ?? 7));
    $validUntil = (new DateTime())->modify('+' . $validDays . ' days');
    $notes = trim((string)($options['quotation_notes'] ?? ($enquiry['notes'] ?? '')));
    $quoteRef = trim((string)($options['quote_reference'] ?? ''));
    if ($quoteRef === '') {
        $baseRef = (string)($enquiry['inquiry_reference'] ?? ('CONF-' . (int)($enquiry['id'] ?? 0)));
        $quoteRef = 'CQ-' . strtoupper($baseRef);
    }

    $baseAmount = (float)($enquiry['total_amount'] ?? 0);
    $vatRate = (float)($enquiry['vat_rate'] ?? 0);
    $vatAmount = (float)($enquiry['vat_amount'] ?? 0);
    if ($vatAmount <= 0 && $vatRate > 0) {
        // Fallback derives per installation mode (on top / extracted / off).
        $vatAmount = vat_components($baseAmount)['vat'];
    }
    $totalAmount = (float)($enquiry['total_with_vat'] ?? 0);
    if ($totalAmount <= 0) {
        $totalAmount = (function_exists('vat_mode') && vat_mode() === 'inclusive')
            ? $baseAmount
            : $baseAmount + $vatAmount;
    }

    $depositRequired = (float)($enquiry['deposit_required'] ?? 0);
    $roomName = (string)($room['name'] ?? 'Conference Room');
    $eventType = (string)($enquiry['event_type'] ?? 'Conference Event');
    $attendees = max(1, (int)($enquiry['number_of_attendees'] ?? 1));
    $eventDate = !empty($enquiry['event_date'])
        ? date('l, F j, Y', strtotime((string)$enquiry['event_date']))
        : 'To be confirmed';
    $startTime = !empty($enquiry['start_time']) ? date('H:i', strtotime((string)$enquiry['start_time'])) : '';
    $endTime = !empty($enquiry['end_time']) ? date('H:i', strtotime((string)$enquiry['end_time'])) : '';
    $eventTime = trim($startTime . ($endTime !== '' ? ' - ' . $endTime : ''));
    if ($eventTime === '') {
        $eventTime = 'To be confirmed';
    }

    $fmt = static function (float $value) use ($currency): string {
        return $currency . number_format($value, 0);
    };
    $esc = static function (mixed $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    // ── Kit layout (code default) ─────────────────────────────────────────────
    $meta = [
        ['Quotation ref', $quoteRef],
        ['Date issued', date('F j, Y')],
        ['Valid until', $validUntil->format('F j, Y')],
        ['Prepared for', (string)($enquiry['contact_person'] ?? 'Guest')],
        ['Company', (string)($enquiry['company_name'] ?? '')],
        ['Enquiry ref', (string)($enquiry['inquiry_reference'] ?? '')],
        ['Event type', $eventType],
        ['Room', $roomName],
        ['Attendees', (string)$attendees],
        ['Event date', $eventDate],
        ['Event time', $eventTime],
    ];

    $totals = [];
    if ($vatAmount > 0) {
        $totals = array_merge($totals, rh_pdf_vat_rows($vatAmount, $vatRate, trim($currency), $fmt));
    }
    $totals[] = ['label' => 'Total quotation', 'value' => $fmt($totalAmount), 'type' => 'total'];
    if ($depositRequired > 0) {
        $totals[] = ['label' => 'Deposit required', 'value' => $fmt($depositRequired), 'type' => 'strong'];
    }

    $body = rh_pdf_section_title('Price breakdown') . rh_pdf_spacer(2)
        . rh_pdf_items_table(
            [
                ['label' => 'Description', 'width' => 78],
                ['label' => 'Amount', 'width' => 22, 'align' => 'right'],
            ],
            [[rh_pdf_e('Conference package - ' . $roomName), rh_pdf_e($fmt($baseAmount))]]
        )
        . rh_pdf_spacer(2) . rh_pdf_totals_table($totals);

    $body .= rh_pdf_spacer(3) . rh_pdf_section_title('Payment terms', 25) . rh_pdf_spacer(2) . rh_pdf_note($paymentPolicy);
    if ($notes !== '') {
        $body .= rh_pdf_spacer(3) . rh_pdf_section_title('Notes', 25) . rh_pdf_spacer(2) . rh_pdf_note($notes);
    }
    $body .= quotationPdfClosingNotes('This quotation is valid until ' . $validUntil->format('F j, Y') . '. Availability and rates are subject to confirmation at acceptance.');

    $kitHtml = rh_pdf_document_shell('Conference Quotation', $meta, $body);

    $legacyHtml = function_exists('hotel_default_conference_quotation_document_html') ? hotel_default_conference_quotation_document_html() : '';

    return quotationPdfRenderDocument(
        'conference_quotation_document',
        $legacyHtml,
        [
            'logo_html' => quotationPdfLogoHtml(),
            'site_name' => $esc($siteName),
            'address' => $esc($siteAddress),
            'contact_phone' => $esc($sitePhone),
            'contact_email' => $esc($siteEmail),
            'quotation_reference' => $esc($quoteRef),
            'valid_until' => $esc($validUntil->format('F j, Y')),
            'inquiry_reference' => $esc((string)($enquiry['inquiry_reference'] ?? '')),
            'company_name' => $esc((string)($enquiry['company_name'] ?? '')),
            'contact_person' => $esc((string)($enquiry['contact_person'] ?? '')),
            'conference_room' => $esc($roomName),
            'event_date' => $esc($eventDate),
            'event_time' => $esc($eventTime),
            'attendees' => (string)$attendees,
            'total_amount' => $esc($fmt($totalAmount)),
            'vat_amount' => $esc(vat_document_value($fmt($vatAmount))),
            'deposit_amount' => $esc($fmt($depositRequired)),
            'payment_policy' => nl2br($esc($paymentPolicy)),
            'quotation_notes' => nl2br($esc($notes)),
        ],
        'Conference Quotation ' . $quoteRef,
        $kitHtml,
        !empty($options['watermark']) ? ['watermark' => (string)$options['watermark']] : []
    );
}

/**
 * Generate an event quotation PDF for manual event booking proposals.
 *
 * @param array $event     Event row from events table.
 * @param array $recipient Keys: name, email, phone.
 * @param array $options   Optional keys: attendee_count, valid_days, quotation_notes, quote_reference, watermark.
 */
function generateEventQuotationPDF(array $event, array $recipient, array $options = []): string
{
    if (!hotel_load_tcpdf()) {
        throw new RuntimeException('The PDF engine (TCPDF) is not installed on this server. Upload the vendor/ folder (composer install) or a TCPDF/ folder to enable PDF documents.');
    }

    $siteName = (string)getSetting('site_name', 'Hotel');
    $sitePhone = (string)getSetting('phone_main', '');
    $siteEmail = (string)getSetting('email_main', getSetting('email_from_email', ''));
    $currency = (string)getSetting('currency_symbol', 'MWK');

    $attendeeCount = max(1, (int)($options['attendee_count'] ?? 1));
    $validDays = max(1, (int)($options['valid_days'] ?? 7));
    $validUntil = (new DateTime())->modify('+' . $validDays . ' days');
    $notes = trim((string)($options['quotation_notes'] ?? ''));

    $quoteRef = trim((string)($options['quote_reference'] ?? ''));
    if ($quoteRef === '') {
        $eventId = (int)($event['id'] ?? 0);
        $seed = (string)($recipient['email'] ?? ($recipient['name'] ?? time()));
        $quoteRef = 'EQ-' . strtoupper((string)$eventId) . '-' . strtoupper(substr(hash('crc32b', $seed), 0, 6));
    }

    $unitPrice = (float)($event['ticket_price'] ?? 0);
    $totalAmount = $unitPrice * $attendeeCount;

    $eventDate = !empty($event['event_date'])
        ? date('l, F j, Y', strtotime((string)$event['event_date']))
        : 'To be confirmed';
    $startTime = !empty($event['start_time']) ? date('H:i', strtotime((string)$event['start_time'])) : '';
    $endTime = !empty($event['end_time']) ? date('H:i', strtotime((string)$event['end_time'])) : '';
    $eventTime = trim($startTime . ($endTime !== '' ? ' - ' . $endTime : ''));
    if ($eventTime === '') {
        $eventTime = 'To be confirmed';
    }

    $location = (string)($event['location'] ?? 'To be confirmed');
    if ($location === '') {
        $location = 'To be confirmed';
    }

    $fmt = static function (float $value) use ($currency): string {
        return $currency . number_format($value, 0);
    };
    $esc = static function (mixed $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    // ── Kit layout (code default) ─────────────────────────────────────────────
    $meta = [
        ['Quotation ref', $quoteRef],
        ['Date issued', date('F j, Y')],
        ['Valid until', $validUntil->format('F j, Y')],
        ['Prepared for', (string)($recipient['name'] ?? 'Guest')],
        ['Email', (string)($recipient['email'] ?? '')],
        ['Phone', (string)($recipient['phone'] ?? '')],
        ['Event', (string)($event['title'] ?? 'Event')],
        ['Date', $eventDate],
        ['Time', $eventTime],
        ['Location', $location, true],
    ];

    $body = rh_pdf_section_title('Price breakdown') . rh_pdf_spacer(2)
        . rh_pdf_items_table(
            [
                ['label' => 'Description', 'width' => 46],
                ['label' => 'Attendees', 'width' => 14, 'align' => 'center'],
                ['label' => 'Rate each', 'width' => 20, 'align' => 'right'],
                ['label' => 'Amount', 'width' => 20, 'align' => 'right'],
            ],
            [[rh_pdf_e((string)($event['title'] ?? 'Event')), rh_pdf_e((string)$attendeeCount), rh_pdf_e($fmt($unitPrice)), rh_pdf_e($fmt($totalAmount))]]
        )
        . rh_pdf_spacer(2)
        . rh_pdf_totals_table([['label' => 'Total quotation', 'value' => $fmt($totalAmount), 'type' => 'total']]);

    if ($notes !== '') {
        $body .= rh_pdf_spacer(3) . rh_pdf_section_title('Notes', 25) . rh_pdf_spacer(2) . rh_pdf_note($notes);
    }
    $body .= quotationPdfClosingNotes('This quotation is valid until ' . $validUntil->format('F j, Y') . '. Please confirm before the validity date to secure your event booking.');

    $kitHtml = rh_pdf_document_shell('Event Quotation', $meta, $body);

    $legacyHtml = function_exists('hotel_default_event_quotation_document_html') ? hotel_default_event_quotation_document_html() : '';

    return quotationPdfRenderDocument(
        'event_quotation_document',
        $legacyHtml,
        [
            'logo_html' => quotationPdfLogoHtml(),
            'site_name' => $esc($siteName),
            'address' => '',
            'contact_phone' => $esc($sitePhone),
            'contact_email' => $esc($siteEmail),
            'quotation_reference' => $esc($quoteRef),
            'valid_until' => $esc($validUntil->format('F j, Y')),
            'recipient_name' => $esc((string)($recipient['name'] ?? 'Guest')),
            'event_title' => $esc((string)($event['title'] ?? 'Event')),
            'event_date' => $esc($eventDate),
            'event_time' => $esc($eventTime),
            'event_location' => $esc($location),
            'attendee_count' => (string)$attendeeCount,
            'rate_per_attendee' => $esc($fmt($unitPrice)),
            'total_amount' => $esc($fmt($totalAmount)),
            'quotation_notes' => nl2br($esc($notes)),
        ],
        'Event Quotation ' . $quoteRef,
        $kitHtml,
        !empty($options['watermark']) ? ['watermark' => (string)$options['watermark']] : []
    );
}
