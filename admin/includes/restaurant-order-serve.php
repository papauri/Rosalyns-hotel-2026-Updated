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

/**
 * Recipe requirements for a menu item at a given portion count.
 * Shared so every path that deducts stock can pre-check it the same way.
 */
function rh_recipe_requirements(PDO $pdo, int $menuItemId, string $menuType, float $portions): array
{
    if ($portions <= 0 || $menuType === '' || !ensureStockTablesExist()) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT i.id AS ingredient_id, i.name, i.unit, i.current_quantity,
                                  ((sri.quantity_per_portion * ?) / (GREATEST(sri.yield_percent, 0.1) / 100)) AS required_qty
                             FROM stock_recipes sr
                             INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
                             INNER JOIN stock_ingredients i ON i.id = sri.ingredient_id
                            WHERE sr.menu_item_id = ?
                              AND sr.menu_type = ?
                              AND sri.quantity_per_portion > 0
                            FOR UPDATE");
    $stmt->execute([$portions, $menuItemId, $menuType]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Aggregate ingredient requirements across order lines and return those that cannot be met.
 * Lines sharing an ingredient are summed first, so two dishes each needing half the remaining
 * stock are correctly reported as short.
 */
function rh_stock_shortages_for_items(PDO $pdo, array $items): array
{
    $requirements = [];

    foreach ($items as $item) {
        $menuItemId = (int)($item['menu_item_id'] ?? 0);
        $menuType = (string)($item['menu_type'] ?? '');
        $quantity = (float)($item['quantity'] ?? 0);

        foreach (rh_recipe_requirements($pdo, $menuItemId, $menuType, $quantity) as $line) {
            $ingredientId = (int)$line['ingredient_id'];
            if (!isset($requirements[$ingredientId])) {
                $requirements[$ingredientId] = [
                    'name' => (string)$line['name'],
                    'unit' => (string)($line['unit'] ?? ''),
                    'current_quantity' => (float)$line['current_quantity'],
                    'required_qty' => 0.0,
                ];
            }
            $requirements[$ingredientId]['required_qty'] += (float)$line['required_qty'];
        }
    }

    $shortages = [];
    foreach ($requirements as $requirement) {
        $requiredQty = (float)$requirement['required_qty'];
        $currentQty = (float)$requirement['current_quantity'];
        if ($currentQty + 0.0001 < $requiredQty) {
            $requirement['short_qty'] = round($requiredQty - $currentQty, 3);
            $shortages[] = $requirement;
        }
    }

    return $shortages;
}

/** Human-readable shortage summary, capped at three ingredients. */
function rh_stock_shortage_message(array $shortages, string $suffix = 'Receive stock, adjust the recipe, or 86 the item before marking it ready.'): string
{
    $parts = [];
    foreach (array_slice($shortages, 0, 3) as $shortage) {
        $unit = trim((string)($shortage['unit'] ?? ''));
        $parts[] = trim((string)$shortage['name'] . ' short by ' . number_format((float)$shortage['short_qty'], 3) . ($unit !== '' ? ' ' . $unit : ''));
    }

    $extra = count($shortages) > 3 ? ' and ' . (count($shortages) - 3) . ' more ingredient(s)' : '';
    return 'Stock check stopped this action: ' . implode(', ', $parts) . $extra . '. ' . $suffix;
}
