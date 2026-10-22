<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Menu Management: portions of a stock item, the till copy of a menu item, search markup.
 * Usage (project root): php scripts/tests/menu_portions.php
 *
 *  1. Pure maths: 1 "each" of a pack-of-10 item is 1 base unit; 1 "Pack" is 10; decimals (0.150 kg) kept;
 *     a pack choice on an item with no pack size is refused; the plain preview sentence.
 *  2. In one rolled-back transaction: saving an item writes the till row and the stock link in base units;
 *     a sale (deductStockForMenuItem, what the till and kitchen screens call) takes 1 each (or 10 for a Pack
 *     line); cost per item and the "portions left" figure use the base quantity; a rename updates (never
 *     duplicates) the till row; a category move carries the stock link with it; delete / archive rules.
 *  3. The real page (page_harness.php, rolled back): add / edit / toggle / delete POSTs, price rules, duplicate
 *     name, CSRF, and the search markup on the rendered page.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';
require_once $root . '/includes/form-validation.php';
require_once $root . '/admin/includes/menu-recipe-lib.php';
require_once $root . '/admin/includes/menu-pos-sync.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function near($a, float $b): bool { return abs((float)$a - $b) <= 0.0005; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

/* ---- 1. Pure maths and wording ---- */
check(near(rh_recipe_qty_to_base(1, 'base', 10), 1.0), 'One each of a pack-of-10 item is 1 base unit');
check(near(rh_recipe_qty_to_base(1, 'pack', 10), 10.0), 'One Pack of 10 is 10 base units');
check(near(rh_recipe_qty_to_base(2.5, 'pack', 10), 25.0), 'Half packs are fine (2.5 packs = 25)');
check(near(rh_recipe_qty_to_base(0.150, 'base', null), 0.15), 'A weight like 0.150 kg is kept exactly');
check(near(rh_recipe_qty_to_base(0.05, 'pack', 24), 1.2), 'A part of a crate (0.05 of 24) converts to 1.2');
foreach ([[0, 'base', 10, 'zero'], [-1, 'base', 10, 'negative'], [1, 'pack', null, 'pack with no pack size'], [1, 'pack', 0.0, 'pack size 0'], [1, 'carton', 10, 'unknown unit'], [0.00001, 'base', null, 'too small to record']] as [$q, $u, $ps, $why]) {
    $threw = false;
    try { rh_recipe_qty_to_base((float)$q, $u, $ps); } catch (RuntimeException $e) { $threw = true; }
    check($threw, "Refused: $why");
}
check(rh_recipe_preview_text('Sausages', 'each', 'Pack', 10.0, 1.0) === 'Uses 1 each of Sausages (pack of 10) per item sold → 1 pack serves 10', 'Preview sentence: 1 each of a pack of 10', rh_recipe_preview_text('Sausages', 'each', 'Pack', 10.0, 1.0));
check(rh_recipe_preview_text('Sausages', 'each', 'Pack', 10.0, 10.0) === 'Uses 10 each of Sausages (pack of 10) per item sold → 1 pack serves 1', 'Preview sentence: a whole pack per item');
check(rh_recipe_preview_text('Rice', 'kg', null, null, 0.15) === 'Uses 0.15 kg of Rice per item sold', 'Preview sentence: no pack, decimal weight');
check(rh_recipe_preview_text('Beer', 'bottle', 'Crate', 24.0, 0.5) === 'Uses 0.5 bottle of Beer (crate of 24) per item sold → 1 crate serves 48', 'Preview sentence: crate serves 48');

