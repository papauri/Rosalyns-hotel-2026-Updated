/**
 * Blocked Dates calendar grid (admin/blocked-dates.php, Calendar view).
 * Rows: All rooms, each room type, each room (a joined room is one row). Columns: the month's nights.
 * Select nights (click, drag a rectangle, shift-click a range, click a room name for its whole month,
 * arrow keys + Space), then Block or Unblock them in one save (action=grid_apply). The server decides
 * everything: past nights, joined rooms, rows blocked by a wider block.
 * The grid (#bdgWrap) is swapped after each save, so every handler is delegated.
 */
(function () {
    'use strict';

    var selected = new Map(); // "t|id|d" -> true
    var anchor = null;        // last clicked cell, for shift-click
    var drag = null;          // {start, mode, base, moved}
    var busy = false;
    var asking = false; // a Block / Unblock confirm (or its booking check) is open
    var lastPointer = 'mouse'; // Safari fires plain MouseEvent clicks, so remember the last pointer type

    function table() { return document.getElementById('bdgTable'); }
    function keyOf(td) { return td.dataset.t + '|' + (td.dataset.id || '') + '|' + td.dataset.d; }
    function isSel(td) { return td && td.matches && td.matches('td[data-sel="1"]'); }

    function visibleRows() {
        var t = table();
        if (!t) return [];
        return Array.prototype.filter.call(t.querySelectorAll('tr.bdg-row'), function (tr) {
            return !tr.closest('tbody').hidden;
        });
    }

    function paint() {
        var t = table();
        if (!t) return;
        t.querySelectorAll('td[data-sel="1"]').forEach(function (td) {
            var on = selected.has(keyOf(td));
            td.classList.toggle('is-selected', on);
            td.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        summary();
    }

    function selectedCells() {
        var t = table();
        if (!t) return [];
        return Array.prototype.filter.call(t.querySelectorAll('td[data-sel="1"]'), function (td) {
            return selected.has(keyOf(td));
        });
    }

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** "12 Dec 2026", "12 to 14 Dec 2026", "30 Dec 2026 to 2 Jan 2027"; back-to-back nights joined (same as the server). */
    function nightsText(dates) {
        var ds = dates.slice().sort();
        var runs = [];
        ds.forEach(function (d) {
            var last = runs[runs.length - 1];
            if (last) {
                var p = last[1].split('-').map(Number);
                var next = new Date(p[0], p[1] - 1, p[2] + 1);
                var nextStr = next.getFullYear() + '-' + String(next.getMonth() + 1).padStart(2, '0') + '-' + String(next.getDate()).padStart(2, '0');
                if (nextStr === d) { last[1] = d; return; }
            }
            if (!last || last[1] !== d) runs.push([d, d]);
        });
        return runs.map(function (r) {
            var a = r[0].split('-').map(Number), b = r[1].split('-').map(Number);
            var full = function (x) { return x[2] + ' ' + MONTHS[x[1] - 1] + ' ' + x[0]; };
            if (r[0] === r[1]) return full(a);
            if (a[0] === b[0] && a[1] === b[1]) return a[2] + ' to ' + full(b);
            if (a[0] === b[0]) return a[2] + ' ' + MONTHS[a[1] - 1] + ' to ' + full(b);
            return full(a) + ' to ' + full(b);
        }).join(', ');
    }

    /** Selected cells grouped by their room line: [{label, t, id, d: [nights]}], in the order shown on the grid. */
    function groupCells(cells) {
        var groups = {}, order = [];
        cells.forEach(function (td) {
            var g = td.dataset.t + '|' + (td.dataset.id || '');
            if (!groups[g]) {
                var tr = td.parentElement;
                groups[g] = { label: (tr && tr.getAttribute('data-row-label')) || 'Room', t: td.dataset.t, id: td.dataset.id ? parseInt(td.dataset.id, 10) : null, d: [] };
                order.push(g);
            }
            groups[g].d.push(td.dataset.d);
        });
        return order.map(function (k) { return groups[k]; });
    }

    /** "Room 101 on 12 to 14 Dec 2026; All rooms on 15 Dec 2026", at most max rooms named. */
    function whereText(groups, max) {
        var parts = groups.map(function (g) { return g.label + ' on ' + nightsText(g.d); });
        var more = parts.length - max;
        return parts.slice(0, max).join('; ') + (more > 0 ? '; and ' + more + ' more' : '');
    }

    function summary() {
        var el = document.getElementById('bdgSummary');
        if (!el) return;
        var cells = selectedCells();
        var direct = 0, inherited = 0, open = 0, booked = 0;
        cells.forEach(function (td) {
            if (td.dataset.state === 'direct') direct++;
            else if (td.dataset.state === 'inherited') inherited++;
            else open++;
            if (td.dataset.booked === '1') booked++;
        });
        var n = cells.length;
        var text;
        if (n === 0) {
            text = 'No nights selected';
        } else if (n === 1) {
            text = cells[0].dataset.info || '1 night selected';
        } else {
            var parts = [];
            if (direct) parts.push(direct + ' already blocked');
            if (inherited) parts.push(inherited + ' blocked by the room type or All rooms');
            if (open) parts.push(open + ' open');
            text = n + ' nights selected: ' + whereText(groupCells(cells), 4) + ' (' + parts.join(', ') + ')';
        }
        if (n > 0 && booked > 0) {
            text += '. ' + booked + ' selected night(s) already have a booking: blocking does not move the guest.';
        }
        el.textContent = text;
        var blockBtn = document.getElementById('bdgBlockBtn');
        var unblockBtn = document.getElementById('bdgUnblockBtn');
        var clearBtn = document.getElementById('bdgClearBtn');
        if (blockBtn) blockBtn.disabled = busy || n === 0;
        if (unblockBtn) unblockBtn.disabled = busy || direct === 0;
        if (clearBtn) clearBtn.disabled = busy || n === 0;
    }

    /** Selectable cells in the rectangle between two cells (visible rows only). */
    function rect(a, b) {
        var rows = visibleRows();
        var ra = rows.indexOf(a.parentElement), rb = rows.indexOf(b.parentElement);
        if (ra < 0 || rb < 0) return [];
        var r0 = Math.min(ra, rb), r1 = Math.max(ra, rb);
        var c0 = Math.min(a.cellIndex, b.cellIndex), c1 = Math.max(a.cellIndex, b.cellIndex);
        var out = [];
        for (var r = r0; r <= r1; r++) {
            for (var c = c0; c <= c1; c++) {
                var td = rows[r].cells[c];
                if (isSel(td)) out.push(td);
            }
        }
        return out;
    }

    function applyRect(a, b, mode, base) {
        selected = new Map(base);
        rect(a, b).forEach(function (td) {
            if (mode === 'add') selected.set(keyOf(td), true);
            else selected.delete(keyOf(td));
        });
        paint();
    }

    function toggle(td) {
        var k = keyOf(td);
        if (selected.has(k)) selected.delete(k); else selected.set(k, true);
        anchor = td;
        paint();
    }

    function focusCell(td) {
        var t = table();
        if (!t || !td) return;
        t.querySelectorAll('td[tabindex="0"]').forEach(function (x) { x.setAttribute('tabindex', '-1'); });
        td.setAttribute('tabindex', '0');
        td.focus();
    }

    // ── Mouse / pen: press, drag a rectangle, release. Touch: tap toggles (dragging scrolls). ──
    document.addEventListener('pointerdown', function (e) {
        lastPointer = e.pointerType || 'mouse';
        var td = e.target.closest && e.target.closest('#bdgTable td[data-sel="1"]');
        if (!td || busy || e.button !== 0 || e.pointerType === 'touch') return;
        e.preventDefault();
        if (e.shiftKey && anchor && document.contains(anchor)) {
            var base = new Map(selected);
            applyRect(anchor, td, 'add', base);
            return;
        }
        drag = { start: td, mode: selected.has(keyOf(td)) ? 'remove' : 'add', base: new Map(selected), moved: false };
        applyRect(td, td, drag.mode, drag.base);
        anchor = td;
        focusCell(td);
    });
    document.addEventListener('pointerover', function (e) {
        if (!drag) return;
        var td = e.target.closest && e.target.closest('#bdgTable td[data-sel="1"]');
        if (!td) return;
        drag.moved = drag.moved || td !== drag.start;
        applyRect(drag.start, td, drag.mode, drag.base);
    });
    document.addEventListener('pointerup', function () { drag = null; });
    document.addEventListener('pointercancel', function () { drag = null; });

    document.addEventListener('click', function (e) {
        if (busy) return;
        var td = e.target.closest && e.target.closest('#bdgTable td[data-sel="1"]');
        if (td && (e.pointerType === 'touch' || lastPointer === 'touch')) { // a tap toggles one night
            toggle(td);
            return;
        }
        if (td && e.detail === 0) { // keyboard "click"
            toggle(td);
            return;
        }
        var th = e.target.closest && e.target.closest('#bdgTable th[data-row-select]');
        if (th) selectRow(th.parentElement);
    });

    /** Room name: select every open night of the row, or clear the row when it is all selected already. */
    function selectRow(tr) {
        var cells = Array.prototype.filter.call(tr.cells, isSel);
        if (!cells.length) return;
        var all = cells.every(function (td) { return selected.has(keyOf(td)); });
        cells.forEach(function (td) {
            if (all) selected.delete(keyOf(td)); else selected.set(keyOf(td), true);
        });
        anchor = cells[0];
        paint();
    }

    // ── Keyboard: arrows move, Shift+arrows extend, Space/Enter toggles; Enter on a room name selects its row. ──
    document.addEventListener('keydown', function (e) {
        var th = e.target.closest && e.target.closest('#bdgTable th[data-row-select]');
        if (th && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            selectRow(th.parentElement);
            return;
        }
        var td = e.target.closest && e.target.closest('#bdgTable td[data-sel="1"]');
        if (!td || busy) return;
        if (e.key === ' ' || e.key === 'Enter') {
            e.preventDefault();
            toggle(td);
            return;
        }
        var dr = { ArrowUp: -1, ArrowDown: 1 }[e.key] || 0;
        var dc = { ArrowLeft: -1, ArrowRight: 1 }[e.key] || 0;
        if (!dr && !dc) return;
        e.preventDefault();
        var rows = visibleRows();
        var r = rows.indexOf(td.parentElement), c = td.cellIndex, next = null;
        for (var step = 1; step < 400 && !next; step++) {
            var rr = r + dr * step, cc = c + dc * step;
            if (rr < 0 || rr >= rows.length || cc < 1 || cc >= rows[rr].cells.length) break;
            if (isSel(rows[rr].cells[cc])) next = rows[rr].cells[cc];
        }
        if (!next) return;
        if (e.shiftKey) {
            if (!anchor || !document.contains(anchor)) anchor = td;
            applyRect(anchor, next, 'add', new Map(selected));
        }
        focusCell(next);
    });

    // First focusable cell gets tabindex 0 so the grid can be reached with Tab.
    function initFocus() {
        var t = table();
        if (!t) return;
        var first = t.querySelector('td[data-sel="1"]');
        if (first && !t.querySelector('td[tabindex="0"]')) first.setAttribute('tabindex', '0');
    }

    // ── Room type filter (shows one type's rows; All rooms stays) ──
    document.addEventListener('change', function (e) {
        if (e.target.id !== 'bdgTypeFilter') return;
        var v = e.target.value;
        var t = table();
        if (!t) return;
        t.querySelectorAll('tbody[data-type-group]').forEach(function (tb) {
            tb.hidden = v !== '' && tb.getAttribute('data-type-group') !== v;
        });
        // A hidden row's nights are not saved by mistake: drop them from the selection.
        t.querySelectorAll('tbody[hidden] td[data-sel="1"]').forEach(function (td) { selected.delete(keyOf(td)); });
        paint();
    });

    function feedback(ok, msg) {
        var fb = document.getElementById('bdgFeedback');
        if (!fb) return;
        fb.className = 'admin-modal-feedback ' + (ok ? 'admin-modal-feedback--success' : 'admin-modal-feedback--error') + ' visible';
        fb.textContent = '';
        var i = document.createElement('i');
        i.className = 'fas fa-' + (ok ? 'check-circle' : 'exclamation-circle');
        fb.appendChild(i);
        fb.appendChild(document.createTextNode(' ' + msg));
    }

    function confirmAsk(opts) {
        if (window.AdminConfirm && typeof window.AdminConfirm.request === 'function') {
            return window.AdminConfirm.request(opts);
        }
        return Promise.resolve(true); // no dialog on this page: the buttons are explicit enough
    }

    /** Read-only server check of the selection: {success, booking_count, bookings} or null on a network error. */
    function preview(items) {
        var fd = new FormData();
        fd.append('action', 'preview_block');
        fd.append('items', JSON.stringify(items));
        var tok = document.querySelector('meta[name="csrf-token"]');
        if (tok) fd.append('csrf_token', tok.getAttribute('content'));
        return fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; });
    }

    function save(op) {
        var cells = selectedCells();
        if (!cells.length || busy || asking) return;
        asking = true;
        var grouped = groupCells(cells);
        var items = grouped.map(function (g) { return { t: g.t, id: g.id, d: g.d }; });
        var wide = grouped.filter(function (g) { return g.t !== 'room'; }).map(function (g) { return g.label; });
        var where = whereText(grouped, 8);
        var baseMsg = op === 'block'
            ? 'Block ' + where + '? Guests will not be able to book them.'
              + (wide.length ? ' ' + wide.join(' and ') + (wide.length === 1 ? ' blocks every room under it.' : ' block every room under them.') : '')
            : 'Unblock ' + where + '? Nights blocked by a room type or All rooms stay blocked; unblock those on that line.';
        // Blocking: ask the server first which bookings already sleep on these nights (nothing is saved), so the
        // decision is made before the rooms go off sale. Unblocking needs no check.
        var ask = op === 'block' ? preview(items).then(function (p) {
            if (!p) return baseMsg;
            if (p.success === false) { feedback(false, p.message || 'The selection could not be checked.'); return null; }
            return baseMsg + (p.booking_count > 0
                ? ' Warning: ' + p.booking_count + ' booking(s) already sleep on these nights and will NOT be moved or cancelled: '
                  + p.bookings + '. Each booking\'s History will get a note.'
                : ' No bookings sleep on these nights.');
        }) : Promise.resolve(baseMsg);
        ask.then(function (msg) {
            if (msg === null) return false;
            return confirmAsk({
                title: op === 'block' ? 'Block nights' : 'Unblock nights',
                message: msg,
                confirmText: op === 'block' ? 'Block' : 'Unblock',
                icon: op === 'block' ? 'fa-ban' : 'fa-unlock'
            });
        }).then(function (ok) {
            asking = false;
            if (!ok) return;
            busy = true;
            summary();
            var fd = new FormData();
            fd.append('action', 'grid_apply');
            fd.append('op', op);
            fd.append('items', JSON.stringify(items));
            var bt = document.getElementById('bdgBlockType');
            var rs = document.getElementById('bdgReason');
            fd.append('block_type', bt ? bt.value : 'manual');
            fd.append('reason', rs ? rs.value : '');
            var tok = document.querySelector('meta[name="csrf-token"]');
            if (tok) fd.append('csrf_token', tok.getAttribute('content'));
            fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function (res) {
                    busy = false;
                    feedback(!!res.success, res.message || (res.success ? 'Saved.' : 'Nothing was changed.'));
                    if (res.success) {
                        selected.clear();
                        if (rs) rs.value = '';
                        if (typeof window.refreshBlockedDatesTable === 'function') window.refreshBlockedDatesTable();
                    }
                    summary();
                })
                .catch(function () {
                    busy = false;
                    feedback(false, 'Network error: nothing may have been saved. Reload the page to check, then try again.');
                    summary();
                });
        });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('#bdgBlockBtn, #bdgUnblockBtn, #bdgClearBtn');
        if (!b) return;
        if (b.id === 'bdgClearBtn') { selected.clear(); paint(); return; }
        save(b.id === 'bdgBlockBtn' ? 'block' : 'unblock');
    });

    // After the grid is swapped (a save here or in a Block modal): keep the type filter, clear stale picks.
    window.bdgAfterRefresh = function () {
        var f = document.getElementById('bdgTypeFilter');
        if (f && f.value !== '') f.dispatchEvent(new Event('change', { bubbles: true }));
        var keep = new Map();
        var t = table();
        if (t) t.querySelectorAll('td[data-sel="1"]').forEach(function (td) { if (selected.has(keyOf(td))) keep.set(keyOf(td), true); });
        selected = keep;
        anchor = null;
        initFocus();
        paint();
    };

    initFocus();
    paint();
})();
