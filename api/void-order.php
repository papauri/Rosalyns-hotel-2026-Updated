<?php
/**
 * api/void-order.php — Admin/manager void of a restaurant order from any UI (POS, lifecycle, etc.).
 *
 *   POST: csrf_token, order_id, void_reason (>=8 chars), [void_notes], [damaged=1]
 *   Auth: session admin_user with the pos_void AND stock_orders permissions.
 *   Effects:
 *     - Stock: lines not started are restored (FIFO batch credit + adjustments row); started/served food
 *       is wastage. SHOP MODE (restaurant off, owner 2026-10-22): sold goods go BACK on the shelf, or
 *       are booked as waste when "damaged" is posted. The response message says which happened.
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
require_once __DIR__ . '/../admin/includes/pos-notices.php';
require_once __DIR__ . '/../admin/includes/pos-order-close.php';
require_once __DIR__ . '/../includes/admin-session.php';
rh_admin_session_start(); // 8h idle sign-out

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

try {
    $pdo->beginTransaction();
    $oh = $pdo->prepare("SELECT id, reference, status, order_type, booking_id, created_by FROM stock_orders WHERE id=? FOR UPDATE");
    $oh->execute([$orderId]);
    $order = $oh->fetch(PDO::FETCH_ASSOC);
    if (!$order) { $pdo->rollBack(); vjerr('Order not found', 404); }

    /* A delivered room-service order is 'completed' (room-service-dashboard mark_delivered). It can still
     * be reversed, but ONLY while its folio charges are open: the linked booking must still be
     * checked-in and not yet final-invoiced. After checkout the charges are on an issued invoice and must
     * be corrected with a credit on the booking instead. */
    $isDeliveredRoomService = ($order['order_type'] ?? '') === 'room_service' && ($order['status'] ?? '') === 'completed';
    if ($isDeliveredRoomService) {
        $bookingIdForVoid = (int)($order['booking_id'] ?? 0);
        if ($bookingIdForVoid <= 0) {
            $lk = $pdo->prepare("SELECT booking_id FROM booking_charges WHERE stock_order_id = ? AND voided = 0 ORDER BY id LIMIT 1");
            $lk->execute([$orderId]);
            $bookingIdForVoid = (int)$lk->fetchColumn();
        }
        $folioOpen = false;
        if ($bookingIdForVoid > 0) {
            $bkLock = $pdo->prepare("SELECT status FROM bookings WHERE id = ? FOR UPDATE");
            $bkLock->execute([$bookingIdForVoid]);
            $bkRow = $bkLock->fetch(PDO::FETCH_ASSOC);
            $folioOpen = $bkRow && ($bkRow['status'] ?? '') === 'checked-in';
            if ($folioOpen && v_column_exists($pdo, 'bookings', 'final_invoice_generated')) {
                $invChk = $pdo->prepare("SELECT final_invoice_generated FROM bookings WHERE id = ?");
                $invChk->execute([$bookingIdForVoid]);
                if ((int)$invChk->fetchColumn() === 1) $folioOpen = false;
            }
        }
        if (!$folioOpen) {
            $pdo->rollBack();
            vjerr('Order ' . $order['reference'] . ' was delivered and the guest has checked out (or the folio is closed) - it can no longer be voided here. Issue a credit on the booking instead.');
        }
    }
    /* Only a live sale can be voided: 'placed' (open tab, possibly part-paid on a split) or 'paid'
     * (or a delivered room-service order whose folio is still open, checked above).
     * 'refunded' already had its money returned, 'voided'/'cancelled' are already reversed -
     * voiding them again would reverse the ledger a second time. */
    if (!$isDeliveredRoomService && !in_array($order['status'], ['placed','paid'], true)) {
        $pdo->rollBack();
        vjerr('Order ' . $order['reference'] . ' is ' . str_replace('_', ' ', (string)$order['status']) . ' - only an open or paid order can be voided.');
    }

    // Shared close (stock restore / wastage, folio charges, order + lines, payment reversal with contra
    // rows, ready pings, STOP note, audit + KDS event): admin/includes/pos-order-close.php.
    // Booking balances are re-derived here, inside the transaction.
    $closed = rh_close_pos_order($pdo, $orderId, (int)$user['id'], $details, 'void', ['damaged' => rh_pos_damaged_flag()]);
    $folioVoided = (int)$closed['folio_voided'];
    foreach ($closed['booking_ids'] as $closedBookingId) {
        recalculateBookingFinancials((int)$closedBookingId);
    }

    $pdo->commit();
    if (function_exists('deleteCache')) { try { rh_clear_stock_dashboard_cache(); } catch (Throwable $e) {} }

    /* Tell the cashier who opened the order that it was voided from another screen (actionable
     * notice on the till). When a payment was reversed the notice says the guest is owed the money
     * and on whose shift - the wording is built in rh_close_pos_order(). */
    foreach ($closed['notices'] as $notice) {
        try {
            rh_pos_notice_send($pdo, $notice);
        } catch (Throwable $e) {
            error_log('void-order POS notify: ' . $e->getMessage()); // best-effort; the void is committed
        }
    }
    if (function_exists('rh_log_offline_replay')) {
        rh_log_offline_replay($pdo, '/api/void-order.php', [
            'action' => 'void_order',
            'entity_type' => 'stock_order',
            'entity_id' => $orderId,
            'entity_reference' => $order['reference'] ?? null,
            'response_status' => 200,
            'response_summary' => 'Order voided (stock put back: ' . (int)($closed['stock_restored'] ?? 0) . ', waste: ' . (int)($closed['stock_wasted'] ?? 0) . ')',
            'details' => ['reason' => $reason],
        ]);
    }
    $stockRestored = (int)($closed['stock_restored'] ?? 0);
    $stockWasted = (int)($closed['stock_wasted'] ?? 0);
    $message = "Order {$order['reference']} voided.";
    if ($stockRestored > 0 && $stockWasted > 0) {
        $message .= " Stock put back for {$stockRestored} line(s); {$stockWasted} line(s) recorded as waste.";
    } elseif ($stockRestored > 0) {
        $message .= ' Stock put back.';
    } elseif ($stockWasted > 0) {
        $message .= !empty($closed['shop_mode']) ? ' Recorded as waste (damaged goods).' : ' Recorded as waste.';
    } else {
        $message .= ' No stock to put back.';
    }
    if ($folioVoided > 0) {
        $message .= " {$folioVoided} room folio charge(s) voided.";
    }
    vjok(['order_id'=>$orderId,'reference'=>$order['reference'],'folio_charges_voided'=>$folioVoided,'stock_restored'=>$stockRestored,'stock_wasted'=>$stockWasted,'message'=>$message]);} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    vjerr($e->getMessage(), 500);
}
