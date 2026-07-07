<?php

/**
 * Gym Package Management — Admin Panel
 * Card-based layout with modal editing, icon-toolbar, and Facebook sharing.
 */
require_once 'admin-init.php';
/** @var array  $user */
/** @var string $csrf_token */
$user       = $user       ?? ['id' => 0, 'username' => '', 'role' => 'guest', 'full_name' => ''];
$csrf_token = $csrf_token ?? generateCsrfToken();
$site_name  = $site_name  ?? getSetting('site_name', 'Hotel');

require_once '../includes/facebook-functions.php';
require_once __DIR__ . '/includes/gym-analytics-lib.php'; // gymDurationLabelFromDays()
$fb_gym_posting_on = isFacebookPostingEnabled()
    && getSetting('facebook_gym_enabled', '1') === '1';

$message = '';
$error   = '';
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security token invalid — refresh the page.');
        }
        $action = $_POST['action'] ?? '';

        // Structured duration: days drive membership expiry; label is display text.
        // When the label is left blank we auto-derive a friendly one from the days.
        $gm_pkg_days = ($_POST['duration_days'] ?? '') !== '' ? max(0, (int)$_POST['duration_days']) : null;
        $gm_pkg_comp = isset($_POST['is_complimentary']) ? 1 : 0;
        $gm_pkg_label = trim($_POST['duration_label'] ?? '');
        if ($gm_pkg_label === '' && $gm_pkg_days !== null && $gm_pkg_days > 0) {
            $gm_pkg_label = gymDurationLabelFromDays($gm_pkg_days);
        }
        // Complimentary packages are always free regardless of the price field.
        $gm_pkg_price = $gm_pkg_comp ? 0.0 : (float)($_POST['price'] ?? 0);

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO gym_packages
                    (name, icon_class, includes_text, duration_label, duration_days, price, is_complimentary, currency_code,
                     cta_text, cta_link, is_featured, is_active, display_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                trim($_POST['name']),
                trim($_POST['icon_class'] ?? 'fas fa-leaf'),
                trim($_POST['includes_text'] ?? ''),
                $gm_pkg_label,
                $gm_pkg_days,
                $gm_pkg_price,
                $gm_pkg_comp,
                trim($_POST['currency_code'] ?? 'MWK'),
                trim($_POST['cta_text'] ?? 'Book Package'),
                trim($_POST['cta_link'] ?? '#book'),
                isset($_POST['is_featured']) ? 1 : 0,
                isset($_POST['is_active']) ? 1 : 0,
                (int)($_POST['display_order'] ?? 0),
            ]);
            $message = 'Gym package added successfully!';
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message]);
                exit;
            }
        }

        if ($action === 'update') {
            $stmt = $pdo->prepare("
                UPDATE gym_packages
                SET name = ?, icon_class = ?, includes_text = ?, duration_label = ?, duration_days = ?,
                    price = ?, is_complimentary = ?, currency_code = ?, cta_text = ?, cta_link = ?,
                    is_featured = ?, is_active = ?, display_order = ?
                WHERE id = ?
            ");
            $stmt->execute([
                trim($_POST['name']),
                trim($_POST['icon_class'] ?? 'fas fa-leaf'),
                trim($_POST['includes_text'] ?? ''),
                $gm_pkg_label,
                $gm_pkg_days,
                $gm_pkg_price,
                $gm_pkg_comp,
                trim($_POST['currency_code'] ?? 'MWK'),
                trim($_POST['cta_text'] ?? 'Book Package'),
                trim($_POST['cta_link'] ?? '#book'),
                isset($_POST['is_featured']) ? 1 : 0,
                isset($_POST['is_active']) ? 1 : 0,
                (int)($_POST['display_order'] ?? 0),
                (int)$_POST['id'],
            ]);
            $message = 'Gym package updated successfully!';
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message]);
                exit;
            }
        }

        if ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM gym_packages WHERE id = ?");
            $stmt->execute([(int)$_POST['id']]);
            $message = 'Package deleted.';
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
        }

        if ($action === 'toggle_active') {
            $stmt = $pdo->prepare("UPDATE gym_packages SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([(int)$_POST['id']]);
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
        }

        if ($action === 'toggle_featured') {
            $stmt = $pdo->prepare("UPDATE gym_packages SET is_featured = NOT is_featured WHERE id = ?");
            $stmt->execute([(int)$_POST['id']]);
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
        }
    } catch (PDOException $e) {
        error_log('Gym management DB error: ' . $e->getMessage());
        $error = 'Database error — please try again.';
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    }
    if (!$is_ajax) {
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
}

