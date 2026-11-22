/**
 * Row right-click menu for every admin list (owner 2026-11-23: "Extend the right click gestures wherever we can
 * globally to bring up several other options easier"; GRID_UNIFY_PLAN Phase 1b).
 *
 * Right-click (or the ContextMenu key / Shift+F10 on something inside the row) on a table row shows THAT row's own
 * actions, read from what the page already drew: the row's buttons, links and its "More" menu items. Choosing an item
 * clicks the original element, so the page's own confirm, form, modal and permission behaviour is exactly what a click
 * on the button does. No server action lives here. Plus Open, Open in new tab and Copy reference where the row has them.
 *
 * It is the FALLBACK of RhContextMenu (admin-context-menu.js): a page that registered its own menu for a row (the
 * housekeeping / maintenance / blocked-dates lists, the room grids) is asked first and wins. Disabled and hidden
 * controls are skipped. A row with nothing to offer returns null, so the browser's own menu shows.
 *
 * The browser's own menu stays on inputs, selected text, Shift+right-click, a link / button / label under the pointer,
 * anywhere under [data-no-ctx], the room grids (.tl-grid, .bdg-table), and touch long-press (no long-press is added
 * here: it would fight scrolling). Phone cards are table rows too, so they get the keyboard / mouse menu only.
 * Labels reach the page only through textContent (admin-context-menu.js).
 */
