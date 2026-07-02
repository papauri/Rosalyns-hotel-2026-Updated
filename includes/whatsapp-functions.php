<?php

/**
 * WhatsApp Notification Functions for Hotel Booking System
 *
 * Supports multiple providers:
 *   - Meta WhatsApp Business Cloud API
 *   - Twilio WhatsApp API
 *   - CallMeBot (simple, free)
 *
 * All settings stored in site_settings table.
 * Feature can be toggled on/off by admin.
 */

if (!defined('WHATSAPP_FUNCTIONS_LOADED')) {
    define('WHATSAPP_FUNCTIONS_LOADED', true);
}

// ============================================================
// SETTINGS HELPERS
// ============================================================

/**
 * Check if WhatsApp notifications are enabled
 */
function isWhatsAppEnabled(): bool
{
    return getSetting('whatsapp_enabled', getSetting('whatsapp_notifications_enabled', '0')) === '1';
}

/**
 * Get the configured WhatsApp provider
 */
function getWhatsAppProvider(): string
{
    return getSetting('whatsapp_provider', 'meta');
}

/**
 * Get the hotel's WhatsApp number (E.164 format)
 */
function getHotelWhatsAppNumber(): string
{
    return getSetting('whatsapp_number', getSetting('whatsapp_hotel_number', getSetting('phone_main', '')));
}

// ============================================================
// CORE SENDER
// ============================================================

/**
 * Send a WhatsApp message to a given phone number.
 *
 * @param string $to   Phone number in E.164 format (+353860081635)
 * @param string $body Plain text message body (max ~4096 chars)
 * @return array ['success'=>bool, 'message'=>string]
 */
function sendWhatsAppMessage(string $to, string $body): array
{
    if (!isWhatsAppEnabled()) {
        return ['success' => false, 'message' => 'WhatsApp notifications are disabled'];
    }

    // Normalize number (ensure + prefix)
    $to = normaliseWhatsAppNumber($to);
    if (empty($to)) {
        return ['success' => false, 'message' => 'Invalid WhatsApp number'];
    }

    $provider = getWhatsAppProvider();

    switch ($provider) {
        case 'twilio':
            return sendWhatsAppViaTwilio($to, $body);
        case 'meta':
            return sendWhatsAppViaMeta($to, $body);
        case 'callmebot':
        default:
            return sendWhatsAppViaCallMeBot($to, $body);
    }
}

/**
 * Normalise a phone number to E.164 format
 */
function normaliseWhatsAppNumber(string $number): string
{
    $number = trim($number);
    if (empty($number)) return '';
    // Keep only digits and leading +
    $number = preg_replace('/[^0-9+]/', '', $number);
    // Ensure leading +
    if ($number[0] !== '+') {
        $number = '+' . $number;
    }
    // Must be at least 8 digits
    if (strlen(preg_replace('/[^0-9]/', '', $number)) < 8) {
        return '';
    }
    return $number;
}

// ============================================================
// PROVIDER: CALLMEBOT (Free, simple, no registration for numbers
//            that have already sent /start to CallMeBot)
// ============================================================

function sendWhatsAppViaCallMeBot(string $to, string $body): array
{
    $apiKey = getSetting('whatsapp_callmebot_api_key', '');
    if (empty($apiKey)) {
        return ['success' => false, 'message' => 'CallMeBot API key not configured'];
    }

    $phone = ltrim($to, '+');
    $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
        'phone'  => $phone,
        'text'   => $body,
        'apikey' => $apiKey,
    ]);

    $result = whatsAppHttpGet($url);
    $success = $result['code'] === 200 && stripos($result['body'], 'Message queued') !== false;

    logWhatsApp($to, 'callmebot', $success, $result['body']);

    return [
        'success' => $success,
        'message' => $success ? 'WhatsApp message sent via CallMeBot' : 'CallMeBot error: ' . $result['body'],
    ];
}

// ============================================================
// PROVIDER: TWILIO
// ============================================================

function sendWhatsAppViaTwilio(string $to, string $body): array
{
    $accountSid = getSetting('whatsapp_twilio_account_sid', '');
    $authToken  = getSetting('whatsapp_twilio_auth_token', '');
    $from       = getSetting('whatsapp_twilio_from_number', '');

    if (empty($accountSid) || empty($authToken) || empty($from)) {
        return ['success' => false, 'message' => 'Twilio credentials not configured'];
    }

    $url  = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
    $data = [
        'From' => 'whatsapp:' . $from,
        'To'   => 'whatsapp:' . $to,
        'Body' => $body,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_USERPWD        => "{$accountSid}:{$authToken}",
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body_resp = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($body_resp, true);
    $success = $httpCode >= 200 && $httpCode < 300 && !empty($decoded['sid']);

    logWhatsApp($to, 'twilio', $success, $body_resp);

    return [
        'success' => $success,
        'message' => $success ? 'WhatsApp sent via Twilio (SID: ' . ($decoded['sid'] ?? '') . ')' : 'Twilio error: ' . ($decoded['message'] ?? $body_resp),
    ];
}

// ============================================================
// PROVIDER: META CLOUD API
// ============================================================

function sendWhatsAppViaMeta(string $to, string $body): array
{
    $accessToken = getSetting('whatsapp_meta_access_token', getSetting('whatsapp_api_token', ''));
    $phoneNumberId = getSetting('whatsapp_meta_phone_number_id', getSetting('whatsapp_phone_id', ''));

    if (empty($accessToken) || empty($phoneNumberId)) {
        return ['success' => false, 'message' => 'Meta WhatsApp credentials not configured'];
    }

    $url = "https://graph.facebook.com/v19.0/{$phoneNumberId}/messages";

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => ltrim($to, '+'),
        'type'              => 'text',
        'text'              => ['body' => $body],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$accessToken}",
        ],
    ]);
    $body_resp = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($body_resp, true);
    $success = $httpCode === 200 && !empty($decoded['messages'][0]['id']);

    logWhatsApp($to, 'meta', $success, $body_resp);

    return [
        'success' => $success,
        'message' => $success ? 'WhatsApp sent via Meta Cloud API' : 'Meta error: ' . ($decoded['error']['message'] ?? $body_resp),
    ];
}

