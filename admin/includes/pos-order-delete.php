<?php

/**
 * Permanent deletion of a POS / restaurant order.
 *
 * Owner decision (2026-10-18): a user holding the `pos_delete_order` permission may delete ANY
 * order at ANY time - open, paid, voided, cancelled, even one in a closed shift. Past Z-reports /
 * End of Day figures change as a result and the owner accepts that.
 *
 * rh_delete_pos_order() is the ONE implementation, used by api/delete-order.php (Orders & Tabs
 * and the POS "Today's orders" window). In a single transaction, with the stock_orders row locked
 * FOR UPDATE, it
 *   (a) STOCK   follows the kitchen-started rule used by void / cancel / 86:
 *                 - lines still PENDING (not started): their deduction trail is put back through
 *                   restoreStockForMenuItem (exact FIFO batches). A line that mixes un-wasted and
 *                   already-wasted deductions gets only the un-wasted part back.
 *                 - lines PREPARING / READY / COLLECTION / SERVED: the food exists / was consumed. They
 *                   stay deducted and are recorded as WASTAGE "Deleted order <REF>" (rh_record_item_wastage),
 *                   so the cost stays visible.
 *                 - stock already written off as wastage stays wasted; its adjustment rows are detached
 *                   from the deleted line (source_id NULL, reason tagged) so nothing dangles.
 *                 - legacy adjustments keyed on the ORDER id are never auto-restored: the lines are
 *                   named in the summary and left untouched.
 *   (b) MONEY   removes every payments row of the order (booking_type 'restaurant', booking_id = the
 *               order) including refund / void-reversal rows, plus their receipt_events and queued MRA
 *               submissions, so shift totals, POS accounting, End of Day and reports no longer count it.
 *               Room-service orders void their live folio charges, the booking is recalculated and any
 *               overpayment that leaves behind is converted to a credit note. Refused (nothing changed) when
 *               a live credit note is linked to the order or a payment was already fiscalised with MRA.
 *               An unsettled (pending / processing) refund is dropped with the order and recorded loudly as
 *               "UNSETTLED REFUND DROPPED". Cash already taken or handed back is not moved.
 *   (c) CHILDREN removes stock_order_items, stock_kds_events, stock_order_audit,
 *               stock_order_deliveries, stock_order_splits, station_messages and
 *               pos_ready_notifications for the order.
 *   (d) AUDIT   writes a permanent row to pos_deleted_orders (who, when, reference, type, status,
 *               total, paid, reason, client_uuid, notes, plus a JSON snapshot of the order, lines,
 *               payments, splits, audit trail and stock movements) and a line in the admin activity log.
 *               The client_uuid is kept so an offline-queued copy of the sale cannot resurrect the order.
 *
 * Schema: pos_deleted_orders is created here on first use (CREATE TABLE IF NOT EXISTS + additive
 * ALTERs) BEFORE the transaction starts - DDL inside a transaction would commit it implicitly.
 */

if (!function_exists('rh_pos_del_table_exists')) {
    function rh_pos_del_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
                $st->execute([$table]);
                $cache[$table] = ((int)$st->fetchColumn() > 0);
            } catch (Throwable $e) {
                $cache[$table] = false;
            }
        }
        return $cache[$table];
    }
}

if (!function_exists('rh_pos_del_column_exists')) {
    function rh_pos_del_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $st->execute([$table, $column]);
                $cache[$key] = ((int)$st->fetchColumn() > 0);
            } catch (Throwable $e) {
                $cache[$key] = false;
            }
        }
        return $cache[$key];
    }
}

