// Console glue around the IdentityVault SDK (identity-vault.js).
window.__vault = { userId: null, verificationToken: null, imageBase64: null, lastTransactionId: null };

function setStatus(msg) {
  const el = document.getElementById('liveness_status');
  if (el) el.textContent = msg;
}

function showPrompt(p) {
  document.getElementById('video_wrap').classList.add('scanning');
  setStatus(p.action === 'baseline' ? p.label : `Step ${p.index} of ${p.total}: ${p.label}`);
}

async function startCamera() {
  try {
    await IdentityVault.startCamera(document.getElementById('video'));
    setStatus('Camera on. Loading face models...');
    IdentityVault.loadModels().then(() => setStatus('Ready. The server will give you a random sequence of actions.'))
      .catch(() => setStatus('Face models failed to load - demo mode will fall back to a clearly-labelled simulated check.'));
  } catch (err) {
    setStatus('Camera unavailable (' + err.message + ') - demo mode will use the simulated fallback.');
  }
}

async function enrollUser() {
  setStatus('Requesting a liveness challenge from the server...');
  const val = (id) => document.getElementById(id).value;
  const r = await IdentityVault.verify({
    clientToken: window.__TOKEN__, video: document.getElementById('video'), canvas: document.getElementById('canvas'), onPrompt: showPrompt,
    enroll: { id_number: val('id_number'), full_name: val('full_name'), dob: val('dob'), phone: val('phone') },
  });
  document.getElementById('video_wrap').classList.remove('scanning');
  setStatus(r.ok ? 'Done.' : 'Not enrolled.');

  if (r.ok) {
    window.__vault.userId = r.userId;
    window.__vault.verificationToken = r.verificationToken;
    renderResultCard('enroll_result', {
      tone: 'success', title: r.status === 'enrolled' ? 'User enrolled' : 'Existing user re-verified', detail: r.message,
      fields: [['User ID', r.userId], ['Liveness', `${r.liveness.passed ? 'passed' : 'failed'} (${r.liveness.mode})`], ['Vault', r.vault_match.is_first_enrollment ? 'first enrollment' : 'matched']],
      extraHtml: `<div class="mt-3 space-y-1">${(r.liveness.signals || []).map((x) => `<div class="text-xs ${x.ok ? 'text-teal' : 'text-danger'}">${x.ok ? '✓' : '✗'} ${esc(x.detail)}</div>`).join('')}</div>`,
      raw: r.raw,
    });
    Wizard.markComplete('enroll');
    Wizard.next('enroll');
  } else {
    renderResultCard('enroll_result', { tone: 'danger', title: 'Enrollment failed', detail: r.message, raw: r.raw });
  }
}