// ============================================================
// HELPER: HTTP GET
// ============================================================

function whatsAppHttpGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'HotelBookingSystem/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || !empty($err)) {
        return ['code' => 0, 'body' => 'cURL error: ' . $err];
    }
    return ['code' => $code, 'body' => $body];
}

// ============================================================
// LOGGING
// ============================================================

function logWhatsApp(string $to, string $provider, bool $success, string $response = ''): void
{
    $logEnabled = getSetting('whatsapp_log_enabled', '1') === '1';
    if (!$logEnabled) return;

    $logDir = __DIR__ . '/../logs';
    if (!file_exists($logDir)) mkdir($logDir, 0755, true);

    $status = $success ? 'SENT' : 'FAILED';
    $line = "[" . date('Y-m-d H:i:s') . "] [WhatsApp:{$provider}] [{$status}] To: {$to}";
    if (!$success) $line .= " | Response: " . substr($response, 0, 200);
    $line .= "\n";

    file_put_contents($logDir . '/whatsapp-log.txt', $line, FILE_APPEND | LOCK_EX);
}

// ============================================================
// TEMPLATE ENGINE
// ============================================================

/**
 * Get a WhatsApp message template from site_settings
 * with variable substitution.
 *
 * @param string $templateKey  e.g. 'booking_received', 'booking_confirmed'
 * @param array  $vars         Key=>value replacements for {{key}} placeholders
 * @return string  Rendered message text
 */
function renderWhatsAppTemplate(string $templateKey, array $vars): string
{
    // 1. File template (templates/whatsapp/{key}.txt) — easiest to edit
    $fileTemplate = loadWhatsAppTemplateFile($templateKey);

    // 2. Database override
    $settingKey = 'whatsapp_tpl_' . $templateKey;
    $dbTemplate = getSetting($settingKey, '');

    // 3. Built-in default
    $template = $fileTemplate !== '' ? $fileTemplate : ($dbTemplate !== '' ? $dbTemplate : getDefaultWhatsAppTemplate($templateKey));

    foreach ($vars as $k => $v) {
        $template = str_replace('{{' . $k . '}}', (string)$v, $template);
    }

    return $template;
}

/**
 * Load a WhatsApp template from the templates/whatsapp/ folder.
 * Returns empty string if the file does not exist.
 */
function loadWhatsAppTemplateFile(string $templateKey): string
{
    $safeKey = preg_replace('/[^a-z0-9_]/i', '', $templateKey);
    if ($safeKey === '') {
        return '';
    }
    $path = __DIR__ . '/../templates/whatsapp/' . $safeKey . '.txt';
    if (!is_file($path)) {
        return '';
    }
    $contents = @file_get_contents($path);
    return is_string($contents) ? rtrim($contents, "\r\n") : '';
}

/**
 * Build standard booking variables for templates
 */
function buildWhatsAppBookingVars(array $booking, array $room = []): array
{
    $siteName      = getSetting('site_name');
    $currency      = getSetting('currency_symbol');
    $checkInTime   = getSetting('check_in_time', '2:00 PM');
    $checkOutTime  = getSetting('check_out_time', '11:00 AM');
    $phoneMain     = getSetting('phone_main', '');
    $hotelWa       = getHotelWhatsAppNumber();

    return [
        'hotel_name'           => $siteName,
        'booking_reference'    => $booking['booking_reference'] ?? '',
        'guest_name'           => $booking['guest_name'] ?? '',
        'guest_phone'          => $booking['guest_phone'] ?? '',
        'room_name'            => $room['name'] ?? ($booking['room_name'] ?? 'Room'),
        'check_in_date'        => !empty($booking['check_in_date']) ? date('D, d M Y', strtotime($booking['check_in_date'])) : '',
        'check_out_date'       => !empty($booking['check_out_date']) ? date('D, d M Y', strtotime($booking['check_out_date'])) : '',
        'nights'               => (string)($booking['number_of_nights'] ?? 1),
        'guests'               => (string)($booking['number_of_guests'] ?? 1),
        'adults'               => (string)($booking['adult_guests'] ?? $booking['number_of_guests'] ?? 1),
        'children'             => (string)($booking['child_guests'] ?? 0),
        'total_amount'         => $currency . ' ' . number_format((float)($booking['total_amount'] ?? 0), 0),
        'check_in_time'        => $checkInTime,
        'check_out_time'       => $checkOutTime,
        'special_requests'     => !empty($booking['special_requests']) ? $booking['special_requests'] : 'None',
        'hotel_phone'          => $phoneMain,
        'hotel_whatsapp'       => $hotelWa,
        'occupancy_type'       => ucfirst($booking['occupancy_type'] ?? 'double'),
        'status'               => ucfirst($booking['status'] ?? 'pending'),
    ];
}

