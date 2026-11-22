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

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
