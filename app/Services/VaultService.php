<?php

namespace App\Services;

use App\Models\BiometricTemplate;
use App\Models\AuditLog;
use PDO;

/**
 * Owns the "Identity Vault Match" step: encrypts and stores biometric
 * templates, and compares new captures against whatever is already
 * enrolled for a user. This runs BEFORE DHA verification - it proves "the
 * person transacting now is the same person who originally enrolled,"
 * which is a distinct claim from DHA proving "the enrolled person is who
 * their ID document says."
 *
 * Supports two template types, tracked via biometric_templates.algorithm_version:
 *  - 'face-descriptor-v1' - a real 128-float face-api.js embedding, compared
 *    by cosine similarity. Used when the client successfully extracts one.
 *  - 'mvp-sha256-stub-v1' - a SHA-256 hash of raw image bytes, compared by
 *    exact match. Fallback for clients without face-api.js/a camera.
 * A capture can only be compared against an enrollment made with the SAME
 * algorithm - see the mismatch branch below for why that fails closed
 * rather than silently comparing incompatible template formats.
 */
class VaultService
{
    // Cosine similarity threshold for "same person" on face-api.js's 128-d
    // descriptors. ILLUSTRATIVE: face-api.js's own examples use Euclidean
    // distance (typically <0.6) rather than cosine similarity, so this
    // number has no calibration data behind it yet - tune it against real
    // enrolled-user capture pairs (same person, different sessions) before
    // trusting it for anything beyond a demo.
    private const FACE_DESCRIPTOR_MATCH_THRESHOLD = 0.92;

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
            AuditLog::record($pdo, 'system', 'vault_enrolled', 'biometric_templates', $userId, $_SERVER['REMOTE_ADDR'] ?? null);

            return [
                'is_first_enrollment' => true,
                'matched' => true,
                'confidence' => 1.0,
                'algorithm' => $algorithmVersion,
                'reason' => 'No prior enrollment found - treated as initial vault enrollment',
            ];
        }

        // Can't meaningfully compare a real embedding against a hash stub
        // (or vice versa) - fail closed rather than guess. This also
        // surfaces as a normal, expected path the first time an existing
        // hash-stub-enrolled user captures with a browser that now supports
        // real descriptors: they need to re-enroll once to upgrade.
        if ($enrolled['algorithm_version'] !== $algorithmVersion) {
            AuditLog::record($pdo, 'system', 'vault_match_algorithm_mismatch', 'biometric_templates', $userId, $_SERVER['REMOTE_ADDR'] ?? null);

            return [
                'is_first_enrollment' => false,
                'matched' => false,
                'confidence' => 0.0,
                'algorithm' => $algorithmVersion,
                'reason' => "Enrolled template uses '{$enrolled['algorithm_version']}' but this capture used "
                    . "'{$algorithmVersion}' - can't compare reliably across algorithm versions. Re-enroll to upgrade.",
            ];
        }

        if ($algorithmVersion === 'face-descriptor-v1') {
            $newDescriptor = self::unpackDescriptor($templateVector);
            $enrolledDescriptor = self::unpackDescriptor($enrolled['template_vector']);
            $similarity = self::cosineSimilarity($newDescriptor, $enrolledDescriptor);
            $confidence = round($similarity, 4);
            $matched = $similarity >= self::FACE_DESCRIPTOR_MATCH_THRESHOLD;
            $reason = $matched
                ? "Face descriptor cosine similarity {$confidence} >= threshold " . self::FACE_DESCRIPTOR_MATCH_THRESHOLD
                : "Face descriptor cosine similarity {$confidence} below threshold " . self::FACE_DESCRIPTOR_MATCH_THRESHOLD;
        } else {
            $matched = hash_equals($enrolled['template_vector'], $templateVector);
            $confidence = $matched ? 1.0 : 0.0;
            $reason = $matched
                ? 'New capture matches enrolled vault template (exact-hash stub)'
                : 'New capture does NOT match enrolled vault template (exact-hash stub) - possible impersonation';
        }

        // Enrolled template stays as the source of truth; this capture is
        // logged as a match attempt, not written over the vault.
        AuditLog::record(
            $pdo,
            'system',
            $matched ? 'vault_match_confirmed' : 'vault_match_failed',
            'biometric_templates',
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null
        );

        return [
            'is_first_enrollment' => false,
            'matched' => $matched,
            'confidence' => $confidence,
            'algorithm' => $algorithmVersion,
            'reason' => $reason,
        ];
    }

    /** Packs a float array (e.g. a 128-d face descriptor) into a binary string for storage. */
    public static function packDescriptor(array $descriptor): string
    {
        return pack('g*', ...$descriptor);
    }

    private static function unpackDescriptor(string $binary): array
    {
        return array_values(unpack('g*', $binary));
    }

    private static function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $count = min(count($a), count($b));
        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