/**
 * Build conference enquiry template variables.
 */
function buildConferenceWhatsAppVars(array $enquiry, array $room = [], array $extra = []): array
{
    $currency = getSetting('currency_symbol');
    $siteName = getSetting('site_name');

    $total = (float)($enquiry['total_with_vat'] ?? 0);
    if ($total <= 0) {
        $base = (float)($enquiry['total_amount'] ?? 0);
        $vat = (float)($enquiry['vat_amount'] ?? 0);
        $total = $base + $vat;
    }

    $startTime = !empty($enquiry['start_time']) ? date('H:i', strtotime((string)$enquiry['start_time'])) : '';
    $endTime = !empty($enquiry['end_time']) ? date('H:i', strtotime((string)$enquiry['end_time'])) : '';
    $eventTime = trim($startTime . ($endTime !== '' ? ' - ' . $endTime : ''));
    if ($eventTime === '') {
        $eventTime = 'To be confirmed';
    }

    $vars = [
        'hotel_name' => $siteName,
        'guest_name' => (string)($enquiry['contact_person'] ?? 'Guest'),
        'contact_person' => (string)($enquiry['contact_person'] ?? 'Guest'),
        'company_name' => (string)($enquiry['company_name'] ?? ''),
        'inquiry_reference' => (string)($enquiry['inquiry_reference'] ?? ''),
        'quote_reference' => (string)($extra['quote_reference'] ?? ''),
        'quotation_reference' => (string)($extra['quote_reference'] ?? ''),
        'conference_room' => (string)($room['name'] ?? ($enquiry['room_name'] ?? 'Conference Room')),
        'event_type' => (string)($enquiry['event_type'] ?? 'Conference Event'),
        'event_date' => !empty($enquiry['event_date']) ? date('D, d M Y', strtotime((string)$enquiry['event_date'])) : 'To be confirmed',
        'event_time' => $eventTime,
        'attendees' => (string)max(1, (int)($enquiry['number_of_attendees'] ?? 1)),
        'total_amount' => $currency . ' ' . number_format($total, 0),
        'valid_until' => (string)($extra['valid_until'] ?? ''),
        'quotation_notes' => (string)($extra['quotation_notes'] ?? ''),
        'hotel_phone' => getSetting('phone_main', ''),
    ];

    return array_merge($vars, $extra);
}

/**
 * Default WhatsApp message templates
 */
