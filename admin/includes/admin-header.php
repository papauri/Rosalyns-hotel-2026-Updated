<?php

/**
 * Admin Header HTML Output
 * Shared header and navbar for admin pages.
 *
 * NOTE: This file outputs HTML. Include admin-init.php FIRST
 * before including this file to ensure proper initialization.
 *
 * Usage:
 *   require_once 'admin-init.php';                  // BEFORE <head>
 *   ... <head> with CSS links ...
 *   require_once 'includes/admin-header.php';       // AFTER <head>
 */

// Load permissions system
require_once __DIR__ . '/permissions.php';

// Resolve the current user from $user (set by admin-init.php) or fall back to session.
if (!isset($user) || !is_array($user)) {
    $user = [
        'id'        => $_SESSION['admin_user_id']   ?? 0,
        'username'  => $_SESSION['admin_username']  ?? '',
        'role'      => $_SESSION['admin_role']      ?? 'guest',
        'full_name' => $_SESSION['admin_full_name'] ?? 'Guest',
    ];
}

// Get the current user's permissions (cached for this request)
$_user_permissions = getUserPermissions((int)($user['id'] ?? 0));

// Without the Dashboard permission a user has no admin portal: on the station-side
// pages they can reach (station report, receipts, room service) the nav only
// offers their station screens and those screens' guides.
$_rh_station_only = !rhHasAdminPortal((int)($user['id'] ?? 0));
$_rh_station_guides = ['01-pos-till.html', '02-kds-kitchen.html', '03-bds-bar.html', '04-cds-coffee.html', '05-room-service.html'];

if (!function_exists('_canShowNavItem')) {
    /**
     * Check if a nav item should be shown for the current user.
     * Empty $permission_key means always-visible.
     */
    function _canShowNavItem(?string $permission_key): bool
    {
        global $_user_permissions;
        if (!$permission_key) return true;
        return isset($_user_permissions[$permission_key]) && $_user_permissions[$permission_key];
    }
}

if (!function_exists('_renderNavLink')) {
    /**
     * Render a single nav <li> if the user has permission and the module is enabled.
     */
    function _renderNavLink(string $href, string $icon, string $label, ?string $perm, string $current_page, string $iconStyle = '', $moduleKey = null): void
    {
        if (!_canShowNavItem($perm)) return;
        if (!empty($GLOBALS['_rh_station_only'])) {
            $_hrefBase = basename((string)(parse_url($href, PHP_URL_PATH) ?: $href));
            $_allowed = str_starts_with($href, '../')
                ? in_array($_hrefBase, $GLOBALS['_rh_station_guides'] ?? [], true)
                : in_array($_hrefBase, rhStationSidePages(), true);
            if (!$_allowed) return;
        }
        if ($moduleKey !== null && function_exists('rh_module_key_enabled')) {
            $keys = is_array($moduleKey) ? $moduleKey : [(string)$moduleKey];
            foreach ($keys as $_mk) {
                if (!rh_module_key_enabled((string)$_mk)) return;
            }
        }
        $hrefPath   = (string)(parse_url($href, PHP_URL_PATH) ?: $href);
        $isActive   = (strpos($href, '../') !== 0 && basename($hrefPath) === $current_page) ? ' active' : '';
        $iconAttr   = $iconStyle !== '' ? ' style="' . htmlspecialchars($iconStyle) . '"' : '';
        // Full-screen station pages and external guides always open in a new tab
        $_newTabPages = ['pos.php', 'kds.php', 'bds.php', 'cds.php'];
        $extra      = (strpos($href, '../') === 0 || in_array(basename($hrefPath), $_newTabPages, true)) ? ' target="_blank" rel="noopener"' : '';
        $linkClass  = 'admin-nav-link' . $isActive;
        $navKey     = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($href)), '-');
        echo '<li class="nav-item" data-nav-key="' . htmlspecialchars($navKey) . '" data-nav-label="' . htmlspecialchars($label) . '">'
            . '<a href="' . htmlspecialchars($href) . '" class="' . $linkClass . '"' . $extra . '>'
            . '<i class="' . htmlspecialchars($icon) . '"' . $iconAttr . '></i> '
            . '<span>' . htmlspecialchars($label) . '</span></a>'
            . '<button type="button" class="nav-favorite-btn" data-nav-key="' . htmlspecialchars($navKey) . '" aria-label="Add ' . htmlspecialchars($label) . ' to favorites" aria-pressed="false" title="Add to favorites">'
            . '<i class="far fa-star"></i>'
            . '</button></li>';
    }
}

