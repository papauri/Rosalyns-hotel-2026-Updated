(function () {
    'use strict';

    // Idempotency guard — prevents double-binding when the script is included
    // twice (page-level <script> + admin-footer.php).
    if (window.__adminMainInitialized) { return; }
    window.__adminMainInitialized = true;

    function initAdminCore() {
        initAdminNavigation();
        initAdminModals();
        initAdminTables();
        refreshAdminPageAssists();
        initAdminConfirmations();
        initAdminPageLoader();
    }

    function refreshAdminPageAssists() {
        initAdminStatusCardFilters();
        initAdminSmartHints();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAdminCore, { once: true });
    } else {
        initAdminCore();
    }

    document.addEventListener('rh:content-updated', function () {
        refreshAdminPageAssists();
    });

    // ============================================
    // ADMIN NAVIGATION TOGGLE (Sidebar Collapsing)
    // ============================================

    function initAdminNavigation() {
        const toggleBtn = document.getElementById('adminNavToggle');
        const nav = document.querySelector('.admin-nav');
        const icon = document.getElementById('navToggleIcon');

        if (!nav) return;
        if (nav.dataset.adminNavInitialized === '1') return;
        nav.dataset.adminNavInitialized = '1';

        const userScope = nav.dataset.adminUserId || 'guest';
        const favoritesKey = 'rhAdminNavFavorites:v1:' + userScope;
        const collapsedGroupsKey = 'rhAdminNavCollapsedGroups:v1:' + userScope;
        const sidebarCollapsedKey = 'rhAdminSidebarCollapsed:v1:' + userScope;
        const favoriteApiUrl = 'api/nav-favorites.php';
        const csrfToken = nav.dataset.adminCsrf || '';
        const favoriteList = document.getElementById('nav-group-favorites');
        const favoriteEmpty = document.getElementById('adminFavoritesEmpty');
        const favoriteCount = document.getElementById('adminFavoriteCount');
        const searchInput = document.getElementById('adminNavSearch');
        const searchEmpty = document.getElementById('adminNavSearchEmpty');
        const sidebarToggle = document.getElementById('adminSidebarCollapse');
        const sourceItems = Array.from(nav.querySelectorAll('.nav-group:not(.nav-favorites-group) .nav-item'));
        const sourceKeySet = new Set(sourceItems.map(function (item) { return String(item.dataset.navKey || '').trim(); }).filter(Boolean));

        function readArray(key) {
            try {
                const value = JSON.parse(localStorage.getItem(key) || '[]');
                return Array.isArray(value) ? value : [];
            } catch (err) {
                return [];
            }
        }

        function writeArray(key, value) {
            try { localStorage.setItem(key, JSON.stringify(value)); } catch (err) { /* storage can be unavailable */ }
        }

        let favoriteKeys = normalizeFavoriteKeys(readArray(favoritesKey));
        let collapsedGroups = new Set(readArray(collapsedGroupsKey));
        let favoriteSaveTimer = null;
        let favoriteSaveInFlight = false;
        let favoriteSaveQueued = false;
        let favoriteApiEnabled = true;
        let lastServerFavoritesJson = '';

        // ---- Server-side sidebar state persistence ----
        const sidebarStateApiUrl = 'api/nav-sidebar-state.php';
        const widthKeyForNav = 'rhAdminSidebarWidth:v1:' + userScope;
        let sidebarApiEnabled = !!csrfToken;
        let sidebarSaveTimer = null;
        let pendingSidebarChanges = {};

        function scheduleSidebarStateSave(changes) {
            if (!sidebarApiEnabled) return;
            Object.assign(pendingSidebarChanges, changes);
            if (sidebarSaveTimer) clearTimeout(sidebarSaveTimer);
            sidebarSaveTimer = window.setTimeout(function () {
                sidebarSaveTimer = null;
                const payload = Object.assign({ csrf_token: csrfToken }, pendingSidebarChanges);
                pendingSidebarChanges = {};
                fetch(sidebarStateApiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(payload)
                }).catch(function () { /* localStorage is the fallback */ });
            }, 700);
        }

        async function hydrateSidebarStateFromServer() {
            if (!sidebarApiEnabled) return;
            try {
                const resp = await fetch(sidebarStateApiUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!resp.ok) {
                    if (resp.status === 404) sidebarApiEnabled = false;
                    return;
                }
                const result = await resp.json();
                if (!result || !result.success || !result.data) return;
                const d = result.data;

                // Apply server state only on a fresh device (localStorage has no saved value)
                if (d.sidebar_collapsed !== null && d.sidebar_collapsed !== undefined) {
                    let lsHadState = false;
                    try { lsHadState = localStorage.getItem(sidebarCollapsedKey) !== null; } catch (e) { /* ignore */ }
                    if (!lsHadState) {
                        applySidebarCollapsedState(d.sidebar_collapsed === true, true);
                        try { localStorage.setItem(sidebarCollapsedKey, d.sidebar_collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
                    }
                }
                if (d.sidebar_width && d.sidebar_width >= 220 && d.sidebar_width <= 480) {
                    let lsHadWidth = false;
                    try { lsHadWidth = localStorage.getItem(widthKeyForNav) !== null; } catch (e) { /* ignore */ }
                    if (!lsHadWidth && !document.body.classList.contains('admin-sidebar-collapsed')) {
                        document.documentElement.style.setProperty('--admin-sidebar-width', d.sidebar_width + 'px');
                        try { localStorage.setItem(widthKeyForNav, String(d.sidebar_width)); } catch (e) { /* ignore */ }
                    }
                }
                if (Array.isArray(d.collapsed_groups) && d.collapsed_groups.length > 0) {
                    let lsHadGroups = false;
                    try { lsHadGroups = localStorage.getItem(collapsedGroupsKey) !== null; } catch (e) { /* ignore */ }
                    if (!lsHadGroups) {
                        collapsedGroups = new Set([...collapsedGroups, ...d.collapsed_groups]);
                        applyCollapsedGroups();
                    }
                }
            } catch (e) {
                // Network unavailable — localStorage is the fallback.
            }
        }

        function findSourceItem(key) {
            return sourceItems.find(function (item) { return item.dataset.navKey === key; }) || null;
        }

        function normalizeFavoriteKeys(list) {
            if (!Array.isArray(list)) return [];
            const unique = [];
            const seen = new Set();
            list.forEach(function (rawKey) {
                const key = String(rawKey || '').trim();
                if (!key) return;
                if (!sourceKeySet.has(key)) return;
                if (seen.has(key)) return;
                seen.add(key);
                unique.push(key);
            });
            return unique;
        }

        function closeMobileNav() {
            if (window.innerWidth > 768) return;
            nav.classList.remove('nav-open');
            if (icon) icon.className = 'fas fa-bars';
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
        }

        function setFavoriteButtonState(button, isFavorite) {
            const item = button.closest('.nav-item');
            const label = item ? (item.dataset.navLabel || 'menu') : 'menu';
            button.classList.toggle('is-favorite', isFavorite);
            button.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
            button.setAttribute('aria-label', (isFavorite ? 'Remove ' : 'Add ') + label + (isFavorite ? ' from favorites' : ' to favorites'));
            button.title = isFavorite ? 'Remove from favorites' : 'Add to favorites';
            const star = button.querySelector('i');
            if (star) star.className = isFavorite ? 'fas fa-star' : 'far fa-star';
        }

        function syncFavoriteButtons() {
            nav.querySelectorAll('.nav-favorite-btn').forEach(function (button) {
                setFavoriteButtonState(button, favoriteKeys.includes(button.dataset.navKey));
            });
        }

        function renderFavorites(options) {
            const opts = options || {};
            if (!favoriteList) return;

            favoriteList.innerHTML = '';
            favoriteKeys = normalizeFavoriteKeys(favoriteKeys);

            favoriteKeys.forEach(function (key) {
                const source = findSourceItem(key);
                if (!source) return;
                const sourceLink = source.querySelector('.admin-nav-link');
                if (!sourceLink) return;

                const item = document.createElement('li');
                item.className = 'nav-favorite-item';
                item.dataset.navKey = key;

                const link = sourceLink.cloneNode(true);
                link.classList.add('favorite-link');
                item.appendChild(link);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'nav-favorite-btn nav-favorite-remove is-favorite';
                remove.dataset.navKey = key;
                remove.setAttribute('aria-label', 'Remove ' + (source.dataset.navLabel || 'menu') + ' from favorites');
                remove.title = 'Remove from favorites';
                remove.innerHTML = '<i class="fas fa-star"></i>';
                remove.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    toggleFavorite(key);
                });
                item.appendChild(remove);
                favoriteList.appendChild(item);
            });

            if (favoriteEmpty) favoriteEmpty.hidden = favoriteKeys.length > 0;
            if (favoriteCount) favoriteCount.textContent = String(favoriteKeys.length);
            writeArray(favoritesKey, favoriteKeys);
            syncFavoriteButtons();
            applySearchFilter();
            if (!opts.skipServerSave) scheduleFavoritesSave();
        }

        function toggleFavorite(key) {
            if (!key) return;
            if (favoriteKeys.includes(key)) {
                favoriteKeys = favoriteKeys.filter(function (existing) { return existing !== key; });
            } else {
                favoriteKeys.push(key);
            }
            renderFavorites();
        }

        function scheduleFavoritesSave() {
            if (!csrfToken || !favoriteApiEnabled) return;
            if (favoriteSaveTimer) {
                clearTimeout(favoriteSaveTimer);
            }
            favoriteSaveTimer = window.setTimeout(function () {
                favoriteSaveTimer = null;
                persistFavoritesToServer();
            }, 280);
        }

        async function persistFavoritesToServer() {
            if (!csrfToken || !favoriteApiEnabled) return;
            if (favoriteSaveInFlight) {
                favoriteSaveQueued = true;
                return;
            }

            const payloadFavorites = normalizeFavoriteKeys(favoriteKeys);
            const payloadJson = JSON.stringify(payloadFavorites);
            if (payloadJson === lastServerFavoritesJson) return;

            favoriteSaveInFlight = true;
            try {
                const response = await fetch(favoriteApiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        csrf_token: csrfToken,
                        favorites: payloadFavorites
                    })
                });

                if (response.status === 404 || response.status === 405) {
                    favoriteApiEnabled = false;
                    return;
                }
                if (!response.ok) return;

                const result = await response.json();
                if (!result || result.success !== true || !result.data || !Array.isArray(result.data.favorites)) return;

                const canonical = normalizeFavoriteKeys(result.data.favorites);
                lastServerFavoritesJson = JSON.stringify(canonical);
                if (JSON.stringify(favoriteKeys) !== lastServerFavoritesJson) {
                    favoriteKeys = canonical;
                    renderFavorites({ skipServerSave: true });
                }
            } catch (err) {
                // Keep localStorage as fallback if network sync is unavailable.
            } finally {
                favoriteSaveInFlight = false;
                if (favoriteSaveQueued) {
                    favoriteSaveQueued = false;
                    scheduleFavoritesSave();
                }
            }
        }

        async function hydrateFavoritesFromServer() {
            if (!csrfToken || !favoriteApiEnabled) return;
            try {
                const response = await fetch(favoriteApiUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (response.status === 404 || response.status === 405) {
                    favoriteApiEnabled = false;
                    return;
                }
                if (!response.ok) return;

                const result = await response.json();
                if (!result || result.success !== true || !result.data || !Array.isArray(result.data.favorites)) return;

                const serverKeys = normalizeFavoriteKeys(result.data.favorites);
                lastServerFavoritesJson = JSON.stringify(serverKeys);

                const mergedKeys = normalizeFavoriteKeys(serverKeys.concat(favoriteKeys));
                if (JSON.stringify(mergedKeys) !== JSON.stringify(favoriteKeys)) {
                    favoriteKeys = mergedKeys;
                    renderFavorites({ skipServerSave: true });
                }

                if (JSON.stringify(mergedKeys) !== lastServerFavoritesJson) {
                    scheduleFavoritesSave();
                }
            } catch (err) {
                // Ignore sync bootstrap errors and continue with localStorage only.
            }
        }

        nav.querySelectorAll('.nav-favorite-btn').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                toggleFavorite(this.dataset.navKey);
            });
        });

        function setGroupCollapsed(group, collapsed) {
            const toggle = group.querySelector('.nav-group-toggle');
            group.classList.toggle('collapsed', collapsed);
            if (toggle) toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }

        function saveCollapsedGroups() {
            writeArray(collapsedGroupsKey, Array.from(collapsedGroups));
            scheduleSidebarStateSave({ collapsed_groups: Array.from(collapsedGroups) });
        }

        function applyCollapsedGroups() {
            nav.querySelectorAll('.nav-group').forEach(function (group) {
                const key = group.dataset.navGroup || '';
                const hasActive = !!group.querySelector('.admin-nav-link.active');
                if (hasActive) collapsedGroups.delete(key);
                setGroupCollapsed(group, collapsedGroups.has(key));
            });
            saveCollapsedGroups();
        }

        nav.querySelectorAll('.nav-group-toggle').forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                const group = this.closest('.nav-group');
                if (!group) return;
                const key = group.dataset.navGroup || '';
                const nextCollapsed = !group.classList.contains('collapsed');
                setGroupCollapsed(group, nextCollapsed);
                if (nextCollapsed) collapsedGroups.add(key);
                else collapsedGroups.delete(key);
                saveCollapsedGroups();
            });
        });

        function normalize(text) {
            return (text || '').toLowerCase().trim();
        }

        function filterGroupItems(group, query) {
            const groupLabel = normalize(group.querySelector('.nav-group-title')?.textContent || '');
            let visible = 0;
            group.querySelectorAll('.nav-item, .nav-favorite-item').forEach(function (item) {
                const label = normalize(item.dataset.navLabel || item.textContent || '');
                const href = normalize(item.querySelector('a')?.getAttribute('href') || '');
                const matches = !query || groupLabel.includes(query) || label.includes(query) || href.includes(query);
                item.hidden = !matches;
                if (matches) visible++;
            });
            const isFavGroup = group.classList.contains('nav-favorites-group');
            const noMatch = !!query && visible === 0 && !isFavGroup;
            group.hidden = noMatch;
            // Collapse non-matching groups so their items are hidden via CSS (display:none !important
            // on .collapsed .nav-group-items), since [hidden] attr alone is overridden by display:flex !important.
            if (noMatch) {
                setGroupCollapsed(group, true);
            } else if (query && visible > 0) {
                setGroupCollapsed(group, false);
            }
            return visible;
        }

        function applySearchFilter() {
            if (!searchInput) return;
            const query = normalize(searchInput.value);
            let totalMatches = 0;
            nav.querySelectorAll('.nav-group').forEach(function (group) {
                totalMatches += filterGroupItems(group, query);
            });
            if (!query) {
                nav.querySelectorAll('.nav-group').forEach(function (group) { group.hidden = false; });
                applyCollapsedGroups();
            }
            if (searchEmpty) searchEmpty.hidden = !query || totalMatches > 0;
        }

        if (searchInput) {
            searchInput.addEventListener('input', applySearchFilter);
            // keyup fallback — some mobile browsers (especially iOS) may delay
            // or skip 'input' events when the virtual keyboard is involved.
            searchInput.addEventListener('keyup', applySearchFilter);
            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    this.value = '';
                    applySearchFilter();
                    this.blur();
                }
            });
        }

        function applySidebarCollapsedState(collapsed, skipServerSave) {
            document.body.classList.toggle('admin-sidebar-collapsed', collapsed);
            if (sidebarToggle) {
                sidebarToggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
                sidebarToggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
                sidebarToggle.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
            }
            try { localStorage.setItem(sidebarCollapsedKey, collapsed ? '1' : '0'); } catch (err) { /* ignore */ }
            if (!skipServerSave) scheduleSidebarStateSave({ sidebar_collapsed: collapsed });
        }

        if (sidebarToggle) {
            let savedSidebarCollapsed = false;
            let hasSavedSidebarState = false;
            try {
                const _stored = localStorage.getItem(sidebarCollapsedKey);
                hasSavedSidebarState = _stored !== null;
                savedSidebarCollapsed = _stored === '1';
            } catch (err) { /* ignore */ }
            // Auto-collapse to rail mode on tablet widths (769–1024px) when no preference saved
            if (!hasSavedSidebarState && window.innerWidth >= 769 && window.innerWidth <= 1024) {
                savedSidebarCollapsed = true;
            }
            applySidebarCollapsedState(savedSidebarCollapsed, true); // skipServerSave on initial load
            sidebarToggle.addEventListener('click', function () {
                applySidebarCollapsedState(!document.body.classList.contains('admin-sidebar-collapsed'));
            });
        }

        // Responsive: auto-manage sidebar at breakpoint transitions (only when no explicit preference)
        var _tabletMq = window.matchMedia('(max-width: 1024px)');
        _tabletMq.addEventListener('change', function (e) {
            if (window.innerWidth <= 768) return; // mobile overlay handles it
            var _hasSaved = false;
            try { _hasSaved = localStorage.getItem(sidebarCollapsedKey) !== null; } catch (err) { /* ignore */ }
            if (!_hasSaved) { applySidebarCollapsedState(e.matches, true); }
        });

        nav.addEventListener('click', function (event) {
            const link = event.target.closest('a');
            if (link && nav.contains(link)) closeMobileNav();
        });

        document.addEventListener('click', function (event) {
            if (toggleBtn && window.innerWidth <= 768 && !nav.contains(event.target) && !toggleBtn.contains(event.target)) {
                closeMobileNav();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && nav.classList.contains('nav-open')) closeMobileNav();
        });

        applyCollapsedGroups();
        renderFavorites({ skipServerSave: true });
        hydrateFavoritesFromServer();
        hydrateSidebarStateFromServer();

        // JS fallback: if PHP didn't mark any nav link as active, detect from URL.
        // This handles pages that override $current_page incorrectly or sub-pages
        // that share a filename with a nav link.
        if (!nav.querySelector('.admin-nav-link:not(.favorite-link).active')) {
            var currentFile = window.location.pathname.split('/').pop() || '';
            if (currentFile) {
                nav.querySelectorAll('.admin-nav-link:not(.favorite-link)').forEach(function (link) {
                    var linkHref = (link.getAttribute('href') || '').split('?')[0].split('/').pop();
                    if (linkHref === currentFile) {
                        link.classList.add('active');
                    }
                });
            }
        }

        // Keep the user oriented: ensure the active group is expanded and
        // scroll the active link into the middle of the sidebar viewport.
        const activeLink = nav.querySelector('.admin-nav-link.active');
        if (activeLink) {
            const activeGroup = activeLink.closest('.nav-group');
            if (activeGroup) {
                activeGroup.classList.remove('collapsed');
                const activeKey = activeGroup.dataset.navGroup || '';
                if (activeKey) {
                    collapsedGroups.delete(activeKey);
                    saveCollapsedGroups();
                }
                const activeToggle = activeGroup.querySelector('.nav-group-toggle');
                if (activeToggle) activeToggle.setAttribute('aria-expanded', 'true');
            }
            if (window.innerWidth > 768) {
                // Defer to next frame so layout is settled before measuring.
                requestAnimationFrame(function () {
                    try {
                        activeLink.scrollIntoView({ block: 'center', inline: 'nearest' });
                    } catch (err) {
                        activeLink.scrollIntoView();
                    }
                });
            }
        }

        initSidebarResize(nav, userScope, scheduleSidebarStateSave);
    }

    // ============================================
    // ADMIN SIDEBAR RESIZE (drag right edge)
    // ============================================

    function initSidebarResize(nav, userScope, onWidthSaved) {
        if (window.innerWidth <= 768) return;

        const widthKey = 'rhAdminSidebarWidth:v1:' + userScope;
        const MIN_WIDTH = 220;
        const MAX_WIDTH = 480;

        function clampWidth(value) {
            const n = Number(value) || 0;
            return Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, Math.round(n)));
        }

        function applyWidth(width) {
            document.documentElement.style.setProperty('--admin-sidebar-width', width + 'px');
        }

        // Restore saved width on load (only when not collapsed).
        let savedWidth = null;
        try {
            const raw = localStorage.getItem(widthKey);
            if (raw) savedWidth = clampWidth(parseInt(raw, 10));
        } catch (err) { /* ignore */ }
        if (savedWidth) applyWidth(savedWidth);

        // Build the drag handle once.
        let handle = document.querySelector('.admin-sidebar-resize-handle');
        if (!handle) {
            handle = document.createElement('div');
            handle.className = 'admin-sidebar-resize-handle';
            handle.setAttribute('role', 'separator');
            handle.setAttribute('aria-orientation', 'vertical');
            handle.setAttribute('aria-label', 'Resize admin sidebar');
            handle.tabIndex = 0;
            document.body.appendChild(handle);
        }

        let dragging = false;

        function onPointerMove(event) {
            if (!dragging) return;
            const next = clampWidth(event.clientX);
            applyWidth(next);
        }

        function onPointerUp(event) {
            if (!dragging) return;
            dragging = false;
            document.body.classList.remove('admin-sidebar-resizing');
            try { handle.releasePointerCapture(event.pointerId); } catch (err) { /* ignore */ }
            document.removeEventListener('pointermove', onPointerMove);
            document.removeEventListener('pointerup', onPointerUp);
            // Persist final width.
            const computed = getComputedStyle(document.documentElement).getPropertyValue('--admin-sidebar-width');
            const px = parseInt(computed, 10);
            if (!Number.isNaN(px)) {
                const finalWidth = clampWidth(px);
                try { localStorage.setItem(widthKey, String(finalWidth)); } catch (err) { /* ignore */ }
                if (typeof onWidthSaved === 'function') onWidthSaved({ sidebar_width: finalWidth });
            }
        }

        handle.addEventListener('pointerdown', function (event) {
            // Only react to primary button on a real pointing device.
            if (event.button !== 0) return;
            // Disable resize when sidebar is collapsed (rail mode).
            if (document.body.classList.contains('admin-sidebar-collapsed')) return;
            dragging = true;
            event.preventDefault();
            document.body.classList.add('admin-sidebar-resizing');
            try { handle.setPointerCapture(event.pointerId); } catch (err) { /* ignore */ }
            document.addEventListener('pointermove', onPointerMove);
            document.addEventListener('pointerup', onPointerUp);
        });

        // Double-click resets to the default width.
        handle.addEventListener('dblclick', function () {
            applyWidth(292);
            try { localStorage.removeItem(widthKey); } catch (err) { /* ignore */ }
            if (typeof onWidthSaved === 'function') onWidthSaved({ sidebar_width: 292 });
        });

        // Keyboard resize for accessibility (left/right arrows).
        handle.addEventListener('keydown', function (event) {
            if (document.body.classList.contains('admin-sidebar-collapsed')) return;
            const step = event.shiftKey ? 24 : 8;
            const computed = getComputedStyle(document.documentElement).getPropertyValue('--admin-sidebar-width');
            const current = parseInt(computed, 10) || 292;
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                const next = clampWidth(current - step);
                applyWidth(next);
                try { localStorage.setItem(widthKey, String(next)); } catch (err) { /* ignore */ }
                if (typeof onWidthSaved === 'function') onWidthSaved({ sidebar_width: next });
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                const next = clampWidth(current + step);
                applyWidth(next);
                try { localStorage.setItem(widthKey, String(next)); } catch (err) { /* ignore */ }
                if (typeof onWidthSaved === 'function') onWidthSaved({ sidebar_width: next });
            } else if (event.key === 'Home') {
                event.preventDefault();
                applyWidth(292);
                try { localStorage.removeItem(widthKey); } catch (err) { /* ignore */ }
                if (typeof onWidthSaved === 'function') onWidthSaved({ sidebar_width: 292 });
            }
        });
    }

    // ============================================
    // ADMIN MODALS
    // ============================================

    function initAdminModals() {
        // Placeholder for modal initialization
    }

    // ============================================
    // ADMIN TABLES RESPONSIVENESS
    // ============================================

    function initAdminTables() {
        if (window.__adminTablesInitialized) return;
        window.__adminTablesInitialized = true;

        const seen = new Set();
        const tables = Array.from(document.querySelectorAll('.admin-content table, .admin-container table, .content table')).filter(function (table) {
            if (seen.has(table)) return false;
            seen.add(table);
            return true;
        });

        tables.forEach(function (table) {
            if (!table || table.dataset.adminSortReady === '1') return;
            if (table.dataset.disableAutoSort === '1' || table.classList.contains('no-auto-sort')) return;
            if (table.querySelector('thead th[onclick], thead th[data-col], thead th.sortable')) return;

            const head = table.tHead;
            const body = (table.tBodies && table.tBodies.length > 0) ? table.tBodies[0] : null;
            if (!head || !body) return;

            const headers = Array.from(head.querySelectorAll('th'));
            if (!headers.length) return;

            // Skip calendar/grid-style tables where sorting headers would break layout.
            if (table.closest('.calendar-wrapper, .calendar-grid, .booking-calendar, .calendar-table')) return;
            const headerLabels = headers.map(function (th) {
                return (th.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            });
            const weekdayPrefixes = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
            const weekdayHeaderCount = headerLabels.filter(function (label) {
                return weekdayPrefixes.some(function (prefix) {
                    return label.indexOf(prefix) === 0;
                });
            }).length;
            if (weekdayHeaderCount >= 5) return;

            const sortableRowCount = Array.from(body.rows).filter(function (row) {
                return row.querySelectorAll('td').length > 1;
            }).length;
            if (sortableRowCount < 2) return;

            table.dataset.adminSortReady = '1';

            headers.forEach(function (th, index) {
                if (!isSortableHeader(th)) return;
                prepareSortableHeader(th);
                th.addEventListener('click', function (event) {
                    if (event.target.closest('input, button, select, a, textarea, label')) return;
                    sortTableByColumn(table, body, headers, th, index);
                });
                th.addEventListener('keydown', function (event) {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    sortTableByColumn(table, body, headers, th, index);
                });
            });
        });

        function isSortableHeader(th) {
            if (!th) return false;
            if (th.classList.contains('no-sort')) return false;
            if (th.dataset.noSort === '1') return false;
            if (th.querySelector('input, button, select, textarea, a')) return false;
            const label = (th.textContent || '').replace(/\s+/g, ' ').trim();
            return label.length > 0;
        }

        function prepareSortableHeader(th) {
            th.classList.add('admin-auto-sortable');
            th.style.cursor = 'pointer';
            th.setAttribute('tabindex', '0');
            th.setAttribute('role', 'button');
            th.setAttribute('aria-sort', 'none');

            if (!th.querySelector('.admin-sort-icon')) {
                const icon = document.createElement('i');
                icon.className = 'fas fa-sort admin-sort-icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.style.marginLeft = '6px';
                icon.style.opacity = '0.55';
                th.appendChild(icon);
            }
        }

        function sortTableByColumn(table, body, headers, activeHeader, index) {
            const currentIndex = parseInt(table.dataset.adminSortIndex || '-1', 10);
            let direction = 'asc';
            if (currentIndex === index) {
                direction = (table.dataset.adminSortDir === 'asc') ? 'desc' : 'asc';
            }
            table.dataset.adminSortIndex = String(index);
            table.dataset.adminSortDir = direction;

            headers.forEach(function (th) {
                th.setAttribute('aria-sort', 'none');
                const icon = th.querySelector('.admin-sort-icon');
                if (icon) {
                    icon.classList.remove('fa-sort-up', 'fa-sort-down');
                    icon.classList.add('fa-sort');
                    icon.style.opacity = '0.55';
                }
            });

            activeHeader.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');
            const activeIcon = activeHeader.querySelector('.admin-sort-icon');
            if (activeIcon) {
                activeIcon.classList.remove('fa-sort', 'fa-sort-up', 'fa-sort-down');
                activeIcon.classList.add(direction === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
                activeIcon.style.opacity = '0.95';
            }

            const sortableRows = [];
            const fixedRows = [];

            Array.from(body.rows).forEach(function (row, rowIndex) {
                if (row.classList.contains('no-sort-row')) {
                    fixedRows.push(row);
                    return;
                }
                if (row.querySelector('th')) {
                    fixedRows.push(row);
                    return;
                }
                const cell = row.cells[index];
                if (!cell || row.cells.length <= 1) {
                    fixedRows.push(row);
                    return;
                }

                const rawValue = (cell.dataset && typeof cell.dataset.sortValue !== 'undefined' && cell.dataset.sortValue !== '')
                    ? cell.dataset.sortValue
                    : ((row.dataset && typeof row.dataset.sortValue !== 'undefined' && row.dataset.sortValue !== '')
                        ? row.dataset.sortValue
                        : cell.textContent);

                sortableRows.push({
                    row: row,
                    originalIndex: rowIndex,
                    comparable: parseComparableValue(rawValue)
                });
            });

            if (sortableRows.length < 2) return;

            sortableRows.sort(function (left, right) {
                const comparison = compareComparableValue(left.comparable, right.comparable);
                if (comparison !== 0) {
                    return direction === 'asc' ? comparison : -comparison;
                }
                return left.originalIndex - right.originalIndex;
            });

            sortableRows.forEach(function (item) { body.appendChild(item.row); });
            fixedRows.forEach(function (row) { body.appendChild(row); });
        }

        function parseComparableValue(rawValue) {
            const text = String(rawValue || '').replace(/\s+/g, ' ').trim();
            if (text === '') {
                return { kind: 'text', value: '' };
            }

            const numericCandidate = text.replace(/,/g, '').replace(/[^\d.-]/g, '');
            if (numericCandidate !== '' && /^-?\d+(\.\d+)?$/.test(numericCandidate)) {
                return { kind: 'number', value: parseFloat(numericCandidate) };
            }

            const asDate = Date.parse(text);
            if (!Number.isNaN(asDate) && /[\/-]/.test(text)) {
                return { kind: 'number', value: asDate };
            }

            return { kind: 'text', value: text.toLowerCase() };
        }

        function compareComparableValue(left, right) {
            if (left.kind === 'number' && right.kind === 'number') {
                return left.value - right.value;
            }
            return String(left.value).localeCompare(String(right.value), undefined, {
                numeric: true,
                sensitivity: 'base'
            });
        }
    }

    // ============================================
    // ADMIN STATUS-CARD FILTERS (GENERIC)
    // ============================================

    function initAdminStatusCardFilters() {
        const cards = Array.from(document.querySelectorAll('.status-card, .stat-card, [data-status-filter]'));
        if (!cards.length) return;

        let tableCounter = 0;
        const activeByTable = new Map();

        function cardHelpCopy(card, token) {
            const labelNode = card.querySelector('.stat-label, .kpi-card__label, .summary-card .label, h3, h4');
            const subNode = card.querySelector('.stat-sub, .report-analysis__hint, .kpi-card__meta, p, small');
            const label = ((labelNode && labelNode.textContent) || readableStatusLabel(token)).replace(/\s+/g, ' ').trim();
            const sub = ((subNode && subNode.textContent) || '').replace(/\s+/g, ' ').trim();
            const lowerLabel = label.toLowerCase();

            let meaning = '';
            if (token === 'paid') {
                meaning = 'Shows the total value of successfully paid records for the current date and filters.';
            } else if (token === 'pending') {
                meaning = 'Shows records still waiting for completion or confirmation.';
            } else if (token === 'cancelled') {
                meaning = 'Shows records that were cancelled and are excluded from active flow.';
            } else if (token === 'active') {
                meaning = 'Shows records currently active and available for operations.';
            } else if (token === 'inactive') {
                meaning = 'Shows records currently disabled or not in active use.';
            }

            if (!meaning) {
                if (lowerLabel.indexOf('sales') >= 0 || lowerLabel.indexOf('revenue') >= 0) {
                    meaning = 'Summarizes sales value for the selected period.';
                } else if (lowerLabel.indexOf('cash') >= 0) {
                    meaning = 'Tracks expected or declared cash totals used for reconciliation.';
                } else if (lowerLabel.indexOf('void') >= 0) {
                    meaning = 'Tracks voided transactions and their value for audit control.';
                } else {
                    meaning = 'Summarizes a key metric for this page using the current filters.';
                }
            }

            const context = sub ? (' Context: ' + sub + '.') : '';
            return label + '|' + meaning + context + ' Click to filter the related table rows; click again to clear.';
        }

        cards.forEach(function (card) {
            if (!card || card.dataset.adminStatusFilterReady === '1') return;
            if (card.hasAttribute('onclick')) return; // keep page-specific handlers untouched

            const token = detectStatusToken(card);
            if (!token) return;

            const targetTable = findTargetTable(card);
            if (!targetTable) return;

            if (!targetTable.dataset.adminStatusFilterTableId) {
                tableCounter += 1;
                targetTable.dataset.adminStatusFilterTableId = 'status-table-' + String(tableCounter);
            }

            const tableId = targetTable.dataset.adminStatusFilterTableId;
            card.dataset.adminStatusFilterReady = '1';
            card.dataset.adminStatusToken = token;
            card.dataset.adminStatusFilterTableId = tableId;
            card.style.cursor = 'pointer';
            card.setAttribute('role', 'button');
            card.setAttribute('tabindex', '0');

            if (!card.hasAttribute('data-help')) {
                card.setAttribute('data-help', cardHelpCopy(card, token));
            }

            function toggleCardFilter() {
                const currentToken = activeByTable.get(tableId) || '';
                const nextToken = (currentToken === token) ? '' : token;
                activeByTable.set(tableId, nextToken);
                setActiveCardStyles(tableId, nextToken);
                filterTableRows(targetTable, nextToken);
            }

            card.addEventListener('click', toggleCardFilter);
            card.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                toggleCardFilter();
            });
        });

        function findTargetTable(card) {
            let cursor = card.parentElement;
            while (cursor && cursor !== document.body) {
                const scopedTable = cursor.querySelector('table');
                if (scopedTable && scopedTable.tBodies && scopedTable.tBodies.length > 0) {
                    return scopedTable;
                }
                cursor = cursor.parentElement;
            }
            return document.querySelector('.admin-content table, .admin-container table, .content table');
        }

        function filterTableRows(table, token) {
            if (!table || !table.tBodies || !table.tBodies.length) return;
            const rows = Array.from(table.tBodies[0].rows);
            rows.forEach(function (row) {
                if (!token) {
                    row.style.display = '';
                    return;
                }
                const rowToken = detectStatusToken(row);
                if (!rowToken) {
                    row.style.display = '';
                    return;
                }
                row.style.display = rowToken === token ? '' : 'none';
            });
        }

        function setActiveCardStyles(tableId, activeToken) {
            const selector = '[data-admin-status-filter-table-id="' + tableId + '"]';
            document.querySelectorAll(selector).forEach(function (card) {
                const isActive = !!activeToken && card.dataset.adminStatusToken === activeToken;
                card.classList.toggle('is-active', isActive);
                card.style.boxShadow = isActive ? '0 0 0 2px var(--color-primary, #8A775F)' : '';
                card.style.transform = isActive ? 'translateY(-2px)' : '';
            });
        }

        function detectStatusToken(sourceEl) {
            if (!sourceEl) return '';

            const fromData = normalizeStatusToken(
                sourceEl.getAttribute('data-status-filter') ||
                sourceEl.getAttribute('data-status') ||
                (sourceEl.dataset ? (sourceEl.dataset.status || sourceEl.dataset.roomStatus || sourceEl.dataset.adminStatusToken || '') : '')
            );
            if (fromData) return fromData;

            const statusNode = sourceEl.querySelector
                ? sourceEl.querySelector('.status-badge, .status-label, .stat-label, .label, [class*="status"], [class*="label"]')
                : null;
            const sourceText = statusNode ? statusNode.textContent : sourceEl.textContent;
            return tokenFromText(sourceText || '');
        }

        function tokenFromText(text) {
            const lower = String(text || '').toLowerCase();
            if (!lower) return '';

            if (lower.indexOf('out of order') >= 0 || lower.indexOf('out-of-order') >= 0) return 'out_of_order';
            if (lower.indexOf('checked in') >= 0 || lower.indexOf('check in') >= 0) return 'checked_in';
            if (lower.indexOf('checked out') >= 0 || lower.indexOf('check out') >= 0) return 'checked_out';

            if (lower.indexOf('available') >= 0) return 'available';
            if (lower.indexOf('occupied') >= 0 || lower.indexOf('booked') >= 0) return 'occupied';
            if (lower.indexOf('cleaning') >= 0) return 'cleaning';
            if (lower.indexOf('maintenance') >= 0) return 'maintenance';
            if (lower.indexOf('pending') >= 0) return 'pending';
            if (lower.indexOf('confirmed') >= 0) return 'confirmed';
            if (lower.indexOf('cancelled') >= 0 || lower.indexOf('canceled') >= 0) return 'cancelled';
            if (lower.indexOf('paid') >= 0 && lower.indexOf('unpaid') < 0) return 'paid';
            if (lower.indexOf('unpaid') >= 0) return 'unpaid';
            if (lower.indexOf('inactive') >= 0) return 'inactive';
            if (lower.indexOf('active') >= 0) return 'active';
            return '';
        }

        function normalizeStatusToken(rawToken) {
            const normalized = String(rawToken || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
            if (!normalized) return '';

            const aliases = {
                outoforder: 'out_of_order',
                out_of_order: 'out_of_order',
                available: 'available',
                occupied: 'occupied',
                booked: 'occupied',
                cleaning: 'cleaning',
                maintenance: 'maintenance',
                pending: 'pending',
                confirmed: 'confirmed',
                cancelled: 'cancelled',
                canceled: 'cancelled',
                checkedin: 'checked_in',
                checked_in: 'checked_in',
                checkedout: 'checked_out',
                checked_out: 'checked_out',
                paid: 'paid',
                unpaid: 'unpaid',
                active: 'active',
                inactive: 'inactive'
            };

            return aliases[normalized] || '';
        }

        function readableStatusLabel(token) {
            const labels = {
                available: 'Available',
                occupied: 'Occupied',
                cleaning: 'Cleaning',
                maintenance: 'Maintenance',
                out_of_order: 'Out of Order',
                pending: 'Pending',
                confirmed: 'Confirmed',
                cancelled: 'Cancelled',
                checked_in: 'Checked In',
                checked_out: 'Checked Out',
                paid: 'Paid',
                unpaid: 'Unpaid',
                active: 'Active',
                inactive: 'Inactive'
            };
            return labels[token] || 'Status';
        }
    }

    // ============================================
    // ADMIN SMART HELP HINTS (LIGHTWEIGHT)
    // ============================================

    function initAdminSmartHints() {
        let hintsApplied = 0;
        const maxHints = 140;
        const pageFile = (window.location.pathname.split('/').pop() || '').toLowerCase();
        const pageTitleNode = document.querySelector('.admin-title, .page-title, .content-header h1, h1, h2');
        const pageTitle = ((pageTitleNode && pageTitleNode.textContent) || '').replace(/\s+/g, ' ').trim();
        const moduleLabel = pageTitle || 'this module';

        function applyHint(el, hintText) {
            if (!el || !(el instanceof HTMLElement)) return;
            if (hintsApplied >= maxHints) return;
            if (el.hasAttribute('data-help') || el.hasAttribute('title')) return;
            if (el.closest('[hidden], [aria-hidden="true"]')) return;
            el.setAttribute('data-help', hintText);
            el.setAttribute('data-rh-help-auto', '1');
            hintsApplied += 1;
        }

        function applyBySelector(selector, hintText, allMatches) {
            const matches = document.querySelectorAll(selector);
            if (!matches.length) return;
            matches.forEach(function (el, idx) {
                if (!allMatches && idx > 0) return;
                applyHint(el, hintText);
            });
        }

        function metricHintBody(labelText, fallbackText) {
            const lower = String(labelText || '').toLowerCase();
            if (lower.indexOf('paid') >= 0 && (lower.indexOf('sale') >= 0 || lower.indexOf('order') >= 0)) {
                return 'Total value of records that reached paid status under the current filters.';
            }
            if (lower.indexOf('cash') >= 0) {
                return 'Tracks cash-side totals used for till balancing and accountant reconciliation.';
            }
            if (lower.indexOf('mobile') >= 0 || lower.indexOf('card') >= 0) {
                return 'Shows non-cash payment totals for reconciliation and payout checks.';
            }
            if (lower.indexOf('void') >= 0) {
                return 'Counts and values voided records so exceptions stay visible in audit.';
            }
            if (lower.indexOf('occupancy') >= 0) {
                return 'Shows current space utilization to guide operational decisions.';
            }
            if (lower.indexOf('outstanding') >= 0 || lower.indexOf('receivable') >= 0) {
                return 'Shows value still due so follow-up and collection can be prioritized.';
            }
            if (lower.indexOf('stock') >= 0 || lower.indexOf('inventory') >= 0) {
                return 'Summarizes stock position for control, replenishment, and variance checks.';
            }
            return fallbackText || 'This metric summarizes key performance for the active filters and date range.';
        }

        // High-value global controls.
        applyBySelector('#adminNavToggle', 'Menu|Open or close the admin sidebar navigation.', false);
        applyBySelector('#adminSidebarCollapse', 'Collapse sidebar|Switch sidebar between full and compact icon mode.', false);
        applyBySelector('#adminNavSearch', 'Search menus|Type to quickly find a page in the admin menu.', false);
        applyBySelector('.btn-logout', 'Sign out|End your admin session securely.', false);

        // Common filter/search controls.
        applyBySelector('input[type="search"], .search-input, input[name*="search"]', 'Search records|Type to filter visible rows without deleting or changing data.', true);
        applyBySelector('input[type="date"], input[type="month"], input[type="datetime-local"]', 'Date filter|Pick a date range used to recalculate this page.', true);
        applyBySelector('select', 'Selection filter|Choose a value to narrow visible records and metrics.', true);
        applyBySelector('.btn-filter, .filter-bar button[type="submit"], .filters button[type="submit"], .btn-add[type="submit"]', 'Apply filters|Refresh this view using only the selected filter values.', true);
        applyBySelector('.btn-reset, .btn-clear, .filter-bar .btn-secondary', 'Clear filters|Reset filter controls and show all records again.', true);
        applyBySelector('.btn-export, a[href*="export=csv"], a[href*="download"]', 'Export data|Download the currently filtered rows for accounting or sharing.', true);
        applyBySelector('.tabs .tab, .tab, .nav-tabs .nav-link', 'View tab|Switch to another report or module section without changing data.', true);
        applyBySelector('.pagination a', 'Pagination|Move between result pages while keeping the same filters.', true);

        // KPI / report cards should explain themselves when Help mode is on.
        document.querySelectorAll('.report-analysis__card, .stat-card, .status-card, .kpi-card, .summary-card, .ops-card, .qt-stat, .dashboard-card, .analytics-card, [data-metric-card]').forEach(function (card) {
            if (hintsApplied >= maxHints) return;
            if (!(card instanceof HTMLElement)) return;
            if (card.hasAttribute('data-help')) return;

            const labelNode = card.querySelector('.report-analysis__label, .stat-card__label, .status-card__label, .kpi-card__label, .qt-stat__label, .metric-label, .stat-label, .status-label, .ops-label, .summary-card .label, h3, h4');
            const hintNode = card.querySelector('.report-analysis__hint, .stat-card__meta, .kpi-card__meta, .qt-stat__value, .stat-value, .ops-value, .acct-kpi__value, .summary-card .value, .stat-sub, .ops-sub, small, p');
            const label = (labelNode && labelNode.textContent ? labelNode.textContent : '').replace(/\s+/g, ' ').trim();
            const hint = (hintNode && hintNode.textContent ? hintNode.textContent : '').replace(/\s+/g, ' ').trim();
            if (!label) return;

            let body = metricHintBody(label, hint);
            const isInteractiveCard = card.matches('[role="button"], [tabindex], a, button') ||
                card.hasAttribute('onclick') ||
                !!card.getAttribute('data-status-filter') ||
                (card.dataset && (card.dataset.adminStatusFilterReady === '1' || card.dataset.adminStatusToken));

            if (isInteractiveCard) {
                body += ' Click this card to focus matching records; click again to clear.';
            }

            card.setAttribute('data-help', label + '|' + body);
            card.setAttribute('data-rh-help-auto', '1');
            hintsApplied += 1;
        });

        // Section-level anchors: only key blocks, not every tiny element.
        document.querySelectorAll('.section-card, .report-section, .acct-panel, .dashboard-section, .qt-header, .qt-filters, .qt-table-wrap, [data-main-section]').forEach(function (block) {
            if (hintsApplied >= maxHints) return;
            if (!(block instanceof HTMLElement)) return;
            if (block.hasAttribute('data-help') || block.hasAttribute('title')) return;

            const headingNode = block.querySelector('h2, h3, .acct-panel__title, .section-title');
            if (!headingNode) return;
            const heading = (headingNode.textContent || '').replace(/\s+/g, ' ').trim();
            if (!heading || heading.length < 3) return;

            const descNode = block.querySelector('p, .acct-panel__sub, .stat-sub, .section-description');
            const desc = (descNode && descNode.textContent ? descNode.textContent : '').replace(/\s+/g, ' ').trim();
            const body = desc || 'Primary section for this module. Review values here before taking actions.';
            applyHint(block, heading + '|' + body);
        });

        // Acronym expansion hints in nav and headings.
        const acronymSeen = new Set();
        document.querySelectorAll('.admin-nav-link, .nav-item a, .page-header h2, h2').forEach(function (node) {
            if (hintsApplied >= maxHints) return;
            if (!(node instanceof HTMLElement)) return;
            if (node.hasAttribute('data-help') || node.hasAttribute('title')) return;
            const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
            if (!text) return;

            if (/\bPOS\b/i.test(text) && !acronymSeen.has('POS')) {
                applyHint(node, 'POS (Point of Sale)|Use the till interface to create orders and collect payments.');
                acronymSeen.add('POS');
            } else if (/\bKDS\b/i.test(text) && !acronymSeen.has('KDS')) {
                applyHint(node, 'KDS (Kitchen Display System)|Kitchen screen for preparing and tracking food tickets.');
                acronymSeen.add('KDS');
            } else if (/\bBDS\b/i.test(text) && !acronymSeen.has('BDS')) {
                applyHint(node, 'BDS (Bar Display System)|Bar station screen for drink orders and ticket flow.');
                acronymSeen.add('BDS');
            } else if (/\bCDS\b/i.test(text) && !acronymSeen.has('CDS')) {
                applyHint(node, 'CDS (Coffee Display System)|Coffee station screen for beverage preparation workflow.');
                acronymSeen.add('CDS');
            }
        });

        function pageMatches(pattern) {
            return pattern.test(pageFile);
        }

        function withModule(text) {
            return text.replace(/\{module\}/g, moduleLabel);
        }

        function findPageSpecificHint(buttonText) {
            const text = String(buttonText || '');

            if (pageMatches(/bookings|create-booking|edit-booking|booking-details|tentative-bookings/)) {
                if (/\b(view|details?)\b/i.test(text)) return 'Booking details|Open full guest, stay, and payment history for this booking.';
                if (/\b(check\s*-?in)\b/i.test(text)) return 'Check in guest|Move this booking to in-house status and start the active-stay workflow.';
                if (/\b(check\s*-?out)\b/i.test(text)) return 'Check out guest|Close the stay, finalize charges, and trigger checkout invoice actions.';
                if (/\b(cancel)\b/i.test(text)) return 'Cancel booking|Set this booking to cancelled and release dates back into availability.';
            }

            if (pageMatches(/payments|invoices|payment-/)) {
                if (/\b(refund)\b/i.test(text)) return 'Refund payment|Create a refund entry and update accounting totals for this payment.';
                if (/\b(invoice)\b/i.test(text)) return 'Invoice|Generate or open the invoice linked to this payment record.';
                if (/\b(receipt)\b/i.test(text)) return 'Receipt|Open, print, or resend the payment receipt for this transaction.';
            }

            if (pageMatches(/individual-rooms|room-|housekeeping|blocked-dates/)) {
                if (/\b(assign)\b/i.test(text)) return 'Assign room task|Link this room to staff or booking workflow from the current list.';
                if (/\b(block|unblock)\b/i.test(text)) return 'Availability block|Prevent or allow new bookings for the selected room/date period.';
                if (/\b(maintenance)\b/i.test(text)) return 'Maintenance status|Move this room into or out of maintenance workflow.';
            }

            if (pageMatches(/stock-/)) {
                if (/\b(receive|receipt)\b/i.test(text)) return 'Stock receipt|Record delivered quantities and update on-hand stock levels.';
                if (/\b(count)\b/i.test(text)) return 'Stock count|Capture physical count values used for variance reconciliation.';
                if (/\b(wastage|waste)\b/i.test(text)) return 'Wastage log|Record write-offs with reason so variance and costs stay accurate.';
            }

            if (pageMatches(/reports|analytics|dashboard/)) {
                if (/\b(export|csv|download)\b/i.test(text)) return 'Export report|Download the currently filtered report data for sharing or accounting.';
                if (/\b(generate|run)\b/i.test(text)) return 'Run report|Recalculate metrics using the currently selected dates and filters.';
            }

            return '';
        }

        // Minimal action hints for common table/admin actions.
        const actionHintRules = [
            { regex: /\b(add|new|create)\b/i, help: withModule('Create record|Open a form to add a new entry in {module}.') },
            { regex: /\b(edit|update)\b/i, help: 'Edit record|Open this item with current values pre-filled so you can safely update it.' },
            { regex: /\b(view|details?)\b/i, help: 'View details|Open the complete record with related history and actions.' },
            { regex: /\b(assign)\b/i, help: 'Assign|Attach this item to the correct staff member, room, or workflow step.' },
            { regex: /\b(status|approve|reject|confirm)\b/i, help: 'Update status|Move this item to the correct workflow state and save the timeline.' },
            { regex: /\b(delete|remove|trash)\b/i, help: 'Delete record|Permanently remove this item after confirmation. This action may not be reversible.' },
            { regex: /\b(save|apply|submit)\b/i, help: 'Save changes|Write the current form values to the database for this record.' },
            { regex: /\b(export|csv|download)\b/i, help: 'Export data|Download the currently filtered rows as a file for sharing or analysis.' },
            { regex: /\b(clear|reset)\b/i, help: 'Clear selection|Reset current filters or form values to their default state.' }
        ];

        document.querySelectorAll('.btn-primary, .btn-add, .btn-filter, .btn-export, .btn-reset, .acct-btn--primary, a.btn-primary, input[type="submit"].btn-primary, [data-primary-action]').forEach(function (el) {
            if (hintsApplied >= maxHints) return;
            if (!(el instanceof HTMLElement)) return;
            if (el.hasAttribute('data-help') || el.hasAttribute('title')) return;
            const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
            if (!text || text.length > 52) return;

            const specificHint = findPageSpecificHint(text);
            if (specificHint) {
                applyHint(el, specificHint);
                return;
            }

            for (let i = 0; i < actionHintRules.length; i += 1) {
                const rule = actionHintRules[i];
                if (!rule.regex.test(text)) continue;
                applyHint(el, rule.help);
                break;
            }
        });
    }

    // ============================================
    // ADMIN CONFIRMATION MODAL
    // ============================================

    function initAdminConfirmations() {
        if (window.__adminConfirmInitialized) return;
        window.__adminConfirmInitialized = true;

        const modalId = 'adminConfirmModal';
        let activeResolve = null;
        let previousFocus = null;

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, function (char) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
            });
        }

        function ensureModal() {
            let modal = document.getElementById(modalId);
            if (modal) return modal;

            modal = document.createElement('div');
            modal.id = modalId;
            modal.className = 'modal-overlay admin-confirm-overlay';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.setAttribute('aria-labelledby', 'adminConfirmTitle');
            modal.innerHTML = [
                '<div class="admin-confirm-card" role="document">',
                '<div class="admin-confirm-head">',
                '<div class="admin-confirm-icon"><i class="fas fa-shield-alt" aria-hidden="true"></i></div>',
                '<div>',
                '<h3 id="adminConfirmTitle">Confirm action</h3>',
                '<p id="adminConfirmMessage">Please confirm this action.</p>',
                '</div>',
                '<button type="button" class="admin-confirm-close" data-admin-confirm-cancel aria-label="Close"><i class="fas fa-times" aria-hidden="true"></i></button>',
                '</div>',
                '<div class="admin-confirm-body">',
                '<div id="adminConfirmDetails" class="admin-confirm-details" hidden></div>',
                '<label id="adminConfirmInputWrap" class="admin-confirm-input-wrap" hidden>',
                '<span id="adminConfirmInputLabel">Reason</span>',
                '<textarea id="adminConfirmInput" rows="3"></textarea>',
                '</label>',
                '</div>',
                '<div class="admin-confirm-actions">',
                '<button type="button" class="btn btn-secondary" data-admin-confirm-cancel>Cancel</button>',
                '<button type="button" class="btn btn-primary admin-confirm-submit" data-admin-confirm-ok>Confirm</button>',
                '</div>',
                '</div>'
            ].join('');
            document.body.appendChild(modal);

            modal.addEventListener('click', function (event) {
                if (event.target === modal || event.target.closest('[data-admin-confirm-cancel]')) {
                    settle(false);
                }
            });
            modal.querySelector('[data-admin-confirm-ok]').addEventListener('click', function () {
                settle(true);
            });

            return modal;
        }

        function renderDetails(target, details) {
            target.innerHTML = '';
            target.hidden = true;

            if (!details) return;
            if (Array.isArray(details)) {
                const items = details.filter(function (item) { return item != null && String(item).trim() !== ''; });
                if (!items.length) return;
                target.innerHTML = '<ul>' + items.map(function (item) {
                    return '<li>' + escapeHtml(item) + '</li>';
                }).join('') + '</ul>';
                target.hidden = false;
                return;
            }

            const text = String(details).trim();
            if (!text) return;
            target.innerHTML = '<p>' + escapeHtml(text) + '</p>';
            target.hidden = false;
        }

        function setTone(modal, tone) {
            modal.classList.remove('admin-confirm-warning', 'admin-confirm-danger', 'admin-confirm-success');
            if (tone) modal.classList.add('admin-confirm-' + tone);
        }

        function settle(confirmed) {
            const modal = document.getElementById(modalId);
            if (!modal || !activeResolve) return;

            const input = modal.querySelector('#adminConfirmInput');
            const value = input ? input.value : '';
            modal.classList.remove('active');
            document.body.classList.remove('modal-open');
            document.removeEventListener('keydown', handleConfirmKeydown);
            if (previousFocus && typeof previousFocus.focus === 'function') {
                window.setTimeout(function () { previousFocus.focus(); }, 0);
            }
            const resolve = activeResolve;
            activeResolve = null;
            resolve({ confirmed: !!confirmed, value: value });
        }

        function handleConfirmKeydown(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                settle(false);
            }
        }

        function request(options) {
            const opts = Object.assign({
                title: 'Confirm action',
                message: 'Please confirm this action before continuing.',
                details: null,
                confirmText: 'Confirm',
                cancelText: 'Cancel',
                tone: 'warning',
                icon: 'fa-shield-alt',
                input: false,
                inputLabel: 'Reason',
                inputPlaceholder: ''
            }, options || {});

            const modal = ensureModal();
            if (activeResolve) settle(false);

            previousFocus = document.activeElement;
            setTone(modal, opts.tone);
            modal.querySelector('.admin-confirm-icon i').className = 'fas ' + opts.icon;
            modal.querySelector('#adminConfirmTitle').textContent = opts.title;
            modal.querySelector('#adminConfirmMessage').textContent = opts.message;
            modal.querySelector('[data-admin-confirm-ok]').textContent = opts.confirmText;
            modal.querySelector('.admin-confirm-actions [data-admin-confirm-cancel]').textContent = opts.cancelText;
            renderDetails(modal.querySelector('#adminConfirmDetails'), opts.details);

            const inputWrap = modal.querySelector('#adminConfirmInputWrap');
            const input = modal.querySelector('#adminConfirmInput');
            inputWrap.hidden = !opts.input;
            input.value = opts.inputValue || '';
            input.placeholder = opts.inputPlaceholder || '';
            modal.querySelector('#adminConfirmInputLabel').textContent = opts.inputLabel || 'Reason';

            modal.classList.add('active');
            document.body.classList.add('modal-open');
            document.addEventListener('keydown', handleConfirmKeydown);

            return new Promise(function (resolve) {
                activeResolve = resolve;
                window.setTimeout(function () {
                    if (opts.input) input.focus();
                    else modal.querySelector('[data-admin-confirm-ok]').focus();
                }, 50);
            });
        }

        window.AdminConfirm = {
            request: function (options) {
                return request(options).then(function (result) { return result.confirmed; });
            },
            prompt: function (options) {
                return request(Object.assign({ input: true }, options || {})).then(function (result) {
                    return result.confirmed ? result.value : null;
                });
            }
        };

        document.addEventListener('click', function (event) {
            const trigger = event.target.closest('[data-admin-confirm]');
            if (!trigger || event.defaultPrevented) return;
            if (trigger.tagName === 'BUTTON' && (trigger.type || '').toLowerCase() === 'submit') return;

            const href = trigger.tagName === 'A' ? trigger.getAttribute('href') : '';
            if (!href || href === '#' || href.indexOf('javascript:') === 0) return;

            event.preventDefault();
            window.AdminConfirm.request(confirmOptionsFrom(trigger)).then(function (confirmed) {
                if (!confirmed) return;
                if (window.AdminPageLoader) window.AdminPageLoader.show(trigger.dataset.adminLoaderText || 'Loading...');
                window.location.href = trigger.href;
            });
        });

        document.addEventListener('submit', function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) return;

            const submitter = event.submitter || document.activeElement;
            const confirmTarget = submitter && submitter.dataset && submitter.dataset.adminConfirm ? submitter : form;
            const hasConfirm = confirmTarget && confirmTarget.dataset && confirmTarget.dataset.adminConfirm;

            if (form.dataset.adminConfirmBypass === '1') {
                delete form.dataset.adminConfirmBypass;
                queueFormLoader(event, form, submitter);
                return;
            }

            if (!hasConfirm || event.defaultPrevented) {
                queueFormLoader(event, form, submitter);
                return;
            }

            event.preventDefault();
            window.AdminConfirm.request(confirmOptionsFrom(confirmTarget)).then(function (confirmed) {
                if (!confirmed) return;
                form.dataset.adminConfirmBypass = '1';
                if (typeof form.requestSubmit === 'function' && submitter && submitter.form === form) {
                    form.requestSubmit(submitter);
                } else if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    HTMLFormElement.prototype.submit.call(form);
                }
            });
        });
    }

    function confirmOptionsFrom(element) {
        return {
            title: element.dataset.adminConfirmTitle || 'Confirm action',
            message: element.dataset.adminConfirm || 'Please confirm this action before continuing.',
            details: element.dataset.adminConfirmDetails ? element.dataset.adminConfirmDetails.split('|') : null,
            confirmText: element.dataset.adminConfirmOk || 'Confirm',
            cancelText: element.dataset.adminConfirmCancel || 'Cancel',
            tone: element.dataset.adminConfirmTone || 'warning',
            icon: element.dataset.adminConfirmIcon || 'fa-shield-alt'
        };
    }

    // ============================================
    // ADMIN PAGE LOADER
    // ============================================

    function getAdminPageLoaderBrand() {
        const brandHeading = document.querySelector('.admin-header-brand h1');
        if (brandHeading && brandHeading.textContent.trim()) {
            return brandHeading.textContent.trim();
        }

        const navBrand = document.querySelector('.admin-nav-title-sub');
        if (navBrand && navBrand.textContent.trim()) {
            return navBrand.textContent.trim();
        }

        const pageTitle = document.title || '';
        if (pageTitle.includes('|')) {
            return pageTitle.split('|').slice(-1)[0].replace(/\s+Admin\s*$/i, '').trim() || 'Hotel Admin';
        }

        return 'Hotel Admin';
    }

    function initAdminPageLoader() {
        if (window.__adminPageLoaderInitialized) return;
        window.__adminPageLoaderInitialized = true;

        let hideTimer = null;
        let unloading = false;
        let suppressBeforeUnloadLoaderUntil = 0;

        function ensureLoader() {
            let loader = document.getElementById('adminPageLoader');
            if (loader) return loader;

            loader = document.createElement('div');
            loader.id = 'adminPageLoader';
            loader.className = 'admin-page-loader';
            loader.setAttribute('aria-live', 'polite');
            loader.setAttribute('aria-hidden', 'true');
            loader.innerHTML = [
                '<div class="admin-page-loader-card">',
                '<div class="admin-page-loader-brand"><i class="fas fa-hotel" aria-hidden="true"></i><span id="adminPageLoaderBrand"></span></div>',
                '<div class="admin-page-loader-spinner" aria-hidden="true">',
                '<span></span><span></span><span></span>',
                '</div>',
                '<div class="admin-page-loader-title">Loading admin workspace</div>',
                '<div class="admin-page-loader-text" id="adminPageLoaderText">Loading...</div>',
                '<div class="admin-page-loader-bar"><span></span></div>',
                '</div>'
            ].join('');
            document.body.appendChild(loader);
            return loader;
        }

        function show(message) {
            const loader = ensureLoader();
            const brand = loader.querySelector('#adminPageLoaderBrand');
            const text = loader.querySelector('#adminPageLoaderText');
            if (brand) brand.textContent = getAdminPageLoaderBrand();
            if (text) text.textContent = message || 'Loading...';
            clearTimeout(hideTimer);
            loader.classList.add('is-visible');
            loader.setAttribute('aria-hidden', 'false');
            document.body.classList.add('admin-page-loading');
        }

        function hide() {
            const loader = document.getElementById('adminPageLoader');
            if (!loader) return;
            loader.classList.remove('is-visible');
            loader.setAttribute('aria-hidden', 'true');
            loader.removeAttribute('data-boot-loader');
            document.body.classList.remove('admin-page-loading');
        }

        window.AdminPageLoader = { show: show, hide: hide };

        document.addEventListener('click', function (event) {
            const link = event.target.closest('a[href]');
            if (link && shouldSuppressBeforeUnloadForLink(link, event)) {
                suppressBeforeUnloadLoaderUntil = Date.now() + 2500;
            }
            if (!link || event.defaultPrevented || !shouldLoadForLink(link, event)) return;
            show(link.dataset.adminLoaderText || 'Opening page...');
            markElementLoading(link, 'Opening...');
        });

        window.addEventListener('beforeunload', function () {
            if (Date.now() < suppressBeforeUnloadLoaderUntil) return;
            unloading = true;
            show('Refreshing admin...');
        });

        window.addEventListener('pageshow', function () {
            unloading = false;
            hide();
        });

        window.addEventListener('pagehide', function () {
            if (!unloading) {
                hide();
            }
        });

        const loader = ensureLoader();
        if (loader.dataset.bootLoader === '1') {
            let bootFinished = false;
            const finishBoot = function () {
                if (bootFinished) return;
                bootFinished = true;
                hide();
            };

            if (document.readyState === 'complete') {
                requestAnimationFrame(finishBoot);
            } else {
                window.addEventListener('load', finishBoot, { once: true });
                // Safety net: a slow/blocked external resource (fonts, CDN icons)
                // can delay or suppress the 'load' event indefinitely, leaving this
                // full-screen overlay intercepting every click on the page.
                window.setTimeout(finishBoot, 4000);
            }
        }
    }

    function shouldSuppressBeforeUnloadForLink(link, event) {
        if (!link || event.defaultPrevented) return false;
        if (isSectionPaginationLink(link)) return true;
        if (link.dataset.noAdminLoader === '1') return true;
        if (link.dataset.noSpa !== undefined) return true;
        if (link.hasAttribute('download')) return true;
        const target = (link.getAttribute('target') || '').toLowerCase();
        if (target && target !== '_self') return true;
        const rawHref = link.getAttribute('href') || '';
        if (/^(mailto|tel|sms|callto):/i.test(rawHref)) return true;
        if (/([?&])(export|download)=/i.test(rawHref)) return true;
        if (/\.pdf(?:[?#]|$)/i.test(rawHref)) return true;
        if (/([?&])export=csv(?:[&#]|$)/i.test(rawHref)) return true;
        return false;
    }

    function shouldLoadForLink(link, event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return false;
        if (isSectionPaginationLink(link)) return false;
        if (link.dataset.noAdminLoader === '1' || link.closest('[data-no-admin-loader="1"]')) return false;
        if (link.hasAttribute('download')) return false;
        const target = (link.getAttribute('target') || '').toLowerCase();
        if (target && target !== '_self') return false;
        const rawHref = link.getAttribute('href') || '';
        if (/([?&])(export|download)=/i.test(rawHref)) return false;
        if (/\.pdf(?:[?#]|$)/i.test(rawHref)) return false;
        if (!rawHref || rawHref === '#' || rawHref.charAt(0) === '#') return false;
        if (/^(javascript|mailto|tel):/i.test(rawHref)) return false;

        let url;
        try { url = new URL(rawHref, window.location.href); }
        catch (err) { return false; }
        if (url.origin !== window.location.origin) return false;
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return false;
        return true;
    }

    function isSectionPaginationLink(link) {
        if (!link || typeof link.closest !== 'function') return false;

        var href = link.getAttribute('href') || '';
        if (!href || href === '#' || href.charAt(0) === '#') return false;

        try {
            var target = new URL(href, window.location.href);
            var current = new URL(window.location.href);
            if (target.origin !== current.origin || target.pathname !== current.pathname) return false;

            var paginationHost = link.closest('[data-admin-pagination], [data-admin-auto-pagination-nav], .bookings-pagination, .pagination, .log-table-pagination, .receipts-pagination, .pagination-bar, .inv-pagination');
            if (paginationHost) return true;

            var hasPaginationParam = false;
            target.searchParams.forEach(function (_value, key) {
                var normalized = String(key || '').toLowerCase();
                if (
                    normalized === 'page' ||
                    normalized === 'p' ||
                    normalized === 'offset' ||
                    normalized === 'start' ||
                    normalized === 'cursor' ||
                    normalized.endsWith('_page') ||
                    normalized.endsWith('_offset') ||
                    normalized.endsWith('_cursor')
                ) {
                    hasPaginationParam = true;
                }
            });
            if (!hasPaginationParam) return false;

            if (!link.closest('#rh-admin-page, .content, .admin-content')) return false;
            if (link.closest('.admin-nav, .admin-header, .admin-sidebar, .breadcrumbs')) return false;

            return true;
        } catch (err) {
            return false;
        }
    }

    function queueFormLoader(event, form, submitter) {
        window.setTimeout(function () {
            if (event.defaultPrevented) return;
            if (form.dataset.noAdminLoader === '1') return;
            if (window.AdminPageLoader) window.AdminPageLoader.show(form.dataset.adminLoaderText || 'Saving changes...');
            markElementLoading(submitter || form.querySelector('button[type="submit"], input[type="submit"]'), form.dataset.adminSubmitText || 'Saving...');
        }, 0);
    }

    function markElementLoading(element, text) {
        if (!element || element.dataset.adminInlineLoading === '1') return;
        if (!element.matches('button, a, input[type="submit"]')) return;

        element.dataset.adminInlineLoading = '1';
        element.dataset.adminOriginalHtml = element.innerHTML || element.value || '';
        if (element.offsetWidth > 0) element.style.minWidth = element.offsetWidth + 'px';
        element.classList.add('admin-inline-loading');
        element.setAttribute('aria-busy', 'true');

        if (element.tagName === 'INPUT') {
            element.value = text || 'Working...';
        } else {
            element.innerHTML = '<span class="admin-inline-spinner" aria-hidden="true"></span><span>' + (text || 'Working...') + '</span>';
        }
        if (element.tagName === 'BUTTON' || element.tagName === 'INPUT') {
            element.disabled = true;
        }
    }

    // Expose admin navigation to global scope if needed by other scripts
    window.initAdminNavigation = initAdminNavigation;
})();

/* ── Global currency-prefix input auto-init ─────────────────────────────────
   Any <input data-currency="MK"> gets automatically wrapped in the shared
   .input-currency-wrap / .input-currency-prefix markup from admin-styles.css.
   Call initCurrencyInputs(root) after dynamic DOM insertions.
   ─────────────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    function wrapInput(input) {
        if (input.closest('.input-currency-wrap') || input.dataset.currencyWrapped) return;
        var symbol = input.dataset.currency || '';
        if (!symbol) return;

        var wrap = document.createElement('div');
        wrap.className = 'input-currency-wrap';

        var prefix = document.createElement('span');
        prefix.className = 'input-currency-prefix';
        prefix.textContent = symbol;
        prefix.setAttribute('aria-hidden', 'true');

        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(prefix);
        wrap.appendChild(input);
        input.dataset.currencyWrapped = '1';
    }

    function initCurrencyInputs(root) {
        var container = root || document;
        container.querySelectorAll('input[data-currency]:not([data-currency-wrapped])').forEach(wrapInput);
    }

    // Run on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initCurrencyInputs(document); });
    } else {
        initCurrencyInputs(document);
    }

    // Re-run when any admin modal opens (covers dynamically populated modals)
    document.addEventListener('modal:open', function (e) {
        var modalId = e.detail && e.detail.modalId;
        var root = modalId ? document.getElementById(modalId) : document;
        initCurrencyInputs(root || document);
    });

    // Expose for dynamic content (modals, SPA page loads)
    window.initCurrencyInputs = initCurrencyInputs;

    // Sync gym currency_code field → prefix text live
    document.addEventListener('input', function (e) {
        var currencyField = e.target.closest('[data-gym-currency-input]');
        if (!currencyField) return;
        var form = currencyField.closest('form, .modal, .form-row');
        if (!form) return;
        var priceInput = form.querySelector('[data-gym-price-input]');
        if (!priceInput) return;
        var prefix = priceInput.closest('.input-currency-wrap')
            ? priceInput.closest('.input-currency-wrap').querySelector('.input-currency-prefix')
            : null;
        if (prefix) prefix.textContent = currencyField.value.trim() || 'MWK';
    });
}());

/* ============================================================
   INSTANT FILTERS (global)
   Any GET filter form auto-applies: selects and date inputs
   submit on change; text/search inputs debounce-submit after
   typing pauses. The typed value and caret survive the reload.
   Opt out per form with data-no-autofilter, per field with
   data-no-autofilter on the input.
   ============================================================ */
(function initInstantFilters() {
    'use strict';

    function isFilterForm(form) {
        if (!form || form.hasAttribute('data-no-autofilter')) return false;
        if (form.hasAttribute('data-live-search-form')) return false; /* page has its own live search */
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        return method === 'get';
    }

    function submitForm(form) {
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
    }

    /* Restore focus + caret to the filter field after a debounce reload */
    var FOCUS_KEY = 'rhInstantFilterFocus';
    try {
        var saved = sessionStorage.getItem(FOCUS_KEY);
        if (saved) {
            sessionStorage.removeItem(FOCUS_KEY);
            var info = JSON.parse(saved);
            if (info && info.page === location.pathname && info.name) {
                var field = document.querySelector('form[method="get" i] [name="' + info.name + '"], form[method="GET"] [name="' + info.name + '"]');
                if (field && (field.type === 'text' || field.type === 'search')) {
                    field.focus();
                    var pos = field.value.length;
                    try { field.setSelectionRange(pos, pos); } catch (e) { /* non-text input */ }
                }
            }
        }
    } catch (e) { /* sessionStorage unavailable */ }

    document.addEventListener('change', function (e) {
        var el = e.target;
        if (!el || el.hasAttribute('data-no-autofilter')) return;
        if (el.hasAttribute('onchange')) return; /* page already auto-applies this field */
        var tag = el.tagName;
        var isSelect = tag === 'SELECT';
        var isDate = tag === 'INPUT' && (el.type === 'date' || el.type === 'month');
        if (!isSelect && !isDate) return;
        var form = el.closest('form');
        if (!isFilterForm(form)) return;
        submitForm(form);
    });

    var debounceTimer = null;
    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el || el.tagName !== 'INPUT') return;
        if (el.type !== 'text' && el.type !== 'search') return;
        if (el.hasAttribute('data-no-autofilter')) return;
        /* Only auto-apply named fields that read as filters/searches */
        var name = (el.getAttribute('name') || '').toLowerCase();
        if (!name || !/search|filter|q\b|query|keyword/.test(name)) return;
        var form = el.closest('form');
        if (!isFilterForm(form)) return;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            try {
                sessionStorage.setItem(FOCUS_KEY, JSON.stringify({ page: location.pathname, name: el.getAttribute('name') }));
            } catch (err) { /* ignore */ }
            submitForm(form);
        }, 650);
    });
}());

/* ── Stale CSRF auto-recovery ─────────────────────────────────────────────
   A tab left open across a re-login holds an outdated csrf_token, so its
   next AJAX action fails with "Security token invalid". Instead of making
   the user decode that, detect the response globally, tell them what is
   happening, and reload once to mint a fresh token. sessionStorage guard
   prevents a reload loop if the token is still invalid after refresh. */
(function () {
    'use strict';
    if (!window.fetch) return;
    var GUARD = 'rhCsrfAutoReloaded';
    var origFetch = window.fetch;
    window.fetch = function () {
        var call = origFetch.apply(this, arguments);
        return call.then(function (resp) {
            try {
                var ct = (resp.headers.get('content-type') || '');
                if (resp.ok && ct.indexOf('application/json') !== -1) {
                    resp.clone().json().then(function (data) {
                        var msg = (data && (data.message || data.error) || '');
                        if (data && data.success === false && /security token invalid/i.test(msg)) {
                            if (sessionStorage.getItem(GUARD)) return; // avoid loop
                            try { sessionStorage.setItem(GUARD, '1'); } catch (e) {}
                            if (window.Alert && Alert.show) {
                                Alert.show('Your session was refreshed — reloading the page…', 'info');
                            }
                            setTimeout(function () { location.reload(); }, 1200);
                        }
                    }).catch(function () { /* not JSON we care about */ });
                }
            } catch (e) { /* never break the original call */ }
            return resp;
        });
    };
    /* Clear the guard once a page load produces a working token again */
    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(function () {
            try { sessionStorage.removeItem(GUARD); } catch (e) {}
        }, 5000);
    });
}());
