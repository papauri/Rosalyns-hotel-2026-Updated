/**
 * Shared bulk-selection helper for admin list pages. Loaded once from includes/admin-footer.php and
 * fully event-delegated, so it keeps working after in-place (SPA) navigation with no per-page setup.
 *
 * Markup contract (see admin/includes/bulk-actions.php for the PHP renderers):
 *   <form method="POST" class="admin-bulk-bar" data-bulk-bar data-bulk-scope="#someTable" hidden>
 *     <input type="hidden" name="action" value="bulk_xxx"> <input type="hidden" name="csrf_token" ...>
 *     <strong data-bulk-count>0</strong> selected
 *     <select name="assigned_to" data-bulk-field> ... </select>
 *     <button type="submit" name="bulk_op" value="assign" data-bulk-requires="assigned_to"
 *             data-admin-confirm="Assign {n} tasks?">Assign</button>
 *     <button type="button" data-bulk-clear>Clear</button>
 *   </form>
 *   In the scope element: <input type="checkbox" data-bulk-all> in the header and
 *   <input type="checkbox" data-bulk-row value="ID"> in each row/card.
 *
 * Only rows that are currently visible (not filtered out, not on another pagination page) count as
 * selected, so "select all" and the submitted ids always match what the user can see. On submit the
 * selected ids are written to hidden bulk_ids[] inputs; the existing data-admin-confirm modal
 * (admin-main.js) is used for confirmation, with "{n}" replaced by the selection size.
 */
(function () {
    'use strict';

    if (window.__adminBulkInitialized) return;
    window.__adminBulkInitialized = true;

    function scopeOf(bar) {
        var sel = bar.getAttribute('data-bulk-scope');
        var el = null;
        try { el = sel ? document.querySelector(sel) : null; } catch (e) { el = null; }
        return el || bar.parentElement;
    }

    function isVisible(el) {
        return !el.disabled && el.getClientRects().length > 0;
    }

    function rowBoxes(scope) {
        return Array.prototype.slice.call(scope.querySelectorAll('input[data-bulk-row]'));
    }

    function selectedBoxes(scope) {
        return rowBoxes(scope).filter(function (cb) { return cb.checked && isVisible(cb); });
    }

    function refreshBar(bar) {
        var scope = scopeOf(bar);
        if (!scope) return;
        var boxes = rowBoxes(scope).filter(isVisible);
        var picked = boxes.filter(function (cb) { return cb.checked; });
        var n = picked.length;

        bar.hidden = n === 0;
        Array.prototype.forEach.call(bar.querySelectorAll('[data-bulk-count]'), function (el) { el.textContent = String(n); });

        Array.prototype.forEach.call(scope.querySelectorAll('input[data-bulk-all]'), function (all) {
            all.checked = boxes.length > 0 && n === boxes.length;
            all.indeterminate = n > 0 && n < boxes.length;
            all.disabled = boxes.length === 0;
        });

        Array.prototype.forEach.call(bar.querySelectorAll('button[type="submit"], button[data-bulk-op]'), function (btn) {
            var need = btn.getAttribute('data-bulk-requires');
            var missing = false;
            if (need) {
                need.split(',').forEach(function (name) {
                    var field = bar.querySelector('[name="' + name.trim() + '"]');
                    if (field && String(field.value) === '') missing = true;
                });
            }
            btn.disabled = n === 0 || missing;
            var tpl = btn.getAttribute('data-bulk-confirm');
            if (tpl) btn.setAttribute('data-admin-confirm', tpl.replace(/\{n\}/g, String(n)));
        });
    }

    function refreshAll() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-bulk-bar]'), refreshBar);
    }

    var pending = 0;
    function scheduleRefresh() {
        if (pending) return;
        pending = window.requestAnimationFrame(function () { pending = 0; refreshAll(); });
    }

    function barsFor(node) {
        return Array.prototype.filter.call(document.querySelectorAll('[data-bulk-bar]'), function (bar) {
            var scope = scopeOf(bar);
            return scope && scope.contains(node);
        });
    }

    document.addEventListener('change', function (event) {
        var t = event.target;
        if (!(t instanceof HTMLInputElement) && !(t instanceof HTMLSelectElement)) {
            return;
        }
        if (t.matches('input[data-bulk-all]')) {
            barsFor(t).forEach(function (bar) {
                rowBoxes(scopeOf(bar)).forEach(function (cb) {
                    if (isVisible(cb)) cb.checked = t.checked;
                });
            });
        }
        scheduleRefresh();
    });

    // Filters, searches and pagination change which rows are visible without firing "change".
    ['input', 'click', 'keyup'].forEach(function (type) {
        document.addEventListener(type, scheduleRefresh, true);
    });

    document.addEventListener('click', function (event) {
        var clear = event.target.closest ? event.target.closest('[data-bulk-clear]') : null;
        if (!clear) return;
        var bar = clear.closest('[data-bulk-bar]');
        if (!bar) return;
        rowBoxes(scopeOf(bar)).forEach(function (cb) { cb.checked = false; });
        refreshBar(bar);
    });

    // Capture phase so the ids are in the form before admin-main.js shows its confirm modal and
    // before the real submit; an empty selection never reaches the server.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-bulk-bar')) return;

        Array.prototype.forEach.call(form.querySelectorAll('input[data-bulk-generated]'), function (el) { el.remove(); });
        var picked = selectedBoxes(scopeOf(form));
        if (!picked.length) {
            event.preventDefault();
            event.stopPropagation();
            refreshBar(form);
            return;
        }
        picked.forEach(function (cb) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'bulk_ids[]';
            input.value = cb.value;
            input.setAttribute('data-bulk-generated', '1');
            form.appendChild(input);
        });
    }, true);

    // Pagination swaps pages after a short loader delay, so look again once it has settled.
    document.addEventListener('click', function () { window.setTimeout(scheduleRefresh, 500); }, true);

    document.addEventListener('rh:content-updated', scheduleRefresh);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleRefresh);
    } else {
        scheduleRefresh();
    }
    window.AdminBulk = { refresh: refreshAll };
})();
