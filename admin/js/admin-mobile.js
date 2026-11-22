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

    // Tick cells in the compact list rows (tablet/phone only): the whole 44px cell is the touch
    // target for its checkbox. A click on the cell outside the box and its label presses the box
    // itself (same change/click events as a real tap), so page handlers see nothing new.
    document.addEventListener('click', function (e) {
        if ((window.innerWidth || 0) > 1024 || !e.target || !e.target.closest) { return; }
        var cell = e.target.closest('table.mobile-enhanced > tbody > tr > td.rh-ph-tick, table.mobile-enhanced > thead > tr > th');
        if (!cell || e.target.closest('input, label, a, button')) { return; }
        var box = cell.querySelector('input[type="checkbox"]:not([disabled])');
        if (box) { box.click(); }
    });

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
            navToggle.setAttribute('aria-label', isExpanded ? 'Close navigation' : 'Open navigation');

            // Opening: bring the current page's row to the middle of the menu
            if (isExpanded) {
                requestAnimationFrame(function () {
                    const current = adminNav.querySelector('.admin-nav-link.active:not(.favorite-link)');
                    if (!current) return;
                    const navBox = adminNav.getBoundingClientRect();
                    const rowBox = current.getBoundingClientRect();
                    adminNav.scrollTop += (rowBox.top - navBox.top) - (navBox.height - rowBox.height) / 2;
                });
            }

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

    // True when the plain table is wider than the box that actually clips it (its scroll wrapper,
    // else its parent) - the card padding is NOT part of that box.
    function tableOverflows(table) {
        const box = table.closest('.table-responsive, .table-container, .table-wrapper, .admin-table-wrap, .admin-table-container') || table.parentElement;
        if (!(box instanceof HTMLElement) || box.clientWidth <= 0) { return false; }
        return table.scrollWidth > box.clientWidth + 2 || box.scrollWidth > box.clientWidth + 2;
    }

    // Cells whose text must never break mid-word (references, money, dates, numbers, single
    // tokens) get .rh-nw; CSS makes them nowrap in kept tables, so measurement is honest.
    function markNoWrapCells(table) {
        table.querySelectorAll('td').forEach(function (td) {
            if (td.classList.contains('rh-nw') || td.querySelector('table')) { return; }
            const t = (td.textContent || '').replace(/\s+/g, ' ').trim();
            if (t === '' || !/\s/.test(t) || /^[A-Za-z]{0,4}\.?\s?[\d,.]+\s?%?$/.test(t)
                || /^\d{1,2}[ \/-][A-Za-z0-9]{2,4}[ \/-]\d{2,4}$/.test(t)) {
                td.classList.add('rh-nw');
            }
        });
    }

    function shouldUseCardLayout(table) {
        if (!(table instanceof HTMLTableElement)) {
            return false;
        }

        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;

        // Owner rule (P3-05), applied to every table: on laptop/desktop viewports (> 1024px)
        // always keep a real data table. Its .table-responsive wrapper scrolls sideways if it is
        // too wide; collapsing to cards beside the sidebar made desktop pages a pile of tiles.
        if (viewportWidth > 1024) {
            return false;
        }

        // Owner rule (2026-10-25): on tablet and phone EVERY table is "fit-or-card" by default.
        // Keep a REAL table whenever every column fits the container (words may wrap, no
        // sideways scroll); fall back to cards only when it would overflow. Low-priority
        // columns (th/td class col-optional / col-optional-md, hidden by CSS at narrow widths)
        // drop out BEFORE this measurement, so they never force cards. A table that must always
        // be cards on tablet/phone opts in with class "always-card".
        if (table.classList.contains('always-card')) {
            return true;
        }
        const availableWidth = getTableAvailableWidth(table);
        if (availableWidth <= 0) {
            return false;
        }
        // Measure the LIVE table in plain-table state (card styles hang off .mobile-enhanced), so
        // the page's own scoped CSS (cell padding, nowrap headers, action rows) is what is judged.
        // A clone measured outside the page container would miss those rules. Style recalculation
        // is synchronous, so nothing paints between this restore and the caller's decision.
        restoreTableFromCards(table);
        markNoWrapCells(table);
        return tableOverflows(table);
    }

    function enhanceMobileTables() {
        const apply = () => {
            const tables = getCardableTables();
            tables.forEach(table => {
                if (shouldUseCardLayout(table)) {
                    transformTableToCards(table);
                } else {
                    restoreTableFromCards(table);
                    // Safety net: the clone measurement above runs outside the page's own CSS, so
                    // confirm on the live table that nothing overflows sideways; if it does, cards.
                    const vw = window.innerWidth || document.documentElement.clientWidth || 0;
                    if (vw <= 1024) {
                        if (tableOverflows(table)) {
                            transformTableToCards(table);
                        }
                    }
                }
            });
        };

        apply();

        // Re-measure once fonts and stylesheets have settled: text widths change after first paint.
        window.addEventListener('load', function () { setTimeout(apply, 50); });
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () { setTimeout(apply, 50); });
        }

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

    /** Header text without its "?" help dot (shared helper lives in admin-components.js). */
    function headerLabel(th) {
        if (typeof window.rhHeaderLabel === 'function') return window.rhHeaderLabel(th);
        const copy = th.cloneNode(true);
        copy.querySelectorAll('.help').forEach(function (dot) { dot.remove(); });
        return (copy.textContent || '').replace(/\s+/g, ' ').trim();
    }

    /**
     * Card mode hides the header row, so the "?" column helpers would be unreachable.
     * One "What the columns mean" disclosure above the table lists each helped column
     * (text only, no per-row markup).
     */
    function addColumnHelpNote(table) {
        const anchor = table.closest('.table-responsive, .table-wrapper, .table-container') || table;
        const prev = anchor.previousElementSibling;
        if (prev && prev.classList.contains('rh-col-help')) return;
        const dots = table.querySelectorAll('thead th .help[data-tip]');
        if (!dots.length || !anchor.parentNode) return;

        const details = document.createElement('details');
        details.className = 'rh-col-help';
        const summary = document.createElement('summary');
        summary.textContent = 'What the columns mean';
        details.appendChild(summary);
        const list = document.createElement('dl');
        dots.forEach(function (dot) {
            const th = dot.closest('th');
            const dt = document.createElement('dt');
            dt.textContent = headerLabel(th);
            const dd = document.createElement('dd');
            const raw = dot.getAttribute('data-tip') || '';
            const sep = raw.indexOf('|');
            dd.textContent = sep > 0 ? raw.slice(sep + 1).trim() : raw;
            list.appendChild(dt);
            list.appendChild(dd);
        });
        details.appendChild(list);
        anchor.parentNode.insertBefore(details, anchor);
    }

    function removeColumnHelpNote(table) {
        const anchor = table.closest('.table-responsive, .table-wrapper, .table-container') || table;
        const prev = anchor.previousElementSibling;
        if (prev && prev.classList.contains('rh-col-help')) prev.remove();
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
                    labelText = headerLabel(headers[index]);
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

        // Compact list-row roles (tick / primary / status / actions ...) for the phone + tablet row CSS
        markCompactRows(table);

        // Mark table as mobile-enhanced
        table.classList.add('mobile-enhanced');
        setCardModeWrappers(table, true);
        addColumnHelpNote(table);
    }

    /**
     * Compact list rows (owner 2026-11-22). In card mode every row becomes a dense list row:
     * tick at the left, primary cell + status pill on line 1, the other cells on one muted
     * "Label value" line, actions on the right. This only tags cells with classes (and a short
     * label in data-short); nothing is moved, cloned or renamed, so every handler keeps working.
     * Page opt-ins (classes on the td): rh-ph-primary, rh-ph-meta, rh-ph-money, rh-ph-skip,
     * rh-ph-status. Bookings keeps its own rules. Only runs at tablet/phone widths.
     */
    var CTL_SEL = 'button, a[href], input[type="submit"], input[type="button"], .quick-action, .btn';
    var FIELD_SEL = 'input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea';

    function shortLabel(text) {
        var t = String(text || '').replace(/\([^)]*\)/g, '').replace(/\s*[\/–—-]\s+.*$/, '').replace(/\s+/g, ' ').trim();
        if (t.length > 14) { t = t.split(' ').slice(0, 2).join(' '); }
        if (t.length > 16) { t = t.slice(0, 15).trim() + '…'; }
        return t;
    }

    // Visible text of a cell: the More menu's items (folded actions) are not part of the row's own text.
    function cellText(cell) {
        var menus = cell.querySelectorAll('.actions-more-menu');
        var t;
        if (!menus.length) { t = cell.textContent || ''; }
        else {
            var copy = cell.cloneNode(true);
            copy.querySelectorAll('.actions-more-menu').forEach(function (m) { m.remove(); });
            t = copy.textContent || '';
        }
        return t.replace(/\s+/g, ' ').trim();
    }

    function rowControls(cell) {
        return Array.prototype.filter.call(cell.querySelectorAll(CTL_SEL), function (c) {
            return !c.closest('.actions-more-menu') && !c.hidden && c.type !== 'hidden'
                && !(c.style && c.style.display === 'none');
        });
    }

    function markCompactRows(table) {
        if ((window.innerWidth || document.documentElement.clientWidth || 0) > 1024) { return; }
        if (table.matches('.bookings-table, .receipts-table, .rh-own-rows') || table.closest('.pos-acct-close-form, .pos-acct-sales-wrap')) { return; }
        var tbody = table.querySelector('tbody');
        if (!tbody) { return; }
        Array.prototype.forEach.call(tbody.children, function (row) {
            if (row.tagName !== 'TR' || row.classList.contains('mobile-enhanced-empty-row')) { return; }
            var cells = Array.prototype.filter.call(row.children, function (c) { return c.tagName === 'TD' || c.tagName === 'TH'; });
            if (!cells.length) { return; }
            // Forget our earlier automatic marks (rows are re-measured on every resize / mutation).
            row.classList.remove('rh-act-side', 'rh-act-line', 'rh-act-n1', 'rh-act-n2', 'rh-act-n3', 'rh-act-n4', 'rh-has-tick', 'rh-has-status');
            cells.forEach(function (c) {
                c.classList.remove('rh-ph-tick', 'rh-ph-actions', 'rh-ph-field', 'rh-ph-empty');
                if (c.getAttribute('data-rh-auto')) {
                    c.classList.remove('rh-ph-primary', 'rh-ph-status', 'rh-ph-money');
                    c.removeAttribute('data-rh-auto');
                }
            });
            var primary = null, status = null, actionCell = null;
            cells.forEach(function (c) {
                if (c.classList.contains('rh-ph-primary')) { primary = c; }
                if (c.classList.contains('rh-ph-status')) { status = c; }
            });
            cells.forEach(function (c, i) {
                var label = c.getAttribute('data-label') || '';
                if (label && !c.getAttribute('data-short')) { c.setAttribute('data-short', shortLabel(label)); }
                var text = cellText(c);
                var ctls = rowControls(c);
                var ctlText = ctls.map(function (b) { return (b.textContent || '').replace(/\s+/g, ' ').trim(); }).join(' ').trim();
                var hasCheck = !!c.querySelector('input[type="checkbox"], input[type="radio"]');
                // Tick cell: a checkbox and nothing else readable.
                if (hasCheck && !c.querySelector(FIELD_SEL) && (text === '' || (!ctls.length && text.length <= 2))) {
                    c.classList.add('rh-ph-tick');
                    row.classList.add('rh-has-tick');
                    return;
                }
                // Cell with a form field keeps its label above the field, full width.
                if (c.querySelector(FIELD_SEL)) { c.classList.add('rh-ph-field'); return; }
                // Actions cell: named Actions, or the last cell whose only text is its own buttons.
                var isActLabel = /^(actions?|manage|options)$/i.test(label);
                var restText = ctlText ? text.split(ctlText).join('').trim() : text;
                var onlyControls = ctls.length > 0 && restText === '';
                if (!actionCell && ctls.length && (isActLabel || (i === cells.length - 1 && onlyControls))) {
                    actionCell = c;
                    c.classList.add('rh-ph-actions');
                    var iconOnly = ctls.every(function (b) { return b.classList.contains('actions-more-toggle') || b.querySelector('i, svg, img'); });
                    ctls.forEach(function (b) {
                        var nm = (b.textContent || '').replace(/\s+/g, ' ').trim();
                        if (nm && !b.getAttribute('aria-label') && !b.getAttribute('title')) { b.setAttribute('title', nm); }
                    });
                    if (iconOnly && restText === '' && ctls.length <= 3) {
                        row.classList.add('rh-act-side', 'rh-act-n' + ctls.length);
                    } else {
                        row.classList.add('rh-act-line', 'rh-act-n' + Math.min(ctls.length, 4));
                    }
                    return;
                }
                // Empty value: nothing worth a line.
                if (!ctls.length && (text === '' || /^[—–-]+$/.test(text)) && !c.querySelector('img, svg, .badge')) {
                    c.classList.add('rh-ph-empty');
                }
            });
            // Primary cell: the one a page marked; else the first cell that says WHAT or WHO (not a
            // time or a bare reference); else the first readable cell.
            var usable = cells.filter(function (c) {
                return c !== actionCell && !/(?:^|\s)rh-ph-(?:tick|field|empty|skip)(?:\s|$)/.test(c.className);
            });
            if (!primary && usable.length) {
                var lab = function (c) { return (c.getAttribute('data-label') || '').trim(); };
                var tiers = [
                    /^(what they did|what happened|description|message|event|title|subject|action|item|menu item|details?)\b/i,
                    /^(name|full name|guest|customer|client|user|person|staff|who|ingredient|product|supplier|member|company|room|page|package|class|instructor)\b/i,
                    /^(reference|ref|invoice|receipt|quot|credit|order|booking|payment|batch|number|no\b|#)/i
                ];
                for (var t = 0; t < tiers.length && !primary; t++) {
                    primary = usable.filter(function (c) { return tiers[t].test(lab(c)); })[0] || null;
                }
                if (!primary) {
                    primary = usable.filter(function (c) { return lab(c) !== '' && !/^(when|time|date|day|created|latest time|closed at|shift date|logged|sent|#|id)\b/i.test(lab(c)) && !/^[\d.,\s]+$/.test((c.textContent || '').trim()); })[0] || usable[0];
                }
                primary.classList.add('rh-ph-primary');
                primary.setAttribute('data-rh-auto', '1');
            }
            if (!status) {
                usable.some(function (c) {
                    if (c !== primary && /status|state|result/i.test(c.getAttribute('data-label') || '')
                        && (c.textContent || '').replace(/\s+/g, ' ').trim().length <= 28) {
                        status = c;
                        c.classList.add('rh-ph-status');
                        c.setAttribute('data-rh-auto', '1');
                        return true;
                    }
                    return false;
                });
            }
            if (status) { row.classList.add('rh-has-status'); }
            // Money: one amount per row is pushed to the right end of the muted line and set bold.
            if (!usable.some(function (c) { return c.classList.contains('rh-ph-money'); })) {
                usable.some(function (c) {
                    if (c !== primary && c !== status && /^(amount|total|balance|price|cost|value|outstanding|due)\b/i.test(c.getAttribute('data-label') || '')
                        && (c.textContent || '').replace(/\s+/g, ' ').trim().length <= 24) {
                        c.classList.add('rh-ph-money');
                        c.setAttribute('data-rh-auto', '1');
                        return true;
                    }
                    return false;
                });
            }
        });
    }

    /**
     * Restore table from card layout
     */
    function restoreTableFromCards(table) {
        table.classList.remove('mobile-enhanced');
        setCardModeWrappers(table, false);
        removeColumnHelpNote(table);

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
                        const labelText = headerLabel(headers[index]);
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
