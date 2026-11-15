<?php
/**
 * A room booking's payment status, from its money (step 8i, owner 2026-11-15: a booking with a balance still owed must
 * never read "Completed" / "Paid"). PURE: no database, no settings.
 *
 *   rh_pay_state_key(float $paid, float $due, bool $closedBill, float $billed, bool $refundedOut): string
 *       The one rule recalculateBookingFinancials() stores in bookings.payment_status:
 *       nothing owed = 'paid'; owed and something paid = 'partial'; owed and nothing paid = 'unpaid'.
 *       A cancelled bill with nothing billed and nothing paid = 'unpaid'; a cancelled bill whose money all went back
 *       out = 'refunded'.
 *   rh_booking_pay_state(array $b, array $opts = []): array
 *       The words for a bookings row, from the same figures the "How the total is made up" box shows (amount_paid =
 *       Paid so far, amount_due = Balance due / Still owed, credit_balance). Never from one payment record's status
 *       (a payment row reads 'completed' even when it covered only part of the bill).
 *       Returns key (paid / partial / unpaid / refunded), label ("Paid in full", "Part paid (MWK X left)", "Unpaid",
 *       "Refunded"), short (the label without the amount, for badges that print the amount on their own line),
 *       class (ok / warn / alert, the rh-pill colours), left (amount still owed), help (plain words for a tooltip).
 *
 * Money is compared with BALANCE_TOLERANCE, never raw float equality.
 */

if (!function_exists('rh_pay_state_tol')) {
    function rh_pay_state_tol(): float
    {
        return defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
    }
}

if (!function_exists('rh_pay_state_key')) {
    function rh_pay_state_key(float $paid, float $due, bool $closedBill, float $billed, bool $refundedOut): string
    {
        $tol = rh_pay_state_tol();
        $key = $due <= $tol ? 'paid' : ($paid > $tol ? 'partial' : 'unpaid');
        if ($closedBill && $billed <= $tol && $paid <= $tol && !$refundedOut) {
            $key = 'unpaid';
        }
        if ($closedBill && $paid <= $tol && $refundedOut && $due <= $tol) {
            $key = 'refunded';
        }
        return $key;
    }
}

if (!function_exists('rh_booking_pay_state')) {
    function rh_booking_pay_state(array $b, array $opts = []): array
    {
        $tol = rh_pay_state_tol();
        $cur = trim((string)($opts['currency'] ?? '')) !== '' ? trim((string)$opts['currency']) : 'MWK';
        $paid = round(max(0.0, (float)($b['amount_paid'] ?? 0)), 2);
        $due = round(max(0.0, (float)($b['amount_due'] ?? 0)), 2);
        $credit = round(max(0.0, (float)($b['credit_balance'] ?? 0)), 2);
        $status = strtolower(trim((string)($b['status'] ?? '')));
        $stored = strtolower(trim((string)($b['payment_status'] ?? '')));
        // A closed booking (cancelled / no-show / expired) with nothing paid and nothing owed is not "Paid in full":
        // it stays Unpaid, or Refunded when its money all went back (the stored status says which; the row carries no
        // refund total).
        $closed = in_array($status, ['cancelled', 'no-show', 'expired'], true);
        $billed = max(0.0, round($paid + $due - $credit, 2));
        $key = rh_pay_state_key($paid, $due, $closed, $billed, $stored === 'refunded');

        $money = $cur . ' ' . number_format($due, 2);
        switch ($key) {
            case 'paid':
                $out = ['label' => 'Paid in full', 'short' => 'Paid in full', 'class' => 'ok',
                    'help' => $credit > $tol ? 'Nothing left to pay; the guest paid more than the bill (see Credit owed to guest).' : 'Nothing left to pay: paid so far covers the whole bill.'];
                break;
            case 'partial':
                $out = ['label' => 'Part paid (' . $money . ' left)', 'short' => 'Part paid', 'class' => 'warn',
                    'help' => 'Something is paid and ' . $money . ' is still owed (the balance due).'];
                break;
            case 'refunded':
                $out = ['label' => 'Refunded', 'short' => 'Refunded', 'class' => 'alert',
                    'help' => 'The money paid on this booking has all been given back.'];
                break;
            default:
                $out = ['label' => 'Unpaid', 'short' => 'Unpaid', 'class' => 'alert',
                    'help' => $due > $tol ? 'Nothing paid yet; ' . $money . ' is owed.' : 'Nothing paid and nothing owed.'];
        }
        return ['key' => $key, 'left' => $key === 'paid' || $key === 'refunded' ? 0.0 : $due] + $out;
    }
}
