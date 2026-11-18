<?php

/**
 * Stock > Fix crate prices (owner 2026-11-17).
 *
 * An earlier import stored the price of a whole crate / case as the cost of ONE bottle or can. Staff type how many units are in
 * one crate (or fill the numbers from the owner's CSV), Preview shows old -> new cost, value and the batches affected, and
 * Confirm divides the cost (and the cost of each ticked batch still holding stock, and open order lines still at the crate price) in one transaction.
 * The logic, rules and the history ASSUMPTION are in admin/includes/stock-cost-fix-lib.php.
 * Permission: stock_management (the same as editing stock items); module: stock.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once 'includes/procurement-schema.php';
require_once __DIR__ . '/../includes/form-validation.php';
require_once __DIR__ . '/includes/stock-cost-fix-lib.php';
// Assistant stock name check (owner 2026-11-17): optional; everything below works the same without it (fail-closed, OFF by default).
if (is_file(__DIR__ . '/../includes/ai/ai-stock-kinds.php')) {
    require_once __DIR__ . '/../includes/ai/ai-stock-kinds.php';
}

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$error = '';
$current_page = basename($_SERVER['PHP_SELF']);
$currency_symbol = (string)getSetting('currency_symbol');

if (!ensureStockTablesExist()) {
    $error = 'Stock tables not yet created.';
} else {
    ensureProcurementSchema($pdo);
}

$scf_typed = [];          // id => ['n' => string, 'pack' => string]: what was typed, carried through every round trip
$scf_preview = null;      // rh_scf_preview() result
$scf_prefill = null;      // rh_scf_csv_prefill() result + file name
$scf_problems = [];       // refused items, named

$scf_items = [];
if (!$error) {
    try {
        $scf_items = rh_scf_grid_items($pdo);
    } catch (Throwable $e) {
        error_log('stock-cost-fix grid: ' . $e->getMessage());
        $error = 'The stock items could not be loaded. Please reload the page.';
    }
}
$scf_itemNames = [];
foreach ($scf_items as $it) {
    $scf_itemNames[(int)$it['id']] = (string)$it['name'];
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Please reload the page.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $withOld = $action === 'apply';
        [$lines, $lineErrors] = rh_scf_lines_from_json((string)($_POST[$withOld ? 'apply_json' : 'grid_json'] ?? ''), $withOld);
        if ($withOld && !$lineErrors) {
            // The ticked "Divide this batch" boxes (unticked ones are not posted).
            [$lines, $lineErrors] = rh_scf_attach_divide($lines, $_POST['divide'] ?? null);
        }
        foreach ($lines as $id => $l) {
            $scf_typed[$id] = ['n' => $l['n'], 'pack' => $l['pack'], 'src' => $l['src'] ?? 'typed'];
        }
        if ($lineErrors) {
            $scf_problems = $lineErrors;
        } elseif ($action === 'prefill') {
            $f = $_FILES['fill_file'] ?? null;
            if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $error = 'Choose the CSV file first, then press Fill from list.';
            } elseif ((int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                $error = 'The file could not be uploaded. Please try again.';
            } elseif ((int)$f['size'] > 2 * 1024 * 1024) {
                $error = 'The file is too large (2 MB at most).';
            } else {
                $data = (string)file_get_contents((string)$f['tmp_name']);
                $scf_prefill = rh_scf_csv_prefill($data, $scf_items);
                $scf_prefill['file'] = basename((string)($f['name'] ?? 'list.csv'));
                if (!$scf_prefill['ok']) {
                    $error = $scf_prefill['error'];
                    $scf_prefill = null;
                } else {
                    foreach ($scf_prefill['fill'] as $id => $n) {
                        $scf_typed[$id] = ['n' => (string)$n, 'pack' => $scf_typed[$id]['pack'] ?? '', 'src' => 'csv'];
                    }
                    // Assistant stock name check (when on): rows marked Y teach "price of one" (administrator / manager only).
                    if (function_exists('rh_ai_sk_learn_csv') && $scf_prefill['real']) {
                        try {
                            rh_ai_sk_learn_csv($pdo, $user, $scf_items, $scf_prefill['real']);
                        } catch (Throwable $e) {
                            error_log('stock-cost-fix learn csv: ' . $e->getMessage());
                        }
                    }
                }
            }
        } elseif ($action === 'preview') {
            if (!$lines) {
                $error = 'Type the units in one crate/case for at least one item, then press Preview.';
            } else {
                $scf_preview = rh_scf_preview($pdo, $lines);
                $scf_problems = $scf_preview['errors'];
            }
        } elseif ($action === 'apply') {
            $res = rh_scf_apply($pdo, $lines, (int)$user['id'], (string)$user['username']);
            if ($res['ok']) {
                // Assistant stock name check (when on): each confirmed size teaches "crate of N" (administrator / manager only).
                if (function_exists('rh_ai_sk_learn_fix')) {
                    try {
                        rh_ai_sk_learn_fix($pdo, $user, $res['rows']);
                    } catch (Throwable $e) {
                        error_log('stock-cost-fix learn: ' . $e->getMessage());
                    }
                }
                $cnt = count($res['rows']);
                $_SESSION['stock_msg'] = 'Corrected ' . $cnt . ' item' . ($cnt === 1 ? '' : 's') . '. Their stock value went from '
                    . $currency_symbol . ' ' . number_format($res['before'], 2) . ' to ' . $currency_symbol . ' ' . number_format($res['after'], 2)
                    . '. Each change is recorded in System Logs and Staff activity.';
                header('Location: stock-cost-fix.php');
                exit;
            }
            $scf_problems = $res['errors'];
            $error = 'Nothing was changed. Fix or remove the items named below, then preview again.';
        } else {
            $error = 'Unknown action.';
        }
    }
}

// Flags: the same shared judge as Stock Items' "May be a case price" note, worked out over every active item with a cost.
$scf_flags = [];
if ($scf_items) {
    try {
        require_once __DIR__ . '/../includes/import/case-signals.php';
        $scf_flags = import_cs_stock_flags($scf_items, import_cs_load_evidence($pdo));
    } catch (Throwable $e) {
        $scf_flags = [];
    }
}
usort($scf_items, function ($a, $b) use ($scf_flags) {
    $fa = isset($scf_flags[(int)$a['id']]) ? 0 : 1;
    $fb = isset($scf_flags[(int)$b['id']]) ? 0 : 1;
    return [$fa, mb_strtolower((string)$a['category']), mb_strtolower((string)$a['name'])]
        <=> [$fb, mb_strtolower((string)$b['category']), mb_strtolower((string)$b['name'])];
});
$scf_categories = [];
$scf_units = [];
foreach ($scf_items as $it) {
    $scf_categories[(string)$it['category']] = true;
    $scf_units[mb_strtolower(trim((string)$it['unit']))] = (string)$it['unit'];
}
ksort($scf_categories, SORT_FLAG_CASE | SORT_STRING);
ksort($scf_units, SORT_STRING);
$scf_isBottleCan = function (string $unit): bool {
    return in_array(mb_strtolower(trim($unit)), ['bottle', 'bottles', 'can', 'cans'], true);
};
$scf_fmtQty = function ($q): string {
    $s = rtrim(rtrim(number_format((float)$q, 3, '.', ','), '0'), '.');
    return $s === '-0' ? '0' : $s;
};
$scf_cur = htmlspecialchars($currency_symbol);
// ?item=<id> (Menu Management's "Fix crate prices" link): the page opens filtered to that stock item.
$scf_focusName = '';
$scf_focusId = isset($_GET['item']) ? (int)$_GET['item'] : 0;
if ($scf_focusId > 0) {
    foreach ($scf_items as $it) {
        if ((int)$it['id'] === $scf_focusId) {
            $scf_focusName = (string)$it['name'];
            break;
        }
    }
}

// Figure cards for the default filter (bottle + can); the page script keeps them in step with the filters.
$scf_listed = 0;
$scf_listedValue = 0.0;
$scf_listedFlagged = 0;
foreach ($scf_items as $it) {
    if ($scf_isBottleCan((string)$it['unit'])) {
        $scf_listed++;
        $scf_listedValue += (float)$it['current_quantity'] * (float)$it['cost_per_unit'];
        $scf_listedFlagged += isset($scf_flags[(int)$it['id']]) ? 1 : 0;
    }
}
$scf_typedCount = 0;
foreach ($scf_typed as $t) {
    $scf_typedCount += trim((string)$t['n']) !== '' ? 1 : 0;
}

$scf_applyJson = '';
if ($scf_preview && !$scf_preview['errors'] && $scf_preview['rows']) {
    $scf_applyJson = json_encode(rh_scf_apply_payload($scf_preview['rows']));
}

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Fix crate prices — Stock Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        /* Fix crate prices: 3 numbered steps, one card each (existing admin tokens only). */
        .scf-page { padding-bottom:140px; }
        .scf-card { background:var(--au-surface,#fff); border:1px solid var(--au-border,#e6e0d6); border-radius:var(--au-radius,4px); padding:16px 18px; margin-bottom:18px; box-shadow:var(--au-shadow-sm,0 2px 8px rgba(70,60,50,.06)); min-width:0; scroll-margin-top:90px; }
        .scf-card h3 { margin:0; font-family:'Cormorant Garamond',serif; font-size:1.3rem; color:var(--au-text,#3e3930); }
        .scf-card p { margin:0 0 8px; color:var(--au-text,#3e3930); font-size:.9rem; line-height:1.5; }
        .scf-intro p { margin:0 0 6px; font-size:.95rem; }
        .scf-muted { color:var(--au-muted,#8a8172); font-size:.82rem; }
        .scf-step__head { display:flex; align-items:center; gap:10px; margin:0 0 6px; }
        .scf-step__num { flex:0 0 auto; width:30px; height:30px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:600; font-size:.9rem; border:1px solid var(--au-accent-deep,#7E684B); color:var(--au-accent-deep,#7E684B); background:#fff; }
        .scf-step--current .scf-step__num { background:var(--au-accent-deep,#7E684B); color:#fff; }
        .scf-card .scf-step__lead { margin:0 0 12px; }
        .scf-step--later p { margin:0; }
        .scf-fill { border:1px solid var(--au-border-soft,#efeae1); border-radius:var(--au-radius-sm,4px); padding:10px 12px; margin:0 0 14px; }
        .scf-fill__lead { display:block; margin:0 0 8px; font-size:.86rem; color:var(--au-text,#3e3930); }
        .scf-card .scf-fill .scf-muted { margin:8px 0 0; }
        .scf-upload { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .scf-file-input { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
        .scf-file-input:focus-visible + .scf-file-btn { outline:2px solid var(--au-accent-deep,#7E684B); outline-offset:2px; }
        .scf-file-name { font-size:.86rem; color:var(--au-text,#3e3930); min-width:0; overflow-wrap:anywhere; }
        .scf-file-name.is-empty { color:var(--au-muted,#8a8172); }
        .scf-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:8px; }
        .scf-toolbar label { display:flex; flex-direction:column; gap:4px; font-size:.78rem; color:var(--au-muted,#8a8172); min-width:0; flex:1 1 160px; }
        .scf-toolbar select, .scf-toolbar input[type="search"] { min-height:44px; padding:8px 10px; border:1px solid var(--au-border,#d3cbc0); border-radius:var(--au-radius-sm,4px); font-family:inherit; font-size:.9rem; background:#fff; width:100%; box-sizing:border-box; }
        .scf-toolbar label.scf-chip { flex:0 0 auto; flex-direction:row; align-items:center; gap:8px; min-height:44px; padding:0 12px; border:1px solid var(--au-border,#d3cbc0); border-radius:var(--au-radius-sm,4px); background:#fff; color:var(--au-text,#3e3930); font-size:.86rem; cursor:pointer; box-sizing:border-box; }
        .scf-chip input[type="checkbox"] { width:20px; height:20px; margin:0; accent-color:var(--au-accent-deep,#7E684B); cursor:pointer; }
        .scf-card .scf-summary { margin:0 0 10px; }
        .scf-btn { min-height:44px; padding:9px 16px; border:1px solid transparent; border-radius:var(--au-radius-sm,4px); cursor:pointer; font-family:inherit; font-size:.88rem; display:inline-flex; align-items:center; gap:6px; text-decoration:none; box-sizing:border-box; }
        .scf-btn--primary { background:var(--au-accent-deep,#7E684B); color:#fff; }
        .scf-btn--ghost { background:#fff; border-color:var(--au-border,#d3cbc0); color:var(--au-text,#3e3930); }
        .scf-btn[disabled] { opacity:.5; cursor:not-allowed; }
        .scf-bar { display:flex; flex-wrap:wrap; gap:10px 14px; align-items:center; justify-content:flex-start; }
        .scf-bar__count { font-size:.92rem; color:var(--au-text,#3e3930); }
        .scf-table { width:100%; border-collapse:collapse; }
        .scf-table th, .scf-table td { padding:9px 10px; border-bottom:1px solid var(--au-border-soft,#efeae1); font-size:.88rem; text-align:left; vertical-align:top; }
        .scf-table th { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:var(--au-muted,#8a8172); }
        .scf-table .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .scf-table td.scf-item { overflow-wrap:anywhere; }
        .scf-table input[type="number"] { width:96px; min-height:44px; padding:6px 8px; border:1px solid var(--au-border,#d3cbc0); border-radius:var(--au-radius-sm,4px); font-family:inherit; font-size:.92rem; box-sizing:border-box; }
        .scf-table input[type="text"] { width:110px; min-height:44px; padding:6px 8px; border:1px solid var(--au-border,#d3cbc0); border-radius:var(--au-radius-sm,4px); font-family:inherit; font-size:.92rem; box-sizing:border-box; }
        .scf-table input[aria-invalid="true"] { border-color:var(--au-danger,#a25048); box-shadow:0 0 0 2px rgba(162,80,72,.18); }
        .scf-table tr.scf-row--typed td { background:#faf8f4; }
        .scf-table tr.scf-row--focus td { background:#f3ecdf; }
        .scf-flag { display:inline-block; margin-top:3px; }
        .scf-down { color:#2e6b34; font-weight:600; }
        .scf-pair { display:block; }
        .scf-amt { white-space:nowrap; }
        #scf-preview-table th, #scf-preview-table td.num { white-space:normal; }
        #scf-preview-table td.scf-item { min-width:120px; }
        .scf-batches { margin:0; padding:0; list-style:none; font-size:.8rem; }
        .scf-batches li { margin:0 0 3px; overflow-wrap:anywhere; }
        .scf-check { color:#8a6a2f; font-weight:600; }
        .scf-batches li + li { margin-top:6px; padding-top:6px; border-top:1px dashed var(--au-border-soft,#efeae1); }
        .scf-tick { display:inline-flex; align-items:center; gap:8px; min-height:44px; padding:0 4px 0 0; cursor:pointer; font-weight:600; color:var(--au-text,#3e3930); }
        .scf-tick input[type="checkbox"] { width:20px; height:20px; margin:0; flex:0 0 auto; accent-color:var(--au-accent-deep,#7E684B); cursor:pointer; }
        .scf-held { margin:8px 0 0; padding:8px 10px; background:#f3ecdf; border-radius:var(--au-radius-sm,4px); font-size:.8rem; color:var(--au-text,#3e3930); }
        .scf-po-note { margin:0 0 14px; padding:10px 12px; border:1px solid var(--au-border,#e6e0d6); border-radius:var(--au-radius-sm,4px); font-size:.88rem; }
        .scf-list { margin:6px 0 0; padding-left:18px; font-size:.86rem; max-height:220px; overflow:auto; overflow-wrap:anywhere; }
        .scf-msg { display:none; margin:0 0 12px; padding:10px 12px; border-radius:var(--au-radius-sm,4px); background:#f0e3e3; color:#8a3a3a; font-size:.88rem; }
        .scf-msg.is-shown { display:block; }
        .scf-empty { text-align:center; padding:30px 12px; color:var(--au-muted,#8a8172); }
        details.scf-details summary { cursor:pointer; min-height:44px; display:flex; align-items:center; font-size:.88rem; color:var(--au-accent-deep,#7E684B); }
        details.scf-details ul { margin:4px 0 6px; padding-left:18px; font-size:.86rem; line-height:1.5; color:var(--au-text,#3e3930); }
        @media (max-width:640px) {
            .scf-card { padding:14px 12px; }
            /* Room to scroll the last button above the fixed Assistant notice and app bar on a phone. */
            .scf-page { padding-bottom:380px; }
            .scf-bar .scf-btn, .scf-upload .scf-btn { flex:1 1 auto; justify-content:center; }
            .scf-table input[type="number"], .scf-table input[type="text"] { width:100%; }
        }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content scf-page" id="scf-page">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-wine-bottle" style="color:var(--au-accent-deep,#7E684B);"></i> Fix crate prices</h2>
            <div><a class="scf-btn scf-btn--ghost" href="stock-ingredients.php"><i class="fas fa-arrow-left"></i> Stock items</a></div>
        </div>

        <?php if ($error): showAlert($error, 'error'); endif; ?>

        <div class="scf-card scf-intro">
            <p>Some drinks were saved with the price of a whole crate as the price of one bottle.</p>
            <p>Type how many bottles or cans are in one crate. The price of one is worked out for you (example: 24,000 for a crate of 24 → 1,000 each).</p>
            <p>Nothing changes until you press <strong>Confirm</strong>. Quantities stay the same.</p>
            <details class="scf-details">
                <summary>More detail</summary>
                <ul>
                    <li><strong>What changes:</strong> the price of one, the crate size saved on the item, the price of each batch still in stock that you tick (the price future sales are costed at), and open purchase order lines still to receive at the crate price.</li>
                    <li><strong>What stays:</strong> the unit, quantities in stock, minimum, reorder and par levels, recipes, and past records (stock counts, adjustments, deliveries, received order lines and used-up batches).</li>
                    <li>Each corrected item is recorded in System Logs and Staff activity.</li>
                    <li>Items without a price are not listed: there is nothing to divide.</li>
                </ul>
            </details>
        </div>

        <?php $scf_hasPreview = $scf_preview && $scf_preview['rows']; $scf_canConfirm = $scf_applyJson !== ''; ?>
        <?php if (!$error || $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <form method="POST" action="stock-cost-fix.php" enctype="multipart/form-data" id="scf-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="grid_json" id="scf-grid-json" value="">

            <div class="scf-card scf-step<?php echo $scf_canConfirm ? '' : ' scf-step--current'; ?>" id="scf-step1">
                <div class="scf-step__head"><span class="scf-step__num" aria-hidden="true">1</span><h3>Type the crate size</h3></div>
                <p class="scf-muted scf-step__lead">Type a number only where the price is for a whole crate or case. Leave the others blank.</p>

                <div class="scf-fill">
                    <span class="scf-fill__lead">Or fill the numbers from your list <i class="help" tabindex="0" role="button" aria-label="About filling from your list" data-tip="A CSV with the columns Item and Units in one crate/case (FILL IN); rows marked Y in Real per-unit price? are skipped.">?</i></span>
                    <div class="scf-upload">
                        <input type="file" class="scf-file-input" name="fill_file" id="scf-file" accept=".csv,text/csv">
                        <label for="scf-file" class="scf-btn scf-btn--ghost scf-file-btn"><i class="fas fa-file-csv" aria-hidden="true"></i> Choose CSV file</label>
                        <span class="scf-file-name is-empty" id="scf-file-name" aria-live="polite">No file chosen</span>
                        <button type="submit" name="action" value="prefill" class="scf-btn scf-btn--ghost" id="scf-prefill-btn" disabled>Fill from list</button>
                    </div>
                    <p class="scf-muted">Save your list in Excel as CSV (File › Save As › CSV UTF-8). Excel files are refused.</p>
                </div>

                <?php if ($scf_prefill): ?>
                    <div class="scf-fill" id="scf-prefill-report">
                        <p><strong>Filled from <?php echo htmlspecialchars($scf_prefill['file']); ?>:</strong> <?php echo count($scf_prefill['fill']); ?> item<?php echo count($scf_prefill['fill']) === 1 ? '' : 's'; ?> filled in. Nothing is saved yet: check the numbers below, then press Preview.</p>
                        <?php foreach ([
                            'unmatched' => ['Not found (no item with a price has exactly this name)', 'Names must match exactly (capitals and spaces at the ends do not matter). Type these by hand.'],
                            'ambiguous' => ['More than one item has this name', 'Not filled: type the number on the right item by hand.'],
                            'bad' => ['Not a whole number of 2 or more', 'Not filled.'],
                            'real' => ['Marked as already priced per unit (Y)', 'Skipped: nothing to correct.'],
                        ] as $k => [$title, $hint]): if (!$scf_prefill[$k]) { continue; } ?>
                            <details class="scf-details" <?php echo $k === 'unmatched' ? 'open' : ''; ?>>
                                <summary><?php echo htmlspecialchars($title); ?>: <?php echo count($scf_prefill[$k]); ?></summary>
                                <p class="scf-muted"><?php echo htmlspecialchars($hint); ?></p>
                                <ul class="scf-list"><?php foreach ($scf_prefill[$k] as $nm): ?><li><?php echo htmlspecialchars($nm); ?></li><?php endforeach; ?></ul>
                            </details>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (function_exists('rh_ai_sk_mount') && $scf_items) {
                    rh_ai_sk_mount($pdo, $user, 'fix', $scf_items, $scf_flags, ['btn_class' => 'scf-btn scf-btn--ghost']);
                } ?>

                <div class="scf-toolbar">
                    <label>Search <input type="search" id="scf-search" placeholder="Item name" autocomplete="off"></label>
                    <label>Category
                        <select id="scf-cat">
                            <option value="">All categories</option>
                            <?php foreach (array_keys($scf_categories) as $c): ?>
                                <option value="<?php echo htmlspecialchars((string)$c === '' ? '__none' : mb_strtolower((string)$c)); ?>"><?php echo htmlspecialchars($c !== '' ? (string)$c : '(no category)'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Unit
                        <select id="scf-unit">
                            <option value="__bc" selected>Bottle and can</option>
                            <option value="">All units</option>
                            <?php foreach ($scf_units as $uk => $ul): ?>
                                <option value="<?php echo htmlspecialchars($uk); ?>"><?php echo htmlspecialchars($ul); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="scf-chip"><input type="checkbox" id="scf-flag-only"> <span class="badge badge-warning">May be a case price</span> <span id="scf-k-flag"><?php echo (int)$scf_listedFlagged; ?></span> <i class="help" tabindex="0" role="button" aria-label="About may be a case price" data-tip="Tick to list only the items this hotel's own records suggest are priced by the crate.">?</i></label>
                </div>
                <p class="scf-muted scf-summary"><span id="scf-k-count"><?php echo (int)$scf_listed; ?></span> items listed · stock value now <?php echo $scf_cur; ?> <span id="scf-k-value"><?php echo number_format($scf_listedValue, 2); ?></span> <i class="help" tabindex="0" role="button" aria-label="About items listed" data-tip="Items with a price that match the filters, and their stock value (in stock times the price of one).">?</i></p>
                <div class="scf-msg" id="scf-js-msg" role="alert"></div>

                <?php if (!$scf_items): ?>
                    <div class="scf-empty"><i class="fas fa-check-circle" style="font-size:1.6rem;"></i><p>No active stock items with a price.</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="scf-table" id="scf-table">
                            <thead>
                                <tr>
                                    <th>Item <i class="help" tabindex="0" role="button" aria-label="About item" data-tip="The stock item, its category and how many are in stock.">?</i></th>
                                    <th>Units in one crate/case <i class="help" tabindex="0" role="button" aria-label="About units in one crate or case" data-tip="How many bottles or cans are in one crate (2 or more); leave blank if the price is already for one.">?</i></th>
                                    <th>Pack name <i class="help" tabindex="0" role="button" aria-label="About pack name" data-tip="What the pack is called, saved on the item; blank means crate.">?</i></th>
                                    <th class="num">Price of one now <i class="help" tabindex="0" role="button" aria-label="About price of one now" data-tip="The price stored for one bottle or can today.">?</i></th>
                                    <th class="num">Value now <i class="help" tabindex="0" role="button" aria-label="About value now" data-tip="In stock times the price of one now.">?</i></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($scf_items as $it):
                                    $id = (int)$it['id'];
                                    $unitKey = mb_strtolower(trim((string)$it['unit']));
                                    $shown = $scf_isBottleCan((string)$it['unit']);
                                    $qty = (float)$it['current_quantity'];
                                    $cost = (float)$it['cost_per_unit'];
                                    $tN = $scf_typed[$id]['n'] ?? '';
                                    $tSrc = in_array($scf_typed[$id]['src'] ?? '', ['suggested', 'csv'], true) ? (string)$scf_typed[$id]['src'] : '';
                                    $tPack = $scf_typed[$id]['pack'] ?? '';
                                    if ($tPack === '' && $tN === '' && trim((string)($it['pack_name'] ?? '')) !== '') {
                                        $tPack = (string)$it['pack_name'];
                                    }
                                    $flag = $scf_flags[$id] ?? null; ?>
                                    <tr data-scf-row="1" data-id="<?php echo $id; ?>" data-name="<?php echo htmlspecialchars(mb_strtolower((string)$it['name'])); ?>" data-cat="<?php echo htmlspecialchars((string)$it['category'] === '' ? '__none' : mb_strtolower((string)$it['category'])); ?>" data-unit="<?php echo htmlspecialchars($unitKey); ?>" data-value="<?php echo htmlspecialchars(sprintf('%.4F', $qty * $cost)); ?>" data-flag="<?php echo $flag ? '1' : '0'; ?>"<?php echo $shown ? '' : ' data-filter-hidden="1" hidden'; ?> class="<?php echo trim((string)$tN) !== '' ? 'scf-row--typed' : ''; ?>">
                                        <td class="scf-item"><strong><?php echo htmlspecialchars((string)$it['name']); ?></strong>
                                            <br><span class="scf-muted"><?php echo htmlspecialchars((string)$it['category'] !== '' ? (string)$it['category'] : '(no category)'); ?> · <?php echo htmlspecialchars(rh_scf_qty_unit($qty, (string)$it['unit'])); ?> in stock</span>
                                            <?php if ($flag): ?><br><span class="scf-flag"><span class="badge badge-warning">May be a case price</span> <i class="help" tabindex="0" role="button" aria-label="Why it may be a case price" data-tip="<?php echo htmlspecialchars((string)$flag['reason']); ?>">?</i></span><?php endif; ?>
                                        </td>
                                        <td><input type="number" class="scf-n" min="2" max="99999" step="1" inputmode="numeric" value="<?php echo htmlspecialchars((string)$tN); ?>"<?php echo $tSrc !== '' && trim((string)$tN) !== '' ? ' data-src="' . $tSrc . '"' : ''; ?> aria-label="Units in one crate or case for <?php echo htmlspecialchars((string)$it['name']); ?>"></td>
                                        <td><input type="text" class="scf-pack" maxlength="30" placeholder="crate" value="<?php echo htmlspecialchars((string)$tPack); ?>" aria-label="Pack name for <?php echo htmlspecialchars((string)$it['name']); ?>"></td>
                                        <td class="num"><?php echo $scf_cur . ' ' . rh_scf_fmt($cost); ?></td>
                                        <td class="num"><?php echo $scf_cur . ' ' . number_format($qty * $cost, 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="scf-muted" style="margin:10px 0 0;">Numbers you type stay when you change page or filter.</p>
                <?php endif; ?>
            </div>

            <div class="scf-card scf-step<?php echo $scf_canConfirm ? '' : ' scf-step--current'; ?>" id="scf-step2">
                <div class="scf-step__head"><span class="scf-step__num" aria-hidden="true">2</span><h3>Check</h3></div>
                <p class="scf-muted scf-step__lead">Preview shows the price of one before and after for every item you typed. Nothing is saved yet.</p>
                <div class="scf-bar">
                    <span class="scf-bar__count"><strong id="scf-k-typed"><?php echo (int)$scf_typedCount; ?></strong> <span id="scf-typed-word"><?php echo $scf_typedCount === 1 ? 'item' : 'items'; ?></span> typed</span>
                    <button type="submit" name="action" value="preview" class="scf-btn <?php echo $scf_canConfirm ? 'scf-btn--ghost' : 'scf-btn--primary'; ?>" id="scf-preview-btn" <?php echo ($scf_items && $scf_typedCount > 0) ? '' : 'disabled'; ?>><i class="fas fa-eye" aria-hidden="true"></i> <?php echo $scf_hasPreview ? 'Preview again' : 'Preview'; ?></button>
                    <span class="scf-muted" id="scf-preview-hint"<?php echo $scf_typedCount > 0 ? ' hidden' : ''; ?>>Type a number in at least one box first.</span>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <?php if ($scf_problems): ?>
            <div class="scf-card" id="scf-problems" role="alert" style="border-color:#e3c4c0;">
                <h3>These items need a look</h3>
                <ul class="scf-list"><?php foreach ($scf_problems as $p): ?><li><?php echo htmlspecialchars($p); ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <?php if ($scf_hasPreview): $scf_pv = $scf_preview; $scf_pvCount = count($scf_pv['rows']); ?>
            <form method="POST" action="stock-cost-fix.php" id="scf-confirm-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="apply_json" value="<?php echo htmlspecialchars($scf_applyJson, ENT_QUOTES); ?>">
            <div class="scf-card" id="scf-preview">
                <h3 style="margin-bottom:10px;">What will change <span class="scf-muted">nothing is changed until you press Confirm</span></h3>
                <div class="ck-kpis ck-kpis--4" style="margin-bottom:14px;">
                    <div class="ck-kpi"><span class="ck-kpi__label">Items to correct <i class="help" tabindex="0" role="button" aria-label="About items to correct" data-tip="Items with a crate size typed that passed the checks.">?</i></span><span class="ck-kpi__value"><?php echo (int)$scf_pvCount; ?></span></div>
                    <div class="ck-kpi"><span class="ck-kpi__label">Value before <i class="help" tabindex="0" role="button" aria-label="About value before" data-tip="In stock times the price of one now, for these items.">?</i></span><span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo $scf_cur; ?></span> <?php echo number_format($scf_pv['before'], 2); ?></span></div>
                    <div class="ck-kpi"><span class="ck-kpi__label">Value after <i class="help" tabindex="0" role="button" aria-label="About value after" data-tip="In stock times the new price of one, for these items; it follows the batch boxes.">?</i></span><span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo $scf_cur; ?></span> <span id="scf-pv-after" data-before="<?php echo htmlspecialchars(sprintf('%.2F', $scf_pv['before'])); ?>"><?php echo number_format($scf_pv['after'], 2); ?></span></span></div>
                    <div class="ck-kpi"><span class="ck-kpi__label">Change <i class="help" tabindex="0" role="button" aria-label="About change" data-tip="Value after minus value before.">?</i></span><span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo $scf_cur; ?></span> <span id="scf-pv-change"><?php echo number_format($scf_pv['after'] - $scf_pv['before'], 2); ?></span></span></div>
                </div>
                <?php if ($scf_pv['pos']): ?>
                    <div class="scf-po-note">
                        <strong>Open purchase orders:</strong> lines still to receive at the crate price get the new price of one, so check their quantities are single units, not crates.
                        <ul class="scf-list">
                            <?php foreach ($scf_pv['pos'] as $po): ?>
                                <li><?php echo htmlspecialchars($po['ref']); ?>: total <?php echo $scf_cur . ' ' . number_format($po['old_total'], 2); ?> → <?php echo number_format($po['new_total'], 2); ?><?php if ($po['status'] !== 'draft'): ?> · <span class="scf-check">already sent: send the supplier the corrected order</span><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php $scf_pvSugN = count(array_filter($scf_pv['rows'], function ($x) { return ($x['src'] ?? '') === 'suggested'; })); if ($scf_pvSugN > 0): ?>
                    <p class="scf-muted"><strong><?php echo (int)$scf_pvSugN; ?> <?php echo $scf_pvSugN === 1 ? 'item uses' : 'items use'; ?> a crate size from a suggestion.</strong> They are listed first: check each size against the invoice before you confirm.</p>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="scf-table" id="scf-preview-table">
                        <thead>
                            <tr>
                                <th>Item <i class="help" tabindex="0" role="button" aria-label="About item" data-tip="The item, the crate size that will be saved on it, and how many are in stock (quantities do not change).">?</i></th>
                                <th class="num">Price of one <i class="help" tabindex="0" role="button" aria-label="About price of one" data-tip="The price of one bottle or can now, and after the change.">?</i></th>
                                <th class="num">Value in stock <i class="help" tabindex="0" role="button" aria-label="About value in stock" data-tip="In stock times the price of one, before and after.">?</i></th>
                                <th>Batches holding stock <i class="help" tabindex="0" role="button" aria-label="About batches holding stock" data-tip="Ticked batches were bought at the crate price and are divided; unticked ones keep their price.">?</i></th>
                                <th>Open purchase orders <i class="help" tabindex="0" role="button" aria-label="About open purchase orders" data-tip="Lines still to receive at the crate price get the new price of one; other lines stay as they are.">?</i></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Sizes taken from a suggestion are listed first, in their own group (display only; the maths is the same).
                            $scf_pvRows = $scf_pv['rows'];
                            $scf_pvSug = count(array_filter($scf_pvRows, function ($x) { return ($x['src'] ?? '') === 'suggested'; }));
                            if ($scf_pvSug > 0) {
                                usort($scf_pvRows, function ($a, $b) { return (($a['src'] ?? '') === 'suggested' ? 0 : 1) <=> (($b['src'] ?? '') === 'suggested' ? 0 : 1); });
                            }
                            foreach ($scf_pvRows as $r): ?>
                                <tr data-scf-pv="1" data-qty="<?php echo htmlspecialchars(sprintf('%.6F', $r['qty'])); ?>" data-divn="<?php echo htmlspecialchars(sprintf('%.4F', $r['div_n'])); ?>">
                                    <td class="scf-item"><strong><?php echo htmlspecialchars($r['name']); ?></strong><?php if (($r['src'] ?? '') === 'suggested'): ?><br><span class="badge badge-warning">From a suggestion: check the crate size</span><?php endif; ?><br><span class="scf-muted"><?php echo htmlspecialchars($r['pack']); ?> of <?php echo (int)$r['n']; ?> · <?php echo htmlspecialchars(rh_scf_qty_unit($r['qty'], (string)$r['unit'])); ?> in stock</span></td>
                                    <td class="num"><span class="scf-pair">Now: <span class="scf-amt"><?php echo $scf_cur . ' ' . rh_scf_fmt($r['old_cost']); ?></span> each</span><span class="scf-pair">After: <span class="scf-down scf-amt"><?php echo $scf_cur; ?> <span class="scf-new-cost"><?php echo rh_scf_fmt($r['new_cost']); ?></span></span> each <i class="help" tabindex="0" role="button" aria-label="How the new price is worked out" data-tip="<?php echo htmlspecialchars($r['basis']); ?>">?</i></span></td>
                                    <td class="num"><span class="scf-pair">Before: <span class="scf-amt"><?php echo $scf_cur . ' ' . number_format($r['old_value'], 2); ?></span></span><span class="scf-pair">After: <span class="scf-down scf-amt"><?php echo $scf_cur; ?> <span class="scf-new-value"><?php echo number_format($r['new_value'], 2); ?></span></span></span></td>
                                    <td>
                                        <?php if (!$r['batches']): ?>
                                            <span class="scf-muted">None hold stock</span>
                                        <?php else: ?>
                                            <ul class="scf-batches">
                                                <?php foreach ($r['batches'] as $b): $bLabel = $b['number'] !== '' ? $b['number'] : ('#' . $b['id']); ?>
                                                    <li>
                                                        <label class="scf-tick"><input type="checkbox" class="scf-divide" name="divide[]" value="<?php echo (int)$r['id'] . ':' . (int)$b['id']; ?>" data-rem="<?php echo htmlspecialchars(sprintf('%.6F', $b['remaining'])); ?>" data-old="<?php echo htmlspecialchars(sprintf('%.4F', $b['old'])); ?>" data-div="<?php echo htmlspecialchars(sprintf('%.4F', $b['divided'])); ?>"<?php echo $b['default'] ? ' checked' : ''; ?>> Divide this batch (bought at the crate price)</label>
                                                        <span><?php echo htmlspecialchars($bLabel); ?>: <?php echo htmlspecialchars(rh_scf_qty_unit($b['remaining'], (string)$r['unit'])); ?> left, <?php echo rh_scf_fmt($b['old']); ?> each now, <?php echo rh_scf_fmt($b['divided']); ?> each if divided</span>
                                                        <?php if (!$b['default']): ?><br><span class="scf-check">Already priced per <?php echo htmlspecialchars((string)$r['unit']); ?>: left as it is.</span> <i class="help" tabindex="0" role="button" aria-label="Why this batch starts unticked" data-tip="Its price is well below the item's price, so it was probably bought per <?php echo htmlspecialchars((string)$r['unit']); ?>; tick it only if it was bought at the crate price.">?</i><?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <p class="scf-held" data-scf-held="1"<?php echo $r['held_mixed'] ? '' : ' hidden'; ?>>Some batches keep their price, so the new price of one is the average over all the stock held, not the old price divided by <?php echo (int)$r['n']; ?>.</p>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$r['po_lines']): ?>
                                            <span class="scf-muted">None open</span>
                                        <?php else: ?>
                                            <ul class="scf-batches">
                                                <?php foreach ($r['po_lines'] as $pl):
                                                    $plWhy = ['pack' => 'ordered by the crate', 'noprice' => 'no price on the line', 'perunit' => 'already priced per ' . $r['unit']];
                                                    $plText = $pl['ref'] . ': ' . rh_scf_qty_unit($pl['outstanding'], (string)$r['unit']) . ' to receive, '
                                                        . ($pl['kind'] === 'divide'
                                                            ? $currency_symbol . ' ' . rh_scf_fmt($pl['old_cost']) . ' → ' . rh_scf_fmt($pl['new_cost']) . ' each'
                                                            : 'left as it is (' . ($plWhy[$pl['kind']] ?? 'unchanged') . ')'); ?>
                                                    <li class="<?php echo $pl['kind'] === 'divide' ? 'scf-down' : 'scf-muted'; ?>"><?php echo htmlspecialchars($plText); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="scf-card scf-step<?php echo $scf_canConfirm ? ' scf-step--current' : ''; ?>" id="scf-step3">
                <div class="scf-step__head"><span class="scf-step__num" aria-hidden="true">3</span><h3>Save</h3></div>
                <p class="scf-muted scf-step__lead"><?php echo $scf_pv['errors'] ? 'Fix the items named above, then press Preview again.' : 'If the numbers look right, press Confirm. If one is wrong, change it in step 1 and press Preview again.'; ?></p>
                <div class="scf-bar">
                    <button type="submit" class="scf-btn scf-btn--primary" id="scf-confirm" <?php echo $scf_canConfirm ? '' : 'disabled'; ?>><i class="fas fa-check" aria-hidden="true"></i> Confirm and correct <?php echo (int)$scf_pvCount; ?> item<?php echo $scf_pvCount === 1 ? '' : 's'; ?></button>
                </div>
            </div>
            </form>
        <?php else: ?>
            <div class="scf-card scf-step scf-step--later" id="scf-step3">
                <div class="scf-step__head"><span class="scf-step__num" aria-hidden="true">3</span><h3>Save</h3></div>
                <p class="scf-muted">After Preview, the Confirm button appears here. Nothing is saved before you press it.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
    <script>
    (function () {
        'use strict';
        var form = document.getElementById('scf-form');
        var table = document.getElementById('scf-table');
        if (!form) return;
        var rows = table ? Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-scf-row]')) : [];
        var search = document.getElementById('scf-search');
        var cat = document.getElementById('scf-cat');
        var unit = document.getElementById('scf-unit');
        var msg = document.getElementById('scf-js-msg');
        var flagOnly = document.getElementById('scf-flag-only');
        var previewBtn = document.getElementById('scf-preview-btn');
        var previewHint = document.getElementById('scf-preview-hint');
        var fileIn = document.getElementById('scf-file');
        var fileName = document.getElementById('scf-file-name');
        var prefillBtn = document.getElementById('scf-prefill-btn');
        var BC =['bottle', 'bottles', 'can', 'cans'];

        function money(v) {
            return (Math.round(v * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function nOf(row) { return (row.querySelector('.scf-n') || {}).value || ''; }
        function nValid(s) {
            s = String(s).trim();
            if (!/^\d{1,5}$/.test(s)) return false;
            var v = parseInt(s, 10);
            return v >= 2 && v <= 99999;
        }
        // skipFlag: ignore the "May be a case price" box (its own count shows how many it would list).
        function matches(row, skipFlag) {
            if (!skipFlag && flagOnly && flagOnly.checked && row.getAttribute('data-flag') !== '1') return false;
            var q = search ? search.value.trim().toLowerCase() : '';
            var c = cat ? cat.value : '';
            var u = unit ? unit.value : '';
            if (q && row.getAttribute('data-name').indexOf(q) === -1) return false;
            if (c !== '' && row.getAttribute('data-cat') !== c) return false;
            if (u === '__bc') return BC.indexOf(row.getAttribute('data-unit')) !== -1;
            if (u !== '' && row.getAttribute('data-unit') !== u) return false;
            return true;
        }
        function cards() {
            var count = 0, value = 0, flag = 0, typed = 0;
            rows.forEach(function (row) {
                if (String(nOf(row)).trim() !== '') typed++;
                if (row.getAttribute('data-flag') === '1' && matches(row, true)) flag++;
                if (row.dataset.filterHidden === '1') return;
                count++;
                value += parseFloat(row.getAttribute('data-value')) || 0;
            });
            var set = function (id, t) { var el = document.getElementById(id); if (el) el.textContent = t; };
            set('scf-k-count', String(count));
            set('scf-k-value', money(value));
            set('scf-k-flag', String(flag));
            set('scf-k-typed', String(typed));
            set('scf-typed-word', typed === 1 ? 'item' : 'items');
            // Preview needs at least one typed box.
            if (previewBtn) previewBtn.disabled = !rows.length || typed === 0;
            if (previewHint) previewHint.hidden = typed > 0;
        }
        function fileChanged() {
            if (!fileIn) return;
            var f = fileIn.files && fileIn.files.length ? fileIn.files[0] : null;
            if (f && /\.(xlsx?|xlsm|xlsb|ods)$/i.test(f.name)) {
                fileIn.value = '';
                f = null;
                say('Excel files are refused. In Excel use File › Save As › CSV UTF-8, then choose the CSV file.');
            } else if (f) {
                say('');
            }
            if (fileName) {
                fileName.textContent = f ? f.name : 'No file chosen';
                fileName.classList.toggle('is-empty', !f);
            }
            if (prefillBtn) prefillBtn.disabled = !f;
        }
        if (fileIn) fileIn.addEventListener('change', fileChanged);
        function applyFilter() {
            rows.forEach(function (row) {
                if (matches(row)) { delete row.dataset.filterHidden; row.hidden = false; }
                else { row.dataset.filterHidden = '1'; row.hidden = true; }
            });
            if (table && typeof table.rhPagerRefresh === 'function') table.rhPagerRefresh(true);
            cards();
        }
        // Enter in Search only filters; it must not submit the form (the first submit button is "Fill from list").
        if (search) search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); applyFilter(); } });
        [search, cat, unit, flagOnly].forEach(function (el) {
            if (el) el.addEventListener(el === search ? 'input' : 'change', applyFilter);
        });
        // Opened from Menu Management for one stock item: list just that item, whatever its unit.
        var focusName = <?php echo json_encode($scf_focusName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        if (focusName && search) {
            search.value = focusName;
            if (unit) unit.value = '';
            applyFilter();
        }
        if (table) {
            table.addEventListener('input', function (e) {
                if (!e.target.classList.contains('scf-n')) return;
                var row = e.target.closest('tr');
                if (row) row.classList.toggle('scf-row--typed', e.target.value.trim() !== '');
                if (e.isTrusted) e.target.removeAttribute('data-src');
                e.target.removeAttribute('aria-invalid');
                cards();
            });
            // Enter in a box must not send the form with the first button (Fill from list).
            table.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target.matches('input')) e.preventDefault();
            });
        }
        function say(text) {
            if (!msg) return;
            msg.textContent = text;
            msg.classList.toggle('is-shown', text !== '');
        }
        // Bring one row into view: clear the filters if they hide it, turn to its page, focus its box.
        function showRow(row, focusEl) {
            if (row.dataset.filterHidden === '1') {
                if (search) search.value = '';
                if (cat) cat.value = '';
                if (unit) unit.value = '';
                if (flagOnly) flagOnly.checked = false;
                applyFilter();
            }
            if (table && typeof table.rhPagerShowRow === 'function') table.rhPagerShowRow(row);
            row.hidden = false;
            row.classList.add('scf-row--focus');
            window.setTimeout(function () { row.classList.remove('scf-row--focus'); }, 2500);
            if (focusEl) { try { focusEl.focus({ preventScroll: true }); } catch (err) { focusEl.focus(); } }
            row.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
        form.addEventListener('submit', function (e) {
            var btn = e.submitter || document.activeElement;
            var act = btn && btn.name === 'action' ? btn.value : 'preview';
            var out = [];
            var bad = null;
            rows.forEach(function (row) {
                var nEl = row.querySelector('.scf-n');
                var n = nEl ? nEl.value.trim() : '';
                if (n === '') return;
                if (!nValid(n)) {
                    nEl.setAttribute('aria-invalid', 'true');
                    if (!bad) bad = row;
                    return;
                }
                var p = row.querySelector('.scf-pack');
                var src = nEl.getAttribute('data-src');
                out.push({ id: parseInt(row.getAttribute('data-id'), 10), n: n, pack: p ? p.value.trim() : '', src: src === 'suggested' || src === 'csv' ? src : 'typed' });
            });
            if (bad && act === 'preview') {
                e.preventDefault();
                say('Units in one crate/case must be a whole number of 2 or more. The first box to fix is highlighted.');
                showRow(bad, bad.querySelector('.scf-n'));
                return;
            }
            if (act === 'prefill') {
                var file = document.getElementById('scf-file');
                if (!file || !file.files || !file.files.length) {
                    e.preventDefault();
                    say('Choose the CSV file first, then press Fill from list.');
                    return;
                }
            } else if (!out.length) {
                e.preventDefault();
                say('Type the units in one crate/case for at least one item, then press Preview.');
                return;
            }
            say('');
            document.getElementById('scf-grid-json').value = JSON.stringify(out);
        });
        var confirmForm = document.getElementById('scf-confirm-form');
        // "Divide this batch": the new cost is the average of the stock held after the change (left x cost after, over the
        // batches; old / N when no batch holds stock), the same rule the server applies on Confirm. Keeps cost, value and cards in step.
        function heldCost(tr) {
            var boxes = tr.querySelectorAll('input.scf-divide');
            var q = 0, v = 0, mixed = false;
            Array.prototype.forEach.call(boxes, function (b) {
                var rem = parseFloat(b.getAttribute('data-rem')) || 0;
                var c = parseFloat(b.getAttribute(b.checked ? 'data-div' : 'data-old')) || 0;
                if (!b.checked) mixed = true;
                q += rem; v += rem * c;
            });
            var cost = q > 0.0000005 ? Math.round(v / q * 10000) / 10000 : (parseFloat(tr.getAttribute('data-divn')) || 0);
            var value = Math.round((parseFloat(tr.getAttribute('data-qty')) || 0) * cost * 100) / 100;
            var c1 = tr.querySelector('.scf-new-cost');
            if (c1) c1.textContent = cost.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
            var v1 = tr.querySelector('.scf-new-value');
            if (v1) { v1.textContent = money(value); v1.setAttribute('data-v', String(value)); }
            var p = tr.querySelector('[data-scf-held]');
            if (p) p.hidden = !mixed;
        }
        function pvCards() {
            var after = 0;
            Array.prototype.forEach.call(document.querySelectorAll('#scf-preview-table .scf-new-value'), function (el) {
                after += parseFloat(el.getAttribute('data-v') || el.textContent.replace(/,/g, '')) || 0;
            });
            var a = document.getElementById('scf-pv-after');
            var ch = document.getElementById('scf-pv-change');
            if (a) a.textContent = money(after);
            if (a && ch) ch.textContent = money(after - (parseFloat(a.getAttribute('data-before')) || 0));
        }
        if (confirmForm) {
            confirmForm.addEventListener('change', function (e) {
                if (!e.target.classList || !e.target.classList.contains('scf-divide')) return;
                var tr = e.target.closest('tr');
                if (tr) { heldCost(tr); pvCards(); }
            });
        }
        if (confirmForm) {
            confirmForm.addEventListener('submit', function () {
                var b = document.getElementById('scf-confirm');
                window.setTimeout(function () { if (b) { b.disabled = true; b.textContent = 'Correcting…'; } }, 0);
            });
        }
        // ?item=ID (from the "May be a case price" note on Stock Items): open that item, waiting for the pager to be ready.
        var want = parseInt(new URLSearchParams(window.location.search).get('item') || '0', 10);
        if (want > 0) {
            var target = rows.filter(function (r) { return parseInt(r.getAttribute('data-id'), 10) === want; })[0];
            if (target) {
                var tries = 0;
                (function waitPager() {
                    if ((table && typeof table.rhPagerShowRow === 'function') || tries++ > 20) { showRow(target, target.querySelector('.scf-n')); return; }
                    window.setTimeout(waitPager, 100);
                })();
            }
        }
        // After Preview the review is what you see first. The grid above it shrinks when the pager starts, so wait for the pager.
        var pv = document.getElementById('scf-preview') || document.getElementById('scf-problems');
        if (pv && !want) {
            var pvTries = 0;
            (function waitPagerPv() {
                if ((table && typeof table.rhPagerShowRow === 'function') || !table || pvTries++ > 20) {
                    window.requestAnimationFrame(function () { pv.scrollIntoView({ block: 'start' }); });
                    return;
                }
                window.setTimeout(waitPagerPv, 100);
            })();
        }
        fileChanged();
        cards();
    })();
    </script>
</body>
</html>
