(function () {
    'use strict';

    if (window.__adminSectionPaginationInitialized) return;
    window.__adminSectionPaginationInitialized = true;

    var PAGE_SIZE = 10;
    // Owner 2026-11-12: every list shows 10 per page and the user can choose to show more. Every auto-paged table
    // and list gets a "Show 10 / 25 / 50 / 100 / All per page" choice next to its pager; an element with
    // data-no-page-size-picker opts out. 0 = All (every row, the pager collapses to the summary).
    var PAGE_SIZE_CHOICES = [10, 25, 50, 100, 0];
    // Plain row lists (dashboard worklists) page the same way as tables: their direct <li> children are the rows.
    var LIST_SELECTOR = 'ul.ck-rows';

    function getLoaderBrandName() {
        var brand = document.querySelector('.admin-header-brand h1');
        if (brand && brand.textContent.trim()) return brand.textContent.trim();

        var navBrand = document.querySelector('.admin-nav-title-sub');
        if (navBrand && navBrand.textContent.trim()) return navBrand.textContent.trim();

        var title = document.title || '';
        if (title.indexOf('|') !== -1) {
            return title.split('|').slice(-1)[0].replace(/\s+Admin\s*$/i, '').trim() || 'Hotel Admin';
        }

        return 'Hotel Admin';
    }

    function ensureScopedLoaderApi() {
        if (window.AdminScopedSectionLoader && typeof window.AdminScopedSectionLoader.show === 'function') {
            return;
        }

        window.AdminScopedSectionLoader = {
            show: function (container, text, options) {
                if (!container) return function () { return; };

                var shownAt = Date.now();
                var minVisibleMs = 320;
                // Use fixed-position overlay so overflow:hidden on container cannot clip it
                var rect = container.getBoundingClientRect();
                var fixedTop = Math.max(0, rect.top);
                var fixedHeight = Math.min(rect.bottom, window.innerHeight) - fixedTop;
                if (fixedHeight < 60) { fixedTop = Math.max(0, (window.innerHeight - 180) / 2); fixedHeight = 180; }
                var loader = document.createElement('div');
                loader.className = 'admin-section-scoped-loader';
                if (options && options.placement === 'near-end') {
                    loader.classList.add('admin-section-scoped-loader--near-end');
                }
                loader.setAttribute('role', 'status');
                loader.setAttribute('aria-live', 'polite');
                loader.setAttribute('aria-label', text || 'Loading section');
                loader.style.cssText = 'position:fixed !important;left:' + Math.round(rect.left) + 'px;top:' + Math.round(fixedTop) + 'px;width:' + Math.round(rect.width) + 'px;height:' + Math.round(fixedHeight) + 'px;right:auto !important;bottom:auto !important;z-index:9998 !important;';
                loader.innerHTML = [
                    '<div class="admin-section-scoped-loader__card">',
                    '<div class="admin-section-scoped-loader__brand"><i class="fas fa-hotel" aria-hidden="true"></i><span>' + getLoaderBrandName() + '</span></div>',
                    '<div class="admin-section-scoped-loader__body">',
                    '<span class="admin-pagination-loader__spinner" aria-hidden="true"></span>',
                    '<span class="admin-pagination-loader__text">' + (text || 'Loading next page...') + '</span>',
                    '</div>',
                    '</div>'
                ].join('');

                container.classList.add('admin-section-loading');
                document.body.appendChild(loader);

                return function () {
                    var elapsed = Date.now() - shownAt;
                    var wait = elapsed >= minVisibleMs ? 0 : (minVisibleMs - elapsed);
                    window.setTimeout(function () {
                        container.classList.remove('admin-section-loading');
                        if (loader.parentNode) loader.parentNode.removeChild(loader);
                    }, wait);
                };
            }
        };
    }

    function isPaginationNav(el) {
        if (!el || el.nodeType !== 1) return false;
        return el.matches('[data-admin-pagination], .bookings-pagination, .pagination, .log-table-pagination, .receipts-pagination, .pagination-bar, .inv-pagination, [data-admin-auto-pagination-nav]');
    }

    function isExplicitPaginationNav(el) {
        return isPaginationNav(el) && !el.matches('[data-admin-auto-pagination-nav], .admin-auto-pagination');
    }

    function resolveTableHost(table) {
        if (!table) return null;
        return table.closest('.table-responsive, .table-wrapper, .table-container, .acct-table-wrap, .invoices-table, .report-table-wrap, [style*="overflow-x:auto"]') || table;
    }

    function resolveSectionScope(table) {
        if (!table) return null;
        // Intentionally excludes broad containers (.content, .table-container, .table-responsive)
        // to avoid false-positive "existing pagination" detection across unrelated sections.
        return table.closest('[data-admin-pagination-scope], .acct-panel, .section-card, .widget, .dashboard-section, .log-section') || table.parentElement;
    }

    function isListElement(el) {
        return !!(el && el.tagName === 'UL');
    }

    // Where the pager sits and what is measured: the table's wrapper, or the list itself (so the
    // pager stays inside the list's own tab pane and hides with it).
    function resolveItemsHost(el) {
        if (!el) return null;
        return isListElement(el) ? el : resolveTableHost(el);
    }

    // Where an existing pager would count as "already paged": a list only looks inside its own
    // pane (or itself), never the whole section, so each tab pane gets its own pager.
    function resolveItemsScope(el) {
        if (!el) return null;
        if (isListElement(el)) return el.closest('[data-admin-pagination-scope], .ck-pane') || el;
        return resolveSectionScope(el);
    }

    function findExistingPaginationNear(table) {
        if (!table) return false;

        var host = resolveItemsHost(table);
        var next = host.nextElementSibling;
        var checks = 0;

        while (next && checks < 5) {
            if (isExplicitPaginationNav(next)) return true;
            if (next.matches('.admin-pagination-loader-wrap, .log-table-pagination-loader-wrap')) {
                next = next.nextElementSibling;
                checks++;
                continue;
            }
            break;
        }

        var sectionScope = resolveItemsScope(table);
        if (sectionScope && sectionScope.querySelector('[data-admin-pagination], .bookings-pagination:not(.admin-auto-pagination), .pagination:not(.admin-auto-pagination), .log-table-pagination:not(.admin-auto-pagination), .receipts-pagination:not(.admin-auto-pagination), .pagination-bar:not(.admin-auto-pagination), .inv-pagination:not(.admin-auto-pagination)')) {
            return true;
        }

        return false;
    }

    function collectDataRows(tbody) {
        return Array.prototype.slice.call(tbody.querySelectorAll(':scope > tr')).filter(function (tr) {
            if (tr.dataset.adminAutoPaginationIgnore === '1') return false;
            if (tr.querySelector('td[colspan], th[colspan]') && tr.children.length === 1) return false;
            return true;
        });
    }

    function collectListItems(list) {
        return Array.prototype.slice.call(list.querySelectorAll(':scope > li')).filter(function (li) {
            return li.dataset.adminAutoPaginationIgnore !== '1';
        });
    }

    // One items source for both kinds: tables page their tbody > tr rows, lists their > li items.
    function resolveItemsContainer(el) {
        if (isListElement(el)) return el;
        return (el.tBodies && el.tBodies.length) ? el.tBodies[0] : null;
    }

    function collectItems(el, container) {
        return isListElement(el) ? collectListItems(container) : collectDataRows(container);
    }

    function buildWindow(currentPage, totalPages) {
        var out = [];
        var start = Math.max(1, currentPage - 2);
        var end = Math.min(totalPages, currentPage + 2);

        if (start > 1) {
            out.push(1);
            if (start > 2) out.push('ellipsis');
        }

        for (var p = start; p <= end; p++) out.push(p);

        if (end < totalPages) {
            if (end < totalPages - 1) out.push('ellipsis');
            out.push(totalPages);
        }

        return out;
    }

    function createButton(label, onClick, disabled) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pg-btn';
        btn.textContent = label;
        btn.disabled = !!disabled;
        if (!disabled && typeof onClick === 'function') {
            btn.addEventListener('click', onClick);
        }
        return btn;
    }

    function withInlineLoader(nav, message, renderFn) {
        if (!nav || typeof renderFn !== 'function') {
            if (typeof renderFn === 'function') renderFn();
            return;
        }

        ensureScopedLoaderApi();
        nav.classList.add('is-loading');
        var tableHostSelector = '.table-responsive, .table-wrapper, .table-container, .acct-table-wrap, .invoices-table, .report-table-wrap';
        var scopedContainer = nav.closest(tableHostSelector);

        if (!scopedContainer) {
            var prevSib = nav.previousElementSibling;
            while (prevSib && !scopedContainer) {
                if (prevSib.matches && prevSib.matches(tableHostSelector)) {
                    scopedContainer = prevSib;
                }
                prevSib = prevSib.previousElementSibling;
            }
        }

        if (!scopedContainer) {
            var nextSib = nav.nextElementSibling;
            while (nextSib && !scopedContainer) {
                if (nextSib.matches && nextSib.matches(tableHostSelector)) {
                    scopedContainer = nextSib;
                }
                nextSib = nextSib.nextElementSibling;
            }
        }

        if (!scopedContainer) {
            scopedContainer = nav.closest('[data-admin-pagination-scope], .section-card, .widget, .dashboard-section') || nav.parentElement || nav;
        }

        var hideScopedLoader = window.AdminScopedSectionLoader.show(scopedContainer, message || 'Loading next page...');

        window.setTimeout(function () {
            try {
                renderFn();
            } finally {
                nav.classList.remove('is-loading');
                hideScopedLoader();
            }
        }, 170);
    }

    function mountPagination(table) {
        if (!table || table.dataset.adminAutoPaginationBound === '1') return;
        if (findExistingPaginationNear(table)) return;

        // "table" is the paged element: a <table> (tbody > tr rows) or a row list (ul > li items).
        var isList = isListElement(table);
        var tbody = resolveItemsContainer(table);
        if (!tbody) return;

        var rows = collectItems(table, tbody);
        if (rows.length <= PAGE_SIZE) return;

        table.dataset.adminAutoPaginationBound = '1';

        var hasSizePicker = !table.closest('[data-no-page-size-picker]');
        var pageSize = PAGE_SIZE; // 0 = All

        // Rows a page script has filtered out (data-filter-hidden="1") do not count: pages, the
        // "Showing x-y of n" summary and the pager all follow the rows that match the filter.
        var totalRows = rows.length;
        var totalPages = Math.ceil(totalRows / pageSize);
        var currentPage = 1;

        function matchingRows() {
            return rows.filter(function (row) { return row.dataset.filterHidden !== '1'; });
        }

        // Rows on one page; "All" (0) means every row on a single page.
        function perPage() {
            return pageSize > 0 ? pageSize : Math.max(1, rows.length);
        }

        function syncTotals() {
            totalRows = matchingRows().length;
            totalPages = Math.max(1, Math.ceil(totalRows / perPage()));
        }

        var nav = document.createElement('nav');
        nav.className = 'bookings-pagination admin-auto-pagination';
        nav.setAttribute('data-admin-auto-pagination-nav', '1');
        nav.setAttribute('aria-label', isList ? 'List pagination' : 'Table pagination');

        var host = resolveItemsHost(table);

        // A list in a tab pane that starts hidden has no size yet: measure only once it is shown.
        function hostIsVisible() {
            return host.getClientRects().length > 0;
        }
        if (host.nextSibling) {
            host.parentNode.insertBefore(nav, host.nextSibling);
        } else {
            host.parentNode.appendChild(nav);
        }

        function scrollHostIntoView() {
            var y = host.getBoundingClientRect().top + window.scrollY - 80;
            if (y < 0) y = 0;
            window.scrollTo({ top: y, behavior: 'smooth' });
        }

        // Keep the pager in one place on every page: measure the tallest page once and pad the
        // pager down by however much shorter the current page is (rows themselves never stretch).
        var tallestPage = 0;
        var baseMargin = null;

        function showRows(page) {
            var size = perPage();
            var from = (page - 1) * size;
            var to = from + size;
            var index = 0;
            var firstShown = false;
            rows.forEach(function (row) {
                if (isList) row.classList.remove('rh-page-first');
                if (row.dataset.filterHidden === '1') { row.hidden = true; return; }
                row.hidden = !(index >= from && index < to);
                // A list's first row on this page drops its top divider (CSS .rh-page-first).
                if (isList && !row.hidden && !firstShown) { row.classList.add('rh-page-first'); firstShown = true; }
                index++;
            });
        }

        function measureTallestPage() {
            tallestPage = 0;
            if (!hostIsVisible()) return; // hidden pane: stays 0, the next render after it shows measures
            var limit = Math.min(totalPages, 60);
            for (var pg = 1; pg <= limit; pg++) {
                showRows(pg);
                tallestPage = Math.max(tallestPage, host.getBoundingClientRect().height);
            }
        }

        function holdPagerPosition() {
            if (!hostIsVisible()) {
                tallestPage = 0;
                nav.style.marginTop = '';
                return;
            }
            if (baseMargin === null) baseMargin = parseFloat(window.getComputedStyle(nav).marginTop) || 0;
            var height = host.getBoundingClientRect().height;
            if (height > tallestPage) tallestPage = height; // a page taller than first measured
            var deficit = tallestPage - height;
            nav.style.marginTop = deficit > 0.5 ? (baseMargin + deficit) + 'px' : '';
        }

        // Row heights settle late (web fonts, images, cells filled by other scripts): re-measure
        // then, and on resize, so the held height matches what the user actually sees.
        function remeasure() {
            if (!host.isConnected) return;
            rows = collectItems(table, tbody);
            measureTallestPage();
            showRows(currentPage);
            holdPagerPosition();
        }
        var resizeTimer = null;
        window.addEventListener('resize', function () {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(remeasure, 150);
        });
        if (document.readyState !== 'complete') {
            window.addEventListener('load', function () { window.setTimeout(remeasure, 50); }, { once: true });
        }
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () { window.setTimeout(remeasure, 50); });
        }
        window.setTimeout(remeasure, 1200);

        function renderPage(targetPage, shouldScroll) {
            rows = collectItems(table, tbody); // follow the live DOM order (a header sort re-orders rows)
            syncTotals();
            currentPage = Math.max(1, Math.min(totalPages, targetPage));
            var size = perPage();
            var from = (currentPage - 1) * size;
            var to = from + size;

            if (!tallestPage) measureTallestPage();
            showRows(currentPage);
            holdPagerPosition();

            nav.innerHTML = '';

            nav.appendChild(createButton('‹ Prev', function () {
                withInlineLoader(nav, 'Loading previous page...', function () {
                    renderPage(currentPage - 1, true);
                });
            }, currentPage <= 1));

            buildWindow(currentPage, totalPages).forEach(function (item) {
                if (item === 'ellipsis') {
                    var ellipsis = document.createElement('span');
                    ellipsis.className = 'pg-ellipsis';
                    ellipsis.innerHTML = '&hellip;';
                    nav.appendChild(ellipsis);
                    return;
                }

                if (item === currentPage) {
                    var current = document.createElement('span');
                    current.className = 'pg-current';
                    current.textContent = String(item);
                    nav.appendChild(current);
                    return;
                }

                nav.appendChild(createButton(String(item), function () {
                    var target = item;
                    withInlineLoader(nav, 'Loading page ' + target + '...', function () {
                        renderPage(target, true);
                    });
                }, false));
            });

            nav.appendChild(createButton('Next ›', function () {
                withInlineLoader(nav, 'Loading next page...', function () {
                    renderPage(currentPage + 1, true);
                });
            }, currentPage >= totalPages));

            var summary = document.createElement('span');
            summary.className = 'pg-summary';
            summary.textContent = totalRows === 0 ? 'Showing 0 of 0' : 'Showing ' + (from + 1) + '–' + Math.min(to, totalRows) + ' of ' + totalRows;
            // One page or none: the page buttons would only be disabled, so show just the summary.
            nav.classList.toggle('admin-auto-pagination--single', pageSize === 0 || totalRows <= pageSize);
            nav.appendChild(summary);

            if (hasSizePicker) {
                var sizeLabel = document.createElement('label');
                sizeLabel.className = 'pg-size';
                sizeLabel.appendChild(document.createTextNode('Show '));
                var sizeSelect = document.createElement('select');
                sizeSelect.setAttribute('aria-label', 'Rows per page');
                PAGE_SIZE_CHOICES.forEach(function (n) {
                    var opt = document.createElement('option');
                    opt.value = n === 0 ? 'all' : String(n);
                    opt.textContent = n === 0 ? 'All' : String(n);
                    if (n === pageSize) opt.selected = true;
                    sizeSelect.appendChild(opt);
                });
                sizeSelect.addEventListener('change', function () {
                    var next = sizeSelect.value === 'all' ? 0 : parseInt(sizeSelect.value, 10);
                    if (PAGE_SIZE_CHOICES.indexOf(next) === -1) return;
                    pageSize = next;
                    tallestPage = 0;
                    renderPage(1, false);
                });
                sizeLabel.appendChild(sizeSelect);
                sizeLabel.appendChild(document.createTextNode(' per page'));
                nav.appendChild(sizeLabel);
            }

            if (shouldScroll) {
                window.requestAnimationFrame(scrollHostIntoView);
            }
        }

        // Page scripts that filter rows call table.rhPagerRefresh(true) after flagging rows with
        // data-filter-hidden: back to page 1 (or stay on the current page) with fresh counts.
        // Lists expose the same call on the <ul> (e.g. after a dashboard tab shows its pane).
        table.rhPagerRefresh = function (resetPage) {
            tallestPage = 0;
            renderPage(resetPage ? 1 : currentPage, false);
        };

        renderPage(1, false);
    }

    function initGlobalSectionPagination() {
        var root = document.getElementById('rh-admin-page') || document;
        if (!root) return;

        var tables = Array.prototype.slice.call(root.querySelectorAll('table'));
        tables.forEach(function (table) {
            if (table.closest('[data-disable-auto-pagination], .no-auto-pagination')) return;
            // A table inside another table's row (e.g. a visitor's pages-viewed list in an
            // expandable row) is a detail list: paginating it put its pager under the outer
            // table's wrapper, stacking several stray pagers there.
            if (table.parentElement && table.parentElement.closest('table')) return;
            // POS log table has its own user-filter pagination logic.
            if (table.classList.contains('log-table') && table.querySelector('.pos-user-cell')) return;
            mountPagination(table);
        });

        var lists = Array.prototype.slice.call(root.querySelectorAll(LIST_SELECTOR));
        lists.forEach(function (list) {
            if (list.closest('[data-disable-auto-pagination], .no-auto-pagination')) return;
            mountPagination(list);
        });
    }

    function scheduleInit(delay) {
        window.setTimeout(initGlobalSectionPagination, typeof delay === 'number' ? delay : 0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            scheduleInit(0);
        }, { once: true });
    } else {
        scheduleInit(0);
    }

    document.addEventListener('rh:content-updated', function () {
        scheduleInit(50);
    });

    window.AdminAutoSectionPagination = {
        init: initGlobalSectionPagination,
        pageSize: PAGE_SIZE
    };
})();
