/**
 * Admin Mobile Enhancements
 * Optimizes admin tables and components for 320px screens
 */

(function () {
    'use strict';

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        // Run layout-reading operations inside rAF so they don't block the first
        // paint and avoid the "Forced reflow" console violation.
        initMobileNavToggle();  // has its own rAF guard now
        requestAnimationFrame(function () {
            enhanceMobileTables();
            addTableDataLabels();
            detectOverflowingTables();
            addTouchGestures();
            optimizeQuickActions();
        });
    }

    /**
     * Initialize mobile navigation toggle
     */
    function initMobileNavToggle() {
        const navToggle = document.querySelector('.admin-nav-toggle');
        const adminNav = document.querySelector('.admin-nav');
        const adminHeader = document.querySelector('.admin-header');

        if (!navToggle || !adminNav) return;

        // Read layout once, write in rAF to avoid forced reflow
        function updateNavPosition() {
            if (window.innerWidth <= 1024 && adminHeader) {
                const headerHeight = adminHeader.offsetHeight; // read
                requestAnimationFrame(function () {
                    adminNav.style.top = headerHeight + 'px'; // write
                });
            } else {
                requestAnimationFrame(function () {
                    adminNav.style.top = '';
                });
            }
        }

        // Debounced resize handler to limit reflow frequency
        let _navResizeRaf = null;
        function _debouncedUpdateNavPosition() {
            if (_navResizeRaf) cancelAnimationFrame(_navResizeRaf);
            _navResizeRaf = requestAnimationFrame(updateNavPosition);
        }

        // Update position on load (deferred to after first paint)
        requestAnimationFrame(updateNavPosition);

        // Update position on window resize
        window.addEventListener('resize', _debouncedUpdateNavPosition);

        navToggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            adminNav.classList.toggle('nav-open');

            // Update position when toggling
            updateNavPosition();

            // Update aria-expanded
            const isExpanded = adminNav.classList.contains('nav-open');
            navToggle.setAttribute('aria-expanded', isExpanded);

            // Update icon
            const icon = navToggle.querySelector('i');
            if (icon) {
                if (isExpanded) {
                    icon.classList.remove('fa-bars');
                    icon.classList.add('fa-times');
                } else {
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            }
        });

        // Close nav when clicking outside
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.admin-nav') && !e.target.closest('.admin-nav-toggle')) {
                adminNav.classList.remove('nav-open');
                navToggle.setAttribute('aria-expanded', 'false');

                const icon = navToggle.querySelector('i');
                if (icon) {
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            }
        });

        // Close nav when window is resized to desktop
        window.addEventListener('resize', function () {
            if (window.innerWidth > 1024) {
                adminNav.classList.remove('nav-open');
                navToggle.setAttribute('aria-expanded', 'false');

                const icon = navToggle.querySelector('i');
                if (icon) {
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            }
        });
    }

    /**
     * Transform tables into card layouts on mobile.
     * Picks up EVERY <table> inside common admin containers, not just
     * tables with a specific class — so all dashboards/reports get the
     * card fallback automatically.
     *
     * Opt-out: add class "no-card-mobile" to a table to keep it as a
     * scrolling table on phones (useful for matrix-style numeric tables
     * like accounting period grids).
     */
    function getCardableTables() {
        const containers = [
            '.admin-content',
            '.admin-container',
            '#rh-admin-page',
            '.content',
            'main.admin-main',
            '.page-wrapper',
            '.dashboard-card',
            '.widget',
            '.card',
            '.table-wrapper',
            '.table-responsive'
        ];
        // Combine container queries plus standalone class names already supported
        const explicitClasses = '.table, .admin-table, .booking-table, .bookings-table, .folio-table, ' +
            '.staff-workload-table, .portal-table, .menu-table, .pm-table, ' +
            '.report-table, .users-table, .visitors-table, .cache-table';
        const set = new Set();
        // 1) Tables inside known containers
        containers.forEach(sel => {
            document.querySelectorAll(sel + ' table').forEach(t => set.add(t));
        });
        // 2) Tables with admin classes
        document.querySelectorAll(explicitClasses).forEach(t => set.add(t));
        // Filter out opt-outs and tiny inner tables (e.g., layout tables)
        return Array.from(set).filter(t => {
            if (t.classList.contains('no-card-mobile')) return false;
            // Skip tables nested inside another table (avoids double-transform)
            if (t.parentElement && t.parentElement.closest('table')) return false;
            // Need at least a thead or tbody with rows to be worth converting
            const tbody = t.querySelector('tbody');
            if (!tbody || !tbody.querySelector('tr')) return false;
            return true;
        });
    }

    function setCardModeWrappers(table, enabled) {
        const wrappers = [
            table.closest('.table-container'),
            table.closest('.table-responsive'),
            table.closest('.table-wrapper'),
            table.parentElement && table.parentElement.closest
                ? table.parentElement.closest('.table-container, .table-responsive, .table-wrapper')
                : null
        ].filter((wrapper, index, arr) => wrapper instanceof HTMLElement && arr.indexOf(wrapper) === index);

        wrappers.forEach((wrapper) => {
            wrapper.classList.toggle('mobile-enhanced-wrapper', enabled);
        });
    }

    function getTableAvailableWidth(table) {
        const container = table.closest(
            '.table-container, .table-responsive, .table-wrapper, .card, .widget, .dashboard-card, .section-card, .today-checkins-section, .content-card, .admin-table-wrap, .admin-table-container'
        );

        if (container instanceof HTMLElement && container.clientWidth > 0) {
            return container.clientWidth;
        }

        if (table.parentElement instanceof HTMLElement && table.parentElement.clientWidth > 0) {
            return table.parentElement.clientWidth;
        }

        return window.innerWidth || document.documentElement.clientWidth || 0;
    }

    function getRequiredTableWidth(table) {
        if (!(table instanceof HTMLTableElement)) {
            return 0;
        }

        // Fast path: when already wider than container, this reflects required width.
        const directScrollWidth = table.scrollWidth || 0;
        if (directScrollWidth > 0) {
            return directScrollWidth;
        }

        // Fallback: clone and measure intrinsic width with nowrap to capture
        // long tokens/URLs that would otherwise clip on constrained devices.
        // Use detached host to avoid triggering the body MutationObserver.
        const host = getMeasureHost();
        const clone = table.cloneNode(true);
        clone.style.cssText = 'position:static;width:max-content;max-width:none;white-space:nowrap;table-layout:auto;';
        host.appendChild(clone);
        const measured = clone.scrollWidth || clone.offsetWidth || 0;
        host.removeChild(clone);
        return measured;
    }

    // Detached container for clone measurement — never touches the live DOM tree,
    // so the MutationObserver watching body/document cannot be triggered.
    let _measureHost = null;
    function getMeasureHost() {
        if (!_measureHost) {
            _measureHost = document.createElement('div');
            _measureHost.style.cssText = 'position:absolute;visibility:hidden;left:-99999px;top:0;pointer-events:none;';
            // Append once; never removed — stays outside the subtrees the observer watches
            // (observer targets are .admin-content, .content, main, body children — not this root div itself).
            document.documentElement.appendChild(_measureHost);
        }
        return _measureHost;
    }

    function shouldUseCardLayout(table) {
        if (!(table instanceof HTMLTableElement)) {
            return false;
        }

        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;

        // "fit-or-card": keep a REAL table at ANY width for as long as every
        // column fits inside the container (wrapping allowed) — only fall back
        // to cards when it would otherwise overflow horizontally. This is the
        // behaviour finance/accounting tables want: a proper table on mobile
        // whenever it fits, cards (never a sideways scroll) when it doesn't.
        if (table.classList.contains('fit-or-card')) {
            const availableWidth = getTableAvailableWidth(table);
            if (availableWidth <= 0) {
                return false;
            }
            const host = getMeasureHost();
            const clone = table.cloneNode(true);
            // Render constrained to the real available width and let cells wrap
            // at natural word boundaries (NOT mid-word), then check whether the
            // content still forces overflow. Measuring with word-wrapping — not
            // character-level breaking — means a table only counts as "fits"
            // when it's genuinely readable: a long unbreakable payment reference
            // keeps its column wide and pushes a busy table to cards, while a
            // compact summary table stays a real table.
            clone.style.cssText = 'position:static;width:' + availableWidth + 'px;max-width:' + availableWidth + 'px;table-layout:auto;';
            clone.querySelectorAll('th, td').forEach(function (cell) {
                cell.style.whiteSpace = 'normal';
                cell.style.overflowWrap = 'normal';
                cell.style.wordBreak = 'normal';
            });
            host.appendChild(clone);
            const overflows = clone.scrollWidth > availableWidth + 2;
            host.removeChild(clone);
            return overflows;
        }

        if (table.classList.contains('tablet-table')) {
            // Always card on phones
            if (viewportWidth <= 640) {
                return true;
            }
            // Owner rule (2026-07-14, P3-05): reserve card layout for tablet/mobile.
            // On standard laptop/desktop viewports (> 1024px) always keep a real data
            // table — the .table-responsive wrapper supplies horizontal scroll when a
            // wide table doesn't fit, rather than collapsing to cards.
            if (viewportWidth > 1024) {
                return false;
            }
            const availableWidth = getTableAvailableWidth(table);
            // Clone-measure intrinsic width using a detached host (avoids triggering
            // the MutationObserver that watches body subtree).
            const host = getMeasureHost();
            const clone = table.cloneNode(true);
            clone.style.cssText = 'position:static;width:max-content;max-width:none;white-space:nowrap;table-layout:auto;';
            host.appendChild(clone);
            const intrinsicWidth = clone.scrollWidth || clone.offsetWidth || 0;
            host.removeChild(clone);
            if (intrinsicWidth === 0) {
                // No data to measure — fall through to heuristic
                const headerCount = table.querySelectorAll('thead th').length;
                const firstRow = table.querySelector('tbody tr');
                const columnCount = Math.max(headerCount, firstRow ? firstRow.querySelectorAll('td').length : 0, 1);
                return availableWidth < (columnCount * 90) + 24;
            }
            return availableWidth < intrinsicWidth;
        }
        // Non-tablet-table: only force cards on phones/small tablets (≤768px).
        // Larger screens use overflow detection below so tables are preserved
        // when they actually fit the available space.
        if (viewportWidth <= 768) {
            return true;
        }

        const availableWidth = getTableAvailableWidth(table);
        const headerCount = table.querySelectorAll('thead th').length;
        const firstRow = table.querySelector('tbody tr');
        const bodyColumnCount = firstRow ? firstRow.querySelectorAll('td').length : 0;
        const columnCount = Math.max(headerCount, bodyColumnCount);

        if (columnCount >= 6 && availableWidth <= 960) {
            return true;
        }

        const hasHorizontalOverflow = table.scrollWidth > availableWidth + 8;
        if (hasHorizontalOverflow && availableWidth <= 1024) {
            return true;
        }

        return false;
    }

    function enhanceMobileTables() {
        const apply = () => {
            const tables = getCardableTables();
            tables.forEach(table => {
                if (shouldUseCardLayout(table)) {
                    transformTableToCards(table);
                } else {
                    restoreTableFromCards(table);
                }
            });
        };

        apply();

        let resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(apply, 200);
        });

        // Re-apply when DOM mutates (e.g., AJAX-loaded rows in bookings/reports)
        if (typeof MutationObserver !== 'undefined') {
            const targets = document.querySelectorAll('.admin-content, .content, main, body');
            const observer = new MutationObserver(function (muts) {
                let needsApply = false;
                for (const m of muts) {
                    if (m.addedNodes && m.addedNodes.length) {
                        for (const n of m.addedNodes) {
                            if (n.nodeType === 1 && (n.tagName === 'TR' || n.tagName === 'TABLE' || (n.querySelector && n.querySelector('table')))) {
                                needsApply = true; break;
                            }
                        }
                    }
                    if (needsApply) break;
                }
                if (needsApply) apply();
            });
            targets.forEach(t => observer.observe(t, { childList: true, subtree: true }));
        }
    }

    /**
     * Transform table rows into card layout
     */
    function transformTableToCards(table) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const rows = tbody.querySelectorAll('tr');
        const headers = table.querySelectorAll('thead th');

        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            const firstCell = cells.length === 1 ? cells[0] : null;
            const firstCellColspan = firstCell ? parseInt(firstCell.getAttribute('colspan') || '1', 10) : 1;
            const firstCellClass = firstCell ? String(firstCell.className || '') : '';
            const isEmptyStateRow = !!firstCell && (
                firstCellColspan > 1 ||
                /(?:^|\s)(?:no-data|table-empty|empty|no-results)(?:\s|$)/i.test(firstCellClass)
            );

            row.classList.toggle('mobile-enhanced-empty-row', isEmptyStateRow);

            cells.forEach((cell, index) => {
                if (isEmptyStateRow) {
                    cell.classList.add('mobile-enhanced-empty-cell');
                    cell.removeAttribute('data-label');
                    return;
                }

                cell.classList.remove('mobile-enhanced-empty-cell');

                // Get header text for this column
                let labelText = '';
                if (headers[index]) {
                    labelText = headers[index].textContent.trim();
                } else {
                    // Fallback: try to get label from data-label attribute
                    labelText = cell.getAttribute('data-label') || '';
                }

                // Set data-label attribute for CSS
                if (labelText && !cell.getAttribute('data-label')) {
                    cell.setAttribute('data-label', labelText);
                }
            });
        });

        // Mark table as mobile-enhanced
        table.classList.add('mobile-enhanced');
        setCardModeWrappers(table, true);
    }

    /**
     * Restore table from card layout
     */
    function restoreTableFromCards(table) {
        table.classList.remove('mobile-enhanced');
        setCardModeWrappers(table, false);

        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        tbody.querySelectorAll('tr.mobile-enhanced-empty-row').forEach((row) => {
            row.classList.remove('mobile-enhanced-empty-row');
        });
        tbody.querySelectorAll('td.mobile-enhanced-empty-cell').forEach((cell) => {
            cell.classList.remove('mobile-enhanced-empty-cell');
        });
    }

    /**
     * Add data-label attributes to table cells
     * These are used by CSS to show labels on mobile
     */
    function addTableDataLabels() {
        const tables = getCardableTables();
        tables.forEach(table => {
            const headers = table.querySelectorAll('thead th');
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            const rows = tbody.querySelectorAll('tr');
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const firstCell = cells.length === 1 ? cells[0] : null;
                const firstCellColspan = firstCell ? parseInt(firstCell.getAttribute('colspan') || '1', 10) : 1;
                const firstCellClass = firstCell ? String(firstCell.className || '') : '';
                const isEmptyStateRow = !!firstCell && (
                    firstCellColspan > 1 ||
                    /(?:^|\s)(?:no-data|table-empty|empty|no-results)(?:\s|$)/i.test(firstCellClass)
                );

                cells.forEach((cell, index) => {
                    if (isEmptyStateRow) {
                        cell.removeAttribute('data-label');
                        return;
                    }

                    if (headers[index] && !cell.getAttribute('data-label')) {
                        const labelText = headers[index].textContent.trim();
                        if (labelText) cell.setAttribute('data-label', labelText);
                    }
                });
            });
        });
    }

    /**
     * Detect tables that overflow and add scroll indicator
     */
    function detectOverflowingTables() {
        const tableContainers = document.querySelectorAll('.table-responsive');

        tableContainers.forEach(container => {
            checkOverflow(container);

            // Re-check on resize
            window.addEventListener('resize', function () {
                checkOverflow(container);
            });
        });
    }

    function checkOverflow(container) {
        const table = container.querySelector('table');
        if (!table) return;

        if (table.scrollWidth > container.clientWidth) {
            container.classList.add('overflowing');
        } else {
            container.classList.remove('overflowing');
        }
    }

    /**
     * Add touch gestures for mobile tables
     */
    function addTouchGestures() {
        const tableContainers = document.querySelectorAll('.table-responsive');

        tableContainers.forEach(container => {
            let startX = 0;
            let scrollLeft = 0;

            container.addEventListener('touchstart', function (e) {
                startX = e.touches[0].pageX - container.offsetLeft;
                scrollLeft = container.scrollLeft;
            }, { passive: true });

            container.addEventListener('touchmove', function (e) {
                if (!startX) return;

                const x = e.touches[0].pageX - container.offsetLeft;
                const walk = (x - startX) * 2; // Scroll-fast
                container.scrollLeft = scrollLeft - walk;
            }, { passive: true });

            container.addEventListener('touchend', function () {
                startX = 0;
            });
        });
    }

    /**
     * Optimize quick action buttons on mobile
     */
    function optimizeQuickActions() {
        if (window.innerWidth <= 480) {
            const quickActions = document.querySelectorAll('.quick-action');

            quickActions.forEach(button => {
                // Add title attribute for tooltips
                if (!button.getAttribute('title')) {
                    const buttonText = button.textContent.trim();
                    if (buttonText) {
                        button.setAttribute('title', buttonText);
                    }
                }
            });

            // Group action buttons into dropdown if too many
            const actionCells = document.querySelectorAll('td:last-child');

            actionCells.forEach(cell => {
                // Pages with a native More-menu system (for example bookings)
                // must keep their original DOM structure intact.
                if (cell.querySelector('.actions-more, .actions-more-toggle, .actions-more-menu')) {
                    return;
                }

                const buttons = cell.querySelectorAll('.quick-action, .btn');

                if (buttons.length > 3) {
                    createActionsDropdown(cell, buttons);
                }
            });
        }
    }

    /**
     * Create dropdown for action buttons
     */
    function createActionsDropdown(cell, buttons) {
        // Check if already converted
        if (cell.querySelector('.actions-dropdown')) return;

        // Do not interfere with custom action overflow menus.
        if (cell.querySelector('.actions-more, .actions-more-toggle, .actions-more-menu')) return;

        // Create dropdown container
        const dropdown = document.createElement('div');
        dropdown.className = 'actions-dropdown';
        dropdown.innerHTML = `
            <button class="actions-dropdown-toggle" onclick="toggleActionsDropdown(this)">
                <i class="fas fa-ellipsis-v"></i>
            </button>
            <div class="actions-dropdown-menu">
                <!-- Buttons will be moved here -->
            </div>
        `;

        // Move buttons to dropdown (except first 2)
        const buttonsToMove = Array.from(buttons).slice(2);
        const dropdownMenu = dropdown.querySelector('.actions-dropdown-menu');

        buttonsToMove.forEach(btn => {
            const wrapper = document.createElement('div');
            wrapper.className = 'dropdown-item';
            wrapper.appendChild(btn);
            dropdownMenu.appendChild(wrapper);
        });

        cell.appendChild(dropdown);
    }

    /**
     * Toggle actions dropdown
     */
    window.toggleActionsDropdown = function (toggleBtn) {
        const dropdown = toggleBtn.closest('.actions-dropdown');
        const menu = dropdown.querySelector('.actions-dropdown-menu');

        // Close other dropdowns
        document.querySelectorAll('.actions-dropdown.active').forEach(other => {
            if (other !== dropdown) {
                other.classList.remove('active');
            }
        });

        // Toggle current dropdown
        dropdown.classList.toggle('active');
    };

    /**
     * Close dropdowns when clicking outside
     */
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.actions-dropdown')) {
            document.querySelectorAll('.actions-dropdown.active').forEach(dropdown => {
                dropdown.classList.remove('active');
            });
        }
    });

    /**
     * Add swipe functionality to tabs on mobile
     */
    function initTabSwipeGestures() {
        // Target all tab header types
        const tabSelectors = [
            '.tabs-header',
            '.filter-tabs',
            '.report-tabs',
            '.menu-type-tabs',
            '.tab-nav'
        ];

        const tabHeaders = document.querySelectorAll(tabSelectors.join(','));

        tabHeaders.forEach(header => {
            let startX = 0;
            let scrollLeft = 0;

            header.addEventListener('touchstart', function (e) {
                startX = e.touches[0].pageX - header.offsetLeft;
                scrollLeft = header.scrollLeft;
            }, { passive: true });

            header.addEventListener('touchmove', function (e) {
                const x = e.touches[0].pageX - header.offsetLeft;
                const walk = (x - startX) * 1.5;
                header.scrollLeft = scrollLeft - walk;
            }, { passive: true });
        });
    }

    // Initialize tab swipe gestures
    setTimeout(initTabSwipeGestures, 100);

})();

