<?php
/**
 * Security configuration.
 *
 * API keys now live in the database (api_clients table, hashed - mint them
 * with bin/create-api-client.php). The legacy single API_KEY env var is
 * still honoured as a full-scope "legacy" client so existing integrations
 * keep working, but per-client keys are the supported path.
 *
 * Admin password: prefer ADMIN_PASSWORD_HASH (output of
 * `php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"`)
 * over the plaintext ADMIN_PASSWORD.
 */

return [
    'api_key' => getenv('API_KEY') ?: null, // legacy shared secret (full scope)
    'admin_password' => getenv('ADMIN_PASSWORD') ?: null,
    'admin_password_hash' => getenv('ADMIN_PASSWORD_HASH') ?: null,
];
