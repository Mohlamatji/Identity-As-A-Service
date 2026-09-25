<?php $security = require __DIR__ . '/../../config/security.php'; ?>
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
  <div class="mx-auto max-w-5xl px-6 md:px-10 h-16 flex items-center justify-between">
    <div class="flex items-center gap-2.5">
      <span class="text-gold w-6 h-6"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3l7 3v5.5c0 4.5-3 7.8-7 9.5-4-1.7-7-5-7-9.5V6l7-3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
      <span class="font-semibold text-white tracking-tight">Identity Vault</span>
    </div>
    <nav class="flex items-center gap-1 text-sm">
      <a href="./" class="px-3 py-1.5 rounded text-mute hover:text-white transition-colors">Console</a>
      <a href="dashboard" class="px-3 py-1.5 rounded text-white bg-white/5">Dashboard</a>
      <a href="api-docs" class="px-3 py-1.5 rounded text-mute hover:text-white transition-colors">API docs</a>
    </nav>
  </div>
</header>

<main class="mx-auto max-w-5xl px-6 py-10 md:px-10">

  <p class="text-sm text-mute mb-8">Fraud outcomes, verification scores, and the audit trail in one place.</p>

  <div id="stats" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-10"></div>

  <section class="mb-10">
    <h2 class="text-sm font-medium text-white mb-3">Recent transactions</h2>
    <div class="rounded-lg border border-line bg-panel overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-mute border-b border-line">
            <th class="px-4 py-3 font-normal">ID</th>
            <th class="px-4 py-3 font-normal">User</th>
            <th class="px-4 py-3 font-normal">Amount</th>
            <th class="px-4 py-3 font-normal">Score</th>
            <th class="px-4 py-3 font-normal">Decision</th>
            <th class="px-4 py-3 font-normal">Reason</th>
            <th class="px-4 py-3 font-normal">Time</th>
          </tr>
        </thead>
        <tbody id="tx_body"></tbody>
      </table>
    </div>
  </section>

  <section>
    <h2 class="text-sm font-medium text-white mb-3">Audit log</h2>
    <div class="rounded-lg border border-line bg-panel overflow-x-auto">
      <table class="w-full text-sm mono">
        <thead>
          <tr class="text-left text-xs text-mute border-b border-line font-sans">
            <th class="px-4 py-3 font-normal">ID</th>
            <th class="px-4 py-3 font-normal">Actor</th>
            <th class="px-4 py-3 font-normal">Action</th>
            <th class="px-4 py-3 font-normal">Subject</th>
            <th class="px-4 py-3 font-normal">Time</th>
          </tr>
        </thead>
        <tbody id="audit_body"></tbody>
      </table>
    </div>
  </section>

</main>

<script src="js/ui.js"></script>
<script>
window.__API_KEY__ = <?= json_encode($security['api_key'] ?? '') ?>;

function decisionBadge(decision) {
  const styles = {
    approved: 'bg-teal/15 text-teal border-teal/30',
    flagged: 'bg-gold/15 text-gold border-gold/30',
    rejected: 'bg-danger/15 text-danger border-danger/30',
  };
  const cls = styles[decision] || styles.rejected;
  return `<span class="inline-block px-2 py-0.5 rounded text-xs border ${cls}">${decision}</span>`;
}

function statTile(value, label, iconName, tone) {
  const toneCls = { neutral: 'text-mute', teal: 'text-teal', gold: 'text-gold', danger: 'text-danger' }[tone] || 'text-mute';
  return `
    <div class="rounded-lg border border-line bg-panel p-4">
      <div class="flex items-center justify-between mb-3">
        <span class="${toneCls}">${icon(iconName, 'w-5 h-5')}</span>
      </div>
      <div class="text-2xl font-semibold text-white mono">${value}</div>
      <div class="text-xs text-mute mt-1">${label}</div>
    </div>`;
}

async function loadDashboard() {
  const [txRes, auditRes] = await Promise.all([
    fetch('api/transactions', { headers: { 'X-Api-Key': window.__API_KEY__ || '' } }),
    fetch('api/audit-log', { headers: { 'X-Api-Key': window.__API_KEY__ || '' } }),
  ]);
  const transactions = await txRes.json();
  const auditLog = await auditRes.json();

  const approved = transactions.filter(t => t.decision === 'approved').length;
  const flagged = transactions.filter(t => t.decision === 'flagged').length;
  const rejected = transactions.filter(t => t.decision === 'rejected').length;

  document.getElementById('stats').innerHTML =
    statTile(transactions.length, 'Total transactions', 'card', 'neutral') +
    statTile(approved, 'Approved', 'check', 'teal') +
    statTile(flagged, 'Flagged', 'alert', 'gold') +
    statTile(rejected, 'Rejected / fraud alerts', 'cross', 'danger');

  document.getElementById('tx_body').innerHTML = transactions.map(t => `
    <tr class="border-b border-line last:border-0">
      <td class="px-4 py-3 mono text-mute">#${t.id}</td>
      <td class="px-4 py-3">${t.full_name}</td>
      <td class="px-4 py-3 mono">${t.amount_display}</td>
      <td class="px-4 py-3 mono">${Number(t.risk_score).toFixed(0)}</td>
      <td class="px-4 py-3">${decisionBadge(t.decision)}</td>
      <td class="px-4 py-3 text-mute text-xs max-w-xs truncate" title="${t.decision_reason}">${t.decision_reason}</td>
      <td class="px-4 py-3 text-mute text-xs mono">${t.created_at}</td>
    </tr>
  `).join('') || '<tr><td class="px-4 py-6 text-mute text-sm" colspan="7">No transactions yet - run the test console first.</td></tr>';

  document.getElementById('audit_body').innerHTML = auditLog.map(a => `
    <tr class="border-b border-line last:border-0">
      <td class="px-4 py-2 text-mute">#${a.id}</td>
      <td class="px-4 py-2">${a.actor}</td>
      <td class="px-4 py-2 text-teal">${a.action}</td>
      <td class="px-4 py-2 text-mute">${a.subject_table ?? ''} ${a.subject_id ?? ''}</td>
      <td class="px-4 py-2 text-mute">${a.created_at}</td>
    </tr>
  `).join('') || '<tr><td class="px-4 py-6 text-mute text-sm font-sans" colspan="5">No audit entries yet.</td></tr>';
}

loadDashboard();
</script>
</body>
</html>
