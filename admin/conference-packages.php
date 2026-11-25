<?php

/**
 * Conference Packages (owner 2026-11-26; plan .claude/CONF_PACKAGES_PLAN.md)
 * Admin Panel - Conference & Events > Conference Packages
 *
 * The catalog of add-ons for conference rooms (catering, AV, stationery...). Priced per event, per day or per delegate
 * per day; offered in every conference room or only in chosen ones. Clients pick them on the public conference form,
 * staff add or remove them in an enquiry's Details. Each enquiry line keeps the price it was added at, so editing or
 * deleting a package here never changes an existing enquiry.
 *
 * Page: 'conference' permission (map in admin/includes/permissions.php). Writes: 'conference_rooms', the same
 * permission that manages the conference rooms themselves.
 */
require_once 'admin-init.php';
require_once __DIR__ . '/../includes/form-validation.php';
require_once __DIR__ . '/../includes/conference-pricing.php';
/** @var array $user */
/** @var string $csrf_token */

$message     = '';
$messageType = '';
$cpReady     = rh_conf_has_packages($pdo);
$cpCanEdit   = hasPermission((int)($user['id'] ?? 0), 'conference_rooms');
$cpTypes     = rh_conf_price_types();

$cpRooms = [];
try {
    $cpRooms = $pdo->query("SELECT id, name, is_active FROM conference_rooms ORDER BY display_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cpRooms = [];
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $savedId = null;
    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            throw new RuntimeException('Security token invalid - refresh the page.');
        }
        if (!$cpReady) {
            throw new RuntimeException('Conference packages are not set up on this system yet (migration 092).');
        }
        if (!$cpCanEdit) {
            throw new RuntimeException('You do not have permission to manage conference packages.');
        }

        if ($action === 'save') {
            $id         = !empty($_POST['pkg_id']) ? (int)$_POST['pkg_id'] : null;
            $name       = rh_clean_text($_POST['name'] ?? '');
            $shortDesc  = rh_clean_text($_POST['short_description'] ?? '');
            $description = trim((string)($_POST['description'] ?? ''));
            $icon       = rh_clean_text($_POST['icon'] ?? '');
            $priceType  = (string)($_POST['price_type'] ?? 'per_event');
            $priceRaw   = trim((string)($_POST['price_amount'] ?? ''));
            $appliesTo  = (($_POST['applies_to'] ?? 'all') === 'rooms') ? 'rooms' : 'all';
            $isFeatured = empty($_POST['is_featured']) ? 0 : 1;
            $isActive   = empty($_POST['is_active']) ? 0 : 1;
            $sortOrder  = min(65535, max(0, (int)($_POST['sort_order'] ?? 0)));
            $inclusions = array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['inclusions'] ?? '')))));
            $validRoomIds = array_map('intval', array_column($cpRooms, 'id'));
            $roomIds = ($appliesTo === 'rooms') ? array_values(array_unique(array_map('intval', (array)($_POST['conference_room_ids'] ?? [])))) : [];

            if ($name === '') {
                throw new RuntimeException('Package name is required.');
            }
            if (mb_strlen($name) > 100) {
                throw new RuntimeException('Package name cannot exceed 100 characters.');
            }
            if (mb_strlen($shortDesc) > 255) {
                throw new RuntimeException('Short description cannot exceed 255 characters.');
            }
            if (mb_strlen($icon) > 60) {
                throw new RuntimeException('Icon class cannot exceed 60 characters.');
            }
            if (!isset($cpTypes[$priceType])) {
                throw new RuntimeException('Choose how the package is priced.');
            }
            if ($priceRaw === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0 || (float)$priceRaw > 9999999.99) {
                throw new RuntimeException('Price must be a number from 0 to 9,999,999.99.');
            }
            $priceAmount = round((float)$priceRaw, 2);
            if ($appliesTo === 'rooms' && (!$roomIds || array_diff($roomIds, $validRoomIds))) {
                throw new RuntimeException('Choose at least one conference room, or offer the package in all rooms.');
            }
            if ($id) {
                $exists = $pdo->prepare('SELECT * FROM conference_packages WHERE id = ?');
                $exists->execute([$id]);
                if (!$exists->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException('That package no longer exists.');
                }
            }
            if ($dup = rh_find_duplicate($pdo, 'conference_packages', 'name', $name, [], $id ?: null)) {
                throw new RuntimeException(rh_duplicate_message('conference package', (string)$dup['value']));
            }
            $params = [
                $name, $shortDesc !== '' ? $shortDesc : null, $description !== '' ? $description : null, $icon !== '' ? $icon : null,
                $priceType, $priceAmount, $inclusions ? json_encode($inclusions) : null,
                $appliesTo, $roomIds ? json_encode($roomIds) : null, $isFeatured, $isActive, $sortOrder,
            ];
            $label = rh_conf_money_words($priceAmount) . ' ' . $cpTypes[$priceType];
            if ($id) {
                $oldSt = $pdo->prepare('SELECT price_type, price_amount, is_active FROM conference_packages WHERE id = ?');
                $oldSt->execute([$id]);
                $old = $oldSt->fetch(PDO::FETCH_ASSOC) ?: [];
                $params[] = $id;
                $pdo->prepare("UPDATE conference_packages SET name = ?, short_description = ?, description = ?, icon = ?,
                    price_type = ?, price_amount = ?, inclusions = ?, applies_to = ?, conference_room_ids = ?,
                    is_featured = ?, is_active = ?, sort_order = ? WHERE id = ?")->execute($params);
                $oldLabel = $old ? rh_conf_money_words((float)$old['price_amount']) . ' ' . ($cpTypes[(string)$old['price_type']] ?? '') : '';
                logActivity((int)($user['id'] ?? 0), 'conference_package_saved', 'Conference package "' . $name . '" updated'
                    . ($oldLabel !== '' && $oldLabel !== $label ? ': price ' . $oldLabel . ' -> ' . $label : ' (' . $label . ')')
                    . '. Enquiries that already have it keep their own price.');
                $message = 'Package updated. Enquiries that already have it keep the price they were given.';
                $savedId = $id;
            } else {
                $ins = $pdo->prepare("INSERT INTO conference_packages
                    (name, short_description, description, icon, price_type, price_amount, inclusions, applies_to,
                     conference_room_ids, is_featured, is_active, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                rh_with_create_lock($pdo, 'conference_package', function () use ($pdo, $ins, $params, $name, &$savedId) {
                    if ($dupNow = rh_find_duplicate($pdo, 'conference_packages', 'name', $name)) {
                        throw new RuntimeException(rh_duplicate_message('conference package', (string)$dupNow['value']));
                    }
                    $ins->execute($params);
                    $savedId = (int)$pdo->lastInsertId();
                });
                logActivity((int)($user['id'] ?? 0), 'conference_package_saved', 'Conference package "' . $name . '" created (' . $label . ')');
                $message = 'Package created.';
            }
            $messageType = 'success';
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['pkg_id'] ?? 0);
            $val = empty($_POST['is_active']) ? 0 : 1;
            $tg = $pdo->prepare('SELECT name, is_active FROM conference_packages WHERE id = ?');
            $tg->execute([$id]);
            $tgRow = $tg->fetch(PDO::FETCH_ASSOC);
            if (!$tgRow) {
                throw new RuntimeException('That package no longer exists.');
            }
            $pdo->prepare('UPDATE conference_packages SET is_active = ? WHERE id = ?')->execute([$val, $id]);
            logActivity((int)($user['id'] ?? 0), 'conference_package_saved', 'Conference package "' . $tgRow['name'] . '": '
                . ((int)$tgRow['is_active'] ? 'active' : 'inactive') . ' -> ' . ($val ? 'active' : 'inactive'));
            $message = $val ? 'Package switched on: clients can pick it again.' : 'Package switched off: clients no longer see it. Enquiries that have it keep it.';
            $messageType = 'success';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['pkg_id'] ?? 0);
            $dl = $pdo->prepare('SELECT name FROM conference_packages WHERE id = ?');
            $dl->execute([$id]);
            $dlName = $dl->fetchColumn();
            if ($dlName === false) {
                throw new RuntimeException('That package no longer exists.');
            }
            $pdo->prepare('DELETE FROM conference_packages WHERE id = ?')->execute([$id]);
            logActivity((int)($user['id'] ?? 0), 'conference_package_deleted', 'Conference package "' . $dlName . '" deleted (enquiries that have it keep their line and price)');
            $message = 'Package deleted. Enquiries that already have it keep their line and price.';
            $messageType = 'success';
        }
    } catch (PDOException $e) {
        error_log('conference_packages save error: ' . $e->getMessage());
        $message = $e->getCode() === '23000'
            ? 'A conference package with this name already exists. Use a different name or edit the existing one.'
            : 'The package could not be saved. Please try again.';
        $messageType = 'error';
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $messageType === 'success', 'message' => $message, 'saved_id' => $savedId]);
        exit;
    }
}

