<?php
/**
 * api/void-order.php — Admin/manager void of a restaurant order from any UI (POS, lifecycle, etc.).
 *
 *   POST: csrf_token, order_id, void_reason (>=8 chars), [void_notes]
 *   Auth: session admin_user with role admin|manager AND stock_orders permission.
 *   Effects:
 *     - Restores stock (FIFO batch credit + adjustments row)
 *     - Marks order voided (kitchen_status='served', served_at stamped, void_reason+notes saved)
 *     - Voids any open KDS/BDS/CDS items (kds_status='void')
 *     - Cancels the linked payment row, appends VOID note
 *     - Logs into stock_order_audit + stock_kds_events for full lifecycle visibility
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../admin/includes/permissions.php';
require_once __DIR__ . '/../admin/includes/offline-log.php';
require_once __DIR__ . '/../includes/station-hours.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function vjerr(string $m, int $code = 400): void { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$m]); exit; }
function vjok(array $extra = []): void { echo json_encode(array_merge(['ok'=>true], $extra)); exit; }

function v_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') vjerr('POST only', 405);
if (empty($_SESSION['admin_user'])) vjerr('Not authenticated', 401);
$user = $_SESSION['admin_user'];
if (!in_array($user['role'] ?? '', ['admin','manager'], true)) vjerr('Only admins/managers may void', 403);
if (!hasPermission((int)$user['id'], 'stock_orders')) vjerr('Forbidden', 403);
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) vjerr('Invalid CSRF token', 403);

$orderId  = (int)($_POST['order_id'] ?? 0);
$reason   = trim((string)($_POST['void_reason'] ?? ''));
$notes    = trim((string)($_POST['void_notes'] ?? ''));
$ip       = $_SERVER['REMOTE_ADDR'] ?? null;

if ($orderId <= 0) vjerr('Missing order_id');
if (mb_strlen($reason) < 8) vjerr('Void reason is required (at least 8 characters)');
$details = $reason . ($notes !== '' ? "\nNotes: " . $notes : '');

// Reuse helper directly (inlined below).
function v_restoreFromPosOrder(PDO $pdo, int $orderId, ?int $doneBy): void {
    $byBatch = []; $byIngredient = [];
    /* source_id on a 'pos_order' adjustment is the stock_order_items.id, NOT the order id —
     * every deduction path passes the ITEM id (kds-action.php bump/ready, and
     * rh_auto_serve_bar_items) so that two lines sharing an ingredient don't collide on the
     * idempotency check. Looking these up by order id therefore matched nothing and silently
     * restored NO stock on void, while still reporting "Stock restored." to the user.
     * Matching on item ids only (not `OR source_id = orderId`) is deliberate: order ids and
     * order-item ids come from different sequences, so an order-id match could collide with
     * an unrelated order's line and credit back stock that was never sold. */
    $sel = $pdo->prepare("SELECT sa.id AS adjustment_id, sa.ingredient_id, sa.quantity_change, sbd.batch_id, sbd.quantity_deducted FROM stock_adjustments sa LEFT JOIN stock_batch_deductions sbd ON sbd.adjustment_id = sa.id WHERE sa.source_type = 'pos_order' AND sa.source_id IN (SELECT id FROM stock_order_items WHERE order_id = ?)");
    $sel->execute([$orderId]);
    $seen = [];
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $aid = (int)$h['adjustment_id'];
        if (!isset($seen[$aid])) {
            $seen[$aid] = true;
            $iid = (int)$h['ingredient_id'];
            $byIngredient[$iid] = ($byIngredient[$iid] ?? 0) + abs((float)$h['quantity_change']);
        }
        if (!empty($h['batch_id'])) {
            $bid = (int)$h['batch_id'];
            $byBatch[$bid] = ($byBatch[$bid] ?? 0) + (float)$h['quantity_deducted'];
        }
    }
    if ($byBatch) {
        $bUpd = $pdo->prepare("UPDATE stock_batches SET quantity_remaining = quantity_remaining + ?, status = CASE WHEN status='depleted' THEN 'active' ELSE status END, updated_at=NOW() WHERE id=?");
        foreach ($byBatch as $bid=>$q) $bUpd->execute([$q, $bid]);
    }
    $cost = $pdo->prepare("SELECT cost_per_unit FROM stock_ingredients WHERE id=?");
    $adj  = $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by) VALUES (?, ?, 'POS order voided (admin)', 'void_restore', ?, ?, ?)");
    $ing  = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + ?, updated_at=NOW() WHERE id=?");
    foreach ($byIngredient as $iid=>$qty) {
        $cost->execute([$iid]); $cpu = (float)($cost->fetchColumn() ?: 0);
        $adj->execute([$iid, $qty, $orderId, $cpu, $doneBy]);
        $ing->execute([$qty, $iid]);
    }
}

