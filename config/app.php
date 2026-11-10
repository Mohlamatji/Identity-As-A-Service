<?php
/**
 * Central runtime configuration. Everything is overridable via environment
 * variables (or putenv() calls in config/local.php), so the same code runs
 * in "development" (zero-setup demo) and "production" (fail-closed) modes.
 *
 * APP_ENV=production flips the whole app to strict mode:
 *   - API auth, admin auth, encryption key and signing key are REQUIRED
 *   - simulated liveness and the hash-stub biometric template are refused
 *   - demo-only overrides (fraud-simulation toggles, demo tokens) are off
 */

$env = getenv('APP_ENV') ?: 'development';
$isProd = $env === 'production';
$flag = static fn (string $name, bool $default): bool =>
    filter_var(getenv($name) !== false ? getenv($name) : ($default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);

return [
    'env' => $env,
    'is_production' => $isProd,
    'demo_mode' => $flag('DEMO_MODE', !$isProd),
    'allow_simulated_liveness' => $flag('ALLOW_SIMULATED_LIVENESS', !$isProd),
    'trust_proxy_headers' => $flag('TRUST_PROXY_HEADERS', false),
    // Header carrying the viewer's country, set by your CDN/proxy
    // (e.g. Cloudflare's CF-IPCountry). Far harder to spoof than a
    // client-supplied field. Only read if trust_proxy_headers is on.
    'country_header' => getenv('COUNTRY_HEADER') ?: 'HTTP_CF_IPCOUNTRY',
    'timezone' => getenv('APP_TIMEZONE') ?: 'Africa/Johannesburg',

    'tokens' => [
        'challenge_ttl' => 120,        // seconds a liveness challenge stays valid
        'session_ttl' => 300,          // seconds a verification token stays valid
        'client_token_ttl' => 900,     // seconds a browser client token stays valid
        'receipt_ttl' => 1800,         // seconds a signed decision receipt stays valid
    ],

    'liveness' => [
        'actions_per_challenge' => 3,
        'min_total_ms' => 1500,
        'max_total_ms' => 30000,
        'min_action_gap_ms' => 250,
        'min_frames' => 12,
        'min_descriptor_samples' => 3,
        // Same-person consistency across the session (Euclidean distance on
        // face-api.js 128-d descriptors). UNCALIBRATED starting points.
        'max_sample_distance' => 0.55,
        'min_sample_variance' => 0.015, // below this a "live" face is implausibly static
    ],

    'vault' => [
        // face-api.js's own guidance is distance < 0.6 for "same person";
        // 0.5 is deliberately stricter for a payments context. UNCALIBRATED.
        'match_distance_threshold' => (float) (getenv('VAULT_MATCH_THRESHOLD') ?: 0.5),
        'lockout_failures' => 5,
        'lockout_window_seconds' => 900,
    ],

    'decision' => [
        'engine_version' => 'iv-engine-2.0',
        'approve_threshold' => 70,
        'review_threshold' => 40,
        // amount (ZAR cents) => minimum score needed to auto-approve.
        // Higher-value payments demand stronger evidence.
        'amount_tiers' => [
            2500000 => 88,   // >= R25,000
            500000 => 78,    // >= R5,000
        ],
    ],

    'behavior' => [
        'velocity_hour_count' => 3,
        'velocity_day_amount_cents' => 5000000,   // R50,000 / 24h
        'new_account_hours' => 24,
        'new_account_large_amount_cents' => 1000000, // R10,000
        'amount_zscore_flag' => 3.0,
        'odd_hours' => [1, 5],                    // local hours [from, to)
        'recent_failure_window_seconds' => 1800,
    ],

    'paypal' => [
        // mock | sandbox | live. "mock" needs no credentials and simulates
        // PayPal's responses locally so the checkout demo always runs.
        'mode' => getenv('PAYPAL_MODE') ?: 'mock',
        'client_id' => getenv('PAYPAL_CLIENT_ID') ?: '',
        'client_secret' => getenv('PAYPAL_CLIENT_SECRET') ?: '',
        'webhook_id' => getenv('PAYPAL_WEBHOOK_ID') ?: '',
        // PayPal supports a fixed list of currencies - confirm ZAR is
        // available for your merchant account; otherwise price the PayPal
        // leg in USD using this indicative rate (ZAR per 1 USD).
        'currency' => getenv('PAYPAL_CURRENCY') ?: 'USD',
        'zar_per_usd' => (float) (getenv('PAYPAL_ZAR_PER_USD') ?: 18.5),
    ],

    'ai' => [
        'api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
        'model' => getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-5-5',
        'base_url' => getenv('ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com',
        'timeout' => 20,
    ],

    'webhook' => [
        'url' => getenv('WEBHOOK_URL') ?: '',
        'secret' => getenv('WEBHOOK_SECRET') ?: '',
    ],
];
