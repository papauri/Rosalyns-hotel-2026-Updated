#!/usr/bin/env node
// Regenerates .claude/dashboard.html from .claude/COST_LOG.md. Deterministic, no LLM call.
// Run after every /build-loop cycle (or every 3 tasks, matching the /compact cadence).

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const LOG_PATH = path.join(ROOT, 'COST_LOG.md');
const OUT_PATH = path.join(ROOT, 'dashboard.html');

// Approximate Pro-plan caps. Anthropic does not publish exact token figures for the
// 5-hour / weekly windows, so these are ballpark constants — adjust here if you learn
// better numbers from your own /usage output.
const SESSION_CAP_TOKENS = 250000; // rough Pro 5-hour-window ballpark
const WEEKLY_CAP_TOKENS = 1500000; // rough Pro weekly ballpark

const TIER_WEIGHT = { haiku: 1, sonnet: 4, opus: 19, 'fable-5': 19 };

function parseLog(md) {
  const lines = md.split('\n');
  const start = lines.findIndex(l => l.trim().startsWith('| Timestamp'));
  if (start === -1) return [];
  const rows = [];
  for (let i = start + 2; i < lines.length; i++) {
    const line = lines[i];
    if (!line.trim().startsWith('|')) continue;
    const cells = line.split('|').map(c => c.trim()).filter((_, idx, arr) => idx > 0 && idx < arr.length - 1);
    if (cells.length < 11) continue;
    const [timestamp, taskId, agent, model, estIn, estOut, estTotal, tier, actual, accuracy, flag] = cells;
    rows.push({
      timestamp, taskId, agent, model,
      estIn: Number(estIn) || 0,
      estOut: Number(estOut) || 0,
      estTotal: Number(estTotal) || 0,
      tier,
      actual: actual === '—' || actual === '' ? null : Number(actual) || 0,
      accuracy,
      flag: flag === '—' ? '' : flag,
    });
  }
  return rows;
}

function phaseOf(taskId) {
  const m = /^([A-Za-z]+\d*)-/.exec(taskId || '');
  return m ? m[1] : (taskId || 'unknown');
}

function aggregate(rows) {
  const byTask = {};
  const byAgent = {};
  const byPhase = {};
  let runningTotal = 0;

  for (const r of rows) {
    const tokens = r.actual !== null ? r.actual : r.estTotal;
    runningTotal += tokens;

    byTask[r.taskId] = (byTask[r.taskId] || 0) + tokens;
    byAgent[r.agent] = (byAgent[r.agent] || 0) + tokens;
    const phase = phaseOf(r.taskId);
    byPhase[phase] = (byPhase[phase] || 0) + tokens;
  }

  return { byTask, byAgent, byPhase, runningTotal };
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function buildHtml(rows, agg) {
  const taskLabels = Object.keys(agg.byTask);
  const taskData = taskLabels.map(k => agg.byTask[k]);
  const agentLabels = Object.keys(agg.byAgent);
  const agentData = agentLabels.map(k => agg.byAgent[k]);
  const phaseLabels = Object.keys(agg.byPhase);
  const phaseData = phaseLabels.map(k => agg.byPhase[k]);

  const sessionPct = Math.min(100, (agg.runningTotal / SESSION_CAP_TOKENS) * 100).toFixed(1);
  const weeklyPct = Math.min(100, (agg.runningTotal / WEEKLY_CAP_TOKENS) * 100).toFixed(1);

  const tableRows = rows.map(r => `
        <tr class="${r.flag ? 'flagged' : ''}">
          <td>${esc(r.timestamp)}</td>
          <td>${esc(r.taskId)}</td>
          <td>${esc(r.agent)}</td>
          <td>${esc(r.model)}</td>
          <td>${r.estTotal.toLocaleString()}</td>
          <td>${r.actual !== null ? r.actual.toLocaleString() : '—'}</td>
          <td>${esc(r.accuracy || '—')}</td>
          <td>${r.flag ? '⚠ ' + esc(r.flag) : ''}</td>
        </tr>`).join('');

  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Build Cost Dashboard — Rosalyn's Hotel 2026</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<style>
  :root { --bg:#f5f2eb; --text:#3e3930; --accent:#8B7355; --gold:#C8A45A; --border:#d5cfc4; --card:#faf8f4; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#16140f; --text:#e8e2d5; --accent:#c9a879; --gold:#d8b968; --border:#3a352b; --card:#1e1b14; }
  }
  * { box-sizing: border-box; }
  body { margin:0; font-family:'DM Sans',system-ui,sans-serif; background:var(--bg); color:var(--text); padding:24px; }
  h1 { font-family:'DM Serif Display',Georgia,serif; font-weight:400; letter-spacing:0.01em; margin:0 0 4px; }
  .sub { color:var(--accent); margin-bottom:24px; font-size:0.9rem; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:20px; margin-bottom:24px; }
  .card { background:var(--card); border:1px solid var(--border); border-radius:2px; padding:20px; box-shadow:0 4px 16px rgba(70,60,50,0.08); }
  .card h2 { margin:0 0 12px; font-size:1rem; letter-spacing:0.04em; text-transform:uppercase; color:var(--accent); }
  .stat { font-size:2rem; font-weight:600; }
  .stat-label { font-size:0.8rem; color:var(--accent); }
  .bar-track { background:var(--border); border-radius:4px; height:10px; margin-top:8px; overflow:hidden; }
  .bar-fill { background:var(--gold); height:100%; }
  table { width:100%; border-collapse:collapse; font-size:0.85rem; }
  th, td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--border); }
  th { color:var(--accent); text-transform:uppercase; font-size:0.75rem; letter-spacing:0.04em; }
  tr.flagged { background:rgba(200,84,84,0.08); }
  .table-wrap { overflow-x:auto; }
  .note { font-size:0.78rem; color:var(--accent); margin-top:8px; }
