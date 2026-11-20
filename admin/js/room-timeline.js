/**
 * Room Timeline (admin/calendar.php, Calendar tab). Read-only tape chart: rooms down the side, days across,
 * one bar per stay (half-day ends), blocks / maintenance hatched, housekeeping icon per room, per-day
 * occupancy / arrivals / departures, a drawer (desktop, tablet) or bottom sheet (phone) per booking and a
 * phone Agenda. Every action is a LINK to the existing page that does it.
 *
 * Data: the JSON block inside the section (first paint) and admin/api/calendar-timeline.php for other ranges.
 * Names and notes only ever reach the page through textContent.
 *
 * SPA safe: window.RoomTimeline.init(root) is called by the page's inline script on every visit; a root is
 * bound once (data-tl-bound) and document-level listeners are bound once and act on the mounted root only.
 */
(function () {
    'use strict';
    if (window.RoomTimeline) return;

    var cur = null;       // state of the mounted timeline
    var docBound = false;
    var STORE_COLLAPSE = 'rhTlCollapsed';
    var STORE_DENSITY = 'rhTlDensity';
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    var mqPhone = window.matchMedia('(max-width: 599px)');
    var mqTablet = window.matchMedia('(min-width: 600px) and (max-width: 1024px)');
    var mqCoarse = window.matchMedia('(pointer: coarse)');
    var mqReduce = window.matchMedia('(prefers-reduced-motion: reduce)');

    /* ---------------------------------------------------------------- helpers */
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
    function help(text) {
        var h = el('i', 'help', '?');
        h.setAttribute('data-tip', text);
        return h;
    }
    function dnum(s) { // 'Y-m-d' -> whole days since epoch (UTC, no time zone drift)
        var p = String(s).split('-');
        return Math.round(Date.UTC(+p[0], +p[1] - 1, +p[2]) / 86400000);
    }
    function dstr(n) {
        var d = new Date(n * 86400000);
        return d.getUTCFullYear() + '-' + String(d.getUTCMonth() + 1).padStart(2, '0') + '-' + String(d.getUTCDate()).padStart(2, '0');
    }
    function addDays(s, n) { return dstr(dnum(s) + n); }
    function fmt(s) {
        var p = String(s).split('-');
        return (+p[2]) + ' ' + MONTHS[+p[1] - 1];
    }
    function fmtRange(a, b) {
        var pa = String(a).split('-'), pb = String(b).split('-');
        if (pa[1] === pb[1] && pa[0] === pb[0]) return (+pa[2]) + ' to ' + fmt(b);
        return fmt(a) + ' to ' + fmt(b);
    }
    function nightsWord(n) { return n + (n === 1 ? ' night' : ' nights'); }
    function money(st, v) {
        var cur = st.data.currency || '';
        return (cur ? cur + ' ' : '') + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function statusWord(s) { return String(s || '').replace(/-/g, ' '); }
    function store(key, val) {
        try {
            if (val === undefined) return JSON.parse(window.localStorage.getItem(key) || 'null');
            window.localStorage.setItem(key, JSON.stringify(val));
        } catch (e) { return null; }
        return null;
    }
    function viewport() { return mqPhone.matches ? 'phone' : (mqTablet.matches ? 'tablet' : 'desktop'); }
    function allowedDays() {
        var vp = viewport();
        return vp === 'phone' ? [3, 7] : (vp === 'tablet' ? [7, 14] : [3, 7, 14, 30]);
    }
    function defaultDays() {
        var vp = viewport();
        return vp === 'phone' ? 3 : (vp === 'tablet' ? 7 : 14);
    }
    function q(st, sel) { return st.root.querySelector(sel); }

    /* ---------------------------------------------------------------- state */
    function init(root) {
        if (!root || root.getAttribute('data-tl-bound') === '1') return;
        var jsonEl = root.querySelector('[data-tl-json]');
        if (!jsonEl) return;
        var cfg;
        try { cfg = JSON.parse(jsonEl.textContent || '{}'); } catch (e) { return; }
        if (!cfg || !cfg.data) return;
        root.setAttribute('data-tl-bound', '1');

        var ini = cfg.initial || {};
        var st = {
            root: root,
            data: cfg.data,
            api: cfg.api || 'api/calendar-timeline.php',
            start: cfg.data.start,
            days: cfg.data.days,
            filters: { type: ini.type ? String(ini.type) : '', floor: ini.floor || '', status: '', room: ini.room ? Number(ini.room) : 0, day: null },
            search: ini.search || '',
            mode: 'grid',
            collapsed: store(STORE_COLLAPSE) || {},
            compact: !!store(STORE_DENSITY),
            loading: 0,
            barIndex: {},
            lastFocus: null,
            sel: null
        };
        cur = st;
        if (!ini.days_set) st.days = defaultDays();
        if (allowedDays().indexOf(st.days) === -1) st.days = defaultDays();

        var typeSel = q(st, '[data-tl-filter="type"]');
        var floorSel = q(st, '[data-tl-filter="floor"]');
        if (typeSel) typeSel.value = st.filters.type;
        if (floorSel) floorSel.value = st.filters.floor;
        var search = q(st, '[data-tl-search]');
        if (search) search.value = st.search;

        bindRoot(st);
        bindDoc();
        go(st, st.start, st.days);
    }

    /* Show [start, start+days). Uses the loaded data when it covers the range, else asks the server. */
    function go(st, start, days) {
        st.start = start;
        st.days = days;
        var dateIn = q(st, '[data-tl-date]');
        if (dateIn) dateIn.value = start;
        if (covers(st.data, start, days)) {
            ++st.loading; // a newer view wins over any fetch still in flight
            render(st);
            return;
        }
        var token = ++st.loading;
        st.root.classList.add('is-loading');
        announce(st, 'Loading ' + fmtRange(start, addDays(start, days - 1)) + '...');
        var url = st.api + '?start=' + encodeURIComponent(start) + '&days=' + encodeURIComponent(days);
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (r.status === 401) throw new Error('You have been signed out. Reload the page to sign in again.');
                if (r.status === 403) throw new Error('You do not have access to the calendar.');
                return r.json().then(function (j) {
                    if (!r.ok || !j || !j.ok) throw new Error((j && j.error) || 'The calendar could not be loaded. Try again.');
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

    function covers(data, start, days) {
        if (!data || !data.start || !data.dates || !data.dates.length) return false;
        var a = dnum(data.start), s = dnum(start);
        return s >= a && s + days <= a + data.dates.length;
    }

    function announce(st, text) {
        var s = q(st, '[data-tl-status]');
        if (s) s.textContent = text;
    }

    /* ---------------------------------------------------------------- filters */
    function laneVisible(st, lane) {
        var f = st.filters;
        if (f.type && String(lane.type_id) !== f.type) return false;
        if (f.floor && lane.floor !== f.floor) return false;
        if (f.room && lane.id !== f.room) return false;
        if (f.status && !(lane.today && lane.today[f.status])) return false;
        if (f.day) {
            var hit = (st.lanesBookings[lane.id] || []).some(function (b) { return dayMatch(st, b); });
            if (!hit) return false;
        }
        return true;
    }
    function dayMatch(st, b) {
        var f = st.filters.day;
        if (!f) return true;
        if (f.kind === 'arr') return b.ci === f.date && ['pending', 'tentative', 'confirmed'].indexOf(b.status) !== -1;
        return b.dep === f.date;
    }
    function filtersActive(st) {
        var f = st.filters;
        return !!(f.type || f.floor || f.status || f.room || f.day);
    }
    function bookingMatches(st, b) {
        var s = st.search.trim().toLowerCase();
        if (!s) return true;
        return [b.guest, b.room_guest, b.ref, b.room_label].some(function (v) {
            return String(v || '').toLowerCase().indexOf(s) !== -1;
        });
    }

    /* ---------------------------------------------------------------- render */
    function render(st) {
        var d = st.data;
        if (!covers(d, st.start, st.days)) { go(st, st.start, st.days); return; }
        st.root.classList.remove('is-loading');
        var offset = dnum(st.start) - dnum(d.start);
        var dates = d.dates.slice(offset, offset + st.days);
        st.dates = dates;
        st.first = dnum(dates[0].d);
        var n = dates.length;
        var todayIdx = dnum(d.today) - st.first;

        // Index bookings per lane / per unassigned type.
        st.lanesBookings = {};
        st.unassigned = {};
        st.byId = {};
        d.bookings.forEach(function (b) {
            st.byId[b.id] = b;
            if (b.lanes.length) {
                b.lanes.forEach(function (l) { (st.lanesBookings[l] = st.lanesBookings[l] || []).push(b); });
            } else {
                (st.unassigned[b.type_id] = st.unassigned[b.type_id] || []).push(b);
            }
        });
        st.blocksByLane = {};
        d.blocks.forEach(function (bl) { (st.blocksByLane[bl.lane] = st.blocksByLane[bl.lane] || []).push(bl); });
        var lanesById = {};
        d.lanes.forEach(function (l) { lanesById[l.id] = l; });
        st.lanesById = lanesById;

        syncToolbar(st);

        // Sizes.
        var scroller = q(st, '[data-tl-scroller]');
        var vp = viewport();
        var roomW = vp === 'phone' ? 64 : (vp === 'tablet' ? 168 : 208);
        var avail = (scroller.clientWidth || st.root.clientWidth || 1000) - roomW - 2;
        var dayW = Math.max(44, Math.floor(avail / n));
        st.dayW = dayW;
        st.root.style.setProperty('--tl-day-w', dayW + 'px');
        st.root.style.setProperty('--tl-days', String(n));
        st.root.classList.toggle('is-compact', st.compact && !mqCoarse.matches);
        st.rowH = (st.compact && !mqCoarse.matches) ? 36 : 44;

        var grid = q(st, '[data-tl-grid]');
        grid.textContent = '';
        grid.setAttribute('aria-label', 'Room timeline, ' + fmtRange(dates[0].d, dates[n - 1].d));
        grid.setAttribute('aria-colcount', String(n + 1));

        // Head: corner + days, then the summary rows.
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
        dates.forEach(function (x) {
            var c = el('div', 'tl-head__day' + (x.we ? ' tl-head__day--weekend' : '') + (x.today ? ' tl-head__day--today' : ''));
            c.setAttribute('role', 'columnheader');
            c.setAttribute('aria-label', x.wd + ' ' + fmt(x.d) + (x.today ? ', today' : ''));
            c.appendChild(el('span', 'tl-head__wd', x.wd));
            c.appendChild(el('span', 'tl-head__num', x.day));
            c.appendChild(el('span', 'tl-head__mon', x.mon));
            track.appendChild(c);
        });
        hr.appendChild(track);
        head.appendChild(hr);

        var sums = [
            ['occ', 'Occupancy', 'Rooms with a stay that night / rooms you can sell (out of order excluded), this hotel, per night.'],
            ['arr', 'Arrivals', 'Bookings checking in that day (pending, tentative, confirmed), all room types. Click a number to show those rooms.'],
            ['dep', 'Departures', 'Bookings checking out that day (in-house and due), all room types. Click a number to show those rooms.']
        ];
        sums.forEach(function (s) {
            var row = el('div', 'tl-row tl-sum tl-sum--' + s[0]);
            row.setAttribute('role', 'row');
            var lab = el('div', 'tl-sum__label');
            lab.setAttribute('role', 'rowheader');
            lab.appendChild(el('span', 'tl-sum__full', s[1]));
            if (s[0] === 'occ') lab.appendChild(el('span', 'tl-sum__short', 'Occ.'));
            lab.appendChild(document.createTextNode(' '));
            lab.appendChild(help(s[2]));
            row.appendChild(lab);
            var t = el('div', 'tl-track');
            t.setAttribute('role', 'presentation');
            dates.forEach(function (x) {
                var stt = d.stats[x.d] || {};
                var c = el('div', 'tl-sum__cell' + (x.we ? ' is-weekend' : '') + (x.today ? ' is-today' : ''));
                c.setAttribute('role', 'gridcell');
                if (s[0] === 'occ') {
                    if (stt.pct === null || stt.pct === undefined) {
                        c.textContent = '–';
                    } else {
                        c.textContent = stt.pct + '%';
                        if (stt.pct >= 100) c.classList.add('is-full');
                        else if (stt.pct >= 90) c.classList.add('is-high');
                        c.title = stt.booked + ' of ' + stt.total + ' rooms';
                    }
                } else {
                    var v = Number(stt[s[0]] || 0);
                    if (v > 0) {
                        var btn = el('button', 'tl-sum__btn', v);
                        btn.type = 'button';
                        btn.setAttribute('data-tl-dayfilter', s[0] + '|' + x.d);
                        btn.setAttribute('aria-label', v + ' ' + (s[0] === 'arr' ? 'arrivals' : 'departures') + ' on ' + fmt(x.d) + ': show those rooms');
                        var fd = st.filters.day;
                        if (fd && fd.kind === s[0] && fd.date === x.d) btn.classList.add('is-active');
                        c.appendChild(btn);
                    } else {
                        c.textContent = '–';
                    }
                }
                t.appendChild(c);
            });
            row.appendChild(t);
            head.appendChild(row);
        });
        grid.appendChild(head);

        // Body.
        var anyRow = false;
        var nowLeft = (todayIdx >= 0 && todayIdx < n) ? (todayIdx + 0.5) * dayW : null;
        d.groups.forEach(function (g) {
            var lanes = g.lanes.map(function (id) { return lanesById[id]; }).filter(function (l) { return l && laneVisible(st, l); });
            var un = (st.unassigned[g.id] || []).filter(function (b) { return inView(st, b); });
            var showUn = un.length > 0 && !st.filters.floor && !st.filters.room && !st.filters.status
                && (!st.filters.type || st.filters.type === String(g.id));
            if (showUn && st.filters.day) {
                un = un.filter(function (b) { return dayMatch(st, b); });
                showUn = un.length > 0;
            }
            if (!lanes.length && !showUn) return;
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
            if (g.total > 0) {
                var badge = el('span', 'badge badge-neutral tl-group__badge', g.free_tonight + ' of ' + g.total + ' free tonight');
                badge.title = 'Rooms of this type with no stay tonight and not out of service or blocked.';
                gc.appendChild(badge);
            }
            gr.appendChild(gc);
            grid.appendChild(gr);
            if (collapsed) return;

            if (showUn) {
                grid.appendChild(laneRow(st, {
                    unassigned: true, type_id: g.id, label: 'Unassigned (' + un.length + ')', bookings: un
                }, dates, nowLeft));
            }
            lanes.forEach(function (l) {
                grid.appendChild(laneRow(st, { lane: l, bookings: st.lanesBookings[l.id] || [] }, dates, nowLeft));
            });
        });
        q(st, '[data-tl-empty]').hidden = anyRow;
        grid.setAttribute('aria-rowcount', String(grid.querySelectorAll('[role="row"]').length));

        applySearch(st, false);
        setupRoving(st);
        renderAgenda(st);
        showMode(st);
        announce(st, 'Showing ' + fmtRange(dates[0].d, dates[n - 1].d) + '.');
    }

    function inView(st, b) {
        var a = st.first, z = st.first + st.dates.length;
        var ci = dnum(b.ci), co = dnum(b.eco);
        return ci < z && co >= a;
    }

    /* Stack overlapping bars in one row (a double booking still shows both). */
    function pack(items) {
        var levels = [];
        items.sort(function (x, y) { return x.x1 - y.x1; });
        items.forEach(function (it) {
            for (var i = 0; i < levels.length; i++) {
                if (levels[i] <= it.x1) { it.level = i; levels[i] = it.x2; return; }
            }
            it.level = levels.length;
            levels.push(it.x2);
        });
        return Math.max(1, levels.length);
    }

    function laneRow(st, spec, dates, nowLeft) {
        var d = st.data, n = dates.length, dayW = st.dayW, trackW = n * dayW;
        var row = el('div', 'tl-row tl-lane' + (spec.unassigned ? ' tl-row--unassigned' : ''));
        row.setAttribute('role', 'row');

        // Room cell.
        var rc = el('div', 'tl-room');
        rc.setAttribute('role', 'rowheader');
        if (spec.unassigned) {
            row.setAttribute('data-tl-type', String(spec.type_id));
            var u = el('span', 'tl-room__link');
            u.appendChild(icon('fa-inbox'));
            u.appendChild(el('span', 'tl-room__num tl-room__num--un', spec.label));
            u.title = 'Bookings of this room type with no room assigned yet.';
            rc.appendChild(u);
        } else {
            var l = spec.lane;
            row.setAttribute('data-tl-lane', String(l.id));
            var hkLabel = d.hk_on ? l.hk.label : '';
            var aria = 'Room ' + l.number + (l.name ? ' ' + l.name : '') + (hkLabel ? ', ' + hkLabel.toLowerCase() : '');
            var link = el(d.can.rooms_page ? 'a' : 'span', 'tl-room__link');
            if (d.can.rooms_page) link.href = 'individual-rooms.php?room_type=' + encodeURIComponent(l.type_id);
            link.setAttribute('aria-label', aria);
            link.title = aria + (l.joined ? ' (joined rooms)' : '');
            var txt = el('span', 'tl-room__text');
            txt.appendChild(el('span', 'tl-room__num', l.number));
            if (l.name) txt.appendChild(el('span', 'tl-room__name', l.name));
            link.appendChild(txt);
            if (d.hk_on) {
                var hk = el('span', 'tl-hk tl-hk--' + l.hk.state);
                hk.title = l.hk.label;
                hk.appendChild(icon({ clean: 'fa-check', dirty: 'fa-exclamation', cleaning: 'fa-ellipsis', inspection: 'fa-eye', blocked: 'fa-ban' }[l.hk.state] || 'fa-check'));
                link.appendChild(hk);
            }
            rc.appendChild(link);
        }
        row.appendChild(rc);

        // Track with day cells.
        var track = el('div', 'tl-track');
        track.setAttribute('role', 'presentation');
        var cells = [];
        dates.forEach(function (x, i) {
            var c = el('div', 'tl-cell' + (x.we ? ' tl-cell--weekend' : '') + (x.today ? ' tl-cell--today' : '') + (x.d < d.today ? ' tl-cell--past' : ''));
            c.setAttribute('role', 'gridcell');
            c.setAttribute('data-tl-day', String(i));
            track.appendChild(c);
            cells.push(c);
        });

        // Blocks (full cells).
        if (!spec.unassigned) {
            (st.blocksByLane[spec.lane.id] || []).forEach(function (bl) {
                var a = Math.max(0, dnum(bl.from) - st.first), z = Math.min(n - 1, dnum(bl.to) - st.first);
                if (z < a) return;
                var blk = el(d.can[bl.kind === 'blocked' ? 'blocked_page' : 'maint_page'] ? 'a' : 'div', 'tl-block tl-block--' + bl.kind);
                if (blk.tagName === 'A') blk.href = bl.href;
                blk.style.left = (a * dayW) + 'px';
                blk.style.width = ((z - a + 1) * dayW) + 'px';
                var labTxt = bl.label + (bl.reason && bl.reason.length <= 24 ? ': ' + bl.reason : '');
                blk.title = bl.label + (bl.reason ? ': ' + bl.reason : '') + ', ' + fmtRange(bl.from, bl.to);
                blk.setAttribute('aria-label', blk.title);
                var pill = el('span', 'tl-block__label');
                pill.appendChild(icon(bl.kind === 'ooo' ? 'fa-ban' : (bl.kind === 'blocked' ? 'fa-lock' : 'fa-screwdriver-wrench')));
                pill.appendChild(el('span', null, labTxt));
                blk.appendChild(pill);
                cells[a].appendChild(blk);
            });
        }

        // Bars.
        var items = [];
        spec.bookings.forEach(function (b) {
            if (!inView(st, b)) return;
            var si = dnum(b.ci) - st.first, ei = dnum(b.eco) - st.first;
            var x1 = (si + 0.5) * dayW + 2, x2 = (ei + 0.5) * dayW - 2;
            var cutS = x1 < 0, cutE = x2 > trackW;
            x1 = Math.max(0, x1);
            x2 = Math.min(trackW, x2);
            if (x2 - x1 < 6) return;
            items.push({ b: b, x1: x1, x2: x2, cutS: cutS, cutE: cutE, si: Math.max(0, Math.min(n - 1, si)) });
        });
        var levels = pack(items);
        row.style.setProperty('--tl-levels', String(levels));
        items.forEach(function (it) {
            var bar = barEl(st, it, !!spec.unassigned, spec.lane);
            bar.style.left = it.x1 + 'px';
            bar.style.width = (it.x2 - it.x1) + 'px';
            bar.style.top = (it.level * st.rowH + 4) + 'px';
            var host = cells[Math.max(0, Math.min(n - 1, Math.floor(it.x1 / dayW)))];
            host.appendChild(bar);
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

    function barEl(st, it, unassigned, lane) {
        var b = it.b;
        var bar = el('button', 'tl-bar tl-bar--' + b.status + ' tl-bar--locked');
        bar.type = 'button';
        bar.tabIndex = -1;
        bar.setAttribute('data-tl-booking', String(b.id));
        if (unassigned) bar.classList.add('tl-bar--unassigned');
        if (b.overdue) bar.classList.add('tl-bar--overdue');
        if (b.missed) bar.classList.add('tl-bar--missed');
        if (it.cutS) bar.classList.add('tl-bar--cut-start');
        if (it.cutE) bar.classList.add('tl-bar--cut-end');
        var w = it.x2 - it.x1;
        if (w < 96) bar.classList.add('tl-bar--narrow');
        if (w < 56) bar.classList.add('tl-bar--tiny');

        var who = b.guest + (b.room_guest && b.room_guest !== b.guest ? ' (' + b.room_guest + ')' : '');
        var room = unassigned ? 'no room yet' : ('Room ' + (b.room_label || (lane ? lane.number : '')));
        var pay = b.pay.key === 'paid' ? 'paid' : (b.pay.key === 'partial' ? 'part paid' : (b.pay.key === 'refunded' ? 'refunded' : 'unpaid'));
        var sentence = who + ', ' + statusWord(b.status) + (b.missed ? ' (missed arrival)' : '') + (b.overdue ? ' (past check-out)' : '')
            + ', ' + fmtRange(b.ci, b.co) + ', ' + nightsWord(b.nights) + ', ' + room + ', ' + pay;
        bar.setAttribute('aria-label', sentence);
        bar.title = sentence;

        if (it.cutS) bar.appendChild(icon('fa-angle-left tl-bar__cut'));
        var body = el('span', 'tl-bar__body');
        body.appendChild(el('span', 'tl-bar__name', b.guest));
        var meta = el('span', 'tl-bar__meta');
        meta.appendChild(el('span', null, b.rate_plan || nightsWord(b.nights)));
        if (b.guests > 0) {
            meta.appendChild(icon('fa-user'));
            meta.appendChild(el('span', null, String(b.guests)));
        }
        body.appendChild(meta);
        bar.appendChild(body);

        var flags = el('span', 'tl-bar__flags');
        if (b.pay.key === 'partial') flags.appendChild(flag('part', 'fa-circle-half-stroke'));
        else if (b.pay.key === 'unpaid') flags.appendChild(flag('unpaid', 'fa-circle-exclamation'));
        if (b.notes) flags.appendChild(flag('note', 'fa-note-sticky'));
        if (b.joined) flags.appendChild(flag('joined', 'fa-link'));
        else if (b.group) flags.appendChild(flag('group', 'fa-users'));
        if (flags.childNodes.length) bar.appendChild(flags);
        if (it.cutE) bar.appendChild(icon('fa-angle-right tl-bar__cut'));
        return bar;
    }
    function flag(kind, ic) {
        var f = el('span', 'tl-flag tl-flag--' + kind);
        f.appendChild(icon(ic));
        return f;
    }

    /* ---------------------------------------------------------------- search, keyboard */
    function applySearch(st, scroll) {
        var s = st.search.trim();
        st.root.classList.toggle('is-searching', s !== '');
        var first = null;
        st.root.querySelectorAll('.tl-bar').forEach(function (bar) {
            var b = st.byId[bar.getAttribute('data-tl-booking')];
            var m = s !== '' && b && bookingMatches(st, b);
            bar.classList.toggle('tl-bar--match', m);
            if (m && !first) first = bar;
        });
        if (scroll && first) {
            first.scrollIntoView({ block: 'nearest', inline: 'center', behavior: mqReduce.matches ? 'auto' : 'smooth' });
            first.focus({ preventScroll: true });
        }
        if (scroll && s !== '' && !first) announce(st, 'No booking in this range matches "' + s + '".');
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
        else if (key === 'ArrowUp' || key === 'ArrowDown') {
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

    /* ---------------------------------------------------------------- drawer / sheet */
    function asSheet() { return mqPhone.matches || (mqCoarse.matches && !mqTablet.matches && window.innerWidth < 1025 && window.innerWidth < 600); }

    function openDrawer(st, title, buildBody, buildFoot, opener) {
        var dr = q(st, '[data-tl-drawer]');
        var bd = q(st, '[data-tl-backdrop]');
        closeMenus(st);
        st.lastFocus = opener || document.activeElement;
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
        // next frame: slide in (no motion under prefers-reduced-motion, see CSS)
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
        st.root.querySelectorAll('.tl-bar.is-selected').forEach(function (b) { b.classList.remove('is-selected'); });
        if (restore !== false && st.lastFocus && document.contains(st.lastFocus)) {
            try { st.lastFocus.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
        }
    }

    function dl(body, label, value, node) {
        var row = el('div', 'tl-dl__row');
        row.appendChild(el('dt', null, label));
        var dd = el('dd');
        if (node) dd.appendChild(node); else dd.textContent = value;
        row.appendChild(dd);
        body.appendChild(row);
    }
    function linkBtn(cls, href, text, ic) {
        var a = el('a', cls);
        a.href = href;
        if (ic) a.appendChild(icon(ic));
        a.appendChild(document.createTextNode((ic ? ' ' : '') + text));
        return a;
    }

    function openBooking(st, b, bar) {
        st.root.querySelectorAll('.tl-bar.is-selected').forEach(function (x) { x.classList.remove('is-selected'); });
        if (bar) bar.classList.add('is-selected');
        var can = st.data.can, today = st.data.today;
        var unassigned = !b.lanes.length;
        openDrawer(st, b.guest, function (body) {
            var top = el('div', 'tl-drawer__status');
            top.appendChild(el('span', 'badge badge-' + b.status, statusWord(b.status).replace(/^\w/, function (c) { return c.toUpperCase(); })));
            if (b.missed) top.appendChild(el('span', 'badge badge-warning', 'Missed arrival'));
            if (b.overdue) top.appendChild(el('span', 'badge badge-danger', 'Past check-out'));
            body.appendChild(top);
            var list = el('dl', 'tl-dl');
            dl(list, 'Reference', b.ref);
            if (b.room_guest && b.room_guest !== b.guest) dl(list, 'Room guest', b.room_guest);
            dl(list, 'Stay', fmtRange(b.ci, b.co) + ', ' + nightsWord(b.nights) + (b.eco !== b.co ? ' (still in, room held to ' + fmt(b.eco) + ')' : ''));
            dl(list, 'Room', unassigned ? 'Not assigned yet' : (b.room_label || '–'));
            dl(list, 'Room type', b.type_name || '–');
            var g = b.guests > 0 ? b.guests + (b.guests === 1 ? ' guest' : ' guests') : '–';
            if (b.adults || b.children) g += ' (' + b.adults + ' adult' + (b.adults === 1 ? '' : 's') + (b.children ? ', ' + b.children + ' child' + (b.children === 1 ? '' : 'ren') : '') + ')';
            dl(list, 'Guests', g);
            if (b.rate_plan) dl(list, 'Rate plan', b.rate_plan);
            dl(list, 'Total', money(st, b.total));
            dl(list, 'Paid', money(st, b.paid));
            var payNode = el('span', 'tl-pay');
            payNode.appendChild(document.createTextNode(money(st, b.pay.key === 'paid' || b.pay.key === 'refunded' ? 0 : b.due) + ' '));
            payNode.appendChild(el('span', 'badge ' + ({ paid: 'badge-paid', partial: 'badge-partial', refunded: 'badge-neutral' }[b.pay.key] || 'badge-unpaid'), b.pay.short));
            dl(list, 'Balance', '', payNode);
            if (b.note_text) dl(list, 'Notes', b.note_text);
            if (b.joined) dl(list, 'Joined rooms', 'Rooms ' + (b.room_label || '') + ' are booked together as one room.');
            else if (b.group) dl(list, 'Group', 'Group of ' + b.group_size + ' rooms' + (b.group_ref ? ', main booking ' + b.group_ref : '') + '.');
            body.appendChild(list);
            var lockNote = el('p', 'tl-drawer__note', 'To move this stay or change its dates, use the actions below; they open the booking forms.');
            body.appendChild(lockNote);
        }, function (foot) {
            if (can.open_booking) foot.appendChild(linkBtn('btn btn-secondary', 'booking-details.php?id=' + b.id, 'Open booking'));
            var primary = null;
            if (b.status === 'confirmed' && b.ci <= today && !unassigned && can.checkin) {
                primary = linkBtn('btn btn-primary', 'bookings.php?action=checkin&booking_id=' + b.id, 'Check in', 'fa-right-to-bracket');
            } else if (b.status === 'checked-in' && can.checkout) {
                primary = linkBtn('btn btn-primary', 'bookings.php?action=checkout&booking_id=' + b.id, 'Check out', 'fa-right-from-bracket');
            } else if (unassigned && b.status === 'confirmed' && can.assign) {
                primary = linkBtn('btn btn-primary', 'bookings.php?action=assign-room&booking_id=' + b.id, 'Assign room', 'fa-door-open');
            }
            if (primary) foot.appendChild(primary);

            var more = [];
            var canRoom = b.status === 'confirmed' || b.status === 'checked-in';
            if (!unassigned && canRoom && can.move) more.push(['bookings.php?action=room-change&booking_id=' + b.id, 'Move room', 'fa-right-left']);
            if (can.edit_dates) more.push(['edit-booking.php?id=' + b.id, 'Change dates', 'fa-calendar-pen']);
            if (can.payment && b.pay.key !== 'paid' && b.pay.key !== 'refunded') more.push(['payment-add.php?booking_type=room&booking_id=' + b.id, 'Record payment', 'fa-money-bill']);
            if (canRoom && can.assign && !(unassigned && b.status === 'confirmed') && b.status === 'confirmed') more.push(['bookings.php?action=assign-room&booking_id=' + b.id, 'Assign room', 'fa-door-open']);
            if (more.length) {
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
                more.forEach(function (m) {
                    var a = linkBtn('', m[0], m[1], m[2]);
                    a.setAttribute('role', 'menuitem');
                    menu.appendChild(a);
                });
                wrap.appendChild(tb);
                wrap.appendChild(menu);
                foot.appendChild(wrap);
            }
            if (!foot.childNodes.length) foot.appendChild(el('span', 'tl-muted', 'No actions available to you here.'));
        }, bar);
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

    /* ---------------------------------------------------------------- new booking from empty cells */
    function newBookingHref(st, lane, a, z) {
        var ci = st.dates[a].d, co = addDays(st.dates[z].d, 1);
        return 'create-booking.php?room=' + encodeURIComponent(lane.id) + '&room_type=' + encodeURIComponent(lane.type_id)
            + '&check_in=' + encodeURIComponent(ci) + '&check_out=' + encodeURIComponent(co);
    }
    function rangeFree(st, lane, a, z) {
        var lo = st.first + a, hi = st.first + z;
        var blocked = (st.blocksByLane[lane.id] || []).some(function (bl) { return dnum(bl.from) <= hi && dnum(bl.to) >= lo; });
        if (blocked) return false;
        return !(st.lanesBookings[lane.id] || []).some(function (b) {
            return dnum(b.ci) <= hi && dnum(b.eco) - 1 >= lo;
        });
    }
    function clearSel(st) {
        st.root.querySelectorAll('.tl-sel').forEach(function (s) { s.remove(); });
        var pop = q(st, '[data-tl-selpop]');
        if (pop) pop.hidden = true;
        st.sel = null;
    }
    function drawSel(st) {
        var s = st.sel;
        st.root.querySelectorAll('.tl-sel').forEach(function (x) { x.remove(); });
        var a = Math.min(s.a, s.b), z = Math.max(s.a, s.b);
        var box = el('div', 'tl-sel' + (s.ok ? '' : ' tl-sel--bad'));
        box.style.left = (a * st.dayW) + 'px';
        box.style.width = ((z - a + 1) * st.dayW) + 'px';
        box.appendChild(el('span', 'tl-sel__label', nightsWord(z - a + 1)));
        s.row.querySelector('.tl-cell[data-tl-day="' + a + '"]').appendChild(box);
    }
    function finishSel(st) {
        var s = st.sel;
        if (!s) return;
        var a = Math.min(s.a, s.b), z = Math.max(s.a, s.b);
        var lane = s.lane;
        s.ok = rangeFree(st, lane, a, z);
        drawSel(st);
        var pop = q(st, '[data-tl-selpop]');
        var text = 'Room ' + lane.number + ', ' + fmtRange(st.dates[a].d, addDays(st.dates[z].d, 1)) + ', ' + nightsWord(z - a + 1) + '.';
        var go = q(st, '[data-tl-selpop-go]');
        if (!s.ok) {
            text += ' This room is not free for all of these nights.';
            go.hidden = true;
        } else {
            go.hidden = false;
            go.href = newBookingHref(st, lane, a, z);
        }
        q(st, '[data-tl-selpop-text]').textContent = text;
        var r = s.row.querySelector('.tl-cell[data-tl-day="' + z + '"]').getBoundingClientRect();
        var host = st.root.getBoundingClientRect();
        pop.hidden = false;
        var pw = pop.offsetWidth || 260;
        var left = Math.min(Math.max(8, r.right - host.left - pw / 2), host.width - pw - 8);
        pop.style.left = left + 'px';
        pop.style.top = (r.bottom - host.top + 6) + 'px';
        (s.ok ? go : q(st, '[data-tl-selpop-cancel]')).focus({ preventScroll: true });
    }
    function tapEmpty(st, lane, idx, cell) {
        var free = rangeFree(st, lane, idx, idx);
        openDrawer(st, 'Room ' + lane.number, function (body) {
            var list = el('dl', 'tl-dl');
            dl(list, 'Night', fmt(st.dates[idx].d) + ' to ' + fmt(addDays(st.dates[idx].d, 1)));
            dl(list, 'Room', lane.number + (lane.name ? ' ' + lane.name : ''));
            dl(list, 'Housekeeping', lane.hk.label);
            body.appendChild(list);
            if (!free) body.appendChild(el('p', 'tl-drawer__note', 'This night is taken or blocked.'));
        }, function (foot) {
            if (free && st.data.can.create) {
                foot.appendChild(linkBtn('btn btn-primary', newBookingHref(st, lane, idx, idx), 'New booking from ' + fmt(st.dates[idx].d), 'fa-plus'));
            }
            if (!foot.childNodes.length) foot.appendChild(el('span', 'tl-muted', 'Nothing to do here.'));
        }, cell);
    }

    /* ---------------------------------------------------------------- agenda (phone) */
    function renderAgenda(st) {
        var box = q(st, '[data-tl-agenda]');
        if (!box) return;
        box.textContent = '';
        var d = st.data, can = d.can;
        var parts = [
            ['arr', 'Arriving today', 'Bookings due to check in today (pending, tentative, confirmed).'],
            ['inh', 'In house', 'Guests checked in who are not due out today.'],
            ['dep', 'Departing today', 'Bookings due out today, including guests past their check-out date.']
        ];
        parts.forEach(function (p) {
            var rows = d.agenda[p[0]] || [];
            var sec = el('section', 'tl-agenda__section');
            var h = el('h3', 'tl-agenda__title');
            h.appendChild(el('span', null, p[1]));
            h.appendChild(el('span', 'ck-tab__count', String(rows.length)));
            h.appendChild(help(p[2]));
            sec.appendChild(h);
            if (!rows.length) {
                sec.appendChild(el('p', 'tl-muted tl-agenda__empty', 'None today.'));
            } else {
                var wrap = el('div', 'tl-agenda__list');
                var ul = el('ul', 'ck-rows');
                rows.forEach(function (b) {
                    var li = el('li', 'ck-row tl-agenda__row');
                    li.appendChild(el('span', 'ck-chip tl-agenda__room', b.room_label || 'No room'));
                    var main = el(can.open_booking ? 'a' : 'span', 'ck-row__main');
                    if (can.open_booking) main.href = 'booking-details.php?id=' + b.id;
                    main.appendChild(el('strong', null, b.guest));
                    main.appendChild(el('span', null, b.ref + ' · ' + nightsWord(b.nights)));
                    li.appendChild(main);
                    var meta = el('span', 'ck-row__meta');
                    meta.appendChild(el('span', 'badge badge-' + b.status, statusWord(b.status)));
                    meta.appendChild(el('span', 'badge ' + ({ paid: 'badge-paid', partial: 'badge-partial', refunded: 'badge-neutral' }[b.pay.key] || 'badge-unpaid'), b.pay.short));
                    li.appendChild(meta);
                    var act = el('span', 'ck-row__act');
                    if (p[0] === 'arr' && b.status === 'confirmed' && b.lanes.length && can.checkin) {
                        act.appendChild(linkBtn('ck-btn ck-btn--sm ck-btn--primary', 'bookings.php?action=checkin&booking_id=' + b.id, 'Check in'));
                    } else if (b.status === 'checked-in' && p[0] === 'dep' && can.checkout) {
                        act.appendChild(linkBtn('ck-btn ck-btn--sm ck-btn--primary', 'bookings.php?action=checkout&booking_id=' + b.id, 'Check out'));
                    } else {
                        var bt = el('button', 'ck-btn ck-btn--sm', 'Details');
                        bt.type = 'button';
                        bt.setAttribute('data-tl-open', String(b.id));
                        act.appendChild(bt);
                    }
                    li.appendChild(act);
                    ul.appendChild(li);
                });
                wrap.appendChild(ul);
                sec.appendChild(wrap);
            }
            box.appendChild(sec);
        });
        st.agendaById = {};
        ['arr', 'inh', 'dep'].forEach(function (k) { (d.agenda[k] || []).forEach(function (b) { st.agendaById[b.id] = b; }); });
        if (st.mode === 'agenda' && window.AdminAutoSectionPagination) window.AdminAutoSectionPagination.init();
    }

    function showMode(st) {
        var agenda = st.mode === 'agenda';
        q(st, '[data-tl-scroller]').hidden = agenda;
        q(st, '[data-tl-agenda]').hidden = !agenda;
        if (agenda) q(st, '[data-tl-empty]').hidden = true;
        st.root.classList.toggle('is-agenda', agenda);
        st.root.querySelectorAll('[data-tl-mode]').forEach(function (b) {
            var on = b.getAttribute('data-tl-mode') === st.mode;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (agenda && window.AdminAutoSectionPagination) window.AdminAutoSectionPagination.init();
    }

    /* ---------------------------------------------------------------- toolbar sync */
    function syncToolbar(st) {
        var allowed = allowedDays();
        st.root.querySelectorAll('[data-tl-days]').forEach(function (b) {
            var n = Number(b.getAttribute('data-tl-days'));
            var on = n === st.days;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.hidden = allowed.indexOf(n) === -1;
        });
        var dateIn = q(st, '[data-tl-date]');
        if (dateIn) dateIn.value = st.start;
        var word = st.days + ' days';
        var prev = q(st, '[data-tl-prev]'), next = q(st, '[data-tl-next]');
        if (prev) prev.setAttribute('aria-label', 'Previous ' + word);
        if (next) next.setAttribute('aria-label', 'Next ' + word);
        var statusSel = q(st, '[data-tl-filter="status"]');
        if (statusSel) statusSel.value = st.filters.status;
        var active = filtersActive(st);
        var clear = q(st, '[data-tl-clear]');
        if (clear) clear.hidden = !active;
        var cnt = q(st, '[data-tl-filter-count]');
        if (cnt) {
            var k = ['type', 'floor', 'status', 'room', 'day'].filter(function (x) { return !!st.filters[x]; }).length;
            cnt.hidden = k === 0;
            cnt.textContent = String(k);
        }
        var dens = q(st, '[data-tl-density]');
        if (dens) {
            dens.setAttribute('aria-pressed', st.compact ? 'true' : 'false');
            dens.setAttribute('aria-label', st.compact ? 'Comfortable rows' : 'Compact rows');
            dens.title = dens.getAttribute('aria-label');
        }
    }

    /* ---------------------------------------------------------------- events */
    function bindRoot(st) {
        var root = st.root;
        root.addEventListener('click', function (e) {
            var t = e.target;
            var x;
            if ((x = t.closest('[data-tl-today]'))) { go(st, st.data.today, st.days); return; }
            if ((x = t.closest('[data-tl-prev]'))) { go(st, addDays(st.start, -st.days), st.days); return; }
            if ((x = t.closest('[data-tl-next]'))) { go(st, addDays(st.start, st.days), st.days); return; }
            if ((x = t.closest('[data-tl-days]'))) { go(st, st.start, Number(x.getAttribute('data-tl-days'))); return; }
            if ((x = t.closest('[data-tl-mode]'))) { st.mode = x.getAttribute('data-tl-mode'); showMode(st); if (st.mode === 'grid') render(st); return; }
            if ((x = t.closest('[data-tl-density]'))) { st.compact = !st.compact; store(STORE_DENSITY, st.compact); render(st); return; }
            if ((x = t.closest('[data-tl-clear]'))) {
                st.filters = { type: '', floor: '', status: '', room: 0, day: null };
                root.querySelectorAll('[data-tl-filter]').forEach(function (s) { s.value = ''; });
                render(st);
                return;
            }
            if ((x = t.closest('[data-tl-legend]'))) {
                var lg = q(st, '#tlLegend');
                lg.hidden = !lg.hidden;
                x.setAttribute('aria-expanded', lg.hidden ? 'false' : 'true');
                return;
            }
            if ((x = t.closest('[data-tl-filters-open]'))) { toggleFilters(st, true); return; }
            if ((x = t.closest('[data-tl-filters-close]'))) { toggleFilters(st, false); return; }
            if ((x = t.closest('[data-tl-collapse-all]'))) {
                var expand = x.getAttribute('aria-expanded') === 'false';
                st.collapsed = {};
                if (!expand) st.data.groups.forEach(function (g) { st.collapsed[g.id] = true; });
                store(STORE_COLLAPSE, st.collapsed);
                render(st);
                return;
            }
            if ((x = t.closest('[data-tl-group]'))) {
                var gid = x.getAttribute('data-tl-group');
                if (st.collapsed[gid]) delete st.collapsed[gid]; else st.collapsed[gid] = true;
                store(STORE_COLLAPSE, st.collapsed);
                render(st);
                var again = root.querySelector('[data-tl-group="' + gid + '"]');
                if (again) again.focus();
                return;
            }
            if ((x = t.closest('[data-tl-dayfilter]'))) {
                var parts = x.getAttribute('data-tl-dayfilter').split('|');
                var fd = st.filters.day;
                st.filters.day = (fd && fd.kind === parts[0] && fd.date === parts[1]) ? null : { kind: parts[0], date: parts[1] };
                render(st);
                var firstRow = root.querySelector('[data-tl-grid] .tl-lane');
                if (firstRow) firstRow.scrollIntoView({ block: 'nearest' });
                announce(st, st.filters.day ? 'Showing rooms with ' + (parts[0] === 'arr' ? 'arrivals' : 'departures') + ' on ' + fmt(parts[1]) + '.' : 'Showing all rooms.');
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
                    var firstItem = menu.querySelector('a');
                    if (firstItem) firstItem.focus();
                }
                return;
            }
            if ((x = t.closest('[data-tl-drawer-close]')) || t.closest('[data-tl-backdrop]')) { closeDrawer(st); return; }
            if ((x = t.closest('[data-tl-selpop-cancel]'))) { clearSel(st); return; }
            if ((x = t.closest('[data-tl-open]'))) {
                var ab = st.agendaById[x.getAttribute('data-tl-open')] || st.byId[x.getAttribute('data-tl-open')];
                if (ab) openBooking(st, ab, x);
                return;
            }
            if ((x = t.closest('.tl-bar'))) {
                var b = st.byId[x.getAttribute('data-tl-booking')];
                if (b) openBooking(st, b, x);
                return;
            }
            if (!t.closest('.tl-more')) closeMenus(st);
        });

        root.addEventListener('change', function (e) {
            var t = e.target;
            if (t.matches('[data-tl-filter]')) {
                var k = t.getAttribute('data-tl-filter');
                st.filters[k] = t.value;
                if (k === 'type' || k === 'floor') st.filters.room = 0;
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

        // Keyboard inside the grid: roving focus between bars.
        q(st, '[data-tl-grid]').addEventListener('keydown', function (e) {
            var bar = e.target.closest && e.target.closest('.tl-bar');
            if (!bar) return;
            if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].indexOf(e.key) !== -1) {
                e.preventDefault();
                moveFocus(st, bar, e.key);
            }
        });

        // Empty-cell selection: click-drag on desktop, tap on touch.
        var grid = q(st, '[data-tl-grid]');
        grid.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) return;
            var cell = e.target.closest('.tl-cell');
            if (!cell || e.target.closest('.tl-bar, .tl-block, .tl-sel')) return;
            var row = cell.closest('.tl-lane');
            if (!row || !row.hasAttribute('data-tl-lane')) return;
            var lane = st.lanesById[row.getAttribute('data-tl-lane')];
            var idx = Number(cell.getAttribute('data-tl-day'));
            if (!lane || st.dates[idx].d < st.data.today) return;
            clearSel(st);
            if (e.pointerType !== 'mouse' || mqCoarse.matches) {
                st.tap = { lane: lane, idx: idx, cell: cell, x: e.clientX, y: e.clientY };
                return;
            }
            if (!st.data.can.create) return;
            e.preventDefault();
            st.sel = { lane: lane, row: row, a: idx, b: idx, ok: true, dragging: true };
            drawSel(st);
        });
        grid.addEventListener('pointermove', function (e) {
            if (!st.sel || !st.sel.dragging) return;
            var hit = document.elementFromPoint(e.clientX, e.clientY);
            var cell = hit && hit.closest('.tl-cell');
            if (!cell || cell.closest('.tl-lane') !== st.sel.row) return;
            var idx = Number(cell.getAttribute('data-tl-day'));
            if (st.dates[idx].d < st.data.today || idx === st.sel.b) return;
            st.sel.b = idx;
            drawSel(st);
        });
        grid.addEventListener('pointerup', function (e) {
            if (st.tap) {
                var tp = st.tap;
                st.tap = null;
                if (Math.abs(e.clientX - tp.x) < 10 && Math.abs(e.clientY - tp.y) < 10) tapEmpty(st, tp.lane, tp.idx, tp.cell);
                return;
            }
            if (!st.sel || !st.sel.dragging) return;
            st.sel.dragging = false;
            finishSel(st);
        });
        grid.addEventListener('pointercancel', function () { st.tap = null; if (st.sel && st.sel.dragging) clearSel(st); });
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

    function bindDoc() {
        if (docBound) return;
        docBound = true;
        function live() { return cur && document.contains(cur.root) ? cur : null; }
        document.addEventListener('keydown', function (e) {
            var st = live();
            if (!st || e.key !== 'Escape') return;
            if (st.root.querySelector('.tl-more.open')) { closeMenus(st); return; }
            if (st.sel) { clearSel(st); return; }
            if (st.root.classList.contains('is-filters-open')) { toggleFilters(st, false); return; }
            var lg = st.root.querySelector('#tlLegend');
            if (lg && !lg.hidden) { lg.hidden = true; return; }
            closeDrawer(st);
        });
        document.addEventListener('click', function (e) {
            var st = live();
            if (!st) return;
            if (!e.target.closest('.tl-more')) closeMenus(st);
            if (!e.target.closest('.tl-legend-wrap')) {
                var lg = st.root.querySelector('#tlLegend');
                if (lg && !lg.hidden) {
                    lg.hidden = true;
                    var b = st.root.querySelector('[data-tl-legend]');
                    if (b) b.setAttribute('aria-expanded', 'false');
                }
            }
            if (st.sel && !st.sel.dragging && !e.target.closest('[data-tl-selpop], .tl-cell')) clearSel(st);
            if (st.root.classList.contains('is-filters-open') && e.target.closest('[data-tl-backdrop]')) toggleFilters(st, false);
        });
        var t = null;
        window.addEventListener('resize', function () {
            window.clearTimeout(t);
            t = window.setTimeout(function () {
                var st = live();
                if (!st) return;
                var allowed = allowedDays();
                if (allowed.indexOf(st.days) === -1) go(st, st.start, defaultDays());
                else if (st.mode === 'grid') render(st);
            }, 150);
        });
    }

    window.RoomTimeline = { init: init };
})();
