<?php

/**
 * Stock Management — Recipes
 *
 * Two-panel UI: left side menu items needing/with recipes, right side the
 * recipe ingredient list with quantity_per_portion and yield_percent.
 * Includes food cost % indicator.
 */
require_once 'admin-init.php';
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
$currency_symbol = getSetting('currency_symbol');

if (!ensureStockTablesExist()) {
    $error = 'Stock tables not yet created. Please run admin/migrations/015_stock_management.php first.';
}

// AJAX endpoint: get a recipe by menu item
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_recipe') {
    header('Content-Type: application/json');
    $itemId = (int)($_GET['menu_item_id'] ?? 0);
    $typeRaw = trim($_GET['menu_type'] ?? '');
    try {
        // Validate slug against menu_categories
        $catChk = $pdo->prepare("SELECT slug FROM menu_categories WHERE slug = ? AND is_active = 1 LIMIT 1");
        $catChk->execute([$typeRaw]);
        $type = (string)($catChk->fetchColumn() ?: 'food');

        // Menu item details from unified table
        $stmt = $pdo->prepare("SELECT mi.id, mi.item_name AS name, mi.price FROM menu_items mi WHERE mi.id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            echo json_encode(['ok' => false, 'error' => 'Menu item not found']);
            exit;
        }

        // Recipe?
        $rs = $pdo->prepare("SELECT id, portions_per_recipe FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?");
        $rs->execute([$itemId, $type]);
        $recipe = $rs->fetch(PDO::FETCH_ASSOC);
        $recipeId = $recipe['id'] ?? null;

        $ingredients = [];
        if ($recipeId) {
            $stm = $pdo->prepare("
                SELECT sri.*, i.name AS ingredient_name, i.unit, i.cost_per_unit
                FROM stock_recipe_ingredients sri
                INNER JOIN stock_ingredients i ON i.id = sri.ingredient_id
                WHERE sri.recipe_id = ?
                ORDER BY i.name
            ");
            $stm->execute([$recipeId]);
            $ingredients = $stm->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode([
            'ok' => true,
            'item' => $item,
            'recipe_id' => $recipeId ? (int)$recipeId : null,
            'portions_per_recipe' => $recipe ? (int)$recipe['portions_per_recipe'] : 1,
            'ingredients' => $ingredients
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security token invalid.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save_recipe') {
                $itemId = (int)($_POST['menu_item_id'] ?? 0);
                $typeRaw = trim($_POST['menu_type'] ?? 'food');
                $catChk2 = $pdo->prepare("SELECT slug FROM menu_categories WHERE slug = ? AND is_active = 1 LIMIT 1");
                $catChk2->execute([$typeRaw]);
                $type = (string)($catChk2->fetchColumn() ?: 'food');
                $portions = max(1, (int)($_POST['portions_per_recipe'] ?? 1));
                $ingIds   = $_POST['ingredient_id'] ?? [];
                $qtys     = $_POST['quantity_per_portion'] ?? [];
                $yields   = $_POST['yield_percent'] ?? [];
                $delim    = $_POST['delete_ingredient'] ?? [];

                if ($itemId <= 0) throw new RuntimeException('Menu item required.');

                $pdo->beginTransaction();
                // Find or create recipe
                $rs = $pdo->prepare("SELECT id FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?");
                $rs->execute([$itemId, $type]);
                $recipeId = (int)($rs->fetchColumn() ?: 0);
                if ($recipeId === 0) {
                    $ins = $pdo->prepare("INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe, created_by) VALUES (?, ?, ?, ?)");
                    $ins->execute([$itemId, $type, $portions, $user['id']]);
                    $recipeId = (int)$pdo->lastInsertId();
                } else {
                    $upd = $pdo->prepare("UPDATE stock_recipes SET portions_per_recipe = ?, updated_at = NOW() WHERE id = ?");
                    $upd->execute([$portions, $recipeId]);
                }

                // Wipe all ingredients then re-insert (simple & atomic)
                $pdo->prepare("DELETE FROM stock_recipe_ingredients WHERE recipe_id = ?")->execute([$recipeId]);

                $insIng = $pdo->prepare("INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent) VALUES (?, ?, ?, ?)");
                $count = is_array($ingIds) ? count($ingIds) : 0;
                $saved = 0;
                for ($k = 0; $k < $count; $k++) {
                    $iid = (int)($ingIds[$k] ?? 0);
                    $q = (float)($qtys[$k] ?? 0);
                    $y = (float)($yields[$k] ?? 100);
                    if ($y <= 0 || $y > 100) $y = 100;
                    if ($iid > 0 && $q > 0 && empty($delim[$k])) {
                        $insIng->execute([$recipeId, $iid, $q, $y]);
                        $saved++;
                    }
                }

                if ($saved === 0) {
                    // Empty recipe → delete it altogether
                    $pdo->prepare("DELETE FROM stock_recipes WHERE id = ?")->execute([$recipeId]);
                    $message = 'Recipe removed (no ingredients).';
                } else {
                    $message = "Recipe saved with {$saved} ingredient(s).";
                }
                $pdo->commit();
            } elseif ($action === 'delete_recipe') {
                $itemId = (int)($_POST['menu_item_id'] ?? 0);
                $typeRaw3 = trim($_POST['menu_type'] ?? '');
                if ($typeRaw3 === '' || $itemId <= 0) {
                    throw new RuntimeException('Invalid recipe delete request.');
                }
                // Use the raw type directly — no fallback. A recipe only exists for its actual type,
                // so falling back to 'food' would silently delete the wrong recipe.
                $pdo->prepare("DELETE FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?")->execute([$itemId, $typeRaw3]);
                $message = 'Recipe deleted.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
    if ($message) {
        $_SESSION['stock_msg'] = $message;
        if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v3');
    }
    if ($error)   $_SESSION['stock_err'] = $error;
    header('Location: stock-recipes.php');
    exit;
}

if (!empty($_SESSION['stock_msg'])) {
    $message = $_SESSION['stock_msg'];
    unset($_SESSION['stock_msg']);
}
if (!empty($_SESSION['stock_err'])) {
    $error   = $_SESSION['stock_err'];
    unset($_SESSION['stock_err']);
}

// Build menu list with recipe + cost calculation
$menuItems = [];
$ingredients = [];
if (!$error || strpos($error, 'not yet') === false) {
    try {
        $menuItems = $pdo->query("
            SELECT mc.slug AS menu_type, mi.id, mi.item_name AS name, mi.price,
                   sr.id AS recipe_id, sr.portions_per_recipe,
                   COALESCE(SUM(sri.quantity_per_portion / (GREATEST(sri.yield_percent, 0.1)/100) * i.cost_per_unit), 0) AS recipe_cost
            FROM menu_items mi
            JOIN menu_categories mc ON mc.id = mi.category_id
            LEFT JOIN stock_recipes sr ON sr.menu_item_id = mi.id AND sr.menu_type = mc.slug
            LEFT JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
            LEFT JOIN stock_ingredients i ON i.id = sri.ingredient_id
            WHERE mc.is_active = 1
            GROUP BY mc.slug, mi.id, mi.item_name, mi.price, sr.id, sr.portions_per_recipe
            ORDER BY mc.sort_order ASC, mi.item_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $ingredients = $pdo->query("SELECT id, name, category, unit, cost_per_unit, current_quantity FROM stock_ingredients WHERE is_archived = 0 ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = 'Failed to load: ' . $e->getMessage();
    }
}

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Recipes — Stock Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/stock-recipes.css?v=<?php echo @filemtime(__DIR__ . '/css/stock-recipes.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content stock-recipes-page">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-book-open" style="color:#8B7355;"></i> Recipes</h2>
            <a href="stock-ingredients.php" class="btn-add"><i class="fas fa-carrot"></i> Manage Ingredients</a>
        </div>

        <?php if ($message): showAlert($message, 'success');
        endif; ?>
        <?php if ($error):   showAlert($error,   'error');
        endif; ?>

        <div class="recipe-layout">
            <div class="menu-list">
                <input type="text" id="menu-search" placeholder="Search menu items..." oninput="filterMenu()">
                <div class="filter-row">
                    <button type="button" class="active" data-filter="all" onclick="setFilter(this,'all')">All</button>
                    <button type="button" data-filter="food" onclick="setFilter(this,'food')">Food</button>
                    <button type="button" data-filter="drink" onclick="setFilter(this,'drink')">Drinks</button>
                    <button type="button" data-filter="missing" onclick="setFilter(this,'missing')" title="Items without a recipe">No&nbsp;recipe</button>
                </div>
                <div id="menu-items">
                    <?php foreach ($menuItems as $m):
                        $price = (float)$m['price'];
                        $cost = (float)$m['recipe_cost'];
                        $costPct = $price > 0 ? ($cost / $price) * 100 : 0;
                        $costClass = $costPct == 0 ? 'badge-no-recipe' : ($costPct < 30 ? 'badge-cost-good' : ($costPct <= 40 ? 'badge-cost-warn' : 'badge-cost-bad'));
                    ?>
                        <div class="menu-item"
                            data-name="<?php echo htmlspecialchars(strtolower($m['name'])); ?>"
                            data-type="<?php echo $m['menu_type']; ?>"
                            data-has-recipe="<?php echo $m['recipe_id'] ? '1' : '0'; ?>"
                            data-menu-id="<?php echo (int)$m['id']; ?>"
                            data-menu-type="<?php echo $m['menu_type']; ?>"
                            role="button"
                            tabindex="0"
                            aria-label="Load recipe for <?php echo htmlspecialchars($m['name']); ?>">
                            <div class="name"><?php echo htmlspecialchars($m['name']); ?></div>
                            <div class="meta">
                                <span><?php echo ucfirst($m['menu_type']); ?> · <?php echo $currency_symbol . ' ' . number_format($price, 2); ?></span>
                                <?php if ($m['recipe_id']): ?>
                                    <span class="badge <?php echo $costClass; ?>" title="Food cost %"><?php echo number_format($costPct, 1); ?>%</span>
                                <?php else: ?>
                                    <span class="badge badge-no-recipe">No recipe</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($menuItems)): ?>
                        <div style="text-align:center; color:#6c757d; padding:20px;">No menu items found. Add some via Menu Management first.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="recipe-editor empty" id="recipeEditor">
                <i class="fas fa-arrow-left" style="font-size:32px; opacity:0.3; margin-bottom:12px;"></i>
                <p>Select a menu item from the left to view or edit its recipe.</p>
            </div>
        </div>
    </div>

    <template id="recipeTemplate">
        <h3 id="re_name"></h3>
        <div class="recipe-meta">
            <span id="re_type"></span> · Sale price: <strong id="re_price"></strong>
        </div>

        <div class="food-cost-help">
            <b><i class="fas fa-info-circle"></i> Understanding food cost %</b><br>
            Food cost % = total ingredient cost ÷ menu sale price. Lower is better — every percentage point lower is gross profit you keep.
            <div class="legend">
                <span><span class="swatch" style="background:#28a745;"></span> &lt; 30% — Healthy</span>
                <span><span class="swatch" style="background:#ffc107;"></span> 30 – 40% — Acceptable</span>
                <span><span class="swatch" style="background:#dc3545;"></span> &gt; 40% — Re-price or rework</span>
            </div>
            <small style="color:#5b6b7c;">Industry targets: food 28–35 %, drinks 18–25 %. Margin = sale price − ingredient cost (before labour & overheads).</small>
        </div>

        <form method="POST" id="recipeForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_recipe">
            <input type="hidden" name="menu_item_id" id="re_item_id">
            <input type="hidden" name="menu_type" id="re_menu_type">

            <div style="margin-bottom:14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <label style="font-size:12px; font-weight:600;" title="If one batch of this recipe yields multiple portions, set this. E.g. a 4-portion stew enters quantities once and is divided.">
                    Recipe yields
                    <input type="number" name="portions_per_recipe" id="re_portions" value="1" min="1" max="200"
                        style="width:70px; padding:6px; border:1px solid #d6d8db; border-radius:6px; margin:0 4px;">
                    portion(s)
                </label>
                <small style="color:#6c757d;">Quantities below are <em>per portion sold</em>. Stock auto-deducts on POS or room-service charge.</small>
            </div>

            <div class="ing-header">
                <div class="col">Ingredient
                    <i class="fas fa-circle-question help"
                        title="Pick from the catalog. Categories are grouped. Use ‘+ Add new ingredient’ if missing."></i>
                </div>
                <div class="col">Qty / portion
                    <i class="fas fa-circle-question help"
                        title="How much of the ingredient (in its base unit) goes into ONE portion. e.g. 0.05 kg = 50 g."></i>
                </div>
                <div class="col">Yield %
                    <i class="fas fa-circle-question help"
                        title="Usable portion after trim/peel/cooking. 100% = no loss. 80% = 20% lost. Stock deducts the RAW quantity (qty ÷ yield)."></i>
                </div>
                <div class="col">Line cost
                    <i class="fas fa-circle-question help"
                        title="Computed = (qty ÷ yield) × current cost-per-unit of the ingredient. Updates live as you type."></i>
                </div>
                <div class="col"></div>
            </div>

            <div id="ingRows"></div>

            <div class="row-actions">
                <button type="button" class="btn-secondary" onclick="addIngRow()"><i class="fas fa-plus"></i> Add ingredient line</button>
                <button type="button" class="btn-link" onclick="document.getElementById('quickIng').classList.toggle('open');">
                    <i class="fas fa-bolt"></i> + Add new ingredient (without leaving page)
                </button>
            </div>

            <div class="quick-ing" id="quickIng">
                <div><label>Name *</label><input id="qi_name" placeholder="e.g. Basmati rice"></div>
                <div><label>Category</label><input id="qi_cat" placeholder="Pantry" value="Pantry"></div>
                <div><label>Unit *</label><input id="qi_unit" placeholder="g, kg, ml, l, pcs" value="g"></div>
                <div><label>Cost / unit (<?php echo htmlspecialchars($currency_symbol); ?>)</label><input id="qi_cost" type="number" step="0.0001" min="0" value="0"></div>
                <div><button type="button" class="btn-primary" onclick="quickAddIngredient()" style="padding:7px 12px;"><i class="fas fa-check"></i></button></div>
                <div id="qi_error" style="color:#c82333;font-size:13px;display:none;"></div>
            </div>

            <div class="cost-summary">
                <div>
                    <div class="label">Total recipe cost</div>
                    <div class="value" id="cs_cost">—</div>
                </div>
                <div>
                    <div class="label">Sale price</div>
                    <div class="value" id="cs_price">—</div>
                </div>
                <div>
                    <div class="label">Food cost %</div>
                    <div class="value" id="cs_pct">—</div>
                </div>
                <div>
                    <div class="label">Gross margin / portion</div>
                    <div class="value" id="cs_margin">—</div>
                </div>
                <div class="target-line" id="cs_target">Set ingredients above to see status.</div>
            </div>

            <div style="margin-top:18px; display:flex; gap:10px; justify-content:space-between; flex-wrap:wrap;">
                <button type="button" class="btn-danger" onclick="deleteRecipe()" title="Permanently remove the recipe. POS orders will then place without auto-deducting stock."><i class="fas fa-trash"></i> Delete recipe</button>
                <div>
                    <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save recipe</button>
                </div>
            </div>
        </form>

        <form method="POST" id="deleteRecipeForm" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="delete_recipe">
            <input type="hidden" name="menu_item_id" id="del_item_id">
            <input type="hidden" name="menu_type" id="del_menu_type">
        </form>
    </template>

    <script>
        let ingredients = <?php echo json_encode(array_map(fn($i) => [
                                'id' => (int)$i['id'],
                                'name' => $i['name'],
                                'unit' => $i['unit'],
                                'cost' => (float)$i['cost_per_unit'],
                                'category' => $i['category'] ?? 'General',
                                'qty' => (float)($i['current_quantity'] ?? 0)
                            ], $ingredients)); ?>;
        const currencySymbol = <?php echo json_encode($currency_symbol); ?>;
        const fmtMoney = n => Number(n || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        let currentItem = null;
        let currentFilter = 'all';

        function buildOptions(selectedId) {
            // Group by category, then sort
            const groups = {};
            ingredients.forEach(i => {
                (groups[i.category || 'General'] = groups[i.category || 'General'] || []).push(i);
            });
            const cats = Object.keys(groups).sort();
            let html = '<option value="">— Select ingredient —</option>';
            cats.forEach(cat => {
                html += `<optgroup label="${cat}">`;
                groups[cat].sort((a, b) => a.name.localeCompare(b.name)).forEach(i => {
                    const sel = (selectedId && Number(selectedId) === i.id) ? ' selected' : '';
                    const lowFlag = i.qty < 0.01 ? ' ⚠' : '';
                    html += `<option value="${i.id}" data-unit="${i.unit}" data-cost="${i.cost}"${sel}>${i.name} (${i.unit})${lowFlag}</option>`;
                });
                html += '</optgroup>';
            });
            return html;
        }

        function setFilter(btn, mode) {
            currentFilter = mode;
            document.querySelectorAll('.filter-row button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filterMenu();
        }

        function filterMenu() {
            const q = (document.getElementById('menu-search').value || '').toLowerCase();
            document.querySelectorAll('.menu-item').forEach(el => {
                const matchText = !q || el.dataset.name.indexOf(q) !== -1;
                const matchType =
                    currentFilter === 'all' ? true :
                    currentFilter === 'food' ? el.dataset.type === 'food' :
                    currentFilter === 'drink' ? el.dataset.type === 'drink' :
                    currentFilter === 'missing' ? el.dataset.hasRecipe === '0' : true;
                el.style.display = (matchText && matchType) ? '' : 'none';
            });
        }

        async function loadRecipe(itemId, type, el) {
            document.querySelectorAll('.menu-item').forEach(e => e.classList.remove('active'));
            if (el) el.classList.add('active');

            const editor = document.getElementById('recipeEditor');
            editor.classList.remove('empty');
            editor.innerHTML = '<p style="text-align:center; color:#6c757d;">Loading...</p>';

            try {
                const res = await fetch(`stock-recipes.php?ajax=get_recipe&menu_item_id=${itemId}&menu_type=${type}`);
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Failed');

                currentItem = {
                    id: itemId,
                    type,
                    item: data.item,
                    ingredients: data.ingredients
                };
                const tpl = document.getElementById('recipeTemplate').content.cloneNode(true);
                editor.innerHTML = '';
                editor.appendChild(tpl);

                document.getElementById('re_name').textContent = data.item.name;
                document.getElementById('re_type').textContent = type.charAt(0).toUpperCase() + type.slice(1);
                document.getElementById('re_price').textContent = currencySymbol + ' ' + fmtMoney(data.item.price);
                document.getElementById('re_item_id').value = itemId;
                document.getElementById('re_menu_type').value = type;
                document.getElementById('del_item_id').value = itemId;
                document.getElementById('del_menu_type').value = type;
                document.getElementById('re_portions').value = data.portions_per_recipe || 1;

                const rows = document.getElementById('ingRows');
                rows.innerHTML = '';
                if (data.ingredients.length) {
                    data.ingredients.forEach(ing => addIngRow(ing));
                } else {
                    addIngRow();
                }
                recalcCost();
            } catch (e) {
                editor.innerHTML = '<p style="color:#c82333;">Error: ' + e.message + '</p>';
            }
        }

        function openMenuRecipeFromItem(item) {
            if (!item) return;
            const itemId = parseInt(item.dataset.menuId || '0', 10);
            const itemType = item.dataset.menuType || 'food';
            if (!itemId) return;
            loadRecipe(itemId, itemType, item);
        }

        function initRecipePage() {
            const list = document.getElementById('menu-items');
            if (!list || list.dataset.bound === '1') return;

            list.addEventListener('click', function(event) {
                const item = event.target.closest('.menu-item');
                if (!item || !list.contains(item)) return;
                openMenuRecipeFromItem(item);
            });

            list.addEventListener('keydown', function(event) {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                const item = event.target.closest('.menu-item');
                if (!item || !list.contains(item)) return;
                event.preventDefault();
                openMenuRecipeFromItem(item);
            });

            list.dataset.bound = '1';
        }

        window.initRecipePage = initRecipePage;
        window.loadRecipe = loadRecipe;
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initRecipePage, {
                once: true
            });
        } else {
            initRecipePage();
        }

        function addIngRow(prefill) {
            const rows = document.getElementById('ingRows');
            const row = document.createElement('div');
            row.className = 'ing-row';
            const ingId = prefill ? prefill.ingredient_id : '';
            row.innerHTML = `
                <div class="ing-pick-wrap">
                    <select name="ingredient_id[]" required onchange="recalcCost()">${buildOptions(ingId)}</select>
                </div>
                <div class="qty-cell">
                    <input type="number" name="quantity_per_portion[]" placeholder="e.g. 0.05" step="0.0001" min="0" required oninput="recalcCost()" title="How much per portion in the ingredient's base unit (g, ml, kg, etc.)">
                    <span class="raw-qty"></span>
                </div>
                <input type="number" name="yield_percent[]" placeholder="100" step="0.1" min="0.1" max="100" value="100" oninput="recalcCost()" title="Usable percentage after preparation (peel, trim, cook). 100 = no loss.">
                <span class="line-cost" style="font-size:13px; font-weight:600; color:#3f4654;">—</span>
                <button type="button" class="remove" title="Remove this ingredient" onclick="this.closest('.ing-row').remove(); recalcCost();"><i class="fas fa-times"></i></button>
                <input type="hidden" name="delete_ingredient[]" value="0">
            `;
            rows.appendChild(row);
            if (prefill) {
                const qtyInput = row.querySelector('input[name="quantity_per_portion[]"]');
                const yldInput = row.querySelector('input[name="yield_percent[]"]');
                qtyInput.value = Number(prefill.quantity_per_portion);
                yldInput.value = Number(prefill.yield_percent);
            }
            recalcCost();
        }

        async function quickAddIngredient() {
            const name = document.getElementById('qi_name').value.trim();
            const cat = document.getElementById('qi_cat').value.trim() || 'General';
            const unit = document.getElementById('qi_unit').value.trim() || 'g';
            const cost = parseFloat(document.getElementById('qi_cost').value || '0');
            const errEl = document.getElementById('qi_error');
            errEl.style.display = 'none';
            if (!name) {
                errEl.textContent = 'Ingredient name is required.';
                errEl.style.display = '';
                return;
            }
            try {
                const fd = new FormData();
                fd.set('action', 'add_ingredient');
                fd.set('name', name);
                fd.set('category', cat);
                fd.set('unit', unit);
                fd.set('cost_per_unit', String(cost));
                const res = await fetch('api/menu-recipe.php?action=add_ingredient', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Failed');
                // Add to local catalogue and refresh all open <select>s
                if (!data.reused) {
                    ingredients.push({
                        id: data.id,
                        name,
                        unit,
                        cost,
                        category: cat,
                        qty: 0
                    });
                }
                document.querySelectorAll('#ingRows select').forEach(sel => {
                    const cur = sel.value;
                    sel.innerHTML = buildOptions(cur || data.id);
                });
                document.getElementById('quickIng').classList.remove('open');
                document.getElementById('qi_name').value = '';
                document.getElementById('qi_cost').value = '0';
                recalcCost();
            } catch (e) {
                document.getElementById('qi_error').textContent = 'Could not add ingredient: ' + e.message;
                document.getElementById('qi_error').style.display = '';
            }
        }

        function recalcCost() {
            if (!currentItem) return;
            const rows = document.querySelectorAll('#ingRows .ing-row');
            let total = 0;
            rows.forEach(r => {
                const sel = r.querySelector('select');
                const qty = parseFloat(r.querySelector('input[name="quantity_per_portion[]"]').value) || 0;
                const yld = Math.max(0.1, parseFloat(r.querySelector('input[name="yield_percent[]"]').value) || 100);
                const opt = sel.selectedOptions[0];
                const cost = opt ? parseFloat(opt.dataset.cost || 0) : 0;
                const unit = opt && opt.dataset.unit ? opt.dataset.unit : '';
                const raw = qty / (yld / 100);
                const lineCost = raw * cost;
                total += lineCost;

                const rawSpan = r.querySelector('.raw-qty');
                if (qty > 0) {
                    rawSpan.textContent = (yld < 100) ?
                        `Deducts ${raw.toFixed(3)} ${unit} (${yld}% yield)` :
                        `${qty} ${unit} per portion`;
                } else {
                    rawSpan.textContent = '';
                }
                const lineSpan = r.querySelector('.line-cost');
                lineSpan.textContent = (qty > 0 && opt) ? currencySymbol + ' ' + fmtMoney(lineCost) : '—';
            });
            const price = parseFloat(currentItem.item.price) || 0;
            const pct = price > 0 ? (total / price) * 100 : 0;
            const margin = price - total;
            document.getElementById('cs_cost').textContent = currencySymbol + ' ' + fmtMoney(total);
            document.getElementById('cs_price').textContent = currencySymbol + ' ' + fmtMoney(price);
            document.getElementById('cs_pct').textContent = price > 0 ? pct.toFixed(1) + '%' : '—';
            document.getElementById('cs_margin').textContent = currencySymbol + ' ' + fmtMoney(margin);

            const pctEl = document.getElementById('cs_pct');
            const targetEl = document.getElementById('cs_target');
            const isDrink = currentItem.type === 'drink';
            const targetMax = isDrink ? 25 : 35;
            const targetLabel = isDrink ? 'drinks 18–25 %' : 'food 28–35 %';
            if (price <= 0 || total <= 0) {
                pctEl.style.color = '#6c757d';
                targetEl.innerHTML = `Set ingredients and a sale price to see the food-cost target.`;
            } else if (pct < 30) {
                pctEl.style.color = '#155724';
                targetEl.innerHTML = `<span class="ok">✓ Healthy.</span> Target for ${targetLabel}.`;
            } else if (pct <= 40) {
                pctEl.style.color = '#856404';
                targetEl.innerHTML = `<span class="warn">⚠ Acceptable but tight.</span> Target for ${targetLabel}. Consider raising sale price or finding cheaper supply.`;
            } else {
                pctEl.style.color = '#c82333';
                targetEl.innerHTML = `<span class="bad">✗ Too high — losing margin.</span> Target for ${targetLabel}. Re-price the item or rework the recipe.`;
            }
        }

        function deleteRecipe() {
            if (!currentItem) return;
            if (!confirm('Delete the entire recipe for this item?\n\nPOS orders for this item will still process but will NOT auto-deduct stock until you create a new recipe.')) return;
            document.getElementById('deleteRecipeForm').submit();
        }
    </script>
    <?php require_once 'includes/admin-footer.php'; ?>

