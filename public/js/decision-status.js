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
    headers: { 'Content-Type': 'application/json', 'X-Api-Key': window.__API_KEY__ || '' },
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
    headers: { 'X-Api-Key': window.__API_KEY__ || '' },
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
    headers: { 'Content-Type': 'application/json', 'X-Api-Key': window.__API_KEY__ || '' },
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
  const livenessMeta = window.__vault.pendingLivenessMeta || { mode: 'simulated', gesture_completed: true, frame_count: 15, duration_ms: 2200 };
  const res = await fetch('api/authenticate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Api-Key': window.__API_KEY__ || '' },
    body: JSON.stringify({
      user_id: window.__vault.userId,
      image_base64: window.__vault.imageBase64,
      descriptor: window.__vault.pendingDescriptor ?? null,
      ...livenessMeta,
    }),
  });
  const data = await res.json();
  const approved = data.status === 'approved';
  renderResultCard('authenticate_result', {
    tone: approved ? 'success' : 'danger',
    title: approved ? 'Biometric match confirmed' : 'Biometric match failed',
    detail: data.message,
    fields: [['Confidence', `${(data.confidence * 100).toFixed(1)}%`]],
    raw: data,
  });
  if (approved) {
    Wizard.markComplete('authenticate');
    Wizard.next('authenticate');
  }
}

async function runTransactionApprove() {
  if (!window.__vault.userId) {
    renderResultCard('decision_result', { tone: 'warn', title: 'Enroll first' });
    return;
  }

  const res = await fetch('api/transaction/approve', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Api-Key': window.__API_KEY__ || '' },
    body: JSON.stringify({
      user_id: window.__vault.userId,
      id_number: document.getElementById('id_number').value,
      bank_partner_id: 'demo-bank',
      amount_cents: zarToCents(document.getElementById('amount_rand').value),
      currency: 'ZAR',
      realtime: true,
      liveness: window.__vault.livenessResult,
      vault_match: window.__vault.vaultMatchResult,
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

  if (res.status === 403) {
    renderResultCard('decision_result', { tone: 'danger', title: 'Blocked - no valid consent', detail: data.message, raw: data });
    return;
  }

  window.__vault.lastTransactionId = data.transaction_id;
  const toneMap = { approved: 'success', flagged: 'warn', rejected: 'danger' };
  renderResultCard('decision_result', {
    tone: toneMap[data.status] || 'neutral',
    title: data.status === 'approved' ? 'Transaction approved' : data.status === 'flagged' ? 'Flagged for review' : 'Transaction rejected',
    detail: data.message,
    fields: [
      ['Amount', data.amount],
      ['Score', `${data.score}/100`],
      ['DHA result', data.dha ? data.dha.match_result : '-'],
      ['Transaction ID', `#${data.transaction_id}`],
    ],
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
  const res = await fetch('api/fraud/check?' + params.toString(), { headers: { 'X-Api-Key': window.__API_KEY__ || '' } });
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
  const res = await fetch(`api/webhook/notify/${window.__vault.lastTransactionId}`, { method: 'POST', headers: { 'X-Api-Key': window.__API_KEY__ || '' } });
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
    headers: { 'X-Api-Key': window.__API_KEY__ || '' },
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
    headers: { 'Content-Type': 'application/json', 'X-Api-Key': window.__API_KEY__ || '' },
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