function getDefaultWhatsAppTemplate(string $key): string
{
    $siteName = getSetting('site_name');
    $templates = [
        'booking_received' =>
        "🏨 *{{hotel_name}}*\n\n" .
            "✅ *New Booking Received!*\n\n" .
            "Hello {{guest_name}}, thank you for choosing us!\n\n" .
            "📋 *Booking Details*\n" .
            "Reference: *{{booking_reference}}*\n" .
            "Room: {{room_name}}\n" .
            "Check-in: {{check_in_date}} at {{check_in_time}}\n" .
            "Check-out: {{check_out_date}} at {{check_out_time}}\n" .
            "Nights: {{nights}}\n" .
            "Guests: {{guests}} (Adults: {{adults}}, Children: {{children}})\n" .
            "Total: *{{total_amount}}*\n\n" .
            "Special Requests: {{special_requests}}\n\n" .
            "Our team will review and confirm your booking shortly.\n" .
            "📞 {{hotel_phone}}",

        'booking_confirmed' =>
        "🏨 *{{hotel_name}}*\n\n" .
            "🎉 *Booking CONFIRMED!*\n\n" .
            "Dear {{guest_name}},\n" .
            "Your reservation has been confirmed!\n\n" .
            "📋 *Confirmed Booking*\n" .
            "Reference: *{{booking_reference}}*\n" .
            "Room: {{room_name}}\n" .
            "Check-in: {{check_in_date}} at {{check_in_time}}\n" .
            "Check-out: {{check_out_date}} at {{check_out_time}}\n" .
            "Nights: {{nights}} | Guests: {{guests}}\n" .
            "Total: *{{total_amount}}*\n\n" .
            "We look forward to welcoming you!\n" .
            "📞 {{hotel_phone}}",

        'booking_cancelled' =>
        "🏨 *{{hotel_name}}*\n\n" .
            "❌ *Booking Cancelled*\n\n" .
            "Dear {{guest_name}},\n" .
            "Your booking *{{booking_reference}}* has been cancelled.\n\n" .
            "Check-in: {{check_in_date}}\n" .
            "Check-out: {{check_out_date}}\n" .
            "Room: {{room_name}}\n\n" .
            "If this was a mistake, please contact us:\n" .
            "📞 {{hotel_phone}}",

        'tentative_created' =>
        "🏨 *{{hotel_name}}*\n\n" .
            "⏳ *Tentative Booking Placed*\n\n" .
            "Dear {{guest_name}},\n" .
            "Your room has been placed on tentative hold.\n\n" .
            "📋 *Details*\n" .
            "Reference: *{{booking_reference}}*\n" .
            "Room: {{room_name}}\n" .
            "Check-in: {{check_in_date}}\n" .
            "Check-out: {{check_out_date}}\n" .
            "Total: *{{total_amount}}*\n\n" .
            "⚠️ Please confirm within the hold period.\n" .
            "Reply to this message or call: 📞 {{hotel_phone}}",

        'checkin_reminder' =>
        "🏨 *{{hotel_name}}*\n\n" .
            "🔔 *Check-in Reminder*\n\n" .
            "Dear {{guest_name}},\n" .
            "Your stay begins tomorrow!\n\n" .
            "Reference: *{{booking_reference}}*\n" .
            "Check-in: {{check_in_date}} at {{check_in_time}}\n" .
            "Room: {{room_name}}\n\n" .
            "We look forward to seeing you!\n" .
            "📞 {{hotel_phone}}",

        // Admin notification templates
        'admin_new_booking' =>
        "🔔 *NEW BOOKING ALERT*\n" .
            "━━━━━━━━━━━━━━━━━━━\n" .
            "Hotel: {{hotel_name}}\n" .
            "Ref: *{{booking_reference}}*\n" .
            "Guest: {{guest_name}}\n" .
            "Phone: {{guest_phone}}\n" .
            "Room: {{room_name}}\n" .
            "In: {{check_in_date}} | Out: {{check_out_date}}\n" .
            "Nights: {{nights}} | Guests: {{guests}}\n" .
            "💰 Total: *{{total_amount}}*\n" .
            "Special: {{special_requests}}",

        'admin_booking_confirmed' =>
        "✅ *BOOKING CONFIRMED*\n" .
            "━━━━━━━━━━━━━━━━━━━\n" .
            "Ref: *{{booking_reference}}*\n" .
            "Guest: {{guest_name}} | {{guest_phone}}\n" .
            "Room: {{room_name}}\n" .
            "In: {{check_in_date}} | Out: {{check_out_date}}\n" .
            "💰 *{{total_amount}}*",

        'admin_booking_cancelled' =>
        "❌ *BOOKING CANCELLED*\n" .
            "━━━━━━━━━━━━━━━━━━━\n" .
            "Ref: *{{booking_reference}}*\n" .
            "Guest: {{guest_name}} | {{guest_phone}}\n" .
            "Room: {{room_name}}\n" .
            "Was: {{check_in_date}} → {{check_out_date}}\n" .
            "💰 *{{total_amount}}*",

        'payment_invoice' =>
        "🏨 *{{hotel_name}}*\n\n" .
            "🧾 *Payment Received*\n\n" .
            "Dear {{guest_name}},\n" .
            "Thank you — we have received your payment.\n\n" .
            "📋 *Invoice {{invoice_number}}*\n" .
            "Booking: *{{booking_reference}}*\n" .
            "Room: {{room_name}}\n" .
            "Check-in: {{check_in_date}}\n" .
            "Check-out: {{check_out_date}}\n\n" .
            "Amount paid: *{{amount_paid}}*\n" .
            "Method: {{payment_method}}\n" .
            "Date: {{payment_date}}\n" .
            "Balance: {{amount_due}}\n\n" .
            "📎 View invoice: {{invoice_url}}\n\n" .
            "📞 {{hotel_phone}}",

        'restaurant_receipt' =>
        "🍽️ *{{hotel_name}} — Restaurant*\n\n" .
            "✅ *Order Receipt*\n\n" .
            "Order: *{{order_reference}}*\n" .
            "Date: {{payment_date}}\n\n" .
            "{{order_summary}}\n\n" .
            "Total: *{{order_total}}*\n" .
            "Paid via: {{payment_method}}\n" .
            "Change: {{change_due}}\n\n" .
            "Thank you for dining with us!\n" .
            "📞 {{hotel_phone}}",

        'room_quotation' =>
        "🏨 *{{hotel_name}}*\n\n" .
            "📄 *Room Quotation*\n\n" .
            "Hello {{guest_name}}, here is your quotation for booking *{{booking_reference}}*.\n\n" .
            "Quotation Ref: *{{quote_reference}}*\n" .
            "Room: {{room_name}}\n" .
            "Check-in: {{check_in_date}}\n" .
            "Check-out: {{check_out_date}}\n" .
            "Guests: {{guests}}\n" .
            "Total: *{{total_amount}}*\n" .
            "Valid until: *{{valid_until}}*\n\n" .
            "{{quotation_notes}}\n\n" .
            "Reply to confirm or call {{hotel_phone}}.",

        'conference_quotation' =>
        "🏨 *{{hotel_name}}*\n\n" .
            "📄 *Conference Quotation*\n\n" .
            "Hello {{contact_person}}, your conference quotation is ready.\n\n" .
            "Ref: *{{inquiry_reference}}*\n" .
            "Quote Ref: *{{quote_reference}}*\n" .
            "Room: {{conference_room}}\n" .
            "Date: {{event_date}}\n" .
            "Time: {{event_time}}\n" .
            "Attendees: {{attendees}}\n" .
            "Total: *{{total_amount}}*\n" .
            "Valid until: *{{valid_until}}*\n\n" .
            "{{quotation_notes}}\n\n" .
            "For confirmation, contact {{hotel_phone}}.",

        'event_quotation' =>
        "🏨 *{{hotel_name}}*\n\n" .
            "🎫 *Event Quotation*\n\n" .
            "Hello {{recipient_name}}, your event quotation is ready.\n\n" .
            "Quote Ref: *{{quote_reference}}*\n" .
            "Event: {{event_title}}\n" .
            "Date: {{event_date}}\n" .
            "Time: {{event_time}}\n" .
            "Location: {{event_location}}\n" .
            "Attendees: {{attendee_count}}\n" .
            "Total: *{{total_amount}}*\n" .
            "Valid until: *{{valid_until}}*\n\n" .
            "{{quotation_notes}}\n\n" .
            "For confirmation, contact {{hotel_phone}}.",

        'gym_quotation' =>
        "🏨 *{{hotel_name}}*\n\n" .
            "💪 *Gym Membership Quotation*\n\n" .
            "Hello {{recipient_name}}, your gym membership quotation is ready.\n\n" .
            "Ref: *{{inquiry_reference}}*\n" .
            "Quote Ref: *{{quote_reference}}*\n" .
            "Package: {{membership_type}}\n" .
            "Total: *{{total_amount}}*\n" .
            "Valid until: *{{valid_until}}*\n\n" .
            "{{quotation_notes}}\n\n" .
            "For confirmation, contact {{hotel_phone}}.",

        'event_inquiry_quotation' =>
        "🏨 *{{hotel_name}}*\n\n" .
            "🎫 *Event Booking Quotation*\n\n" .
            "Hello {{recipient_name}}, your event booking quotation is ready.\n\n" .
            "Ref: *{{inquiry_reference}}*\n" .
            "Quote Ref: *{{quote_reference}}*\n" .
            "Event: {{event_title}}\n" .
            "Guests: {{guests}}\n" .
            "Total: *{{total_amount}}*\n" .
            "Valid until: *{{valid_until}}*\n\n" .
            "{{quotation_notes}}\n\n" .
            "For confirmation, contact {{hotel_phone}}.",
    ];

    return $templates[$key] ?? "{{hotel_name}}: Booking {{booking_reference}} update.";
}

