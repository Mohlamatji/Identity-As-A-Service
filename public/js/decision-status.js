function zarToCents(rand) {
  return Math.round(parseFloat(rand) * 100);
}

async function grantConsent() {
  if (!window.__vault.userId) {
    renderResultCard('consent_result', { tone: 'warn', title: 'Enroll first', detail: 'A user must exist before consent can be recorded.' });
    return;
  }
  if (!document.getElementById('consent_explicit_checkbox').checked) {
    renderResultCard('consent_result', { tone: 'warn', title: 'Explicit consent required', detail: 'Tick the checkbox above before granting consent - that affirmative action is the explicit consent itself, not just the button click.' });
    return;
  }
  const res = await fetch('api/consent/grant', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + window.__TOKEN__ },
    body: JSON.stringify({
      user_id: window.__vault.userId,
      reason: document.getElementById('consent_reason').value,
      validity_days: parseInt(document.getElementById('consent_validity_days').value, 10),
    }),
  });
  const data = await res.json();
  if (data.status === 'granted') {
    renderResultCard('consent_result', {
      tone: 'success',
      title: 'Consent recorded',
      fields: [['Reason', data.reason], ['Expires', data.expires_at]],
      raw: data,
    });
    Wizard.markComplete('consent');
    Wizard.next('consent');
  } else {
    renderResultCard('consent_result', { tone: 'danger', title: 'Could not record consent', detail: data.message, raw: data });
  }
}

async function checkConsentStatus() {
  if (!window.__vault.userId) {
    renderResultCard('consent_result', { tone: 'warn', title: 'Enroll first' });
    return;
  }
  const res = await fetch(`api/consent/status?user_id=${window.__vault.userId}`, {
    headers: { Authorization: 'Bearer ' + window.__TOKEN__ },
  });
  const data = await res.json();
  renderResultCard('consent_result', {
    tone: data.valid ? 'success' : 'warn',
    title: data.valid ? 'Consent is valid' : 'No valid consent on file',
    fields: data.has_consent ? [['Reason', data.reason], ['Expires', data.expires_at], ['Revoked', data.revoked ? 'yes' : 'no']] : [],
    raw: data,
  });
}

async function revokeConsent() {
  if (!window.__vault.userId) {
    renderResultCard('consent_result', { tone: 'warn', title: 'Enroll first' });
    return;
  }
  const res = await fetch('api/consent/revoke', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + window.__TOKEN__ },
    body: JSON.stringify({ user_id: window.__vault.userId }),
  });
  const data = await res.json();
  renderResultCard('consent_result', {
    tone: data.status === 'revoked' ? 'warn' : 'neutral',
    title: data.status === 'revoked' ? 'Consent revoked' : 'Nothing to revoke',
    raw: data,
  });
}

async function authenticateUser() {
  if (!window.__vault.userId) {
    renderResultCard('authenticate_result', { tone: 'warn', title: 'Enroll first' });
    return;
  }
  renderResultCard('authenticate_result', { tone: 'neutral', title: 'Starting liveness challenge...', detail: 'Follow the prompts above the camera.' });
  const r = await IdentityVault.verify({
    clientToken: window.__TOKEN__, userId: window.__vault.userId, video: document.getElementById('video'),
    canvas: document.getElementById('canvas'), onPrompt: showPrompt,
    amountCents: zarToCents(document.getElementById('amount_rand').value) || undefined,
  });
  document.getElementById('video_wrap').classList.remove('scanning');
  if (r.ok) window.__vault.verificationToken = r.verificationToken;
  renderResultCard('authenticate_result', {
    tone: r.ok ? 'success' : 'danger',
    title: r.ok ? 'Verified - single-use token issued' : 'Verification failed',
    detail: r.message,
    fields: r.liveness ? [
      ['Liveness', `${r.liveness.passed ? 'passed' : 'failed'} (${r.liveness.mode}, score ${r.liveness.score})`],
      ['Face match', r.vault_match.distance != null ? `distance ${r.vault_match.distance}` : (r.vault_match.matched ? 'ok' : 'no')],
    ] : [],
    extraHtml: r.liveness ? `<div class="mt-3 space-y-1">${(r.liveness.signals || []).map((x) => `<div class="text-xs ${x.ok ? 'text-teal' : 'text-danger'}">${x.ok ? '✓' : '✗'} ${esc(x.detail)}</div>`).join('')}</div>` : '',
    raw: r.raw,
  });
  if (r.ok) { Wizard.markComplete('authenticate'); Wizard.next('authenticate'); }
}

