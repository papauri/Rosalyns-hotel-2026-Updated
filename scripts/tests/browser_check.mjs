// Headless-Chrome browser check over the DevTools protocol (no dependencies, no MCP).
// 1. Serve the repo:   php -S 0.0.0.0:8000 -t . scripts/tests/dev-router.php
// 2. Run steps:        node scripts/tests/browser_check.mjs steps.json  [BASE=http://localhost:8000]
// steps: [{viewport:[w,h]} | {nav:"{base}/__devlogin?to=pos.php", after:ms} | {eval:"js", label} | {wait:ms} | {shot:"file.png"} | {init:"js"}]
// Real pointer input: {click:[x,y]} | {clickSel:"css", label} (scrolls into view, clicks the centre, prints the element
// actually under the pointer so overlays that swallow clicks show up) | {type:"text"} (Input.insertText into the focused field)
// | {key:"Tab"|"Enter"|...}.
// "{base}" in nav is replaced by $BASE (default http://localhost:8000). /__devlogin signs in as the
// first active admin (dev-router.php only, built-in server only). Console errors/exceptions print.
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const steps = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const port = Number(process.env.CDP_PORT || 9333); // set CDP_PORT when two checks run at once
const profile = mkdtempSync(join(tmpdir(), 'cdp-'));
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', [
  '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
  '--no-first-run', '--window-size=1400,1000', 'about:blank'], { stdio: 'ignore' });
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

let targets;
for (let i = 0; i < 50; i++) {
  try { targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json(); if (targets.length) break; } catch {}
  await sleep(200);
}
const page = targets.find(t => t.type === 'page');
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise(r => ws.addEventListener('open', r));
let id = 0; const pending = new Map(); const logs = [];
ws.addEventListener('message', (ev) => {
  const m = JSON.parse(ev.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return; }
  if (m.method === 'Runtime.consoleAPICalled') logs.push(`[console.${m.params.type}] ` + m.params.args.map(a => a.value ?? a.description ?? '').join(' '));
  if (m.method === 'Runtime.exceptionThrown') logs.push('[exception] ' + (m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text) + ' @' + (m.params.exceptionDetails.url || '') + ':' + m.params.exceptionDetails.lineNumber);
  if (m.method === 'Page.javascriptDialogOpening') { logs.push('[dialog] ' + m.params.message); send('Page.handleJavaScriptDialog', { accept: false }); }
});
function send(method, params = {}) { const i = ++id; ws.send(JSON.stringify({ id: i, method, params })); return new Promise(r => pending.set(i, r)); }
await send('Runtime.enable'); await send('Page.enable');

for (const s of steps) {
  if (s.nav) { s.nav = s.nav.replace("{base}", process.env.BASE || "http://localhost:8000"); await send("Page.navigate", { url: s.nav }); await sleep(s.after ?? 2500); console.log('NAV', s.nav); }
  if (s.viewport) { await send('Emulation.setDeviceMetricsOverride', { width: s.viewport[0], height: s.viewport[1], deviceScaleFactor: 1, mobile: s.viewport[0] < 700 }); }
  if (s.init) { await send('Page.addScriptToEvaluateOnNewDocument', { source: s.init }); }
  if (s.wait) await sleep(s.wait);
  if (s.clickSel) {
    const r = await send('Runtime.evaluate', { returnByValue: true, expression: `(() => { const e = document.querySelector(${JSON.stringify(s.clickSel)}); if (!e) return null; e.scrollIntoView({block:'center'}); const b = e.getBoundingClientRect(); const x = b.x + b.width/2, y = b.y + b.height/2; const t = document.elementFromPoint(x, y); return {x, y, hit: t ? (t.tagName + (t.id ? '#' + t.id : '') + (t.className && t.className.baseVal === undefined ? '.' + String(t.className).split(' ').join('.') : '')) : null, inside: !!t && (t === e || e.contains(t) || t.contains(e))}; })()` });
    const v = r.result?.result?.value;
    if (!v) { console.log('CLICKSEL', s.clickSel, '=> NOT FOUND'); }
    else { console.log('CLICKSEL', s.clickSel, '=>', JSON.stringify(v)); s.click = [v.x, v.y]; }
  }
  if (s.click) {
    const [x, y] = s.click; const base = { x, y, button: 'left', clickCount: 1 };
    await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
    await send('Input.dispatchMouseEvent', { ...base, type: 'mousePressed' });
    await send('Input.dispatchMouseEvent', { ...base, type: 'mouseReleased' });
    await sleep(s.after ?? 400);
  }
  if (s.type !== undefined) { await send('Input.insertText', { text: s.type }); await sleep(150); }
  if (s.key) { const k = s.key; const code = { Enter: 13, Tab: 9, Escape: 27 }[k] || 0; for (const t of ['keyDown', 'keyUp']) await send('Input.dispatchKeyEvent', { type: t, key: k, code: k, windowsVirtualKeyCode: code }); await sleep(150); }
  if (s.eval) {
    const r = await send('Runtime.evaluate', { expression: s.eval, awaitPromise: true, returnByValue: true });
    console.log('EVAL', s.label || '', '=>', JSON.stringify(r.result?.result?.value ?? r.result?.exceptionDetails?.exception?.description ?? r.result));
  }
  if (s.shot) { const r = await send('Page.captureScreenshot', { format: 'png' }); writeFileSync(s.shot, Buffer.from(r.result.data, 'base64')); console.log('SHOT', s.shot); }
  if (logs.length) { console.log(logs.splice(0).join('\n')); }
}
ws.close(); chrome.kill();
process.exit(0);