/* ============================================================
 * Global Modal UX — ESC to close + backdrop-click to close
 * Works across all admin pages without touching each page's JS.
 * Detects modals by: [data-modal], .modal (with display:flex/block),
 * and .overlay that are visibly open.
 * ============================================================ */
(function () {
    'use strict';

    /**
     * Return true if an element is considered "visibly open".
     * Handles both inline-style modals (display:block/flex) and
     * class-based modals (.active, .modal--active, .show, .open).
     */
    function isVisible(el) {
        if (!el) return false;
        // Quick class-based check (most common patterns)
        if (el.classList.contains('active') ||
            el.classList.contains('modal--active') ||
            el.classList.contains('show') ||
            el.classList.contains('open')) {
            // Confirm it's not hidden by an inline style override
            if (el.style.display === 'none') return false;
            return true;
        }
        // Inline style check
        const d = el.style.display;
        if (d === 'none' || d === '') return false;
        if (d === 'block' || d === 'flex' || d === 'grid') return true;
        // Computed style fallback
        try {
            const cs = window.getComputedStyle(el);
            return cs.display !== 'none' && cs.visibility !== 'hidden' && parseFloat(cs.opacity) > 0;
        } catch (err) { return false; }
    }

    /**
     * Try to close a modal element gracefully.
     * Prefers a visible × button, otherwise hides the element directly.
     */
    function tryClose(el) {
        if (!el) return;
        // 1. Call a named close function if the element has one registered
        //    via onclick="closeXxxModal()" pattern
        const closeBtn = el.querySelector(
            'button.close-modal, button[data-modal-close], .modal__close, ' +
            '.rh-modal-close, button.modal-close, button[aria-label="Close"], ' +
            'button[aria-label="Close modal"]'
        );
        if (closeBtn) { closeBtn.click(); return; }

        // 2. Class-based hide (e.g. .modal-overlay.active, .overlay.show)
        el.classList.remove('modal--active', 'active', 'show', 'open');

        // 3. Inline style hide
        if (el.style.display && el.style.display !== 'none') {
            el.style.display = 'none';
        }
    }

    /**
     * Collect all open modals on the page.
     */
    function openModals() {
        const candidates = document.querySelectorAll(
            '.modal, [data-modal], .booking-modal-overlay, .modal-overlay, .overlay'
        );
        return Array.from(candidates).filter(isVisible);
    }

    // ESC key — close the topmost open modal
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        const open = openModals();
        if (!open.length) return;
        e.preventDefault();
        tryClose(open[open.length - 1]);
    });

    // Backdrop click — click directly on the modal root (not its content)
    // Standard pattern: the .modal element IS the full-screen backdrop,
    // and .modal-content / .modal__container is the inner card.
    document.addEventListener('click', function (e) {
        const modal = e.target.closest('.modal, [data-modal], .modal-overlay, .booking-modal-overlay, .overlay');
        if (!modal) return;
        // Only if we clicked exactly on the backdrop root (not a child card)
        if (e.target !== modal) return;
        if (!isVisible(modal)) return;
        // Don't close if the modal explicitly opts out
        if (modal.dataset.closeOnOverlay === 'false') return;
        tryClose(modal);
    });

    // data-modal-close attribute — click on backdrop or a tagged element closes parent modal
    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-modal-close]');
        if (!trigger) return;
        const modal = trigger.closest('[data-modal]');
        if (modal) { tryClose(modal); }
    });

}());