/* ---- 2. Database behaviour, one rolled-back transaction ---- */
if (!ensureStockTablesExist()) { bad('Stock tables exist'); echo "\n$pass passed, $fail failed\n"; exit(1); }
$adminId = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1");
$run = strtoupper(bin2hex(random_bytes(3)));
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= 0.0005;
$yieldSql = rh_stock_yield_sql('sri.yield_percent');
try {
    $pdo->beginTransaction();
    $mkIng = static function (string $name, string $unit, float $qty, float $cost, ?string $packName, ?float $packSize, float $yield = 100.0) use ($pdo): int {
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, pack_name, pack_size, yield_percent) VALUES (?, 'ZZMP', ?, ?, ?, ?, ?, ?)")
            ->execute([$name, $unit, $qty, $cost, $packName, $packSize, $yield]);
        $id = (int)$pdo->lastInsertId();
        if ($qty > 0) {
            $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, status) VALUES (?, ?, ?, ?, ?, CURDATE(), 'active')")
                ->execute([$id, 'ZZMP-' . $id, $qty, $qty, $cost]);
        }
        return $id;
    };
    $sausage = $mkIng("ZZMP $run Sausages", 'each', 100, 1500, 'Pack', 10.0);
    $rice = $mkIng("ZZMP $run Rice", 'kg', 50, 2000, null, null);
    $packet = $mkIng("ZZMP $run Packet sausage", 'packet', 5, 15300, null, null);

    $web = static fn(string $name, string $cat, float $price = 1500.0): array => [
        'item_name' => $name, 'description' => '', 'price' => number_format($price, 2, '.', ''), 'category' => $cat, 'station' => 'kitchen',
        'is_available' => 1, 'display_order' => 1, 'show_pos' => 1, 'show_room_service' => 1, 'tags' => '',
    ];
    $catA = "ZZMP Snacks $run";
    $catB = "ZZMP Mains $run";

    // Create the till copy (food_menu is not needed for the helper itself).
    $pos = rh_menu_pos_sync_item($pdo, 'food', $web("ZZMP $run Bbq sausage", $catA), null, null);
    check($pos['created'] && $pos['id'] > 0 && $pos['slug'] !== '', 'Saving an item creates its till row and category');
    check((int)col($pdo, "SELECT COUNT(*) FROM menu_items WHERE id = ? AND show_pos = 1 AND is_available = 1 AND station = 'kitchen'", [$pos['id']]) === 1, 'Till row carries station, availability and where it shows');
    $again = rh_menu_pos_sync_item($pdo, 'food', $web("ZZMP $run Bbq sausage", $catA, 1600.0), null, null);
    check(!$again['created'] && $again['id'] === $pos['id'] && (int)col($pdo, "SELECT COUNT(*) FROM menu_items WHERE item_name = ?", ["ZZMP $run Bbq sausage"]) === 1, 'Saving again updates the same till row (no duplicate)');
    check($near(col($pdo, "SELECT price FROM menu_items WHERE id = ?", [$pos['id']]), 1600.0), 'A price change reaches the till row');

    // Switched off at the till (86): editing the price here must not switch it back on.
    $pdo->prepare("UPDATE menu_items SET is_available = 0 WHERE id = ?")->execute([$pos['id']]);
    rh_menu_pos_sync_item($pdo, 'food', ['is_available' => null] + $web("ZZMP $run Bbq sausage", $catA, 1700.0), null, null);
    check((int)col($pdo, "SELECT is_available FROM menu_items WHERE id = ?", [$pos['id']]) === 0 && $near(col($pdo, "SELECT price FROM menu_items WHERE id = ?", [$pos['id']]), 1700.0), 'An item switched off at the till stays off when only its price is edited');
    rh_menu_pos_sync_item($pdo, 'food', $web("ZZMP $run Bbq sausage", $catA, 1700.0), null, null);
    check((int)col($pdo, "SELECT is_available FROM menu_items WHERE id = ?", [$pos['id']]) === 1, 'Choosing Available here switches it back on');
    // Existing website items without a till row get one (insert-only, twice is a no-op).
    $bfRow = $web("ZZMP $run Backfilled", $catA);
    $pdo->prepare("INSERT INTO food_menu (item_name, price, category, station) VALUES (?, 10, ?, 'kitchen')")->execute([$bfRow['item_name'], $catA]);
    $made1 = rh_menu_pos_backfill($pdo, ['food' => [$bfRow], 'drink' => []]);
    $made2 = rh_menu_pos_backfill($pdo, ['food' => [$bfRow], 'drink' => []]);
    check($made1 === 1 && $made2 === 0 && (int)col($pdo, "SELECT COUNT(*) FROM menu_items WHERE item_name = ?", [$bfRow['item_name']]) === 1, 'A website item with no till row gets one, once (second run does nothing)');

    // Stock link: 1 each of a pack-of-10 item.
    $res = rh_menu_recipe_save($pdo, $pos['id'], $pos['slug'], null, null, [['ingredient_id' => $sausage, 'quantity' => 1, 'unit' => 'base']], $adminId);
    check($res['lines_written'] === 1 && $near(col($pdo, "SELECT quantity_per_portion FROM stock_recipe_ingredients WHERE recipe_id = ?", [$res['recipe_id']]), 1.0), 'Saved "1 each": stored as 1 base unit');
    $before = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$sausage]);
    $okSale = deductStockForMenuItem($pos['id'], $pos['slug'], 3.0, 'pos_order', 987654321, $adminId);
    $after = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$sausage]);
    check($okSale && $near($before - $after, 3.0), 'A sale of 3 takes 3 each off the shelf, not 3 packs', "before=$before after=$after");
    check($near(rh_menu_recipe_cost($pdo, $pos['id'], $pos['slug']), 1500.0), 'Cost per item = 1 each x 1,500 = 1,500');
    $snap = $pdo->prepare("SELECT MIN(FLOOR(GREATEST(0, i.current_quantity) / (sri.quantity_per_portion / $yieldSql))) FROM stock_recipes sr INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id INNER JOIN stock_ingredients i ON i.id = sri.ingredient_id WHERE sr.menu_item_id = ? AND sr.menu_type = ? AND sri.quantity_per_portion > 0");
    $snap->execute([$pos['id'], $pos['slug']]);
    check((int)$snap->fetchColumn() === 97, 'Portions left (till stock figure) counts each, not packs: 97 left', (string)$snap->fetchColumn());

    // Same item as a whole Pack per item.
    $res = rh_menu_recipe_save($pdo, $pos['id'], $pos['slug'], null, null, [['ingredient_id' => $sausage, 'quantity' => 1, 'unit' => 'pack']], $adminId);
    check($near(col($pdo, "SELECT quantity_per_portion FROM stock_recipe_ingredients WHERE recipe_id = ?", [$res['recipe_id']]), 10.0), 'Saved "1 Pack": stored as 10 base units');
    $before = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$sausage]);
    deductStockForMenuItem($pos['id'], $pos['slug'], 2.0, 'pos_order', 987654322, $adminId);
    $after = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$sausage]);
    check($near($before - $after, 20.0), 'A sale of 2 with a Pack line takes 20 each', "before=$before after=$after");
    check($near(rh_menu_recipe_cost($pdo, $pos['id'], $pos['slug']), 15000.0), 'Cost per item with a Pack line = 10 x 1,500 = 15,000');

    // Decimals and yield; a line that omits yield keeps the one the stock team set.
    $res = rh_menu_recipe_save($pdo, $pos['id'], $pos['slug'], null, null, [['ingredient_id' => $rice, 'quantity' => 0.150, 'unit' => 'base', 'yield_percent' => 50]], $adminId);
    check($near(rh_menu_recipe_cost($pdo, $pos['id'], $pos['slug']), 600.0), '0.150 kg at 2,000 with 50% yield costs 600');
    rh_menu_recipe_save($pdo, $pos['id'], $pos['slug'], null, null, [['ingredient_id' => $rice, 'quantity' => 0.2, 'unit' => 'base']], $adminId);
    check($near(col($pdo, "SELECT yield_percent FROM stock_recipe_ingredients WHERE recipe_id = ?", [$res['recipe_id']]), 50.0), 'Editing a quantity without yield keeps the line\'s yield (not reset to 100)');
    $lines = rh_menu_recipe_lines($pdo, $pos['id'], $pos['slug']);
    check(count($lines) === 1 && $near($lines[0]['quantity_per_portion'], 0.2), 'Lines read back in base units');
    $threw = false;
    try { rh_menu_recipe_save($pdo, $pos['id'], $pos['slug'], null, null, [['ingredient_id' => $rice, 'quantity' => 1, 'unit' => 'pack']], $adminId); } catch (RuntimeException $e) { $threw = true; }
    check($threw, 'A pack choice on an item with no pack size is refused on save');
    $threw = false;
    try { rh_menu_recipe_save($pdo, $pos['id'], $pos['slug'], null, null, [['ingredient_id' => $rice, 'quantity' => 1], ['ingredient_id' => $rice, 'quantity' => 2]], $adminId); } catch (InvalidArgumentException $e) { $threw = true; }
    check($threw, 'The same stock item twice is refused');
    check($near(col($pdo, "SELECT quantity_per_portion FROM stock_recipe_ingredients WHERE recipe_id = ?", [$res['recipe_id']]), 0.2), 'A refused save leaves the previous link untouched');

    // Counted in packets with no pack size: a fraction (1 sausage of 10 = 0.1 packet) is exact.
    rh_menu_recipe_save($pdo, $pos['id'], $pos['slug'], null, null, [['ingredient_id' => $packet, 'quantity' => 0.1, 'unit' => 'base']], $adminId);
    $b = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$packet]);
    deductStockForMenuItem($pos['id'], $pos['slug'], 1.0, 'pos_order', 987654323, $adminId);
    $a = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$packet]);
    check($near($b - $a, 0.1) && $near(rh_menu_recipe_cost($pdo, $pos['id'], $pos['slug']), 1530.0), 'An item counted in packets: 0.1 packet per sale, cost 1,530');

    // Portions / notes belong to Stock > Recipes: a save from here keeps them.
    $pdo->prepare("UPDATE stock_recipes SET portions_per_recipe = 4, notes = 'keep me' WHERE menu_item_id = ? AND menu_type = ?")->execute([$pos['id'], $pos['slug']]);
    rh_menu_recipe_save($pdo, $pos['id'], $pos['slug'], null, null, [['ingredient_id' => $packet, 'quantity' => 0.1]], $adminId);
    check((int)col($pdo, "SELECT portions_per_recipe FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?", [$pos['id'], $pos['slug']]) === 4
        && (string)col($pdo, "SELECT notes FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?", [$pos['id'], $pos['slug']]) === 'keep me', 'Saving from Menu Management keeps the recipe\'s portions and notes');

    // Category move is refused while the item is on an open order (that line keeps the old category slug,
    // so the till and kitchen screens would no longer find the stock link).
    $pdo->prepare("INSERT INTO stock_orders (reference, order_type, customer_name, status, total_amount, subtotal, kitchen_status, created_by, created_at) VALUES (?, 'walk_in', 'ZZMP', 'placed', 100, 100, 'new', ?, NOW())")->execute(["ZZMPM$run", $adminId]);
    $mvOid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status) VALUES (?, ?, ?, 'ZZMP move line', 1, 100, 100, 'kitchen', 'pending')")->execute([$mvOid, $pos['id'], $pos['slug']]);
    $refused = false;
    try { rh_menu_pos_sync_item($pdo, 'food', $web("ZZMP $run Bbq sausage", $catB, 1600.0), ['item_name' => "ZZMP $run Bbq sausage", 'category' => $catA], null); } catch (RuntimeException $e) { $refused = stripos($e->getMessage(), 'open order') !== false; }
    check($refused, 'A category move is refused while the item is on an open order (plain message)');
    check((int)col($pdo, "SELECT COUNT(*) FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?", [$pos['id'], $pos['slug']]) === 1, 'A refused move leaves the stock link under the old category');
    $sameCat = rh_menu_pos_sync_item($pdo, 'food', $web("ZZMP $run Bbq sausage", $catA, 1650.0), ['item_name' => "ZZMP $run Bbq sausage", 'category' => $catA], null);
    check($sameCat['id'] === $pos['id'] && $near(col($pdo, "SELECT price FROM menu_items WHERE id = ?", [$pos['id']]), 1650.0), 'Editing the price (same category) is still allowed with the item on an open order');
    $pdo->prepare("DELETE FROM stock_order_items WHERE order_id = ?")->execute([$mvOid]);
    $pdo->prepare("DELETE FROM stock_orders WHERE id = ?")->execute([$mvOid]);

    // Rename and category move.
    $moved = rh_menu_pos_sync_item($pdo, 'food', $web("ZZMP $run Bbq sausage stick", $catB, 1600.0), ['item_name' => "ZZMP $run Bbq sausage", 'category' => $catA], null);
    check(!$moved['created'] && $moved['id'] === $pos['id'], 'Rename + category change updates the same till row');
    check($moved['slug'] !== $pos['slug'] && (int)col($pdo, "SELECT COUNT(*) FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?", [$pos['id'], $moved['slug']]) === 1
        && (int)col($pdo, "SELECT COUNT(*) FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?", [$pos['id'], $pos['slug']]) === 0, 'The stock link moves with the category (the till finds it under the new category)');
    $b = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$packet]);
    deductStockForMenuItem($pos['id'], $moved['slug'], 1.0, 'pos_order', 987654324, $adminId);
    check($near($b - (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$packet]), 0.1), 'A sale after the move still takes stock off');
    $pos = $moved;

    // Barcode: unique across items.
    rh_menu_pos_sync_item($pdo, 'food', $web("ZZMP $run Bbq sausage stick", $catB, 1600.0), null, "ZZ$run" . '001');
    check(col($pdo, "SELECT barcode FROM menu_items WHERE id = ?", [$pos['id']]) === "ZZ{$run}001", 'Barcode saved (normalised)');
    $other = rh_menu_pos_sync_item($pdo, 'food', $web("ZZMP $run Second item", $catB), null, null);
    $threw = false;
    try { rh_menu_pos_sync_item($pdo, 'food', $web("ZZMP $run Second item", $catB), null, "zz-$run-001"); } catch (RuntimeException $e) { $threw = true; }
    check($threw, 'The same barcode on a second item is refused (any spelling)');
    check(col($pdo, "SELECT barcode FROM menu_items WHERE id = ?", [$other['id']]) === null, 'The second item stays without a barcode');

    // Backfill is strictly insert-only: a website row that only matches a till row by collation
    // (Cafe / Cafe with an accent) must not overwrite that till row's price, category or availability.
    $cafeTill = rh_menu_pos_sync_item($pdo, 'food', $web("ZZMP $run Cafe", $catA, 5.0), null, null);
    $pdo->prepare("UPDATE menu_items SET is_available = 0 WHERE id = ?")->execute([$cafeTill['id']]);
    $accented = $web("ZZMP $run Caf\u{e9}", $catA, 99.0);
    $made = rh_menu_pos_backfill($pdo, ['food' => [$accented], 'drink' => []]);
    $row = $pdo->prepare("SELECT price, is_available, category FROM menu_items WHERE id = ?");
    $row->execute([$cafeTill['id']]);
    $r0 = $row->fetch(PDO::FETCH_ASSOC);
    check($made === 0 && $near($r0['price'], 5.0) && (int)$r0['is_available'] === 0, 'Backfill never changes an existing till row (price and availability kept)', json_encode($r0));
    check((int)col($pdo, "SELECT COUNT(*) FROM menu_items WHERE item_name LIKE ?", ["ZZMP $run Caf%"]) === 1, 'Backfill adds no duplicate for a collation-equal name');

    // Delete rules.
    $open = rh_menu_pos_remove($pdo, $other['id'], $other['slug']);
    check($open['mode'] === 'deleted' && (int)col($pdo, "SELECT COUNT(*) FROM menu_items WHERE id = ?", [$other['id']]) === 0, 'An item never sold is deleted (till row gone)');
    $pdo->prepare("INSERT INTO stock_orders (reference, order_type, customer_name, status, total_amount, subtotal, kitchen_status, created_by, created_at) VALUES (?, 'walk_in', 'ZZMP', 'placed', 100, 100, 'new', ?, NOW())")->execute(["ZZMP$run", $adminId]);
    $oid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status) VALUES (?, ?, ?, 'ZZMP line', 1, 100, 100, 'kitchen', 'pending')")->execute([$oid, $pos['id'], $pos['slug']]);
    $r = rh_menu_pos_remove($pdo, $pos['id'], $pos['slug']);
    check($r['mode'] === 'blocked' && $r['open_lines'] === 1 && (int)col($pdo, "SELECT COUNT(*) FROM menu_items WHERE id = ?", [$pos['id']]) === 1, 'An item on an open order is not deleted');
    $pdo->prepare("UPDATE stock_orders SET status = 'paid' WHERE id = ?")->execute([$oid]);
    $r = rh_menu_pos_remove($pdo, $pos['id'], $pos['slug']);
    check($r['mode'] === 'archived' && (int)col($pdo, "SELECT COUNT(*) FROM menu_items WHERE id = ? AND is_available = 0 AND show_pos = 0 AND show_room_service = 0", [$pos['id']]) === 1, 'A sold item is archived (hidden from till and room service), not deleted');
    check((int)col($pdo, "SELECT COUNT(*) FROM stock_order_items WHERE menu_item_id = ?", [$pos['id']]) === 1, 'Past orders still read the item');
    check((int)col($pdo, "SELECT COUNT(*) FROM stock_order_items WHERE order_id = ? AND unit_price = 100", [$oid]) === 1, 'Order lines keep the price they sold at (a later price change does not touch them)');

    // "Pieces" entry: the page sends the base-unit part (pieces / pieces-in-a-packet); only that is stored.
    // 1 sausage of a 6-sausage packet = 1/6 packet. The column keeps 4 decimals, so 1/6 is stored as 0.1667.
    $col = (string)col($pdo, "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_recipe_ingredients' AND COLUMN_NAME = 'quantity_per_portion'");
    check($col === 'decimal(12,4)', 'Recipe quantity column is decimal(12,4) (4 decimals, the precision the pieces guide warns about)', $col);
    $pk6 = $mkIng("ZZMP $run Six pack", 'packet', 10, 15300, null, null);
    $dz = $mkIng("ZZMP $run Eggs", 'dozen', 10, 12000, null, null);
    $pc = rh_menu_pos_sync_item($pdo, 'food', $web("ZZMP $run Single sausage", $catA), null, null);
    $res = rh_menu_recipe_save($pdo, $pc['id'], $pc['slug'], null, null, [['ingredient_id' => $pk6, 'quantity' => 1 / 6, 'unit' => 'base']], $adminId);
    $stored = (float)col($pdo, "SELECT quantity_per_portion FROM stock_recipe_ingredients WHERE recipe_id = ?", [$res['recipe_id']]);
    check($near($stored, 0.1667), '1 piece of a 6-piece packet is stored as 1/6 packet (0.1667)', (string)$stored);
    $b = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$pk6]);
    for ($i = 0; $i < 6; $i++) { deductStockForMenuItem($pc['id'], $pc['slug'], 1.0, 'pos_order', 987655000 + $i, $adminId); }
    $a = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$pk6]);
    check(abs(($b - $a) - 1.0) <= 0.0005, '6 sales of the 1-piece item take about 1 packet off (4-decimal rounding: 1.0002)', 'took ' . ($b - $a));
    check($near(rh_menu_recipe_cost($pdo, $pc['id'], $pc['slug']), 15300 * 0.1667), 'Cost per piece = packet cost / 6 (2,550.51 at 4 decimals)', (string)rh_menu_recipe_cost($pdo, $pc['id'], $pc['slug']));
    check(abs(rh_menu_recipe_cost($pdo, $pc['id'], $pc['slug']) - 15300 / 6) < 1.0, 'Cost per piece is within 1 of the exact 15,300 / 6 = 2,550');
    $res = rh_menu_recipe_save($pdo, $pc['id'], $pc['slug'], null, null, [['ingredient_id' => $dz, 'quantity' => 1 / 12, 'unit' => 'base']], $adminId);
    $stored = (float)col($pdo, "SELECT quantity_per_portion FROM stock_recipe_ingredients WHERE recipe_id = ?", [$res['recipe_id']]);
    check($near($stored, 0.0833), '1 egg of a dozen is stored as 1/12 (0.0833)', (string)$stored);
    $b = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$dz]);
    for ($i = 0; $i < 12; $i++) { deductStockForMenuItem($pc['id'], $pc['slug'], 1.0, 'pos_order', 987655100 + $i, $adminId); }
    $a = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$dz]);
    check(abs(($b - $a) - 1.0) <= 0.0005, '12 sales of the 1-egg item take about 1 dozen off (0.9996)', 'took ' . ($b - $a));
    $res = rh_menu_recipe_save($pdo, $pc['id'], $pc['slug'], null, null, [['ingredient_id' => $pk6, 'quantity' => 0.5, 'unit' => 'base']], $adminId);
    check($near(col($pdo, "SELECT quantity_per_portion FROM stock_recipe_ingredients WHERE recipe_id = ?", [$res['recipe_id']]), 0.5), '3 pieces of a 6-piece packet is stored as 0.5 packet (exact)');
} catch (Throwable $e) {
    bad('In-process scenario', $e->getMessage() . ' @' . $e->getLine());
}
if ($pdo->inTransaction()) { $pdo->rollBack(); }
check((int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", ["ZZMP $run%"]) === 0 && (int)col($pdo, "SELECT COUNT(*) FROM menu_items WHERE item_name LIKE ?", ["ZZMP $run%"]) === 0, 'Rolled back: no test rows left behind');

/* ---- 3. The real page, rolled back by the harness ---- */
$harness = __DIR__ . '/page_harness.php';
$page = static function (array $post, array $setup = [], array $vars = [], array $verify = [], string $method = 'POST', array $grep = [], string $target = 'admin/menu-management.php') use ($harness): array {
    $cfg = base64_encode(json_encode(['post' => $post, 'ajax' => $method === 'POST', 'method' => $method, 'setup' => $setup, 'vars' => $vars, 'verify' => $verify, 'grep' => $grep]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($target) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr($out, -700), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $res = $x; } }
    return $res;
};
$clean = static fn(array $r): bool => !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']);
$hr = strtoupper(bin2hex(random_bytes(3)));
$ingSetup = [
    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, pack_name, pack_size) VALUES ('ZZMP $hr Sausages', 'ZZMP', 'each', 100, 1500, 'Pack', 10)",
    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES ('ZZMP $hr Rice', 'ZZMP', 'kg', 50, 2000)",
];
$ingVars = ['sau' => "SELECT id FROM stock_ingredients WHERE name = 'ZZMP $hr Sausages'", 'rice' => "SELECT id FROM stock_ingredients WHERE name = 'ZZMP $hr Rice'"];
$qty = static fn(array $r, int $i): float => (float)(($r['verify'][$i][0] ?? [])['q'] ?? -1);
$verifyAdd = [
    "SELECT COUNT(*) AS n FROM food_menu WHERE item_name = 'ZZMP $hr Item'",
    "SELECT COUNT(*) AS n FROM menu_items WHERE item_name = 'ZZMP $hr Item' AND price = 1500 AND show_pos = 1",
    "SELECT sri.quantity_per_portion AS q FROM stock_recipe_ingredients sri JOIN stock_recipes sr ON sr.id = sri.recipe_id JOIN menu_items mi ON mi.id = sr.menu_item_id JOIN menu_categories mc ON mc.id = mi.category_id AND mc.slug = sr.menu_type WHERE mi.item_name = 'ZZMP $hr Item' ORDER BY sri.id LIMIT 1",
    "SELECT sri.quantity_per_portion AS q FROM stock_recipe_ingredients sri JOIN stock_recipes sr ON sr.id = sri.recipe_id JOIN menu_items mi ON mi.id = sr.menu_item_id WHERE mi.item_name = 'ZZMP $hr Item' ORDER BY sri.id DESC LIMIT 1",
];
$basePost = ['action' => 'add', 'menu_type' => 'food', 'name' => "ZZMP $hr Item", 'category' => "ZZMP Cat $hr", 'price' => '1500', 'station' => 'kitchen', 'is_available' => '1', 'show_pos' => '1', 'show_room_service' => '1', 'description' => ''];

$r = $page($basePost + ['recipe_changed' => '1', 'recipe_lines' => json_encode([['ingredient_id' => '{sau}', 'quantity' => 1, 'unit' => 'base']])], $ingSetup, $ingVars, $verifyAdd);
check($clean($r) && ($r['json']['success'] ?? false) === true, 'Page: add item with "1 each" saves', json_encode($r['json'] ?? $r['raw'] ?? $r));
check((int)(($r['verify'][0][0] ?? [])['n'] ?? 0) === 1 && (int)(($r['verify'][1][0] ?? [])['n'] ?? 0) === 1, 'Page: one website row and one till row are written together', json_encode($r['verify'] ?? $r));
check($near($qty($r, 3), 1.0), 'Page: "1 each" is stored as 1 base unit', json_encode($r['verify'][3] ?? $r));

$r = $page($basePost + ['recipe_changed' => '1', 'recipe_lines' => json_encode([['ingredient_id' => '{sau}', 'quantity' => 1, 'unit' => 'pack']])], $ingSetup, $ingVars, $verifyAdd);
check($clean($r) && ($r['json']['success'] ?? false) === true && $near($qty($r, 3), 10.0), 'Page: "1 Pack (10 each)" is stored as 10 base units', json_encode($r['json'] ?? $r['verify'] ?? $r));

$r = $page($basePost + ['recipe_changed' => '1', 'recipe_lines' => json_encode([['ingredient_id' => '{sau}', 'quantity' => 1 / 6, 'unit' => 'base']])], $ingSetup, $ingVars, $verifyAdd);
check($clean($r) && ($r['json']['success'] ?? false) === true && $near($qty($r, 3), 0.1667), 'Page: "1 piece of 6" (sent as 1/6 of the unit) is stored as 0.1667', json_encode($r['json'] ?? $r['verify'] ?? $r));

$r = $page($basePost + ['recipe_changed' => '1', 'recipe_lines' => json_encode([['ingredient_id' => '{rice}', 'quantity' => 0.15, 'unit' => 'base']])], $ingSetup, $ingVars, $verifyAdd);
check($clean($r) && ($r['json']['success'] ?? false) === true && $near($qty($r, 3), 0.15), 'Page: 0.15 kg is stored as 0.15', json_encode($r['json'] ?? $r['verify'] ?? $r));

$r = $page($basePost + ['recipe_changed' => '1', 'recipe_lines' => json_encode([['ingredient_id' => '{rice}', 'quantity' => 1, 'unit' => 'pack']])], $ingSetup, $ingVars, $verifyAdd);
check($clean($r) && ($r['json']['success'] ?? true) === false && (int)(($r['verify'][0][0] ?? [])['n'] ?? 1) === 0, 'Page: a pack choice on an item with no pack size is refused and nothing is saved', json_encode($r['json'] ?? $r));

$r = $page($basePost, [], [], $verifyAdd);
check($clean($r) && ($r['json']['success'] ?? false) === true && (int)(($r['verify'][1][0] ?? [])['n'] ?? 0) === 1 && ($r['verify'][2] ?? []) === [], 'Page: an item with no stock link saves and creates no recipe (stock section untouched)', json_encode($r['json'] ?? $r));

$r = $page(array_merge($basePost, ['price' => '0']), [], [], $verifyAdd);
check($clean($r) && ($r['json']['success'] ?? true) === false && (int)(($r['verify'][0][0] ?? [])['n'] ?? 1) === 0, 'Page: price 0 without "free" is refused', json_encode($r['json'] ?? $r));
$r = $page(array_merge($basePost, ['price' => '0', 'free_item' => '1']), [], [], ["SELECT price AS q FROM menu_items WHERE item_name = 'ZZMP $hr Item'"]);
check($clean($r) && ($r['json']['success'] ?? false) === true && $near($qty($r, 0), 0.0), 'Page: a ticked free item saves at 0', json_encode($r['json'] ?? $r));
$r = $page(array_merge($basePost, ['price' => '-5']), [], [], $verifyAdd);
check($clean($r) && ($r['json']['success'] ?? true) === false, 'Page: a negative price is refused');
$r = $page(array_merge($basePost, ['name' => '']), [], [], $verifyAdd);
check($clean($r) && ($r['json']['success'] ?? true) === false, 'Page: a missing name is refused');
$r = $page(array_merge($basePost, ['csrf_token' => 'wrong']), [], [], $verifyAdd);
check($clean($r) && ($r['json']['success'] ?? true) === false && (int)(($r['verify'][0][0] ?? [])['n'] ?? 1) === 0, 'Page: a wrong security token saves nothing');

// Duplicate name in the same category, and the same barcode on another item.
$dupSetup = ["INSERT INTO food_menu (item_name, price, category, station) VALUES ('ZZMP $hr Item', 100, 'ZZMP Cat $hr', 'kitchen')"];
$r = $page($basePost, $dupSetup, [], $verifyAdd);
check($clean($r) && ($r['json']['success'] ?? true) === false && stripos((string)($r['json']['message'] ?? ''), 'already exists') !== false, 'Page: the same name in the same category is refused', json_encode($r['json'] ?? $r));
$bcSetup = [
    "INSERT INTO menu_categories (name, slug, business_context, default_station, sort_order, shows_on_pos, shows_on_room_service, display_order, is_active) VALUES ('ZZMP Cat $hr', 'zzmp-cat-" . strtolower($hr) . "', 'food_service', 'kitchen', 999, 1, 1, 999, 1)",
    "INSERT INTO menu_items (category_id, item_name, price, category, barcode, station) SELECT id, 'ZZMP $hr Other', 10, 'ZZMP Cat $hr', 'ZZBC$hr', 'kitchen' FROM menu_categories WHERE slug = 'zzmp-cat-" . strtolower($hr) . "'",
];
$r = $page($basePost + ['barcode' => "zz-bc-$hr"], $bcSetup, [], $verifyAdd);
check($clean($r) && ($r['json']['success'] ?? true) === false && stripos((string)($r['json']['message'] ?? ''), 'barcode') !== false && (int)(($r['verify'][0][0] ?? [])['n'] ?? 1) === 0, 'Page: a barcode already on another item is refused and nothing is saved', json_encode($r['json'] ?? $r));

// Toggle, delete (never sold, sold, on an open order) on seeded rows (website row + till row + recipe).
$seed = static function (string $tag) use ($hr): array {
    $slug = 'zzmp-' . strtolower($hr) . '-' . $tag;
    return [
        "INSERT INTO menu_categories (name, slug, business_context, default_station, sort_order, shows_on_pos, shows_on_room_service, display_order, is_active) VALUES ('ZZMP $tag $hr', '$slug', 'food_service', 'kitchen', 999, 1, 1, 999, 1)",
        "INSERT INTO food_menu (item_name, price, category, station) VALUES ('ZZMP $hr $tag', 100, 'ZZMP $tag $hr', 'kitchen')",
        "INSERT INTO menu_items (category_id, item_name, price, category, station) SELECT id, 'ZZMP $hr $tag', 100, 'ZZMP $tag $hr', 'kitchen' FROM menu_categories WHERE slug = '$slug'",
        "INSERT INTO stock_recipes (menu_item_id, menu_type) SELECT mi.id, mc.slug FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mc.slug = '$slug'",
        "INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion) SELECT sr.id, (SELECT id FROM stock_ingredients WHERE name = 'ZZMP $hr Sausages'), 1 FROM stock_recipes sr JOIN menu_categories mc ON mc.slug = sr.menu_type WHERE mc.slug = '$slug'",
    ];
};
$seedAll = array_merge($ingSetup, $seed('tog'));
$togVars = ['webid' => "SELECT id FROM food_menu WHERE item_name = 'ZZMP $hr tog'"];
$togVerify = ["SELECT is_available AS q FROM food_menu WHERE item_name = 'ZZMP $hr tog'", "SELECT is_available AS q FROM menu_items WHERE item_name = 'ZZMP $hr tog'"];
$r = $page(['action' => 'toggle_availability', 'menu_type' => 'food', 'id' => '{webid}'], $seedAll, $togVars, $togVerify);
check($clean($r) && ($r['json']['success'] ?? false) === true && $near($qty($r, 0), 0.0) && $near($qty($r, 1), 0.0), 'Page: marking unavailable hides it on the website menu AND the till', json_encode($r['json'] ?? $r) . json_encode($r['verify'] ?? ''));

$delVerify = ["SELECT COUNT(*) AS q FROM food_menu WHERE item_name = 'ZZMP $hr del'", "SELECT COUNT(*) AS q FROM menu_items WHERE item_name = 'ZZMP $hr del'", "SELECT COUNT(*) AS q FROM stock_recipes sr JOIN menu_categories mc ON mc.slug = sr.menu_type WHERE mc.name = 'ZZMP del $hr'", "SELECT is_available AS q FROM menu_items WHERE item_name = 'ZZMP $hr del'", "SELECT show_pos AS q FROM menu_items WHERE item_name = 'ZZMP $hr del'"];
$delVars = ['webid' => "SELECT id FROM food_menu WHERE item_name = 'ZZMP $hr del'"];
$delSetup = array_merge($ingSetup, $seed('del'));
$r = $page(['action' => 'delete', 'menu_type' => 'food', 'id' => '{webid}'], $delSetup, $delVars, $delVerify);
check($clean($r) && ($r['json']['success'] ?? false) === true && $near($qty($r, 0), 0) && $near($qty($r, 1), 0) && $near($qty($r, 2), 0), 'Page: deleting a never-sold item removes the website row, the till row and the stock link', json_encode($r['json'] ?? $r) . json_encode($r['verify'] ?? ''));

$soldSetup = array_merge($delSetup, [
    "INSERT INTO stock_orders (reference, order_type, customer_name, status, total_amount, subtotal, kitchen_status, created_by, created_at) VALUES ('ZZMP$hr', 'walk_in', 'ZZMP', 'paid', 100, 100, 'served', (SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1), NOW())",
    "INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status) SELECT (SELECT id FROM stock_orders WHERE reference = 'ZZMP$hr'), mi.id, mc.slug, mi.item_name, 1, 100, 100, 'kitchen', 'served' FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mi.item_name = 'ZZMP $hr del'",
]);
$r = $page(['action' => 'delete', 'menu_type' => 'food', 'id' => '{webid}'], $soldSetup, $delVars, $delVerify);
check($clean($r) && ($r['json']['success'] ?? false) === true && $near($qty($r, 0), 1) && $near($qty($r, 1), 1) && $near($qty($r, 3), 0) && $near($qty($r, 4), 0), 'Page: deleting a sold item hides it (unavailable, off the till) and keeps it for history', json_encode($r['json'] ?? $r) . json_encode($r['verify'] ?? ''));

$openSetup = $soldSetup;
$openSetup[count($openSetup) - 2] = "INSERT INTO stock_orders (reference, order_type, customer_name, status, total_amount, subtotal, kitchen_status, created_by, created_at) VALUES ('ZZMP$hr', 'walk_in', 'ZZMP', 'placed', 100, 100, 'new', (SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1), NOW())";
$r = $page(['action' => 'delete', 'menu_type' => 'food', 'id' => '{webid}'], $openSetup, $delVars, $delVerify);
check($clean($r) && ($r['json']['success'] ?? true) === false && $near($qty($r, 0), 1) && $near($qty($r, 1), 1), 'Page: an item on an open order cannot be deleted', json_encode($r['json'] ?? $r));

// Stock > Recipes saves under the item's real category slug even when the category is inactive / the posted type is wrong.
$inSlug = 'zzmp-off-' . strtolower($hr);
$recSetup = array_merge($ingSetup, [
    "INSERT INTO menu_categories (name, slug, business_context, default_station, sort_order, shows_on_pos, shows_on_room_service, display_order, is_active) VALUES ('ZZMP Off $hr', '$inSlug', 'food_service', 'kitchen', 999, 1, 1, 999, 0)",
    "INSERT INTO menu_items (category_id, item_name, price, category, station) SELECT id, 'ZZMP $hr Inactive', 10, 'ZZMP Off $hr', 'kitchen' FROM menu_categories WHERE slug = '$inSlug'",
]);
$recVars = $ingVars + ['mid' => "SELECT id FROM menu_items WHERE item_name = 'ZZMP $hr Inactive'"];
$r = $page(['action' => 'save_recipe', 'menu_item_id' => '{mid}', 'menu_type' => 'food', 'portions_per_recipe' => '1', 'ingredient_id' => [(string)(int)col($pdo, "SELECT id FROM stock_ingredients WHERE is_archived = 0 ORDER BY id LIMIT 1")], 'quantity_per_portion' => ['1'], 'yield_percent' => ['100']],
    $recSetup, $recVars, [
        "SELECT COUNT(*) AS q FROM stock_recipes sr JOIN menu_items mi ON mi.id = sr.menu_item_id WHERE mi.item_name = 'ZZMP $hr Inactive' AND sr.menu_type = '$inSlug'",
        "SELECT COUNT(*) AS q FROM stock_recipes sr JOIN menu_items mi ON mi.id = sr.menu_item_id WHERE mi.item_name = 'ZZMP $hr Inactive' AND sr.menu_type = 'food'",
    ], 'POST', [], 'admin/stock-recipes.php');
check($clean($r) && $near($qty($r, 0), 1) && $near($qty($r, 1), 0), 'Stock > Recipes: an item in an inactive category is saved under its real slug, not "food"', json_encode($r['verify'] ?? $r['raw'] ?? $r));

// Rendered page: search markup, filters, the stock section, the unit chooser, mobile-safe table labels.
$r = $page([], array_merge($ingSetup, ["INSERT INTO food_menu (item_name, price, category, station) VALUES ('ZZMP $hr Shown', 100, 'ZZMP Cat $hr', 'kitchen')"]), [], [], 'GET', ['id="mmSearch"', 'No items match', 'mmApplyFilters', 'data-search=', 'id="mmCatFilter"', 'What it uses from stock', 'id="mmIngUnit"', 'id="mmIngPer"', 'data-q="0.5"', 'function mmShowAsPieces', 'This item is free', 'data-label="Item"', 'aria-controls="mmResults"']);
check($clean($r) && !empty($r['found']) && !in_array(false, $r['found'], true), 'Page: search box, filters, "No items match" state, stock section and unit chooser are on the page', json_encode($r['found'] ?? $r['raw'] ?? $r));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
