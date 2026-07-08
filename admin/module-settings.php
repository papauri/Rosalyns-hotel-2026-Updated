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
if (!hasPermission((int)$user['id'], 'module_settings')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

// Ensure enabled_modules table exists and load current states
$modules = function_exists('getEnabledModules') ? getEnabledModules() : [];
$module_state = [];
foreach ($modules as $m) {
    $module_state[(string)$m['module_key']] = (bool)(int)$m['is_enabled'];
}

// Finance is always locked on — cannot be disabled
$locked_modules = ['finance'];

// Station sub-modules (only active when pos is enabled)
$station_meta = [
    'station_kds'          => ['icon' => 'fas fa-utensils',       'color' => '#c82333', 'label' => 'Kitchen Display (KDS)',     'desc' => 'Kitchen order tickets and prep display.'],
    'station_bds'          => ['icon' => 'fas fa-cocktail',        'color' => '#5e35b1', 'label' => 'Bar Display (BDS)',         'desc' => 'Bar drink orders and display screen.'],
    'station_cds'          => ['icon' => 'fas fa-mug-hot',         'color' => '#6f4e37', 'label' => 'Coffee Bar Display (CDS)', 'desc' => 'Coffee bar orders and display screen.'],
    'station_room_service' => ['icon' => 'fas fa-bell-concierge',  'color' => '#0c8d6c', 'label' => 'Room Service Station',     'desc' => 'In-room dining orders and dashboard.'],
];

$modules_meta = [
    'bookings' => [
        'icon'   => 'fas fa-calendar-check',
        'color'  => '#2e7d32',
        'bg'     => '#e8f5e9',
        'label'  => 'Bookings & Reservations',
        'desc'   => 'Room bookings, calendar, blocked dates, tentative holds, check-in and check-out flows.',
        'warn'   => 'Disabling hides all booking pages and the Bookings nav section.',
        'locked' => false,
    ],
    'housekeeping' => [
        'icon'   => 'fas fa-broom',
        'color'  => '#8B7355',
        'bg'     => '#f5f2eb',
        'label'  => 'Housekeeping',
        'desc'   => 'Room cleaning schedules, task assignment, and housekeeping reconciliation.',
        'warn'   => null,
        'locked' => false,
    ],
    'pos' => [
        'icon'   => 'fas fa-cash-register',
        'color'  => '#8B7355',
        'bg'     => '#fdf8f0',
        'label'  => 'POS & Stations',
        'desc'   => 'Point-of-sale till, deals, offline log and station displays. Configure individual stations below.',
        'warn'   => null,
        'locked' => false,
    ],
    'stock' => [
        'icon'   => 'fas fa-boxes',
        'color'  => '#1565c0',
        'bg'     => '#e3f2fd',
        'label'  => 'Stock Management',
        'desc'   => 'Ingredients, recipes, batch tracking, stock orders, barcode receiving, stock counts and wastage.',
        'warn'   => null,
        'locked' => false,
    ],
    'conference' => [
        'icon'   => 'fas fa-briefcase',
        'color'  => '#5e35b1',
        'bg'     => '#ede7f6',
        'label'  => 'Conference Rooms',
        'desc'   => 'Conference room management, bookings and inquiry handling.',
        'warn'   => null,
        'locked' => false,
    ],
    'gym' => [
        'icon'   => 'fas fa-dumbbell',
        'color'  => '#c62828',
        'bg'     => '#ffebee',
        'label'  => 'Gym & Fitness',
        'desc'   => 'Gym packages, membership management and gym inquiry tracking.',
        'warn'   => null,
        'locked' => false,
    ],
    'finance' => [
        'icon'   => 'fas fa-calculator',
        'color'  => '#B18247',
        'bg'     => '#fdf3e3',
        'label'  => 'Finance & Accounting',
        'desc'   => 'Payments, invoices, receipts, credit notes, quotations, accounting dashboard, reports and end-of-day.',
        'warn'   => null,
        'locked' => true,
    ],
    'website_cms' => [
        'icon'   => 'fas fa-globe',
        'color'  => '#0c8d6c',
        'bg'     => '#e8f5f1',
        'label'  => 'Website & CMS',
        'desc'   => 'Gallery, media portal, pages, events, reviews, contact inquiries, footer and section headers.',
        'warn'   => null,
        'locked' => false,
    ],
];

// Business presets — define which modules are ON for each business type
// finance is always 1 and the API will enforce that
require_once __DIR__ . '/includes/module-presets.php';
$presets = getBusinessPresets();

// Guest-facing page flags (site settings, not enabled_modules rows) — surfaced
// in the UI below and included in the preset "Active" match.
$front_end_state = [
    'events_page'     => getSetting('events_system_enabled', '1') === '1',
    'restaurant_page' => getSetting('restaurant_system_enabled', '1') === '1',
];

$front_end_meta = [
    'events_page' => [
        'icon'  => 'fas fa-calendar-day',
        'color' => '#B18247',
        'label' => 'Events Page',
        'desc'  => 'Guest events page, event bookings and the admin events inbox.',
    ],
    'restaurant_page' => [
        'icon'  => 'fas fa-utensils',
        'color' => '#c82333',
        'label' => 'Restaurant Page',
        'desc'  => 'Guest restaurant page, dine-in tables, recipes and station reports.',
    ],
];

// Work out which preset (if any) matches the installation's current module state,
// so the matching preset button can be highlighted as "Active". Front-end page
// flags are part of the match — a preset isn't "Active" if e.g. events differs.
$current_module_snapshot = [];
foreach (array_merge(array_keys($modules_meta), array_keys($station_meta)) as $mk) {
    $current_module_snapshot[$mk] = ($module_state[$mk] ?? true) ? 1 : 0;
}
$active_preset_key = null;
foreach ($presets as $preset_key => $preset) {
    if (empty(array_diff_assoc($preset['modules'], $current_module_snapshot))
        && empty(array_diff_assoc($current_module_snapshot, $preset['modules']))) {
        $fe_match = true;
        foreach (($preset['front_end'] ?? []) as $fe_key => $fe_val) {
            if (isset($front_end_state[$fe_key]) && ((int)$front_end_state[$fe_key]) !== (int)$fe_val) {
                $fe_match = false;
                break;
            }
        }
        if ($fe_match) {
            $active_preset_key = $preset_key;
            break;
        }
    }
}
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
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        /* ── Presets ─────────────────────────────────────── */
        .ms-presets-card {
            background: #fff;
            border: 1px solid #d5cfc4;
            border-radius: 4px;
            padding: 22px 24px 20px;
            margin-bottom: 28px;
        }
        .ms-presets-heading {
            font-size: .82rem;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: #8B7355;
            margin: 0 0 6px;
        }
        .ms-presets-sub {
            font-size: .84rem;
            color: #7a6f63;
            margin: 0 0 18px;
        }
        .ms-presets-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .ms-preset-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border: 1.5px solid #d5cfc4;
            border-radius: 4px;
            background: #faf8f4;
            color: #3e3930;
            font-size: .84rem;
            font-weight: 500;
            cursor: pointer;
            transition: border-color .18s, background .18s, color .18s;
            user-select: none;
        }
        .ms-preset-btn:hover { border-color: #8B7355; background: #f5f0e8; color: #3e3930; }
        .ms-preset-btn.is-active-preset {
            border-color: #2e7d32;
            background: #e8f5e9;
        }
        .ms-preset-btn.is-active-preset:hover { border-color: #2e7d32; background: #ddf0de; }
        .ms-preset-active-badge {
            display: inline-flex; align-items: center; gap: 4px;
            background: #2e7d32; color: #fff;
            border-radius: 3px; font-size: .64rem; font-weight: 700;
            letter-spacing: .05em; text-transform: uppercase;
            padding: 1px 6px; margin-left: 6px;
        }
        .ms-preset-btn:active { background: #ede5d6; }
        .ms-preset-btn i { color: #8B7355; font-size: .9rem; }
        .ms-preset-btn .ms-preset-desc {
            font-size: .74rem;
            color: #9a8f82;
            display: block;
            font-weight: 400;
            line-height: 1.2;
            margin-top: 1px;
        }
        .ms-preset-btn-inner { display: flex; flex-direction: column; }

        /* ── Intro / reload banner ───────────────────────── */
        .ms-intro {
            background: #fff;
            border: 1px solid #d5cfc4;
            border-radius: 4px;
            padding: 18px 22px;
            margin-bottom: 22px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .ms-intro i { color: #8B7355; font-size: 1.2rem; margin-top: 2px; flex-shrink: 0; }
        .ms-intro p { margin: 0; color: #5a5147; font-size: .88rem; line-height: 1.6; }
        .ms-intro strong { color: #3e3930; }

        .ms-reload-banner {
            background: #fffbeb;
            border: 1px solid #f6c90e;
            border-radius: 4px;
            padding: 10px 16px;
            margin-bottom: 22px;
            font-size: .82rem;
            color: #7a5f00;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ── Module grid ─────────────────────────────────── */
        .ms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
            gap: 16px;
            margin-bottom: 40px;
        }

        .ms-card {
            background: #fff;
            border: 1px solid #d5cfc4;
            border-radius: 4px;
            padding: 20px 20px 18px;
            display: flex;
            flex-direction: column;
            transition: box-shadow .2s, border-color .2s;
        }
        .ms-card:hover { box-shadow: 0 4px 18px rgba(70,60,50,.10); border-color: #c4b89a; }
        .ms-card.is-disabled { opacity: .68; }
        .ms-card.is-locked { border-color: #C8A45A; background: #fffdf8; }

        .ms-card-header {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            margin-bottom: 12px;
        }
        .ms-card-icon {
            width: 42px; height: 42px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0;
        }
        .ms-card-title-block { flex: 1; min-width: 0; }
        .ms-card-title {
            font-size: .96rem; font-weight: 600; color: #3e3930;
            margin: 0 0 3px; line-height: 1.3;
            display: flex; align-items: center; gap: 7px;
        }
        .ms-core-badge {
            display: inline-flex; align-items: center; gap: 4px;
            background: #fdf3e3; color: #B18247;
            border: 1px solid #e8c98a; border-radius: 3px;
            font-size: .68rem; font-weight: 700; letter-spacing: .05em;
            padding: 1px 6px; text-transform: uppercase;
        }
        .ms-card-desc { font-size: .8rem; color: #7a6f63; line-height: 1.55; margin: 0; }

        .ms-card-footer {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: 14px; padding-top: 12px; border-top: 1px solid #ede8e0;
        }
        .ms-status-label { font-size: .79rem; font-weight: 500; color: #8a7f73; }
        .ms-status-label.enabled  { color: #2e7d32; }
        .ms-status-label.disabled { color: #9e4040; }
        .ms-status-label.locked   { color: #B18247; }

        /* Toggle switch */
        .ms-toggle { position: relative; display: inline-block; width: 48px; height: 25px; }
        .ms-toggle input { opacity: 0; width: 0; height: 0; }
        .ms-toggle-slider {
            position: absolute; inset: 0;
            background: #d5cfc4; border-radius: 25px; cursor: pointer;
            transition: background .22s;
        }
        .ms-toggle-slider::before {
            content: '';
            position: absolute;
            width: 19px; height: 19px; left: 3px; top: 3px;
            background: #fff; border-radius: 50%;
            transition: transform .22s;
            box-shadow: 0 1px 4px rgba(0,0,0,.18);
        }
        .ms-toggle input:checked + .ms-toggle-slider { background: #4CAF50; }
        .ms-toggle input:checked + .ms-toggle-slider::before { transform: translateX(23px); }
        .ms-toggle input:disabled + .ms-toggle-slider { opacity: .55; cursor: not-allowed; }

        /* Warning badge */
        .ms-card-warn {
            background: #fff7ed; border: 1px solid #fb923c; border-radius: 4px;
            padding: 7px 10px; margin-top: 10px; font-size: .77rem; color: #9a3412;
            display: flex; gap: 7px; align-items: flex-start;
        }
        .ms-card-warn i { margin-top: 1px; flex-shrink: 0; }
        .ms-card-warn.hidden { display: none; }

        /* Confirm overlay */
        .ms-confirm-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(30,25,20,.45); z-index: 9000;
            align-items: center; justify-content: center;
        }
        .ms-confirm-overlay.active { display: flex; }
        .ms-confirm-box {
            background: #fff; border-radius: 6px; padding: 28px 26px 22px;
            max-width: 420px; width: 92%;
            box-shadow: 0 12px 48px rgba(30,25,20,.22);
        }
        .ms-confirm-box h3 { margin: 0 0 10px; font-size: 1.02rem; color: #3e3930; }
        .ms-confirm-box p  { margin: 0 0 20px; font-size: .87rem; color: #5a5147; line-height: 1.55; }
        .ms-confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .ms-confirm-actions .btn-cancel  { background: #f0ebe3; color: #3e3930; border: 1px solid #d5cfc4; border-radius: 3px; padding: 8px 16px; font-size: .85rem; cursor: pointer; font-weight: 500; }
        .ms-confirm-actions .btn-disable { background: #c0392b; color: #fff; border: none; border-radius: 3px; padding: 8px 16px; font-size: .85rem; cursor: pointer; font-weight: 600; }
        .ms-confirm-actions .btn-cancel:hover  { background: #e8e0d4; }
        .ms-confirm-actions .btn-disable:hover { background: #a93226; }

        /* Preset applying state */
        .ms-preset-btn.applying { opacity: .6; pointer-events: none; }
        .ms-section-title {
            font-size: .82rem; font-weight: 700; letter-spacing: .07em;
            text-transform: uppercase; color: #8B7355; margin: 0 0 14px;
        }

        /* ── Station sub-panel ───────────────────────────── */
        .ms-stations-panel {
            margin-top: 14px;
            border-top: 1px solid #ede8e0;
            padding-top: 10px;
        }
        .ms-stations-toggle {
            display: flex; align-items: center; gap: 7px;
            background: none; border: none; padding: 4px 0; cursor: pointer;
            font-size: .8rem; font-weight: 600; color: #8B7355;
            width: 100%;
        }
        .ms-stations-toggle:hover { color: #6e5a3e; }
        .ms-stations-chevron { margin-left: auto; font-size: .7rem; transition: transform .2s; }
        .ms-stations-toggle[aria-expanded="true"] .ms-stations-chevron { transform: rotate(180deg); }

        .ms-stations-body {
            margin-top: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .ms-station-row {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: 8px 10px;
            background: #faf8f4; border: 1px solid #ede8e0; border-radius: 4px;
        }
        .ms-station-left { display: flex; align-items: flex-start; gap: 10px; flex: 1; min-width: 0; }
        .ms-station-name { font-size: .8rem; font-weight: 600; color: #3e3930; line-height: 1.2; }
        .ms-station-desc { font-size: .74rem; color: #9a8f82; margin-top: 1px; }
        .ms-station-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

        @media (max-width: 640px) {
            .ms-grid { grid-template-columns: 1fr; }
            .ms-presets-row { flex-direction: column; }
            .ms-preset-btn { width: 100%; }
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
            <p>Configure which modules are active for this installation. <strong>Disabled modules are hidden from the sidebar and dashboard</strong> — no data is deleted. Finance &amp; Accounting is always on and cannot be disabled.</p>
        </div>

        <!-- Business type presets -->
        <div class="ms-presets-card">
            <div class="ms-presets-heading"><i class="fas fa-bolt" style="margin-right:6px;"></i>Quick Setup — Business Presets</div>
            <p class="ms-presets-sub">Select a business type to automatically enable the right modules in one click.</p>
            <div class="ms-presets-row">
                <?php foreach ($presets as $preset_key => $preset):
                    $is_active_preset = ($preset_key === $active_preset_key);
                ?>
                <button type="button"
                        class="ms-preset-btn<?php echo $is_active_preset ? ' is-active-preset' : ''; ?>"
                        data-preset="<?php echo htmlspecialchars($preset_key); ?>"
                        data-preset-label="<?php echo htmlspecialchars($preset['label']); ?>"
                        data-modules="<?php echo htmlspecialchars(json_encode($preset['modules'])); ?>"
                        title="<?php echo htmlspecialchars($preset['desc']); ?>">
                    <i class="<?php echo htmlspecialchars($preset['icon']); ?>"></i>
                    <span class="ms-preset-btn-inner">
                        <span><?php echo htmlspecialchars($preset['label']); ?><?php if ($is_active_preset): ?><span class="ms-preset-active-badge"><i class="fas fa-check"></i> Active</span><?php endif; ?></span>
                        <span class="ms-preset-desc"><?php echo htmlspecialchars($preset['desc']); ?></span>
                    </span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ms-reload-banner">
            <i class="fas fa-rotate-right"></i>
            Changes take effect immediately. The navigation sidebar updates on your next page load.
        </div>

        <div class="ms-section-title">Individual Modules</div>

        <div class="ms-grid">
            <?php foreach ($modules_meta as $key => $meta):
                $enabled = $module_state[$key] ?? true;
                $locked  = $meta['locked'];
                $pos_on  = ($module_state['pos'] ?? true);
            ?>
            <div class="ms-card <?php echo $locked ? 'is-locked' : ($enabled ? '' : 'is-disabled'); ?>" id="ms-card-<?php echo htmlspecialchars($key); ?>">
                <div class="ms-card-header">
                    <div class="ms-card-icon" style="background:<?php echo htmlspecialchars($meta['bg']); ?>;color:<?php echo htmlspecialchars($meta['color']); ?>;">
                        <i class="<?php echo htmlspecialchars($meta['icon']); ?>"></i>
                    </div>
                    <div class="ms-card-title-block">
                        <div class="ms-card-title">
                            <?php echo htmlspecialchars($meta['label']); ?>
                            <?php if ($locked): ?>
                            <span class="ms-core-badge"><i class="fas fa-lock"></i> Core</span>
                            <?php endif; ?>
                        </div>
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
                    <span class="ms-status-label <?php echo $locked ? 'locked' : ($enabled ? 'enabled' : 'disabled'); ?>" id="ms-label-<?php echo htmlspecialchars($key); ?>">
                        <?php if ($locked): ?>
                            <i class="fas fa-lock"></i> Always On
                        <?php else: ?>
                            <i class="fas fa-<?php echo $enabled ? 'check-circle' : 'times-circle'; ?>"></i>
                            <?php echo $enabled ? 'Enabled' : 'Disabled'; ?>
                        <?php endif; ?>
                    </span>
                    <label class="ms-toggle" aria-label="Toggle <?php echo htmlspecialchars($meta['label']); ?>">
                        <input type="checkbox"
                               id="ms-toggle-<?php echo htmlspecialchars($key); ?>"
                               data-module="<?php echo htmlspecialchars($key); ?>"
                               data-has-warn="<?php echo $meta['warn'] ? '1' : '0'; ?>"
                               data-warn-text="<?php echo htmlspecialchars((string)$meta['warn']); ?>"
                               data-label="<?php echo htmlspecialchars($meta['label']); ?>"
                               <?php echo ($enabled || $locked) ? 'checked' : ''; ?>
                               <?php echo $locked ? 'disabled' : ''; ?>>
                        <span class="ms-toggle-slider"></span>
                    </label>
                </div>

                <?php if ($key === 'pos'): ?>
                <!-- Station sub-modules — only relevant when POS is on -->
                <div class="ms-stations-panel" id="ms-stations-panel" style="<?php echo $enabled ? '' : 'opacity:.45;pointer-events:none;'; ?>">
                    <button type="button" class="ms-stations-toggle" id="msStationsToggle" aria-expanded="false">
                        <i class="fas fa-display" style="color:#8B7355;"></i>
                        <span>Configure Stations</span>
                        <i class="fas fa-chevron-down ms-stations-chevron"></i>
                    </button>
                    <div class="ms-stations-body" id="msStationsBody" hidden>
                        <?php foreach ($station_meta as $skey => $smeta):
                            $senabled = $module_state[$skey] ?? true;
                        ?>
                        <div class="ms-station-row" id="ms-card-<?php echo htmlspecialchars($skey); ?>">
                            <div class="ms-station-left">
                                <i class="<?php echo htmlspecialchars($smeta['icon']); ?>" style="color:<?php echo htmlspecialchars($smeta['color']); ?>;width:18px;text-align:center;"></i>
                                <div>
                                    <div class="ms-station-name"><?php echo htmlspecialchars($smeta['label']); ?></div>
                                    <div class="ms-station-desc"><?php echo htmlspecialchars($smeta['desc']); ?></div>
                                </div>
                            </div>
                            <div class="ms-station-right">
                                <span class="ms-status-label <?php echo $senabled ? 'enabled' : 'disabled'; ?>" id="ms-label-<?php echo htmlspecialchars($skey); ?>" style="font-size:.74rem;">
                                    <i class="fas fa-<?php echo $senabled ? 'check-circle' : 'times-circle'; ?>"></i>
                                    <?php echo $senabled ? 'On' : 'Off'; ?>
                                </span>
                                <label class="ms-toggle" style="width:40px;height:22px;" aria-label="Toggle <?php echo htmlspecialchars($smeta['label']); ?>">
                                    <input type="checkbox"
                                           id="ms-toggle-<?php echo htmlspecialchars($skey); ?>"
                                           data-module="<?php echo htmlspecialchars($skey); ?>"
                                           data-has-warn="0"
                                           data-warn-text=""
                                           data-label="<?php echo htmlspecialchars($smeta['label']); ?>"
                                           <?php echo $senabled ? 'checked' : ''; ?>>
                                    <span class="ms-toggle-slider" style="border-radius:22px;"></span>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="ms-section-title">Guest Website Pages</div>

        <div class="ms-grid">
            <?php foreach ($front_end_meta as $fe_key => $fe_meta):
                $fe_enabled = $front_end_state[$fe_key];
            ?>
            <div class="ms-card <?php echo $fe_enabled ? '' : 'is-disabled'; ?>" id="ms-card-<?php echo htmlspecialchars($fe_key); ?>">
                <div class="ms-card-header">
                    <div class="ms-card-icon" style="background:#fdf8f0;color:<?php echo htmlspecialchars($fe_meta['color']); ?>;">
                        <i class="<?php echo htmlspecialchars($fe_meta['icon']); ?>"></i>
                    </div>
                    <div class="ms-card-title-block">
                        <div class="ms-card-title"><?php echo htmlspecialchars($fe_meta['label']); ?></div>
                        <p class="ms-card-desc"><?php echo htmlspecialchars($fe_meta['desc']); ?></p>
                    </div>
                </div>
                <div class="ms-card-footer">
                    <span class="ms-status-label <?php echo $fe_enabled ? 'enabled' : 'disabled'; ?>" id="ms-label-<?php echo htmlspecialchars($fe_key); ?>">
                        <i class="fas fa-<?php echo $fe_enabled ? 'check-circle' : 'times-circle'; ?>"></i>
                        <?php echo $fe_enabled ? 'Enabled' : 'Disabled'; ?>
                    </span>
                    <label class="ms-toggle" aria-label="Toggle <?php echo htmlspecialchars($fe_meta['label']); ?>">
                        <input type="checkbox"
                               id="ms-toggle-<?php echo htmlspecialchars($fe_key); ?>"
                               data-module="<?php echo htmlspecialchars($fe_key); ?>"
                               data-has-warn="0"
                               data-warn-text=""
                               data-label="<?php echo htmlspecialchars($fe_meta['label']); ?>"
                               <?php echo $fe_enabled ? 'checked' : ''; ?>>
                        <span class="ms-toggle-slider"></span>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Confirmation dialog for modules with warnings -->
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

    <!-- Preset impact dialog — shows which users lose access before a preset is applied -->
    <div class="ms-confirm-overlay" id="msPresetImpactOverlay">
        <div class="ms-confirm-box" style="max-width:520px;">
            <h3><i class="fas fa-users" style="color:#B18247;margin-right:8px;"></i> Apply "<span id="msPresetImpactName"></span>" preset?</h3>
            <p id="msPresetImpactSummary" style="margin-bottom:12px;"></p>
            <div id="msPresetImpactUsers" style="max-height:280px;overflow-y:auto;margin-bottom:16px;"></div>
            <div class="ms-confirm-actions">
                <button class="btn-cancel" id="msPresetImpactCancel">Cancel</button>
                <button class="btn-disable" id="msPresetImpactProceed" style="background:#8B7355;">Apply Preset</button>
            </div>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>

    <script>
    (function () {
        // Per-render state. The SPA re-executes this inline script on every
        // navigation to this page; state is refreshed each time, while the
        // event listeners below are DELEGATED on document and bound exactly
        // once — so preset/toggle clicks work on the first render, whether
        // the page arrived via full load or SPA swap.
        window.__msState = window.__msState || {};
        window.__msState.csrf = <?php echo json_encode($csrf_token); ?>;
        window.__msState.moduleLabels = <?php echo json_encode(array_combine(array_keys($modules_meta), array_column($modules_meta, 'label'))); ?>;
        window.__msState.pendingToggle = null;
        window.__msState.pendingPreset = null;

        if (window.__msBound) { return; }
        window.__msBound = true;
        var S = window.__msState;

        function showToast(msg, type) {
            if (typeof Alert !== 'undefined' && Alert.show) {
                Alert.show(msg, type || 'success');
                return;
            }
            var el = document.createElement('div');
            el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;background:' +
                (type === 'error' ? '#c0392b' : type === 'info' ? '#8B7355' : '#2e7d32') +
                ';color:#fff;padding:12px 20px;border-radius:6px;font-size:.87rem;box-shadow:0 4px 16px rgba(0,0,0,.18);max-width:320px;';
            el.textContent = msg;
            document.body.appendChild(el);
            setTimeout(function () { el.remove(); }, 3500);
        }

        var stationKeys = ['station_kds', 'station_bds', 'station_cds', 'station_room_service'];

        function updateCard(moduleKey, enable) {
            var card  = document.getElementById('ms-card-' + moduleKey);
            var label = document.getElementById('ms-label-' + moduleKey);
            var warn  = document.getElementById('ms-warn-' + moduleKey);
            var cb    = document.getElementById('ms-toggle-' + moduleKey);
            if (cb && !cb.disabled) { cb.checked = enable; }
            var isStation = stationKeys.indexOf(moduleKey) !== -1;
            if (label) {
                label.className = 'ms-status-label ' + (enable ? 'enabled' : 'disabled');
                label.innerHTML = '<i class="fas fa-' + (enable ? 'check-circle' : 'times-circle') + '"></i> ' + (enable ? (isStation ? 'On' : 'Enabled') : (isStation ? 'Off' : 'Disabled'));
            }
            if (card) { card.classList.toggle('is-disabled', !enable); }
            if (warn) { warn.classList.toggle('hidden', enable); }
        }

        function clearActivePresetHighlight() {
            document.querySelectorAll('.ms-preset-btn').forEach(function (other) {
                var badge = other.querySelector('.ms-preset-active-badge');
                if (badge) { badge.remove(); }
                other.classList.remove('is-active-preset');
            });
        }

        function doToggle(checkbox, moduleKey, enable, silent) {
            if (checkbox) { checkbox.disabled = true; }

            var fd = new FormData();
            fd.append('csrf_token', S.csrf);
            fd.append('module_key', moduleKey);
            fd.append('is_enabled', enable ? '1' : '0');

            return fetch('api/toggle-module.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        if (checkbox) { checkbox.checked = !enable; }
                        if (!silent) { showToast(data.error || 'Failed to save.', 'error'); }
                        return false;
                    }
                    updateCard(moduleKey, enable);
                    clearActivePresetHighlight();
                    if (!silent) {
                        showToast((enable ? 'Enabled' : 'Disabled') + ': ' + (checkbox ? checkbox.getAttribute('data-label') : moduleKey) + ' — refreshing admin view...', enable ? 'success' : 'info');
                        // Same reasoning as the preset-apply flow: the sidebar nav is
                        // rendered server-side and won't reflect this change until
                        // the page reloads.
                        setTimeout(function () { window.location.reload(); }, 900);
                    }
                    return true;
                })
                .catch(function () {
                    if (checkbox) { checkbox.checked = !enable; }
                    if (!silent) { showToast('Network error — please try again.', 'error'); }
                    return false;
                })
                .finally(function () { if (checkbox) { checkbox.disabled = false; } });
        }

        // Module toggle switches — delegated
        function handleToggleChange(cb) {
            var key     = cb.getAttribute('data-module');
            var enable  = cb.checked;
            var hasWarn = cb.getAttribute('data-has-warn') === '1';

            // POS toggle also dims/undims the station sub-panel
            if (key === 'pos') {
                var stationsPanel = document.getElementById('ms-stations-panel');
                if (stationsPanel) {
                    stationsPanel.style.opacity = enable ? '' : '.45';
                    stationsPanel.style.pointerEvents = enable ? '' : 'none';
                }
            }

            if (!enable && hasWarn) {
                S.pendingToggle = { cb: cb, key: key };
                var overlay = document.getElementById('msConfirmOverlay');
                var text    = document.getElementById('msConfirmText');
                if (text) {
                    text.textContent = 'Disabling "' + cb.getAttribute('data-label') +
                        '" will hide its pages and navigation links. ' + cb.getAttribute('data-warn-text') +
                        ' You can re-enable it at any time.';
                }
                if (overlay) { overlay.classList.add('active'); }
                cb.checked = true;
                return;
            }
            doToggle(cb, key, enable, false);
        }

        function applyPresetConfig(config, label, btn, presetKey) {
            btn.classList.add('applying');

            var fd = new FormData();
            fd.append('csrf_token', S.csrf);
            fd.append('preset_key', presetKey);

            fetch('api/apply-preset.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    btn.classList.remove('applying');

                    if (!data.success) {
                        showToast(data.error || 'Failed to apply preset.', 'error');
                        return;
                    }

                    Object.keys(data.applied_modules || config).forEach(function (key) {
                        updateCard(key, !!(data.applied_modules ? data.applied_modules[key] : config[key]));
                    });

                    clearActivePresetHighlight();
                    btn.classList.add('is-active-preset');
                    var nameSpan = btn.querySelector('.ms-preset-btn-inner > span:first-child');
                    if (nameSpan) {
                        var newBadge = document.createElement('span');
                        newBadge.className = 'ms-preset-active-badge';
                        newBadge.innerHTML = '<i class="fas fa-check"></i> Active';
                        nameSpan.appendChild(newBadge);
                    }
                    showToast('Preset applied: ' + label + ' — refreshing admin view...', 'success');
                    // The sidebar nav (admin-header.php) and any other module-gated
                    // chrome on this page were rendered server-side at page load and
                    // won't reflect the new module state from an AJAX-only update —
                    // reload so the admin's whole view matches the preset immediately,
                    // without the admin having to manually refresh.
                    setTimeout(function () { window.location.reload(); }, 900);
                })
                .catch(function () {
                    btn.classList.remove('applying');
                    showToast('Network error — preset was not applied. Please try again.', 'error');
                });
        }

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.textContent = String(str == null ? '' : str);
            return div.innerHTML;
        }

        var extraModuleLabels = {
            station_kds: 'Kitchen Display (KDS)', station_bds: 'Bar Display (BDS)',
            station_cds: 'Coffee Bar Display (CDS)', station_room_service: 'Room Service Station',
            restaurant_page: 'Restaurant / food-service pages',
            events_page: 'Events page & event bookings'
        };
        function moduleLabel(key) { return S.moduleLabels[key] || extraModuleLabels[key] || key; }

        function renderPresetImpact(presetKey, presetLabel, data) {
            var nameEl    = document.getElementById('msPresetImpactName');
            var summaryEl = document.getElementById('msPresetImpactSummary');
            var usersEl   = document.getElementById('msPresetImpactUsers');
            if (nameEl) { nameEl.textContent = presetLabel; }

            var disabledLabels = (data.modules_disabled || []).map(moduleLabel);
            var enabledLabels  = (data.modules_enabled  || []).map(moduleLabel);

            if (summaryEl) {
                var parts = [];
                if (enabledLabels.length)  { parts.push('Turns ON: ' + enabledLabels.join(', ') + '.'); }
                if (disabledLabels.length) { parts.push('Turns OFF: ' + disabledLabels.join(', ') + '.'); }
                summaryEl.textContent = parts.length ? parts.join(' ') : 'This preset matches the current module state — nothing changes.';
            }

            if (usersEl) {
                var menuHtml = '';
                var mkList = function (title, icon, color, items) {
                    if (!items || !items.length) { return ''; }
                    return '<p style="font-size:.78rem;font-weight:700;color:' + color + ';margin:0 0 4px;"><i class="fas ' + icon + '"></i> ' + title + '</p>' +
                        '<ul style="margin:0 0 10px;padding-left:20px;font-size:.78rem;color:#5a5147;">' +
                        items.map(function (m) { return '<li>' + escapeHtml(m) + '</li>'; }).join('') +
                        '</ul>';
                };
                menuHtml += mkList('Menus & pages removed', 'fa-eye-slash', '#9a3412', data.menus_removed);
                menuHtml += mkList('Menus & pages added', 'fa-eye', '#2e7d32', data.menus_added);

                var users = data.affected_users || [];
                if (!users.length) {
                    usersEl.innerHTML = menuHtml + '<p style="font-size:.84rem;color:#2e7d32;margin:0;"><i class="fas fa-circle-check"></i> No active users currently rely on the modules being turned off.</p>';
                } else {
                    var rows = users.map(function (u) {
                        var perms = u.permissions_lost.map(function (p) { return escapeHtml(p.label); }).join(', ');
                        return '<div style="padding:8px 10px;border:1px solid #ede8e0;border-radius:4px;margin-bottom:6px;background:#faf8f4;">' +
                            '<div style="font-size:.85rem;font-weight:600;color:#3e3930;">' + escapeHtml(u.full_name) + ' <span style="font-weight:400;color:#9a8f82;">(' + escapeHtml(u.role) + ')</span></div>' +
                            '<div style="font-size:.76rem;color:#9a3412;margin-top:2px;"><i class="fas fa-triangle-exclamation"></i> Will lose: ' + perms + '</div>' +
                            '</div>';
                    });
                    usersEl.innerHTML = menuHtml +
                        '<p style="font-size:.82rem;color:#9a3412;font-weight:600;margin:0 0 8px;">' + users.length + ' user' + (users.length === 1 ? '' : 's') + ' will lose access to the following:</p>' +
                        rows.join('');
                }
            }
        }

        // Preset button — check impact on existing users, confirm, then apply
        function handlePresetClick(btn) {
            var config = JSON.parse(btn.getAttribute('data-modules') || '{}');
            var label  = btn.getAttribute('data-preset-label') || 'preset';
            var presetKey = btn.getAttribute('data-preset');
            var overlay = document.getElementById('msPresetImpactOverlay');

            btn.classList.add('applying');

            fetch('api/preset-affected-users.php?preset_key=' + encodeURIComponent(presetKey), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    btn.classList.remove('applying');
                    if (!data.success) {
                        showToast(data.error || 'Could not check preset impact.', 'error');
                        return;
                    }
                    renderPresetImpact(presetKey, label, data);
                    S.pendingPreset = { config: config, label: label, btn: btn, presetKey: presetKey };
                    if (overlay) { overlay.classList.add('active'); }
                })
                .catch(function () {
                    btn.classList.remove('applying');
                    showToast('Network error — please try again.', 'error');
                });
        }

        // ── Delegated listeners — bound once, work on every render ──────────
        document.addEventListener('change', function (e) {
            var cb = e.target.closest ? e.target.closest('.ms-toggle input[type="checkbox"]') : null;
            if (!cb || cb.disabled) { return; }
            handleToggleChange(cb);
        });

        document.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) { return; }

            var presetBtn = t.closest('.ms-preset-btn');
            if (presetBtn && !presetBtn.classList.contains('applying')) { handlePresetClick(presetBtn); return; }

            if (t.closest('#msStationsToggle')) {
                var stationsBody = document.getElementById('msStationsBody');
                var stationsToggle = document.getElementById('msStationsToggle');
                if (stationsBody && stationsToggle) {
                    var open = stationsBody.hidden;
                    stationsBody.hidden = !open;
                    stationsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                }
                return;
            }

            var confirmOverlay = document.getElementById('msConfirmOverlay');
            if (t.closest('#msConfirmCancel')) {
                S.pendingToggle = null;
                if (confirmOverlay) { confirmOverlay.classList.remove('active'); }
                return;
            }
            if (t.closest('#msConfirmProceed')) {
                if (confirmOverlay) { confirmOverlay.classList.remove('active'); }
                if (S.pendingToggle) {
                    S.pendingToggle.cb.checked = false;
                    doToggle(S.pendingToggle.cb, S.pendingToggle.key, false, false);
                    S.pendingToggle = null;
                }
                return;
            }
            if (confirmOverlay && t === confirmOverlay) {
                S.pendingToggle = null;
                confirmOverlay.classList.remove('active');
                return;
            }

            var impactOverlay = document.getElementById('msPresetImpactOverlay');
            if (t.closest('#msPresetImpactCancel')) {
                S.pendingPreset = null;
                if (impactOverlay) { impactOverlay.classList.remove('active'); }
                return;
            }
            if (t.closest('#msPresetImpactProceed')) {
                if (impactOverlay) { impactOverlay.classList.remove('active'); }
                if (S.pendingPreset) {
                    applyPresetConfig(S.pendingPreset.config, S.pendingPreset.label, S.pendingPreset.btn, S.pendingPreset.presetKey);
                    S.pendingPreset = null;
                }
                return;
            }
            if (impactOverlay && t === impactOverlay) {
                S.pendingPreset = null;
                impactOverlay.classList.remove('active');
            }
        });
    })();
    </script>
</body>
</html>