$cpList = [];
$cpUse = [];
if ($cpReady) {
    $cpList = $pdo->query("SELECT * FROM conference_packages ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    // Open enquiries (pending / confirmed) carrying each package: shown so staff know an edit does not reprice them.
    foreach ($pdo->query("SELECT cip.package_id, COUNT(*) AS n FROM conference_inquiry_packages cip
        JOIN conference_inquiries ci ON ci.id = cip.inquiry_id
        WHERE ci.status IN ('pending', 'confirmed') AND cip.package_id IS NOT NULL GROUP BY cip.package_id")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $cpUse[(int)$u['package_id']] = (int)$u['n'];
    }
}
$cpRoomNames = [];
foreach ($cpRooms as $r) {
    $cpRoomNames[(int)$r['id']] = (string)$r['name'];
}
$cpCurrency = (string)getSetting('currency_symbol', 'MWK');
$cpVatNote = vat_mode() === 'exclusive' ? 'before VAT (VAT is added on top, as for the room rates)' : (vat_mode() === 'off' ? '' : 'VAT included, as for the room rates');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conference Packages - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/conference-management.css?v=<?php echo @filemtime(__DIR__ . '/css/conference-management.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-box"></i> Conference Packages</h1>
                <p>Add-ons for conference rooms: catering, equipment, stationery and more.</p>
            </div>
            <?php if ($cpReady && $cpCanEdit): ?>
                <div class="cp-head-actions">
                    <button type="button" class="btn btn-primary" onclick="openCpModal()"><i class="fas fa-plus"></i> New Package</button>
                </div>
            <?php endif; ?>
        </div>

        <div class="admin-container">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (!$cpReady): ?>
                <div class="alert alert-danger">Conference packages are not set up on this system yet (migration 092 has not run).</div>
            <?php else: ?>
                <div class="cp-howto">
                    <strong>How it works:</strong> clients pick packages on the conference enquiry form; staff can add or remove them in an
                    enquiry's Details. The package price is added to the venue price to make the total. Each enquiry keeps the price it was
                    given, so changing a price here only affects new enquiries.
                </div>

                <?php if (!$cpList): ?>
                    <p class="cp-empty">No conference packages yet.<?php echo $cpCanEdit ? ' Use New Package to add the first one.' : ''; ?></p>
                <?php else: ?>
                    <div class="table-responsive" id="cpList">
                        <table class="data-table cp-table">
                            <thead>
                                <tr>
                                    <th>Package</th>
                                    <th class="num">Price <i class="help" data-tip="The unit price<?php echo $cpVatNote !== '' ? ', ' . htmlspecialchars($cpVatNote) : ''; ?>. An enquiry line multiplies it as set in Priced.">?</i></th>
                                    <th>Priced <i class="help" data-tip="Per event: charged once. Per day: times the event days. Per delegate per day: times the delegates and the event days.">?</i></th>
                                    <th>Offered in <i class="help" data-tip="The conference rooms clients can pick this package for.">?</i></th>
                                    <th class="num">Open enquiries <i class="help" data-tip="Pending and confirmed enquiries that have this package now. They keep their own price when you edit it here.">?</i></th>
                                    <th>Status</th>
                                    <?php if ($cpCanEdit): ?><th></th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cpList as $p):
                                    $pIds = rh_conf_package_room_ids($p);
                                    $pScope = (string)$p['applies_to'] === 'rooms'
                                        ? implode(', ', array_map(static function ($rid) use ($cpRoomNames) {
                                            return $cpRoomNames[$rid] ?? ('room #' . $rid);
                                        }, $pIds))
                                        : 'All conference rooms';
                                    $pActive = (int)$p['is_active'] === 1;
                                    $pJs = [
                                        'id' => (int)$p['id'], 'name' => (string)$p['name'], 'short_description' => (string)($p['short_description'] ?? ''),
                                        'description' => (string)($p['description'] ?? ''), 'icon' => (string)($p['icon'] ?? ''),
                                        'price_type' => (string)$p['price_type'], 'price_amount' => (string)$p['price_amount'],
                                        'inclusions' => implode("\n", (array)(json_decode((string)($p['inclusions'] ?? ''), true) ?: [])),
                                        'applies_to' => (string)$p['applies_to'], 'room_ids' => $pIds,
                                        'is_featured' => (int)$p['is_featured'], 'is_active' => (int)$p['is_active'], 'sort_order' => (int)$p['sort_order'],
                                    ];
                                ?>
                                    <tr class="<?php echo $pActive ? '' : 'cp-row--off'; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars((string)$p['name']); ?></strong>
                                            <?php if (!empty($p['short_description'])): ?><div class="cp-sub"><?php echo htmlspecialchars((string)$p['short_description']); ?></div><?php endif; ?>
                                        </td>
                                        <td class="num"><?php echo htmlspecialchars($cpCurrency) . ' ' . number_format((float)$p['price_amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($cpTypes[(string)$p['price_type']] ?? (string)$p['price_type']); ?></td>
                                        <td><?php echo htmlspecialchars($pScope); ?></td>
                                        <td class="num"><?php echo (int)($cpUse[(int)$p['id']] ?? 0); ?></td>
                                        <td><span class="badge <?php echo $pActive ? 'badge-confirmed' : 'badge-cancelled'; ?>"><?php echo $pActive ? 'On' : 'Off'; ?></span></td>
                                        <?php if ($cpCanEdit): ?>
                                            <td class="cp-actions">
                                                <button type="button" class="btn btn-sm" onclick='openCpModal(<?php echo htmlspecialchars(json_encode($pJs), ENT_QUOTES, 'UTF-8'); ?>)'>Edit</button>
                                                <form method="POST" class="cp-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="pkg_id" value="<?php echo (int)$p['id']; ?>">
                                                    <input type="hidden" name="is_active" value="<?php echo $pActive ? 0 : 1; ?>">
                                                    <button type="submit" class="btn btn-sm"><?php echo $pActive ? 'Switch off' : 'Switch on'; ?></button>
                                                </form>
                                                <form method="POST" class="cp-inline" data-name="<?php echo htmlspecialchars((string)$p['name'], ENT_QUOTES); ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="pkg_id" value="<?php echo (int)$p['id']; ?>">
                                                    <button type="button" class="btn btn-sm btn-danger" onclick="cpConfirmDelete(this)">Delete</button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($cpReady && $cpCanEdit): ?>
        <div class="modal-overlay" id="cpModalOverlay" role="dialog" aria-modal="true" aria-labelledby="cpModalTitle">
            <div class="modal-content cp-modal">
                <form method="POST" id="cpForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="pkg_id" id="cpId" value="">
                    <div class="modal-header">
                        <h3 id="cpModalTitle">New Package</h3>
                        <button type="button" class="modal-close" onclick="closeCpModal()" aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cpName">Package name <span class="cp-req">*</span></label>
                                <input type="text" id="cpName" name="name" maxlength="100" required placeholder="e.g. Full-day catering">
                            </div>
                            <div class="form-group">
                                <label for="cpIcon">Icon (Font Awesome class)</label>
                                <input type="text" id="cpIcon" name="icon" maxlength="60" placeholder="fas fa-utensils">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="cpShort">Short description</label>
                            <input type="text" id="cpShort" name="short_description" maxlength="255" placeholder="One line shown on the enquiry form">
                        </div>
                        <div class="form-group">
                            <label for="cpDesc">Full description (optional)</label>
                            <textarea id="cpDesc" name="description" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="cpIncl">Inclusions (one per line)</label>
                            <textarea id="cpIncl" name="inclusions" rows="4" placeholder="Morning tea&#10;Lunch buffet&#10;Afternoon tea"></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cpType">Priced <i class="help" data-tip="Per event: charged once. Per day: times the event days. Per delegate per day: times the delegates and the event days.">?</i></label>
                                <select id="cpType" name="price_type">
                                    <?php foreach ($cpTypes as $k => $v): ?>
                                        <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars(ucfirst($v)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="cpPrice">Price (<?php echo htmlspecialchars($cpCurrency); ?>) <span class="cp-req">*</span> <i class="help" data-tip="The unit price<?php echo $cpVatNote !== '' ? ', ' . htmlspecialchars($cpVatNote) : ''; ?>. 0 = included free.">?</i></label>
                                <input type="number" id="cpPrice" name="price_amount" min="0" max="9999999.99" step="0.01" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="cpApplies">Offered in</label>
                            <select id="cpApplies" name="applies_to" onchange="cpScopeChange(this.value)">
                                <option value="all">All conference rooms</option>
                                <option value="rooms">Chosen conference rooms only</option>
                            </select>
                        </div>
                        <div class="form-group" id="cpRoomsField" hidden>
                            <label>Conference rooms</label>
                            <div class="cp-room-checks">
                                <?php foreach ($cpRooms as $r): ?>
                                    <label class="cp-room-check">
                                        <input type="checkbox" name="conference_room_ids[]" class="cp-room-chk" value="<?php echo (int)$r['id']; ?>">
                                        <?php echo htmlspecialchars((string)$r['name']) . ((int)$r['is_active'] === 1 ? '' : ' (off)'); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cpSort">Sort order</label>
                                <input type="number" id="cpSort" name="sort_order" min="0" max="65535" value="0">
                            </div>
                            <div class="form-group">
                                <label class="cp-check"><input type="checkbox" id="cpFeatured" name="is_featured" value="1"> Featured</label>
                                <label class="cp-check"><input type="checkbox" id="cpActive" name="is_active" value="1" checked> On (clients can pick it)</label>
                            </div>
                        </div>
                        <div id="cpFeedback" class="admin-modal-feedback"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeCpModal()">Close</button>
                        <button type="submit" id="cpSaveBtn" class="btn btn-primary">Save Package</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="cpDeleteModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="cpDeleteTitle">
            <div class="modal-content cp-modal cp-modal--small">
                <div class="modal-header">
                    <h3 id="cpDeleteTitle">Delete package?</h3>
                    <button type="button" class="modal-close" onclick="closeCpDelete()" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <p id="cpDeleteText"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCpDelete()">Cancel</button>
                    <button type="button" id="cpDeleteYes" class="btn btn-danger">Delete</button>
                </div>
            </div>
        </div>

        <script src="js/admin-form-guard.js?v=<?php echo @filemtime(__DIR__ . '/js/admin-form-guard.js'); ?>"></script>
        <script>
            (function () {
                var overlay = document.getElementById('cpModalOverlay');
                var form = document.getElementById('cpForm');

                window.cpScopeChange = function (val) {
                    document.getElementById('cpRoomsField').hidden = val !== 'rooms';
                };
                window.openCpModal = function (p) {
                    document.getElementById('cpModalTitle').textContent = p ? 'Edit Package' : 'New Package';
                    document.getElementById('cpId').value = p ? p.id : '';
                    document.getElementById('cpName').value = p ? p.name : '';
                    document.getElementById('cpIcon').value = p ? p.icon : '';
                    document.getElementById('cpShort').value = p ? p.short_description : '';
                    document.getElementById('cpDesc').value = p ? p.description : '';
                    document.getElementById('cpIncl').value = p ? p.inclusions : '';
                    document.getElementById('cpType').value = p ? p.price_type : 'per_event';
                    document.getElementById('cpPrice').value = p ? p.price_amount : '';
                    document.getElementById('cpApplies').value = p ? p.applies_to : 'all';
                    document.getElementById('cpSort').value = p ? p.sort_order : 0;
                    document.getElementById('cpFeatured').checked = p ? !!p.is_featured : false;
                    document.getElementById('cpActive').checked = p ? !!p.is_active : true;
                    var ids = p && Array.isArray(p.room_ids) ? p.room_ids : [];
                    document.querySelectorAll('.cp-room-chk').forEach(function (c) { c.checked = ids.indexOf(+c.value) !== -1; });
                    cpScopeChange(p ? p.applies_to : 'all');
                    var fb = document.getElementById('cpFeedback');
                    fb.className = 'admin-modal-feedback';
                    fb.textContent = '';
                    if (window.RHFormGuard) { RHFormGuard.clearErrors(form); }
                    overlay.classList.add('active');
                };
                window.closeCpModal = function () {
                    overlay.classList.remove('active');
                };
                overlay.addEventListener('click', function (e) { if (e.target === overlay) { closeCpModal(); } });

                if (window.RHFormGuard) {
                    RHFormGuard.watch(document.getElementById('cpName'), function () {
                        return { form: 'conference_package', excludeId: document.getElementById('cpId').value };
                    });
                    RHFormGuard.guardSubmit(form, { busy: false });
                }

                var saved = false;
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var btn = document.getElementById('cpSaveBtn');
                    var fb = document.getElementById('cpFeedback');
                    btn.disabled = true;
                    btn.textContent = 'Saving...';
                    fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) })
                        .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                        .then(function (res) {
                            btn.disabled = false;
                            btn.textContent = 'Save Package';
                            fb.className = 'admin-modal-feedback visible ' + (res.success ? 'admin-modal-feedback--success' : 'admin-modal-feedback--error');
                            fb.textContent = res.message || '';
                            if (res.success) {
                                saved = true;
                                if (res.saved_id) {
                                    document.getElementById('cpId').value = res.saved_id;
                                    document.getElementById('cpModalTitle').textContent = 'Edit Package';
                                }
                            }
                        })
                        .catch(function () {
                            btn.disabled = false;
                            btn.textContent = 'Save Package';
                            fb.className = 'admin-modal-feedback visible admin-modal-feedback--error';
                            fb.textContent = 'Network error - please try again.';
                        });
                });
                // The list refreshes when the window closes after a save (full reload keeps paging and helpers in step).
                var origClose = window.closeCpModal;
                window.closeCpModal = function () {
                    origClose();
                    if (saved) { window.location.reload(); }
                };

                var delModal = document.getElementById('cpDeleteModal');
                window.cpConfirmDelete = function (btn) {
                    var f = btn.closest('form');
                    document.getElementById('cpDeleteText').textContent = 'Delete "' + (f.getAttribute('data-name') || 'this package')
                        + '"? Clients will no longer see it. Enquiries that already have it keep their line and price.';
                    document.getElementById('cpDeleteYes').onclick = function () { delModal.classList.remove('active'); f.submit(); };
                    delModal.classList.add('active');
                };
                window.closeCpDelete = function () { delModal.classList.remove('active'); };
                delModal.addEventListener('click', function (e) { if (e.target === delModal) { closeCpDelete(); } });
            })();
        </script>
    <?php endif; ?>

    <script src="js/admin-components.js" defer></script>
<?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>
