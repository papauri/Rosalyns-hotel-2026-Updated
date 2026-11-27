// Pure selection logic of admin/js/room-timeline-select.js (GRID_UNIFY_PLAN Phase 1). No DOM, no DB.
// Run: node scripts/tests/grid_select_test.mjs
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const src = readFileSync(join(root, 'admin', 'js', 'room-timeline-select.js'), 'utf8');
const sandbox = { module: { exports: {} } };
vm.runInNewContext(src, sandbox);
const L = sandbox.module.exports;

let pass = 0, fail = 0;
function ok(label, cond, detail) {
    if (cond) { pass++; console.log('[PASS] ' + label); }
    else { fail++; console.log('[FAIL] ' + label + (detail !== undefined ? ' :: ' + JSON.stringify(detail) : '')); }
}
const eq = (a, b) => JSON.stringify(a) === JSON.stringify(b);

const lanes = ['10', '11', '12'];
const dates = ['2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04'];

ok('key / parts round trip (lane ids may hold a colon)', eq(L.parts(L.key('pair:3', '2026-12-01')), { lane: 'pair:3', d: '2026-12-01' }));
ok('rectangle across rooms and nights, either drag direction',
    eq(L.rect(lanes, dates, { lane: '12', d: '2026-12-03' }, { lane: '11', d: '2026-12-02' }),
        ['11|2026-12-02', '11|2026-12-03', '12|2026-12-02', '12|2026-12-03']));
ok('rectangle skips what the mode refuses (past nights)',
    eq(L.rect(lanes, dates, { lane: '10', d: '2026-12-01' }, { lane: '10', d: '2026-12-03' }, (l, d) => d >= '2026-12-02'),
        ['10|2026-12-02', '10|2026-12-03']));
ok('rectangle to a room that is not shown is empty', L.rect(lanes, dates, { lane: '10', d: '2026-12-01' }, { lane: '99', d: '2026-12-01' }).length === 0);
ok('runs join back-to-back nights, also across a month end',
    eq(L.runs(['2026-12-31', '2027-01-01', '2026-12-29', '2027-01-03']), [['2026-12-29', '2026-12-29'], ['2026-12-31', '2027-01-01'], ['2027-01-03', '2027-01-03']]));
ok('one room, back-to-back nights = one run (New booking allowed)', eq(L.oneRun(['11|2026-12-03', '11|2026-12-02']), { lane: '11', from: '2026-12-02', to: '2026-12-03' }));
ok('a gap is not one run (no New booking)', L.oneRun(['11|2026-12-01', '11|2026-12-03']) === null);
ok('two rooms are not one run', L.oneRun(['11|2026-12-01', '12|2026-12-01']) === null);
ok('count words', L.countWords(['11|2026-12-01', '11|2026-12-02', '12|2026-12-01']) === '3 nights in 2 rooms'
    && L.countWords(['11|2026-12-01']) === '1 night in 1 room');
ok('bounds = first and last night', eq(L.bounds(['12|2026-12-04', '10|2026-12-02']), { from: '2026-12-02', to: '2026-12-04' }));
ok('nights text', L.nightsText(['2026-12-01', '2026-12-02', '2026-12-04']) === '1 to 2 Dec, 4 Dec'
    && L.nightsText(['2026-12-31', '2027-01-01']) === '31 Dec to 1 Jan');
ok('pairs follow the shown room order, nights ascending',
    eq(L.pairs(['12|2026-12-02', '10|2026-12-03', '10|2026-12-01'], lanes).map(p => p.lane + '@' + p.d), ['10@2026-12-01', '10@2026-12-03', '12@2026-12-02']));
ok('byLane counts a night once', eq(L.byLane(['10|2026-12-01', '10|2026-12-01']).map['10'], ['2026-12-01']));

