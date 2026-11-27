<?php
/**
 * Guest credit at New Booking (admin/create-booking.php): which of the selected credit notes may be applied.
 *
 * ONE eligibility rule for the save (it applies the notes after the commit) and the dry run (the "price check" quote,
 * plan .claude/CREATE_BOOKING_WIZARD_PLAN.md Phase B): status active / partially applied, not expired, a balance above
 * $minBalance, and the booking guest's own email, or another account's note only under an override WITH a reason.
 * The save passes 0.005 (its threshold before this helper, so a real save is unchanged); the dry run passes
 * BALANCE_TOLERANCE (0.01), the point at or below which applyCreditNote() (config/credit-notes.php) refuses, so the
 * preview shows only what will really be applied. Read-only: no writes here.
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
