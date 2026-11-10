<!DOCTYPE html>
<html lang="en">
<head>
<title>Identity Vault - Verified PayPal Checkout</title>
<?php require __DIR__ . '/_head.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
</head>
<body class="font-sans text-slate-200 antialiased">
<header class="border-b border-line">
  <div class="mx-auto max-w-5xl px-6 md:px-10 h-16 flex items-center justify-between">
    <div class="flex items-center gap-2.5">
      <span class="text-gold w-6 h-6"><svg viewBox="0 0 24 24" fill="none"><path d="M12 3l7 3v5.5c0 4.5-3 7.8-7 9.5-4-1.7-7-5-7-9.5V6l7-3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
      <span class="font-semibold text-white tracking-tight">Identity Vault</span>
    </div>
    <nav class="flex items-center gap-1 text-sm">
      <a href="./" class="px-3 py-1.5 rounded text-mute hover:text-white">Console</a>
      <a href="checkout" class="px-3 py-1.5 rounded text-white bg-white/5">PayPal checkout</a>
      <a href="dashboard" class="px-3 py-1.5 rounded text-mute hover:text-white">Dashboard</a>
      <a href="api-docs" class="px-3 py-1.5 rounded text-mute hover:text-white">API docs</a>
    </nav>
  </div>
</header>

<main class="mx-auto max-w-5xl px-6 md:px-10 py-10">
  <div class="flex items-center gap-3 mb-2">
    <h1 class="text-lg font-semibold text-white">Verified checkout</h1>
    <span id="pp_mode" class="text-[11px] rounded border border-line px-2 py-0.5 text-mute mono">PayPal: ...</span>
  </div>
  <p class="text-sm text-mute max-w-2xl mb-8">A merchant drops Identity Vault in front of PayPal. The buyer proves they're live and who they say they are; the decision engine scores the payment; <strong class="text-slate-300">PayPal is only called if the payment is approved</strong>, and capture needs a signed approval receipt.</p>

  <div id="result_view" class="hidden mb-8"></div>

  <div id="checkout_view" class="grid md:grid-cols-5 gap-6">
    <section class="md:col-span-2 rounded-lg border border-line bg-panel p-5">
      <h2 class="text-sm font-medium text-white mb-4">Your order</h2>
      <div class="space-y-2" id="products"></div>
      <div class="border-t border-line mt-4 pt-4 flex justify-between text-sm"><span class="text-mute">Total</span><span id="total" class="mono text-white"></span></div>
      <div id="pp_amount" class="text-[11px] text-mute mono mt-1"></div>
      <div id="tier_note" class="text-xs text-mute mt-3"></div>
    </section>

    <section class="md:col-span-3 rounded-lg border border-line bg-panel p-5">
      <h2 class="text-sm font-medium text-white mb-1">Verify &amp; pay</h2>
      <p class="text-xs text-mute mb-4" id="who">First time here? We'll enrol you, then verify the payment.</p>

      <div id="enroll_fields" class="grid grid-cols-2 gap-2 mb-4">
        <input id="id_number" value="8001015009087" class="rounded border border-line bg-ink px-3 py-2 text-sm" placeholder="SA ID number">
        <input id="full_name" value="Jane Test" class="rounded border border-line bg-ink px-3 py-2 text-sm" placeholder="Full name">
        <input id="dob" type="date" value="1980-01-01" class="rounded border border-line bg-ink px-3 py-2 text-sm">
        <input id="phone" value="0821234567" class="rounded border border-line bg-ink px-3 py-2 text-sm" placeholder="Phone">
      </div>

      <div id="video_wrap" class="relative rounded border border-line bg-ink overflow-hidden mb-2"><video id="video" autoplay muted playsinline class="w-full block"></video><div class="scan-line"></div></div>
      <canvas id="canvas" class="hidden"></canvas>
      <p id="prompt" class="text-sm text-teal min-h-[1.25rem] mb-3">Start the camera to begin.</p>

      <label class="flex items-start gap-2 text-xs text-slate-300 mb-3 cursor-pointer">
        <input type="checkbox" id="consent" class="mt-0.5 accent-gold">
        <span>I consent to my facial biometric data and ID number being processed for Fraud Prevention/Fraud Detection (POPIA).</span>
      </label>
      <label class="flex items-center gap-2 text-xs text-slate-300 mb-4 cursor-pointer"><input type="checkbox" id="sim_device" class="accent-danger"> Simulate an unfamiliar device (fraud demo)</label>

      <div class="flex flex-wrap gap-2">
        <button id="btn_cam" class="rounded bg-ink border border-line hover:border-gold text-sm px-4 py-2">Start camera</button>
        <button id="btn_pay" class="rounded bg-gold text-ink font-medium text-sm px-5 py-2 hover:bg-white">Verify &amp; pay with PayPal</button>
        <button id="btn_forget" class="rounded bg-ink border border-line hover:border-danger text-xs px-3 py-2 text-mute">Reset demo user</button>
      </div>
      <div id="pay_result" class="mt-4"></div>
    </section>
  </div>
</main>

<script>window.__TOKEN__ = <?= json_encode($demoToken) ?>;</script>
<script src="js/ui.js"></script>
<script src="js/identity-vault.js"></script>
<script src="js/checkout.js"></script>
</body>
</html>
