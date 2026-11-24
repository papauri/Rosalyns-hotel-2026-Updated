/* Hotel Settings folding (owner 2026-11-25): every group (Website & booking, ...), every settings card and the
   Hotel details sub-groups fold from a button in their heading. Everything starts open; what a person folds is
   remembered in this browser (localStorage). Folded content opens by itself for a Jump-to link, a #hash (forms post
   back to booking-settings.php#card), and while the search box or group filter is in use (not remembered).
   Without JavaScript nothing folds. Loaded after the page's filter script so its search runs first. */
(function () {
    'use strict';
    if (window.rhBsFoldBound) { return; }
    window.rhBsFoldBound = true;

    var KEY = 'rh.bsFold.v1';
    var saved = {};
    try {
        var raw = JSON.parse(window.localStorage.getItem(KEY) || '{}');
        if (raw && typeof raw === 'object') { saved = raw; }
    } catch (e) { saved = {}; }

    function store() {
        try { window.localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) { /* private mode: not remembered */ }
    }

    var folds = [];

    function uniqueId(base) {
        var id = base, n = 2;
        while (document.getElementById(id)) { id = base + '-' + n++; }
        return id;
    }

    /** root: the foldable block; head: its heading element; body: the part that hides (created when null). */
    function make(root, head, after, body) {
        if (!root || !head || !root.id) { return; }
        if (!body) {
            body = document.createElement('div');
            body.className = 'bs-fold-body';
            var start = after.nextSibling;
            var moving = [];
            for (var n = start; n; n = n.nextSibling) { moving.push(n); }
            moving.forEach(function (node) { body.appendChild(node); });
            root.appendChild(body);
        }
        if (!body.id) { body.id = uniqueId(root.id + '-body'); }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'bs-fold';
        btn.setAttribute('aria-controls', body.id);
        while (head.firstChild) { btn.appendChild(head.firstChild); }
        var icon = document.createElement('i');
        icon.className = 'fas fa-chevron-down bs-fold__icon';
        icon.setAttribute('aria-hidden', 'true');
        btn.appendChild(icon);
        head.appendChild(btn);

        var f = { root: root, body: body, btn: btn, key: root.id };
        btn.addEventListener('click', function () {
            var open = btn.getAttribute('aria-expanded') !== 'true';
            set(f, open);
            if (open) { delete saved[f.key]; } else { saved[f.key] = 1; }
            store();
        });
        folds.push(f);
        set(f, saved[f.key] !== 1);
    }

    function set(f, open) {
        f.btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        f.body.hidden = !open;
        f.root.classList.toggle('is-folded', !open);
    }

    /** Open every fold that holds el (or is el), outermost first. Not remembered. */
    function openFor(el) {
        if (!el) { return; }
        folds.forEach(function (f) {
            if ((f.root === el || f.root.contains(el)) && f.body.hidden) { set(f, true); }
        });
    }

    function restore() {
        folds.forEach(function (f) { set(f, saved[f.key] !== 1); });
    }

    function targetOf(hash) {
        if (!hash || hash.length < 2) { return null; }
        try { return document.getElementById(decodeURIComponent(hash.slice(1))); } catch (e) { return null; }
    }

    // Groups: heading + one-line intro stay visible; the cards fold.
    Array.prototype.forEach.call(document.querySelectorAll('.bs-group[data-bs-section]'), function (sec) {
        var h = sec.querySelector(':scope > .bs-group__title');
        if (!h) { return; }
        if (!sec.id) { sec.id = uniqueId('bs-sec-' + sec.getAttribute('data-bs-section')); }
        var intro = sec.querySelector(':scope > .bs-group__intro');
        make(sec, h, intro || h, null);
    });
    // Cards: the panel heading stays, the rest folds.
    Array.prototype.forEach.call(document.querySelectorAll('.bs-group .settings-card[id]'), function (card) {
        var head = card.querySelector(':scope > .rh-panel__head');
        var title = head ? head.querySelector('.rh-panel__title') : null;
        if (head && title) { make(card, title, head, null); }
    });
    // Hotel details sub-groups (server markup: section.bs-sub > h3.bs-sub__title + div.bs-sub__body).
    Array.prototype.forEach.call(document.querySelectorAll('.bs-sub[id]'), function (sub) {
        var h = sub.querySelector(':scope > .bs-sub__title');
        var b = sub.querySelector(':scope > .bs-sub__body');
        if (h && b) { make(sub, h, h, b); }
    });

    // #hash on load (a save posts back to booking-settings.php#card) and later hash changes / Jump-to clicks.
    function reveal(hash, scroll) {
        var t = targetOf(hash);
        if (!t) { return; }
        openFor(t);
        if (scroll) { t.scrollIntoView(); }
    }
    if (window.location.hash) { reveal(window.location.hash, true); }
    window.addEventListener('hashchange', function () { reveal(window.location.hash, true); });
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href^="#"]') : null;
        if (a) { reveal(a.getAttribute('href'), false); } // the browser scrolls after this handler
    });

    // Search / group filter: show what matches, opened; back to the remembered folds when cleared.
    var box = document.getElementById('bs-search');
    var groupSel = document.getElementById('bs-group-filter');
    function onFilter() {
        var active = (box && box.value.replace(/\s+/g, '') !== '') || (groupSel && groupSel.value !== '');
        if (!active) { restore(); return; }
        Array.prototype.forEach.call(document.querySelectorAll('.bs-group .settings-card[id]:not(.bs-out)'), function (card) {
            openFor(card);
        });
    }
    if (box) { box.addEventListener('input', onFilter); box.addEventListener('keydown', function (e) { if (e.key === 'Escape' || e.key === 'Esc') { setTimeout(onFilter, 0); } }); }
    if (groupSel) { groupSel.addEventListener('change', onFilter); }
    var clearBtn = document.getElementById('bs-clear');
    if (clearBtn) { clearBtn.addEventListener('click', function () { setTimeout(onFilter, 0); }); }
    if (box && box.value !== '') { onFilter(); }

    // A save: the card (and its group) the form sits in is remembered open, so the page comes back with the
    // section that holds the save message open, even when it was only opened by a search.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.closest) { return; }
        var changed = false;
        folds.forEach(function (f) {
            if (f.root.contains(form) && saved[f.key] === 1) { delete saved[f.key]; changed = true; }
        });
        if (changed) { store(); }
    }, true);
}());