// Fault 1 (owner 2026-11-24, "I click a day and a different day is selected"): the night under the pointer comes from
// the rendered track (screen pixels), never from the CSS day width. Display size 90% = html zoom 0.9: 14 cells of 95px
// render 85.5px wide on screen; the old maths (x - left) / 95 picked the night before.
{
    const n = 14, dayW = 95, z = 0.9, left = 300 * z, width = n * dayW * z;
    const centre = (i) => left + (i + 0.5) * dayW * z;
    let all = true, old = 0;
    for (let i = 0; i < n; i++) {
        if (L.colAt(centre(i), left, width, n) !== i) all = false;
        if (Math.floor((centre(i) - left) / dayW) !== i) old++;
    }
    ok('colAt: the centre of every cell at display size 90% is that cell', all);
    ok('the old dayW maths really picked another night at 90% (the fault this replaces)', old > 0, old);
    ok('colAt at 100%, 60% and 110% (each cell centre)', [1, 0.6, 1.1].every(s => [0, 5, 13].every(i => L.colAt(10 + (i + 0.5) * 44 * s, 10, n * 44 * s, n) === i)));
    ok('colAt: the left edge is the first night, the right edge is outside', L.colAt(left, left, width, n) === 0 && L.colAt(left + width, left, width, n) === -1);
    ok('colAt: left of the track, an empty track or no nights = -1', L.colAt(left - 1, left, width, n) === -1 && L.colAt(5, 0, 0, n) === -1 && L.colAt(5, 0, 100, 0) === -1);
}

// Same fault in the drags (owner 2026-11-24): booking move, check-in / check-out handle, block and job extend grips
// name a night from the pointer. They use colOf (not limited to the track; the caller clamps) and colsMoved (sideways
// move). Calendar of 28 nights from day number 100, CSS day width 44, track 10px from the screen edge.
{
    const n = 28, dayW = 44, first = 100, last = first + n - 1;
    const at = (s) => ({ left: 10 * s, width: n * dayW * s, centre: (i) => 10 * s + (i + 0.5) * dayW * s });
    // The callers' clamps (room-timeline.js end edge / block grip, room-timeline-ops.js job grip).
    const extendTo = (s, x, floor) => { const t = at(s), c = L.colOf(x, t.left, t.width, n); return c === null ? null : Math.min(last, Math.max(floor, first + c)); };
    for (const s of [0.6, 0.9, 1]) {
        const t = at(s);
        ok(`colOf at display size ${s * 100}%: every cell centre is that night`, Array.from({ length: n }, (_, i) => i).every(i => L.colOf(t.centre(i), t.left, t.width, n) === i));
        ok(`extend grip at ${s * 100}%: released on night 20 extends to night 20`, extendTo(s, t.centre(20), first + 3) === first + 20);
        ok(`extend grip at ${s * 100}%: pulled past the right edge = the last night shown (never the first)`, extendTo(s, t.left + t.width + 200 * s, first + 3) === last);
        ok(`extend grip at ${s * 100}%: pulled left of the track = the floor (no night before it)`, extendTo(s, t.left - 50, first + 3) === first + 3);
        ok(`booking drag at ${s * 100}%: 3 nights right / 5 nights left moves 3 / -5`, L.colsMoved(3 * dayW * s, t.width, n) === 3 && L.colsMoved(-5 * dayW * s, t.width, n) === -5);
        ok(`booking drag at ${s * 100}%: under half a night either way = no move (and never -0)`,
            Object.is(L.colsMoved(0.4 * dayW * s, t.width, n), 0) && Object.is(L.colsMoved(-0.4 * dayW * s, t.width, n), 0));
    }
    const t6 = at(0.6);
    ok('the old dayW maths named the wrong night at 60% (extend to night 20 gave night 12; a 3-night move gave 2)',
        first + Math.floor((t6.centre(20) - t6.left) / dayW) === first + 12 && Math.round(3 * dayW * 0.6 / dayW) === 2);
    ok('colOf: no rendered track or no nights = null (the drag ignores that move); colsMoved = 0',
        L.colOf(50, 0, 0, n) === null && L.colOf(50, 0, 100, 0) === null && L.colsMoved(50, 0, n) === 0);
}