// Nav structure: see admin-nav-items.php (shared with the staff guides knowledge base).
require_once __DIR__ . '/admin-nav-items.php';
$_nav_groups = rh_admin_nav_groups();

// Groups that start folded until the user opens them.
$_nav_default_collapsed = ['Help'];

$_nav_group_icons = [
    'Front Desk' => 'fas fa-concierge-bell',
    'Conference & Events' => 'fas fa-briefcase',
    'Restaurant & Bar' => 'fas fa-utensils',
    'Stock' => 'fas fa-boxes',
    'Money' => 'fas fa-coins',
    'Gym' => 'fas fa-dumbbell',
    'Website' => 'fas fa-globe',
    'Settings' => 'fas fa-sliders-h',
    'Help' => 'fas fa-circle-question',
    'External' => 'fas fa-external-link-alt',
];

$current_page = $current_page ?? basename($_SERVER['PHP_SELF']);
$site_name    = $site_name    ?? (function_exists('getSetting') ? (getSetting('site_name') ?: 'Admin') : 'Admin');
$_admin_user_id_for_sw = (int)($user['id'] ?? 0);

$_admin_nav_label_map = [];
foreach ($_nav_groups as $_group_items) {
    foreach ($_group_items as $_item) {
        $_href = (string)($_item[0] ?? '');
        if ($_href === '' || str_starts_with($_href, '../')) {
            continue;
        }
        $_basename = basename(parse_url($_href, PHP_URL_PATH) ?: $_href);
        if ($_basename === '' || !str_ends_with($_basename, '.php')) {
            continue;
        }
        $_admin_nav_label_map[$_basename] = (string)($_item[2] ?? $_basename);
    }
}

$_admin_parent_fallback_map = rh_admin_parent_pages(); // shared with the knowledge base (admin-nav-items.php)

$_admin_parse_parent_target = static function (string $candidate, string $currentPage): ?array {
    $candidate = trim($candidate);
    if ($candidate === '') {
        return null;
    }

    $parts = parse_url($candidate);
    if ($parts === false) {
        return null;
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && $currentHost !== '' && $host !== $currentHost) {
        return null;
    }

    $path = (string)($parts['path'] ?? '');
    if ($path === '') {
        return null;
    }

    $page = basename($path);
    if ($page === '' || !str_ends_with($page, '.php')) {
        return null;
    }
    if ($page === $currentPage || in_array($page, ['login.php', 'logout.php'], true)) {
        return null;
    }

    $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';

    return [
        'page' => $page,
        'href' => $page . $query,
    ];
};

$_admin_is_top_level_page = isset($_admin_nav_label_map[$current_page]);
$_admin_back_target = null;

foreach (['return_to', 'return', 'back_to'] as $_return_key) {
    if (!isset($_GET[$_return_key])) {
        continue;
    }
    $_candidate = $_admin_parse_parent_target((string)$_GET[$_return_key], $current_page);
    if ($_candidate !== null) {
        $_admin_back_target = $_candidate;
        break;
    }
}

if ($_admin_back_target === null && !$_admin_is_top_level_page) {
    $_ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $_candidate = $_admin_parse_parent_target($_ref, $current_page);
    if ($_candidate !== null) {
        $_admin_back_target = $_candidate;
    }
}

if ($_admin_back_target === null && isset($_admin_parent_fallback_map[$current_page])) {
    $_parent_page = (string)$_admin_parent_fallback_map[$current_page];
    $_admin_back_target = [
        'page' => $_parent_page,
        'href' => $_parent_page,
    ];
}

// Never offer a "Back to" a page the user cannot open or, without portal
// access, a page outside the station screens.
if ($_admin_back_target !== null) {
    $_back_page = basename((string)($_admin_back_target['page'] ?? ''));
    if ($_back_page === '' || !rhCanLinkTo((int)($user['id'] ?? 0), $_back_page)) {
        $_admin_back_target = null;
    }
}

