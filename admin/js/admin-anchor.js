/**
 * Admin results anchoring.
 *
 * One shared behaviour for every admin page whose cards, tabs or chips filter a list:
 * after the click the page lands on the filtered results instead of leaving the user to
 * scroll down to them.
 *
 * Opt-in marker: put data-rh-results on the element that starts the list (the heading
 * above it, or the table wrapper). Pages without a marker fall back to their largest
 * visible table, so a page works without any markup change.
 *
 *  - Reloading links (status, filter, page and similar query keys, on this page or another
 *    admin page) get the #rh-results fragment added when clicked, so the SPA swap or full
 *    reload ends on the list. GET filter forms get the same fragment.
 *  - JS filters (status cards, tabs, chips) scroll the results into view after the click.
 *    Add data-rh-scroll to any other control that filters a list on the page.
 *  - Nothing scrolls when the list is already in view. The header is allowed for, smooth
 *    scrolling is off for prefers-reduced-motion, and focus moves to the start of the
 *    results (no focus ring, the shared CSS removes it) only when the page scrolled.
 *
 * Delegated listeners, bound once: they survive SPA page swaps. window.rhAnchors is the
 * small API that admin-spa.js and page scripts use.
 */
(function () {
    'use strict';

    if (window.rhAnchors) return;

    var RESULTS_ID = 'rh-results';
    var GAP = 12; // space kept between the header and the top of the results
    var NO_ANCHOR_PAGES = /\/(kds|bds|cds|pos|login|logout)\.php$/i;
    // Query keys that mean "this link changes what a list shows".
    var FILTER_KEY = /^(status|filter\w*|health|kitchen_status|payment_method|order_type|hour|date\w*|category|type|stage|role|rating|reply|source|state|severity|level|method|search|q|refund_status|range|period|month|year|show|sort|order|page|p|\w+_page)$/i;
    // Keys that are page sections more often than list filters: only anchor when the page marks its results.
    var SECTION_KEY = /^(tab|view|section)$/i;
    // Controls that filter a list in place (no reload).
    var JS_FILTER = '[data-rh-scroll], [data-status-filter], [data-admin-status-filter-ready], .tb-tab, .filter-btn, .filter-tabs button, button[data-filter]';
    var SKIP_ZONE = '.admin-header, .admin-nav, .admin-sidebar, #adminSidebar, .admin-nav-drawer, [data-modal], .modal-overlay, .modal, dialog, [data-no-anchor]';
    var WRAPPERS = '.table-responsive, .table-container, .table-wrapper, .table-wrap';

    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function headerOffset() {
        var h = document.querySelector('.admin-header');
        var bottom = 0;
        if (h) {
            var cs = window.getComputedStyle(h);
            if (cs.position === 'fixed' || cs.position === 'sticky') {
                bottom = Math.max(0, h.getBoundingClientRect().bottom);
            }
        }
        return bottom + GAP;
    }

    function isShown(el) {
        if (!el || el.hidden) return false;
        return !!(el.offsetParent || (el.getClientRects && el.getClientRects().length));
    }

    function inTemplateOrModal(el) {
        return !!(el.closest && el.closest('template, [data-modal], .modal-overlay, .modal, dialog, [hidden]'));
    }

    function pageRoot() {
        return document.getElementById('rh-admin-page') || document.body;
    }

    function markedResults() {
        var list = pageRoot().querySelectorAll('[data-rh-results]');
        for (var i = 0; i < list.length; i++) {
            if (isShown(list[i]) && !inTemplateOrModal(list[i])) return list[i];
        }
        return null;
    }

    function wrapperOf(table) {
        return (table.closest && table.closest(WRAPPERS)) || table;
    }

    // The visible table with the most body rows is the main list of the page.
    function largestTable() {
        var tables = pageRoot().querySelectorAll('table');
        var best = null;
        var bestRows = 0;
        for (var i = 0; i < tables.length; i++) {
            var t = tables[i];
            if (!isShown(t) || inTemplateOrModal(t)) continue;
            var rows = t.tBodies && t.tBodies.length ? t.tBodies[0].rows.length : 0;
            if (rows > bestRows) { best = t; bestRows = rows; }
        }
        return best ? wrapperOf(best) : null;
    }

    function findResults(strict) {
        return markedResults() || (strict ? null : largestTable());
    }

    // Give the results element the fragment id so #rh-results resolves natively and in the SPA.
    function prepare() {
        if (NO_ANCHOR_PAGES.test(window.location.pathname)) return null;
        var el = findResults(false);
        if (!el) return null;
        var current = document.getElementById(RESULTS_ID);
        if (current && current !== el) { current.removeAttribute('id'); }
        if (!el.id) { el.id = RESULTS_ID; }
        return el;
    }

    function inView(el, offset) {
        var top = el.getBoundingClientRect().top;
        return top >= offset - 8 && top <= window.innerHeight * 0.45;
    }

    /**
     * Bring the list to the top of the page, below the header. Does nothing when the list is
     * already in view unless opts.force is set. Returns true when it scrolled.
     */
    function scrollToResults(el, opts) {
        opts = opts || {};
        el = el || prepare();
        if (!el || !isShown(el)) return false;
        var offset = headerOffset();
        if (!opts.force && inView(el, offset)) return false;

        var y = el.getBoundingClientRect().top + window.pageYOffset - offset;
        window.scrollTo({ top: Math.max(0, y), behavior: reducedMotion() ? 'auto' : 'smooth' });

        if (opts.focus !== false) {
            if (!el.hasAttribute('tabindex')) { el.setAttribute('tabindex', '-1'); }
            try { el.focus({ preventScroll: true }); } catch (e) { /* older browsers */ }
        }
        return true;
    }

    // Links that reload the page with a filter
    function hasKey(search, re) {
        var params;
        try { params = new URLSearchParams(search); } catch (e) { return false; }
        var found = false;
        params.forEach(function (v, k) { if (re.test(k)) found = true; });
        return found;
    }

    function wantsFragment(a, url) {
        if (url.origin !== window.location.origin || url.hash) return false;
        if (!/\/admin\/[^/]*\.php$/i.test(url.pathname) || NO_ANCHOR_PAGES.test(url.pathname)) return false;
        if (a.closest(SKIP_ZONE) || a.hasAttribute('download')) return false;
        if (a.target && a.target !== '' && a.target !== '_self') return false;
        if (!url.search || /[?&](export|download|format|focus|flash)=/i.test(url.search)) return false;
        var samePage = url.pathname === window.location.pathname;
        if (samePage && url.search === window.location.search) return false;
        if (hasKey(url.search, FILTER_KEY)) return true;
        // tab= / view= only when this page marks its results, so section tabs never jump.
        return samePage && hasKey(url.search, SECTION_KEY) && !!markedResults();
    }

    function onLinkClick(e) {
        if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button > 0) return;
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
        var url;
        try { url = new URL(a.href, window.location.href); } catch (err) { return; }
        if (!wantsFragment(a, url)) return;
        a.setAttribute('href', href + '#' + RESULTS_ID);
    }

    // GET filter forms land on the list as well (the browser keeps the fragment of the action).
    function anchorForm(form) {
        if (!form || form.tagName !== 'FORM') return;
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') return;
        if (form.closest(SKIP_ZONE)) return;
        if (NO_ANCHOR_PAGES.test(window.location.pathname)) return;
        var action = form.getAttribute('action');
        var url;
        try { url = new URL(action || window.location.href, window.location.href); } catch (err) { return; }
        if (url.origin !== window.location.origin || url.hash) return;
        if (!/\/admin\/[^/]*\.php$/i.test(url.pathname)) return;
        var named = form.querySelectorAll('input[name], select[name]');
        var filters = false;
        for (var i = 0; i < named.length; i++) {
            if (FILTER_KEY.test(named[i].name)) { filters = true; break; }
        }
        if (!filters) return;
        form.setAttribute('action', url.pathname + '#' + RESULTS_ID);
    }

    function onFormSubmit(e) { anchorForm(e.target); }

    // Selects that call form.submit() from onchange never fire a submit event.
    function onFormChange(e) {
        var field = e.target;
        if (!field || !field.form || !field.getAttribute) return;
        if (field.hasAttribute('data-autosubmit') || /submit\(/.test(field.getAttribute('onchange') || '')) { anchorForm(field.form); }
    }

    // In-place filters (status cards, tabs, chips)
    function resultsForControl(control) {
        var tableId = control.getAttribute && control.getAttribute('data-admin-status-filter-table-id');
        if (tableId) {
            var table = document.querySelector('table[data-admin-status-filter-table-id="' + tableId + '"]');
            if (table) return wrapperOf(table);
        }
        return findResults(false);
    }

    function onControlClick(e) {
        if (NO_ANCHOR_PAGES.test(window.location.pathname)) return;
        var control = e.target.closest && e.target.closest(JS_FILTER);
        if (!control || control.closest('.admin-header, [data-modal], .modal-overlay, [data-no-anchor]')) return;
        // A real link reloads the page; onLinkClick adds the fragment for that.
        if (control.tagName === 'A') {
            var h = control.getAttribute('href') || '';
            if (h !== '' && h.charAt(0) !== '#' && !/^javascript:/i.test(h)) return;
        }
        // Let the page's own handler apply the filter first, then look at where the list is.
        window.setTimeout(function () {
            var target = resultsForControl(control);
            if (target) { scrollToResults(target); }
        }, 80);
    }

    // Arriving with #rh-results (full load, SPA swap, back/forward)
    function landOnHash() {
        if (window.location.hash !== '#' + RESULTS_ID) return;
        var el = prepare();
        if (!el) return;
        window.requestAnimationFrame(function () {
            if (!scrollToResults(el)) {
                // Already in view (the browser jump got there first): just park focus.
                if (!el.hasAttribute('tabindex')) { el.setAttribute('tabindex', '-1'); }
                try { el.focus({ preventScroll: true }); } catch (err) { /* ignore */ }
            }
        });
    }

    window.rhAnchors = {
        prepare: prepare,
        findResults: findResults,
        scrollToResults: scrollToResults,
        headerOffset: headerOffset
    };

    document.addEventListener('click', onLinkClick, true);
    document.addEventListener('click', onControlClick, false);
    document.addEventListener('submit', onFormSubmit, true);
    document.addEventListener('change', onFormChange, true);
    document.addEventListener('rh:content-updated', function () { prepare(); landOnHash(); });
    window.addEventListener('hashchange', landOnHash);

    function init() { prepare(); landOnHash(); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
