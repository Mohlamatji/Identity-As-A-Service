const PRODUCTS = [
  { id: 'p1', name: 'Specialty coffee beans (1kg)', cents: 25000 },
  { id: 'p2', name: 'Laptop stand bundle', cents: 750000 },
  { id: 'p3', name: 'Home audio system', cents: 3000000 },
];
const store = {
  get: () => { try { return JSON.parse(localStorage.getItem('iv_demo') || 'null'); } catch { return null; } },
  set: (v) => { try { localStorage.setItem('iv_demo', JSON.stringify(v)); } catch {} },
  clear: () => { try { localStorage.removeItem('iv_demo'); } catch {} },
};
let selected = PRODUCTS[0];
let ppConfig = { mode: 'mock', currency: 'USD', zar_per_usd: 18.5 };
const $ = (id) => document.getElementById(id);
const zar = (c) => 'R ' + (c / 100).toLocaleString('en-ZA', { minimumFractionDigits: 2 });
const prompt_ = (t) => { $('prompt').textContent = t; };
const post = (path, body, extra = {}) => fetch(path, { method: 'POST', headers: authHeaders(Object.assign({ 'Content-Type': 'application/json' }, extra)), body: JSON.stringify(body) }).then(async (r) => ({ status: r.status, data: await r.json() }));

function renderProducts() {
  $('products').innerHTML = PRODUCTS.map((p) => `
    <label class="flex items-center justify-between gap-3 rounded border ${p.id === selected.id ? 'border-gold bg-gold/5' : 'border-line'} px-3 py-2.5 cursor-pointer text-sm">
      <span class="flex items-center gap-2"><input type="radio" name="prod" value="${p.id}" ${p.id === selected.id ? 'checked' : ''} class="accent-gold"><span>${esc(p.name)}</span></span>
      <span class="mono text-slate-300">${zar(p.cents)}</span></label>`).join('');
  document.querySelectorAll('input[name=prod]').forEach((el) => el.addEventListener('change', () => { selected = PRODUCTS.find((p) => p.id === el.value); renderProducts(); }));
  $('total').textContent = zar(selected.cents);
  const usd = ppConfig.currency === 'ZAR' ? selected.cents / 100 : selected.cents / 100 / ppConfig.zar_per_usd;
  $('pp_amount').textContent = `PayPal leg: ${ppConfig.currency} ${usd.toFixed(2)}`;
  $('tier_note').textContent = selected.cents >= 2500000 ? 'High-value payment: needs a very strong score (selfie vs Home Affairs photo + live liveness).'
    : selected.cents >= 500000 ? 'Mid-value payment: higher approval threshold than a small purchase.' : 'Low-value payment: standard approval threshold.';
}

function showResult(html) { const v = $('result_view'); v.classList.remove('hidden'); v.innerHTML = html; v.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }

async function init() {
  const cfg = await fetch('api/paypal/config', { headers: authHeaders() }).then((r) => r.json()).catch(() => null);
  if (cfg) ppConfig = cfg;
  $('pp_mode').textContent = 'PayPal: ' + ppConfig.mode + (ppConfig.mode === 'mock' ? ' (simulated)' : '');
  renderProducts();
  IdentityVault.behavior.attach(document);

  const u = store.get();
  if (u) { $('who').textContent = `Welcome back, user #${u.userId}. We'll verify you before this payment.`; $('enroll_fields').classList.add('hidden'); }

  const q = new URLSearchParams(location.search);
  if (q.get('cancelled')) showResult(`<div class="rounded-lg border border-gold/30 bg-gold/10 p-4 text-sm">Payment cancelled at PayPal. Nothing was captured.</div>`);
  if (q.get('token') && (q.get('PayerID') || q.get('mock'))) await capture(q.get('token'));
}

async function capture(orderId) {
  showResult(`<div class="rounded-lg border border-line bg-panel p-4 text-sm text-mute">Buyer approved at PayPal. Checking the signed approval receipt and capturing...</div>`);
  const { status, data } = await post(`api/paypal/orders/${encodeURIComponent(orderId)}/capture`, {}, { 'Idempotency-Key': 'cap-' + orderId });
  const ok = status === 200 && data.status === 'COMPLETED';
  showResult(`<div class="rounded-lg border ${ok ? 'border-teal/30 bg-teal/10' : 'border-danger/30 bg-danger/10'} p-5">
    <div class="flex items-center gap-3"><span class="${ok ? 'text-teal' : 'text-danger'}">${icon(ok ? 'check' : 'cross', 'w-7 h-7')}</span>
    <div><div class="text-white font-medium">${ok ? 'Payment captured' : 'Capture refused'}</div>
    <div class="text-sm text-mute">${ok ? `PayPal capture <span class="mono">${esc(data.capture_id)}</span> for order <span class="mono">${esc(orderId)}</span>${data.mode === 'mock' ? ' (simulated)' : ''}.` : esc(data.message || 'Unknown error')}</div></div></div>
    <a href="dashboard" class="inline-block text-xs text-teal underline mt-3">See it in the dashboard &rarr;</a></div>`);
  history.replaceState({}, '', 'checkout');
}

