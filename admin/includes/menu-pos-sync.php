<?php

/**
 * Keeps the till's catalogue (menu_categories + menu_items) in step with the menu staff edit in
 * Menu Management (food_menu / drink_menu, the website menu).
 *
 * Why: the till, room service, the kitchen screens and the stock link (recipes) all work from
 * menu_items ids, while Menu Management edits the website tables. Before this, an item added in
 * Menu Management never reached the till (only the CLI script scripts/import_menu_to_pos.php
 * copied it), and the Recipe button attached the recipe to the wrong id. Every save in Menu
 * Management now writes both, in the same transaction.
 *
 * The two rows are matched on category label + item name (the same key import_menu_to_pos.php
 * uses), so rows created by that script are picked up, not duplicated.
 */

if (!function_exists('rh_menu_pos_key')) {

    /** Match key between a website menu row and its till row. */
    function rh_menu_pos_key(string $itemName, string $category): string
    {
        return mb_strtolower(rh_clean_text($itemName)) . '|' . mb_strtolower(rh_clean_text($category));
    }

    function rh_menu_pos_slug(string $name): string
    {
        $s = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
        return $s !== '' ? $s : 'category';
    }

    /**
     * The till category for a menu category label (created when it does not exist yet).
     * Prefers a category whose default station matches the item's station ("Desserts" kitchen vs
     * "Desserts (Bar)"), like the import script does.
     *
     * @return array{id:int,slug:string}
     */
    function rh_menu_pos_category(PDO $pdo, string $label, string $station): array
    {
        $label = rh_clean_text($label);
        $stationLabel = ucfirst(str_replace('_', ' ', $station));
        $find = $pdo->prepare(
            "SELECT id, slug FROM menu_categories
              WHERE COALESCE(business_context, 'food_service') = 'food_service'
                AND (LOWER(name) = LOWER(?) OR LOWER(name) = LOWER(?))
              ORDER BY (default_station = ?) DESC, (LOWER(name) = LOWER(?)) DESC, id ASC
              LIMIT 1"
        );
        $find->execute([$label, $label . ' (' . $stationLabel . ')', $station, $label]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['id' => (int)$row['id'], 'slug' => (string)$row['slug']];
        }

        $name = mb_substr($label, 0, 100);
        $base = rh_menu_pos_slug($name);
        $slug = $base;
        $chk = $pdo->prepare("SELECT COUNT(*) FROM menu_categories WHERE slug = ?");
        for ($n = 2; $n < 200; $n++) {
            $chk->execute([$slug]);
            if ((int)$chk->fetchColumn() === 0) {
                break;
            }
            $slug = $base . '-' . $n;
        }
        $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_categories")->fetchColumn();
        $ins = $pdo->prepare(
            "INSERT INTO menu_categories (name, slug, business_context, default_station, sort_order, shows_on_pos, shows_on_room_service, display_order, is_active)
             VALUES (?, ?, 'food_service', ?, ?, 1, 1, ?, 1)"
        );
        $ins->execute([$name, $slug, $station, $sort, $sort]);
        return ['id' => (int)$pdo->lastInsertId(), 'slug' => $slug];
    }

    /** Live (non-void) lines of this till item on open ('placed') orders. */
    function rh_menu_pos_open_lines(PDO $pdo, int $posId): int
    {
        $open = $pdo->prepare(
            "SELECT COUNT(*) FROM stock_order_items soi JOIN stock_orders so ON so.id = soi.order_id
              WHERE soi.menu_item_id = ? AND soi.kds_status <> 'void' AND so.status = 'placed'"
        );
        $open->execute([$posId]);
        return (int)$open->fetchColumn();
    }

    /** Find the till row for a website label+name (lowest id when more than one). */
    function rh_menu_pos_find(PDO $pdo, string $itemName, string $category): ?array
    {
        $st = $pdo->prepare(
            "SELECT mi.id, mi.barcode, mc.slug
               FROM menu_items mi
               JOIN menu_categories mc ON mc.id = mi.category_id
              WHERE LOWER(mi.item_name) = LOWER(?) AND LOWER(COALESCE(mi.category, '')) = LOWER(?)
                AND COALESCE(mc.business_context, 'food_service') = 'food_service'
              ORDER BY mi.id ASC LIMIT 1"
        );
        $st->execute([rh_clean_text($itemName), rh_clean_text($category)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? ['id' => (int)$row['id'], 'slug' => (string)$row['slug'], 'barcode' => $row['barcode']] : null;
    }

    /**
     * Create or update the till row for one website menu row.
     *
     * @param string      $menuType 'food' or 'drink'
     * @param array       $row      the website row as saved: item_name, description, price, category, station,
     *                              is_available, display_order, show_pos, show_room_service, tags (drinks).
     *                              is_available null = leave the till's availability as it is (an item switched
     *                              off at the till stays off when only its price or name is edited here)
     * @param array|null  $old      item_name + category BEFORE this edit (so a rename updates, not duplicates)
     * @param string|null $barcode  null = leave as is, '' = clear, else set (checked for duplicates)
     * @param bool        $insertOnly true = never change a till row that already matches (page-load backfill)
     * @return array{id:int,slug:string,created:bool}
     * @throws RuntimeException when the barcode already belongs to another item
     */
    function rh_menu_pos_sync_item(PDO $pdo, string $menuType, array $row, ?array $old = null, ?string $barcode = null, bool $insertOnly = false): array
    {
        $station = in_array($row['station'] ?? '', ['kitchen', 'bar', 'coffee_bar'], true) ? $row['station'] : ($menuType === 'food' ? 'kitchen' : 'bar');
        $label = rh_clean_text((string)$row['category']);

        $existing = null;
        if ($old) {
            $existing = rh_menu_pos_find($pdo, (string)$old['item_name'], (string)$old['category']);
        }
        if (!$existing) {
            $existing = rh_menu_pos_find($pdo, (string)$row['item_name'], $label);
        }
        if ($existing && $insertOnly) {
            return ['id' => $existing['id'], 'slug' => $existing['slug'], 'created' => false];
        }
        $cat = rh_menu_pos_category($pdo, $label, $station);

        $bc = null; // value to write when $barcode !== null
        if ($barcode !== null) {
            $bc = rhNormalizeBarcode($barcode);
            if (mb_strlen($bc) > 100) {
                throw new RuntimeException('Barcode is too long (100 characters maximum).');
            }
            if ($bc !== '') {
                $taken = rhLookupBarcode($pdo, $bc, 'menu_item');
                if ($taken !== null && (int)($taken['row']['id'] ?? 0) !== (int)($existing['id'] ?? 0)) {
                    throw new RuntimeException('That barcode is already used by "' . rh_clean_text((string)($taken['row']['name'] ?? 'another item')) . '".');
                }
            }
        }

        $vals = [
            (int)$cat['id'],
            mb_substr(rh_clean_text((string)$row['item_name']), 0, 255),
            ($row['description'] ?? '') !== '' ? (string)$row['description'] : null,
            (float)$row['price'],
            $label,
            array_key_exists('is_available', $row) && $row['is_available'] === null ? 1 : (int)!empty($row['is_available']),
            (int)($row['display_order'] ?? 0),
            $station,
            (int)!empty($row['show_pos']),
            (int)!empty($row['show_room_service']),
            $menuType === 'drink' ? (($row['tags'] ?? '') !== '' ? (string)$row['tags'] : null) : null,
        ];

        if ($existing) {
            // Checked before anything is written: the recipe is keyed by category slug and an open order line keeps the old one.
            if ($existing['slug'] !== $cat['slug'] && rh_menu_pos_open_lines($pdo, (int)$existing['id']) > 0) {
                throw new RuntimeException('This item is on an open order, so it cannot move to another category yet. Close or settle those orders first (changing the category would stop its stock being taken off).');
            }
            $sql = "UPDATE menu_items SET category_id = ?, item_name = ?, description = ?, price = ?, category = ?, is_available = ?,
                           display_order = ?, station = ?, show_pos = ?, show_room_service = ?, tags = ?";
            $params = $vals;
            if (array_key_exists('is_available', $row) && $row['is_available'] === null) {
                $sql = str_replace('is_available = ?,', 'is_available = is_available,', $sql);
                unset($params[5]);
                $params = array_values($params);
            }
            if ($bc !== null) {
                $sql .= ", barcode = ?";
                $params[] = $bc !== '' ? $bc : null;
            }
            $sql .= " WHERE id = ?";
            $params[] = $existing['id'];
            $pdo->prepare($sql)->execute($params);
            $posId = (int)$existing['id'];
            // The recipe is keyed by the category slug: when the item moves category, move it too,
            // or the next sale would look for the recipe under the new slug and deduct nothing.
            if ($existing['slug'] !== $cat['slug']) {
                $pdo->prepare("UPDATE stock_recipes SET menu_type = ? WHERE menu_item_id = ? AND menu_type = ?")
                    ->execute([$cat['slug'], $posId, $existing['slug']]);
            }
            return ['id' => $posId, 'slug' => $cat['slug'], 'created' => false];
        }

        $cols = "category_id, item_name, description, price, category, is_available, display_order, station, show_pos, show_room_service, tags, currency_code";
        $ph = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
        $params = array_merge($vals, [(string)($row['currency_code'] ?? 'MWK') ?: 'MWK']);
        if ($bc !== null && $bc !== '') {
            $cols .= ', barcode';
            $ph .= ', ?';
            $params[] = $bc;
        }
        $pdo->prepare("INSERT INTO menu_items ($cols) VALUES ($ph)")->execute($params);
        return ['id' => (int)$pdo->lastInsertId(), 'slug' => $cat['slug'], 'created' => true];
    }

    /** All till rows keyed by rh_menu_pos_key(): id, slug, barcode (what Menu Management needs per row). */
    function rh_menu_pos_map(PDO $pdo): array
    {
        $map = [];
        $q = $pdo->query(
            "SELECT mi.id, mi.item_name, mi.category, mi.barcode, mi.show_pos, mi.show_room_service, mi.is_available, mc.slug
               FROM menu_items mi
               JOIN menu_categories mc ON mc.id = mi.category_id
              WHERE COALESCE(mc.business_context, 'food_service') = 'food_service'
              ORDER BY mi.id DESC"
        );
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // Lowest id wins (same as rh_menu_pos_find): rows are read newest first and overwritten.
            $map[rh_menu_pos_key((string)$r['item_name'], (string)($r['category'] ?? ''))] = [
                'id' => (int)$r['id'], 'slug' => (string)$r['slug'], 'barcode' => $r['barcode'],
                'show_pos' => (int)$r['show_pos'], 'show_room_service' => (int)$r['show_room_service'],
                'is_available' => (int)$r['is_available'],
            ];
        }
        return $map;
    }

    /**
     * Make sure every website menu row has a till row (insert-only, idempotent).
     * Returns how many were created.
     */
    function rh_menu_pos_backfill(PDO $pdo, array $websiteRowsByType): int
    {
        $map = rh_menu_pos_map($pdo);
        $missing = [];
        foreach ($websiteRowsByType as $type => $rows) {
            foreach ($rows as $r) {
                if (!isset($map[rh_menu_pos_key((string)$r['item_name'], (string)$r['category'])])) {
                    $missing[] = [$type, $r];
                }
            }
        }
        if (!$missing) {
            return 0;
        }
        // Two people opening the page at once must not both create the same till row (no unique key to stop it).
        $lock = substr('rh_menu_pos_backfill_' . (defined('DB_NAME') ? DB_NAME : 'db'), 0, 64);
        if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 5)')->fetchColumn() !== 1) {
            return 0;
        }
        try {
            $map = rh_menu_pos_map($pdo); // re-read: the other request may have just done it
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            $made = 0;
            try {
                foreach ($missing as [$type, $r]) {
                    if (isset($map[rh_menu_pos_key((string)$r['item_name'], (string)$r['category'])])) {
                        continue;
                    }
                    $res = rh_menu_pos_sync_item($pdo, $type, $r, null, null, true);
                    $made += $res['created'] ? 1 : 0;
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
            return $made;
        } finally {
            try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')'); } catch (Throwable $e2) {}
        }
    }

    /**
     * Take an item off the menu for good, or hide it when it has sales history.
     *
     * @return array{mode:string,open_lines:int,deals:int}  mode: 'deleted' | 'archived' | 'blocked'
     */
    function rh_menu_pos_remove(PDO $pdo, int $posId, string $slug): array
    {
        $openLines = rh_menu_pos_open_lines($pdo, $posId);
        if ($openLines > 0) {
            return ['mode' => 'blocked', 'open_lines' => $openLines, 'deals' => 0];
        }

        $hist = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE menu_item_id = ?");
        $hist->execute([$posId]);
        $hasHistory = ((int)$hist->fetchColumn() > 0);
        if (!$hasHistory) {
            try {
                $chg = $pdo->prepare("SELECT COUNT(*) FROM booking_charges WHERE source_item_id = ?");
                $chg->execute([$posId]);
                $hasHistory = ((int)$chg->fetchColumn() > 0);
            } catch (Throwable $e) {
                // legacy schema without booking_charges.source_item_id: nothing to check
            }
        }
        if ($hasHistory) {
            // History stays readable (orders, folios, reports): hide the item everywhere instead.
            $pdo->prepare("UPDATE menu_items SET is_available = 0, show_pos = 0, show_room_service = 0 WHERE id = ?")->execute([$posId]);
            return ['mode' => 'archived', 'open_lines' => 0, 'deals' => 0];
        }

        $pdo->prepare("DELETE FROM stock_recipes WHERE menu_item_id = ?")->execute([$posId]);
        $deals = 0;
        try {
            $dl = $pdo->query("SELECT id, item_ids FROM pos_deals WHERE item_ids IS NOT NULL AND item_ids <> '' FOR UPDATE");
            foreach ($dl->fetchAll(PDO::FETCH_ASSOC) as $deal) {
                $ids = json_decode((string)$deal['item_ids'], true);
                if (!is_array($ids)) {
                    continue;
                }
                $kept = array_values(array_filter($ids, static function ($v) use ($posId) { return (int)$v !== $posId; }));
                if (count($kept) === count($ids)) {
                    continue;
                }
                if ($kept) {
                    $pdo->prepare("UPDATE pos_deals SET item_ids = ? WHERE id = ?")->execute([json_encode($kept), (int)$deal['id']]);
                } else {
                    $pdo->prepare("UPDATE pos_deals SET item_ids = NULL, is_active = 0 WHERE id = ?")->execute([(int)$deal['id']]);
                }
                $deals++;
            }
        } catch (Throwable $e) {
            error_log('menu delete: deal item_ids cleanup: ' . $e->getMessage());
        }
        $pdo->prepare("DELETE FROM menu_items WHERE id = ?")->execute([$posId]);
        return ['mode' => 'deleted', 'open_lines' => 0, 'deals' => $deals];
    }
}