/** Create / upgrade the permanent deletion-record table (idempotent; never call inside a transaction). */
function rh_pos_deleted_orders_ensure(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_deleted_orders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        original_order_id INT UNSIGNED NOT NULL,
        reference VARCHAR(50) NOT NULL DEFAULT '',
        client_uuid VARCHAR(64) NULL,
        order_type VARCHAR(30) NOT NULL DEFAULT '',
        order_status VARCHAR(30) NOT NULL DEFAULT '',
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        tip_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(30) NULL,
        booking_id INT UNSIGNED NULL,
        booking_reference VARCHAR(50) NULL,
        order_created_at DATETIME NULL,
        order_created_by INT UNSIGNED NULL,
        deleted_by INT UNSIGNED NULL,
        deleted_by_name VARCHAR(150) NOT NULL DEFAULT '',
        deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reason VARCHAR(500) NOT NULL DEFAULT '',
        notes VARCHAR(1500) NULL,
        ip_address VARCHAR(45) NULL,
        stock_summary VARCHAR(500) NULL,
        payments_removed INT UNSIGNED NOT NULL DEFAULT 0,
        folio_charges_voided INT UNSIGNED NOT NULL DEFAULT 0,
        snapshot_json LONGTEXT NULL,
        INDEX idx_pos_deleted_orders_ref (reference),
        INDEX idx_pos_deleted_orders_order (original_order_id),
        INDEX idx_pos_deleted_orders_uuid (client_uuid),
        INDEX idx_pos_deleted_orders_when (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Additive upgrades for a table created by an earlier version.
    if (!rh_pos_del_column_exists($pdo, 'pos_deleted_orders', 'client_uuid')) {
        $pdo->exec("ALTER TABLE pos_deleted_orders ADD COLUMN client_uuid VARCHAR(64) NULL AFTER reference, ADD INDEX idx_pos_deleted_orders_uuid (client_uuid)");
    }
    if (!rh_pos_del_column_exists($pdo, 'pos_deleted_orders', 'notes')) {
        $pdo->exec("ALTER TABLE pos_deleted_orders ADD COLUMN notes VARCHAR(1500) NULL AFTER reason");
    }
}

/**
 * True when an order with this client_uuid was deleted. Used by the till's duplicate-submission guard so an
 * offline-queued copy of a sale that has since been deleted is not replayed back into existence.
 * Read-only; safe inside a transaction; false when the table does not exist yet.
 */
function rh_pos_deleted_client_uuid(PDO $pdo, string $clientUuid): ?array
{
    if ($clientUuid === '') {
        return null;
    }
    try {
        $st = $pdo->prepare("SELECT reference, deleted_at FROM pos_deleted_orders WHERE client_uuid = ? LIMIT 1");
        $st->execute([$clientUuid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null; // table (or column) not created yet: nothing has ever been deleted
    }
}

/**
 * Put back what a PENDING line (never started) still holds deducted, from its own trail in stock_adjustments.
 *
 * Trail rules (the same pairing the void / 86 / recall code uses):
 *   - restores are paired against deductions oldest-first per ingredient, so the first N deductions of an
 *     ingredient are already put back (N = its void_restore rows);
 *   - a deduction whose reason starts 'Voided to wastage' was reclassified as wastage - it physically
 *     happened and stays wasted; the rest of a MIXED line is still restored (skip_voided_wastage);
 *   - only rows created at/after the order existed count, so a legacy row of an older order that happens to
 *     share the numeric id is never mistaken for this line's trail.
 * An empty menu_type cannot stop a restore that has a trail: a placeholder is passed (the recipe fallback is
 * never reached when a trail exists).
 *
 * @return string 'restored' | 'restored_mixed' | 'wasted' | 'none'
 */
function rh_pos_del_put_back_trail(PDO $pdo, string $sourceType, int $sourceId, int $menuItemId, string $menuType, float $qty, string $reason, int $userId, string $notBefore): string
{
    if ($sourceId <= 0) {
        return 'none';
    }
    $sel = $pdo->prepare("SELECT id, ingredient_id, COALESCE(reason, '') AS reason
                            FROM stock_adjustments
                           WHERE source_type = ? AND source_id = ? AND quantity_change < 0 AND created_at >= ?
                        ORDER BY id ASC");
    $sel->execute([$sourceType, $sourceId, $notBefore]);
    $deductions = $sel->fetchAll(PDO::FETCH_ASSOC);
    if (!$deductions) {
        return 'none';
    }

    $restoredLeft = [];
    $hasRestoreCol = function_exists('rh_stock_adjustments_has_restore_col') && rh_stock_adjustments_has_restore_col();
    if ($hasRestoreCol) {
        $rc = $pdo->prepare("SELECT ingredient_id, COUNT(*) FROM stock_adjustments
                              WHERE source_type = 'void_restore' AND restore_of_type = ? AND source_id = ? AND created_at >= ?
                           GROUP BY ingredient_id");
        $rc->execute([$sourceType, $sourceId, $notBefore]);
        $restoredLeft = $rc->fetchAll(PDO::FETCH_KEY_PAIR);
    } else {
        // Legacy schema (no restore_of_type): any restore against this id means it was handled.
        $rc = $pdo->prepare("SELECT COUNT(*) FROM stock_adjustments WHERE source_type = 'void_restore' AND source_id = ? AND created_at >= ?");
        $rc->execute([$sourceId, $notBefore]);
        if ((int)$rc->fetchColumn() > 0) {
            return 'none';
        }
    }

    // Untagged deductions are paired with the restores oldest-first; tagged ones are already wasted.
    $heldUntagged = 0;
    $heldTagged = 0;
    foreach ($deductions as $d) {
        if (strncasecmp((string)$d['reason'], 'Voided to wastage', 17) === 0) {
            $heldTagged++;
            continue;
        }
        $iid = (int)$d['ingredient_id'];
        if (($restoredLeft[$iid] ?? 0) > 0) {
            $restoredLeft[$iid]--;
            continue;
        }
        $heldUntagged++;
    }
    if ($heldUntagged === 0) {
        return $heldTagged > 0 ? 'wasted' : 'none';
    }
    if (!restoreStockForMenuItem($menuItemId, $menuType !== '' ? $menuType : 'food', $qty, $reason, $userId, $sourceId, $sourceType, [
        'skip_voided_wastage' => true,
        'trail_not_before' => $notBefore,
    ])) {
        throw new RuntimeException('Stock restore failed - nothing was deleted. Please retry or contact a manager.');
    }
    return $heldTagged > 0 ? 'restored_mixed' : 'restored';
}

/**
 * Delete an order completely (see the file header for exactly what that means).
 *
 * @return array{ok:bool, order_id:int, reference:string, record_id:int, stock_restored:int,
 *               stock_wasted:int, stock_partial:int, payments_removed:int, folio_voided:int,
 *               unsettled_refund_dropped:float, credit_note:?string, message:string}
 * @throws RuntimeException with a user-facing message (code 403 = not permitted, 404 = no such order)
 */
function rh_delete_pos_order(PDO $pdo, int $orderId, int $userId, string $reason): array
{
    $reason = trim($reason);
    if ($orderId <= 0) {
        throw new RuntimeException('Missing order.', 400);
    }
    if (mb_strlen($reason) < 8) {
        throw new RuntimeException('A reason of at least 8 characters is required to delete an order.', 400);
    }
    if (function_exists('hasPermission') && !hasPermission($userId, 'pos_delete_order')) {
        throw new RuntimeException('You do not have permission to delete orders.', 403);
    }
    // The stock restore helpers use the global connection: they must share this transaction.
    if (!isset($GLOBALS['pdo']) || $pdo !== $GLOBALS['pdo']) {
        throw new RuntimeException('Order deletion must run on the application database connection.', 500);
    }
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Order deletion must run in its own transaction.', 500);
    }
    $tolerance = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;

    // DDL first (it would implicitly commit an open transaction).
    rh_pos_deleted_orders_ensure($pdo);
    $hasStationMessages = rh_pos_del_table_exists($pdo, 'station_messages');
    $hasReadyNotifs = rh_pos_del_table_exists($pdo, 'pos_ready_notifications');
    $hasChargeOrderCol = rh_pos_del_table_exists($pdo, 'booking_charges') && rh_pos_del_column_exists($pdo, 'booking_charges', 'stock_order_id');
    $hasRestoreCol = function_exists('rh_stock_adjustments_has_restore_col') && rh_stock_adjustments_has_restore_col();
    if (!function_exists('rh_auto_credit_overpayment')) {
        require_once __DIR__ . '/../../config/credit-notes.php';
    }

    $actorName = '';
    try {
        $an = $pdo->prepare("SELECT COALESCE(NULLIF(full_name, ''), username) FROM admin_users WHERE id = ?");
        $an->execute([$userId]);
        $actorName = (string)($an->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $actorName = '';
    }

    $creditNoteNumber = null;
    try {
        $pdo->beginTransaction();

        // ── Lock the order row first (the same lock every pay / void / cancel / KDS path takes) ──
        $ordSel = $pdo->prepare("SELECT * FROM stock_orders WHERE id = ? FOR UPDATE");
        $ordSel->execute([$orderId]);
        $order = $ordSel->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new RuntimeException('Order not found - it may already have been deleted.', 404);
        }
        $reference = (string)$order['reference'];
        $orderCreatedAt = (string)($order['created_at'] ?: '1970-01-01 00:00:00');

        $itemSel = $pdo->prepare("SELECT id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, kds_status, station, stock_deducted
                                    FROM stock_order_items WHERE order_id = ? ORDER BY id ASC FOR UPDATE");
        $itemSel->execute([$orderId]);
        $items = $itemSel->fetchAll(PDO::FETCH_ASSOC);

        $paySel = $pdo->prepare("SELECT * FROM payments WHERE booking_type = 'restaurant' AND booking_id = ? ORDER BY id ASC FOR UPDATE");
        $paySel->execute([$orderId]);
        $payments = $paySel->fetchAll(PDO::FETCH_ASSOC);
        $paymentIds = array_map(static fn(array $p): int => (int)$p['id'], $payments);

        // Paid = original sale rows; refunded = COMPLETED refunds only; unsettled = pending / processing
        // refunds, which die with the order and are recorded loudly.
        $paidAmount = 0.0;
        $refundedAmount = 0.0;
        $unsettledRefund = 0.0;
        foreach ($payments as $p) {
            if (!empty($p['deleted_at'])) {
                continue;
            }
            if ((string)($p['payment_type'] ?? '') === 'refund') {
                $amt = (float)(((float)($p['refund_amount'] ?? 0)) > 0 ? $p['refund_amount'] : $p['total_amount']);
                $rs = (string)($p['refund_status'] ?? 'completed');
                if ($rs === 'completed') {
                    $refundedAmount += $amt;
                } elseif (in_array($rs, ['pending', 'processing'], true)) {
                    $unsettledRefund += $amt;
                }
            } elseif (in_array((string)($p['payment_status'] ?? 'completed'), ['completed', 'paid', 'refunded', 'partially_refunded'], true)) {
                $paidAmount += (float)$p['total_amount'];
            }
        }
        $paidAmount = round($paidAmount, 2);
        $refundedAmount = round($refundedAmount, 2);
        $unsettledRefund = round($unsettledRefund, 2);

        // ── Guards that need something reversed first (nothing has been changed yet) ──
        foreach ($payments as $p) {
            if (in_array((string)($p['mra_status'] ?? ''), ['submitted', 'accepted'], true)) {
                throw new RuntimeException('Payment ' . (string)$p['payment_reference'] . ' of ' . $reference . ' was already fiscalised with MRA - it cannot be deleted. Void or refund the order instead.', 409);
            }
        }
        $cnNumbers = [];
        try {
            $pidPh = $paymentIds ? implode(',', array_fill(0, count($paymentIds), '?')) : '';
            // A: credit notes issued from this order or from one of its payments.
            $stA = $pdo->prepare("SELECT cn.credit_note_number FROM credit_notes cn
                      WHERE cn.status <> 'voided'
                        AND ((cn.booking_type = 'restaurant' AND cn.booking_id = ?)" . ($paymentIds ? " OR cn.original_payment_id IN ($pidPh)" : '') . ')');
            $stA->execute(array_merge([$orderId], $paymentIds));
            $cnNumbers = array_merge($cnNumbers, $stA->fetchAll(PDO::FETCH_COLUMN));
            // B: live credit notes applied to this order / redeemed through one of its payments.
            $stB = $pdo->prepare("SELECT cn.credit_note_number FROM credit_note_applications a
                       JOIN credit_notes cn ON cn.id = a.credit_note_id
                      WHERE cn.status <> 'voided'
                        AND ((a.applied_to_booking_type = 'restaurant' AND a.applied_to_booking_id = ?)" . ($paymentIds ? " OR a.payment_id IN ($pidPh)" : '') . ')');
            $stB->execute(array_merge([$orderId], $paymentIds));
            $cnNumbers = array_merge($cnNumbers, $stB->fetchAll(PDO::FETCH_COLUMN));
            // C: live credit notes referenced straight from the order's payment rows.
            if ($paymentIds) {
                $stC = $pdo->prepare("SELECT cn.credit_note_number FROM credit_notes cn
                          WHERE cn.status <> 'voided' AND cn.id IN (SELECT p.credit_note_id FROM payments p WHERE p.id IN ($pidPh) AND p.credit_note_id IS NOT NULL)");
                $stC->execute($paymentIds);
                $cnNumbers = array_merge($cnNumbers, $stC->fetchAll(PDO::FETCH_COLUMN));
            }
        } catch (PDOException $e) {
            error_log('rh_delete_pos_order credit-note guard: ' . $e->getMessage()); // credit-note tables absent
        }
        $cnNumbers = array_values(array_unique(array_filter(array_map('strval', $cnNumbers))));
        if ($cnNumbers) {
            throw new RuntimeException('Credit note ' . implode(', ', array_slice($cnNumbers, 0, 3)) . ' is linked to ' . $reference . ' (issued from it, applied to it or used to pay it). Void that credit note first, then delete the order.', 409);
        }

        // Deleting a payment must not silently wipe its audit trail: refuse if a cascade would.
        if ($paymentIds && rh_pos_del_table_exists($pdo, 'payment_audit_log')) {
            $cas = $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'payments'
                                   AND TABLE_NAME = 'payment_audit_log' AND DELETE_RULE = 'CASCADE'");
            if ((int)$cas->fetchColumn() > 0) {
                $pidPh = implode(',', array_fill(0, count($paymentIds), '?'));
                $au = $pdo->prepare("SELECT COUNT(*) FROM payment_audit_log WHERE payment_id IN ($pidPh)");
                $au->execute($paymentIds);
                if ((int)$au->fetchColumn() > 0) {
                    throw new RuntimeException($reference . ' has payment audit-log entries that would be erased with its payments, so it cannot be deleted. Void or refund the order instead.', 409);
                }
            }
        }

        $stockReason = 'Deleted order ' . $reference;
        $isRoomService = ((string)$order['order_type'] === 'room_service');
        $tally = ['restored' => 0, 'wasted' => 0, 'partial' => 0];
        $lineOutcomes = [];      // [{line_id,name,status,outcome}]
        $namesMixed = [];
        $namesWasted = [];
        $namesUnresolved = [];
        $unresolvedIds = [];
        $noTrail = [];

        // Live room-service charges that own a line's stock (booked against the charge, not the KDS line).
        $trackedChargeSel = null;
        if ($isRoomService && $hasChargeOrderCol) {
            $trackedChargeSel = $pdo->prepare("SELECT COUNT(*) FROM booking_charges
                                                WHERE stock_order_id = ? AND source_item_id = ? AND ABS(quantity - ?) < 0.005
                                                  AND stock_tracked = 1 AND voided = 0");
        }
        $hasTrailSel = $pdo->prepare("SELECT COUNT(*) FROM stock_adjustments WHERE source_type = 'pos_order' AND source_id = ? AND quantity_change < 0 AND created_at >= ?");

        // ── (a) STOCK, line by line. Done BEFORE folio charges are voided. ──
        foreach ($items as $it) {
            $lineId = (int)$it['id'];
            $status = (string)$it['kds_status'];
            $name = (string)$it['item_name'];
            if ($status === 'void') {
                $lineOutcomes[] = ['line_id' => $lineId, 'name' => $name, 'status' => $status, 'outcome' => 'already 86d'];
                continue;
            }
            $hasTrailSel->execute([$lineId, $orderCreatedAt]);
            $hasTrail = (int)$hasTrailSel->fetchColumn() > 0;
            if ($trackedChargeSel && !$hasTrail) {
                $trackedChargeSel->execute([$orderId, (int)$it['menu_item_id'], (float)$it['quantity']]);
                if ((int)$trackedChargeSel->fetchColumn() > 0) {
                    $lineOutcomes[] = ['line_id' => $lineId, 'name' => $name, 'status' => $status, 'outcome' => 'stock on folio charge'];
                    continue; // handled with its folio charge below
                }
            }
            if ($status === 'pending') {
                $res = rh_pos_del_put_back_trail($pdo, 'pos_order', $lineId, (int)$it['menu_item_id'], (string)$it['menu_type'], (float)$it['quantity'], $stockReason . ' (' . $name . ')', $userId, $orderCreatedAt);
                if ($res === 'restored' || $res === 'restored_mixed') {
                    $tally['restored']++;
                    if ($res === 'restored_mixed') {
                        $tally['partial']++;
                        $namesMixed[] = $name;
                    }
                } elseif ($res === 'wasted') {
                    $namesWasted[] = $name;
                } elseif ((int)$it['stock_deducted'] === 1 && !$isRoomService) {
                    $noTrail[] = $name;
                }
                $lineOutcomes[] = ['line_id' => $lineId, 'name' => $name, 'status' => $status, 'outcome' => $res];
                continue;
            }
            // Kitchen started (preparing / ready / collection / served): the food exists - wastage, never restored.
            $alreadyOff = ((int)$it['stock_deducted'] === 1);
            $n = rh_record_item_wastage($pdo, (int)$it['menu_item_id'], (string)$it['menu_type'], (float)$it['quantity'], !$alreadyOff, $userId, $stockReason . ' - wastage (' . $name . ', ' . $status . ')', 'pos_order', $lineId);
            if ($n > 0) {
                $tally['wasted']++;
                $namesWasted[] = $name;
            } elseif ($alreadyOff && !$hasTrail) {
                $noTrail[] = $name;
            }
            $lineOutcomes[] = ['line_id' => $lineId, 'name' => $name, 'status' => $status, 'outcome' => $n > 0 ? 'wastage' : 'none'];
        }

        // Legacy adjustments keyed on the ORDER id (older format): never auto-restored - name the lines.
        $legacyN = 0;
        $itemIdSet = array_map(static fn(array $i): int => (int)$i['id'], $items);
        if (!in_array($orderId, $itemIdSet, true)) {
            $lg = $pdo->prepare("SELECT COUNT(*) FROM stock_adjustments WHERE source_type = 'pos_order' AND source_id = ? AND quantity_change < 0 AND created_at >= ?");
            $lg->execute([$orderId, $orderCreatedAt]);
            $legacyN = (int)$lg->fetchColumn();
        }
        if ($legacyN > 0) {
            foreach ($items as $it) {
                if ((int)$it['stock_deducted'] === 1 && (string)$it['kds_status'] !== 'void') {
                    $hasTrailSel->execute([(int)$it['id'], $orderCreatedAt]);
                    if ((int)$hasTrailSel->fetchColumn() === 0) {
                        $namesUnresolved[] = (string)$it['item_name'];
                        $unresolvedIds[(int)$it['id']] = true;
                    }
                }
            }
            if (!$namesUnresolved) {
                $namesUnresolved[] = '(order-keyed stock movements)';
            }
            $noTrail = array_values(array_diff($noTrail, $namesUnresolved));
        }

        // ── Room-service: folio charges. Tracked charges follow the kitchen rule, then every live charge
        //    is voided and the booking recalculated. ──
        $folioVoided = 0;
        $bookingIds = [];
        $voidedByBooking = [];
        if ($hasChargeOrderCol) {
            $chSel = $pdo->prepare("SELECT id, booking_id, charge_type, source_item_id, quantity, stock_tracked, voided, line_total
                                      FROM booking_charges WHERE stock_order_id = ? FOR UPDATE");
            $chSel->execute([$orderId]);
            $charges = $chSel->fetchAll(PDO::FETCH_ASSOC);
            $voidChargeUpd = $pdo->prepare("UPDATE booking_charges SET voided = 1, voided_at = NOW(), void_reason = ?, voided_by = ?, updated_at = NOW() WHERE id = ? AND voided = 0");
            foreach ($charges as $ch) {
                $bid = (int)$ch['booking_id'];
                $bookingIds[$bid] = true;
                if ((int)$ch['voided'] === 1) {
                    continue; // already voided earlier; its stock was dealt with then
                }
                if (!empty($ch['stock_tracked'])) {
                    $r = rh_restore_room_service_charge_stock($pdo, $orderId, $ch, $stockReason, $userId);
                    if ($r === 'restored') {
                        $tally['restored']++;
                    } elseif ($r === 'wastage') {
                        $tally['wasted']++;
                    }
                }
                $voidChargeUpd->execute([mb_substr('Order ' . $reference . ' deleted: ' . $reason, 0, 255), $userId, (int)$ch['id']]);
                $voidedByBooking[$bid] = round(($voidedByBooking[$bid] ?? 0.0) + (float)$ch['line_total'], 2);
                $folioVoided++;
            }
            if ($charges) {
                // The charges stay on the folio as voided history; they must not point at a deleted order.
                $pdo->prepare("UPDATE booking_charges SET stock_order_id = NULL, updated_at = NOW() WHERE stock_order_id = ?")->execute([$orderId]);
            }
        }
        if (!empty($order['booking_id'])) {
            $bookingIds[(int)$order['booking_id']] = true;
        }

        // Folio totals, then any overpayment the voided charges leave behind becomes a credit note.
        $bookingNotes = [];
        foreach (array_keys($bookingIds) as $bid) {
            if ($bid <= 0) {
                continue;
            }
            if (!recalculateBookingFinancials($bid)) {
                throw new RuntimeException('Could not recalculate the guest folio (booking #' . $bid . ') - nothing was deleted.', 500);
            }
            $voidedTotal = (float)($voidedByBooking[$bid] ?? 0.0);
            $bk = $pdo->prepare("SELECT booking_reference, status, credit_balance, final_invoice_generated FROM bookings WHERE id = ?");
            $bk->execute([$bid]);
            $b = $bk->fetch(PDO::FETCH_ASSOC);
            if (!$b) {
                continue;
            }
            $cnRef = null;
            if ($voidedTotal > $tolerance && (float)$b['credit_balance'] > $tolerance && (string)$b['status'] !== 'cancelled') {
                $cn = rh_auto_credit_overpayment($pdo, 'room', $bid, min((float)$b['credit_balance'], $voidedTotal), 'DEL-' . $reference, $userId);
                if ($cn) {
                    $cnRef = (string)$cn['credit_note_number'];
                    $creditNoteNumber = $creditNoteNumber ?? $cnRef;
                    $bookingNotes[] = 'booking ' . (string)$b['booking_reference'] . ': credit note ' . $cnRef . ' raised for ' . number_format((float)$cn['excess'], 2) . ' (folio charges voided left the booking overpaid)';
                }
            }
            if ((string)$b['status'] === 'checked-out' || (int)($b['final_invoice_generated'] ?? 0) === 1) {
                $bookingNotes[] = 'booking ' . (string)$b['booking_reference'] . ' was already invoiced - invoice not reissued' . ($cnRef ? '; credit note ' . $cnRef . ' raised' : '');
            }
        }

        // ── Snapshot the stock movements, then detach what must not dangle ──
        $detachIds = [];
        foreach ($items as $i) {
            if (empty($unresolvedIds[(int)$i['id']])) {
                $detachIds[] = (int)$i['id'];
            }
        }
        $adjSnapshot = [];
        if ($detachIds) {
            $ph = implode(',', array_fill(0, count($detachIds), '?'));
            $typeSql = $hasRestoreCol
                ? "(sa.source_type = 'pos_order' OR (sa.source_type = 'void_restore' AND (sa.restore_of_type = 'pos_order' OR sa.restore_of_type IS NULL)))"
                : "sa.source_type IN ('pos_order', 'void_restore')";
            $snapSel = $pdo->prepare("SELECT sa.id, sa.source_id, sa.source_type, sa.ingredient_id, sa.quantity_change, LEFT(COALESCE(sa.reason, ''), 120) AS reason
                                        FROM stock_adjustments sa
                                       WHERE sa.source_id IN ($ph) AND $typeSql AND sa.created_at >= ?
                                    ORDER BY sa.id ASC");
            $snapSel->execute(array_merge($detachIds, [$orderCreatedAt]));
            foreach ($snapSel->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $action = (string)$a['source_type'] === 'void_restore'
                    ? 'restored'
                    : (strncasecmp((string)$a['reason'], 'Voided to wastage', 17) === 0 ? 'wasted' : 'deducted');
                $adjSnapshot[] = [
                    'adjustment_id' => (int)$a['id'],
                    'line_id' => (int)$a['source_id'],
                    'ingredient_id' => (int)$a['ingredient_id'],
                    'qty' => (float)$a['quantity_change'],
                    'action' => $action,
                ];
            }
            $suffix = ' [order ' . mb_substr($reference, 0, 40) . ' deleted]';
            $pdo->prepare("UPDATE stock_adjustments sa
                              SET sa.source_id = NULL, sa.reason = LEFT(CONCAT(LEFT(COALESCE(sa.reason, ''), 170), ?), 255)
                            WHERE sa.source_id IN ($ph) AND $typeSql AND sa.created_at >= ?")
                ->execute(array_merge([$suffix], $detachIds, [$orderCreatedAt]));
        }

        // ── Snapshot for the permanent record (taken before anything is deleted) ──
        $splitRows = [];
        $auditRows = [];
        try {
            $s = $pdo->prepare("SELECT * FROM stock_order_splits WHERE order_id = ? ORDER BY split_number");
            $s->execute([$orderId]);
            $splitRows = $s->fetchAll(PDO::FETCH_ASSOC);
            $a = $pdo->prepare("SELECT event, actor_name, details, created_at FROM stock_order_audit WHERE order_id = ? ORDER BY id ASC LIMIT 300");
            $a->execute([$orderId]);
            $auditRows = $a->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('rh_delete_pos_order snapshot: ' . $e->getMessage());
        }
        $bookingReference = null;
        if (!empty($order['booking_id'])) {
            try {
                $br = $pdo->prepare("SELECT booking_reference FROM bookings WHERE id = ?");
                $br->execute([(int)$order['booking_id']]);
                $bookingReference = $br->fetchColumn() ?: null;
            } catch (Throwable $e) {
                $bookingReference = null;
            }
        }

        $stockSummary = sprintf(
            'Stock: %d line(s) put back, %d recorded as wastage%s%s%s%s',
            $tally['restored'],
            $tally['wasted'],
            $namesMixed ? '; mixed lines restored in part: ' . implode(', ', array_slice($namesMixed, 0, 5)) : '',
            $namesWasted ? '; already wasted / now wasted: ' . implode(', ', array_slice(array_unique($namesWasted), 0, 5)) : '',
            $namesUnresolved ? '; NOT resolved (legacy order-keyed stock, left untouched): ' . implode(', ', array_slice($namesUnresolved, 0, 5)) : '',
            $noTrail ? '; no stock movement on record: ' . implode(', ', array_slice($noTrail, 0, 5)) : ''
        );
        $noteParts = [];
        if ($unsettledRefund > $tolerance) {
            $noteParts[] = 'UNSETTLED REFUND DROPPED: ' . number_format($unsettledRefund, 2) . ' (a pending/processing refund was removed with the order - the customer may still be owed this)';
        }
        foreach ($bookingNotes as $bn) {
            $noteParts[] = $bn;
        }
        $noteParts[] = 'Cash already taken or handed back is not moved.';
        $notes = implode(' | ', $noteParts);

        $snapshot = json_encode([
            'order' => $order,
            'items' => $items,
            'payments' => $payments,
            'splits' => $splitRows,
            'audit' => $auditRows,
            'stock' => [
                'summary' => $stockSummary,
                'tally' => $tally,
                'lines' => $lineOutcomes,
                'adjustments' => $adjSnapshot,
                'mixed_lines' => $namesMixed,
                'wasted_lines' => array_values(array_unique($namesWasted)),
                'unresolved_lines' => $namesUnresolved,
                'no_trail_lines' => $noTrail,
            ],
            'folio_charges_voided' => $folioVoided,
            'unsettled_refund_dropped' => $unsettledRefund,
            'credit_note' => $creditNoteNumber,
            'booking_notes' => $bookingNotes,
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        // ── (b)/(c) MONEY + CHILD ROWS ──
        if ($paymentIds) {
            $ph = implode(',', array_fill(0, count($paymentIds), '?'));
            if (rh_pos_del_table_exists($pdo, 'receipt_events')) {
                $pdo->prepare("DELETE FROM receipt_events WHERE payment_id IN ($ph)")->execute($paymentIds);
            }
            if (rh_pos_del_table_exists($pdo, 'mra_submission_queue')) {
                $pdo->prepare("DELETE FROM mra_submission_queue WHERE payment_id IN ($ph)")->execute($paymentIds);
            }
            // Refund / reversal rows reference their original (FK), so they go first.
            $pdo->prepare("DELETE FROM payments WHERE booking_type = 'restaurant' AND booking_id = ? AND (payment_type = 'refund' OR original_payment_id IS NOT NULL)")->execute([$orderId]);
            $pdo->prepare("DELETE FROM payments WHERE booking_type = 'restaurant' AND booking_id = ?")->execute([$orderId]);
        }
        $pdo->prepare("DELETE FROM stock_kds_events WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM stock_order_audit WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM stock_order_deliveries WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM stock_order_splits WHERE order_id = ?")->execute([$orderId]);
        if ($hasStationMessages) {
            $pdo->prepare("DELETE FROM station_messages WHERE order_id = ?")->execute([$orderId]);
        }
        if ($hasReadyNotifs) {
            $pdo->prepare("DELETE FROM pos_ready_notifications WHERE order_id = ?")->execute([$orderId]);
        }
        $pdo->prepare("DELETE FROM stock_order_items WHERE order_id = ?")->execute([$orderId]);

        // ── (d) AUDIT: permanent record, then the order itself ──
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $pdo->prepare("INSERT INTO pos_deleted_orders
                (original_order_id, reference, client_uuid, order_type, order_status, total_amount, paid_amount, refunded_amount, tip_amount,
                 payment_method, booking_id, booking_reference, order_created_at, order_created_by,
                 deleted_by, deleted_by_name, reason, notes, ip_address, stock_summary, payments_removed, folio_charges_voided, snapshot_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $orderId,
                mb_substr($reference, 0, 50),
                !empty($order['client_uuid']) ? mb_substr((string)$order['client_uuid'], 0, 64) : null,
                mb_substr((string)$order['order_type'], 0, 30),
                mb_substr((string)$order['status'], 0, 30),
                (float)$order['total_amount'],
                $paidAmount,
                $refundedAmount,
                (float)($order['tip_amount'] ?? 0),
                $order['payment_method'] ?: null,
                !empty($order['booking_id']) ? (int)$order['booking_id'] : null,
                $bookingReference,
                $order['created_at'] ?: null,
                !empty($order['created_by']) ? (int)$order['created_by'] : null,
                $userId > 0 ? $userId : null,
                mb_substr($actorName, 0, 150),
                mb_substr($reason, 0, 500),
                mb_substr($notes, 0, 1500),
                $ip,
                mb_substr($stockSummary, 0, 500),
                count($paymentIds),
                $folioVoided,
                $snapshot !== false ? $snapshot : null,
            ]);
        $recordId = (int)$pdo->lastInsertId();

        $pdo->prepare("DELETE FROM stock_orders WHERE id = ?")->execute([$orderId]);

        if (function_exists('logActivity')) {
            logActivity($userId, 'pos_delete_order',
                'Deleted order ' . $reference . ' (' . (string)$order['order_type'] . ', ' . (string)$order['status']
                . ', total ' . number_format((float)$order['total_amount'], 2) . ', paid ' . number_format($paidAmount, 2)
                . ', refunded ' . number_format($refundedAmount, 2) . '): ' . $reason . ' | ' . $stockSummary
                . ' | payments removed ' . count($paymentIds) . ' | ' . $notes . ' | record #' . $recordId);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof RuntimeException) {
            throw $e;
        }
        throw new RuntimeException('Could not delete the order: ' . $e->getMessage(), 500, $e);
    }

    rh_clear_stock_dashboard_cache();
    if (function_exists('rh_log_event')) {
        rh_log_event('pos/delete-order', 'warning', 'Order deleted', [
            'order_id' => $orderId, 'reference' => $reference, 'by' => $userId, 'record_id' => $recordId,
            'status' => (string)$order['status'], 'total' => (float)$order['total_amount'], 'paid' => $paidAmount,
            'unsettled_refund_dropped' => $unsettledRefund,
        ]);
    }

    $message = 'Order ' . $reference . ' deleted. ' . $stockSummary . '. Payments removed: ' . count($paymentIds)
        . ($folioVoided > 0 ? '. Room folio charges voided: ' . $folioVoided : '')
        . ($unsettledRefund > $tolerance ? '. UNSETTLED REFUND DROPPED: ' . number_format($unsettledRefund, 2) . ' - the customer may still be owed it' : '')
        . ($bookingNotes ? '. ' . implode('; ', $bookingNotes) : '')
        . '. Cash already taken or handed back is not moved - settle the drawer separately.';
    return [
        'ok' => true,
        'order_id' => $orderId,
        'reference' => $reference,
        'record_id' => $recordId,
        'stock_restored' => $tally['restored'],
        'stock_wasted' => $tally['wasted'],
        'stock_partial' => $tally['partial'],
        'payments_removed' => count($paymentIds),
        'folio_voided' => $folioVoided,
        'unsettled_refund_dropped' => $unsettledRefund,
        'credit_note' => $creditNoteNumber,
        'message' => $message,
    ];
}
