<?php

/**
 * Sync a restaurant/POS order into the central payments ledger.
 *
 * @param array<string,mixed> $vatParts Requires keys: net, vat_rate, vat, gross
 */
function rh_sync_restaurant_payment(PDO $pdo, int $orderId, string $reference, ?string $customerName, array $vatParts, int $recordedBy, string $mappedMethod): int
{
    $paymentReference = 'POS-' . $reference;
    $notes = trim('Restaurant order ' . $reference . ($customerName !== null && $customerName !== '' ? ' - ' . $customerName : ''));

    $existing = $pdo->prepare("\n        SELECT id, receipt_number FROM payments\n        WHERE booking_type = 'restaurant'\n          AND COALESCE(payment_type, '') != 'refund'\n          AND deleted_at IS NULL\n          AND (payment_reference = ? OR booking_id = ?)\n        ORDER BY CASE WHEN payment_reference = ? THEN 0 ELSE 1 END, id DESC\n        LIMIT 1\n    ");
    $existing->execute([$paymentReference, $orderId, $paymentReference]);
    $existingPayment = $existing->fetch(PDO::FETCH_ASSOC);
    $paymentId = (int)($existingPayment['id'] ?? 0);

    if ($paymentId > 0) {
        $receiptNumber = !empty($existingPayment['receipt_number'])
            ? (string)$existingPayment['receipt_number']
            : finance_next_receipt_number($pdo, date('Y-m-d'));

        $update = $pdo->prepare("\n            UPDATE payments\n            SET payment_reference = ?, booking_reference = ?, payment_date = CURDATE(),\n                payment_amount = ?, vat_rate = ?, vat_amount = ?, total_amount = ?,\n                payment_method = ?, payment_type = 'full_payment', payment_status = 'completed',\n                status = 'completed', receipt_number = COALESCE(NULLIF(receipt_number, ''), ?), notes = ?, recorded_by = ?, updated_at = NOW()\n            WHERE id = ?\n        ");
        $update->execute([
            $paymentReference,
            $reference,
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

    $receiptNumber = finance_next_receipt_number($pdo, date('Y-m-d'));
    $insert = $pdo->prepare("\n        INSERT INTO payments (\n            payment_reference, booking_type, booking_id, booking_reference,\n            payment_date, payment_amount, vat_rate, vat_amount, total_amount,\n            payment_method, payment_type, payment_status, receipt_number, invoice_generated,\n            status, notes, recorded_by\n        ) VALUES (?, 'restaurant', ?, ?, CURDATE(), ?, ?, ?, ?, ?, 'full_payment', 'completed', ?, 0, 'completed', ?, ?)\n    ");
    $insert->execute([
        $paymentReference,
        $orderId,
        $reference,
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

