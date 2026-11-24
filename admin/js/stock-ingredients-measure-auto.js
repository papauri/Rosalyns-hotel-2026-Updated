/* Stock Items: fill count measures from bottle sizes (owner 2026-11-24).
 * Opening the dialog runs the preview for every active item (POST action=preview_measure_auto, nothing saved). Rows that will be
 * set start ticked; rows with another (hand-set) measure start unticked. Apply (action=measure_auto_apply) sends the ticked ids and
 * the preview's fingerprint: refused when anything changed since. Every check is on the server (admin/includes/stock-measure-auto-lib.php).
 * Needs openModal() / closeModal() from the page. */
(function () {
    'use strict';
    var CFG = window.SMA_CFG || { csrf: '' };
    var state = { fingerprint: '', busy: false, applied: false };

    function $(id) { return document.getElementById(id); }

    function showErrors(list) {
        var box = $('sma_errors');
        box.innerHTML = '';
        if (!list || !list.length) { box.hidden = true; return; }
        var ul = document.createElement('ul');
        list.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
        box.appendChild(ul);
        box.hidden = false;
    }

    var LABEL = {
        set: 'Will be set', different: 'Has another measure: kept unless ticked', same: 'Already this measure: nothing to do',
        skip: 'Skipped', done_set: 'Set', done_change: 'Changed', not_ticked: 'Not ticked: unchanged'
    };
    var BADGE = { set: 'set', different: 'different', same: 'same', skip: 'skip', done_set: 'done_set', done_change: 'done_change', not_ticked: 'same' };
    var ORDER = { set: 0, done_set: 0, different: 1, done_change: 1, not_ticked: 2, skip: 3, same: 4 };

    function help(text) {
        var i = document.createElement('i');
        i.className = 'help';
        i.setAttribute('data-tip', text);
        i.textContent = '?';
        return i;
    }
    function cell(tr, text, cls, label) {
        var td = document.createElement('td');
        if (cls) td.className = cls;
        td.textContent = text;
        if (label) td.setAttribute('data-label', label);
        tr.appendChild(td);
        return td;
    }
    function ticked() {
        return Array.prototype.map.call(document.querySelectorAll('#sma_table_wrap .sma-tick:checked'), function (b) { return b.value; });
    }
    function syncApply() {
        var n = ticked().length;
        var btn = $('sma_apply');
        btn.disabled = state.busy || !state.fingerprint || n === 0;
        btn.textContent = n ? 'Apply to ' + n + (n === 1 ? ' ticked item' : ' ticked items') : 'Apply to ticked items';
    }

    function renderTable(rows, withTicks) {
        var wrap = $('sma_table_wrap');
        wrap.innerHTML = '';
        if (!rows || !rows.length) return;
        rows = rows.slice().sort(function (a, b) { return ((ORDER[a.status] || 0) - (ORDER[b.status] || 0)) || a.name.localeCompare(b.name); });
        var host = document.createElement('div');
        host.className = 'table-responsive smb-table-host';
        var table = document.createElement('table');
        table.className = 'smb-table';
        var thead = document.createElement('thead');
        var hr = document.createElement('tr');
        var heads = [];
        if (withTicks) heads.push(['Save', 'Ticked rows are saved when you press Apply. Rows that will be set start ticked, except items sold in more than one measure (for example shot and double): check those, then tick. Rows that already have another measure (set by hand) start unticked and only change if you tick them.']);
        heads.push(['Item', 'The stock item and the unit its stock is kept in.']);
        heads.push(['What happens', 'Will be set: it has no count measure yet. Has another measure: kept unless you tick it. Already this measure: nothing to do. Skipped: never saved, the reason says why.']);
        heads.push(['Count measure', 'The measure worked out from the bottle size and the smallest drink measure it is sold in; for an item that already has one, what it is now and what it would become.']);
        heads.push(['Sold in', 'The drink measures its menu items are sold in (from the menu items\' stock links), smallest first. A whole bottle is left out.']);
        heads.push(['Stock shows as', 'How the stock on hand reads now and after. The quantity itself does not change; point at it for the exact stored figure.']);
        heads.forEach(function (h) {
            var th = document.createElement('th');
            th.appendChild(document.createTextNode(h[0] + ' '));
            th.appendChild(help(h[1]));
            hr.appendChild(th);
        });
        thead.appendChild(hr);
        table.appendChild(thead);
        var tbody = document.createElement('tbody');
        rows.forEach(function (r) {
            var tr = document.createElement('tr');
            tr.className = 'smb-row smb-' + r.status + (r.status === 'same' ? ' sma-muted' : '');
            if (withTicks) {
                var tc = cell(tr, '', 'sma-tick-col');
                if (r.status === 'set' || r.status === 'different') {
                    var lab = document.createElement('label');
                    lab.className = 'stm-tick-label';
                    var box = document.createElement('input');
                    box.type = 'checkbox';
                    box.className = 'sma-tick';
                    box.value = String(r.id);
                    box.checked = !!r.tick;
                    box.setAttribute('aria-label', 'Save ' + r.name);
                    box.addEventListener('change', syncApply);
                    lab.appendChild(box);
                    tc.appendChild(lab);
                }
            }
            var name = cell(tr, '', 'smb-name');
            var strong = document.createElement('strong');
            strong.textContent = r.name;
            name.appendChild(strong);
            var unit = document.createElement('span');
            unit.className = 'smb-unit';
            unit.textContent = ' (' + r.unit + ')';
            name.appendChild(unit);
            var what = cell(tr, '', 'smb-what');
            var badge = document.createElement('span');
            badge.className = 'smb-badge smb-badge-' + (BADGE[r.status] || r.status);
            badge.textContent = r.status === 'set' && !r.tick && r.note ? 'Will be set if you tick it' : (LABEL[r.status] || r.status);
            what.appendChild(badge);
            if (r.note && (r.status === 'set' || r.status === 'different')) {
                var note = document.createElement('div');
                note.className = 'smb-why';
                note.textContent = r.note + '.';
                what.appendChild(note);
            }
            if (r.status === 'skip' && r.why) {
                var why = document.createElement('div');
                why.className = 'smb-why';
                why.textContent = r.why.charAt(0).toUpperCase() + r.why.slice(1) + '.';
                what.appendChild(why);
            }
            var m;
            if (r.status === 'set' || r.status === 'done_set') m = r.new;
            else if (r.status === 'different' || r.status === 'not_ticked') m = r.new ? 'Currently ' + r.old + ', would become ' + r.new : (r.old || 'none');
            else if (r.status === 'done_change') m = r.new + ' (was ' + r.old + ')';
            else m = r.old || 'none';
            cell(tr, m, 'smb-measure', 'Count measure');
            cell(tr, r.sold || '–', 'sma-sold', 'Sold in');
            var changing = (r.status === 'set' || r.status === 'different') && r.qty_after !== r.qty_now;
            var q = cell(tr, changing ? r.qty_now + ' -> ' + r.qty_after : (r.status === 'done_set' || r.status === 'done_change' ? r.qty_after : r.qty_now), 'smb-qty', 'Stock shows as');
            q.title = 'Exact: ' + r.qty_exact;
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        host.appendChild(table);
        wrap.appendChild(host);
        if (window.AdminAutoSectionPagination && typeof window.AdminAutoSectionPagination.init === 'function') {
            window.AdminAutoSectionPagination.init(); // 10 per page with the 10 / 25 / 50 / All picker, like every list
        }
    }

    function post(action, extra) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('csrf_token', CFG.csrf);
        Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });
        return fetch(window.location.pathname, {
            method: 'POST', credentials: 'same-origin', body: body,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) {
            return r.json().catch(function () { throw new Error('HTTP ' + r.status); });
        });
    }

    function busy(on, text) {
        state.busy = on;
        $('sma_preview').disabled = on;
        if (text) { $('sma_summary').textContent = text; $('sma_result').hidden = false; }
        syncApply();
    }

    window.smaOpen = function () {
        state.fingerprint = '';
        state.applied = false;
        $('sma_apply').hidden = false;
        $('sma_preview').hidden = false;
        $('sma_cancel').textContent = 'Cancel';
        $('sma_table_wrap').innerHTML = '';
        showErrors([]);
        window.openModal('smaModal');
        window.smaPreview();
    };

    window.smaClose = function () {
        window.closeModal('smaModal');
        if (state.applied) {
            window.location.reload(); // the list shows the new measures
        }
    };

    window.smaPreview = function () {
        if (state.busy) return;
        showErrors([]);
        state.fingerprint = '';
        $('sma_table_wrap').innerHTML = '';
        busy(true, 'Working out a count measure for each item...');
        post('preview_measure_auto').then(function (d) {
            if (!d || !d.ok) {
                busy(false);
                $('sma_result').hidden = true;
                showErrors(d && d.errors && d.errors.length ? d.errors : ['The preview could not be worked out.']);
                return;
            }
            var c = d.counts;
            var parts = [];
            var check = (d.rows || []).filter(function (r) { return r.status === 'set' && !r.tick; }).length;
            if (c.set - check) parts.push((c.set - check) + ' will be set');
            if (check) parts.push(check + ' sold in more than one measure (unticked: check before ticking)');
            if (c.different) parts.push(c.different + ' have another measure (kept unless you tick them)');
            if (c.same) parts.push(c.same + ' already have it');
            if (c.skip) parts.push(c.skip + ' skipped');
            state.fingerprint = d.fingerprint;
            busy(false, (d.rows && d.rows.length ? 'Preview: ' + parts.join(', ') + '. Nothing is saved until you press Apply.' : 'There are no active items.'));
            renderTable(d.rows, true);
            syncApply();
        }).catch(function () {
            busy(false);
            showErrors(['The preview could not be loaded. Check the connection and try again.']);
        });
    };

    window.smaApply = function () {
        var ids = ticked();
        if (state.busy || !state.fingerprint || !ids.length) return;
        showErrors([]);
        var fp = state.fingerprint;
        busy(true, 'Saving...');
        post('measure_auto_apply', { fingerprint: fp, ticked: ids.join(',') }).then(function (d) {
            if (!d || !d.ok) {
                state.fingerprint = '';
                busy(false);
                showErrors(d && d.errors && d.errors.length ? d.errors : ['Nothing was saved.']);
                return;
            }
            state.applied = true;
            state.fingerprint = '';
            busy(false, 'Done. Set on ' + d.set.length + ', changed on ' + d.changed.length + ', unchanged ' + d.unchanged.length + ', skipped ' + (d.skipped || []).length
                + '. Quantities, costs and batches did not change.');
            renderTable(d.rows, false);
            $('sma_apply').hidden = true;
            $('sma_preview').hidden = true;
            $('sma_cancel').textContent = 'Close';
        }).catch(function () {
            busy(false);
            showErrors(['The answer did not arrive. Reload the page and check the items before trying again.']);
        });
    };
})();
