<?php

/**
 * Put stock items on the drinks menu (owner 2026-11-22).
 *
 * Opened from Stock Items (tick rows > "Put on the menu": ?ids=1,2,3, those rows ticked) and from Menu Management > Drinks >
 * "Add from stock" (no ids: every stock item not archived and not kept by weight, showing the ones not on the drinks menu yet).
 * One row per item: drink name, Sold as, price (VAT included), category, cost of one, profit, status. "Add N to the menu" writes
 * the ticked rows that are ready. Logic, rules and writes: admin/includes/stock-to-menu-lib.php (the same writes as Import step 4).
 * Permission: menu (it writes the drinks menu); modules: pos + stock.
 */
require_once 'admin-init.php';
require_once __DIR__ . '/../includes/form-validation.php';
require_once __DIR__ . '/includes/stock-to-menu-lib.php';

$uid = (int)($_SESSION['admin_user_id'] ?? 0);
$currency_symbol = (string)getSetting('currency_symbol');
$error = '';
if (!ensureStockTablesExist()) {
    $error = 'Stock tables not yet created.';
}

// ---- JSON: preview (nothing saved) and save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $out = ['ok' => false, 'error' => ''];
    $rows = json_decode((string)($_POST['rows'] ?? ''), true);
    $action = (string)($_POST['action'] ?? '');
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
        $out['error'] = 'Security token invalid. Please reload the page.';
    } elseif ($error !== '') {
        $out['error'] = $error;
    } elseif (!is_array($rows) || count($rows) > 1000) {
        $out['error'] = 'Nothing to check. Please reload the page.';
    } elseif ($action === 'preview') {
        try {
            $out = ['ok' => true, 'error' => ''] + stm_build($pdo, $rows);
        } catch (Throwable $e) {
            error_log('stock-to-menu preview: ' . $e->getMessage());
            $out['error'] = 'The rows could not be checked. Please try again.';
        }
    } elseif ($action === 'save') {
        $out = stm_save($pdo, $uid, $rows);
    } else {
        $out['error'] = 'Unknown action.';
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- the screen
$fromStock = isset($_GET['ids']);
$ids = $fromStock ? array_slice(array_filter(array_map('intval', explode(',', (string)$_GET['ids']))), 0, 1000) : null;
$built = ['rows' => [], 'cats' => []];
if ($error === '') {
    try {
        $inputs = [];
        foreach (stm_items($pdo, $ids) as $it) {
            $inputs[] = ['ing_id' => (int)$it['id'], 'tick' => $fromStock];
        }
        $built = stm_build($pdo, $inputs);
        foreach ($built['rows'] as &$br) {      // arrived ticked from Stock Items: a row that cannot go, or has nothing to change, starts unticked
            if (in_array($br['status'], ['blocked', 'same'], true)) {
                $br['tick'] = false;
            }
        }
        unset($br);
    } catch (Throwable $e) {
        error_log('stock-to-menu build: ' . $e->getMessage());
        $error = 'The stock items could not be read. Please try again.';
    }
}
$show = $fromStock ? 'all' : 'new';
$h = function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Put on the drinks menu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/stock-to-menu.css?v=<?php echo @filemtime(__DIR__ . '/css/stock-to-menu.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content stm-page" id="stm-page">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-wine-glass" style="color:var(--au-accent-deep,#7a5b34);"></i> Put on the drinks menu</h2>
            <div class="stm-head-links">
                <a class="stm-btn stm-btn--ghost" href="stock-ingredients.php"><i class="fas fa-arrow-left"></i> Stock items</a>
                <a class="stm-btn stm-btn--ghost" href="menu-management.php"><i class="fas fa-utensils"></i> Menu</a>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="stm-msg stm-msg--error is-shown"><?php echo $h($error); ?></div>
        <?php else: ?>
        <div class="stm-card stm-intro">
            <p>Tick a stock item, type its selling price and press <strong>Add to the menu</strong>. It goes on the drinks menu and the till, and each sale takes what it is sold as off stock.</p>
            <p class="stm-muted">Prices include VAT and are saved exactly as typed. A drink already on the menu only gets its new price: its category and stock link stay as they are.</p>
        </div>

        <div id="stm-result" class="stm-msg" role="status" aria-live="polite"></div>

        <div class="stm-card">
            <div class="stm-toolbar">
                <label>Search
                    <input type="search" id="stm-q" placeholder="Drink or stock item">
                </label>
                <label>Show
                    <select id="stm-show">
                        <option value="new"<?php echo $show === 'new' ? ' selected' : ''; ?>>Not on the drinks menu yet</option>
                        <option value="menu">Already on the drinks menu</option>
                        <option value="ticked">Ticked</option>
                        <option value="all"<?php echo $show === 'all' ? ' selected' : ''; ?>>All</option>
                    </select>
                </label>
                <button type="button" class="stm-btn stm-btn--ghost" id="stm-tick-shown"><i class="fas fa-check-double"></i> Tick all shown</button>
                <button type="button" class="stm-btn stm-btn--ghost" id="stm-untick"><i class="fas fa-xmark"></i> Untick all</button>
            </div>
            <p class="stm-muted" id="stm-count"></p>

            <?php if (!$built['rows']): ?>
                <div class="stm-empty">
                    <?php if ($fromStock): ?>
                        None of the ticked stock items were found. <a href="stock-ingredients.php">Go back to Stock items</a>.
                    <?php else: ?>
                        No stock items yet. Add or import them in <a href="stock-ingredients.php">Stock items</a> first.
                    <?php endif; ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="stm-table" id="stm-table">
                    <thead>
                        <tr>
                            <th class="stm-col-tick">Add <i class="help" data-tip="Tick the drinks to save. Typing a price ticks the row for you. Only ticked rows are saved.">?</i></th>
                            <th>Drink name <i class="help" data-tip="The name on the drinks menu and the till. It starts as the stock item's name; change it if guests know it by another name. The stock item below it is what each sale takes stock from.">?</i></th>
                            <th>Sold as <i class="help" data-tip="What one sale is: one whole stock unit (bottle, can), or a measure: the item's own count measure, or a measure from your Drink measures list (Stock Items) when the item has a bottle size. Each sale takes this much off stock.">?</i></th>
                            <th>Price<?php echo $currency_symbol !== '' ? ' (' . $h($currency_symbol) . ')' : ''; ?> <i class="help" data-tip="The selling price of one, VAT included, in <?php echo $h($currency_symbol); ?>. It is saved exactly as typed (2 decimals); VAT is never added on top.">?</i></th>
                            <th>Category <i class="help" data-tip="The drinks menu category. It starts as the stock category when the drinks menu has one with that name, else Bar. A drink already on the menu keeps its own category.">?</i></th>
                            <th class="num">Cost of one<?php echo $currency_symbol !== '' ? ' (' . $h($currency_symbol) . ')' : ''; ?> <i class="help" data-tip="Stock cost of one sale: the item's current average cost per unit (Stock Items) times what one sale takes. For a drink already on the menu with a stock link, the cost of that link.">?</i></th>
                            <th class="num">Profit<?php echo $currency_symbol !== '' ? ' (' . $h($currency_symbol) . ')' : ''; ?> <i class="help" data-tip="Price minus cost of one, per sale, VAT included in the price. Shown once a price is typed.">?</i></th>
                            <th>Status <i class="help" data-tip="New on the menu, Price change (old -> new), Already on the menu at this price (nothing to save), or Cannot go on the menu with the reason.">?</i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($built['rows'] as $r): ?>
                            <tr class="stm-row" data-ing="<?php echo (int)$r['ing_id']; ?>" data-search="<?php echo $h(mb_strtolower($r['stock_name'] . ' ' . $r['name_typed'])); ?>">
                                <td class="stm-col-tick"><label class="stm-tick"><input type="checkbox" class="stm-tick-box" aria-label="Add <?php echo $h($r['stock_name']); ?>"<?php echo $r['tick'] ? ' checked' : ''; ?>></label></td>
                                <td class="stm-name">
                                    <input type="text" class="stm-in-name" maxlength="200" value="<?php echo $h($r['name_typed']); ?>" aria-label="Drink name">
                                    <span class="stm-muted stm-stock">Stock: <?php echo $h($r['stock_name']); ?> (<?php echo $h($r['unit']); ?>)</span>
                                </td>
                                <td>
                                    <select class="stm-in-sold" aria-label="Sold as">
                                        <?php foreach ($r['sold_options'] as $o): ?>
                                            <option value="<?php echo $h($o['v']); ?>"<?php echo $o['v'] === $r['sold'] ? ' selected' : ''; ?>><?php echo $h($o['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="stm-muted stm-sold-note"><?php echo $h($r['sold_note']); ?></span>
                                </td>
                                <td><input type="text" class="stm-in-price" inputmode="decimal" autocomplete="off" placeholder="0.00" value="" aria-label="Price, VAT included"></td>
                                <td><input type="text" class="stm-in-cat" maxlength="100" list="stm-cats" value="<?php echo $h($r['category']); ?>" aria-label="Category"></td>
                                <td class="num stm-cost"></td>
                                <td class="num stm-profit"></td>
                                <td class="stm-status"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <datalist id="stm-cats">
                <?php foreach ($built['cats'] as $c): ?>
                    <option value="<?php echo $h($c); ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <?php endif; ?>
        </div>

        <?php if ($built['rows']): ?>
        <div class="stm-bar" id="stm-bar">
            <span class="stm-bar__count" id="stm-ready">Nothing ticked yet.</span>
            <button type="button" class="stm-btn stm-btn--primary" id="stm-save" disabled><i class="fas fa-plus"></i> <span id="stm-save-text">Add to the menu</span></button>
        </div>
        <?php endif; ?>
        <script type="application/json" id="stm-data"><?php echo json_encode(['rows' => $built['rows'], 'currency' => $currency_symbol, 'csrf' => $csrf_token], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
        <?php endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
    <script src="js/stock-to-menu.js?v=<?php echo @filemtime(__DIR__ . '/js/stock-to-menu.js'); ?>"></script>
    <script>if (window.RHStockToMenu) { window.RHStockToMenu.init(); }</script>
</body>

</html>
