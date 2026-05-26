<?php

/**
 * docs/guides/12-email-templates.php
 * Email Template Variables & HTML Tags Reference Guide
 * Searchable via ?q= GET parameter.
 */

declare(strict_types=1);

// ── Hotel branding from DB ────────────────────────────────────────────────────
$_guide_site_name = 'Hotel';
try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../../config/cache.php';
    $_guide_site_name = getSetting('site_name') ?: 'Hotel';
} catch (Throwable $e) { /* fail silently — fallback text stays */ }

// ── Search query ──────────────────────────────────────────────────────────────
$query     = trim(strip_tags($_GET['q'] ?? ''));
$qLow      = strtolower($query);

// ── All template variables ────────────────────────────────────────────────────
// Each entry: cat, tag, description, example, templates (comma-separated template names)
$all_vars = [

    /* ── Guest & Booking ───────────────────────────────────────── */
    [
        'cat' => 'Guest & Booking',
        'tag' => '{{guest_name}}',
        'desc' => 'Full name of the guest who made the booking.',
        'example' => 'John Banda',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Guest & Booking',
        'tag' => '{{guest_email}}',
        'desc' => 'Email address of the guest.',
        'example' => 'john.banda@example.com',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Guest & Booking',
        'tag' => '{{guest_phone}}',
        'desc' => 'Phone number of the guest.',
        'example' => '+265 999 000 111',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Guest & Booking',
        'tag' => '{{booking_reference}}',
        'desc' => 'Unique booking reference code generated on submission.',
        'example' => 'LSH2026147371',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Guest & Booking',
        'tag' => '{{number_of_guests}}',
        'desc' => 'Total number of guests (adults + children combined).',
        'example' => '3',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Guest & Booking',
        'tag' => '{{adult_guests}}',
        'desc' => 'Number of adult guests only.',
        'example' => '2',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Guest & Booking',
        'tag' => '{{child_guests}}',
        'desc' => 'Number of child guests only.',
        'example' => '1',
        'tpl' => 'All booking emails'
    ],

    /* ── Room & Stay ───────────────────────────────────────────── */
    [
        'cat' => 'Room & Stay',
        'tag' => '{{room_name}}',
        'desc' => 'Room type name, with assigned room number appended if available (e.g. "Deluxe Suite — Room 12").',
        'example' => 'Deluxe Suite — Room 12',
        'tpl' => 'booking_received, booking_confirmed, booking_cancelled'
    ],

    [
        'cat' => 'Room & Stay',
        'tag' => '{{room_assignment}}',
        'desc' => 'Specific room number(s) assigned to the booking.',
        'example' => '12, 14',
        'tpl' => 'booking_confirmed'
    ],

    [
        'cat' => 'Room & Stay',
        'tag' => '{{room_numbers}}',
        'desc' => 'Alias for {{room_assignment}}. Outputs the same value.',
        'example' => '12, 14',
        'tpl' => 'booking_confirmed'
    ],

    [
        'cat' => 'Room & Stay',
        'tag' => '{{check_in_date}}',
        'desc' => 'Check-in date in long format: day of week, month, day, year.',
        'example' => 'Monday, June 1, 2026',
        'tpl' => 'All booking emails, tentative_quotation'
    ],

    [
        'cat' => 'Room & Stay',
        'tag' => '{{check_out_date}}',
        'desc' => 'Check-out date in long format.',
        'example' => 'Thursday, June 4, 2026',
        'tpl' => 'All booking emails, tentative_quotation'
    ],

    [
        'cat' => 'Room & Stay',
        'tag' => '{{check_in_date_formatted}}',
        'desc' => 'Check-in date in short format: Month Day, Year.',
        'example' => 'June 1, 2026',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Room & Stay',
        'tag' => '{{check_out_date_formatted}}',
        'desc' => 'Check-out date in short format.',
        'example' => 'June 4, 2026',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Room & Stay',
        'tag' => '{{check_in_time}}',
        'desc' => 'Official check-in time configured in Booking Settings.',
        'example' => '2:00 PM',
        'tpl' => 'booking_confirmed, tentative_booking_converted'
    ],

    [
        'cat' => 'Room & Stay',
        'tag' => '{{check_out_time}}',
        'desc' => 'Official check-out time configured in Booking Settings.',
        'example' => '11:00 AM',
        'tpl' => 'booking_confirmed, tentative_booking_converted'
    ],

    [
        'cat' => 'Room & Stay',
        'tag' => '{{number_of_nights}}',
        'desc' => 'Total number of nights of the stay.',
        'example' => '3',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Room & Stay',
        'tag' => '{{nights}}',
        'desc' => 'Alias for {{number_of_nights}}.',
        'example' => '3',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Room & Stay',
        'tag' => '{{special_requests}}',
        'desc' => 'Any special requests entered by the guest during booking.',
        'example' => 'Late check-out if possible, ground floor room',
        'tpl' => 'booking_received, booking_confirmed'
    ],

    [
        'cat' => 'Room & Stay',
        'tag' => '{{rate_plan_label}}',
        'desc' => 'Name of the rate plan applied to the booking (e.g. Bed & Breakfast, Early Bird).',
        'example' => 'Bed & Breakfast',
        'tpl' => 'booking_received, booking_confirmed'
    ],

    /* ── Pricing & Payment ─────────────────────────────────────── */
    [
        'cat' => 'Pricing & Payment',
        'tag' => '{{currency_symbol}}',
        'desc' => 'Currency symbol from Site Settings.',
        'example' => 'MWK',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Pricing & Payment',
        'tag' => '{{total_amount}}',
        'desc' => 'Total booking amount, formatted with commas (no symbol).',
        'example' => '250,000',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Pricing & Payment',
        'tag' => '{{total_amount_formatted}}',
        'desc' => 'Alias for {{total_amount}}. Outputs the same formatted number.',
        'example' => '250,000',
        'tpl' => 'All booking emails'
    ],

    [
        'cat' => 'Pricing & Payment',
        'tag' => '{{rate_per_night}}',
        'desc' => 'Price per night for the booked room type.',
        'example' => '75,000',
        'tpl' => 'booking_received, booking_confirmed'
    ],

    [
        'cat' => 'Pricing & Payment',
        'tag' => '{{room_subtotal}}',
        'desc' => 'Room cost subtotal before VAT and extra charges.',
        'example' => '225,000',
        'tpl' => 'booking_received, booking_confirmed'
    ],

    [
        'cat' => 'Pricing & Payment',
        'tag' => '{{vat_amount}}',
        'desc' => 'VAT portion of the total booking cost.',
        'example' => '25,000',
        'tpl' => 'booking_received, booking_confirmed'
    ],

    [
        'cat' => 'Pricing & Payment',
        'tag' => '{{child_supplement_total_formatted}}',
        'desc' => 'Total child supplement charge added to the booking.',
        'example' => '15,000',
        'tpl' => 'booking_received, booking_confirmed'
    ],

    [
        'cat' => 'Pricing & Payment',
        'tag' => '{{child_price_multiplier}}',
        'desc' => 'Child pricing percentage (e.g. 50 means children pay 50% of adult rate).',
        'example' => '50',
        'tpl' => 'booking_received, booking_confirmed'
    ],

    [
        'cat' => 'Pricing & Payment',
        'tag' => '{{rate_plan_discount_formatted}}',
        'desc' => 'Discount amount applied from the selected rate plan.',
        'example' => '10,000',
        'tpl' => 'booking_received, booking_confirmed'
    ],

    [
        'cat' => 'Pricing & Payment',
        'tag' => '{{package_total_formatted}}',
        'desc' => 'Total value of any add-on packages selected at booking.',
        'example' => '20,000',
        'tpl' => 'booking_received, booking_confirmed'
    ],

    [
        'cat' => 'Pricing & Payment',
        'tag' => '{{payment_policy}}',
        'desc' => 'Hotel\'s payment policy text, taken from Booking Settings.',
        'example' => 'Full payment is required upon check-in.',
        'tpl' => 'booking_received, booking_confirmed, tentative_booking_converted'
    ],

    /* ── Tentative Bookings ─────────────────────────────────────── */
    [
        'cat' => 'Tentative Bookings',
        'tag' => '{{tentative_expires_at_formatted}}',
        'desc' => 'Date and time when the tentative hold expires.',
        'example' => 'May 25, 2026 5:00 PM',
        'tpl' => 'tentative_booking_created, tentative_booking_reminder'
    ],

    [
        'cat' => 'Tentative Bookings',
        'tag' => '{{tentative_status}}',
        'desc' => 'Current status label of the tentative booking (capitalised).',
        'example' => 'Tentative',
        'tpl' => 'tentative_booking_created'
    ],

    /* ── Cancellations ─────────────────────────────────────────── */
    [
        'cat' => 'Cancellations',
        'tag' => '{{cancellation_reason}}',
        'desc' => 'Reason text entered by the admin when cancelling the booking.',
        'example' => 'Guest changed travel plans',
        'tpl' => 'booking_cancelled'
    ],

    /* ── Hotel Info ────────────────────────────────────────────── */
    [
        'cat' => 'Hotel Info',
        'tag' => '{{site_name}}',
        'desc' => 'Hotel name from Site Settings.',
        'example' => $_guide_site_name,
        'tpl' => 'All emails'
    ],

    [
        'cat' => 'Hotel Info',
        'tag' => '{{site_url}}',
        'desc' => 'Hotel website URL from Site Settings.',
        'example' => 'https://rosalynslodge.com',
        'tpl' => 'All emails'
    ],

    [
        'cat' => 'Hotel Info',
        'tag' => '{{contact_email}}',
        'desc' => 'Hotel\'s outgoing from-address email (used for reply-to links).',
        'example' => 'reservations@rosalynslodge.com',
        'tpl' => 'All emails'
    ],

    [
        'cat' => 'Hotel Info',
        'tag' => '{{phone_main}}',
        'desc' => 'Hotel\'s main telephone number.',
        'example' => '+265 1 234 567',
        'tpl' => 'All emails'
    ],

    [
        'cat' => 'Hotel Info',
        'tag' => '{{hotel_phone}}',
        'desc' => 'Alias for {{phone_main}}. Used specifically in credit note emails.',
        'example' => '+265 1 234 567',
        'tpl' => 'credit_note'
    ],

    [
        'cat' => 'Hotel Info',
        'tag' => '{{hotel_address}}',
        'desc' => 'Full hotel postal/street address.',
        'example' => 'Area 3, Lilongwe, Malawi',
        'tpl' => 'credit_note'
    ],

    /* ── Credit Notes ──────────────────────────────────────────── */
    [
        'cat' => 'Credit Notes',
        'tag' => '{{credit_note_number}}',
        'desc' => 'Unique credit note reference number.',
        'example' => 'CN-2026-0042',
        'tpl' => 'credit_note'
    ],

    [
        'cat' => 'Credit Notes',
        'tag' => '{{amount}}',
        'desc' => 'Face value of the credit note (formatted with commas, no symbol).',
        'example' => '50,000',
        'tpl' => 'credit_note'
    ],

    [
        'cat' => 'Credit Notes',
        'tag' => '{{balance}}',
        'desc' => 'Remaining available balance on the credit note.',
        'example' => '50,000',
        'tpl' => 'credit_note'
    ],

    [
        'cat' => 'Credit Notes',
        'tag' => '{{amount_used}}',
        'desc' => 'Amount already redeemed from this credit note.',
        'example' => '0',
        'tpl' => 'credit_note'
    ],

    [
        'cat' => 'Credit Notes',
        'tag' => '{{reason}}',
        'desc' => 'Short reason the credit note was issued (e.g. refund, goodwill, early departure).',
        'example' => 'refund',
        'tpl' => 'credit_note'
    ],

    [
        'cat' => 'Credit Notes',
        'tag' => '{{reason_notes}}',
        'desc' => 'Longer note or explanation accompanying the credit note reason.',
        'example' => 'Guest departed one night early due to family emergency',
        'tpl' => 'credit_note'
    ],

    [
        'cat' => 'Credit Notes',
        'tag' => '{{expires_at}}',
        'desc' => 'Expiry date of the credit note (formatted as d M Y).',
        'example' => '30 May 2027',
        'tpl' => 'credit_note'
    ],

    /* ── Conference & Events ───────────────────────────────────── */
    [
        'cat' => 'Conference & Events',
        'tag' => '{{contact_person}}',
        'desc' => 'Name of the primary contact person for a conference or event inquiry.',
        'example' => 'Grace Chirwa',
        'tpl' => 'conference_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{recipient_name}}',
        'desc' => 'General recipient name used in event quotation emails.',
        'example' => 'Grace Chirwa',
        'tpl' => 'event_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{company_name}}',
        'desc' => 'Company or organisation name of the conference/event client.',
        'example' => 'Lilongwe Holdings Ltd',
        'tpl' => 'conference_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{quotation_reference}}',
        'desc' => 'Quotation reference number.',
        'example' => 'QUOT-2026-0015',
        'tpl' => 'conference_quotation, event_quotation, tentative_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{quote_reference}}',
        'desc' => 'Alias for {{quotation_reference}}.',
        'example' => 'QUOT-2026-0015',
        'tpl' => 'conference_quotation, tentative_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{inquiry_reference}}',
        'desc' => 'Conference inquiry reference number (auto-generated on submission).',
        'example' => 'CONF-2026-0007',
        'tpl' => 'conference_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{conference_room}}',
        'desc' => 'Name of the conference room or venue being booked.',
        'example' => 'Baobab Suite',
        'tpl' => 'conference_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{event_date}}',
        'desc' => 'Date of the event.',
        'example' => 'June 15, 2026',
        'tpl' => 'conference_quotation, event_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{event_time}}',
        'desc' => 'Start time of the event.',
        'example' => '10:00 AM',
        'tpl' => 'conference_quotation, event_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{event_title}}',
        'desc' => 'Title or name of the event.',
        'example' => 'Annual Strategy Summit',
        'tpl' => 'event_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{event_location}}',
        'desc' => 'Venue or location description for the event.',
        'example' => 'Main Hall, Ground Floor',
        'tpl' => 'event_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{attendee_count}}',
        'desc' => 'Number of expected attendees.',
        'example' => '50',
        'tpl' => 'conference_quotation, event_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{attendees}}',
        'desc' => 'Alias for {{attendee_count}}.',
        'example' => '50',
        'tpl' => 'conference_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{valid_until}}',
        'desc' => 'Expiry date of the quotation offer.',
        'example' => 'June 1, 2026',
        'tpl' => 'conference_quotation, event_quotation, tentative_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{quotation_notes}}',
        'desc' => 'Additional notes or terms included in the quotation.',
        'example' => 'Includes AV equipment and morning tea break.',
        'tpl' => 'conference_quotation, event_quotation, tentative_quotation'
    ],

    [
        'cat' => 'Conference & Events',
        'tag' => '{{contact_phone}}',
        'desc' => 'Phone number of the conference/event contact person.',
        'example' => '+265 999 888 777',
        'tpl' => 'conference_quotation, event_quotation, tentative_quotation'
    ],
];

