<?php

namespace App\Services\VerificationAdapters;

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
