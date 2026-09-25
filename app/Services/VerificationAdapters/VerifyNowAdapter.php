<?php

namespace App\Services\VerificationAdapters;

/**
 * Adapter for VerifyNow's real DHA-backed verification API.
 *
 * Confirmed against VerifyNow's actual integration guide
 * (verifynow.co.za/api-docs/integration-guide) on 2026-09-04 - base URL,
 * auth header, the /verify endpoint's request/response shape, the
 * Idempotency-Key requirement, and the documented error codes (400/401/
 * 402/409/429) are all taken directly from their docs, not guessed. The
 * /facematch response shape is NOT shown in that guide (only request
 * examples are given) - parsing it below is a best-effort guess pending a
 * real sandbox call; see parseFaceMatchResponse().
 *
 * Two things about VerifyNow's real API that don't map cleanly onto this
 * adapter interface's $realtime parameter, worth knowing before you wire
 * this up for real:
 *  - VerifyNow's actual axis is sandbox vs production ("mode"), not
 *    realtime vs batch pricing - there's no documented batch discount.
 *    $realtime is kept here only for this app's own cost/queryType
 *    bookkeeping; it is NOT sent to VerifyNow.
 *  - Idempotency-Key is required on every production call to prevent
 *    duplicate charges on retry. A fresh key is generated per call here,
 *    which is correct for a one-shot verification but means a genuine
 *    network-level retry of the *same* logical request would currently be
 *    billed twice - reuse the same key across retries of one logical
 *    attempt if you add retry logic later.
 */
