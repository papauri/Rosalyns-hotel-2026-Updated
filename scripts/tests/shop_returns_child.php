<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/shop_returns.php (run by page_harness.php, rolled back, nothing
 * reaches the live DB). Builds its own ingredient + batch + recipe + paid till sale, deducts the stock the way
 * the till does, then voids / refunds it and reports what happened to the shelf and to the waste log.
 */
require_once $root . '/admin/includes/restaurant-payment-sync.php';
require_once $root . '/admin/includes/pos-order-close.php';
require_once $root . '/config/receipts.php';

$sc = (string)($_POST['scenario'] ?? '');
$adminId = (int)$_SESSION['admin_user_id'];
$out = ['scenario' => $sc];

$setShopMode = static function (bool $shop) use ($pdo): void {
    $v = $shop ? '0' : '1';
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('restaurant_system_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
    $GLOBALS['_SITE_SETTINGS']['restaurant_system_enabled'] = $v;
};

/** A paid shop sale of $qty units of one stocked product, auto-served at payment with its stock taken off. */
$fixture = static function (int $qty) use ($pdo, $adminId): array {
    $ref = 'SRT-' . random_int(100000, 999999);
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES ('SHOPRET FIXTURE', 'General', 'pcs', 10, 5)")->execute();
    $ing = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, status) VALUES (?, 'SHOPRET-B1', 10, 10, 5, CURDATE(), 'active')")->execute([$ing]);
    $menuItemId = random_int(2000000000, 2100000000);
    $pdo->prepare("INSERT INTO stock_recipes (menu_item_id, menu_type) VALUES (?, 'shopret')")->execute([$menuItemId]);
    $recipeId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion) VALUES (?, ?, 1)")->execute([$recipeId, $ing]);
    $total = 100.0 * $qty;
    $pdo->prepare("INSERT INTO stock_orders (reference, order_type, customer_name, status, total_amount, subtotal, kitchen_status, created_by, paid_by, paid_at, created_at)
                   VALUES (?, 'walk_in', 'Shop Test', 'paid', ?, ?, 'served', ?, ?, NOW(), NOW())")->execute([$ref, $total, $total, $adminId, $adminId]);
    $orderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status, started_at, ready_at, served_at, stock_deducted)
                   VALUES (?, ?, 'shopret', 'SHOPRET PRODUCT', ?, 100, ?, 'bar', 'served', NOW(), NOW(), NOW(), 0)")->execute([$orderId, $menuItemId, $qty, $total]);
    $lineId = (int)$pdo->lastInsertId();
    $ok = deductStockForMenuItem($menuItemId, 'shopret', (float)$qty, 'pos_order', $lineId, $adminId);
    if ($ok) {
        $pdo->prepare("UPDATE stock_order_items SET stock_deducted = 1 WHERE id = ?")->execute([$lineId]);
    }
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount, payment_method, payment_type, payment_status, status, receipt_number, created_at)
                   VALUES (?, 'restaurant', ?, ?, CURDATE(), ?, 0, 0, ?, 'cash', 'full_payment', 'completed', 'completed', ?, NOW())")->execute(['POS-' . $ref, $orderId, $ref, $total, $total, 'RSRT' . random_int(1000, 9999)]);
    return ['ref' => $ref, 'order_id' => $orderId, 'line_id' => $lineId, 'ing' => $ing, 'deducted' => $ok, 'item' => $menuItemId];
};
$shelf = static function (int $ing) use ($pdo): float {
    $s = $pdo->prepare("SELECT current_quantity FROM stock_ingredients WHERE id = ?");
    $s->execute([$ing]);
    return (float)$s->fetchColumn();
};
$wasteRows = static function (int $ing) use ($pdo): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM stock_wastage WHERE ingredient_id = ?");
    $s->execute([$ing]);
    return (int)$s->fetchColumn();
};

