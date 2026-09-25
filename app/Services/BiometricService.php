<?php

namespace App\Services;

use PDO;

/**
 * Orchestrates the "Biometric Capture SDK" + "Liveness Detection Check"
 * flowchart stages. Controllers call this rather than touching
 * BiometricTemplate/VaultService/LivenessService directly, so the capture
 * pipeline has one owner.
 */
class BiometricService
{
    public function __construct(
        private readonly LivenessService $liveness = new LivenessService(),
        private readonly VaultService $vault = new VaultService()
    ) {
    }

    /**
     * $gestureMeta comes straight from the client. Real capture (face-api.js
     * available): ['mode'=>'real','blink_detected'=>bool,'head_turn_detected'=>bool,
     * 'frames_analyzed'=>int,'duration_ms'=>int,'descriptor'=>float[128]|null].
     * Fallback: ['mode'=>'simulated','gesture_completed'=>bool,'frame_count'=>int,
     * 'duration_ms'=>int].
     *
     * 'descriptor' is a face-api.js 128-float face-recognition embedding,
     * extracted client-side. When present, it's used as the real biometric
     * template (compared by cosine similarity in VaultService). When absent
     * - no face-api.js, no camera, or descriptor extraction failed - this
     * falls back to hashing the raw image bytes, the same MVP stub as
     * before, clearly tagged as such via algorithm_version.
     */
    public function capture(PDO $pdo, int $userId, string $imageBase64, array $gestureMeta): array
    {
        $descriptor = $gestureMeta['descriptor'] ?? null;

        if (is_array($descriptor) && count($descriptor) > 0) {
            $templateVector = VaultService::packDescriptor(array_map('floatval', $descriptor));
            $algorithmVersion = 'face-descriptor-v1';
        } else {
            // MVP stand-in for when a real embedding isn't available: hash
            // the image bytes into a fixed-length "template". This is NOT
            // real biometric matching - exact-match only, no tolerance for
            // lighting/angle changes. VaultService encrypts either template
            // type before it touches storage.
            $templateVector = hash('sha256', base64_decode($imageBase64), true);
            $algorithmVersion = 'mvp-sha256-stub-v1';
        }

        $livenessResult = $this->liveness->evaluate($gestureMeta);
        $vaultMatch = $this->vault->enrollOrMatch($pdo, $userId, $templateVector, $algorithmVersion);

        return [
            'liveness' => $livenessResult,
            'vault_match' => $vaultMatch,
        ];
    }
}
