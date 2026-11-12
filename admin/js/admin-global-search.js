/**
 * Universal admin search — navbar search icon, Ctrl+K / Cmd+K, or "/".
 *
 * One box for everything:
 *   - Pages: every link in the user's own sidebar (already filtered by permission and
 *     module), matched instantly in the browser.
 *   - Records and settings: api/global-search.php (bookings, guests, payments, receipts,
 *     enquiries, quotations, credit notes, rooms, menu, stock, suppliers, reviews, staff,
 *     Hotel Settings sections, email templates), gated server-side the same way, plus
 *     "Help & guides" answers from the staff guides knowledge base (open in a new tab).
 * Enter or click goes straight there. Results that land on a list page carry ?rh_hl=,
 * which this file uses to scroll to and flash the matching row (see highlightFromUrl).
 *
 * Loaded once from admin-header.php, outside the SPA content area, so it survives page swaps.
 */
(function () {
    'use strict';
    if (window.__rhGlobalSearch) return;
    window.__rhGlobalSearch = true;

    var API = 'api/global-search.php';
    var DEBOUNCE_MS = 160;
    var MAX_PAGES = 8;
    var CACHE_MAX = 40;      // record searches kept for this page view (backspacing never refetches)
    var CACHE_MS = 60000;    // ...for a minute, so new bookings still show up

    var overlay, input, list, status, activeIndex = -1, flat = [], timer = null, inflight = null, lastQuery = '';
    var lastGroups = [], cache = {}, cacheKeys = [], firstStale = Infinity, pendingEnter = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function words(q) {
        return String(q || '').toLowerCase().split(/\s+/).filter(Boolean);
    }

    // Highlight every typed word (the search matches word by word, in any order).
    function mark(text, q) {
        var t = String(text || '');
        var ws = words(q);
        if (!ws.length) return esc(t);
        var lower = t.toLowerCase(), hits = [];
        ws.forEach(function (w) {
            var from = 0, i;
            while ((i = lower.indexOf(w, from)) >= 0) { hits.push([i, i + w.length]); from = i + w.length; }
        });
        if (!hits.length) return esc(t);
        hits.sort(function (a, b) { return a[0] - b[0]; });
        var out = '', pos = 0;
        hits.forEach(function (h) {
            if (h[0] < pos) { if (h[1] > pos) { out += '<mark>' + esc(t.slice(pos, h[1])) + '</mark>'; pos = h[1]; } return; }
            out += esc(t.slice(pos, h[0])) + '<mark>' + esc(t.slice(h[0], h[1])) + '</mark>';
            pos = h[1];
        });
        return out + esc(t.slice(pos));
    }

    function cacheGet(q) {
        var hit = cache[q.toLowerCase()];
        return hit && (Date.now() - hit.at) < CACHE_MS ? hit.groups : null;
    }

    function cachePut(q, groups) {
        var k = q.toLowerCase();
        if (!cache[k]) cacheKeys.push(k);
        cache[k] = { at: Date.now(), groups: groups };
        while (cacheKeys.length > CACHE_MAX) delete cache[cacheKeys.shift()];
    }

    // ---- Pages from the rendered sidebar (permission-filtered by the server already) ----
    function sidebarPages() {
        var seen = {}, out = [];
        // Skip the Favorites group: its links duplicate the real groups and lose the section name.
        document.querySelectorAll('.admin-nav .nav-group:not(.nav-favorites-group) .nav-item > a[href]').forEach(function (a) {
            var href = a.getAttribute('href') || '';
            if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href) || /logout\.php/.test(href)) return;
            var li = a.closest('.nav-item');
            var label = ((li && li.getAttribute('data-nav-label')) || a.textContent || '').replace(/\s+/g, ' ').trim();
            if (!label || seen[href]) return;
            seen[href] = true;
            var group = a.closest('.nav-group');
            var title = group ? group.querySelector('.nav-group-title') : null;
            out.push({ title: label, sub: title ? title.textContent.trim() : '', url: href, icon: (a.querySelector('i') || {}).className || 'fas fa-file' });
        });
        return out;
    }

    // Initials of a label ("Room Dashboard" -> "rd").
    function initials(s) {
        return s.split(/[\s&/-]+/).filter(Boolean).map(function (w) { return w.charAt(0); }).join('');
    }

    // Every letter of q in order inside s ("bkngs" -> "bookings"): the last resort for a typo.
    function inOrder(q, s) {
        var j = 0;
        for (var i = 0; i < s.length && j < q.length; i++) { if (s.charAt(i) === q.charAt(j)) j++; }
        return j === q.length;
    }

    function matchPages(q) {
        var ql = q.toLowerCase().trim(), ws = words(ql), compact = ql.replace(/\s+/g, '');
        return sidebarPages().map(function (p) {
            var hay = (p.title + ' ' + p.sub).toLowerCase(), t = p.title.toLowerCase(), score = 0;
            var starts = ws.every(function (w) { return (' ' + t).indexOf(' ' + w) >= 0; });
            if (t === ql) score = 100;
            else if (t.indexOf(ql) === 0) score = 85;
            else if (starts) score = 75;                                   // "dash room" -> Room Dashboard
            else if (t.indexOf(ql) >= 0) score = 60;
            else if (ws.every(function (w) { return hay.indexOf(w) >= 0; })) score = 45; // words in title or section
            else if (compact.length >= 2 && initials(t).indexOf(compact) === 0) score = 40; // "rd" -> Room Dashboard
            else if (compact.length >= 3 && inOrder(compact, t.replace(/\s+/g, ''))) score = 20;
            return { p: p, score: score };
        }).filter(function (x) { return x.score > 0; })
            .sort(function (a, b) { return b.score - a.score; })
            .slice(0, MAX_PAGES)
            .map(function (x) { return x.p; });
    }

    // ---- Rendering ----
    function render(q, pageItems, groups, loading) {
        // Keep the highlighted result if it is still listed (results arriving must not move the selection).
        var keepUrl = activeIndex > 0 && flat[activeIndex] ? flat[activeIndex].url : null;
        flat = [];
        // Records still showing from the previous words while the new ones load: dimmed, and Enter waits for the
        // fresh results instead of opening one of them.
        firstStale = loading ? pageItems.length : Infinity;
        var html = '';
        function group(label, icon, items, defaultIcon) {
            if (!items.length) return;
            html += '<li class="rh-gs__group" role="presentation"><i class="fas ' + esc(icon) + '" aria-hidden="true"></i> ' + esc(label) + '</li>';
            items.forEach(function (it) {
                var idx = flat.length;
                flat.push(it);
                var ic = it.icon ? it.icon : 'fas ' + defaultIcon;
                html += '<li class="rh-gs__item' + (idx >= firstStale ? ' is-stale' : '') + '" role="option" id="rh-gs-opt-' + idx + '" data-idx="' + idx + '" aria-selected="false">'
                    + '<i class="' + esc(ic) + ' rh-gs__icon" aria-hidden="true"></i>'
                    + '<span class="rh-gs__text"><span class="rh-gs__title">' + mark(it.title, q) + '</span>'
                    + (it.sub ? '<span class="rh-gs__sub">' + mark(it.sub, q) + '</span>' : '') + '</span>'
                    + '<i class="fas fa-arrow-right rh-gs__go" aria-hidden="true"></i></li>';
            });
        }
        group('Pages', 'fa-compass', pageItems, 'fa-file');
        (groups || []).forEach(function (g) { group(g.label, g.icon, g.items, g.icon); });
        var keepScroll = list.scrollTop;
        list.innerHTML = html;
        activeIndex = flat.length ? 0 : -1;
        if (keepUrl) {
            for (var k = 0; k < flat.length; k++) { if (flat[k].url === keepUrl) { activeIndex = k; break; } }
        }
        list.scrollTop = activeIndex > 0 ? keepScroll : 0;
        paintActive(false);
        if (!q) {
            status.textContent = 'Search bookings, guests, phone numbers, receipts, rooms, menu items, staff, settings, pages — or ask “how do I…” to search the guides.';
        } else if (loading) {
            status.textContent = flat.length ? 'Searching records…' : 'Searching…';
        } else {
            status.textContent = flat.length ? flat.length + ' result' + (flat.length === 1 ? '' : 's') + ' · ↑↓ to move, Enter to open' : 'Nothing found for “' + q + '”.';
        }
    }

    // reveal: true only for arrow keys. It scrolls the result list itself, never the page or the panel, so the
    // search box never moves while typing or hovering (scrollIntoView also scrolled the page behind it).
    function paintActive(reveal) {
        list.querySelectorAll('.rh-gs__item').forEach(function (el) {
            var on = Number(el.dataset.idx) === activeIndex;
            el.classList.toggle('is-active', on);
            el.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) {
                input.setAttribute('aria-activedescendant', el.id);
                if (reveal) {
                    var top = el.offsetTop, bottom = top + el.offsetHeight;
                    if (top < list.scrollTop) list.scrollTop = top - 28; // leave the group heading visible
                    else if (bottom > list.scrollTop + list.clientHeight) list.scrollTop = bottom - list.clientHeight + 6;
                }
            }
        });
        if (activeIndex < 0) input.removeAttribute('aria-activedescendant');
    }

    function go(idx) {
        var it = flat[idx];
        if (!it || !it.url) return;
        close();
        var target = new URL(it.url, window.location.href);
        if (it.newTab) { // guides open beside the admin, like the sidebar's guide links
            window.open(target.href, '_blank', 'noopener');
            return;
        }
        if (target.pathname === window.location.pathname && target.search === window.location.search && target.hash) {
            window.location.hash = target.hash; // same page, new section: let the deep-link script flash it
            return;
        }
        window.location.href = target.href;
    }

    function search(q) {
        if (q === lastQuery && q !== '') return; // same words (a space or Shift typed): nothing to redo
        lastQuery = q;
        var pages = q ? matchPages(q) : [];
        if (inflight && inflight.abort) inflight.abort();
        if (q.length < 2) {
            lastGroups = [];
            render(q, pages, [], false);
            return;
        }
        var cached = cacheGet(q);
        if (cached) {
            lastGroups = cached;
            render(q, pages, cached, false);
            return;
        }
        // While the records load, the previous records stay on screen (no flicker, nothing jumps).
        render(q, pages, lastGroups, true);
        inflight = window.AbortController ? new AbortController() : null;
        fetch(API + '?q=' + encodeURIComponent(q), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: inflight ? inflight.signal : undefined
        }).then(function (r) { return r.json(); })
            .then(function (data) {
                var groups = (data && data.groups) || [];
                cachePut(q, groups);
                if (q !== lastQuery) return;
                lastGroups = groups;
                render(q, pages, groups, false);
                if (pendingEnter) { pendingEnter = false; if (activeIndex >= 0) go(activeIndex); }
            })
            .catch(function (e) {
                if (e && e.name === 'AbortError') return;
                if (q !== lastQuery) return;
                pendingEnter = false;
                lastGroups = [];
                render(q, pages, [], false);
                status.textContent = 'Could not search records just now — pages are still listed.';
            });
    }

    // ---- Open / close ----
    function build() {
        overlay = document.createElement('div');
        overlay.className = 'rh-gs';
        overlay.hidden = true;
        overlay.innerHTML =
            '<div class="rh-gs__panel" role="dialog" aria-modal="true" aria-label="Search everything">'
            + '<div class="rh-gs__bar"><i class="fas fa-search" aria-hidden="true"></i>'
            + '<input type="search" class="rh-gs__input" placeholder="Search anything — booking, guest, phone, receipt, room, setting…" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="true" aria-controls="rh-gs-list" aria-autocomplete="list">'
            + '<button type="button" class="rh-gs__close" aria-label="Close search"><kbd>Esc</kbd></button></div>'
            + '<ul class="rh-gs__list" id="rh-gs-list" role="listbox"></ul>'
            + '<div class="rh-gs__status" aria-live="polite"></div></div>';
        document.body.appendChild(overlay);
        input = overlay.querySelector('.rh-gs__input');
        list = overlay.querySelector('.rh-gs__list');
        status = overlay.querySelector('.rh-gs__status');

        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });
        overlay.querySelector('.rh-gs__close').addEventListener('click', close);
        input.addEventListener('input', function () {
            clearTimeout(timer);
            pendingEnter = false;
            var q = input.value.trim().replace(/\s+/g, ' ');
            // Pages match in the browser at once; records wait for a short pause (cached answers show at once too).
            timer = setTimeout(function () { search(q); }, q.length < 2 || cacheGet(q) ? 0 : DEBOUNCE_MS);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (flat.length) { activeIndex = (activeIndex + 1) % flat.length; paintActive(true); } }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (flat.length) { activeIndex = (activeIndex - 1 + flat.length) % flat.length; paintActive(true); } }
            else if (e.key === 'Enter') {
                e.preventDefault();
                var q = input.value.trim().replace(/\s+/g, ' ');
                // Typed faster than the search: run it now and open the top result when it arrives.
                if (q !== lastQuery) { clearTimeout(timer); search(q); }
                if (activeIndex >= firstStale || (activeIndex < 0 && inflight && q.length >= 2 && !cacheGet(q))) { pendingEnter = true; return; }
                if (activeIndex >= 0) go(activeIndex);
            }
            else if (e.key === 'Escape') { e.preventDefault(); close(); }
        });
        list.addEventListener('mousemove', function (e) {
            var li = e.target.closest('.rh-gs__item');
            if (li && Number(li.dataset.idx) !== activeIndex) { activeIndex = Number(li.dataset.idx); paintActive(false); }
        });
        list.addEventListener('click', function (e) {
            var li = e.target.closest('.rh-gs__item');
            if (li) go(Number(li.dataset.idx));
        });
    }

    var lastFocus = null;
    function open() {
        if (!overlay) build();
        lastFocus = document.activeElement;
        overlay.hidden = false;
        document.documentElement.classList.add('rh-gs-open');
        input.value = '';
        lastQuery = '';
        lastGroups = [];
        pendingEnter = false;
        activeIndex = -1;
        render('', [], [], false);
        setTimeout(function () { input.focus(); }, 0);
    }

    function close() {
        if (!overlay || overlay.hidden) return;
        overlay.hidden = true;
        document.documentElement.classList.remove('rh-gs-open');
        if (inflight && inflight.abort) inflight.abort();
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* element gone after SPA swap */ } }
    }

    document.addEventListener('keydown', function (e) {
        var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((e.target && e.target.tagName) || '') || (e.target && e.target.isContentEditable);
        if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            if (overlay && !overlay.hidden) close(); else open();
        } else if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) {
            e.preventDefault();
            open();
        }
    });
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('[data-rh-global-search]')) {
            e.preventDefault();
            open();
        }
    });

    // ---- Landing: ?rh_hl=<text> scrolls to and flashes the matching row/card ----
    function highlightFromUrl() {
        var params;
        try { params = new URLSearchParams(window.location.search); } catch (e) { return; }
        var needle = (params.get('rh_hl') || '').trim();
        if (!needle) return;
        var lower = needle.toLowerCase();
        var root = document.getElementById('rh-admin-page') || document.body;
        var started = Date.now();
        (function attempt() {
            var candidates = root.querySelectorAll('tr, .card, [class*="card"]:not([class*="cards"]), li, .rh-panel');
            var best = null;
            for (var i = 0; i < candidates.length; i++) {
                var el = candidates[i];
                if (!el.offsetParent) continue; // hidden (other tab, collapsed)
                var txt = (el.textContent || '').toLowerCase();
                if (txt.indexOf(lower) < 0) continue;
                if (!best || txt.length < (best.textContent || '').length) best = el;
            }
            if (!best) {
                if (Date.now() - started < 2500) { setTimeout(attempt, 150); }
                return;
            }
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            best.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
            best.classList.remove('rh-deeplink-flash');
            void best.offsetWidth;
            best.classList.add('rh-deeplink-flash', 'rh-gs-landed');
            setTimeout(function () { best.classList.remove('rh-gs-landed'); }, 4000);
        })();
        // Drop the parameter so a refresh or a shared link doesn't keep re-flashing.
        params.delete('rh_hl');
        var qs = params.toString();
        try { history.replaceState(history.state, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash); } catch (e) { /* ignore */ }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', highlightFromUrl);
    else highlightFromUrl();
    document.addEventListener('rh:content-updated', highlightFromUrl);

    // ---- Help button: always opens help for the page now on screen (the SPA swaps pages
    // without reloading the header, so the server-rendered link would go stale). ----
    function syncPageHelp() {
        var a = document.querySelector('[data-rh-page-help]');
        if (!a) return;
        var page = (window.location.pathname.split('/').pop() || 'dashboard.php');
        if (!/\.php$/.test(page)) page = 'dashboard.php';
        a.setAttribute('href', '../docs/guides/system-map.php?page=' + encodeURIComponent(page));
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', syncPageHelp);
    else syncPageHelp();
    document.addEventListener('rh:content-updated', syncPageHelp);
    window.addEventListener('popstate', syncPageHelp);

    window.rhOpenGlobalSearch = open;
})();
