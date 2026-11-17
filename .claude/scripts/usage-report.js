#!/usr/bin/env node
// Weekly Claude Code usage report (owner 2026-11-18). Replaces the hand-kept COST_LOG.md.
// Reads the session logs Claude Code already writes (~/.claude/projects/**/*.jsonl). No AI calls, no network.
//
//   node .claude/scripts/usage-report.js                 last 7 days, printed
//   node .claude/scripts/usage-report.js --save          also writes MSP/usage-reports/<end>.md + a HISTORY.md row
//   node .claude/scripts/usage-report.js --since=2026-11-10 --until=2026-11-17 [--save] [--out=<dir>]
//
// Cost = every step re-sends the whole conversation. "Weighted" tokens = fresh input + 1.25 x cache write
// + 0.1 x cache read + 5 x output (input-equivalent; same weights for every model, so compare models by share).
// Canonical copy lives in Rosalyns-hotel-2026; Liwonde points here. It covers ALL projects, not just this repo.

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const args = Object.fromEntries(process.argv.slice(2).map((a) => {
  const m = a.match(/^--([^=]+)(?:=(.*))?$/);
  return m ? [m[1], m[2] === undefined ? true : m[2]] : [a, true];
}));
const DAY = 86400000;
// A bare date means local midnight (the start of that day here), not UTC.
const when = (s) => new Date(/^\d{4}-\d{2}-\d{2}$/.test(s) ? s + 'T00:00' : s);
const UNTIL = args.until ? when(String(args.until)) : new Date();
const SINCE = args.since ? when(String(args.since)) : new Date(UNTIL - 7 * DAY);
if (isNaN(UNTIL) || isNaN(SINCE) || SINCE >= UNTIL) { console.error('Bad --since/--until'); process.exit(1); }

const HOME = process.env.USERPROFILE || process.env.HOME;
const LOGS = path.join(HOME, '.claude', 'projects');
const MSP = path.resolve(__dirname, '..', '..', '..');           // .../OneDrive/MSP
const OUT = args.out ? path.resolve(String(args.out)) : path.join(MSP, 'usage-reports');
const HOTELS = ['Rosalyns-hotel-2026', 'Liwonde_Sun_Hotel_2026'];
const BIG_LINES = 400, BIG_BYTES = 40000;                        // same limits as read-guard.php
const MAIN_BIG = 300000;                                         // main sessions should compact at ~250k now

// ---------- collect ----------
function walk(d, out) {
  let ents; try { ents = fs.readdirSync(d, { withFileTypes: true }); } catch { return out; }
  for (const e of ents) {
    const p = path.join(d, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name.endsWith('.jsonl')) { try { if (fs.statSync(p).mtime >= SINCE) out.push(p); } catch {} }
  }
  return out;
}
const projName = (f) => path.relative(LOGS, f).split(path.sep)[0]
  .replace(/^C--Users-[^-]+(?:-[^-]+)*?-OneDrive-MSP-/, '').replace(/^C--Users-.*$/, '~home');

const blank = () => ({ calls: 0, in: 0, cw: 0, cr: 0, out: 0 });
const add = (m, k, u) => {
  const r = m[k] || (m[k] = blank());
  r.calls++; r.in += u.input_tokens || 0; r.cw += u.cache_creation_input_tokens || 0;
  r.cr += u.cache_read_input_tokens || 0; r.out += u.output_tokens || 0;
};
const weight = (r) => r.in + 1.25 * r.cw + 0.1 * r.cr + 5 * r.out;

const total = blank(), byProj = {}, byModel = {}, bySide = {}, byAgent = {};
const runs = [];           // one per subagent file
const mains = [];          // one per main-session file
const seen = new Map();    // message id -> file (global de-dupe; resumed/forked sessions repeat messages)
let dupes = 0, guardBlocks = 0, codeIndexUses = 0;
const bigWhole = {};
const sizeCache = new Map();
function isBig(p) {
  if (sizeCache.has(p)) return sizeCache.get(p);
  let big = false;
  try {
    const st = fs.statSync(p);
    // Same scope as read-guard.php: text files inside the MSP projects.
    if (st.isFile() && path.resolve(p).toLowerCase().startsWith(MSP.toLowerCase() + path.sep)
      && /\.(php|js|mjs|css|html?|md|txt|json|sql|csv|xml|ya?ml|ini|sh|ps1)$/i.test(p)) {
      if (st.size > BIG_BYTES) big = true;
      else big = fs.readFileSync(p, 'utf8').split('\n').length > BIG_LINES;
    }
  } catch {}
  sizeCache.set(p, big);
  return big;
}