// Fault 2 (owner 2026-11-24, "it won't retain my selection when I go to next month and want to extend"): the picks
// outlive the dates on screen; Shift-click across ranges spans every night in between, also nights on neither screen.
{
    const dec = [];
    for (let d = 1; d <= 31; d++) dec.push('2026-12-' + String(d).padStart(2, '0'));
    const novEnd = ['2026-11-28', '2026-11-29', '2026-11-30'];
    const jan = ['2027-01-15', '2027-01-16', '2027-01-17'];
    const shownLanes = ['10', '11', '12'];
    const yes = () => true;
    const thirty = L.rect(shownLanes, dec, { lane: '11', d: '2026-12-01' }, { lane: '11', d: '2026-12-30' }, yes);
    ok('30 nights picked on one room in December', thirty.length === 30 && L.countWords(thirty) === '30 nights in 1 room');
    ok('next range: December picks are kept (only rooms gone / not selectable drop)',
        eq(L.keep(thirty, shownLanes, yes), thirty));
    ok('keep drops a room that is no longer shown and a night that is not selectable',
        eq(L.keep(['10|2026-12-01', '99|2026-12-01', '11|2026-11-01'], shownLanes, (l, d) => d >= '2026-12-01'), ['10|2026-12-01']));
    const ext = L.rect(shownLanes, jan, { lane: '11', d: '2026-12-01' }, { lane: '11', d: '2027-01-16' }, yes);
    ok('Shift-click in a later range extends from the anchor: 1 Dec to 16 Jan = 47 nights', ext.length === 47, ext.length);
    ok('the extension covers nights on neither screen (31 Dec, 1 to 14 Jan) and crosses the year end',
        ext.includes('11|2026-12-31') && ext.includes('11|2027-01-01') && ext.includes('11|2027-01-14'));
    ok('extension text names the whole run', L.nightsText(L.byLane(ext).map['11']) === '1 Dec to 16 Jan');
    ok('Shift-click back into an earlier range works the other way round',
        eq(L.rect(shownLanes, novEnd, { lane: '12', d: '2026-12-02' }, { lane: '12', d: '2026-11-29' }, yes), ['12|2026-11-29', '12|2026-11-30', '12|2026-12-01', '12|2026-12-02']));
    ok('an extension skips nights the mode refuses (past nights)',
        eq(L.rect(shownLanes, novEnd, { lane: '10', d: '2026-11-27' }, { lane: '10', d: '2026-11-30' }, (l, d) => d >= '2026-11-29'), ['10|2026-11-29', '10|2026-11-30']));
    ok('an extension across rooms is the rectangle of shown rooms', L.rect(shownLanes, jan, { lane: '10', d: '2026-12-31' }, { lane: '11', d: '2027-01-15' }, yes).length === 32);
    ok('an extension from a room that is not shown is empty', L.rect(shownLanes, jan, { lane: '99', d: '2026-12-01' }, { lane: '11', d: '2027-01-15' }, yes).length === 0);
    ok('span lists every night either way, also a single night', L.span('2026-12-30', '2027-01-02').length === 4 && eq(L.span('2027-01-02', '2027-01-01'), ['2027-01-01', '2027-01-02']) && eq(L.span('2026-12-05', '2026-12-05'), ['2026-12-05']));
    ok('span refuses a run longer than two years (a stray anchor never makes a huge selection)', L.span('2026-01-01', '2029-01-01').length === 0);
    const name = (l) => 'Room ' + l;
    ok('the bar names every selected night, off-screen ones too', L.where(ext, shownLanes, name) === 'Room 11: 1 Dec to 16 Jan');
    ok('the bar names each room (rooms in shown order)', L.where(['12|2026-12-02', '10|2026-12-01', '10|2026-12-02'], shownLanes, name) === 'Room 10: 1 to 2 Dec; Room 12: 2 Dec');
    ok('more than 3 rooms: the bar names the nights once', L.where(['1|2026-12-01', '2|2026-12-01', '3|2026-12-02', '4|2027-01-05'], [], name) === '4 rooms: 1 to 2 Dec, 5 Jan');
    ok('nothing selected: no words', L.where([], shownLanes, name) === '');
}

