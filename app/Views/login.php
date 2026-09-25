<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Identity Vault - Admin Login</title>
<link rel="stylesheet" href="css/app.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="font-sans text-slate-200 antialiased bg-ink min-h-screen flex items-center justify-center">
<div class="w-full max-w-sm rounded-lg border border-line bg-panel p-8">
  <div class="flex items-center gap-2.5 mb-6">
    <span class="text-gold w-6 h-6"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3l7 3v5.5c0 4.5-3 7.8-7 9.5-4-1.7-7-5-7-9.5V6l7-3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
    <span class="font-semibold text-white tracking-tight">Identity Vault</span>
  </div>
  <p class="text-sm text-mute mb-6">Admin dashboard login</p>
  <?php if (!empty($loginError)): ?>
    <p class="text-sm text-danger mb-4"><?= htmlspecialchars($loginError) ?></p>
  <?php endif; ?>
  <form method="POST" action="login">
    <label class="block mb-4">
      <span class="text-xs text-mute">Password</span>
      <input type="password" name="password" autofocus class="mt-1 w-full rounded border border-line bg-ink px-3 py-2 text-sm text-white focus:outline-none focus:border-gold">
    </label>
    <button type="submit" class="w-full rounded bg-gold text-ink font-medium text-sm px-4 py-2 hover:bg-white transition-colors">Log in</button>
  </form>
</div>
</body>
</html>
