<?php $security = require __DIR__ . '/../../config/security.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Identity Vault - Console</title>
<link rel="stylesheet" href="css/app.css">
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="font-sans text-slate-200 antialiased">

<header class="border-b border-line">
  <div class="mx-auto max-w-4xl px-6 md:px-10 h-16 flex items-center justify-between">
    <div class="flex items-center gap-2.5">
      <span class="text-gold w-6 h-6"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3l7 3v5.5c0 4.5-3 7.8-7 9.5-4-1.7-7-5-7-9.5V6l7-3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
      <span class="font-semibold text-white tracking-tight">Identity Vault</span>
    </div>
    <nav class="flex items-center gap-1 text-sm">
      <a href="./" class="px-3 py-1.5 rounded text-white bg-white/5">Console</a>
      <a href="dashboard" class="px-3 py-1.5 rounded text-mute hover:text-white transition-colors">Dashboard</a>
      <a href="api-docs" class="px-3 py-1.5 rounded text-mute hover:text-white transition-colors">API docs</a>
    </nav>
  </div>
</header>

<main class="mx-auto max-w-4xl px-6 md:px-10 pb-24">

  <p class="text-sm text-mute max-w-xl mt-8">Live run of the verification pipeline - every step below calls the real API, in order.</p>

  <!-- Privacy notice -->
  <details id="privacy-notice" class="rounded-lg border border-line bg-panel mt-6 group">
    <summary class="cursor-pointer list-none px-5 py-3.5 flex items-center justify-between text-sm">
      <span class="flex items-center gap-2 text-white"><svg class="w-4 h-4 text-teal" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3l7 3v5.5c0 4.5-3 7.8-7 9.5-4-1.7-7-5-7-9.5V6l7-3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>What data we collect and why</span>
      <span class="text-mute text-xs group-open:rotate-180 transition-transform">&#9662;</span>
    </summary>
    <div class="px-5 pb-5 text-sm text-mute space-y-3 border-t border-line pt-4">
      <p><strong class="text-slate-300">What's collected:</strong> your South African ID number, name, date of birth, phone number, a facial biometric template (not the raw photo), and device/location signals used for fraud detection.</p>
      <p><strong class="text-slate-300">Why:</strong> Fraud Prevention/Fraud Detection - confirming your identity against Department of Home Affairs records before approving a transaction.</p>
      <p><strong class="text-slate-300">Who's involved:</strong> this organization is the Responsible Party under POPIA; VerifyNow acts as an Operator, processing your ID and biometric data on our behalf solely for that purpose.</p>
      <p><strong class="text-slate-300">Your rights:</strong> you can request a full export of what's held about you, or request its deletion, at any time.</p>
      <div class="flex gap-2 flex-wrap pt-1">
        <button onclick="exportMyData()" class="rounded bg-ink border border-line hover:border-teal text-xs px-3 py-1.5 transition-colors">Export my data</button>
        <button onclick="deleteMyData()" class="rounded bg-ink border border-line hover:border-danger text-xs px-3 py-1.5 transition-colors">Request deletion</button>
      </div>
      <div id="data_subject_result" class="mt-2"></div>
    </div>
  </details>

  <!-- Stepper -->
  <div class="flex items-start mt-8">
    <div class="flex flex-col items-center gap-2 shrink-0">
      <button id="stepdot_enroll" class="w-10 h-10 rounded-full border border-line bg-ink text-mute flex items-center justify-center transition-colors"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.6"/><path d="M4.5 20c1.4-4 4.2-6 7.5-6s6.1 2 7.5 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></button>
      <span class="text-[11px] text-mute">Enroll</span>
    </div>
    <div id="stepline_enroll" class="flex-1 h-px bg-line mt-5 mx-1 transition-colors"></div>
    <div class="flex flex-col items-center gap-2 shrink-0">
      <button id="stepdot_consent" class="w-10 h-10 rounded-full border border-line bg-ink text-mute flex items-center justify-center transition-colors"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3l7 3v5.5c0 4.5-3 7.8-7 9.5-4-1.7-7-5-7-9.5V6l7-3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></button>
      <span class="text-[11px] text-mute">Consent</span>
    </div>
    <div id="stepline_consent" class="flex-1 h-px bg-line mt-5 mx-1 transition-colors"></div>
    <div class="flex flex-col items-center gap-2 shrink-0">
      <button id="stepdot_authenticate" class="w-10 h-10 rounded-full border border-line bg-ink text-mute flex items-center justify-center transition-colors"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="4" y="4" width="16" height="16" rx="4" stroke="currentColor" stroke-width="1.6"/><path d="M8.5 15c1 .9 2.2 1.3 3.5 1.3s2.5-.4 3.5-1.3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></button>
      <span class="text-[11px] text-mute">Authenticate</span>
    </div>
    <div id="stepline_authenticate" class="flex-1 h-px bg-line mt-5 mx-1 transition-colors"></div>
    <div class="flex flex-col items-center gap-2 shrink-0">
      <button id="stepdot_approve" class="w-10 h-10 rounded-full border border-line bg-ink text-mute flex items-center justify-center transition-colors"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="6" width="18" height="12.5" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M3 10.5h18" stroke="currentColor" stroke-width="1.6"/></svg></button>
      <span class="text-[11px] text-mute">Approve</span>
    </div>
    <div id="stepline_approve" class="flex-1 h-px bg-line mt-5 mx-1 transition-colors"></div>
    <div class="flex flex-col items-center gap-2 shrink-0">
      <button id="stepdot_fraud" class="w-10 h-10 rounded-full border border-line bg-ink text-mute flex items-center justify-center transition-colors"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="4.5" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="1" fill="currentColor"/></svg></button>
      <span class="text-[11px] text-mute">Fraud check</span>
    </div>
    <div id="stepline_fraud" class="flex-1 h-px bg-line mt-5 mx-1 transition-colors"></div>
    <div class="flex flex-col items-center gap-2 shrink-0">
      <button id="stepdot_webhook" class="w-10 h-10 rounded-full border border-line bg-ink text-mute flex items-center justify-center transition-colors"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 10.5a6 6 0 0112 0c0 3.6 1 5 2 6H4c1-1 2-2.4 2-6z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></button>
      <span class="text-[11px] text-mute">Notify</span>
    </div>
  </div>

  <!-- Panel: Enroll -->
  <section id="panel_enroll" class="rounded-lg border border-line bg-panel p-6 mt-8">
    <div class="flex items-center justify-between mb-1">
      <h2 class="font-medium text-white">Enroll a user</h2>
      <span class="text-[11px] text-mute mono">POST /api/enroll</span>
    </div>
    <p class="text-sm text-mute mb-5">Creates the user record and captures their biometric template.</p>

    <div class="grid grid-cols-2 gap-3 mb-5">
      <label class="block">
        <span class="text-xs text-mute">ID number</span>
        <input id="id_number" value="8001015009087" class="mt-1 w-full rounded border border-line bg-ink px-3 py-2 text-sm text-white focus:outline-none focus:border-gold">
      </label>
      <label class="block">
        <span class="text-xs text-mute">Full name</span>
        <input id="full_name" value="Jane Test" class="mt-1 w-full rounded border border-line bg-ink px-3 py-2 text-sm text-white focus:outline-none focus:border-gold">
      </label>
      <label class="block">
        <span class="text-xs text-mute">Date of birth</span>
        <input id="dob" type="date" value="1980-01-01" class="mt-1 w-full rounded border border-line bg-ink px-3 py-2 text-sm text-white focus:outline-none focus:border-gold">
      </label>
      <label class="block">
        <span class="text-xs text-mute">Phone</span>
        <input id="phone" value="0821234567" class="mt-1 w-full rounded border border-line bg-ink px-3 py-2 text-sm text-white focus:outline-none focus:border-gold">
      </label>
    </div>

    <div id="video_wrap" class="relative rounded border border-line bg-ink overflow-hidden mb-2">
      <video id="video" autoplay muted playsinline class="w-full block"></video>
      <div class="scan-line"></div>
    </div>
    <canvas id="canvas" class="hidden"></canvas>
    <p id="liveness_status" class="text-xs text-teal mb-4 min-h-[1rem]">Start the camera to begin.</p>

    <div class="flex gap-2 flex-wrap">
      <button onclick="startCamera()" class="rounded bg-ink border border-line hover:border-gold text-sm px-4 py-2 transition-colors">Start camera</button>
      <button onclick="enrollUser()" class="rounded bg-gold text-ink font-medium text-sm px-4 py-2 hover:bg-white transition-colors">Capture &amp; enroll</button>
    </div>
    <div id="enroll_result" class="mt-4"></div>
  </section>

  <!-- Panel: Consent -->
  <section id="panel_consent" class="rounded-lg border border-line bg-panel p-6 mt-6 hidden">
    <div class="flex items-center justify-between mb-1">
      <h2 class="font-medium text-white">Record consent</h2>
      <span class="text-[11px] text-mute mono">POST /api/consent/grant</span>
    </div>
    <p class="text-sm text-mute mb-5">VerifyNow requires explicit, recorded consent before any DHA check - the approve step below will refuse without it. Their lawful-purpose field is fixed to "Fraud Prevention/Fraud Detection" and isn't changeable on their side, so the reason below matches it.</p>

    <div class="grid grid-cols-3 gap-3 mb-4">
      <label class="block col-span-2">
        <span class="text-xs text-mute">Reason (fixed to match VerifyNow's lawful basis)</span>
        <input id="consent_reason" value="Fraud Prevention/Fraud Detection" readonly class="mt-1 w-full rounded border border-line bg-ink/60 px-3 py-2 text-sm text-slate-400 cursor-not-allowed">
      </label>
      <label class="block">
        <span class="text-xs text-mute">Validity (days)</span>
        <input id="consent_validity_days" value="90" class="mt-1 w-full rounded border border-line bg-ink px-3 py-2 text-sm text-white focus:outline-none focus:border-gold">
      </label>
    </div>

    <label class="flex items-start gap-2.5 text-sm text-slate-300 mb-5 cursor-pointer">
      <input type="checkbox" id="consent_explicit_checkbox" class="mt-0.5 rounded border-line bg-ink accent-gold">
      <span>I explicitly consent to processing of my <strong class="text-white">facial biometric data</strong> and <strong class="text-white">South African ID number</strong> for this purpose. This data is shared with VerifyNow (our verification service provider, acting as Operator) to check it against Department of Home Affairs records.</span>
    </label>

    <div class="flex gap-2 flex-wrap">
      <button onclick="grantConsent()" class="rounded bg-gold text-ink font-medium text-sm px-4 py-2 hover:bg-white transition-colors">Grant consent</button>
      <button onclick="checkConsentStatus()" class="rounded bg-ink border border-line hover:border-gold text-sm px-4 py-2 transition-colors">Check status</button>
      <button onclick="revokeConsent()" class="rounded bg-ink border border-line hover:border-danger text-sm px-4 py-2 transition-colors">Revoke</button>
    </div>
    <p class="text-xs text-mute mt-3">You can request a full export or deletion of your data at any time - see the <a href="#privacy-notice" class="text-teal hover:text-white underline">privacy notice</a> above for how.</p>
    <div id="consent_result" class="mt-4"></div>
  </section>

  <!-- Panel: Authenticate -->
  <section id="panel_authenticate" class="rounded-lg border border-line bg-panel p-6 mt-6 hidden">
    <div class="flex items-center justify-between mb-1">
      <h2 class="font-medium text-white">Authenticate</h2>
      <span class="text-[11px] text-mute mono">POST /api/authenticate</span>
    </div>
    <p class="text-sm text-mute mb-5">Re-verifies against the template from step 1 - confirms this is the same enrolled person.</p>
    <div class="flex gap-2 flex-wrap">
      <button onclick="capturePhoto()" class="rounded bg-ink border border-line hover:border-gold text-sm px-4 py-2 transition-colors">Capture new frame</button>
      <button onclick="authenticateUser()" class="rounded bg-teal text-ink font-medium text-sm px-4 py-2 hover:bg-white transition-colors">Authenticate</button>
    </div>
    <div id="authenticate_result" class="mt-4"></div>
  </section>

  <!-- Panel: Approve transaction -->
  <section id="panel_approve" class="rounded-lg border border-line bg-panel p-6 mt-6 hidden">
    <div class="flex items-center justify-between mb-1">
      <h2 class="font-medium text-white">Approve a transaction</h2>
      <span class="text-[11px] text-mute mono">POST /api/transaction/approve</span>
    </div>
    <p class="text-sm text-mute mb-5">Runs DHA verification, behavioral analytics, and the decision engine end to end.</p>

    <div class="grid grid-cols-3 gap-3 mb-4">
      <label class="block">
        <span class="text-xs text-mute">Amount (ZAR)</span>
        <input id="amount_rand" value="5000.00" class="mt-1 w-full rounded border border-line bg-ink px-3 py-2 text-sm text-white focus:outline-none focus:border-gold">
      </label>
      <label class="block">
        <span class="text-xs text-mute">Device fingerprint</span>
        <input id="device_fingerprint" value="device-abc-123" class="mt-1 w-full rounded border border-line bg-ink px-3 py-2 text-sm text-white focus:outline-none focus:border-gold">
      </label>
      <label class="block">
        <span class="text-xs text-mute">IP country</span>
        <input id="ip_country" value="ZA" class="mt-1 w-full rounded border border-line bg-ink px-3 py-2 text-sm text-white focus:outline-none focus:border-gold">
      </label>
    </div>

    <div class="flex gap-5 mb-5">
      <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
        <input type="checkbox" id="simulate_new_device" class="rounded border-line bg-ink accent-danger">
        Simulate different device
      </label>
      <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
        <input type="checkbox" id="simulate_new_location" class="rounded border-line bg-ink accent-danger">
        Simulate different location
      </label>
    </div>

    <button onclick="runTransactionApprove()" class="rounded bg-gold text-ink font-medium text-sm px-4 py-2 hover:bg-white transition-colors">Approve transaction</button>
    <div id="decision_result" class="mt-4"></div>
  </section>

  <!-- Panel: Fraud check -->
  <section id="panel_fraud" class="rounded-lg border border-line bg-panel p-6 mt-6 hidden">
    <div class="flex items-center justify-between mb-1">
      <h2 class="font-medium text-white">Check for anomalies</h2>
      <span class="text-[11px] text-mute mono">GET /api/fraud/check</span>
    </div>
    <p class="text-sm text-mute mb-5">Standalone read against the stored baseline - no transaction is recorded.</p>
    <button onclick="runFraudCheck()" class="rounded bg-ink border border-line hover:border-gold text-sm px-4 py-2 transition-colors">Check anomalies</button>
    <div id="fraud_result" class="mt-4"></div>
  </section>

  <!-- Panel: Webhook -->
  <section id="panel_webhook" class="rounded-lg border border-line bg-panel p-6 mt-6 hidden">
    <div class="flex items-center justify-between mb-1">
      <h2 class="font-medium text-white">Notify the bank</h2>
      <span class="text-[11px] text-mute mono">POST /api/webhook/notify/&#123;id&#125;</span>
    </div>
    <p class="text-sm text-mute mb-5">Fires the simulated bank API call (approved) or fraud alert (rejected/flagged) for the last transaction.</p>
    <button onclick="runWebhook()" class="rounded bg-ink border border-line hover:border-gold text-sm px-4 py-2 transition-colors">Fire webhook</button>
    <div id="webhook_result" class="mt-4"></div>
  </section>

  <p class="text-xs text-mute mt-10">Test console - not production UI. See <a href="api-docs" class="text-teal hover:text-white underline">API docs</a> for the full endpoint reference.</p>
</main>

<script>window.__API_KEY__ = <?= json_encode($security['api_key'] ?? '') ?>;</script>
<script src="js/ui.js"></script>
<script src="js/capture.js"></script>
<script src="js/decision-status.js"></script>
<script>Wizard.init();</script>
</body>
</html>
