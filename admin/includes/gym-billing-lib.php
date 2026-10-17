<?php

/**
 * Gym front-desk billing: every paid enrolment and renewal gets a billable gym account (a
 * gym_inquiries row) so the fee is paid, invoiced and reported exactly like any other gym payment
 * (Record payment -> payments ledger -> finance sync -> Gym reports / End of day). Owner decision
 * Oct 2026; no new table. Complimentary members and zero fees create no charge.
 *
 * The caller owns the transaction, so the member row and its account commit (or fail) together.
 */

require_once __DIR__ . '/finance-account-sync.php';

if (!function_exists('rh_gym_member_marker')) {
    /** Marker stored in gym_inquiries.notes that ties a desk account to its member (renewals have no FK). */
    function rh_gym_member_marker(string $memberNumber): string
    {
        return '[member:' . $memberNumber . ']';
    }
}

if (!function_exists('rh_gym_bill_membership')) {
    /**
     * @param array $a name, email, phone, membership_type, start_date, fee (gross as entered, same figure the
     *                 manual Set Total Amount takes), member_number, kind ('enrolment'|'renewal'),
     *                 inquiry_id (enrolment from an existing inquiry: that inquiry becomes the account)
     * @return array{inquiry_id:int,reference:string,total:float,created:bool}|null null when nothing is billable
     */
    function rh_gym_bill_membership(PDO $pdo, array $a): ?array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $fee = round((float)($a['fee'] ?? 0), 2);
        if ($fee <= $tol) {
            return null;
        }
        $kind = ((string)($a['kind'] ?? 'enrolment')) === 'renewal' ? 'renewal' : 'enrolment';
        $number = (string)($a['member_number'] ?? '');
        $marker = rh_gym_member_marker($number);
        $vat = vat_components($fee);

        $existingId = (int)($a['inquiry_id'] ?? 0);
        if ($existingId > 0) {
            // The inquiry being converted is the member's account. Keep a total staff already set;
            // otherwise the package fee becomes the bill.
            $sel = $pdo->prepare("SELECT id, reference_number, total_amount, status FROM gym_inquiries WHERE id = ? FOR UPDATE");
            $sel->execute([$existingId]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row && (string)$row['status'] !== 'cancelled') {
                if ((float)($row['total_amount'] ?? 0) <= $tol) {
                    $pdo->prepare("UPDATE gym_inquiries SET total_amount = ?, vat_rate = ?, vat_amount = ?, total_with_vat = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$fee, $vat['rate'], $vat['vat'], $vat['total'], $existingId]);
                }
                syncGymInquiryPaymentSnapshot($pdo, $existingId);
                $tot = $pdo->prepare("SELECT total_amount FROM gym_inquiries WHERE id = ?");
                $tot->execute([$existingId]);
                return ['inquiry_id' => $existingId, 'reference' => (string)$row['reference_number'], 'total' => (float)$tot->fetchColumn(), 'created' => false];
            }
        }

        do {
            $reference = 'GYM-' . strtoupper(substr(uniqid(), -8));
            $chk = $pdo->prepare("SELECT COUNT(*) FROM gym_inquiries WHERE reference_number = ?");
            $chk->execute([$reference]);
        } while ((int)$chk->fetchColumn() > 0);

        $when = (string)($a['start_date'] ?? '');
        $ins = $pdo->prepare("
            INSERT INTO gym_inquiries (
                reference_number, name, email, phone, membership_type, preferred_date, guests, message, consent, status,
                total_amount, vat_rate, vat_amount, total_with_vat, amount_paid, amount_due, payment_status, notes
            ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, 0, 'converted', ?, ?, ?, ?, 0, ?, 'pending', ?)
        ");
        $ins->execute([
            $reference,
            mb_substr((string)($a['name'] ?? ''), 0, 255),
            mb_substr((string)($a['email'] ?? ''), 0, 255),
            mb_substr((string)($a['phone'] ?? ''), 0, 50),
            ((string)($a['membership_type'] ?? '')) !== '' ? mb_substr((string)$a['membership_type'], 0, 100) : null,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $when) ? $when : null,
            ucfirst($kind) . ' fee recorded at the front desk for member ' . $number . '.',
            $fee, $vat['rate'], $vat['vat'], $vat['total'], $vat['total'],
            $marker . ' Desk ' . $kind . ' - record the payment from Gym Inquiries (Record payment).',
        ]);
        $newId = (int)$pdo->lastInsertId();
        syncGymInquiryPaymentSnapshot($pdo, $newId);
        return ['inquiry_id' => $newId, 'reference' => $reference, 'total' => $fee, 'created' => true];
    }
}
