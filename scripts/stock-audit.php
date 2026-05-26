<?php
/**
 * Stock Audit Script — Rosalyn's Hotel
 * Read-only diagnostic checks against the live DB.
 * Run: php scripts/stock-audit.php
 *
 * Checks:
 *   1. Recipe coverage (food + drink menu items)
 *   2. stock_tracked accuracy (booking_charges)
 *   3. POS order restore integrity (cancelled/voided dine-in orders)
 *   4. Room service void restore integrity
 *   5. Stock adjustments balance (deductions vs restorations)
 *   6. Ingredients with negative current_quantity
 *   7. Pending reconciliation count
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pass = 0;
$warn = 0;
$fail = 0;

function ok(string $msg): void { global $pass; $pass++; echo "\033[32m  ✔ PASS\033[0m  $msg\n"; }
function warn(string $msg): void { global $warn; $warn++; echo "\033[33m  ⚠ WARN\033[0m  $msg\n"; }
function fail(string $msg): void { global $fail; $fail++; echo "\033[31m  ✘ FAIL\033[0m  $msg\n"; }

echo "\n\033[1m==== Rosalyn's Hotel — Stock Audit (" . date('Y-m-d H:i') . ") ====\033[0m\n\n";

// ── 1. Recipe coverage ────────────────────────────────────────────────────────
echo "\033[1m[1] Recipe Coverage\033[0m\n";

$foodTotal = (int)$pdo->query("SELECT COUNT(mi.id) FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mc.slug = 'food' AND mi.is_available = 1")->fetchColumn();
$drinkTotal = (int)$pdo->query("SELECT COUNT(mi.id) FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mc.slug = 'drink' AND mi.is_available = 1")->fetchColumn();

$foodWithRecipe = (int)$pdo->query("
    SELECT COUNT(DISTINCT mi.id) FROM menu_items mi
    JOIN menu_categories mc ON mc.id = mi.category_id
    INNER JOIN stock_recipes sr ON sr.menu_item_id = mi.id AND sr.menu_type = 'food'
    INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
    WHERE mc.slug = 'food' AND mi.is_available = 1
")->fetchColumn();

$drinkWithRecipe = (int)$pdo->query("
    SELECT COUNT(DISTINCT mi.id) FROM menu_items mi
    JOIN menu_categories mc ON mc.id = mi.category_id
    INNER JOIN stock_recipes sr ON sr.menu_item_id = mi.id AND sr.menu_type = 'drink'
    INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
    WHERE mc.slug = 'drink' AND mi.is_available = 1
")->fetchColumn();

$foodMissing = $foodTotal - $foodWithRecipe;
$drinkMissing = $drinkTotal - $drinkWithRecipe;

$foodPct = $foodTotal > 0 ? round($foodWithRecipe / $foodTotal * 100) : 0;
$drinkPct = $drinkTotal > 0 ? round($drinkWithRecipe / $drinkTotal * 100) : 0;

if ($foodMissing === 0) {
    ok("Food recipes: {$foodWithRecipe}/{$foodTotal} items have recipes (100%)");
} elseif ($foodPct >= 80) {
    warn("Food recipes: {$foodWithRecipe}/{$foodTotal} items have recipes ({$foodPct}%) — {$foodMissing} missing");
} else {
    fail("Food recipes: {$foodWithRecipe}/{$foodTotal} items have recipes ({$foodPct}%) — {$foodMissing} missing");
}

if ($drinkMissing === 0) {
    ok("Drink recipes: {$drinkWithRecipe}/{$drinkTotal} items have recipes (100%)");
} elseif ($drinkPct >= 80) {
    warn("Drink recipes: {$drinkWithRecipe}/{$drinkTotal} items have recipes ({$drinkPct}%) — {$drinkMissing} missing");
} else {
    fail("Drink recipes: {$drinkWithRecipe}/{$drinkTotal} items have recipes ({$drinkPct}%) — {$drinkMissing} missing");
}

// Show which food items are missing recipes
if ($foodMissing > 0) {
    $missingFood = $pdo->query("
        SELECT mi.id, mi.item_name, mi.category FROM menu_items mi
        JOIN menu_categories mc ON mc.id = mi.category_id
        WHERE mc.slug = 'food' AND mi.is_available = 1 AND mi.id NOT IN (
            SELECT DISTINCT sr.menu_item_id FROM stock_recipes sr
            INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
            WHERE sr.menu_type = 'food'
        ) ORDER BY mi.category, mi.item_name LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($missingFood as $r) {
        echo "           → Food [{$r['category']}] {$r['item_name']} (ID#{$r['id']})\n";
    }
    if ($foodMissing > 20) echo "           → ... and " . ($foodMissing - 20) . " more\n";
}

// Show which drink items are missing recipes
if ($drinkMissing > 0) {
    $missingDrink = $pdo->query("
        SELECT mi.id, mi.item_name, mi.category FROM menu_items mi
        JOIN menu_categories mc ON mc.id = mi.category_id
        WHERE mc.slug = 'drink' AND mi.is_available = 1 AND mi.id NOT IN (
            SELECT DISTINCT sr.menu_item_id FROM stock_recipes sr
            INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
            WHERE sr.menu_type = 'drink'
        ) ORDER BY mi.category, mi.item_name LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($missingDrink as $r) {
        echo "           → Drink [{$r['category']}] {$r['item_name']} (ID#{$r['id']})\n";
    }
    if ($drinkMissing > 20) echo "           → ... and " . ($drinkMissing - 20) . " more\n";
}
echo "\n";

// ── 2. stock_tracked accuracy (booking_charges) ───────────────────────────────
echo "\033[1m[2] stock_tracked Accuracy (Room Service / Folio Charges)\033[0m\n";

// Charges marked stock_tracked=1 but no stock_adjustments exist for them
$falsePositives = (int)$pdo->query("
    SELECT COUNT(*) FROM booking_charges bc
    WHERE bc.stock_tracked = 1
      AND bc.voided = 0
      AND bc.charge_type IN ('food','drink')
      AND bc.source_item_id IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM stock_adjustments sa
          WHERE sa.source_type = 'room_service' AND sa.source_id = bc.id AND sa.quantity_change < 0
      )
")->fetchColumn();

if ($falsePositives === 0) {
    ok("stock_tracked=1 accuracy: all tracked charges have matching deduction records");
} else {
    fail("stock_tracked false-positives: {$falsePositives} charge(s) marked stock_tracked=1 but no matching stock_adjustments found");
}

// Charges where stock_tracked=0 but recipe exists (should be tracked but isn't)
$shouldBeTracked = (int)$pdo->query("
    SELECT COUNT(*) FROM booking_charges bc
    WHERE bc.stock_tracked = 0
      AND bc.voided = 0
      AND bc.charge_type IN ('food','drink')
      AND bc.source_item_id IS NOT NULL
      AND EXISTS (
          SELECT 1 FROM stock_recipes sr
          INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
          WHERE sr.menu_item_id = bc.source_item_id AND sr.menu_type = bc.charge_type
      )
")->fetchColumn();

if ($shouldBeTracked === 0) {
    ok("No untracked charges that should have had stock deducted");
} else {
    warn("Untracked charges with recipes: {$shouldBeTracked} food/drink charge(s) have stock_tracked=0 but a recipe exists — these need reconciliation");
}

$pendingRecon = (int)$pdo->query("
    SELECT COUNT(*) FROM booking_charges
    WHERE stock_tracked = 0 AND voided = 0 AND charge_type IN ('food','drink') AND source_item_id IS NOT NULL
")->fetchColumn();
if ($pendingRecon === 0) {
    ok("Pending reconciliation: 0 untracked food/drink charges");
} else {
    warn("Pending reconciliation: {$pendingRecon} food/drink charge(s) with stock_tracked=0 (visible on stock dashboard)");
}
echo "\n";

// ── 3. POS order restore integrity ───────────────────────────────────────────
echo "\033[1m[3] POS Order Cancel/Void Restore Integrity\033[0m\n";

// Cancelled/voided POS orders (non-room-service) where stock was deducted but no restore exists
$unrestoredOrders = $pdo->query("
    SELECT so.id, so.reference, so.status, so.order_type,
           COUNT(soi.id) AS deducted_items
    FROM stock_orders so
    INNER JOIN stock_order_items soi ON soi.order_id = so.id AND soi.stock_deducted = 1
    WHERE so.status IN ('cancelled','voided')
      AND so.order_type NOT IN ('room_service')
      AND NOT EXISTS (
          SELECT 1 FROM stock_adjustments sa
          INNER JOIN stock_order_items soi2 ON soi2.order_id = so.id
          WHERE sa.source_type IN ('pos_order','void_restore')
            AND sa.source_id = soi2.id
            AND sa.quantity_change > 0
      )
      AND NOT EXISTS (
          SELECT 1 FROM stock_adjustments sa2
          WHERE sa2.source_type = 'void_restore'
            AND sa2.source_id = so.id
            AND sa2.quantity_change > 0
      )
    GROUP BY so.id
    ORDER BY so.id DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($unrestoredOrders)) {
    ok("POS void/cancel restore: all cancelled/voided orders have matching stock restorations");
} else {
    $cnt = count($unrestoredOrders);
    fail("POS void/cancel restore: {$cnt} cancelled/voided dine-in order(s) have deducted items but no stock restore found (historical data affected by the source_id bug)");
    foreach ($unrestoredOrders as $o) {
        echo "           → Order {$o['reference']} (ID#{$o['id']}, {$o['status']}, {$o['order_type']}) — {$o['deducted_items']} deducted item(s)\n";
    }
}
echo "\n";

// ── 4. Room service void restore integrity ───────────────────────────────────
echo "\033[1m[4] Room Service Void Restore Integrity\033[0m\n";

// Voided room service charges where stock was tracked but no restore exists
$unrestoredRoomService = (int)$pdo->query("
    SELECT COUNT(*) FROM booking_charges bc
    WHERE bc.voided = 1
      AND bc.stock_tracked = 1
      AND bc.charge_type IN ('food','drink')
      AND bc.source_item_id IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM stock_adjustments sa
          WHERE sa.source_type = 'void_restore'
            AND sa.source_id = bc.id
            AND sa.quantity_change > 0
      )
")->fetchColumn();

if ($unrestoredRoomService === 0) {
    ok("Room service void restore: all voided tracked charges have restoration records");
} else {
    fail("Room service void restore: {$unrestoredRoomService} voided charge(s) with stock_tracked=1 have no restore adjustment");
}
echo "\n";

// ── 5. Stock adjustments net balance ─────────────────────────────────────────
echo "\033[1m[5] Stock Adjustments Balance\033[0m\n";

$adjBalance = $pdo->query("
    SELECT
        COUNT(*) AS total_adjustments,
        SUM(CASE WHEN quantity_change < 0 THEN 1 ELSE 0 END) AS deductions,
        SUM(CASE WHEN quantity_change > 0 THEN 1 ELSE 0 END) AS additions,
        SUM(quantity_change) AS net_change
    FROM stock_adjustments
")->fetch(PDO::FETCH_ASSOC);

echo "           Total adjustments: {$adjBalance['total_adjustments']} | Deductions: {$adjBalance['deductions']} | Additions: {$adjBalance['additions']} | Net: " . round((float)$adjBalance['net_change'], 2) . " units\n";

$negativeIngredients = $pdo->query("
    SELECT si.id, si.name, si.current_quantity, si.unit FROM stock_ingredients si
    WHERE si.current_quantity < 0
    ORDER BY si.current_quantity ASC
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($negativeIngredients)) {
    ok("Negative stock: no ingredients currently in negative quantity");
} else {
    $cnt = count($negativeIngredients);
    warn("Negative stock: {$cnt} ingredient(s) with negative current_quantity");
    foreach ($negativeIngredients as $i) {
        echo "           → {$i['name']} (ID#{$i['id']}): {$i['current_quantity']} {$i['unit']}\n";
    }
}
echo "\n";

// ── 6. KDS stock_deducted accuracy ───────────────────────────────────────────
echo "\033[1m[6] KDS stock_deducted Flag Accuracy\033[0m\n";

// Items marked stock_deducted=1 but no matching adjustment exists (false positive flags)
// Accounts for three legitimate patterns:
//  (a) item-level source_id (current format: source_type='pos_order', source_id=item_id)
//  (b) order-level legacy source_id (source_type='pos_order', source_id=order_id)
//  (c) room service items where deduction is under source_type='room_service', source_id=charge_id
$kdsfalsePositives = (int)$pdo->query("
    SELECT COUNT(*) FROM stock_order_items soi
    JOIN stock_orders so ON so.id = soi.order_id
    WHERE soi.stock_deducted = 1
      AND soi.kds_status NOT IN ('void')
      AND soi.menu_item_id IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM stock_adjustments sa
          WHERE sa.source_type = 'pos_order' AND sa.source_id = soi.id AND sa.quantity_change < 0
      )
      AND NOT EXISTS (
          SELECT 1 FROM stock_adjustments sa2
          WHERE sa2.source_type = 'pos_order' AND sa2.source_id = so.id AND sa2.quantity_change < 0
      )
      AND NOT EXISTS (
          SELECT 1 FROM booking_charges bc
          JOIN stock_adjustments sa3 ON sa3.source_type = 'room_service' AND sa3.source_id = bc.id
          WHERE bc.stock_order_id = so.id AND sa3.quantity_change < 0
      )
      AND EXISTS (
          SELECT 1 FROM stock_recipes sr
          INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
          WHERE sr.menu_item_id = soi.menu_item_id AND sr.menu_type = soi.menu_type
      )
")->fetchColumn();

if ($kdsfalsePositives === 0) {
    ok("KDS stock_deducted flags: all deducted items with recipes have matching adjustment records");
} else {
    warn("KDS stock_deducted false-positives: {$kdsfalsePositives} item(s) flagged stock_deducted=1 with a recipe but no adjustment record (may be pre-migration data)");
}

// Items marked stock_deducted=0 but adjustment exists (should be flagged but isn't)
$kdsMissingFlag = (int)$pdo->query("
    SELECT COUNT(*) FROM stock_order_items soi
    WHERE soi.stock_deducted = 0
      AND soi.kds_status IN ('ready','collection','served')
      AND soi.menu_item_id IS NOT NULL
      AND EXISTS (
          SELECT 1 FROM stock_adjustments sa
          WHERE sa.source_type = 'pos_order' AND sa.source_id = soi.id AND sa.quantity_change < 0
      )
")->fetchColumn();

if ($kdsMissingFlag === 0) {
    ok("KDS stock_deducted sync: all served/ready items with adjustments are correctly flagged");
} else {
    warn("KDS flag desync: {$kdsMissingFlag} item(s) have stock adjustments but stock_deducted=0 — flag desync");
}
echo "\n";

// ── 7. General health ─────────────────────────────────────────────────────────
echo "\033[1m[7] General Stock Health\033[0m\n";

$expiredBatches = (int)$pdo->query("
    SELECT COUNT(*) FROM stock_batches
    WHERE status = 'active' AND expiry_date IS NOT NULL AND expiry_date < CURDATE()
")->fetchColumn();

if ($expiredBatches === 0) {
    ok("Expired batches: no active batches past their expiry date");
} else {
    warn("Expired batches: {$expiredBatches} active batch(es) past expiry date — should be marked expired");
}

$criticalStock = (int)$pdo->query("
    SELECT COUNT(*) FROM stock_ingredients
    WHERE is_archived = 0 AND min_quantity > 0 AND current_quantity <= min_quantity AND current_quantity > 0
")->fetchColumn();

if ($criticalStock === 0) {
    ok("Critical stock: no ingredients at or below critical level");
} else {
    warn("Critical stock: {$criticalStock} ingredient(s) at or below critical level");
}

$orphanedDeductions = (int)$pdo->query("
    SELECT COUNT(DISTINCT sa.id) FROM stock_adjustments sa
    LEFT JOIN stock_batch_deductions sbd ON sbd.adjustment_id = sa.id
    WHERE sa.source_type = 'pos_order'
      AND sa.quantity_change < 0
      AND sbd.id IS NULL
")->fetchColumn();

if ($orphanedDeductions === 0) {
    ok("Batch deduction records: all POS deduction adjustments have batch attribution");
} else {
    warn("Orphaned deductions: {$orphanedDeductions} POS deduction adjustment(s) with no stock_batch_deductions record (stock went negative — ingredient had no batches)");
}
echo "\n";

// ── Summary ───────────────────────────────────────────────────────────────────
echo "─────────────────────────────────────────────────\n";
echo "\033[1mSummary: \033[32m{$pass} PASS\033[0m\033[1m  \033[33m{$warn} WARN\033[0m\033[1m  \033[31m{$fail} FAIL\033[0m\n\n";
