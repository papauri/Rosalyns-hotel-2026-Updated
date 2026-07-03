<?php

/**
 * Product Management mode — the non-restaurant face of menu-management.php.
 *
 * Rendered instead of the Food/Drinks restaurant menu when the installation's
 * business preset has no food service (isRestaurantEnabled() === false):
 * gym, retail, supermarket, hotel-without-restaurant, conference venue.
 *
 * Operates directly on the POS catalog tables (menu_categories + menu_items),
 * so everything managed here is immediately sellable on the POS till,
 * including barcode scanning. New categories default to station 'bar'
 * (column is NOT NULL) and items to station NULL so they inherit it —
 * bar-stationed lines auto-serve at payment with stock deduction and never
 * fire kitchen/station tickets.
 *
 * Included by menu-management.php AFTER admin-init.php; expects:
 *   $pdo, $user, $csrf_token, $isAjax
 */

if (!defined('ADMIN_ACCESS')) {
    exit;
}

$pm_message = '';
$pm_error = '';
$pm_stock_on = function_exists('moduleEnabled') && moduleEnabled('stock');

$pm_json = static function (bool $ok, string $msg, array $extra = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
};

if (!function_exists('pm_syncProductStockLink')) {
    /**
     * Keep a retail product tied 1:1 to the stock ledger: product = stock item.
     * Ensures a matching stock_ingredients row (unit 'pcs'), a stock_recipes
     * row (menu_type = the product's category slug, which is exactly what the
     * POS passes to deductStockForMenuItem at payment) with a single line of
     * quantity 1 @ 100% yield, and registers the product's barcode in
     * stock_ingredient_barcodes (pack size 1) so Receive Stock scans book the
     * same unit IN that POS sales book OUT.
     *
     * Best-effort: never blocks the product save; no-op when the stock module
     * is off (e.g. Gym preset — POS without inventory).
     */
    function pm_syncProductStockLink(PDO $pdo, int $itemId, string $name, string $barcode, int $userId): void
    {
        if ($itemId <= 0 || !function_exists('moduleEnabled') || !moduleEnabled('stock')) {
            return;
        }
        try {
            $slugStmt = $pdo->prepare("SELECT mc.slug FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mi.id = ?");
            $slugStmt->execute([$itemId]);
            $slug = (string)$slugStmt->fetchColumn();
            if ($slug === '') {
                return;
            }

            // Existing link? (recipe for this item+slug with at least one line)
            $recStmt = $pdo->prepare("SELECT sr.id AS recipe_id, sri.ingredient_id
                                      FROM stock_recipes sr
                                      LEFT JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
                                      WHERE sr.menu_item_id = ? AND sr.menu_type = ?
                                      LIMIT 1");
            $recStmt->execute([$itemId, $slug]);
            $link = $recStmt->fetch(PDO::FETCH_ASSOC);

            $ingId = (int)($link['ingredient_id'] ?? 0);
            if ($ingId > 0) {
                // Keep the stock item's name in step with the product name.
                $pdo->prepare("UPDATE stock_ingredients SET name = ?, updated_at = NOW() WHERE id = ?")->execute([$name, $ingId]);
            } else {
                $findIng = $pdo->prepare("SELECT id FROM stock_ingredients WHERE name = ? AND is_archived = 0 LIMIT 1");
                $findIng->execute([$name]);
                $ingId = (int)$findIng->fetchColumn();
                if ($ingId <= 0) {
                    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, min_quantity, cost_per_unit) VALUES (?, 'Retail', 'pcs', 0, 0, 0)")->execute([$name]);
                    $ingId = (int)$pdo->lastInsertId();
                }
            }

            $recipeId = (int)($link['recipe_id'] ?? 0);
            if ($recipeId <= 0) {
                $pdo->prepare("INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe, notes, created_by) VALUES (?, ?, 1, 'Auto: 1 sold = 1 unit of stock', ?)")->execute([$itemId, $slug, $userId ?: null]);
                $recipeId = (int)$pdo->lastInsertId();
            }
            $lineChk = $pdo->prepare("SELECT COUNT(*) FROM stock_recipe_ingredients WHERE recipe_id = ?");
            $lineChk->execute([$recipeId]);
            if ((int)$lineChk->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent) VALUES (?, ?, 1, 100)")->execute([$recipeId, $ingId]);
            }

            if ($barcode !== '') {
                $bcChk = $pdo->prepare("SELECT ingredient_id FROM stock_ingredient_barcodes WHERE barcode = ?");
                $bcChk->execute([$barcode]);
                if ($bcChk->fetchColumn() === false) {
                    $pdo->prepare("INSERT INTO stock_ingredient_barcodes (barcode, ingredient_id, pack_size, pack_label, created_by) VALUES (?, ?, 1, 'Unit', ?)")->execute([$barcode, $ingId, $userId ?: null]);
                }
            }
        } catch (Throwable $e) {
            error_log('pm_syncProductStockLink: ' . $e->getMessage());
        }
    }
}

