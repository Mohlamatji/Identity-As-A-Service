<?php

namespace App\Services\VerificationAdapters;

/**
 * Deterministic mock adapter so the full onboarding -> decision flow can be
 * tested end-to-end with no bureau API keys. It "matches" any ID number that
 * doesn't end in the digit 0, and simulates realtime being pricier than batch,
 * mirroring the real DHA fee structure (realtime queries cost more than
 * off-peak/batch queries).
 */
class MockAdapter implements VerificationAdapterInterface
{
    public function verifyIdentity(string $idNumber, string $fullName, ?string $selfieImageBase64, bool $realtime): VerificationResult
    {
        $lastDigit = substr(preg_replace('/\D/', '', $idNumber), -1);
        $isMatch = $lastDigit !== '0';

        return new VerificationResult(
            success: true,
            matchResult: $isMatch ? 'match' : 'no_match',
            photoMatchConfidence: $selfieImageBase64 ? ($isMatch ? 0.94 : 0.31) : null,
            queryType: $realtime ? 'realtime' : 'batch',
            costCents: $realtime ? 1000 : 100, // R10 realtime vs R1 batch, illustrative
            rawResponse: [
                'mock' => true,
                'id_number' => $idNumber,
                'full_name' => $fullName,
                'matched' => $isMatch,
                'note' => 'Synthetic response - MockAdapter never calls a real bureau. Stored here so the DHA verification audit trail is demonstrable without real credentials.',
            ]
        );
    }
}
