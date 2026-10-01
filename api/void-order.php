<?php
/**
 * api/void-order.php — Admin/manager void of a restaurant order from any UI (POS, lifecycle, etc.).
 *
 *   POST: csrf_token, order_id, void_reason (>=8 chars), [void_notes]
 *   Auth: session admin_user with the pos_void AND stock_orders permissions.
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
require_once __DIR__ . '/../admin/includes/restaurant-payment-sync.php';
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
if (!hasPermission((int)$user['id'], 'pos_void')) vjerr('You do not have permission to void orders', 403);
if (!hasPermission((int)$user['id'], 'stock_orders')) vjerr('Forbidden', 403);
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) vjerr('Invalid CSRF token', 403);

$orderId  = (int)($_POST['order_id'] ?? 0);
$reason   = trim((string)($_POST['void_reason'] ?? ''));
$notes    = trim((string)($_POST['void_notes'] ?? ''));
$ip       = $_SERVER['REMOTE_ADDR'] ?? null;

if ($orderId <= 0) vjerr('Missing order_id');
if (mb_strlen($reason) < 8) vjerr('Void reason is required (at least 8 characters)');
$details = $reason . ($notes !== '' ? "\nNotes: " . $notes : '');

// Stock restore is shared with api/cancel-order.php and admin/stock-orders.php
// (rh_restore_pos_order_stock in restaurant-payment-sync.php): per-line, matched on the
// original deduction, and it skips lines an 86 or a KDS recall already dealt with.
function v_restoreFromPosOrder(PDO $pdo, int $orderId, ?int $doneBy): void {
    rh_restore_pos_order_stock($pdo, $orderId, $doneBy, 'POS order voided (admin)');
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
    /* Only a live sale can be voided: 'placed' (open tab, possibly part-paid on a split) or 'paid'.
     * 'refunded' already had its money returned, 'voided'/'cancelled' are already reversed -
     * voiding them again would reverse the ledger a second time. */
    if (!in_array($order['status'], ['placed','paid'], true)) {
        $pdo->rollBack();
        vjerr('Order ' . $order['reference'] . ' is ' . str_replace('_', ' ', (string)$order['status']) . ' - only an open or paid order can be voided.');
    }

    v_restoreFromPosOrder($pdo, $orderId, (int)$user['id']);
    $folioVoided = v_voidRoomServiceFolioCharges($pdo, $orderId, $details, (int)$user['id']);

    $pdo->prepare("UPDATE stock_orders SET status='voided', voided_by=?, voided_at=NOW(), void_reason=?, updated_at=NOW(), kitchen_status='served', served_at=COALESCE(served_at, NOW()) WHERE id=?")
         ->execute([(int)$user['id'], mb_substr($details, 0, 500), $orderId]);
    $pdo->prepare("UPDATE stock_order_items SET kds_status='void', served_at=COALESCE(served_at, NOW()), bumped_by=? WHERE order_id=? AND kds_status NOT IN ('served','void')")
         ->execute([(int)$user['id'], $orderId]);

    // Reverse the sale with contra rows (payment_type='refund' - the category every report already
    // nets out) through the shared method used by the POS refund: one reversal per original payment
    // row (per split leg, in that leg's tender), for only what is still refundable after any
    // earlier refund. The original rows are never mutated. An order that was never paid simply has
    // nothing to reverse. 'cancellation' is the closest member of the refund_reason ENUM; the
    // operator's wording goes to notes.
    rh_reverse_restaurant_payments(
        $pdo,
        $orderId,
        (string)($order['reference'] ?? ('ORD' . $orderId)),
        'VOID-',
        'cancellation',
        'Void: ' . $details,
        (int)$user['id']
    );
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
