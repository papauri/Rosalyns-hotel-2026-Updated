<?php
/**
 * Import engine - SEED vocabulary (generic spreadsheet words). Phase 1b stores/edit this in the
 * `import_aliases` table; every pass takes the alias set as a PARAMETER, so no logic here is
 * hotel-specific. Hotel-specific learned corrections are layered on top by merging into this array
 * with import_merge_aliases().
 *
 * Shape:
 *   fields        field => [header phrases]            (compared after import_norm_text())
 *   derived       fields whose columns are shown greyed and NEVER imported
 *   units         words that are units of measure       (used to read a unit out of a Remarks cell)
 *   section_words words typical of category headings    (supporting signal only)
 *   total_words   a row whose label STARTS with one of these is a total, never an item
 *   summary_phrases a row whose label CONTAINS one of these is a summary line, never an item
 *   skip_sheet_words sheet names containing these are dashboards/summaries (skipped, listed)
 *   noise_tokens  tokens dropped from headers before matching (currency etc.)
 */

if (!function_exists('import_default_aliases')) {

    function import_default_aliases(): array
    {
        return [
            // Bump when phrases are added: import_seed_aliases() then tops every existing hotel up ONCE (INSERT IGNORE on the
            // alias hash, so nothing is ever doubled). v2 (2026-11-16): our own Stock > Ingredients CSV export headings and
            // renamed copies of it ("Current Quantity level", "Minimum Quantity level", "Lead Time (days)" ...). v3 (2026-11-16): unit spelling "trays" -> "tray".
            'version' => 3,
            'fields' => [
                'item_name' => [
                    'item name', 'item', 'items', 'item description', 'description', 'product', 'product name',
                    'name', 'article', 'stock item', 'particulars', 'ingredient', 'material', 'goods', 'details',
                    'ingredient name', 'stock name', 'stock description', 'product description', 'commodity',
                ],
                'batch' => ['batch', 'batch no', 'batch number', 'batch ref', 'lot', 'lot no', 'lot number'],
                'qty_primary' => [
                    'bar balance', 'kitchen balance', 'bar stock', 'kitchen stock', 'bar qty', 'kitchen qty',
                    'bar quantity', 'kitchen quantity', 'bar', 'kitchen', 'counter balance', 'shop balance',
                ],
                'qty_secondary' => [
                    'stores balance', 'store balance', 'stores', 'store', 'stores qty', 'store qty',
                    'stores quantity', 'store quantity', 'stores stock', 'store stock', 'main store', 'main store balance',
                ],
                'qty_single' => [
                    'balance', 'qty', 'quantity', 'stock', 'stock balance', 'on hand', 'qty on hand',
                    'quantity on hand', 'closing balance', 'closing stock', 'current stock', 'count',
                    'physical count', 'opening balance', 'opening stock', 'stock on hand', 'available',
                    // v2. "Current Qty", "Closing Qty", "Qty Available" ... already read as Balance through "qty"; adding them
                    // word for word would only turn headings like "Main Store Current Qty" into questions, so they are left out.
                    'current quantity level', 'current level', 'current stock level', 'in stock', 'on hand qty',
                ],
                'unit' => [
                    'unit', 'units', 'uom', 'unit of measure', 'unit of measurement', 'measure', 'measurement',
                    'unit type', 'units of measure',
                ],
                'expiry' => [
                    'expiry', 'expiry date', 'expiration', 'expiration date', 'expire date', 'expires', 'exp',
                    'exp date', 'best before', 'best before date', 'use by', 'use by date', 'next expiry date',
                ],
                'max_level' => [
                    'max level', 'maximum level', 'max', 'maximum', 'max stock', 'maximum stock', 'par', 'par level',
                    'par stock', 'order up to',
                    'maximum quantity', 'maximum quantity level', 'max qty', 'max quantity', 'order up to level', 'par qty',
                ],
                'min_level' => [
                    'min level', 'minimum level', 'min', 'minimum', 'min stock', 'minimum stock', 'reorder level',
                    're order level', 'reorder point', 're order point', 'safety stock',
                    'minimum stock level', 'min stock level', 'low stock level', 'alert level',
                ],
                // A minimum QUANTITY column ("Min Qty" in our own export): stock keeps one level the import can set (the reorder
                // point = min_level). Listed under 'derived' (greyed, never imported) and promoted to min_level only when the sheet
                // has no reorder/min-level column of its own: see 'field_fallbacks' and import_map_apply_fallbacks().
                'min_qty' => [
                    'min qty', 'min quantity', 'minimum qty', 'minimum quantity', 'minimum quantity level', 'min quantity level',
                    'min qty level',
                ],
                'unit_price' => [
                    'unit price', 'price', 'cost', 'unit cost', 'cost price', 'buying price', 'purchase price',
                    'rate', 'price per unit', 'cost per unit', 'unit rate',
                    'cost unit', 'unit cost price', 'avg cost', 'average cost', 'weighted average cost', 'buying cost',
                ],
                'remarks' => ['remarks', 'remark', 'comments', 'comment', 'notes', 'note', 'observations'],
                'category' => ['category', 'categories', 'department', 'dept', 'group', 'type', 'section', 'class'],
                // derived / bookkeeping columns: recognised so they can be shown greyed and ignored
                'qty_total' => ['total balance', 'total qty', 'total quantity', 'total stock', 'total', 'grand total'],
                'days_to_expiry' => ['days to expiry', 'days to expire', 'days left', 'days remaining', 'expiry days', 'days until expiry'],
                'status' => ['status', 'stock status', 'state', 'condition'],
                'action' => ['action required', 'action', 'action needed', 'actions', 'next step'],
                'purchase_qty' => [
                    'purchase qty', 'purchase quantity', 'order qty', 'order quantity', 'qty to order',
                    'quantity to order', 'to order', 'reorder qty', 'reorder quantity', 're order qty', 'qty to buy',
                    'qty needed', 'shortfall', 'quantity in reorder',
                ],
                'total_cost' => [
                    'total cost', 'total value', 'total price', 'line total', 'line cost', 'amount', 'stock value',
                    'value', 'total amount', 'total estimated cost', 'extended cost', 'inventory value', 'closing value', 'valuation',
                    'stock valuation', 'inventory valuation',
                ],
                'lead_time' => ['lead time', 'lead time days', 'lead days', 'supplier lead time', 'reorder time', 'reorder time in days'],
                'row_no' => [
                    'no', 'sn', 's n', 'sr', 'sr no', 'serial', 'serial no', 'serial number', 'number', 'ref', 'ref no', 'id', 'code', 'sku',
                    'commodity code',
                ],
            ],
            'derived' => ['qty_total', 'days_to_expiry', 'status', 'action', 'purchase_qty', 'total_cost', 'row_no', 'lead_time', 'min_qty'],
            // derived field => the field it stands in for when the sheet has NO column of that field (stored as kind field_fallback)
            'field_fallbacks' => ['min_qty' => 'min_level'],
            'units' => [
                'piece', 'pieces', 'pc', 'pcs', 'each', 'ea', 'bottle', 'bottles', 'btl', 'btls', 'can', 'cans', 'kg', 'kgs',
                'kilo', 'kilos', 'kilogram', 'kilograms', 'g', 'gram', 'grams', 'litre', 'litres', 'liter', 'liters',
                'l', 'ltr', 'ltrs', 'ml', 'packet', 'packets', 'pkt', 'pkts', 'pack', 'packs', 'box', 'boxes',
                'carton', 'cartons', 'case', 'cases', 'crate', 'crates', 'dozen', 'roll', 'rolls', 'sachet',
                'sachets', 'bag', 'bags', 'tin', 'tins', 'jar', 'jars', 'tray', 'trays', 'set', 'sets', 'pair',
                'pairs', 'bundle', 'bundles', 'gallon', 'gallons', 'drum', 'drums', 'sack', 'sacks', 'tub',
                'tubs', 'unit', 'units', 'bunch', 'bunches', 'head', 'heads', 'loaf', 'loaves', 'ream', 'reams',
                'keg', 'kegs', 'sheet', 'sheets', 'tube', 'tubes',
            ],
            // different spellings of the SAME unit -> the spelling stock uses (format only, never a conversion: kg stays kg, a dozen is not 12).
            // A hotel can add rows (import_aliases kind unit_synonym, term = the spelling, maps_to = the unit).
            'unit_synonyms' => [
                'btl' => 'bottle', 'btls' => 'bottle', 'bottles' => 'bottle', 'bottle' => 'bottle',
                'pc' => 'pcs', 'pcs' => 'pcs', 'piece' => 'pcs', 'pieces' => 'pcs',
                'ltr' => 'L', 'ltrs' => 'L', 'lt' => 'L', 'litre' => 'L', 'litres' => 'L', 'liter' => 'L', 'liters' => 'L', 'l' => 'L',
                'kgs' => 'kg', 'kilo' => 'kg', 'kilos' => 'kg', 'kilogram' => 'kg', 'kilograms' => 'kg', 'kg' => 'kg',
                'gm' => 'g', 'gms' => 'g', 'gram' => 'g', 'grams' => 'g', 'mls' => 'ml',
                'pkt' => 'packet', 'pkts' => 'packet', 'packet' => 'packet', 'packets' => 'packet',
                'ctn' => 'carton', 'ctns' => 'carton', 'cartons' => 'carton',
                'doz' => 'dozen', 'dz' => 'dozen', 'cans' => 'can', 'boxes' => 'box', 'bx' => 'box', 'sachets' => 'sachet',
                'trays' => 'tray', 'tray' => 'tray',
            ],
            'section_words' => [
                'beers', 'beer', 'spirits', 'wines', 'wine', 'soft drinks', 'juices', 'liqueurs', 'protein',
                'vegetables', 'vegitables', 'fruits', 'fruit', 'dairy', 'dry goods', 'condiments', 'spices',
                'cleaning', 'chemicals', 'stationery', 'stationary', 'beverages', 'meat', 'fish', 'poultry',
                'bakery', 'frozen', 'canned', 'toiletries', 'linen', 'cordials', 'mixers', 'water',
            ],
            'total_words' => [
                'total', 'totals', 'grand total', 'sub total', 'subtotal', 'sum', 'overall', 'overall total',
                'carried forward', 'brought forward', 'c f', 'b f',
            ],
            'summary_phrases' => [
                'items below', 'out of', 're order items', 'reorder items', 'estimated purchase', 'total items',
            ],
            'skip_sheet_words' => [
                'dashboard', 'navigator', 'summary', 'overview', 'index', 'contents', 'cover', 'readme', 'read me',
                'instructions', 'chart', 'charts', 'pivot', 'lookup', 'legend', 'about',
            ],
            'noise_tokens' => ['mwk', 'mk', 'kwacha', 'usd', 'zar', 'kes', 'tzs', 'gbp', 'eur', 'currency'],
            // a heading with one of these words ("Total Buying Cost", "Value in Stock", "Days in Stock") is a worked-out figure: it is
            // never read as an imported field from PART of its words (only a word-for-word heading match is applied; else a question)
            'guard_words' => ['total', 'totals', 'subtotal', 'value', 'values', 'valuation', 'amount', 'amounts', 'days'],
        ];
    }

    /**
     * Tab icons for the stock workbook export: a plain WORD in a category name -> one emoji in front of the tab name. Generic
     * stock words only; a hotel can add or change rows in import_aliases (kind 'tab_icon', term = word, maps_to = emoji) and
     * those win. A category with none of these words simply gets no icon (nothing is invented).
     */
    function import_default_tab_icons(): array
    {
        return [
            'bar' => "\u{1F37A}", 'beer' => "\u{1F37A}", 'beers' => "\u{1F37A}", 'beverage' => "\u{1F37A}", 'beverages' => "\u{1F37A}",
            'drinks' => "\u{1F37A}", 'wine' => "\u{1F377}", 'wines' => "\u{1F377}", 'spirits' => "\u{1F943}",
            'kitchen' => "\u{1F373}", 'food' => "\u{1F373}", 'dry' => "\u{1F373}",
            'chemical' => "\u{1F9F4}", 'chemicals' => "\u{1F9F4}", 'cleaning' => "\u{1F9F4}", 'housekeeping' => "\u{1F9F9}",
            'mug' => "\u{2615}", 'coffee' => "\u{2615}", 'tea' => "\u{2615}",
            'stationery' => "\u{1F4CE}", 'stationary' => "\u{1F4CE}", 'office' => "\u{1F4CE}",
            'laundry' => "\u{1F9FA}", 'linen' => "\u{1F6CF}", 'maintenance' => "\u{1F527}", 'spa' => "\u{1F9D6}", 'gym' => "\u{1F3CB}",
            'retail' => "\u{1F6D2}", 'shop' => "\u{1F6D2}",
        ];
    }

    /**
     * Layer hotel-specific aliases ("training") over the seed.
     * $extra = ['fields'=>['item_name'=>['stock item x']], 'units'=>[...], ...] — lists are appended.
     */
    function import_merge_aliases(array $base, array $extra): array
    {
        foreach ($extra as $key => $val) {
            if ($key === 'fields' && is_array($val)) {
                foreach ($val as $field => $phrases) {
                    $base['fields'][$field] = array_values(array_unique(array_merge($base['fields'][$field] ?? [], (array)$phrases)));
                }
            } elseif ($key === 'field_fallbacks' && is_array($val)) {
                $base[$key] = array_merge((array)($base[$key] ?? []), $val);   // keyed (derived field => field): keys kept
            } elseif (is_array($val) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = array_values(array_unique(array_merge($base[$key], $val)));
            } else {
                $base[$key] = $val;
            }
        }
        return $base;
    }
}
