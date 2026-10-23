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
require_once __DIR__ . '/../includes/menu-recipe-lib.php';

header('Content-Type: application/json');

requireApiAnyPermission(['menu', 'stock_management']);

if (!function_exists('ensureStockTablesExist') || !ensureStockTablesExist()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Stock tables unavailable']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

// Every non-GET call changes recipes/ingredients: it must carry the admin CSRF token.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $csrfSent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
    if (!validateCsrfToken($csrfSent)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Security token invalid. Refresh the page and try again.']);
        exit;
    }
}

try {
    switch ($action) {

        case 'list_ingredients': {
                $rows = $pdo->query(
                    "SELECT id, name, category, unit, cost_per_unit, current_quantity, min_quantity, yield_percent, pack_name, pack_size, pieces_per_unit
                 FROM stock_ingredients
                 WHERE is_archived = 0
                 ORDER BY category, name"
                )->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['ok' => true, 'ingredients' => $rows]);
                break;
            }

        case 'get_recipe': {
                $mid  = (int)($_GET['menu_item_id'] ?? 0);
                                if ($mid <= 0) {
                    throw new InvalidArgumentException('menu_item_id required');
                }
                $type = rh_menu_item_slug($pdo, $mid);

                $itemStmt = $pdo->prepare("SELECT mi.id, mi.item_name, mi.price FROM menu_items mi WHERE mi.id = ?");
                $itemStmt->execute([$mid]);
                $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
                if (!$item) {
                    throw new RuntimeException('Menu item not found');
                }

                $rec = $pdo->prepare("SELECT id, portions_per_recipe, notes FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?");
                $rec->execute([$mid, $type]);
                $recipe = $rec->fetch(PDO::FETCH_ASSOC);

                $lines = $recipe ? rh_menu_recipe_lines($pdo, $mid, $type) : [];

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
                                $portions = isset($_POST['portions']) ? max(1, (int)$_POST['portions']) : null;
                $notes    = isset($_POST['notes']) ? trim((string)$_POST['notes']) : null;
                $linesJson = $_POST['lines'] ?? '[]';
                $lines = json_decode($linesJson, true);
                if (!is_array($lines)) {
                    $lines = [];
                }

                if ($mid <= 0) {
                    throw new InvalidArgumentException('menu_item_id required');
                }
                // The recipe is keyed by the item's own category slug (what the till sells it under).
                $type = rh_menu_item_slug($pdo, $mid);

                $res = rh_menu_recipe_save($pdo, $mid, $type, $portions, $notes, $lines, (int)$_SESSION['admin_user_id']);
                $written = $res['lines_written'];
                echo json_encode([
                    'ok' => true,
                    'recipe_id' => $res['recipe_id'],
                    'lines_written' => $written,
                    'message' => "Stock link saved ($written stock item" . ($written === 1 ? '' : 's') . ')'
                ]);
                break;
            }

        case 'delete_recipe': {
                $mid  = (int)($_POST['menu_item_id'] ?? 0);
                                if ($mid <= 0) {
                    throw new InvalidArgumentException('menu_item_id required');
                }
                $type = rh_menu_item_slug($pdo, $mid);
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