(function () {
    'use strict';
    var CM = window.RhContextMenu;
    if (!CM || typeof CM.registerFallback !== 'function' || window.__rhRowCtx) return;
    window.__rhRowCtx = true;

    var CONTROLS = 'a[href], button, [role="button"], input[type="submit"], input[type="button"], hr.menu-divider';
    var NEVER = '.actions-more-toggle, .rh-info-btn, [data-no-ctx], [data-bulk-clear], [data-help-only]';
    var OWN_NATIVE = 'a[href], button, [role="button"], label, summary, .rh-info-btn';
    var REF_LABEL = /^(ref(erence)?|booking ref(erence)?|number|no\.?|#|invoice( no\.?| number)?|receipt( no\.?| number)?|credit note( no\.?)?|order( no\.?| number)?|sku|code)$/i;
    var NOISE_ICONS = /^fa-(fw|lg|xs|sm|2x|3x|spin|pulse|solid|regular|brands|light)$/;

    function clean(t) { return String(t || '').replace(/\s+/g, ' ').trim(); }

    function labelOf(n) {
        var l = n.querySelector('.label');
        var t = l ? clean(l.textContent) : '';
        if (!t) {
            var c = n.cloneNode(true);
            c.querySelectorAll('.rh-next-label, .rh-fold-label, i, svg, .badge').forEach(function (x) { x.remove(); });
            t = clean(c.textContent);
        }
        if (!t) t = clean(n.getAttribute('aria-label')) || clean(n.getAttribute('title'));
        return t.length > 60 ? t.slice(0, 57) + '...' : t;
    }
    function iconOf(n) {
        var i = n.querySelector('i[class*="fa-"]');
        if (!i) return '';
        var m = String(i.className).match(/fa-[a-z0-9-]+/g) || [];
        for (var k = 0; k < m.length; k++) if (!NOISE_ICONS.test(m[k])) return m[k];
        return '';
    }

    /** Disabled, [hidden], aria-hidden or not drawn. Items inside the (closed) More menu are judged without that menu. */
    function unusable(n) {
        if (n.disabled || n.getAttribute('aria-disabled') === 'true' || n.getAttribute('aria-hidden') === 'true') return true;
        // Some pages mark a not-allowed action by class only (bookings.php Check in: "quick-action checkin disabled").
        if (n.classList && (n.classList.contains('disabled') || n.classList.contains('is-disabled'))) return true;
        var menu = n.closest('.actions-more-menu');
        if (menu) {
            for (var p = n; p && p !== menu.parentNode; p = p.parentNode) {
                if (p.hidden || (p.style && p.style.display === 'none')) return true;
            }
            return false;
        }
        if (n.closest('[hidden]')) return true;
        return n.getClientRects().length === 0;
    }

    /** A link that is an action or opens the record, not a data link (a filter pill, an account name, a payment ref). */
    var ACTIONISH = '.actions-more-menu, .actions-row, .actions-cell, .quick-action, .btn, .action-btn, .icon-btn, .btn-icon, [data-label^="Action"], [data-label^="action"]';
    var RECORD_HREF = /[?&](id|[a-z_]*_id)=\d|(^|\/)(view|detail|details|edit|open)[-_a-z]*\.php/i;

    function linkOk(a) {
        var h = a.getAttribute('href') || '';
        if (a.tagName !== 'A') return false;
        if (h === '' || (h.charAt(0) === '#' && !a.hasAttribute('onclick'))) return false;
        if (/^(mailto|tel|sms|javascript):/i.test(h.trim()) || a.hasAttribute('download')) return false;
        return true;
    }

    function refOf(row) {
        var cell = null;
        row.querySelectorAll(':scope > td[data-label]').forEach(function (td) {
            if (!cell && REF_LABEL.test(clean(td.getAttribute('data-label')))) cell = td;
        });
        var t = cell ? clean(cell.textContent) : '';
        if (!t) {
            var cb = row.querySelector('[data-ref]');
            t = cb ? clean(cb.getAttribute('data-ref')) : '';
        }
        return t && t.length <= 60 ? t : '';
    }

    function copy(text) {
        // Alert.show() renders its message as HTML: the copied cell text is escaped first (QA 2026-11-23).
        var safe = String(text).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
        var done = function () { if (window.Alert && typeof window.Alert.show === 'function') window.Alert.show('Copied: ' + safe, 'success'); };
        if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text, done); });
        } else {
            legacyCopy(text, done);
        }
    }
    function legacyCopy(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0;';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        if (ok) done();
    }

    function rowItems(row) {
        var items = [];
        var seen = {};
        var lastMenu = null;
        var firstLink = null;
        var menuLink = null;
        var hrefs = {};
        row.querySelectorAll(CONTROLS).forEach(function (n) {
            if (n.matches('hr')) { items.push({ sep: true }); return; }
            if (n.matches(NEVER) || n.closest(NEVER) || n.closest('.rh-ctx')) return;
            if (n.closest('form[data-no-ctx], .modal, [role="dialog"]')) return;
            if (unusable(n)) return;
            if (!n.closest(ACTIONISH) && n.type !== 'submit') { // data cell content (a name, a filter pill, a value button), not an action
                if (n.tagName === 'A' && !firstLink && linkOk(n) && RECORD_HREF.test(n.getAttribute('href') || '')) firstLink = n; // but it may be the record's own page
                return;
            }
            var label = labelOf(n);
            if (!label) return;
            var inMenu = !!n.closest('.actions-more-menu');
            if (linkOk(n)) {
                if (!inMenu && !firstLink) firstLink = n;
                if (inMenu && !menuLink && /^(full |open |view )?(details?|view|open|edit)\b/i.test(label)) menuLink = n;
            }
            var key = label.toLowerCase() + '|' + (n.getAttribute('href') || '');
            if (seen[key]) return;
            seen[key] = true;
            if (lastMenu !== null && lastMenu !== inMenu) items.push({ sep: true }); // buttons, then the More menu
            lastMenu = inMenu;
            if (linkOk(n)) hrefs[n.href] = true;
            items.push({
                label: label,
                icon: iconOf(n),
                danger: n.classList.contains('text-danger') || /(^|[\s-])(danger|cancel|delete|void|remove)([\s-]|$)/i.test(n.className) || /^(delete|void|remove|cancel)\b/i.test(label),
                run: function () { if (document.contains(n)) n.click(); }
            });
        });
        return { items: items, link: firstLink || menuLink, hrefs: hrefs };
    }

    function tidy(items) { // no leading, trailing or doubled dividers
        var out = [];
        items.forEach(function (it) {
            if (it.sep && (!out.length || out[out.length - 1].sep)) return;
            out.push(it);
        });
        while (out.length && out[out.length - 1].sep) out.pop();
        return out;
    }

    function build(target, e) {
        if (!target || !target.closest) return null;
        var b = document.body;
        if (b && (b.classList.contains('pos-screen') || b.classList.contains('station-screen'))) return null; // touch tills
        var keyboard = e && e.type === 'keydown';
        if (!keyboard) {
            if (e && e.sourceCapabilities && e.sourceCapabilities.firesTouchEvents) return null; // a touch long-press
            if (target.closest(OWN_NATIVE)) return null;
        }
        var row = target.closest('tbody > tr, [data-ctx-row]');
        if (!row || row.closest('.tl-grid, .bdg-table, [data-no-ctx], .rh-ctx')) return null;
        var r = rowItems(row);
        var actions = r.items.filter(function (it) { return !it.sep; });
        if (!actions.length && !r.link) return null;

        var items = r.items.slice();
        var extras = [];
        if (r.link && !r.hrefs[r.link.href]) {
            extras.push({ label: 'Open', icon: 'fa-arrow-up-right-from-square', run: function () { r.link.click(); } });
        }
        if (r.link) {
            extras.push({ label: 'Open in new tab', icon: 'fa-up-right-from-square', run: function () { window.open(r.link.href, '_blank', 'noopener'); } });
        }
        var ref = refOf(row);
        if (ref) extras.push({ label: 'Copy reference', icon: 'fa-copy', run: function () { copy(ref); } });
        if (extras.length) {
            if (items.length) items.push({ sep: true });
            items = items.concat(extras);
        }
        return { title: ref, items: tidy(items) };
    }

    CM.registerFallback(build);
})();
