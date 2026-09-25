<?php
/**
 * Verification provider configuration.
 *
 * VERIFICATION_PROVIDER selects which adapter DecisionEngine's dependency
 * gets wired to. 'mock' is used for local testing and always returns a
 * deterministic result so the full flow can be exercised without real
 * bureau credentials. Switch to 'datanamix' or 'verifynow' once you have
 * sandbox/production API keys from that provider.
 */

return [
    'active_provider' => getenv('VERIFICATION_PROVIDER') ?: 'mock',

    'datanamix' => [
        'base_url' => getenv('DATANAMIX_BASE_URL') ?: 'https://api.datanamix.com',
        'api_key'  => getenv('DATANAMIX_API_KEY') ?: '',
    ],

    'verifynow' => [
        // Confirmed against VerifyNow's real integration guide
        // (verifynow.co.za/api-docs/integration-guide) - not a guess.
        'base_url' => getenv('VERIFYNOW_BASE_URL') ?: 'https://www.verifynow.co.za/api/external',
        'api_key'  => getenv('VERIFYNOW_API_KEY') ?: '',
        // sandbox = 0 credits used, works with any valid-format ID, and
        // reserved 90/91-ending test IDs force negative/error paths per
        // VerifyNow's docs. Switch to 'production' only once you're ready
        // to spend real credits.
        'mode' => getenv('VERIFYNOW_MODE') ?: 'sandbox',
    ],
];
