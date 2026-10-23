<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var string $csrf_token */
require_once '../includes/alert.php';
require_once __DIR__ . '/../includes/form-validation.php';
require_once __DIR__ . '/includes/menu-recipe-lib.php';
require_once __DIR__ . '/includes/menu-pos-sync.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';
$current_page = basename($_SERVER['PHP_SELF']);
$current_tab = (($_GET['tab'] ?? 'food') === 'drinks') ? 'drinks' : 'food';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Non-restaurant presets (gym, retail, supermarket…) manage the POS product
// catalog instead of a food/drinks menu — same page slot, different mode.
if (function_exists('isRestaurantEnabled') && !isRestaurantEnabled()) {
    require __DIR__ . '/includes/product-management-mode.php';
    exit;
}

// Facebook sharing
require_once '../includes/facebook-functions.php';
$fb_menu_posting_on = facebookShareVisible('menu');
$fb_share_attrs     = facebookShareButtonAttrs('Feature on Facebook'); // title, or disabled + hint when not connected

/* Every save in this page writes the website menu (food_menu / drink_menu) AND the till's catalogue
 * (menu_items) in one transaction - see includes/menu-pos-sync.php. Stock links (recipes) hang off
 * the till item. */

// Handle menu item actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security token invalid. Refresh the page and try again.']);
            exit;
        }
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
    $menuLockName = '';
    $jsonExtra = [];
    try {
        $action = (string)($_POST['action'] ?? '');
        // The drinks tab sends 'drinks'; only that or 'drink' selects drink_menu.
        $menu_type = in_array((string)($_POST['menu_type'] ?? 'food'), ['drink', 'drinks'], true) ? 'drink' : 'food';
        $table = $menu_type === 'food' ? 'food_menu' : 'drink_menu';
        $stockOn = function_exists('ensureStockTablesExist') && ensureStockTablesExist();

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
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $pdo->beginTransaction();
            $upd = $pdo->prepare("UPDATE `{$table}` SET display_order = ? WHERE id = ?");
            $sel = $pdo->prepare("SELECT item_name, category FROM `{$table}` WHERE id = ?");
            $updPos = $pdo->prepare("UPDATE menu_items SET display_order = ? WHERE id = ?");
            foreach ($ids as $order => $id) {
                $id = (int)$id;
                if ($id <= 0) {
                    continue;
                }
                $upd->execute([$order + 1, $id]);
                $sel->execute([$id]);
                $r = $sel->fetch(PDO::FETCH_ASSOC);
                $p = $r ? rh_menu_pos_find($pdo, (string)$r['item_name'], (string)$r['category']) : null;
                if ($p) {
                    $updPos->execute([$order + 1, $p['id']]);
                }
            }
            $pdo->commit();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true]);
            exit;
        } elseif ($action === 'add' || $action === 'update') {
            $name = rh_clean_text($_POST['name'] ?? '');
            $category = rh_clean_text($_POST['category'] ?? '');
            if ($category === '__new__') {
                $category = rh_clean_text($_POST['new_category'] ?? '');
            }
            $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 2000);
            $rawPrice = str_replace(',', '', trim((string)($_POST['price'] ?? '')));
            $isFree = !empty($_POST['free_item']);
            $tags = $menu_type === 'drink' ? mb_substr(rh_clean_text($_POST['tags'] ?? ''), 0, 255) : '';
            $itemId = (int)($_POST['id'] ?? 0);

            if ($name === '' || mb_strlen($name) > 200) {
                throw new RuntimeException('Item name is required (200 characters maximum).');
            }
            if ($category === '' || mb_strlen($category) > 100) {
                throw new RuntimeException('Pick a category (or type a new one, 100 characters maximum).');
            }
            if ($isFree) {
                $price = '0.00';
            } else {
                if ($rawPrice === '' || !is_numeric($rawPrice) || (float)$rawPrice <= 0 || (float)$rawPrice > 99999999) {
                    throw new RuntimeException('Enter the price (more than 0). For a free item, tick "This item is free".');
                }
                $price = number_format((float)$rawPrice, 2, '.', '');
            }
            if ($action === 'update' && $itemId <= 0) {
                throw new RuntimeException('Invalid menu item.');
            }
            $station = in_array($_POST['station'] ?? '', ['kitchen', 'bar', 'coffee_bar'], true)
                ? $_POST['station'] : ($menu_type === 'food' ? 'kitchen' : 'bar');
            $isAvail = !empty($_POST['is_available']) ? 1 : 0;
            $showPos = !empty($_POST['show_pos']) ? 1 : 0;
            $showRs = !empty($_POST['show_room_service']) ? 1 : 0;
            $barcode = array_key_exists('barcode', $_POST) ? rhNormalizeBarcode((string)$_POST['barcode']) : null;

            // Same name within the same menu type + category is a duplicate (lock covers check + write).
            $menuLockName = substr('rh_form_menu_item_' . (defined('DB_NAME') ? DB_NAME : 'db'), 0, 64); // one lock for food and drinks: a barcode is unique across both
            if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($menuLockName) . ', 10)')->fetchColumn() !== 1) {
                $menuLockName = '';
                throw new RuntimeException('Someone else is saving a menu item right now. Please try again in a moment.');
            }

            $cur = null;
            if ($action === 'update') {
                $curStmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = ?");
                $curStmt->execute([$itemId]);
                $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
                if (!$cur) {
                    throw new RuntimeException('This item no longer exists. Reload the page.');
                }
            }
            // On edit, only check when the name or category actually changes, so an item that already
            // has a twin (from before this check existed) can still be edited in place.
            $keyChanged = true;
            if ($cur && mb_strtolower(rh_clean_text($cur['item_name'])) === mb_strtolower($name) && (string)$cur['category'] === $category) {
                $keyChanged = false;
            }
            $dup = $keyChanged
                ? rh_find_duplicate($pdo, $table, 'item_name', $name, ['category' => $category], $action === 'update' ? $itemId : null)
                : null;
            if ($dup) {
                throw new RuntimeException('A ' . ($menu_type === 'food' ? 'food' : 'drink') . ' item called "' . $dup['value'] . '" already exists in "' . $category . '". Use a different name or edit the existing one.');
            }

            // New items go to the end of their category; an edit keeps its place unless it changes category.
            if ($cur && (string)$cur['category'] === $category) {
                $displayOrder = (int)$cur['display_order'];
            } else {
                $ord = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 FROM `{$table}` WHERE category = ?");
                $ord->execute([$category]);
                $displayOrder = (int)$ord->fetchColumn();
            }

            $pdo->beginTransaction();
            if ($action === 'add') {
                if ($menu_type === 'food') {
                    $pdo->prepare("INSERT INTO food_menu (item_name, description, price, category, is_available, display_order, station, show_pos, show_room_service) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$name, $description, $price, $category, $isAvail, $displayOrder, $station, $showPos, $showRs]);
                } else {
                    $pdo->prepare("INSERT INTO drink_menu (item_name, description, price, category, is_available, display_order, tags, station, show_pos, show_room_service) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$name, $description, $price, $category, $isAvail, $displayOrder, $tags, $station, $showPos, $showRs]);
                }
                $itemId = (int)$pdo->lastInsertId();
                $old = null;
            } else {
                if ($menu_type === 'food') {
                    $pdo->prepare("UPDATE food_menu SET item_name = ?, description = ?, price = ?, category = ?, is_available = ?, display_order = ?, station = ?, show_pos = ?, show_room_service = ? WHERE id = ?")
                        ->execute([$name, $description, $price, $category, $isAvail, $displayOrder, $station, $showPos, $showRs, $itemId]);
                } else {
                    $pdo->prepare("UPDATE drink_menu SET item_name = ?, description = ?, price = ?, category = ?, is_available = ?, display_order = ?, tags = ?, station = ?, show_pos = ?, show_room_service = ? WHERE id = ?")
                        ->execute([$name, $description, $price, $category, $isAvail, $displayOrder, $tags, $station, $showPos, $showRs, $itemId]);
                }
                $old = ['item_name' => $cur['item_name'], 'category' => $cur['category']];
            }

            // The till's copy of this item (price, station, where it shows, barcode) and its stock link.
            $pos = rh_menu_pos_sync_item($pdo, $menu_type, [
                'item_name' => $name, 'description' => $description, 'price' => $price, 'category' => $category,
                'station' => $station,
                // Edited without touching availability: leave the till's own setting (it may have been switched off there).
                'is_available' => ($cur && (int)$cur['is_available'] === $isAvail) ? null : $isAvail,
                'display_order' => $displayOrder,
                'show_pos' => $showPos, 'show_room_service' => $showRs, 'tags' => $tags,
            ], $old, $barcode);

            $stockMsg = '';
            if ($stockOn && !empty($_POST['recipe_changed'])) {
                $recipeLines = json_decode((string)($_POST['recipe_lines'] ?? '[]'), true);
                if (!is_array($recipeLines)) {
                    $recipeLines = [];
                }
                if ($recipeLines) {
                    $saved = rh_menu_recipe_save($pdo, $pos['id'], $pos['slug'], null, null, $recipeLines, (int)$user['id']);
                    $stockMsg = ' Stock link saved (' . $saved['lines_written'] . ' stock item' . ($saved['lines_written'] === 1 ? '' : 's') . ').';
                } else {
                    $removed = rh_menu_recipe_clear($pdo, $pos['id'], $pos['slug']);
                    $stockMsg = $removed ? ' Stock link removed.' : '';
                }
            }
            $pdo->commit();
            $message = ($action === 'add' ? 'Menu item added.' : 'Menu item updated.') . $stockMsg;
            $jsonExtra = ['pos_id' => $pos['id']];
        } elseif ($action === 'delete') {
            $itemId = (int)($_POST['id'] ?? 0);
            $rowStmt = $pdo->prepare("SELECT item_name, category FROM `{$table}` WHERE id = ?");
            $rowStmt->execute([$itemId]);
            $webRow = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$webRow) {
                throw new RuntimeException('This item is already gone. Reload the page.');
            }
            $pos = rh_menu_pos_find($pdo, (string)$webRow['item_name'], (string)$webRow['category']);
            // Another website row with the same name + category shares the till row: leave the till row alone.
            $twins = 0;
            foreach (['food_menu', 'drink_menu'] as $t) {
                $tw = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE LOWER(item_name) = LOWER(?) AND LOWER(category) = LOWER(?)" . ($t === $table ? ' AND id <> ?' : ''));
                $tw->execute($t === $table ? [$webRow['item_name'], $webRow['category'], $itemId] : [$webRow['item_name'], $webRow['category']]);
                $twins += (int)$tw->fetchColumn();
            }

            $pdo->beginTransaction();
            $mode = 'deleted';
            $res = ['mode' => 'deleted', 'open_lines' => 0, 'deals' => 0];
            if ($pos && $twins === 0) {
                $res = rh_menu_pos_remove($pdo, $pos['id'], $pos['slug']);
                $mode = $res['mode'];
            }
            if ($mode === 'blocked') {
                $pdo->rollBack();
                throw new RuntimeException('This item is on ' . $res['open_lines'] . ' open order line(s). Close or settle those orders first, then delete it (or mark it unavailable now).');
            }
            if ($mode === 'archived') {
                $pdo->prepare("UPDATE `{$table}` SET is_available = 0 WHERE id = ?")->execute([$itemId]);
                $pdo->commit();
                $message = 'This item has sales history, so it cannot be deleted. It has been marked unavailable and hidden from the till and room service instead.';
            } else {
                $pdo->prepare("DELETE FROM `{$table}` WHERE id = ?")->execute([$itemId]);
                $pdo->commit();
                $message = 'Menu item deleted.' . ($pos && $twins === 0 ? ' Its stock link was removed too.' : '') . ($res['deals'] > 0 ? ' (' . $res['deals'] . ' deal(s) updated.)' : '');
            }
        } elseif ($action === 'toggle_availability') {
            $itemId = (int)($_POST['id'] ?? 0);
            $rowStmt = $pdo->prepare("SELECT item_name, category, is_available FROM `{$table}` WHERE id = ?");
            $rowStmt->execute([$itemId]);
            $webRow = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$webRow) {
                throw new RuntimeException('This item no longer exists. Reload the page.');
            }
            $newAvail = ((int)$webRow['is_available'] === 1) ? 0 : 1;
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE `{$table}` SET is_available = ? WHERE id = ?")->execute([$newAvail, $itemId]);
            $pos = rh_menu_pos_find($pdo, (string)$webRow['item_name'], (string)$webRow['category']);
            if ($pos) {
                $pdo->prepare("UPDATE menu_items SET is_available = ? WHERE id = ?")->execute([$newAvail, $pos['id']]);
            }
            $pdo->commit();
            $message = $newAvail ? 'Item is available again.' : 'Item marked unavailable: hidden from the till, room service and the guest menu.';
            $jsonExtra = ['is_available' => $newAvail];
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) {
            $error = $e->getMessage();
        } else {
            error_log('menu-management: ' . $e->getMessage());
            $error = 'Something went wrong and nothing was changed. Please try again.';
        }
    }
    if ($menuLockName !== '') {
        try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($menuLockName) . ')'); } catch (Throwable $e2) {}
    }
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['success' => $error === '', 'message' => $error !== '' ? $error : $message], $error === '' ? $jsonExtra : []));
        exit;
    }
}

