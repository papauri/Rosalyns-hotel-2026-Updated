<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var string $csrf_token */
require_once '../includes/alert.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';
$current_page = basename($_SERVER['PHP_SELF']);
$current_tab = $_GET['tab'] ?? 'food';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Non-restaurant presets (gym, retail, supermarket…) manage the POS product
// catalog instead of a food/drinks menu — same page slot, different mode.
if (function_exists('isRestaurantEnabled') && !isRestaurantEnabled()) {
    require __DIR__ . '/includes/product-management-mode.php';
    exit;
}

// Facebook sharing
require_once '../includes/facebook-functions.php';
$fb_menu_posting_on = isFacebookPostingEnabled()
    && getSetting('facebook_menu_enabled', '1') === '1';

// Handle menu item actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security token invalid.']);
            exit;
        }
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
    try {
        $action = $_POST['action'] ?? '';
        $menu_type = $_POST['menu_type'] ?? 'food';

        if ($action === 'save_category_order') {
            $menu_type = $_POST['order_type'] ?? 'food';
            $order_setting = $menu_type === 'food' ? 'menu_food_categories_order' : 'menu_drink_categories_order';
            $categories = $_POST['categories'] ?? [];

            updateSetting($order_setting, json_encode($categories));

            // Clear cache
            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'Category order updated successfully!';
        } elseif ($action === 'reorder_items') {
            $table = $menu_type === 'food' ? 'food_menu' : 'drink_menu';
            $ids = $_POST['ids'] ?? [];
            $stmt = $pdo->prepare("UPDATE `{$table}` SET display_order = ? WHERE id = ?");
            foreach ($ids as $order => $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $stmt->execute([$order + 1, $id]);
                }
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true]);
            exit;
        } elseif ($action === 'add') {

            if ($menu_type === 'food') {
                // Add new food item - auto-increment display_order if not specified
                $category = $_POST['category'];
                $display_order = isset($_POST['display_order']) && $_POST['display_order'] !== '' ? (int)$_POST['display_order'] : null;

                if ($display_order === null) {
                    // Get next available display_order for this category
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 as next_order FROM food_menu WHERE category = ?");
                    $stmt->execute([$category]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $display_order = $result['next_order'];
                }

                $stmt = $pdo->prepare("
                    INSERT INTO food_menu (item_name, description, price, category, is_available, display_order, station, show_pos, show_room_service)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['price'],
                    $category,
                    isset($_POST['is_available']) ? 1 : 0,
                    $display_order,
                    in_array($_POST['station'] ?? 'kitchen', ['kitchen', 'bar', 'coffee_bar'], true) ? $_POST['station'] : 'kitchen',
                    isset($_POST['show_pos']) ? 1 : 0,
                    isset($_POST['show_room_service']) ? 1 : 0
                ]);
            } else {
                // Add new drink item - auto-increment item_order if not specified
                $category = $_POST['category'];
                $item_order = isset($_POST['item_order']) && $_POST['item_order'] !== '' ? (int)$_POST['item_order'] : null;

                if ($item_order === null) {
                    // Get next available item_order for this category
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 as next_order FROM drink_menu WHERE category = ?");
                    $stmt->execute([$category]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $item_order = $result['next_order'];
                }

                $stmt = $pdo->prepare("
                    INSERT INTO drink_menu (item_name, description, price, category, is_available, display_order, tags, station, show_pos, show_room_service)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['price'],
                    $category,
                    isset($_POST['is_available']) ? 1 : 0,
                    $item_order,
                    $_POST['tags'] ?? '',
                    in_array($_POST['station'] ?? 'bar', ['kitchen', 'bar', 'coffee_bar'], true) ? $_POST['station'] : 'bar',
                    isset($_POST['show_pos']) ? 1 : 0,
                    isset($_POST['show_room_service']) ? 1 : 0
                ]);
            }
            $message = 'Menu item added successfully!';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $message]);
                exit;
            }
        } elseif ($action === 'update') {
            if ($menu_type === 'food') {
                // Update existing food item
                $stmt = $pdo->prepare("
                    UPDATE food_menu
                    SET item_name = ?, description = ?, price = ?, category = ?, is_available = ?, display_order = ?, station = ?, show_pos = ?, show_room_service = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['price'],
                    $_POST['category'],
                    $_POST['is_available'] ?? 1,
                    $_POST['display_order'] ?? 0,
                    in_array($_POST['station'] ?? 'kitchen', ['kitchen', 'bar', 'coffee_bar'], true) ? $_POST['station'] : 'kitchen',
                    isset($_POST['show_pos']) ? 1 : 0,
                    isset($_POST['show_room_service']) ? 1 : 0,
                    $_POST['id']
                ]);
            } else {
                // Update existing drink item
                $stmt = $pdo->prepare("
                    UPDATE drink_menu
                    SET item_name = ?, description = ?, price = ?, category = ?, is_available = ?, display_order = ?, tags = ?, station = ?, show_pos = ?, show_room_service = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['price'],
                    $_POST['category'],
                    $_POST['is_available'] ?? 1,
                    $_POST['display_order'] ?? 0,
                    $_POST['tags'] ?? '',
                    in_array($_POST['station'] ?? 'bar', ['kitchen', 'bar', 'coffee_bar'], true) ? $_POST['station'] : 'bar',
                    isset($_POST['show_pos']) ? 1 : 0,
                    isset($_POST['show_room_service']) ? 1 : 0,
                    $_POST['id']
                ]);
            }
            $message = 'Menu item updated successfully!';
        } elseif ($action === 'delete') {
            $table = $_POST['menu_type'] === 'food' ? 'food_menu' : 'drink_menu';
            $recipeMenuType = $_POST['menu_type'] === 'food' ? 'food' : 'drink';
            $itemId = (int)$_POST['id'];

            // Recipe-aware delete: warn if a stock recipe is attached
            $hasRecipe = false;
            if (function_exists('ensureStockTablesExist') && ensureStockTablesExist()) {
                $rcheck = $pdo->prepare("SELECT COUNT(*) FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?");
                $rcheck->execute([$itemId, $recipeMenuType]);
                $hasRecipe = ((int)$rcheck->fetchColumn() > 0);
            }

            if ($hasRecipe && empty($_POST['force_delete'])) {
                $error = 'This menu item has a stock recipe. Re-submit with confirmation to delete the item AND its recipe.';
            } else {
                $pdo->beginTransaction();
                if ($hasRecipe) {
                    // Cascading FK on stock_recipes will remove ingredients automatically
                    $delR = $pdo->prepare("DELETE FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?");
                    $delR->execute([$itemId, $recipeMenuType]);
                }
                $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
                $stmt->execute([$itemId]);
                $pdo->commit();
                $message = 'Menu item deleted successfully!' . ($hasRecipe ? ' (Recipe also removed.)' : '');
            }
        } elseif ($action === 'toggle_availability') {
            $table = $_POST['menu_type'] === 'food' ? 'food_menu' : 'drink_menu';
            $field = 'is_available';
            $stmt = $pdo->prepare("UPDATE $table SET $field = NOT $field WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Menu item availability updated!';
        }
    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    }
}

