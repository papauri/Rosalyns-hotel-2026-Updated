/**
 * One way to select and act on the room grids and their lists (owner FEATURE 2026-11-23, .claude/GRID_UNIFY_PLAN.md
 * Phase 1). Loaded before room-timeline.js on Calendar, Housekeeping and Maintenance, and on Blocked Dates.
 *
 *  logic      pure helpers (cell keys, rectangles, runs of nights, the "N nights in M rooms" words); also run by
 *             scripts/tests/grid_select_test.mjs
 *  menu       the admin-wide right-click menu RhContextMenu (admin/js/admin-context-menu.js, loaded first): opened
 *             by right-click, the ContextMenu key / Shift+F10, or a long press on touch
 *  bar        the sticky bulk bar (the .admin-bulk-bar look) the timelines use; lists keep admin-bulk.js's own bar
 *  cells(cfg) the selection on a .tl-grid: click = one night, drag = rectangle across rooms, Shift+click = rectangle
 *             from the anchor, Ctrl/Cmd+click = add / remove, room name = its shown nights, day head = that night in
 *             every shown room, arrows + Shift + Space on the keyboard, Esc clears (the engine calls clear()).
 *             Touch: a tap toggles a night, a long press opens the menu, no drag. Bars keep their own gestures
 *             (booking drag, end grip, drawer); ops modes add Ctrl/Cmd or Shift + click to pick several bars.
 *  listBulk   reads a list's admin-bulk.js bar (its action, CSRF token, buttons, confirm words, fields) so a timeline
 *             can run exactly the same bulk action on the jobs / tasks picked on the grid
 *  lists      Shift+click on a row tick box ticks the range; right-click (or long press) on a row opens the menu with
 *             that list's bulk actions (acts on the ticked rows, or on that row): RhContextMenu.register
 *
 * Nothing here saves anything: every action is a callback that uses the page's existing form or POST action, with
 * its CSRF token, permission checks and confirm. Names reach the page only through textContent.
 */
