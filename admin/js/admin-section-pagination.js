(function () {
    'use strict';

    if (window.__adminSectionPaginationInitialized) return;
    window.__adminSectionPaginationInitialized = true;

    var PAGE_SIZE = 10;

    function isPaginationNav(el) {
        if (!el || el.nodeType !== 1) return false;
        return el.matches('[data-admin-pagination], .bookings-pagination, .pagination, .log-table-pagination, .receipts-pagination, .pagination-bar, .inv-pagination, [data-admin-auto-pagination-nav]');
    }

    function findExistingPaginationNear(table) {
        if (!table) return false;

        var host = table.closest('.table-responsive, .table-wrapper, .table-container, [style*="overflow-x:auto"]') || table;
        var next = host.nextElementSibling;
        var checks = 0;

        while (next && checks < 5) {
            if (isPaginationNav(next)) return true;
            if (next.matches('.admin-pagination-loader-wrap, .log-table-pagination-loader-wrap')) {
                next = next.nextElementSibling;
                checks++;
                continue;
            }
            break;
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

        nav.classList.add('is-loading');

        var loaderWrap = document.createElement('div');
        loaderWrap.className = 'admin-pagination-loader-wrap';
        loaderWrap.innerHTML = [
            '<div class="admin-pagination-loader" role="status" aria-live="polite" aria-label="' + (message || 'Loading results') + '">',
            '<span class="admin-pagination-loader__spinner" aria-hidden="true"></span>',
            '<span class="admin-pagination-loader__text">' + (message || 'Loading next page...') + '</span>',
            '</div>'
        ].join('');

        nav.insertAdjacentElement('afterend', loaderWrap);

        window.setTimeout(function () {
            try {
                renderFn();
            } finally {
                nav.classList.remove('is-loading');
                if (loaderWrap.parentNode) loaderWrap.parentNode.removeChild(loaderWrap);
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

        var host = table.closest('.table-responsive, .table-wrapper, .table-container, [style*="overflow-x:auto"]') || table;
        if (host.nextSibling) {
            host.parentNode.insertBefore(nav, host.nextSibling);
        } else {
            host.parentNode.appendChild(nav);
        }

        function renderPage(targetPage) {
            currentPage = Math.max(1, Math.min(totalPages, targetPage));
            var from = (currentPage - 1) * PAGE_SIZE;
            var to = from + PAGE_SIZE;

            rows.forEach(function (row, index) {
                row.hidden = !(index >= from && index < to);
            });

            nav.innerHTML = '';

            nav.appendChild(createButton('‹ Prev', function () {
                withInlineLoader(nav, 'Loading previous page...', function () {
                    renderPage(currentPage - 1);
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
                        renderPage(target);
                    });
                }, false));
            });

            nav.appendChild(createButton('Next ›', function () {
                withInlineLoader(nav, 'Loading next page...', function () {
                    renderPage(currentPage + 1);
                });
            }, currentPage >= totalPages));

            var summary = document.createElement('span');
            summary.className = 'pg-summary';
            summary.textContent = 'Showing ' + (from + 1) + '–' + Math.min(to, totalRows) + ' of ' + totalRows;
            nav.appendChild(summary);
        }

        renderPage(1);
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