// ============================================================
// HIGH-LEVEL BOOKING NOTIFICATION FUNCTIONS
// ============================================================

/**
 * Send WhatsApp notifications for a new standard booking
 * - to guest (if guest_phone is set)
 * - to hotel WhatsApp number
 */
function sendBookingWhatsAppNotifications(array $booking, array $room = []): array
{
    if (!isWhatsAppEnabled()) {
        return [
            'guest' => ['success' => false, 'message' => 'WhatsApp disabled'],
            'hotel' => ['success' => false, 'message' => 'WhatsApp disabled']
        ];
    }

    $vars = buildWhatsAppBookingVars($booking, $room);

    // Guest notification
    $guestResult = ['success' => false, 'message' => 'No guest phone'];
    $guestPhone  = normaliseWhatsAppNumber($booking['guest_phone'] ?? '');
    if (!empty($guestPhone)) {
        $guestMsg   = renderWhatsAppTemplate('booking_received', $vars);
        $guestResult = sendWhatsAppMessage($guestPhone, $guestMsg);
    }

    // Hotel notification
    $hotelResult = ['success' => false, 'message' => 'No hotel WhatsApp number'];
    $hotelPhone  = normaliseWhatsAppNumber(getHotelWhatsAppNumber());
    if (!empty($hotelPhone)) {
        $adminMsg   = renderWhatsAppTemplate('admin_new_booking', $vars);
        $hotelResult = sendWhatsAppMessage($hotelPhone, $adminMsg);
    }

    return ['guest' => $guestResult, 'hotel' => $hotelResult];
}

/**
 * Send WhatsApp notifications when booking is confirmed by admin
 */
function sendBookingConfirmedWhatsApp(array $booking, array $room = []): array
{
    if (!isWhatsAppEnabled()) {
        return [
            'guest' => ['success' => false, 'message' => 'WhatsApp disabled'],
            'hotel' => ['success' => false, 'message' => 'WhatsApp disabled']
        ];
    }

    $vars = buildWhatsAppBookingVars($booking, $room);

    $guestResult = ['success' => false, 'message' => 'No guest phone'];
    $guestPhone  = normaliseWhatsAppNumber($booking['guest_phone'] ?? '');
    if (!empty($guestPhone)) {
        $msg = renderWhatsAppTemplate('booking_confirmed', $vars);
        $guestResult = sendWhatsAppMessage($guestPhone, $msg);
    }

    $hotelResult = ['success' => false, 'message' => 'No hotel WhatsApp'];
    $hotelPhone  = normaliseWhatsAppNumber(getHotelWhatsAppNumber());
    if (!empty($hotelPhone)) {
        $msg = renderWhatsAppTemplate('admin_booking_confirmed', $vars);
        $hotelResult = sendWhatsAppMessage($hotelPhone, $msg);
    }

    return ['guest' => $guestResult, 'hotel' => $hotelResult];
}

/**
 * Send WhatsApp notifications when booking is cancelled
 */
function sendBookingCancelledWhatsApp(array $booking, array $room = []): array
{
    if (!isWhatsAppEnabled()) {
        return [
            'guest' => ['success' => false, 'message' => 'WhatsApp disabled'],
            'hotel' => ['success' => false, 'message' => 'WhatsApp disabled']
        ];
    }

    $vars = buildWhatsAppBookingVars($booking, $room);

    $guestResult = ['success' => false, 'message' => 'No guest phone'];
    $guestPhone  = normaliseWhatsAppNumber($booking['guest_phone'] ?? '');
    if (!empty($guestPhone)) {
        $msg = renderWhatsAppTemplate('booking_cancelled', $vars);
        $guestResult = sendWhatsAppMessage($guestPhone, $msg);
    }

    $hotelResult = ['success' => false, 'message' => 'No hotel WhatsApp'];
    $hotelPhone  = normaliseWhatsAppNumber(getHotelWhatsAppNumber());
    if (!empty($hotelPhone)) {
        $msg = renderWhatsAppTemplate('admin_booking_cancelled', $vars);
        $hotelResult = sendWhatsAppMessage($hotelPhone, $msg);
    }

    return ['guest' => $guestResult, 'hotel' => $hotelResult];
}

