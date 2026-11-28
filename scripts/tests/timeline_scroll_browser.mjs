// Room Timeline sideways scroll (owner 2026-11-28): headless Chrome over CDP, READ-ONLY (the one drag ends on the
// Change dates question, which is cancelled; nothing is saved).
// 1. Serve the repo:  php -S 127.0.0.1:8017 -t . scripts/tests/dev-router.php
// 2. Run:             node scripts/tests/timeline_scroll_browser.mjs   [BASE=http://localhost:8017] [CDP_PORT=9342]
// Checks: 1440px / 14 days the grid is wider than its box; Next scrolls (no reload) and the date box moves 14 days;
// scrolling to the right end draws a new window; Alt+Page Down moves one screen; a confirmed stay dragged to the
// right edge scrolls the grid and the Change dates question names the further dates (cancelled); 390px has no
// sideways page scroll and the phone snap still works. Exit 1 on any FAIL.
import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.BASE || 'http://localhost:8017';
const port = Number(process.env.CDP_PORT || 9342);
const profile = mkdtempSync(join(tmpdir(), 'cdp-'));
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', `--remote-debugging-port=${port}`,
  `--user-data-dir=${profile}`, '--no-first-run', '--window-size=1440,900', 'about:blank'], { stdio: 'ignore' });
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
let fails = 0;
const check = (ok, label, extra) => { if (!ok) fails++; console.log((ok ? 'PASS ' : 'FAIL ') + label + (extra !== undefined ? '  ' + JSON.stringify(extra) : '')); };

let targets;
for (let i = 0; i < 50; i++) { try { targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json(); if (targets.length) break; } catch {} await sleep(200); }
const ws = new WebSocket(targets.find(t => t.type === 'page').webSocketDebuggerUrl);
await new Promise(r => ws.addEventListener('open', r));
let id = 0; const pend = new Map(); const logs = [];
ws.addEventListener('message', (ev) => {
  const m = JSON.parse(ev.data);
  if (m.id && pend.has(m.id)) { pend.get(m.id)(m); pend.delete(m.id); return; }
  if (m.method === 'Runtime.exceptionThrown') logs.push('[exception] ' + (m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text));
  if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') logs.push('[console.error] ' + m.params.args.map(a => a.value ?? a.description).join(' '));
});
const send = (method, params = {}) => { const i = ++id; ws.send(JSON.stringify({ id: i, method, params })); return new Promise(r => pend.set(i, r)); };
const ev = async (expr) => { const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true }); return r.result?.result?.value ?? r.result?.exceptionDetails?.exception?.description; };
const mouse = (type, x, y) => send('Input.dispatchMouseEvent', { type, x, y, button: 'left', buttons: type === 'mouseReleased' ? 0 : 1, clickCount: 1 });
const dnum = (s) => { const p = s.split('-'); return Math.round(Date.UTC(+p[0], +p[1] - 1, +p[2]) / 86400000); };
const dstr = (n) => new Date(n * 86400000).toISOString().slice(0, 10);
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const fmt = (s) => { const p = s.split('-'); return (+p[2]) + ' ' + MONTHS[+p[1] - 1]; };
const fmtRange = (a, b) => { const pa = a.split('-'), pb = b.split('-'); return (pa[1] === pb[1] && pa[0] === pb[0]) ? (+pa[2]) + ' to ' + fmt(b) : fmt(a) + ' to ' + fmt(b); };
const state = () => ev(`(() => { const sc = document.querySelector('[data-tl-scroller]'); const r = document.querySelector('[data-tl-root]');
  return { date: document.querySelector('[data-tl-date]').value, left: sc.scrollLeft, sw: sc.scrollWidth, cw: sc.clientWidth,
    win: r.getAttribute('data-tl-window'), loading: r.classList.contains('is-loading'), mark: window.__tlMark || 0 }; })()`);
// The window fills in from the server (about 1 to 3 s from this laptop to the hosted test DB): poll, never a fixed wait.
const waitWin = async (prev, ms = 12000) => { const t0 = Date.now(); let s; do { await sleep(200); s = await state(); if (s.win !== prev && !s.loading) { await sleep(300); return state(); } } while (Date.now() - t0 < ms); return s; };
const settle = async () => { for (let i = 0; i < 40; i++) { await sleep(150); const s = await state(); if (!s.loading) return s; } return state(); };

