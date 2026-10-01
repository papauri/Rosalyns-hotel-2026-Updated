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

    // A split bill is booked leg by leg (rh_record_restaurant_split_leg_payment). Never collapse
    // those rows back into one: return the first leg and leave the ledger as recorded.
    $legSel = $pdo->prepare("SELECT id FROM payments WHERE booking_type = 'restaurant' AND booking_id = ? AND payment_reference LIKE ? AND deleted_at IS NULL ORDER BY id ASC LIMIT 1");
    $legSel->execute([$orderId, $paymentReference . '~L%']);
    $firstLegId = (int)$legSel->fetchColumn();
    if ($firstLegId > 0) {
        return $firstLegId;
    }

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


/**
 * True when this order already has per-leg split payment rows (see
 * rh_record_restaurant_split_leg_payment). A split bill is booked as one ledger row per
 * leg so each leg keeps its own tender; the single-row sync above must leave them alone.
 */
function rh_restaurant_order_has_split_legs(PDO $pdo, int $orderId, string $reference): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_type = 'restaurant' AND booking_id = ? AND payment_reference LIKE ? AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL");
    $st->execute([$orderId, 'POS-' . $reference . '~L%']);
    return (int)$st->fetchColumn() > 0;
}

/**
 * Book ONE split-bill leg into the payments ledger with that leg's own tender.
 *
 * $vatParts is for the LEG's gross share (net / vat_rate / vat / gross), extracted exactly as
 * the single-row sale does. The tip is never passed in — tips are cash movement, not revenue.
 * Idempotent on the leg reference. Returns the payments.id.
 */
