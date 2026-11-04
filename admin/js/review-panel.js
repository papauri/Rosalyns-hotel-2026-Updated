/*
 * The Decisions panel (admin/includes/review-panel.php): Refresh and Noted / Not now. Loaded only when a panel is
 * printed. One document-level listener, bound once, so it keeps working after in-app navigation. Posts to the
 * panel's own endpoint with the page's security token; nothing else is sent.
 */
(function () {
    'use strict';
    if (window.rhDecBound) { return; }
    window.rhDecBound = true;

    function post(sec, fields) {
        var fd = new FormData();
        fd.append('csrf_token', sec.getAttribute('data-csrf') || '');
        fd.append('area', sec.getAttribute('data-area') || '');
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch(sec.getAttribute('data-endpoint') || 'api/decisions.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { success: false, error: 'Something went wrong. Try again.' }; }); });
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('[data-dec]') : null;
        if (!btn) { return; }
        var sec = btn.closest('.rh-dec');
        if (!sec) { return; }
        var kind = btn.getAttribute('data-dec');
        if (kind === 'refresh') {
            btn.disabled = true;
            var label = btn.textContent;
            btn.textContent = 'Updating';
            post(sec, { action: 'refresh' }).then(function (j) {
                if (j && j.success && j.html === '') { sec.remove(); return; } // nothing to show now: no stale cards
                if (j && j.success && typeof j.html === 'string' && j.html !== '') {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = j.html;
                    var fresh = tmp.querySelector('.rh-dec');
                    if (fresh) { sec.replaceWith(fresh); return; }
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
