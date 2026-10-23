<?php
/**
 * Stock sheet import (Rosalyn's October 2026 sheet) - planning, applying and the report.
 * Used by scripts/import_stock_sheet.php (CLI only) and scripts/tests/stock_sheet_import.php.
 *
 * rh_ssi_plan()   reads the sheet + the database and decides everything; it writes nothing.
 * rh_ssi_apply()  writes that plan in the caller's transaction; running it twice changes nothing the second time.
 * rh_ssi_report() turns a plan into the plain-text dry-run report.
 *
 * What the import does NOT do: delete or archive anything, touch recipes, or approve the opening count.
 */

if (!function_exists('rh_ssi_ensure_table')) {
    /** Opening-stock batches waiting for the opening count to be approved. DDL: call BEFORE the transaction. */
    function rh_ssi_ensure_table(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS stock_opening_batches (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                count_id INT UNSIGNED NOT NULL,
                ingredient_id INT UNSIGNED NOT NULL,
                batch_label VARCHAR(50) DEFAULT NULL,
                quantity DECIMAL(16,6) NOT NULL DEFAULT 0,
                expiry_date DATE DEFAULT NULL,
                applied_batch_id INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sob_count (count_id, ingredient_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('rh_ssi_key')) {
    /** Case-insensitive, trimmed, single-spaced name used to match sheet rows to ingredients. */
    function rh_ssi_key(string $name): string
    {
        $n = preg_replace('/[\s\x{00A0}]+/u', ' ', $name);
        return mb_strtolower(trim((string)$n));
    }
}

if (!function_exists('rh_ssi_num')) {
    /** A number from a sheet cell, or null when blank / not a number. */
    function rh_ssi_num($v): ?float
    {
        $v = trim(str_replace(',', '', (string)$v));
        return ($v === '' || !is_numeric($v)) ? null : (float)$v;
    }
}

if (!function_exists('rh_ssi_load_csv')) {
    /** @return array<int,array<string,string>> rows keyed by the header names */
    function rh_ssi_load_csv(string $path): array
    {
        $fh = @fopen($path, 'r');
        if (!$fh) {
            throw new RuntimeException("Cannot read the CSV: $path");
        }
        $head = fgetcsv($fh);
        if (!$head) {
            throw new RuntimeException('The CSV is empty.');
        }
        $head = array_map(static fn($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h)), $head);
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            if ($r === [null] || $r === []) {
                continue;
            }
            $r = array_pad($r, count($head), '');
            $rows[] = array_combine($head, array_slice($r, 0, count($head)));
        }
        fclose($fh);
        return $rows;
    }
}

if (!function_exists('rh_ssi_load_purchase_qty')) {
    /**
     * The Bar sheet's "Purchase Qty" (column L) by sheet row, read from the unpacked workbook
     * (xl/worksheets/sheet2.xml + xl/sharedStrings.xml). qty is null when blank; formula = true when the
     * cell is a formula (the sheet's own MAX(0, max - balance), which is in bottles, not crates).
     *
     * @return array<int,array{name:string,qty:?float,formula:bool}>
     */
    function rh_ssi_load_purchase_qty(string $xlsxDir): array
    {
        $sheet = rtrim($xlsxDir, '/\\') . '/xl/worksheets/sheet2.xml';
        $strings = rtrim($xlsxDir, '/\\') . '/xl/sharedStrings.xml';
        if (!is_file($sheet) || !is_file($strings)) {
            throw new RuntimeException("Workbook files not found under $xlsxDir (need xl/worksheets/sheet2.xml and xl/sharedStrings.xml).");
        }
        $shared = [];
        $sx = simplexml_load_file($strings);
        foreach ($sx->si as $si) {
            $t = isset($si->t) ? (string)$si->t : '';
            foreach ($si->r as $run) {
                $t .= (string)$run->t;
            }
            $shared[] = $t;
        }
        $out = [];
        $sh = simplexml_load_file($sheet);
        foreach ($sh->sheetData->row as $row) {
            $n = (int)$row['r'];
            $name = ''; $qty = null; $formula = false;
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                if ($ref === 'A' . $n) {
                    $v = isset($c->v) ? (string)$c->v : '';
                    $name = ((string)$c['t'] === 's') ? ($shared[(int)$v] ?? '') : $v;
                } elseif ($ref === 'L' . $n) {
                    $formula = isset($c->f);
                    $qty = (isset($c->v) && (string)$c->v !== '') ? (float)$c->v : null;
                }
            }
            $out[$n] = ['name' => trim($name), 'qty' => $qty, 'formula' => $formula];
        }
        return $out;
    }
}

if (!function_exists('rh_ssi_norm_unit')) {
    /** Sheet unit text -> the stock unit we store ('' when the sheet gave none). */
    function rh_ssi_norm_unit(string $u): string
    {
        $k = mb_strtolower(trim($u));
        static $map = [
            'bottles' => 'bottle', 'bottle' => 'bottle', 'packets' => 'packet', 'packet' => 'packet',
            'packs' => 'pack', 'pack' => 'pack', 'pieces' => 'pcs', 'piece' => 'pcs', 'pcs' => 'pcs',
            'portions' => 'portion', 'portion' => 'portion', 'tins' => 'tin', 'tin' => 'tin',
            'litres' => 'L', 'litre' => 'L', 'liters' => 'L', 'liter' => 'L', 'l' => 'L',
            'mls' => 'ml', 'ml' => 'ml', 'grams' => 'g', 'gram' => 'g', 'g' => 'g', 'kg' => 'kg',
            'container' => 'container', 'containers' => 'container', 'box' => 'box', 'boxes' => 'box',
            'roll' => 'roll', 'rolls' => 'roll', 'cups' => 'cup', 'cup' => 'cup', 'heads' => 'head', 'head' => 'head',
            'bunch' => 'bunch', 'bunches' => 'bunch', 'bucket' => 'bucket', 'buckets' => 'bucket',
            'sachets' => 'sachet', 'sachet' => 'sachet', 'tablets' => 'tablet', 'tablet' => 'tablet',
        ];
        return $map[$k] ?? ($k === '' ? '' : mb_substr(trim($u), 0, 50));
    }
}

if (!function_exists('rh_ssi_units_compatible')) {
    /** Is a sheet unit the same thing as the ingredient's stock unit (each / pcs / piece count as one)? */
    function rh_ssi_units_compatible(string $sheetUnit, string $existingUnit): bool
    {
        $a = rh_ssi_norm_unit($sheetUnit);
        $b = rh_ssi_norm_unit($existingUnit);
        $count = ['each', 'pcs'];
        $b = in_array(mb_strtolower($existingUnit), ['each', 'piece', 'pieces', 'pcs'], true) ? 'pcs' : $b;
        $a = in_array($a, $count, true) ? 'pcs' : $a;
        return mb_strtolower($a) === mb_strtolower($b);
    }
}