$_admin_back_label = '';
if ($_admin_back_target !== null) {
    $_parent_page = (string)($_admin_back_target['page'] ?? '');
    $_parent_label = $_admin_nav_label_map[$_parent_page] ?? ucwords(str_replace('-', ' ', preg_replace('/\.php$/', '', $_parent_page)));
    $_admin_back_label = 'Back to ' . $_parent_label;
}
?>
<script>
    /* no-FOUC: apply saved sidebar state before first CSS paint */
    (function() {
        try {
            var uid = '<?php echo $_admin_user_id_for_sw; ?>';
            var ck = 'rhAdminSidebarCollapsed:v1:' + uid;
            var stored = localStorage.getItem(ck);
            // Auto-collapse to rail on tablet widths (769–1024px) when no preference has been saved
            var autoCollapse = stored === null && window.matchMedia('(min-width: 769px) and (max-width: 1024px)').matches;
            if (stored === '1' || autoCollapse) {
                document.body.classList.add('admin-sidebar-collapsed');
            }
            var wk = 'rhAdminSidebarWidth:v1:' + uid;
            var w = parseInt(localStorage.getItem(wk), 10);
            if (w && w >= 220 && w <= 480) {
                document.documentElement.style.setProperty('--admin-sidebar-width', w + 'px');
            }
        } catch (e) {}
    })();
    // PWA settings: configure install prompt dismiss period
    window.RH_PWA_DISMISS_DAYS = <?php echo (int)getSetting('pwa_install_dismiss_days', '14'); ?>;
    // Inject manifest link if not already in <head> — required for beforeinstallprompt to fire.
    (function() {
        if (document.querySelector('link[rel="manifest"]')) return;

        var manifestLink = document.createElement('link');
        manifestLink.rel = 'manifest';

        var candidates = [];
        try {
            candidates = [
                new URL('manifest.php', window.location.href).toString(),
                new URL('../manifest.php', window.location.href).toString(),
            ];
        } catch (e) {
            candidates = ['manifest.php', '../manifest.php'];
        }

        function attachManifest(href) {
            manifestLink.href = href;
            document.head.appendChild(manifestLink);
        }

        function probeManifest(index) {
            if (index >= candidates.length) {
                attachManifest('manifest.php');
                return;
            }

            fetch(candidates[index], {
                    method: 'HEAD',
                    credentials: 'same-origin',
                    cache: 'no-store'
                })
                .then(function(response) {
                    if (response.ok) {
                        attachManifest(candidates[index]);
                        return;
                    }
                    probeManifest(index + 1);
                })
                .catch(function() {
                    probeManifest(index + 1);
                });
        }

        probeManifest(0);
    })();
    // Register admin service worker — required for PWA installability criteria.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('sw.js', {
                    scope: './'
                })
                .catch(function() {
                    /* SW unavailable — non-fatal */
                });
        });
    }
</script>
<?php /* Display size — the same standard as the POS and station boards. Output
         before anything visible so the page never paints at the wrong size. */ ?>
<?php require_once __DIR__ . '/page-zoom.php'; rh_page_zoom_bootstrap(); ?>
<link rel="stylesheet" href="css/page-zoom.css?v=<?php echo @filemtime(__DIR__ . '/../css/page-zoom.css'); ?>">
<?php /* One size for every summary card and data table — loaded after page CSS so it wins. */ ?>
<link rel="stylesheet" href="css/admin-uniform.css?v=<?php echo @filemtime(__DIR__ . '/../css/admin-uniform.css'); ?>">
<link rel="stylesheet" href="css/admin-global-search.css?v=<?php echo @filemtime(__DIR__ . '/../css/admin-global-search.css'); ?>">
<script src="js/admin-global-search.js?v=<?php echo @filemtime(__DIR__ . '/../js/admin-global-search.js'); ?>" defer></script>
<div id="adminPageLoader" class="admin-page-loader is-visible" data-boot-loader="1" role="status" aria-live="polite" aria-hidden="false">
    <div class="admin-page-loader-card">
        <div class="admin-page-loader-brand"><i class="fas fa-hotel" aria-hidden="true"></i><span id="adminPageLoaderBrand"><?php echo htmlspecialchars($site_name); ?></span></div>
        <div class="admin-page-loader-spinner" aria-hidden="true">
            <span></span><span></span><span></span>
        </div>
        <div class="admin-page-loader-title">Loading admin workspace</div>
        <div class="admin-page-loader-text" id="adminPageLoaderText">Preparing your admin view...</div>
        <div class="admin-page-loader-bar"><span></span></div>
    </div>