// Fetch all menu items grouped by category
try {
    // Simple approach: just use food_menu table
    $stmt = $pdo->query("
        SELECT * FROM food_menu
        ORDER BY category ASC, display_order ASC, item_name ASC
    ");
    $food_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch drink items from drink_menu
    $stmt = $pdo->query("
        SELECT * FROM drink_menu
        ORDER BY category, display_order ASC, item_name ASC
    ");
    $drink_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group food items by category
    $grouped_food = [];
    $food_categories = [];
    foreach ($food_items as $item) {
        $grouped_food[$item['category']][] = $item;
        if (!in_array($item['category'], $food_categories)) {
            $food_categories[] = $item['category'];
        }
    }
    // Always include these standard food categories even if empty
    $default_food_categories = ['Breakfast', 'Lunch', 'Dinner', 'Snacks', 'Room Service'];
    $food_categories = array_unique(array_merge($default_food_categories, $food_categories));
    sort($food_categories);

    // Group drink items by category
    $grouped_drinks = [];
    $drink_categories = [];
    foreach ($drink_items as $item) {
        $grouped_drinks[$item['category']][] = $item;
        if (!in_array($item['category'], $drink_categories)) {
            $drink_categories[] = $item['category'];
        }
    }
    // Always include these standard drink categories even if empty
    $default_drink_categories = ['Coffee', 'Tea', 'Juice', 'Cocktails', 'Mocktails', 'Beer', 'Wine', 'Spirits', 'Soft Drinks', 'Room Service'];
    $drink_categories = array_unique(array_merge($default_drink_categories, $drink_categories));
    sort($drink_categories);
} catch (PDOException $e) {
    $error = 'Error fetching menu items: ' . $e->getMessage();
    $food_categories = [];
    $grouped_food = [];
    $grouped_drinks = [];
    $drink_categories = [];
}

// Precompute which menu items have a stock recipe (drives the Recipe button state)
$recipeMap = ['food' => [], 'drink' => []];
$stockReady = function_exists('ensureStockTablesExist') && ensureStockTablesExist();
if ($stockReady) {
    try {
        $rows = $pdo->query("SELECT menu_item_id, menu_type FROM stock_recipes")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $t = $r['menu_type'] === 'drink' ? 'drink' : 'food';
            $recipeMap[$t][(int)$r['menu_item_id']] = true;
        }
    } catch (Throwable $e) {
        // Non-fatal — recipe markers simply won't show
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
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
    <title>Menu Management - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/menu-management.css?v=<?php echo @filemtime(__DIR__ . '/css/menu-management.css'); ?>">
    <link rel="stylesheet" href="css/facebook-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/facebook-settings.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title">Menu Management</h2>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <?php if ($fb_menu_posting_on): ?>
            <!-- Share Full Menu on Facebook banner -->
            <div style="margin-bottom:18px;border-radius:12px;background:linear-gradient(135deg,#1877F2 0%,#0d47a1 100%);color:#fff;padding:18px 24px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;box-shadow:0 4px 16px rgba(24,119,242,0.25);">
                <div style="width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,0.18);display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;">
                    <i class="fab fa-facebook-f"></i>
                </div>
                <div style="flex:1;min-width:180px;">
                    <div style="font-weight:700;font-size:1rem;margin-bottom:3px;">Share Our Restaurant Menu on Facebook</div>
                    <div style="font-size:0.82rem;opacity:0.82;line-height:1.4;">Post a promotional update featuring your food &amp; drink highlights to your Facebook Page.</div>
                </div>
                <button type="button"
                    style="background:rgba(255,255,255,0.15);color:#fff;border:2px solid rgba(255,255,255,0.55);border-radius:8px;padding:10px 22px;font-family:inherit;font-size:0.9rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:background 0.18s,border-color 0.18s;white-space:nowrap;"
                    onclick="openFbMenuShareAllModal()">
                    <i class="fab fa-facebook-f"></i> Share Full Menu
                </button>
            </div>
        <?php endif; ?>

        <!-- Menu Type Tabs -->
        <div class="menu-type-tabs">
            <button class="menu-type-tab <?php echo $current_tab === 'food' ? 'active' : ''; ?>" onclick="switchTab('food')">
                <i class="fas fa-utensils"></i> Food Menu
            </button>
            <button class="menu-type-tab <?php echo $current_tab === 'drinks' ? 'active' : ''; ?>" onclick="switchTab('drinks')">
                <i class="fas fa-glass-martini-alt"></i> Drinks Menu
            </button>
            <button class="menu-type-tab menu-type-tab--rs" onclick="jumpToRoomService()" title="Jump to Room Service items in Food &amp; Drinks">
                <i class="fas fa-concierge-bell"></i> Room Service
            </button>
        </div>

        <!-- Food Menu Tab Content -->
        <div class="tab-content <?php echo $current_tab === 'food' ? 'active' : ''; ?>" id="food-tab">
            <div class="page-header">
                <h3 class="page-title">Food Items</h3>
                <button class="btn-add" onclick="openAddModal('food')">
                    <i class="fas fa-plus"></i> Add Food Item
                </button>
            </div>

            <?php foreach ($food_categories as $category): ?>
                <div class="category-section" id="food-cat-<?php echo preg_replace('/[^a-z0-9]+/', '-', strtolower($category)); ?>">
                    <h3 class="category-header">
                        <span>
                            <i class="fas fa-<?php
                                                echo $category === 'Breakfast' ? 'coffee' : ($category === 'Lunch' ? 'hamburger' : ($category === 'Dinner' ? 'drumstick-bite' : ($category === 'Room Service' ? 'concierge-bell' : ($category === 'Snacks' ? 'cookie-bite' : 'utensils'))));
                                                ?>"></i>
                            <?php echo $category; ?>
                            <?php if (isset($grouped_food[$category])): ?>
                                <span class="cat-count">(<?php echo count($grouped_food[$category]); ?> items)</span>
                            <?php endif; ?>
                        </span>
                        <button class="mm-btn mm-btn-sm" onclick="openAddModal('food', '<?php echo htmlspecialchars($category); ?>')">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </h3>

                    <?php if (isset($grouped_food[$category]) && !empty($grouped_food[$category])): ?>
                        <div class="table-responsive">
                            <table class="menu-table">
                                <thead>
                                    <tr>
                                        <th class="drag-col" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></th>
                                        <th style="width: 250px;">Item Name</th>
                                        <th style="width: 350px;">Description</th>
                                        <th style="width: 150px;">Price (<?php echo htmlspecialchars(getSetting('currency_symbol')); ?>)</th>
                                        <th style="width: 200px;">Routing</th>
                                        <th style="width: 150px;">Status</th>
                                        <th style="width: 300px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grouped_food[$category] as $item): ?>
                                        <tr id="food-row-<?php echo $item['id']; ?>" data-category="<?php echo htmlspecialchars($item['category']); ?>">
                                            <td class="drag-col">
                                                <span class="drag-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>
                                                <input type="hidden" value="<?php echo (int)$item['display_order']; ?>" data-field="display_order">
                                            </td>
                                            <td>
                                                <input type="text" value="<?php echo htmlspecialchars($item['item_name']); ?>" data-field="name">
                                            </td>
                                            <td>
                                                <textarea data-field="description"><?php echo htmlspecialchars($item['description'] ?? ''); ?></textarea>
                                            </td>
                                            <td>
                                                <input type="number" value="<?php echo $item['price']; ?>" step="0.01" data-field="price">
                                            </td>
                                            <td>
                                                <select data-field="station" class="menu-routing-select">
                                                    <option value="kitchen" <?php echo (($item['station'] ?? 'kitchen') === 'kitchen')    ? 'selected' : ''; ?>>Kitchen</option>
                                                    <option value="bar" <?php echo (($item['station'] ?? '') === 'bar')                  ? 'selected' : ''; ?>>Bar</option>
                                                    <option value="coffee_bar" <?php echo (($item['station'] ?? '') === 'coffee_bar')           ? 'selected' : ''; ?>>Coffee Bar</option>
                                                </select>
                                                <div class="menu-routing-checks">
                                                    <label><input type="checkbox" data-field="show_pos" <?php echo !isset($item['show_pos']) || $item['show_pos'] ? 'checked' : ''; ?>> POS</label>
                                                    <label><input type="checkbox" data-field="show_room_service" <?php echo !isset($item['show_room_service']) || $item['show_room_service'] ? 'checked' : ''; ?>> Room Service</label>
                                                </div>
                                            </td>
                                            <td>
                                                <select data-field="is_available">
                                                    <option value="1" <?php echo $item['is_available'] ? 'selected' : ''; ?>>Available</option>
                                                    <option value="0" <?php echo !$item['is_available'] ? 'selected' : ''; ?>>Unavailable</option>
                                                </select>
                                            </td>
                                            <td class="actions-cell">
                                                <div class="action-buttons">
                                                    <button class="btn-action btn-save"
                                                        onclick="saveRow(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['category']); ?>', 'food')"
                                                        title="Save Changes">
                                                        <i class="fas fa-save"></i> Save
                                                    </button>
                                                    <button class="btn-action btn-toggle <?php echo $item['is_available'] ? 'active' : ''; ?>"
                                                        onclick="quickToggle(<?php echo $item['id']; ?>, 'food')"
                                                        title="<?php echo $item['is_available'] ? 'Mark as Unavailable' : 'Mark as Available'; ?>">
                                                        <i class="fas fa-toggle-<?php echo $item['is_available'] ? 'on' : 'off'; ?>"></i> Toggle
                                                    </button>
                                                    <button class="btn-action btn-recipe<?php echo isset($recipeMap['food'][(int)$item['id']]) ? ' has-recipe' : ''; ?>"
                                                        onclick="openRecipeModal(<?php echo (int)$item['id']; ?>, 'food', '<?php echo htmlspecialchars($item['item_name'], ENT_QUOTES); ?>')"
                                                        title="Manage stock recipe">
                                                        <i class="fas fa-flask"></i> Recipe
                                                    </button>
                                                    <button class="btn-action btn-delete"
                                                        onclick="mmConfirmDelete(<?php echo $item['id']; ?>, 'food')"
                                                        title="Delete Item">
                                                        <i class="fas fa-trash-alt"></i> Delete
                                                    </button>
                                                    <?php if ($fb_menu_posting_on): ?>
                                                        <button class="btn-action btn-facebook"
                                                            onclick='openFbMenuModal(<?php echo (int)$item["id"]; ?>, <?php echo htmlspecialchars(json_encode($item["item_name"]), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode((float)$item["price"]), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($item["category"] ?? ""), ENT_QUOTES, "UTF-8"); ?>, "food")'
                                                            title="Feature on Facebook">
                                                            <i class="fab fa-facebook-f"></i> Feature
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No items in this category yet. Click "Add Food Item" to get started.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Drinks Menu Tab Content -->
        <div class="tab-content <?php echo $current_tab === 'drinks' ? 'active' : ''; ?>" id="drinks-tab">
            <div class="page-header">
                <h3 class="page-title">Drinks Items</h3>
                <button class="btn-add" onclick="openAddModal('drinks')">
                    <i class="fas fa-plus"></i> Add Drink Item
                </button>
            </div>

            <?php foreach ($drink_categories as $category): ?>
                <div class="category-section" id="drinks-cat-<?php echo preg_replace('/[^a-z0-9]+/', '-', strtolower($category)); ?>">
                    <h3 class="category-header">
                        <span>
                            <i class="fas fa-<?php
                                                echo $category === 'Coffee' ? 'coffee' : ($category === 'Wine' ? 'wine-bottle' : ($category === 'Cocktails' ? 'glass-martini-alt' : ($category === 'Beer' ? 'beer' : 'glass-martini-alt')));
                                                ?>"></i>
                            <?php echo $category; ?>
                            <?php if (isset($grouped_drinks[$category])): ?>
                                <span class="cat-count">(<?php echo count($grouped_drinks[$category]); ?> items)</span>
                            <?php endif; ?>
                        </span>
                        <button class="mm-btn mm-btn-sm" onclick="openAddModal('drinks', '<?php echo htmlspecialchars($category); ?>')">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </h3>

                    <?php if (isset($grouped_drinks[$category]) && !empty($grouped_drinks[$category])): ?>
                        <div class="table-responsive">
                            <table class="menu-table">
                                <thead>
                                    <tr>
                                        <th class="drag-col" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></th>
                                        <th style="width: 250px;">Item Name</th>
                                        <th style="width: 300px;">Description</th>
                                        <th style="width: 150px;">Price (<?php echo htmlspecialchars(getSetting('currency_symbol')); ?>)</th>
                                        <th style="width: 150px;">Tags</th>
                                        <th style="width: 200px;">Routing</th>
                                        <th style="width: 150px;">Status</th>
                                        <th style="width: 300px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grouped_drinks[$category] as $item): ?>
                                        <tr id="drink-row-<?php echo $item['id']; ?>" data-category="<?php echo htmlspecialchars($item['category']); ?>">
                                            <td class="drag-col">
                                                <span class="drag-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>
                                                <input type="hidden" value="<?php echo (int)$item['display_order']; ?>" data-field="display_order">
                                            </td>
                                            <td>
                                                <input type="text" value="<?php echo htmlspecialchars($item['item_name']); ?>" data-field="name">
                                            </td>
                                            <td>
                                                <textarea data-field="description"><?php echo htmlspecialchars($item['description'] ?? ''); ?></textarea>
                                            </td>
                                            <td>
                                                <input type="number" value="<?php echo $item['price']; ?>" step="0.01" data-field="price">
                                            </td>
                                            <td>
                                                <input type="text" value="<?php echo htmlspecialchars($item['tags'] ?? ''); ?>" data-field="tags" placeholder="e.g., Hot, Cold, Premium">
                                            </td>
                                            <td>
                                                <select data-field="station" class="menu-routing-select">
                                                    <option value="bar" <?php echo (($item['station'] ?? 'bar') === 'bar')                ? 'selected' : ''; ?>>Bar</option>
                                                    <option value="coffee_bar" <?php echo (($item['station'] ?? '') === 'coffee_bar')           ? 'selected' : ''; ?>>Coffee Bar</option>
                                                    <option value="kitchen" <?php echo (($item['station'] ?? '') === 'kitchen')              ? 'selected' : ''; ?>>Kitchen</option>
                                                </select>
                                                <div class="menu-routing-checks">
                                                    <label><input type="checkbox" data-field="show_pos" <?php echo !isset($item['show_pos']) || $item['show_pos'] ? 'checked' : ''; ?>> POS</label>
                                                    <label><input type="checkbox" data-field="show_room_service" <?php echo !isset($item['show_room_service']) || $item['show_room_service'] ? 'checked' : ''; ?>> Room Service</label>
                                                </div>
                                            </td>
                                            <td>
                                                <select data-field="is_available">
                                                    <option value="1" <?php echo $item['is_available'] ? 'selected' : ''; ?>>Available</option>
                                                    <option value="0" <?php echo !$item['is_available'] ? 'selected' : ''; ?>>Unavailable</option>
                                                </select>
                                            </td>
                                            <td class="actions-cell">
                                                <div class="action-buttons">
                                                    <button class="btn-action btn-save"
                                                        onclick="saveRow(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['category']); ?>', 'drinks')"
                                                        title="Save Changes">
                                                        <i class="fas fa-save"></i> Save
                                                    </button>
                                                    <button class="btn-action btn-toggle <?php echo $item['is_available'] ? 'active' : ''; ?>"
                                                        onclick="quickToggle(<?php echo $item['id']; ?>, 'drinks')"
                                                        title="<?php echo $item['is_available'] ? 'Mark as Unavailable' : 'Mark as Available'; ?>">
                                                        <i class="fas fa-toggle-<?php echo $item['is_available'] ? 'on' : 'off'; ?>"></i> Toggle
                                                    </button>
                                                    <button class="btn-action btn-recipe<?php echo isset($recipeMap['drink'][(int)$item['id']]) ? ' has-recipe' : ''; ?>"
                                                        onclick="openRecipeModal(<?php echo (int)$item['id']; ?>, 'drink', '<?php echo htmlspecialchars($item['item_name'], ENT_QUOTES); ?>')"
                                                        title="Manage stock recipe">
                                                        <i class="fas fa-flask"></i> Recipe
                                                    </button>
                                                    <button class="btn-action btn-delete"
                                                        onclick="mmConfirmDelete(<?php echo $item['id']; ?>, 'drinks')"
                                                        title="Delete Item">
                                                        <i class="fas fa-trash-alt"></i> Delete
                                                    </button>
                                                    <?php if ($fb_menu_posting_on): ?>
                                                        <button class="btn-action btn-facebook"
                                                            onclick='openFbMenuModal(<?php echo (int)$item["id"]; ?>, <?php echo htmlspecialchars(json_encode($item["item_name"]), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode((float)$item["price"]), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($item["category"] ?? ""), ENT_QUOTES, "UTF-8"); ?>, "drink")'
                                                            title="Feature on Facebook">
                                                            <i class="fab fa-facebook-f"></i> Feature
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No items in this category yet. Click "Add Drink Item" to get started.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Add Menu Item Modal -->
    <div class="mm-modal" id="addMenuModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3 id="modal-title">Add New Menu Item</h3>
                <button type="button" class="mm-modal-close" onclick="closeAddModal()" aria-label="Close">&times;</button>
            </div>
            <form method="POST" id="addMenuForm">
                <div class="mm-modal-body">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="menu_type" id="menu_type" value="food">

                    <div class="mm-field">
                        <label for="add_category">Category *</label>
                        <select name="category" id="add_category" required>
                            <option value="">Select category</option>
                        </select>
                    </div>
                    <div class="mm-field">
                        <label for="add_name">Item name *</label>
                        <input type="text" name="name" id="add_name" required>
                    </div>
                    <div class="mm-field">
                        <label for="add_description">Description *</label>
                        <textarea name="description" id="add_description" rows="3" required></textarea>
                    </div>
                    <div class="mm-field-row">
                        <div class="mm-field">
                            <label for="add_price">Price *</label>
                            <input type="number" name="price" id="add_price" step="0.01" required data-currency="<?php echo htmlspecialchars(getSetting('currency_symbol'), ENT_QUOTES); ?>">
                        </div>
                        <div class="mm-field">
                            <label for="add_order">Display order</label>
                            <input type="number" name="display_order" id="add_order" placeholder="Auto">
                        </div>
                    </div>
                    <div class="mm-field" id="tags-field-container" style="display:none;">
                        <label for="add_tags">Tags (comma-separated)</label>
                        <input type="text" name="tags" id="add_tags" placeholder="e.g., Hot, Cold, Premium">
                    </div>
                    <div class="mm-field">
                        <label for="add_station">Station (where this item is prepared)</label>
                        <select name="station" id="add_station">
                            <option value="kitchen">Kitchen (KDS)</option>
                            <option value="bar">Bar (BDS)</option>
                            <option value="coffee_bar">Coffee Bar (CDS)</option>
                        </select>
                    </div>
                    <div class="mm-field">
                        <label class="mm-checkbox-line">
                            <input type="checkbox" name="is_available" id="add_active" checked>
                            <span id="availability-label">Available (visible on menu)</span>
                        </label>
                    </div>
                    <div class="mm-field">
                        <label class="mm-checkbox-line">
                            <input type="checkbox" name="show_pos" id="add_show_pos" checked>
                            <span>Show in POS / Till</span>
                        </label>
                    </div>
                    <div class="mm-field">
                        <label class="mm-checkbox-line">
                            <input type="checkbox" name="show_room_service" id="add_show_rs" checked>
                            <span>Show in Room Service Dashboard</span>
                        </label>
                    </div>
                </div>
                <div class="mm-modal-foot">
                    <div id="addMenuFeedback" class="admin-modal-feedback" style="width:100%;margin-bottom:8px;"></div>
                    <button type="button" class="mm-btn" onclick="closeAddModal()">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <button type="submit" id="addMenuSaveBtn" class="mm-btn mm-btn-primary">
                        <i class="fas fa-save"></i> Add item
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Recipe Modal -->
    <div class="mm-modal" id="recipeModal">
        <div class="mm-modal-card">
            <div class="mm-modal-head">
                <h3 id="recipe-modal-title">Recipe</h3>
                <button type="button" class="mm-modal-close" onclick="closeRecipeModal()" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body">
                <div class="recipe-meta" id="recipe-meta">
                    <span><strong>Item:</strong> <span id="rm-item-name">—</span></span>
                    <span><strong>Sell price:</strong> <span id="rm-item-price">—</span></span>
                    <span><strong>Plate cost:</strong> <span id="rm-plate-cost">—</span></span>
                    <span><strong>Food cost %:</strong> <span id="rm-food-pct">—</span></span>
                </div>

                <div class="mm-field" style="max-width:160px;">
                    <label for="rm-portions">Portions per recipe</label>
                    <input type="number" id="rm-portions" min="1" value="1">
                </div>

                <table class="recipe-table" id="rm-table">
                    <thead>
                        <tr>
                            <th>Ingredient</th>
                            <th class="col-qty">Qty / portion</th>
                            <th class="col-unit">Unit</th>
                            <th class="col-cost">Cost</th>
                            <th class="col-rm"></th>
                        </tr>
                    </thead>
                    <tbody id="rm-lines"></tbody>
                </table>

                <div class="recipe-add-row">
                    <select id="rm-add-select">
                        <option value="">— Add ingredient —</option>
                    </select>
                    <input type="number" id="rm-add-qty" placeholder="Qty" step="0.0001" style="width:110px;">
                    <button type="button" class="mm-btn mm-btn-sm" onclick="rmAddLine()">
                        <i class="fas fa-plus"></i> Add
                    </button>
                    <button type="button" class="mm-btn mm-btn-sm mm-btn-ghost" onclick="openIngredientModal()">
                        <i class="fas fa-seedling"></i> New ingredient
                    </button>
                </div>

                <div class="recipe-totals">
                    <span>Total ingredients: <strong id="rm-line-count">0</strong></span>
                    <span>Total cost / portion: <strong id="rm-total-cost">—</strong></span>
                </div>

                <div class="recipe-link">
                    <i class="fas fa-info-circle"></i>
                    Saved recipes appear in
                    <a href="stock-recipes.php" target="_blank">Stock &raquo; Recipes</a>
                    and drive automatic stock deduction when this item is charged to a booking.
                </div>
            </div>
            <div class="mm-modal-foot">
                <button type="button" class="mm-btn" onclick="rmDeleteRecipe()" id="rm-delete-btn" style="margin-right:auto;color:var(--mm-danger);">
                    <i class="fas fa-trash"></i> Delete recipe
                </button>
                <button type="button" class="mm-btn" onclick="closeRecipeModal()">Cancel</button>
                <button type="button" class="mm-btn mm-btn-primary" onclick="rmSave()">
                    <i class="fas fa-save"></i> Save recipe
                </button>
            </div>
        </div>
    </div>

    <!-- New Ingredient Modal -->
    <div class="mm-modal" id="ingredientModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3>New ingredient</h3>
                <button type="button" class="mm-modal-close" onclick="closeIngredientModal()" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body">
                <div class="mm-field">
                    <label for="ig-name">Name *</label>
                    <input type="text" id="ig-name" required>
                </div>
                <div class="mm-field-row">
                    <div class="mm-field">
                        <label for="ig-category">Category</label>
                        <input type="text" id="ig-category" placeholder="e.g., Proteins, Dairy" value="General">
                    </div>
                    <div class="mm-field">
                        <label for="ig-unit">Unit *</label>
                        <select id="ig-unit">
                            <option value="g">g (gram)</option>
                            <option value="kg">kg (kilogram)</option>
                            <option value="ml">ml (millilitre)</option>
                            <option value="L">L (litre)</option>
                            <option value="each">each (piece)</option>
                        </select>
                    </div>
                </div>
                <div class="mm-field-row">
                    <div class="mm-field">
                        <label for="ig-cost">Cost per unit</label>
                        <input type="number" id="ig-cost" step="0.01" value="0" data-currency="<?php echo htmlspecialchars(getSetting('currency_symbol'), ENT_QUOTES); ?>">
                    </div>
                    <div class="mm-field">
                        <label for="ig-yield">Yield %</label>
                        <input type="number" id="ig-yield" min="1" max="100" value="100">
                    </div>
                </div>
            </div>
            <div class="mm-modal-foot">
                <button type="button" class="mm-btn" onclick="closeIngredientModal()">Cancel</button>
                <button type="button" class="mm-btn mm-btn-primary" onclick="saveNewIngredient()">
                    <i class="fas fa-save"></i> Create
                </button>
            </div>
        </div>
    </div>


    <script>
        function switchTab(tab) {
            // Update URL without reloading
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.pushState({}, '', url);

            // Update tab buttons
            document.querySelectorAll('.menu-type-tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`.menu-type-tab[onclick="switchTab('${tab}')"]`)?.classList.add('active');

            // Update tab content
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(`${tab}-tab`).classList.add('active');
        }

        function jumpToRoomService() {
            // Activate food tab first (Room Service exists in both food & drinks)
            switchTab('food');
            // Small delay to let CSS display change take effect before scrolling
            setTimeout(() => {
                const anchor = document.getElementById('food-cat-room-service');
                if (anchor) {
                    anchor.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                    anchor.classList.add('rs-highlight');
                    setTimeout(() => anchor.classList.remove('rs-highlight'), 2000);
                }
            }, 80);
        }

        // If URL has ?jump=room-service, auto-scroll after load
        document.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            if (params.get('jump') === 'room-service') {
                jumpToRoomService();
            }
            initDragToReorder();
        });

        function openAddModal(menuType, category = null) {
            const modal = document.getElementById('addMenuModal');
            const menuTypeInput = document.getElementById('menu_type');
            const categorySelect = document.getElementById('add_category');
            const tagsContainer = document.getElementById('tags-field-container');
            const modalTitle = document.getElementById('modal-title');
            const availabilityLabel = document.getElementById('availability-label');

            menuTypeInput.value = menuType;
            modalTitle.textContent = menuType === 'food' ? 'Add new food item' : 'Add new drink item';
            availabilityLabel.textContent = menuType === 'food' ? 'Available (visible on menu)' : 'Active (visible on menu)';
            tagsContainer.style.display = menuType === 'drinks' ? 'block' : 'none';

            // Default station: food→kitchen, drinks→bar
            const stationSel = document.getElementById('add_station');
            if (stationSel) stationSel.value = menuType === 'food' ? 'kitchen' : 'bar';
            // Default visibility: both checked
            const sp = document.getElementById('add_show_pos');
            if (sp) sp.checked = true;
            const sr = document.getElementById('add_show_rs');
            if (sr) sr.checked = true;

            categorySelect.innerHTML = '<option value="">Select category</option>';
            const categories = menuType === 'food' ? <?php echo json_encode($food_categories); ?> : <?php echo json_encode($drink_categories); ?>;
            categories.forEach(cat => {
                const opt = document.createElement('option');
                opt.value = cat;
                opt.textContent = cat;
                categorySelect.appendChild(opt);
            });
            if (category) {
                categorySelect.value = category;
            }

            modal.classList.add('open');
        }

        function closeAddModal() {
            document.getElementById('addMenuModal').classList.remove('open');
            document.getElementById('addMenuFeedback').className = 'admin-modal-feedback';
            document.getElementById('addMenuFeedback').innerHTML = '';
        }

        // Close any modal when clicking the backdrop
        document.querySelectorAll('.mm-modal').forEach(m => {
            m.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('open');
                }
            });
        });

        function saveRow(id, category, menuType) {
            const row = document.getElementById(`${menuType}-row-${id}`);
            const formData = new FormData();

            formData.append('action', 'update');
            formData.append('id', id);
            formData.append('category', category);
            formData.append('menu_type', menuType);

            if (menuType === 'food') {
                formData.append('display_order', row.querySelector('[data-field="display_order"]').value);
                formData.append('name', row.querySelector('[data-field="name"]').value);
                formData.append('description', row.querySelector('[data-field="description"]').value);
                formData.append('price', row.querySelector('[data-field="price"]').value);
                formData.append('is_available', row.querySelector('[data-field="is_available"]').value);
            } else {
                formData.append('display_order', row.querySelector('[data-field="display_order"]').value);
                formData.append('name', row.querySelector('[data-field="name"]').value);
                formData.append('description', row.querySelector('[data-field="description"]').value);
                formData.append('price', row.querySelector('[data-field="price"]').value);
                formData.append('tags', row.querySelector('[data-field="tags"]').value);
                formData.append('is_available', row.querySelector('[data-field="is_available"]').value);
            }

            // Routing fields (station + show_pos + show_room_service) — apply to both food & drink
            const stationField = row.querySelector('[data-field="station"]');
            if (stationField) formData.append('station', stationField.value);
            const showPos = row.querySelector('[data-field="show_pos"]');
            if (showPos && showPos.checked) formData.append('show_pos', '1');
            const showRs = row.querySelector('[data-field="show_room_service"]');
            if (showRs && showRs.checked) formData.append('show_room_service', '1');

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.ok) {
                        window.location.reload();
                    } else {
                        Alert.show('Error saving item', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Alert.show('Error saving item', 'error');
                });
        }

        // Quick toggle availability
        function quickToggle(id, menuType) {
            const formData = new FormData();
            formData.append('action', 'toggle_availability');
            formData.append('id', id);
            formData.append('menu_type', menuType);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.ok) {
                        window.location.reload();
                    } else {
                        Alert.show('Error toggling availability', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Alert.show('Error toggling availability', 'error');
                });
        }

        function deleteRow(id, menuType) {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            formData.append('menu_type', menuType);
            formData.append('force_delete', '1');

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.ok) {
                        window.location.reload();
                    } else {
                        Alert.show('Error deleting item', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Alert.show('Error deleting item', 'error');
                });
        }

        /* ============================================================
           RECIPE MANAGEMENT
           ============================================================ */
        const RECIPE_API = 'api/menu-recipe.php';
        const CURRENCY = <?php echo json_encode(getSetting('currency_symbol')); ?>;
        let _ingredientsCache = [];
        let _rmContext = {
            menu_item_id: 0,
            menu_type: 'food',
            item_name: '',
            item_price: 0
        };
        let _rmLines = []; // {ingredient_id, quantity, name, unit, cost_per_unit}

        function fmtMoney(n) {
            const v = Number(n) || 0;
            return CURRENCY + v.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        async function loadIngredients(force = false) {
            if (!force && _ingredientsCache.length) return _ingredientsCache;
            const res = await fetch(RECIPE_API + '?action=list_ingredients');
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Failed to load ingredients');
            _ingredientsCache = data.ingredients;
            return _ingredientsCache;
        }

        function rmFillIngredientSelect() {
            const sel = document.getElementById('rm-add-select');
            sel.innerHTML = '<option value="">— Add ingredient —</option>';
            const grouped = {};
            _ingredientsCache.forEach(i => {
                (grouped[i.category || 'Other'] ||= []).push(i);
            });
            Object.keys(grouped).sort().forEach(cat => {
                const og = document.createElement('optgroup');
                og.label = cat;
                grouped[cat].forEach(i => {
                    const opt = document.createElement('option');
                    opt.value = i.id;
                    opt.textContent = `${i.name} (${i.unit})`;
                    og.appendChild(opt);
                });
                sel.appendChild(og);
            });
        }

        async function openRecipeModal(menuItemId, menuType, itemName) {
            _rmContext = {
                menu_item_id: menuItemId,
                menu_type: menuType,
                item_name: itemName,
                item_price: 0
            };
            document.getElementById('recipe-modal-title').textContent = `Recipe — ${itemName}`;
            document.getElementById('rm-item-name').textContent = itemName;
            document.getElementById('rm-item-price').textContent = '—';
            document.getElementById('rm-plate-cost').textContent = '—';
            document.getElementById('rm-food-pct').textContent = '—';
            document.getElementById('rm-portions').value = 1;
            document.getElementById('rm-lines').innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--mm-muted);padding:16px;">Loading…</td></tr>';
            document.getElementById('recipeModal').classList.add('open');

            try {
                await loadIngredients();
                rmFillIngredientSelect();

                const url = `${RECIPE_API}?action=get_recipe&menu_item_id=${menuItemId}&menu_type=${menuType}`;
                const res = await fetch(url);
                const data = await res.json();
                if (!data.ok) throw new Error(data.error);

                _rmContext.item_price = parseFloat(data.item.price) || 0;
                document.getElementById('rm-item-price').textContent = fmtMoney(_rmContext.item_price);

                if (data.recipe) {
                    document.getElementById('rm-portions').value = data.recipe.portions_per_recipe || 1;
                }

                _rmLines = (data.lines || []).map(l => ({
                    ingredient_id: parseInt(l.ingredient_id, 10),
                    quantity: parseFloat(l.quantity_per_portion) || 0,
                    name: l.name,
                    unit: l.unit,
                    cost_per_unit: parseFloat(l.cost_per_unit) || 0
                }));
                rmRender();
            } catch (e) {
                document.getElementById('rm-lines').innerHTML = `<tr><td colspan="5" style="text-align:center;color:var(--mm-danger);padding:16px;">${e.message}</td></tr>`;
            }
        }

        function closeRecipeModal() {
            document.getElementById('recipeModal').classList.remove('open');
        }

        function rmRender() {
            const tbody = document.getElementById('rm-lines');
            if (!_rmLines.length) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--mm-muted);padding:16px;">No ingredients yet. Add one below.</td></tr>';
            } else {
                tbody.innerHTML = '';
                _rmLines.forEach((line, idx) => {
                    const lineCost = (line.quantity || 0) * (line.cost_per_unit || 0);
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${line.name}</td>
                        <td class="col-qty"><input type="number" step="0.0001" min="0" value="${line.quantity}" data-idx="${idx}" oninput="rmEditQty(this)"></td>
                        <td class="col-unit">${line.unit}</td>
                        <td class="col-cost">${fmtMoney(lineCost)}</td>
                        <td class="col-rm"><button type="button" class="recipe-rm-btn" onclick="rmRemove(${idx})" title="Remove"><i class="fas fa-times"></i></button></td>
                    `;
                    tbody.appendChild(tr);
                });
            }
            const total = _rmLines.reduce((s, l) => s + (l.quantity || 0) * (l.cost_per_unit || 0), 0);
            document.getElementById('rm-line-count').textContent = _rmLines.length;
            document.getElementById('rm-total-cost').textContent = fmtMoney(total);
            const sell = _rmContext.item_price || 0;
            document.getElementById('rm-plate-cost').textContent = fmtMoney(total);
            document.getElementById('rm-food-pct').textContent = sell > 0 ?
                ((total / sell) * 100).toFixed(1) + '%' :
                '—';
        }

        function rmEditQty(input) {
            const idx = parseInt(input.dataset.idx, 10);
            _rmLines[idx].quantity = parseFloat(input.value) || 0;
            rmRender();
        }

        function rmRemove(idx) {
            _rmLines.splice(idx, 1);
            rmRender();
        }

        function rmAddLine() {
            const sel = document.getElementById('rm-add-select');
            const qtyInp = document.getElementById('rm-add-qty');
            const id = parseInt(sel.value, 10);
            const qty = parseFloat(qtyInp.value);
            if (!id || !qty || qty <= 0) {
                Alert.show('Pick an ingredient and enter a quantity > 0', 'error');
                return;
            }
            const ing = _ingredientsCache.find(i => parseInt(i.id, 10) === id);
            if (!ing) return;
            const existing = _rmLines.findIndex(l => l.ingredient_id === id);
            if (existing >= 0) {
                _rmLines[existing].quantity = qty;
            } else {
                _rmLines.push({
                    ingredient_id: id,
                    quantity: qty,
                    name: ing.name,
                    unit: ing.unit,
                    cost_per_unit: parseFloat(ing.cost_per_unit) || 0
                });
            }
            sel.value = '';
            qtyInp.value = '';
            rmRender();
        }

        async function rmSave() {
            const portions = Math.max(1, parseInt(document.getElementById('rm-portions').value, 10) || 1);
            const fd = new FormData();
            fd.append('action', 'save_recipe');
            fd.append('menu_item_id', _rmContext.menu_item_id);
            fd.append('menu_type', _rmContext.menu_type);
            fd.append('portions', portions);
            fd.append('lines', JSON.stringify(_rmLines.map(l => ({
                ingredient_id: l.ingredient_id,
                quantity: l.quantity
            }))));
            try {
                const res = await fetch(RECIPE_API, {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error);
                Alert.show(data.message || 'Recipe saved', 'success');
                closeRecipeModal();
                setTimeout(() => window.location.reload(), 600);
            } catch (e) {
                Alert.show('Save failed: ' + e.message, 'error');
            }
        }

        async function rmDeleteRecipe() {
            if (!confirm('Delete the recipe for this menu item? Stock auto-deduction will stop.')) return;
            const fd = new FormData();
            fd.append('action', 'delete_recipe');
            fd.append('menu_item_id', _rmContext.menu_item_id);
            fd.append('menu_type', _rmContext.menu_type);
            try {
                const res = await fetch(RECIPE_API, {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error);
                Alert.show('Recipe deleted', 'success');
                closeRecipeModal();
                setTimeout(() => window.location.reload(), 600);
            } catch (e) {
                Alert.show('Delete failed: ' + e.message, 'error');
            }
        }

        function openIngredientModal() {
            document.getElementById('ig-name').value = '';
            document.getElementById('ig-category').value = 'General';
            document.getElementById('ig-unit').value = 'g';
            document.getElementById('ig-cost').value = 0;
            document.getElementById('ig-yield').value = 100;
            document.getElementById('ingredientModal').classList.add('open');
            setTimeout(() => document.getElementById('ig-name').focus(), 50);
        }

        function closeIngredientModal() {
            document.getElementById('ingredientModal').classList.remove('open');
        }

        async function saveNewIngredient() {
            const name = document.getElementById('ig-name').value.trim();
            if (!name) {
                Alert.show('Name is required', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'add_ingredient');
            fd.append('name', name);
            fd.append('category', document.getElementById('ig-category').value.trim() || 'General');
            fd.append('unit', document.getElementById('ig-unit').value);
            fd.append('cost_per_unit', document.getElementById('ig-cost').value || 0);
            fd.append('yield_percent', document.getElementById('ig-yield').value || 100);
            try {
                const res = await fetch(RECIPE_API, {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error);
                await loadIngredients(true);
                rmFillIngredientSelect();
                document.getElementById('rm-add-select').value = data.id;
                Alert.show(data.reused ? 'Ingredient already existed — selected.' : 'Ingredient created', 'success');
                closeIngredientModal();
            } catch (e) {
                Alert.show('Failed: ' + e.message, 'error');
            }
        }

        // ── AJAX save for Add Menu Modal ──────────────────────────────────
        document.getElementById('addMenuForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('addMenuSaveBtn');
            const fb = document.getElementById('addMenuFeedback');
            const origHtml = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
            fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new FormData(this)
                })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(res) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.style.width = '100%';
                    fb.className = 'admin-modal-feedback ' + (res.success ? 'admin-modal-feedback--success' : 'admin-modal-feedback--error') + ' visible';
                    fb.innerHTML = '<i class="fas fa-' + (res.success ? 'check-circle' : 'exclamation-circle') + '"></i> ' + res.message;
                    if (res.success) refreshMenuTab();
                })
                .catch(function() {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error — please try again.';
                });
        });

        function refreshMenuTab() {
            var tabParam = document.querySelector('.tab-content.active') && document.querySelector('.tab-content.active').id === 'drinks-tab' ? 'drinks' : 'food';
            fetch(window.location.pathname + '?tab=' + tabParam)
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var foodNext = doc.getElementById('food-tab');
                    var foodCur = document.getElementById('food-tab');
                    if (foodNext && foodCur) foodCur.innerHTML = foodNext.innerHTML;
                    var drinkNext = doc.getElementById('drinks-tab');
                    var drinkCur = document.getElementById('drinks-tab');
                    if (drinkNext && drinkCur) drinkCur.innerHTML = drinkNext.innerHTML;
                    initDragToReorder();
                }).catch(function() {});
        }

        /* ============================================================
           DRAG-TO-REORDER rows within a category table
           ============================================================ */
        function initDragToReorder() {
            document.querySelectorAll('.menu-table tbody').forEach(function(tbody) {
                var dragging = null;

                tbody.querySelectorAll('tr').forEach(function(row) {
                    var handle = row.querySelector('.drag-handle');
                    if (!handle) return;

                    handle.addEventListener('mousedown', function() {
                        row.setAttribute('draggable', 'true');
                    });
                    handle.addEventListener('mouseup', function() {
                        row.setAttribute('draggable', 'false');
                    });

                    row.addEventListener('dragstart', function(e) {
                        dragging = row;
                        row.classList.add('drag-dragging');
                        e.dataTransfer.effectAllowed = 'move';
                    });

                    row.addEventListener('dragend', function() {
                        row.classList.remove('drag-dragging');
                        tbody.querySelectorAll('tr').forEach(function(r) {
                            r.classList.remove('drag-over');
                        });
                        row.setAttribute('draggable', 'false');
                        dragging = null;
                        saveRowOrder(tbody);
                    });

                    row.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        if (dragging && dragging !== row) {
                            tbody.querySelectorAll('tr').forEach(function(r) {
                                r.classList.remove('drag-over');
                            });
                            row.classList.add('drag-over');
                            var rect = row.getBoundingClientRect();
                            if (e.clientY < rect.top + rect.height / 2) {
                                tbody.insertBefore(dragging, row);
                            } else {
                                tbody.insertBefore(dragging, row.nextSibling);
                            }
                        }
                    });
                });
            });
        }

        function saveRowOrder(tbody) {
            var rows = Array.from(tbody.querySelectorAll('tr'));
            var tabContent = tbody.closest('.tab-content');
            var menuType = (tabContent && tabContent.id === 'drinks-tab') ? 'drinks' : 'food';
            var formData = new FormData();
            formData.append('action', 'reorder_items');
            formData.append('menu_type', menuType);
            rows.forEach(function(row, i) {
                var m = row.id.match(/^(?:food|drink)-row-(\d+)$/);
                if (m) {
                    formData.append('ids[]', m[1]);
                    var hidden = row.querySelector('[data-field="display_order"]');
                    if (hidden) hidden.value = i + 1;
                }
            });
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            }).catch(function() {});
        }

        /* ============================================================
           DELETE CONFIRM — no window.confirm()
           ============================================================ */
        function mmConfirmDelete(id, menuType) {
            var overlay = document.createElement('div');
            overlay.className = 'mm-confirm-overlay';
            overlay.innerHTML =
                '<div class="mm-confirm-card">' +
                '  <p class="mm-confirm-msg"><i class="fas fa-exclamation-triangle"></i> Delete this menu item?<br><small>Any attached stock recipe will also be removed.</small></p>' +
                '  <div class="mm-confirm-actions">' +
                '    <button class="btn-action btn-delete mm-confirm-yes"><i class="fas fa-trash-alt"></i> Delete</button>' +
                '    <button class="btn-action mm-confirm-no">Cancel</button>' +
                '  </div>' +
                '</div>';
            document.body.appendChild(overlay);
            overlay.querySelector('.mm-confirm-yes').addEventListener('click', function() {
                document.body.removeChild(overlay);
                deleteRow(id, menuType);
            });
            overlay.querySelector('.mm-confirm-no').addEventListener('click', function() {
                document.body.removeChild(overlay);
            });
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) document.body.removeChild(overlay);
            });
        }
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>

    <?php if ($fb_menu_posting_on): ?>
        <!-- Facebook Menu Item Share Modal — two-column with live preview -->
        <div class="modal-overlay" id="fbMenuModal" style="display:none;" onclick="if(event.target===this)closeFbMenuModal()">
            <div class="modal-content" style="max-width:760px;">
                <div class="modal-header" style="border-top:4px solid #1877F2;">
                    <h3 id="fbMenuTitle" style="color:#1877F2;"><i class="fab fa-facebook-f"></i> Feature on Facebook</h3>
                    <button class="modal-close" type="button" onclick="closeFbMenuModal()">&times;</button>
                </div>
                <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <!-- Left: compose -->
                    <div>
                        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Caption</label>
                        <textarea id="fbMenuCaption" class="form-control" rows="9" style="font-size:13px;line-height:1.6;resize:vertical;" oninput="_fbMenuUpdatePreview()"></textarea>
                        <div style="font-size:11px;color:#6b7280;margin-top:4px;text-align:right;"><span id="fbMenuCharCount">0</span> characters</div>
                        <div class="admin-modal-feedback" id="fbMenuFeedback"></div>
                    </div>
                    <!-- Right: live preview -->
                    <div>
                        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Live Preview</label>
                        <div style="border:1.5px solid #dde3f0;border-radius:10px;overflow:hidden;background:#fff;font-family:Helvetica,Arial,sans-serif;font-size:13px;">
                            <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid #f0f2f5;">
                                <div style="width:38px;height:38px;border-radius:50%;background:#1877F2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fab fa-facebook-f" style="color:#fff;font-size:16px;"></i>
                                </div>
                                <div>
                                    <div id="fbMenuPreviewPageName" style="font-weight:700;font-size:13px;color:#050505;"></div>
                                    <div style="font-size:11px;color:#65676b;">Just now &middot; <i class="fas fa-globe-africa"></i></div>
                                </div>
                            </div>
                            <div id="fbMenuPreviewText" style="padding:10px 12px;white-space:pre-wrap;word-break:break-word;color:#050505;font-size:13px;line-height:1.5;min-height:60px;"></div>
                            <div id="fbMenuPreviewEmoji" style="background:linear-gradient(135deg,#fff8f0,#fff3e0);height:70px;display:flex;align-items:center;justify-content:center;font-size:36px;"></div>
                            <div style="padding:8px 12px;border-top:1px solid #f0f2f5;display:flex;gap:16px;">
                                <span style="color:#65676b;font-size:12px;"><i class="far fa-thumbs-up"></i> Like</span>
                                <span style="color:#65676b;font-size:12px;"><i class="far fa-comment"></i> Comment</span>
                                <span style="color:#65676b;font-size:12px;"><i class="fas fa-share"></i> Share</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" id="fbMenuSubmitBtn" class="btn" style="background:#1877F2;color:#fff;border-color:#1877F2;">
                        <i class="fab fa-facebook-f"></i> Post to Facebook Page
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeFbMenuModal()">Cancel</button>
                </div>
            </div>
        </div>

        <!-- Share Full Menu on Facebook modal -->
        <div class="modal-overlay" id="fbMenuShareAllModal" style="display:none;" onclick="if(event.target===this)closeFbMenuShareAllModal()">
            <div class="modal-content" style="max-width:820px;">
                <div class="modal-header" style="border-top:4px solid #1877F2;">
                    <h3 style="color:#1877F2;"><i class="fab fa-facebook-f"></i> Share Restaurant Menu on Facebook</h3>
                    <button class="modal-close" type="button" onclick="closeFbMenuShareAllModal()">&times;</button>
                </div>
                <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <!-- Left: compose -->
                    <div>
                        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Caption</label>
                        <textarea id="fbMenuShareAllCaption" class="form-control" rows="12" style="font-size:13px;line-height:1.6;resize:vertical;" oninput="_fbMenuAllUpdatePreview()"></textarea>
                        <div style="font-size:11px;color:#6b7280;margin-top:4px;text-align:right;"><span id="fbMenuAllCharCount">0</span> characters</div>
                        <div class="admin-modal-feedback" id="fbMenuShareAllFeedback"></div>
                    </div>
                    <!-- Right: live preview -->
                    <div>
                        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Live Preview</label>
                        <div style="border:1.5px solid #dde3f0;border-radius:10px;overflow:hidden;background:#fff;font-family:Helvetica,Arial,sans-serif;font-size:13px;">
                            <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid #f0f2f5;">
                                <div style="width:38px;height:38px;border-radius:50%;background:#1877F2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fab fa-facebook-f" style="color:#fff;font-size:16px;"></i>
                                </div>
                                <div>
                                    <div id="fbMenuAllPreviewPageName" style="font-weight:700;font-size:13px;color:#050505;"></div>
                                    <div style="font-size:11px;color:#65676b;">Just now &middot; <i class="fas fa-globe-africa"></i></div>
                                </div>
                            </div>
                            <div id="fbMenuAllPreviewText" style="padding:10px 12px;white-space:pre-wrap;word-break:break-word;color:#050505;font-size:13px;line-height:1.5;min-height:80px;"></div>
                            <div style="background:linear-gradient(135deg,#fff8f0,#fff3e0);height:70px;display:flex;align-items:center;justify-content:center;font-size:30px;gap:12px;color:#c2410c;">
                                <span>&#127869;&#65039;</span><span>&#127867;</span><span>&#129371;</span>
                            </div>
                            <div style="padding:8px 12px;border-top:1px solid #f0f2f5;display:flex;gap:16px;">
                                <span style="color:#65676b;font-size:12px;"><i class="far fa-thumbs-up"></i> Like</span>
                                <span style="color:#65676b;font-size:12px;"><i class="far fa-comment"></i> Comment</span>
                                <span style="color:#65676b;font-size:12px;"><i class="fas fa-share"></i> Share</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" id="fbMenuShareAllSubmitBtn" class="btn" style="background:#1877F2;color:#fff;border-color:#1877F2;">
                        <i class="fab fa-facebook-f"></i> Post to Facebook Page
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeFbMenuShareAllModal()">Cancel</button>
                </div>
            </div>
        </div>
        <script>
            (function() {
                var _fbMenuId = 0;
                var _fbMenuType = 'food';
                var _fbMenuItemEmoji = '\ud83c\udf7d\ufe0f';

                window._fbMenuDefaults = {
                    baseUrl: <?php echo json_encode(defined('BASE_URL') ? rtrim(BASE_URL, '/') : ''); ?>,
                    currency: <?php echo json_encode(getSetting('currency_symbol', 'MWK')); ?>,
                    hashtags: <?php echo json_encode(getSetting('facebook_default_hashtags', '#hotel #restaurant #food')); ?>,
                    pageName: <?php echo json_encode(getSetting('facebook_page_name', getSetting('site_name', 'Hotel'))); ?>
                };

                // Update live preview for single menu item modal
                window._fbMenuUpdatePreview = function() {
                    var caption = (document.getElementById('fbMenuCaption').value || '');
                    var previewEl = document.getElementById('fbMenuPreviewText');
                    var charEl = document.getElementById('fbMenuCharCount');
                    var emojiEl = document.getElementById('fbMenuPreviewEmoji');
                    if (previewEl) previewEl.textContent = caption;
                    if (charEl) charEl.textContent = caption.length;
                    if (emojiEl) emojiEl.textContent = _fbMenuItemEmoji;
                };

                window.openFbMenuModal = function(itemId, itemName, price, category, menuType) {
                    _fbMenuId = itemId;
                    _fbMenuType = menuType || 'food';
                    _fbMenuItemEmoji = menuType === 'drink' ? '\ud83c\udf79' : '\ud83c\udf7d\ufe0f';

                    var modal = document.getElementById('fbMenuModal');
                    if (!modal) return;
                    document.getElementById('fbMenuTitle').innerHTML = '<i class="fab fa-facebook-f"></i> Feature &ldquo;' + itemName.replace(/</g, '&lt;') + '&rdquo; on Facebook';

                    var d = window._fbMenuDefaults || {};
                    var icon = _fbMenuItemEmoji;
                    var lines = [icon + ' ' + itemName];
                    if (price) lines.push(d.currency + ' ' + Number(price).toLocaleString());
                    if (category) lines.push('Category: ' + category);
                    lines.push('');
                    lines.push('See our full menu: ' + d.baseUrl + '/restaurant.php');
                    lines.push('');
                    lines.push(d.hashtags || '');
                    document.getElementById('fbMenuCaption').value = lines.join('\n').trim();

                    var pageNameEl = document.getElementById('fbMenuPreviewPageName');
                    if (pageNameEl) pageNameEl.textContent = d.pageName || '';

                    var fb = document.getElementById('fbMenuFeedback');
                    fb.className = 'admin-modal-feedback';
                    fb.innerHTML = '';
                    window._fbMenuUpdatePreview();
                    modal.style.display = 'flex';
                };

                window.closeFbMenuModal = function() {
                    var m = document.getElementById('fbMenuModal');
                    if (m) m.style.display = 'none';
                };

                // ── Share Full Menu ─────────────────────────────────────────────
                window._fbMenuAllUpdatePreview = function() {
                    var caption = (document.getElementById('fbMenuShareAllCaption').value || '');
                    var previewEl = document.getElementById('fbMenuAllPreviewText');
                    var charEl = document.getElementById('fbMenuAllCharCount');
                    if (previewEl) previewEl.textContent = caption;
                    if (charEl) charEl.textContent = caption.length;
                };

                window.openFbMenuShareAllModal = function() {
                    var d = window._fbMenuDefaults || {};
                    var pageNameEl = document.getElementById('fbMenuAllPreviewPageName');
                    if (pageNameEl) pageNameEl.textContent = d.pageName || '';

                    // Build a default promotional menu caption
                    var lines = [
                        '\ud83c\udf7d\ufe0f Our Restaurant Menu is Waiting for You!',
                        '',
                        'From hearty breakfasts to satisfying dinners and refreshing drinks \u2014 there\'s something for everyone.',
                        '',
                        '\ud83c\udf73 Food: Breakfast, Lunch, Dinner, Snacks',
                        '\ud83c\udf79 Drinks: Cocktails, Mocktails, Coffee, Juices & more',
                        '',
                        '\ud83d\udccd Visit us or explore the full menu online:',
                        (d.baseUrl ? d.baseUrl + '/restaurant.php' : ''),
                        '',
                        (d.hashtags || ''),
                    ];
                    document.getElementById('fbMenuShareAllCaption').value = lines.join('\n').trim();

                    var fb = document.getElementById('fbMenuShareAllFeedback');
                    fb.className = 'admin-modal-feedback';
                    fb.innerHTML = '';
                    window._fbMenuAllUpdatePreview();
                    document.getElementById('fbMenuShareAllModal').style.display = 'flex';
                };

                window.closeFbMenuShareAllModal = function() {
                    var m = document.getElementById('fbMenuShareAllModal');
                    if (m) m.style.display = 'none';
                };

                // Run immediately if the DOM is already parsed — this inline block
                // can execute after DOMContentLoaded has fired, in which case a plain
                // addEventListener('DOMContentLoaded') never runs and the Share/submit
                // buttons stay dead until a reload happens to change the timing.
                function _fbMenuBindSubmits() {
                    // ── Single menu item submit ────────────────────────────────
                    var submitBtn = document.getElementById('fbMenuSubmitBtn');
                    if (submitBtn) {
                        submitBtn.addEventListener('click', function() {
                            var caption = (document.getElementById('fbMenuCaption').value || '').trim();
                            var fb = document.getElementById('fbMenuFeedback');
                            if (!caption) {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a caption.';
                                return;
                            }
                            submitBtn.disabled = true;
                            var origHtml = submitBtn.innerHTML;
                            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting\u2026';
                            fb.className = 'admin-modal-feedback';
                            fb.innerHTML = '';

                            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                            var csrfInp = document.querySelector('input[name="csrf_token"]');
                            var csrf = csrfMeta ? csrfMeta.getAttribute('content') : (csrfInp ? csrfInp.value : '');

                            var fd = new FormData();
                            fd.append('csrf_token', csrf);
                            fd.append('type', 'menu_item');
                            fd.append('menu_type', _fbMenuType);
                            fd.append('id', String(_fbMenuId));
                            fd.append('message', caption);

                            fetch('api/facebook-post.php', {
                                    method: 'POST',
                                    headers: {
                                        'X-Requested-With': 'XMLHttpRequest'
                                    },
                                    body: fd
                                })
                                .then(function(r) {
                                    return r.json();
                                })
                                .then(function(data) {
                                    submitBtn.disabled = false;
                                    submitBtn.innerHTML = origHtml;
                                    if (data.success) {
                                        fb.className = 'admin-modal-feedback admin-modal-feedback--success visible';
                                        var lnk = data.post_url ? ' <a href="' + data.post_url + '" target="_blank" rel="noopener">View post</a>' : '';
                                        fb.innerHTML = '<i class="fas fa-check-circle"></i> Posted to Facebook!' + lnk;
                                    } else {
                                        fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Unknown error.');
                                    }
                                })
                                .catch(function() {
                                    submitBtn.disabled = false;
                                    submitBtn.innerHTML = origHtml;
                                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error \u2014 please try again.';
                                });
                        });
                    }

                    // ── Share Full Menu submit ─────────────────────────────────
                    var shareAllBtn = document.getElementById('fbMenuShareAllSubmitBtn');
                    if (shareAllBtn) {
                        shareAllBtn.addEventListener('click', function() {
                            var caption = (document.getElementById('fbMenuShareAllCaption').value || '').trim();
                            var fb = document.getElementById('fbMenuShareAllFeedback');
                            if (!caption) {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a caption.';
                                return;
                            }
                            shareAllBtn.disabled = true;
                            var origHtml = shareAllBtn.innerHTML;
                            shareAllBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting\u2026';
                            fb.className = 'admin-modal-feedback';
                            fb.innerHTML = '';

                            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                            var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

                            var fd = new FormData();
                            fd.append('csrf_token', csrf);
                            fd.append('type', 'menu_share');
                            fd.append('message', caption);

                            fetch('api/facebook-post.php', {
                                    method: 'POST',
                                    headers: {
                                        'X-Requested-With': 'XMLHttpRequest'
                                    },
                                    body: fd
                                })
                                .then(function(r) {
                                    return r.json();
                                })
                                .then(function(data) {
                                    shareAllBtn.disabled = false;
                                    shareAllBtn.innerHTML = origHtml;
                                    if (data.success) {
                                        fb.className = 'admin-modal-feedback admin-modal-feedback--success visible';
                                        var lnk = data.post_url ? ' <a href="' + data.post_url + '" target="_blank" rel="noopener">View post</a>' : '';
                                        fb.innerHTML = '<i class="fas fa-check-circle"></i> Posted to Facebook!' + lnk;
                                    } else {
                                        fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Unknown error.');
                                    }
                                })
                                .catch(function() {
                                    shareAllBtn.disabled = false;
                                    shareAllBtn.innerHTML = origHtml;
                                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error \u2014 please try again.';
                                });
                        });
                    }
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', _fbMenuBindSubmits);
                } else {
                    _fbMenuBindSubmits();
                }
            }());
        </script>
    <?php endif; ?>

