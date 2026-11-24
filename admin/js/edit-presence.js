/* "Also open right now" warning for one record (admin/api/presence.php?editing=<key>&id=<n>).
 *
 * Markup (any page; the classes are the Create Booking banner's, admin-components.css):
 *   <div class="cb-presence" data-edit-presence data-key="edit-booking" data-id="42" data-endpoint="api/presence.php" role="status" aria-live="polite" hidden>
 *       <strong class="cb-presence__title">Also open right now</strong>
 *       <ul class="cb-presence__list"></ul>
 *   </div>
 * Keys: edit-booking, stock-count, data-import, stock-ingredients. data-id="0" = nothing open yet (box stays hidden).
 *
 * Polls every 30 s while the tab is visible; after 3 minutes without input it asks with idle=1 (reads the
 * list but stops saying "I am here", so an abandoned tab ages out on the server). Names are written with
 * textContent. It is a warning only: it never blocks or changes anything on the page.
 *
 * window.RHEditPresence.setId(n) switches the record (0 = stop and hide), for a modal or the import's job
 * switch. The script loads once; on every in-app page change (rh:content-updated) it looks for the box
 * again, so polling stops when the page is left and never doubles up.
 */
(function () {
    'use strict';
    if (window.RHEditPresence) { return; }

    var POLL_MS = 30000;
    var IDLE_MS = 180000;
    var S = { box: null, list: null, key: '', id: 0, timer: null, lastInput: Date.now(), seq: 0 };

    function findBox() { return document.querySelector('[data-edit-presence]'); }

    function clearList() {
        if (S.list) { S.list.textContent = ''; }
        if (S.box) { S.box.hidden = true; }
    }

    function stop() {
        if (S.timer) { clearInterval(S.timer); S.timer = null; }
        S.seq++; // a reply still on its way is ignored
        clearList();
    }

    function render(others) {
        if (!S.box || !S.list) { return; }
        S.list.textContent = '';
        if (!others || !others.length) { S.box.hidden = true; return; }
        others.forEach(function (o) {
            var li = document.createElement('li');
            li.textContent = String(o && o.text != null ? o.text : '');
            S.list.appendChild(li);
        });
        S.box.hidden = false;
    }

    function tick() {
        if (!S.box || !S.box.isConnected) { stop(); return; }
        if (S.id <= 0 || document.hidden || !window.fetch) { return; }
        var seq = ++S.seq;
        var id = S.id;
        var q = 'editing=' + encodeURIComponent(S.key) + '&id=' + encodeURIComponent(String(id));
        if (Date.now() - S.lastInput > IDLE_MS) { q += '&idle=1'; }
        var endpoint = S.box.getAttribute('data-endpoint') || 'api/presence.php';
        fetch(endpoint + '?' + q, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (seq !== S.seq || id !== S.id) { return; } // the record changed or the page was left meanwhile
                if (j && j.success && j.data) { render(j.data.others); }
            })
            .catch(function () { /* keep what is shown */ });
    }

    function start() {
        if (S.timer) { clearInterval(S.timer); S.timer = null; }
        S.timer = setInterval(tick, POLL_MS);
        tick();
    }

    function attach(box) {
        S.box = box;
        S.list = box.querySelector('.cb-presence__list');
        if (!S.list) { S.list = document.createElement('ul'); S.list.className = 'cb-presence__list'; box.appendChild(S.list); }
        S.key = box.getAttribute('data-key') || '';
        S.id = parseInt(box.getAttribute('data-id'), 10) || 0;
        clearList();
        if (S.id > 0 && S.key) { start(); }
    }

    // Look for the box now and after every in-app page change. Safe to call again: the same box is left alone.
    function init() {
        var box = findBox();
        if (box && box === S.box && box.isConnected) { return; }
        if (S.box) { stop(); S.box = null; S.list = null; }
        if (box) { attach(box); }
    }

    function setId(n) {
        var id = parseInt(n, 10) || 0;
        if (!S.box || !S.box.isConnected) { init(); }
        if (!S.box) { return; }
        if (id === S.id) { return; }
        stop();
        S.id = id > 0 ? id : 0;
        S.box.setAttribute('data-id', String(S.id));
        if (S.id > 0 && S.key) { start(); }
    }

    ['pointerdown', 'keydown', 'touchstart', 'scroll'].forEach(function (evt) {
        window.addEventListener(evt, function () { S.lastInput = Date.now(); }, { passive: true });
    });
    // Back on a hidden tab: refresh at once.
    document.addEventListener('visibilitychange', function () { if (!document.hidden && S.timer) { tick(); } });
    document.addEventListener('rh:content-updated', function () {
        var left = S.box && !S.box.isConnected;
        init();
        if (left && !S.box && window.fetch) {
            // Left the page: say which screen is open now so the old record stops showing for others.
            var page = (location.pathname.split('/').pop() || '').replace(/[^A-Za-z0-9_.-]/g, '');
            fetch('api/presence.php?ping=1&page=' + encodeURIComponent(page), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(function () {});
        }
    });

    window.RHEditPresence = { setId: setId, refresh: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