await send('Runtime.enable'); await send('Page.enable');
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
await send('Page.navigate', { url: BASE + '/__devlogin?to=calendar.php%3Fdays%3D14' });
await sleep(5000);
await ev('window.__tlMark = 1'); // gone after a full page load
let s0 = await settle();
console.log('page', await ev('location.pathname + location.search'), JSON.stringify(s0));

// 1. Wider than the box.
check(s0.sw > s0.cw, '1440/14 days: the grid scrolls sideways (scrollWidth > clientWidth)', { sw: s0.sw, cw: s0.cw, win: s0.win });

// 2. Next = one screen, scrolled, no page load.
await ev(`document.querySelector('[data-tl-next]').click()`);
await sleep(80);
const sMid = await state();
const s1 = await waitWin(s0.win); // the view is now at the end of the first window: it fills in
check(sMid.win === s0.win && sMid.left > s0.left, 'Next scrolls the drawn grid (same window right after the click)', { before: s0.left, after: sMid.left });
check(s1.mark === 1, 'Next did not reload the page');
check(dnum(s1.date) - dnum(s0.date) === 14, 'Next: date box +14 days', { from: s0.date, to: s1.date });

// 3. Scroll to the right end: a new window is drawn, the first day on screen kept.
await ev(`(() => { const sc = document.querySelector('[data-tl-scroller]'); sc.scrollLeft = sc.scrollWidth; })()`);
await sleep(300);
const sEnd = await state();
const s2 = await waitWin(sEnd.win);
check(s2.win !== sEnd.win && s2.left < s2.sw - s2.cw - 1, 'Right end: the window fills in (more days to the right)', { before: sEnd.win, after: s2.win });
check(s2.date === sEnd.date, 'Right end: the first day on screen stays put through the refill', { before: sEnd.date, after: s2.date });
check(s2.mark === 1, 'Refill did not reload the page');

// 3b. A picked night and an open booking panel survive a refill.
const cellPt = await ev(`(() => { const sc = document.querySelector('[data-tl-scroller]'); const sr = sc.getBoundingClientRect();
  const room = document.querySelector('.tl-head .tl-corner').getBoundingClientRect().width;
  for (const c of document.querySelectorAll('.tl-lane[data-tl-lane] .tl-cell')) { const r = c.getBoundingClientRect();
    if (r.left < sr.left + room + 5 || r.right > sr.left + sc.clientWidth - 5 || r.top < sr.top + 140 || r.bottom > sr.bottom - 5) continue;
    const x = r.left + r.width / 2, y = r.top + r.height / 2; if (document.elementFromPoint(x, y) !== c) continue;
    return { x, y, lane: c.closest('[data-tl-lane]').getAttribute('data-tl-lane'), day: +c.getAttribute('data-tl-day') }; } return null; })()`);
