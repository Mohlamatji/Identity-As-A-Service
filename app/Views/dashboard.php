<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Identity Vault - Dashboard</title>
<link rel="stylesheet" href="css/app.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="font-sans text-slate-200 antialiased">

<header class="border-b border-line">
  <div class="mx-auto max-w-6xl px-6 md:px-10 h-16 flex items-center justify-between">
    <div class="flex items-center gap-2.5">
      <span class="text-gold w-6 h-6"><svg viewBox="0 0 24 24" fill="none"><path d="M12 3l7 3v5.5c0 4.5-3 7.8-7 9.5-4-1.7-7-5-7-9.5V6l7-3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
      <span class="font-semibold text-white tracking-tight">Identity Vault</span>
    </div>
    <nav class="flex items-center gap-1 text-sm">
      <a href="./" class="px-3 py-1.5 rounded text-mute hover:text-white">Console</a>
      <a href="checkout" class="px-3 py-1.5 rounded text-mute hover:text-white">PayPal checkout</a>
      <a href="dashboard" class="px-3 py-1.5 rounded text-white bg-white/5">Dashboard</a>
      <a href="api-docs" class="px-3 py-1.5 rounded text-mute hover:text-white">API docs</a>
    </nav>
  </div>
</header>

<main class="mx-auto max-w-6xl px-6 py-10 md:px-10">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-8">
    <p class="text-sm text-mute">Decisions, explainable risk factors, PayPal outcomes and a tamper-evident audit trail.</p>
    <span id="chain_badge" class="text-xs rounded border border-line px-2.5 py-1 text-mute">Verifying audit chain...</span>
  </div>

  <div id="stats" class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6"></div>

  <div class="grid md:grid-cols-2 gap-3 mb-10">
    <div class="rounded-lg border border-line bg-panel p-4">
      <div class="text-xs text-mute mb-3">Risk score distribution</div>
      <div id="histogram" class="flex items-end gap-1.5 h-24"></div>
      <div class="flex justify-between text-[10px] text-mute mono mt-1"><span>0</span><span>50</span><span>100</span></div>
    </div>
    <div class="rounded-lg border border-line bg-panel p-4">
      <div class="text-xs text-mute mb-3">Outcome mix &amp; required actions</div>
      <div id="mix" class="space-y-2"></div>
    </div>
  </div>

  <section class="mb-10">
    <h2 class="text-sm font-medium text-white mb-3">Recent transactions <span class="text-mute font-normal">- click a row for the factor breakdown</span></h2>
    <div class="rounded-lg border border-line bg-panel overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-xs text-mute border-b border-line">
          <th class="px-4 py-3 font-normal">ID</th><th class="px-4 py-3 font-normal">User</th><th class="px-4 py-3 font-normal">Amount</th>
          <th class="px-4 py-3 font-normal">Score</th><th class="px-4 py-3 font-normal">Decision</th><th class="px-4 py-3 font-normal">PayPal</th><th class="px-4 py-3 font-normal">Time</th>
        </tr></thead>
        <tbody id="tx_body"></tbody>
      </table>
    </div>
    <div id="detail" class="mt-4"></div>
  </section>

  <section>
    <h2 class="text-sm font-medium text-white mb-3">Audit log</h2>
    <div class="rounded-lg border border-line bg-panel overflow-x-auto">
      <table class="w-full text-sm mono">
        <thead><tr class="text-left text-xs text-mute border-b border-line font-sans">
          <th class="px-4 py-3 font-normal">ID</th><th class="px-4 py-3 font-normal">Actor</th><th class="px-4 py-3 font-normal">Action</th><th class="px-4 py-3 font-normal">Subject</th><th class="px-4 py-3 font-normal">Time (UTC)</th>
        </tr></thead>
        <tbody id="audit_body"></tbody>
      </table>
    </div>
  </section>
</main>

<script src="js/ui.js"></script>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const get = (p) => fetch(p).then((r) => r.json());