if (!function_exists('rh_ssi_infer_pack')) {
    /**
     * Which crate size (6, 12 or 24) reproduces the sheet's purchase quantity?
     * ceil((max - balance) / p) must equal the quantity. One fit = the answer; none or several = ask the owner.
     *
     * @return array{pack:?int,fits:int[],reason:string}
     */
    function rh_ssi_infer_pack(float $balance, float $max, ?float $purchaseQty, bool $qtyIsFormula = false): array
    {
        if ($qtyIsFormula) {
            return ['pack' => null, 'fits' => [], 'reason' => 'the purchase quantity is the sheet\'s own formula (counted in bottles), so it says nothing about crate size'];
        }
        if ($purchaseQty === null) {
            return ['pack' => null, 'fits' => [], 'reason' => 'no purchase quantity on the sheet to work from'];
        }
        $need = max(0.0, $max - $balance);
        $fits = [];
        foreach ([6, 12, 24] as $p) {
            if ((int)ceil(round($need / $p, 6) - 0.000001) === (int)round($purchaseQty) && abs($purchaseQty - round($purchaseQty)) < 0.0001) {
                $fits[] = $p;
            }
        }
        if (count($fits) === 1) {
            return ['pack' => $fits[0], 'fits' => $fits, 'reason' => ''];
        }
        if (count($fits) === 0) {
            $why = abs($purchaseQty - round($purchaseQty)) >= 0.0001
                ? 'the purchase quantity (' . rtrim(rtrim(number_format($purchaseQty, 2), '0'), '.') . ') is not a whole number of crates'
                : 'no crate size of 6, 12 or 24 gives the sheet\'s purchase quantity of ' . rtrim(rtrim(number_format($purchaseQty, 2), '0'), '.') . ' (the price may already be per bottle)';
            return ['pack' => null, 'fits' => [], 'reason' => $why];
        }
        return ['pack' => null, 'fits' => $fits, 'reason' => 'more than one crate size fits (' . implode(', ', $fits) . ')' . ($need <= 0 ? ' - nothing to buy, so the quantity does not tell them apart' : '')];
    }
}

if (!function_exists('rh_ssi_half_crate_fits')) {
    /**
     * Hint only (never applied): the sheet sometimes buys in half crates (0.5, 1.5 ...). Which crate sizes give
     * that quantity when (max - balance) / p is rounded to the nearest half?
     * @return int[]
     */
    function rh_ssi_half_crate_fits(float $balance, float $max, float $qty): array
    {
        if (abs($qty * 2 - round($qty * 2)) > 0.0001) {
            return [];
        }
        $need = max(0.0, $max - $balance);
        $fits = [];
        foreach ([6, 12, 24] as $p) {
            if (abs(round($need / $p * 2) / 2 - $qty) < 0.0001) {
                $fits[] = $p;
            }
        }
        return $fits;
    }
}

if (!function_exists('rh_ssi_reorder_point')) {
    function rh_ssi_reorder_point(float $max, string $unit): float
    {
        if ($max <= 0) {
            return 0.0;
        }
        $fractional = in_array($unit, ['kg', 'g', 'L', 'ml'], true);
        $r = $fractional ? round($max * 0.3, 2) : (float)ceil($max * 0.3 - 0.000001);
        return min($r, $max);
    }
}