if (cellPt) {
  await mouse('mouseMoved', cellPt.x, cellPt.y); await mouse('mousePressed', cellPt.x, cellPt.y); await mouse('mouseReleased', cellPt.x, cellPt.y);
  await sleep(300);
  const picked = await ev(`(() => { const r = document.querySelector('[data-tl-root]'); const w = r.getAttribute('data-tl-window').split('/')[0];
    const c = document.querySelectorAll('.tl-lane[data-tl-lane] .tl-cell[aria-selected="true"]'); return { n: c.length, w }; })()`);
  const pickDate = dstr(dnum(picked.w) + cellPt.day);
  await ev(`(() => { const sc = document.querySelector('[data-tl-scroller]'); sc.scrollLeft = sc.scrollWidth; })()`);
  await waitWin(await ev(`document.querySelector('[data-tl-root]').getAttribute('data-tl-window')`));
  const after = await ev(`(() => { const r = document.querySelector('[data-tl-root]'); const w = r.getAttribute('data-tl-window').split('/')[0];
    const row = document.querySelector('.tl-lane[data-tl-lane="${cellPt.lane}"]'); const i = Math.round((Date.UTC(...'${pickDate}'.split('-').map((v, k) => k === 1 ? v - 1 : +v)) - Date.UTC(...w.split('-').map((v, k) => k === 1 ? v - 1 : +v))) / 86400000);
    const c = row && row.querySelector('.tl-cell[data-tl-day="' + i + '"]'); return { w, sel: c ? c.getAttribute('aria-selected') : 'not drawn', bars: document.querySelectorAll('.tl-sel').length }; })()`);
  check(picked.n >= 1 && after.w !== picked.w && (after.sel === 'true' || after.sel === 'not drawn') && after.bars >= (after.sel === 'true' ? 1 : 0),
    'A picked night stays picked through a refill', { picked: picked.n, pickDate, before: picked.w, after });
  await send('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await sleep(300);
} else check(false, 'An empty night on screen to pick');
await ev(`document.querySelector('[data-tl-today]').click()`); await sleep(500); await settle(); await sleep(2500); // back to the stays around today
const barPt = await ev(`(() => { const sc = document.querySelector('[data-tl-scroller]'); const sr = sc.getBoundingClientRect();
  const room = document.querySelector('.tl-head .tl-corner').getBoundingClientRect().width;
  for (const b of document.querySelectorAll('.tl-bar[data-tl-booking]')) { const r = b.getBoundingClientRect();
    if (r.width < 20 || r.right < sr.left + room + 20 || r.left > sr.left + sc.clientWidth - 20) continue; // partly on screen is enough
    return { id: b.getAttribute('data-tl-booking') }; } return null; })()`);
if (barPt) {
  await ev(`document.querySelector('.tl-bar[data-tl-booking="${barPt.id}"]').click()`);
  await sleep(900);
  const w0 = await ev(`document.querySelector('[data-tl-root]').getAttribute('data-tl-window')`);
  await ev(`(() => { const sc = document.querySelector('[data-tl-scroller]'); sc.scrollLeft = sc.scrollWidth; })()`);
  await waitWin(w0);
  const dr = await ev(`(() => { const d = document.querySelector('[data-tl-drawer]'); const b = document.querySelector('.tl-bar[data-tl-booking="${barPt.id}"]');
    return { open: !d.hidden, title: document.querySelector('[data-tl-drawer-title]').textContent, win: document.querySelector('[data-tl-root]').getAttribute('data-tl-window'), selected: b ? b.classList.contains('is-selected') : 'not drawn' }; })()`);
  check(dr.open && dr.win !== w0 && dr.selected !== false, 'The open booking panel stays open (bar still marked) through a refill', { before: w0, ...dr });
  await ev(`(() => { const c = document.querySelector('[data-tl-drawer-close]'); if (c) c.click(); })()`);
  await sleep(500);
} else check(false, 'A booking bar on screen to open');
s2.date = (await settle()).date;

// 4. Alt+Page Down = one screen right.
await ev(`document.querySelector('[data-tl-scroller]').focus()`);
const k = (type) => send('Input.dispatchKeyEvent', { type, key: 'PageDown', code: 'PageDown', windowsVirtualKeyCode: 34, nativeVirtualKeyCode: 34, modifiers: 1 });
await k('rawKeyDown'); await k('keyUp');
await sleep(1500);
const s3 = await settle();
check(dnum(s3.date) - dnum(s2.date) === 14, 'Alt+Page Down: one screen (14 days) right', { from: s2.date, to: s3.date });
const kUp = (type) => send('Input.dispatchKeyEvent', { type, key: 'PageUp', code: 'PageUp', windowsVirtualKeyCode: 33, nativeVirtualKeyCode: 33, modifiers: 1 });
await kUp('rawKeyDown'); await kUp('keyUp');
await sleep(1500);
const s3b = await settle();
check(dnum(s3b.date) - dnum(s3.date) === -14, 'Alt+Page Up: one screen (14 days) left', { from: s3.date, to: s3b.date });

// 5. Drag a confirmed stay to the right edge: the grid scrolls and the Change dates question has the further dates.
await ev(`document.querySelector('[data-tl-today]').click()`);
await sleep(1500); await settle();
const pick = await ev(`(async () => {
  const sc = document.querySelector('[data-tl-scroller]');
  const cfg = JSON.parse(document.querySelector('[data-tl-json]').textContent);
  // The data the grid shows now (a refill may have replaced the first-paint JSON): read it from the bars on screen.
  const bars = [...document.querySelectorAll('.tl-bar[data-tl-draggable="true"]')];
  const sr = sc.getBoundingClientRect(); const room = document.querySelector('.tl-head .tl-corner').getBoundingClientRect().width;
  for (const bar of bars) {
    const r = bar.getBoundingClientRect();
    if (r.width < 30 || r.left < sr.left + room + 10 || r.left + 40 > sr.left + sc.clientWidth - 150) continue;
    if (!/tl-bar--confirmed/.test(bar.className) || bar.querySelector('.tl-bar__grip--start') === null) continue;
    const bid = bar.getAttribute('data-tl-booking');
    sc.scrollTop += r.top - (sr.top + sr.height / 2); // up / down only: the day under it stays
    await new Promise(res => setTimeout(res, 1200));
    const r2 = document.querySelector('.tl-bar[data-tl-booking="' + bid + '"]').getBoundingClientRect();
    return { id: bar.getAttribute('data-tl-booking'), x: r2.left + Math.min(30, r2.width / 2), id2: bid, y: r2.top + r2.height / 2, label: bar.getAttribute('aria-label'),
      right: sr.left + sc.clientWidth - 15, dayW: document.querySelector('.tl-head__day').getBoundingClientRect().width };
  }
  return { none: true, n: bars.length, today: cfg.data.today, bars: bars.map(b => { const r = b.getBoundingClientRect(); return [b.className, Math.round(r.left), Math.round(r.right), !!b.querySelector('.tl-bar__grip--start')]; }), sr: [sr.left, sc.clientWidth, room] };
})()`);
console.log('drag pick', JSON.stringify(pick));
if (pick && pick.x) {
  const before = await state();
  await mouse('mouseMoved', pick.x, pick.y); await mouse('mousePressed', pick.x, pick.y);
  const steps = 12;
  for (let i = 1; i <= steps; i++) { await mouse('mouseMoved', pick.x + (pick.right - pick.x) * i / steps, pick.y); await sleep(30); }
  await sleep(1200); // held at the edge: auto-scroll
  const during = await state();
  await mouse('mouseReleased', pick.right, pick.y);
  await sleep(800);
  const dlg = await ev(`(() => { const m = document.querySelector('.admin-confirm-overlay'); return m && getComputedStyle(m).display !== 'none' ? m.innerText.replace(/\\s+/g, ' ').slice(0, 400) : null; })()`);
  check(during.left > before.left + 20, 'Drag at the right edge scrolls the grid', { before: before.left, during: during.left });
  check(!!dlg && /Change dates/.test(dlg), 'Drop opens the Change dates question', dlg);
  // Which shift does the question name? Compare with the shift the pointer alone would have made.
  const m = dlg && dlg.match(/^.*?: (.+?) \((\d+) nights?\) to (.+?) \((\d+) nights?\)\?/);
  let shift = null;
  const info = await ev(`(() => { const b = JSON.parse(document.querySelector('[data-tl-json]').textContent).data.bookings.find(x => String(x.id) === ${JSON.stringify(pick.id)}); return b ? { ci: b.ci, co: b.co } : null; })()`);
  if (m && info) {
    for (let k2 = -200; k2 <= 200; k2++) if (fmtRange(dstr(dnum(info.ci) + k2), dstr(dnum(info.co) + k2)) === m[3]) { shift = k2; break; }
  }
  const pointerOnly = Math.round((pick.right - pick.x) / pick.dayW);
  check(shift !== null && shift > pointerOnly, 'The new dates include the scrolled distance (shift > pointer-only shift)', { shift, pointerOnly, from: m && m[1], to: m && m[3] });
  check(during.left <= before.sw - before.cw + 1 && during.sw <= before.sw + 1, 'The drag never scrolls past the drawn window (the ghost does not widen the grid)', { left: during.left, maxLeft: before.sw - before.cw, sw: during.sw, sw0: before.sw });
  const wp = (before.win || '').split('/');
  const winLast = wp.length === 2 ? dnum(wp[0]) + (+wp[1]) - 1 : null;
  check(shift !== null && info && winLast !== null && dnum(info.co) + shift - 1 <= winLast, 'The proposed last night is inside the drawn window', { win: before.win, newLastNight: shift !== null && info ? dstr(dnum(info.co) + shift - 1) : null });
  await ev(`(() => { const c = document.querySelector('.admin-confirm-overlay [data-admin-confirm-cancel].btn') || document.querySelector('.admin-confirm-overlay [data-admin-confirm-cancel]'); if (c) c.click(); })()`);
  await sleep(600);
  const after = await ev(`({ href: location.pathname + location.search, ghosts: document.querySelectorAll('.tl-bar--ghost').length, mark: window.__tlMark || 0 })`);
  check(after.mark === 1 && /calendar\.php/.test(after.href) && after.ghosts === 0, 'Cancel: no page change, no ghost left (nothing saved)', after);
} else {
  check(false, 'A confirmed draggable stay on screen to drag', pick);
}

// 5a. Owner 2026-11-28: pending / tentative stays drag to other dates too (Change dates hand-off, no room move).
await sleep(600);
const pq = await ev(`(async () => {
  const cfg = JSON.parse(document.querySelector('[data-tl-json]').textContent).data;
  const rows = cfg.bookings.filter(b => (b.status === 'pending' || b.status === 'tentative'));
  const wrong = rows.filter(b => !b.joined && !b.group && !b.missed && b.ci >= cfg.today && !(b.drag && b.drag.dates && !b.drag.move));
  const sc = document.querySelector('[data-tl-scroller]'), sr = sc.getBoundingClientRect();
  const room = document.querySelector('.tl-head .tl-corner').getBoundingClientRect().width;
  const bars = [...document.querySelectorAll('.tl-bar--pending, .tl-bar--tentative')].filter(x => !x.classList.contains('tl-bar--ghost'));
  const locked = bars.filter(x => { const b = cfg.bookings.find(y => String(y.id) === x.getAttribute('data-tl-booking')); return b && b.drag && b.drag.dates && x.getAttribute('data-tl-draggable') !== 'true'; });
  for (const bar of bars) {
    if (bar.getAttribute('data-tl-draggable') !== 'true') continue;
    let r = bar.getBoundingClientRect();
    if (r.width < 30) continue;
    sc.scrollLeft += r.left - (sr.left + room + 120);
    sc.scrollTop += r.top - (sr.top + sr.height / 2);
    await new Promise(res => setTimeout(res, 1200));
    const b2 = document.querySelector('.tl-bar[data-tl-booking="' + bar.getAttribute('data-tl-booking') + '"]:not(.tl-bar--ghost)');
    if (!b2) continue;
    r = b2.getBoundingClientRect();
    if (r.left < sr.left + room + 5 || r.right > sr.left + sc.clientWidth - 5) continue;
    return { n: rows.length, wrong: wrong.map(b => b.id), locked: locked.length, id: b2.getAttribute('data-tl-booking'), x: r.left + Math.min(30, r.width / 2), y: r.top + r.height / 2,
      dayW: document.querySelector('.tl-head__day').getBoundingClientRect().width };
  }
  return { n: rows.length, wrong: wrong.map(b => b.id), locked: locked.length, none: true, bars: bars.length };
})()`);
console.log('pending pick', JSON.stringify(pq));
check(pq.wrong.length === 0, 'Pending / tentative upcoming stays may change dates by drag (no room move)', pq.wrong);
check(pq.locked === 0, 'Their bars are draggable on screen', pq.locked);
if (pq.x) {
  await mouse('mouseMoved', pq.x, pq.y); await mouse('mousePressed', pq.x, pq.y);
  for (let i = 1; i <= 6; i++) { await mouse('mouseMoved', pq.x + pq.dayW * 2 * i / 6, pq.y); await sleep(30); }
  await mouse('mouseReleased', pq.x + pq.dayW * 2, pq.y);
  await sleep(800);
  const pqDlg = await ev(`(() => { const m = document.querySelector('.admin-confirm-overlay'); return m && getComputedStyle(m).display !== 'none' ? m.innerText.replace(/\\s+/g, ' ').slice(0, 300) : null; })()`);
  check(!!pqDlg && /Change dates/.test(pqDlg), 'A pending / tentative stay dragged 2 nights opens the Change dates question', pqDlg);
  await ev(`(() => { const c = document.querySelector('.admin-confirm-overlay [data-admin-confirm-cancel].btn') || document.querySelector('.admin-confirm-overlay [data-admin-confirm-cancel]'); if (c) c.click(); })()`);
  await sleep(600);
  const pqAfter = await ev(`({ href: location.pathname, ghosts: document.querySelectorAll('.tl-bar--ghost').length })`);
  check(/calendar\.php/.test(pqAfter.href) && pqAfter.ghosts === 0, 'Cancel: nothing saved, no ghost left', pqAfter);
} else console.log('NOTE: no pending / tentative bar on screen to drag (data check above still ran)');

// 5b. Arrow keys on nights (commit bb3a41c): Right past the last drawn night goes on to the next night.
await sleep(800);
const edge = await ev(`(async () => { const sc = document.querySelector('[data-tl-scroller]'); sc.scrollLeft = sc.scrollWidth; await new Promise(r => setTimeout(r, 60));
  const r = document.querySelector('[data-tl-root]'); const [w, len] = r.getAttribute('data-tl-window').split('/');
  const row = [...document.querySelectorAll('.tl-lane[data-tl-lane]')].find(x => { const c = x.querySelector('.tl-cell[data-tl-day="' + (len - 1) + '"]'); if (!c) return false; const b = c.getBoundingClientRect(); return document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2) === c; });
  if (!row) return null; const c = row.querySelector('.tl-cell[data-tl-day="' + (len - 1) + '"]').getBoundingClientRect();
  return { w, len: +len, lane: row.getAttribute('data-tl-lane'), x: c.left + c.width / 2, y: c.top + c.height / 2 }; })()`);
if (edge) {
  const lastDay = dstr(dnum(edge.w) + edge.len - 1);
  await mouse('mouseMoved', edge.x, edge.y); await mouse('mousePressed', edge.x, edge.y); await mouse('mouseReleased', edge.x, edge.y);
  await send('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: 'ArrowRight', code: 'ArrowRight', windowsVirtualKeyCode: 39 });
  await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'ArrowRight', code: 'ArrowRight', windowsVirtualKeyCode: 39 });
  await sleep(3000); await settle();
  const fc = await ev(`(() => { const a = document.activeElement; const r = document.querySelector('[data-tl-root]'); const w = r.getAttribute('data-tl-window').split('/')[0];
    if (!a || !a.classList.contains('tl-cell')) return { focused: a ? a.className : null, w };
    const i = +a.getAttribute('data-tl-day'); const d = new Date((Math.round(Date.UTC(...w.split('-').map((v, k) => k === 1 ? v - 1 : +v)) / 86400000) + i) * 86400000).toISOString().slice(0, 10);
    const b = a.getBoundingClientRect(), sc = document.querySelector('[data-tl-scroller]').getBoundingClientRect();
    return { lane: a.closest('[data-tl-lane]').getAttribute('data-tl-lane'), d, w, onScreen: b.left >= sc.left && b.right <= sc.right, mark: window.__tlMark || 0 }; })()`);
  check(fc.lane === edge.lane && fc.d === dstr(dnum(lastDay) + 1) && fc.onScreen && fc.mark === 1, 'Arrow Right past the last drawn night: the cursor goes on to the next night, on screen', { lastDay, ...fc });
  await send('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
} else check(false, 'A night at the end of the window to start the arrow check');

// 6. Phone 390px: no sideways page scroll, the grid still snaps by day.
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
await send('Page.navigate', { url: BASE + '/admin/calendar.php' });
await sleep(4500);
console.log('phone page', await ev(`location.pathname + ' ' + document.title + ' root=' + !!document.querySelector('[data-tl-root]') + ' agenda=' + (document.querySelector('[data-tl-root]')||{classList:{contains(){}}}).classList.contains('is-agenda') + ' days=' + document.querySelectorAll('.tl-head__day').length`));
const ph = await ev(`(() => { const sc = document.querySelector('[data-tl-scroller]'); const de = document.documentElement; const cs = getComputedStyle(sc);
  const dw = document.querySelector('.tl-head__day').getBoundingClientRect().width;
  return { pageSw: de.scrollWidth, pageCw: de.clientWidth, sw: sc.scrollWidth, cw: sc.clientWidth, snap: cs.scrollSnapType, left: sc.scrollLeft, dayW: dw,
    win: document.querySelector('[data-tl-root]').getAttribute('data-tl-window'), date: document.querySelector('[data-tl-date]').value }; })()`);
check(ph.pageSw <= ph.pageCw, '390px: no sideways page scroll', { pageSw: ph.pageSw, pageCw: ph.pageCw });
check(ph.sw > ph.cw && /x/.test(ph.snap) && /mandatory/.test(ph.snap), '390px: grid scrolls sideways with day snap', ph);
check(Math.abs(ph.left / ph.dayW - Math.round(ph.left / ph.dayW)) < 0.05, '390px: first paint lands on a whole day', { left: ph.left, dayW: ph.dayW });

if (logs.length) { console.log(logs.join('\n')); check(false, 'No console errors'); } else check(true, 'No console errors');
console.log(fails ? `${fails} FAILED` : 'ALL PASS');
ws.close(); chrome.kill(); process.exit(fails ? 1 : 0);