/**
 * Send WhatsApp notifications for tentative booking created
 */
function sendTentativeWhatsAppNotifications(array $booking, array $room = []): array
{
    if (!isWhatsAppEnabled()) {
        return [
            'guest' => ['success' => false, 'message' => 'WhatsApp disabled'],
            'hotel' => ['success' => false, 'message' => 'WhatsApp disabled']
        ];
    }

    $vars = buildWhatsAppBookingVars($booking, $room);

    $guestResult = ['success' => false, 'message' => 'No guest phone'];
    $guestPhone  = normaliseWhatsAppNumber($booking['guest_phone'] ?? '');
    if (!empty($guestPhone)) {
        $msg = renderWhatsAppTemplate('tentative_created', $vars);
        $guestResult = sendWhatsAppMessage($guestPhone, $msg);
    }

    $hotelResult = ['success' => false, 'message' => 'No hotel WhatsApp'];
    $hotelPhone  = normaliseWhatsAppNumber(getHotelWhatsAppNumber());
    if (!empty($hotelPhone)) {
        $msg = renderWhatsAppTemplate('admin_new_booking', $vars);
        $hotelResult = sendWhatsAppMessage($hotelPhone, $msg);
    }

    return ['guest' => $guestResult, 'hotel' => $hotelResult];
}

/**
 * Send a test WhatsApp message (used from admin settings page)
 */
function sendWhatsAppTestMessage(string $to, string $message = ''): array
{
    if (empty($message)) {
        $siteName = getSetting('site_name');
        $message  = "✅ *{$siteName}*\n\nWhatsApp test message successful!\nSent at: " . date('d M Y H:i:s');
    }
    return sendWhatsAppMessage($to, $message);
}

/**
 * Send WhatsApp invoice/payment confirmation to guest.
 *
 * The function is a no-op when WhatsApp is disabled or the guest has no phone,
 * which makes it safe to call from booking/payment flows even before Meta
 * credentials are configured.
 *
 * @param array       $booking    Booking row (must contain guest_phone, booking_reference, etc).
 * @param array       $payment    ['invoice_number'=>..., 'amount_paid'=>..., 'amount_due'=>...,
 *                                 'payment_method'=>..., 'payment_date'=>..., 'invoice_url'=>...]
 * @param array       $room       Optional room row.
 */
function sendBookingInvoiceWhatsApp(array $booking, array $payment = [], array $room = []): array
{
    if (!isWhatsAppEnabled()) {
        return ['guest' => ['success' => false, 'message' => 'WhatsApp disabled']];
    }

    $vars = buildWhatsAppBookingVars($booking, $room);
    $currency = getSetting('currency_symbol');
    $vars['invoice_number'] = (string)($payment['invoice_number'] ?? ($booking['invoice_number'] ?? ''));
    $vars['invoice_url']    = (string)($payment['invoice_url']    ?? ($booking['invoice_url']    ?? ''));
    $vars['amount_paid']    = $currency . ' ' . number_format((float)($payment['amount_paid'] ?? $booking['total_amount'] ?? 0), 0);
    $vars['amount_due']     = $currency . ' ' . number_format((float)($payment['amount_due']  ?? 0), 0);
    $vars['payment_method'] = ucfirst(str_replace('_', ' ', (string)($payment['payment_method'] ?? '')));
    $vars['payment_date']   = !empty($payment['payment_date']) ? date('d M Y H:i', strtotime((string)$payment['payment_date'])) : date('d M Y H:i');

    $guestResult = ['success' => false, 'message' => 'No guest phone'];
    $guestPhone  = normaliseWhatsAppNumber($booking['guest_phone'] ?? '');
    if (!empty($guestPhone)) {
        $msg = renderWhatsAppTemplate('payment_invoice', $vars);
        $guestResult = sendWhatsAppMessage($guestPhone, $msg);
    }

    return ['guest' => $guestResult];
}

/**
 * Send WhatsApp restaurant order receipt to a customer phone.
 *
 * @param string $customerPhone E.164 customer phone number.
 * @param array  $order         POS order row (reference, total_amount, change_due, payment_method, paid_at).
 * @param array  $items         Optional list of ['item_name'=>..., 'quantity'=>..., 'line_total'=>...].
 */
function sendRestaurantReceiptWhatsApp(string $customerPhone, array $order, array $items = []): array
{
    if (!isWhatsAppEnabled()) {
        return ['success' => false, 'message' => 'WhatsApp disabled'];
    }

    $phone = normaliseWhatsAppNumber($customerPhone);
    if (empty($phone)) {
        return ['success' => false, 'message' => 'No customer phone'];
    }

    $currency = getSetting('currency_symbol');
    $summaryLines = [];
    foreach ($items as $item) {
        $qty   = (string)($item['quantity'] ?? '');
        $name  = (string)($item['item_name'] ?? '');
        $total = $currency . ' ' . number_format((float)($item['line_total'] ?? 0), 0);
        if ($qty !== '' && $name !== '') {
            $summaryLines[] = "{$qty} × {$name} — {$total}";
        }
    }
    $summary = $summaryLines ? implode("\n", $summaryLines) : 'See attached receipt.';

    $vars = [
        'hotel_name'      => getSetting('site_name'),
        'hotel_phone'     => getSetting('phone_main', ''),
        'order_reference' => (string)($order['reference'] ?? ''),
        'order_total'     => $currency . ' ' . number_format((float)($order['total_amount'] ?? 0), 0),
        'change_due'      => $currency . ' ' . number_format((float)($order['change_due'] ?? 0), 0),
        'payment_method'  => ucfirst(str_replace('_', ' ', (string)($order['payment_method'] ?? ''))),
        'payment_date'    => !empty($order['paid_at']) ? date('d M Y H:i', strtotime((string)$order['paid_at'])) : date('d M Y H:i'),
        'order_summary'   => $summary,
    ];

    $msg = renderWhatsAppTemplate('restaurant_receipt', $vars);
    return sendWhatsAppMessage($phone, $msg);
}