if (!function_exists('rh_ssi_merge_notes')) {
    /**
     * Existing notes with every line this import writes replaced (so a re-run changes nothing), then the sheet
     * section and the given import notes added. Anyone else's note lines are kept.
     */
    function rh_ssi_merge_notes(?string $existing, string $section, array $extras = []): string
    {
        $mine = '/^(Stock sheet|Price not on the July 2026 sheet|Unit chosen at import|Max level on the sheet looks wrong|Sheet price MWK|Sheet check:|Sheet clash:|Cost taken as per bottle|Crate of \d+ worked out)/i';
        $lines = [];
        foreach (preg_split('/\R/', (string)$existing) as $ln) {
            if (trim($ln) === '' || preg_match($mine, trim($ln))) {
                continue;
            }
            $lines[] = rtrim($ln);
        }
        if ($section !== '') {
            $lines[] = 'Stock sheet section: ' . $section;
        }
        foreach ($extras as $x) {
            $lines[] = $x;
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('rh_ssi_choose_unit')) {
    /** The unit that best suits an item the sheet gave no unit for (owner asked for a sensible pick; each is flagged "please check"). */
    function rh_ssi_choose_unit(string $name): string
    {
        static $map = [
            'eggs' => 'tray', 'yogurt' => 'cup', 'custard powder' => 'tin', 'cup cake baking' => 'packet', 'rajah carry' => 'packet',
            'tomato pure' => 'tin', 'charcoal' => 'bag', 'coffee beans' => 'kg', 'classic tea' => 'box',
            'all purpose cleaner' => 'bottle', 'airfreshner auto' => 'each', 'electrical tape' => 'roll', 'milk creamer' => 'sachet',
            'carbon paper' => 'packet', 'celtape' => 'roll', 'chalk' => 'box', 'pens (box)' => 'box', 'ream paper' => 'ream',
            'rubber band' => 'packet', 'sampler rolls' => 'roll', 'small envelopes' => 'packet', 'a4 envelope' => 'packet',
            'staples' => 'box', 'visa rolls' => 'roll', 'cash sale book' => 'book', 'delivery book' => 'book', 'hard cover book a4' => 'book',
            'invoice book (eco)' => 'book', 'invoice book (nbs)' => 'book', 'quotation book (eco)' => 'book', 'quotation book (nbs)' => 'book',
            'receipt book' => 'book',
        ];
        return $map[rh_ssi_key($name)] ?? 'each';
    }
}

if (!function_exists('rh_ssi_spirit_section')) {
    /** Bar sections whose prices are normally per BOTTLE (spirits, liqueurs, wines); beers, ciders and soft drinks are priced by the crate. */
    function rh_ssi_spirit_section(string $section): bool
    {
        return (bool)preg_match('/^(brandys|vodka|gins|flamed shooters|cognac brandy|cane spirit|rums|aperitifs|tequilas|liqueur shooters|creamy liquers|whiskys|house wines red|red wine by bottle|white wine by bottle)$/i', trim($section));
    }
}
if (!function_exists('rh_ssi_plan')) {
    /**
     * Decide everything (no writes).
     *
     * @param array<int,array<string,string>> $rows           the CSV rows
     * @param array<int,array{name:string,qty:?float,formula:bool}> $purchaseQty  Bar purchase quantities by sheet row
     * @param array<string,array{pack_size:?float,cost:?float}> $overrides  owner answers by name key
     * @param array{today?:string,budget_month?:string,budgets?:array<string,float>} $opts
     */
    function rh_ssi_plan(PDO $pdo, array $rows, array $purchaseQty, array $overrides = [], array $opts = []): array
    {
        $today = $opts['today'] ?? date('Y-m-d');
        $plan = [
            'items' => [], 'skipped' => [], 'duplicates' => [], 'row_count' => count($rows),
            'budget_month' => $opts['budget_month'] ?? '2026-10',
            'budgets' => $opts['budgets'] ?? ['Bar' => 3387814.00, 'Kitchen' => 4730287.50, 'Chemical' => 1524739.00, '77 Mug' => 0.0, 'Stationery' => 236240.00],
            'qty_name_mismatch' => [], 'existing_not_in_sheet' => [], 'covered_ids' => [], 'existing_count' => null,
        ];

        // ── 1. Group sheet rows into items ──────────────────────────────────────────────
        $items = [];
        foreach ($rows as $r) {
            $sheetRow = (int)($r['sheet_row'] ?? 0);
            $name = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', (string)($r['name'] ?? '')));
            $dept = trim((string)($r['department'] ?? ''));
            $skip = static function (string $why) use (&$plan, $dept, $name, $sheetRow) {
                $plan['skipped'][] = ['dept' => $dept, 'name' => $name, 'row' => $sheetRow, 'why' => $why];
            };
            if ($name === '') {
                $skip('blank name');
                continue;
            }
            if (preg_match('/^(sub\s*)?(grand\s+)?totals?\b/i', $name)) {
                $skip('a total line, not an item');
                continue;
            }
            if (preg_match('/^re-?order\s+items?$/i', $name)) {
                $skip('a heading on the sheet ("' . $name . '"), not a stock item');
                continue;
            }
            $key = rh_ssi_key($name);
            // The same name in a DIFFERENT department is a different stock location (e.g. Amarula at the bar and
            // at 77 Mug): kept as its own item "Name (Dept)" so no stock is lost; merge them later if they are one.
            $renamedFrom = '';
            if (isset($items[$key]) && $items[$key]['dept'] !== $dept) {
                $renamedFrom = $name;
                $name = $name . ' (' . $dept . ')';
                $key = rh_ssi_key($name);
                $plan['duplicates'][] = ['name' => $renamedFrom, 'rows' => [$items[rh_ssi_key($renamedFrom)]['lines'][0]['row'], $sheetRow], 'kind' => 'also in ' . $items[rh_ssi_key($renamedFrom)]['dept'] . ': the ' . $dept . ' one is kept as its own item "' . $name . '"'];
            }
            $balance = rh_ssi_num($r['balance'] ?? '');
            $max = rh_ssi_num($r['max_level'] ?? '');
            $price = rh_ssi_num($r['unit_price'] ?? '');
            $expiry = trim((string)($r['expiry'] ?? ''));
            $batch = trim((string)($r['batch'] ?? ''));
            $line = [
                'row' => $sheetRow, 'section' => trim((string)($r['section'] ?? '')), 'unit' => trim((string)($r['unit'] ?? '')),
                'balance' => $balance, 'max' => $max, 'price' => $price, 'expiry' => $expiry, 'batch' => $batch,
                'L' => $purchaseQty[$sheetRow] ?? null,
            ];
            if (!isset($items[$key])) {
                $items[$key] = ['key' => $key, 'name' => $name, 'dept' => $dept, 'section' => $line['section'], 'lines' => [$line]];
            } else {
                $items[$key]['lines'][] = $line;
            }
        }
        foreach ($items as $it) {
            if (count($it['lines']) > 1) {
                $plan['duplicates'][] = ['name' => $it['name'], 'rows' => array_column($it['lines'], 'row'), 'kind' => 'same department: one item, ' . count($it['lines']) . ' batches'];
            }
        }

        // ── 2. Existing ingredients ─────────────────────────────────────────────────────
        $existing = [];
        $hasPack = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_ingredients' AND COLUMN_NAME = 'pack_size'")->fetchColumn() > 0;
        $packCols = $hasPack ? 'pack_name, pack_size,' : 'NULL AS pack_name, NULL AS pack_size,';
        foreach ($pdo->query("SELECT id, name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point, notes, {$packCols} is_archived,
                                     (SELECT COUNT(*) FROM stock_recipe_ingredients x WHERE x.ingredient_id = i.id) AS recipes
                              FROM stock_ingredients i")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $existing[rh_ssi_key((string)$e['name'])] = $e;
        }

        // Ingredients already counted by an earlier run of this import (so a re-run does not count them twice).
        $covered = [];
        try {
            $q = $pdo->query("SELECT DISTINCT l.ingredient_id FROM stock_count_lines l INNER JOIN stock_counts c ON c.id = l.count_id
                              WHERE c.notes LIKE 'Stock sheet import%' AND c.status IN ('draft','submitted','approved')");
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $covered[(int)$id] = true;
            }
        } catch (Throwable $e) { /* counts tables not there: nothing covered */ }
        $plan['covered_ids'] = array_keys($covered);

        // ── 3. Work out each item ───────────────────────────────────────────────────────
        $packResolved = [];   // price => [pack sizes] for the "same price as" hint
        foreach ($items as $key => $it) {
            $dept = $it['dept'];
            $isBottleDept = in_array($dept, ['Bar', '77 Mug'], true);
            $flags = []; $maxNote = ''; $crateNote = ''; $bottleNote = ''; $inferNote = '';
            $lines = $it['lines'];
            $sections = array_values(array_unique(array_filter(array_column($lines, 'section'))));
            $section = $sections[0] ?? '';
            $unitRaw = '';
            foreach ($lines as $l) { if ($l['unit'] !== '') { $unitRaw = $l['unit']; break; } }
            $unit = rh_ssi_norm_unit($unitRaw);
            $unitAssumed = false;
            if ($isBottleDept) {
                $unit = 'bottle';
            } elseif ($unit === '') {
                $unit = rh_ssi_choose_unit($it['name']);
                $unitAssumed = true;
            }

            // Balance: single total per item.
            $balance = 0.0;
            $blank = false; $neg = false;
            foreach ($lines as $l) {
                if ($l['balance'] === null) { $blank = true; continue; }
                if ($l['balance'] < 0) { $neg = true; continue; }
                $balance += $l['balance'];
            }
            if ($blank && count($lines) === 1) { $flags[] = 'balance is blank on the sheet - counted as 0'; }
            if ($neg) { $flags[] = 'negative balance on the sheet - counted as 0'; }
            $balance = round($balance, 6);

            $maxes = array_filter(array_column($lines, 'max'), static fn($v) => $v !== null);
            $max = $maxes ? max($maxes) : 0.0;
            if (count(array_unique(array_map('strval', $maxes))) > 1) { $flags[] = 'max level differs between its rows - the highest is used'; }
            if ($maxes && $max < 0) { $flags[] = 'negative max level - par left at 0'; $max = 0.0; }
            $maxLooksWrong = ($max > 0 && $max < 3 && $balance > $max * 5 && $balance > 10) || ($max > 0 && $balance > $max * 3 && $balance > 20);
            if ($maxLooksWrong) {
                $maxNote = 'Max level on the sheet looks wrong (max ' . rtrim(rtrim(number_format($max, 2), '0'), '.') . ', ' . rtrim(rtrim(number_format($balance, 2), '0'), '.') . ' on hand) - please check.';
                $flags[] = 'max level ' . rtrim(rtrim(number_format($max, 2), '0'), '.') . ' but ' . rtrim(rtrim(number_format($balance, 2), '0'), '.') . ' on hand - check the max level';
            }

            // Price: qty-weighted across rows that have one.
            $prices = array_values(array_filter(array_column($lines, 'price'), static fn($v) => $v !== null));
            $price = null;
            if ($prices) {
                $price = $prices[0];
                if (max($prices) - min($prices) > 0.005 * max($prices)) { $flags[] = 'its rows carry different prices - the first (' . number_format($prices[0], 2) . ') is used'; }
            }
            $priceBad = false;
            foreach ($lines as $l) { if ($l['price'] !== null && $l['price'] < 0) { $priceBad = true; } }
            if ($priceBad) { $flags[] = 'negative price on the sheet - ignored'; $price = null; }
            if ($price !== null && $price <= 0) { $price = null; }

            // Pack (Bar only) and cost per stock unit.
            $pack = null; $packStatus = 'n/a'; $packFits = []; $packReason = ''; $halfFit = []; $perBottle = false;
            $ov = $overrides[$key] ?? null;
            if ($dept === 'Bar') {
                if ($ov && !empty($ov['pack_size'])) {
                    $pack = (int)$ov['pack_size']; $packStatus = 'owner';
                } else {
                    $Ls = []; $anyFormula = false;
                    foreach ($lines as $l) {
                        if ($l['L'] === null || $l['L']['qty'] === null) { continue; }
                        if ($l['L']['formula']) { $anyFormula = true; continue; }
                        $Ls[] = $l['L']['qty'];
                    }
                    $inf = rh_ssi_infer_pack($balance, $max, $Ls ? max($Ls) : null, !$Ls && $anyFormula);
                    $pack = $inf['pack']; $packFits = $inf['fits']; $packReason = $inf['reason'];
                    $packStatus = $pack ? 'inferred' : ($packFits ? 'ambiguous' : 'none');
                    // The sheet's own purchase quantity is a plain bottle count (MAX(0, max - balance)) and its total is that count x the
                    // price, so the price on that row is per BOTTLE (spirits, wines): no crate involved.
                    $perBottle = !$pack && !$Ls && $anyFormula;
                    if (!$pack && $Ls && max($Ls) > 0) { $halfFit = rh_ssi_half_crate_fits($balance, $max, max($Ls)); }
                }
                foreach ($lines as $l) {
                    if ($l['L'] !== null && $l['L']['name'] !== '' && rh_ssi_key($l['L']['name']) !== rh_ssi_key($it['name'])) {
                        $plan['qty_name_mismatch'][] = ['row' => $l['row'], 'csv' => $it['name'], 'workbook' => $l['L']['name']];
                    }
                }
            }
            $cost = null; $costStatus = 'ok';
            if ($ov && isset($ov['cost']) && $ov['cost'] > 0) {
                $cost = round((float)$ov['cost'], 4);
            } elseif ($price === null) {
                $costStatus = 'no_price';
            } elseif ($dept === 'Bar') {
                $crateText = 'Sheet price MWK ' . number_format($price, 0) . ' looks like a crate/case price; crate size unknown - set the pack size and cost.';
                if ($pack) {
                    $cost = round($price / $pack, 4);
                    $packResolved[(string)$price][$pack] = true;
                    if ($packStatus === 'inferred') { $inferNote = 'Crate of ' . $pack . ' worked out from the sheet\'s purchase quantity (price MWK ' . number_format($price, 0) . ' a crate) - please check.'; }
                } elseif ($perBottle && rh_ssi_spirit_section($section) && $price <= 150000) {
                    // Spirits / wines: a plausible per-bottle price is kept.
                    $cost = round($price, 4);
                    $costStatus = 'per_bottle';
                    $bottleNote = 'Cost taken as per bottle (sheet price MWK ' . number_format($price, 0) . ') - please check.';
                } else {
                    // Beers, ciders, soft drinks, or a price too big for one bottle: priced like a crate, no cost until the crate size is set.
                    $costStatus = 'pack_unknown';
                    $crateNote = $crateText;
                }
            } else {
                $cost = round($price, 4);
            }

            // A price of 500 or more per ml / per gram is a bottle or pack price typed against a tiny unit: left blank, not valued.
            if ($dept !== 'Bar' && $cost !== null && in_array($unit, ['ml', 'g'], true) && $cost >= 500) {
                $crateNote = 'Sheet price MWK ' . number_format($price, 0) . ' is probably for a whole bottle/pack, but the unit is ' . $unit . ' - set the unit and the cost.';
                $cost = null;
                $costStatus = 'unit_odd';
            }

            // Batches with an expiry (quantity = that row's balance).
            $batches = [];
            foreach ($lines as $l) {
                $q = ($l['balance'] !== null && $l['balance'] > 0) ? $l['balance'] : 0.0;
                $okDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $l['expiry']) && checkdate((int)substr($l['expiry'], 5, 2), (int)substr($l['expiry'], 8, 2), (int)substr($l['expiry'], 0, 4)) && substr($l['expiry'], 0, 4) >= '2000';
                if ($l['expiry'] !== '' && !$okDate) { $flags[] = 'expiry "' . $l['expiry'] . '" (row ' . $l['row'] . ') is not a valid date - ignored'; }
                if ($q > 0 && ($okDate || $l['batch'] !== '')) {
                    $batches[] = ['label' => mb_substr($l['batch'], 0, 50), 'qty' => $q, 'expiry' => $okDate ? $l['expiry'] : null, 'row' => $l['row'], 'past' => $okDate && $l['expiry'] < $today];
                }
            }

            $par = $max;
            $reorder = rh_ssi_reorder_point($max, $unit);
            $e = $existing[$key] ?? null;
            $action = $e ? 'update' : 'create';
            $changes = [];
            if ($e) {
                if ((int)$e['is_archived'] === 1) {
                    $action = 'skip_archived';
                    $flags[] = 'matches an ARCHIVED ingredient - left alone (restore or rename it, then re-run)';
                } else {
                    $compatible = $unitAssumed ? true : rh_ssi_units_compatible($unitRaw !== '' ? $unitRaw : $unit, (string)$e['unit']);
                    if ($isBottleDept) { $compatible = rh_ssi_units_compatible('bottle', (string)$e['unit']); }
                    if (!$compatible) {
                        $action = 'skip_conflict';
                        $flags[] = 'unit differs from the existing ingredient (sheet "' . ($unitRaw !== '' ? $unitRaw : $unit) . '" vs "' . $e['unit'] . '") - left alone, decide which unit is right';
                    } elseif ($unitAssumed && $cost !== null && (float)$e['cost_per_unit'] > 0 && ($cost / (float)$e['cost_per_unit'] > 3 || $cost / (float)$e['cost_per_unit'] < 1 / 3)) {
                        $action = 'skip_conflict';
                        $flags[] = 'sheet price ' . number_format($cost, 2) . ' is very different from the existing cost ' . number_format((float)$e['cost_per_unit'], 2) . ' and the sheet has no unit - left alone, check the unit';
                    } elseif ($cost !== null && (float)$e['cost_per_unit'] > 0 && (int)$e['recipes'] > 0 && ($cost / (float)$e['cost_per_unit'] >= 2 || $cost / (float)$e['cost_per_unit'] <= 0.5)) {
                        $action = 'skip_conflict';
                        $flags[] = 'cost would move from ' . number_format((float)$e['cost_per_unit'], 2) . ' to ' . number_format($cost, 2) . ' and ' . $e['recipes'] . ' recipe(s) use it - nothing changed, check the unit is the same';
                    } else {
                        $unit = (string)$e['unit'];   // keep the unit the ingredient (and its recipes) already use
                    }
                }
            }
            if ($action === 'update') {
                $reorder = rh_ssi_reorder_point($max, $unit);
                if ($e['category'] !== $dept) { $changes['category'] = [$e['category'], $dept]; }
                if ($cost !== null && abs((float)$e['cost_per_unit'] - $cost) > 0.00005) {
                    $changes['cost'] = [(float)$e['cost_per_unit'], $cost];
                }
                if (abs((float)$e['par_level'] - $par) > 0.00005) { $changes['par'] = [(float)$e['par_level'], $par]; }
                if (abs((float)$e['reorder_point'] - $reorder) > 0.00005) { $changes['reorder'] = [(float)$e['reorder_point'], $reorder]; }
                if ($pack && ((float)($e['pack_size'] ?? 0) !== (float)$pack)) { $changes['pack'] = [$e['pack_size'] ? (rtrim(rtrim((string)$e['pack_size'], '0'), '.') . ' ' . ($e['pack_name'] ?: 'pack')) : 'none', $pack . ' crate']; }
            }
            // Every item we are not sure about says why, in its notes (plain words, the owner's wording).
            $noteSet = [];
            if (in_array($action, ['skip_conflict', 'skip_archived'], true)) {
                $why = $flags ? preg_replace('/ - .*$/', '', (string)end($flags)) : 'it does not match what is already here';
                $noteSet[] = 'Sheet clash: sheet has unit "' . ($unitRaw !== '' ? $unitRaw : $unit) . '"' . ($unitAssumed ? ' (chosen, none given)' : '') . ', price MWK ' . ($price !== null ? number_format($price, 0) : 'none') . ', balance ' . rtrim(rtrim(number_format($balance, 3), '0'), '.') . ', max ' . rtrim(rtrim(number_format($max, 3), '0'), '.') . ' - not applied (' . $why . '). Please check.';
            } else {
                if ($cost === null && $costStatus === 'no_price' && (!$e || (float)$e['cost_per_unit'] <= 0)) { $noteSet[] = 'Price not on the July 2026 sheet - add the cost.'; }
                if ($unitAssumed) { $noteSet[] = 'Unit chosen at import - please check.'; }
                if ($maxNote !== '') { $noteSet[] = $maxNote; }
                foreach ([$crateNote, $bottleNote, $inferNote] as $nn) { if ($nn !== '') { $noteSet[] = $nn; } }
                foreach ($flags as $f) {
                    if (!preg_match('/^(max level \d|unit differs|sheet price|matches an ARCHIVED|cost would move)/', $f)) { $noteSet[] = 'Sheet check: ' . $f; }
                }
            }
            $notes = rh_ssi_merge_notes($e['notes'] ?? '', $section, $noteSet);
            if ($action === 'update' && $notes !== trim((string)($e['notes'] ?? ''))) { $changes['notes'] = [(string)($e['notes'] ?? ''), $notes]; }
            $notesChanged = $e ? ($notes !== trim((string)($e['notes'] ?? ''))) : true;

            // Does this item get a line in the opening count?
            $countable = in_array($action, ['create', 'update'], true) && ($balance <= 0.00005 || ($cost !== null && $cost > 0));
            $alreadyCounted = $e && isset($covered[(int)$e['id']]);
            $item = [
                'key' => $key, 'name' => $it['name'], 'dept' => $dept, 'section' => $section, 'unit' => $unit,
                'rows' => array_column($lines, 'row'), 'balance' => $balance, 'max' => $max, 'price' => $price, 'cost' => $cost,
                'half_fit' => $halfFit, 'cost_status' => $costStatus, 'pack' => $pack, 'pack_status' => $packStatus, 'pack_fits' => $packFits, 'pack_reason' => $packReason,
                'batches' => $batches, 'flags' => $flags, 'existing' => $e, 'action' => $action, 'par' => $par, 'reorder' => $reorder,
                'notes' => $notes, 'changes' => $changes, 'in_count' => $countable && !$alreadyCounted,
                'already_counted' => $alreadyCounted, 'unit_assumed' => $unitAssumed, 'note_set' => $noteSet, 'notes_changed' => $notesChanged,
                'value' => ($countable && $cost !== null) ? round($balance * $cost, 2) : 0.0,
            ];
            $plan['items'][$key] = $item;
        }
        // Hint for unresolved bar items: the crate size other items at the same price turned out to have.
        // Hints for unresolved bar items (NEVER applied - the owner confirms them): the crate size other items at the
        // same price turned out to have, and the crate size that explains a half-crate purchase quantity.
        foreach ($plan['items'] as $k => $item) {
            if ($item['dept'] !== 'Bar' || $item['pack'] || $item['price'] === null) {
                continue;
            }
            $bySibling = isset($packResolved[(string)$item['price']]) ? array_keys($packResolved[(string)$item['price']]) : [];
            $byHalf = $item['half_fit'] ?? [];
            $cand = null; $why = [];
            if (count($byHalf) === 1) { $cand = $byHalf[0]; $why[] = 'bought in half crates, which only a crate of ' . $byHalf[0] . ' explains'; }
            if (count($bySibling) === 1) {
                if ($cand !== null && $cand !== $bySibling[0]) { $cand = null; $why = ['half-crate and same-price hints disagree']; }
                else { $cand = $bySibling[0]; $why[] = 'other items at the same price (' . number_format($item['price'], 0) . ') are crates of ' . $bySibling[0]; }
            } elseif (count($bySibling) > 1 && $cand === null) {
                $why[] = 'other items at this price use different crate sizes (' . implode('/', $bySibling) . ')';
            }
            $plan['items'][$k]['pack_hint'] = ['p' => $cand, 'why' => implode('; ', $why)];
        }

        // ── 4. Existing ingredients that are not on the sheet ───────────────────────────
        foreach ($existing as $key => $e) {
            if (!isset($plan['items'][$key])) {
                $plan['existing_not_in_sheet'][] = $e;
            }
        }

        // ── 5. Budget lines (fixed amounts, warn) ───────────────────────────────────────
        $plan['budget_lines'] = [];
        foreach ($plan['budgets'] as $cat => $amt) {
            if ($amt > 0) {
                $plan['budget_lines'][] = ['category' => $cat, 'mode' => 'fixed', 'amount' => $amt, 'control' => 'warn'];
            }
        }

        // ── 6. Did an earlier run already make the opening count? ───────────────────────
        try {
            $q = $pdo->query("SELECT id, reference, status FROM stock_counts WHERE notes LIKE 'Stock sheet import%' AND status IN ('draft','submitted','approved') ORDER BY id");
            $plan['existing_count'] = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $plan['existing_count'] = [];
        }
        return $plan;
    }
}

if (!function_exists('rh_ssi_suggestions_csv')) {
    /** Overrides file for the owner to check and pass back with --overrides: flagged bar items with a suggested crate size. */
    function rh_ssi_suggestions_csv(array $plan): string
    {
        $fh = fopen('php://memory', 'w+');
        fputcsv($fh, ['name', 'pack_size', 'cost_per_unit', 'sheet_price', 'on_hand', 'hint']);
        foreach ($plan['items'] as $i) {
            if ($i['dept'] === 'Bar' && !$i['pack'] && $i['price'] !== null && in_array($i['action'], ['create', 'update'], true)) {
                fputcsv($fh, [$i['name'], $i['pack_hint']['p'] ?? '', '', $i['price'], $i['balance'], $i['pack_hint']['why'] ?? ($i['pack_reason'] ?? '')]);
            }
        }
        rewind($fh);
        return (string)stream_get_contents($fh);
    }
}

if (!function_exists('rh_ssi_apply')) {
    /**
     * Write the plan. The caller owns the transaction (begin before, commit after); run rh_ssi_ensure_table()
     * and make sure stock_counts.is_opening exists BEFORE it (DDL).
     *
     * @return array{created:int,updated:int,count_id:?int,count_ref:?string,count_lines:int,batches:int,budget_changed:int,budget_error:string}
     */
    function rh_ssi_apply(PDO $pdo, array $plan, int $userId): array
    {
        $res = ['created' => 0, 'updated' => 0, 'count_id' => null, 'count_ref' => null, 'count_lines' => 0, 'batches' => 0, 'budget_changed' => 0, 'budget_error' => ''];

        $ins = $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, min_quantity, cost_per_unit, yield_percent, notes, reorder_point, par_level, pack_name, pack_size)
                              VALUES (?, ?, ?, 0, 0, ?, 100, ?, ?, ?, ?, ?)");
        $upd = $pdo->prepare("UPDATE stock_ingredients SET category = ?, cost_per_unit = ?, notes = ?, reorder_point = ?, par_level = ?, pack_name = ?, pack_size = ?, updated_at = NOW() WHERE id = ?");
        foreach ($plan['items'] as $k => &$it) {
            if ($it['action'] === 'create') {
                $dup = $pdo->prepare("SELECT id FROM stock_ingredients WHERE LOWER(TRIM(name)) = ? LIMIT 1");
                $dup->execute([$it['key']]);
                if ($id = (int)$dup->fetchColumn()) {   // appeared since the plan was made: treat as existing, change nothing
                    $it['id'] = $id; $it['system_qty'] = null;
                    continue;
                }
                $ins->execute([
                    mb_substr($it['name'], 0, 255), $it['dept'], $it['unit'], $it['cost'] ?? 0, $it['notes'] !== '' ? $it['notes'] : null,
                    $it['reorder'], $it['par'], $it['pack'] ? 'crate' : null, $it['pack'] ?: null,
                ]);
                $it['id'] = (int)$pdo->lastInsertId();
                $it['system_qty'] = 0.0;
                $res['created']++;
            } elseif ($it['action'] === 'update') {
                $e = $it['existing'];
                $it['id'] = (int)$e['id'];
                $it['system_qty'] = (float)$e['current_quantity'];
                if ($it['changes']) {
                    $upd->execute([
                        $it['dept'], $it['cost'] ?? (float)$e['cost_per_unit'], $it['notes'] !== '' ? $it['notes'] : null,
                        $it['reorder'], $it['par'],
                        $it['pack'] ? 'crate' : ($e['pack_name'] ?: null), $it['pack'] ?: ($e['pack_size'] ?: null), $it['id'],
                    ]);
                    $res['updated']++;
                }
            } elseif (in_array($it['action'], ['skip_conflict', 'skip_archived'], true) && !empty($it['existing'])) {
                // Left alone apart from a note saying what the sheet had and why nothing was applied.
                $e = $it['existing'];
                if (!empty($it['notes_changed'])) {
                    $pdo->prepare("UPDATE stock_ingredients SET notes = ? WHERE id = ?")->execute([$it['notes'] !== '' ? $it['notes'] : null, (int)$e['id']]);
                    $res['notes_only'] = ($res['notes_only'] ?? 0) + 1;
                }
            }
        }
        unset($it);

        // ── One opening count, left SUBMITTED ────────────────────────────────────────
        $lines = array_filter($plan['items'], static fn($i) => !empty($i['in_count']) && !empty($i['id']));
        if ($lines) {
            $ref = date('Ymd') . '-' . substr(strtoupper(bin2hex(random_bytes(2))), 0, 4);
            $hasSnap = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_counts' AND COLUMN_NAME = 'snapshot_at'")->fetchColumn() > 0;
            $hasCountedAt = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_count_lines' AND COLUMN_NAME = 'counted_at'")->fetchColumn() > 0;
            $hasCountedBy = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_count_lines' AND COLUMN_NAME = 'counted_by'")->fetchColumn() > 0;
            $note = 'Stock sheet import ' . date('Y-m-d') . ': opening balances (bar and kitchen plus stores) from the October 2026 stock sheet. Counted by the import - a second manager must approve it. Ingredients not on the sheet are not in this count.';
            $pdo->prepare("INSERT INTO stock_counts (reference, count_date, shift, scope, scope_value, status, counted_by, notes" . ($hasSnap ? ', snapshot_at' : '') . ", is_opening)
                           VALUES (?, CURDATE(), NULL, 'full', NULL, 'draft', ?, ?" . ($hasSnap ? ', NOW()' : '') . ", 1)")
                ->execute([$ref, $userId, $note]);
            $countId = (int)$pdo->lastInsertId();
            $lineIns = $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost, reason_code" . ($hasCountedAt ? ', counted_at' : '') . ($hasCountedBy ? ', counted_by' : '') . ")
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?" . ($hasCountedAt ? ', NOW()' : '') . ($hasCountedBy ? ', ?' : '') . ")");
            $batchIns = $pdo->prepare("INSERT INTO stock_opening_batches (count_id, ingredient_id, batch_label, quantity, expiry_date) VALUES (?, ?, ?, ?, ?)");
            $short = 0.0; $surplus = 0.0;
            foreach ($lines as $it) {
                $sys = (float)($it['system_qty'] ?? 0);
                $actual = (float)$it['balance'];
                $variance = round($actual - $sys, 6);
                $cost = (float)($it['cost'] ?? ($it['existing']['cost_per_unit'] ?? 0));
                $vc = round($variance * $cost, 4);
                if ($vc < 0) { $short += $vc; } else { $surplus += $vc; }
                $args = [$countId, $it['id'], $sys, $actual, $variance, $cost, $vc, $variance < -0.0001 ? 'correction' : ''];
                if ($hasCountedBy) { $args[] = $userId; }
                $lineIns->execute($args);
                $res['count_lines']++;
                foreach ($it['batches'] as $b) {
                    $batchIns->execute([$countId, $it['id'], $b['label'] !== '' ? $b['label'] : null, $b['qty'], $b['expiry']]);
                    $res['batches']++;
                }
            }
            $pdo->prepare("UPDATE stock_counts SET status = 'submitted', total_variance_cost = ?, shortage_cost = ?, surplus_cost = ?, updated_at = NOW() WHERE id = ?")
                ->execute([round($short + $surplus, 2), round($short, 2), round($surplus, 2), $countId]);
            $res['count_id'] = $countId;
            $res['count_ref'] = $ref;
        }

        // ── Stock budget lines for the month (existing lines stay) ───────────────────────
        if ($plan['budget_lines'] && function_exists('rh_sbud_save')) {
            $ym = $plan['budget_month'];
            $merged = [];
            foreach (rh_sbud_lines($pdo, $ym) as $o) {
                $merged[rh_sbud_cat_key($o['category'])] = ['category' => $o['category'], 'mode' => $o['mode'], 'amount' => $o['amount'], 'target_pct' => $o['target_pct'], 'control' => $o['control']];
            }
            foreach ($plan['budget_lines'] as $b) {
                $merged[rh_sbud_cat_key($b['category'])] = $b;
            }
            $r = rh_sbud_save($pdo, $ym, array_values($merged), $userId, 'manual');
            if (!$r['ok']) {
                throw new RuntimeException('Stock budget not saved: ' . $r['error']);
            }
            $res['budget_changed'] = (int)$r['changed'];
        }
        return $res;
    }
}