function rh_record_restaurant_split_leg_payment(PDO $pdo, int $orderId, string $reference, ?string $customerName, array $vatParts, int $recordedBy, string $mappedMethod, int $legNumber, bool $isFinalLeg, ?string $businessDate = null): int
{
    if ($businessDate === null && function_exists('rh_station_union_business_window')) {
        $businessDate = rh_station_union_business_window()['business_date'] ?? null;
    }
    if ($businessDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
        $businessDate = date('Y-m-d');
    }
    $legReference = 'POS-' . $reference . '~L' . $legNumber;

    $dup = $pdo->prepare("SELECT id FROM payments WHERE payment_reference = ? LIMIT 1");
    $dup->execute([$legReference]);
    $existingId = (int)$dup->fetchColumn();
    if ($existingId > 0) {
        return $existingId;
    }

    $notes = trim('Restaurant order ' . $reference . ' - split leg ' . $legNumber . ($customerName !== null && $customerName !== '' ? ' - ' . $customerName : ''));
    $receiptNumber = finance_next_receipt_number($pdo, $businessDate);
    $pdo->prepare("INSERT INTO payments (
            payment_reference, booking_type, booking_id, booking_reference,
            payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, receipt_number, invoice_generated,
            status, notes, recorded_by
        ) VALUES (?, 'restaurant', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, 0, 'completed', ?, ?)")
        ->execute([
            $legReference,
            $orderId,
            $reference,
            $businessDate,
            (float)($vatParts['net'] ?? 0),
            (float)($vatParts['vat_rate'] ?? 0),
            (float)($vatParts['vat'] ?? 0),
            (float)($vatParts['gross'] ?? 0),
            $mappedMethod,
            $isFinalLeg ? 'full_payment' : 'partial_payment',
            $receiptNumber,
            $notes,
            $recordedBy,
        ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Reverse a restaurant order's sale with contra rows — the ONE method used by POS refund,
 * API void and the stock-orders void. The original payment rows are never mutated.
 *
 * For every original (non-refund) payment row it computes what is still refundable — the
 * original gross minus all refund rows already raised against it that are pending,
 * processing or completed — and writes one reversal row for that remainder in the ORIGINAL
 * row's tender (so a split bill is reversed leg by leg). Net / VAT are scaled pro rata when
 * the remainder is partial. Nothing is written for a row with nothing left.
 *
 * The caller MUST hold the stock_orders row lock (FOR UPDATE) so two refunds cannot race.
 *
 * @param string $refPrefix  reference prefix for the reversal rows, e.g. 'REF-' or 'VOID-'
 * @param string $refundReasonEnum  value for payments.refund_reason (an ENUM, not free text)
 * @param array|null $fallback  ['net','vat_rate','vat','gross','method'] used only when the
 *        order has no original payment row at all (legacy data); a full reversal row is then
 *        written with no original_payment_id.
 * @return array{rows:int, reversed_gross:float, nothing_to_refund:bool}
 */
function rh_reverse_restaurant_payments(PDO $pdo, int $orderId, string $reference, string $refPrefix, string $refundReasonEnum, string $notes, int $userId, ?array $fallback = null, ?string $businessDate = null): array
{
    if ($businessDate === null && function_exists('rh_station_union_business_window')) {
        $businessDate = rh_station_union_business_window()['business_date'] ?? null;
    }
    if ($businessDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
        $businessDate = date('Y-m-d');
    }
    $tolerance = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;

    $origSel = $pdo->prepare("SELECT id, payment_reference, payment_amount, vat_rate, vat_amount, total_amount, payment_method
                                FROM payments
                               WHERE booking_type = 'restaurant' AND booking_id = ?
                                 AND COALESCE(payment_type, '') != 'refund'
                                 AND COALESCE(payment_status, 'completed') = 'completed'
                                 AND deleted_at IS NULL
                            ORDER BY id ASC FOR UPDATE");
    $origSel->execute([$orderId]);
    $originals = $origSel->fetchAll(PDO::FETCH_ASSOC);

    $refSum = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN COALESCE(refund_amount, 0) > 0 THEN refund_amount ELSE total_amount END), 0)
                               FROM payments
                              WHERE booking_type = 'restaurant' AND original_payment_id = ?
                                AND payment_type = 'refund' AND deleted_at IS NULL
                                AND COALESCE(refund_status, 'completed') IN ('pending', 'processing', 'completed')");
    $refExists = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");

    $insert = $pdo->prepare("INSERT INTO payments (
            payment_reference, booking_type, booking_id, booking_reference,
            payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status,
            original_payment_id, refund_reason, refund_status, refund_amount,
            notes, recorded_by, created_at
        ) VALUES (?, 'restaurant', ?, ?, ?, ?, ?, ?, ?, ?, 'refund', 'completed', 'completed', ?, ?, 'completed', ?, ?, ?, NOW())");

    $nextRef = static function (string $base) use ($refExists): string {
        $ref = $base;
        $n = 1;
        while (true) {
            $refExists->execute([$ref]);
            if ((int)$refExists->fetchColumn() === 0) {
                return $ref;
            }
            $n++;
            $ref = $base . '-' . $n;
        }
    };

    if (!$originals) {
        if ($fallback === null || (float)($fallback['gross'] ?? 0) <= 0) {
            return ['rows' => 0, 'reversed_gross' => 0.0, 'nothing_to_refund' => true];
        }
        $legacyRef = $refPrefix . 'POS-' . $reference;
        $refExists->execute([$legacyRef]);
        if ((int)$refExists->fetchColumn() > 0) {
            return ['rows' => 0, 'reversed_gross' => 0.0, 'nothing_to_refund' => true];
        }
        $insert->execute([
            $legacyRef, $orderId, $reference, $businessDate,
            (float)$fallback['net'], (float)$fallback['vat_rate'], (float)$fallback['vat'], (float)$fallback['gross'],
            (string)($fallback['method'] ?? 'cash'), null, $refundReasonEnum, (float)$fallback['gross'], $notes, $userId,
        ]);
        return ['rows' => 1, 'reversed_gross' => (float)$fallback['gross'], 'nothing_to_refund' => false];
    }

    $rows = 0;
    $reversed = 0.0;
    foreach ($originals as $o) {
        $origGross = (float)$o['total_amount'];
        $refSum->execute([(int)$o['id']]);
        $already = (float)$refSum->fetchColumn();
        $remaining = round($origGross - $already, 2);
        if ($remaining <= $tolerance) {
            continue;
        }
        $full = ($remaining >= $origGross - $tolerance);
        $net = $full ? (float)$o['payment_amount'] : round((float)$o['payment_amount'] * ($remaining / $origGross), 2);
        $vat = $full ? (float)$o['vat_amount'] : round($remaining - $net, 2);
        $ref = $nextRef($refPrefix . (string)$o['payment_reference']);
        $insert->execute([
            $ref, $orderId, $reference, $businessDate,
            $net, (float)$o['vat_rate'], $vat, $full ? $origGross : $remaining,
            $o['payment_method'], (int)$o['id'], $refundReasonEnum, $full ? $origGross : $remaining, $notes, $userId,
        ]);
        $rows++;
        $reversed += $full ? $origGross : $remaining;
    }

    return ['rows' => $rows, 'reversed_gross' => round($reversed, 2), 'nothing_to_refund' => $rows === 0];
}

/**
 * Stamp stock_orders.paid_by (who took the money) when the column exists. Cached column check so
 * every pay path keeps working on a database where migration 020 has not run yet.
 */
function rh_stamp_order_paid_by(PDO $pdo, int $orderId, int $userId): void
{
    static $has = null;
    if ($has === null) {
        try {
            $st = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_orders' AND COLUMN_NAME = 'paid_by'");
            $has = ((int)$st->fetchColumn() > 0);
        } catch (Throwable $e) {
            $has = false;
        }
    }
    if ($has && $orderId > 0 && $userId > 0) {
        $pdo->prepare("UPDATE stock_orders SET paid_by = ? WHERE id = ?")->execute([$userId, $orderId]);
    }
}

/**
 * Record an order line's ingredients as WASTAGE (stock_wastage row, plus a 'wastage' FIFO
 * deduction when $deductNow - i.e. the stock had not been taken off the shelf yet). With
 * $deductNow false (stock already deducted at ready/serve) only the wastage log row is written.
 * Best-effort per ingredient. Returns the number of ingredient lines recorded.
 */
function rh_record_item_wastage(PDO $pdo, int $menuItemId, string $menuType, float $quantity, bool $deductNow, int $userId, string $reasonText): int
{
    if ($quantity <= 0 || $menuType === '') {
        return 0;
    }
    $rq = $pdo->prepare("SELECT sri.ingredient_id,
                                ((sri.quantity_per_portion * ?) / (GREATEST(sri.yield_percent, 0.1) / 100)) AS required_qty
                           FROM stock_recipes sr
                           INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
                          WHERE sr.menu_item_id = ? AND sr.menu_type = ? AND sri.quantity_per_portion > 0");
    $rq->execute([$quantity, $menuItemId, $menuType]);
    $reqs = $rq->fetchAll(PDO::FETCH_ASSOC);
    if (!$reqs) {
        return 0;
    }
    $costSel = $pdo->prepare("SELECT cost_per_unit FROM stock_ingredients WHERE id = ?");
    $wIns = $pdo->prepare("INSERT INTO stock_wastage (ingredient_id, batch_id, quantity, cost_per_unit, wastage_cost, reason, recorded_date, recorded_by) VALUES (?, NULL, ?, ?, ?, ?, CURDATE(), ?)");
    $recorded = 0;
    foreach ($reqs as $r) {
        $iid = (int)$r['ingredient_id'];
        $q = round((float)$r['required_qty'], 4);
        if ($iid <= 0 || $q <= 0) {
            continue;
        }
        try {
            $costSel->execute([$iid]);
            $cost = (float)($costSel->fetchColumn() ?: 0);
            $wIns->execute([$iid, $q, $cost, round($q * $cost, 4), mb_substr($reasonText, 0, 250), $userId]);
            $wastageId = (int)$pdo->lastInsertId();
            if ($deductNow && function_exists('deductStockBatchFIFO')) {
                if (function_exists('ensureStockBatchCoverageForDeduction')) {
                    ensureStockBatchCoverageForDeduction($iid, $q, $cost, $userId, 'Auto batch sync before wastage ' . $wastageId);
                }
                $adjId = (int)(deductStockBatchFIFO($iid, $q, 'wastage', $wastageId, $userId) ?? 0);
                if ($adjId > 0) {
                    $pdo->prepare('UPDATE stock_adjustments SET reason = ? WHERE id = ?')->execute([mb_substr($reasonText, 0, 255), $adjId]);
                }
            }
            $recorded++;
        } catch (Throwable $e) {
            error_log('rh_record_item_wastage: ' . $e->getMessage());
        }
    }
    return $recorded;
}

/**
 * Raise PENDING refund rows (the cashier settles them through the refund flow) for money owed
 * back on an order, allocated across the order's original payment rows NEWEST FIRST and capped at
 * what is still refundable on each (original gross less pending/processing/completed refunds).
 * Used when a paid or part-paid line is 86'd. Caller holds the stock_orders row lock.
 *
 * @return array{allocated:float, rows:int, shortfall:float}
 */
function rh_create_pending_refund_allocation(PDO $pdo, int $orderId, string $reference, float $amount, string $refundReasonEnum, string $note, int $userId, ?string $businessDate = null): array
{
    $amount = round($amount, 2);
    $tolerance = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
    if ($amount <= $tolerance) {
        return ['allocated' => 0.0, 'rows' => 0, 'shortfall' => 0.0];
    }
    if ($businessDate === null && function_exists('rh_station_union_business_window')) {
        $businessDate = rh_station_union_business_window()['business_date'] ?? null;
    }
    if ($businessDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
        $businessDate = date('Y-m-d');
    }
    $origSel = $pdo->prepare("SELECT id, payment_reference, payment_amount, vat_rate, vat_amount, total_amount, payment_method
                                FROM payments
                               WHERE booking_type = 'restaurant' AND booking_id = ?
                                 AND COALESCE(payment_type, '') != 'refund'
                                 AND COALESCE(payment_status, 'completed') = 'completed'
                                 AND deleted_at IS NULL
                            ORDER BY id DESC FOR UPDATE");
    $origSel->execute([$orderId]);
    $refSum = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN COALESCE(refund_amount, 0) > 0 THEN refund_amount ELSE total_amount END), 0)
                               FROM payments
                              WHERE booking_type = 'restaurant' AND original_payment_id = ?
                                AND payment_type = 'refund' AND deleted_at IS NULL
                                AND COALESCE(refund_status, 'completed') IN ('pending', 'processing', 'completed')");
    $refExists = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");
    $insert = $pdo->prepare("INSERT INTO payments (
            payment_reference, booking_type, booking_id, booking_reference,
            payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status,
            original_payment_id, refund_reason, refund_status, refund_amount, refund_notes, notes,
            recorded_by, created_at
        ) VALUES (?, 'restaurant', ?, ?, ?, ?, ?, ?, ?, ?, 'refund', 'pending', ?, ?, 'pending', ?, ?, ?, ?, NOW())");

    $left = $amount;
    $rows = 0;
    foreach ($origSel->fetchAll(PDO::FETCH_ASSOC) as $o) {
        if ($left <= $tolerance) {
            break;
        }
        $origGross = (float)$o['total_amount'];
        $refSum->execute([(int)$o['id']]);
        $remaining = round($origGross - (float)$refSum->fetchColumn(), 2);
        if ($remaining <= $tolerance) {
            continue;
        }
        $take = round(min($remaining, $left), 2);
        $ratio = $origGross > 0 ? ($take / $origGross) : 1.0;
        $net = round((float)$o['payment_amount'] * $ratio, 2);
        $vat = round($take - $net, 2);
        $base = 'REF86-' . (string)$o['payment_reference'];
        $ref = $base;
        $n = 1;
        while (true) {
            $refExists->execute([$ref]);
            if ((int)$refExists->fetchColumn() === 0) {
                break;
            }
            $n++;
            $ref = $base . '-' . $n;
        }
        $insert->execute([
            $ref, $orderId, $reference, $businessDate,
            $net, (float)$o['vat_rate'], $vat, $take,
            $o['payment_method'], (int)$o['id'], $refundReasonEnum, $take, mb_substr($note, 0, 500), mb_substr($note, 0, 500),
            $userId,
        ]);
        $rows++;
        $left = round($left - $take, 2);
    }
    return ['allocated' => round($amount - max(0.0, $left), 2), 'rows' => $rows, 'shortfall' => max(0.0, round($left, 2))];
}

/**
 * Deal with a cancelled / voided POS order's stock - the ONE implementation behind the API void,
 * API cancel and the stock-orders cancel / void. Same prep-stage rule as an 86:
 *   - line still pending      -> its deduction (if any) is RESTORED, matched on the original
 *                                deduction (restoreStockForMenuItem pairs restores to deductions)
 *   - preparing/ready/served  -> the food exists / was consumed: recorded as WASTAGE, never restored
 *                                (stock already deducted -> log row only; not yet deducted -> the
 *                                wastage deduction is taken)
 * Lines an 86 already handled (kds_status='void') are skipped. Room-service orders are left to the
 * folio-void path, which owns their stock. Throws when a restore fails so the caller's
 * transaction rolls back with a clear error instead of reporting "Stock restored".
 *
 * @return int number of order lines processed
 */
function rh_restore_pos_order_stock(PDO $pdo, int $orderId, ?int $doneBy, string $reason): int
{
    if ($orderId <= 0) {
        return 0;
    }
    $typeSel = $pdo->prepare("SELECT order_type FROM stock_orders WHERE id = ?");
    $typeSel->execute([$orderId]);
    if ((string)$typeSel->fetchColumn() === 'room_service') {
        return 0;
    }
    $sel = $pdo->prepare("SELECT soi.id, soi.menu_item_id, soi.menu_type, soi.quantity, soi.kds_status, soi.stock_deducted, soi.item_name,
                                 EXISTS (SELECT 1 FROM stock_adjustments sa
                                          WHERE sa.source_type = 'pos_order' AND sa.source_id = soi.id AND sa.quantity_change < 0) AS has_trail
                            FROM stock_order_items soi
                           WHERE soi.order_id = ? AND soi.kds_status <> 'void'
                        ORDER BY soi.id ASC");
    $sel->execute([$orderId]);
    $done = 0;
    $flag = $pdo->prepare("UPDATE stock_order_items SET stock_deducted = 0 WHERE id = ?");
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $lineId = (int)$line['id'];
        if ((string)$line['kds_status'] === 'pending') {
            if ((int)$line['has_trail'] === 1) {
                if (!restoreStockForMenuItem((int)$line['menu_item_id'], (string)$line['menu_type'], (float)$line['quantity'], $reason, $doneBy, $lineId, 'pos_order')) {
                    throw new RuntimeException('Stock restore failed for ' . $line['item_name'] . ' - nothing was changed. Please retry or contact a manager.');
                }
                $flag->execute([$lineId]);
                $done++;
            }
            continue;
        }
        $alreadyOff = ((int)$line['stock_deducted'] === 1);
        rh_record_item_wastage($pdo, (int)$line['menu_item_id'], (string)$line['menu_type'], (float)$line['quantity'], !$alreadyOff, (int)$doneBy, $reason . ' - wastage (' . $line['item_name'] . ', ' . $line['kds_status'] . ')');
        $flag->execute([$lineId]);
        $done++;
    }
    return $done;
}
