/* Stock Items: set one count measure on many ticked items (owner 2026-11-23, "Build #2").
 * Preview first (POST action=preview_measure_bulk, nothing saved), then Apply (action=measure_bulk_apply with the preview's
 * fingerprint: refused when anything changed since). The server does every check (admin/includes/stock-measure-bulk-lib.php);
 * the ml line here only shows the same sum while typing. Needs stmSelection() / openModal() / closeModal() from the page. */
(function () {
    'use strict';
    var CFG = window.SMB_CFG || { csrf: '', maxN: 1000, measures: [] };
    var state = { fingerprint: '', previewKey: '', busy: false, applied: false };

    function $(id) { return document.getElementById(id); }
    function plural(w) { return w ? (/(s|x|z|ch|sh)$/i.test(w) ? w + 'es' : w + 's') : w; }
    function fmt(n) { return String(Math.round(n * 10000) / 10000); }
    function num(v) { v = String(v || '').trim(); return /^\d+(\.\d+)?$/.test(v) ? parseFloat(v) : null; }
    function words() {
        var big = $('smb_big').value.trim();
        var small = $('smb_small').value.trim();
        return { big: big, small: small, bigW: big || 'bottle', smallW: small || 'measure' };
    }
    function fields() {
        var sel = typeof window.stmSelection === 'function' ? window.stmSelection() : { mode: 'ids', ids: '' };
        var f = {
            big_name: $('smb_big').value, small_name: $('smb_small').value, per_big: $('smb_n').value.trim(),
            bottle_ml: $('smb_bottle_ml').value.trim(), measure_ml: $('smb_measure_ml').value.trim(), side: $('smb_side').value,
            change_too: $('smb_change').checked ? '1' : '0'
        };
        Object.keys(sel).forEach(function (k) { f[k] = sel[k]; });
        return f;
    }
    function key() { return JSON.stringify(fields()); }

    // Labels and the ml line follow what is typed. Any change after a preview switches Apply off until Preview is pressed again.
    function sync() {
        listFill();
        var w = words();
        $('smb_n_lbl').firstChild.textContent = (w.small ? 'How many ' + plural(w.small) + ' in one ' + w.bigW : 'How many smaller in one bigger') + ' ';
        $('smb_bottle_ml_lbl').textContent = 'One ' + (w.big || 'bigger unit') + ' in ml';
        $('smb_measure_ml_lbl').textContent = 'One ' + (w.small || 'smaller measure') + ' in ml';
        var side = $('smb_side');
        side.options[0].textContent = 'The bigger unit' + (w.big ? ' (' + w.big + ')' : '') + ' (quantities stay as they are)';
        side.options[1].textContent = 'The smaller measure' + (w.small ? ' (' + w.small + ')' : '');
        var line = $('smb_ml_line');
        var b = $('smb_bottle_ml').value.trim();
        var m = $('smb_measure_ml').value.trim();
        if (b === '' && m === '') {
            line.hidden = true;
        } else if (b === '' || m === '') {
            line.textContent = 'Type both sizes in ml, or leave both empty.';
            line.className = 'field-note smb-ml-bad';
            line.hidden = false;
        } else {
            var bn = num(b);
            var mn = num(m);
            if (bn === null || mn === null || bn <= 0 || mn <= 0) {
                line.textContent = 'Sizes in ml must be numbers above 0 (use a dot for decimals).';
                line.className = 'field-note smb-ml-bad';
            } else {
                var raw = bn / mn;
                var n = Math.round(raw * 10000) / 10000;
                var exact = Math.abs(n * mn - bn) <= 0.000001 && n >= 1 && n <= CFG.maxN;
                if (!exact && $('smb_n').dataset.fromMl === '1') { $('smb_n').value = ''; $('smb_n').dataset.fromMl = ''; }
                if (Math.abs(n * mn - bn) > 0.000001) {
                    line.textContent = fmt(bn) + ' ml / ' + fmt(mn) + ' ml = ' + fmt(raw) + '... ' + plural(w.smallW) + ': not exact, so it is not used. Type how many ' + plural(w.smallW) + ' are in one ' + w.bigW + ' yourself.';
                    line.className = 'field-note smb-ml-bad';
                } else if (n < 1 || n > CFG.maxN) {
                    line.textContent = fmt(bn) + ' ml / ' + fmt(mn) + ' ml = ' + fmt(n) + ': it must be between 1 and ' + CFG.maxN + '.';
                    line.className = 'field-note smb-ml-bad';
                } else {
                    line.textContent = fmt(bn) + ' ml / ' + fmt(mn) + ' ml = exactly ' + fmt(n) + ' ' + plural(w.smallW) + ' in one ' + w.bigW + '.';
                    line.className = 'field-note smb-ml-ok';
                    var nBox = $('smb_n');
                    if (nBox.value.trim() === '' || nBox.dataset.fromMl === '1') {
                        nBox.value = fmt(n);
                        nBox.dataset.fromMl = '1';
                    }
                }
            }
            line.hidden = false;
        }
        if (state.fingerprint && key() !== state.previewKey) {
            state.fingerprint = '';
            $('smb_apply').disabled = true;
            $('smb_summary').textContent = 'Something was changed after the preview. Press Preview again.';
        }
    }

    // Once a bigger size in ml is typed, a name from the Drink measures list fills its own size (never over a size typed by hand).
    function listFill() {
        var w = $('smb_small').value.trim().toLowerCase();
        var hit = (CFG.measures || []).filter(function (x) { return String(x.name).toLowerCase() === w; })[0];
        var box = $('smb_measure_ml');
        if (box.value.trim() !== '' && box.dataset.fromList !== '1') return;
        if (hit && hit.ml > 0 && $('smb_bottle_ml').value.trim() !== '') {
            box.value = fmt(hit.ml);
            box.dataset.fromList = '1';
        } else if (box.dataset.fromList === '1') {
            box.value = '';
            box.dataset.fromList = '';
        }
    }
    function smallPicked() { sync(); }

    function showErrors(list) {
        var box = $('smb_errors');
        box.innerHTML = '';
        if (!list || !list.length) { box.hidden = true; return; }
        var ul = document.createElement('ul');
        list.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
        box.appendChild(ul);
        box.hidden = false;
    }

    var LABEL = {
        set: 'Will be set', same: 'Already this measure: nothing changes', different: 'Has another measure: kept',
        change: 'Will be changed', skip: 'Skipped', done_set: 'Set', done_change: 'Changed'
    };
    var ORDER = { skip: 0, different: 1, change: 2, done_change: 2, set: 3, done_set: 3, same: 4 };

    function help(text) {
        var i = document.createElement('i');
        i.className = 'help';
        i.setAttribute('data-tip', text);
        i.textContent = '?';
        return i;
    }
    function cell(tr, text, cls) {
        var td = document.createElement('td');
        if (cls) td.className = cls;
        td.textContent = text;
        tr.appendChild(td);
        return td;
    }

    function renderTable(rows) {
        var wrap = $('smb_table_wrap');
        wrap.innerHTML = '';
        if (!rows || !rows.length) return;
        rows = rows.slice().sort(function (a, b) { return (ORDER[a.status] - ORDER[b.status]) || a.name.localeCompare(b.name); });
        var host = document.createElement('div');
        host.className = 'table-responsive smb-table-host';
        var table = document.createElement('table');
        table.className = 'smb-table';
        var thead = document.createElement('thead');
        var hr = document.createElement('tr');
        [['Item', 'The ticked stock item and the unit its stock is kept in.'],
         ['What happens', 'Will be set: it has no count measure yet. Already this measure: nothing changes. Has another measure: kept unless you tick Change these too. Skipped: never saved, the reason says why.'],
         ['Count measure', 'The item\'s count measure now, and what it will be. "none" = it has no count measure.'],
         ['Stock shows as', 'How the stock on hand reads now and after. The quantity itself does not change; point at it for the exact stored figure.']
        ].forEach(function (h) {
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
            tr.className = 'smb-row smb-' + r.status;
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
            badge.className = 'smb-badge smb-badge-' + r.status;
            badge.textContent = LABEL[r.status] || r.status;
            what.appendChild(badge);
            if (r.status === 'skip' && r.why) {
                var why = document.createElement('div');
                why.className = 'smb-why';
                why.textContent = r.why.charAt(0).toUpperCase() + r.why.slice(1) + '.';
                what.appendChild(why);
            }
            var m;
            if (r.status === 'set' || r.status === 'done_set') m = (r.status === 'set' ? 'none -> ' : '') + r.new;
            else if (r.status === 'change') m = r.old + ' -> ' + r.new;
            else if (r.status === 'done_change') m = r.new + ' (was ' + r.old + ')';
            else if (r.status === 'different') m = r.old + ' (kept; yours: ' + r.new + ')';
            else m = r.old || 'none';
            cell(tr, m, 'smb-measure').setAttribute('data-label', 'Count measure');
            var q = cell(tr, (r.status === 'set' || r.status === 'change') && r.qty_after !== r.qty_now ? r.qty_now + ' -> ' + r.qty_after : r.qty_after, 'smb-qty');
            q.title = 'Exact: ' + r.qty_exact;
            q.setAttribute('data-label', 'Stock shows as');
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
        var f = fields();
        f.action = action;
        f.csrf_token = CFG.csrf;
        Object.keys(extra || {}).forEach(function (k) { f[k] = extra[k]; });
        var body = new URLSearchParams();
        Object.keys(f).forEach(function (k) { body.append(k, f[k]); });
        return fetch(window.location.pathname, {
            method: 'POST', credentials: 'same-origin', body: body,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) {
            return r.json().catch(function () { throw new Error('HTTP ' + r.status); });
        });
    }

    function busy(on, text) {
        state.busy = on;
        $('smb_preview').disabled = on;
        if (on) { $('smb_apply').disabled = true; }
        if (text) { $('smb_summary').textContent = text; $('smb_result').hidden = false; }
    }

    window.smbOpen = function () {
        var n = document.querySelectorAll('.ing-row .stm-tick:checked').length;
        if (!n) return;
        $('smb_count').textContent = String(n);
        $('smb_count_word').textContent = n === 1 ? 'item' : 'items';
        state.fingerprint = '';
        state.applied = false;
        $('smb_apply').disabled = true;
        $('smb_apply').hidden = false;
        $('smb_preview').hidden = false;
        $('smb_cancel').textContent = 'Cancel';
        $('smb_result').hidden = true;
        $('smb_table_wrap').innerHTML = '';
        $('smb_change_wrap').hidden = true;
        $('smb_change').checked = false;
        showErrors([]);
        sync();
        window.openModal('smbModal');
        $('smb_small').focus();
    };

    window.smbClose = function () {
        window.closeModal('smbModal');
        if (state.applied) {
            window.location.reload(); // the list shows the new measures
        }
    };

    window.smbPreview = function () {
        if (state.busy) return;
        showErrors([]);
        busy(true, 'Working out what happens to each item...');
        var k = key();
        post('preview_measure_bulk').then(function (d) {
            busy(false);
            if (!d || !d.ok) {
                $('smb_result').hidden = true;
                showErrors(d && d.errors && d.errors.length ? d.errors : ['The preview could not be worked out.']);
                return;
            }
            var c = d.counts;
            var parts = [];
            if (c.set) parts.push(c.set + ' will be set');
            if (c.change) parts.push(c.change + ' will be changed');
            if (c.same) parts.push(c.same + ' already have it (nothing changes)');
            if (c.different) parts.push(c.different + ' have another measure (kept)');
            if (c.skip) parts.push(c.skip + ' skipped');
            $('smb_summary').textContent = 'Preview: ' + parts.join(', ') + '. Quantities, costs and batches do not change.'
                + (c.set + c.change ? ' Press Apply to save.' : ' Nothing would be saved.');
            var diffCount = c.different + c.change + (d.rows || []).filter(function (r) { return r.status === 'skip' && r.old; }).length;
            $('smb_change_wrap').hidden = diffCount === 0 && !$('smb_change').checked;
            $('smb_change_text').textContent = 'Change these too (items that already have a different count measure)';
            renderTable(d.rows);
            $('smb_result').hidden = false;
            state.fingerprint = d.fingerprint;
            state.previewKey = k;
            $('smb_apply').disabled = !(c.set + c.change) || key() !== k;
        }).catch(function () {
            busy(false);
            showErrors(['The preview could not be loaded. Check the connection and try again.']);
        });
    };

    window.smbApply = function () {
        if (state.busy || !state.fingerprint || key() !== state.previewKey) return;
        showErrors([]);
        busy(true, 'Saving...');
        post('measure_bulk_apply', { fingerprint: state.fingerprint }).then(function (d) {
            busy(false);
            if (!d || !d.ok) {
                state.fingerprint = '';
                showErrors(d && d.errors && d.errors.length ? d.errors : ['Nothing was saved.']);
                return;
            }
            state.applied = true;
            state.fingerprint = '';
            var sk = (d.skipped || []).length;
            $('smb_summary').textContent = 'Done. Set on ' + d.set.length + ', changed on ' + d.changed.length + ', unchanged ' + d.unchanged.length + ', skipped ' + sk
                + '. Quantities, costs and batches did not change.';
            renderTable(d.rows);
            $('smb_apply').hidden = true;
            $('smb_preview').hidden = true;
            $('smb_change_wrap').hidden = true;
            $('smb_cancel').textContent = 'Close';
        }).catch(function () {
            busy(false);
            showErrors(['The answer did not arrive. Reload the page and check the items before trying again.']);
        });
    };

    ['smb_big', 'smb_n', 'smb_side', 'smb_bottle_ml', 'smb_measure_ml', 'smb_change'].forEach(function (id) {
        var el = $(id);
        if (!el) return;
        el.addEventListener(el.tagName === 'SELECT' || el.type === 'checkbox' ? 'change' : 'input', function () {
            if (id === 'smb_n') el.dataset.fromMl = '';
            if (id === 'smb_measure_ml') el.dataset.fromList = '';
            sync();
            if (id === 'smb_change' && state.previewKey) window.smbPreview(); // the list changes with it: show it again
        });
    });
    if ($('smb_small')) {
        $('smb_small').addEventListener('input', smallPicked);
        $('smb_small').addEventListener('change', smallPicked);
    }
})();
