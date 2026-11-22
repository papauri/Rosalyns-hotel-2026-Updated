/* Put stock items on the drinks menu (admin/stock-to-menu.php, owner 2026-11-22).
 * The server works everything out (admin/includes/stock-to-menu-lib.php): this file sends the rows that matter (the ticked ones and
 * the one just changed) to "preview" and paints what comes back; "Add to the menu" sends the ticked rows to "save". */
(function () {
    'use strict';

    function init() {
        var dataEl = document.getElementById('stm-data');
        var table = document.getElementById('stm-table');
        if (!dataEl || !table || table.dataset.stmBound === '1') return;
        table.dataset.stmBound = '1';
        var data;
        try { data = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
        var cur = data.currency ? data.currency + ' ' : '';
        var showCost = data.show_cost !== false;      // no Stock permission: the server sends no costs and the cells keep their "—" + ?
        var rowsEl = Array.prototype.slice.call(table.querySelectorAll('tr.stm-row'));
        // 'Show profit without VAT' (owner 2026-11-22): display only, remembered per browser; the server sends profit_ex_vat (VAT on + Stock permission)
        var vat = showCost && data.vat ? data.vat : null;
        var exBox = document.getElementById('stm-exvat');
        var VAT_KEY = 'rhProfitExVat';
        if (exBox) { try { exBox.checked = window.localStorage.getItem(VAT_KEY) === '1'; } catch (e) { /* works without it */ } }
        function exVat() { return !!(vat && exBox && exBox.checked); }
        var profitHead = document.getElementById('stm-profit-head');
        var PROFIT_TIP = profitHead && profitHead.querySelector('.help') ? profitHead.querySelector('.help').getAttribute('data-tip') : '';
        function profitWords() {
            if (!exVat()) return ['Profit', PROFIT_TIP];
            return ['Profit without VAT', vat.mode === 'inclusive'
                ? 'Price without the ' + Number(vat.rate) + '% VAT, minus cost of one, per sale. Shown once a price is typed.'
                : 'Your menu prices do not include VAT (it is added on top at the till), so this is the same as the profit: price minus cost of one, per sale.'];
        }
        function paintHead() {
            if (!profitHead) return;
            var w = profitWords();
            var lbl = profitHead.querySelector('.stm-profit-label');
            var dot = profitHead.querySelector('.help');
            if (lbl) lbl.textContent = w[0];
            if (dot) { dot.setAttribute('data-tip', w[1]); dot.setAttribute('aria-label', w[1]); }
            var full = w[0] + (data.currency ? ' (' + data.currency + ')' : '');
            rowsEl.forEach(function (tr) { var td = tr.querySelector('.stm-profit'); if (td && td.hasAttribute('data-label')) td.setAttribute('data-label', full); });
            var note = table.closest('.table-responsive');
            note = note ? note.previousElementSibling : null;      // phone cards: the shared 'What the columns mean' list
            if (note && note.classList.contains('rh-col-help')) {
                Array.prototype.forEach.call(note.querySelectorAll('dt'), function (dt) {
                    if (/^Profit/.test(dt.textContent) && dt.nextElementSibling) { dt.textContent = full; dt.nextElementSibling.textContent = w[1]; }
                });
            }
        }
        var state = {};          // ing id -> last row the server sent
        (data.rows || []).forEach(function (r) { state[r.ing_id] = r; });
        var q = document.getElementById('stm-q');
        var show = document.getElementById('stm-show');
        var saveBtn = document.getElementById('stm-save');
        var result = document.getElementById('stm-result');
        var seq = 0;
        var timer = null;
        var pending = {};

        function money(n) {
            return cur + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function num(n) {
            return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
        }
        function rowEl(id) {
            for (var i = 0; i < rowsEl.length; i++) { if (rowsEl[i].dataset.ing === String(id)) return rowsEl[i]; }
            return null;
        }
        function input(tr) {
            return {
                ing_id: parseInt(tr.dataset.ing, 10),
                name: tr.querySelector('.stm-in-name').value,
                sold: tr.querySelector('.stm-in-sold').value,
                price: tr.querySelector('.stm-in-price').value,
                category: tr.querySelector('.stm-in-cat').value,
                tick: tr.querySelector('.stm-tick-box').checked
            };
        }

        function paint(tr, r) {
            var cost = tr.querySelector('.stm-cost');
            var profit = tr.querySelector('.stm-profit');
            var status = tr.querySelector('.stm-status');
            var cat = tr.querySelector('.stm-in-cat');
            var sold = tr.querySelector('.stm-in-sold');
            if (showCost) {
                cost.innerHTML = r.cost === null ? '<span class="stm-muted">Not known</span>' : esc(num(r.cost)) + (r.cost_note ? '<span class="stm-muted stm-small">' + esc(r.cost_note) + '</span>' : '');
                var pv = exVat() ? (r.profit_ex_vat === undefined ? null : r.profit_ex_vat) : r.profit;
                profit.innerHTML = pv === null ? '<span class="stm-muted">-</span>' : '<span class="' + (pv < 0 ? 'stm-neg' : '') + '">' + esc(num(pv)) + '</span>';
            }
            var cls = { 'new': 'stm-pill--new', 'update': 'stm-pill--update', 'same': 'stm-pill--same', 'blocked': 'stm-pill--blocked' }[r.status] || '';
            var html = '<span class="stm-pill ' + cls + '">' + esc(r.status_text) + '</span>';
            if (r.status === 'update' || r.status === 'same') {
                html += '<span class="stm-muted stm-small">On the menu as "' + esc(r.menu_name) + '" in ' + esc(r.category) + '</span>';
            }
            if (r.warn) html += '<span class="stm-warn">' + esc(r.warn) + '</span>';
            status.innerHTML = html;
            if (r.category_locked) {
                cat.value = r.category;
                cat.readOnly = true;
                cat.title = 'A drink already on the menu keeps its category.';
            } else {
                cat.readOnly = false;
                cat.title = '';
            }
            sold.disabled = !!r.sold_locked;
            var note = tr.querySelector('.stm-sold-note');
            if (note) note.hidden = !!r.sold_locked;
            sold.title = r.sold_locked ? 'It keeps the stock link it has on the menu.' : '';
            tr.classList.toggle('stm-row--blocked', r.status === 'blocked');
            tr.classList.toggle('stm-row--ticked', tr.querySelector('.stm-tick-box').checked);
            tr.dataset.status = r.status;
        }

        function filter() {
            var term = (q.value || '').trim().toLowerCase();
            var mode = show.value;
            var shown = 0;
            rowsEl.forEach(function (tr) {
                var st = tr.dataset.status || '';
                var ok = true;
                if (term && (tr.dataset.search || '').indexOf(term) === -1 && tr.querySelector('.stm-in-name').value.toLowerCase().indexOf(term) === -1) ok = false;
                if (mode === 'new' && st !== 'new' && !tr.querySelector('.stm-tick-box').checked) ok = false;
                if (mode === 'menu' && st !== 'update' && st !== 'same') ok = false;
                if (mode === 'ticked' && !tr.querySelector('.stm-tick-box').checked) ok = false;
                tr.dataset.filterHidden = ok ? '' : '1';
                tr.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });
            if (typeof table.rhPagerRefresh === 'function') {
                table.rhPagerRefresh(true);
            }
            var c = document.getElementById('stm-count');
            if (c) c.textContent = shown === rowsEl.length ? rowsEl.length + ' stock item(s)' : shown + ' of ' + rowsEl.length + ' stock item(s) shown';
        }

        function counts() {
            var ticked = 0, ready = 0;
            rowsEl.forEach(function (tr) {
                if (!tr.querySelector('.stm-tick-box').checked) return;
                ticked++;
                var r = state[tr.dataset.ing];
                if (r && r.ready) ready++;
            });
            var el = document.getElementById('stm-ready');
            if (el) {
                el.textContent = ticked === 0 ? 'Nothing ticked yet.'
                    : ready + ' of ' + ticked + ' ticked row(s) ready to save' + (ready < ticked ? ' (the others need a price or cannot go on the menu).' : '.');
            }
            if (saveBtn) {
                saveBtn.disabled = ready === 0;
                document.getElementById('stm-save-text').textContent = ready > 0 ? 'Add ' + ready + ' to the menu' : 'Add to the menu';
            }
        }

        function post(action, rows) {
            var body = new URLSearchParams();
            body.set('csrf_token', data.csrf || '');
            body.set('action', action);
            body.set('rows', JSON.stringify(rows));
            return fetch('stock-to-menu.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: body.toString()
            }).then(function (res) { return res.json(); });
        }

        // the changed rows + every ticked row (two ticked rows can be the same drink): checked by the server, then painted
        function refresh(extraIds) {
            var ids = {};
            (extraIds || []).forEach(function (id) { ids[id] = true; });
            rowsEl.forEach(function (tr) { if (tr.querySelector('.stm-tick-box').checked) ids[tr.dataset.ing] = true; });
            var rows = Object.keys(ids).map(function (id) { var tr = rowEl(id); return tr ? input(tr) : null; }).filter(Boolean);
            if (!rows.length) { counts(); return Promise.resolve(); }
            var mine = ++seq;
            if (saveBtn) saveBtn.disabled = true;
            return post('preview', rows).then(function (res) {
                if (mine !== seq) return;
                if (!res || !res.ok) { showResult('error', esc((res && res.error) || 'The rows could not be checked.')); return; }
                res.rows.forEach(function (r) {
                    state[r.ing_id] = r;
                    var tr = rowEl(r.ing_id);
                    if (tr) paint(tr, r);
                });
                counts();
            }).catch(function () {
                if (mine === seq) showResult('error', 'The rows could not be checked. Check the connection and try again.');
            });
        }
        function later(id) {
            pending[id] = true;
            clearTimeout(timer);
            timer = setTimeout(function () { var ids = Object.keys(pending); pending = {}; refresh(ids); }, 350);
        }

        function showResult(kind, html) {
            if (!result) return;
            result.className = 'stm-msg is-shown stm-msg--' + kind;
            result.innerHTML = html;
            result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        rowsEl.forEach(function (tr) {
            var id = tr.dataset.ing;
            if (state[id]) paint(tr, state[id]);
            tr.querySelector('.stm-tick-box').addEventListener('change', function () { tr.classList.toggle('stm-row--ticked', this.checked); refresh([id]); });
            tr.querySelector('.stm-in-price').addEventListener('input', function () {
                var box = tr.querySelector('.stm-tick-box');
                if (this.value.trim() !== '' && !box.checked) { box.checked = true; tr.classList.add('stm-row--ticked'); }
                later(id);
            });
            ['.stm-in-name', '.stm-in-cat'].forEach(function (sel) { tr.querySelector(sel).addEventListener('input', function () { later(id); }); });
            tr.querySelector('.stm-in-sold').addEventListener('change', function () { later(id); });
        });
        q.addEventListener('input', filter);
        if (exBox) {
            exBox.addEventListener('change', function () {
                try { window.localStorage.setItem(VAT_KEY, exBox.checked ? '1' : '0'); } catch (e) { /* works without it */ }
                paintHead();
                rowsEl.forEach(function (tr) { var r = state[tr.dataset.ing]; if (r) paint(tr, r); });
            });
        }
        paintHead();
        show.addEventListener('change', filter);
        document.getElementById('stm-tick-shown').addEventListener('click', function () {
            var ids = [];
            rowsEl.forEach(function (tr) {
                if (tr.dataset.filterHidden === '1' || tr.dataset.status === 'blocked' || tr.dataset.status === 'same') return;
                var box = tr.querySelector('.stm-tick-box');
                if (!box.checked) { box.checked = true; tr.classList.add('stm-row--ticked'); ids.push(tr.dataset.ing); }
            });
            refresh(ids);
        });
        document.getElementById('stm-untick').addEventListener('click', function () {
            rowsEl.forEach(function (tr) { tr.querySelector('.stm-tick-box').checked = false; tr.classList.remove('stm-row--ticked'); });
            counts();
            filter();
        });
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var rows = rowsEl.filter(function (tr) { return tr.querySelector('.stm-tick-box').checked; }).map(input);
                if (!rows.length) return;
                saveBtn.disabled = true;
                var txt = document.getElementById('stm-save-text');
                var was = txt.textContent;
                txt.textContent = 'Saving...';
                post('save', rows).then(function (res) {
                    txt.textContent = was;
                    if (!res || !res.ok) { showResult('error', esc((res && res.error) || 'Nothing was saved.')); counts(); return; }
                    var parts = [];
                    if (res.added.length) parts.push('<strong>Added to the menu (' + res.added.length + '):</strong> ' + res.added.map(function (a) { return esc(a.name) + ' at ' + esc(money(a.price)) + ' (' + esc(a.category) + ')'; }).join(', ') + '.');
                    if (res.updated.length) parts.push('<strong>Price changed (' + res.updated.length + '):</strong> ' + res.updated.map(function (a) { return esc(a.name) + ' ' + esc(money(a.old_price)) + ' -> ' + esc(money(a.price)); }).join(', ') + '.');
                    if (res.skipped.length) parts.push('<strong>Skipped (' + res.skipped.length + '):</strong> ' + res.skipped.map(function (a) { return esc(a.name) + ': ' + esc(a.why); }).join(' '));
                    showResult(res.skipped.length && !res.added.length && !res.updated.length ? 'warn' : 'ok', parts.join('<br>') || 'Nothing to save.');
                    // saved rows: untick and check again (they now show "Already on the menu at this price")
                    var done = {};
                    res.added.concat(res.updated).forEach(function (a) { done[String(a.name).toLowerCase()] = true; });
                    var again = [];
                    rowsEl.forEach(function (tr) {
                        var r = state[tr.dataset.ing];
                        var box = tr.querySelector('.stm-tick-box');
                        if (box.checked && r && r.ready && done[String(r.name).toLowerCase()]) { box.checked = false; tr.classList.remove('stm-row--ticked'); again.push(tr.dataset.ing); }
                    });
                    refresh(again).then(filter);
                }).catch(function () {
                    txt.textContent = was;
                    showResult('error', 'The save could not be sent. Check the connection; nothing was saved unless the menu shows it.');
                    counts();
                });
            });
        }
        filter();
        counts();
        // ticked on arrival (from Stock Items): nothing to check until a price is typed; the first paint came with the page
    }

    window.RHStockToMenu = { init: init };
})();
