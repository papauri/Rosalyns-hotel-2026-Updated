<?php
/**
 * Import - export the CURRENT stock as a tabbed workbook (the same look as the hotel's own tracker workbook).
 *
 *   import_export_stock_tabs($pdo)                       the tabs that can be exported: [['key','label','items'], ...]
 *   import_export_stock_workbook($pdo, $opts)             builds the .xlsx; ['ok','stream','size','filename','summary'] or ['ok'=>false,'error']
 *
 * Every number and word in the workbook comes from the live database (stock_ingredients, stock_batches) or the site settings
 * (hotel name, currency). Nothing hotel-specific is written in this file. VALUES ONLY: no formulas are written, so nothing can
 * disagree with the stored figures after the file is opened.
 *
 * TABS (ASSUMPTION A39): stock has no department field; stock_ingredients.category is the grouping every stock screen uses, so ONE TAB
 * PER CATEGORY (alphabetical), each headed by a merged heading row carrying the full category name. Archived items are left out (as on
 * every stock screen). A DASHBOARD tab comes first and a PURCHASE SUMMARY tab last.
 *
 * ROWS: one row per item; an item with stock in several FIFO batches gets one row per batch ONLY when the batches add up to its
 * balance (otherwise one row with the item's balance and its earliest expiry, so nothing is invented or hidden).
 *
 * DEFINITIONS (identical to the stock screens; file:line in IMPORT_PLAN.md A40-A45):
 *   Balance        stock_ingredients.current_quantity (config/database.php rh_stock_alert_summary, rh_stock_valuation)
 *   Max Level      stock_ingredients.par_level (raw; blank when 0)
 *   Unit Price     stock_ingredients.cost_per_unit, the stored decimal(12,4) text as it is (blank when 0 = "no cost")
 *   Purchase Qty   max(Max Level - Balance, 0), blank when no Max Level            (the tracker's "Max - Balance")
 *   Total Cost     round(Purchase Qty x Unit Price, 2)                              (admin/stock-reorder.php:82 rounds a line the same way)
 *   Days / Status  DATEDIFF(expiry, today) and the Batch Tracker tiers (admin/stock-batches.php:193): <0 EXPIRED, 0-7 AT RISK, 8-30 CHECK SOON, >30 ACTIVE
 *   Out / Low      rh_stock_alert_summary() rules: out = history and qty <= 0.0001; low = qty > 0.0001 and qty <= (reorder_point, else min_quantity)
 *   Stock value    sum(max(qty, 0) x cost) = rh_stock_valuation()
 */

require_once __DIR__ . '/export-xlsx.php';
require_once __DIR__ . '/default-aliases.php';