(function () {
    'use strict';

    /* ================================================================ logic (pure, no DOM) */
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function dnum(s) {
        var p = String(s).split('-');
        return Math.round(Date.UTC(+p[0], +p[1] - 1, +p[2]) / 86400000);
    }
    function dstr(n) {
        var d = new Date(n * 86400000);
        return d.getUTCFullYear() + '-' + String(d.getUTCMonth() + 1).padStart(2, '0') + '-' + String(d.getUTCDate()).padStart(2, '0');
    }
    function key(lane, d) { return String(lane) + '|' + d; }
    function parts(k) {
        var i = String(k).lastIndexOf('|');
        return { lane: String(k).slice(0, i), d: String(k).slice(i + 1) };
    }
    /** Keys of the rectangle between cells a and b ({lane, d}), in the given visible order; ok(lane, d) filters. */
    function rect(lanes, dates, a, b, ok) {
        var la = lanes.indexOf(String(a.lane)), lb = lanes.indexOf(String(b.lane));
        var da = dates.indexOf(a.d), db = dates.indexOf(b.d);
        if (la < 0 || lb < 0 || da < 0 || db < 0) return [];
        var out = [];
        for (var r = Math.min(la, lb); r <= Math.max(la, lb); r++) {
            for (var c = Math.min(da, db); c <= Math.max(da, db); c++) {
                if (!ok || ok(lanes[r], dates[c])) out.push(key(lanes[r], dates[c]));
            }
        }
        return out;
    }
    /** Keys grouped by room: {lanes: [lane ids in first-seen order, or in `order`], map: {lane: [sorted dates]}}. */
    function byLane(keys, order) {
        var map = {}, lanes = [];
        keys.forEach(function (k) {
            var p = parts(k);
            if (!map[p.lane]) { map[p.lane] = []; lanes.push(p.lane); }
            if (map[p.lane].indexOf(p.d) === -1) map[p.lane].push(p.d);
        });
        lanes.forEach(function (l) { map[l].sort(); });
        if (order && order.length) {
            lanes.sort(function (x, y) {
                var ix = order.indexOf(x), iy = order.indexOf(y);
                return (ix === -1 ? 1e9 : ix) - (iy === -1 ? 1e9 : iy);
            });
        }
        return { lanes: lanes, map: map };
    }
    /** Sorted 'Y-m-d' dates -> runs of back-to-back nights [[from, to], ...]. */
    function runs(dates) {
        var out = [];
        dates.slice().sort().forEach(function (d) {
            var last = out[out.length - 1];
            if (last && dnum(d) === dnum(last[1]) + 1) last[1] = d;
            else if (!last || last[1] !== d) out.push([d, d]);
        });
        return out;
    }
    /** One room and back-to-back nights only: {lane, from, to} (the last NIGHT, not the check-out), else null. */
    function oneRun(keys) {
        var g = byLane(keys);
        if (g.lanes.length !== 1) return null;
        var r = runs(g.map[g.lanes[0]]);
        return r.length === 1 ? { lane: g.lanes[0], from: r[0][0], to: r[0][1] } : null;
    }
    /** First and last selected night. */
    function bounds(keys) {
        var lo = null, hi = null;
        keys.forEach(function (k) {
            var d = parts(k).d;
            if (lo === null || d < lo) lo = d;
            if (hi === null || d > hi) hi = d;
        });
        return { from: lo, to: hi };
    }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
    /** "3 nights in 2 rooms" (a night of a room counts once). */
    function countWords(keys) {
        var g = byLane(keys);
        var n = 0;
        g.lanes.forEach(function (l) { n += g.map[l].length; });
        return plural(n, 'night', 'nights') + ' in ' + plural(g.lanes.length, 'room', 'rooms');
    }
    /** "3 Dec", "3 to 5 Dec", "30 Dec to 2 Jan"; several runs joined with ", ". */
    function nightsText(dates) {
        return runs(dates).map(function (r) {
            var a = r[0].split('-').map(Number), b = r[1].split('-').map(Number);
            var full = function (x) { return x[2] + ' ' + MONTHS[x[1] - 1]; };
            if (r[0] === r[1]) return full(a);
            if (a[0] === b[0] && a[1] === b[1]) return a[2] + ' to ' + full(b);
            return full(a) + ' to ' + full(b);
        }).join(', ');
    }
    /** Every (room, night) pair, rooms in `order`, nights ascending: [{lane, d}]. */
    function pairs(keys, order) {
        var g = byLane(keys, order), out = [];
        g.lanes.forEach(function (l) { g.map[l].forEach(function (d) { out.push({ lane: l, d: d }); }); });
        return out;
    }

    var logic = {
        dnum: dnum, dstr: dstr, key: key, parts: parts, rect: rect, byLane: byLane, runs: runs, oneRun: oneRun,
        bounds: bounds, countWords: countWords, nightsText: nightsText, pairs: pairs, plural: plural
    };
    if (typeof module === 'object' && module && module.exports) module.exports = logic;
    if (typeof window === 'undefined' || !window.document || window.RhGridSel) return;

    /* ================================================================ DOM helpers */
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }
    function icon(name) {
        var i = el('i', 'fas ' + String(name || '').replace(/^fas\s+/, ''));
        i.setAttribute('aria-hidden', 'true');
        return i;
    }
    function hostEl() { return document.getElementById('rh-admin-page') || document.body; }
    var mqCoarse = window.matchMedia('(pointer: coarse)');
    var LONG_PRESS_MS = (window.RhContextMenu && window.RhContextMenu.LONG_PRESS_MS) || 550;

    /* ================================================================ the right-click menu (admin-context-menu.js) */
    function CM() { return window.RhContextMenu || null; }
    /** o: {title, items, x, y (pointer) or at (element: keyboard), opener}. */
    function openMenu(o) {
        var c = CM();
        if (!c) return;
        c.open(o.at ? null : o.x, o.at ? null : o.y, o.items, { title: o.title, anchorEl: o.at, opener: o.opener });
    }

    /* ================================================================ the sticky bulk bar (timelines) */
    function makeBar(host, label) {
        var bar = el('div', 'admin-bulk-bar tl-bulkbar');
        bar.hidden = true;
        bar.setAttribute('role', 'region');
        bar.setAttribute('aria-label', label || 'Selection');
        var count = el('span', 'admin-bulk-bar__count');
        count.setAttribute('aria-live', 'polite');
        count.appendChild(icon('fa-check-square'));
        count.appendChild(document.createTextNode(' '));
        var strong = el('strong');
        count.appendChild(strong);
        var detail = el('span', 'tl-bulkbar__detail');
        count.appendChild(detail);
        var controls = el('div', 'admin-bulk-bar__controls');
        var clear = el('button', 'btn btn-secondary btn-sm admin-bulk-bar__clear', 'Clear');
        clear.type = 'button';
        bar.appendChild(count);
        bar.appendChild(controls);
        bar.appendChild(clear);
        host.appendChild(bar);
        var onClear = null;
        clear.addEventListener('click', function () { if (onClear) onClear(); });
        return {
            el: bar,
            /** text: main words; sub: optional detail; items: actions ({label, icon, cls, run, href, disabled, hint}
                or {node}: a field shown as is); clearFn: what Clear does. */
            show: function (text, sub, items, clearFn) {
                strong.textContent = text;
                detail.textContent = sub ? ' ' + sub : '';
                onClear = clearFn;
                controls.textContent = '';
                (items || []).forEach(function (it) {
                    if (!it) return;
                    if (it.node) { controls.appendChild(it.node); return; }
                    var b;
                    if (it.href && !it.disabled) {
                        b = el('a', 'btn btn-sm ' + (it.cls || 'btn-secondary'));
                        b.href = it.href;
                    } else {
                        b = el('button', 'btn btn-sm ' + (it.cls || 'btn-secondary'));
                        b.type = 'button';
                        b.disabled = !!it.disabled;
                        b.addEventListener('click', function () { if (!it.disabled && typeof it.run === 'function') it.run(); });
                    }
                    if (it.icon) { b.appendChild(icon(it.icon)); b.appendChild(document.createTextNode(' ')); }
                    b.appendChild(document.createTextNode(it.label));
                    if (it.hint) b.title = it.hint;
                    controls.appendChild(b);
                });
                if (!controls.childNodes.length) controls.appendChild(el('span', 'tl-bulkbar__none', 'Nothing you can do for this selection here.'));
                bar.hidden = false;
            },
            hide: function () { bar.hidden = true; onClear = null; }
        };
    }

    /* ================================================================ a list's bulk bar, reused by a timeline */
    /**
     * The admin-bulk.js form (data-bulk-bar) -> {form, action, csrf, ops: [{op, label, icon, cls, confirm, title, ok,
     * tone, requires}], fields: [select clones, cached per form so a chosen value stays]}.
     */
    var fieldCache = typeof WeakMap === 'function' ? new WeakMap() : null;
    function listBulk(form) {
        if (!form || !form.querySelector) return null;
        var act = form.querySelector('input[name="action"]');
        var tok = form.querySelector('input[name="csrf_token"]');
        if (!act || !tok) return null;
        var ops = [];
        form.querySelectorAll('button[name="bulk_op"]').forEach(function (b) {
            var i = b.querySelector('i');
            ops.push({
                op: b.value,
                label: (b.textContent || '').trim(),
                icon: i ? (String(i.className).match(/fa-[a-z0-9-]+/g) || []).filter(function (c) { return c !== 'fa-solid'; })[0] || '' : '',
                cls: (String(b.className).match(/btn-(primary|secondary|success|warning|danger|info)/) || ['btn-secondary'])[0],
                confirm: b.getAttribute('data-bulk-confirm') || b.getAttribute('data-admin-confirm') || '',
                title: b.getAttribute('data-admin-confirm-title') || (b.textContent || '').trim(),
                ok: b.getAttribute('data-admin-confirm-ok') || (b.textContent || '').trim(),
                tone: b.getAttribute('data-admin-confirm-tone') || 'warning',
                requires: (b.getAttribute('data-bulk-requires') || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean),
                extend: b.getAttribute('data-rh-extend') || '' // Extend... opens admin-extend.js's dialog instead of a confirm
            });
        });
        var fields = fieldCache ? fieldCache.get(form) : null;
        if (!fields) {
            fields = [];
            form.querySelectorAll('select[name]').forEach(function (s) {
                var c = s.cloneNode(true);
                c.removeAttribute('id');
                c.removeAttribute('data-bulk-field');
                c.value = s.value;
                fields.push(c);
            });
            if (fieldCache) fieldCache.set(form, fields);
        }
        return { form: form, action: act.value, csrf: tok.value, ops: ops, fields: fields };
    }
    function fieldValue(spec, name) {
        var f = spec.fields.filter(function (x) { return x.name === name; })[0];
        return f ? String(f.value) : null;
    }
    /** Ask with the list's own confirm words, then POST the list's own form fields for these ids (page reloads). */
    function runListBulk(spec, opSpec, ids) {
        if (opSpec.extend) { // Extend... (GRID_UNIFY_PLAN Phase 3): the shared dialog, then the list's own action
            if (window.RhExtend) window.RhExtend.forBulk(opSpec.extend, spec.action, spec.csrf, ids);
            return;
        }
        var missing = opSpec.requires.filter(function (n) { var v = fieldValue(spec, n); return v === null || v === ''; });
        if (missing.length) {
            var f = spec.fields.filter(function (x) { return x.name === missing[0]; })[0];
            if (f && document.contains(f)) f.focus();
            return;
        }
        var n = ids.length;
        var msg = String(opSpec.confirm || (opSpec.label + ' ' + n + '?')).replace(/\{n\}/g, String(n));
        var ask = window.AdminConfirm && typeof window.AdminConfirm.request === 'function'
            ? window.AdminConfirm.request({ title: opSpec.title, message: msg, confirmText: opSpec.ok, tone: opSpec.tone, icon: opSpec.icon || 'fa-shield-alt' })
            : Promise.resolve(false);
        ask.then(function (ok) {
            if (!ok) return;
            var f = document.createElement('form');
            f.method = 'post';
            f.hidden = true;
            var add = function (name, value) {
                var i = document.createElement('input');
                i.type = 'hidden';
                i.name = name;
                i.value = String(value);
                f.appendChild(i);
            };
            add('action', spec.action);
            add('csrf_token', spec.csrf);
            add('bulk_op', opSpec.op);
            spec.fields.forEach(function (x) { add(x.name, x.value); });
            ids.forEach(function (id) { add('bulk_ids[]', id); });
            document.body.appendChild(f);
            f.submit();
        });
    }

    /* ================================================================ selection on a .tl-grid */
    /**
     * cfg: root, grid, laneAttr ('data-tl-lane' | 'data-ops-lane'), dates() visible 'Y-m-d' list, dayW() px,
     *      selectable(lane, d), cellLabel(lane, d), locked() true while a save / bar drag runs,
     *      onChange(api), cellMenu(api, hit) -> menu {title, items}, roomMenu / dayMenu optional (default cellMenu),
     *      barIds(barEl) -> ids a bar stands for (ops modes; omit = bars are not pickable), barMenu(api, barEl) -> menu,
     *      roomClick: 'select' (the room name selects its row; a link in it still opens from the keyboard).
     */
    function cells(cfg) {
        var grid = cfg.grid, root = cfg.root, attr = cfg.laneAttr;
        var sel = {};      // key -> true
        var bars = {};     // item id -> true
        var anchor = null; // {lane, d}
        var cursor = null; // {lane, d} the cell with the Tab stop
        var drag = null, touch = null, ctxMuteUntil = 0;
        root.classList.add('has-cellsel');

        function lanesShown() {
            var out = [];
            grid.querySelectorAll('.tl-lane[' + attr + ']').forEach(function (r) { out.push(r.getAttribute(attr)); });
            return out;
        }
        function rowOf(lane) {
            var hit = null;
            grid.querySelectorAll('.tl-lane[' + attr + ']').forEach(function (r) { if (!hit && r.getAttribute(attr) === String(lane)) hit = r; });
            return hit;
        }
        function cellOf(lane, d) {
            var row = rowOf(lane);
            var i = cfg.dates().indexOf(d);
            return row && i !== -1 ? row.querySelector('.tl-track > .tl-cell[data-tl-day="' + i + '"]') : null;
        }
        function ok(lane, d) { return !!cfg.selectable(lane, d); }
        /** The night under a point of a room row's track (a block or bar may cover several cells). */
        function hitAt(x, y, target) {
            var node = target && target.nodeType === 1 ? target : document.elementFromPoint(x, y);
            if (!node || !node.closest || !grid.contains(node) || node.closest('.tl-room')) return null;
            var row = node.closest('.tl-lane[' + attr + ']');
            if (!row) return null;
            var track = row.querySelector('.tl-track');
            if (!track) return null;
            var dates = cfg.dates();
            var idx = Math.floor((x - track.getBoundingClientRect().left) / cfg.dayW());
            if (idx < 0 || idx >= dates.length) return null;
            return { lane: row.getAttribute(attr), d: dates[idx], idx: idx, row: row };
        }
        function keys() { return Object.keys(sel); }
        function setKeys(list, base) {
            var next = base ? Object.assign({}, base) : {};
            list.forEach(function (k) { next[k] = true; });
            sel = next;
        }
        function dropKeys(list, base) {
            var next = Object.assign({}, base || sel);
            list.forEach(function (k) { delete next[k]; });
            sel = next;
        }

        function paint() {
            var dates = cfg.dates(), dIdx = {};
            dates.forEach(function (d, i) { dIdx[d] = i; });
            var rows = {};
            grid.querySelectorAll('.tl-lane[' + attr + ']').forEach(function (r) { rows[r.getAttribute(attr)] = r; });
            // Nothing stays picked out of sight: a hidden room / night is never acted on by mistake.
            Object.keys(sel).forEach(function (k) {
                var p = logic.parts(k);
                if (!rows[p.lane] || !(p.d in dIdx) || !ok(p.lane, p.d)) delete sel[k];
            });
            grid.querySelectorAll('.tl-sel').forEach(function (x) { x.parentNode.removeChild(x); });
            grid.querySelectorAll('.tl-cell[aria-selected]').forEach(function (c) { c.removeAttribute('aria-selected'); });
            var g = logic.byLane(Object.keys(sel)), w = cfg.dayW();
            g.lanes.forEach(function (lane) {
                var track = rows[lane].querySelector('.tl-track');
                var idxs = g.map[lane].map(function (d) { return dIdx[d]; }).sort(function (a, b) { return a - b; });
                var start = null, prev = null;
                idxs.concat([null]).forEach(function (i) {
                    if (i !== null && prev !== null && i === prev + 1) { prev = i; return; }
                    if (start !== null) {
                        var o = el('div', 'tl-sel');
                        o.setAttribute('aria-hidden', 'true');
                        o.style.left = (start * w) + 'px';
                        o.style.width = ((prev - start + 1) * w) + 'px';
                        track.appendChild(o);
                    }
                    start = prev = i;
                });
                idxs.forEach(function (i) {
                    var c = track.querySelector('.tl-cell[data-tl-day="' + i + '"]');
                    if (c) c.setAttribute('aria-selected', 'true');
                });
            });
            if (cfg.barIds) {
                var present = {};
                grid.querySelectorAll('.tl-bar').forEach(function (b) {
                    var ids = cfg.barIds(b) || [];
                    ids.forEach(function (id) { present[id] = true; });
                    var on = ids.length > 0 && ids.every(function (id) { return bars[id]; });
                    b.classList.toggle('is-picked', on);
                });
                Object.keys(bars).forEach(function (id) { if (!present[id]) delete bars[id]; });
            }
            tabStop();
            root.classList.toggle('has-picks', Object.keys(sel).length > 0 || Object.keys(bars).length > 0);
            if (cfg.onChange) cfg.onChange(api);
        }
        /** One Tab stop on the cells (the bars keep their own). */
        function tabStop() {
            grid.querySelectorAll('.tl-cell[tabindex]').forEach(function (c) { c.removeAttribute('tabindex'); });
            var c = cursor ? cellOf(cursor.lane, cursor.d) : null;
            if (!c) {
                var lanes = lanesShown(), dates = cfg.dates();
                for (var r = 0; r < lanes.length && !c; r++) {
                    for (var i = 0; i < dates.length && !c; i++) {
                        if (ok(lanes[r], dates[i])) c = cellOf(lanes[r], dates[i]);
                    }
                }
            }
            if (c) c.tabIndex = 0;
        }
        function focusCell(lane, d) {
            var c = cellOf(lane, d);
            if (!c) return;
            cursor = { lane: String(lane), d: d };
            grid.querySelectorAll('.tl-cell[tabindex]').forEach(function (x) { x.removeAttribute('tabindex'); });
            c.tabIndex = 0;
            c.setAttribute('aria-label', (cfg.cellLabel ? cfg.cellLabel(lane, d) : d) + (sel[logic.key(lane, d)] ? ', selected' : ''));
            try { c.focus({ preventScroll: true }); } catch (e) { c.focus(); }
            if (c.scrollIntoView) c.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
        function pickBarsClear() { bars = {}; }

        /* ---- pointer: mouse / pen drag a rectangle; touch taps and long presses */
        function onDown(e) {
            if (e.button !== 0 || (cfg.locked && cfg.locked())) return;
            var t = e.target;
            if (!t.closest || t.closest('.tl-bar, .tl-hk-link, .tl-room, .tl-head__day, a.tl-room__link')) return;
            var hit = hitAt(e.clientX, e.clientY, t);
            if (!hit) return;
            if (e.pointerType === 'touch' || (e.pointerType !== 'mouse' && mqCoarse.matches)) {
                touch = { hit: hit, x: e.clientX, y: e.clientY, fired: false };
                touch.timer = window.setTimeout(function () { longPress(); }, LONG_PRESS_MS);
                return;
            }
            if (!ok(hit.lane, hit.d)) return;
            e.preventDefault();
            var k = logic.key(hit.lane, hit.d);
            var ctrl = e.ctrlKey || e.metaKey;
            pickBarsClear();
            drag = { start: hit, last: hit, moved: false, ctrl: ctrl, base: ctrl ? Object.assign({}, sel) : {}, mode: 'add', soleOff: false };
            if (e.shiftKey && anchor && ok(anchor.lane, anchor.d) && cellOf(anchor.lane, anchor.d)) {
                drag.from = anchor;
                setKeys(logic.rect(lanesShown(), cfg.dates(), anchor, hit, ok), drag.base);
            } else if (ctrl) {
                drag.from = hit;
                drag.mode = sel[k] ? 'remove' : 'add';
                if (drag.mode === 'remove') dropKeys([k], drag.base); else setKeys([k], drag.base);
                anchor = { lane: hit.lane, d: hit.d };
            } else {
                drag.from = hit;
                drag.soleOff = Object.keys(sel).length === 1 && !!sel[k];
                setKeys([k]);
                anchor = { lane: hit.lane, d: hit.d };
            }
            paint();
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
            window.addEventListener('pointercancel', onCancel);
        }
        function onMove(e) {
            if (touch) {
                if (Math.abs(e.clientX - touch.x) > 10 || Math.abs(e.clientY - touch.y) > 10) cancelTouch();
                return;
            }
            if (!drag) return;
            var hit = hitAt(e.clientX, e.clientY, null);
            if (!hit || (hit.lane === drag.last.lane && hit.d === drag.last.d)) return;
            drag.last = hit;
            drag.moved = true;
            drag.soleOff = false;
            var r = logic.rect(lanesShown(), cfg.dates(), drag.from, hit, ok);
            if (drag.mode === 'remove') dropKeys(r, drag.base); else setKeys(r, drag.base);
            paint();
        }
        function onUp() {
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            window.removeEventListener('pointercancel', onCancel);
            if (!drag) return;
            var d = drag;
            drag = null;
            if (!d.moved && d.soleOff) { sel = {}; paint(); }
            focusCell(d.last.lane, d.last.d);
        }
        function onCancel() {
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            window.removeEventListener('pointercancel', onCancel);
            drag = null;
            cancelTouch();
        }
        function cancelTouch() {
            if (touch && touch.timer) window.clearTimeout(touch.timer);
            touch = null;
        }
        function touchUp(e) {
            if (!touch) return;
            var tp = touch;
            cancelTouch();
            if (tp.fired) { e.preventDefault(); return; }
            if (Math.abs(e.clientX - tp.x) > 10 || Math.abs(e.clientY - tp.y) > 10) return;
            if (!ok(tp.hit.lane, tp.hit.d)) return;
            var k = logic.key(tp.hit.lane, tp.hit.d);
            pickBarsClear();
            if (sel[k]) delete sel[k]; else sel[k] = true;
            anchor = { lane: tp.hit.lane, d: tp.hit.d };
            cursor = { lane: tp.hit.lane, d: tp.hit.d };
            paint();
        }
        function longPress() {
            if (!touch) return;
            touch.fired = true;
            touch.timer = 0;
            ctxMuteUntil = Date.now() + 1000;
            var hit = touch.hit;
            var k = logic.key(hit.lane, hit.d);
            if (ok(hit.lane, hit.d) && !sel[k]) { pickBarsClear(); sel[k] = true; anchor = { lane: hit.lane, d: hit.d }; paint(); }
            showCellMenu(hit, { x: touch.x, y: touch.y });
        }

        /* ---- whole room / whole night */
        function pickSet(list, ctrl) {
            if (!list.length) return;
            pickBarsClear();
            var all = list.every(function (k) { return sel[k]; });
            if (all) dropKeys(list);
            else setKeys(list, ctrl ? sel : null);
            paint();
        }
        function roomKeys(lane) {
            return cfg.dates().filter(function (d) { return ok(lane, d); }).map(function (d) { return logic.key(lane, d); });
        }
        function dayKeys(d) {
            return lanesShown().filter(function (l) { return ok(l, d); }).map(function (l) { return logic.key(l, d); });
        }
        function headDate(head) {
            var heads = Array.prototype.slice.call(head.parentNode.querySelectorAll('.tl-head__day'));
            return cfg.dates()[heads.indexOf(head)] || null;
        }

        /* ---- menus */
        function showCellMenu(hit, at) {
            var spec = cfg.cellMenu ? cfg.cellMenu(api, hit) : null;
            if (!spec || !spec.items || !spec.items.length) return;
            openMenu({ title: spec.title, items: spec.items, x: at.x, y: at.y, at: at.el, opener: at.el || (hit ? cellOf(hit.lane, hit.d) : null) });
        }
        function showBarMenu(bar, at) {
            if (!cfg.barMenu) return false;
            if (cfg.barIds) {
                var ids = cfg.barIds(bar) || [];
                if (ids.length && !ids.every(function (id) { return bars[id]; })) {
                    sel = {};
                    bars = {};
                    ids.forEach(function (id) { bars[id] = true; });
                    paint();
                }
            }
            var spec = cfg.barMenu(api, bar);
            if (!spec || !spec.items || !spec.items.length) return false;
            openMenu({ title: spec.title, items: spec.items, x: at.x, y: at.y, at: at.el, opener: bar });
            return true;
        }

        grid.addEventListener('pointerdown', onDown);
        grid.addEventListener('pointerup', function (e) { if (touch) touchUp(e); });
        grid.addEventListener('pointercancel', function () { cancelTouch(); });
        grid.addEventListener('pointermove', function (e) { if (touch) onMove(e); });

        grid.addEventListener('click', function (e) {
            var t = e.target, x;
            if (!t.closest) return;
            // A hatched block is a link: a pointer click selects its night (pointerdown above); Enter still opens it.
            if ((x = t.closest('a.tl-block')) && e.detail !== 0) { e.preventDefault(); return; }
            if ((x = t.closest('.tl-head__day'))) {
                var d = headDate(x);
                if (d) pickSet(dayKeys(d), e.ctrlKey || e.metaKey);
                return;
            }
            if ((x = t.closest('.tl-room')) && !t.closest('.tl-hk-link')) {
                var row = x.closest('.tl-lane[' + attr + ']');
                if (!row) return;
                var link = t.closest('a');
                if (link && e.detail === 0) return; // keyboard Enter on the room link: it opens as before
                if (link) e.preventDefault();
                pickSet(roomKeys(row.getAttribute(attr)), e.ctrlKey || e.metaKey);
                return;
            }
            var bar = t.closest('.tl-bar');
            if (bar && cfg.barIds && (e.ctrlKey || e.metaKey || e.shiftKey)) {
                var ids = cfg.barIds(bar) || [];
                if (!ids.length) return;
                e.preventDefault();
                e.stopPropagation(); // not the drawer: this click picks the bar
                sel = {};
                var all = ids.every(function (id) { return bars[id]; });
                ids.forEach(function (id) { if (all) delete bars[id]; else bars[id] = true; });
                paint();
            }
        });

        grid.addEventListener('contextmenu', function (e) {
            if (Date.now() < ctxMuteUntil) { e.preventDefault(); return; }
            if (CM() && CM().wantsNative(e)) return;
            var t = e.target, x;
            if (!t.closest || t.closest('.tl-hk-link')) return;
            cancelTouch();
            var at = { x: e.clientX, y: e.clientY };
            if ((x = t.closest('.tl-bar'))) {
                if (showBarMenu(x, at)) e.preventDefault();
                return;
            }
            if ((x = t.closest('.tl-head__day'))) {
                var d = headDate(x);
                if (!d) return;
                e.preventDefault();
                var dk = dayKeys(d);
                if (dk.length && !dk.every(function (k) { return sel[k]; })) { pickBarsClear(); setKeys(dk); paint(); }
                showCellMenu(null, at);
                return;
            }
            if ((x = t.closest('.tl-room'))) {
                var row = x.closest('.tl-lane[' + attr + ']');
                if (!row) return;
                e.preventDefault();
                var rk = roomKeys(row.getAttribute(attr));
                if (rk.length && !rk.every(function (k) { return sel[k]; })) { pickBarsClear(); setKeys(rk); paint(); }
                showCellMenu({ lane: row.getAttribute(attr), d: null, room: true }, at);
                return;
            }
            var hit = hitAt(e.clientX, e.clientY, t);
            if (!hit) return;
            e.preventDefault();
            var k = logic.key(hit.lane, hit.d);
            if (!sel[k] && ok(hit.lane, hit.d)) { pickBarsClear(); setKeys([k]); anchor = { lane: hit.lane, d: hit.d }; paint(); }
            showCellMenu(hit, at);
        });

        grid.addEventListener('keydown', function (e) {
            var t = e.target;
            if (!t.closest) return;
            var isMenuKey = e.key === 'ContextMenu' || (e.key === 'F10' && e.shiftKey);
            var bar = t.closest('.tl-bar');
            if (isMenuKey && bar) {
                if (showBarMenu(bar, { el: bar })) e.preventDefault();
                return;
            }
            if (!t.classList || !t.classList.contains('tl-cell')) return;
            var row = t.closest('.tl-lane[' + attr + ']');
            if (!row) return;
            var lane = row.getAttribute(attr), dates = cfg.dates();
            var idx = Number(t.getAttribute('data-tl-day')), d = dates[idx];
            var k = logic.key(lane, d);
            if (isMenuKey) {
                e.preventDefault();
                if (!sel[k] && ok(lane, d)) { pickBarsClear(); setKeys([k]); anchor = { lane: lane, d: d }; paint(); }
                showCellMenu({ lane: lane, d: d, idx: idx, row: row }, { el: t });
                return;
            }
            if (e.key === ' ' || e.key === 'Enter') {
                e.preventDefault();
                if (!ok(lane, d)) return;
                pickBarsClear();
                if (sel[k]) delete sel[k]; else sel[k] = true;
                anchor = { lane: lane, d: d };
                paint();
                focusCell(lane, d);
                return;
            }
            var dr = { ArrowUp: -1, ArrowDown: 1 }[e.key] || 0;
            var dc = { ArrowLeft: -1, ArrowRight: 1 }[e.key] || 0;
            if (!dr && !dc) return;
            e.preventDefault();
            var lanes = lanesShown();
            var r2 = lanes.indexOf(lane) + dr, c2 = idx + dc;
            if (r2 < 0 || r2 >= lanes.length || c2 < 0 || c2 >= dates.length) return;
            var next = { lane: lanes[r2], d: dates[c2] };
            if (e.shiftKey) {
                if (!anchor || !cellOf(anchor.lane, anchor.d)) anchor = { lane: lane, d: d };
                pickBarsClear();
                setKeys(logic.rect(lanes, dates, anchor, next, ok));
                paint();
            }
            focusCell(next.lane, next.d);
        });

        var api = {
            keys: keys,
            barIds: function () { return Object.keys(bars); },
            count: function () { return Object.keys(sel).length + Object.keys(bars).length; },
            clear: function () { sel = {}; bars = {}; anchor = null; paint(); },
            paint: paint,
            lanes: lanesShown,
            busy: function () { return !!drag; }
        };
        return api;
    }

    /* ================================================================ lists: Shift ranges + right-click on rows */
    function bulkScopes() {
        var out = [];
        document.querySelectorAll('form[data-bulk-bar]').forEach(function (bar) {
            var s = null;
            try { s = bar.getAttribute('data-bulk-scope') ? document.querySelector(bar.getAttribute('data-bulk-scope')) : null; } catch (e) { s = null; }
            out.push({ bar: bar, scope: s || bar.parentElement });
        });
        return out;
    }
    function scopeFor(node) {
        var hit = null;
        bulkScopes().forEach(function (x) { if (!hit && x.scope && x.scope.contains(node) && !x.bar.contains(node)) hit = x; });
        return hit;
    }
    function shown(cb) { return !cb.disabled && cb.getClientRects().length > 0; }
    function rowBoxes(scope) {
        return Array.prototype.filter.call(scope.querySelectorAll('input[data-bulk-row]'), shown);
    }
    function changed(cb) { cb.dispatchEvent(new Event('change', { bubbles: true })); }
    var lastBox = null;
    document.addEventListener('click', function (e) {
        var cb = e.target;
        if (!(cb instanceof HTMLInputElement) || !cb.matches('input[data-bulk-row]')) return;
        var sc = scopeFor(cb);
        if (!sc) return;
        if (e.shiftKey && lastBox && lastBox !== cb && sc.scope.contains(lastBox)) {
            var boxes = rowBoxes(sc.scope);
            var a = boxes.indexOf(lastBox), b = boxes.indexOf(cb);
            if (a !== -1 && b !== -1) {
                boxes.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (x) { x.checked = cb.checked; });
                changed(cb);
            }
        }
        lastBox = cb;
    });
    function rowOfTarget(t) {
        if (!t || !t.closest) return null;
        var row = t.closest('tr, li, [data-ops-id]');
        while (row && !row.querySelector('input[data-bulk-row]')) row = row.parentElement ? row.parentElement.closest('tr, li, [data-ops-id]') : null;
        return row;
    }
    /** The menu for a list row: that list's bulk buttons, acting on the ticked rows (or this row when it is not ticked). */
    function listMenu(row) {
        var cb = row.querySelector('input[data-bulk-row]');
        var sc = cb ? scopeFor(cb) : null;
        if (!sc || !shown(cb)) return null;
        if (!cb.checked) { // act on this row only
            rowBoxes(sc.scope).forEach(function (x) { x.checked = false; });
            cb.checked = true;
            changed(cb);
        }
        if (window.AdminBulk && typeof window.AdminBulk.refresh === 'function') window.AdminBulk.refresh();
        var n = rowBoxes(sc.scope).filter(function (x) { return x.checked; }).length;
        var items = [];
        sc.bar.querySelectorAll('button[name="bulk_op"]').forEach(function (btn) {
            var i = btn.querySelector('i');
            var ic = i ? ((String(i.className).match(/fa-[a-z0-9-]+/g) || [])[0] || '') : '';
            var need = (btn.getAttribute('data-bulk-requires') || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            var field = need.length ? sc.bar.querySelector('[name="' + need[0] + '"]') : null;
            var empty = !!(field && String(field.value) === '');
            items.push({
                label: (btn.textContent || '').trim() + (empty ? '...' : ''),
                icon: ic,
                danger: /btn-danger/.test(btn.className),
                hint: empty ? 'Choose in the bar at the bottom first.' : '',
                run: function () {
                    if (empty && field) { field.focus(); return; }
                    if (window.AdminBulk) window.AdminBulk.refresh();
                    if (!btn.disabled) btn.click(); // the list's own button: admin-bulk.js ids + the page's confirm
                }
            });
        });
        items.push({ label: 'Clear selection', icon: 'fa-xmark', run: function () { var c = sc.bar.querySelector('[data-bulk-clear]'); if (c) c.click(); } });
        return { title: plural(n, 'row', 'rows') + ' selected', items: items };
    }
    if (CM()) {
        CM().register('tr, li, [data-ops-id]', function (node, e) {
            var t = e.target;
            // Grids have their own menu; links and buttons in a row keep the browser's menu.
            if (!t || !t.closest || t.closest('.tl-grid, .bdg-table, a[href], button, .actions-more')) return null;
            var row = rowOfTarget(t);
            return row ? listMenu(row) : null;
        });
    }

    /** The admin confirm dialog, always answering {confirmed, value} (value = the optional text box). */
    function ask(opts) {
        var AC = window.AdminConfirm;
        if (!AC) return Promise.resolve({ confirmed: false, value: '' });
        if (typeof AC.ask === 'function') return AC.ask(opts);
        if (opts.input && typeof AC.prompt === 'function') {
            return AC.prompt(opts).then(function (v) { return { confirmed: v !== null, value: v || '' }; });
        }
        return AC.request(opts).then(function (okd) { return { confirmed: !!okd, value: '' }; });
    }

    window.RhGridSel = {
        logic: logic,
        bar: makeBar,
        cells: cells,
        ask: ask,
        listBulk: listBulk,
        runListBulk: runListBulk,
        LONG_PRESS_MS: LONG_PRESS_MS
    };
})();
