/*
 * The Decisions panel (admin/includes/review-panel.php): the "Decisions" tab and side drawer, Refresh, and Noted / Not now.
 * Loaded only when a panel is printed. Document-level listeners, bound once, so they keep working after in-app navigation
 * (the drawer's open state lives only on its own element, so a new page always starts closed). Posts to the panel's own
 * endpoint with the page's security token; nothing else is sent.
 */
(function () {
    'use strict';
    if (window.rhDecBound) { return; }
    window.rhDecBound = true;

    var FOCUSABLE = 'a[href], button:not([disabled]), summary, input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function post(sec, fields) {
        var fd = new FormData();
        fd.append('csrf_token', sec.getAttribute('data-csrf') || '');
        fd.append('area', sec.getAttribute('data-area') || '');
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch(sec.getAttribute('data-endpoint') || 'api/decisions.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { success: false, error: 'Something went wrong. Try again.' }; }); });
    }

    function openWrap() { return document.querySelector('.rh-dec-wrap.is-open'); }

    function openDrawer(wrap) {
        var tab = wrap.querySelector('[data-dec-open]');
        var drawer = wrap.querySelector('.rh-dec-drawer');
        if (!drawer) { return; }
        wrap.classList.add('is-open');
        if (tab) { tab.setAttribute('aria-expanded', 'true'); }
        drawer.focus({ preventScroll: true });
    }

    function closeDrawer(wrap, noFocus) {
        wrap.classList.remove('is-open');
        var tab = wrap.querySelector('[data-dec-open]');
        if (tab) {
            tab.setAttribute('aria-expanded', 'false');
            if (!noFocus) { tab.focus({ preventScroll: true }); }
        }
    }

    document.addEventListener('keydown', function (ev) {
        var wrap = openWrap();
        if (!wrap) { return; }
        if (ev.key === 'Escape' || ev.key === 'Esc') { ev.preventDefault(); closeDrawer(wrap); return; }
        if (ev.key === 'Tab') { // keep Tab inside the open drawer
            var drawer = wrap.querySelector('.rh-dec-drawer');
            var items = drawer ? Array.prototype.filter.call(drawer.querySelectorAll(FOCUSABLE), function (e) { return e.offsetParent !== null; }) : [];
            if (!items.length) { ev.preventDefault(); return; }
            var first = items[0], last = items[items.length - 1];
            if (!drawer.contains(document.activeElement) || (ev.shiftKey && (document.activeElement === first || document.activeElement === drawer))) { ev.preventDefault(); last.focus(); }
            else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
        }
    });

    document.addEventListener('click', function (ev) {
        var t = ev.target && ev.target.closest ? ev.target : null;
        if (!t) { return; }
        var opener = t.closest('[data-dec-open]');
        if (opener) {
            var w = opener.closest('.rh-dec-wrap');
            if (w) { if (w.classList.contains('is-open')) { closeDrawer(w); } else { openDrawer(w); } }
            return;
        }
        var closer = t.closest('[data-dec-close]');
        if (closer) {
            var cw = closer.closest('.rh-dec-wrap');
            if (cw) { closeDrawer(cw); }
            return;
        }
        var btn = t.closest('[data-dec]');
        if (!btn) { return; }
        var sec = btn.closest('.rh-dec');
        if (!sec) { return; }
        var kind = btn.getAttribute('data-dec');
        if (kind === 'refresh') {
            btn.disabled = true;
            var label = btn.textContent;
            btn.textContent = 'Updating';
            var wrap = sec.closest('.rh-dec-wrap');
            post(sec, { action: 'refresh' }).then(function (j) {
                if (j && j.success && j.html === '') { // nothing to show now: no stale cards, no tab
                    (wrap || sec).remove();
                    return;
                }
                if (j && j.success && typeof j.html === 'string' && j.html !== '') {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = j.html;
                    var fresh = tmp.querySelector('.rh-dec');
                    if (fresh) {
                        sec.replaceWith(fresh);
                        if (wrap) { // keep the tab's count in step with the new list
                            var oldN = wrap.querySelector('[data-dec-count]');
                            var newN = tmp.querySelector('[data-dec-count]');
                            var tab = wrap.querySelector('[data-dec-open]');
                            var newTab = tmp.querySelector('[data-dec-open]');
                            if (oldN && newN) { oldN.textContent = newN.textContent; }
                            if (tab && newTab) { tab.setAttribute('aria-label', newTab.getAttribute('aria-label') || ''); }
                            var drawer = wrap.querySelector('.rh-dec-drawer');
                            if (drawer && wrap.classList.contains('is-open')) { drawer.focus({ preventScroll: true }); }
                        }
                        return;
                    }
                }
                btn.disabled = false;
                btn.textContent = label;
            }).catch(function () { btn.disabled = false; btn.textContent = label; });
        } else if (kind === 'note') {
            var card = btn.closest('.rh-dec-card');
            var state = card ? card.querySelector('.rh-dec-card__state') : null;
            btn.disabled = true;
            post(sec, { action: 'note', card_key: btn.getAttribute('data-key') || '', what: btn.getAttribute('data-what') || '' }).then(function (j) {
                if (state) { state.textContent = j && j.success ? (j.label + ' - saved') : ((j && j.error) || 'Could not be saved.'); }
                if (!(j && j.success)) { btn.disabled = false; }
            }).catch(function () { btn.disabled = false; if (state) { state.textContent = 'Could not be saved.'; } });
        }
    });
})();
