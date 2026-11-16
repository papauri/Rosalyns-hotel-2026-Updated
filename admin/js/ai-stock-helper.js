/**
 * Stock name check on Stock > Fix crate prices (spec .claude/AI_STOCK_HELPER_SPEC.md; engine includes/ai/ai-stock-kinds.php).
 * Runs only when the page printed [data-sk-mount] (the helper is on and this person may use it); otherwise it does nothing.
 *
 * - Paints each stored or new reading under the item's name: one plain line, a size chip ("Use 24") when there is one,
 *   and "This is the price of one" for administrators and managers.
 * - A chip fills the crate box ONLY when the person presses it, and never overwrites a box that already holds a number.
 *   A box filled this way is marked data-src="suggested" (the page posts it; Preview lists those items in their own group).
 *   Typing in the box again removes the mark.
 * - "Use all agreed suggestions" fills only the empty boxes of agreed readings.
 * - "Check names" asks the server one chunk at a time (ids only; the server reads the names itself), at most
 *   data-max-chunks requests per run.
 * Re-run safe: the page calls window.rhSkInit() after each (in-app) load.
 */
(function () {
    'use strict';
    function csrf() {
        if (window._rhCsrf) return String(window._rhCsrf);
        var m = document.querySelector('meta[name="csrf-token"]');
        if (m) return String(m.getAttribute('content') || '');
        var f = document.querySelector('input[name="csrf_token"]');
        return f ? String(f.value || '') : '';
    }

    window.rhSkInit = function () {
        var mount = document.querySelector('[data-sk-mount]');
        if (!mount || mount.getAttribute('data-sk-ready') === '1' || !window.fetch || !window.FormData) return;
        mount.setAttribute('data-sk-ready', '1');
        var surface = mount.getAttribute('data-surface') || '';
        var api = mount.getAttribute('data-api') || '';
        var maxChunks = parseInt(mount.getAttribute('data-max-chunks') || '5', 10) || 5;
        var canTeach = mount.getAttribute('data-can-teach') === '1';
        var checkBtn = mount.querySelector('[data-sk-check]');
        var allBtn = mount.querySelector('[data-sk-all]');
        var allN = mount.querySelector('[data-sk-all-n]');
        var status = mount.querySelector('[data-sk-status]');
        var results = {};
        var cached = mount.querySelector('[data-sk-cached]');
        var busy = false;
        try {
            var data = JSON.parse(cached ? cached.textContent : '{}') || {};
            results = data.results || {};
            if (data.summary) say(data.summary);
        } catch (e) { results = {}; }

        function say(t) { if (status) status.textContent = t || ''; }
        function rowOf(id) { return document.querySelector('tr[data-scf-row][data-id="' + String(parseInt(id, 10)) + '"]'); }
        function boxOf(row) { return row ? row.querySelector('.scf-n') : null; }

        function use(row, n, explicit) {
            var box = boxOf(row);
            if (!box) return false;
            var cur = box.value.trim();
            if (cur !== '') {
                if (explicit && cur !== String(n)) say('That box already holds a number. Clear it first if you want the suggestion.');
                return false;
            }
            box.value = String(n);
            box.setAttribute('data-src', 'suggested');
            box.dispatchEvent(new Event('input', { bubbles: true }));
            return true;
        }

        function paint(id) {
            var row = rowOf(id);
            if (!row) return;
            var cell = row.querySelector('td.scf-item') || row.cells[0];
            if (!cell) return;
            var old = cell.querySelector('.sk-line');
            if (old) old.parentNode.removeChild(old);
            var r = results[id];
            row.removeAttribute('data-sk-state');
            if (!r || !r.line) return;
            row.setAttribute('data-sk-state', r.state || '');
            var p = document.createElement('div');
            p.className = 'sk-line';
            var src = document.createElement('span');
            src.className = 'sk-line__src';
            src.textContent = 'Suggested by the Assistant: ';
            p.appendChild(src);
            p.appendChild(document.createTextNode(r.line));
            p.appendChild(document.createElement('br'));
            (r.chips || []).forEach(function (n) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'sk-chip';
                b.textContent = 'Use ' + n;
                b.setAttribute('aria-label', 'Use ' + n + ' as the units in one crate');
                b.addEventListener('click', function () {
                    if (use(row, n, true)) say('Filled ' + n + '. Check it, then press Preview.');
                    updateAll();
                });
                p.appendChild(b);
            });
            if (canTeach && ['crate', 'unclear', 'split'].indexOf(r.state) !== -1) {
                var t = document.createElement('button');
                t.type = 'button';
                t.className = 'sk-link';
                t.textContent = 'This is the price of one';
                t.addEventListener('click', function () { teachOne(id, t); });
                p.appendChild(t);
            }
            cell.appendChild(p);
        }

        function agreedEmpty() {
            var out = [];
            Object.keys(results).forEach(function (id) {
                var r = results[id];
                if (!r || !r.all || !r.chips || r.chips.length !== 1) return;
                var row = rowOf(id);
                var box = boxOf(row);
                if (box && box.value.trim() === '') out.push([row, r.chips[0]]);
            });
            return out;
        }
        function updateAll() {
            if (!allBtn) return;
            var n = agreedEmpty().length;
            if (allN) allN.textContent = String(n);
            allBtn.hidden = n === 0;
        }
        if (allBtn) {
            allBtn.addEventListener('click', function () {
                var k = 0;
                agreedEmpty().forEach(function (x) { if (use(x[0], x[1], false)) k++; });
                say('Filled ' + k + ' box' + (k === 1 ? '' : 'es') + ' from agreed suggestions. Check each one, then press Preview.');
                updateAll();
            });
        }
        // a person typing in a box makes it their own number again
        document.addEventListener('input', function (e) {
            if (e.isTrusted && e.target && e.target.classList && e.target.classList.contains('scf-n')) {
                e.target.removeAttribute('data-src');
                updateAll();
            }
        });

        function post(fields, timeoutMs) {
            var body = new FormData();
            Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
            body.append('csrf_token', csrf());
            body.append('surface', surface);
            var ctrl = window.AbortController ? new AbortController() : null;
            var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, timeoutMs) : null;
            return fetch(api, {
                method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: ctrl ? ctrl.signal : undefined
            }).then(function (r) {
                if (timer) clearTimeout(timer);
                return r.json().catch(function () { return null; });
            }, function () {
                if (timer) clearTimeout(timer);
                return null;
            });
        }

        function listedIds() {
            var ids = [];
            Array.prototype.forEach.call(document.querySelectorAll('tr[data-scf-row]'), function (row) {
                if (row.getAttribute('data-filter-hidden') !== '1') ids.push(row.getAttribute('data-id'));
            });
            return ids;
        }

        function run(round, ids) {
            post({ action: 'check', ids: ids.join(','), round: String(round) }, 100000).then(function (j) {
                if (!j) {
                    finish('The name check did not finish. The list works as before.');
                    return;
                }
                if (j.results) {
                    Object.keys(j.results).forEach(function (id) { results[id] = j.results[id]; paint(id); });
                    updateAll();
                }
                if (!j.success) {
                    finish((j.error || 'The name check did not finish.') + (j.summary ? ' ' + j.summary : ''));
                    return;
                }
                if (j.more > 0 && j.sent > 0 && round < maxChunks) {
                    say((j.summary || '') + ' Checking more names...');
                    run(round + 1, ids);
                    return;
                }
                finish((j.summary || '') + (j.more > 0 ? ' Press Check names again for the rest.' : ''));
            });
        }
        function finish(text) {
            busy = false;
            if (checkBtn) checkBtn.disabled = false;
            say(text);
        }
        if (checkBtn) {
            checkBtn.addEventListener('click', function () {
                if (busy) return;
                var ids = listedIds();
                if (!ids.length) { say('No items are listed. Change the filters first.'); return; }
                busy = true;
                checkBtn.disabled = true;
                say('Checking ' + ids.length + ' name' + (ids.length === 1 ? '' : 's') + '...');
                run(1, ids);
            });
        }

        function teachOne(id, btn) {
            btn.disabled = true;
            post({ action: 'price_of_one', id: String(id) }, 20000).then(function (j) {
                btn.disabled = false;
                if (!j || !j.success) { say((j && j.error) || 'That could not be saved. Please try again.'); return; }
                results[id] = j.result || null;
                paint(id);
                updateAll();
                say('Kept: this is the price of one. A later reading that disagrees will ask you to check it.');
            });
        }

        Object.keys(results).forEach(paint);
        updateAll();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.rhSkInit);
    } else {
        window.rhSkInit();
    }
})();
