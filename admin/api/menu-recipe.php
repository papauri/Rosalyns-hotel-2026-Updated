<?php

/**
 * Recipe AJAX endpoint for menu-management.php
 *
 * Actions (JSON in, JSON out):
 *   GET  ?action=get_recipe&menu_item_id=&menu_type=
 *   GET  ?action=list_ingredients
 *   POST action=save_recipe   (menu_item_id, menu_type, portions, lines[])
 *   POST action=add_ingredient (name, category, unit, cost_per_unit)
 *   POST action=delete_recipe (menu_item_id, menu_type)
 *
 * Auth: requires admin session.
 */

declare(strict_types=1);

require_once __DIR__ . '/api-init.php';

header('Content-Type: application/json');

requireApiAnyPermission(['menu', 'stock_management']);

if (!function_exists('ensureStockTablesExist') || !ensureStockTablesExist()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Stock tables unavailable']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        case 'list_ingredients': {
                $rows = $pdo->query(
                    "SELECT id, name, category, unit, cost_per_unit, current_quantity, min_quantity, yield_percent
                 FROM stock_ingredients
                 WHERE is_archived = 0
                 ORDER BY category, name"
                )->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['ok' => true, 'ingredients' => $rows]);
                break;
            }

        case 'get_recipe': {
                $mid  = (int)($_GET['menu_item_id'] ?? 0);
                $typeRaw = trim($_GET['menu_type'] ?? '');
                $catSlug = $pdo->prepare("SELECT slug FROM menu_categories WHERE slug = ? AND is_active = 1 LIMIT 1");
                $catSlug->execute([$typeRaw]);
                $type = (string)($catSlug->fetchColumn() ?: 'food');
                if ($mid <= 0) {
                    throw new InvalidArgumentException('menu_item_id required');
                }

                $itemStmt = $pdo->prepare("SELECT mi.id, mi.item_name, mi.price FROM menu_items mi WHERE mi.id = ?");
                $itemStmt->execute([$mid]);
                $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
                if (!$item) {
                    throw new RuntimeException('Menu item not found');
                }

                $rec = $pdo->prepare("SELECT id, portions_per_recipe, notes FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?");
                $rec->execute([$mid, $type]);
                $recipe = $rec->fetch(PDO::FETCH_ASSOC);

                $lines = [];
                if ($recipe) {
                    $ls = $pdo->prepare(
                        "SELECT ri.id, ri.ingredient_id, ri.quantity_per_portion, ri.yield_percent,
                            i.name, i.unit, i.cost_per_unit
                     FROM stock_recipe_ingredients ri
                     JOIN stock_ingredients i ON i.id = ri.ingredient_id
                     WHERE ri.recipe_id = ?
                     ORDER BY i.name"
                    );
                    $ls->execute([$recipe['id']]);
                    $lines = $ls->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode([
                    'ok'      => true,
                    'item'    => $item,
                    'recipe'  => $recipe ?: null,
                    'lines'   => $lines,
                ]);
                break;
            }

        case 'save_recipe': {
                $mid  = (int)($_POST['menu_item_id'] ?? 0);
                $typeRaw2 = trim($_POST['menu_type'] ?? '');
                $catSlug2 = $pdo->prepare("SELECT slug FROM menu_categories WHERE slug = ? AND is_active = 1 LIMIT 1");
                $catSlug2->execute([$typeRaw2]);
                $type = (string)($catSlug2->fetchColumn() ?: 'food');
                $portions = max(1, (int)($_POST['portions'] ?? 1));
                $notes    = trim((string)($_POST['notes'] ?? ''));
                $linesJson = $_POST['lines'] ?? '[]';
                $lines = json_decode($linesJson, true);
                if (!is_array($lines)) {
                    $lines = [];
                }

                if ($mid <= 0) {
                    throw new InvalidArgumentException('menu_item_id required');
                }

                // Validate menu item exists in unified table
                $check = $pdo->prepare("SELECT id FROM menu_items WHERE id = ?");
                $check->execute([$mid]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Menu item not found');
                }

                $pdo->beginTransaction();

                // Upsert recipe row
                $up = $pdo->prepare(
                    "INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe, notes, created_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    portions_per_recipe = VALUES(portions_per_recipe),
                    notes = VALUES(notes),
                    updated_at = CURRENT_TIMESTAMP,
                    id = LAST_INSERT_ID(id)"
                );
                $up->execute([$mid, $type, $portions, $notes, (int)$_SESSION['admin_user_id']]);
                $recipeId = (int)$pdo->lastInsertId();

                // Replace ingredient lines
                $del = $pdo->prepare("DELETE FROM stock_recipe_ingredients WHERE recipe_id = ?");
                $del->execute([$recipeId]);

                $ins = $pdo->prepare(
                    "INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent)
                 VALUES (?, ?, ?, ?)"
                );
                $written = 0;
                foreach ($lines as $line) {
                    $ingId = (int)($line['ingredient_id'] ?? 0);
                    $qty   = (float)($line['quantity'] ?? 0);
                    $yld   = isset($line['yield_percent']) && $line['yield_percent'] !== ''
                        ? max(1, min(100, (float)$line['yield_percent'])) : 100;
                    if ($ingId > 0 && $qty > 0) {
                        $ins->execute([$recipeId, $ingId, $qty, $yld]);
                        $written++;
                    }
                }

                $pdo->commit();
                echo json_encode([
                    'ok' => true,
                    'recipe_id' => $recipeId,
                    'lines_written' => $written,
                    'message' => "Recipe saved ($written ingredient line" . ($written === 1 ? '' : 's') . ')'
                ]);
                break;
            }

        case 'delete_recipe': {
                $mid  = (int)($_POST['menu_item_id'] ?? 0);
                $typeRaw3 = trim($_POST['menu_type'] ?? '');
                $catSlug3 = $pdo->prepare("SELECT slug FROM menu_categories WHERE slug = ? AND is_active = 1 LIMIT 1");
                $catSlug3->execute([$typeRaw3]);
                $type = (string)($catSlug3->fetchColumn() ?: 'food');
                if ($mid <= 0) {
                    throw new InvalidArgumentException('menu_item_id required');
                }
                $del = $pdo->prepare("DELETE FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?");
                $del->execute([$mid, $type]);
                echo json_encode(['ok' => true, 'deleted' => $del->rowCount()]);
                break;
            }

        case 'add_ingredient': {
                $name = trim((string)($_POST['name'] ?? ''));
                $cat  = trim((string)($_POST['category'] ?? 'General'));
                $unit = trim((string)($_POST['unit'] ?? 'g'));
                $cost = (float)($_POST['cost_per_unit'] ?? 0);
                $minq = (float)($_POST['min_quantity'] ?? 0);
                $yld  = max(1.0, min(100.0, (float)($_POST['yield_percent'] ?? 100)));
                if ($name === '') {
                    throw new InvalidArgumentException('Ingredient name required');
                }
                if ($unit === '') {
                    $unit = 'g';
                }

                // Reuse existing by case-insensitive name
                $find = $pdo->prepare("SELECT id FROM stock_ingredients WHERE LOWER(name) = LOWER(?) LIMIT 1");
                $find->execute([$name]);
                $existing = $find->fetchColumn();
                if ($existing) {
                    echo json_encode(['ok' => true, 'id' => (int)$existing, 'reused' => true]);
                    break;
                }

                $ins = $pdo->prepare(
                    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, min_quantity, cost_per_unit, yield_percent, is_archived)
                 VALUES (?, ?, ?, 0, ?, ?, ?, 0)"
                );
                $ins->execute([$name, $cat ?: 'General', $unit, $minq, $cost, $yld]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'reused' => false]);
                break;
            }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