// Fix round (reviewer 2026-11-24): Shift-click / Shift+Arrow / long press ADD to the picks and grow the SAME room's run
// from its picked night nearest the click (also off screen); the anchor's rectangle only for a room with nothing picked.
{
    const rooms = ['101', '102', '103', '104', '105'];
    const dec = L.span('2026-12-01', '2026-12-30'), jan = L.span('2026-12-31', '2027-01-29'); // 30-day views, then Next
    const yes = (l, d) => d >= '2026-11-24';
    const room101Dec = dec.map(d => L.key('101', d));
    const want = L.span('2026-12-01', '2027-01-16').map(d => L.key('101', d)).sort();
    const hit = { lane: '101', d: '2027-01-16' };
    const sorted = (x) => x.keys.slice().sort();
    // (a) click "Room 101" name (picks its shown nights, anchor = its last night), Next, Shift-click 16 Jan on Room 101.
    const a = L.shiftAdd(room101Dec, L.parts(room101Dec[room101Dec.length - 1]), hit, rooms, jan, yes);
    ok('(a) room name, Next, Shift-click 16 Jan: Room 101 is 1 Dec to 16 Jan (the December nights kept)', eq(sorted(a), want), a.keys.length);
    ok('(a) without an anchor the room\'s own picks still lead the extension', eq(sorted(L.shiftAdd(room101Dec, null, hit, rooms, jan, yes)), want));
    // (b) 3 Dec on Room 105 first, then the room name: the old anchor in Room 105 never makes a rectangle 101-105.
    const b = L.shiftAdd(room101Dec, { lane: '105', d: '2026-12-03' }, hit, rooms, jan, yes);
    ok('(b) a stale anchor in Room 105: only Room 101 grows, Rooms 102-105 untouched', eq(sorted(b), want) && b.keys.every(k => L.parts(k).lane === '101'), L.byLane(b.keys).lanes);
    // (c) drag 30 Dec back to 1 Dec (anchor 30 Dec; also the other way, anchor 1 Dec), Next, Shift-click 16 Jan.
    ok('(c) drag 30 Dec to 1 Dec, Next, Shift-click: 1 to 29 Dec stay, 1 Dec to 16 Jan picked', eq(sorted(L.shiftAdd(room101Dec, { lane: '101', d: '2026-12-30' }, hit, rooms, jan, yes)), want));
    ok('(c) anchor on 1 Dec: the run still grows from its last night (30 Dec), nothing dropped', eq(sorted(L.shiftAdd(room101Dec, { lane: '101', d: '2026-12-01' }, hit, rooms, jan, yes)), want));
    ok('Shift-click before the room\'s run grows it backwards from its first night (5 to 15 Dec)',
        eq(sorted(L.shiftAdd(L.span('2026-12-10', '2026-12-15').map(d => L.key('101', d)), null, { lane: '101', d: '2026-12-05' }, rooms, dec, yes)), L.span('2026-12-05', '2026-12-15').map(d => L.key('101', d))));
    ok('Shift-click keeps the other rooms\' picks (adds, never replaces)',
        L.shiftAdd(room101Dec.concat(['104|2026-12-07']), null, hit, rooms, jan, yes).keys.indexOf('104|2026-12-07') !== -1);
    const r = L.shiftAdd(['101|2026-12-03'], { lane: '101', d: '2026-12-03' }, { lane: '103', d: '2026-12-05' }, rooms, dec, yes);
    ok('a room with nothing picked: the rectangle from the anchor (Rooms 101-103 x 3-5 Dec), the pick kept', r.keys.length === 9 && eq(r.from, { lane: '101', d: '2026-12-03' }));
    ok('the extension skips refused (past) nights', eq(sorted(L.shiftAdd(['101|2026-11-26'], null, { lane: '101', d: '2026-11-22' }, rooms, dec, yes)), ['101|2026-11-24', '101|2026-11-25', '101|2026-11-26']));
    // Touch long press on a new night: the night itself is picked, and "Select 30 Dec to 16 Jan" grows Room 101's run.
    const lp = L.pressPlan(room101Dec, { lane: '101', d: '2026-12-30' }, hit, rooms, jan, yes);
    ok('long press on 16 Jan: 16 Jan itself is added (the menu acts on it)', eq(lp.add, ['101|2027-01-16']));
    ok('long press on 16 Jan: offers Room 101 from its last night, 30 Dec to 16 Jan (18 nights, one room)',
        eq(lp.from, { lane: '101', d: '2026-12-30' }) && lp.range.length === 18 && L.byLane(lp.range).lanes.length === 1);
    ok('long press with a stale anchor in Room 105: still Room 101 only', L.byLane(L.pressPlan(room101Dec, { lane: '105', d: '2026-12-03' }, hit, rooms, jan, yes).range).lanes.join() === '101');
    ok('long press, nothing picked and no anchor: the night is added, no range offered', eq(L.pressPlan([], null, hit, rooms, jan, yes), { add: ['101|2027-01-16'], from: { lane: '101', d: '2027-01-16' }, range: [] }));
    ok('long press on a picked or refused night changes nothing',
        L.pressPlan(room101Dec, null, { lane: '101', d: '2026-12-05' }, rooms, dec, yes).add.length === 0 && L.pressPlan([], null, { lane: '101', d: '2026-11-20' }, rooms, dec, yes).add.length === 0);
}

