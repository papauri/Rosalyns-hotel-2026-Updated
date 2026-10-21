<?php

require_once __DIR__ . '/../../includes/station-hours.php';

/**
 * Receipt for a restaurant / room-service sale: when the order carries a valid customer email, queue the
 * payment receipt to go out when the request ends (after the till's own transaction has committed; a
 * rolled-back sale is skipped). Orders without an email send nothing (walk-ins); the till can still email
 * or print the itemised receipt by hand. receipt_auto_send() refuses duplicates.
 */
function rh_queue_restaurant_receipt(PDO $pdo, int $orderId, int $paymentId): void
{
    if ($orderId <= 0 || $paymentId <= 0) {
        return;
    }
    try {
        $st = $pdo->prepare('SELECT customer_email FROM stock_orders WHERE id = ?');
        $st->execute([$orderId]);
        $email = trim((string)$st->fetchColumn());
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        require_once __DIR__ . '/../../config/receipts.php';
        receipt_queue_after_request($paymentId);
    } catch (Throwable $e) {
        error_log('rh_queue_restaurant_receipt: ' . $e->getMessage());
    }
}

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

    $existing = $pdo->prepare("\n        SELECT id, receipt_number, payment_date, payment_status, recorded_by FROM payments\n        WHERE booking_type = 'restaurant'\n          AND COALESCE(payment_type, '') != 'refund'\n          AND deleted_at IS NULL\n          AND (payment_reference = ? OR booking_id = ?)\n        ORDER BY CASE WHEN payment_reference = ? THEN 0 ELSE 1 END, id DESC\n        LIMIT 1\n    ");
    $existing->execute([$paymentReference, $orderId, $paymentReference]);
    $existingPayment = $existing->fetch(PDO::FETCH_ASSOC);
    $paymentId = (int)($existingPayment['id'] ?? 0);

    if ($paymentId > 0) {
        // A row already booked as a completed sale keeps its original payment_date and
        // recorded_by: re-syncing (e.g. Reconcile on an old paid order) corrects amounts/tender
        // only, it never re-dates the sale into today's EOD / revenue report / VAT quarter.
        $alreadyBooked = in_array((string)($existingPayment['payment_status'] ?? ''), ['completed', 'paid', 'refunded', 'partially_refunded'], true)
            && !empty($existingPayment['payment_date']);
        $keepDate = $alreadyBooked ? (string)$existingPayment['payment_date'] : $businessDate;
        $keepActor = ($alreadyBooked && (int)($existingPayment['recorded_by'] ?? 0) > 0) ? (int)$existingPayment['recorded_by'] : $recordedBy;

        $receiptNumber = !empty($existingPayment['receipt_number'])
            ? (string)$existingPayment['receipt_number']
            : finance_next_receipt_number($pdo, $keepDate);

        $update = $pdo->prepare("\n            UPDATE payments\n            SET payment_reference = ?, booking_reference = ?, payment_date = ?,\n                payment_amount = ?, vat_rate = ?, vat_amount = ?, total_amount = ?,\n                payment_method = ?, payment_type = 'full_payment', payment_status = 'completed',\n                status = 'completed', receipt_number = COALESCE(NULLIF(receipt_number, ''), ?), notes = ?, recorded_by = ?, updated_at = NOW()\n            WHERE id = ?\n        ");
        $update->execute([
            $paymentReference,
            $reference,
            $keepDate,
            (float)($vatParts['net'] ?? 0),
            (float)($vatParts['vat_rate'] ?? 0),
            (float)($vatParts['vat'] ?? 0),
            (float)($vatParts['gross'] ?? 0),
            $mappedMethod,
            $receiptNumber,
            $notes,
            $keepActor,
            $paymentId,
        ]);
        if (!$alreadyBooked) {
            rh_queue_restaurant_receipt($pdo, $orderId, $paymentId);
        }
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
    $newPaymentId = (int)$pdo->lastInsertId();
    rh_queue_restaurant_receipt($pdo, $orderId, $newPaymentId);
    return $newPaymentId;
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
    $legPaymentId = (int)$pdo->lastInsertId();
    // One receipt per leg (each leg is its own payment with its own receipt number), sent after the till's
    // transaction commits and only when the order carries a customer email.
    rh_queue_restaurant_receipt($pdo, $orderId, $legPaymentId);
    return $legPaymentId;
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
 * Record an order line's ingredients as WASTAGE.
 *  - $deductNow true (stock not yet taken off the shelf): the 'wastage' FIFO deduction is taken
 *    FIRST; the stock_wastage row is written only if it succeeded, costed from the actual FIFO
 *    adjustment (cost_at_time), and the adjustment's source_id is then pointed at the wastage id.
 *    A failed deduction records nothing and raises a system-log warning.
 *  - $deductNow false (stock already deducted as usage): the usage is RECLASSIFIED rather than
 *    duplicated - the wastage row is written at the usage's own cost and that line's usage
 *    adjustments are annotated 'Voided to wastage ...' (reports exclude those from Usage and count
 *    them as wasted), so the cost is counted once. Pass $usageSourceType/$usageSourceId (the line the
 *    usage was booked against) for this; without an id only the log row is written.
 * Runs inside the caller's transaction when there is one, else its own. Best-effort per ingredient.
 * Returns the number of ingredient lines recorded.
 */
function rh_record_item_wastage(PDO $pdo, int $menuItemId, string $menuType, float $quantity, bool $deductNow, int $userId, string $reasonText, string $usageSourceType = 'pos_order', ?int $usageSourceId = null): int
{
    if ($quantity <= 0 || $menuType === '') {
        return 0;
    }
    if (!in_array($usageSourceType, ['pos_order', 'room_service'], true)) {
        $usageSourceType = 'pos_order';
    }
    $rq = $pdo->prepare("SELECT sri.ingredient_id,
                                ((sri.quantity_per_portion * ?) / " . rh_stock_yield_sql('sri.yield_percent') . ") AS required_qty
                           FROM stock_recipes sr
                           INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
                          WHERE sr.menu_item_id = ? AND sr.menu_type = ? AND sri.quantity_per_portion > 0");
    $rq->execute([$quantity, $menuItemId, $menuType]);
    $reqs = [];
    foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $reqRow) { // one entry per ingredient even if the recipe repeats it
        $reqKey = (int)$reqRow['ingredient_id'];
        if (isset($reqs[$reqKey])) {
            $reqs[$reqKey]['required_qty'] = (float)$reqs[$reqKey]['required_qty'] + (float)$reqRow['required_qty'];
        } else {
            $reqs[$reqKey] = $reqRow;
        }
    }
    if (!$reqs) {
        return 0;
    }
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    $warn = static function (int $iid, string $why) use ($menuItemId, $menuType): void {
        error_log('rh_record_item_wastage: ingredient ' . $iid . ' - ' . $why);
        if (function_exists('rh_log_event')) {
            rh_log_event('stock', 'warning', 'Wastage not recorded for ingredient #' . $iid . ' (' . $why . ') - stock needs reconciliation.', ['ingredient_id' => $iid, 'menu_item_id' => $menuItemId, 'menu_type' => $menuType]);
        }
    };
    $today = date('Y-m-d'); // hotel date (PHP default tz = RH_TIMEZONE)
    $costSel = $pdo->prepare("SELECT cost_per_unit FROM stock_ingredients WHERE id = ?");
    $adjCostSel = $pdo->prepare("SELECT cost_at_time FROM stock_adjustments WHERE id = ?");
    $wIns = $pdo->prepare("INSERT INTO stock_wastage (ingredient_id, batch_id, quantity, cost_per_unit, wastage_cost, reason, recorded_date, recorded_by) VALUES (?, NULL, ?, ?, ?, ?, ?, ?)");
    /* Usage rows to reclassify = the line's deductions that are still standing. Restores (KDS recall,
     * pre-prep void) are paired against deductions oldest-first, so the first N deduction rows are
     * already reversed (N = restore rows for this source/ingredient) and must not be tagged or
     * costed again; of the rest, rows already tagged 'Voided to wastage' are skipped. */
    $hasRestoreCol = function_exists('rh_stock_adjustments_has_restore_col') && rh_stock_adjustments_has_restore_col();
    $dedSel = $pdo->prepare("SELECT id, ABS(quantity_change) AS q, cost_at_time, COALESCE(reason, '') AS reason
                               FROM stock_adjustments
                              WHERE source_type = ? AND source_id = ? AND ingredient_id = ? AND quantity_change < 0
                           ORDER BY id ASC");
    $restSel = $pdo->prepare($hasRestoreCol
        ? "SELECT COUNT(*) FROM stock_adjustments WHERE source_type = 'void_restore' AND restore_of_type = ? AND source_id = ? AND ingredient_id = ?"
        : "SELECT COUNT(*) FROM stock_adjustments WHERE source_type = 'void_restore' AND source_id = ? AND ingredient_id = ?");
    $usageMark = $pdo->prepare("UPDATE stock_adjustments SET reason = ? WHERE id = ?");
    $recorded = 0;
    foreach ($reqs as $r) {
        $iid = (int)$r['ingredient_id'];
        $q = round((float)$r['required_qty'], 4);
        if ($iid <= 0 || $q <= 0) {
            continue;
        }
        // Each ingredient is atomic: SAVEPOINT inside the (caller's or own) transaction.
        $sp = 'rh_wst_' . $iid;
        $pdo->exec('SAVEPOINT ' . $sp);
        try {
            $costSel->execute([$iid]);
            $cost = (float)($costSel->fetchColumn() ?: 0);
            $reasonShort = mb_substr($reasonText, 0, 250);
            if ($deductNow) {
                if (!function_exists('deductStockBatchFIFO')) {
                    throw new RuntimeException('stock engine unavailable');
                }
                if (function_exists('ensureStockBatchCoverageForDeduction')) {
                    ensureStockBatchCoverageForDeduction($iid, $q, $cost, $userId, 'Auto batch sync before item wastage');
                }
                // Deduct FIRST; only a successful deduction produces a wastage row.
                $adjId = (int)(deductStockBatchFIFO($iid, $q, 'wastage', null, $userId) ?? 0);
                if ($adjId <= 0) {
                    throw new RuntimeException('FIFO deduction failed');
                }
                $adjCostSel->execute([$adjId]);
                $actual = $adjCostSel->fetchColumn();
                $cost = ($actual !== false && $actual !== null) ? (float)$actual : $cost;
                $wIns->execute([$iid, $q, $cost, round($q * $cost, 4), $reasonShort, $today, $userId]);
                $wastageId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE stock_adjustments SET source_id = ?, reason = ? WHERE id = ?')
                    ->execute([$wastageId, mb_substr($reasonText, 0, 255), $adjId]);
            } else {
                // Stock already left the shelf as usage: reclassify it as wastage at its own cost.
                $hasUsage = false;
                $usageIds = [];
                if ($usageSourceId !== null && $usageSourceId > 0) {
                    $dedSel->execute([$usageSourceType, $usageSourceId, $iid]);
                    $restSel->execute($hasRestoreCol ? [$usageSourceType, $usageSourceId, $iid] : [$usageSourceId, $iid]);
                    $skipRestored = (int)$restSel->fetchColumn();
                    $uq = 0.0;
                    $uc = 0.0;
                    foreach ($dedSel->fetchAll(PDO::FETCH_ASSOC) as $d) {
                        if ($skipRestored > 0) {
                            $skipRestored--;
                            continue;
                        }
                        if (strncasecmp((string)$d['reason'], 'Voided to wastage', 17) === 0) {
                            continue;
                        }
                        $uq += (float)$d['q'];
                        $uc += (float)$d['q'] * (float)$d['cost_at_time'];
                        $usageIds[] = (int)$d['id'];
                    }
                    if ($uq > 0.0001) {
                        $hasUsage = true;
                        $cost = round($uc / $uq, 4);
                    }
                }
                if (!$hasUsage) {
                    // Legacy line flagged stock_deducted with no usage rows to reclassify: a log-only
                    // wastage row would double count, so record an event for reconciliation instead.
                    $pdo->exec('RELEASE SAVEPOINT ' . $sp);
                    if (function_exists('rh_log_event')) {
                        rh_log_event('stock', 'warning', 'Voided started line has no usage adjustment to reclassify for ingredient #' . $iid . ' - wastage not recorded.', ['ingredient_id' => $iid, 'menu_item_id' => $menuItemId, 'menu_type' => $menuType, 'usage_source' => $usageSourceType . ':' . (int)$usageSourceId]);
                    }
                    continue;
                }
                $wIns->execute([$iid, $q, $cost, round($q * $cost, 4), $reasonShort, $today, $userId]);
                $wastageId = (int)$pdo->lastInsertId();
                $markReason = mb_substr('Voided to wastage #' . $wastageId . ' - ' . $reasonText, 0, 255);
                foreach ($usageIds as $uid) {
                    $usageMark->execute([$markReason, $uid]);
                }
            }
            $pdo->exec('RELEASE SAVEPOINT ' . $sp);
            $recorded++;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $sp);
            } catch (Throwable $e2) {
                error_log('rh_record_item_wastage rollback: ' . $e2->getMessage());
            }
            $warn($iid, $e->getMessage());
        }
    }
    if ($ownTx && $pdo->inTransaction()) {
        $pdo->commit();
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
 * Lines an 86 already handled (kds_status='void') are skipped. Room-service orders use the same
 * per-line rule for stock taken at the KDS (source 'pos_order'); a line whose stock was booked
 * against a stock-tracked folio charge (source 'room_service') is left to
 * rh_restore_room_service_charge_stock(), which owns it. Throws when a restore fails so the
 * caller's transaction rolls back with a clear error instead of reporting "Stock restored".
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
    $isRoomService = ((string)$typeSel->fetchColumn() === 'room_service');
    $trackedChargeSel = null;
    if ($isRoomService) {
        try {
            $trackedChargeSel = $pdo->prepare("SELECT COUNT(*) FROM booking_charges
                                                WHERE stock_order_id = ? AND source_item_id = ? AND ABS(quantity - ?) < 0.005
                                                  AND stock_tracked = 1 AND voided = 0");
        } catch (Throwable $e) {
            $trackedChargeSel = null;
        }
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
        if ($trackedChargeSel && (int)$line['has_trail'] === 0) {
            // Stock-tracked room-service charge owns this line's stock (rh_restore_room_service_charge_stock).
            try {
                $trackedChargeSel->execute([$orderId, (int)$line['menu_item_id'], (float)$line['quantity']]);
                if ((int)$trackedChargeSel->fetchColumn() > 0) {
                    continue;
                }
            } catch (Throwable $e) {
                // booking_charges.stock_order_id missing on a legacy schema: nothing tracked.
            }
        }
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
        rh_record_item_wastage($pdo, (int)$line['menu_item_id'], (string)$line['menu_type'], (float)$line['quantity'], !$alreadyOff, (int)$doneBy, $reason . ' - wastage (' . $line['item_name'] . ', ' . $line['kds_status'] . ')', 'pos_order', $lineId);
        $flag->execute([$lineId]);
        $done++;
    }
    return $done;
}

/**
 * A refunded order must not keep unserved lines alive on the KDS/BDS/CDS (linked-data rule).
 * Runs inside the caller's transaction (the refund's). Lines already served or void are left
 * alone (the food went out; its stock stays consumed). For every other line:
 *   - pending (never started)               -> stock restored when it had been deducted, line void
 *   - preparing / ready / collection        -> WASTAGE (the food exists), line void, station told to stop
 * Then the order's kitchen_status is closed, ready pings are removed and station notes acknowledged.
 * Room-service orders are skipped (their stock rides on the folio charge - void that instead).
 * Throws RuntimeException when a stock restore fails so the caller's transaction rolls back.
 *
 * @return array{closed:int,restored:int,wasted:int}
 */
function rh_close_refunded_order_lines(PDO $pdo, int $orderId, int $userId, string $reason): array
{
    $out = ['closed' => 0, 'restored' => 0, 'wasted' => 0];
    if ($orderId <= 0) {
        return $out;
    }
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('rh_close_refunded_order_lines must run inside the caller\'s transaction.');
    }
    $oh = $pdo->prepare("SELECT reference, order_type, kitchen_status FROM stock_orders WHERE id = ? FOR UPDATE");
    $oh->execute([$orderId]);
    $order = $oh->fetch(PDO::FETCH_ASSOC);
    if (!$order || (string)$order['order_type'] === 'room_service') {
        return $out;
    }
    $ref = (string)$order['reference'];
    $sel = $pdo->prepare("SELECT id, item_name, menu_item_id, menu_type, quantity, station, kds_status, stock_deducted,
                                 EXISTS (SELECT 1 FROM stock_adjustments sa
                                          WHERE sa.source_type = 'pos_order' AND sa.source_id = stock_order_items.id AND sa.quantity_change < 0) AS has_trail
                            FROM stock_order_items
                           WHERE order_id = ? AND kds_status NOT IN ('served', 'void')
                        ORDER BY id ASC FOR UPDATE");
    $sel->execute([$orderId]);
    $lines = $sel->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) {
        return $out;
    }
    $actorName = '';
    try {
        $as = $pdo->prepare("SELECT COALESCE(NULLIF(full_name, ''), username) FROM admin_users WHERE id = ?");
        $as->execute([$userId]);
        $actorName = (string)$as->fetchColumn();
    } catch (Throwable $e) {
        // name is cosmetic
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $void = $pdo->prepare("UPDATE stock_order_items SET kds_status = 'void', served_at = COALESCE(served_at, NOW()), bumped_by = ?, stock_deducted = 0 WHERE id = ?");
    $stopStations = [];
    foreach ($lines as $ln) {
        $lineId = (int)$ln['id'];
        $k = (string)$ln['kds_status'];
        if ($k === 'pending') {
            if ((int)$ln['has_trail'] === 1 && (int)$ln['stock_deducted'] === 1) {
                if (!restoreStockForMenuItem((int)$ln['menu_item_id'], (string)$ln['menu_type'], (float)$ln['quantity'], 'Order refunded: line not started', $userId, $lineId, 'pos_order')) {
                    throw new RuntimeException('Stock restore failed for ' . $ln['item_name'] . ' - nothing was changed. Please retry or contact a manager.');
                }
                $out['restored']++;
            }
        } else {
            $alreadyOff = ((int)$ln['stock_deducted'] === 1);
            $n = rh_record_item_wastage($pdo, (int)$ln['menu_item_id'], (string)$ln['menu_type'], (float)$ln['quantity'], !$alreadyOff, $userId, 'Order refunded - wastage (' . $ln['item_name'] . ', ' . $ref . ', ' . $k . ')', 'pos_order', $lineId);
            if ($n > 0) {
                $out['wasted']++;
            }
            if (in_array((string)$ln['station'], ['kitchen', 'bar', 'coffee_bar'], true)) {
                $stopStations[(string)$ln['station']] = true;
            }
        }
        $void->execute([$userId > 0 ? $userId : null, $lineId]);
        $out['closed']++;
        try {
            $pdo->prepare("INSERT INTO stock_kds_events (order_id, order_item_id, event, from_status, to_status, user_id, user_name, ip_address) VALUES (?, ?, 'voided', ?, 'void', ?, ?, ?)")
                ->execute([$orderId, $lineId, $k, $userId, $actorName, $ip]);
        } catch (Throwable $e) {
            // legacy event schema
        }
    }
    // Only served lines (if any) remain, so the ticket is finished. The order's served_at is left alone:
    // a refunded ticket that was never served must not count in kitchen-time averages.
    $pdo->prepare("UPDATE stock_orders SET kitchen_status = 'served' WHERE id = ?")->execute([$orderId]);
    try {
        $pdo->prepare("DELETE FROM pos_ready_notifications WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE station_messages SET pos_acknowledged = 1, pos_acknowledged_at = NOW(), pos_acknowledged_by = ? WHERE order_id = ? AND source = 'station' AND COALESCE(pos_acknowledged, 0) = 0")
            ->execute([$userId, $orderId]);
        foreach (array_keys($stopStations) as $stn) {
            $pdo->prepare("INSERT INTO station_messages (station, message, sent_by, sent_by_name, source, priority, order_id, order_ref, is_acknowledged, acknowledged_at) VALUES (?, ?, ?, ?, 'pos', 'urgent', ?, ?, 0, NULL)")
                ->execute([$stn, mb_substr('STOP - order ' . $ref . ' refunded. Do not serve it.', 0, 255), $userId > 0 ? $userId : null, mb_substr($actorName, 0, 120), $orderId, $ref]);
        }
    } catch (Throwable $e) {
        error_log('rh_close_refunded_order_lines notices: ' . $e->getMessage());
    }
    try {
        $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, 'refund_closed_lines', ?, ?)")
            ->execute([$orderId, $userId > 0 ? $userId : null, $actorName, mb_substr($reason . ' | ' . $out['closed'] . ' unserved line(s) closed, stock restored ' . $out['restored'] . ', wastage ' . $out['wasted'], 0, 500), $ip]);
    } catch (Throwable $e) {
        error_log('rh_close_refunded_order_lines audit: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Stock side of voiding / cancelling ONE room-service folio charge whose ingredients were booked
 * against the charge itself (booking_charges.stock_tracked = 1, source 'room_service'). Same prep
 * rule as an 86 and as rh_restore_pos_order_stock: the charge's kitchen line decides.
 *   - line pending                            -> deduction RESTORED to the shelf
 *   - preparing / ready / collection / served -> the food exists: WASTAGE (usage reclassified), never restored
 *   - line already 86'd (void)                -> skipped, the 86 handled it
 *   - line has a KDS (pos_order) deduction    -> skipped, rh_restore_pos_order_stock owns that stock
 *   - no kitchen line found (legacy charge)   -> restored, as before
 * Runs inside the caller's transaction. Never throws for a stock failure (the money void must
 * stand); failures are logged for reconciliation.
 *
 * @param array $charge booking_charges row: id, charge_type, source_item_id, quantity, stock_tracked
 * @return string 'restored' | 'wastage' | 'skipped'
 */
function rh_restore_room_service_charge_stock(PDO $pdo, int $orderId, array $charge, string $reason, ?int $doneBy): string
{
    if (empty($charge['stock_tracked'])
        || !in_array((string)($charge['charge_type'] ?? ''), ['food', 'drink'], true)
        || empty($charge['source_item_id'])
    ) {
        return 'skipped';
    }
    $chargeId = (int)$charge['id'];
    $itemId = (int)$charge['source_item_id'];
    $qty = (float)$charge['quantity'];
    $menuType = (string)$charge['charge_type'];
    $kdsStatus = null;
    try {
        $ln = $pdo->prepare("SELECT soi.id, soi.menu_type, soi.kds_status,
                                    EXISTS (SELECT 1 FROM stock_adjustments sa
                                             WHERE sa.source_type = 'pos_order' AND sa.source_id = soi.id AND sa.quantity_change < 0) AS has_trail
                               FROM stock_order_items soi
                              WHERE soi.order_id = ? AND soi.menu_item_id = ? AND ABS(soi.quantity - ?) < 0.005
                           ORDER BY (soi.kds_status = 'void') ASC, soi.id ASC");
        $ln->execute([$orderId, $itemId, $qty]);
        $line = $ln->fetch(PDO::FETCH_ASSOC);
        if ($line) {
            if ((string)$line['kds_status'] === 'void' || (int)$line['has_trail'] === 1) {
                return 'skipped';
            }
            $kdsStatus = (string)$line['kds_status'];
            if ((string)$line['menu_type'] !== '') {
                $menuType = (string)$line['menu_type'];
            }
        }
    } catch (Throwable $e) {
        error_log('rh_restore_room_service_charge_stock line lookup (charge ' . $chargeId . '): ' . $e->getMessage());
    }

    try {
        if ($kdsStatus !== null && $kdsStatus !== 'pending') {
            $n = rh_record_item_wastage($pdo, $itemId, $menuType, $qty, false, (int)$doneBy, $reason . ' - wastage (room service, ' . $kdsStatus . ')', 'room_service', $chargeId);
            return $n > 0 ? 'wastage' : 'skipped';
        }
        if (!restoreStockForMenuItem($itemId, $menuType, $qty, $reason, $doneBy, $chargeId, 'room_service')) {
            error_log('rh_restore_room_service_charge_stock: restore failed for charge ' . $chargeId);
            return 'skipped';
        }
        return 'restored';
    } catch (Throwable $e) {
        error_log('rh_restore_room_service_charge_stock charge ' . $chargeId . ': ' . $e->getMessage());
        return 'skipped';
    }
}
