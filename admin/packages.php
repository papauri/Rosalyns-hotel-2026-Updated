<?php

/**
 * Room Packages Management
 * Admin Panel — Revenue > Packages
 */
require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */

$message     = '';
$messageType = '';

/* ── Fetch all room types for scope selector ──────────────────────────── */
$allRooms = $pdo->query("SELECT id, name FROM rooms WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ── POST handlers ────────────────────────────────────────────────────── */
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
            exit;
        }
        $message = 'Invalid security token.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id               = !empty($_POST['pkg_id']) ? (int)$_POST['pkg_id'] : null;
            $name             = trim($_POST['name'] ?? '');
            $description      = trim($_POST['description'] ?? '');
            $shortDesc        = trim($_POST['short_description'] ?? '');
            $icon             = trim($_POST['icon'] ?? 'fas fa-gift');
            $priceType        = in_array($_POST['price_type'] ?? 'per_night', ['per_night', 'per_stay', 'per_person_per_night'])
                ? $_POST['price_type'] : 'per_night';
            $priceAmount      = max(0, (float)($_POST['price_amount'] ?? 0));
            $appliesTo        = in_array($_POST['applies_to'] ?? 'all', ['all', 'room_types']) ? $_POST['applies_to'] : 'all';
            $roomTypeIds      = ($appliesTo === 'room_types' && !empty($_POST['room_type_ids']))
                ? json_encode(array_values(array_unique(array_map('intval', (array)$_POST['room_type_ids']))))
                : null;
            $isFeatured       = empty($_POST['is_featured']) ? 0 : 1;
            $isActive         = empty($_POST['is_active'])   ? 0 : 1;
            $sortOrder        = max(0, (int)($_POST['sort_order'] ?? 0));

            // Build inclusions JSON from textarea (one per line)
            $inclusionsRaw = trim($_POST['inclusions'] ?? '');
            $inclusionsList = array_values(array_filter(array_map('trim', explode("\n", $inclusionsRaw))));
            $inclusionsJson = !empty($inclusionsList) ? json_encode($inclusionsList) : null;

            // Generate slug from name
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
            if (empty($slug)) {
                $slug = 'package-' . time();
            }
            // Ensure uniqueness
            $existCheck = $pdo->prepare("SELECT COUNT(*) FROM room_packages WHERE slug=? AND id != ?");
            $existCheck->execute([$slug, $id ?? 0]);
            if ((int)$existCheck->fetchColumn() > 0) {
                $slug .= '-' . random_int(100, 999);
            }

            if (empty($name)) {
                $message = 'Package name is required.';
                $messageType = 'error';
            } else {
                try {
                    if ($id) {
                        $stmt = $pdo->prepare("UPDATE room_packages SET
                            name=?, slug=?, description=?, short_description=?, icon=?,
                            price_type=?, price_amount=?, inclusions=?,
                            applies_to=?, room_type_ids=?,
                            is_featured=?, is_active=?, sort_order=?
                            WHERE id=?");
                        $stmt->execute([
                            $name,
                            $slug,
                            $description ?: null,
                            $shortDesc ?: null,
                            $icon ?: null,
                            $priceType,
                            $priceAmount,
                            $inclusionsJson,
                            $appliesTo,
                            $roomTypeIds,
                            $isFeatured,
                            $isActive,
                            $sortOrder,
                            $id
                        ]);
                        rh_log_event('room_packages', 'info', "Package updated: {$name}", ['id' => $id, 'user' => $user['username']]);
                        $message = 'Package updated successfully.';
                        $savedId  = $id;
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO room_packages
                            (name, slug, description, short_description, icon,
                             price_type, price_amount, inclusions,
                             applies_to, room_type_ids,
                             is_featured, is_active, sort_order)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        $stmt->execute([
                            $name,
                            $slug,
                            $description ?: null,
                            $shortDesc ?: null,
                            $icon ?: null,
                            $priceType,
                            $priceAmount,
                            $inclusionsJson,
                            $appliesTo,
                            $roomTypeIds,
                            $isFeatured,
                            $isActive,
                            $sortOrder
                        ]);
                        $savedId  = (int)$pdo->lastInsertId();
                        rh_log_event('room_packages', 'info', "Package created: {$name}", ['user' => $user['username']]);
                        $message = 'Package created successfully.';
                    }
                    $messageType = 'success';
                } catch (PDOException $e) {
                    error_log('room_packages save error: ' . $e->getMessage());
                    $message = 'Database error saving package.';
                    $messageType = 'error';
                }
            }
            // AJAX: return JSON and exit — never redirect
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success'  => $messageType === 'success',
                    'message'  => $message,
                    'saved_id' => $savedId ?? null,
                ]);
                exit;
            }
        } elseif ($action === 'toggle') {
            $id  = (int)($_POST['pkg_id'] ?? 0);
            $val = (int)($_POST['is_active'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE room_packages SET is_active=? WHERE id=?")->execute([$val, $id]);
                $message = $val ? 'Package activated.' : 'Package deactivated.';
                $messageType = 'success';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['pkg_id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM room_packages WHERE id=?")->execute([$id]);
                rh_log_event('room_packages', 'info', "Package deleted", ['id' => $id, 'user' => $user['username']]);
                $message = 'Package deleted.';
                $messageType = 'success';
            }
        }
    }
}

