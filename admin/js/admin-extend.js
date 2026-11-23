/**
 * RhExtend: the one "Extend..." dialog (owner 2026-11-23: "extend for more days or months, years, time";
 * .claude/GRID_UNIFY_PLAN.md Phase 3). Used by the Calendar (block runs), Blocked Dates (grid + list), Maintenance
 * (timeline + list) and Housekeeping (timeline + list). Nothing is saved here: the page's own server action does
 * the work (admin/blocked-dates.php extend_blocks, room-maintenance.php / housekeeping.php bulk extend) with its
 * CSRF, permission, locks and the booking-clash check.
 *
 * API
 *   RhExtend.open({kind: 'blocks'|'maint'|'hk', title, sub, submit(how, preview) -> Promise<json>, done(json)})
 *       Step 1: the fields. "Check" posts submit(how, true) (read only) and shows what would happen (booked nights
 *       skipped, jobs refused, duplicate days). Step 2: "Extend" / "Repeat" posts submit(how, false); done(json).
 *       how: blocks / maint {mode: 'by', n, unit} | {mode: 'until', until}; hk {every: 'daily'|'weekly', until}.
 *   RhExtend.post(url, fields) -> Promise<json>   POST as the admin pages expect (CSRF + X-Requested-With).
 *   RhExtend.flash(msg, kind)                     show a message after the next page load (the save reloads).
 *   A list bulk bar button <button type="button" data-rh-extend="maint|hk"> opens it for the ticked rows and posts
 *   the bar's own action with bulk_op=extend (RhGridSel.runListBulk does the same for rows picked on a timeline).
 * Text reaches the page only through textContent.
 */