class VerifyNowAdapter implements VerificationAdapterInterface
{
    // From VerifyNow's public pricing (ZAR 17 : $1 USD, confirmed 2026-09-04):
    // ID Verification (Data Only) = 1 credit = R2.99. Face Match (Home
    // Affairs, i.e. comparing a selfie against the DHA reference photo
    // rather than a caller-supplied reference image) = 10 credits = R29.90.
    private const SAID_VERIFICATION_COST_CENTS = 299;
    private const FACEMATCH_HOME_AFFAIRS_COST_CENTS = 2990;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $mode = 'sandbox'
    ) {
    }

    public function verifyIdentity(string $idNumber, string $fullName, ?string $selfieImageBase64, bool $realtime): VerificationResult
    {
        $idResult = $this->callSaidVerification($idNumber);
        if (!$idResult['success']) {
            return new VerificationResult(
                success: false,
                matchResult: 'error',
                photoMatchConfidence: null,
                queryType: $realtime ? 'realtime' : 'batch',
                costCents: 0,
                errorMessage: $idResult['error'],
                errorCode: $idResult['error_code'],
                rawResponse: $idResult['raw_response']
            );
        }

        $photoMatchConfidence = null;
        $totalCostCents = self::SAID_VERIFICATION_COST_CENTS;

        if ($selfieImageBase64 !== null) {
            $faceResult = $this->callFaceMatch($idNumber, $selfieImageBase64);
            if ($faceResult['success']) {
                $photoMatchConfidence = $faceResult['confidence'];
                $totalCostCents += self::FACEMATCH_HOME_AFFAIRS_COST_CENTS;
            }
            // A face-match failure doesn't invalidate the ID check itself -
            // fall through with photoMatchConfidence left null, same as if
            // no selfie had been supplied at all.
        }

        return new VerificationResult(
            success: true,
            matchResult: $idResult['matched'] ? 'match' : 'no_match',
            photoMatchConfidence: $photoMatchConfidence,
            queryType: $realtime ? 'realtime' : 'batch',
            costCents: $totalCostCents,
            rawResponse: $idResult['raw_response']
        );
    }

    /**
     * POST /verify, reportType 'said_verification'. Request/response shape
     * confirmed against VerifyNow's documented example - see class docblock.
     */
    private function callSaidVerification(string $idNumber): array
    {
        [$httpCode, $data, $curlError] = $this->post('/verify', [
            'reportType' => 'said_verification',
            'idNumber' => $idNumber,
            'mode' => $this->mode,
        ]);

        if ($curlError) {
            $errorCode = str_starts_with($curlError, "PHP's curl extension") ? 'curl_unavailable' : 'network_error';
            return ['success' => false, 'matched' => false, 'error' => $curlError, 'error_code' => $errorCode, 'raw_response' => null];
        }
        if ($httpCode !== 200 || !is_array($data)) {
            return [
                'success' => false,
                'matched' => false,
                'error' => "VerifyNow /verify returned HTTP {$httpCode}" . (isset($data['error']) ? ": {$data['error']}" : ''),
                'error_code' => self::httpCodeToErrorCode($httpCode),
                'raw_response' => is_array($data) ? $data : null,
            ];
        }
        if (($data['success'] ?? false) !== true) {
            return [
                'success' => false,
                'matched' => false,
                'error' => $data['error'] ?? 'VerifyNow /verify call did not succeed',
                'error_code' => 'http_error',
                'raw_response' => $data,
            ];
        }

        $parsed = $this->parseSaidVerificationResponse($data);
        $parsed['raw_response'] = $data;
        return $parsed;
    }

    /** Extracted for unit testing against VerifyNow's documented example response. */
    private function parseSaidVerificationResponse(array $data): array
    {
        $status = $data['results']['said_verification']['realTimeResults']['Status'] ?? '';
        $matched = stripos($status, 'valid') !== false;

        return ['success' => true, 'matched' => $matched, 'error' => null, 'error_code' => null];
    }

    /**
     * Maps VerifyNow's documented HTTP error codes to a stable string the
     * rest of the app can branch on - specifically so an operational
     * failure (out of credits, rate limited, bad key) is never confused
     * with "this person's ID didn't verify". See VerificationResult's
     * $errorCode docblock for why that distinction matters.
     */
    private static function httpCodeToErrorCode(int $httpCode): string
    {
        return match ($httpCode) {
            400 => 'invalid_params',
            401 => 'invalid_api_key',
            402 => 'insufficient_credits',
            409 => 'idempotency_conflict',
            429 => 'rate_limited',
            default => 'http_error',
        };
    }

    /**
     * POST /facematch, bundle 'facematch' (compares the selfie against the
     * Home Affairs reference photo, not a caller-supplied reference image).
     *
     * UNCONFIRMED: VerifyNow's integration guide shows the request body for
     * this endpoint but not an example response. The field names below
     * (face_match / confidence, and match_score as a fallback) are a
     * best-effort guess based on how the rest of their API is shaped -
     * confirm against a real sandbox response before trusting this in
     * production, and adjust parseFaceMatchResponse() accordingly.
     */
    private function callFaceMatch(string $idNumber, string $selfieImageBase64): array
    {
        [$httpCode, $data, $curlError] = $this->post('/facematch', [
            'bundle' => 'facematch',
            'mode' => $this->mode,
            'id_number' => $idNumber,
            'selfie_image_base64' => $selfieImageBase64,
        ]);

        if ($curlError || $httpCode !== 200 || !is_array($data)) {
            return ['success' => false, 'confidence' => null];
        }

        return $this->parseFaceMatchResponse($data);
    }

    private function parseFaceMatchResponse(array $data): array
    {
        // Try a couple of plausible shapes given VerifyNow's other response
        // conventions (nested under results.*, or top-level) - genuinely
        // unconfirmed, see callFaceMatch()'s docblock.
        $faceData = $data['results']['facematch'] ?? $data;

        if (isset($faceData['confidence'])) {
            return ['success' => true, 'confidence' => (float) $faceData['confidence'] / 100];
        }
        if (isset($faceData['match_score'])) {
            return ['success' => true, 'confidence' => (float) $faceData['match_score'] / 100];
        }

        return ['success' => false, 'confidence' => null];
    }

    /** @return array{0: int, 1: array|null, 2: string|null} [httpCode, decodedBody, curlErrorOrNull] */
    private function post(string $path, array $body): array
    {
        if (!function_exists('curl_init')) {
            return [0, null, "PHP's curl extension is not installed/enabled - cannot reach VerifyNow. Install php-curl."];
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'Idempotency-Key: ' . self::generateIdempotencyKey(),
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 15,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlError) {
            return [0, null, $curlError ?: 'No response from VerifyNow'];
        }

        return [$httpCode, json_decode($responseBody, true), null];
    }

    private static function generateIdempotencyKey(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