function decisionBadge(d, action) {
  const styles = { approved: 'bg-teal/15 text-teal border-teal/30', flagged: 'bg-gold/15 text-gold border-gold/30', rejected: 'bg-danger/15 text-danger border-danger/30' };
  const label = d === 'flagged' && action ? 'flagged: ' + action.replace('_', ' ') : d;
  return `<span class="inline-block px-2 py-0.5 rounded text-xs border ${styles[d] || styles.rejected}">${esc(label)}</span>`;
}
function tile(value, label, iconName, tone) {
  const c = { neutral: 'text-mute', teal: 'text-teal', gold: 'text-gold', danger: 'text-danger' }[tone];
  return `<div class="rounded-lg border border-line bg-panel p-4"><div class="${c} mb-3">${icon(iconName, 'w-5 h-5')}</div>
    <div class="text-2xl font-semibold text-white mono">${esc(value)}</div><div class="text-xs text-mute mt-1">${esc(label)}</div></div>`;
}

async function loadStats() {
  const s = await get('api/admin/stats');
  document.getElementById('stats').innerHTML =
    tile(s.total, 'Total decisions', 'card', 'neutral') + tile(s.approved, 'Approved', 'check', 'teal') +
    tile(s.flagged, 'Flagged (step-up / review)', 'alert', 'gold') + tile(s.rejected, 'Rejected', 'cross', 'danger') +
    tile(s.value_protected_display, 'Value held back from the rail', 'shield', 'teal');
  const max = Math.max(1, ...s.score_histogram);
  document.getElementById('histogram').innerHTML = s.score_histogram.map((n, i) =>
    `<div class="flex-1 rounded-t ${i < 4 ? 'bg-danger/70' : i < 7 ? 'bg-gold/70' : 'bg-teal/70'}" style="height:${Math.max(3, n / max * 100)}%" title="${i * 10}-${i * 10 + 9}: ${n}"></div>`).join('');
  const bar = (label, n, cls) => `<div class="flex items-center gap-3 text-xs"><span class="w-28 text-mute">${esc(label)}</span><div class="flex-1 h-2 rounded bg-ink"><div class="h-2 rounded ${cls}" style="width:${s.total ? n / s.total * 100 : 0}%"></div></div><span class="mono w-8 text-right">${esc(n)}</span></div>`;
  document.getElementById('mix').innerHTML = bar('Approved', s.approved, 'bg-teal') + bar('Flagged', s.flagged, 'bg-gold') + bar('Rejected', s.rejected, 'bg-danger')
    + `<div class="text-xs text-mute pt-1">Approval rate ${s.approval_rate ?? '-'}% · avg score ${s.avg_score ?? '-'} · step-ups ${s.required_actions.step_up || 0} · manual reviews ${s.required_actions.manual_review || 0}</div>`;
}

async function loadTransactions() {
  const txs = await get('api/transactions');
  document.getElementById('tx_body').innerHTML = txs.map((t) => `
    <tr class="border-b border-line last:border-0 hover:bg-white/5 cursor-pointer" onclick="showDetail(${Number(t.id)})">
      <td class="px-4 py-3 mono text-mute">#${esc(t.id)}</td><td class="px-4 py-3">${esc(t.full_name)}</td>
      <td class="px-4 py-3 mono">${esc(t.amount_display)}</td><td class="px-4 py-3 mono">${esc(Number(t.risk_score).toFixed(0))}</td>
      <td class="px-4 py-3">${decisionBadge(t.decision, t.required_action)}</td>
      <td class="px-4 py-3 text-xs mono text-mute">${t.paypal_status ? esc(t.paypal_status) : '-'}</td>
      <td class="px-4 py-3 text-mute text-xs mono">${esc(t.created_at)}</td></tr>`).join('')
    || '<tr><td class="px-4 py-6 text-mute text-sm" colspan="7">No transactions yet - run the console or the PayPal checkout demo.</td></tr>';
}