switch ($sc) {
    case 'void': // post: shop (0/1), damaged (0/1)
    case 'refund':
        $shop = ($_POST['shop'] ?? '0') === '1';
        $dmg = ($_POST['dmg'] ?? '0') === '1';
        $setShopMode($shop);
        $f = $fixture(2);
        $out['deducted'] = $f['deducted'];
        $out['shelf_after_sale'] = $shelf($f['ing']);
        $pdo->beginTransaction();
        if ($sc === 'void') {
            $r = rh_close_pos_order($pdo, $f['order_id'], $adminId, 'Shop return test', 'void', ['damaged' => $dmg]);
            $out['restored'] = $r['stock_restored'];
            $out['wasted'] = $r['stock_wasted'];
            $out['payments_reversed'] = $r['reversal']['rows'] ?? 0;
        } else {
            $r = rh_close_refunded_order_lines($pdo, $f['order_id'], $adminId, 'Shop refund test', ['damaged' => $dmg]);
            $out['restored'] = $r['restored'];
            $out['wasted'] = $r['wasted'];
        }
        $pdo->commit();
        $out['shelf_after'] = $shelf($f['ing']);
        $out['waste_rows'] = $wasteRows($f['ing']);
        $l = $pdo->prepare("SELECT stock_deducted, kds_status FROM stock_order_items WHERE id = ?");
        $l->execute([$f['line_id']]);
        $out['line'] = $l->fetch(PDO::FETCH_ASSOC);
        // Doing it a second time must not put the goods back twice.
        if ($sc === 'void') {
            $out['second_void_blocked'] = false;
            try {
                $pdo->beginTransaction();
                rh_close_pos_order($pdo, $f['order_id'], $adminId, 'Shop return test again', 'void', ['damaged' => $dmg]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $out['second_void_blocked'] = true;
            }
            $out['shelf_after_second'] = $shelf($f['ing']);
        }
        break;

    case 'endpoint': // the real api/void-order.php; post: shop, damaged
        $shop = ($_POST['shop'] ?? '0') === '1';
        $setShopMode($shop);
        $f = $fixture(2);
        $_POST['order_id'] = (string)$f['order_id'];
        $_POST['void_reason'] = 'Shop return endpoint test';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_REQUEST = $_POST;
        chdir($root . '/api');
        require $root . '/api/void-order.php'; // prints JSON and exits; the harness reads it
        break;

    case 'wording':
        $custom = trim((string)getSetting('restaurant_receipt_footer', ''));
        $out['exp_shop'] = ($custom === '' || $custom === 'Thank you for dining with us!') ? 'Thank you for shopping with us!' : $custom;
        $out['exp_rest'] = $custom !== '' ? $custom : 'Thank you for dining with us!';
        $setShopMode(true);
        $out['shop_footer'] = receipt_till_footer();
        $setShopMode(false);
        $out['restaurant_footer'] = receipt_till_footer();
        $order = ['reference' => 'ORD-X1', 'order_type' => 'walk_in', 'status' => 'paid', 'subtotal' => 300, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0, 'total_amount' => 300,
                  'paid_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'customer_name' => '', 'customer_email' => '', 'customer_phone' => '', 'payment_method' => 'cash',
                  'invoice_number' => '', 'table_number' => '', 'notes' => '', 'split_count' => 1, 'tip_amount' => 0, 'tendered_amount' => null, 'change_due' => null,
                  'mobile_wallet_reference' => '', 'mobile_wallet_provider' => '', 'card_last4' => '', 'card_auth_code' => '', 'discount_reason' => ''];
        $items = [['item_name' => 'Bread loaf', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200, 'notes' => ''], ['item_name' => 'Milk 1L', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'notes' => '']];
        $ctxFor = static function () {
            return ['currency' => 'MWK', 'site' => 'Test Shop', 'address' => '', 'phone' => '', 'email' => '', 'footer' => receipt_till_footer(), 'cashier' => 'Tester', 'split_legs' => [], 'receipt_numbers' => 'RCP-1', 'receipt_by_leg' => []];
        };
        foreach ([true, false] as $shop) {
            $setShopMode($shop);
            $k = $shop ? 'shop' : 'restaurant';
            $html = receipt_build_restaurant_email_html($order, $items, $ctxFor());
            $out[$k . '_email_title'] = strpos($html, 'RESTAURANT RECEIPT') !== false ? 'RESTAURANT RECEIPT' : (strpos($html, '>RECEIPT<') !== false ? 'RECEIPT' : '?');
            $out[$k . '_email_footer'] = strpos($html, receipt_h($shop ? $out['exp_shop'] : $out['exp_rest'])) !== false;
            $pay = ['booking_type' => 'restaurant', 'payment_reference' => 'POS-ORD-X1', 'booking_reference' => 'ORD-X1', 'payment_amount' => 300, 'vat_amount' => 0, 'total_amount' => 300, 'vat_rate' => 0,
                    'payment_type' => 'full_payment', 'payment_method' => 'cash', 'payment_status' => 'completed', 'receipt_number' => 'RCP-1', 'payment_date' => date('Y-m-d')];
            $ctx = ['guest_name' => 'Walk-in', 'description' => ($shop ? 'Sale ' : 'Restaurant order ') . 'ORD-X1', 'order_items' => $items, 'order_totals' => $order];
            $pdf = receipt_render_payment_pdf_html($pay, $ctx);
            $flat = strip_tags($pdf);
            $out[$k . '_pdf_itemised'] = strpos($flat, 'Bread loaf') !== false && strpos($flat, 'Milk 1L') !== false;
            $out[$k . '_pdf_lump'] = strpos($flat, 'Full Payment') !== false;
            // A split leg or an order whose lines do not add up keeps the single summary line.
            $leg = receipt_render_payment_pdf_html($pay, ['order_totals' => $order + [], 'order_items' => [['item_name' => 'Odd item', 'quantity' => 1, 'unit_price' => 50, 'line_total' => 50]]] + $ctx);
            $out[$k . '_pdf_mismatch_lump'] = strpos(strip_tags($leg), 'Odd item') === false && strpos(strip_tags($leg), 'Full Payment') !== false;
        }
        break;

    case 'vatmodes': // itemised payment receipt PDF is internally consistent in every VAT mode
        $setShopMode(true);
        $setVat = static function (string $enabled, string $mode): void {
            $GLOBALS['_SITE_SETTINGS']['vat_enabled'] = $enabled;
            $GLOBALS['_SITE_SETTINGS']['vat_rate'] = '16.5';
            $GLOBALS['_SITE_SETTINGS']['vat_pricing_mode'] = $mode;
        };
        $cur = (string)getSetting('currency_symbol', 'MWK');
        $m = static function (float $v) use ($cur): string { return rh_pdf_money($v, $cur); };
        $items = [];
        foreach (['Pen', 'Ink', 'Pad'] as $n) {
            $items[] = ['item_name' => $n, 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100];
        }
        $order = ['subtotal' => 300, 'discount_amount' => 0, 'service_charge' => 0, 'total_amount' => 300, 'split_count' => 1];
        $pay = ['booking_type' => 'restaurant', 'payment_reference' => 'POS-VATX', 'booking_reference' => 'VATX', 'payment_amount' => 257.51, 'vat_amount' => 42.49, 'total_amount' => 300, 'vat_rate' => 16.5,
                'payment_type' => 'full_payment', 'payment_method' => 'cash', 'payment_status' => 'completed', 'receipt_number' => 'RCP-V', 'payment_date' => date('Y-m-d')];
        $ctx = ['guest_name' => 'Walk-in', 'description' => 'Sale VATX', 'order_items' => $items, 'order_totals' => $order];

        $setVat('1', 'exclusive');
        $flat = strip_tags(receipt_render_payment_pdf_html($pay, $ctx));
        $out['excl_lines_net'] = substr_count($flat, $m(85.84)) >= 2 && strpos($flat, $m(85.83)) !== false && strpos($flat, $m(100)) === false; // 85.84+85.84+85.83 = 257.51
        $out['excl_vat_row'] = strpos($flat, 'VAT (16.5%)') !== false && strpos($flat, $m(42.49)) !== false;
        $out['excl_total'] = strpos($flat, $m(300)) !== false;
        // With a discount and service charge the net lines, discount and service still sum to the net.
        $order2 = ['subtotal' => 330, 'discount_amount' => 40, 'service_charge' => 10, 'total_amount' => 300, 'split_count' => 1];
        $items2 = [['item_name' => 'Pen', 'quantity' => 1, 'unit_price' => 110, 'line_total' => 110], ['item_name' => 'Ink', 'quantity' => 1, 'unit_price' => 110, 'line_total' => 110], ['item_name' => 'Pad', 'quantity' => 1, 'unit_price' => 110, 'line_total' => 110]];
        $flat2 = strip_tags(receipt_render_payment_pdf_html($pay, ['order_items' => $items2, 'order_totals' => $order2] + $ctx));
        // net lines 94.42 x3, discount 34.33, service 8.58 -> 257.51
        $out['excl_disc_rows'] = strpos($flat2, 'Discount') !== false && strpos($flat2, 'Service charge') !== false && strpos($flat2, 'Subtotal (net)') !== false && strpos($flat2, $m(257.51)) !== false && strpos($flat2, $m(110)) === false;
        // Net + VAT that does not equal the total keeps the single summary line.
        $bad = ['payment_amount' => 250.0] + $pay;
        $flatBad = strip_tags(receipt_render_payment_pdf_html($bad, $ctx));
        $out['excl_mismatch_lump'] = strpos($flatBad, 'Pen') === false && strpos($flatBad, 'Full Payment') !== false;

        $setVat('1', 'inclusive');
        $flatI = strip_tags(receipt_render_payment_pdf_html($pay, $ctx));
        $out['incl_lines_gross'] = substr_count($flatI, $m(100)) >= 3;
        $out['incl_vat_note'] = strpos($flatI, 'include VAT at 16.5%') !== false && strpos($flatI, 'Subtotal (net)') === false && strpos($flatI, $m(300)) !== false;

        $setVat('0', 'exclusive');
        $flatO = strip_tags(receipt_render_payment_pdf_html(['vat_amount' => 0, 'vat_rate' => 0, 'payment_amount' => 300] + $pay, $ctx));
        $out['off_plain'] = substr_count($flatO, $m(100)) >= 3 && strpos($flatO, 'VAT (') === false && strpos($flatO, 'include VAT') === false && strpos($flatO, 'Subtotal (net)') === false;
        break;
}
echo json_encode($out);
