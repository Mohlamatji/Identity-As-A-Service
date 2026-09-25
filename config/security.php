<?php
/**
 * Security configuration. Both controls are OPT-IN via environment
 * variables, off by default so local `php -S` testing and the curl
 * examples in the README keep working with zero setup. Set these before
 * deploying anywhere reachable by the public.
 */

return [
    // If set, every /api/* request must send a matching X-Api-Key header.
    // If unset/empty, /api/* is open (dev-friendly default).
    'api_key' => getenv('API_KEY') ?: null,

    // If set, /dashboard requires a session login using this password.
    // If unset/empty, /dashboard is open (dev-friendly default).
    'admin_password' => getenv('ADMIN_PASSWORD') ?: null,
];
