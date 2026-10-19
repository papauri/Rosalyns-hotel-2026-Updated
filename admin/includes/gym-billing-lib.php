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

if (!function_exists('rh_gym_void_unpaid_for_member')) {
    /**
     * A gym member was CANCELLED (owner decision 2026-10-20): void the UNPAID part of every live bill
     * tied to them - the enrolment account (gym_members.gym_inquiry_id) and any desk renewal accounts
     * (the [member:NUMBER] marker in gym_inquiries.notes). Money already paid is never touched:
     * no payment row is changed except a still-pending one, which is closed (it could otherwise clear
     * later against a voided bill), and nothing is refunded - staff refund or credit-note it as normal.
     *
     *  - Nothing paid or part paid -> the account is marked cancelled (the existing void: amount_due 0, nothing
     *    billed). Paid money on it is then credit owed back, settled by refund / credit note as for any cancelled
     *    account (owner 2026-10-20; the bill is deliberately NOT shrunk to the paid amount, which would make it
     *    fall due again after a refund).
     *  - Fully paid, already cancelled, or no bill -> left alone.
     * Each touched account gets a dated note line saying what was cleared and by whom.
     * Same-transaction helper: it never begins or commits; the caller owns the transaction.
     * Suspended / expired members do NOT call this - only cancelled.
     *
     * @return array{cleared:float, accounts:int, details:array<int,array{id:int,reference:string,cleared:float,mode:string}>}
     */
    function rh_gym_void_unpaid_for_member(PDO $pdo, int $memberId, string $memberNumber, ?int $inquiryId, string $actor): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $out = ['cleared' => 0.0, 'accounts' => 0, 'details' => []];
        $params = [(int)($inquiryId ?? 0)];
        $where = 'id = ?';
        if ($memberNumber !== '') {
            $where .= ' OR notes LIKE ?';
            $params[] = '%' . rh_gym_member_marker($memberNumber) . '%';
        }
        $sel = $pdo->prepare("SELECT id FROM gym_inquiries WHERE ($where) AND status <> 'cancelled' ORDER BY id FOR UPDATE");
        $sel->execute($params);
        $ids = $sel->fetchAll(PDO::FETCH_COLUMN);
        $cancelledPayStatus = function_exists('rh_payment_status_supported') ? rh_payment_status_supported($pdo, 'cancelled') : true;

        foreach ($ids as $rawId) {
            $id = (int)$rawId;
            $st = $pdo->prepare("SELECT id, reference_number, status, total_amount, vat_amount, total_with_vat, notes FROM gym_inquiries WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                continue;
            }
            $gross = rh_account_gross_total($row);
            $paid = rh_sum_account_paid($pdo, 'gym', $id);
            $unpaid = round($gross - $paid, 2);
            if ($unpaid <= $tol) {
                continue; // fully paid (or nothing billed): nothing unpaid to clear
            }
            // Pending (uncleared) payments never count as paid; close them so they cannot land on a voided bill.
            if ($cancelledPayStatus) {
                $pdo->prepare("
                    UPDATE payments
                       SET payment_status = 'cancelled', status = 'failed',
                           notes = CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' ' END, '[Member cancelled before this payment cleared]'),
                           updated_at = NOW()
                     WHERE booking_type = 'gym' AND booking_id = ?
                       AND COALESCE(payment_type, '') <> 'refund'
                       AND payment_status = 'pending'
                       AND deleted_at IS NULL
                ")->execute([$id]);
            }
            $currency = function_exists('getSetting') ? (string)getSetting('currency_symbol') : '';
            $ref = (string)($row['reference_number'] ?? ('#' . $id));
            // Unpaid or part-paid: the account is cancelled (bill void, due 0). Whatever was already paid is then
            // credit owed back (net paid - 0 billed), cleared by a refund / credit note like any other cancelled
            // account. The bill is NOT shrunk to the paid amount: a later refund would otherwise make the
            // shrunken bill fall due again for a member who has left.
            $mode = 'cancelled';
            $line = 'Member ' . $memberNumber . ' cancelled by ' . $actor . ': unpaid ' . ($paid > $tol ? '' : 'bill of ') . $currency . number_format($unpaid, 2) . ' voided (account cancelled)'
                . ($paid > $tol ? '; ' . $currency . number_format($paid, 2) . ' already paid stays as credit owed back - refund it or issue a credit note.' : '.');
            $pdo->prepare("UPDATE gym_inquiries SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$id]);
            $note = '[' . date('Y-m-d H:i') . '] ' . $line;
            $pdo->prepare("UPDATE gym_inquiries SET notes = CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END, ?) WHERE id = ?")
                ->execute([$note, $id]);
            syncGymInquiryPaymentSnapshot($pdo, $id);
            $out['cleared'] = round($out['cleared'] + $unpaid, 2);
            $out['accounts']++;
            $out['details'][] = ['id' => $id, 'reference' => $ref, 'cleared' => $unpaid, 'mode' => $mode];
        }
        return $out;
    }
}