/* ── Fetch all packages ──────────────────────────────────────────────── */
$packages = $pdo->query("SELECT * FROM room_packages ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

$priceTypeLabels = [
    'per_night'           => 'per night',
    'per_stay'            => 'per stay',
    'per_person_per_night' => 'per person/night',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Room Packages — Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        /* ── Package cards ───────────────────────────────────────────────────── */
        .pkg-card {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 12px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            transition: box-shadow .2s, border-color .2s;
        }

        .pkg-card:hover {
            box-shadow: 0 4px 18px rgba(139, 115, 85, .1);
            border-color: rgba(139, 115, 85, .3);
        }

        .pkg-card__icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: linear-gradient(135deg, #f3ece4 0%, #ede4d8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: #8B7355;
            flex-shrink: 0;
        }

        .pkg-card__icon--complimentary {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #1f7a42;
        }

        .pkg-card__body {
            flex: 1;
            min-width: 0;
        }

        .pkg-card__title {
            font-weight: 600;
            font-size: 15px;
            margin: 0 0 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pkg-card__meta {
            font-size: 13px;
            color: #6c757d;
            margin: 0 0 8px;
        }

        .pkg-card__actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
            align-self: center;
        }

        .inclusion-pill {
            display: inline-block;
            background: #f0f4ff;
            color: #3047af;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            margin: 2px 2px 0 0;
        }

        .featured-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #fff3cd;
            color: #856404;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .complimentary-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #d4edda;
            color: #1f7a42;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .inactive-overlay {
            opacity: 0.45;
        }

        /* ── Modal overlay ───────────────────────────────────────────────────── */
        .pkg-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .55);
            z-index: 1000;
            overflow-y: auto;
            padding: 20px;
        }

        .pkg-modal-overlay.active {
            display: flex;
            align-items: flex-start;
            justify-content: center;
        }

        /* ── Modal box ───────────────────────────────────────────────────────── */
        .pkg-modal {
            background: #fff;
            border-radius: 10px;
            max-width: 700px;
            width: 100%;
            margin: auto;
            box-shadow: 0 10px 40px rgba(0, 0, 0, .25);
        }

        .pkg-modal__header {
            padding: 20px 24px 16px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: none;
        }

        .pkg-modal__header h3 {
            margin: 0;
            font-size: 17px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pkg-modal__header h3 i {
            color: #8B7355;
        }

        .pkg-modal__close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #6c757d;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            padding: 0;
        }

        .pkg-modal__close:hover {
            color: #495057;
        }

        .pkg-modal__body {
            padding: 20px 24px;
            max-height: calc(100vh - 170px);
            overflow-y: auto;
        }

        .pkg-modal__footer {
            padding: 16px 24px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            background: #f8f9fa;
        }

        /* ── Package card mobile ─────────────────────────────────────────────── */
        @media (max-width: 600px) {
            .pkg-card {
                display: grid;
                grid-template-columns: auto 1fr;
                grid-template-rows: auto auto auto;
                gap: 12px;
            }

            .pkg-card__icon {
                grid-row: 1 / 3;
                grid-column: 1;
                width: 52px;
                height: 52px;
            }

            .pkg-card__body {
                grid-row: 1;
                grid-column: 2;
                display: flex;
                flex-direction: column;
                min-width: 0;
            }

            .pkg-card__meta {
                word-break: break-word;
                overflow-wrap: break-word;
            }

            .pkg-card__actions {
                grid-row: 2;
                grid-column: 2;
                align-self: flex-start;
                justify-self: flex-end;
            }
        }

        /* ── Form layout ─────────────────────────────────────────────────────── */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        @media (max-width: 560px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 5px;
            color: #343a40;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color .2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #8B7355;
            outline: none;
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #adb5bd;
        }

        .form-hint {
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
            line-height: 1.4;
        }

        /* ── Section dividers ────────────────────────────────────────────────── */
        .form-section-label {
            font-size: 13px;
            font-weight: 600;
            color: #343a40;
            margin: 0 0 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ── Price toggle ────────────────────────────────────────────────────── */
        .price-toggle-row {
            display: flex;
            gap: 10px;
            margin-bottom: 8px;
        }

        .price-toggle-btn {
            flex: 1;
            padding: 8px 10px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            background: #fff;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-align: center;
            transition: all .2s;
            color: #495057;
        }

        .price-toggle-btn.active {
            border-color: #8B7355;
            background: rgba(139, 115, 85, .08);
            color: #8B7355;
        }

        .price-toggle-btn.active.free-btn {
            border-color: #1f7a42;
            background: rgba(31, 122, 66, .08);
            color: #1f7a42;
        }

        .price-amount-field {
            transition: opacity .2s;
        }

        .price-amount-field.hidden {
            opacity: .35;
            pointer-events: none;
        }

        /* ── Toggle switch — see .pkg-modal .toggle-* rules below ─────────────── */

        /* ── Icon preview — flex prefix, no absolute overlap ────────────────── */
        .icon-preview-wrap {
            display: flex;
            align-items: stretch;
            border: 1px solid #ced4da;
            border-radius: 5px;
            overflow: hidden;
            transition: border-color .2s;
        }

        .icon-preview-wrap:focus-within {
            border-color: #8B7355;
        }

        .icon-preview-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            width: 40px;
            background: rgba(139, 115, 85, .06);
            border-right: 1px solid #ced4da;
            color: #8B7355;
            font-size: 15px;
            flex-shrink: 0;
            transition: background .2s;
        }

        .icon-preview-wrap:focus-within .icon-preview-badge {
            background: rgba(139, 115, 85, .12);
        }

        .icon-preview-wrap input {
            flex: 1;
            min-width: 0;
            padding: 8px 10px;
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            background: transparent;
        }

        /* ── Toggle switch — scoped to .pkg-modal for full specificity ────────── */
        .pkg-modal .toggle-row {
            display: flex;
            align-items: center;
            padding: 4px 0;
            margin: 0;
        }

        .pkg-modal .toggle-switch {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            user-select: none;
        }

        /* Hide the real checkbox */
        .pkg-modal .toggle-switch input[type="checkbox"] {
            display: none;
        }

        /* The pill track */
        .pkg-modal .toggle-track {
            display: inline-block;
            width: 40px;
            height: 22px;
            background: #ccc;
            border-radius: 11px;
            position: relative;
            transition: background .2s;
        }

        /* The knob */
        .pkg-modal .toggle-track::after {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 16px;
            height: 16px;
            background: #fff;
            border-radius: 50%;
            transition: left .2s;
        }

        /* Checked state — green, knob slides right */
        .pkg-modal .toggle-switch input[type="checkbox"]:checked+.toggle-track {
            background: #28a745;
        }

        .pkg-modal .toggle-switch input[type="checkbox"]:checked+.toggle-track::after {
            left: 21px;
        }

        .pkg-modal .toggle-label {
            font-size: 13px;
            color: #343a40;
            line-height: 1.4;
        }
    </style>
    <style>
        .pkg-card__body {
            flex: 1;
            min-width: 0;
        }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-gift"></i> Room Packages</h1>
                <p>Add-on packages guests can select at booking — breakfast, romance, corporate, and more.</p>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:flex-end;">
                <button class="btn btn-primary" onclick="openPkgModal()">
                    <i class="fas fa-plus"></i> New Package
                </button>
            </div>
        </div>

        <div class="admin-container">

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?>" style="margin-bottom:16px;">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div style="background:#f8f9fa; border:1px solid #e9ecef; border-radius:8px; padding:16px; margin-bottom:24px; font-size:14px; color:#343a40;">
                <strong><i class="fas fa-info-circle" style="color:#8B7355;"></i> How it works:</strong>
                Packages appear as optional add-ons on the booking form.
                Guests check them before submitting. Package costs are added to the booking total.
                Price is calculated automatically based on the pricing type you choose.
            </div>

            <?php if (empty($packages)): ?>
                <div style="text-align:center; padding:60px 20px; color:#6c757d;">
                    <i class="fas fa-gift" style="font-size:48px; opacity:.3; display:block; margin-bottom:12px;"></i>
                    <p>No packages yet. Create your first package to offer guests extras like breakfast or spa access.</p>
                </div>
            <?php else: ?>
                <div id="pkgCardList">
                    <?php foreach ($packages as $pkg):
                        $inactive = !$pkg['is_active'];
                        $inclusions = [];
                        if (!empty($pkg['inclusions'])) {
                            $decoded = json_decode($pkg['inclusions'], true);
                            $inclusions = is_array($decoded) ? $decoded : [];
                        }
                        $priceLabel = $priceTypeLabels[$pkg['price_type']] ?? $pkg['price_type'];
                        $roomIds = [];
                        if (!empty($pkg['room_type_ids'])) {
                            $decoded = json_decode($pkg['room_type_ids'], true);
                            $roomIds = is_array($decoded) ? $decoded : [];
                        }
                        $scopeText = $pkg['applies_to'] === 'all' ? 'All rooms' : count($roomIds) . ' room type(s)';
                    ?>
                        <div class="pkg-card <?php echo $inactive ? 'inactive-overlay' : ''; ?>">
                            <div class="pkg-card__icon <?php echo (float)$pkg['price_amount'] === 0.0 ? 'pkg-card__icon--complimentary' : ''; ?>">
                                <i class="<?php echo htmlspecialchars($pkg['icon'] ?: 'fas fa-gift'); ?>"></i>
                            </div>
                            <div class="pkg-card__body">
                                <div class="pkg-card__title">
                                    <?php echo htmlspecialchars($pkg['name']); ?>
                                    <?php if ($pkg['is_featured']): ?> <span class="featured-badge"><i class="fas fa-star"></i> Featured</span><?php endif; ?>
                                    <?php if ((float)$pkg['price_amount'] === 0.0): ?><span class="complimentary-badge"><i class="fas fa-gift"></i> Complimentary</span><?php endif; ?>
                                </div>
                                <div class="pkg-card__meta">
                                    <?php if ((float)$pkg['price_amount'] === 0.0): ?>
                                        <strong style="color:#1f7a42;">FREE</strong> &middot; Complimentary add-on
                                    <?php else: ?>
                                        <strong>MWK <?php echo number_format((float)$pkg['price_amount'], 0); ?></strong> <?php echo htmlspecialchars($priceLabel); ?>
                                    <?php endif; ?>
                                    &nbsp;·&nbsp; <?php echo htmlspecialchars($scopeText); ?>
                                    <?php if ($pkg['short_description']): ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($pkg['short_description']); ?><?php endif; ?>
                                </div>
                                <?php if (!empty($inclusions)): ?>
                                    <div>
                                        <?php foreach (array_slice($inclusions, 0, 6) as $inc): ?>
                                            <span class="inclusion-pill"><i class="fas fa-check"></i> <?php echo htmlspecialchars($inc); ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($inclusions) > 6): ?><span class="inclusion-pill">+<?php echo count($inclusions) - 6; ?> more</span><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="pkg-card__actions">
                                <button class="btn btn-sm btn-outline-secondary" onclick='editPkg(<?php echo json_encode($pkg); ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="pkg_id" value="<?php echo (int)$pkg['id']; ?>">
                                    <input type="hidden" name="is_active" value="<?php echo $inactive ? 1 : 0; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $inactive ? 'btn-outline-success' : 'btn-outline-secondary'; ?>"
                                        title="<?php echo $inactive ? 'Activate' : 'Deactivate'; ?>">
                                        <i class="fas <?php echo $inactive ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline;" class="delete-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="pkg_id" value="<?php echo (int)$pkg['id']; ?>">
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete(this, 'Delete package &ldquo;<?php echo htmlspecialchars($pkg['name'], ENT_QUOTES); ?>&rdquo;? Guests with existing bookings will keep it, but new bookings won&rsquo;t see it.')"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div><!-- #pkgCardList -->

            <?php endif; ?>
        </div>
    </div>

    <!-- ─── Create / Edit Modal ─────────────────────────────────────────── -->
    <div class="modal-overlay pkg-modal-overlay" id="pkgModalOverlay">
        <div class="modal-content pkg-modal">
            <form method="POST" id="pkgForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="pkg_id" id="fieldPkgId" value="">

                <div class="modal-header pkg-modal__header">
                    <h3 id="pkgModalTitle"><i class="fas fa-gift"></i> New Package</h3>
                    <button type="button" class="modal-close pkg-modal__close" onclick="closePkgModal()" aria-label="Close">&times;</button>
                </div>

                <div class="modal-body pkg-modal__body">

                    <p class="form-section-label"><i class="fas fa-info-circle"></i> Identity</p>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Package Name <span style="color:red">*</span></label>
                            <input type="text" id="fpName" name="name" required placeholder="e.g. Bed &amp; Breakfast">
                        </div>
                        <div class="form-group">
                            <label>Icon (Font Awesome class)</label>
                            <div class="icon-preview-wrap">
                                <span class="icon-preview-badge"><i class="fas fa-gift" id="fpIconPreview"></i></span>
                                <input type="text" id="fpIcon" name="icon" placeholder="fas fa-coffee" oninput="updateIconPreview(this.value)">
                            </div>
                            <p class="form-hint">Browse at fontawesome.com/icons</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Short Description</label>
                        <input type="text" id="fpShortDesc" name="short_description" placeholder="Brief summary shown on booking form">
                    </div>

                    <div class="form-group">
                        <label>Full Description (optional)</label>
                        <textarea id="fpDescription" name="description" rows="2" placeholder="Longer description for detail view"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Inclusions (one per line)</label>
                        <textarea id="fpInclusions" name="inclusions" rows="5" placeholder="Daily breakfast for 2&#10;Welcome drink on arrival&#10;Late checkout (subject to availability)&#10;Complimentary newspaper"></textarea>
                        <p class="form-hint">One item per line. These appear as ticks on the booking form.</p>
                    </div>

                    <p class="form-section-label" style="margin-top:20px;"><i class="fas fa-tag"></i> Pricing</p>

                    <!-- Complimentary toggle -->
                    <div class="form-group">
                        <div class="price-toggle-row">
                            <button type="button" class="price-toggle-btn active" id="btnPriced" onclick="setComplimentary(false)">
                                <i class="fas fa-money-bill-wave" style="margin-right:6px;"></i> Priced Add-on
                            </button>
                            <button type="button" class="price-toggle-btn free-btn" id="btnFree" onclick="setComplimentary(true)">
                                <i class="fas fa-gift" style="margin-right:6px;"></i> Complimentary (FREE)
                            </button>
                        </div>
                        <p class="form-hint" id="pricingHint">Set a price for this package below.</p>
                    </div>

                    <div class="form-row price-amount-field" id="priceAmountWrap">
                        <div class="form-group">
                            <label>Price Type</label>
                            <select id="fpPriceType" name="price_type">
                                <option value="per_night">Per Night</option>
                                <option value="per_stay">Per Stay (flat fee)</option>
                                <option value="per_person_per_night">Per Person Per Night</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Price Amount (MWK) <span style="color:red">*</span></label>
                            <input type="number" id="fpPriceAmount" name="price_amount" min="0" step="0.01" placeholder="e.g. 15000">
                        </div>
                    </div>
                    <!-- Hidden field holds 0 when complimentary mode is active -->
                    <input type="hidden" id="fpPriceAmountHidden" name="price_amount_free" value="">

                    <p class="form-section-label" style="margin-top:20px;"><i class="fas fa-layer-group"></i> Scope &amp; Visibility</p>

                    <div class="form-group">
                        <label>Applies To</label>
                        <select id="fpAppliesTo" name="applies_to" onchange="onPkgAppliesToChange(this.value)">
                            <option value="all">All Room Types</option>
                            <option value="room_types">Selected Room Types Only</option>
                        </select>
                    </div>

                    <div class="form-group" id="pkgRoomTypesField" style="display:none;">
                        <label>Select Room Types</label>
                        <div style="display:flex; flex-wrap:wrap; gap:8px;">
                            <?php foreach ($allRooms as $r): ?>
                                <label style="display:flex; align-items:center; gap:5px; padding:6px 12px; border:1.5px solid #dee2e6; border-radius:8px; cursor:pointer; font-size:13px; transition:border-color .2s;">
                                    <input type="checkbox" name="room_type_ids[]" class="pkg-room-chk" value="<?php echo (int)$r['id']; ?>">
                                    <?php echo htmlspecialchars($r['name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-row" style="margin-top:8px;">
                        <div class="form-group">
                            <label>Sort Order</label>
                            <input type="number" id="fpSortOrder" name="sort_order" min="0" value="0">
                            <p class="form-hint">Lower = shown first on booking form.</p>
                        </div>
                        <div class="form-group">
                            <label style="margin-bottom:12px;">Options</label>
                            <div style="display:flex; flex-direction:column; gap:12px;">
                                <div class="toggle-row">
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="fpIsFeatured" name="is_featured" value="1">
                                        <span class="toggle-track"></span>
                                        <span class="toggle-label">Featured (shown prominently)</span>
                                    </label>
                                </div>
                                <div class="toggle-row">
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="fpIsActive" name="is_active" value="1" checked>
                                        <span class="toggle-track"></span>
                                        <span class="toggle-label">Active (visible on booking form)</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer pkg-modal__footer" style="flex-direction:column; align-items:stretch; gap:0;">
                    <div id="pkgModalFeedback" class="admin-modal-feedback"></div>
                    <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" class="btn btn-secondary" onclick="closePkgModal()">Close</button>
                        <button type="submit" id="pkgSaveBtn" class="btn btn-primary"><i class="fas fa-save"></i> Save Package</button>
                    </div>
                </div>

            </form>
        </div>
    </div>

    <script>
        const pkgOverlay = document.getElementById('pkgModalOverlay');
        let _isComplimentary = false;

        function setComplimentary(free) {
            _isComplimentary = free;
            const wrap = document.getElementById('priceAmountWrap');
            const inp = document.getElementById('fpPriceAmount');
            const hint = document.getElementById('pricingHint');
            const btnP = document.getElementById('btnPriced');
            const btnF = document.getElementById('btnFree');
            if (free) {
                wrap.classList.add('hidden');
                inp.value = '0';
                hint.textContent = 'This package will be offered at no charge to guests.';
                btnP.classList.remove('active');
                btnF.classList.add('active');
            } else {
                wrap.classList.remove('hidden');
                if (inp.value === '0') inp.value = '';
                hint.textContent = 'Set a price for this package below.';
                btnP.classList.add('active');
                btnF.classList.remove('active');
            }
        }

        function updateIconPreview(val) {
            const prev = document.getElementById('fpIconPreview');
            if (!prev) return;
            prev.className = (val && val.trim()) ? val.trim() : 'fas fa-gift';
        }

        function openPkgModal(pkg) {
            document.getElementById('pkgModalTitle').innerHTML = pkg ?
                '<i class="fas fa-edit"></i> Edit Package' :
                '<i class="fas fa-plus"></i> New Package';
            document.getElementById('fieldPkgId').value = pkg ? pkg.id : '';
            document.getElementById('fpName').value = pkg ? pkg.name : '';
            document.getElementById('fpIcon').value = pkg ? (pkg.icon || '') : '';
            document.getElementById('fpShortDesc').value = pkg ? (pkg.short_description || '') : '';
            document.getElementById('fpDescription').value = pkg ? (pkg.description || '') : '';
            document.getElementById('fpPriceType').value = pkg ? pkg.price_type : 'per_night';
            document.getElementById('fpPriceAmount').value = pkg ? pkg.price_amount : '';
            document.getElementById('fpSortOrder').value = pkg ? pkg.sort_order : 0;
            document.getElementById('fpIsFeatured').checked = pkg ? !!+pkg.is_featured : false;
            document.getElementById('fpIsActive').checked = pkg ? !!+pkg.is_active : true;

            updateIconPreview(pkg ? (pkg.icon || '') : '');

            // Sync complimentary toggle
            const isComp = pkg ? parseFloat(pkg.price_amount) === 0 : false;
            setComplimentary(isComp);

            // Inclusions: decode JSON to one-per-line
            let inclText = '';
            if (pkg && pkg.inclusions) {
                try {
                    const arr = JSON.parse(pkg.inclusions);
                    inclText = Array.isArray(arr) ? arr.join('\n') : pkg.inclusions;
                } catch (e) {
                    inclText = pkg.inclusions || '';
                }
            }
            document.getElementById('fpInclusions').value = inclText;

            const appliesTo = pkg ? pkg.applies_to : 'all';
            document.getElementById('fpAppliesTo').value = appliesTo;
            onPkgAppliesToChange(appliesTo);

            // Room type checkboxes
            const ids = pkg && pkg.room_type_ids ? JSON.parse(pkg.room_type_ids) : [];
            document.querySelectorAll('.pkg-room-chk').forEach(chk => {
                chk.checked = ids.includes(+chk.value);
            });

            pkgOverlay.classList.add('active');
        }

        function editPkg(pkg) {
            openPkgModal(pkg);
        }

        function closePkgModal() {
            pkgOverlay.classList.remove('active');
            // Clear feedback when modal is dismissed
            const fb = document.getElementById('pkgModalFeedback');
            if (fb) {
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
            }
        }

        pkgOverlay.addEventListener('click', function(e) {
            if (e.target === pkgOverlay) closePkgModal();
        });

        // ── AJAX save — keep modal open, show feedback inline ────────────────
        document.getElementById('pkgForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const form = this;
            const saveBtn = document.getElementById('pkgSaveBtn');
            const fb = document.getElementById('pkgModalFeedback');

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';

            fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new FormData(form)
                })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(res) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Package';
                    if (res.success) {
                        fb.className = 'admin-modal-feedback admin-modal-feedback--success visible';
                        fb.innerHTML = '<i class="fas fa-check-circle"></i> ' + res.message;
                        // Flip to "Edit" mode and lock in the real ID for subsequent saves
                        if (res.saved_id) {
                            document.getElementById('fieldPkgId').value = res.saved_id;
                            document.getElementById('pkgModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Package';
                        }
                        refreshPkgCardList();
                    } else {
                        fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + res.message;
                    }
                })
                .catch(function() {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Package';
                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error — please try again.';
                });
        });

        function refreshPkgCardList() {
            fetch(window.location.href)
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const next = doc.getElementById('pkgCardList');
                    const cur = document.getElementById('pkgCardList');
                    if (next && cur) cur.innerHTML = next.innerHTML;
                })
                .catch(function() {}); // silent — list refreshes on next manual reload
        }

        function onPkgAppliesToChange(val) {
            document.getElementById('pkgRoomTypesField').style.display = val === 'room_types' ? 'block' : 'none';
        }

        function confirmDelete(btn, message) {
            const modal = document.getElementById('deleteConfirmModal');
            document.getElementById('deleteConfirmMessage').innerHTML = message;
            const form = btn.closest('form');
            document.getElementById('deleteConfirmYes').onclick = function() {
                modal.style.display = 'none';
                form.submit();
            };
            modal.style.display = 'flex';
        }
        document.addEventListener('DOMContentLoaded', function() {
            const dm = document.getElementById('deleteConfirmModal');
            if (dm) dm.addEventListener('click', function(e) {
                if (e.target === this) this.style.display = 'none';
            });
        });
    </script>

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9000; align-items:center; justify-content:center; padding:20px;">
        <div style="background:#fff; border-radius:10px; max-width:420px; width:100%; padding:28px; box-shadow:0 10px 40px rgba(0,0,0,.3);">
            <h4 style="margin:0 0 12px; font-size:16px;"><i class="fas fa-exclamation-triangle" style="color:#dc3545;"></i> Confirm Delete</h4>
            <p id="deleteConfirmMessage" style="margin:0 0 22px; font-size:14px; color:#343a40; line-height:1.5;"></p>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="document.getElementById('deleteConfirmModal').style.display='none'" class="btn btn-secondary">Cancel</button>
                <button type="button" id="deleteConfirmYes" class="btn btn-danger"><i class="fas fa-trash"></i> Yes, Delete</button>
            </div>
        </div>
    </div>

    <script src="js/admin-components.js" defer></script>
<?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

