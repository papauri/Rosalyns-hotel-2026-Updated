/*
 * The Decisions panel (admin/includes/review-panel.php): the "Decisions" tab and side drawer, Refresh, and Noted / Not now.
 * Loaded only when a panel (or its hidden first-wording hook) is printed. Document-level listeners, bound once, so they keep
 * working after in-app navigation (the drawer's open state lives only on its own element, so a new page always starts closed).
 * Posts to the panel's own endpoint with the page's security token; nothing else is sent.
 *
 * The tab and drawer are position: fixed. A page box with containment (e.g. the cockpit's .ck, a container query box)
 * would pin them to that box instead of the window, so each panel is moved up to be a direct child of #rh-admin-page
 * (still inside the in-app content, so it goes with the page on navigation). Until then the tab stays hidden (CSS).
 */
(function () {
    'use strict';
    if (window.rhDecBound) { if (window.rhDecInit) { window.rhDecInit(); } return; }
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

    // Move a panel out of any contained box: a direct child of #rh-admin-page (or <main> / <body> when there is none).
    // Any older copy of the same area's panel already there (e.g. after a part of the page was reloaded) is removed.
    function place(wrap) {
        var host = document.getElementById('rh-admin-page') || document.querySelector('main') || document.body;
        if (wrap.parentNode !== host) {
            Array.prototype.forEach.call(host.children, function (el) {
                if (el !== wrap && el.id && el.id === wrap.id) { el.remove(); }
            });
            host.appendChild(wrap);
        }
        wrap.classList.add('is-placed');
    }

    // First wording: the page printed a hidden hook because today's cards are not worded yet. Ask once; show the panel
    // only if a good review comes back, otherwise remove the hook (nothing is ever shown in its place).
    function wordPending(hook, retries) {
        hook.setAttribute('data-dec-sent', '1');
        post(hook, { action: 'refresh' }).then(function (j) {
            if (!hook.isConnected) { return; }
            var wrap = null;
            if (j && j.success && typeof j.html === 'string' && j.html !== '') {
                var tmp = document.createElement('div');
                tmp.innerHTML = j.html;
                wrap = tmp.querySelector('.rh-dec-wrap');
            }
            if (wrap) {
                hook.replaceWith(wrap);
                place(wrap);
            } else if (j && j.success && j.status === 'busy' && retries > 0) { // being worded by another request: ask again shortly
                setTimeout(function () { if (hook.isConnected) { wordPending(hook, retries - 1); } }, 5000);
            } else {
                hook.remove();
            }
        }).catch(function () { if (hook.isConnected) { hook.remove(); } });
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('.rh-dec-wrap'), place);
        Array.prototype.forEach.call(document.querySelectorAll('[data-dec-pending]:not([data-dec-sent])'), function (hook) { wordPending(hook, 2); });
    }
    window.rhDecInit = init;

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
            var items = drawer ? Array.prototype.filter.call(drawer.querySelectorAll(FOCUSABLE), function (e) { return e.getClientRects().length > 0; }) : [];
            if (!items.length) { ev.preventDefault(); return; }
            var first = items[0], last = items[items.length - 1], act = document.activeElement;
            if (!drawer.contains(act)) { ev.preventDefault(); (ev.shiftKey ? last : first).focus(); }
            else if (ev.shiftKey && (act === first || act === drawer)) { ev.preventDefault(); last.focus(); }
            else if (!ev.shiftKey && act === last) { ev.preventDefault(); first.focus(); }
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

    // In-app navigation brings the page in without reloading this file: place the new panel and ask for its wording.
    document.addEventListener('rh:content-updated', init);
    init();
})();
