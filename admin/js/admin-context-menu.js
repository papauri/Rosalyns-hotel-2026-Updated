/**
 * RhContextMenu: the one admin right-click menu (owner 2026-11-23: "Extend the right click gestures wherever we can").
 * A reusable component, not tied to one page. Looks like the shared row "More" menu (.actions-more-menu) and is placed
 * by rhPlaceActionMenu (admin-components.js). Today used by the room grids (admin/js/room-timeline-select.js), the
 * Blocked Dates calendar (admin/js/blocked-dates-grid.js) and the Housekeeping / Maintenance / Blocked Dates lists;
 * other pages register their own builders later (GRID_UNIFY_PLAN Phase 1b).
 *
 * API
 *   RhContextMenu.open(x, y, items, opts)   open at the pointer (client px). opts: {title, anchorEl, opener}; with
 *                                           anchorEl (and x / y null) it opens under that element (keyboard).
 *   RhContextMenu.register(selector, build) right-click, the ContextMenu key / Shift+F10 and a long press (touch) on an
 *                                           element matching selector call build(el, event) -> items | {title, items}
 *                                           | null (null = the browser's own menu). Later registrations are asked first.
 *   RhContextMenu.registerFallback(build)  asked LAST, for a right-click / ContextMenu key where no registration above
 *                                           answered (admin/js/admin-row-context.js: any list row). Same return values.
 *   RhContextMenu.close(), .isOpen(), .wantsNative(event), .muteClicks(ms), .LONG_PRESS_MS
 *   item: {label, icon ('fa-...'), run(), href, danger, disabled, hint} or {sep: true} (a divider line).
 *   Disabled items stay listed, hint says why.
 *
 * The browser's own menu is kept on Shift+right-click, in text fields / selects / editable text, on selected text and
 * anywhere under [data-no-ctx].
 * Keyboard in the menu: arrows, Home / End, Enter / Space, Esc (focus goes back), Tab closes.
 * Labels reach the page only through textContent.
 */