/**
 * Send room quotation details to a tentative booking guest.
 */
function sendRoomQuotationWhatsApp(array $booking, array $room = [], array $options = []): array
{
    if (!isWhatsAppEnabled()) {
        return ['success' => false, 'message' => 'WhatsApp disabled'];
    }

    $phone = normaliseWhatsAppNumber((string)($booking['guest_phone'] ?? ''));
    if (empty($phone)) {
        return ['success' => false, 'message' => 'No guest phone'];
    }

    $validDays = max(1, (int)($options['valid_days'] ?? 7));
    $quoteRef = (string)($options['quote_reference'] ?? ('QT-' . strtoupper((string)($booking['booking_reference'] ?? ''))));
    $notes = trim((string)($options['quotation_notes'] ?? ''));
    $validUntil = (new DateTime())->modify('+' . $validDays . ' days')->format('F j, Y');

    $vars = buildWhatsAppBookingVars($booking, $room);
    $vars['quote_reference'] = $quoteRef;
    $vars['quotation_reference'] = $quoteRef;
    $vars['valid_until'] = $validUntil;
    $vars['quotation_notes'] = $notes !== '' ? $notes : 'Please confirm before the validity date to secure this rate.';
    $vars['check_in_date'] = !empty($booking['check_in_date']) ? date('D, d M Y', strtotime((string)$booking['check_in_date'])) : '';
    $vars['check_out_date'] = !empty($booking['check_out_date']) ? date('D, d M Y', strtotime((string)$booking['check_out_date'])) : '';
    $vars['guests'] = $vars['adults'] . ' adults' . ((int)$vars['children'] > 0 ? ', ' . $vars['children'] . ' children' : '');

    $message = renderWhatsAppTemplate('room_quotation', $vars);
    return sendWhatsAppMessage($phone, $message);
}

/**
 * Send conference quotation details via WhatsApp.
 */
function sendConferenceQuotationWhatsApp(array $enquiry, array $room = [], array $options = []): array
{
    if (!isWhatsAppEnabled()) {
        return ['success' => false, 'message' => 'WhatsApp disabled'];
    }

    $phone = normaliseWhatsAppNumber((string)($enquiry['phone'] ?? ''));
    if (empty($phone)) {
        return ['success' => false, 'message' => 'No contact phone'];
    }

    $validDays = max(1, (int)($options['valid_days'] ?? 7));
    $quoteRef = (string)($options['quote_reference'] ?? ('CQ-' . strtoupper((string)($enquiry['inquiry_reference'] ?? ''))));
    $notes = trim((string)($options['quotation_notes'] ?? ''));
    $validUntil = (new DateTime())->modify('+' . $validDays . ' days')->format('F j, Y');

    $vars = buildConferenceWhatsAppVars($enquiry, $room, [
        'quote_reference' => $quoteRef,
        'valid_until' => $validUntil,
        'quotation_notes' => $notes !== '' ? $notes : 'Please confirm before the validity date to secure this quotation.',
    ]);

    $message = renderWhatsAppTemplate('conference_quotation', $vars);
    return sendWhatsAppMessage($phone, $message);
}

/**
 * Send event quotation details via WhatsApp.
 */
function sendEventQuotationWhatsApp(array $event, array $recipient = [], array $options = []): array
{
    if (!isWhatsAppEnabled()) {
        return ['success' => false, 'message' => 'WhatsApp disabled'];
    }

    $phone = normaliseWhatsAppNumber((string)($recipient['phone'] ?? ''));
    if (empty($phone)) {
        return ['success' => false, 'message' => 'No recipient phone'];
    }

    $currency = getSetting('currency_symbol');
    $attendeeCount = max(1, (int)($options['attendee_count'] ?? 1));
    $validDays = max(1, (int)($options['valid_days'] ?? 7));
    $quoteRef = (string)($options['quote_reference'] ?? ('EQ-' . strtoupper((string)($event['id'] ?? '0'))));
    $notes = trim((string)($options['quotation_notes'] ?? ''));
    $unitPrice = (float)($event['ticket_price'] ?? 0);
    $totalAmount = $unitPrice * $attendeeCount;
    $validUntil = (new DateTime())->modify('+' . $validDays . ' days')->format('F j, Y');

    $startTime = !empty($event['start_time']) ? date('H:i', strtotime((string)$event['start_time'])) : '';
    $endTime = !empty($event['end_time']) ? date('H:i', strtotime((string)$event['end_time'])) : '';
    $eventTime = trim($startTime . ($endTime !== '' ? ' - ' . $endTime : ''));
    if ($eventTime === '') {
        $eventTime = 'To be confirmed';
    }

    $vars = [
        'hotel_name' => getSetting('site_name'),
        'recipient_name' => (string)($recipient['name'] ?? 'Guest'),
        'quote_reference' => $quoteRef,
        'quotation_reference' => $quoteRef,
        'event_title' => (string)($event['title'] ?? 'Event'),
        'event_date' => !empty($event['event_date']) ? date('D, d M Y', strtotime((string)$event['event_date'])) : 'To be confirmed',
        'event_time' => $eventTime,
        'event_location' => (string)($event['location'] ?? 'To be confirmed'),
        'attendee_count' => (string)$attendeeCount,
        'total_amount' => $currency . ' ' . number_format($totalAmount, 0),
        'valid_until' => $validUntil,
        'quotation_notes' => $notes !== '' ? $notes : 'Please confirm before the validity date to secure your event booking.',
        'hotel_phone' => getSetting('phone_main', ''),
    ];

    $message = renderWhatsAppTemplate('event_quotation', $vars);
    return sendWhatsAppMessage($phone, $message);
}