// Fetch all menu items grouped by category
$food_items = [];
$drink_items = [];
try {
    $food_items = $pdo->query("SELECT * FROM food_menu ORDER BY category ASC, display_order ASC, item_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $drink_items = $pdo->query("SELECT * FROM drink_menu ORDER BY category ASC, display_order ASC, item_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('menu-management fetch: ' . $e->getMessage());
    $error = 'Could not load the menu items. Please reload the page.';
}

// Every item here needs a till copy (the stock link and the till work from it). Insert-only, idempotent.
try {
    rh_menu_pos_backfill($pdo, ['food' => $food_items, 'drink' => $drink_items]);
} catch (Throwable $e) {
    error_log('menu-management: till sync: ' . $e->getMessage());
}
$posMap = [];
try {
    $posMap = rh_menu_pos_map($pdo);
} catch (Throwable $e) {
    error_log('menu-management: till map: ' . $e->getMessage());
}

// Stock links (recipes) of every item: one query, keyed by till item id + category slug.
$stockReady = function_exists('ensureStockTablesExist') && ensureStockTablesExist();
$recipeBy = [];
if ($stockReady) {
    try {
        $rows = $pdo->query(
            "SELECT sr.menu_item_id, sr.menu_type, i.name, i.unit, i.pack_name, i.pack_size, i.cost_per_unit,
                    ri.quantity_per_portion, ri.yield_percent
               FROM stock_recipes sr
               JOIN stock_recipe_ingredients ri ON ri.recipe_id = sr.id
               JOIN stock_ingredients i ON i.id = ri.ingredient_id
              ORDER BY i.name"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $recipeBy[$r['menu_item_id'] . '|' . $r['menu_type']][] = $r;
        }
    } catch (Throwable $e) {
        error_log('menu-management: recipes: ' . $e->getMessage());
    }
}

$currencySymbol = (string)getSetting('currency_symbol');
$stationLabels = ['kitchen' => 'Kitchen', 'bar' => 'Bar', 'coffee_bar' => 'Coffee bar'];

// Category lists for the item form: the usual ones plus anything already in use.
$groupItems = static function (array $items): array {
    $g = [];
    foreach ($items as $item) {
        $g[$item['category']][] = $item;
    }
    ksort($g, SORT_NATURAL | SORT_FLAG_CASE);
    return $g;
};
$grouped_food = $groupItems($food_items);
$grouped_drinks = $groupItems($drink_items);
$food_categories = array_values(array_unique(array_merge(['Breakfast', 'Lunch', 'Dinner', 'Snacks', 'Room Service'], array_keys($grouped_food))));
sort($food_categories, SORT_NATURAL | SORT_FLAG_CASE);
$drink_categories = array_values(array_unique(array_merge(['Coffee', 'Tea', 'Juice', 'Cocktails', 'Mocktails', 'Beer', 'Wine', 'Spirits', 'Soft Drinks', 'Room Service'], array_keys($grouped_drinks))));
sort($drink_categories, SORT_NATURAL | SORT_FLAG_CASE);

// Filter dropdown: every category that has items, with its count.
$filterCats = [];
foreach (array_merge($food_items, $drink_items) as $it) {
    $filterCats[$it['category']] = ($filterCats[$it['category']] ?? 0) + 1;
}
ksort($filterCats, SORT_NATURAL | SORT_FLAG_CASE);

/** One menu tab: a card per category with a readable table of its items. */
$mmRenderTab = static function (string $tab, array $grouped) use ($posMap, $recipeBy, $currencySymbol, $stationLabels, $fb_menu_posting_on, $fb_share_attrs): void {
    $menuType = $tab === 'food' ? 'food' : 'drink';
    if (!$grouped) {
        echo '<div class="empty-state mm-empty-all"><p>No ' . ($tab === 'food' ? 'food' : 'drink') . ' items yet. Use "Add item" to create the first one.</p></div>';
        return;
    }
    foreach ($grouped as $category => $items) {
        $catId = $tab . '-cat-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$category));
        ?>
        <div class="category-section" id="<?= htmlspecialchars($catId) ?>" data-cat="<?= htmlspecialchars((string)$category, ENT_QUOTES) ?>">
            <h3 class="category-header">
                <span><?= htmlspecialchars((string)$category) ?> <span class="cat-count" data-total="<?= count($items) ?>">(<?= count($items) ?>)</span></span>
                <button type="button" class="mm-btn mm-btn-sm" onclick="mmOpenAdd('<?= $tab ?>', this.closest('.category-section').dataset.cat)">Add item here</button>
            </h3>
            <div class="table-responsive">
                <table class="menu-table">
                    <thead>
                        <tr>
                            <th class="drag-col" title="Drag to reorder"></th>
                            <th>Item</th>
                            <th>Price (<?= htmlspecialchars($currencySymbol) ?>)</th>
                            <th>Shown in</th>
                            <th>Uses from stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item):
                            $pos = $posMap[rh_menu_pos_key((string)$item['item_name'], (string)$item['category'])] ?? null;
                            $lines = $pos ? ($recipeBy[$pos['id'] . '|' . $pos['slug']] ?? []) : [];
                            $cost = 0.0;
                            foreach ($lines as $l) {
                                $cost += ((float)$l['quantity_per_portion'] / rh_stock_yield_factor((float)$l['yield_percent'])) * (float)$l['cost_per_unit'];
                            }
                            $price = (float)$item['price'];
                            $showPos = !isset($item['show_pos']) || (int)$item['show_pos'] === 1;
                            $showRs = !isset($item['show_room_service']) || (int)$item['show_room_service'] === 1;
                            $avail = (int)$item['is_available'] === 1;
                            $station = $item['station'] ?? ($menuType === 'food' ? 'kitchen' : 'bar');
                            $barcode = $pos && $pos['barcode'] !== null ? (string)$pos['barcode'] : '';
                            $data = [
                                'id' => (int)$item['id'], 'type' => $tab, 'name' => $item['item_name'], 'category' => $item['category'],
                                'description' => (string)($item['description'] ?? ''), 'price' => $price, 'tags' => (string)($item['tags'] ?? ''),
                                'station' => $station, 'show_pos' => $showPos ? 1 : 0, 'show_rs' => $showRs ? 1 : 0, 'avail' => $avail ? 1 : 0,
                                'barcode' => $barcode, 'pos_id' => $pos ? $pos['id'] : 0, 'slug' => $pos ? $pos['slug'] : '',
                            ];
                            $search = mb_strtolower($item['item_name'] . ' ' . $item['category'] . ' ' . $barcode);
                            ?>
                            <tr id="<?= $menuType ?>-row-<?= (int)$item['id'] ?>" class="mm-row" data-tab="<?= $tab ?>" data-cat="<?= htmlspecialchars((string)$item['category'], ENT_QUOTES) ?>"
                                data-search="<?= htmlspecialchars($search, ENT_QUOTES) ?>" data-rs="<?= $showRs ? 1 : 0 ?>" data-avail="<?= $avail ? 1 : 0 ?>" data-stock="<?= $lines ? 1 : 0 ?>"
                                data-item="<?= htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>">
                                <td class="drag-col">
                                    <span class="drag-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>
                                </td>
                                <td class="mm-c-name" data-label="Item">
                                    <strong class="mm-item-name"><?= htmlspecialchars($item['item_name']) ?></strong>
                                    <?php if (trim((string)($item['description'] ?? '')) !== ''): ?>
                                        <span class="mm-sub"><?= htmlspecialchars(mb_strimwidth((string)$item['description'], 0, 90, '…')) ?></span>
                                    <?php endif; ?>
                                    <?php if ($barcode !== ''): ?>
                                        <span class="mm-sub">Barcode <?= htmlspecialchars($barcode) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['tags'])): ?>
                                        <span class="mm-sub"><?= htmlspecialchars((string)$item['tags']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="mm-c-price" data-label="Price"><?= $price > 0 ? number_format($price, 2) : '<span class="mm-tag">Free</span>' ?></td>
                                <td data-label="Shown in">
                                    <span class="mm-tag"><?= htmlspecialchars($stationLabels[$station] ?? 'Kitchen') ?></span>
                                    <?php if ($showPos): ?><span class="mm-tag">Till</span><?php endif; ?>
                                    <?php if ($showRs): ?><span class="mm-tag">Room service</span><?php endif; ?>
                                    <?php if (!$showPos && !$showRs): ?><span class="mm-tag mm-tag-off">Website only</span><?php endif; ?>
                                    <?php if ($avail && $pos && (int)$pos['is_available'] === 0): ?><span class="mm-tag mm-tag-off" title="Switched off from the till (86). Marking it unavailable and available again here puts it back.">Off at the till</span><?php endif; ?>
                                </td>
                                <td data-label="Uses from stock" class="mm-c-stock">
                                    <?php if ($lines): ?>
                                        <?php foreach (array_slice($lines, 0, 2) as $l): ?>
                                            <span class="mm-uses"><?= htmlspecialchars($l['name']) ?>: <?= htmlspecialchars(rh_recipe_fmt_qty((float)$l['quantity_per_portion'])) ?> <?= htmlspecialchars($l['unit']) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($lines) > 2): ?><span class="mm-sub">+<?= count($lines) - 2 ?> more</span><?php endif; ?>
                                        <span class="mm-sub">Cost <?= number_format($cost, 2) ?><?= $price > 0 ? ' (' . round($cost / $price * 100) . '% of price)' : '' ?></span>
                                    <?php else: ?>
                                        <span class="mm-sub">Not linked to stock</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status">
                                    <button type="button" class="mm-pill <?= $avail ? 'is-on' : 'is-off' ?>" onclick="mmToggle(<?= (int)$item['id'] ?>, '<?= $tab ?>')"
                                        title="<?= $avail ? 'Click to mark unavailable' : 'Click to mark available' ?>"><?= $avail ? 'Available' : 'Unavailable' ?></button>
                                </td>
                                <td class="actions-cell">
                                    <div class="action-buttons">
                                        <button type="button" class="btn-action btn-edit" onclick="mmOpenEdit(this)">Edit</button>
                                        <button type="button" class="btn-action btn-delete" onclick="mmConfirmDelete(<?= (int)$item['id'] ?>, '<?= $tab ?>')">Delete</button>
                                        <?php if ($fb_menu_posting_on): ?>
                                            <button type="button" class="btn-action btn-facebook"
                                                onclick='openFbMenuModal(<?= (int)$item["id"] ?>, <?= htmlspecialchars(json_encode($item["item_name"]), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode((float)$item["price"]), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($item["category"] ?? ""), ENT_QUOTES, "UTF-8") ?>, "<?= $menuType ?>")'
                                                <?= $fb_share_attrs ?>>
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
        </div>
        <?php
    }
};
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

    <div class="content mm-restaurant">
        <div class="page-header">
            <h2 class="page-title">Menu Management</h2>
            <button type="button" class="btn-add" onclick="mmOpenAdd(mmActiveTab())">Add item</button>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <?php if ($fb_menu_posting_on): ?>
            <!-- Share Full Menu on Facebook banner -->
            <div class="rh-share-bar">
                <i class="fab fa-facebook-f rh-share-bar__icon" aria-hidden="true"></i>
                <div class="rh-share-bar__text">
                    <div class="rh-share-bar__title">Share our restaurant menu on Facebook</div>
                    <div class="rh-share-bar__sub">Post a promotional update featuring your food &amp; drink highlights to your Facebook Page.</div>
                </div>
                <button type="button" class="rh-share-bar__btn btn-facebook"
                    onclick="openFbMenuShareAllModal()"<?php echo facebookShareButtonAttrs('Share the full menu on Facebook'); ?>>
                    <i class="fab fa-facebook-f"></i> Share Full Menu
                </button>
            </div>
        <?php endif; ?>

        <!-- Find an item: filters both tabs as you type -->
        <div class="mm-toolbar" id="mmToolbar">
            <div class="mm-search">
                <label for="mmSearch" class="mm-sr">Search menu items</label>
                <input type="search" id="mmSearch" placeholder="Search by item name, category or barcode" autocomplete="off" aria-controls="mmResults">
                <button type="button" class="mm-search-clear" id="mmSearchClear" onclick="mmClearFilters()" aria-label="Clear search" hidden>&times;</button>
            </div>
            <select id="mmCatFilter" aria-label="Filter by category">
                <option value="">All categories</option>
                <?php foreach ($filterCats as $c => $n): ?>
                    <option value="<?= htmlspecialchars((string)$c, ENT_QUOTES) ?>"><?= htmlspecialchars((string)$c) ?> (<?= (int)$n ?>)</option>
                <?php endforeach; ?>
            </select>
            <select id="mmShowFilter" aria-label="Filter by status">
                <option value="">All items</option>
                <option value="avail">Available only</option>
                <option value="unavail">Unavailable only</option>
                <option value="rs">Room service items</option>
                <option value="nostock">Not linked to stock</option>
            </select>
            <span class="mm-count" id="mmCount" aria-live="polite"></span>
        </div>

        <!-- Menu Type Tabs -->
        <div class="menu-type-tabs" role="tablist">
            <button type="button" role="tab" id="mmTabFood" class="menu-type-tab <?php echo $current_tab === 'food' ? 'active' : ''; ?>" onclick="switchTab('food')">
                Food <span class="mm-tab-n" data-tab-count="food"><?= count($food_items) ?></span>
            </button>
            <button type="button" role="tab" id="mmTabDrinks" class="menu-type-tab <?php echo $current_tab === 'drinks' ? 'active' : ''; ?>" onclick="switchTab('drinks')">
                Drinks <span class="mm-tab-n" data-tab-count="drinks"><?= count($drink_items) ?></span>
            </button>
        </div>

        <div id="mmCats" hidden data-food="<?= htmlspecialchars(json_encode($food_categories), ENT_QUOTES, 'UTF-8') ?>" data-drinks="<?= htmlspecialchars(json_encode($drink_categories), ENT_QUOTES, 'UTF-8') ?>"></div>

        <div id="mmResults">
            <div class="mm-nomatch" id="mmNoMatch" hidden>
                <p id="mmNoMatchText">No items match.</p>
                <p><button type="button" class="mm-btn mm-btn-sm" id="mmNoMatchSwitch" hidden></button>
                    <button type="button" class="mm-btn mm-btn-sm" onclick="mmClearFilters()">Clear search and filters</button></p>
            </div>

            <!-- Food Menu Tab Content -->
            <div class="tab-content <?php echo $current_tab === 'food' ? 'active' : ''; ?>" id="food-tab">
                <?php $mmRenderTab('food', $grouped_food); ?>
            </div>

            <!-- Drinks Menu Tab Content -->
            <div class="tab-content <?php echo $current_tab === 'drinks' ? 'active' : ''; ?>" id="drinks-tab">
                <?php $mmRenderTab('drinks', $grouped_drinks); ?>
            </div>
        </div>

    <!-- Add / edit one menu item (details, where it shows, what it uses from stock) -->
    <div class="mm-modal" id="mmItemModal" role="dialog" aria-modal="true" aria-labelledby="mmItemTitle">
        <div class="mm-modal-card">
            <div class="mm-modal-head">
                <h3 id="mmItemTitle">Add menu item</h3>
                <button type="button" class="mm-modal-close" onclick="mmCloseItem()" aria-label="Close">&times;</button>
            </div>
            <form method="POST" id="mmItemForm" novalidate>
                <div class="mm-modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <input type="hidden" name="action" id="mmAction" value="add">
                    <input type="hidden" name="id" id="mmId" value="">
                    <input type="hidden" name="menu_type" id="mmType" value="food">

                    <div class="mm-field">
                        <label for="mmName">Item name <span class="mm-req">*</span></label>
                        <input type="text" name="name" id="mmName" maxlength="200" required autocomplete="off">
                    </div>
                    <div class="mm-field-row">
                        <div class="mm-field">
                            <label for="mmCategory">Category <span class="mm-req">*</span></label>
                            <select name="category" id="mmCategory" required></select>
                            <input type="text" name="new_category" id="mmNewCategory" maxlength="100" placeholder="New category name" hidden>
                        </div>
                        <div class="mm-field">
                            <label for="mmPrice">Price (<?= htmlspecialchars($currencySymbol) ?>) <span class="mm-req">*</span></label>
                            <input type="number" name="price" id="mmPrice" step="0.01" min="0" inputmode="decimal" required>
                            <label class="mm-checkbox-line mm-free"><input type="checkbox" name="free_item" id="mmFree" value="1"> <span>This item is free</span></label>
                        </div>
                    </div>
                    <div class="mm-field">
                        <label for="mmStation">Prepared at</label>
                        <select name="station" id="mmStation">
                            <option value="kitchen">Kitchen</option>
                            <option value="bar">Bar</option>
                            <option value="coffee_bar">Coffee bar</option>
                        </select>
                    </div>
                    <fieldset class="mm-field mm-where">
                        <legend>Where it shows</legend>
                        <label class="mm-checkbox-line"><input type="checkbox" name="is_available" id="mmAvail" value="1" checked> <span>Available (untick to hide it everywhere)</span></label>
                        <label class="mm-checkbox-line"><input type="checkbox" name="show_pos" id="mmShowPos" value="1" checked> <span>On the till (POS)</span></label>
                        <label class="mm-checkbox-line"><input type="checkbox" name="show_room_service" id="mmShowRs" value="1" checked> <span>In room service</span></label>
                    </fieldset>
                    <div class="mm-field">
                        <label for="mmDesc">Description <span class="mm-opt">(optional)</span></label>
                        <textarea name="description" id="mmDesc" rows="2" maxlength="2000"></textarea>
                    </div>
                    <div class="mm-field-row">
                        <div class="mm-field" id="mmTagsWrap" hidden>
                            <label for="mmTags">Tags <span class="mm-opt">(optional, comma separated)</span></label>
                            <input type="text" name="tags" id="mmTags" maxlength="255" placeholder="e.g. Hot, Cold, Premium">
                        </div>
                        <div class="mm-field">
                            <label for="mmBarcode">Barcode <span class="mm-opt">(optional)</span></label>
                            <input type="text" name="barcode" id="mmBarcode" maxlength="100" autocomplete="off" placeholder="Scan or type">
                        </div>
                    </div>

                    <!-- What one sale uses from stock -->
                    <section class="mm-stock" id="mmStock" aria-labelledby="mmStockTitle">
                        <h4 id="mmStockTitle">What it uses from stock</h4>
                        <p class="mm-hint">Each time one is sold, stock goes down by this amount. Selling one sausage from a packet of 6? Pick the packet, choose "pieces" and type 1 (the sums are done for you). How many pieces are in one packet comes from the stock item and is changed in Stock only, never here. Stock counted in kg or litres? Type whole grams or ml (250 g, 25 ml) and the kg or litres are worked out. Leave this empty if the item does not use tracked stock.</p>
                        <div id="mmStockLines"></div>
                        <div class="mm-picker">
                            <div class="mm-pk-row">
                                <label class="mm-pk-f"><span>Category</span>
                                    <select id="mmIngCat" aria-label="Stock category"><option value="">All categories</option></select></label>
                                <label class="mm-pk-f"><span>Search</span>
                                    <input type="search" id="mmIngSearch" placeholder="Type to find a stock item…" autocomplete="off" aria-label="Search stock items"></label>
                            </div>
                            <label class="mm-pk-f"><span>Stock item</span>
                                <select id="mmIngPick" aria-label="Stock item"><option value="">Choose a stock item…</option></select></label>
                            <p class="mm-hint mm-pk-none" id="mmIngNone" role="status" hidden>No stock items match</p>
                            <p class="mm-unitline" id="mmIngUnitLine" aria-live="polite" hidden></p>
                        </div>
                        <div class="mm-stock-add">
                            <input type="number" id="mmIngQty" step="any" min="0" inputmode="decimal" placeholder="Qty" aria-label="Quantity used per item sold">
                            <select id="mmIngUnit" aria-label="Unit"></select>
                            <button type="button" class="mm-btn" id="mmIngAdd" onclick="mmStockAdd()">Add</button>
                        </div>
                        <div class="mm-qtytools" id="mmIngTools" hidden>
                            <span class="mm-quick" role="group" aria-label="Quick quantity">
                                <button type="button" class="mm-btn mm-btn-sm" data-q="1" aria-label="Use 1">1</button>
                                <button type="button" class="mm-btn mm-btn-sm" data-q="2" aria-label="Use 2">2</button>
                                <button type="button" class="mm-btn mm-btn-sm" data-q="3" aria-label="Use 3">3</button>
                                <button type="button" class="mm-btn mm-btn-sm" data-q="0.25" aria-label="Use one quarter">&frac14;</button>
                                <button type="button" class="mm-btn mm-btn-sm" data-q="0.5" aria-label="Use one half">&frac12;</button>
                                <button type="button" class="mm-btn mm-btn-sm" data-q="0.75" aria-label="Use three quarters">&frac34;</button>
                            </span>
                            <span class="mm-per" id="mmIngPerWrap" hidden></span>
                        </div>
                        <p class="mm-preview" id="mmIngPreview" aria-live="polite"></p>
                        <p class="mm-hint"><button type="button" class="mm-linkbtn" onclick="openIngredientModal()">Stock item not listed? Create it</button></p>
                        <div class="mm-stock-totals" id="mmStockTotals" hidden>
                            <span>Stock cost per item sold: <strong id="mmCostTotal">-</strong></span>
                            <span id="mmCostPct"></span>
                        </div>
                    </section>
                </div>
                <div class="mm-modal-foot">
                    <button type="button" class="mm-btn" onclick="mmCloseItem()">Cancel</button>
                    <button type="submit" id="mmSaveBtn" class="mm-btn mm-btn-primary">Save item</button>
                </div>
            </form>
        </div>
    </div>

    <!-- New Ingredient Modal -->
    <div class="mm-modal" id="ingredientModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3>New stock item</h3>
                <button type="button" class="mm-modal-close" onclick="closeIngredientModal()" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body">
                <div class="mm-field">
                    <label for="ig-name">Name <span class="mm-req">*</span></label>
                    <input type="text" id="ig-name" required>
                </div>
                <div class="mm-field-row">
                    <div class="mm-field">
                        <label for="ig-category">Category</label>
                        <input type="text" id="ig-category" placeholder="e.g., Proteins, Dairy" value="General">
                    </div>
                    <div class="mm-field">
                        <label for="ig-unit">Counted in <span class="mm-req">*</span></label>
                        <select id="ig-unit">
                            <option value="each">each (piece)</option>
                            <option value="g">g (gram)</option>
                            <option value="kg">kg (kilogram)</option>
                            <option value="ml">ml (millilitre)</option>
                            <option value="L">L (litre)</option>
                        </select>
                    </div>
                </div>
                <div class="mm-field-row">
                    <div class="mm-field">
                        <label for="ig-cost">Cost per unit</label>
                        <input type="number" id="ig-cost" step="0.01" value="0">
                    </div>
                    <div class="mm-field">
                        <label for="ig-yield">Yield %</label>
                        <input type="number" id="ig-yield" min="1" max="100" value="100">
                    </div>
                </div>
                <p class="mm-hint">Pack sizes (for example a pack of 10) are set in Stock &raquo; Ingredients.</p>
            </div>
            <div class="mm-modal-foot">
                <button type="button" class="mm-btn" onclick="closeIngredientModal()">Cancel</button>
                <button type="button" class="mm-btn mm-btn-primary" onclick="saveNewIngredient()">Create</button>
            </div>
        </div>
    </div>

    </div><!-- /.mm-restaurant -->

    <script src="js/admin-form-guard.js?v=<?php echo @filemtime(__DIR__ . '/js/admin-form-guard.js'); ?>"></script>
    <script>
        /* ============================================================
           Menu Management page script. Top-level functions are used by the inline
           onclick handlers above (the admin SPA re-exports them after in-place navigation).
           ============================================================ */
        const MM_CURRENCY = <?= json_encode($currencySymbol) ?>;
        const MM_CSRF = <?= json_encode($csrf_token) ?>;
        const MM_RECIPE_API = 'api/menu-recipe.php';
        const MM_CATS = {
            food: [],
            drinks: []
        };

        function mmReadCats(root) {
            const el = (root || document).getElementById('mmCats');
            if (!el) return;
            try {
                MM_CATS.food = JSON.parse(el.getAttribute('data-food') || '[]');
                MM_CATS.drinks = JSON.parse(el.getAttribute('data-drinks') || '[]');
            } catch (e) {}
        }
        const MM_FILTER = {
            q: '',
            cat: '',
            show: ''
        };
        const MM_STATE = {
            tab: <?= json_encode($current_tab) ?>,
            ing: null, // stock items (loaded once, when a form is first opened)
            lines: [], // stock link lines being edited
            changed: false, // stock section touched (only then is it sent on save)
            ready: false, // existing stock link loaded, so saving it is safe
            editing: null, // the row being edited, or null when adding
            busy: false
        };

        function mmEl(id) {
            return document.getElementById(id);
        }

        function mmFmtMoney(n) {
            const v = Number(n) || 0;
            return MM_CURRENCY + ' ' + v.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function mmFmtQty(n, max) {
            max = max === undefined ? 4 : max;
            let s = Number(n).toFixed(max);
            if (s.indexOf('.') !== -1) s = s.replace(/0+$/, '').replace(/\.$/, '');
            return s === '' || s === '-0' ? '0' : s;
        }

        function mmActiveTab() {
            return MM_STATE.tab;
        }

        /* ---------- Tabs ---------- */
        function switchTab(tab) {
            MM_STATE.tab = tab === 'drinks' ? 'drinks' : 'food';
            const url = new URL(window.location);
            url.searchParams.set('tab', MM_STATE.tab);
            window.history.replaceState({}, '', url);
            mmEl('mmTabFood').classList.toggle('active', MM_STATE.tab === 'food');
            mmEl('mmTabDrinks').classList.toggle('active', MM_STATE.tab === 'drinks');
            mmEl('food-tab').classList.toggle('active', MM_STATE.tab === 'food');
            mmEl('drinks-tab').classList.toggle('active', MM_STATE.tab === 'drinks');
            mmApplyFilters(false);
        }

        /* ---------- Search and filters ---------- */
        function mmRowMatches(tr, tokens) {
            const hay = tr.dataset.search || '';
            for (let i = 0; i < tokens.length; i++) {
                if (hay.indexOf(tokens[i]) === -1) return false;
            }
            if (MM_FILTER.cat && tr.dataset.cat !== MM_FILTER.cat) return false;
            switch (MM_FILTER.show) {
                case 'avail':
                    return tr.dataset.avail === '1';
                case 'unavail':
                    return tr.dataset.avail === '0';
                case 'rs':
                    return tr.dataset.rs === '1';
                case 'nostock':
                    return tr.dataset.stock === '0';
            }
            return true;
        }

        function mmApplyFilters(scroll) {
            const tokens = MM_FILTER.q.toLowerCase().split(/\s+/).filter(Boolean);
            const filtering = tokens.length > 0 || MM_FILTER.cat !== '' || MM_FILTER.show !== '';
            const shown = {
                food: 0,
                drinks: 0
            };
            const total = {
                food: 0,
                drinks: 0
            };
            document.querySelectorAll('#mmResults .mm-row').forEach(function(tr) {
                const tab = tr.dataset.tab;
                total[tab]++;
                const ok = mmRowMatches(tr, tokens);
                tr.classList.toggle('mm-hide', !ok);
                if (ok) shown[tab]++;
            });
            document.querySelectorAll('#mmResults .category-section').forEach(function(sec) {
                const rows = sec.querySelectorAll('.mm-row');
                let n = 0;
                rows.forEach(function(r) {
                    if (!r.classList.contains('mm-hide')) n++;
                });
                sec.classList.toggle('mm-hide', n === 0);
                const c = sec.querySelector('.cat-count');
                if (c) c.textContent = filtering ? '(' + n + ' of ' + rows.length + ')' : '(' + rows.length + ')';
            });
            document.querySelectorAll('[data-tab-count]').forEach(function(b) {
                const t = b.getAttribute('data-tab-count');
                b.textContent = filtering ? shown[t] + ' of ' + total[t] : String(total[t]);
            });
            mmEl('mmResults').classList.toggle('mm-filtering', filtering);
            mmEl('mmSearchClear').hidden = !(MM_FILTER.q !== '');

            const cur = MM_STATE.tab;
            const other = cur === 'food' ? 'drinks' : 'food';
            const box = mmEl('mmNoMatch');
            const sw = mmEl('mmNoMatchSwitch');
            const noMatch = filtering && total[cur] > 0 && shown[cur] === 0;
            box.hidden = !noMatch;
            if (noMatch) {
                const label = MM_FILTER.q ? ' "' + MM_FILTER.q + '"' : '';
                mmEl('mmNoMatchText').textContent = 'No ' + (cur === 'food' ? 'food' : 'drink') + ' items match' + label + '.';
                if (shown[other] > 0) {
                    sw.hidden = false;
                    sw.textContent = 'Show ' + shown[other] + ' matching ' + (other === 'food' ? 'food' : 'drink') + ' item' + (shown[other] === 1 ? '' : 's');
                    sw.onclick = function() {
                        switchTab(other);
                    };
                } else {
                    sw.hidden = true;
                }
            }
            const all = total.food + total.drinks;
            const hits = shown.food + shown.drinks;
            mmEl('mmCount').textContent = filtering ? hits + ' of ' + all + (all === 1 ? ' item' : ' items') : all + (all === 1 ? ' item' : ' items');

            if (scroll) {
                // Results must land in view: bring the first match (or the no-match note) up under the toolbar.
                const target = noMatch ? box : document.querySelector('#mmResults .tab-content.active .mm-row:not(.mm-hide)');
                if (target) {
                    const r = target.getBoundingClientRect();
                    if (r.top < 60 || r.top > window.innerHeight * 0.75) {
                        const tb = mmEl('mmToolbar').getBoundingClientRect();
                        window.scrollTo({
                            top: Math.max(0, window.pageYOffset + r.top - Math.max(tb.height + 80, 120)),
                            behavior: 'smooth'
                        });
                    }
                }
            }
        }

        function mmClearFilters() {
            MM_FILTER.q = '';
            MM_FILTER.cat = '';
            MM_FILTER.show = '';
            mmEl('mmSearch').value = '';
            mmEl('mmCatFilter').value = '';
            mmEl('mmShowFilter').value = '';
            mmApplyFilters(true);
            mmEl('mmSearch').focus();
        }

        function mmInitFilters() {
            let timer = null;
            const s = mmEl('mmSearch');
            s.addEventListener('input', function() {
                MM_FILTER.q = s.value.trim();
                mmApplyFilters(false); // instant
                clearTimeout(timer);
                timer = setTimeout(function() {
                    mmApplyFilters(true);
                }, 250);
            });
            s.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    mmClearFilters();
                }
            });
            mmEl('mmCatFilter').addEventListener('change', function() {
                MM_FILTER.cat = this.value;
                mmApplyFilters(true);
            });
            mmEl('mmShowFilter').addEventListener('change', function() {
                MM_FILTER.show = this.value;
                mmApplyFilters(true);
            });
            const params = new URLSearchParams(window.location.search);
            if (params.get('jump') === 'room-service') {
                MM_FILTER.show = 'rs';
                mmEl('mmShowFilter').value = 'rs';
            }
            if (params.get('q')) {
                MM_FILTER.q = params.get('q');
                s.value = MM_FILTER.q;
            }
            mmApplyFilters(false);
        }

        /* ---------- Add / edit form ---------- */
        function mmFillCategories(tab, selected) {
            const sel = mmEl('mmCategory');
            sel.innerHTML = '';
            const first = document.createElement('option');
            first.value = '';
            first.textContent = 'Select category';
            sel.appendChild(first);
            const list = (MM_CATS[tab] || []).slice();
            if (selected && list.indexOf(selected) === -1) list.push(selected);
            list.forEach(function(c) {
                const o = document.createElement('option');
                o.value = c;
                o.textContent = c;
                sel.appendChild(o);
            });
            const n = document.createElement('option');
            n.value = '__new__';
            n.textContent = 'New category…';
            sel.appendChild(n);
            sel.value = selected || '';
            mmEl('mmNewCategory').hidden = true;
            mmEl('mmNewCategory').value = '';
        }

        function mmSyncFree() {
            const free = mmEl('mmFree').checked;
            const p = mmEl('mmPrice');
            if (free) p.value = '0';
            p.disabled = free;
            mmStockTotals();
        }

        function mmOpenAdd(tab, category) {
            mmOpenItem('add', tab === 'drinks' ? 'drinks' : 'food', {
                category: category || ''
            });
        }

        function mmOpenEdit(btn) {
            const tr = btn.closest('tr');
            let it = null;
            try {
                it = JSON.parse(tr.dataset.item);
            } catch (e) {
                it = null;
            }
            if (!it) return;
            mmOpenItem('update', it.type, it);
        }

        function mmOpenItem(mode, tab, it) {
            const form = mmEl('mmItemForm');
            if (window.RHFormGuard) RHFormGuard.clearErrors(form);
            MM_STATE.editing = mode === 'update' ? it : null;
            MM_STATE.lines = [];
            MM_STATE.changed = false;
            MM_STATE.ready = mode === 'add' || !it.pos_id; // nothing to load
            mmEl('mmItemTitle').textContent = mode === 'add' ? (tab === 'food' ? 'Add food item' : 'Add drink item') : 'Edit ' + (it.name || 'item');
            mmEl('mmAction').value = mode;
            mmEl('mmId').value = mode === 'update' ? it.id : '';
            mmEl('mmType').value = tab;
            mmEl('mmName').value = mode === 'update' ? it.name : '';
            mmFillCategories(tab, it.category || '');
            const free = mode === 'update' && Number(it.price) === 0;
            mmEl('mmFree').checked = free;
            mmEl('mmPrice').disabled = false;
            mmEl('mmPrice').value = mode === 'update' ? Number(it.price).toFixed(2) : '';
            mmSyncFree();
            mmEl('mmStation').value = mode === 'update' ? it.station : (tab === 'food' ? 'kitchen' : 'bar');
            mmEl('mmAvail').checked = mode === 'update' ? !!it.avail : true;
            mmEl('mmShowPos').checked = mode === 'update' ? !!it.show_pos : true;
            mmEl('mmShowRs').checked = mode === 'update' ? !!it.show_rs : true;
            mmEl('mmDesc').value = mode === 'update' ? it.description : '';
            mmEl('mmTags').value = mode === 'update' ? it.tags : '';
            mmEl('mmTagsWrap').hidden = tab !== 'drinks';
            mmEl('mmBarcode').value = mode === 'update' ? it.barcode : '';
            mmEl('mmSaveBtn').textContent = mode === 'add' ? 'Add item' : 'Save changes';
            mmEl('mmIngPick').value = '';
            mmEl('mmIngCat').value = '';
            mmEl('mmIngSearch').value = '';
            mmEl('mmIngQty').value = '';
            mmIngRenderPicker();
            mmIngChosen();
            mmStockRender();
            mmEl('mmItemModal').classList.add('open');
            document.body.style.overflow = 'hidden';
            setTimeout(function() {
                mmEl('mmName').focus();
            }, 50);
            const loading = mode === 'update' && it.pos_id;
            if (loading) {
                mmEl('mmSaveBtn').disabled = true;
                mmEl('mmIngPreview').textContent = 'Loading the stock link…';
            }
            mmLoadStock(mode === 'update' ? it : null).finally(function() {
                if (loading && MM_STATE.editing === it) {
                    mmEl('mmSaveBtn').disabled = false;
                    if (MM_STATE.ready) mmEl('mmIngPreview').textContent = '';
                }
            });
        }

        function mmCloseItem() {
            mmEl('mmItemModal').classList.remove('open');
            document.body.style.overflow = '';
        }

        async function mmLoadIngredients() {
            if (MM_STATE.ing) return MM_STATE.ing;
            const res = await fetch(MM_RECIPE_API + '?action=list_ingredients', {
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Could not load stock items');
            MM_STATE.ing = data.ingredients.map(function(i) {
                return {
                    id: parseInt(i.id, 10),
                    name: i.name,
                    category: i.category || 'General',
                    unit: i.unit,
                    cost: parseFloat(i.cost_per_unit) || 0,
                    stock: parseFloat(i.current_quantity) || 0,
                    pack_name: i.pack_name || '',
                    pack_size: parseFloat(i.pack_size) || 0,
                    pieces: parseFloat(i.pieces_per_unit) || 0
                };
            });
            const cats = [];
            MM_STATE.ing.forEach(function(i) {
                if (cats.indexOf(i.category) === -1) cats.push(i.category);
            });
            cats.sort(function(a, b) {
                return a.localeCompare(b);
            });
            const cs = mmEl('mmIngCat');
            cs.innerHTML = '<option value="">All categories</option>';
            cats.forEach(function(c) {
                const o = document.createElement('option');
                o.value = c;
                o.textContent = c;
                cs.appendChild(o);
            });
            mmIngRenderPicker();
            return MM_STATE.ing;
        }

        /* Money without forced decimals: MWK 15,300 (or MWK 2,550.5). */
        function mmFmtMoneyShort(n) {
            return MM_CURRENCY + ' ' + (Number(n) || 0).toLocaleString('en-US', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2
            });
        }

        /* A kg or litre item: staff type whole grams / ml (the server divides by 1000). Returns 'g', 'ml' or ''. Same words as rh_recipe_small_unit(). */
        function mmSmallOf(unit) {
            const u = (unit || '').trim();
            if (/^(kgs?|kilograms?|kilos?)$/i.test(u)) return 'g';
            if (/^(l|ltrs?|litres?|liters?)$/i.test(u)) return 'ml';
            return '';
        }

        /* Stock already counted in g or ml: whole numbers in its own unit, with the same quick buttons. Returns 'g', 'ml' or ''. */
        function mmNativeSmall(unit) {
            const u = (unit || '').trim();
            if (/^(g|gm|grams?)$/i.test(u)) return 'g';
            if (/^(mls?|millilit(re|er)s?)$/i.test(u)) return 'ml';
            return '';
        }

        /* Whole grams / ml, 1 to 1,000,000 (what the server accepts). */
        function mmSmallOk(q) {
            return q >= 1 && q <= 1000000 && q === Math.floor(q);
        }

        /* Quick buttons suited to the unit: g 50/100/250/500, ml 25/50/250/330, else 1/2/3 and a quarter, half, three-quarters. */
        const MM_FRAC = {
            '0.25': ['¼', 'Use one quarter'],
            '0.5': ['½', 'Use one half'],
            '0.75': ['¾', 'Use three quarters']
        };

        function mmQuickSet(tools, l) {
            const small = l.entry === 'small' ? mmSmallOf(l.unit) : (l.entry === 'base' ? mmNativeSmall(l.unit) : '');
            const vals = small === 'g' ? ['50', '100', '250', '500', '', ''] : (small === 'ml' ? ['25', '50', '250', '330', '', ''] : ['1', '2', '3', '0.25', '0.5', '0.75']);
            tools.querySelectorAll('[data-q]').forEach(function(b, i) {
                const v = vals[i] || '';
                b.hidden = v === '';
                if (v === '') return;
                b.setAttribute('data-q', v);
                b.textContent = MM_FRAC[v] ? MM_FRAC[v][0] : v;
                b.setAttribute('aria-label', MM_FRAC[v] ? MM_FRAC[v][1] : 'Use ' + v + (small ? ' ' + small : ''));
            });
        }

        /* "50 packets", "1 packet", "2 boxes", "12.5 kg" (short metric units and dozen stay as they are). */
        function mmPlural(unit, n) {
            const u = (unit || '').trim();
            if (Math.abs(n - 1) < 1e-9 || /s$/i.test(u) || /^(kg|g|gm|mg|l|ltr|ml|cl|lb|oz|dozen|dz|doz)$/i.test(u)) return u;
            return /(x|ch|sh)$/i.test(u) ? u + 'es' : u + 's';
        }

        function mmQtyUnit(n, unit, dp) {
            return mmFmtQty(n, dp) + ' ' + mmPlural(unit, n);
        }

        /* One dropdown line: "BBq sausage — 50 packets in stock (6 pieces each) · MWK 15,300 per packet". */
        function mmIngOptionText(i) {
            let t = i.name + ' — ' + mmQtyUnit(i.stock, i.unit, 4) + ' in stock';
            const per = mmPer(i);
            if (mmIsContainer(i.unit) && per > 0) t += ' (' + mmFmtQty(per, 2) + ' pieces each)';
            if (i.cost > 0) t += ' · ' + mmFmtMoneyShort(i.cost) + ' per ' + i.unit;
            return t;
        }

        /* The clear unit line shown once a stock item is picked: what it is counted in, what is in stock, what one costs. */
        function mmUnitLine(i) {
            const per = mmIsContainer(i.unit) ? mmPer(i) : 0;
            let t = i.name + ' is counted in ' + mmPlural(i.unit, 2) + '.';
            if (mmIsContainer(i.unit)) {
                t += per > 0 ? ' 1 ' + i.unit + ' = ' + mmFmtQty(per, 2) + ' pieces.' : ' Pieces in one ' + i.unit + ' is not set on this stock item, so single pieces cannot be used yet (set it in Stock).';
            }
            if (i.pack_size > 0) t += ' 1 ' + (i.pack_name || 'pack').toLowerCase() + ' = ' + mmQtyUnit(i.pack_size, i.unit, 3) + '.';
            t += ' In stock now: ' + mmQtyUnit(i.stock, i.unit, 4);
            if (per > 0) t += ' (' + mmFmtQty(i.stock * per, 2) + ' pieces)';
            t += '.';
            if (i.cost > 0) t += ' Cost ' + mmFmtMoneyShort(i.cost) + ' per ' + i.unit + (per > 0 ? ' (' + mmFmtMoneyShort(i.cost / per) + ' per piece)' : '') + '.';
            const sm = mmSmallOf(i.unit);
            if (sm) t += ' Type a whole number of ' + sm + ' (1,000 ' + sm + ' = 1 ' + i.unit + ').';
            return t;
        }

        /* Rebuild the Item dropdown from the Category choice and the typed search (name, any part, any case). */
        function mmIngRenderPicker() {
            const sel = mmEl('mmIngPick');
            const none = mmEl('mmIngNone');
            const keep = sel.value;
            const cat = mmEl('mmIngCat').value;
            const q = mmEl('mmIngSearch').value.trim().toLowerCase();
            const list = (MM_STATE.ing || []).filter(function(i) {
                return (!cat || i.category === cat) && (!q || i.name.toLowerCase().indexOf(q) !== -1);
            });
            sel.innerHTML = '';
            const ph = document.createElement('option');
            ph.value = '';
            ph.textContent = !MM_STATE.ing ? 'Loading stock items…' : (list.length ? 'Choose a stock item… (' + list.length + ')' : 'No stock items match');
            sel.appendChild(ph);
            let group = null;
            let groupCat = null;
            list.forEach(function(i) {
                if (!cat && i.category !== groupCat) {
                    group = document.createElement('optgroup');
                    group.label = i.category;
                    sel.appendChild(group);
                    groupCat = i.category;
                }
                const o = document.createElement('option');
                o.value = String(i.id);
                o.textContent = mmIngOptionText(i);
                o.title = o.textContent;
                (cat ? sel : group).appendChild(o);
            });
            none.hidden = list.length > 0 || !(MM_STATE.ing && MM_STATE.ing.length);
            if (keep && list.some(function(i) {
                    return String(i.id) === keep;
                })) sel.value = keep;
            else sel.value = '';
            if (sel.value !== keep) mmIngChosen();
        }

        async function mmLoadStock(it) {
            const hint = mmEl('mmIngPreview');
            try {
                await mmLoadIngredients();
            } catch (e) {
                hint.textContent = 'Stock items could not be loaded: ' + e.message;
                MM_STATE.ready = false;
                return;
            }
            if (!it || !it.pos_id) return;
            try {
                const res = await fetch(MM_RECIPE_API + '?action=get_recipe&menu_item_id=' + encodeURIComponent(it.pos_id), {
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Could not load the stock link');
                if (MM_STATE.editing !== it) return; // another item was opened meanwhile
                MM_STATE.lines = (data.lines || []).map(function(l) {
                    return {
                        ingredient_id: parseInt(l.ingredient_id, 10),
                        name: l.name,
                        unit: l.unit,
                        cost: parseFloat(l.cost_per_unit) || 0,
                        pack_name: l.pack_name || '',
                        pack_size: parseFloat(l.pack_size) || 0,
                        qty: parseFloat(l.quantity_per_portion) || 0,
                        entry: 'base', // existing links keep their exact base-unit meaning
                        pieces: parseFloat(l.pieces_per_unit) || 0,
                        yield_percent: parseFloat(l.yield_percent) || 100
                    };
                }).map(mmShowAsPieces);
                MM_STATE.ready = true;
                mmStockRender();
            } catch (e) {
                hint.textContent = 'The existing stock link could not be loaded (' + e.message + '), so it will be left as it is.';
                MM_STATE.ready = false;
            }
        }

        /* One plain sentence per line (same wording as the server: rh_recipe_preview_text). */
        /* "Pieces": for stock counted in a container (packet, box, dozen...), staff type WHOLE pieces
           (1 sausage) and the page works out the part of the container. Only the base-unit quantity is saved (6 decimals). */
        const MM_CONTAINER_RE = /^(packets?|packs?|packages?|boxe?s?|tins?|trays?|crates?|cartons?|bags?|sachets?|buckets?|containers?|bunch(es)?|bundles?|cases?|punnets?|dozens?|dz|doz)$/i;

        function mmIsContainer(unit) {
            return MM_CONTAINER_RE.test((unit || '').trim());
        }

        function mmIsDozen(unit) {
            return /^(dozens?|dz|doz)$/i.test((unit || '').trim());
        }

        /* Pieces in one base unit: 12 for a dozen, else what the stock item says (set in Stock only, never from this page). 0 = not set. */
        function mmPer(l) {
            if (mmIsDozen(l.unit)) return 12;
            const p = parseFloat(l.pieces);
            return p > 0 ? p : 0;
        }

        /* Reopening an item: show pieces again when the saved quantity is a whole number of pieces (or ends in a quarter, half, three-quarters). */
        function mmShowAsPieces(l) {
            const q = l.qty;
            if (mmSmallOf(l.unit) && q > 0) { // kg / litre item: show whole g / ml again when the saved amount is a whole number of them
                const g = Math.round(q * 1000);
                if (mmSmallOk(g) && Math.abs(g / 1000 - q) < 0.0000005) {
                    l.entry = 'small';
                    l.qty = g;
                }
                return l;
            }
            const per = mmPer(l);
            if (!mmIsContainer(l.unit) || !(q > 0) || q >= 1 || !(per >= 2)) return l;
            const k = Math.round(q * per * 4) / 4;
            if (k >= 0.25 && Math.abs(k / per - q) < 0.000001) {
                l.entry = 'pieces';
                l.qty = k;
            }
            return l;
        }

        /* Unrounded part of a unit, for cost only (1 piece of 6 = exactly 1/6, so 1 piece costs 2,550.00 and the line total agrees). */
        function mmLineExact(l) {
            if (l.entry === 'small') return (parseFloat(l.qty) || 0) / 1000;
            if (l.entry === 'pieces') {
                const per = mmPer(l);
                return per > 0 ? (parseFloat(l.qty) || 0) / per : 0;
            }
            return mmLineBase(l);
        }

        function mmLineBase(l) {
            const q = parseFloat(l.qty) || 0;
            if (l.entry === 'small') return Math.round(q / 1000 * 1000000) / 1000000;
            if (l.entry === 'pieces') {
                const per = mmPer(l);
                return per > 0 ? Math.round(q / per * 1000000) / 1000000 : 0;
            }
            return Math.round((l.entry === 'pack' && l.pack_size > 0 ? q * l.pack_size : q) * 1000000) / 1000000;
        }

        /* Pieces: whole numbers or ending in .25 / .5 / .75 (typed or from the ¼ ½ ¾ buttons); anything else is cut to a whole number.
           Grams / ml: whole numbers only. */
        function mmPiecesGuard(inp, entry) {
            if (entry !== 'pieces' && entry !== 'small') return;
            const v = inp.value;
            if (entry === 'pieces' && /^\d*(\.(0*|2|25|5|50|7|75))?$/.test(v)) return; // also lets "1.2" stand while 1.25 is being typed
            if (v === '') return;
            const n = parseFloat(v);
            if (!(n >= 0)) {
                inp.value = '';
                return;
            }
            if (n !== Math.floor(n)) inp.value = Math.floor(n) > 0 ? String(Math.floor(n)) : '';
        }

        /* Whole pieces, or ending in a quarter, half or three-quarters (same rule as the server). */
        function mmPiecesOk(q) {
            return q > 0 && q <= 99999 && Math.abs(q * 4 - Math.round(q * 4)) < 1e-9;
        }

        function mmQtyMode(inp, entry, unit) {
            const whole = entry === 'pieces' || entry === 'small';
            inp.inputMode = whole ? 'numeric' : 'decimal';
            inp.placeholder = entry === 'pieces' ? 'Pieces' : (entry === 'small' ? (mmSmallOf(unit) || 'g') : (entry === 'base' && mmNativeSmall(unit) ? mmNativeSmall(unit) : 'Qty'));
        }

        /* Changing the unit between g/ml and kg/litre/pack keeps the same amount (250 g = 0.25 kg = 0.333333 of a 0.75 L bottle). Returns the new box text. */
        function mmKeepAmount(was, now, text, l) {
            const v = parseFloat(text);
            if (!(v > 0) || was === now) return text;
            if (was === 'small' && now === 'base') return mmFmtQty(v / 1000, 6);
            if (was === 'small' && now === 'pack' && l.pack_size > 0) return mmFmtQty(v / 1000 / l.pack_size, 6);
            if (was === 'base' && now === 'small') return String(Math.round(v * 1000));
            if (was === 'pack' && now === 'small' && l.pack_size > 0) return String(Math.round(v * l.pack_size * 1000));
            return text;
        }

        /* The small grey working for a g / ml amount: "= 250 g = 0.250 kg · cost MWK 1,750.00 · 1 kg serves 4". */
        function mmSmallText(l) {
            const k = parseFloat(l.qty) || 0;
            if (!(k > 0)) return '';
            const sm = mmSmallOf(l.unit) || 'g';
            const base = mmLineBase(l);
            let bs = mmFmtQty(base, 6);
            if ((bs.split('.')[1] || '').length < 3) bs = base.toFixed(3); // at least 3 decimals: 0.250 kg, 1.000 kg
            let t = '= ' + mmFmtQty(k, 0) + ' ' + sm + ' = ' + bs + ' ' + l.unit;
            if (l.cost > 0) t += ' · cost ' + mmFmtMoney(mmLineExact(l) * l.cost);
            if (base > 0 && 1 / base >= 1) t += ' · 1 ' + l.unit + ' serves ' + mmFmtQty(1 / base, 2);
            return t;
        }

        /* Small grey print under the box: the decimal working, for people who want to check it. */
        function mmPiecesText(l) {
            const per = mmPer(l);
            const k = parseFloat(l.qty) || 0;
            if (!(per > 0)) return 'Pieces in one ' + l.unit + ' is not set on this stock item. Set it in Stock, or choose "' + l.unit + '" as the unit.';
            if (!(k > 0)) return '';
            const base = mmLineBase(l);
            let t = '= ' + mmFmtQty(k, 2) + ' of ' + mmFmtQty(per, 2) + ' pieces = ' + mmFmtQty(base, 6) + ' ' + l.unit + ' off stock per item sold';
            if (l.cost > 0) t += ' · cost ' + mmFmtMoney(l.cost / per) + ' each';
            const n = per / k;
            if (base > 0 && n >= 2 && Math.abs(Math.round(n) - n) < 1e-9) {
                t += ' · ' + Math.round(n) + ' sold use ' + mmFmtQty(base * Math.round(n), 4) + ' ' + l.unit;
            }
            return t;
        }

        function mmPreviewText(l) {
            if (l.entry === 'pieces') return mmPiecesText(l);
            if (l.entry === 'small') return mmSmallText(l);
            const base = mmLineBase(l);
            const hasPack = l.pack_size > 0;
            const pw = (l.pack_name || 'pack').toLowerCase();
            let t = 'Uses ' + mmFmtQty(base, 6) + ' ' + l.unit + ' of ' + l.name;
            if (hasPack) t += ' (' + pw + ' of ' + mmFmtQty(l.pack_size, 3) + ')';
            t += ' per item sold';
            if (hasPack && base > 0) t += ' → 1 ' + pw + ' serves ' + mmFmtQty(l.pack_size / base, 2);
            return t;
        }

        /* The pieces working is small grey print; every other sentence keeps the normal preview look. */
        function mmSetPreview(el, l) {
            el.textContent = mmPreviewText(l);
            el.classList.toggle('mm-math', l.entry === 'pieces' || l.entry === 'small');
        }

        function mmUnitOptions(sel, l, current) {
            sel.innerHTML = '';
            const smallWord = mmSmallOf(l.unit);
            if (smallWord) { // kg / litre item: the small unit comes first (whole grams or ml)
                const s = document.createElement('option');
                s.value = 'small';
                s.textContent = smallWord;
                sel.appendChild(s);
            }
            const b = document.createElement('option');
            b.value = 'base';
            b.textContent = l.unit;
            sel.appendChild(b);
            if (l.pack_size > 0) {
                const p = document.createElement('option');
                p.value = 'pack';
                p.textContent = (l.pack_name || 'Pack') + ' (' + mmFmtQty(l.pack_size, 3) + ' ' + l.unit + ')';
                sel.appendChild(p);
            }
            const per = mmIsContainer(l.unit) ? mmPer(l) : 0;
            if (mmIsContainer(l.unit)) {
                const pc = document.createElement('option');
                pc.value = 'pieces';
                pc.textContent = per > 0 ? 'pieces (' + mmFmtQty(per, 2) + ' in a ' + l.unit + ')' : 'pieces (set pieces per ' + l.unit + ' in Stock first)';
                pc.disabled = !(per > 0);
                sel.appendChild(pc);
            }
            sel.value = current === 'pack' && l.pack_size > 0 ? 'pack' : (current === 'pieces' && per > 0 ? 'pieces' : (current === 'small' && smallWord ? 'small' : 'base'));
            sel.disabled = Array.prototype.filter.call(sel.options, function(o) {
                return !o.disabled;
            }).length < 2;
        }

        /* Quick 1 / 2 / 3 / ¼ / ½ / ¾ buttons, shared by the add row and each saved line. */
        function mmWireTools(tools, qtyInput) {
            tools.querySelectorAll('[data-q]').forEach(function(b) {
                b.addEventListener('click', function() {
                    qtyInput.value = b.getAttribute('data-q');
                    qtyInput.dispatchEvent(new Event('input', {
                        bubbles: true
                    }));
                });
            });
        }

        /* Read-only note for packet / box stock: "1 packet = 6 pieces (from Stock)". Pieces per packet is never saved from this page. */
        function mmToolsSync(l, perWrap) {
            if (perWrap.parentNode) mmQuickSet(perWrap.parentNode, l); // quick buttons follow the unit chosen
            const per = mmPer(l);
            perWrap.hidden = !mmIsContainer(l.unit);
            perWrap.textContent = per > 0 ? '1 ' + l.unit + ' = ' + mmFmtQty(per, 2) + ' pieces (from Stock' + (mmIsDozen(l.unit) ? '' : '; change it there if wrong') + ')' :
                'Pieces in one ' + l.unit + ': not set. Set it on the stock item in Stock to use single pieces.';
        }

        function mmStockRender() {
            const box = mmEl('mmStockLines');
            box.innerHTML = '';
            if (!MM_STATE.lines.length) {
                const e = document.createElement('div');
                e.className = 'mm-stock-empty';
                e.textContent = 'Nothing linked. Selling this item will not change stock.';
                box.appendChild(e);
            }
            MM_STATE.lines.forEach(function(l, idx) {
                const row = document.createElement('div');
                row.className = 'mm-stock-line';
                const name = document.createElement('div');
                name.className = 'mm-stock-name';
                name.textContent = l.name;
                const qty = document.createElement('input');
                qty.type = 'number';
                qty.step = 'any';
                qty.min = '0';
                qty.value = l.qty;
                mmQtyMode(qty, l.entry, l.unit);
                qty.setAttribute('aria-label', 'Quantity of ' + l.name + (l.entry === 'small' ? ' in ' + mmSmallOf(l.unit) : ''));
                const unit = document.createElement('select');
                unit.setAttribute('aria-label', 'Unit for ' + l.name);
                mmUnitOptions(unit, l, l.entry);
                const rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'mm-btn mm-btn-sm';
                rm.textContent = 'Remove';
                const pv = document.createElement('p');
                pv.className = 'mm-preview';
                mmSetPreview(pv, l);
                const tools = document.createElement('div');
                tools.className = 'mm-qtytools';
                tools.innerHTML = '<span class="mm-quick" role="group" aria-label="Quick quantity">' +
                    '<button type="button" class="mm-btn mm-btn-sm" data-q="1" aria-label="Use 1">1</button>' +
                    '<button type="button" class="mm-btn mm-btn-sm" data-q="2" aria-label="Use 2">2</button>' +
                    '<button type="button" class="mm-btn mm-btn-sm" data-q="3" aria-label="Use 3">3</button>' +
                    '<button type="button" class="mm-btn mm-btn-sm" data-q="0.25" aria-label="Use one quarter">¼</button>' +
                    '<button type="button" class="mm-btn mm-btn-sm" data-q="0.5" aria-label="Use one half">½</button>' +
                    '<button type="button" class="mm-btn mm-btn-sm" data-q="0.75" aria-label="Use three quarters">¾</button></span>' +
                    '<span class="mm-per"></span>';
                const perWrap = tools.querySelector('.mm-per');
                mmToolsSync(l, perWrap);
                mmWireTools(tools, qty);
                const refresh = function() {
                    MM_STATE.changed = true;
                    mmToolsSync(l, perWrap);
                    mmSetPreview(pv, l);
                    mmStockTotals();
                };
                qty.addEventListener('input', function() {
                    mmPiecesGuard(qty, l.entry);
                    l.qty = qty.value;
                    refresh();
                });
                unit.addEventListener('change', function() {
                    const was = l.entry;
                    l.entry = unit.value;
                    mmQtyMode(qty, l.entry, l.unit);
                    qty.setAttribute('aria-label', 'Quantity of ' + l.name + (l.entry === 'small' ? ' in ' + mmSmallOf(l.unit) : ''));
                    if (l.entry === 'pieces') {
                        mmPiecesGuard(qty, 'pieces');
                        if (!(parseFloat(qty.value) > 0)) qty.value = '1';
                        l.qty = qty.value;
                    } else if (l.entry === 'small') {
                        qty.value = mmKeepAmount(was, 'small', qty.value, l); // 0.25 kg -> 250 g
                        mmPiecesGuard(qty, 'small');
                        l.qty = qty.value;
                    } else if (was === 'small') {
                        qty.value = mmKeepAmount(was, l.entry, qty.value, l); // 250 g -> 0.25 kg
                        l.qty = qty.value;
                    }
                    refresh();
                });
                rm.addEventListener('click', function() {
                    MM_STATE.lines.splice(idx, 1);
                    MM_STATE.changed = true;
                    mmStockRender();
                });
                row.appendChild(name);
                row.appendChild(qty);
                row.appendChild(unit);
                row.appendChild(rm);
                row.appendChild(tools);
                row.appendChild(pv);
                if (!(l.pack_size > 0) && !mmIsContainer(l.unit)) {
                    const note = document.createElement('p');
                    note.className = 'mm-sub';
                    const sw = mmSmallOf(l.unit);
                    note.textContent = sw ? 'Counted in ' + l.unit + '. Choose ' + sw + ' to type whole numbers (250 ' + sw + ' = 0.25 ' + l.unit + ').' :
                        (mmNativeSmall(l.unit) ? 'Counted in ' + l.unit + '. Whole numbers are fine (250 = 250 ' + l.unit + ').' : 'Counted in ' + l.unit + '. For part of one, use a decimal (1 of 10 = 0.1).');
                    row.appendChild(note);
                }
                box.appendChild(row);
            });
            mmStockTotals();
        }

        function mmStockTotals() {
            const wrap = mmEl('mmStockTotals');
            if (!MM_STATE.lines.length) {
                wrap.hidden = true;
                return;
            }
            let cost = 0;
            MM_STATE.lines.forEach(function(l) {
                const y = l.yield_percent > 0 ? l.yield_percent / 100 : 1;
                cost += mmLineExact(l) / y * l.cost;
            });
            wrap.hidden = false;
            mmEl('mmCostTotal').textContent = mmFmtMoney(cost);
            const price = mmEl('mmFree').checked ? 0 : (parseFloat(mmEl('mmPrice').value) || 0);
            mmEl('mmCostPct').textContent = price > 0 ? 'Food cost ' + (cost / price * 100).toFixed(1) + '% of the price, margin ' + mmFmtMoney(price - cost) : '';
        }

        /* The picked stock item (the Item dropdown holds the stock item id). */
        function mmFindIng(value) {
            const id = parseInt(value, 10);
            if (!id || !MM_STATE.ing) return null;
            return MM_STATE.ing.find(function(i) {
                return i.id === id;
            }) || null;
        }

        /* The row being typed: pick a stock item, the unit line and unit choices follow it, the sentence updates live. */
        function mmIngChosen() {
            const ing = mmFindIng(mmEl('mmIngPick').value);
            const sel = mmEl('mmIngUnit');
            const ul = mmEl('mmIngUnitLine');
            if (!ing) {
                MM_STATE.draftId = 0;
                sel.innerHTML = '<option value="base">unit</option>';
                sel.disabled = true;
                mmEl('mmIngPreview').textContent = '';
                mmEl('mmIngTools').hidden = true;
                ul.hidden = true;
                ul.textContent = '';
                mmQtyMode(mmEl('mmIngQty'), 'base', '');
                return;
            }
            let prev = sel.value;
            if (MM_STATE.draftId !== ing.id) { // a different stock item: pieces is the easy default for packets, boxes, dozens
                MM_STATE.draftId = ing.id;
                prev = mmIsContainer(ing.unit) && mmPer(ing) > 0 ? 'pieces' : (mmSmallOf(ing.unit) ? 'small' : 'base'); // pieces for packets with pieces set, whole g / ml for kg and litres
                if (prev === 'pieces' && !(parseFloat(mmEl('mmIngQty').value) > 0)) mmEl('mmIngQty').value = '1';
            }
            mmUnitOptions(sel, ing, prev);
            MM_STATE.draftUnit = sel.value;
            ul.hidden = false;
            ul.textContent = mmUnitLine(ing);
            mmQtyMode(mmEl('mmIngQty'), sel.value, ing.unit);
            mmPiecesGuard(mmEl('mmIngQty'), sel.value);
            mmIngLive();
        }

        /* The row being typed as a line object (so the preview and the Add button share one set of rules). */
        function mmDraftLine(ing) {
            return {
                ingredient_id: ing.id,
                name: ing.name,
                unit: ing.unit,
                cost: ing.cost,
                pack_name: ing.pack_name,
                pack_size: ing.pack_size,
                pieces: ing.pieces,
                qty: mmEl('mmIngQty').value,
                entry: mmEl('mmIngUnit').value
            };
        }

        function mmIngLive() {
            const ing = mmFindIng(mmEl('mmIngPick').value);
            const out = mmEl('mmIngPreview');
            if (!ing) {
                out.textContent = '';
                return;
            }
            const d = mmDraftLine(ing);
            mmEl('mmIngTools').hidden = false;
            mmToolsSync(d, mmEl('mmIngPerWrap'));
            const q = parseFloat(mmEl('mmIngQty').value);
            if (!(q > 0)) {
                out.classList.remove('mm-math');
                const sw = d.entry === 'small' ? mmSmallOf(ing.unit) : (d.entry === 'base' ? mmNativeSmall(ing.unit) : '');
                out.textContent = mmIsContainer(ing.unit) ? (mmPer(ing) > 0 ? 'Choose "pieces" and type how many one sale uses (1 for one sausage), or tap 1, 2, 3, ¼, ½ or ¾.' :
                    'Type how many ' + mmPlural(ing.unit, 2) + ' one sale uses (0.5 = half a ' + ing.unit + ').') : sw ?
                    'Type how many ' + sw + ' one sale uses (a whole number, for example 250), or tap a button.' : ing.pack_size > 0 ?
                    'One ' + (ing.pack_name || 'pack').toLowerCase() + ' is ' + mmFmtQty(ing.pack_size, 3) + ' ' + ing.unit + '. Enter how much one sale uses.' :
                    'Enter how much one sale uses (decimals are fine, for example 0.15).';
                return;
            }
            mmSetPreview(out, d);
        }

        function mmStockAdd() {
            const ing = mmFindIng(mmEl('mmIngPick').value);
            const q = parseFloat(mmEl('mmIngQty').value);
            if (!ing) {
                Alert.show('Pick a stock item from the list first.', 'error');
                return;
            }
            if (!(q > 0)) {
                Alert.show('Enter how much one sale uses (more than 0).', 'error');
                return;
            }
            if (MM_STATE.lines.some(function(l) {
                    return l.ingredient_id === ing.id;
                })) {
                Alert.show(ing.name + ' is already in the list. Change its quantity there.', 'error');
                return;
            }
            const nl = mmDraftLine(ing);
            nl.qty = q;
            nl.yield_percent = 0; // 0 = not sent: the server keeps this recipe's existing yield for the line, else 100
            if (nl.entry === 'pieces') {
                if (!mmIsContainer(ing.unit)) nl.entry = 'base';
                else if (!mmPiecesOk(q)) {
                    mmEl('mmIngQty').focus();
                    Alert.show('Type a whole number of pieces (1, 2, 3), or one ending in a quarter, half or three-quarters (tap ¼, ½ or ¾).', 'error');
                    return;
                } else if (!(mmPer(nl) > 0)) {
                    Alert.show('Pieces in one ' + ing.unit + ' of ' + ing.name + ' is not set. Set it in Stock, or choose "' + ing.unit + '" as the unit.', 'error');
                    return;
                }
            } else if (nl.entry === 'small') {
                if (!mmSmallOf(ing.unit)) nl.entry = 'base';
                else if (!mmSmallOk(q)) {
                    mmEl('mmIngQty').focus();
                    Alert.show('Type a whole number of ' + mmSmallOf(ing.unit) + ' (1 to 1,000,000), for example 250.', 'error');
                    return;
                }
            } else if (nl.entry === 'pack' && !(ing.pack_size > 0)) nl.entry = 'base';
            MM_STATE.lines.push(nl);
            MM_STATE.changed = true;
            mmEl('mmIngPick').value = '';
            mmEl('mmIngQty').value = '';
            mmIngChosen();
            mmStockRender();
        }

        async function mmSubmit(e) {
            e.preventDefault();
            if (MM_STATE.busy) return;
            const form = mmEl('mmItemForm');
            const fail = function(msg) {
                if (window.RHFormGuard) RHFormGuard.showError(form, msg);
                else Alert.show(msg, 'error');
            };
            if (window.RHFormGuard) RHFormGuard.showError(form, '');
            const invalid = form.querySelector('.rh-input-invalid');
            if (invalid) {
                invalid.focus();
                fail(invalid.validationMessage || 'Fix the highlighted field first.');
                return;
            }
            const name = mmEl('mmName').value.trim();
            if (!name) {
                mmEl('mmName').focus();
                return fail('Item name is required.');
            }
            const cat = mmEl('mmCategory').value;
            if (!cat || (cat === '__new__' && !mmEl('mmNewCategory').value.trim())) {
                mmEl('mmCategory').focus();
                return fail('Pick a category, or type the name of a new one.');
            }
            if (!mmEl('mmFree').checked && !(parseFloat(mmEl('mmPrice').value) > 0)) {
                mmEl('mmPrice').focus();
                return fail('Enter the price (more than 0). For a free item, tick "This item is free".');
            }
            const fd = new FormData(form);
            fd.set('price', mmEl('mmFree').checked ? '0' : mmEl('mmPrice').value);
            if (MM_STATE.ready && MM_STATE.changed) {
                for (let i = 0; i < MM_STATE.lines.length; i++) {
                    const ln = MM_STATE.lines[i];
                    if (!(parseFloat(ln.qty) > 0)) return fail('Enter a quantity above 0 for ' + ln.name + ', or remove it.');
                    if (ln.entry === 'small' && !mmSmallOk(parseFloat(ln.qty))) return fail('Type a whole number of ' + (mmSmallOf(ln.unit) || 'g') + ' for ' + ln.name + ' (1 to 1,000,000), or choose ' + ln.unit + ' as the unit.');
                    if (ln.entry === 'pieces' && !mmPiecesOk(parseFloat(ln.qty))) return fail('Type a whole number of pieces for ' + ln.name + ' (1, 2, 3), or one ending in a quarter, half or three-quarters.');
                    if (ln.entry === 'pieces' && !(mmPer(ln) > 0)) return fail('Pieces in one ' + ln.unit + ' of ' + ln.name + ' is not set. Set it in Stock, or choose "' + ln.unit + '" as the unit.');
                }
                fd.set('recipe_changed', '1');
                fd.set('recipe_lines', JSON.stringify(MM_STATE.lines.map(function(l) {
                    const o = {
                        ingredient_id: l.ingredient_id,
                        quantity: parseFloat(l.qty),
                        unit: l.entry
                    };
                    // Pieces are sent as typed; the server divides by the stock item's own pieces-in-a-unit and stores 6 decimals.
                    if (l.yield_percent > 0) o.yield_percent = l.yield_percent;
                    return o;
                })));
            }
            const btn = mmEl('mmSaveBtn');
            const label = btn.textContent;
            MM_STATE.busy = true;
            btn.disabled = true;
            btn.textContent = 'Saving…';
            try {
                const r = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: fd
                });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const res = await r.json();
                if (!res.success) {
                    fail(res.message || 'Could not save this item.');
                    return;
                }
                mmCloseItem();
                MM_STATE.ing = null; // stock amounts change after a sale: reload the list next time
                Alert.show(res.message || 'Saved.', 'success');
                mmRefresh();
            } catch (err) {
                fail('Network error. Please try again.');
            } finally {
                MM_STATE.busy = false;
                btn.disabled = false;
                btn.textContent = label;
            }
        }

        /* ---------- Quick actions ---------- */
        async function mmPost(fields) {
            const fd = new FormData();
            Object.keys(fields).forEach(function(k) {
                fd.append(k, fields[k]);
            });
            fd.append('csrf_token', MM_CSRF);
            const r = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            });
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }

        async function mmToggle(id, tab) {
            try {
                const res = await mmPost({
                    action: 'toggle_availability',
                    id: id,
                    menu_type: tab
                });
                Alert.show(res.message || 'Done', res.success ? 'success' : 'error');
                if (res.success) mmRefresh();
            } catch (e) {
                Alert.show('Could not change availability. Please try again.', 'error');
            }
        }

        function mmConfirmDelete(id, tab) {
            const overlay = document.createElement('div');
            overlay.className = 'mm-confirm-overlay';
            overlay.innerHTML =
                '<div class="mm-confirm-card" role="alertdialog" aria-modal="true">' +
                '  <p class="mm-confirm-msg">Delete this menu item?<small>Its stock link goes with it. An item that has been sold is hidden instead, so past orders stay readable.</small></p>' +
                '  <div class="mm-confirm-actions">' +
                '    <button type="button" class="btn-action btn-delete mm-confirm-yes">Delete</button>' +
                '    <button type="button" class="btn-action mm-confirm-no">Cancel</button>' +
                '  </div>' +
                '</div>';
            document.body.appendChild(overlay);
            const close = function() {
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            };
            overlay.querySelector('.mm-confirm-yes').addEventListener('click', async function() {
                close();
                try {
                    const res = await mmPost({
                        action: 'delete',
                        id: id,
                        menu_type: tab
                    });
                    Alert.show(res.message || 'Done', res.success ? 'success' : 'error');
                    if (res.success) mmRefresh();
                } catch (e) {
                    Alert.show('Could not delete. Please try again.', 'error');
                }
            });
            overlay.querySelector('.mm-confirm-no').addEventListener('click', close);
            overlay.addEventListener('click', function(ev) {
                if (ev.target === overlay) close();
            });
        }

        /* Re-read the lists from the server and keep the search and filters as they are. */
        function mmRefresh() {
            return fetch(window.location.pathname + '?tab=' + MM_STATE.tab, {
                    credentials: 'same-origin'
                })
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    ['food-tab', 'drinks-tab'].forEach(function(id) {
                        const next = doc.getElementById(id);
                        const cur = mmEl(id);
                        if (next && cur) cur.innerHTML = next.innerHTML;
                    });
                    const nextCat = doc.getElementById('mmCatFilter');
                    if (nextCat) {
                        mmEl('mmCatFilter').innerHTML = nextCat.innerHTML;
                        mmEl('mmCatFilter').value = MM_FILTER.cat;
                        if (mmEl('mmCatFilter').value !== MM_FILTER.cat) MM_FILTER.cat = '';
                    }
                    mmReadCats(doc);
                    mmInitDrag();
                    mmWireNameChecks();
                    mmApplyFilters(false);
                }).catch(function() {});
        }

        /* ---------- Drag to reorder rows inside a category (off while a filter is on) ---------- */
        function mmInitDrag() {
            document.querySelectorAll('.menu-table tbody').forEach(function(tbody) {
                let dragging = null;
                tbody.querySelectorAll('tr').forEach(function(row) {
                    const handle = row.querySelector('.drag-handle');
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
                        mmSaveOrder(tbody);
                    });
                    row.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        if (dragging && dragging !== row) {
                            tbody.querySelectorAll('tr').forEach(function(r) {
                                r.classList.remove('drag-over');
                            });
                            row.classList.add('drag-over');
                            const rect = row.getBoundingClientRect();
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

        function mmSaveOrder(tbody) {
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const tc = tbody.closest('.tab-content');
            const fd = new FormData();
            fd.append('action', 'reorder_items');
            fd.append('menu_type', tc && tc.id === 'drinks-tab' ? 'drinks' : 'food');
            rows.forEach(function(row) {
                const m = row.id.match(/^(?:food|drink)-row-(\d+)$/);
                if (m) fd.append('ids[]', m[1]);
            });
            fd.append('csrf_token', MM_CSRF);
            fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            }).catch(function() {});
        }

        /* ---------- New stock item (small helper form) ---------- */
        function openIngredientModal() {
            mmEl('ig-name').value = mmEl('mmIngSearch').value.trim();
            mmEl('ig-category').value = 'General';
            mmEl('ig-unit').value = 'each';
            mmEl('ig-cost').value = 0;
            mmEl('ig-yield').value = 100;
            mmEl('ingredientModal').classList.add('open');
            setTimeout(function() {
                mmEl('ig-name').focus();
            }, 50);
        }

        function closeIngredientModal() {
            mmEl('ingredientModal').classList.remove('open');
        }

        async function saveNewIngredient() {
            const name = mmEl('ig-name').value.trim();
            if (!name) {
                Alert.show('Name is required', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'add_ingredient');
            fd.append('name', name);
            fd.append('category', mmEl('ig-category').value.trim() || 'General');
            fd.append('unit', mmEl('ig-unit').value);
            fd.append('cost_per_unit', mmEl('ig-cost').value || 0);
            fd.append('yield_percent', mmEl('ig-yield').value || 100);
            fd.append('csrf_token', MM_CSRF);
            try {
                const res = await fetch(MM_RECIPE_API, {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error);
                MM_STATE.ing = null;
                const list = await mmLoadIngredients();
                const made = list.find(function(i) {
                    return i.id === data.id;
                });
                if (made) {
                    mmEl('mmIngCat').value = '';
                    mmEl('mmIngSearch').value = '';
                    mmIngRenderPicker();
                    mmEl('mmIngPick').value = String(made.id);
                    mmIngChosen();
                }
                Alert.show(data.reused ? 'That stock item already existed. It is selected.' : 'Stock item created. Enter the quantity and add it.', 'success');
                closeIngredientModal();
            } catch (e) {
                Alert.show('Failed: ' + e.message, 'error');
            }
        }

        /* ---------- Wiring ---------- */
        function mmWireNameChecks() {
            if (!window.RHFormGuard) return;
            RHFormGuard.watch(mmEl('mmName'), function() {
                const ed = MM_STATE.editing;
                const cat = mmEl('mmCategory').value === '__new__' ? mmEl('mmNewCategory').value : mmEl('mmCategory').value;
                return {
                    form: mmEl('mmType').value === 'drinks' ? 'menu_drink' : 'menu_food',
                    excludeId: ed ? ed.id : 0,
                    params: {
                        category: cat
                    }
                };
            }, [mmEl('mmCategory')]);
            RHFormGuard.watch(mmEl('mmBarcode'), function() {
                const ed = MM_STATE.editing;
                return {
                    form: 'menu_item_barcode',
                    excludeId: ed && ed.pos_id ? ed.pos_id : 0
                };
            });
        }

        function mmInit() {
            mmReadCats(document);
            mmInitFilters();
            mmInitDrag();
            mmWireNameChecks();
            mmEl('mmItemForm').addEventListener('submit', mmSubmit);
            mmEl('mmFree').addEventListener('change', mmSyncFree);
            mmEl('mmPrice').addEventListener('input', mmStockTotals);
            mmEl('mmCategory').addEventListener('change', function() {
                const isNew = this.value === '__new__';
                mmEl('mmNewCategory').hidden = !isNew;
                if (isNew) mmEl('mmNewCategory').focus();
            });
            mmEl('mmIngPick').addEventListener('change', mmIngChosen);
            mmEl('mmIngCat').addEventListener('change', mmIngRenderPicker);
            mmEl('mmIngSearch').addEventListener('input', mmIngRenderPicker);
            mmEl('mmIngQty').addEventListener('input', function() {
                mmPiecesGuard(mmEl('mmIngQty'), mmEl('mmIngUnit').value);
                mmIngLive();
            });
            mmEl('mmIngUnit').addEventListener('change', function() {
                const q = mmEl('mmIngQty');
                const pk = mmFindIng(mmEl('mmIngPick').value);
                const now = mmEl('mmIngUnit').value;
                if (pk) q.value = mmKeepAmount(MM_STATE.draftUnit || 'base', now, q.value, pk);
                MM_STATE.draftUnit = now;
                mmQtyMode(q, now, pk ? pk.unit : '');
                if (now === 'pieces') {
                    mmPiecesGuard(q, 'pieces');
                    if (!(parseFloat(q.value) > 0)) q.value = '1';
                } else if (now === 'small') {
                    mmPiecesGuard(q, 'small');
                }
                mmIngLive();
            });
            mmWireTools(mmEl('mmIngTools'), mmEl('mmIngQty'));
            mmEl('mmIngQty').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    mmStockAdd();
                }
            });
            // Close a form when its dark backdrop is clicked.
            document.querySelectorAll('.mm-modal').forEach(function(m) {
                m.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('open');
                        document.body.style.overflow = '';
                    }
                });
            });
            if (window.RHFormGuard) RHFormGuard.guardSubmit(mmEl('mmItemForm'), {
                busy: false
            }); // saving is AJAX with its own busy button
        }
        mmInit();
    </script>

    <?php /* Modals sit before the footer: admin-footer.php closes #rh-admin-page, and the admin SPA
       only swaps that container, so markup printed after it is lost on in-place navigation. */ ?>
    <?php if ($fb_menu_posting_on): ?>
        <!-- Facebook Menu Item Share Modal — two-column with live preview -->
        <div class="modal-overlay" id="fbMenuModal" style="display:none;" onclick="if(event.target===this)closeFbMenuModal()">
            <div class="modal-content" style="max-width:760px;">
                <div class="modal-header">
                    <h3 id="fbMenuTitle"><i class="fab fa-facebook-f"></i> Feature on Facebook</h3>
                    <button class="modal-close" type="button" aria-label="Close" onclick="closeFbMenuModal()">&times;</button>
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
                    <button type="button" class="btn btn-secondary" onclick="closeFbMenuModal()">Cancel</button>
                    <button type="button" id="fbMenuSubmitBtn" class="btn btn-primary">
                        <i class="fab fa-facebook-f"></i> Post to Facebook Page
                    </button>
                </div>
            </div>
        </div>

        <!-- Share Full Menu on Facebook modal -->
        <div class="modal-overlay" id="fbMenuShareAllModal" style="display:none;" onclick="if(event.target===this)closeFbMenuShareAllModal()">
            <div class="modal-content" style="max-width:820px;">
                <div class="modal-header">
                    <h3><i class="fab fa-facebook-f"></i> Share Restaurant Menu on Facebook</h3>
                    <button class="modal-close" type="button" aria-label="Close" onclick="closeFbMenuShareAllModal()">&times;</button>
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
                    <button type="button" class="btn btn-secondary" onclick="closeFbMenuShareAllModal()">Cancel</button>
                    <button type="button" id="fbMenuShareAllSubmitBtn" class="btn btn-primary">
                        <i class="fab fa-facebook-f"></i> Post to Facebook Page
                    </button>
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
                    hashtags: <?php echo json_encode(getSetting('facebook_default_hashtags', '#hotel #accommodation #luxury')); ?>,
                    pageName: <?php echo json_encode(getSetting('facebook_page_name', '')); ?>
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

    <?php require_once 'includes/admin-footer.php'; ?>


