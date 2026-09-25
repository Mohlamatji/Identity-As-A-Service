<?php

namespace App\Services\VerificationAdapters;

/**
 * Result of a DHA-backed identity verification call, normalized across providers.
 */
class VerificationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $matchResult,   // 'match' | 'no_match' | 'error'
        public readonly ?float $photoMatchConfidence, // 0-1, null if not returned
        public readonly string $queryType,     // 'realtime' | 'batch'
        public readonly int $costCents,
        public readonly ?string $errorMessage = null,
        // Set only when matchResult is 'error', to distinguish an
        // OPERATIONAL failure of the bureau integration (insufficient
        // credits, rate limited, bad API key, idempotency conflict) from a
        // genuine "this person's details didn't verify" outcome. These are
        // very different things for a fraud-prevention product to conflate
        // - one is evidence about the person, the other is evidence about
        // whether the check ran at all.
        // null | 'invalid_params' | 'invalid_api_key' | 'insufficient_credits'
        // | 'idempotency_conflict' | 'rate_limited' | 'http_error'
        // | 'network_error' | 'curl_unavailable'
        public readonly ?string $errorCode = null,
        // Full decoded response body, kept for audit/compliance persistence
        // (DhaVerification model encrypts this before storage). Null on
        // network-level failures where no response body exists at all.
        public readonly ?array $rawResponse = null
    ) {
    }
}

/**
 * Every DHA-accredited bureau (Datanamix, VerifyNow, etc.) implements this
 * interface. The Decision Engine and controllers only ever depend on this
 * interface, never on a concrete provider - swapping bureaus or adding
 * direct NPR access later means writing one new class, not touching the
 * rest of the app.
 */
interface VerificationAdapterInterface
{
    public function verifyIdentity(string $idNumber, string $fullName, ?string $selfieImageBase64, bool $realtime): VerificationResult;
}