</div>
<?php echo rhUserTzScript(); ?>
<header class="admin-header">
    <div class="admin-header-brand">
        <i class="fas fa-hotel"></i>
        <h1><?php echo htmlspecialchars($site_name); ?></h1>
    </div>
    <div class="user-info">
        <!-- Connectivity indicator — JS in offline-queue.js keeps this updated -->
        <div id="rhConnPill" title="Network status" style="
            display:inline-flex;align-items:center;gap:5px;
            padding:4px 11px;border-radius:999px;font-size:.75rem;font-weight:700;
            background:#d1fae5;color:#065f46;cursor:default;
            transition:background .3s,color .3s;user-select:none;">
            <i class="fas fa-circle" id="rhConnDot" style="font-size:.55rem"></i>
            <span id="rhConnLabel">Online</span>
        </div>
        <button type="button" class="admin-gsearch-btn" data-rh-global-search aria-label="Search everything (Ctrl+K)" title="Search everything (Ctrl+K or /)">
            <i class="fas fa-search" aria-hidden="true"></i><span>Search</span><kbd>Ctrl K</kbd>
        </button>
        <?php /* Help for the page on screen: where it is, who can open it, the guide sections about it.
                 admin-global-search.js keeps the link pointing at the current page after SPA swaps. */ ?>
        <a href="../docs/guides/system-map.php?page=<?php echo rawurlencode(basename((string)$current_page)); ?>" class="admin-gsearch-btn admin-help-btn" data-rh-page-help target="_blank" rel="noopener" title="Help for this page (opens the staff guides)" aria-label="Help for this page">
            <i class="fas fa-circle-question" aria-hidden="true"></i><span>Help</span>
        </a>
        <?php rh_page_zoom_control(); ?>
        <div class="user-meta">
            <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
            <div class="user-role"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars(ucfirst($user['role'])); ?></div>
        </div>
        <button class="admin-nav-toggle" id="adminNavToggle" aria-label="Toggle navigation" aria-expanded="false">
            <i class="fas fa-bars" id="navToggleIcon"></i>
        </button>
        <a href="change-password.php" class="btn-logout" title="Change my password" style="margin-right:6px;">
            <i class="fas fa-key"></i>
        </a>
        <a href="logout.php" class="btn-logout" title="Sign out">
            <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
        </a>
    </div>
</header>