if (!function_exists('import_export_stock_tabs')) {

    function import_export_tab_label(string $category): string
    {
        $c = trim($category);
        return $c === '' ? 'Uncategorised' : $c;
    }

    /** Tabs that exist in the live stock (non-archived items), alphabetical. */
    function import_export_stock_tabs(PDO $pdo): array
    {
        $out = [];
        $rows = $pdo->query("SELECT TRIM(COALESCE(category, '')) AS cat, COUNT(*) AS n FROM stock_ingredients WHERE is_archived = 0 GROUP BY cat ORDER BY cat")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out[] = ['key' => (string)$r['cat'], 'label' => import_export_tab_label((string)$r['cat']), 'items' => (int)$r['n']];
        }
        return $out;
    }

    /** word -> emoji: the seeded generic words, then this hotel's own import_aliases rows (kind 'tab_icon') on top. */
    function import_export_tab_icons(PDO $pdo): array
    {
        $icons = import_default_tab_icons();
        try {
            foreach ($pdo->query("SELECT term, maps_to FROM import_aliases WHERE kind = 'tab_icon' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $icons[mb_strtolower(trim((string)$r['term']))] = (string)$r['maps_to'];
            }
        } catch (Throwable $e) {
            // the Import tables are optional here: the seeded words are enough
        }
        return $icons;
    }

    function import_export_icon_for(string $category, array $icons): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($category), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $w) {
            if (isset($icons[$w]) && $icons[$w] !== '') {
                return $icons[$w];
            }
        }
        return '';
    }

    /** A file name safe for Content-Disposition: letters, digits, space, dash, underscore, dot. */
    function import_export_filename(string $hotel, string $date): string
    {
        $h = preg_replace('/[^\p{L}\p{N} _.\-]+/u', '', $hotel) ?? '';
        $h = trim(preg_replace('/\s+/u', ' ', $h) ?? '');
        $h = mb_substr($h, 0, 60);
        return 'Stock ' . ($h !== '' ? $h . ' ' : '') . $date . '.xlsx';
    }

    /** Download headers for a built workbook (no caching; the file name has an ASCII fallback plus the UTF-8 form). */
    function import_export_headers(string $filename, int $size): array
    {
        $ascii = preg_replace('/[^A-Za-z0-9 _.\-]+/', '', $filename) ?? '';
        $ascii = trim($ascii) !== '' ? trim($ascii) : 'stock.xlsx';
        return [
            'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Content-Length: ' . $size,
            'X-Content-Type-Options: nosniff',
            'Cache-Control: no-store, no-cache, must-revalidate, private',
            'Pragma: no-cache',
            'Expires: 0',
        ];
    }

    /** Send a workbook built by import_export_stock_workbook() and stop. The stream is copied in chunks, never read whole. */
    function import_export_send(array $built): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        foreach (import_export_headers((string)$built['filename'], (int)$built['size']) as $h) {
            header($h);
        }
        $s = $built['stream'];
        rewind($s);
        while (!feof($s)) {
            $chunk = fread($s, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            flush();
        }
        fclose($s);
        exit;
    }

    /** Tier of a batch from its days to expiry (the Batch Tracker's tiers, stock-batches.php:193). */
    function import_export_expiry_status(?int $days): string
    {
        if ($days === null) {
            return '';
        }
        if ($days < 0) {
            return 'EXPIRED';
        }
        if ($days <= 7) {
            return 'AT RISK';
        }
        if ($days <= 30) {
            return 'CHECK SOON';
        }
        return 'ACTIVE';
    }

    /**
     * Build the workbook.
     * $opts: tabs (array of tab keys; empty = every tab), hotel, currency, today (YYYY-MM-DD, tests), generated (string shown in the subtitle).
     */
    function import_export_stock_workbook(PDO $pdo, array $opts = []): array
    {
        $fail = function (string $m): array {
            return ['ok' => false, 'error' => $m];
        };
        $hotel = trim((string)($opts['hotel'] ?? ''));
        $hotelShow = $hotel !== '' ? $hotel : 'Hotel';
        $currency = trim((string)($opts['currency'] ?? ''));
        try {
            $today = (string)($opts['today'] ?? $pdo->query('SELECT CURDATE()')->fetchColumn());
            $tabsAll = import_export_stock_tabs($pdo);
        } catch (Throwable $e) {
            return $fail('The stock list could not be read.');
        }
        $want = [];
        foreach ((array)($opts['tabs'] ?? []) as $k) {
            $want[mb_strtolower(trim((string)$k))] = true;
        }
        $tabs = [];
        foreach ($tabsAll as $t) {
            if (!$want || isset($want[mb_strtolower($t['key'])])) {
                $tabs[] = $t;
            }
        }
        if (!$tabs) {
            return $fail($tabsAll ? 'None of the chosen tabs has any stock.' : 'There is no stock to export yet.');
        }

        $histSql = rh_stock_history_sql($pdo, 'i');
        $thrSql = rh_stock_threshold_sql('i');
        $hasBatches = rh_stock_table_present($pdo, 'stock_batches');
        $icons = import_export_tab_icons($pdo);

        $W = new ImportXlsxWriter(['title' => 'Stock - ' . $hotelShow, 'creator' => $hotelShow]);

        // ---- the look: copied from the hotel's own tracker workbook (fills, fonts, widths, number formats) ----------------------------------
        $NAVY = 'FF1F3864';
        $BLUE = 'FF2E5FA3';
        $GOLD = 'FFC9A227';
        $LINE = 'FF999999';
        $tabColors = ['FFC00000', 'FF0E6655', 'FF4A235A', 'FF784212', 'FF375623', 'FF1F3864'];
        $sectionFills = ['FF154360', 'FF6C3483', 'FF922B21', 'FF0E6655', 'FF784212', 'FF4D5656'];
        $banner = $W->style(['font' => ['b' => true, 'sz' => 15, 'color' => 'FFFFFFFF'], 'fill' => $NAVY, 'h' => 'center', 'v' => 'center']);
        $subtitle = $W->style(['font' => ['i' => true, 'sz' => 9, 'color' => 'FFFFFFFF'], 'fill' => $BLUE, 'h' => 'center', 'v' => 'center']);
        $head = $W->style(['font' => ['b' => true, 'sz' => 10, 'color' => 'FFFFFFFF'], 'fill' => $NAVY, 'h' => 'center', 'v' => 'center', 'wrap' => true]);
        $headGold = $W->style(['font' => ['b' => true, 'sz' => 10, 'color' => $NAVY], 'fill' => $GOLD, 'h' => 'center', 'v' => 'center', 'wrap' => true]);
        $note = $W->style(['font' => ['i' => true, 'sz' => 9, 'color' => 'FF666666'], 'h' => 'left', 'v' => 'center', 'wrap' => true]);
        $totalLabel = $W->style(['font' => ['b' => true, 'sz' => 11, 'color' => 'FFFFFFFF'], 'fill' => $NAVY, 'h' => 'left', 'v' => 'center']);
        $totalValue = $W->style(['font' => ['b' => true, 'sz' => 11, 'color' => 'FFFFFFFF'], 'fill' => $NAVY, 'num' => '#,##0.00', 'h' => 'right', 'v' => 'center']);
        $totalInt = $W->style(["font" => ["b" => true, "sz" => 11, "color" => "FFFFFFFF"], "fill" => $NAVY, "num" => "#,##0", "h" => "right", "v" => "center"]);
        $statusLook = [
            'EXPIRED' => ['FFFFCCCC', 'FFC00000'], 'AT RISK' => ['FFF5CBA7', 'FF922B21'],
            'CHECK SOON' => ['FFFFF2CC', 'FFE65C00'], 'ACTIVE' => ['FFE2EFDA', 'FF375623'],
        ];
        $cache = [];
        // one style per (kind, band): band 0 = grey row, 1 = white row (the tracker alternates F2F2F2 / FFFFFF)
        $cell = function (string $kind, int $band, string $extra = '') use (&$cache, $W, $LINE, $NAVY, $statusLook) {
            $k = $kind . $band . $extra;
            if (isset($cache[$k])) {
                return $cache[$k];
            }
            $fill = $band === 0 ? 'FFF2F2F2' : 'FFFFFFFF';
            $font = ['sz' => 10];
            $s = ['border' => $LINE, 'fill' => $fill, 'v' => 'center'];
            switch ($kind) {
                case 'name':
                    $s['font'] = ['b' => true] + $font;
                    $s['h'] = 'left';
                    break;
                case 'text':
                    $s['font'] = $font;
                    $s['h'] = 'left';
                    break;
                case 'center':
                    $s['font'] = $font;
                    $s['h'] = 'center';
                    break;
                case 'date':
                    $s['font'] = $font;
                    $s['h'] = 'center';
                    $s['num'] = 'dd\-mmm\-yyyy';
                    break;
                case 'bal_out':
                    $s['font'] = ['b' => true, 'color' => 'FFC00000'] + $font;
                    $s['fill'] = 'FFFFCCCC';
                    $s['h'] = 'center';
                    break;
                case 'bal_low':
                    $s['font'] = ['b' => true, 'color' => 'FFE65C00'] + $font;
                    $s['fill'] = 'FFFFF2CC';
                    $s['h'] = 'center';
                    break;
                case 'status':
                    $look = $statusLook[$extra] ?? null;
                    $s['font'] = ['b' => $look !== null, 'color' => $look[1] ?? null] + $font;
                    $s['fill'] = $look[0] ?? $fill;
                    $s['h'] = 'center';
                    break;
                case 'max':
                    $s['font'] = ['b' => true, 'color' => $NAVY] + $font;
                    $s['fill'] = 'FFEBF5FB';
                    $s['h'] = 'center';
                    break;
                case 'purchase':
                    $s['font'] = ['b' => true, 'color' => 'FFE65C00'] + $font;
                    $s['fill'] = 'FFFFF2CC';
                    $s['h'] = 'center';
                    break;
                case 'price':
                    $s['font'] = ['color' => 'FF375623'] + $font;
                    $s['fill'] = 'FFE2EFDA';
                    $s['h'] = 'right';
                    $s['num'] = '#,##0.00##';
                    break;
                case 'cost':
                    $s['font'] = ['b' => true, 'color' => $NAVY] + $font;
                    $s['fill'] = 'FFE2EFDA';
                    $s['h'] = 'right';
                    $s['num'] = '#,##0.00';
                    break;
            }
            return $cache[$k] = $W->style($s);
        };

        // sheets in tab order: DASHBOARD, one per category, PURCHASE SUMMARY (rows are filled later, in any order)
        $dashSi = $W->addSheet("\u{1F4CA} DASHBOARD", ['tab' => 'FFC9A227', 'gridlines' => false, 'cols' => [26, 58, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14], 'landscape' => true, 'first' => true,
            'footer' => $hotelShow . ' - stock dashboard']);
        $tabSi = [];
        $usedLabels = [];
        foreach ($tabs as $i => $t) {
            $icon = import_export_icon_for($t['label'], $icons);
            $title = ($icon !== '' ? $icon . ' ' : '') . $t['label'];
            $si = $W->addSheet($title, ['tab' => $tabColors[$i % count($tabColors)], 'gridlines' => false, 'freeze' => [1, 3], 'landscape' => true, 'repeat_rows' => [3, 3],
                'cols' => [34, 14, 12, 10, 14, 13, 14, 26, 26, 12, 16, 14, 16], 'footer' => $hotelShow . ' - ' . $t['label']]);
            $tabSi[$i] = ['si' => $si, 'icon' => $icon];
        }
        $sumSi = $W->addSheet("\u{1F4B0} PURCHASE SUMMARY", ['tab' => 'FF375623', 'gridlines' => false, 'freeze' => [0, 3], 'landscape' => true,
            'cols' => [30, 34, 14, 26, 46], 'footer' => $hotelShow . ' - purchase summary']);

        $headers = ['Item Name', 'Batch', 'Balance', 'Unit', 'Expiry Date', 'Days to Expiry', 'Status', 'Action Required', 'Comments', 'Max Level',
            "Purchase Qty\n(Max - Balance)", 'Unit Price', 'Total Cost'];
        $subline = 'As at ' . $today . '   |   ' . ($currency !== '' ? 'Amounts in ' . $currency : 'Amounts in the hotel currency');
        $priceNote = 'Prices are shown exactly as stored in the system - VAT is not added or removed. Total Cost is Purchase Qty multiplied by Unit Price, rounded to 2 decimals.';
        $valuesNote = 'Values only: nothing in this sheet recalculates. Balance, Max Level and Unit Price are the stock list figures at the moment of the export.';

        $tot = ['items' => 0, 'expired' => 0, 'out' => 0, 'low' => 0, 'never' => 0, 'value' => 0.0, 'purchase' => 0.0, 'rows' => 0, 'lines_to_buy' => 0];
        $perTab = [];
        $batchSql = "SELECT b.ingredient_id, b.id, b.batch_number, b.quantity_remaining, b.expiry_date, DATEDIFF(b.expiry_date, ?) AS days
                     FROM stock_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id
                     WHERE i.is_archived = 0 AND TRIM(COALESCE(i.category, '')) = ? AND b.status = 'active' AND b.quantity_remaining > 0.0001
                     ORDER BY b.ingredient_id, (b.expiry_date IS NULL) ASC, b.expiry_date ASC, b.id ASC";
        $expStmt = $hasBatches ? $pdo->prepare("SELECT COUNT(*) FROM stock_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id
                     WHERE i.is_archived = 0 AND TRIM(COALESCE(i.category, '')) = ? AND b.status = 'active' AND b.quantity_remaining > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date < ?") : null;
        $batchStmt = $hasBatches ? $pdo->prepare($batchSql) : null;
        $itemStmt = $pdo->prepare("SELECT i.id, i.name, i.unit, i.current_quantity, i.par_level, i.cost_per_unit, i.notes,
                                          {$thrSql} AS thr, (CASE WHEN {$histSql} THEN 1 ELSE 0 END) AS has_hist
                                   FROM stock_ingredients i WHERE i.is_archived = 0 AND TRIM(COALESCE(i.category, '')) = ? ORDER BY i.name, i.id");

        foreach ($tabs as $ti => $t) {
            $si = $tabSi[$ti]['si'];
            $icon = $tabSi[$ti]['icon'];
            // expired batches: counted from the batches themselves (not from exported rows), the Stock Dashboard rule (admin/stock-dashboard.php:79), non-archived items
            $expN = 0;
            if ($expStmt) {
                $expStmt->execute([$t["key"], $today]);
                $expN = (int)$expStmt->fetchColumn();
            }
            $tot['expired'] += $expN;
            $perTab[$ti]['expired'] = $expN;
            $itemStmt->execute([$t['key']]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            $byItem = [];
            if ($batchStmt) {
                $batchStmt->execute([$today, $t['key']]);
                foreach ($batchStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
                    $byItem[(int)$b['ingredient_id']][] = $b;
                }
            }
            $W->row($si, 1, [1 => ['str' => trim($icon . ' ' . mb_strtoupper($t['label']) . ' - ' . mb_strtoupper($hotelShow)), 's' => $banner]] + array_fill_keys(range(2, 13), ['s' => $banner]), 39.75);
            $W->merge($si, 'A1:M1');
            $W->row($si, 2, [1 => ['str' => $subline . '   |   ' . count($items) . ' item' . (count($items) === 1 ? '' : 's'), 's' => $subtitle]] + array_fill_keys(range(2, 13), ['s' => $subtitle]), 18.0);
            $W->merge($si, 'A2:M2');
            $hc = [];
            foreach ($headers as $hi => $h) {
                $hc[$hi + 1] = ['str' => $h, 's' => $hi >= 9 ? $headGold : $head];
            }
            $W->row($si, 3, $hc, 38.25);

            $r = 4;
            if ($t['key'] !== '') {      // heading row with the full category name: the category the re-import reads back
                $sec = $W->style(['font' => ['b' => true, 'sz' => 10, 'color' => 'FFFFFFFF'], 'fill' => $sectionFills[$ti % count($sectionFills)], 'h' => 'left', 'v' => 'center']);
                $W->row($si, $r, [1 => ['str' => $t['label'], 's' => $sec]] + array_fill_keys(range(2, 13), ['s' => $sec]), 18.0);
                $W->merge($si, 'A' . $r . ':M' . $r);
                $r++;
            }
            $firstItemRow = $r;
            $tabCost = 0.0;
            $tabLines = 0;
            $tabNoPrice = 0;
            $band = 0;
            foreach ($items as $it) {
                $qty = (float)$it['current_quantity'];
                $qtyText = (string)$it['current_quantity'];
                $par = (float)$it['par_level'];
                $cost = (float)$it['cost_per_unit'];
                $thr = (float)$it['thr'];
                $hist = (int)$it['has_hist'] === 1;
                $state = 'ok';
                if ($qty <= 0.0001) {
                    $state = $hist ? ($qty < -0.0001 ? 'negative' : 'out') : 'never';
                    $hist ? $tot['out']++ : $tot['never']++;
                    if ($hist) {
                        $perTab[$ti]['out'] = ($perTab[$ti]['out'] ?? 0) + 1;
                    }
                } elseif ($thr > 0 && $qty <= $thr) {
                    $state = 'low';
                    $tot['low']++;
                    $perTab[$ti]['low'] = ($perTab[$ti]['low'] ?? 0) + 1;
                }
                if ($qty > 0) {
                    $tot['value'] += $qty * $cost;
                }
                $purchase = null;
                $lineCost = null;
                if ($par > 0) {
                    $purchase = max($par - $qty, 0.0);
                    $purchase = round($purchase, 6);
                    $lineCost = round($purchase * $cost, 2);
                    if ($purchase > 0) {
                        $tabLines++;
                        if ($cost <= 0) {
                            $tabNoPrice++;
                        }
                    }
                    $tabCost += $lineCost;
                }
                // rows: one per batch when the batches add up to the balance, else one row
                $batches = $byItem[(int)$it['id']] ?? [];
                $sum = 0.0;
                foreach ($batches as $b) {
                    $sum += (float)$b['quantity_remaining'];
                }
                $rows = [];
                if ($batches && abs($sum - $qty) <= 0.00005) {
                    $used = [];
                    foreach ($batches as $b) {
                        $label = trim((string)$b['batch_number']);
                        if ($label === '' || isset($used[mb_strtolower($label)])) {
                            $label = 'B' . str_pad((string)$b['id'], 6, '0', STR_PAD_LEFT);
                        }
                        $used[mb_strtolower($label)] = true;
                        $rows[] = ['label' => $label, 'qty' => (string)$b['quantity_remaining'], 'expiry' => $b['expiry_date'], 'days' => $b['expiry_date'] !== null ? (int)$b['days'] : null];
                    }
                } else {
                    $first = $batches ? $batches[0] : null;     // earliest expiry first (the FIFO order)
                    $rows[] = ['label' => '', 'qty' => $qtyText, 'expiry' => $first['expiry_date'] ?? null, 'days' => ($first && $first['expiry_date'] !== null) ? (int)$first['days'] : null];
                }
                foreach ($rows as $ri => $row) {
                    $status = import_export_expiry_status($row['days']);
                    if ($status === 'EXPIRED') {
                        $action = 'Dispose';
                    } elseif ($status === 'AT RISK') {
                        $action = 'Use first / reorder urgently';
                    } elseif ($status === 'CHECK SOON') {
                        $action = 'Monitor closely';
                    } elseif ($state === 'negative') {
                        $action = 'Negative stock - reconcile';
                    } elseif ($state === 'out') {
                        $action = 'Out of stock - reorder';
                    } elseif ($state === 'low') {
                        $action = 'Below reorder level';
                    } elseif ($state === 'never') {
                        $action = 'Opening stock not recorded';
                    } else {
                        $action = 'Normal';
                    }
                    $balKind = ($state === 'out' || $state === 'negative') ? 'bal_out' : ($state === 'low' ? 'bal_low' : 'center');
                    $serial = $row['expiry'] !== null ? ImportXlsxWriter::dateSerial((string)$row['expiry']) : null;
                    $c = [
                        1 => ['str' => (string)$it['name'], 's' => $cell('name', $band)],
                        2 => ['str' => $row['label'], 's' => $cell('center', $band)],
                        3 => ['num' => $row['qty'], 's' => $cell($balKind, $band)],
                        4 => ['str' => (string)$it['unit'], 's' => $cell('center', $band)],
                        5 => ['num' => $serial, 's' => $cell('date', $band)],
                        6 => ['num' => $row['days'], 's' => $cell('center', $band)],
                        7 => ['str' => $status !== '' ? $status : '-', 's' => $cell('status', $band, $status)],
                        8 => ['str' => $action, 's' => $cell('text', $band)],
                        9 => ['str' => $ri === 0 ? trim((string)$it['notes']) : '', 's' => $cell('text', $band)],
                        10 => ['num' => ($ri === 0 && $par > 0) ? (string)$it['par_level'] : null, 's' => $cell('max', $band)],
                        11 => ['num' => $ri === 0 ? $purchase : null, 's' => $cell('purchase', $band)],
                        12 => ['num' => $cost > 0 ? (string)$it['cost_per_unit'] : null, 's' => $cell('price', $band)],
                        13 => ['num' => $ri === 0 ? $lineCost : null, 's' => $cell('cost', $band)],
                    ];
                    $W->row($si, $r++, $c);
                    $tot['rows']++;
                }
                $band = 1 - $band;
                $tot['items']++;
            }
            $lastItemRow = $r - 1;
            if ($lastItemRow >= $firstItemRow) {
                $W->filter($si, 'A3:M' . $lastItemRow);
            }
            $r++;
            $W->row($si, $r, [1 => ['str' => '  TOTAL ESTIMATED PURCHASE COST', 's' => $totalLabel]] + array_fill_keys(range(2, 12), ['s' => $totalLabel]) + [13 => ['num' => round($tabCost, 2), 's' => $totalValue]], 21.75);
            $W->merge($si, 'A' . $r . ':L' . $r);
            $r += 2;
            $W->row($si, $r, [1 => ['str' => $priceNote, 's' => $note]], 26.0);
            $W->merge($si, 'A' . $r . ':M' . $r);
            $r++;
            $W->row($si, $r, [1 => ['str' => $valuesNote, 's' => $note]], 26.0);
            $W->merge($si, 'A' . $r . ':M' . $r);

            $perTab[$ti]['items'] = count($items);
            $perTab[$ti]['cost'] = round($tabCost, 2);
            $perTab[$ti]['lines'] = $tabLines;
            $perTab[$ti]['no_price'] = $tabNoPrice;
            $tot['purchase'] += round($tabCost, 2);
            $tot['lines_to_buy'] += $tabLines;
        }
        $tot['value'] = round($tot['value'], 2);
        $tot['purchase'] = round($tot['purchase'], 2);
        $tot['alerts'] = $tot['out'] + $tot['low'];

        // ---- DASHBOARD -----------------------------------------------------------------------------------------------------------------------
        $money = function (float $v): string {
            return number_format($v, 2, '.', ',');
        };
        $W->row($dashSi, 1, [1 => ['str' => mb_strtoupper($hotelShow) . ' - STOCK', 's' => $banner]] + array_fill_keys(range(2, 12), ['s' => $banner]), 39.75);
        $W->merge($dashSi, 'A1:L1');
        $W->row($dashSi, 2, [1 => ['str' => $subline . '   |   taken from the live stock list', 's' => $subtitle]] + array_fill_keys(range(2, 12), ['s' => $subtitle]), 18.0);
        $W->merge($dashSi, 'A2:L2');
        $cards = [
            ['ITEMS LISTED', $tot['items'], 'FF1F3864', 'FFEBF5FB', '#,##0'],
            ['EXPIRED BATCHES' . "\n" . '(STOCK STILL LEFT)', $tot['expired'], 'FFC00000', 'FFFFCCCC', '#,##0'],
            ['OUT OF STOCK', $tot['out'], 'FF0E6655', 'FFE8F8F5', '#,##0'],
            ['REORDER ALERTS' . "\n" . '(OUT + LOW)', $tot['alerts'], 'FF4A235A', 'FFF5EEF8', '#,##0'],
            ['STOCK VALUE' . "\n" . '(AT COST)', $tot['value'], 'FF375623', 'FFE2EFDA', '#,##0.00'],
            ['TOTAL PURCHASE' . "\n" . 'COST', $tot['purchase'], 'FF1F3864', 'FFFFF2CC', '#,##0.00'],
        ];
        $lab = $val = $labFill = $valFill = [];
        foreach ($cards as $ci => [$label, $value, $dark, $light, $fmt]) {
            $c1 = $ci * 2 + 1;
            $ls = $W->style(['font' => ['b' => true, 'sz' => 9, 'color' => 'FFFFFFFF'], 'fill' => $dark, 'h' => 'center', 'v' => 'center', 'wrap' => true]);
            $vs = $W->style(['font' => ['b' => true, 'sz' => 20, 'color' => $dark], 'fill' => $light, 'num' => $fmt, 'h' => 'center', 'v' => 'center']);
            $lab[$c1] = ['str' => $label, 's' => $ls];
            $lab[$c1 + 1] = ['s' => $ls];
            $labFill[$c1] = $labFill[$c1 + 1] = ['s' => $ls];
            $val[$c1] = ['num' => $value, 's' => $vs];
            $val[$c1 + 1] = ['s' => $vs];
            $valFill[$c1] = $valFill[$c1 + 1] = ['s' => $vs];
            $W->merge($dashSi, ImportXlsxWriter::colName($c1) . '4:' . ImportXlsxWriter::colName($c1 + 1) . '5');
            $W->merge($dashSi, ImportXlsxWriter::colName($c1) . '6:' . ImportXlsxWriter::colName($c1 + 1) . '8');
        }
        $W->row($dashSi, 4, $lab, 12.0);
        $W->row($dashSi, 5, $labFill, 12.0);
        $W->row($dashSi, 6, $val, 21.75);
        $W->row($dashSi, 7, $valFill, 21.75);
        $W->row($dashSi, 8, $valFill, 21.75);
        $navBanner = $W->style(['font' => ['b' => true, 'sz' => 10, 'color' => 'FFFFFFFF'], 'fill' => 'FF154360', 'h' => 'left', 'v' => 'center']);
        $W->row($dashSi, 10, [1 => ['str' => "  \u{1F4CB}  SHEET NAVIGATOR", 's' => $navBanner]] + array_fill_keys(range(2, 12), ['s' => $navBanner]), 18.0);
        $W->merge($dashSi, 'A10:L10');
        $W->row($dashSi, 11, [1 => ['str' => 'Sheet', 's' => $head], 2 => ['str' => 'Description', 's' => $head]], 21.75);
        $linkStyle = $W->style(['font' => ['b' => true, 'color' => 'FF0563C1'], 'border' => $LINE, 'fill' => 'FFFFFFFF', 'h' => 'left', 'v' => 'center']);
        $descStyle = $W->style(['font' => ['sz' => 10], 'border' => $LINE, 'fill' => 'FFFFFFFF', 'h' => 'left', 'v' => 'center']);
        $r = 12;
        foreach ($tabs as $ti => $t) {
            $title = $W->sheetTitle($tabSi[$ti]['si']);
            $p = $perTab[$ti];
            $desc = $p['items'] . ' item' . ($p['items'] === 1 ? '' : 's') . ' in this category; ' . (($p['out'] ?? 0) + ($p['low'] ?? 0)) . ' to reorder; purchase cost ' . $money((float)$p['cost']);
            $W->row($dashSi, $r, [1 => ['str' => $title, 's' => $linkStyle], 2 => ['str' => $desc, 's' => $descStyle]], 18.0);
            $W->link($dashSi, 'A' . $r, "'" . str_replace("'", "''", $title) . "'!A1", 'Go to ' . $title);
            $r++;
        }
        $sumTitle = $W->sheetTitle($sumSi);
        $W->row($dashSi, $r, [1 => ['str' => $sumTitle, 's' => $linkStyle], 2 => ['str' => 'Purchase cost for each sheet, with a grand total', 's' => $descStyle]], 18.0);
        $W->link($dashSi, 'A' . $r, "'" . str_replace("'", "''", $sumTitle) . "'!A1", 'Go to ' . $sumTitle);
        $r += 2;
        $W->row($dashSi, $r, [1 => ['str' => '  HOW THESE FIGURES ARE WORKED OUT', 's' => $navBanner]] + array_fill_keys(range(2, 12), ['s' => $navBanner]), 18.0);
        $W->merge($dashSi, 'A' . $r . ':L' . $r);
        $r++;
        $explain = [
            'Out of stock: the item has stock history and its balance is zero or less. Reorder alerts: out of stock plus items at or below their reorder point (the same rule the Stock Dashboard uses).',
            'Expired batches: batches that still hold stock and whose expiry date is before the date above. Days to Expiry is counted from that date.',
            'Status: EXPIRED = past its expiry date, AT RISK = 0 to 7 days left, CHECK SOON = 8 to 30 days left, ACTIVE = more than 30 days left (the same tiers as the Batch Tracker).',
            'Purchase Qty = Max Level minus Balance, never below zero, blank when no Max Level is set. Total Cost = Purchase Qty x Unit Price. The Reorder / Buying page also subtracts what is already on order and rounds up to whole packs, so its figures can differ.',
            'Stock value = balance x cost per unit, with negative balances counted as nothing (the same figure as the Stock Dashboard). Archived items are not listed.',
            $priceNote,
            $valuesNote,
        ];
        foreach ($explain as $line) {
            $W->row($dashSi, $r, [1 => ['str' => $line, 's' => $note]], 27.0);
            $W->merge($dashSi, 'A' . $r . ':L' . $r);
            $r++;
        }

        // ---- PURCHASE SUMMARY ----------------------------------------------------------------------------------------------------------------
        $W->row($sumSi, 1, [1 => ['str' => 'PURCHASE COST SUMMARY - ' . mb_strtoupper($hotelShow), 's' => $banner]] + array_fill_keys(range(2, 5), ['s' => $banner]), 39.75);
        $W->merge($sumSi, 'A1:E1');
        $W->row($sumSi, 2, [1 => ['str' => $subline, 's' => $subtitle]] + array_fill_keys(range(2, 5), ['s' => $subtitle]), 18.0);
        $W->merge($sumSi, 'A2:E2');
        $W->row($sumSi, 3, [1 => ['str' => 'Department', 's' => $head], 2 => ['str' => 'Sheet', 's' => $head], 3 => ['str' => 'Lines to buy', 's' => $head],
            4 => ['str' => "Estimated Purchase Cost" . ($currency !== '' ? "\n(" . $currency . ')' : ''), 's' => $head], 5 => ['str' => 'Notes', 's' => $head]], 27.75);
        $r = 4;
        foreach ($tabs as $ti => $t) {
            $band = $ti % 2;
            $p = $perTab[$ti];
            $title = $W->sheetTitle($tabSi[$ti]['si']);
            $notes = $p['no_price'] > 0 ? $p['no_price'] . ' line' . ($p['no_price'] === 1 ? '' : 's') . ' to buy ha' . ($p['no_price'] === 1 ? 's' : 've') . ' no unit price, so add' . ($p['no_price'] === 1 ? 's' : '') . ' nothing to this cost.' : '';
            $W->row($sumSi, $r, [
                1 => ['str' => $t['label'], 's' => $cell('name', $band)],
                2 => ['str' => $title, 's' => $cell('text', $band)],
                3 => ['num' => $p['lines'], 's' => $cell('center', $band)],
                4 => ['num' => $p['cost'], 's' => $cell('cost', $band)],
                5 => ['str' => $notes, 's' => $cell('text', $band)],
            ], 21.75);
            $W->link($sumSi, 'B' . $r, "'" . str_replace("'", "''", $title) . "'!A1", 'Go to ' . $title);
            $r++;
        }
        $r++;
        $W->row($sumSi, $r, [1 => ['str' => '  GRAND TOTAL PURCHASE COST', 's' => $totalLabel], 2 => ['s' => $totalLabel], 3 => ['num' => $tot['lines_to_buy'], 's' => $totalInt],
            4 => ['num' => $tot['purchase'], 's' => $totalValue]], 21.75);

        $out = $W->finish();
        if (empty($out['ok'])) {
            return $fail((string)($out['error'] ?? 'The workbook could not be built.'));
        }
        $out['filename'] = import_export_filename($hotel, $today);
        $out['summary'] = $tot + ['tabs' => count($tabs), 'sheets' => $W->sheetCount()];
        $out['tab_keys'] = array_column($tabs, 'key');
        return $out;
    }
}