if (!function_exists('rh_ssi_report')) {
    function rh_ssi_report(array $plan, string $mode = 'DRY RUN (nothing written)'): string
    {
        $m = static fn($n, $dp = 2) => number_format((float)$n, $dp);
        $q = static fn($n) => rtrim(rtrim(number_format((float)$n, 3, '.', ','), '0'), '.');
        $items = $plan['items'];
        $by = static function (callable $f) use ($items) { return array_values(array_filter($items, $f)); };
        $create = $by(fn($i) => $i['action'] === 'create');
        $update = $by(fn($i) => $i['action'] === 'update');
        $conflict = $by(fn($i) => $i['action'] === 'skip_conflict');
        $archived = $by(fn($i) => $i['action'] === 'skip_archived');
        $inCount = $by(fn($i) => !empty($i['in_count']));
        $already = $by(fn($i) => !empty($i['already_counted']));
        $bar = $by(fn($i) => $i['dept'] === 'Bar');
        $resolved = array_values(array_filter($bar, fn($i) => $i['pack']));
        $perBottle = array_values(array_filter($bar, fn($i) => $i['cost_status'] === 'per_bottle'));
        $flaggedBar = array_values(array_filter($bar, fn($i) => !$i['pack'] && $i['price'] !== null && $i['cost_status'] !== 'per_bottle'));
        $barNoPrice = array_values(array_filter($bar, fn($i) => !$i['pack'] && $i['price'] === null));
        $changed = array_values(array_filter($update, fn($i) => $i['changes']));

        $o = [];
        $o[] = "ROSALYN'S STOCK SHEET IMPORT - " . $mode;
        $o[] = 'Sheet rows read: ' . $plan['row_count'] . '   Distinct items: ' . count($items) . '   Rows skipped: ' . count($plan['skipped']);
        $o[] = str_repeat('=', 78);
        $o[] = '';
        $o[] = 'HEADLINE';
        $o[] = '  Items to create:                 ' . count($create);
        $o[] = '  Items to update (matched):       ' . count($update) . '  (' . count($changed) . ' with a change, ' . (count($update) - count($changed)) . ' already up to date)';
        $o[] = '  Matched but left alone (unit/price clash): ' . count($conflict) . '   archived matches left alone: ' . count($archived);
        $loadable = $by(fn($i) => in_array($i['action'], ['create', 'update'], true));
        $withCost = $by(fn($i) => in_array($i['action'], ['create', 'update'], true) && (($i['cost'] ?? 0) > 0 || (!empty($i['existing']) && (float)$i['existing']['cost_per_unit'] > 0)));
        $withNotes = $by(fn($i) => !empty($i['note_set']));
        $o[] = '  Items with a cost / with a BLANK cost: ' . count($withCost) . ' / ' . (count($loadable) - count($withCost)) . '  (blank = the sheet had no price, or a crate-type price whose crate size is unknown)';
        $o[] = '  Items given a "please check" note: ' . count($withNotes) . '  (' . count($by(fn($i) => in_array($i['action'], ['skip_conflict', 'skip_archived'], true) && !empty($i['notes_changed']))) . ' of them are existing items left otherwise unchanged)';
        $o[] = '  Bar crate sizes worked out:      ' . count($resolved) . ' of ' . count($bar) . ' bar items';
        $o[] = '  Bar priced per BOTTLE and kept (spirits/wines up to MWK 150,000): ' . count($perBottle);
        $o[] = '  Bar items with a crate-type price, crate size unknown: ' . count($flaggedBar) . '  (cost left blank, note added)';
        $o[] = '  Opening count lines:             ' . count($inCount) . ($already ? '  (' . count($already) . ' already counted by an earlier run, not repeated)' : '');
        $leftOut = $by(fn($i) => in_array($i['action'], ['create', 'update'], true) && $i['balance'] > 0 && !$i['in_count'] && !$i['already_counted']);
        $o[] = '  Left OUT of the count for a missing cost: ' . count($leftOut) . ' items with stock on hand (' . $q(array_sum(array_column($leftOut, 'balance'))) . ' units): ' . count(array_filter($leftOut, fn($i) => $i['cost_status'] === 'no_price')) . ' have no price, ' . count(array_filter($leftOut, fn($i) => $i['cost_status'] === 'pack_unknown')) . ' crate-priced with the crate size unknown, ' . count(array_filter($leftOut, fn($i) => $i['cost_status'] === 'unit_odd')) . ' priced per bottle against an ml/g unit';
        $o[] = '  Expiry batches to attach:        ' . array_sum(array_map(fn($i) => !empty($i['in_count']) ? count($i['batches']) : 0, $items));
        $valueByDept = [];
        foreach ($inCount as $i) { $valueByDept[$i['dept']] = ($valueByDept[$i['dept']] ?? 0) + $i['value']; }
        $o[] = '  Stock value at cost (counted items): MWK ' . $m(array_sum($valueByDept)) . '   (of which bar items priced per bottle by assumption: MWK ' . $m(array_sum(array_map(fn($i) => !empty($i['in_count']) && $i['cost_status'] === 'per_bottle' ? $i['value'] : 0, $items))) . ')';
        $o[] = '';

        $o[] = str_repeat('-', 78);
        $o[] = 'STOCK VALUE AT COST, by category (only items that get a count line)';
        foreach (['Bar', 'Kitchen', 'Chemical', '77 Mug', 'Stationery'] as $d) {
            $n = count(array_filter($inCount, fn($i) => $i['dept'] === $d));
            $withBal = count(array_filter($inCount, fn($i) => $i['dept'] === $d && $i['balance'] > 0));
            $o[] = sprintf('  %-12s %4d lines (%d with stock)   MWK %s', $d, $n, $withBal, $m($valueByDept[$d] ?? 0));
        }
        $noCostStock = $by(fn($i) => in_array($i['action'], ['create', 'update'], true) && $i['balance'] > 0 && !$i['in_count'] && !$i['already_counted']);
        $o[] = '  Not valued - stock on hand but no usable cost: ' . count($noCostStock) . ' items (' . $q(array_sum(array_column($noCostStock, 'balance'))) . ' units in total)';
        $o[] = '';

        $o[] = str_repeat('-', 78);
        $o[] = 'ITEMS TO CREATE (' . count($create) . ')';
        foreach (['Bar', 'Kitchen', 'Chemical', '77 Mug', 'Stationery'] as $d) {
            $n = count(array_filter($create, fn($i) => $i['dept'] === $d));
            $o[] = '  ' . $d . ': ' . $n;
        }
        foreach ($create as $i) {
            $o[] = sprintf('    %-34s %-10s %-9s bal %-8s par %-7s cost %s%s', mb_substr($i['name'], 0, 34), $i['dept'], $i['unit'], $q($i['balance']), $q($i['par']), $i['cost'] !== null ? $m($i['cost'], 2) : '-', $i['pack'] ? '  [crate of ' . $i['pack'] . ']' : '');
        }
        $o[] = '';

        $o[] = str_repeat('-', 78);
        $o[] = 'ITEMS TO UPDATE - MATCHED BY NAME (' . count($update) . ')  old -> new';
        foreach ($update as $i) {
            $e = $i['existing'];
            $parts = [];
            foreach ($i['changes'] as $field => $pair) {
                if ($field === 'notes') { $parts[] = 'notes updated'; continue; }
                if (in_array($field, ['cost', 'par', 'reorder'], true)) { $parts[] = $field . ' ' . $m($pair[0], 2) . ' -> ' . $m($pair[1], 2); }
                else { $parts[] = $field . ' ' . $pair[0] . ' -> ' . $pair[1]; }
            }
            $o[] = sprintf('    #%d %-30s (%s, %s, %d recipe(s)): %s', $e['id'], mb_substr($i['name'], 0, 30), $e['category'], $e['unit'], $e['recipes'], $parts ? implode('; ', $parts) : 'nothing to change');
        }
        if ($conflict || $archived) {
            $o[] = '  Matched but LEFT ALONE:';
            foreach (array_merge($conflict, $archived) as $i) {
                $o[] = '    #' . $i['existing']['id'] . ' ' . $i['name'] . ' - ' . implode('; ', $i['flags']);
            }
        }
        $o[] = '';

        $o[] = str_repeat('-', 78);
        $o[] = 'BAR CRATE SIZES';
        $o[] = '  Rule: crate size p in {6, 12, 24} where ceil((max - balance) / p) equals the sheet\'s purchase quantity; exactly one fit is used.';
        $o[] = '  Worked out (' . count($resolved) . '): cost per bottle = sheet price / crate size';
        foreach ($resolved as $i) {
            $o[] = sprintf('    %-34s crate of %-3d price %s -> %s a bottle%s', mb_substr($i['name'], 0, 34), $i['pack'], $m($i['price'], 0), $m($i['cost'], 2), $i['pack_status'] === 'owner' ? '  (owner answer)' : '');
        }
        $o[] = 'KEPT AS PER BOTTLE (' . count($perBottle) . '): spirits, liqueurs and wines whose sheet purchase quantity is its own bottle count and whose price is plausible for one bottle (up to MWK 150,000). Cost per bottle = sheet price, pack left blank, note added. Beers, ciders and soft drinks priced like crates are NOT here: they get a blank cost.';
        foreach ($perBottle as $i) {
            $o[] = sprintf('    %-34s price %s a bottle, bal %s', mb_substr($i['name'], 0, 34), $m($i['price'], 0), $q($i['balance']));
        }
        $o[] = '  FLAGGED for the owner - crate size not worked out (' . count($flaggedBar) . '): pack and cost left BLANK (note: sheet price looks like a crate price), stock not counted until answered';
        foreach ($flaggedBar as $i) {
            $hint = (!empty($i['pack_hint']) && $i['pack_hint']['why'] !== '') ? ' [hint' . ($i['pack_hint']['p'] ? ': crate of ' . $i['pack_hint']['p'] : '') . ' - ' . $i['pack_hint']['why'] . ']' : '';
            $o[] = sprintf('    %-34s price %s bal %s max %s - %s%s', mb_substr($i['name'], 0, 34), $m($i['price'], 0), $q($i['balance']), $q($i['max']), $i['pack_reason'], $hint);
        }
        $o[] = '  Bar items with no price on the sheet (' . count($barNoPrice) . '): ' . implode(', ', array_map(fn($i) => $i['name'], $barNoPrice));
        $o[] = '';

        $o[] = str_repeat('-', 78);
        $o[] = 'ROWS SKIPPED (' . count($plan['skipped']) . ')';
        foreach ($plan['skipped'] as $s) {
            $o[] = sprintf('    row %-4s %-10s "%s" - %s', $s['row'], $s['dept'], $s['name'], $s['why']);
        }
        $o[] = '';

        $o[] = str_repeat('-', 78);
        $noUnit = $by(fn($i) => $i['unit_assumed'] ?? false);
        $o[] = 'NO UNIT ON THE SHEET - "each" used (' . count($noUnit) . '); check ones that are really packs, trays, kg, litres:';
        $o[] = '    ' . implode(', ', array_map(fn($i) => $i['name'], $noUnit));
        $o[] = '';
        $noPrice = $by(fn($i) => $i['cost_status'] === 'no_price' && in_array($i['action'], ['create', 'update'], true));
        $o[] = 'NO PRICE ON THE SHEET (' . count($noPrice) . '): created with a BLANK cost and the note "Price not on the July 2026 sheet - add the cost."; items with stock on hand are NOT in the opening count until a cost is given';
        foreach (['Bar', 'Kitchen', 'Chemical', '77 Mug', 'Stationery'] as $d) {
            $names = array_map(fn($i) => $i['name'] . ($i['balance'] > 0 ? ' (' . $q($i['balance']) . ')' : ''), array_filter($noPrice, fn($i) => $i['dept'] === $d));
            if ($names) { $o[] = '    ' . $d . ' (' . count($names) . '): ' . implode(', ', $names); }
        }
        $o[] = '';

        $o[] = str_repeat('-', 78);
        $o[] = 'ODD DATA / FLAGS PER ITEM';
        $n = 0;
        foreach ($items as $i) {
            if (!$i['flags']) { continue; }
            $n++;
            $o[] = '    ' . $i['dept'] . ' / ' . $i['name'] . ' (row ' . implode(',', $i['rows']) . '): ' . implode('; ', $i['flags']);
        }
        if ($n === 0) { $o[] = '    none'; }
        $o[] = '';

        $o[] = str_repeat('-', 78);
        $o[] = 'DUPLICATES IN THE SHEET (' . count($plan['duplicates']) . ')';
        foreach ($plan['duplicates'] as $d) {
            $o[] = '    "' . $d['name'] . '" rows ' . implode(', ', $d['rows']) . ' - ' . $d['kind'];
        }
        $o[] = '';

        $o[] = str_repeat('-', 78);
        $o[] = 'EXISTING INGREDIENTS NOT ON THE SHEET - left untouched (' . count($plan['existing_not_in_sheet']) . ')';
        foreach ($plan['existing_not_in_sheet'] as $e) {
            $o[] = sprintf('    #%-4d %-34s %-10s %-6s qty %s%s%s', $e['id'], mb_substr($e['name'], 0, 34), $e['category'], $e['unit'], $q($e['current_quantity']), (int)$e['is_archived'] ? '  [archived]' : '', (int)$e['recipes'] ? '  [' . $e['recipes'] . ' recipe(s)]' : '');
        }
        $o[] = '';

        $o[] = str_repeat('-', 78);
        $o[] = 'OPENING COUNT';
        if (!empty($plan['existing_count'])) {
            $o[] = '  A stock sheet count already exists: ' . implode(', ', array_map(fn($c) => $c['reference'] . ' (' . $c['status'] . ')', $plan['existing_count'])) . ' - only items it does not already hold would be added to a new one.';
        }
        $surplusQty = array_sum(array_map(fn($i) => $i['balance'] - (float)($i['existing']['current_quantity'] ?? 0), $inCount));
        $o[] = '  ONE opening count, status SUBMITTED (never approved by the import): ' . count($inCount) . ' lines, unit costs filled, counted_by = the user running --apply.';
        $o[] = '  Approving it (a different manager) turns each line\'s balance into stock; ' . array_sum(array_map(fn($i) => count($i['batches']), $inCount)) . ' expiry batches are split out at that moment.';
        $o[] = '  Net quantity it adds: ' . $q($surplusQty) . ' units (existing ingredients are counted against their current, mostly zero or negative, quantity).';
        $o[] = '';
        $o[] = 'STOCK BUDGET - ' . date('F Y', strtotime($plan['budget_month'] . '-01')) . ' (fixed, control "warn")';
        foreach ($plan['budgets'] as $c => $a) {
            $o[] = sprintf('    %-12s %s%s', $c, $m($a), $a > 0 ? '' : '  (skipped - zero)');
        }
        if ($plan['qty_name_mismatch']) {
            $o[] = '';
            $o[] = 'NAME MISMATCH between CSV and workbook purchase-qty column (check): ' . count($plan['qty_name_mismatch']);
            foreach ($plan['qty_name_mismatch'] as $x) { $o[] = '    row ' . $x['row'] . ': CSV "' . $x['csv'] . '" vs workbook "' . $x['workbook'] . '"'; }
        }
        $o[] = '';
        return implode("\n", $o);
    }
}