/**
 * Send a gym membership quotation via WhatsApp (mirrors sendConferenceQuotationWhatsApp).
 */
function sendGymQuotationWhatsApp(array $inquiry, array $options = []): array
{
    if (!isWhatsAppEnabled()) {
        return ['success' => false, 'message' => 'WhatsApp disabled'];
    }

    $phone = normaliseWhatsAppNumber((string)($inquiry['phone'] ?? ''));
    if (empty($phone)) {
        return ['success' => false, 'message' => 'No contact phone'];
    }

    $currency = getSetting('currency_symbol');
    $validDays = max(1, (int)($options['valid_days'] ?? 7));
    $quoteRef = (string)($options['quote_reference'] ?? ('GQ-' . strtoupper((string)($inquiry['reference_number'] ?? ''))));
    $notes = trim((string)($options['quotation_notes'] ?? ''));
    $validUntil = (new DateTime())->modify('+' . $validDays . ' days')->format('F j, Y');

    $baseAmount = (float)($inquiry['total_amount'] ?? 0);
    $vatAmount = (float)($inquiry['vat_amount'] ?? 0);
    $totalAmount = (float)($inquiry['total_with_vat'] ?? 0);
    if ($totalAmount <= 0) {
        $totalAmount = $baseAmount + $vatAmount;
    }

    $vars = [
        'hotel_name' => getSetting('site_name'),
        'recipient_name' => (string)($inquiry['name'] ?? 'Guest'),
        'inquiry_reference' => (string)($inquiry['reference_number'] ?? ''),
        'quote_reference' => $quoteRef,
        'quotation_reference' => $quoteRef,
        'membership_type' => (string)($inquiry['membership_type'] ?? ''),
        'total_amount' => $currency . ' ' . number_format($totalAmount, 0),
        'valid_until' => $validUntil,
        'quotation_notes' => $notes !== '' ? $notes : 'Please confirm before the validity date to secure this quotation.',
        'hotel_phone' => getSetting('phone_main', ''),
    ];

    $message = renderWhatsAppTemplate('gym_quotation', $vars);
    return sendWhatsAppMessage($phone, $message);
}

/**
 * Send an event booking quotation via WhatsApp (mirrors sendGymQuotationWhatsApp).
 * Distinct from sendEventQuotationWhatsApp, which sends an ad-hoc quote against
 * an events listing row rather than an event_inquiries booking.
 */
function sendEventInquiryQuotationWhatsApp(array $inquiry, array $options = []): array
{
    if (!isWhatsAppEnabled()) {
        return ['success' => false, 'message' => 'WhatsApp disabled'];
    }

    $phone = normaliseWhatsAppNumber((string)($inquiry['phone'] ?? ''));
    if (empty($phone)) {
        return ['success' => false, 'message' => 'No contact phone'];
    }

    $currency = getSetting('currency_symbol');
    $validDays = max(1, (int)($options['valid_days'] ?? 7));
    $quoteRef = (string)($options['quote_reference'] ?? ('EQ-' . strtoupper((string)($inquiry['reference_number'] ?? ''))));
    $notes = trim((string)($options['quotation_notes'] ?? ''));
    $validUntil = (new DateTime())->modify('+' . $validDays . ' days')->format('F j, Y');

    $baseAmount = (float)($inquiry['total_amount'] ?? 0);
    $vatAmount = (float)($inquiry['vat_amount'] ?? 0);
    $totalAmount = (float)($inquiry['total_with_vat'] ?? 0);
    if ($totalAmount <= 0) {
        $totalAmount = $baseAmount + $vatAmount;
    }

    $vars = [
        'hotel_name' => getSetting('site_name'),
        'recipient_name' => (string)($inquiry['name'] ?? 'Guest'),
        'inquiry_reference' => (string)($inquiry['reference_number'] ?? ''),
        'quote_reference' => $quoteRef,
        'quotation_reference' => $quoteRef,
        'event_title' => (string)($inquiry['event_title'] ?? 'Event'),
        'guests' => (string)max(1, (int)($inquiry['guests'] ?? 1)),
        'total_amount' => $currency . ' ' . number_format($totalAmount, 0),
        'valid_until' => $validUntil,
        'quotation_notes' => $notes !== '' ? $notes : 'Please confirm before the validity date to secure your event booking.',
        'hotel_phone' => getSetting('phone_main', ''),
    ];

    $message = renderWhatsAppTemplate('event_inquiry_quotation', $vars);
    return sendWhatsAppMessage($phone, $message);
}