// Off-screen picks judged on real Timeline payloads (owner 2026-11-24: Unblock / Extend / booked count looked only at
// the dates on screen). Payload shape: admin/includes/calendar-data.php rh_cal_timeline_payload.
{
    const days = (start, n) => L.span(start, L.dstr(L.dnum(start) + n - 1)).map((d) => ({ d }));
    // Loaded range: 1-14 Dec. Room 101 has its own block 3-5 Dec; Room 102 a room-type block 4 Dec; Room 101 booked 8-9 Dec.
    const dec = { start: '2026-12-01', dates: days('2026-12-01', 14),
        bookings: [{ lanes: [101], ci: '2026-12-08', eco: '2026-12-10' }],
        blocks: [{ lane: 101, kind: 'blocked', scope: 'own', from: '2026-12-03', to: '2026-12-05' },
                 { lane: 102, kind: 'blocked', scope: 'type', from: '2026-12-04', to: '2026-12-04' },
                 { lane: 103, kind: 'maintenance', scope: 'job', from: '2026-12-02', to: '2026-12-02' }] };
    // Fetched window 30 Dec - 29 Jan (crosses the year end): Room 102 own block 2-6 Jan, Room 102 booked 31 Dec - 1 Jan.
    const jan = { start: '2026-12-30', dates: days('2026-12-30', 31),
        bookings: [{ lanes: [102], ci: '2026-12-31', eco: '2027-01-02' }],
        blocks: [{ lane: 102, kind: 'blocked', scope: 'own', from: '2027-01-02', to: '2027-01-06' }] };
    const picks = ['101|2026-12-04', '101|2026-12-08', '102|2026-12-04', '102|2026-12-31', '102|2027-01-01', '102|2027-01-03', '103|2026-12-02'];

    const before = L.judge(picks, [dec]);
    ok('judge on the loaded range only: the Jan nights are missing (not guessed free)', eq(before.missing, ['2026-12-31', '2027-01-01', '2027-01-03']));
    ok('judge on the loaded range only: 1 booked night, 1 own-block night on screen', before.booked === 1 && eq(before.own, ['101|2026-12-04']));
    const all = L.judge(picks, [dec, jan]);
    ok('judge over both ranges: nothing missing', all.missing.length === 0);
    ok('booked counted over both ranges (8 Dec + 31 Dec + 1 Jan = 3)', all.booked === 3, all);
    ok('Unblock nights = only the room\'s own block, on and off screen (room-type and maintenance nights left out)',
        eq(all.own, ['101|2026-12-04', '102|2027-01-03']), all.own);
    ok('Extend items: one per own-block run, the off-screen run included',
        eq(all.extend, [{ t: 'room', id: 101, last: '2026-12-05' }, { t: 'room', id: 102, last: '2027-01-06' }]), all.extend);
    // A night both payloads hold is judged once, by the first (the loaded range wins, never counted twice).
    const overlap = { start: '2026-12-08', dates: days('2026-12-08', 3), bookings: [{ lanes: [101], ci: '2026-12-08', eco: '2026-12-10' }], blocks: [] };
    ok('a night held by two payloads counts once', L.judge(['101|2026-12-08', '101|2026-12-08'], [dec, overlap]).booked === 1);
    ok('a picked night on a stay\'s check-out day is not booked', L.judge(['101|2026-12-10'], [dec]).booked === 0);
    ok('no payloads: every night missing, nothing offered', (() => { const j = L.judge(['101|2026-12-04'], []); return eq(j.missing, ['2026-12-04']) && !j.own.length && !j.extend.length && j.booked === 0; })());

    ok('windows: nothing missing = no request', L.windows([], 31, 3).length === 0);
    ok('windows: one night = a 3-night request (the API minimum)', eq(L.windows(['2027-01-03'], 31, 3), [{ start: '2027-01-03', days: 3 }]));
    ok('windows: nights across the year end in one request', eq(L.windows(['2027-01-03', '2026-12-31', '2027-01-01', '2026-12-31'], 31, 3), [{ start: '2026-12-31', days: 4 }]));
    ok('windows: a span over 31 nights is split (1 Jan - 1 Feb = 31 + 1 nights)',
        eq(L.windows(['2027-01-01', '2027-01-31', '2027-02-01'], 31, 3), [{ start: '2027-01-01', days: 31 }, { start: '2027-02-01', days: 3 }]));
    ok('windows: months apart are two requests, the months between not read',
        eq(L.windows(['2027-03-10', '2027-06-20'], 31, 3), [{ start: '2027-03-10', days: 3 }, { start: '2027-06-20', days: 3 }]));
    const w = L.windows(['2027-01-01', '2027-01-31', '2027-02-01', '2027-03-10'], 31, 3);
    ok('windows: every missing night is held by a window', ['2027-01-01', '2027-01-31', '2027-02-01', '2027-03-10'].every((d) => w.some((x) => L.holds(x, d))));
    ok('holds: window end is exclusive, payload uses its dates', !L.holds({ start: '2027-01-01', days: 3 }, '2027-01-04') && L.holds({ start: '2027-01-01', days: 3 }, '2027-01-03')
        && L.holds(jan, '2027-01-29') && !L.holds(jan, '2027-01-30'));
}