const agentMeta = (f) => {
  try { return JSON.parse(fs.readFileSync(f.replace(/\.jsonl$/, '.meta.json'), 'utf8')).agentType || '?'; }
  catch { return '?'; }
};

for (const f of walk(LOGS, [])) {
  const sub = f.includes(path.sep + 'subagents' + path.sep);
  const type = sub ? agentMeta(f) : 'main';
  const proj = projName(f);
  let calls = 0, peak = 0, cwd = null;
  const uses = {};
  let text;
  try { text = fs.readFileSync(f, 'utf8'); } catch { continue; }
  for (const line of text.split('\n')) {
    if (!line) continue;
    let j; try { j = JSON.parse(line); } catch { continue; }
    const ts = new Date(j.timestamp);
    if (!(ts >= SINCE && ts < UNTIL)) continue;
    if (j.cwd && !cwd) cwd = j.cwd;
    const c = j.message && j.message.content;
    if (j.type === 'assistant' && j.message && j.message.usage) {
      const model = String(j.message.model || '?').replace(/^claude-/, '');
      if (model === '<synthetic>') continue;
      const id = (j.message.id || '') + '|' + (j.requestId || '');
      if (seen.has(id)) { if (seen.get(id) !== f) dupes++; continue; }
      seen.set(id, f);
      const u = j.message.usage;
      calls++;
      peak = Math.max(peak, (u.input_tokens || 0) + (u.cache_creation_input_tokens || 0) + (u.cache_read_input_tokens || 0));
      add({ t: total }, 't', u);
      add(byProj, proj, u); add(byModel, model, u); add(bySide, sub ? 'subagent' : 'main', u);
      add(byAgent, type + ' [' + model.replace(/-\d.*$/, '') + ']', u);
      if (Array.isArray(c)) for (const x of c) if (x.type === 'tool_use') {
        const inp = x.input || {};
        uses[x.id] = x.name;
        const target = inp.file_path || inp.path || inp.pattern || '';
        if (/CODE_INDEX/.test(target)) codeIndexUses++;
        if (x.name === 'Read' && inp.file_path && !(inp.offset || inp.limit || inp.pages) && isBig(inp.file_path)) {
          const n = path.basename(inp.file_path);
          bigWhole[n] = (bigWhole[n] || 0) + 1;
        }
      }
    }
    if (j.type === 'user' && Array.isArray(c)) for (const x of c) {
      if (x.type === 'tool_result' && uses[x.tool_use_id] === 'Read'
        && /whole reads of files over/.test(JSON.stringify(x.content || ''))) guardBlocks++;
    }
  }
  if (!calls) continue;
  if (sub) runs.push({ type, proj, calls, peak, cwd });
  else mains.push({ proj, calls, peak, id: path.basename(f, '.jsonl').slice(0, 8) });
}

// Copy runs back into byAgent totals for the totals table (already done above); now agent caps.
const capCache = new Map();
function maxTurns(cwd, type) {
  const key = cwd + '|' + type;
  if (capCache.has(key)) return capCache.get(key);
  let cap = null;
  try {
    const md = fs.readFileSync(path.join(cwd, '.claude', 'agents', type + '.md'), 'utf8');
    const m = md.match(/^maxTurns:\s*(\d+)/m);
    if (m) cap = +m[1];
  } catch {}
  capCache.set(key, cap);
  return cap;
}

// ---------- commits ----------
function commits(repo) {
  try {
    return +execFileSync('git', ['-C', path.join(MSP, repo), 'rev-list', '--count', '--all',
      '--since=' + SINCE.toISOString(), '--until=' + UNTIL.toISOString()], { encoding: 'utf8' }).trim();
  } catch { return null; }
}
const repos = fs.readdirSync(MSP, { withFileTypes: true })
  .filter((e) => e.isDirectory() && fs.existsSync(path.join(MSP, e.name, '.git'))).map((e) => e.name);
