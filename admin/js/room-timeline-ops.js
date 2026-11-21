/**
 * Room timeline, operations modes: Housekeeping (admin/housekeeping.php), Maintenance (admin/room-maintenance.php) and
 * Room Service (admin/room-service-dashboard.php).
 * Loaded after room-timeline.js. RoomTimeline.init(root) hands a root whose JSON says mode "housekeeping",
 * "maintenance" or "service" to RoomTimeline.opsEngine with the adapter registered in RoomTimeline.modes (the booking
 * timeline is untouched). Thin renderer (CALENDAR_UI_DECISION 10.2 fallback) that emits the calendar's own .tl-* classes.
 *
 * Adapter shape (Room Service adds opt-in keys the day modes leave out: fixedDays, minColW, rangeLabel, nowX, scrollX,
 * laneHidden, hideByDefault, calLabel, run, poll; a post entry may be a function of the item):
 *   columns(st)           the column heads [{d, wd, day, mon, we, today}] (days here; hours for service)
 *   span(st, item)        [first column, last column, cut at start, cut at end] or null when not in view
 *   rows                  'room' (one lane per room, grouped by room type)
 *   bars(st, lane, items) [{items, a, z, cutS, cutE}] the bars of one lane (several on one cell may become a chip)
 *   bar(st, item)         {cls, name, meta, flags, label} what one bar shows
 *   drawer(st, item, body) the drawer body; actions(st, item) {primary, more}
 *   search(item)          text the search box matches; match(st, item) the page filters; card*(...) the figure cards
 *
 * Changes only through the page's own forms: the same POST action / id / csrf_token fields and the same confirm
 * (the page's hkConfirm / rmConfirm with its exact messages), and the page's own edit / history functions.
 * Names and notes reach the page only through textContent. Bound once per root (data-tl-bound), document listeners
 * once, acting on the mounted root only (SPA safe).
 */
