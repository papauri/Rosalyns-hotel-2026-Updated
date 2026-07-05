/**
 * Admin deep-linking + flash highlight.
 *
 * Lets any admin link jump straight to a specific row/card/section instead of
 * just landing on the general page. Two ways to target an element:
 *
 *   1. URL hash          →  page.php#booking-123
 *   2. ?focus= query     →  page.php?status=pending&focus=booking-123
 *
 * The target is resolved by, in order:
 *   - element id === value
 *   - [data-focus="value"]
 *   - [data-focus-id="value"]
 *
 * On match it scrolls the element into view (centred) and flashes it once.
 * Because rows can render slightly late (pagination, async widgets) it retries
 * for a short window. Same-page anchor clicks flash their target too. Honours
 * prefers-reduced-motion (scroll without the animation).
 *
 * No dependencies. Loaded globally from admin-footer.php.
 */
(function () {
    'use strict';

    var FLASH_CLASS = 'rh-deeplink-flash';
    var RETRY_MS = 1600;      // keep looking this long for late content
    var RETRY_STEP = 120;

    function reducedMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function resolveTarget(value) {
        if (!value) return null;
        // Strip a leading '#'.
        value = String(value).replace(/^#/, '').trim();
        if (value === '') return null;
        // Try id first (guard against invalid selector chars).
        var byId = null;
        try { byId = document.getElementById(value); } catch (e) { byId = null; }
        if (byId) return byId;
        var esc = (window.CSS && CSS.escape) ? CSS.escape(value) : value.replace(/["\\]/g, '\\$&');
        return document.querySelector('[data-focus="' + esc + '"]') ||
            document.querySelector('[data-focus-id="' + esc + '"]');
    }

    function flash(el) {
        if (!el) return;
        // Reveal inside collapsed <details> where possible.
        var details = el.closest && el.closest('details');
        if (details && !details.open) { details.open = true; }
        // Reveal if hidden by section pagination (rows use the `hidden` attr) so
        // the followed item is actually visible, even on a later page.
        var node = el;
        while (node && node !== document.body) {
            if (node.hidden) { node.hidden = false; }
            node = node.parentElement;
        }

        el.scrollIntoView({
            behavior: reducedMotion() ? 'auto' : 'smooth',
            block: 'center',
            inline: 'nearest'
        });

        if (reducedMotion()) {
            // Still give a brief static highlight so the user sees the target.
            el.classList.add(FLASH_CLASS);
            setTimeout(function () { el.classList.remove(FLASH_CLASS); }, 1600);
            return;
        }
        // Restart the animation cleanly if it was already applied.
        el.classList.remove(FLASH_CLASS);
        // eslint-disable-next-line no-unused-expressions
        void el.offsetWidth;
        el.classList.add(FLASH_CLASS);
        el.addEventListener('animationend', function handler() {
            el.classList.remove(FLASH_CLASS);
            el.removeEventListener('animationend', handler);
        });
    }

    function getFocusValue() {
        // ?focus= takes priority; fall back to a real hash target.
        try {
            var params = new URLSearchParams(window.location.search);
            var f = params.get('focus');
            if (f) return f;
        } catch (e) { /* older browsers */ }
        if (window.location.hash && window.location.hash.length > 1) {
            return window.location.hash.slice(1);
        }
        return null;
    }

    function focusWhenReady(value) {
        if (!value) return;
        var deadline = Date.now() + RETRY_MS;
        (function attempt() {
            var el = resolveTarget(value);
            if (el) { flash(el); return; }
            if (Date.now() < deadline) { setTimeout(attempt, RETRY_STEP); }
        })();
    }

    // ── Group flash ─────────────────────────────────────────────────────────
    // A dashboard insight modal's "Open full page" link lands on the filtered
    // list; ?flash= then briefly pulses the rows the card was about so the user
    // sees exactly what they drilled into. Two forms:
    //   ?flash=status:checked-in   → every [data-status="checked-in"] row
    //   ?flash=results             → every visible row in [data-flash-scope]
    var GROUP_FLASH_CLASS = 'rh-flash-group';

    function isVisibleEl(el) {
        if (!el || el.hidden) return false;
        return el.offsetParent !== null || el.getClientRects().length > 0;
    }

    function resolveGroup(spec) {
        if (!spec) return [];
        if (spec === 'results') {
            var scope = document.querySelector('[data-flash-scope]');
            if (!scope) return [];
            return Array.prototype.slice.call(scope.querySelectorAll('tbody > tr')).filter(isVisibleEl);
        }
        var m = /^([a-z][a-z0-9_-]*):(.+)$/i.exec(spec);
        if (!m) return [];
        var key = m[1].toLowerCase();
        var val = m[2];
        var esc = (window.CSS && CSS.escape) ? CSS.escape(val) : val.replace(/["\\]/g, '\\$&');
        var sel = '[data-' + key + '="' + esc + '"]';
        var nodes;
        try { nodes = document.querySelectorAll(sel); } catch (e) { return []; }
        return Array.prototype.slice.call(nodes).filter(isVisibleEl);
    }

    function pulse(el) {
        if (!el) return;
        el.classList.remove(GROUP_FLASH_CLASS);
        void el.offsetWidth;
        el.classList.add(GROUP_FLASH_CLASS);
        el.addEventListener('animationend', function handler() {
            el.classList.remove(GROUP_FLASH_CLASS);
            el.removeEventListener('animationend', handler);
        });
    }

    function flashGroup(spec) {
        if (!spec) return;
        // "results" flashes whatever the active tab currently shows, so it must
        // run AFTER the page's tab-filter has hidden non-matching rows — a short
        // initial defer lets that settle before we read visibility.
        if (spec === 'results') {
            setTimeout(function () { flashGroupNow(spec); }, 300);
            return;
        }
        flashGroupNow(spec);
    }

    function flashGroupNow(spec) {
        // Allow a touch longer than single-focus: tab-switching JS may still be
        // hiding/showing rows when we first look.
        var deadline = Date.now() + RETRY_MS + 600;
        (function attempt() {
            var els = resolveGroup(spec);
            if (els.length) {
                els[0].scrollIntoView({
                    behavior: reducedMotion() ? 'auto' : 'smooth',
                    block: 'center',
                    inline: 'nearest'
                });
                if (reducedMotion()) {
                    els.forEach(function (el) {
                        el.classList.add(GROUP_FLASH_CLASS);
                        setTimeout(function () { el.classList.remove(GROUP_FLASH_CLASS); }, 1600);
                    });
                    return;
                }
                // Gentle stagger so a run of rows ripples rather than blinking at once.
                els.forEach(function (el, i) {
                    setTimeout(function () { pulse(el); }, Math.min(i * 55, 550));
                });
                return;
            }
            if (Date.now() < deadline) { setTimeout(attempt, RETRY_STEP); }
        })();
    }

    function getFlashSpec() {
        try {
            return new URLSearchParams(window.location.search).get('flash');
        } catch (e) { return null; }
    }

    // ── On load: honour ?focus= / hash and ?flash= group. ──────────────────
    function init() {
        focusWhenReady(getFocusValue());
        flashGroup(getFlashSpec());
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ── Same-page anchor clicks: flash the target too. ─────────────────────
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[href*="#"]');
        if (!a) return;
        var href = a.getAttribute('href') || '';
        var hashIdx = href.indexOf('#');
        if (hashIdx < 0) return;
        var hash = href.slice(hashIdx + 1);
        if (!hash) return;
        // Only intercept when the link stays on the current page.
        var path = href.slice(0, hashIdx);
        var samePage = path === '' || path === '#' ||
            path === window.location.pathname ||
            path === (window.location.pathname.split('/').pop());
        if (!samePage) return;
        var el = resolveTarget(hash);
        if (el) { e.preventDefault(); flash(el); }
    }, false);

    // Hash changes within the page (e.g. section pagination anchors).
    window.addEventListener('hashchange', function () {
        if (window.location.hash && window.location.hash.length > 1) {
            focusWhenReady(window.location.hash.slice(1));
        }
    });

    // Expose for programmatic use (e.g. after AJAX table loads).
    window.rhDeepLinkFlash = function (value) { focusWhenReady(value); };
    window.rhFlashGroup = function (spec) { flashGroup(spec); };
})();