const commitBy = Object.fromEntries(repos.map((r) => [r, commits(r)]));
const hotelCommits = HOTELS.reduce((a, r) => a + (commitBy[r] || 0), 0);
const allCommits = Object.values(commitBy).reduce((a, n) => a + (n || 0), 0);

// ---------- report ----------
const M = (x) => (x / 1e6).toFixed(1) + 'M';
const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0) + '%';
const q = (arr, p) => { const s = [...arr].sort((a, b) => a - b); return s.length ? s[Math.min(s.length - 1, Math.floor(s.length * p))] : 0; };
const tw = weight(total);
const pad = (n) => String(n).padStart(2, '0');
const day = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const stamp = (d) => `${day(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}`.replace(/ 00:00$/, '');
// --until is exclusive: a bare end date shows as the last whole day covered.
const endLabel = /:/.test(stamp(UNTIL)) ? stamp(UNTIL) : day(new Date(UNTIL - 1));
const label = `${stamp(SINCE)} to ${endLabel}`;
const L = [];
const table = (title, m, n = 12) => {
  L.push('', `### ${title}`, '', '| | calls | weighted | share | avg context/call |', '|---|---:|---:|---:|---:|');
  Object.entries(m).sort((a, b) => weight(b[1]) - weight(a[1])).slice(0, n).forEach(([k, r]) =>
    L.push(`| ${k} | ${r.calls} | ${M(weight(r))} | ${pct(weight(r), tw)} | ${Math.round((r.in + r.cw + r.cr) / r.calls / 1000)}k |`));
};

const opus = Object.entries(byModel).filter(([k]) => /opus/.test(k)).reduce((a, [, r]) => a + r.calls, 0);
const subCalls = (bySide.subagent || blank()).calls;
const isLead = (t) => /^dept-|specialist|artifacts-lead|^ui-/.test(t);
const leadSteps = runs.filter((r) => isLead(r.type)).map((r) => r.calls);
const qaSteps = runs.filter((r) => r.type === 'qa-auditor').map((r) => r.calls);
const overCap = runs.map((r) => ({ ...r, cap: r.cwd ? maxTurns(r.cwd, r.type) : null })).filter((r) => r.cap && r.calls > r.cap);
const hotelMains = mains.filter((m) => HOTELS.some((h) => m.proj.replace(/_/g, '-') === h.replace(/_/g, '-')));
const bigMains = mains.filter((m) => m.peak > MAIN_BIG);
const bigWholeTotal = Object.values(bigWhole).reduce((a, n) => a + n, 0);

L.push(`# Claude Code usage: ${label}`, '',
  `Generated ${stamp(new Date())} (local time) by \`Rosalyns-hotel-2026/.claude/scripts/usage-report.js\`.`, '',
  '## Headline', '',
  `- **${total.calls.toLocaleString()} model calls**, ${M(tw)} weighted tokens (cache read ${M(total.cr)}, cache write ${M(total.cw)}, output ${M(total.out)}).`,
  `- **Calls per commit: ${hotelCommits ? Math.round(total.calls / hotelCommits) : 'n/a'}** (hotel repos: ${hotelCommits} commits; all repos: ${allCommits}, ${allCommits ? Math.round(total.calls / allCommits) : 'n/a'} per commit).`,
  `- Opus share of calls: ${pct(opus, total.calls)}. Subagent share of calls: ${pct(subCalls, total.calls)}.`,
  `- Lead/specialist runs: ${leadSteps.length}, steps median ${q(leadSteps, 0.5)} / p90 ${q(leadSteps, 0.9)} / max ${Math.max(0, ...leadSteps)}. qa-auditor runs: ${qaSteps.length} (p90 ${q(qaSteps, 0.9)}); qa-quick runs: ${runs.filter((r) => r.type === 'qa-quick').length}.`,
  '', '## Are the 2026-11-17 limits working?', '',
  `- Agent runs past their current \`maxTurns\`: **${overCap.length}** (should be 0 for runs after 17 Nov).` +
    (overCap.length ? ' ' + overCap.slice(0, 8).map((r) => `${r.type} ${r.calls}/${r.cap} (${r.proj})`).join(', ') : ''),
  `- Main sessions with peak context over ${MAIN_BIG / 1000}k: **${bigMains.length}** of ${mains.length} (hotel repos: ${bigMains.filter((m) => hotelMains.includes(m)).length} of ${hotelMains.length}). Hotel sessions should compact near 250k; a session started before 17 Nov keeps the old window until /clear.`,
  `- Whole reads of big files (over ${BIG_LINES} lines / ${BIG_BYTES / 1000} KB today): **${bigWholeTotal}**; read-guard blocks: ${guardBlocks}. CODE_INDEX uses: ${codeIndexUses}.`,
  bigWholeTotal ? '  Top: ' + Object.entries(bigWhole).sort((a, b) => b[1] - a[1]).slice(0, 8).map(([k, n]) => `${k} ${n}`).join(', ') : null,
  `- Messages repeated across log files (resumed/forked sessions, counted once): ${dupes}.`);