(function () {
    'use strict';
    var RT = window.RoomTimeline;
    if (!RT || !RT.modes || !RT.util) return;
    if (typeof RT.opsEngine !== 'function') define(RT);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();

    function boot() {
        document.querySelectorAll('[data-tl-root]:not([data-tl-bound])').forEach(function (r) { RT.init(r); });
    }

    function define(RT) {
        var U = RT.util;
        var el = U.el, icon = U.icon, help = U.help, dnum = U.dnum, addDays = U.addDays, fmt = U.fmt, fmtRange = U.fmtRange, store = U.store;
        var cur = null;
        var docBound = false;

        /* ------------------------------------------------------------ helpers */
        function q(st, sel) { return st.root.querySelector(sel); }
        function words(s) {
            s = String(s || '').replace(/_/g, ' ').trim();
            return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
        }
        function live() { return cur && document.contains(cur.root) ? cur : null; }
        function listEl(st) { return st.cfg.list ? document.querySelector(st.cfg.list) : null; }
        function tableEl(st) { return st.cfg.table ? document.querySelector(st.cfg.table) : null; }
        function announce(st, text) { var s = q(st, '[data-tl-status]'); if (s) s.textContent = text; }
        function reduce() { return U.mqReduce.matches; }
        function scrollTo(node) {
            if (!node) return;
            if (window.rhAnchors && typeof window.rhAnchors.scrollToResults === 'function') window.rhAnchors.scrollToResults(node);
            else node.scrollIntoView({ block: 'start', behavior: reduce() ? 'auto' : 'smooth' });
        }
        function covers(data, start, days) {
            if (!data || !data.start || !data.dates || !data.dates.length) return false;
            var a = dnum(data.start), s = dnum(start);
            return s >= a && s + days <= a + data.dates.length;
        }
        function calHref(day, roomId) {
            return 'calendar.php?view=timeline&start=' + encodeURIComponent(day) + '&filter_room_id=' + encodeURIComponent(roomId);
        }
        function statusBadge(status) {
            var cls = { pending: 'badge-pending', in_progress: 'badge-info', completed: 'badge-completed', verified: 'badge-success', blocked: 'badge-blocked', cancelled: 'badge-cancelled' }[status] || 'badge-neutral';
            return el('span', 'badge ' + cls, words(status));
        }
        function flag(kind, ic, title) {
            var f = el('span', 'tl-flag tl-flag--' + kind);
            f.appendChild(icon(ic));
            if (title) f.title = title;
            return f;
        }
        function dl(body, label, value, node) {
            var row = el('div', 'tl-dl__row');
            row.appendChild(el('dt', null, label));
            var dd = el('dd');
            if (node) dd.appendChild(node); else dd.textContent = value;
            row.appendChild(dd);
            body.appendChild(row);
        }
        function dayColumns(st) {
            var off = dnum(st.start) - dnum(st.data.start);
            return st.data.dates.slice(off, off + st.days);
        }
        // What the toolbar / status line call the shown range ("21 to 27 Nov"; a one-day mode names its day).
        function rangeText(st, start, days) {
            return st.A.rangeLabel ? st.A.rangeLabel(start) : fmtRange(start, addDays(start, days - 1));
        }

        /* ------------------------------------------------------------ engine */
        function engine(root, cfg, A) {
            var ini = cfg.initial || {};
            var st = {
                root: root,
                cfg: cfg,
                A: A,
                page: String(cfg.page || cfg.mode),
                data: cfg.data,
                api: cfg.api,
                start: cfg.data.start,
                days: 0,
                filters: { type: '', floor: '', status: ini.status ? String(ini.status) : '', priority: '', assignee: '', card: '' },
                search: '',
                collapsed: store(U.STORE_COLLAPSE) || {},
                compact: !!store(U.STORE_DENSITY),
                loading: 0,
                hitLane: 0,
                lastFocus: null,
                view: 'timeline',
                allRooms: false,
                quiet: false,
                scrollPending: true
            };
            cur = st;
            var allowed = U.allowedDays();
            if (A.fixedDays) st.days = A.fixedDays; // a one-day mode (Room Service): no range picker
            else st.days = (ini.days && allowed.indexOf(Number(ini.days)) !== -1) ? Number(ini.days) : U.defaultDays();
            var saved = store('rh_ops_view_' + st.page);
            if (ini.view === 'timeline' || ini.view === 'list') st.view = ini.view;
            else if (saved === 'timeline' || saved === 'list') st.view = saved;
            else st.view = U.mqPhone.matches ? 'list' : 'timeline';

            var stSel = q(st, '[data-tl-filter="status"]');
            if (stSel) {
                stSel.value = st.filters.status;
                if (stSel.value !== st.filters.status) st.filters.status = '';
            }
            root.hidden = false;
            bindRoot(st);
            bindDoc();
            showView(st);
            render(st);
            deepLink(st, ini);
        }

        function go(st, start, days, force) {
            if (start !== st.start) st.scrollPending = true;
            st.start = start;
            st.days = days;
            var dateIn = q(st, '[data-tl-date]');
            if (dateIn) dateIn.value = start;
            if (!force && covers(st.data, start, days)) {
                ++st.loading;
                render(st);
                return;
            }
            var token = ++st.loading;
            st.root.classList.add('is-loading');
            announce(st, 'Loading ' + rangeText(st, start, days) + '...');
            var url = st.api + '?start=' + encodeURIComponent(start) + '&days=' + encodeURIComponent(st.A.fixedDays ? st.A.fixedDays : Math.max(days, 14));
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    if (r.status === 401) throw new Error('You have been signed out. Reload the page to sign in again.');
                    if (r.status === 403) throw new Error('You do not have access to this page.');
                    return r.json().then(function (j) {
                        if (!r.ok || !j || !j.ok) throw new Error((j && j.error) || 'The timeline could not be loaded. Try again.');
                        return j;
                    });
                })
                .then(function (j) {
                    if (token !== st.loading || cur !== st) return;
                    if (!covers(j, st.start, st.days)) { st.root.classList.remove('is-loading'); return; }
                    st.data = j;
                    render(st);
                })
                .catch(function (err) {
                    if (token !== st.loading || cur !== st) return;
                    st.root.classList.remove('is-loading');
                    announce(st, err.message);
                    if (window.Alert && typeof window.Alert.show === 'function') window.Alert.show(err.message, 'error');
                });
        }

        /* ------------------------------------------------------------ filters */
        function itemFiltersActive(st) {
            var f = st.filters;
            return !!(f.status || f.priority || f.assignee || (f.card && st.A.cardOnItems && st.A.cardOnItems(f.card)));
        }
        function itemMatch(st, it) {
            var f = st.filters;
            if (f.status && !st.A.statusMatch(st, it, f.status)) return false;
            if (!f.status && st.A.hideByDefault && st.A.hideByDefault(it)) return false; // e.g. cancelled orders
            if (f.priority && String(it.priority) !== f.priority) return false;
            if (f.assignee) {
                if (f.assignee === '__none') { if (it.assignee) return false; } else if (it.assignee !== f.assignee) return false;
            }
            if (f.card && st.A.cardOnItems && st.A.cardOnItems(f.card) && !(it.cards && it.cards[f.card])) return false;
            return true;
        }
        function laneVisible(st, lane) {
            var f = st.filters;
            if (f.type && String(lane.type_id) !== f.type) return false;
            if (f.floor && lane.floor !== f.floor) return false;
            if (f.card && !(st.A.cardOnItems && st.A.cardOnItems(f.card)) && !st.A.cardLane(st, f.card, lane)) return false;
            if (itemFiltersActive(st) && !(st.itemsByLane[lane.id] || []).length) return false;
            if (st.A.laneHidden && st.A.laneHidden(st, lane)) return false; // e.g. rooms with no order ("Show all rooms" off)
            return true;
        }
        function filterCount(st) {
            return ['type', 'floor', 'status', 'priority', 'assignee'].filter(function (k) { return !!st.filters[k]; }).length;
        }
        function fillSelect(sel, values, allLabel, labelOf) {
            if (!sel) return;
            var keep = sel.value;
            sel.textContent = '';
            var o = el('option', null, allLabel);
            o.value = '';
            sel.appendChild(o);
            values.forEach(function (v) {
                var op = el('option', null, labelOf ? labelOf(v) : v);
                op.value = v;
                sel.appendChild(op);
            });
            sel.value = keep;
            if (sel.value !== keep) sel.value = '';
        }

        /* ------------------------------------------------------------ render */
        function render(st) {
            var d = st.data;
            if (!covers(d, st.start, st.days)) { go(st, st.start, st.days); return; }
            st.root.classList.remove('is-loading');
            var A = st.A;
            var cols = A.columns(st);
            st.cols = cols;
            st.first = dnum(cols[0].d);
            var n = cols.length;

            st.lanesById = {};
            d.lanes.forEach(function (l) { st.lanesById[l.id] = l; });
            st.byId = {};
            st.itemsByLane = {};
            var assignees = {}, prios = {}, anyUnassigned = false;
            d.items.forEach(function (it) {
                st.byId[it.id] = it;
                if (it.assignee) assignees[it.assignee] = true; else anyUnassigned = true;
                if (it.priority) prios[it.priority] = true;
                if (!A.span(st, it) || !itemMatch(st, it)) return;
                (st.itemsByLane[it.room_id] = st.itemsByLane[it.room_id] || []).push(it);
            });
            var aSel = q(st, '[data-tl-filter="assignee"]');
            var aList = Object.keys(assignees).sort(function (x, y) { return x.localeCompare(y); });
            if (anyUnassigned) aList.unshift('__none');
            if (st.filters.assignee && aList.indexOf(st.filters.assignee) === -1) aList.push(st.filters.assignee);
            fillSelect(aSel, aList, 'Everyone', function (v) { return v === '__none' ? 'Nobody (unassigned)' : v; });
            var order = ['urgent', 'high', 'medium', 'normal', 'low'];
            var pList = Object.keys(prios).sort(function (x, y) {
                var ix = order.indexOf(x), iy = order.indexOf(y);
                return (ix === -1 ? 99 : ix) - (iy === -1 ? 99 : iy);
            });
            if (st.filters.priority && pList.indexOf(st.filters.priority) === -1) pList.push(st.filters.priority);
            fillSelect(q(st, '[data-tl-filter="priority"]'), pList, 'Any priority', words);
            syncToolbar(st);

            var scroller = q(st, '[data-tl-scroller]');
            var vp = U.viewport();
            var roomW = vp === 'phone' ? 92 : (vp === 'tablet' ? 168 : 208);
            var avail = (scroller.clientWidth || st.root.clientWidth || 1000) - roomW - 2;
            var dayW = Math.max(A.minColW || 44, Math.floor(avail / n));
            st.dayW = dayW;
            st.root.style.setProperty('--tl-day-w', dayW + 'px');
            st.root.style.setProperty('--tl-days', String(n));
            var compact = st.compact && !U.mqCoarse.matches;
            st.root.classList.toggle('is-compact', compact);
            st.rowH = compact ? 36 : 44;

            var grid = q(st, '[data-tl-grid]');
            grid.textContent = '';
            grid.setAttribute('aria-label', (st.cfg.label || 'Room timeline') + ', ' + rangeText(st, cols[0].d, n));
            grid.setAttribute('aria-colcount', String(n + 1));

            // Head: corner + columns.
            var head = el('div', 'tl-headblock');
            var hr = el('div', 'tl-row tl-head');
            hr.setAttribute('role', 'row');
            var corner = el('div', 'tl-corner');
            corner.setAttribute('role', 'columnheader');
            var allCollapsed = d.groups.length > 0 && d.groups.every(function (g) { return st.collapsed[g.id]; });
            var cbtn = el('button', 'tl-corner__toggle');
            cbtn.type = 'button';
            cbtn.setAttribute('data-tl-collapse-all', '1');
            cbtn.setAttribute('aria-expanded', allCollapsed ? 'false' : 'true');
            cbtn.setAttribute('aria-label', allCollapsed ? 'Expand all room types' : 'Collapse all room types');
            cbtn.appendChild(icon(allCollapsed ? 'fa-chevron-right' : 'fa-chevron-down'));
            corner.appendChild(cbtn);
            corner.appendChild(el('span', 'tl-corner__label', 'Rooms'));
            hr.appendChild(corner);
            var track = el('div', 'tl-track');
            track.setAttribute('role', 'presentation');
            cols.forEach(function (x) {
                var c = el('div', 'tl-head__day' + (x.we ? ' tl-head__day--weekend' : '') + (x.today ? ' tl-head__day--today' : ''));
                c.setAttribute('role', 'columnheader');
                c.setAttribute('aria-label', x.label || (x.wd + ' ' + fmt(x.d) + (x.today ? ', today' : '')));
                c.appendChild(el('span', 'tl-head__wd', x.wd));
                c.appendChild(el('span', 'tl-head__num', x.day));
                c.appendChild(el('span', 'tl-head__mon', x.mon));
                track.appendChild(c);
            });
            hr.appendChild(track);
            head.appendChild(hr);
            grid.appendChild(head);

            // Body.
            var todayIdx = dnum(d.today) - st.first;
            var nowLeft = A.nowX ? A.nowX(st, dayW) : ((todayIdx >= 0 && todayIdx < n) ? (todayIdx + 0.5) * dayW : null);
            st.multi = {};
            var anyRow = false;
            d.groups.forEach(function (g) {
                var lanes = g.lanes.map(function (id) { return st.lanesById[id]; }).filter(function (l) { return l && laneVisible(st, l); });
                if (!lanes.length) return;
                anyRow = true;
                var collapsed = !!st.collapsed[g.id];
                var gr = el('div', 'tl-row tl-group');
                gr.setAttribute('role', 'row');
                var gc = el('div', 'tl-group__cell');
                gc.setAttribute('role', 'rowheader');
                var tog = el('button', 'tl-group__toggle');
                tog.type = 'button';
                tog.setAttribute('data-tl-group', String(g.id));
                tog.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                tog.appendChild(icon(collapsed ? 'fa-chevron-right' : 'fa-chevron-down'));
                tog.appendChild(el('span', 'tl-group__name', g.name));
                gc.appendChild(tog);
                var cnt = lanes.reduce(function (s, l) { return s + (st.itemsByLane[l.id] || []).length; }, 0);
                if (cnt > 0) gc.appendChild(el('span', 'badge badge-neutral tl-group__badge', cnt + ' ' + A.noun(cnt)));
                gr.appendChild(gc);
                grid.appendChild(gr);
                if (collapsed) return;
                lanes.forEach(function (l) { grid.appendChild(laneRow(st, l, cols, nowLeft)); });
            });
            q(st, '[data-tl-empty]').hidden = anyRow;
            grid.setAttribute('aria-rowcount', String(grid.querySelectorAll('[role="row"]').length));

            applySearch(st, false);
            setupRoving(st);
            syncCards(st);
            var t = tableEl(st);
            if (st.filters.card || (t && t.rhOpsFilter)) applyList(st);
            if (st.flash) { announce(st, st.flash); st.flash = ''; }
            else if (!st.quiet) announce(st, 'Showing ' + rangeText(st, cols[0].d, n) + '.');
            st.quiet = false;
            if (st.scrollPending && A.scrollX && st.view === 'timeline') { // e.g. Room Service: bring "now" into view
                st.scrollPending = false;
                var sx = A.scrollX(st, dayW);
                if (sx !== null && sx !== undefined) scroller.scrollLeft = Math.max(0, sx - Math.floor((scroller.clientWidth - roomW) / 3));
            }
        }

        function laneRow(st, l, cols, nowLeft) {
            var d = st.data, n = cols.length, dayW = st.dayW;
            var row = el('div', 'tl-row tl-lane' + (st.hitLane === l.id ? ' tl-lane--hit' : ''));
            row.setAttribute('role', 'row');
            row.setAttribute('data-ops-lane', String(l.id));

            var rc = el('div', 'tl-room');
            rc.setAttribute('role', 'rowheader');
            var wrap = el('span', 'tl-room__link');
            var txt = el('span', 'tl-room__text');
            txt.appendChild(el('span', 'tl-room__num', l.number));
            if (l.name) txt.appendChild(el('span', 'tl-room__name', l.name));
            wrap.appendChild(txt);
            var stateTxt = st.A.roomState(st, l, wrap);
            wrap.setAttribute('aria-label', 'Room ' + l.number + (l.name ? ' ' + l.name : '') + (stateTxt ? ', ' + stateTxt : ''));
            wrap.title = wrap.getAttribute('aria-label');
            rc.appendChild(wrap);
            row.appendChild(rc);

            var track = el('div', 'tl-track');
            track.setAttribute('role', 'presentation');
            var cells = [];
            var stay = {};
            (l.stay || []).forEach(function (x) { stay[x] = true; });
            cols.forEach(function (x, i) {
                var c = el('div', 'tl-cell' + (x.we ? ' tl-cell--weekend' : '') + (x.today ? ' tl-cell--today' : '') + (x.d < d.today ? ' tl-cell--past' : '') + (stay[x.d] ? ' tl-cell--stay' : ''));
                c.setAttribute('role', 'gridcell');
                c.setAttribute('data-tl-day', String(i));
                if (stay[x.d]) c.title = 'Guest in house this night';
                track.appendChild(c);
                cells.push(c);
            });

            var specs = st.A.bars(st, l, st.itemsByLane[l.id] || []);
            var levels = pack(specs);
            row.style.setProperty('--tl-levels', String(levels));
            specs.forEach(function (sp) {
                var bar = sp.items.length > 1 ? chipEl(st, l, sp) : barEl(st, sp);
                bar.style.left = (sp.a * dayW + 2) + 'px';
                bar.style.width = ((sp.z - sp.a + 1) * dayW - 4) + 'px';
                bar.style.top = (sp.level * st.rowH + 4) + 'px';
                if ((sp.z - sp.a + 1) * dayW - 4 < 96) bar.classList.add('tl-bar--narrow');
                if ((sp.z - sp.a + 1) * dayW - 4 < 56) bar.classList.add('tl-bar--tiny');
                cells[Math.max(0, Math.min(n - 1, Math.floor(sp.a)))].appendChild(bar); // a / z may be fractions (hour columns)
            });

            if (nowLeft !== null) {
                var now = el('div', 'tl-now');
                now.style.left = nowLeft + 'px';
                now.setAttribute('aria-hidden', 'true');
                track.appendChild(now);
            }
            row.appendChild(track);
            return row;
        }

        // Stack overlapping bars in one row on sub-lanes (never overlap). A bar covers columns a to z inclusive, so it
        // ends at z + 1 (whole columns: the same as "last z < next a"; also right for the fractions of hour columns).
        function pack(specs) {
            var levels = [];
            specs.sort(function (x, y) { return x.a - y.a || y.z - x.z; });
            specs.forEach(function (sp) {
                for (var i = 0; i < levels.length; i++) {
                    if (levels[i] <= sp.a) { sp.level = i; levels[i] = sp.z + 1; return; }
                }
                sp.level = levels.length;
                levels.push(sp.z + 1);
            });
            return Math.max(1, levels.length);
        }

        function barEl(st, sp) {
            var it = sp.items[0];
            var v = st.A.bar(st, it);
            var bar = el('button', 'tl-bar ' + v.cls);
            bar.type = 'button';
            bar.tabIndex = -1;
            bar.setAttribute('data-ops-item', String(it.id));
            if (it.overdue) bar.classList.add('tl-bar--overdue');
            if (sp.cutS) bar.classList.add('tl-bar--cut-start');
            if (sp.cutE) bar.classList.add('tl-bar--cut-end');
            bar.setAttribute('aria-label', v.label + '.');
            bar.title = v.label;
            if (sp.cutS) bar.appendChild(icon('fa-angle-left tl-bar__cut'));
            var body = el('span', 'tl-bar__body');
            body.appendChild(el('span', 'tl-bar__name', v.name));
            var meta = el('span', 'tl-bar__meta');
            (v.meta || []).forEach(function (m) { meta.appendChild(typeof m === 'string' ? el('span', null, m) : m); });
            body.appendChild(meta);
            bar.appendChild(body);
            if (v.flags && v.flags.length) {
                var flags = el('span', 'tl-bar__flags');
                v.flags.forEach(function (f) { flags.appendChild(f); });
                bar.appendChild(flags);
            }
            if (sp.cutE) bar.appendChild(icon('fa-angle-right tl-bar__cut'));
            return bar;
        }

        function chipEl(st, lane, sp) {
            var key = lane.id + '|' + sp.a;
            st.multi[key] = sp.items;
            var noun = st.A.noun(sp.items.length);
            var bar = el('button', 'tl-bar tl-task tl-task--count');
            bar.type = 'button';
            bar.tabIndex = -1;
            bar.setAttribute('data-ops-multi', key);
            if (sp.items.some(function (x) { return x.overdue; })) bar.classList.add('tl-bar--overdue');
            var label = sp.items.length + ' ' + noun + ', room ' + lane.number + ', ' + fmt(st.cols[sp.a].d);
            bar.setAttribute('aria-label', label + '. Open the list.');
            bar.title = label;
            var body = el('span', 'tl-bar__body');
            body.appendChild(el('span', 'tl-bar__name', sp.items.length + ' ' + noun));
            bar.appendChild(body);
            if (sp.items.some(function (x) { return x.priority === 'urgent' || x.priority === 'high'; })) {
                var flags = el('span', 'tl-bar__flags');
                flags.appendChild(flag('urgent', 'fa-exclamation', 'High priority'));
                bar.appendChild(flags);
            }
            return bar;
        }

        /* ------------------------------------------------------------ search, keyboard */
        function applySearch(st, scroll) {
            var s = st.search.trim().toLowerCase();
            st.root.classList.toggle('is-searching', s !== '');
            var first = null;
            st.root.querySelectorAll('[data-tl-grid] .tl-bar').forEach(function (bar) {
                var list = bar.hasAttribute('data-ops-multi') ? (st.multi[bar.getAttribute('data-ops-multi')] || []) : [st.byId[bar.getAttribute('data-ops-item')]];
                var m = s !== '' && list.some(function (it) { return it && st.A.search(it).toLowerCase().indexOf(s) !== -1; });
                bar.classList.toggle('tl-bar--match', m);
                if (m && !first) first = bar;
            });
            if (scroll && first) {
                first.scrollIntoView({ block: 'nearest', inline: 'center', behavior: reduce() ? 'auto' : 'smooth' });
                first.focus({ preventScroll: true });
            }
            if (scroll && s !== '' && !first) announce(st, 'Nothing in this range matches "' + st.search.trim() + '".');
        }
        function rowsOfBars(st) {
            var rows = [];
            st.root.querySelectorAll('[data-tl-grid] .tl-lane').forEach(function (r) {
                var bars = Array.prototype.slice.call(r.querySelectorAll('.tl-bar'));
                bars.sort(function (a, b) { return parseFloat(a.style.left) - parseFloat(b.style.left); });
                if (bars.length) rows.push(bars);
            });
            return rows;
        }
        function setupRoving(st) {
            var rows = rowsOfBars(st);
            if (rows.length) rows[0][0].tabIndex = 0;
        }
        function moveFocus(st, bar, key) {
            var rows = rowsOfBars(st);
            var ri = -1, bi = -1;
            rows.forEach(function (r, i) { var j = r.indexOf(bar); if (j !== -1) { ri = i; bi = j; } });
            if (ri === -1) return;
            var target = null;
            if (key === 'ArrowLeft') target = rows[ri][bi - 1];
            else if (key === 'ArrowRight') target = rows[ri][bi + 1];
            else if (key === 'Home') target = rows[ri][0];
            else if (key === 'End') target = rows[ri][rows[ri].length - 1];
            else {
                var r2 = rows[ri + (key === 'ArrowUp' ? -1 : 1)];
                if (r2) {
                    var x = parseFloat(bar.style.left);
                    target = r2.reduce(function (best, c) {
                        return (!best || Math.abs(parseFloat(c.style.left) - x) < Math.abs(parseFloat(best.style.left) - x)) ? c : best;
                    }, null);
                }
            }
            if (!target) return;
            bar.tabIndex = -1;
            target.tabIndex = 0;
            target.focus();
            target.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }

        /* ------------------------------------------------------------ drawer / sheet */
        function asSheet() { return U.mqPhone.matches; }
        function openDrawer(st, title, buildBody, buildFoot, opener, keepFocus) {
            var dr = q(st, '[data-tl-drawer]');
            var bd = q(st, '[data-tl-backdrop]');
            closeMenus(st);
            if (!keepFocus || !st.lastFocus) st.lastFocus = opener || document.activeElement;
            q(st, '[data-tl-drawer-title]').textContent = title;
            var body = q(st, '[data-tl-drawer-body]');
            var foot = q(st, '[data-tl-drawer-foot]');
            body.textContent = '';
            foot.textContent = '';
            buildBody(body);
            buildFoot(foot);
            var sheet = asSheet();
            dr.classList.toggle('tl-drawer--sheet', sheet);
            dr.setAttribute('aria-modal', sheet ? 'true' : 'false');
            bd.hidden = !sheet;
            dr.hidden = false;
            window.requestAnimationFrame(function () { dr.classList.add('is-open'); });
            var close = q(st, '[data-tl-drawer-close]');
            if (close) close.focus();
        }
        function closeDrawer(st, restore) {
            if (!st) return;
            var dr = q(st, '[data-tl-drawer]');
            if (!dr || dr.hidden) return;
            closeMenus(st);
            dr.classList.remove('is-open');
            dr.hidden = true;
            q(st, '[data-tl-backdrop]').hidden = true;
            st.drawerItem = null;
            st.root.querySelectorAll('.tl-bar.is-selected').forEach(function (b) { b.classList.remove('is-selected'); });
            if (restore !== false && st.lastFocus && document.contains(st.lastFocus)) {
                try { st.lastFocus.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
            }
        }
        function closeMenus(st) {
            if (!st) return;
            st.root.querySelectorAll('.tl-more.open').forEach(function (w) {
                w.classList.remove('open');
                var m = w.querySelector('.actions-more-menu');
                if (m) m.style.cssText = '';
                var t = w.querySelector('[data-tl-more]');
                if (t) t.setAttribute('aria-expanded', 'false');
            });
        }

        function openItem(st, it, opener, keepFocus) {
            st.root.querySelectorAll('.tl-bar.is-selected').forEach(function (x) { x.classList.remove('is-selected'); });
            if (opener && opener.classList && opener.classList.contains('tl-bar')) opener.classList.add('is-selected');
            st.drawerItem = it;
            var lane = st.lanesById[it.room_id] || null;
            openDrawer(st, st.A.title(st, it, lane), function (body) {
                st.A.drawer(st, it, lane, body);
            }, function (foot) {
                var acts = st.A.actions(st, it);
                if (acts.primary) foot.appendChild(actButton(acts.primary, 'btn btn-primary'));
                if (st.data.can && st.data.can.calendar && Number(it.room_id) > 0) {
                    var a = el('a', 'btn btn-secondary');
                    a.href = calHref(st.A.day(it), it.room_id);
                    a.appendChild(icon('fa-calendar-days'));
                    a.appendChild(document.createTextNode(' ' + (st.A.calLabel || 'Open on calendar')));
                    foot.appendChild(a);
                }
                if (acts.more.length) {
                    var wrap = el('div', 'actions-more tl-more');
                    var tb = el('button', 'btn btn-secondary actions-more-toggle');
                    tb.type = 'button';
                    tb.setAttribute('aria-haspopup', 'true');
                    tb.setAttribute('aria-expanded', 'false');
                    tb.setAttribute('data-tl-more', '1');
                    tb.appendChild(icon('fa-ellipsis'));
                    tb.appendChild(document.createTextNode(' More'));
                    var menu = el('div', 'actions-more-menu');
                    menu.setAttribute('role', 'menu');
                    acts.more.forEach(function (m) {
                        var b = actButton(m, '');
                        b.setAttribute('role', 'menuitem');
                        menu.appendChild(b);
                    });
                    wrap.appendChild(tb);
                    wrap.appendChild(menu);
                    foot.appendChild(wrap);
                }
                if (!foot.childNodes.length) foot.appendChild(el('span', 'tl-muted', 'No actions available here.'));
            }, opener, keepFocus);
        }
        function actButton(act, cls) {
            var b = el('button', cls);
            b.type = 'button';
            b.setAttribute('data-ops-act', act.key);
            if (act.icon) b.appendChild(icon(act.icon));
            b.appendChild(document.createTextNode((act.icon ? ' ' : '') + act.label));
            return b;
        }
        function openMulti(st, key, opener) {
            var items = st.multi[key] || [];
            if (!items.length) return;
            var parts = key.split('|');
            var lane = st.lanesById[parts[0]];
            var day = st.cols[Number(parts[1])].d;
            openDrawer(st, 'Room ' + (lane ? lane.number : '') + ', ' + fmt(day) + ': ' + items.length + ' ' + st.A.noun(items.length), function (body) {
                var ul = el('ul', 'tl-ops-list');
                items.forEach(function (it) {
                    var li = el('li');
                    var b = el('button', 'tl-ops-list__item');
                    b.type = 'button';
                    b.setAttribute('data-ops-open', String(it.id));
                    var v = st.A.bar(st, it);
                    b.appendChild(el('span', 'tl-ops-list__name', v.name));
                    b.appendChild(statusBadge(it.status));
                    b.appendChild(el('span', 'tl-muted', it.assignee || 'Unassigned'));
                    b.setAttribute('aria-label', v.label);
                    li.appendChild(b);
                    ul.appendChild(li);
                });
                body.appendChild(ul);
            }, function (foot) {
                if (st.data.can && st.data.can.calendar && lane) {
                    var a = el('a', 'btn btn-secondary');
                    a.href = calHref(day, lane.id);
                    a.appendChild(icon('fa-calendar-days'));
                    a.appendChild(document.createTextNode(' Open on calendar'));
                    foot.appendChild(a);
                }
            }, opener);
        }

        /* The page's own form: same action / id / csrf_token fields and the same confirm the list buttons use. */
        // A post entry is [action, confirm message, button label, button class, id field (default "id")], or a
        // function(item) returning one (Room Service: the message names the order, the field is order_id).
        function postAction(st, key, it) {
            var p = st.A.post[key];
            if (typeof p === 'function') p = p(it);
            if (!p) return;
            var old = document.querySelector('form[data-ops-post]');
            if (old) old.parentNode.removeChild(old);
            var f = document.createElement('form');
            f.method = 'post';
            f.hidden = true;
            f.setAttribute('data-ops-post', '1');
            [['action', p[0]], [p[4] || 'id', String(it.id)], ['csrf_token', String(st.cfg.csrf || '')]].forEach(function (kv) {
                var i = document.createElement('input');
                i.type = 'hidden';
                i.name = kv[0];
                i.value = kv[1];
                f.appendChild(i);
            });
            document.body.appendChild(f);
            var fn = st.cfg.confirm ? window[st.cfg.confirm] : null;
            if (typeof fn === 'function') fn(f, p[1], p[2], p[3]);
            else if (window.confirm(p[1])) f.submit();
        }
        function runAct(st, key) {
            var it = st.drawerItem;
            if (!it) return;
            closeMenus(st);
            if (st.A.run && typeof st.A.run[key] === 'function') { // a mode's own non-POST action (Room Service: order history)
                closeDrawer(st, false);
                st.A.run[key](st, it);
                return;
            }
            if (key === 'edit') {
                var ed = st.cfg.edit ? window[st.cfg.edit] : null;
                if (typeof ed !== 'function') return;
                closeDrawer(st, false);
                ed(it.edit);
                return;
            }
            if (key === 'history') {
                var hf = window.viewAuditLog;
                if (typeof hf !== 'function') return;
                closeDrawer(st, false);
                hf(it.id, st.A.historyLabel(it));
                return;
            }
            postAction(st, key, it);
        }

        /* ------------------------------------------------------------ cards and the list */
        function cardNodes() { return document.querySelectorAll('[data-ops-card]'); }
        function syncCards(st) {
            var counts = st.data.counts || {};
            cardNodes().forEach(function (c) {
                var key = c.getAttribute('data-ops-card');
                var on = st.filters.card === key;
                c.classList.toggle('is-active', on);
                c.setAttribute('aria-pressed', on ? 'true' : 'false');
                var ck = c.getAttribute('data-ops-count');
                var v = c.querySelector('.ck-kpi__value');
                if (ck && v && counts[ck] !== undefined) v.textContent = String(counts[ck]);
                var sub = c.querySelector('.ck-kpi__sub');
                if (sub) sub.textContent = on ? 'Showing these. Click again to show all.' : (c.getAttribute('data-ops-sub') || 'Show these');
            });
        }
        function applyList(st) {
            var t = tableEl(st);
            if (!t) return;
            t.rhOpsFilter = st.filters.card ? st.A.listFilter(st, st.filters.card) : null;
            var fn = st.cfg.filter ? window[st.cfg.filter] : null;
            if (typeof fn === 'function') fn(true);
        }
        function toggleCard(st, key) {
            st.filters.card = st.filters.card === key ? '' : key;
            render(st);
            applyList(st);
            announce(st, st.filters.card ? 'Showing ' + st.A.cardWords(st.filters.card) + '.' : 'Showing all rooms.');
            scrollTo(st.view === 'list' ? listEl(st) : q(st, '[data-tl-scroller]'));
        }

        /* ------------------------------------------------------------ view (Timeline | List) */
        function showView(st) {
            var list = st.view === 'list';
            st.root.classList.toggle('is-list', list);
            var l = listEl(st);
            if (l) l.hidden = !list;
            q(st, '[data-tl-scroller]').hidden = list;
            if (list) q(st, '[data-tl-empty]').hidden = true;
            st.root.querySelectorAll('[data-ops-view]').forEach(function (b) {
                var on = b.getAttribute('data-ops-view') === st.view;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            if (list && window.AdminAutoSectionPagination) window.AdminAutoSectionPagination.init();
        }

        /* ------------------------------------------------------------ deep links */
        function deepLink(st, ini) {
            var it = ini.id ? st.byId[ini.id] : null;
            var lane = null;
            if (ini.room_id) lane = st.lanesById[ini.room_id] || null;
            else if (ini.room) lane = st.data.lanes.filter(function (l) { return l.number === String(ini.room); })[0] || null;
            if (!lane && it) lane = st.lanesById[it.room_id] || null;
            if (lane) {
                st.hitLane = lane.id;
                if (st.collapsed[lane.type_id]) delete st.collapsed[lane.type_id];
                render(st);
                if (st.view === 'timeline') {
                    var row = st.root.querySelector('[data-ops-lane="' + lane.id + '"]');
                    if (row) row.scrollIntoView({ block: 'center', inline: 'nearest' });
                }
            }
            if (ini.id) {
                var t = tableEl(st);
                var tr = t ? t.querySelector('tr[data-ops-id="' + Number(ini.id) + '"]') : null;
                if (tr) {
                    tr.classList.add('ops-row--hit');
                    if (st.view === 'list') tr.scrollIntoView({ block: 'center' });
                }
                if (it) {
                    var bar = st.root.querySelector('[data-ops-item="' + it.id + '"]');
                    if (bar && st.view === 'timeline') bar.scrollIntoView({ block: 'nearest', inline: 'center' });
                    openItem(st, it, bar);
                } else {
                    announce(st, 'That entry is not in this date range.');
                }
                try {
                    var u = new URL(window.location.href);
                    u.searchParams.delete('id');
                    window.history.replaceState(window.history.state, '', u.pathname + u.search + u.hash);
                } catch (e) { /* ignore */ }
            }
        }

        /* ------------------------------------------------------------ toolbar */
        function syncToolbar(st) {
            var allowed = U.allowedDays();
            st.root.querySelectorAll('[data-tl-days]').forEach(function (b) {
                var n = Number(b.getAttribute('data-tl-days'));
                var on = n === st.days;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
                b.hidden = allowed.indexOf(n) === -1;
            });
            var dateIn = q(st, '[data-tl-date]');
            if (dateIn) dateIn.value = st.start;
            var word = st.days === 1 ? 'day' : st.days + ' days';
            var prev = q(st, '[data-tl-prev]'), next = q(st, '[data-tl-next]');
            if (prev) prev.setAttribute('aria-label', 'Previous ' + word);
            if (next) next.setAttribute('aria-label', 'Next ' + word);
            var allBtn = q(st, '[data-ops-allrooms]');
            if (allBtn) allBtn.setAttribute('aria-pressed', st.allRooms ? 'true' : 'false');
            var k = filterCount(st);
            var clear = q(st, '[data-tl-clear]');
            if (clear) clear.hidden = k === 0;
            var cnt = q(st, '[data-tl-filter-count]');
            if (cnt) { cnt.hidden = k === 0; cnt.textContent = String(k); }
            var dens = q(st, '[data-tl-density]');
            if (dens) {
                dens.setAttribute('aria-pressed', st.compact ? 'true' : 'false');
                dens.setAttribute('aria-label', st.compact ? 'Comfortable rows' : 'Compact rows');
                dens.title = dens.getAttribute('aria-label');
            }
        }
        function toggleFilters(st, open) {
            var box = q(st, '[data-tl-filters]');
            var btn = q(st, '[data-tl-filters-open]');
            st.root.classList.toggle('is-filters-open', open);
            if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            q(st, '[data-tl-backdrop]').hidden = !open && q(st, '[data-tl-drawer]').hidden;
            if (open) {
                var first = box.querySelector('select, button');
                if (first) first.focus();
            } else if (btn) {
                btn.focus();
            }
        }

        /* ------------------------------------------------------------ events */
        function bindRoot(st) {
            var root = st.root;
            root.addEventListener('click', function (e) {
                var t = e.target, x;
                if ((x = t.closest('[data-ops-act]'))) { e.preventDefault(); runAct(st, x.getAttribute('data-ops-act')); return; }
                if ((x = t.closest('[data-ops-open]'))) {
                    var oi = st.byId[x.getAttribute('data-ops-open')];
                    if (oi) openItem(st, oi, x, true);
                    return;
                }
                if ((x = t.closest('[data-ops-view]'))) {
                    st.view = x.getAttribute('data-ops-view') === 'list' ? 'list' : 'timeline';
                    store('rh_ops_view_' + st.page, st.view);
                    showView(st);
                    if (st.view === 'timeline') render(st);
                    return;
                }
                if ((x = t.closest('[data-ops-allrooms]'))) {
                    st.allRooms = !st.allRooms;
                    render(st);
                    announce(st, st.allRooms ? 'Showing every room.' : 'Showing only rooms with an order.');
                    return;
                }
                if ((x = t.closest('[data-tl-today]'))) { go(st, st.data.today, st.days); return; }
                if ((x = t.closest('[data-tl-prev]'))) { go(st, addDays(st.start, -st.days), st.days); return; }
                if ((x = t.closest('[data-tl-next]'))) { go(st, addDays(st.start, st.days), st.days); return; }
                if ((x = t.closest('[data-tl-days]'))) { go(st, st.start, Number(x.getAttribute('data-tl-days'))); return; }
                if ((x = t.closest('[data-tl-density]'))) { st.compact = !st.compact; store(U.STORE_DENSITY, st.compact); render(st); return; }
                if ((x = t.closest('[data-tl-clear]'))) {
                    st.filters.type = st.filters.floor = st.filters.status = st.filters.priority = st.filters.assignee = '';
                    root.querySelectorAll('[data-tl-filter]').forEach(function (s) { s.value = ''; });
                    render(st);
                    return;
                }
                if ((x = t.closest('[data-tl-legend]'))) {
                    var lg = q(st, '[data-tl-legend-box]');
                    if (lg) {
                        lg.hidden = !lg.hidden;
                        x.setAttribute('aria-expanded', lg.hidden ? 'false' : 'true');
                    }
                    return;
                }
                if ((x = t.closest('[data-tl-filters-open]'))) { toggleFilters(st, true); return; }
                if ((x = t.closest('[data-tl-filters-close]'))) { toggleFilters(st, false); return; }
                if ((x = t.closest('[data-tl-collapse-all]'))) {
                    var expand = x.getAttribute('aria-expanded') === 'false';
                    st.collapsed = {};
                    if (!expand) st.data.groups.forEach(function (g) { st.collapsed[g.id] = true; });
                    store(U.STORE_COLLAPSE, st.collapsed);
                    render(st);
                    return;
                }
                if ((x = t.closest('[data-tl-group]'))) {
                    var gid = x.getAttribute('data-tl-group');
                    if (st.collapsed[gid]) delete st.collapsed[gid]; else st.collapsed[gid] = true;
                    store(U.STORE_COLLAPSE, st.collapsed);
                    render(st);
                    var again = root.querySelector('[data-tl-group="' + gid + '"]');
                    if (again) again.focus();
                    return;
                }
                if ((x = t.closest('[data-tl-more]'))) {
                    var wrap = x.closest('.tl-more');
                    var open = !wrap.classList.contains('open');
                    closeMenus(st);
                    if (open) {
                        wrap.classList.add('open');
                        x.setAttribute('aria-expanded', 'true');
                        var menu = wrap.querySelector('.actions-more-menu');
                        if (window.rhPlaceActionMenu) window.rhPlaceActionMenu(x, menu);
                        var firstItem = menu.querySelector('a, button');
                        if (firstItem) firstItem.focus();
                    }
                    return;
                }
                if (t.closest('[data-tl-drawer-close]') || t.closest('[data-tl-backdrop]')) {
                    if (root.classList.contains('is-filters-open')) toggleFilters(st, false);
                    else closeDrawer(st);
                    return;
                }
                if ((x = t.closest('[data-ops-multi]'))) { openMulti(st, x.getAttribute('data-ops-multi'), x); return; }
                if ((x = t.closest('[data-ops-item]'))) {
                    var it = st.byId[x.getAttribute('data-ops-item')];
                    if (it) openItem(st, it, x);
                    return;
                }
                if (!t.closest('.tl-more')) closeMenus(st);
            });

            root.addEventListener('change', function (e) {
                var t = e.target;
                if (t.matches('[data-tl-filter]')) {
                    st.filters[t.getAttribute('data-tl-filter')] = t.value;
                    render(st);
                } else if (t.matches('[data-tl-date]')) {
                    if (/^\d{4}-\d{2}-\d{2}$/.test(t.value)) go(st, t.value, st.days);
                }
            });

            var searchIn = q(st, '[data-tl-search]');
            if (searchIn) {
                searchIn.addEventListener('input', function () { st.search = searchIn.value; applySearch(st, false); });
                searchIn.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); st.search = searchIn.value; applySearch(st, true); }
                });
            }

            q(st, '[data-tl-grid]').addEventListener('keydown', function (e) {
                var bar = e.target.closest && e.target.closest('.tl-bar');
                if (!bar) return;
                if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].indexOf(e.key) !== -1) {
                    e.preventDefault();
                    moveFocus(st, bar, e.key);
                }
            });
        }

        function bindDoc() {
            if (docBound) return;
            docBound = true;
            document.addEventListener('keydown', function (e) {
                var st = live();
                if (!st || e.key !== 'Escape') return;
                if (st.root.querySelector('.tl-more.open')) { closeMenus(st); return; }
                if (st.root.classList.contains('is-filters-open')) { toggleFilters(st, false); return; }
                var lg = q(st, '[data-tl-legend-box]');
                if (lg && !lg.hidden) { lg.hidden = true; return; }
                closeDrawer(st);
            });
            document.addEventListener('click', function (e) {
                var st = live();
                if (!st) return;
                var card = e.target.closest('[data-ops-card]');
                if (card) { e.preventDefault(); toggleCard(st, card.getAttribute('data-ops-card')); return; }
                if (!e.target.closest('.tl-more')) closeMenus(st);
                if (!e.target.closest('.tl-legend-wrap')) {
                    var lg = q(st, '[data-tl-legend-box]');
                    if (lg && !lg.hidden) {
                        lg.hidden = true;
                        var b = q(st, '[data-tl-legend]');
                        if (b) b.setAttribute('aria-expanded', 'false');
                    }
                }
            }, true);
            var timer = null;
            window.addEventListener('resize', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    var st = live();
                    if (!st) return;
                    if (!st.A.fixedDays && U.allowedDays().indexOf(st.days) === -1) go(st, st.start, U.defaultDays());
                    else if (st.view === 'timeline') render(st);
                }, 150);
            });
        }

        /* ------------------------------------------------------------ the two modes */
        function prioFlag(it) {
            return (it.priority === 'urgent' || it.priority === 'high') ? [flag('urgent', 'fa-exclamation', words(it.priority) + ' priority')] : [];
        }
        function stdActions(it, verifyKey) {
            var primary = null, more = [];
            if (it.status === 'pending') {
                primary = { key: 'start', label: 'Start', icon: 'fa-play' };
                more.push({ key: 'done', label: 'Mark done', icon: 'fa-check' });
            } else if (it.status === 'in_progress') {
                primary = { key: 'done', label: 'Mark done', icon: 'fa-check' };
            } else if (it.status === 'completed') {
                primary = { key: verifyKey, label: 'Verify', icon: 'fa-check-double' };
            }
            if (it.status !== 'verified') more.push({ key: 'edit', label: 'Edit or reassign', icon: 'fa-pen' });
            more.push({ key: 'history', label: 'History', icon: 'fa-clock-rotate-left' });
            more.push({ key: 'delete', label: 'Delete', icon: 'fa-trash' });
            return { primary: primary, more: more };
        }
        function roomLine(lane, it) {
            return lane ? ('Room ' + lane.number + (lane.name ? ' ' + lane.name : '')) : ('Room ' + it.room);
        }

        var HK_STATE_ICON = { clean: 'fa-check', dirty: 'fa-exclamation', cleaning: 'fa-ellipsis', inspection: 'fa-eye', blocked: 'fa-ban' };

        RT.modes.housekeeping = {
            noun: function (n) { return n === 1 ? 'task' : 'tasks'; },
            columns: dayColumns,
            rows: 'room',
            day: function (it) { return it.day; },
            span: function (st, it) {
                var i = dnum(it.day) - st.first;
                return (i < 0 || i >= st.cols.length) ? null : [i, i, false, false];
            },
            bars: function (st, lane, items) {
                var byDay = {}, out = [];
                items.forEach(function (it) {
                    var i = dnum(it.day) - st.first;
                    (byDay[i] = byDay[i] || []).push(it);
                });
                Object.keys(byDay).forEach(function (k) {
                    var i = Number(k);
                    out.push({ items: byDay[k], a: i, z: i, cutS: false, cutE: false });
                });
                return out;
            },
            bar: function (st, it) {
                var cls = 'tl-task tl-task--' + ({ pending: 'pending', in_progress: 'progress', completed: 'done', verified: 'verified', blocked: 'blocked' }[it.status] || 'pending');
                var flags = prioFlag(it);
                if (it.status === 'verified') flags.push(flag('state', 'fa-check', 'Verified'));
                if (it.status === 'blocked') flags.push(flag('state', 'fa-ban', 'Blocked'));
                var who = it.assignee || 'Unassigned';
                var label = it.type_label + ', room ' + it.room + ', ' + words(it.status) + ', ' + who
                    + (it.due ? ', due ' + fmt(it.due) : ', no date') + (it.time ? ' at ' + it.time : '')
                    + (it.overdue ? ' (overdue)' : '') + (it.priority ? ', ' + it.priority + ' priority' : '');
                return { cls: cls, name: it.type_label, meta: [who + (it.time ? ' ' + it.time : '')], flags: flags, label: label };
            },
            roomState: function (st, l, wrap) {
                var hk = el('span', 'tl-hk tl-hk--' + l.hk.state);
                hk.title = l.hk.label;
                hk.appendChild(icon(HK_STATE_ICON[l.hk.state] || 'fa-check'));
                wrap.appendChild(hk);
                return l.hk.label.toLowerCase();
            },
            title: function (st, it, lane) { return roomLine(lane, it) + ': ' + it.type_label; },
            drawer: function (st, it, lane, body) {
                var top = el('div', 'tl-drawer__status');
                top.appendChild(statusBadge(it.status));
                if (it.overdue) top.appendChild(el('span', 'badge badge-danger', 'Overdue'));
                if (lane) {
                    var hk = el('span', 'badge badge-neutral', 'Room: ' + lane.hk.label);
                    top.appendChild(hk);
                }
                body.appendChild(top);
                var list = el('dl', 'tl-dl');
                dl(list, 'Room', roomLine(lane, it) + (lane && st.data.groups ? groupName(st, lane) : ''));
                dl(list, 'Task', it.type_label);
                if (it.priority) dl(list, 'Priority', words(it.priority));
                dl(list, 'Due', (it.due ? fmt(it.due) : 'No date') + (it.time ? ' at ' + it.time : '') + (it.carried ? ' (still open, shown on today)' : ''));
                dl(list, 'Assigned to', it.assignee || 'Nobody yet');
                if (it.started) dl(list, 'Started', it.started);
                if (it.completed) dl(list, 'Finished', it.completed);
                if (it.notes) dl(list, 'Notes', it.notes);
                if (it.booking) {
                    var bk = it.booking;
                    var txt = bk.guest + (bk.ref ? ' (' + bk.ref + ')' : '') + ', ' + fmtRange(bk.ci, bk.co) + ', checks out ' + fmt(bk.co);
                    if (st.data.can && st.data.can.calendar) {
                        var a = el('a', null, txt);
                        a.href = calHref(bk.ci, it.room_id);
                        dl(list, 'Booking', '', a);
                    } else {
                        dl(list, 'Booking', txt);
                    }
                }
                body.appendChild(list);
                if (lane && lane.stay && lane.stay.indexOf(it.day) !== -1) body.appendChild(el('p', 'tl-drawer__note', 'A guest is in this room that night.'));
            },
            actions: function (st, it) { return stdActions(it, 'verify'); },
            post: {
                start: ['mark_started', 'Mark this assignment as started?', 'Start', 'btn-warning'],
                done: ['mark_complete', 'Mark this assignment as completed?', 'Mark Done', 'btn-success'],
                verify: ['verify_assignment', 'Mark this assignment as verified? This cannot be undone.', 'Verify', 'btn-success'],
                'delete': ['delete_assignment', 'Delete this assignment? This cannot be undone.', 'Delete', 'btn-danger']
            },
            historyLabel: function (it) { return it.room; },
            search: function (it) {
                return [it.room, it.type_label, it.assignee, it.notes, it.booking ? it.booking.guest : '', it.booking ? it.booking.ref : ''].join(' ');
            },
            statusMatch: function (st, it, s) {
                if (s === 'due') return ['pending', 'in_progress', 'blocked'].indexOf(it.status) !== -1 && (it.due === '' || it.due <= st.data.today) && it.status !== 'blocked';
                return it.status === s;
            },
            cardOnItems: function () { return false; },
            cardLane: function (st, key, lane) { return key === 'unassigned' ? !!lane.unassigned : lane.hk.state === key; },
            cardWords: function (key) {
                return { dirty: 'dirty rooms', cleaning: 'rooms being cleaned', inspection: 'rooms waiting for inspection', clean: 'clean rooms', unassigned: 'rooms whose task has nobody assigned' }[key] || 'these rooms';
            },
            listFilter: function (st, key) {
                var rooms = {};
                st.data.lanes.forEach(function (l) { if (st.A.cardLane(st, key, l)) rooms[l.id] = true; });
                return { rooms: rooms };
            }
        };

        RT.modes.maintenance = {
            noun: function (n) { return n === 1 ? 'job' : 'jobs'; },
            columns: dayColumns,
            rows: 'room',
            day: function (it) { return it.from; },
            span: function (st, it) {
                var n = st.cols.length;
                var a = dnum(it.from) - st.first, z = dnum(it.draw_to) - st.first;
                if (z < 0 || a > n - 1) return null;
                return [Math.max(0, a), Math.min(n - 1, z), a < 0, z > n - 1 || !!it.open_end];
            },
            bars: function (st, lane, items) {
                return items.map(function (it) {
                    var s = st.A.span(st, it);
                    return { items: [it], a: s[0], z: s[1], cutS: s[2], cutE: s[3] };
                });
            },
            bar: function (st, it) {
                var done = it.status === 'completed' || it.status === 'verified';
                var cls = 'tl-job tl-job--' + (done ? 'done' : (it.status === 'in_progress' ? 'progress' : 'open')) + (it.blocks ? ' tl-job--blocks' : '');
                var meta = [];
                var ic = icon(it.blocks ? 'fa-ban' : 'fa-screwdriver-wrench');
                meta.push(ic);
                meta.push(it.blocks ? 'Blocks room' : (it.assignee || 'Unassigned'));
                var name = it.title || it.type_label || 'Maintenance';
                var label = name + ', room ' + it.room + ', ' + words(it.status) + ', ' + fmtRange(it.from, it.to)
                    + (it.open_end ? ', no end date' : '') + (it.blocks ? ', blocks the room' : ', guests can still stay')
                    + (it.overdue ? ' (past its end date)' : '') + (it.priority ? ', ' + it.priority + ' priority' : '')
                    + ', ' + (it.assignee || 'unassigned');
                return { cls: cls, name: name, meta: meta, flags: prioFlag(it), label: label };
            },
            roomState: function (st, l, wrap) {
                if (!l.out) return '';
                var s = el('span', 'tl-hk tl-hk--blocked');
                s.title = 'Out of service now';
                s.appendChild(icon('fa-ban'));
                wrap.appendChild(s);
                return 'out of service now';
            },
            title: function (st, it, lane) { return roomLine(lane, it) + ': ' + (it.title || it.type_label || 'Maintenance'); },
            drawer: function (st, it, lane, body) {
                var top = el('div', 'tl-drawer__status');
                top.appendChild(statusBadge(it.status));
                if (it.overdue) top.appendChild(el('span', 'badge badge-danger', 'Past its end date'));
                top.appendChild(el('span', 'badge ' + (it.blocks ? 'badge-blocked' : 'badge-neutral'), it.blocks ? 'Blocks room' : 'Room stays on sale'));
                body.appendChild(top);
                var list = el('dl', 'tl-dl');
                dl(list, 'Room', roomLine(lane, it) + (lane ? groupName(st, lane) : ''));
                dl(list, 'Issue', it.title || '–');
                if (it.type_label) dl(list, 'Category', it.type_label);
                if (it.priority) dl(list, 'Priority', words(it.priority));
                dl(list, 'Dates', fmt(it.from) + (it.open_end ? ', no end date yet' : ' to ' + fmt(it.to)));
                dl(list, 'Blocks room', it.blocks ? 'Yes: the room cannot be sold on these dates' : 'No: guests can still stay');
                if (it.reporter) dl(list, 'Reported by', it.reporter);
                dl(list, 'Assigned to', it.assignee || 'Nobody yet');
                if (it.started) dl(list, 'Started', it.started);
                if (it.completed) dl(list, 'Finished', fmt(it.completed));
                if (it.notes) dl(list, 'Notes', it.notes);
                var aff = it.affects > 0 ? it.affects + (it.affects === 1 ? ' stay overlaps' : ' stays overlap') + ' these dates' : 'No stays on these dates';
                if (it.affects > 0 && st.data.can && st.data.can.calendar) {
                    var a = el('a', null, aff);
                    a.href = calHref(it.first_stay || it.from, it.room_id);
                    dl(list, 'Affects bookings', '', a);
                } else {
                    dl(list, 'Affects bookings', aff);
                }
                body.appendChild(list);
            },
            actions: function (st, it) { return stdActions(it, 'verify'); },
            post: {
                start: ['mark_started', 'Mark this maintenance as started?', 'Start', 'btn-warning'],
                done: ['mark_complete', 'Mark this maintenance as completed? The room stays in Maintenance until the task is verified.', 'Mark Done', 'btn-success'],
                verify: ['verify_schedule', 'Mark this maintenance as verified? The room will be released from Maintenance and this cannot be undone.', 'Verify', 'btn-success'],
                'delete': ['delete_schedule', 'Delete this schedule? This cannot be undone.', 'Delete', 'btn-danger']
            },
            historyLabel: function (it) { return it.title || it.room; },
            search: function (it) { return [it.room, it.title, it.type_label, it.assignee, it.reporter, it.notes].join(' '); },
            statusMatch: function (st, it, s) {
                if (s === 'done') return it.status === 'completed' || it.status === 'verified';
                if (s === 'blocks') return !!it.blocks && it.status !== 'completed' && it.status !== 'verified';
                return it.status === s;
            },
            cardOnItems: function (key) { return key === 'open' || key === 'progress' || key === 'due'; },
            cardLane: function (st, key, lane) { return key === 'out' ? !!lane.out : true; },
            cardWords: function (key) {
                return { open: 'open jobs', progress: 'jobs in progress', due: 'jobs due this week', out: 'rooms out of order now' }[key] || 'these jobs';
            },
            listFilter: function (st, key) {
                if (key !== 'out') return { card: key };
                var rooms = {};
                st.data.lanes.forEach(function (l) { if (l.out) rooms[l.id] = true; });
                return { rooms: rooms };
            }
        };

        /* Room Service (admin/room-service-dashboard.php): one day, 24 hour columns, a bar = one order from placed to
           delivered (or to now while open). Only rooms with an order show unless "Show all rooms" is on. The one
           change is the page's own Delivered form (mark_delivered + order_id + csrf_token, the same native confirm
           text as the Active queue button); history opens the page's lifecycle modal. Data: rh_ops_rs_payload(). */
        var RS_WORD = { 'new': 'New', preparing: 'Preparing', ready: 'Ready', delivered: 'Delivered', cancelled: 'Cancelled' };
        var RS_BADGE = { 'new': 'badge-pending', preparing: 'badge-info', ready: 'badge-success', delivered: 'badge-completed', cancelled: 'badge-cancelled' };
        var WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        function pad2(n) { return (n < 10 ? '0' : '') + n; }
        function dayLabel(d) { return WEEKDAYS[new Date(dnum(d) * 86400000).getUTCDay()] + ' ' + fmt(d); }
        function rsLive(st) { return st.data.now !== null && st.data.now !== undefined && st.start === st.data.today; }
        function rsRoom(lane, it) {
            if (lane && Number(lane.id) > 0) return roomLine(lane, it);
            return it.room ? 'Room ' + it.room : 'No room linked';
        }
        function rsTimes(it) {
            return it.placed + (it.delivered ? ' to ' + it.delivered : (it.open ? ', open' : ''));
        }

        RT.modes.service = {
            fixedDays: 1,
            minColW: 56,
            calLabel: 'Room on calendar',
            noun: function (n) { return n === 1 ? 'order' : 'orders'; },
            rangeLabel: dayLabel,
            columns: function (st) {
                var nowH = rsLive(st) ? Math.floor(st.data.now / 60) : -1;
                var out = [];
                for (var h = 0; h < 24; h++) {
                    out.push({ d: st.start, wd: '', day: pad2(h), mon: '', we: false, today: h === nowH,
                        label: pad2(h) + ':00 to ' + pad2(h) + ':59' + (h === nowH ? ', now' : '') });
                }
                return out;
            },
            nowX: function (st, colW) { return rsLive(st) ? (st.data.now / 60) * colW : null; },
            scrollX: function (st, colW) {
                if (rsLive(st)) return (st.data.now / 60) * colW;
                var first = null;
                st.data.items.forEach(function (it) {
                    if (it.day === st.start && it.state !== 'cancelled' && (first === null || it.from < first)) first = it.from;
                });
                return (first === null ? 7 * 60 : first) / 60 * colW;
            },
            rows: 'room',
            day: function (it) { return it.day; },
            span: function (st, it) {
                if (it.day !== st.start) return null;
                return [Math.min(23, Math.floor(it.from / 60)), Math.min(23, Math.floor(Math.max(it.from, it.to - 1) / 60)), !!it.cut_s, !!it.cut_e];
            },
            bars: function (st, lane, items) {
                var minW = 52 / st.dayW; // a short order still gets a bar you can tap (in hours)
                return items.map(function (it) {
                    var a = it.from / 60;
                    var w = Math.max((it.to - it.from) / 60, minW);
                    if (a + w > 24) a = Math.max(0, 24 - w);
                    return { items: [it], a: a, z: a + w - 1, cutS: !!it.cut_s, cutE: !!it.cut_e };
                });
            },
            bar: function (st, it) {
                var name = it.ref || ('Order ' + it.id);
                var label = 'Order ' + name + ', ' + (it.room ? 'room ' + it.room : 'no room linked') + ', ' + (RS_WORD[it.state] || words(it.state))
                    + ', ' + it.count + ', placed ' + it.placed + (it.delivered ? ', delivered ' + it.delivered : '')
                    + (it.overdue ? ' (late)' : '') + (it.guest ? ', ' + it.guest : '');
                return { cls: 'tl-order tl-order--' + it.state, name: name, meta: [it.count, rsTimes(it)], flags: [], label: label };
            },
            roomState: function () { return ''; },
            title: function (st, it, lane) { return rsRoom(lane, it) + ': order ' + (it.ref || it.id); },
            drawer: function (st, it, lane, body) {
                var top = el('div', 'tl-drawer__status');
                top.appendChild(el('span', 'badge ' + (RS_BADGE[it.state] || 'badge-neutral'), RS_WORD[it.state] || words(it.state)));
                if (it.overdue) top.appendChild(el('span', 'badge badge-danger', 'Late: open over ' + (st.data.late_minutes || 10) + ' min'));
                body.appendChild(top);
                var list = el('dl', 'tl-dl');
                dl(list, 'Room', rsRoom(lane, it) + (lane && Number(lane.id) > 0 ? groupName(st, lane) : ''));
                dl(list, 'Guest', it.guest || 'Not recorded');
                dl(list, 'Order no.', it.ref || String(it.id));
                dl(list, 'Placed', (it.placed_day && it.placed_day !== it.day ? fmt(it.placed_day) + ' at ' : '') + it.placed);
                dl(list, 'Ready', it.ready || (it.open ? 'Not yet' : '-'));
                dl(list, 'Delivered', it.delivered || (it.state === 'cancelled' ? 'Cancelled' : 'Not yet'));
                if (it.lines && it.lines.length) {
                    var ul = el('ul', 'tl-ops-items');
                    it.lines.forEach(function (ln) {
                        var li = el('li', null, ln.qty + ' x ' + ln.name);
                        if (ln.notes) li.appendChild(el('span', 'tl-ops-items__note', ln.notes));
                        ul.appendChild(li);
                    });
                    dl(list, 'Items (' + it.count + ')', '', ul);
                } else {
                    dl(list, 'Items', 'None');
                }
                if (it.notes) dl(list, 'Notes / allergies', it.notes);
                dl(list, 'Total', it.total);
                dl(list, 'On the room bill', it.charged ? 'Yes' : 'Not yet');
                body.appendChild(list);
            },
            actions: function (st, it) {
                var primary = (st.data.can && st.data.can.manage && it.open) ? { key: 'delivered', label: 'Delivered', icon: 'fa-check' } : null;
                var more = typeof window.rhOpenLifecycle === 'function' ? [{ key: 'history', label: 'Order history', icon: 'fa-clock-rotate-left' }] : [];
                return { primary: primary, more: more };
            },
            post: {
                delivered: function (it) { return ['mark_delivered', 'Mark order ' + it.ref + ' as delivered?', 'Delivered', 'btn-success', 'order_id']; }
            },
            run: {
                history: function (st, it) { if (typeof window.rhOpenLifecycle === 'function') window.rhOpenLifecycle(it.id); }
            },
            historyLabel: function (it) { return it.ref; },
            search: function (it) {
                return [it.room, it.guest, it.ref, it.notes].concat((it.lines || []).map(function (ln) { return ln.name; })).join(' ');
            },
            statusMatch: function (st, it, s) { return it.state === s; },
            hideByDefault: function (it) { return it.state === 'cancelled'; },
            laneHidden: function (st, lane) {
                return !st.allRooms && !(st.hitLane && lane.id === st.hitLane) && !(st.itemsByLane[lane.id] || []).length;
            },
            cardOnItems: function () { return true; },
            cardLane: function () { return true; },
            cardWords: function (key) {
                return { 'new': 'new orders', preparing: 'orders being prepared', ready: 'orders ready to deliver', delivered: 'orders delivered today' }[key] || 'these orders';
            },
            listFilter: function (st, key) { return { card: key }; },
            // What changed since the last refresh, for the status line (screen readers); no sound, no flashing.
            poll: function (before, after) {
                var was = {};
                (before.items || []).forEach(function (it) { was[it.id] = it; });
                var msgs = [];
                (after.items || []).forEach(function (it) {
                    var o = was[it.id];
                    var where = it.room ? ', room ' + it.room : '';
                    if (!o && it.open) msgs.push('New order ' + (it.ref || it.id) + where);
                    else if (o && o.state !== it.state && it.state === 'ready') msgs.push('Order ' + (it.ref || it.id) + ' is ready' + where);
                });
                if (msgs.length > 3) return msgs.length + ' order updates.';
                return msgs.length ? msgs.join('. ') + '.' : '';
            }
        };

        function groupName(st, lane) {
            var g = st.data.groups.filter(function (x) { return x.id === lane.type_id; })[0];
            return g && g.name ? ', ' + g.name : '';
        }

        /* ------------------------------------------------------------ public */
        RT.opsEngine = engine;
        // The page's list filter asks this for each row (data-ops-room / data-ops-cards) while a figure card is on.
        RT.opsRowMatch = function (table, row) {
            var f = table && table.rhOpsFilter;
            if (!f) return true;
            if (f.rooms) return !!f.rooms[row.getAttribute('data-ops-room')];
            if (f.card) return (' ' + (row.getAttribute('data-ops-cards') || '') + ' ').indexOf(' ' + f.card + ' ') !== -1;
            return true;
        };
        // After the page saved something without a reload (edit modal): fetch the shown range again.
        RT.reload = function () {
            var st = live();
            if (!st) return;
            showView(st); // the page may have swapped its list card (Maintenance replaces it whole)
            go(st, st.start, st.days, true);
        };
        // A mode with a poll() hook (Room Service): the page's existing poll timer calls this; there is no timer here.
        // Refreshes the grid only on today's timeline while nobody is using it: drawer, filters and menus closed,
        // the search box and the grid not focused, nothing loading. Keeps the scroll place; says what changed.
        RT.opsPoll = function () {
            var st = live();
            if (!st || !st.A.poll || !st.api || !idle(st)) return;
            var token = st.loading, start = st.start;
            fetch(st.api + '?start=' + encodeURIComponent(start) + '&days=1', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (!j || !j.ok || cur !== st || token !== st.loading || st.start !== start || !idle(st) || !covers(j, start, st.days)) return;
                    var msg = st.A.poll(st.data, j);
                    var sc = q(st, '[data-tl-scroller]');
                    var sl = sc.scrollLeft, stp = sc.scrollTop;
                    st.data = j;
                    st.quiet = true;
                    if (msg) st.flash = msg;
                    render(st);
                    sc.scrollLeft = sl;
                    sc.scrollTop = stp;
                })
                .catch(function () { /* a network blip: the next poll tries again */ });
        };
        function idle(st) {
            if (!document.contains(st.root) || st.view !== 'timeline' || st.start !== st.data.today) return false;
            if (st.root.classList.contains('is-loading') || st.root.classList.contains('is-filters-open')) return false;
            var dr = q(st, '[data-tl-drawer]');
            if (dr && !dr.hidden) return false;
            if (st.root.querySelector('.tl-more.open')) return false;
            var ae = document.activeElement;
            if (ae && ae.matches && ae.matches('[data-tl-search], [data-tl-date], [data-tl-filter]')) return false;
            var grid = q(st, '[data-tl-grid]');
            if (ae && grid && grid.contains(ae)) return false;
            return true;
        }
    }
})();