// Arrow keys past the edge page the grid (owner 2026-11-26): pastEdge names the night just off screen, else null.
{
    const edge = ['2026-12-30', '2026-12-31'];
    ok('pastEdge: Right on the last night shown = the next night, also across the year end', L.pastEdge(edge, 1, 1) === '2027-01-01');
    ok('pastEdge: Left on the first night shown = the night before', L.pastEdge(dates, 0, -1) === '2026-11-30');
    ok('pastEdge: inside the range = null (the cursor just moves)', L.pastEdge(dates, 1, 1) === null && L.pastEdge(dates, 3, -1) === null);
    ok('pastEdge: Up / Down (no sideways step), empty grid or a stale column = null',
        L.pastEdge(dates, 3, 0) === null && L.pastEdge([], 0, 1) === null && L.pastEdge(dates, 7, 1) === null);
    ok('Shift+Right past the edge: the rectangle reaches the off-screen night',
        eq(L.rect(lanes, dates, { lane: '10', d: '2026-12-03' }, { lane: '10', d: L.pastEdge(dates, 3, 1) }), ['10|2026-12-03', '10|2026-12-04', '10|2026-12-05']));
}

// Picked days on Housekeeping / Maintenance act on the tasks / jobs in them (owner 2026-11-28): itemsIn.
{
    const items = [
        { id: 1, lane: '10', from: '2026-12-01', to: '2026-12-01' },  // task, room 10, 1 Dec
        { id: 2, lane: '10', from: '2026-12-01', to: '2026-12-01' },  // second task, same cell
        { id: 3, lane: '11', from: '2026-12-02', to: '2026-12-04' },  // job over three days
        { id: 4, lane: '12', from: '2026-11-20', to: '2026-11-20' },  // past task
        { id: 5, lane: '11', from: '2026-12-06', to: '' }             // no end: its first day only
    ];
    ok('itemsIn: every task in a picked cell, once each, in item order',
        eq(L.itemsIn(['10|2026-12-01', '10|2026-12-01'], items), ['1', '2']));
    ok('itemsIn: a job counts when any picked day falls inside it (middle day)', eq(L.itemsIn(['11|2026-12-03'], items), ['3']));
    ok('itemsIn: the same day in another room is not that room\'s job', eq(L.itemsIn(['10|2026-12-03', '12|2026-12-03'], items), []));
    ok('itemsIn: past days resolve too (verify / delete a past task)', eq(L.itemsIn(['12|2026-11-20'], items), ['4']));
    ok('itemsIn: a rectangle across rooms and days', eq(L.itemsIn(L.rect(lanes, dates, { lane: '10', d: '2026-12-01' }, { lane: '11', d: '2026-12-02' }), items), ['1', '2', '3']));
    ok('itemsIn: the day after a job and an item with no end', eq(L.itemsIn(['11|2026-12-05'], items), []) && eq(L.itemsIn(['11|2026-12-06'], items), ['5']));
    ok('itemsIn: nothing picked or no items', eq(L.itemsIn([], items), []) && eq(L.itemsIn(['10|2026-12-01'], []), []));
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