(function () {
    'use strict';
    if (window.RhExtend) return;

    var FLASH = 'rhExtendFlash';
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }
    function icon(name) {
        var i = el('i', 'fas ' + name);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }
    function ymd(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function addDays(n) {
        var d = new Date();
        d.setDate(d.getDate() + n);
        return ymd(d);
    }
    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var inp = document.querySelector('input[name="csrf_token"]');
        return window._rhCsrf || (meta ? meta.getAttribute('content') || '' : '') || (inp ? inp.value : '');
    }
    function help(text) {
        var h = el('span', 'rh-extend__help', '?');
        h.setAttribute('tabindex', '0');
        h.setAttribute('role', 'img');
        h.setAttribute('aria-label', text);
        h.title = text;
        return h;
    }

    function post(url, fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) {
            var v = fields[k];
            if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', String(x)); });
            else fd.append(k, String(v));
        });
        if (!fd.has('csrf_token')) fd.append('csrf_token', token());
        return fetch(url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) {
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) throw new Error('The server did not confirm this. Reload the page and check before trying again.');
            return r.json();
        }, function () {
            throw new Error('Could not reach the server. Check the connection and try again.');
        });
    }

    function flash(msg, kind) {
        try { window.sessionStorage.setItem(FLASH, JSON.stringify({ m: String(msg || ''), k: kind || 'success' })); } catch (e) { /* private mode: no message after reload */ }
    }
    function showFlash() {
        var raw = null;
        try { raw = window.sessionStorage.getItem(FLASH); window.sessionStorage.removeItem(FLASH); } catch (e) { raw = null; }
        if (!raw) return;
        var f = null;
        try { f = JSON.parse(raw); } catch (e) { f = null; }
        if (!f || !f.m) return;
        var safe = String(f.m).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        if (window.Alert && typeof window.Alert.show === 'function') window.Alert.show(safe, f.k === 'error' ? 'error' : 'success');
    }

    var cur = null;
    function close() {
        if (!cur) return;
        var d = cur.dlg, opener = cur.opener;
        cur = null;
        if (d.open && typeof d.close === 'function') d.close();
        d.remove();
        if (opener && document.contains(opener) && opener.focus) opener.focus();
    }

    /** The fields of one kind; returns read() -> how (throws a plain message when something is missing). */
    function buildFields(kind, body) {
        var today = addDays(0);
        if (kind === 'hk') {
            var row = el('div', 'rh-extend__row');
            var lab = el('label', 'rh-extend__label', 'Repeat');
            var every = el('select', 'rh-extend__input');
            every.name = 'every';
            [['daily', 'Every day'], ['weekly', 'Every week (same weekday)']].forEach(function (o) {
                var op = el('option', null, o[1]);
                op.value = o[0];
                every.appendChild(op);
            });
            lab.appendChild(every);
            row.appendChild(lab);
            var lab2 = el('label', 'rh-extend__label', 'until');
            var until = el('input', 'rh-extend__input');
            until.type = 'date';
            until.name = 'until';
            until.min = addDays(1);
            until.max = addDays(366);
            lab2.appendChild(until);
            row.appendChild(lab2);
            body.appendChild(row);
            body.appendChild(el('p', 'rh-extend__note', 'Each new task keeps the room, type, priority, assignee, time of day and notes. A day that already has an open task of the same type is skipped. At most one year ahead.'));
            return function () {
                if (!until.value) throw new Error('Choose the date to repeat until.');
                return { every: every.value, until: until.value };
            };
        }
        var name = 'rh-extend-mode-' + Math.random().toString(36).slice(2, 8);
        var r1 = el('div', 'rh-extend__row');
        var byL = el('label', 'rh-extend__choice');
        var byR = el('input');
        byR.type = 'radio';
        byR.name = name;
        byR.value = 'by';
        byR.checked = true;
        byL.appendChild(byR);
        byL.appendChild(document.createTextNode(' By '));
        var n = el('input', 'rh-extend__input rh-extend__n');
        n.type = 'number';
        n.min = '1';
        n.max = '1096';
        n.value = '1';
        n.setAttribute('aria-label', 'How many');
        var unit = el('select', 'rh-extend__input');
        unit.setAttribute('aria-label', 'Unit');
        [['days', 'days'], ['weeks', 'weeks'], ['months', 'months'], ['years', 'years']].forEach(function (o) {
            var op = el('option', null, o[1]);
            op.value = o[0];
            unit.appendChild(op);
        });
        r1.appendChild(byL);
        r1.appendChild(n);
        r1.appendChild(unit);
        var r2 = el('div', 'rh-extend__row');
        var unL = el('label', 'rh-extend__choice');
        var unR = el('input');
        unR.type = 'radio';
        unR.name = name;
        unR.value = 'until';
        unL.appendChild(unR);
        unL.appendChild(document.createTextNode(kind === 'maint' ? ' Until (date and time) ' : ' Until (last night) '));
        var until2 = el('input', 'rh-extend__input');
        until2.type = kind === 'maint' ? 'datetime-local' : 'date';
        until2.min = kind === 'maint' ? today + 'T00:00' : today;
        until2.setAttribute('aria-label', kind === 'maint' ? 'New end date and time' : 'New last night');
        r2.appendChild(unL);
        r2.appendChild(until2);
        body.appendChild(r1);
        body.appendChild(r2);
        var pick = function (which) { byR.checked = which === 'by'; unR.checked = which === 'until'; };
        n.addEventListener('focus', function () { pick('by'); });
        unit.addEventListener('focus', function () { pick('by'); });
        until2.addEventListener('focus', function () { pick('until'); });
        body.appendChild(el('p', 'rh-extend__note', kind === 'maint'
            ? 'The end moves on and keeps its time of day; months end on the last day of a shorter month. A job that closes its room is not extended over a booked night. At most 3 years from the start.'
            : 'New nights copy the block\'s type and reason. Booked nights are skipped and listed; nights already blocked stay as they are. At most 3 years per room.'));
        return function () {
            if (unR.checked) {
                if (!until2.value) throw new Error(kind === 'maint' ? 'Choose the new end date and time.' : 'Choose the new last night.');
                return { mode: 'until', until: until2.value };
            }
            var v = parseInt(n.value, 10);
            if (!(v >= 1)) throw new Error('Enter how many days, weeks, months or years (1 or more).');
            return { mode: 'by', n: v, unit: unit.value };
        };
    }

    function open(cfg) {
        close();
        var dlg = el('dialog', 'rh-extend');
        dlg.setAttribute('aria-labelledby', 'rhExtendTitle');
        var head = el('div', 'rh-extend__head');
        var h = el('h2', 'rh-extend__title', cfg.title || 'Extend');
        h.id = 'rhExtendTitle';
        head.appendChild(h);
        if (cfg.sub) head.appendChild(el('p', 'rh-extend__sub', cfg.sub));
        var body = el('div', 'rh-extend__body');
        var read = buildFields(cfg.kind, body);
        var resultWrap = el('div', 'rh-extend__result');
        resultWrap.hidden = true;
        var resultHead = el('p', 'rh-extend__result-head');
        resultHead.appendChild(document.createTextNode('What will happen '));
        resultHead.appendChild(help(cfg.kind === 'hk'
            ? 'Counted now from the tasks on the Housekeeping list: new tasks to add and days skipped because the room already has an open task of that type. Nothing is saved yet.'
            : (cfg.kind === 'maint'
                ? 'Checked now against live bookings (pending, tentative, confirmed, in house) for the newly covered nights of each selected job. Nothing is saved yet.'
                : 'Checked now against live bookings (pending, tentative, confirmed, in house) for every new night of each selected block. Nothing is saved yet.')));
        var result = el('p', 'rh-extend__result-text');
        result.setAttribute('aria-live', 'polite');
        resultWrap.appendChild(resultHead);
        resultWrap.appendChild(result);
        body.appendChild(resultWrap);
        var err = el('p', 'rh-extend__error');
        err.setAttribute('role', 'alert');
        err.hidden = true;
        body.appendChild(err);
        var foot = el('div', 'rh-extend__foot');
        var cancel = el('button', 'btn btn-secondary', 'Cancel');
        cancel.type = 'button';
        var go = el('button', 'btn btn-primary');
        go.type = 'button';
        foot.appendChild(cancel);
        foot.appendChild(go);
        dlg.appendChild(head);
        dlg.appendChild(body);
        dlg.appendChild(foot);
        (document.getElementById('rh-admin-page') || document.body).appendChild(dlg);
        cur = { dlg: dlg, opener: cfg.opener || document.activeElement };

        var saveWord = cfg.kind === 'hk' ? 'Repeat' : 'Extend';
        var stage = 'check', how = null, busy = false;
        function setStage(s) {
            stage = s;
            go.textContent = '';
            go.appendChild(icon(s === 'check' ? 'fa-magnifying-glass' : (cfg.kind === 'hk' ? 'fa-repeat' : 'fa-arrows-left-right')));
            go.appendChild(document.createTextNode(' ' + (s === 'check' ? 'Check' : saveWord)));
            if (s === 'check') resultWrap.hidden = true;
        }
        setStage('check');
        function showErr(m) { err.textContent = m; err.hidden = !m; }
        body.addEventListener('input', function () { if (!busy) { setStage('check'); showErr(''); } });
        body.addEventListener('change', function () { if (!busy) { setStage('check'); showErr(''); } });
        cancel.addEventListener('click', function () { if (!busy) close(); });
        dlg.addEventListener('cancel', function (e) { e.preventDefault(); if (!busy) close(); });
        go.addEventListener('click', function () {
            if (busy) return;
            showErr('');
            if (stage === 'check') {
                try { how = read(); } catch (e) { showErr(e.message); return; }
            }
            busy = true;
            go.disabled = true;
            cancel.disabled = true;
            go.setAttribute('aria-busy', 'true');
            var preview = stage === 'check';
            Promise.resolve().then(function () { return cfg.submit(how, preview); }).then(function (res) {
                busy = false;
                go.disabled = false;
                cancel.disabled = false;
                go.removeAttribute('aria-busy');
                if (!res) { showErr('That did not work. Try again.'); return; }
                if (preview) {
                    result.textContent = String(res.message || '');
                    resultWrap.hidden = false;
                    if (res.success) setStage('save');
                    else showErr('Nothing can be changed with these settings.');
                    return;
                }
                close();
                if (typeof cfg.done === 'function') cfg.done(res);
            }, function (e) {
                busy = false;
                go.disabled = false;
                cancel.disabled = false;
                go.removeAttribute('aria-busy');
                showErr((e && e.message) || 'That did not work. Try again.');
            });
        });
        if (typeof dlg.showModal === 'function') dlg.showModal();
        else dlg.setAttribute('open', '');
        var first = body.querySelector('input, select');
        if (first) first.focus();
        return dlg;
    }

    /** A list's bulk bar: ticked rows -> the page's own bulk action with bulk_op=extend; the page reloads. */
    function forBulk(kind, action, csrf, ids, extra, opener) {
        if (!ids.length) return;
        var noun = kind === 'hk' ? 'task' : 'job';
        open({
            kind: kind,
            opener: opener,
            title: kind === 'hk' ? 'Repeat tasks' : 'Extend jobs',
            sub: ids.length + ' ' + noun + (ids.length === 1 ? '' : 's') + ' selected',
            submit: function (how, preview) {
                var f = { action: action, csrf_token: csrf, bulk_op: 'extend', bulk_ids: ids };
                Object.keys(how).forEach(function (k) { f[k] = how[k]; });
                Object.keys(extra || {}).forEach(function (k) { f[k] = extra[k]; });
                if (preview) f.preview = '1';
                return post(window.location.pathname, f);
            },
            done: function (res) {
                flash(res.message || (res.success ? 'Saved.' : 'Nothing was changed.'), res.success ? 'success' : 'error');
                window.location.reload();
            }
        });
    }

    /**
     * Block runs: items [{t: 'room'|'type'|'all', id, last}] (last = a night of the run; the server extends from the
     * run's end). opts: {sub, opener, url (default blocked-dates.php), done(res)}; without done the page reloads.
     */
    function forBlocks(items, opts) {
        opts = opts || {};
        if (!items.length) return;
        open({
            kind: 'blocks',
            opener: opts.opener,
            title: 'Extend blocks',
            sub: opts.sub || (items.length + ' block' + (items.length === 1 ? '' : 's') + ' selected'),
            submit: function (how, preview) {
                var f = { action: 'extend_blocks', items: JSON.stringify(items) };
                Object.keys(how).forEach(function (k) { f[k] = how[k]; });
                if (preview) f.preview = '1';
                return post(new URL(opts.url || 'blocked-dates.php', window.location.href).pathname, f);
            },
            done: function (res) {
                if (typeof opts.done === 'function') { opts.done(res); return; }
                flash(res.message || (res.success ? 'Saved.' : 'Nothing was changed.'), res.success ? 'success' : 'error');
                window.location.reload();
            }
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('button[data-rh-extend]') : null;
        if (!btn) return;
        var form = btn.closest('form[data-bulk-bar]');
        if (!form) return;
        e.preventDefault();
        var scope = null;
        try { scope = form.getAttribute('data-bulk-scope') ? document.querySelector(form.getAttribute('data-bulk-scope')) : null; } catch (err) { scope = null; }
        scope = scope || form.parentElement;
        var ids = Array.prototype.filter.call(scope.querySelectorAll('input[data-bulk-row]'), function (cb) {
            return cb.checked && !cb.disabled && cb.getClientRects().length > 0;
        }).map(function (cb) { return cb.value; });
        if (btn.getAttribute('data-rh-extend') === 'blocks') { // Blocked Dates list: each ticked night stands for its row's run
            var items = [], seen = {};
            Array.prototype.forEach.call(scope.querySelectorAll('input[data-bulk-row][data-ext-t]'), function (cb) {
                if (!cb.checked || cb.disabled || !cb.getClientRects().length) return;
                var it = { t: cb.getAttribute('data-ext-t'), id: parseInt(cb.getAttribute('data-ext-id'), 10) || null, last: cb.getAttribute('data-ext-last') };
                var k = it.t + ':' + it.id + ':' + it.last;
                if (!seen[k]) { seen[k] = true; items.push(it); }
            });
            forBlocks(items, { opener: btn, sub: items.length + ' blocked night' + (items.length === 1 ? '' : 's') + ' selected (each extends from the end of its block)' });
            return;
        }
        var act = form.querySelector('input[name="action"]');
        var tok = form.querySelector('input[name="csrf_token"]');
        forBulk(btn.getAttribute('data-rh-extend'), act ? act.value : '', tok ? tok.value : token(), ids, null, btn);
    });

    /* ---- the Excel-style end handle (owner 2026-11-23: "dragging the current action across several days or deduct").
       Desktop pointer only (touch scrolls: a tap on the handle opens Extend... instead). */
    var mqFine = window.matchMedia('(pointer: fine)'), mqCoarse = window.matchMedia('(pointer: coarse)');
    function canDrag() { return mqFine.matches && !mqCoarse.matches && window.innerWidth > 1024; }
    var draggedAt = 0;
    /** True right after a handle drag ended (its click must not open the dialog). */
    function justDragged() { return Date.now() - draggedAt < 400; }
    /**
     * Drag a handle by whole days. opts: dayAt(clientX, clientY) -> day number (clamped by the caller) | null,
     * onMove(n) while dragging, onDone(n | null) at the end (null: no move, Esc or cancelled). Returns false when
     * this pointer may not drag (then the click / tap opens Extend...).
     */
    function gripDrag(e, opts) {
        if (e.button !== 0 || e.pointerType !== 'mouse' || !canDrag()) return false;
        e.preventDefault();
        e.stopPropagation();
        var x0 = e.clientX, moved = false, last = null;
        function mv(ev) {
            if (!moved && Math.abs(ev.clientX - x0) < 4) return;
            moved = true;
            ev.preventDefault();
            var n = opts.dayAt(ev.clientX, ev.clientY);
            if (n !== null && n !== last) { last = n; opts.onMove(n); }
        }
        function stop(result) {
            window.removeEventListener('pointermove', mv);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('pointercancel', cancel);
            document.removeEventListener('keydown', key, true);
            if (moved) draggedAt = Date.now();
            opts.onDone(result);
        }
        function up() { stop(moved ? last : null); }
        function cancel() { stop(null); }
        function key(ev) { if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); stop(null); } }
        window.addEventListener('pointermove', mv);
        window.addEventListener('pointerup', up);
        window.addEventListener('pointercancel', cancel);
        document.addEventListener('keydown', key, true);
        return true;
    }
    /** A handle element: <span class="rh-grip" role="button" tabindex="0">. label = what it extends. */
    function grip(cls, label) {
        var g = el('span', 'rh-grip ' + (cls || ''));
        g.setAttribute('role', 'button');
        g.tabIndex = 0;
        g.setAttribute('aria-label', 'Extend or shorten: ' + label);
        g.title = canDrag() ? 'Drag to extend or shorten. Click for Extend...' : 'Extend...';
        return g;
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', showFlash);
    else showFlash();

    window.RhExtend = { open: open, post: post, flash: flash, forBulk: forBulk, forBlocks: forBlocks, close: close,
        canDrag: canDrag, gripDrag: gripDrag, justDragged: justDragged, grip: grip };
})();
