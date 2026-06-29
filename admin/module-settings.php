<?php
require_once 'admin-init.php';
/** @var string $csrf_token */
/** @var PDO $pdo */

$user = [
    'id'        => $_SESSION['admin_user_id'],
    'username'  => $_SESSION['admin_username'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];

require_once __DIR__ . '/includes/permissions.php';
if (!hasPermission((int)$user['id'], 'booking_settings')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

// Ensure enabled_modules table exists and load current states
$modules = function_exists('getEnabledModules') ? getEnabledModules() : [];
$module_state = [];
foreach ($modules as $m) {
    $module_state[(string)$m['module_key']] = (bool)(int)$m['is_enabled'];
}

$modules_meta = [
    'bookings'    => [
        'icon'  => 'fas fa-calendar-check',
        'color' => '#2e7d32',
        'bg'    => '#e8f5e9',
        'label' => 'Bookings & Reservations',
        'desc'  => 'Room bookings, calendar, blocked dates, tentative holds, check-in and check-out flows.',
        'warn'  => 'Disabling hides all booking pages and the Bookings nav section.',
    ],
    'housekeeping' => [
        'icon'  => 'fas fa-broom',
        'color' => '#8B7355',
        'bg'    => '#f5f2eb',
        'label' => 'Housekeeping',
        'desc'  => 'Room cleaning schedules, task assignment, and housekeeping reconciliation.',
        'warn'  => null,
    ],
    'pos' => [
        'icon'  => 'fas fa-cash-register',
        'color' => '#8B7355',
        'bg'    => '#fdf8f0',
        'label' => 'POS & Stations',
        'desc'  => 'Point-of-sale till, kitchen display (KDS), bar display (BDS), coffee bar (CDS), room service, deals and offline log.',
        'warn'  => null,
    ],
    'stock' => [
        'icon'  => 'fas fa-boxes',
        'color' => '#1565c0',
        'bg'    => '#e3f2fd',
        'label' => 'Stock Management',
        'desc'  => 'Ingredients, recipes, batch tracking, stock orders, barcode receiving, stock counts and wastage.',
        'warn'  => null,
    ],
    'conference' => [
        'icon'  => 'fas fa-briefcase',
        'color' => '#5e35b1',
        'bg'    => '#ede7f6',
        'label' => 'Conference Rooms',
        'desc'  => 'Conference room management, bookings and inquiry handling.',
        'warn'  => null,
    ],
    'gym' => [
        'icon'  => 'fas fa-dumbbell',
        'color' => '#c62828',
        'bg'    => '#ffebee',
        'label' => 'Gym & Fitness',
        'desc'  => 'Gym packages, membership management and gym inquiry tracking.',
        'warn'  => null,
    ],
    'finance' => [
        'icon'  => 'fas fa-calculator',
        'color' => '#B18247',
        'bg'    => '#fdf3e3',
        'label' => 'Finance & Accounting',
        'desc'  => 'Payments, invoices, receipts, credit notes, quotations, accounting dashboard and reports.',
        'warn'  => 'Disabling hides all Finance nav items including payments and reports.',
    ],
    'website_cms' => [
        'icon'  => 'fas fa-globe',
        'color' => '#0c8d6c',
        'bg'    => '#e8f5f1',
        'label' => 'Website & CMS',
        'desc'  => 'Gallery, media portal, pages, events, reviews, contact inquiries, footer and section headers.',
        'warn'  => null,
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Settings — Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <style>
        .ms-intro {
            background: #fff;
            border: 1px solid #d5cfc4;
            border-radius: 4px;
            padding: 20px 24px;
            margin-bottom: 28px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .ms-intro i { color: #8B7355; font-size: 1.3rem; margin-top: 2px; flex-shrink: 0; }
        .ms-intro p { margin: 0; color: #5a5147; font-size: .9rem; line-height: 1.6; }
        .ms-intro strong { color: #3e3930; }

        .ms-reload-banner {
            background: #fffbeb;
            border: 1px solid #f6c90e;
            border-radius: 4px;
            padding: 11px 18px;
            margin-bottom: 28px;
            font-size: .84rem;
            color: #7a5f00;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 18px;
            margin-bottom: 40px;
        }

        .ms-card {
            background: #fff;
            border: 1px solid #d5cfc4;
            border-radius: 4px;
            padding: 22px 22px 20px;
            display: flex;
            flex-direction: column;
            gap: 0;
            transition: box-shadow .2s, border-color .2s;
            position: relative;
        }
        .ms-card:hover { box-shadow: 0 4px 18px rgba(70,60,50,.10); border-color: #c4b89a; }
        .ms-card.is-disabled { opacity: .72; }

        .ms-card-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 14px;
        }
        .ms-card-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .ms-card-title-block { flex: 1; min-width: 0; }
        .ms-card-title { font-size: .98rem; font-weight: 600; color: #3e3930; margin: 0 0 3px; line-height: 1.3; }
        .ms-card-desc { font-size: .81rem; color: #7a6f63; line-height: 1.55; margin: 0; }

        .ms-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid #ede8e0;
        }
        .ms-status-label { font-size: .8rem; font-weight: 500; color: #8a7f73; }
        .ms-status-label.enabled  { color: #2e7d32; }
        .ms-status-label.disabled { color: #9e4040; }

        /* Toggle switch */
        .ms-toggle { position: relative; display: inline-block; width: 50px; height: 26px; }
        .ms-toggle input { opacity: 0; width: 0; height: 0; }
        .ms-toggle-slider {
            position: absolute; inset: 0;
            background: #d5cfc4; border-radius: 26px;
            cursor: pointer;
            transition: background .25s;
        }
        .ms-toggle-slider::before {
            content: '';
            position: absolute;
            width: 20px; height: 20px;
            left: 3px; top: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform .25s;
            box-shadow: 0 1px 4px rgba(0,0,0,.18);
        }
        .ms-toggle input:checked + .ms-toggle-slider { background: #4CAF50; }
        .ms-toggle input:checked + .ms-toggle-slider::before { transform: translateX(24px); }
        .ms-toggle input:disabled + .ms-toggle-slider { opacity: .5; cursor: not-allowed; }

        /* Warning badge */
        .ms-card-warn {
            background: #fff7ed;
            border: 1px solid #fb923c;
            border-radius: 4px;
            padding: 8px 12px;
            margin-top: 12px;
            font-size: .78rem;
            color: #9a3412;
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }
        .ms-card-warn i { margin-top: 1px; flex-shrink: 0; }
        .ms-card-warn.hidden { display: none; }

        /* Confirm dialog overlay */
        .ms-confirm-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(30,25,20,.45);
            z-index: 9000;
            align-items: center;
            justify-content: center;
        }
        .ms-confirm-overlay.active { display: flex; }
        .ms-confirm-box {
            background: #fff;
            border-radius: 6px;
            padding: 30px 28px 24px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 12px 48px rgba(30,25,20,.22);
        }
        .ms-confirm-box h3 { margin: 0 0 10px; font-size: 1.05rem; color: #3e3930; }
        .ms-confirm-box p  { margin: 0 0 22px; font-size: .88rem; color: #5a5147; line-height: 1.55; }
        .ms-confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .ms-confirm-actions .btn-cancel  { background: #f0ebe3; color: #3e3930; border: 1px solid #d5cfc4; border-radius: 3px; padding: 8px 18px; font-size: .86rem; cursor: pointer; font-weight: 500; }
        .ms-confirm-actions .btn-disable { background: #c0392b; color: #fff; border: none; border-radius: 3px; padding: 8px 18px; font-size: .86rem; cursor: pointer; font-weight: 600; }
        .ms-confirm-actions .btn-cancel:hover  { background: #e8e0d4; }
        .ms-confirm-actions .btn-disable:hover { background: #a93226; }

        @media (max-width: 640px) {
            .ms-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <?php require_once 'includes/admin-flash.php'; ?>

    <div class="content">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-puzzle-piece" style="color:#8B7355;margin-right:10px;"></i>
                Module Settings
            </h1>
        </div>

        <div class="ms-intro">
            <i class="fas fa-info-circle"></i>
            <p>Enable or disable feature modules for this installation. <strong>Disabled modules are hidden from the sidebar navigation and the dashboard.</strong> You can re-enable any module at any time — no data is deleted.</p>
        </div>

        <div class="ms-reload-banner">
            <i class="fas fa-rotate-right"></i>
            Changes take effect immediately. The navigation sidebar updates on your next page load.
        </div>

        <div class="ms-grid">
            <?php foreach ($modules_meta as $key => $meta):
                $enabled = $module_state[$key] ?? true;
            ?>
            <div class="ms-card <?php echo $enabled ? '' : 'is-disabled'; ?>" id="ms-card-<?php echo htmlspecialchars($key); ?>">
                <div class="ms-card-header">
                    <div class="ms-card-icon" style="background:<?php echo htmlspecialchars($meta['bg']); ?>;color:<?php echo htmlspecialchars($meta['color']); ?>;">
                        <i class="<?php echo htmlspecialchars($meta['icon']); ?>"></i>
                    </div>
                    <div class="ms-card-title-block">
                        <div class="ms-card-title"><?php echo htmlspecialchars($meta['label']); ?></div>
                        <p class="ms-card-desc"><?php echo htmlspecialchars($meta['desc']); ?></p>
                    </div>
                </div>

                <?php if ($meta['warn']): ?>
                <div class="ms-card-warn <?php echo $enabled ? 'hidden' : ''; ?>" id="ms-warn-<?php echo htmlspecialchars($key); ?>">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span><?php echo htmlspecialchars($meta['warn']); ?></span>
                </div>
                <?php endif; ?>

                <div class="ms-card-footer">
                    <span class="ms-status-label <?php echo $enabled ? 'enabled' : 'disabled'; ?>" id="ms-label-<?php echo htmlspecialchars($key); ?>">
                        <i class="fas fa-<?php echo $enabled ? 'check-circle' : 'times-circle'; ?>"></i>
                        <?php echo $enabled ? 'Enabled' : 'Disabled'; ?>
                    </span>
                    <label class="ms-toggle" aria-label="Toggle <?php echo htmlspecialchars($meta['label']); ?>">
                        <input type="checkbox"
                               id="ms-toggle-<?php echo htmlspecialchars($key); ?>"
                               data-module="<?php echo htmlspecialchars($key); ?>"
                               data-has-warn="<?php echo $meta['warn'] ? '1' : '0'; ?>"
                               data-warn-text="<?php echo htmlspecialchars((string)$meta['warn']); ?>"
                               data-label="<?php echo htmlspecialchars($meta['label']); ?>"
                               <?php echo $enabled ? 'checked' : ''; ?>>
                        <span class="ms-toggle-slider"></span>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Confirmation dialog for disabling modules with warnings -->
    <div class="ms-confirm-overlay" id="msConfirmOverlay">
        <div class="ms-confirm-box">
            <h3><i class="fas fa-triangle-exclamation" style="color:#f59e0b;margin-right:8px;"></i> Disable Module?</h3>
            <p id="msConfirmText"></p>
            <div class="ms-confirm-actions">
                <button class="btn-cancel" id="msConfirmCancel">Keep Enabled</button>
                <button class="btn-disable" id="msConfirmProceed">Yes, Disable</button>
            </div>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>

    <script>
    (function () {
        var csrf = <?php echo json_encode($csrf_token); ?>;
        var pendingToggle = null;

        function showToast(msg, type) {
            if (typeof Alert !== 'undefined' && Alert.show) {
                Alert.show(msg, type || 'success');
                return;
            }
            // Fallback
            var el = document.createElement('div');
            el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;background:' +
                (type === 'error' ? '#c0392b' : '#2e7d32') +
                ';color:#fff;padding:12px 20px;border-radius:6px;font-size:.88rem;box-shadow:0 4px 16px rgba(0,0,0,.18);';
            el.textContent = msg;
            document.body.appendChild(el);
            setTimeout(function () { el.remove(); }, 3500);
        }

        function doToggle(checkbox, moduleKey, enable) {
            checkbox.disabled = true;
            var card  = document.getElementById('ms-card-' + moduleKey);
            var label = document.getElementById('ms-label-' + moduleKey);
            var warn  = document.getElementById('ms-warn-' + moduleKey);

            var fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('module_key', moduleKey);
            fd.append('is_enabled', enable ? '1' : '0');

            fetch('api/toggle-module.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        checkbox.checked = !enable; // revert
                        showToast(data.error || 'Failed to save.', 'error');
                    } else {
                        if (label) {
                            label.className = 'ms-status-label ' + (enable ? 'enabled' : 'disabled');
                            label.innerHTML = '<i class="fas fa-' + (enable ? 'check-circle' : 'times-circle') + '"></i> ' + (enable ? 'Enabled' : 'Disabled');
                        }
                        if (card) { card.classList.toggle('is-disabled', !enable); }
                        if (warn) { warn.classList.toggle('hidden', enable); }
                        showToast((enable ? 'Enabled' : 'Disabled') + ': ' + checkbox.getAttribute('data-label'), enable ? 'success' : 'info');
                    }
                })
                .catch(function () {
                    checkbox.checked = !enable;
                    showToast('Network error — please try again.', 'error');
                })
                .finally(function () { checkbox.disabled = false; });
        }

        document.querySelectorAll('.ms-toggle input[type="checkbox"]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var key    = cb.getAttribute('data-module');
                var enable = cb.checked;
                var hasWarn = cb.getAttribute('data-has-warn') === '1';

                if (!enable && hasWarn) {
                    // Show confirmation before disabling
                    pendingToggle = { cb: cb, key: key };
                    var overlay = document.getElementById('msConfirmOverlay');
                    var text    = document.getElementById('msConfirmText');
                    if (text) {
                        text.textContent = 'Disabling "' + cb.getAttribute('data-label') +
                            '" will hide its pages and navigation links. ' + cb.getAttribute('data-warn-text') +
                            ' You can re-enable it at any time.';
                    }
                    if (overlay) { overlay.classList.add('active'); }
                    cb.checked = true; // revert visually until confirmed
                    return;
                }

                doToggle(cb, key, enable);
            });
        });

        var cancelBtn  = document.getElementById('msConfirmCancel');
        var proceedBtn = document.getElementById('msConfirmProceed');
        var overlay    = document.getElementById('msConfirmOverlay');

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                pendingToggle = null;
                if (overlay) { overlay.classList.remove('active'); }
            });
        }

        if (proceedBtn) {
            proceedBtn.addEventListener('click', function () {
                if (overlay) { overlay.classList.remove('active'); }
                if (pendingToggle) {
                    pendingToggle.cb.checked = false;
                    doToggle(pendingToggle.cb, pendingToggle.key, false);
                    pendingToggle = null;
                }
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    pendingToggle = null;
                    overlay.classList.remove('active');
                }
            });
        }
    })();
    </script>
</body>
</html>
