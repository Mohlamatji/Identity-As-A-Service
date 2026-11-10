<!DOCTYPE html>
<html lang="en">
<head><title>Simulated PayPal</title><?php $assetPrefix = "../"; require __DIR__ . '/_head.php'; ?></head>
<body class="font-sans text-slate-200 antialiased bg-ink min-h-screen flex items-center justify-center">
<div class="w-full max-w-sm rounded-lg border border-line bg-panel p-8 text-center">
  <div class="text-xs rounded border border-gold/40 text-gold px-2 py-1 inline-block mb-4">SIMULATED PAYPAL (PAYPAL_MODE=mock)</div>
  <p class="text-sm text-mute mb-6">This stands in for PayPal's "Review your purchase" page so the demo runs without PayPal credentials. With <span class="mono">PAYPAL_MODE=sandbox</span> the buyer is sent to the real PayPal sandbox instead.</p>
  <?php $order = preg_replace('/[^A-Z0-9]/', '', $_GET['token'] ?? ''); ?>
  <a class="block rounded bg-gold text-ink font-medium text-sm px-4 py-2 hover:bg-white mb-2" href="../checkout?token=<?= htmlspecialchars($order) ?>&PayerID=MOCKPAYER">Approve payment</a>
  <a class="block text-xs text-mute underline" href="../checkout?cancelled=1">Cancel</a>
</div>
</body></html>