table('By project', byProj);
table('By model', byModel);
table('Main session vs subagents', bySide);
table('By agent type [model]', byAgent, 20);

L.push('', '### Agent runs (steps per run)', '', '| agent | runs | median | p90 | max | cap | avg peak context |', '|---|---:|---:|---:|---:|---:|---:|');
const types = {};
for (const r of runs) (types[r.type] || (types[r.type] = [])).push(r);
Object.entries(types).sort((a, b) => b[1].length - a[1].length).forEach(([t, rs]) => {
  const s = rs.map((r) => r.calls);
  const cap = rs.find((r) => r.cwd) ? maxTurns(rs.find((r) => r.cwd).cwd, t) : null;
  L.push(`| ${t} | ${rs.length} | ${q(s, 0.5)} | ${q(s, 0.9)} | ${Math.max(...s)} | ${cap || '-'} | ${Math.round(rs.reduce((a, r) => a + r.peak, 0) / rs.length / 1000)}k |`);
});

L.push('', '### Biggest main sessions', '');
mains.sort((a, b) => b.peak - a.peak).slice(0, 8).forEach((m) => L.push(`- ${m.proj} ${m.id}: peak ${Math.round(m.peak / 1000)}k, ${m.calls} calls`));
L.push('', '### Commits', '', Object.entries(commitBy).filter(([, n]) => n).map(([r, n]) => `${r} ${n}`).join(' · ') || 'none', '');

const report = L.filter((x) => x !== null).join('\n');
process.stdout.write(report + '\n');

if (args.save) {
  fs.mkdirSync(OUT, { recursive: true });
  const file = path.join(OUT, day(new Date(UNTIL - 1)) + '.md');
  fs.writeFileSync(file, report + '\n');
  const hist = path.join(OUT, 'HISTORY.md');
  const head = '# Weekly Claude Code usage (one row per report; newest last)\n\n'
    + '| period | calls | weighted | hotel commits | calls/commit | Opus | subagent | lead median/p90 | past cap | main >300k | big whole reads |\n'
    + '|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|\n';
  let rows = fs.existsSync(hist) ? fs.readFileSync(hist, 'utf8').split('\n').filter((l) => /^\| \d{4}-/.test(l)) : [];
  rows = rows.filter((l) => !l.startsWith(`| ${stamp(SINCE)} to `));   // a re-run replaces its row
  rows.push(`| ${label} | ${total.calls} | ${M(tw)} | ${hotelCommits} | ${hotelCommits ? Math.round(total.calls / hotelCommits) : '-'} | ${pct(opus, total.calls)} | ${pct(subCalls, total.calls)} | ${q(leadSteps, 0.5)}/${q(leadSteps, 0.9)} | ${overCap.length} | ${bigMains.length} | ${bigWholeTotal} |`);
  rows.sort();
  fs.writeFileSync(hist, head + rows.join('\n') + '\n');
  console.error(`saved ${file} and ${hist}`);
}