$('btn_cam').onclick = async () => {
  try { await IdentityVault.startCamera($('video')); prompt_('Camera on. Loading face models...'); IdentityVault.loadModels().then(() => prompt_('Ready.')).catch(() => prompt_('Models unavailable - demo will use the labelled simulated fallback.')); }
  catch (e) { prompt_('Camera unavailable (' + e.message + ') - demo will use the simulated fallback.'); }
};
$('btn_forget').onclick = () => { store.clear(); location.href = 'checkout'; };

$('btn_pay').onclick = async () => {
  if (!$('consent').checked) return prompt_('Tick the consent box first - it is the explicit POPIA consent.');
  $('btn_pay').disabled = true;
  const onPrompt = (p) => { $('video_wrap').classList.add('scanning'); prompt_(p.action === 'baseline' ? p.label : `Step ${p.index} of ${p.total}: ${p.label}`); };
  const video = $('video'), canvas = $('canvas');
  try {
    let u = store.get();
    if (!u) {
      prompt_('Enrolling you (first time only)...');
      const idNumber = $('id_number').value;
      const e = await IdentityVault.verify({ clientToken: window.__TOKEN__, video, canvas, onPrompt, enroll: { id_number: idNumber, full_name: $('full_name').value, dob: $('dob').value, phone: $('phone').value } });
      if (!e.ok) throw new Error(e.message || 'Enrollment failed');
      u = { userId: e.userId, idNumber }; store.set(u);
      $('enroll_fields').classList.add('hidden');
    }

    prompt_('Verifying this payment...');
    const v = await IdentityVault.verify({ clientToken: window.__TOKEN__, userId: u.userId, amountCents: selected.cents, payee: 'demo-merchant', video, canvas, onPrompt });
    $('video_wrap').classList.remove('scanning');
    if (!v.ok) { showResult(`<div class="rounded-lg border border-danger/30 bg-danger/10 p-4 text-sm"><b class="text-white">Verification failed.</b> ${esc(v.message)}<div class="mt-2 space-y-1">${(v.liveness?.signals || []).map((x) => `<div class="text-xs ${x.ok ? 'text-teal' : 'text-danger'}">${x.ok ? '✓' : '✗'} ${esc(x.detail)}</div>`).join('')}</div><div class="text-xs text-mute mt-2">PayPal was never called.</div></div>`); return; }

    await post('api/consent/grant', { user_id: u.userId, reason: 'Fraud Prevention/Fraud Detection', validity_days: 90 });
    prompt_('Scoring the payment...');
    const body = { user_id: u.userId, id_number: u.idNumber, amount_cents: selected.cents, currency: 'ZAR', payee: 'demo-merchant', verification_token: v.verificationToken, selfie_base64: v.image };
    if ($('sim_device').checked) body.new_device = true;
    const { status, data } = await post('api/paypal/orders', body, { 'Idempotency-Key': 'ord-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10) });
    if (status !== 200 && !data.status) throw new Error(data.message || 'Request failed');
    renderDecision(data);
    prompt_('Done.');
  } catch (err) {
    $('video_wrap').classList.remove('scanning');
    prompt_(err.message);
  } finally { $('btn_pay').disabled = false; }
};

function renderDecision(d) {
  const tone = { approved: ['border-teal/30 bg-teal/10', 'text-teal', 'check'], flagged: ['border-gold/30 bg-gold/10', 'text-gold', 'alert'], rejected: ['border-danger/30 bg-danger/10', 'text-danger', 'cross'] }[d.status] || ['border-line bg-panel', 'text-mute', 'shield'];
  const title = d.status === 'approved' ? 'Approved by Identity Vault' : d.status === 'flagged' ? (d.required_action === 'step_up' ? 'Extra verification required' : 'Held for manual review') : 'Declined';
  const pp = d.paypal ? `<a href="${esc(d.paypal.approve_url)}" class="inline-block mt-4 rounded bg-gold text-ink font-medium text-sm px-5 py-2 hover:bg-white">Continue to PayPal &rarr;</a>
      <div class="text-[11px] text-mute mono mt-2">${esc(d.paypal.mode)} order ${esc(d.paypal.order_id)} · ${esc(d.paypal.currency)} ${esc(d.paypal.amount)}</div>`
    : `<div class="text-xs text-mute mt-3">${esc(d.paypal_note || d.paypal_error || '')}</div>`;
  showResult(`<div class="rounded-lg border ${tone[0]} p-5"><div class="flex items-center gap-3"><span class="${tone[1]}">${icon(tone[2], 'w-7 h-7')}</span>
    <div><div class="text-white font-medium">${title}</div><div class="text-sm text-mute">${esc(d.amount)} · score ${esc(d.score)} / threshold ${esc(d.threshold)}</div></div></div>
    ${renderFactors(d)}${pp}
    <details class="mt-4"><summary class="text-xs text-mute cursor-pointer">Signed decision receipt</summary><pre class="text-[10px] mono break-all whitespace-pre-wrap text-slate-400 mt-2">${esc(d.receipt)}</pre></details></div>`);
}

init();
