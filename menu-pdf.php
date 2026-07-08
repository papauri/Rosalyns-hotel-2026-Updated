<?php

/**
 * Dynamic Restaurant Menu - Print-Ready HTML
 *
 * Dark, minimalist design inspired by modern tasting-card menus.
 * Pulls live data from food_menu and drink_menu tables.
 * Optimized for print-to-PDF and direct browser viewing.
 *
 * Category ordering is managed via admin panel in database settings:
 * - menu_food_categories_order
 * - menu_drink_categories_order
 * If no custom order is set, categories display in natural database order
 */
require_once 'config/database.php';
require_once 'config/base-url.php';
require_once 'includes/booking-functions.php';

// Module gate: the print menu belongs to the Restaurant module. When it's off,
// this page must not be reachable (consistent with restaurant.php). Redirect home.
if (function_exists('isRestaurantEnabled') && !isRestaurantEnabled()) {
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/'));
    exit;
}

function cleanText(mixed $value, string $fallback = ''): string
{
    $text = trim((string)$value);
    return $text !== '' ? $text : $fallback;
}

function fmtPrice(mixed $price, string $symbol): string
{
    return $symbol . ' ' . number_format((float)$price, 0, '.', ',');
}

// All settings from DB – zero hardcoded values
$currency_symbol = getSetting('currency_symbol');
$currency_code   = getSetting('currency_code')   ?: 'MWK';
$site_name       = getSetting('site_name')        ?: 'Hotel';
$site_tagline    = getSetting('restaurant_tagline', 'Fresh. Local. Inspired.');
$site_phone      = getSetting('phone_main', getSetting('phone', ''));
$site_email      = getSetting('email_restaurant', getSetting('email', ''));
$address_line1   = cleanText(getSetting('address_line1', ''), '');
$address_line2   = cleanText(getSetting('address_line2', ''), '');
$address_country = cleanText(getSetting('address_country', ''), '');

$address_parts = array_values(array_filter([$address_line1, $address_line2, $address_country], static function ($part) {
    return trim((string)$part) !== '';
}));
$site_address = implode(', ', $address_parts);

$menu_generated_date = date('j M Y');

// ── Fetch all menu items grouped by top-level category (food / drink) ──
$food_categories  = [];
$drink_categories = [];
try {
    $stmt = $pdo->query("
        SELECT id, category, item_name, description, price, is_featured
        FROM food_menu
        WHERE is_available = 1
        ORDER BY category ASC, display_order ASC, id ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $cat = cleanText($item['category'], 'Uncategorized');
        if (!isset($food_categories[$cat])) $food_categories[$cat] = [];
        $food_categories[$cat][] = $item;
    }
} catch (PDOException $e) {
    error_log("Menu PDF - food fetch error: " . $e->getMessage());
}
try {
    $stmt = $pdo->query("
        SELECT id, category, item_name, description, price, 0 AS is_featured
        FROM drink_menu
        WHERE is_available = 1
        ORDER BY category ASC, display_order ASC, id ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $cat = cleanText($item['category'], 'Drinks');
        if (!isset($drink_categories[$cat])) $drink_categories[$cat] = [];
        $drink_categories[$cat][] = $item;
    }
} catch (PDOException $e) {
    error_log("Menu PDF - drink fetch error: " . $e->getMessage());
}

function parseCategoryOrder(mixed $json): array
{
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map(static function ($value) {
        return is_string($value) ? trim($value) : '';
    }, $decoded), static function ($value) {
        return $value !== '';
    }));
}

// Get category order from database settings
// If no order is set, categories will appear in their natural order from database
$food_order_json = getSetting('menu_food_categories_order');
$food_order = parseCategoryOrder($food_order_json);

$drink_order_json = getSetting('menu_drink_categories_order');
$drink_order = parseCategoryOrder($drink_order_json);

// Sort helper - applies order if specified, otherwise keeps natural order
function sortedCategories(array $categories, array $order): array
{
    if (empty($order)) {
        return $categories;
    }
    $sorted = [];
    foreach ($order as $key) {
        if (isset($categories[$key])) $sorted[$key] = $categories[$key];
    }
    // append any remaining
    foreach ($categories as $key => $val) {
        if (!isset($sorted[$key])) $sorted[$key] = $val;
    }
    return $sorted;
}

$food_categories = sortedCategories($food_categories, $food_order);
$drink_categories = sortedCategories($drink_categories, $drink_order);

$chef_specials = [];
foreach ($food_categories as $items) {
    foreach ($items as $item) {
        if (!empty($item['is_featured'])) {
            $chef_specials[] = $item;
        }
    }
}
if (empty($chef_specials)) {
    foreach ($food_categories as $items) {
        foreach ($items as $item) {
            $chef_specials[] = $item;
            if (count($chef_specials) >= 4) {
                break 2;
            }
        }
    }
}

$food_left_categories = [];
$food_right_categories = [];
if (!empty($food_categories)) {
    $split_index = (int)ceil(count($food_categories) / 2);
    $food_left_categories = array_slice($food_categories, 0, $split_index, true);
    $food_right_categories = array_slice($food_categories, $split_index, null, true);
}

$drink_items_flat = [];
foreach ($drink_categories as $drink_category_name => $drink_items) {
    foreach ($drink_items as $drink_item) {
        $drink_items_flat[] = [
            'category' => $drink_category_name,
            'name' => cleanText($drink_item['item_name'], 'Unnamed Item'),
            'price' => $drink_item['price'] ?? 0,
        ];
    }
}