function v_voidRoomServiceFolioCharges(PDO $pdo, int $orderId, string $reason, int $voidedBy): int {
    if (!v_column_exists($pdo, 'booking_charges', 'stock_order_id')) {
        return 0;
    }

    $stmt = $pdo->prepare("SELECT id, booking_id, charge_type, source_item_id, quantity, stock_tracked FROM booking_charges WHERE stock_order_id = ? AND voided = 0 FOR UPDATE");
    $stmt->execute([$orderId]);
    $charges = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$charges) {
        return 0;
    }

    // If stock was already deducted via the POS order path (source_type='pos_order'),
    // v_restoreFromPosOrder() has already restored it. Only call restoreStockForMenuItem()
    // for deductions recorded under source_type='room_service' to avoid double-restoration.
    /* Same item-id keying as v_restoreFromPosOrder above. This guard MUST agree with it:
     * it decides whether the folio path should also restore, so an order-id lookup here
     * (always 0 rows) would let both paths credit the same stock back twice now that the
     * POS restore actually finds its rows. */
    $posAdjStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_adjustments WHERE source_type = 'pos_order' AND source_id IN (SELECT id FROM stock_order_items WHERE order_id = ?)");
    $posAdjStmt->execute([$orderId]);
    $stockAlreadyRestoredViaPosPath = (int)$posAdjStmt->fetchColumn() > 0;

    $bookingIds = [];
    $update = $pdo->prepare("UPDATE booking_charges SET voided = 1, voided_at = NOW(), void_reason = ?, voided_by = ?, updated_at = NOW() WHERE id = ?");
    foreach ($charges as $charge) {
        $chargeId = (int)$charge['id'];
        $update->execute([mb_substr($reason, 0, 255), $voidedBy, $chargeId]);
        if (!$stockAlreadyRestoredViaPosPath
            && !empty($charge['stock_tracked'])
            && in_array((string)$charge['charge_type'], ['food', 'drink'], true)
            && !empty($charge['source_item_id'])
        ) {
            restoreStockForMenuItem((int)$charge['source_item_id'], (string)$charge['charge_type'], (float)$charge['quantity'], 'Room service order voided: ' . $reason, $voidedBy, $chargeId);
        }
        $bookingIds[(int)$charge['booking_id']] = true;
    }

    foreach (array_keys($bookingIds) as $bookingId) {
        recalculateBookingFinancials((int)$bookingId);
    }

    return count($charges);
}

