<?php

/**
 * Shared settlement-time serve helpers for restaurant/POS orders.
 *
 * Extracted so every till path behaves identically. admin/pos.php had this logic inline and
 * admin/restaurant-tables.php had none at all, so settling a table from the table registry
 * left un-bumped drinks with stock_deducted=0 and kds_status='pending' forever: the stock was
 * never taken off the shelf and the ticket stayed on the bar display under an order that had
 * already been paid for.
 */

/**
 * Auto-serve bar / coffee-bar items on an order at settlement time.
 *
 * Drinks are handed to the customer immediately, so they must not block settling the tab the
 * way food does. Mirrors the KDS bump: deduct stock for any not-yet-deducted drink line, then
 * mark the lines served. Returns the number of lines served.
 */
function rh_auto_serve_bar_items(PDO $pdo, int $orderId, array $actor): int
{
    $sel = $pdo->prepare("SELECT id, menu_item_id, menu_type, quantity, stock_deducted FROM stock_order_items WHERE order_id = ? AND station IN ('bar','coffee_bar') AND kds_status NOT IN ('served','void')");
    $sel->execute([$orderId]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;

    $actorId = (int)($actor['id'] ?? 0);

    // Deduct stock for any drink lines that never went through the KDS bump.
    foreach ($rows as $r) {
        if ((int)$r['stock_deducted'] === 0) {
            $ok = deductStockForMenuItem((int)$r['menu_item_id'], (string)$r['menu_type'], (float)$r['quantity'], 'pos_order', (int)$r['id'], $actorId);
            if ($ok) {
                $pdo->prepare("UPDATE stock_order_items SET stock_deducted = 1 WHERE id = ?")->execute([(int)$r['id']]);
            } else {
                error_log("rh_auto_serve_bar_items: stock deduction failed for item #{$r['id']} on order #{$orderId}");
            }
        }
    }

    $pdo->prepare("UPDATE stock_order_items SET kds_status='served', started_at=COALESCE(started_at,NOW()), ready_at=COALESCE(ready_at,NOW()), served_at=NOW(), bumped_by=? WHERE order_id = ? AND station IN ('bar','coffee_bar') AND kds_status NOT IN ('served','void')")
        ->execute([$actorId, $orderId]);

    // If everything on the order is now served, mark the order served too.
    $remain = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE order_id = ? AND kds_status NOT IN ('served','void')");
    $remain->execute([$orderId]);
    if ((int)$remain->fetchColumn() === 0) {
        $pdo->prepare("UPDATE stock_orders SET kitchen_status='served', served_at=COALESCE(served_at,NOW()) WHERE id = ?")->execute([$orderId]);
    }

    try {
        $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, 'bar_items_auto_served', ?, ?)")
            ->execute([
                $orderId,
                $actorId,
                (string)($actor['full_name'] ?? $actor['username'] ?? ''),
                json_encode(['count' => count($rows), 'reason' => 'auto-served on tab settlement']),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (Throwable $e) {
        error_log('rh_auto_serve_bar_items audit: ' . $e->getMessage());
    }

    return count($rows);
}
