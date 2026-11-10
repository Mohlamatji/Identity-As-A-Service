<?php

namespace App\Services;

use App\Support\Config;
use App\Support\Vec;
use PDO;

/**
 * Orchestrates one capture: validate liveness evidence against the server's
 * challenge, then (only if liveness passed) enroll/match in the vault.
 *
 * Two deliberate behaviours the original lacked:
 *  - A failed liveness check NEVER enrolls a template. Previously a spoofed
 *    first capture could become the user's permanent "face".
 *  - A failed liveness check NEVER reveals the vault match result, so an
 *    attacker can't use the endpoint as a match oracle to tune a spoof.
 */
class BiometricService
{
    public function __construct(
        private readonly LivenessService $liveness = new LivenessService(),
        private readonly VaultService $vault = new VaultService()
    ) {
    }

    public function capture(PDO $pdo, int $userId, string $imageBase64, array $challenge, array $evidence): array
    {
        $liveness = $this->liveness->evaluate($challenge, $evidence);

        if (!$liveness['passed']) {
            return [
                'liveness' => $liveness,
                'vault_match' => [
                    'is_first_enrollment' => false,
                    'matched' => false,
                    'confidence' => 0.0,
                    'distance' => null,
                    'algorithm' => null,
                    'reason' => 'Not evaluated: liveness did not pass',
                ],
            ];
        }

        // Preferred: the mean of the server-validated, same-person descriptors.
        // Legacy single 'descriptor' is accepted only in non-production.
        $descriptor = $liveness['mean_descriptor'];
        if ($descriptor === null && !Config::isProduction() && Vec::isDescriptor($evidence['descriptor'] ?? null)) {
            $descriptor = $evidence['descriptor'];
        }

        if ($descriptor !== null) {
            $template = VaultService::packDescriptor($descriptor);
            $algorithm = 'face-descriptor-v1';
        } elseif (!Config::isProduction() && Config::get('allow_simulated_liveness')) {
            $template = hash('sha256', (string) base64_decode($imageBase64), true);
            $algorithm = 'mvp-sha256-stub-v1';
        } else {
            return [
                'liveness' => $liveness,
                'vault_match' => [
                    'is_first_enrollment' => false,
                    'matched' => false,
                    'confidence' => 0.0,
                    'distance' => null,
                    'algorithm' => null,
                    'reason' => 'No face descriptor available - the hash-stub template is not accepted in this environment',
                ],
            ];
        }

        return [
            'liveness' => $liveness,
            'vault_match' => $this->vault->enrollOrMatch($pdo, $userId, $template, $algorithm),
        ];
    }
}
