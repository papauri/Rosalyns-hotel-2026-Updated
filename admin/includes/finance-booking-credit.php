<?php
/**
 * Guest credit at New Booking (admin/create-booking.php): which of the selected credit notes may be applied.
 *
 * ONE eligibility rule for the save (it applies the notes after the commit) and the dry run (the "price check" quote,
 * plan .claude/CREATE_BOOKING_WIZARD_PLAN.md Phase B): status active / partially applied, not expired, a balance above
 * $minBalance, and the booking guest's own email, or another account's note only under an override WITH a reason.
 * The save and the dry run both pass BALANCE_TOLERANCE (0.01), the point at or below which applyCreditNote()
 * (config/credit-notes.php) refuses (the save used 0.005 before 2026-11-28: a 0.01 note was then refused there, so
 * the stored result is the same), so the preview shows only what will really be applied. Read-only: no writes here.
 */

if (!function_exists('rh_cb_credit_pick')) {
    /**
     * Pure filter over credit_notes rows (id, credit_note_number, balance, guest_email, status, expires_at), kept in
     * the order given. Returns ['notes' => [[id, number, balance, override, cn_email], ...], 'override_active' => bool].
     */
    function rh_cb_credit_pick(array $rows, string $guestEmail, bool $override, string $reason, string $today, float $minBalance): array
    {
        $overrideActive = $override && trim($reason) !== '';
        $notes = [];
        foreach ($rows as $cn) {
            if (!in_array((string)$cn['status'], ['active', 'partially_applied'], true)) {
                continue;
            }
            if ($cn['expires_at'] !== null && (string)$cn['expires_at'] < $today) {
                continue;
            }
            if ((float)$cn['balance'] <= $minBalance) {
                continue;
            }
            // Security: the guest's own credit applies freely; credit belonging to
            // a different account is only allowed under an explicit, audited override.
            $emailMatches = strcasecmp(trim((string)$cn['guest_email']), trim($guestEmail)) === 0;
            if (!$emailMatches && !$overrideActive) {
                continue;
            }
            $notes[] = [
                'id'       => (int)$cn['id'],
                'number'   => (string)$cn['credit_note_number'],
                'balance'  => (float)$cn['balance'],
                'override' => !$emailMatches,
                'cn_email' => (string)$cn['guest_email'],
            ];
        }
        return ['notes' => $notes, 'override_active' => $overrideActive];
    }
}

if (!function_exists('rh_cb_credit_queue')) {
    /** The selected note ids as posted, read one by one in that order, then filtered by rh_cb_credit_pick(). */
    function rh_cb_credit_queue(PDO $pdo, array $rawIds, string $guestEmail, bool $override, string $reason, float $minBalance): array
    {
        $rows = [];
        foreach (array_unique(array_map('intval', $rawIds)) as $cnId) {
            if ($cnId <= 0) {
                continue;
            }
            $cnStmt = $pdo->prepare("SELECT id, credit_note_number, balance, guest_email, status, expires_at FROM credit_notes WHERE id = ?");
            $cnStmt->execute([$cnId]);
            $cn = $cnStmt->fetch(PDO::FETCH_ASSOC);
            if ($cn) {
                $rows[] = $cn;
            }
        }
        return rh_cb_credit_pick($rows, $guestEmail, $override, $reason, date('Y-m-d'), $minBalance);
    }
}

if (!function_exists('rh_cb_credit_simulate')) {
    /**
     * Pure: what the save does with the money of a new booking, row by row, without writing anything (the dry run's
     * figures). $rowTotals = each created row's total_with_vat in the save's order; $cash = amount collected after the
     * payment normalisation (0 = no payment); $notes = rh_cb_credit_queue()['notes'] in posted order; $tol = the point
     * at or below which applyCreditNote() refuses (BALANCE_TOLERANCE): a note with that balance or less, a row owing
     * that or less. Mirrors admin/create-booking.php: the cash split (proportional, the last row takes the rounding;
     * equal shares when the total is 0), amount_due = max(0, total - cash share) after recalculateBookingFinancials(),
     * then the credit loop (rows in order, notes in order, min(owed, balance) each, a refused apply changes nothing).
     * Returns ['rows' => [[total, cash, credit, owed, status], ...], 'will_apply' => sum credit, 'still_owed' => sum owed].
     */
    function rh_cb_credit_simulate(array $rowTotals, float $cash, float $grandTotal, array $notes, float $tol): array
    {
        $n = count($rowTotals);
        $rows = [];
        $distributed = 0.0;
        foreach (array_values($rowTotals) as $i => $twv) {
            $share = 0.0;
            if ($cash > 0) {
                $share = ($i === $n - 1)
                    ? round($cash - $distributed, 2)
                    : ($grandTotal > 0 ? round($cash * ((float)$twv / $grandTotal), 2) : round($cash / $n, 2));
                $distributed += $share;
            }
            $rows[] = ['total' => round((float)$twv, 2), 'cash' => $share, 'credit' => 0.0, 'owed' => round(max(0.0, (float)$twv - $share), 2)];
        }
        $balances = array_map(static fn($cn) => round((float)$cn['balance'], 2), array_values($notes));
        foreach ($rows as &$row) {
            foreach ($balances as &$bal) {
                if ($row['owed'] <= $tol) {
                    break;
                }
                if ($bal <= $tol) {
                    continue;
                }
                $amt = round(min($row['owed'], $bal), 2);
                if ($amt <= 0) {
                    continue;
                }
                $row['owed']   = round($row['owed'] - $amt, 2);
                $row['credit'] = round($row['credit'] + $amt, 2);
                $bal = max(0.0, round($bal - $amt, 2));
            }
            unset($bal);
        }
        unset($row);
        $willApply = 0.0;
        $stillOwed = 0.0;
        foreach ($rows as &$row) {
            $row['status'] = rh_pay_state_key($row['cash'] + $row['credit'], $row['owed'], false, $row['total'], false);
            $willApply += $row['credit'];
            $stillOwed += $row['owed'];
        }
        unset($row);
        return ['rows' => $rows, 'will_apply' => round($willApply, 2), 'still_owed' => round($stillOwed, 2)];
    }
}