$pm_slugify = static function (string $name) use ($pdo): string {
    $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    if ($slug === '') {
        $slug = 'category';
    }
    $base = $slug;
    $n = 2;
    $chk = $pdo->prepare("SELECT COUNT(*) FROM menu_categories WHERE slug = ?");
    while (true) {
        $chk->execute([$slug]);
        if ((int)$chk->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '-' . $n++;
        if ($n > 50) {
            return $base . '-' . substr(uniqid(), -5);
        }
    }
};

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pm_action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $pm_json(false, 'Security token invalid — refresh the page.');
    }

    $action = (string)$_POST['pm_action'];

    try {
        if ($action === 'cat_save') {
            $catId = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $order = (int)($_POST['display_order'] ?? 0);
            if ($name === '' || mb_strlen($name) > 100) {
                $pm_json(false, 'Category name is required (max 100 characters).');
            }
            if ($catId > 0) {
                $stmt = $pdo->prepare("UPDATE menu_categories SET name = ?, display_order = ?, sort_order = ? WHERE id = ?");
                $stmt->execute([$name, $order, max(0, $order), $catId]);
                $pm_json(true, 'Category updated.');
            }
            $stmt = $pdo->prepare("INSERT INTO menu_categories (name, slug, business_context, description, icon, default_station, sort_order, shows_on_pos, shows_on_room_service, display_order, is_active) VALUES (?, ?, 'retail', '', 'fa-tag', 'bar', ?, 1, 0, ?, 1)");
            $stmt->execute([$name, $pm_slugify($name), max(0, $order), $order]);
            $pm_json(true, 'Category added.');
        }

        if ($action === 'cat_toggle') {
            $catId = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE menu_categories SET is_active = NOT is_active WHERE id = ?")->execute([$catId]);
            $pm_json(true, 'Category status updated.');
        }

        if ($action === 'item_save') {
            $itemId = (int)($_POST['id'] ?? 0);
            $catId = (int)($_POST['category_id'] ?? 0);
            $name = trim((string)($_POST['item_name'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $barcode = trim((string)($_POST['barcode'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $order = (int)($_POST['display_order'] ?? 0);

            if ($name === '' || mb_strlen($name) > 255) {
                $pm_json(false, 'Product name is required (max 255 characters).');
            }
            if ($price < 0 || $price > 99999999) {
                $pm_json(false, 'Price must be zero or a positive amount.');
            }
            $catStmt = $pdo->prepare("SELECT id, name FROM menu_categories WHERE id = ?");
            $catStmt->execute([$catId]);
            $cat = $catStmt->fetch(PDO::FETCH_ASSOC);
            if (!$cat) {
                $pm_json(false, 'Choose a valid category.');
            }
            if ($barcode !== '') {
                if (mb_strlen($barcode) > 100) {
                    $pm_json(false, 'Barcode is too long (max 100 characters).');
                }
                // Mirror pos.php's duplicate guard — one barcode maps to one product.
                $dup = $pdo->prepare("SELECT id, item_name FROM menu_items WHERE barcode = ? AND id != ?");
                $dup->execute([$barcode, $itemId]);
                if ($dupRow = $dup->fetch(PDO::FETCH_ASSOC)) {
                    $pm_json(false, 'Barcode already assigned to "' . $dupRow['item_name'] . '".');
                }
            }

            if ($itemId > 0) {
                $stmt = $pdo->prepare("UPDATE menu_items SET category_id = ?, category = ?, item_name = ?, price = ?, barcode = ?, description = ?, display_order = ? WHERE id = ?");
                $stmt->execute([$catId, $cat['name'], $name, $price, $barcode !== '' ? $barcode : null, $desc, max(0, $order), $itemId]);
                pm_syncProductStockLink($pdo, $itemId, $name, $barcode, (int)($user['id'] ?? 0));
                $pm_json(true, 'Product updated.');
            }
            $stmt = $pdo->prepare("INSERT INTO menu_items (category_id, category, item_name, price, barcode, description, is_available, display_order, station, show_pos, show_room_service) VALUES (?, ?, ?, ?, ?, ?, 1, ?, NULL, 1, 0)");
            $stmt->execute([$catId, $cat['name'], $name, $price, $barcode !== '' ? $barcode : null, $desc, max(0, $order)]);
            pm_syncProductStockLink($pdo, (int)$pdo->lastInsertId(), $name, $barcode, (int)($user['id'] ?? 0));
            $pm_json(true, 'Product added.');
        }

        if ($action === 'item_toggle') {
            $itemId = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE menu_items SET is_available = NOT is_available WHERE id = ?")->execute([$itemId]);
            $pm_json(true, 'Availability updated.');
        }

        if ($action === 'item_delete') {
            $itemId = (int)($_POST['id'] ?? 0);
            // Sold products stay for order history / recipe-cost joins — deactivate instead.
            $sold = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE menu_item_id = ?");
            $sold->execute([$itemId]);
            if ((int)$sold->fetchColumn() > 0) {
                $pdo->prepare("UPDATE menu_items SET is_available = 0 WHERE id = ?")->execute([$itemId]);
                $pm_json(false, 'This product has sales history so it cannot be deleted — it has been marked unavailable instead.');
            }
            $pdo->prepare("DELETE FROM menu_items WHERE id = ?")->execute([$itemId]);
            $pm_json(true, 'Product deleted.');
        }

        $pm_json(false, 'Unknown action.');
    } catch (PDOException $e) {
        error_log('product-management: ' . $e->getMessage());
        $pm_json(false, 'Database error — please try again.');
    }
}

// ── Data for the page ────────────────────────────────────────────────────────
$pm_categories = [];
$pm_items_by_cat = [];
try {
    // Only retail-context categories: this page manages the shop/gym/supermarket
    // catalog — restaurant Food/Drinks categories belong to the food-service mode.
    $pm_categories = $pdo->query("SELECT id, name, slug, icon, display_order, is_active FROM menu_categories WHERE COALESCE(business_context, 'food_service') = 'retail' ORDER BY is_active DESC, display_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    // stock_qty rides along via the 1:1 product↔stock link (recipe keyed on the
    // category slug); NULL when the stock module is off or the item is unlinked.
    $itemRows = $pdo->query("SELECT mi.id, mi.category_id, mi.item_name, mi.barcode, mi.description, mi.price, mi.is_available, mi.display_order,
                                    MAX(si.current_quantity) AS stock_qty, MAX(si.unit) AS stock_unit
                             FROM menu_items mi
                             JOIN menu_categories mc ON mc.id = mi.category_id
                             LEFT JOIN stock_recipes sr ON sr.menu_item_id = mi.id AND sr.menu_type = mc.slug
                             LEFT JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
                             LEFT JOIN stock_ingredients si ON si.id = sri.ingredient_id
                             WHERE COALESCE(mc.business_context, 'food_service') = 'retail'
                             GROUP BY mi.id
                             ORDER BY mi.display_order ASC, mi.item_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($itemRows as $r) {
        $pm_items_by_cat[(int)$r['category_id']][] = $r;
    }
} catch (PDOException $e) {
    $pm_error = 'Could not load the product catalog: ' . $e->getMessage();
}
$pm_currency = (string)getSetting('currency_symbol', 'K');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function() {
            var _t = '<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>';
            var _f = window.fetch;
            window.fetch = function(u, o) {
                if (o && o.body instanceof FormData && !o.body.has('csrf_token')) o.body.append('csrf_token', _t);
                return _f.apply(this, arguments);
            };
        })();
    </script>
    <title>Product Management - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/menu-management.css">
</head>

<body>
    <?php require_once __DIR__ . '/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title">Product Management</h2>
            <button class="btn-add" onclick="pmOpenCatModal()">
                <i class="fas fa-plus"></i> Add Category
            </button>
        </div>

        <p style="margin:0 0 18px;color:#7a6f63;font-size:.9rem;max-width:760px;">
            Products managed here are sold on the <strong>POS till</strong> — barcode scanning included.
            Group them into categories; the till shows one button per active category.
        </p>

        <?php if ($pm_error): ?>
            <?php showAlert($pm_error, 'error'); ?>
        <?php endif; ?>

        <?php if (empty($pm_categories)): ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <p>No product categories yet. Add your first category to start building the catalog.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($pm_categories as $cat):
            $catId = (int)$cat['id'];
            $items = $pm_items_by_cat[$catId] ?? [];
            $inactive = !(int)$cat['is_active'];
        ?>
            <div class="category-section" <?php echo $inactive ? 'style="opacity:.55;"' : ''; ?>>
                <div class="category-header">
                    <i class="fas <?php echo htmlspecialchars($cat['icon'] ?: 'fa-tag'); ?>"></i>
                    <?php echo htmlspecialchars($cat['name']); ?>
                    <span class="cat-count"><?php echo count($items); ?> product<?php echo count($items) === 1 ? '' : 's'; ?></span>
                    <?php if ($inactive): ?><span class="cat-count" style="background:#c0392b;color:#fff;">Inactive</span><?php endif; ?>
                    <span style="margin-left:auto;display:inline-flex;gap:8px;">
                        <button class="mm-btn mm-btn-sm" onclick="pmOpenItemModal(<?php echo $catId; ?>)"><i class="fas fa-plus"></i> Add Product</button>
                        <button class="mm-btn mm-btn-sm mm-btn-ghost" onclick="pmOpenCatModal(<?php echo htmlspecialchars(json_encode(['id' => $catId, 'name' => $cat['name'], 'display_order' => (int)$cat['display_order']]), ENT_QUOTES); ?>)"><i class="fas fa-pen"></i></button>
                        <button class="mm-btn mm-btn-sm mm-btn-ghost" onclick="pmCatToggle(<?php echo $catId; ?>)" title="<?php echo $inactive ? 'Reactivate' : 'Deactivate'; ?>"><i class="fas fa-power-off"></i></button>
                    </span>
                </div>
                <?php if (empty($items)): ?>
                    <div class="empty-state" style="padding:18px;"><p style="margin:0;">No products in this category yet.</p></div>
                <?php else: ?>
                    <table class="menu-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="width:120px;">Price (<?php echo htmlspecialchars($pm_currency); ?>)</th>
                                <?php if ($pm_stock_on): ?><th style="width:110px;" title="Live stock on hand — receive with Receive Stock, sales deduct automatically">In Stock</th><?php endif; ?>
                                <th style="width:160px;">Barcode</th>
                                <th>Description</th>
                                <th style="width:70px;">Order</th>
                                <th style="width:110px;">Available</th>
                                <th style="width:130px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $it): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($it['item_name']); ?></strong></td>
                                    <td><?php echo number_format((float)$it['price'], 2); ?></td>
                                    <?php if ($pm_stock_on): ?>
                                    <td>
                                        <?php if ($it['stock_qty'] !== null): $q = (float)$it['stock_qty']; ?>
                                            <a href="stock-barcode-receive.php" style="text-decoration:none;font-weight:600;color:<?php echo $q <= 0 ? '#9e4040' : ($q <= 5 ? '#b26a00' : '#2e7d32'); ?>;" title="Receive stock">
                                                <?php echo rtrim(rtrim(number_format($q, 2), '0'), '.'); ?> <?php echo htmlspecialchars((string)$it['stock_unit']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color:#b8b0a4;" title="Not linked to stock yet — re-save the product to link it">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <td><?php echo $it['barcode'] !== null && $it['barcode'] !== '' ? '<i class="fas fa-barcode" style="margin-right:5px;color:#8B7355;"></i>' . htmlspecialchars($it['barcode']) : '<span style="color:#b8b0a4;">—</span>'; ?></td>
                                    <td style="color:#7a6f63;font-size:.85rem;"><?php echo htmlspecialchars(mb_strimwidth((string)$it['description'], 0, 80, '…')); ?></td>
                                    <td><?php echo (int)$it['display_order']; ?></td>
                                    <td>
                                        <span style="font-weight:600;color:<?php echo (int)$it['is_available'] ? '#2e7d32' : '#9e4040'; ?>;">
                                            <i class="fas fa-<?php echo (int)$it['is_available'] ? 'check-circle' : 'times-circle'; ?>"></i>
                                            <?php echo (int)$it['is_available'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </td>
                                    <td class="actions-cell">
                                        <div class="action-buttons">
                                            <button class="btn-action" title="Edit"
                                                onclick="pmOpenItemModal(<?php echo $catId; ?>, <?php echo htmlspecialchars(json_encode(['id' => (int)$it['id'], 'item_name' => $it['item_name'], 'price' => (float)$it['price'], 'barcode' => (string)$it['barcode'], 'description' => (string)$it['description'], 'display_order' => (int)$it['display_order']]), ENT_QUOTES); ?>)"><i class="fas fa-pen"></i></button>
                                            <button class="btn-action btn-toggle <?php echo (int)$it['is_available'] ? 'active' : ''; ?>" title="Toggle availability"
                                                onclick="pmItemToggle(<?php echo (int)$it['id']; ?>)"><i class="fas fa-power-off"></i></button>
                                            <button class="btn-action btn-delete" title="Delete"
                                                onclick="pmConfirm('Delete &quot;<?php echo htmlspecialchars($it['item_name'], ENT_QUOTES); ?>&quot;? Products with sales history are deactivated instead.', function(){ pmItemDelete(<?php echo (int)$it['id']; ?>); })"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Category modal -->
    <div class="mm-modal" id="pmCatModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3 id="pmCatModalTitle">Add Category</h3>
                <button type="button" class="mm-modal-close" onclick="pmClose('pmCatModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body">
                <input type="hidden" id="pmCatId" value="0">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Category name</label>
                <input type="text" id="pmCatName" maxlength="100" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;margin-bottom:12px;" placeholder="e.g. Supplements">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Display order</label>
                <input type="number" id="pmCatOrder" value="0" min="0" style="width:120px;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
            </div>
            <div class="mm-modal-foot" style="display:flex;justify-content:flex-end;gap:10px;padding:14px 18px;">
                <button class="mm-btn mm-btn-ghost" onclick="pmClose('pmCatModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="pmCatSave()">Save Category</button>
            </div>
        </div>
    </div>

    <!-- Product modal -->
    <div class="mm-modal" id="pmItemModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3 id="pmItemModalTitle">Add Product</h3>
                <button type="button" class="mm-modal-close" onclick="pmClose('pmItemModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body">
                <input type="hidden" id="pmItemId" value="0">
                <input type="hidden" id="pmItemCat" value="0">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Product name</label>
                <input type="text" id="pmItemName" maxlength="255" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;margin-bottom:12px;">
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Price (<?php echo htmlspecialchars($pm_currency); ?>)</label>
                        <input type="number" id="pmItemPrice" min="0" step="0.01" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                    <div style="width:110px;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Order</label>
                        <input type="number" id="pmItemOrder" value="0" min="0" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                </div>
                <label style="display:block;font-weight:600;margin-bottom:4px;"><i class="fas fa-barcode"></i> Barcode <span style="font-weight:400;color:#9a8f82;">(optional — scan or type)</span></label>
                <input type="text" id="pmItemBarcode" maxlength="100" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;margin-bottom:12px;" placeholder="Scan with the barcode gun">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Description <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                <textarea id="pmItemDesc" rows="2" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;"></textarea>
            </div>
            <div class="mm-modal-foot" style="display:flex;justify-content:flex-end;gap:10px;padding:14px 18px;">
                <button class="mm-btn mm-btn-ghost" onclick="pmClose('pmItemModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="pmItemSave()">Save Product</button>
            </div>
        </div>
    </div>

    <!-- Confirm modal -->
    <div class="mm-modal" id="pmConfirmModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3><i class="fas fa-triangle-exclamation" style="color:#f59e0b;"></i> Are you sure?</h3>
                <button type="button" class="mm-modal-close" onclick="pmClose('pmConfirmModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body"><p id="pmConfirmText" style="margin:0;"></p></div>
            <div class="mm-modal-foot" style="display:flex;justify-content:flex-end;gap:10px;padding:14px 18px;">
                <button class="mm-btn mm-btn-ghost" onclick="pmClose('pmConfirmModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" id="pmConfirmYes" style="background:#c0392b;border-color:#c0392b;">Yes, continue</button>
            </div>
        </div>
    </div>

    <script>
        function pmOpen(id) { document.getElementById(id).classList.add('open'); }
        function pmClose(id) { document.getElementById(id).classList.remove('open'); }
        function pmToast(msg, ok) {
            if (typeof Alert !== 'undefined' && Alert.show) { Alert.show(msg, ok ? 'success' : 'error'); }
            else { window.alertFallback = msg; }
        }

        function pmPost(fields, doneReload) {
            var fd = new FormData();
            Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
            return fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    pmToast(d.message || (d.success ? 'Saved.' : 'Failed.'), !!d.success);
                    if (d.success && doneReload !== false) { setTimeout(function () { window.location.reload(); }, 700); }
                    return d;
                })
                .catch(function () { pmToast('Network error — please try again.', false); });
        }

        function pmOpenCatModal(cat) {
            document.getElementById('pmCatModalTitle').textContent = cat ? 'Edit Category' : 'Add Category';
            document.getElementById('pmCatId').value = cat ? cat.id : 0;
            document.getElementById('pmCatName').value = cat ? cat.name : '';
            document.getElementById('pmCatOrder').value = cat ? cat.display_order : 0;
            pmOpen('pmCatModal');
        }
        function pmCatSave() {
            var name = document.getElementById('pmCatName').value.trim();
            if (!name) { pmToast('Category name is required.', false); return; }
            pmPost({ pm_action: 'cat_save', id: document.getElementById('pmCatId').value, name: name, display_order: document.getElementById('pmCatOrder').value });
        }
        function pmCatToggle(id) { pmPost({ pm_action: 'cat_toggle', id: id }); }

        function pmOpenItemModal(catId, item) {
            document.getElementById('pmItemModalTitle').textContent = item ? 'Edit Product' : 'Add Product';
            document.getElementById('pmItemId').value = item ? item.id : 0;
            document.getElementById('pmItemCat').value = catId;
            document.getElementById('pmItemName').value = item ? item.item_name : '';
            document.getElementById('pmItemPrice').value = item ? item.price : '';
            document.getElementById('pmItemOrder').value = item ? item.display_order : 0;
            document.getElementById('pmItemBarcode').value = item ? item.barcode : '';
            document.getElementById('pmItemDesc').value = item ? item.description : '';
            pmOpen('pmItemModal');
            setTimeout(function () { document.getElementById('pmItemName').focus(); }, 60);
        }
        function pmItemSave() {
            var name = document.getElementById('pmItemName').value.trim();
            var price = document.getElementById('pmItemPrice').value;
            if (!name) { pmToast('Product name is required.', false); return; }
            if (price === '' || parseFloat(price) < 0) { pmToast('Enter a valid price.', false); return; }
            pmPost({
                pm_action: 'item_save',
                id: document.getElementById('pmItemId').value,
                category_id: document.getElementById('pmItemCat').value,
                item_name: name,
                price: price,
                barcode: document.getElementById('pmItemBarcode').value.trim(),
                description: document.getElementById('pmItemDesc').value.trim(),
                display_order: document.getElementById('pmItemOrder').value
            });
        }
        function pmItemToggle(id) { pmPost({ pm_action: 'item_toggle', id: id }); }
        function pmItemDelete(id) { pmPost({ pm_action: 'item_delete', id: id }); }

        var pmConfirmCb = null;
        function pmConfirm(text, cb) {
            document.getElementById('pmConfirmText').textContent = text;
            pmConfirmCb = cb;
            pmOpen('pmConfirmModal');
        }
        document.getElementById('pmConfirmYes').addEventListener('click', function () {
            pmClose('pmConfirmModal');
            if (pmConfirmCb) { pmConfirmCb(); pmConfirmCb = null; }
        });

        // Close modals when clicking the backdrop
        ['pmCatModal', 'pmItemModal', 'pmConfirmModal'].forEach(function (id) {
            var el = document.getElementById(id);
            el.addEventListener('click', function (e) { if (e.target === el) { pmClose(id); } });
        });
    </script>

    <?php require_once __DIR__ . '/admin-footer.php'; ?>
</body>

</html>