<nav class="admin-nav" aria-label="Admin navigation" data-admin-nav data-admin-user-id="<?php echo (int)($user['id'] ?? 0); ?>" data-admin-csrf="<?php echo htmlspecialchars((string)($csrf_token ?? '')); ?>">
    <div class="admin-nav-inner">
        <div class="admin-nav-title-row">
            <div class="admin-nav-brand-mark"><i class="fas fa-hotel"></i></div>
            <div class="admin-nav-title-copy">
                <div class="admin-nav-title-main">Admin Menu</div>
                <div class="admin-nav-title-sub"><?php echo htmlspecialchars($site_name); ?></div>
            </div>
            <button type="button" class="admin-sidebar-collapse-toggle" id="adminSidebarCollapse" aria-label="Collapse sidebar" aria-pressed="false" title="Collapse sidebar">
                <i class="fas fa-angles-left"></i>
            </button>
        </div>

        <div class="admin-nav-search">
            <i class="fas fa-search"></i>
            <input type="search" id="adminNavSearch" placeholder="Search menus" aria-label="Search admin menus" autocomplete="off">
        </div>

        <section class="nav-group nav-favorites-group" data-nav-group="favorites">
            <button type="button" class="nav-group-toggle" aria-expanded="true" aria-controls="nav-group-favorites">
                <span class="nav-group-label"><i class="fas fa-star"></i><span class="nav-group-title">Favorites</span></span>
                <span class="nav-group-count" id="adminFavoriteCount">0</span>
                <i class="fas fa-chevron-down nav-group-chevron"></i>
            </button>
            <ul class="nav-group-items" id="nav-group-favorites"></ul>
            <p class="admin-nav-empty" id="adminFavoritesEmpty">Star menus you use often and they will stay here.</p>
        </section>

        <?php foreach ($_nav_groups as $group_label => $items):
            $visibleCount = 0;
            $isActiveGroup = false;
            foreach ($items as $it) {
                if (!_canShowNavItem($it[3] ?? null)) continue;
                $_modKeys = $it[5] ?? null;
                if ($_modKeys !== null && function_exists('rh_module_key_enabled')) {
                    $_mkList = is_array($_modKeys) ? $_modKeys : [(string)$_modKeys];
                    $_mkOk = true;
                    foreach ($_mkList as $_mk) { if (!rh_module_key_enabled((string)$_mk)) { $_mkOk = false; break; } }
                    if (!$_mkOk) continue;
                }
                $visibleCount++;
                if (basename($it[0]) === $current_page) {
                    $isActiveGroup = true;
                }
            }
            if ($visibleCount === 0) continue;
            $group_id = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($group_label)), '-');
            $group_icon = $_nav_group_icons[$group_label] ?? 'fas fa-folder';
        ?>
            <?php $_group_default_collapsed = in_array($group_label, $_nav_default_collapsed, true); $_group_start_folded = $_group_default_collapsed && !$isActiveGroup; ?>
            <section class="nav-group <?php echo $isActiveGroup ? 'is-active-group' : ''; ?><?php echo $_group_start_folded ? ' collapsed' : ''; ?>" data-nav-group="<?php echo htmlspecialchars($group_id); ?>"<?php echo $_group_default_collapsed ? ' data-nav-default-collapsed="1"' : ''; ?>>
                <button type="button" class="nav-group-toggle" aria-expanded="<?php echo $_group_start_folded ? 'false' : 'true'; ?>" aria-controls="nav-group-<?php echo htmlspecialchars($group_id); ?>">
                    <span class="nav-group-label"><i class="<?php echo htmlspecialchars($group_icon); ?>"></i><span class="nav-group-title"><?php echo htmlspecialchars($group_label); ?></span></span>
                    <span class="nav-group-count"><?php echo (int)$visibleCount; ?></span>
                    <i class="fas fa-chevron-down nav-group-chevron"></i>
                </button>
                <ul class="nav-group-items" id="nav-group-<?php echo htmlspecialchars($group_id); ?>">
                    <?php foreach ($items as $it): ?>
                        <?php _renderNavLink($it[0], $it[1], $it[2], $it[3] ?? null, $current_page, $it[4] ?? '', $it[5] ?? null); ?>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

        <section class="nav-group" data-nav-group="external">
            <button type="button" class="nav-group-toggle" aria-expanded="true" aria-controls="nav-group-external">
                <span class="nav-group-label"><i class="<?php echo htmlspecialchars($_nav_group_icons['External']); ?>"></i><span class="nav-group-title">External</span></span>
                <span class="nav-group-count">1</span>
                <i class="fas fa-chevron-down nav-group-chevron"></i>
            </button>
            <ul class="nav-group-items" id="nav-group-external">
                <?php _renderNavLink('../index.php', 'fas fa-external-link-alt', 'View Website', null, $current_page); ?>
            </ul>
        </section>

        <p class="admin-nav-empty admin-nav-search-empty" id="adminNavSearchEmpty" hidden>No matching menus.</p>
    </div>
</nav>

<?php /* SPA router — loaded once here; applies to every admin page automatically */ ?>
<script>
    document.documentElement.classList.add('admin-dynamic-layout');
    if (document.body) {
        document.body.classList.add('admin-dynamic-layout');
    }
</script>
<script src="js/admin-page-intro.js?v=<?php echo @filemtime(__DIR__ . '/../js/admin-page-intro.js'); ?>" defer></script>
<script src="js/admin-spa.js" defer></script>

<?php /* Help FAB — rendered here, outside #rh-admin-page, so it survives SPA
          content swaps and position:fixed is always relative to the viewport. */ ?>
<?php require_once __DIR__ . '/help-tooltips.php'; ?>

<?php /* #rh-admin-page wraps all per-page content.
          This div is intentionally left unclosed here — each admin page's </body>
          auto-closes it per the HTML5 spec. All page-specific content therefore
          lands inside this wrapper, which carries the sidebar-offset margin. */ ?>
<?php /** @var string $csrf_token */ ?>
<div id="rh-admin-page">
    <?php if ($_admin_back_target !== null && $_admin_back_label !== ''): ?>
        <div class="content">
            <a href="<?php echo htmlspecialchars((string)$_admin_back_target['href']); ?>" class="btn btn-secondary btn-sm" aria-label="<?php echo htmlspecialchars($_admin_back_label); ?>">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
                <span><?php echo htmlspecialchars($_admin_back_label); ?></span>
            </a>
        </div>
    <?php endif; ?>
    <script>
        window._rhCsrf = <?= json_encode($csrf_token ?? '') ?>;
    </script>

