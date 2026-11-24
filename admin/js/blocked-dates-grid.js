/**
 * Blocked Dates calendar grid (admin/blocked-dates.php, Calendar view).
 * Rows: All rooms, each room type, each room (a joined room is one row). Columns: the month's nights.
 * Select nights (click, drag a rectangle, shift-click a range, click a room name for its whole month,
 * click a day for that night in every shown line, arrow keys + Space, Esc clears), then Block or Unblock them in
 * one save (action=grid_apply). The server decides everything: past nights, joined rooms, rows blocked by a wider
 * block. The actions sit in the shared sticky bulk bar (#bdgBar, the admin-bulk-bar look) and in the admin-wide
 * right-click menu (RhContextMenu, admin/js/admin-context-menu.js: right-click, the ContextMenu key / Shift+F10, or a
 * long press on touch), the same as on the Calendar, Housekeeping and Maintenance timelines (GRID_UNIFY_PLAN Phase 1).
 * The grid (#bdgWrap) is swapped after each save, so every handler is delegated.
 */
(function () {
    'use strict';

    // "t|id|d" -> {t, id, d, label, state, booked, info}. Nights picked in other months stay here (and in this tab's
    // sessionStorage) while the month arrows reload the page, so one Block / Unblock can cover several months.
    var selected = new Map();
    var anchor = null;        // last clicked cell, for shift-click
    var drag = null;          // {start, mode, base, moved}
    var busy = false;
    var asking = false; // a Block / Unblock confirm (or its booking check) is open
    var lastPointer = 'mouse'; // Safari fires plain MouseEvent clicks, so remember the last pointer type

    function table() { return document.getElementById('bdgTable'); }
    function keyOf(td) { return td.dataset.t + '|' + (td.dataset.id || '') + '|' + td.dataset.d; }
    function isSel(td) { return td && td.matches && td.matches('td[data-sel="1"]'); }
    /** What a picked night needs once its month is no longer on screen. */
    function rec(td) {
        var tr = td.parentElement;
        return {
            t: td.dataset.t, id: td.dataset.id || '', d: td.dataset.d,
            label: (tr && tr.getAttribute('data-row-label')) || 'Room',
            state: td.dataset.state || '', booked: td.dataset.booked === '1', info: td.dataset.info || ''
        };
    }
    /** Nights the grid on screen covers: {"2026-12-01": true, ...}. */
    function shownDates() {
        var out = {};
        var t = table();
        if (t) t.querySelectorAll('td[data-d]').forEach(function (td) { out[td.dataset.d] = true; });
        return out;
    }
    /** Drop the picks of the month on screen (other months' picks stay). */
    function dropShown() {
        var shown = shownDates();
        selected.forEach(function (v, k) { if (shown[v.d]) selected.delete(k); });
    }
    /** Keep picks that are still selectable on screen, plus every pick in another month. */
    function prune() {
        var shown = shownDates();
        var live = {};
        var t = table();
        if (t) t.querySelectorAll('td[data-sel="1"]').forEach(function (td) { live[keyOf(td)] = td; });
        selected.forEach(function (v, k) {
            if (shown[v.d] && !live[k]) selected.delete(k);
            else if (live[k]) selected.set(k, rec(live[k]));
        });
    }

    var STORE_KEY = 'bdg-selection-v1';
    var ANCHOR_KEY = 'bdg-anchor-v1';
    // The Shift-click anchor once its month is off screen ({t, id, d, label}), so Shift-click (or a long press on touch)
    // in the next month extends the run from it (owner 2026-11-24).
    var anchorRec = null;
    function anchorInfo() { return anchor ? rec(anchor) : anchorRec; }
    function persist() {
        try {
            if (!selected.size) { anchorRec = null; sessionStorage.removeItem(STORE_KEY); sessionStorage.removeItem(ANCHOR_KEY); return; }
            sessionStorage.setItem(STORE_KEY, JSON.stringify(Array.from(selected.values())));
            var a = anchorInfo();
            if (a) sessionStorage.setItem(ANCHOR_KEY, JSON.stringify(a)); else sessionStorage.removeItem(ANCHOR_KEY);
        } catch (e) { /* storage blocked: the selection just stays on this month */ }
    }
    function todayStr() {
        var now = new Date();
        return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    }
    /** A stored pick checked before use (room line kind, id digits, a night from today on), or null. */
    function cleanRec(v, today) {
        if (!v || ['all', 'type', 'room'].indexOf(v.t) < 0 || typeof v.d !== 'string'
            || !/^\d{4}-\d{2}-\d{2}$/.test(v.d) || v.d < today) return null;
        var id = v.id === null || v.id === undefined ? '' : String(v.id);
        if (id !== '' && !/^\d+$/.test(id)) return null;
        return {
            t: v.t, id: id, d: v.d, label: typeof v.label === 'string' ? v.label : 'Room',
            state: typeof v.state === 'string' ? v.state : '', booked: v.booked === true,
            info: typeof v.info === 'string' ? v.info : ''
        };
    }
    function restore() {
        var list = null;
        try { list = JSON.parse(sessionStorage.getItem(STORE_KEY) || 'null'); } catch (e) { list = null; }
        if (!Array.isArray(list)) return;
        var today = todayStr();
        list.slice(0, 5000).forEach(function (v) {
            var r = cleanRec(v, today);
            if (r) selected.set(r.t + '|' + r.id + '|' + r.d, r);
        });
        if (!anchorRec) {
            try { anchorRec = cleanRec(JSON.parse(sessionStorage.getItem(ANCHOR_KEY) || 'null'), today); } catch (e) { anchorRec = null; }
        }
    }

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

    /** Every picked night as a record: the month on screen first (read live), then nights picked in other months. */
    function selectedCells() {
        var t = table();
        if (!t) return [];
        var out = [], seen = {};
        Array.prototype.forEach.call(t.querySelectorAll('td[data-sel="1"]'), function (td) {
            var k = keyOf(td);
            if (selected.has(k)) { out.push(rec(td)); seen[k] = true; }
        });
        var shown = shownDates();
        selected.forEach(function (v, k) {
            if (!v || typeof v !== 'object' || !v.t || !v.d) return; // never send a pick without its room line + night
            if (!seen[k] && !shown[v.d]) { var r = Object.assign({}, v); r.offMonth = true; out.push(r); }
        });
        return out;
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
        cells.forEach(function (c) {
            var g = c.t + '|' + (c.id || '');
            if (!groups[g]) {
                groups[g] = { label: c.label || 'Room', t: c.t, id: c.id ? parseInt(c.id, 10) : null, d: [] };
                order.push(g);
            }
            groups[g].d.push(c.d);
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
        var direct = 0, inherited = 0, open = 0, booked = 0, offMonth = 0;
        cells.forEach(function (c) {
            if (c.state === 'direct') direct++;
            else if (c.state === 'inherited') inherited++;
            else open++;
            if (c.booked) booked++;
            if (c.offMonth) offMonth++;
        });
        var n = cells.length;
        var text;
        var groups = groupCells(cells);
        var allRooms = groups.every(function (g) { return g.t === 'room'; });
        var head = n + (n === 1 ? ' night' : ' nights') + (allRooms
            ? ' in ' + groups.length + (groups.length === 1 ? ' room' : ' rooms')
            : ' on ' + groups.length + (groups.length === 1 ? ' line' : ' lines'));
        if (n === 0) {
            text = 'No nights selected';
        } else if (n === 1) {
            text = cells[0].info || '1 night selected';
        } else {
            var parts = [];
            if (direct) parts.push(direct + ' already blocked');
            if (inherited) parts.push(inherited + ' blocked by the room type or All rooms');
            if (open) parts.push(open + ' open');
            text = whereText(groups, 4) + ' (' + parts.join(', ') + ')';
        }
        if (n > 0 && booked > 0) {
            text += '. ' + booked + ' selected night(s) already have a booking: blocking does not move the guest.';
        }
        if (offMonth > 0) {
            text = text.replace(/\.?\s*$/, '.') + (n === 1 ? ' This night is in another month.'
                : ' ' + offMonth + (offMonth === 1 ? ' of these nights is' : ' of these nights are') + ' in another month.');
        }
        persist();
        el.textContent = '';
        if (n > 0) {
            var strong = document.createElement('strong');
            strong.textContent = head;
            el.appendChild(strong);
            el.appendChild(document.createTextNode(': ' + text));
        } else {
            el.textContent = text;
        }
        var bar = document.getElementById('bdgBar');
        if (bar) bar.hidden = n === 0 && !busy;
        var blockBtn = document.getElementById('bdgBlockBtn');
        var unblockBtn = document.getElementById('bdgUnblockBtn');
        var clearBtn = document.getElementById('bdgClearBtn');
        if (blockBtn) blockBtn.disabled = busy || n === 0;
        if (unblockBtn) unblockBtn.disabled = busy || direct === 0;
        var extendBtn = document.getElementById('bdgExtendBtn');
        if (extendBtn) extendBtn.disabled = busy || direct === 0 || !window.RhExtend;
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
            if (mode === 'add') selected.set(keyOf(td), rec(td));
            else selected.delete(keyOf(td));
        });
        paint();
    }

    /** The room line of a row: "t|id" from its cells. */
    function lineOf(tr) {
        var c = tr && tr.querySelector('td[data-t]');
        return c ? c.dataset.t + '|' + (c.dataset.id || '') : '';
    }
    /** Every night from the anchor a ({t, id, d}, maybe in another month) to td, on the shown lines between the two,
        added to base. Nights on screen count only when selectable; nights off screen from today on (the server still
        decides past, joined and wider-blocked nights on save). false = the anchor's line is not shown. */
    function extendTo(a, td, base) {
        var L = window.RhGridSel && window.RhGridSel.logic;
        if (!a || !td || !L) return false;
        var rows = visibleRows(), ra = -1, rb = rows.indexOf(td.parentElement);
        rows.forEach(function (tr, i) { if (ra < 0 && lineOf(tr) === a.t + '|' + (a.id || '')) ra = i; });
        var dates = L.span(a.d, td.dataset.d);
        if (ra < 0 || rb < 0 || !dates.length) return false;
        var today = todayStr();
        selected = new Map(base);
        for (var r = Math.min(ra, rb); r <= Math.max(ra, rb); r++) {
            var tr = rows[r], ln = lineOf(tr).split('|'), label = tr.getAttribute('data-row-label') || 'Room';
            dates.forEach(function (d) {
                var c = tr.querySelector('td[data-d="' + d + '"]');
                if (c) { if (isSel(c)) selected.set(keyOf(c), rec(c)); }
                else if (d >= today) selected.set(ln[0] + '|' + ln[1] + '|' + d, { t: ln[0], id: ln[1], d: d, label: label, state: '', booked: false, info: '' });
            });
        }
        paint();
        return true;
    }
    /** Touch has no Shift: the long-press menu offers "Select <anchor> to here" on a night outside the selection. */
    function extendItem(td) {
        var a = anchorInfo();
        if (!a || !td || selected.has(keyOf(td)) || (a.t + '|' + (a.id || '') + '|' + a.d) === keyOf(td)) return null;
        var L = window.RhGridSel && window.RhGridSel.logic;
        if (!L) return null;
        return {
            label: 'Select ' + nightsText(L.span(a.d, td.dataset.d)),
            icon: 'fa-arrows-left-right',
            run: function () { extendTo(a, td, new Map(selected)); }
        };
    }

    function toggle(td) {
        var k = keyOf(td);
        if (selected.has(k)) selected.delete(k); else selected.set(k, rec(td));
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
        if (e.target.closest && e.target.closest('.rh-grip')) return; // the end handle has its own gesture
        var td = e.target.closest && e.target.closest('#bdgTable td[data-sel="1"]');
        if (!td || busy || e.button !== 0 || e.pointerType === 'touch') return;
        e.preventDefault();
        if (e.shiftKey && anchor && document.contains(anchor)) {
            var base = new Map(selected);
            applyRect(anchor, td, 'add', base);
            return;
        }
        if (e.shiftKey && !anchor && anchorRec && extendTo(anchorRec, td, new Map(selected))) return; // anchor in another month
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
        var dh = e.target.closest && e.target.closest('#bdgTable thead th.bdg-dayhead');
        if (dh) { selectColumn(dh.cellIndex); return; }
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
            if (all) selected.delete(keyOf(td)); else selected.set(keyOf(td), rec(td));
        });
        anchor = cells[0];
        paint();
    }

    /** Day head: every open night of that column in the shown lines, or clear them when all are selected. */
    function selectColumn(ci) {
        var cells = visibleRows().map(function (tr) { return tr.cells[ci]; }).filter(isSel);
        if (!cells.length) return;
        var all = cells.every(function (td) { return selected.has(keyOf(td)); });
        cells.forEach(function (td) {
            if (all) selected.delete(keyOf(td)); else selected.set(keyOf(td), rec(td));
        });
        anchor = cells[0];
        paint();
    }

    // ── The admin-wide right-click menu (RhContextMenu): the same Block / Unblock / Clear as the bar. ──
    function menuApi() { return window.RhContextMenu || null; }
    function actions() {
        if (busy || asking) return null;
        var cells = selectedCells();
        if (!cells.length) return null;
        var direct = cells.some(function (c) { return c.state === 'direct'; });
        var n = cells.length;
        return {
            title: n + (n === 1 ? ' night selected' : ' nights selected'),
            items: [
                { label: 'Block', icon: 'fa-ban', run: function () { save('block'); } },
                { label: 'Unblock', icon: 'fa-unlock', disabled: !direct, hint: direct ? '' : 'None of these nights has its own block.', run: function () { save('unblock'); } },
                { label: 'Extend...', icon: 'fa-arrows-left-right', disabled: !direct || !window.RhExtend, hint: direct ? '' : 'Select a night of a line\'s own block.', run: function () { extendSel(); } },
                { label: 'Clear selection', icon: 'fa-xmark', run: function () { selected.clear(); paint(); } }
            ]
        };
    }
    /** Right-click outside the selection selects that night (or that line / that day) first. */
    function pickFor(target) {
        var td = target.closest('#bdgTable td[data-sel="1"]');
        if (td) {
            if (!selected.has(keyOf(td))) { dropShown(); selected.set(keyOf(td), rec(td)); anchor = td; paint(); }
            return td;
        }
        var th = target.closest('#bdgTable th[data-row-select]');
        if (th) {
            var rowCells = Array.prototype.filter.call(th.parentElement.cells, isSel);
            if (rowCells.length && !rowCells.every(function (c) { return selected.has(keyOf(c)); })) {
                dropShown();
                rowCells.forEach(function (c) { selected.set(keyOf(c), rec(c)); });
                anchor = rowCells[0];
                paint();
            }
            return rowCells.length ? th : null;
        }
        var dh = target.closest('#bdgTable thead th.bdg-dayhead');
        if (dh) {
            var colCells = visibleRows().map(function (tr) { return tr.cells[dh.cellIndex]; }).filter(isSel);
            if (colCells.length && !colCells.every(function (c) { return selected.has(keyOf(c)); })) {
                dropShown();
                colCells.forEach(function (c) { selected.set(keyOf(c), rec(c)); });
                anchor = colCells[0];
                paint();
            }
            return colCells.length ? dh : null;
        }
        return null;
    }
    // Right-click, the ContextMenu key / Shift+F10 and a long press: outside the selection that night (line, day) first.
    if (menuApi()) {
        menuApi().register('#bdgTable', function (node, e) {
            if (busy || asking || !e.target || !e.target.closest) return null;
            var tdx = e.target.closest('#bdgTable td[data-sel="1"]');
            var ext = tdx && lastPointer === 'touch' ? extendItem(tdx) : null;
            if (ext) { // a long press outside the selection: extend to here first, the selection's actions after
                selected.set(keyOf(tdx), rec(tdx)); // the pressed night is picked too, so the actions include it
                paint();
                var acts = actions();
                return { title: acts ? acts.title : '', items: [ext].concat(acts ? acts.items : []) };
            }
            return pickFor(e.target) ? actions() : null;
        });
    }

    // ── Keyboard: arrows move, Shift+arrows extend, Space/Enter toggles; Enter on a room name selects its row;
    //    Esc clears the selection. ──
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && selected.size && !busy && !asking && !document.body.classList.contains('modal-open')
            && !(menuApi() && menuApi().isOpen()) && document.getElementById('bdgTable')) {
            selected.clear();
            paint();
            return;
        }
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
        fb.className = 'admin-modal-feedback bdg-feedback ' + (ok ? 'admin-modal-feedback--success' : 'admin-modal-feedback--error') + ' visible';
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
            // Booked nights are skipped by the save (never blocked over a booking): say so before saving.
            if (p.can_block === false) { feedback(false, p.note || 'Every selected night is booked. Nothing to block.'); return null; }
            return baseMsg + (p.note ? ' ' + p.note : '');
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

    /* ── Extend (GRID_UNIFY_PLAN Phase 3): Extend... on the selected own-block nights (each extends from the end of
       its run: admin/blocked-dates.php extend_blocks, one transaction, booked nights skipped and listed), and the end
       handle on each run of a line's own block: drag right = extend, drag left = unblock the trimmed nights. ── */
    function dnumG(s) { var p = String(s).split('-'); return Math.round(Date.UTC(+p[0], +p[1] - 1, +p[2]) / 86400000); }
    function dstrG(n) {
        var d = new Date(n * 86400000);
        return d.getUTCFullYear() + '-' + String(d.getUTCMonth() + 1).padStart(2, '0') + '-' + String(d.getUTCDate()).padStart(2, '0');
    }
    function itemOf(t, id, last) { return { t: t, id: id === '' || id === null || id === undefined ? null : Number(id), last: last }; }
    function afterExtend(res) {
        feedback(!!(res && res.success), (res && res.message) || 'Nothing was changed.');
        if (res && res.success) {
            selected.clear();
            if (typeof window.refreshBlockedDatesTable === 'function') window.refreshBlockedDatesTable();
        }
        summary();
    }
    function extendSel(opener) {
        if (!window.RhExtend || busy || asking) return;
        var byRow = {};
        selectedCells().forEach(function (c) {
            if (c.state !== 'direct') return;
            var k = c.t + '|' + c.id;
            (byRow[k] = byRow[k] || { t: c.t, id: c.id, d: [] }).d.push(c.d);
        });
        var items = [];
        Object.keys(byRow).forEach(function (k) {
            var r = byRow[k], ds = r.d.slice().sort();
            ds.forEach(function (d, i) {
                var n = dnumG(d);
                var next = ds[i + 1] ? dnumG(ds[i + 1]) : null;
                if (next !== n + 1) items.push(itemOf(r.t, r.id, d)); // the last night of each picked run
            });
        });
        if (!items.length) return;
        window.RhExtend.forBlocks(items, { opener: opener, url: window.location.pathname, done: afterExtend });
    }
    function addGrips() {
        var t = table();
        if (!t || !window.RhExtend) return;
        t.querySelectorAll('.rh-grip').forEach(function (g) { g.remove(); });
        t.querySelectorAll('tr.bdg-row').forEach(function (tr) {
            var cells = Array.prototype.filter.call(tr.querySelectorAll('td[data-d]'), function () { return true; });
            cells.forEach(function (td, i) {
                if (td.dataset.state !== 'direct' || td.getAttribute('data-sel') !== '1') return;
                var nx = cells[i + 1];
                if (nx && nx.dataset.state === 'direct' && dnumG(nx.dataset.d) === dnumG(td.dataset.d) + 1) return; // not the run's end
                // The month's last night: the run may go on into next month, which this grid cannot see, and a handle
                // here would shorten from the middle of the run (leaving a gap). Extend... in the menu / bar still works.
                if (!nx) return;
                var from = td;
                for (var j = i - 1; j >= 0 && cells[j].dataset.state === 'direct' && dnumG(cells[j].dataset.d) === dnumG(cells[j + 1].dataset.d) - 1; j--) from = cells[j];
                gripOn(tr, td, from, cells);
            });
        });
    }
    function gripOn(tr, td, fromTd, cells) {
        var label = (tr.getAttribute('data-row-label') || 'Room') + ', block ending ' + td.dataset.d;
        var g = window.RhExtend.grip('bdg-grip', label);
        td.appendChild(g);
        var item = itemOf(td.dataset.t, td.dataset.id || '', td.dataset.d);
        var last = dnumG(td.dataset.d);
        var openDlg = function () { if (!busy && !asking) window.RhExtend.forBlocks([item], { opener: g, url: window.location.pathname, done: afterExtend }); };
        var mark = function (n) {
            cells.forEach(function (c) {
                var x = dnumG(c.dataset.d);
                c.classList.toggle('bdg-cell--grow', n > last && x > last && x <= n);
                c.classList.toggle('bdg-cell--trim', n < last && x > n && x <= last);
            });
        };
        g.addEventListener('pointerdown', function (e) {
            if (busy || asking) { e.stopPropagation(); return; }
            var lo = Math.max(dnumG(fromTd.dataset.d), dnumG(cells.filter(function (c) { return c.getAttribute('data-sel') === '1'; })[0].dataset.d) - 1);
            var hi = dnumG(cells[cells.length - 1].dataset.d);
            var started = window.RhExtend.gripDrag(e, {
                dayAt: function (x, y) {
                    var hit = document.elementFromPoint(x, tr.getBoundingClientRect().top + 4);
                    var c = hit && hit.closest ? hit.closest('td[data-d]') : null;
                    if (!c || c.parentElement !== tr) return null;
                    return Math.min(hi, Math.max(lo, dnumG(c.dataset.d)));
                },
                onMove: mark,
                onDone: function (n) {
                    mark(last);
                    if (n === null || n === last) return;
                    resizeRun(item, td.dataset.t, td.dataset.id || '', last, n, tr.getAttribute('data-row-label') || 'This line');
                }
            });
            if (!started) e.stopPropagation(); // touch: the tap opens Extend...
        });
        g.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!window.RhExtend.justDragged()) openDlg();
        });
        g.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); openDlg(); }
        });
    }
    function resizeRun(item, t, id, last, n, label) {
        var X = window.RhExtend, url = window.location.pathname;
        asking = true;
        var finish = function () { asking = false; summary(); };
        var fail = function (err) { finish(); feedback(false, (err && err.message) || 'That did not work. Try again.'); };
        if (n > last) {
            var base = { action: 'extend_blocks', items: JSON.stringify([item]), mode: 'until', until: dstrG(n) };
            X.post(url, Object.assign({ preview: '1' }, base)).then(function (p) {
                if (!p || !p.success) throw new Error((p && p.message) || 'Nothing new would be blocked.');
                return confirmAsk({ title: 'Extend block', message: label + ': extend the block to the night of ' + dstrG(n) + '? ' + p.message, confirmText: 'Extend', icon: 'fa-arrows-left-right' });
            }).then(function (ok) {
                if (!ok) { finish(); return null; }
                busy = true;
                return X.post(url, base).then(function (res) { busy = false; finish(); afterExtend(res); });
            }).catch(function (err) { busy = false; fail(err); });
            return;
        }
        var nights = [];
        for (var k = n + 1; k <= last; k++) nights.push(dstrG(k));
        confirmAsk({ title: 'Shorten block', message: label + ': unblock ' + nights.length + ' night(s) (' + nightsText(nights) + ')? Those nights go back on sale. A room-type or All rooms block on them stays.',
            confirmText: 'Unblock', icon: 'fa-unlock' }).then(function (ok) {
            if (!ok) { finish(); return null; }
            busy = true;
            return X.post(url, { action: 'grid_apply', op: 'unblock', items: JSON.stringify([{ t: t, id: id === '' ? null : Number(id), d: nights }]) })
                .then(function (res) { busy = false; finish(); afterExtend(res); });
        }).catch(function (err) { busy = false; fail(err); });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('#bdgBlockBtn, #bdgUnblockBtn, #bdgClearBtn, #bdgExtendBtn');
        if (!b) return;
        if (b.id === 'bdgClearBtn') { selected.clear(); paint(); return; }
        if (b.id === 'bdgExtendBtn') { extendSel(b); return; }
        save(b.id === 'bdgBlockBtn' ? 'block' : 'unblock');
    });

    // After the grid is swapped (a save here or in a Block modal): keep the type filter, clear stale picks.
    window.bdgAfterRefresh = function () {
        var f = document.getElementById('bdgTypeFilter');
        if (f && f.value !== '') f.dispatchEvent(new Event('change', { bubbles: true }));
        if (anchor) anchorRec = rec(anchor); // the old grid's anchor, for a Shift-click on the new one
        prune();
        anchor = null;
        initFocus();
        paint();
        addGrips();
    };

    // Picks from other months survive the month arrows / month picker in this tab. Those links are admin SPA swaps
    // (admin-spa.js): this file is not run again, so the new month is painted on rh:content-updated.
    function boot() {
        if (!table()) return;
        if (anchor) anchorRec = rec(anchor); // the last month's anchor: Shift-click here extends from it
        restore();
        prune();
        anchor = null;
        initFocus();
        paint();
        addGrips();
    }
    document.addEventListener('rh:content-updated', boot);
    boot();
})();
