<?php
/**
 * Booking Lifecycle Guards
 *
 * Centralised rules for what actions are permitted at each booking status.
 * Import this file (require_once) then call bookingAllowsAction() or
 * getBookingPermissions() before mutating any booking-related state.
 */

// Statuses that are fully terminal — the booking is over and locked.
const BOOKING_TERMINAL_STATUSES = ['cancelled', 'no-show', 'expired'];

// Statuses that are "closed" — checked-out guests; some read actions still allowed.
const BOOKING_CLOSED_STATUSES   = ['checked-out'];

/**
 * Returns ['allowed' => bool, 'reason' => string].
 *
 * $action values:
 *   add_charge      – add a folio charge or menu item
 *   void_charge     – void an existing folio charge
 *   generate_invoice – generate / regenerate an invoice PDF
 *   send_invoice    – email or WhatsApp an invoice to the guest
 *   record_payment  – record a new payment against this booking
 *   send_quotation  – send / resend a quotation
 *   mark_quotation  – mark quotation accepted / declined
 *   generate_credit_note – issue a credit note
 */
function bookingAllowsAction(array $booking, string $action): array
{
    $status = $booking['status'] ?? 'pending';

    $terminal = in_array($status, BOOKING_TERMINAL_STATUSES, true);
    $closed   = in_array($status, BOOKING_CLOSED_STATUSES, true);

    switch ($action) {

        case 'add_charge':
        case 'void_charge':
            if ($terminal) {
                return ['allowed' => false, 'reason' =>
                    "Folio charges cannot be modified on a {$status} booking."];
            }
            if ($closed) {
                return ['allowed' => false, 'reason' =>
                    'Folio charges cannot be modified after check-out. Raise a credit note if an adjustment is needed.'];
            }
            return ['allowed' => true, 'reason' => ''];

        case 'generate_invoice':
        case 'send_invoice':
            if ($status === 'tentative') {
                return ['allowed' => false, 'reason' =>
                    'Invoices cannot be generated or sent for tentative (unconfirmed) bookings. Convert the booking to confirmed first.'];
            }
            if ($terminal) {
                return ['allowed' => false, 'reason' =>
                    "Invoices cannot be sent for a {$status} booking."];
            }
            return ['allowed' => true, 'reason' => ''];

        case 'record_payment':
            if ($terminal) {
                return ['allowed' => false, 'reason' =>
                    "Payments cannot be recorded against a {$status} booking."];
            }
            if ($closed) {
                // Allow payment on checked-out if there is still a balance due
                $balanceDue = (float)($booking['amount_due'] ?? ($booking['total_amount'] ?? 0) - ($booking['amount_paid'] ?? 0));
                if ($balanceDue <= BALANCE_TOLERANCE) {
                    return ['allowed' => false, 'reason' =>
                        'This booking is checked-out and fully settled. No further payments can be recorded.'];
                }
            }
            return ['allowed' => true, 'reason' => ''];

        case 'send_quotation':
            if ($terminal) {
                return ['allowed' => false, 'reason' =>
                    "Quotations cannot be sent for a {$status} booking."];
            }
            if ($closed) {
                return ['allowed' => false, 'reason' =>
                    'Quotations cannot be sent after check-out.'];
            }
            return ['allowed' => true, 'reason' => ''];

        case 'mark_quotation':
            if ($terminal) {
                return ['allowed' => false, 'reason' =>
                    "Quotation status cannot be updated for a {$status} booking."];
            }
            if ($closed) {
                return ['allowed' => false, 'reason' =>
                    'Quotation status cannot be updated after check-out.'];
            }
            return ['allowed' => true, 'reason' => ''];

        case 'generate_credit_note':
            // Credit notes make sense on terminal / closed bookings (refund scenario)
            // but not on active bookings where a void is more appropriate.
            if ($status === 'tentative') {
                return ['allowed' => false, 'reason' =>
                    'Credit notes cannot be created for tentative bookings. Convert or cancel the booking first.'];
            }
            return ['allowed' => true, 'reason' => ''];
    }

    return ['allowed' => true, 'reason' => ''];
}

/**
 * Returns a permission map for a booking — convenient for templates.
 *
 * Keys: can_add_charge, can_void_charge, can_generate_invoice,
 *       can_send_invoice, can_record_payment, can_send_quotation,
 *       can_mark_quotation, can_generate_credit_note
 */
function getBookingPermissions(array $booking): array
{
    $actions = [
        'can_add_charge'          => 'add_charge',
        'can_void_charge'         => 'void_charge',
        'can_generate_invoice'    => 'generate_invoice',
        'can_send_invoice'        => 'send_invoice',
        'can_record_payment'      => 'record_payment',
        'can_send_quotation'      => 'send_quotation',
        'can_mark_quotation'      => 'mark_quotation',
        'can_generate_credit_note'=> 'generate_credit_note',
    ];

    $perms = [];
    foreach ($actions as $key => $action) {
        $result      = bookingAllowsAction($booking, $action);
        $perms[$key] = $result['allowed'];
        $perms[$key . '_reason'] = $result['reason'];
    }
    return $perms;
}

/**
 * Detects whether a new payment amount creates an overpayment on a booking.
 *
 * Returns ['overpaid' => bool, 'excess' => float, 'booking' => array|null].
 * The caller is responsible for creating the credit note (using issueCreditNote()
 * from config/credit-notes.php) so we avoid pulling that dependency here.
 */
function detectOverpayment(PDO $pdo, int $bookingId, float $newPaymentAmount): array
{
    try {
        $stmt = $pdo->prepare("SELECT total_amount, folio_charges_total, amount_paid, booking_reference, guest_name, guest_email FROM bookings WHERE id = ?");
        $stmt->execute([$bookingId]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("detectOverpayment query failed: " . $e->getMessage());
        return ['overpaid' => false, 'excess' => 0.0, 'booking' => null];
    }

    if (!$b) {
        return ['overpaid' => false, 'excess' => 0.0, 'booking' => null];
    }

    $folioTotal   = (float)($b['folio_charges_total'] ?? 0);
    $roomTotal    = (float)($b['total_amount'] ?? 0);
    $grandTotal   = $roomTotal + $folioTotal;
    $alreadyPaid  = (float)($b['amount_paid'] ?? 0);
    $afterPayment = $alreadyPaid + $newPaymentAmount;

    if ($afterPayment <= $grandTotal + BALANCE_TOLERANCE) {
        return ['overpaid' => false, 'excess' => 0.0, 'booking' => $b];
    }

    return [
        'overpaid' => true,
        'excess'   => round($afterPayment - $grandTotal, 2),
        'booking'  => $b,
    ];
}

