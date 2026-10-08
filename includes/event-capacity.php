<?php

/**
 * Event capacity + waitlist (owner decision 2026-10-02).
 *
 * events.capacity is the number of seats (0 / NULL = unlimited). RSVPs in a seat-holding
 * status count their guests against it; an RSVP that does not fit is saved as 'waitlisted'
 * (event_inquiries.status is free text, so no schema change). Staff promote waitlisted
 * RSVPs from Admin -> Event Bookings once seats free up (or after raising the capacity).
 */

if (!function_exists('rh_event_seat_statuses')) {

    /** Statuses whose guests hold seats. */
    function rh_event_seat_statuses(): array
    {
        return ['pending', 'confirmed', 'completed'];
    }

    function rh_event_seats_taken(PDO $pdo, int $eventId, int $excludeInquiryId = 0): int
    {
        $st = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(guests, 1)), 0) FROM event_inquiries
            WHERE event_id = ? AND id <> ? AND status IN ('pending', 'confirmed', 'completed')");
        $st->execute([$eventId, $excludeInquiryId]);
        return (int)$st->fetchColumn();
    }

    /** Normalise an RSVP e-mail for storage and comparison. */
    function rh_event_normalise_email(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * True when this e-mail already holds an active (non-cancelled) RSVP for the event.
     * Call inside the transaction that holds the event-row lock so two submits cannot both pass.
     */
    function rh_event_has_active_rsvp(PDO $pdo, int $eventId, string $email): bool
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM event_inquiries
            WHERE event_id = ? AND LOWER(TRIM(email)) = ? AND status <> 'cancelled'");
        $st->execute([$eventId, rh_event_normalise_email($email)]);
        return (int)$st->fetchColumn() > 0;
    }

    /**
     * @return array{capacity:int,taken:int,left:?int,waitlisted:int} left is null when unlimited
     */
    function rh_event_capacity_info(PDO $pdo, int $eventId): array
    {
        $st = $pdo->prepare('SELECT capacity FROM events WHERE id = ?');
        $st->execute([$eventId]);
        $capacity = max(0, (int)$st->fetchColumn());
        $taken = rh_event_seats_taken($pdo, $eventId);
        $w = $pdo->prepare("SELECT COUNT(*) FROM event_inquiries WHERE event_id = ? AND status = 'waitlisted'");
        $w->execute([$eventId]);
        return [
            'capacity' => $capacity,
            'taken' => $taken,
            'left' => $capacity > 0 ? max(0, $capacity - $taken) : null,
            'waitlisted' => (int)$w->fetchColumn(),
        ];
    }

    /**
     * Decide the status for a new RSVP. Call inside a transaction: the event row is locked so
     * two guests cannot both take the last seats.
     *
     * @return string|null 'pending' | 'waitlisted', or null when the event is not open for RSVPs
     */
    function rh_event_rsvp_status(PDO $pdo, int $eventId, int $guests): ?string
    {
        // Past events are closed to new RSVPs (the page greys them out, the server must agree).
        $st = $pdo->prepare('SELECT capacity, event_date, start_time FROM events WHERE id = ? AND is_active = 1 AND event_date >= ? FOR UPDATE');
        $st->execute([$eventId, date('Y-m-d')]);
        $evRow = $st->fetch(PDO::FETCH_ASSOC);
        if (!$evRow) {
            return null;
        }
        // A same-day event closes to new RSVPs once its start time has passed (server/hotel timezone).
        if ((string)$evRow['event_date'] === date('Y-m-d') && !empty($evRow['start_time'])
            && date('H:i:s') >= date('H:i:s', strtotime((string)$evRow['start_time']))) {
            return null;
        }
        $capacity = max(0, (int)$evRow['capacity']);
        if ($capacity === 0) {
            return 'pending';
        }
        return rh_event_seats_taken($pdo, $eventId) + max(1, $guests) > $capacity ? 'waitlisted' : 'pending';
    }

    /** Shared body for the two waitlist emails. */
    function rh_event_waitlist_email_html(array $inquiry, string $heading, string $lead, string $statusTitle, string $statusText, string $tone): string
    {
        global $email_from_email, $email_site_name;
        $e = static function ($v): string {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        };
        $colors = $tone === 'good' ? ['#d4edda', '#28a745', '#155724'] : ['#fff3cd', '#C8A45A', '#856404'];
        $row = static function (string $label, string $value, bool $last = false) use ($e): string {
            return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;"><tr>'
                . '<td style="padding:10px 10px 10px 0;font-weight:bold;color:#1A1A1A;width:44%;vertical-align:top;' . ($last ? '' : 'border-bottom:1px solid #e8e0d4;') . '">' . $e($label) . '</td>'
                . '<td style="padding:10px 0 10px 6px;color:#333;text-align:left;vertical-align:top;' . ($last ? '' : 'border-bottom:1px solid #e8e0d4;') . '">' . $value . '</td></tr></table>';
        };
        return '
            <h1 style="color: #8B7355; text-align: center;">' . $e($heading) . '</h1>
            <p>Dear ' . $e($inquiry['name'] ?? 'Guest') . ',</p>
            <p>' . $lead . '</p>
            <div style="background: #FAF6F0; border: 2px solid #C8A45A; padding: 20px; margin: 20px 0; border-radius: 10px;">
                <h2 style="color: #8B7355; margin-top: 0;text-align:left;">Booking Details</h2>'
            . $row('Reference:', '<strong style="color:#8B7355;font-size:18px;">' . $e($inquiry['reference_number'] ?? '') . '</strong>')
            . $row('Event:', $e($inquiry['event_title'] ?? 'N/A'))
            . (!empty($inquiry['event_date']) ? $row('Event Date:', $e(date('F j, Y', strtotime((string)$inquiry['event_date'])))) : '')
            . $row('Attendees:', (string)(int)($inquiry['guests'] ?? 1), true) . '
            </div>
            <div style="background: ' . $colors[0] . '; padding: 15px; border-left: 4px solid ' . $colors[1] . '; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: ' . $colors[2] . '; margin-top: 0;text-align:left;">' . $e($statusTitle) . '</h3>
                <p style="color: ' . $colors[2] . '; margin: 0;">' . $statusText . '</p>
            </div>
            <p>If you have any questions, please contact us at <a href="mailto:' . $e($email_from_email) . '">' . $e($email_from_email) . '</a> or call ' . $e(getSetting('phone_main')) . '.</p>
            <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">Warm regards &mdash; ' . $e($email_site_name) . '</p>';
    }

    /** "You are on the waitlist" email for an RSVP that did not fit. */
    function sendEventWaitlistedEmail(array $inquiry): array
    {
        global $email_site_name;
        try {
            $html = rh_event_waitlist_email_html(
                $inquiry,
                "You're on the Waitlist",
                'Thank you for your interest in this event at <strong>' . htmlspecialchars((string)$email_site_name) . '</strong>. It is fully booked at the moment, so we have added you to the waitlist.',
                'Booking Status: Waitlisted',
                'If a place opens up we will email you straight away to confirm your booking. You do not need to do anything for now.',
                'wait'
            );
            return sendEmail((string)$inquiry['email'], (string)$inquiry['name'],
                'Event Waitlist - ' . $email_site_name . ' [' . $inquiry['reference_number'] . ']', $html);
        } catch (Throwable $e) {
            error_log('sendEventWaitlistedEmail: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** "Booking received - awaiting confirmation" email for a pending RSVP (staff confirm later). */
    function sendEventReceivedEmail(array $inquiry): array
    {
        global $email_site_name;
        try {
            $html = rh_event_waitlist_email_html(
                $inquiry,
                'Booking Received',
                'Thank you for booking with <strong>' . htmlspecialchars((string)$email_site_name) . '</strong>. We have received your event booking request.',
                'Booking Status: Awaiting Confirmation',
                'Your place is being held. Our team will confirm your booking shortly and email you again once it is confirmed.',
                'wait'
            );
            return sendEmail((string)$inquiry['email'], (string)$inquiry['name'],
                'Event Booking Received - ' . $email_site_name . ' [' . $inquiry['reference_number'] . ']', $html);
        } catch (Throwable $e) {
            error_log('sendEventReceivedEmail: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** "A place is available" email when staff promote a waitlisted RSVP. */
    function sendEventWaitlistPromotedEmail(array $inquiry): array
    {
        global $email_site_name;
        try {
            $html = rh_event_waitlist_email_html(
                $inquiry,
                'A Place Has Opened Up',
                'Good news! A place has opened up for the event you were waitlisted for at <strong>' . htmlspecialchars((string)$email_site_name) . '</strong>.',
                'Booking Status: Off the Waitlist',
                'Your booking has moved off the waitlist. Our team will be in touch shortly to confirm the details.',
                'good'
            );
            return sendEmail((string)$inquiry['email'], (string)$inquiry['name'],
                'A Place Is Available - ' . $email_site_name . ' [' . $inquiry['reference_number'] . ']', $html);
        } catch (Throwable $e) {
            error_log('sendEventWaitlistPromotedEmail: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