// ── Fetch packages ───────────────────────────────────────────────────────────
try {
    $stmt = $pdo->query("
        SELECT id, name, icon_class, includes_text, duration_label, duration_days, price, is_complimentary,
               currency_code, cta_text, cta_link, is_featured, is_active, display_order
        FROM gym_packages
        ORDER BY display_order ASC, name ASC
    ");
    $gym_packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $gym_packages = [];
    $error = 'Error fetching gym packages: ' . $e->getMessage();
}

$base_url = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$currency = getSetting('currency_symbol', 'MWK');
$fb_page_name = getSetting('facebook_page_name', $site_name);
$gym_css_version = (string)@filemtime(__DIR__ . '/css/gym-management.css');
if ($gym_css_version === '' || $gym_css_version === '0') {
    $gym_css_version = (string)time();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gym Package Management — Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/gym-management.css?v=<?php echo urlencode($gym_css_version); ?>">
    <style>
        /* Final page-scoped fallback so gym cards keep standardized icon sizing. */
        #rh-admin-page .gym-card-icon-area {
            height: 132px !important;
            gap: 8px !important;
        }

        #rh-admin-page .gym-card-icon-area>i {
            font-size: 34px !important;
            line-height: 1 !important;
        }

        #rh-admin-page .gym-card-featured-badge i {
            font-size: 10px !important;
            line-height: 1 !important;
            opacity: 1 !important;
        }

        #rh-admin-page .gmc-icon-btn {
            width: 30px !important;
            height: 30px !important;
            min-width: 30px !important;
            min-height: 30px !important;
            font-size: 11px !important;
            line-height: 1 !important;
            padding: 0 !important;
        }
    </style>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="admin-content">
        <div class="admin-container">

            <!-- Page header -->
            <div class="page-header-row" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
                <h1 class="page-title"><i class="fas fa-dumbbell" style="color:var(--gold,#8B7355);margin-right:8px;"></i>Gym Package Management</h1>
                <div style="display:flex;gap:10px;align-items:center;">
                    <a href="gym-inquiries.php" class="btn btn-secondary" style="font-size:13px;">
                        <i class="fas fa-inbox"></i> View Inquiries
                    </a>
                    <button type="button" class="btn btn-primary" onclick="openAddGymPackageModal()">
                        <i class="fas fa-plus"></i> Add Package
                    </button>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success" style="margin-bottom:16px;"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger" style="margin-bottom:16px;"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (empty($gym_packages)): ?>
                <div class="empty-state">
                    <i class="fas fa-dumbbell"></i>
                    <p>No gym packages yet. Click <strong>Add Package</strong> to create your first one.</p>
                </div>
            <?php else: ?>

                <!-- Package cards grid -->
                <div class="gym-cards-grid">
                    <?php foreach ($gym_packages as $pkg): ?>
                        <?php
                        $isActive   = (bool)$pkg['is_active'];
                        $isFeatured = (bool)$pkg['is_featured'];
                        $pkgId      = (int)$pkg['id'];
                        $iconClass  = htmlspecialchars($pkg['icon_class'] ?? 'fas fa-leaf', ENT_QUOTES, 'UTF-8');
                        $pkgName    = htmlspecialchars($pkg['name'], ENT_QUOTES, 'UTF-8');
                        $includes   = htmlspecialchars($pkg['includes_text'] ?? '', ENT_QUOTES, 'UTF-8');
                        $priceNum   = number_format((float)$pkg['price']);
                        $currCode   = htmlspecialchars($pkg['currency_code'] ?? 'MWK', ENT_QUOTES, 'UTF-8');
                        $duration   = htmlspecialchars($pkg['duration_label'] ?? '', ENT_QUOTES, 'UTF-8');
                        $pkgJson    = json_encode([
                            'id'            => $pkgId,
                            'name'          => $pkg['name'],
                            'icon_class'    => $pkg['icon_class'] ?? 'fas fa-leaf',
                            'includes_text' => $pkg['includes_text'] ?? '',
                            'duration_label' => $pkg['duration_label'] ?? '',
                            'duration_days' => isset($pkg['duration_days']) && $pkg['duration_days'] !== null ? (int)$pkg['duration_days'] : '',
                            'is_complimentary' => (int)($pkg['is_complimentary'] ?? 0),
                            'price'         => (float)$pkg['price'],
                            'currency_code' => $pkg['currency_code'] ?? 'MWK',
                            'cta_text'      => $pkg['cta_text'] ?? 'Book Package',
                            'cta_link'      => $pkg['cta_link'] ?? '#book',
                            'is_featured'   => (int)$pkg['is_featured'],
                            'is_active'     => (int)$pkg['is_active'],
                            'display_order' => (int)$pkg['display_order'],
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                        ?>
                        <div class="gym-card" id="gym-card-<?php echo $pkgId; ?>">

                            <!-- Icon hero area -->
                            <div class="gym-card-icon-area">
                                <i class="<?php echo $iconClass; ?>"></i>
                                <?php if ($isFeatured): ?>
                                    <span class="gym-card-featured-badge"><i class="fas fa-star"></i> Featured</span>
                                <?php endif; ?>
                                <span class="gym-card-order-badge">#<?php echo (int)$pkg['display_order']; ?></span>
                            </div>

                            <!-- Card body -->
                            <div class="gym-card-body">
                                <h3 class="gym-card-title"><?php echo $pkgName; ?></h3>

                                <div class="gym-card-price">
                                    <?php echo $currCode; ?> <?php echo $priceNum; ?>
                                    <?php if ($duration): ?><small> / <?php echo $duration; ?></small><?php endif; ?>
                                </div>

                                <?php if ($duration): ?>
                                    <div class="gym-card-duration"><i class="fas fa-clock"></i> <?php echo $duration; ?></div>
                                <?php endif; ?>

                                <?php if ($includes): ?>
                                    <div class="gym-card-includes">
                                        <?php
                                        $lines = array_filter(array_map('trim', explode("\n", $pkg['includes_text'] ?? '')));
                                        $bullets = array_slice($lines, 0, 4);
                                        foreach ($bullets as $b) {
                                            echo '<span style="display:block;"><i class="fas fa-check" style="color:var(--gold,#8B7355);font-size:10px;margin-right:5px;"></i>'
                                                . htmlspecialchars($b, ENT_QUOTES, 'UTF-8') . '</span>';
                                        }
                                        if (count($lines) > 4) {
                                            echo '<span style="color:#9ca3af;font-size:11px;">+ ' . (count($lines) - 4) . ' more…</span>';
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>

                                <div class="gym-card-meta">
                                    <span class="gym-badge <?php echo $isActive ? 'active' : 'inactive'; ?>">
                                        <i class="fas fa-circle" style="font-size:7px;"></i>
                                        <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>

                                <!-- Card actions -->
                                <div class="gym-card-actions">
                                    <div class="gmc-toolbar">
                                        <!-- Active toggle -->
                                        <button type="button"
                                            class="gmc-icon-btn <?php echo $isActive ? 'gmc-icon-btn--active-on' : 'gmc-icon-btn--active-off'; ?>"
                                            title="<?php echo $isActive ? 'Deactivate' : 'Activate'; ?>"
                                            onclick="gmcToggleActive(<?php echo $pkgId; ?>, this)">
                                            <i class="fas <?php echo $isActive ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
                                        </button>

                                        <!-- Featured toggle -->
                                        <button type="button"
                                            class="gmc-icon-btn <?php echo $isFeatured ? 'gmc-icon-btn--featured-on' : ''; ?>"
                                            title="<?php echo $isFeatured ? 'Unfeature' : 'Mark as featured'; ?>"
                                            onclick="gmcToggleFeatured(<?php echo $pkgId; ?>, this)">
                                            <i class="fas fa-star"></i>
                                        </button>

                                        <div class="gmc-toolbar-sep"></div>

                                        <?php if ($fb_gym_posting_on): ?>
                                            <!-- Share on Facebook -->
                                            <button type="button"
                                                class="gmc-icon-btn gmc-icon-btn--facebook"
                                                title="Share on Facebook"
                                                onclick='openFbGymModal(<?php echo $pkgJson; ?>)'>
                                                <i class="fab fa-facebook-f"></i>
                                            </button>
                                        <?php endif; ?>

                                        <div class="gmc-spacer"></div>

                                        <!-- Delete -->
                                        <button type="button"
                                            class="gmc-icon-btn gmc-icon-btn--danger"
                                            title="Delete package"
                                            onclick="gmcDeletePackage(<?php echo $pkgId; ?>, '<?php echo addslashes($pkg['name']); ?>')">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>

                                    <!-- Edit CTA -->
                                    <button type="button" class="gmc-edit-btn"
                                        onclick='openEditGymPackageModal(<?php echo $pkgJson; ?>)'>
                                        <i class="fas fa-pencil-alt"></i> Edit Package
                                    </button>
                                </div>
                            </div>

                        </div><!-- .gym-card -->
                    <?php endforeach; ?>
                </div><!-- .gym-cards-grid -->

            <?php endif; ?>

            <!-- Share All Gym Packages banner -->
            <?php if ($fb_gym_posting_on && !empty($gym_packages)): ?>
                <div class="fb-gym-all-banner">
                    <div class="fb-gym-all-banner__icon"><i class="fab fa-facebook-f"></i></div>
                    <div class="fb-gym-all-banner__text">
                        <div class="fb-gym-all-banner__title">Share Our Wellness Packages on Facebook</div>
                        <div class="fb-gym-all-banner__sub">Select packages to include and post a promotional update to your Facebook Page.</div>
                    </div>
                    <button type="button" class="fb-gym-all-btn" onclick="openFbAllGymModal()">
                        <i class="fab fa-facebook-f"></i> Share All Packages
                    </button>
                </div>
            <?php endif; ?>

        </div><!-- .admin-container -->
    </div><!-- .admin-content -->

    <?php require_once 'includes/admin-footer.php'; ?>

    <!-- ── Add Package Modal ────────────────────────────────────────────────────── -->
    <div class="modal-overlay" id="addGymPackageModal" style="display:none;" onclick="if(event.target===this)closeAddGymPackageModal()">
        <div class="modal-content" style="max-width:620px;">
            <div class="modal-header">
                <h3><i class="fas fa-plus" style="color:var(--gold,#8B7355);margin-right:8px;"></i>Add Gym Package</h3>
                <button class="modal-close" type="button" onclick="closeAddGymPackageModal()">&times;</button>
            </div>
            <form id="addGymPackageForm" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label class="form-label">Package Name *</label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. Rejuvenation Retreat">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Icon Class</label>
                            <input type="text" name="icon_class" class="form-control" value="fas fa-leaf" placeholder="fas fa-dumbbell">
                            <small class="form-text text-muted">Font Awesome 6 class, e.g. <code>fas fa-star</code></small>
                        </div>
                    </div>
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label class="form-label">Price *</label>
                            <input type="number" name="price" class="form-control" min="0" step="0.01" required placeholder="0.00" data-currency="MWK" data-gym-price-input>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Currency Code</label>
                            <input type="text" name="currency_code" class="form-control" value="MWK" maxlength="10" data-gym-currency-input>
                        </div>
                    </div>
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label class="form-label">Duration (days)</label>
                            <input type="number" name="duration_days" class="form-control" min="0" step="1" placeholder="e.g. 30 monthly, 365 yearly" list="gymDurationPresets">
                            <datalist id="gymDurationPresets">
                                <option value="1">1 Day pass</option>
                                <option value="7">1 Week</option>
                                <option value="30">Monthly</option>
                                <option value="90">Quarterly</option>
                                <option value="180">6 Months</option>
                                <option value="365">Yearly</option>
                            </datalist>
                            <small class="form-text text-muted">Drives membership expiry. Leave blank / 0 for open-ended.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Duration Label <small>(optional — auto-set from days)</small></label>
                            <input type="text" name="duration_label" class="form-control" placeholder="Auto: Monthly, Yearly…">
                        </div>
                    </div>
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label class="form-label" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                                <input type="checkbox" name="is_complimentary" value="1"> Complimentary (free — e.g. hotel guest)
                            </label>
                            <small class="form-text text-muted">Free packages ignore the price field and record no fee.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Display Order</label>
                            <input type="number" name="display_order" class="form-control" value="0" min="0">
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label class="form-label">What's Included <small>(one item per line)</small></label>
                        <textarea name="includes_text" class="form-control" rows="5" placeholder="Personal training sessions&#10;Spa treatments&#10;Nutrition consultation"></textarea>
                    </div>
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label class="form-label">CTA Button Text</label>
                            <input type="text" name="cta_text" class="form-control" value="Book Package">
                        </div>
                        <div class="form-group">
                            <label class="form-label">CTA Link</label>
                            <input type="text" name="cta_link" class="form-control" value="#book">
                        </div>
                    </div>
                    <div style="display:flex;gap:20px;">
                        <label class="form-label" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" name="is_active" value="1" checked> Active
                        </label>
                        <label class="form-label" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" name="is_featured" value="1"> Featured
                        </label>
                    </div>
                    <div class="admin-modal-feedback" id="addGymFeedback"></div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Package</button>
                    <button type="button" class="btn btn-secondary" onclick="closeAddGymPackageModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Edit Package Modal ───────────────────────────────────────────────────── -->
    <div class="modal-overlay" id="editGymPackageModal" style="display:none;" onclick="if(event.target===this)closeEditGymPackageModal()">
        <div class="modal-content" style="max-width:620px;">
            <div class="modal-header">
                <h3><i class="fas fa-pencil-alt" style="color:var(--gold,#8B7355);margin-right:8px;"></i>Edit Gym Package</h3>
                <button class="modal-close" type="button" onclick="closeEditGymPackageModal()">&times;</button>
            </div>
            <form id="editGymPackageForm" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="editGymId">
                <div class="modal-body">
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label class="form-label">Package Name *</label>
                            <input type="text" name="name" id="editGymName" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Icon Class</label>
                            <input type="text" name="icon_class" id="editGymIconClass" class="form-control">
                            <small class="form-text text-muted">Font Awesome 6 class, e.g. <code>fas fa-star</code></small>
                        </div>
                    </div>
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label class="form-label">Price *</label>
                            <input type="number" name="price" id="editGymPrice" class="form-control" min="0" step="0.01" required data-currency="MWK" data-gym-price-input>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Currency Code</label>
                            <input type="text" name="currency_code" id="editGymCurrency" class="form-control" maxlength="10" data-gym-currency-input>
                        </div>
                    </div>
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label class="form-label">Duration (days)</label>
                            <input type="number" name="duration_days" id="editGymDurationDays" class="form-control" min="0" step="1" list="gymDurationPresets">
                            <small class="form-text text-muted">Drives membership expiry. Blank / 0 = open-ended.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Duration Label <small>(optional)</small></label>
                            <input type="text" name="duration_label" id="editGymDuration" class="form-control">
                        </div>
                    </div>
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label class="form-label" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                                <input type="checkbox" name="is_complimentary" id="editGymComplimentary" value="1"> Complimentary (free — e.g. hotel guest)
                            </label>
                            <small class="form-text text-muted">Free packages ignore the price field.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Display Order</label>
                            <input type="number" name="display_order" id="editGymOrder" class="form-control" min="0">
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label class="form-label">What's Included <small>(one item per line)</small></label>
                        <textarea name="includes_text" id="editGymIncludes" class="form-control" rows="5"></textarea>
                    </div>
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label class="form-label">CTA Button Text</label>
                            <input type="text" name="cta_text" id="editGymCtaText" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">CTA Link</label>
                            <input type="text" name="cta_link" id="editGymCtaLink" class="form-control">
                        </div>
                    </div>
                    <div style="display:flex;gap:20px;">
                        <label class="form-label" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" name="is_active" id="editGymActive" value="1"> Active
                        </label>
                        <label class="form-label" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" name="is_featured" id="editGymFeatured" value="1"> Featured
                        </label>
                    </div>
                    <div class="admin-modal-feedback" id="editGymFeedback"></div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditGymPackageModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($fb_gym_posting_on): ?>
        <!-- ── Individual Package FB Share Modal ─────────────────────────────────── -->
        <div class="modal-overlay" id="fbGymModal" style="display:none;" onclick="if(event.target===this)closeFbGymModal()">
            <div class="modal-content" style="max-width:760px;">
                <div class="modal-header" style="border-top:4px solid #1877F2;">
                    <h3 id="fbGymModalTitle" style="color:#1877F2;"><i class="fab fa-facebook-f"></i> Share Package on Facebook</h3>
                    <button class="modal-close" type="button" onclick="closeFbGymModal()">&times;</button>
                </div>
                <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <!-- Left: compose -->
                    <div>
                        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Caption</label>
                        <textarea id="fbGymCaption" class="form-control" rows="9" style="font-size:13px;line-height:1.6;resize:vertical;" oninput="_fbGymUpdatePreview()"></textarea>
                        <div style="font-size:11px;color:#6b7280;margin-top:4px;text-align:right;"><span id="fbGymCharCount">0</span> characters</div>
                        <div class="admin-modal-feedback" id="fbGymFeedback"></div>
                    </div>
                    <!-- Right: live preview -->
                    <div>
                        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Live Preview</label>
                        <div style="border:1.5px solid #dde3f0;border-radius:10px;overflow:hidden;background:#fff;font-family:Helvetica,Arial,sans-serif;font-size:13px;">
                            <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid #f0f2f5;">
                                <div style="width:38px;height:38px;border-radius:50%;background:#1877F2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fab fa-facebook-f" style="color:#fff;font-size:16px;"></i>
                                </div>
                                <div>
                                    <div id="fbGymPreviewPageName" style="font-weight:700;font-size:13px;color:#050505;"></div>
                                    <div style="font-size:11px;color:#65676b;">Just now &middot; <i class="fas fa-globe-africa"></i></div>
                                </div>
                            </div>
                            <div id="fbGymPreviewText" style="padding:10px 12px;white-space:pre-wrap;word-break:break-word;color:#050505;font-size:13px;line-height:1.5;min-height:60px;"></div>
                            <div id="fbGymPreviewIconArea" style="background:linear-gradient(135deg,#f0f4ff,#e8f5e9);height:100px;display:flex;align-items:center;justify-content:center;font-size:40px;color:#8B7355;"></div>
                            <div style="padding:8px 12px;border-top:1px solid #f0f2f5;display:flex;gap:16px;">
                                <span style="color:#65676b;font-size:12px;"><i class="far fa-thumbs-up"></i> Like</span>
                                <span style="color:#65676b;font-size:12px;"><i class="far fa-comment"></i> Comment</span>
                                <span style="color:#65676b;font-size:12px;"><i class="fas fa-share"></i> Share</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" id="fbGymSubmitBtn" class="btn" style="background:#1877F2;color:#fff;border-color:#1877F2;">
                        <i class="fab fa-facebook-f"></i> Post to Facebook Page
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeFbGymModal()">Cancel</button>
                </div>
            </div>
        </div>

        <!-- ── Share All Gym Packages Modal ──────────────────────────────────────── -->
        <div class="modal-overlay" id="fbAllGymModal" style="display:none;" onclick="if(event.target===this)closeFbAllGymModal()">
            <div class="modal-content" style="max-width:820px;">
                <div class="modal-header" style="border-top:4px solid #1877F2;">
                    <h3 style="color:#1877F2;"><i class="fab fa-facebook-f"></i> Share All Wellness Packages</h3>
                    <button class="modal-close" type="button" onclick="closeFbAllGymModal()">&times;</button>
                </div>
                <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <!-- Left: package selection + caption -->
                    <div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <label style="font-weight:600;font-size:13px;">Select Packages</label>
                            <button type="button" onclick="fgAllSelectAll()" class="btn btn-secondary" style="font-size:11px;padding:4px 10px;">Select All</button>
                        </div>
                        <div class="fb-gym-select-list" id="fbGymAllSelectList"></div>
                        <label style="font-weight:600;font-size:13px;display:block;margin:14px 0 6px;">Caption</label>
                        <textarea id="fbGymAllCaption" class="form-control" rows="8" style="font-size:13px;line-height:1.6;resize:vertical;" oninput="fgAllUpdatePreview()"></textarea>
                        <div style="font-size:11px;color:#6b7280;margin-top:4px;text-align:right;"><span id="fbGymAllCharCount">0</span> characters</div>
                        <div class="admin-modal-feedback" id="fbGymAllFeedback"></div>
                    </div>
                    <!-- Right: live preview -->
                    <div>
                        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Live Preview</label>
                        <div style="border:1.5px solid #dde3f0;border-radius:10px;overflow:hidden;background:#fff;font-family:Helvetica,Arial,sans-serif;font-size:13px;">
                            <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid #f0f2f5;">
                                <div style="width:38px;height:38px;border-radius:50%;background:#1877F2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fab fa-facebook-f" style="color:#fff;font-size:16px;"></i>
                                </div>
                                <div>
                                    <div id="fbGymAllPreviewPageName" style="font-weight:700;font-size:13px;color:#050505;"></div>
                                    <div style="font-size:11px;color:#65676b;">Just now &middot; <i class="fas fa-globe-africa"></i></div>
                                </div>
                            </div>
                            <div id="fbGymAllPreviewText" style="padding:10px 12px;white-space:pre-wrap;word-break:break-word;color:#050505;font-size:13px;line-height:1.5;min-height:60px;"></div>
                            <div style="background:linear-gradient(135deg,#f0f4ff,#e8f5e9);height:80px;display:flex;align-items:center;justify-content:center;font-size:32px;color:#8B7355;">
                                <i class="fas fa-dumbbell"></i>
                            </div>
                            <div style="padding:8px 12px;border-top:1px solid #f0f2f5;display:flex;gap:16px;">
                                <span style="color:#65676b;font-size:12px;"><i class="far fa-thumbs-up"></i> Like</span>
                                <span style="color:#65676b;font-size:12px;"><i class="far fa-comment"></i> Comment</span>
                                <span style="color:#65676b;font-size:12px;"><i class="fas fa-share"></i> Share</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" id="fbGymAllSubmitBtn" class="btn" style="background:#1877F2;color:#fff;border-color:#1877F2;">
                        <i class="fab fa-facebook-f"></i> Post All to Facebook
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeFbAllGymModal()">Cancel</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        // ── Package data injected from PHP ──────────────────────────────────────────
        window._gymPackages = <?php echo json_encode(array_values($gym_packages), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        <?php if ($fb_gym_posting_on): ?>
            window._fbGymDefaults = {
                baseUrl: <?php echo json_encode($base_url); ?>,
                currency: <?php echo json_encode($currency); ?>,
                hashtags: <?php echo json_encode(getSetting('facebook_default_hashtags', '#hotel #wellness #gym')); ?>,
                pageName: <?php echo json_encode($fb_page_name); ?>
            };

            // ── Current gym package being shared ──
            var _fbGymCurrentId = 0;
            var _fbGymCurrentIcon = 'fas fa-leaf';

            // Build default caption for a single gym package
            function _fbGymBuildCaption(pkg) {
                var d = window._fbGymDefaults || {};
                var lines = ['\ud83d\udcaa ' + pkg.name];
                if (pkg.price) {
                    lines.push(d.currency + ' ' + Number(pkg.price).toLocaleString() + (pkg.duration_label ? ' / ' + pkg.duration_label : ''));
                }
                if (pkg.includes_text) {
                    var items = pkg.includes_text.split('\n').map(function(s) {
                        return s.trim();
                    }).filter(Boolean);
                    if (items.length) {
                        lines.push('');
                        lines.push('Includes:');
                        items.slice(0, 5).forEach(function(b) {
                            lines.push('\u2714 ' + b);
                        });
                    }
                }
                lines.push('');
                lines.push('\ud83c\udfe8 ' + (d.baseUrl ? d.baseUrl + '/gym.php' : ''));
                lines.push('');
                lines.push(d.hashtags || '');
                return lines.join('\n').trim();
            }

            // Update live preview for single gym modal
            function _fbGymUpdatePreview() {
                var caption = (document.getElementById('fbGymCaption').value || '');
                document.getElementById('fbGymPreviewText').textContent = caption;
                document.getElementById('fbGymCharCount').textContent = caption.length;
                document.getElementById('fbGymPreviewIconArea').innerHTML = '<i class="' + _fbGymCurrentIcon + '" style="font-size:40px;color:#8B7355;"></i>';
            }

            window.openFbGymModal = function(pkg) {
                _fbGymCurrentId = pkg.id;
                _fbGymCurrentIcon = pkg.icon_class || 'fas fa-leaf';
                var d = window._fbGymDefaults || {};
                document.getElementById('fbGymModalTitle').innerHTML = '<i class="fab fa-facebook-f"></i> Share &ldquo;' + pkg.name.replace(/</g, '&lt;') + '&rdquo; on Facebook';
                document.getElementById('fbGymPreviewPageName').textContent = d.pageName || '';
                document.getElementById('fbGymCaption').value = _fbGymBuildCaption(pkg);
                var fb = document.getElementById('fbGymFeedback');
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
                _fbGymUpdatePreview();
                document.getElementById('fbGymModal').style.display = 'flex';
            };

            window.closeFbGymModal = function() {
                var m = document.getElementById('fbGymModal');
                if (m) m.style.display = 'none';
            };

            document.addEventListener('DOMContentLoaded', function() {
                // Single gym package submit
                var submitBtn = document.getElementById('fbGymSubmitBtn');
                if (submitBtn) {
                    submitBtn.addEventListener('click', function() {
                        var caption = (document.getElementById('fbGymCaption').value || '').trim();
                        var fb = document.getElementById('fbGymFeedback');
                        if (!caption) {
                            fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                            fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a caption.';
                            return;
                        }
                        submitBtn.disabled = true;
                        var origHtml = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting\u2026';
                        fb.className = 'admin-modal-feedback';
                        fb.innerHTML = '';

                        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute('content') || '';
                        var fd = new FormData();
                        fd.append('csrf_token', csrf);
                        fd.append('type', 'gym_package');
                        fd.append('id', String(_fbGymCurrentId));
                        fd.append('message', caption);

                        fetch('api/facebook-post.php', {
                                method: 'POST',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                body: fd
                            })
                            .then(function(r) {
                                return r.json();
                            })
                            .then(function(data) {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = origHtml;
                                if (data.success) {
                                    fb.className = 'admin-modal-feedback admin-modal-feedback--success visible';
                                    var lnk = data.post_url ? ' <a href="' + data.post_url + '" target="_blank" rel="noopener">View post</a>' : '';
                                    fb.innerHTML = '<i class="fas fa-check-circle"></i> Posted to Facebook!' + lnk;
                                } else {
                                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Unknown error.');
                                }
                            })
                            .catch(function() {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = origHtml;
                                fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error \u2014 please try again.';
                            });
                    });
                }
            });

            // ── Share All Gym Packages ─────────────────────────────────────────────────
            function fgAllBuildCaption(packages) {
                var d = window._fbGymDefaults || {};
                if (!packages.length) return '';
                var lines = ['\ud83c\udfe8 Wellness Packages at ' + (d.pageName || 'our hotel')];
                lines.push('');
                lines.push('Elevate your wellbeing with our exclusive packages:');
                lines.push('');
                packages.forEach(function(p) {
                    var priceStr = d.currency + ' ' + Number(p.price).toLocaleString() + (p.duration_label ? ' / ' + p.duration_label : '');
                    lines.push('\ud83d\udcaa ' + p.name + ' \u2014 ' + priceStr);
                });
                lines.push('');
                lines.push('\u27a1 ' + (d.baseUrl ? d.baseUrl + '/gym.php' : ''));
                lines.push('');
                lines.push(d.hashtags || '');
                return lines.join('\n').trim();
            }

            function fgAllUpdatePreview() {
                var caption = (document.getElementById('fbGymAllCaption').value || '');
                document.getElementById('fbGymAllPreviewText').textContent = caption;
                document.getElementById('fbGymAllCharCount').textContent = caption.length;
            }

            function fgAllSelectAll() {
                var checkboxes = document.querySelectorAll('#fbGymAllSelectList input[type="checkbox"]');
                var allChecked = Array.from(checkboxes).every(function(c) {
                    return c.checked;
                });
                checkboxes.forEach(function(c) {
                    c.checked = !allChecked;
                    var item = c.closest('.fb-gym-select-item');
                    if (item) item.classList.toggle('checked', c.checked);
                });
                _fgAllRebuildCaption();
            }

            function _fgAllRebuildCaption() {
                var selected = [];
                document.querySelectorAll('#fbGymAllSelectList input[type="checkbox"]:checked').forEach(function(c) {
                    var id = parseInt(c.value, 10);
                    var pkg = (window._gymPackages || []).find(function(p) {
                        return p.id === id;
                    });
                    if (pkg) selected.push(pkg);
                });
                document.getElementById('fbGymAllCaption').value = fgAllBuildCaption(selected);
                fgAllUpdatePreview();
            }

            window.openFbAllGymModal = function() {
                var d = window._fbGymDefaults || {};
                document.getElementById('fbGymAllPreviewPageName').textContent = d.pageName || '';

                // Populate package list
                var list = document.getElementById('fbGymAllSelectList');
                list.innerHTML = '';
                (window._gymPackages || []).forEach(function(p) {
                    var item = document.createElement('label');
                    item.className = 'fb-gym-select-item checked';
                    item.innerHTML =
                        '<input type="checkbox" value="' + p.id + '" checked>' +
                        '<span class="fb-gym-select-icon"><i class="' + (p.icon_class || 'fas fa-leaf') + '"></i></span>' +
                        '<span class="fb-gym-select-info">' +
                        '<span class="fb-gym-select-name">' + p.name.replace(/</g, '&lt;') + '</span>' +
                        '<span class="fb-gym-select-price">' + (d.currency || 'MWK') + ' ' + Number(p.price).toLocaleString() + (p.duration_label ? ' / ' + p.duration_label : '') + '</span>' +
                        '</span>';
                    item.querySelector('input').addEventListener('change', function(e) {
                        item.classList.toggle('checked', e.target.checked);
                        _fgAllRebuildCaption();
                    });
                    list.appendChild(item);
                });

                _fgAllRebuildCaption();

                var fb = document.getElementById('fbGymAllFeedback');
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
                document.getElementById('fbAllGymModal').style.display = 'flex';
            };

            window.closeFbAllGymModal = function() {
                var m = document.getElementById('fbAllGymModal');
                if (m) m.style.display = 'none';
            };

            document.addEventListener('DOMContentLoaded', function() {
                var submitAllBtn = document.getElementById('fbGymAllSubmitBtn');
                if (!submitAllBtn) return;
                submitAllBtn.addEventListener('click', function() {
                    var ids = [];
                    document.querySelectorAll('#fbGymAllSelectList input[type="checkbox"]:checked').forEach(function(c) {
                        ids.push(parseInt(c.value, 10));
                    });
                    var caption = (document.getElementById('fbGymAllCaption').value || '').trim();
                    var fb = document.getElementById('fbGymAllFeedback');

                    if (!ids.length) {
                        fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Select at least one package.';
                        return;
                    }
                    if (!caption) {
                        fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a caption.';
                        return;
                    }

                    submitAllBtn.disabled = true;
                    var origHtml = submitAllBtn.innerHTML;
                    submitAllBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting\u2026';
                    fb.className = 'admin-modal-feedback';
                    fb.innerHTML = '';

                    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute('content') || '';
                    var fd = new FormData();
                    fd.append('csrf_token', csrf);
                    fd.append('type', 'gym_packages_all');
                    fd.append('message', caption);
                    ids.forEach(function(id) {
                        fd.append('ids[]', String(id));
                    });

                    fetch('api/facebook-post.php', {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: fd
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            submitAllBtn.disabled = false;
                            submitAllBtn.innerHTML = origHtml;
                            if (data.success) {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--success visible';
                                var lnk = data.post_url ? ' <a href="' + data.post_url + '" target="_blank" rel="noopener">View post</a>' : '';
                                fb.innerHTML = '<i class="fas fa-check-circle"></i> Posted to Facebook!' + lnk;
                            } else {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Unknown error.');
                            }
                        })
                        .catch(function() {
                            submitAllBtn.disabled = false;
                            submitAllBtn.innerHTML = origHtml;
                            fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                            fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error \u2014 please try again.';
                        });
                });
            });
        <?php endif; ?>

        // ── Add / Edit modal helpers ────────────────────────────────────────────────
        function openAddGymPackageModal() {
            document.getElementById('addGymPackageForm').reset();
            document.getElementById('addGymFeedback').className = 'admin-modal-feedback';
            document.getElementById('addGymFeedback').innerHTML = '';
            document.getElementById('addGymPackageModal').style.display = 'flex';
        }

        function closeAddGymPackageModal() {
            document.getElementById('addGymPackageModal').style.display = 'none';
        }

        function openEditGymPackageModal(pkg) {
            document.getElementById('editGymId').value = pkg.id;
            document.getElementById('editGymName').value = pkg.name || '';
            document.getElementById('editGymIconClass').value = pkg.icon_class || 'fas fa-leaf';
            document.getElementById('editGymPrice').value = pkg.price || 0;
            document.getElementById('editGymCurrency').value = pkg.currency_code || 'MWK';
            document.getElementById('editGymDurationDays').value = (pkg.duration_days === 0 || pkg.duration_days) ? pkg.duration_days : '';
            document.getElementById('editGymDuration').value = pkg.duration_label || '';
            document.getElementById('editGymComplimentary').checked = !!pkg.is_complimentary;
            document.getElementById('editGymOrder').value = pkg.display_order || 0;
            document.getElementById('editGymIncludes').value = pkg.includes_text || '';
            document.getElementById('editGymCtaText').value = pkg.cta_text || 'Book Package';
            document.getElementById('editGymCtaLink').value = pkg.cta_link || '#book';
            document.getElementById('editGymActive').checked = !!pkg.is_active;
            document.getElementById('editGymFeatured').checked = !!pkg.is_featured;
            document.getElementById('editGymFeedback').className = 'admin-modal-feedback';
            document.getElementById('editGymFeedback').innerHTML = '';
            document.getElementById('editGymPackageModal').style.display = 'flex';
        }

        function closeEditGymPackageModal() {
            document.getElementById('editGymPackageModal').style.display = 'none';
        }

        // ── AJAX form submissions ───────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            function handleGymForm(formId, feedbackId, closeFunc) {
                var form = document.getElementById(formId);
                if (!form) return;
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var fb = document.getElementById(feedbackId);
                    var btn = form.querySelector('[type="submit"]');
                    if (btn) {
                        btn.disabled = true;
                    }
                    var fd = new FormData(form);
                    fetch(window.location.pathname, {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: fd
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            if (btn) btn.disabled = false;
                            if (data.success) {
                                window.location.reload();
                            } else {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.message || 'Error saving.');
                            }
                        })
                        .catch(function() {
                            if (btn) btn.disabled = false;
                            fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                            fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error. Please try again.';
                        });
                });
            }
            handleGymForm('addGymPackageForm', 'addGymFeedback', closeAddGymPackageModal);
            handleGymForm('editGymPackageForm', 'editGymFeedback', closeEditGymPackageModal);
        });

        // ── Toggle active / featured / delete ─────────────────────────────────────
        function _gmcPost(payload, onSuccess) {
            var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute('content') || '';
            var fd = new FormData();
            fd.append('csrf_token', csrf);
            Object.keys(payload).forEach(function(k) {
                fd.append(k, payload[k]);
            });
            fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            }).then(function(r) {
                return r.json();
            }).then(function(d) {
                if (d.success) onSuccess();
            });
        }

        function gmcToggleActive(id, btn) {
            _gmcPost({
                action: 'toggle_active',
                id: id
            }, function() {
                window.location.reload();
            });
        }

        function gmcToggleFeatured(id, btn) {
            _gmcPost({
                action: 'toggle_featured',
                id: id
            }, function() {
                window.location.reload();
            });
        }

        function gmcDeletePackage(id, name) {
            if (!confirm('Delete "' + name + '"? This cannot be undone.')) return;
            _gmcPost({
                action: 'delete',
                id: id
            }, function() {
                window.location.reload();
            });
        }
    </script>

</body>

</html>

