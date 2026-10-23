<?php

/**
 * Menu item <-> stock link ("recipe") helpers shared by Menu Management and its recipe API.
 *
 * A recipe line says how much of one stock item ONE menu item sold uses, always in the stock
 * item's BASE unit (stock_ingredients.unit, e.g. "each", "kg", "ml") - that is the figure the
 * till, the kitchen screens, cost of goods and stock reports all read (quantity_per_portion).
 * Staff may type the amount in the stock item's PACK instead (1 Pack = 10 each); it is converted
 * to the base unit here, once, on save. Nothing downstream ever sees pack units.
 *
 * Tables are the existing stock_recipes / stock_recipe_ingredients (same as stock-recipes.php).
 */

if (!function_exists('rh_recipe_qty_to_base')) {

    /**
     * What a stock unit measures when it is a BIG weight or volume: 'g' for kg / kilogram (small unit = grams),
     * 'ml' for litre / l / ltr (small unit = millilitres), null for everything else (g and ml themselves are
     * already the small unit, so staff just type whole numbers in them). Same word lists as Menu Management's page script.
     */
    function rh_recipe_small_unit(string $unit): ?string
    {
        $u = trim($unit);
        if (preg_match('/^(kgs?|kilograms?|kilos?)$/i', $u)) {
            return 'g';
        }
        if (preg_match('/^(l|ltrs?|litres?|liters?)$/i', $u)) {
            return 'ml';
        }
        return null;
    }

    /**
     * Convert what staff typed into the stock item's base unit.
     *
     * @param string $entryUnit 'base' (the stock unit), 'pack' (one purchase pack = $packSize base units),
     *                          'pieces' ($qty single pieces; one stock unit holds $piecesPerUnit pieces)
     *                          or 'small' ($qty whole grams / millilitres of a kg / litre item; divided by 1000)
     * @throws RuntimeException on a quantity that is not a positive number, or a pack/pieces choice with no size
     */
    function rh_recipe_qty_to_base(float $qty, string $entryUnit, ?float $packSize, ?float $piecesPerUnit = null): float
    {
        if (!is_finite($qty) || $qty <= 0) {
            throw new RuntimeException('Enter a quantity greater than 0.');
        }
        if ($entryUnit === 'small') {
            // Whole grams / ml only (1 to 1,000,000), so a typed 250 is exactly 0.25 and never a rounding surprise.
            if ($qty < 1 || $qty > 1000000 || abs($qty - round($qty)) > 1e-9) {
                throw new RuntimeException('Type a whole number of grams or ml (1 to 1,000,000).');
            }
            $qty = round($qty) / 1000;
        } elseif ($entryUnit === 'pack') {
            if ($packSize === null || $packSize <= 0) {
                throw new RuntimeException('This stock item has no pack size, so the quantity cannot be entered in packs.');
            }
            $qty = $qty * $packSize;
        } elseif ($entryUnit === 'pieces') {
            if ($piecesPerUnit === null || $piecesPerUnit <= 0) {
                throw new RuntimeException('Set "Pieces in one packet" on this stock item in Stock first.');
            }
            // Whole pieces only (1, 2, 3), or a quarter / half / three-quarters from the fraction buttons.
            $frac = $qty - floor($qty);
            $okFrac = $frac < 1e-9 || abs($frac - 0.25) < 1e-9 || abs($frac - 0.5) < 1e-9 || abs($frac - 0.75) < 1e-9;
            if ($qty > 99999 || !$okFrac) {
                throw new RuntimeException('Pieces must be a whole number (1, 2, 3) or end in a quarter, half or three-quarters, and at most 99999.');
            }
            $qty = $qty / $piecesPerUnit;
        } elseif ($entryUnit !== 'base') {
            throw new RuntimeException('Unknown unit choice.');
        }
        $base = round($qty, 6); // stock quantities keep 6 decimals (1 piece of 6 = 0.166667)
        if ($base <= 0) {
            throw new RuntimeException('That quantity is too small to record (smallest is 0.000001).');
        }
        if ($base > 99999999) {
            throw new RuntimeException('That quantity is too large.');
        }
        return $base;
    }

    /** 10 -> "10", 0.15 -> "0.15", 0.1500 -> "0.15" (trailing zeros dropped). */
    function rh_recipe_fmt_qty(float $n, int $max = 6): string
    {
        $s = rtrim(rtrim(number_format($n, $max, '.', ''), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /**
     * One plain sentence for a recipe line, e.g.
     * "Uses 1 each of Sausages (pack of 10) per item sold -> 1 pack serves 10".
     * $qtyBase is the base-unit quantity per item sold.
     */
    function rh_recipe_preview_text(string $name, string $unit, ?string $packName, ?float $packSize, float $qtyBase): string
    {
        $hasPack = $packSize !== null && $packSize > 0;
        $packWord = ($packName !== null && trim($packName) !== '') ? mb_strtolower(trim($packName)) : 'pack';
        $text = 'Uses ' . rh_recipe_fmt_qty($qtyBase) . ' ' . $unit . ' of ' . $name;
        if ($hasPack) {
            $text .= ' (' . $packWord . ' of ' . rh_recipe_fmt_qty((float)$packSize, 3) . ')';
        }
        $text .= ' per item sold';
        if ($hasPack && $qtyBase > 0) {
            $text .= ' → 1 ' . $packWord . ' serves ' . rh_recipe_fmt_qty((float)$packSize / $qtyBase, 2);
        }
        return $text;
    }

    /** The category slug a till item is sold under (the recipe's menu_type). @throws RuntimeException when the item is gone */
    function rh_menu_item_slug(PDO $pdo, int $menuItemId): string
    {
        $st = $pdo->prepare("SELECT mc.slug FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mi.id = ?");
        $st->execute([$menuItemId]);
        $slug = $st->fetchColumn();
        if ($slug === false || $slug === null || $slug === '') {
            throw new RuntimeException('Menu item not found');
        }
        return (string)$slug;
    }

    /** Recipe lines of one menu item (POS item id + category slug), with the stock item details. */
    function rh_menu_recipe_lines(PDO $pdo, int $menuItemId, string $menuType): array
    {
        $st = $pdo->prepare(
            "SELECT ri.id, ri.ingredient_id, ri.quantity_per_portion, ri.yield_percent,
                    i.name, i.unit, i.cost_per_unit, i.pack_name, i.pack_size, i.pieces_per_unit, i.is_archived
               FROM stock_recipes sr
               JOIN stock_recipe_ingredients ri ON ri.recipe_id = sr.id
               JOIN stock_ingredients i ON i.id = ri.ingredient_id
              WHERE sr.menu_item_id = ? AND sr.menu_type = ?
              ORDER BY i.name"
        );
        $st->execute([$menuItemId, $menuType]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cost of goods for ONE item sold, from the recipe: sum of (base quantity / yield) x cost per base unit.
     * Same arithmetic as the till, the kitchen screens and the stock reports.
     */
    function rh_menu_recipe_cost(PDO $pdo, int $menuItemId, string $menuType): float
    {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM((sri.quantity_per_portion / " . rh_stock_yield_sql('sri.yield_percent') . ") * i.cost_per_unit), 0)
               FROM stock_recipes sr
               JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
               JOIN stock_ingredients i ON i.id = sri.ingredient_id
              WHERE sr.menu_item_id = ? AND sr.menu_type = ?"
        );
        $st->execute([$menuItemId, $menuType]);
        return round((float)$st->fetchColumn(), 4);
    }

    /**
     * Save (replace) the recipe of one menu item.
     *
     * @param ?int $portions / ?string $notes null = keep the recipe's current value (1 / empty for a new recipe)
     * Pieces per packet is read from the stock item and is NEVER written from here (it is set in Stock only).
     * @param array $lines each: ingredient_id, quantity (as typed), unit ('base'|'pack'|'pieces'|'small', default base),
     *                     yield_percent (optional, 1-100; omitted = keep what the item had, else 100)
     * @return array{recipe_id:int,lines_written:int}
     * @throws RuntimeException|InvalidArgumentException on bad input (nothing is written)
     */
    function rh_menu_recipe_save(PDO $pdo, int $menuItemId, string $menuType, ?int $portions, ?string $notes, array $lines, ?int $userId): array
    {
        if ($menuItemId <= 0 || $menuType === '') {
            throw new InvalidArgumentException('Menu item required.');
        }

        // Portions and notes belong to Stock > Recipes: when the caller does not say, keep what is there.
        $cur = $pdo->prepare("SELECT portions_per_recipe, notes FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?");
        $cur->execute([$menuItemId, $menuType]);
        $curRow = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
        $portions = max(1, $portions ?? (int)($curRow['portions_per_recipe'] ?? 1));
        $notes = $notes ?? (string)($curRow['notes'] ?? '');

        // Existing yields, so an edit that does not mention yield keeps the stock lead's per-line setting.
        $keepYield = [];
        $ex = $pdo->prepare(
            "SELECT ri.ingredient_id, ri.yield_percent
               FROM stock_recipes sr JOIN stock_recipe_ingredients ri ON ri.recipe_id = sr.id
              WHERE sr.menu_item_id = ? AND sr.menu_type = ?"
        );
        $ex->execute([$menuItemId, $menuType]);
        foreach ($ex->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $keepYield[(int)$r['ingredient_id']] = (float)$r['yield_percent'];
        }

        $ingStmt = $pdo->prepare("SELECT id, name, unit, pack_size, pieces_per_unit, is_archived FROM stock_ingredients WHERE id = ?");
        $clean = [];
        $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $ingId = (int)($line['ingredient_id'] ?? 0);
            if ($ingId <= 0) {
                continue;
            }
            if (isset($seen[$ingId])) {
                throw new InvalidArgumentException('A stock item can only appear once - combine the quantities into one line.');
            }
            $seen[$ingId] = true;
            $ingStmt->execute([$ingId]);
            $ing = $ingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ing) {
                throw new RuntimeException('A stock item in this list no longer exists. Reload and try again.');
            }
            if ((int)$ing['is_archived'] === 1 && !isset($keepYield[$ingId])) {
                throw new RuntimeException('"' . $ing['name'] . '" is archived in Stock and cannot be added to an item.');
            }
            $packSize = ($ing['pack_size'] !== null && (float)$ing['pack_size'] > 0) ? (float)$ing['pack_size'] : null;
            $entryUnit = (string)($line['unit'] ?? 'base');
            $piecesPer = rh_stock_pieces_per_unit((string)$ing['unit'], $ing['pieces_per_unit']);
            if ($entryUnit === 'pieces') {
                if (!rh_stock_unit_is_container((string)$ing['unit'])) {
                    throw new RuntimeException($ing['name'] . ': pieces can only be used for stock counted in a packet, box, dozen or similar.');
                }
                if ($piecesPer === null) {
                    throw new RuntimeException($ing['name'] . ': set "Pieces in one ' . $ing['unit'] . '" on this stock item in Stock first, then you can use single pieces.');
                }
            }
            if ($entryUnit === 'small' && rh_recipe_small_unit((string)$ing['unit']) === null) {
                throw new RuntimeException($ing['name'] . ': grams / ml can only be used for stock counted in kg or litres (use the stock unit instead).');
            }
            try {
                $base = rh_recipe_qty_to_base((float)($line['quantity'] ?? 0), $entryUnit, $packSize, $piecesPer);
            } catch (RuntimeException $e) {
                throw new RuntimeException($ing['name'] . ': ' . $e->getMessage());
            }
            if (isset($line['yield_percent']) && $line['yield_percent'] !== '') {
                $yld = max(1.0, min(100.0, (float)$line['yield_percent']));
            } else {
                $yld = $keepYield[$ingId] ?? 100.0;
            }
            $clean[] = [$ingId, $base, $yld];
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $up = $pdo->prepare(
                "INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe, notes, created_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    portions_per_recipe = VALUES(portions_per_recipe),
                    notes = VALUES(notes),
                    updated_at = CURRENT_TIMESTAMP,
                    id = LAST_INSERT_ID(id)"
            );
            $up->execute([$menuItemId, $menuType, $portions, $notes, $userId]);
            $recipeId = (int)$pdo->lastInsertId();

            $pdo->prepare("DELETE FROM stock_recipe_ingredients WHERE recipe_id = ?")->execute([$recipeId]);
            $ins = $pdo->prepare(
                "INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent) VALUES (?, ?, ?, ?)"
            );
            foreach ($clean as [$ingId, $base, $yld]) {
                $ins->execute([$recipeId, $ingId, $base, $yld]);
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['recipe_id' => $recipeId, 'lines_written' => count($clean)];
    }

    /** Remove the stock link of one menu item (lines go with it by foreign key). Returns rows removed. */
    function rh_menu_recipe_clear(PDO $pdo, int $menuItemId, string $menuType): int
    {
        $del = $pdo->prepare("DELETE FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ?");
        $del->execute([$menuItemId, $menuType]);
        return $del->rowCount();
    }
}