// ── Filter by search query ────────────────────────────────────────────────────
if ($qLow !== '') {
    $all_vars = array_values(array_filter($all_vars, static function (array $v) use ($qLow): bool {
        return str_contains(strtolower($v['tag']),  $qLow)
            || str_contains(strtolower($v['desc']), $qLow)
            || str_contains(strtolower($v['cat']),  $qLow)
            || str_contains(strtolower($v['tpl']),  $qLow)
            || str_contains(strtolower($v['example']), $qLow);
    }));
}

// ── Group by category ─────────────────────────────────────────────────────────
$grouped = [];
foreach ($all_vars as $v) {
    $grouped[$v['cat']][] = $v;
}

$totalFound = count($all_vars);

// ── Template registry ─────────────────────────────────────────────────────────
$templates = [
    [
        'key' => 'booking_received',
        'name' => 'Booking Received (Customer)',
        'desc' => 'Sent to the guest immediately after they submit a booking request. Lets them know the booking is awaiting confirmation.',
        'triggers' => 'Public booking form submission'
    ],

    [
        'key' => 'booking_confirmed',
        'name' => 'Booking Confirmed (Customer)',
        'desc' => 'Sent when a staff member confirms the booking from the admin panel.',
        'triggers' => 'Admin → Bookings → Confirm'
    ],

    [
        'key' => 'booking_cancelled',
        'name' => 'Booking Cancelled (Customer)',
        'desc' => 'Sent when a booking is cancelled, including the cancellation reason.',
        'triggers' => 'Admin → Bookings → Cancel'
    ],

    [
        'key' => 'payment_invoice',
        'name' => 'Payment Invoice (Customer)',
        'desc' => 'Sent with a PDF invoice attachment after a payment is recorded.',
        'triggers' => 'Admin → Payments → Add Payment (if email invoice enabled)'
    ],

    [
        'key' => 'tentative_booking_created',
        'name' => 'Tentative Booking Created',
        'desc' => 'Sent when a booking is placed on a tentative/hold status with an expiry date.',
        'triggers' => 'Admin → Bookings → Create Tentative'
    ],

    [
        'key' => 'tentative_booking_reminder',
        'name' => 'Tentative Booking Reminder',
        'desc' => 'Reminder email sent before the tentative hold expires.',
        'triggers' => 'Automatic — cron or manual trigger from Tentative Bookings page'
    ],

    [
        'key' => 'tentative_booking_expired',
        'name' => 'Tentative Booking Expired',
        'desc' => 'Notifies the guest that their tentative hold has lapsed and the room is released.',
        'triggers' => 'Automatic — when hold expiry passes'
    ],

    [
        'key' => 'tentative_booking_converted',
        'name' => 'Tentative Booking Converted',
        'desc' => 'Sent when a tentative booking is confirmed/converted to a full confirmed booking.',
        'triggers' => 'Admin → Tentative Bookings → Convert'
    ],

    [
        'key' => 'tentative_quotation',
        'name' => 'Tentative Booking Quotation',
        'desc' => 'A formal quotation email sent for a tentative booking.',
        'triggers' => 'Admin → Quotations → Send to guest'
    ],

    [
        'key' => 'conference_quotation',
        'name' => 'Conference Quotation',
        'desc' => 'Quotation email sent to a conference/event client.',
        'triggers' => 'Admin → Conference Management → Send Quotation'
    ],

    [
        'key' => 'event_quotation',
        'name' => 'Event Quotation',
        'desc' => 'Quotation email for a general event enquiry.',
        'triggers' => 'Admin → Events Management → Send Quotation'
    ],

    [
        'key' => 'credit_note',
        'name' => 'Credit Note (Guest)',
        'desc' => 'Sent with a credit note PDF when a credit note is issued to a guest.',
        'triggers' => 'Admin → Credit Notes → Issue & Send'
    ],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Template Reference — <?php echo htmlspecialchars($_guide_site_name, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/guide-theme.css">
    <style>
        /* ── Search bar ─────────────────────────────────────────────── */
        .search-wrap {
            display: flex;
            gap: 0.6rem;
            margin: 1.5rem 0 0.5rem;
            max-width: 560px;
        }

        .search-wrap input[type="search"] {
            flex: 1;
            padding: 0.65rem 1rem;
            border: 1px solid var(--line-strong);
            border-radius: 8px;
            background: white;
            font-family: 'Jost', sans-serif;
            font-size: 0.95rem;
            color: var(--ink);
            outline: none;
            transition: border-color 0.2s;
        }

        .search-wrap input[type="search"]:focus {
            border-color: var(--gold);
        }

        .search-wrap button {
            padding: 0.65rem 1.4rem;
            background: var(--gold);
            color: var(--dark);
            border: none;
            border-radius: 8px;
            font-family: 'Jost', sans-serif;
            font-size: 0.92rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            white-space: nowrap;
        }

        .search-wrap button:hover {
            background: var(--gold-soft);
        }

        .search-clear {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.85rem;
            color: var(--brown);
            text-decoration: none;
            border: 1px solid var(--line-strong);
            border-bottom-color: var(--line-strong);
            padding: 0.65rem 0.9rem;
            border-radius: 8px;
            transition: 0.2s;
            white-space: nowrap;
        }

        .search-clear:hover {
            background: white;
            border-color: var(--brown);
        }

        .search-status {
            font-size: 0.88rem;
            color: var(--muted);
            margin: 0.4rem 0 1.8rem;
        }

        .search-status strong {
            color: var(--gold);
        }

        .search-status .no-results {
            color: #c0392b;
            font-weight: 500;
        }

        /* ── Tag pill ───────────────────────────────────────────────── */
        .tag-pill {
            display: inline-block;
            font-family: 'JetBrains Mono', 'Consolas', monospace;
            font-size: 0.83rem;
            background: rgba(212, 168, 67, 0.12);
            color: var(--brown);
            border: 1px solid rgba(212, 168, 67, 0.3);
            padding: 0.18em 0.55em;
            border-radius: 5px;
            white-space: nowrap;
        }

        .tag-pill.highlight {
            background: rgba(212, 168, 67, 0.28);
            border-color: var(--gold);
        }

        /* ── Category heading ───────────────────────────────────────── */
        .cat-heading {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin: 2.4rem 0 0.8rem;
        }

        .cat-heading h3 {
            margin: 0;
            font-size: clamp(1.1rem, 1.5vw, 1.3rem);
            color: var(--dark);
        }

        .cat-count {
            font-family: 'Cormorant Garamond', serif;
            font-size: 0.88rem;
            color: var(--muted);
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 0.1em 0.65em;
        }

        /* ── Variable table ─────────────────────────────────────────── */
        .var-table td:first-child {
            width: 28%;
            min-width: 200px;
        }

        .var-table td:nth-child(2) {
            width: 42%;
        }

        .var-table td:last-child {
            width: 30%;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .var-table .tpl-badge {
            display: inline-block;
            font-size: 0.76rem;
            background: rgba(26, 26, 26, 0.07);
            border-radius: 4px;
            padding: 0.1em 0.45em;
            margin: 0.15em 0.15em 0.15em 0;
            white-space: nowrap;
        }

        /* ── Template key table ─────────────────────────────────────── */
        .tpl-key {
            font-family: 'JetBrains Mono', 'Consolas', monospace;
            font-size: 0.83rem;
            color: var(--brown);
            background: rgba(212, 168, 67, 0.1);
            padding: 0.18em 0.5em;
            border-radius: 4px;
        }

        /* ── HTML tag section ───────────────────────────────────────── */
        .html-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 1rem;
            margin: 1.4rem 0;
        }

        .html-card {
            background: white;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 1.2rem 1.4rem;
            box-shadow: var(--shadow-soft);
        }

        .html-card .tag-name {
            font-family: 'JetBrains Mono', 'Consolas', monospace;
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--brown);
            margin-bottom: 0.4rem;
        }

        .html-card p {
            font-size: 0.9rem;
            margin: 0 0 0.5rem;
            max-width: 100%;
        }

        .html-card .example-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--muted);
            margin: 0.6rem 0 0.2rem;
        }

        .html-card pre {
            margin: 0;
            font-size: 0.78rem;
            padding: 0.6rem 0.8rem;
        }

        .avoid-table td:first-child {
            font-family: 'JetBrains Mono', 'Consolas', monospace;
            font-size: 0.87rem;
            color: #c0392b;
        }

        .avoid-table tr td {
            background: none !important;
        }

        .avoid-table tr:nth-child(even) td {
            background: rgba(248, 243, 233, 0.45) !important;
        }

        /* ── Quick-copy bar ─────────────────────────────────────────── */
        .copy-tip {
            background: white;
            border: 1px solid var(--line);
            border-left: 4px solid var(--gold);
            border-radius: 8px;
            padding: 0.9rem 1.2rem;
            font-size: 0.9rem;
            margin: 1rem 0 1.5rem;
        }

        /* ── Section anchor links ───────────────────────────────────── */
        .toc {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .toc li {
            border-bottom: 1px dashed var(--line);
        }

        .toc li:last-child {
            border: none;
        }

        .toc a {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 0;
            font-size: 0.95rem;
            border: none;
        }

        .toc a:hover {
            color: var(--brown);
        }

        .toc a span {
            color: var(--muted);
            font-size: 0.8rem;
        }

        @media (max-width: 640px) {
            .var-table td:first-child {
                width: 100%;
                display: block;
            }

            .var-table tr {
                display: block;
                margin-bottom: 1rem;
            }

            .var-table td {
                display: block;
                width: 100% !important;
                padding: 0.3rem 0.6rem;
            }

            .var-table th {
                display: none;
            }
        }
    </style>
    <script src="assets/guide-init.js" defer></script>
</head>

<body>

    <nav class="top">
        <a href="index.html" class="brand"><?php echo htmlspecialchars(strtoupper($_guide_site_name), ENT_QUOTES, 'UTF-8'); ?></a>
        <div class="nav-links">
            <a href="index.html">All Guides</a>
            <a href="99-admin-dashboard-full-guide.html">Admin Bible</a>
            <a href="12-email-templates.php" class="active">Email Templates</a>
        </div>
    </nav>

    <header class="hero">
        <div class="deck">
            <div class="eyebrow">Guide 12 &middot; Admin Reference</div>
            <h1>Email Template Tags</h1>
            <p class="lead">Every <code>{{variable}}</code> tag available in email templates — searchable, with live examples and the templates they apply to. Plus an HTML tag reference for building well-formatted emails.</p>
            <div class="meta">
                <span>Location <strong>/admin/booking-settings.php → Email Templates</strong></span>
                <span>Tags <strong><?php echo count($all_vars) === $totalFound ? $totalFound : $totalFound; ?> defined</strong></span>
                <span>Read time <strong>8 min</strong></span>
            </div>
        </div>
    </header>

    <main class="content">
        <div class="deck">

            <!-- ── Table of contents ───────────────────────────────────── -->
            <section id="toc">
                <h2>Contents</h2>
                <ul class="toc">
                    <li><a href="#how-to-use">How template tags work <span>↓</span></a></li>
                    <li><a href="#variables">Variable reference (searchable) <span>↓</span></a></li>
                    <li><a href="#templates">Available email templates <span>↓</span></a></li>
                    <li><a href="#html-tags">HTML tags for email <span>↓</span></a></li>
                    <li><a href="#best-practices">Best practices <span>↓</span></a></li>
                </ul>
            </section>

            <!-- ── How to use ──────────────────────────────────────────── -->
            <section id="how-to-use">
                <h2>How template tags work</h2>
                <p>Email templates live in the database and are editable from <strong>Booking Settings → Email Templates</strong>. Each template contains a subject line and an HTML body. Inside both, you can place <strong>double-curly-brace tags</strong> and the system will replace them with real data when the email is sent.</p>

                <div class="copy-tip">
                    <strong>Syntax:</strong> wrap the tag name in double curly braces — <code>{{guest_name}}</code><br>
                    Tags are case-sensitive. Copy them exactly as shown in the table below.<br>
                    Tags can appear in <strong>both the subject line and the HTML body</strong>.
                </div>

                <h3>Example — subject line</h3>
                <pre><code>Booking Confirmed — {{site_name}} [{{booking_reference}}]</code></pre>
                <p>→ renders as: <em>Booking Confirmed — <?php echo htmlspecialchars($_guide_site_name, ENT_QUOTES, 'UTF-8'); ?> [LSH2026147371]</em></p>

                <h3>Example — body paragraph</h3>
                <pre><code>Dear {{guest_name}},

Your {{number_of_nights}}-night stay in the {{room_name}} is confirmed.
Check in on {{check_in_date_formatted}} from {{check_in_time}}.
Total: {{currency_symbol}} {{total_amount_formatted}}.</code></pre>

                <h3>What happens when a tag isn't available?</h3>
                <p>If a tag is not applicable to a particular template (e.g. <code>{{credit_note_number}}</code> in a booking email), it is left as-is or outputs empty string. Always check the <em>Templates</em> column in the table below to confirm a tag is supported by your chosen template.</p>

                <div class="callout warn">
                    Tags are replaced using PHP <code>strtr()</code> — not eval'd or executed. They are safe to use in any part of the HTML body, including inside <code>href</code> attributes for links.
                </div>
            </section>

            <!-- ── Variable reference ──────────────────────────────────── -->
            <section id="variables">
                <h2>Variable reference</h2>

                <!-- Search form -->
                <form method="GET" action="12-email-templates.php#variables">
                    <div class="search-wrap">
                        <input
                            type="search"
                            name="q"
                            placeholder="Search tags, descriptions, categories…"
                            value="<?php echo htmlspecialchars($query, ENT_QUOTES, 'UTF-8'); ?>"
                            autocomplete="off"
                            autofocus>
                        <button type="submit">Search</button>
                        <?php if ($query !== ''): ?>
                            <a href="12-email-templates.php#variables" class="search-clear">✕ Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Search status -->
                <p class="search-status">
                    <?php if ($query !== ''): ?>
                        <?php if ($totalFound > 0): ?>
                            Showing <strong><?php echo $totalFound; ?></strong> tag<?php echo $totalFound !== 1 ? 's' : ''; ?> matching <strong>&ldquo;<?php echo htmlspecialchars($query, ENT_QUOTES, 'UTF-8'); ?>&rdquo;</strong>
                        <?php else: ?>
                            <span class="no-results">No tags matched &ldquo;<?php echo htmlspecialchars($query, ENT_QUOTES, 'UTF-8'); ?>&rdquo; — try a shorter word or a category name.</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php echo count($all_vars); ?> tags across <?php echo count($grouped); ?> categories. Use the search box to filter.
                    <?php endif; ?>
                </p>

                <?php if (empty($grouped)): ?>
                    <div class="callout warn">No matching tags found. <a href="12-email-templates.php#variables">Clear search →</a></div>
                <?php else: ?>
                    <?php foreach ($grouped as $catName => $vars): ?>
                        <div class="cat-heading">
                            <h3><?php echo htmlspecialchars($catName, ENT_QUOTES, 'UTF-8'); ?></h3>
                            <span class="cat-count"><?php echo count($vars); ?> tag<?php echo count($vars) !== 1 ? 's' : ''; ?></span>
                        </div>
                        <table class="var-table">
                            <thead>
                                <tr>
                                    <th>Tag</th>
                                    <th>Description &amp; Example</th>
                                    <th>Available in</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vars as $v):
                                    $isMatch = $qLow !== '' && (
                                        str_contains(strtolower($v['tag']), $qLow) ||
                                        str_contains(strtolower($v['example']), $qLow)
                                    );
                                ?>
                                    <tr>
                                        <td>
                                            <span class="tag-pill<?php echo $isMatch ? ' highlight' : ''; ?>">
                                                <?php echo htmlspecialchars($v['tag'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($v['desc'], ENT_QUOTES, 'UTF-8'); ?>
                                            <br><small style="color:var(--muted);">e.g. <em><?php echo htmlspecialchars($v['example'], ENT_QUOTES, 'UTF-8'); ?></em></small>
                                        </td>
                                        <td>
                                            <?php
                                            $tpls = explode(',', $v['tpl']);
                                            foreach ($tpls as $t) {
                                                echo '<span class="tpl-badge">' . htmlspecialchars(trim($t), ENT_QUOTES, 'UTF-8') . '</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- ── Available templates ─────────────────────────────────── -->
            <section id="templates">
                <h2>Available email templates</h2>
                <p>These are the 12 template slots stored in the <code>booking_email_templates</code> table. Each has a <strong>template key</strong> (immutable), a display name, and a purpose. Edit their HTML body and subject from <strong>Admin → Booking Settings → Email Templates</strong>.</p>
                <table>
                    <thead>
                        <tr>
                            <th>Template key</th>
                            <th>Display name</th>
                            <th>When it's sent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $t): ?>
                            <tr>
                                <td><span class="tpl-key"><?php echo htmlspecialchars($t['key'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></strong><br><small style="color:var(--muted);font-size:0.85em;"><?php echo htmlspecialchars($t['desc'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                                <td style="color:var(--muted);font-size:0.9rem;"><?php echo htmlspecialchars($t['triggers'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="callout">
                    <strong>Tip:</strong> The template key cannot be changed. When the system looks up a template (e.g. <code>booking_confirmed</code>), it uses the key — not the display name. You can safely rename the display name without breaking email delivery.
                </div>
            </section>

            <!-- ── HTML tags for email ─────────────────────────────────── -->
            <section id="html-tags">
                <h2>HTML tags for email</h2>
                <p>Email clients (Gmail, Outlook, Apple Mail) render HTML differently from web browsers. Always use <strong>inline styles</strong> and <strong>table-based layouts</strong> for reliable rendering. The tags below are safe across all major clients.</p>

                <h3>Safe structural tags</h3>
                <div class="html-grid">

                    <div class="html-card">
                        <div class="tag-name">&lt;table&gt; &lt;tr&gt; &lt;td&gt;</div>
                        <p>The only reliable layout tool in email. Use nested tables for columns, padding, and spacing. Never use flexbox or grid.</p>
                        <div class="example-label">Example</div>
                        <pre><code>&lt;table width="100%" style="border-collapse:collapse;"&gt;
  &lt;tr&gt;
    &lt;td style="padding:12px;"&gt;
      Content here
    &lt;/td&gt;
  &lt;/tr&gt;
&lt;/table&gt;</code></pre>
                    </div>

                    <div class="html-card">
                        <div class="tag-name">&lt;p&gt;</div>
                        <p>Standard paragraph. Always add <code>style="margin:0 0 16px;"</code> — Outlook resets margins aggressively.</p>
                        <div class="example-label">Example</div>
                        <pre><code>&lt;p style="margin:0 0 16px;
  font-family:Arial,sans-serif;
  font-size:15px;line-height:1.6;
  color:#2A2723;"&gt;
  Dear {{guest_name}},
&lt;/p&gt;</code></pre>
                    </div>

                    <div class="html-card">
                        <div class="tag-name">&lt;h1&gt; — &lt;h4&gt;</div>
                        <p>Use for headings. Always specify <code>font-family</code> and <code>color</code> inline — web fonts like Cormorant Garamond are not available in most email clients.</p>
                        <div class="example-label">Example</div>
                        <pre><code>&lt;h1 style="font-family:Georgia,serif;
  font-size:28px;color:#1A1A1A;
  text-align:center;margin:0 0 16px;"&gt;
  Booking Confirmed
&lt;/h1&gt;</code></pre>
                    </div>

                    <div class="html-card">
                        <div class="tag-name">&lt;a&gt;</div>
                        <p>Links work well. Always specify <code>color</code> inline — some clients override link colours. Use full absolute URLs.</p>
                        <div class="example-label">Example</div>
                        <pre><code>&lt;a href="{{site_url}}"
  style="color:#B18247;
  text-decoration:none;"&gt;
  Visit {{site_name}}
&lt;/a&gt;</code></pre>
                    </div>

                    <div class="html-card">
                        <div class="tag-name">&lt;img&gt;</div>
                        <p>Images are blocked by default in many clients until the user allows them. Always include an <code>alt</code> attribute. Use absolute URLs (not relative paths).</p>
                        <div class="example-label">Example</div>
                        <pre><code>&lt;img
  src="https://yoursite.com/images/logo.png"
  alt="Rosalyn's Hotel"
  width="200"
  style="display:block;border:0;"
&gt;</code></pre>
                    </div>

                    <div class="html-card">
                        <div class="tag-name">&lt;strong&gt; &lt;b&gt;</div>
                        <p>Bold text. Both work reliably. Prefer <code>&lt;strong&gt;</code> for semantic meaning.</p>
                        <div class="example-label">Example</div>
                        <pre><code>&lt;p&gt;Reference:
  &lt;strong&gt;{{booking_reference}}&lt;/strong&gt;
&lt;/p&gt;</code></pre>
                    </div>

                    <div class="html-card">
                        <div class="tag-name">&lt;em&gt; &lt;i&gt;</div>
                        <p>Italic text. Both work in email. Use for light emphasis.</p>
                        <div class="example-label">Example</div>
                        <pre><code>&lt;em style="color:#8A775F;"&gt;
  {{rate_plan_label}}
&lt;/em&gt;</code></pre>
                    </div>

                    <div class="html-card">
                        <div class="tag-name">&lt;br&gt;</div>
                        <p>Line break. Safe everywhere. Useful for address blocks and key-value pairs without full paragraphs.</p>
                        <div class="example-label">Example</div>
                        <pre><code>Check-in: {{check_in_date_formatted}}&lt;br&gt;
Check-out: {{check_out_date_formatted}}&lt;br&gt;
Nights: {{number_of_nights}}</code></pre>
                    </div>

                    <div class="html-card">
                        <div class="tag-name">&lt;hr&gt;</div>
                        <p>Horizontal rule. Use to separate sections. Style inline: <code>border:none; border-top:1px solid #EAE1D8;</code></p>
                        <div class="example-label">Example</div>
                        <pre><code>&lt;hr style="border:none;
  border-top:1px solid #EAE1D8;
  margin:20px 0;"&gt;</code></pre>
                    </div>

                    <div class="html-card">
                        <div class="tag-name">&lt;span&gt;</div>
                        <p>Inline wrapper for applying colour, font-size, or other styles to part of a line.</p>
                        <div class="example-label">Example</div>
                        <pre><code>Total:
&lt;span style="color:#B18247;
  font-weight:700;font-size:18px;"&gt;
  {{currency_symbol}} {{total_amount}}
&lt;/span&gt;</code></pre>
                    </div>

                    <div class="html-card">
                        <div class="tag-name">&lt;ul&gt; &lt;li&gt;</div>
                        <p>Unordered lists render in most clients. Add <code>style="padding-left:20px;"</code> to <code>&lt;ul&gt;</code> as Outlook may strip default indentation.</p>
                        <div class="example-label">Example</div>
                        <pre><code>&lt;ul style="padding-left:20px;
  margin:0 0 16px;"&gt;
  &lt;li&gt;Item 1&lt;/li&gt;
  &lt;li&gt;Item 2&lt;/li&gt;
&lt;/ul&gt;</code></pre>
                    </div>

                    <div class="html-card">
                        <div class="tag-name">&lt;div&gt;</div>
                        <p>Works as a block container but <strong>not</strong> for layout — use <code>&lt;table&gt;</code> for layout instead. Divs are fine for simple wrappers with background colour.</p>
                        <div class="example-label">Example</div>
                        <pre><code>&lt;div style="background:#F7F3EE;
  padding:16px;border-radius:8px;"&gt;
  {{payment_policy}}
&lt;/div&gt;</code></pre>
                    </div>

                </div>

                <h3>Tags &amp; techniques to avoid in email</h3>
                <table class="avoid-table">
                    <thead>
                        <tr>
                            <th>Avoid</th>
                            <th>Why</th>
                            <th>Use instead</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>CSS classes / external stylesheets</td>
                            <td>Stripped by Gmail and most webmail clients</td>
                            <td>Inline <code>style=""</code> attributes on every element</td>
                        </tr>
                        <tr>
                            <td>Flexbox / CSS Grid</td>
                            <td>Not supported in Outlook 2007–2019</td>
                            <td><code>&lt;table&gt;</code> layout</td>
                        </tr>
                        <tr>
                            <td>CSS variables (<code>var(--gold)</code>)</td>
                            <td>Not rendered in email clients</td>
                            <td>Hard-coded hex values like <code>#B18247</code></td>
                        </tr>
                        <tr>
                            <td>Web fonts (<code>@font-face</code>)</td>
                            <td>Ignored by Outlook, Gmail, and others</td>
                            <td>Web-safe stacks: <code>Georgia, serif</code> or <code>Arial, sans-serif</code></td>
                        </tr>
                        <tr>
                            <td>JavaScript</td>
                            <td>Stripped by all email clients for security</td>
                            <td>Not applicable in email — use links instead</td>
                        </tr>
                        <tr>
                            <td><code>&lt;iframe&gt;</code> / <code>&lt;video&gt;</code></td>
                            <td>Blocked in nearly every email client</td>
                            <td>Linked thumbnail image pointing to a webpage</td>
                        </tr>
                        <tr>
                            <td>Relative image paths (<code>../images/logo.png</code>)</td>
                            <td>Will not load in email — requires absolute URLs</td>
                            <td><code>https://yoursite.com/images/logo.png</code></td>
                        </tr>
                        <tr>
                            <td><code>position: absolute/fixed</code></td>
                            <td>Broken in Outlook and many mobile clients</td>
                            <td>Table cells with <code>padding</code> and <code>align</code></td>
                        </tr>
                        <tr>
                            <td><code>border-radius</code></td>
                            <td>Ignored by Outlook (but fine in Gmail/Apple Mail)</td>
                            <td>Accept it degrades gracefully in Outlook</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- ── Best practices ──────────────────────────────────────── -->
            <section id="best-practices">
                <h2>Best practices</h2>

                <h3>Structure every email the same way</h3>
                <ol class="steps">
                    <li><strong>Outer wrapper table</strong> — <code>width="100%"</code> background colour, max-width 600px centred.</li>
                    <li><strong>Header row</strong> — hotel logo or name, dark background, gold text. Keep it short.</li>
                    <li><strong>Body row</strong> — greeting paragraph, key details table, any policy text.</li>
                    <li><strong>Footer row</strong> — address, phone, email, unsubscribe link (if applicable).</li>
                </ol>

                <h3>Keep the subject line informative</h3>
                <ul class="checklist">
                    <li>Always include <code>{{booking_reference}}</code> — guests search their inbox for it.</li>
                    <li>Include <code>{{site_name}}</code> so the sender is recognisable.</li>
                    <li>Keep it under 60 characters so it doesn't get truncated on mobile.</li>
                </ul>

                <h3>Formatting money correctly</h3>
                <div class="copy-tip">
                    Always pair the currency symbol tag with the amount tag:<br>
                    <code>{{currency_symbol}} {{total_amount_formatted}}</code><br>
                    → renders as: <em>MWK 250,000</em>
                </div>

                <h3>Testing before sending live</h3>
                <ul class="checklist">
                    <li>Enable <strong>Dev Preview Mode</strong> in Booking Settings → Email Settings. This routes all emails to the admin address instead of real guests.</li>
                    <li>Create a test booking and trigger each template — verify the tags all resolved.</li>
                    <li>Check the email renders correctly in Gmail, Outlook, and on mobile.</li>
                    <li>Disable Preview Mode before going live.</li>
                </ul>

                <h3>Editing templates safely</h3>
                <ul class="checklist">
                    <li>Never delete a template row from the database — the system recreates defaults if a template is missing, but custom edits are lost.</li>
                    <li>Use the admin editor, not a direct DB edit, so the <code>updated_at</code> timestamp is recorded.</li>
                    <li>The <code>is_active</code> toggle disables sending without deleting the template. Useful when testing a new version.</li>
                    <li>The <strong>text_body</strong> column is the plain-text fallback. Keep it updated alongside the HTML body.</li>
                </ul>

                <h3>Quick-reference: building a booking details block</h3>
                <p>This is the most common pattern in booking emails — a clean key-value summary table:</p>
                <pre><code>&lt;table width="100%" style="border-collapse:collapse;background:#F7F3EE;border-radius:8px;"&gt;
  &lt;tr&gt;
    &lt;td style="padding:8px 16px;color:#5E554D;font-size:14px;"&gt;Reference&lt;/td&gt;
    &lt;td style="padding:8px 16px;font-weight:700;color:#2A2723;font-size:14px;"&gt;
      {{booking_reference}}
    &lt;/td&gt;
  &lt;/tr&gt;
  &lt;tr&gt;
    &lt;td style="padding:8px 16px;color:#5E554D;font-size:14px;"&gt;Room&lt;/td&gt;
    &lt;td style="padding:8px 16px;font-weight:600;color:#2A2723;font-size:14px;"&gt;
      {{room_name}}
    &lt;/td&gt;
  &lt;/tr&gt;
  &lt;tr&gt;
    &lt;td style="padding:8px 16px;color:#5E554D;font-size:14px;"&gt;Check-in&lt;/td&gt;
    &lt;td style="padding:8px 16px;color:#2A2723;font-size:14px;"&gt;
      {{check_in_date_formatted}} from {{check_in_time}}
    &lt;/td&gt;
  &lt;/tr&gt;
  &lt;tr&gt;
    &lt;td style="padding:8px 16px;color:#5E554D;font-size:14px;"&gt;Check-out&lt;/td&gt;
    &lt;td style="padding:8px 16px;color:#2A2723;font-size:14px;"&gt;
      {{check_out_date_formatted}} by {{check_out_time}}
    &lt;/td&gt;
  &lt;/tr&gt;
  &lt;tr style="border-top:2px solid #EAE1D8;"&gt;
    &lt;td style="padding:10px 16px;color:#5E554D;font-size:14px;font-weight:600;"&gt;Total&lt;/td&gt;
    &lt;td style="padding:10px 16px;color:#B18247;font-size:16px;font-weight:700;"&gt;
      {{currency_symbol}} {{total_amount_formatted}}
    &lt;/td&gt;
  &lt;/tr&gt;
&lt;/table&gt;</code></pre>

            </section>

        </div><!-- /deck -->
    </main>

    <footer class="guide-footer">
        <div class="deck">
            <div class="crest">— R H —</div>
            <p><?php echo htmlspecialchars($_guide_site_name, ENT_QUOTES, 'UTF-8'); ?> Management System &middot; Email Template Reference</p>
            <p><a href="99-admin-dashboard-full-guide.html">Admin Bible →</a> &nbsp;&middot;&nbsp; <a href="index.html">All Guides →</a></p>
        </div>
    </footer>

</body>

</html>
