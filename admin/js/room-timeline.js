/**
 * Room Timeline (admin/calendar.php, Calendar tab). Tape chart: rooms down the side, days across,
 * one bar per stay (half-day ends), blocks / maintenance hatched, housekeeping icon per room, per-day
 * occupancy / arrivals / departures, a drawer (desktop, tablet) or bottom sheet (phone) per booking and a
 * phone Agenda. Move room and Change dates (in-house check-out) run here through the existing admin/bookings.php
 * actions only, always after a confirm (see "changes" below); every other action is a LINK to the page that does it.
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

        // Seam for the operations pages (Housekeeping, Maintenance; later Room Service): a root whose JSON says
        // "mode" other than booking is drawn by the mode registered in RoomTimeline.modes (admin/js/room-timeline-ops.js).
        // The booking timeline below is untouched by it. A mode not registered yet is left unbound: the ops file
        // binds waiting roots itself when it loads.
        var mode = (typeof cfg.mode === 'string' && cfg.mode) ? cfg.mode : 'booking';
        if (mode !== 'booking') {
            var adapter = Object.prototype.hasOwnProperty.call(MODES, mode) ? MODES[mode] : null;
            if (!adapter || typeof window.RoomTimeline.opsEngine !== 'function') return;
            root.setAttribute('data-tl-bound', '1');
            window.RoomTimeline.opsEngine(root, cfg, adapter);
            return;
        }
        root.setAttribute('data-tl-bound', '1');

        var ini = cfg.initial || {};
        var st = {
            root: root,
            data: cfg.data,
            api: cfg.api || 'api/calendar-timeline.php',
            start: cfg.data.start,
            days: cfg.data.days,
            // status: a room state carried in from bookings.php (calendar.php ?tl_status=), whitelisted again here.
            filters: { type: ini.type ? String(ini.type) : '', floor: ini.floor || '', status: ['free', 'occ', 'arr', 'dep', 'dirty', 'oos'].indexOf(ini.status) !== -1 ? ini.status : '', room: ini.room ? Number(ini.room) : 0, day: null },
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
        syncDock(null); // a panel left open by a timeline that was swapped out no longer holds the page's room
        if (!ini.days_set) st.days = defaultDays();
        if (allowedDays().indexOf(st.days) === -1) st.days = defaultDays();

        var typeSel = q(st, '[data-tl-filter="type"]');
        var floorSel = q(st, '[data-tl-filter="floor"]');
        if (typeSel) typeSel.value = st.filters.type;
        if (floorSel) floorSel.value = st.filters.floor;
        var statusSel = q(st, '[data-tl-filter="status"]');
        if (statusSel) statusSel.value = st.filters.status;
        var search = q(st, '[data-tl-search]');
        if (search) search.value = st.search;

        bindRoot(st);
        bindDoc();
        initPicks(st);
        go(st, st.start, st.days);
    }

    /* Show [start, start+days). Uses the loaded data when it covers the range, else asks the server.
       force: always ask the server (after a change was saved). */
    function go(st, start, days, force) {
        st.start = start;
        st.days = days;
        var dateIn = q(st, '[data-tl-date]');
        if (dateIn) dateIn.value = start;
        if (!force && covers(st.data, start, days)) {
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
        var roomW = vp === 'phone' ? 92 : (vp === 'tablet' ? 168 : 208);
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
        if (st.picks) st.picks.paint();
        renderAgenda(st);
        showMode(st);
        if (st.keepScroll) { // after a saved change: stay where the user was
            scroller.scrollTop = st.keepScroll.top;
            scroller.scrollLeft = st.keepScroll.left;
            if (Math.abs(window.scrollY - st.keepScroll.win) > 1) window.scrollTo(window.scrollX, st.keepScroll.win);
            st.keepScroll = null;
        }
        if (st.flash) {
            announce(st, st.flash);
            st.flash = '';
        } else {
            announce(st, 'Showing ' + fmtRange(dates[0].d, dates[n - 1].d) + '.');
        }
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
            var hkLink = null;
            if (d.hk_on) {
                var hk = el('span', 'tl-hk tl-hk--' + l.hk.state);
                hk.title = l.hk.label;
                hk.appendChild(icon({ clean: 'fa-check', dirty: 'fa-exclamation', cleaning: 'fa-ellipsis', inspection: 'fa-eye', blocked: 'fa-ban' }[l.hk.state] || 'fa-check'));
                if (d.can.hk_page && l.hk_href) {
                    // Its own link (a link inside the room link is not allowed): opens this room on the Housekeeping timeline.
                    hkLink = el('a', 'tl-hk-link');
                    hkLink.href = String(l.hk_href);
                    hkLink.setAttribute('aria-label', 'Housekeeping for room ' + l.number + ': ' + l.hk.label);
                    hkLink.title = l.hk.label + '. Open housekeeping for this room.';
                    hkLink.appendChild(hk);
                } else {
                    link.appendChild(hk);
                }
            }
            rc.appendChild(link);
            if (hkLink) rc.appendChild(hkLink);
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
                // Extend / shorten handle (GRID_UNIFY_PLAN Phase 3): only on a run of the room's OWN block that ends in view.
                if (bl.kind === 'blocked' && bl.scope === 'own' && d.can.block && window.RhExtend && z === dnum(bl.to) - st.first && bl.to >= d.today) {
                    blockGrip(st, blk, spec.lane, bl, a);
                }
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

        // What may be changed from here (server rules in b.drag + this user's permissions); drag is desktop only.
        var rules = rulesOf(st, b);
        var desk = canDrag();
        var dMove = desk && (rules.move || rules.dates);
        var dEnd = desk && (rules.end || rules.dates) && !it.cutE;
        var dStart = desk && rules.dates && !it.cutS;
        bar.setAttribute('draggable', 'false'); // never the browser's own drag and drop
        bar.setAttribute('data-tl-draggable', (dMove || dEnd) ? 'true' : 'false');
        if (dMove || dEnd) bar.classList.remove('tl-bar--locked');
        if (dMove) bar.classList.add('tl-bar--movable');

        var who = b.guest + (b.room_guest && b.room_guest !== b.guest ? ' (' + b.room_guest + ')' : '');
        var room = unassigned ? 'no room yet' : ('Room ' + (b.room_label || (lane ? lane.number : '')));
        var pay = b.pay.key === 'paid' ? 'paid' : (b.pay.key === 'partial' ? 'part paid' : (b.pay.key === 'refunded' ? 'refunded' : 'unpaid'));
        var sentence = who + ', ' + statusWord(b.status) + (b.missed ? ' (missed arrival)' : '') + (b.overdue ? ' (past check-out)' : '')
            + ', ' + fmtRange(b.ci, b.co) + ', ' + nightsWord(b.nights) + ', ' + room + ', ' + pay;
        var keys = (rules.move ? ' Press M to move room.' : '') + (rules.end ? ' Press D to change dates.' : '');
        bar.setAttribute('aria-label', sentence + '.' + keys);
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
        if (dStart) {
            var gs = el('span', 'tl-bar__grip tl-bar__grip--start');
            gs.setAttribute('aria-hidden', 'true');
            gs.title = 'Drag to change the check-in date';
            bar.appendChild(gs);
        }
        if (dEnd) {
            var grip = el('span', 'tl-bar__grip tl-bar__grip--end');
            grip.setAttribute('aria-hidden', 'true');
            grip.title = 'Drag to change the check-out date';
            bar.appendChild(grip);
        }
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

    /* keep: a step of a change in progress (Move room / Change dates); anything else drops that change first. */
    function openDrawer(st, title, buildBody, buildFoot, opener, keep) {
        if (st.busy) return false; // a save is running: its panel stays until it answers
        if (!keep && st.pending) dropChange(st);
        var dr = q(st, '[data-tl-drawer]');
        var bd = q(st, '[data-tl-backdrop]');
        closeMenus(st);
        if (!keep || !st.lastFocus) st.lastFocus = opener || document.activeElement;
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
        syncDock(st, refitGrid(st));
        // next frame: slide in (no motion under prefers-reduced-motion, see CSS)
        window.requestAnimationFrame(function () { dr.classList.add('is-open'); });
        var close = q(st, '[data-tl-drawer-close]');
        if (close) close.focus();
        return true;
    }
    function closeDrawer(st, restore) {
        if (!st || st.busy) return;
        if (st.pending) dropChange(st); // Cancel / Esc / close: the bar snaps back, nothing is saved
        var dr = q(st, '[data-tl-drawer]');
        if (!dr || dr.hidden) return;
        closeMenus(st);
        dr.classList.remove('is-open');
        dr.hidden = true;
        q(st, '[data-tl-backdrop]').hidden = true;
        st.root.querySelectorAll('.tl-bar.is-selected').forEach(function (b) { b.classList.remove('is-selected'); });
        syncDock(st, refitGrid(st));
        if (restore !== false && st.lastFocus && document.contains(st.lastFocus)) {
            try { st.lastFocus.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
        }
    }

    /* Owner 2026-11-23: on a laptop the side panel opens like the Assistant chat. html.rh-tl-open moves the floating
       Assistant, notice and "?" buttons to the left of the panel (any width, not the phone sheet); html.rh-tl-docked
       (screen 1025px+ and the page keeps DOCK_MIN_PAGE beside the sidebar and the panel) makes the page give up the
       panel's width on its right instead of being covered (room-timeline.css). The grid then re-fits its day columns:
       refit runs once the page has slid (falsy = the caller draws again itself). Shared with room-timeline-ops.js. */
    var DOCK_MIN_PAGE = 600;
    var refitTimer = 0;
    function syncDock(st, refit) {
        var root = document.documentElement;
        var dr = st ? q(st, '[data-tl-drawer]') : null;
        var open = !!dr && !dr.hidden && document.contains(dr) && !dr.classList.contains('tl-drawer--sheet');
        var zoom = parseFloat(window.getComputedStyle(root).zoom) || 1;
        var nav = document.querySelector('.admin-nav');
        var side = nav ? Math.max(0, nav.getBoundingClientRect().right) / zoom : 0;
        var w = parseFloat(window.getComputedStyle(root).getPropertyValue('--rh-tl-w')) || 400;
        var dock = open && window.innerWidth >= 1025 && (window.innerWidth / zoom - side - w) >= DOCK_MIN_PAGE;
        var was = root.classList.contains('rh-tl-docked');
        root.classList.toggle('rh-tl-open', open);
        root.classList.toggle('rh-tl-docked', dock);
        try { window.dispatchEvent(new Event('rh-dock-change')); } catch (e) { /* old browser: the pill re-places on its next resize */ }
        if (was === dock || typeof refit !== 'function' || !st) return;
        window.clearTimeout(refitTimer);
        refitTimer = window.setTimeout(function () { // after the page's 220ms slide (ai-panel.css)
            if (document.contains(st.root)) refit();
        }, 240);
    }
    /* Draw the grid again and keep its place: the open item's bar stays marked, the bar focus returns to on close is
       the new one, and a bar that had focus (the panel just closed) gets it back. attr = the bars' id attribute. */
    function redrawKeep(st, attr, draw, openId) {
        var a = document.activeElement;
        var focusId = a && st.root.contains(a) ? a.getAttribute(attr) : null;
        var lastId = st.lastFocus && st.lastFocus.getAttribute ? st.lastFocus.getAttribute(attr) : null;
        draw();
        var dr = q(st, '[data-tl-drawer]');
        var open = dr && !dr.hidden;
        st.root.querySelectorAll('[' + attr + ']').forEach(function (bar) {
            var id = bar.getAttribute(attr);
            if (open && openId != null && id === String(openId)) bar.classList.add('is-selected');
            if (lastId !== null && id === lastId && !document.contains(st.lastFocus)) st.lastFocus = bar;
            if (focusId !== null && id === focusId) { try { bar.focus({ preventScroll: true }); } catch (e) { /* ignore */ } }
        });
    }
    function refitGrid(st) {
        return function () {
            if (cur !== st || st.mode !== 'grid' || st.busy || st.pending || st.drag) return;
            redrawKeep(st, 'data-tl-booking', function () { render(st); }, st.drawerBooking ? st.drawerBooking.id : null);
        };
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
        if (st.busy) return;
        if (bar) bar.classList.add('is-selected');
        st.drawerBooking = b;
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
            changeNotes(st, b).forEach(function (t) { body.appendChild(el('p', 'tl-drawer__note', t)); });
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
            var rules = rulesOf(st, b);
            // Move room / Change dates run here when the timeline may do them (panel, then a confirm); otherwise
            // they stay links to the booking forms. The 4th item is the panel the link opens instead.
            if (rules.move) more.push(['bookings.php?action=' + (unassigned ? 'assign-room' : 'room-change') + '&booking_id=' + b.id, unassigned ? 'Choose a room' : 'Move room', 'fa-right-left', 'move']);
            else if (!unassigned && canRoom && can.move) more.push(['bookings.php?action=room-change&booking_id=' + b.id, 'Move room', 'fa-right-left']);
            if (rules.end) more.push(['edit-booking.php?id=' + b.id, 'Change dates', 'fa-calendar-pen', 'dates']);
            else if (can.edit_dates) more.push(['edit-booking.php?id=' + b.id, 'Change dates', 'fa-calendar-pen']);
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
                    if (m[3]) {
                        a.setAttribute('data-tl-act', m[3]);
                        a.setAttribute('data-tl-act-id', String(b.id));
                    }
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

    /* ---------------------------------------------------------------- changes (existing bookings.php actions only)
       Move room: get_available_rooms (precheck), then assign_individual_room (no money). A confirmed booking dropped on a
       room of another type: room_change_options (price + availability), then upgrade_room_type (charge pricing, the
       upgrade flow that reprices) and assign_individual_room for the room it was dropped on.
       Change dates (in-house, end of stay only): stay_nights_quote, then stay_nights_apply with the quoted figures.
       Nothing is saved before Confirm; Confirm runs once (st.busy) and every rule is checked again by bookings.php. */
    var mqFine = window.matchMedia('(pointer: fine)');
    function canDrag() { return mqFine.matches && !mqCoarse.matches && window.innerWidth > 1024; }
    function rulesOf(st, b) {
        var dr = b.drag || {}, can = st.data.can || {};
        return { move: !!dr.move && !!can.move, end: !!dr.end && !!can.nights, dates: !!dr.dates && !!can.edit_dates && !!can.open_booking, why: dr.why || '' };
    }
    function changeNotes(st, b) {
        var dr = b.drag || {}, r = rulesOf(st, b), out = [];
        if (!dr.move && !dr.end && !dr.dates) return [dr.why || 'This booking cannot be changed here. Open the booking.'];
        if (!r.move && !r.end && !r.dates) return ['Your role cannot change this booking from the timeline.'];
        var drag = canDrag();
        if (r.dates && drag) out.push('To change the dates, drag the bar sideways or drag either end. The booking\'s Change dates screen then opens with the new dates and price; nothing is saved here.');
        if (r.move) out.push(drag ? 'To move this stay, drag the bar to another room, or use More, Move room.' : 'To move this stay, use More, Move room.');
        if (r.end) out.push(drag ? 'To change the check-out, drag the right edge of the bar, or use More, Change dates.' : 'To change the check-out, use More, Change dates.');
        if (dr.why) out.push(dr.why);
        return out;
    }
    function dot(s) { s = String(s || '').trim(); return s === '' || /[.!?]$/.test(s) ? s : s + '.'; }
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function toast(st, msg, kind) {
        announce(st, msg);
        // Alert.show renders HTML; server messages can carry staff-typed text (e.g. "Reason: ..."), so escape it.
        if (window.Alert && typeof window.Alert.show === 'function') window.Alert.show(escHtml(msg), kind || 'info');
    }
    function moneyTol(st) {
        var t = Number(st && st.data ? st.data.tolerance : NaN);
        return t > 0 ? t : 0.01;
    }

    /* POST one existing action to admin/bookings.php (its CSRF, deleted-booking and permission checks run first).
       Resolves with the JSON answer; rejects with the server's message (it answers 200 + success:false). */
    function postAction(fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, String(fields[k])); });
        var meta = document.querySelector('meta[name="csrf-token"]');
        var tok = window._rhCsrf || (meta ? (meta.getAttribute('content') || '') : '');
        if (tok) fd.append('csrf_token', tok);
        var ctl = window.AbortController ? new AbortController() : null;
        var tid = ctl ? window.setTimeout(function () { ctl.abort(); }, 45000) : 0;
        return fetch(new URL('bookings.php', window.location.href).pathname, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            signal: ctl ? ctl.signal : undefined,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) {
            window.clearTimeout(tid);
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) {
                throw new Error('The server did not confirm this. Reload the page and check the booking before trying again.');
            }
            return r.json().then(function (j) {
                if (!r.ok || !j || j.success === false) throw new Error(dot((j && (j.message || j.error)) || 'That did not work. Try again.'));
                return j;
            });
        }, function (err) {
            window.clearTimeout(tid);
            throw new Error(err && err.name === 'AbortError'
                ? 'The server took too long to answer. Reload the page and check the booking before trying again.'
                : 'Could not reach the server. Check the connection and try again.');
        });
    }

    /* Drop a change that was not confirmed: the bar snaps back. */
    function dropChange(st) {
        st.pending = null;
        endDrag(st);
    }
    function endDrag(st) {
        st.root.querySelectorAll('.tl-bar--ghost').forEach(function (g) { g.remove(); });
        st.root.querySelectorAll('.tl-bar.is-dragging').forEach(function (x) { x.classList.remove('is-dragging'); });
        st.root.classList.remove('is-dragging');
        clearDrop(st);
    }
    function clearDrop(st) {
        st.root.querySelectorAll('.tl-cell--drop-ok, .tl-cell--drop-bad').forEach(function (c) {
            c.classList.remove('tl-cell--drop-ok', 'tl-cell--drop-bad');
        });
    }
    function markDrop(st, row, from, to, ok) { // day numbers, both included
        clearDrop(st);
        if (!row) return;
        var last = st.first + st.dates.length - 1;
        for (var dn = Math.max(from, st.first); dn <= Math.min(to, last); dn++) {
            var c = row.querySelector('.tl-cell[data-tl-day="' + (dn - st.first) + '"]');
            if (c) c.classList.add(ok ? 'tl-cell--drop-ok' : 'tl-cell--drop-bad');
        }
    }
    /* Nights from..to (day numbers, both included) free on this lane in the loaded data. A hint for the drop colour;
       the server precheck decides. */
    function laneFree(st, lane, b, from, to) {
        if (to < from) return true;
        var blocked = (st.blocksByLane[lane.id] || []).some(function (bl) { return dnum(bl.from) <= to && dnum(bl.to) >= from; });
        if (blocked) return false;
        return !(st.lanesBookings[lane.id] || []).some(function (o) {
            return o.id !== b.id && dnum(o.ci) <= to && dnum(o.eco) - 1 >= from;
        });
    }
    function moveVerdict(st, b, row) {
        if (!row || !row.hasAttribute('data-tl-lane')) return null;
        var lane = st.lanesById[row.getAttribute('data-tl-lane')];
        if (!lane || b.lanes.indexOf(lane.id) !== -1) return null;
        var v = { lane: lane, row: row, ok: true, why: '' };
        if (lane.joined) {
            v.ok = false;
            v.why = 'Room ' + lane.number + ' is a pair of joined rooms. To book a pair, change the room type from the booking.';
        } else if (!laneFree(st, lane, b, dnum(b.ci), dnum(b.co) - 1)) {
            v.ok = false;
            v.why = 'Room ' + lane.number + ' is taken or blocked on some of these nights.';
        }
        return v;
    }
    function placeGhost(st, d, row, left, width) {
        if (!d.ghost) {
            d.ghost = el('div', 'tl-bar tl-bar--ghost tl-bar--' + d.b.status);
            d.ghost.setAttribute('aria-hidden', 'true');
            d.ghost.appendChild(el('span', 'tl-bar__name', d.b.guest));
        }
        var track = row.querySelector('.tl-track');
        if (!track) return;
        if (d.ghost.parentNode !== track) track.appendChild(d.ghost);
        d.ghost.style.left = left + 'px';
        d.ghost.style.width = Math.max(12, width) + 'px';
        d.ghost.style.top = '4px';
    }

    function beginDrag(st, e, bar) {
        if (e.pointerType !== 'mouse' || !canDrag() || st.busy || st.pending) return;
        if (bar.getAttribute('data-tl-draggable') !== 'true') return;
        var b = st.byId[bar.getAttribute('data-tl-booking')];
        if (!b) return;
        var r = rulesOf(st, b);
        var kind = e.target.closest('.tl-bar__grip--end') ? (r.end ? 'end' : (r.dates ? 'co' : null))
            : (e.target.closest('.tl-bar__grip--start') ? (r.dates ? 'ci' : null) : ((r.move || r.dates) ? 'move' : null));
        if (!kind) return;
        st.drag = { kind: kind, rules: r, bar: bar, b: b, row: bar.closest('.tl-lane'), x: e.clientX, y: e.clientY, id: e.pointerId, started: false };
    }
    /* Pointer x -> night, measured on the RENDERED track (its width / number of nights, the same pixels as the pointer),
       never on the CSS day width: at a display size below 100% (html zoom) the two differ and the night before was
       named (owner 2026-11-24). Without room-timeline-select.js the old measure stays. */
    function dayUnder(st, track, x) { // day number under x, not clamped (callers clamp); null = no rendered track
        var tr = track.getBoundingClientRect(), L = window.RhGridSel && window.RhGridSel.logic;
        var c = L ? L.colOf(x, tr.left, tr.width, st.dates.length) : Math.floor((x - tr.left) / st.dayW);
        return c === null ? null : st.first + c;
    }
    function nightsMoved(st, row, dx) { // whole nights a sideways drag moved on that row
        var track = row && row.querySelector('.tl-track'), L = window.RhGridSel && window.RhGridSel.logic;
        if (!track || !L) return Math.round(dx / st.dayW) || 0;
        return L.colsMoved(dx, track.getBoundingClientRect().width, st.dates.length);
    }
    function dragMove(st, e) {
        var d = st.drag;
        if (!d.started) {
            if (Math.abs(e.clientX - d.x) < 4 && Math.abs(e.clientY - d.y) < 4) return;
            d.started = true;
            d.bar.classList.add('is-dragging');
            st.root.classList.add('is-dragging');
            clearSel(st);
            try { q(st, '[data-tl-grid]').setPointerCapture(d.id); } catch (err) { /* not supported: moves still arrive */ }
        }
        e.preventDefault();
        var left = parseFloat(d.bar.style.left) || 0, width = parseFloat(d.bar.style.width) || 0;
        if (d.kind === 'ci' || d.kind === 'co') { datesEdgeMove(st, d, e, left, width); return; }
        if (d.kind === 'move') {
            var hit = document.elementFromPoint(e.clientX, e.clientY);
            var row = hit && hit.closest('.tl-lane');
            if (row && !q(st, '[data-tl-grid]').contains(row)) row = null;
            // Sideways on a confirmed stay = the same nights on other dates (Change dates). Another row = Move room.
            d.shift = d.rules.dates ? nightsMoved(st, d.row, e.clientX - d.x) : 0;
            if (d.shift !== 0) {
                var sameRow = !row || row === d.row || (row.hasAttribute('data-tl-lane') && d.b.lanes.indexOf(row.getAttribute('data-tl-lane')) !== -1);
                d.target = null;
                var nci = dnum(d.b.ci) + d.shift, nco = dnum(d.b.co) + d.shift;
                d.datesBad = !sameRow ? 'Change the dates and the room one at a time: drop the bar on its own row to change the dates, or on another row at the same dates to move it.'
                    : (nci < dnum(st.data.today) ? 'The new check-in would be in the past.' : '');
                d.newCi = nci;
                d.newCo = nco;
                placeGhost(st, d, d.row, left + d.shift * st.dayW, width);
                d.ghost.classList.toggle('tl-bar--ghost-bad', !!d.datesBad);
                markDrop(st, d.row, nci, nco - 1, !d.datesBad);
                return;
            }
            if (!d.rules.move) { d.target = null; clearDrop(st); if (d.ghost) d.ghost.remove(); d.ghost = null; return; }
            d.target = moveVerdict(st, d.b, row);
            if (!row) { clearDrop(st); return; }
            placeGhost(st, d, row, left, width);
            d.ghost.classList.toggle('tl-bar--ghost-bad', !!(d.target && !d.target.ok));
            if (d.target) markDrop(st, row, dnum(d.b.ci), dnum(d.b.co) - 1, d.target.ok);
            else clearDrop(st);
            return;
        }
        // End edge: the check-out moves to the day under the pointer (never before tomorrow's first night).
        var track = d.row && d.row.querySelector('.tl-track');
        if (!track) return;
        var last = st.first + st.dates.length - 1;
        var co = dayUnder(st, track, e.clientX);
        if (co === null) return;
        co = Math.min(last, Math.max(co, dnum(d.b.ci) + 1, dnum(st.data.today)));
        d.newCo = co;
        placeGhost(st, d, d.row, left, (co - st.first + 0.5) * st.dayW - 2 - left);
        var oldCo = dnum(d.b.co);
        var lane = d.b.lanes.length ? st.lanesById[d.b.lanes[0]] : null;
        var okEnd = co <= oldCo || !lane || laneFree(st, lane, d.b, oldCo, co - 1);
        d.ghost.classList.toggle('tl-bar--ghost-bad', !okEnd);
        if (co === oldCo) clearDrop(st);
        else markDrop(st, d.row, Math.min(co, oldCo), Math.max(co, oldCo) - 1, okEnd);
    }
    function dragEnd(st) {
        var d = st.drag;
        st.drag = null;
        if (!d || !d.started) return;
        st.suppressClick = true;
        window.setTimeout(function () { st.suppressClick = false; }, 0);
        if (d.kind === 'ci' || d.kind === 'co' || (d.kind === 'move' && d.shift)) {
            if (d.datesBad) { endDrag(st); toast(st, d.datesBad, 'warning'); return; }
            var nci2 = d.kind === 'co' ? dnum(d.b.ci) : d.newCi, nco2 = d.kind === 'ci' ? dnum(d.b.co) : d.newCo;
            if (nci2 === undefined || nco2 === undefined || (nci2 === dnum(d.b.ci) && nco2 === dnum(d.b.co))) { endDrag(st); return; }
            handOffDates(st, d.b, nci2, nco2);
            return;
        }
        if (d.kind === 'move') {
            var v = d.target;
            if (!v) { endDrag(st); return; }
            if (!v.ok) { endDrag(st); toast(st, v.why, 'warning'); return; }
            startMove(st, d.b, v.lane, d.bar);
            return;
        }
        var delta = (d.newCo || dnum(d.b.co)) - dnum(d.b.co);
        if (!delta) { endDrag(st); return; }
        startNights(st, d.b, delta, d.bar);
    }

    /* M / D on a focused bar, and the drawer's More items. */
    function keyChange(st, b, bar, what) {
        var r = rulesOf(st, b);
        if (what === 'move' && r.move) { st.drawerBooking = b; showMovePanel(st, b, bar); return; }
        if (what === 'dates' && r.end) { st.drawerBooking = b; showDatesPanel(st, b, bar); return; }
        openBooking(st, b, bar);
        announce(st, changeNotes(st, b)[0] || '');
    }

    /* A change cannot be made here: say why, offer the booking. The dragged bar snaps back at once. */
    function refuse(st, b, msg, opener) {
        endDrag(st);
        st.pending = null;
        openDrawer(st, b.guest, function (body) {
            var p = el('p', 'tl-confirm__error', dot(msg));
            p.setAttribute('role', 'alert');
            body.appendChild(p);
        }, function (foot) {
            if (st.data.can.open_booking) foot.appendChild(linkBtn('btn btn-primary', 'booking-details.php?id=' + b.id, 'Open booking'));
            var c = el('button', 'btn btn-secondary', 'Close');
            c.type = 'button';
            c.setAttribute('data-tl-cancel', '1');
            foot.appendChild(c);
        }, opener, true);
    }
    function waitPanel(st, title, text, opener) {
        openDrawer(st, title, function (body) {
            body.appendChild(el('p', 'tl-muted', text));
        }, function (foot) {
            var c = el('button', 'btn btn-secondary', 'Cancel');
            c.type = 'button';
            c.setAttribute('data-tl-cancel', '1');
            foot.appendChild(c);
        }, opener, true);
    }

    /* The confirm step: the sentence, the figures, any reason fields, Cancel / Confirm. cfg.run(values) returns a
       Promise of { message, kind }; values comes from cfg.fields(body) (a getter that throws a plain message). */
    function showConfirm(st, cfg) {
        var errEl = null, btn = null, getVals = function () { return {}; };
        var label = cfg.submit || 'Confirm';
        openDrawer(st, cfg.title, function (body) {
            body.appendChild(el('p', 'tl-confirm__text', cfg.text));
            var list = el('dl', 'tl-dl');
            cfg.lines.forEach(function (ln) { // [label, value, optional "?" helper text]
                if (!ln) return;
                dl(list, ln[0], ln[1]);
                if (ln[2]) list.lastChild.querySelector('dt').appendChild(help(ln[2]));
            });
            body.appendChild(list);
            if (cfg.fields) getVals = cfg.fields(body);
            errEl = el('p', 'tl-confirm__error');
            errEl.setAttribute('role', 'alert');
            errEl.hidden = true;
            body.appendChild(errEl);
        }, function (foot) {
            var c = el('button', 'btn btn-secondary', 'Cancel');
            c.type = 'button';
            c.setAttribute('data-tl-cancel', '1');
            foot.appendChild(c);
            btn = el('button', 'btn btn-primary', label);
            btn.type = 'button';
            btn.setAttribute('data-tl-confirm', '1');
            foot.appendChild(btn);
        }, cfg.opener, true);
        function showErr(m) {
            errEl.textContent = dot(m);
            errEl.hidden = false;
        }
        st.pending.confirm = function () {
            if (st.busy) return; // single flight: a second click while saving does nothing
            var vals;
            try { vals = getVals(); } catch (e) { showErr(e.message); return; }
            st.busy = true;
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.textContent = 'Saving...';
            errEl.hidden = true;
            var cancelBtn = st.root.querySelector('[data-tl-drawer-foot] [data-tl-cancel]');
            if (cancelBtn) cancelBtn.disabled = true;
            Promise.resolve().then(function () { return cfg.run(vals); }).then(function (res) {
                st.busy = false;
                finishChange(st, res.message, res.kind || 'success');
            }, function (e) {
                st.busy = false;
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
                btn.textContent = label;
                if (cancelBtn) cancelBtn.disabled = false;
                showErr(e.message || 'That did not work. Try again.');
            });
        };
        if (btn) btn.focus();
    }
    function finishChange(st, msg, kind) {
        st.pending = null;
        closeDrawer(st, false);
        endDrag(st);
        toast(st, msg, kind);
        st.flash = msg;
        var sc = q(st, '[data-tl-scroller]');
        st.keepScroll = sc ? { top: sc.scrollTop, left: sc.scrollLeft, win: window.scrollY } : null;
        go(st, st.start, st.days, true);
    }

    /* ---- Move room */
    function roomLabel(r) { return String(r.room_number || r.label || r.id); }
    function roomsOfType(b, typeId) {
        return postAction({ action: 'get_available_rooms', room_type_id: typeId, check_in: b.ci, check_out: b.co, exclude_booking_id: b.id })
            .then(function (j) {
                if (j.mode && j.mode !== 'rooms') throw new Error('Rooms of this type are booked as joined pairs. Change it from the booking.');
                return j.data || [];
            });
    }
    /* The server's allowed list for this room: the same list the Assign room picker shows. */
    function checkRoom(list, roomId, number) {
        var hit = null;
        list.forEach(function (r) { if (Number(r.id) === Number(roomId)) hit = r; });
        if (!hit) throw new Error('Room ' + number + ' is not a room this booking can use.');
        if (hit.current) throw new Error('The booking already has Room ' + number + '.');
        if (!hit.available) throw new Error('Room ' + number + ' cannot take this stay: ' + (hit.reason || 'it is not free on these nights'));
        if (hit.requires_child_override) throw new Error('Room ' + number + ' does not take children. Assign it from the booking, where the override note is asked for.');
        return hit;
    }
    function precheckMove(st, b, lane) {
        if (lane.type_id === b.type_id) {
            return roomsOfType(b, b.type_id).then(function (list) { return { cross: false, room: checkRoom(list, lane.id, lane.number) }; });
        }
        if (b.status !== 'confirmed') return Promise.reject(new Error('Only a confirmed booking can change room type here. Open the booking.'));
        return postAction({ action: 'room_change_options', booking_id: b.id }).then(function (j) {
            var data = j.data || {}, opt = null;
            (data.options || []).forEach(function (o) { if (Number(o.id) === Number(lane.type_id)) opt = o; });
            if (!opt) throw new Error('This booking cannot change to the room type of Room ' + lane.number + '.');
            if (!opt.available) throw new Error(opt.name + ': ' + (opt.unavailable_reason || 'not available for these nights'));
            // A cheaper type that leaves the guest paid above the new bill creates a credit (money back): that needs
            // the booking's Change room type dialog, which explains the refund / credit note. Never from the calendar.
            var tol = moneyTol(st), q = opt.quote || {};
            var paid = Number((data.booking || {}).amount_paid || 0);
            if (Number(q.charge_delta || 0) < -tol && paid > Number(q.charge_total || 0) - tol) {
                throw new Error('The guest has already paid more than the ' + opt.name + ' price, so this move would leave money to give back. Do this from the booking (Change room type), where the refund or credit note is handled.');
            }
            return roomsOfType(b, lane.type_id).then(function (list) {
                return { cross: true, room: checkRoom(list, lane.id, lane.number), opt: opt };
            });
        });
    }
    function startMove(st, b, lane, opener) {
        var token = { b: b };
        st.pending = token;
        waitPanel(st, 'Move room', 'Checking Room ' + lane.number + ' for ' + fmtRange(b.ci, b.co) + '...', opener);
        precheckMove(st, b, lane).then(function (plan) {
            if (st.pending !== token) return;
            confirmMove(st, b, lane, plan, opener);
        }, function (err) {
            if (st.pending !== token) return;
            refuse(st, b, err.message, opener);
        });
    }
    function confirmMove(st, b, lane, plan, opener) {
        var from = b.lanes.length ? 'Room ' + (b.room_label || '') : 'no room yet';
        var text = 'Move ' + b.guest + ' (' + b.ref + ') from ' + from + ' to Room ' + lane.number + ', '
            + fmtRange(b.ci, b.co) + ', ' + nightsWord(b.nights) + '.';
        var lines = [['Dates', fmtRange(b.ci, b.co) + ' (no change)']];
        var opt = plan.opt, qt = opt ? opt.quote : null;
        if (plan.cross) {
            lines.push(['Room type', (b.type_name || 'Current type') + ' to ' + opt.name]);
            var dlt = Number(qt.charge_delta || 0);
            lines.push(['Price', qt.direction === 'same'
                ? 'No change (same rate)'
                : money(st, qt.current_total) + ' to ' + money(st, qt.charge_total) + ' (' + (dlt > 0 ? '+' : '-') + money(st, Math.abs(dlt)) + ')',
                'The stay total now and after the change, at the new room type\'s rate for the same nights (the Change room type quote).']);
            lines.push(['Email to guest', 'Not sent (send it from the booking if needed)']);
        } else {
            lines.push(['Price', 'No change', 'Same room type, same nights: giving the booking another room never changes the bill.']);
        }
        if (b.status === 'confirmed' && lane.hk && lane.hk.state !== 'clean') lines.push(['Housekeeping', 'Room ' + lane.number + ': ' + lane.hk.label + ' (it must be ready by check-in)']);
        showConfirm(st, {
            title: 'Move room',
            text: text,
            lines: lines,
            opener: opener,
            fields: plan.cross ? function (body) { return reasonFields(body, opt); } : null,
            run: function (vals) {
                var assign = function () {
                    return postAction({ action: 'assign_individual_room', booking_id: b.id, individual_room_id: lane.id });
                };
                if (!plan.cross) {
                    return assign().then(function (j) { return { message: dot(j.message || ('Moved to Room ' + lane.number)) }; });
                }
                return postAction({
                    action: 'upgrade_room_type', booking_id: b.id, new_room_id: lane.type_id, pricing: 'charge',
                    reason_code: vals.reason, note: vals.note, send_email: '0'
                }).then(function (j1) {
                    // The type change is saved (and gave the booking a room of the new type); now the room it was
                    // dropped on. If that fails the type change stands: say so, never retry the paid part.
                    return assign().then(function () {
                        return { message: dot(j1.message || 'Room type changed') + ' Room ' + lane.number + ' assigned.' };
                    }, function (e2) {
                        return { message: dot(j1.message || 'Room type changed') + ' Room ' + lane.number + ' could not be given: ' + dot(e2.message) + ' Check the room on the booking.', kind: 'warning' };
                    });
                });
            }
        });
    }
    /* Reason (+ note) for a room type change, as the Change room type form asks them (rh_room_change_reasons). */
    function reasonFields(body, opt) {
        var wrap = el('div', 'tl-confirm__fields');
        var l1 = el('label', 'tl-field');
        l1.appendChild(el('span', 'tl-field__label', 'Reason for the change'));
        var sel = el('select', 'form-control');
        sel.appendChild(el('option', null, 'Choose a reason'));
        sel.firstChild.value = '';
        Object.keys(opt.reasons || {}).forEach(function (k) {
            var o = el('option', null, opt.reasons[k]);
            o.value = k;
            sel.appendChild(o);
        });
        l1.appendChild(sel);
        wrap.appendChild(l1);
        var l2 = el('label', 'tl-field');
        var noteNeeded = function () { return opt.quote.direction === 'downgrade' || /_other$/.test(sel.value); };
        var noteLab = el('span', 'tl-field__label', 'Note');
        l2.appendChild(noteLab);
        var ta = el('textarea', 'form-control');
        ta.rows = 2;
        ta.maxLength = 500;
        l2.appendChild(ta);
        wrap.appendChild(l2);
        var sync = function () { noteLab.textContent = noteNeeded() ? 'Note (required: who authorised it and why)' : 'Note (optional)'; };
        sel.addEventListener('change', sync);
        sync();
        body.appendChild(wrap);
        return function () {
            if (!sel.value) throw new Error('Choose a reason for the room change.');
            var note = String(ta.value || '').trim();
            if (noteNeeded() && note.length < 5) throw new Error('Add a short note explaining the room change (who authorised it and why).');
            return { reason: sel.value, note: note };
        };
    }
    /* Touch, tablet, phone and keyboard: the rooms of this type the booking may move to, then the same confirm. */
    function showMovePanel(st, b, opener) {
        var token = { b: b };
        st.pending = token;
        waitPanel(st, b.lanes.length ? 'Move room' : 'Choose a room', 'Finding free rooms for ' + fmtRange(b.ci, b.co) + '...', opener);
        roomsOfType(b, b.type_id).then(function (list) {
            if (st.pending !== token) return;
            var ok = list.filter(function (r) { return r.available && !r.current && !r.requires_child_override; });
            var other = list.filter(function (r) { return !r.current; }).length - ok.length;
            token.pick = function (id) {
                var r = null;
                ok.forEach(function (x) { if (String(x.id) === String(id)) r = x; });
                if (!r) return;
                var lane = st.lanesById[r.id] || { id: Number(r.id), number: roomLabel(r), type_id: b.type_id, hk: null };
                confirmMove(st, b, lane, { cross: false, room: r }, opener);
            };
            openDrawer(st, b.lanes.length ? 'Move room' : 'Choose a room', function (body) {
                body.appendChild(el('p', 'tl-confirm__text', b.guest + ' (' + b.ref + '), ' + fmtRange(b.ci, b.co) + ', ' + nightsWord(b.nights)
                    + (b.lanes.length ? ', now in Room ' + (b.room_label || '') : '') + '.'));
                if (!ok.length) {
                    body.appendChild(el('p', 'tl-drawer__note', 'No other ' + (b.type_name || 'room') + ' room is free for these nights.'));
                } else {
                    body.appendChild(el('p', 'tl-muted', 'Free ' + (b.type_name || '') + ' rooms for these nights (the price does not change):'));
                    var ul = el('ul', 'tl-pick');
                    ok.forEach(function (r) {
                        var li = el('li');
                        var btn = el('button', 'tl-pick__btn');
                        btn.type = 'button';
                        btn.setAttribute('data-tl-room-pick', String(r.id));
                        btn.appendChild(el('span', 'tl-pick__num', 'Room ' + roomLabel(r)));
                        var extra = [];
                        if (r.floor) extra.push('Floor ' + r.floor);
                        if (r.turnover) extra.push(String(r.turnover));
                        if (extra.length) btn.appendChild(el('span', 'tl-pick__meta', extra.join(', ')));
                        li.appendChild(btn);
                        ul.appendChild(li);
                    });
                    body.appendChild(ul);
                }
                if (other > 0) body.appendChild(el('p', 'tl-muted', other + ' other ' + (other === 1 ? 'room is' : 'rooms are') + ' taken, blocked or not suitable.'));
            }, function (foot) {
                var c = el('button', 'btn btn-secondary', 'Cancel');
                c.type = 'button';
                c.setAttribute('data-tl-cancel', '1');
                foot.appendChild(c);
                if (st.data.can.move && b.status === 'confirmed' && b.lanes.length) {
                    foot.appendChild(linkBtn('btn btn-secondary', 'bookings.php?action=room-change&booking_id=' + b.id, 'Change room type'));
                }
            }, opener, true);
        }, function (err) {
            if (st.pending !== token) return;
            refuse(st, b, err.message, opener);
        });
    }

    /* ---- Change dates (in-house: add / take off nights at the end) */
    function showDatesPanel(st, b, opener) {
        var token = { b: b };
        st.pending = token;
        var minCo = dstr(Math.max(dnum(b.ci) + 1, dnum(st.data.today)));
        var input = null, errEl = null;
        token.dates = function () {
            var v = input ? input.value : '';
            if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) { errEl.textContent = 'Pick the new check-out date.'; errEl.hidden = false; return; }
            if (v < minCo) { errEl.textContent = 'The check-out cannot be before ' + fmt(minCo) + '.'; errEl.hidden = false; return; }
            var delta = dnum(v) - dnum(b.co);
            if (!delta) { errEl.textContent = 'That is the current check-out. Pick another date.'; errEl.hidden = false; return; }
            if (Math.abs(delta) > 60) { errEl.textContent = 'Change at most 60 nights at a time.'; errEl.hidden = false; return; }
            startNights(st, b, delta, opener);
        };
        openDrawer(st, 'Change dates', function (body) {
            body.appendChild(el('p', 'tl-confirm__text', b.guest + ' (' + b.ref + ') checked in on ' + fmt(b.ci) + ' and checks out on ' + fmt(b.co) + '.'));
            var lab = el('label', 'tl-field');
            lab.appendChild(el('span', 'tl-field__label', 'New check-out date'));
            input = el('input', 'form-control');
            input.type = 'date';
            input.min = minCo;
            input.value = b.co;
            lab.appendChild(input);
            body.appendChild(lab);
            body.appendChild(el('p', 'tl-muted', 'The check-in date cannot be changed here. The next step shows the new total before anything is saved.'));
            errEl = el('p', 'tl-confirm__error');
            errEl.setAttribute('role', 'alert');
            errEl.hidden = true;
            body.appendChild(errEl);
        }, function (foot) {
            var c = el('button', 'btn btn-secondary', 'Cancel');
            c.type = 'button';
            c.setAttribute('data-tl-cancel', '1');
            foot.appendChild(c);
            var go2 = el('button', 'btn btn-primary', 'Show the new total');
            go2.type = 'button';
            go2.setAttribute('data-tl-dates-go', '1');
            foot.appendChild(go2);
        }, opener, true);
        if (input) input.focus();
    }
    function startNights(st, b, delta, opener) {
        var token = { b: b };
        st.pending = token;
        waitPanel(st, 'Change dates', 'Working out the new total...', opener);
        postAction({ action: 'stay_nights_quote', booking_id: b.id, delta: delta, un_fee_override: '', un_fee_reason: '' }).then(function (j) {
            if (st.pending !== token) return;
            var qt = j.quote;
            if (!qt) throw new Error('Could not work out the new total.');
            if (Number(qt.delta) > 0 && qt.available === false) {
                throw new Error('Not available: ' + (qt.availability_error || 'the room is taken on the added nights'));
            }
            if (Number(qt.money_back || 0) > 0.01) {
                throw new Error(money(st, qt.money_back) + ' was paid for the nights taken off and goes back to the guest. Do this from the booking, where the refund or credit note is chosen');
            }
            confirmNights(st, b, qt, opener);
        }).catch(function (err) {
            if (st.pending !== token) return;
            refuse(st, b, err.message, opener);
        });
    }
    function confirmNights(st, b, qt, opener) {
        var n = Math.abs(Number(qt.delta));
        var verb = Number(qt.delta) > 0 ? 'Add ' + nightsWord(n) + ' to' : 'Take ' + nightsWord(n) + ' off';
        var text = verb + ' the stay of ' + b.guest + ' (' + b.ref + '): check-out ' + qt.old_check_out_fmt + ' to ' + qt.new_check_out_fmt
            + ', ' + qt.old_nights + ' to ' + qt.new_nights + ' nights.';
        var lines = [
            ['Check-out', qt.old_check_out_fmt + ' to ' + qt.new_check_out_fmt],
            ['Nights', qt.old_nights + ' to ' + qt.new_nights],
            ['Price', money(st, qt.old_total) + ' to ' + money(st, qt.new_total), 'The room charge for the whole stay, now and with the new check-out. It is worked out again when you confirm: if it changed, nothing is saved.'],
            Number(qt.folio || 0) > 0.01 ? ['Extras (unchanged)', money(st, qt.folio), 'Food, drinks and other charges on the guest bill. Changing nights does not touch them.'] : null,
            ['Paid so far', money(st, qt.paid), 'Everything paid on this booking to date.'],
            ['Balance owed', money(st, qt.old_due) + ' to ' + money(st, qt.new_due), 'Room charge plus extras (and any fee kept for nights taken off), less what has been paid: now and after the change.'],
            qt.plan_note ? ['Discount', qt.plan_note] : null,
            (qt.unused && qt.unused.unused > 0 && qt.unused.text) ? ['Unused nights', qt.unused.text] : null,
            qt.pay_later ? ['Pay-later booking', 'The balance stays owed under the agreed arrangement.'] : null,
            Number(qt.delta) > 0 ? ['Room availability', 'Checked: the room is free on the added nights.'] : null
        ];
        showConfirm(st, {
            title: 'Change dates',
            text: text,
            lines: lines,
            opener: opener,
            fields: function (body) {
                var lab = el('label', 'tl-field');
                lab.appendChild(el('span', 'tl-field__label', 'Reason (required)'));
                var inp = el('input', 'form-control');
                inp.type = 'text';
                inp.maxLength = 255;
                inp.setAttribute('data-tl-reason', '1');
                lab.appendChild(inp);
                body.appendChild(lab);
                return function () {
                    var r = String(inp.value || '').trim();
                    if (r.length < 3) throw new Error('Say why the nights are changing (the reason is required).');
                    return { reason: r };
                };
            },
            run: function (vals) {
                var un = qt.unused;
                return postAction({
                    action: 'stay_nights_apply', booking_id: b.id, delta: qt.delta,
                    expected_new_total: qt.new_total, expected_total_delta: qt.total_delta, reason: vals.reason,
                    un_fee_override: '', un_fee_reason: '',
                    un_fee_quoted: (un && un.overridable) ? Number(un.amount).toFixed(2) : ''
                }).then(function (j) { return { message: dot(j.message || 'Stay updated') }; });
            }
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
    /* ---------------------------------------------------------------- selected nights (room-timeline-select.js)
       Click / drag / Shift / Ctrl / room name / day head / keyboard / long press pick nights in any rooms; the bulk bar
       and the right-click menu then offer: New booking (one room, back-to-back free nights), Block / Unblock nights
       (admin/blocked-dates.php preview_block then grid_apply, the Blocked Dates confirm words) and New maintenance job
       (room-maintenance.php opens its add form prefilled). Bars keep their own gestures (drag, end grip, drawer). */
    function clearSel(st) { if (st.picks && st.picks.count()) st.picks.clear(); }
    function initPicks(st) {
        var G = window.RhGridSel;
        if (!G) return;
        st.pickBar = G.bar(st.root, 'Selected nights');
        st.picks = G.cells({
            root: st.root,
            grid: q(st, '[data-tl-grid]'),
            laneAttr: 'data-tl-lane',
            dates: function () { return (st.dates || []).map(function (x) { return x.d; }); },
            dayW: function () { return st.dayW || 44; },
            selectable: function (lane, d) { return !!(st.lanesById && st.lanesById[lane]) && d >= st.data.today; },
            cellLabel: function (lane, d) {
                var l = st.lanesById[lane];
                return 'Room ' + (l ? l.number : lane) + ', night of ' + fmt(d) + (nightBooked(st, lane, d) ? ', booked' : '') + (blockAt(st, lane, d) ? ', blocked' : '');
            },
            locked: function () { return !!(st.busy || st.drag); },
            onChange: function () { picksChanged(st); },
            cellMenu: function (api, hit) { return pickMenu(st, hit); },
            barMenu: function (api, bar) {
                var b = st.byId[bar.getAttribute('data-tl-booking')];
                if (!b) return null;
                var items = [{ label: 'Show details', icon: 'fa-circle-info', run: function () { openBooking(st, b, bar); } }];
                if (st.data.can.open_booking) items.push({ label: 'Open booking', icon: 'fa-arrow-up-right-from-square', href: 'booking-details.php?id=' + b.id });
                return { title: b.guest, items: items };
            }
        });
    }
    function dayIdx(st, d) {
        var i = dnum(d) - st.first;
        return i >= 0 && i < st.dates.length ? i : -1;
    }
    function nightBooked(st, lane, d) {
        var n = dnum(d);
        return (st.lanesBookings[lane] || []).some(function (b) { return dnum(b.ci) <= n && dnum(b.eco) - 1 >= n; });
    }
    /** The block drawn on that room night (kind: only that kind), or null. */
    function blockAt(st, lane, d, kind) {
        var hit = null;
        (st.blocksByLane[lane] || []).forEach(function (bl) {
            if (!hit && bl.from <= d && bl.to >= d && (!kind || bl.kind === kind)) hit = bl;
        });
        return hit;
    }
    function pickActions(st, keys) {
        var L = window.RhGridSel.logic, can = st.data.can || {}, out = [];
        var one = L.oneRun(keys);
        if (can.create && one) {
            var lane = st.lanesById[one.lane], a = dayIdx(st, one.from), z = dayIdx(st, one.to);
            if (lane && a !== -1 && z !== -1 && rangeFree(st, lane, a, z)) {
                out.push({ label: 'New booking', icon: 'fa-plus', cls: 'btn-primary', href: newBookingHref(st, lane, a, z) });
            }
        }
        if (can.block) {
            out.push({ label: 'Block nights', icon: 'fa-ban', run: function () { blockPicks(st, 'block'); } });
            var anyBlocked = keys.some(function (k) { var p = L.parts(k); return !!blockAt(st, p.lane, p.d, 'blocked'); });
            if (anyBlocked) out.push({ label: 'Unblock nights', icon: 'fa-unlock', run: function () { blockPicks(st, 'unblock'); } });
        }
        if (can.block && window.RhExtend) {
            var ext = extendItems(st, keys);
            out.push(ext.length
                ? { label: 'Extend...', icon: 'fa-arrows-left-right', run: function () { extendBlocks(st, ext); } }
                : { label: 'Extend...', icon: 'fa-arrows-left-right', disabled: true, hint: 'Select a night of a room\'s own block (a room-type or All rooms block is extended on Blocked Dates).' });
        }
        if (can.maint_page) out.push({ label: 'New maintenance job', icon: 'fa-screwdriver-wrench', href: maintHref(st, keys) });
        return out;
    }
    function picksChanged(st) {
        if (!st.pickBar) return;
        var keys = st.picks.keys();
        if (!keys.length) { st.pickBar.hide(); return; }
        var L = window.RhGridSel.logic;
        var booked = keys.filter(function (k) { var p = L.parts(k); return nightBooked(st, p.lane, p.d); }).length;
        // Every selected night by name, also those in another date range (owner 2026-11-24).
        var words = L.where(keys, st.picks.lanes(), function (l) { return 'Room ' + (st.lanesById[l] ? st.lanesById[l].number : l); });
        st.pickBar.show(L.countWords(keys), words + (booked ? ' (' + booked + ' already booked)' : ''), pickActions(st, keys), function () { st.picks.clear(); });
    }
    function pickMenu(st, hit) {
        var L = window.RhGridSel.logic, can = st.data.can || {};
        var keys = st.picks.keys();
        var items = keys.length ? pickActions(st, keys) : [];
        if (hit && hit.d) {
            var bl = blockAt(st, hit.lane, hit.d);
            if (bl && bl.href && can[bl.kind === 'blocked' ? 'blocked_page' : 'maint_page']) {
                items.push({ label: bl.kind === 'blocked' ? 'Open on Blocked Dates' : 'Open the maintenance job', icon: 'fa-arrow-up-right-from-square', href: bl.href });
            }
        }
        if (hit && hit.room && can.rooms_page && st.lanesById[hit.lane]) {
            items.push({ label: 'Open rooms of this type', icon: 'fa-bed', href: 'individual-rooms.php?room_type=' + encodeURIComponent(st.lanesById[hit.lane].type_id) });
        }
        if (keys.length) items.push({ label: 'Clear selection', icon: 'fa-xmark', run: function () { st.picks.clear(); } });
        if (!items.length) {
            items.push({ label: 'Nothing to do here', disabled: true, hint: hit && hit.d && hit.d < st.data.today ? 'Past nights cannot be changed.' : 'Your role has no actions for these nights.' });
        }
        return { title: keys.length ? L.countWords(keys) : (hit && hit.d ? fmt(hit.d) : 'Room'), items: items };
    }
    /** room-maintenance.php opens its add form for these rooms (each room of a joined room) and nights. */
    function maintHref(st, keys) {
        var L = window.RhGridSel.logic, g = L.byLane(keys, st.picks.lanes()), b = L.bounds(keys), ids = [];
        g.lanes.forEach(function (l) {
            var lane = st.lanesById[l];
            ((lane && lane.room_ids && lane.room_ids.length) ? lane.room_ids : [l]).forEach(function (id) {
                if (ids.indexOf(String(id)) === -1) ids.push(String(id));
            });
        });
        return 'room-maintenance.php?new_job=1&rooms=' + encodeURIComponent(ids.join(',')) + '&from=' + encodeURIComponent(b.from) + '&to=' + encodeURIComponent(b.to);
    }
    /* POST to admin/blocked-dates.php (its CSRF, Block Rooms permission and lock run there). */
    function postBlocks(fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, String(fields[k])); });
        var meta = document.querySelector('meta[name="csrf-token"]');
        var tok = window._rhCsrf || (meta ? (meta.getAttribute('content') || '') : '');
        if (tok) fd.append('csrf_token', tok);
        return fetch(new URL('blocked-dates.php', window.location.href).pathname, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) {
            var ct = r.headers.get('content-type') || '';
            if (!r.ok || ct.indexOf('application/json') === -1) throw new Error('The server did not confirm this. Reload the page and check Blocked Dates before trying again.');
            return r.json();
        }, function () {
            throw new Error('Could not reach the server. Check the connection and try again.');
        });
    }
    /** Block / Unblock the picked nights: the same check, words and save as the Blocked Dates calendar. */
    function blockPicks(st, op) {
        if (st.blockBusy || !st.picks) return;
        var L = window.RhGridSel.logic, keys = st.picks.keys();
        if (!keys.length) return;
        var g = L.byLane(keys, st.picks.lanes());
        var items = g.lanes.map(function (l) { return { t: 'room', id: Number(l), d: g.map[l] }; });
        var local = g.lanes.map(function (l) { return 'Room ' + (st.lanesById[l] ? st.lanesById[l].number : l) + ' on ' + L.nightsText(g.map[l]); }).join('; ');
        st.blockBusy = true;
        var check = postBlocks({ action: 'preview_block', items: JSON.stringify(items) }).catch(function () { return null; });
        check.then(function (p) {
            if (p && p.success === false) throw new Error(p.message || 'The selection could not be checked.');
            // Booked nights are skipped by the save (never blocked over a booking).
            if (op === 'block' && p && p.can_block === false) throw new Error(p.note || 'Every selected night is booked. Nothing to block.');
            var where = p && p.where ? p.where : local;
            var msg = op === 'block'
                ? 'Block ' + where + '? Guests will not be able to book them.' + (p && p.note ? ' ' + p.note : '')
                : 'Unblock ' + where + '? Nights blocked by a room type or All rooms stay blocked; unblock those on Blocked Dates.';
            return window.RhGridSel.ask({
                title: op === 'block' ? 'Block nights' : 'Unblock nights',
                message: msg,
                confirmText: op === 'block' ? 'Block' : 'Unblock',
                icon: op === 'block' ? 'fa-ban' : 'fa-unlock',
                input: op === 'block',
                inputLabel: 'Reason (optional)'
            });
        }).then(function (ans) {
            if (!ans || !ans.confirmed) return null;
            return postBlocks({ action: 'grid_apply', op: op, items: JSON.stringify(items), block_type: 'manual', reason: op === 'block' ? String(ans.value || '') : '' });
        }).then(function (res) {
            st.blockBusy = false;
            if (!res) return;
            var msg = dot(res.message || (res.success ? 'Saved.' : 'Nothing was changed.'));
            if (!res.success) { toast(st, msg, 'error'); return; }
            st.picks.clear();
            toast(st, msg, 'success');
            st.flash = msg;
            var sc = q(st, '[data-tl-scroller]');
            st.keepScroll = sc ? { top: sc.scrollTop, left: sc.scrollLeft, win: window.scrollY } : null;
            go(st, st.start, st.days, true);
        }).catch(function (err) {
            st.blockBusy = false;
            toast(st, (err && err.message) || 'That did not work. Try again.', 'error');
        });
    }

    /* ---------------------------------------------------------------- Extend (GRID_UNIFY_PLAN Phase 3)
       Block runs: Extend... (admin-extend.js dialog -> blocked-dates.php extend_blocks: one transaction, booked nights
       skipped and listed) and the end handle: drag right = extend (same action, a confirm with the check first), drag
       left = unblock the trimmed nights (grid_apply unblock: only the room's own block). Bookings: a drag on a confirmed
       stay opens its own Change dates screen prefilled; nothing is saved on the timeline. */
    function extendItems(st, keys) {
        var L = window.RhGridSel.logic, seen = {}, out = [];
        keys.forEach(function (k) {
            var p = L.parts(k), bl = blockAt(st, p.lane, p.d, 'blocked');
            if (!bl || bl.scope !== 'own') return;
            var id = p.lane + '|' + bl.to;
            if (seen[id]) return;
            seen[id] = true;
            out.push({ t: 'room', id: Number(p.lane), last: bl.to });
        });
        return out;
    }
    function blockSaved(st, res, revert) {
        var msg = dot((res && res.message) || (res && res.success ? 'Saved.' : 'Nothing was changed.'));
        if (!res || !res.success) { if (revert) revert(); toast(st, msg, 'error'); return; }
        if (st.picks) st.picks.clear();
        toast(st, msg, 'success');
        st.flash = msg;
        var sc = q(st, '[data-tl-scroller]');
        st.keepScroll = sc ? { top: sc.scrollTop, left: sc.scrollLeft, win: window.scrollY } : null;
        go(st, st.start, st.days, true);
    }
    function extendBlocks(st, items, opener) {
        window.RhExtend.forBlocks(items, { opener: opener, done: function (res) { blockSaved(st, res); } });
    }
    function blockGrip(st, blk, lane, bl, a) {
        var g = window.RhExtend.grip('tl-grip', 'block on room ' + lane.number + ', ' + fmtRange(bl.from, bl.to));
        blk.appendChild(g);
        var item = { t: 'room', id: Number(lane.id), last: bl.to };
        var open = function () { if (!st.blockBusy) extendBlocks(st, [item], g); };
        g.addEventListener('pointerdown', function (e) {
            if (st.busy || st.blockBusy || st.drag) { e.stopPropagation(); return; }
            var w0 = blk.style.width, track = blk.closest('.tl-track');
            var lastVis = st.first + st.dates.length - 1;
            var floor = Math.max(dnum(bl.from), dnum(st.data.today) - 1); // trimming never touches past nights
            var started = window.RhExtend.gripDrag(e, {
                dayAt: function (x) {
                    if (!track) return null;
                    var n = dayUnder(st, track, x);
                    return n === null ? null : Math.min(lastVis, Math.max(floor, n));
                },
                onMove: function (n) {
                    blk.classList.add('is-resizing');
                    blk.style.width = (Math.max(0.3, n - st.first - a + 1) * st.dayW) + 'px';
                },
                onDone: function (n) {
                    blk.classList.remove('is-resizing');
                    var revert = function () { blk.style.width = w0; };
                    if (n === null || n === dnum(bl.to)) { revert(); return; }
                    resizeBlock(st, lane, bl, n, revert);
                }
            });
            if (!started) e.stopPropagation(); // touch: no drag (the page scrolls); the tap opens Extend...
        });
        g.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!window.RhExtend.justDragged()) open();
        });
        g.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); open(); }
        });
    }
    /** Handle released on night n (day number): extend to n, or unblock the nights after n. Confirm first. */
    function resizeBlock(st, lane, bl, n, revert) {
        var L = window.RhGridSel ? window.RhGridSel.logic : null;
        var room = 'Room ' + lane.number;
        var item = { t: 'room', id: Number(lane.id), last: bl.to };
        st.blockBusy = true;
        var done = function () { st.blockBusy = false; };
        var fail = function (err) { done(); revert(); toast(st, (err && err.message) || 'That did not work. Try again.', 'error'); };
        if (n > dnum(bl.to)) {
            var base = { action: 'extend_blocks', items: JSON.stringify([item]), mode: 'until', until: dstr(n) };
            postBlocks(Object.assign({ preview: '1' }, base)).then(function (p) {
                if (!p || !p.success) throw new Error((p && p.message) || 'Nothing new would be blocked.');
                return window.RhGridSel.ask({ title: 'Extend block', message: room + ': extend the block to the night of ' + fmt(dstr(n)) + '? ' + dot(p.message),
                    confirmText: 'Extend', icon: 'fa-arrows-left-right' });
            }).then(function (ans) {
                if (!ans || !ans.confirmed) { done(); revert(); return null; }
                return postBlocks(base).then(function (res) { done(); blockSaved(st, res, revert); });
            }).catch(fail);
            return;
        }
        var nights = [];
        for (var k = n + 1; k <= dnum(bl.to); k++) { if (dstr(k) >= st.data.today) nights.push(dstr(k)); }
        if (!nights.length) { done(); revert(); return; }
        var words = L ? L.nightsText(nights) : (fmt(nights[0]) + ' to ' + fmt(nights[nights.length - 1]));
        window.RhGridSel.ask({ title: 'Shorten block', message: room + ': unblock ' + nightsWord(nights.length) + ' (' + words + ')? Those nights go back on sale. A room-type or All rooms block on them stays.',
            confirmText: 'Unblock', icon: 'fa-unlock' }).then(function (ans) {
            if (!ans || !ans.confirmed) { done(); revert(); return null; }
            return postBlocks({ action: 'grid_apply', op: 'unblock', items: JSON.stringify([{ t: 'room', id: Number(lane.id), d: nights }]) })
                .then(function (res) { done(); blockSaved(st, res, revert); });
        }).catch(fail);
    }

    /* Bookings: the start / end edge of a confirmed stay (day under the pointer). */
    function datesEdgeMove(st, d, e, left, width) {
        var track = d.row && d.row.querySelector('.tl-track');
        if (!track) return;
        var lastVis = st.first + st.dates.length - 1;
        var day = dayUnder(st, track, e.clientX);
        if (day === null) return;
        var ci = dnum(d.b.ci), co = dnum(d.b.co), today = dnum(st.data.today);
        if (d.kind === 'ci') {
            d.newCi = Math.max(today, Math.min(day, co - 1));
            var x1 = (d.newCi - st.first + 0.5) * st.dayW + 2;
            placeGhost(st, d, d.row, x1, left + width - x1);
            d.datesBad = '';
            markDrop(st, d.row, Math.min(d.newCi, ci), Math.max(d.newCi, ci) - 1, true);
            if (d.newCi === ci) clearDrop(st);
        } else {
            d.newCo = Math.min(lastVis, Math.max(day, ci + 1, today + 1));
            placeGhost(st, d, d.row, left, (d.newCo - st.first + 0.5) * st.dayW - 2 - left);
            d.datesBad = '';
            markDrop(st, d.row, Math.min(d.newCo, co), Math.max(d.newCo, co) - 1, true);
            if (d.newCo === co) clearDrop(st);
        }
        if (d.ghost) d.ghost.classList.remove('tl-bar--ghost-bad');
    }
    /** Owner answer 4: open the booking's own Change dates (prefilled, reprice shown there). Nothing saved here. */
    function handOffDates(st, b, ciN, coN) {
        var ci = dstr(ciN), co = dstr(coN);
        var href = 'booking-details.php?id=' + encodeURIComponent(b.id) + '&open=dates&ci=' + ci + '&co=' + co;
        var msg = b.guest + ': ' + fmtRange(b.ci, b.co) + ' (' + nightsWord(b.nights) + ') to ' + fmtRange(ci, co) + ' (' + nightsWord(coN - ciN) + ')? '
            + 'The booking\'s Change dates screen opens with these dates and shows the new price. Nothing is saved until you confirm there.';
        var ask = window.RhGridSel ? window.RhGridSel.ask({ title: 'Change dates', message: msg, confirmText: 'Open Change dates', icon: 'fa-calendar-pen' })
            : Promise.resolve({ confirmed: true });
        ask.then(function (ans) {
            endDrag(st);
            // Inside bookings.php's Calendar frame the booking opens in the whole window, not in the frame.
            if (ans && ans.confirmed) (window.top !== window.self ? window.top : window).location.href = new URL(href, window.location.href).href;
        });
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
            if (st.suppressClick) { // the click that ends a drag is not a click on the bar
                st.suppressClick = false;
                if (t.closest('[data-tl-grid]')) return;
            }
            if ((x = t.closest('[data-tl-confirm]'))) {
                e.preventDefault();
                if (st.pending && typeof st.pending.confirm === 'function') st.pending.confirm();
                return;
            }
            if ((x = t.closest('[data-tl-cancel]'))) { e.preventDefault(); closeDrawer(st); return; }
            if ((x = t.closest('[data-tl-act]'))) {
                e.preventDefault();
                closeMenus(st);
                var ab0 = st.drawerBooking;
                if (ab0 && String(ab0.id) === x.getAttribute('data-tl-act-id')) {
                    if (x.getAttribute('data-tl-act') === 'move') showMovePanel(st, ab0);
                    else showDatesPanel(st, ab0);
                }
                return;
            }
            if ((x = t.closest('[data-tl-room-pick]'))) {
                e.preventDefault();
                if (st.pending && typeof st.pending.pick === 'function') st.pending.pick(x.getAttribute('data-tl-room-pick'));
                return;
            }
            if ((x = t.closest('[data-tl-dates-go]'))) {
                e.preventDefault();
                if (st.pending && typeof st.pending.dates === 'function') st.pending.dates();
                return;
            }
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
                return;
            }
            var k = String(e.key || '').toLowerCase();
            if ((k === 'm' || k === 'd') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                var kb = st.byId[bar.getAttribute('data-tl-booking')];
                if (!kb) return;
                e.preventDefault();
                keyChange(st, kb, bar, k === 'm' ? 'move' : 'dates');
            }
        });

        // Bar drag (move room / end grip). Night selection on empty cells: room-timeline-select.js (initPicks).
        var grid = q(st, '[data-tl-grid]');
        grid.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) return;
            var onBar = e.target.closest('.tl-bar');
            if (onBar) beginDrag(st, e, onBar);
        });
        grid.addEventListener('pointermove', function (e) {
            if (st.drag) dragMove(st, e);
        });
        grid.addEventListener('pointerup', function () {
            if (st.drag) dragEnd(st);
        });
        grid.addEventListener('pointercancel', function () {
            if (st.drag) { st.drag = null; endDrag(st); }
        });
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
            if (st.drag) { st.drag = null; endDrag(st); return; }
            if (st.root.querySelector('.tl-more.open')) { closeMenus(st); return; }
            if (st.picks && st.picks.count()) { st.picks.clear(); return; }
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
            if (st.root.classList.contains('is-filters-open') && e.target.closest('[data-tl-backdrop]')) toggleFilters(st, false);
        });
        var t = null;
        window.addEventListener('resize', function () {
            window.clearTimeout(t);
            t = window.setTimeout(function () {
                var st = live();
                if (!st) return;
                syncDock(st); // this handler draws the grid again itself
                var allowed = allowedDays();
                if (allowed.indexOf(st.days) === -1) go(st, st.start, defaultDays());
                else if (st.mode === 'grid') render(st);
            }, 150);
        });
    }

    /* Modes other than the booking timeline register here (admin/js/room-timeline-ops.js): name -> adapter
       { columns, rows, bars, drawer, search }. util = the shared helpers, so every mode draws the same way. */
    var MODES = {};
    window.RoomTimeline = {
        init: init,
        modes: MODES,
        util: {
            el: el, icon: icon, help: help, dnum: dnum, dstr: dstr, addDays: addDays, fmt: fmt, fmtRange: fmtRange,
            store: store, viewport: viewport, allowedDays: allowedDays, defaultDays: defaultDays,
            mqPhone: mqPhone, mqTablet: mqTablet, mqCoarse: mqCoarse, mqReduce: mqReduce,
            STORE_COLLAPSE: STORE_COLLAPSE, STORE_DENSITY: STORE_DENSITY,
            syncDock: syncDock, redrawKeep: redrawKeep
        }
    };
})();