$drink_split = (int)ceil(count($drink_items_flat) / 2);
$drink_col_a = array_slice($drink_items_flat, 0, $drink_split);
$drink_col_b = array_slice($drink_items_flat, $drink_split);

$food_empty = empty($food_categories);
$drink_empty = empty($drink_categories);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($site_name); ?> — Menu</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/menu-pdf.css">
</head>

<body>

    <!-- Print / Back Bar -->
    <div class="print-bar no-print">
        <button class="btn-print" onclick="window.print()">Save as PDF</button>
        <a class="btn-back" href="restaurant.php">Back to Restaurant</a>
    </div>

    <div class="menu-page">
        <header class="menu-top">
            <div>
                <div class="hotel-name"><?php echo htmlspecialchars($site_name); ?></div>
                <div class="tagline"><?php echo htmlspecialchars($site_tagline); ?></div>
            </div>
            <div class="menu-date">Updated <?php echo htmlspecialchars($menu_generated_date); ?></div>
        </header>

        <div class="editorial-grid">
            <section class="col col-left">
                <div class="menu-wordmark" aria-hidden="true">
                    <span>ME</span>
                    <span>NU</span>
                </div>

                <?php if ($food_empty): ?>
                    <div class="menu-category">
                        <div class="category-title">Menu Unavailable</div>
                        <div class="item-desc">No food items are currently published. Please update menu items in Admin &gt; Menu Management.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($food_left_categories as $category => $items): ?>
                        <div class="menu-category">
                            <div class="section-divider">
                                <h2><?php echo htmlspecialchars($category); ?></h2>
                            </div>
                            <?php foreach ($items as $item): ?>
                                <?php $item_name = cleanText($item['item_name'], 'Unnamed Item'); ?>
                                <?php $item_desc = cleanText($item['description'], ''); ?>
                                <div class="menu-item">
                                    <span class="item-name"><?php echo htmlspecialchars($item_name); ?></span>
                                    <span class="item-dots"></span>
                                    <span class="item-price"><?php echo fmtPrice($item['price'], $currency_symbol); ?></span>
                                </div>
                                <?php if ($item_desc !== '' && strlen($item_desc) > 5): ?>
                                    <div class="item-desc"><?php echo htmlspecialchars($item_desc); ?></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <section class="col col-right">
                <?php foreach ($food_right_categories as $category => $items): ?>
                    <div class="menu-category">
                        <div class="section-divider">
                            <h2><?php echo htmlspecialchars($category); ?></h2>
                        </div>
                        <?php foreach ($items as $item): ?>
                            <?php $item_name = cleanText($item['item_name'], 'Unnamed Item'); ?>
                            <?php $item_desc = cleanText($item['description'], ''); ?>
                            <div class="menu-item">
                                <span class="item-name"><?php echo htmlspecialchars($item_name); ?></span>
                                <span class="item-dots"></span>
                                <span class="item-price"><?php echo fmtPrice($item['price'], $currency_symbol); ?></span>
                            </div>
                            <?php if ($item_desc !== '' && strlen($item_desc) > 5): ?>
                                <div class="item-desc"><?php echo htmlspecialchars($item_desc); ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($chef_specials)): ?>
                    <div class="specials-block">
                        <div class="specials-title">Chef’s Specials.</div>
                        <?php foreach ($chef_specials as $special): ?>
                            <div class="menu-item">
                                <span class="item-name"><?php echo htmlspecialchars(cleanText($special['item_name'], 'Unnamed Item')); ?></span>
                                <span class="item-dots"></span>
                                <span class="item-price"><?php echo fmtPrice($special['price'] ?? 0, $currency_symbol); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="drinks-block">
                    <div class="section-divider">
                        <h2>Drinks.</h2>
                    </div>
                    <?php if ($drink_empty): ?>
                        <div class="item-desc">No drink items are currently published. Please update menu items in Admin &gt; Menu Management.</div>
                    <?php else: ?>
                        <div class="drinks-grid">
                            <div>
                                <?php foreach ($drink_col_a as $drink): ?>
                                    <div class="menu-item drink-row">
                                        <span class="item-name"><?php echo htmlspecialchars($drink['name']); ?></span>
                                        <span class="item-dots"></span>
                                        <span class="item-price"><?php echo fmtPrice($drink['price'], $currency_symbol); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div>
                                <?php foreach ($drink_col_b as $drink): ?>
                                    <div class="menu-item drink-row">
                                        <span class="item-name"><?php echo htmlspecialchars($drink['name']); ?></span>
                                        <span class="item-dots"></span>
                                        <span class="item-price"><?php echo fmtPrice($drink['price'], $currency_symbol); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="menu-footer">
            <p>All prices are inclusive of applicable taxes.<br>
                Menu items are subject to availability.<br>
                Please inform your server of any dietary requirements or allergies.</p>
            <div class="currency-note">All prices in <?php echo htmlspecialchars($currency_code); ?></div>
            <?php if (!empty($site_phone) || !empty($site_email) || $site_address !== ''): ?>
                <div class="contact-line">
                    <?php if (!empty($site_phone)): ?><span><?php echo htmlspecialchars($site_phone); ?></span><?php endif; ?>
                    <?php if (!empty($site_phone) && (!empty($site_email) || $site_address !== '')): ?><span class="dot">•</span><?php endif; ?>
                    <?php if (!empty($site_email)): ?><span><?php echo htmlspecialchars($site_email); ?></span><?php endif; ?>
                    <?php if (!empty($site_email) && $site_address !== ''): ?><span class="dot">•</span><?php endif; ?>
                    <?php if ($site_address !== ''): ?><span><?php echo htmlspecialchars($site_address); ?></span><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

</body>

</html>