try {
    $pdo->beginTransaction();
    $oh = $pdo->prepare("SELECT id, reference, status, order_type FROM stock_orders WHERE id=? FOR UPDATE");
    $oh->execute([$orderId]);
    $order = $oh->fetch(PDO::FETCH_ASSOC);
    if (!$order) { $pdo->rollBack(); vjerr('Order not found', 404); }
    if (in_array($order['status'], ['cancelled','voided'], true)) { $pdo->rollBack(); vjerr('Order already reversed'); }

    v_restoreFromPosOrder($pdo, $orderId, (int)$user['id']);
    $folioVoided = v_voidRoomServiceFolioCharges($pdo, $orderId, $details, (int)$user['id']);

    $pdo->prepare("UPDATE stock_orders SET status='voided', voided_by=?, voided_at=NOW(), void_reason=?, updated_at=NOW(), kitchen_status='served', served_at=COALESCE(served_at, NOW()) WHERE id=?")
         ->execute([(int)$user['id'], mb_substr($details, 0, 500), $orderId]);
    $pdo->prepare("UPDATE stock_order_items SET kds_status='void', served_at=COALESCE(served_at, NOW()), bumped_by=? WHERE order_id=? AND kds_status NOT IN ('served','void')")
         ->execute([(int)$user['id'], $orderId]);

    // Reverse the original sale with a contra row (payment_type='refund' — the same category
    // every other report already nets out), the same way admin/pos.php's refund_order does.
    // This USED TO overwrite the original payment row to status='cancelled'/'failed' in place,
    // which erased the original sale figure and left nothing in `payments` explaining why a
    // report summing payment_status='completed' and a report summing stock_orders.status would
    // disagree. The original row now stays untouched as the historical record of what was
    // charged; only a paid order gets (or needs) a reversal.
    $origVoidPayStmt = $pdo->prepare("SELECT id, payment_amount, vat_rate, vat_amount, total_amount, payment_method, receipt_number FROM payments WHERE booking_type='restaurant' AND booking_id=? AND COALESCE(payment_type,'') != 'refund' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
    $origVoidPayStmt->execute([$orderId]);
    $origVoidPay = $origVoidPayStmt->fetch(PDO::FETCH_ASSOC);
    if ($origVoidPay) {
        $voidBusinessDate = function_exists('rh_station_union_business_window')
            ? (rh_station_union_business_window()['business_date'] ?? date('Y-m-d'))
            : date('Y-m-d');
        $pdo->prepare("INSERT INTO payments (
                payment_reference, booking_type, booking_id, booking_reference,
                payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, status,
                original_payment_id, refund_reason, refund_status, refund_amount,
                notes, recorded_by, created_at
            ) VALUES (?, 'restaurant', ?, ?, ?, ?, ?, ?, ?, ?, 'refund', 'completed', 'completed', ?, ?, 'completed', ?, ?, ?, NOW())")
            ->execute([
                'VOID-' . ($order['reference'] ?? ('ORD' . $orderId)),
                $orderId,
                $order['reference'] ?? null,
                $voidBusinessDate,
                (float)$origVoidPay['payment_amount'],
                (float)$origVoidPay['vat_rate'],
                (float)$origVoidPay['vat_amount'],
                (float)$origVoidPay['total_amount'],
                $origVoidPay['payment_method'],
                (int)$origVoidPay['id'],
                /* refund_reason is an ENUM, not free text — see the matching note in
                 * admin/pos.php's refund_order. 'cancellation' is the closest listed member
                 * for a void; the operator's wording goes to `notes` below. */
                'cancellation',
                (float)$origVoidPay['total_amount'],
                'Void: ' . $details,
                (int)$user['id'],
            ]);
    }

    /* Retract any outstanding "ready for collection" ping and unacknowledged station note for
     * this order. The POS poll only suppresses a notification while items are still in
     * progress — once every item is 'void' that check passes, so a voided order would keep
     * telling a waiter to go and collect food that no longer exists. */
    try {
        $pdo->prepare("DELETE FROM pos_ready_notifications WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE station_messages SET pos_acknowledged = 1, pos_acknowledged_at = NOW(), pos_acknowledged_by = ? WHERE order_id = ? AND source = 'station' AND COALESCE(pos_acknowledged, 0) = 0")
             ->execute([(int)$user['id'], $orderId]);
    } catch (Throwable $e) {
        error_log('void-order notification cleanup: ' . $e->getMessage());
    }

    $actorName = $user['full_name'] ?? $user['username'] ?? 'admin';
    $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, 'voided', ?, ?)")
         ->execute([$orderId, (int)$user['id'], $actorName, $details, $ip]);
    try {
        $pdo->prepare("INSERT INTO stock_kds_events (order_id, event, from_status, to_status, user_id, user_name, ip_address) VALUES (?, 'voided', 'in_progress', 'void', ?, ?, ?)")
             ->execute([$orderId, (int)$user['id'], $actorName, $ip]);
    } catch (Throwable $e) { /* legacy */ }

    $pdo->commit();
    if (function_exists('deleteCache')) { try { deleteCache('stock_dashboard_metrics_v1'); } catch (Throwable $e) {} }
    if (function_exists('rh_log_offline_replay')) {
        rh_log_offline_replay($pdo, '/api/void-order.php', [
            'action' => 'void_order',
            'entity_type' => 'stock_order',
            'entity_id' => $orderId,
            'entity_reference' => $order['reference'] ?? null,
            'response_status' => 200,
            'response_summary' => 'Order voided + stock/folio restored',
            'details' => ['reason' => $reason],
        ]);
    }
    $message = "Order {$order['reference']} voided. Stock restored.";
    if ($folioVoided > 0) {
        $message .= " {$folioVoided} room folio charge(s) voided.";
    }
    vjok(['order_id'=>$orderId,'reference'=>$order['reference'],'folio_charges_voided'=>$folioVoided,'message'=>$message]);} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    vjerr($e->getMessage(), 500);
}
