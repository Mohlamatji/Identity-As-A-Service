<?php
// Shared demo-page head. Mints a short-lived, scope-limited browser token
// (never the long-lived API key). DEMO_MODE only - see public/index.php.
$demoToken = (new App\Services\ClientAuthService())->mintClientToken(
    new App\Support\AuthContext('open', 'demo-checkout', 'Demo checkout', ['*'], 300), App\Services\ClientAuthService::ALL_SCOPES, null, true
)['client_token'];
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?= $assetPrefix ?? '' ?>css/app.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