async function showDetail(id) {
  const d = await get('api/admin/transactions/' + id);
  const ai = d.ai_analysis;
  document.getElementById('detail').innerHTML = `
    <div class="rounded-lg border border-line bg-panel p-5">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="text-sm text-white">Transaction #${esc(d.id)} · ${esc(d.amount_display)} · ${decisionBadge(d.decision, d.required_action)}</div>
        <div class="text-[11px] text-mute mono">${esc(d.decision_id)} · ${esc(d.engine_version)}</div>
      </div>
      ${renderFactors(d)}
      ${d.paypal ? `<div class="text-xs text-mute mt-3 mono">PayPal ${esc(d.paypal.mode)} order ${esc(d.paypal.paypal_order_id)} · ${esc(d.paypal.status)}${d.paypal.capture_id ? ' · capture ' + esc(d.paypal.capture_id) : ''}</div>` : ''}
      <div class="mt-4 border-t border-line pt-3">
        <div class="flex items-center justify-between"><span class="text-xs text-mute">AI risk analyst (advisory - the engine decides, AI explains)</span>
          <button onclick="explain(${Number(d.id)})" class="rounded bg-ink border border-line hover:border-gold text-xs px-3 py-1.5">${ai ? 'Regenerate' : 'Explain with AI'}</button></div>
        <div id="ai_out" class="mt-3">${ai ? renderAi(ai) : ''}</div>
      </div>
    </div>`;
  document.getElementById('detail').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function renderAi(a) {
  return `<div class="text-sm text-slate-300">${esc(a.analyst_summary)}</div>
    <div class="grid sm:grid-cols-2 gap-3 mt-3">
      <div class="rounded border border-line bg-ink p-3"><div class="text-[11px] text-mute">Recommended action</div><div class="text-sm text-gold mono">${esc(a.recommended_action)}</div></div>
      <div class="rounded border border-line bg-ink p-3"><div class="text-[11px] text-mute">Customer-safe message (reveals no signals)</div><div class="text-sm">${esc(a.customer_message)}</div></div>
    </div>
    <div class="text-[11px] text-mute mt-2">${esc(a.confidence_note)} · source: ${esc(a.source)}${a.model ? ' (' + esc(a.model) + ')' : ''}</div>`;
}

async function explain(id) {
  document.getElementById('ai_out').innerHTML = '<div class="text-xs text-mute">Analysing...</div>';
  const res = await fetch(`api/admin/transactions/${id}/explain?refresh=1`, { method: 'POST', headers: { 'X-CSRF-Token': CSRF } });
  document.getElementById('ai_out').innerHTML = renderAi(await res.json());
}

async function loadAudit() {
  const log = await get('api/audit-log');
  document.getElementById('audit_body').innerHTML = log.map((a) => `
    <tr class="border-b border-line last:border-0"><td class="px-4 py-2 text-mute">#${esc(a.id)}</td><td class="px-4 py-2">${esc(a.actor)}</td>
    <td class="px-4 py-2 text-teal">${esc(a.action)}</td><td class="px-4 py-2 text-mute">${esc(a.subject_table ?? '')} ${esc(a.subject_id ?? '')}</td>
    <td class="px-4 py-2 text-mute">${esc(a.created_at)}</td></tr>`).join('')
    || '<tr><td class="px-4 py-6 text-mute text-sm font-sans" colspan="5">No audit entries yet.</td></tr>';
  const v = await get('api/admin/audit/verify');
  const b = document.getElementById('chain_badge');
  b.textContent = v.valid ? `✓ Audit chain intact · ${v.checked} entries hash-linked` : `✗ Audit chain BROKEN at entry #${v.broken_at_id}`;
  b.className = 'text-xs rounded border px-2.5 py-1 ' + (v.valid ? 'border-teal/40 text-teal' : 'border-danger/50 text-danger');
}

loadStats(); loadTransactions(); loadAudit();
</script>
</body>
</html>
