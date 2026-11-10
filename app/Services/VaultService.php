<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BiometricTemplate;
use App\Support\Config;
use App\Support\Vec;
use PDO;

/**
 * Identity Vault Match: proves "the person transacting now is the same
 * person who originally enrolled" (distinct from DHA proving "the enrolled
 * person is who their ID says").
 *
 * Template types (biometric_templates.algorithm_version):
 *  - 'face-descriptor-v1': real 128-d face-api.js embedding, compared by
 *    EUCLIDEAN DISTANCE - the metric face-api.js's recognition net is
 *    actually trained/documented for (the previous cosine>=0.92 rule had no
 *    basis for these embeddings). Confidence is derived from the distance so
 *    borderline matches score lower in the Decision Engine.
 *  - 'mvp-sha256-stub-v1': hash of image bytes, exact match only. Refused
 *    entirely in production.
 *
 * Captures are only compared against enrollments of the same algorithm;
 * mismatches fail closed with a re-enroll message.
 */
class VaultService
{
    public function enrollOrMatch(
        PDO $pdo,
        int $userId,
        string $templateVector,
        string $algorithmVersion,
        string $templateType = 'face'
    ): array {
        $enrolled = BiometricTemplate::latestForUser($pdo, $userId, $templateType);

        if ($enrolled === null) {
            BiometricTemplate::create($pdo, $userId, $templateType, $templateVector, $algorithmVersion);
            AuditLog::record($pdo, 'system', 'vault_enrolled', 'biometric_templates', $userId, $_SERVER['REMOTE_ADDR'] ?? null, ['algorithm' => $algorithmVersion]);
            return [
                'is_first_enrollment' => true,
                'matched' => true,
                'confidence' => 1.0,
                'distance' => null,
                'algorithm' => $algorithmVersion,
                'reason' => 'No prior enrollment found - treated as initial vault enrollment',
            ];
        }

        if ($enrolled['algorithm_version'] !== $algorithmVersion) {
            AuditLog::record($pdo, 'system', 'vault_match_algorithm_mismatch', 'biometric_templates', $userId, $_SERVER['REMOTE_ADDR'] ?? null);
            return [
                'is_first_enrollment' => false,
                'matched' => false,
                'confidence' => 0.0,
                'distance' => null,
                'algorithm' => $algorithmVersion,
                'reason' => "Enrolled template uses '{$enrolled['algorithm_version']}' but this capture used '{$algorithmVersion}' - re-enroll to upgrade.",
            ];
        }

        $distance = null;
        if ($algorithmVersion === 'face-descriptor-v1') {
            $threshold = (float) Config::get('vault.match_distance_threshold', 0.5);
            $distance = round(Vec::distance(self::unpackDescriptor($templateVector), self::unpackDescriptor($enrolled['template_vector'])), 4);
            $matched = $distance <= $threshold;
            // 0 distance -> 1.0, exactly at threshold -> 0.5, 2x threshold or worse -> 0.
            $confidence = round(max(0.0, 1.0 - $distance / (2 * $threshold)), 4);
            $reason = $matched
                ? "Face embedding distance {$distance} <= threshold {$threshold}"
                : "Face embedding distance {$distance} exceeds threshold {$threshold}";
        } else {
            $matched = hash_equals($enrolled['template_vector'], $templateVector);
            $confidence = $matched ? 1.0 : 0.0;
            $reason = $matched ? 'Exact-hash stub match (demo only)' : 'Exact-hash stub mismatch - possible impersonation';
        }

        AuditLog::record($pdo, 'system', $matched ? 'vault_match_confirmed' : 'vault_match_failed', 'biometric_templates', $userId, $_SERVER['REMOTE_ADDR'] ?? null, $distance !== null ? ['distance' => $distance] : null);

        return [
            'is_first_enrollment' => false,
            'matched' => $matched,
            'confidence' => $confidence,
            'distance' => $distance,
            'algorithm' => $algorithmVersion,
            'reason' => $reason,
        ];
    }

    public static function packDescriptor(array $descriptor): string
    {
        return pack('g*', ...array_map('floatval', $descriptor));
    }

    public static function unpackDescriptor(string $binary): array
    {
        return array_values(unpack('g*', $binary));
    }
}