async function runTransactionApprove() {
  if (!window.__vault.userId) {
    renderResultCard('decision_result', { tone: 'warn', title: 'Enroll first' });
    return;
  }

  if (!window.__vault.verificationToken) {
    renderResultCard('decision_result', { tone: 'warn', title: 'Authenticate first', detail: 'Approval needs a fresh single-use verification token. Run the Authenticate step (each token works once).' });
    return;
  }
  const res = await fetch('api/transaction/approve', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + window.__TOKEN__, 'Idempotency-Key': 'console-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10) },
    body: JSON.stringify({
      user_id: window.__vault.userId,
      id_number: document.getElementById('id_number').value,
      bank_partner_id: 'demo-bank',
      amount_cents: zarToCents(document.getElementById('amount_rand').value),
      currency: 'ZAR',
      realtime: true,
      verification_token: window.__vault.verificationToken,
      selfie_base64: window.__vault.imageBase64,
      device_fingerprint: document.getElementById('device_fingerprint').value,
      ip_country: document.getElementById('ip_country').value,
      // Fraud-simulation toggles: only sent when checked, so unchecked
      // means "compare against stored baseline" rather than "force false".
      ...(document.getElementById('simulate_new_device').checked ? { new_device: true } : {}),
      ...(document.getElementById('simulate_new_location').checked ? { ip_country_mismatch: true } : {}),
    }),
  });
  const data = await res.json();

  if (res.status !== 200) {
    renderResultCard('decision_result', { tone: 'danger', title: res.status === 403 ? 'Blocked' : 'Request refused', detail: data.message, raw: data });
    return;
  }
  window.__vault.verificationToken = null; // spent

  window.__vault.lastTransactionId = data.transaction_id;
  const toneMap = { approved: 'success', flagged: 'warn', rejected: 'danger' };
  renderResultCard('decision_result', {
    tone: toneMap[data.status] || 'neutral',
    title: data.status === 'approved' ? 'Transaction approved' : data.status === 'flagged' ? (data.required_action === 'step_up' ? 'Step-up verification required' : 'Flagged for review') : 'Transaction rejected',
    detail: data.message,
    fields: [
      ['Amount', data.amount],
      ['Score', `${data.score}/100`],
      ['DHA result', data.dha ? data.dha.match_result : '-'],
      ['Transaction ID', `#${data.transaction_id}`],
      ['Next step', data.required_action || (data.status === 'approved' ? 'proceed' : 'none')],
    ],
    extraHtml: renderFactors(data),
    raw: data,
  });
  Wizard.markComplete('approve');
  Wizard.next('approve');
}

async function runFraudCheck() {
  if (!window.__vault.userId) {
    renderResultCard('fraud_result', { tone: 'warn', title: 'Enroll first' });
    return;
  }
  const params = new URLSearchParams({
    user_id: window.__vault.userId,
    device_fingerprint: document.getElementById('device_fingerprint').value,
    ip_country: document.getElementById('ip_country').value,
  });
  const res = await fetch('api/fraud/check?' + params.toString(), { headers: { Authorization: 'Bearer ' + window.__TOKEN__ } });
  const data = await res.json();
  const anomalous = data.anomaly && (data.anomaly.new_device || data.anomaly.ip_country_mismatch || data.velocity.flagged);
  renderResultCard('fraud_result', {
    tone: anomalous ? 'warn' : 'success',
    title: anomalous ? 'Anomaly detected' : 'No anomalies found',
    fields: [
      ['New device', data.anomaly.new_device ? 'yes' : 'no'],
      ['Location mismatch', data.anomaly.ip_country_mismatch ? 'yes' : 'no'],
      ['Tx last hour', data.velocity.transactions_last_hour],
    ],
    raw: data,
  });
  Wizard.markComplete('fraud');
  Wizard.next('fraud');
}

async function runWebhook() {
  if (!window.__vault.lastTransactionId) {
    renderResultCard('webhook_result', { tone: 'warn', title: 'Approve a transaction first' });
    return;
  }
  const res = await fetch(`api/webhook/notify/${window.__vault.lastTransactionId}`, { method: 'POST', headers: { Authorization: 'Bearer ' + window.__TOKEN__ } });
  const data = await res.json();
  renderResultCard('webhook_result', {
    tone: data.action === 'bank_api_call' ? 'success' : 'warn',
    title: data.action === 'bank_api_call' ? 'Bank notified' : 'Fraud alert raised',
    detail: data.result,
    raw: data,
  });
  Wizard.markComplete('webhook');
}

async function exportMyData() {
  if (!window.__vault.userId) {
    renderResultCard('data_subject_result', { tone: 'warn', title: 'Enroll first', detail: 'There is nothing to export until a user exists.' });
    return;
  }
  const res = await fetch(`api/data-subject/export?user_id=${window.__vault.userId}`, {
    headers: { Authorization: 'Bearer ' + window.__TOKEN__ },
  });
  const data = await res.json();
  if (res.status !== 200) {
    renderResultCard('data_subject_result', { tone: 'danger', title: 'Export failed', detail: data.message, raw: data });
    return;
  }
  renderResultCard('data_subject_result', {
    tone: 'neutral',
    title: 'Export ready',
    detail: 'Full record shown below - the raw response also includes transaction and DHA verification history.',
    fields: [
      ['Name on file', data.user.full_name],
      ['Consent valid', data.consent.valid ? 'yes' : 'no'],
      ['Transactions', data.transactions.length],
    ],
    raw: data,
  });
}

async function deleteMyData() {
  if (!window.__vault.userId) {
    renderResultCard('data_subject_result', { tone: 'warn', title: 'Enroll first' });
    return;
  }
  if (!confirm('This will delete your biometric template and behavioral baseline, revoke consent, and anonymize your name/phone. Transaction and DHA records are retained where a separate legal obligation requires it. Continue?')) {
    return;
  }
  const res = await fetch('api/data-subject/delete-request', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + window.__TOKEN__ },
    body: JSON.stringify({ user_id: window.__vault.userId }),
  });
  const data = await res.json();
  renderResultCard('data_subject_result', {
    tone: 'warn',
    title: 'Deletion request processed',
    detail: 'See the raw response for exactly what was deleted vs retained, and why.',
    raw: data,
  });
}
