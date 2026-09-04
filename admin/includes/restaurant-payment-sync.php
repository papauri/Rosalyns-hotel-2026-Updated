<?php

require_once __DIR__ . '/../../includes/station-hours.php';

/**
 * Sync a restaurant/POS order into the central payments ledger.
 *
 * @param array<string,mixed> $vatParts Requires keys: net, vat_rate, vat, gross
 * @param string|null $businessDate Y-m-d trading date this sale belongs to. Defaults to the
 *   current restaurant trading window's date (not the calendar date) so a sale made after
 *   midnight during an open trading window, its receipt number, its shift close and the
 *   accounting page all land on the same date — see .claude/POS_KDS_ACCOUNTING_PLAN.md D4.
 */
function rh_sync_restaurant_payment(PDO $pdo, int $orderId, string $reference, ?string $customerName, array $vatParts, int $recordedBy, string $mappedMethod, ?string $businessDate = null): int
{
    if ($businessDate === null && function_exists('rh_station_union_business_window')) {
        $businessDate = rh_station_union_business_window()['business_date'] ?? null;
    }
    if ($businessDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
        $businessDate = date('Y-m-d');
    }

    $paymentReference = 'POS-' . $reference;
    $notes = trim('Restaurant order ' . $reference . ($customerName !== null && $customerName !== '' ? ' - ' . $customerName : ''));

    $existing = $pdo->prepare("\n        SELECT id, receipt_number FROM payments\n        WHERE booking_type = 'restaurant'\n          AND COALESCE(payment_type, '') != 'refund'\n          AND deleted_at IS NULL\n          AND (payment_reference = ? OR booking_id = ?)\n        ORDER BY CASE WHEN payment_reference = ? THEN 0 ELSE 1 END, id DESC\n        LIMIT 1\n    ");
    $existing->execute([$paymentReference, $orderId, $paymentReference]);
    $existingPayment = $existing->fetch(PDO::FETCH_ASSOC);
    $paymentId = (int)($existingPayment['id'] ?? 0);

    if ($paymentId > 0) {
        $receiptNumber = !empty($existingPayment['receipt_number'])
            ? (string)$existingPayment['receipt_number']
            : finance_next_receipt_number($pdo, $businessDate);

        $update = $pdo->prepare("\n            UPDATE payments\n            SET payment_reference = ?, booking_reference = ?, payment_date = ?,\n                payment_amount = ?, vat_rate = ?, vat_amount = ?, total_amount = ?,\n                payment_method = ?, payment_type = 'full_payment', payment_status = 'completed',\n                status = 'completed', receipt_number = COALESCE(NULLIF(receipt_number, ''), ?), notes = ?, recorded_by = ?, updated_at = NOW()\n            WHERE id = ?\n        ");
        $update->execute([
            $paymentReference,
            $reference,
            $businessDate,
            (float)($vatParts['net'] ?? 0),
            (float)($vatParts['vat_rate'] ?? 0),
            (float)($vatParts['vat'] ?? 0),
            (float)($vatParts['gross'] ?? 0),
            $mappedMethod,
            $receiptNumber,
            $notes,
            $recordedBy,
            $paymentId,
        ]);
        return $paymentId;
    }

    $receiptNumber = finance_next_receipt_number($pdo, $businessDate);
    $insert = $pdo->prepare("\n        INSERT INTO payments (\n            payment_reference, booking_type, booking_id, booking_reference,\n            payment_date, payment_amount, vat_rate, vat_amount, total_amount,\n            payment_method, payment_type, payment_status, receipt_number, invoice_generated,\n            status, notes, recorded_by\n        ) VALUES (?, 'restaurant', ?, ?, ?, ?, ?, ?, ?, ?, 'full_payment', 'completed', ?, 0, 'completed', ?, ?)\n    ");
    $insert->execute([
        $paymentReference,
        $orderId,
        $reference,
        $businessDate,
        (float)($vatParts['net'] ?? 0),
        (float)($vatParts['vat_rate'] ?? 0),
        (float)($vatParts['vat'] ?? 0),
        (float)($vatParts['gross'] ?? 0),
        $mappedMethod,
        $receiptNumber,
        $notes,
        $recordedBy,
    ]);
    return (int)$pdo->lastInsertId();
}

