<?php

namespace App\Services\VerificationAdapters;

/**
 * Adapter for Datanamix's DHA-backed verification API.
 *
 * NOTE: This is a structural stub. The exact endpoint paths, auth scheme
 * and response shape below are illustrative - confirm them against your
 * signed Datanamix API docs once you have sandbox credentials, and adjust
 * parseResponse() accordingly. Nothing else in the app needs to change
 * when you do, since everything depends on VerificationAdapterInterface.
 */
class DatanamixAdapter implements VerificationAdapterInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey
    ) {
    }

    public function verifyIdentity(string $idNumber, string $fullName, ?string $selfieImageBase64, bool $realtime): VerificationResult
    {
        $endpoint = $realtime ? '/v1/id-verify/realtime' : '/v1/id-verify/batch';

        $payload = [
            'id_number' => $idNumber,
            'full_name' => $fullName,
        ];
        if ($selfieImageBase64 !== null) {
            $payload['selfie_image'] = $selfieImageBase64;
        }

        if (!function_exists('curl_init')) {
            return new VerificationResult(
                success: false,
                matchResult: 'error',
                photoMatchConfidence: null,
                queryType: $realtime ? 'realtime' : 'batch',
                costCents: 0,
                errorMessage: "PHP's curl extension is not installed/enabled - cannot reach Datanamix. Install php-curl.",
                errorCode: 'curl_unavailable'
            );
        }

        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlError) {
            return new VerificationResult(
                success: false,
                matchResult: 'error',
                photoMatchConfidence: null,
                queryType: $realtime ? 'realtime' : 'batch',
                costCents: 0,
                errorMessage: $curlError ?: 'No response from Datanamix',
                errorCode: 'network_error'
            );
        }

        return $this->parseResponse($responseBody, $httpCode, $realtime);
    }

    private function parseResponse(string $body, int $httpCode, bool $realtime): VerificationResult
    {
        $data = json_decode($body, true);

        if ($httpCode !== 200 || !is_array($data)) {
            // Illustrative mapping - Datanamix's real error codes aren't
            // confirmed (unlike VerifyNow's), so this assumes conventional
            // REST conventions rather than a documented contract.
            $errorCode = match ($httpCode) {
                400 => 'invalid_params',
                401 => 'invalid_api_key',
                402 => 'insufficient_credits',
                409 => 'idempotency_conflict',
                429 => 'rate_limited',
                default => 'http_error',
            };
            return new VerificationResult(
                success: false,
                matchResult: 'error',
                photoMatchConfidence: null,
                queryType: $realtime ? 'realtime' : 'batch',
                costCents: 0,
                errorMessage: "Datanamix returned HTTP {$httpCode}",
                errorCode: $errorCode,
                rawResponse: is_array($data) ? $data : null
            );
        }

        // Illustrative field names - confirm against actual Datanamix docs.
        return new VerificationResult(
            success: true,
            matchResult: ($data['match'] ?? false) ? 'match' : 'no_match',
            photoMatchConfidence: isset($data['photo_confidence']) ? (float) $data['photo_confidence'] : null,
            queryType: $realtime ? 'realtime' : 'batch',
            costCents: (int) ($data['cost_cents'] ?? ($realtime ? 1000 : 100)),
            rawResponse: $data
        );
    }
}
