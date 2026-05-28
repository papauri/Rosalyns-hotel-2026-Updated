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
        initMobileNavToggle();
        enhanceMobileTables();
        addTableDataLabels();
        detectOverflowingTables();
        addTouchGestures();
        optimizeQuickActions();
    }

    /**
     * Initialize mobile navigation toggle
     */
    function initMobileNavToggle() {
        const navToggle = document.querySelector('.admin-nav-toggle');
        const adminNav = document.querySelector('.admin-nav');
        const adminHeader = document.querySelector('.admin-header');

        if (!navToggle || !adminNav) return;

        // Function to update nav position based on header height
        function updateNavPosition() {
            if (window.innerWidth <= 768 && adminHeader) {
                const headerHeight = adminHeader.offsetHeight;
                adminNav.style.top = headerHeight + 'px';
            } else {
                adminNav.style.top = '';
            }
        }

        // Update position on load
        updateNavPosition();

        // Update position on window resize
        window.addEventListener('resize', updateNavPosition);

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
            if (window.innerWidth > 768) {
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
        const clone = table.cloneNode(true);
        clone.style.position = 'absolute';
        clone.style.visibility = 'hidden';
        clone.style.left = '-99999px';
        clone.style.top = '0';
        clone.style.width = 'max-content';
        clone.style.maxWidth = 'none';
        clone.style.whiteSpace = 'nowrap';
        clone.style.tableLayout = 'auto';
        document.body.appendChild(clone);
        const measured = clone.scrollWidth || clone.offsetWidth || 0;
        document.body.removeChild(clone);
        return measured;
    }

    function shouldUseCardLayout(table) {
        if (!(table instanceof HTMLTableElement)) {
            return false;
        }

        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        if (table.classList.contains('tablet-table')) {
            const availableWidth = getTableAvailableWidth(table);
            const headerCount = table.querySelectorAll('thead th').length;
            const firstRow = table.querySelector('tbody tr');
            const bodyColumnCount = firstRow ? firstRow.querySelectorAll('td').length : 0;
            const columnCount = Math.max(headerCount, bodyColumnCount, 1);
            const measuredRequiredWidth = getRequiredTableWidth(table);
            const minColumnWidth = columnCount >= 7 ? 150 : (columnCount >= 5 ? 138 : 124);
            const heuristicWidth = (columnCount * minColumnWidth) + 24;
            const requiredWidth = Math.max(measuredRequiredWidth, heuristicWidth);

            if (viewportWidth <= 640) {
                return true;
            }
            return availableWidth < requiredWidth;
        }
        if (viewportWidth <= 1024) {
            return true;
        }

        const availableWidth = getTableAvailableWidth(table);
        const headerCount = table.querySelectorAll('thead th').length;
        const firstRow = table.querySelector('tbody tr');
        const bodyColumnCount = firstRow ? firstRow.querySelectorAll('td').length : 0;
        const columnCount = Math.max(headerCount, bodyColumnCount);

        if (columnCount >= 6 && availableWidth <= 980) {
            return true;
        }

        const hasHorizontalOverflow = table.scrollWidth > availableWidth + 8;
        if (hasHorizontalOverflow && availableWidth <= 1100) {
            return true;
        }

        return false;
    }

    function getCardFieldColumnCount(table) {
        const availableWidth = getTableAvailableWidth(table);
        // Standardized card-field density: use 2 columns when it comfortably fits.
        const usableWidth = Math.max(0, availableWidth - 20);
        const minFieldColumnWidth = 150;
        const fieldGap = 12;
        if (usableWidth >= ((minFieldColumnWidth * 2) + fieldGap)) {
            return 2;
        }

        return 1;
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

            cells.forEach((cell, index) => {
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
        table.style.setProperty('--mobile-card-cols', String(getCardFieldColumnCount(table)));
        setCardModeWrappers(table, true);
    }

    /**
     * Restore table from card layout
     */
    function restoreTableFromCards(table) {
        table.classList.remove('mobile-enhanced');
        table.style.removeProperty('--mobile-card-cols');
        setCardModeWrappers(table, false);
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
                cells.forEach((cell, index) => {
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