(function () {
    'use strict';
    if (window.RhContextMenu) return;

    var LONG_PRESS_MS = 550;
    var cur = null;       // {el, anchor, opener}
    var regs = [];        // [{selector, build}]
    var fallback = null;  // build(el, event) used when no registration answered
    var muteUntil = 0;    // a long press that opened the menu must not also click what is under the finger
    var press = null;
    var liftMute = 0;     // the menu opened under a held finger: the click its lift may send never runs an item

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }
    function icon(name) {
        var i = el('i', 'fas ' + String(name || '').replace(/^fas\s+/, ''));
        i.setAttribute('aria-hidden', 'true');
        return i;
    }
    function host() { return document.getElementById('rh-admin-page') || document.body; }
    function zoom() {
        return (document.documentElement.classList.contains('page-zoomed') && window.__rhPageZoom > 0) ? window.__rhPageZoom : 1;
    }

    /** True when the browser's own menu should show (Shift, text fields, selected text). */
    function wantsNative(e) {
        if (!e) return false;
        if (e.type === 'contextmenu' && e.shiftKey) return true;
        var t = e.target;
        if (t && t.closest && t.closest('[data-no-ctx]')) return true;
        if (t && t.closest && t.closest('textarea, select, [contenteditable=""], [contenteditable="true"], input:not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"])')) return true;
        var s = window.getSelection ? window.getSelection() : null;
        if (s && !s.isCollapsed && String(s).trim() !== '' && t && s.containsNode && s.containsNode(t, true)) return true;
        return false;
    }

    function close(restore) {
        if (!cur) return;
        var m = cur;
        cur = null;
        if (m.el.parentNode) m.el.parentNode.removeChild(m.el);
        if (m.anchor && m.anchor.parentNode) m.anchor.parentNode.removeChild(m.anchor);
        if (restore !== false && m.opener && document.contains(m.opener)) {
            try { m.opener.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
        }
    }

    function open(x, y, items, opts) {
        opts = opts || {};
        close(false);
        var list = (items || []).filter(Boolean);
        if (!list.length) return false;
        var h = host();
        var m = el('div', 'actions-more-menu rh-ctx');
        m.setAttribute('role', 'menu');
        m.setAttribute('aria-label', opts.title || 'Actions');
        if (opts.title) {
            var head = el('div', 'rh-ctx__head', opts.title);
            head.setAttribute('role', 'presentation');
            m.appendChild(head);
        }
        list.forEach(function (it) {
            if (it.sep) {
                var hr = el('hr', 'menu-divider');
                hr.setAttribute('role', 'separator');
                m.appendChild(hr);
                return;
            }
            var b;
            if (it.href && !it.disabled) {
                b = el('a');
                b.href = it.href;
            } else {
                b = el('button');
                b.type = 'button';
            }
            b.setAttribute('role', 'menuitem');
            b.tabIndex = -1;
            if (it.danger) b.className = 'text-danger';
            if (it.icon) b.appendChild(icon(it.icon));
            b.appendChild(el('span', 'rh-ctx__label', it.label));
            if (it.disabled) {
                b.disabled = true;
                b.setAttribute('aria-disabled', 'true');
                if (it.hint) b.appendChild(el('span', 'rh-ctx__hint', it.hint));
            }
            if (it.hint) b.title = it.hint;
            b.addEventListener('click', function (e) {
                if (it.disabled) { e.preventDefault(); return; }
                if (it.href) { close(false); return; } // the link navigates
                e.preventDefault();
                close(false);
                if (typeof it.run === 'function') it.run();
            });
            m.appendChild(b);
        });
        h.appendChild(m);
        var anchor = null;
        var at = opts.anchorEl && document.contains(opts.anchorEl) ? opts.anchorEl : null;
        if (at && (x === null || x === undefined)) {
            if (window.rhPlaceActionMenu) window.rhPlaceActionMenu(at, m);
            else { var ar = at.getBoundingClientRect(); placeAt(m, ar.left, ar.bottom); }
        } else {
            x = Math.round(x || 0);
            y = Math.round(y || 0);
            anchor = el('span', 'rh-ctx__anchor');
            anchor.setAttribute('aria-hidden', 'true');
            anchor.style.cssText = 'position:fixed;left:' + x + 'px;top:' + y + 'px;width:1px;height:1px;pointer-events:none;';
            h.appendChild(anchor);
            if (window.rhPlaceActionMenu) {
                window.rhPlaceActionMenu(anchor, m);
                // The shared placement right-aligns a menu to its button; a right-click menu opens at the pointer.
                if (!m.classList.contains('actions-more-menu--card')) {
                    var r = m.getBoundingClientRect();
                    var vw = window.visualViewport ? window.visualViewport.width : window.innerWidth;
                    var want = Math.min(Math.max(8, x), vw - 8 - r.width);
                    m.style.left = (parseFloat(m.style.left) + (want - r.left) / zoom()) + 'px';
                }
            } else {
                placeAt(m, x, y);
            }
        }
        cur = { el: m, anchor: anchor, opener: opts.opener || at || document.activeElement };
        var first = m.querySelector('[role="menuitem"]:not([disabled])');
        if (first) first.focus({ preventScroll: true });
        return true;
    }
    function placeAt(m, x, y) {
        m.style.cssText = 'display:block;position:fixed;z-index:12050;left:' + Math.max(8, x) + 'px;top:' + Math.max(8, y) + 'px;';
    }
    function spec(r) {
        if (!r) return null;
        if (Array.isArray(r)) return r.length ? { title: '', items: r } : null;
        return r.items && r.items.length ? r : null;
    }

    function register(selector, build) {
        if (typeof selector === 'string' && typeof build === 'function') regs.push({ selector: selector, build: build });
    }
    function registerFallback(build) { if (typeof build === 'function') fallback = build; }
    /** The registered target under node (last registered first): {reg, node}. */
    function find(node) {
        if (!node || !node.closest) return null;
        for (var i = regs.length - 1; i >= 0; i--) {
            var hit = null;
            try { hit = node.closest(regs[i].selector); } catch (e) { hit = null; }
            if (hit) return { reg: regs[i], node: hit };
        }
        return null;
    }
    /** Ask the matching builders, nearest registration first, until one answers. */
    function build(target, e) {
        for (var i = regs.length - 1; i >= 0; i--) {
            var hit = null;
            try { hit = target.closest(regs[i].selector); } catch (err) { hit = null; }
            if (!hit) continue;
            var s = spec(regs[i].build(hit, e));
            if (s) return { spec: s, node: hit };
        }
        if (fallback && target.closest) {
            var fs = null;
            try { fs = spec(fallback(target, e)); } catch (err) { fs = null; }
            if (fs) return { spec: fs, node: target };
        }
        return null;
    }

    function muteClicks(ms) { muteUntil = Date.now() + (ms || 800); }

    /* ---- right-click */
    document.addEventListener('contextmenu', function (e) {
        if (Date.now() < muteUntil) { e.preventDefault(); return; }
        if (e.defaultPrevented || wantsNative(e) || !(fallback || find(e.target))) return;
        var b = build(e.target, e);
        if (!b) return;
        e.preventDefault();
        open(e.clientX, e.clientY, b.spec.items, { title: b.spec.title, opener: b.node });
    });
    /* ---- ContextMenu key / Shift+F10 on the focused element */
    document.addEventListener('keydown', function (e) {
        if (!(e.key === 'ContextMenu' || (e.key === 'F10' && e.shiftKey)) || e.defaultPrevented || cur) return;
        var t = document.activeElement;
        if (!t || t === document.body || wantsNative({ type: 'keydown', target: t }) || !(fallback || find(t))) return;
        var b = build(t, e);
        if (!b) return;
        e.preventDefault();
        open(null, null, b.spec.items, { title: b.spec.title, anchorEl: t, opener: t });
    });
    /* ---- long press (touch) */
    function endPress(e) {
        if (!press) return;
        window.clearTimeout(press.timer);
        if (press.opened && e && e.type === 'pointerup') liftMute = Date.now() + 400;
        press = null;
    }
    document.addEventListener('pointerdown', function (e) {
        if (cur && !cur.el.contains(e.target)) close(false);
        endPress();
        if (e.pointerType !== 'touch' || wantsNative(e) || !find(e.target)) return;
        var t = e.target, x = e.clientX, y = e.clientY;
        var mine = press = { x: x, y: y, timer: window.setTimeout(function () {
            var ev = { type: 'longpress', target: t, clientX: x, clientY: y, preventDefault: function () {} };
            var b = build(t, ev);
            if (!b) { if (press === mine) press = null; return; }
            muteClicks(800);
            mine.opened = true; // kept until the finger lifts (endPress), so that lift's click is swallowed
            open(x, y, b.spec.items, { title: b.spec.title, opener: b.node });
        }, LONG_PRESS_MS) };
    }, true);
    document.addEventListener('pointermove', function (e) {
        if (press && (Math.abs(e.clientX - press.x) > 10 || Math.abs(e.clientY - press.y) > 10)) endPress();
    }, true);
    document.addEventListener('pointerup', endPress, true);
    document.addEventListener('pointercancel', endPress, true);
    document.addEventListener('click', function (e) {
        if (Date.now() < liftMute) { liftMute = 0; e.preventDefault(); e.stopPropagation(); return; }
        if (Date.now() < muteUntil && !(cur && cur.el.contains(e.target))) { e.preventDefault(); e.stopPropagation(); }
    }, true);

    /* ---- inside the menu */
    document.addEventListener('keydown', function (e) {
        if (!cur) return;
        var list = Array.prototype.slice.call(cur.el.querySelectorAll('[role="menuitem"]:not([disabled])'));
        var i = list.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
        if (e.key === 'Tab') { close(false); return; }
        if ((e.key === ' ' || e.key === 'Enter') && i !== -1) { e.preventDefault(); e.stopPropagation(); list[i].click(); return; }
        var next = null;
        if (e.key === 'ArrowDown') next = list[(i + 1) % list.length];
        else if (e.key === 'ArrowUp') next = list[(i - 1 + list.length) % list.length];
        else if (e.key === 'Home') next = list[0];
        else if (e.key === 'End') next = list[list.length - 1];
        if (next) { e.preventDefault(); e.stopPropagation(); next.focus(); }
    }, true);
    window.addEventListener('scroll', function (e) {
        if (cur && !(e.target && e.target.nodeType === 1 && cur.el.contains(e.target))) close(false);
    }, true);
    window.addEventListener('resize', function () { close(false); });

    window.RhContextMenu = {
        open: open,
        close: close,
        isOpen: function () { return !!cur; },
        register: register,
        registerFallback: registerFallback,
        wantsNative: wantsNative,
        muteClicks: muteClicks,
        LONG_PRESS_MS: LONG_PRESS_MS
    };
})();