</style>
</head>
<body>
<h1>Build Cost Dashboard</h1>
<div class="sub">Rosalyn's Hotel 2026 — generated from .claude/COST_LOG.md, refreshed each /build-loop cycle</div>

<div class="grid">
  <div class="card">
    <h2>Running total (this log)</h2>
    <div class="stat">${agg.runningTotal.toLocaleString()}</div>
    <div class="stat-label">tokens across ${rows.length} dispatches</div>
  </div>
  <div class="card">
    <h2>Vs. session cap (approx.)</h2>
    <div class="stat">${sessionPct}%</div>
    <div class="bar-track"><div class="bar-fill" style="width:${sessionPct}%"></div></div>
    <div class="note">of ~${SESSION_CAP_TOKENS.toLocaleString()} token ballpark for a Pro 5-hour window — not an official figure</div>
  </div>
  <div class="card">
    <h2>Vs. weekly cap (approx.)</h2>
    <div class="stat">${weeklyPct}%</div>
    <div class="bar-track"><div class="bar-fill" style="width:${weeklyPct}%"></div></div>
    <div class="note">of ~${WEEKLY_CAP_TOKENS.toLocaleString()} token ballpark for a Pro weekly window — not an official figure</div>
  </div>
</div>

<div class="grid">
  <div class="card"><h2>Cost per task</h2><canvas id="taskChart"></canvas></div>
  <div class="card"><h2>Cost per agent type</h2><canvas id="agentChart"></canvas></div>
  <div class="card"><h2>Cost per phase</h2><canvas id="phaseChart"></canvas></div>
</div>

<div class="card">
  <h2>Dispatch log</h2>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Time</th><th>Task</th><th>Agent</th><th>Model</th><th>Est.</th><th>Actual</th><th>Accuracy</th><th>Flag</th></tr></thead>
    <tbody>${tableRows || '<tr><td colspan="8">No entries yet.</td></tr>'}</tbody>
  </table>
  </div>
</div>

<script>
const palette = ['#8B7355','#C8A45A','#6B5740','#A38A6B','#D8C4A0','#5C4A34'];
function bar(id, labels, data) {
  new Chart(document.getElementById(id), {
    type: 'bar',
    data: { labels, datasets: [{ data, backgroundColor: palette }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });
}
bar('taskChart', ${JSON.stringify(taskLabels)}, ${JSON.stringify(taskData)});
bar('agentChart', ${JSON.stringify(agentLabels)}, ${JSON.stringify(agentData)});
bar('phaseChart', ${JSON.stringify(phaseLabels)}, ${JSON.stringify(phaseData)});
</script>
</body>
</html>
`;
}

function main() {
  const md = fs.existsSync(LOG_PATH) ? fs.readFileSync(LOG_PATH, 'utf8') : '';
  const rows = parseLog(md);
  const agg = aggregate(rows);
  const html = buildHtml(rows, agg);
  fs.writeFileSync(OUT_PATH, html, 'utf8');
  console.log(`dashboard.html refreshed: ${rows.length} rows, ${agg.runningTotal.toLocaleString()} tokens total`);
}

main();
