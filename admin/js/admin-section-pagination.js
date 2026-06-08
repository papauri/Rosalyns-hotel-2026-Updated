(function () {
    'use strict';

    if (window.__adminSectionPaginationInitialized) return;
    window.__adminSectionPaginationInitialized = true;

    var PAGE_SIZE = 10;

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

    function findExistingPaginationNear(table) {
        if (!table) return false;

        var host = resolveTableHost(table);
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

        var sectionScope = resolveSectionScope(table);
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

        var tbody = (table.tBodies && table.tBodies.length) ? table.tBodies[0] : null;
        if (!tbody) return;

        var rows = collectDataRows(tbody);
        if (rows.length <= PAGE_SIZE) return;

        table.dataset.adminAutoPaginationBound = '1';

        var totalRows = rows.length;
        var totalPages = Math.ceil(totalRows / PAGE_SIZE);
        var currentPage = 1;

        var nav = document.createElement('nav');
        nav.className = 'bookings-pagination admin-auto-pagination';
        nav.setAttribute('data-admin-auto-pagination-nav', '1');
        nav.setAttribute('aria-label', 'Table pagination');

        var host = resolveTableHost(table);
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

        function renderPage(targetPage, shouldScroll) {
            currentPage = Math.max(1, Math.min(totalPages, targetPage));
            var from = (currentPage - 1) * PAGE_SIZE;
            var to = from + PAGE_SIZE;

            rows.forEach(function (row, index) {
                row.hidden = !(index >= from && index < to);
            });

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
            summary.textContent = 'Showing ' + (from + 1) + '–' + Math.min(to, totalRows) + ' of ' + totalRows;
            nav.appendChild(summary);

            if (shouldScroll) {
                window.requestAnimationFrame(scrollHostIntoView);
            }
        }

        renderPage(1, false);
    }

    function initGlobalSectionPagination() {
        var root = document.getElementById('rh-admin-page') || document;
        if (!root) return;

        var tables = Array.prototype.slice.call(root.querySelectorAll('table'));
        tables.forEach(function (table) {
            if (table.closest('[data-disable-auto-pagination], .no-auto-pagination')) return;
            // POS log table has its own user-filter pagination logic.
            if (table.classList.contains('log-table') && table.querySelector('.pos-user-cell')) return;
            mountPagination(table);
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
